<?php
/**
 * Aperçu des documents bureautiques (Word, PowerPoint, tableurs, LibreOffice).
 *  - Avec LibreOffice installé sur le serveur : conversion en PDF, mise en cache (TPWebData/cache/apercus).
 *  - Sinon : aperçu texte simplifié des .docx / .pptx / .odt / .odp (titres, paragraphes, diapositives).
 */

function office_exts()
{
    return ['doc', 'docx', 'odt', 'rtf', 'ppt', 'pptx', 'odp', 'pps', 'ppsx', 'xls', 'xlsx', 'ods'];
}

function is_office_file($path)
{
    return in_array(file_ext($path), office_exts(), true);
}

/** Chemin de LibreOffice (soffice), ou '' s'il est introuvable. */
function soffice_path()
{
    static $found = null;
    if ($found !== null) return $found;
    if (!function_exists('proc_open')) return $found = '';
    if (TPW_SOFFICE !== '' && is_file(TPW_SOFFICE)) return $found = TPW_SOFFICE;
    $candidates = DIRECTORY_SEPARATOR === '\\'
        ? ['C:\\Program Files\\LibreOffice\\program\\soffice.exe', 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe']
        : ['/usr/bin/soffice', '/usr/local/bin/soffice', '/usr/lib/libreoffice/program/soffice', '/opt/libreoffice/program/soffice', '/usr/bin/libreoffice'];
    foreach ($candidates as $c) if (@is_file($c)) return $found = $c;
    return $found = '';
}

function office_cache_dir()
{
    $dir = TPW_CACHE . DIRECTORY_SEPARATOR . 'apercus';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

/**
 * PDF d'aperçu d'un document bureautique (converti au premier affichage puis mis en cache).
 * Retourne le chemin du PDF ou false.
 */
function office_pdf($abs)
{
    $soffice = soffice_path();
    if ($soffice === '' || !is_file($abs)) return false;
    $dir = office_cache_dir();
    $key = sha1(realpath($abs) . '|' . filemtime($abs) . '|' . filesize($abs));
    $pdf = $dir . DIRECTORY_SEPARATOR . $key . '.pdf';
    $failed = $dir . DIRECTORY_SEPARATOR . $key . '.echec';
    if (is_file($pdf)) return $pdf;
    if (is_file($failed) && filemtime($failed) > time() - 3600) return false;

    @set_time_limit(180);
    // Une seule conversion à la fois (LibreOffice partage un profil).
    $lock = @fopen($dir . DIRECTORY_SEPARATOR . 'conversion.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    if (is_file($pdf)) { if ($lock) fclose($lock); return $pdf; }

    $work = $dir . DIRECTORY_SEPARATOR . 'tmp-' . bin2hex(random_bytes(4));
    @mkdir($work, 0750, true);
    // Copie sous un nom ASCII : évite les soucis d'encodage des noms de fichiers.
    $src = $work . DIRECTORY_SEPARATOR . 'document.' . file_ext($abs);
    @copy($abs, $src);
    $profile = TPW_CACHE . DIRECTORY_SEPARATOR . 'libreoffice-profil';
    $profileUrl = 'file:///' . ltrim(str_replace('\\', '/', $profile), '/');

    $cmd = [$soffice, '--headless', '--norestore', '--nolockcheck', '-env:UserInstallation=' . $profileUrl,
            '--convert-to', 'pdf', '--outdir', $work, $src];
    $line = implode(' ', array_map('escapeshellarg', $cmd));
    if (DIRECTORY_SEPARATOR !== '\\' && @is_file('/usr/bin/timeout')) $line = '/usr/bin/timeout 120 ' . $line;
    $env = DIRECTORY_SEPARATOR === '\\' ? null : ['HOME' => TPW_CACHE, 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
    $proc = @proc_open($line, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $work, $env);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    $out = $work . DIRECTORY_SEPARATOR . 'document.pdf';
    $ok = is_file($out) && filesize($out) > 0 && @rename($out, $pdf);
    remove_tree($work);
    if (!$ok) @touch($failed);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return $ok ? $pdf : false;
}

/** Supprime les aperçus non consultés depuis 60 jours. */
function office_cache_purge()
{
    $dir = TPW_CACHE . DIRECTORY_SEPARATOR . 'apercus';
    foreach ((array) @scandir($dir) as $f) {
        if (!preg_match('/^[a-f0-9]{40}\.(pdf|echec)$/', $f)) continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        if (@fileatime($p) < time() - 60 * 86400 && @filemtime($p) < time() - 60 * 86400) @unlink($p);
    }
}

/**
 * Aperçu texte de secours (sans LibreOffice) : HTML échappé, ou '' si impossible.
 * Word/Writer : titres et paragraphes ; PowerPoint/Impress : texte de chaque diapositive.
 */
function office_text_html($abs)
{
    if (!class_exists('ZipArchive')) return '';
    $ext = file_ext($abs);
    $zip = new ZipArchive();
    if ($zip->open($abs) !== true) return '';
    $html = '';
    $esc = function ($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); };

    if ($ext === 'docx') {
        $xml = $zip->getFromName('word/document.xml');
        if ($xml !== false && preg_match_all('~<w:p[ >].*?</w:p>~s', $xml, $ps)) {
            foreach ($ps[0] as $p) {
                preg_match_all('~<w:t(?: [^>]*)?>(.*?)</w:t>~s', $p, $ts);
                $text = trim(html_entity_decode(implode('', $ts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($text === '') continue;
                $heading = preg_match('~<w:pStyle w:val="(?:Heading|Titre|Title)(\d?)"~i', $p, $hm);
                $level = $heading ? max(2, min(4, (int) ($hm[1] ?: 1) + 1)) : 0;
                $html .= $level ? "<h$level>" . $esc($text) . "</h$level>" : '<p>' . $esc($text) . '</p>';
            }
        }
    } elseif ($ext === 'pptx') {
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $n, $m)) $slides[(int) $m[1]] = $n;
        }
        ksort($slides);
        foreach ($slides as $num => $name) {
            $xml = $zip->getFromName($name);
            $html .= '<section class="office-slide"><p class="office-slide__num">Diapositive ' . $num . '</p>';
            if ($xml !== false && preg_match_all('~<a:p>.*?</a:p>~s', $xml, $ps)) {
                foreach ($ps[0] as $p) {
                    preg_match_all('~<a:t>(.*?)</a:t>~s', $p, $ts);
                    $text = trim(html_entity_decode(implode('', $ts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($text !== '') $html .= '<p>' . $esc($text) . '</p>';
                }
            }
            $html .= '</section>';
        }
    } elseif (in_array($ext, ['odt', 'odp'], true)) {
        $xml = $zip->getFromName('content.xml');
        if ($xml !== false) {
            $xml = preg_replace('~<text:(?:s|tab|line-break)[^>]*/>~', ' ', $xml);
            if (preg_match_all('~<(text:h|text:p)\b[^>]*>(.*?)</\1>~s', $xml, $ps, PREG_SET_ORDER)) {
                foreach ($ps as $p) {
                    $text = trim(html_entity_decode(strip_tags($p[2]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($text === '') continue;
                    $html .= $p[1] === 'text:h' ? '<h3>' . $esc($text) . '</h3>' : '<p>' . $esc($text) . '</p>';
                }
            }
        }
    }
    $zip->close();
    return $html;
}

/** Mode d'aperçu disponible pour un document bureautique : 'pdf', 'text' ou ''. */
function office_preview_mode($path)
{
    if (!is_office_file($path)) return '';
    if (soffice_path() !== '') return 'pdf';
    return in_array(file_ext($path), ['docx', 'pptx', 'odt', 'odp'], true) ? 'text' : '';
}
