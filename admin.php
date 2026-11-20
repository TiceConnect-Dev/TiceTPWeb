<?php
/**
 * Espace enseignant : routeur (connexion, actions, téléchargements, vues).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/backup.php';
require_once __DIR__ . '/inc/cas.php';
require_once __DIR__ . '/inc/trash.php';
require_once __DIR__ . '/inc/totp.php';
require_once __DIR__ . '/inc/autobackup.php';
require_once __DIR__ . '/inc/admin/actions.php';
require_once __DIR__ . '/inc/admin/views_library.php';
require_once __DIR__ . '/inc/admin/views_students.php';
require_once __DIR__ . '/inc/admin/views_admin.php';
require_once __DIR__ . '/inc/admin/views_quiz.php';
require_once __DIR__ . '/inc/admin/views_people.php';

/** Menu : clé => [libellé, icône, réservé à l'administrateur, droit nécessaire]. */
$views = [
    'library'  => ['Bibliothèque', 'library', false, null],
    'deposits' => ['Dépôts des élèves', 'inbox', false, 'deposits'],
    'announcements' => ['Annonces', 'megaphone', false, 'announcements'],
    'exams'    => ['Mode examen', 'exam', false, 'exams'],
    'access'   => ['Codes d’accès', 'key', false, 'codes'],
    'groups'   => ['Groupes', 'group', false, 'groups'],
    'students' => ['Élèves', 'school', false, 'students'],
    'trash'    => ['Corbeille', 'trash', false, 'manage'],
    'levels'   => ['Niveaux et classes', 'users', true, null],
    'users'    => ['Comptes enseignants', 'user', true, null],
    'year'     => ['Fin d’année', 'calendar', true, null],
    'journal'  => ['Journal d’activité', 'clock', true, null],
    'backups'  => ['Sauvegardes', 'archive', true, null],
    'storage'  => ['Stockage', 'disk', true, null],
    'settings' => ['Paramètres', 'settings', true, null],
    'account'  => ['Mon compte', 'user', false, null],
    'feedback' => ['Signaler un bug / une idée', 'megaphone', false, null],
    'quiz'     => ['QCM', 'quiz', false, 'upload'], // page sans entrée de menu (ouverte depuis la bibliothèque)
];
$hiddenViews = ['quiz'];

if (isset($_GET['cancel2fa'])) {
    unset($_SESSION['pending_2fa']);
    redirect('admin.php');
}

/** Compte connecté qui doit d'abord activer la double authentification. */
function must_setup_2fa()
{
    $u = current_user();
    return $u !== null && $u['totp'] === '' && totp_required($u);
}

$error = '';
$notice = '';

/* ---------- Formulaires ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = post('action');
    if (logged() && isset(audit_labels()[$act]) && !in_array($act, ['login', 'setup', 'logout'], true)) {
        audit_pending($act, audit_post_details());
    }
    if (must_setup_2fa() && !in_array($act, ['totp_start', 'totp_enable', 'logout'], true)) {
        $error = 'Activez d’abord la double authentification (obligatoire pour votre compte).';
    } else {
        $error = handle_admin_post($act);
    }
}

/* ---------- Téléchargements (enseignant connecté) ---------- */

if (isset($_GET['dl']) && logged()) {
    handle_admin_download(query('dl'));
}

if (isset($_SESSION['flash'])) {
    $notice = (string) $_SESSION['flash'];
    unset($_SESSION['flash']);
}
if (isset($_SESSION['totp_codes'])) {
    // Codes de secours : affichés une seule fois.
    $GLOBALS['tpw_totp_codes'] = $_SESSION['totp_codes'];
    unset($_SESSION['totp_codes']);
}

release_session();

/* ---------- Deuxième étape de connexion ---------- */

if (!logged() && !empty($_SESSION['pending_2fa'])) {
    view_second_factor($error);
    exit;
}

/* ---------- Connexion / installation ---------- */

if (!configured() || !logged()) {
    view_login(!configured(), $error);
    exit;
}

/* ---------- Interface ---------- */

$user = current_user();
maintenance_run();
$data = settings();
$view = query('view', 'library');
if ($view === 'security') $view = is_admin($user) ? 'settings' : 'account';
$allowed = function ($v) use ($user) {
    return !($v[2] && !is_admin($user)) && ($v[3] === null || can($v[3], $user));
};
if (!isset($views[$view]) || !$allowed($views[$view])) $view = 'library';
$force2fa = must_setup_2fa();
if ($force2fa) $view = 'account';

page_top($views[$view][0] . ' — Espace enseignant', 'admin');
?>
<div class="container admin">
  <aside class="sidemenu" aria-label="Menu de l’espace enseignant">
    <div class="sidemenu__user">
      <span class="sidemenu__avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
      <span><strong><?= h($user['name']) ?></strong><small><?= h(role_label($user['role'])) ?></small></span>
    </div>
    <ul>
      <?php foreach ($views as $key => $v):
          if (!$allowed($v) || in_array($key, $hiddenViews, true)) continue;
          if ($key === 'levels') echo '</ul><p class="sidemenu__title">Administration</p><ul>';
          if ($key === 'account') echo '</ul><hr><ul>'; ?>
        <li>
          <a href="<?= h(admin_url($key)) ?>" class="sidemenu__link<?= $key === $view ? ' is-active' : '' ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>>
            <?= icon($v[1]) ?><span><?= h($v[0]) ?></span>
            <?php if ($key === 'exams' && active_exams($data)): ?><span class="sidemenu__pill"><?= count(active_exams($data)) ?></span><?php endif; ?>
            <?php if ($key === 'users' && pending_users()): ?><span class="sidemenu__pill" title="Comptes à valider"><?= count(pending_users()) ?></span><?php endif; ?>
            <?php if ($key === 'students' && ($nr = count(student_requests_for($user)))): ?><span class="sidemenu__pill" title="Demandes d’accès"><?= $nr ?></span><?php endif; ?>
            <?php if ($key === 'feedback' && is_admin($user) && ($nf = feedback_new_count())): ?><span class="sidemenu__pill" title="Nouveaux signalements"><?= $nf ?></span><?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
      <li><a class="sidemenu__link" href="index.php" target="_blank" rel="noopener"><?= icon('external') ?><span>Voir l’espace élèves</span></a></li>
      <li>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="sidemenu__link sidemenu__link--button"><?= icon('logout') ?><span>Se déconnecter</span></button>
        </form>
      </li>
    </ul>
  </aside>

  <div class="admin__content">
    <?php
    if ($notice !== '') alert('success', $notice);
    elseif (isset($_GET['saved'])) alert('success', 'Les modifications ont été enregistrées.');
    if ($error !== '') alert('error', $error);
    if ($force2fa) {
        alert('warning', 'L’administrateur a rendu la double authentification obligatoire pour votre compte. Activez-la ci-dessous pour accéder au portail.', 'Étape obligatoire');
    }
    if (active_exams($data) && $view !== 'exams') {
        alert('warning', 'Un mode examen est en cours : les classes concernées ne voient que le dossier de l’épreuve.', 'Mode examen actif');
    }

    switch ($view) {
        case 'deposits': view_deposits($data, $user); break;
        case 'exams':    view_exams($data, $user); break;
        case 'announcements': view_announcements($data, $user); break;
        case 'trash':    view_trash($user); break;
        case 'year':     view_year($data); break;
        case 'journal':  view_journal($data); break;
        case 'access':   view_access($data); break;
        case 'groups':
            $gc = query('class');
            if ($gc !== '' && level_ok($gc, $data) && (is_admin($user) || in_array($gc, $user['classes'], true))) view_group_members($gc, $data);
            else view_groups($data, $user);
            break;
        case 'students': view_students($data, $user); break;
        case 'feedback': view_feedback($user); break;
        case 'quiz':     view_quiz($data, $user); break;
        case 'storage':  view_storage($data); break;
        case 'levels':   view_levels($data); break;
        case 'users':    view_users($data, $user); break;
        case 'backups':  view_backups(); break;
        case 'settings': view_settings($data); break;
        case 'account':  view_account($user); break;
        default:         view_library($data, $user);
    }
    ?>
  </div>
</div>
<?php
page_end();

// Sauvegarde automatique en retard (sans tâche planifiée) : après l'envoi de la page.
if (autobackup_due()) {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else flush();
    autobackup_run();
}
