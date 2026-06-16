<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    echo json_encode(['count' => 0]); exit;
}
$res = mysqli_query($link, "SELECT COUNT(*) as cnt FROM abwesenheiten WHERE status='Ausstehend'");
$cnt = (int)(mysqli_fetch_assoc($res)['cnt'] ?? 0);
echo json_encode(['count' => $cnt]);
