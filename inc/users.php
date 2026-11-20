<?php
/**
 * Comptes enseignants et permissions.
 *
 * users.json : { "identifiant": { "name", "role": "admin"|"prof", "hash", "folders": [...] } }
 * - admin : gère tout (classes, comptes, sauvegardes, paramètres) ;
 * - prof  : gère uniquement les dossiers qui lui sont attribués (et leur contenu).
 * L'ancien fichier admin.json (versions ≤ 10) est migré automatiquement vers le compte « admin ».
 */

function users_file()
{
    return TPW_STATE . '/users.json';
}

function users_all($reset = false)
{
    static $cache = null;
    if ($reset) $cache = null;
    if ($cache !== null) return $cache;

    $users = load_json(users_file(), []);
    if (!$users && is_file(TPW_STATE . '/admin.json')) {
        $old = load_json(TPW_STATE . '/admin.json', []);
        if (!empty($old['hash'])) {
            $users = ['admin' => ['name' => 'Administrateur', 'role' => 'admin', 'hash' => $old['hash'], 'folders' => []]];
            atomic_json(users_file(), $users);
        }
    }
    foreach ($users as $login => $u) {
        $users[$login] += ['name' => (string) $login, 'role' => 'prof', 'hash' => '', 'folders' => [], 'ent_id' => '',
                           'perms' => null, 'subject' => '', 'classes' => [], 'quota_mb' => 0,
                           'totp' => '', 'totp_backup' => [], 'totp_last' => 0, 'pending' => false];
        if (!is_array($users[$login]['totp_backup'])) $users[$login]['totp_backup'] = [];
        if (!is_array($users[$login]['folders'])) $users[$login]['folders'] = [];
        if (!is_array($users[$login]['classes'])) $users[$login]['classes'] = [];
        $users[$login]['perms'] = normalize_perms($users[$login]['perms']);
    }
    return $cache = $users;
}

function save_users(array $users)
{
    ksort($users);
    atomic_json(users_file(), $users);
    users_all(true);
}

function configured()
{
    return (bool) users_all();
}

function valid_login($login)
{
    return is_string($login) && preg_match('/^[a-z0-9._-]{2,32}$/D', $login);
}

function find_user($login)
{
    $users = users_all();
    return isset($users[$login]) ? ['login' => (string) $login] + $users[$login] : null;
}

/** Utilisateur connecté (ou null). */
function current_user()
{
    if (empty($_SESSION['user'])) return null;
    $u = find_user((string) $_SESSION['user']);
    return $u && empty($u['pending']) ? $u : null;
}

/** Comptes créés par l'ENT qui attendent la validation de l'administrateur. */
function pending_users()
{
    return array_filter(users_all(), function ($u) { return !empty($u['pending']); });
}

function logged()
{
    return current_user() !== null;
}

function is_admin($user = null)
{
    if ($user === null) $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

function login_user($login)
{
    session_regenerate_id(true);
    $_SESSION['user'] = $login;
}

function admin_count()
{
    return count(array_filter(users_all(), function ($u) {
        return $u['role'] === 'admin';
    }));
}

/* ---------- Permissions sur les dossiers ---------- */

function path_within($path, $root)
{
    return $root === '' || $path === $root || strpos($path, $root . '/') === 0;
}

/**
 * Dossiers racines gérés par l'utilisateur ('' = tout) :
 * dossiers attribués + dossiers partagés par un collègue (co-enseignement) + banque de sa matière.
 */
function managed_roots($user = null)
{
    if ($user === null) $user = current_user();
    if ($user === null) return [];
    if ($user['role'] === 'admin') return [''];
    $roots = $user['folders'];
    foreach (coteach_map() as $path => $logins) {
        if (in_array($user['login'], $logins, true)) $roots[] = (string) $path;
    }
    $bank = bank_folder($user['subject']);
    if ($bank !== '' && can('bank', $user)) $roots[] = $bank;
    return array_values(array_unique(array_filter($roots, function ($f) {
        return is_string($f) && $f !== '' && full_path($f, 'dir');
    })));
}

/* ---------- Co-enseignement ---------- */

/** Dossiers partagés : chemin => [identifiants des collègues]. */
function coteach_map()
{
    static $cache = null, $rev = -1;
    $current = isset($GLOBALS['tpw_settings_rev']) ? $GLOBALS['tpw_settings_rev'] : 0;
    if ($cache !== null && $rev === $current) return $cache;
    $rev = $current;
    $data = settings();
    $out = [];
    foreach ($data['coteach'] as $path => $logins) {
        if (is_array($logins) && $logins) $out[(string) $path] = array_values(array_filter($logins, 'is_string'));
    }
    return $cache = $out;
}

function coteachers_of($path)
{
    $map = coteach_map();
    return isset($map[$path]) ? $map[$path] : [];
}

/* ---------- Banque de ressources ---------- */

/** Dossier de la banque pour une matière ('' si pas de matière). */
function bank_folder($subject)
{
    $s = safe_name(trim((string) $subject));
    return $s === '' ? '' : TPW_BANK . '/' . $s;
}

/** Crée le dossier de banque d'une matière (et la racine), retourne son chemin ou ''. */
function ensure_bank_folder($subject)
{
    $path = bank_folder($subject);
    if ($path === '') return '';
    $abs = TPW_COURSES . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_dir($abs) && !@mkdir($abs, 0750, true)) return '';
    return $path;
}

function can_manage($path, $user = null)
{
    foreach (managed_roots($user) as $root) {
        if (path_within($path, $root)) return true;
    }
    return false;
}

/** Libellé lisible d'un rôle. */
function role_label($role)
{
    return $role === 'admin' ? 'Administrateur' : 'Enseignant';
}

/* ---------- Droits des enseignants ---------- */

/** Droits réglables par compte enseignant : clé => [libellé, valeur par défaut]. */
function perm_definitions()
{
    return [
        'publish'       => ['Publier, masquer et programmer ses cours', true],
        'upload'        => ['Ajouter des fichiers et des liens', true],
        'folders'       => ['Créer des sous-dossiers', true],
        'manage'        => ['Renommer, déplacer, dupliquer et supprimer', true],
        'deposits'      => ['Ouvrir des dépôts et gérer les rendus des élèves', true],
        'exams'         => ['Lancer le mode examen', true],
        'announcements' => ['Publier des annonces', true],
        'own_classes'   => ['Choisir lui-même sa matière et ses classes (dossiers créés automatiquement)', true],
        'groups'        => ['Créer des groupes dans ses classes (groupes de besoin, spécialités, tiers-temps)', true],
        'coteach'       => ['Partager ses dossiers avec des collègues (co-enseignement)', true],
        'bank'          => ['Utiliser la banque de ressources de sa matière', true],
        'students'      => ['Gérer la liste des élèves de ses classes (ajout, import, groupes)', false],
        'codes'         => ['Gérer les codes d’accès des classes', false],
    ];
}

function normalize_perms($perms)
{
    $out = [];
    foreach (perm_definitions() as $key => $def) {
        $out[$key] = is_array($perms) && array_key_exists($key, $perms) ? (bool) $perms[$key] : $def[1];
    }
    return $out;
}

/** L'utilisateur a-t-il ce droit ? (un administrateur a tous les droits) */
function can($perm, $user = null)
{
    if ($user === null) $user = current_user();
    if ($user === null) return false;
    if ($user['role'] === 'admin') return true;
    return !empty($user['perms'][$perm]);
}

/* ---------- Dossiers de matière (Classe/Matière) ---------- */

/** Chemin du dossier d'une matière dans une classe. */
function subject_folder_path($class, $subject)
{
    $c = safe_name($class);
    $s = safe_name($subject);
    return ($c === '' || $s === '') ? '' : $c . '/' . $s;
}

/**
 * Crée les dossiers « Classe/Matière » d'un enseignant, les lui attribue
 * et les publie pour la classe correspondante. Retourne le nombre de dossiers créés.
 */
function ensure_subject_folders($login)
{
    $users = users_all();
    if (!isset($users[$login])) return 0;
    $u = $users[$login];
    $subject = trim((string) $u['subject']);
    if ($subject !== '') ensure_bank_folder($subject);
    if ($subject === '' || !$u['classes']) return 0;

    $data = settings();
    $created = 0;
    foreach ($u['classes'] as $class) {
        if (!in_array($class, $data['levels'], true)) continue;
        $path = subject_folder_path($class, $subject);
        if ($path === '') continue;
        $abs = TPW_COURSES . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_dir($abs)) {
            if (!@mkdir($abs, 0750, true)) continue;
            $created++;
        }
        if (!in_array($path, $u['folders'], true)) $u['folders'][] = $path;
        if (!isset($data['rules'][$path])) {
            $data['rules'][$path] = ['mode' => 'publish', 'levels' => [$class], 'from' => '', 'until' => '', 'published_at' => time()];
        }
    }
    save_settings($data);
    $users[$login] = $u;
    save_users($users);
    return $created;
}
