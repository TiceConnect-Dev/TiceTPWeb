<?php
/**
 * Espace élèves : choix de la classe, code éventuel, consultation, recherche et dépôt des travaux.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/cas.php';

$data  = settings();
$level = query('level');
$dir   = query('dir');
$q     = trim(query('q'));
$niveau = query('niveau');
$accessError = '';

if ($level !== '' && !level_ok($level, $data)) {
    fail(404, 'Classe introuvable', 'Cette classe n’existe pas ou a été renommée par votre professeur.');
}

/* =====================================================================
   Formulaires
   ===================================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');

    if ($action === 'class_login') {
        $requested = post('level');
        if (!level_ok($requested, $data)) fail(404, 'Classe introuvable', 'Cette classe n’existe pas ou a été renommée.');
        if (validate_class_code($requested, post('code'))) redirect(level_url($requested));
        $level = $requested;
        $dir = '';
        $accessError = class_limit_message($level);
    }

    if ($action === 'student_feedback') {
        $sent = isset($_SESSION['fb_sent']) ? array_filter((array) $_SESSION['fb_sent'], function ($t) { return $t > time() - 3600; }) : [];
        if (post('website') !== '') redirect('index.php'); // piège à robots
        if (count($sent) >= 5) {
            $_SESSION['fb_flash'] = ['error', 'Trop de signalements envoyés : réessaie plus tard.'];
        } else {
            $me = current_student();
            $who = $me ? student_name($me) : trim(preg_replace('/\s+/u', ' ', post('name')));
            $cls = $me ? $me['class'] : (level_ok(post('class'), $data) ? post('class') : '');
            $err = feedback_add(post('type'), post('title'), post('text'), post('page'), mb_substr($who, 0, 80), 'élève', $cls);
            if ($err === '') {
                $sent[] = time();
                $_SESSION['fb_sent'] = $sent;
                audit_log('student_feedback', ['classe' => $cls], 'élève');
            }
            $_SESSION['fb_flash'] = $err === '' ? ['success', 'Merci ! Ton message a été transmis à l’équipe du portail.'] : ['error', $err];
        }
        redirect('index.php?signaler=1');
    }

    if ($action === 'student_request') {
        $req = isset($_SESSION['ent_request']) ? $_SESSION['ent_request'] : null;
        if (!$req || $req['time'] < time() - 1800) redirect('index.php');
        $last = clean_person_name(post('last'));
        $first = clean_person_name(post('first'));
        $cls = level_ok(post('class'), $data) ? post('class') : '';
        if ($last === '' || $first === '' || $cls === '') {
            $_SESSION['req_flash'] = 'Indique ton nom, ton prénom et ta classe.';
            redirect('index.php?demande=1');
        }
        $list = student_requests();
        $list[md5(strtolower($req['ent_id']))] = ['ent_id' => $req['ent_id'], 'last' => $last, 'first' => $first, 'class' => $cls, 'time' => time()];
        save_student_requests($list);
        audit_log('student_request', ['classe' => $cls], 'élève');
        $_SESSION['ent_request']['sent'] = true;
        redirect('index.php?demande=1');
    }

    if ($action === 'student_logout') {
        unset($_SESSION['student']);
        session_regenerate_id(true);
        redirect('index.php');
    }

    if ($action === 'set_groups') {
        $level = post('level');
        if (!level_ok($level, $data) || !class_allowed($level)) redirect(level_url($level));
        $wanted = isset($_POST['groups']) && is_array($_POST['groups']) ? $_POST['groups'] : [];
        $codes = isset($_POST['gcode']) && is_array($_POST['gcode']) ? $_POST['gcode'] : [];
        $kept = [];
        $refused = [];
        foreach (class_groups($data, $level) as $i => $g) {
            if (!in_array($g['name'], $wanted, true)) continue;
            $already = in_array($g['name'], viewer_groups($level, $data), true);
            if ($already || group_code_ok($g, isset($codes[$i]) ? $codes[$i] : '')) $kept[] = $g['name'];
            else $refused[] = $g['name'];
        }
        if (post('none') === '1') { $kept = []; $refused = []; }
        $_SESSION['groups'][$level] = $kept;
        $_SESSION['groups_flash'] = $refused ? 'Code incorrect pour : ' . implode(', ', $refused) . '. Demandez-le à votre professeur.' : '';
        $back = post('back');
        redirect(strpos($back, 'index.php?') === 0 ? $back : level_url($level));
    }

    if ($action === 'deposit') {
        $level = post('level');
        $dir = post('dir');
        if (!level_ok($level, $data) || !class_allowed($level)) redirect(level_url($level));
        $cfg = $dir !== '' ? deposit_for($dir) : null;
        if (!deposit_for_viewer($cfg, $level) || !full_path($dir, 'dir') || !can_browse($dir, $level, $data)) {
            fail(404, 'Dépôt indisponible', 'Ce dépôt n’existe pas ou n’est pas ouvert pour votre classe.');
        }
        $last = clean_person_name(post('last'));
        $first = clean_person_name(post('first'));
        $me = current_student();
        if ($me && $me['class'] === $level) { $last = $me['last']; $first = $me['first']; } // élève identifié par l'ENT
        $files = uploaded_files('files');
        $errors = [];
        // Coéquipiers (rendu en binôme ou en groupe).
        $members = [];
        $mLast = isset($_POST['m_last']) && is_array($_POST['m_last']) ? $_POST['m_last'] : [];
        $mFirst = isset($_POST['m_first']) && is_array($_POST['m_first']) ? $_POST['m_first'] : [];
        for ($i = 0; $i < $cfg['group_max'] - 1; $i++) {
            $ml = isset($mLast[$i]) && is_string($mLast[$i]) ? trim($mLast[$i]) : '';
            $mf = isset($mFirst[$i]) && is_string($mFirst[$i]) ? trim($mFirst[$i]) : '';
            if ($ml === '' && $mf === '') continue;
            $cl = clean_person_name($ml);
            $cf = clean_person_name($mf);
            if ($cl === '' || $cf === '') { $errors[] = 'Nom ou prénom de coéquipier invalide (lettres uniquement).'; break; }
            $members[] = ['last' => $cl, 'first' => $cf];
        }
        if ($errors) {
            // erreur déjà signalée
        } elseif (!deposit_accepting($cfg)) $errors[] = 'Le dépôt est fermé.';
        elseif ($last === '' || $first === '') $errors[] = 'Indiquez votre nom et votre prénom (lettres uniquement).';
        elseif (!$files) $errors[] = 'Choisissez au moins un fichier.';
        else {
            $result = deposit_store($cfg, $level, $last, $first, $files, $members, $me && $me['class'] === $level ? $me['id'] : '');
            $errors = $result['errors'];
            if ($result['ok']) {
                audit_log('student_deposit', ['classe' => $level, 'dossier' => $dir, 'fichiers' => count($result['ok']), 'reçu' => $result['receipt']], 'élève');
                $who = $first . ' ' . mb_strtoupper($last);
                foreach ($members as $m) $who .= ', ' . $m['first'] . ' ' . mb_strtoupper($m['last']);
                $_SESSION['receipts'][$dir][] = ['name' => $who, 'files' => $result['ok'], 'time' => time(),
                    'receipt' => $result['receipt'], 'late' => !empty($result['late']), 'level' => $level, 'folder' => basename($dir)];
            }
        }
        $_SESSION['deposit_flash'] = ['ok' => isset($result) ? $result['ok'] : [], 'errors' => $errors, 'last' => $last, 'first' => $first,
                                      'receipt' => isset($result['receipt']) ? $result['receipt'] : '', 'late' => !empty($result['late']), 'members' => $members];
        redirect(level_url($level, $dir) . '#rendre');
    }
}

/* =====================================================================
   Code de classe
   ===================================================================== */

/* Demande d'accès d'un élève connecté par l'ENT mais absent de la liste. */
if (query('demande') === '1') {
    $req = isset($_SESSION['ent_request']) ? $_SESSION['ent_request'] : null;
    $flash = isset($_SESSION['req_flash']) ? $_SESSION['req_flash'] : '';
    unset($_SESSION['req_flash']);
    if (!$req) redirect('index.php');
    $existing = student_requests();
    $already = isset($existing[md5(strtolower($req['ent_id']))]) ? $existing[md5(strtolower($req['ent_id']))] : null;
    release_session();
    page_top('Demande d’accès');
    ?>
    <div class="container container--narrow section">
      <section class="card card--form">
        <div class="card__icon"><?= icon('school') ?></div>
        <?php if ($already): ?>
          <h1 class="h2">Demande envoyée</h1>
          <p>Ta demande pour la classe <strong><?= h($already['class']) ?></strong> (<?= h($already['first'] . ' ' . mb_strtoupper($already['last'])) ?>) attend la validation d’un professeur.</p>
          <p class="muted">Une fois validée, clique simplement à nouveau sur « Se connecter avec l’ENT ».</p>
          <div class="form__actions"><a class="btn" href="index.php">Retour à l’accueil</a></div>
        <?php else: ?>
          <h1 class="h2">Première connexion</h1>
          <p>Ta connexion à l’ENT a réussi, mais tu n’es pas encore inscrit sur le portail. Indique ta classe : un professeur validera ta demande.</p>
          <?php if ($flash !== '') alert('error', $flash); ?>
          <form method="post" class="form" action="index.php?demande=1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="student_request">
            <div class="field-row">
              <div class="field"><label for="rq-last">Nom</label><input id="rq-last" class="input" type="text" name="last" required maxlength="60" value="<?= h($req['last']) ?>"></div>
              <div class="field"><label for="rq-first">Prénom</label><input id="rq-first" class="input" type="text" name="first" required maxlength="60" value="<?= h($req['first']) ?>"></div>
            </div>
            <div class="field"><label for="rq-class">Ma classe</label>
              <select id="rq-class" class="input input--short" name="class" required>
                <option value="">— Choisir —</option>
                <?php foreach ($data['levels'] as $c): ?><option value="<?= h($c) ?>"<?= $c === $req['class'] ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
              </select></div>
            <div class="form__actions"><button class="btn" type="submit">Envoyer ma demande</button></div>
          </form>
        <?php endif; ?>
      </section>
    </div>
    <?php
    page_end();
    exit;
}

/* Signaler un problème ou une idée (élèves). */
if (query('signaler') === '1') {
    $fb = isset($_SESSION['fb_flash']) ? $_SESSION['fb_flash'] : null;
    unset($_SESSION['fb_flash']);
    release_session();
    $me = current_student();
    page_top('Signaler un problème');
    ?>
    <div class="container container--narrow section">
      <?php breadcrumb([['Accueil', 'index.php'], ['Signaler un problème', null]]); ?>
      <section class="card card--form">
        <div class="card__icon"><?= icon('megaphone') ?></div>
        <h1 class="h2">Signaler un problème ou proposer une idée</h1>
        <p>Un document qui ne s’ouvre pas, une erreur, une idée pour améliorer le portail ? Explique-le ici.</p>
        <?php if ($fb) alert($fb[0], $fb[1]); ?>
        <form method="post" class="form" action="index.php?signaler=1">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="student_feedback">
          <input type="hidden" name="page" value="<?= h(isset($_SERVER['HTTP_REFERER']) ? (string) parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY) : '') ?>">
          <div class="hp" aria-hidden="true"><label>Site web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="choices">
            <label class="choice"><input type="radio" name="type" value="bug" checked><span class="choice__body"><strong>Un problème</strong></span></label>
            <label class="choice"><input type="radio" name="type" value="idea"><span class="choice__body"><strong>Une idée</strong></span></label>
          </div>
          <div class="field"><label for="sf-title">En quelques mots</label><input id="sf-title" class="input" type="text" name="title" required maxlength="150" placeholder="ex. : Le TP 3 ne s’ouvre pas"></div>
          <div class="field"><label for="sf-text">Détails <span class="hint">facultatif</span></label><textarea id="sf-text" class="input input--text" name="text" rows="4" maxlength="4000"></textarea></div>
          <?php if ($me): ?>
            <p class="muted small">Envoyé au nom de <?= h(student_name($me)) ?> (<?= h($me['class']) ?>).</p>
          <?php else: ?>
            <div class="field-row">
              <div class="field"><label for="sf-name">Ton nom <span class="hint">facultatif</span></label><input id="sf-name" class="input" type="text" name="name" maxlength="80"></div>
              <div class="field"><label for="sf-class">Ta classe <span class="hint">facultatif</span></label>
                <select id="sf-class" class="input" name="class"><option value="">—</option><?php foreach ($data['levels'] as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?></select></div>
            </div>
          <?php endif; ?>
          <div class="form__actions"><button class="btn" type="submit">Envoyer</button><a class="btn btn--tertiary" href="index.php">Retour</a></div>
        </form>
      </section>
    </div>
    <?php
    page_end();
    exit;
}

/* Mes travaux : dépôts, reçus, notes et résultats de QCM (élève connecté), ou recherche par reçu. */
if (query('mestravaux') === '1') {
    $me = current_student();
    $code = strtoupper(trim(query('recu')));
    $lookup = null;
    $lookupError = '';
    if ($code !== '') {
        $tries = isset($_SESSION['receipt_tries']) ? array_filter((array) $_SESSION['receipt_tries'], function ($t) { return $t > time() - 600; }) : [];
        if (count($tries) >= 10) {
            $lookupError = 'Trop de recherches : réessaie dans quelques minutes.';
        } else {
            $tries[] = time();
            $_SESSION['receipt_tries'] = $tries;
            if (!preg_match('/^R-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) $code = preg_replace('/^R?-?([A-Z0-9]{4})-?([A-Z0-9]{4})$/', 'R-$1-$2', preg_replace('/\s+/', '', $code));
            foreach (deposits_config() as $p => $c) {
                $c = deposit_normalize($c);
                $files = array_values(array_filter(deposit_entries($c), function ($e) use ($code) { return isset($e['receipt']) && $e['receipt'] === $code; }));
                if ($files) { $lookup = ['path' => (string) $p, 'cfg' => $c, 'entries' => $files]; break; }
            }
            if (!$lookup) $lookupError = 'Aucun dépôt ne correspond à ce numéro de reçu. Vérifie le numéro (format R-XXXX-XXXX).';
        }
    }
    release_session();

    // Travaux de l'élève connecté.
    $mine = [];
    $quizzes = [];
    if ($me) {
        $myKey = deposit_name_key($me['class'], $me['last'], $me['first']);
        foreach (deposits_config() as $p => $c) {
            $c = deposit_normalize($c);
            $own = array_values(array_filter(deposit_entries($c), function ($e) use ($me, $myKey) {
                if (!empty($e['student']) && $e['student'] === $me['id']) return true;
                foreach (deposit_members($e) as $m) if (deposit_name_key($e['level'], $m['last'], $m['first']) === $myKey) return true;
                return false;
            }));
            $reminded = isset($c['reminders'][$me['id']]) && deposit_for_viewer($c, $me['class']) && deposit_accepting($c);
            if ($own || $reminded) $mine[] = ['path' => (string) $p, 'cfg' => $c, 'entries' => $own];
        }
        foreach (all_items() as $p => $t) {
            $p = (string) $p;
            if ($t !== 'file' || !is_quiz_file($p)) continue;
            $abs = full_path($p, 'file');
            $qz = $abs ? quiz_load($abs) : null;
            if (!$qz || $qz['id'] === '') continue;
            foreach (quiz_results($qz['id']) as $r) {
                $match = (!empty($r['student']) && $r['student'] === $me['id'])
                    || ($r['level'] === $me['class'] && mb_strtolower($r['last'] . '|' . $r['first']) === mb_strtolower($me['last'] . '|' . $me['first']));
                if ($match) $quizzes[] = ['path' => $p, 'quiz' => $qz, 'r' => $r];
            }
        }
        usort($quizzes, function ($a, $b) { return $b['r']['time'] - $a['r']['time']; });
    }

    $renderDeposit = function (array $d, $level, $last, $first, $sid) {
        $c = $d['cfg'];
        $fb = $c['feedback_public'] ? deposit_feedback_for(deposit_feedback($c), $sid, $level, $last, $first) : null;
        ?>
        <article class="card work">
          <div class="work__head">
            <h2 class="h4"><?= icon('inbox') ?><?= h(basename($d['path'])) ?></h2>
            <?php if ($d['entries']): ?><?= badge('Rendu', 'success') ?><?php else: ?><?= badge('Pas encore rendu', 'error') ?><?php endif; ?>
          </div>
          <?php if (!$d['entries']): ?>
            <p class="work__remind"><?= icon('megaphone') ?><span>Ton professeur t’a relancé : ce travail n’a pas encore été rendu<?= $c['until'] !== '' ? ' (date limite : ' . h(format_datetime($c['until'])) . ')' : '' ?>.</span></p>
            <?php if (level_ok($level, settings())): ?><p><a class="btn btn--sm" href="<?= h(level_url($level, $d['path'])) ?>#rendre"><?= icon('upload') ?>Rendre mon travail</a></p><?php endif; ?>
          <?php else: ?>
            <ul class="work__files">
              <?php foreach ($d['entries'] as $e): ?>
                <li><?= icon('check') ?><span><strong><?= h($e['orig']) ?></strong> · <?= h(date('d/m/Y à H:i', $e['time'])) ?><?= !empty($e['late']) ? ' ' . badge('En retard', 'warning') : '' ?><?= !empty($e['receipt']) ? ' · reçu <code>' . h($e['receipt']) . '</code>' : '' ?></span></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($fb): ?>
            <div class="work__feedback">
              <?php if ($fb['grade'] !== ''): ?><p class="work__grade">Note : <strong><?= h($fb['grade']) ?></strong></p><?php endif; ?>
              <?php if ($fb['comment'] !== ''): ?><p class="work__comment"><?= icon('info') ?><span><?= nl2br(h($fb['comment'])) ?></span></p><?php endif; ?>
            </div>
          <?php elseif ($d['entries']): ?>
            <p class="muted small">Pas encore de note ni de commentaire.</p>
          <?php endif; ?>
        </article>
        <?php
    };

    page_top('Mes travaux');
    ?>
    <div class="container container--text section">
      <?php breadcrumb([['Accueil', 'index.php'], ['Mes travaux', null]]); ?>
      <?php render_student_bar(); ?>
      <div class="page-head page-head--compact"><div><h1>Mes travaux</h1>
        <p class="muted"><?= $me ? 'Tes travaux rendus, les notes et commentaires de tes professeurs, et tes résultats de QCM.' : 'Retrouve un travail rendu grâce au numéro de reçu affiché après ton dépôt.' ?></p></div></div>

      <?php if ($me): ?>
        <?php if ($me && level_ok($me['class'], $data)): ?><p><a class="link-more" href="<?= h(level_url($me['class']) . '&todo=1') ?>"><?= icon('inbox') ?>Voir ce qu’il reste à rendre</a></p><?php endif; ?>
        <?php if (!$mine && !$quizzes): ?>
          <div class="empty"><?= icon('inbox') ?><p><strong>Rien pour le moment.</strong></p><p class="muted">Tes dépôts et tes QCM apparaîtront ici.</p></div>
        <?php endif; ?>
        <?php foreach ($mine as $d) $renderDeposit($d, $me['class'], $me['last'], $me['first'], $me['id']); ?>
        <?php if ($quizzes): ?>
          <h2 class="h4">Mes QCM</h2>
          <ul class="doc-list">
            <?php foreach ($quizzes as $q): ?>
              <li><div class="doc"><span class="doc__icon"><?= icon('quiz') ?></span><span class="doc__body">
                <span class="doc__title"><?= h($q['quiz']['title'] !== '' ? $q['quiz']['title'] : display_name($q['path'])) ?></span>
                <span class="doc__meta"><?= h(date('d/m/Y à H:i', $q['r']['time'])) ?></span></span>
                <span class="doc__action"><strong><?= (int) $q['r']['score'] ?> / <?= (int) $q['r']['total'] ?></strong> · <?= h(str_replace('.', ',', (string) quiz_on20($q['r']['score'], $q['r']['total']))) ?>/20</span></div></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endif; ?>

      <section class="card">
        <h2 class="h4"><?= icon('search') ?> Retrouver un dépôt avec mon reçu</h2>
        <form method="get" class="form form--inline" action="index.php">
          <input type="hidden" name="mestravaux" value="1">
          <div class="field"><label for="recu">Numéro de reçu</label>
            <input id="recu" class="input input--receipt" type="text" name="recu" maxlength="12" placeholder="R-XXXX-XXXX" value="<?= h(query('recu')) ?>" autocomplete="off" spellcheck="false"></div>
          <button class="btn" type="submit">Rechercher</button>
        </form>
        <?php if ($lookupError !== '') alert('error', $lookupError); ?>
      </section>
      <?php if ($lookup):
          $e0 = $lookup['entries'][0];
          $renderDeposit($lookup, $e0['level'], $e0['last'], $e0['first'], isset($e0['student']) ? (string) $e0['student'] : '');
      endif; ?>
    </div>
    <?php
    page_end();
    exit;
}

/* Accès réservé à l'ENT : sans connexion, aucune classe ni ressource n'est affichée. */
$entGate = students_ent_only($data) && !logged() && !current_student();
if ($entGate && ($level !== '' || $niveau !== '')) {
    redirect('index.php');
}
if ($entGate) {
    release_session();
    $entCfg = ent_config($data);
    page_top('Connexion');
    ?>
    <section class="hero">
      <div class="container">
        <p class="hero__kicker">Portail pédagogique de l’établissement</p>
        <h1>Cours et travaux pratiques</h1>
        <p class="lead">Connecte-toi avec ton compte ENT pour retrouver les cours de ta classe et rendre tes travaux.</p>
        <p class="hero__actions"><a class="btn btn--ent btn--hero" href="cas.php?mode=eleve"><?= icon('school') ?>Se connecter avec <?= h($entCfg['label']) ?></a></p>
      </div>
    </section>
    <div class="container section container--text">
      <p class="muted">Problème de connexion ou classe introuvable ? Signale-le à ton professeur. Enseignants : <a href="admin.php">espace enseignant</a>.</p>
    </div>
    <?php
    page_end();
    exit;
}

if ($level !== '' && !class_allowed($level) && students_ent_only($data)) {
    // Élève connecté par l'ENT qui ouvre une autre classe que la sienne.
    $me = current_student();
    fail(403, 'Classe non accessible', 'Tu as accès uniquement aux ressources de ta classe' . ($me ? ' (' . $me['class'] . ')' : '') . '. Utilise le bouton « Ma classe » ou reviens à l’accueil.');
}

if ($level !== '' && !class_allowed($level)) {
    release_session();
    page_top('Accès à la classe ' . $level);
    ?>
    <div class="container container--narrow section">
      <?php breadcrumb(class_crumbs($level, $data, false)); ?>
      <section class="card card--form">
        <div class="card__icon"><?= icon('lock') ?></div>
        <h1 class="h2">Accès protégé — <?= h($level) ?></h1>
        <p>Saisissez le code communiqué par votre professeur pour consulter les ressources de cette classe.</p>
        <?php if ($accessError !== '') alert('error', $accessError); ?>
        <form method="post" action="<?= h(level_url($level)) ?>" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="class_login">
          <input type="hidden" name="level" value="<?= h($level) ?>">
          <div class="field">
            <label for="code">Code de classe <span class="hint">4 caractères, lettres et chiffres</span></label>
            <input id="code" class="input input--code" name="code" type="password" required maxlength="4" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus>
          </div>
          <div class="form__actions">
            <button class="btn" type="submit">Accéder aux ressources</button>
            <a class="btn btn--secondary" href="<?= h(change_class_url($level, $data)) ?>">Changer de classe</a>
          </div>
        </form>
      </section>
    </div>
    <?php
    page_end();
    exit;
}

/* =====================================================================
   Page d'un niveau : choix de la classe
   ===================================================================== */

if ($level === '' && $niveau !== '') {
    $map = class_levels_map($data);
    if (!isset($map[$niveau])) fail(404, 'Niveau introuvable', 'Ce niveau n’existe pas ou a été renommé.');
    release_session();
    $codes = class_access_data();
    page_top($niveau);
    ?>
    <div class="container section">
      <?php breadcrumb([['Accueil', 'index.php'], [$niveau, null]]); ?>
      <div class="page-head">
        <div>
          <p class="page-head__kicker"><?= badge('Niveau', 'info') ?></p>
          <h1><?= h($niveau) ?></h1>
          <p class="muted">Choisissez votre classe.</p>
        </div>
        <div class="page-head__actions">
          <a class="btn btn--secondary btn--sm" href="index.php"><?= icon('back') ?>Tous les niveaux</a>
        </div>
      </div>
      <ul class="tiles tiles--levels">
        <?php foreach ($map[$niveau] as $lvl) class_tile($lvl, $data, $codes); ?>
      </ul>
    </div>
    <?php
    page_end();
    exit;
}

/* =====================================================================
   Accueil : choix du niveau (ou de la classe)
   ===================================================================== */

if ($level === '') {
    release_session();
    $codes = class_access_data();
    $structure = class_structure($data);
    $niveaux = array_values(array_filter($structure, function ($i) { return $i['type'] === 'level'; }));
    $singles = array_values(array_filter($structure, function ($i) { return $i['type'] === 'class'; }));
    page_top('Accueil');
    ?>
    <section class="hero">
      <div class="container">
        <p class="hero__kicker">Portail pédagogique de l’établissement</p>
        <h1>Cours et travaux pratiques</h1>
        <p class="lead">Retrouvez les cours, sujets de TP et documents publiés par vos professeurs, et rendez vos travaux en ligne. Choisissez votre <?= $niveaux ? 'niveau' : 'classe' ?> pour commencer.</p>
        <?php $me = current_student(); $entCfg = function_exists('ent_config') ? ent_config($data) : null; ?>
        <?php if ($me && level_ok($me['class'], $data)): ?>
          <p class="hero__actions"><a class="btn btn--hero" href="<?= h(level_url($me['class'])) ?>"><?= icon('arrow') ?>Aller dans ma classe (<?= h($me['class']) ?>)</a></p>
        <?php elseif ($entCfg && !empty($entCfg['enabled']) && $entCfg['url'] !== '' && !empty($entCfg['students'])): ?>
          <p class="hero__actions"><a class="btn btn--ent" href="cas.php?mode=eleve"><?= icon('school') ?>Se connecter avec <?= h($entCfg['label']) ?></a>
            <span class="hero__or">ou choisissez votre classe ci-dessous</span></p>
        <?php endif; ?>
      </div>
    </section>

    <div class="container section">
      <?php render_student_bar(); ?>
      <?php if (!$data['levels']): ?>
        <div class="empty"><?= icon('info') ?><p>Aucune classe n’est encore configurée.</p></div>
      <?php endif; ?>

      <?php if ($niveaux): ?>
        <div class="section__head">
          <h2>Choisir mon niveau</h2>
          <p class="muted"><?= count($niveaux) ?> niveau<?= count($niveaux) > 1 ? 'x' : '' ?></p>
        </div>
        <ul class="tiles tiles--levels">
          <?php foreach ($niveaux as $n):
              $examCount = count(array_filter($n['classes'], function ($c) use ($data) { return exam_folders_for($c, $data) !== null; })); ?>
            <li>
              <a class="tile tile--level tile--niveau" href="<?= h(niveau_url($n['name'])) ?>">
                <span class="tile__title"><?= h($n['name']) ?></span>
                <span class="tile__meta">
                  <?php if ($examCount): ?><?= icon('exam') ?>Examen en cours<?php else: ?><?= count($n['classes']) ?> classe<?= count($n['classes']) > 1 ? 's' : '' ?><?php endif; ?>
                </span>
                <span class="tile__arrow"><?= icon('arrow') ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($singles): ?>
        <div class="section__head<?= $niveaux ? ' section__head--spaced' : '' ?>">
          <h2><?= $niveaux ? 'Autres classes' : 'Choisir ma classe' ?></h2>
          <p class="muted"><?= count($singles) ?> classe<?= count($singles) > 1 ? 's' : '' ?></p>
        </div>
        <ul class="tiles tiles--levels">
          <?php foreach ($singles as $c) class_tile($c['name'], $data, $codes); ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    exit;
}

/* =====================================================================
   Classe : dossier, recherche, dépôt
   ===================================================================== */

if (!valid_path($dir) || ($dir !== '' && !full_path($dir, 'dir'))) {
    fail(404, 'Dossier introuvable', 'Ce dossier n’existe pas ou a été déplacé.');
}
if ($dir !== '' && !can_browse($dir, $level, $data)) {
    fail(404, 'Dossier non disponible', 'Ce dossier n’est pas publié pour votre classe.');
}

$depositFlash = isset($_SESSION['deposit_flash']) ? $_SESSION['deposit_flash'] : null;
unset($_SESSION['deposit_flash']);
$groupFlash = isset($_SESSION['groups_flash']) ? (string) $_SESSION['groups_flash'] : '';
unset($_SESSION['groups_flash']);
$todoPage = query('todo') === '1';
$receipts = isset($_SESSION['receipts'][$dir]) ? $_SESSION['receipts'][$dir] : [];
release_session();

$inlineTypes = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'mp4', 'webm', 'mp3', 'wav'];
$examFolders = exam_folders_for($level, $data);
$examNow = exam_for_viewer($level, $data);

/** Rappel de l'élève connecté par l'ENT. */
function render_student_bar($level = '')
{
    $st = current_student();
    if (!$st) return;
    ?>
    <div class="student-bar">
      <?= icon('user') ?>
      <span>Connecté : <strong><?= h(student_name($st)) ?></strong> · <?= h($st['class']) ?></span>
      <?php if ($level !== $st['class'] && level_ok($st['class'], settings())): ?><a href="<?= h(level_url($st['class'])) ?>">Ma classe</a><?php endif; ?>
      <a href="index.php?mestravaux=1">Mes travaux</a>
      <form method="post" action="index.php" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="student_logout">
        <button type="submit" class="link-btn">Se déconnecter</button>
      </form>
    </div>
    <?php
}

/** Ligne de document (aperçu intégré si possible, sinon téléchargement). */
function doc_item($level, $sub, $withPath = false, array $data = null, $classHome = '')
{
    if ($data === null) $data = settings();
    $new = is_new($sub, $data);
    $kind = file_kind($sub);
    $abs = full_path($sub, 'file');
    $preview = preview_type($sub);
    $isLink = file_ext($sub) === 'url';
    $url = $isLink && $abs ? read_url_file($abs) : '';
    $external = $isLink && $preview === null;

    if ($preview === 'quiz') $href = 'quiz.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($sub);
    elseif ($preview) $href = 'preview.php?level=' . rawurlencode($level) . '&path=' . rawurlencode($sub);
    elseif ($isLink) $href = file_url($level, $sub);
    else $href = file_url($level, $sub) . '&dl=1';

    if ($isLink) {
        $meta = $preview === 'youtube' ? 'Vidéo YouTube' : 'Lien · ' . ($url !== '' ? url_host($url) : 'adresse invalide');
    } elseif ($preview === 'quiz') {
        $qz = $abs ? quiz_load($abs) : null;
        $meta = 'QCM · ' . ($qz ? count($qz['questions']) : 0) . ' question' . ($qz && count($qz['questions']) > 1 ? 's' : '') . ' · correction automatique';
    } else {
        $meta = $kind['label'];
        if ($kind['ext'] !== '' && $preview !== 'html' && $kind['ext'] !== $kind['label']) $meta .= ' · ' . $kind['ext'];
        if ($abs) $meta .= ' · ' . human_size(filesize($abs));
    }
    if ($withPath) {
        $where = parent_dir($sub);
        if ($classHome !== '' && path_within($where, $classHome)) $where = ltrim(substr($where, strlen($classHome)), '/');
        // Ressource partagée : affichée sous le nom de sa matière.
        $map = isset($GLOBALS['tpw_subject_map']) ? $GLOBALS['tpw_subject_map'] : ['linked' => []];
        foreach ($map['linked'] as $lp => $ls) {
            if (!path_within($sub, $lp)) continue;
            $base = parent_dir($lp);
            $rest = trim(substr(parent_dir($sub), strlen($base)), '/');
            $where = $ls . ($rest !== '' ? '/' . $rest : '');
            break;
        }
        $meta .= ' · ' . ($where === '' ? 'Accueil de la classe' : str_replace('/', ' › ', $where));
    }

    if ($external) $action = 'Ouvrir le lien';
    elseif ($preview === 'html') $action = 'Ouvrir le cours';
    elseif ($preview === 'youtube') $action = 'Regarder';
    elseif ($preview === 'quiz') $action = 'Répondre';
    elseif ($preview) $action = 'Consulter';
    else $action = 'Télécharger';
    $actionIcon = $external ? 'external' : ($preview ? 'arrow' : 'download');
    $iconName = $preview === 'youtube' ? 'video' : $kind['icon'];
    ?>
    <li>
      <a class="doc" href="<?= h($href) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
        <span class="doc__icon doc__icon--<?= h($iconName) ?>"><?= icon($iconName) ?></span>
        <span class="doc__body">
          <span class="doc__title"><?= h(display_name($sub)) ?><?= $new ? ' ' . badge('Nouveau', 'new') : '' ?></span>
          <span class="doc__meta"><?= h($meta) ?></span>
        </span>
        <span class="doc__action"><span><?= $action ?></span><?= icon($actionIcon) ?></span>
      </a>
    </li>
    <?php
}

function parent_dir($path)
{
    $p = dirname($path);
    return $p === '.' ? '' : $p;
}

/**
 * Regroupement par matière côté élève.
 * Une ressource partagée hors du dossier « Classe/Matière » (ex. « SNT/TP Web » partagé à 2GT2)
 * est rangée dans la matière de l'enseignant qui la possède : l'élève la retrouve dans
 * « Mes matières › SNT » au lieu d'un second dossier « SNT ».
 * Retourne ['subjects' => [Matière => ['folder' => chemin|'', 'items' => [...]]], 'linked' => [chemin => Matière], 'other' => [...]].
 */
function student_subjects($level, array $data, $classHome)
{
    $out = ['subjects' => [], 'linked' => [], 'other' => []];
    if ($classHome !== '' && can_browse($classHome, $level, $data)) {
        foreach (names_in($classHome)['dirs'] as $d) {
            if (can_browse($d, $level, $data)) $out['subjects'][basename($d)] = ['folder' => $d, 'items' => []];
        }
    }
    // Éléments visibles les plus hauts dans l'arborescence (hors dossier de classe et banque).
    $tops = [];
    $walk = function ($rel, $depth) use (&$walk, &$tops, $level, $data, $classHome) {
        if ($depth > 12 || count($tops) > 400) return;
        $list = names_in($rel);
        foreach ($list['dirs'] as $d) {
            if ($d === $classHome || in_bank($d)) continue;
            if (rule_for($d, $level, $data)) $tops[] = $d;
            elseif (can_browse($d, $level, $data)) $walk($d, $depth + 1);
        }
        foreach ($list['files'] as $f) {
            if (!is_asset_file($f) && rule_for($f, $level, $data)) $tops[] = $f;
        }
    };
    $walk('', 0);

    $subjectNames = [];
    foreach (array_keys($out['subjects']) as $sname) $subjectNames[mb_strtolower($sname)] = $sname;
    foreach ($tops as $p) {
        $subject = resource_subject($p, $level, $subjectNames);
        if ($subject === '') { $out['other'][] = $p; continue; }
        if (isset($subjectNames[mb_strtolower($subject)])) $subject = $subjectNames[mb_strtolower($subject)];
        if (!isset($out['subjects'][$subject])) {
            $out['subjects'][$subject] = ['folder' => '', 'items' => []];
            $subjectNames[mb_strtolower($subject)] = $subject;
        }
        $out['subjects'][$subject]['items'][] = $p;
        $out['linked'][$p] = $subject;
    }
    uksort($out['subjects'], 'strnatcasecmp');
    return $out;
}

/** Matière d'une ressource : celle de l'enseignant propriétaire, sinon le nom de son dossier racine. */
function resource_subject($path, $level, array $subjectNames)
{
    static $owners = null;
    if ($owners === null) {
        $owners = [];
        foreach (users_all() as $login => $u) {
            if (trim((string) $u['subject']) === '') continue;
            foreach (user_own_roots(['login' => (string) $login] + $u) as $r) $owners[] = [$r, trim($u['subject']), $u['classes']];
        }
        usort($owners, function ($a, $b) { return strlen($b[0]) - strlen($a[0]); });
    }
    $best = '';
    foreach ($owners as $o) {
        if (!path_within($path, $o[0])) continue;
        if (in_array($level, $o[2], true)) return $o[1];
        if ($best === '') $best = $o[1];
    }
    if ($best !== '') return $best;
    $first = strtok($path, '/');
    return isset($subjectNames[mb_strtolower($first)]) ? $subjectNames[mb_strtolower($first)] : '';
}

function subject_url($level, $subject)
{
    return level_url($level) . '&matiere=' . rawurlencode($subject);
}

/** Travaux à rendre pour l'élève (dépôts ouverts, en retard autorisé ou programmés), par échéance. */
function student_todo($level, array $data)
{
    $out = [];
    foreach (deposits_config() as $path => $cfg) {
        $path = (string) $path;
        $cfg = deposit_normalize($cfg);
        if (!deposit_for_viewer($cfg, $level) || !full_path($path, 'dir') || !can_browse($path, $level, $data)) continue;
        $state = deposit_state($cfg);
        if ($state === 'closed') continue;
        $out[] = ['path' => $path, 'cfg' => $cfg, 'state' => $state, 'until' => parse_datetime($cfg['until'])];
    }
    usort($out, function ($a, $b) {
        $ua = $a['until'] === null ? PHP_INT_MAX : $a['until'];
        $ub = $b['until'] === null ? PHP_INT_MAX : $b['until'];
        return $ua === $ub ? strnatcasecmp($a['path'], $b['path']) : ($ua < $ub ? -1 : 1);
    });
    return $out;
}

/** Ligne de l'échéancier. */
function todo_item($level, array $t, $classHome)
{
    $where = parent_dir($t['path']);
    if ($classHome !== '' && path_within($where, $classHome)) $where = ltrim(substr($where, strlen($classHome)), '/');
    $cfg = $t['cfg'];
    ?>
    <li>
      <a class="todo" href="<?= h(level_url($level, $t['path'])) ?>#rendre">
        <span class="todo__icon todo__icon--<?= h($t['state']) ?>"><?= icon($t['state'] === 'scheduled' ? 'clock' : 'inbox') ?></span>
        <span class="todo__body">
          <span class="todo__title"><?= h(basename($t['path'])) ?></span>
          <span class="todo__meta"><?= h($where === '' ? 'Accueil de la classe' : str_replace('/', ' › ', $where)) ?></span>
        </span>
        <span class="todo__when">
          <?php if ($t['state'] === 'scheduled'): ?>
            <span class="badge badge--warning">Ouvre le <?= h(format_datetime($cfg['from'])) ?></span>
          <?php elseif ($t['state'] === 'late'): ?>
            <span class="badge badge--error">En retard</span><span class="muted small">Retards acceptés<?= $cfg['late_until'] !== '' ? ' jusqu’au ' . h(format_datetime($cfg['late_until'])) : '' ?></span>
          <?php elseif ($t['until'] !== null): ?>
            <strong data-countdown="<?= (int) $t['until'] ?>"><?= h(format_datetime($cfg['until'])) ?></strong>
            <span class="muted small">avant le <?= h(format_datetime($cfg['until'])) ?></span>
          <?php else: ?>
            <span class="muted small">Sans date limite</span>
          <?php endif; ?>
        </span>
      </a>
    </li>
    <?php
}

/**
 * Choix du groupe (classe avec groupes).
 *  - Élève connecté par l'ENT : groupes attribués par l'enseignant, rien à choisir.
 *  - Premier passage : grande carte « Dans quel groupe es-tu ? » directement dans la page.
 *  - Ensuite : simple rappel « Mon groupe : … · Changer ».
 */
function render_group_bar($level, array $data, $flash)
{
    $groups = class_groups($data, $level);
    if (!$groups) return;
    $mine = viewer_groups($level, $data);
    $st = current_student();
    if ($st && $st['class'] === $level) {
        if ($mine) echo '<p class="group-line">' . icon('group') . '<span>Mon groupe : <strong>' . h(implode(', ', $mine)) . '</strong></span></p>';
        return;
    }
    $asked = isset($_SESSION['groups'][$level]);
    $back = isset($_SERVER['REQUEST_URI']) ? basename((string) $_SERVER['REQUEST_URI']) : '';
    if (strpos($back, 'index.php?') !== 0) $back = level_url($level);
    $form = function ($big) use ($groups, $mine, $level, $back, $flash) {
        ?>
        <form method="post" class="form form--tight" action="<?= h($back) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_groups">
          <input type="hidden" name="level" value="<?= h($level) ?>">
          <input type="hidden" name="back" value="<?= h($back) ?>">
          <?php if ($flash !== '') alert('error', $flash); ?>
          <div class="pills<?= $big ? ' pills--big' : '' ?>">
            <?php foreach ($groups as $i => $g): $on = in_array($g['name'], $mine, true); ?>
              <span class="group-choice">
                <label class="pill"><input type="checkbox" name="groups[]" value="<?= h($g['name']) ?>"<?= $on ? ' checked' : '' ?><?= $g['hash'] !== '' && !$on ? ' data-needs-code="gc-' . $i . '"' : '' ?>><span><?= $g['hash'] !== '' ? icon('lock') : '' ?><?= h($g['name']) ?></span></label>
                <?php if ($g['hash'] !== '' && !$on): ?>
                  <input id="gc-<?= $i ?>" class="input input--code input--mini" type="password" name="gcode[<?= $i ?>]" maxlength="4" placeholder="Code" autocomplete="off" hidden aria-label="Code du groupe <?= h($g['name']) ?>">
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
          <div class="form__actions">
            <button class="btn<?= $big ? '' : ' btn--secondary btn--sm' ?>" type="submit">Valider</button>
            <?php if ($big): ?><button class="btn btn--tertiary" type="submit" name="none" value="1">Je ne suis dans aucun de ces groupes</button><?php endif; ?>
          </div>
        </form>
        <?php
    };
    if (!$asked || $flash !== '') {
        ?>
        <section class="group-ask" aria-labelledby="group-ask-title">
          <div class="group-ask__head"><?= icon('group') ?><div>
            <h2 class="h4" id="group-ask-title">Dans quel groupe es-tu ?</h2>
            <p class="muted small">Ton professeur publie certaines ressources pour un groupe seulement. Coche ton ou tes groupes (une seule fois, jusqu’à la fermeture du navigateur).</p>
          </div></div>
          <?php $form(true); ?>
        </section>
        <?php
        return;
    }
    ?>
    <details class="group-bar">
      <summary>
        <?= icon('group') ?>
        <span><?= $mine ? 'Mon groupe : <strong>' . h(implode(', ', $mine)) . '</strong>' : 'Aucun groupe choisi' ?></span>
        <span class="group-bar__edit">Changer</span>
      </summary>
      <div class="group-bar__body"><?php $form(false); ?></div>
    </details>
    <?php
}

$classHome = class_home_folder($level);
// Regroupement par matière (désactivé pendant un examen : seul le dossier de l'épreuve compte).
$subjectMap = $examFolders === null ? student_subjects($level, $data, $classHome) : ['subjects' => [], 'linked' => [], 'other' => []];
$GLOBALS['tpw_subject_map'] = $subjectMap;
$matiere = query('matiere');
if ($matiere !== '' && !isset($subjectMap['subjects'][$matiere])) {
    fail(404, 'Matière introuvable', 'Aucune ressource de cette matière n’est publiée pour votre classe.');
}
foreach ($subjectMap['subjects'] as $sname => $sinfo) {
    if ($dir !== '' && $sinfo['folder'] === $dir) $matiere = $sname; // dossier « Classe/Matière » = page de la matière
}
if ($matiere !== '' && $dir === '') $dir = $subjectMap['subjects'][$matiere]['folder'];
// Ressource partagée rangée dans une matière : racine du lien.
$linkRoot = '';
foreach ($subjectMap['linked'] as $lp => $ls) {
    if ($dir !== '' && path_within($dir, $lp)) { $linkRoot = $lp; break; }
}

$crumbs = class_crumbs($level, $data);
if ($matiere !== '') {
    $crumbs[] = [$matiere, null];
} elseif ($linkRoot !== '') {
    $crumbs[] = [$subjectMap['linked'][$linkRoot], subject_url($level, $subjectMap['linked'][$linkRoot])];
    $walk = parent_dir($linkRoot);
    foreach (explode('/', substr($dir, strlen($walk === '' ? '' : $walk . '/'))) as $part) {
        $walk = $walk === '' ? $part : $walk . '/' . $part;
        $crumbs[] = [$part, level_url($level, $walk)];
    }
} elseif ($dir !== '') {
    $walk = '';
    foreach (explode('/', $dir) as $part) {
        $walk = $walk === '' ? $part : $walk . '/' . $part;
        if ($walk === $classHome) continue; // le dossier de classe = la page de la classe
        foreach ($subjectMap['subjects'] as $sname => $sinfo) {
            if ($sinfo['folder'] === $walk) { $crumbs[] = [$sname, subject_url($level, $sname)]; continue 2; }
        }
        $crumbs[] = [$part, level_url($level, $walk)];
    }
}
if ($q !== '') $crumbs[] = ['Recherche', null];
if ($todoPage) $crumbs[] = ['À rendre', null];

page_top(($todoPage ? 'À rendre' : ($q !== '' ? 'Recherche' : ($matiere !== '' ? $matiere : ($dir === '' ? 'Ressources' : basename($dir))))) . ' — ' . $level);
?>
<div class="container section">
  <?php breadcrumb($crumbs); ?>

  <?php if ($examFolders !== null): ?>
    <div class="exam-banner" role="status">
      <?= icon('exam') ?>
      <div>
        <p class="exam-banner__title">Examen en cours<?php if ($examNow): ?> · fin à <?= h(date('H:i', $examNow['end_ts'])) ?> <span class="exam-banner__timer" data-countdown="<?= (int) $examNow['end_ts'] ?>" data-countdown-short></span><?php endif; ?></p>
        <p>Seules les ressources de l’épreuve sont accessibles.
          <?php foreach ($examFolders as $f): ?>
            <a href="<?= h(level_url($level, $f)) ?>">Ouvrir « <?= h(basename($f)) ?> »</a>
          <?php endforeach; ?>
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php $announcements = active_announcements($data, $level); if ($announcements): ?>
    <div class="announcements" aria-label="Annonces">
      <?php foreach ($announcements as $a): ?>
        <article class="announcement announcement--<?= h($a['type']) ?>">
          <?= icon($a['type'] === 'important' ? 'alert' : 'megaphone') ?>
          <div class="announcement__body">
            <p class="announcement__title"><?= h($a['title']) ?></p>
            <?php if ($a['text'] !== ''): ?><p><?= nl2br(h($a['text'])) ?></p><?php endif; ?>
            <p class="announcement__meta">Publiée le <?= h(format_datetime($a['created'])) ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php render_student_bar($level); ?>
  <?php render_group_bar($level, $data, $groupFlash); ?>
  <?php
  // Relances de l'enseignant (élève connecté, travail non rendu).
  $meNow = current_student();
  if ($meNow && $meNow['class'] === $level) {
      foreach (deposits_config() as $rp => $rc) {
          $rc = deposit_normalize($rc);
          if (!isset($rc['reminders'][$meNow['id']]) || !deposit_for_viewer($rc, $level) || !deposit_accepting($rc)) continue;
          if (deposit_student_status($meNow, deposit_submitters(deposit_entries($rc))) !== null) continue;
          echo '<div class="alert alert--warning" role="status">' . icon('megaphone') . '<div><p class="alert__title">Rappel de ton professeur</p><p>« ' . h(basename((string) $rp)) . ' » n’a pas encore été rendu'
              . ($rc['until'] !== '' ? ' (date limite : ' . h(format_datetime($rc['until'])) . ')' : '') . '. <a href="' . h(level_url($level, (string) $rp)) . '#rendre">Rendre mon travail</a></p></div></div>';
      }
  }
  ?>

  <div class="page-head">
    <div>
      <p class="page-head__kicker"><?= badge($level, 'info') ?><?php foreach (viewer_groups($level, $data) as $vg) echo ' ' . badge($vg, ''); ?></p>
      <h1><?= $todoPage ? 'À rendre' : ($q !== '' ? 'Résultats de recherche' : h($matiere !== '' ? $matiere : ($dir === '' ? 'Ressources de la classe' : basename($dir)))) ?></h1>
    </div>
    <form class="search" method="get" action="index.php" role="search">
      <input type="hidden" name="level" value="<?= h($level) ?>">
      <label for="q" class="sr-only">Rechercher un document</label>
      <input id="q" class="input search__input" type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher un cours, un TP…" maxlength="100">
      <button class="btn search__btn" type="submit" title="Rechercher"><?= icon('search') ?><span class="sr-only">Rechercher</span></button>
    </form>
  </div>

  <?php
  if ($todoPage) {
      /* ----- Échéancier ----- */
      $todo = student_todo($level, $data);
      ?>
      <div class="toolbar">
        <p class="muted"><?= count($todo) ?> travail<?= count($todo) > 1 ? 'x' : '' ?> à rendre ou à venir</p>
        <a class="btn btn--secondary btn--sm" href="<?= h(level_url($level)) ?>"><?= icon('back') ?>Ressources de la classe</a>
      </div>
      <?php if (!$todo): ?>
        <div class="empty"><?= icon('check') ?><p><strong>Rien à rendre pour le moment.</strong></p><p class="muted">Les dépôts ouverts par vos professeurs apparaîtront ici avec leur date limite.</p></div>
      <?php else: ?>
        <ul class="todo-list"><?php foreach ($todo as $t) todo_item($level, $t, $classHome); ?></ul>
      <?php endif; ?>
      <?php
  } elseif ($q !== '') {
      /* ----- Recherche ----- */
      $terms = array_filter(explode(' ', fold_text($q)));
      $dirs = [];
      $docs = [];
      foreach (all_items() as $path => $type) {
          $path = (string) $path;
          if ($path === '') continue;
          $hay = fold_text(basename($path));
          $match = true;
          foreach ($terms as $t) if (strpos($hay, $t) === false) { $match = false; break; }
          if (!$match) continue;
          if ($type === 'dir' && can_browse($path, $level, $data)) $dirs[] = $path;
          elseif ($type === 'file' && !is_asset_file($path) && rule_for($path, $level, $data)) $docs[] = $path;
          if (count($dirs) + count($docs) >= 100) break;
      }
      ?>
      <p class="muted"><?= count($dirs) + count($docs) ?> résultat<?= count($dirs) + count($docs) > 1 ? 's' : '' ?> pour « <?= h($q) ?> » · <a href="<?= h(level_url($level)) ?>">Effacer la recherche</a></p>
      <?php if (!$dirs && !$docs): ?>
        <div class="empty"><?= icon('search') ?><p><strong>Aucun résultat.</strong></p><p class="muted">Vérifiez l’orthographe ou essayez un autre mot.</p></div>
      <?php endif; ?>
      <?php if ($dirs): ?>
        <section class="block">
          <h2 class="h4">Dossiers</h2>
          <ul class="tiles">
            <?php foreach ($dirs as $sub): ?>
              <li><a class="tile" href="<?= h(level_url($level, $sub)) ?>">
                <span class="tile__icon"><?= icon('folder') ?></span>
                <span class="tile__title"><?= h(basename($sub)) ?></span>
                <span class="tile__meta"><?= h(parent_dir($sub) === '' ? 'Racine' : parent_dir($sub)) ?></span>
                <span class="tile__arrow"><?= icon('arrow') ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
      <?php if ($docs): ?>
        <section class="block">
          <h2 class="h4">Documents</h2>
          <ul class="doc-list"><?php foreach ($docs as $sub) doc_item($level, $sub, true, $data, $classHome); ?></ul>
        </section>
      <?php endif; ?>
      <?php
  } else {
      /* ----- Contenu du dossier ----- */
      $visibleIn = function ($rel) use ($level, $data) {
          $listing = names_in($rel);
          return [
              array_values(array_filter($listing['dirs'], function ($sub) use ($level, $data) { return can_browse($sub, $level, $data); })),
              array_values(array_filter($listing['files'], function ($sub) use ($level, $data) { return !is_asset_file($sub) && rule_for($sub, $level, $data); })),
          ];
      };
      $subjects = [];
      $recent = [];
      $cfg = $dir !== '' ? deposit_for($dir) : null;
      $showDeposit = deposit_for_viewer($cfg, $level);

      if ($matiere !== '') {
          // Page d'une matière : dossier « Classe/Matière » + ressources partagées par l'enseignant.
          $folders = [];
          $files = [];
          if ($dir !== '') list($folders, $files) = $visibleIn($dir);
          foreach ($subjectMap['subjects'][$matiere]['items'] as $it) {
              if (full_path($it, 'dir')) $folders[] = $it; else $files[] = $it;
          }
          $parent = '';
      } elseif ($dir === '') {
          // Accueil de la classe : matières, autres ressources, dernières publications.
          $subjects = array_keys($subjectMap['subjects']);
          $folders = [];
          $files = [];
          if ($examFolders !== null) {
              list($folders, $files) = $visibleIn('');
          } else {
              foreach ($subjectMap['other'] as $it) {
                  if (full_path($it, 'dir')) $folders[] = $it; else $files[] = $it;
              }
              if ($classHome !== '' && can_browse($classHome, $level, $data)) {
                  foreach (names_in($classHome)['files'] as $f) if (!is_asset_file($f) && rule_for($f, $level, $data)) $files[] = $f;
              }
          }
          $parent = null;
          $limit = time() - 14 * 86400;
          foreach (all_items() as $p => $t) {
              $p = (string) $p;
              if ($t !== 'file' || is_asset_file($p) || !rule_for($p, $level, $data)) continue;
              $nt = news_time($p, $data);
              if ($nt >= $limit) $recent[$p] = $nt;
          }
          arsort($recent);
          $recent = array_slice(array_keys($recent), 0, 5);
      } else {
          list($folders, $files) = $visibleIn($dir);
          $parent = parent_dir($dir);
          if ($parent === $classHome) $parent = '';
      }
      // Retour : vers la matière quand on remonte d'un dossier de matière ou d'une ressource partagée.
      $parentUrl = null;
      if ($parent !== null) {
          $parentUrl = level_url($level, $parent);
          if ($linkRoot !== '' && $dir === $linkRoot) $parentUrl = subject_url($level, $subjectMap['linked'][$linkRoot]);
          foreach ($subjectMap['subjects'] as $sname => $sinfo) {
              if ($sinfo['folder'] !== '' && $sinfo['folder'] === $parent) $parentUrl = subject_url($level, $sname);
          }
          if ($matiere !== '') $parentUrl = level_url($level);
      }
      ?>
      <div class="toolbar">
        <p class="muted">
          <?php
          $summary = [];
          if ($subjects) $summary[] = count($subjects) . ' matière' . (count($subjects) > 1 ? 's' : '');
          if ($folders) $summary[] = count($folders) . ' dossier' . (count($folders) > 1 ? 's' : '');
          if ($files) $summary[] = count($files) . ' document' . (count($files) > 1 ? 's' : '');
          echo h(implode(' · ', $summary));
          ?>
        </p>
        <?php if ($parentUrl !== null): ?>
          <a class="btn btn--secondary btn--sm" href="<?= h($parentUrl) ?>"><?= icon('back') ?><?= $matiere !== '' ? 'Toutes mes matières' : 'Dossier parent' ?></a>
        <?php else: ?>
          <a class="btn btn--secondary btn--sm" href="<?= h(change_class_url($level, $data)) ?>"><?= icon('back') ?>Changer de classe</a>
        <?php endif; ?>
      </div>

      <?php if (!$folders && !$files && !$showDeposit && !$subjects): ?>
        <div class="empty">
          <?= icon('folder') ?>
          <p><strong>Aucune ressource disponible pour le moment.</strong></p>
          <p class="muted">Vos professeurs n’ont encore rien publié ici pour la classe <?= h($level) ?>.</p>
        </div>
      <?php endif; ?>

      <?php if ($showDeposit) render_deposit_box($cfg, $level, $dir, $depositFlash, $receipts); ?>

      <?php if ($dir === ''): $todo = student_todo($level, $data); if ($todo): ?>
        <section class="block todo-block">
          <div class="todo-block__head">
            <h2 class="h4"><?= icon('inbox') ?> À rendre</h2>
            <a class="link-more" href="<?= h(level_url($level) . '&todo=1') ?>">Tout voir (<?= count($todo) ?>)<?= icon('arrow') ?></a>
          </div>
          <ul class="todo-list"><?php foreach (array_slice($todo, 0, 3) as $t) todo_item($level, $t, $classHome); ?></ul>
        </section>
      <?php endif; endif; ?>

      <?php if ($recent): ?>
        <section class="block recent">
          <h2 class="h4"><?= icon('clock') ?> Dernières publications</h2>
          <ul class="doc-list"><?php foreach ($recent as $sub) doc_item($level, $sub, true, $data, $classHome); ?></ul>
        </section>
      <?php endif; ?>

      <?php if ($subjects): ?>
        <section class="block">
          <h2 class="h4">Mes matières</h2>
          <ul class="tiles tiles--subjects">
            <?php foreach ($subjects as $sname):
                $sinfo = $subjectMap['subjects'][$sname];
                $isNewSubject = ($sinfo['folder'] !== '' && is_new($sinfo['folder'], $data));
                foreach ($sinfo['items'] as $it) if (is_new($it, $data)) $isNewSubject = true;
                $hasWork = false;
                foreach (array_merge($sinfo['folder'] !== '' ? [$sinfo['folder']] : [], $sinfo['items']) as $it) {
                    $dc = deposit_for($it);
                    if (deposit_for_viewer($dc, $level) && deposit_accepting($dc)) $hasWork = true;
                } ?>
              <li>
                <a class="tile tile--subject" href="<?= h(subject_url($level, $sname)) ?>">
                  <span class="tile__icon"><?= icon('library') ?></span>
                  <span class="tile__title"><?= h($sname) ?></span>
                  <span class="tile__meta"><?= $hasWork ? icon('inbox') . 'Travail à rendre' : 'Ouvrir' ?><?= $isNewSubject ? ' ' . badge('Nouveau', 'new') : '' ?></span>
                  <span class="tile__arrow"><?= icon('arrow') ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($folders): ?>
        <section class="block">
          <h2 class="h4"><?= $subjects ? 'Autres ressources' : 'Dossiers' ?></h2>
          <ul class="tiles">
            <?php foreach ($folders as $sub):
                $dcfg = deposit_for($sub);
                $hasDeposit = deposit_for_viewer($dcfg, $level) && deposit_accepting($dcfg); ?>
              <li>
                <a class="tile" href="<?= h(level_url($level, $sub)) ?>">
                  <span class="tile__icon"><?= icon('folder') ?></span>
                  <span class="tile__title"><?= h(basename($sub)) ?></span>
                  <span class="tile__meta"><?= $hasDeposit ? icon('inbox') . 'Travail à rendre' : 'Ouvrir le dossier' ?><?= is_new($sub, $data) ? ' ' . badge('Nouveau', 'new') : '' ?></span>
                  <span class="tile__arrow"><?= icon('arrow') ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($files): ?>
        <section class="block">
          <h2 class="h4">Documents</h2>
          <ul class="doc-list"><?php foreach ($files as $sub) doc_item($level, $sub, false, $data); ?></ul>
        </section>
      <?php endif; ?>
      <?php
  }
  ?>
</div>
<?php
page_end();

/** Encadré « Rendre mon travail ». */
function render_deposit_box(array $cfg, $level, $dir, $flash, array $receipts)
{
    $state = deposit_state($cfg);
    $exts = deposit_exts($cfg);
    $until = parse_datetime($cfg['until']);
    ?>
    <section class="deposit<?= $state === 'late' ? ' deposit--late' : '' ?>" id="rendre">
      <div class="deposit__head">
        <span class="deposit__icon"><?= icon('inbox') ?></span>
        <div>
          <h2 class="h3">Rendre mon travail</h2>
          <p class="muted">
            <?php if ($state === 'scheduled'): ?>Le dépôt ouvrira le <?= h(format_datetime($cfg['from'])) ?>.
            <?php elseif ($state === 'closed'): ?>Le dépôt est fermé depuis le <?= h(format_datetime($cfg['late'] === 'accept' && $cfg['late_until'] !== '' ? $cfg['late_until'] : $cfg['until'])) ?>.
            <?php elseif ($state === 'late'): ?><strong class="text-danger">Date limite dépassée</strong> (<?= h(format_datetime($cfg['until'])) ?>) : votre travail sera marqué « en retard »<?= $cfg['late_until'] !== '' ? ', dépôt possible jusqu’au ' . h(format_datetime($cfg['late_until'])) : '' ?>.
            <?php elseif ($until !== null): ?>À déposer avant le <strong><?= h(format_datetime($cfg['until'])) ?></strong> · <span data-countdown="<?= (int) $until ?>"></span>
            <?php else: ?>Dépôt ouvert.<?php endif; ?>
          </p>
        </div>
      </div>

      <?php if ($cfg['note'] !== ''): ?>
        <div class="deposit__note"><?= nl2br(h($cfg['note'])) ?></div>
      <?php endif; ?>

      <?php
      if ($flash) {
          if ($flash['ok']) {
              ?>
              <div class="receipt" data-receipt>
                <div class="receipt__head"><?= icon('check') ?><div>
                  <p class="receipt__title"><?= count($flash['ok']) > 1 ? 'Vos fichiers ont bien été déposés' : 'Votre fichier a bien été déposé' ?><?= !empty($flash['late']) ? ' (en retard)' : '' ?></p>
                  <p class="muted small"><?= h(implode(', ', $flash['ok'])) ?></p>
                </div></div>
                <p class="receipt__code">Reçu n° <strong><?= h($flash['receipt']) ?></strong></p>
                <p class="muted small">Notez ce numéro ou imprimez ce reçu : il prouve que votre travail a été rendu le <?= h(date('d/m/Y à H:i')) ?>.</p>
                <button type="button" class="btn btn--tertiary btn--sm" data-print><?= icon('printer') ?>Imprimer le reçu</button>
                <a class="btn btn--tertiary btn--sm" href="index.php?mestravaux=1&amp;recu=<?= h(rawurlencode($flash['receipt'])) ?>"><?= icon('search') ?>Suivre ce dépôt</a>
              </div>
              <?php
          }
          if ($flash['errors']) alert('error', implode(' ', $flash['errors']), 'Dépôt incomplet');
      }
      ?>

      <?php if (deposit_accepting($cfg)): ?>
        <form method="post" enctype="multipart/form-data" class="form" action="<?= h(level_url($level, $dir)) ?>" data-dropzone>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="deposit">
          <input type="hidden" name="level" value="<?= h($level) ?>">
          <input type="hidden" name="dir" value="<?= h($dir) ?>">
          <?php $me = current_student(); if ($me && $me['class'] === $level): ?>
            <p class="student-identity"><?= icon('user') ?><span>Travail déposé au nom de <strong><?= h(student_name($me)) ?></strong></span></p>
            <input type="hidden" name="last" value="<?= h($me['last']) ?>"><input type="hidden" name="first" value="<?= h($me['first']) ?>">
          <?php else: ?>
          <div class="field-row">
            <div class="field">
              <label for="dep-last">Nom</label>
              <input id="dep-last" class="input" type="text" name="last" required maxlength="60" autocomplete="family-name" value="<?= h($flash ? $flash['last'] : '') ?>">
            </div>
            <div class="field">
              <label for="dep-first">Prénom</label>
              <input id="dep-first" class="input" type="text" name="first" required maxlength="60" autocomplete="given-name" value="<?= h($flash ? $flash['first'] : '') ?>">
            </div>
          </div>
          <?php endif; ?>
          <?php if ($cfg['group_max'] > 1): ?>
            <details class="schedule teammates"<?= $flash && !empty($flash['members']) ? ' open' : '' ?>>
              <summary><?= icon('group') ?>Travail en <?= $cfg['group_max'] === 2 ? 'binôme' : 'groupe' ?> ? Ajoutez vos coéquipiers <span class="muted small">(jusqu’à <?= $cfg['group_max'] - 1 ?>)</span></summary>
              <?php for ($i = 0; $i < $cfg['group_max'] - 1; $i++): $m = $flash && isset($flash['members'][$i]) ? $flash['members'][$i] : ['last' => '', 'first' => '']; ?>
                <div class="field-row">
                  <div class="field"><label for="m-last-<?= $i ?>">Nom du coéquipier <?= $i + 1 ?></label><input id="m-last-<?= $i ?>" class="input" type="text" name="m_last[]" maxlength="60" value="<?= h($m['last']) ?>"></div>
                  <div class="field"><label for="m-first-<?= $i ?>">Prénom</label><input id="m-first-<?= $i ?>" class="input" type="text" name="m_first[]" maxlength="60" value="<?= h($m['first']) ?>"></div>
                </div>
              <?php endfor; ?>
              <p class="muted small">Un seul membre du groupe dépose : le travail est enregistré pour tous.</p>
            </details>
          <?php endif; ?>
          <label class="dropzone">
            <input type="file" name="files[]" multiple required<?= $exts ? ' accept="' . h(implode(',', array_map(function ($e) { return '.' . $e; }, $exts))) . '"' : '' ?>>
            <?= icon('upload') ?>
            <strong>Déposer mon fichier ici</strong>
            <span>ou cliquer pour parcourir — <?= $exts ? h(strtoupper(implode(', ', $exts))) . ' · ' : '' ?><?= h(human_size(deposit_max_bytes($cfg))) ?> maximum par fichier</span>
            <span class="dropzone__list" data-file-list></span>
          </label>
          <p class="deposit__privacy"><?= icon('info') ?><span>Votre nom, votre classe et vos fichiers sont transmis uniquement à votre professeur. Ils sont conservés pendant l’année scolaire, puis archivés un an avant d’être supprimés. <a href="legal.php" target="_blank" rel="noopener">En savoir plus sur vos données</a></span></p>
          <div class="form__actions">
            <button class="btn" type="submit"><?= icon('upload') ?>Envoyer mon travail</button>
            <span class="muted small">Vous pouvez déposer une nouvelle version : votre professeur verra toutes vos versions.</span>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($receipts): ?>
        <div class="receipts">
          <p class="receipts__title">Vos dépôts depuis ce poste</p>
          <ul>
            <?php foreach (array_reverse($receipts) as $r): ?>
              <li><?= icon('check') ?><span><strong><?= h(implode(', ', $r['files'])) ?></strong> — <?= h($r['name']) ?>, le <?= h(date('d/m/Y à H:i', $r['time'])) ?><?= !empty($r['receipt']) ? ' · reçu <code>' . h($r['receipt']) . '</code>' : '' ?><?= !empty($r['late']) ? ' ' . badge('En retard', 'warning') : '' ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>
    <?php
}
