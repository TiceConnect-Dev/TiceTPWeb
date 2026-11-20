<?php
/**
 * Statistiques de consultation des ressources.
 * config/stats/AAAA-MM.log : une ligne par consultation « horodatage \t chemin \t classe \t élève »
 * (élève = identifiant de la liste si connecté par l'ENT, sinon vide). Conservation : 12 mois.
 * Une même ressource ouverte plusieurs fois par la même session en 30 minutes ne compte qu'une fois.
 */

const STATS_MONTHS = 12;

function stats_dir()
{
    return TPW_STATE . DIRECTORY_SEPARATOR . 'stats';
}

function stats_record($path, $level)
{
    if (logged() || $path === '') return; // les consultations des enseignants ne comptent pas
    $key = md5($path . '|' . $level);
    if (isset($_SESSION['viewed'][$key]) && $_SESSION['viewed'][$key] > time() - 1800) return;
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['viewed'][$key] = time();
    $st = current_student();
    $dir = stats_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return;
    $line = time() . "\t" . str_replace(["\t", "\n"], ' ', $path) . "\t" . str_replace(["\t", "\n"], ' ', $level) . "\t" . ($st ? $st['id'] : '') . "\n";
    @file_put_contents($dir . DIRECTORY_SEPARATOR . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Agrégat des consultations d'un élément (fichier ou dossier et son contenu), sur 12 mois.
 * Retourne ['total', 'last', 'by_class' => [classe => n], 'students' => [id => dernière], 'by_item' => [chemin => n], 'recent' => n sur 7 jours].
 */
function stats_for($path)
{
    static $lines = null;
    if ($lines === null) {
        $lines = [];
        foreach ((array) @scandir(stats_dir()) as $f) {
            if (!preg_match('/^\d{4}-\d{2}\.log$/', $f)) continue;
            foreach ((array) @file(stats_dir() . DIRECTORY_SEPARATOR . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                $parts = explode("\t", $l);
                if (count($parts) >= 3) $lines[] = [(int) $parts[0], $parts[1], $parts[2], isset($parts[3]) ? $parts[3] : ''];
            }
        }
    }
    $out = ['total' => 0, 'last' => 0, 'by_class' => [], 'students' => [], 'by_item' => [], 'recent' => 0];
    $week = time() - 7 * 86400;
    foreach ($lines as $l) {
        if (!path_within($l[1], $path)) continue;
        $out['total']++;
        $out['last'] = max($out['last'], $l[0]);
        if ($l[0] >= $week) $out['recent']++;
        $out['by_class'][$l[2]] = (isset($out['by_class'][$l[2]]) ? $out['by_class'][$l[2]] : 0) + 1;
        if ($l[3] !== '') $out['students'][$l[3]] = max(isset($out['students'][$l[3]]) ? $out['students'][$l[3]] : 0, $l[0]);
        $out['by_item'][$l[1]] = (isset($out['by_item'][$l[1]]) ? $out['by_item'][$l[1]] : 0) + 1;
    }
    arsort($out['by_class']);
    arsort($out['by_item']);
    return $out;
}

/** Purge des statistiques de plus de 12 mois. */
function stats_purge()
{
    $limit = date('Y-m', strtotime('-' . STATS_MONTHS . ' months'));
    foreach ((array) @scandir(stats_dir()) as $f) {
        if (preg_match('/^(\d{4}-\d{2})\.log$/', $f, $m) && $m[1] < $limit) @unlink(stats_dir() . DIRECTORY_SEPARATOR . $f);
    }
}
