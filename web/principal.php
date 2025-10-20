<?php
// showmac.php
// Simple display of the client MAC sent by the AP.

if (isset($_GET['mac'])) {
    $mac = htmlspecialchars($_GET['mac']);
    echo "<h2>Client MAC Address:</h2>";
    echo "<p><strong>$mac</strong></p>";
} else {
    echo "<h2>No MAC address found in the URL.</h2>";
    echo "<p>Try accessing this page with ?mac=xx:xx:xx:xx:xx:xx</p>";
}
?>
