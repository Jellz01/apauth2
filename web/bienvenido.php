<?php
// bienvenido.php — Loading + CoA async + check de Internet (minimal)
session_start();
ini_set('display_errors',1); error_reporting(E_ALL);

// === CoA config ===
$coa_secret     = 'telecom';
$coa_port       = 3799;            // estándar RFC5176
$default_ap_ip  = '192.168.0.9';   // fallback si no conseguimos NAS-IP de radacct
$log_file       = '/tmp/coa_async.log';

function logx($m){ global $log_file; file_put_contents($log_file,"[".date('Y-m-d H:i:s')."] $m\n",FILE_APPEND); }

// Datos de sesión (puestos por la página de registro)
$mac_raw = $_SESSION['registration_mac'] ?? '';
$ip_cli  = $_SESSION['registration_ip']  ?? '';

// Normalizar MAC y variantes típicas de radacct
$mac_clean = strtoupper(preg_replace('/[^A-Fa-f0-9]/','',$mac_raw));
$mac_variants = [
  implode(':',str_split($mac_clean,2)),
  implode('-',str_split($mac_clean,2)),
  $mac_clean,
  strtolower(implode(':',str_split($mac_clean,2))),
  strtolower($mac_clean)
];

// Busca sesión activa en radacct para mandar CoA con identificadores correctos
$session = null;
if ($mac_raw) {
  try {
    $conn = new mysqli('mysql','radius','radpass','radius');
    $list = implode("','", array_map([$conn,'real_escape_string'],$mac_variants));
    $sql = "SELECT acctsessionid, callingstationid, nasipaddress, username, framedipaddress
            FROM radacct
            WHERE acctstoptime IS NULL
              AND callingstationid IN ('$list')
            ORDER BY acctstarttime DESC
            LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows>0) $session = $res->fetch_assoc();
    $conn->close();
  } catch(Exception $e){ logx("MySQL err: ".$e->getMessage()); }
}

// Lanza CoA en background (no bloquea la UI)
function start_coa_bg($nas_ip,$port,$secret,$attrs){
  $tmp = tempnam(sys_get_temp_dir(),'coa_');
  file_put_contents($tmp,$attrs);
  $cmd = sprintf(
    "sh -c 'radclient -r 2 -t 3 -x %s:%d disconnect %s < %s >> %s 2>&1 &'",
    escapeshellarg($nas_ip), $port, escapeshellarg($secret), escapeshellarg($tmp), escapeshellarg('/tmp/coa_async.log')
  );
  logx("CoA BG -> $nas_ip:$port\n$attrs\nCMD: $cmd");
  exec($cmd); // no bloquea
}

// Construye atributos y envía
if ($session){
  // Con datos reales de la sesión
  $attrs  = 'Acct-Session-Id = "'.$session['acctsessionid']."\"\n";
  $attrs .= 'Calling-Station-Id = "'.$session['callingstationid']."\"\n";
  $attrs .= 'NAS-IP-Address = '.$session['nasipaddress']."\n";
  if (!empty($session['username']))        $attrs .= 'User-Name = "'.$session['username']."\"\n";
  if (!empty($session['framedipaddress'])) $attrs .= 'Framed-IP-Address = '.$session['framedipaddress']."\n";
  start_coa_bg($session['nasipaddress'],$coa_port,$coa_secret,$attrs);
} else if ($mac_raw){
  // Sin radacct aún: intentamos con MAC + (opcional) IP y NAS fallback
  $nas_ip = $_GET['ap_ip'] ?? $_POST['ap_ip'] ?? $default_ap_ip;
  $attrs  = 'Calling-Station-Id = "'.implode(':',str_split($mac_clean,2))."\"\n";
  if ($ip_cli) $attrs .= "Framed-IP-Address = $ip_cli\n";
  $attrs .= "NAS-IP-Address = $nas_ip\n";
  start_coa_bg($nas_ip,$coa_port,$coa_secret,$attrs);
}

// Marcamos bandera por si quieres usarla luego
$_SESSION['coa_executed'] = true;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>GoNet · Conectando…</title>
<style>
  :root { --bg1:#667eea; --bg2:#764ba2; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body {
    min-height:100vh; display:grid; place-items:center;
    background:linear-gradient(135deg, var(--bg1) 0%, var(--bg2) 100%);
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    color:#111;
  }
  .card {
    background:#fff; width:min(92vw,460px);
    padding:36px 28px; border-radius:18px;
    box-shadow:0 18px 55px rgba(0,0,0,.18); text-align:center;
  }
  .spinner {
    width:64px; height:64px; border:6px solid #eef3ff;
    border-top:6px solid #667eea; border-radius:50%;
    margin:4px auto 18px; animation:spin 1s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg) } }
  h1 { font-size:1.25rem; font-weight:700; }
  p  { margin-top:8px; color:#555; }
  .ok { display:none; font-size:1.3rem; font-weight:800; color:#2e7d32; margin-top:6px; }
  .btn { display:none; margin-top:16px; background:#667eea; color:#fff;
         padding:10px 16px; border-radius:10px; text-decoration:none; font-weight:600; }
  .logo { width:180px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.18); margin-bottom:16px; }
</style>
</head>
<body>
  <div class="card">
    <img class="logo" src="gonetlogo.png" alt="GoNet" />
    <div class="spinner" id="sp"></div>
    <h1 id="title">Conectando…</h1>
    <p id="sub">Estamos habilitando tu acceso. Esto tomará unos segundos.</p>
    <div class="ok" id="ok">Bienvenidos a la red GoNet</div>
    <a class="btn" id="go" href="success.php">Continuar</a>
  </div>

<script>
// Comprobación simple de Internet: intentamos cargar un favicon público.
// Si carga (onload) asumimos salida a Internet ok.
const probes = [
  'https://www.google.com/favicon.ico',
  'https://www.cloudflare.com/favicon.ico',
  'https://www.bing.com/favicon.ico'
];

let i = 0, ready = false;
function checkOnce(){
  const img = new Image();
  img.onload = () => {
    if (ready) return;
    ready = true;
    // UI: mostrar mensaje de bienvenida y botón / redirigir
    document.getElementById('sp').style.display = 'none';
    document.getElementById('title').style.display = 'none';
    document.getElementById('sub').style.display = 'none';
    document.getElementById('ok').style.display = 'block';
    const go = document.getElementById('go');
    go.style.display = 'inline-block';
    // Redirección automática tras 1.2 s (opcional)
    setTimeout(()=>{ window.location.href = 'success.php'; }, 1200);
  };
  img.onerror = () => { /* reintentar luego */ };
  img.src = probes[i % probes.length] + '?_=' + Date.now();
  i++;
}

checkOnce();
const t = setInterval(()=>{
  if (!ready) checkOnce();
  else clearInterval(t);
}, 1200);
</script>
</body>
</html>
