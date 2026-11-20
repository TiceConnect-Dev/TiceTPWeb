<?php
/** Manifeste de l'application installable (PWA) : portail élèves. */
require_once __DIR__ . '/inc/bootstrap.php';
release_session();
$school = school_name();
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name' => TPW_APP_NAME . ($school !== '' ? ' — ' . $school : ''),
    'short_name' => TPW_APP_NAME,
    'description' => 'Cours, travaux pratiques et dépôts des élèves',
    'lang' => 'fr',
    'start_url' => 'index.php?source=pwa',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => branding()['color'],
    'icons' => [
        ['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => 'assets/icon-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
