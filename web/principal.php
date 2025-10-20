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
$mac = isset($_GET['mac']) ? strtolower(trim($_GET['mac'])) : '';

// -----------------------------
// Handle form submission
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre   = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $cedula   = trim($_POST['cedula']);
    $telefono = trim($_POST['telefono']);
    $email    = trim($_POST['email']);
    $mac      = strtolower(trim($_POST['mac']));

    // Check if MAC already registered
    $check = $conn->prepare("SELECT COUNT(*) FROM clients WHERE mac = ?");
    $check->bind_param("s", $mac);
    $check->execute();
    $check->bind_result($exists);
    $check->fetch();
    $check->close();

    if ($exists == 0) {
        // Insert new client (enabled=1 triggers radcheck sync via trigger)
        $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled)
                                VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac);
        if ($stmt->execute()) {
            echo "<h3>✅ Registro exitoso. Tu dispositivo ha sido autorizado.</h3>";
        } else {
            echo "<h3>⚠️ Error al registrar: " . $stmt->error . "</h3>";
        }
        $stmt->close();
    } else {
        echo "<h3>ℹ️ Este dispositivo ya está registrado y autorizado.</h3>";
    }

    $conn->close();
    exit;
}
?>
