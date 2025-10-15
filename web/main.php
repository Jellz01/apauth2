<?php
$host = 'mysql_server';
$db   = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    die("Error de conexión a la base de datos.");
}

// 1️⃣ Get MAC from query parameter or AP header
$mac = strtoupper(trim($_GET['mac'] ?? $_SERVER['HTTP_X_CLIENT_MAC'] ?? ''));

// 2️⃣ Debug: log MAC for troubleshooting
file_put_contents('mac_debug.log', date('Y-m-d H:i:s') . " MAC received: '$mac'\n", FILE_APPEND);

// 3️⃣ Validate MAC format
if (!$mac || !preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/', $mac)) {
    file_put_contents('mac_debug.log', date('Y-m-d H:i:s') . " Invalid MAC\n", FILE_APPEND);
    die("MAC inválida. Verifique mac_debug.log para detalles.");
}

// 4️⃣ Check if MAC is already registered
$stmtCheck = $conn->prepare("SELECT * FROM clients WHERE mac_address = ?");
$stmtCheck->bind_param("s", $mac);
$stmtCheck->execute();
$result = $stmtCheck->get_result();

if ($result->num_rows > 0) {
    $client = $result->fetch_assoc();
    if ($client['approved'] == 1) {
        echo "Usuario ya registrado y aprobado. Puede autenticarse con RADIUS.";
    } else {
        echo "Usuario registrado pero aún no aprobado por el administrador.";
    }
    exit();
}

// 5️⃣ Process form submission for new MAC
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $cedula   = trim($_POST['cedula']);
    $telefono = trim($_POST['telefono']);
    $correo   = trim($_POST['correo']);

    // Validate all required fields
    if (!$nombre || !$apellido || !$cedula || !$telefono || !$correo) {
        header("Location: principal.html?status=error&message=Todos%20los%20campos%20son%20obligatorios.");
        exit();
    }

    // Insert client record
    $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac_address, approved)
                            VALUES (?, ?, ?, ?, ?, ?, 1)"); // approved=1 for immediate access
    $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $mac);

    if ($stmt->execute()) {
        $client_id = $conn->insert_id;

        // Insert RADIUS entry
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
    // No POST → redirect to the registration form
    header("Location: principal.html?status=info&message=Por%20favor%20complete%20el%20formulario%20para%20registrarse.&mac=$mac");
    exit();
}
?>
