<?php
/**
 * Copie corrigée (annotée par l'enseignant) d'un rendu, côté élève.
 * Accès : l'élève connecté auteur du rendu, ou le numéro de reçu du rendu ; seulement si
 * l'enseignant a rendu les retours visibles pour ce dépôt.
 */

require_once __DIR__ . '/inc/bootstrap.php';

$id = query('d');
$file = query('f');
$receipt = strtoupper(query('r'));

$tries = isset($_SESSION['copie_tries']) ? array_filter((array) $_SESSION['copie_tries'], function ($t) { return $t > time() - 600; }) : [];
if (count($tries) >= 20) fail(429, 'Trop de tentatives', 'Réessaie dans quelques minutes.');

$found = null;
foreach (deposits_config() as $p => $cfg) {
    if (!is_array($cfg) || !isset($cfg['id']) || $cfg['id'] !== $id) continue;
    $cfg = deposit_normalize($cfg);
    foreach (deposit_entries($cfg) as $e) {
        if ($e['file'] === $file) { $found = [$cfg, $e]; break 2; }
    }
}
$ok = false;
if ($found && !empty($found[0]['feedback_public']) && !empty($found[1]['corrected'])) {
    list($cfg, $e) = $found;
    $me = current_student();
    if (!empty($e['receipt']) && hash_equals($e['receipt'], $receipt)) $ok = true;
    if ($me) {
        if (!empty($e['student']) && $e['student'] === $me['id']) $ok = true;
        foreach (deposit_members($e) as $m) {
            if (deposit_name_key($e['level'], $m['last'], $m['first']) === deposit_name_key($me['class'], $me['last'], $me['first'])) $ok = true;
        }
    }
}
if (!$ok) {
    $tries[] = time();
    $_SESSION['copie_tries'] = $tries;
    fail(404, 'Copie indisponible', 'Cette copie corrigée n’existe pas ou n’est pas encore disponible.');
}
release_session();
$full = deposit_file_path($cfg, $e['corrected']);
if (!$full) fail(404, 'Copie indisponible', 'Cette copie corrigée n’existe plus.');
audit_log('student_correction', ['dossier' => basename(dirname($e['file']))], 'élève');

$name = pathinfo((string) $e['orig'], PATHINFO_FILENAME) . ' - corrigé.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($full));
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($full);
