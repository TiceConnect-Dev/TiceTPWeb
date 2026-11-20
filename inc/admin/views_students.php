<?php
/**
 * Vues : dépôts des élèves, mode examen, codes d'accès.
 */

function view_deposits(array $data, array $user)
{
    $all = array_filter(deposits_config(), function ($c, $p) use ($user) {
        return can_manage((string) $p, $user) && full_path((string) $p, 'dir');
    }, ARRAY_FILTER_USE_BOTH);
    $item = query('item');

    if ($item !== '' && isset($all[$item])) {
        view_deposit_detail($item, deposit_normalize($all[$item]));
        return;
    }
    ?>
    <div class="page-head">
      <div>
        <h1>Dépôts des élèves</h1>
        <p class="muted">Les travaux rendus par les élèves, dossier par dossier. Pour ouvrir un nouveau dépôt, sélectionnez un dossier dans la Bibliothèque.</p>
      </div>
      <div class="page-head__actions"><a class="btn btn--secondary" href="<?= h(admin_url('library')) ?>"><?= icon('library') ?>Ouvrir un dépôt</a></div>
    </div>

    <?php if (!$all): ?>
      <div class="empty"><?= icon('inbox') ?><p><strong>Aucun dépôt pour le moment.</strong></p><p class="muted">Dans la Bibliothèque, choisissez le dossier d’un TP puis activez « Dépôt des travaux des élèves ».</p></div>
      <?php return; ?>
    <?php endif; ?>

    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Dossier</th><th>Classes</th><th>Date limite</th><th>État</th><th class="num">Rendus</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($all as $path => $cfg): $cfg = deposit_normalize($cfg); $n = count(deposit_entries($cfg)); ?>
            <tr>
              <td><a href="<?= h(admin_url('deposits', (string) $path)) ?>"><strong><?= h(basename((string) $path)) ?></strong></a><br><span class="muted small"><?= h($path) ?></span></td>
              <td><?= $cfg['levels'] ? class_chips($cfg['levels'], 4) : '<span class="muted">—</span>' ?></td>
              <td><?= $cfg['until'] !== '' ? h(format_datetime($cfg['until'])) : '<span class="muted">Sans limite</span>' ?></td>
              <td><?= deposit_state_badge($cfg) ?></td>
              <td class="num"><strong><?= $n ?></strong></td>
              <td class="actions"><a class="btn btn--tertiary btn--sm" href="<?= h(admin_url('deposits', (string) $path)) ?>">Voir</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function view_deposit_detail($item, array $cfg)
{
    $entries = deposit_entries($cfg);
    $dups = deposit_duplicates($cfg, $entries);
    $dupOf = [];
    foreach ($dups as $hash => $idx) {
        foreach ($idx as $i) {
            $others = [];
            foreach ($idx as $j) if ($j !== $i) $others[] = deposit_members_text($entries[$j]) . ' (' . $entries[$j]['level'] . ')';
            $dupOf[$i] = array_values(array_unique($others));
        }
    }
    $byLevel = [];
    foreach ($entries as $i => $e) $byLevel[$e['level']][$i] = $e;
    $students = [];
    foreach ($entries as $e) foreach (deposit_members($e) as $m) $students[$e['level'] . '|' . mb_strtolower($m['last'] . ' ' . $m['first'])] = true;
    $lateCount = count(array_filter($entries, function ($e) use ($cfg) {
        return !empty($e['late']) || ($cfg['until'] !== '' && $e['time'] > parse_datetime($cfg['until']));
    }));
    $base = 'admin.php?item=' . rawurlencode($item);

    breadcrumb([['Dépôts des élèves', admin_url('deposits')], [basename($item), null]]);
    ?>
    <div class="page-head">
      <div>
        <p class="page-head__kicker"><?= deposit_state_badge($cfg) ?> <?= $cfg['levels'] ? class_chips($cfg['levels'], 6) : '' ?></p>
        <h1><?= h(basename($item)) ?></h1>
        <p class="muted">
          <?= count($entries) ?> fichier<?= count($entries) > 1 ? 's' : '' ?> · <?= count($students) ?> élève<?= count($students) > 1 ? 's' : '' ?>
          <?= $cfg['until'] !== '' ? ' · date limite : ' . h(format_datetime($cfg['until'])) : '' ?>
          <?= $lateCount ? ' · ' . $lateCount . ' en retard' : '' ?>
          <?= $cfg['group_max'] > 1 ? ' · rendus par groupes de ' . (int) $cfg['group_max'] . ' maximum' : '' ?>
        </p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--secondary" href="<?= h(admin_url('library', $item)) ?>#depot"><?= icon('settings') ?>Réglages du dépôt</a>
        <?php if ($entries): ?>
          <a class="btn" href="<?= h($base . '&dl=rendus_zip') ?>"><?= icon('download') ?>Tout télécharger (.zip)</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($dups): ?>
      <?php alert('warning', count($dupOf) . ' fichier(s) strictement identique(s) déposé(s) par des élèves différents (même contenu, octet pour octet). Vérifiez les lignes marquées « Identique ». Un même fichier peut aussi être légitime (sujet rendu vierge, travail de groupe).', 'Copies identiques détectées'); ?>
    <?php endif; ?>

    <?php deposit_tracking_card($item, $cfg, $entries); ?>

    <?php if (!$entries): ?>
      <div class="empty"><?= icon('inbox') ?><p><strong>Aucun rendu pour le moment.</strong></p>
        <p class="muted">Classes concernées : <?= $cfg['levels'] ? h(implode(', ', array_map('target_label', $cfg['levels']))) : 'aucune' ?>.</p></div>
    <?php else: ?>
      <input type="search" class="input list-filter" placeholder="Rechercher un élève ou un numéro de reçu…" data-list-filter=".deposit-table" aria-label="Rechercher un élève ou un reçu">
    <?php endif; ?>

    <?php foreach ($byLevel as $level => $list): ?>
      <section class="card card--flush">
        <div class="card__header">
          <h2 class="h4"><?= h($level) ?> <span class="muted small">— <?= count($list) ?> fichier<?= count($list) > 1 ? 's' : '' ?></span></h2>
          <a class="btn btn--tertiary btn--sm" href="<?= h($base . '&dl=rendus_zip&level=' . rawurlencode($level)) ?>"><?= icon('download') ?>Télécharger la classe</a>
        </div>
        <div class="table-wrap table-wrap--flush">
          <table class="table table--rows deposit-table">
            <thead><tr><th>Élève(s)</th><th>Fichier</th><th>Déposé le</th><th>Reçu</th><th class="num">Taille</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($list as $i => $e):
                  $late = !empty($e['late']) || ($cfg['until'] !== '' && $e['time'] > parse_datetime($cfg['until']));
                  $members = deposit_members($e); ?>
                <tr data-filter-text="<?= h(fold_text(deposit_members_text($e) . ' ' . (isset($e['receipt']) ? $e['receipt'] : '') . ' ' . $e['orig'])) ?>"<?= isset($dupOf[$i]) ? ' class="is-flagged"' : '' ?>>
                  <td>
                    <?php foreach ($members as $k => $m): ?>
                      <?= $k ? '<br>' : '' ?><strong><?= h(mb_strtoupper($m['last'])) ?></strong> <?= h($m['first']) ?>
                    <?php endforeach; ?>
                    <?= count($members) > 1 ? '<br>' . badge('Groupe de ' . count($members), 'info') : '' ?>
                  </td>
                  <td class="break"><?= h($e['orig']) ?>
                    <?php if (isset($dupOf[$i])): ?><br><span class="badge badge--error" title="<?= h('Identique à : ' . implode(', ', $dupOf[$i])) ?>">Identique</span> <span class="muted small">à <?= h(implode(', ', $dupOf[$i])) ?></span><?php endif; ?>
                  </td>
                  <td><?= h(date('d/m/Y H:i', $e['time'])) ?><?= $late ? ' ' . badge('En retard', 'warning') : '' ?></td>
                  <td><?= !empty($e['receipt']) ? '<code class="small">' . h($e['receipt']) . '</code>' : '<span class="muted">—</span>' ?></td>
                  <td class="num"><?= h(human_size($e['size'])) ?></td>
                  <td class="actions">
                    <a class="icon-btn" href="<?= h($base . '&dl=rendu&f=' . rawurlencode($e['file'])) ?>" title="Télécharger"><?= icon('download') ?><span class="sr-only">Télécharger</span></a>
                    <form method="post" class="inline-form" data-confirm="Supprimer ce rendu de <?= h(deposit_members_text($e)) ?> ?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="deposit_delete_entry">
                      <input type="hidden" name="folder" value="<?= h($item) ?>">
                      <input type="hidden" name="file" value="<?= h($e['file']) ?>">
                      <button class="icon-btn icon-btn--danger" type="submit" title="Supprimer"><?= icon('trash') ?><span class="sr-only">Supprimer</span></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>

    <section class="card card--danger">
      <h2 class="h4">Zone de nettoyage</h2>
      <p class="muted">Pensez à télécharger l’archive avant de supprimer les rendus.</p>
      <div class="form__actions">
        <form method="post" class="inline-form" data-confirm="Supprimer TOUS les rendus de ce dépôt ? Cette action est définitive.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="deposit_purge">
          <input type="hidden" name="folder" value="<?= h($item) ?>">
          <button class="btn btn--tertiary btn--danger" type="submit"><?= icon('trash') ?>Vider les rendus</button>
        </form>
        <form method="post" class="inline-form" data-confirm="Supprimer ce dépôt, ses réglages et tous ses rendus ?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="deposit_purge">
          <input type="hidden" name="remove" value="1">
          <input type="hidden" name="folder" value="<?= h($item) ?>">
          <button class="btn btn--tertiary btn--danger" type="submit">Supprimer le dépôt</button>
        </form>
      </div>
    </section>
    <?php
}

function view_exams(array $data, array $user)
{
    $active = active_exams($data);
    $users = users_all();
    $folders = array_values(array_filter(folder_list(), function ($f) use ($user) {
        return can_manage($f, $user);
    }));
    ?>
    <div class="page-head">
      <div>
        <h1>Mode examen</h1>
        <p class="muted">Pendant une épreuve, les classes choisies ne voient plus qu’un seul dossier : toutes leurs autres ressources sont masquées. Le mode s’arrête automatiquement à l’heure de fin. Les autres classes ne sont pas concernées.</p>
      </div>
    </div>

    <?php if ($active): ?>
      <h2 class="h4">En cours</h2>
      <div class="exam-list">
        <?php foreach ($active as $exam): ?>
          <section class="card card--exam">
            <div>
              <p class="exam__label"><?= icon('exam') ?> Examen en cours</p>
              <h3 class="h4"><?= h(basename($exam['path'])) ?></h3>
              <p class="muted small"><code><?= h($exam['path']) ?></code></p>
              <ul class="tags"><?php foreach ($exam['levels'] as $lv): ?><li><?= h($lv) ?></li><?php endforeach; ?></ul>
              <p class="small">Fin prévue <strong><?= h(format_datetime($exam['until'])) ?></strong>
                <?php if (!empty($exam['extra_minutes']) && !empty($exam['extra_groups'])): ?> · <?= badge('+' . (int) $exam['extra_minutes'] . ' min', 'warning') ?> pour <?= h(implode(', ', $exam['extra_groups'])) ?><?php endif; ?>
                · lancé par <?= h(isset($users[$exam['by']]) ? $users[$exam['by']]['name'] : $exam['by']) ?></p>
            </div>
            <form method="post" data-confirm="Terminer maintenant le mode examen ?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="exam_stop">
              <input type="hidden" name="id" value="<?= h($exam['id']) ?>">
              <button class="btn btn--danger-solid" type="submit">Terminer l’examen</button>
            </form>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <section class="card">
      <h2 class="h4">Lancer un examen</h2>
      <?php if (!$folders): ?>
        <p class="muted">Aucun dossier disponible. Créez d’abord le dossier de l’épreuve dans la Bibliothèque.</p>
      <?php else: ?>
        <form method="post" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="exam_start">
          <div class="field">
            <label for="exam-folder">Dossier de l’épreuve <span class="hint">seul ce dossier (et son contenu) restera visible</span></label>
            <select id="exam-folder" class="input" name="folder" required>
              <option value="">— Choisir un dossier —</option>
              <?php foreach ($folders as $f): ?>
                <option value="<?= h($f) ?>"><?= str_repeat('&nbsp;&nbsp;&nbsp;', substr_count($f, '/')) . h(basename($f)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <fieldset class="fieldset">
            <legend>Classes en examen</legend>
            <?php level_checkboxes($data, [], 'levels[]', ['groups' => false]); ?>
          </fieldset>
          <div class="field">
            <label for="exam-until">Fin automatique</label>
            <input id="exam-until" class="input input--short" type="datetime-local" name="until" required value="<?= h(date('Y-m-d\TH:i', time() + 7200 - time() % 300)) ?>">
          </div>
          <?php $gnames = all_group_names($data); ?>
          <details class="schedule">
            <summary><?= icon('hourglass') ?>Tiers-temps et temps supplémentaire <span class="muted small">(facultatif)</span></summary>
            <?php if (!$gnames): ?>
              <p class="muted small">Créez d’abord un groupe (ex. : « Tiers-temps ») dans les classes concernées, depuis le menu Groupes. Les élèves concernés le choisissent sur la page de leur classe, avec un code si vous le souhaitez.</p>
            <?php else: ?>
              <div class="field-row">
                <div class="field">
                  <label for="exam-extra">Temps supplémentaire</label>
                  <select id="exam-extra" class="input" name="extra_minutes">
                    <option value="0">Aucun</option>
                    <?php foreach ([10, 15, 20, 30, 40, 45, 60, 90] as $m): ?><option value="<?= $m ?>"><?= $m ?> minutes</option><?php endforeach; ?>
                  </select>
                  <span class="hint">Un tiers-temps sur une épreuve de 2 h = 40 minutes.</span>
                </div>
                <div class="field">
                  <span class="label">Pour les élèves du groupe</span>
                  <div class="pills">
                    <?php foreach ($gnames as $g): ?>
                      <label class="pill"><input type="checkbox" name="extra_groups[]" value="<?= h($g) ?>"<?= preg_match('/tiers/i', $g) ? ' checked' : '' ?>><span><?= h($g) ?></span></label>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </details>
          <?php alert('info', 'Le contenu du dossier d’épreuve est visible pour les classes choisies même s’il n’est pas publié. Un dépôt des travaux configuré dans ce dossier reste utilisable.'); ?>
          <div class="form__actions"><button class="btn" type="submit"><?= icon('exam') ?>Activer le mode examen</button></div>
        </form>
      <?php endif; ?>
    </section>
    <?php
}

function view_access(array $data)
{
    $codes = class_access_data();
    ?>
    <div class="page-head">
      <div>
        <h1>Codes d’accès</h1>
        <p class="muted">Sans code, une classe est accessible librement depuis le réseau de l’établissement. Avec un code de 4 caractères, les élèves le saisissent une fois par session. Changer un code déconnecte les élèves déjà entrés.</p>
      </div>
    </div>

    <?php if (!$data['levels']): ?>
      <div class="empty"><?= icon('info') ?><p>Aucune classe configurée.</p></div>
    <?php endif; ?>

    <?php
    $groups = [];
    $singles = [];
    foreach (class_structure($data) as $item) {
        if ($item['type'] === 'level') $groups[$item['name']] = $item['classes'];
        else $singles[] = $item['name'];
    }
    if ($singles) $groups[$groups ? 'Autres classes' : ''] = $singles;
    foreach ($groups as $title => $classes): ?>
      <?php if ($title !== ''): ?><h2 class="h4 group-title"><?= h($title) ?></h2><?php endif; ?>
      <div class="code-grid">
        <?php foreach ($classes as $lev) code_card($lev, $codes); ?>
      </div>
    <?php endforeach;
}

function view_announcements(array $data, array $user)
{
    $active = active_announcements($data);
    $users = users_all();
    ?>
    <div class="page-head">
      <div>
        <h1>Annonces</h1>
        <p class="muted">Un message affiché en haut de la page des classes choisies : TP reporté, matériel à apporter, rappel de date limite…</p>
      </div>
    </div>

    <?php if ($active): ?>
      <div class="announce-admin">
        <?php foreach ($active as $a): $mine = $a['by'] === $user['login']; ?>
          <article class="announcement announcement--<?= h($a['type']) ?>">
            <?= icon($a['type'] === 'important' ? 'alert' : 'megaphone') ?>
            <div class="announcement__body">
              <p class="announcement__title"><?= h($a['title']) ?></p>
              <?php if ($a['text'] !== ''): ?><p><?= nl2br(h($a['text'])) ?></p><?php endif; ?>
              <p class="announcement__meta">
                <?= h(implode(', ', $a['levels'])) ?> ·
                <?= h(isset($users[$a['by']]) ? $users[$a['by']]['name'] : $a['by']) ?>
                <?= $a['from'] !== '' && parse_datetime($a['from']) > time() ? ' · visible à partir du ' . h(format_datetime($a['from'])) : '' ?>
                <?= $a['until'] !== '' ? ' · jusqu’au ' . h(format_datetime($a['until'])) : ' · sans limite' ?>
              </p>
            </div>
            <?php if ($mine || is_admin($user)): ?>
              <form method="post" class="inline-form" data-confirm="Supprimer cette annonce ?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="announce_delete">
                <input type="hidden" name="id" value="<?= h($a['id']) ?>">
                <button class="icon-btn icon-btn--danger" type="submit" title="Supprimer"><?= icon('trash') ?><span class="sr-only">Supprimer</span></button>
              </form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <section class="card">
      <h2 class="h4">Nouvelle annonce</h2>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="announce_save">
        <div class="field">
          <label for="an-title">Titre</label>
          <input id="an-title" class="input" type="text" name="title" required maxlength="120" placeholder="ex. : TP3 reporté à jeudi">
        </div>
        <div class="field">
          <label for="an-text">Message <span class="hint">facultatif</span></label>
          <textarea id="an-text" class="input input--text" name="text" rows="3" maxlength="1000"></textarea>
        </div>
        <fieldset class="fieldset">
          <legend>Importance</legend>
          <div class="choices">
            <label class="choice"><input type="radio" name="type" value="info" checked><span class="choice__body"><strong>Information</strong><span>Bandeau bleu.</span></span></label>
            <label class="choice"><input type="radio" name="type" value="important"><span class="choice__body"><strong>Important</strong><span>Bandeau orange, affiché en premier.</span></span></label>
          </div>
        </fieldset>
        <fieldset class="fieldset">
          <legend>Classes</legend>
          <?php level_checkboxes($data, []); ?>
        </fieldset>
        <div class="field-row">
          <div class="field">
            <label for="an-from">Visible à partir du <span class="hint">vide = maintenant</span></label>
            <input id="an-from" class="input" type="datetime-local" name="from">
          </div>
          <div class="field">
            <label for="an-until">Retirée automatiquement le <span class="hint">vide = jusqu’à suppression</span></label>
            <input id="an-until" class="input" type="datetime-local" name="until" value="<?= h(date('Y-m-d\T18:00', strtotime('+7 days'))) ?>">
          </div>
        </div>
        <div class="form__actions"><button class="btn" type="submit"><?= icon('megaphone') ?>Publier l’annonce</button></div>
      </form>
    </section>
    <?php
}

function code_card($lev, array $codes)
{
    $enabled = class_code_enabled($lev, $codes);
    $visible = isset($codes[$lev]['display']) ? (string) $codes[$lev]['display'] : '';
    $id = 'classe-' . md5($lev);
    ?>
        <form method="post" class="card code-card" id="<?= h($id) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="class_code">
      <input type="hidden" name="level" value="<?= h($lev) ?>">
      <div class="code-card__head">
        <h2 class="h4"><?= h($lev) ?></h2>
        <?= $enabled ? badge('Protégée', 'info') : badge('Accès libre', '') ?>
      </div>
      <?php if ($enabled && $visible !== ''): ?>
        <div class="code-display">
          <span class="code-display__label">Code actuel</span>
          <strong class="code-display__value"><?= h($visible) ?></strong>
          <button type="button" class="btn btn--tertiary btn--sm" data-copy="<?= h($visible) ?>"><?= icon('copy') ?>Copier</button>
        </div>
      <?php elseif ($enabled): ?>
        <p class="muted small">Code créé avec une ancienne version : il ne peut pas être affiché. Remplacez-le pour pouvoir le consulter.</p>
      <?php endif; ?>
      <label class="toggle">
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <span>Protéger cette classe par un code</span>
      </label>
      <div class="field">
        <label for="<?= h($id) ?>-code"><?= $enabled ? 'Nouveau code <span class="hint">Laisser vide pour conserver le code actuel</span>' : 'Code <span class="hint">4 caractères, lettres et chiffres (ex. : A7B2)</span>' ?></label>
        <input id="<?= h($id) ?>-code" class="input input--code" type="text" name="class_code" minlength="4" maxlength="4"
               pattern="[A-Za-z0-9]{4}" autocomplete="off" autocapitalize="characters" spellcheck="false">
      </div>
      <div class="form__actions"><button class="btn btn--secondary" type="submit">Enregistrer</button></div>
    </form>
    <?php
}

function view_groups(array $data, array $user)
{
    $admin = is_admin($user);
    $mine = array_values(array_intersect($data['levels'], $user['classes']));
    $classes = $admin ? $data['levels'] : $mine;
    $withGroups = array_values(array_filter($classes, function ($c) use ($data) { return (bool) class_groups($data, $c); }));
    ?>
    <div class="page-head">
      <div>
        <h1>Groupes</h1>
        <p class="muted">Un groupe est une partie d’une classe : groupe A / B, spécialité, groupe de besoin, élèves en tiers-temps…</p>
      </div>
    </div>

    <ol class="howto">
      <li><strong>Créez le groupe</strong> ci-dessous, dans la bonne classe.</li>
      <li><strong>Partagez</strong> une ressource, un dépôt ou une annonce : le groupe apparaît à côté de sa classe dans la liste des classes.</li>
      <li><strong>Les élèves</strong> : si la liste des élèves est importée, répartissez-les (bouton « Répartir les élèves ») — ceux qui se connectent par l’ENT retrouvent leurs groupes automatiquement. Sinon, chaque élève choisit son groupe à son arrivée dans la classe ; un code empêche de rejoindre un groupe sans y être invité.</li>
    </ol>

    <?php if (!$classes): ?>
      <div class="empty"><?= icon('group') ?><p><strong>Aucune classe.</strong></p><p class="muted">Choisissez d’abord vos classes dans <a href="<?= h(admin_url('account')) ?>#mes-classes">Mon compte</a>.</p></div>
      <?php return; ?>
    <?php endif; ?>

    <?php if (count($classes) > 8): ?>
      <input type="search" class="input list-filter" placeholder="Rechercher une classe…" data-list-filter="#groups-grid" aria-label="Rechercher une classe">
    <?php endif; ?>
    <div class="group-grid" id="groups-grid">
      <?php foreach ($classes as $c): $groups = class_groups($data, $c); $id = 'groupes-' . md5($c); ?>
        <section class="card group-card" id="<?= h($id) ?>" data-filter-text="<?= h(fold_text($c . ' ' . implode(' ', group_names($data, $c)))) ?>">
          <div class="code-card__head">
            <h2 class="h4"><?= h($c) ?></h2>
            <?= $groups ? badge(count($groups) . ' groupe' . (count($groups) > 1 ? 's' : ''), 'info') : '' ?>
          </div>
          <?php if ($groups): ?>
            <ul class="group-chips">
              <?php foreach ($groups as $g): ?>
                <li class="group-chip">
                  <span><?= icon('group') ?><?= h($g['name']) ?><?php if ($g['display'] !== ''): ?> <span class="group-chip__code" title="Code à donner aux élèves du groupe"><?= icon('lock') ?><?= h($g['display']) ?></span><?php endif; ?></span>
                  <form method="post" class="inline-form" data-confirm="<?= h('Supprimer le groupe « ' . $g['name'] . ' » de ' . $c . ' ? Il sera retiré des publications et des dépôts.') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="group_remove">
                    <input type="hidden" name="class" value="<?= h($c) ?>">
                    <input type="hidden" name="name" value="<?= h($g['name']) ?>">
                    <button class="icon-btn icon-btn--danger" type="submit" title="Supprimer"><?= icon('close') ?><span class="sr-only">Supprimer</span></button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="muted small">Aucun groupe.</p>
          <?php endif; ?>
          <?php $nb = count(students_of_class($c)); if ($groups && $nb): ?>
            <p><a class="btn btn--secondary btn--sm" href="<?= h(admin_url('groups') . '&class=' . rawurlencode($c)) ?>"><?= icon('users') ?>Répartir les <?= $nb ?> élèves</a></p>
          <?php endif; ?>
          <details class="group-add">
            <summary><?= icon('plus') ?>Ajouter un groupe</summary>
            <form method="post" class="form form--tight">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="group_add">
              <input type="hidden" name="class" value="<?= h($c) ?>">
              <div class="group-add__row">
                <div class="field">
                  <label for="<?= h($id) ?>-name">Nom</label>
                  <input id="<?= h($id) ?>-name" class="input" type="text" name="name" required maxlength="40" placeholder="ex. : Groupe A" list="group-suggestions">
                </div>
                <div class="field">
                  <label for="<?= h($id) ?>-code">Code <span class="hint">facultatif</span></label>
                  <input id="<?= h($id) ?>-code" class="input input--code" type="text" name="code" maxlength="4" pattern="[A-Za-z0-9]{4}" placeholder="K7P2" autocomplete="off">
                </div>
              </div>
              <div class="form__actions"><button class="btn btn--secondary btn--sm" type="submit"><?= icon('plus') ?>Créer</button></div>
            </form>
          </details>
        </section>
      <?php endforeach; ?>
    </div>
    <datalist id="group-suggestions">
      <option value="Groupe A"><option value="Groupe B"><option value="Groupe 1"><option value="Groupe 2"><option value="Tiers-temps"><option value="Soutien"><option value="Approfondissement">
    </datalist>
    <?php if (!$admin && count($mine) < count($data['levels'])): ?>
      <p class="muted small">Seules vos classes sont affichées. Pour en ajouter : <a href="<?= h(admin_url('account')) ?>#mes-classes">Mon compte › Ma matière et mes classes</a>.</p>
    <?php endif; ?>
    <?php
}

/**
 * Suivi par élève : qui a rendu, qui n'a pas rendu (avec la liste des élèves), relance,
 * notes et commentaires (publiables aux élèves).
 */
function deposit_tracking_card($item, array $cfg, array $entries)
{
    $roster = deposit_roster($cfg);
    $submitters = deposit_submitters($entries);
    $fb = deposit_feedback($cfg);
    $rows = [];
    $seen = [];
    foreach ($roster as $id => $s) {
        $st = deposit_student_status($s, $submitters);
        $rows['s:' . $id] = ['name' => student_name($s), 'class' => $s['class'], 'status' => $st, 'id' => $id];
        $seen['s:' . $id] = true;
        $seen[deposit_name_key($s['class'], $s['last'], $s['first'])] = true;
    }
    // Élèves qui ont rendu sans figurer dans la liste.
    foreach ($entries as $e) {
        if (!empty($e['student']) && isset($seen['s:' . $e['student']])) continue;
        foreach (deposit_members($e) as $m) {
            $k = deposit_name_key($e['level'], $m['last'], $m['first']);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $rows[$k] = ['name' => trim($m['first'] . ' ' . mb_strtoupper($m['last'])), 'class' => $e['level'], 'status' => $submitters[$k], 'id' => ''];
        }
    }
    if (!$rows) return;
    $missing = array_filter($rows, function ($r) { return $r['status'] === null; });
    $done = count(array_filter($roster, function ($st) use ($rows) { return $rows['s:' . $st['id']]['status'] !== null; }));
    $hasRoster = (bool) $roster;
    ?>
    <section class="card" id="suivi">
      <div class="card__title-row">
        <h2 class="h4"><?= icon('users') ?> Suivi et évaluation</h2>
        <?php if ($hasRoster): ?><span class="track-count"><strong><?= $done ?></strong> / <?= count($roster) ?> élève<?= count($roster) > 1 ? 's' : '' ?> ont rendu</span><?php endif; ?>
      </div>
      <?php if ($hasRoster): ?>
        <div class="meter meter--track"><span style="width:<?= (int) round($done / max(1, count($roster)) * 100) ?>%"></span></div>
      <?php else: ?>
        <p class="muted small">Importez la liste des élèves (menu Élèves) pour voir aussi ceux qui n’ont pas rendu.</p>
      <?php endif; ?>
      <form method="post" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="deposit_feedback">
        <input type="hidden" name="folder" value="<?= h($item) ?>">
        <?php if (count($rows) > 10): ?><input type="search" class="input list-filter" placeholder="Rechercher un élève…" data-list-filter="#track-table" aria-label="Rechercher un élève"><?php endif; ?>
        <div class="table-wrap">
          <table class="table table--rows" id="track-table">
            <thead><tr><th>Élève</th><th>Statut</th><th class="grade-col">Note</th><th>Commentaire</th><?php if ($missing): ?><th class="center">Relancer</th><?php endif; ?></tr></thead>
            <tbody>
              <?php foreach ($rows as $key => $r): $f = isset($fb[$key]) ? $fb[$key] : ['grade' => '', 'comment' => '']; $kid = md5($key); ?>
                <tr data-filter-text="<?= h(fold_text($r['name'] . ' ' . $r['class'])) ?>" class="<?= $r['status'] === null ? 'is-missing' : '' ?>">
                  <td><strong><?= h($r['name']) ?></strong><br><span class="muted small"><?= h($r['class']) ?></span></td>
                  <td>
                    <?php if ($r['status'] === null): ?>
                      <?= badge('Pas rendu', 'error') ?>
                      <?php if ($r['id'] !== '' && isset($cfg['reminders'][$r['id']])): ?><br><span class="muted small">relancé le <?= h(date('d/m à H:i', (int) $cfg['reminders'][$r['id']])) ?></span><?php endif; ?>
                    <?php else: ?>
                      <?= badge('Rendu', 'success') ?><?= $r['status']['late'] ? ' ' . badge('En retard', 'warning') : '' ?>
                      <br><span class="muted small"><?= h(date('d/m à H:i', $r['status']['last'])) ?><?= $r['status']['n'] > 1 ? ' · ' . $r['status']['n'] . ' fichiers' : '' ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="grade-col"><input class="input input--sm" type="text" name="g[<?= h($key) ?>]" maxlength="20" value="<?= h($f['grade']) ?>" placeholder="ex. 15/20" aria-label="Note de <?= h($r['name']) ?>"></td>
                  <td><input class="input input--sm input--wide" type="text" name="c[<?= h($key) ?>]" maxlength="1000" value="<?= h($f['comment']) ?>" placeholder="Commentaire" aria-label="Commentaire pour <?= h($r['name']) ?>"></td>
                  <?php if ($missing): ?><td class="center"><?php if ($r['status'] === null && $r['id'] !== ''): ?><input type="checkbox" name="remind[]" value="<?= h($r['id']) ?>" checked aria-label="Relancer <?= h($r['name']) ?>"><?php endif; ?></td><?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <label class="toggle"><input type="checkbox" name="public" value="1"<?= !empty($cfg['feedback_public']) ? ' checked' : '' ?>><span>Les élèves voient leur note et leur commentaire <span class="muted small">(page « Mes travaux » ou avec leur numéro de reçu)</span></span></label>
        <div class="form__actions">
          <button class="btn" type="submit" name="do" value="save">Enregistrer les notes</button>
          <?php if ($missing): ?>
            <button class="btn btn--secondary" type="submit" name="do" value="remind"><?= icon('megaphone') ?>Relancer les élèves cochés</button>
            <label class="toggle toggle--sm"><input type="checkbox" name="announce" value="1"><span>et publier un rappel pour la classe (sans les noms)</span></label>
          <?php endif; ?>
        </div>
        <?php if ($missing): ?><p class="muted small">La relance s’affiche sur la page de chaque élève relancé quand il est connecté par l’ENT, jusqu’à ce qu’il rende son travail.</p><?php endif; ?>
      </form>
    </section>
    <?php
}
