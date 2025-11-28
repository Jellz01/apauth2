<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ============ CONFIG OMADA ============ */

// IP o hostname del controller (ajusta si no es 10.0.0.10)
const OMADA_CONTROLLER    = '10.0.0.10';
const OMADA_PORT          = 8043;

// IMPORTANTE: pon aquí el ID que ves en la URL de Omada después de /e/ 
// Ej: https://10.0.0.10:8043/e/ABCDEF1234567890/#/site/jellz_Gonet/dashboard
// => OMADA_CONTROLLER_ID = 'ABCDEF1234567890';
const OMADA_CONTROLLER_ID = 'PON_AQUI_TU_CONTROLLER_ID';

const OMADA_OP_USER       = 'portal-operator';
const OMADA_OP_PASS       = 'S3cret!';
const OMADA_SITE          = 'jellz_Gonet';

// Archivos temporales para cookies y token CSRF
define('OMADA_COOKIE_FILE', sys_get_temp_dir() . '/omada_cookie.txt');
define('OMADA_TOKEN_FILE',  sys_get_temp_dir() . '/omada_token.txt');

/* ============ CONFIG BD ============ */

$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";

/** ===================== Helpers ===================== */

function normalize_mac($mac_raw) {
    if (empty($mac_raw)) return '';
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac_raw);
    return strtoupper($hex);
}

function validarCedulaEC(string $cedula): bool {
    if (!preg_match('/^\d{10}$/', $cedula)) return false;
    $prov = (int)substr($cedula, 0, 2);
    if ($prov < 1 || $prov > 24) return false;
    $tercer = (int)$cedula[2];
    if ($tercer >= 6) return false;
    $coef = [2,1,2,1,2,1,2,1,2];
    $suma = 0;
    for ($i = 0; $i < 9; $i++) {
        $prod = (int)$cedula[$i] * $coef[$i];
        if ($prod >= 10) $prod -= 9;
        $suma += $prod;
    }
    $dv = (10 - ($suma % 10)) % 10;
    return $dv === (int)$cedula[9];
}

function validarTelefonoEC(string $tel): bool {
    $tel = preg_replace('/\D+/', '', $tel);
    return preg_match('/^09\d{8}$/', $tel) === 1;
}

function validarEmailReal(string $email): bool {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $dom = substr(strrchr($email, "@"), 1);
    if (!$dom) return false;
    $mxOk = function_exists('checkdnsrr') ? checkdnsrr($dom, 'MX') : false;
    $aOk  = function_exists('checkdnsrr') ? checkdnsrr($dom, 'A')  : false;
    return (function_exists('checkdnsrr')) ? ($mxOk || $aOk) : true;
}

/** ============= Conexión a la Base de Datos ============= */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    error_log("✅ CONEXIÓN BD EXITOSA");
} catch (Exception $e) {
    error_log("❌ ERROR CONEXIÓN BD: " . $e->getMessage());
    die("<div class='error'>❌ Error de conexión</div>");
}

/** ============= Parámetros de entrada desde Omada ============= */
/*
   Omada (External Portal Server) manda algo tipo:
   ?clientMac=...&clientIp=...&apMac=...&ssidName=...&radioId=0&site=jellz_Gonet&redirectUrl=...
*/

$mac_raw   = $_GET['clientMac']  ?? $_GET['mac'] ?? $_POST['mac'] ?? '';
$ip_raw    = $_GET['clientIp']   ?? $_GET['ip']  ?? $_POST['ip']  ?? '';
$ap_raw    = $_GET['apMac']      ?? $_GET['ap']  ?? $_POST['ap_mac'] ?? '';
$ssidName  = $_GET['ssidName']   ?? $_POST['ssidName']   ?? '';
$radioId   = $_GET['radioId']    ?? $_POST['radioId']    ?? '0';
$site      = $_GET['site']       ?? $_POST['site']       ?? OMADA_SITE;

$redirect_url_raw = $_GET['redirectUrl'] ?? $_POST['redirect_url'] ?? '';

$mac_norm     = normalize_mac($mac_raw);
$ap_mac_norm  = normalize_mac($ap_raw);
$ip           = trim($ip_raw);
$redirect_url = trim($redirect_url_raw);

error_log("🔍 REQUEST - MAC: '$mac_norm', IP: '$ip', AP_MAC: '$ap_mac_norm', SSID: '$ssidName', SITE: '$site', REDIRECT: '$redirect_url'");

$errors = [
    'mac'      => '',
    'nombre'   => '',
    'apellido' => '',
    'cedula'   => '',
    'telefono' => '',
    'email'    => '',
    'terminos' => ''
];

/** ============= Helpers Omada API ============= */

function omada_hotspot_login(): bool {
    @unlink(OMADA_COOKIE_FILE);
    @unlink(OMADA_TOKEN_FILE);

    $loginInfo = [
        "name"     => OMADA_OP_USER,
        "password" => OMADA_OP_PASS,
        "site"     => OMADA_SITE,
    ];

    $url = sprintf(
        "https://%s:%d/%s/api/v2/hotspot/login",
        OMADA_CONTROLLER,
        OMADA_PORT,
        OMADA_CONTROLLER_ID
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
        error_log("❌ Omada hotspot login curl error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $obj = json_decode($res, true);
    if (!is_array($obj) || ($obj['errorCode'] ?? -1) !== 0) {
        error_log("❌ Omada hotspot login failed: $res");
        return false;
    }

    $token = $obj['result']['token'] ?? '';
    if ($token) {
        file_put_contents(OMADA_TOKEN_FILE, $token);
    } else {
        error_log("⚠️ Omada login sin token CSRF en respuesta");
    }

    error_log("✅ Omada hotspot login OK");
    return true;
}

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
        'authType'  => 4, // External Portal auth
    ];

    $csrfToken = @file_get_contents(OMADA_TOKEN_FILE) ?: '';

    $headers = [
        "Content-Type: application/json",
        "Accept: application/json",
    ];
    if ($csrfToken !== '') {
        $headers[] = "Csrf-Token: " . $csrfToken;
    }

    $url = sprintf(
        "https://%s:%d/%s/api/v2/hotspot/extPortal/auth",
        OMADA_CONTROLLER,
        OMADA_PORT,
        OMADA_CONTROLLER_ID
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
        error_log("❌ Omada authorize curl error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $obj = json_decode($res, true);
    if (!is_array($obj) || ($obj['errorCode'] ?? -1) !== 0) {
        error_log("❌ Omada authorize failed: $res");
        return false;
    }

    error_log("✅ Omada authorize OK para $clientMac");
    return true;
}

/** ============= Manejo POST (form y quick connect) ============= */

$formShouldBeVisible = false; // por defecto oculto

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ¿Es un quick connect? (NAVEGAR sin formulario)
    if (isset($_POST['quick_connect']) && $_POST['quick_connect'] === '1') {
        error_log("⚡ QUICK CONNECT SOLICITADO");

        $mac_post_raw  = $_POST['mac']      ?? $mac_raw;
        $ap_post_raw   = $_POST['ap_mac']   ?? $ap_raw;
        $ssidName_post = $_POST['ssidName'] ?? $ssidName;
        $radioId_post  = $_POST['radioId']  ?? $radioId;
        $site_post     = $_POST['site']     ?? $site;
        $redirect_url  = $_POST['redirect_url'] ?? $redirect_url;

        $mac_norm_q    = normalize_mac($mac_post_raw);
        $ap_mac_norm_q = normalize_mac($ap_post_raw);
        $ssidName      = $ssidName_post;
        $radioId       = $radioId_post;
        $site          = $site_post;

        if ($mac_norm_q === '' || strlen($mac_norm_q) !== 12) {
            // Si falla MAC, mostramos error y dejamos el portal cargado
            $errors['mac'] = 'No se pudo identificar tu dispositivo para conectar.';
            $formShouldBeVisible = false; // sigue solo pantalla principal
        } else {
            // Quick connect: solo Omada, sin guardar datos personales
            if (!omada_hotspot_login()) {
                error_log("⚠️ Omada hotspot login falló en QUICK CONNECT, redirigiendo igual");
            } else {
                omada_authorize_client(
                    $mac_norm_q,
                    $ap_mac_norm_q,
                    $ssidName,
                    $radioId,
                    $site,
                    120
                );
            }

            if (!empty($redirect_url)) {
                header("Location: " . $redirect_url);
            } else {
                header("Location: https://www.google.com");
            }
            exit;
        }

    } else {
        // ---- FORMULARIO COMPLETO (REGISTRO) ----
        error_log("📨 PROCESANDO FORMULARIO POST (REGISTRO)");

        $nombre   = trim($_POST['nombre']   ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $cedula   = preg_replace('/\D+/', '', $_POST['cedula'] ?? '');
        $telefono = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $terminos = isset($_POST['terminos']) ? 1 : 0;

        // MAC/AP/SSID/SITE/REDIRECT desde hidden + GET
        $mac_post_raw  = $_POST['mac']      ?? $mac_raw;
        $ap_post_raw   = $_POST['ap_mac']   ?? $ap_raw;
        $ssidName_post = $_POST['ssidName'] ?? $ssidName;
        $radioId_post  = $_POST['radioId']  ?? $radioId;
        $site_post     = $_POST['site']     ?? $site;
        $redirect_url  = $_POST['redirect_url'] ?? $redirect_url;

        $mac_norm     = normalize_mac($mac_post_raw);
        $ap_mac_norm  = normalize_mac($ap_post_raw);
        $ssidName     = $ssidName_post;
        $radioId      = $radioId_post;
        $site         = $site_post;

        // Validaciones
        if ($mac_norm === '' || strlen($mac_norm) !== 12) {
            $errors['mac'] = 'No se pudo identificar correctamente tu dispositivo.';
        }

        if ($nombre === '')   $errors['nombre']   = 'Ingresa tu nombre.';
        if ($apellido === '') $errors['apellido'] = 'Ingresa tu apellido.';
        if (!validarCedulaEC($cedula)) $errors['cedula'] = 'Cédula inválida.';
        if (!validarTelefonoEC($telefono)) $errors['telefono'] = 'Teléfono inválido (09XXXXXXXX).';
        if (!validarEmailReal($email)) $errors['email'] = 'Email inválido.';
        if (!$terminos) $errors['terminos'] = 'Debes aceptar los términos.';

        $hayErrores = array_filter($errors, fn($e) => $e !== '');

        // Si el usuario intentó enviar el formulario, lo mostramos
        $formShouldBeVisible = true;

        if (!$hayErrores) {
            try {
                $conn->begin_transaction();
                error_log("🔄 INICIANDO TRANSACCIÓN");

                // Insertar cliente en tu tabla
                $stmt_clients = $conn->prepare("
                    INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, ap_mac, enabled)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt_clients->bind_param(
                    "sssssss",
                    $nombre,
                    $apellido,
                    $cedula,
                    $telefono,
                    $email,
                    $mac_norm,
                    $ap_mac_norm
                );
                $stmt_clients->execute();
                $stmt_clients->close();
                error_log("✅ CLIENTE INSERTADO - MAC: $mac_norm, AP_MAC: $ap_mac_norm");

                $conn->commit();
                error_log("✅ TRANSACCIÓN COMPLETADA");

                // 1) Login Hotspot API
                if (!omada_hotspot_login()) {
                    error_log("⚠️ Omada hotspot login falló, redirigiendo igual");
                } else {
                    // 2) Autorizar cliente (por ejemplo 120 minutos)
                    omada_authorize_client(
                        $mac_norm,
                        $ap_mac_norm,
                        $ssidName,
                        $radioId,
                        $site,
                        120
                    );
                }

                // 3) Redirigir a la URL original
                if (!empty($redirect_url)) {
                    header("Location: " . $redirect_url);
                } else {
                    header("Location: https://www.google.com");
                }
                exit;

            } catch (Exception $e) {
                error_log("❌ ERROR: " . $e->getMessage());
                $conn->rollback();
                header('Content-Type: text/plain; charset=utf-8');
                die('Error en registro');
            }
        } else {
            // Mantener datos en el formulario
            $_POST['nombre']   = $nombre;
            $_POST['apellido'] = $apellido;
            $_POST['cedula']   = $cedula;
            $_POST['telefono'] = $telefono;
            $_POST['email']    = $email;
        }
    }
}

/** ============= HTML (Portal) ============= */

// Clases CSS según si el formulario debe estar visible o no
$formPanelClass   = $formShouldBeVisible ? '' : 'hidden';
$mainCardExtraCls = $formShouldBeVisible ? '' : 'full-left';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internet Wi-Fi - GoNet</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'SF Pro Display', Roboto, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #022c22 0%, #065f46 45%, #10b981 100%);
            color: #022c22;
            min-height: 100vh;
        }

        .page-wrapper {
            position: relative;
            min-height: 100vh;
            overflow: hidden;
        }

        /* SLIDER DE PROMOS */
        .promo-slider {
            position: absolute;
            inset: 0;
            z-index: 1;
        }

        .promo-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 1s ease-in-out;
            background-position: center;
            background-size: cover;
            background-repeat: no-repeat;
            filter: brightness(0.75);
        }

        .promo-slide.active {
            opacity: 1;
        }

        .overlay {
            position: relative;
            z-index: 2;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .main-card {
            width: 100%;
            max-width: 900px;
            background: rgba(226, 252, 236, 0.95);
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(16, 185, 129, 0.35);
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.2fr);
            gap: 24px;
        }

        .main-card.full-left {
            grid-template-columns: 1fr;
        }

        @media (max-width: 768px) {
            .main-card {
                grid-template-columns: 1fr;
            }
        }

        .welcome-side {
            display: flex;
            flex-direction: column;
            gap: 12px;
            color: #022c22;
        }

        .logo {
            width: 190px;
            margin-bottom: 6px;
        }

        .welcome-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #022c22;
        }

        .welcome-subtitle {
            font-size: 0.95rem;
            color: #064e3b;
        }

        .badge-ssid {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.12);
            color: #047857;
            font-size: 0.8rem;
            margin-top: 4px;
        }

        .badge-ssid span {
            font-weight: 600;
        }

        .btn-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .btn {
            border-radius: 999px;
            padding: 10px 20px;
            font-size: 0.95rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
            white-space: nowrap;
        }

        .btn-primary {
            background: #00a870;
            color: #ecfdf5;
            box-shadow: 0 12px 30px rgba(16, 185, 129, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 40px rgba(16, 185, 129, 0.6);
            background: #059669;
        }

        .btn-secondary {
            background: #dcfce7;
            color: #065f46;
            border: 1px solid rgba(34, 197, 94, 0.7);
        }

        .btn-secondary:hover {
            transform: translateY(-1px);
            background: #bbf7d0;
        }

        .contact-block {
            margin-top: 16px;
            padding: 12px 12px 10px;
            border-radius: 16px;
            background: #ecfdf5;
            border: 1px solid rgba(22, 163, 74, 0.35);
        }

        .contact-block h3 {
            font-size: 0.95rem;
            color: #064e3b;
            margin-bottom: 4px;
        }

        .contact-block p {
            font-size: 0.85rem;
            color: #047857;
        }

        .whatsapp-list {
            margin-top: 6px;
            font-size: 0.85rem;
        }

        .whatsapp-list a {
            color: #15803d;
            text-decoration: none;
        }

        .whatsapp-list a:hover {
            text-decoration: underline;
        }

        .more-info {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #065f46;
        }

        .link-more {
            border: none;
            background: none;
            padding: 0;
            margin-left: 4px;
            color: #0284c7;
            font-size: 0.8rem;
            text-decoration: underline;
            cursor: pointer;
        }

        .link-more:hover {
            color: #0369a1;
        }

        .form-side {
            background: #ecfdf5;
            border-radius: 18px;
            padding: 18px 16px 14px;
            border: 1px solid rgba(22, 163, 74, 0.4);
        }

        .form-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: #064e3b;
            margin-bottom: 6px;
        }

        .form-subtitle {
            font-size: 0.8rem;
            color: #047857;
            margin-bottom: 10px;
        }

        .info-display {
            background: #d1fae5;
            border-radius: 12px;
            padding: 8px 10px;
            font-size: 0.8rem;
            color: #065f46;
            margin-bottom: 8px;
            text-align: left;
        }

        .error-mac {
            background: #fee2e2;
            border-radius: 12px;
            padding: 10px;
            font-size: 0.85rem;
            color: #b91c1c;
            margin-bottom: 8px;
        }

        .form-group {
            margin-bottom: 10px;
        }

        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #064e3b;
            margin-bottom: 4px;
        }

        input[type="text"],
        input[type="tel"],
        input[type="email"] {
            width: 100%;
            padding: 9px 10px;
            border-radius: 10px;
            border: 1px solid rgba(22, 163, 74, 0.6);
            font-size: 0.9rem;
            background: #ffffff;
            color: #022c22;
        }

        input::placeholder {
            color: #6b7280;
        }

        input:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.5);
        }

        .field-error {
            color: #b91c1c;
            font-size: 0.75rem;
            margin-top: 3px;
        }

        .error {
            background: #fee2e2;
            color: #b91c1c;
            padding: 8px 10px;
            border-radius: 10px;
            margin: 6px 0;
            text-align: center;
            font-size: 0.8rem;
        }

        .terms-row {
            margin-top: 4px;
            font-size: 0.78rem;
            color: #064e3b;
            display: flex;
            gap: 6px;
        }

        .terms-row a {
            color: #0284c7;
            text-decoration: underline;
        }

        .terms-row a:hover {
            color: #0369a1;
        }

        .submit-row {
            margin-top: 10px;
        }

        .submit-row button {
            width: 100%;
        }

        .hidden {
            display: none;
        }
    </style>
</head>
<body>
<div class="page-wrapper">

    <!-- SLIDER DE 5 PROMOS (CAMBIA LAS IMÁGENES A LAS TUYAS) -->
    <div class="promo-slider">
        <div class="promo-slide active" style="background-image: url('promo1.jpg');"></div>
        <div class="promo-slide" style="background-image: url('promo2.jpg');"></div>
        <div class="promo-slide" style="background-image: url('promo3.jpg');"></div>
        <div class="promo-slide" style="background-image: url('promo4.jpg');"></div>
        <div class="promo-slide" style="background-image: url('promo5.jpg');"></div>
    </div>

    <div class="overlay">
        <div class="main-card <?php echo $mainCardExtraCls; ?>" id="main-card">
            <!-- LADO IZQUIERDO: BIENVENIDA / BOTONES / WHATSAPP -->
            <div class="welcome-side">
                <img src="gonetlogo.png" alt="GoNet" class="logo">

                <div class="badge-ssid">
                    <span>Wi-Fi GoNet</span>
                    <span>•</span>
                    <span><?php echo htmlspecialchars($ssidName ?: 'Red invitado'); ?></span>
                </div>

                <h1 class="welcome-title">
                    Bienvenidos al Internet de GoNet
                </h1>
                <p class="welcome-subtitle">
                    Disfruta de una conexión rápida y estable mientras navegas, trabajas o te entretienes.
                </p>

                <div class="btn-row">
                    <!-- QUICK CONNECT: conecta sin formulario -->
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('quick-connect-form').submit();">
                        NAVEGAR
                    </button>

                    <!-- BOTÓN WHATSAPP (CAMBIA EL NÚMERO) -->
                    <a class="btn btn-secondary" href="https://wa.me/593900000000" target="_blank" rel="noopener">
                        Comunícate con nosotros
                    </a>
                </div>

                <div class="contact-block">
                    <h3>Soporte y ventas GoNet</h3>
                    <p>Si necesitas ayuda o quieres contratar nuestros servicios, estamos para ayudarte.</p>
                    <div class="whatsapp-list">
                        WhatsApp:
                        <!-- CAMBIA ESTOS NÚMEROS A LOS REALES -->
                        <div><a href="https://wa.me/593900000001" target="_blank">+593 9 0000 0001</a></div>
                        <div><a href="https://wa.me/593900000002" target="_blank">+593 9 0000 0002</a></div>
                    </div>
                </div>

                <div class="more-info">
                    Para más información
                    <button type="button" class="link-more" onclick="showForm();">
                        aplasta aquí
                    </button>.
                </div>

                <?php if (!empty($errors['mac'])): ?>
                    <div class="error" style="margin-top:10px;">
                        <?php echo htmlspecialchars($errors['mac']); ?>
                    </div>
                <?php endif; ?>

                <!-- FORMULARIO OCULTO PARA QUICK CONNECT (SIN DATOS PERSONALES) -->
                <form id="quick-connect-form" method="POST" class="hidden">
                    <input type="hidden" name="quick_connect" value="1">
                    <input type="hidden" name="mac"          value="<?php echo htmlspecialchars($mac_raw); ?>">
                    <input type="hidden" name="ap_mac"       value="<?php echo htmlspecialchars($ap_raw); ?>">
                    <input type="hidden" name="ip"           value="<?php echo htmlspecialchars($ip); ?>">
                    <input type="hidden" name="ssidName"     value="<?php echo htmlspecialchars($ssidName); ?>">
                    <input type="hidden" name="radioId"      value="<?php echo htmlspecialchars($radioId); ?>">
                    <input type="hidden" name="site"         value="<?php echo htmlspecialchars($site); ?>">
                    <input type="hidden" name="redirect_url" value="<?php echo htmlspecialchars($redirect_url); ?>">
                </form>
            </div>

            <!-- LADO DERECHO: FORMULARIO DE REGISTRO (OPCIONAL, SOLO SI APRIETA "aplasta aquí") -->
            <div class="form-side <?php echo $formPanelClass; ?>" id="form-panel">
                <div class="form-title">Registro rápido</div>
                <div class="form-subtitle">
                    Completa tus datos para activar tu acceso a Internet.
                </div>

                <?php if ($mac_norm === ''): ?>
                    <div class="error-mac">
                        ❌ No se detectó correctamente tu dispositivo.<br>
                        <small>Por favor, desconéctate y vuelve a conectarte a la red Wi-Fi.</small>
                    </div>
                <?php else: ?>
                    <div class="info-display">
                        Tu dispositivo está listo para ser registrado. Solo necesitamos algunos datos.
                    </div>

                    <?php if (!empty($errors['mac']) && $formShouldBeVisible): ?>
                        <div class="error"><?php echo htmlspecialchars($errors['mac']); ?></div>
                    <?php endif; ?>

                    <form id="form-wifi-gonet" method="POST" autocomplete="on" novalidate>
                        <div class="form-group">
                            <label>Nombre *</label>
                            <input type="text" name="nombre" required
                                   value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                                   placeholder="Ingresa tu nombre">
                            <?php if (!empty($errors['nombre'])): ?>
                                <div class="field-error"><?php echo $errors['nombre']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Apellido *</label>
                            <input type="text" name="apellido" required
                                   value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>"
                                   placeholder="Ingresa tu apellido">
                            <?php if (!empty($errors['apellido'])): ?>
                                <div class="field-error"><?php echo $errors['apellido']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Cédula (10 dígitos) *</label>
                            <input type="text" name="cedula" inputmode="numeric" required
                                   value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>"
                                   placeholder="Ej: 0102030405">
                            <?php if (!empty($errors['cedula'])): ?>
                                <div class="field-error"><?php echo $errors['cedula']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Teléfono (09XXXXXXXX) *</label>
                            <input type="tel" name="telefono" inputmode="tel" required
                                   value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>"
                                   placeholder="Ej: 09XXXXXXXX">
                            <?php if (!empty($errors['telefono'])): ?>
                                <div class="field-error"><?php echo $errors['telefono']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" required
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   placeholder="tucorreo@ejemplo.com">
                            <?php if (!empty($errors['email'])): ?>
                                <div class="field-error"><?php echo $errors['email']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="terms-row">
                                <input type="checkbox" name="terminos" required
                                    <?php echo isset($_POST['terminos']) ? 'checked' : ''; ?>>
                                <span>
                                    Acepto los
                                    <a href="https://gonet.ec/terminos" target="_blank" rel="noopener">
                                        términos y condiciones
                                    </a>.
                                </span>
                            </label>
                            <?php if (!empty($errors['terminos'])): ?>
                                <div class="field-error"><?php echo $errors['terminos']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Hidden: MAC/AP/SSID/SITE/REDIRECT -->
                        <input type="hidden" name="mac"          value="<?php echo htmlspecialchars($mac_raw); ?>">
                        <input type="hidden" name="ap_mac"       value="<?php echo htmlspecialchars($ap_raw); ?>">
                        <input type="hidden" name="ip"           value="<?php echo htmlspecialchars($ip); ?>">
                        <input type="hidden" name="ssidName"     value="<?php echo htmlspecialchars($ssidName); ?>">
                        <input type="hidden" name="radioId"      value="<?php echo htmlspecialchars($radioId); ?>">
                        <input type="hidden" name="site"         value="<?php echo htmlspecialchars($site); ?>">
                        <input type="hidden" name="redirect_url" value="<?php echo htmlspecialchars($redirect_url); ?>">

                        <div class="submit-row">
                            <button type="submit" class="btn btn-primary">
                                NAVEGAR
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // Slider simple de 5 imágenes
    (function() {
        const slides = document.querySelectorAll('.promo-slide');
        if (!slides.length) return;
        let current = 0;

        function showSlide(index) {
            slides.forEach((s, i) => {
                s.classList.toggle('active', i === index);
            });
        }

        setInterval(() => {
            current = (current + 1) % slides.length;
            showSlide(current);
        }, 5000); // cada 5 segundos
    })();

    // Mostrar el formulario cuando se hace click en "aplasta aquí"
    function showForm() {
        const formPanel = document.getElementById('form-panel');
        const mainCard  = document.getElementById('main-card');
        if (formPanel && mainCard) {
            formPanel.classList.remove('hidden');
            mainCard.classList.remove('full-left');
            formPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
</script>
</body>
</html>
