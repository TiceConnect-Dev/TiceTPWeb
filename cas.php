<?php
/**
 * Retour de l'ENT (CAS) :
 *  - mode=login : connexion d'un enseignant dont le compte est lié à l'ENT ;
 *  - mode=link  : liaison du compte connecté à son identifiant ENT ;
 *  - mode=test  : (administrateur) affiche ce que l'ENT renvoie, pour la mise en place.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/cas.php';
require_once __DIR__ . '/inc/totp.php';

$data = settings();
$cfg = ent_config($data);
$ticket = query('ticket');

// L'adresse de service est fixe (…/cas.php, sans paramètre) : c'est celle déclarée dans
// le connecteur de l'ENT. Le mode et le jeton anti-falsification sont gardés en session.
$pending = isset($_SESSION['cas_pending']) && is_array($_SESSION['cas_pending']) ? $_SESSION['cas_pending'] : null;
if ($ticket !== '' && $pending) {
    $mode = $pending['mode'];
} else {
    $mode = query('mode', 'login');
}
if (!in_array($mode, ['login', 'link', 'test', 'eleve'], true)) $mode = 'login';

if (!ent_enabled($data) && !($mode === 'test' && is_admin() && $cfg['url'] !== '')) {
    fail(404, 'Connexion ENT désactivée', 'La connexion par l’ENT n’est pas activée sur ce portail.');
}
if (($mode === 'link' && !logged()) || ($mode === 'test' && !is_admin())) {
    redirect('admin.php');
}

/* ---------- 1. Départ vers l'ENT ---------- */

if ($ticket === '') {
    $service = cas_service_url($cfg);
    $retry = isset($_SESSION['cas_retry']) && $_SESSION['cas_retry'] > time() - 120;
    $_SESSION['cas_pending'] = ['mode' => $mode, 'time' => time(), 'service' => $service, 'retry' => $retry];
    redirect(cas_login_url($cfg, $service));
}

/* ---------- 2. Retour de l'ENT : validation du ticket ---------- */

unset($_SESSION['cas_pending']);
if (!$pending || $pending['time'] < time() - 900) {
    // Retour sans demande en cours (ou trop ancienne) : on relance proprement.
    redirect('cas.php?mode=' . rawurlencode($mode));
}

$sent = isset($pending['service']) ? $pending['service'] : cas_service_url($cfg);
$service = cas_return_service($sent);
$check = cas_validate_variants($cfg, $service, $ticket);
$result = $check['ok'];

// Premier échec : on relance une fois la demande. L'utilisateur est maintenant connecté
// à l'ENT, le second passage se fait sans écran (et sans la redirection d'authentification
// qui peut altérer l'adresse de service associée au ticket).
if ($result === null && empty($pending['retry'])) {
    $_SESSION['cas_retry'] = time();
    redirect('cas.php?mode=' . rawurlencode($mode));
}
unset($_SESSION['cas_retry']);

if ($result === null) {
    if ($mode === 'test' || is_admin()) {
        release_session();
        http_response_code(502);
        page_top('Connexion ENT impossible', 'admin');
        ?>
        <div class="container container--narrow section">
          <section class="card">
            <?php alert('error', 'L’ENT a délivré un ticket mais a refusé toutes les formes d’adresse essayées.', 'Connexion ENT impossible'); ?>
            <h1 class="h3">Diagnostic</h1>
            <dl class="meta-list">
              <div><dt>Adresse envoyée à l’ENT</dt><dd><code><?= h($sent) ?></code></dd></div>
              <div><dt>Adresse de retour</dt><dd><code><?= h($service) ?></code></dd></div>
              <?php foreach ($check['attempts'] as $a): ?>
                <div><dt>Essai « <?= h($a[0]) ?> »</dt><dd><code><?= h($a[1]) ?></code><br><span class="muted small"><?= h($a[2]) ?></span></dd></div>
              <?php endforeach; ?>
            </dl>
            <p class="muted small">Envoyez une capture de cette page pour analyse.</p>
            <div class="form__actions"><a class="btn btn--secondary" href="<?= h(admin_url('settings')) ?>#ent">Retour aux paramètres</a></div>
          </section>
        </div>
        <?php
        page_end();
        exit;
    }
    $details = [];
    foreach ($check['attempts'] as $a) $details[] = $a[0] . ' : ' . $a[2];
    fail(502, 'Connexion ENT impossible', 'L’ENT n’a pas validé la connexion. Réessayez dans quelques instants. Détail — ' . implode(' | ', $details));
}
$entId = $result['user'];

/* ---------- Élève (liste des élèves, Administration › Élèves) ---------- */

$student = find_student_by_ent($entId);
if ($mode === 'eleve' || ($mode === 'login' && $student !== null && find_user_by_ent($entId) === null)) {
    if ($student === null) {
        // Élève inconnu : création directe si l'ENT transmet nom, prénom et classe (réglage),
        // sinon demande d'accès à faire valider par un enseignant.
        $attrs = ent_student_attrs($result['attributes']);
        $cls = $attrs['class'] !== '' ? match_class(preg_replace('/^.*\$/', '', $attrs['class']), $data) : '';
        if (!empty($cfg['students_autocreate']) && $attrs['last'] !== '' && $attrs['first'] !== '' && $cls !== '') {
            $list = students_all();
            $nid = student_new_id();
            $list[$nid] = ['last' => $attrs['last'], 'first' => $attrs['first'], 'class' => $cls, 'groups' => [], 'ent_id' => mb_substr($entId, 0, 120), 'created' => time()];
            save_students($list);
            $student = find_student($nid);
            audit_log('student_created_ent', ['classe' => $cls], 'élève');
        } elseif (!empty($cfg['students_selfreg'])) {
            $_SESSION['ent_request'] = ['ent_id' => mb_substr($entId, 0, 120), 'last' => $attrs['last'], 'first' => $attrs['first'], 'class' => $cls, 'time' => time()];
            redirect('index.php?demande=1');
        }
    }
    if ($student === null) {
        audit_log('ent_unknown', ['ent' => $entId, 'espace' => 'élèves'], '-');
        release_session();
        page_top('Élève non enregistré');
        ?>
        <div class="container container--narrow section">
          <section class="card card--form">
            <div class="card__icon"><?= icon('user') ?></div>
            <h1 class="h2">Compte élève non enregistré</h1>
            <p>Ta connexion à l’ENT a réussi, mais ton identifiant <code><?= h($entId) ?></code> n’est pas encore dans la liste des élèves de TPWeb.</p>
            <?php if (students_ent_only($data)): ?>
              <p>Montre ce message à ton professeur : il doit t’ajouter à la liste des élèves avec cet identifiant. Tu pourras ensuite te connecter.</p>
              <div class="form__actions"><a class="btn" href="index.php">Retour à l’accueil</a></div>
            <?php else: ?>
              <p>Tu peux quand même accéder à ta classe : choisis-la sur la page d’accueil (avec le code donné par ton professeur si elle est protégée), et signale-lui ce message.</p>
              <div class="form__actions"><a class="btn" href="index.php">Choisir ma classe</a></div>
            <?php endif; ?>
          </section>
        </div>
        <?php
        page_end();
        exit;
    }
    login_student($student);
    audit_log('student_ent_login', ['classe' => $student['class']], 'élève');
    redirect(level_ok($student['class'], $data) ? level_url($student['class']) : 'index.php');
}

if ($mode === 'login') {
    $user = find_user_by_ent($entId);
    $created = false;
    if ($user === null && $cfg['autocreate'] !== 'off') {
        // Première connexion : création automatique du compte enseignant.
        $user = ent_create_account($entId, $result['attributes'], $cfg['autocreate'] === 'pending');
        $created = true;
        audit_log('ent_account_created', ['compte' => $user['login'], 'ent' => $entId, 'état' => $cfg['autocreate'] === 'pending' ? 'en attente' : 'actif'], $user['login']);
    }
    if ($user !== null && !empty($user['pending'])) {
        release_session();
        page_top('Compte en attente de validation', 'admin');
        ?>
        <div class="container container--narrow section">
          <section class="card card--form">
            <div class="card__icon"><?= icon('clock') ?></div>
            <h1 class="h2"><?= $created ? 'Compte créé' : 'Compte en attente' ?></h1>
            <p>Bonjour <?= h($user['name']) ?>. Votre compte TPWeb <?= $created ? 'vient d’être créé à partir de votre connexion à l’ENT' : 'a bien été créé' ?>.</p>
            <p>Il doit maintenant être <strong>validé par l’administrateur du portail</strong>. Une fois validé, cliquez simplement à nouveau sur « Se connecter avec <?= h($cfg['label']) ?> ».</p>
            <div class="form__actions"><a class="btn" href="admin.php">Retour à la connexion</a></div>
          </section>
        </div>
        <?php
        page_end();
        exit;
    }
    if ($user === null) {
        audit_log('ent_unknown', ['ent' => $entId], '-');
        release_session();
        page_top('Compte ENT non reconnu', 'admin');
        ?>
        <div class="container container--narrow section">
          <section class="card card--form">
            <div class="card__icon"><?= icon('user') ?></div>
            <h1 class="h2">Compte ENT non reconnu</h1>
            <p>Votre connexion à l’ENT a réussi, mais l’identifiant <code><?= h($entId) ?></code> n’est lié à aucun compte enseignant de TPWeb.</p>
            <p>Deux possibilités :</p>
            <ul>
              <li>connectez-vous une fois avec votre identifiant TPWeb, puis dans <strong>Mon compte</strong> cliquez sur « Lier mon compte ENT » ;</li>
              <li>ou transmettez l’identifiant ci-dessus à l’administrateur du portail.</li>
            </ul>
            <div class="form__actions"><a class="btn" href="admin.php">Retour à la connexion</a></div>
          </section>
        </div>
        <?php
        page_end();
        exit;
    }
    if ($created) $_SESSION['flash'] = 'Bienvenue ! Votre compte TPWeb a été créé. Choisissez votre matière et vos classes : vos dossiers seront créés automatiquement.';
    admin_login_reset();
    // Double authentification éventuelle, puis connexion.
    $next = login_or_second_factor($user['login'], 'ent');
    redirect($next === 'admin.php' && $user['subject'] === '' ? admin_url('account') . '#mes-classes' : $next);
}

if ($mode === 'link') {
    $me = current_user();
    $other = find_user_by_ent($entId);
    if ($other !== null && $other['login'] !== $me['login']) {
        fail(409, 'Identifiant ENT déjà utilisé', 'Cet identifiant ENT est déjà lié au compte « ' . $other['login'] . ' ». Demandez à l’administrateur de le délier.');
    }
    $users = users_all();
    $users[$me['login']]['ent_id'] = $entId;
    save_users($users);
    audit_log('ent_link', ['ent' => $entId], $me['login']);
    $_SESSION['flash'] = 'Votre compte est maintenant lié à l’ENT (' . $entId . '). Vous pouvez utiliser « Se connecter avec l’ENT ».';
    redirect(admin_url('account'));
}

/* ---------- Mode test (administrateur) ---------- */

release_session();
page_top('Test de la connexion ENT', 'admin');
?>
<div class="container container--narrow section">
  <section class="card">
    <?php alert('success', 'Le serveur CAS a validé la connexion.', 'Connexion ENT fonctionnelle'); ?>
    <h1 class="h3">Ce que l’ENT transmet</h1>
    <dl class="meta-list">
      <div><dt>Forme d’adresse acceptée</dt><dd><?= h(end($check['attempts'])[0]) ?> — <code><?= h(end($check['attempts'])[1]) ?></code></dd></div>
      <div><dt>Identifiant (cas:user)</dt><dd><code><?= h($entId) ?></code></dd></div>
      <?php foreach ($result['attributes'] as $name => $values): ?>
        <div><dt><?= h($name) ?></dt><dd><?= h(implode(', ', $values)) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <p class="muted small">C’est cet identifiant qui doit être renseigné dans le champ « Identifiant ENT » des comptes enseignants (ou lié automatiquement depuis « Mon compte »).</p>
    <div class="form__actions"><a class="btn btn--secondary" href="<?= h(admin_url('settings')) ?>">Retour aux paramètres</a></div>
  </section>
</div>
<?php
page_end();
