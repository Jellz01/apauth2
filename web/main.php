<?php

$host = 'mysql_server';
$db = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

$conn = mysqli_connect($host, $user, $pass, $db);

if($conn) {
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nombre = $_POST['nombre'];
        $apellido = $_POST['apellido'];
        $cedula = $_POST['cedula'];
        $telefono = $_POST['telefono'];
        $correo = $_POST['correo'];

        // CHECK IF CEDULA ALREADY EXISTS
        $check = $conn->prepare("SELECT cedula FROM clients WHERE cedula = ?");
        $check->execute([$cedula]);
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            header("Location: principal.html?status=error&message=El%20cliente%20con%20c%C3%A9dula%20" . urlencode($cedula) . "%20ya%20está%20registrado.");
            exit();
        } else {
            // Insert into clients table, approved=0 by default
            $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email, username, approved) VALUES (?, ?, ?, ?, ?, ?, 0)");
            
            if ($stmt->execute([$nombre, $apellido, $cedula, $telefono, $correo, $cedula])) {
                // Insert RADIUS password
                $stmt2 = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
                $stmt2->execute([$cedula, $cedula]);
                
                header("Location: principal.html?status=success&message=Registro%20enviado%20correctamente.%20Espere%20aprobaci%C3%B3n%20del%20administrador.");
                exit();
            } else {
                header("Location: principal.html?status=error&message=Error%20al%20registrar%20el%20cliente.");
                exit();
            }
        }
    }
} else {
    header("Location: principal.html?status=error&message=Error%20de%20conexión%20a%20la%20base%20de%20datos.");
    exit();
}

?>