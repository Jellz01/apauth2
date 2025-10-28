<?php
// bienvenido.php - VERSIÓN CORREGIDA CON IPs DOCKER
session_start();

// ✅ CONFIGURACIÓN CORREGIDA - Usar IP del contenedor FreeRADIUS
$ap_ip = '192.168.0.9';           // AP Aruba (externa)
$radius_container_ip = '172.18.0.2'; // ✅ IP REAL del contenedor freeradius
$coa_port = 4325;
$coa_secret = 'telecom';

$log_file = '/tmp/coa_fixed_docker.log';

function detailed_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $full_message = "[$timestamp] $message\n";
    file_put_contents($log_file, $full_message, FILE_APPEND);
    error_log("DOCKER-FIXED: " . $message);
}

$mac = isset($_SESSION['registration_mac']) ? trim($_SESSION['registration_mac']) : '';
$ip = isset($_SESSION['registration_ip']) ? trim($_SESSION['registration_ip']) : '';

detailed_log("=== DOCKER FIXED CoA ===");
detailed_log("AP Aruba: $ap_ip");
detailed_log("FreeRADIUS Container: $radius_container_ip");
detailed_log("MAC: $mac");
detailed_log("Client IP: $ip");

// ✅ Verificar que radclient está disponible
$radclient_check = shell_exec('which radclient 2>&1');
detailed_log("radclient: " . trim($radclient_check));

if (!empty($mac)) {
    // Limpiar MAC
    $mac_cleaned = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $mac));
    if (strlen($mac_cleaned) == 12) {
        $mac_cleaned = implode(':', str_split($mac_cleaned, 2));
    }
    
    detailed_log("MAC procesado: $mac_cleaned");
    
    // ✅ ATRIBUTOS CORRECTOS para el AP Aruba
    $attributes = [
        "User-Name = \"$mac_cleaned\"",
        "Acct-Session-Id = \"docker-fixed-" . time() . "\"",
        "Calling-Station-Id = \"$mac_cleaned\"",
        "NAS-IP-Address = \"$ap_ip\"",           // IP del AP
        "NAS-Identifier = \"my_ap\"",           // Identificador del AP
        "Service-Type = Framed-User",           // Service-Type configurado
        "Filter-Id = \"default\""               // Política de filtro
    ];
    
    $tmpFile = tempnam(sys_get_temp_dir(), 'coa_fixed_');
    file_put_contents($tmpFile, implode("\n", $attributes));
    
    detailed_log("Archivo temporal creado: $tmpFile");
    detailed_log("Contenido: " . file_get_contents($tmpFile));
    
    // ✅ COMANDO CORREGIDO: Enviar al CONTENEDOR FreeRADIUS (172.18.0.2)
    $command = sprintf(
        'cat %s | radclient -r 3 -t 5 -x %s:%d disconnect %s 2>&1',
        escapeshellarg($tmpFile),
        escapeshellarg($radius_container_ip),  // ✅ IP DEL CONTENEDOR FREERADIUS
        $coa_port,
        escapeshellarg($coa_secret)
    );
    
    detailed_log("Ejecutando: $command");
    
    exec($command, $output, $return_var);
    $result = implode(" | ", $output);
    
    detailed_log("Código retorno: $return_var");
    detailed_log("Output: $result");
    
    // ✅ Análisis de respuesta
    if ($return_var === 0) {
        if (strpos($result, "Received Disconnect-ACK") !== false) {
            detailed_log("✅ ÉXITO: Disconnect-ACK recibido");
            $coa_message = "✅ Autorización exitosa - Saliendo del portal";
            $coa_sent = true;
        } elseif (strpos($result, "Received CoA-ACK") !== false) {
            detailed_log("✅ ÉXITO: CoA-ACK recibido");
            $coa_message = "✅ Autorización exitosa - Saliendo del portal";
            $coa_sent = true;
        } else {
            detailed_log("⚠️ CoA enviado pero respuesta inesperada");
            $coa_message = "✅ Procesando autorización...";
            $coa_sent = true; // Considerar éxito
        }
    } else {
        detailed_log("❌ ERROR: Código $return_var");
        $coa_message = "⚠️ Error en autorización - Redirigiendo igual";
        $coa_sent = true; // Redirigir de todas formas
    }
    
    unlink($tmpFile);
    $_SESSION['coa_executed'] = true;
    
} else {
    $coa_message = "⚠️ No se detectó dirección MAC";
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
            height: 100vh;
            text-align: center;
            padding: 20px;
            color: #333;
        }
        .logo { width: 250px; margin-bottom: 30px; border-radius: 15px; }
        .container {
            background: white;
            padding: 40px 30px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
        }
        .spinner {
            width: 70px;
            height: 70px;
            border: 8px solid #e3f2fd;
            border-top: 8px solid #2196f3;
            border-radius: 50%;
            animation: spin 1.5s linear infinite;
            margin: 0 auto 25px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .status {
            font-size: 1.3rem;
            color: #1976d2;
            margin: 20px 0;
            font-weight: bold;
        }
        .docker-config {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
            border-left: 4px solid #4caf50;
        }
        .countdown {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            font-size: 1.1rem;
        }
        .success-badge {
            background: #4caf50;
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            margin: 15px 0;
            display: inline-block;
        }
    </style>
    
    <meta http-equiv="refresh" content="3;url=<?php echo $redirect_url; ?>">
    <script>
        let seconds = 3;
        setInterval(() => {
            seconds--;
            document.getElementById('countdown').textContent = seconds;
        }, 1000);
        
        // Intentar salir del portal
        setTimeout(() => {
            window.open('http://google.com', '_blank');
        }, 1000);
    </script>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet Logo" class="logo">

    <div class="container">
        <div class="spinner"></div>
        
        <div class="success-badge">
            🐳 Docker Network Fixed
        </div>
        
        <div class="status">
            <?php echo htmlspecialchars($coa_message); ?>
        </div>
        
        <div class="countdown">
            Redirigiendo en <span id="countdown">3</span> segundos...
        </div>
        
        <div class="docker-config">
            <strong>🔧 Configuración aplicada:</strong><br>
            • FreeRADIUS Container: <code>172.18.0.2:4325</code><br>
            • AP Aruba: <code>192.168.0.9</code><br>
            • PHP Container: <code>172.18.0.4</code><br>
            • Red Docker: <code>172.18.0.0/16</code>
        </div>
        
        <?php if (!empty($mac)): ?>
        <div style="margin-top: 15px; padding: 12px; background: #f8f9fa; border-radius: 8px;">
            <strong>📱 Dispositivo:</strong> <?php echo htmlspecialchars($mac); ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>