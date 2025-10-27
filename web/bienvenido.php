<?php
// bienvenido.php - RECIBE MAC Y IP DE SESIÓN CON LOGGING DETALLADO

// Iniciar sesión ANTES de cualquier output
session_start();

// ----------------------------
// 🔧 Configuration for CoA
// ----------------------------
$ap_ip = '192.168.0.9';   // Aruba AP IP
$coa_port = 4325;         // CoA port (RFC 3576) - PUERTO CORRECTO
$coa_secret = 'telecom';  // Must match your clients.conf coa secret

// Crear archivo de log detallado
$log_file = '/tmp/coa_debug_' . date('Y-m-d_H-i-s') . '.log';
$php_error_log = ini_get('error_log');

function detailed_log($message) {
    global $log_file, $php_error_log;
    $timestamp = date('Y-m-d H:i:s');
    $full_message = "[$timestamp] $message\n";
    
    // Log a archivo de depuración
    file_put_contents($log_file, $full_message, FILE_APPEND);
    
    // Log a error_log de PHP
    error_log($full_message);
    
    // Log a stdout (Docker)
    echo "<!-- DEBUG: $message -->\n";
}

// ----------------------------
// 📥 Get MAC and IP from SESSION
// ----------------------------
$mac = isset($_SESSION['registration_mac']) ? trim($_SESSION['registration_mac']) : '';
$ip = isset($_SESSION['registration_ip']) ? trim($_SESSION['registration_ip']) : '';
$coa_sent = false;
$coa_message = '';

detailed_log("=== INICIO BIENVENIDO.PHP ===");
detailed_log("MAC de sesión: $mac");
detailed_log("IP de sesión: $ip");
detailed_log("AP IP: $ap_ip");
detailed_log("CoA Puerto: $coa_port");
detailed_log("CoA Secret: $coa_secret");

// ----------------------------
// 📡 Send CoA if MAC exists
// ----------------------------
// FORZAR NUEVO CoA (eliminar marca de ejecución previa para testing)
unset($_SESSION['coa_executed']);

if (!empty($mac)) {
    detailed_log("✓ MAC no vacía, procediendo con CoA...");
    
    $mac_cleaned = preg_replace('/[^A-Fa-f0-9:]/', '', $mac);
    detailed_log("✓ MAC limpiado: $mac_cleaned (original: $mac)");
    
    // Crear atributos RADIUS
    $attributes = "User-Name=$mac_cleaned\nAcct-Session-Id=coa-reauth-" . time();
    detailed_log("✓ Atributos RADIUS creados:\n$attributes");
    
    // Crear archivo temporal
    $tmpFile = tempnam(sys_get_temp_dir(), 'coa_');
    detailed_log("✓ Archivo temporal creado: $tmpFile");
    
    // Escribir atributos en archivo
    $bytes_written = file_put_contents($tmpFile, $attributes);
    detailed_log("✓ Bytes escritos en archivo temporal: $bytes_written");
    
    // Leer contenido para verificar
    $file_content = file_get_contents($tmpFile);
    detailed_log("✓ Contenido del archivo temporal:\n$file_content");
    
    // Verificar que radclient existe
    $radclient_check = shell_exec('which radclient 2>&1');
    detailed_log("✓ radclient ubicación: " . trim($radclient_check));
    
    // Verificar conectividad al AP
    detailed_log("✓ Verificando conectividad al AP $ap_ip:$coa_port...");
    $nc_check = shell_exec("nc -zv $ap_ip $coa_port 2>&1");
    detailed_log("✓ Resultado nc: " . trim($nc_check));
    
    // Construir comando CoA
    $command = sprintf(
        'cat %s | radclient -r 2 -t 3 -x %s:%d coa %s 2>&1',
        escapeshellarg($tmpFile),
        escapeshellarg($ap_ip),
        $coa_port,
        escapeshellarg($coa_secret)
    );
    
    detailed_log("✓ Comando CoA construido:");
    detailed_log("  $command");
    
    // Ejecutar comando CoA
    detailed_log("🔥 EJECUTANDO CoA...");
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    
    detailed_log("✓ Comando ejecutado con código de retorno: $return_var");
    detailed_log("✓ Output del comando (" . count($output) . " líneas):");
    foreach ($output as $idx => $line) {
        detailed_log("  [$idx] $line");
    }
    
    // Analizar respuesta
    $coa_output = implode(" | ", $output);
    detailed_log("✓ Output combinado: $coa_output");
    
    // Validar respuestas
    if ($return_var === 0) {
        detailed_log("✓ Código de retorno 0 (éxito)");
        
        if (strpos($coa_output, "Received Disconnect-ACK") !== false) {
            detailed_log("✓ Respuesta contiene: Received Disconnect-ACK");
            $coa_sent = true;
        } elseif (strpos($coa_output, "Received CoA-ACK") !== false) {
            detailed_log("✓ Respuesta contiene: Received CoA-ACK");
            $coa_sent = true;
        } else {
            detailed_log("⚠️ Código 0 pero respuesta inesperada");
            $coa_sent = true;
        }
    } else {
        detailed_log("❌ Código de retorno: $return_var (ERROR)");
        $coa_sent = false;
    }
    
    // Eliminar archivo temporal
    unlink($tmpFile);
    detailed_log("✓ Archivo temporal eliminado: $tmpFile");
    
    // Establecer mensaje
    $coa_message = $coa_sent
        ? '✅ CoA enviado exitosamente - Conectándote...'
        : '⚠️ Error enviando CoA: ' . htmlspecialchars($coa_output);
    
    detailed_log("✓ Estado final CoA: " . ($coa_sent ? 'ÉXITO' : 'FALLO'));
    detailed_log("✓ Mensaje: $coa_message");
    
    // Marcar CoA como ejecutado
    $_SESSION['coa_executed'] = true;
    detailed_log("✓ Sesión marcada como coa_executed");
    
} elseif (!empty($mac) && isset($_SESSION['coa_executed'])) {
    detailed_log("ℹ️ CoA ya fue ejecutado previamente");
    $coa_sent = true;
    $coa_message = '✅ Ya conectado - Disfrutando de GoNet Wi-Fi';
} else {
    detailed_log("❌ No hay MAC en sesión o está vacía");
    $coa_sent = false;
    $coa_message = '⚠️ No hay información de dispositivo';
}

detailed_log("=== FIN LÓGICA CoA ===");
detailed_log("Archivo de log: $log_file");
detailed_log("Error log PHP: $php_error_log");
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

        .debug-info {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            max-width: 500px;
            text-align: left;
            font-size: 0.8rem;
            font-family: monospace;
            border: 1px solid #ddd;
            max-height: 300px;
            overflow-y: auto;
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
            
            <div class="debug-info">
                <strong>📋 Información de Debug:</strong><br>
                Archivo de log: <?php echo htmlspecialchars($log_file); ?><br>
                Error log PHP: <?php echo htmlspecialchars($php_error_log); ?><br>
                Puerto CoA: <?php echo $coa_port; ?><br>
                AP: <?php echo htmlspecialchars($ap_ip); ?><br>
                Estado: <?php echo $coa_sent ? 'ENVIADO ✓' : 'FALLIDO ✗'; ?>
            </div>
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