<?php
// 🔍 DEBUG: Log everything we receive from Aruba
$debug = [
    'timestamp' => date("Y-m-d H:i:s"),
    'GET' => $_GET,
    'POST' => $_POST,
    'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'not set',
    'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? 'not set',
    'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? 'not set',
    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? 'not set',
];
file_put_contents('/tmp/captive_portal_debug.log', print_r($debug, true) . "\n\n", FILE_APPEND);

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

// 🔥 GET MAC FROM URL (Aruba sends it here)
$mac = $_GET['mac'] ?? '';
$mac_clean = strtolower(str_replace([':', '-', '.'], '', $mac));

// Log the MAC for debugging
log_error("MAC received: '$mac' | Cleaned: '$mac_clean' | MAC empty: " . (empty($mac_clean) ? 'YES' : 'NO'));

// ✅ Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = trim($_POST['cedula'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo   = trim($_POST['correo'] ?? '');
    
    // Get MAC from hidden field (passed from the form)
    $mac_from_form = trim($_POST['mac'] ?? '');
    if (!empty($mac_from_form)) {
        $mac_clean = strtolower(str_replace([':', '-', '.'], '', $mac_from_form));
        log_error("MAC from form: '$mac_from_form' | Cleaned: '$mac_clean'");
    }

    // ✅ 1. Check required fields
    if (!$nombre || !$apellido || !$cedula || !$telefono || !$correo) {
        log_error("Missing required fields");
        header("Location: principal.html?status=error&message=Todos%20los%20campos%20son%20obligatorios.");
        exit();
    }

    // ✅ 2. Validate MAC is present
    if (empty($mac_clean)) {
        log_error("MAC address missing during registration. GET mac: '$mac', POST mac: '$mac_from_form'");
        header("Location: principal.html?status=error&message=Error:%20MAC%20address%20no%20detectada.%20Por%20favor%20intente%20nuevamente.");
        exit();
    }

    // ✅ 3. Validate cédula
    if (!validarCedulaEcuatoriana($cedula)) {
        log_error("Invalid cedula: $cedula");
        header("Location: principal.html?status=error&message=Cédula%20inválida.%20Ingrese%20una%20cédula%20ecuatoriana%20válida.");
        exit();
    }

    // ✅ 4. Validate phone number
    if (!preg_match('/^09\d{8}$/', $telefono)) {
        log_error("Invalid phone: $telefono");
        header("Location: principal.html?status=error&message=El%20teléfono%20debe%20comenzar%20con%2009%20y%20tener%2010%20dígitos.");
        exit();
    }

    // ✅ 5. Insert client record
    $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        log_error("Prepare failed (insert client): " . $conn->error);
        die("Error preparando la inserción del cliente.");
    }

    $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $mac_clean);

    if ($stmt->execute()) {
        $client_id = $conn->insert_id;
        log_error("Client registered with ID: $client_id, MAC: $mac_clean");

        // ✅ 6. Insert MAC into radcheck for authentication
        $stmt2 = $conn->prepare("
            INSERT INTO radcheck (username, attribute, op, value)
            VALUES (?, 'Cleartext-Password', ':=', ?)
        ");
        
        if ($stmt2) {
            $stmt2->bind_param("ss", $mac_clean, $mac_clean);
            if ($stmt2->execute()) {
                log_error("MAC $mac_clean added to radcheck successfully");
            } else {
                log_error("Execute failed (radcheck): " . $stmt2->error);
            }
            $stmt2->close();
        } else {
            log_error("Prepare failed (radcheck): " . $conn->error);
        }

        // ✅ 7. Optional: Add session timeout (24 hours)
        $stmt3 = $conn->prepare("
            INSERT INTO radreply (username, attribute, op, value)
            VALUES (?, 'Session-Timeout', ':=', '86400')
        ");
        
        if ($stmt3) {
            $stmt3->bind_param("s", $mac_clean);
            $stmt3->execute();
            $stmt3->close();
        }

        // ✅ 8. Redirect to welcome page
        header("Location: bienvenido.html?status=success&message=Registro%20completado.");
        exit();
    } else {
        log_error("Execute failed (insert client): " . $stmt->error);
        die("Error al registrar el cliente: " . $stmt->error);
    }

    $stmt->close();
} else {
    // Show form with MAC pre-filled
    if (!empty($mac)) {
        log_error("Redirecting to form with MAC: $mac");
        header("Location: principal.html?mac=" . urlencode($mac));
    } else {
        log_error("No MAC received, redirecting to form without MAC");
        header("Location: principal.html");
    }
    exit();
}

mysqli_close($conn);
?>