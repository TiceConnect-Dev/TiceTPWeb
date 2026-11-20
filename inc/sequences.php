<?php
/**
 * Séquences : un dossier présenté comme une suite de séances numérotées (sous-dossiers et documents
 * dans l'ordre alphabétique : « 1 - Introduction », « 2 - TP »…), avec la progression de l'élève.
 *
 * settings.json › sequences : { "chemin du dossier": { "mode": "free" | "order" } }
 *  - free  : tout est accessible, l'élève coche « J'ai terminé » pour suivre sa progression ;
 *  - order : pour l'élève connecté par l'ENT, une séance s'ouvre quand la précédente est terminée.
 * Progression : students.json › progress { "chemin de la séquence": [séances terminées] } pour un
 * élève connecté, sinon dans le navigateur.
 */

function sequences_map()
{
    static $cache = null, $rev = -1;
    $current = isset($GLOBALS['tpw_settings_rev']) ? $GLOBALS['tpw_settings_rev'] : 0;
    if ($cache !== null && $rev === $current) return $cache;
    $rev = $current;
    $data = settings();
    $cache = [];
    foreach (isset($data['sequences']) && is_array($data['sequences']) ? $data['sequences'] : [] as $p => $cfg) {
        if (is_array($cfg)) $cache[(string) $p] = $cfg + ['mode' => 'free'];
    }
    return $cache;
}

function sequence_cfg($path)
{
    $map = sequences_map();
    return isset($map[$path]) ? $map[$path] : null;
}

/** Séances d'une séquence : sous-dossiers et documents, dans l'ordre naturel. */
function sequence_items($path)
{
    $list = names_in($path);
    $items = array_merge($list['dirs'], array_values(array_filter($list['files'], function ($f) { return !is_asset_file($f); })));
    usort($items, function ($a, $b) { return strnatcasecmp(basename($a), basename($b)); });
    return $items;
}

/** Titre d'une séance sans son numéro (« 2 - TP adresses IP.pdf » → « TP adresses IP »). */
function sequence_title($item)
{
    $t = display_name($item);
    $clean = preg_replace('/^\s*\d+\s*[-–—.)_:]\s*/u', '', $t);
    return $clean !== '' ? $clean : $t;
}

/** Séances terminées par l'élève connecté. */
function sequence_done(array $student, $seq)
{
    return isset($student['progress'][$seq]) && is_array($student['progress'][$seq]) ? $student['progress'][$seq] : [];
}

/** Index de la première séance non terminée (= dernière séance ouverte en mode « dans l'ordre »). */
function sequence_open_index(array $items, array $done)
{
    foreach ($items as $i => $it) if (!in_array($it, $done, true)) return $i;
    return count($items);
}

/** L'élément est-il verrouillé pour l'élève connecté (séquence « dans l'ordre ») ? */
function sequence_locked($path, array $student)
{
    if (!empty($GLOBALS['tpw_no_seq_lock'])) return false;
    foreach (sequences_map() as $seq => $cfg) {
        if ($cfg['mode'] !== 'order' || $path === $seq || !path_within($path, $seq)) continue;
        $items = sequence_items($seq);
        $open = sequence_open_index($items, sequence_done($student, $seq));
        foreach ($items as $i => $it) {
            if (path_within($path, $it)) return $i > $open;
        }
    }
    return false;
}

/** Progression des élèves connectés : séance => nombre d'élèves l'ayant terminée. */
function sequence_progress_counts($seq)
{
    $out = [];
    foreach (students_all() as $s) {
        if (empty($s['progress'][$seq]) || !is_array($s['progress'][$seq])) continue;
        foreach ($s['progress'][$seq] as $it) $out[$it] = (isset($out[$it]) ? $out[$it] : 0) + 1;
    }
    return $out;
}
