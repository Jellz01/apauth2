<?php

$host = 'mysql';
$db   = 'radius';
$user = 'radius';
$pass = 'radpass';

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
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

    if (!$nombre || !$apellido || !$cedula || !$telefono || !$correo) {
        header("Location: principal.html?status=error&message=Todos%20los%20campos%20son%20obligatorios");
        exit();
    }

    if (!validarCedulaEcuatoriana($cedula)) {
        header("Location: principal.html?status=error&message=Cédula%20inválida");
        exit();
    }

    if (!preg_match('/^09\d{8}$/', $telefono)) {
        header("Location: principal.html?status=error&message=Teléfono%20inválido");
        exit();
    }

    // Clean MAC - UPPERCASE and remove all separators (exactly like in logs)
    $mac_clean = strtoupper(str_replace([':', '-', '.', ' '], '', $mac_from_form));

    // Start transaction for both inserts
    mysqli_begin_transaction($conn);

    try {
        // Insert into clients table
        $stmt1 = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt1) {
            throw new Exception("DB prepare error (clients): " . $conn->error);
        }
        $stmt1->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $mac_clean);
        
        if (!$stmt1->execute()) {
            throw new Exception("DB execute error (clients): " . $stmt1->error);
        }
        $stmt1->close();

        // Insert into radcheck table for MAC authentication - UPPERCASE, NO SEPARATORS
        $stmt2 = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Accept')");
        if (!$stmt2) {
            throw new Exception("DB prepare error (radcheck): " . $conn->error);
        }
        $stmt2->bind_param("s", $mac_clean);
        
        if (!$stmt2->execute()) {
            throw new Exception("DB execute error (radcheck): " . $stmt2->error);
        }
        $stmt2->close();

        // Commit both inserts
        mysqli_commit($conn);
        
        header("Location: bienvenido.html?status=success&message=Registro%20completado");
        exit();

    } catch (Exception $e) {
        // Rollback on any error
        mysqli_rollback($conn);
        die("Database error: " . $e->getMessage());
    }

} else {
    header("Location: principal.html");
    exit();
}
?>