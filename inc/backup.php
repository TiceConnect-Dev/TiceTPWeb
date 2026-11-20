<?php
/**
 * Sauvegarde et restauration de TPWebData (archives ZIP dans TPWebData/sauvegardes).
 *  - « réglages » : config/ (publications, classes, codes, comptes, dépôts) ;
 *  - « complète » : config/ + cours/ + rendus/.
 */

function zip_available()
{
    return class_exists('ZipArchive');
}

function backup_name_ok($name)
{
    return is_string($name) && preg_match('/^tpweb-\d{8}-\d{6}-(reglages|complete|avant-restauration|avant-maj)\.zip$/D', $name);
}

function backup_list()
{
    $out = [];
    foreach ((array) @scandir(TPW_BACKUPS) as $name) {
        if (!backup_name_ok($name)) continue;
        $full = TPW_BACKUPS . DIRECTORY_SEPARATOR . $name;
        $out[] = ['name' => $name, 'size' => filesize($full), 'time' => filemtime($full), 'type' => preg_replace('/^.*-(\w[\w-]*)\.zip$/', '$1', $name)];
    }
    usort($out, function ($a, $b) {
        return $b['time'] - $a['time'];
    });
    return $out;
}

function backup_type_label($type)
{
    $labels = ['reglages' => 'Réglages', 'complete' => 'Complète', 'avant-restauration' => 'Avant restauration', 'avant-maj' => 'Avant mise à jour'];
    return isset($labels[$type]) ? $labels[$type] : $type;
}

/** Ajoute récursivement un dossier à l'archive sous le préfixe donné. */
function zip_add_tree(ZipArchive $zip, $dir, $prefix, array $skip = [])
{
    foreach ((array) @scandir($dir) as $item) {
        if ($item === '.' || $item === '..' || in_array($item, $skip, true)) continue;
        $full = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_link($full)) continue;
        if (is_dir($full)) {
            $zip->addEmptyDir($prefix . $item);
            zip_add_tree($zip, $full, $prefix . $item . '/');
        } elseif (is_file($full)) {
            $zip->addFile($full, $prefix . $item);
        }
    }
}

/** Crée une sauvegarde ; retourne son nom ou false. */
function backup_create($type)
{
    if (!zip_available()) return false;
    @set_time_limit(0);
    $name = 'tpweb-' . date('Ymd-His') . '-' . $type . '.zip';
    $path = TPW_BACKUPS . DIRECTORY_SEPARATOR . $name;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $zip->addFromString('tpweb-backup.json', json_encode([
        'app' => 'TPWeb', 'version' => TPW_VERSION, 'type' => $type, 'date' => date('c'),
    ], JSON_PRETTY_PRINT));
    $zip->addEmptyDir('config');
    zip_add_tree($zip, TPW_STATE, 'config/', ['login_attempts.json', 'secrets.json', 'autobackup.lock', 'maintenance.json']);
    if ($type === 'complete') {
        $zip->addEmptyDir('cours');
        zip_add_tree($zip, TPW_COURSES, 'cours/');
        $zip->addEmptyDir('rendus');
        zip_add_tree($zip, TPW_RENDUS, 'rendus/');
    }
    return $zip->close() ? $name : false;
}

/**
 * Restaure une archive TPWeb. Les fichiers présents dans l'archive remplacent
 * ceux du serveur ; les autres sont conservés. Une copie des réglages actuels est faite avant.
 * Retourne le nombre de fichiers restaurés, ou un message d'erreur (string).
 */
function backup_restore($zipFile)
{
    if (!zip_available()) return 'L’extension PHP « zip » n’est pas activée.';
    @set_time_limit(0);

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) return 'Archive illisible.';
    if ($zip->locateName('tpweb-backup.json') === false) {
        $zip->close();
        return 'Ce fichier n’est pas une sauvegarde TPWeb.';
    }

    backup_create('avant-restauration');

    $roots = ['config' => TPW_STATE, 'cours' => TPW_COURSES, 'rendus' => TPW_RENDUS];
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if (substr($name, -1) === '/') continue;
        $parts = explode('/', $name, 2);
        if (count($parts) !== 2 || !isset($roots[$parts[0]]) || !valid_path($parts[1]) || $parts[1] === '') continue;
        if ($parts[0] === 'config' && in_array($parts[1], ['login_attempts.json', 'secrets.json'], true)) continue;

        $target = $roots[$parts[0]] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $parts[1]);
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) continue;
        if (is_link($target)) continue;

        $in = $zip->getStream($zip->getNameIndex($i));
        $out = @fopen($target, 'wb');
        if ($in && $out) {
            stream_copy_to_stream($in, $out);
            $count++;
        }
        if ($in) fclose($in);
        if ($out) fclose($out);
    }
    $zip->close();
    users_all(true);
    return $count;
}
