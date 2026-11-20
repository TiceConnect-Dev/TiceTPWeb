<?php
/**
 * Groupes dans une classe (différenciation) : groupe A/B, spécialité, tiers-temps…
 *
 * settings.json › groups : { "2GT2": [ { "name": "Groupe A", "hash": "", "display": "" }, … ] }
 * Une « cible » de publication est soit une classe (« 2GT2 »), soit un groupe (« 2GT2|Groupe A »).
 * L'élève choisit son ou ses groupes sur la page de sa classe (code facultatif par groupe) ;
 * le choix est gardé pour la session du navigateur.
 */

const GROUP_SEP = '|';

/** Groupes d'une classe (liste normalisée). */
function class_groups(array $data, $class)
{
    $raw = isset($data['groups'][$class]) && is_array($data['groups'][$class]) ? $data['groups'][$class] : [];
    $out = [];
    foreach ($raw as $g) {
        if (!is_array($g) || !isset($g['name']) || !is_string($g['name']) || $g['name'] === '') continue;
        $out[] = $g + ['hash' => '', 'display' => ''];
    }
    return $out;
}

function group_names(array $data, $class)
{
    return array_map(function ($g) { return $g['name']; }, class_groups($data, $class));
}

function group_key($class, $name)
{
    return $class . GROUP_SEP . $name;
}

/** [classe, groupe|null] */
function split_target($target)
{
    $target = (string) $target;
    $i = strpos($target, GROUP_SEP);
    return $i === false ? [$target, null] : [substr($target, 0, $i), substr($target, $i + 1)];
}

/** Libellé lisible : « 2GT2 · Groupe A ». */
function target_label($target)
{
    list($c, $g) = split_target($target);
    return $g === null ? $c : $c . ' · ' . $g;
}

/** Classes + groupes existants (cibles valides). */
function all_targets(array $data)
{
    $out = [];
    foreach ($data['levels'] as $c) {
        $out[] = $c;
        foreach (group_names($data, $c) as $g) $out[] = group_key($c, $g);
    }
    return $out;
}

/** Tous les noms de groupes distincts (ex. pour le tiers-temps). */
function all_group_names(array $data)
{
    $names = [];
    foreach ($data['levels'] as $c) {
        foreach (group_names($data, $c) as $g) $names[$g] = true;
    }
    $names = array_keys($names);
    natcasesort($names);
    return array_values($names);
}

/** Groupes choisis par l'élève pour cette classe (session), validés. */
function viewer_groups($level, array $data = null)
{
    if ($data === null) $data = settings();
    // Élève connecté (ENT) : ses groupes sont ceux attribués par l'enseignant.
    $st = function_exists('current_student') ? current_student() : null;
    if ($st && $st['class'] === $level) return array_values(array_intersect(group_names($data, $level), $st['groups']));
    $chosen = isset($_SESSION['groups'][$level]) && is_array($_SESSION['groups'][$level]) ? $_SESSION['groups'][$level] : [];
    return array_values(array_intersect(group_names($data, $level), $chosen));
}

/**
 * Une liste de cibles concerne-t-elle cet élève ?
 * $groups = null : groupes de la session ; [] : la classe entière seulement.
 */
function target_match(array $targets, $level, $groups = null, array $data = null)
{
    if (in_array($level, $targets, true)) return true;
    if ($groups === null) $groups = viewer_groups($level, $data);
    foreach ($groups as $g) {
        if (in_array(group_key($level, $g), $targets, true)) return true;
    }
    return false;
}

/** Remplace les cibles d'une classe renommée/supprimée : garde uniquement les cibles valides. */
function clean_targets(array $targets, array $data)
{
    return array_values(array_intersect(all_targets($data), $targets));
}

/** Vérifie le code d'un groupe protégé. */
function group_code_ok(array $group, $code)
{
    if ($group['hash'] === '') return true;
    return password_verify(strtoupper(trim((string) $code)), $group['hash']);
}

/**
 * Analyse la saisie des groupes d'une classe : un groupe par ligne, code facultatif après « : ».
 *   Groupe A
 *   Tiers-temps : K7P2
 * Les codes existants non réécrits sont conservés (ligne sans code = groupe sans code).
 * Retourne la liste normalisée ou un message d'erreur.
 */
function parse_groups_text($text, array $existing)
{
    $old = [];
    foreach ($existing as $g) $old[mb_strtolower($g['name'])] = $g;
    $out = [];
    $seen = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $code = '';
        if (preg_match('/^(.*?)\s*:\s*([A-Za-z0-9]{4})$/', $line, $m)) {
            $line = trim($m[1]);
            $code = strtoupper($m[2]);
        }
        $name = trim(preg_replace('/\s+/u', ' ', str_replace(GROUP_SEP, '-', $line)));
        if ($name === '' || mb_strlen($name) > 40) return 'Nom de groupe invalide ou trop long (40 caractères maximum) : « ' . mb_substr($line, 0, 30) . ' ».';
        if (isset($seen[mb_strtolower($name)])) return 'Le groupe « ' . $name . ' » apparaît plusieurs fois.';
        $seen[mb_strtolower($name)] = true;
        if ($code !== '') {
            $prev = isset($old[mb_strtolower($name)]) ? $old[mb_strtolower($name)] : null;
            $out[] = $prev && $prev['display'] === $code ? $prev : ['name' => $name, 'hash' => password_hash($code, PASSWORD_DEFAULT), 'display' => $code];
        } else {
            $out[] = ['name' => $name, 'hash' => '', 'display' => ''];
        }
    }
    if (count($out) > 12) return '12 groupes maximum par classe.';
    return $out;
}

/** Texte éditable des groupes d'une classe. */
function groups_to_text(array $groups)
{
    return implode("\n", array_map(function ($g) {
        return $g['name'] . ($g['display'] !== '' ? ' : ' . $g['display'] : '');
    }, $groups));
}
