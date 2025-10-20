<?php
// debug_headers.php
// Shows client IPs, request headers, GET/POST params, raw body and searches for MAC-like values.

// --- Helper: safely print arrays
function pretty($v) {
    return '<pre style="white-space:pre-wrap;word-break:break-word;">' . htmlspecialchars(print_r($v, true)) . '</pre>';
}

// --- Collect candidate client IPs (trust but verify)
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '(none)';
$forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? null;

// Normalize X-Forwarded-For (may contain comma list)
if ($forwardedFor) {
    // take first (original) and last (closest proxy) as examples
    $xffList = array_map('trim', explode(',', $forwardedFor));
    $xffFirst = $xffList[0] ?? null;
    $xffLast  = end($xffList);
} else {
    $xffList = [];
    $xffFirst = $xffLast = null;
}

// --- All SERVER variables
$serverVars = $_SERVER;

// --- All headers (getallheaders exists on Apache/IIS; fallback for others)
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    // fallback: build from $_SERVER
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $headers[$name] = $v;
        } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
            $headers[str_replace('_', '-', ucwords(strtolower($k)))] = $v;
        }
    }
}

// --- GET and POST
$get = $_GET;
$post = $_POST;

// --- Raw request body (useful for JSON or forwarded query)
$rawBody = file_get_contents('php://input');

// --- Full request URI and query string
$uri = ($_SERVER['REQUEST_URI'] ?? '(unknown)');
$qs  = ($_SERVER['QUERY_STRING'] ?? '');

// --- Search for MAC-like patterns in headers, GET, POST, and body
$macPattern = '/(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}|[0-9A-Fa-f]{12}/';
$found = [];

// Helper to search arrays/strings
function search_for_mac($source, $label, $pattern, &$found) {
    if (is_array($source)) {
        foreach ($source as $k => $v) {
            if (is_string($v) && preg_match_all($pattern, $v, $m)) {
                foreach ($m[0] as $mac) $found[] = ["where" => "$label.$k", "value" => $mac];
            }
        }
    } elseif (is_string($source) && preg_match_all($pattern, $source, $m)) {
        foreach ($m[0] as $mac) $found[] = ["where" => $label, "value" => $mac];
    }
}

search_for_mac($headers, 'Header', $macPattern, $found);
search_for_mac($get, 'GET', $macPattern, $found);
search_for_mac($post, 'POST', $macPattern, $found);
search_for_mac($rawBody, 'Body', $macPattern, $found);
search_for_mac($qs, 'QueryString', $macPattern, $found);

// --- Optionally log to file (uncomment to enable)
// $logLine = sprintf("[%s] REMOTE=%s XFF=%s URI=%s FOUND=%s\n", date('c'), $remoteAddr, $forwardedFor, $uri, json_encode($found));
// file_put_contents(__DIR__ . '/debug_headers.log', $logLine, FILE_APPEND);

// --- Output
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Debug: Request Info</title>
  <style>
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; margin: 20px; line-height:1.4; }
    h1 { margin-bottom: 6px; }
    .box { border:1px solid #ddd; padding:10px; margin-bottom:12px; border-radius:6px; background:#fafafa; }
    .warn { color: #a33; font-weight:600; }
  </style>
</head>
<body>
  <h1>Request debug</h1>

  <div class="box">
    <strong>Client addresses</strong>
    <div>REMOTE_ADDR: <code><?= htmlspecialchars($remoteAddr) ?></code></div>
    <div>X-Forwarded-For / Client IP header: <code><?= htmlspecialchars($forwardedFor ?? '(none)') ?></code></div>
    <div>First XFF (likely original client): <code><?= htmlspecialchars($xffFirst ?? '(none)') ?></code></div>
    <div>Last XFF (closest proxy): <code><?= htmlspecialchars($xffLast ?? '(none)') ?></code></div>
  </div>

  <div class="box">
    <strong>Request line</strong>
    <div>URI: <code><?= htmlspecialchars($uri) ?></code></div>
    <div>Query string: <code><?= htmlspecialchars($qs) ?></code></div>
    <div>Method: <code><?= htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? '(unknown)') ?></code></div>
  </div>

  <div class="box">
    <strong>Headers</strong>
    <?= pretty($headers) ?>
  </div>

  <div class="box">
    <strong>GET parameters</strong>
    <?= pretty($get) ?>
  </div>

  <div class="box">
    <strong>POST parameters</strong>
    <?= pretty($post) ?>
  </div>

  <div class="box">
    <strong>Raw body</strong>
    <pre style="white-space:pre-wrap;word-break:break-word;"><?= htmlspecialchars($rawBody ?: '(empty)') ?></pre>
  </div>

  <div class="box">
    <strong>$_SERVER (selected)</strong>
    <?= pretty(array_intersect_key($serverVars, array_flip([
        'SERVER_NAME','SERVER_ADDR','SERVER_PROTOCOL','SERVER_PORT','REQUEST_METHOD',
        'REMOTE_ADDR','REMOTE_PORT','HTTP_HOST','HTTP_USER_AGENT','HTTP_REFERER'
    ]))) ?>
  </div>

  <div class="box">
    <strong>MAC-like values found</strong>
    <?php if (count($found) === 0): ?>
      <div class="warn">No MAC-like pattern found in headers / GET / POST / body.</div>
      <div>Common places APs put the MAC: query string param names like <code>mac</code>, <code>client_mac</code>, <code>callingstationid</code>, or in a header added by the gateway.</div>
    <?php else: ?>
      <?= pretty($found) ?>
    <?php endif; ?>
  </div>

  <div class="box">
    <strong>Notes</strong>
    <ul>
      <li>Browsers do <strong>not</strong> send the device MAC address in normal HTTP requests. If you see a MAC, it was added by the AP/gateway or proxy in the URL or a header.</li>
      <li>If you want the AP to include MAC, look for WISPr or captive-portal settings that add the MAC as a query parameter when redirecting to the portal.</li>
      <li>Be careful logging or exposing MAC addresses (privacy). Remove logs or secure them when not needed.</li>
    </ul>
  </div>
</body>
</html>
