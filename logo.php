<?php
/** Logo de l'établissement (Paramètres › Apparence). */
require_once __DIR__ . '/inc/bootstrap.php';
release_session();
$b = branding();
$file = $b['logo'] !== '' ? branding_dir() . DIRECTORY_SEPARATOR . $b['logo'] : '';
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($file === '' || !is_file($file) || !isset($types[$ext])) { http_response_code(404); exit; }
header('Content-Type: ' . $types[$ext]);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($file));
readfile($file);
