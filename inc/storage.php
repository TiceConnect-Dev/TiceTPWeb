<?php
/**
 * Stockage JSON (réglages, compte administrateur, codes de classes).
 * Le format des fichiers est identique aux versions précédentes de TPWeb.
 */

function init_store()
{
    foreach ([TPW_DATA, TPW_COURSES, TPW_STATE, TPW_RENDUS, TPW_BACKUPS, TPW_TRASH, TPW_ARCHIVES, TPW_CACHE] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            fail(500, 'Stockage indisponible', 'Impossible de créer le dossier : ' . $dir);
        }
    }
    if (!is_file(TPW_STATE . '/settings.json')) {
        atomic_json(TPW_STATE . '/settings.json', ['levels' => TPW_DEFAULT_LEVELS, 'rules' => []]);
    }
}

function load_json($file, $fallback)
{
    $raw = @file_get_contents($file);
    $json = $raw === false ? null : json_decode($raw, true);
    return is_array($json) ? $json : $fallback;
}

function atomic_json($file, $data)
{
    $bytes = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = tempnam(dirname($file), 'tpw');
    if ($tmp === false) {
        fail(500, 'Erreur de stockage', 'Impossible d’écrire dans le dossier de configuration.');
    }
    file_put_contents($tmp, $bytes, LOCK_EX);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        fail(500, 'Erreur d’enregistrement', 'Le fichier de configuration n’a pas pu être mis à jour.');
    }
}

/* ---------- Réglages ---------- */

function settings()
{
    $data = load_json(TPW_STATE . '/settings.json', []);
    $data += ['levels' => [], 'rules' => [], 'school' => '', 'exams' => [], 'announcements' => [], 'ent' => [],
              'groups' => [], 'coteach' => [], 'autobackup' => [], 'security' => []];
    foreach (['levels', 'rules', 'exams', 'announcements', 'ent', 'groups', 'coteach', 'autobackup', 'security'] as $key) {
        if (!is_array($data[$key])) $data[$key] = [];
    }
    return $data;
}

function save_settings(array $data)
{
    atomic_json(TPW_STATE . '/settings.json', $data);
    $GLOBALS['tpw_settings_rev'] = (isset($GLOBALS['tpw_settings_rev']) ? $GLOBALS['tpw_settings_rev'] : 0) + 1;
}

function legal_defaults()
{
    return ['publisher' => '', 'controller' => '', 'address' => '', 'contact' => '', 'dpo' => '', 'host' => ''];
}

function school_name()
{
    static $name = null;
    if ($name === null) {
        $data = settings();
        $name = trim((string) $data['school']);
    }
    return $name;
}

/* ---------- Codes de classes ---------- */

function class_access_data()
{
    return load_json(TPW_STATE . '/class_codes.json', []);
}

function save_class_access_data(array $codes)
{
    atomic_json(TPW_STATE . '/class_codes.json', $codes);
}

/* ---------- Dépôts (configuration) ---------- */

function deposits_config()
{
    return load_json(TPW_STATE . '/deposits.json', []);
}

function save_deposits_config(array $deposits)
{
    atomic_json(TPW_STATE . '/deposits.json', $deposits);
    $GLOBALS['tpw_deposits_rev'] = (isset($GLOBALS['tpw_deposits_rev']) ? $GLOBALS['tpw_deposits_rev'] : 0) + 1;
}

/** Date saisie (datetime-local) -> horodatage, ou null si vide/invalide. */
function parse_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') return null;
    $ts = strtotime(str_replace('T', ' ', $value));
    return $ts === false ? null : $ts;
}

/** Normalise une saisie datetime-local ("" si invalide). */
function clean_datetime($value)
{
    $ts = parse_datetime($value);
    return $ts === null ? '' : date('Y-m-d\TH:i', $ts);
}

function format_datetime($value)
{
    $ts = is_int($value) ? $value : parse_datetime($value);
    return $ts === null ? '' : date('d/m/Y à H:i', $ts);
}

/** Fenêtre de dates ouverte maintenant ? (bornes vides = sans limite) */
function window_open($from, $until, $now = null)
{
    $now = $now === null ? time() : $now;
    $start = parse_datetime($from);
    $end = parse_datetime($until);
    return ($start === null || $now >= $start) && ($end === null || $now < $end);
}

/* ---------- Niveaux et classes ---------- */

/**
 * Organisation des classes : liste ordonnée d'éléments
 *   ['type' => 'level', 'name' => '6ème', 'classes' => ['6ème 1', '6ème 2']]  (niveau)
 *   ['type' => 'class', 'name' => 'BTS SIO 1']                                 (classe seule)
 * $data['levels'] reste la liste plate des classes (utilisée par les publications).
 */
function class_structure(array $data)
{
    $known = $data['levels'];
    $seen = [];
    $out = [];
    $raw = isset($data['structure']) && is_array($data['structure']) ? $data['structure'] : [];
    foreach ($raw as $item) {
        if (!is_array($item) || !isset($item['name'])) continue;
        if (isset($item['type']) && $item['type'] === 'level') {
            $classes = [];
            foreach ((array) $item['classes'] as $c) {
                if (in_array($c, $known, true) && !isset($seen[$c])) { $classes[] = $c; $seen[$c] = true; }
            }
            if ($classes) $out[] = ['type' => 'level', 'name' => (string) $item['name'], 'classes' => $classes];
        } elseif (in_array($item['name'], $known, true) && !isset($seen[$item['name']])) {
            $out[] = ['type' => 'class', 'name' => $item['name']];
            $seen[$item['name']] = true;
        }
    }
    foreach ($known as $c) {
        if (!isset($seen[$c])) $out[] = ['type' => 'class', 'name' => $c];
    }
    return $out;
}

/** Niveaux uniquement : nom => classes. */
function class_levels_map(array $data)
{
    $map = [];
    foreach (class_structure($data) as $item) {
        if ($item['type'] === 'level') $map[$item['name']] = $item['classes'];
    }
    return $map;
}

/** Niveau d'une classe ('' si classe seule). */
function niveau_of($class, array $data)
{
    foreach (class_levels_map($data) as $niveau => $classes) {
        if (in_array($class, $classes, true)) return $niveau;
    }
    return '';
}

/** Texte éditable : niveaux en début de ligne, classes indentées dessous. */
function structure_to_text(array $data)
{
    $lines = [];
    foreach (class_structure($data) as $item) {
        if ($item['type'] === 'level') {
            $lines[] = $item['name'];
            foreach ($item['classes'] as $c) $lines[] = '    ' . $c;
        } else {
            $lines[] = $item['name'];
        }
    }
    return implode("\n", $lines);
}

/**
 * Analyse le texte saisi. Retourne ['structure' => ..., 'levels' => ...] ou un message d'erreur.
 * Une ligne non indentée suivie de lignes indentées est un niveau ; sinon c'est une classe.
 */
function parse_structure_text($text)
{
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        if (trim($line) === '') continue;
        $indented = (bool) preg_match('/^(\s+|[-*•]\s*)/u', $line);
        $name = trim(preg_replace('/^\s*[-*•]?\s*/u', '', $line));
        if ($name === '') continue;
        if (mb_strlen($name) > 80) return 'Nom trop long (80 caractères maximum) : « ' . mb_substr($name, 0, 30) . '… ».';
        $rows[] = [$indented, $name];
    }

    $structure = [];
    $levels = [];
    $names = [];
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        list($indented, $name) = $rows[$i];
        $lower = mb_strtolower($name);
        if (isset($names[$lower])) return 'Le nom « ' . $name . ' » apparaît plusieurs fois.';
        $names[$lower] = true;

        $hasChildren = !$indented && $i + 1 < $n && $rows[$i + 1][0];
        if ($hasChildren) {
            $classes = [];
            while ($i + 1 < $n && $rows[$i + 1][0]) {
                $i++;
                $c = $rows[$i][1];
                $cl = mb_strtolower($c);
                if (isset($names[$cl])) return 'Le nom « ' . $c . ' » apparaît plusieurs fois.';
                $names[$cl] = true;
                $classes[] = $c;
                $levels[] = $c;
            }
            $structure[] = ['type' => 'level', 'name' => $name, 'classes' => $classes];
        } else {
            // Ligne indentée sans niveau au-dessus : traitée comme une classe seule.
            $structure[] = ['type' => 'class', 'name' => $name];
            $levels[] = $name;
        }
    }
    if (!$levels) return 'Indiquez au moins une classe.';
    if (count($levels) > 300) return 'Trop de classes (300 maximum).';
    return ['structure' => $structure, 'levels' => $levels];
}

/* ---------- Apparence (logo, couleur, message d'accueil) ---------- */

function branding()
{
    $data = settings();
    $b = isset($data['branding']) && is_array($data['branding']) ? $data['branding'] : [];
    $b += ['color' => '#000091', 'logo' => '', 'message' => ''];
    if (!preg_match('/^#[0-9a-f]{6}$/i', $b['color'])) $b['color'] = '#000091';
    if ($b['logo'] !== '' && !is_file(branding_dir() . DIRECTORY_SEPARATOR . $b['logo'])) $b['logo'] = '';
    return $b;
}

function branding_dir()
{
    return TPW_STATE . DIRECTORY_SEPARATOR . 'apparence';
}

/** Mélange une couleur hexadécimale avec du blanc (t > 0) ou du noir (t < 0). */
function color_mix($hex, $t)
{
    $rgb = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    foreach ($rgb as $i => $c) $rgb[$i] = (int) round($t >= 0 ? $c + (255 - $c) * $t : $c * (1 + $t));
    return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
}

/** Couleur principale choisie, déclinée pour le thème clair et le thème sombre. */
function branding_css()
{
    $c = branding()['color'];
    if (strtolower($c) === '#000091') return '';
    return ':root{--blue:' . $c . ';--blue-hover:' . color_mix($c, .18) . ';--blue-active:' . color_mix($c, .3) . ';--blue-soft:' . color_mix($c, .86) . ';--blue-softer:' . color_mix($c, .94) . '}'
        . 'html[data-theme="dark"]{--blue:' . color_mix($c, .5) . ';--blue-hover:' . color_mix($c, .62) . ';--blue-active:' . color_mix($c, .7) . ';--blue-soft:' . color_mix($c, -.62) . ';--blue-softer:' . color_mix($c, -.74) . '}'
        . '.brand__mark{background:' . $c . '}';
}
