<?php
/**
 * Vues : éditeur de QCM et résultats des élèves.
 */

function quiz_option_row($qi, $oi, $text, $ok)
{
    ?>
    <div class="qe-option" data-qe-option>
      <label class="qe-option__ok" title="Bonne réponse"><input type="checkbox" data-name="ok" value="<?= (int) $oi ?>"<?= $ok ? ' checked' : '' ?>><span class="sr-only">Bonne réponse</span></label>
      <input class="input" type="text" data-name="opts" maxlength="500" value="<?= h($text) ?>" placeholder="Réponse">
      <button type="button" class="icon-btn icon-btn--danger" data-qe-remove-option title="Retirer cette réponse"><?= icon('close') ?><span class="sr-only">Retirer</span></button>
    </div>
    <?php
}

function quiz_question_card($qi, array $q)
{
    ?>
    <section class="card qe-question" data-qe-question>
      <div class="qe-question__head">
        <span class="qe-question__num" data-qe-num><?= (int) $qi + 1 ?></span>
        <div class="qe-question__tools">
          <button type="button" class="icon-btn" data-qe-up title="Monter"><?= icon('upload') ?><span class="sr-only">Monter</span></button>
          <button type="button" class="icon-btn" data-qe-down title="Descendre"><?= icon('download') ?><span class="sr-only">Descendre</span></button>
          <button type="button" class="icon-btn icon-btn--danger" data-qe-remove title="Supprimer la question"><?= icon('trash') ?><span class="sr-only">Supprimer</span></button>
        </div>
      </div>
      <div class="field">
        <label>Question</label>
        <textarea class="input input--text" data-name="text" rows="2" maxlength="2000" placeholder="ex. : Quelle est l’unité de la force ?"><?= h($q['text']) ?></textarea>
      </div>
      <p class="qe-hint muted small">Cochez la ou les bonnes réponses.</p>
      <div class="qe-options" data-qe-options>
        <?php foreach ($q['options'] as $oi => $opt) quiz_option_row($qi, $oi, $opt, in_array($oi, $q['correct'], true)); ?>
      </div>
      <div class="qe-question__foot">
        <button type="button" class="btn btn--tertiary btn--sm" data-qe-add-option><?= icon('plus') ?>Ajouter une réponse</button>
        <label class="toggle toggle--sm"><input type="checkbox" data-name="multi" value="1"<?= !empty($q['multi']) ? ' checked' : '' ?>><span>Plusieurs bonnes réponses</span></label>
      </div>
      <details class="schedule"<?= $q['explain'] !== '' ? ' open' : '' ?>>
        <summary><?= icon('info') ?>Explication affichée avec la correction <span class="muted small">(facultatif)</span></summary>
        <textarea class="input input--text" data-name="explain" rows="2" maxlength="1000"><?= h($q['explain']) ?></textarea>
      </details>
    </section>
    <?php
}

function view_quiz(array $data, array $user)
{
    $item = query('item');
    $abs = full_path($item, 'file');
    if (!$abs || !is_quiz_file($item) || !can_manage($item, $user)) {
        alert('error', 'QCM introuvable.');
        return;
    }
    $quiz = quiz_load($abs);
    if ($quiz['id'] === '') { quiz_save($abs, $quiz); $quiz = quiz_load($abs); }
    $results = quiz_results($quiz['id']);
    $tab = query('tab') === 'resultats' ? 'resultats' : 'questions';
    $folder = parent_path($item);
    $vis = visible_targets($item, $data);

    breadcrumb([['Bibliothèque', admin_url('library', $folder)], [display_name($item), null]]);
    ?>
    <div class="page-head page-head--compact">
      <div>
        <p class="page-head__kicker"><?= badge('QCM', 'info') ?> <?= $vis ? class_chips($vis, 6) : '<span class="muted small">non publié — publiez-le depuis la Bibliothèque (onglet Partage)</span>' ?></p>
        <h1><?= h($quiz['title'] !== '' ? $quiz['title'] : display_name($item)) ?></h1>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--secondary btn--sm" href="<?= h(admin_url('library', $item)) ?>#partage"><?= icon('users') ?>Partage</a>
      </div>
    </div>

    <nav class="tabs tabs--page" aria-label="Sections">
      <a class="tabs__tab<?= $tab === 'questions' ? ' is-active' : '' ?>" href="<?= h(admin_url('quiz', $item)) ?>"><?= icon('edit') ?><span>Questions</span><span class="tabs__count"><?= count($quiz['questions']) ?></span></a>
      <a class="tabs__tab<?= $tab === 'resultats' ? ' is-active' : '' ?>" href="<?= h(admin_url('quiz', $item) . '&tab=resultats') ?>"><?= icon('chart') ?><span>Résultats</span><span class="tabs__count"><?= count($results) ?></span></a>
    </nav>

    <?php if ($tab === 'resultats') { quiz_results_view($item, $quiz, $results, $data); return; } ?>

    <form method="post" class="form form--tight quiz-editor" data-quiz-editor>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="quiz_save">
      <input type="hidden" name="item" value="<?= h($item) ?>">
      <section class="card">
        <div class="field">
          <label for="qe-title">Titre affiché</label>
          <input id="qe-title" class="input" type="text" name="title" maxlength="150" value="<?= h($quiz['title']) ?>">
        </div>
        <div class="field">
          <label for="qe-intro">Consigne <span class="hint">facultatif</span></label>
          <textarea id="qe-intro" class="input input--text" name="intro" rows="2" maxlength="1500" placeholder="ex. : Vous avez droit à votre cours. Une seule réponse par question sauf indication."><?= h($quiz['intro']) ?></textarea>
        </div>
        <div class="field-row field-row--3">
          <div class="field">
            <label for="qe-corr">Correction pour l’élève</label>
            <select id="qe-corr" class="input" name="correction">
              <option value="immediate"<?= $quiz['correction'] === 'immediate' ? ' selected' : '' ?>>Juste après sa réponse</option>
              <option value="after_close"<?= $quiz['correction'] === 'after_close' ? ' selected' : '' ?>>Publiée automatiquement à la fermeture</option>
              <option value="never"<?= $quiz['correction'] === 'never' ? ' selected' : '' ?>>Jamais (note seulement)</option>
            </select>
          </div>
          <div class="field">
            <label for="qe-until">Fermeture du QCM <span class="hint">vide = toujours ouvert</span></label>
            <input id="qe-until" class="input" type="datetime-local" name="until" value="<?= h($quiz['until']) ?>">
          </div>
          <label class="toggle"><input type="checkbox" name="shuffle" value="1"<?= $quiz['shuffle'] ? ' checked' : '' ?>><span>Mélanger l’ordre des réponses</span></label>
          <div class="field">
            <label for="qe-attempts">Essais par élève</label>
            <select id="qe-attempts" class="input" name="attempts">
              <?php foreach ([1 => '1 seul essai', 2 => '2 essais', 3 => '3 essais', 0 => 'Illimité (entraînement)'] as $v => $l): ?>
                <option value="<?= $v ?>"<?= $quiz['attempts'] === $v ? ' selected' : '' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </section>

      <div class="qe-list" data-qe-list>
        <?php foreach ($quiz['questions'] as $qi => $q) quiz_question_card($qi, $q); ?>
      </div>
      <?php if (!$quiz['questions']): ?>
        <p class="muted" data-qe-empty>Aucune question pour l’instant : cliquez sur « Ajouter une question ».</p>
      <?php endif; ?>

      <template data-qe-template><?php quiz_question_card(0, ['text' => '', 'multi' => false, 'options' => ['', '', ''], 'correct' => [], 'explain' => '']); ?></template>
      <template data-qe-option-template><?php quiz_option_row(0, 0, '', false); ?></template>

      <div class="form__actions form__actions--sticky">
        <button type="button" class="btn btn--secondary" data-qe-add><?= icon('plus') ?>Ajouter une question</button>
        <button class="btn" type="submit">Enregistrer le QCM</button>
        <a class="btn btn--tertiary" href="<?= h(admin_url('library', $item)) ?>">Retour</a>
      </div>
    </form>
    <?php
}

function quiz_results_view($item, array $quiz, array $results, array $data)
{
    $level = query('level');
    $levels = array_values(array_unique(array_map(function ($r) { return $r['level']; }, $results)));
    natcasesort($levels);
    $rows = $level !== '' ? array_values(array_filter($results, function ($r) use ($level) { return $r['level'] === $level; })) : $results;
    $total = count($quiz['questions']);
    $avg = $rows ? array_sum(array_map(function ($r) { return $r['total'] ? $r['score'] / $r['total'] : 0; }, $rows)) / count($rows) : 0;
    $stats = quiz_question_stats($quiz, $rows);
    $base = admin_url('quiz', $item) . '&tab=resultats';
    ?>
    <?php if (!$results): ?>
      <div class="empty"><?= icon('chart') ?><p><strong>Aucune réponse pour le moment.</strong></p><p class="muted">Publiez le QCM pour une classe : les résultats apparaîtront ici dès qu’un élève l’aura validé.</p></div>
      <?php return; ?>
    <?php endif; ?>

    <div class="toolbar">
      <form method="get" class="inline-form">
        <input type="hidden" name="view" value="quiz"><input type="hidden" name="item" value="<?= h($item) ?>"><input type="hidden" name="tab" value="resultats">
        <label for="qr-level" class="sr-only">Classe</label>
        <select id="qr-level" class="input input--short" name="level" onchange="this.form.submit()">
          <option value="">Toutes les classes</option>
          <?php foreach ($levels as $lv): ?><option value="<?= h($lv) ?>"<?= $lv === $level ? ' selected' : '' ?>><?= h($lv) ?></option><?php endforeach; ?>
        </select>
      </form>
      <div class="link-actions">
        <a class="btn btn--secondary btn--sm" href="<?= h('admin.php?dl=quiz_csv&item=' . rawurlencode($item) . ($level !== '' ? '&level=' . rawurlencode($level) : '')) ?>"><?= icon('download') ?>Exporter (CSV, tableur)</a>
      </div>
    </div>

    <dl class="figures">
      <div><dt>Copies</dt><dd><?= count($rows) ?></dd></div>
      <div><dt>Moyenne</dt><dd><?= h(str_replace('.', ',', (string) round($avg * 20, 1))) ?><small> / 20</small></dd></div>
      <div><dt>Questions</dt><dd><?= $total ?></dd></div>
    </dl>

    <section class="card">
      <h2 class="h4">Réussite par question</h2>
      <ol class="qstats">
        <?php foreach ($quiz['questions'] as $i => $q): $st = $stats[$i]; $pct = $st['n'] ? round($st['ok'] / $st['n'] * 100) : 0; ?>
          <li>
            <span class="qstats__text"><?= h(mb_strimwidth($q['text'], 0, 90, '…')) ?></span>
            <span class="meter<?= $pct < 40 ? ' meter--low' : ($pct < 70 ? ' meter--mid' : '') ?>"><span style="width:<?= (int) $pct ?>%"></span></span>
            <span class="qstats__pct"><?= (int) $pct ?> %</span>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>

    <section class="card card--flush">
      <div class="card__header"><h2 class="h4">Copies</h2>
        <input type="search" class="input list-filter list-filter--inline" placeholder="Rechercher un élève…" data-list-filter="#quiz-table" aria-label="Rechercher un élève"></div>
      <div class="table-wrap table-wrap--flush">
        <table class="table table--rows" id="quiz-table">
          <thead><tr><th>Élève</th><th>Classe</th><th class="num">Score</th><th class="num">Sur 20</th><th>Date</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr data-filter-text="<?= h(fold_text($r['last'] . ' ' . $r['first'] . ' ' . $r['level'])) ?>">
                <td><strong><?= h(mb_strtoupper($r['last'])) ?></strong> <?= h($r['first']) ?></td>
                <td><?= h($r['level']) ?><?= !empty($r['groups']) ? ' <span class="muted small">' . h(implode(', ', $r['groups'])) . '</span>' : '' ?></td>
                <td class="num"><?= (int) $r['score'] ?> / <?= (int) $r['total'] ?></td>
                <td class="num"><strong><?= h(str_replace('.', ',', (string) quiz_on20($r['score'], $r['total']))) ?></strong></td>
                <td><?= h(date('d/m/Y H:i', $r['time'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card card--danger">
      <form method="post" class="inline-form" data-confirm="Effacer tous les résultats de ce QCM ? Cette action est définitive.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="quiz_reset">
        <input type="hidden" name="item" value="<?= h($item) ?>">
        <button class="btn btn--tertiary btn--danger" type="submit"><?= icon('trash') ?>Effacer les résultats</button>
        <span class="muted small">Pensez à exporter les résultats avant.</span>
      </form>
    </section>
    <?php
}
