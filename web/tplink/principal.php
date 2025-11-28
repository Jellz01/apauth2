<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log file para debug extensivo
$debug_log = "/tmp/portal_debug.log";
file_put_contents($debug_log, "======= NUEVA SESIÓN PORTAL =======\n", FILE_APPEND);

function debug_log($message) {
    global $debug_log;
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message\n";
    file_put_contents($debug_log, $log_message, FILE_APPEND);
    error_log($message); // También al error log normal
}

debug_log("🎬 SCRIPT INICIADO");

/* ===================== DB CONFIG ===================== */
$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";

/* ===================== AP / RADIUS CONFIG ===================== */
define('AP_IP', '192.168.0.7');
define('RADIUS_SECRET', 'telecom');
define('COA_PORT', '3799');

/* =========================================================
 * HELPER: normalizar MAC (quitar :, -, ., espacios)
 * ========================================================= */
function normalize_mac($mac_raw) {
    if (empty($mac_raw)) {
        debug_log("❌ MAC raw está vacía");
        return '';
    }
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac_raw);
    $result = strtoupper($hex);
    debug_log("🔧 MAC normalizada: '$mac_raw' -> '$result'");
    return $result;
}

/* =========================================================
 * HELPER: extraer solo IP si viene "192.168.0.9:22080"
 * ========================================================= */
function only_ip_part($str) {
    if (!$str) {
        debug_log("❌ IP string vacía");
        return '';
    }
    if (strpos($str, ':') !== false) {
        $parts = explode(':', $str);
        $result = $parts[0];
        debug_log("🔧 IP extraída: '$str' -> '$result'");
        return $result;
    }
    debug_log("🔧 IP limpia: '$str'");
    return $str;
}

/* =========================================================
 * REDIRECCIONES
 * ========================================================= */
function redirect_to_bienvenido($mac_norm, $ip) {
    debug_log("🔄 Redirigiendo a bienvenido.php - MAC: $mac_norm, IP: $ip");
    $_SESSION['registration_mac'] = $mac_norm;
    $_SESSION['registration_ip']  = $ip;
    $_SESSION['coa_executed']     = false;

    $bienvenido_url = 'bienvenido.php';

    if (!headers_sent()) {
        header("Location: " . $bienvenido_url);
        exit;
    } else {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="refresh" content="0;url=' . htmlspecialchars($bienvenido_url) . '">
        </head>
        <body>
            <p>Redireccionando... <a href="' . htmlspecialchars($bienvenido_url) . '">Click aquí</a></p>
            <script>window.location.href = "' . htmlspecialchars($bienvenido_url) . '";</script>
        </body>
        </html>';
        exit;
    }
}

function redirect_to_tyc($mac_norm, $ip) {
    debug_log("🔄 Redirigiendo a tyc.php - MAC: $mac_norm, IP: $ip");
    $_SESSION['registration_mac'] = $mac_norm;
    $_SESSION['registration_ip']  = $ip;

    $tyc_url = 'tyc.php';

    if (!headers_sent()) {
        header("Location: " . $tyc_url);
        exit;
    } else {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="refresh" content="0;url=' . htmlspecialchars($tyc_url) . '">
        </head>
        <body>
            <p>Redireccionando... <a href="' . htmlspecialchars($tyc_url) . '">Click aquí</a></p>
            <script>window.location.href = "' . htmlspecialchars($tyc_url) . '";</script>
        </body>
        </html>';
        exit;
    }
}

/* =========================================================
 * CoA opcional (por si quieres forzar reautenticación)
 * ========================================================= */
function trigger_coa_disconnect($mac) {
    if (empty($mac)) {
        debug_log("❌ trigger_coa_disconnect: MAC vacía");
        return false;
    }

    $ap_ip = AP_IP;
    $secret = RADIUS_SECRET;
    $port = COA_PORT;

    // Forzar una desconexión (re-auth) vía radclient
    $cmd = sprintf(
        'echo "User-Name=%s" | radclient -r 2 -t 3 -x %s:%s disconnect %s >> /tmp/coa.log 2>&1 &',
        escapeshellarg($mac),
        escapeshellarg($ap_ip),
        escapeshellarg($port),
        escapeshellarg($secret)
    );

    debug_log("🚀 Lanzando CoA: $cmd");
    @exec($cmd);
    return true;
}

/* =========================================================
 * (Legacy) Intento de autorización directa al AP (standalone)
 * En Omada no es necesario; dejamos como fallback.
 * ========================================================= */
function tplink_authorize_client($clientMac, $apMac = '', $ssid = '', $token = '') {
    debug_log("🔐 Intentando autorización directa TP-Link - MAC: $clientMac, AP: $apMac, SSID: $ssid");
    
    $ap_ip = AP_IP;
    $endpoints = [
        "http://{$ap_ip}/portal_auth.cgi",
        "http://{$ap_ip}/cgi-bin/portal_auth",
        "http://{$ap_ip}/login",
    ];

    $payload = [
        'clientMac' => $clientMac,
        'success'   => 'true',
        'authType'  => 'radius',
    ];

    if ($apMac) $payload['apMac'] = $apMac;
    if ($ssid)  $payload['ssid']  = $ssid;
    if ($token) $payload['token'] = $token;

    debug_log("📦 Payload TP-Link: " . json_encode($payload));

    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        debug_log("🔍 Endpoint TP-Link: $url → HTTP $http_code → Resp: $resp → Error: $err");
        if ($http_code >= 200 && $http_code < 300) {
            debug_log("✅ Cliente autorizado en TP-Link: $url");
            return true;
        }
    }

    debug_log("⚠️ No se pudo notificar al AP directamente (fallback).");
    return false;
}

/* =========================================================
 * 🔑 CLAVE: Omada Controller /portal/radius/browserauth
 * Tu portal debe POSTEAR aquí para que Omada llame a RADIUS.
 * ========================================================= */
function omada_radius_browserauth($target, $targetPort, $username, $password, $clientMac, $clientIp, $apMac, $ssidName, $radioId, $originUrl) {
    if (!$target || !$targetPort) {
        debug_log("❌ browserauth: faltan target/targetPort");
        return false;
    }

    // Preferir HTTPS si targetPort es 8043; si no, HTTP
    $scheme = ($targetPort == '8043' || $targetPort == 8043) ? "https" : "http";
    $url = "{$scheme}://{$target}:{$targetPort}/portal/radius/browserauth";

    debug_log("🎯 BROWSERAUTH URL COMPLETA: $url");

    // x-www-form-urlencoded evita CORS y es lo esperado por Omada
    $fields = [
        'username'   => $username,
        'password'   => $password,
        'clientMac'  => $clientMac,
        'clientIp'   => $clientIp,
        'apMac'      => $apMac,
        'ssidName'   => $ssidName,
        'radioId'    => ($radioId === '' ? '0' : $radioId),
        'originUrl'  => $originUrl,
    ];

    $postData = http_build_query($fields);
    
    debug_log("📤 BROWSERAUTH PAYLOAD:");
    debug_log("  URL: $url");
    debug_log("  Username: $username");
    debug_log("  Password: $password");
    debug_log("  ClientMac: $clientMac");
    debug_log("  ClientIp: $clientIp");
    debug_log("  ApMac: $apMac");
    debug_log("  SsidName: $ssidName");
    debug_log("  RadioId: $radioId");
    debug_log("  OriginUrl: $originUrl");
    debug_log("  Full POST Data: $postData");

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        // En laboratorios locales, el cert puede ser self-signed:
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_VERBOSE        => true,
        CURLOPT_HEADER         => true,
    ]);
    
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    
    // Log verbose output
    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    fclose($verbose);
    
    curl_close($ch);

    debug_log("📡 BROWSERAUTH RESPUESTA:");
    debug_log("  HTTP Code: $code");
    debug_log("  Response: $resp");
    debug_log("  Error: $err");
    debug_log("  Verbose Output: $verboseLog");

    $success = ($code >= 200 && $code < 300);
    if ($success) {
        debug_log("✅ BROWSERAUTH EXITOSO");
    } else {
        debug_log("❌ BROWSERAUTH FALLIDO");
    }
    
    return $success;
}

/* =========================================================
 * CONEXIÓN BD
 * ========================================================= */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    debug_log("✅ CONEXIÓN BD EXITOSA");
} catch (Exception $e) {
    debug_log("❌ ERROR CONEXIÓN BD: " . $e->getMessage());
    die("<div class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</div>");
}

/* =========================================================
 * LOG TODOS LOS PARÁMETROS RECIBIDOS
 * ========================================================= */
debug_log("========== NUEVA PETICIÓN ==========");
debug_log("📋 MÉTODO: " . $_SERVER['REQUEST_METHOD']);
debug_log("🔗 URL COMPLETA: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
debug_log("📥 GET: " . json_encode($_GET));
debug_log("📤 POST: " . json_encode($_POST));
debug_log("🌐 REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));
debug_log("🔀 HTTP_X_FORWARDED_FOR: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'N/A'));
debug_log("👤 HTTP_USER_AGENT: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'));

// Log todos los headers
debug_log("📨 HEADERS COMPLETOS:");
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        debug_log("  $key: $value");
    }
}

/* =========================================================
 * PARÁMETROS QUE MANDA OMADA AL PORTAL
 * ========================================================= */
$client_mac_raw = $_GET['clientMac'] ?? $_POST['clientMac'] ?? $_GET['mac'] ?? $_POST['mac'] ?? '';
$ap_mac_raw     = $_GET['ap'] ?? $_POST['ap'] ?? $_GET['apMac'] ?? $_POST['apMac'] ?? '';
$ap_ip_raw      = $_GET['target'] ?? $_POST['target'] ?? $_GET['ip'] ?? $_POST['ip'] ?? AP_IP;
$token          = $_GET['token'] ?? $_POST['token'] ?? '';
$ssid           = $_GET['ssid'] ?? $_POST['ssid'] ?? '';
$redirect_url   = $_GET['url'] ?? $_POST['url'] ?? $_GET['redirect'] ?? '';

/* Omada controller params explícitos */
$target         = $_GET['target']     ?? $_POST['target']     ?? '';
$targetPort     = $_GET['targetPort'] ?? $_POST['targetPort'] ?? '8088';
$radioId        = $_GET['radioId']    ?? $_POST['radioId']    ?? '0';
$ssidName       = $_GET['ssidName']   ?? $_POST['ssidName']   ?? ($ssid ?: '');
$originUrl      = $_GET['originUrl']  ?? $_POST['originUrl']  ?? $redirect_url;
$clientIp_om    = $_GET['clientIp']   ?? $_POST['clientIp']   ?? ($_SERVER['REMOTE_ADDR'] ?? '');

/* Normalizaciones */
$mac_norm   = normalize_mac($client_mac_raw);
$ap_norm    = normalize_mac($ap_mac_raw);
$ap_ip      = only_ip_part($ap_ip_raw) ?: AP_IP;
$client_ip  = $_SERVER['REMOTE_ADDR'] ?? '';

debug_log("🎯 PARÁMETROS PROCESADOS:");
debug_log("  - MAC Cliente Raw: '$client_mac_raw'");
debug_log("  - MAC Cliente Norm: '$mac_norm'");
debug_log("  - AP MAC Raw: '$ap_mac_raw'");
debug_log("  - AP MAC Norm: '$ap_norm'");
debug_log("  - AP IP (UI): '$ap_ip'");
debug_log("  - Cliente IP (server): '$client_ip'");
debug_log("  - Cliente IP (Omada): '$clientIp_om'");
debug_log("  - Omada target: '$target'");
debug_log("  - Omada targetPort: '$targetPort'");
debug_log("  - SSID: '$ssid'");
debug_log("  - SSID Name: '$ssidName'");
debug_log("  - radioId: '$radioId'");
debug_log("  - originUrl: '$originUrl'");
debug_log("  - Token: '$token'");

$errors = [
    'nombre'   => '',
    'apellido' => '',
    'cedula'   => '',
    'telefono' => '',
    'email'    => '',
    'terminos' => ''
];

/* =========================================================
 *  RESOLVER ZONA POR AP
 * ========================================================= */
$zona_codigo = '';
$zona_nombre = '';
$zona_banner = '';

if ($ap_norm !== '') {
    try {
        debug_log("🔍 Buscando zona por AP MAC: $ap_norm");
        $stmtZ = $conn->prepare("
            SELECT z.codigo, z.nombre, z.banner_url
            FROM wifi_zona_aps a
            JOIN wifi_zonas z ON a.zona_codigo = z.codigo
            WHERE a.ap_mac = ?
            LIMIT 1
        ");
        $stmtZ->bind_param("s", $ap_norm);
        $stmtZ->execute();
        $resZ = $stmtZ->get_result();
        if ($rowZ = $resZ->fetch_assoc()) {
            $zona_codigo = $rowZ['codigo'];
            $zona_nombre = $rowZ['nombre'];
            $zona_banner = $rowZ['banner_url'];
            debug_log("✅ Zona detectada por AP_MAC: $ap_norm → {$zona_codigo} ({$zona_nombre})");
        } else {
            debug_log("⚠️ No se encontró zona para AP MAC: $ap_norm");
        }
        $stmtZ->close();
    } catch (Exception $e) {
        debug_log("❌ Error buscando zona por AP: " . $e->getMessage());
    }
} else {
    debug_log("⚠️ No hay AP MAC para buscar zona");
}

$_SESSION['wifi_zona_codigo'] = $zona_codigo;
$_SESSION['wifi_zona_nombre'] = $zona_nombre;
$_SESSION['wifi_zona_banner'] = $zona_banner;

/* =========================================================
 * VERIFICAR ESTADO ACTUAL DEL CLIENTE
 * ========================================================= */
$mac_status = 'new';
$client_exists = false;

if ($mac_norm !== '') {
    try {
        debug_log("🔍 Verificando estado del cliente: $mac_norm");
        
        // ¿ya autorizado en radcheck?
        $check_radcheck = $conn->prepare("
            SELECT id FROM radcheck 
            WHERE username = ? AND (
                (attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept') OR
                (attribute = 'Cleartext-Password' AND op = ':=')
            )
            LIMIT 1
        ");
        $check_radcheck->bind_param("s", $mac_norm);
        $check_radcheck->execute();
        $has_rc = $check_radcheck->get_result()->num_rows > 0;
        $check_radcheck->close();

        if ($has_rc) {
            $mac_status = 'registered';
            debug_log("✅ Cliente ya registrado en radcheck: $mac_norm");
        } else {
            debug_log("⚠️ Cliente NO encontrado en radcheck: $mac_norm");
        }

        // ¿existe en clients?
        $check_clients = $conn->prepare("SELECT id FROM clients WHERE mac = ? LIMIT 1");
        $check_clients->bind_param("s", $mac_norm);
        $check_clients->execute();
        $client_exists = $check_clients->get_result()->num_rows > 0;
        $check_clients->close();

        if ($client_exists) {
            debug_log("✅ Cliente encontrado en tabla clients: $mac_norm");
        } else {
            debug_log("⚠️ Cliente NO encontrado en tabla clients: $mac_norm");
        }

    } catch (Exception $e) {
        debug_log("❌ Error verificando estado: " . $e->getMessage());
    }

    // Si ya está registrado, lanzar browserauth hacia Omada y redirigir
    if ($mac_status === 'registered') {
        debug_log("🔄 Cliente ya registrado → invocando browserauth hacia Omada");

        // Usamos MAC=usuario y MAC=clave (coherente con Cleartext-Password o dummy)
        $u = $mac_norm; 
        $p = $mac_norm;

        debug_log("🔐 Credenciales para browserauth - User: $u, Pass: $p");

        $ok = omada_radius_browserauth(
            $target, $targetPort, $u, $p, $mac_norm, ($clientIp_om ?: $client_ip),
            $ap_norm, ($ssidName ?: $ssid), $radioId, $originUrl
        );

        // (Opcional) CoA como empujón extra
        if (!$ok) {
            debug_log("⚠️ browserauth falló, intento CoA/fallback");
            tplink_authorize_client($mac_norm, $ap_norm, ($ssidName ?: $ssid), $token);
            trigger_coa_disconnect($mac_norm);
        }

        redirect_to_tyc($mac_norm, $client_ip);
    }
} else {
    debug_log("❌ No hay MAC para verificar estado");
}

/* =========================================================
 * MANEJO POST (registro)
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nombre'])) {
    debug_log("📝 Procesando formulario de registro POST");

    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = preg_replace('/\D+/', '', $_POST['cedula'] ?? '');
    $telefono = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $terminos = isset($_POST['terminos']) ? 1 : 0;

    debug_log("📋 Datos del formulario:");
    debug_log("  Nombre: $nombre");
    debug_log("  Apellido: $apellido");
    debug_log("  Cédula: $cedula");
    debug_log("  Teléfono: $telefono");
    debug_log("  Email: $email");
    debug_log("  Términos: $terminos");

    // Validaciones
    if ($nombre === '')   $errors['nombre']   = 'Ingresa tu nombre.';
    if ($apellido === '') $errors['apellido'] = 'Ingresa tu apellido.';
    if (!validarCedulaEC($cedula))   $errors['cedula']   = 'Cédula inválida.';
    if (!validarTelefonoEC($telefono)) $errors['telefono'] = 'Teléfono inválido.';
    if (!validarEmailReal($email))   $errors['email']    = 'Correo inválido.';
    if (!$terminos)                  $errors['terminos'] = 'Debes aceptar los términos.';

    $hayErrores = array_filter($errors, fn($e) => $e !== '');
    debug_log("❌ Errores de validación: " . json_encode($errors));

    if (!$hayErrores && $mac_norm !== '') {
        try {
            debug_log("🔄 Iniciando transacción de registro para: $mac_norm");
            $conn->begin_transaction();

            // ¿ya existe en radcheck?
            $check = $conn->prepare("SELECT id FROM radcheck WHERE username = ? LIMIT 1");
            $check->bind_param("s", $mac_norm);
            $check->execute();
            $existe_rc = $check->get_result()->num_rows > 0;
            $check->close();

            debug_log("📊 Cliente en radcheck: " . ($existe_rc ? 'EXISTE' : 'NO EXISTE'));

            if (!$existe_rc) {
                // Insert en clients
                $stmt_clients = $conn->prepare("
                    INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, ap_mac, enabled)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt_clients->bind_param("sssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac_norm, $ap_norm);
                $stmt_clients->execute();
                $stmt_clients->close();
                debug_log("✅ Cliente insertado en tabla clients");

                // Insert en radcheck con Cleartext-Password
                $stmt_rc = $conn->prepare("
                    INSERT INTO radcheck (username, attribute, op, value)
                    VALUES (?, 'Cleartext-Password', ':=', ?)
                ");
                $stmt_rc->bind_param("ss", $mac_norm, $mac_norm);
                $stmt_rc->execute();
                $stmt_rc->close();
                debug_log("✅ Cliente autorizado en radcheck");
            } else {
                debug_log("⚠️ Cliente ya existía en radcheck, solo actualizando clients");
                
                // Actualizar tabla clients si existe
                $update_clients = $conn->prepare("
                    UPDATE clients SET nombre=?, apellido=?, cedula=?, telefono=?, email=?, ap_mac=?
                    WHERE mac=?
                ");
                $update_clients->bind_param("sssssss", $nombre, $apellido, $cedula, $telefono, $email, $ap_norm, $mac_norm);
                $update_clients->execute();
                $update_clients->close();
                debug_log("✅ Cliente actualizado en tabla clients");
            }

            $conn->commit();
            debug_log("✅ Transacción completada exitosamente");

            // 🔔 CLAVE: invocar browserauth (Omada → RADIUS)
            $u = $mac_norm; 
            $p = $mac_norm; // coherente con Cleartext-Password
            
            debug_log("🚀 Invocando browserauth después del registro");
            debug_log("🔐 Credenciales: User=$u, Pass=$p");

            $ok = omada_radius_browserauth(
                $target, $targetPort, $u, $p, $mac_norm, ($clientIp_om ?: $client_ip),
                $ap_norm, ($ssidName ?: $ssid), $radioId, $originUrl
            );

            if (!$ok) {
                debug_log("⚠️ browserauth falló después del registro, intento fallback");
                tplink_authorize_client($mac_norm, $ap_norm, ($ssidName ?: $ssid), $token);
                sleep(1);
                trigger_coa_disconnect($mac_norm);
            } else {
                debug_log("✅ browserauth exitoso después del registro");
            }

            debug_log("✅ Registro completado, redirigiendo a bienvenido");
            redirect_to_bienvenido($mac_norm, $client_ip);

        } catch (Exception $e) {
            if ($conn->errno) {
                $conn->rollback();
                debug_log("❌ Error en transacción, rollback ejecutado");
            }
            debug_log("❌ Error en registro: " . $e->getMessage());
            debug_log("❌ Error code: " . $conn->errno);

            if ($conn->errno == 1062) {
                // Duplicado (ya existía) → igual intentamos browserauth
                debug_log("⚠️ Duplicado detectado (errno 1062), intentar browserauth + redirigir");
                $u = $mac_norm; $p = $mac_norm;
                omada_radius_browserauth(
                    $target, $targetPort, $u, $p, $mac_norm, ($clientIp_om ?: $client_ip),
                    $ap_norm, ($ssidName ?: $ssid), $radioId, $originUrl
                );
                trigger_coa_disconnect($mac_norm);
                redirect_to_tyc($mac_norm, $client_ip);
            } else {
                die("<div class='error'>❌ Registration failed: " . htmlspecialchars($e->getMessage()) . "</div>");
            }
        }
    } else {
        if ($mac_norm === '') debug_log("❌ No se puede registrar: MAC vacía");
        debug_log("❌ Errores de validación impiden el registro");
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_log("📝 POST recibido pero sin datos de formulario");
    debug_log("📤 POST data: " . json_encode($_POST));
}

/* =========================================================
 * VALIDACIONES ECU
 * ========================================================= */
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
            color: #333;
        }
        .top-image, .bottom-image {
            width: 100%;
            max-width: 400px;
            border-radius: 15px;
            margin: 10px 0;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
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
        h2 { color: #2c3e50; text-align: center; margin-bottom: 20px; font-size: 1.8rem; font-weight: 600; }
        .form-group { margin-bottom: 16px; }
        label { display:block; font-weight:600; margin-bottom:6px; }
        input {
            width: 100%; padding: 12px; border: 2px solid #e1e8ed;
            border-radius: 12px; font-size: 1rem; transition: all 0.3s ease;
        }
        input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        button {
            width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2);
            color: white; border: none; border-radius: 12px; font-size: 1.05rem;
            font-weight: 600; cursor: pointer; margin-top: 10px; transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        button:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4); }
        button:disabled { opacity: 0.6; cursor: not-allowed; }
        .error {
            background: #ffebee; color: #c62828; padding: 12px; border-radius: 10px;
            margin: 15px 0; text-align: center; font-size: 0.9rem; border-left: 4px solid #c62828;
        }
        .field-error { color:#c62828; font-size:0.85rem; margin-top:6px; }
        .info-display {
            background: #e3f2fd; padding: 12px; border-radius: 10px; margin: 10px 0;
            font-size: 0.9rem; color: #1565c0; text-align: center; border-left: 4px solid #2196f3;
        }
        .status-info {
            background: #e8f5e8; padding: 15px; border-radius: 10px; margin: 15px 0;
            font-size: 0.95rem; color: #2e7d32; text-align: center; border-left: 4px solid #4caf50;
            font-weight: 500;
        }
        .warning-info {
            background: #fff3e0; padding: 15px; border-radius: 10px; margin: 15px 0;
            font-size: 0.95rem; color: #ef6c00; text-align: center; border-left: 4px solid #ff9800;
        }
        .debug-info {
            background: #f5f5f5; padding: 12px; border-radius: 10px; margin: 15px 0;
            font-size: 0.85rem; color: #666; font-family: monospace;
            max-height: 300px; overflow-y: auto; border: 2px dashed #ccc;
        }
        .debug-section {
            background: #fff; padding: 10px; margin: 8px 0; border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .required::after { content: " *"; color: #e74c3c; }
        .terminos-container {
            background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 15px 0;
            border: 2px solid #e9ecef;
        }
        .terminos-checkbox { display: flex; align-items: flex-start; gap: 10px; margin: 10px 0; }
        .terminos-checkbox input[type="checkbox"] { width: 20px; height: 20px; margin-top: 2px; }
        .terminos-text { font-size: 0.9rem; color: #555; line-height: 1.4; }
        .terminos-link { color: #667eea; text-decoration: none; font-weight: 500; }
        .terminos-link:hover { text-decoration: underline; }
        .zona-badge {
            background:#fff3cd; color:#856404; padding:8px 12px; border-radius:12px;
            font-size:0.8rem; display:inline-block; margin-bottom:15px; width:100%;
            text-align:center;
        }
        .log-display {
            background: #2c3e50; color: #ecf0f1; padding: 12px; border-radius: 10px;
            margin: 15px 0; font-family: monospace; font-size: 0.8rem;
            max-height: 200px; overflow-y: auto; white-space: pre-wrap;
        }
        @media (max-width: 480px) {
            .form-container { padding: 25px 20px; border-radius: 15px; margin: 15px 0; }
            input, button { font-size: 1rem; }
            h2 { font-size: 1.5rem; }
            body { padding: 15px; }
        }
    </style>
</head>
<body>

    <img src="gonetlogo.png" alt="GoNet Logo" class="top-image" onerror="this.style.display='none'">

    <div class="form-container">
        <h2>🌐 Registro Wi-Fi - DEBUG</h2>

        <!-- DEBUG INFO EXTENDIDA -->
        <div class="debug-info">
            <strong>🔍 DEBUG INFO COMPLETA:</strong>
            
            <div class="debug-section">
                <strong>🎯 PARÁMETROS OMADA:</strong><br>
                Controller target: <?php echo htmlspecialchars($target ?: 'NO ENVIADO'); ?><br>
                Controller targetPort: <?php echo htmlspecialchars($targetPort ?: 'NO ENVIADO'); ?><br>
                Cliente MAC: <?php echo htmlspecialchars($mac_norm ?: 'NO DETECTADA'); ?><br>
                AP MAC: <?php echo htmlspecialchars($ap_norm ?: 'NO DETECTADA'); ?><br>
                Cliente IP (Omada): <?php echo htmlspecialchars($clientIp_om ?: 'NO ENVIADO'); ?><br>
                SSID: <?php echo htmlspecialchars(($ssidName ?: $ssid) ?: 'NO ENVIADO'); ?><br>
                radioId: <?php echo htmlspecialchars($radioId); ?><br>
                originUrl: <?php echo htmlspecialchars($originUrl ?: 'NO ENVIADO'); ?>
            </div>

            <div class="debug-section">
                <strong>📊 ESTADO CLIENTE:</strong><br>
                MAC Status: <?php echo htmlspecialchars($mac_status); ?><br>
                Existe en clients: <?php echo $client_exists ? 'SÍ' : 'NO'; ?><br>
                Existe en radcheck: <?php echo isset($existe_rc) ? ($existe_rc ? 'SÍ' : 'NO') : 'NO VERIFICADO'; ?>
            </div>

            <div class="debug-section">
                <strong>🌐 CONEXIÓN:</strong><br>
                AP IP (UI): <?php echo htmlspecialchars($ap_ip ?: ''); ?><br>
                Cliente IP (Server): <?php echo htmlspecialchars($client_ip); ?><br>
                Zona: <?php echo htmlspecialchars($zona_nombre ?: 'NO DETECTADA'); ?>
            </div>

            <div class="debug-section">
                <strong>🔗 URLs CONSTRUIDAS:</strong><br>
                <?php if ($target && $targetPort): ?>
                    <?php $scheme = ($targetPort == '8043') ? "https" : "http"; ?>
                    BrowserAuth URL: <?php echo htmlspecialchars("{$scheme}://{$target}:{$targetPort}/portal/radius/browserauth"); ?>
                <?php else: ?>
                    BrowserAuth URL: NO CONFIGURADA (falta target/targetPort)
                <?php endif; ?>
            </div>
        </div>

        <!-- ÚLTIMOS LOGS -->
        <div class="log-display">
            <strong>📝 ÚLTIMOS LOGS:</strong><br>
            <?php 
            $last_logs = `tail -20 /tmp/portal_debug.log`;
            echo htmlspecialchars($last_logs);
            ?>
        </div>

        <?php if ($mac_norm === ''): ?>
            <div class="error">
                ❌ ERROR CRÍTICO: No se detectó tu dirección MAC.<br>
                <small>Esto significa que el Omada Controller no está enviando los parámetros correctos al portal.</small>
            </div>
            
            <div class="debug-info">
                <strong>🚨 SOLUCIÓN:</strong><br>
                1. Verifica que el portal esté bien configurado en el Omada Controller<br>
                2. Revisa que el cliente venga del SSID correcto<br>
                3. Verifica los logs del Omada Controller<br>
                4. Parámetros GET recibidos: <?php echo htmlspecialchars(json_encode($_GET)); ?>
            </div>
            
        <?php elseif ($mac_status === 'registered'): ?>
            <div class="status-info">
                ✅ Este dispositivo ya está registrado.<br>
                Redirigiendo a Términos y Condiciones...
            </div>
            <script>
                setTimeout(() => {
                    window.location.href = 'tyc.php';
                }, 2000);
            </script>
            
        <?php elseif ($client_exists): ?>
            <div class="warning-info">
                ⚠️ Dispositivo conocido pero no autorizado.<br>
                Completa el registro para activar el acceso.
            </div>
        <?php else: ?>
            <div class="info-display">
                📝 Completa tu registro para acceder a Internet
            </div>
        <?php endif; ?>

        <?php if ($mac_norm !== '' && $mac_status !== 'registered'): ?>
        <form method="POST" id="registrationForm" novalidate>
            <div class="form-group">
                <label class="required">Nombre</label>
                <input type="text" name="nombre" placeholder="Tu nombre" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
                <?php if (!empty($errors['nombre'])): ?><div class="field-error"><?php echo htmlspecialchars($errors['nombre']); ?></div><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required">Apellido</label>
                <input type="text" name="apellido" placeholder="Tu apellido" required value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>">
                <?php if (!empty($errors['apellido'])): ?><div class="field-error"><?php echo htmlspecialchars($errors['apellido']); ?></div><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required">Cédula</label>
                <input type="text" name="cedula" placeholder="10 dígitos" required inputmode="numeric" pattern="\d{10}" value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>">
                <?php if (!empty($errors['cedula'])): ?><div class="field-error"><?php echo htmlspecialchars($errors['cedula']); ?></div><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required">Teléfono</label>
                <input type="tel" name="telefono" placeholder="09XXXXXXXX" required inputmode="tel" pattern="^09\d{8}$" value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
                <?php if (!empty($errors['telefono'])): ?><div class="field-error"><?php echo htmlspecialchars($errors['telefono']); ?></div><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required">Email</label>
                <input type="email" name="email" placeholder="correo@ejemplo.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                <?php if (!empty($errors['email'])): ?><div class="field-error"><?php echo htmlspecialchars($errors['email']); ?></div><?php endif; ?>
            </div>

            <div class="terminos-container">
                <div class="terminos-checkbox">
                    <input type="checkbox" name="terminos" id="terminos" <?php echo isset($_POST['terminos']) ? 'checked' : ''; ?> required>
                    <label for="terminos" class="terminos-text">
                        Acepto los <a href="terminos.html" target="_blank" class="terminos-link">Términos y Condiciones</a>
                        y la <a href="privacidad.html" target="_blank" class="terminos-link">Política de Privacidad</a>.
                    </label>
                </div>
                <?php if (!empty($errors['terminos'])): ?><div class="field-error"><?php echo htmlspecialchars($errors['terminos']); ?></div><?php endif; ?>
            </div>

            <!-- Campos ocultos con todos los parámetros -->
            <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($client_ip); ?>">
            <input type="hidden" name="ap_mac" value="<?php echo htmlspecialchars($ap_norm); ?>">
            <input type="hidden" name="ap_ip" value="<?php echo htmlspecialchars($ap_ip); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="ssid" value="<?php echo htmlspecialchars($ssidName ?: $ssid); ?>">
            <input type="hidden" name="target" value="<?php echo htmlspecialchars($target); ?>">
            <input type="hidden" name="targetPort" value="<?php echo htmlspecialchars($targetPort); ?>">
            <input type="hidden" name="radioId" value="<?php echo htmlspecialchars($radioId); ?>">
            <input type="hidden" name="originUrl" value="<?php echo htmlspecialchars($originUrl); ?>">
            <input type="hidden" name="clientIp" value="<?php echo htmlspecialchars($clientIp_om); ?>">

            <button type="submit" id="submitBtn">🚀 Registrar y Conectar</button>
            
            <div class="debug-info" style="margin-top: 15px; font-size: 0.75rem;">
                <strong>🔧 DEBUG POST:</strong><br>
                Al enviar, se ejecutará browserauth a:<br>
                <?php if ($target && $targetPort): ?>
                    <?php $scheme = ($targetPort == '8043') ? "https" : "http"; ?>
                    <strong><?php echo htmlspecialchars("{$scheme}://{$target}:{$targetPort}/portal/radius/browserauth"); ?></strong><br>
                    Con credenciales: usuario=<?php echo htmlspecialchars($mac_norm); ?>, password=<?php echo htmlspecialchars($mac_norm); ?>
                <?php else: ?>
                    <strong style="color: red;">ERROR: No hay target/targetPort para browserauth</strong>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- Publicidad -->
    <?php if ($zona_banner): ?>
        <img src="<?php echo htmlspecialchars($zona_banner); ?>" alt="Publicidad" class="bottom-image" onerror="this.style.display='none'">
    <?php else: ?>
        <img src="banner.png" alt="Banner" class="bottom-image" onerror="this.style.display='none'">
    <?php endif; ?>

    <script>
        // Debug en consola del navegador
        console.log("🔍 DEBUG CLIENTE:");
        console.log("MAC: <?php echo $mac_norm; ?>");
        console.log("Target: <?php echo $target; ?>");
        console.log("TargetPort: <?php echo $targetPort; ?>");
        console.log("URL BrowserAuth: <?php echo $target && $targetPort ? $scheme.'://'.$target.':'.$targetPort.'/portal/radius/browserauth' : 'NO CONFIGURADA'; ?>");

        const form = document.getElementById('registrationForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                const submitBtn = document.getElementById('submitBtn');
                if (submitBtn) {
                    submitBtn.innerHTML = '⏳ Procesando...';
                    submitBtn.disabled = true;
                }
                
                // Debug antes de enviar
                console.log("📤 Enviando formulario...");
                console.log("🔐 Credenciales para browserauth:");
                console.log("  Usuario: <?php echo $mac_norm; ?>");
                console.log("  Password: <?php echo $mac_norm; ?>");
            });
        }
    </script>

</body>
</html>