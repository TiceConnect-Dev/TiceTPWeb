<?php
/**
 * Sauvegardes automatiques planifiées, avec copie hors du serveur.
 *
 * settings.json › autobackup : { "enabled", "type": "reglages"|"complete", "freq": "daily"|"weekly",
 *                                "hour": 2, "keep": 7, "ext_dir": "", "webdav_url": "", "webdav_user": "" }
 * Mot de passe WebDAV : config/secrets.json (jamais inclus dans les sauvegardes).
 * État : config/autobackup_state.json { "last", "files": [...], "log": [...] }
 *
 * Déclenchement : tâche planifiée « php cron.php » (recommandé), sinon à la première visite
 * de l'espace enseignant après l'heure prévue.
 */

function autobackup_config()
{
    $cfg = settings()['autobackup'];
    $cfg += ['enabled' => false, 'type' => 'reglages', 'freq' => 'daily', 'hour' => 2, 'keep' => 7,
             'ext_dir' => '', 'webdav_url' => '', 'webdav_user' => ''];
    $cfg['type'] = $cfg['type'] === 'complete' ? 'complete' : 'reglages';
    $cfg['freq'] = $cfg['freq'] === 'weekly' ? 'weekly' : 'daily';
    $cfg['hour'] = max(0, min(23, (int) $cfg['hour']));
    $cfg['keep'] = max(1, min(60, (int) $cfg['keep']));
    return $cfg;
}

function secrets_file()
{
    return TPW_STATE . '/secrets.json';
}

function secret_get($key)
{
    $s = load_json(secrets_file(), []);
    return isset($s[$key]) ? (string) $s[$key] : '';
}

function secret_set($key, $value)
{
    $s = load_json(secrets_file(), []);
    if ($value === '') unset($s[$key]); else $s[$key] = $value;
    atomic_json(secrets_file(), $s);
}

function autobackup_state()
{
    $st = load_json(TPW_STATE . '/autobackup_state.json', []);
    return $st + ['last' => 0, 'files' => [], 'log' => []];
}

function autobackup_save_state(array $st)
{
    $st['log'] = array_slice($st['log'], -20);
    atomic_json(TPW_STATE . '/autobackup_state.json', $st);
}

/** Prochaine échéance (horodatage) après la dernière sauvegarde. */
function autobackup_next(array $cfg, array $st)
{
    $interval = $cfg['freq'] === 'weekly' ? 7 : 1;
    $base = $st['last'] ? strtotime(date('Y-m-d', $st['last'])) + $interval * 86400 : strtotime(date('Y-m-d'));
    $next = $base + $cfg['hour'] * 3600;
    if (!$st['last'] && $next < time() - 86400) $next = time();
    return $next;
}

function autobackup_due()
{
    $cfg = autobackup_config();
    if (!$cfg['enabled'] || !zip_available()) return false;
    return time() >= autobackup_next($cfg, autobackup_state());
}

/** Copie une sauvegarde vers un dossier externe (partage réseau monté, disque USB…). */
function autobackup_copy_dir($file, $dir)
{
    $dir = rtrim($dir, '/\\');
    if (!is_dir($dir) || !is_writable($dir)) return 'dossier externe inaccessible (' . $dir . ')';
    if (realpath($dir) !== false && strpos(realpath($dir), realpath(TPW_DATA)) === 0) return 'le dossier externe ne doit pas être dans TPWebData';
    if (!@copy($file, $dir . DIRECTORY_SEPARATOR . basename($file))) return 'copie vers le dossier externe impossible';
    return '';
}

/** Envoie une sauvegarde par WebDAV (Nextcloud, NAS Synology/QNAP, apps.education.fr…). */
function autobackup_copy_webdav($file, $url, $user, $pass)
{
    if (!function_exists('curl_init')) return 'extension curl absente';
    $target = rtrim($url, '/') . '/' . rawurlencode(basename($file));
    $fh = fopen($file, 'rb');
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_PUT => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($file),
        CURLOPT_USERPWD => $user . ':' . $pass, CURLOPT_HTTPAUTH => CURLAUTH_BASIC | CURLAUTH_DIGEST,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3600, CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    if (function_exists('ca_bundle') && ca_bundle()) curl_setopt($ch, CURLOPT_CAINFO, ca_bundle());
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if ($err !== '') return 'WebDAV : ' . $err;
    if ($code < 200 || $code >= 300) return 'WebDAV : réponse HTTP ' . $code . ($code === 401 ? ' (identifiants refusés)' : ($code === 404 || $code === 409 ? ' (dossier distant introuvable)' : ''));
    return '';
}

/** Lance une sauvegarde automatique (ou manuelle avec $force). Retourne la ligne de journal. */
function autobackup_run($force = false)
{
    if (!$force && !autobackup_due()) return null;
    $cfg = autobackup_config();
    $lock = @fopen(TPW_STATE . '/autobackup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return null;
    @set_time_limit(0);
    @ignore_user_abort(true);

    $st = autobackup_state();
    $st['last'] = time();
    autobackup_save_state($st); // évite un second déclenchement pendant la sauvegarde

    $entry = ['time' => time(), 'file' => '', 'ok' => false, 'msg' => ''];
    $name = backup_create($cfg['type']);
    if (!$name) {
        $entry['msg'] = 'échec de la création de l’archive';
    } else {
        $entry['file'] = $name;
        $entry['ok'] = true;
        $st['files'][] = $name;
        $full = TPW_BACKUPS . DIRECTORY_SEPARATOR . $name;
        $notes = [];
        if ($cfg['ext_dir'] !== '') {
            $e = autobackup_copy_dir($full, $cfg['ext_dir']);
            $notes[] = $e === '' ? 'copiée dans le dossier externe' : $e;
            if ($e !== '') $entry['ok'] = false;
        }
        if ($cfg['webdav_url'] !== '') {
            $e = autobackup_copy_webdav($full, $cfg['webdav_url'], $cfg['webdav_user'], secret_get('webdav_pass'));
            $notes[] = $e === '' ? 'envoyée en WebDAV' : $e;
            if ($e !== '') $entry['ok'] = false;
        }
        $entry['msg'] = $notes ? implode(' ; ', $notes) : 'conservée sur le serveur';
        // Rotation : on ne garde que les N dernières sauvegardes automatiques.
        $st['files'] = array_values(array_filter($st['files'], function ($f) {
            return is_file(TPW_BACKUPS . DIRECTORY_SEPARATOR . $f);
        }));
        while (count($st['files']) > $cfg['keep']) {
            $old = array_shift($st['files']);
            if (backup_name_ok($old)) @unlink(TPW_BACKUPS . DIRECTORY_SEPARATOR . $old);
        }
    }
    $st['log'][] = $entry;
    autobackup_save_state($st);
    audit_log('backup_auto', ['fichier' => $entry['file'], 'résultat' => $entry['ok'] ? 'réussie' : 'à vérifier', 'détail' => $entry['msg']], 'système');
    flock($lock, LOCK_UN);
    fclose($lock);
    return $entry;
}
