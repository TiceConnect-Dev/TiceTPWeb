<?php
/**
 * Mise à jour de TPWeb depuis l'interface (administrateur) :
 *  1. sauvegarde automatique (réglages + copie du code actuel, pour revenir en arrière) ;
 *  2. contrôle de l'archive (TPWeb/config.php, numéro de version, chemins sûrs) ;
 *  3. copie des nouveaux fichiers sur l'application, en gardant les réglages personnalisés de config.php.
 * Les données (cours, rendus, réglages) ne sont jamais touchées. Si le dossier de l'application
 * est en lecture seule (Docker « :ro »), la page explique comment faire sur le serveur.
 */

function app_dir()
{
    return dirname(__DIR__);
}

/** Le dossier de l'application est-il modifiable par PHP ? (test réel d'écriture) */
function app_writable()
{
    $dir = app_dir();
    $probe = $dir . DIRECTORY_SEPARATOR . '.tpw-write-test-' . bin2hex(random_bytes(3));
    if (!@file_put_contents($probe, '1')) return false;
    @unlink($probe);
    foreach (['config.php', 'index.php', 'inc' . DIRECTORY_SEPARATOR . 'bootstrap.php'] as $f) {
        if (!is_writable($dir . DIRECTORY_SEPARATOR . $f)) return false;
    }
    return true;
}

/**
 * Analyse une archive de mise à jour. Retourne ['prefix', 'version', 'files' => n, 'size'] ou un message d'erreur.
 */
function update_inspect($zipFile)
{
    if (!class_exists('ZipArchive')) return 'L’extension PHP « zip » n’est pas activée.';
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) return 'Archive illisible (ce n’est pas un fichier .zip valide).';
    $prefix = null;
    foreach (['TPWeb/', ''] as $p) {
        if ($zip->locateName($p . 'config.php') !== false && $zip->locateName($p . 'inc/bootstrap.php') !== false && $zip->locateName($p . 'index.php') !== false) { $prefix = $p; break; }
    }
    if ($prefix === null) { $zip->close(); return 'Ce fichier n’est pas une version de TPWeb (TPWeb/config.php introuvable).'; }
    $config = (string) $zip->getFromName($prefix . 'config.php');
    if (!preg_match("/define\\('TPW_VERSION',\\s*'([^']+)'\\)/", $config, $m)) { $zip->close(); return 'Numéro de version introuvable dans config.php.'; }
    $files = 0;
    $size = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        $name = str_replace('\\', '/', $st['name']);
        if ($prefix !== '' && strpos($name, $prefix) !== 0) continue;
        $rel = substr($name, strlen($prefix));
        if ($rel === '' || substr($rel, -1) === '/') continue;
        if (strpos($rel, '..') !== false || $rel[0] === '/' || preg_match('/[\x00-\x1f:]/', $rel)) { $zip->close(); return 'Archive refusée : chemin suspect « ' . $rel . ' ».'; }
        $files++;
        $size += $st['size'];
    }
    $zip->close();
    if ($size > 200 * 1024 * 1024) return 'Archive trop volumineuse pour une mise à jour (200 Mo maximum décompressés).';
    return ['prefix' => $prefix, 'version' => $m[1], 'files' => $files, 'size' => $size];
}

/** Reporte dans le nouveau config.php les réglages modifiés de l'ancien (sauf la version). */
function update_merge_config($old, $new)
{
    preg_match_all("/^define\\('([A-Z_]+)',.*\\);[ \\t]*$/m", $old, $om, PREG_SET_ORDER);
    foreach ($om as $line) {
        if ($line[1] === 'TPW_VERSION') continue;
        $pattern = "/^define\\('" . $line[1] . "',.*\\);[ \\t]*$/m";
        if (preg_match($pattern, $new)) $new = preg_replace_callback($pattern, function () use ($line) { return $line[0]; }, $new, 1);
    }
    return $new;
}

/** Sauvegarde avant mise à jour : réglages + code actuel de l'application. */
function update_backup()
{
    if (!class_exists('ZipArchive')) return false;
    $name = 'tpweb-' . date('Ymd-His') . '-avant-maj.zip';
    $zip = new ZipArchive();
    if ($zip->open(TPW_BACKUPS . DIRECTORY_SEPARATOR . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $zip->addFromString('tpweb-backup.json', json_encode(['app' => 'TPWeb', 'version' => TPW_VERSION, 'type' => 'avant-maj', 'date' => date('c')], JSON_PRETTY_PRINT));
    $zip->addEmptyDir('config');
    zip_add_tree($zip, TPW_STATE, 'config/', ['login_attempts.json', 'secrets.json', 'autobackup.lock', 'maintenance.json']);
    $zip->addEmptyDir('application');
    zip_add_tree($zip, app_dir(), 'application/TPWeb/');
    return $zip->close() ? $name : false;
}

/** Installe la mise à jour. Retourne ['version', 'files', 'backup'] ou un message d'erreur. */
function update_apply($zipFile)
{
    @set_time_limit(0);
    $info = update_inspect($zipFile);
    if (is_string($info)) return $info;
    if (!app_writable()) return 'Le dossier de l’application est en lecture seule : suivez la procédure manuelle ci-dessous.';
    $backup = update_backup();
    if (!$backup) return 'La sauvegarde préalable a échoué : mise à jour annulée.';

    // 1. Extraction complète dans un dossier temporaire (rien n'est modifié si elle échoue).
    $tmp = TPW_CACHE . DIRECTORY_SEPARATOR . 'update-' . bin2hex(random_bytes(4));
    if (!@mkdir($tmp, 0750, true)) return 'Impossible de créer le dossier temporaire.';
    $zip = new ZipArchive();
    $zip->open($zipFile);
    $list = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', $zip->getNameIndex($i));
        if ($info['prefix'] !== '' && strpos($name, $info['prefix']) !== 0) continue;
        $rel = substr($name, strlen($info['prefix']));
        if ($rel === '' || substr($rel, -1) === '/') continue;
        $dest = $tmp . '/' . $rel;
        if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0750, true);
        $data = $zip->getFromIndex($i);
        if ($data === false || @file_put_contents($dest, $data) === false) { $zip->close(); remove_tree($tmp); return 'Extraction impossible (« ' . $rel . ' »). Rien n’a été modifié.'; }
        $list[] = $rel;
    }
    $zip->close();

    // 2. config.php : garder les réglages locaux.
    $oldCfg = (string) @file_get_contents(app_dir() . '/config.php');
    @file_put_contents($tmp . '/config.php', update_merge_config($oldCfg, (string) file_get_contents($tmp . '/config.php')));

    // 3. Copie (index.php et admin.php en dernier pour limiter les états intermédiaires).
    usort($list, function ($a, $b) {
        $wa = in_array($a, ['index.php', 'admin.php'], true) ? 1 : 0;
        $wb = in_array($b, ['index.php', 'admin.php'], true) ? 1 : 0;
        return $wa - $wb ?: strcmp($a, $b);
    });
    $n = 0;
    $failed = [];
    foreach ($list as $rel) {
        $dest = app_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) { $failed[] = $rel; continue; }
        if (@copy($tmp . '/' . $rel, $dest . '.tpwnew') && @rename($dest . '.tpwnew', $dest)) $n++;
        else { @unlink($dest . '.tpwnew'); $failed[] = $rel; }
    }
    remove_tree($tmp);
    if (function_exists('opcache_reset')) @opcache_reset();
    audit_log('update_apply', ['de' => TPW_VERSION, 'vers' => $info['version'], 'fichiers' => $n, 'sauvegarde' => $backup]);
    if ($failed) return 'Mise à jour incomplète : ' . count($failed) . ' fichier(s) non copiés (' . implode(', ', array_slice($failed, 0, 5)) . '…). Sauvegarde : ' . $backup . '.';
    return ['version' => $info['version'], 'files' => $n, 'backup' => $backup];
}

function view_update()
{
    $writable = app_writable();
    $zipOk = class_exists('ZipArchive');
    $last = array_values(array_filter(backup_list(), function ($b) { return $b['type'] === 'avant-maj'; }));
    ?>
    <div class="page-head">
      <div>
        <h1>Mise à jour</h1>
        <p class="muted">Version installée : <strong>TPWeb <?= h(TPW_VERSION) ?></strong> · PHP <?= h(PHP_VERSION) ?></p>
      </div>
    </div>

    <section class="card">
      <h2 class="h4"><?= icon('refresh') ?> Installer une nouvelle version</h2>
      <p class="small">Envoyez l’archive <code>TPWeb_Vxx.zip</code>. TPWeb fait d’abord une <strong>sauvegarde</strong> (réglages et code actuel), vérifie l’archive, puis remplace les fichiers de l’application. Vos cours, rendus, comptes et réglages ne sont pas modifiés ; les réglages personnalisés de <code>config.php</code> sont conservés.</p>
      <?php if (!$zipOk): ?>
        <?php alert('error', 'L’extension PHP « zip » n’est pas activée : la mise à jour depuis l’interface est impossible.'); ?>
      <?php elseif (getenv('TPW_DOCKER_IMAGE')): ?>
        <?php alert('info', 'TPWeb tourne avec l’image Docker ' . getenv('TPW_DOCKER_IMAGE') . '. Pour mettre à jour : dans Portainer, ouvrez la stack (ou le conteneur) et faites « Update / Re-pull image », puis redéployez. Les données (volume TPWebData) sont conservées.', 'Mise à jour par l’image Docker'); ?>
        <p class="muted small">Faites une sauvegarde depuis <a href="<?= h(admin_url('backups')) ?>">Sauvegardes</a> avant, par précaution. Chaque nouvelle version publiée sur GitHub produit automatiquement une nouvelle image <code>:latest</code>.</p>
      <?php elseif (!$writable): ?>
        <?php alert('info', 'Le dossier de l’application est en lecture seule pour PHP (c’est le cas avec Docker et un volume « :ro », ce qui est plus sûr). Mettez à jour sur le serveur :', 'Mise à jour manuelle'); ?>
        <ol class="small update-steps">
          <li>Faites une sauvegarde depuis <a href="<?= h(admin_url('backups')) ?>">Sauvegardes</a> (par précaution).</li>
          <li>Copiez l’archive sur le serveur puis décompressez-la à la place de l’ancienne version :
            <pre class="code">cd /opt/tpweb
cp TPWeb/config.php config.php.bak
unzip -o TPWeb_Vxx.zip        # remplace le dossier TPWeb/
# si vous aviez modifié config.php : comparez avec config.php.bak</pre></li>
          <li>Rechargez cette page : la nouvelle version s’affiche en haut. Pas besoin de redémarrer le conteneur.</li>
        </ol>
        <p class="muted small">Pour autoriser la mise à jour depuis l’interface, retirez « :ro » du volume de l’application dans Portainer (et donnez les droits à l’utilisateur www-data) — au prix d’une sécurité moindre.</p>
        <form method="post" enctype="multipart/form-data" class="form form--inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_check">
          <div class="field"><label for="upd-zip-c">Vérifier une archive avant de l’installer à la main</label>
            <input id="upd-zip-c" class="input" type="file" name="archive" accept=".zip,application/zip" required></div>
          <button class="btn btn--secondary" type="submit">Vérifier</button>
        </form>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="form form--tight" data-confirm="Installer cette version ? Une sauvegarde est faite avant ; le portail peut être indisponible quelques secondes.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_apply">
          <div class="field"><label for="upd-zip">Archive de la nouvelle version <span class="hint">.zip, <?= h(human_size(upload_limit())) ?> maximum</span></label>
            <input id="upd-zip" class="input" type="file" name="archive" accept=".zip,application/zip" required></div>
          <div class="form__actions"><button class="btn" type="submit"><?= icon('refresh') ?>Sauvegarder puis mettre à jour</button></div>
        </form>
      <?php endif; ?>
    </section>

    <?php if ($last): ?>
      <section class="card">
        <h2 class="h4"><?= icon('archive') ?> Sauvegardes avant mise à jour</h2>
        <p class="muted small">Elles contiennent les réglages et le code de la version précédente (dossier <code>application/TPWeb</code>), pour revenir en arrière si besoin.</p>
        <ul class="dash-list dash-list--compact">
          <?php foreach (array_slice($last, 0, 5) as $b): ?>
            <li><a href="admin.php?dl=backup&amp;f=<?= h(rawurlencode($b['name'])) ?>"><?= h($b['name']) ?></a> <span class="muted"><?= h(human_size($b['size'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
    <?php
}
