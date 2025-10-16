<?php

$host = 'mysql_server';
$db   = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    log_error("Database connection failed: " . mysqli_connect_error());
    die("Database connection failed.");
}

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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = trim($_POST['cedula'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo   = trim($_POST['correo'] ?? '');
    $mac_from_form = trim($_POST['mac'] ?? '');

    if (!empty($mac_from_form)) {
        $mac_clean = strtolower(str_replace([':', '-', '.'], '', $mac_from_form));
        log_error("MAC from form detected: '$mac_from_form', Clean='$mac_clean'");
    }

    // 1️⃣ Required fields
    if (!$nombre || !$apellido || !$cedula || !$telefono || !$correo) {
        log_error("Missing required fields");
        header("Location: principal.html?status=error&message=Todos%20los%20campos%20son%20obligatorios&mac=$mac&ip=$ip&ap=$ap");
        exit();
    }

    

    // 3️⃣ Validate cedula
    if (!validarCedulaEcuatoriana($cedula)) {
        log_error("Invalid cedula: $cedula");
        header("Location: principal.html?status=error&message=Cédula%20inválida&mac=$mac&ip=$ip&ap=$ap");
        exit();
    }

    // 4️⃣ Validate phone
    if (!preg_match('/^09\d{8}$/', $telefono)) {
        log_error("Invalid phone: $telefono");
        header("Location: principal.html?status=error&message=Teléfono%20inválido&mac=$mac&ip=$ip&ap=$ap");
        exit();
    }

    // 5️⃣ Insert client into database
    $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        log_error("Prepare insert client failed: " . $conn->error);
        die("DB error.");
    }
    $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $mac_clean);
    if ($stmt->execute()) {
        $client_id = $conn->insert_id;
        log_error("Client registered: ID=$client_id, MAC=$mac_clean");

        // 6️⃣ Insert MAC into radcheck
        $stmt2 = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
        if ($stmt2) {
            $stmt2->bind_param("ss", $mac_clean, $mac_clean);
            $stmt2->execute();
            $stmt2->close();
            log_error("MAC inserted into radcheck");
        } else {
            log_error("Prepare radcheck failed: " . $conn->error);
        }

        // 7️⃣ Optional: session timeout 24h
        $stmt3 = $conn->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Session-Timeout', ':=', '86400')");
        if ($stmt3) {
            $stmt3->bind_param("s", $mac_clean);
            $stmt3->execute();
            $stmt3->close();
        }

        // 8️⃣ Redirect to welcome page
        header("Location: bienvenido.html?status=success&message=Registro%20completado&mac=$mac&ip=$ip&ap=$ap");
        exit();

    } else {
        log_error("Execute insert client failed: " . $stmt->error);
        die("DB error.");
    }
    $stmt->close();

} else {
    // GET request: redirect to principal.html with detected variables
    $redirect_url = "principal.html?mac=" . urlencode($mac) . "&ip=" . urlencode($ip) . "&ap=" . urlencode($ap);
    log_error("Redirecting to: $redirect_url");
    header("Location: $redirect_url");
    exit();
}

mysqli_close($conn);
?>
