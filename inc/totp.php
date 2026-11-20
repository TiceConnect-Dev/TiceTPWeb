<?php
/**
 * Double authentification (TOTP, RFC 6238) : codes à 6 chiffres renouvelés toutes les 30 secondes,
 * compatibles avec les applications d'authentification (Google Authenticator, Microsoft Authenticator,
 * FreeOTP, Aegis…). Codes de secours à usage unique en cas de perte du téléphone.
 */

function base32_encode_bytes($bytes)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode_text($text)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) $text));
    $bits = '';
    foreach (str_split($text) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) return '';
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function totp_new_secret()
{
    return base32_encode_bytes(random_bytes(20));
}

function totp_code($secret, $step)
{
    $key = base32_decode_text($secret);
    $msg = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Vérifie un code (tolérance ± 30 secondes). Retourne le pas de temps utilisé, ou false.
 * $lastStep : dernier pas accepté, pour refuser la réutilisation d'un code.
 */
function totp_verify($secret, $code, $lastStep = 0)
{
    $code = preg_replace('/\D/', '', (string) $code);
    if (strlen($code) !== 6 || $secret === '') return false;
    $now = (int) floor(time() / 30);
    for ($d = -1; $d <= 1; $d++) {
        $step = $now + $d;
        if ($step <= (int) $lastStep) continue;
        if (hash_equals(totp_code($secret, $step), $code)) return $step;
    }
    return false;
}

/** Adresse otpauth:// pour le QR code. */
function totp_uri($secret, $login)
{
    $issuer = TPW_APP_NAME . (school_name() !== '' ? ' ' . school_name() : '');
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $login) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

/** 8 codes de secours (affichés une fois) et leurs empreintes (stockées). */
function totp_backup_codes()
{
    $plain = [];
    $hashes = [];
    for ($i = 0; $i < 8; $i++) {
        $c = strtoupper(substr(base32_encode_bytes(random_bytes(5)), 0, 8));
        $c = substr($c, 0, 4) . '-' . substr($c, 4);
        $plain[] = $c;
        $hashes[] = password_hash($c, PASSWORD_DEFAULT);
    }
    return [$plain, $hashes];
}

/** Utilise un code de secours : retourne la liste restante, ou false si le code est invalide. */
function totp_use_backup(array $hashes, $code)
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    if (strlen($code) !== 8) return false;
    $code = substr($code, 0, 4) . '-' . substr($code, 4);
    foreach ($hashes as $i => $h) {
        if (password_verify($code, $h)) {
            unset($hashes[$i]);
            return array_values($hashes);
        }
    }
    return false;
}

/** La double authentification est-elle exigée pour ce compte ? */
function totp_required(array $user)
{
    $sec = settings()['security'];
    if ($user['role'] === 'admin' && !empty($sec['force_2fa_admin'])) return true;
    return !empty($sec['force_2fa_all']);
}

/**
 * Connexion en deux étapes : après le mot de passe (ou l'ENT), l'identifiant attend le code.
 * Retourne l'URL vers laquelle rediriger.
 */
function login_or_second_factor($login, $via)
{
    $user = find_user($login);
    if ($user && $user['totp'] !== '') {
        session_regenerate_id(true);
        unset($_SESSION['user']);
        $_SESSION['pending_2fa'] = ['login' => $login, 'time' => time(), 'via' => $via, 'tries' => 0];
        return 'admin.php?step=2fa';
    }
    login_user($login);
    audit_log($via === 'ent' ? 'ent_login' : 'login', [], $login);
    return 'admin.php';
}
