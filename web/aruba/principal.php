<?php
/* ============================================
 *  CONFIG BÁSICA OMADA
 * ============================================ */

const OMADA_CONTROLLER    = '192.168.0.3';  // IP o hostname de tu Omada Controller
const OMADA_PORT          = 8043;           // Puerto HTTPS (por defecto 8043)

/*
 * De tu URL:
 *   https://localhost:8043/2ec6e0fb47d04f54bdfec140a0a15ca1/login?...
 * el controllerId es: 2ec6e0fb47d04f54bdfec140a0a15ca1
 */
const OMADA_CONTROLLER_ID = '2ec6e0fb47d04f54bdfec140a0a15ca1';

/* Usuario y contraseña del Hotspot Operator */
const OMADA_OP_USER       = 'portal-operator';
const OMADA_OP_PASS       = 'Gonet2025@';

/* Nombre del site solo como respaldo si Omada no manda "site" */
const OMADA_DEFAULT_SITE  = 'jellz_Gonet';

/* Archivos temporales para cookies y token CSRF */
define('OMADA_COOKIE_FILE', sys_get_temp_dir() . '/omada_cookie.txt');
define('OMADA_TOKEN_FILE',  sys_get_temp_dir() . '/omada_token.txt');

/* ============================================
 *  HELPERS OMADA
 * ============================================ */

function omada_build_base_path(): string {
    if (OMADA_CONTROLLER_ID !== '') {
        return '/' . OMADA_CONTROLLER_ID;
    }
    return '';
}

/**
 * LOGIN del operador Hotspot (primer paso).
 * SOLO name + password.
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

    error_log("🌐 OMADA LOGIN URL: $url");

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

    error_log("📥 OMADA LOGIN RESPUESTA RAW: $res");

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
 * AUTH / CLIENT ACKNOWLEDGE
 * Aquí es donde Omada autoriza al cliente.
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
        'clientMac' => $clientMac,  // tal cual viene del AP
        'apMac'     => $apMac,      // tal cual viene del AP
        'ssidName'  => $ssidName,
        'radioId'   => $radioId,
        'site'      => $site,
        'time'      => $milliseconds,
        'authType'  => 4,           // External Portal
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

    error_log("🌐 OMADA AUTH URL: $url");
    error_log("➡️ AUTH PAYLOAD: " . json_encode($authInfo));

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
        error_log("❌ OMADA AUTH CURL ERROR: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    error_log("📥 OMADA AUTH RESPUESTA RAW: $res");

    $obj = json_decode($res, true);
    if (!is_array($obj) || ($obj['errorCode'] ?? -1) !== 0) {
        error_log("❌ OMADA AUTH FAILED: " . print_r($obj, true));
        return false;
    }

    error_log("✅ OMADA AUTH OK PARA MAC: $clientMac");
    return true;
}

/* ============================================
 *  JALAR VARIABLES QUE MANDA OMADA
 *  (SOLO GET/POST, SIN MODIFICAR)
 * ============================================ */

$clientMac = $_GET['clientMac']   ?? $_POST['clientMac']   ?? ($_GET['mac'] ?? $_POST['mac'] ?? '');
$clientIp  = $_GET['clientIp']    ?? $_POST['clientIp']    ?? ($_GET['ip']  ?? $_POST['ip']  ?? '');
$apMac     = $_GET['apMac']       ?? $_POST['apMac']       ?? ($_GET['ap']  ?? $_POST['ap']  ?? '');
$ssidName  = $_GET['ssidName']    ?? $_POST['ssidName']    ?? '';
$radioId   = $_GET['radioId']     ?? $_POST['radioId']     ?? '0';
$site      = $_GET['site']        ?? $_POST['site']        ?? OMADA_DEFAULT_SITE;
$redirect  = $_GET['redirectUrl'] ?? $_POST['redirectUrl'] ?? 'https://www.google.com';

error_log("🔍 PARAMS - clientMac={$clientMac}, apMac={$apMac}, ip={$clientIp}, ssid={$ssidName}, radioId={$radioId}, site={$site}, redirect={$redirect}");

/* ============================================
 *  VALIDACIÓN BÁSICA
 * ============================================ */

if ($clientMac === '') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "No se pudo identificar tu dispositivo. Vuelve a conectarte al Wi-Fi.";
    exit;
}

/* ============================================
 *  FLUJO SIMPLE: LOGIN + AUTH + REDIRECT
 * ============================================ */

$loginOk = omada_hotspot_login();
if (!$loginOk) {
    error_log("⚠️ OMADA LOGIN FALLÓ, redirigimos sin auth");
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

/* Redirigir siempre a la URL original o a Google */
header("Location: " . $redirect);
exit;
