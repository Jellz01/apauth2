<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ============ CONFIG OMADA ============ */

const OMADA_CONTROLLER    = '10.0.0.10';      // <-- CAMBIA ESTO
const OMADA_PORT          = 8043;             // <-- CAMBIA SI USAS OTRO
const OMADA_CONTROLLER_ID = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; // <-- CAMBIA ESTO
const OMADA_OP_USER       = 'portal-operator'; // <-- CAMBIA ESTO
const OMADA_OP_PASS       = 'S3cret!';         // <-- CAMBIA ESTO
const OMADA_SITE          = 'Default';         // <-- CAMBIA SI TU SITE TIENE OTRO NOMBRE

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
   ?clientMac=...&clientIp=...&apMac=...&ssidName=...&radioId=0&site=Default&redirectUrl=...
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
        "site"     => OMADA_SITE, // algunos ejemplos no lo ponen, pero no estorba
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

/** ============= Manejo POST (form) ============= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("📨 PROCESANDO FORMULARIO POST");

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

    // Validaciones (MAC solo interna)
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

            // === AHORA: LLAMAR A OMADA PARA AUTORIZAR DISPOSITIVO ===

            // 1) Login Hotspot API
            if (!omada_hotspot_login()) {
                error_log("⚠️ Omada hotspot login falló, redirigiendo igual");
                if (!empty($redirect_url)) {
                    header("Location: " . $redirect_url);
                } else {
                    header("Location: https://www.google.com");
                }
                exit;
            }

            // 2) Autorizar cliente (por ejemplo 120 minutos)
            omada_authorize_client(
                $mac_norm,
                $ap_mac_norm,
                $ssidName,
                $radioId,
                $site,
                120
            );

            // 3) Redirigir a la URL original que el user quería
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

/** ============= HTML (Portal) ============= */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro Wi-Fi - GoNet</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Arial', sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            padding: 20px; 
        }
        .form-container { 
            background: white; 
            padding: 30px 25px; 
            border-radius: 20px; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.2); 
            width: 100%; 
            max-width: 450px; 
            margin: 20px 0; 
        }
        h2 { 
            color: #2c3e50; 
            text-align: center; 
            margin-bottom: 25px; 
            font-size: 1.8rem; 
        }
        .form-group { margin-bottom: 16px; }
        label { 
            display: block; 
            font-weight: 600; 
            margin-bottom: 6px; 
        }
        input { 
            width: 100%; 
            padding: 12px; 
            border: 2px solid #e1e8ed; 
            border-radius: 12px; 
            font-size: 1rem; 
        }
        input:focus { 
            outline: none; 
            border-color: #667eea; 
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); 
        }
        button { 
            width: 100%; 
            padding: 14px; 
            background: linear-gradient(135deg, #667eea, #764ba2); 
            color: white; 
            border: none; 
            border-radius: 12px; 
            font-size: 1.05rem; 
            font-weight: 600; 
            cursor: pointer; 
            margin-top: 10px; 
            transition: all 0.3s ease; 
        }
        button:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4); 
        }
        .error { 
            background: #ffebee; 
            color: #c62828; 
            padding: 12px; 
            border-radius: 10px; 
            margin: 15px 0; 
            text-align: center; 
        }
        .field-error { 
            color: #c62828; 
            font-size: 0.85rem; 
            margin-top: 6px; 
        }
        .info-display { 
            background: #e3f2fd; 
            padding: 12px; 
            border-radius: 10px; 
            margin: 10px 0; 
            font-size: 0.9rem; 
            color: #1565c0; 
            text-align: center; 
        }
    </style>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet Logo" style="width: 100%; max-width: 400px; border-radius: 15px; margin: 10px 0;">

    <div class="form-container">
        <h2>Registro para Wi-Fi 🌐</h2>

        <?php if ($mac_norm === ''): ?>
            <div class="error">
                ❌ No se detectó correctamente tu dispositivo.<br>
                <small>Intenta reconectarte a la red Wi-Fi.</small>
            </div>
        <?php else: ?>
            <div class="info-display">
                📝 Completa el formulario para activar tu acceso a Internet.
            </div>

            <form method="POST" autocomplete="on" novalidate>
                <div class="form-group">
                    <label><strong>Nombre *</strong></label>
                    <input type="text" name="nombre" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
                    <?php if (!empty($errors['nombre'])): ?><div class="field-error"><?php echo $errors['nombre']; ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label><strong>Apellido *</strong></label>
                    <input type="text" name="apellido" required value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>">
                    <?php if (!empty($errors['apellido'])): ?><div class="field-error"><?php echo $errors['apellido']; ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label><strong>Cédula (10 dígitos) *</strong></label>
                    <input type="text" name="cedula" inputmode="numeric" required value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>">
                    <?php if (!empty($errors['cedula'])): ?><div class="field-error"><?php echo $errors['cedula']; ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label><strong>Teléfono (09XXXXXXXX) *</strong></label>
                    <input type="tel" name="telefono" inputmode="tel" required value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
                    <?php if (!empty($errors['telefono'])): ?><div class="field-error"><?php echo $errors['telefono']; ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label><strong>Email *</strong></label>
                    <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    <?php if (!empty($errors['email'])): ?><div class="field-error"><?php echo $errors['email']; ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="terminos" required <?php echo isset($_POST['terminos']) ? 'checked' : ''; ?>>
                        <strong>Acepto Términos y Condiciones *</strong>
                    </label>
                    <?php if (!empty($errors['terminos'])): ?><div class="field-error"><?php echo $errors['terminos']; ?></div><?php endif; ?>
                </div>

                <!-- Hidden: el usuario no ve MAC/AP/SSID/SITE/REDIRECT -->
                <input type="hidden" name="mac"          value="<?php echo htmlspecialchars($mac_raw); ?>">
                <input type="hidden" name="ap_mac"       value="<?php echo htmlspecialchars($ap_raw); ?>">
                <input type="hidden" name="ip"           value="<?php echo htmlspecialchars($ip); ?>">
                <input type="hidden" name="ssidName"     value="<?php echo htmlspecialchars($ssidName); ?>">
                <input type="hidden" name="radioId"      value="<?php echo htmlspecialchars($radioId); ?>">
                <input type="hidden" name="site"         value="<?php echo htmlspecialchars($site); ?>">
                <input type="hidden" name="redirect_url" value="<?php echo htmlspecialchars($redirect_url); ?>">

                <button type="submit">🚀 Conectar a Internet</button>
            </form>
        <?php endif; ?>
    </div>

    <img src="banner.png" alt="Banner" style="width: 100%; max-width: 400px; border-radius: 15px; margin: 10px 0;">
</body>
</html>
