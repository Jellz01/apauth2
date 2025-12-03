<?php
/* ========= CONFIG OMADA ========= */

const OMADA_CONTROLLER    = '192.168.0.3';  // IP de tu controlador
const OMADA_PORT          = 8043;

// Si algún día sacas el controllerId (la parte rara en la URL),
// lo pones aquí. Mientras tanto, vacío:
const OMADA_CONTROLLER_ID = '';

// Usuario y clave del Hotspot Operator (en Omada)
const OMADA_OP_USER       = 'portal-operator';
const OMADA_OP_PASS       = 'S3cret!';

// Nombre del sitio en Omada (como sale en el controller)
const OMADA_SITE          = 'jellz_Gonet';

// Archivos temporales para cookies y token
define('OMADA_COOKIE_FILE', sys_get_temp_dir() . '/omada_cookie.txt');
define('OMADA_TOKEN_FILE',  sys_get_temp_dir() . '/omada_token.txt');

/* ========= PEQUEÑOS HELPERS ========= */

function normalize_mac($mac_raw) {
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac_raw);
    return strtoupper($hex);
}

function omada_build_base_path(): string {
    if (OMADA_CONTROLLER_ID !== '') {
        return '/' . OMADA_CONTROLLER_ID;
    }
    return '';
}

/**
 * LOGIN del operador Hotspot:
 * SOLO name + password (como dice TP-Link).
 */
function omada_hotspot_login(): bool {
    @unlink(OMADA_COOKIE_FILE);
    @unlink(OMADA_TOKEN_FILE);

    $loginInfo = [
        "name"     => OMADA_OP_USER,
        "password" => OMADA_OP_PASS,
    ];

    $basePath = omada_build_base_path();

    $url = sprintf(
        "https://%s:%d%s/api/v2/hotspot/login",
        OMADA_CONTROLLER,
        OMADA_PORT,
        $basePath
    );

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => OMADA_COOKIE_FILE,
        CURLOPT_COOKIEFILE     => OMADA_COOKIE_FILE,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Accept: application/json",
        ],
        CURLOPT_POSTFIELDS     => json_encode($loginInfo),
    ]);

    $res = curl_exec($ch);
    if ($res === false) {
        error_log("❌ OMADA LOGIN CURL ERROR: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $obj = json_decode($res, true);
    if (!is_array($obj) || ($obj['errorCode'] ?? -1) !== 0) {
        error_log("❌ OMADA LOGIN FAILED: " . print_r($obj, true));
        return false;
    }

    $token = $obj['result']['token'] ?? '';
    if ($token) {
        file_put_contents(OMADA_TOKEN_FILE, $token);
        error_log("✅ OMADA LOGIN OK - TOKEN GUARDADO");
    } else {
        error_log("⚠️ OMADA LOGIN SIN TOKEN EN RESPUESTA");
    }

    return true;
}

/**
 * CLIENT ACKNOWLEDGE / AUTH:
 * Aquí es donde realmente se “abre” el acceso al cliente.
 */
function omada_authorize_client(
    string $clientMac,
    string $apMac,
    string $ssidName,
    string $radioId,
    string $site,
    int $minutes = 120
): bool {

    $milliseconds = $minutes * 60 * 1000;

    $authInfo = [
        'clientMac' => $clientMac,
        'apMac'     => $apMac,
        'ssidName'  => $ssidName,
        'radioId'   => $radioId,
        'site'      => $site,
        'time'      => $milliseconds,
        'authType'  => 4, // External Portal
    ];

    $csrfToken = @file_get_contents(OMADA_TOKEN_FILE) ?: '';

    $headers = [
        "Content-Type: application/json",
        "Accept: application/json",
    ];
    if ($csrfToken !== '') {
        $headers[] = "Csrf-Token: " . $csrfToken;
    }

    $basePath = omada_build_base_path();

    $url = sprintf(
        "https://%s:%d%s/api/v2/hotspot/extPortal/auth",
        OMADA_CONTROLLER,
        OMADA_PORT,
        $basePath
    );

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => OMADA_COOKIE_FILE,
        CURLOPT_COOKIEFILE     => OMADA_COOKIE_FILE,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($authInfo),
    ]);

    $res = curl_exec($ch);
    if ($res === false) {
        error_log("❌ OMADA AUTHORIZE CURL ERROR: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $obj = json_decode($res, true);
    if (!is_array($obj) || ($obj['errorCode'] ?? -1) !== 0) {
        error_log("❌ OMADA AUTHORIZE FAILED: " . print_r($obj, true));
        return false;
    }

    error_log("✅ OMADA AUTHORIZE OK PARA MAC: $clientMac");
    return true;
}

/* ========= LEER PARÁMETROS QUE MANDA OMADA ========= */

$mac_raw   = $_GET['clientMac']  ?? $_GET['mac'] ?? '';
$ip_raw    = $_GET['clientIp']   ?? $_GET['ip']  ?? '';
$ap_raw    = $_GET['apMac']      ?? $_GET['ap']  ?? '';
$ssidName  = $_GET['ssidName']   ?? '';
$radioId   = $_GET['radioId']    ?? '0';
$site      = $_GET['site']       ?? OMADA_SITE;
$redirect  = $_GET['redirectUrl'] ?? 'https://www.google.com';

$clientMac = normalize_mac($mac_raw);
$apMac     = normalize_mac($ap_raw);

/* ========= SI NO HAY MAC, NO PODEMOS AUTORIZAR ========= */

if ($clientMac === '' || strlen($clientMac) !== 12) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "No se pudo identificar tu dispositivo. Vuelve a conectarte al Wi-Fi.";
    exit;
}

/* ========= FLUJO SIMPLE: LOGIN + AUTH + REDIRECT ========= */

$loginOk = omada_hotspot_login();
if (!$loginOk) {
    error_log("⚠️ LOGIN FALLÓ, igual redirigimos sin auth");
} else {
    $authOk = omada_authorize_client(
        $clientMac,
        $apMac,
        $ssidName,
        $radioId,
        $site,
        120  // minutos de acceso
    );
    error_log("RESULTADO AUTH: " . ($authOk ? "OK" : "FAIL"));
}

// Redirigir a la URL original o a Google
header("Location: " . $redirect);
exit;
