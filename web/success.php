<?php
// success.php - Página de éxito final
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoNet WiFi - ¡Conectado!</title>
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
            margin-bottom: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .success-container {
            background: white;
            padding: 50px 40px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
            animation: fadeIn 0.8s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .success-icon {
            font-size: 5rem;
            margin-bottom: 20px;
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
        
        h1 {
            color: #2e7d32;
            margin-bottom: 15px;
            font-size: 2.2rem;
        }
        
        .message {
            font-size: 1.2rem;
            color: #555;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .features {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 25px 0;
            text-align: left;
        }
        
        .features h3 {
            color: #1976d2;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .features ul {
            list-style: none;
            padding: 0;
        }
        
        .features li {
            padding: 8px 0;
            padding-left: 30px;
            position: relative;
        }
        
        .features li:before {
            content: "✓";
            color: #4caf50;
            font-weight: bold;
            position: absolute;
            left: 0;
        }
        
        .welcome-message {
            background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 5px solid #2196f3;
        }
        
        .timer {
            font-size: 0.9rem;
            color: #666;
            margin-top: 20px;
            background: #f5f5f5;
            padding: 10px;
            border-radius: 8px;
        }
    </style>
    
    <script>
        // Timer de sesión
        let sessionTime = 0;
        setInterval(function() {
            sessionTime++;
            const minutes = Math.floor(sessionTime / 60);
            const seconds = sessionTime % 60;
            document.getElementById('session-timer').textContent = 
                `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }, 1000);
    </script>
</head>
<body>
    <img src="gonetlogo.png" alt="GoNet Logo" class="logo">

    <div class="success-container">
        <div class="success-icon">🎉</div>
        
        <h1>¡Conexión Exitosa!</h1>
        
        <div class="message">
            Estás conectado a <strong>GoNet WiFi</strong><br>
            Disfruta de tu conexión a internet
        </div>
        
        <div class="welcome-message">
            <strong>¡Bienvenido a nuestra red!</strong><br>
            Tu dispositivo ha sido autorizado correctamente.
        </div>
        
        <div class="features">
            <h3>✅ Servicios Disponibles</h3>
            <ul>
                <li>Navegación web ilimitada</li>
                <li>Redes sociales y mensajería</li>
                <li>Streaming de video y música</li>
                <li>Descargas y actualizaciones</li>
                <li>Juegos online</li>
            </ul>
        </div>
        
        <?php if (isset($_SESSION['registration_mac'])): ?>
        <div style="background: #e8f5e9; padding: 15px; border-radius: 10px; margin: 15px 0;">
            <strong>📱 Dispositivo:</strong><br>
            MAC: <code><?php echo htmlspecialchars($_SESSION['registration_mac']); ?></code>
            <?php if (isset($_SESSION['registration_ip'])): ?>
            <br>IP: <code><?php echo htmlspecialchars($_SESSION['registration_ip']); ?></code>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="timer">
            ⏱️ Tiempo de sesión: <span id="session-timer">00:00</span>
        </div>
        
        <div style="margin-top: 25px; font-size: 0.9rem; color: #666;">
            ¿Problemas con la conexión?<br>
            Contacta a soporte técnico.
        </div>
    </div>
</body>
</html>