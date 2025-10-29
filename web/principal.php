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
 * Valida cédula ecuatoriana (10 dígitos, provincia 01–24, dígito verificador).
 */
function validar_cedula_ec($cedula) {
    if (!preg_match('/^\d{10}$/', $cedula)) return false;

    $prov = intval(substr($cedula, 0, 2));
    $tercer = intval($cedula[2]);

    if ($prov < 1 || $prov > 24) return false;       // provincias válidas
    if ($tercer >= 6) return false;                  // persona natural (0-5)

    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $num = intval($cedula[$i]);
        if ($i % 2 == 0) { // posiciones impares (0,2,4,6,8) *2
            $num *= 2;
            if ($num > 9) $num -= 9;
        }
        $sum += $num;
    }
    $verificador = (10 - ($sum % 10)) % 10;
    return $verificador === intval($cedula[9]);
}

/**
 * Valida nombre/apellido: solo letras/espacios/tildes/ñ, >=2 letras, al menos una vocal.
 */
function validar_nombre_real($texto) {
    $texto = trim($texto);
    // Solo letras con tildes, ñ y espacios/apóstrofos/guiones moderados
    if (!preg_match("/^[A-Za-zÁÉÍÓÚáéíóúÑñÜü' -]{2,}$/u", $texto)) return false;
    // Al menos 2 letras (no solo símbolos/espacios)
    if (!preg_match("/[A-Za-zÁÉÍÓÚáéíóúÑñÜü].*[A-Za-zÁÉÍÓÚáéíóúÑñÜü]/u", $texto)) return false;
    // Al menos una vocal para evitar strings como "qwrtt" o "zzzz"
    if (!preg_match("/[AEIOUaeiouÁÉÍÓÚáéíóúÜü]/u", $texto)) return false;
    // Evitar más de 3 caracteres iguales consecutivos (ej: "aaaaaa")
    if (preg_match("/(.)\\1{3,}/u", $texto)) return false;
    return true;
}

/**
 * Lanza el CoA en background (&) para no bloquear la respuesta al usuario.
 * Usa /tmp/coa_async.log para logging de radclient.
 * Pasa preferentemente la IP del AP/Controlador en $ap_ip (no la IP del cliente).
 */
function start_coa_async($mac, $ap_ip) {
    if (empty($mac) || empty($ap_ip)) {
        error_log("❌ start_coa_async: mac o ap_ip vacíos");
        return false;
    }

    $coa_secret = "telecom";
    $coa_port   = "4325"; // Mantengo tu puerto actual

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

/** ============= Conexión a la Base de Datos ============= */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    error_log("✅ CONEXIÓN BD EXITOSA");
} catch (Exception $e) {
    error_log("❌ ERROR CONEXIÓN BD: " . $e->getMessage());
    die("<div class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</div>");
}

/** ============= Parámetros de entrada ============= */

$mac_raw  = $_GET['mac']    ?? $_POST['mac']    ?? '';
$ip_raw   = $_GET['ip']     ?? $_POST['ip']     ?? '';
$url_raw  = $_GET['url']    ?? $_POST['url']    ?? '';
$ap_raw   = $_GET['ap_mac'] ?? $_POST['ap_mac'] ?? ''; // por si lo usas
$essid    = $_GET['essid']  ?? $_POST['essid']  ?? '';

$ap_ip_default = '192.168.0.9';
$ap_ip_input   = $_GET['ap_ip'] ?? $_POST['ap_ip'] ?? '';
$ap_ip         = trim($ap_ip_input) !== '' ? trim($ap_ip_input) : $ap_ip_default;

$mac_norm = normalize_mac($mac_raw);
$ap_norm  = normalize_mac($ap_raw);
$ip       = trim($ip_raw);

error_log("🔍 PARÁMETROS - MAC: '$mac_norm', IP Cliente: '$ip', AP_IP: '$ap_ip'");

/** ============= Manejo POST (Registro/Conexión) ============= */

$errors = [
    'nombre'   => '',
    'apellido' => '',
    'cedula'   => '',
    'telefono' => '',
    'email'    => '',
    'terminos' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("📨 PROCESANDO FORMULARIO POST");

    $nombre   = $_POST['nombre']   ?? '';
    $apellido = $_POST['apellido'] ?? '';
    $cedula   = $_POST['cedula']   ?? '';
    $telefono = $_POST['telefono'] ?? '';
    $email    = $_POST['email']    ?? '';
    $terminos = isset($_POST['terminos']) ? 1 : 0;

    $mac_post   = $_POST['mac']    ?? '';
    $ip_post    = $_POST['ip']     ?? '';
    $ap_ip_post = $_POST['ap_ip']  ?? $ap_ip;

    $mac_norm   = normalize_mac($mac_post);
    $ip         = trim($ip_post);
    $ap_ip      = trim($ap_ip_post) !== '' ? trim($ap_ip_post) : $ap_ip;

    // ====== VALIDACIONES SERVIDOR ======
    if (!validar_nombre_real($nombre)) {
        $errors['nombre'] = 'Ingresa un nombre válido (solo letras, mínimo 2 y con al menos una vocal).';
    }
    if (!validar_nombre_real($apellido)) {
        $errors['apellido'] = 'Ingresa un apellido válido (solo letras, mínimo 2 y con al menos una vocal).';
    }
    if (!validar_cedula_ec($cedula)) {
        $errors['cedula'] = 'Cédula inválida. Verifica los 10 dígitos y el dígito verificador.';
    }
    if (!preg_match('/^09\d{8}$/', $telefono)) {
        $errors['telefono'] = 'Teléfono inválido. Debe tener 10 dígitos y comenzar con 09.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email inválido. Revisa el formato (ejemplo@dominio.com).';
    }
    if (!$terminos) {
        $errors['terminos'] = 'Debes aceptar los términos y condiciones para registrarte.';
    }
    if ($mac_norm === '') {
        // Error crítico, pero lo dejamos como mensaje general
        $errors['nombre'] = $errors['nombre'] ?: 'No se detectó una MAC válida desde el portal.';
    }

    $hay_errores = array_filter($errors, fn($e) => $e !== '');

    if (!$hay_errores) {
        try {
            $conn->begin_transaction();
            error_log("🔄 INICIANDO TRANSACCIÓN BD");

            // 1) Verificar si ya existe en radcheck
            $check_radcheck = $conn->prepare("
                SELECT id FROM radcheck 
                WHERE username = ? AND attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept'
            ");
            $check_radcheck->bind_param("s", $mac_norm);
            $check_radcheck->execute();
            $check_radcheck->store_result();

            if ($check_radcheck->num_rows > 0) {
                $check_radcheck->close();
                $conn->commit();

                error_log("ℹ️ MAC $mac_norm YA EXISTE en radcheck, lanzando CoA en background...");
                start_coa_async($mac_norm, $ap_ip);
                redirect_to_bienvenido($mac_norm, $ip);
            }
            $check_radcheck->close();

            // 2) Insertar en clients
            $stmt_clients = $conn->prepare("
                INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt_clients->bind_param("ssssss", $nombre, $apellido, $cedula, $telefono, $email, $mac_norm);
            $stmt_clients->execute();
            $client_id = $conn->insert_id;
            $stmt_clients->close();
            error_log("✅ CLIENTE INSERTADO con ID: $client_id");

            // 3) Insertar en radcheck (auto-aceptar por MAC)
            $stmt_radcheck = $conn->prepare("
                INSERT INTO radcheck (username, attribute, op, value)
                VALUES (?, 'Auth-Type', ':=', 'Accept')
            ");
            $stmt_radcheck->bind_param("s", $mac_norm);
            $stmt_radcheck->execute();
            $radcheck_id = $conn->insert_id;
            $stmt_radcheck->close();
            error_log("✅ RADCHECK INSERTADO con ID: $radcheck_id");

            $conn->commit();
            error_log("✅ TRANSACCIÓN BD COMPLETADA");

            // 4) Lanzar CoA en background (no bloquea UX)
            error_log("🎉 REGISTRO COMPLETADO, lanzando CoA en background...");
            start_coa_async($mac_norm, $ap_ip);

            // 5) Redirigir de inmediato
            error_log("🔄 REDIRIGIENDO A BIENVENIDO.PHP");
            redirect_to_bienvenido($mac_norm, $ip);

        } catch (Exception $e) {
            error_log("❌ ERROR EN REGISTRO: " . $e->getMessage());
            error_log("❌ CÓDIGO ERROR: " . $conn->errno);
            error_log("❌ MENSAJE ERROR: " . $conn->error);

            if ($conn->errno) {
                $conn->rollback();
                error_log("🔄 TRANSACCIÓN REVERTIDA");
            }

            if ($conn->errno == 1062) {
                // Duplicado: ya existe. Lanza CoA en background y redirige
                error_log("⚠️ MAC $mac_norm YA EXISTE (1062), lanzando CoA en background...");
                start_coa_async($mac_norm, $ap_ip);
                redirect_to_bienvenido($mac_norm, $ip);
            } else {
                die("<div class='error'>❌ Registration failed: " . htmlspecialchars($e->getMessage()) . " (Error: " . $conn->errno . ")</div>");
            }
        }
    }
}

/** ============= Estado de la MAC para la UI ============= */

$mac_status    = 'new';
$client_exists = false;
if ($mac_norm !== '') {
    try {
        $check_radcheck_display = $conn->prepare("
            SELECT id FROM radcheck 
            WHERE username = ? AND attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept'
        ");
        $check_radcheck_display->bind_param("s", $mac_norm);
        $check_radcheck_display->execute();
        $check_radcheck_display->store_result();

        if ($check_radcheck_display->num_rows > 0) {
            $mac_status = 'registered';
        }
        $check_radcheck_display->close();

        $check_clients_display = $conn->prepare("SELECT id FROM clients WHERE mac = ?");
        $check_clients_display->bind_param("s", $mac_norm);
        $check_clients_display->execute();
        $check_clients_display->store_result();

        if ($check_clients_display->num_rows > 0) {
            $client_exists = true;
        }
        $check_clients_display->close();

    } catch (Exception $e) {
        error_log("⚠️ Error verificando estado: " . $e->getMessage());
    }
}

// Helper para repoblar valores y marcar errores en inputs
function v($key){ return htmlspecialchars($_POST[$key] ?? '', ENT_QUOTES, 'UTF-8'); }
function err($key,$errors){ return $errors[$key] ?? ''; }
function has_err($key,$errors){ return !empty($errors[$key]); }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro Wi-Fi - GoNet</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Arial', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; color: #333; }
        .top-image, .bottom-image { width: 100%; max-width: 400px; border-radius: 15px; margin: 10px 0; box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .form-container { background: white; padding: 30px 25px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); width: 100%; max-width: 450px; margin: 20px 0; }
        h2 { color: #2c3e50; text-align: center; margin-bottom: 25px; font-size: 1.8rem; font-weight: 600; }
        .form-group { margin-bottom: 16px; }
        label { display:block; margin-bottom:6px; }
        input { width: 100%; padding: 12px; border: 2px solid #e1e8ed; border-radius: 12px; font-size: 1rem; transition: all 0.2s ease; }
        input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        button { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 12px; font-size: 1.05rem; font-weight: 600; cursor: pointer; margin-top: 10px; transition: all 0.2s ease; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3); }
        button:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4); }
        .error { background: #ffebee; color: #c62828; padding: 12px; border-radius: 10px; margin: 15px 0; text-align: center; font-size: 0.9rem; border-left: 4px solid #c62828; }
        .mac-display { background: #f8f9fa; padding: 15px; border-radius: 12px; margin: 15px 0; font-size: 0.95rem; color: #2c3e50; text-align: center; word-wrap: break-word; border: 2px solid #e9ecef; }
        .info-display { background: #e3f2fd; padding: 12px; border-radius: 10px; margin: 10px 0; font-size: 0.9rem; color: #1565c0; text-align: center; border-left: 4px solid #2196f3; }
        .status-info { background: #e8f5e8; padding: 15px; border-radius: 10px; margin: 15px 0; font-size: 0.95rem; color: #2e7d32; text-align: center; border-left: 4px solid #4caf50; font-weight: 500; }
        .warning-info { background: #fff3e0; padding: 15px; border-radius: 10px; margin: 15px 0; font-size: 0.95rem; color: #ef6c00; text-align: center; border-left: 4px solid #ff9800; }
        .required::after { content: " *"; color: #e74c3c; }
        .terminos-container { background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 15px 0; border: 2px solid #e9ecef; }
        .terminos-checkbox { display: flex; align-items: flex-start; gap: 10px; margin: 10px 0; }
        .terminos-checkbox input[type="checkbox"] { width: 20px; height: 20px; margin-top: 2px; }
        .terminos-text { font-size: 0.9rem; color: #555; line-height: 1.4; }
        .terminos-link { color: #667eea; text-decoration: none; font-weight: 500; }
        .terminos-link:hover { text-decoration: underline; }

        /* 👇 Estilos de errores por campo */
        .field-error { color: #c62828; font-size: 0.85rem; margin-top: 6px; }
        .input-error { border-color: #e53935 !important; box-shadow: 0 0 0 3px rgba(229,57,53,0.1) !important; }

        @media (max-width: 480px) {
            .form-container { padding: 25px 20px; border-radius: 15px; margin: 15px 0; }
            input, button { font-size: 1rem; }
            h2 { font-size: 1.5rem; }
            body { padding: 15px; }
        }
    </style>
</head>
<body>

    <img src="gonetlogo.png" alt="GoNet Logo" class="top-image">

    <div class="form-container">
        <h2> Registro para Wi-Fi</h2>

        <?php if ($mac_norm === ''): ?>
            <div class="error">
                ❌ No se detectó ninguna dirección MAC.<br>
                <small>Conéctate a la red Wi-Fi y accede desde el portal cautivo.</small>
            </div>
        <?php elseif ($mac_status === 'registered'): ?>
            <div class="status-info">
                ✅ Este dispositivo ya está registrado.<br>
                <strong>Serás conectado inmediatamente.</strong>
            </div>
        <?php elseif ($client_exists && $mac_status === 'new'): ?>
            <div class="warning-info">
                ⚠️ Dispositivo registrado pero necesita configuración.<br>
                <strong>Completa el registro para conectar.</strong>
            </div>
        <?php else: ?>
            <div class="info-display">
                📝 Completa el registro para acceder a Internet
            </div>
        <?php endif; ?>

        <?php if ($mac_norm !== ''): ?>
        <form method="POST" autocomplete="on" id="registrationForm" novalidate>
            <div class="form-group">
                <label class="required" for="nombre">Nombre</label>
                <input type="text" id="nombre" name="nombre"
                       placeholder="Tu nombre"
                       value="<?php echo v('nombre'); ?>"
                       class="<?php echo has_err('nombre',$errors)?'input-error':''; ?>"
                       inputmode="text" />
                <?php if (err('nombre',$errors)): ?>
                    <div class="field-error"><?php echo htmlspecialchars(err('nombre',$errors)); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required" for="apellido">Apellido</label>
                <input type="text" id="apellido" name="apellido"
                       placeholder="Tu apellido"
                       value="<?php echo v('apellido'); ?>"
                       class="<?php echo has_err('apellido',$errors)?'input-error':''; ?>"
                       inputmode="text" />
                <?php if (err('apellido',$errors)): ?>
                    <div class="field-error"><?php echo htmlspecialchars(err('apellido',$errors)); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required" for="cedula">Cédula</label>
                <input type="text" id="cedula" name="cedula"
                       placeholder="Número de cédula"
                       value="<?php echo v('cedula'); ?>"
                       class="<?php echo has_err('cedula',$errors)?'input-error':''; ?>"
                       inputmode="numeric" maxlength="10" />
                <?php if (err('cedula',$errors)): ?>
                    <div class="field-error"><?php echo htmlspecialchars(err('cedula',$errors)); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required" for="telefono">Teléfono</label>
                <input type="text" id="telefono" name="telefono"
                       placeholder="09XXXXXXXX"
                       value="<?php echo v('telefono'); ?>"
                       class="<?php echo has_err('telefono',$errors)?'input-error':''; ?>"
                       inputmode="numeric" maxlength="10" />
                <?php if (err('telefono',$errors)): ?>
                    <div class="field-error"><?php echo htmlspecialchars(err('telefono',$errors)); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="required" for="email">Email</label>
                <input type="email" id="email" name="email"
                       placeholder="correo@ejemplo.com"
                       value="<?php echo v('email'); ?>"
                       class="<?php echo has_err('email',$errors)?'input-error':''; ?>" />
                <?php if (err('email',$errors)): ?>
                    <div class="field-error"><?php echo htmlspecialchars(err('email',$errors)); ?></div>
                <?php endif; ?>
            </div>

            <div class="terminos-container">
                <div class="terminos-checkbox">
                    <input type="checkbox" name="terminos" id="terminos" <?php echo isset($_POST['terminos'])?'checked':''; ?>>
                    <label for="terminos" class="terminos-text">
                        Acepto los <a href="terminos.html" target="_blank" class="terminos-link">Términos y Condiciones</a> 
                        y la <a href="privacidad.html" target="_blank" class="terminos-link">Política de Privacidad</a> 
                        de GoNet Wi-Fi.
                    </label>
                </div>
                <?php if (err('terminos',$errors)): ?>
                    <div class="field-error"><?php echo htmlspecialchars(err('terminos',$errors)); ?></div>
                <?php endif; ?>
            </div>

            <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac_norm); ?>">
            <input type="hidden" name="ip"  value="<?php echo htmlspecialchars($ip); ?>">
            <!-- <input type="hidden" name="ap_ip" value="<?php echo htmlspecialchars($ap_ip); ?>"> -->

            <button type="submit" id="submitBtn">
                <?php echo $mac_status === 'registered' ? '✅ Conectar Ahora' : '🚀 Registrar y Conectar'; ?>
            </button>
        </form>
        <?php endif; ?>
    </div>

    <img src="banner.png" alt="Banner" class="bottom-image">

    <script>
        const $ = (id)=>document.getElementById(id);
        const setErr = (input, msg)=>{
            input.classList.add('input-error');
            let err = input.parentElement.querySelector('.field-error');
            if(!err){
                err = document.createElement('div');
                err.className = 'field-error';
                input.parentElement.appendChild(err);
            }
            err.textContent = msg;
        };
        const clearErr = (input)=>{
            input.classList.remove('input-error');
            const err = input.parentElement.querySelector('.field-error');
            if(err) err.textContent = '';
        };

        // Validadores lado cliente (coherentes con servidor)
        const isValidNombre = (s)=>{
            if(!s) return false;
            if(!/^[A-Za-zÁÉÍÓÚáéíóúÑñÜü' -]{2,}$/.test(s)) return false;
            if(!/[AEIOUaeiouÁÉÍÓÚáéíóúÜü]/.test(s)) return false;
            if(/(.)\1{3,}/.test(s)) return false;
            return true;
        };
        const isValidCedula = (ced)=>{
            if(!/^\d{10}$/.test(ced)) return false;
            const prov = parseInt(ced.slice(0,2),10);
            const tercer = parseInt(ced[2],10);
            if(prov < 1 || prov > 24) return false;
            if(tercer >= 6) return false;
            let sum=0;
            for(let i=0;i<9;i++){
                let num = parseInt(ced[i],10);
                if(i%2===0){ num*=2; if(num>9) num-=9; }
                sum+=num;
            }
            const ver = (10 - (sum % 10)) % 10;
            return ver === parseInt(ced[9],10);
        };
        const isValidTel = (t)=> /^09\d{8}$/.test(t);
        const isValidEmail = (e)=> /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);

        const nombre = $('nombre');
        const apellido = $('apellido');
        const cedula = $('cedula');
        const telefono = $('telefono');
        const email = $('email');
        const terminos = $('terminos');

        const attach = (input, fn, msg)=>{
            input?.addEventListener('input', ()=>{
                if(fn(input.value.trim())) clearErr(input);
            });
            input?.addEventListener('blur', ()=>{
                const v = input.value.trim();
                if(!fn(v)) setErr(input, msg);
                else clearErr(input);
            });
        };

        attach(nombre, isValidNombre, 'Ingresa un nombre válido (solo letras, mínimo 2 y con al menos una vocal).');
        attach(apellido, isValidNombre, 'Ingresa un apellido válido (solo letras, mínimo 2 y con al menos una vocal).');
        attach(cedula, isValidCedula, 'Cédula inválida. Verifica los 10 dígitos y el dígito verificador.');
        attach(telefono, isValidTel, 'Teléfono inválido. Debe tener 10 dígitos y comenzar con 09.');
        attach(email, isValidEmail, 'Email inválido. Revisa el formato.');

        // Solo números en cédula/teléfono
        cedula?.addEventListener('input', e=>{
            e.target.value = e.target.value.replace(/\D+/g,'').slice(0,10);
        });
        telefono?.addEventListener('input', e=>{
            e.target.value = e.target.value.replace(/\D+/g,'').slice(0,10);
        });

        document.getElementById('registrationForm')?.addEventListener('submit', function(e) {
            let ok = true;

            if(!isValidNombre(nombre.value.trim())) { setErr(nombre,'Ingresa un nombre válido (solo letras, mínimo 2 y con al menos una vocal).'); ok=false; }
            if(!isValidNombre(apellido.value.trim())) { setErr(apellido,'Ingresa un apellido válido (solo letras, mínimo 2 y con al menos una vocal).'); ok=false; }
            if(!isValidCedula(cedula.value.trim())) { setErr(cedula,'Cédula inválida. Verifica los 10 dígitos y el dígito verificador.'); ok=false; }
            if(!isValidTel(telefono.value.trim())) { setErr(telefono,'Teléfono inválido. Debe tener 10 dígitos y comenzar con 09.'); ok=false; }
            if(!isValidEmail(email.value.trim())) { setErr(email,'Email inválido. Revisa el formato.'); ok=false; }
            if(!terminos.checked){
                // mostrar error bajo el checkbox
                let err = terminos.closest('.terminos-container').querySelector('.field-error');
                if(!err){
                    err = document.createElement('div');
                    err.className = 'field-error';
                    terminos.closest('.terminos-container').appendChild(err);
                }
                err.textContent = 'Debes aceptar los términos y condiciones para registrarte.';
                ok = false;
            } else {
                const err = terminos.closest('.terminos-container').querySelector('.field-error');
                if(err) err.textContent = '';
            }

            if(!ok){
                e.preventDefault();
                return false;
            }

            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '⏳ Procesando...';
            submitBtn.disabled = true;
        });
    </script>

</body>
</html>
