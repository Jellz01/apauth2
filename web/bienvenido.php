<?php
// bienvenido.php - VERSIÓN CORREGIDA COA
session_start();

// ✅ CONFIGURACIÓN CoA - Enviar directamente al AP
$ap_ip = '192.168.0.9';           // IP del AP Aruba
$coa_port = 3799;                 // Puerto estándar CoA/DM para Aruba
$coa_secret = 'telecom';          // Secret compartido con el AP

$log_file = '/tmp/coa_debug.log';

function detailed_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    error_log($message);
}

$mac = isset($_SESSION['registration_mac']) ? trim($_SESSION['registration_mac']) : '';
$ip = isset($_SESSION['registration_ip']) ? trim($_SESSION['registration_ip']) : '';

detailed_log("=== INICIO CoA REQUEST ===");
detailed_log("AP Destino: $ap_ip:$coa_port");
detailed_log("MAC: $mac | IP: $ip");

// ✅ Verificar conectividad al AP
$connectivity = @fsockopen($ap_ip, $coa_port, $errno, $errstr, 2);
if ($connectivity) {
    fclose($connectivity);
    detailed_log("✅ AP alcanzable en $ap_ip:$coa_port");
} else {
    detailed_log("❌ AP NO alcanzable: $errstr ($errno)");
}

// Verificar radclient
$radclient_path = trim(shell_exec('which radclient 2>&1'));
detailed_log("radclient: $radclient_path");

if (!empty($mac)) {
    // Normalizar MAC address
    $mac_cleaned = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $mac));
    if (strlen($mac_cleaned) == 12) {
        $mac_cleaned = implode('-', str_split($mac_cleaned, 2)); // Aruba prefiere AA-BB-CC-DD-EE-FF
    }
    
    detailed_log("MAC normalizado: $mac_cleaned");
    
    // ✅ ATRIBUTOS CoA/DM según RFC 5176 y Aruba
    $attributes = [
        "Acct-Session-Id = \"gonet-" . time() . "-" . substr(md5($mac_cleaned), 0, 8) . "\"",
        "Calling-Station-Id = \"$mac_cleaned\"",
        "NAS-IP-Address = $ap_ip",
    ];
    
    // Agregar User-Name si disponible
    if (!empty($_SESSION['registration_name'])) {
        $username = $_SESSION['registration_name'];
        $attributes[] = "User-Name = \"$username\"";
        detailed_log("Username: $username");
    }
    
    // Crear archivo temporal
    $tmpFile = tempnam(sys_get_temp_dir(), 'coa_');
    file_put_contents($tmpFile, implode("\n", $attributes) . "\n");
    
    detailed_log("Archivo temporal: $tmpFile");
    detailed_log("Contenido:\n" . file_get_contents($tmpFile));
    
    // ✅ COMANDO CoA - Disconnect-Request al AP
    $command = sprintf(
        'radclient -r 3 -t 3 -x %s:%d disconnect %s < %s 2>&1',
        escapeshellarg($ap_ip),
        $coa_port,
        escapeshellarg($coa_secret),
        escapeshellarg($tmpFile)
    );
    
    detailed_log("Ejecutando: $command");
    
    // Ejecutar comando
    exec($command, $output, $return_var);
    $result = implode("\n", $output);
    
    detailed_log("Return code: $return_var");
    detailed_log("Output:\n$result");
    
    // Analizar respuesta
    $coa_sent = false;
    if ($return_var === 0) {
        if (stripos($result, "Disconnect-ACK") !== false) {
            detailed_log("✅ SUCCESS: Disconnect-ACK recibido");
            $coa_message = "✅ Autorización exitosa";
            $coa_status = "success";
            $coa_sent = true;
        } elseif (stripos($result, "CoA-ACK") !== false) {
            detailed_log("✅ SUCCESS: CoA-ACK recibido");
            $coa_message = "✅ Autorización exitosa";
            $coa_status = "success";
            $coa_sent = true;
        } else {
            detailed_log("⚠️ Respuesta inesperada (código 0)");
            $coa_message = "⚠️ Procesando autorización...";
            $coa_status = "warning";
        }
    } else {
        // Analizar errores comunes
        if (stripos($result, "no response") !== false || stripos($result, "timed out") !== false) {
            detailed_log("❌ ERROR: Timeout - AP no responde");
            $coa_message = "⚠️ El AP no responde al CoA";
            $coa_status = "error";
        } elseif (stripos($result, "Disconnect-NAK") !== false) {
            detailed_log("❌ ERROR: Disconnect-NAK - Sesión no encontrada");
            $coa_message = "⚠️ Sesión no encontrada en el AP";
            $coa_status = "warning";
        } else {
            detailed_log("❌ ERROR: Código $return_var");
            $coa_message = "⚠️ Error en comunicación con AP";
            $coa_status = "error";
        }
    }
    
    unlink($tmpFile);
    $_SESSION['coa_executed'] = true;
    $_SESSION['coa_status'] = $coa_status;
    
} else {
    detailed_log("❌ MAC address no disponible");
    $coa_message = "⚠️ No se detectó dirección MAC";
    $coa_status = "error";
    $coa_sent = false;
}

$redirect_url = 'success.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoNet WiFi - Autorizando</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Arial', sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .logo { 
            width: 200px; 
            margin-bottom: 30px; 
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .spinner {
            width: 60px;
            height: 60px;
            border: 6px solid #e3f2fd;
            border-top: 6px solid #2196f3;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 25px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .status {
            font-size: 1.3rem;
            margin: 20px 0;
            font-weight: 600;
        }
        .status.success { color: #4caf50; }
        .status.warning { color: #ff9800; }
        .status.error { color: #f44336; }
        
        .countdown {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            font-size: 1.1rem;
            color: #666;
        }
        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            text-align: left;
            font-size: 0.9rem;
            color: #555;
        }
        .info-box strong { color: #333; }
        .debug-link {
            margin-top: 15px;
            font-size: 0.85rem;
        }
        .debug-link a {
            color: #2196f3;
            text-decoration: none;
        }
    </style>
    
    <meta http-equiv="refresh" content="5;url=<?php echo $redirect_url; ?>">
    <script>
        let seconds = 5;
        const countdownEl = document.getElementById('countdown');
        
        const timer = setInterval(() => {
            seconds--;
            if (countdownEl) {
                countdownEl.textContent = seconds;
            }
            if (seconds <= 0) {
                clearInterval(timer);
            }
        }, 1000);
        
        // Intentar abrir Internet después de 2 segundos
        setTimeout(() => {
            window.open('http://www.google.com', '_blank');
        }, 2000);
    </script>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet WiFi" class="logo">

    <div class="container">
        <div class="spinner"></div>
        
        <div class="status <?php echo $coa_status ?? 'warning'; ?>">
            <?php echo htmlspecialchars($coa_message); ?>
        </div>
        
        <div class="countdown">
            Redirigiendo en <span id="countdown">5</span> segundos...
        </div>
        
        <?php if (!empty($mac)): ?>
        <div class="info-box">
            <strong>📱 Dispositivo registrado:</strong><br>
            <?php echo htmlspecialchars($mac_cleaned ?? $mac); ?>
            <?php if (!empty($ip)): ?>
            <br><strong>🌐 IP:</strong> <?php echo htmlspecialchars($ip); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="debug-link">
            <a href="javascript:void(0)" onclick="alert('Log: <?php echo $log_file; ?>')">
                🔍 Ver logs de depuración
            </a>
        </div>
    </div>
</body>
</html>