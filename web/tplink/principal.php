<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";

// TP-Link AP Configuration
define('AP_IP', '192.168.0.7');
define('RADIUS_SECRET', 'telecom');
define('COA_PORT', '3799');

/* =========================================================
 * HELPER: normalizar MAC (quitar :, -, ., espacios)
 * ========================================================= */
function normalize_mac($mac_raw) {
    if (empty($mac_raw)) return '';
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac_raw);
    return strtoupper($hex);
}

/* =========================================================
 * HELPER: extraer solo IP si viene "192.168.0.9:22080"
 * ========================================================= */
function only_ip_part($str) {
    if (!$str) return '';
    if (strpos($str, ':') !== false) {
        $parts = explode(':', $str);
        return $parts[0];
    }
    return $str;
}

/* =========================================================
 * REDIRECCIONES
 * ========================================================= */
function redirect_to_bienvenido($mac_norm, $ip) {
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
 * HELPER: lanzar CoA (Change of Authorization) 
 * Esto fuerza al AP a re-autenticar al cliente
 * ========================================================= */
function trigger_coa_disconnect($mac) {
    if (empty($mac)) {
        error_log("❌ trigger_coa_disconnect: MAC vacía");
        return false;
    }

    $ap_ip = AP_IP;
    $secret = RADIUS_SECRET;
    $port = COA_PORT;

    // Comando para desconectar (forzar re-autenticación)
    $cmd = sprintf(
        'echo "User-Name=%s" | radclient -r 2 -t 3 -x %s:%s disconnect %s >> /tmp/coa.log 2>&1 &',
        escapeshellarg($mac),
        escapeshellarg($ap_ip),
        escapeshellarg($port),
        escapeshellarg($secret)
    );

    error_log("🚀 Lanzando CoA: $cmd");
    @exec($cmd);
    
    return true;
}

/* =========================================================
 * TP-Link External Portal Authentication
 * Este método notifica al AP que el usuario está autorizado
 * ========================================================= */
function tplink_authorize_client($clientMac, $apMac = '', $ssid = '', $token = '') {
    $ap_ip = AP_IP;
    
    // Para TP-Link standalone AP (no Omada controller)
    // El AP típicamente expone una página de autorización
    
    // Intentar múltiples endpoints comunes de TP-Link
    $endpoints = [
        "http://{$ap_ip}/portal_auth.cgi",
        "http://{$ap_ip}/cgi-bin/portal_auth",
        "http://{$ap_ip}/login",
    ];

    $payload = [
        'clientMac' => $clientMac,
        'success' => 'true',
        'authType' => 'radius',
    ];

    if ($apMac) $payload['apMac'] = $apMac;
    if ($ssid) $payload['ssid'] = $ssid;
    if ($token) $payload['token'] = $token;

    error_log("🔐 Intentando autorizar cliente: " . json_encode($payload));

    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        
        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        error_log("🔍 Endpoint: $url → HTTP $http_code → Resp: $resp");
        
        if ($http_code >= 200 && $http_code < 300) {
            error_log("✅ Cliente autorizado en: $url");
            return true;
        }
    }
    
    error_log("⚠️ No se pudo notificar al AP directamente, usando CoA");
    return false;
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

/* =========================================================
 * CONEXIÓN BD
 * ========================================================= */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    error_log("✅ CONEXIÓN BD EXITOSA");
} catch (Exception $e) {
    error_log("❌ ERROR CONEXIÓN BD: " . $e->getMessage());
    die("<div class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</div>");
}

/* =========================================================
 * LOG TODOS LOS PARÁMETROS RECIBIDOS
 * ========================================================= */
error_log("========== NUEVA PETICIÓN ==========");
error_log("GET: " . json_encode($_GET));
error_log("POST: " . json_encode($_POST));
error_log("REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));
error_log("HTTP_X_FORWARDED_FOR: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'N/A'));

/* =========================================================
 * PARÁMETROS QUE MANDA TP-LINK
 * ========================================================= */
$client_mac_raw = $_GET['clientMac'] ?? $_POST['clientMac'] ?? $_GET['mac'] ?? $_POST['mac'] ?? '';
$ap_mac_raw = $_GET['ap'] ?? $_POST['ap'] ?? $_GET['apMac'] ?? $_POST['apMac'] ?? '';
$ap_ip_raw = $_GET['target'] ?? $_POST['target'] ?? $_GET['ip'] ?? $_POST['ip'] ?? AP_IP;
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$ssid = $_GET['ssid'] ?? $_POST['ssid'] ?? '';
$redirect_url = $_GET['url'] ?? $_POST['url'] ?? $_GET['redirect'] ?? '';

$mac_norm = normalize_mac($client_mac_raw);
$ap_norm = normalize_mac($ap_mac_raw);
$ap_ip = only_ip_part($ap_ip_raw) ?: AP_IP;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

$errors = [
    'nombre'   => '',
    'apellido' => '',
    'cedula'   => '',
    'telefono' => '',
    'email'    => '',
    'terminos' => ''
];

error_log("📋 PARÁMETROS PROCESADOS:");
error_log("  - MAC Cliente: $mac_norm");
error_log("  - AP MAC: $ap_norm");
error_log("  - AP IP: $ap_ip");
error_log("  - Cliente IP: $client_ip");
error_log("  - Token: $token");
error_log("  - SSID: $ssid");
error_log("  - Redirect URL: $redirect_url");

/* =========================================================
 *  RESOLVER ZONA POR AP
 * ========================================================= */
$zona_codigo = '';
$zona_nombre = '';
$zona_banner = '';

if ($ap_norm !== '') {
    try {
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
            error_log("✅ Zona detectada por AP_MAC: $ap_norm → {$zona_codigo}");
        }
        $stmtZ->close();
    } catch (Exception $e) {
        error_log("⚠️ Error buscando zona por AP: " . $e->getMessage());
    }
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
        // Verificar si ya está en radcheck (ya autorizado)
        $check_radcheck = $conn->prepare("
            SELECT id FROM radcheck 
            WHERE username = ? AND attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept'
        ");
        $check_radcheck->bind_param("s", $mac_norm);
        $check_radcheck->execute();
        $check_radcheck->store_result();

        if ($check_radcheck->num_rows > 0) {
            $mac_status = 'registered';
            error_log("✅ Cliente ya registrado en radcheck: $mac_norm");
        }
        $check_radcheck->close();

        // Verificar si existe en clients
        $check_clients = $conn->prepare("SELECT id FROM clients WHERE mac = ?");
        $check_clients->bind_param("s", $mac_norm);
        $check_clients->execute();
        $check_clients->store_result();

        if ($check_clients->num_rows > 0) {
            $client_exists = true;
            error_log("✅ Cliente encontrado en tabla clients: $mac_norm");
        }
        $check_clients->close();

    } catch (Exception $e) {
        error_log("⚠️ Error verificando estado: " . $e->getMessage());
    }

    // Si ya está registrado, notificar al AP y redirigir
    if ($mac_status === 'registered') {
        error_log("🔄 Cliente ya registrado, notificando al AP y redirigiendo");
        
        // Intentar notificar al AP
        tplink_authorize_client($mac_norm, $ap_norm, $ssid, $token);
        
        // Forzar re-autenticación con CoA
        trigger_coa_disconnect($mac_norm);
        
        redirect_to_tyc($mac_norm, $client_ip);
    }
}

/* =========================================================
 * MANEJO POST (registro)
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nombre'])) {
    error_log("📝 Procesando formulario de registro");
    
    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = preg_replace('/\D+/', '', $_POST['cedula'] ?? '');
    $telefono = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $terminos = isset($_POST['terminos']) ? 1 : 0;

    // Validaciones
    if ($nombre === '')   $errors['nombre']   = 'Ingresa tu nombre.';
    if ($apellido === '') $errors['apellido'] = 'Ingresa tu apellido.';
    if (!validarCedulaEC($cedula)) {
        $errors['cedula'] = 'Cédula inválida.';
    }
    if (!validarTelefonoEC($telefono)) {
        $errors['telefono'] = 'Teléfono inválido.';
    }
    if (!validarEmailReal($email)) {
        $errors['email'] = 'Correo inválido.';
    }
    if (!$terminos) {
        $errors['terminos'] = 'Debes aceptar los términos.';
    }

    $hayErrores = array_filter($errors, fn($e) => $e !== '');

    if (!$hayErrores && $mac_norm !== '') {
        try {
            $conn->begin_transaction();
            error_log("🔄 Iniciando transacción de registro para: $mac_norm");

            // Verificar si ya existe
            $check = $conn->prepare("SELECT id FROM radcheck WHERE username = ?");
            $check->bind_param("s", $mac_norm);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                error_log("⚠️ Cliente ya existe en radcheck, saltando insert");
                $check->close();
                $conn->commit();
            } else {
                $check->close();

                // Insertar en clients
                $stmt_clients = $conn->prepare("
                    INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled, ap_mac, ip)
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
                ");
                $stmt_clients->bind_param("ssssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac_norm, $ap_norm, $client_ip);
                $stmt_clients->execute();
                $stmt_clients->close();
                error_log("✅ Cliente insertado en tabla clients");

                // Insertar en radcheck (autorización RADIUS)
                $stmt_radcheck = $conn->prepare("
                    INSERT INTO radcheck (username, attribute, op, value)
                    VALUES (?, 'Auth-Type', ':=', 'Accept')
                ");
                $stmt_radcheck->bind_param("s", $mac_norm);
                $stmt_radcheck->execute();
                $stmt_radcheck->close();
                error_log("✅ Cliente autorizado en radcheck");

                $conn->commit();
            }

            // CRÍTICO: Notificar al AP que el cliente está autorizado
            error_log("🔔 Notificando al AP sobre autorización de: $mac_norm");
            tplink_authorize_client($mac_norm, $ap_norm, $ssid, $token);
            
            // Forzar re-autenticación con CoA
            sleep(1); // Pequeña pausa para que se registre
            trigger_coa_disconnect($mac_norm);
            
            error_log("✅ Registro completado, redirigiendo a bienvenido");
            redirect_to_bienvenido($mac_norm, $client_ip);

        } catch (Exception $e) {
            if ($conn->errno) {
                $conn->rollback();
            }

            error_log("❌ Error en registro: " . $e->getMessage());

            if ($conn->errno == 1062) {
                // Duplicado, ya existe
                error_log("⚠️ Duplicado detectado, redirigiendo a TYC");
                tplink_authorize_client($mac_norm, $ap_norm, $ssid, $token);
                trigger_coa_disconnect($mac_norm);
                redirect_to_tyc($mac_norm, $client_ip);
            } else {
                die("<div class='error'>❌ Registration failed: " . htmlspecialchars($e->getMessage()) . "</div>");
            }
        }
    } else {
        if ($mac_norm === '') {
            error_log("❌ No se puede registrar: MAC vacía");
        }
        error_log("❌ Errores de validación: " . json_encode($errors));
    }
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
            max-height: 200px; overflow-y: auto;
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
        <h2>🌐 Registro Wi-Fi</h2>

        <?php if ($zona_codigo !== ''): ?>
            <div class="zona-badge">
                📍 Zona: <strong><?php echo htmlspecialchars($zona_nombre ?: $zona_codigo); ?></strong><br>
                🔷 AP: <strong><?php echo htmlspecialchars(AP_IP); ?></strong>
            </div>
        <?php endif; ?>

        <!-- DEBUG INFO -->
        <div class="debug-info">
            <strong>🔍 Debug Info:</strong><br>
            AP IP: <?php echo htmlspecialchars($ap_ip); ?><br>
            Cliente MAC: <?php echo htmlspecialchars($mac_norm ?: 'No detectada'); ?><br>
            AP MAC: <?php echo htmlspecialchars($ap_norm ?: 'No detectada'); ?><br>
            Cliente IP: <?php echo htmlspecialchars($client_ip); ?><br>
            Token: <?php echo htmlspecialchars($token ?: 'No enviado'); ?><br>
            SSID: <?php echo htmlspecialchars($ssid ?: 'No enviado'); ?>
        </div>

        <?php if ($mac_norm === ''): ?>
            <div class="error">
                ❌ No se detectó tu dirección MAC.<br>
                <small>Asegúrate de estar conectado a la red Wi-Fi.<br>
                Parámetros recibidos: <?php echo htmlspecialchars(json_encode($_GET)); ?></small>
            </div>
        <?php elseif ($mac_status === 'registered'): ?>
            <div class="status-info">
                ✅ Este dispositivo ya está registrado.<br>
                Redirigiendo...
            </div>
        <?php elseif ($client_exists): ?>
            <div class="warning-info">
                ⚠️ Dispositivo conocido. Completa el registro.
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

            <!-- Campos ocultos -->
            <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($client_ip); ?>">
            <input type="hidden" name="ap_mac" value="<?php echo htmlspecialchars($ap_norm); ?>">
            <input type="hidden" name="ap_ip" value="<?php echo htmlspecialchars($ap_ip); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="ssid" value="<?php echo htmlspecialchars($ssid); ?>">

            <button type="submit" id="submitBtn">🚀 Registrar y Conectar</button>
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
        const form = document.getElementById('registrationForm');
        if (form) {
            const fields = {
                nombre:   form.querySelector('[name="nombre"]'),
                apellido: form.querySelector('[name="apellido"]'),
                cedula:   form.querySelector('[name="cedula"]'),
                telefono: form.querySelector('[name="telefono"]'),
                email:    form.querySelector('[name="email"]'),
                terminos: form.querySelector('[name="terminos"]'),
            };

            function validarCedulaEC(ced) {
                ced = ced.replace(/\D+/g,'');
                if (!/^\d{10}$/.test(ced)) return false;
                const prov = parseInt(ced.slice(0,2),10);
                if (prov < 1 || prov > 24) return false;
                const t = parseInt(ced[2],10);
                if (t >= 6) return false;
                const coef = [2,1,2,1,2,1,2,1,2];
                let suma = 0;
                for (let i=0;i<9;i++){
                    let prod = parseInt(ced[i],10) * coef[i];
                    if (prod >= 10) prod -= 9;
                    suma += prod;
                }
                const dv = (10 - (suma % 10)) % 10;
                return dv === parseInt(ced[9],10);
            }

            function validarTelefonoEC(tel) {
                tel = tel.replace(/\D+/g,'');
                return /^09\d{8}$/.test(tel);
            }

            function validarEmailBasico(mail) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail);
            }

            function setError(input, msg) {
                let errDiv = input.parentElement.querySelector('.field-error');
                if (msg) {
                    if (!errDiv) {
                        errDiv = document.createElement('div');
                        errDiv.className = 'field-error';
                        input.parentElement.appendChild(errDiv);
                    }
                    errDiv.textContent = msg;
                    input.setAttribute('aria-invalid', 'true');
                } else {
                    if (errDiv) errDiv.textContent = '';
                    input.removeAttribute('aria-invalid');
                }
            }

            function validateAll() {
                let hasErrors = false;

                if (!fields.nombre.value.trim()) {
                    setError(fields.nombre, 'Ingresa tu nombre.');
                    hasErrors = true;
                } else setError(fields.nombre, '');

                if (!fields.apellido.value.trim()) {
                    setError(fields.apellido, 'Ingresa tu apellido.');
                    hasErrors = true;
                } else setError(fields.apellido, '');

                const ced = fields.cedula.value;
                if (!validarCedulaEC(ced)) {
                    setError(fields.cedula, 'Cédula inválida.');
                    hasErrors = true;
                } else setError(fields.cedula, '');

                const tel = fields.telefono.value;
                if (!validarTelefonoEC(tel)) {
                    setError(fields.telefono, 'Teléfono inválido.');
                    hasErrors = true;
                } else setError(fields.telefono, '');

                const mail = fields.email.value;
                if (!validarEmailBasico(mail)) {
                    setError(fields.email, 'Correo inválido.');
                    hasErrors = true;
                } else setError(fields.email, '');

                if (!fields.terminos.checked) {
                    let errDiv = fields.terminos.closest('.terminos-container').querySelector('.field-error');
                    if (!errDiv) {
                        errDiv = document.createElement('div');
                        errDiv.className = 'field-error';
                        fields.terminos.closest('.terminos-container').appendChild(errDiv);
                    }
                    errDiv.textContent = 'Debes aceptar los términos.';
                    hasErrors = true;
                } else {
                    let errDiv = fields.terminos.closest('.terminos-container').querySelector('.field-error');
                    if (errDiv) errDiv.textContent = '';
                }

                return !hasErrors;
            }

            form.addEventListener('submit', function(e) {
                if (!validateAll()) {
                    e.preventDefault();
                    return false;
                }
                const submitBtn = document.getElementById('submitBtn');
                if (submitBtn) {
                    submitBtn.innerHTML = '⏳ Procesando...';
                    submitBtn.disabled = true;
                }
            });

            ['input','change','blur'].forEach(evt => {
                form.addEventListener(evt, () => validateAll(), true);
            });
        }
    </script>

</body>
</html>