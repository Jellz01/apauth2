<?php
// bienvenido.php

// ----------------------------
// 🔧 Configuration for CoA
// ----------------------------
$ap_ip = '192.168.0.9';   // Aruba AP IP
$coa_port = 3799;          // CoA port (RFC 3576)
$coa_secret = 'telecom';   // Must match your clients.conf coa secret

// ----------------------------
// 📥 Get MAC from URL
// ----------------------------
$mac = isset($_GET['mac']) ? trim($_GET['mac']) : '';
$coa_sent = false;
$coa_message = '';

// ----------------------------
// 📡 Send CoA if MAC exists
// ----------------------------
if (!empty($mac)) {
    $mac = preg_replace('/[^A-Fa-f0-9:]/', '', $mac);

    $attributes = "User-Name=$mac\nAcct-Session-Id=coa-reauth-" . time();
    $tmpFile = tempnam(sys_get_temp_dir(), 'coa_');
    file_put_contents($tmpFile, $attributes);

    $command = sprintf(
        'cat %s | radclient -x %s:%d coa %s 2>&1',
        escapeshellarg($tmpFile),
        escapeshellarg($ap_ip),
        $coa_port,
        escapeshellarg($coa_secret)
    );
    exec($command, $output, $return_var);
    unlink($tmpFile);

    $coa_sent = ($return_var === 0);
    $coa_message = $coa_sent
        ? '✅ CoA enviado exitosamente'
        : '⚠️ Error enviando CoA: ' . implode("\n", $output);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>GoNet WiFi</title>
    <style>
        body {
            margin: 0;
            background-color: #ffffff;
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            text-align: center;
        }
        .logo {
            width: 250px;
            max-width: 80%;
            margin-bottom: 30px;
        }
        
        .mac {
            font-size: 0.9rem;
            color: #555;
            margin-top: 8px;
        }
    </style>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet Logo" class="logo">

    <?php if (!empty($mac)): ?>
        <div class="coa-status">
            <?= htmlspecialchars($coa_message) ?>
            <div class="mac"><strong>MAC:</strong> <?= htmlspecialchars($mac) ?></div>
        </div>
    <?php else: ?>
        <div class="coa-status" style="background:#fff3cd;color:#856404;">
            ⚠️ No se detectó ninguna dirección MAC.
        </div>
    <?php endif; ?>
</body>
</html>
