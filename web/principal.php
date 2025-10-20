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

// -----------------------------
// Handle form submission
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Always use null coalescing + trim to avoid undefined-key warnings
    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = trim($_POST['cedula']   ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo   = trim($_POST['correo']   ?? ''); // matches name="correo"
    $mac      = strtolower(trim($_POST['mac']   ?? $mac));

    // Validate required fields
    if ($nombre === '' || $apellido === '' || $cedula === '' || $telefono === '' || $correo === '' || $mac === '') {
        echo "<h3>⚠️ Faltan campos obligatorios. Intente nuevamente.</h3>";
        exit;
    }

    // Check if MAC already registered
    $check = $conn->prepare("SELECT COUNT(*) FROM clients WHERE mac = ?");
    $check->bind_param("s", $mac);
    $check->execute();
    $check->bind_result($exists);
    $check->fetch();
    $check->close();

    if ((int)$exists === 0) {
        // Insert new client (enabled=1 triggers radcheck sync via trigger)
        $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled)
                                VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $mac);
        if ($stmt->execute()) {
            echo "<h3>✅ Registro exitoso. Tu dispositivo ha sido autorizado.</h3>";
        } else {
            echo "<h3>⚠️ Error al registrar: " . htmlspecialchars($stmt->error) . "</h3>";
        }
        $stmt->close();
    } else {
        echo "<h3>ℹ️ Este dispositivo ya está registrado y autorizado.</h3>";
    }

    $conn->close();
    exit;
}
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
