<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    header("location: index.php"); exit;
}
$benutzer_name = $_SESSION['username'];
$rolle = $_SESSION['rolle'];
$success = '';

// Zählt Werktage (Mo-Fr, keine Feiertage) innerhalb eines Abwesenheitszeitraums
function absence_working_days($link, int $uid, string $typ, string $von, string $bis, array $feiertage): int {
    $st = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND status='Genehmigt' AND abwesenheit_typ=? AND abwesenheit_beginn<=? AND abwesenheit_ende>=?");
    mysqli_stmt_bind_param($st, "isss", $uid, $typ, $bis, $von);
    mysqli_stmt_execute($st);
    $total = 0;
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $row) {
        $d = max($row['abwesenheit_beginn'], $von);
        $e = min($row['abwesenheit_ende'],   $bis);
        while ($d <= $e) {
            if ((int)date('N', strtotime($d)) <= 5 && !isset($feiertage[$d])) $total++;
            $d = date('Y-m-d', strtotime("$d +1 day"));
        }
    }
    mysqli_stmt_close($st);
    return $total;
}

// --- BERICHT GENERIEREN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $von = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['von'] ?? '') ? $_POST['von'] : date('Y-m-01');
    $bis = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['bis'] ?? '') ? $_POST['bis'] : date('Y-m-t');
    $bid = (int)($_POST['benutzer_id'] ?? 0);

    $von_fmt = date('d.m.Y', strtotime($von));
    $bis_fmt = date('d.m.Y', strtotime($bis));

    // Feiertage für den Zeitraum laden (identisch zu statistik.php)
    $feiertage = [];
    $st_f = mysqli_prepare($link, "SELECT datum FROM feiertage WHERE datum BETWEEN ? AND ?");
    mysqli_stmt_bind_param($st_f, "ss", $von, $bis);
    mysqli_stmt_execute($st_f);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($st_f), MYSQLI_ASSOC) as $f) $feiertage[$f['datum']] = true;
    mysqli_stmt_close($st_f);

    if ($bid) {
        // Einzelner Benutzer
        $st = mysqli_prepare($link, "
            SELECT b.name,
                   COALESCE(ROUND(SUM(TIME_TO_SEC(a.stunden_differenz))/3600, 2), 0) as gesamtstunden
            FROM benutzer b
            LEFT JOIN anwesenheitsaufzeichnungen a ON b.benutzer_id=a.benutzer_id AND a.anwesenheits_datum BETWEEN ? AND ?
            WHERE b.benutzer_id=?
            GROUP BY b.benutzer_id, b.name
        ");
        mysqli_stmt_bind_param($st, "ssi", $von, $bis, $bid);
        mysqli_stmt_execute($st);
        $row = mysqli_stmt_get_result($st)->fetch_assoc();
        if ($row) {
            $urlaub = absence_working_days($link, $bid, 'Urlaub', $von, $bis, $feiertage);
            $krank  = absence_working_days($link, $bid, 'Krank',  $von, $bis, $feiertage);
            $name = sprintf('Bericht %s – %s (%s)', $von_fmt, $bis_fmt, $row['name']);
            $daten = json_encode(['benutzer_id'=>$bid,'gesamtstunden'=>$row['gesamtstunden'],'urlaub'=>$urlaub,'krank'=>$krank]);
            $ts = date('Y-m-d H:i:s');
            $ins = mysqli_prepare($link, "INSERT INTO berichte (bericht_name, bericht_daten, erzeugt_am) VALUES (?,?,?)");
            mysqli_stmt_bind_param($ins, "sss", $name, $daten, $ts);
            mysqli_stmt_execute($ins); mysqli_stmt_close($ins);
            $success = "Bericht '$name' erstellt.";
        }
    } else {
        // Alle Benutzer → EINEN kombinierten Bericht
        $st = mysqli_prepare($link, "
            SELECT b.benutzer_id, b.name,
                   COALESCE(ROUND(SUM(TIME_TO_SEC(a.stunden_differenz))/3600, 2), 0) as gesamtstunden
            FROM benutzer b
            LEFT JOIN anwesenheitsaufzeichnungen a ON b.benutzer_id=a.benutzer_id AND a.anwesenheits_datum BETWEEN ? AND ?
            GROUP BY b.benutzer_id, b.name
            ORDER BY b.name
        ");
        mysqli_stmt_bind_param($st, "ss", $von, $bis);
        mysqli_stmt_execute($st);
        $alle = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
        $ts = date('Y-m-d H:i:s');
        $mitarbeiter_data = [];
        $total_stunden = 0.0;
        $total_urlaub  = 0;
        $total_krank   = 0;
        foreach ($alle as $row) {
            $urlaub = absence_working_days($link, $row['benutzer_id'], 'Urlaub', $von, $bis, $feiertage);
            $krank  = absence_working_days($link, $row['benutzer_id'], 'Krank',  $von, $bis, $feiertage);
            $total_stunden += (float)$row['gesamtstunden'];
            $total_urlaub  += $urlaub;
            $total_krank   += $krank;
            $mitarbeiter_data[] = ['name'=>$row['name'],'gesamtstunden'=>(float)$row['gesamtstunden'],'urlaub'=>$urlaub,'krank'=>$krank];
        }
        $bname = sprintf('Bericht %s – %s (Alle %d Mitarbeiter)', $von_fmt, $bis_fmt, count($alle));
        $daten = json_encode(['gesamt'=>true,'mitarbeiter'=>$mitarbeiter_data,'gesamtstunden'=>round($total_stunden,2),'urlaub'=>$total_urlaub,'krank'=>$total_krank]);
        $ins = mysqli_prepare($link, "INSERT INTO berichte (bericht_name, bericht_daten, erzeugt_am) VALUES (?,?,?)");
        mysqli_stmt_bind_param($ins, "sss", $bname, $daten, $ts);
        mysqli_stmt_execute($ins); mysqli_stmt_close($ins);
        $success = sprintf('Bericht für %d Mitarbeiter erstellt.', count($alle));
    }
    header("Location: admin_berichte.php?msg=" . urlencode($success) . "&sel_von=" . urlencode($von) . "&sel_bis=" . urlencode($bis) . "&sel_bid=$bid"); exit;
}

// Berichte älter als 1 Tag automatisch löschen
mysqli_query($link, "DELETE FROM berichte WHERE erzeugt_am < NOW() - INTERVAL 1 DAY");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $bid2 = (int)$_POST['bericht_id'];
    $st = mysqli_prepare($link, "DELETE FROM berichte WHERE bericht_id=?");
    mysqli_stmt_bind_param($st, "i", $bid2);
    mysqli_stmt_execute($st); mysqli_stmt_close($st);
    header("Location: admin_berichte.php?msg=" . urlencode("Bericht gelöscht.")); exit;
}

// Hilfsfunktion für einfache prepared statements
function mysqli_prepare_get($link, $sql, $types, ...$params) {
    $st = mysqli_prepare($link, $sql);
    if ($params) mysqli_stmt_bind_param($st, $types, ...$params);
    mysqli_stmt_execute($st);
    return mysqli_stmt_get_result($st);
}

if (isset($_GET['msg'])) $success = htmlspecialchars($_GET['msg']);
$sel_von = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['sel_von'] ?? '') ? $_GET['sel_von'] : date('Y-m-01');
$sel_bis = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['sel_bis'] ?? '') ? $_GET['sel_bis'] : date('Y-m-t');
$sel_bid = isset($_GET['sel_bid']) ? (int)$_GET['sel_bid'] : 0;

// Berichte laden
$page_num = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page_num - 1) * $per_page;
$total = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) as cnt FROM berichte"))['cnt'];
$total_pages = ceil($total / $per_page);

$berichte = mysqli_fetch_all(mysqli_query($link, sprintf("SELECT * FROM berichte ORDER BY erzeugt_am DESC LIMIT %d OFFSET %d", $per_page, $offset)), MYSQLI_ASSOC);

$alle_benutzer = mysqli_fetch_all(mysqli_query($link, "SELECT benutzer_id, name FROM benutzer ORDER BY name"), MYSQLI_ASSOC);
$wartungen = mysqli_fetch_all(mysqli_query($link, "SELECT beschreibung FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"), MYSQLI_ASSOC);
$session_id = (int)$_SESSION['id'];
$st_n = mysqli_prepare($link, "SELECT n.benachrichtigung_id, n.nachricht, n.zeitstempel, n.gelesen, b.name AS von_name FROM benachrichtigungen n LEFT JOIN benutzer b ON n.von_benutzer_id = b.benutzer_id WHERE n.benutzer_id=? AND n.zeitstempel >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY n.zeitstempel DESC LIMIT 20");
mysqli_stmt_bind_param($st_n, "i", $session_id);
mysqli_stmt_execute($st_n);
$nachrichten_user = mysqli_fetch_all(mysqli_stmt_get_result($st_n), MYSQLI_ASSOC);
mysqli_stmt_close($st_n);
$nachrichten_count = count(array_filter($nachrichten_user, fn($n) => !$n['gelesen']));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Berichte | Admin</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.admin-subnav{background:#111;border-bottom:1px solid #333;padding:0 30px;display:flex;gap:5px;}
.admin-subnav a{color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;}
.admin-subnav a:hover{color:#fff;}.admin-subnav a.active{color:#007bff;border-bottom-color:#007bff;}
.btn-sm{padding:5px 12px;border-radius:6px;border:1px solid #555;background:none;color:#fff;cursor:pointer;font-size:0.8rem;}
.btn-sm:hover{background:#333;}
.btn-danger{border-color:#ff4d4d;color:#ff4d4d;}.btn-danger:hover{background:#ff4d4d;color:#fff;}
.alert-success{background:rgba(46,204,113,0.15);border:1px solid #2ecc71;color:#2ecc71;padding:12px 16px;border-radius:8px;margin-bottom:20px;}
.gen-card{background:#2d2d2d;border:1px solid #444;border-radius:16px;padding:25px;margin-bottom:25px;}
.gen-card h3{margin:0 0 18px;color:#fff;font-size:1rem;display:flex;align-items:center;gap:8px;}
.gen-form{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.gen-form label{display:flex;flex-direction:column;gap:5px;font-size:0.85rem;color:#aaa;}
.gen-form select,.gen-form input{background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;min-width:150px;}
.gen-form button{padding:10px 22px;background:#2ecc71;color:#000;border:none;border-radius:8px;cursor:pointer;font-weight:700;}
.gen-form button:hover{filter:brightness(1.1);}
.pagination{display:flex;gap:6px;margin-top:20px;justify-content:center;}
.pagination a,.pagination span{padding:6px 12px;border-radius:6px;border:1px solid #444;color:#aaa;text-decoration:none;font-size:0.85rem;}
.pagination a:hover{background:#333;color:#fff;}.pagination .current{background:#007bff;color:#fff;border-color:#007bff;}
.json-detail{font-size:0.8rem;color:#aaa;line-height:1.6;}
</style>
</head>
<body>

<nav class="navbar">
    <div class="nav-links">
        <a href="index.php">Dashboard</a>
        <a href="statistik.php">Statistik</a>
        <a href="abwesenheit_antrag.php">Abwesenheit</a>
        <a href="admin_dashboard.php">Admin</a>
    </div>
    <div class="user-info">
        <div class="bell-container">
            <i class="fas fa-bell"></i>
            <div class="bell-dropdown">
                <?php if (!empty($wartungen)): foreach ($wartungen as $w): ?><div class="bell-item"><div><?php echo htmlspecialchars($w['beschreibung']); ?></div></div><?php endforeach; else: ?><div class="bell-item">Keine geplanten Wartungen</div><?php endif; ?>
            </div>
        </div>
        <div class="bell-container msg-container" style="color:#6bc5f8;" onmouseenter="this.querySelector('.bell-badge')?.remove();fetch('mark_nachrichten.php')">
            <i class="fas fa-envelope"></i>
            <?php if ($nachrichten_count > 0): ?><span class="bell-badge"><?php echo $nachrichten_count; ?></span><?php endif; ?>
            <div class="bell-dropdown" style="width:320px;">
                <div style="padding:10px 14px;font-weight:600;border-bottom:1px solid #333;color:#fff;font-size:0.9rem;">Meine Nachrichten</div>
                <?php if (empty($nachrichten_user)): ?><div class="bell-item" style="color:#888;">Keine neuen Nachrichten</div>
                <?php else: foreach ($nachrichten_user as $n): ?><div class="bell-item" data-id="<?php echo (int)$n['benachrichtigung_id']; ?>"><small><?php echo date('d.m.Y H:i', strtotime($n['zeitstempel'])); ?><?php if (!empty($n['von_name'])): ?> · Von: <?php echo htmlspecialchars($n['von_name']); ?><?php endif; ?></small><div><?php echo htmlspecialchars($n['nachricht']); ?></div><button class="msg-del-btn" title="Löschen"><i class="fas fa-trash-alt"></i></button></div><?php endforeach; endif; ?>
            </div>
        </div>
        <?php include '_navbar_profile.php'; ?>
    </div>
</nav>

<div class="admin-subnav">
    <a href="admin_dashboard.php">Übersicht</a>
    <a href="admin_benutzer.php">Benutzer</a>
    <a href="admin_zeiterfassung.php">Zeiterfassung</a>
    <a href="admin_abwesenheiten.php">Abwesenheiten</a>
    <a href="admin_sicherheit.php">Sicherheit</a>
    <a href="admin_benachrichtigungen.php">Benachrichtigungen</a>
    <a href="admin_berichte.php" class="active">Berichte</a>
    <a href="admin_wartung.php">Wartung</a>
</div>

<div class="container">
<div class="page-width">
    <h1 class="page-title">Berichte</h1>

    <?php if ($success): ?><div class="alert-success"><?php echo $success; ?></div><?php endif; ?>

    <!-- Bericht generieren -->
    <div class="gen-card">
        <h3><i class="fas fa-chart-bar" style="color:#2ecc71;"></i> Bericht generieren</h3>
        <form method="POST" class="gen-form">
            <input type="hidden" name="action" value="generate">
            <label>Von<input type="date" name="von" value="<?php echo $sel_von; ?>"></label>
            <label>Bis<input type="date" name="bis" value="<?php echo $sel_bis; ?>"></label>
            <label>Mitarbeiter
                <select name="benutzer_id">
                    <option value="0" <?php echo $sel_bid===0?'selected':''; ?>>Alle Mitarbeiter</option>
                    <?php foreach ($alle_benutzer as $u): ?>
                        <option value="<?php echo $u['benutzer_id']; ?>" <?php echo $sel_bid===(int)$u['benutzer_id']?'selected':''; ?>><?php echo htmlspecialchars($u['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit"><i class="fas fa-play"></i> Generieren</button>
        </form>
    </div>

    <!-- Export CSV Button -->
    <div style="margin-bottom:16px;text-align:right;">
        <a href="export.php?von=<?php echo urlencode($sel_von); ?>&bis=<?php echo urlencode($sel_bis); ?>&user_id=<?php echo $sel_bid ?: 'all'; ?>" class="btn-sm" style="border-color:#2ecc71;color:#2ecc71;text-decoration:none;padding:8px 16px;border-radius:6px;border:1px solid;">
            <i class="fas fa-file-excel"></i> Excel Export
        </a>
    </div>

    <!-- Berichte Tabelle -->
    <div class="dashboard-card" style="padding:0;overflow:hidden;">
        <table class="admin-table">
            <thead><tr><th>ID</th><th>Berichtsname</th><th>Details</th><th>Erstellt am</th><th style="text-align:right;">Löschen</th></tr></thead>
            <tbody>
            <?php if (empty($berichte)): ?>
                <tr><td colspan="5" class="muted-text" style="text-align:center;padding:30px;">Noch keine Berichte vorhanden.</td></tr>
            <?php endif; ?>
            <?php foreach ($berichte as $b):
                $daten = json_decode($b['bericht_daten'] ?? '{}', true);
            ?>
                <tr>
                    <td style="color:#666;">#<?php echo $b['bericht_id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($b['bericht_name']); ?></strong></td>
                    <td class="json-detail">
                        <?php if (!empty($daten)): ?>
                            <?php if (!empty($daten['gesamt']) && !empty($daten['mitarbeiter'])): ?>
                                Stunden: <?php echo $daten['gesamtstunden']; ?>h &nbsp; Urlaub: <?php echo $daten['urlaub']; ?> T &nbsp; Krank: <?php echo $daten['krank']; ?> T
                            <?php else: ?>
                                <?php if (isset($daten['gesamtstunden'])): ?>Stunden: <?php echo $daten['gesamtstunden']; ?>h &nbsp;<?php endif; ?>
                                <?php if (isset($daten['urlaub'])): ?>Urlaub: <?php echo $daten['urlaub']; ?> T &nbsp;<?php endif; ?>
                                <?php if (isset($daten['krank'])): ?>Krank: <?php echo $daten['krank']; ?> T<?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="font-size:0.82rem;"><?php echo date('d.m.Y H:i', strtotime($b['erzeugt_am'])); ?></td>
                    <td style="text-align:right;">
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="bericht_id" value="<?php echo (int)$b['bericht_id']; ?>">
                            <button type="submit" class="btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination" style="padding:16px;">
            <?php if ($page_num > 1): ?><a href="?page=<?php echo $page_num-1; ?>">&#8249;</a><?php endif; ?>
            <?php for ($i=1;$i<=$total_pages;$i++): ?>
                <?php if ($i==$page_num): ?><span class="current"><?php echo $i; ?></span>
                <?php else: ?><a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page_num < $total_pages): ?><a href="?page=<?php echo $page_num+1; ?>">&#8250;</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
</body>
</html>
