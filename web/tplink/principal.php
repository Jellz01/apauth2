<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";

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
    // si viene con puerto -> separar
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
 * HELPER: lanzar CoA en background
 * ========================================================= */
function start_coa_async($mac, $ap_ip) {
    if (empty($mac) || empty($ap_ip)) {
        error_log("❌ start_coa_async: mac o ap_ip vacíos");
        return false;
    }

    // ojo: si llegó con puerto ya lo limpiamos antes de llamarlo
    $coa_secret = "telecom";
    $coa_port   = "4325";

    $payload = sprintf('User-Name=%s', addslashes($mac));
    $cmd = sprintf(
        'sh -c \'echo "%s" | radclient -r 2 -t 3 -x %s:%s disconnect %s >> /tmp/coa_async.log 2>&1 &\'',
        $payload,
        escapeshellarg($ap_ip),
        $coa_port,
        escapeshellarg($coa_secret)
    );

    error_log("🚀 Lanzando CoA en background: $cmd");
    @exec($cmd);
    return true;
}

/* =========================================================
 * HELPER: avisar al controlador TP-Link / Omada
 * (ajusta la URL a tu controlador)
 * ========================================================= */
function omada_allow_client($controller_base, $token, $clientMac, $apMac, $ssid) {
    // si no hay token ni base no hacemos nada
    if (!$controller_base || !$clientMac) {
        error_log("⚠️ omada_allow_client: faltan datos (controller_base o clientMac)");
        return;
    }

    // endpoint típico de Omada ext portal (AJUSTA si tu versión usa otro)
    // muchos usan: /extportal/auth, /portal/extPortal/auth o /api/v2/hotspot/extPortal/auth
    $url = rtrim($controller_base, "/") . "/portal/extPortal/auth";

    $payload = [
        "success"   => true,
        "clientMac" => $clientMac,
        "apMac"     => $apMac,
        "ssid"      => $ssid,
        "token"     => $token,
        "authType"  => "radius",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 3,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("❌ omada_allow_client CURL error: $err");
    } else {
        error_log("✅ omada_allow_client enviado a $url → $resp");
    }
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
 * PARÁMETROS QUE MANDA TP-LINK / OMADA
 * =========================================================
 * target    -> 192.168.0.9:22080
 * clientMac -> MAC del cliente
 * ap        -> MAC del AP
 * ssid      -> SSID
 * token     -> token de sesión del portal (a veces)
 */
$client_mac_raw = $_GET['clientMac'] ?? $_POST['clientMac'] ?? '';
$ap_mac_raw_tpl = $_GET['ap']        ?? $_POST['ap']        ?? '';
$ap_ip_tpl      = $_GET['target']    ?? $_POST['target']    ?? '';
$token_omada    = $_GET['token']     ?? $_POST['token']     ?? '';
$essid          = $_GET['ssid']      ?? $_POST['ssid']      ?? '';

/* compat con tus nombres viejos */
$mac_raw_fallback  = $_GET['mac']    ?? $_POST['mac']    ?? '';
$ap_raw_fallback   = $_GET['ap_mac'] ?? $_POST['ap_mac'] ?? '';
$ip_raw_fallback   = $_GET['ip']     ?? $_POST['ip']     ?? '';

/* elegir finales */
$mac_raw = $client_mac_raw !== '' ? $client_mac_raw : $mac_raw_fallback;
$ap_raw  = $ap_mac_raw_tpl !== '' ? $ap_mac_raw_tpl : $ap_raw_fallback;

$ap_ip_default = '192.168.0.9'; // por si no manda nada
$ap_ip_input   = $ap_ip_tpl !== '' ? $ap_ip_tpl : ($_GET['ap_ip'] ?? $_POST['ap_ip'] ?? $ip_raw_fallback ?? '');
$ap_ip_clean   = only_ip_part($ap_ip_input);
$ap_ip         = $ap_ip_clean !== '' ? $ap_ip_clean : $ap_ip_default;

$mac_norm = normalize_mac($mac_raw);
$ap_norm  = normalize_mac($ap_raw);
$ip       = trim($ip_raw_fallback);

$errors = [
    'nombre'   => '',
    'apellido' => '',
    'cedula'   => '',
    'telefono' => '',
    'email'    => '',
    'terminos' => ''
];

error_log("🔍 TP-LINK PARAMS → clientMac='{$client_mac_raw}', ap='{$ap_mac_raw_tpl}', target='{$ap_ip_tpl}', token='{$token_omada}', ssid='{$essid}'");
error_log("🔍 PARÁMETROS FINALES - MAC_CLIENTE: '$mac_norm', AP_MAC: '$ap_norm', AP_IP: '$ap_ip'");

/* =========================================================
 *  RESOLVER ZONA POR AP
 * ========================================================= */
$zona_codigo = '';
$zona_nombre = '';
$zona_banner = '';

// 1) directa por AP
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

// 2) fallback por cliente
if ($zona_codigo === '' && $mac_norm !== '') {
    try {
        $stmtC = $conn->prepare("
            SELECT c.ap_mac
            FROM clients c
            WHERE c.mac = ?
            ORDER BY c.id DESC
            LIMIT 1
        ");
        $stmtC->bind_param("s", $mac_norm);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        if ($rowC = $resC->fetch_assoc()) {
            $ap_from_client = normalize_mac($rowC['ap_mac']);
            if ($ap_from_client !== '') {
                $stmtZ2 = $conn->prepare("
                    SELECT z.codigo, z.nombre, z.banner_url
                    FROM wifi_zona_aps a
                    JOIN wifi_zonas z ON a.zona_codigo = z.codigo
                    WHERE a.ap_mac = ?
                    LIMIT 1
                ");
                $stmtZ2->bind_param("s", $ap_from_client);
                $stmtZ2->execute();
                $resZ2 = $stmtZ2->get_result();
                if ($rowZ2 = $resZ2->fetch_assoc()) {
                    $zona_codigo = $rowZ2['codigo'];
                    $zona_nombre = $rowZ2['nombre'];
                    $zona_banner = $rowZ2['banner_url'];
                    error_log("✅ Zona detectada por MAC cliente: $mac_norm → {$zona_codigo}");
                }
                $stmtZ2->close();
            }
        }
        $stmtC->close();
    } catch (Exception $e) {
        error_log("⚠️ Error deduciendo zona por cliente: " . $e->getMessage());
    }
}

/* guardar en sesión la zona detectada */
$_SESSION['wifi_zona_codigo'] = $zona_codigo;
$_SESSION['wifi_zona_nombre'] = $zona_nombre;
$_SESSION['wifi_zona_banner'] = $zona_banner;

/* =========================================================
 * MANEJO POST (registro)
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = preg_replace('/\D+/', '', $_POST['cedula'] ?? '');
    $telefono = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $terminos = isset($_POST['terminos']) ? 1 : 0;

    $mac_post    = $_POST['mac']    ?? '';
    $ip_post     = $_POST['ip']     ?? '';
    $ap_post_raw = $_POST['ap_mac'] ?? $ap_raw;
    $ap_ip_post  = $_POST['ap_ip']  ?? $ap_ip;

    $mac_norm   = normalize_mac($mac_post);
    $ap_norm    = normalize_mac($ap_post_raw);
    $ip         = trim($ip_post);
    $ap_ip      = only_ip_part($ap_ip_post) ?: $ap_ip;

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

    if (!$hayErrores) {
        try {
            $conn->begin_transaction();

            // 1) ya existe en radcheck?
            $check_radcheck = $conn->prepare("
                SELECT id FROM radcheck 
                WHERE username = ? AND attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept'
            ");
            $check_radcheck->bind_param("s", $mac_norm);
            $check_radcheck->execute();
            $check_radcheck->store_result();

            if ($check_radcheck->num_rows > 0) {
                $check_radcheck->close();
                $conn->commit();

                // avisar a Omada igual
                $omada_url = 'http://192.168.0.9:8088'; // AJUSTA
                omada_allow_client($omada_url, $token_omada, $mac_norm, $ap_norm, $essid);

                $_SESSION['wifi_zona_codigo'] = $zona_codigo;
                $_SESSION['wifi_zona_nombre'] = $zona_nombre;
                $_SESSION['wifi_zona_banner'] = $zona_banner;
                redirect_to_tyc($mac_norm, $ip);
            }
            $check_radcheck->close();

            // 2) insert en clients
            $stmt_clients = $conn->prepare("
                INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled, ap_mac)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?)
            ");
            $stmt_clients->bind_param("sssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac_norm, $ap_norm);
            $stmt_clients->execute();
            $stmt_clients->close();

            // 3) insert en radcheck
            $stmt_radcheck = $conn->prepare("
                INSERT INTO radcheck (username, attribute, op, value)
                VALUES (?, 'Auth-Type', ':=', 'Accept')
            ");
            $stmt_radcheck->bind_param("s", $mac_norm);
            $stmt_radcheck->execute();
            $stmt_radcheck->close();

            $conn->commit();

            // avisar a Omada que ya está ok
            $omada_url = 'http://192.168.0.9:8088'; // AJUSTA a tu controlador
            omada_allow_client($omada_url, $token_omada, $mac_norm, $ap_norm, $essid);

            // guardar zona
            $_SESSION['wifi_zona_codigo'] = $zona_codigo;
            $_SESSION['wifi_zona_nombre'] = $zona_nombre;
            $_SESSION['wifi_zona_banner'] = $zona_banner;

            // CoA
            start_coa_async($mac_norm, $ap_ip);

            redirect_to_bienvenido($mac_norm, $ip);

        } catch (Exception $e) {
            if ($conn->errno) {
                $conn->rollback();
            }

            if ($conn->errno == 1062) {
                // duplicado → mandar a TYC
                $_SESSION['wifi_zona_codigo'] = $zona_codigo;
                $_SESSION['wifi_zona_nombre'] = $zona_nombre;
                $_SESSION['wifi_zona_banner'] = $zona_banner;
                redirect_to_tyc($mac_norm, $ip);
            } else {
                die("<div class='error'>❌ Registration failed: " . htmlspecialchars($e->getMessage()) . " (Error: " . $conn->errno . ")</div>");
            }
        }
    } else {
        $_POST['nombre']   = $nombre;
        $_POST['apellido'] = $apellido;
        $_POST['cedula']   = $cedula;
        $_POST['telefono'] = $telefono;
        $_POST['email']    = $email;
    }
}

/* =========================================================
 * ESTADO DE MAC (para redir temprana)
 * ========================================================= */
$mac_status    = 'new';
$client_exists = false;
if ($mac_norm !== '') {
    try {
        $check_radcheck_display = $conn->prepare("
            SELECT id FROM radcheck 
            WHERE username = ? AND attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept'
        ");
        $check_radcheck_display->bind_param("s", $mac_norm);
        $check_radcheck_display->execute();
        $check_radcheck_display->store_result();

        if ($check_radcheck_display->num_rows > 0) {
            $mac_status = 'registered';
        }
        $check_radcheck_display->close();

        $check_clients_display = $conn->prepare("SELECT id FROM clients WHERE mac = ?");
        $check_clients_display->bind_param("s", $mac_norm);
        $check_clients_display->execute();
        $check_clients_display->store_result();

        if ($check_clients_display->num_rows > 0) {
            $client_exists = true;
        }
        $check_clients_display->close();

    } catch (Exception $e) {
        error_log("⚠️ Error verificando estado: " . $e->getMessage());
    }

    if ($mac_status === 'registered') {
        // avisar también a Omada por si viene con token
        $omada_url = 'http://192.168.0.9:8088'; // AJUSTA
        omada_allow_client($omada_url, $token_omada, $mac_norm, $ap_norm, $essid);

        $_SESSION['wifi_zona_codigo'] = $zona_codigo;
        $_SESSION['wifi_zona_nombre'] = $zona_nombre;
        $_SESSION['wifi_zona_banner'] = $zona_banner;
        redirect_to_tyc($mac_norm, $ip);
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
        .admin-link {
            margin-top: 15px;
            font-size: 0.9rem;
            text-align: center;
        }
        .admin-link a {
            color: #fff;
            background: rgba(0,0,0,0.3);
            padding: 8px 16px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
        }
        .admin-link a:hover {
            background: rgba(0,0,0,0.5);
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

    <img src="gonetlogo.png" alt="GoNet Logo" class="top-image">

    <div class="form-container">
        <h2>Registro para Wi-Fi</h2>

        <?php if ($zona_codigo !== ''): ?>
            <div class="zona-badge">
                📍 Zona detectada: <strong><?php echo htmlspecialchars($zona_nombre ?: $zona_codigo); ?></strong><br>
                🟣 AP: <strong>detectado</strong>
            </div>
        <?php else: ?>
            <div class="zona-badge" style="background:#ffecec;color:#b71c1c;">
                ⚠️ No se pudo determinar la zona de este AP
            </div>
        <?php endif; ?>

        <?php if ($mac_norm === ''): ?>
            <div class="error">
                ❌ No se detectó ninguna dirección MAC del dispositivo.<br>
                <small>Conéctate a la red Wi-Fi y vuelve a intentar.</small>
            </div>
        <?php elseif ($mac_status === 'registered'): ?>
            <div class="status-info">
                ✅ Este dispositivo ya está registrado.<br>
                Redirigiendo...
            </div>
        <?php elseif ($client_exists && $mac_status === 'new'): ?>
            <div class="warning-info">
                ⚠️ Dispositivo visto antes, completa el registro.
            </div>
        <?php else: ?>
            <div class="info-display">
                📝 Completa el registro para acceder a Internet.
            </div>
        <?php endif; ?>

        <?php if ($mac_norm !== '' && $mac_status !== 'registered'): ?>
        <form method="POST" autocomplete="on" id="registrationForm" novalidate>
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
                <input
                    type="text"
                    name="cedula"
                    placeholder="Número de cédula (10 dígitos)"
                    required
                    inputmode="numeric"
                    pattern="\d{10}"
                    value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>">
                <?php if (!empty($errors['cedula'])): ?><div class="field-error"><?php echo htmlspecialchars($errors['cedula']); ?></div><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required">Teléfono</label>
                <input
                    type="tel"
                    name="telefono"
                    placeholder="09XXXXXXXX"
                    required
                    inputmode="tel"
                    pattern="^09\d{8}$"
                    value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
                <?php if (!empty($errors['telefono'])): ?><div class="field-error"><?php echo htmlspecialchars($errors['telefono']); ?></div><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required">Email</label>
                <input
                    type="email"
                    name="email"
                    placeholder="correo@ejemplo.com"
                    required
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
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

            <!-- Datos ocultos -->
            <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
            <input type="hidden" name="ip"  value="<?php echo htmlspecialchars($ip); ?>">
            <input type="hidden" name="ap_mac" value="<?php echo htmlspecialchars($ap_norm); ?>">
            <input type="hidden" name="ap_ip"  value="<?php echo htmlspecialchars($ap_ip); ?>">

            <button type="submit" id="submitBtn">🚀 Registrar y Conectar</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Publicidad -->
    <?php if ($zona_banner): ?>
        <img src="<?php echo htmlspecialchars($zona_banner); ?>" alt="Publicidad zona <?php echo htmlspecialchars($zona_nombre ?: $zona_codigo); ?>" class="bottom-image">
    <?php else: ?>
        <img src="banner.png" alt="Banner" class="bottom-image">
    <?php endif; ?>

    <!-- Link admin -->
    <div class="admin-link">
        <p>🔐 Iniciar al portal de administración</p>
        <a href="admin_zonas.php">Ir a admin_zonas.php</a>
    </div>

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
