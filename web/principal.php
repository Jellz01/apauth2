<?php
// register_client.php

// ----------------------------
// 🔧 Database Configuration
// ----------------------------
$host = "mysql";   // Change to 127.0.0.1 if not using Docker
$user = "radius";
$pass = "radpass";
$db   = "radius";

// ----------------------------
// 🧰 Helpers
// ----------------------------
function normalize_mac($mac_raw) {
    // Remove everything that's not hex, then uppercase
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac_raw);
    return strtoupper($hex);
}

function safe_url($u) {
    // Allow only http/https (avoid header injection / javascript:)
    $u = trim((string)$u);
    if ($u === '') return '';
    $parts = parse_url($u);
    if (!$parts || !isset($parts['scheme'])) return '';
    $scheme = strtolower($parts['scheme']);
    return ($scheme === 'http' || $scheme === 'https') ? $u : '';
}

function redirect_or_welcome($url) {
    $url = safe_url($url);
    if (!headers_sent()) {
        header("Location: " . ($url !== '' ? $url : "bienvenido.html"));
    } else {
        echo '<script>location.href=' . json_encode($url !== '' ? $url : "bienvenido.html") . ';</script>';
    }
    exit;
}

// ----------------------------
// 🔌 Database Connection
// ----------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    die("<div class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// ----------------------------
// 🧾 Get Parameters from URL (RAUTH / captive portal)
// ----------------------------
$mac_raw  = $_GET['mac']    ?? '';
$ip_raw   = $_GET['ip']     ?? '';
$url_raw  = $_GET['url']    ?? '';
$ap_raw   = $_GET['ap_mac'] ?? '';
$essid    = $_GET['essid']  ?? '';

$mac_norm = normalize_mac($mac_raw);
$ap_norm  = normalize_mac($ap_raw);
$ip       = trim($ip_raw);
$url_in   = safe_url($url_raw);

// ----------------------------
// 📥 Process Form Submission
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read POST (user fields)
    $nombre   = $_POST['nombre']   ?? '';
    $apellido = $_POST['apellido'] ?? '';
    $cedula   = $_POST['cedula']   ?? '';
    $telefono = $_POST['telefono'] ?? '';
    $email    = $_POST['email']    ?? '';

    // Hidden fields (may come from form); normalize MAC again just in case
    $mac_post  = $_POST['mac'] ?? '';
    $ip_post   = $_POST['ip']  ?? '';
    $url_post  = $_POST['url'] ?? '';

    $mac_norm  = normalize_mac($mac_post);
    $ip        = trim($ip_post);
    $url_in    = safe_url($url_post);

    // If MAC is empty after normalization, bail gracefully
    if ($mac_norm === '') {
        die("<div class='error'>❌ MAC address missing or invalid.</div>");
    }

    try {
        // Use a transaction so clients + radcheck are consistent
        $conn->begin_transaction();

        // 1) Check if this device is already registered (using normalized MAC)
        $check = $conn->prepare("SELECT id FROM clients WHERE mac = ?");
        $check->bind_param("s", $mac_norm);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            // Already registered → just redirect right away
            $check->close();
            $conn->commit();
            redirect_or_welcome($url_in);
        }
        $check->close();

        // 2) Insert into clients (store normalized MAC)
        $stmt = $conn->prepare("
            INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac_norm);
        $stmt->execute();
        $stmt->close();

        // 3) Ensure radcheck has Auth-Type := Accept for this MAC (normalized)
        //    First see if there's already such a row to avoid duplicates
        $rad_sel = $conn->prepare("
            SELECT id FROM radcheck
            WHERE username = ? AND attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept'
            LIMIT 1
        ");
        $rad_sel->bind_param("s", $mac_norm);
        $rad_sel->execute();
        $rad_sel->store_result();

        if ($rad_sel->num_rows === 0) {
            $rad_sel->close();

            $rad_ins = $conn->prepare("
                INSERT INTO radcheck (username, attribute, op, value)
                VALUES (?, 'Auth-Type', ':=', 'Accept')
            ");
            $rad_ins->bind_param("s", $mac_norm);
            $rad_ins->execute();
            $rad_ins->close();
        } else {
            $rad_sel->close();
        }

        // All good 🎉
        $conn->commit();
        redirect_or_welcome($url_in);

    } catch (Exception $e) {
        // Rollback on any error and show a friendly message
        if ($conn->errno) {
            $conn->rollback();
        }
        die("<div class='error'>❌ Registration failed: " . htmlspecialchars($e->getMessage()) . "</div>");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Client Registration</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* { box-sizing: border-box; }
body {
  font-family: Arial, sans-serif; background: #f4f4f4; display: flex; flex-direction: column;
  align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 10px;
}
.top-image, .bottom-image { width: 100%; max-width: 400px; border-radius: 10px; }
.form-container {
  background: white; padding: 25px 20px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.15);
  width: 100%; max-width: 400px; margin: 15px 0;
}
h2 { color: #333; text-align: center; margin-bottom: 20px; font-size: 1.4rem; }
input {
  width: 100%; padding: 12px; margin: 8px 0; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem;
}
button {
  width: 100%; padding: 14px; background: #667eea; color: white; border: none; border-radius: 8px;
  font-size: 1.05rem; cursor: pointer; margin-top: 10px; transition: background 0.3s ease;
}
button:hover { background: #5568d3; }
.error {
  background: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin: 10px 0;
  text-align: center; font-size: 0.9rem; display: block;
}
.mac-display {
  background: #f9f9f9; padding: 10px; border-radius: 8px; margin-top: 12px; font-size: 0.9rem;
  color: #333; text-align: center; word-wrap: break-word;
}
.info-display {
  background: #e3f2fd; padding: 8px; border-radius: 6px; margin: 6px 0; font-size: 0.85rem;
  color: #1565c0; text-align: center;
}
@media (max-width: 480px) {
  .form-container { padding: 20px 15px; border-radius: 10px; }
  input, button { font-size: 1rem; }
  h2 { font-size: 1.3rem; }
}
</style>
</head>
<body>

    <!-- Top banner -->
    <img src="gonetlogo.png" alt="Top Banner" class="top-image">

    <div class="form-container">
        <h2>Register to Access Wi-Fi</h2>

        <form method="POST" autocomplete="on">
            <input type="text" name="nombre"   placeholder="First Name"     required>
            <input type="text" name="apellido" placeholder="Last Name"      required>
            <input type="text" name="cedula"   placeholder="ID / Cédula"    required>
            <input type="text" name="telefono" placeholder="Phone Number"   required>
            <input type="email" name="email"   placeholder="Email"          required>

            <!-- Hidden fields (normalized MAC; IP/URL passed through) -->
            <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
            <input type="hidden" name="ip"  value="<?php echo htmlspecialchars($ip); ?>">
            <input type="hidden" name="url" value="<?php echo htmlspecialchars($url_in); ?>">

            <!-- 👇 Visible info -->
            <?php if ($mac_norm !== ''): ?>
                <div class="mac-display">
                    <strong>Device MAC (normalized):</strong><br><?php echo htmlspecialchars($mac_norm); ?>
                </div>
            <?php endif; ?>

            <?php if ($ip !== ''): ?>
                <div class="info-display">
                    <strong>IP:</strong> <?php echo htmlspecialchars($ip); ?>
                </div>
            <?php endif; ?>

            <?php if ($essid !== ''): ?>
                <div class="info-display">
                    <strong>Network:</strong> <?php echo htmlspecialchars($essid); ?>
                </div>
            <?php endif; ?>

            <?php if ($ap_norm !== ''): ?>
                <div class="info-display">
                    <strong>AP MAC:</strong> <?php echo htmlspecialchars($ap_norm); ?>
                </div>
            <?php endif; ?>

            <button type="submit">Register</button>
        </form>
    </div>

    <!-- Bottom banner -->
    <img src="banner.png" alt="Bottom Banner" class="bottom-image">

</body>
</html>
