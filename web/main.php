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

// ✅ Function to validate Ecuadorian cédula
function validarCedulaEcuatoriana($cedula) {
    if (!preg_match('/^\d{10}$/', $cedula)) return false;

    $provincia = intval(substr($cedula, 0, 2));
    if ($provincia < 1 || $provincia > 24) return false;

    $ultimoDigito = intval(substr($cedula, 9, 1));
    $suma = 0;
    for ($i = 0; $i < 9; $i++) {
        $num = intval($cedula[$i]);
        if ($i % 2 == 0) {
            $num *= 2;
            if ($num > 9) $num -= 9;
        }
        $suma += $num;
    }
    $verificador = 10 - ($suma % 10);
    if ($verificador == 10) $verificador = 0;

    return $verificador == $ultimoDigito;
}

// ✅ Connect to database
$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    log_error("Database connection failed: " . mysqli_connect_error());
    die("Error de conexión a la base de datos.");
}

// ✅ Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = trim($_POST['cedula'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo   = trim($_POST['correo'] ?? '');
    $mac      = strtoupper(trim($_POST['mac'] ?? '')); // optional now

    // ✅ 1. Check required fields (MAC not required)
    if (!$nombre || !$apellido || !$cedula || !$telefono || !$correo) {
        header("Location: principal.html?status=error&message=Todos%20los%20campos%20son%20obligatorios.");
        exit();
    }

    // ✅ 2. Validate cédula
    if (!validarCedulaEcuatoriana($cedula)) {
        header("Location: principal.html?status=error&message=Cédula%20inválida.%20Ingrese%20una%20cédula%20ecuatoriana%20válida.");
        exit();
    }

    // ✅ 3. Validate phone number
    if (!preg_match('/^09\d{8}$/', $telefono)) {
        header("Location: principal.html?status=error&message=El%20teléfono%20debe%20comenzar%20con%2009%20y%20tener%2010%20dígitos.");
        exit();
    }

    // ✅ Insert client record
    $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        log_error("Prepare failed (insert client): " . $conn->error);
        die("Error preparando la inserción del cliente.");
    }

    $stmt->bind_param("sssss", $nombre, $apellido, $cedula, $telefono, $correo);

    if ($stmt->execute()) {
        $client_id = $conn->insert_id;

        // ✅ Optional: Insert into FreeRADIUS *only if MAC exists*
        if (!empty($mac)) {
            $stmt2 = $conn->prepare("
                INSERT INTO radcheck (client_id, username, attribute, op, value)
                VALUES (?, ?, 'Cleartext-Password', ':=', ?)
            ");
            if ($stmt2) {
                $username = $mac;
                $password = $mac;
                $stmt2->bind_param("iss", $client_id, $username, $password);
                if (!$stmt2->execute()) {
                    log_error("Execute failed (radcheck): " . $stmt2->error);
                }
                $stmt2->close();
            } else {
                log_error("Prepare failed (radcheck): " . $conn->error);
            }
        }

        // ✅ Redirect to welcome page
        header("Location: bienvenido.html?status=success&message=Registro%20completado.");
        exit();
    } else {
        log_error("Execute failed (insert client): " . $stmt->error);
        die("Error al registrar el cliente: " . $stmt->error);
    }

    $stmt->close();
} else {
    header("Location: principal.html");
    exit();
}
?>
