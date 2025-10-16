<?php
$host = 'mysql_server';
$db   = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = trim($_POST['cedula'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo   = trim($_POST['correo'] ?? '');
    $mac_from_form = $_POST['mac'] ?? ''; // **keep exactly as received**

    // Validate required fields
    if (!$nombre || !$apellido || !$cedula || !$telefono || !$correo) {
        $error = "Todos los campos son obligatorios.";
    } else {
        $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            die("DB prepare error: " . $conn->error);
        }
        $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $mac_from_form);

        if ($stmt->execute()) {
            $stmt->close();
            mysqli_close($conn);
            header("Location: bienvenido.html?status=success&message=Registro completado");
            exit();
        } else {
            $error = "Error al registrar cliente: " . $stmt->error;
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registro - WiFi Público</title>
<style>
* { box-sizing: border-box; }
body { font-family: monospace; background: #f4f4f4; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 10px; }
.form-container { background: white; padding: 25px 20px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); width: 100%; max-width: 400px; margin: 15px 0; font-family: monospace; }
h2 { text-align: center; margin-bottom: 20px; }
input { width: 100%; padding: 12px; margin: 8px 0; border: 1px solid #ddd; border-radius: 8px; font-family: monospace; }
button { width: 100%; padding: 14px; background: #667eea; color: white; border: none; border-radius: 8px; cursor: pointer; }
.error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin: 10px 0; text-align: center; font-size: 0.9rem; display: block; }
</style>
</head>
<body>

<div class="form-container">
<h2>BIENVENIDOS</h2>

<?php if(!empty($error)) echo "<div class='error'>$error</div>"; ?>

<form method="POST" action="">
    <input type="text" name="nombre" placeholder="Nombre" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
    <input type="text" name="apellido" placeholder="Apellido" required value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>">
    <input type="text" name="cedula" placeholder="Cédula" required value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>">
    <input type="tel" name="telefono" placeholder="Teléfono" required value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
    <input type="email" name="correo" placeholder="Correo electrónico" required value="<?php echo htmlspecialchars($_POST['correo'] ?? ''); ?>">

    <!-- Keep MAC exactly as received -->
    <input type="hidden" name="mac" value="<?php echo htmlspecialchars($_GET['client_mac'] ?? ''); ?>">

    <button type="submit">Registrarse</button>
</form>
</div>

</body>
</html>
