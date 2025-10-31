<?php
// admin_zonas.php
session_start();

/* ========= LOGIN MUY SIMPLE ========= */
$ADMIN_USER = 'admin';
$ADMIN_PASS = 'g0net123';

if (!isset($_SESSION['admin_ok'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user'], $_POST['pass'])) {
        if ($_POST['user'] === $ADMIN_USER && $_POST['pass'] === $ADMIN_PASS) {
            $_SESSION['admin_ok'] = true;
            header("Location: admin_zonas.php");
            exit;
        } else {
            $error_login = "Credenciales inválidas";
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Login GoNet Zonas</title>
        <style>
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                background: #e9edf5;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
            }
            .login-wrapper {
                background: #fff;
                width: 100%;
                max-width: 380px;
                border-radius: 16px;
                box-shadow: 0 10px 35px rgba(15, 23, 42, 0.12);
                padding: 36px 34px 32px;
                text-align: center;
            }
            .login-title { font-size: 28px; font-weight: 700; margin-bottom: 6px; color: #1f2937; }
            .login-sub { font-size: 13px; color: #6b7280; margin-bottom: 24px; }
            .input-group { position: relative; margin-bottom: 16px; }
            .input-group input {
                width: 100%;
                border: 1px solid #d1d5db;
                border-radius: 10px;
                padding: 10px 10px 10px 38px;
                font-size: 14px;
                outline: none;
                transition: 0.2s;
            }
            .input-group input:focus {
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
            }
            .input-group .icon {
                position: absolute;
                top: 50%;
                left: 14px;
                transform: translateY(-50%);
                font-size: 15px;
                color: #9ca3af;
            }
            .btn-login {
                width: 100%;
                border: none;
                border-radius: 10px;
                padding: 10px 0;
                font-size: 15px;
                font-weight: 600;
                color: #fff;
                background: linear-gradient(135deg, #2563eb, #3b82f6);
                cursor: pointer;
            }
            .err {
                background: #fee2e2;
                color: #b91c1c;
                border-radius: 8px;
                padding: 6px 10px;
                font-size: 13px;
                margin-bottom: 12px;
                text-align: left;
            }
            .login-footer { margin-top: 18px; font-size: 12.5px; color: #94a3b8; }
        </style>
    </head>
    <body>
        <div class="login-wrapper">
            <div class="login-title">Login</div>
            <div class="login-sub">Hey, ingresa tus datos para administrar las zonas 👋</div>
            <?php if (!empty($error_login)): ?>
                <div class="err"><?php echo htmlspecialchars($error_login); ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="input-group">
                    <span class="icon">👤</span>
                    <input type="text" name="user" placeholder="Usuario" required>
                </div>
                <div class="input-group">
                    <span class="icon">🔒</span>
                    <input type="password" name="pass" placeholder="Contraseña" required>
                </div>
                <button type="submit" class="btn-login">Entrar</button>
            </form>
            <div class="login-footer">GoNet Wi-Fi · panel de marketing</div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* ========= DB ========= */
$host = "mysql";
$user = "radius";
$pass = "radpass";
$db   = "radius";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    http_response_code(500);
    echo "DB error: " . htmlspecialchars($e->getMessage());
    exit;
}

/* ========= API ========= */
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // LIST
    if ($action === 'list') {
        $zonas = [];
        $res = $conn->query("SELECT id, codigo, nombre, descripcion, banner_url FROM wifi_zonas ORDER BY nombre");
        while ($row = $res->fetch_assoc()) { $zonas[] = $row; }

        $aps = [];
        $res2 = $conn->query("SELECT a.ap_mac, a.zona_codigo FROM wifi_zona_aps a");
        while ($row2 = $res2->fetch_assoc()) { $aps[] = $row2; }

        $no_asignados = [];
        $res3 = $conn->query("
            SELECT DISTINCT c.ap_mac 
            FROM clients c
            WHERE c.ap_mac IS NOT NULL AND c.ap_mac <> '' 
              AND c.ap_mac NOT IN (SELECT ap_mac FROM wifi_zona_aps)
        ");
        while ($row3 = $res3->fetch_assoc()) { $no_asignados[] = $row3['ap_mac']; }

        header('Content-Type: application/json');
        echo json_encode([
            'zonas' => $zonas,
            'aps'   => $aps,
            'no_asignados' => $no_asignados
        ]);
        exit;
    }

    // CREATE ZONE
    if ($action === 'create_zone' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $codigo = trim($_POST['codigo'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $desc   = trim($_POST['descripcion'] ?? '');

        if ($codigo === '' || $nombre === '') {
            http_response_code(400);
            echo "Código y nombre son obligatorios";
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO wifi_zonas (codigo, nombre, descripcion) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $codigo, $nombre, $desc);
        $stmt->execute();
        echo "OK";
        exit;
    }

    // ASSIGN AP
    if ($action === 'assign_ap' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ap_mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $_POST['ap_mac'] ?? ''));
        $zona   = $_POST['zona'] ?? '';

        if ($ap_mac === '' || $zona === '') {
            http_response_code(400);
            echo "Faltan datos";
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO wifi_zona_aps (ap_mac, zona_codigo) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE zona_codigo = VALUES(zona_codigo)");
        $stmt->bind_param("ss", $ap_mac, $zona);
        $stmt->execute();
        echo "OK";
        exit;
    }

    // ADD AP MANUAL
    if ($action === 'add_ap' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ap_mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $_POST['ap_mac'] ?? ''));
        $zona   = $_POST['zona'] ?? '';

        if ($ap_mac === '' || $zona === '') {
            http_response_code(400);
            echo "Faltan datos";
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO wifi_zona_aps (ap_mac, zona_codigo) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE zona_codigo = VALUES(zona_codigo)");
        $stmt->bind_param("ss", $ap_mac, $zona);
        $stmt->execute();
        echo "OK";
        exit;
    }

    // UPLOAD BANNER
    if ($action === 'upload_banner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $zona = $_POST['zona'] ?? '';
        if ($zona === '') {
            http_response_code(400);
            echo "Zona requerida";
            exit;
        }

        if (!isset($_FILES['banner']) || $_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo "Error al subir archivo";
            exit;
        }

        $uploadDir = __DIR__ . '/banners';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

        $ext = strtolower(pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png','jpg','jpeg','gif','webp'])) {
            http_response_code(400);
            echo "Formato no permitido";
            exit;
        }

        $filename    = 'banner_' . $zona . '.' . $ext;
        $destination = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($_FILES['banner']['tmp_name'], $destination)) {
            http_response_code(500);
            echo "No se pudo mover el archivo";
            exit;
        }

        $publicPath = 'banners/' . $filename;
        $stmt = $conn->prepare("UPDATE wifi_zonas SET banner_url = ? WHERE codigo = ?");
        $stmt->bind_param("ss", $publicPath, $zona);
        $stmt->execute();

        echo "OK";
        exit;
    }

    http_response_code(400);
    echo "Acción no válida";
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Admin Zonas Wi-Fi</title>
<style>
    :root {
        --bg: #eef1f9;
        --sidebar: #fff;
        --card: #fff;
        --primary: #3b82f6;
        --text: #1f2937;
        --muted: #6b7280;
        --radius: 18px;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        background: var(--bg);
        display: flex;
        min-height: 100vh;
        color: var(--text);
    }
    .sidebar {
        width: 230px;
        background: var(--sidebar);
        padding: 24px 20px;
        display: flex;
        flex-direction: column;
        gap: 24px;
        box-shadow: 4px 0 18px rgba(15,23,42,0.05);
        position: sticky;
        top: 0;
        height: 100vh;
    }
    .brand { display:flex; gap:10px; align-items:center; }
    .brand-logo {
        width: 36px;height:36px;
        background: linear-gradient(135deg, #2563eb, #3b82f6);
        border-radius: 14px;
        display:flex;align-items:center;justify-content:center;
        color:#fff;font-weight:600;
    }
    .brand-name { font-weight: 700; font-size: 14.5px; }
    .menu { display:flex;flex-direction:column;gap:8px; }
    .menu a {
        padding:8px 10px;
        border-radius: 12px;
        text-decoration:none;
        color:#475569;
        font-size:13px;
        display:flex;align-items:center;gap:8px;
    }
    .menu a.active,.menu a:hover { background:rgba(59,130,246,.12);color:#1d4ed8; }
    .profile-box {
        margin-top:auto;
        background:rgba(59,130,246,.08);
        border-radius:14px;
        padding:10px;
        display:flex;align-items:center;gap:8px;
    }
    .profile-avatar {
        width:30px;height:30px;border-radius:12px;background:#fff;
        display:flex;align-items:center;justify-content:center;color:#2563eb;font-weight:700;
    }

    .main {
        flex:1;
        padding: 20px 26px 26px;
        display:flex;
        flex-direction:column;
        gap:18px;
    }
    .topbar {
        display:flex;justify-content:space-between;align-items:center;
    }
    .page-title { font-size:22px;font-weight:700; }
    .small-muted { font-size:12px;color:#94a3b8; }
    .top-actions { display:flex;gap:10px;align-items:center; }
    .badge-status {
        background:rgba(34,197,94,.12);
        color:#15803d;
        padding:5px 8px;
        border-radius:8px;
        font-size:11px;
        font-weight:600;
    }
    .btn-logout { background:#ef4444;border:none;color:#fff;padding:6px 12px;border-radius:10px;cursor:pointer;font-size:12px; }

    .cards { display:flex;gap:16px;flex-wrap:wrap; }
    .card-mini {
        background:var(--card);
        border-radius:18px;
        padding:12px 14px 12px;
        flex:1;
        min-width:160px;
        box-shadow:0 12px 26px rgba(15,23,42,0.03);
    }
    .card-mini-title { font-size:11.5px;color:#94a3b8;margin-bottom:6px; }
    .card-mini-value { font-size:24px;font-weight:700; }
    .card-mini-foot { font-size:11px;color:#22c55e;margin-top:4px; }

    .content-grid {
        display:grid;
        grid-template-columns: 2.5fr 1fr;
        gap:18px;
        align-items:flex-start;
    }
    .panel {
        background:var(--card);
        border-radius:18px;
        padding:14px 14px 10px;
        box-shadow:0 12px 26px rgba(15,23,42,0.03);
    }
    .panel-title { display:flex;justify-content:space-between;align-items:center;margin-bottom:10px; }
    .panel-title h2 { font-size:14.3px;margin:0; }
    .zona-block h3 { margin: 2px 0 8px; font-size:13px; }
    .zona-drop {
        min-height:115px;
        border:2px dashed #dbe2ff;
        border-radius:14px;
        padding:10px;
        margin-bottom:14px;
        background:rgba(245,246,255,.6);
        transition:.2s;
    }
    .zona-drop.over { border-color:#2563eb;background:rgba(59,130,246,.13); }
    .ap-pill {
        display:inline-flex;align-items:center;
        background:#2563eb;color:#fff;
        padding:3px 10px 4px;
        border-radius:999px;
        margin:3px 3px;
        font-size:11.5px;
        cursor:grab;
    }
    #noAsignados { display:flex;flex-wrap:wrap; }

    .forms-row {
        display:flex;
        gap:18px;
        margin-top:4px;
    }
    .form-card {
        background:var(--card);
        border-radius:18px;
        padding:14px 14px 10px;
        box-shadow:0 12px 26px rgba(15,23,42,0.03);
        flex:1;
        min-width:230px;
    }
    .form-card h3 { margin:0 0 8px;font-size:14px; }
    .form-card label { font-size:12.5px;color:#475569; }
    .form-card input[type=text],
    .form-card textarea,
    .form-card select,
    .form-card input[type=file] {
        width:100%;
        padding:6px 8px;
        border-radius:10px;
        border:1px solid #d1d5db;
        font-size:13px;
        margin-bottom:8px;
    }
    .form-card button {
        padding:6px 10px;
        background:#2563eb;
        border:none;
        color:#fff;
        border-radius:10px;
        cursor:pointer;
        font-size:12.5px;
    }
    .small { font-size:0.75rem; color:#6b7280; }
    img.banner-preview { max-width:140px;border-radius:10px;margin-bottom:5px; }

    @media (max-width: 1100px) {
        .content-grid { grid-template-columns: 1fr; }
        .sidebar { display:none; }
        .main { padding: 18px 14px 50px; }
        .forms-row { flex-direction: column; }
    }
</style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-logo">G</div>
            <div class="brand-name">GoNet Wi-Fi<br><span style="font-size:11px;color:#94a3b8;">Marketing Zones</span></div>
        </div>
        <div class="menu">
            <a href="#" class="active">📊 Panel</a>
            <a href="#">📁 Zonas</a>
            <a href="#">📡 APs</a>
            <a href="#">🖼 Banners</a>
        </div>
        <div class="profile-box">
            <div class="profile-avatar">A</div>
            <div>
                <div style="font-size:12.5px;font-weight:600;">Admin</div>
                <small style="font-size:11px;color:#64748b;">online</small>
            </div>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <div class="page-title">Dashboard de Zonas</div>
                <div class="small-muted">Arrastra APs a cada zona para mostrar promos diferentes 🚀</div>
            </div>
            <div class="top-actions">
                <span class="badge-status" id="liveCount">Sincronizado</span>
                <form method="post" action="admin_zonas_logout.php" style="margin:0;">
                    <button class="btn-logout" type="submit">Salir</button>
                </form>
            </div>
        </div>

        <div class="cards">
            <div class="card-mini">
                <div class="card-mini-title">Zonas activas</div>
                <div class="card-mini-value" id="cardZonas">0</div>
                <div class="card-mini-foot">Zonas con banners o APs</div>
            </div>
            <div class="card-mini">
                <div class="card-mini-title">APs asignados</div>
                <div class="card-mini-value" id="cardAps">0</div>
                <div class="card-mini-foot">Con zona definida</div>
            </div>
            <div class="card-mini">
                <div class="card-mini-title">APs sin zona</div>
                <div class="card-mini-value" id="cardNoAps">0</div>
                <div class="card-mini-foot" style="color:#f97316;">Arrástralos ➡</div>
            </div>
        </div>

        <div class="content-grid">
            <div class="panel" id="panelZonas">
                <div class="panel-title">
                    <h2>Zonas Wi-Fi</h2>
                    <span class="small">Drag & Drop de APs</span>
                </div>
                <div id="zonasContainer"></div>
            </div>
            <div class="panel">
                <div class="panel-title">
                    <h2>APs sin zona</h2>
                    <span class="small">Detectados en clients</span>
                </div>
                <div id="noAsignados"></div>
                <p class="small">Tip: Si el AP cambia IP no pasa nada, lo ubicamos por MAC.</p>
            </div>
        </div>

        <div class="forms-row">
            <div class="form-card">
                <h3>Crear zona</h3>
                <form id="formZona">
                    <label>Código (sin espacios)</label>
                    <input type="text" name="codigo" required placeholder="STADIUM01">
                    <label>Nombre</label>
                    <input type="text" name="nombre" required placeholder="Zona Estadio">
                    <label>Descripción</label>
                    <textarea name="descripcion" rows="3" placeholder="Promos fútbol, solo sábados"></textarea>
                    <button type="submit">Guardar zona</button>
                </form>
            </div>
            <div class="form-card">
                <h3>Asignar AP manual</h3>
                <form id="formAP">
                    <label>MAC del AP</label>
                    <input type="text" name="ap_mac" placeholder="FCECDA123456 o FC:EC:DA..." required>
                    <label>Zona</label>
                    <select name="zona" id="selectZona"></select>
                    <button type="submit">Asignar</button>
                </form>
            </div>
            <div class="form-card">
                <h3>Subir banner</h3>
                <form id="formBanner" enctype="multipart/form-data" method="post">
                    <label>Zona</label>
                    <select name="zona" id="selectZonaBanner"></select>
                    <label>Archivo</label>
                    <input type="file" name="banner" accept="image/*" required>
                    <button type="submit">Subir</button>
                </form>
                <p class="small">Se guarda en <code>wifi_zonas.banner_url</code></p>
            </div>
        </div>
    </main>

<script>
// helper para ver los datos que mandamos
function debugFormData(fd, label = 'FormData') {
    console.group(label);
    for (const [k, v] of fd.entries()) {
        console.log(k, v);
    }
    console.groupEnd();
}

async function loadData() {
    console.log("🟦 loadData() -> admin_zonas.php?action=list");
    const res = await fetch('admin_zonas.php?action=list');
    console.log("⬅️ loadData() status:", res.status);
    if (!res.ok) {
        const txt = await res.text();
        console.error("❌ Error al cargar info:", txt);
        alert("No se pudo cargar info");
        return;
    }
    const data = await res.json();
    console.log("✅ loadData() data:", data);
    renderZonas(data.zonas, data.aps);
    renderNoAsignados(data.no_asignados);
    fillSelects(data.zonas);

    document.getElementById('cardZonas').textContent = data.zonas.length;
    document.getElementById('cardAps').textContent = data.aps.length;
    document.getElementById('cardNoAps').textContent = data.no_asignados.length;
    document.getElementById('liveCount').textContent = "Actualizado " + new Date().toLocaleTimeString();
}

function renderZonas(zonas, aps) {
    const cont = document.getElementById('zonasContainer');
    cont.innerHTML = '';

    zonas.forEach(z => {
        const div = document.createElement('div');
        div.className = 'zona-block';
        div.innerHTML = `
            <h3>${z.nombre} <span style="font-size:0.7rem;color:#94a3b8">(${z.codigo})</span></h3>
            ${z.banner_url ? `<img src="${z.banner_url}" class="banner-preview">` : ''}
            <div class="zona-drop" data-zona="${z.codigo}"></div>
            <p class="small">${z.descripcion ? z.descripcion : 'Sin descripción'}</p>
        `;
        cont.appendChild(div);
    });

    aps.forEach(a => {
        const zonaDiv = cont.querySelector('.zona-drop[data-zona="'+a.zona_codigo+'"]');
        if (zonaDiv) {
            zonaDiv.appendChild(makeApPill(a.ap_mac));
        }
    });

    document.querySelectorAll('.zona-drop').forEach(z => {
        z.addEventListener('dragover', e => {
            e.preventDefault();
            z.classList.add('over');
        });
        z.addEventListener('dragleave', () => z.classList.remove('over'));
        z.addEventListener('drop', async e => {
            e.preventDefault();
            z.classList.remove('over');
            const ap_mac = e.dataTransfer.getData('text/plain');
            if (!ap_mac) return;
            console.log("📦 drop AP", ap_mac, "→ zona", z.dataset.zona);
            await assignAP(ap_mac, z.dataset.zona);
            loadData();
        });
    });
}

function renderNoAsignados(list) {
    const cont = document.getElementById('noAsignados');
    cont.innerHTML = '';
    list.forEach(mac => { cont.appendChild(makeApPill(mac)); });
}

function makeApPill(mac) {
    const span = document.createElement('span');
    span.className = 'ap-pill';
    span.textContent = mac;
    span.draggable = true;
    span.addEventListener('dragstart', e => {
        e.dataTransfer.setData('text/plain', mac);
    });
    return span;
}

function fillSelects(zonas) {
    const sel1 = document.getElementById('selectZona');
    const sel2 = document.getElementById('selectZonaBanner');
    sel1.innerHTML = '';
    sel2.innerHTML = '';
    zonas.forEach(z => {
        const opt1 = document.createElement('option');
        opt1.value = z.codigo;
        opt1.textContent = z.nombre;
        sel1.appendChild(opt1);

        const opt2 = document.createElement('option');
        opt2.value = z.codigo;
        opt2.textContent = z.nombre;
        sel2.appendChild(opt2);
    });
}

async function assignAP(ap_mac, zona) {
    const fd = new FormData();
    fd.append('ap_mac', ap_mac);
    fd.append('zona', zona);
    console.log("🟦 assignAP() →", ap_mac, zona);
    const res = await fetch('admin_zonas.php?action=assign_ap', {
        method: 'POST',
        body: fd
    });
    console.log("⬅️ assignAP() status:", res.status);
    if (!res.ok) {
        console.error("❌ Error asignando AP:", await res.text());
    }
}

document.getElementById('formZona').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    debugFormData(fd, "📄 formZona");
    const res = await fetch('admin_zonas.php?action=create_zone', {
        method: 'POST',
        body: fd
    });
    console.log("⬅️ create_zone status:", res.status);
    const txt = await res.text();
    console.log("create_zone response:", txt);
    if (res.ok) {
        e.target.reset();
        loadData();
    } else {
        console.error("❌ Error creando zona:", txt);
        alert(txt);
    }
});

document.getElementById('formAP').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    debugFormData(fd, "📡 formAP");
    const res = await fetch('admin_zonas.php?action=add_ap', {
        method: 'POST',
        body: fd
    });
    console.log("⬅️ add_ap status:", res.status);
    const txt = await res.text();
    console.log("add_ap response:", txt);
    if (res.ok) {
        e.target.reset();
        loadData();
    } else {
        console.error("❌ Error asignando AP:", txt);
        alert(txt);
    }
});

document.getElementById('formBanner').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    debugFormData(fd, "🖼 formBanner (antes de subir)");
    try {
        const res = await fetch('admin_zonas.php?action=upload_banner', {
            method: 'POST',
            body: fd
        });
        console.log("⬅️ upload_banner status:", res.status);
        const txt = await res.text();
        console.log("upload_banner raw response:", txt);

        if (res.ok) {
            alert("Banner subido ✅");
            e.target.reset();
            loadData();
        } else {
            console.error("❌ Error al subir banner:", txt);
            alert(txt);
        }
    } catch (err) {
        console.error("❌ fetch upload_banner lanzó excepción:", err);
        alert("Error de red subiendo banner");
    }
});

loadData();
</script>
</body>
</html>
