<?php
// register_client.php

// ----------------------------
// 🔧 Database Configuration
// ----------------------------
$host = "mysql_server";   // Change to 127.0.0.1 if not using Docker
$user = "radius";
$pass = "dalodbpass";
$db   = "radius";

// ----------------------------
// 🔌 Database Connection
// ----------------------------
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("<div class='error'>❌ Database connection failed: " . htmlspecialchars($conn->connect_error) . "</div>");
}

// ----------------------------
// 🧾 Get Parameters from URL
// ----------------------------
$mac = isset($_GET['mac']) ? htmlspecialchars($_GET['mac']) : '';
$ip = isset($_GET['ip']) ? htmlspecialchars($_GET['ip']) : '';
$url = isset($_GET['url']) ? htmlspecialchars($_GET['url']) : '';
$ap_mac = isset($_GET['ap_mac']) ? htmlspecialchars($_GET['ap_mac']) : '';
$essid = isset($_GET['essid']) ? htmlspecialchars($_GET['essid']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ----------------------------
    // 📥 Get Form Data
    // ----------------------------
    $nombre   = $_POST['nombre'];
    $apellido = $_POST['apellido'];
    $cedula   = $_POST['cedula'];
    $telefono = $_POST['telefono'];
    $email    = $_POST['email'];
    $mac      = $_POST['mac']; // Hidden field
    $ip       = $_POST['ip'];
    $url      = $_POST['url'];

    // ----------------------------
    // 🕵️ Check if MAC already registered
    // ----------------------------
    $check = $conn->prepare("SELECT id FROM clients WHERE mac = ?");
    if (!$check) {
        die("<div class='error'>Prepare failed (check): " . htmlspecialchars($conn->error) . "</div>");
    }
    $check->bind_param("s", $mac);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        echo "<script>alert('⚠️ This device is already registered.');</script>";
        // Redirect back to AP with success
        if (!empty($url)) {
            header("Location: " . urldecode($url));
            exit;
        }
    } else {
        // ----------------------------
        // 🧩 Insert into clients table
        // ----------------------------
        $query = "INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled)
                  VALUES (?, ?, ?, ?, ?, ?, 1)";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            die("<div class='error'>Prepare failed (clients): " . htmlspecialchars($conn->error) . "</div>");
        }
        $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac);

        if ($stmt->execute()) {
            // ----------------------------
            // ✅ Also insert into radcheck
            // ----------------------------
            $rad = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Accept')");
            if (!$rad) {
                die("<div class='error'>Prepare failed (radcheck): " . htmlspecialchars($conn->error) . "</div>");
            }
            $rad->bind_param("s", $mac);
            if ($rad->execute()) {
                // ----------------------------
                // 🔄 Redirect back to Aruba AP (RAUTH)
                // ----------------------------
                if (!empty($url)) {
                    
                    header("Location: " . urldecode($url));
                    exit;
                } else {
                    echo "<script>alert('✅ Device registered successfully! You can now connect.'); window.location='bienvenido.html';</script>";
                }
            } else {
                echo "<div class='error'>Error inserting into radcheck: " . htmlspecialchars($rad->error) . "</div>";
            }
        } else {
            echo "<div class='error'>Error inserting into clients: " . htmlspecialchars($stmt->error) . "</div>";
        }
    }
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

