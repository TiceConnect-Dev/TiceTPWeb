<?php
/**
 * Espace disque : taille des dossiers, quotas par enseignant, tableau de bord.
 * Le quota d'un enseignant porte sur ses propres dossiers (dossiers de matière et dossiers attribués),
 * pas sur la banque de ressources ni sur les dossiers partagés par un collègue.
 */

/** Taille d'un dossier ou d'un fichier (sans suivre les liens), mise en cache pour la requête. */
function dir_size($abs)
{
    static $cache = [];
    if (isset($cache[$abs])) return $cache[$abs];
    if (is_link($abs)) return $cache[$abs] = 0;
    if (is_file($abs)) return $cache[$abs] = (int) filesize($abs);
    $total = 0;
    foreach ((array) @scandir($abs) as $item) {
        if ($item === '.' || $item === '..') continue;
        $total += dir_size($abs . DIRECTORY_SEPARATOR . $item);
    }
    return $cache[$abs] = $total;
}

/** Dossiers propres d'un enseignant (sans doublons imbriqués). */
function user_own_roots(array $user)
{
    $roots = array_values(array_filter($user['folders'], function ($f) {
        return is_string($f) && $f !== '' && !in_bank($f) && full_path($f, 'dir');
    }));
    sort($roots);
    $out = [];
    foreach ($roots as $r) {
        $nested = false;
        foreach ($out as $o) if (path_within($r, $o)) { $nested = true; break; }
        if (!$nested) $out[] = $r;
    }
    return $out;
}

function user_usage(array $user)
{
    $total = 0;
    foreach (user_own_roots($user) as $r) {
        $abs = full_path($r, 'dir');
        if ($abs) $total += dir_size($abs);
    }
    return $total;
}

function user_quota_bytes(array $user)
{
    $mb = isset($user['quota_mb']) ? (int) $user['quota_mb'] : 0;
    return $mb > 0 ? $mb * 1024 * 1024 : 0;
}

/**
 * Message d'erreur si l'ajout de $bytes dans $path dépasse un quota ou l'espace disque, sinon ''.
 * $path = 'rendus' pour les dépôts des élèves (seul l'espace disque est vérifié).
 */
function quota_error_for_path($path, $bytes)
{
    $free = @disk_free_space(TPW_DATA);
    if ($free !== false && $free - $bytes < 100 * 1024 * 1024) {
        return 'Le serveur manque d’espace disque. Prévenez l’administrateur du portail.';
    }
    if ($path === 'rendus' || in_bank($path)) return '';
    foreach (users_all() as $login => $u) {
        $quota = user_quota_bytes($u);
        if (!$quota) continue;
        $u = ['login' => (string) $login] + $u;
        $concerned = false;
        foreach (user_own_roots($u) as $r) if (path_within($path, $r)) { $concerned = true; break; }
        if (!$concerned) continue;
        $used = user_usage($u);
        if ($used + $bytes > $quota) {
            return 'Quota de stockage atteint pour ' . $u['name'] . ' : ' . human_size($used) . ' utilisés sur ' . human_size($quota)
                . '. Supprimez des fichiers (puis videz la corbeille) ou demandez à l’administrateur d’augmenter le quota.';
        }
    }
    return '';
}

/** Taille totale des fichiers envoyés dans un formulaire. */
function uploaded_total(array $files)
{
    $t = 0;
    foreach ($files as $f) if ($f['error'] === UPLOAD_ERR_OK) $t += (int) $f['size'];
    return $t;
}

/** Vue d'ensemble de l'espace disque (tableau de bord). */
function storage_overview()
{
    $parts = [
        'cours' => ['Cours', TPW_COURSES], 'rendus' => ['Rendus et résultats des élèves', TPW_RENDUS],
        'corbeille' => ['Corbeille', TPW_TRASH], 'archives' => ['Archives de fin d’année', TPW_ARCHIVES],
        'sauvegardes' => ['Sauvegardes', TPW_BACKUPS], 'cache' => ['Aperçus (cache)', TPW_CACHE],
    ];
    $out = ['parts' => [], 'total' => 0];
    foreach ($parts as $k => $p) {
        $size = is_dir($p[1]) ? dir_size($p[1]) : 0;
        $out['parts'][$k] = ['label' => $p[0], 'size' => $size];
        $out['total'] += $size;
    }
    $out['free'] = @disk_free_space(TPW_DATA);
    $out['disk'] = @disk_total_space(TPW_DATA);
    return $out;
}
