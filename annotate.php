<?php
/**
 * Annotation d'une copie rendue (enseignant) : stylo, surligneur, texte, ✓ / ✗, gomme.
 * Le PDF annoté est fabriqué dans le navigateur (pdf.js + pdf-lib) puis enregistré à côté du rendu ;
 * les annotations restent modifiables (fichier .json à côté du PDF corrigé).
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!logged()) redirect('admin.php');
$user = current_user();
$item = query('item');
$file = query('f');
$cfg = deposit_for($item);
if (!$cfg || !can('deposits', $user) || !can_manage($item, $user)) fail(404, 'Dépôt introuvable', 'Ce dépôt n’existe pas ou ne vous est pas attribué.');
$cfg = deposit_normalize($cfg);
$entry = null;
foreach (deposit_entries($cfg) as $e) if ($e['file'] === $file) $entry = $e;
if (!$entry || !deposit_file_path($cfg, $file)) fail(404, 'Rendu introuvable', 'Ce rendu n’existe plus.');
if (!deposit_annotatable($file)) fail(415, 'Format non annotable', 'Seuls les PDF, les images et (si LibreOffice est installé) les documents bureautiques peuvent être annotés.');

// Annotations déjà faites (pour reprendre la correction).
$prev = [];
$jsonFile = deposit_file_path($cfg, preg_replace('/\.pdf$/', '.json', deposit_correction_name($file)));
if ($jsonFile) $prev = load_json($jsonFile, []);

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$kind = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) ? 'image' : 'pdf';
$src = 'admin.php?dl=rendu&item=' . rawurlencode($item) . '&f=' . rawurlencode($file) . '&raw=1';
$back = admin_url('deposits', $item);
$who = deposit_members_text($entry) . ' (' . $entry['level'] . ')';
release_session();

page_top('Annoter — ' . $who, 'admin');
?>
<div class="annot" data-annot
     data-src="<?= h($src) ?>" data-kind="<?= h($kind) ?>" data-ext="<?= h($ext) ?>"
     data-folder="<?= h($item) ?>" data-file="<?= h($file) ?>" data-back="<?= h($back) ?>"
     data-csrf="<?= h(csrf_token()) ?>" data-public="<?= $cfg['feedback_public'] ? '1' : '0' ?>">
  <script type="application/json" data-annot-prev><?= json_encode($prev, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <div class="annot__bar" role="toolbar" aria-label="Outils d’annotation">
    <a class="btn btn--tertiary btn--sm" href="<?= h($back) ?>"><?= icon('back') ?>Retour</a>
    <span class="annot__who"><strong><?= h($who) ?></strong> <span class="muted small"><?= h($entry['orig']) ?></span></span>
    <span class="annot__tools">
      <button type="button" class="annot__tool is-active" data-tool="pen" title="Stylo (P)" aria-pressed="true"><?= icon('pen') ?><span>Stylo</span></button>
      <button type="button" class="annot__tool" data-tool="hl" title="Surligneur (S)" aria-pressed="false"><span class="annot__hl" aria-hidden="true"></span><span>Surligner</span></button>
      <button type="button" class="annot__tool" data-tool="text" title="Texte (T)" aria-pressed="false"><strong aria-hidden="true">T</strong><span>Texte</span></button>
      <button type="button" class="annot__tool" data-tool="check" title="Juste" aria-pressed="false"><span class="annot__ok" aria-hidden="true">✓</span><span class="sr-only">Juste</span></button>
      <button type="button" class="annot__tool" data-tool="cross" title="Faux" aria-pressed="false"><span class="annot__ko" aria-hidden="true">✗</span><span class="sr-only">Faux</span></button>
      <button type="button" class="annot__tool" data-tool="erase" title="Gomme : cliquez sur une annotation (E)" aria-pressed="false"><?= icon('trash') ?><span>Gomme</span></button>
    </span>
    <span class="annot__opts">
      <span class="annot__colors" role="radiogroup" aria-label="Couleur">
        <?php foreach (['#e1000f' => 'Rouge', '#000091' => 'Bleu', '#18753c' => 'Vert', '#000000' => 'Noir'] as $c => $l): ?>
          <button type="button" class="annot__color<?= $c === '#e1000f' ? ' is-active' : '' ?>" data-color="<?= $c ?>" style="--c:<?= $c ?>" title="<?= $l ?>" aria-label="<?= $l ?>"></button>
        <?php endforeach; ?>
      </span>
      <label class="annot__size" title="Épaisseur / taille"><span class="sr-only">Taille</span>
        <select data-size class="input input--sm"><option value="1">Fin</option><option value="2" selected>Moyen</option><option value="3">Épais</option></select></label>
      <button type="button" class="btn btn--tertiary btn--sm" data-undo title="Annuler (Ctrl+Z)"><?= icon('back') ?>Annuler</button>
      <button type="button" class="btn btn--sm" data-save><?= icon('check') ?>Enregistrer la copie annotée</button>
    </span>
  </div>
  <p class="annot__status muted small" data-status role="status">Chargement de la copie…</p>
  <div class="annot__pages" data-pages></div>
</div>
<script src="assets/vendor/pdf.min.js"></script>
<script src="assets/vendor/pdf-lib.min.js"></script>
<script src="assets/annotate.js?v=<?= h(TPW_VERSION) ?>"></script>
<?php
page_end();
