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

// -----------------------------
// Get MAC from Aruba redirect
// -----------------------------
$mac = isset($_GET['client_mac']) ? strtolower(trim($_GET['client_mac'])) : '';

// Limpiar la MAC (quitar guiones, dos puntos, espacios)
$mac = str_replace([':', '-', ' ', '.'], '', $mac);

// -----------------------------
// Handle form submission
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Always use null coalescing + trim to avoid undefined-key warnings
    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = trim($_POST['cedula']   ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo   = trim($_POST['correo']   ?? '');
    $mac_post = strtolower(trim($_POST['mac'] ?? ''));
    
    // Usar la MAC del POST si existe, si no, la del GET
    $mac = $mac_post !== '' ? str_replace([':', '-', ' ', '.'], '', $mac_post) : $mac;

    // Validate required fields
    if ($nombre === '' || $apellido === '' || $cedula === '' || $telefono === '' || $correo === '' || $mac === '') {
        echo "<h3>⚠️ Faltan campos obligatorios. Intente nuevamente.</h3>";
        echo "<a href='?client_mac=" . urlencode($mac) . "'>← Volver al formulario</a>";
        exit;
    }

    // Validar formato de MAC (debe tener 12 caracteres hexadecimales)
    if (!preg_match('/^[0-9a-f]{12}$/i', $mac)) {
        echo "<h3>⚠️ Formato de MAC inválido: " . htmlspecialchars($mac) . "</h3>";
        echo "<a href='?client_mac=" . urlencode($mac) . "'>← Volver al formulario</a>";
        exit;
    }

    // Formatear MAC para FreeRADIUS (con dos puntos cada 2 caracteres)
    $mac_formatted = implode(':', str_split($mac, 2));

    // Check if MAC already registered
    $check = $conn->prepare("SELECT enabled FROM clients WHERE mac = ? OR mac = ?");
    $check->bind_param("ss", $mac, $mac_formatted);
    $check->execute();
    $check->bind_result($enabled);
    $exists = $check->fetch();
    $check->close();

    if ($exists) {
        // MAC ya existe
        if ($enabled == 1) {
            // Ya está habilitada
            echo "<div style='text-align: center; padding: 50px; font-family: Arial;'>";
            echo "<h2>ℹ️ Dispositivo ya registrado</h2>";
            echo "<p>Este dispositivo (<code>$mac_formatted</code>) ya está autorizado.</p>";
            echo "<p>Ya puedes navegar en Internet.</p>";
            echo "<hr>";
            echo "<small><a href='http://google.com'>Continuar navegando</a></small>";
            echo "</div>";
            echo "<script>setTimeout(function(){ window.location.href = 'http://google.com'; }, 2000);</script>";
        } else {
            // Existe pero no está habilitada - activarla
            $update = $conn->prepare("UPDATE clients SET enabled = 1 WHERE mac = ? OR mac = ?");
            $update->bind_param("ss", $mac, $mac_formatted);
            $update->execute();
            $update->close();
            
            echo "<div style='text-align: center; padding: 50px; font-family: Arial;'>";
            echo "<h2>✅ ¡Dispositivo activado!</h2>";
            echo "<p>Tu dispositivo (<code>$mac_formatted</code>) ha sido habilitado.</p>";
            echo "<p>Ya puedes navegar en Internet.</p>";
            echo "<hr>";
            echo "<small><a href='http://google.com'>Continuar navegando</a></small>";
            echo "</div>";
            echo "<script>setTimeout(function(){ window.location.href = 'http://google.com'; }, 2000);</script>";
        }
        $conn->close();
        exit;
    }

    // MAC no existe - registrar nuevo usuario
    $conn->begin_transaction();
    
    try {
        // 1. Insertar en tabla clients con enabled = 1 (acceso inmediato)
        $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled)
                                VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $mac_formatted);
        $stmt->execute();
        $stmt->close();

        // Confirmar transacción
        $conn->commit();

        echo "<div style='text-align: center; padding: 50px; font-family: Arial;'>";
        echo "<h2>✅ ¡Registro exitoso!</h2>";
        echo "<p>Bienvenido/a <strong>$nombre $apellido</strong></p>";
        echo "<p>Tu dispositivo (<code>$mac_formatted</code>) ha sido autorizado.</p>";
        echo "<p>Ya puedes navegar en Internet.</p>";
        echo "<hr>";
        echo "<small>Si no te redirige automáticamente, <a href='http://google.com'>haz clic aquí</a></small>";
        echo "</div>";
        
        // Redireccionar automáticamente después de 3 segundos
        echo "<script>setTimeout(function(){ window.location.href = 'http://google.com'; }, 3000);</script>";

    } catch (Exception $e) {
        // Revertir transacción en caso de error
        $conn->rollback();
        echo "<h3>⚠️ Error al registrar: " . htmlspecialchars($e->getMessage()) . "</h3>";
        echo "<a href='?client_mac=" . urlencode($mac) . "'>← Volver al formulario</a>";
    }

    $conn->close();
    exit;
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
.mac-display { background: #f9f9f9; padding: 10px; border-radius: 8px; margin-top: 12px; font-size: 0.9rem; color: #333; text-align: center; word-wrap: break-word; display: none; }
.error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin: 10px 0; text-align: center; font-size: 0.9rem; display: block; }
@media (max-width: 480px) { .form-container { padding: 20px 15px; border-radius: 10px; } input, button { font-size: 1rem; } h2 { font-size: 1.3rem; } }
</style>
</head>
<body>

<img src="gonetlogo.png" alt="WiFi Banner" class="top-image">

<div class="form-container">
<h2>BIENVENIDOS</h2>

<?php if(!empty($error)) echo "<div class='error'>$error</div>"; ?>

<form id="wifiForm" method="POST" action="">
    <input type="text" id="nombre" name="nombre" placeholder="Nombre" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
    <input type="text" id="apellido" name="apellido" placeholder="Apellido" required value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>">
    <input type="text" id="cedula" name="cedula" placeholder="Cédula" required value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>">
    <input type="tel" id="telefono" name="telefono" placeholder="Teléfono" required pattern="09[0-9]{8}" value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
    <input type="email" id="correo" name="correo" placeholder="Correo electrónico" required value="<?php echo htmlspecialchars($_POST['correo'] ?? ''); ?>">

    <!-- Hidden MAC (from AP) -->
    <input type="hidden" name="mac" value="<?php echo htmlspecialchars($_GET['client_mac'] ?? ''); ?>">

    <button type="submit">Registrarse</button>
    <div class="mac-display" id="macDisplay"></div>
</form>
</div>

<img src="banner.png" alt="WiFi Footer" class="bottom-image">

<script>
// Show the MAC on the form for debugging
document.addEventListener("DOMContentLoaded", () => {
    const params = new URLSearchParams(window.location.search);
    const mac = params.get("client_mac") || "No MAC";
    const macDisplay = document.getElementById("macDisplay");
    if (mac !== "No MAC") {
        macDisplay.style.display = "block";
        macDisplay.textContent = "MAC: " + mac;
    }
});
</script>

</body>
</html>