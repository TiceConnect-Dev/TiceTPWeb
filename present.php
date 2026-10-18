<?php
/**
 * Mode projection (enseignant) : un document en plein écran, sans menus,
 * avec passage au document suivant/précédent du même dossier (flèches du clavier).
 */

require_once __DIR__ . '/inc/bootstrap.php';

$user = current_user();
if (!$user) redirect('admin.php');
$item = query('item');
$abs = full_path($item, 'file');
if (!$abs || !can_manage($item, $user)) fail(404, 'Document introuvable', 'Ce document n’existe pas ou ne vous est pas attribué.');
release_session();

$type = preview_type($item);
$folder = dirname($item) === '.' ? '' : dirname($item);

// Documents projetables du même dossier.
$siblings = array_values(array_filter(names_in($folder)['files'], function ($f) {
    return !is_asset_file($f) && !is_quiz_file($f) && preview_type($f) !== null;
}));
$pos = array_search($item, $siblings, true);
$prev = $pos !== false && $pos > 0 ? $siblings[$pos - 1] : null;
$next = $pos !== false && $pos < count($siblings) - 1 ? $siblings[$pos + 1] : null;

$src = 'admin.php?dl=file&inline=1&item=' . rawurlencode($item);
$officeMode = $type === 'office' ? office_preview_mode($item) : '';

// Cours HTML : jeton limité au dossier géré qui contient le cours.
$root = '';
foreach (managed_roots($user) as $r) if (path_within($item, $r)) { $root = $r; break; }
$viewSrc = 'view.php?tk=' . rawurlencode(teacher_view_token($root)) . '&f=' . rawurlencode($root) . '&path=' . rawurlencode($item);
$url = function ($p) { return 'present.php?item=' . rawurlencode($p); };
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h(display_name($item)) ?> · Projection · <?= h(TPW_APP_NAME) ?></title>
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/style.css?v=<?= h(TPW_VERSION) ?>">
</head>
<body class="present" data-present<?= $prev ? ' data-prev="' . h($url($prev)) . '"' : '' ?><?= $next ? ' data-next="' . h($url($next)) . '"' : '' ?>>
  <header class="present__bar">
    <a class="present__btn" href="<?= h(admin_url('library', $item)) ?>" title="Quitter la projection (Échap)"><?= icon('close') ?><span>Quitter</span></a>
    <p class="present__title"><?= h(display_name($item)) ?><?php if ($pos !== false && count($siblings) > 1): ?> <span class="present__count"><?= $pos + 1 ?> / <?= count($siblings) ?></span><?php endif; ?></p>
    <div class="present__nav">
      <?php if ($prev): ?><a class="present__btn" href="<?= h($url($prev)) ?>" title="Précédent (←)"><?= icon('prev') ?><span class="sr-only">Précédent</span></a><?php endif; ?>
      <?php if ($next): ?><a class="present__btn" href="<?= h($url($next)) ?>" title="Suivant (→)"><?= icon('chevron') ?><span class="sr-only">Suivant</span></a><?php endif; ?>
      <button type="button" class="present__btn" data-fullscreen title="Plein écran (F)"><?= icon('expand') ?><span>Plein écran</span></button>
    </div>
  </header>
  <main class="present__stage" id="stage">
    <?php switch ($type):
        case 'html': ?>
          <iframe class="present__frame present__frame--light" title="<?= h(display_name($item)) ?>" sandbox="allow-scripts allow-forms allow-downloads allow-popups" src="<?= h($viewSrc) ?>"></iframe>
          <?php break;
        case 'pdf': ?>
          <iframe class="present__frame" title="<?= h(display_name($item)) ?>" src="<?= h($src) ?>#view=Fit"></iframe>
          <?php break;
        case 'office':
          if ($officeMode === 'pdf'): ?>
            <iframe class="present__frame" title="<?= h(display_name($item)) ?>" src="<?= h('admin.php?dl=file&as=pdf&item=' . rawurlencode($item)) ?>#view=Fit"></iframe>
          <?php else: ?>
            <article class="present__text office-text"><?= office_text_html($abs) ?: '<p>Aucun texte à afficher.</p>' ?></article>
          <?php endif;
          break;
        case 'image': ?>
          <img class="present__media" src="<?= h($src) ?>" alt="<?= h(display_name($item)) ?>">
          <?php break;
        case 'video': ?>
          <video class="present__media" src="<?= h($src) ?>" controls autoplay></video>
          <?php break;
        case 'audio': ?>
          <div class="present__audio"><?= icon('audio') ?><audio src="<?= h($src) ?>" controls autoplay></audio></div>
          <?php break;
        case 'youtube': ?>
          <iframe class="present__frame" title="<?= h(display_name($item)) ?>" src="https://www.youtube-nocookie.com/embed/<?= h(youtube_id(read_url_file($abs))) ?>?rel=0"
                  allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
          <?php break;
        case 'text': ?>
          <iframe class="present__frame present__frame--light" title="<?= h(display_name($item)) ?>" src="<?= h($src) ?>"></iframe>
          <?php break;
        default: ?>
          <p class="present__empty">Ce type de document ne peut pas être projeté. <a href="<?= h('admin.php?dl=file&item=' . rawurlencode($item)) ?>">Télécharger</a></p>
    <?php endswitch; ?>
  </main>
  <script src="assets/app.js?v=<?= h(TPW_VERSION) ?>"></script>
</body>
</html>
