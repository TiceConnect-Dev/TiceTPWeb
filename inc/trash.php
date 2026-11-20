<?php
/**
 * Corbeille : les éléments supprimés sont conservés TPW_TRASH_DAYS jours
 * (avec leurs règles de publication, dépôts et attributions) et peuvent être restaurés.
 */

function trash_index()
{
    return load_json(TPW_TRASH . '/index.json', []);
}

function save_trash_index(array $list)
{
    atomic_json(TPW_TRASH . '/index.json', array_values($list));
}

/** Copie récursive (fichier ou dossier), sans suivre les liens. */
function copy_tree($src, $dst)
{
    if (is_link($src)) return true;
    if (is_file($src)) return @copy($src, $dst);
    if (!is_dir($dst) && !@mkdir($dst, 0750, true)) return false;
    $ok = true;
    foreach ((array) @scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        $ok = copy_tree($src . DIRECTORY_SEPARATOR . $item, $dst . DIRECTORY_SEPARATOR . $item) && $ok;
    }
    return $ok;
}

/** Déplace sur le disque (rename, ou copie + suppression si besoin). */
function move_tree($src, $dst)
{
    if (@rename($src, $dst)) return true;
    if (copy_tree($src, $dst)) {
        remove_tree($src);
        return true;
    }
    return false;
}

/** Données liées à un chemin (règles, dépôts, attributions) pour pouvoir les restaurer. */
function snapshot_links($path)
{
    $data = settings();
    $snap = ['rules' => [], 'deposits' => [], 'users' => []];
    foreach ($data['rules'] as $p => $r) if (path_within((string) $p, $path)) $snap['rules'][(string) $p] = $r;
    foreach (deposits_config() as $p => $c) if (path_within((string) $p, $path)) $snap['deposits'][(string) $p] = $c;
    foreach (users_all() as $login => $u) {
        $f = array_values(array_filter($u['folders'], function ($x) use ($path) { return path_within($x, $path); }));
        if ($f) $snap['users'][(string) $login] = $f;
    }
    return $snap;
}

/** Réapplique un instantané, en remplaçant l'ancien chemin par le nouveau. */
function restore_links(array $snap, $old, $new)
{
    $map = function ($p) use ($old, $new) { return $new . substr((string) $p, strlen($old)); };
    $data = settings();
    foreach ($snap['rules'] as $p => $r) $data['rules'][$map($p)] = $r;
    save_settings($data);
    $deposits = deposits_config();
    foreach ($snap['deposits'] as $p => $c) $deposits[$map($p)] = $c;
    save_deposits_config($deposits);
    $users = users_all();
    foreach ($snap['users'] as $login => $folders) {
        if (!isset($users[$login])) continue;
        foreach ($folders as $f) $users[$login]['folders'][] = $map($f);
        $users[$login]['folders'] = array_values(array_unique($users[$login]['folders']));
    }
    save_users($users);
}

/** Envoie un élément du dossier des cours à la corbeille. */
function trash_add($path, array $user)
{
    $abs = full_path($path, 'any');
    if (!$abs || $path === '') return 'Élément introuvable.';
    $id = bin2hex(random_bytes(8));
    $dir = TPW_TRASH . DIRECTORY_SEPARATOR . $id;
    if (!@mkdir($dir, 0750, true)) return 'Impossible d’écrire dans la corbeille.';
    $isDir = is_dir($abs);
    $snap = snapshot_links($path);
    if (!move_tree($abs, $dir . DIRECTORY_SEPARATOR . basename($abs))) {
        @rmdir($dir);
        return 'La suppression a échoué (fichier ouvert ?).';
    }
    rekey_paths($path, null);
    $list = trash_index();
    $list[] = [
        'id' => $id, 'path' => $path, 'name' => basename($path), 'type' => $isDir ? 'dir' : 'file',
        'by' => $user['login'], 'time' => time(), 'links' => $snap,
    ];
    save_trash_index($list);
    return true;
}

function trash_find($id)
{
    foreach (trash_index() as $e) if ($e['id'] === $id) return $e;
    return null;
}

/** L'utilisateur peut-il voir / restaurer cet élément ? */
function trash_visible(array $e, array $user)
{
    return is_admin($user) || $e['by'] === $user['login'] || can_manage($e['path'], $user);
}

/** Restaure un élément ; retourne le nouveau chemin ou un message d'erreur. */
function trash_restore($id)
{
    $e = trash_find($id);
    if (!$e) return 'Élément introuvable dans la corbeille.';
    $src = TPW_TRASH . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . $e['name'];
    if (!file_exists($src)) return 'Les fichiers de cet élément ont disparu de la corbeille.';

    $parent = dirname($e['path']) === '.' ? '' : dirname($e['path']);
    $parentAbs = TPW_COURSES . ($parent === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $parent));
    if (!is_dir($parentAbs) && !@mkdir($parentAbs, 0750, true)) return 'Impossible de recréer le dossier d’origine.';
    $name = unique_name($parentAbs, $e['name']);
    if (!move_tree($src, $parentAbs . DIRECTORY_SEPARATOR . $name)) return 'La restauration a échoué.';
    @rmdir(TPW_TRASH . DIRECTORY_SEPARATOR . $id);

    $newPath = $parent === '' ? $name : $parent . '/' . $name;
    if (!empty($e['links'])) restore_links($e['links'], $e['path'], $newPath);
    save_trash_index(array_filter(trash_index(), function ($x) use ($id) { return $x['id'] !== $id; }));
    return $newPath;
}

function trash_delete($id)
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return;
    remove_tree(TPW_TRASH . DIRECTORY_SEPARATOR . $id);
    save_trash_index(array_filter(trash_index(), function ($x) use ($id) { return $x['id'] !== $id; }));
}

/** Supprime définitivement les éléments plus anciens que la durée de conservation. */
function trash_purge_old()
{
    $limit = time() - TPW_TRASH_DAYS * 86400;
    foreach (trash_index() as $e) {
        if ($e['time'] < $limit) trash_delete($e['id']);
    }
}

function trash_empty()
{
    foreach (trash_index() as $e) trash_delete($e['id']);
}

function tree_size($path)
{
    if (is_file($path)) return filesize($path);
    $total = 0;
    foreach ((array) @scandir($path) as $i) {
        if ($i === '.' || $i === '..') continue;
        $total += tree_size($path . DIRECTORY_SEPARATOR . $i);
    }
    return $total;
}
