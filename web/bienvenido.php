<?php
// bienvenido.php - CON LOADING SIEMPRE VISIBLE
session_start();

$ap_ip = '192.168.0.9';
$coa_port = 4325;
$coa_secret = 'telecom';

$log_file = '/tmp/coa_success.log';

function detailed_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $full_message = "[$timestamp] $message\n";
    file_put_contents($log_file, $full_message, FILE_APPEND);
}

// Obtener datos de sesión
$mac = isset($_SESSION['registration_mac']) ? trim($_SESSION['registration_mac']) : '';
$ip = isset($_SESSION['registration_ip']) ? trim($_SESSION['registration_ip']) : '';

// ✅ ENVIAR CoA SI HAY MAC (en background)
if (!empty($mac)) {
    detailed_log("Enviando CoA para MAC: $mac");
    
    $mac_cleaned = preg_replace('/[^A-Fa-f0-9:]/', '', $mac);
    $attributes = "User-Name = \"$mac_cleaned\"\nAcct-Session-Id = \"coa-reauth-" . time() . "\"";
    
    $tmpFile = tempnam(sys_get_temp_dir(), 'coa_');
    file_put_contents($tmpFile, $attributes);
    
    // Ejecutar CoA en background (no esperar respuesta)
    $command = sprintf(
        'cat %s | radclient -r 1 -t 1 %s:%d coa %s > /dev/null 2>&1 &',
        escapeshellarg($tmpFile),
        escapeshellarg($ap_ip),
        $coa_port,
        escapeshellarg($coa_secret)
    );
    
    shell_exec($command);
    unlink($tmpFile);
    $_SESSION['coa_executed'] = true;
    detailed_log("CoA enviado en background para: $mac_cleaned");
}

// ✅ SIEMPRE REDIRIGIR después de 3 segundos
$redirect_url = 'success.php';
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
            overflow: hidden;
        }
        
        .logo {
            width: 250px;
            margin-bottom: 40px;
            border-radius: 15px;
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .loading-container {
            background: white;
            padding: 50px 40px;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.15);
            max-width: 500px;
            width: 100%;
            animation: slideUp 0.8s ease-out;
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0; 
                transform: translateY(50px) scale(0.9); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1); 
            }
        }
        
        .spinner-large {
            width: 80px;
            height: 80px;
            border: 8px solid #e3f2fd;
            border-top: 8px solid #2196f3;
            border-radius: 50%;
            animation: spin 1.5s linear infinite;
            margin: 0 auto 30px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .status-text {
            font-size: 1.4rem;
            color: #1976d2;
            margin: 20px 0;
            font-weight: bold;
        }
        
        .progress-container {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            margin: 30px 0;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #2196f3, #21cbf3);
            border-radius: 4px;
            animation: progress 3s ease-in-out;
            width: 100%;
        }
        
        @keyframes progress {
            0% { width: 0%; }
            100% { width: 100%; }
        }
        
        .countdown {
            font-size: 1.1rem;
            color: #666;
            margin: 25px 0;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            border: 2px solid #e9ecef;
        }
        
        .device-card {
            background: linear-gradient(135deg, #e8f5e9, #f1f8e9);
            padding: 20px;
            border-radius: 12px;
            margin: 25px 0;
            text-align: left;
            border-left: 5px solid #4caf50;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
        
        .steps {
            display: flex;
            justify-content: space-between;
            margin: 30px 0;
            position: relative;
        }
        
        .step {
            text-align: center;
            flex: 1;
            position: relative;
            z-index: 2;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            background: #4caf50;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: bold;
        }
        
        .step.active .step-number {
            background: #2196f3;
            animation: pulse 1s infinite;
        }
        
        .step-text {
            font-size: 0.8rem;
            color: #666;
        }
        
        .steps:before {
            content: '';
            position: absolute;
            top: 20px;
            left: 10%;
            right: 10%;
            height: 3px;
            background: #e0e0e0;
            z-index: 1;
        }
    </style>
    
    <!-- REDIRECCIÓN AUTOMÁTICA -->
    <meta http-equiv="refresh" content="3;url=<?php echo $redirect_url; ?>">
    <script>
        // Contador regresivo animado
        let seconds = 3;
        function updateCountdown() {
            seconds--;
            const countdownElement = document.getElementById('countdown');
            if (countdownElement) {
                countdownElement.textContent = seconds;
                countdownElement.style.transform = 'scale(1.2)';
                setTimeout(() => {
                    countdownElement.style.transform = 'scale(1)';
                }, 200);
            }
            if (seconds > 0) {
                setTimeout(updateCountdown, 1000);
            }
        }
        
        // Iniciar animaciones después de cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(updateCountdown, 1000);
            
            // Animar pasos progresivamente
            const steps = document.querySelectorAll('.step');
            steps.forEach((step, index) => {
                setTimeout(() => {
                    step.classList.add('active');
                }, index * 800);
            });
        });
        
        // Redirección por JavaScript también
        setTimeout(function() {
            window.location.href = '<?php echo $redirect_url; ?>';
        }, 3000);
    </script>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet Logo" class="logo">

    <div class="loading-container">
        <div class="spinner-large"></div>
        
        <div class="status-text">
            Conectando a GoNet WiFi...
        </div>
        
        <!-- Pasos de conexión -->
        <div class="steps">
            <div class="step active">
                <div class="step-number">1</div>
                <div class="step-text">Autenticando</div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div class="step-text">Autorizando</div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div class="step-text">Conectado</div>
            </div>
        </div>
        
        <div class="progress-container">
            <div class="progress-bar"></div>
        </div>
        
        <div class="countdown">
            ✅ Redirigiendo en <span id="countdown" style="font-weight: bold; color: #2196f3;">3</span> segundos...
        </div>
        
        <?php if (!empty($mac)): ?>
        <div class="device-card">
            <strong>📱 Tu dispositivo se está conectando:</strong><br>
            <div style="margin-top: 10px;">
                🔹 MAC: <code><?php echo htmlspecialchars($mac); ?></code><br>
                <?php if (!empty($ip)): ?>
                🔹 IP: <code><?php echo htmlspecialchars($ip); ?></code>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 20px; font-size: 0.9rem; color: #666;">
            ⚡ Estamos configurando tu conexión de forma segura
        </div>
    </div>
</body>
</html>