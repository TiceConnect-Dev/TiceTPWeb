<?php
/**
 * Accès sécurisé au dossier des cours (aucune sortie possible hors de TPW_COURSES).
 */

/** Chemin relatif valide : séparateurs "/", ni "..", ni ".", ni segment vide. */
function valid_path($path)
{
    if (!is_string($path) || strlen($path) > 2000) return false;
    if (strpos($path, "\0") !== false || strpos($path, '\\') !== false) return false;
    if ($path === '' || $path === '.') return true;
    if ($path[0] === '/' || preg_match('~(^|/)(\.|\.\.|)(/|$)~', $path)) return false;
    return true;
}

/**
 * Chemin absolu d'un élément du dossier des cours, ou false.
 * $kind : 'dir', 'file' ou autre (n'importe quel type).
 */
function full_path($relative, $kind)
{
    if (!valid_path($relative)) return false;

    $root = realpath(TPW_COURSES);
    if ($root === false) return false;

    $suffix = $relative === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $file = realpath($root . $suffix);
    if ($file === false) return false;
    if ($file !== $root && strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) return false;
    if ($kind === 'dir' && !is_dir($file)) return false;
    if ($kind === 'file' && !is_file($file)) return false;

    // Refuse les liens symboliques sur tout le trajet.
    $walk = $root;
    foreach (explode('/', $relative) as $part) {
        if ($part === '') continue;
        $walk .= DIRECTORY_SEPARATOR . $part;
        if (is_link($walk)) return false;
    }
    return $file;
}

/** Contenu direct d'un dossier : ['dirs' => [...], 'files' => [...]] (chemins relatifs). */
function names_in($relative)
{
    $out = ['dirs' => [], 'files' => []];
    $dir = full_path($relative, 'dir');
    if (!$dir) return $out;

    foreach ((array) @scandir($dir) as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') continue;
        $sub = $relative === '' ? $name : $relative . '/' . $name;
        if (full_path($sub, 'dir')) {
            $out['dirs'][] = $sub;
        } elseif (full_path($sub, 'file')) {
            $out['files'][] = $sub;
        }
    }
    natcasesort($out['dirs']);
    natcasesort($out['files']);
    $out['dirs'] = array_values($out['dirs']);
    $out['files'] = array_values($out['files']);
    return $out;
}

/** Tous les dossiers (parcours en profondeur, ordre d'affichage). */
function folder_list()
{
    $result = [];
    $walk = function ($relative, $depth) use (&$walk, &$result) {
        if ($depth > 15 || count($result) > 3000) return;
        foreach (names_in($relative)['dirs'] as $sub) {
            $result[] = $sub;
            $walk($sub, $depth + 1);
        }
    };
    $walk('', 0);
    return $result;
}

/** Index de tous les éléments : chemin => 'dir' | 'file' (racine incluse). */
function all_items()
{
    $result = ['' => 'dir'];
    $walk = function ($relative, $depth) use (&$walk, &$result) {
        if ($depth > 15 || count($result) > 6000) return;
        $items = names_in($relative);
        foreach ($items['dirs'] as $dir) {
            $result[$dir] = 'dir';
            $walk($dir, $depth + 1);
        }
        foreach ($items['files'] as $file) {
            if (count($result) > 6000) break;
            $result[$file] = 'file';
        }
    };
    $walk('', 0);
    return $result;
}

/** Arborescence imbriquée à partir de la liste plate des dossiers. */
function folder_tree(array $folders)
{
    $tree = [];
    foreach ($folders as $folder) {
        $node = &$tree;
        foreach (explode('/', $folder) as $part) {
            $part = (string) $part;
            if (!isset($node[$part])) $node[$part] = [];
            $node = &$node[$part];
        }
        unset($node);
    }
    return $tree;
}

/* ---------- Informations d'affichage ---------- */

function file_ext($path)
{
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

function is_html_file($path)
{
    return in_array(file_ext($path), ['html', 'htm'], true);
}

/** Type de document : libellé lisible + icône. */
function file_kind($path)
{
    $ext = file_ext($path);
    $map = [
        'html' => ['Cours en ligne', 'web'],  'htm' => ['Cours en ligne', 'web'],
        'pdf'  => ['PDF', 'pdf'],
        'png'  => ['Image', 'image'], 'jpg' => ['Image', 'image'], 'jpeg' => ['Image', 'image'],
        'gif'  => ['Image', 'image'], 'webp' => ['Image', 'image'], 'svg' => ['Image', 'image'],
        'mp4'  => ['Vidéo', 'video'], 'webm' => ['Vidéo', 'video'],
        'mp3'  => ['Audio', 'audio'], 'wav' => ['Audio', 'audio'],
        'zip'  => ['Archive', 'archive'], '7z' => ['Archive', 'archive'], 'rar' => ['Archive', 'archive'],
        'doc'  => ['Document Word', 'doc'], 'docx' => ['Document Word', 'doc'], 'odt' => ['Document texte', 'doc'],
        'xls'  => ['Tableur', 'sheet'], 'xlsx' => ['Tableur', 'sheet'], 'ods' => ['Tableur', 'sheet'], 'csv' => ['Tableur', 'sheet'],
        'ppt'  => ['Présentation', 'slides'], 'pptx' => ['Présentation', 'slides'], 'odp' => ['Présentation', 'slides'],
        'txt'  => ['Texte', 'doc'],
        'url'  => ['Lien', 'link'],
        'qcm'  => ['QCM', 'quiz'], 'rtf' => ['Document texte', 'doc'], 'pps' => ['Présentation', 'slides'], 'ppsx' => ['Présentation', 'slides'],
    ];
    $kind = isset($map[$ext]) ? $map[$ext] : ['Fichier', 'file'];
    return ['label' => $kind[0], 'icon' => $kind[1], 'ext' => strtoupper($ext)];
}

function human_size($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes < 1024) return (int) $bytes . ' o';
    $units = ['Ko', 'Mo', 'Go'];
    $i = -1;
    do {
        $bytes /= 1024;
        $i++;
    } while ($bytes >= 1024 && $i < count($units) - 1);
    return number_format($bytes, $bytes < 10 ? 1 : 0, ',', ' ') . ' ' . $units[$i];
}

/** Nom affiché d'un document (sans extension). */
function display_name($path)
{
    $base = basename($path);
    $name = pathinfo($base, PATHINFO_FILENAME);
    return $name !== '' ? $name : $base;
}

/* ---------- Envoi et gestion des fichiers ---------- */

/** Taille maximale d'un envoi acceptée par PHP (octets). */
function upload_limit()
{
    $parse = function ($v) {
        $v = trim((string) $v);
        if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
        $n = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            // no break
            case 'm': $n *= 1024;
            // no break
            case 'k': $n *= 1024;
        }
        return (int) $n;
    };
    return min($parse(ini_get('upload_max_filesize')), $parse(ini_get('post_max_size')));
}

/** Nom de fichier ou de dossier sûr (compatible Windows), ou '' si invalide. */
function safe_name($name)
{
    $name = str_replace('\\', '/', (string) $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', ' ', $name);
    $name = preg_replace('/\s+/u', ' ', (string) $name);
    $name = trim((string) $name, " .");
    if ($name === '' || strlen($name) > 180) return '';
    $stem = strtoupper(preg_replace('/\..*$/', '', $name));
    if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $stem)) return '';
    return $name;
}

/** Extensions jamais acceptées dans le dossier des cours. */
function blocked_extension($name)
{
    $ext = file_ext($name);
    $blocked = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'ini'];
    return in_array($ext, $blocked, true) || strtolower(basename($name)) === '.htaccess';
}

/** Normalise $_FILES[champ] (simple ou multiple) en liste. */
function uploaded_files($field)
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
        if (!empty($_FILES[$field]) && is_string($_FILES[$field]['name'])) {
            return $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE ? [] : [$_FILES[$field]];
        }
        return [];
    }
    $out = [];
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if ($_FILES[$field]['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = [
            'name' => $name,
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error' => $_FILES[$field]['error'][$i],
            'size' => $_FILES[$field]['size'][$i],
        ];
    }
    return $out;
}

function upload_error_message($code)
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'fichier trop volumineux';
        case UPLOAD_ERR_PARTIAL: return 'envoi interrompu';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE: return 'erreur d’écriture sur le serveur';
        default: return 'envoi refusé';
    }
}

/** Nom libre dans un dossier : « nom (2).ext », « nom (3).ext »… */
function unique_name($dir, $name)
{
    if (!file_exists($dir . DIRECTORY_SEPARATOR . $name)) return $name;
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $stem = $ext === '' ? $name : substr($name, 0, -strlen($ext) - 1);
    for ($i = 2; $i < 1000; $i++) {
        $candidate = $stem . ' (' . $i . ')' . ($ext === '' ? '' : '.' . $ext);
        if (!file_exists($dir . DIRECTORY_SEPARATOR . $candidate)) return $candidate;
    }
    return $stem . '-' . bin2hex(random_bytes(3)) . ($ext === '' ? '' : '.' . $ext);
}

/** Supprime récursivement un dossier (sans suivre les liens). */
function remove_tree($dir)
{
    if (is_link($dir) || is_file($dir)) return @unlink($dir);
    if (!is_dir($dir)) return true;
    foreach ((array) @scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        remove_tree($dir . DIRECTORY_SEPARATOR . $item);
    }
    return @rmdir($dir);
}

/**
 * Après un renommage, reporte l'ancien chemin vers le nouveau dans toutes les données
 * (règles, dépôts, examens, dossiers attribués aux enseignants). $new = null : suppression.
 */
function rekey_paths($old, $new)
{
    $map = function ($path) use ($old, $new) {
        $path = (string) $path;
        if (!path_within($path, $old)) return $path;
        if ($new === null) return null;
        return $new . substr($path, strlen($old));
    };

    $data = settings();
    $rules = [];
    foreach ($data['rules'] as $path => $rule) {
        $p = $map($path);
        if ($p !== null) $rules[$p] = $rule;
    }
    $data['rules'] = $rules;
    foreach ($data['exams'] as $i => $exam) {
        $p = $map($exam['path']);
        if ($p === null) unset($data['exams'][$i]); else $data['exams'][$i]['path'] = $p;
    }
    $data['exams'] = array_values($data['exams']);
    $coteach = [];
    foreach ($data['coteach'] as $path => $logins) {
        $p = $map($path);
        if ($p !== null) $coteach[$p] = $logins;
    }
    $data['coteach'] = $coteach;
    $seqs = [];
    foreach (isset($data['sequences']) && is_array($data['sequences']) ? $data['sequences'] : [] as $path => $cfg) {
        $p = $map((string) $path);
        if ($p !== null) $seqs[$p] = $cfg;
    }
    $data['sequences'] = $seqs;
    save_settings($data);

    $deposits = [];
    foreach (deposits_config() as $path => $cfg) {
        $p = $map($path);
        if ($p === null) continue;
        // Corrigé renommé ou déplacé dans le dossier : on suit le nouveau chemin.
        if (!empty($cfg['solution'])) {
            $sol = $map((string) $path . '/' . $cfg['solution']);
            $cfg['solution'] = ($sol !== null && strpos($sol, $p . '/') === 0) ? substr($sol, strlen($p) + 1) : '';
        }
        $deposits[$p] = $cfg;
    }
    save_deposits_config($deposits);

    $users = users_all();
    foreach ($users as $login => $u) {
        $folders = [];
        foreach ($u['folders'] as $f) {
            $p = $map($f);
            if ($p !== null) $folders[] = $p;
        }
        $users[$login]['folders'] = array_values(array_unique($folders));
    }
    save_users($users);
}

/** Texte comparable pour la recherche : minuscules, sans accents. */
function fold_text($s)
{
    $s = function_exists('mb_strtolower') ? mb_strtolower((string) $s, 'UTF-8') : strtolower((string) $s);
    $from = ['à','â','ä','á','ã','ç','é','è','ê','ë','í','ì','î','ï','ñ','ó','ò','ô','ö','õ','ú','ù','û','ü','ý','ÿ','œ','æ','_','-','.'];
    $to   = ['a','a','a','a','a','c','e','e','e','e','i','i','i','i','n','o','o','o','o','o','u','u','u','u','y','y','oe','ae',' ',' ',' '];
    return str_replace($from, $to, $s);
}

/** Types affichés directement dans le portail (aperçu intégré). */
function preview_type($path)
{
    $ext = file_ext($path);
    if (in_array($ext, ['html', 'htm'], true)) return 'html';
    if ($ext === 'pdf') return 'pdf';
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) return 'image';
    if (in_array($ext, ['mp4', 'webm'], true)) return 'video';
    if (in_array($ext, ['mp3', 'wav'], true)) return 'audio';
    if ($ext === 'txt') return 'text';
    if ($ext === 'qcm') return 'quiz';
    if (function_exists('office_preview_mode') && office_preview_mode($path) !== '') return 'office';
    if ($ext === 'url') {
        $abs = full_path($path, 'file');
        $url = $abs ? read_url_file($abs) : '';
        if (youtube_id($url) !== '') return 'youtube';
    }
    return null;
}

/** Fichiers techniques (ressources des cours HTML) masqués dans la liste des élèves. */
function is_asset_file($path)
{
    return in_array(file_ext($path), ['css', 'js', 'mjs', 'map', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'ico', 'json'], true);
}

/* ---------- Liens externes (fichiers .url, format raccourci Windows) ---------- */

/** Adresse http(s) valide, ou ''. */
function clean_url($url)
{
    $url = trim((string) $url);
    if (strlen($url) > 2000 || !preg_match('~^https?://[^\s<>"]+$~i', $url)) return '';
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

/** Lit l'adresse d'un fichier .url ('' si invalide). */
function read_url_file($abs)
{
    $raw = @file_get_contents($abs, false, null, 0, 8192);
    if ($raw === false) return '';
    if (preg_match('/^\s*URL\s*=\s*(.+?)\s*$/mi', $raw, $m)) return clean_url($m[1]);
    return '';
}

/** Crée un fichier .url ; retourne le nom créé ou ''. */
function write_url_file($dirAbs, $title, $url)
{
    $name = safe_name($title);
    if ($name === '' || clean_url($url) === '') return '';
    $name = unique_name($dirAbs, $name . '.url');
    $content = "[InternetShortcut]\r\nURL=" . $url . "\r\n";
    return file_put_contents($dirAbs . DIRECTORY_SEPARATOR . $name, $content) !== false ? $name : '';
}

/** Identifiant d'une vidéo YouTube ('' sinon). */
function youtube_id($url)
{
    if ($url === '') return '';
    if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return '';
}

/** Nom d'hôte lisible d'une adresse. */
function url_host($url)
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    return preg_replace('/^www\./', '', $host);
}

/**
 * Reporte sur une copie les réglages de partage de l'original (règles, séquences),
 * en décalant éventuellement toutes les dates d'un an. Retourne le nombre de règles copiées.
 */
function clone_sharing($from, $to, $shiftYear = false)
{
    $data = settings();
    $shift = function ($v) use ($shiftYear) {
        if (!$shiftYear || !is_string($v) || $v === '' || parse_datetime($v) === null) return $v;
        $ts = strtotime('+1 year', parse_datetime($v));
        return strpos($v, 'T') !== false ? date('Y-m-d\TH:i', $ts) : date(strlen($v) > 10 ? 'Y-m-d H:i' : 'Y-m-d', $ts);
    };
    $map = function ($p) use ($from, $to) { return $p === $from ? $to : $to . substr($p, strlen($from)); };
    $n = 0;
    foreach ($data['rules'] as $p => $r) {
        $p = (string) $p;
        if (!path_within($p, $from) || !is_array($r)) continue;
        foreach (['from', 'until', 'solution_from'] as $k) if (isset($r[$k])) $r[$k] = $shift($r[$k]);
        if (isset($r['published_at'])) $r['published_at'] = time();
        $data['rules'][$map($p)] = $r;
        $n++;
    }
    if (!empty($data['sequences'])) {
        foreach ($data['sequences'] as $p => $s) if (path_within((string) $p, $from)) $data['sequences'][$map((string) $p)] = $s;
    }
    save_settings($data);
    // Dépôts de copies : même configuration, nouvel identifiant (les rendus de l'original restent à part).
    $deps = deposits_config();
    $changed = false;
    foreach ($deps as $p => $cfg) {
        $p = (string) $p;
        if (!path_within($p, $from) || !is_array($cfg)) continue;
        $cfg['id'] = bin2hex(random_bytes(8));
        foreach (['from', 'until', 'solution_from'] as $k) if (isset($cfg[$k])) $cfg[$k] = $shift($cfg[$k]);
        if (isset($cfg['solution']) && is_string($cfg['solution']) && path_within($cfg['solution'], $from)) $cfg['solution'] = $map($cfg['solution']);
        unset($cfg['reminded'], $cfg['closed_notified']);
        $deps[$map($p)] = $cfg;
        $changed = true;
        $n++;
    }
    if ($changed) save_deposits_config($deps);
    return $n;
}
