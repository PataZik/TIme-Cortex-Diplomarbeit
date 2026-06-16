<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    header("location: index.php"); exit;
}
$benutzer_name = $_SESSION['username'];
$rolle = $_SESSION['rolle'];

$success = '';

function auto_pause_day($link, int $bid, string $datum): void {
    $st = mysqli_prepare($link, "SELECT anwesenheit_id, start_arbeitszeit, ende_arbeitszeit FROM anwesenheitsaufzeichnungen WHERE benutzer_id=? AND anwesenheits_datum=? AND ende_arbeitszeit IS NOT NULL AND ende_arbeitszeit != '00:00:00' ORDER BY start_arbeitszeit ASC");
    mysqli_stmt_bind_param($st, "is", $bid, $datum);
    mysqli_stmt_execute($st);
    $entries = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    mysqli_stmt_close($st);

    if (empty($entries)) return;

    $aids_str = implode(',', array_map('intval', array_column($entries, 'anwesenheit_id')));
    mysqli_query($link, "DELETE FROM pausen WHERE anwesenheit_id IN ($aids_str) AND is_auto=1");

    $total_brutto  = 0;
    $entry_bruttos = [];
    foreach ($entries as $e) {
        $b = max(0, strtotime("1970-01-01 " . substr($e['ende_arbeitszeit'],0,8)) - strtotime("1970-01-01 " . substr($e['start_arbeitszeit'],0,8)));
        $entry_bruttos[(int)$e['anwesenheit_id']] = $b;
        $total_brutto += $b;
    }

    if ($total_brutto <= 6 * 3600) return;

    $res_mp = mysqli_query($link, "SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, start_pause, ende_pause)), 0) as p FROM pausen WHERE anwesenheit_id IN ($aids_str) AND is_auto=0");
    $manual_pause = (int)($res_mp ? mysqli_fetch_assoc($res_mp)['p'] : 0);
    if ($manual_pause >= 1800) return;

    $cumulative = 0;
    $target_aid  = null;
    $ps = $pe    = null;
    foreach ($entries as $e) {
        $aid      = (int)$e['anwesenheit_id'];
        $b        = $entry_bruttos[$aid];
        $prev_cum = $cumulative;
        $cumulative += $b;
        if ($cumulative > 6 * 3600) {
            $secs_needed    = 6 * 3600 - $prev_cum;
            $entry_start_ts = strtotime("$datum " . substr($e['start_arbeitszeit'],0,8));
            $ps = date('Y-m-d H:i:s', $entry_start_ts + $secs_needed);
            $pe = date('Y-m-d H:i:s', $entry_start_ts + $secs_needed + 1800);
            $target_aid = $aid;
            break;
        }
    }

    if (!$target_aid) return;

    $is_auto = 1;
    $st_pi   = mysqli_prepare($link, "INSERT INTO pausen (benutzer_id, anwesenheit_id, start_pause, ende_pause, is_auto) VALUES (?,?,?,?,?)");
    mysqli_stmt_bind_param($st_pi, "iissi", $bid, $target_aid, $ps, $pe, $is_auto);
    mysqli_stmt_execute($st_pi);
    mysqli_stmt_close($st_pi);
}

// --- AKTIONEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit') {
        $aid   = (int)$_POST['anwesenheit_id'];
        $start = $_POST['start_arbeitszeit'];
        $ende  = $_POST['ende_arbeitszeit'];
        $datum = $_POST['anwesenheits_datum'];

        $st_bid = mysqli_prepare($link, "SELECT benutzer_id FROM anwesenheitsaufzeichnungen WHERE anwesenheit_id=?");
        mysqli_stmt_bind_param($st_bid, "i", $aid);
        mysqli_stmt_execute($st_bid);
        $bid_row = mysqli_stmt_get_result($st_bid)->fetch_assoc();
        mysqli_stmt_close($st_bid);
        $bid_val = (int)($bid_row['benutzer_id'] ?? 0);

        // Überschneidungscheck: kein anderer Eintrag desselben Benutzers an diesem Tag darf sich überschneiden
        $st_ov = mysqli_prepare($link, "SELECT COUNT(*) as cnt FROM anwesenheitsaufzeichnungen WHERE benutzer_id=? AND anwesenheits_datum=? AND anwesenheit_id != ? AND start_arbeitszeit < ? AND ende_arbeitszeit > ?");
        mysqli_stmt_bind_param($st_ov, "isiss", $bid_val, $datum, $aid, $ende, $start);
        mysqli_stmt_execute($st_ov);
        $ov_cnt = (int)mysqli_stmt_get_result($st_ov)->fetch_assoc()['cnt'];
        mysqli_stmt_close($st_ov);
        if ($ov_cnt > 0) {
            header("Location: admin_zeiterfassung.php?msg=" . urlencode("Fehler: Die Zeiten überschneiden sich mit einem bestehenden Eintrag!"));
            exit;
        }

        $brutto_sek = max(0, strtotime("1970-01-01 $ende") - strtotime("1970-01-01 $start"));

        // Process manually submitted pauses
        $pause_starts  = $_POST['pause_start'] ?? [];
        $pause_ends    = $_POST['pause_end']   ?? [];
        $valid_pauses  = [];
        $pause_sek_sum = 0;
        foreach ($pause_starts as $i => $ps) {
            $pe = $pause_ends[$i] ?? '';
            if ($ps && $pe) {
                $dur = max(0, strtotime("1970-01-01 $pe") - strtotime("1970-01-01 $ps"));
                $pause_sek_sum += $dur;
                $valid_pauses[] = ['start' => $ps, 'end' => $pe];
            }
        }

        $diff = sprintf('%02d:%02d:%02d', floor($brutto_sek/3600), floor(($brutto_sek%3600)/60), $brutto_sek%60);
        $st = mysqli_prepare($link, "UPDATE anwesenheitsaufzeichnungen SET anwesenheits_datum=?, start_arbeitszeit=?, ende_arbeitszeit=?, stunden_differenz=? WHERE anwesenheit_id=?");
        mysqli_stmt_bind_param($st, "ssssi", $datum, $start, $ende, $diff, $aid);
        mysqli_stmt_execute($st); mysqli_stmt_close($st);

        // Rebuild manual pauses, auto-pause managed by auto_pause_day
        $st_del = mysqli_prepare($link, "DELETE FROM pausen WHERE anwesenheit_id=? AND is_auto=0");
        mysqli_stmt_bind_param($st_del, "i", $aid);
        mysqli_stmt_execute($st_del); mysqli_stmt_close($st_del);
        foreach ($valid_pauses as $p) {
            $sdt = "$datum {$p['start']}:00";
            $edt = "$datum {$p['end']}:00";
            $is_auto = 0;
            $st_pi = mysqli_prepare($link, "INSERT INTO pausen (benutzer_id, anwesenheit_id, start_pause, ende_pause, is_auto) VALUES (?,?,?,?,?)");
            mysqli_stmt_bind_param($st_pi, "iissi", $bid_val, $aid, $sdt, $edt, $is_auto);
            mysqli_stmt_execute($st_pi); mysqli_stmt_close($st_pi);
        }
        auto_pause_day($link, $bid_val, $datum);

        $success = "Eintrag aktualisiert.";
    }

    if ($action === 'delete') {
        $aid = (int)$_POST['anwesenheit_id'];
        $st_info = mysqli_prepare($link, "SELECT benutzer_id, anwesenheits_datum FROM anwesenheitsaufzeichnungen WHERE anwesenheit_id=?");
        mysqli_stmt_bind_param($st_info, "i", $aid);
        mysqli_stmt_execute($st_info);
        $del_info = mysqli_stmt_get_result($st_info)->fetch_assoc();
        mysqli_stmt_close($st_info);
        $st_dp = mysqli_prepare($link, "DELETE FROM pausen WHERE anwesenheit_id=?");
        mysqli_stmt_bind_param($st_dp, "i", $aid);
        mysqli_stmt_execute($st_dp); mysqli_stmt_close($st_dp);
        $st = mysqli_prepare($link, "DELETE FROM anwesenheitsaufzeichnungen WHERE anwesenheit_id=?");
        mysqli_stmt_bind_param($st, "i", $aid);
        mysqli_stmt_execute($st); mysqli_stmt_close($st);
        if ($del_info) auto_pause_day($link, (int)$del_info['benutzer_id'], $del_info['anwesenheits_datum']);
        $success = "Eintrag gelöscht.";
    }

    if ($action === 'create') {
        $bid   = (int)$_POST['benutzer_id'];
        $datum = $_POST['anwesenheits_datum'];
        $start = $_POST['start_arbeitszeit'];
        $ende  = $_POST['ende_arbeitszeit'];

        // Überschneidungscheck: kein Eintrag desselben Benutzers an diesem Tag darf sich überschneiden
        $st_ov = mysqli_prepare($link, "SELECT COUNT(*) as cnt FROM anwesenheitsaufzeichnungen WHERE benutzer_id=? AND anwesenheits_datum=? AND start_arbeitszeit < ? AND ende_arbeitszeit > ?");
        mysqli_stmt_bind_param($st_ov, "isss", $bid, $datum, $ende, $start);
        mysqli_stmt_execute($st_ov);
        $ov_cnt = (int)mysqli_stmt_get_result($st_ov)->fetch_assoc()['cnt'];
        mysqli_stmt_close($st_ov);
        if ($ov_cnt > 0) {
            header("Location: admin_zeiterfassung.php?msg=" . urlencode("Fehler: Die Zeiten überschneiden sich mit einem bestehenden Eintrag!"));
            exit;
        }

        $brutto_sek = max(0, strtotime("1970-01-01 $ende") - strtotime("1970-01-01 $start"));
        $diff = sprintf('%02d:%02d:%02d', floor($brutto_sek/3600), floor(($brutto_sek%3600)/60), $brutto_sek%60);
        $st = mysqli_prepare($link, "INSERT INTO anwesenheitsaufzeichnungen (benutzer_id, anwesenheits_datum, start_arbeitszeit, ende_arbeitszeit, stunden_differenz) VALUES (?,?,?,?,?)");
        mysqli_stmt_bind_param($st, "issss", $bid, $datum, $start, $ende, $diff);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        auto_pause_day($link, $bid, $datum);
        $success = "Eintrag erstellt.";
    }

    header("Location: admin_zeiterfassung.php?msg=" . urlencode($success)); exit;
}

if (isset($_GET['msg'])) $success = htmlspecialchars($_GET['msg']);

// Filter
$filter_user  = (int)($_GET['filter_user'] ?? 0);
$filter_von   = $_GET['filter_von'] ?? '';
$filter_bis   = $_GET['filter_bis'] ?? '';
$sort         = in_array($_GET['sort'] ?? '', ['datum_asc','datum_desc','stunden_desc','name_asc']) ? $_GET['sort'] : 'datum_desc';
$page_num     = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 20;
$offset       = ($page_num - 1) * $per_page;

$where = "WHERE 1=1";
$params = []; $types = "";

if ($filter_user) { $where .= " AND a.benutzer_id = ?"; $params[] = $filter_user; $types .= "i"; }
if ($filter_von)  { $where .= " AND a.anwesenheits_datum >= ?"; $params[] = $filter_von; $types .= "s"; }
if ($filter_bis)  { $where .= " AND a.anwesenheits_datum <= ?"; $params[] = $filter_bis; $types .= "s"; }

$order_map = [
    'datum_asc'    => 'a.anwesenheits_datum ASC, b.name ASC',
    'datum_desc'   => 'a.anwesenheits_datum DESC, b.name ASC',
    'stunden_desc' => 'TIME_TO_SEC(a.stunden_differenz) DESC',
    'name_asc'     => 'b.name ASC, a.anwesenheits_datum DESC',
];
$order = $order_map[$sort];

// Count
$cnt_st = mysqli_prepare($link, "SELECT COUNT(*) as cnt FROM anwesenheitsaufzeichnungen a JOIN benutzer b ON a.benutzer_id=b.benutzer_id $where");
if ($params) mysqli_stmt_bind_param($cnt_st, $types, ...$params);
mysqli_stmt_execute($cnt_st);
$total = mysqli_stmt_get_result($cnt_st)->fetch_assoc()['cnt'];
$total_pages = ceil($total / $per_page);

// Soll-Stunden pro Benutzer (für Überstundenberechnung)
$soll_map = [];
$res_soll = mysqli_query($link, "SELECT bp.benutzer_id, a.soll_stunden_pro_tag FROM benutzerprofile bp JOIN anstellungsarten a ON bp.anstellungs_art_id=a.art_id");
while ($s = mysqli_fetch_assoc($res_soll)) {
    $p = explode(':', $s['soll_stunden_pro_tag']);
    $soll_map[$s['benutzer_id']] = ($p[0]*3600)+($p[1]*60)+($p[2] ?? 0);
}

// Daten
$sql = "SELECT a.anwesenheit_id, a.benutzer_id, b.name, a.anwesenheits_datum,
               a.start_arbeitszeit, a.ende_arbeitszeit, a.stunden_differenz
        FROM anwesenheitsaufzeichnungen a
        JOIN benutzer b ON a.benutzer_id = b.benutzer_id
        $where ORDER BY $order LIMIT ? OFFSET ?";
$st = mysqli_prepare($link, $sql);
$p2 = array_merge($params, [$per_page, $offset]);
$t2 = $types . "ii";
mysqli_stmt_bind_param($st, $t2, ...$p2);
mysqli_stmt_execute($st);
$eintraege = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

// Benutzer für Filter-Dropdown
$alle_benutzer = mysqli_fetch_all(mysqli_query($link, "SELECT benutzer_id, name FROM benutzer ORDER BY name"), MYSQLI_ASSOC);

// Zusammenfassung (gefiltert)
$sum_st = mysqli_prepare($link, "SELECT SUM(TIME_TO_SEC(a.stunden_differenz)) as gesamt, COUNT(*) as anzahl FROM anwesenheitsaufzeichnungen a JOIN benutzer b ON a.benutzer_id=b.benutzer_id $where");
if ($params) mysqli_stmt_bind_param($sum_st, $types, ...$params);
mysqli_stmt_execute($sum_st);
$sum_data = mysqli_stmt_get_result($sum_st)->fetch_assoc();
$gesamt_sek = $sum_data['gesamt'] ?? 0;

$wartungen = mysqli_fetch_all(mysqli_query($link, "SELECT beschreibung FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"), MYSQLI_ASSOC);
$session_id = (int)$_SESSION['id'];
$st_n = mysqli_prepare($link, "SELECT n.benachrichtigung_id, n.nachricht, n.zeitstempel, n.gelesen, b.name AS von_name FROM benachrichtigungen n LEFT JOIN benutzer b ON n.von_benutzer_id = b.benutzer_id WHERE n.benutzer_id=? AND n.zeitstempel >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY n.zeitstempel DESC LIMIT 20");
mysqli_stmt_bind_param($st_n, "i", $session_id);
mysqli_stmt_execute($st_n);
$nachrichten_user = mysqli_fetch_all(mysqli_stmt_get_result($st_n), MYSQLI_ASSOC);
mysqli_stmt_close($st_n);
$nachrichten_count = count(array_filter($nachrichten_user, fn($n) => !$n['gelesen']));

function fmtH($sek) { $sek=max(0,(int)$sek); return sprintf('%02d:%02d',floor($sek/3600),floor(($sek%3600)/60)); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Zeiterfassung | Admin</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.admin-subnav{background:#111;border-bottom:1px solid #333;padding:0 30px;display:flex;gap:5px;}
.admin-subnav a{color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;}
.admin-subnav a:hover{color:#fff;}
.admin-subnav a.active{color:#007bff;border-bottom-color:#007bff;}
.btn-sm{padding:5px 12px;border-radius:6px;border:1px solid #555;background:none;color:#fff;cursor:pointer;font-size:0.8rem;}
.btn-sm:hover{background:#333;}
.btn-danger{border-color:#ff4d4d;color:#ff4d4d;}
.btn-danger:hover{background:#ff4d4d;color:#fff;}
.btn-primary{border-color:#007bff;color:#007bff;}
.btn-primary:hover{background:#007bff;color:#fff;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#2d2d2d;border:1px solid #444;border-radius:16px;padding:30px;width:440px;max-width:95vw;}
.modal-box h3{margin:0 0 20px;color:#fff;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;color:#aaa;font-size:0.85rem;margin-bottom:5px;}
.form-group input,.form-group select{width:100%;box-sizing:border-box;background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;}
.modal-actions button{padding:9px 20px;border-radius:8px;border:none;cursor:pointer;font-weight:600;}
.btn-cancel{background:#333;color:#aaa;}
.btn-save{background:#007bff;color:#fff;}
.alert-success{background:rgba(46,204,113,0.15);border:1px solid #2ecc71;color:#2ecc71;padding:12px 16px;border-radius:8px;margin-bottom:20px;}
.alert-error{background:rgba(255,77,77,0.15);border:1px solid #ff4d4d;color:#ff4d4d;padding:12px 16px;border-radius:8px;margin-bottom:20px;}
.summary-row{display:flex;gap:16px;margin-bottom:20px;}
.summary-box{background:#2d2d2d;border:1px solid #444;border-radius:10px;padding:14px 20px;flex:1;text-align:center;}
.summary-box .val{font-size:1.6rem;font-weight:bold;color:#fff;}
.summary-box .lbl{font-size:0.8rem;color:#888;text-transform:uppercase;}
.text-plus{color:#2ecc71;}
.text-minus{color:#ff4d4d;}
.pagination{display:flex;gap:6px;margin-top:20px;justify-content:center;}
.pagination a,.pagination span{padding:6px 12px;border-radius:6px;border:1px solid #444;color:#aaa;text-decoration:none;font-size:0.85rem;}
.pagination a:hover{background:#333;color:#fff;}
.pagination .current{background:#007bff;color:#fff;border-color:#007bff;}
</style>
</head>
<body>

<?php $page_file = basename($_SERVER['PHP_SELF']); ?>
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
    <a href="admin_zeiterfassung.php" class="active">Zeiterfassung</a>
    <a href="admin_abwesenheiten.php">Abwesenheiten</a>
    <a href="admin_sicherheit.php">Sicherheit</a>
    <a href="admin_benachrichtigungen.php">Benachrichtigungen</a>
    <a href="admin_berichte.php">Berichte</a>
    <a href="admin_wartung.php">Wartung</a>
</div>

<div class="container">
<div class="page-width">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h1 class="page-title" style="margin:0;">Zeiterfassung</h1>
        <button class="btn-sm btn-primary" onclick="openModal('modal-create')"><i class="fas fa-plus"></i> Eintrag hinzufügen</button>
    </div>

    <?php if ($success): ?>
        <?php if (str_starts_with($success, 'Fehler:')): ?>
            <div class="alert-error"><?php echo $success; ?></div>
        <?php else: ?>
            <div class="alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
    <?php endif; ?>


    <!-- Filter -->
    <div class="dashboard-card" style="padding:18px;margin-bottom:20px;">
        <form method="GET" class="filter-form" style="margin:0;">
            <label>Mitarbeiter
                <select name="filter_user">
                    <option value="0">Alle</option>
                    <?php foreach ($alle_benutzer as $u): ?>
                        <option value="<?php echo $u['benutzer_id']; ?>" <?php echo $filter_user==$u['benutzer_id']?'selected':''; ?>><?php echo htmlspecialchars($u['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Von<input type="date" name="filter_von" value="<?php echo htmlspecialchars($filter_von); ?>"></label>
            <label>Bis<input type="date" name="filter_bis" value="<?php echo htmlspecialchars($filter_bis); ?>"></label>
            <label>Sortierung
                <select name="sort">
                    <option value="datum_desc" <?php echo $sort=='datum_desc'?'selected':''; ?>>Datum ↓</option>
                    <option value="datum_asc" <?php echo $sort=='datum_asc'?'selected':''; ?>>Datum ↑</option>
                    <option value="stunden_desc" <?php echo $sort=='stunden_desc'?'selected':''; ?>>Stunden ↓</option>
                    <option value="name_asc" <?php echo $sort=='name_asc'?'selected':''; ?>>Name A-Z</option>
                </select>
            </label>
            <button type="submit" class="btn-export"><i class="fas fa-filter"></i> Filtern</button>
            <a href="admin_zeiterfassung.php" style="color:#aaa;text-decoration:none;align-self:flex-end;padding:9px 12px;border:1px solid #444;border-radius:8px;font-size:0.9rem;">Reset</a>
        </form>
    </div>

    <!-- Tabelle -->
    <div class="dashboard-card" style="padding:0;overflow:hidden;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Mitarbeiter</th>
                    <th>Datum</th>
                    <th>Start</th>
                    <th>Ende</th>
                    <th>Ist-Zeit</th>
                    <th>Soll-Zeit</th>
                    <th>Überstunden</th>
                    <th style="text-align:right;">Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($eintraege)): ?>
                <tr><td colspan="8" class="muted-text" style="text-align:center;padding:30px;">Keine Einträge gefunden.</td></tr>
            <?php endif; ?>
            <?php foreach ($eintraege as $e):
                $ist_sek = 0;
                $p = explode(':', $e['stunden_differenz'] ?? '0:0:0');
                $ist_sek = ($p[0]*3600)+($p[1]*60)+($p[2]??0);
                $soll_sek = $soll_map[$e['benutzer_id']] ?? 0;
                $diff_sek = $ist_sek - $soll_sek;
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($e['name']); ?></strong></td>
                    <td><?php echo date('d.m.Y', strtotime($e['anwesenheits_datum'])); ?></td>
                    <td><?php echo substr($e['start_arbeitszeit'],0,5); ?></td>
                    <td><?php echo substr($e['ende_arbeitszeit'],0,5); ?></td>
                    <td><strong><?php echo substr($e['stunden_differenz'],0,5); ?>h</strong></td>
                    <td style="color:#aaa;"><?php echo fmtH($soll_sek); ?>h</td>
                    <td>
                        <?php if ($diff_sek > 0): ?>
                            <span class="text-plus">+<?php echo fmtH($diff_sek); ?>h</span>
                        <?php elseif ($diff_sek < 0): ?>
                            <span class="text-minus"><?php echo fmtH(abs($diff_sek)); ?>h</span>
                        <?php else: ?><span style="color:#888;">±0:00h</span><?php endif; ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="btn-sm btn-primary" onclick='openEditModal(<?php echo json_encode($e); ?>)'><i class="fas fa-edit"></i></button>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Eintrag löschen?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="anwesenheit_id" value="<?php echo (int)$e['anwesenheit_id']; ?>">
                            <button type="submit" class="btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination" style="padding:16px;">
            <?php $q = http_build_query(['filter_user'=>$filter_user,'filter_von'=>$filter_von,'filter_bis'=>$filter_bis,'sort'=>$sort]); ?>
            <?php if ($page_num > 1): ?><a href="?page=<?php echo $page_num-1; ?>&<?php echo $q; ?>">&#8249;</a><?php endif; ?>
            <?php for ($i=1;$i<=$total_pages;$i++): ?>
                <?php if ($i==$page_num): ?><span class="current"><?php echo $i; ?></span>
                <?php else: ?><a href="?page=<?php echo $i; ?>&<?php echo $q; ?>"><?php echo $i; ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page_num < $total_pages): ?><a href="?page=<?php echo $page_num+1; ?>&<?php echo $q; ?>">&#8250;</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- MODAL: ERSTELLEN -->
<div class="modal-overlay" id="modal-create">
<div class="modal-box">
    <h3><i class="fas fa-plus" style="color:#007bff;"></i> Eintrag hinzufügen</h3>
    <form method="POST">
        <input type="hidden" name="action" value="create">
        <div class="form-group">
            <label>Mitarbeiter</label>
            <select name="benutzer_id" required>
                <?php foreach ($alle_benutzer as $u): ?>
                    <option value="<?php echo $u['benutzer_id']; ?>"><?php echo htmlspecialchars($u['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>Datum</label><input type="date" name="anwesenheits_datum" value="<?php echo date('Y-m-d'); ?>" required></div>
        <div class="form-row">
            <div class="form-group"><label>Start</label><input type="time" name="start_arbeitszeit" value="08:00" required></div>
            <div class="form-group"><label>Ende</label><input type="time" name="ende_arbeitszeit" value="17:00" required></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-create')">Abbrechen</button>
            <button type="submit" class="btn-save">Erstellen</button>
        </div>
    </form>
</div>
</div>

<!-- MODAL: BEARBEITEN -->
<div class="modal-overlay" id="modal-edit">
<div class="modal-box" style="width:500px;">
    <h3><i class="fas fa-edit" style="color:#007bff;"></i> Eintrag bearbeiten</h3>
    <form method="POST" id="form-edit">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="anwesenheit_id" id="edit-aid">
        <div class="form-group"><label>Datum</label><input type="date" name="anwesenheits_datum" id="edit-datum" required></div>
        <div class="form-row">
            <div class="form-group"><label>Beginn</label><input type="time" name="start_arbeitszeit" id="edit-start" required oninput="calcNetto()"></div>
            <div class="form-group"><label>Ende</label><input type="time" name="ende_arbeitszeit" id="edit-ende" required oninput="calcNetto()"></div>
        </div>

        <div style="margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;">
            <span style="color:#aaa;font-size:0.85rem;">Pausen</span>
            <button type="button" onclick="addPauseRow('','')" style="background:none;border:1px solid #555;border-radius:6px;color:#aaa;cursor:pointer;font-size:0.78rem;padding:3px 10px;">+ Pause hinzufügen</button>
        </div>
        <div id="pause-rows" style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px;"></div>
        <div id="edit-netto-info" style="background:#1a1a1a;border:1px solid #333;border-radius:8px;padding:9px 12px;font-size:0.82rem;color:#aaa;margin-bottom:14px;display:flex;gap:16px;flex-wrap:wrap;">
            <span>Brutto: <strong id="netto-brutto" style="color:#fff;">—</strong></span>
            <span>Pause: <strong id="netto-pause" style="color:#ff8800;">—</strong></span>
            <span>Netto: <strong id="netto-netto" style="color:#2ecc71;">—</strong></span>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-edit')">Abbrechen</button>
            <button type="submit" class="btn-save">Speichern</button>
        </div>
    </form>
</div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}

function fmtSek(s){s=Math.max(0,s|0);return (Math.floor(s/3600)+':'+String(Math.floor((s%3600)/60)).padStart(2,'0'))+'h';}

function timeToSek(t){if(!t)return 0;const p=t.split(':');return (+p[0])*3600+(+p[1])*60;}

function calcNetto(){
    const s=timeToSek(document.getElementById('edit-start').value);
    const e=timeToSek(document.getElementById('edit-ende').value);
    const brutto=Math.max(0,e-s);
    let pauseSek=0;
    document.querySelectorAll('.pause-row').forEach(row=>{
        const ps=timeToSek(row.querySelector('.p-start').value);
        const pe=timeToSek(row.querySelector('.p-end').value);
        if(ps&&pe) pauseSek+=Math.max(0,pe-ps);
    });
    const netto=Math.max(0,brutto-pauseSek);
    document.getElementById('netto-brutto').textContent=fmtSek(brutto);
    document.getElementById('netto-pause').textContent=fmtSek(pauseSek);
    document.getElementById('netto-netto').textContent=fmtSek(netto);
}

function addPauseRow(startVal, endVal){
    const div=document.createElement('div');
    div.className='pause-row';
    div.style.cssText='display:flex;gap:8px;align-items:center;';
    div.innerHTML=`<input type="time" name="pause_start[]" class="p-start" value="${startVal}" oninput="calcNetto()" style="flex:1;background:#1a1a1a;border:1px solid #444;border-radius:6px;padding:7px 10px;color:#fff;font-size:0.85rem;">`
        +`<span style="color:#666;font-size:0.8rem;">–</span>`
        +`<input type="time" name="pause_end[]" class="p-end" value="${endVal}" oninput="calcNetto()" style="flex:1;background:#1a1a1a;border:1px solid #444;border-radius:6px;padding:7px 10px;color:#fff;font-size:0.85rem;">`
        +`<button type="button" onclick="this.parentElement.remove();calcNetto();" style="background:none;border:1px solid #ff4d4d;border-radius:6px;color:#ff4d4d;cursor:pointer;padding:5px 9px;font-size:0.8rem;"><i class="fas fa-times"></i></button>`;
    document.getElementById('pause-rows').appendChild(div);
    calcNetto();
}

function openEditModal(e){
    document.getElementById('edit-aid').value=e.anwesenheit_id;
    document.getElementById('edit-datum').value=e.anwesenheits_datum;
    document.getElementById('edit-start').value=e.start_arbeitszeit?e.start_arbeitszeit.substring(0,5):'';
    document.getElementById('edit-ende').value=e.ende_arbeitszeit?e.ende_arbeitszeit.substring(0,5):'';
    document.getElementById('pause-rows').innerHTML='';
    calcNetto();
    openModal('modal-edit');
    fetch('get_pausen.php?aid='+e.anwesenheit_id)
        .then(r=>r.json())
        .then(pauses=>{
            pauses.forEach(p=>addPauseRow(p.start_t||'', p.ende_t||''));
        }).catch(()=>{});
}
document.querySelectorAll('.modal-overlay').forEach(el=>{el.addEventListener('click',function(e){if(e.target===el)el.classList.remove('open');});});
</script>
</body>
</html>
