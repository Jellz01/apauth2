<?php
$host = 'mysql_server';
$db = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    header("Location: principal.html?status=error&message=Error%20de%20conexi%C3%B3n%20a%20la%20base%20de%20datos.");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $cedula   = trim($_POST['cedula']);
    $telefono = trim($_POST['telefono']);
    $correo   = trim($_POST['correo']);

    // 1️⃣ Insert into clients
    $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, username, approved)
                            VALUES (?, ?, ?, ?, ?, ?, 0)");
    $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $correo, $cedula);

    if ($stmt->execute()) {
        // 2️⃣ Add RADIUS user (username = cedula, password = cedula)
        $stmt2 = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value)
                                 VALUES (?, 'Cleartext-Password', ':=', ?)");
        $stmt2->bind_param("ss", $cedula, $cedula);
        $stmt2->execute();

        header("Location: bienvenido.html?status=success&message=Registro%20enviado%20correctamente.%20Espere%20aprobaci%C3%B3n%20del%20administrador.");
    } else {
        header("Location: principal.html?status=error&message=Error%20al%20registrar%20el%20cliente.");
    }

    exit();
}
?>
