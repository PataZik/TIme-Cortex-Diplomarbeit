<?php
session_start();
if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    http_response_code(401); exit;
}
require_once 'db_config.php';

header('Content-Type: application/json');
$aid = (int)($_GET['aid'] ?? 0);
if (!$aid) { echo '[]'; exit; }

$st = mysqli_prepare($link, "SELECT pause_id, DATE_FORMAT(start_pause,'%H:%i') AS start_t, DATE_FORMAT(ende_pause,'%H:%i') AS ende_t FROM pausen WHERE anwesenheit_id=? AND is_auto=0 ORDER BY start_pause ASC");
mysqli_stmt_bind_param($st, "i", $aid);
mysqli_stmt_execute($st);
echo json_encode(mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC));
mysqli_stmt_close($st);
