<?php
/**
 * Point d'entrée commun : session, en-têtes de sécurité, chargement des modules.
 */

require_once dirname(__DIR__) . '/config.php';

date_default_timezone_set(TPW_TIMEZONE);

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('TPWEBSESSID');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(0, '/', '', $secure, true);
    session_start();
}

if (PHP_SAPI === 'cli') $_SESSION = isset($_SESSION) ? $_SESSION : [];

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/deposits.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/office.php';
require_once __DIR__ . '/quiz.php';
require_once __DIR__ . '/usage.php';
require_once __DIR__ . '/students.php';
require_once __DIR__ . '/feedback.php';
require_once __DIR__ . '/stats.php';

init_store();
