<?php
$host = 'mysql_server';
$db   = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Error de conexión a la base de datos.");
}

// Get MAC from AP/captive portal header (replace with your AP header)
$mac = strtoupper(trim($_SERVER['HTTP_X_CLIENT_MAC'] ?? ''));

// For testing: uncomment to hardcode a MAC
// $mac = 'AA:BB:CC:DD:EE:FF';

if (!$mac || !preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/', $mac)) {
    die("MAC inválida.");
}

// Check if the MAC is already registered
$stmtCheck = $conn->prepare("SELECT * FROM clients WHERE mac_address = ?");
$stmtCheck->bind_param("s", $mac);
$stmtCheck->execute();
$result = $stmtCheck->get_result();

if ($result->num_rows > 0) {
    $client = $result->fetch_assoc();

    if ($client['approved'] == 1) {
        // Already registered and approved → allow login
        echo "Usuario ya registrado y aprobado. Puede autenticarse con RADIUS.";
    } else {
        // Registered but not approved yet
        echo "Usuario registrado pero aún no aprobado por el administrador.";
    }
    exit();
}

// MAC not registered → show and process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $cedula   = trim($_POST['cedula']);
    $telefono = trim($_POST['telefono']);
    $correo   = trim($_POST['correo']);

    // Validate required fields
    if (!$nombre || !$apellido || !$cedula || !$telefono || !$correo) {
        header("Location: principal.html?status=error&message=Todos%20los%20campos%20son%20obligatorios.");
        exit();
    }

    // Insert client record
    $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac_address, approved)
                            VALUES (?, ?, ?, ?, ?, ?, 1)"); // approved=1 for immediate connection
    $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $mac);

    if ($stmt->execute()) {
        $client_id = $conn->insert_id;

        // Insert RADIUS entry linked to client_id
        $stmt2 = $conn->prepare("INSERT INTO radcheck (client_id, username, attribute, op, value)
                                 VALUES (?, ?, 'Cleartext-Password', ':=', ?)");
        $stmt2->bind_param("iss", $client_id, $mac, $mac);
        $stmt2->execute();

        header("Location: bienvenido.html?status=success&message=Registro%20completado.%20Ahora%20puede%20conectarse.");
        exit();
    } else {
        header("Location: principal.html?status=error&message=Error%20al%20registrar%20el%20cliente.");
        exit();
    }
} else {
    // No POST → show form
    header("Location: principal.html?status=info&message=Por%20favor%20complete%20el%20formulario%20para%20registrarse.");
    exit();
}
?>
