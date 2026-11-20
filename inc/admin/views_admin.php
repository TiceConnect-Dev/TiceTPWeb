<?php
/**
 * Vues : classes, comptes enseignants, sauvegardes, paramètres, mon compte.
 */

function view_levels(array $data)
{
    $structure = class_structure($data);
    ?>
    <div class="page-head">
      <div>
        <h1>Niveaux et classes</h1>
        <p class="muted">Les élèves choisissent d’abord leur niveau, puis leur classe. Un niveau s’écrit en début de ligne ; ses classes s’écrivent en dessous, en retrait (espaces, tabulation ou tiret).</p>
      </div>
    </div>
    <div class="split split--levels">
      <section class="card">
        <form method="post" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="levels">
          <div class="field">
            <label for="level_names">Organisation <span class="hint">Une ligne non indentée sans classe en dessous reste une classe seule (ex. : BTS SIO 1).</span></label>
            <textarea id="level_names" class="input input--structure" name="level_names" rows="22" spellcheck="false" data-tab-indent><?= h(structure_to_text($data)) ?></textarea>
          </div>
          <?php alert('info', 'Les publications, dépôts et codes sont associés au nom exact de chaque classe. Renommer une classe revient à en créer une nouvelle : pensez à revoir ses publications et son code.'); ?>
          <div class="form__actions"><button class="btn" type="submit">Enregistrer</button></div>
        </form>
      </section>
      <aside class="card">
        <h2 class="h4">Exemple</h2>
        <pre class="example">6ème
    6ème 1
    6ème 2
    6ème 3
Seconde
    2GT1
    2GT2
Terminale
    TGA
    TSTI2D
BTS SIO 1</pre>
        <h2 class="h4">Organisation actuelle</h2>
        <ul class="structure-preview">
          <?php foreach ($structure as $item): ?>
            <?php if ($item['type'] === 'level'): ?>
              <li><strong><?= icon('school') ?><?= h($item['name']) ?></strong> <span class="muted small"><?= count($item['classes']) ?> classe<?= count($item['classes']) > 1 ? 's' : '' ?></span>
                <ul><?php foreach ($item['classes'] as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>
              </li>
            <?php else: ?>
              <li><?= icon('users') ?><?= h($item['name']) ?></li>
            <?php endif; ?>
          <?php endforeach; ?>
        </ul>
      </aside>
    </div>
    <?php
}

/**
 * Sélecteur générique à pastilles (dossiers, droits…), avec recherche quand la liste est longue.
 * $options : valeur => libellé. Les éléments cochés sont affichés en premier.
 */
function value_picker($name, array $options, array $chosen, array $nouns, $filterFrom = 12)
{
    if (!$options) {
        echo '<p class="muted small">' . h($nouns['none']) . '</p>';
        return;
    }
    uksort($options, function ($a, $b) use ($chosen, $options) {
        $ca = in_array((string) $a, $chosen, true) ? 0 : 1;
        $cb = in_array((string) $b, $chosen, true) ? 0 : 1;
        return $ca !== $cb ? $ca - $cb : strnatcasecmp($options[$a], $options[$b]);
    });
    $n = count(array_intersect(array_map('strval', array_keys($options)), $chosen));
    echo '<div class="picker" data-picker data-sum-zero="' . h($nouns['zero']) . '" data-sum-one="' . h($nouns['one']) . '" data-sum-many="' . h($nouns['many']) . '">';
    echo '<div class="picker__bar"><span class="picker__summary" data-picker-summary>' . h($n === 0 ? $nouns['zero'] : str_replace('{n}', $n, $n === 1 ? $nouns['one'] : $nouns['many'])) . '</span>';
    if (count($options) > $filterFrom) {
        echo '<input type="search" class="input picker__filter" placeholder="' . h($nouns['search']) . '" data-picker-filter aria-label="' . h($nouns['search']) . '">';
    }
    echo '</div><div class="pills pills--scroll">';
    foreach ($options as $value => $label) {
        $value = (string) $value;
        echo '<label class="pill" data-pill="' . h(fold_text($label)) . '"><input type="checkbox" name="' . h($name) . '" value="' . h($value) . '"'
            . (in_array($value, $chosen, true) ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    }
    echo '</div></div>';
}

/** Formulaire d'un compte (création ou modification). */
function user_form(array $u = null, array $data = null)
{
    if ($data === null) $data = settings();
    $isNew = $u === null;
    $u = $u ?: ['login' => '', 'name' => '', 'role' => 'prof', 'folders' => [], 'subject' => '', 'classes' => [], 'perms' => normalize_perms(null), 'ent_id' => ''];
    $p = $isNew ? 'new' : 'edit';
    $folders = [];
    foreach (folder_list() as $f) {
        if (substr_count($f, '/') < 3) $folders[$f] = str_replace('/', ' › ', $f);
    }
    $perms = [];
    foreach (perm_definitions() as $key => $def) $perms[$key] = $def[0];
    $chosenPerms = array_keys(array_filter($u['perms']));
    ?>
    <form method="post" class="form form--tight user-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="user_save">
      <input type="hidden" name="mode" value="<?= $isNew ? 'new' : 'edit' ?>">
      <?php if (!$isNew): ?><input type="hidden" name="login" value="<?= h($u['login']) ?>"><?php endif; ?>

      <section class="card">
        <h2 class="h4">Identité</h2>
        <div class="field-row">
          <?php if ($isNew): ?>
            <div class="field">
              <label for="<?= $p ?>-login">Identifiant <span class="hint">ex. : m.dupont</span></label>
              <input id="<?= $p ?>-login" class="input" type="text" name="login" required maxlength="32" pattern="[a-z0-9._\-]{2,32}" autocapitalize="none" spellcheck="false">
            </div>
          <?php endif; ?>
          <div class="field">
            <label for="<?= $p ?>-name">Nom affiché <span class="hint">ex. : M. Dupont</span></label>
            <input id="<?= $p ?>-name" class="input" type="text" name="name" required maxlength="60" value="<?= h($u['name']) ?>">
          </div>
          <div class="field">
            <label for="<?= $p ?>-role">Rôle</label>
            <select id="<?= $p ?>-role" class="input" name="role" data-role-select>
              <option value="prof"<?= $u['role'] === 'prof' ? ' selected' : '' ?>>Enseignant</option>
              <option value="admin"<?= $u['role'] === 'admin' ? ' selected' : '' ?>>Administrateur</option>
            </select>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="<?= $p ?>-pass">Mot de passe <span class="hint"><?= $isNew ? TPW_PASSWORD_MIN . ' caractères minimum — vide = connexion par l’ENT uniquement' : 'vide = inchangé' ?></span></label>
            <input id="<?= $p ?>-pass" class="input" type="text" name="password" minlength="<?= TPW_PASSWORD_MIN ?>" autocomplete="new-password" spellcheck="false">
          </div>
          <?php if (ent_config()['url'] !== ''): ?>
            <div class="field">
              <label for="<?= $p ?>-ent">Identifiant ENT <span class="hint">facultatif</span></label>
              <input id="<?= $p ?>-ent" class="input" type="text" name="ent_id" maxlength="120" spellcheck="false" value="<?= h($u['ent_id']) ?>">
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="card" data-prof-only>
        <h2 class="h4">Matière et classes</h2>
        <?php subject_fields($data, $u['subject'], $u['classes'], $p); ?>
      </section>

      <section class="card" data-prof-only>
        <h2 class="h4">Droits</h2>
        <?php value_picker('perms[]', $perms, $chosenPerms, [
            'zero' => 'Aucun droit : consultation seule', 'one' => '1 droit accordé', 'many' => '{n} droits accordés', 'none' => '', 'search' => 'Rechercher un droit…',
        ], 99); ?>
      </section>

      <section class="card" data-prof-only>
        <h2 class="h4">Autres dossiers gérés</h2>
        <p class="muted small">En plus de ses dossiers de matière, ex. un dossier commun à l’équipe de la discipline.</p>
        <?php value_picker('folders[]', $folders, $u['folders'], [
            'zero' => 'Aucun dossier supplémentaire', 'one' => '1 dossier attribué', 'many' => '{n} dossiers attribués', 'none' => 'Aucun dossier de cours pour l’instant.', 'search' => 'Rechercher un dossier…',
        ]); ?>
        <div class="field" style="margin-top:1rem">
          <label for="<?= $p ?>-newf">Ou créer un nouveau dossier à la racine</label>
          <input id="<?= $p ?>-newf" class="input input--short" type="text" name="new_folder" maxlength="180" placeholder="ex. : Équipe Physique-Chimie">
        </div>
      </section>

      <section class="card">
        <h2 class="h4">Stockage<?= $isNew ? '' : ' et sécurité' ?></h2>
        <div class="field-row">
          <div class="field">
            <label for="<?= $p ?>-quota">Quota de stockage (Mo) <span class="hint">0 = illimité · ses propres dossiers, hors banque et dossiers partagés</span></label>
            <input id="<?= $p ?>-quota" class="input input--short" type="number" name="quota_mb" min="0" max="1000000" step="1" value="<?= (int) (isset($u['quota_mb']) ? $u['quota_mb'] : 0) ?>">
            <?php if (!$isNew): $used = user_usage($u); $q = user_quota_bytes($u); ?>
              <span class="hint">Utilisé : <?= h(human_size($used)) ?><?= $q ? ' sur ' . h(human_size($q)) . ' (' . (int) round($used / $q * 100) . ' %)' : '' ?></span>
            <?php endif; ?>
          </div>
          <?php if (!$isNew): ?>
            <div class="field">
              <span class="label">Double authentification</span>
              <p><?= $u['totp'] !== '' ? badge('Activée', 'success') . ' <span class="muted small">' . count($u['totp_backup']) . ' code(s) de secours restant(s)</span>' : badge('Non activée', '') ?></p>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <div class="form__actions form__actions--sticky">
        <button class="btn" type="submit"><?= $isNew ? 'Créer le compte' : 'Enregistrer les modifications' ?></button>
        <a class="btn btn--tertiary" href="<?= h(admin_url('users')) ?>">Annuler</a>
      </div>
    </form>
    <?php
}

function view_users(array $data, array $me)
{
    $users = users_all();
    $edit = query('edit');

    // Page d'édition d'un seul compte.
    if ($edit !== '') {
        $isNew = $edit === 'new';
        if (!$isNew && !isset($users[$edit])) $isNew = true;
        breadcrumb([['Comptes enseignants', admin_url('users')], [$isNew ? 'Nouveau compte' : $users[$edit]['name'], null]]);
        ?>
        <div class="page-head page-head--compact">
          <div><h1><?= $isNew ? 'Nouveau compte' : h($users[$edit]['name']) ?></h1>
            <?php if (!$isNew): ?><p class="muted">Identifiant <code><?= h($edit) ?></code></p><?php endif; ?></div>
          <?php if (!$isNew && !empty($users[$edit]['pending'])): ?>
            <div class="page-head__actions">
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="user_approve"><input type="hidden" name="login" value="<?= h($edit) ?>"><input type="hidden" name="back" value="edit">
                <button class="btn btn--sm" type="submit"><?= icon('check') ?>Valider ce compte</button></form>
            </div>
          <?php endif; ?>
          <?php if (!$isNew && $users[$edit]['totp'] !== ''): ?>
            <div class="page-head__actions">
              <form method="post" class="inline-form" data-confirm="Réinitialiser la double authentification de <?= h($users[$edit]['name']) ?> (téléphone perdu) ? Il se connectera avec son seul mot de passe, puis devra la réactiver si elle est obligatoire.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="totp_reset">
                <input type="hidden" name="login" value="<?= h($edit) ?>">
                <button class="btn btn--tertiary btn--sm" type="submit"><?= icon('shield') ?>Réinitialiser la double authentification</button>
              </form>
            </div>
          <?php endif; ?>
          <?php if (!$isNew && $edit !== $me['login']): ?>
            <div class="page-head__actions">
              <form method="post" class="inline-form" data-confirm="Supprimer le compte de <?= h($users[$edit]['name']) ?> ? Ses dossiers et cours sont conservés.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="user_delete">
                <input type="hidden" name="login" value="<?= h($edit) ?>">
                <button class="btn btn--tertiary btn--sm btn--danger" type="submit"><?= icon('trash') ?>Supprimer ce compte</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
        <?php
        user_form($isNew ? null : ['login' => $edit] + $users[$edit], $data);
        return;
    }

    // Liste compacte.
    ?>
    <div class="page-head">
      <div>
        <h1>Comptes enseignants</h1>
        <p class="muted"><?= count($users) ?> compte<?= count($users) > 1 ? 's' : '' ?>. Chaque enseignant gère uniquement ses dossiers.</p>
      </div>
      <div class="page-head__actions">
        <a class="btn" href="<?= h(admin_url('users') . '&edit=new') ?>"><?= icon('user') ?>Nouveau compte</a>
      </div>
    </div>

    <?php $pending = pending_users(); if ($pending): ?>
      <section class="card card--pending">
        <h2 class="h4"><?= icon('clock') ?> <?= count($pending) ?> compte<?= count($pending) > 1 ? 's' : '' ?> créé<?= count($pending) > 1 ? 's' : '' ?> par l’ENT à valider</h2>
        <ul class="pending-list">
          <?php foreach ($pending as $pl => $pu): $pl = (string) $pl; ?>
            <li>
              <span><strong><?= h($pu['name']) ?></strong> <span class="muted small">identifiant ENT <code><?= h($pu['ent_id']) ?></code><?= !empty($pu['created']) ? ' · le ' . h(date('d/m/Y à H:i', (int) $pu['created'])) : '' ?></span></span>
              <span class="link-actions">
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="user_approve"><input type="hidden" name="login" value="<?= h($pl) ?>">
                  <button class="btn btn--sm" type="submit"><?= icon('check') ?>Valider</button></form>
                <a class="btn btn--tertiary btn--sm" href="<?= h(admin_url('users') . '&edit=' . rawurlencode($pl)) ?>">Vérifier</a>
                <?php $targets = array_filter(users_all(), function ($x) { return empty($x['pending']) && empty($x['ent_id']); }); if ($targets): ?>
                  <form method="post" class="inline-form merge-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="user_merge"><input type="hidden" name="login" value="<?= h($pl) ?>">
                    <label class="sr-only" for="merge-<?= h($pl) ?>">Rattacher à</label>
                    <select id="merge-<?= h($pl) ?>" class="input input--sm" name="target" required>
                      <option value="">Rattacher à un compte existant…</option>
                      <?php foreach ($targets as $tl => $tu): $same = preg_replace('/[^a-z]/', '', fold_text($tu['name'])) === preg_replace('/[^a-z]/', '', fold_text($pu['name'])); ?><option value="<?= h((string) $tl) ?>"<?= $same ? ' selected' : '' ?>><?= $same ? '✓ ' : '' ?><?= h($tu['name']) ?><?= $tu['subject'] !== '' ? ' — ' . h($tu['subject']) : '' ?> (<?= h((string) $tl) ?>)</option><?php endforeach; ?>
                    </select>
                    <button class="btn btn--secondary btn--sm" type="submit">Rattacher</button>
                  </form>
                <?php endif; ?>
                <form method="post" class="inline-form" data-confirm="Refuser et supprimer ce compte ?"><?= csrf_field() ?><input type="hidden" name="action" value="user_delete"><input type="hidden" name="login" value="<?= h($pl) ?>">
                  <button class="btn btn--tertiary btn--sm btn--danger" type="submit">Refuser</button></form>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?php users_import_card(); ?>

    <?php if (count($users) > 8): ?>
      <input type="search" class="input list-filter" placeholder="Rechercher un enseignant, une matière, une classe…" data-list-filter="#users-table" aria-label="Rechercher un enseignant">
    <?php endif; ?>
    <div class="table-wrap">
      <table class="table table--rows" id="users-table">
        <thead><tr><th>Enseignant</th><th>Matière</th><th>Classes</th><th class="num">Dossiers</th><th>Stockage</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($users as $login => $u): $login = (string) $login;
              $url = admin_url('users') . '&edit=' . rawurlencode($login); ?>
            <tr data-filter-text="<?= h(fold_text($u['name'] . ' ' . $login . ' ' . $u['subject'] . ' ' . implode(' ', $u['classes']))) ?>">
              <td>
                <a href="<?= h($url) ?>"><strong><?= h($u['name']) ?></strong></a>
                <?= $u['role'] === 'admin' ? badge('Admin', 'info') : '' ?><?= !empty($u['ent_id']) ? ' ' . badge('ENT', '') : '' ?><?= $u['totp'] !== '' ? ' ' . badge('2FA', 'success') : '' ?><?= !empty($u['pending']) ? ' ' . badge('À valider', 'warning') : '' ?>
                <br><span class="muted small"><?= h($login) ?><?= $login === $me['login'] ? ' · vous' : '' ?></span>
              </td>
              <td><?= $u['subject'] !== '' ? h($u['subject']) : '<span class="muted">—</span>' ?></td>
              <td><?= $u['classes'] ? class_chips($u['classes'], 4) : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= $u['role'] === 'admin' ? '<span class="muted">tous</span>' : count($u['folders']) ?></td>
              <td><?php if ($u['role'] === 'admin'): ?><span class="muted">—</span><?php else: $used = user_usage(['login' => $login] + $u); $q = user_quota_bytes($u); ?>
                <span class="usage"><span class="meter meter--sm<?= $q && $used > 0.9 * $q ? ' meter--low' : '' ?>"><span style="width:<?= $q ? min(100, (int) round($used / $q * 100)) : 0 ?>%"></span></span><span class="small"><?= h(human_size($used)) ?><?= $q ? ' / ' . h(human_size($q)) : '' ?></span></span>
              <?php endif; ?></td>
              <td class="actions"><a class="btn btn--tertiary btn--sm" href="<?= h($url) ?>"><?= icon('edit') ?>Modifier</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function view_backups()
{
    $list = backup_list();
    $zip = zip_available();
    ?>
    <div class="page-head">
      <div>
        <h1>Sauvegardes</h1>
        <p class="muted">Les sauvegardes sont enregistrées dans <code><?= h(TPW_BACKUPS) ?></code>. Téléchargez-les régulièrement sur une clé USB ou un partage réseau.</p>
      </div>
    </div>

    <?php if (!$zip) alert('error', 'L’extension PHP « zip » n’est pas activée. Dans XAMPP : php.ini → retirez le « ; » devant « extension=zip », puis redémarrez Apache.', 'Sauvegardes indisponibles'); ?>

    <?php autobackup_card(); ?>

    <div class="split">
      <section class="card">
        <h2 class="h4">Créer une sauvegarde</h2>
        <form method="post" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="backup_create">
          <div class="choices choices--stack">
            <label class="choice">
              <input type="radio" name="type" value="reglages" checked>
              <span class="choice__body"><strong>Réglages</strong><span>Publications, classes, codes, comptes, dépôts. Léger et rapide.</span></span>
            </label>
            <label class="choice">
              <input type="radio" name="type" value="complete">
              <span class="choice__body"><strong>Complète</strong><span>Réglages + tous les cours + tous les rendus des élèves. Peut être volumineuse.</span></span>
            </label>
          </div>
          <div class="form__actions"><button class="btn" type="submit"<?= $zip ? '' : ' disabled' ?>><?= icon('archive') ?>Créer la sauvegarde</button></div>
        </form>
      </section>

      <section class="card">
        <h2 class="h4">Restaurer depuis un fichier</h2>
        <form method="post" enctype="multipart/form-data" class="form" data-confirm="Restaurer cette sauvegarde ? Les fichiers qu’elle contient remplaceront ceux du serveur.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="backup_restore">
          <div class="field">
            <label for="archive">Archive TPWeb (.zip) <span class="hint"><?= h(human_size(upload_limit())) ?> maximum — pour une archive plus lourde, copiez-la dans le dossier des sauvegardes</span></label>
            <input id="archive" class="input" type="file" name="archive" accept=".zip" required>
          </div>
          <div class="form__actions"><button class="btn btn--secondary" type="submit"<?= $zip ? '' : ' disabled' ?>>Restaurer</button></div>
        </form>
      </section>
    </div>

    <section class="card card--flush">
      <div class="card__header"><h2 class="h4">Sauvegardes disponibles</h2><span class="muted small"><?= count($list) ?></span></div>
      <?php if (!$list): ?>
        <p class="card__empty">Aucune sauvegarde pour le moment.</p>
      <?php else: ?>
        <div class="table-wrap table-wrap--flush">
          <table class="table">
            <thead><tr><th>Date</th><th>Type</th><th class="num">Taille</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($list as $b): ?>
                <tr>
                  <td><?= h(date('d/m/Y à H:i', $b['time'])) ?><br><span class="muted small"><?= h($b['name']) ?></span></td>
                  <td><?= badge(backup_type_label($b['type']), $b['type'] === 'complete' ? 'info' : '') ?></td>
                  <td class="num"><?= h(human_size($b['size'])) ?></td>
                  <td class="actions">
                    <a class="icon-btn" href="admin.php?dl=backup&amp;f=<?= h(rawurlencode($b['name'])) ?>" title="Télécharger"><?= icon('download') ?><span class="sr-only">Télécharger</span></a>
                    <form method="post" class="inline-form" data-confirm="Restaurer la sauvegarde du <?= h(date('d/m/Y à H:i', $b['time'])) ?> ? Les fichiers qu’elle contient remplaceront ceux du serveur.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="backup_restore">
                      <input type="hidden" name="name" value="<?= h($b['name']) ?>">
                      <button class="btn btn--tertiary btn--sm" type="submit">Restaurer</button>
                    </form>
                    <form method="post" class="inline-form" data-confirm="Supprimer cette sauvegarde ?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="backup_delete">
                      <input type="hidden" name="name" value="<?= h($b['name']) ?>">
                      <button class="icon-btn icon-btn--danger" type="submit" title="Supprimer"><?= icon('trash') ?><span class="sr-only">Supprimer</span></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function view_settings(array $data)
{
    ?>
    <div class="page-head">
      <div>
        <h1>Paramètres</h1>
        <p class="muted">Identité de l’établissement et informations techniques.</p>
      </div>
    </div>

    <section class="card">
      <h2 class="h4">Établissement</h2>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="school">
        <div class="field">
          <label for="school">Nom affiché dans l’en-tête <span class="hint">Exemple : Lycée Camille Claudel — Fourmies</span></label>
          <input id="school" class="input" type="text" name="school" maxlength="120" value="<?= h($data['school']) ?>">
        </div>
        <div class="form__actions"><button class="btn btn--secondary" type="submit">Enregistrer</button></div>
      </form>
    </section>

    <?php $br = branding(); ?>
    <section class="card" id="apparence">
      <h2 class="h4"><?= icon('palette') ?> Apparence</h2>
      <form method="post" enctype="multipart/form-data" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="branding_save">
        <div class="field-row">
          <div class="field">
            <label for="br-color">Couleur principale <span class="hint">boutons, liens, en-tête — le thème sombre est adapté automatiquement</span></label>
            <div class="color-row"><input id="br-color" type="color" name="color" value="<?= h($br['color']) ?>">
              <button type="button" class="link-btn" data-color-reset="#000091">Couleur d’origine</button></div>
          </div>
          <div class="field">
            <label for="br-logo">Logo de l’établissement <span class="hint">PNG, JPG ou WebP, 1 Mo maximum, de préférence carré</span></label>
            <?php if ($br['logo'] !== ''): ?><p class="logo-preview"><img src="logo.php?v=<?= h(substr(md5($br['logo']), 0, 8)) ?>" alt="Logo actuel"> <label class="toggle toggle--sm"><input type="checkbox" name="logo_remove" value="1"><span>Retirer le logo</span></label></p><?php endif; ?>
            <input id="br-logo" class="input" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
          </div>
        </div>
        <div class="field">
          <label for="br-msg">Message d’accueil sur la page des élèves <span class="hint">facultatif — ex. : « Le CDI est ouvert de 8 h à 17 h. »</span></label>
          <textarea id="br-msg" class="input input--text" name="message" rows="2" maxlength="500"><?= h($br['message']) ?></textarea>
        </div>
        <div class="form__actions"><button class="btn btn--secondary" type="submit">Enregistrer l’apparence</button></div>
      </form>
    </section>

    <?php $mc = mail_config(); $mq = load_json(TPW_STATE . '/mail_queue.json', []); ?>
    <section class="card" id="courriels">
      <div class="card__title-row">
        <h2 class="h4"><?= icon('mail') ?> Courriels</h2>
        <?= mail_ready() ? badge('Activés', 'success') : badge('Désactivés', 'neutral') ?>
      </div>
      <p class="muted small">Notifications aux enseignants : nouveaux rendus, bilan de fermeture des dépôts, signalements. Chaque enseignant choisit ses notifications dans « Mon compte ».</p>
      <form method="post" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mail_settings">
        <label class="toggle"><input type="checkbox" name="enabled" value="1"<?= !empty($mc['enabled']) ? ' checked' : '' ?>><span>Activer l’envoi de courriels</span></label>
        <div class="field-row">
          <div class="field"><label for="m-from">Adresse d’expédition</label><input id="m-from" class="input" type="email" name="from" maxlength="120" value="<?= h($mc['from']) ?>" placeholder="tpweb@camilleclaudelfourmies.fr"></div>
          <div class="field"><label for="m-fromname">Nom de l’expéditeur</label><input id="m-fromname" class="input" type="text" name="from_name" maxlength="60" value="<?= h($mc['from_name']) ?>"></div>
        </div>
        <div class="field"><label for="m-transport">Mode d’envoi</label>
          <select id="m-transport" class="input input--short" name="transport">
            <option value="smtp"<?= $mc['transport'] === 'smtp' ? ' selected' : '' ?>>Serveur SMTP (recommandé)</option>
            <option value="php"<?= $mc['transport'] === 'php' ? ' selected' : '' ?>>Fonction mail() de PHP (sendmail du serveur)</option>
          </select></div>
        <div class="field-row">
          <div class="field"><label for="m-host">Serveur SMTP</label><input id="m-host" class="input" type="text" name="host" maxlength="120" value="<?= h($mc['host']) ?>" placeholder="smtp.example.fr"></div>
          <div class="field"><label for="m-port">Port</label><input id="m-port" class="input input--short" type="number" name="port" min="1" max="65535" value="<?= (int) $mc['port'] ?>"></div>
          <div class="field"><label for="m-secure">Chiffrement</label>
            <select id="m-secure" class="input" name="secure">
              <?php foreach (['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'Aucun (port 25, réseau interne)'] as $k => $l): ?>
                <option value="<?= $k ?>"<?= $mc['secure'] === $k ? ' selected' : '' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="field-row">
          <div class="field"><label for="m-user">Identifiant SMTP <span class="hint">vide si aucune authentification</span></label><input id="m-user" class="input" type="text" name="user" maxlength="120" value="<?= h($mc['user']) ?>" autocomplete="off"></div>
          <div class="field"><label for="m-pass">Mot de passe SMTP <span class="hint"><?= secret_get('smtp_pass') !== '' ? 'enregistré — laisser vide pour le garder' : 'non enregistré' ?></span></label><input id="m-pass" class="input" type="password" name="pass" maxlength="200" autocomplete="new-password"></div>
        </div>
        <div class="field"><label for="m-base">Adresse publique du portail <span class="hint">pour les liens des courriels — vide = détectée automatiquement (<?= h(mail_base_url() ?: '?') ?>)</span></label>
          <input id="m-base" class="input" type="url" name="base" maxlength="200" value="<?= h($mc['base']) ?>" placeholder="https://tpweb.camilleclaudelfourmies.fr"></div>
        <div class="form__actions">
          <button class="btn btn--secondary" type="submit">Enregistrer</button>
          <button class="btn btn--tertiary" type="submit" name="test" value="1"><?= icon('mail') ?>Enregistrer et m’envoyer un courriel de test</button>
        </div>
      </form>
      <?php if ($mq): ?>
        <p class="muted small"><?= count($mq) ?> courriel(s) en attente<?php $lastErr = end($mq); if (!empty($lastErr['error'])) echo ' · dernière erreur : ' . h($lastErr['error']); ?></p>
      <?php endif; ?>
    </section>

    <?php legal_settings_card($data); ?>

    <?php $ent = ent_config($data); $service = app_base_url($ent) . '/cas.php'; ?>
    <section class="card" id="ent">
      <div class="card__title-row">
        <h2 class="h4"><?= icon('school') ?> Connexion par l’ENT (CAS)</h2>
        <?= ent_enabled($data) ? badge('Activée', 'success') : badge('Désactivée', '') ?>
      </div>
      <p class="muted">Les enseignants se connectent avec leurs identifiants ENT (ex. : ENT Hauts-de-France, basé sur Open ENT NG). La connexion par identifiant TPWeb reste toujours possible.</p>
      <details class="help">
        <summary>Mise en place avec l’administrateur ENT</summary>
        <ol>
          <li>Dans la console d’administration de l’ENT (Connecteurs › Nouveau connecteur), créez le connecteur avec les valeurs ci-dessous, puis <strong>attribuez-le au profil Enseignants</strong>.</li>
          <li>Renseignez l’adresse du serveur CAS de l’ENT, enregistrez puis cliquez sur « Tester ».</li>
          <li>Activez la connexion. Chaque enseignant lie ensuite son compte depuis « Mon compte » (ou l’administrateur saisit son identifiant ENT).</li>
        </ol>
        <p>Le serveur TPWeb doit pouvoir accéder à Internet en HTTPS : c’est lui qui vérifie auprès de l’ENT chaque connexion.</p>
      </details>
      <dl class="meta-list">
        <?php
        $connectorUrl = cas_service_url($ent);
        $pattern = '^' . preg_quote(app_base_url($ent), '') . '/cas\\.php.*$';
        $copy = function ($v) { return '<code>' . h($v) . '</code> <button type="button" class="btn btn--tertiary btn--sm" data-copy="' . h($v) . '">' . icon('copy') . 'Copier</button>'; };
        ?>
        <div><dt>Identifiant</dt><dd><?= $copy('tpweb') ?></dd></div>
        <div><dt>Nom d’affichage</dt><dd><?= $copy('TPWeb') ?></dd></div>
        <div><dt>URL</dt><dd><?= $copy($connectorUrl) ?></dd></div>
        <div><dt>Cible</dt><dd>Nouvelle page</dd></div>
        <div><dt>Champs spécifiques CAS</dt><dd>Cocher « Activer », Type : <strong>Defaut</strong></dd></div>
        <div><dt>Pattern (si demandé)</dt><dd><?= $copy($pattern) ?></dd></div>
        <div><dt>Champs OAuth</dt><dd>Ne rien cocher</dd></div>
      </dl>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ent_settings">
        <div class="field-row">
          <div class="field">
            <label for="ent-url">Adresse du serveur CAS <span class="hint">ex. : https://enthdf.fr/cas — à confirmer avec l’administrateur ENT</span></label>
            <input id="ent-url" class="input" type="url" name="url" maxlength="300" value="<?= h($ent['url']) ?>" placeholder="https://">
          </div>
          <div class="field">
            <label for="ent-label">Nom affiché sur le bouton</label>
            <input id="ent-label" class="input" type="text" name="label" maxlength="60" value="<?= h($ent['label']) ?>" placeholder="l’ENT Hauts-de-France">
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="ent-version">Version du protocole</label>
            <select id="ent-version" class="input" name="version">
              <option value="2"<?= $ent['version'] === '2' ? ' selected' : '' ?>>CAS 2.0 (serviceValidate)</option>
              <option value="3"<?= $ent['version'] === '3' ? ' selected' : '' ?>>CAS 3.0 (p3/serviceValidate, avec attributs)</option>
            </select>
          </div>
          <div class="field">
            <label for="ent-base">Adresse publique de TPWeb <span class="hint">facultatif — si l’adresse ci-dessus n’est pas celle utilisée par les postes</span></label>
            <input id="ent-base" class="input" type="url" name="service_base" maxlength="300" value="<?= h($ent['service_base']) ?>" placeholder="http://192.168.1.10/TPWeb">
          </div>
        </div>
        <div class="field">
          <label for="ent-service">URL du connecteur dans l’ENT <span class="hint">facultatif — à remplir seulement si l’URL saisie dans le connecteur ENT diffère de celle proposée ci-dessus (elle doit être identique au caractère près)</span></label>
          <input id="ent-service" class="input" type="url" name="service_url" maxlength="300" value="<?= h($ent['service_url']) ?>" placeholder="<?= h(app_base_url($ent) . '/cas.php') ?>">
        </div>
        <div class="field">
          <label for="ent-auto">Enseignant inconnu de TPWeb qui se connecte par l’ENT</label>
          <select id="ent-auto" class="input" name="autocreate">
            <option value="off"<?= $ent['autocreate'] === 'off' ? ' selected' : '' ?>>Refuser : le compte doit être créé ou lié à l’avance</option>
            <option value="pending"<?= $ent['autocreate'] === 'pending' ? ' selected' : '' ?>>Créer son compte, à valider par un administrateur (recommandé)</option>
            <option value="active"<?= $ent['autocreate'] === 'active' ? ' selected' : '' ?>>Créer son compte, utilisable immédiatement</option>
          </select>
          <span class="hint">Le compte reçoit les droits par défaut et le nom transmis par l’ENT ; l’enseignant choisit ensuite sa matière et ses classes. Un élève présent dans la liste des élèves n’est jamais pris pour un enseignant.</span>
        </div>
        <label class="toggle"><input type="checkbox" name="students" value="1" <?= !empty($ent['students']) ? 'checked' : '' ?>><span>Connexion des élèves par l’ENT <span class="muted small">— bouton sur l’accueil des élèves ; l’élève doit figurer dans Administration › Élèves avec son identifiant ENT ; attribuez aussi le connecteur au profil Élèves dans l’ENT</span></span></label>
        <div class="sub-toggles">
          <label class="toggle toggle--sm"><input type="checkbox" name="students_selfreg" value="1" <?= !empty($ent['students_selfreg']) ? 'checked' : '' ?>><span>Un élève absent de la liste peut demander l’accès en choisissant sa classe (validation par un enseignant, menu Élèves)</span></label>
          <label class="toggle toggle--sm"><input type="checkbox" name="students_autocreate" value="1" <?= !empty($ent['students_autocreate']) ? 'checked' : '' ?>><span>Créer l’élève automatiquement si l’ENT transmet son nom, son prénom et sa classe <span class="muted">(attributs CAS — l’ENT HDF ne les transmet pas actuellement : vérifiez avec « Tester »)</span></span></label>
        </div>
        <label class="toggle"><input type="checkbox" name="enabled" value="1" <?= !empty($ent['enabled']) ? 'checked' : '' ?>><span>Activer le bouton « Se connecter avec l’ENT »</span></label>
        <div class="form__actions">
          <button class="btn btn--secondary" type="submit">Enregistrer</button>
          <?php if ($ent['url'] !== ''): ?><a class="btn btn--tertiary" href="cas.php?mode=test">Tester la connexion</a><?php endif; ?>
        </div>
      </form>
    </section>

    <?php $sec = $data['security']; ?>
    <section class="card" id="securite">
      <h2 class="h4"><?= icon('shield') ?> Sécurité des comptes</h2>
      <p class="muted">La double authentification demande, en plus du mot de passe, un code à 6 chiffres affiché par une application sur le téléphone (Google Authenticator, Microsoft Authenticator, FreeOTP, Aegis…). Chaque enseignant l’active dans « Mon compte ».</p>
      <form method="post" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="security_save">
        <label class="toggle"><input type="checkbox" name="force_2fa_admin" value="1"<?= !empty($sec['force_2fa_admin']) ? ' checked' : '' ?>><span>Obligatoire pour les administrateurs <span class="muted small">(recommandé)</span></span></label>
        <label class="toggle"><input type="checkbox" name="force_2fa_all" value="1"<?= !empty($sec['force_2fa_all']) ? ' checked' : '' ?>><span>Obligatoire pour tous les enseignants</span></label>
        <p class="muted small">Quand elle est obligatoire, un compte sans double authentification doit l’activer dès sa prochaine connexion avant d’accéder au reste. En cas de téléphone perdu : Comptes enseignants › le compte › « Réinitialiser la double authentification ».</p>
        <div class="form__actions"><button class="btn btn--secondary" type="submit">Enregistrer</button></div>
      </form>
    </section>

    <section class="card">
      <h2 class="h4">Informations techniques</h2>
      <dl class="meta-list">
        <div><dt>Dossier des cours</dt><dd><code><?= h(TPW_COURSES) ?></code></dd></div>
        <div><dt>Rendus des élèves</dt><dd><code><?= h(TPW_RENDUS) ?></code></dd></div>
        <div><dt>Configuration</dt><dd><code><?= h(TPW_STATE) ?></code></dd></div>
        <div><dt>Taille maximale d’envoi</dt><dd><?= h(human_size(upload_limit())) ?> <span class="muted small">(php.ini : upload_max_filesize et post_max_size)</span></dd></div>
        <div><dt>Archives ZIP</dt><dd><?= zip_available() ? badge('Disponible', 'success') : badge('Extension zip désactivée', 'error') ?></dd></div>
        <div><dt>Aperçu Word / PowerPoint</dt><dd><?= soffice_path() !== '' ? badge('LibreOffice', 'success') . ' <code>' . h(soffice_path()) . '</code>' : badge('Aperçu texte simplifié', 'warning') . ' <span class="muted small">installez LibreOffice sur le serveur pour un aperçu fidèle (voir README)</span>' ?></dd></div>
        <div><dt>Requêtes HTTPS sortantes</dt><dd><?= function_exists('curl_init') ? 'curl' : (ini_get('allow_url_fopen') ? 'allow_url_fopen' : '<span class="muted">indisponibles</span>') ?><?= ca_bundle() !== '' ? ' · certificats : <code>' . h(ca_bundle()) . '</code>' : ' · <span class="muted">certificats système</span>' ?></dd></div>
        <div><dt>Fuseau horaire</dt><dd><?= h(date_default_timezone_get()) ?> — <?= h(date('d/m/Y H:i')) ?></dd></div>
        <div><dt>Versions</dt><dd>TPWeb <?= h(TPW_VERSION) ?> · PHP <?= h(PHP_VERSION) ?></dd></div>
      </dl>
    </section>
    <?php
}

function view_account(array $user)
{
    ?>
    <div class="page-head">
      <div>
        <h1>Mon compte</h1>
        <p class="muted">Identifiant : <code><?= h($user['login']) ?></code> · <?= h(role_label($user['role'])) ?></p>
      </div>
    </div>
    <section class="card">
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="account">
        <div class="field">
          <label for="acc-name">Nom affiché</label>
          <input id="acc-name" class="input input--short" type="text" name="name" required maxlength="60" value="<?= h($user['name']) ?>">
        </div>
        <fieldset class="fieldset">
          <legend>Changer de mot de passe <span class="hint">Laisser vide pour conserver le mot de passe actuel.</span></legend>
          <div class="field-row">
            <div class="field">
              <label for="old">Mot de passe actuel</label>
              <input id="old" class="input" type="password" name="old" autocomplete="current-password">
            </div>
            <div class="field">
              <label for="new">Nouveau <span class="hint"><?= TPW_PASSWORD_MIN ?> caractères minimum</span></label>
              <input id="new" class="input" type="password" name="new" minlength="<?= TPW_PASSWORD_MIN ?>" autocomplete="new-password">
            </div>
            <div class="field">
              <label for="new_confirm">Confirmation</label>
              <input id="new_confirm" class="input" type="password" name="new_confirm" minlength="<?= TPW_PASSWORD_MIN ?>" autocomplete="new-password">
            </div>
          </div>
        </fieldset>
        <div class="form__actions"><button class="btn" type="submit">Enregistrer</button></div>
      </form>
    </section>

    <?php $np = mail_prefs($user); ?>
    <section class="card" id="notifications">
      <h2 class="h4"><?= icon('mail') ?> Notifications par courriel</h2>
      <?php if (!mail_ready()): ?><p class="muted small">L’administrateur n’a pas encore configuré l’envoi de courriels (Paramètres › Courriels). Vous pouvez déjà renseigner votre adresse.</p><?php endif; ?>
      <form method="post" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="notify_save">
        <div class="field">
          <label for="acc-mail">Adresse électronique <span class="hint">académique de préférence</span></label>
          <input id="acc-mail" class="input input--short" type="email" name="email" maxlength="120" value="<?= h(user_email($user)) ?>" placeholder="prenom.nom@ac-lille.fr">
        </div>
        <fieldset class="fieldset"><legend>Nouveaux rendus dans mes dépôts</legend>
          <?php foreach (['instant' => 'Un courriel à chaque rendu', 'daily' => 'Un résumé par jour (vers 17 h)', 'off' => 'Jamais'] as $k => $l): ?>
            <label class="toggle toggle--sm"><input type="radio" name="submit" value="<?= $k ?>"<?= $np['submit'] === $k ? ' checked' : '' ?>><span><?= h($l) ?></span></label>
          <?php endforeach; ?>
        </fieldset>
        <label class="toggle toggle--sm"><input type="checkbox" name="closed" value="1"<?= $np['closed'] ? ' checked' : '' ?>><span>Bilan à la fermeture d’un dépôt <span class="hint">nombre de rendus, élèves sans rendu</span></span></label>
        <?php if ($user['role'] === 'admin'): ?>
          <label class="toggle toggle--sm"><input type="checkbox" name="feedback" value="1"<?= $np['feedback'] ? ' checked' : '' ?>><span>Nouveaux signalements (bugs, idées)</span></label>
        <?php endif; ?>
        <div class="form__actions"><button class="btn btn--secondary" type="submit">Enregistrer</button></div>
      </form>
    </section>

    <?php if ($user['role'] !== 'admin' && $user['subject'] === '' && !$user['classes'] && can('own_classes', $user)): ?>
      <?php alert('info', 'Indiquez votre matière et vos classes ci-dessous : TPWeb crée alors vos dossiers « Classe/Matière » et les publie pour vos élèves.', 'Bienvenue sur TPWeb'); ?>
    <?php endif; ?>
    <?php $data = settings(); if ($user['role'] !== 'admin' || $user['subject'] !== '' || $user['classes']): ?>
      <section class="card" id="mes-classes">
        <h2 class="h4">Ma matière et mes classes</h2>
        <?php if (can('own_classes', $user)): ?>
          <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="my_classes">
            <?php subject_fields($data, $user['subject'], $user['classes'], 'me'); ?>
            <div class="form__actions"><button class="btn" type="submit">Enregistrer et créer mes dossiers</button></div>
          </form>
        <?php else: ?>
          <p><?= $user['subject'] !== '' ? '<strong>' . h($user['subject']) . '</strong>' : 'Aucune matière' ?><?= $user['classes'] ? ' — ' . h(implode(', ', $user['classes'])) : '' ?></p>
          <p class="muted small">Vos classes sont gérées par l’administrateur du portail.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php account_security_card($user); ?>

    <?php if ($user['role'] !== 'admin'): $used = user_usage($user); $q = user_quota_bytes($user); ?>
      <section class="card">
        <h2 class="h4"><?= icon('disk') ?> Mon espace de stockage</h2>
        <p><span class="usage usage--lg"><span class="meter<?= $q && $used > 0.9 * $q ? ' meter--low' : '' ?>"><span style="width:<?= $q ? min(100, (int) round($used / $q * 100)) : 0 ?>%"></span></span>
          <span><strong><?= h(human_size($used)) ?></strong><?= $q ? ' utilisés sur ' . h(human_size($q)) : ' utilisés (pas de limite)' ?></span></span></p>
        <p class="muted small">Compte vos dossiers de matière et dossiers attribués (pas la banque de ressources ni les dossiers partagés par un collègue). Les éléments de la corbeille sont effacés au bout de <?= TPW_TRASH_DAYS ?> jours.</p>
      </section>
    <?php endif; ?>

    <?php if (ent_enabled()): $ent = ent_config(); ?>
      <section class="card">
        <h2 class="h4">Connexion avec <?= h($ent['label']) ?></h2>
        <?php if (!empty($user['ent_id'])): ?>
          <p><?= badge('Compte lié', 'success') ?> Identifiant ENT : <code><?= h($user['ent_id']) ?></code></p>
          <p class="muted">Vous pouvez vous connecter avec le bouton « Se connecter avec <?= h($ent['label']) ?> » sur la page de connexion.</p>
          <form method="post" class="inline-form" data-confirm="Délier votre compte de l’ENT ?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ent_unlink">
            <button class="btn btn--tertiary btn--sm btn--danger" type="submit">Délier mon compte ENT</button>
          </form>
        <?php else: ?>
          <p class="muted">Liez votre compte pour vous connecter ensuite en un clic avec vos identifiants ENT. Vous serez redirigé vers l’ENT puis ramené ici.</p>
          <a class="btn btn--ent" href="cas.php?mode=link"><?= icon('school') ?>Lier mon compte ENT</a>
        <?php endif; ?>
      </section>
    <?php endif; ?>
    <?php
}

function view_trash(array $user)
{
    trash_purge_old();
    $users = users_all();
    $list = array_values(array_filter(trash_index(), function ($e) use ($user) { return trash_visible($e, $user); }));
    usort($list, function ($a, $b) { return $b['time'] - $a['time']; });
    ?>
    <div class="page-head">
      <div>
        <h1>Corbeille</h1>
        <p class="muted">Les éléments supprimés sont conservés <?= TPW_TRASH_DAYS ?> jours avec leurs réglages de publication, puis effacés automatiquement.</p>
      </div>
      <?php if (is_admin($user) && $list): ?>
        <div class="page-head__actions">
          <form method="post" class="inline-form" data-confirm="Vider définitivement toute la corbeille ?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="trash_empty">
            <button class="btn btn--tertiary btn--danger" type="submit"><?= icon('trash') ?>Vider la corbeille</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$list): ?>
      <div class="empty"><?= icon('trash') ?><p><strong>La corbeille est vide.</strong></p></div>
      <?php return; ?>
    <?php endif; ?>

    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Élément</th><th>Emplacement d’origine</th><th>Supprimé</th><th class="num">Taille</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($list as $e):
              $abs = TPW_TRASH . DIRECTORY_SEPARATOR . $e['id'] . DIRECTORY_SEPARATOR . $e['name'];
              $left = max(0, (int) ceil(($e['time'] + TPW_TRASH_DAYS * 86400 - time()) / 86400)); ?>
            <tr>
              <td><span class="cell-icon"><?= icon($e['type'] === 'dir' ? 'folder' : file_kind($e['name'])['icon']) ?><strong><?= h($e['name']) ?></strong></span></td>
              <td class="break"><code><?= h(parent_path($e['path']) === '' ? '/' : parent_path($e['path'])) ?></code></td>
              <td><?= h(date('d/m/Y H:i', $e['time'])) ?><br><span class="muted small">par <?= h(isset($users[$e['by']]) ? $users[$e['by']]['name'] : $e['by']) ?> · encore <?= $left ?> j</span></td>
              <td class="num"><?= file_exists($abs) ? h(human_size(tree_size($abs))) : '—' ?></td>
              <td class="actions">
                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="trash_restore">
                  <input type="hidden" name="id" value="<?= h($e['id']) ?>">
                  <button class="btn btn--secondary btn--sm" type="submit"><?= icon('restore') ?>Restaurer</button>
                </form>
                <form method="post" class="inline-form" data-confirm="Supprimer définitivement « <?= h($e['name']) ?> » ?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="trash_delete">
                  <input type="hidden" name="id" value="<?= h($e['id']) ?>">
                  <button class="icon-btn icon-btn--danger" type="submit" title="Supprimer définitivement"><?= icon('trash') ?><span class="sr-only">Supprimer définitivement</span></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function view_year(array $data)
{
    $deposits = deposits_config();
    $rendus = 0;
    foreach ($deposits as $c) $rendus += count(deposit_entries(deposit_normalize($c)));
    $published = count(array_filter($data['rules'], function ($r) { return isset($r['mode']) && $r['mode'] === 'publish'; }));
    $codes = count(class_access_data());
    $teachersWithClasses = count(array_filter(users_all(), function ($u) { return !empty($u['classes']); }));
    $options = [
        'backup'         => ['Créer d’abord une sauvegarde complète', 'Fortement recommandé : cours, rendus et réglages.', true],
        'archive_rendus' => ['Archiver les rendus et fermer les dépôts', $rendus . ' rendu(s) dans ' . count($deposits) . ' dépôt(s), déplacés dans TPWebData/archives.', true],
        'unpublish'      => ['Retirer toutes les publications', $published . ' publication(s). Les cours restent sur le serveur, mais plus rien n’est visible des élèves.', true],
        'clear_news'     => ['Effacer les annonces et les examens', count($data['announcements']) . ' annonce(s), ' . count($data['exams']) . ' examen(s).', true],
        'reset_classes'  => ['Remettre à zéro les classes des enseignants', $teachersWithClasses . ' enseignant(s) concerné(s). Chacun choisira ses nouvelles classes à la rentrée.', true],
        'clear_students' => ['Vider la liste des élèves', count(students_all()) . ' élève(s) enregistré(s). Importez la nouvelle liste à la rentrée.', true],
        'reset_codes'    => ['Supprimer les codes d’accès des classes', $codes . ' code(s) actif(s).', false],
        'empty_trash'    => ['Vider la corbeille', count(trash_index()) . ' élément(s).', false],
    ];
    ?>
    <div class="page-head">
      <div>
        <h1>Fin d’année scolaire</h1>
        <p class="muted">Préparez le portail pour la rentrée en une seule opération. Les cours ne sont jamais supprimés : seuls leur publication, les dépôts et les réglages annuels sont remis à zéro.</p>
      </div>
    </div>
    <section class="card">
      <form method="post" class="form" data-confirm="Lancer la fin d’année avec les options choisies ?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="year_end">
        <div class="choices choices--stack">
          <?php foreach ($options as $key => $o): ?>
            <label class="choice">
              <input type="checkbox" name="<?= h($key) ?>" value="1"<?= $o[2] ? ' checked' : '' ?>>
              <span class="choice__body"><strong><?= h($o[0]) ?></strong><span><?= h($o[1]) ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php alert('warning', 'Ces opérations s’appliquent à tous les enseignants. Faites-les après le dernier conseil de classe, quand plus aucun rendu n’est attendu.'); ?>
        <div class="field">
          <label for="year-confirm">Confirmation <span class="hint">Tapez FIN en majuscules</span></label>
          <input id="year-confirm" class="input input--short" type="text" name="confirm" required autocomplete="off" pattern="FIN">
        </div>
        <div class="form__actions"><button class="btn btn--danger-solid" type="submit"><?= icon('calendar') ?>Lancer la fin d’année</button></div>
      </form>
    </section>
    <?php
}

/** Formulaire « matière + classes » (compte enseignant). */
function subject_fields(array $data, $subject, array $classes, $prefix)
{
    ?>
    <div class="field">
      <label for="<?= h($prefix) ?>-subject">Matière enseignée <span class="hint">ex. : Physique-Chimie, SNT, Mathématiques — utilisée pour nommer les dossiers</span></label>
      <input id="<?= h($prefix) ?>-subject" class="input input--short" type="text" name="subject" maxlength="60" value="<?= h($subject) ?>">
    </div>
    <fieldset class="fieldset">
      <legend>Classes <span class="hint">Un dossier « Classe/Matière » est créé et publié automatiquement pour chaque classe cochée.</span></legend>
      <?php level_checkboxes($data, $classes, 'levels[]', ['mine' => false, 'groups' => false]); ?>
    </fieldset>
    <?php
}

function view_journal(array $data)
{
    $months = audit_months();
    $month = query('m', $months ? $months[0] : date('Y-m'));
    if (!in_array($month, $months, true)) $month = $months ? $months[0] : date('Y-m');
    $who = query('u');
    $q = trim(query('q'));
    $labels = audit_labels();
    $users = users_all();
    $rows = array_filter(audit_read($month), function ($r) use ($who, $q, $labels) {
        if ($who !== '' && $r['u'] !== $who) return false;
        if ($q === '') return true;
        $hay = fold_text(($labels[$r['a']] ?? $r['a']) . ' ' . audit_details_text((array) $r['d']) . ' ' . $r['u'] . ' ' . $r['ip']);
        return strpos($hay, fold_text($q)) !== false;
    });
    $accounts = array_unique(array_map(function ($r) { return $r['u']; }, audit_read($month)));
    sort($accounts);
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $monthLabel = function ($m) use ($mois) { return ucfirst($mois[(int) substr($m, 5, 2) - 1]) . ' ' . substr($m, 0, 4); };
    ?>
    <div class="page-head">
      <div>
        <h1>Journal d’activité</h1>
        <p class="muted">Connexions, publications, suppressions, dépôts, examens et modifications de comptes. Conservé <?= AUDIT_MONTHS ?> mois puis effacé automatiquement. Aucun mot de passe ni code n’y figure.</p>
      </div>
      <?php if ($months): ?>
        <div class="page-head__actions">
          <a class="btn btn--secondary btn--sm" href="admin.php?dl=journal&amp;m=<?= h($month) ?>"><?= icon('download') ?>Exporter le mois (.csv)</a>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$months): ?>
      <div class="empty"><?= icon('clock') ?><p><strong>Le journal est vide pour le moment.</strong></p></div>
      <?php return; ?>
    <?php endif; ?>

    <form method="get" class="filters">
      <input type="hidden" name="view" value="journal">
      <div class="field">
        <label for="j-m">Mois</label>
        <select id="j-m" class="input" name="m">
          <?php foreach ($months as $m): ?><option value="<?= h($m) ?>"<?= $m === $month ? ' selected' : '' ?>><?= h($monthLabel($m)) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="j-u">Compte</label>
        <select id="j-u" class="input" name="u">
          <option value="">Tous</option>
          <?php foreach ($accounts as $a): ?><option value="<?= h($a) ?>"<?= $a === $who ? ' selected' : '' ?>><?= h(isset($users[$a]) ? $users[$a]['name'] . ' (' . $a . ')' : $a) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="j-q">Rechercher</label>
        <input id="j-q" class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="dossier, action, IP…">
      </div>
      <button class="btn btn--secondary" type="submit">Filtrer</button>
    </form>

    <p class="muted small"><?= count($rows) ?> entrée<?= count($rows) > 1 ? 's' : '' ?></p>
    <div class="table-wrap">
      <table class="table table--compact">
        <thead><tr><th>Date</th><th>Compte</th><th>Action</th><th>Détails</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($rows, 0, 1000) as $r):
              $danger = in_array($r['a'], ['login_failed', 'delete', 'trash_delete', 'trash_empty', 'deposit_purge', 'user_delete', 'year_end', 'backup_restore', 'ent_unknown'], true); ?>
            <tr>
              <td class="nowrap"><?= h(date('d/m H:i:s', $r['t'])) ?></td>
              <td><?= h(isset($users[$r['u']]) ? $users[$r['u']]['name'] : $r['u']) ?></td>
              <td><?= $danger ? badge($labels[$r['a']] ?? $r['a'], 'warning') : h($labels[$r['a']] ?? $r['a']) ?></td>
              <td class="break small"><?= h(audit_details_text((array) $r['d'])) ?></td>
              <td class="nowrap muted small"><?= h($r['ip']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/** Réglages de la page « Mentions légales et données personnelles ». */
function legal_settings_card(array $data)
{
    $l = (isset($data['legal']) && is_array($data['legal']) ? $data['legal'] : []) + legal_defaults();
    $fields = [
        'publisher'  => ['Directeur de la publication', 'ex. : Mme X, proviseure du lycée Camille Claudel'],
        'controller' => ['Responsable du traitement', 'ex. : Le chef d’établissement du lycée Camille Claudel'],
        'address'    => ['Adresse de l’établissement', 'ex. : 9 rue de …, 59610 Fourmies'],
        'contact'    => ['Contact pour exercer ses droits', 'ex. : ce.059xxxxx@ac-lille.fr'],
        'dpo'        => ['Délégué à la protection des données (DPO)', 'ex. : DPO de l’académie de Lille — dpd@ac-lille.fr'],
        'host'       => ['Hébergeur du serveur', 'ex. : OVH SAS, 2 rue Kellermann, 59100 Roubaix, France'],
    ];
    ?>
    <section class="card" id="legal">
      <div class="card__title-row">
        <h2 class="h4"><?= icon('info') ?> Mentions légales et données personnelles</h2>
        <a class="btn btn--tertiary btn--sm" href="legal.php" target="_blank" rel="noopener"><?= icon('external') ?>Voir la page</a>
      </div>
      <p class="muted">Ces informations sont affichées sur la page publique accessible depuis le pied de chaque page. Les champs vides apparaissent « à compléter ».</p>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="legal">
        <div class="field-row">
          <?php foreach ($fields as $k => $f): ?>
            <div class="field">
              <label for="legal-<?= h($k) ?>"><?= h($f[0]) ?></label>
              <input id="legal-<?= h($k) ?>" class="input" type="text" name="<?= h($k) ?>" maxlength="500" value="<?= h($l[$k]) ?>" placeholder="<?= h($f[1]) ?>">
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form__actions"><button class="btn btn--secondary" type="submit">Enregistrer</button></div>
      </form>
    </section>
    <?php
}

/** Carte « Double authentification » de Mon compte (activation, codes de secours, désactivation). */
function account_security_card(array $user)
{
    $setup = isset($_SESSION['totp_setup']) ? (string) $_SESSION['totp_setup'] : '';
    $codes = isset($GLOBALS['tpw_totp_codes']) ? $GLOBALS['tpw_totp_codes'] : null;
    $required = totp_required($user);
    ?>
    <section class="card" id="securite">
      <div class="card__title-row">
        <h2 class="h4"><?= icon('shield') ?> Double authentification</h2>
        <?= $user['totp'] !== '' ? badge('Activée', 'success') : ($required ? badge('Obligatoire', 'warning') : badge('Non activée', '')) ?>
      </div>

      <?php if ($codes): ?>
        <div class="backup-codes" data-receipt>
          <p><strong>Vos codes de secours</strong> — chacun ne sert qu’une fois, si vous n’avez plus votre téléphone. Imprimez-les ou notez-les maintenant : ils ne seront plus affichés.</p>
          <ul class="backup-codes__list"><?php foreach ($codes as $c): ?><li><code><?= h($c) ?></code></li><?php endforeach; ?></ul>
          <button type="button" class="btn btn--tertiary btn--sm" data-print><?= icon('printer') ?>Imprimer</button>
        </div>
      <?php endif; ?>

      <?php if ($user['totp'] === '' && $setup === ''): ?>
        <p class="muted">Protégez votre compte : en plus du mot de passe, un code à 6 chiffres affiché par une application de votre téléphone vous sera demandé à chaque connexion.</p>
        <form method="post" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="totp_start">
          <button class="btn" type="submit"><?= icon('shield') ?>Activer la double authentification</button>
        </form>
      <?php elseif ($user['totp'] === ''): ?>
        <ol class="steps">
          <li>Installez une application d’authentification sur votre téléphone : <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong>, <strong>FreeOTP</strong> ou <strong>Aegis</strong>.</li>
          <li>Dans l’application, ajoutez un compte en scannant ce QR code :
            <div class="totp-setup">
              <div class="totp-qr" data-qr="<?= h(totp_uri($setup, $user['login'])) ?>" aria-label="QR code de configuration"></div>
              <p class="small">Impossible de scanner ? Saisissez cette clé :<br><code class="totp-secret"><?= h(trim(chunk_split($setup, 4, ' '))) ?></code></p>
            </div>
          </li>
          <li>Saisissez le code à 6 chiffres affiché par l’application :
            <form method="post" class="form form--inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="totp_enable">
              <div class="field"><label for="totp-code" class="sr-only">Code</label>
                <input id="totp-code" class="input input--code" type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code" placeholder="123456"></div>
              <button class="btn" type="submit">Valider et activer</button>
            </form>
          </li>
        </ol>
      <?php else: ?>
        <p class="muted">Un code de votre application vous est demandé à chaque connexion<?= ent_enabled() ? ' (y compris par l’ENT)' : '' ?>. Codes de secours restants : <strong><?= count($user['totp_backup']) ?></strong>.</p>
        <div class="split">
          <form method="post" class="form form--compact">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="totp_codes">
            <div class="field"><label for="totp-regen">Nouveaux codes de secours <span class="hint">code actuel de l’application</span></label>
              <div class="input-group"><input id="totp-regen" class="input input--code" type="text" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code">
                <button class="btn btn--secondary" type="submit">Générer</button></div></div>
          </form>
          <?php if (!$required): ?>
            <form method="post" class="form form--compact" data-confirm="Désactiver la double authentification ?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="totp_disable">
              <div class="field"><label for="totp-off">Désactiver <span class="hint">mot de passe ou code de l’application</span></label>
                <div class="input-group"><input id="totp-off" class="input" type="password" name="password" required autocomplete="current-password">
                  <button class="btn btn--tertiary btn--danger" type="submit">Désactiver</button></div></div>
            </form>
          <?php else: ?>
            <p class="muted small">La double authentification est obligatoire pour votre compte (réglage de l’administrateur).</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

/** Formulaire de saisie du code (deuxième étape de connexion). */
function view_second_factor($error)
{
    $p = $_SESSION['pending_2fa'];
    $u = find_user($p['login']);
    page_top('Vérification', 'admin');
    ?>
    <div class="container container--narrow section">
      <section class="card card--form">
        <div class="card__icon"><?= icon('shield') ?></div>
        <h1 class="h2">Code de vérification</h1>
        <p>Bonjour <?= h($u ? $u['name'] : $p['login']) ?>. Ouvrez votre application d’authentification et saisissez le code à 6 chiffres affiché pour TPWeb.</p>
        <?php if ($error !== '') alert('error', $error); ?>
        <form method="post" class="form" action="admin.php?step=2fa">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="login_2fa">
          <div class="field">
            <label for="code">Code <span class="hint">ou un code de secours (XXXX-XXXX)</span></label>
            <input id="code" class="input input--code input--otp" type="text" name="code" required maxlength="9" autocomplete="one-time-code" inputmode="numeric" autofocus>
          </div>
          <div class="form__actions">
            <button class="btn" type="submit">Vérifier</button>
            <a class="btn btn--tertiary" href="admin.php?cancel2fa=1">Annuler</a>
          </div>
        </form>
      </section>
    </div>
    <?php
    page_end();
}

/** Tableau de bord du stockage (administrateur). */
function view_storage(array $data)
{
    $o = storage_overview();
    $users = users_all();
    $rows = [];
    foreach ($users as $login => $u) {
        if ($u['role'] === 'admin') continue;
        $u = ['login' => (string) $login] + $u;
        $rows[] = ['u' => $u, 'used' => user_usage($u), 'quota' => user_quota_bytes($u)];
    }
    usort($rows, function ($a, $b) { return $b['used'] - $a['used']; });
    $disk = $o['disk'] ?: 0;
    $free = $o['free'] !== false ? $o['free'] : 0;
    $usedDisk = $disk ? $disk - $free : 0;
    ?>
    <div class="page-head">
      <div>
        <h1>Stockage</h1>
        <p class="muted">Place occupée par TPWeb sur le serveur et par chaque enseignant. Les quotas se règlent dans la fiche de chaque compte.</p>
      </div>
    </div>

    <?php if ($disk && $free < 2 * 1024 * 1024 * 1024) alert('warning', 'Il reste moins de 2 Go sur le disque du serveur : videz la corbeille, supprimez d’anciennes sauvegardes ou augmentez l’espace disque.', 'Espace disque faible'); ?>

    <dl class="figures">
      <div><dt>TPWeb (total)</dt><dd><?= h(human_size($o['total'])) ?></dd></div>
      <div><dt>Libre sur le disque</dt><dd><?= $disk ? h(human_size($free)) : '—' ?></dd></div>
      <div><dt>Disque occupé</dt><dd><?= $disk ? (int) round($usedDisk / $disk * 100) . '<small> %</small>' : '—' ?></dd></div>
    </dl>

    <section class="card">
      <h2 class="h4">Répartition</h2>
      <?php $max = max(1, max(array_map(function ($p) { return $p['size']; }, $o['parts']))); ?>
      <ul class="bars">
        <?php foreach ($o['parts'] as $k => $p): ?>
          <li><span class="bars__label"><?= h($p['label']) ?></span><span class="meter"><span style="width:<?= max($p['size'] ? 1 : 0, (int) round($p['size'] / $max * 100)) ?>%"></span></span><span class="bars__value"><?= h(human_size($p['size'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <p class="muted small">Le cache des aperçus (documents Word/PowerPoint convertis) est purgé automatiquement au bout de 60 jours sans consultation.</p>
    </section>

    <section class="card card--flush">
      <div class="card__header"><h2 class="h4">Par enseignant</h2>
        <?php if (count($rows) > 8): ?><input type="search" class="input list-filter list-filter--inline" placeholder="Rechercher…" data-list-filter="#storage-table" aria-label="Rechercher un enseignant"><?php endif; ?></div>
      <?php if (!$rows): ?>
        <p class="card__empty">Aucun compte enseignant.</p>
      <?php else: ?>
        <div class="table-wrap table-wrap--flush">
          <table class="table table--rows" id="storage-table">
            <thead><tr><th>Enseignant</th><th>Utilisé</th><th>Quota</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): $pct = $r['quota'] ? min(100, (int) round($r['used'] / $r['quota'] * 100)) : 0; ?>
                <tr data-filter-text="<?= h(fold_text($r['u']['name'] . ' ' . $r['u']['login'] . ' ' . $r['u']['subject'])) ?>">
                  <td><strong><?= h($r['u']['name']) ?></strong><br><span class="muted small"><?= h($r['u']['subject'] !== '' ? $r['u']['subject'] : $r['u']['login']) ?></span></td>
                  <td><span class="usage"><span class="meter meter--sm<?= $pct >= 90 ? ' meter--low' : ($pct >= 75 ? ' meter--mid' : '') ?>"><span style="width:<?= $pct ?>%"></span></span><span class="small"><?= h(human_size($r['used'])) ?></span></span></td>
                  <td><?= $r['quota'] ? h(human_size($r['quota'])) . ' <span class="muted small">(' . $pct . ' %)</span>' : '<span class="muted">illimité</span>' ?></td>
                  <td class="actions"><a class="btn btn--tertiary btn--sm" href="<?= h(admin_url('users') . '&edit=' . rawurlencode($r['u']['login'])) ?>"><?= icon('edit') ?>Quota</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

/** Carte « Sauvegarde automatique » (page Sauvegardes). */
function autobackup_card()
{
    $cfg = autobackup_config();
    $st = autobackup_state();
    $next = $cfg['enabled'] ? autobackup_next($cfg, $st) : null;
    $hasPass = secret_get('webdav_pass') !== '';
    ?>
    <section class="card" id="auto">
      <div class="card__title-row">
        <h2 class="h4"><?= icon('clock') ?> Sauvegarde automatique</h2>
        <?= $cfg['enabled'] ? badge('Activée', 'success') : badge('Désactivée', '') ?>
      </div>
      <?php if ($cfg['enabled']): ?>
        <p class="small">Prochaine sauvegarde : <strong><?= h(date('d/m/Y à H:i', max(time(), $next))) ?></strong><?= $st['last'] ? ' · dernière : ' . h(date('d/m/Y à H:i', $st['last'])) : '' ?></p>
      <?php endif; ?>
      <form method="post" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="autobackup_save">
        <label class="toggle"><input type="checkbox" name="enabled" value="1"<?= $cfg['enabled'] ? ' checked' : '' ?>><span>Sauvegarder automatiquement</span></label>
        <div class="field-row field-row--4">
          <div class="field"><label for="ab-type">Contenu</label>
            <select id="ab-type" class="input" name="type"><option value="reglages"<?= $cfg['type'] === 'reglages' ? ' selected' : '' ?>>Réglages</option><option value="complete"<?= $cfg['type'] === 'complete' ? ' selected' : '' ?>>Complète (cours + rendus)</option></select></div>
          <div class="field"><label for="ab-freq">Fréquence</label>
            <select id="ab-freq" class="input" name="freq"><option value="daily"<?= $cfg['freq'] === 'daily' ? ' selected' : '' ?>>Chaque jour</option><option value="weekly"<?= $cfg['freq'] === 'weekly' ? ' selected' : '' ?>>Chaque semaine</option></select></div>
          <div class="field"><label for="ab-hour">Vers</label>
            <select id="ab-hour" class="input" name="hour"><?php for ($hh = 0; $hh < 24; $hh++): ?><option value="<?= $hh ?>"<?= $cfg['hour'] === $hh ? ' selected' : '' ?>><?= sprintf('%02d:00', $hh) ?></option><?php endfor; ?></select></div>
          <div class="field"><label for="ab-keep">Garder les</label>
            <select id="ab-keep" class="input" name="keep"><?php foreach ([3, 5, 7, 14, 30] as $k): ?><option value="<?= $k ?>"<?= $cfg['keep'] === $k ? ' selected' : '' ?>><?= $k ?> dernières</option><?php endforeach; ?></select></div>
        </div>
        <fieldset class="fieldset">
          <legend>Copie hors du serveur <span class="hint">recommandé : une sauvegarde sur le même disque ne protège pas d’une panne</span></legend>
          <div class="field">
            <label for="ab-dir">Dossier externe <span class="hint">partage réseau ou disque monté, ex. : /mnt/nas/tpweb ou \\NAS\sauvegardes\tpweb</span></label>
            <input id="ab-dir" class="input" type="text" name="ext_dir" maxlength="300" value="<?= h($cfg['ext_dir']) ?>">
          </div>
          <div class="field-row field-row--3">
            <div class="field"><label for="ab-dav">Dossier WebDAV <span class="hint">Nextcloud, NAS, apps.education.fr…</span></label>
              <input id="ab-dav" class="input" type="url" name="webdav_url" maxlength="300" value="<?= h($cfg['webdav_url']) ?>" placeholder="https://nuage.exemple.fr/remote.php/dav/files/tpweb/Sauvegardes"></div>
            <div class="field"><label for="ab-user">Utilisateur</label>
              <input id="ab-user" class="input" type="text" name="webdav_user" maxlength="120" value="<?= h($cfg['webdav_user']) ?>" autocomplete="off"></div>
            <div class="field"><label for="ab-pass">Mot de passe <span class="hint"><?= $hasPass ? 'enregistré — vide = inchangé' : 'mot de passe d’application conseillé' ?></span></label>
              <input id="ab-pass" class="input" type="password" name="webdav_pass" maxlength="200" autocomplete="new-password"></div>
          </div>
        </fieldset>
        <details class="help">
          <summary>Déclenchement à heure fixe (recommandé)</summary>
          <p>Sans tâche planifiée, la sauvegarde part à la première visite de l’espace enseignant après l’heure prévue. Pour une sauvegarde à heure fixe, ajoutez une tâche planifiée qui lance <code>cron.php</code> toutes les heures :</p>
          <p>Docker (crontab de l’hôte) : <code>0 * * * * docker exec tpweb php /var/www/html/cron.php</code><br>
             Windows (Planificateur de tâches) : <code>C:\xampp\php\php.exe C:\xampp\htdocs\TPWeb\cron.php</code></p>
        </details>
        <div class="form__actions">
          <button class="btn btn--secondary" type="submit">Enregistrer</button>
        </div>
      </form>
      <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="autobackup_now">
        <button class="btn btn--tertiary btn--sm" type="submit"<?= zip_available() ? '' : ' disabled' ?>><?= icon('archive') ?>Lancer maintenant (avec copie externe)</button>
      </form>
      <?php if ($st['log']): ?>
        <h3 class="h5">Dernières exécutions</h3>
        <ul class="log-list">
          <?php foreach (array_reverse(array_slice($st['log'], -6)) as $l): ?>
            <li><?= $l['ok'] ? badge('OK', 'success') : badge('À vérifier', 'error') ?> <span><?= h(date('d/m/Y H:i', $l['time'])) ?> — <?= h($l['file'] !== '' ? $l['file'] : 'aucune archive') ?> · <?= h($l['msg']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php
}
