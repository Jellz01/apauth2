<?php
// register_client.php - VERSIÓN SIMPLIFICADA SIN GUARDAR TÉRMINOS EN BD

// ----------------------------
// 🐛 HABILITAR DEBUGGING
// ----------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ----------------------------
// 🔧 Database Configuration
// ----------------------------
$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";

// ----------------------------
// 🧰 Helpers
// ----------------------------
function normalize_mac($mac_raw) {
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac_raw);
    return strtoupper($hex);
}

function safe_url_improved($u) {
    $u = trim((string)$u);
    
    // Si está vacío o no es una URL válida, usar bienvenido.html
    if ($u === '' || filter_var($u, FILTER_VALIDATE_URL) === false) {
        error_log("🔄 URL INVALID OR EMPTY, DEFAULTING TO BIENVENIDO");
        return 'bienvenido.html';
    }
    
    return $u;
}

function redirect_or_welcome($url) {
    $url = safe_url_improved($url);
    
    error_log("🎯 REDIRIGIENDO A: " . $url);
    
    if (!headers_sent()) {
        header("Location: " . $url);
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
    } else {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '">
        </head>
        <body>
            <p>Redireccionando... <a href="' . htmlspecialchars($url) . '">Click aquí si no redirige</a></p>
            <script>window.location.href = "' . addslashes($url) . '";</script>
        </body>
        </html>';
    }
    exit;
}

// ----------------------------
// 🔥 FUNCIÓN CoA
// ----------------------------
function execute_coa($mac, $ip) {
    error_log("🔥 EJECUTANDO CoA PARA MAC: $mac, IP: $ip");
    
    // Método 1: Usar radclient para CoA
    $secret = "testing123";
    $coa_command = "echo 'User-Name=$mac' | radclient -x $ip:3799 disconnect $secret 2>&1";
    
    $output = shell_exec($coa_command);
    error_log("📋 OUTPUT CoA: " . $output);
    
    // Verificar si CoA fue exitoso
    if (strpos($output, "Received Disconnect-ACK") !== false) {
        error_log("✅ CoA EXITOSO - Disconnect-ACK recibido");
        return true;
    } else {
        error_log("❌ CoA FALLIDO - No se recibió ACK");
        return false;
    }
}

// ----------------------------
// 🔌 Database Connection
// ----------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    die("<div class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// ----------------------------
// 🧾 Get Parameters
// ----------------------------
$mac_raw  = $_GET['mac']    ?? '';
$ip_raw   = $_GET['ip']     ?? '';
$url_raw  = $_GET['url']    ?? '';
$ap_raw   = $_GET['ap_mac'] ?? '';
$essid    = $_GET['essid']  ?? '';

$mac_norm = normalize_mac($mac_raw);
$ap_norm  = normalize_mac($ap_raw);
$ip       = trim($ip_raw);
$url_in   = $url_raw;

// Debug de parámetros
error_log("🔗 PARÁMETROS RECIBIDOS:");
error_log("   - MAC: $mac_norm");
error_log("   - IP: $ip");
error_log("   - URL: $url_in");
error_log("   - AP: $ap_norm");
error_log("   - ESSID: $essid");

// ----------------------------
// 📥 Process Form Submission
// ----------------------------
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
    $url_post  = $_POST['url'] ?? '';

    $mac_norm  = normalize_mac($mac_post);
    $ip        = trim($ip_post);
    $url_in    = $url_post;

    error_log("📝 DATOS FORMULARIO:");
    error_log("   Nombre: $nombre, MAC: $mac_norm, IP: $ip, URL: $url_in, Términos: $terminos");

    // Validar términos y condiciones (SOLO VALIDACIÓN, NO SE GUARDA EN BD)
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
            // ✅ MAC YA EXISTE EN RADCHECK - Solo ejecutar CoA y redirigir
            $check_radcheck->close();
            $conn->commit();
            
            error_log("ℹ️ MAC $mac_norm YA EXISTE en radcheck, ejecutando CoA...");
            
            // Ejecutar CoA y redirigir
            execute_coa($mac_norm, $ip);
            
            // Debug antes de redirigir
            error_log("🎯 PRE-REDIRECCIÓN - URL: $url_in");
            redirect_or_welcome($url_in);
        }
        $check_radcheck->close();

        // 2) INSERT INTO clients (MAC no existe en radcheck)
        // SOLO insertamos datos básicos, sin campo de términos
        $stmt_clients = $conn->prepare("
            INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt_clients->bind_param("sssssi", $nombre, $apellido, $cedula, $telefono, $email, $mac_norm);
        $stmt_clients->execute();
        $client_id = $conn->insert_id;
        $stmt_clients->close();
        error_log("✅ CLIENTE INSERTADO con ID: $client_id");

        // 3) INSERT INTO radcheck
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
        
        // 4) 🔥 EJECUTAR CoA después del registro exitoso
        error_log("🎉 REGISTRO COMPLETADO, EJECUTANDO CoA...");
        execute_coa($mac_norm, $ip);
        
        // 5) Redirigir con debug extensivo
        error_log("🔄 REDIRIGIENDO A: $url_in");
        error_log("🎯 ESTADO FINAL:");
        error_log("   - MAC: $mac_norm");
        error_log("   - IP: $ip");
        error_log("   - URL: $url_in");
        error_log("   - Headers sent: " . (headers_sent() ? 'YES' : 'NO'));
        
        redirect_or_welcome($url_in);

    } catch (Exception $e) {
        error_log("❌ ERROR EN REGISTRO: " . $e->getMessage());
        if ($conn->errno) {
            $conn->rollback();
            error_log("🔄 TRANSACCIÓN REVERTIDA");
        }
        
        // Si es error de duplicado, probablemente ya existe
        if ($conn->errno == 1062) {
            error_log("⚠️ MAC $mac_norm YA EXISTE (error 1062), ejecutando CoA...");
            execute_coa($mac_norm, $ip);
            redirect_or_welcome($url_in);
        } else {
            die("<div class='error'>❌ Registration failed: " . htmlspecialchars($e->getMessage()) . "</div>");
        }
    }
}

// Verificar estado actual de la MAC para mostrar en el formulario
$mac_status = 'new';
$client_exists = false;
if ($mac_norm !== '') {
    try {
        // Verificar en radcheck
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
        
        // Verificar en clients
        $check_clients_display = $conn->prepare("SELECT id FROM clients WHERE mac = ?");
        $check_clients_display->bind_param("s", $mac_norm);
        $check_clients_display->execute();
        $check_clients_display->store_result();
        
        if ($check_clients_display->num_rows > 0) {
            $client_exists = true;
        }
        $check_clients_display->close();
        
    } catch (Exception $e) {
        // Silently continue if check fails
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
        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
        }
        
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
        
        h2 { 
            color: #2c3e50; 
            text-align: center; 
            margin-bottom: 25px; 
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        input {
            width: 100%; 
            padding: 15px; 
            margin: 8px 0; 
            border: 2px solid #e1e8ed; 
            border-radius: 12px; 
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        button {
            width: 100%; 
            padding: 16px; 
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white; 
            border: none; 
            border-radius: 12px;
            font-size: 1.1rem; 
            font-weight: 600;
            cursor: pointer; 
            margin-top: 15px; 
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        button:hover { 
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        button:disabled {
            background: #cccccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .error {
            background: #ffebee; 
            color: #c62828; 
            padding: 12px; 
            border-radius: 10px; 
            margin: 15px 0;
            text-align: center; 
            font-size: 0.9rem; 
            border-left: 4px solid #c62828;
        }
        
        .mac-display {
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 12px; 
            margin: 15px 0; 
            font-size: 0.95rem;
            color: #2c3e50; 
            text-align: center; 
            word-wrap: break-word;
            border: 2px solid #e9ecef;
        }
        
        .info-display {
            background: #e3f2fd; 
            padding: 12px; 
            border-radius: 10px; 
            margin: 10px 0; 
            font-size: 0.9rem;
            color: #1565c0; 
            text-align: center;
            border-left: 4px solid #2196f3;
        }
        
        .status-info {
            background: #e8f5e8; 
            padding: 15px; 
            border-radius: 10px; 
            margin: 15px 0; 
            font-size: 0.95rem;
            color: #2e7d32; 
            text-align: center;
            border-left: 4px solid #4caf50;
            font-weight: 500;
        }
        
        .warning-info {
            background: #fff3e0; 
            padding: 15px; 
            border-radius: 10px; 
            margin: 15px 0; 
            font-size: 0.95rem;
            color: #ef6c00; 
            text-align: center;
            border-left: 4px solid #ff9800;
        }
        
        .required::after {
            content: " *";
            color: #e74c3c;
        }
        
        .terminos-container {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
            border: 2px solid #e9ecef;
        }
        
        .terminos-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 10px 0;
        }
        
        .terminos-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 2px;
        }
        
        .terminos-text {
            font-size: 0.9rem;
            color: #555;
            line-height: 1.4;
        }
        
        .terminos-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .terminos-link:hover {
            text-decoration: underline;
        }
        
        .debug-info {
            background: #f0f0f0;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
            font-size: 0.8rem;
            color: #666;
            border-left: 4px solid #999;
        }
        
        @media (max-width: 480px) {
            .form-container { 
                padding: 25px 20px; 
                border-radius: 15px; 
                margin: 15px 0;
            }
            
            input, button { 
                font-size: 1rem; 
            }
            
            h2 { 
                font-size: 1.5rem; 
            }
            
            body {
                padding: 15px;
            }
        }
    </style>
</head>
<body>

    <!-- Top banner -->
    <img src="gonetlogo.png" alt="GoNet Logo" class="top-image">

    <div class="form-container">
        <h2>📡 Registro para Wi-Fi</h2>

        <?php if ($mac_status === 'registered'): ?>
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

        <!-- Debug info (opcional - puedes eliminar esto en producción) -->
        <div class="debug-info">
            <strong>Info de Conexión:</strong><br>
            MAC: <?php echo htmlspecialchars($mac_norm); ?><br>
            IP: <?php echo htmlspecialchars($ip); ?><br>
            URL: <?php echo htmlspecialchars($url_in); ?>
        </div>

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

            <!-- Términos y Condiciones - SOLO VALIDACIÓN EN FRONTEND -->
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

            <!-- Hidden fields -->
            <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip); ?>">
            <input type="hidden" name="url" value="<?php echo htmlspecialchars($url_in); ?>">

            <!-- Device Information -->
            <?php if ($mac_norm !== ''): ?>
                <div class="mac-display">
                    <strong>🔧 Dispositivo MAC:</strong><br>
                    <code><?php echo htmlspecialchars($mac_norm); ?></code>
                </div>
            <?php endif; ?>

            <?php if ($ip !== ''): ?>
                <div class="info-display">
                    <strong>🌐 Dirección IP:</strong> <?php echo htmlspecialchars($ip); ?>
                </div>
            <?php endif; ?>

            <?php if ($essid !== ''): ?>
                <div class="info-display">
                    <strong>📶 Red Wi-Fi:</strong> <?php echo htmlspecialchars($essid); ?>
                </div>
            <?php endif; ?>

            <?php if ($ap_norm !== ''): ?>
                <div class="info-display">
                    <strong>📡 Punto de Acceso:</strong> <?php echo htmlspecialchars($ap_norm); ?>
                </div>
            <?php endif; ?>

            <button type="submit" id="submitBtn">
                <?php echo $mac_status === 'registered' ? '✅ Conectar Ahora' : '🚀 Registrar y Conectar'; ?>
            </button>
        </form>
    </div>

    <!-- Bottom banner -->
    <img src="banner.png" alt="Banner" class="bottom-image">

    <script>
        // Validación de términos antes de enviar el formulario
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const terminosCheckbox = document.getElementById('terminos');
            if (!terminosCheckbox.checked) {
                e.preventDefault();
                alert('Debes aceptar los términos y condiciones para continuar.');
                terminosCheckbox.focus();
                return false;
            }
            
            // Mostrar loading en el botón
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '⏳ Procesando...';
            submitBtn.disabled = true;
        });
    </script>

</body>
</html>