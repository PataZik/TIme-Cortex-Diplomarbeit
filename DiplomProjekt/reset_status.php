<?php
session_start();
require_once 'db_config.php';
header('Content-Type: application/json');

$antrag_id = (int)($_GET['id'] ?? 0);

if ($antrag_id < 1 || empty($_SESSION['reset_antrag_id']) || (int)$_SESSION['reset_antrag_id'] !== $antrag_id) {
    echo json_encode(['status' => 'error']);
    exit;
}

$st = mysqli_prepare($link, "SELECT status, token FROM passwort_reset_antraege WHERE antrag_id=?");
mysqli_stmt_bind_param($st, "i", $antrag_id);
mysqli_stmt_execute($st);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
mysqli_stmt_close($st);

if (!$row) {
    echo json_encode(['status' => 'error']);
    exit;
}

echo json_encode(['status' => $row['status'], 'token' => $row['token']]);
