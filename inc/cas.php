<?php
/**
 * Connexion des enseignants par l'ENT (protocole CAS 2.0 / 3.0).
 *
 * Fonctionne avec les ENT qui exposent un serveur CAS (Open ENT NG, dont l'ENT Hauts-de-France).
 * Prérequis côté ENT : l'administrateur ENT de l'établissement déclare TPWeb comme
 * « connecteur » CAS avec l'adresse de service affichée dans Paramètres.
 * Prérequis côté serveur : PHP doit pouvoir joindre l'ENT en HTTPS (validation du ticket).
 *
 * Chaque compte TPWeb est lié à un identifiant ENT (champ « ent_id »).
 */

function ent_config(array $data = null)
{
    if ($data === null) $data = settings();
    $cfg = is_array($data['ent']) ? $data['ent'] : [];
    $cfg += ['enabled' => false, 'label' => 'ENT', 'url' => '', 'version' => '2', 'service_base' => '', 'service_url' => '', 'autocreate' => 'off', 'students' => false,
             'students_selfreg' => false, 'students_autocreate' => false];
    if (!in_array($cfg['autocreate'], ['off', 'pending', 'active'], true)) $cfg['autocreate'] = 'off';
    return $cfg;
}

function ent_enabled(array $data = null)
{
    $cfg = ent_config($data);
    return !empty($cfg['enabled']) && $cfg['url'] !== '';
}

/** Adresse de base de TPWeb vue depuis le navigateur (ex. http://192.168.1.10/TPWeb). */
function app_base_url(array $cfg)
{
    if ($cfg['service_base'] !== '') return rtrim($cfg['service_base'], '/');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/** Adresse de service CAS : fixe, identique à l'URL déclarée dans le connecteur de l'ENT. */
function cas_service_url(array $cfg)
{
    // Identique à l'URL déclarée dans le connecteur ENT (réglable dans Paramètres).
    if (!empty($cfg['service_url'])) return $cfg['service_url'];
    return app_base_url($cfg) . '/cas.php';
}

function cas_login_url(array $cfg, $service)
{
    return rtrim($cfg['url'], '/') . '/login?service=' . rawurlencode($service);
}

/** Certificats racines pour les requêtes HTTPS (XAMPP n'en configure pas toujours). */
function ca_bundle()
{
    $candidates = [ini_get('curl.cainfo'), ini_get('openssl.cafile')];
    $phpDir = dirname(PHP_BINARY);
    $candidates[] = $phpDir . '/extras/ssl/cacert.pem';
    $candidates[] = dirname($phpDir) . '/apache/bin/curl-ca-bundle.crt';
    $candidates[] = dirname($phpDir) . '/php/extras/ssl/cacert.pem';
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '' && is_file($c)) return $c;
    }
    return '';
}

/** Requête HTTP GET ; retourne [code, corps] ou une chaîne d'erreur. */
function http_get($url)
{
    $ca = ca_bundle();
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TPWeb/' . TPW_VERSION,
        ]);
        if ($ca !== '') curl_setopt($ch, CURLOPT_CAINFO, $ca);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return 'Connexion à l’ENT impossible : ' . $err;
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string) $body];
    }
    if (!ini_get('allow_url_fopen')) return 'Ni l’extension curl ni allow_url_fopen ne sont disponibles dans PHP.';
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    if ($ca !== '') $ssl['cafile'] = $ca;
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'user_agent' => 'TPWeb/' . TPW_VERSION], 'ssl' => $ssl]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return 'Connexion à l’ENT impossible (vérifiez l’accès Internet du serveur et l’extension openssl).';
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int) $m[1];
    return [$code, $body];
}

/**
 * Valide un ticket CAS.
 * Retourne ['user' => identifiant, 'attributes' => [...]] ou un message d'erreur (string).
 */
function cas_validate(array $cfg, $service, $ticket)
{
    if (!preg_match('/^[A-Za-z0-9._:-]{1,256}$/', $ticket)) return 'Ticket CAS invalide.';
    $path = $cfg['version'] === '3' ? '/p3/serviceValidate' : '/serviceValidate';
    $url = rtrim($cfg['url'], '/') . $path . '?service=' . rawurlencode($service) . '&ticket=' . rawurlencode($ticket);

    $res = http_get($url);
    if (is_string($res)) return $res;
    list($code, $body) = $res;

    // On lit la réponse même en cas de code HTTP d'erreur : le motif CAS est souvent dans le corps.
    $prev = libxml_use_internal_errors(true);
    $xml = trim($body) !== '' ? simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET) : false;
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        $extract = trim(mb_substr(strip_tags($body), 0, 160));
        return 'Le serveur CAS a répondu avec l’erreur HTTP ' . $code . ($extract !== '' ? ' : ' . $extract : '') . ' [' . $path . ']';
    }

    $cas = $xml->children('http://www.yale.edu/tp/cas');
    if (isset($cas->authenticationFailure)) {
        return 'L’ENT a refusé la connexion : ' . trim((string) $cas->authenticationFailure) . ' (' . (string) $cas->authenticationFailure->attributes()->code . ')';
    }
    if (!isset($cas->authenticationSuccess)) return 'Réponse du serveur CAS inattendue.';

    $success = $cas->authenticationSuccess;
    $user = trim((string) $success->user);
    if ($user === '') return 'L’ENT n’a pas transmis d’identifiant.';

    $attributes = [];
    if (isset($success->attributes)) {
        foreach ($success->attributes->children('http://www.yale.edu/tp/cas') as $name => $value) {
            $attributes[$name][] = trim((string) $value);
        }
    }
    return ['user' => $user, 'attributes' => $attributes];
}

function normalize_ent_id($id)
{
    $id = trim((string) $id);
    return function_exists('mb_strtolower') ? mb_strtolower($id, 'UTF-8') : strtolower($id);
}

/** Compte TPWeb lié à un identifiant ENT (ou null). */
function find_user_by_ent($entId)
{
    $entId = normalize_ent_id($entId);
    if ($entId === '') return null;
    foreach (users_all() as $login => $u) {
        if (!empty($u['ent_id']) && normalize_ent_id($u['ent_id']) === $entId) return find_user((string) $login);
    }
    return null;
}

/**
 * Adresse de service telle que l'ENT nous a renvoyés (requête actuelle sans « ticket »).
 * C'est exactement celle à laquelle l'ENT a associé le ticket. Le schéma et l'hôte sont
 * repris de l'adresse envoyée à l'ENT (le serveur, derrière un proxy, peut se croire en http).
 */
function cas_return_service($sentService)
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $query = (string) parse_url($uri, PHP_URL_QUERY);
    $kept = [];
    foreach ($query === '' ? [] : explode('&', $query) as $pair) {
        if ($pair === '' || strpos($pair, 'ticket=') === 0) continue;
        $kept[] = $pair;
    }
    $origin = preg_replace('~^(https?://[^/]+).*$~i', '$1', $sentService);
    return $origin . $path . ($kept ? '?' . implode('&', $kept) : '');
}

/**
 * Valide un ticket en essayant plusieurs formes de l'adresse de service si l'ENT
 * répond INVALID_SERVICE (certains serveurs mémorisent l'adresse encodée ou tronquée).
 * Retourne ['ok' => résultat|null, 'attempts' => [[libellé, adresse, message], ...]].
 * La variante gagnante est mémorisée pour être essayée en premier la fois suivante.
 */
function cas_validate_variants(array $cfg, $service, $ticket)
{
    $noQuery = preg_replace('/\?.*$/', '', $service);
    $other = preg_match('~^https://~i', $service) ? preg_replace('~^https://~i', 'http://', $service) : preg_replace('~^http://~i', 'https://', $service);
    $variants = [
        'exacte'          => [$service, $cfg['version']],
        'encodée'         => [rawurlencode($service), $cfg['version']],
        'sans paramètres' => [$noQuery, $cfg['version']],
        'autre protocole' => [$other, $cfg['version']],
        'CAS 3.0'         => [$service, $cfg['version'] === '3' ? '2' : '3'],
    ];
    // Variante qui a fonctionné la dernière fois en premier.
    if (!empty($cfg['variant']) && isset($variants[$cfg['variant']])) {
        $variants = [$cfg['variant'] => $variants[$cfg['variant']]] + $variants;
    }

    $attempts = [];
    $seen = [];
    foreach ($variants as $label => $v) {
        list($svc, $version) = $v;
        $key = $svc . '|' . $version;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $res = cas_validate(['version' => $version] + $cfg, $svc, $ticket);
        if (is_array($res)) {
            $attempts[] = [$label, $svc, 'OK'];
            if (($cfg['variant'] ?? '') !== $label) {
                $data = settings();
                $data['ent']['variant'] = $label;
                save_settings($data);
            }
            return ['ok' => $res, 'attempts' => $attempts];
        }
        $attempts[] = [$label, $svc, $res];
        // Ticket consommé : inutile d'insister. Service refusé ou erreur HTTP : on essaie la variante suivante.
        if (strpos($res, 'INVALID_TICKET') !== false) break;
    }
    return ['ok' => null, 'attempts' => $attempts];
}

/** Nom affiché déduit des attributs CAS, sinon de l'identifiant (thomas.luc → Thomas Luc). */
function ent_display_name($entId, array $attributes)
{
    $get = function ($keys) use ($attributes) {
        foreach ($keys as $k) {
            foreach ($attributes as $name => $values) {
                if (strcasecmp($name, $k) === 0 && isset($values[0]) && trim($values[0]) !== '') return trim($values[0]);
            }
        }
        return '';
    };
    $display = $get(['displayName', 'cn', 'name']);
    if ($display === '') $display = trim($get(['firstName', 'givenName', 'prenom']) . ' ' . $get(['lastName', 'sn', 'nom', 'surname']));
    if ($display === '') {
        $base = preg_replace('/@.*$/', '', (string) $entId);
        $parts = preg_split('/[._-]+/', $base);
        $display = implode(' ', array_map(function ($p) { return mb_convert_case($p, MB_CASE_TITLE, 'UTF-8'); }, array_filter($parts, 'strlen')));
    }
    $display = preg_replace('/\s+/u', ' ', $display);
    return mb_substr($display !== '' ? $display : (string) $entId, 0, 60);
}

/** Identifiant TPWeb libre déduit de l'identifiant ENT. */
function ent_new_login($entId)
{
    $base = strtolower(preg_replace('/@.*$/', '', (string) $entId));
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
        if ($t !== false) $base = strtolower($t);
    }
    $base = trim(preg_replace('/[^a-z0-9._-]+/', '.', $base), '.-_');
    if (strlen($base) < 2) $base = 'prof';
    $base = substr($base, 0, 28);
    $users = users_all();
    $login = $base;
    for ($i = 2; isset($users[$login]); $i++) $login = $base . $i;
    return $login;
}

/**
 * Crée le compte d'un enseignant à sa première connexion par l'ENT.
 * $pending : le compte attend la validation d'un administrateur.
 */
function ent_create_account($entId, array $attributes, $pending)
{
    $users = users_all();
    $login = ent_new_login($entId);
    $users[$login] = [
        'name' => ent_display_name($entId, $attributes), 'role' => 'prof', 'hash' => '', 'folders' => [],
        'ent_id' => mb_substr((string) $entId, 0, 120), 'perms' => normalize_perms(null), 'subject' => '', 'classes' => [],
        'pending' => (bool) $pending, 'created' => time(), 'created_by' => 'ent',
    ];
    save_users($users);
    return find_user($login);
}
