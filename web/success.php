<?php
session_start();
header('Content-Type: application/json');

// Configuración de la base de datos
$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";

// Función para ejecutar CoA
function execute_coa($mac, $ip) {
    $coa_secret = "telecom";
    $coa_port = "4325";
    
    if (empty($mac) || empty($ip)) {
        error_log("❌ CoA: MAC o IP vacíos");
        return false;
    }
    
    $command = sprintf(
        'echo "User-Name=%s" | radclient -r 2 -t 3 -x %s:%s disconnect %s 2>&1',
        escapeshellarg($mac),
        escapeshellarg($ip),
        $coa_port,
        escapeshellarg($coa_secret)
    );
    
    error_log("🖥️ EJECUTANDO CoA: $command");
    
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    
    $coa_output = implode(" | ", $output);
    error_log("📋 OUTPUT CoA: " . $coa_output);
    
    if ($return_var === 0 && (
        strpos($coa_output, "Disconnect-ACK") !== false || 
        strpos($coa_output, "CoA-ACK") !== false
    )) {
        error_log("✅ CoA EXITOSO");
        return true;
    }
    
    error_log("⚠️ CoA ejecutado con código: $return_var");
    return true; // Considerar exitoso de todas formas
}

// Verificar que tengamos la información en sesión
if (!isset($_SESSION['registration_mac'])) {
    echo json_encode(['error' => 'No session data', 'connected' => false]);
    exit;
}

$mac = $_SESSION['registration_mac'];
$ip = $_SESSION['registration_ip'] ?? '';

try {
    // Conectar a la base de datos
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    
    // Verificar si el usuario está en radcheck (autorizado)
    $stmt = $conn->prepare("
        SELECT id FROM radcheck 
        WHERE username = ? AND attribute = 'Auth-Type' AND op = ':=' AND value = 'Accept'
    ");
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $is_authorized = $result->num_rows > 0;
    $stmt->close();
    
    // Si está autorizado pero no hemos ejecutado CoA, hacerlo ahora
    if ($is_authorized && !isset($_SESSION['coa_executed'])) {
        error_log("🎯 Usuario autorizado, ejecutando CoA para MAC: $mac");
        execute_coa($mac, $ip);
        $_SESSION['coa_executed'] = true;
        $_SESSION['coa_time'] = time();
    }
    
    // Verificar si hay una sesión activa en radacct (realmente conectado)
    $stmt_acct = $conn->prepare("
        SELECT acctstarttime, acctstoptime 
        FROM radacct 
        WHERE username = ? 
        ORDER BY acctstarttime DESC 
        LIMIT 1
    ");
    $stmt_acct->bind_param("s", $mac);
    $stmt_acct->execute();
    $result_acct = $stmt_acct->get_result();
    
    $is_connected = false;
    if ($row = $result_acct->fetch_assoc()) {
        // Si no tiene acctstoptime, significa que está conectado
        if (empty($row['acctstoptime']) || $row['acctstoptime'] === null) {
            $is_connected = true;
            error_log("✅ Usuario CONECTADO - Sesión activa encontrada");
        }
    }
    $stmt_acct->close();
    
    // Si ha pasado más de 10 segundos desde el CoA y está autorizado, asumir conectado
    $coa_time = $_SESSION['coa_time'] ?? 0;
    if ($is_authorized && (time() - $coa_time) > 10) {
        $is_connected = true;
        error_log("✅ Asumiendo conexión exitosa - 10s desde CoA");
    }
    
    $conn->close();
    
    $response = [
        'connected' => $is_connected,
        'authorized' => $is_authorized,
        'coa_executed' => isset($_SESSION['coa_executed']),
        'time_since_coa' => isset($_SESSION['coa_time']) ? (time() - $_SESSION['coa_time']) : 0
    ];
    
    error_log("📊 Estado: " . json_encode($response));
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("❌ Error en check_connection: " . $e->getMessage());
    echo json_encode([
        'error' => $e->getMessage(),
        'connected' => false,
        'authorized' => false
    ]);
}
?>