<?php
/**
 * Dépôt des travaux des élèves.
 *
 * Configuration (config/deposits.json), une entrée par dossier :
 *   { "id", "levels": [classes ou groupes], "from", "until", "max_mb", "exts", "note", "owner",
 *     "late": "refuse"|"accept", "late_until", "group_max" }
 * Rendus : TPWebData/rendus/<id>/<classe>/<fichier> + rendus/<id>/index.json
 * Chaque rendu : élève(s), fichier, taille, date, empreinte SHA-1, numéro de reçu, retard.
 */

const DEPOSIT_DEFAULT_EXTS = 'pdf, odt, docx, ods, xlsx, odp, pptx, zip, txt, png, jpg';

function deposit_for($path)
{
    $all = deposits_config();
    return isset($all[$path]) && is_array($all[$path]) ? deposit_normalize($all[$path]) : null;
}

function deposit_normalize(array $cfg)
{
    $cfg += ['id' => '', 'levels' => [], 'from' => '', 'until' => '', 'max_mb' => 20, 'exts' => DEPOSIT_DEFAULT_EXTS, 'note' => '', 'owner' => '',
             'late' => 'refuse', 'late_until' => '', 'group_max' => 1, 'feedback_public' => false, 'reminders' => [], 'solution' => ''];
    if (!is_array($cfg['reminders'])) $cfg['reminders'] = [];
    if (!is_array($cfg['levels'])) $cfg['levels'] = [];
    if (!in_array($cfg['late'], ['refuse', 'accept'], true)) $cfg['late'] = 'refuse';
    $cfg['group_max'] = max(1, min(5, (int) $cfg['group_max']));
    return $cfg;
}

/** Le dépôt est-il ouvert à cet élève (classe ou l'un de ses groupes) ? */
function deposit_for_viewer($cfg, $level, $groups = null)
{
    return $cfg && !empty($cfg['enabled']) && target_match($cfg['levels'], $level, $groups);
}

/** Liste des extensions autorisées (minuscules, sans point). */
function deposit_exts(array $cfg)
{
    $exts = [];
    foreach (preg_split('/[\s,;]+/', strtolower((string) $cfg['exts'])) as $e) {
        $e = ltrim($e, '.');
        if ($e !== '' && preg_match('/^[a-z0-9]{1,10}$/', $e) && !blocked_extension('x.' . $e)) $exts[] = $e;
    }
    return array_values(array_unique($exts));
}

/** État lisible : 'open', 'late' (date passée, retards acceptés), 'scheduled', 'closed'. */
function deposit_state(array $cfg)
{
    $cfg = deposit_normalize($cfg);
    $now = time();
    $from = parse_datetime($cfg['from']);
    $until = parse_datetime($cfg['until']);
    if ($from !== null && $now < $from) return 'scheduled';
    if ($until !== null && $now >= $until) {
        if ($cfg['late'] !== 'accept') return 'closed';
        $hard = parse_datetime($cfg['late_until']);
        return ($hard !== null && $now >= $hard) ? 'closed' : 'late';
    }
    return 'open';
}

/** Le dépôt accepte-t-il des fichiers maintenant ? */
function deposit_accepting(array $cfg)
{
    return in_array(deposit_state($cfg), ['open', 'late'], true);
}

/** Numéro de reçu lisible (sans caractères ambigus) : R-7KQ2-9XFM. */
function deposit_receipt_code()
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 8; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return 'R-' . substr($out, 0, 4) . '-' . substr($out, 4);
}

/** Noms des membres d'un rendu : [['last','first'], …] (le déposant en premier). */
function deposit_members(array $e)
{
    if (!empty($e['members']) && is_array($e['members'])) return $e['members'];
    return [['last' => $e['last'], 'first' => $e['first']]];
}

function deposit_members_text(array $e)
{
    return implode(', ', array_map(function ($m) {
        return mb_strtoupper($m['last']) . ' ' . $m['first'];
    }, deposit_members($e)));
}

/**
 * Rendus identiques déposés par des élèves différents : empreinte => [indices].
 * Les empreintes manquantes (anciens rendus) sont calculées à la volée.
 */
function deposit_duplicates(array $cfg, array &$entries)
{
    $by = [];
    foreach ($entries as $i => $e) {
        if (empty($e['hash'])) {
            $full = deposit_file_path($cfg, $e['file']);
            $entries[$i]['hash'] = $full && filesize($full) < 200 * 1024 * 1024 ? sha1_file($full) : '';
        }
        if ($entries[$i]['hash'] === '' || $entries[$i]['size'] < 64) continue;
        $by[$entries[$i]['hash']][] = $i;
    }
    $out = [];
    foreach ($by as $hash => $idx) {
        $people = [];
        foreach ($idx as $i) $people[mb_strtolower($entries[$i]['level'] . '|' . $entries[$i]['last'] . ' ' . $entries[$i]['first'])] = true;
        if (count($people) > 1) $out[$hash] = $idx;
    }
    return $out;
}

function deposit_max_bytes(array $cfg)
{
    return (int) min(max(1, (int) $cfg['max_mb']) * 1024 * 1024, upload_limit());
}

function deposit_dir(array $cfg)
{
    if (!preg_match('/^[a-f0-9]{12,40}$/', (string) $cfg['id'])) return false;
    return TPW_RENDUS . DIRECTORY_SEPARATOR . $cfg['id'];
}

/** Entrées du dépôt (les plus récentes en premier). */
function deposit_entries(array $cfg)
{
    $dir = deposit_dir($cfg);
    if (!$dir) return [];
    $list = load_json($dir . '/index.json', []);
    usort($list, function ($a, $b) {
        return $b['time'] - $a['time'];
    });
    return $list;
}

/** Modifie l'index d'un dépôt sous verrou exclusif. */
function deposit_update_index(array $cfg, callable $change)
{
    $dir = deposit_dir($cfg);
    if (!$dir) return false;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return false;

    $lock = fopen($dir . '/index.lock', 'c');
    if (!$lock) return false;
    flock($lock, LOCK_EX);
    $list = load_json($dir . '/index.json', []);
    $list = $change($list);
    atomic_json($dir . '/index.json', array_values($list));
    flock($lock, LOCK_UN);
    fclose($lock);
    return true;
}

/** Nom propre d'un élève : lettres, espaces, tirets, apostrophes. */
function clean_person_name($value)
{
    $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
    if ($value === '' || mb_strlen($value) > 60) return '';
    if (!preg_match("/^[\\p{L}][\\p{L}\\p{M} '’.-]*$/u", $value)) return '';
    return $value;
}

/** Fragment de nom de fichier ASCII. */
function slug($value)
{
    $value = (string) $value;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($t !== false) $value = $t;
    }
    $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value);
    return trim((string) $value, '-');
}

/**
 * Enregistre les fichiers envoyés par un élève.
 * Retourne ['ok' => [noms...], 'errors' => [messages...]].
 */
function deposit_store(array $cfg, $level, $last, $first, array $files, array $members = [], $studentId = '')
{
    $result = ['ok' => [], 'errors' => [], 'receipt' => ''];
    $late = deposit_state($cfg) === 'late';
    $receipt = deposit_receipt_code();
    $allMembers = array_merge([['last' => $last, 'first' => $first]], $members);
    $dir = deposit_dir($cfg);
    $levelDir = $dir ? $dir . DIRECTORY_SEPARATOR . (slug($level) !== '' ? slug($level) : 'classe') : false;
    if (!$levelDir || (!is_dir($levelDir) && !@mkdir($levelDir, 0750, true))) {
        $result['errors'][] = 'Le dossier des rendus est inaccessible. Prévenez votre professeur.';
        return $result;
    }

    $exts = deposit_exts($cfg);
    $max = deposit_max_bytes($cfg);
    $entries = [];

    foreach (array_slice($files, 0, 10) as $i => $f) {
        $orig = safe_name($f['name']);
        $label = $orig !== '' ? $orig : 'fichier ' . ($i + 1);
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $result['errors'][] = $label . ' : ' . upload_error_message($f['error']) . '.';
            continue;
        }
        if ($orig === '' || !is_uploaded_file($f['tmp_name'])) {
            $result['errors'][] = $label . ' : nom de fichier invalide.';
            continue;
        }
        $ext = file_ext($orig);
        if ($exts && !in_array($ext, $exts, true)) {
            $result['errors'][] = $label . ' : format non accepté (formats autorisés : ' . implode(', ', $exts) . ').';
            continue;
        }
        if ($f['size'] > $max) {
            $result['errors'][] = $label . ' : fichier trop volumineux (maximum ' . human_size($max) . ').';
            continue;
        }

        $quota = quota_error_for_path('rendus', (int) $f['size']);
        if ($quota !== '') {
            $result['errors'][] = $label . ' : espace de stockage du serveur insuffisant. Prévenez votre professeur.';
            continue;
        }

        $stored = strtoupper(slug($last)) . '_' . slug($first) . '_' . date('Ymd-His') . '_' . bin2hex(random_bytes(2)) . ($ext !== '' ? '.' . $ext : '');
        $target = $levelDir . DIRECTORY_SEPARATOR . $stored;
        if (!move_uploaded_file($f['tmp_name'], $target)) {
            $result['errors'][] = $label . ' : erreur d’enregistrement.';
            continue;
        }
        $entry = [
            'level' => $level, 'last' => $last, 'first' => $first,
            'file' => basename($levelDir) . '/' . $stored, 'orig' => $orig,
            'size' => (int) $f['size'], 'time' => time(),
            'hash' => (string) @sha1_file($target), 'receipt' => $receipt, 'late' => $late,
        ];
        if (count($allMembers) > 1) $entry['members'] = $allMembers;
        if ($studentId !== '') $entry['student'] = $studentId;
        $entries[] = $entry;
        $result['ok'][] = $orig;
    }

    if ($entries) {
        deposit_update_index($cfg, function ($list) use ($entries) {
            return array_merge($list, $entries);
        });
        $result['receipt'] = $receipt;
        $result['late'] = $late;
    }
    return $result;
}

/** Chemin absolu d'un rendu (validé), ou false. */
function deposit_file_path(array $cfg, $file)
{
    $dir = deposit_dir($cfg);
    if (!$dir || !preg_match('~^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$~', (string) $file)) return false;
    $full = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    return is_file($full) && !is_link($full) ? $full : false;
}

function deposit_delete_entry(array $cfg, $file)
{
    $full = deposit_file_path($cfg, $file);
    if ($full) @unlink($full);
    deposit_update_index($cfg, function ($list) use ($file) {
        return array_filter($list, function ($e) use ($file) {
            return $e['file'] !== $file;
        });
    });
}

function deposit_delete_all(array $cfg)
{
    $dir = deposit_dir($cfg);
    if ($dir && is_dir($dir)) remove_tree($dir);
}

/** Nom lisible d'un rendu dans une archive : Classe/NOM Prénom - fichier.ext */
function deposit_archive_name(array $e)
{
    $who = count(deposit_members($e)) > 1 ? deposit_members_text($e) : mb_strtoupper($e['last']) . ' ' . $e['first'];
    $name = safe_name($who . ' - ' . date('Y-m-d H\hi', $e['time']) . (!empty($e['late']) ? ' - RETARD' : '') . ' - ' . $e['orig']);
    return (safe_name($e['level']) ?: 'Classe') . '/' . ($name !== '' ? $name : basename($e['file']));
}

/* ---------- Suivi par élève, notes et relances ---------- */

/** Clé d'un élève identifié par son nom (rendus anonymes). */
function deposit_name_key($level, $last, $first)
{
    return 'n:' . md5(fold_text($level . '|' . trim($last) . '|' . trim($first)));
}

/** Clés (« s:id » et « n:… ») des élèves qui ont rendu, avec leur premier et dernier rendu. */
function deposit_submitters(array $entries)
{
    $out = [];
    foreach ($entries as $e) {
        $keys = [];
        if (!empty($e['student'])) $keys[] = 's:' . $e['student'];
        foreach (deposit_members($e) as $m) $keys[] = deposit_name_key($e['level'], $m['last'], $m['first']);
        foreach ($keys as $k) {
            if (!isset($out[$k])) $out[$k] = ['first' => $e['time'], 'last' => $e['time'], 'late' => !empty($e['late']), 'n' => 0];
            $out[$k]['first'] = min($out[$k]['first'], $e['time']);
            $out[$k]['last'] = max($out[$k]['last'], $e['time']);
            $out[$k]['n']++;
        }
    }
    return $out;
}

/** Statut d'un élève de la liste pour un dépôt (null = pas rendu). */
function deposit_student_status(array $s, array $submitters)
{
    $k1 = 's:' . $s['id'];
    $k2 = deposit_name_key($s['class'], $s['last'], $s['first']);
    if (isset($submitters[$k1])) return $submitters[$k1];
    if (isset($submitters[$k2])) return $submitters[$k2];
    return null;
}

/** Élèves de la liste concernés par un dépôt (classes et groupes visés). */
function deposit_roster(array $cfg)
{
    $out = [];
    foreach (students_all() as $id => $s) {
        if (in_array($s['class'], $cfg['levels'], true)) { $out[(string) $id] = ['id' => (string) $id] + $s; continue; }
        foreach ($s['groups'] as $g) {
            if (in_array(group_key($s['class'], $g), $cfg['levels'], true)) { $out[(string) $id] = ['id' => (string) $id] + $s; break; }
        }
    }
    uasort($out, function ($a, $b) { return strnatcasecmp($a['class'], $b['class']) ?: strcasecmp($a['last'] . $a['first'], $b['last'] . $b['first']); });
    return $out;
}

function deposit_feedback(array $cfg)
{
    $dir = deposit_dir($cfg);
    return $dir ? load_json($dir . '/feedback.json', []) : [];
}

function deposit_save_feedback(array $cfg, array $fb)
{
    $dir = deposit_dir($cfg);
    if (!$dir) return;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    atomic_json($dir . '/feedback.json', $fb);
}

/** Note / commentaire d'un élève (par identifiant de la liste ou par nom). */
function deposit_feedback_for(array $fb, $studentId, $level, $last, $first)
{
    if ($studentId !== '' && isset($fb['s:' . $studentId])) return $fb['s:' . $studentId];
    $k = deposit_name_key($level, $last, $first);
    return isset($fb[$k]) ? $fb[$k] : null;
}
