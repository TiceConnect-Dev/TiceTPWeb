<?php
/**
 * Élèves (facultatif) : liste des élèves par classe, avec leurs groupes et leur identifiant ENT.
 *
 * config/students.json : { "id": { "last", "first", "class", "groups": [...], "ent_id", "created" } }
 *
 * - Sans liste d'élèves, rien ne change : les classes restent accessibles avec leur code.
 * - Un élève de la liste qui se connecte par l'ENT arrive directement dans sa classe, avec ses
 *   groupes déjà choisis ; son nom est rempli automatiquement pour les dépôts et les QCM.
 */

function students_file()
{
    return TPW_STATE . '/students.json';
}

function students_all($reset = false)
{
    static $cache = null;
    if ($reset) $cache = null;
    if ($cache !== null) return $cache;
    $list = load_json(students_file(), []);
    foreach ($list as $id => $s) {
        if (!is_array($s)) { unset($list[$id]); continue; }
        $list[$id] = $s + ['last' => '', 'first' => '', 'class' => '', 'groups' => [], 'ent_id' => '', 'created' => 0];
        if (!is_array($list[$id]['groups'])) $list[$id]['groups'] = [];
    }
    return $cache = $list;
}

function save_students(array $list)
{
    uasort($list, function ($a, $b) {
        return strnatcasecmp($a['class'], $b['class']) ?: strcasecmp($a['last'] . ' ' . $a['first'], $b['last'] . ' ' . $b['first']);
    });
    atomic_json(students_file(), $list);
    students_all(true);
}

function student_new_id()
{
    return bin2hex(random_bytes(6));
}

function find_student($id)
{
    $all = students_all();
    return is_string($id) && isset($all[$id]) ? ['id' => $id] + $all[$id] : null;
}

function find_student_by_ent($entId)
{
    $entId = function_exists('normalize_ent_id') ? normalize_ent_id($entId) : strtolower(trim((string) $entId));
    if ($entId === '') return null;
    foreach (students_all() as $id => $s) {
        $mine = function_exists('normalize_ent_id') ? normalize_ent_id($s['ent_id']) : strtolower(trim($s['ent_id']));
        if ($mine !== '' && $mine === $entId) return ['id' => (string) $id] + $s;
    }
    return null;
}

/** Élève connecté par l'ENT (ou null). */
function current_student()
{
    if (empty($_SESSION['student'])) return null;
    return find_student((string) $_SESSION['student']);
}

function student_name(array $s)
{
    return trim($s['first'] . ' ' . mb_strtoupper($s['last']));
}

/** Élèves d'une classe, triés par nom. */
function students_of_class($class)
{
    $out = [];
    foreach (students_all() as $id => $s) if ($s['class'] === $class) $out[(string) $id] = $s;
    uasort($out, function ($a, $b) { return strcasecmp($a['last'] . ' ' . $a['first'], $b['last'] . ' ' . $b['first']); });
    return $out;
}

function student_count_by_class()
{
    $n = [];
    foreach (students_all() as $s) $n[$s['class']] = (isset($n[$s['class']]) ? $n[$s['class']] : 0) + 1;
    return $n;
}

/** Connexion d'un élève : sa classe est déverrouillée pour la session. */
function login_student(array $s)
{
    session_regenerate_id(true);
    $_SESSION['student'] = $s['id'];
}

/**
 * Lit un fichier CSV (séparateur ; , ou tabulation, UTF-8 ou Windows-1252) en lignes associatives
 * dont les clés sont les en-têtes normalisés (minuscules, sans accents).
 */
function csv_read_rows($file, $max = 5000)
{
    $raw = (string) @file_get_contents($file, false, null, 0, 5 * 1024 * 1024);
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    if (!preg_match('//u', $raw) && function_exists('iconv')) {
        $conv = @iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
        if ($conv !== false) $raw = $conv;
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $first = '';
    foreach ($lines as $l) if (trim($l) !== '') { $first = $l; break; }
    $sep = ';';
    foreach (["\t", ';', ','] as $c) if (substr_count($first, $c) > substr_count($first, $sep)) $sep = $c;
    $rows = [];
    $head = null;
    foreach ($lines as $l) {
        if (trim($l) === '') continue;
        $cells = array_map('trim', str_getcsv($l, $sep));
        if ($head === null) {
            $head = array_map(function ($h) { return trim(fold_text($h)); }, $cells);
            continue;
        }
        $row = [];
        foreach ($head as $i => $h) $row[$h] = isset($cells[$i]) ? $cells[$i] : '';
        $rows[] = $row;
        if (count($rows) >= $max) break;
    }
    return $rows;
}

/** Première valeur non vide parmi plusieurs noms de colonnes possibles. */
function csv_get(array $row, array $names)
{
    foreach ($names as $n) {
        $n = fold_text($n);
        if (isset($row[$n]) && $row[$n] !== '') return $row[$n];
    }
    return '';
}

/** Retrouve une classe existante sans tenir compte de la casse ni des espaces. */
function match_class($name, array $data)
{
    $want = preg_replace('/\s+/', '', fold_text($name));
    if ($want === '') return '';
    foreach ($data['levels'] as $c) {
        if (preg_replace('/\s+/', '', fold_text($c)) === $want) return $c;
    }
    return '';
}

/**
 * Importe des élèves depuis un CSV (colonnes : Nom ; Prénom ; Classe ; Groupes ; Identifiant ENT).
 * Un élève déjà présent (même identifiant ENT, ou même nom + prénom + classe) est mis à jour.
 * $replaceClasses : vide d'abord les classes présentes dans le fichier.
 */
function students_import($file, array $data, $replaceClasses = false, array $allowedClasses = null)
{
    $rows = csv_read_rows($file);
    $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'unknown' => [], 'errors' => []];
    if (!$rows) { $report['errors'][] = 'Fichier vide ou illisible.'; return $report; }
    $list = students_all();
    if ($replaceClasses) {
        $classes = [];
        foreach ($rows as $r) { $c = match_class(csv_get($r, ['classe', 'division', 'class']), $data); if ($c !== '') $classes[$c] = true; }
        foreach ($list as $id => $s) {
            if (isset($classes[$s['class']]) && ($allowedClasses === null || in_array($s['class'], $allowedClasses, true))) unset($list[$id]);
        }
    }
    foreach ($rows as $i => $r) {
        $last = clean_person_name(csv_get($r, ['nom', 'nom de famille', 'last', 'nom eleve']));
        $first = clean_person_name(csv_get($r, ['prenom', 'prénom', 'first', 'prenom eleve']));
        // Format « NOM Prénom » dans une seule colonne (exports Pronote).
        if ($last === '' && $first === '') {
            $full = csv_get($r, ['eleve', 'élève', 'nom prenom', 'nom et prenom']);
            if (preg_match('/^(.+?)\s+(\S+)$/u', trim($full), $m)) { $last = clean_person_name($m[1]); $first = clean_person_name($m[2]); }
        }
        $rawClass = csv_get($r, ['classe', 'division', 'class']);
        $class = match_class($rawClass, $data);
        if ($last === '' || $first === '') { $report['skipped']++; continue; }
        if ($class === '') { $report['skipped']++; if ($rawClass !== '') $report['unknown'][$rawClass] = true; continue; }
        if ($allowedClasses !== null && !in_array($class, $allowedClasses, true)) { $report['skipped']++; continue; }
        $groups = [];
        $known = group_names($data, $class);
        foreach (preg_split('/[,|\/]+/', csv_get($r, ['groupes', 'groupe', 'groups'])) as $g) {
            $g = trim($g);
            foreach ($known as $k) if ($g !== '' && mb_strtolower($k) === mb_strtolower($g)) $groups[] = $k;
        }
        $ent = mb_substr(trim(csv_get($r, ['identifiant ent', 'ent', 'login', 'identifiant', 'login ent'])), 0, 120);
        $found = null;
        foreach ($list as $id => $s) {
            if (($ent !== '' && strtolower($s['ent_id']) === strtolower($ent))
                || ($s['class'] === $class && mb_strtolower($s['last'] . '|' . $s['first']) === mb_strtolower($last . '|' . $first))) { $found = $id; break; }
        }
        if ($found !== null) {
            $list[$found]['last'] = $last;
            $list[$found]['first'] = $first;
            $list[$found]['class'] = $class;
            if ($groups) $list[$found]['groups'] = $groups;
            if ($ent !== '') $list[$found]['ent_id'] = $ent;
            $report['updated']++;
        } else {
            $list[student_new_id()] = ['last' => $last, 'first' => $first, 'class' => $class, 'groups' => $groups, 'ent_id' => $ent, 'created' => time()];
            $report['created']++;
        }
    }
    save_students($list);
    $report['unknown'] = array_keys($report['unknown']);
    return $report;
}

/* ---------- Demandes d'accès des élèves inconnus (connexion ENT) ---------- */

function student_requests()
{
    $list = load_json(TPW_STATE . '/student_requests.json', []);
    return is_array($list) ? $list : [];
}

function save_student_requests(array $list)
{
    atomic_json(TPW_STATE . '/student_requests.json', $list);
}

/** Demandes visibles par un utilisateur (administrateur : toutes ; enseignant : ses classes). */
function student_requests_for(array $user)
{
    $admin = is_admin($user);
    return array_filter(student_requests(), function ($r) use ($admin, $user) {
        return $admin || in_array($r['class'], $user['classes'], true);
    });
}

/**
 * Nom, prénom et classe transmis par l'ENT dans les attributs CAS (si disponibles).
 * Retourne ['last' => ..., 'first' => ..., 'class' => ...] (valeurs éventuellement vides).
 */
function ent_student_attrs(array $attributes)
{
    $get = function (array $keys) use ($attributes) {
        foreach ($keys as $k) {
            foreach ($attributes as $name => $values) {
                if (strcasecmp($name, $k) === 0 && isset($values[0]) && trim($values[0]) !== '') return trim($values[0]);
            }
        }
        return '';
    };
    return [
        'last' => clean_person_name($get(['lastName', 'sn', 'nom', 'surname', 'familyName'])),
        'first' => clean_person_name($get(['firstName', 'givenName', 'prenom', 'prénom'])),
        'class' => $get(['classe', 'classes', 'class', 'classname', 'division', 'ENTEleveClasses', 'structureClass']),
    ];
}
