<?php
/**
 * Espace enseignant : traitement des formulaires et des téléchargements.
 * Chaque action redirige en cas de succès, ou retourne un message d'erreur.
 */

function flash_redirect($message, $url)
{
    $_SESSION['flash'] = $message;
    redirect($url);
}

function parent_path($path)
{
    $parent = dirname($path);
    return ($parent === '.' || $parent === DIRECTORY_SEPARATOR) ? '' : str_replace('\\', '/', $parent);
}

/** Classes (et groupes si $withGroups) cochées dans le formulaire, dans l'ordre de référence. */
function selected_levels(array $data, $withGroups = false)
{
    $selected = isset($_POST['levels']) && is_array($_POST['levels']) ? $_POST['levels'] : [];
    return array_values(array_intersect($withGroups ? all_targets($data) : $data['levels'], $selected));
}

function handle_admin_post($action)
{
    /* ----- Sans connexion ----- */

    if ($action === 'setup' && !configured()) {
        $login = strtolower(trim(post('login')));
        $name = trim(post('name'));
        $pass = post('password');
        if (!valid_login($login)) return 'Identifiant invalide : 2 à 32 caractères (lettres minuscules, chiffres, point, tiret).';
        if ($name === '' || mb_strlen($name) > 60) return 'Indiquez votre nom (60 caractères maximum).';
        if (strlen($pass) < TPW_PASSWORD_MIN) return 'Choisissez un mot de passe de ' . TPW_PASSWORD_MIN . ' caractères minimum.';
        if ($pass !== post('password_confirm')) return 'Les deux mots de passe ne correspondent pas.';
        save_users([$login => ['name' => $name, 'role' => 'admin', 'hash' => password_hash($pass, PASSWORD_DEFAULT), 'folders' => []]]);
        login_user($login);
        audit_pending('setup', [], $login);
        redirect('admin.php');
    }

    if ($action === 'login' && configured()) {
        if (admin_login_blocked()) return 'Trop de tentatives. Réessayez dans cinq minutes.';
        $login = strtolower(trim(post('login')));
        $user = find_user($login);
        if ($user && $user['hash'] !== '' && password_verify(post('password'), $user['hash'])) {
            admin_login_reset();
            redirect(login_or_second_factor($login, 'password'));
        }
        audit_log('login_failed', ['identifiant' => mb_substr($login, 0, 40)], '-');
        admin_login_failed();
        sleep(1);
        return 'Identifiant ou mot de passe incorrect.';
    }

    if ($action === 'login_2fa') {
        $p = isset($_SESSION['pending_2fa']) ? $_SESSION['pending_2fa'] : null;
        if (!$p || $p['time'] < time() - 600) { unset($_SESSION['pending_2fa']); return 'Délai dépassé : reconnectez-vous.'; }
        if (admin_login_blocked()) return 'Trop de tentatives. Réessayez dans cinq minutes.';
        $users = users_all();
        if (!isset($users[$p['login']])) return 'Compte introuvable.';
        $u = $users[$p['login']];
        $code = trim(post('code'));
        $step = totp_verify($u['totp'], $code, $u['totp_last']);
        $usedBackup = false;
        if ($step === false) {
            $rest = totp_use_backup($u['totp_backup'], $code);
            if ($rest !== false) { $u['totp_backup'] = $rest; $usedBackup = true; }
        } else {
            $u['totp_last'] = $step;
        }
        if ($step === false && !$usedBackup) {
            audit_log('login_2fa_failed', [], $p['login']);
            admin_login_failed();
            sleep(1);
            return 'Code incorrect. Vérifiez l’heure de votre téléphone, ou utilisez un code de secours.';
        }
        $users[$p['login']] = $u;
        save_users($users);
        admin_login_reset();
        unset($_SESSION['pending_2fa']);
        login_user($p['login']);
        audit_log($p['via'] === 'ent' ? 'ent_login' : 'login', ['double authentification' => $usedBackup ? 'code de secours' : 'oui'], $p['login']);
        if ($usedBackup) flash_redirect('Code de secours utilisé : il vous en reste ' . count($u['totp_backup']) . '. Pensez à en générer de nouveaux (Mon compte).', admin_url('account'));
        redirect('admin.php');
    }

    $user = current_user();
    if ($user === null) return '';

    if ($action === 'logout') {
        audit_pending('logout', [], $user['login']);
        $_SESSION = [];
        session_destroy();
        redirect('admin.php');
    }

    $data = settings();
    $admin = is_admin($user);

    // Droits nécessaires pour chaque action.
    $required = [
        'save' => 'publish', 'copy_publications' => 'publish',
        'upload' => 'upload', 'add_link' => 'upload',
        'mkdir' => 'folders',
        'rename' => 'manage', 'delete' => 'manage', 'move' => 'manage', 'duplicate' => 'manage',
        'trash_restore' => 'manage', 'trash_delete' => 'manage',
        'deposit_save' => 'deposits', 'deposit_delete_entry' => 'deposits', 'deposit_purge' => 'deposits', 'deposit_feedback' => 'deposits',
        'exam_start' => 'exams', 'exam_stop' => 'exams',
        'announce_save' => 'announcements', 'announce_delete' => 'announcements',
        'class_code' => 'codes',
        'my_classes' => 'own_classes',
        'copy_to' => 'upload', 'import_zip' => 'upload', 'quiz_create' => 'upload', 'quiz_save' => 'upload', 'quiz_reset' => 'upload', 'quiz_import' => 'upload', 'correction_save' => 'deposits', 'correction_delete' => 'deposits',
        'coteach_save' => 'coteach', 'groups_save' => 'groups', 'group_add' => 'groups', 'group_remove' => 'groups', 'group_members' => 'groups',
        'students_import' => 'students', 'student_save' => 'students', 'student_delete' => 'students', 'students_bulk' => 'students', 'student_request_decide' => 'students',
    ];
    if (isset($required[$action]) && !can($required[$action], $user)) {
        return 'Vous n’avez pas le droit d’effectuer cette action. Demandez à l’administrateur du portail.';
    }

    switch ($action) {

        /* ----- Bibliothèque : publication ----- */

        case 'save':
            $item = post('folder');
            $kind = post('kind', 'dir');
            $mode = post('mode');
            if (!in_array($kind, ['dir', 'file'], true) || !full_path($item, $kind) || !in_array($mode, ['hide', 'publish', 'inherit'], true)) {
                return 'Élément ou mode de publication invalide.';
            }
            if (!can_manage($item, $user)) return 'Vous ne gérez pas cet élément.';
            $from = clean_datetime(post('from'));
            $until = clean_datetime(post('until'));
            if ($from !== '' && $until !== '' && parse_datetime($until) <= parse_datetime($from)) {
                return 'La date de fin doit être postérieure à la date de début.';
            }
            if ($mode === 'inherit') {
                unset($data['rules'][$item]);
            } elseif ($mode === 'hide') {
                $data['rules'][$item] = ['mode' => 'hide', 'levels' => []];
            } else {
                $levels = selected_levels($data, true);
                $old = isset($data['rules'][$item]) ? $data['rules'][$item] : [];
                $changed = !isset($old['mode']) || $old['mode'] !== 'publish' || array_diff($levels, isset($old['levels']) ? $old['levels'] : []);
                $data['rules'][$item] = [
                    'mode' => 'publish', 'levels' => $levels, 'from' => $from, 'until' => $until,
                    'published_at' => $changed ? time() : (isset($old['published_at']) ? $old['published_at'] : time()),
                ];
            }
            save_settings($data);
            redirect(admin_url('library', $item) . '&saved=1#partage');

        /* ----- Bibliothèque : fichiers ----- */

        case 'upload':
            $dir = post('folder');
            $abs = full_path($dir, 'dir');
            if (!$abs || !can_manage($dir, $user)) return 'Dossier invalide.';
            $files = uploaded_files('files');
            if (!$files) return 'Choisissez au moins un fichier.';
            $quota = quota_error_for_path($dir, uploaded_total($files));
            if ($quota !== '') return $quota;
            $ok = 0;
            $errors = [];
            foreach ($files as $f) {
                $name = safe_name($f['name']);
                if ($f['error'] !== UPLOAD_ERR_OK) { $errors[] = $f['name'] . ' : ' . upload_error_message($f['error']); continue; }
                if ($name === '' || blocked_extension($name)) { $errors[] = $f['name'] . ' : type de fichier non autorisé'; continue; }
                $name = unique_name($abs, $name);
                if (is_uploaded_file($f['tmp_name']) && move_uploaded_file($f['tmp_name'], $abs . DIRECTORY_SEPARATOR . $name)) {
                    $ok++;
                } else {
                    $errors[] = $f['name'] . ' : erreur d’enregistrement';
                }
            }
            if ($errors) return ($ok ? $ok . ' fichier(s) ajouté(s). ' : '') . 'Refusé : ' . implode(' ; ', $errors) . '.';
            flash_redirect($ok . ' fichier' . ($ok > 1 ? 's ajoutés' : ' ajouté') . '.', admin_url('library', $dir));

        case 'add_link':
            $dir = post('folder');
            $abs = full_path($dir, 'dir');
            if (!$abs || !can_manage($dir, $user)) return 'Dossier invalide.';
            $url = clean_url(post('url'));
            if ($url === '') return 'Adresse invalide : elle doit commencer par http:// ou https://.';
            $name = write_url_file($abs, post('title'), $url);
            if ($name === '') return 'Titre invalide.';
            flash_redirect('Lien « ' . display_name($name) . ' » ajouté.', admin_url('library', $dir));

        case 'mkdir':
            $dir = post('folder');
            $abs = full_path($dir, 'dir');
            $name = safe_name(post('name'));
            if (!$abs || !can_manage($dir, $user)) return 'Dossier invalide.';
            if ($name === '') return 'Nom de dossier invalide.';
            if (file_exists($abs . DIRECTORY_SEPARATOR . $name)) return 'Un élément porte déjà ce nom.';
            if (!@mkdir($abs . DIRECTORY_SEPARATOR . $name, 0750)) return 'Impossible de créer le dossier.';
            $new = $dir === '' ? $name : $dir . '/' . $name;
            flash_redirect('Dossier « ' . $name . ' » créé.', admin_url('library', $new));

        case 'rename':
            $item = post('item');
            $name = safe_name(post('name'));
            $abs = full_path($item, 'any');
            $parent = parent_path($item);
            if ($item === '' || !$abs || !can_manage($parent, $user)) return 'Vous ne pouvez pas renommer cet élément.';
            if ($name === '' || (is_file($abs) && blocked_extension($name))) return 'Nouveau nom invalide.';
            if ($name === basename($item)) redirect(admin_url('library', $item));
            $target = dirname($abs) . DIRECTORY_SEPARATOR . $name;
            if (file_exists($target) && strcasecmp($name, basename($item)) !== 0) return 'Un élément porte déjà ce nom.';
            if (!@rename($abs, $target)) return 'Le renommage a échoué (fichier ouvert ?).';
            $new = $parent === '' ? $name : $parent . '/' . $name;
            rekey_paths($item, $new);
            flash_redirect('Élément renommé.', admin_url('library', $new));

        case 'delete':
            $item = post('item');
            $abs = full_path($item, 'any');
            $parent = parent_path($item);
            if ($item === '' || !$abs || !can_manage($parent, $user)) return 'Vous ne pouvez pas supprimer cet élément.';
            $res = trash_add($item, $user);
            if ($res !== true) return $res;
            flash_redirect('« ' . basename($item) . ' » a été placé dans la corbeille (restaurable pendant ' . TPW_TRASH_DAYS . ' jours).', admin_url('library', $parent));

        case 'move':
            $item = post('item');
            $dest = post('dest');
            if ($dest === '/') $dest = '';
            $abs = full_path($item, 'any');
            $destAbs = full_path($dest, 'dir');
            if ($item === '' || !$abs || !can_manage(parent_path($item), $user)) return 'Vous ne pouvez pas déplacer cet élément.';
            if (!$destAbs || !can_manage($dest, $user)) return 'Dossier de destination invalide.';
            if (path_within($dest, $item)) return 'Impossible de déplacer un dossier dans lui-même.';
            if ($dest === parent_path($item)) return 'L’élément est déjà dans ce dossier.';
            $name = unique_name($destAbs, basename($abs));
            if (!@rename($abs, $destAbs . DIRECTORY_SEPARATOR . $name)) return 'Le déplacement a échoué (fichier ouvert ?).';
            $new = $dest === '' ? $name : $dest . '/' . $name;
            rekey_paths($item, $new);
            flash_redirect('Élément déplacé dans « ' . ($dest === '' ? 'Racine' : basename($dest)) . ' ».', admin_url('library', $new));

        case 'duplicate':
            $item = post('item');
            $abs = full_path($item, 'any');
            $parent = parent_path($item);
            if ($item === '' || !$abs || !can_manage($parent, $user)) return 'Vous ne pouvez pas dupliquer cet élément.';
            $base = basename($abs);
            if (is_dir($abs)) {
                $wanted = $base . ' (copie)';
            } else {
                $ext = pathinfo($base, PATHINFO_EXTENSION);
                $wanted = pathinfo($base, PATHINFO_FILENAME) . ' (copie)' . ($ext !== '' ? '.' . $ext : '');
            }
            $name = unique_name(dirname($abs), $wanted);
            @set_time_limit(0);
            $quota = quota_error_for_path($parent, dir_size($abs));
            if ($quota !== '') return $quota;
            if (!copy_tree($abs, dirname($abs) . DIRECTORY_SEPARATOR . $name)) return 'La copie a échoué.';
            quiz_refresh_ids(dirname($abs) . DIRECTORY_SEPARATOR . $name);
            $new = $parent === '' ? $name : $parent . '/' . $name;
            if (post('keep_rules') === '1' && can('publish', $user)) {
                $n = clone_sharing($item, $new, post('shift_year') === '1');
                flash_redirect('Copie « ' . $name . ' » créée avec ses réglages de partage (' . $n . ' règle(s)' . (post('shift_year') === '1' ? ', dates décalées d’un an' : '') . ').', admin_url('library', $new));
            }
            flash_redirect('Copie « ' . $name . ' » créée. Elle suit la publication du dossier parent : vérifiez sa visibilité.', admin_url('library', $new));

        case 'copy_to':
            $item = post('item');
            $dest = post('dest');
            if ($dest === '/') $dest = '';
            $abs = full_path($item, 'any');
            $destAbs = full_path($dest, 'dir');
            if ($item === '' || !$abs || !can_manage($item, $user)) return 'Vous ne pouvez pas copier cet élément.';
            if (!$destAbs || !can_manage($dest, $user)) return 'Dossier de destination invalide.';
            if (path_within($dest, $item)) return 'Impossible de copier un dossier dans lui-même.';
            $quota = quota_error_for_path($dest, dir_size($abs));
            if ($quota !== '') return $quota;
            @set_time_limit(0);
            $name = unique_name($destAbs, basename($abs));
            if (!copy_tree($abs, $destAbs . DIRECTORY_SEPARATOR . $name)) return 'La copie a échoué.';
            quiz_refresh_ids($destAbs . DIRECTORY_SEPARATOR . $name);
            $new = $dest === '' ? $name : $dest . '/' . $name;
            if (post('keep_rules') === '1' && can('publish', $user) && !in_bank($dest)) {
                $n = clone_sharing($item, $new, post('shift_year') === '1');
                flash_redirect('« ' . basename($item) . ' » copié avec ses réglages de partage (' . $n . ' règle(s)).', admin_url('library', $new));
            }
            flash_redirect('« ' . basename($item) . ' » copié dans « ' . ($dest === '' ? 'Racine' : str_replace('/', ' › ', $dest)) . ' ».' . (in_bank($dest) ? '' : ' La copie suit la publication de son nouveau dossier.'), admin_url('library', $new));

        case 'import_zip':
            $dir = post('folder');
            $abs = full_path($dir, 'dir');
            if (!$abs || !can_manage($dir, $user)) return 'Dossier invalide.';
            if (!class_exists('ZipArchive')) return 'L’extension PHP « zip » n’est pas activée sur le serveur.';
            $files = uploaded_files('archive');
            if (!$files || $files[0]['error'] !== UPLOAD_ERR_OK) return 'Choisissez une archive .zip (taille maximale : ' . human_size(upload_limit()) . ').';
            $res = import_zip_into($files[0]['tmp_name'], $abs, $dir);
            if (is_string($res)) return $res;
            flash_redirect($res['files'] . ' fichier(s) et ' . $res['dirs'] . ' dossier(s) importé(s)' . ($res['skipped'] ? ' ; ' . $res['skipped'] . ' élément(s) ignoré(s) (types interdits ou noms invalides)' : '') . '.', admin_url('library', $dir));

        case 'quiz_create':
            $dir = post('folder');
            $abs = full_path($dir, 'dir');
            if (!$abs || !can_manage($dir, $user)) return 'Dossier invalide.';
            $title = trim(post('title'));
            $name = safe_name($title);
            if ($name === '') return 'Titre invalide.';
            $name = unique_name($abs, $name . '.' . QUIZ_EXT);
            if (!quiz_save($abs . DIRECTORY_SEPARATOR . $name, ['title' => mb_substr($title, 0, 150)])) return 'Impossible de créer le QCM.';
            $new = $dir === '' ? $name : $dir . '/' . $name;
            flash_redirect('QCM créé : ajoutez vos questions.', admin_url('quiz', $new));

        case 'quiz_save':
            $item = post('item');
            $abs = full_path($item, 'file');
            if (!$abs || !is_quiz_file($item) || !can_manage($item, $user)) return 'QCM introuvable.';
            $old = quiz_load($abs);
            $questions = [];
            $raw = isset($_POST['q']) && is_array($_POST['q']) ? $_POST['q'] : [];
            foreach ($raw as $q) {
                if (!is_array($q)) continue;
                $text = trim(str_replace("\r", '', isset($q['text']) && is_string($q['text']) ? $q['text'] : ''));
                $opts = [];
                $correct = [];
                foreach (isset($q['opts']) && is_array($q['opts']) ? $q['opts'] : [] as $oi => $o) {
                    $o = is_string($o) ? trim($o) : '';
                    if ($o === '') continue;
                    if (isset($q['ok']) && is_array($q['ok']) && in_array((string) $oi, $q['ok'], true)) $correct[] = count($opts);
                    $opts[] = mb_substr($o, 0, 500);
                }
                if ($text === '' && !$opts) continue;
                if ($text === '') return 'Une question n’a pas d’énoncé.';
                if (count($opts) < 2) return 'La question « ' . mb_substr($text, 0, 40) . ' » doit avoir au moins deux réponses.';
                if (!$correct) return 'Cochez la ou les bonnes réponses de la question « ' . mb_substr($text, 0, 40) . ' ».';
                $questions[] = ['text' => mb_substr($text, 0, 2000), 'multi' => !empty($q['multi']) || count($correct) > 1,
                                'options' => $opts, 'correct' => $correct, 'explain' => mb_substr(trim(isset($q['explain']) && is_string($q['explain']) ? $q['explain'] : ''), 0, 1000)];
            }
            if (count($questions) > QUIZ_MAX_QUESTIONS) return 'Un QCM est limité à ' . QUIZ_MAX_QUESTIONS . ' questions.';
            $quiz = [
                'id' => $old['id'], 'title' => mb_substr(trim(post('title')), 0, 150), 'intro' => mb_substr(trim(str_replace("\r", '', post('intro'))), 0, 1500),
                'correction' => in_array(post('correction'), ['immediate', 'after_close', 'never'], true) ? post('correction') : 'immediate',
                'show_correction' => post('correction') !== 'never', 'until' => clean_datetime(post('until')), 'shuffle' => post('shuffle') === '1',
                'attempts' => max(0, min(10, (int) post('attempts', '1'))), 'questions' => $questions,
                'draw' => max(0, min(QUIZ_MAX_QUESTIONS, (int) post('draw', '0'))),
            ];
            if (!quiz_save($abs, $quiz)) return 'Enregistrement impossible.';
            flash_redirect('QCM enregistré (' . count($questions) . ' question' . (count($questions) > 1 ? 's' : '') . ').', admin_url('quiz', $item));

        case 'quiz_import':
            $item = post('item');
            $src = post('source');
            $abs = full_path($item, 'file');
            $srcAbs = full_path($src, 'file');
            if (!$abs || !is_quiz_file($item) || !can_manage($item, $user)) return 'QCM introuvable.';
            if (!$srcAbs || !is_quiz_file($src) || !can_manage($src, $user) || $src === $item) return 'Choisissez un autre QCM.';
            $q = quiz_load($abs);
            $from = quiz_load($srcAbs);
            $pick = isset($_POST['pick']) && is_array($_POST['pick']) ? array_map('intval', $_POST['pick']) : array_keys($from['questions']);
            $added = 0;
            foreach ($from['questions'] as $i => $qq) {
                if (!in_array($i, $pick, true) || count($q['questions']) >= QUIZ_MAX_QUESTIONS) continue;
                $q['questions'][] = $qq;
                $added++;
            }
            quiz_save($abs, $q);
            flash_redirect($added . ' question(s) importée(s) depuis « ' . display_name($src) . ' ».', admin_url('quiz', $item));

        case 'quiz_reset':
            $item = post('item');
            $abs = full_path($item, 'file');
            if (!$abs || !is_quiz_file($item) || !can_manage($item, $user)) return 'QCM introuvable.';
            $q = quiz_load($abs);
            $f = quiz_results_file($q['id']);
            if ($f && is_file($f)) @unlink($f);
            flash_redirect('Résultats effacés.', admin_url('quiz', $item) . '&tab=resultats');

        case 'coteach_save':
            $item = post('folder');
            if (!full_path($item, 'dir') || $item === '' || in_bank($item)) return 'Dossier invalide.';
            $owns = $admin;
            foreach ($user['folders'] as $f) if (is_string($f) && $f !== '' && path_within($item, $f)) $owns = true;
            if (!$owns) return 'Seul le titulaire du dossier peut le partager.';
            $all = users_all();
            $logins = isset($_POST['coteach']) && is_array($_POST['coteach']) ? $_POST['coteach'] : [];
            $logins = array_values(array_filter($logins, function ($l) use ($all, $user) {
                return is_string($l) && isset($all[$l]) && $l !== $user['login'] && $all[$l]['role'] !== 'admin';
            }));
            if ($logins) $data['coteach'][$item] = $logins; else unset($data['coteach'][$item]);
            save_settings($data);
            flash_redirect($logins ? 'Dossier partagé avec ' . count($logins) . ' collègue(s).' : 'Le dossier n’est plus partagé.', admin_url('library', $item) . '#collegues');

        case 'group_members':
            $class = post('class');
            if (!level_ok($class, $data)) return 'Classe inconnue.';
            if (!$admin && !in_array($class, $user['classes'], true)) return 'Vous ne pouvez gérer que vos classes.';
            $names = group_names($data, $class);
            $m = isset($_POST['m']) && is_array($_POST['m']) ? $_POST['m'] : [];
            $list = students_all();
            $n = 0;
            foreach ($list as $id => $s) {
                if ($s['class'] !== $class) continue;
                $chosen = isset($m[$id]) && is_array($m[$id]) ? array_values(array_intersect($names, $m[$id])) : [];
                // Les groupes d'autres classes (s'il y en avait) sont retirés ; ceux de la classe remplacés.
                $list[$id]['groups'] = $chosen;
                $n++;
            }
            save_students($list);
            flash_redirect('Répartition enregistrée pour ' . $n . ' élève(s) de ' . $class . '.', admin_url('groups') . '&class=' . rawurlencode($class));

        case 'students_import':
            $files = uploaded_files('file');
            if (!$files || $files[0]['error'] !== UPLOAD_ERR_OK) return 'Choisissez un fichier CSV.';
            $allowed = $admin ? null : array_values(array_intersect($data['levels'], $user['classes']));
            $r = students_import($files[0]['tmp_name'], $data, post('replace') === '1', $allowed);
            if ($r['errors']) return implode(' ', $r['errors']);
            $msg = $r['created'] . ' élève(s) ajouté(s), ' . $r['updated'] . ' mis à jour';
            if ($r['skipped']) $msg .= ', ' . $r['skipped'] . ' ligne(s) ignorée(s)';
            if ($r['unknown']) $msg .= ' — classes inconnues : ' . implode(', ', array_slice($r['unknown'], 0, 8)) . ' (créez-les dans Niveaux et classes, ou corrigez le fichier)';
            flash_redirect($msg . '.', admin_url('students'));

        case 'student_save':
            $id = post('id');
            $list = students_all();
            if ($id !== '' && !isset($list[$id])) return 'Élève introuvable.';
            $last = clean_person_name(post('last'));
            $first = clean_person_name(post('first'));
            $class = post('class');
            if ($last === '' || $first === '') return 'Nom et prénom invalides (lettres uniquement).';
            if (!level_ok($class, $data)) return 'Choisissez une classe.';
            if (!$admin && !in_array($class, $user['classes'], true)) return 'Vous ne pouvez gérer que les élèves de vos classes.';
            if ($id !== '' && !$admin && !in_array($list[$id]['class'], $user['classes'], true)) return 'Cet élève n’est pas dans vos classes.';
            $ent = mb_substr(trim(post('ent_id')), 0, 120);
            if ($ent !== '') {
                $other = find_student_by_ent($ent);
                if ($other && $other['id'] !== $id) return 'Cet identifiant ENT est déjà utilisé par ' . student_name($other) . ' (' . $other['class'] . ').';
            }
            $g = isset($_POST['groups'][$class]) && is_array($_POST['groups'][$class]) ? array_values(array_intersect(group_names($data, $class), $_POST['groups'][$class])) : [];
            if ($id === '') $id = student_new_id();
            $list[$id] = ['last' => $last, 'first' => $first, 'class' => $class, 'groups' => $g, 'ent_id' => $ent,
                          'created' => isset($list[$id]['created']) ? $list[$id]['created'] : time()] + (isset($list[$id]) ? $list[$id] : []);
            save_students($list);
            if (post('again') === '1') flash_redirect(student_name($list[$id]) . ' ajouté(e). Élève suivant :', admin_url('students') . '&edit=new&class=' . rawurlencode($class));
            flash_redirect(student_name($list[$id]) . ' enregistré(e).', admin_url('students') . '&class=' . rawurlencode($class));

        case 'students_bulk':
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
            $op = post('op');
            $list = students_all();
            $ids = array_values(array_filter($ids, function ($id) use ($list, $admin, $user) {
                return is_string($id) && isset($list[$id]) && ($admin || in_array($list[$id]['class'], $user['classes'], true));
            }));
            if (!$ids) return 'Sélectionnez au moins un élève.';
            $n = 0;
            if ($op === 'class') {
                $to = post('class_to');
                if (!level_ok($to, $data) || (!$admin && !in_array($to, $user['classes'], true))) return 'Classe de destination invalide.';
                foreach ($ids as $id) { if ($list[$id]['class'] !== $to) { $list[$id]['class'] = $to; $list[$id]['groups'] = []; $n++; } }
                $msg = $n . ' élève(s) déplacé(s) en ' . $to . ' (leurs groupes ont été retirés).';
            } elseif ($op === 'group_add') {
                list($gc, $gn) = split_target(post('group_to'));
                if ($gn === null || !in_array($gn, group_names($data, $gc), true)) return 'Groupe invalide.';
                $other = 0;
                foreach ($ids as $id) {
                    if ($list[$id]['class'] !== $gc) { $other++; continue; }
                    if (!in_array($gn, $list[$id]['groups'], true)) { $list[$id]['groups'][] = $gn; $n++; }
                }
                $msg = $n . ' élève(s) ajouté(s) au groupe ' . $gn . ' de ' . $gc . ($other ? ' (' . $other . ' élève(s) d’une autre classe ignoré(s))' : '') . '.';
            } elseif ($op === 'group_clear') {
                foreach ($ids as $id) { if ($list[$id]['groups']) { $list[$id]['groups'] = []; $n++; } }
                $msg = $n . ' élève(s) retiré(s) de leurs groupes.';
            } elseif ($op === 'ent_clear') {
                foreach ($ids as $id) { if ($list[$id]['ent_id'] !== '') { $list[$id]['ent_id'] = ''; $n++; } }
                $msg = 'Identifiant ENT effacé pour ' . $n . ' élève(s).';
            } elseif ($op === 'delete') {
                foreach ($ids as $id) { unset($list[$id]); $n++; }
                $msg = $n . ' élève(s) retiré(s) de la liste.';
            } else {
                return 'Choisissez une action.';
            }
            save_students($list);
            flash_redirect($msg, admin_url('students') . (query('class') !== '' ? '&class=' . rawurlencode(query('class')) : ''));

        case 'student_request_decide':
            $reqs = student_requests();
            $allowed = student_classes_for($user, $data);
            $keys = post('accept_all') === '1' ? array_keys(student_requests_for($user)) : array_filter([post('accept'), post('refuse')]);
            $refuse = post('refuse') !== '';
            $classes = isset($_POST['class']) && is_array($_POST['class']) ? $_POST['class'] : [];
            $list = students_all();
            $n = 0;
            foreach ($keys as $k) {
                if (!isset($reqs[$k])) continue;
                $r = $reqs[$k];
                if (!$admin && !in_array($r['class'], $user['classes'], true)) continue;
                if (!$refuse) {
                    $cls = isset($classes[$k]) && in_array($classes[$k], $allowed, true) ? $classes[$k] : $r['class'];
                    if (!level_ok($cls, $data) || find_student_by_ent($r['ent_id'])) { unset($reqs[$k]); continue; }
                    $list[student_new_id()] = ['last' => $r['last'], 'first' => $r['first'], 'class' => $cls, 'groups' => [], 'ent_id' => $r['ent_id'], 'created' => time()];
                }
                unset($reqs[$k]);
                $n++;
            }
            save_students($list);
            save_student_requests($reqs);
            flash_redirect($refuse ? 'Demande refusée.' : $n . ' élève(s) ajouté(s) à la liste : ils peuvent se connecter par l’ENT.', admin_url('students'));

        case 'student_delete':
            $list = students_all();
            $id = post('id');
            if (!isset($list[$id])) return 'Élève introuvable.';
            if (!$admin && !in_array($list[$id]['class'], $user['classes'], true)) return 'Cet élève n’est pas dans vos classes.';
            $class = $list[$id]['class'];
            unset($list[$id]);
            save_students($list);
            flash_redirect('Élève retiré de la liste.', admin_url('students') . '&class=' . rawurlencode($class));

        case 'group_add':
        case 'group_remove':
            $class = post('class');
            if (!level_ok($class, $data)) return 'Classe inconnue.';
            if (!$admin && !in_array($class, $user['classes'], true)) return 'Vous ne pouvez gérer que les groupes de vos classes (Mon compte › Mes classes).';
            $groups = class_groups($data, $class);
            $name = trim(preg_replace('/\s+/u', ' ', str_replace(GROUP_SEP, '-', post('name'))));
            $back = admin_url('groups') . (post('back') !== '' && valid_path(post('back')) ? '' : '') . '#groupes-' . md5($class);
            if ($action === 'group_add') {
                if ($name === '' || mb_strlen($name) > 40) return 'Nom de groupe invalide (40 caractères maximum).';
                foreach ($groups as $g) if (mb_strtolower($g['name']) === mb_strtolower($name)) return 'Le groupe « ' . $name . ' » existe déjà en ' . $class . '.';
                if (count($groups) >= 12) return '12 groupes maximum par classe.';
                $code = strtoupper(trim(post('code')));
                if ($code !== '' && !preg_match('/^[A-Z0-9]{4}$/', $code)) return 'Le code doit contenir 4 lettres ou chiffres.';
                $groups[] = ['name' => $name, 'hash' => $code !== '' ? password_hash($code, PASSWORD_DEFAULT) : '', 'display' => $code];
                $data['groups'][$class] = $groups;
                save_settings($data);
                flash_redirect('Groupe « ' . $name . ' » créé en ' . $class . '. Il apparaît maintenant à côté de la classe quand vous partagez une ressource.', $back);
            }
            $groups = array_values(array_filter($groups, function ($g) use ($name) { return $g['name'] !== $name; }));
            if ($groups) $data['groups'][$class] = $groups; else unset($data['groups'][$class]);
            $valid = all_targets($data);
            foreach ($data['rules'] as $p => $r) if (isset($r['levels'])) $data['rules'][$p]['levels'] = array_values(array_intersect($r['levels'], $valid));
            foreach ($data['announcements'] as $i => $a) $data['announcements'][$i]['levels'] = array_values(array_intersect($a['levels'], $valid));
            save_settings($data);
            $deps = deposits_config();
            foreach ($deps as $p => $c) if (isset($c['levels'])) $deps[$p]['levels'] = array_values(array_intersect($c['levels'], $valid));
            save_deposits_config($deps);
            flash_redirect('Groupe « ' . $name . ' » supprimé de ' . $class . '.', $back);

        case 'groups_save':
            $class = post('class');
            if (!level_ok($class, $data)) return 'Classe inconnue.';
            if (!$admin && !in_array($class, $user['classes'], true)) return 'Vous ne pouvez gérer que les groupes de vos classes.';
            $parsed = parse_groups_text(post('groups'), class_groups($data, $class));
            if (is_string($parsed)) return $parsed;
            $oldNames = group_names($data, $class);
            if ($parsed) $data['groups'][$class] = $parsed; else unset($data['groups'][$class]);
            // Groupes supprimés : retirés des publications, dépôts et annonces.
            $removed = array_diff($oldNames, array_map(function ($g) { return $g['name']; }, $parsed));
            if ($removed) {
                $valid = all_targets($data);
                foreach ($data['rules'] as $p => $r) if (isset($r['levels'])) $data['rules'][$p]['levels'] = array_values(array_intersect($r['levels'], $valid));
                foreach ($data['announcements'] as $i => $a) $data['announcements'][$i]['levels'] = array_values(array_intersect($a['levels'], $valid));
                $deps = deposits_config();
                foreach ($deps as $p => $c) if (isset($c['levels'])) $deps[$p]['levels'] = array_values(array_intersect($c['levels'], $valid));
                save_deposits_config($deps);
            }
            save_settings($data);
            redirect(admin_url('groups') . '&saved=1#groupes-' . md5($class));

        /* ----- Corbeille ----- */

        case 'trash_restore':
            $e = trash_find(post('id'));
            if (!$e || !trash_visible($e, $user)) return 'Élément introuvable.';
            $parent = parent_path($e['path']);
            if (!$admin && !can_manage($parent, $user) && !can_manage($e['path'], $user)) return 'Vous ne pouvez pas restaurer cet élément ici.';
            $res = trash_restore($e['id']);
            if (strpos($res, '/') === false && !full_path($res, 'any')) return $res;
            flash_redirect('« ' . $e['name'] . ' » a été restauré.', admin_url('library', $res));

        case 'trash_delete':
            $e = trash_find(post('id'));
            if (!$e || !trash_visible($e, $user)) return 'Élément introuvable.';
            trash_delete($e['id']);
            flash_redirect('Élément supprimé définitivement.', admin_url('trash'));

        /* ----- Ma matière et mes classes ----- */

        case 'my_classes':
            $users = users_all();
            $subject = trim(preg_replace('/\s+/u', ' ', post('subject')));
            if ($subject !== '' && safe_name($subject) === '') return 'Nom de matière invalide.';
            $users[$user['login']]['subject'] = mb_substr($subject, 0, 60);
            $users[$user['login']]['classes'] = selected_levels($data);
            save_users($users);
            $n = ensure_subject_folders($user['login']);
            flash_redirect($n ? $n . ' dossier(s) de matière créé(s) et publié(s) pour vos classes.' : 'Vos classes ont été enregistrées.', admin_url('account'));

        /* ----- Dépôts ----- */

        case 'deposit_save':
            $item = post('folder');
            if (!full_path($item, 'dir') || !can_manage($item, $user)) return 'Dossier invalide.';
            $all = deposits_config();
            $cfg = isset($all[$item]) ? deposit_normalize($all[$item]) : deposit_normalize([]);
            if ($cfg['id'] === '') $cfg['id'] = bin2hex(random_bytes(8));
            $from = clean_datetime(post('from'));
            $until = clean_datetime(post('until'));
            if ($from !== '' && $until !== '' && parse_datetime($until) <= parse_datetime($from)) {
                return 'La date limite doit être postérieure à la date d’ouverture.';
            }
            $cfg['enabled'] = post('enabled') === '1';
            $cfg['levels'] = selected_levels($data, true);
            $cfg['from'] = $from;
            $cfg['until'] = $until;
            $cfg['max_mb'] = max(1, min(500, (int) post('max_mb', '20')));
            $cfg['exts'] = implode(', ', deposit_exts(['exts' => post('exts')]));
            $cfg['note'] = mb_substr(trim(post('note')), 0, 600);
            $cfg['late'] = post('late') === 'accept' ? 'accept' : 'refuse';
            $cfg['late_until'] = $cfg['late'] === 'accept' ? clean_datetime(post('late_until')) : '';
            if ($cfg['late_until'] !== '' && ($until === '' || parse_datetime($cfg['late_until']) <= parse_datetime($until))) {
                return 'La limite des retards doit être postérieure à la date limite.';
            }
            if ($cfg['late'] === 'accept' && $until === '') $cfg['late'] = 'refuse';
            $cfg['group_max'] = max(1, min(5, (int) post('group_max', '1')));
            $sol = trim(post('solution'));
            if ($sol !== '' && (!valid_path($item . '/' . $sol) || !full_path($item . '/' . $sol, 'any'))) return 'Corrigé introuvable dans ce dossier.';
            $cfg['solution'] = $sol;
            $cfg['owner'] = $cfg['owner'] !== '' ? $cfg['owner'] : $user['login'];
            if ($cfg['enabled'] && !$cfg['levels']) return 'Cochez au moins une classe pour ouvrir le dépôt.';
            $all[$item] = $cfg;
            save_deposits_config($all);
            flash_redirect($cfg['enabled'] ? 'Dépôt des travaux enregistré.' : 'Dépôt des travaux désactivé.', admin_url('library', $item) . '#depot');

        case 'deposit_feedback':
            $item = post('folder');
            $all = deposits_config();
            if (!isset($all[$item]) || !can_manage($item, $user)) return 'Dépôt introuvable.';
            $cfg = deposit_normalize($all[$item]);
            $fb = deposit_feedback($cfg);
            $g = isset($_POST['g']) && is_array($_POST['g']) ? $_POST['g'] : [];
            $c = isset($_POST['c']) && is_array($_POST['c']) ? $_POST['c'] : [];
            foreach ($g + $c as $key => $unused) {
                if (!preg_match('/^(s:[a-f0-9]{12}|n:[a-f0-9]{32})$/', (string) $key)) continue;
                $grade = mb_substr(trim(isset($g[$key]) && is_string($g[$key]) ? $g[$key] : ''), 0, 20);
                $comment = mb_substr(trim(isset($c[$key]) && is_string($c[$key]) ? $c[$key] : ''), 0, 1000);
                if ($grade === '' && $comment === '') { unset($fb[$key]); continue; }
                $old = isset($fb[$key]) ? $fb[$key] : [];
                if (!$old || $old['grade'] !== $grade || $old['comment'] !== $comment) $fb[$key] = ['grade' => $grade, 'comment' => $comment, 'time' => time(), 'by' => $user['login']];
            }
            deposit_save_feedback($cfg, $fb);
            $cfg['feedback_public'] = post('public') === '1';
            $msg = 'Notes et commentaires enregistrés' . ($cfg['feedback_public'] ? ' (visibles par les élèves)' : '') . '.';
            if (post('do') === 'remind') {
                $ids = isset($_POST['remind']) && is_array($_POST['remind']) ? array_filter($_POST['remind'], 'is_string') : [];
                foreach ($ids as $id) if (find_student($id)) $cfg['reminders'][$id] = time();
                $msg = count($ids) . ' élève(s) relancé(s).';
                if (post('announce') === '1' && $cfg['levels']) {
                    $data['announcements'][] = [
                        'id' => bin2hex(random_bytes(6)), 'title' => 'Rappel : « ' . basename($item) . ' » à rendre',
                        'text' => $cfg['until'] !== '' ? 'Date limite : ' . format_datetime($cfg['until']) . '. Pensez à déposer votre travail dans le dossier « ' . basename($item) . ' ».' : 'Pensez à déposer votre travail dans le dossier « ' . basename($item) . ' ».',
                        'type' => 'important', 'levels' => $cfg['levels'], 'from' => '',
                        'until' => date('Y-m-d\TH:i', max(time() + 3 * 86400, (int) parse_datetime($cfg['until']))), 'by' => $user['login'], 'created' => date('Y-m-d\TH:i'),
                    ];
                    save_settings($data);
                    $msg .= ' Annonce de rappel publiée pour la classe.';
                }
            }
            $all[$item] = $cfg;
            save_deposits_config($all);
            flash_redirect($msg, admin_url('deposits', $item) . '#suivi');

        case 'correction_save':
            $item = post('folder');
            $cfg = deposit_for($item);
            header('Content-Type: application/json; charset=UTF-8');
            if (!$cfg || !can_manage($item, $user)) { echo json_encode(['ok' => false, 'error' => 'Dépôt introuvable.']); exit; }
            $files = uploaded_files('pdf');
            if (!$files || $files[0]['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'error' => 'Envoi de la copie impossible (taille maximale : ' . human_size(upload_limit()) . ').']); exit; }
            $err = deposit_save_correction(deposit_normalize($cfg), post('file'), $files[0]['tmp_name'], isset($_POST['annots']) ? (string) $_POST['annots'] : '');
            if ($err === '') audit_log('correction_save', ['dossier' => $item]);
            echo json_encode($err === '' ? ['ok' => true, 'public' => !empty(deposit_normalize($cfg)['feedback_public'])] : ['ok' => false, 'error' => $err]);
            exit;

        case 'correction_delete':
            $item = post('folder');
            $cfg = deposit_for($item);
            if (!$cfg || !can_manage($item, $user)) return 'Dépôt introuvable.';
            deposit_delete_correction(deposit_normalize($cfg), post('file'));
            flash_redirect('Copie annotée supprimée.', admin_url('deposits', $item));

        case 'deposit_delete_entry':
            $item = post('folder');
            $cfg = deposit_for($item);
            if (!$cfg || !can_manage($item, $user)) return 'Dépôt introuvable.';
            deposit_delete_entry($cfg, post('file'));
            flash_redirect('Rendu supprimé.', admin_url('deposits', $item));

        case 'deposit_purge':
            $item = post('folder');
            $cfg = deposit_for($item);
            if (!$cfg || !can_manage($item, $user)) return 'Dépôt introuvable.';
            deposit_delete_all($cfg);
            if (post('remove') === '1') {
                $all = deposits_config();
                unset($all[$item]);
                save_deposits_config($all);
                flash_redirect('Dépôt supprimé avec tous ses rendus.', admin_url('deposits'));
            }
            flash_redirect('Tous les rendus ont été supprimés.', admin_url('deposits', $item));

        /* ----- Mode examen ----- */

        case 'exam_start':
            $path = post('folder');
            $levels = selected_levels($data);
            $until = clean_datetime(post('until'));
            if (!full_path($path, 'dir') || $path === '' || !can_manage($path, $user)) return 'Choisissez un dossier que vous gérez.';
            if (!$levels) return 'Cochez au moins une classe.';
            if ($until === '' || parse_datetime($until) <= time()) return 'Indiquez une heure de fin dans le futur.';
            $data['exams'] = active_exams($data);
            $extra = max(0, min(240, (int) post('extra_minutes', '0')));
            $extraGroups = isset($_POST['extra_groups']) && is_array($_POST['extra_groups']) ? array_values(array_intersect(all_group_names($data), $_POST['extra_groups'])) : [];
            if ($extra > 0 && !$extraGroups) return 'Choisissez le groupe des élèves qui bénéficient du temps supplémentaire (ex. : Tiers-temps).';
            $data['exams'][] = [
                'id' => bin2hex(random_bytes(6)), 'path' => $path, 'levels' => $levels,
                'until' => $until, 'by' => $user['login'], 'started' => date('Y-m-d\TH:i'),
                'extra_minutes' => $extraGroups ? $extra : 0, 'extra_groups' => $extra ? $extraGroups : [],
            ];
            save_settings($data);
            flash_redirect('Mode examen activé pour ' . implode(', ', $levels) . '.', admin_url('exams'));

        case 'exam_stop':
            $id = post('id');
            foreach ($data['exams'] as $i => $exam) {
                if ($exam['id'] !== $id) continue;
                if (!$admin && $exam['by'] !== $user['login'] && !can_manage($exam['path'], $user)) return 'Vous ne pouvez pas arrêter cet examen.';
                unset($data['exams'][$i]);
            }
            $data['exams'] = array_values($data['exams']);
            save_settings($data);
            flash_redirect('Mode examen terminé : les classes retrouvent leurs ressources habituelles.', admin_url('exams'));

        /* ----- Annonces ----- */

        case 'announce_save':
            $text = trim(str_replace("\r", '', post('text')));
            $title = trim(post('title'));
            $levels = selected_levels($data, true);
            $until = clean_datetime(post('until'));
            $from = clean_datetime(post('from'));
            if ($title === '' || mb_strlen($title) > 120) return 'Indiquez un titre (120 caractères maximum).';
            if (mb_strlen($text) > 1000) return 'Le message est trop long (1000 caractères maximum).';
            if (!$levels) return 'Cochez au moins une classe.';
            if ($until !== '' && parse_datetime($until) <= time()) return 'La date de fin doit être dans le futur.';
            $data['announcements'] = array_values(array_filter($data['announcements'], function ($a) {
                return window_open('', isset($a['until']) ? $a['until'] : '');
            }));
            $data['announcements'][] = [
                'id' => bin2hex(random_bytes(6)), 'title' => $title, 'text' => $text,
                'type' => post('type') === 'important' ? 'important' : 'info',
                'levels' => $levels, 'from' => $from, 'until' => $until,
                'by' => $user['login'], 'created' => date('Y-m-d\TH:i'),
            ];
            save_settings($data);
            flash_redirect('Annonce publiée pour ' . implode(', ', array_map('target_label', $levels)) . '.', admin_url('announcements'));

        case 'announce_delete':
            foreach ($data['announcements'] as $i => $a) {
                if ($a['id'] !== post('id')) continue;
                if (!$admin && $a['by'] !== $user['login']) return 'Vous ne pouvez supprimer que vos annonces.';
                unset($data['announcements'][$i]);
            }
            $data['announcements'] = array_values($data['announcements']);
            save_settings($data);
            flash_redirect('Annonce supprimée.', admin_url('announcements'));

        /* ----- Copie des publications ----- */

        case 'copy_publications':
            $source = post('source');
            $targets = selected_levels($data);
            if (!level_ok($source, $data)) return 'Choisissez la classe source.';
            $targets = array_values(array_diff($targets, [$source]));
            if (!$targets) return 'Cochez au moins une classe de destination.';
            $add = function (array $levels) use ($source, $targets, $data) {
                if (!in_array($source, $levels, true)) return null;
                $merged = array_unique(array_merge($levels, $targets));
                return array_values(array_intersect(all_targets($data), $merged));
            };
            $n = 0;
            foreach ($data['rules'] as $path => $rule) {
                if (!isset($rule['mode']) || $rule['mode'] !== 'publish' || !can_manage((string) $path, $user)) continue;
                $new = $add(isset($rule['levels']) ? $rule['levels'] : []);
                if ($new !== null && $new !== $rule['levels']) { $data['rules'][$path]['levels'] = $new; $n++; }
            }
            save_settings($data);
            $deposits = deposits_config();
            $d = 0;
            if (post('with_deposits') === '1') {
                foreach ($deposits as $path => $cfg) {
                    if (!can_manage((string) $path, $user)) continue;
                    $new = $add(isset($cfg['levels']) ? $cfg['levels'] : []);
                    if ($new !== null && $new !== $cfg['levels']) { $deposits[$path]['levels'] = $new; $d++; }
                }
                save_deposits_config($deposits);
            }
            flash_redirect($n . ' publication(s)' . (post('with_deposits') === '1' ? ' et ' . $d . ' dépôt(s)' : '') . ' de ' . $source . ' étendue(s) à ' . implode(', ', $targets) . '.', admin_url('library'));

        /* ----- Codes de classes ----- */

        case 'class_code':
            $level = post('level');
            $new = trim(post('class_code'));
            $enabled = post('enabled') === '1';
            $codes = class_access_data();
            if (!level_ok($level, $data)) return 'Classe inconnue.';
            if ($new !== '' && !preg_match('/^[A-Za-z0-9]{4}$/D', $new)) return 'Le code doit contenir exactement 4 caractères (lettres et chiffres).';
            if ($enabled && !class_code_enabled($level, $codes) && $new === '') return 'Saisissez un code de 4 caractères pour protéger « ' . $level . ' ».';
            if (!$enabled) {
                unset($codes[$level]);
            } elseif ($new !== '') {
                $new = strtoupper($new);
                $codes[$level] = ['hash' => password_hash($new, PASSWORD_DEFAULT), 'display' => $new];
            }
            save_class_access_data($codes);
            redirect(admin_url('access') . '&saved=1#classe-' . md5($level));

        /* ----- Mon compte ----- */

        case 'account':
            $users = users_all();
            $me = $users[$user['login']];
            $name = trim(post('name'));
            if ($name === '' || mb_strlen($name) > 60) return 'Indiquez votre nom (60 caractères maximum).';
            $me['name'] = $name;
            $new = post('new');
            if ($new !== '' || post('old') !== '') {
                if (!password_verify(post('old'), $me['hash'])) return 'Mot de passe actuel incorrect.';
                if (strlen($new) < TPW_PASSWORD_MIN) return 'Le nouveau mot de passe doit contenir au moins ' . TPW_PASSWORD_MIN . ' caractères.';
                if ($new !== post('new_confirm')) return 'Les deux nouveaux mots de passe ne correspondent pas.';
                $me['hash'] = password_hash($new, PASSWORD_DEFAULT);
            }
            $users[$user['login']] = $me;
            save_users($users);
            redirect(admin_url('account') . '&saved=1');

        case 'totp_start':
            $_SESSION['totp_setup'] = totp_new_secret();
            redirect(admin_url('account') . '#securite');

        case 'totp_enable':
            $secret = isset($_SESSION['totp_setup']) ? (string) $_SESSION['totp_setup'] : '';
            if ($secret === '') return 'Recommencez l’activation.';
            $step = totp_verify($secret, post('code'));
            if ($step === false) return 'Code incorrect : vérifiez qu’il s’agit du code affiché par l’application pour TPWeb (et l’heure du téléphone).';
            list($plain, $hashes) = totp_backup_codes();
            $users = users_all();
            $users[$user['login']]['totp'] = $secret;
            $users[$user['login']]['totp_last'] = $step;
            $users[$user['login']]['totp_backup'] = $hashes;
            save_users($users);
            unset($_SESSION['totp_setup']);
            $_SESSION['totp_codes'] = $plain;
            redirect(admin_url('account') . '#securite');

        case 'totp_codes':
            if ($user['totp'] === '') return 'La double authentification n’est pas activée.';
            if (totp_verify($user['totp'], post('code'), 0) === false) return 'Code incorrect.';
            list($plain, $hashes) = totp_backup_codes();
            $users = users_all();
            $users[$user['login']]['totp_backup'] = $hashes;
            save_users($users);
            $_SESSION['totp_codes'] = $plain;
            redirect(admin_url('account') . '#securite');

        case 'totp_disable':
            if ($user['totp'] === '') redirect(admin_url('account'));
            if (totp_required($user)) return 'La double authentification est obligatoire pour votre compte.';
            if (!password_verify(post('password'), $user['hash']) && totp_verify($user['totp'], post('password'), 0) === false) {
                return 'Saisissez votre mot de passe (ou un code de l’application) pour désactiver la double authentification.';
            }
            $users = users_all();
            $users[$user['login']]['totp'] = '';
            $users[$user['login']]['totp_backup'] = [];
            save_users($users);
            flash_redirect('Double authentification désactivée.', admin_url('account'));

        case 'seq_save':
            $item = post('folder');
            if (!full_path($item, 'dir') || $item === '' || !can_manage($item, $user) || !can('publish', $user)) return 'Dossier invalide.';
            $mode = post('mode');
            if (!isset($data['sequences']) || !is_array($data['sequences'])) $data['sequences'] = [];
            if (in_array($mode, ['free', 'order'], true)) $data['sequences'][$item] = ['mode' => $mode]; else unset($data['sequences'][$item]);
            save_settings($data);
            flash_redirect(in_array($mode, ['free', 'order'], true) ? 'Le dossier s’affiche maintenant comme une séquence pour les élèves.' : 'Le dossier s’affiche de nouveau normalement.', admin_url('library', $item));

        case 'notify_save':
            $users = users_all();
            $email = trim(post('email'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Adresse électronique invalide.';
            $users[$user['login']]['email'] = mb_substr($email, 0, 120);
            $sub = post('submit');
            $users[$user['login']]['notify'] = [
                'submit' => in_array($sub, ['instant', 'daily', 'off'], true) ? $sub : 'daily',
                'closed' => post('closed') === '1', 'feedback' => post('feedback') === '1',
            ];
            save_users($users);
            flash_redirect('Préférences de notification enregistrées.', admin_url('account') . '#notifications');

        case 'feedback_add':
            $err = feedback_add(post('type'), post('title'), post('text'), post('page'), $user['login'], 'enseignant');
            if ($err !== '') return $err;
            flash_redirect('Merci ! Votre signalement a été envoyé à l’administrateur du portail.', admin_url('feedback'));

        case 'ent_unlink':
            $users = users_all();
            $users[$user['login']]['ent_id'] = '';
            save_users($users);
            flash_redirect('Votre compte n’est plus lié à l’ENT.', admin_url('account'));
    }

    /* ----- Réservé à l'administrateur ----- */

    if (!$admin) return 'Action réservée à l’administrateur.';

    switch ($action) {
        case 'levels':
            $parsed = parse_structure_text(post('level_names'));
            if (is_string($parsed)) return $parsed;
            $data['levels'] = $parsed['levels'];
            $data['structure'] = $parsed['structure'];
            save_settings($data);
            redirect(admin_url('levels') . '&saved=1');

        case 'school':
            $school = trim(preg_replace('/\s+/u', ' ', post('school')));
            if (strlen($school) > 120) return 'Le nom de l’établissement est trop long (120 caractères maximum).';
            $data['school'] = $school;
            save_settings($data);
            redirect(admin_url('settings') . '&saved=1');

        case 'trash_empty':
            trash_empty();
            flash_redirect('La corbeille a été vidée.', admin_url('trash'));

        case 'year_end':
            if (post('confirm') !== 'FIN') return 'Tapez FIN dans la case de confirmation pour lancer la fin d’année.';
            @set_time_limit(0);
            $done = [];
            if (post('backup') === '1') {
                if (!zip_available()) return 'Sauvegarde impossible : l’extension zip n’est pas activée. Décochez l’option ou activez-la.';
                $b = backup_create('complete');
                if (!$b) return 'La sauvegarde préalable a échoué : rien n’a été modifié.';
                $done[] = 'sauvegarde complète créée';
            }
            $y = (int) date('Y');
            $label = (int) date('n') >= 8 ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y;
            if (post('archive_rendus') === '1') {
                $target = TPW_ARCHIVES . DIRECTORY_SEPARATOR . 'rendus-' . $label;
                if (is_dir($target)) $target .= '-' . date('His');
                if (move_tree(TPW_RENDUS, $target)) {
                    @touch($target); // départ du délai de conservation des archives (1 an)
                    @mkdir(TPW_RENDUS, 0750, true);
                }
                save_deposits_config([]);
                $done[] = 'rendus archivés dans archives/' . basename($target) . ' et dépôts fermés';
            }
            if (post('unpublish') === '1') {
                $data['rules'] = [];
                $done[] = 'publications retirées';
            }
            if (post('clear_news') === '1') {
                $data['announcements'] = [];
                $data['exams'] = [];
                $done[] = 'annonces et examens effacés';
            }
            save_settings($data);
            if (post('reset_codes') === '1') {
                save_class_access_data([]);
                $done[] = 'codes d’accès supprimés';
            }
            if (post('reset_classes') === '1') {
                $users = users_all();
                foreach ($users as $l => $u) $users[$l]['classes'] = [];
                save_users($users);
                $done[] = 'classes des enseignants remises à zéro';
            }
            if (post('clear_students') === '1') {
                save_students([]);
                $done[] = 'liste des élèves vidée';
            }
            if (post('empty_trash') === '1') {
                trash_empty();
                $done[] = 'corbeille vidée';
            }
            flash_redirect('Fin d’année terminée : ' . ($done ? implode(', ', $done) : 'aucune option choisie') . '.', admin_url('year'));

        case 'update_check':
        case 'update_apply':
            $files = uploaded_files('archive');
            if (!$files || $files[0]['error'] !== UPLOAD_ERR_OK) return 'Choisissez l’archive .zip de la nouvelle version (taille maximale : ' . human_size(upload_limit()) . ').';
            if ($action === 'update_check') {
                $info = update_inspect($files[0]['tmp_name']);
                if (is_string($info)) return $info;
                flash_redirect('Archive valide : TPWeb ' . $info['version'] . ' (' . $info['files'] . ' fichiers, ' . human_size($info['size']) . '). Version installée : ' . TPW_VERSION . '.', admin_url('update'));
            }
            $res = update_apply($files[0]['tmp_name']);
            if (is_string($res)) return $res;
            flash_redirect('TPWeb ' . $res['version'] . ' installé (' . $res['files'] . ' fichiers). Sauvegarde préalable : ' . $res['backup'] . '.', admin_url('update'));

        case 'mail_settings':
            $from = trim(post('from'));
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) return 'Adresse d’expédition invalide.';
            $base = trim(post('base'));
            if ($base !== '' && !preg_match('#^https?://[^\s]+$#', $base)) return 'Adresse du portail invalide (commence par https://).';
            $data['mail'] = [
                'enabled' => post('enabled') === '1', 'transport' => post('transport') === 'php' ? 'php' : 'smtp',
                'host' => mb_substr(trim(post('host')), 0, 120), 'port' => max(1, min(65535, (int) post('port', '587'))),
                'secure' => in_array(post('secure'), ['tls', 'ssl', 'none'], true) ? post('secure') : 'tls',
                'user' => mb_substr(trim(post('user')), 0, 120), 'from' => $from,
                'from_name' => mb_substr(trim(post('from_name')), 0, 60), 'base' => rtrim($base, '/'),
            ];
            save_settings($data);
            if (post('pass') !== '') secret_set('smtp_pass', post('pass'));
            if (post('test') === '1') {
                $to = user_email($user);
                if ($to === '') return 'Réglages enregistrés. Indiquez d’abord votre adresse dans « Mon compte » pour recevoir le test.';
                if (!mail_ready()) return 'Réglages enregistrés, mais l’envoi est désactivé ou incomplet (adresse d’expédition, serveur).';
                $err = mail_send_now($to, 'TPWeb : courriel de test', "Bonjour,\n\nCe courriel confirme que TPWeb peut envoyer des notifications.\n\n" . mail_base_url());
                if ($err !== '') return 'Réglages enregistrés, mais le test a échoué : ' . $err;
                flash_redirect('Courriel de test envoyé à ' . $to . '.', admin_url('settings') . '#courriels');
            }
            flash_redirect('Réglages des courriels enregistrés.', admin_url('settings') . '#courriels');

        case 'branding_save':
            $color = post('color');
            $b = isset($data['branding']) && is_array($data['branding']) ? $data['branding'] : [];
            $b['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#000091';
            $b['message'] = mb_substr(trim(str_replace("\r", '', post('message'))), 0, 500);
            $dir = branding_dir();
            if (post('logo_remove') === '1' && !empty($b['logo'])) { @unlink($dir . DIRECTORY_SEPARATOR . $b['logo']); $b['logo'] = ''; }
            $files = uploaded_files('logo');
            if ($files) {
                $f = $files[0];
                if ($f['error'] !== UPLOAD_ERR_OK) return 'Logo : envoi impossible (fichier trop lourd ?).';
                if ($f['size'] > 1024 * 1024) return 'Logo trop lourd (1 Mo maximum).';
                $info = @getimagesize($f['tmp_name']);
                $exts = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
                if (!$info || !isset($exts[$info[2]])) return 'Le logo doit être une image PNG, JPG ou WebP.';
                if (!is_dir($dir)) @mkdir($dir, 0750, true);
                if (!empty($b['logo'])) @unlink($dir . DIRECTORY_SEPARATOR . $b['logo']);
                $name = 'logo-' . bin2hex(random_bytes(3)) . '.' . $exts[$info[2]];
                if (!move_uploaded_file($f['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) return 'Enregistrement du logo impossible.';
                $b['logo'] = $name;
            }
            $data['branding'] = $b;
            save_settings($data);
            redirect(admin_url('settings') . '&saved=1#apparence');

        case 'legal':
            $fields = ['publisher', 'controller', 'address', 'contact', 'dpo', 'host'];
            $legal = [];
            foreach ($fields as $f) $legal[$f] = mb_substr(trim(str_replace("\r", '', post($f))), 0, 500);
            $data['legal'] = $legal;
            save_settings($data);
            redirect(admin_url('settings') . '&saved=1#legal');

        case 'ent_settings':
            $url = rtrim(trim(post('url')), '/');
            $base = rtrim(trim(post('service_base')), '/');
            if ($url !== '' && clean_url($url) === '') return 'Adresse du serveur CAS invalide.';
            if ($base !== '' && clean_url($base) === '') return 'Adresse publique de TPWeb invalide.';
            $serviceUrl = trim(post('service_url'));
            if ($serviceUrl !== '' && (clean_url($serviceUrl) === '' || strpos($serviceUrl, '/cas.php') === false)) {
                return 'URL du connecteur invalide : elle doit pointer vers cas.php de TPWeb.';
            }
            $enabled = post('enabled') === '1';
            if ($enabled && $url === '') return 'Indiquez l’adresse du serveur CAS avant d’activer la connexion ENT.';
            $label = trim(post('label'));
            $data['ent'] = [
                'enabled' => $enabled, 'url' => $url, 'label' => $label !== '' ? mb_substr($label, 0, 60) : 'l’ENT',
                'version' => post('version') === '3' ? '3' : '2', 'service_base' => $base, 'service_url' => $serviceUrl,
                'autocreate' => in_array(post('autocreate'), ['off', 'pending', 'active'], true) ? post('autocreate') : 'off',
                'students' => post('students') === '1',
                'students_selfreg' => post('students_selfreg') === '1', 'students_autocreate' => post('students_autocreate') === '1',
                'variant' => isset($data['ent']['variant']) ? $data['ent']['variant'] : '',
            ];
            save_settings($data);
            redirect(admin_url('settings') . '&saved=1#ent');

        case 'user_save':
            $users = users_all();
            $login = strtolower(trim(post('login')));
            $isNew = post('mode') === 'new';
            if (!valid_login($login)) return 'Identifiant invalide : 2 à 32 caractères (lettres minuscules, chiffres, point, tiret).';
            if ($isNew && isset($users[$login])) return 'Cet identifiant est déjà utilisé.';
            if (!$isNew && !isset($users[$login])) return 'Compte introuvable.';
            $name = trim(post('name'));
            if ($name === '' || mb_strlen($name) > 60) return 'Indiquez le nom de l’enseignant.';
            $role = post('role') === 'admin' ? 'admin' : 'prof';
            if (!$isNew && $users[$login]['role'] === 'admin' && $role !== 'admin' && admin_count() <= 1) {
                return 'Il doit rester au moins un administrateur.';
            }
            $pass = post('password');
            // Mot de passe facultatif : un compte préparé sans mot de passe se connecte par l'ENT
            // (identifiant ENT saisi, ou rattaché lors de sa première connexion).
            if ($isNew && $pass !== '' && strlen($pass) < TPW_PASSWORD_MIN) return 'Mot de passe de ' . TPW_PASSWORD_MIN . ' caractères minimum (ou laissez-le vide : connexion par l’ENT).';
            if (!$isNew && $pass !== '' && strlen($pass) < TPW_PASSWORD_MIN) return 'Mot de passe de ' . TPW_PASSWORD_MIN . ' caractères minimum.';

            $folders = isset($_POST['folders']) && is_array($_POST['folders']) ? $_POST['folders'] : [];
            $folders = array_values(array_filter($folders, function ($f) {
                return is_string($f) && $f !== '' && full_path($f, 'dir');
            }));
            $newFolder = safe_name(post('new_folder'));
            if ($newFolder !== '') {
                $abs = TPW_COURSES . DIRECTORY_SEPARATOR . $newFolder;
                if (!is_dir($abs) && !@mkdir($abs, 0750)) return 'Impossible de créer le dossier « ' . $newFolder . ' ».';
                $folders[] = $newFolder;
            }

            $entId = trim(post('ent_id'));
            if ($entId !== '') {
                $owner = find_user_by_ent($entId);
                if ($owner !== null && $owner['login'] !== $login) return 'Cet identifiant ENT est déjà lié au compte « ' . $owner['login'] . ' ».';
            }

            $entry = $isNew ? ['hash' => ''] : $users[$login];
            if (isset($_POST['ent_id'])) $entry['ent_id'] = mb_substr($entId, 0, 120);
            $perms = isset($_POST['perms']) && is_array($_POST['perms']) ? $_POST['perms'] : [];
            $entry['perms'] = [];
            foreach (perm_definitions() as $key => $def) $entry['perms'][$key] = in_array($key, $perms, true);
            $subject = trim(preg_replace('/\s+/u', ' ', post('subject')));
            if ($subject !== '' && safe_name($subject) === '') return 'Nom de matière invalide.';
            $entry['subject'] = mb_substr($subject, 0, 60);
            $entry['classes'] = selected_levels($data);
            $entry['name'] = $name;
            $entry['role'] = $role;
            $entry['folders'] = array_values(array_unique($folders));
            $entry['quota_mb'] = max(0, min(1000000, (int) post('quota_mb', '0')));
            if ($pass !== '') $entry['hash'] = password_hash($pass, PASSWORD_DEFAULT);
            $users[$login] = $entry;
            save_users($users);
            $n = ensure_subject_folders($login);
            $hint = ($entry['hash'] === '' && empty($entry['ent_id'])) ? ' Sans mot de passe ni identifiant ENT : à sa première connexion par l’ENT, rattachez-le à ce compte (Comptes enseignants › comptes à valider).' : '';
            flash_redirect(($isNew ? 'Compte « ' . $login . ' » créé.' : 'Compte « ' . $login . ' » mis à jour.') . ($n ? ' ' . $n . ' dossier(s) de matière créé(s).' : '') . $hint, admin_url('users'));

        case 'student_access':
            $data['student_access'] = post('mode') === 'ent' ? 'ent' : 'code';
            save_settings($data);
            flash_redirect($data['student_access'] === 'ent'
                ? (students_ent_only($data) ? 'Connexion par l’ENT obligatoire pour les élèves : les classes ne sont plus visibles sans connexion.' : 'Mode enregistré, mais il ne s’appliquera qu’une fois la connexion des élèves par l’ENT activée (Paramètres).')
                : 'Les classes sont de nouveau visibles, avec leur code d’accès.', admin_url('students') . '#acces');

        case 'feedback_update':
            $list = feedback_all();
            foreach ($list as $i => $f) {
                if ($f['id'] !== post('id')) continue;
                if (post('delete') === '1') { unset($list[$i]); feedback_save($list); flash_redirect('Signalement supprimé.', admin_url('feedback')); }
                if (isset(feedback_statuses()[post('status')])) $list[$i]['status'] = post('status');
                $reply = trim(str_replace("\r", '', post('reply')));
                if ($reply !== '') $list[$i]['replies'][] = ['by' => $user['name'], 'text' => mb_substr($reply, 0, 2000), 'time' => time()];
                feedback_save($list);
                flash_redirect('Signalement mis à jour.', admin_url('feedback') . '#fb-' . $f['id']);
            }
            return 'Signalement introuvable.';

        case 'students_purge':
            $class = post('class');
            $list = students_all();
            foreach ($list as $id => $s) if ($class === '' || $s['class'] === $class) unset($list[$id]);
            save_students($list);
            flash_redirect($class !== '' ? 'Élèves de ' . $class . ' retirés de la liste.' : 'Liste des élèves vidée.', admin_url('students'));

        case 'users_import':
            $files = uploaded_files('file');
            if (!$files || $files[0]['error'] !== UPLOAD_ERR_OK) return 'Choisissez un fichier CSV.';
            $r = users_import($files[0]['tmp_name'], $data, post('update') === '1');
            if (is_string($r)) return $r;
            $msg = $r['created'] . ' compte(s) créé(s), ' . $r['updated'] . ' mis à jour';
            if ($r['skipped']) $msg .= ', ' . $r['skipped'] . ' ligne(s) ignorée(s)';
            if ($r['unknown']) $msg .= ' — classes inconnues ignorées : ' . implode(', ', array_slice($r['unknown'], 0, 8));
            flash_redirect($msg . '.', admin_url('users'));

        case 'user_merge':
            // Compte créé par l'ENT → rattaché à un compte existant (préparé par l'administrateur).
            $users = users_all();
            $from = post('login');
            $to = post('target');
            if (!isset($users[$from]) || empty($users[$from]['pending'])) return 'Compte en attente introuvable.';
            if (!isset($users[$to]) || $to === $from) return 'Choisissez le compte auquel rattacher cet enseignant.';
            $users[$to]['ent_id'] = $users[$from]['ent_id'];
            unset($users[$from]);
            save_users($users);
            audit_log('user_merge', ['ent' => $users[$to]['ent_id'], 'compte' => $to]);
            flash_redirect('Identifiant ENT « ' . $users[$to]['ent_id'] . ' » rattaché au compte de ' . $users[$to]['name'] . '. Il peut maintenant se connecter avec l’ENT.', admin_url('users'));

        case 'totp_reset':
            $users = users_all();
            $login = post('login');
            if (!isset($users[$login])) return 'Compte introuvable.';
            $users[$login]['totp'] = '';
            $users[$login]['totp_backup'] = [];
            save_users($users);
            flash_redirect('Double authentification réinitialisée pour « ' . $login . ' » : elle lui sera redemandée si elle est obligatoire.', admin_url('users') . '&edit=' . rawurlencode($login));

        case 'security_save':
            $data['security'] = ['force_2fa_admin' => post('force_2fa_admin') === '1', 'force_2fa_all' => post('force_2fa_all') === '1'];
            save_settings($data);
            redirect(admin_url('settings') . '&saved=1#securite');

        case 'autobackup_save':
            $ext = trim(post('ext_dir'));
            if ($ext !== '' && (!is_dir($ext) || !is_writable($ext))) return 'Le dossier externe « ' . $ext . ' » n’existe pas ou n’est pas accessible en écriture par le serveur.';
            $dav = trim(post('webdav_url'));
            if ($dav !== '' && clean_url($dav) === '') return 'Adresse WebDAV invalide (https://…).';
            $data['autobackup'] = [
                'enabled' => post('enabled') === '1', 'type' => post('type') === 'complete' ? 'complete' : 'reglages',
                'freq' => post('freq') === 'weekly' ? 'weekly' : 'daily', 'hour' => max(0, min(23, (int) post('hour', '2'))),
                'keep' => max(1, min(60, (int) post('keep', '7'))), 'ext_dir' => $ext, 'webdav_url' => $dav,
                'webdav_user' => mb_substr(trim(post('webdav_user')), 0, 120),
            ];
            if (post('webdav_pass') !== '') secret_set('webdav_pass', post('webdav_pass'));
            if ($dav === '') secret_set('webdav_pass', '');
            save_settings($data);
            redirect(admin_url('backups') . '&saved=1#auto');

        case 'autobackup_now':
            if (!zip_available()) return 'L’extension PHP « zip » n’est pas activée.';
            $r = autobackup_run(true);
            if (!$r) return 'Une sauvegarde est déjà en cours.';
            flash_redirect('Sauvegarde ' . ($r['ok'] ? 'réussie' : 'terminée avec un avertissement') . ' : ' . $r['msg'] . '.', admin_url('backups') . '#auto');

        case 'user_approve':
            $users = users_all();
            $login = post('login');
            if (!isset($users[$login])) return 'Compte introuvable.';
            $users[$login]['pending'] = false;
            save_users($users);
            flash_redirect('Compte « ' . $users[$login]['name'] . ' » validé : il peut se connecter avec l’ENT.', post('back') === 'edit' ? admin_url('users') . '&edit=' . rawurlencode($login) : admin_url('users'));

        case 'user_delete':
            $users = users_all();
            $login = post('login');
            if (!isset($users[$login])) return 'Compte introuvable.';
            if ($login === $user['login']) return 'Vous ne pouvez pas supprimer votre propre compte.';
            if ($users[$login]['role'] === 'admin' && admin_count() <= 1) return 'Il doit rester au moins un administrateur.';
            unset($users[$login]);
            save_users($users);
            flash_redirect('Compte supprimé. Ses dossiers et cours sont conservés.', admin_url('users'));

        case 'backup_create':
            if (!zip_available()) return 'L’extension PHP « zip » n’est pas activée (php.ini : extension=zip).';
            $type = post('type') === 'complete' ? 'complete' : 'reglages';
            $name = backup_create($type);
            if (!$name) return 'La sauvegarde a échoué.';
            flash_redirect('Sauvegarde « ' . $name . ' » créée.', admin_url('backups'));

        case 'backup_delete':
            $name = post('name');
            if (!backup_name_ok($name)) return 'Sauvegarde introuvable.';
            @unlink(TPW_BACKUPS . DIRECTORY_SEPARATOR . $name);
            flash_redirect('Sauvegarde supprimée.', admin_url('backups'));

        case 'backup_restore':
            $name = post('name');
            if ($name !== '') {
                if (!backup_name_ok($name) || !is_file(TPW_BACKUPS . DIRECTORY_SEPARATOR . $name)) return 'Sauvegarde introuvable.';
                $result = backup_restore(TPW_BACKUPS . DIRECTORY_SEPARATOR . $name);
            } else {
                $files = uploaded_files('archive');
                if (!$files || $files[0]['error'] !== UPLOAD_ERR_OK) return 'Choisissez une archive de sauvegarde (.zip).';
                $result = backup_restore($files[0]['tmp_name']);
            }
            if (is_string($result)) return $result;
            flash_redirect('Restauration terminée : ' . $result . ' fichier(s) restauré(s). Une copie des réglages précédents a été conservée.', admin_url('backups'));
    }

    return 'Action inconnue.';
}

/* =====================================================================
   Téléchargements
   ===================================================================== */

function send_file($full, $downloadName, $inline = false)
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $ascii = str_replace(['"', "\r", "\n"], '_', slug(pathinfo($downloadName, PATHINFO_FILENAME)) . '.' . file_ext($downloadName));
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . filesize($full));
    header('Cache-Control: private, no-store');
    readfile($full);
    exit;
}

function send_inline($full, $mime)
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline');
    header('Content-Length: ' . filesize($full));
    header('Cache-Control: private, no-store');
    readfile($full);
    exit;
}

function handle_admin_download($type)
{
    $user = current_user();
    $item = query('item');

    if ($type === 'file') {
        $full = full_path($item, 'file');
        if (!$full || !can_manage($item, $user)) fail(404, 'Fichier introuvable', 'Ce fichier n’existe pas ou ne vous est pas attribué.');
        if (query('as') === 'pdf' && is_office_file($item)) {
            $pdf = office_pdf($full);
            if (!$pdf) fail(404, 'Aperçu indisponible', 'La conversion de ce document a échoué. Téléchargez-le pour l’ouvrir.');
            send_inline($pdf, 'application/pdf');
        }
        if (query('inline') === '1') {
            $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
                      'webp' => 'image/webp', 'txt' => 'text/plain; charset=UTF-8', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
                      'mp3' => 'audio/mpeg', 'wav' => 'audio/wav'];
            $ext = file_ext($full);
            if (isset($types[$ext])) send_inline($full, $types[$ext]);
        }
        send_file($full, basename($full));
    }

    if (in_array($type, ['students_template', 'users_template', 'students_csv'], true)) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $data = settings();
        if ($type === 'students_csv' && !can('students', $user)) fail(403, 'Accès refusé', 'Vous n’avez pas accès à la liste des élèves.');
        if ($type === 'users_template' && !is_admin($user)) fail(403, 'Accès refusé', 'Réservé à l’administrateur.');
        $names = ['students_template' => 'modele-eleves.csv', 'users_template' => 'modele-enseignants.csv', 'students_csv' => 'eleves.csv'];
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $names[$type] . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        if ($type === 'users_template') {
            fputcsv($out, ['Nom', 'Prénom', 'Identifiant', 'Matière', 'Classes', 'Identifiant ENT', 'Mot de passe'], ';');
            fputcsv($out, ['Dupont', 'Marie', 'm.dupont', 'Physique-Chimie', '2GT1, 2GT2', 'marie.dupont', ''], ';');
        } else {
            fputcsv($out, ['Nom', 'Prénom', 'Classe', 'Groupes', 'Identifiant ENT'], ';');
            if ($type === 'students_template') {
                $c = isset($data['levels'][0]) ? $data['levels'][0] : '2GT1';
                fputcsv($out, ['Martin', 'Léa', $c, '', 'lea.martin'], ';');
            } else {
                $class = query('class');
                $allowed = student_classes_for($user, $data);
                foreach (students_all() as $s) {
                    if ($class !== '' && $s['class'] !== $class) continue;
                    if (!is_admin($user) && !in_array($s['class'], $allowed, true)) continue;
                    fputcsv($out, [$s['last'], $s['first'], $s['class'], implode(', ', $s['groups']), $s['ent_id']], ';');
                }
            }
        }
        fclose($out);
        exit;
    }

    if ($type === 'quiz_csv') {
        $abs = full_path($item, 'file');
        if (!$abs || !is_quiz_file($item) || !can_manage($item, $user)) fail(404, 'QCM introuvable', 'Ce QCM n’existe pas.');
        $quiz = quiz_load($abs);
        $level = query('level');
        $rows = array_reverse(quiz_results($quiz['id']));
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . (slug(display_name($item)) ?: 'qcm') . '-resultats.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $head = ['Nom', 'Prénom', 'Classe', 'Groupes', 'Score', 'Sur', 'Note /20', 'Date'];
        foreach ($quiz['questions'] as $i => $q) $head[] = 'Q' . ($i + 1);
        fputcsv($out, $head, ';');
        foreach ($rows as $r) {
            if ($level !== '' && $r['level'] !== $level) continue;
            $line = [mb_strtoupper($r['last']), $r['first'], $r['level'], implode(', ', isset($r['groups']) ? $r['groups'] : []), $r['score'], $r['total'],
                     str_replace('.', ',', (string) quiz_on20($r['score'], $r['total'])), date('d/m/Y H:i', $r['time'])];
            foreach ($quiz['questions'] as $i => $q) $line[] = isset($r['detail'][$i]) ? (!empty($r['detail'][$i]['ok']) ? '1' : '0') : '';
            fputcsv($out, $line, ';');
        }
        fclose($out);
        exit;
    }

    if (in_array($type, ['rendu', 'rendus_zip'], true) && !can('deposits', $user)) {
        fail(403, 'Accès refusé', 'Vous n’avez pas le droit de consulter les rendus.');
    }

    if ($type === 'rendu') {
        $cfg = deposit_for($item);
        $full = $cfg && can_manage($item, $user) ? deposit_file_path($cfg, query('f')) : false;
        if (!$full) fail(404, 'Rendu introuvable', 'Ce rendu n’existe plus.');
        if (query('corr') === '1') {
            $c = deposit_file_path($cfg, deposit_correction_name(query('f')));
            if (!$c) fail(404, 'Copie annotée introuvable', 'Cette copie n’a pas encore été annotée.');
            foreach (deposit_entries($cfg) as $e) {
                if ($e['file'] === query('f')) send_file($c, pathinfo(deposit_archive_name($e), PATHINFO_FILENAME) . ' - corrigé.pdf');
            }
        }
        if (query('raw') === '1') {
            // Source de l'outil d'annotation : PDF tel quel, image, ou document bureautique converti en PDF.
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            if (is_office_file($full)) {
                $pdf = office_pdf($full);
                if (!$pdf) fail(415, 'Conversion impossible', 'Ce document ne peut pas être converti en PDF (LibreOffice absent ?).');
                send_inline($pdf, 'application/pdf');
            }
            $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            if (isset($types[$ext])) send_inline($full, $types[$ext]);
            fail(415, 'Format non annotable', 'Seuls les PDF, images et documents bureautiques peuvent être annotés.');
        }
        foreach (deposit_entries($cfg) as $e) {
            if ($e['file'] === query('f')) send_file($full, basename(deposit_archive_name($e)));
        }
        send_file($full, basename($full));
    }

    if ($type === 'rendus_zip') {
        $cfg = deposit_for($item);
        if (!$cfg || !can_manage($item, $user)) fail(404, 'Dépôt introuvable', 'Ce dépôt n’existe pas.');
        if (!zip_available()) fail(500, 'Archive impossible', 'L’extension PHP « zip » n’est pas activée sur le serveur.');
        $level = query('level');
        audit_log('download_rendus', ['dossier' => $item, 'classe' => $level !== '' ? $level : 'toutes']);
        $tmp = tempnam(sys_get_temp_dir(), 'tpwz');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $n = 0;
        foreach (deposit_entries($cfg) as $e) {
            if ($level !== '' && $e['level'] !== $level) continue;
            $full = deposit_file_path($cfg, $e['file']);
            if ($full) { $zip->addFile($full, deposit_archive_name($e)); $n++; }
        }
        if (!$n) $zip->addFromString('aucun-rendu.txt', "Aucun rendu pour le moment.\n");
        $zip->close();
        $name = 'Rendus - ' . (basename($item) ?: 'cours') . ($level !== '' ? ' - ' . $level : '') . ' - ' . date('Y-m-d') . '.zip';
        register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
        send_file($tmp, safe_name($name) ?: 'rendus.zip');
    }

    if ($type === 'journal' && is_admin($user)) {
        $month = query('m');
        $rows = audit_read($month);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="journal-tpweb-' . preg_replace('/[^0-9-]/', '', $month) . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Compte', 'Action', 'Détails', 'Adresse IP'], ';');
        $labels = audit_labels();
        foreach ($rows as $r) {
            fputcsv($out, [date('d/m/Y H:i:s', $r['t']), $r['u'], isset($labels[$r['a']]) ? $labels[$r['a']] : $r['a'], audit_details_text((array) $r['d']), $r['ip']], ';');
        }
        fclose($out);
        exit;
    }

    if ($type === 'backup' && is_admin($user)) {
        $name = query('f');
        $full = TPW_BACKUPS . DIRECTORY_SEPARATOR . $name;
        if (!backup_name_ok($name) || !is_file($full)) fail(404, 'Sauvegarde introuvable', 'Cette sauvegarde n’existe plus.');
        send_file($full, $name);
    }

    fail(404, 'Téléchargement introuvable', 'Ce lien n’est pas valide.');
}

/* =====================================================================
   Import d'une arborescence (.zip)
   ===================================================================== */

/** Nom d'entrée ZIP en UTF-8 (les archives Windows anciennes utilisent CP437/CP850). */
function zip_member_name($name)
{
    $name = str_replace('\\', '/', (string) $name);
    if (!preg_match('//u', $name) && function_exists('iconv')) {
        $conv = @iconv('CP850', 'UTF-8//IGNORE', $name);
        if ($conv !== false) $name = $conv;
    }
    return $name;
}

/**
 * Extrait une archive dans un dossier de cours, en recréant les sous-dossiers.
 * Retourne ['files' => n, 'dirs' => n, 'skipped' => n] ou un message d'erreur.
 */
function import_zip_into($zipFile, $destAbs, $destRel)
{
    @set_time_limit(0);
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) return 'Archive illisible : est-ce bien un fichier .zip ?';
    if ($zip->numFiles > 10000) { $zip->close(); return 'Archive trop volumineuse (10 000 éléments maximum).'; }

    // Analyse : chemins nettoyés et taille totale.
    $plan = [];
    $total = 0;
    $skipped = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $raw = zip_member_name($stat['name']);
        $isDir = substr($raw, -1) === '/';
        $parts = array_values(array_filter(explode('/', $raw), 'strlen'));
        if (!$parts) continue;
        $clean = [];
        $bad = false;
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..' || $part[0] === '.' || $part === '__MACOSX') { $bad = true; break; }
            $s = safe_name($part);
            if ($s === '') { $bad = true; break; }
            $clean[] = $s;
        }
        $base = strtolower(end($parts));
        if ($bad || in_array($base, ['thumbs.db', 'desktop.ini'], true) || (!$isDir && blocked_extension(end($clean)))) { $skipped++; continue; }
        // Liens symboliques Unix (attributs externes) : ignorés.
        if (method_exists($zip, 'getExternalAttributesIndex') && $zip->getExternalAttributesIndex($i, $opsys, $attr)
            && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) { $skipped++; continue; }
        $plan[] = [$i, implode('/', $clean), $isDir];
        if (!$isDir) $total += (int) $stat['size'];
    }
    if ($total > 4 * 1024 * 1024 * 1024) { $zip->close(); return 'Contenu décompressé trop volumineux (4 Go maximum).'; }
    $quota = quota_error_for_path($destRel, $total);
    if ($quota !== '') { $zip->close(); return $quota; }

    // Extraction (les dossiers existants sont fusionnés, les fichiers homonymes renommés).
    $files = 0;
    $dirs = [];
    foreach ($plan as $row) {
        list($i, $rel, $isDir) = $row;
        $dirRel = $isDir ? $rel : (strpos($rel, '/') === false ? '' : substr($rel, 0, strrpos($rel, '/')));
        $target = $destAbs;
        if ($dirRel !== '') {
            foreach (explode('/', $dirRel) as $seg) {
                $target .= DIRECTORY_SEPARATOR . $seg;
                if (is_link($target) || is_file($target)) { $target = null; break; }
                if (!is_dir($target)) {
                    if (!@mkdir($target, 0750)) { $target = null; break; }
                    $dirs[$target] = true;
                }
            }
        }
        if ($target === null) { $skipped++; continue; }
        if ($isDir) continue;
        $name = unique_name($target, basename($rel));
        $in = $zip->getStream($zip->getNameIndex($i));
        $out = @fopen($target . DIRECTORY_SEPARATOR . $name, 'wb');
        if ($in && $out) {
            stream_copy_to_stream($in, $out);
            $files++;
            if (is_quiz_file($name)) { fclose($out); $out = null; quiz_refresh_ids($target . DIRECTORY_SEPARATOR . $name); }
        } else {
            $skipped++;
        }
        if ($in) fclose($in);
        if ($out) fclose($out);
    }
    $zip->close();
    return ['files' => $files, 'dirs' => count($dirs), 'skipped' => $skipped];
}

/* =====================================================================
   Import des comptes enseignants
   ===================================================================== */

function users_import($file, array $data, $update)
{
    $rows = csv_read_rows($file);
    if (!$rows) return 'Fichier vide ou illisible.';
    $users = users_all();
    $r = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'unknown' => []];
    $touched = [];
    foreach ($rows as $row) {
        $last = trim(csv_get($row, ['nom', 'nom de famille']));
        $first = trim(csv_get($row, ['prenom', 'prénom']));
        $display = trim(csv_get($row, ['nom affiche', 'nom affiché', 'nom complet']));
        if ($display === '') $display = trim($first . ' ' . $last);
        if ($display === '') { $r['skipped']++; continue; }
        $login = strtolower(trim(csv_get($row, ['identifiant', 'login', 'identifiant tpweb'])));
        $ent = mb_substr(trim(csv_get($row, ['identifiant ent', 'ent', 'login ent'])), 0, 120);
        if ($login === '') {
            $base = $first !== '' || $last !== '' ? ($first !== '' ? $first . '.' : '') . $last : $display;
            $login = ent_new_login($base);
        }
        if (!valid_login($login)) { $r['skipped']++; continue; }
        $subject = mb_substr(trim(preg_replace('/\s+/u', ' ', csv_get($row, ['matiere', 'matière', 'discipline']))), 0, 60);
        if ($subject !== '' && safe_name($subject) === '') $subject = '';
        $classes = [];
        foreach (preg_split('/[,|\/]+/', csv_get($row, ['classes', 'classe'])) as $c) {
            $c = trim($c);
            if ($c === '') continue;
            $m = match_class($c, $data);
            if ($m !== '') $classes[] = $m; else $r['unknown'][$c] = true;
        }
        $pass = csv_get($row, ['mot de passe', 'password']);
        if (isset($users[$login])) {
            if (!$update) { $r['skipped']++; continue; }
            if ($subject !== '') $users[$login]['subject'] = $subject;
            if ($classes) $users[$login]['classes'] = array_values(array_unique($classes));
            if ($ent !== '') $users[$login]['ent_id'] = $ent;
            if (strlen($pass) >= TPW_PASSWORD_MIN) $users[$login]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
            $r['updated']++;
        } else {
            $users[$login] = ['name' => mb_substr($display, 0, 60), 'role' => 'prof', 'hash' => strlen($pass) >= TPW_PASSWORD_MIN ? password_hash($pass, PASSWORD_DEFAULT) : '',
                              'folders' => [], 'ent_id' => $ent, 'perms' => normalize_perms(null), 'subject' => $subject,
                              'classes' => array_values(array_unique($classes)), 'created' => time(), 'created_by' => 'import'];
            $r['created']++;
        }
        $touched[] = $login;
    }
    save_users($users);
    foreach (array_unique($touched) as $l) ensure_subject_folders($l);
    $r['unknown'] = array_keys($r['unknown']);
    return $r;
}
