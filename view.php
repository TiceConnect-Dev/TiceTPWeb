<?php
/**
 * Diffusion des cours HTML et de leurs ressources (CSS, JS, images, polices…).
 * Les liens relatifs sont réécrits pour rester dans le périmètre publié.
 */

require_once __DIR__ . '/inc/bootstrap.php';

$level = query('level');
$path  = query('path');
$data  = settings();
// Mode enseignant (projection) : jeton signé limité à un dossier et à une durée (le cours est isolé
// dans un cadre sans cookie, la session ne peut donc pas servir ici).
$teacherRoot = teacher_view_root(query('tk'), query('f'));
$teacher = $teacherRoot !== null && path_within($path, $teacherRoot);

if (!$teacher) class_guard($level);

$types = [
    'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8',
    'css'  => 'text/css; charset=UTF-8',
    'js'   => 'text/javascript; charset=UTF-8', 'mjs' => 'text/javascript; charset=UTF-8',
    'json' => 'application/json',
    'svg'  => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    'pdf'  => 'application/pdf',
    'mp4'  => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
    'txt'  => 'text/plain; charset=UTF-8',
];

$ext  = file_ext($path);
$file = full_path($path, 'file');

if (!$file || !isset($types[$ext]) || (!$teacher && !rule_for($path, $level, $data))) {
    // Ressource chargée par une page de cours : réponse texte simple.
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Ressource introuvable ou non publiée.');
}

release_session();

header('Content-Type: ' . $types[$ext]);
header('Content-Disposition: inline');
header('Cache-Control: private, no-store');

$isHtml = $ext === 'html' || $ext === 'htm';
if ($isHtml) {
    // Page isolée : JavaScript autorisé, mais aucun accès à la session ni à l'administration.
    header('Content-Security-Policy: sandbox allow-scripts allow-forms allow-downloads; base-uri none');
}

/** Convertit une référence relative en URL view.php, sinon la laisse inchangée. */
function ref_url($ref, $from, $level)
{
    if ($GLOBALS['teacher']) $level = null;
    $r = html_entity_decode($ref, ENT_QUOTES, 'UTF-8');
    if ($r === '' || $r[0] === '#' || $r[0] === '/' || $r[0] === '?'
        || substr($r, 0, 2) === '//' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $r)) {
        return $ref;
    }

    $fragment = '';
    $query = '';
    if (($i = strpos($r, '#')) !== false) {
        $fragment = substr($r, $i);
        $r = substr($r, 0, $i);
    }
    if (($i = strpos($r, '?')) !== false) {
        $query = substr($r, $i);
        $r = substr($r, 0, $i);
    }
    if ($r === '') return $ref;

    $folder = str_replace('\\', '/', dirname($from));
    $stack = ($folder === '.' || $folder === '') ? [] : explode('/', $folder);

    foreach (explode('/', $r) as $part) {
        $part = rawurldecode($part);
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            if (!$stack) return $ref;
            array_pop($stack);
            continue;
        }
        if (strpos($part, '/') !== false || strpos($part, '\\') !== false || strpos($part, "\0") !== false) {
            return $ref;
        }
        $stack[] = $part;
    }

    $target = implode('/', $stack);
    if (!valid_path($target)) return $ref;

    $prefix = $level === null
        ? 'view.php?tk=' . rawurlencode(query('tk')) . '&amp;f=' . rawurlencode(query('f'))
        : 'view.php?level=' . rawurlencode($level);
    return $prefix . '&amp;path=' . rawurlencode($target)
        . htmlspecialchars($query . $fragment, ENT_QUOTES, 'UTF-8');
}

if ($isHtml) {
    $html = file_get_contents($file);
    $html = preg_replace_callback(
        '~(\b(?:src|href|poster|action)\s*=\s*)(["\'])(.*?)\2~is',
        function ($m) use ($path, $level) {
            return $m[1] . $m[2] . ref_url($m[3], $path, $level) . $m[2];
        },
        $html
    );
    echo $html;
    exit;
}

if ($ext === 'css') {
    $css = file_get_contents($file);
    $css = preg_replace_callback(
        '~url\(\s*(["\']?)([^)"\']+)\1\s*\)~i',
        function ($m) use ($path, $level) {
            $url = html_entity_decode(ref_url(trim($m[2]), $path, $level), ENT_QUOTES, 'UTF-8');
            return 'url(' . $m[1] . $url . $m[1] . ')';
        },
        $css
    );
    echo $css;
    exit;
}

header('Content-Length: ' . filesize($file));
readfile($file);
