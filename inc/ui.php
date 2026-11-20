<?php
/**
 * Gabarit HTML commun, icônes et composants d'interface.
 */

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Icônes SVG en ligne (trait 1.75, 24×24). */
function icon($name, $class = '')
{
    static $paths = [
        'folder'   => '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.2l2 2.2h8.8A1.5 1.5 0 0 1 21 8.7v9.8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z"/>',
        'file'     => '<path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3v5h5"/>',
        'doc'      => '<path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3v5h5M8.5 12.5h7M8.5 16h7"/>',
        'pdf'      => '<path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3v5h5"/><path d="M8.5 17v-4h1.3a1.2 1.2 0 0 1 0 2.4H8.5M13 17v-4h1a2 2 0 0 1 0 4zM17.5 13h-1.7v4M15.8 15h1.4"/>',
        'web'      => '<rect x="3" y="4" width="18" height="16" rx="1.5"/><path d="M3 8.5h18M6 6.3h.01M8.5 6.3h.01M8 13l-2 2 2 2M16 13l2 2-2 2M13.2 12l-2.4 6"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="1.5"/><circle cx="9" cy="9.5" r="1.6"/><path d="m21 16-5-5-9 9"/>',
        'video'    => '<rect x="3" y="5" width="13" height="14" rx="1.5"/><path d="m16 10 5-3v10l-5-3"/>',
        'audio'    => '<path d="M9 18V5l11-2v13"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="16" r="2.5"/>',
        'archive'  => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10.5A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V9M10 13h4"/>',
        'sheet'    => '<path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3v5h5M8 12h8v6H8zM8 15h8M12 12v6"/>',
        'slides'   => '<rect x="3" y="4" width="18" height="13" rx="1.5"/><path d="M12 17v4M8 21h8M7 13l3-3 2 2 5-5"/>',
        'lock'     => '<rect x="4.5" y="10.5" width="15" height="10" rx="1.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
        'unlock'   => '<rect x="4.5" y="10.5" width="15" height="10" rx="1.5"/><path d="M8 10.5V7a4 4 0 0 1 7.8-1.2"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'back'     => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'chevron'  => '<path d="m9 6 6 6-6 6"/>',
        'home'     => '<path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4.5v-6h-5v6H5a1 1 0 0 1-1-1z"/>',
        'library'  => '<path d="M4 4h4v16H4zM10 4h4v16h-4zM16.2 4.6l3.8 1-3.9 14.8-3.8-1"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
        'key'      => '<circle cx="8" cy="15" r="4.5"/><path d="m11.2 11.8 8.8-8.8M16.5 6.5l2.5 2.5M14 9l2 2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'logout'   => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 16l-4-4 4-4M6 12h10"/>',
        'eye'      => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'  => '<path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-2.2 3.1M6.6 6.6C3.7 8.5 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M3 3l18 18M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'copy'     => '<rect x="8" y="8" width="12" height="12" rx="1.5"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.6h.01"/>',
        'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12.3 2.7 2.7L16 9.7"/>',
        'alert'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.4h.01"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="1.5"/><path d="M3.5 10h17M8 3v4M16 3v4M8 14h2M14 14h2M8 17h2"/>',
        'move'     => '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6h4.2l2 2.2h8.8A1.5 1.5 0 0 1 21 9.7v8.8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z"/><path d="M9 14h6M13 11.5l2.5 2.5-2.5 2.5"/>',
        'restore'  => '<path d="M4 12a8 8 0 1 0 2.4-5.7L4 8.5M4 4v4.5h4.5"/>',
        'link'     => '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
        'megaphone'=> '<path d="M3 10v4a1 1 0 0 0 1 1h3l6 4V5L7 9H4a1 1 0 0 0-1 1zM17 8.5a5 5 0 0 1 0 7M7 15l1.5 5h2.5l-1-4.2"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'     => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'type'     => '<path d="M4 7V5h12v2M10 5v14M7 19h6M14 13v-1.5h7V13M17.5 11.5V19M16 19h3"/>',
        'copy2'    => '<rect x="8" y="8" width="12" height="12" rx="1.5"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8M12 14h4M14 12v4"/>',
        'inbox'    => '<path d="M3 13.5 5.6 5.1A1.5 1.5 0 0 1 7 4h10a1.5 1.5 0 0 1 1.4 1.1l2.6 8.4V19a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M3 13.5h5l1.5 2.5h5l1.5-2.5h5"/>',
        'exam'     => '<path d="M9 4h6v3H9z"/><path d="M15 5.5h2.5A1.5 1.5 0 0 1 19 7v12.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19.5V7a1.5 1.5 0 0 1 1.5-1.5H9M9 13l2 2 4-4"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'edit'     => '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.5A1.5 1.5 0 0 0 8.5 21h7a1.5 1.5 0 0 0 1.5-1.5L18 7M9 7V4.5A.5.5 0 0 1 9.5 4h5a.5.5 0 0 1 .5.5V7"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'upload'   => '<path d="M12 16V4M7 9l5-5 5 5M5 20h14"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'school'   => '<path d="M3 21h18M5 21V10l7-5 7 5v11M10 21v-5h4v5M12 9.5v.01"/>',
        'quiz'     => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M8 8h.01M11 8h5M8 12.5h.01M11 12.5h5M8 17h.01M11 17h5"/>',
        'present'  => '<rect x="3" y="4" width="18" height="12" rx="1.2"/><path d="M12 16v3M8 21l4-2 4 2M10 8.5v4l3.5-2z"/>',
        'shield'   => '<path d="M12 3 5 6v5.5c0 4.4 3 8.2 7 9.5 4-1.3 7-5.1 7-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
        'group'    => '<circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/><path d="M2.5 19a5.5 5.5 0 0 1 11 0M10.5 19a5.5 5.5 0 0 1 11 0"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'bank'     => '<path d="M3 9.5 12 4l9 5.5M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20.5h18"/>',
        'import'   => '<path d="M12 3v11M7.5 9.5 12 14l4.5-4.5M4 15v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/>',
        'printer'  => '<path d="M7 9V4h10v5M7 17H5a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-2"/><path d="M7 14h10v6H7z"/>',
        'disk'     => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'hourglass'=> '<path d="M6 3h12M6 21h12M7 3c0 5 5 6 5 9s-5 4-5 9M17 3c0 5-5 6-5 9s5 4 5 9"/>',
        'share'    => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.4M8.2 13.2l7.6 4.4"/>',
        'expand'   => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
        'prev'     => '<path d="m15 6-6 6 6 6"/>',
        'close'    => '<path d="M6 6l12 12M18 6 6 18"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'star'     => '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>',
        'bell'     => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15zM10 20a2 2 0 0 0 4 0"/>',
        'pen'      => '<path d="m14.5 4.5 5 5L9 20H4v-5z"/><path d="m12.5 6.5 5 5"/>',
        'palette'  => '<path d="M12 3a9 9 0 1 0 0 18c1 0 1.5-.8 1.5-1.6 0-1.2-1-1.4-1-2.4 0-.9.7-1.5 1.6-1.5H17a4 4 0 0 0 4-4c0-4.7-4-8.5-9-8.5z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7" r="1"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="m3.5 6 8.5 7 8.5-7"/>',
        'dashboard'=> '<rect x="3" y="3" width="8" height="10" rx="1"/><rect x="13" y="3" width="8" height="6" rx="1"/><rect x="13" y="11" width="8" height="10" rx="1"/><rect x="3" y="15" width="8" height="6" rx="1"/>',
        'refresh'  => '<path d="M20 11a8 8 0 0 0-14.9-3M4 13a8 8 0 0 0 14.9 3M5 4v4h4M19 20v-4h-4"/>',
        'steps'    => '<path d="M4 20h5v-5h5v-5h5V5"/>',
    ];
    $svg = isset($paths[$name]) ? $paths[$name] : $paths['file'];
    $cls = 'icon' . ($class !== '' ? ' ' . $class : '');
    return '<svg class="' . h($cls) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $svg . '</svg>';
}

/** En-tête de page. $area : 'public' ou 'admin'. */
function page_top($title, $area = 'public')
{
    $school = school_name();
    $isAdmin = $area === 'admin';
    $GLOBALS['tpw_area_admin'] = $isAdmin;
    ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> · <?= h(TPW_APP_NAME) ?></title>
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/style.css?v=<?= h(TPW_VERSION) ?>">
  <?php $brand = branding(); if (($css = branding_css()) !== ''): ?><style><?= $css ?></style><?php endif; ?>
  <meta name="theme-color" content="<?= h($brand['color']) ?>">
  <?php if (!$isAdmin): ?><link rel="manifest" href="manifest.php"><link rel="apple-touch-icon" href="assets/icon-192.png"><?php endif; ?>
  <script>
    // Préférences d'affichage appliquées avant le rendu (évite un flash de thème).
    (function () {
      try {
        var d = document.documentElement, t = localStorage.getItem('tpw-theme') || 'auto';
        if (t === 'auto') t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        d.setAttribute('data-theme', t);
        d.setAttribute('data-text', localStorage.getItem('tpw-text') || 'normal');
        if (localStorage.getItem('tpw-dys') === '1') d.setAttribute('data-dys', '1');
      } catch (e) {}
    })();
  </script>
</head>
<body class="area-<?= h($area) ?>"<?= !$isAdmin && function_exists('current_student') && current_student() ? ' data-student="1"' : '' ?>>
  <div class="offline-bar" role="status">Hors ligne : seuls les cours déjà ouverts sur cet appareil sont disponibles.</div>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <header class="header" role="banner">
    <div class="container header__inner">
      <a class="brand" href="index.php" title="Accueil — <?= h(TPW_APP_NAME) ?>">
        <?php if ($brand['logo'] !== ''): ?>
          <span class="brand__logo" aria-hidden="true"><img src="logo.php?v=<?= h(substr(md5($brand['logo']), 0, 8)) ?>" alt=""></span>
        <?php else: ?>
          <span class="brand__mark" aria-hidden="true"><span>TP</span></span>
        <?php endif; ?>
        <span class="brand__text">
          <span class="brand__name"><?= h(TPW_APP_NAME) ?></span>
          <span class="brand__tagline">Ressources pédagogiques<?= $school !== '' ? ' · ' . h($school) : '' ?></span>
        </span>
      </a>
      <nav class="header__tools" aria-label="Accès rapides">
        <a href="index.php" class="header__link<?= $isAdmin ? '' : ' is-current' ?>"<?= $isAdmin ? '' : ' aria-current="page"' ?>><?= icon('users') ?><span>Espace élèves</span></a>
        <a href="admin.php" class="header__link<?= $isAdmin ? ' is-current' : '' ?>"<?= $isAdmin ? ' aria-current="page"' : '' ?>><?= icon('lock') ?><span>Espace enseignant</span></a>
        <details class="display-menu" data-display-menu>
          <summary class="header__link" title="Paramètres d’affichage"><?= icon('type') ?><span>Affichage</span></summary>
          <div class="display-menu__panel">
            <div class="display-menu__group">
              <p class="display-menu__label">Thème</p>
              <div class="segmented" data-pref="tpw-theme">
                <button type="button" data-value="light"><?= icon('sun') ?>Clair</button>
                <button type="button" data-value="dark"><?= icon('moon') ?>Sombre</button>
                <button type="button" data-value="auto">Système</button>
              </div>
            </div>
            <div class="display-menu__group">
              <p class="display-menu__label">Taille du texte</p>
              <div class="segmented" data-pref="tpw-text">
                <button type="button" data-value="normal">A</button>
                <button type="button" data-value="large" style="font-size:1rem">A+</button>
                <button type="button" data-value="xlarge" style="font-size:1.15rem">A++</button>
              </div>
            </div>
            <div class="display-menu__group">
              <label class="toggle"><input type="checkbox" data-dys-toggle><span>Lecture facilitée (police et espacements adaptés)</span></label>
            </div>
          </div>
        </details>
      </nav>
    </div>
  </header>
  <main id="contenu" class="main" role="main">
<?php
}

function page_end()
{
    $school = school_name();
    ?>
  </main>
  <footer class="footer" role="contentinfo">
    <div class="container footer__inner">
      <div class="footer__brand">
        <strong><?= h(TPW_APP_NAME) ?></strong>
        <span>Portail de diffusion des cours et travaux pratiques<?= $school !== '' ? ' — ' . h($school) : '' ?></span>
      </div>
      <ul class="footer__links">
        <li><a href="index.php">Espace élèves</a></li>
        <li><a href="admin.php">Espace enseignant</a></li>
        <li><a href="<?= !empty($GLOBALS['tpw_area_admin']) ? 'admin.php?view=feedback' : 'index.php?signaler=1' ?>">Signaler un problème</a></li>
        <li><a href="legal.php">Mentions légales et données personnelles</a></li>
      </ul>
    </div>
    <div class="footer__bottom">
      <div class="container">Service accessible uniquement depuis le réseau de l’établissement · Version <?= h(TPW_VERSION) ?></div>
    </div>
  </footer>
  <script src="assets/app.js?v=<?= h(TPW_VERSION) ?>"></script>
</body>
</html>
<?php
}

/** Page d'erreur complète, puis arrêt. */
function fail($code, $title, $message)
{
    http_response_code($code);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    page_top($title);
    ?>
    <div class="container container--narrow">
      <section class="error-page">
        <p class="error-page__code">Erreur <?= (int) $code ?></p>
        <h1><?= h($title) ?></h1>
        <p class="lead"><?= h($message) ?></p>
        <a class="btn" href="index.php"><?= icon('home') ?>Retour à l’accueil</a>
      </section>
    </div>
    <?php
    page_end();
    exit;
}

function alert($type, $message, $title = '')
{
    $icons = ['success' => 'check', 'error' => 'alert', 'info' => 'info', 'warning' => 'alert'];
    $role = $type === 'error' ? 'alert' : 'status';
    echo '<div class="alert alert--' . h($type) . '" role="' . $role . '">' . icon(isset($icons[$type]) ? $icons[$type] : 'info');
    echo '<div>' . ($title !== '' ? '<p class="alert__title">' . h($title) . '</p>' : '') . '<p>' . h($message) . '</p></div></div>';
}

function badge($label, $variant = '')
{
    return '<span class="badge' . ($variant !== '' ? ' badge--' . h($variant) : '') . '">' . h($label) . '</span>';
}

/* ---------- URLs ---------- */

function level_url($level, $dir = '')
{
    $url = 'index.php?level=' . rawurlencode($level);
    return $dir === '' ? $url : $url . '&dir=' . rawurlencode($dir);
}

function file_url($level, $path)
{
    return 'file.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($path);
}

function admin_url($view, $item = null)
{
    $url = 'admin.php?view=' . rawurlencode($view);
    return $item === null ? $url : $url . '&item=' . rawurlencode($item);
}

/** Fil d'Ariane : liste de [libellé, url] (le dernier élément n'est pas un lien). */
function breadcrumb(array $items)
{
    echo '<nav class="breadcrumb" aria-label="Vous êtes ici"><ol>';
    $last = count($items) - 1;
    foreach (array_values($items) as $i => $item) {
        list($label, $url) = $item;
        if ($i === $last || $url === null) {
            echo '<li><span aria-current="page">' . h($label) . '</span></li>';
        } else {
            echo '<li><a href="' . h($url) . '">' . h($label) . '</a></li>';
        }
    }
    echo '</ol></nav>';
}

/* ---------- Niveaux ---------- */

function niveau_url($niveau)
{
    return 'index.php?niveau=' . rawurlencode($niveau);
}

/** Début du fil d'Ariane d'une classe : Accueil › Niveau › Classe. */
function class_crumbs($level, array $data, $linkClass = true)
{
    $crumbs = [['Accueil', 'index.php']];
    $niveau = niveau_of($level, $data);
    if ($niveau !== '') $crumbs[] = [$niveau, niveau_url($niveau)];
    $crumbs[] = [$level, $linkClass ? level_url($level) : null];
    return $crumbs;
}

/** Adresse de retour « changer de classe » (page du niveau si la classe en a un). */
function change_class_url($level, array $data)
{
    $niveau = niveau_of($level, $data);
    return $niveau !== '' ? niveau_url($niveau) : 'index.php';
}

/** Tuile d'une classe (accueil ou page de niveau). */
function class_tile($lvl, array $data, array $codes)
{
    $locked = class_code_enabled($lvl, $codes);
    $exam = exam_folders_for($lvl, $data) !== null;
    ?>
    <li>
      <a class="tile tile--level" href="<?= h(level_url($lvl)) ?>">
        <span class="tile__title"><?= h($lvl) ?></span>
        <span class="tile__meta">
          <?php if ($exam): ?><?= icon('exam') ?>Examen en cours<?php elseif ($locked): ?><?= icon('lock') ?>Accès par code<?php else: ?>Accès libre<?php endif; ?>
        </span>
        <span class="tile__arrow"><?= icon('arrow') ?></span>
      </a>
    </li>
    <?php
}
