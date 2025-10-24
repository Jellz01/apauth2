<?php
// register_client.php - VERSIÓN COMPLETA CORREGIDA

// ----------------------------
// 🐛 HABILITAR DEBUGGING
// ----------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Log para debugging
error_log("=== INICIANDO REGISTRO CLIENTE ===");

// ----------------------------
// 🔧 Database Configuration
// ----------------------------
$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";

// ----------------------------
// 🧰 Helpers MEJORADOS
// ----------------------------
function normalize_mac($mac_raw) {
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac_raw);
    return strtoupper($hex);
}

function safe_url_improved($u) {
    $u = trim((string)$u);
    if ($u === '') {
        error_log("🔍 URL vacía, usando bienvenido.html por defecto");
        return 'bienvenido.html';
    }
    
    // Si la URL no tiene scheme, agregar http://
    if (!preg_match('/^https?:\/\//i', $u)) {
        $u = 'http://' . $u;
        error_log("🔍 URL sin scheme, agregado http://: " . $u);
    }
    
    $parts = parse_url($u);
    if (!$parts || !isset($parts['host'])) {
        error_log("❌ URL inválida después de parse: " . $u);
        return 'bienvenido.html';
    }
    
    $scheme = strtolower($parts['scheme'] ?? 'http');
    $result = ($scheme === 'http' || $scheme === 'https') ? $u : 'bienvenido.html';
    error_log("🔍 URL final: " . $result);
    
    return $result;
}

function redirect_or_welcome_improved($url) {
    $url = safe_url_improved($url);
    
    // Log para debugging
    error_log("🎯 REDIRIGIENDO A: " . $url);
    
    if (!headers_sent()) {
        error_log("✅ Usando header Location para redirección");
        header("Location: " . $url);
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
    } else {
        error_log("⚠️ Headers ya enviados, usando redirección JavaScript");
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
// 🔥 FUNCIÓN NUEVA: Ejecutar CoA
// ----------------------------
function execute_coa($mac, $ip) {
    error_log("🔥 INICIANDO CoA PARA MAC: $mac, IP: $ip");
    
    // Método 1: Usar radclient para CoA
    $secret = "testing123"; // Cambia esto por tu secret de FreeRADIUS
    $coa_command = "echo 'User-Name=$mac' | radclient -x $ip:3799 disconnect $secret 2>&1";
    error_log("🔧 Comando CoA: " . $coa_command);
    
    $output = shell_exec($coa_command);
    error_log("📋 Output CoA: " . $output);
    
    // Verificar si CoA fue exitoso
    if (strpos($output, "Received Disconnect-ACK") !== false) {
        error_log("✅ CoA EXITOSO - Disconnect-ACK recibido");
    } else {
        error_log("❌ CoA FALLIDO - No se recibió ACK");
    }
    
    return $output;
}

// ----------------------------
// 🔌 Database Connection
// ----------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    error_log("✅ Conexión a BD exitosa");
} catch (Exception $e) {
    error_log("❌ Error conexión BD: " . $e->getMessage());
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
$url_in   = $url_raw; // No aplicar safe_url todavía

// DEBUG logging
error_log("📥 PARÁMETROS RECIBIDOS:");
error_log("   MAC: $mac_raw -> $mac_norm");
error_log("   IP: $ip_raw");
error_log("   URL: $url_raw");
error_log("   AP: $ap_raw -> $ap_norm");
error_log("   ESSID: $essid");

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

    $mac_post  = $_POST['mac'] ?? '';
    $ip_post   = $_POST['ip']  ?? '';
    $url_post  = $_POST['url'] ?? '';

    $mac_norm  = normalize_mac($mac_post);
    $ip        = trim($ip_post);
    $url_in    = $url_post;

    error_log("📝 Datos del formulario:");
    error_log("   Nombre: $nombre");
    error_log("   MAC desde POST: $mac_norm");
    error_log("   IP desde POST: $ip");
    error_log("   URL desde POST: $url_in");

    if ($mac_norm === '') {
        error_log("❌ MAC address vacía o inválida");
        die("<div class='error'>❌ MAC address missing or invalid.</div>");
    }

    try {
        $conn->begin_transaction();
        error_log("🔄 Iniciando transacción BD");

        // 1) Check if already registered
        $check = $conn->prepare("SELECT id FROM clients WHERE mac = ?");
        $check->bind_param("s", $mac_norm);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            error_log("ℹ️ MAC ya registrada en BD, redirigiendo...");
            $check->close();
            $conn->commit();
            
            // 🔥 EJECUTAR CoA incluso si ya está registrado
            error_log("🔥 Ejecutando CoA para MAC existente...");
            execute_coa($mac_norm, $ip);
            
            redirect_or_welcome_improved($url_in);
        }
        $check->close();
        error_log("✅ MAC no registrada, procediendo con registro nuevo");

        // 2) Insert new client
        $stmt = $conn->prepare("
            INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled, registration_date)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac_norm);
        $stmt->execute();
        $client_id = $conn->insert_id;
        $stmt->close();
        error_log("✅ Cliente insertado con ID: $client_id");

        // 3) Add to radcheck
        $rad_sel = $conn->prepare("
            SELECT id FROM radcheck 
            WHERE username = ? AND attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept'
        ");
        $rad_sel->bind_param("s", $mac_norm);
        $rad_sel->execute();
        $rad_sel->store_result();

        if ($rad_sel->num_rows === 0) {
            $rad_sel->close();
            $rad_ins = $conn->prepare("
                INSERT INTO radcheck (username, attribute, op, value)
                VALUES (?, 'Auth-Type', ':=', 'Accept')
            ");
            $rad_ins->bind_param("s", $mac_norm);
            $rad_ins->execute();
            $rad_ins->close();
            error_log("✅ Entrada añadida a radcheck");
        } else {
            $rad_sel->close();
            error_log("ℹ️ Entrada ya existía en radcheck");
        }

        $conn->commit();
        error_log("✅ Transacción BD completada");
        
        // 4) 🔥 EJECUTAR CoA después del registro
        error_log("🎉 REGISTRO COMPLETADO, EJECUTANDO CoA...");
        $coa_result = execute_coa($mac_norm, $ip);
        
        // 5) Redirigir
        error_log("🔄 INICIANDO REDIRECCIÓN FINAL");
        redirect_or_welcome_improved($url_in);

    } catch (Exception $e) {
        error_log("❌ ERROR EN REGISTRO: " . $e->getMessage());
        if ($conn->errno) {
            $conn->rollback();
            error_log("🔄 Transacción revertida");
        }
        die("<div class='error'>❌ Registration failed: " . htmlspecialchars($e->getMessage()) . "</div>");
    }
}

// Si llegamos aquí, mostrar el formulario
error_log("📄 Mostrando formulario de registro");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Client Registration</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* { box-sizing: border-box; }
body {
  font-family: Arial, sans-serif; background: #f4f4f4; display: flex; flex-direction: column;
  align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 10px;
}
.top-image, .bottom-image { width: 100%; max-width: 400px; border-radius: 10px; }
.form-container {
  background: white; padding: 25px 20px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.15);
  width: 100%; max-width: 400px; margin: 15px 0;
}
h2 { color: #333; text-align: center; margin-bottom: 20px; font-size: 1.4rem; }
input {
  width: 100%; padding: 12px; margin: 8px 0; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem;
}
button {
  width: 100%; padding: 14px; background: #667eea; color: white; border: none; border-radius: 8px;
  font-size: 1.05rem; cursor: pointer; margin-top: 10px; transition: background 0.3s ease;
}
button:hover { background: #5568d3; }
.error {
  background: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin: 10px 0;
  text-align: center; font-size: 0.9rem; display: block;
}
.mac-display {
  background: #f9f9f9; padding: 10px; border-radius: 8px; margin-top: 12px; font-size: 0.9rem;
  color: #333; text-align: center; word-wrap: break-word;
}
.info-display {
  background: #e3f2fd; padding: 8px; border-radius: 6px; margin: 6px 0; font-size: 0.85rem;
  color: #1565c0; text-align: center;
}
.debug-info {
  background: #fff3cd; padding: 8px; border-radius: 6px; margin: 6px 0; font-size: 0.75rem;
  color: #856404; text-align: left; display: none; /* Oculto por defecto */
}
@media (max-width: 480px) {
  .form-container { padding: 20px 15px; border-radius: 10px; }
  input, button { font-size: 1rem; }
  h2 { font-size: 1.3rem; }
}
</style>
</head>
<body>

    <!-- Top banner -->
    <img src="gonetlogo.png" alt="Top Banner" class="top-image">

    <div class="form-container">
        <h2>Register to Access Wi-Fi</h2>

        <!-- Debug info (puedes activarlo cambiando display: none a block) -->
        <div class="debug-info">
            <strong>Debug Info:</strong><br>
            MAC: <?php echo htmlspecialchars($mac_norm); ?><br>
            IP: <?php echo htmlspecialchars($ip); ?><br>
            URL: <?php echo htmlspecialchars($url_in); ?>
        </div>

        <form method="POST" autocomplete="on">
            <input type="text" name="nombre"   placeholder="First Name"     required>
            <input type="text" name="apellido" placeholder="Last Name"      required>
            <input type="text" name="cedula"   placeholder="ID / Cédula"    required>
            <input type="text" name="telefono" placeholder="Phone Number"   required>
            <input type="email" name="email"   placeholder="Email"          required>

            <!-- Hidden fields -->
            <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
            <input type="hidden" name="ip"  value="<?php echo htmlspecialchars($ip); ?>">
            <input type="hidden" name="url" value="<?php echo htmlspecialchars($url_in); ?>">

            <!-- Visible info -->
            <?php if ($mac_norm !== ''): ?>
                <div class="mac-display">
                    <strong>Device MAC:</strong><br><?php echo htmlspecialchars($mac_norm); ?>
                </div>
            <?php endif; ?>

            <?php if ($ip !== ''): ?>
                <div class="info-display">
                    <strong>IP:</strong> <?php echo htmlspecialchars($ip); ?>
                </div>
            <?php endif; ?>

            <?php if ($essid !== ''): ?>
                <div class="info-display">
                    <strong>Network:</strong> <?php echo htmlspecialchars($essid); ?>
                </div>
            <?php endif; ?>

            <?php if ($ap_norm !== ''): ?>
                <div class="info-display">
                    <strong>AP MAC:</strong> <?php echo htmlspecialchars($ap_norm); ?>
                </div>
            <?php endif; ?>

            <button type="submit">Register & Connect</button>
        </form>
    </div>

    <!-- Bottom banner -->
    <img src="banner.png" alt="Bottom Banner" class="bottom-image">

</body>
</html>