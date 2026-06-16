<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    header("location: index.php"); exit;
}
$benutzer_name = $_SESSION['username'];
$rolle = $_SESSION['rolle'];

$error = '';
$success = '';

// DB Migration: Verlaufshistorie für Anstellungsarten
mysqli_query($link, "CREATE TABLE IF NOT EXISTS anstellungsart_verlauf (
    verlauf_id INT AUTO_INCREMENT PRIMARY KEY,
    benutzer_id INT NOT NULL,
    anstellungs_art_id INT NOT NULL,
    gueltig_ab DATE NOT NULL,
    KEY idx_v (benutzer_id, gueltig_ab)
)");
// Initialeintrag für Benutzer ohne Verlauf
mysqli_query($link, "INSERT INTO anstellungsart_verlauf (benutzer_id, anstellungs_art_id, gueltig_ab)
    SELECT bp.benutzer_id, bp.anstellungs_art_id, COALESCE(bp.eintrittsdatum, '2026-01-01')
    FROM benutzerprofile bp
    WHERE bp.anstellungs_art_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM anstellungsart_verlauf v WHERE v.benutzer_id = bp.benutzer_id)");

// --- AKTIONEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // BENUTZER ERSTELLEN
    if ($action === 'create') {
        $name           = trim($_POST['name'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $nfc            = trim($_POST['nfc_karte_id'] ?? '');
        $usr_rolle      = $_POST['user_rolle'] ?? 'Mitarbeiter';
        $art_id         = (int)($_POST['anstellungs_art_id'] ?? 1);
        $urlaubstage    = (int)($_POST['urlaubstage_gesamt'] ?? 25);
        $eintrittsdatum = $_POST['eintrittsdatum'] ?? date('Y-m-d');

        if ($name && $email) {
            // Eindeutigkeitsprüfung
            $st_chk = mysqli_prepare($link, "SELECT benutzer_id FROM benutzer WHERE email=? LIMIT 1");
            mysqli_stmt_bind_param($st_chk, "s", $email);
            mysqli_stmt_execute($st_chk);
            if (mysqli_stmt_get_result($st_chk)->fetch_assoc()) $error = "Diese E-Mail-Adresse ist bereits vergeben.";
            mysqli_stmt_close($st_chk);

            if (!$error) {
                $st_chk = mysqli_prepare($link, "SELECT benutzer_id FROM benutzer WHERE name=? LIMIT 1");
                mysqli_stmt_bind_param($st_chk, "s", $name);
                mysqli_stmt_execute($st_chk);
                if (mysqli_stmt_get_result($st_chk)->fetch_assoc()) $error = "Dieser Name ist bereits vergeben.";
                mysqli_stmt_close($st_chk);
            }

            if (!$error && $nfc !== '') {
                $st_chk = mysqli_prepare($link, "SELECT benutzer_id FROM benutzer WHERE nfc_karte_id=? LIMIT 1");
                mysqli_stmt_bind_param($st_chk, "s", $nfc);
                mysqli_stmt_execute($st_chk);
                if (mysqli_stmt_get_result($st_chk)->fetch_assoc()) $error = "Diese NFC-Karten-ID ist bereits vergeben.";
                mysqli_stmt_close($st_chk);
            }
        }

        if ($name && $email && !$error) {
            mysqli_begin_transaction($link);
            try {
                $st = mysqli_prepare($link, "INSERT INTO benutzer (name, nfc_karte_id, email) VALUES (?,?,?)");
                mysqli_stmt_bind_param($st, "sss", $name, $nfc, $email);
                mysqli_stmt_execute($st);
                $new_id = mysqli_insert_id($link);
                mysqli_stmt_close($st);

                $st2 = mysqli_prepare($link, "INSERT INTO benutzerprofile (benutzer_id, rolle, eintrittsdatum, anstellungs_art_id, urlaubstage_gesamt) VALUES (?,?,?,?,?)");
                mysqli_stmt_bind_param($st2, "issii", $new_id, $usr_rolle, $eintrittsdatum, $art_id, $urlaubstage);
                mysqli_stmt_execute($st2);
                mysqli_stmt_close($st2);

                mysqli_commit($link);
                $success = "Benutzer erfolgreich erstellt. Der Mitarbeiter kann sich jetzt unter 'Neues Login erstellen' registrieren.";
            } catch (Exception $e) {
                mysqli_rollback($link);
                $error = "Fehler beim Erstellen: " . $e->getMessage();
            }
        } elseif (!$name || !$email) {
            $error = "Bitte Name und Email ausfüllen.";
        }
    }

    // BENUTZER BEARBEITEN
    if ($action === 'edit') {
        $bid      = (int)$_POST['benutzer_id'];
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $nfc      = trim($_POST['nfc_karte_id'] ?? '');
        $usr_rolle= $_POST['user_rolle'] ?? 'Mitarbeiter';
        $art_id   = (int)($_POST['anstellungs_art_id'] ?? 1);
        $urlaubstage = (int)($_POST['urlaubstage_gesamt'] ?? 25);
        $eintrittsdatum = $_POST['eintrittsdatum'] ?? date('Y-m-d');
        $gueltig_ab = trim($_POST['anstellungsart_gueltig_ab'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gueltig_ab)) $gueltig_ab = date('Y-m-d');

        // Wenn Anstellungsart geändert wurde → Verlaufseintrag anlegen
        $st_cur = mysqli_prepare($link, "SELECT anstellungs_art_id FROM benutzerprofile WHERE benutzer_id=?");
        mysqli_stmt_bind_param($st_cur, "i", $bid);
        mysqli_stmt_execute($st_cur);
        $cur_art = (int)(mysqli_stmt_get_result($st_cur)->fetch_assoc()['anstellungs_art_id'] ?? 0);
        mysqli_stmt_close($st_cur);

        // Eindeutigkeitsprüfung (anderen Benutzer ausschließen)
        $st_chk = mysqli_prepare($link, "SELECT benutzer_id FROM benutzer WHERE email=? AND benutzer_id!=? LIMIT 1");
        mysqli_stmt_bind_param($st_chk, "si", $email, $bid);
        mysqli_stmt_execute($st_chk);
        if (mysqli_stmt_get_result($st_chk)->fetch_assoc()) $error = "Diese E-Mail-Adresse ist bereits vergeben.";
        mysqli_stmt_close($st_chk);

        if (!$error) {
            $st_chk = mysqli_prepare($link, "SELECT benutzer_id FROM benutzer WHERE name=? AND benutzer_id!=? LIMIT 1");
            mysqli_stmt_bind_param($st_chk, "si", $name, $bid);
            mysqli_stmt_execute($st_chk);
            if (mysqli_stmt_get_result($st_chk)->fetch_assoc()) $error = "Dieser Name ist bereits vergeben.";
            mysqli_stmt_close($st_chk);
        }

        if (!$error && $nfc !== '') {
            $st_chk = mysqli_prepare($link, "SELECT benutzer_id FROM benutzer WHERE nfc_karte_id=? AND benutzer_id!=? LIMIT 1");
            mysqli_stmt_bind_param($st_chk, "si", $nfc, $bid);
            mysqli_stmt_execute($st_chk);
            if (mysqli_stmt_get_result($st_chk)->fetch_assoc()) $error = "Diese NFC-Karten-ID ist bereits vergeben.";
            mysqli_stmt_close($st_chk);
        }

        if (!$error) {
            if ($art_id !== $cur_art) {
                $st_v = mysqli_prepare($link, "INSERT INTO anstellungsart_verlauf (benutzer_id, anstellungs_art_id, gueltig_ab) VALUES (?,?,?)");
                mysqli_stmt_bind_param($st_v, "iis", $bid, $art_id, $gueltig_ab);
                mysqli_stmt_execute($st_v); mysqli_stmt_close($st_v);
            }

            $st = mysqli_prepare($link, "UPDATE benutzer SET name=?, email=?, nfc_karte_id=? WHERE benutzer_id=?");
            mysqli_stmt_bind_param($st, "sssi", $name, $email, $nfc, $bid);
            mysqli_stmt_execute($st); mysqli_stmt_close($st);

            $st2 = mysqli_prepare($link, "UPDATE benutzerprofile SET rolle=?, anstellungs_art_id=?, urlaubstage_gesamt=?, eintrittsdatum=? WHERE benutzer_id=?");
            mysqli_stmt_bind_param($st2, "siisi", $usr_rolle, $art_id, $urlaubstage, $eintrittsdatum, $bid);
            mysqli_stmt_execute($st2); mysqli_stmt_close($st2);

            $success = "Benutzer aktualisiert.";
        }
    }

    // PASSWORT ZURÜCKSETZEN
    if ($action === 'reset_pw') {
        $bid = (int)$_POST['benutzer_id'];
        $new_pw = $_POST['new_passwort'] ?? '';
        if ($new_pw) {
            $hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $st = mysqli_prepare($link, "UPDATE login_daten SET passwort_hash=? WHERE benutzer_id=?");
            mysqli_stmt_bind_param($st, "si", $hash, $bid);
            mysqli_stmt_execute($st); mysqli_stmt_close($st);
            $success = "Passwort zurückgesetzt.";
        }
    }

    // BENUTZER LÖSCHEN
    if ($action === 'delete') {
        $bid = (int)$_POST['benutzer_id'];
        foreach ([
            "DELETE FROM pausen WHERE benutzer_id=?",
            "DELETE FROM anwesenheitsaufzeichnungen WHERE benutzer_id=?",
            "DELETE FROM abwesenheiten WHERE benutzer_id=?",
            "DELETE FROM benachrichtigungen WHERE benutzer_id=?",
            "DELETE FROM passwort_reset_antraege WHERE benutzer_id=?",
            "DELETE FROM login_daten WHERE benutzer_id=?",
            "DELETE FROM benutzerprofile WHERE benutzer_id=?",
            "DELETE FROM benutzer WHERE benutzer_id=?",
        ] as $sql) {
            $st = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($st, "i", $bid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
        $success = "Benutzer gelöscht.";
    }

    header("Location: admin_benutzer.php?msg=" . urlencode($success ?: $error));
    exit;
}

if (isset($_GET['msg'])) {
    $success = htmlspecialchars($_GET['msg']);
}

// Filter & Suche
$search = trim($_GET['search'] ?? '');
$filter_rolle = $_GET['filter_rolle'] ?? '';

$where = "WHERE 1=1";
$params = [];
$types = "";
if ($search) {
    $s = "%$search%";
    $where .= " AND (b.name LIKE ? OR b.email LIKE ? OR ld.benutzername LIKE ?)";
    $params = array_merge($params, [$s, $s, $s]);
    $types .= "sss";
}
if ($filter_rolle) {
    $where .= " AND bp.rolle = ?";
    $params[] = $filter_rolle;
    $types .= "s";
}

$page_num = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page_num - 1) * $per_page;

$count_sql = "SELECT COUNT(*) as cnt FROM benutzer b
              LEFT JOIN benutzerprofile bp ON b.benutzer_id = bp.benutzer_id
              LEFT JOIN login_daten ld ON b.benutzer_id = ld.benutzer_id
              $where";
$st_count = mysqli_prepare($link, $count_sql);
if ($params) mysqli_stmt_bind_param($st_count, $types, ...$params);
mysqli_stmt_execute($st_count);
$total = mysqli_stmt_get_result($st_count)->fetch_assoc()['cnt'];
$total_pages = ceil($total / $per_page);

$sql = "SELECT b.benutzer_id, b.name, b.email, b.nfc_karte_id,
               bp.rolle, bp.eintrittsdatum, bp.urlaubstage_gesamt, bp.anstellungs_art_id,
               a.bezeichnung as anstellungsart,
               ld.benutzername, ld.letzter_login
        FROM benutzer b
        LEFT JOIN benutzerprofile bp ON b.benutzer_id = bp.benutzer_id
        LEFT JOIN anstellungsarten a ON bp.anstellungs_art_id = a.art_id
        LEFT JOIN login_daten ld ON b.benutzer_id = ld.benutzer_id
        $where
        ORDER BY FIELD(bp.rolle,'Admin','Manager','Mitarbeiter'), b.name ASC
        LIMIT ? OFFSET ?";
$st = mysqli_prepare($link, $sql);
$p2 = array_merge($params, [$per_page, $offset]);
$t2 = $types . "ii";
mysqli_stmt_bind_param($st, $t2, ...$p2);
mysqli_stmt_execute($st);
$res_benutzer = mysqli_stmt_get_result($st);
$benutzer_list = mysqli_fetch_all($res_benutzer, MYSQLI_ASSOC);

$anstellungsarten = mysqli_fetch_all(mysqli_query($link, "SELECT * FROM anstellungsarten ORDER BY art_id"), MYSQLI_ASSOC);

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

$urlaub_res = mysqli_query($link, "SELECT benutzer_id, abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE status='Genehmigt' AND abwesenheit_typ='Urlaub'");
$urlaub_used = [];
while ($ur = mysqli_fetch_assoc($urlaub_res)) {
    $bid_u = (int)$ur['benutzer_id'];
    $urlaub_used[$bid_u] = ($urlaub_used[$bid_u] ?? 0) + countWorkdays($ur['abwesenheit_beginn'], $ur['abwesenheit_ende'], $feiertage_set);
}

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
<title>Benutzerverwaltung | Admin</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.admin-subnav { background:#111; border-bottom:1px solid #333; padding:0 30px; display:flex; gap:5px; }
.admin-subnav a { color:#aaa; text-decoration:none; padding:12px 16px; font-size:0.88rem; border-bottom:3px solid transparent; display:inline-block; }
.admin-subnav a:hover { color:#fff; }
.admin-subnav a.active { color:#007bff; border-bottom-color:#007bff; }
.btn-sm { padding:5px 12px; border-radius:6px; border:1px solid #555; background:none; color:#fff; cursor:pointer; font-size:0.8rem; }
.btn-sm:hover { background:#333; }
.btn-danger { border-color:#ff4d4d; color:#ff4d4d; }
.btn-danger:hover { background:#ff4d4d; color:#fff; }
.btn-primary { border-color:#007bff; color:#007bff; }
.btn-primary:hover { background:#007bff; color:#fff; }
.btn-success { border-color:#2ecc71; color:#2ecc71; }
.btn-success:hover { background:#2ecc71; color:#fff; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9000; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#2d2d2d; border:1px solid #444; border-radius:16px; padding:30px; width:500px; max-width:95vw; max-height:90vh; overflow-y:auto; }
.modal-box h3 { margin:0 0 20px; color:#fff; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; color:#aaa; font-size:0.85rem; margin-bottom:5px; }
.form-group input, .form-group select { width:100%; box-sizing:border-box; background:#1a1a1a; border:1px solid #444; border-radius:8px; padding:9px 12px; color:#fff; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
.modal-actions button { padding:9px 20px; border-radius:8px; border:none; cursor:pointer; font-weight:600; }
.btn-cancel { background:#333; color:#aaa; }
.btn-save { background:#007bff; color:#fff; }
.alert { padding:12px 16px; border-radius:8px; margin-bottom:20px; }
.alert-success { background:rgba(46,204,113,0.15); border:1px solid #2ecc71; color:#2ecc71; }
.alert-error { background:rgba(255,77,77,0.15); border:1px solid #ff4d4d; color:#ff4d4d; }
.badge-rolle { padding:3px 10px; border-radius:12px; font-size:0.75rem; font-weight:600; }
.badge-Admin { background:rgba(0,123,255,0.2); color:#007bff; border:1px solid #007bff; }
.badge-Manager { background:rgba(255,204,0,0.15); color:#ffcc00; border:1px solid #ffcc00; }
.badge-Mitarbeiter { background:rgba(46,204,113,0.15); color:#2ecc71; border:1px solid #2ecc71; }
.pagination { display:flex; gap:6px; margin-top:20px; justify-content:center; }
.pagination a, .pagination span { padding:6px 12px; border-radius:6px; border:1px solid #444; color:#aaa; text-decoration:none; font-size:0.85rem; }
.pagination a:hover { background:#333; color:#fff; }
.pagination .current { background:#007bff; color:#fff; border-color:#007bff; }
</style>
</head>
<body>

<?php $page = basename($_SERVER['PHP_SELF']); ?>
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
    <a href="admin_benutzer.php" class="active">Benutzer</a>
    <a href="admin_zeiterfassung.php">Zeiterfassung</a>
    <a href="admin_abwesenheiten.php">Abwesenheiten</a>
    <a href="admin_sicherheit.php">Sicherheit</a>
    <a href="admin_benachrichtigungen.php">Benachrichtigungen</a>
    <a href="admin_berichte.php">Berichte</a>
    <a href="admin_wartung.php">Wartung</a>
</div>

<div class="container">
<div class="page-width">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h1 class="page-title" style="margin:0;">Benutzerverwaltung</h1>
        <button class="btn-sm btn-primary" onclick="openModal('modal-create')"><i class="fas fa-user-plus"></i> Neuer Benutzer</button>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['new_user_credentials'])): $creds = $_SESSION['new_user_credentials']; unset($_SESSION['new_user_credentials']); ?>
    <div class="alert alert-success" style="border-color:#ffcc00;color:#ffcc00;background:rgba(255,204,0,0.1);">
        <i class="fas fa-key"></i> <strong>Zugangsdaten für den neuen Mitarbeiter:</strong><br>
        Benutzername: <strong><?php echo htmlspecialchars($creds['username']); ?></strong> &nbsp;|&nbsp;
        Temporäres Passwort: <strong><?php echo htmlspecialchars($creds['password']); ?></strong>
        <br><small style="color:#aaa;">Bitte diese Zugangsdaten an den Mitarbeiter weitergeben. Der Mitarbeiter kann Benutzername &amp; Passwort selbst ändern.</small>
    </div>
    <?php endif; ?>

    <!-- Filter -->
    <div class="dashboard-card" style="padding:18px;margin-bottom:20px;">
        <form method="GET" class="filter-form" style="margin:0;">
            <label>Suche<input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, Email, Benutzername..."></label>
            <label>Rolle
                <select name="filter_rolle">
                    <option value="">Alle Rollen</option>
                    <?php foreach (['Admin','Manager','Mitarbeiter'] as $r): ?>
                        <option value="<?php echo $r; ?>" <?php echo $filter_rolle===$r?'selected':''; ?>><?php echo $r; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn-export"><i class="fas fa-search"></i> Suchen</button>
            <a href="admin_benutzer.php" style="color:#aaa;text-decoration:none;align-self:flex-end;padding:9px 12px;border:1px solid #444;border-radius:8px;font-size:0.9rem;">Reset</a>
        </form>
    </div>

    <!-- Tabelle -->
    <div class="dashboard-card" style="padding:0;overflow:hidden;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Rolle</th>
                    <th>Anstellungsart</th>
                    <th>Urlaub</th>

                    <th style="text-align:right;">Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($benutzer_list)): ?>
                <tr><td colspan="7" class="muted-text" style="text-align:center;padding:30px;">Keine Benutzer gefunden.</td></tr>
            <?php endif; ?>
            <?php foreach ($benutzer_list as $u): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                    <td style="font-size:0.85rem;"><?php echo htmlspecialchars($u['email'] ?? '-'); ?></td>
                    <td><span class="badge-rolle badge-<?php echo htmlspecialchars($u['rolle'] ?? ''); ?>"><?php echo htmlspecialchars($u['rolle'] ?? '-'); ?></span></td>
                    <td style="font-size:0.85rem;"><?php echo htmlspecialchars($u['anstellungsart'] ?? '-'); ?></td>
                    <?php $rest_urlaub = max(0, (int)$u['urlaubstage_gesamt'] - ($urlaub_used[$u['benutzer_id']] ?? 0)); ?>
                    <td><strong><?php echo $rest_urlaub; ?></strong> / <?php echo (int)$u['urlaubstage_gesamt']; ?> Tage</td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="btn-sm btn-primary" onclick='openEditModal(<?php echo json_encode($u); ?>)'><i class="fas fa-edit"></i></button>
                        <button class="btn-sm btn-danger" onclick='openDeleteModal(<?php echo (int)$u["benutzer_id"]; ?>, <?php echo json_encode($u["name"]); ?>)'><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination" style="padding:16px;">
            <?php if ($page_num > 1): ?><a href="?page=<?php echo $page_num-1; ?>&search=<?php echo urlencode($search); ?>&filter_rolle=<?php echo urlencode($filter_rolle); ?>">&#8249;</a><?php endif; ?>
            <?php for ($i=1; $i<=$total_pages; $i++): ?>
                <?php if ($i==$page_num): ?><span class="current"><?php echo $i; ?></span>
                <?php else: ?><a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&filter_rolle=<?php echo urlencode($filter_rolle); ?>"><?php echo $i; ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page_num < $total_pages): ?><a href="?page=<?php echo $page_num+1; ?>&search=<?php echo urlencode($search); ?>&filter_rolle=<?php echo urlencode($filter_rolle); ?>">&#8250;</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- MODAL: ERSTELLEN -->
<div class="modal-overlay" id="modal-create">
<div class="modal-box">
    <h3><i class="fas fa-user-plus" style="color:#007bff;"></i> Neuer Benutzer</h3>
    <p style="color:#888;font-size:0.82rem;margin:-10px 0 16px;">Benutzername &amp; Passwort werden automatisch generiert und nach dem Erstellen angezeigt.</p>
    <form method="POST">
        <input type="hidden" name="action" value="create">
        <div class="form-row">
            <div class="form-group"><label>Vollständiger Name *</label><input type="text" name="name" required></div>
            <div class="form-group"><label>Email *</label><input type="email" name="email" required></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Rolle</label>
                <select name="user_rolle">
                    <option value="Mitarbeiter">Mitarbeiter</option>
                    <option value="Manager">Manager</option>
                    <option value="Admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label>Anstellungsart</label>
                <select name="anstellungs_art_id">
                    <?php foreach ($anstellungsarten as $a): ?>
                        <option value="<?php echo $a['art_id']; ?>"><?php echo htmlspecialchars($a['bezeichnung']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Eintrittsdatum</label><input type="date" name="eintrittsdatum" value="<?php echo date('Y-m-d'); ?>"></div>
            <div class="form-group"><label>Urlaubstage</label><input type="number" name="urlaubstage_gesamt" value="25" min="0"></div>
        </div>
        <div class="form-group"><label>NFC Karten-ID</label><input type="text" name="nfc_karte_id" placeholder="optional"></div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-create')">Abbrechen</button>
            <button type="submit" class="btn-save">Erstellen</button>
        </div>
    </form>
</div>
</div>

<!-- MODAL: BEARBEITEN -->
<div class="modal-overlay" id="modal-edit">
<div class="modal-box">
    <h3><i class="fas fa-edit" style="color:#007bff;"></i> Benutzer bearbeiten</h3>
    <form method="POST" id="form-edit">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="benutzer_id" id="edit-id">
        <div class="form-row">
            <div class="form-group"><label>Name</label><input type="text" name="name" id="edit-name" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" id="edit-email"></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Rolle</label>
                <select name="user_rolle" id="edit-rolle">
                    <option value="Mitarbeiter">Mitarbeiter</option>
                    <option value="Manager">Manager</option>
                    <option value="Admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label>Anstellungsart</label>
                <select name="anstellungs_art_id" id="edit-art">
                    <?php foreach ($anstellungsarten as $a): ?>
                        <option value="<?php echo $a['art_id']; ?>"><?php echo htmlspecialchars($a['bezeichnung']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="art-gueltig-row" style="display:none;background:rgba(0,123,255,0.07);border:1px solid #0055cc;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
            <label style="display:block;color:#6bc5f8;font-size:0.85rem;margin-bottom:6px;font-weight:600;">
                <i class="fas fa-calendar-alt"></i> Änderung gilt ab
            </label>
            <input type="date" name="anstellungsart_gueltig_ab" id="edit-art-gueltig" style="width:100%;box-sizing:border-box;background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;">
            <small style="color:#888;font-size:0.78rem;margin-top:6px;display:block;">Vor diesem Datum gilt die bisherige Anstellungsart. Ab diesem Datum das neue Modell.</small>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Eintrittsdatum</label><input type="date" name="eintrittsdatum" id="edit-eintritt"></div>
            <div class="form-group"><label>Urlaubstage</label><input type="number" name="urlaubstage_gesamt" id="edit-urlaub" min="0"></div>
        </div>
        <div class="form-group"><label>NFC Karten-ID</label><input type="text" name="nfc_karte_id" id="edit-nfc"></div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-edit')">Abbrechen</button>
            <button type="submit" class="btn-save">Speichern</button>
        </div>
    </form>
</div>
</div>

<!-- MODAL: LÖSCHEN mit Namensbestätigung -->
<div class="modal-overlay" id="modal-delete">
<div class="modal-box" style="max-width:420px;">
    <h3><i class="fas fa-trash" style="color:#ff4d4d;"></i> Benutzer löschen</h3>
    <p style="color:#aaa;margin-bottom:6px;">Dieser Vorgang kann nicht rückgängig gemacht werden.</p>
    <p style="color:#aaa;margin-bottom:16px;">Zur Bestätigung den Namen eingeben: <strong id="delete-name-display" style="color:#fff;"></strong></p>
    <form method="POST" id="form-delete" onsubmit="return validateDelete()">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="benutzer_id" id="delete-id">
        <div class="form-group">
            <label>Name bestätigen</label>
            <input type="text" id="delete-name-confirm" placeholder="Exakter Name eingeben..." autocomplete="off">
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-delete')">Abbrechen</button>
            <button type="submit" style="background:#ff4d4d;color:#fff;padding:9px 20px;border-radius:8px;border:none;cursor:pointer;font-weight:600;">Endgültig löschen</button>
        </div>
    </form>
</div>
</div>

<script>
let _deleteName = '';
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openEditModal(u) {
    document.getElementById('edit-id').value = u.benutzer_id;
    document.getElementById('edit-name').value = u.name;
    document.getElementById('edit-email').value = u.email || '';
    document.getElementById('edit-nfc').value = u.nfc_karte_id || '';
    document.getElementById('edit-rolle').value = u.rolle || 'Mitarbeiter';
    const artSel = document.getElementById('edit-art');
    artSel.value = u.anstellungs_art_id || 1;
    artSel.dataset.original = String(u.anstellungs_art_id || 1);
    document.getElementById('edit-eintritt').value = u.eintrittsdatum || '';
    document.getElementById('edit-urlaub').value = u.urlaubstage_gesamt || 25;
    document.getElementById('art-gueltig-row').style.display = 'none';
    document.getElementById('edit-art-gueltig').value = '<?php echo date('Y-m-d'); ?>';
    openModal('modal-edit');
}
document.getElementById('edit-art').addEventListener('change', function() {
    document.getElementById('art-gueltig-row').style.display =
        (this.value !== this.dataset.original) ? 'block' : 'none';
});
function openDeleteModal(id, name) {
    _deleteName = name;
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-name-display').textContent = name;
    document.getElementById('delete-name-confirm').value = '';
    openModal('modal-delete');
}
function validateDelete() {
    if (document.getElementById('delete-name-confirm').value !== _deleteName) {
        alert('Name stimmt nicht überein. Bitte exakt eingeben.');
        return false;
    }
    return true;
}
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target === el) el.classList.remove('open'); });
});
</script>
</body>
</html>
