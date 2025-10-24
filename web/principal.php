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

// Function to clean MAC address (uppercase, no separators) - converts 88:aa:uu to 88AAUU
function cleanMacAddress($mac) {
    return strtoupper(str_replace([':', '-', '.', ' '], '', $mac));
}

// Function to format MAC address for display (with colons)
function formatMacForDisplay($mac) {
    $clean_mac = cleanMacAddress($mac);
    return implode(':', str_split($clean_mac, 2));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $cedula   = trim($_POST['cedula'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo   = trim($_POST['correo'] ?? '');
    $mac_from_form = trim($_POST['mac'] ?? '');

    // Clean MAC - converts 88:aa:uu to 88AAUU (uppercase, no separators)
    $mac_clean = cleanMacAddress($mac_from_form);
    
    // Format for display - converts 88:aa:uu to 88:AA:UU
    $mac_display = formatMacForDisplay($mac_from_form);

    // Debug logging
    error_log("MAC Address Processing:");
    error_log("Raw MAC from form: " . $mac_from_form);
    error_log("Cleaned MAC (for DB): " . $mac_clean);
    error_log("Display MAC: " . $mac_display);

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

    // Start transaction for both inserts
    mysqli_begin_transaction($conn);

    try {
        // Check if MAC already exists in clients table
        $check_stmt = $conn->prepare("SELECT id FROM clients WHERE mac = ?");
        $check_stmt->bind_param("s", $mac_clean);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            throw new Exception("Esta dirección MAC ya está registrada en el sistema");
        }
        $check_stmt->close();

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

        // Insert into radcheck table for MAC authentication - store in multiple formats
        $mac_formats = [
            $mac_clean, // Uppercase, no separators: 88AAUU
            strtolower($mac_clean), // Lowercase, no separators: 88aauu
        ];

        $insert_count = 0;
        foreach ($mac_formats as $mac_format) {
            $stmt2 = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Accept')");
            if (!$stmt2) {
                throw new Exception("DB prepare error (radcheck): " . $conn->error);
            }
            $stmt2->bind_param("s", $mac_format);
            
            if ($stmt2->execute()) {
                $insert_count++;
            } else {
                // If duplicate entry, ignore and continue (it's okay if one format already exists)
                if (strpos($stmt2->error, 'Duplicate entry') === false) {
                    throw new Exception("DB execute error (radcheck): " . $stmt2->error);
                }
            }
            $stmt2->close();
        }

        // Commit both inserts
        mysqli_commit($conn);
        
        // Success - redirect with success message
        header("Location: bienvenido.html?status=success&message=Registro%20completado&mac=" . urlencode($mac_display));
        exit();

    } catch (Exception $e) {
        // Rollback on any error
        mysqli_rollback($conn);
        
        // Check if it's a duplicate MAC error
        if (strpos($e->getMessage(), 'ya está registrada') !== false) {
            header("Location: principal.html?status=error&message=Esta%20dirección%20MAC%20ya%20está%20registrada");
        } else {
            header("Location: principal.html?status=error&message=Error%20en%20el%20registro");
        }
        exit();
    }

} else {
    // If not POST, redirect to form
    header("Location: principal.html");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Client Registration</title>

<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 10px; }
.top-image, .bottom-image { width: 100%; max-width: 400px; border-radius: 10px; }
.form-container { background: white; padding: 25px 20px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); width: 100%; max-width: 400px; margin: 15px 0; }
h2 { color: #333; text-align: center; margin-bottom: 20px; font-size: 1.4rem; }
input { width: 100%; padding: 12px; margin: 8px 0; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; }
button { width: 100%; padding: 14px; background: #667eea; color: white; border: none; border-radius: 8px; font-size: 1.05rem; cursor: pointer; margin-top: 10px; transition: background 0.3s ease; }
button:hover { background: #5568d3; }
.error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin: 10px 0; text-align: center; font-size: 0.9rem; display: block; }
.mac-display { background: #f9f9f9; padding: 10px; border-radius: 8px; margin-top: 12px; font-size: 0.9rem; color: #333; text-align: center; word-wrap: break-word; }
.info-display { background: #e3f2fd; padding: 8px; border-radius: 6px; margin: 6px 0; font-size: 0.85rem; color: #1565c0; text-align: center; }
@media (max-width: 480px) { .form-container { padding: 20px 15px; border-radius: 10px; } input, button { font-size: 1rem; } h2 { font-size: 1.3rem; } }
</style>
</head>
<body>

    <!-- Top banner -->
    <img src="gonetlogo.png" alt="Top Banner" class="top-image">

    <div class="form-container">
        <h2>Register to Access Wi-Fi</h2>
        <form method="POST">
            <input type="text" name="nombre" placeholder="First Name" required>
            <input type="text" name="apellido" placeholder="Last Name" required>
            <input type="text" name="cedula" placeholder="ID / Cedula" required>
            <input type="text" name="telefono" placeholder="Phone Number" required>
            <input type="email" name="email" placeholder="Email" required>
            
            <!-- Hidden fields for RAUTH -->
            <input type="hidden" name="mac" value="<?php echo $mac; ?>">
            <input type="hidden" name="ip" value="<?php echo $ip; ?>">
            <input type="hidden" name="url" value="<?php echo $url; ?>">

            <!-- 👇 MAC shown above the register button -->
            <?php if (!empty($mac)): ?>
                <div class="mac-display">
                    <strong>Device MAC:</strong><br><?php echo $mac; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($ip)): ?>
                <div class="info-display">
                    <strong>IP:</strong> <?php echo $ip; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($essid)): ?>
                <div class="info-display">
                    <strong>Network:</strong> <?php echo $essid; ?>
                </div>
            <?php endif; ?>

            <button type="submit">Register</button>
        </form>
    </div>

    <!-- Bottom banner -->
    <img src="banner.png" alt="Bottom Banner" class="bottom-image">

</body>
</html>