<?php
/**
 * Notifications par courriel aux enseignants (SMTP minimal, sans dépendance).
 *  - nouveau rendu (immédiat ou résumé quotidien), dépôt fermé (bilan), nouveau signalement (admin) ;
 *  - envois mis en file d'attente puis expédiés après la page (et par cron.php), avec nouvel essai en cas d'échec.
 * Le mot de passe SMTP est rangé dans secrets.json (jamais dans settings.json ni dans les sauvegardes de réglages).
 */

function mail_config()
{
    $d = settings();
    $c = isset($d['mail']) && is_array($d['mail']) ? $d['mail'] : [];
    return $c + ['enabled' => false, 'transport' => 'smtp', 'host' => '', 'port' => 587, 'secure' => 'tls',
                 'user' => '', 'from' => '', 'from_name' => 'TPWeb', 'base' => ''];
}

function mail_ready()
{
    $c = mail_config();
    if (empty($c['enabled']) || !filter_var($c['from'], FILTER_VALIDATE_EMAIL)) return false;
    return $c['transport'] === 'php' || $c['host'] !== '';
}

/** Préférences de notification d'un compte enseignant. */
function mail_prefs(array $u)
{
    $p = isset($u['notify']) && is_array($u['notify']) ? $u['notify'] : [];
    return $p + ['submit' => 'daily', 'closed' => true, 'feedback' => true];
}

function user_email(array $u)
{
    return isset($u['email']) && filter_var($u['email'], FILTER_VALIDATE_EMAIL) ? (string) $u['email'] : '';
}

/** Adresse publique du portail (pour les liens des courriels, y compris depuis cron.php). */
function mail_base_url()
{
    $c = mail_config();
    if ($c['base'] !== '') return rtrim($c['base'], '/');
    $f = TPW_STATE . '/base_url.txt';
    if (PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
              || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $_SERVER['HTTP_HOST']);
        $dir = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
        $url = ($https ? 'https' : 'http') . '://' . $host . $dir;
        if (@file_get_contents($f) !== $url) @file_put_contents($f, $url, LOCK_EX);
        return $url;
    }
    $saved = @file_get_contents($f);
    return $saved ? trim($saved) : '';
}

/* ---------- Envoi SMTP ---------- */

function smtp_read($fp)
{
    $out = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $out .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return $out;
}

function smtp_cmd($fp, $cmd, $expect)
{
    if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
    $r = smtp_read($fp);
    if (!in_array(substr($r, 0, 3), (array) $expect, true)) {
        throw new RuntimeException(trim($r) !== '' ? trim(preg_replace('/\s+/', ' ', $r)) : 'pas de réponse du serveur');
    }
    return $r;
}

function mail_header_encode($s)
{
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Envoie un courriel texte. Retourne '' ou un message d'erreur. */
function mail_send_now($to, $subject, $body)
{
    $c = mail_config();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'Adresse destinataire invalide.';
    $from = $c['from'];
    $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . mail_header_encode($c['from_name'] !== '' ? $c['from_name'] : 'TPWeb') . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . mail_header_encode($subject),
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
    ];
    $encoded = rtrim(chunk_split(base64_encode(str_replace("\r", '', $body)), 76, "\r\n"));

    if ($c['transport'] === 'php') {
        $h = array_filter($headers, function ($l) { return stripos($l, 'To:') !== 0 && stripos($l, 'Subject:') !== 0; });
        return @mail($to, mail_header_encode($subject), $encoded, implode("\r\n", $h), '-f' . $from) ? '' : 'La fonction mail() de PHP a échoué.';
    }

    if (!function_exists('secret_get')) require_once __DIR__ . '/autobackup.php';
    $host = $c['host'];
    $port = (int) $c['port'];
    $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client(($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return 'Connexion impossible à ' . $host . ':' . $port . ' (' . $errstr . ').';
    stream_set_timeout($fp, 15);
    try {
        $me = isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== '' ? $_SERVER['SERVER_NAME'] : 'tpweb.local';
        smtp_cmd($fp, null, '220');
        $ehlo = smtp_cmd($fp, 'EHLO ' . $me, '250');
        if ($c['secure'] === 'tls') {
            smtp_cmd($fp, 'STARTTLS', '220');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('échec du chiffrement STARTTLS');
            $ehlo = smtp_cmd($fp, 'EHLO ' . $me, '250');
        }
        if ($c['user'] !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', '334');
            smtp_cmd($fp, base64_encode($c['user']), '334');
            smtp_cmd($fp, base64_encode(secret_get('smtp_pass')), '235');
        }
        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', '250');
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', ['250', '251']);
        smtp_cmd($fp, 'DATA', '354');
        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $encoded . "\r\n.\r\n");
        smtp_cmd($fp, null, '250');
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return '';
    } catch (RuntimeException $e) {
        @fclose($fp);
        return 'Erreur SMTP : ' . $e->getMessage();
    }
}

/* ---------- File d'attente ---------- */

function mail_queue_file() { return TPW_STATE . '/mail_queue.json'; }

function mail_queue($to, $subject, $body)
{
    if (!mail_ready() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $q = load_json(mail_queue_file(), []);
    if (count($q) >= 500) array_shift($q);
    $q[] = ['to' => $to, 'subject' => $subject, 'body' => $body . "\n\n— \nCourriel automatique de TPWeb. Pour changer vos notifications : " . mail_base_url() . "/admin.php?view=account#notifications\n", 'tries' => 0, 't' => time()];
    atomic_json(mail_queue_file(), $q);
    if (empty($GLOBALS['tpw_mail_shutdown'])) {
        $GLOBALS['tpw_mail_shutdown'] = true;
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            mail_flush(10, 12);
        });
    }
}

/** Expédie la file (au plus $max courriels, $budget secondes). */
function mail_flush($max = 30, $budget = 25)
{
    if (!mail_ready()) return 0;
    $lock = @fopen(TPW_STATE . '/mail.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0;
    $start = time();
    $sent = 0;
    $q = load_json(mail_queue_file(), []);
    $keep = [];
    foreach ($q as $m) {
        if ($sent >= $max || time() - $start > $budget || (!empty($m['next']) && $m['next'] > time())) { $keep[] = $m; continue; }
        $err = mail_send_now($m['to'], $m['subject'], $m['body']);
        if ($err === '') { $sent++; continue; }
        $m['tries'] = (int) $m['tries'] + 1;
        $m['error'] = $err;
        $m['next'] = time() + 600 * $m['tries'];
        if ($m['tries'] < 6) $keep[] = $m;
        else audit_log('mail_failed', ['à' => $m['to'], 'erreur' => $err], 'système');
    }
    // Des courriels ont pu être ajoutés pendant l'envoi : on les conserve.
    $now = load_json(mail_queue_file(), []);
    $keep = array_merge($keep, array_slice($now, count($q)));
    atomic_json(mail_queue_file(), $keep);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $sent;
}

/* ---------- Événements ---------- */

/** Nouveau rendu : courriel immédiat ou ligne du résumé quotidien pour l'enseignant propriétaire du dépôt. */
function mail_on_submission(array $cfg, $dir, $level, $who, $count, $late)
{
    if (!mail_ready()) return;
    $u = !empty($cfg['owner']) ? find_user($cfg['owner']) : null;
    if (!$u || user_email($u) === '') return;
    $p = mail_prefs($u);
    $line = date('d/m H:i') . ' · ' . $level . ' · ' . $who . ' · ' . $count . ' fichier(s)' . ($late ? ' · EN RETARD' : '');
    $title = !empty($cfg['title']) ? $cfg['title'] : basename($dir);
    if ($p['submit'] === 'instant') {
        mail_queue(user_email($u), 'Nouveau rendu : ' . $title, "Nouveau rendu pour « " . $title . " » (" . str_replace('/', ' › ', $dir) . ") :\n\n" . $line
            . "\n\nVoir les rendus : " . mail_base_url() . "/admin.php?view=deposits&item=" . rawurlencode($dir));
    } elseif ($p['submit'] === 'daily') {
        $f = TPW_STATE . '/mail_digest.json';
        $dg = load_json($f, []);
        $dg[$u['login']][$dir][] = $line;
        if (count($dg[$u['login']][$dir]) > 300) array_shift($dg[$u['login']][$dir]);
        atomic_json($f, $dg);
    }
}

/** Nouveau signalement de bug / idée : courriel aux administrateurs qui l'ont demandé. */
function mail_on_feedback($type, $title, $author, $role)
{
    if (!mail_ready()) return;
    foreach (users_all() as $login => $u) {
        $u['login'] = $login;
        if ($u['role'] !== 'admin' || user_email($u) === '' || empty(mail_prefs($u)['feedback'])) continue;
        mail_queue(user_email($u), 'Nouveau signalement : ' . $title, "Nouveau signalement (" . $type . ") de " . $author . " (" . $role . ") :\n\n« " . $title . " »\n\nVoir : " . mail_base_url() . "/admin.php?view=feedback");
    }
}

/** Tâches périodiques (au plus toutes les 10 minutes) : bilans de fermeture, résumés quotidiens, file. */
function mail_tick($force = false)
{
    if (!mail_ready()) return;
    $stamp = TPW_STATE . '/mail_tick.json';
    $st = load_json($stamp, ['t' => 0, 'digest' => '']);
    if (!$force && (int) $st['t'] > time() - 600) return;
    $st['t'] = time();
    atomic_json($stamp, $st);

    // Dépôts fermés depuis moins de 3 jours : bilan au propriétaire (une fois par date de fermeture).
    $deps = deposits_config();
    $changed = false;
    foreach ($deps as $dir => $cfg) {
        if (!is_array($cfg) || empty($cfg['enabled']) || empty($cfg['until'])) continue;
        $cfg = deposit_normalize($cfg);
        if (deposit_state($cfg) !== 'closed') continue;
        $end = parse_datetime($cfg['until']);
        if ($cfg['late'] === 'accept' && parse_datetime($cfg['late_until'])) $end = max($end, parse_datetime($cfg['late_until']));
        if (!$end || $end < time() - 3 * 86400) continue;
        if (isset($cfg['closed_notified']) && $cfg['closed_notified'] === $cfg['until']) continue;
        $deps[$dir]['closed_notified'] = $cfg['until'];
        $changed = true;
        $u = !empty($cfg['owner']) ? find_user($cfg['owner']) : null;
        if (!$u || user_email($u) === '' || empty(mail_prefs($u)['closed'])) continue;
        $entries = deposit_entries($cfg);
        $n = deposit_submitter_count($entries);
        $missing = '';
        if (function_exists('deposit_roster')) {
            $roster = deposit_roster($cfg);
            if ($roster) $missing = "\nÉlèves inscrits : " . count($roster) . " · sans rendu : " . max(0, count($roster) - $n);
        }
        $title = !empty($cfg['title']) ? $cfg['title'] : basename((string) $dir);
        mail_queue(user_email($u), 'Dépôt fermé : ' . $title, "Le dépôt « " . $title . " » (" . str_replace('/', ' › ', (string) $dir) . ") est fermé depuis le " . date('d/m/Y à H:i', $end) . ".\n\n"
            . "Élèves ayant rendu : " . $n . " · fichiers : " . count($entries) . $missing
            . "\n\nTélécharger les copies : " . mail_base_url() . "/admin.php?view=deposits&item=" . rawurlencode((string) $dir));
    }
    if ($changed) save_deposits_config($deps);

    // Résumé quotidien des rendus, envoyé une fois par jour à partir de 17 h.
    $today = date('Y-m-d');
    if ((int) date('G') >= 17 && $st['digest'] !== $today) {
        $f = TPW_STATE . '/mail_digest.json';
        $dg = load_json($f, []);
        foreach ($dg as $login => $byDir) {
            $u = find_user($login);
            if (!$u || user_email($u) === '' || !$byDir) continue;
            $txt = '';
            $total = 0;
            foreach ($byDir as $dir => $lines) {
                $total += count($lines);
                $txt .= "■ " . str_replace('/', ' › ', $dir) . " (" . count($lines) . ")\n  " . implode("\n  ", $lines) . "\n\n";
            }
            mail_queue(user_email($u), 'Rendus du jour : ' . $total . ' nouveau(x)', "Rendus reçus depuis le dernier résumé :\n\n" . $txt . "Voir les dépôts : " . mail_base_url() . "/admin.php?view=deposits");
        }
        atomic_json($f, []);
        $st['digest'] = $today;
        atomic_json($stamp, $st);
    }
    mail_flush();
}
