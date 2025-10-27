<?php
// bienvenido.php - RECIBE MAC Y IP DE SESIÓN

// Iniciar sesión ANTES de cualquier output
session_start();

// ----------------------------
// 🔧 Configuration for CoA
// ----------------------------
$ap_ip = '192.168.0.9';   // Aruba AP IP
$coa_port = 4325;          // CoA port (RFC 3576)
$coa_secret = 'telecom';   // Must match your clients.conf coa secret

// ----------------------------
// 📥 Get MAC and IP from SESSION
// ----------------------------
$mac = isset($_SESSION['registration_mac']) ? trim($_SESSION['registration_mac']) : '';
$ip = isset($_SESSION['registration_ip']) ? trim($_SESSION['registration_ip']) : '';
$coa_sent = false;
$coa_message = '';

error_log("🎯 BIENVENIDO.PHP - MAC de sesión: $mac, IP: $ip");

// ----------------------------
// 📡 Send CoA if MAC exists
// ----------------------------
if (!empty($mac) && !isset($_SESSION['coa_executed'])) {
    error_log("🔥 ENVIANDO CoA PARA MAC: $mac");
    
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
    
    error_log("🖥️ COMANDO CoA: $command");
    
    $output = [];
    exec($command, $output, $return_var);
    unlink($tmpFile);

    $coa_sent = ($return_var === 0);
    $coa_message = $coa_sent
        ? '✅ CoA enviado exitosamente - Conectándote...'
        : '⚠️ Error enviando CoA: ' . implode("\n", $output);
    
    error_log("📋 OUTPUT CoA: $coa_message");
    
    // Marcar CoA como ejecutado
    $_SESSION['coa_executed'] = true;
} elseif (!empty($mac) && isset($_SESSION['coa_executed'])) {
    $coa_sent = true;
    $coa_message = '✅ Ya conectado - Disfrutando de GoNet Wi-Fi';
    error_log("ℹ️ CoA ya fue ejecutado previamente");
} else {
    error_log("⚠️ No hay MAC en sesión");
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
            font-family: 'Arial', sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            text-align: center;
            padding: 20px;
            color: #333;
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
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
            margin-bottom: 8px;
        }
        
        .mac code {
            background: rgba(0, 0, 0, 0.05);
            padding: 8px 12px;
            border-radius: 8px;
            display: inline-block;
            margin-top: 5px;
            font-family: monospace;
            font-size: 0.85rem;
        }
        
        .loading {
            display: inline-block;
            width: 12px;
            height: 12px;
            background: currentColor;
            border-radius: 50%;
            opacity: 0.7;
            margin: 0 3px;
            animation: pulse 1.4s infinite;
        }
        
        .loading:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .loading:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes pulse {
            0%, 60%, 100% {
                opacity: 0.7;
                transform: scale(0.8);
            }
            30% {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet Logo" class="logo">

    <?php if (!empty($mac)): ?>
        <div class="coa-status <?php echo $coa_sent ? 'success' : 'error'; ?>">
            <div>
                <?php echo htmlspecialchars($coa_message); ?>
                <?php if (!$coa_sent): ?>
                    <div style="margin-top: 10px;">
                        <span class="loading"></span><span class="loading"></span><span class="loading"></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mac">
                <strong>🔧 Dispositivo MAC:</strong>
                <code><?php echo htmlspecialchars($mac); ?></code>
            </div>
            <?php if (!empty($ip)): ?>
                <div class="mac">
                    <strong>🌐 Dirección IP:</strong>
                    <code><?php echo htmlspecialchars($ip); ?></code>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="coa-status warning">
            ⚠️ No se detectó ninguna dirección MAC.<br>
            <small style="font-size: 0.85rem; margin-top: 10px; display: block;">
                Intenta recargar la página o conéctate a la red Wi-Fi nuevamente.
            </small>
        </div>
    <?php endif; ?>

</body>
</html>