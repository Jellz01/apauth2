<?php
$host = 'mysql_server';
$db   = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

// Path to log file inside PHP container
$logFile = '/tmp/registration_errors.log';

// Helper function to log errors
function log_error($message) {
    global $logFile;
    file_put_contents($logFile, date("Y-m-d H:i:s") . " - " . $message . "\n", FILE_APPEND);
}

// Connect to database
$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    log_error("Database connection failed: " . mysqli_connect_error());
    die("Error de conexión a la base de datos.");
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $cedula   = trim($_POST['cedula']);
    $telefono = trim($_POST['telefono']);
    $correo   = trim($_POST['correo']);
    $mac      = strtoupper(trim($_POST['mac'])); // 👈 MAC address (uppercase for consistency)

    // Validate required fields
    if (!$nombre || !$apellido || !$cedula || !$telefono || !$correo || !$mac) {
        header("Location: principal.html?status=error&message=Todos%20los%20campos%20son%20obligatorios.");
        exit();
    }

    // Insert client record
    $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        log_error("Prepare failed (insert client): " . $conn->error);
        die("Error preparando la inserción del cliente.");
    }

    $stmt->bind_param("sssss", $nombre, $apellido, $cedula, $telefono, $correo);

    if ($stmt->execute()) {
        $client_id = $conn->insert_id;

        // Insert FreeRADIUS credentials (MAC as both username and password)
        $stmt2 = $conn->prepare("
            INSERT INTO radcheck (client_id, username, attribute, op, value)
            VALUES (?, ?, 'Cleartext-Password', ':=', ?)
        ");
        if (!$stmt2) {
            log_error("Prepare failed (radcheck): " . $conn->error);
            die("Error preparando la inserción en FreeRADIUS.");
        }

        $username = $mac;
        $password = $mac; // 👈 MAC as password too
        $stmt2->bind_param("iss", $client_id, $username, $password);

        if (!$stmt2->execute()) {
            log_error("Execute failed (radcheck): " . $stmt2->error);
            die("Error al insertar en FreeRADIUS.");
        }

        header("Location: bienvenido.html?status=success&message=Registro%20completado.%20Usuario:%20$mac");
        exit();
    } else {
        log_error("Execute failed (insert client): " . $stmt->error);
        die("Error al registrar el cliente: " . $stmt->error);
    }
} else {
    // GET request → redirect to form
    header("Location: principal.html");
    exit();
}
?>
