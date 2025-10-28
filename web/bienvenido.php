<?php
// bienvenido.php - CoA con datos de sesión correctos
session_start();

// CONFIGURACIÓN
$ap_ip = '192.168.0.9';
$coa_port = 3799;
$coa_secret = 'telecom';
$radius_db = '/etc/freeradius/3.0/mods-config/sql/main/mysql/radacct'; // Ajustar según tu DB

$log_file = '/tmp/coa_debug.log';

function detailed_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    error_log($message);
}

$mac = isset($_SESSION['registration_mac']) ? trim($_SESSION['registration_mac']) : '';
$ip = isset($_SESSION['registration_ip']) ? trim($_SESSION['registration_ip']) : '';

detailed_log("=== INICIO CoA con búsqueda de sesión ===");

if (!empty($mac)) {
    // Normalizar MAC (probar múltiples formatos)
    $mac_clean = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $mac));
    $mac_formats = [
        implode('-', str_split($mac_clean, 2)),  // AA-BB-CC-DD-EE-FF
        implode(':', str_split($mac_clean, 2)),  // AA:BB:CC:DD:EE:FF
        strtolower(implode(':', str_split($mac_clean, 2))), // aa:bb:cc:dd:ee:ff
        $mac_clean,                               // AABBCCDDEEFF
        strtolower($mac_clean)                    // aabbccddeeff
    ];
    
    detailed_log("Formatos MAC a probar: " . implode(', ', $mac_formats));
    
    // ✅ OPCIÓN 1: Buscar sesión en radacct (si tienes acceso a MySQL)
    $session_data = null;
    
    // Intentar conectar a MySQL de FreeRADIUS
    try {
        $db_host = '172.18.0.2'; // IP contenedor FreeRADIUS
        $db_user = 'radius';
        $db_pass = 'radpass';
        $db_name = 'radius';
        
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        
        if ($conn->connect_error) {
            detailed_log("⚠️ No se pudo conectar a MySQL: " . $conn->connect_error);
        } else {
            detailed_log("✅ Conectado a MySQL radius");
            
            // Buscar sesión activa del usuario
            $mac_escaped = $conn->real_escape_string($mac_formats[0]);
            $query = "SELECT acctsessionid, callingstationid, nasipaddress, username, framedipaddress 
                     FROM radacct 
                     WHERE callingstationid IN ('" . implode("','", array_map([$conn, 'real_escape_string'], $mac_formats)) . "')
                     AND acctstoptime IS NULL 
                     ORDER BY acctstarttime DESC 
                     LIMIT 1";
            
            detailed_log("Query: $query");
            $result = $conn->query($query);
            
            if ($result && $result->num_rows > 0) {
                $session_data = $result->fetch_assoc();
                detailed_log("✅ Sesión encontrada en radacct:");
                detailed_log("  - Session ID: " . $session_data['acctsessionid']);
                detailed_log("  - MAC: " . $session_data['callingstationid']);
                detailed_log("  - Username: " . $session_data['username']);
                detailed_log("  - NAS IP: " . $session_data['nasipaddress']);
                detailed_log("  - Framed IP: " . $session_data['framedipaddress']);
            } else {
                detailed_log("⚠️ No se encontró sesión activa en radacct");
            }
            $conn->close();
        }
    } catch (Exception $e) {
        detailed_log("❌ Error MySQL: " . $e->getMessage());
    }
    
    // ✅ OPCIÓN 2: Si no hay sesión en DB, usar radclient con múltiples atributos
    if (!$session_data) {
        detailed_log("⚠️ Usando atributos genéricos (puede fallar)");
        
        // Crear archivo con TODOS los atributos posibles que el AP podría usar
        $attributes = [];
        
        // Probar con cada formato de MAC
        foreach ($mac_formats as $idx => $mac_format) {
            detailed_log("Intento #" . ($idx + 1) . " con MAC: $mac_format");
            
            $tmpFile = tempnam(sys_get_temp_dir(), 'coa_');
            $attr_content = "Calling-Station-Id = \"$mac_format\"\n";
            
            // Agregar IP si está disponible
            if (!empty($ip)) {
                $attr_content .= "Framed-IP-Address = $ip\n";
            }
            
            // Agregar NAS-IP-Address
            $attr_content .= "NAS-IP-Address = $ap_ip\n";
            
            file_put_contents($tmpFile, $attr_content);
            detailed_log("Contenido:\n" . $attr_content);
            
            // Ejecutar radclient
            $command = sprintf(
                'radclient -r 2 -t 3 -x %s:%d disconnect %s < %s 2>&1',
                escapeshellarg($ap_ip),
                $coa_port,
                escapeshellarg($coa_secret),
                escapeshellarg($tmpFile)
            );
            
            exec($command, $output, $return_var);
            $result = implode("\n", $output);
            
            detailed_log("Return code: $return_var");
            detailed_log("Output: $result");
            
            // Si recibimos ACK, salir del loop
            if (stripos($result, "Disconnect-ACK") !== false) {
                detailed_log("✅ SUCCESS con formato: $mac_format");
                $coa_message = "✅ Autorización exitosa";
                $coa_status = "success";
                $coa_sent = true;
                unlink($tmpFile);
                break;
            }
            
            unlink($tmpFile);
            $output = []; // Limpiar para siguiente intento
        }
        
        if (!isset($coa_sent)) {
            detailed_log("❌ Ningún formato de MAC funcionó");
            $coa_message = "⚠️ Sesión no encontrada - Reintente conexión WiFi";
            $coa_status = "error";
            $coa_sent = false;
        }
        
    } else {
        // ✅ USAR DATOS REALES DE LA SESIÓN
        $tmpFile = tempnam(sys_get_temp_dir(), 'coa_');
        $attr_content = "Acct-Session-Id = \"" . $session_data['acctsessionid'] . "\"\n";
        $attr_content .= "Calling-Station-Id = \"" . $session_data['callingstationid'] . "\"\n";
        $attr_content .= "NAS-IP-Address = " . $session_data['nasipaddress'] . "\n";
        
        if (!empty($session_data['username'])) {
            $attr_content .= "User-Name = \"" . $session_data['username'] . "\"\n";
        }
        
        if (!empty($session_data['framedipaddress'])) {
            $attr_content .= "Framed-IP-Address = " . $session_data['framedipaddress'] . "\n";
        }
        
        file_put_contents($tmpFile, $attr_content);
        detailed_log("✅ Usando datos reales de sesión:\n" . $attr_content);
        
        $command = sprintf(
            'radclient -r 3 -t 5 -x %s:%d disconnect %s < %s 2>&1',
            escapeshellarg($ap_ip),
            $coa_port,
            escapeshellarg($coa_secret),
            escapeshellarg($tmpFile)
        );
        
        exec($command, $output, $return_var);
        $result = implode("\n", $output);
        
        detailed_log("Return code: $return_var");
        detailed_log("Output:\n$result");
        
        if ($return_var === 0 && stripos($result, "Disconnect-ACK") !== false) {
            detailed_log("✅ SUCCESS con datos de sesión");
            $coa_message = "✅ Autorización exitosa";
            $coa_status = "success";
            $coa_sent = true;
        } else {
            detailed_log("❌ Falló incluso con datos correctos");
            $coa_message = "⚠️ Error en desconexión - Verifique AP";
            $coa_status = "error";
            $coa_sent = false;
        }
        
        unlink($tmpFile);
    }
    
    $_SESSION['coa_executed'] = true;
    $_SESSION['coa_status'] = $coa_status ?? 'error';
    
} else {
    detailed_log("❌ MAC no disponible");
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
    <title>GoNet WiFi - Autorizando Acceso</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
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
            background: #e3f2fd;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            text-align: left;
            font-size: 0.9rem;
            color: #1565c0;
            border-left: 4px solid #2196f3;
        }
        .error-box {
            background: #ffebee;
            border-left-color: #f44336;
            color: #c62828;
        }
        .info-box strong { display: block; margin-bottom: 8px; }
        .manual-steps {
            text-align: left;
            margin-top: 10px;
            line-height: 1.6;
        }
        .manual-steps li { margin: 5px 0; }
    </style>
    
    <meta http-equiv="refresh" content="6;url=<?php echo $redirect_url; ?>">
    <script>
        let seconds = 6;
        const countdownEl = document.getElementById('countdown');
        
        const timer = setInterval(() => {
            seconds--;
            if (countdownEl) countdownEl.textContent = seconds;
            if (seconds <= 0) clearInterval(timer);
        }, 1000);
        
        <?php if ($coa_status === 'success'): ?>
        setTimeout(() => {
            window.open('http://www.google.com', '_blank');
        }, 2000);
        <?php endif; ?>
    </script>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet WiFi" class="logo">

    <div class="container">
        <?php if ($coa_status !== 'success'): ?>
            <div style="font-size: 3rem; margin-bottom: 15px;">⚠️</div>
        <?php else: ?>
            <div class="spinner"></div>
        <?php endif; ?>
        
        <div class="status <?php echo $coa_status ?? 'error'; ?>">
            <?php echo htmlspecialchars($coa_message); ?>
        </div>
        
        <?php if ($coa_status === 'error'): ?>
        <div class="info-box error-box">
            <strong>⚠️ Sesión no encontrada en el Access Point</strong>
            <p>Para continuar navegando:</p>
            <ol class="manual-steps">
                <li>Desconéctese del WiFi</li>
                <li>Vuelva a conectarse</li>
                <li>Complete el registro nuevamente</li>
            </ol>
            <p style="margin-top: 10px; font-size: 0.85rem;">
                <em>Nota: El portal detectará su sesión automáticamente</em>
            </p>
        </div>
        <?php else: ?>
        <div class="countdown">
            Redirigiendo en <span id="countdown">6</span> segundos...
        </div>
        <?php endif; ?>
        
        <?php if (!empty($mac) && isset($session_data)): ?>
        <div class="info-box">
            <strong>📱 Sesión detectada:</strong>
            MAC: <?php echo htmlspecialchars($session_data['callingstationid']); ?><br>
            Session ID: <?php echo htmlspecialchars(substr($session_data['acctsessionid'], 0, 16)); ?>...
        </div>
        <?php elseif (!empty($mac)): ?>
        <div class="info-box">
            <strong>📱 Dispositivo:</strong>
            <?php echo htmlspecialchars($mac); ?>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 20px; font-size: 0.85rem; color: #999;">
            Logs: <code><?php echo $log_file; ?></code>
        </div>
    </div>
</body>
</html>