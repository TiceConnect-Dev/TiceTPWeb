<?php
/**
 * Contrôle d'accès : jeton CSRF, administrateur, règles de publication, codes de classes.
 */

/* ---------- CSRF & session ---------- */

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
        // Le navigateur a envoyé plus que post_max_size : PHP a tout ignoré.
        fail(413, 'Envoi trop volumineux', 'Les fichiers envoyés dépassent la taille maximale autorisée par le serveur (' . human_size(upload_limit()) . ').');
    }
    $sent = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), $sent)) {
        fail(403, 'Requête refusée', 'La session a expiré ou la requête n’est pas valide. Rechargez la page puis réessayez.');
    }
}

/** Libère le verrou de session (après avoir généré le jeton CSRF). */
function release_session()
{
    csrf_token();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function redirect($where)
{
    if (function_exists('audit_flush')) audit_flush();
    header('Location: ' . $where);
    exit;
}

function post($key, $default = '')
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : $default;
}

function query($key, $default = '')
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : $default;
}

/* ---------- Connexion enseignant ---------- */

/** Limitation des tentatives de connexion administrateur (par adresse IP). */
function admin_throttle_file()
{
    return TPW_STATE . '/login_attempts.json';
}

function admin_throttle_key()
{
    return hash('sha256', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
}

function admin_login_blocked()
{
    $data = load_json(admin_throttle_file(), []);
    $key = admin_throttle_key();
    return isset($data[$key]['until']) && (int) $data[$key]['until'] > time();
}

function admin_login_failed()
{
    $data = load_json(admin_throttle_file(), []);
    $now = time();
    foreach ($data as $k => $row) {
        if (!isset($row['last']) || (int) $row['last'] < $now - 3600) unset($data[$k]);
    }
    $key = admin_throttle_key();
    $count = (isset($data[$key]['count']) ? (int) $data[$key]['count'] : 0) + 1;
    $data[$key] = ['count' => $count, 'last' => $now, 'until' => $count >= 5 ? $now + 300 : 0];
    atomic_json(admin_throttle_file(), $data);
}

function admin_login_reset()
{
    $data = load_json(admin_throttle_file(), []);
    unset($data[admin_throttle_key()]);
    atomic_json(admin_throttle_file(), $data);
}

/* ---------- Règles de publication ---------- */

function level_ok($level, array $data)
{
    return in_array($level, $data['levels'], true);
}

/** Fin effective d'un examen pour un élève (tiers-temps : prolongation pour certains groupes). */
function exam_end_for(array $exam, $level, $groups = null, array $data = null)
{
    $end = parse_datetime(isset($exam['until']) ? $exam['until'] : '');
    if ($end === null) return null;
    $extra = isset($exam['extra_minutes']) ? (int) $exam['extra_minutes'] : 0;
    $extraGroups = isset($exam['extra_groups']) && is_array($exam['extra_groups']) ? $exam['extra_groups'] : [];
    if ($extra > 0 && $extraGroups) {
        if ($groups === null) $groups = viewer_groups($level, $data);
        if (array_intersect($groups, $extraGroups)) $end += $extra * 60;
    }
    return $end;
}

/** Dernière fin possible d'un examen (avec prolongation). */
function exam_last_end(array $exam)
{
    $end = parse_datetime(isset($exam['until']) ? $exam['until'] : '');
    if ($end === null) return null;
    $extra = isset($exam['extra_minutes']) ? (int) $exam['extra_minutes'] : 0;
    return !empty($exam['extra_groups']) && $extra > 0 ? $end + $extra * 60 : $end;
}

/** Dossiers d'examen actifs pour une classe (null = pas d'examen en cours). */
function exam_folders_for($level, array $data, $groups = null)
{
    $folders = [];
    foreach ($data['exams'] as $exam) {
        if (!is_array($exam) || empty($exam['levels']) || !in_array($level, $exam['levels'], true)) continue;
        $end = exam_end_for($exam, $level, $groups, $data);
        if ($end !== null && time() >= $end) continue;
        $folders[] = (string) $exam['path'];
    }
    return $folders ? $folders : null;
}

/** Examen en cours pour un élève (pour l'affichage du compte à rebours), ou null. */
function exam_for_viewer($level, array $data, $groups = null)
{
    foreach ($data['exams'] as $exam) {
        if (!is_array($exam) || empty($exam['levels']) || !in_array($level, $exam['levels'], true)) continue;
        $end = exam_end_for($exam, $level, $groups, $data);
        if ($end !== null && time() < $end) return $exam + ['end_ts' => $end];
    }
    return null;
}

/** L'élément est-il dans la banque de ressources (jamais visible des élèves) ? */
function in_bank($path)
{
    return path_within((string) $path, TPW_BANK);
}

/** La règle est-elle dans sa période de publication ? */
function rule_in_window(array $rule)
{
    return window_open(isset($rule['from']) ? $rule['from'] : '', isset($rule['until']) ? $rule['until'] : '');
}

/**
 * Un élément est-il visible pour une classe ?
 * - Mode examen : seuls les dossiers d'examen de la classe sont visibles.
 * - Sinon, les règles explicites d'un enfant remplacent celles du parent ; sans règle, l'élément hérite.
 * - Une publication programmée hors de sa période se comporte comme « Masquer ».
 */
function rule_for($path, $level, array $data, $groups = null)
{
    if (in_bank($path)) return false;
    if ($groups === null) $groups = viewer_groups($level, $data);
    // Corrigé d'un dépôt : invisible jusqu'à la fermeture, puis publié pour les classes du dépôt.
    $solution = solution_visibility($path, $level, $groups);
    if ($solution === false) return false;
    $exam = exam_folders_for($level, $data, $groups);
    if ($exam !== null) {
        foreach ($exam as $folder) {
            if (path_within($path, $folder)) return true;
        }
        return false;
    }

    $chain = [''];
    $current = '';
    if ($path !== '') {
        foreach (explode('/', $path) as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            $chain[] = $current;
        }
    }

    if ($solution === true) return true;
    $visible = false;
    foreach ($chain as $p) {
        if (!isset($data['rules'][$p]) || !is_array($data['rules'][$p])) continue;
        $rule = $data['rules'][$p];
        $mode = isset($rule['mode']) ? $rule['mode'] : '';
        if ($mode === 'hide') {
            $visible = false;
        } elseif ($mode === 'publish') {
            $visible = rule_in_window($rule) && target_match(isset($rule['levels']) ? $rule['levels'] : [], $level, $groups);
        }
    }
    return $visible;
}

/** Un dossier est navigable s'il est publié ou s'il contient un élément publié. */
function can_browse($folder, $level, array $data, $groups = null)
{
    if (in_bank($folder)) return false;
    if ($groups === null) $groups = viewer_groups($level, $data);
    $exam = exam_folders_for($level, $data, $groups);
    if ($exam !== null) {
        foreach ($exam as $f) {
            if (path_within($folder, $f) || path_within($f, $folder)) return true;
        }
        return false;
    }

    if (rule_for($folder, $level, $data, $groups)) return true;

    $prefix = $folder === '' ? '' : $folder . '/';
    foreach ($data['rules'] as $path => $rule) {
        $path = (string) $path;
        if ($path === $folder || strpos($path, $prefix) !== 0) continue;
        if ((full_path($path, 'dir') || full_path($path, 'file')) && rule_for($path, $level, $data, $groups)) {
            return true;
        }
    }
    return false;
}

/** Classes entières pour lesquelles un élément est effectivement visible (hors mode examen). */
function visible_levels($path, array $data)
{
    $data['exams'] = [];
    $out = [];
    foreach ($data['levels'] as $level) {
        if (rule_for($path, $level, $data, [])) $out[] = $level;
    }
    return $out;
}

/** Classes et groupes qui voient un élément (un groupe n'est listé que si sa classe entière ne le voit pas). */
function visible_targets($path, array $data)
{
    $data['exams'] = [];
    $out = [];
    foreach ($data['levels'] as $level) {
        if (rule_for($path, $level, $data, [])) { $out[] = $level; continue; }
        foreach (group_names($data, $level) as $g) {
            if (rule_for($path, $level, $data, [$g])) $out[] = group_key($level, $g);
        }
    }
    return $out;
}

/* ---------- Codes de classes ---------- */

function class_code_enabled($level, $codes = null)
{
    if ($codes === null) $codes = class_access_data();
    return isset($codes[$level]['hash']) && is_string($codes[$level]['hash']) && $codes[$level]['hash'] !== '';
}

/**
 * Accès des élèves réservé à l'ENT : les classes ne sont plus ouvertes avec un code,
 * l'élève doit se connecter par l'ENT (et figurer dans la liste des élèves).
 * Sans connexion ENT active, ce réglage est ignoré (pour ne jamais bloquer le portail).
 */
function students_ent_only(array $data = null)
{
    if ($data === null) $data = settings();
    $ent = $data['ent'];
    return isset($data['student_access']) && $data['student_access'] === 'ent'
        && !empty($ent['enabled']) && !empty($ent['url']) && !empty($ent['students']);
}

function class_allowed($level)
{
    if (logged()) return true; // enseignant connecté : aperçu de toutes les classes
    $st = current_student();
    if ($st && $st['class'] === $level) return true; // élève connecté par l'ENT : sa classe est ouverte
    if (students_ent_only()) return false;
    $codes = class_access_data();
    if (!class_code_enabled($level, $codes)) return true;
    return !empty($_SESSION['class_auth'][$level])
        && hash_equals($codes[$level]['hash'], (string) $_SESSION['class_auth'][$level]);
}

/** Bloque l'accès à une ressource si la classe est inconnue ou non déverrouillée. */
function class_guard($level)
{
    if (!level_ok($level, settings())) {
        fail(404, 'Classe introuvable', 'Cette classe n’existe pas ou a été renommée.');
    }
    if (!class_allowed($level)) {
        // Redirection vers la saisie du code, sans transmettre le chemin du document.
        redirect('index.php?level=' . rawurlencode($level));
    }
}

function validate_class_code($level, $code)
{
    if (!level_ok($level, settings()) || students_ent_only()) return false;

    $codes = class_access_data();
    if (!class_code_enabled($level, $codes)) return true;

    $now = time();
    $attempts = isset($_SESSION['attempts'][$level]) ? $_SESSION['attempts'][$level] : ['count' => 0, 'until' => 0];
    if ((int) $attempts['until'] > $now) return false;

    $candidate = isset($codes[$level]['display']) ? strtoupper($code) : $code;
    if (password_verify($candidate, $codes[$level]['hash'])) {
        session_regenerate_id(true);
        $_SESSION['class_auth'][$level] = $codes[$level]['hash'];
        unset($_SESSION['attempts'][$level]);
        return true;
    }

    $count = (int) $attempts['count'] + 1;
    $_SESSION['attempts'][$level] = ['count' => $count, 'until' => $count >= 5 ? $now + 300 : 0];
    return false;
}

function class_limit_message($level)
{
    $attempts = isset($_SESSION['attempts'][$level]) ? $_SESSION['attempts'][$level] : [];
    if (!empty($attempts['until']) && $attempts['until'] > time()) {
        return 'Trop de tentatives. Réessayez dans cinq minutes.';
    }
    return 'Code incorrect. Vérifiez le code communiqué par votre professeur.';
}

/* ---------- Mode examen ---------- */

/** Examens en cours (non terminés). */
function active_exams(array $data)
{
    return array_values(array_filter($data['exams'], function ($e) {
        if (!is_array($e)) return false;
        $end = exam_last_end($e);
        return $end === null || time() < $end;
    }));
}

/* ---------- Annonces ---------- */

/** Annonces en cours (toutes classes, ou pour une classe donnée). */
function active_announcements(array $data, $level = null)
{
    $out = array_values(array_filter($data['announcements'], function ($a) use ($level) {
        if (!is_array($a) || !window_open(isset($a['from']) ? $a['from'] : '', isset($a['until']) ? $a['until'] : '')) return false;
        return $level === null || target_match(isset($a['levels']) ? $a['levels'] : [], $level);
    }));
    usort($out, function ($a, $b) {
        $pa = $a['type'] === 'important' ? 0 : 1;
        $pb = $b['type'] === 'important' ? 0 : 1;
        return $pa !== $pb ? $pa - $pb : strcmp($b['created'], $a['created']);
    });
    return $out;
}

/* ---------- Nouveautés et dossier de classe ---------- */

/** Date de publication de la règle qui s'applique à un chemin (0 si inconnue). */
function rule_published_at($path, array $data)
{
    $at = 0;
    $current = '';
    $chain = [''];
    if ($path !== '') {
        foreach (explode('/', $path) as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            $chain[] = $current;
        }
    }
    foreach ($chain as $p) {
        if (isset($data['rules'][$p]['mode']) && $data['rules'][$p]['mode'] === 'publish') {
            $at = isset($data['rules'][$p]['published_at']) ? (int) $data['rules'][$p]['published_at'] : 0;
            $from = parse_datetime(isset($data['rules'][$p]['from']) ? $data['rules'][$p]['from'] : '');
            if ($from !== null && $from > $at) $at = $from;
        }
    }
    return $at;
}

/** Moment où l'élément est devenu « nouveau » pour les élèves (ajout ou publication). */
function news_time($path, array $data)
{
    $abs = full_path($path, 'any');
    return max($abs ? (int) filemtime($abs) : 0, rule_published_at($path, $data));
}

function is_new($path, array $data)
{
    return news_time($path, $data) >= time() - TPW_NEW_DAYS * 86400;
}

/** Dossier racine portant le nom de la classe (dossiers « Classe/Matière »), ou ''. */
function class_home_folder($level)
{
    $name = safe_name($level);
    return ($name !== '' && full_path($name, 'dir')) ? $name : '';
}

/* ---------- Jeton d'affichage enseignant (projection des cours HTML) ---------- */

function view_key()
{
    $file = TPW_STATE . '/secrets.json';
    $s = load_json($file, []);
    if (empty($s['view_key'])) {
        $s['view_key'] = bin2hex(random_bytes(32));
        atomic_json($file, $s);
    }
    return $s['view_key'];
}

/** Jeton valable 4 heures pour lire les fichiers d'un dossier. */
function teacher_view_token($root)
{
    $exp = time() + 4 * 3600;
    return $exp . '.' . hash_hmac('sha256', $root . '|' . $exp, view_key());
}

/** Dossier autorisé par un jeton valide, ou null. */
function teacher_view_root($token, $root)
{
    if (!is_string($token) || !preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $token, $m) || (int) $m[1] < time()) return null;
    if (!valid_path((string) $root)) return null;
    return hash_equals(hash_hmac('sha256', $root . '|' . $m[1], view_key()), $m[2]) ? (string) $root : null;
}

/* ---------- Corrigés publiés à la fermeture d'un dépôt ---------- */

/** Corrigés configurés : chemin du corrigé => configuration du dépôt. */
function solution_map()
{
    static $cache = null, $rev = -1;
    $current = isset($GLOBALS['tpw_deposits_rev']) ? $GLOBALS['tpw_deposits_rev'] : 0;
    if ($cache !== null && $rev === $current) return $cache;
    $rev = $current;
    $cache = [];
    foreach (deposits_config() as $path => $cfg) {
        if (!is_array($cfg) || empty($cfg['enabled']) || empty($cfg['solution']) || !is_string($cfg['solution'])) continue;
        $sol = (string) $path . '/' . $cfg['solution'];
        if (valid_path($sol)) $cache[$sol] = deposit_normalize($cfg);
    }
    return $cache;
}

/**
 * Visibilité d'un élément qui fait partie d'un corrigé : null (pas un corrigé),
 * false (pas encore publié), true (dépôt clos : visible pour les classes du dépôt).
 */
function solution_visibility($path, $level, $groups)
{
    foreach (solution_map() as $sol => $cfg) {
        if (!path_within((string) $path, $sol)) continue;
        if (deposit_state($cfg) !== 'closed') return false;
        return target_match($cfg['levels'], $level, $groups);
    }
    return null;
}

/** Date de publication du corrigé (horodatage) ou null si le dépôt n'a pas de fin. */
function solution_release_time(array $cfg)
{
    $until = parse_datetime($cfg['until']);
    if ($until === null) return null;
    if ($cfg['late'] === 'accept') {
        $late = parse_datetime($cfg['late_until']);
        return $late === null ? null : $late;
    }
    return $until;
}
