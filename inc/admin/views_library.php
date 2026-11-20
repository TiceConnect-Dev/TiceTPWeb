<?php
/**
 * Vues : connexion et bibliothèque (publication, fichiers, dépôt des travaux).
 */

function view_login($setup, $error)
{
    page_top($setup ? 'Installation' : 'Connexion', 'admin');
    ?>
    <div class="container container--narrow section">
      <section class="card card--form">
        <div class="card__icon"><?= icon($setup ? 'settings' : 'lock') ?></div>
        <h1 class="h2"><?= $setup ? 'Créer le compte administrateur' : 'Espace enseignant' ?></h1>
        <p><?= $setup
            ? 'Première utilisation : créez le compte qui administrera le portail. Vous pourrez ensuite créer les comptes de vos collègues.'
            : 'Connectez-vous pour gérer vos cours, les dépôts des élèves et l’accès des classes.' ?></p>
        <?php if ($error !== '') alert('error', $error); ?>
        <?php if (!$setup && ent_enabled()): $ent = ent_config(); ?>
          <div class="ent-login">
            <a class="btn btn--ent" href="cas.php?mode=login"><?= icon('school') ?>Se connecter avec <?= h($ent['label']) ?></a>
            <p class="separator"><span>ou avec un identifiant TPWeb</span></p>
          </div>
        <?php endif; ?>
        <form method="post" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $setup ? 'setup' : 'login' ?>">
          <div class="field">
            <label for="login">Identifiant<?= $setup ? ' <span class="hint">Lettres minuscules, chiffres, point ou tiret — ex. : admin, m.dupont</span>' : '' ?></label>
            <input id="login" class="input" type="text" name="login" required autocapitalize="none" spellcheck="false" autocomplete="username"
                   value="<?= h($setup ? 'admin' : post('login')) ?>"<?= $setup ? '' : ' autofocus' ?>>
          </div>
          <?php if ($setup): ?>
            <div class="field">
              <label for="name">Nom affiché <span class="hint">ex. : M. Dupont</span></label>
              <input id="name" class="input" type="text" name="name" required maxlength="60">
            </div>
          <?php endif; ?>
          <div class="field">
            <label for="password">Mot de passe<?= $setup ? ' <span class="hint">' . TPW_PASSWORD_MIN . ' caractères minimum</span>' : '' ?></label>
            <input id="password" class="input" type="password" name="password" required
                   <?= $setup ? 'minlength="' . TPW_PASSWORD_MIN . '" autocomplete="new-password"' : 'autocomplete="current-password"' ?>>
          </div>
          <?php if ($setup): ?>
            <div class="field">
              <label for="password_confirm">Confirmer le mot de passe</label>
              <input id="password_confirm" class="input" type="password" name="password_confirm" required minlength="<?= TPW_PASSWORD_MIN ?>" autocomplete="new-password">
            </div>
          <?php endif; ?>
          <div class="form__actions">
            <button class="btn" type="submit"><?= $setup ? 'Créer le compte' : 'Se connecter' ?></button>
          </div>
          <?php if (!$setup): ?>
            <p class="muted small">Après une mise à jour depuis une ancienne version, l’identifiant est <strong>admin</strong> avec votre mot de passe habituel.</p>
          <?php endif; ?>
        </form>
      </section>
    </div>
    <?php
    page_end();
}

/** Pastilles de classes (les premières, puis « +n »). */
function class_chips(array $levels, $max = 4)
{
    $out = '<span class="chips">';
    foreach (array_slice($levels, 0, $max) as $lv) {
        $isGroup = strpos((string) $lv, GROUP_SEP) !== false;
        $out .= '<span class="chip' . ($isGroup ? ' chip--group' : '') . '">' . h(target_label($lv)) . '</span>';
    }
    if (count($levels) > $max) $out .= '<span class="chip chip--more">+' . (count($levels) - $max) . '</span>';
    return $out . '</span>';
}

function rule_badge(array $rule = null)
{
    $mode = isset($rule['mode']) ? $rule['mode'] : 'inherit';
    if ($mode === 'publish') {
        $scheduled = (isset($rule['from']) && $rule['from'] !== '') || (isset($rule['until']) && $rule['until'] !== '');
        if ($scheduled && !rule_in_window($rule)) return badge('Programmé', 'warning');
        return badge('Publié', 'success');
    }
    if ($mode === 'hide') return badge('Masqué', 'error');
    return badge('Hérité', '');
}

function render_tree(array $nodes, $prefix, $selection, array $rules, array $user)
{
    echo '<ul class="tree__list">';
    foreach ($nodes as $name => $children) {
        $name = (string) $name;
        $path = $prefix === '' ? $name : $prefix . '/' . $name;
        $manage = can_manage($path, $user);
        $mode = isset($rules[$path]['mode']) ? $rules[$path]['mode'] : 'inherit';
        $selected = $selection === $path;
        $open = $selected || strpos($selection, $path . '/') === 0 || !$manage;
        $dot = $mode === 'inherit' ? '' : '<span class="tree__dot tree__dot--' . h($mode) . '" title="' . ($mode === 'publish' ? 'Publié' : 'Masqué') . '"></span>';
        $isBank = $path === TPW_BANK;
        $shared = !empty(coteachers_of($path));
        $inner = icon($isBank ? 'bank' : 'folder') . '<span class="tree__label">' . h($name) . '</span>' . $dot . ($shared ? '<span class="tree__shared" title="Partagé avec des collègues">' . icon('share') . '</span>' : '');
        $link = $manage
            ? '<a class="tree__link' . ($selected ? ' is-selected' : '') . '" href="' . h(admin_url('library', $path)) . '"' . ($selected ? ' aria-current="true"' : '') . '>' . $inner . '</a>'
            : '<span class="tree__link tree__link--muted">' . $inner . '</span>';

        echo '<li>';
        if ($children) {
            echo '<details class="tree__branch"' . ($open ? ' open' : '') . '><summary><span class="tree__toggle">' . icon('chevron') . '</span>' . $link . '</summary>';
            render_tree($children, $path, $selection, $rules, $user);
            echo '</details>';
        } else {
            echo '<div class="tree__leaf">' . $link . '</div>';
        }
        echo '</li>';
    }
    echo '</ul>';
}

/**
 * Sélecteur de classes compact : pastilles, « Mes classes » en premier, niveaux repliés, recherche.
 * $opts['only'] : limiter aux classes données (ex. classes qui voient un dossier) ;
 * $opts['mine'] : false pour ne pas afficher la ligne « Mes classes ».
 */
function level_checkboxes(array $data, array $chosen, $name = 'levels[]', array $opts = [])
{
    $only = isset($opts['only']) ? $opts['only'] : null;
    $withGroups = !isset($opts['groups']) || $opts['groups'];
    $avail = $only !== null ? array_values(array_intersect($data['levels'], $only)) : $data['levels'];
    // Groupes proposés pour chaque classe (ceux de la liste imposée, le cas échéant).
    $groupsOf = [];
    if ($withGroups) {
        foreach ($data['levels'] as $c) {
            foreach (group_names($data, $c) as $g) {
                $key = group_key($c, $g);
                if ($only === null || in_array($key, $only, true) || in_array($c, $only, true)) $groupsOf[$c][] = $key;
            }
        }
    }
    // Classes affichées : disponibles, ou dont au moins un groupe est disponible.
    $shown = array_values(array_filter($data['levels'], function ($c) use ($avail, $groupsOf) {
        return in_array($c, $avail, true) || !empty($groupsOf[$c]);
    }));
    if (!$shown) {
        echo '<p class="muted small">' . h(isset($opts['empty']) ? $opts['empty'] : 'Aucune classe configurée.') . '</p>';
        return;
    }
    $user = current_user();
    $mine = (!isset($opts['mine']) || $opts['mine']) && $user ? array_values(array_intersect($avail, $user['classes'])) : [];
    $allKeys = $avail;
    foreach ($groupsOf as $keys) $allKeys = array_merge($allKeys, $keys);
    $nClasses = count(array_intersect($avail, $chosen));
    $nGroups = count(array_intersect(array_diff($allKeys, $avail), $chosen));

    // Une classe et ses groupes, côte à côte.
    $render = function ($c, $extra = '') use ($chosen, $name, $avail, $groupsOf) {
        $out = '';
        $isClass = in_array($c, $avail, true);
        $hasGroups = !empty($groupsOf[$c]);
        if ($hasGroups) $out .= '<span class="pill-set" data-pill-set>';
        if ($isClass) {
            $out .= '<label class="pill' . $extra . '" data-pill="' . h(fold_text($c)) . '"><input type="checkbox" name="' . h($name) . '" value="' . h($c) . '"'
                . (in_array($c, $chosen, true) ? ' checked' : '') . ($hasGroups ? ' data-parent-class' : '') . '><span>' . h($c) . '</span></label>';
        } else {
            $out .= '<span class="pill-set__label">' . h($c) . '</span>';
        }
        if ($hasGroups) {
            foreach ($groupsOf[$c] as $key) {
                $g = split_target($key)[1];
                $out .= '<label class="pill pill--group" data-pill="' . h(fold_text($c . ' ' . $g)) . '" title="' . h('Seulement le groupe « ' . $g . ' » de ' . $c) . '"><input type="checkbox" name="' . h($name) . '" value="' . h($key) . '" data-sub'
                    . (in_array($key, $chosen, true) ? ' checked' : '') . '><span>' . h($g) . '</span></label>';
            }
            $out .= '</span>';
        }
        return $out;
    };
    $summary = function ($nc, $ng) {
        if (!$nc && !$ng) return 'Aucune classe sélectionnée';
        $parts = [];
        if ($nc) $parts[] = $nc . ' classe' . ($nc > 1 ? 's' : '');
        if ($ng) $parts[] = $ng . ' groupe' . ($ng > 1 ? 's' : '');
        return implode(' + ', $parts) . ($ng ? ' sélectionné' : ' sélectionnée') . ($nc + $ng > 1 ? 's' : '');
    };
    echo '<div class="picker" data-picker>';
    echo '<div class="picker__bar"><span class="picker__summary" data-picker-summary>' . h($summary($nClasses, $nGroups)) . '</span>'
        . '<span class="link-actions"><button type="button" class="link-btn" data-check="all">Tout</button><button type="button" class="link-btn" data-check="none">Aucune</button></span></div>';
    $groupsLink = function () use ($withGroups, $only, $user) {
        if (!$withGroups || $only !== null || !$user || !can('groups', $user)) return;
        echo '<p class="picker__hint">' . icon('group') . '<span>Pour ne viser qu’une partie d’une classe (groupe A/B, spécialité, tiers-temps), cochez un groupe à côté de la classe. '
            . '<a href="' . h(admin_url('groups')) . '">Créer ou modifier les groupes</a></span></p>';
    };

    // Peu de classes ou liste imposée : une simple rangée de pastilles.
    $structure = class_structure($data);
    $hasLevels = (bool) array_filter($structure, function ($i) { return $i['type'] === 'level'; });
    if ($only !== null || !$hasLevels || count($avail) <= 10) {
        echo '<div class="pills">';
        foreach ($shown as $c) echo $render($c);
        echo '</div>';
        $groupsLink();
        echo '</div>';
        return;
    }

    if ($mine) {
        echo '<div class="picker__mine"><span class="picker__label">Mes classes</span><div class="pills">';
        foreach ($mine as $c) echo $render($c, ' pill--mine');
        echo '</div></div>';
    }
    if (count($avail) > 16) {
        echo '<input type="search" class="input picker__filter" placeholder="Rechercher une classe…" data-picker-filter aria-label="Rechercher une classe">';
    }
    echo '<div class="picker__groups">';
    $singles = [];
    foreach ($structure as $item) {
        if ($item['type'] !== 'level') { if (in_array($item['name'], $shown, true)) $singles[] = $item['name']; continue; }
        $classes = array_values(array_intersect($item['classes'], $shown));
        if (!$classes) continue;
        $sel = count(array_intersect($classes, $chosen));
        $gsel = 0;
        foreach ($classes as $c) if (!empty($groupsOf[$c])) $gsel += count(array_intersect($groupsOf[$c], $chosen));
        echo '<details class="picker__group" data-level-group' . ($sel || $gsel ? ' open' : '') . '>';
        echo '<summary><span class="picker__group-name">' . h($item['name']) . '</span><span class="picker__count" data-group-count>' . ($sel ? $sel . '/' . count($classes) : count($classes)) . '</span>' . icon('chevron') . '</summary>';
        echo '<div class="pills"><label class="pill pill--all"><input type="checkbox" data-level-all><span>Tout le niveau</span></label>';
        foreach ($classes as $c) echo $render($c);
        echo '</div></details>';
    }
    if ($singles) {
        $sel = count(array_intersect($singles, $chosen));
        echo '<details class="picker__group"' . ($sel ? ' open' : '') . '><summary><span class="picker__group-name">Autres classes</span><span class="picker__count" data-group-count>' . ($sel ? $sel . '/' . count($singles) : count($singles)) . '</span>' . icon('chevron') . '</summary><div class="pills">';
        foreach ($singles as $c) echo $render($c);
        echo '</div></details>';
    }
    echo '</div>';
    $groupsLink();
    echo '</div>';
}

function view_library(array $data, array $user)
{
    $roots = managed_roots($user);
    if (!$roots) {
        ?>
        <div class="page-head"><div><h1>Bibliothèque</h1></div></div>
        <div class="empty"><?= icon('folder') ?><p><strong>Aucun dossier ne vous est encore attribué.</strong></p><p class="muted">Demandez à l’administrateur du portail de vous attribuer un dossier de cours.</p></div>
        <?php
        return;
    }

    $items = array_filter(all_items(), function ($type, $path) use ($user) {
        return can_manage((string) $path, $user);
    }, ARRAY_FILTER_USE_BOTH);
    $folders = array_values(array_filter(folder_list(), function ($f) use ($user, $roots) {
        if (can_manage($f, $user)) return true;
        foreach ($roots as $r) if (path_within($r, $f)) return true; // ancêtre d'un dossier attribué
        return false;
    }));

    $selection = query('item', is_admin($user) ? '' : $roots[0]);
    if (!isset($items[$selection])) $selection = is_admin($user) ? '' : $roots[0];
    $kind = $items[$selection];

    $rule = isset($data['rules'][$selection]) ? $data['rules'][$selection] : [];
    $mode = isset($rule['mode']) ? $rule['mode'] : 'inherit';
    $chosen = isset($rule['levels']) ? $rule['levels'] : [];
    $visible = visible_targets($selection, $data);
    $canEdit = $selection !== '' && can_manage(parent_path($selection), $user);
    $inBank = in_bank($selection);
    $isQuiz = $kind === 'file' && is_quiz_file($selection);
    $previewable = $kind === 'file' && (preview_type($selection) !== null);
    // Partage avec des collègues : réservé au titulaire du dossier (pas via un partage ni la banque).
    $owns = is_admin($user);
    foreach ($user['folders'] as $f) if (is_string($f) && $f !== '' && path_within($selection, $f)) $owns = true;
    $canCoteach = $kind === 'dir' && $selection !== '' && !$inBank && $owns && can('coteach', $user);

    $dirCount = count(array_filter($items, function ($t) { return $t === 'dir'; }));
    $publishedCount = count(array_filter($data['rules'], function ($r, $p) use ($user) {
        return isset($r['mode']) && $r['mode'] === 'publish' && can_manage((string) $p, $user);
    }, ARRAY_FILTER_USE_BOTH));
    $depositCount = count(array_filter(deposits_config(), function ($c, $p) use ($user) {
        return !empty($c['enabled']) && can_manage((string) $p, $user);
    }, ARRAY_FILTER_USE_BOTH));

    $title = $selection === '' ? 'Racine des cours' : ($kind === 'file' ? display_name($selection) : basename($selection));

    $crumbs = [];
    if (is_admin($user)) $crumbs[] = ['Racine', admin_url('library', '')];
    $walk = '';
    if ($selection !== '') {
        foreach (explode('/', $selection) as $part) {
            $walk = $walk === '' ? $part : $walk . '/' . $part;
            $crumbs[] = [$part, can_manage($walk, $user) ? admin_url('library', $walk) : null];
        }
    }
    ?>
    <div class="page-head">
      <div>
        <h1>Bibliothèque</h1>
        <p class="muted">Ajoutez vos documents, choisissez les classes qui peuvent les consulter et ouvrez le dépôt des travaux.</p>
      </div>
    </div>

    <dl class="figures">
      <div><dt>Dossiers</dt><dd><?= max(0, $dirCount - (isset($items['']) ? 1 : 0)) ?></dd></div>
      <div><dt>Documents</dt><dd><?= count($items) - $dirCount ?></dd></div>
      <div><dt>Publications</dt><dd><?= $publishedCount ?></dd></div>
      <div><dt>Dépôts ouverts</dt><dd><?= $depositCount ?></dd></div>
    </dl>

    <div class="library">
      <nav class="tree" aria-label="Arborescence des cours">
        <p class="tree__head"><?= is_admin($user) ? 'Dossier des cours' : 'Mes dossiers' ?></p>
        <?php if (is_admin($user)): ?>
          <a class="tree__link tree__link--root<?= $selection === '' ? ' is-selected' : '' ?>" href="<?= h(admin_url('library', '')) ?>"><?= icon('home') ?><span class="tree__label">Racine</span></a>
        <?php endif; ?>
        <?php if ($folders): render_tree(folder_tree($folders), '', $selection, $data['rules'], $user); else: ?>
          <p class="tree__empty">Aucun dossier pour l’instant.</p>
        <?php endif; ?>
      </nav>

      <div class="library__main">
        <?php if ($crumbs) breadcrumb($crumbs); ?>

        <section class="card item-card">
          <div class="item-head">
            <span class="item-head__icon"><?= icon($kind === 'dir' ? ($selection === '' ? 'home' : 'folder') : file_kind($selection)['icon']) ?></span>
            <div>
              <h2 class="h3"><?= h($title) ?></h2>
              <p class="muted small"><?= $kind === 'dir' ? 'Dossier' : h(file_kind($selection)['label']) ?> · <code><?= h($selection === '' ? '/' : $selection) ?></code></p>
            </div>
            <div class="item-head__badge"><?= rule_badge($rule) ?></div>
          </div>

          <?php
          $canTools = $canEdit && can('manage', $user);
          if ($kind === 'file' || $canTools || ($selection !== '' && can('upload', $user))): ?>
            <div class="item-tools">
              <?php if ($isQuiz): ?>
                <a class="btn btn--secondary btn--sm" href="<?= h(admin_url('quiz', $selection)) ?>"><?= icon('edit') ?>Modifier le QCM</a>
                <a class="btn btn--tertiary btn--sm" href="<?= h(admin_url('quiz', $selection) . '&tab=resultats') ?>"><?= icon('chart') ?>Résultats</a>
              <?php elseif ($previewable): ?>
                <a class="btn btn--tertiary btn--sm" href="present.php?item=<?= h(rawurlencode($selection)) ?>" target="_blank" rel="noopener"><?= icon('present') ?>Projeter</a>
              <?php endif; ?>
              <?php if ($kind === 'file' && !$isQuiz): ?>
                <a class="btn btn--tertiary btn--sm" href="admin.php?dl=file&amp;item=<?= h(rawurlencode($selection)) ?>"><?= icon('download') ?>Télécharger</a>
              <?php endif; ?>
              <?php if ($selection !== '' && can('upload', $user)): ?>
                <button type="button" class="btn btn--tertiary btn--sm" data-toggle="copy-form"><?= icon('copy2') ?>Copier vers…</button>
              <?php endif; ?>
              <?php if ($canTools): ?>
                <button type="button" class="btn btn--tertiary btn--sm" data-toggle="rename-form"><?= icon('edit') ?>Renommer</button>
                <button type="button" class="btn btn--tertiary btn--sm" data-toggle="move-form"><?= icon('move') ?>Déplacer</button>
                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="duplicate">
                  <input type="hidden" name="item" value="<?= h($selection) ?>">
                  <button type="submit" class="btn btn--tertiary btn--sm"><?= icon('copy') ?>Dupliquer</button>
                </form>
                <form method="post" class="inline-form" data-confirm="<?= h('Placer « ' . basename($selection) . ' »' . ($kind === 'dir' ? ' et tout son contenu' : '') . ' dans la corbeille ? Il restera restaurable pendant ' . TPW_TRASH_DAYS . ' jours.') ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="item" value="<?= h($selection) ?>">
                  <button type="submit" class="btn btn--tertiary btn--sm btn--danger"><?= icon('trash') ?>Supprimer</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($canTools): ?>
            <form method="post" class="form form--inline" id="rename-form" hidden>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="item" value="<?= h($selection) ?>">
              <div class="field">
                <label for="rename-name">Nouveau nom</label>
                <input id="rename-name" class="input" type="text" name="name" required maxlength="180" value="<?= h(basename($selection)) ?>">
              </div>
              <button class="btn btn--secondary" type="submit">Renommer</button>
            </form>
            <?php
            $destinations = array_values(array_filter(array_merge(is_admin($user) ? [''] : [], $folders), function ($f) use ($user, $selection) {
                return can_manage($f, $user) && !path_within($f, $selection) && $f !== parent_path($selection);
            })); ?>
            <form method="post" class="form form--inline" id="move-form" hidden>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="move">
              <input type="hidden" name="item" value="<?= h($selection) ?>">
              <div class="field">
                <label for="move-dest">Déplacer vers</label>
                <select id="move-dest" class="input" name="dest" required>
                  <option value="">— Choisir un dossier —</option>
                  <?php foreach ($destinations as $f): ?>
                    <option value="<?= h($f === '' ? '/' : $f) ?>"><?= $f === '' ? 'Racine des cours' : h(str_replace('/', ' › ', $f)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn--secondary" type="submit">Déplacer</button>
            </form>
          <?php endif; ?>
          <?php if ($selection !== '' && can('upload', $user)):
              $copyDest = array_values(array_filter(array_merge(is_admin($user) ? [''] : [], $folders), function ($f) use ($user, $selection) {
                  return can_manage($f, $user) && !path_within($f, $selection);
              })); ?>
            <form method="post" class="form form--inline" id="copy-form" hidden>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="copy_to">
              <input type="hidden" name="item" value="<?= h($selection) ?>">
              <div class="field">
                <label for="copy-dest">Copier vers <span class="hint">ex. : de la banque de ressources vers votre dossier de classe</span></label>
                <select id="copy-dest" class="input" name="dest" required>
                  <option value="">— Choisir un dossier —</option>
                  <?php foreach ($copyDest as $f): ?>
                    <option value="<?= h($f === '' ? '/' : $f) ?>"><?= $f === '' ? 'Racine des cours' : h(str_replace('/', ' › ', $f)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn--secondary" type="submit">Copier</button>
            </form>
          <?php endif; ?>

          <div class="share-line">
            <?= icon($visible ? 'eye' : ($inBank ? 'bank' : 'eye-off')) ?>
            <?php if ($inBank): ?>
              <span class="muted">Banque de ressources : visible uniquement par les enseignants de la matière, jamais par les élèves</span>
            <?php elseif ($visible): ?>
              <span>Visible par</span> <?= class_chips($visible, 8) ?>
            <?php else: ?>
              <span class="muted">Visible par aucune classe</span>
            <?php endif; ?>
            <?php $cot = $kind === 'dir' ? coteachers_of($selection) : []; if ($cot): $all = users_all(); ?>
              <span class="share-line__schedule"><?= icon('share') ?>Partagé avec <?= h(implode(', ', array_map(function ($l) use ($all) { return isset($all[$l]) ? $all[$l]['name'] : $l; }, $cot))) ?></span>
            <?php endif; ?>
            <?php if ($mode === 'publish' && (!empty($rule['from']) || !empty($rule['until']))): ?>
              <span class="share-line__schedule"><?= icon('clock') ?><?= !empty($rule['from']) ? 'à partir du ' . h(format_datetime($rule['from'])) : '' ?><?= !empty($rule['until']) ? ' jusqu’au ' . h(format_datetime($rule['until'])) : '' ?></span>
            <?php endif; ?>
          </div>

          <?php
          $depCfg = $kind === 'dir' ? deposit_for($selection) : null;
          $tabs = [];
          if ($kind === 'dir') $tabs['contenu'] = ['Contenu', 'folder', null];
          $tabs['partage'] = ['Partage', 'users', $visible ? (string) count($visible) : null];
          if ($kind === 'dir' && can('deposits', $user) && $selection !== '' && !$inBank) $tabs['depot'] = ['Dépôt des travaux', 'inbox', $depCfg && !empty($depCfg['enabled']) ? 'ouvert' : null];
          if ($canCoteach) $tabs['collegues'] = ['Collègues', 'share', coteachers_of($selection) ? (string) count(coteachers_of($selection)) : null];
          if ($selection !== '' && !$inBank) { $stTab = stats_for($selection); $tabs['stats'] = ['Statistiques', 'chart', $stTab['total'] ? (string) $stTab['total'] : null]; }
          if ($kind === 'file') $tabs['infos'] = ['Informations', 'info', null];
          ?>
          <nav class="tabs" data-tabs data-default="<?= $kind === 'dir' ? 'contenu' : 'partage' ?>" aria-label="Sections">
            <?php foreach ($tabs as $id => $t): ?>
              <a href="#<?= $id ?>" class="tabs__tab" data-tab="<?= $id ?>"><?= icon($t[1]) ?><span><?= h($t[0]) ?></span><?= $t[2] !== null ? '<span class="tabs__count">' . h($t[2]) . '</span>' : '' ?></a>
            <?php endforeach; ?>
          </nav>
        </section>

        <?php if ($kind === 'dir'): ?>
          <div class="tab-panel" id="contenu" data-tab-panel>
            <?php library_contents($selection, $items, $data); ?>
            <?php if (can('upload', $user) || can('folders', $user)) library_upload($selection, $user); ?>
          </div>
        <?php endif; ?>

        <div class="tab-panel" id="partage" data-tab-panel>
          <section class="card">
            <?php if ($inBank): ?>
              <p class="muted"><?= icon('bank') ?> La banque de ressources n’est jamais visible des élèves. Pour publier un document de la banque, copiez-le d’abord dans l’un de vos dossiers (bouton « Copier vers… »).</p>
            <?php elseif (!can('publish', $user)): ?>
              <p class="muted">Vous n’avez pas le droit de modifier le partage. Demandez à l’administrateur.</p>
            <?php else: ?>
              <form method="post" class="form form--tight" data-publish-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="folder" value="<?= h($selection) ?>">
                <input type="hidden" name="kind" value="<?= h($kind) ?>">
                <fieldset class="fieldset">
                  <legend>Qui peut voir <?= $kind === 'dir' ? 'ce dossier' : 'ce document' ?> ?</legend>
                  <div class="segmented segmented--modes">
                    <?php
                    $options = [
                        'publish' => ['Partager avec des classes', 'users'],
                        'inherit' => [$selection === '' ? 'Personne (par défaut)' : 'Comme le dossier parent', 'folder'],
                        'hide'    => ['Personne', 'eye-off'],
                    ];
                    foreach ($options as $value => $opt): ?>
                      <label class="segmented__opt"><input type="radio" name="mode" value="<?= $value ?>" <?= $mode === $value ? 'checked' : '' ?>><span><?= icon($opt[1]) ?><?= h($opt[0]) ?></span></label>
                    <?php endforeach; ?>
                  </div>
                </fieldset>
                <div data-levels class="publish-details">
                  <?php level_checkboxes($data, $chosen); ?>
                  <details class="schedule"<?= !empty($rule['from']) || !empty($rule['until']) ? ' open' : '' ?>>
                    <summary><?= icon('clock') ?>Programmer l’ouverture ou la fermeture <span class="muted small">(facultatif)</span></summary>
                    <div class="field-row">
                      <div class="field">
                        <label for="pub-from">Visible à partir du</label>
                        <input id="pub-from" class="input" type="datetime-local" name="from" value="<?= h(isset($rule['from']) ? $rule['from'] : '') ?>">
                      </div>
                      <div class="field">
                        <label for="pub-until">Masqué à partir du</label>
                        <input id="pub-until" class="input" type="datetime-local" name="until" value="<?= h(isset($rule['until']) ? $rule['until'] : '') ?>">
                      </div>
                    </div>
                  </details>
                </div>
                <div class="form__actions">
                  <button class="btn" type="submit">Enregistrer le partage</button>
                  <?php if ($kind === 'dir'): ?><span class="muted small">Le contenu du dossier suit ce réglage, sauf réglage propre à un élément.</span><?php endif; ?>
                </div>
              </form>
            <?php endif; ?>
          </section>
        </div>

        <?php if (isset($tabs['depot'])): ?>
          <div class="tab-panel" id="depot" data-tab-panel>
            <?php library_deposit($selection, $data); ?>
          </div>
        <?php endif; ?>

        <?php if (isset($tabs['stats'])): ?>
          <div class="tab-panel" id="stats" data-tab-panel>
            <?php library_stats($selection, $kind, $data, $stTab); ?>
          </div>
        <?php endif; ?>

        <?php if (isset($tabs['collegues'])): ?>
          <div class="tab-panel" id="collegues" data-tab-panel>
            <?php library_coteach($selection, $user); ?>
          </div>
        <?php endif; ?>

        <?php if ($kind === 'file'): $abs = full_path($selection, 'file'); ?>
          <div class="tab-panel" id="infos" data-tab-panel>
            <section class="card">
              <dl class="meta-list">
                <div><dt>Nom du fichier</dt><dd><?= h(basename($selection)) ?></dd></div>
                <div><dt>Type</dt><dd><?= h(file_kind($selection)['label']) ?></dd></div>
                <div><dt>Taille</dt><dd><?= $abs ? h(human_size(filesize($abs))) : '—' ?></dd></div>
                <div><dt>Modifié le</dt><dd><?= $abs ? h(date('d/m/Y à H:i', filemtime($abs))) : '—' ?></dd></div>
              </dl>
            </section>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (can('publish', $user)) library_copy_card($data); ?>
    <?php
}

function library_copy_card(array $data)
{
    if (count($data['levels']) < 2) return;
    ?>
    <details class="card card--details" id="copier">
      <summary><span><?= icon('copy2') ?> <strong>Copier les publications d’une classe vers d’autres</strong> <span class="muted small">— ex. : tout ce qui est publié pour 2GT1 devient aussi visible pour 2GT2 et 2GT3</span></span><?= icon('chevron') ?></summary>
      <form method="post" class="form" data-confirm="Étendre les publications à ces classes ?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="copy_publications">
        <div class="field">
          <label for="copy-src">Classe source</label>
          <select id="copy-src" class="input input--short" name="source" required>
            <option value="">— Choisir —</option>
            <?php foreach ($data['levels'] as $lv): ?><option value="<?= h($lv) ?>"><?= h($lv) ?></option><?php endforeach; ?>
          </select>
        </div>
        <fieldset class="fieldset">
          <legend>Classes de destination</legend>
          <?php level_checkboxes($data, [], 'levels[]', ['groups' => false]); ?>
        </fieldset>
        <label class="toggle"><input type="checkbox" name="with_deposits" value="1" checked><span>Ouvrir aussi les dépôts des travaux à ces classes</span></label>
        <p class="muted small">Seules les publications des dossiers que vous gérez sont copiées. Les publications existantes des classes de destination sont conservées.</p>
        <div class="form__actions"><button class="btn btn--secondary" type="submit"><?= icon('copy2') ?>Copier les publications</button></div>
      </form>
    </details>
    <?php
}

function library_contents($selection, array $items, array $data)
{
    $contents = names_in($selection);
    $entries = array_merge($contents['dirs'], $contents['files']);
    $deposits = deposits_config();
    ?>
    <section class="card card--flush">
      <div class="card__header">
        <h2 class="h4">Contenu du dossier</h2>
        <span class="muted small"><?= count($entries) ?> élément<?= count($entries) > 1 ? 's' : '' ?></span>
      </div>
      <?php if (!$entries): ?>
        <p class="card__empty">Ce dossier est vide. Utilisez le formulaire ci-dessous pour ajouter des documents.</p>
      <?php else: ?>
        <ul class="rows">
          <?php foreach ($entries as $p):
              $isDir = isset($items[$p]) && $items[$p] === 'dir';
              $k = $isDir ? null : file_kind($p);
              $r = isset($data['rules'][$p]) ? $data['rules'][$p] : null;
              $vis = visible_targets($p, $data);
              $abs = $isDir ? false : full_path($p, 'file'); ?>
            <li>
              <a class="row" href="<?= h(admin_url('library', $p)) ?>">
                <span class="row__icon"><?= icon($isDir ? 'folder' : $k['icon']) ?></span>
                <span class="row__body">
                  <span class="row__title"><?= h(basename($p)) ?></span>
                  <span class="row__meta">
                    <?= $isDir ? 'Dossier' : h($k['label']) . ($abs ? ' · ' . h(human_size(filesize($abs))) : '') ?>
                    · <?= $vis ? class_chips($vis, 3) : '<span class="muted">' . (in_bank($p) ? 'banque' : 'non visible') . '</span>' ?>
                    <?php if ($isDir && !empty($deposits[$p]['enabled'])): ?> · dépôt ouvert<?php endif; ?>
                  </span>
                </span>
                <?= rule_badge($r) ?>
                <span class="row__chevron"><?= icon('chevron') ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php
}

function library_upload($selection, array $user)
{
    $up = can('upload', $user);
    $mk = can('folders', $user);
    ?>
    <section class="card">
      <h2 class="h4">Ajouter au dossier</h2>
      <div class="<?= $up ? 'split' : '' ?>">
        <?php if ($up): ?>
        <form method="post" enctype="multipart/form-data" class="form" data-dropzone>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="folder" value="<?= h($selection) ?>">
          <label class="dropzone">
            <input type="file" name="files[]" multiple required>
            <?= icon('upload') ?>
            <strong>Déposer des fichiers ici</strong>
            <span>ou cliquer pour parcourir — <?= h(human_size(upload_limit())) ?> maximum par envoi</span>
            <span class="dropzone__list" data-file-list></span>
          </label>
          <div class="form__actions"><button class="btn" type="submit"><?= icon('upload') ?>Envoyer les fichiers</button></div>
        </form>
        <?php endif; ?>
        <div class="stack">
          <?php if ($mk): ?>
          <form method="post" class="form form--compact">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mkdir">
            <input type="hidden" name="folder" value="<?= h($selection) ?>">
            <div class="field">
              <label for="mkdir-name">Nouveau sous-dossier</label>
              <div class="input-group">
                <input id="mkdir-name" class="input" type="text" name="name" required maxlength="180" placeholder="ex. : Chapitre 2 - Énergie">
                <button class="btn btn--secondary" type="submit" title="Créer le dossier"><?= icon('folder') ?><span class="sr-only">Créer le dossier</span></button>
              </div>
            </div>
          </form>
          <?php endif; ?>
          <?php if ($up): ?>
          <form method="post" class="form form--compact">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_link">
            <input type="hidden" name="folder" value="<?= h($selection) ?>">
            <div class="field">
              <label for="link-url">Lien vers un site ou une vidéo <span class="hint">YouTube, Capytale, Genially, site web…</span></label>
              <input id="link-url" class="input" type="url" name="url" required maxlength="2000" placeholder="https://">
            </div>
            <div class="field">
              <label for="link-title">Titre affiché</label>
              <div class="input-group">
                <input id="link-title" class="input" type="text" name="title" required maxlength="150" placeholder="ex. : Vidéo — les forces">
                <button class="btn btn--secondary" type="submit" title="Ajouter le lien"><?= icon('link') ?><span class="sr-only">Ajouter le lien</span></button>
              </div>
            </div>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <p class="muted small">Un fichier portant le même nom qu’un fichier existant est renommé automatiquement (ex. : « TP1 (2).pdf »). Les cours HTML peuvent être envoyés avec leurs images et feuilles de style. Les vidéos YouTube s’affichent directement dans le portail. Les documents Word, PowerPoint et tableurs s’affichent en aperçu<?= soffice_path() === '' ? ' simplifié (installez LibreOffice sur le serveur pour un aperçu fidèle)' : '' ?>.</p>
      <?php if ($up): ?>
        <div class="split split--tools">
          <details class="schedule">
            <summary><?= icon('quiz') ?>Créer un QCM auto-corrigé</summary>
            <form method="post" class="form form--compact">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="quiz_create">
              <input type="hidden" name="folder" value="<?= h($selection) ?>">
              <div class="field">
                <label for="quiz-title">Titre du QCM</label>
                <div class="input-group">
                  <input id="quiz-title" class="input" type="text" name="title" required maxlength="150" placeholder="ex. : QCM — Les forces">
                  <button class="btn btn--secondary" type="submit">Créer</button>
                </div>
              </div>
              <p class="muted small">Le QCM se publie comme un document. Les élèves obtiennent leur note immédiatement ; vous retrouvez les résultats par classe.</p>
            </form>
          </details>
          <details class="schedule">
            <summary><?= icon('import') ?>Importer une arborescence (.zip)</summary>
            <form method="post" enctype="multipart/form-data" class="form form--compact" data-confirm="Importer cette archive dans « <?= h($selection === '' ? 'Racine' : basename($selection)) ?> » ?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="import_zip">
              <input type="hidden" name="folder" value="<?= h($selection) ?>">
              <div class="field">
                <label for="import-zip">Archive .zip <span class="hint">dossiers et fichiers recréés à l’identique</span></label>
                <input id="import-zip" class="input" type="file" name="archive" accept=".zip" required>
              </div>
              <p class="muted small">Pour reprendre vos cours de TP Works, d’une clé USB ou d’un partage réseau : compressez le dossier (clic droit › Compresser), puis importez-le ici. Les fichiers exécutables sont ignorés.</p>
              <div class="form__actions"><button class="btn btn--secondary btn--sm" type="submit"><?= icon('import') ?>Importer</button></div>
            </form>
          </details>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function library_deposit($selection, array $data)
{
    $cfg = deposit_for($selection);
    $enabled = $cfg && !empty($cfg['enabled']);
    if (!$cfg) $cfg = deposit_normalize(['levels' => []]);
    $count = $cfg['id'] !== '' ? count(deposit_entries($cfg)) : 0;
    $visible = visible_targets($selection, $data);
    $customised = $cfg['exts'] !== DEPOSIT_DEFAULT_EXTS || (int) $cfg['max_mb'] !== 20 || $cfg['note'] !== '';
    ?>
    <section class="card">
      <div class="card__title-row">
        <h2 class="h4"><?= icon('inbox') ?> Dépôt des travaux des élèves</h2>
        <?= $enabled ? deposit_state_badge($cfg) : badge('Fermé', '') ?>
      </div>
      <?php if ($count): ?>
        <p><a class="btn btn--secondary btn--sm" href="<?= h(admin_url('deposits', $selection)) ?>"><?= icon('inbox') ?><?= $count ?> rendu<?= $count > 1 ? 's' : '' ?> reçu<?= $count > 1 ? 's' : '' ?> — voir</a></p>
      <?php endif; ?>
      <form method="post" class="form form--tight" data-deposit-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="deposit_save">
        <input type="hidden" name="folder" value="<?= h($selection) ?>">
        <label class="toggle">
          <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> data-deposit-toggle>
          <span>Ouvrir un dépôt « Rendre mon travail » dans ce dossier</span>
        </label>
        <div class="publish-details" data-deposit-fields>
          <fieldset class="fieldset">
            <legend>Pour quelles classes ? <span class="hint">Seules les classes qui voient ce dossier sont proposées. Les autres ne verront pas le dépôt.</span></legend>
            <?php level_checkboxes($data, $cfg['levels'], 'levels[]', ['only' => $visible, 'mine' => false,
                'empty' => 'Ce dossier n’est partagé avec aucune classe : partagez-le d’abord (onglet Partage).']); ?>
          </fieldset>
          <div class="field-row">
            <div class="field">
              <label for="dep-until">Date limite <span class="hint">vide = sans limite</span></label>
              <input id="dep-until" class="input" type="datetime-local" name="until" value="<?= h($cfg['until']) ?>">
            </div>
            <div class="field">
              <label for="dep-from">Ouverture <span class="hint">vide = immédiatement</span></label>
              <input id="dep-from" class="input" type="datetime-local" name="from" value="<?= h($cfg['from']) ?>">
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label for="dep-late">Après la date limite</label>
              <select id="dep-late" class="input" name="late" data-late-select>
                <option value="refuse"<?= $cfg['late'] === 'refuse' ? ' selected' : '' ?>>Fermer le dépôt</option>
                <option value="accept"<?= $cfg['late'] === 'accept' ? ' selected' : '' ?>>Accepter les retards (marqués « en retard »)</option>
              </select>
            </div>
            <div class="field" data-late-until>
              <label for="dep-late-until">Retards acceptés jusqu’au <span class="hint">vide = sans limite</span></label>
              <input id="dep-late-until" class="input" type="datetime-local" name="late_until" value="<?= h($cfg['late_until']) ?>">
            </div>
          </div>
          <?php
          // Corrigé : un fichier ou sous-dossier du dossier, publié automatiquement à la fermeture du dépôt.
          $candidates = [];
          $walkSol = function ($rel, $depth) use (&$walkSol, &$candidates, $selection) {
              if ($depth > 2) return;
              $list = names_in($rel);
              foreach (array_merge($list['dirs'], $list['files']) as $p) {
                  if (is_asset_file($p)) continue;
                  $candidates[substr($p, strlen($selection) + 1)] = full_path($p, 'dir') ? 'dir' : 'file';
                  if (full_path($p, 'dir')) $walkSol($p, $depth + 1);
              }
          };
          $walkSol($selection, 0);
          $release = solution_release_time($cfg);
          ?>
          <div class="field">
            <label for="dep-solution">Corrigé publié automatiquement à la fermeture <span class="hint">facultatif — le document reste caché aux élèves jusque-là</span></label>
            <select id="dep-solution" class="input" name="solution">
              <option value="">Aucun corrigé</option>
              <?php foreach ($candidates as $rel => $type): ?>
                <option value="<?= h($rel) ?>"<?= $cfg['solution'] === $rel ? ' selected' : '' ?>><?= $type === 'dir' ? '📁 ' : '' ?><?= h(str_replace('/', ' › ', $rel)) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($cfg['solution'] !== ''): ?>
              <span class="hint"><?= $release ? ($release > time() ? 'Publication prévue le ' . h(format_datetime($release)) . '.' : 'Corrigé publié (dépôt clos).') : ($cfg['until'] !== '' && $cfg['late'] === 'accept' ? 'Les retards sont acceptés sans limite : fixez « Retards acceptés jusqu’au » pour que le corrigé soit publié.' : 'Indiquez une date limite : sans fin de dépôt, le corrigé reste caché.') ?></span>
            <?php endif; ?>
          </div>
          <div class="field">
            <label for="dep-group">Travail rendu</label>
            <select id="dep-group" class="input input--short" name="group_max">
              <?php foreach ([1 => 'Individuellement', 2 => 'En binôme (2 élèves)', 3 => 'En groupe de 3', 4 => 'En groupe de 4', 5 => 'En groupe de 5'] as $n => $lab): ?>
                <option value="<?= $n ?>"<?= (int) $cfg['group_max'] === $n ? ' selected' : '' ?>><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <details class="schedule"<?= $customised ? ' open' : '' ?>>
            <summary><?= icon('settings') ?>Consigne, formats et taille <span class="muted small">(facultatif)</span></summary>
            <div class="field">
              <label for="dep-note">Consigne affichée aux élèves</label>
              <textarea id="dep-note" class="input input--text" name="note" rows="2" maxlength="600" placeholder="ex. : Déposez votre compte rendu au format PDF, nommé TP3_NOM.pdf"><?= h($cfg['note']) ?></textarea>
            </div>
            <div class="field-row">
              <div class="field">
                <label for="dep-exts">Formats acceptés <span class="hint">extensions séparées par des virgules</span></label>
                <input id="dep-exts" class="input" type="text" name="exts" value="<?= h($cfg['exts']) ?>">
              </div>
              <div class="field">
                <label for="dep-max">Taille max. par fichier (Mo) <span class="hint">serveur : <?= h(human_size(upload_limit())) ?></span></label>
                <input id="dep-max" class="input" type="number" name="max_mb" min="1" max="500" value="<?= (int) $cfg['max_mb'] ?>">
              </div>
            </div>
          </details>
        </div>
        <div class="form__actions"><button class="btn" type="submit">Enregistrer le dépôt</button></div>
      </form>
    </section>
    <?php
}

function deposit_state_badge(array $cfg)
{
    if (empty($cfg['enabled'])) return badge('Désactivé', '');
    $state = deposit_state($cfg);
    if ($state === 'scheduled') return badge('Programmé', 'warning');
    if ($state === 'late') return badge('Retards acceptés', 'warning');
    if ($state === 'closed') return badge('Clos', 'error');
    return badge('Ouvert', 'success');
}

/** Onglet « Collègues » : co-enseignement d'un dossier. */
function library_coteach($selection, array $user)
{
    $all = users_all();
    $options = [];
    foreach ($all as $login => $u) {
        $login = (string) $login;
        if ($login === $user['login'] || $u['role'] === 'admin') continue;
        $options[$login] = $u['name'] . ($u['subject'] !== '' ? ' — ' . $u['subject'] : '');
    }
    $chosen = coteachers_of($selection);
    ?>
    <section class="card">
      <h2 class="h4"><?= icon('share') ?> Co-enseignement</h2>
      <p class="muted">Les collègues choisis voient ce dossier dans leur bibliothèque et peuvent ajouter, modifier et publier son contenu, selon leurs propres droits. Utile pour un enseignement partagé (AP, EMC, sciences, projet…).</p>
      <form method="post" class="form form--tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="coteach_save">
        <input type="hidden" name="folder" value="<?= h($selection) ?>">
        <?php value_picker('coteach[]', $options, $chosen, [
            'zero' => 'Dossier non partagé', 'one' => 'Partagé avec 1 collègue', 'many' => 'Partagé avec {n} collègues',
            'none' => 'Aucun autre compte enseignant.', 'search' => 'Rechercher un collègue…',
        ]); ?>
        <div class="form__actions"><button class="btn" type="submit">Enregistrer</button></div>
      </form>
    </section>
    <?php
}

/** Onglet « Statistiques » : consultations par les élèves (12 derniers mois). */
function library_stats($selection, $kind, array $data, array $st)
{
    $roster = [];
    $visible = visible_targets($selection, $data);
    foreach (students_all() as $id => $s) {
        $ok = in_array($s['class'], $visible, true);
        foreach ($s['groups'] as $g) if (in_array(group_key($s['class'], $g), $visible, true)) $ok = true;
        if ($ok) $roster[(string) $id] = $s;
    }
    $seen = array_intersect_key($roster, $st['students']);
    $notSeen = array_diff_key($roster, $st['students']);
    ?>
    <section class="card">
      <h2 class="h4"><?= icon('chart') ?> Consultations par les élèves</h2>
      <?php if (!$st['total']): ?>
        <p class="muted">Aucune consultation pour le moment<?= $visible ? '' : ' — cet élément n’est visible par aucune classe' ?>.</p>
      <?php else: ?>
        <dl class="figures figures--small">
          <div><dt>Ouvertures</dt><dd><?= (int) $st['total'] ?></dd></div>
          <div><dt>7 derniers jours</dt><dd><?= (int) $st['recent'] ?></dd></div>
          <div><dt>Dernière</dt><dd class="small-dd"><?= h(date('d/m à H:i', $st['last'])) ?></dd></div>
          <?php if ($roster): ?><div><dt>Élèves connectés</dt><dd><?= count($seen) ?><small> / <?= count($roster) ?></small></dd></div><?php endif; ?>
        </dl>
        <div class="split">
          <div>
            <h3 class="h5">Par classe</h3>
            <?php $max = max($st['by_class']); ?>
            <ul class="bars bars--compact">
              <?php foreach ($st['by_class'] as $c => $n): ?>
                <li><span class="bars__label"><?= h($c) ?></span><span class="meter"><span style="width:<?= (int) round($n / $max * 100) ?>%"></span></span><span class="bars__value"><?= (int) $n ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php if ($kind === 'dir' && count($st['by_item']) > 1): ?>
            <div>
              <h3 class="h5">Les plus consultés</h3>
              <ol class="top-list">
                <?php foreach (array_slice($st['by_item'], 0, 8, true) as $p => $n): ?>
                  <li><a href="<?= h(admin_url('library', (string) $p)) ?>"><?= h(display_name((string) $p)) ?></a> <span class="muted small"><?= (int) $n ?></span></li>
                <?php endforeach; ?>
              </ol>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($roster && $notSeen && $kind === 'file'): ?>
        <details class="schedule">
          <summary><?= icon('users') ?><?= count($notSeen) ?> élève<?= count($notSeen) > 1 ? 's' : '' ?> connecté<?= count($notSeen) > 1 ? 's' : '' ?> par l’ENT ne l’<?= count($notSeen) > 1 ? 'ont' : 'a' ?> pas encore ouvert</summary>
          <p class="not-seen"><?php $names = []; foreach ($notSeen as $s) $names[] = h(student_name($s)) . ' <span class="muted small">' . h($s['class']) . '</span>'; echo implode(' · ', $names); ?></p>
        </details>
      <?php endif; ?>
      <p class="muted small">Comptées une fois par élève et par demi-heure ; les consultations des enseignants ne comptent pas. Les élèves sont identifiés seulement s’ils sont connectés par l’ENT. Données conservées 12 mois.</p>
    </section>
    <?php
}
