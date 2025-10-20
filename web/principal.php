<?php
// -----------------------------
// Database connection
// -----------------------------
$host = 'mysql_server';
$db   = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("❌ Database connection failed: " . $conn->connect_error);
}

// Enable error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// -----------------------------
// Get MAC from Aruba redirect (GET or POST)
// -----------------------------
$mac = '';
$debug_mode = true; // Set to false in production

// Try GET parameters first (most common)
if (isset($_GET['client_mac']) && $_GET['client_mac'] !== '' && $_GET['client_mac'] !== '$client_mac$') {
    $mac = $_GET['client_mac'];
} 
// Try POST parameters (some Aruba versions)
elseif (isset($_POST['client_mac']) && $_POST['client_mac'] !== '' && $_POST['client_mac'] !== '$client_mac$') {
    $mac = $_POST['client_mac'];
}
// Try alternative parameter names
elseif (isset($_GET['mac']) && $_GET['mac'] !== '') {
    $mac = $_GET['mac'];
}
elseif (isset($_GET['sta']) && $_GET['sta'] !== '') {
    $mac = $_GET['sta'];
}

$mac = strtolower(trim($mac));

// Debug mode: Show what we received
if ($debug_mode && $mac === '' || $mac === '$client_mac$' || strpos($mac, '$') !== false) {
    echo "<div style='text-align: center; padding: 50px; font-family: Arial;'>";
    echo "<h2>⚠️ Error: No se detectó la dirección MAC</h2>";
    echo "<p>El sistema no pudo obtener automáticamente tu MAC.</p>";
    echo "<hr>";
    echo "<h3>🔧 Información de Debug:</h3>";
    echo "<pre style='background: #f0f0f0; padding: 15px; border-radius: 8px; text-align: left; max-width: 600px; margin: 20px auto; overflow-x: auto;'>";
    echo "<strong>GET Parameters:</strong>\n";
    print_r($_GET);
    echo "\n<strong>POST Parameters:</strong>\n";
    print_r($_POST);
    echo "\n<strong>Request URI:</strong>\n";
    echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'N/A');
    echo "</pre>";
    echo "<p><strong>Solución temporal:</strong> Ingresa tu MAC manualmente abajo</p>";
    
    // Show manual form
    echo '<form method="GET" style="max-width: 400px; margin: 20px auto;">
            <input type="text" name="client_mac" placeholder="aa:bb:cc:dd:ee:ff" 
                   pattern="[0-9A-Fa-f:-]{12,17}" required 
                   style="width: 100%; padding: 12px; margin: 8px 0; border: 1px solid #ddd; border-radius: 8px;">
            <button type="submit" style="width: 100%; padding: 14px; background: #667eea; color: white; 
                   border: none; border-radius: 8px; cursor: pointer;">Continuar</button>
          </form>';
    exit;
}

// Limpiar la MAC (quitar guiones, dos puntos, espacios)
$mac_clean = str_replace([':', '-', ' ', '.'], '', $mac);

// Validar formato de MAC (debe tener 12 caracteres hexadecimales)
if (!preg_match('/^[0-9a-f]{12}$/i', $mac_clean)) {
    echo "<h3>⚠️ Formato de MAC inválido: " . htmlspecialchars($mac) . "</h3>";
    echo "<a href='?client_mac=" . urlencode($mac) . "'>← Volver al formulario</a>";
    exit;
}

// Formatear MAC para FreeRADIUS (con dos puntos cada 2 caracteres)
$mac_formatted = implode(':', str_split($mac_clean, 2));

// -----------------------------
// Handle form submission
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['nombre'])) {

    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = trim($_POST['cedula']   ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo   = trim($_POST['correo']   ?? '');

    // Validate required fields
    if ($nombre === '' || $apellido === '' || $cedula === '' || $telefono === '' || $correo === '') {
        $error = "⚠️ Todos los campos son obligatorios";
    } else {
        
        try {
            // Check if MAC already registered
            $check = $conn->prepare("SELECT id, enabled FROM clients WHERE mac = ?");
            $check->bind_param("s", $mac_formatted);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                
                if ($row['enabled'] == 1) {
                    // Already registered and enabled
                    echo "<div style='text-align: center; padding: 50px; font-family: Arial;'>";
                    echo "<h2>ℹ️ Dispositivo ya registrado</h2>";
                    echo "<p>Este dispositivo (<code>$mac_formatted</code>) ya está autorizado.</p>";
                    echo "<p>Ya puedes navegar en Internet.</p>";
                    echo "<hr>";
                    echo "<small><a href='http://google.com'>Continuar navegando</a></small>";
                    echo "</div>";
                    echo "<script>setTimeout(function(){ window.location.href = 'http://google.com'; }, 2000);</script>";
                } else {
                    // Update to enable
                    $update = $conn->prepare("UPDATE clients SET enabled = 1, nombre = ?, apellido = ?, telefono = ?, email = ? WHERE mac = ?");
                    $update->bind_param("sssss", $nombre, $apellido, $telefono, $correo, $mac_formatted);
                    $update->execute();
                    
                    echo "<div style='text-align: center; padding: 50px; font-family: Arial;'>";
                    echo "<h2>✅ ¡Dispositivo activado!</h2>";
                    echo "<p>Bienvenido/a <strong>$nombre $apellido</strong></p>";
                    echo "<p>Tu dispositivo ha sido habilitado.</p>";
                    echo "<hr>";
                    echo "<small><a href='http://google.com'>Continuar navegando</a></small>";
                    echo "</div>";
                    echo "<script>setTimeout(function(){ window.location.href = 'http://google.com'; }, 2000);</script>";
                }
                $check->close();
                $conn->close();
                exit;
            }
            
            $check->close();
            
            // MAC not registered - insert new client
            $conn->begin_transaction();
            
            // Insert into clients table with enabled = 1
            $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled, created_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $mac_formatted);
            
            if (!$stmt->execute()) {
                throw new Exception("Error al insertar en clients: " . $stmt->error);
            }
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            echo "<div style='text-align: center; padding: 50px; font-family: Arial;'>";
            echo "<h2>✅ ¡Registro exitoso!</h2>";
            echo "<p>Bienvenido/a <strong>$nombre $apellido</strong></p>";
            echo "<p>Tu dispositivo (<code>$mac_formatted</code>) ha sido autorizado.</p>";
            echo "<p>Ya puedes navegar en Internet.</p>";
            echo "<hr>";
            echo "<small>Si no te redirige automáticamente, <a href='http://google.com'>haz clic aquí</a></small>";
            echo "</div>";
            
            echo "<script>setTimeout(function(){ window.location.href = 'http://google.com'; }, 3000);</script>";
            
            $conn->close();
            exit;
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            $error = "Error al registrar: " . htmlspecialchars($e->getMessage());
        }
    }
}

// -----------------------------
// Display registration form
// -----------------------------
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Registro - WiFi Público</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 10px; }
.top-image, .bottom-image { width: 100%; max-width: 400px; border-radius: 10px; }
.form-container { background: white; padding: 25px 20px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); width: 100%; max-width: 400px; margin: 15px 0; }
h2 { color: #333; text-align: center; margin-bottom: 20px; font-size: 1.4rem; }
input { width: 100%; padding: 12px; margin: 8px 0; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; }
button { width: 100%; padding: 14px; background: #667eea; color: white; border: none; border-radius: 8px; font-size: 1.05rem; cursor: pointer; margin-top: 10px; transition: background 0.3s ease; }
button:hover { background: #5568d3; }
.mac-display { background: #f9f9f9; padding: 10px; border-radius: 8px; margin-top: 12px; font-size: 0.9rem; color: #333; text-align: center; word-wrap: break-word; }
.error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin: 10px 0; text-align: center; font-size: 0.9rem; display: block; }
.success { background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 8px; margin: 10px 0; text-align: center; font-size: 0.9rem; display: block; }
@media (max-width: 480px) { .form-container { padding: 20px 15px; border-radius: 10px; } input, button { font-size: 1rem; } h2 { font-size: 1.3rem; } }
</style>
</head>
<body>

<img src="gonetlogo.png" alt="WiFi Banner" class="top-image" onerror="this.style.display='none'">

<div class="form-container">
<h2>BIENVENIDOS</h2>

<?php if(isset($error)) echo "<div class='error'>$error</div>"; ?>

<form method="POST" action="">
    <input type="text" name="nombre" placeholder="Nombre" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
    <input type="text" name="apellido" placeholder="Apellido" required value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>">
    <input type="text" name="cedula" placeholder="Cédula" required value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>">
    <input type="tel" name="telefono" placeholder="Teléfono" required pattern="09[0-9]{8}" value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
    <input type="email" name="correo" placeholder="Correo electrónico" required value="<?php echo htmlspecialchars($_POST['correo'] ?? ''); ?>">

    <button type="submit">Registrarse</button>
    
    <div class="mac-display">
        <strong>Dispositivo:</strong> <?php echo htmlspecialchars($mac_formatted); ?>
    </div>
</form>
</div>

<img src="banner.png" alt="WiFi Footer" class="bottom-image" onerror="this.style.display='none'">

</body>
</html>

<?php $conn->close(); ?>