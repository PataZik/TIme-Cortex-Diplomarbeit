<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin'])) { http_response_code(403); exit; }
$bid = (int)$_SESSION['id'];
$nid = (int)($_POST['id'] ?? 0);
if ($nid > 0) {
    $st = mysqli_prepare($link, "DELETE FROM benachrichtigungen WHERE benachrichtigung_id=? AND benutzer_id=?");
    mysqli_stmt_bind_param($st, "ii", $nid, $bid);
    mysqli_stmt_execute($st);
}
echo 'ok';
