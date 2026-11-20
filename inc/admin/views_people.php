<?php
/**
 * Vues : élèves (liste, ajout, import) et répartition des élèves dans les groupes.
 */

/** Classes dont l'utilisateur peut gérer les élèves. */
function student_classes_for(array $user, array $data)
{
    return is_admin($user) ? $data['levels'] : array_values(array_intersect($data['levels'], $user['classes']));
}

function view_students(array $data, array $user)
{
    $classes = student_classes_for($user, $data);
    $edit = query('edit');
    if ($edit !== '') { student_form($edit, $data, $classes); return; }

    $all = students_all();
    $mine = array_filter($all, function ($s) use ($classes, $user) { return is_admin($user) || in_array($s['class'], $classes, true); });
    $counts = [];
    foreach ($mine as $s) $counts[$s['class']] = (isset($counts[$s['class']]) ? $counts[$s['class']] : 0) + 1;
    $class = query('class');
    if ($class !== '' && !in_array($class, $classes, true) && !isset($counts[$class])) $class = '';
    $rows = $class === '' ? $mine : array_filter($mine, function ($s) use ($class) { return $s['class'] === $class; });
    $ent = ent_config($data);
    ?>
    <div class="page-head">
      <div>
        <h1>Élèves</h1>
        <p class="muted"><?= count($mine) ?> élève<?= count($mine) > 1 ? 's' : '' ?> enregistré<?= count($mine) > 1 ? 's' : '' ?>. Facultatif : sans liste, les classes restent accessibles à tous avec leur code.</p>
      </div>
      <div class="page-head__actions">
        <a class="btn" href="<?= h(admin_url('students') . '&edit=new' . ($class !== '' ? '&class=' . rawurlencode($class) : '')) ?>"><?= icon('plus') ?>Ajouter un élève</a>
      </div>
    </div>

    <?php student_requests_card($user, $data); ?>
    <?php if (is_admin($user)) student_access_card($data, count($all)); ?>

    <details class="card card--details"<?= $mine ? '' : ' open' ?>>
      <summary><span><?= icon('import') ?> <strong>Importer une liste d’élèves</strong> <span class="muted small">— fichier CSV ou export Pronote / ENT</span></span><?= icon('chevron') ?></summary>
      <div class="split">
        <form method="post" enctype="multipart/form-data" class="form form--tight">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="students_import">
          <div class="field">
            <label for="st-file">Fichier (.csv)</label>
            <input id="st-file" class="input" type="file" name="file" accept=".csv,.txt" required>
          </div>
          <label class="toggle"><input type="checkbox" name="replace" value="1"><span>Remplacer les élèves des classes présentes dans le fichier (nouvelle année, changement de liste)</span></label>
          <div class="form__actions"><button class="btn" type="submit"><?= icon('import') ?>Importer</button>
            <a class="btn btn--tertiary btn--sm" href="admin.php?dl=students_template"><?= icon('download') ?>Modèle à remplir</a></div>
        </form>
        <div class="help help--flat">
          <p><strong>Colonnes reconnues</strong> (première ligne = titres, séparateur ; ou ,) :</p>
          <p><code>Nom ; Prénom ; Classe ; Groupes ; Identifiant ENT</code></p>
          <ul>
            <li>Seules les colonnes Nom, Prénom et Classe sont obligatoires ; une colonne unique « Élève » (NOM Prénom) est aussi acceptée.</li>
            <li>La classe doit exister dans TPWeb (majuscules et espaces ignorés).</li>
            <li>Groupes : séparés par des virgules (ex. « Groupe A, Tiers-temps »), ils doivent exister dans la classe.</li>
            <li>Identifiant ENT : permet à l’élève de se connecter par l’ENT<?= empty($ent['students']) ? ' (à activer dans Paramètres › Connexion par l’ENT)' : '' ?>.</li>
            <li>Un élève déjà présent (même identifiant ENT ou même nom dans la même classe) est mis à jour, pas dupliqué.</li>
          </ul>
        </div>
      </div>
    </details>

    <?php if (!$mine): ?>
      <div class="empty"><?= icon('users') ?><p><strong>Aucun élève enregistré.</strong></p><p class="muted">Importez une liste ou ajoutez les élèves un par un.</p></div>
      <?php return; ?>
    <?php endif; ?>

    <div class="list-tools">
      <input type="search" class="input list-tools__search" placeholder="Rechercher un élève, un identifiant ENT…" data-list-filter="#students-table" aria-label="Rechercher un élève">
      <form method="get" class="inline-form">
        <input type="hidden" name="view" value="students">
        <label for="st-class" class="sr-only">Classe</label>
        <select id="st-class" class="input" name="class" onchange="this.form.submit()">
          <option value="">Toutes les classes (<?= count($mine) ?>)</option>
          <?php foreach ($classes as $c): if (empty($counts[$c])) continue; ?>
            <option value="<?= h($c) ?>"<?= $c === $class ? ' selected' : '' ?>><?= h($c) ?> (<?= (int) $counts[$c] ?>)</option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="link-actions">
        <?php if ($class !== '' && class_groups($data, $class)): ?>
          <a class="btn btn--secondary btn--sm" href="<?= h(admin_url('groups') . '&class=' . rawurlencode($class)) ?>"><?= icon('group') ?>Répartir dans les groupes</a>
        <?php endif; ?>
        <a class="btn btn--tertiary btn--sm" href="<?= h('admin.php?dl=students_csv' . ($class !== '' ? '&class=' . rawurlencode($class) : '')) ?>"><?= icon('download') ?>Exporter</a>
      </div>
    </div>

    <form method="post" data-bulk-form data-confirm-delete="Retirer les élèves sélectionnés de la liste ? Leurs rendus sont conservés.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="students_bulk">
      <div class="bulk-bar" data-bulk-bar hidden>
        <strong data-bulk-count>0 élève sélectionné</strong>
        <label class="sr-only" for="bulk-op">Action</label>
        <select id="bulk-op" class="input input--sm" name="op" data-bulk-op required>
          <option value="">Choisir une action…</option>
          <option value="class">Changer de classe</option>
          <option value="group_add">Ajouter à un groupe</option>
          <option value="group_clear">Retirer de tous les groupes</option>
          <option value="ent_clear">Effacer l’identifiant ENT</option>
          <option value="delete">Retirer de la liste</option>
        </select>
        <select class="input input--sm" name="class_to" data-bulk-for="class" hidden aria-label="Nouvelle classe">
          <?php foreach ($classes as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?>
        </select>
        <select class="input input--sm" name="group_to" data-bulk-for="group_add" hidden aria-label="Groupe">
          <?php foreach ($classes as $c): foreach (group_names($data, $c) as $g): ?><option value="<?= h(group_key($c, $g)) ?>"><?= h(target_label(group_key($c, $g))) ?></option><?php endforeach; endforeach; ?>
        </select>
        <button class="btn btn--sm" type="submit">Appliquer</button>
        <button class="btn btn--sm btn--bulk-delete" type="submit" name="op" value="delete" formnovalidate data-bulk-delete><?= icon('trash') ?>Supprimer</button>
        <button class="link-btn" type="button" data-bulk-clear>Tout désélectionner</button>
      </div>
      <div class="table-wrap">
        <table class="table table--rows" id="students-table">
          <thead><tr>
            <th class="check-col"><input type="checkbox" data-bulk-all aria-label="Tout sélectionner"></th>
            <th>Élève</th><th>Classe</th><th>Groupes</th><th>Identifiant ENT</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $id => $s): $id = (string) $id; $known = level_ok($s['class'], $data); ?>
              <tr data-filter-text="<?= h(fold_text($s['last'] . ' ' . $s['first'] . ' ' . $s['class'] . ' ' . $s['ent_id'] . ' ' . implode(' ', $s['groups']))) ?>">
                <td class="check-col"><input type="checkbox" name="ids[]" value="<?= h($id) ?>" data-bulk-item aria-label="<?= h('Sélectionner ' . student_name($s)) ?>"></td>
                <td><strong><?= h(mb_strtoupper($s['last'])) ?></strong> <?= h($s['first']) ?></td>
                <td><?= h($s['class']) ?><?= $known ? '' : ' ' . badge('Classe inconnue', 'error') ?></td>
                <td><?= $s['groups'] ? class_chips(array_map(function ($g) use ($s) { return group_key($s['class'], $g); }, $s['groups']), 3) : '<span class="muted">—</span>' ?></td>
                <td><?= $s['ent_id'] !== '' ? '<code class="small">' . h($s['ent_id']) . '</code>' : '<span class="muted">—</span>' ?></td>
                <td class="actions"><a class="btn btn--tertiary btn--sm" href="<?= h(admin_url('students') . '&edit=' . rawurlencode($id)) ?>"><?= icon('edit') ?>Modifier</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>

    <?php if (is_admin($user)): ?>
      <section class="card card--danger">
        <form method="post" class="inline-form" data-confirm="<?= h($class !== '' ? 'Retirer tous les élèves de ' . $class . ' de la liste ?' : 'Vider toute la liste des élèves ?') ?> Les rendus et résultats déjà enregistrés sont conservés.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="students_purge">
          <input type="hidden" name="class" value="<?= h($class) ?>">
          <button class="btn btn--tertiary btn--danger" type="submit"><?= icon('trash') ?><?= $class !== '' ? 'Retirer les élèves de ' . h($class) : 'Vider la liste des élèves' ?></button>
        </form>
      </section>
    <?php endif; ?>
    <?php
}

function student_form($id, array $data, array $classes)
{
    $isNew = $id === 'new';
    $s = $isNew ? ['last' => '', 'first' => '', 'class' => query('class'), 'groups' => [], 'ent_id' => ''] : find_student($id);
    if (!$s) { alert('error', 'Élève introuvable.'); return; }
    breadcrumb([['Élèves', admin_url('students')], [$isNew ? 'Nouvel élève' : student_name($s), null]]);
    ?>
    <div class="page-head page-head--compact">
      <div><h1><?= $isNew ? 'Nouvel élève' : h(student_name($s)) ?></h1></div>
      <?php if (!$isNew): ?>
        <div class="page-head__actions">
          <form method="post" class="inline-form" data-confirm="Retirer cet élève de la liste ? Ses rendus sont conservés.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="student_delete">
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <button class="btn btn--tertiary btn--sm btn--danger" type="submit"><?= icon('trash') ?>Retirer de la liste</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
    <form method="post" class="form form--tight" data-student-form>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="student_save">
      <input type="hidden" name="id" value="<?= h($isNew ? '' : $id) ?>">
      <section class="card">
        <div class="field-row">
          <div class="field"><label for="st-last">Nom</label><input id="st-last" class="input" type="text" name="last" required maxlength="60" value="<?= h($s['last']) ?>"></div>
          <div class="field"><label for="st-first">Prénom</label><input id="st-first" class="input" type="text" name="first" required maxlength="60" value="<?= h($s['first']) ?>"></div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="st-cls">Classe</label>
            <select id="st-cls" class="input" name="class" required data-class-select>
              <option value="">— Choisir —</option>
              <?php foreach ($classes as $c): ?><option value="<?= h($c) ?>"<?= $c === $s['class'] ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="st-ent">Identifiant ENT <span class="hint">facultatif — pour la connexion par l’ENT</span></label>
            <input id="st-ent" class="input" type="text" name="ent_id" maxlength="120" spellcheck="false" value="<?= h($s['ent_id']) ?>" placeholder="ex. : lea.martin">
          </div>
        </div>
        <div class="field">
          <span class="label">Groupes</span>
          <?php foreach ($classes as $c): $gs = group_names($data, $c); ?>
            <div class="pills" data-class-groups="<?= h($c) ?>"<?= $c === $s['class'] ? '' : ' hidden' ?>>
              <?php if (!$gs): ?><span class="muted small">Aucun groupe dans cette classe.</span><?php endif; ?>
              <?php foreach ($gs as $g): ?>
                <label class="pill"><input type="checkbox" name="groups[<?= h($c) ?>][]" value="<?= h($g) ?>"<?= $c === $s['class'] && in_array($g, $s['groups'], true) ? ' checked' : '' ?>><span><?= h($g) ?></span></label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <div class="form__actions">
        <button class="btn" type="submit"><?= $isNew ? 'Ajouter l’élève' : 'Enregistrer' ?></button>
        <?php if ($isNew): ?><button class="btn btn--secondary" type="submit" name="again" value="1">Ajouter et saisir le suivant</button><?php endif; ?>
        <a class="btn btn--tertiary" href="<?= h(admin_url('students') . ($s['class'] !== '' ? '&class=' . rawurlencode($s['class']) : '')) ?>">Annuler</a>
      </div>
    </form>
    <?php
}

/** Répartition des élèves d'une classe dans ses groupes (tableau à cases). */
function view_group_members($class, array $data)
{
    $groups = group_names($data, $class);
    $students = students_of_class($class);
    breadcrumb([['Groupes', admin_url('groups')], [$class, null]]);
    ?>
    <div class="page-head page-head--compact">
      <div><h1>Groupes de <?= h($class) ?></h1>
        <p class="muted">Cochez les groupes de chaque élève. Un élève connecté par l’ENT retrouve automatiquement ses groupes, sans rien choisir.</p></div>
    </div>
    <?php if (!$groups): ?>
      <div class="empty"><?= icon('group') ?><p><strong>Aucun groupe dans cette classe.</strong></p><p class="muted"><a href="<?= h(admin_url('groups')) ?>">Créez d’abord un groupe</a>.</p></div>
      <?php return; ?>
    <?php endif; ?>
    <?php if (!$students): ?>
      <div class="empty"><?= icon('users') ?><p><strong>Aucun élève enregistré en <?= h($class) ?>.</strong></p><p class="muted"><a href="<?= h(admin_url('students')) ?>">Importez ou ajoutez les élèves</a> pour les répartir. Sans liste, chaque élève choisit son groupe sur la page de sa classe.</p></div>
      <?php return; ?>
    <?php endif; ?>
    <form method="post" class="form form--tight">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="group_members">
      <input type="hidden" name="class" value="<?= h($class) ?>">
      <div class="table-wrap">
        <table class="table table--rows table--matrix">
          <thead><tr><th>Élève</th><?php foreach ($groups as $g): ?><th class="center"><?= h($g) ?><br><button type="button" class="link-btn small" data-col-toggle="<?= h(md5($g)) ?>">tout</button></th><?php endforeach; ?></tr></thead>
          <tbody>
            <?php foreach ($students as $id => $s): ?>
              <tr>
                <td><strong><?= h(mb_strtoupper($s['last'])) ?></strong> <?= h($s['first']) ?></td>
                <?php foreach ($groups as $g): ?>
                  <td class="center"><input type="checkbox" class="matrix-box" data-col="<?= h(md5($g)) ?>" name="m[<?= h((string) $id) ?>][]" value="<?= h($g) ?>"<?= in_array($g, $s['groups'], true) ? ' checked' : '' ?> aria-label="<?= h(student_name($s) . ' — ' . $g) ?>"></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="form__actions form__actions--sticky"><button class="btn" type="submit">Enregistrer la répartition</button>
        <a class="btn btn--tertiary" href="<?= h(admin_url('groups')) ?>">Retour</a></div>
    </form>
    <?php
}

/** Formulaire d'import des comptes enseignants (page Comptes enseignants). */
function users_import_card()
{
    ?>
    <details class="card card--details">
      <summary><span><?= icon('import') ?> <strong>Importer des comptes enseignants</strong> <span class="muted small">— fichier CSV</span></span><?= icon('chevron') ?></summary>
      <div class="split">
        <form method="post" enctype="multipart/form-data" class="form form--tight">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="users_import">
          <div class="field"><label for="us-file">Fichier (.csv)</label><input id="us-file" class="input" type="file" name="file" accept=".csv,.txt" required></div>
          <label class="toggle"><input type="checkbox" name="update" value="1" checked><span>Mettre à jour les comptes existants (matière, classes, identifiant ENT)</span></label>
          <div class="form__actions"><button class="btn" type="submit"><?= icon('import') ?>Importer</button>
            <a class="btn btn--tertiary btn--sm" href="admin.php?dl=users_template"><?= icon('download') ?>Modèle à remplir</a></div>
        </form>
        <div class="help help--flat">
          <p><strong>Colonnes reconnues</strong> :</p>
          <p><code>Nom ; Prénom ; Identifiant ; Matière ; Classes ; Identifiant ENT ; Mot de passe</code></p>
          <ul>
            <li>Obligatoire : le nom (ou « Nom affiché »). L’identifiant est créé à partir de prénom.nom s’il est absent.</li>
            <li>Classes séparées par des virgules ; les dossiers « Classe/Matière » sont créés automatiquement.</li>
            <li>Sans mot de passe, l’enseignant se connecte par l’ENT (identifiant ENT conseillé) ; vous pourrez aussi lui en définir un.</li>
          </ul>
        </div>
      </div>
    </details>
    <?php
}

/** Carte « Accès des élèves » : avec le code de classe, ou uniquement par l'ENT. */
function student_access_card(array $data, $nbStudents)
{
    $ent = ent_config($data);
    $ready = !empty($ent['enabled']) && $ent['url'] !== '' && !empty($ent['students']);
    $mode = isset($data['student_access']) && $data['student_access'] === 'ent' ? 'ent' : 'code';
    ?>
    <section class="card" id="acces">
      <div class="card__title-row">
        <h2 class="h4"><?= icon('lock') ?> Accès des élèves au portail</h2>
        <?= $mode === 'ent' && $ready ? badge('ENT obligatoire', 'info') : badge('Libre / code de classe', '') ?>
      </div>
      <form method="post" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="student_access">
        <div class="choices">
          <label class="choice"><input type="radio" name="mode" value="code"<?= $mode === 'code' ? ' checked' : '' ?>>
            <span class="choice__body"><strong>Classes visibles, accès avec le code</strong><span>Comme aujourd’hui. Les élèves de la liste peuvent aussi se connecter par l’ENT.</span></span></label>
          <label class="choice"><input type="radio" name="mode" value="ent"<?= $mode === 'ent' ? ' checked' : '' ?><?= $ready ? '' : ' disabled' ?>>
            <span class="choice__body"><strong>Connexion par l’ENT obligatoire</strong><span>Plus aucune classe ni ressource n’est affichée sans connexion : chaque élève arrive directement dans sa classe.</span></span></label>
        </div>
        <?php if (!$ready): ?>
          <p class="muted small">Pour activer ce mode : Paramètres › Connexion par l’ENT › cochez « Connexion des élèves par l’ENT » (et attribuez le connecteur au profil Élèves dans l’ENT).</p>
        <?php elseif ($nbStudents === 0): ?>
          <p class="muted small">Importez d’abord la liste des élèves avec leur identifiant ENT : un élève absent de la liste ne pourra plus accéder au portail.</p>
        <?php endif; ?>
        <div class="form__actions"><button class="btn btn--secondary btn--sm" type="submit">Enregistrer</button></div>
      </form>
    </section>
    <?php
}

/** Signalements : bugs et idées (enseignant : les siens ; administrateur : tous). */
function view_feedback(array $user)
{
    $admin = is_admin($user);
    $all = feedback_all();
    $types = feedback_types();
    $statuses = feedback_statuses();
    $status = query('status');
    $list = array_values(array_filter($all, function ($f) use ($admin, $user, $status) {
        if (!$admin && !($f['role'] === 'enseignant' && $f['author'] === $user['login'])) return false;
        return $status === '' || $f['status'] === $status;
    }));
    $page = isset($_SERVER['HTTP_REFERER']) ? (string) parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY) : '';
    ?>
    <div class="page-head">
      <div>
        <h1>Signaler un bug ou une idée</h1>
        <p class="muted">Un problème, une page qui ne marche pas, une fonction qui manque ? Décrivez-le ici : <?= $admin ? 'vous retrouvez ci-dessous tous les signalements des enseignants et des élèves.' : 'l’administrateur du portail le reçoit et vous répond ici.' ?></p>
      </div>
    </div>

    <details class="card card--details"<?= $list && $admin ? '' : ' open' ?>>
      <summary><span><?= icon('plus') ?> <strong>Nouveau signalement</strong></span><?= icon('chevron') ?></summary>
      <form method="post" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="feedback_add">
        <input type="hidden" name="page" value="<?= h($page) ?>">
        <div class="choices">
          <?php foreach ($types as $k => $label): ?>
            <label class="choice"><input type="radio" name="type" value="<?= h($k) ?>"<?= $k === 'bug' ? ' checked' : '' ?>><span class="choice__body"><strong><?= h($label) ?></strong></span></label>
          <?php endforeach; ?>
        </div>
        <div class="field"><label for="fb-title">Titre</label><input id="fb-title" class="input" type="text" name="title" required maxlength="150" placeholder="ex. : Le PDF ne s’affiche pas dans l’aperçu"></div>
        <div class="field"><label for="fb-text">Description <span class="hint">que faisiez-vous, sur quelle page, que s’est-il passé ?</span></label>
          <textarea id="fb-text" class="input input--text" name="text" rows="4" maxlength="4000"></textarea></div>
        <div class="form__actions"><button class="btn" type="submit"><?= icon('megaphone') ?>Envoyer</button></div>
      </form>
    </details>

    <?php if ($admin): ?>
      <nav class="tabs tabs--page" aria-label="Filtrer">
        <a class="tabs__tab<?= $status === '' ? ' is-active' : '' ?>" href="<?= h(admin_url('feedback')) ?>">Tous<span class="tabs__count"><?= count($all) ?></span></a>
        <?php foreach ($statuses as $k => $st): $n = count(array_filter($all, function ($f) use ($k) { return $f['status'] === $k; })); ?>
          <a class="tabs__tab<?= $status === $k ? ' is-active' : '' ?>" href="<?= h(admin_url('feedback') . '&status=' . $k) ?>"><?= h($st[0]) ?><span class="tabs__count"><?= $n ?></span></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if (!$list): ?>
      <div class="empty"><?= icon('megaphone') ?><p><strong>Aucun signalement<?= $status !== '' ? ' dans cette catégorie' : '' ?>.</strong></p></div>
    <?php endif; ?>
    <div class="feedback-list">
      <?php foreach ($list as $f): $st = isset($statuses[$f['status']]) ? $statuses[$f['status']] : $statuses['new']; ?>
        <article class="card feedback feedback--<?= h($f['type']) ?>" id="fb-<?= h($f['id']) ?>">
          <div class="feedback__head">
            <?= badge(isset($types[$f['type']]) ? $types[$f['type']] : $f['type'], $f['type'] === 'bug' ? 'error' : 'info') ?>
            <?= badge($st[0], $st[1]) ?>
            <span class="muted small"><?= h(date('d/m/Y à H:i', $f['time'])) ?> · <?= h($f['role'] === 'élève' ? 'Élève' . ($f['author'] !== '' ? ' : ' . $f['author'] : '') . ($f['class'] !== '' ? ' (' . $f['class'] . ')' : '') : 'Enseignant : ' . $f['author']) ?></span>
          </div>
          <h2 class="h4"><?= h($f['title']) ?></h2>
          <?php if ($f['text'] !== ''): ?><p class="feedback__text"><?= nl2br(h($f['text'])) ?></p><?php endif; ?>
          <?php if ($admin && $f['page'] !== ''): ?><p class="muted small">Page : <code><?= h($f['page']) ?></code></p><?php endif; ?>
          <?php foreach ($f['replies'] as $r): ?>
            <div class="feedback__reply"><?= icon('user') ?><div><p class="muted small"><?= h($r['by']) ?> · <?= h(date('d/m/Y à H:i', (int) $r['time'])) ?></p><p><?= nl2br(h($r['text'])) ?></p></div></div>
          <?php endforeach; ?>
          <?php if ($admin): ?>
            <form method="post" class="form form--tight feedback__admin">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="feedback_update">
              <input type="hidden" name="id" value="<?= h($f['id']) ?>">
              <div class="feedback__row">
                <select class="input input--sm" name="status" aria-label="Statut">
                  <?php foreach ($statuses as $k => $s2): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($s2[0]) ?></option><?php endforeach; ?>
                </select>
                <input class="input" type="text" name="reply" maxlength="2000" placeholder="Répondre (visible par l’enseignant qui a signalé)…" aria-label="Réponse">
                <button class="btn btn--secondary btn--sm" type="submit">Enregistrer</button>
                <button class="icon-btn icon-btn--danger" type="submit" name="delete" value="1" title="Supprimer" formnovalidate onclick="return confirm('Supprimer ce signalement ?')"><?= icon('trash') ?><span class="sr-only">Supprimer</span></button>
              </div>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php
}

/** Demandes d'accès des élèves (première connexion ENT). */
function student_requests_card(array $user, array $data)
{
    $reqs = student_requests_for($user);
    if (!$reqs) return;
    $classes = student_classes_for($user, $data);
    ?>
    <section class="card card--pending" id="demandes">
      <h2 class="h4"><?= icon('clock') ?> <?= count($reqs) ?> demande<?= count($reqs) > 1 ? 's' : '' ?> d’accès d’élève<?= count($reqs) > 1 ? 's' : '' ?></h2>
      <p class="muted small">Élèves connectés par l’ENT mais absents de la liste. Vérifiez la classe puis validez : l’élève pourra se connecter immédiatement.</p>
      <form method="post" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="student_request_decide">
        <ul class="pending-list">
          <?php foreach ($reqs as $key => $r): ?>
            <li>
              <span><strong><?= h(mb_strtoupper($r['last'])) ?></strong> <?= h($r['first']) ?> <span class="muted small">ENT <code><?= h($r['ent_id']) ?></code> · le <?= h(date('d/m à H:i', (int) $r['time'])) ?></span></span>
              <span class="link-actions">
                <select class="input input--sm" name="class[<?= h($key) ?>]" aria-label="Classe">
                  <?php foreach ($classes as $c): ?><option value="<?= h($c) ?>"<?= $c === $r['class'] ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn--sm" type="submit" name="accept" value="<?= h($key) ?>"><?= icon('check') ?>Valider</button>
                <button class="btn btn--tertiary btn--sm btn--danger" type="submit" name="refuse" value="<?= h($key) ?>">Refuser</button>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($reqs) > 1): ?><div class="form__actions"><button class="btn btn--secondary btn--sm" type="submit" name="accept_all" value="1">Tout valider</button></div><?php endif; ?>
      </form>
    </section>
    <?php
}
