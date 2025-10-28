<?php
session_start();


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";


function normalize_mac($mac_raw) {
    if (empty($mac_raw)) return '';
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac_raw);
    return strtoupper($hex);
}

function redirect_to_bienvenido($mac_norm, $ip) {
    error_log("🎯 REDIRIGIENDO A BIENVENIDO.PHP CON MAC: $mac_norm");
    
   
    $_SESSION['registration_mac'] = $mac_norm;
    $_SESSION['registration_ip'] = $ip;
    $_SESSION['coa_executed'] = false;
    
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

//  CONFIGURACIONES DEL COA 
function execute_coa($mac, $ap_ip) {
    
    
    $coa_secret = "telecom";
    $coa_port = "4325";
    
    if (empty($mac) || empty($ap_ip)) {
        
        return false;
    }
    
    $command = sprintf(
        'echo "User-Name=%s" | radclient -r 2 -t 3 -x %s:%s disconnect %s 2>&1',
        escapeshellarg($mac),
        escapeshellarg($ap_ip),
        $coa_port,
        escapeshellarg($coa_secret)
    );
    
    error_log("🖥️ COMANDO CoA: $command");
    
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    
    $coa_output = implode(" | ", $output);
    error_log("📋 OUTPUT CoA: " . $coa_output);
    
    $coa_success = false;
    if ($return_var === 0) {
        if (strpos($coa_output, "Received Disconnect-ACK") !== false) {
            error_log("✅ CoA EXITOSO - Disconnect-ACK recibido");
            $coa_success = true;
        } else if (strpos($coa_output, "Received CoA-ACK") !== false) {
            error_log("✅ CoA EXITOSO - CoA-ACK recibido");
            $coa_success = true;
        } else {
            error_log("⚠️ CoA EJECUTADO pero respuesta no esperada");
            $coa_success = true;
        }
    } else {
        error_log("❌ ERROR EN CoA - Código: $return_var");
    }
    
    return $coa_success;
}

/// CONECTANDOME A LA BASE DE DATOS
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    error_log("✅ CONEXIÓN BD EXITOSA");
} catch (Exception $e) {
    error_log("❌ ERROR CONEXIÓN BD: " . $e->getMessage());
    die("<div class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// JALAMOS LOS PARAMETROS
$mac_raw  = $_GET['mac']    ?? $_POST['mac']    ?? '';
$ip_raw   = $_GET['ip']     ?? $_POST['ip']     ?? '';
$url_raw  = $_GET['url']    ?? $_POST['url']    ?? '';
$ap_raw   = $_GET['ap_mac'] ?? $_POST['ap_mac'] ?? '';
$essid    = $_GET['essid']  ?? $_POST['essid']  ?? '';

$mac_norm = normalize_mac($mac_raw);
$ap_norm  = normalize_mac($ap_raw);
$ip       = trim($ip_raw);

error_log("🔍 PARÁMETROS - MAC: '$mac_norm', IP: '$ip'");


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("📨 PROCESANDO FORMULARIO POST");
    
    $nombre   = $_POST['nombre']   ?? '';
    $apellido = $_POST['apellido'] ?? '';
    $cedula   = $_POST['cedula']   ?? '';
    $telefono = $_POST['telefono'] ?? '';
    $email    = $_POST['email']    ?? '';
    $terminos = isset($_POST['terminos']) ? 1 : 0;

    $mac_post  = $_POST['mac'] ?? '';
    $ip_post   = $_POST['ip']  ?? '';

    $mac_norm  = normalize_mac($mac_post);
    $ip        = trim($ip_post);

    error_log("📝 DATOS FORMULARIO: $nombre, MAC: $mac_norm, IP: $ip");

// TERMINOS Y CONDICIONES
    if (!$terminos) {
        error_log("❌ Términos y condiciones no aceptados");
        die("<div class='error'>❌ Debes aceptar los términos y condiciones para registrarte.</div>");
    }

    if ($mac_norm === '') {
        error_log("❌ MAC address vacía o inválida");
        die("<div class='error'>❌ MAC address missing or invalid.</div>");
    }

    try {
        $conn->begin_transaction();
        error_log("🔄 INICIANDO TRANSACCIÓN BD");

        // 1) VERIFICAR SI LA MAC YA EXISTE EN RADCHECK
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
            
            error_log("ℹ️ MAC $mac_norm YA EXISTE en radcheck, ejecutando CoA...");
            
            execute_coa($mac_norm, $ip);
            redirect_to_bienvenido($mac_norm, $ip);
        }
        $check_radcheck->close();

        // INSERTAR A CLIENTES
        $stmt_clients = $conn->prepare("
            INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt_clients->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac_norm);
        $stmt_clients->execute();
        $client_id = $conn->insert_id;
        $stmt_clients->close();
        error_log("✅ CLIENTE INSERTADO con ID: $client_id");

        // 3) INSERT LA MAC A RADCHECK
        $stmt_radcheck = $conn->prepare("
            INSERT INTO radcheck (username, attribute, op, value)
            VALUES (?, 'Auth-Type', ':=', 'Accept')
        ");
        $stmt_radcheck->bind_param("s", $mac_norm);
        $stmt_radcheck->execute();
        $radcheck_id = $conn->insert_id;
        $stmt_radcheck->close();
        error_log("✅ RADCHECK INSERTADO con ID: $radcheck_id");

        $conn->commit();
        error_log("✅ TRANSACCIÓN BD COMPLETADA");
        
        // 4) EJECUTAR CoA
        error_log("🎉 REGISTRO COMPLETADO, EJECUTANDO CoA...");
        execute_coa($mac_norm, $ip);
        
        // 5) REDIRIGIR
        error_log("🔄 REDIRIGIENDO A BIENVENIDO.PHP");
        redirect_to_bienvenido($mac_norm, $ip);

    } catch (Exception $e) {
        error_log("❌ ERROR EN REGISTRO: " . $e->getMessage());
        error_log("❌ CÓDIGO ERROR: " . $conn->errno);
        error_log("❌ MENSAJE ERROR: " . $conn->error);
        
        if ($conn->errno) {
            $conn->rollback();
            error_log("🔄 TRANSACCIÓN REVERTIDA");
        }
        
        if ($conn->errno == 1062) {
            error_log("⚠️ MAC $mac_norm YA EXISTE (error 1062), ejecutando CoA...");
            execute_coa($mac_norm, $ip);
            redirect_to_bienvenido($mac_norm, $ip);
        } else {
            die("<div class='error'>❌ Registration failed: " . htmlspecialchars($e->getMessage()) . " (Error: " . $conn->errno . ")</div>");
        }
    }
}

// VERIFICAR SI LA AMC ES NUEVA O YA EXISTE
$mac_status = 'new';
$client_exists = false;
if ($mac_norm !== '') {
    try {
        // VERIFICACMOS EXISTENCIA EN RADCHECK
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
        
        // VERIFICAR CLIENTES
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
        body { font-family: 'Arial', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; color: #333; }
        .top-image, .bottom-image { width: 100%; max-width: 400px; border-radius: 15px; margin: 10px 0; box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .form-container { background: white; padding: 30px 25px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); width: 100%; max-width: 450px; margin: 20px 0; }
        h2 { color: #2c3e50; text-align: center; margin-bottom: 25px; font-size: 1.8rem; font-weight: 600; }
        .form-group { margin-bottom: 20px; }
        input { width: 100%; padding: 15px; margin: 8px 0; border: 2px solid #e1e8ed; border-radius: 12px; font-size: 1rem; transition: all 0.3s ease; }
        input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        button { width: 100%; padding: 16px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 12px; font-size: 1.1rem; font-weight: 600; cursor: pointer; margin-top: 15px; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3); }
        button:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4); }
        .error { background: #ffebee; color: #c62828; padding: 12px; border-radius: 10px; margin: 15px 0; text-align: center; font-size: 0.9rem; border-left: 4px solid #c62828; }
        .mac-display { background: #f8f9fa; padding: 15px; border-radius: 12px; margin: 15px 0; font-size: 0.95rem; color: #2c3e50; text-align: center; word-wrap: break-word; border: 2px solid #e9ecef; }
        .info-display { background: #e3f2fd; padding: 12px; border-radius: 10px; margin: 10px 0; font-size: 0.9rem; color: #1565c0; text-align: center; border-left: 4px solid #2196f3; }
        .status-info { background: #e8f5e8; padding: 15px; border-radius: 10px; margin: 15px 0; font-size: 0.95rem; color: #2e7d32; text-align: center; border-left: 4px solid #4caf50; font-weight: 500; }
        .warning-info { background: #fff3e0; padding: 15px; border-radius: 10px; margin: 15px 0; font-size: 0.95rem; color: #ef6c00; text-align: center; border-left: 4px solid #ff9800; }
        .required::after { content: " *"; color: #e74c3c; }
        .terminos-container { background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 15px 0; border: 2px solid #e9ecef; }
        .terminos-checkbox { display: flex; align-items: flex-start; gap: 10px; margin: 10px 0; }
        .terminos-checkbox input[type="checkbox"] { width: 20px; height: 20px; margin-top: 2px; }
        .terminos-text { font-size: 0.9rem; color: #555; line-height: 1.4; }
        .terminos-link { color: #667eea; text-decoration: none; font-weight: 500; }
        .terminos-link:hover { text-decoration: underline; }
        @media (max-width: 480px) { .form-container { padding: 25px 20px; border-radius: 15px; margin: 15px 0; } input, button { font-size: 1rem; } h2 { font-size: 1.5rem; } body { padding: 15px; } }
    </style>
</head>
<body>

    <img src="gonetlogo.png" alt="GoNet Logo" class="top-image">

    <div class="form-container">
        <h2> Registro para Wi-Fi</h2>

        <?php if ($mac_norm === ''): ?>
            <div class="error">
                ❌ No se detectó ninguna dirección MAC.<br>
                <small>Conéctate a la red Wi-Fi y accede desde el portal cautivo.</small>
            </div>
        <?php elseif ($mac_status === 'registered'): ?>
            <div class="status-info">
                ✅ Este dispositivo ya está registrado.<br>
                <strong>Serás conectado inmediatamente.</strong>
            </div>
        <?php elseif ($client_exists && $mac_status === 'new'): ?>
            <div class="warning-info">
                ⚠️ Dispositivo registrado pero necesita configuración.<br>
                <strong>Completa el registro para conectar.</strong>
            </div>
        <?php else: ?>
            <div class="info-display">
                📝 Completa el registro para acceder a Internet
            </div>
        <?php endif; ?>

        <?php if ($mac_norm !== ''): ?>
        <form method="POST" autocomplete="on" id="registrationForm">
            <div class="form-group">
                <label class="required">Nombre</label>
                <input type="text" name="nombre" placeholder="Tu nombre" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="required">Apellido</label>
                <input type="text" name="apellido" placeholder="Tu apellido" required value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="required">Cédula</label>
                <input type="text" name="cedula" placeholder="Número de cédula" required value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="required">Teléfono</label>
                <input type="text" name="telefono" placeholder="Número de teléfono" required value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="required">Email</label>
                <input type="email" name="email" placeholder="correo@ejemplo.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="terminos-container">
                <div class="terminos-checkbox">
                    <input type="checkbox" name="terminos" id="terminos" required>
                    <label for="terminos" class="terminos-text">
                        Acepto los <a href="terminos.html" target="_blank" class="terminos-link">Términos y Condiciones</a> 
                        y la <a href="privacidad.html" target="_blank" class="terminos-link">Política de Privacidad</a> 
                        de GoNet Wi-Fi.
                    </label>
                </div>
            </div>

            <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip); ?>">

          

           

            <button type="submit" id="submitBtn">
                <?php echo $mac_status === 'registered' ? '✅ Conectar Ahora' : '🚀 Registrar y Conectar'; ?>
            </button>
        </form>
        <?php endif; ?>
    </div>

    <img src="banner.png" alt="Banner" class="bottom-image">

    <script>
        document.getElementById('registrationForm')?.addEventListener('submit', function(e) {
            const terminosCheckbox = document.getElementById('terminos');
            if (!terminosCheckbox.checked) {
                e.preventDefault();
                alert('Debes aceptar los términos y condiciones para continuar.');
                terminosCheckbox.focus();
                return false;
            }
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '⏳ Procesando...';
            submitBtn.disabled = true;
        });
    </script>

</body>
</html>