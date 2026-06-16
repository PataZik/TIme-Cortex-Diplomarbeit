<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    header("location: index.php"); exit;
}
$benutzer_name = $_SESSION['username'];
$rolle = $_SESSION['rolle'];
$success = '';

// --- AKTIONEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $nachricht    = trim($_POST['nachricht'] ?? '');
        $empfaenger   = $_POST['empfaenger'] ?? 'alle';
        $zeitstempel  = date('Y-m-d H:i:s');
        $von_id       = (int)$_SESSION['id'];

        if ($nachricht) {
            if ($empfaenger === 'alle') {
                $res = mysqli_query($link, "SELECT benutzer_id FROM benutzer");
                while ($row = mysqli_fetch_assoc($res)) {
                    $bid = $row['benutzer_id'];
                    $st = mysqli_prepare($link, "INSERT INTO benachrichtigungen (benutzer_id, nachricht, zeitstempel, status, von_benutzer_id) VALUES (?,?,?,'Gesendet',?)");
                    mysqli_stmt_bind_param($st, "issi", $bid, $nachricht, $zeitstempel, $von_id);
                    mysqli_stmt_execute($st); mysqli_stmt_close($st);
                }
                $success = "Nachricht an alle Benutzer gesendet.";
            } else {
                $bid = (int)$empfaenger;
                $st = mysqli_prepare($link, "INSERT INTO benachrichtigungen (benutzer_id, nachricht, zeitstempel, status, von_benutzer_id) VALUES (?,?,?,'Gesendet',?)");
                mysqli_stmt_bind_param($st, "issi", $bid, $nachricht, $zeitstempel, $von_id);
                mysqli_stmt_execute($st); mysqli_stmt_close($st);
                $success = "Nachricht gesendet.";
            }
        }
    }

    if ($action === 'delete') {
        $nid = (int)$_POST['benachrichtigung_id'];
        $st = mysqli_prepare($link, "DELETE FROM benachrichtigungen WHERE benachrichtigung_id=?");
        mysqli_stmt_bind_param($st, "i", $nid);
        mysqli_stmt_execute($st); mysqli_stmt_close($st);
        $success = "Benachrichtigung gelöscht.";
    }

    header("Location: admin_benachrichtigungen.php?msg=" . urlencode($success)); exit;
}
if (isset($_GET['msg'])) $success = htmlspecialchars($_GET['msg']);

// Filter
$filter_user   = (int)($_GET['filter_user'] ?? 0);
$filter_status = $_GET['filter_status'] ?? '';
$page_num      = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;
$offset        = ($page_num - 1) * $per_page;

$where = "WHERE 1=1";
$params = []; $types = "";
if ($filter_user)   { $where .= " AND n.benutzer_id=?"; $params[] = $filter_user; $types .= "i"; }
if ($filter_status) { $where .= " AND n.status=?"; $params[] = $filter_status; $types .= "s"; }

$cnt_st = mysqli_prepare($link, "SELECT COUNT(*) as cnt FROM benachrichtigungen n $where");
if ($params) mysqli_stmt_bind_param($cnt_st, $types, ...$params);
mysqli_stmt_execute($cnt_st);
$total = mysqli_stmt_get_result($cnt_st)->fetch_assoc()['cnt'];
$total_pages = ceil($total / $per_page);

$sql = "SELECT n.*, b.name
        FROM benachrichtigungen n
        JOIN benutzer b ON n.benutzer_id = b.benutzer_id
        $where ORDER BY n.zeitstempel DESC LIMIT ? OFFSET ?";
$st = mysqli_prepare($link, $sql);
$p2 = array_merge($params, [$per_page, $offset]);
$t2 = $types . "ii";
mysqli_stmt_bind_param($st, $t2, ...$p2);
mysqli_stmt_execute($st);
$nachrichten = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

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
<title>Benachrichtigungen | Admin</title>
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
.alert-success{background:rgba(46,204,113,0.15);border:1px solid #2ecc71;color:#2ecc71;padding:12px 16px;border-radius:8px;margin-bottom:20px;}
.summary-row{display:flex;gap:16px;margin-bottom:25px;}
.summary-box{background:#2d2d2d;border:1px solid #444;border-radius:10px;padding:14px 20px;flex:1;text-align:center;}
.summary-box .val{font-size:1.6rem;font-weight:bold;color:#fff;}
.summary-box .lbl{font-size:0.8rem;color:#888;text-transform:uppercase;}
.send-card{background:#2d2d2d;border:1px solid #444;border-radius:16px;padding:25px;margin-bottom:25px;}
.send-card h3{margin:0 0 18px;color:#fff;font-size:1rem;display:flex;align-items:center;gap:8px;}
.send-form{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.send-form label{display:flex;flex-direction:column;gap:5px;font-size:0.85rem;color:#aaa;}
.send-form select,.send-form textarea{background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;font-family:inherit;}
.send-form textarea{width:400px;height:70px;resize:vertical;}
.send-form select{min-width:200px;}
.send-form button{padding:10px 22px;background:#007bff;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;}
.send-form button:hover{filter:brightness(1.1);}
.badge-Gesendet{background:rgba(46,204,113,0.15);color:#2ecc71;border:1px solid #2ecc71;}
.badge-Ausstehend{background:rgba(255,204,0,0.15);color:#ffcc00;border:1px solid #ffcc00;}
.badge-Fehlgeschlagen{background:rgba(255,77,77,0.15);color:#ff4d4d;border:1px solid #ff4d4d;}
.status-pill{padding:3px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;}
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
    <a href="admin_abwesenheiten.php">Abwesenheiten</a>
    <a href="admin_sicherheit.php">Sicherheit</a>
    <a href="admin_benachrichtigungen.php" class="active">Benachrichtigungen</a>
    <a href="admin_berichte.php">Berichte</a>
    <a href="admin_wartung.php">Wartung</a>
</div>

<div class="container">
<div class="page-width">
    <h1 class="page-title">Benachrichtigungen</h1>

    <?php if ($success): ?><div class="alert-success"><?php echo $success; ?></div><?php endif; ?>

    <!-- Senden + Filter nebeneinander -->
    <div style="display:flex;gap:25px;margin-bottom:25px;align-items:stretch;">

        <!-- Linke Hälfte: Nachricht senden -->
        <div class="send-card" style="flex:1;margin:0;">
            <h3><i class="fas fa-paper-plane" style="color:#007bff;"></i> Nachricht senden</h3>
            <form method="POST">
                <input type="hidden" name="action" value="send">
                <div style="margin-bottom:12px;">
                    <label style="display:flex;flex-direction:column;gap:5px;font-size:0.85rem;color:#aaa;">Empfänger
                        <select name="empfaenger" style="background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;">
                            <option value="alle">Alle Benutzer</option>
                            <?php foreach ($alle_benutzer as $u): ?>
                                <option value="<?php echo $u['benutzer_id']; ?>"><?php echo htmlspecialchars($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div style="margin-bottom:14px;">
                    <label style="display:flex;flex-direction:column;gap:5px;font-size:0.85rem;color:#aaa;">Nachricht
                        <textarea name="nachricht" placeholder="Nachricht eingeben..." required style="background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;font-family:inherit;height:80px;resize:vertical;width:100%;box-sizing:border-box;"></textarea>
                    </label>
                </div>
                <button type="submit" style="padding:10px 22px;background:#007bff;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;"><i class="fas fa-paper-plane"></i> Senden</button>
            </form>
        </div>

        <!-- Rechte Hälfte: Filter -->
        <div class="dashboard-card" style="flex:1;padding:25px;margin:0;display:flex;flex-direction:column;">
            <h3 style="margin:0 0 18px;color:#fff;font-size:1rem;display:flex;align-items:center;gap:8px;"><i class="fas fa-filter" style="color:#007bff;"></i> Filtern</h3>
            <form method="GET" style="display:flex;flex-direction:column;flex:1;">
                <div style="margin-bottom:12px;">
                    <label style="display:flex;flex-direction:column;gap:5px;font-size:0.85rem;color:#aaa;">Empfänger
                        <select name="filter_user" style="background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;">
                            <option value="0">Alle</option>
                            <?php foreach ($alle_benutzer as $u): ?>
                                <option value="<?php echo $u['benutzer_id']; ?>" <?php echo $filter_user==$u['benutzer_id']?'selected':''; ?>><?php echo htmlspecialchars($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div style="margin-bottom:14px;">
                    <label style="display:flex;flex-direction:column;gap:5px;font-size:0.85rem;color:#aaa;">Status
                        <select name="filter_status" style="background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;">
                            <option value="">Alle</option>
                            <option value="Gesendet" <?php echo $filter_status=='Gesendet'?'selected':''; ?>>Gesendet</option>
                        </select>
                    </label>
                </div>
                <div style="display:flex;gap:8px;margin-top:auto;">
                    <button type="submit" style="padding:9px 18px;background:#007bff;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;"><i class="fas fa-filter"></i> Filtern</button>
                    <a href="admin_benachrichtigungen.php" style="padding:9px 14px;color:#aaa;text-decoration:none;border:1px solid #444;border-radius:8px;font-size:0.9rem;">Reset</a>
                </div>
            </form>
        </div>

    </div>

    <div class="dashboard-card" style="padding:0;overflow:hidden;">
        <table class="admin-table">
            <thead><tr><th>Empfänger</th><th>Nachricht</th><th>Zeitstempel</th><th>Status</th><th style="text-align:right;">Löschen</th></tr></thead>
            <tbody>
            <?php if (empty($nachrichten)): ?>
                <tr><td colspan="5" class="muted-text" style="text-align:center;padding:30px;">Keine Einträge.</td></tr>
            <?php endif; ?>
            <?php foreach ($nachrichten as $n): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($n['name']); ?></strong></td>
                    <td style="max-width:300px;word-break:break-word;"><?php echo htmlspecialchars($n['nachricht']); ?></td>
                    <td style="font-size:0.82rem;white-space:nowrap;"><?php echo date('d.m.Y H:i', strtotime($n['zeitstempel'])); ?></td>
                    <td><span class="status-pill badge-<?php echo htmlspecialchars($n['status']); ?>"><?php echo htmlspecialchars($n['status']); ?></span></td>
                    <td style="text-align:right;">
                        <form method="POST" class="inline-form" onsubmit="return confirm('Löschen?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="benachrichtigung_id" value="<?php echo (int)$n['benachrichtigung_id']; ?>">
                            <button type="submit" class="btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination" style="padding:16px;">
            <?php $q = http_build_query(['filter_user'=>$filter_user,'filter_status'=>$filter_status]); ?>
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
</body>
</html>
