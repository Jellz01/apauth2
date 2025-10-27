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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoNet WiFi - Bienvenido</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            text-align: center;
            padding: 20px;
        }
        
        .logo {
            width: 250px;
            max-width: 80%;
            margin-bottom: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .coa-status {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            max-width: 400px;
            width: 100%;
            font-size: 1.1rem;
            color: #2c3e50;
        }
        
        .coa-status.success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 5px solid #4caf50;
        }
        
        .coa-status.error {
            background: #ffebee;
            color: #c62828;
            border-left: 5px solid #f44336;
        }
        
        .coa-status.warning {
            background: #fff3e0;
            color: #ef6c00;
            border-left: 5px solid #ff9800;
        }
        
        .mac {
            font-size: 0.9rem;
            color: inherit;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            word-break: break-all;
        }
        
        .mac strong {
            display: block;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet Logo" class="logo">

    <?php if (!empty($mac)): ?>
        <div class="coa-status <?php echo $coa_sent ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($coa_message); ?>
            <div class="mac">
                <strong>🔧 Dispositivo MAC:</strong>
                <?php echo htmlspecialchars($mac); ?>
            </div>
        </div>
    <?php else: ?>
        <div class="coa-status warning">
            ⚠️ No se detectó ninguna dirección MAC.
        </div>
    <?php endif; ?>
</body>
</html>