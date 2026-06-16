<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    header("location: index.php"); exit;
}
$benutzer_name = $_SESSION['username'];
$rolle = $_SESSION['rolle'];
$success = '';
$error_msg = '';

// DB-Migration
mysqli_query($link, "ALTER TABLE abwesenheiten ADD COLUMN IF NOT EXISTS verspätungszeit TIME NULL DEFAULT NULL");

// --- HILFSFUNKTION: Arbeitseinträge für genehmigte Abwesenheit anlegen ---
function createWorkEntries($link, int $aid): void {
    $st_abs = mysqli_prepare($link,
        "SELECT a.benutzer_id, a.abwesenheit_beginn, a.abwesenheit_ende,
                aa.soll_stunden_pro_tag, bp.anstellungs_art_id
         FROM abwesenheiten a
         JOIN benutzerprofile bp ON a.benutzer_id = bp.benutzer_id
         JOIN anstellungsarten aa ON bp.anstellungs_art_id = aa.art_id
         WHERE a.abwesenheit_id = ?");
    mysqli_stmt_bind_param($st_abs, "i", $aid);
    mysqli_stmt_execute($st_abs);
    $abs = mysqli_stmt_get_result($st_abs)->fetch_assoc();
    mysqli_stmt_close($st_abs);
    if (!$abs) return;

    $bid        = (int)$abs['benutzer_id'];
    $p          = explode(':', $abs['soll_stunden_pro_tag']);
    $brutto_sek = ($p[0]*3600)+($p[1]*60)+($p[2]??0);
    $abzug_sek  = ($abs['anstellungs_art_id'] == 1) ? 1800 : 0;
    $brutto_fmt = sprintf('%02d:%02d:%02d', floor($brutto_sek/3600), floor(($brutto_sek%3600)/60), $brutto_sek%60);
    $end_h      = 8 + (int)floor($brutto_sek/3600);
    $end_m      = (int)floor(($brutto_sek%3600)/60);
    $end_time   = sprintf('%02d:%02d:00', $end_h, $end_m);

    $von_datum = $abs['abwesenheit_beginn'];
    $bis_datum = $abs['abwesenheit_ende'];
    $feiertage_abs = [];
    $st_fabs = mysqli_prepare($link, "SELECT datum FROM feiertage WHERE datum BETWEEN ? AND ?");
    mysqli_stmt_bind_param($st_fabs, "ss", $von_datum, $bis_datum);
    mysqli_stmt_execute($st_fabs);
    $res_fabs = mysqli_stmt_get_result($st_fabs);
    while ($frow = mysqli_fetch_assoc($res_fabs)) $feiertage_abs[$frow['datum']] = true;

    $ins = mysqli_prepare($link,
        "INSERT INTO anwesenheitsaufzeichnungen
         (benutzer_id, anwesenheits_datum, start_arbeitszeit, ende_arbeitszeit, stunden_differenz)
         SELECT ?,?,?,?,? WHERE NOT EXISTS
         (SELECT 1 FROM anwesenheitsaufzeichnungen WHERE benutzer_id=? AND anwesenheits_datum=?)");
    $ins_pause  = null;
    $start_fixed = '08:00:00';
    if ($abzug_sek > 0) {
        $ins_pause = mysqli_prepare($link,
            "INSERT INTO pausen (benutzer_id, anwesenheit_id, start_pause, ende_pause) VALUES (?,?,?,?)");
    }
    $cur = strtotime($von_datum);
    $end = strtotime($bis_datum);
    while ($cur <= $end) {
        $datum = date('Y-m-d', $cur);
        if ((int)date('N', $cur) <= 5 && !isset($feiertage_abs[$datum])) {
            mysqli_stmt_bind_param($ins, "issssis", $bid, $datum, $start_fixed, $end_time, $brutto_fmt, $bid, $datum);
            mysqli_stmt_execute($ins);
            if ($ins_pause) {
                $new_aid = (int)mysqli_insert_id($link);
                if ($new_aid > 0) {
                    $ps = $datum . ' 12:00:00'; $pe = $datum . ' 12:30:00';
                    mysqli_stmt_bind_param($ins_pause, "iiss", $bid, $new_aid, $ps, $pe);
                    mysqli_stmt_execute($ins_pause);
                }
            }
        }
        $cur = strtotime('+1 day', $cur);
    }
    mysqli_stmt_close($ins);
    if ($ins_pause) mysqli_stmt_close($ins_pause);
}

// --- AKTIONEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve' || $action === 'reject') {
        $aid    = (int)$_POST['abwesenheit_id'];
        $status = $action === 'approve' ? 'Genehmigt' : 'Abgelehnt';
        $st = mysqli_prepare($link, "UPDATE abwesenheiten SET status=? WHERE abwesenheit_id=?");
        mysqli_stmt_bind_param($st, "si", $status, $aid);
        mysqli_stmt_execute($st); mysqli_stmt_close($st);

        $abs_typ_check = mysqli_fetch_assoc(mysqli_query($link, "SELECT abwesenheit_typ FROM abwesenheiten WHERE abwesenheit_id=$aid"))['abwesenheit_typ'] ?? '';
        if ($action === 'approve' && $abs_typ_check !== 'Verspätung') {
            createWorkEntries($link, $aid);
        }
        $success = "Status auf '$status' gesetzt.";
    }

    if ($action === 'delete') {
        $aid = (int)$_POST['abwesenheit_id'];
        // Zuerst Daten holen um Arbeitseinträge mitlöschen zu können
        $abs_del = mysqli_fetch_assoc(mysqli_query($link,
            "SELECT benutzer_id, abwesenheit_beginn, abwesenheit_ende, abwesenheit_typ
             FROM abwesenheiten WHERE abwesenheit_id=$aid"));
        // Abwesenheit löschen
        $st = mysqli_prepare($link, "DELETE FROM abwesenheiten WHERE abwesenheit_id=?");
        mysqli_stmt_bind_param($st, "i", $aid);
        mysqli_stmt_execute($st); mysqli_stmt_close($st);
        // Dazugehörige Arbeitseinträge löschen (nur wenn Abwesenheit work entries hatte)
        if ($abs_del && $abs_del['abwesenheit_typ'] !== 'Verspätung') {
            $bid_del = (int)$abs_del['benutzer_id'];
            $cur_del = strtotime($abs_del['abwesenheit_beginn']);
            $end_del = strtotime($abs_del['abwesenheit_ende']);
            while ($cur_del <= $end_del) {
                $d = date('Y-m-d', $cur_del);
                if ((int)date('N', $cur_del) <= 5) {
                    $st_del = mysqli_prepare($link,
                        "DELETE FROM anwesenheitsaufzeichnungen WHERE benutzer_id=? AND anwesenheits_datum=?");
                    mysqli_stmt_bind_param($st_del, "is", $bid_del, $d);
                    mysqli_stmt_execute($st_del); mysqli_stmt_close($st_del);
                }
                $cur_del = strtotime('+1 day', $cur_del);
            }
        }
        $success = "Eintrag gelöscht.";
    }

    if ($action === 'create') {
        $bid    = (int)$_POST['benutzer_id'];
        $beginn = trim($_POST['abwesenheit_beginn'] ?? '');
        $ende   = trim($_POST['abwesenheit_ende'] ?? '');
        $typ    = trim($_POST['abwesenheit_typ'] ?? '');
        $status = 'Genehmigt';

        $create_error = '';
        if (!$typ) $create_error = "Bitte Typ auswählen.";
        if ($typ === 'Persönlicher Feiertag') {
            if ($beginn !== $ende) {
                $create_error = "Persönlicher Feiertag kann nur 1 Tag dauern.";
            } else {
                $pf_jahr = (int)date('Y', strtotime($beginn));
                $pf_chk = mysqli_prepare($link, "SELECT COUNT(*) AS cnt FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Persönlicher Feiertag' AND YEAR(abwesenheit_beginn)=? AND status != 'Abgelehnt'");
                mysqli_stmt_bind_param($pf_chk, "ii", $bid, $pf_jahr);
                mysqli_stmt_execute($pf_chk);
                if ((int)mysqli_stmt_get_result($pf_chk)->fetch_assoc()['cnt'] > 0) {
                    $create_error = "Dieser Mitarbeiter hat für $pf_jahr bereits einen Persönlichen Feiertag.";
                }
            }
        }

        $verspaetungszeit = null;
        if (!$create_error && $typ === 'Verspätung') {
            $vt = trim($_POST['verspaetungszeit'] ?? '');
            if (!$vt) {
                $create_error = "Bitte Ankommenszeit angeben.";
            } else {
                $ankunft_sek = strtotime("1970-01-01 $vt:00");
                $start_sek   = strtotime("1970-01-01 08:00:00");
                $late_sek    = $ankunft_sek - $start_sek;
                if ($late_sek <= 0) {
                    $create_error = "Ankommenszeit muss nach 08:00 liegen.";
                } else {
                    $verspaetungszeit = sprintf('%02d:%02d:00', floor($late_sek/3600), floor(($late_sek%3600)/60));
                    $ende = $beginn;
                }
            }
        }

        if (!$create_error) {
            $ov_st = mysqli_prepare($link,
                "SELECT abwesenheit_typ, abwesenheit_beginn, abwesenheit_ende
                 FROM abwesenheiten
                 WHERE benutzer_id=? AND status != 'Abgelehnt'
                   AND abwesenheit_beginn <= ? AND abwesenheit_ende >= ?
                 LIMIT 1");
            mysqli_stmt_bind_param($ov_st, "iss", $bid, $ende, $beginn);
            mysqli_stmt_execute($ov_st);
            $ov_row = mysqli_stmt_get_result($ov_st)->fetch_assoc();
            mysqli_stmt_close($ov_st);
            if ($ov_row) {
                $create_error = "Überschneidung: Bereits "
                    . $ov_row['abwesenheit_typ'] . " vom "
                    . date('d.m.Y', strtotime($ov_row['abwesenheit_beginn'])) . " bis "
                    . date('d.m.Y', strtotime($ov_row['abwesenheit_ende'])) . " vorhanden.";
            }
        }

        if ($create_error) {
            $error_msg = $create_error;
        } else {
            $st = mysqli_prepare($link, "INSERT INTO abwesenheiten (benutzer_id, abwesenheit_beginn, abwesenheit_ende, abwesenheit_typ, status, verspätungszeit) VALUES (?,?,?,?,?,?)");
            mysqli_stmt_bind_param($st, "isssss", $bid, $beginn, $ende, $typ, $status, $verspaetungszeit);
            mysqli_stmt_execute($st);
            $new_abs_id = (int)mysqli_insert_id($link);
            mysqli_stmt_close($st);
            // Arbeitseinträge automatisch anlegen (wie beim Approve), außer bei Verspätung
            if ($typ !== 'Verspätung') {
                createWorkEntries($link, $new_abs_id);
            }
            $success = "Abwesenheit erstellt.";
        }
    }

    if ($error_msg) {
        header("Location: admin_abwesenheiten.php?err=" . urlencode($error_msg)); exit;
    }
    header("Location: admin_abwesenheiten.php?msg=" . urlencode($success)); exit;
}
if (isset($_GET['msg'])) $success   = htmlspecialchars($_GET['msg']);
if (isset($_GET['err'])) $error_msg = htmlspecialchars($_GET['err']);

// Filter
$filter_user   = (int)($_GET['filter_user'] ?? 0);
$filter_status = $_GET['filter_status'] ?? '';
$filter_typ    = $_GET['filter_typ'] ?? '';
$filter_von    = $_GET['filter_von'] ?? '';
$filter_bis    = $_GET['filter_bis'] ?? '';
$page_num      = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;
$offset        = ($page_num - 1) * $per_page;

$where = "WHERE 1=1";
$params = []; $types = "";

if ($filter_user)   { $where .= " AND a.benutzer_id=?"; $params[] = $filter_user; $types .= "i"; }
if ($filter_status) { $where .= " AND a.status=?"; $params[] = $filter_status; $types .= "s"; }
if ($filter_typ)    { $where .= " AND a.abwesenheit_typ=?"; $params[] = $filter_typ; $types .= "s"; }
if ($filter_von)    { $where .= " AND a.abwesenheit_beginn >= ?"; $params[] = $filter_von; $types .= "s"; }
if ($filter_bis)    { $where .= " AND a.abwesenheit_ende <= ?"; $params[] = $filter_bis; $types .= "s"; }

$cnt_st = mysqli_prepare($link, "SELECT COUNT(*) as cnt FROM abwesenheiten a JOIN benutzer b ON a.benutzer_id=b.benutzer_id $where");
if ($params) mysqli_stmt_bind_param($cnt_st, $types, ...$params);
mysqli_stmt_execute($cnt_st);
$total = mysqli_stmt_get_result($cnt_st)->fetch_assoc()['cnt'];
$total_pages = ceil($total / $per_page);

$sql = "SELECT a.*, b.name,
               DATEDIFF(a.abwesenheit_ende, a.abwesenheit_beginn)+1 as tage
        FROM abwesenheiten a
        JOIN benutzer b ON a.benutzer_id = b.benutzer_id
        $where ORDER BY a.abwesenheit_beginn DESC
        LIMIT ? OFFSET ?";
$st = mysqli_prepare($link, $sql);
$p2 = array_merge($params, [$per_page, $offset]);
$t2 = $types . "ii";
mysqli_stmt_bind_param($st, $t2, ...$p2);
mysqli_stmt_execute($st);
$antraege = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

$feiertage_set = array_column(
    mysqli_fetch_all(mysqli_query($link, "SELECT datum FROM feiertage"), MYSQLI_ASSOC),
    'datum'
);

function countWorkdays(string $von, string $bis, array $feiertage = []): int {
    $count = 0;
    $cur = strtotime($von);
    $end = strtotime($bis);
    while ($cur <= $end) {
        $ds = date('Y-m-d', $cur);
        if ((int)date('N', $cur) <= 5 && !in_array($ds, $feiertage)) $count++;
        $cur = strtotime('+1 day', $cur);
    }
    return $count;
}

$alle_benutzer = mysqli_fetch_all(mysqli_query($link, "SELECT benutzer_id, name FROM benutzer ORDER BY name"), MYSQLI_ASSOC);
$wartungen = mysqli_fetch_all(mysqli_query($link, "SELECT beschreibung FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"), MYSQLI_ASSOC);
$session_id = (int)$_SESSION['id'];
$st_n = mysqli_prepare($link, "SELECT n.benachrichtigung_id, n.nachricht, n.zeitstempel, n.gelesen, b.name AS von_name FROM benachrichtigungen n LEFT JOIN benutzer b ON n.von_benutzer_id = b.benutzer_id WHERE n.benutzer_id=? AND n.zeitstempel >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY n.zeitstempel DESC LIMIT 20");
mysqli_stmt_bind_param($st_n, "i", $session_id);
mysqli_stmt_execute($st_n);
$nachrichten_user = mysqli_fetch_all(mysqli_stmt_get_result($st_n), MYSQLI_ASSOC);
mysqli_stmt_close($st_n);
$nachrichten_count = count(array_filter($nachrichten_user, fn($n) => !$n['gelesen']));

// Stats
$stats = mysqli_fetch_all(mysqli_query($link, "SELECT status, COUNT(*) as cnt FROM abwesenheiten GROUP BY status"), MYSQLI_ASSOC);
$stats_map = [];
foreach ($stats as $s) $stats_map[$s['status']] = $s['cnt'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Abwesenheiten | Admin</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.admin-subnav{background:#111;border-bottom:1px solid #333;padding:0 30px;display:flex;gap:5px;}
.admin-subnav a{color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;}
.admin-subnav a:hover{color:#fff;}.admin-subnav a.active{color:#007bff;border-bottom-color:#007bff;}
.btn-sm{padding:5px 12px;border-radius:6px;border:1px solid #555;background:none;color:#fff;cursor:pointer;font-size:0.8rem;}
.btn-sm:hover{background:#333;}
.btn-danger{border-color:#ff4d4d;color:#ff4d4d;}.btn-danger:hover{background:#ff4d4d;color:#fff;}
.btn-primary{border-color:#007bff;color:#007bff;}.btn-primary:hover{background:#007bff;color:#fff;}
.btn-approve{border-color:#2ecc71;color:#2ecc71;}.btn-approve:hover{background:#2ecc71;color:#000;}
.btn-reject{border-color:#ff4d4d;color:#ff4d4d;}.btn-reject:hover{background:#ff4d4d;color:#fff;}
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
.btn-cancel{background:#333;color:#aaa;}.btn-save{background:#007bff;color:#fff;}
.alert-success{background:rgba(46,204,113,0.15);border:1px solid #2ecc71;color:#2ecc71;padding:12px 16px;border-radius:8px;margin-bottom:20px;}
.alert-error{background:rgba(255,77,77,0.15);border:1px solid #ff4d4d;color:#ff4d4d;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600;}
.badge-Ausstehend{background:rgba(255,204,0,0.15);color:#ffcc00;border:1px solid #ffcc00;}
.badge-Genehmigt{background:rgba(46,204,113,0.15);color:#2ecc71;border:1px solid #2ecc71;}
.badge-Abgelehnt{background:rgba(255,77,77,0.15);color:#ff4d4d;border:1px solid #ff4d4d;}
.status-pill{padding:3px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;}
.summary-row{display:flex;gap:16px;margin-bottom:20px;}
.summary-box{background:#2d2d2d;border:1px solid #444;border-radius:10px;padding:14px 20px;flex:1;text-align:center;}
.summary-box .val{font-size:1.6rem;font-weight:bold;color:#fff;}
.summary-box .lbl{font-size:0.8rem;color:#888;text-transform:uppercase;}
.pagination{display:flex;gap:6px;margin-top:20px;justify-content:center;}
.pagination a,.pagination span{padding:6px 12px;border-radius:6px;border:1px solid #444;color:#aaa;text-decoration:none;font-size:0.85rem;}
.pagination a:hover{background:#333;color:#fff;}.pagination .current{background:#007bff;color:#fff;border-color:#007bff;}
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
    <a href="admin_abwesenheiten.php" class="active">Abwesenheiten</a>
    <a href="admin_sicherheit.php">Sicherheit</a>
    <a href="admin_benachrichtigungen.php">Benachrichtigungen</a>
    <a href="admin_berichte.php">Berichte</a>
    <a href="admin_wartung.php">Wartung</a>
</div>

<div class="container">
<div class="page-width">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h1 class="page-title" style="margin:0;">Abwesenheiten</h1>
        <button class="btn-sm btn-primary" onclick="openModal('modal-create')"><i class="fas fa-plus"></i> Neu</button>
    </div>

    <?php if ($error_msg): ?><div class="alert-error">❌ <?php echo $error_msg; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success"><?php echo $success; ?></div><?php endif; ?>


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
            <label>Status
                <select name="filter_status">
                    <option value="">Alle</option>
                    <option value="Ausstehend" <?php echo $filter_status=='Ausstehend'?'selected':''; ?>>Ausstehend</option>
                    <option value="Genehmigt" <?php echo $filter_status=='Genehmigt'?'selected':''; ?>>Genehmigt</option>
                    <option value="Abgelehnt" <?php echo $filter_status=='Abgelehnt'?'selected':''; ?>>Abgelehnt</option>
                </select>
            </label>
            <label>Typ
                <select name="filter_typ">
                    <option value="">Alle</option>
                    <option value="Urlaub" <?php echo $filter_typ=='Urlaub'?'selected':''; ?>>Urlaub</option>
                    <option value="Krank" <?php echo $filter_typ=='Krank'?'selected':''; ?>>Krank</option>
                    <option value="Pflegeurlaub" <?php echo $filter_typ=='Pflegeurlaub'?'selected':''; ?>>Pflegeurlaub</option>
                    <option value="Zeitausgleich" <?php echo $filter_typ=='Zeitausgleich'?'selected':''; ?>>Zeitausgleich</option>
                    <option value="Verspätung" <?php echo $filter_typ=='Verspätung'?'selected':''; ?>>Verspätung</option>
                    <option value="Persönlicher Feiertag" <?php echo $filter_typ=='Persönlicher Feiertag'?'selected':''; ?>>Persönlicher Feiertag</option>
                    <option value="Sonstiges" <?php echo $filter_typ=='Sonstiges'?'selected':''; ?>>Sonstiges</option>
                </select>
            </label>
            <label>Von<input type="date" name="filter_von" value="<?php echo htmlspecialchars($filter_von); ?>"></label>
            <label>Bis<input type="date" name="filter_bis" value="<?php echo htmlspecialchars($filter_bis); ?>"></label>
            <button type="submit" class="btn-export"><i class="fas fa-filter"></i> Filtern</button>
            <a href="admin_abwesenheiten.php" style="color:#aaa;text-decoration:none;align-self:flex-end;padding:9px 12px;border:1px solid #444;border-radius:8px;font-size:0.9rem;">Reset</a>
        </form>
    </div>

    <!-- Tabelle -->
    <div class="dashboard-card" style="padding:0;overflow:hidden;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Mitarbeiter</th>
                    <th>Beginn</th>
                    <th>Ende</th>
                    <th>Tage</th>
                    <th>Typ</th>
                    <th>Status</th>
                    <th style="text-align:right;">Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($antraege)): ?>
                <tr><td colspan="7" class="muted-text" style="text-align:center;padding:30px;">Keine Einträge gefunden.</td></tr>
            <?php endif; ?>
            <?php foreach ($antraege as $a):
                $isVz = $a['abwesenheit_typ'] === 'Verspätung';
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($a['name']); ?></strong></td>
                    <td><?php echo date('d.m.Y', strtotime($a['abwesenheit_beginn'])); ?></td>
                    <td><?php echo date('d.m.Y', strtotime($isVz ? $a['abwesenheit_beginn'] : $a['abwesenheit_ende'])); ?></td>
                    <td><?php echo $isVz ? '—' : countWorkdays($a['abwesenheit_beginn'], $a['abwesenheit_ende'], $feiertage_set); ?></td>
                    <td><?php
                        $typ_colors = [
                            'Urlaub'               => '#007bff',
                            'Krank'                => '#ff4d4d',
                            'Pflegeurlaub'         => '#e67e22',
                            'Zeitausgleich'        => '#6bc5f8',
                            'Verspätung'           => '#ff8800',
                            'Persönlicher Feiertag'=> '#ffcc00',
                            'Sonstiges'            => '#888',
                        ];
                        $tc = $typ_colors[$a['abwesenheit_typ']] ?? '#888';
                        echo '<span style="color:'.$tc.';font-weight:600;">'.htmlspecialchars($a['abwesenheit_typ']).'</span>';
                        if ($isVz && !empty($a['verspätungszeit'])) {
                            $vp2 = explode(':', $a['verspätungszeit']);
                            $ak_sek = 8*3600 + ($vp2[0]*3600)+($vp2[1]*60);
                            $ak_fmt = sprintf('%02d:%02d', floor($ak_sek/3600), floor(($ak_sek%3600)/60));
                            echo ' <span style="color:#ff8800;font-size:0.78rem;">(08:00 – '.$ak_fmt.')</span>';
                        }
                    ?></td>
                    <td><span class="status-pill badge-<?php echo htmlspecialchars($a['status']); ?>"><?php echo htmlspecialchars($a['status']); ?></span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <?php if ($a['status'] === 'Ausstehend'): ?>
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="abwesenheit_id" value="<?php echo (int)$a['abwesenheit_id']; ?>">
                            <button type="submit" class="btn-sm btn-approve" title="Genehmigen"><i class="fas fa-check"></i></button>
                        </form>
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="abwesenheit_id" value="<?php echo (int)$a['abwesenheit_id']; ?>">
                            <button type="submit" class="btn-sm btn-reject" title="Ablehnen"><i class="fas fa-times"></i></button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Eintrag löschen?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="abwesenheit_id" value="<?php echo (int)$a['abwesenheit_id']; ?>">
                            <button type="submit" class="btn-sm btn-danger" title="Löschen"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination" style="padding:16px;">
            <?php $q = http_build_query(['filter_user'=>$filter_user,'filter_status'=>$filter_status,'filter_typ'=>$filter_typ,'filter_von'=>$filter_von,'filter_bis'=>$filter_bis]); ?>
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
    <h3><i class="fas fa-calendar-plus" style="color:#007bff;"></i> Abwesenheit eintragen</h3>
    <form method="POST">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="status" value="Genehmigt">

        <!-- Zeile 1: Mitarbeiter | Typ -->
        <div class="form-row">
            <div class="form-group">
                <label>Mitarbeiter</label>
                <select name="benutzer_id" required>
                    <?php foreach ($alle_benutzer as $u): ?>
                        <option value="<?php echo $u['benutzer_id']; ?>"><?php echo htmlspecialchars($u['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Typ</label>
                <select name="abwesenheit_typ" id="admin-typ-sel" required>
                    <option value="Urlaub">🏖️ Urlaub</option>
                    <option value="Krank">🤒 Krank</option>
                    <option value="Pflegeurlaub">👶 Pflegeurlaub</option>
                    <option value="Zeitausgleich">⏱️ Zeitausgleich</option>
                    <option value="Verspätung">⏰ Verspätung</option>
                    <option value="Persönlicher Feiertag">🎉 Persönlicher Feiertag</option>
                    <option value="Sonstiges">📋 Sonstiges</option>
                </select>
            </div>
        </div>

        <!-- Zeile 2: Beginn/Ankommenszeit | Ende/Datum -->
        <div class="form-row" style="align-items:flex-end;">
            <div class="form-group" id="admin-von-group">
                <label id="admin-label-von">Beginn</label>
                <input type="date" name="abwesenheit_beginn" id="admin-beginn" required>
            </div>
            <div class="form-group" id="admin-vz-group" style="display:none;">
                <label>Ankommenszeit <span style="color:#aaa;font-size:0.78rem;font-weight:400;">(Verspätungszeit wird ab 08:00 berechnet)</span></label>
                <input type="time" name="verspaetungszeit" id="admin-vz-time" step="60"
                       style="background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;width:100%;box-sizing:border-box;">
            </div>
            <div class="form-group" id="admin-bis-group">
                <label id="admin-label-bis">Ende</label>
                <input type="date" name="abwesenheit_ende" id="admin-ende" required>
            </div>
        </div>

        <!-- Hinweis für 1-Tages-Typen -->
        <div id="admin-pf-hint" style="display:none;background:rgba(255,204,0,0.08);border:1px solid #a07800;border-radius:8px;padding:10px 14px;font-size:0.83rem;color:#ffcc00;margin-bottom:14px;">
            🎉 Max. 1 Tag — Ende wird automatisch = Beginn gesetzt.
        </div>

        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-create')">Abbrechen</button>
            <button type="submit" class="btn-save">Erstellen</button>
        </div>
    </form>
</div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(el=>{
    el.addEventListener('click',function(e){if(e.target===el)el.classList.remove('open');});
});

const adminTypSel  = document.getElementById('admin-typ-sel');
const adminBeginn  = document.getElementById('admin-beginn');
const adminEnde    = document.getElementById('admin-ende');
const adminVonGrp  = document.getElementById('admin-von-group');
const adminVzGrp   = document.getElementById('admin-vz-group');
const adminBisGrp  = document.getElementById('admin-bis-group');
const adminPfHint  = document.getElementById('admin-pf-hint');
const adminLabelBis= document.getElementById('admin-label-bis');

function onAdminTypChange() {
    const typ  = adminTypSel.value;
    const isPF = typ === 'Persönlicher Feiertag';
    const isVz = typ === 'Verspätung';

    // Verspätung: links = Datum (Beginn), rechts = Ankommenszeit
    adminVonGrp.style.display  = '';
    adminVzGrp.style.display   = isVz ? '' : 'none';
    adminBisGrp.style.display  = isVz ? 'none' : '';
    adminPfHint.style.display  = isPF ? 'block' : 'none';
    adminLabelBis.textContent  = 'Ende';

    if (isVz) {
        // Datum-Label anpassen, Ende = Beginn (1 Tag)
        adminEnde.value    = adminBeginn.value;
        adminEnde.readOnly = false;
    } else if (isPF) {
        adminEnde.readOnly    = true;
        adminEnde.style.opacity = '0.5';
        adminEnde.value       = adminBeginn.value;
    } else {
        adminEnde.readOnly    = false;
        adminEnde.style.opacity = '1';
    }
}

function syncAdminEnde() {
    const typ = adminTypSel.value;
    if (typ === 'Persönlicher Feiertag' || typ === 'Verspätung') {
        adminEnde.value = adminBeginn.value;
    }
}

adminTypSel.addEventListener('change', onAdminTypChange);
adminBeginn.addEventListener('change', syncAdminEnde);
onAdminTypChange();
</script>
</body>
</html>
