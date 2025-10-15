<?php
$host = 'mysql_server';
$db   = 'radius';
$user = 'radius';
$pass = 'dalodbpass';

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    die("Error de conexión a la base de datos.");
}

// Process form submission
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
    
    // Check if already registered by cedula or email
    $stmtCheck = $conn->prepare("SELECT * FROM clients WHERE cedula = ? OR email = ?");
    $stmtCheck->bind_param("ss", $cedula, $correo);
    $stmtCheck->execute();
    $result = $stmtCheck->get_result();
    
    if ($result->num_rows > 0) {
        header("Location: bienvenido.html?status=success&message=Ya%20está%20registrado.");
        exit();
    }
    
    // Insert client record (without MAC and approved column)
    $stmt = $conn->prepare("INSERT INTO clients (nombre, apellido, cedula, telefono, email)
                            VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $nombre, $apellido, $cedula, $telefono, $correo);
    
    if ($stmt->execute()) {
        $client_id = $conn->insert_id;
        
        // Insert RADIUS entry using cedula as username
        $stmt2 = $conn->prepare("INSERT INTO radcheck (client_id, username, attribute, op, value)
                                 VALUES (?, ?, 'Cleartext-Password', ':=', ?)");
        $password = $cedula; // or generate a password
        $stmt2->bind_param("iss", $client_id, $cedula, $password);
        $stmt2->execute();
        
        header("Location: bienvenido.html?status=success&message=Registro%20completado.%20Usuario:%20$cedula");
        exit();
    } else {
        header("Location: principal.html?status=error&message=Error%20al%20registrar%20el%20cliente.");
        exit();
    }
} else {
    // GET request - redirect to form
    header("Location: principal.html");
    exit();
}
?>