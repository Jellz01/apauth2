<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Archivo de debug para portal cautivo TP-Link / Omada / lo-que-sea.
 * Muestra TODO lo que llega.
 * Guarda también en /tmp/captive_dump.log dentro del contenedor.
 */

function get_all_headers_safe() {
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $h = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$h] = $value;
        }
    }
    return $headers;
}

$raw_body = file_get_contents('php://input');
$headers  = get_all_headers_safe();
$now      = date('Y-m-d H:i:s');

$dump = [
    'time'        => $now,
    'method'      => $_SERVER['REQUEST_METHOD'] ?? '',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'query_string'=> $_SERVER['QUERY_STRING'] ?? '',
    'GET'         => $_GET,
    'POST'        => $_POST,
    'FILES'       => $_FILES,
    'HEADERS'     => $headers,
    'RAW_BODY'    => $raw_body,
    'SESSION'     => $_SESSION,
];

// intentar decodificar body como JSON por si el AP manda JSON
$json_body = null;
if (!empty($raw_body)) {
    $decoded = json_decode($raw_body, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $json_body = $decoded;
        $dump['RAW_BODY_JSON_DECODED'] = $decoded;
    }
}

// log a archivo dentro del contenedor
$log_file = '/tmp/captive_dump.log';
file_put_contents($log_file, "========== $now ==========\n" . print_r($dump, true) . "\n\n", FILE_APPEND);

// detección de posibles campos de MAC (del cliente y del AP)
$posibles_campos_mac = [
    'mac','clientMac','client_mac','sta_mac','staMac','user_mac',
    'ap_mac','apMac','bssid','ap','apmac',
];
$mac_detectadas = [];
foreach ($posibles_campos_mac as $campo) {
    if (isset($_GET[$campo]))   $mac_detectadas[$campo]['get']  = $_GET[$campo];
    if (isset($_POST[$campo]))  $mac_detectadas[$campo]['post'] = $_POST[$campo];
    if ($json_body && isset($json_body[$campo])) $mac_detectadas[$campo]['json'] = $json_body[$campo];
}

// HTML de salida
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>DEBUG Captive Portal</title>
    <style>
        body { font-family: monospace; background: #111; color: #eee; padding: 20px; }
        h1 { margin-bottom: 10px; }
        pre { background: #222; padding: 15px; border-radius: 8px; overflow-x: auto; }
        .box { margin-bottom: 20px; }
        .ok { color: #0f0; }
        .warn { color: #ff0; }
        .err { color: #f33; }
        code { color: #9cf; }
    </style>
</head>
<body>
    <h1>📦 DEBUG Captive Portal (TP-Link / Omada / Aruba style)</h1>
    <p>Hora del servidor: <strong><?php echo htmlspecialchars($now); ?></strong></p>
    <p>IP que hizo la petición: <strong><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? ''); ?></strong></p>
    <p>Archivo log: <code>/tmp/captive_dump.log</code> (dentro del contenedor)</p>

    <div class="box">
        <h2>🔎 REQUEST LINE</h2>
        <pre><?php echo htmlspecialchars(($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '/')); ?></pre>
    </div>

    <div class="box">
        <h2>🟦 GET ($_GET)</h2>
        <pre><?php echo htmlspecialchars(print_r($_GET, true)); ?></pre>
    </div>

    <div class="box">
        <h2>🟧 POST ($_POST)</h2>
        <pre><?php echo htmlspecialchars(print_r($_POST, true)); ?></pre>
    </div>

    <div class="box">
        <h2>📦 HEADERS</h2>
        <pre><?php echo htmlspecialchars(print_r($headers, true)); ?></pre>
    </div>

    <div class="box">
        <h2>📝 RAW BODY (php://input)</h2>
        <pre><?php echo htmlspecialchars($raw_body === '' ? '(vacío)' : $raw_body); ?></pre>
    </div>

    <?php if ($json_body): ?>
    <div class="box">
        <h2>📄 RAW BODY (JSON decodificado)</h2>
        <pre><?php echo htmlspecialchars(print_r($json_body, true)); ?></pre>
    </div>
    <?php endif; ?>

    <div class="box">
        <h2>📁 FILES ($_FILES)</h2>
        <pre><?php echo htmlspecialchars(print_r($_FILES, true)); ?></pre>
    </div>

    <div class="box">
        <h2>🧠 SESSION ($_SESSION)</h2>
        <pre><?php echo htmlspecialchars(print_r($_SESSION, true)); ?></pre>
    </div>

    <div class="box">
        <h2>📡 Posibles campos de MAC detectados</h2>
        <?php if (empty($mac_detectadas)): ?>
            <p class="err">❌ No llegó ningún campo típico de MAC (ni cliente ni AP).</p>
            <p>👉 Revisa la config del TP-Link / Omada: a qué URL está llamando y qué variables expone.</p>
        <?php else: ?>
            <pre><?php echo htmlspecialchars(print_r($mac_detectadas, true)); ?></pre>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>ℹ️ SERVER ($_SERVER)</h2>
        <pre><?php echo htmlspecialchars(print_r($_SERVER, true)); ?></pre>
    </div>

    <div class="box">
        <h2>📌 Siguiente paso</h2>
        <p>Con esto ya vemos **exactamente** cómo tu TP-Link está llamando al portal. Luego en tu <code>principal.php</code> solo tienes que usar esos nombres reales.</p>
        <p>Ejemplo típico:</p>
        <pre>
$mac_cliente = $_GET['clientMac'] ?? $_GET['mac'] ?? '';
$ap_mac      = $_GET['apMac']     ?? $_GET['ap_mac'] ?? '';
$ap_ip       = $_GET['target']    ?? $_GET['ap_ip']  ?? '';
        </pre>
    </div>
</body>
</html>
