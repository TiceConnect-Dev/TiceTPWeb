<?php
/**
 * Journal d'activité (traçabilité) et maintenance automatique des durées de conservation.
 *
 * Journal : TPWebData/config/journal/AAAA-MM.jsonl — une ligne JSON par action
 *   { "t": horodatage, "u": compte, "ip": adresse, "a": action, "d": { détails } }
 * Conservation : 12 mois (purge automatique). Aucun mot de passe ni code n'y est écrit.
 */

const AUDIT_MONTHS = 12;
const ARCHIVE_DAYS = 365;

function audit_dir()
{
    return TPW_STATE . DIRECTORY_SEPARATOR . 'journal';
}

/** Libellés lisibles des actions. */
function audit_labels()
{
    return [
        'setup' => 'Installation du portail', 'login' => 'Connexion', 'login_failed' => 'Échec de connexion',
        'ent_login' => 'Connexion par l’ENT', 'ent_link' => 'Liaison du compte ENT', 'ent_unknown' => 'Compte ENT non reconnu',
        'ent_unlink' => 'Compte ENT délié', 'logout' => 'Déconnexion',
        'save' => 'Publication modifiée', 'copy_publications' => 'Publications copiées',
        'upload' => 'Fichiers ajoutés', 'add_link' => 'Lien ajouté', 'mkdir' => 'Dossier créé',
        'rename' => 'Élément renommé', 'move' => 'Élément déplacé', 'duplicate' => 'Élément dupliqué',
        'delete' => 'Élément mis à la corbeille', 'trash_restore' => 'Élément restauré',
        'trash_delete' => 'Suppression définitive', 'trash_empty' => 'Corbeille vidée',
        'deposit_save' => 'Dépôt configuré', 'deposit_delete_entry' => 'Rendu supprimé', 'deposit_purge' => 'Rendus supprimés',
        'student_deposit' => 'Travail déposé par un élève', 'download_rendus' => 'Rendus téléchargés',
        'exam_start' => 'Mode examen lancé', 'exam_stop' => 'Mode examen terminé',
        'announce_save' => 'Annonce publiée', 'announce_delete' => 'Annonce supprimée',
        'class_code' => 'Code de classe modifié', 'levels' => 'Classes modifiées',
        'user_save' => 'Compte enseignant enregistré', 'user_delete' => 'Compte enseignant supprimé',
        'account' => 'Compte personnel modifié', 'my_classes' => 'Classes de l’enseignant modifiées',
        'school' => 'Établissement modifié', 'ent_settings' => 'Réglages ENT modifiés', 'legal' => 'Mentions légales modifiées',
        'backup_create' => 'Sauvegarde créée', 'backup_delete' => 'Sauvegarde supprimée', 'backup_restore' => 'Sauvegarde restaurée',
        'year_end' => 'Fin d’année lancée',
        'quiz_save' => 'QCM enregistré', 'quiz_create' => 'QCM créé', 'quiz_reset' => 'Résultats de QCM effacés', 'student_quiz' => 'QCM rendu par un élève',
        'import_zip' => 'Archive importée', 'copy_to' => 'Élément copié', 'coteach_save' => 'Co-enseignants modifiés',
        'groups_save' => 'Groupes de classe modifiés', 'quota_save' => 'Quota modifié',
        'totp_enable' => 'Double authentification activée', 'totp_disable' => 'Double authentification désactivée',
        'totp_reset' => 'Double authentification réinitialisée', 'login_2fa_failed' => 'Échec du code de double authentification',
        'security_save' => 'Règles de sécurité modifiées', 'autobackup_save' => 'Sauvegarde automatique configurée',
        'autobackup_now' => 'Sauvegarde automatique lancée', 'ent_account_created' => 'Compte créé par l’ENT', 'student_ent_login' => 'Connexion d’un élève par l’ENT',
        'students_import' => 'Élèves importés', 'students_bulk' => 'Action groupée sur des élèves', 'feedback_add' => 'Signalement envoyé', 'student_correction' => 'Copie corrigée consultée par un élève', 'correction_save' => 'Copie annotée', 'correction_delete' => 'Copie annotée supprimée', 'update_apply' => 'Mise à jour de TPWeb', 'update_check' => 'Archive de mise à jour vérifiée', 'mail_settings' => 'Réglages des courriels', 'notify_save' => 'Notifications modifiées', 'mail_failed' => 'Échec d’envoi de courriel', 'quiz_import' => 'Questions de QCM importées', 'branding_save' => 'Apparence modifiée', 'seq_save' => 'Séquence configurée', 'deposit_feedback' => 'Notes / relances d’un dépôt', 'feedback_update' => 'Signalement traité', 'student_feedback' => 'Signalement envoyé par un élève', 'student_created_ent' => 'Élève créé par l’ENT',
        'student_request' => 'Demande d’accès d’un élève', 'student_request_decide' => 'Demande d’accès traitée', 'student_access' => 'Mode d’accès des élèves modifié', 'student_save' => 'Élève enregistré', 'student_delete' => 'Élève supprimé', 'students_purge' => 'Liste d’élèves vidée',
        'group_members' => 'Élèves répartis dans les groupes', 'group_add' => 'Groupe créé', 'group_remove' => 'Groupe supprimé',
        'users_import' => 'Comptes enseignants importés', 'user_merge' => 'Compte ENT rattaché à un compte existant', 'user_approve' => 'Compte enseignant validé', 'backup_auto' => 'Sauvegarde automatique',
    ];
}

function client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '';
}

/** Écrit une ligne dans le journal. $who : identifiant, 'élève', ou null = compte connecté. */
function audit_log($action, array $details = [], $who = null)
{
    if ($who === null) {
        $u = function_exists('current_user') ? current_user() : null;
        $who = $u ? $u['login'] : '-';
    }
    $dir = audit_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return;
    $line = json_encode([
        't' => time(), 'u' => (string) $who, 'ip' => client_ip(), 'a' => (string) $action, 'd' => $details,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($dir . DIRECTORY_SEPARATOR . date('Y-m') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Action en attente : enregistrée au moment de la redirection qui suit un succès
 * (les erreurs ne redirigent pas et ne sont donc pas journalisées comme réussies).
 */
function audit_pending($action, array $details = [], $who = null)
{
    if ($who === null) {
        $u = current_user();
        $who = $u ? $u['login'] : '-';
    }
    $GLOBALS['tpw_audit_pending'] = [$action, $details, $who];
}

function audit_flush()
{
    if (empty($GLOBALS['tpw_audit_pending'])) return;
    list($a, $d, $w) = $GLOBALS['tpw_audit_pending'];
    unset($GLOBALS['tpw_audit_pending']);
    audit_log($a, $d, $w);
}

/** Détails sûrs d'un formulaire (jamais de mot de passe, de code ni de texte libre long). */
function audit_post_details()
{
    $keep = ['folder' => 'dossier', 'item' => 'élément', 'name' => 'nom', 'dest' => 'destination', 'level' => 'classe',
             'mode' => 'mode', 'type' => 'type', 'source' => 'source', 'subject' => 'matière', 'title' => 'titre', 'url' => 'adresse',
             'class' => 'classe'];
    $modes = ['publish' => 'publier', 'hide' => 'masquer', 'inherit' => 'hériter'];
    $out = [];
    foreach ($keep as $k => $label) {
        if (!isset($_POST[$k]) || !is_string($_POST[$k]) || $_POST[$k] === '') continue;
        $v = mb_substr($_POST[$k], 0, 200);
        if ($k === 'mode' && isset($modes[$v])) $v = $modes[$v];
        if ($k === 'dest' && $v === '/') $v = 'racine';
        $out[$label] = $v;
    }
    if (isset($_POST['login']) && is_string($_POST['login']) && in_array(post('action'), ['user_save', 'user_delete'], true)) {
        $out['compte'] = mb_substr($_POST['login'], 0, 40);
    }
    if (isset($_POST['levels']) && is_array($_POST['levels'])) {
        $out['classes'] = mb_substr(implode(', ', array_filter($_POST['levels'], 'is_string')), 0, 300);
    }
    if (isset($_POST['enabled'])) $out['actif'] = post('enabled') === '1' ? 'oui' : 'non';
    if (isset($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
        $out['fichiers'] = mb_substr(implode(', ', $_FILES['files']['name']), 0, 300);
    }
    return $out;
}

/** Mois disponibles dans le journal (plus récents en premier). */
function audit_months()
{
    $out = [];
    foreach ((array) @scandir(audit_dir()) as $f) {
        if (preg_match('/^(\d{4}-\d{2})\.jsonl$/', $f, $m)) $out[] = $m[1];
    }
    rsort($out);
    return $out;
}

function audit_read($month)
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) return [];
    $file = audit_dir() . DIRECTORY_SEPARATOR . $month . '.jsonl';
    $rows = [];
    foreach ((array) @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) $rows[] = $r + ['t' => 0, 'u' => '', 'ip' => '', 'a' => '', 'd' => []];
    }
    return array_reverse($rows);
}

function audit_details_text(array $d)
{
    $parts = [];
    foreach ($d as $k => $v) $parts[] = $k . ' : ' . (is_array($v) ? implode(', ', $v) : $v);
    return implode(' · ', $parts);
}

/* ---------- Maintenance (au plus une fois par jour) ---------- */

/**
 * Applique les durées de conservation annoncées dans les mentions légales :
 * journal 12 mois, archives de rendus 1 an, corbeille 30 jours, tentatives de connexion 1 heure.
 */
function maintenance_run($force = false)
{
    $stamp = TPW_STATE . '/maintenance.json';
    $last = load_json($stamp, ['t' => 0]);
    if (!$force && (int) $last['t'] > time() - 86400) return;
    atomic_json($stamp, ['t' => time()]);

    $limit = date('Y-m', strtotime('-' . AUDIT_MONTHS . ' months'));
    foreach (audit_months() as $m) {
        if ($m < $limit) @unlink(audit_dir() . DIRECTORY_SEPARATOR . $m . '.jsonl');
    }

    foreach ((array) @scandir(TPW_ARCHIVES) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = TPW_ARCHIVES . DIRECTORY_SEPARATOR . $f;
        if (@filemtime($p) < time() - ARCHIVE_DAYS * 86400) remove_tree($p);
    }

    if (function_exists('trash_purge_old')) trash_purge_old();
    if (function_exists('office_cache_purge')) office_cache_purge();
    if (function_exists('stats_purge')) stats_purge();

    $attempts = load_json(TPW_STATE . '/login_attempts.json', []);
    foreach ($attempts as $k => $row) {
        if (!isset($row['last']) || (int) $row['last'] < time() - 3600) unset($attempts[$k]);
    }
    atomic_json(TPW_STATE . '/login_attempts.json', $attempts);
}
