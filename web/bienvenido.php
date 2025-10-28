<?php
// bienvenido.php - CON LOADING AUTOMÁTICO
session_start();

// Configuración
$ap_ip = '192.168.0.9';
$coa_port = 4325;
$coa_secret = 'telecom';

$log_file = '/tmp/coa_debug_' . date('Y-m-d_H-i-s') . '.log';

function detailed_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $full_message = "[$timestamp] $message\n";
    file_put_contents($log_file, $full_message, FILE_APPEND);
    error_log($message);
}

detailed_log("=== INICIO BIENVENIDO.PHP ===");

$mac = isset($_SESSION['registration_mac']) ? trim($_SESSION['registration_mac']) : '';
$ip = isset($_SESSION['registration_ip']) ? trim($_SESSION['registration_ip']) : '';
$coa_sent = false;
$coa_message = '';

// ✅ NUEVO: Determinar si debemos redirigir automáticamente
$auto_redirect = false;
$redirect_url = 'success.php'; // Cambia por tu página de éxito

if (!empty($mac)) {
    detailed_log("Procesando MAC: $mac");
    
    $mac_cleaned = preg_replace('/[^A-Fa-f0-9:]/', '', $mac);
    
    // Crear atributos RADIUS
    $attributes = "User-Name = \"$mac_cleaned\"\nAcct-Session-Id = \"coa-reauth-" . time() . "\"";
    
    $tmpFile = tempnam(sys_get_temp_dir(), 'coa_');
    file_put_contents($tmpFile, $attributes);
    
    // Ejecutar CoA
    $command = sprintf(
        'cat %s | radclient -r 2 -t 3 -x %s:%d coa %s 2>&1',
        escapeshellarg($tmpFile),
        escapeshellarg($ap_ip),
        $coa_port,
        escapeshellarg($coa_secret)
    );
    
    exec($command, $output, $return_var);
    $coa_output = implode(" | ", $output);
    
    // ✅ NUEVA LÓGICA: Considerar éxito aunque no haya respuesta
    if ($return_var === 0) {
        if (strpos($coa_output, "Received Disconnect-ACK") !== false || 
            strpos($coa_output, "Received CoA-ACK") !== false) {
            $coa_sent = true;
            $coa_message = '✅ Autorización exitosa';
            $auto_redirect = true; // ✅ Redirigir automáticamente
        } else {
            // ✅ Aunque no haya respuesta, consideramos éxito y redirigimos
            $coa_sent = true;
            $coa_message = '✅ Procesando tu conexión...';
            $auto_redirect = true; // ✅ Redirigir automáticamente
        }
    } else {
        $coa_sent = false;
        $coa_message = '⚠️ Error en autorización';
    }
    
    unlink($tmpFile);
    $_SESSION['coa_executed'] = true;
    
} else {
    $coa_message = '⚠️ No se detectó dirección MAC';
}

detailed_log("Resultado: " . ($coa_sent ? 'ÉXITO' : 'FALLO') . " - Redirección: " . ($auto_redirect ? 'SÍ' : 'NO'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoNet WiFi - Conectando</title>
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
            padding: 40px 30px;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            max-width: 450px;
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
        
        .loading-container {
            margin: 25px 0;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            margin: 20px 0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4caf50, #8bc34a);
            border-radius: 3px;
            animation: progress 2s ease-in-out infinite;
        }
        
        @keyframes progress {
            0% { width: 0%; }
            50% { width: 70%; }
            100% { width: 100%; }
        }
        
        .countdown {
            font-size: 0.9rem;
            color: #666;
            margin-top: 15px;
        }
        
        .mac {
            font-size: 0.9rem;
            color: inherit;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            word-break: break-all;
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

        .debug-info {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            text-align: left;
            font-size: 0.8rem;
            font-family: monospace;
            border: 1px solid #ddd;
        }
    </style>
    
    <?php if ($auto_redirect): ?>
    <!-- ✅ REDIRECCIÓN AUTOMÁTICA después de 3 segundos -->
    <meta http-equiv="refresh" content="3;url=<?php echo $redirect_url; ?>">
    <script>
        // También redirigir con JavaScript por si acaso
        setTimeout(function() {
            window.location.href = '<?php echo $redirect_url; ?>';
        }, 3000);
        
        // Mostrar cuenta regresiva
        let countdown = 3;
        setInterval(function() {
            countdown--;
            const element = document.getElementById('countdown');
            if (element) {
                element.textContent = countdown;
            }
        }, 1000);
    </script>
    <?php endif; ?>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet Logo" class="logo">

    <div class="coa-status <?php echo $coa_sent ? 'success' : 'error'; ?>">
        <!-- ✅ SIEMPRE MOSTRAR LOADING -->
        <div class="loading-container">
            <div class="spinner"></div>
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
            <strong><?php echo htmlspecialchars($coa_message); ?></strong>
            
            <?php if ($auto_redirect): ?>
            <div class="countdown">
                Redirigiendo en <span id="countdown">3</span> segundos...
            </div>
            <?php else: ?>
            <div class="countdown">
                Por favor espera...
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($mac)): ?>
        <div class="mac">
            <strong>🔧 Dispositivo MAC:</strong>
            <code><?php echo htmlspecialchars($mac); ?></code>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($ip)): ?>
        <div class="mac">
            <strong>🌐 Dirección IP:</strong>
            <code><?php echo htmlspecialchars($ip); ?></code>
        </div>
        <?php endif; ?>
        
        <div class="debug-info">
            <strong>📋 Estado del Sistema:</strong><br>
            <?php if ($auto_redirect): ?>
            ✅ CoA procesado - Redirección automática activada<br>
            <?php else: ?>
            ⚠️ Esperando respuesta del sistema<br>
            <?php endif; ?>
            Protocolo: UDP CoA<br>
            AP: <?php echo htmlspecialchars($ap_ip); ?>
        </div>
    </div>

    <!-- ✅ Script para manejar casos sin redirección automática -->
    <script>
        // Si no hay redirección automática después de 5 segundos, ofrecer botón manual
        setTimeout(function() {
            if (!<?php echo $auto_redirect ? 'true' : 'false'; ?>) {
                const statusDiv = document.querySelector('.coa-status');
                const button = document.createElement('button');
                button.innerHTML = '🔄 Continuar Manualmente';
                button.style.cssText = `
                    background: #2196F3;
                    color: white;
                    border: none;
                    padding: 12px 24px;
                    border-radius: 8px;
                    font-size: 1rem;
                    cursor: pointer;
                    margin-top: 15px;
                `;
                button.onclick = function() {
                    window.location.href = '<?php echo $redirect_url; ?>';
                };
                statusDiv.appendChild(button);
            }
        }, 5000);
    </script>
</body>
</html>