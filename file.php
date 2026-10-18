<?php
/**
 * Ouverture ou téléchargement d'un document publié.
 * Les pages HTML sont toujours affichées dans le navigateur (via view.php).
 */

require_once __DIR__ . '/inc/bootstrap.php';

$level = query('level');
$path  = query('path');
$data  = settings();

class_guard($level);

$file = full_path($path, 'file');
if (!$file || !rule_for($path, $level, $data)) {
    fail(404, 'Document indisponible', 'Ce document n’existe pas ou n’est pas publié pour votre classe.');
}

release_session();

if (file_ext($path) === 'url') {
    // Lien externe : redirection vers l'adresse enregistrée.
    $url = read_url_file($file);
    if ($url === '') fail(404, 'Lien invalide', 'Ce lien ne contient pas d’adresse web valide.');
    header('Location: ' . $url, true, 302);
    exit;
}

if (is_quiz_file($path)) {
    // Le fichier du QCM contient les réponses : il n'est jamais envoyé aux élèves.
    header('Location: quiz.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($path), true, 302);
    exit;
}

if (query('as') === 'pdf' && is_office_file($path)) {
    // Aperçu d'un document bureautique converti en PDF (LibreOffice).
    $pdf = office_pdf($file);
    if (!$pdf) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Aperçu indisponible pour ce document. Utilisez le bouton Télécharger.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="apercu.pdf"');
    header('Content-Length: ' . filesize($pdf));
    header('Cache-Control: private, max-age=600');
    @touch($pdf);
    readfile($pdf);
    exit;
}

if (is_html_file($path) && query('dl') !== '1') {
    header('Location: view.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($path), true, 302);
    exit;
}

$types = [
    'pdf' => 'application/pdf',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'txt' => 'text/plain; charset=UTF-8',
    'mp4' => 'video/mp4', 'webm' => 'video/webm',
    'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
];
$ext    = file_ext($file);
$inline = isset($types[$ext]) && query('dl') !== '1';
$name   = str_replace(['"', "\r", "\n"], '_', basename($file));

header('Content-Type: ' . (isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode(basename($file)));
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
readfile($file);
