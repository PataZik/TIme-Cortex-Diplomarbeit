<?php
session_start();
require_once 'db_config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['rolle'] ?? '', ['Admin', 'Manager'])) {
    echo json_encode(['count' => 0]); exit;
}

$res = mysqli_query($link, "SELECT COUNT(*) AS cnt FROM passwort_reset_antraege WHERE status='Ausstehend'");
$row = mysqli_fetch_assoc($res);
echo json_encode(['count' => (int)($row['cnt'] ?? 0)]);
