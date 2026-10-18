<?php
/**
 * Aperçu intégré d'un document (cours HTML, PDF, image, vidéo, audio, texte)
 * dans le portail, avec retour au dossier et téléchargement.
 */

require_once __DIR__ . '/inc/bootstrap.php';

$level = query('level');
$path  = query('path');
$data  = settings();

class_guard($level);

$type = preview_type($path);
if (!full_path($path, 'file') || !rule_for($path, $level, $data)) {
    fail(404, 'Document indisponible', 'Ce document n’existe pas ou n’est pas publié pour votre classe.');
}
if ($type === null) {
    redirect(file_url($level, $path) . '&dl=1');
}
if ($type === 'quiz') {
    redirect('quiz.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($path));
}
$officeMode = $type === 'office' ? office_preview_mode($path) : '';

release_session();

$folder = dirname($path) === '.' ? '' : dirname($path);
$fileSrc = file_url($level, $path);
$viewSrc = 'view.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($path);
$kind = file_kind($path);
$abs = full_path($path, 'file');

$classHome = class_home_folder($level);
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

page_top(display_name($path));
?>
<div class="container section preview">
  <?php breadcrumb($crumbs); ?>
  <div class="page-head page-head--compact">
    <div>
      <p class="page-head__kicker"><?= badge($type === 'youtube' ? 'Vidéo YouTube' : $kind['label'], 'info') ?> <span class="muted small"><?= $abs && $type !== 'youtube' ? h(human_size(filesize($abs))) : '' ?></span></p>
      <h1 class="h2"><?= h(display_name($path)) ?></h1>
    </div>
    <div class="page-head__actions">
      <a class="btn btn--secondary btn--sm" href="<?= h(level_url($level, $folder === $classHome ? '' : $folder)) ?>"><?= icon('back') ?>Retour au dossier</a>
      <?php if ($type !== 'office' || $officeMode === 'pdf'): ?>
        <a class="btn btn--tertiary btn--sm" href="<?= h($type === 'html' ? $viewSrc : ($type === 'office' ? $fileSrc . '&as=pdf' : $fileSrc)) ?>" target="_blank" rel="noopener"><?= icon('external') ?><?= $type === 'youtube' ? 'Ouvrir sur YouTube' : 'Plein écran' ?></a>
      <?php endif; ?>
      <?php if (!in_array($type, ['html', 'youtube'], true)): ?>
        <a class="btn btn--tertiary btn--sm" href="<?= h($fileSrc . '&dl=1') ?>"><?= icon('download') ?>Télécharger</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="viewer viewer--<?= h($type) ?>">
    <?php switch ($type):
        case 'html': ?>
          <iframe class="viewer__frame" title="<?= h(display_name($path)) ?>" sandbox="allow-scripts allow-forms allow-downloads allow-popups" referrerpolicy="same-origin" src="<?= h($viewSrc) ?>"></iframe>
          <?php break;
        case 'pdf': ?>
          <iframe class="viewer__frame" title="<?= h(display_name($path)) ?>" src="<?= h($fileSrc) ?>"></iframe>
          <p class="viewer__fallback muted small">Le PDF ne s’affiche pas ? <a href="<?= h($fileSrc) ?>" target="_blank" rel="noopener">Ouvrez-le dans un nouvel onglet</a>.</p>
          <?php break;
        case 'image': ?>
          <img class="viewer__media" src="<?= h($fileSrc) ?>" alt="<?= h(display_name($path)) ?>">
          <?php break;
        case 'video': ?>
          <video class="viewer__media" src="<?= h($fileSrc) ?>" controls preload="metadata"></video>
          <?php break;
        case 'audio': ?>
          <div class="viewer__audio"><?= icon('audio') ?><audio src="<?= h($fileSrc) ?>" controls preload="metadata"></audio></div>
          <?php break;
        case 'youtube': ?>
          <div class="viewer__video">
            <iframe title="<?= h(display_name($path)) ?>" src="https://www.youtube-nocookie.com/embed/<?= h(youtube_id(read_url_file($abs))) ?>?rel=0"
                    allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
          </div>
          <?php break;
        case 'office':
          if ($officeMode === 'pdf'): ?>
            <iframe class="viewer__frame" title="<?= h(display_name($path)) ?>" src="<?= h($fileSrc . '&as=pdf') ?>"></iframe>
            <p class="viewer__fallback muted small">Aperçu converti automatiquement : la mise en page peut différer légèrement de l’original. Pour modifier le document, utilisez le bouton Télécharger.</p>
          <?php else: $html = $abs ? office_text_html($abs) : ''; ?>
            <div class="viewer__office">
              <?php alert('info', 'Aperçu simplifié (texte uniquement). Téléchargez le document pour voir la mise en page, les images et les tableaux.'); ?>
              <article class="office-text"><?= $html !== '' ? $html : '<p class="muted">Ce document ne contient pas de texte affichable.</p>' ?></article>
            </div>
          <?php endif;
          break;
        case 'text': ?>
          <iframe class="viewer__frame viewer__frame--text" title="<?= h(display_name($path)) ?>" src="<?= h($fileSrc) ?>"></iframe>
          <?php break;
    endswitch; ?>
  </div>
</div>
<?php
page_end();
