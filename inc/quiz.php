<?php
/**
 * QCM auto-corrigés.
 *
 * Un QCM est un fichier « Titre.qcm » (JSON) rangé dans un dossier de cours : il se publie,
 * se déplace et se duplique comme un document. Résultats des élèves :
 * TPWebData/rendus/qcm/<id>.json (archivés avec les rendus en fin d'année).
 *
 * { "id", "title", "intro", "show_correction": true, "shuffle": false,
 *   "questions": [ { "text", "multi": false, "options": ["…"], "correct": [0], "explain": "" } ] }
 */

const QUIZ_EXT = 'qcm';
const QUIZ_MAX_QUESTIONS = 60;
const QUIZ_MAX_OPTIONS = 8;

function is_quiz_file($path)
{
    return file_ext($path) === QUIZ_EXT;
}

function quiz_new_id()
{
    return bin2hex(random_bytes(8));
}

function quiz_normalize($q)
{
    if (!is_array($q)) $q = [];
    $q += ['id' => '', 'title' => '', 'intro' => '', 'show_correction' => true, 'shuffle' => false, 'questions' => [], 'attempts' => 1,
           'until' => '', 'correction' => ''];
    // Correction : immédiate, à la fermeture du QCM, ou jamais (compatibilité : show_correction).
    if (!in_array($q['correction'], ['immediate', 'after_close', 'never'], true)) $q['correction'] = $q['show_correction'] ? 'immediate' : 'never';
    $q['until'] = clean_datetime($q['until']);
    if (!preg_match('/^[a-f0-9]{16}$/', (string) $q['id'])) $q['id'] = '';
    $q['show_correction'] = (bool) $q['show_correction'];
    $q['shuffle'] = (bool) $q['shuffle'];
    $q['attempts'] = max(0, min(10, (int) $q['attempts']));
    $questions = [];
    foreach (is_array($q['questions']) ? $q['questions'] : [] as $item) {
        if (!is_array($item)) continue;
        $item += ['text' => '', 'multi' => false, 'options' => [], 'correct' => [], 'explain' => ''];
        $opts = array_values(array_filter(array_map(function ($o) { return trim((string) $o); }, (array) $item['options']), 'strlen'));
        $correct = array_values(array_unique(array_filter(array_map('intval', (array) $item['correct']), function ($i) use ($opts) {
            return $i >= 0 && $i < count($opts);
        })));
        if (trim((string) $item['text']) === '' || count($opts) < 2 || !$correct) continue;
        $questions[] = ['text' => (string) $item['text'], 'multi' => (bool) $item['multi'] || count($correct) > 1,
                        'options' => array_slice($opts, 0, QUIZ_MAX_OPTIONS), 'correct' => $correct, 'explain' => (string) $item['explain']];
    }
    $q['questions'] = array_slice($questions, 0, QUIZ_MAX_QUESTIONS);
    return $q;
}

function quiz_load($abs)
{
    $raw = @file_get_contents($abs, false, null, 0, 1024 * 1024);
    return quiz_normalize($raw === false ? [] : json_decode($raw, true));
}

function quiz_save($abs, array $q)
{
    $q = quiz_normalize($q);
    if ($q['id'] === '') $q['id'] = quiz_new_id();
    return file_put_contents($abs, json_encode($q, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

/** Après une copie (dupliquer, banque, import) : nouvel identifiant pour ne pas mélanger les résultats. */
function quiz_refresh_ids($abs)
{
    if (is_file($abs)) {
        if (is_quiz_file($abs)) {
            $q = quiz_load($abs);
            $q['id'] = quiz_new_id();
            quiz_save($abs, $q);
        }
        return;
    }
    foreach ((array) @scandir($abs) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $abs . DIRECTORY_SEPARATOR . $f;
        if (!is_link($p)) quiz_refresh_ids($p);
    }
}

function quiz_results_file($id)
{
    if (!preg_match('/^[a-f0-9]{16}$/', (string) $id)) return false;
    $dir = TPW_RENDUS . DIRECTORY_SEPARATOR . 'qcm';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . DIRECTORY_SEPARATOR . $id . '.json';
}

function quiz_results($id)
{
    $f = quiz_results_file($id);
    $list = $f ? load_json($f, []) : [];
    usort($list, function ($a, $b) { return $b['time'] - $a['time']; });
    return $list;
}

/** Ordre d'affichage des réponses (mélange stable par élève si demandé). */
function quiz_option_order(array $quiz, $qi, $seed)
{
    $order = array_keys($quiz['questions'][$qi]['options']);
    if (!$quiz['shuffle']) return $order;
    usort($order, function ($a, $b) use ($seed, $qi) {
        return strcmp(md5($seed . '|' . $qi . '|' . $a), md5($seed . '|' . $qi . '|' . $b));
    });
    return $order;
}

/**
 * Corrige une copie. $answers : [indice question => [indices réponses]].
 * Une question rapporte 1 point si l'ensemble des réponses cochées est exactement le bon.
 */
function quiz_grade(array $quiz, array $answers)
{
    $score = 0;
    $detail = [];
    foreach ($quiz['questions'] as $i => $q) {
        $given = isset($answers[$i]) && is_array($answers[$i]) ? array_values(array_unique(array_map('intval', $answers[$i]))) : [];
        sort($given);
        $good = $q['correct'];
        sort($good);
        $ok = $given === $good;
        if ($ok) $score++;
        $detail[] = ['given' => $given, 'ok' => $ok];
    }
    return ['score' => $score, 'total' => count($quiz['questions']), 'detail' => $detail];
}

/** Nombre de copies déjà rendues par un élève (même classe, même nom). */
function quiz_attempts_of(array $results, $level, $last, $first)
{
    $key = mb_strtolower(trim($last) . ' ' . trim($first));
    $n = 0;
    foreach ($results as $r) {
        if ($r['level'] === $level && mb_strtolower($r['last'] . ' ' . $r['first']) === $key) $n++;
    }
    return $n;
}

function quiz_store_result(array $quiz, array $row)
{
    $file = quiz_results_file($quiz['id']);
    if (!$file) return false;
    $lock = fopen($file . '.lock', 'c');
    if (!$lock) return false;
    flock($lock, LOCK_EX);
    $list = load_json($file, []);
    $list[] = $row;
    atomic_json($file, $list);
    flock($lock, LOCK_UN);
    fclose($lock);
    return true;
}

/** Taux de réussite par question (sur les dernières copies). */
function quiz_question_stats(array $quiz, array $results)
{
    $stats = [];
    foreach ($quiz['questions'] as $i => $q) $stats[$i] = ['ok' => 0, 'n' => 0];
    foreach ($results as $r) {
        foreach ((array) $r['detail'] as $i => $d) {
            if (!isset($stats[$i])) continue;
            $stats[$i]['n']++;
            if (!empty($d['ok'])) $stats[$i]['ok']++;
        }
    }
    return $stats;
}

/** Note sur 20, arrondie au demi-point. */
function quiz_on20($score, $total)
{
    if ($total <= 0) return 0;
    return round($score / $total * 40) / 2;
}

/** Le QCM est-il fermé (date de fin passée) ? */
function quiz_closed(array $quiz)
{
    $u = parse_datetime($quiz['until']);
    return $u !== null && time() >= $u;
}

/** La correction peut-elle être montrée maintenant ? */
function quiz_correction_visible(array $quiz)
{
    return $quiz['correction'] === 'immediate' || ($quiz['correction'] === 'after_close' && quiz_closed($quiz));
}

/** Correction : réponses justes, réponses de l'élève si connues, explications. */
function render_quiz_correction(array $quiz, $answers = null, $detail = null)
{
    ?>
    <ol class="quiz-list">
      <?php foreach ($quiz['questions'] as $i => $q):
          $given = $answers !== null && isset($answers[$i]) ? $answers[$i] : [];
          $ok = $detail !== null ? !empty($detail[$i]['ok']) : null; ?>
        <li class="quiz-q<?= $ok === null ? '' : ($ok ? ' quiz-q--ok' : ' quiz-q--ko') ?>">
          <p class="quiz-q__text"><?= nl2br(h($q['text'])) ?> <?= $ok === null ? '' : ($ok ? badge('Juste', 'success') : badge('Faux', 'error')) ?></p>
          <ul class="quiz-correction">
            <?php foreach ($q['options'] as $oi => $opt):
                $isGood = in_array($oi, $q['correct'], true); $isGiven = in_array($oi, $given, true); ?>
              <li class="<?= $isGood ? 'is-good' : '' ?><?= $isGiven && !$isGood ? ' is-wrong' : '' ?>">
                <?= icon($isGood ? 'check' : ($isGiven ? 'close' : 'chevron')) ?>
                <span><?= h($opt) ?><?= $isGiven ? ' <em class="muted small">(ta réponse)</em>' : '' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if ($q['explain'] !== ''): ?><p class="quiz-explain"><?= icon('info') ?><span><?= nl2br(h($q['explain'])) ?></span></p><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php
}
