<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    header("location: index.php"); exit;
}
$benutzer_name = $_SESSION['username'];
$rolle = $_SESSION['rolle'];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        $durch_id     = (int)($_POST['durchgefuehrt_von_id'] ?? 0);
        $von_datum    = $_POST['von_datum'] ?? date('Y-m-d');
        $von_zeit     = trim($_POST['von_zeit'] ?? '');
        $bis_datum    = $_POST['bis_datum'] ?? $von_datum;
        $bis_zeit     = trim($_POST['bis_zeit'] ?? '');

        $start = $von_datum . ' ' . ($von_zeit ? $von_zeit . ':00' : '00:00:00');
        $ende  = $bis_datum . ' ' . ($bis_zeit ? $bis_zeit . ':00' : '00:00:00');

        // Vergangenheits-Prüfung nur wenn Zeit angegeben (minutengenau)
        if ($von_zeit) {
            $current_minute = date('Y-m-d H:i') . ':00';
            if ($start < $current_minute) {
                $success = "Fehler: Uhrzeit darf nicht in der Vergangenheit liegen.";
                header("Location: admin_wartung.php?msg=" . urlencode($success)); exit;
            }
        }

        // Durchgeführt von: Name des gewählten Benutzers
        $durch = '';
        if ($durch_id > 0) {
            $st_n = mysqli_prepare($link, "SELECT name FROM benutzer WHERE benutzer_id=?");
            mysqli_stmt_bind_param($st_n, "i", $durch_id);
            mysqli_stmt_execute($st_n);
            $durch = mysqli_stmt_get_result($st_n)->fetch_assoc()['name'] ?? '';
            mysqli_stmt_close($st_n);
        }

        if ($beschreibung) {
            $st = mysqli_prepare($link, "INSERT INTO wartungsprotokolle (start_zeitpunkt, ende_zeitpunkt, beschreibung, durchgefuehrt_von) VALUES (?,?,?,?)");
            mysqli_stmt_bind_param($st, "ssss", $start, $ende, $beschreibung, $durch);
            mysqli_stmt_execute($st); mysqli_stmt_close($st);
            $success = "Wartungsprotokoll erstellt.";
        }
    }

    if ($action === 'edit') {
        $wid          = (int)$_POST['wartung_id'];
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        $durch_id     = (int)($_POST['durchgefuehrt_von_id'] ?? 0);
        $von_datum    = $_POST['von_datum'] ?? date('Y-m-d');
        $von_zeit     = trim($_POST['von_zeit'] ?? '');
        $bis_datum    = $_POST['bis_datum'] ?? $von_datum;
        $bis_zeit     = trim($_POST['bis_zeit'] ?? '');

        $start = $von_datum . ' ' . ($von_zeit ? $von_zeit . ':00' : '00:00:00');
        $ende  = $bis_datum . ' ' . ($bis_zeit ? $bis_zeit . ':00' : '00:00:00');

        $durch = '';
        if ($durch_id > 0) {
            $st_n = mysqli_prepare($link, "SELECT name FROM benutzer WHERE benutzer_id=?");
            mysqli_stmt_bind_param($st_n, "i", $durch_id);
            mysqli_stmt_execute($st_n);
            $durch = mysqli_stmt_get_result($st_n)->fetch_assoc()['name'] ?? '';
            mysqli_stmt_close($st_n);
        }

        if ($beschreibung && $wid) {
            $st = mysqli_prepare($link, "UPDATE wartungsprotokolle SET beschreibung=?, durchgefuehrt_von=?, start_zeitpunkt=?, ende_zeitpunkt=? WHERE wartung_id=?");
            mysqli_stmt_bind_param($st, "ssssi", $beschreibung, $durch, $start, $ende, $wid);
            mysqli_stmt_execute($st); mysqli_stmt_close($st);
            $success = "Eintrag aktualisiert.";
        }
    }

    if ($action === 'delete') {
        $wid = (int)$_POST['wartung_id'];
        $st = mysqli_prepare($link, "DELETE FROM wartungsprotokolle WHERE wartung_id=?");
        mysqli_stmt_bind_param($st, "i", $wid);
        mysqli_stmt_execute($st); mysqli_stmt_close($st);
        $success = "Eintrag gelöscht.";
    }

    header("Location: admin_wartung.php?msg=" . urlencode($success)); exit;
}
if (isset($_GET['msg'])) $success = htmlspecialchars($_GET['msg']);

$page_num = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page_num - 1) * $per_page;
$total = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) as cnt FROM wartungsprotokolle"))['cnt'];
$total_pages = ceil($total / $per_page);

$protokolle = mysqli_fetch_all(mysqli_query($link, sprintf("SELECT * FROM wartungsprotokolle ORDER BY start_zeitpunkt DESC LIMIT %d OFFSET %d", $per_page, $offset)), MYSQLI_ASSOC);


$wartungen_bell = mysqli_fetch_all(mysqli_query($link, "SELECT beschreibung, start_zeitpunkt, ende_zeitpunkt FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"), MYSQLI_ASSOC);
$alle_benutzer_w = mysqli_fetch_all(mysqli_query($link, "SELECT benutzer_id, name FROM benutzer ORDER BY name"), MYSQLI_ASSOC);
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
<title>Wartung | Admin</title>
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
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#2d2d2d;border:1px solid #444;border-radius:16px;padding:30px;width:480px;max-width:95vw;}
.modal-box h3{margin:0 0 20px;color:#fff;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;color:#aaa;font-size:0.85rem;margin-bottom:5px;}
.form-group input,.form-group textarea,.form-group select{width:100%;box-sizing:border-box;background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;font-family:inherit;}
.form-group textarea{height:80px;resize:vertical;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;}
.modal-actions button{padding:9px 20px;border-radius:8px;border:none;cursor:pointer;font-weight:600;}
.btn-cancel{background:#333;color:#aaa;}.btn-save{background:#007bff;color:#fff;}
.pagination{display:flex;gap:6px;margin-top:20px;justify-content:center;}
.pagination a,.pagination span{padding:6px 12px;border-radius:6px;border:1px solid #444;color:#aaa;text-decoration:none;font-size:0.85rem;}
.pagination a:hover{background:#333;color:#fff;}.pagination .current{background:#007bff;color:#fff;border-color:#007bff;}
.sysinfo{background:#1a1a1a;border:1px solid #333;border-radius:10px;padding:16px 20px;margin-bottom:25px;}
.sysinfo-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #2a2a2a;font-size:0.9rem;}
.sysinfo-row:last-child{border-bottom:none;}
.sysinfo-row span:first-child{color:#888;}
.sysinfo-row span:last-child{color:#fff;font-weight:600;}
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
                <?php if (!empty($wartungen_bell)): foreach ($wartungen_bell as $w):
                    $von_str = date('d.m.Y', strtotime($w['start_zeitpunkt']));
                    $bis_str = date('d.m.Y', strtotime($w['ende_zeitpunkt']));
                    $von_t = date('H:i', strtotime($w['start_zeitpunkt']));
                    $bis_t = date('H:i', strtotime($w['ende_zeitpunkt']));
                    $von_disp = $von_str . ($von_t !== '00:00' ? ' ' . $von_t : '');
                    $bis_disp = $bis_str . ($bis_t !== '00:00' ? ' ' . $bis_t : '');
                    $range = $von_str === $bis_str && $von_t === '00:00' && $bis_t === '00:00'
                        ? $von_str
                        : 'von ' . $von_disp . ' bis ' . $bis_disp;
                ?><div class="bell-item"><div><?php echo htmlspecialchars($w['beschreibung']); ?></div><small style="color:#888;"><?php echo $range; ?></small></div><?php endforeach; else: ?><div class="bell-item">Keine geplanten Wartungen</div><?php endif; ?>
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
    <a href="admin_berichte.php">Berichte</a>
    <a href="admin_wartung.php" class="active">Wartung</a>
</div>

<div class="container">
<div class="page-width">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h1 class="page-title" style="margin:0;">Wartung & System</h1>
        <button class="btn-sm btn-primary" onclick="openModal('modal-create')"><i class="fas fa-plus"></i> Log eintragen</button>
    </div>

    <?php if ($success): ?><div class="alert-success"><?php echo $success; ?></div><?php endif; ?>

    <!-- Wartungsprotokolle -->
    <div class="dashboard-card" style="padding:0;overflow:hidden;">
        <div style="padding:20px 25px 0;"><h3 class="section-title"><i class="fas fa-clipboard-list" style="color:#007bff;"></i> Wartungsprotokolle</h3></div>
        <table class="admin-table">
            <thead><tr><th>Beschreibung</th><th>Von</th><th>Bis</th><th>Durchgeführt von</th><th style="text-align:right;">Aktionen</th></tr></thead>
            <tbody>
            <?php if (empty($protokolle)): ?>
                <tr><td colspan="5" class="muted-text" style="text-align:center;padding:30px;">Keine Einträge.</td></tr>
            <?php endif; ?>
            <?php foreach ($protokolle as $p):
                $von_t = date('H:i', strtotime($p['start_zeitpunkt']));
                $bis_t = date('H:i', strtotime($p['ende_zeitpunkt']));
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($p['beschreibung']); ?></td>
                    <td style="font-size:0.82rem;white-space:nowrap;">
                        <?php echo date('d.m.Y', strtotime($p['start_zeitpunkt'])); ?>
                        <?php if ($von_t !== '00:00'): ?><span style="color:#888;"> <?php echo $von_t; ?></span><?php endif; ?>
                    </td>
                    <td style="font-size:0.82rem;white-space:nowrap;">
                        <?php echo date('d.m.Y', strtotime($p['ende_zeitpunkt'])); ?>
                        <?php if ($bis_t !== '00:00'): ?><span style="color:#888;"> <?php echo $bis_t; ?></span><?php endif; ?>
                    </td>
                    <td style="color:#aaa;"><?php echo htmlspecialchars($p['durchgefuehrt_von'] ?? '-'); ?></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="btn-sm btn-primary" onclick='openEditModal(<?php echo json_encode($p); ?>)'><i class="fas fa-edit"></i></button>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Eintrag löschen?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="wartung_id" value="<?php echo (int)$p['wartung_id']; ?>">
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

<!-- MODAL: ERSTELLEN -->
<div class="modal-overlay" id="modal-create">
<div class="modal-box">
    <h3><i class="fas fa-tools" style="color:#007bff;"></i> Log eintragen</h3>
    <form method="POST">
        <input type="hidden" name="action" value="create">
        <div class="form-group"><label>Beschreibung *</label><textarea name="beschreibung" required placeholder="Was wurde durchgeführt?"></textarea></div>
        <div class="form-row">
            <div class="form-group">
                <label>Von (Datum) *</label>
                <input type="date" name="von_datum" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Von (Uhrzeit, optional)</label>
                <input type="time" name="von_zeit" id="create-von-zeit">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Bis (Datum)</label>
                <input type="date" name="bis_datum" id="create-bis-datum" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
                <label>Bis (Uhrzeit, optional)</label>
                <input type="time" name="bis_zeit">
            </div>
        </div>
        <div class="form-group">
            <label>Durchgeführt von</label>
            <select name="durchgefuehrt_von_id" style="width:100%;box-sizing:border-box;background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;">
                <option value="0">— Bitte wählen —</option>
                <?php foreach ($alle_benutzer_w as $u): ?>
                    <option value="<?php echo $u['benutzer_id']; ?>" <?php echo ($u['benutzer_id'] == (int)$_SESSION['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-create')">Abbrechen</button>
            <button type="submit" class="btn-save">Speichern</button>
        </div>
    </form>
</div>
</div>

<!-- MODAL: BEARBEITEN -->
<div class="modal-overlay" id="modal-edit">
<div class="modal-box">
    <h3><i class="fas fa-edit" style="color:#007bff;"></i> Log bearbeiten</h3>
    <form method="POST">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="wartung_id" id="edit-wid">
        <div class="form-group"><label>Beschreibung *</label><textarea name="beschreibung" id="edit-beschreibung" required></textarea></div>
        <div class="form-row">
            <div class="form-group">
                <label>Von (Datum) *</label>
                <input type="date" name="von_datum" id="edit-von-datum" required>
            </div>
            <div class="form-group">
                <label>Von (Uhrzeit, optional)</label>
                <input type="time" name="von_zeit" id="edit-von-zeit">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Bis (Datum)</label>
                <input type="date" name="bis_datum" id="edit-bis-datum">
            </div>
            <div class="form-group">
                <label>Bis (Uhrzeit, optional)</label>
                <input type="time" name="bis_zeit" id="edit-bis-zeit">
            </div>
        </div>
        <div class="form-group">
            <label>Durchgeführt von</label>
            <select name="durchgefuehrt_von_id" id="edit-durch-id" style="width:100%;box-sizing:border-box;background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;">
                <option value="0">— Bitte wählen —</option>
                <?php foreach ($alle_benutzer_w as $u): ?>
                    <option value="<?php echo $u['benutzer_id']; ?>"><?php echo htmlspecialchars($u['name']); ?></option>
                <?php endforeach; ?>
            </select>
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

// Auto-set Bis-Datum when Von-Datum changes (create modal)
document.querySelector('[name="von_datum"]')?.addEventListener('change', function() {
    const bis = document.getElementById('create-bis-datum');
    if (bis && !bis.value) bis.value = this.value;
});

function openEditModal(p) {
    document.getElementById('edit-wid').value = p.wartung_id;
    document.getElementById('edit-beschreibung').value = p.beschreibung;

    // start_zeitpunkt: "YYYY-MM-DD HH:MM:SS"
    const st = p.start_zeitpunkt || '';
    const et = p.ende_zeitpunkt  || '';
    document.getElementById('edit-von-datum').value = st.substring(0, 10);
    const vonT = st.substring(11, 16);
    document.getElementById('edit-von-zeit').value  = (vonT && vonT !== '00:00') ? vonT : '';
    document.getElementById('edit-bis-datum').value = et.substring(0, 10);
    const bisT = et.substring(11, 16);
    document.getElementById('edit-bis-zeit').value  = (bisT && bisT !== '00:00') ? bisT : '';

    // Try to match durchgefuehrt_von name to a dropdown entry
    const sel = document.getElementById('edit-durch-id');
    const name = p.durchgefuehrt_von || '';
    let matched = false;
    for (let opt of sel.options) {
        if (opt.text === name) { sel.value = opt.value; matched = true; break; }
    }
    if (!matched) sel.value = '0';

    openModal('modal-edit');
}
document.querySelectorAll('.modal-overlay').forEach(el=>{el.addEventListener('click',function(e){if(e.target===el)el.classList.remove('open');});});
</script>
</body>
</html>
