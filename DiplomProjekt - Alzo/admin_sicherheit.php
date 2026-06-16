<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    header("location: index.php"); exit;
}
$benutzer_name = $_SESSION['username'];
$rolle = $_SESSION['rolle'];
$success = '';

function _delete_reset_notification($link, $antrag_id) {
    $st = mysqli_prepare($link, "SELECT b.name FROM passwort_reset_antraege pra JOIN benutzer b ON pra.benutzer_id=b.benutzer_id WHERE pra.antrag_id=?");
    mysqli_stmt_bind_param($st, "i", $antrag_id);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    if (!$row) return;
    $nachricht = "Passwort-Reset-Antrag von " . $row['name'] . " wartet auf Genehmigung.";
    $st2 = mysqli_prepare($link, "DELETE FROM benachrichtigungen WHERE nachricht=?");
    mysqli_stmt_bind_param($st2, "s", $nachricht);
    mysqli_stmt_execute($st2);
    mysqli_stmt_close($st2);
}

// --- AKTIONEN: Passwort-Reset-Anträge ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $aid    = (int)($_POST['antrag_id'] ?? 0);

    if ($action === 'approve_reset' && $aid) {
        $token = bin2hex(random_bytes(32));
        $st = mysqli_prepare($link, "UPDATE passwort_reset_antraege SET status='Genehmigt', token=? WHERE antrag_id=?");
        mysqli_stmt_bind_param($st, "si", $token, $aid);
        mysqli_stmt_execute($st); mysqli_stmt_close($st);
        _delete_reset_notification($link, $aid);
        $success = "Reset-Antrag genehmigt.";
    }

    if ($action === 'reject_reset' && $aid) {
        $st = mysqli_prepare($link, "UPDATE passwort_reset_antraege SET status='Abgelehnt' WHERE antrag_id=?");
        mysqli_stmt_bind_param($st, "i", $aid);
        mysqli_stmt_execute($st); mysqli_stmt_close($st);
        _delete_reset_notification($link, $aid);
        $success = "Reset-Antrag abgelehnt.";
    }

    header("Location: admin_sicherheit.php?msg=" . urlencode($success)); exit;
}
if (isset($_GET['msg'])) $success = htmlspecialchars($_GET['msg']);

// Passwort-Reset-Anträge
$st_reset = mysqli_prepare($link,
    "SELECT pra.*, b.name, ld.benutzername
     FROM passwort_reset_antraege pra
     JOIN benutzer b ON pra.benutzer_id = b.benutzer_id
     LEFT JOIN login_daten ld ON pra.benutzer_id = ld.benutzer_id
     ORDER BY pra.zeitstempel DESC LIMIT 5");
mysqli_stmt_execute($st_reset);
$reset_antraege = mysqli_fetch_all(mysqli_stmt_get_result($st_reset), MYSQLI_ASSOC);
mysqli_stmt_close($st_reset);

// Letzte Logins
$letzte_logins = mysqli_fetch_all(mysqli_query($link,
    "SELECT b.name, ld.benutzername, ld.letzter_login, bp.rolle
     FROM login_daten ld
     JOIN benutzer b ON ld.benutzer_id = b.benutzer_id
     LEFT JOIN benutzerprofile bp ON ld.benutzer_id = bp.benutzer_id
     ORDER BY ld.letzter_login DESC"
), MYSQLI_ASSOC);

$wartungen = mysqli_fetch_all(mysqli_query($link, "SELECT beschreibung FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"), MYSQLI_ASSOC);
$session_id = (int)$_SESSION['id'];
$st_n = mysqli_prepare($link, "SELECT n.benachrichtigung_id, n.nachricht, n.zeitstempel, n.gelesen, b.name AS von_name FROM benachrichtigungen n LEFT JOIN benutzer b ON n.von_benutzer_id = b.benutzer_id WHERE n.benutzer_id=? AND n.zeitstempel >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY n.zeitstempel DESC LIMIT 20");
mysqli_stmt_bind_param($st_n, "i", $session_id);
mysqli_stmt_execute($st_n);
$nachrichten_user = mysqli_fetch_all(mysqli_stmt_get_result($st_n), MYSQLI_ASSOC);
mysqli_stmt_close($st_n);
$nachrichten_count = count(array_filter($nachrichten_user, fn($n) => !$n['gelesen']));

// Statistik
$pending_count = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) as cnt FROM passwort_reset_antraege WHERE status='Ausstehend'"))['cnt'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Sicherheit | Admin</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.admin-subnav{background:#111;border-bottom:1px solid #333;padding:0 30px;display:flex;gap:5px;}
.admin-subnav a{color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;}
.admin-subnav a:hover{color:#fff;}.admin-subnav a.active{color:#007bff;border-bottom-color:#007bff;}
.btn-sm{padding:5px 12px;border-radius:6px;border:1px solid #555;background:none;color:#fff;cursor:pointer;font-size:0.8rem;}
.btn-sm:hover{background:#333;}
.btn-approve{border-color:#2ecc71;color:#2ecc71;}.btn-approve:hover{background:#2ecc71;color:#000;}
.btn-reject{border-color:#ff4d4d;color:#ff4d4d;}.btn-reject:hover{background:#ff4d4d;color:#fff;}
.btn-primary{border-color:#007bff;color:#007bff;}.btn-primary:hover{background:#007bff;color:#fff;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#2d2d2d;border:1px solid #444;border-radius:16px;padding:30px;width:420px;max-width:95vw;}
.modal-box h3{margin:0 0 20px;color:#fff;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;color:#aaa;font-size:0.85rem;margin-bottom:5px;}
.form-group input,.form-group select{width:100%;box-sizing:border-box;background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;}
.modal-actions button{padding:9px 20px;border-radius:8px;border:none;cursor:pointer;font-weight:600;}
.btn-cancel{background:#333;color:#aaa;}.btn-save{background:#007bff;color:#fff;}
.alert-success{background:rgba(46,204,113,0.15);border:1px solid #2ecc71;color:#2ecc71;padding:12px 16px;border-radius:8px;margin-bottom:20px;word-break:break-all;}
.badge-Ausstehend{background:rgba(255,204,0,0.15);color:#ffcc00;border:1px solid #ffcc00;}
.badge-Genehmigt{background:rgba(46,204,113,0.15);color:#2ecc71;border:1px solid #2ecc71;}
.badge-Abgelehnt{background:rgba(255,77,77,0.15);color:#ff4d4d;border:1px solid #ff4d4d;}
.status-pill{padding:3px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;}
.summary-row{display:flex;gap:16px;margin-bottom:25px;}
.summary-box{background:#2d2d2d;border:1px solid #444;border-radius:10px;padding:14px 20px;flex:1;text-align:center;}
.summary-box .val{font-size:1.6rem;font-weight:bold;color:#fff;}
.summary-box .lbl{font-size:0.8rem;color:#888;text-transform:uppercase;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:25px;}
@media(max-width:900px){.two-col{grid-template-columns:1fr;}}
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
    <a href="admin_sicherheit.php" class="active">Sicherheit</a>
    <a href="admin_benachrichtigungen.php">Benachrichtigungen</a>
    <a href="admin_berichte.php">Berichte</a>
    <a href="admin_wartung.php">Wartung</a>
</div>

<div class="container">
<div class="page-width">
    <h1 class="page-title">Login & Sicherheit</h1>

    <?php if ($success): ?><div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>

    <div class="two-col">
        <!-- RESET-ANTRÄGE -->
        <div class="dashboard-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <h3 class="section-title" style="margin:0;"><i class="fas fa-key" style="color:#ffcc00;"></i> Passwort-Reset-Anträge</h3>
                <?php if ($pending_count > 0): ?>
                    <span style="background:rgba(255,204,0,0.15);color:#ffcc00;border:1px solid #ffcc00;padding:3px 12px;border-radius:12px;font-size:0.82rem;font-weight:600;"><?php echo $pending_count; ?> offen</span>
                <?php endif; ?>
            </div>
            <table class="admin-table">
                <thead><tr><th>Benutzer</th><th>Zeitstempel</th><th>Status</th><th style="text-align:right;">Aktion</th></tr></thead>
                <tbody>
                <?php if (empty($reset_antraege)): ?>
                    <tr><td colspan="4" class="muted-text" style="text-align:center;padding:20px;">Keine Anträge.</td></tr>
                <?php endif; ?>
                <?php foreach ($reset_antraege as $r): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                            <div style="font-size:0.8rem;color:#888;"><?php echo htmlspecialchars($r['benutzername'] ?? ''); ?></div>
                        </td>
                        <td style="font-size:0.82rem;">
                            <?php echo date('d.m.Y H:i', strtotime($r['zeitstempel'])); ?>
                        </td>
                        <td><span class="status-pill badge-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <?php if ($r['status'] === 'Ausstehend'): ?>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="approve_reset">
                                <input type="hidden" name="antrag_id" value="<?php echo (int)$r['antrag_id']; ?>">
                                <button type="submit" class="btn-sm btn-approve" title="Genehmigen"><i class="fas fa-check"></i></button>
                            </form>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="reject_reset">
                                <input type="hidden" name="antrag_id" value="<?php echo (int)$r['antrag_id']; ?>">
                                <button type="submit" class="btn-sm btn-reject" title="Ablehnen"><i class="fas fa-times"></i></button>
                            </form>
                            <?php else: ?>
                                <span style="color:#555;font-size:0.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- LETZTE LOGINS -->
        <div class="dashboard-card">
            <h3 class="section-title"><i class="fas fa-sign-in-alt" style="color:#007bff;"></i> Letzte Logins</h3>
            <table class="admin-table">
                <thead><tr><th>Name</th><th>Rolle</th><th>Letzter Login</th></tr></thead>
                <tbody>
                <?php foreach ($letzte_logins as $l): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($l['name']); ?></strong></td>
                        <td><span style="font-size:0.8rem;color:#aaa;"><?php echo htmlspecialchars($l['rolle'] ?? '-'); ?></span></td>
                        <td style="font-size:0.82rem;">
                            <?php if ($l['letzter_login']): ?>
                                <?php echo date('d.m.Y H:i', strtotime($l['letzter_login'])); ?>
                            <?php else: ?>
                                <span style="color:#555;">Nie</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(el=>{el.addEventListener('click',function(e){if(e.target===el)el.classList.remove('open');});});
</script>
</body>
</html>
