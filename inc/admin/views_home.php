<?php
/**
 * Tableau de bord : page d'accueil de l'espace enseignant (version administrateur plus complète).
 */

function view_home(array $data, array $user)
{
    $admin = is_admin($user);
    $now = time();
    $week = $now - 7 * 86400;

    // Dépôts que je gère.
    $deps = [];
    if (can('deposits', $user)) {
        foreach (deposits_config() as $p => $cfg) {
            $p = (string) $p;
            if (!is_array($cfg) || empty($cfg['enabled']) || !can_manage($p, $user) || !full_path($p, 'dir')) continue;
            $cfg = deposit_normalize($cfg);
            $state = deposit_state($cfg);
            if (!in_array($state, ['open', 'late', 'scheduled'], true)) {
                $until = parse_datetime($cfg['until']);
                if (!$until || $until < $now - 7 * 86400) continue; // fermé depuis plus d'une semaine
            }
            $entries = deposit_entries($cfg);
            $roster = deposit_roster($cfg);
            $deps[] = ['path' => $p, 'cfg' => $cfg, 'state' => $state, 'n' => deposit_submitter_count($entries), 'roster' => count($roster),
                       'new' => count(array_filter($entries, function ($e) use ($week) { return $e['time'] >= $week; })),
                       'until' => parse_datetime($cfg['until'])];
        }
        usort($deps, function ($a, $b) { return ($a['until'] ?: PHP_INT_MAX) - ($b['until'] ?: PHP_INT_MAX); });
    }

    // Nouveautés de la semaine dans mes dossiers.
    $recent = [];
    foreach (all_items() as $p => $t) {
        $p = (string) $p;
        if ($t !== 'file' || !can_manage($p, $user)) continue;
        $abs = full_path($p, 'file');
        if ($abs && ($m = filemtime($abs)) >= $week) $recent[$p] = $m;
    }
    arsort($recent);
    $recentCount = count($recent);
    $recent = array_slice($recent, 0, 8, true);

    // Consultations (12 mois) de mes contenus.
    $st = stats_for('');
    $top = [];
    foreach ($st['by_item'] as $p => $n) {
        if (can_manage((string) $p, $user)) $top[(string) $p] = $n;
        if (count($top) >= 6) break;
    }
    $requests = can('students', $user) ? count(student_requests_for($user)) : 0;
    $exams = active_exams($data);
    ?>
    <div class="page-head">
      <div>
        <h1>Bonjour <?= h(preg_replace('/\s.*$/u', '', $user['name']) ?: $user['name']) ?></h1>
        <p class="muted"><?= h(dash_date($now)) ?> · votre tableau de bord</p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--secondary btn--sm" href="<?= h(admin_url('library')) ?>"><?= icon('library') ?>Bibliothèque</a>
      </div>
    </div>

    <div class="dash-kpis">
      <a class="dash-kpi" href="<?= h(admin_url('deposits')) ?>"><span class="dash-kpi__n"><?= count(array_filter($deps, function ($d) { return in_array($d['state'], ['open', 'late'], true); })) ?></span><span>dépôt(s) ouvert(s)</span></a>
      <a class="dash-kpi" href="<?= h(admin_url('deposits')) ?>"><span class="dash-kpi__n"><?= array_sum(array_column($deps, 'new')) ?></span><span>rendu(s) cette semaine</span></a>
      <a class="dash-kpi" href="<?= h(admin_url('library')) ?>"><span class="dash-kpi__n"><?= $recentCount ?></span><span>document(s) ajouté(s) ou modifié(s) en 7 jours</span></a>
      <span class="dash-kpi"><span class="dash-kpi__n"><?= (int) $st['recent'] ?></span><span>consultation(s) élèves en 7 jours</span></span>
    </div>

    <?php
    $todo = [];
    if ($requests) $todo[] = [admin_url('students') . '#demandes', $requests . ' demande(s) d’accès d’élèves à traiter'];
    if ($admin && ($pu = count(pending_users()))) $todo[] = [admin_url('users'), $pu . ' compte(s) enseignant à valider'];
    if ($admin && ($nf = feedback_new_count())) $todo[] = [admin_url('feedback'), $nf . ' nouveau(x) signalement(s)'];
    if ($exams && can('exams', $user)) $todo[] = [admin_url('exams'), count($exams) . ' mode(s) examen en cours'];
    if ($todo): ?>
      <section class="card dash-todo">
        <h2 class="h4"><?= icon('bell') ?> À traiter</h2>
        <ul class="dash-list"><?php foreach ($todo as $t): ?><li><a href="<?= h($t[0]) ?>"><?= h($t[1]) ?></a></li><?php endforeach; ?></ul>
      </section>
    <?php endif; ?>

    <div class="dash-grid">
      <?php if (can('deposits', $user)): ?>
      <section class="card">
        <h2 class="h4"><?= icon('inbox') ?> Dépôts en cours</h2>
        <?php if (!$deps): ?><p class="muted small">Aucun dépôt ouvert. Activez un dépôt depuis un dossier de la bibliothèque.</p><?php else: ?>
          <ul class="dash-list">
            <?php foreach (array_slice($deps, 0, 8) as $d): $pct = $d['roster'] ? min(100, round(100 * $d['n'] / $d['roster'])) : null; ?>
              <li>
                <a href="<?= h(admin_url('deposits', $d['path'])) ?>"><strong><?= h(basename($d['path'])) ?></strong></a> <?= deposit_state_badge($d['cfg']) ?>
                <span class="muted small"><?= h(implode(', ', $d['cfg']['levels'])) ?><?= $d['until'] ? ' · ' . h(format_datetime($d['cfg']['until'])) : '' ?></span>
                <span class="dash-bar" title="<?= $d['n'] ?> rendu(s)<?= $d['roster'] ? ' sur ' . $d['roster'] : '' ?>">
                  <?php if ($pct !== null): ?><span class="dash-bar__fill" style="width:<?= $pct ?>%"></span><?php endif; ?>
                  <span class="dash-bar__txt"><?= $d['n'] ?><?= $d['roster'] ? ' / ' . $d['roster'] : '' ?> élève(s)<?= $d['new'] ? ' · +' . $d['new'] . ' cette semaine' : '' ?></span>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="card">
        <h2 class="h4"><?= icon('clock') ?> Nouveautés de la semaine</h2>
        <?php if (!$recent): ?><p class="muted small">Rien de nouveau dans vos dossiers depuis 7 jours.</p><?php else: ?>
          <ul class="dash-list">
            <?php foreach ($recent as $p => $m): ?>
              <li><a href="<?= h(admin_url('library', $p)) ?>"><?= h(display_name($p)) ?></a> <span class="muted small"><?= h(str_replace('/', ' › ', dirname($p))) ?> · <?= h(date('d/m H:i', $m)) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section class="card">
        <h2 class="h4"><?= icon('chart') ?> Les plus consultés</h2>
        <?php if (!$top): ?><p class="muted small">Pas encore de consultation enregistrée.</p><?php else: $max = max($top); ?>
          <ul class="dash-list">
            <?php foreach ($top as $p => $n): ?>
              <li><a href="<?= h(admin_url('library', $p)) ?>"><?= h(display_name($p)) ?></a>
                <span class="dash-bar"><span class="dash-bar__fill" style="width:<?= round(100 * $n / $max) ?>%"></span><span class="dash-bar__txt"><?= $n ?> vue(s)</span></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <?php if ($admin):
          $free = @disk_free_space(TPW_DATA);
          $total = @disk_total_space(TPW_DATA);
          $backups = function_exists('backup_list') ? array_slice(backup_list(), 0, 4) : [];
          $ab = function_exists('autobackup_state') ? autobackup_state() : []; ?>
      <section class="card">
        <h2 class="h4"><?= icon('disk') ?> Serveur</h2>
        <?php if ($free !== false && $total): $used = round(100 * (1 - $free / $total)); ?>
          <p class="small">Espace disque : <strong><?= h(human_size($free)) ?></strong> libres sur <?= h(human_size($total)) ?></p>
          <span class="dash-bar<?= $used > 90 ? ' dash-bar--warn' : '' ?>"><span class="dash-bar__fill" style="width:<?= $used ?>%"></span><span class="dash-bar__txt"><?= $used ?> % utilisés</span></span>
        <?php endif; ?>
        <p class="small">Dernières sauvegardes :</p>
        <?php if (!$backups): ?><p class="muted small"><?= icon('alert') ?> Aucune sauvegarde. <a href="<?= h(admin_url('backups')) ?>">En créer une</a></p><?php else: ?>
          <ul class="dash-list dash-list--compact">
            <?php foreach ($backups as $b): ?><li><?= h(date('d/m/Y H:i', $b['time'])) ?> · <?= h(backup_type_label($b['type'])) ?> · <span class="muted"><?= h(human_size($b['size'])) ?></span></li><?php endforeach; ?>
          </ul>
          <?php if ($backups[0]['time'] < $now - 14 * 86400) alert('warning', 'La dernière sauvegarde date de plus de deux semaines.'); ?>
        <?php endif; ?>
        <p class="muted small">TPWeb <?= h(TPW_VERSION) ?> · PHP <?= h(PHP_VERSION) ?><?= mail_ready() ? ' · courriels activés' : '' ?></p>
      </section>
      <?php endif; ?>
    </div>
    <?php
}

function dash_date($ts)
{
    $d = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $m = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return ucfirst($d[(int) date('w', $ts)]) . ' ' . (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}
