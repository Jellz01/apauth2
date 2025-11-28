<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

/** ============= Parámetros de entrada (Omada + fallback) ============= */

// Omada suele mandar: clientMac, clientIp, apMac, redirectUrl/originUrl
$mac_raw  = $_GET['mac']
         ?? $_GET['clientMac']
         ?? $_GET['cid']
         ?? $_POST['mac']
         ?? '';

$ip_raw   = $_GET['ip']
         ?? $_GET['clientIp']
         ?? $_POST['ip']
         ?? '';

$ap_raw   = $_GET['apMac']
         ?? $_GET['ap']
         ?? $_POST['ap_mac']
         ?? '';

$redirect_url_raw = $_GET['redirectUrl']
                 ?? $_GET['originUrl']
                 ?? $_POST['redirect_url']
                 ?? '';

$mac_norm      = normalize_mac($mac_raw);
$ap_mac_norm   = normalize_mac($ap_raw);
$ip            = trim($ip_raw);
$redirect_url  = trim($redirect_url_raw);

error_log("🔍 REQUEST - MAC: '$mac_norm', IP: '$ip', AP_MAC: '$ap_mac_norm', REDIRECT: '$redirect_url'");

$errors = [
    'mac'      => '',
    'nombre'   => '',
    'apellido' => '',
    'cedula'   => '',
    'telefono' => '',
    'email'    => '',
    'terminos' => ''
];

/** ============= Helper para responder OK a Omada ============= */

function responder_ok_y_salir(string $redirect_url = '') {
    if (!empty($redirect_url)) {
        header("Location: " . $redirect_url);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><title>Registro Wi-Fi</title></head>
<body style='font-family: Arial, sans-serif; text-align:center; padding:40px;'>
    <h2>✅ Registro completado</h2>
    <p>Ya puedes navegar en Internet.</p>
</body>
</html>";
    exit;
}

/** ============= Manejo POST ============= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("📨 PROCESANDO FORMULARIO POST");

    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = preg_replace('/\D+/', '', $_POST['cedula'] ?? '');
    $telefono = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $terminos = isset($_POST['terminos']) ? 1 : 0;

    // MAC y AP MAC desde campos ocultos
    $mac_post_raw = $_POST['mac']    ?? '';
    $ap_post_raw  = $_POST['ap_mac'] ?? '';

    $mac_norm    = normalize_mac($mac_post_raw);
    $ap_mac_norm = normalize_mac($ap_post_raw);

    // Validaciones (MAC solo a nivel interno, sin mostrar al usuario)
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

            // Verificar si ya existe en radcheck
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
                error_log("✅ MAC ya estaba registrada (POST): $mac_norm");
                responder_ok_y_salir($redirect_url);
            }
            $check_radcheck->close();

            // Insertar cliente
            $stmt_clients = $conn->prepare("
                INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled, ap_mac)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?)
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

            // Insertar en radcheck para RADIUS
            $stmt_radcheck = $conn->prepare("
                INSERT INTO radcheck (username, attribute, op, value)
                VALUES (?, 'Auth-Type', ':=', 'Accept')
            ");
            $stmt_radcheck->bind_param("s", $mac_norm);
            $stmt_radcheck->execute();
            $stmt_radcheck->close();
            error_log("✅ RADCHECK INSERTADO - MAC: $mac_norm");

            $conn->commit();
            error_log("✅ TRANSACCIÓN COMPLETADA");

            responder_ok_y_salir($redirect_url);

        } catch (Exception $e) {
            error_log("❌ ERROR: " . $e->getMessage());
            
            if ($conn->errno == 1062) {
                // Duplicado - ya existe
                error_log("ℹ️ MAC duplicada (ya estaba registrada)");
                responder_ok_y_salir($redirect_url);
            } else {
                $conn->rollback();
                error_log("❌ Transacción revertida");
                header('Content-Type: text/plain; charset=utf-8');
                die('Error en registro');
            }
        }
    } else {
        // Validación fallida - mantener datos en el formulario
        $_POST['nombre']   = $nombre;
        $_POST['apellido'] = $apellido;
        $_POST['cedula']   = $cedula;
        $_POST['telefono'] = $telefono;
        $_POST['email']    = $email;
    }
}

/** ============= Verificar estado de MAC (auto-login Omada) ============= */

$mac_already_registered = false;
if ($mac_norm !== '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $check = $conn->prepare("
            SELECT id FROM radcheck 
            WHERE username = ? AND attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept'
        ");
        $check->bind_param("s", $mac_norm);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            $mac_already_registered = true;
            error_log("ℹ️ MAC ya registrada (GET), auto-login");
            $check->close();
            responder_ok_y_salir($redirect_url);
        }
        $check->close();
    } catch (Exception $e) {
        error_log("⚠️ Error verificando MAC: " . $e->getMessage());
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

                <!-- Hidden: el usuario no ve MAC ni AP ni redirect -->
                <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
                <input type="hidden" name="ap_mac" value="<?php echo htmlspecialchars($ap_raw); ?>">
                <input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip); ?>">
                <input type="hidden" name="redirect_url" value="<?php echo htmlspecialchars($redirect_url); ?>">

                <button type="submit">🚀 Conectar a Internet</button>
            </form>
        <?php endif; ?>
    </div>

    <img src="banner.png" alt="Banner" style="width: 100%; max-width: 400px; border-radius: 15px; margin: 10px 0;">
</body>
</html>
