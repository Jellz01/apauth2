<?php
// Database configuration
$host = 'mysql_server';
$db   = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("❌ Database connection failed: " . $conn->connect_error);
}

// Get MAC from Aruba redirect (URL parameter)
$mac = isset($_GET['mac']) ? strtolower(trim($_GET['mac'])) : '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = $_POST['nombre'];
    $apellido = $_POST['apellido'];
    $cedula = $_POST['cedula'];
    $telefono = $_POST['telefono'];
    $email = $_POST['email'];
    $mac = strtolower(trim($_POST['mac']));

    // Avoid duplicates
    $check = $conn->prepare("SELECT COUNT(*) FROM clients WHERE mac = ?");
    $check->bind_param("s", $mac);
    $check->execute();
    $check->bind_result($exists);
    $check->fetch();
    $check->close();

    if ($exists == 0) {
        // Insert client — enabled = 1 triggers automatic sync to radcheck
        $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled)
                                VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac);
        if ($stmt->execute()) {
            echo "<h3>✅ Registration successful! Your device is now authorized.</h3>";
        } else {
            echo "<h3>⚠️ Database error: " . $stmt->error . "</h3>";
        }
        $stmt->close();
    } else {
        echo "<h3>ℹ️ This device is already registered and authorized.</h3>";
    }
    $conn->close();
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Public Wi-Fi Registration</title>
</head>
<body>
  <h2>Register to Access Free Wi-Fi</h2>
  <form method="POST">
    <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac); ?>">

    <label>Nombre:</label><br>
    <input type="text" name="nombre" required><br><br>

    <label>Apellido:</label><br>
    <input type="text" name="apellido" required><br><br>

    <label>Cédula:</label><br>
    <input type="text" name="cedula" required><br><br>

    <label>Teléfono:</label><br>
    <input type="text" name="telefono" required><br><br>

    <label>Correo electrónico:</label><br>
    <input type="email" name="email" required><br><br>

    <button type="submit">Registrar Dispositivo</button>
  </form>
</body>
</html>
