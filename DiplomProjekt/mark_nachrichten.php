<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin'])) { http_response_code(403); exit; }
$bid = (int)$_SESSION['id'];
$st = mysqli_prepare($link, "UPDATE benachrichtigungen SET gelesen=1 WHERE benutzer_id=? AND gelesen=0");
mysqli_stmt_bind_param($st, "i", $bid);
mysqli_stmt_execute($st);
echo 'ok';
