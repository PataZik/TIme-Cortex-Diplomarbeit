<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    header("location: index.php");
    exit;
}

$user_id = $_GET['user_id'] ?? 'all';

// Date range: support von/bis or legacy year/month
if (!empty($_GET['von']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['von'])) {
    $start_date = $_GET['von'];
    $end_date   = (!empty($_GET['bis']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bis'])) ? $_GET['bis'] : $start_date;
} else {
    $year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
    if ($year < 2024 || $year > 2100) die("Ungültiges Jahr.");
    if ($month < 1   || $month > 12)  die("Ungültiger Monat.");
    $start_date = $year . '-' . sprintf('%02d', $month) . '-01';
    $end_date   = date('Y-m-t', strtotime($start_date));
}

if ($user_id === 'all') {
    $sql = "
        SELECT b.name, a.anwesenheits_datum, a.start_arbeitszeit, a.ende_arbeitszeit, a.stunden_differenz
        FROM anwesenheitsaufzeichnungen a
        JOIN benutzer b ON a.benutzer_id = b.benutzer_id
        WHERE a.anwesenheits_datum BETWEEN ? AND ?
        ORDER BY b.name ASC, a.anwesenheits_datum ASC, a.start_arbeitszeit ASC
    ";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $start_date, $end_date);
    $filename = "arbeitszeiten_{$start_date}_bis_{$end_date}_alle.csv";
} else {
    $user_id = (int)$user_id;
    $st2 = mysqli_prepare($link, "SELECT name FROM benutzer WHERE benutzer_id = ?");
    mysqli_stmt_bind_param($st2, "i", $user_id);
    mysqli_stmt_execute($st2);
    $res_user = mysqli_stmt_get_result($st2)->fetch_assoc();
    mysqli_stmt_close($st2);
    if (!$res_user) die("Mitarbeiter nicht gefunden.");
    $safe_name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $res_user['name']);
    $sql = "
        SELECT b.name, a.anwesenheits_datum, a.start_arbeitszeit, a.ende_arbeitszeit, a.stunden_differenz
        FROM anwesenheitsaufzeichnungen a
        JOIN benutzer b ON a.benutzer_id = b.benutzer_id
        WHERE a.anwesenheits_datum BETWEEN ? AND ? AND a.benutzer_id = ?
        ORDER BY a.anwesenheits_datum ASC, a.start_arbeitszeit ASC
    ";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, "ssi", $start_date, $end_date, $user_id);
    $filename = "arbeitszeiten_{$start_date}_bis_{$end_date}_{$safe_name}.csv";
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Group by employee
$by_employee = [];
while ($row = mysqli_fetch_assoc($result)) {
    $by_employee[$row['name']][] = $row;
}
mysqli_stmt_close($stmt);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

$first = true;
foreach ($by_employee as $name => $rows) {
    if (!$first) {
        fputcsv($output, [], ';'); // blank separator line
    }
    $first = false;

    fputcsv($output, [$name], ';');
    fputcsv($output, ['Mitarbeiter', 'Datum', 'Start', 'Ende', 'Stunden-Differenz'], ';');

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['name'],
            date('d.m.Y', strtotime($r['anwesenheits_datum'])),
            $r['start_arbeitszeit'],
            $r['ende_arbeitszeit'],
            $r['stunden_differenz'],
        ], ';');
    }
}

fclose($output);
exit;
