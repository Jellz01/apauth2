<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";

/** ===================== Helpers ===================== */

function normalize_mac($mac_raw) {
    if (empty($mac_raw)) return '';
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac_raw);
    return strtoupper($hex);
}

function redirect_to_bienvenido($mac_norm, $ip) {
    error_log("🎯 REDIRIGIENDO A BIENVENIDO.PHP CON MAC: $mac_norm, IP: $ip");

    $_SESSION['registration_mac'] = $mac_norm;
    $_SESSION['registration_ip']  = $ip;
    $_SESSION['coa_executed']     = false;

    $bienvenido_url = 'bienvenido.php';

    if (!headers_sent()) {
        header("Location: " . $bienvenido_url);
        exit;
    } else {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="refresh" content="0;url=' . htmlspecialchars($bienvenido_url) . '">
        </head>
        <body>
            <p>Redireccionando... <a href="' . htmlspecialchars($bienvenido_url) . '">Click aquí</a></p>
            <script>window.location.href = "' . htmlspecialchars($bienvenido_url) . '";</script>
        </body>
        </html>';
        exit;
    }
}

/**
 * Lanza el CoA en background (&) para no bloquear la respuesta al usuario.
 * Se recomienda apuntar al AP/Controlador (NAS) verdadero.
 */
function start_coa_async($mac, $ap_ip) {
    if (empty($mac) || empty($ap_ip)) {
        error_log("❌ start_coa_async: mac o ap_ip vacíos");
        return false;
    }

    $coa_secret = "telecom";
    $coa_port   = "4325"; // si en Aruba usas 3799, unifica aquí y en el NAS

    $payload = sprintf('User-Name=%s', addslashes($mac));
    $cmd = sprintf(
        'sh -c \'echo "%s" | radclient -r 2 -t 3 -x %s:%s disconnect %s >> /tmp/coa_async.log 2>&1 &\'',
        $payload,
        escapeshellarg($ap_ip),
        $coa_port,
        escapeshellarg($coa_secret)
    );

    error_log("🚀 Lanzando CoA en background: $cmd");
    exec($cmd); // no bloquea
    return true;
}

/** ============= Conexión a la Base de Datos (opcional) ============= */
/* No estrictamente necesaria para este flujo, pero la dejamos por consistencia */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    error_log("✅ CONEXIÓN BD EXITOSA");
} catch (Exception $e) {
    error_log("❌ ERROR CONEXIÓN BD: " . $e->getMessage());
    // No matamos la página, este flujo puede funcionar sin DB
}

/** ============= Parámetros de entrada ============= */

$mac_raw  = $_GET['mac']    ?? $_POST['mac']    ?? '';
$ip_raw   = $_GET['ip']     ?? $_POST['ip']     ?? '';
$url_raw  = $_GET['url']    ?? $_POST['url']    ?? '';
$ap_raw   = $_GET['ap_mac'] ?? $_POST['ap_mac'] ?? ''; // por si lo usas
$essid    = $_GET['essid']  ?? $_POST['essid']  ?? '';

$ap_ip_default = '192.168.0.9';                           // ⇐ Ajusta a tu NAS
$ap_ip_input   = $_GET['ap_ip'] ?? $_POST['ap_ip'] ?? '';
$ap_ip         = trim($ap_ip_input) !== '' ? trim($ap_ip_input) : $ap_ip_default;

$mac_norm = normalize_mac($mac_raw);
$ap_norm  = normalize_mac($ap_raw);
$ip       = trim($ip_raw);

error_log("🔍 PARÁMETROS - MAC: '$mac_norm', IP Cliente: '$ip', AP_IP: '$ap_ip'");

/** ============= Manejo POST (solo T&C) ============= */

$errors = ['terminos' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $terminos = isset($_POST['terminos']) ? 1 : 0;

    $mac_post   = $_POST['mac']    ?? '';
    $ip_post    = $_POST['ip']     ?? '';
    $ap_ip_post = $_POST['ap_ip']  ?? $ap_ip;

    $mac_norm   = normalize_mac($mac_post);
    $ip         = trim($ip_post);
    $ap_ip      = trim($ap_ip_post) !== '' ? trim($ap_ip_post) : $ap_ip;

    if (!$terminos) {
        $errors['terminos'] = 'Debes aceptar los términos y condiciones para continuar.';
    }
    if ($mac_norm === '') {
        // Normalmente esto NO debería pasar si el AP mandó la MAC
        $errors['terminos'] = $errors['terminos'] ?: 'No se detectó una MAC válida desde el portal.';
    }

    if (!$errors['terminos']) {
        // Lanzar CoA no bloqueante y redirigir a pantalla de “Conectando…”
        start_coa_async($mac_norm, $ap_ip);
        redirect_to_bienvenido($mac_norm, $ip);
    }
}

// Helper pequeño para errores
function err($key,$errors){ return $errors[$key] ?? ''; }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aceptar Términos - GoNet</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Arial', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; color: #333; }
        .top-image, .bottom-image { width: 100%; max-width: 400px; border-radius: 15px; margin: 10px 0; box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .form-container { background: white; padding: 30px 25px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); width: 100%; max-width: 450px; margin: 20px 0; }
        h2 { color: #2c3e50; text-align: center; margin-bottom: 25px; font-size: 1.8rem; font-weight: 600; }
        p  { margin: 8px 0; color:#4b5563; }
        .form-group { margin-bottom: 16px; }
        label { display:block; margin-bottom:6px; }
        input[type="checkbox"] { width: 20px; height: 20px; vertical-align: middle; margin-right:8px; }
        button { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 12px; font-size: 1.05rem; font-weight: 600; cursor: pointer; margin-top: 10px; transition: all 0.2s ease; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3); }
        button:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4); }
        .error { background: #ffebee; color: #c62828; padding: 12px; border-radius: 10px; margin: 15px 0; text-align: center; font-size: 0.9rem; border-left: 4px solid #c62828; }
        .info-display { background: #e3f2fd; padding: 12px; border-radius: 10px; margin: 10px 0; font-size: 0.95rem; color: #1565c0; text-align: left; border-left: 4px solid #2196f3; }
        .terminos-container { background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 15px 0; border: 2px solid #e9ecef; }
        .terminos-checkbox { display: flex; align-items: flex-start; gap: 10px; margin: 10px 0; }
        .terminos-text { font-size: 0.95rem; color: #374151; line-height: 1.5; }
        .terminos-link { color: #667eea; text-decoration: none; font-weight: 600; }
        .terminos-link:hover { text-decoration: underline; }
        .field-error { color: #c62828; font-size: 0.85rem; margin-top: 6px; }
        @media (max-width: 480px) {
            .form-container { padding: 25px 20px; border-radius: 15px; margin: 15px 0; }
            button { font-size: 1rem; }
            h2 { font-size: 1.5rem; }
            body { padding: 15px; }
        }
        ul { margin-left: 18px; }
    </style>
</head>
<body>

    <img src="gonetlogo.png" alt="GoNet Logo" class="top-image">

    <div class="form-container">
        <h2> Acceso Rápido a GoNet</h2>

        <?php if ($mac_norm === ''): ?>
            <div class="error">
                ❌ No se detectó ninguna dirección MAC.<br>
                <small>Conéctate a la red Wi-Fi y accede desde el portal cautivo.</small>
            </div>
        <?php endif; ?>

        <div class="info-display">
            <p><strong>Antes de conectar</strong>, por favor confirma que aceptas nuestros términos. En esta red podrás:</p>
            <ul>
                <li>Navegar en Internet y acceder a tus redes sociales.</li>
                <li>Actualizar tus apps y consultar correo.</li>
                <li>Acceder a contenido promocional y beneficios locales.</li>
            </ul>
            <p>El uso está sujeto a nuestra política de uso responsable.</p>
        </div>

        <?php if ($mac_norm !== ''): ?>
        <form method="POST" id="tcForm">
            <div class="terminos-container">
                <div class="terminos-checkbox">
                    <input type="checkbox" name="terminos" id="terminos" <?php echo isset($_POST['terminos'])?'checked':''; ?>>
                    <label for="terminos" class="terminos-text">
                        Acepto los <a href="terminos.html" target="_blank" class="terminos-link">Términos y Condiciones</a> y la
                        <a href="privacidad.html" target="_blank" class="terminos-link">Política de Privacidad</a> de GoNet Wi-Fi.
                    </label>
                </div>
                <?php if (err('terminos',$errors)): ?>
                    <div class="field-error"><?php echo htmlspecialchars(err('terminos',$errors)); ?></div>
                <?php endif; ?>
            </div>

            <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
            <input type="hidden" name="ip"  value="<?php echo htmlspecialchars($ip); ?>">
            <!-- Si quieres persistir el AP IP explícitamente: -->
            <!-- <input type="hidden" name="ap_ip" value="<?php echo htmlspecialchars($ap_ip); ?>"> -->

            <button type="submit" id="submitBtn">✅ Aceptar y Conectar</button>
        </form>
        <?php endif; ?>
    </div>

    <img src="banner.png" alt="Banner" class="bottom-image">

    <script>
        const terminos = document.getElementById('terminos');
        const form = document.getElementById('tcForm');
        form?.addEventListener('submit', (e)=>{
            let ok = true;
            let errBox = terminos.closest('.terminos-container').querySelector('.field-error');
            if(!terminos.checked){
                if(!errBox){
                    errBox = document.createElement('div');
                    errBox.className = 'field-error';
                    terminos.closest('.terminos-container').appendChild(errBox);
                }
                errBox.textContent = 'Debes aceptar los términos y condiciones para continuar.';
                ok = false;
            } else if (errBox) {
                errBox.textContent = '';
            }

            if(!ok){
                e.preventDefault();
                return false;
            }

            const btn = document.getElementById('submitBtn');
            btn.textContent = '⏳ Conectando…';
            btn.disabled = true;
        });
    </script>

</body>
</html>

