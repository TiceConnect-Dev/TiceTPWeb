<?php
/**
 * Espace élèves : choix de la classe, code éventuel, consultation, recherche et dépôt des travaux.
 */

require_once __DIR__ . '/inc/bootstrap.php';

$data  = settings();
$level = query('level');
$dir   = query('dir');
$q     = trim(query('q'));
$niveau = query('niveau');
$accessError = '';

if ($level !== '' && !level_ok($level, $data)) {
    fail(404, 'Classe introuvable', 'Cette classe n’existe pas ou a été renommée par votre professeur.');
}

/* =====================================================================
   Formulaires
   ===================================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');

    if ($action === 'class_login') {
        $requested = post('level');
        if (!level_ok($requested, $data)) fail(404, 'Classe introuvable', 'Cette classe n’existe pas ou a été renommée.');
        if (validate_class_code($requested, post('code'))) redirect(level_url($requested));
        $level = $requested;
        $dir = '';
        $accessError = class_limit_message($level);
    }

    if ($action === 'set_groups') {
        $level = post('level');
        if (!level_ok($level, $data) || !class_allowed($level)) redirect(level_url($level));
        $wanted = isset($_POST['groups']) && is_array($_POST['groups']) ? $_POST['groups'] : [];
        $codes = isset($_POST['gcode']) && is_array($_POST['gcode']) ? $_POST['gcode'] : [];
        $kept = [];
        $refused = [];
        foreach (class_groups($data, $level) as $i => $g) {
            if (!in_array($g['name'], $wanted, true)) continue;
            $already = in_array($g['name'], viewer_groups($level, $data), true);
            if ($already || group_code_ok($g, isset($codes[$i]) ? $codes[$i] : '')) $kept[] = $g['name'];
            else $refused[] = $g['name'];
        }
        $_SESSION['groups'][$level] = $kept;
        $_SESSION['groups_flash'] = $refused ? 'Code incorrect pour : ' . implode(', ', $refused) . '. Demandez-le à votre professeur.' : '';
        redirect(level_url($level, post('dir')));
    }

    if ($action === 'deposit') {
        $level = post('level');
        $dir = post('dir');
        if (!level_ok($level, $data) || !class_allowed($level)) redirect(level_url($level));
        $cfg = $dir !== '' ? deposit_for($dir) : null;
        if (!deposit_for_viewer($cfg, $level) || !full_path($dir, 'dir') || !can_browse($dir, $level, $data)) {
            fail(404, 'Dépôt indisponible', 'Ce dépôt n’existe pas ou n’est pas ouvert pour votre classe.');
        }
        $last = clean_person_name(post('last'));
        $first = clean_person_name(post('first'));
        $files = uploaded_files('files');
        $errors = [];
        // Coéquipiers (rendu en binôme ou en groupe).
        $members = [];
        $mLast = isset($_POST['m_last']) && is_array($_POST['m_last']) ? $_POST['m_last'] : [];
        $mFirst = isset($_POST['m_first']) && is_array($_POST['m_first']) ? $_POST['m_first'] : [];
        for ($i = 0; $i < $cfg['group_max'] - 1; $i++) {
            $ml = isset($mLast[$i]) && is_string($mLast[$i]) ? trim($mLast[$i]) : '';
            $mf = isset($mFirst[$i]) && is_string($mFirst[$i]) ? trim($mFirst[$i]) : '';
            if ($ml === '' && $mf === '') continue;
            $cl = clean_person_name($ml);
            $cf = clean_person_name($mf);
            if ($cl === '' || $cf === '') { $errors[] = 'Nom ou prénom de coéquipier invalide (lettres uniquement).'; break; }
            $members[] = ['last' => $cl, 'first' => $cf];
        }
        if ($errors) {
            // erreur déjà signalée
        } elseif (!deposit_accepting($cfg)) $errors[] = 'Le dépôt est fermé.';
        elseif ($last === '' || $first === '') $errors[] = 'Indiquez votre nom et votre prénom (lettres uniquement).';
        elseif (!$files) $errors[] = 'Choisissez au moins un fichier.';
        else {
            $result = deposit_store($cfg, $level, $last, $first, $files, $members);
            $errors = $result['errors'];
            if ($result['ok']) {
                audit_log('student_deposit', ['classe' => $level, 'dossier' => $dir, 'fichiers' => count($result['ok']), 'reçu' => $result['receipt']], 'élève');
                $who = $first . ' ' . mb_strtoupper($last);
                foreach ($members as $m) $who .= ', ' . $m['first'] . ' ' . mb_strtoupper($m['last']);
                $_SESSION['receipts'][$dir][] = ['name' => $who, 'files' => $result['ok'], 'time' => time(),
                    'receipt' => $result['receipt'], 'late' => !empty($result['late']), 'level' => $level, 'folder' => basename($dir)];
            }
        }
        $_SESSION['deposit_flash'] = ['ok' => isset($result) ? $result['ok'] : [], 'errors' => $errors, 'last' => $last, 'first' => $first,
                                      'receipt' => isset($result['receipt']) ? $result['receipt'] : '', 'late' => !empty($result['late']), 'members' => $members];
        redirect(level_url($level, $dir) . '#rendre');
    }
}

/* =====================================================================
   Code de classe
   ===================================================================== */

if ($level !== '' && !class_allowed($level)) {
    release_session();
    page_top('Accès à la classe ' . $level);
    ?>
    <div class="container container--narrow section">
      <?php breadcrumb(class_crumbs($level, $data, false)); ?>
      <section class="card card--form">
        <div class="card__icon"><?= icon('lock') ?></div>
        <h1 class="h2">Accès protégé — <?= h($level) ?></h1>
        <p>Saisissez le code communiqué par votre professeur pour consulter les ressources de cette classe.</p>
        <?php if ($accessError !== '') alert('error', $accessError); ?>
        <form method="post" action="<?= h(level_url($level)) ?>" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="class_login">
          <input type="hidden" name="level" value="<?= h($level) ?>">
          <div class="field">
            <label for="code">Code de classe <span class="hint">4 caractères, lettres et chiffres</span></label>
            <input id="code" class="input input--code" name="code" type="password" required maxlength="4" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus>
          </div>
          <div class="form__actions">
            <button class="btn" type="submit">Accéder aux ressources</button>
            <a class="btn btn--secondary" href="<?= h(change_class_url($level, $data)) ?>">Changer de classe</a>
          </div>
        </form>
      </section>
    </div>
    <?php
    page_end();
    exit;
}

/* =====================================================================
   Page d'un niveau : choix de la classe
   ===================================================================== */

if ($level === '' && $niveau !== '') {
    $map = class_levels_map($data);
    if (!isset($map[$niveau])) fail(404, 'Niveau introuvable', 'Ce niveau n’existe pas ou a été renommé.');
    release_session();
    $codes = class_access_data();
    page_top($niveau);
    ?>
    <div class="container section">
      <?php breadcrumb([['Accueil', 'index.php'], [$niveau, null]]); ?>
      <div class="page-head">
        <div>
          <p class="page-head__kicker"><?= badge('Niveau', 'info') ?></p>
          <h1><?= h($niveau) ?></h1>
          <p class="muted">Choisissez votre classe.</p>
        </div>
        <div class="page-head__actions">
          <a class="btn btn--secondary btn--sm" href="index.php"><?= icon('back') ?>Tous les niveaux</a>
        </div>
      </div>
      <ul class="tiles tiles--levels">
        <?php foreach ($map[$niveau] as $lvl) class_tile($lvl, $data, $codes); ?>
      </ul>
    </div>
    <?php
    page_end();
    exit;
}

/* =====================================================================
   Accueil : choix du niveau (ou de la classe)
   ===================================================================== */

if ($level === '') {
    release_session();
    $codes = class_access_data();
    $structure = class_structure($data);
    $niveaux = array_values(array_filter($structure, function ($i) { return $i['type'] === 'level'; }));
    $singles = array_values(array_filter($structure, function ($i) { return $i['type'] === 'class'; }));
    page_top('Accueil');
    ?>
    <section class="hero">
      <div class="container">
        <p class="hero__kicker">Portail pédagogique de l’établissement</p>
        <h1>Cours et travaux pratiques</h1>
        <p class="lead">Retrouvez les cours, sujets de TP et documents publiés par vos professeurs, et rendez vos travaux en ligne. Choisissez votre <?= $niveaux ? 'niveau' : 'classe' ?> pour commencer.</p>
      </div>
    </section>

    <div class="container section">
      <?php if (!$data['levels']): ?>
        <div class="empty"><?= icon('info') ?><p>Aucune classe n’est encore configurée.</p></div>
      <?php endif; ?>

      <?php if ($niveaux): ?>
        <div class="section__head">
          <h2>Choisir mon niveau</h2>
          <p class="muted"><?= count($niveaux) ?> niveau<?= count($niveaux) > 1 ? 'x' : '' ?></p>
        </div>
        <ul class="tiles tiles--levels">
          <?php foreach ($niveaux as $n):
              $examCount = count(array_filter($n['classes'], function ($c) use ($data) { return exam_folders_for($c, $data) !== null; })); ?>
            <li>
              <a class="tile tile--level tile--niveau" href="<?= h(niveau_url($n['name'])) ?>">
                <span class="tile__title"><?= h($n['name']) ?></span>
                <span class="tile__meta">
                  <?php if ($examCount): ?><?= icon('exam') ?>Examen en cours<?php else: ?><?= count($n['classes']) ?> classe<?= count($n['classes']) > 1 ? 's' : '' ?><?php endif; ?>
                </span>
                <span class="tile__arrow"><?= icon('arrow') ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($singles): ?>
        <div class="section__head<?= $niveaux ? ' section__head--spaced' : '' ?>">
          <h2><?= $niveaux ? 'Autres classes' : 'Choisir ma classe' ?></h2>
          <p class="muted"><?= count($singles) ?> classe<?= count($singles) > 1 ? 's' : '' ?></p>
        </div>
        <ul class="tiles tiles--levels">
          <?php foreach ($singles as $c) class_tile($c['name'], $data, $codes); ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    exit;
}

/* =====================================================================
   Classe : dossier, recherche, dépôt
   ===================================================================== */

if (!valid_path($dir) || ($dir !== '' && !full_path($dir, 'dir'))) {
    fail(404, 'Dossier introuvable', 'Ce dossier n’existe pas ou a été déplacé.');
}
if ($dir !== '' && !can_browse($dir, $level, $data)) {
    fail(404, 'Dossier non disponible', 'Ce dossier n’est pas publié pour votre classe.');
}

$depositFlash = isset($_SESSION['deposit_flash']) ? $_SESSION['deposit_flash'] : null;
unset($_SESSION['deposit_flash']);
$groupFlash = isset($_SESSION['groups_flash']) ? (string) $_SESSION['groups_flash'] : '';
unset($_SESSION['groups_flash']);
$todoPage = query('todo') === '1';
$receipts = isset($_SESSION['receipts'][$dir]) ? $_SESSION['receipts'][$dir] : [];
release_session();

$inlineTypes = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'mp4', 'webm', 'mp3', 'wav'];
$examFolders = exam_folders_for($level, $data);
$examNow = exam_for_viewer($level, $data);

/** Ligne de document (aperçu intégré si possible, sinon téléchargement). */
function doc_item($level, $sub, $withPath = false, array $data = null, $classHome = '')
{
    if ($data === null) $data = settings();
    $new = is_new($sub, $data);
    $kind = file_kind($sub);
    $abs = full_path($sub, 'file');
    $preview = preview_type($sub);
    $isLink = file_ext($sub) === 'url';
    $url = $isLink && $abs ? read_url_file($abs) : '';
    $external = $isLink && $preview === null;

    if ($preview === 'quiz') $href = 'quiz.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($sub);
    elseif ($preview) $href = 'preview.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($sub);
    elseif ($isLink) $href = file_url($level, $sub);
    else $href = file_url($level, $sub) . '&dl=1';

    if ($isLink) {
        $meta = $preview === 'youtube' ? 'Vidéo YouTube' : 'Lien · ' . ($url !== '' ? url_host($url) : 'adresse invalide');
    } elseif ($preview === 'quiz') {
        $qz = $abs ? quiz_load($abs) : null;
        $meta = 'QCM · ' . ($qz ? count($qz['questions']) : 0) . ' question' . ($qz && count($qz['questions']) > 1 ? 's' : '') . ' · correction automatique';
    } else {
        $meta = $kind['label'];
        if ($kind['ext'] !== '' && $preview !== 'html' && $kind['ext'] !== $kind['label']) $meta .= ' · ' . $kind['ext'];
        if ($abs) $meta .= ' · ' . human_size(filesize($abs));
    }
    if ($withPath) {
        $where = parent_dir($sub);
        if ($classHome !== '' && path_within($where, $classHome)) $where = ltrim(substr($where, strlen($classHome)), '/');
        $meta .= ' · ' . ($where === '' ? 'Accueil de la classe' : str_replace('/', ' › ', $where));
    }

    if ($external) $action = 'Ouvrir le lien';
    elseif ($preview === 'html') $action = 'Ouvrir le cours';
    elseif ($preview === 'youtube') $action = 'Regarder';
    elseif ($preview === 'quiz') $action = 'Répondre';
    elseif ($preview) $action = 'Consulter';
    else $action = 'Télécharger';
    $actionIcon = $external ? 'external' : ($preview ? 'arrow' : 'download');
    $iconName = $preview === 'youtube' ? 'video' : $kind['icon'];
    ?>
    <li>
      <a class="doc" href="<?= h($href) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
        <span class="doc__icon doc__icon--<?= h($iconName) ?>"><?= icon($iconName) ?></span>
        <span class="doc__body">
          <span class="doc__title"><?= h(display_name($sub)) ?><?= $new ? ' ' . badge('Nouveau', 'new') : '' ?></span>
          <span class="doc__meta"><?= h($meta) ?></span>
        </span>
        <span class="doc__action"><span><?= $action ?></span><?= icon($actionIcon) ?></span>
      </a>
    </li>
    <?php
}

function parent_dir($path)
{
    $p = dirname($path);
    return $p === '.' ? '' : $p;
}

/** Travaux à rendre pour l'élève (dépôts ouverts, en retard autorisé ou programmés), par échéance. */
function student_todo($level, array $data)
{
    $out = [];
    foreach (deposits_config() as $path => $cfg) {
        $path = (string) $path;
        $cfg = deposit_normalize($cfg);
        if (!deposit_for_viewer($cfg, $level) || !full_path($path, 'dir') || !can_browse($path, $level, $data)) continue;
        $state = deposit_state($cfg);
        if ($state === 'closed') continue;
        $out[] = ['path' => $path, 'cfg' => $cfg, 'state' => $state, 'until' => parse_datetime($cfg['until'])];
    }
    usort($out, function ($a, $b) {
        $ua = $a['until'] === null ? PHP_INT_MAX : $a['until'];
        $ub = $b['until'] === null ? PHP_INT_MAX : $b['until'];
        return $ua === $ub ? strnatcasecmp($a['path'], $b['path']) : ($ua < $ub ? -1 : 1);
    });
    return $out;
}

/** Ligne de l'échéancier. */
function todo_item($level, array $t, $classHome)
{
    $where = parent_dir($t['path']);
    if ($classHome !== '' && path_within($where, $classHome)) $where = ltrim(substr($where, strlen($classHome)), '/');
    $cfg = $t['cfg'];
    ?>
    <li>
      <a class="todo" href="<?= h(level_url($level, $t['path'])) ?>#rendre">
        <span class="todo__icon todo__icon--<?= h($t['state']) ?>"><?= icon($t['state'] === 'scheduled' ? 'clock' : 'inbox') ?></span>
        <span class="todo__body">
          <span class="todo__title"><?= h(basename($t['path'])) ?></span>
          <span class="todo__meta"><?= h($where === '' ? 'Accueil de la classe' : str_replace('/', ' › ', $where)) ?></span>
        </span>
        <span class="todo__when">
          <?php if ($t['state'] === 'scheduled'): ?>
            <span class="badge badge--warning">Ouvre le <?= h(format_datetime($cfg['from'])) ?></span>
          <?php elseif ($t['state'] === 'late'): ?>
            <span class="badge badge--error">En retard</span><span class="muted small">Retards acceptés<?= $cfg['late_until'] !== '' ? ' jusqu’au ' . h(format_datetime($cfg['late_until'])) : '' ?></span>
          <?php elseif ($t['until'] !== null): ?>
            <strong data-countdown="<?= (int) $t['until'] ?>"><?= h(format_datetime($cfg['until'])) ?></strong>
            <span class="muted small">avant le <?= h(format_datetime($cfg['until'])) ?></span>
          <?php else: ?>
            <span class="muted small">Sans date limite</span>
          <?php endif; ?>
        </span>
      </a>
    </li>
    <?php
}

/** Bandeau « Mon groupe » (classe avec groupes). */
function render_group_bar($level, $dir, array $data, $flash)
{
    $groups = class_groups($data, $level);
    if (!$groups) return;
    $mine = viewer_groups($level, $data);
    ?>
    <details class="group-bar"<?= $flash !== "" ? " open" : "" ?>>
      <summary>
        <?= icon('group') ?>
        <span><?= $mine ? 'Mon groupe : <strong>' . h(implode(', ', $mine)) . '</strong>' : 'Choisissez votre groupe pour voir toutes vos ressources' ?></span>
        <span class="group-bar__edit"><?= $mine ? 'Modifier' : 'Choisir' ?></span>
      </summary>
      <form method="post" class="form form--tight" action="<?= h(level_url($level, $dir)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_groups">
        <input type="hidden" name="level" value="<?= h($level) ?>">
        <input type="hidden" name="dir" value="<?= h($dir) ?>">
        <?php if ($flash !== '') alert('error', $flash); ?>
        <div class="pills">
          <?php foreach ($groups as $i => $g): $on = in_array($g['name'], $mine, true); ?>
            <span class="group-choice">
              <label class="pill"><input type="checkbox" name="groups[]" value="<?= h($g['name']) ?>"<?= $on ? ' checked' : '' ?><?= $g['hash'] !== '' && !$on ? ' data-needs-code="gc-' . $i . '"' : '' ?>><span><?= $g['hash'] !== '' ? icon('lock') : '' ?><?= h($g['name']) ?></span></label>
              <?php if ($g['hash'] !== '' && !$on): ?>
                <input id="gc-<?= $i ?>" class="input input--code input--mini" type="password" name="gcode[<?= $i ?>]" maxlength="4" placeholder="Code" autocomplete="off" hidden aria-label="Code du groupe <?= h($g['name']) ?>">
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
        <p class="muted small">Cochez le ou les groupes dont vous faites partie (indiqués par votre professeur). Ce choix est oublié à la fermeture du navigateur.</p>
        <div class="form__actions"><button class="btn btn--secondary btn--sm" type="submit">Valider</button></div>
      </form>
    </details>
    <?php
}

$classHome = class_home_folder($level);
$crumbs = class_crumbs($level, $data);
$walk = '';
if ($dir !== '') {
    foreach (explode('/', $dir) as $part) {
        $walk = $walk === '' ? $part : $walk . '/' . $part;
        if ($walk === $classHome) continue; // le dossier de classe = la page de la classe
        $crumbs[] = [$part, level_url($level, $walk)];
    }
}
if ($q !== '') $crumbs[] = ['Recherche', null];
if ($todoPage) $crumbs[] = ['À rendre', null];

page_top(($todoPage ? 'À rendre' : ($q !== '' ? 'Recherche' : ($dir === '' ? 'Ressources' : basename($dir)))) . ' — ' . $level);
?>
<div class="container section">
  <?php breadcrumb($crumbs); ?>

  <?php if ($examFolders !== null): ?>
    <div class="exam-banner" role="status">
      <?= icon('exam') ?>
      <div>
        <p class="exam-banner__title">Examen en cours<?php if ($examNow): ?> · fin à <?= h(date('H:i', $examNow['end_ts'])) ?> <span class="exam-banner__timer" data-countdown="<?= (int) $examNow['end_ts'] ?>" data-countdown-short></span><?php endif; ?></p>
        <p>Seules les ressources de l’épreuve sont accessibles.
          <?php foreach ($examFolders as $f): ?>
            <a href="<?= h(level_url($level, $f)) ?>">Ouvrir « <?= h(basename($f)) ?> »</a>
          <?php endforeach; ?>
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php $announcements = active_announcements($data, $level); if ($announcements): ?>
    <div class="announcements" aria-label="Annonces">
      <?php foreach ($announcements as $a): ?>
        <article class="announcement announcement--<?= h($a['type']) ?>">
          <?= icon($a['type'] === 'important' ? 'alert' : 'megaphone') ?>
          <div class="announcement__body">
            <p class="announcement__title"><?= h($a['title']) ?></p>
            <?php if ($a['text'] !== ''): ?><p><?= nl2br(h($a['text'])) ?></p><?php endif; ?>
            <p class="announcement__meta">Publiée le <?= h(format_datetime($a['created'])) ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php render_group_bar($level, $dir, $data, $groupFlash); ?>

  <div class="page-head">
    <div>
      <p class="page-head__kicker"><?= badge($level, 'info') ?><?php foreach (viewer_groups($level, $data) as $vg) echo ' ' . badge($vg, ''); ?></p>
      <h1><?= $todoPage ? 'À rendre' : ($q !== '' ? 'Résultats de recherche' : h($dir === '' ? 'Ressources de la classe' : basename($dir))) ?></h1>
    </div>
    <form class="search" method="get" action="index.php" role="search">
      <input type="hidden" name="level" value="<?= h($level) ?>">
      <label for="q" class="sr-only">Rechercher un document</label>
      <input id="q" class="input search__input" type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher un cours, un TP…" maxlength="100">
      <button class="btn search__btn" type="submit" title="Rechercher"><?= icon('search') ?><span class="sr-only">Rechercher</span></button>
    </form>
  </div>

  <?php
  if ($todoPage) {
      /* ----- Échéancier ----- */
      $todo = student_todo($level, $data);
      ?>
      <div class="toolbar">
        <p class="muted"><?= count($todo) ?> travail<?= count($todo) > 1 ? 'x' : '' ?> à rendre ou à venir</p>
        <a class="btn btn--secondary btn--sm" href="<?= h(level_url($level)) ?>"><?= icon('back') ?>Ressources de la classe</a>
      </div>
      <?php if (!$todo): ?>
        <div class="empty"><?= icon('check') ?><p><strong>Rien à rendre pour le moment.</strong></p><p class="muted">Les dépôts ouverts par vos professeurs apparaîtront ici avec leur date limite.</p></div>
      <?php else: ?>
        <ul class="todo-list"><?php foreach ($todo as $t) todo_item($level, $t, $classHome); ?></ul>
      <?php endif; ?>
      <?php
  } elseif ($q !== '') {
      /* ----- Recherche ----- */
      $terms = array_filter(explode(' ', fold_text($q)));
      $dirs = [];
      $docs = [];
      foreach (all_items() as $path => $type) {
          $path = (string) $path;
          if ($path === '') continue;
          $hay = fold_text(basename($path));
          $match = true;
          foreach ($terms as $t) if (strpos($hay, $t) === false) { $match = false; break; }
          if (!$match) continue;
          if ($type === 'dir' && can_browse($path, $level, $data)) $dirs[] = $path;
          elseif ($type === 'file' && !is_asset_file($path) && rule_for($path, $level, $data)) $docs[] = $path;
          if (count($dirs) + count($docs) >= 100) break;
      }
      ?>
      <p class="muted"><?= count($dirs) + count($docs) ?> résultat<?= count($dirs) + count($docs) > 1 ? 's' : '' ?> pour « <?= h($q) ?> » · <a href="<?= h(level_url($level)) ?>">Effacer la recherche</a></p>
      <?php if (!$dirs && !$docs): ?>
        <div class="empty"><?= icon('search') ?><p><strong>Aucun résultat.</strong></p><p class="muted">Vérifiez l’orthographe ou essayez un autre mot.</p></div>
      <?php endif; ?>
      <?php if ($dirs): ?>
        <section class="block">
          <h2 class="h4">Dossiers</h2>
          <ul class="tiles">
            <?php foreach ($dirs as $sub): ?>
              <li><a class="tile" href="<?= h(level_url($level, $sub)) ?>">
                <span class="tile__icon"><?= icon('folder') ?></span>
                <span class="tile__title"><?= h(basename($sub)) ?></span>
                <span class="tile__meta"><?= h(parent_dir($sub) === '' ? 'Racine' : parent_dir($sub)) ?></span>
                <span class="tile__arrow"><?= icon('arrow') ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
      <?php if ($docs): ?>
        <section class="block">
          <h2 class="h4">Documents</h2>
          <ul class="doc-list"><?php foreach ($docs as $sub) doc_item($level, $sub, true, $data, $classHome); ?></ul>
        </section>
      <?php endif; ?>
      <?php
  } else {
      /* ----- Contenu du dossier ----- */
      $listing = names_in($dir);
      $folders = array_values(array_filter($listing['dirs'], function ($sub) use ($level, $data) {
          return can_browse($sub, $level, $data);
      }));
      $files = array_values(array_filter($listing['files'], function ($sub) use ($level, $data) {
          return !is_asset_file($sub) && rule_for($sub, $level, $data);
      }));
      $cfg = $dir !== '' ? deposit_for($dir) : null;
      $showDeposit = deposit_for_viewer($cfg, $level);
      $parent = $dir === '' ? null : parent_dir($dir);
      if ($parent !== null && $parent === $classHome) $parent = '';

      // Accueil de la classe : les dossiers « Classe/Matière » deviennent « Mes matières ».
      $subjects = [];
      $recent = [];
      if ($dir === '') {
          if ($classHome !== '' && can_browse($classHome, $level, $data)) {
              $folders = array_values(array_filter($folders, function ($f) use ($classHome) { return $f !== $classHome; }));
              $inside = names_in($classHome);
              $subjects = array_values(array_filter($inside['dirs'], function ($sub) use ($level, $data) { return can_browse($sub, $level, $data); }));
              foreach ($inside['files'] as $f) if (!is_asset_file($f) && rule_for($f, $level, $data)) $files[] = $f;
          }
          // Dernières publications (tous dossiers confondus).
          $limit = time() - 14 * 86400;
          foreach (all_items() as $p => $t) {
              $p = (string) $p;
              if ($t !== 'file' || is_asset_file($p) || !rule_for($p, $level, $data)) continue;
              $nt = news_time($p, $data);
              if ($nt >= $limit) $recent[$p] = $nt;
          }
          arsort($recent);
          $recent = array_slice(array_keys($recent), 0, 5);
      }
      ?>
      <div class="toolbar">
        <p class="muted">
          <?php
          $summary = [];
          if ($subjects) $summary[] = count($subjects) . ' matière' . (count($subjects) > 1 ? 's' : '');
          if ($folders) $summary[] = count($folders) . ' dossier' . (count($folders) > 1 ? 's' : '');
          if ($files) $summary[] = count($files) . ' document' . (count($files) > 1 ? 's' : '');
          echo h(implode(' · ', $summary));
          ?>
        </p>
        <?php if ($parent !== null): ?>
          <a class="btn btn--secondary btn--sm" href="<?= h(level_url($level, $parent)) ?>"><?= icon('back') ?>Dossier parent</a>
        <?php else: ?>
          <a class="btn btn--secondary btn--sm" href="<?= h(change_class_url($level, $data)) ?>"><?= icon('back') ?>Changer de classe</a>
        <?php endif; ?>
      </div>

      <?php if (!$folders && !$files && !$showDeposit && !$subjects): ?>
        <div class="empty">
          <?= icon('folder') ?>
          <p><strong>Aucune ressource disponible pour le moment.</strong></p>
          <p class="muted">Vos professeurs n’ont encore rien publié ici pour la classe <?= h($level) ?>.</p>
        </div>
      <?php endif; ?>

      <?php if ($showDeposit) render_deposit_box($cfg, $level, $dir, $depositFlash, $receipts); ?>

      <?php if ($dir === ''): $todo = student_todo($level, $data); if ($todo): ?>
        <section class="block todo-block">
          <div class="todo-block__head">
            <h2 class="h4"><?= icon('inbox') ?> À rendre</h2>
            <a class="link-more" href="<?= h(level_url($level) . '&todo=1') ?>">Tout voir (<?= count($todo) ?>)<?= icon('arrow') ?></a>
          </div>
          <ul class="todo-list"><?php foreach (array_slice($todo, 0, 3) as $t) todo_item($level, $t, $classHome); ?></ul>
        </section>
      <?php endif; endif; ?>

      <?php if ($recent): ?>
        <section class="block recent">
          <h2 class="h4"><?= icon('clock') ?> Dernières publications</h2>
          <ul class="doc-list"><?php foreach ($recent as $sub) doc_item($level, $sub, true, $data, $classHome); ?></ul>
        </section>
      <?php endif; ?>

      <?php if ($subjects): ?>
        <section class="block">
          <h2 class="h4">Mes matières</h2>
          <ul class="tiles tiles--subjects">
            <?php foreach ($subjects as $sub): ?>
              <li>
                <a class="tile tile--subject" href="<?= h(level_url($level, $sub)) ?>">
                  <span class="tile__icon"><?= icon('library') ?></span>
                  <span class="tile__title"><?= h(basename($sub)) ?></span>
                  <span class="tile__meta"><?= is_new($sub, $data) ? badge('Nouveau', 'new') : 'Ouvrir' ?></span>
                  <span class="tile__arrow"><?= icon('arrow') ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($folders): ?>
        <section class="block">
          <h2 class="h4"><?= $subjects ? 'Autres ressources' : 'Dossiers' ?></h2>
          <ul class="tiles">
            <?php foreach ($folders as $sub):
                $dcfg = deposit_for($sub);
                $hasDeposit = deposit_for_viewer($dcfg, $level) && deposit_accepting($dcfg); ?>
              <li>
                <a class="tile" href="<?= h(level_url($level, $sub)) ?>">
                  <span class="tile__icon"><?= icon('folder') ?></span>
                  <span class="tile__title"><?= h(basename($sub)) ?></span>
                  <span class="tile__meta"><?= $hasDeposit ? icon('inbox') . 'Travail à rendre' : 'Ouvrir le dossier' ?><?= is_new($sub, $data) ? ' ' . badge('Nouveau', 'new') : '' ?></span>
                  <span class="tile__arrow"><?= icon('arrow') ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($files): ?>
        <section class="block">
          <h2 class="h4">Documents</h2>
          <ul class="doc-list"><?php foreach ($files as $sub) doc_item($level, $sub, false, $data); ?></ul>
        </section>
      <?php endif; ?>
      <?php
  }
  ?>
</div>
<?php
page_end();

/** Encadré « Rendre mon travail ». */
function render_deposit_box(array $cfg, $level, $dir, $flash, array $receipts)
{
    $state = deposit_state($cfg);
    $exts = deposit_exts($cfg);
    $until = parse_datetime($cfg['until']);
    ?>
    <section class="deposit<?= $state === 'late' ? ' deposit--late' : '' ?>" id="rendre">
      <div class="deposit__head">
        <span class="deposit__icon"><?= icon('inbox') ?></span>
        <div>
          <h2 class="h3">Rendre mon travail</h2>
          <p class="muted">
            <?php if ($state === 'scheduled'): ?>Le dépôt ouvrira le <?= h(format_datetime($cfg['from'])) ?>.
            <?php elseif ($state === 'closed'): ?>Le dépôt est fermé depuis le <?= h(format_datetime($cfg['late'] === 'accept' && $cfg['late_until'] !== '' ? $cfg['late_until'] : $cfg['until'])) ?>.
            <?php elseif ($state === 'late'): ?><strong class="text-danger">Date limite dépassée</strong> (<?= h(format_datetime($cfg['until'])) ?>) : votre travail sera marqué « en retard »<?= $cfg['late_until'] !== '' ? ', dépôt possible jusqu’au ' . h(format_datetime($cfg['late_until'])) : '' ?>.
            <?php elseif ($until !== null): ?>À déposer avant le <strong><?= h(format_datetime($cfg['until'])) ?></strong> · <span data-countdown="<?= (int) $until ?>"></span>
            <?php else: ?>Dépôt ouvert.<?php endif; ?>
          </p>
        </div>
      </div>

      <?php if ($cfg['note'] !== ''): ?>
        <div class="deposit__note"><?= nl2br(h($cfg['note'])) ?></div>
      <?php endif; ?>

      <?php
      if ($flash) {
          if ($flash['ok']) {
              ?>
              <div class="receipt" data-receipt>
                <div class="receipt__head"><?= icon('check') ?><div>
                  <p class="receipt__title"><?= count($flash['ok']) > 1 ? 'Vos fichiers ont bien été déposés' : 'Votre fichier a bien été déposé' ?><?= !empty($flash['late']) ? ' (en retard)' : '' ?></p>
                  <p class="muted small"><?= h(implode(', ', $flash['ok'])) ?></p>
                </div></div>
                <p class="receipt__code">Reçu n° <strong><?= h($flash['receipt']) ?></strong></p>
                <p class="muted small">Notez ce numéro ou imprimez ce reçu : il prouve que votre travail a été rendu le <?= h(date('d/m/Y à H:i')) ?>.</p>
                <button type="button" class="btn btn--tertiary btn--sm" data-print><?= icon('printer') ?>Imprimer le reçu</button>
              </div>
              <?php
          }
          if ($flash['errors']) alert('error', implode(' ', $flash['errors']), 'Dépôt incomplet');
      }
      ?>

      <?php if (deposit_accepting($cfg)): ?>
        <form method="post" enctype="multipart/form-data" class="form" action="<?= h(level_url($level, $dir)) ?>" data-dropzone>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="deposit">
          <input type="hidden" name="level" value="<?= h($level) ?>">
          <input type="hidden" name="dir" value="<?= h($dir) ?>">
          <div class="field-row">
            <div class="field">
              <label for="dep-last">Nom</label>
              <input id="dep-last" class="input" type="text" name="last" required maxlength="60" autocomplete="family-name" value="<?= h($flash ? $flash['last'] : '') ?>">
            </div>
            <div class="field">
              <label for="dep-first">Prénom</label>
              <input id="dep-first" class="input" type="text" name="first" required maxlength="60" autocomplete="given-name" value="<?= h($flash ? $flash['first'] : '') ?>">
            </div>
          </div>
          <?php if ($cfg['group_max'] > 1): ?>
            <details class="schedule teammates"<?= $flash && !empty($flash['members']) ? ' open' : '' ?>>
              <summary><?= icon('group') ?>Travail en <?= $cfg['group_max'] === 2 ? 'binôme' : 'groupe' ?> ? Ajoutez vos coéquipiers <span class="muted small">(jusqu’à <?= $cfg['group_max'] - 1 ?>)</span></summary>
              <?php for ($i = 0; $i < $cfg['group_max'] - 1; $i++): $m = $flash && isset($flash['members'][$i]) ? $flash['members'][$i] : ['last' => '', 'first' => '']; ?>
                <div class="field-row">
                  <div class="field"><label for="m-last-<?= $i ?>">Nom du coéquipier <?= $i + 1 ?></label><input id="m-last-<?= $i ?>" class="input" type="text" name="m_last[]" maxlength="60" value="<?= h($m['last']) ?>"></div>
                  <div class="field"><label for="m-first-<?= $i ?>">Prénom</label><input id="m-first-<?= $i ?>" class="input" type="text" name="m_first[]" maxlength="60" value="<?= h($m['first']) ?>"></div>
                </div>
              <?php endfor; ?>
              <p class="muted small">Un seul membre du groupe dépose : le travail est enregistré pour tous.</p>
            </details>
          <?php endif; ?>
          <label class="dropzone">
            <input type="file" name="files[]" multiple required<?= $exts ? ' accept="' . h(implode(',', array_map(function ($e) { return '.' . $e; }, $exts))) . '"' : '' ?>>
            <?= icon('upload') ?>
            <strong>Déposer mon fichier ici</strong>
            <span>ou cliquer pour parcourir — <?= $exts ? h(strtoupper(implode(', ', $exts))) . ' · ' : '' ?><?= h(human_size(deposit_max_bytes($cfg))) ?> maximum par fichier</span>
            <span class="dropzone__list" data-file-list></span>
          </label>
          <p class="deposit__privacy"><?= icon('info') ?><span>Votre nom, votre classe et vos fichiers sont transmis uniquement à votre professeur. Ils sont conservés pendant l’année scolaire, puis archivés un an avant d’être supprimés. <a href="legal.php" target="_blank" rel="noopener">En savoir plus sur vos données</a></span></p>
          <div class="form__actions">
            <button class="btn" type="submit"><?= icon('upload') ?>Envoyer mon travail</button>
            <span class="muted small">Vous pouvez déposer une nouvelle version : votre professeur verra toutes vos versions.</span>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($receipts): ?>
        <div class="receipts">
          <p class="receipts__title">Vos dépôts depuis ce poste</p>
          <ul>
            <?php foreach (array_reverse($receipts) as $r): ?>
              <li><?= icon('check') ?><span><strong><?= h(implode(', ', $r['files'])) ?></strong> — <?= h($r['name']) ?>, le <?= h(date('d/m/Y à H:i', $r['time'])) ?><?= !empty($r['receipt']) ? ' · reçu <code>' . h($r['receipt']) . '</code>' : '' ?><?= !empty($r['late']) ? ' ' . badge('En retard', 'warning') : '' ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>
    <?php
}
