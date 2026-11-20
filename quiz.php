<?php
/**
 * QCM côté élève : répondre, puis voir sa note (et la correction si l'enseignant l'a permis).
 */

require_once __DIR__ . '/inc/bootstrap.php';

$level = query('level');
$path  = query('path');
$data  = settings();

class_guard($level);

$abs = full_path($path, 'file');
if (!$abs || !is_quiz_file($path) || !rule_for($path, $level, $data)) {
    fail(404, 'QCM indisponible', 'Ce QCM n’existe pas ou n’est pas publié pour votre classe.');
}
$quiz = quiz_load($abs);
if ($quiz['id'] === '' || !$quiz['questions']) {
    fail(404, 'QCM vide', 'Ce QCM ne contient encore aucune question.');
}

// Tirage aléatoire de questions (banque de questions) : même tirage pour un même élève.
$full = $quiz;
$meSeed = current_student();
$quiz = quiz_subset($full, ($meSeed ? $meSeed['id'] : session_id()) . $full['id']);

$self = 'quiz.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($path);
$folder = dirname($path) === '.' ? '' : dirname($path);
$classHome = class_home_folder($level);
$back = level_url($level, $folder === $classHome ? '' : $folder);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $last = clean_person_name(post('last'));
    $first = clean_person_name(post('first'));
    $me = current_student();
    if ($me && $me['class'] === $level) { $last = $me['last']; $first = $me['first']; }
    $answers = [];
    foreach ($quiz['questions'] as $i => $q) {
        $raw = isset($_POST['q'][$i]) ? (array) $_POST['q'][$i] : [];
        $answers[$i] = array_values(array_filter(array_map('intval', $raw), function ($v) use ($q) {
            return $v >= 0 && $v < count($q['options']);
        }));
    }
    $results = quiz_results($quiz['id']);
    if (quiz_closed($quiz)) {
        $error = 'Ce QCM est fermé depuis le ' . format_datetime($quiz['until']) . '.';
    } elseif ($last === '' || $first === '') {
        $error = 'Indiquez votre nom et votre prénom (lettres uniquement).';
    } elseif ($quiz['attempts'] > 0 && quiz_attempts_of($results, $level, $last, $first) >= $quiz['attempts']) {
        $error = $quiz['attempts'] === 1
            ? 'Vous avez déjà répondu à ce QCM. Une seule réponse est possible.'
            : 'Vous avez déjà utilisé vos ' . $quiz['attempts'] . ' essais pour ce QCM.';
    } else {
        $grade = quiz_grade($quiz, $answers);
        quiz_store_result($quiz, [
            'level' => $level, 'groups' => viewer_groups($level, $data), 'last' => $last, 'first' => $first,
            'student' => $me && $me['class'] === $level ? $me['id'] : '',
            'answers' => quiz_unmap($answers, $quiz['map']), 'score' => $grade['score'], 'total' => $grade['total'],
            'detail' => quiz_unmap($grade['detail'], $quiz['map']), 'time' => time(),
        ]);
        audit_log('student_quiz', ['classe' => $level, 'qcm' => $path], 'élève');
        $_SESSION['quiz_done'][$quiz['id']] = ['answers' => $answers, 'grade' => $grade, 'name' => $first . ' ' . mb_strtoupper($last), 'time' => time()];
        redirect($self . '&done=1');
    }
}

$done = query('done') === '1' && isset($_SESSION['quiz_done'][$quiz['id']]) ? $_SESSION['quiz_done'][$quiz['id']] : null;
$closed = quiz_closed($quiz);
// Élève connecté : sa dernière copie (pour afficher sa note et sa correction après la fermeture).
$mine = null;
$meQ = current_student();
if ($meQ && $meQ['class'] === $level) {
    foreach (quiz_results($quiz['id']) as $r) {
        if ((!empty($r['student']) && $r['student'] === $meQ['id']) || mb_strtolower($r['level'] . '|' . $r['last'] . '|' . $r['first']) === mb_strtolower($level . '|' . $meQ['last'] . '|' . $meQ['first'])) { $mine = $r; break; }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$done) stats_record($path, $level);
$seed = session_id() . $quiz['id'];
release_session();

$crumbs = class_crumbs($level, $data);
$walk = '';
if ($folder !== '') {
    foreach (explode('/', $folder) as $part) {
        $walk = $walk === '' ? $part : $walk . '/' . $part;
        if ($walk === $classHome) continue;
        $crumbs[] = [$part, level_url($level, $walk)];
    }
}
$crumbs[] = [display_name($path), null];

page_top(display_name($path) . ' — QCM');
?>
<div class="container container--text section quiz">
  <?php breadcrumb($crumbs); ?>
  <div class="page-head page-head--compact">
    <div>
      <p class="page-head__kicker"><?= badge('QCM', 'info') ?> <span class="muted small"><?= count($quiz['questions']) ?> question<?= count($quiz['questions']) > 1 ? 's' : '' ?></span></p>
      <h1 class="h2"><?= h($quiz['title'] !== '' ? $quiz['title'] : display_name($path)) ?></h1>
    </div>
    <div class="page-head__actions">
      <a class="btn btn--secondary btn--sm" href="<?= h($back) ?>"><?= icon('back') ?>Retour au dossier</a>
    </div>
  </div>

  <?php if ($done): $g = $done['grade']; ?>
    <section class="quiz-score">
      <p class="quiz-score__label">Résultat de <?= h($done['name']) ?></p>
      <p class="quiz-score__value"><?= (int) $g['score'] ?> / <?= (int) $g['total'] ?></p>
      <p class="quiz-score__sub">soit <?= h(str_replace('.', ',', (string) quiz_on20($g['score'], $g['total']))) ?> / 20 · <?= h(date('d/m/Y à H:i', $done['time'])) ?></p>
      <p class="muted small">Votre résultat a été transmis à votre professeur.</p>
    </section>

    <?php if (quiz_correction_visible($quiz)): ?>
      <h2 class="h3">Correction</h2>
      <?php render_quiz_correction($quiz, $done['answers'], $g['detail']); ?>
    <?php elseif ($quiz['correction'] === 'after_close' && $quiz['until'] !== ''): ?>
      <?php alert('info', 'La correction sera visible ici après la fermeture du QCM, le ' . format_datetime($quiz['until']) . '.'); ?>
    <?php endif; ?>
    <div class="form__actions">
      <a class="btn" href="<?= h($back) ?>"><?= icon('back') ?>Retour au dossier</a>
      <?php if ($quiz['attempts'] !== 1): ?><a class="btn btn--secondary" href="<?= h($self) ?>">Recommencer</a><?php endif; ?>
    </div>

  <?php elseif ($closed): ?>
    <?php alert('info', 'Ce QCM est fermé depuis le ' . format_datetime($quiz['until']) . ' : il n’est plus possible d’y répondre.', 'QCM fermé'); ?>
    <?php if ($mine): ?>
      <section class="quiz-score">
        <p class="quiz-score__label">Ton résultat</p>
        <p class="quiz-score__value"><?= (int) $mine['score'] ?> / <?= (int) $mine['total'] ?></p>
        <p class="quiz-score__sub">soit <?= h(str_replace('.', ',', (string) quiz_on20($mine['score'], $mine['total']))) ?> / 20</p>
      </section>
    <?php endif; ?>
    <?php if (quiz_correction_visible($quiz)): ?>
      <h2 class="h3">Corrigé</h2>
      <?php render_quiz_correction($full, $mine ? $mine['answers'] : null, $mine ? $mine['detail'] : null); ?>
    <?php endif; ?>
    <div class="form__actions"><a class="btn" href="<?= h($back) ?>"><?= icon('back') ?>Retour au dossier</a></div>

  <?php else: ?>
    <?php if ($quiz['until'] !== ''): ?><p class="quiz-deadline"><?= icon('clock') ?><span>À faire avant le <strong><?= h(format_datetime($quiz['until'])) ?></strong> · <span data-countdown="<?= (int) parse_datetime($quiz['until']) ?>"></span></span></p><?php endif; ?>
    <?php if ($quiz['intro'] !== ''): ?><div class="deposit__note"><?= nl2br(h($quiz['intro'])) ?></div><?php endif; ?>
    <?php if ($error !== '') alert('error', $error); ?>
    <form method="post" class="form" action="<?= h($self) ?>" data-quiz-form>
      <?= csrf_field() ?>
      <?php $me = current_student(); if ($me && $me['class'] === $level): ?>
        <p class="student-identity"><?= icon('user') ?><span>Réponses envoyées au nom de <strong><?= h(student_name($me)) ?></strong></span></p>
      <?php else: ?>
      <div class="field-row">
        <div class="field">
          <label for="qz-last">Nom</label>
          <input id="qz-last" class="input" type="text" name="last" required maxlength="60" autocomplete="family-name" value="<?= h(post('last')) ?>">
        </div>
        <div class="field">
          <label for="qz-first">Prénom</label>
          <input id="qz-first" class="input" type="text" name="first" required maxlength="60" autocomplete="given-name" value="<?= h(post('first')) ?>">
        </div>
      </div>
      <?php endif; ?>
      <ol class="quiz-list">
        <?php foreach ($quiz['questions'] as $i => $q): ?>
          <li class="quiz-q">
            <fieldset>
              <legend class="quiz-q__text"><?= nl2br(h($q['text'])) ?>
                <span class="hint"><?= $q['multi'] ? 'Plusieurs réponses possibles' : 'Une seule réponse' ?></span></legend>
              <div class="quiz-options">
                <?php foreach (quiz_option_order($quiz, $i, $seed) as $oi): $id = 'q' . $i . '-' . $oi;
                    $prev = isset($_POST['q'][$i]) && in_array((string) $oi, (array) $_POST['q'][$i], true); ?>
                  <label class="quiz-option" for="<?= $id ?>">
                    <input id="<?= $id ?>" type="<?= $q['multi'] ? 'checkbox' : 'radio' ?>" name="q[<?= $i ?>][]" value="<?= $oi ?>"<?= $prev ? ' checked' : '' ?>>
                    <span><?= h($q['options'][$oi]) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
          </li>
        <?php endforeach; ?>
      </ol>
      <p class="deposit__privacy"><?= icon('info') ?><span>Votre nom, votre classe et vos réponses sont transmis uniquement à votre professeur. <a href="legal.php" target="_blank" rel="noopener">En savoir plus sur vos données</a></span></p>
      <div class="form__actions">
        <button class="btn" type="submit"><?= icon('check') ?>Valider mes réponses</button>
        <span class="muted small"><?= $quiz['attempts'] === 0 ? 'Vous pouvez recommencer autant de fois que vous voulez.' : ($quiz['attempts'] === 1 ? 'Une seule réponse possible : vérifiez avant de valider.' : $quiz['attempts'] . ' essais possibles.') ?></span>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php
page_end();
