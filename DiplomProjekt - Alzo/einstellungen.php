<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: login.php"); exit;
}
require_once 'db_config.php';

$benutzer_id   = $_SESSION['id'];
$benutzer_name = $_SESSION['username'];
$rolle         = $_SESSION['rolle'];
$success = '';
$error   = '';

// --- PROFILBILD HOCHLADEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'profilbild') {
    if (isset($_FILES['profilbild']) && $_FILES['profilbild']['error'] === UPLOAD_ERR_OK) {
        $erlaubte_typen = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $_FILES['profilbild']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $erlaubte_typen)) {
            $error = "Nur JPG, PNG oder GIF erlaubt.";
        } elseif ($_FILES['profilbild']['size'] > 2 * 1024 * 1024) {
            $error = "Maximale Dateigröße: 2 MB.";
        } else {
            $ext      = pathinfo($_FILES['profilbild']['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $benutzer_id . '_' . time() . '.' . strtolower($ext);
            $ziel     = 'uploads/profilbilder/' . $filename;

            // Altes Bild löschen
            if (!empty($profilbild) && file_exists('uploads/profilbilder/' . $profilbild)) {
                unlink('uploads/profilbilder/' . $profilbild);
            }

            if (move_uploaded_file($_FILES['profilbild']['tmp_name'], $ziel)) {
                $st = mysqli_prepare($link, "UPDATE benutzer SET profilbild=? WHERE benutzer_id=?");
                mysqli_stmt_bind_param($st, "si", $filename, $benutzer_id);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
                $profilbild = $filename;
                $success = "Profilbild erfolgreich aktualisiert.";
            } else {
                $error = "Fehler beim Speichern des Bildes.";
            }
        }
    } else {
        $error = "Bitte eine Datei auswählen.";
    }
}

// --- PROFILBILD ENTFERNEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'profilbild_entfernen') {
    if (!empty($profilbild) && file_exists('uploads/profilbilder/' . $profilbild)) {
        unlink('uploads/profilbilder/' . $profilbild);
    }
    $st = mysqli_prepare($link, "UPDATE benutzer SET profilbild=NULL WHERE benutzer_id=?");
    mysqli_stmt_bind_param($st, "i", $benutzer_id);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    $profilbild = null;
    $success = "Profilbild entfernt.";
}

// --- PASSWORT ÄNDERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'passwort') {
    $altes_pw  = $_POST['altes_passwort']  ?? '';
    $neues_pw  = $_POST['neues_passwort']  ?? '';
    $wiederhol = $_POST['passwort_wiederholen'] ?? '';

    if (empty($altes_pw) || empty($neues_pw) || empty($wiederhol)) {
        $error = "Bitte alle Passwortfelder ausfüllen.";
    } elseif (strlen($neues_pw) < 6) {
        $error = "Das neue Passwort muss mindestens 6 Zeichen lang sein.";
    } elseif ($neues_pw !== $wiederhol) {
        $error = "Die neuen Passwörter stimmen nicht überein.";
    } else {
        // Aktuellen Hash laden
        $st = mysqli_prepare($link, "SELECT passwort_hash FROM login_daten WHERE benutzer_id=?");
        mysqli_stmt_bind_param($st, "i", $benutzer_id);
        mysqli_stmt_execute($st);
        $row = mysqli_stmt_get_result($st)->fetch_assoc();
        mysqli_stmt_close($st);

        if (!$row || !password_verify($altes_pw, $row['passwort_hash'])) {
            $error = "Das aktuelle Passwort ist falsch.";
        } else {
            $new_hash = password_hash($neues_pw, PASSWORD_DEFAULT);
            $st = mysqli_prepare($link, "UPDATE login_daten SET passwort_hash=? WHERE benutzer_id=?");
            mysqli_stmt_bind_param($st, "si", $new_hash, $benutzer_id);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            $success = "Passwort erfolgreich geändert.";
        }
    }
}

// --- NACHRICHTEN ---
$st_n = mysqli_prepare($link, "SELECT n.benachrichtigung_id, n.nachricht, n.zeitstempel, n.gelesen, b.name AS von_name FROM benachrichtigungen n LEFT JOIN benutzer b ON n.von_benutzer_id = b.benutzer_id WHERE n.benutzer_id=? AND n.zeitstempel >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY n.zeitstempel DESC LIMIT 20");
mysqli_stmt_bind_param($st_n, "i", $benutzer_id);
mysqli_stmt_execute($st_n);
$nachrichten_user = mysqli_fetch_all(mysqli_stmt_get_result($st_n), MYSQLI_ASSOC);
mysqli_stmt_close($st_n);
$nachrichten_count = count(array_filter($nachrichten_user, fn($n) => !$n['gelesen']));
$wartungen = mysqli_fetch_all(mysqli_query($link,
    "SELECT beschreibung FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"
), MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Einstellungen | Zeiterfassung</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; max-width:860px; margin:0 auto; }
.settings-card { background:var(--card-bg); border:1px solid #333; border-radius:16px; padding:28px; }
.settings-card h3 { margin:0 0 20px; font-size:1rem; display:flex; align-items:center; gap:8px; }
.form-group { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
.form-group label { font-size:0.85rem; color:#aaa; }
.form-group input { background:#1a1a1a; border:1px solid #444; border-radius:8px; padding:9px 12px; color:#fff; font-family:inherit; }
.form-group input:focus { outline:none; border-color:#007bff; }
.btn-submit { padding:10px 26px; background:#007bff; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:0.9rem; }
.btn-submit:hover { filter:brightness(1.1); }
.btn-danger { background:rgba(255,77,77,0.15); color:#ff4d4d; border:1px solid #ff4d4d; padding:7px 16px; border-radius:8px; cursor:pointer; font-size:0.85rem; }
.btn-danger:hover { background:#ff4d4d; color:#fff; }
.alert-success { background:rgba(46,204,113,0.15); border:1px solid #2ecc71; color:#2ecc71; padding:12px 16px; border-radius:8px; margin-bottom:20px; }
.alert-error   { background:rgba(255,77,77,0.15); border:1px solid #ff4d4d; color:#ff4d4d; padding:12px 16px; border-radius:8px; margin-bottom:20px; }
.avatar-preview { width:90px; height:90px; border-radius:50%; object-fit:cover; border:3px solid #444; display:block; margin:0 auto 16px; }
.avatar-placeholder { width:90px; height:90px; border-radius:50%; background:#333; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:2.8rem; color:#888; }
.upload-area { border:2px dashed #444; border-radius:10px; padding:18px; text-align:center; cursor:pointer; transition:border-color 0.2s; }
.upload-area:hover { border-color:#007bff; }
.upload-area input[type=file] { display:none; }
.upload-area label { cursor:pointer; color:#aaa; font-size:0.9rem; }
@media(max-width:700px){ .settings-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>

<?php $page = basename($_SERVER['PHP_SELF']); ?>
<nav class="navbar">
    <div class="nav-links">
        <a href="index.php">Dashboard</a>
        <a href="statistik.php">Statistik</a>
        <a href="abwesenheit_antrag.php">Abwesenheit</a>
        <?php if ($rolle != 'Mitarbeiter'): ?>
            <a href="admin_dashboard.php">Admin</a>
        <?php endif; ?>
    </div>
    <div class="user-info">
        <div class="bell-container">
            <i class="fas fa-bell"></i>
            <div class="bell-dropdown">
                <?php if (!empty($wartungen)): foreach ($wartungen as $w): ?>
                    <div class="bell-item"><div><?php echo htmlspecialchars($w['beschreibung']); ?></div></div>
                <?php endforeach; else: ?><div class="bell-item">Keine geplanten Wartungen</div><?php endif; ?>
            </div>
        </div>
        <div class="bell-container msg-container" style="color:#6bc5f8;" onmouseenter="this.querySelector('.bell-badge')?.remove();fetch('mark_nachrichten.php')">
            <i class="fas fa-envelope"></i>
            <?php if ($nachrichten_count > 0): ?><span class="bell-badge"><?php echo $nachrichten_count; ?></span><?php endif; ?>
            <div class="bell-dropdown" style="width:320px;">
                <div style="padding:10px 14px;font-weight:600;border-bottom:1px solid #333;color:#fff;font-size:0.9rem;">Meine Nachrichten</div>
                <?php if (empty($nachrichten_user)): ?><div class="bell-item" style="color:#888;">Keine neuen Nachrichten</div>
                <?php else: foreach ($nachrichten_user as $n): ?>
                    <div class="bell-item" data-id="<?php echo (int)$n['benachrichtigung_id']; ?>"><small><?php echo date('d.m.Y H:i', strtotime($n['zeitstempel'])); ?><?php if (!empty($n['von_name'])): ?> · Von: <?php echo htmlspecialchars($n['von_name']); ?><?php endif; ?></small><div><?php echo htmlspecialchars($n['nachricht']); ?></div><button class="msg-del-btn" title="Löschen"><i class="fas fa-trash-alt"></i></button></div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <a href="einstellungen.php" class="user-profile-link" style="color:var(--primary-color);">
            <?php if (!empty($profilbild) && file_exists("uploads/profilbilder/" . $profilbild)): ?>
                <img src="uploads/profilbilder/<?php echo htmlspecialchars($profilbild); ?>" class="user-avatar" alt="Profilbild">
            <?php else: ?>
                <i class="fas fa-user-circle" style="font-size:1.5rem;"></i>
            <?php endif; ?>
            <span class="username"><?php echo htmlspecialchars($benutzer_name); ?></span>
        </a>
    </div>
</nav>

<div class="container" style="display:block;padding-top:40px;">
    <div style="max-width:860px;margin:0 auto;">
        <h1 class="page-title"><i class="fas fa-cog" style="color:#007bff;"></i> Einstellungen</h1>

        <?php if ($success): ?><div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="settings-grid">

            <!-- PROFILBILD -->
            <div class="settings-card">
                <h3><i class="fas fa-camera" style="color:#007bff;"></i> Profilbild</h3>

                <?php if (!empty($profilbild) && file_exists("uploads/profilbilder/" . $profilbild)): ?>
                    <img src="uploads/profilbilder/<?php echo htmlspecialchars($profilbild); ?>" class="avatar-preview" alt="Profilbild">
                <?php else: ?>
                    <div class="avatar-placeholder"><i class="fas fa-user-circle"></i></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="profilbild">
                    <div class="upload-area">
                        <label for="file-input">
                            <i class="fas fa-upload" style="font-size:1.5rem;margin-bottom:6px;display:block;"></i>
                            Bild auswählen (JPG, PNG, max 2 MB)
                        </label>
                        <input type="file" id="file-input" name="profilbild" accept="image/jpeg,image/png,image/gif"
                               onchange="previewImg(this)">
                    </div>
                    <img id="img-preview" style="display:none;width:90px;height:90px;border-radius:50%;object-fit:cover;margin:12px auto 0;border:2px solid #007bff;" alt="">
                    <button type="submit" class="btn-submit" style="width:100%;margin-top:14px;">
                        <i class="fas fa-save"></i> Bild speichern
                    </button>
                </form>

                <?php if (!empty($profilbild)): ?>
                <form method="POST" style="margin-top:10px;" onsubmit="return confirm('Profilbild wirklich entfernen?')">
                    <input type="hidden" name="action" value="profilbild_entfernen">
                    <button type="submit" class="btn-danger" style="width:100%;">
                        <i class="fas fa-trash"></i> Profilbild entfernen
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- PASSWORT ÄNDERN -->
            <div class="settings-card">
                <h3><i class="fas fa-lock" style="color:#ffcc00;"></i> Passwort ändern</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="passwort">
                    <div class="form-group">
                        <label>Aktuelles Passwort</label>
                        <input type="password" name="altes_passwort" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label>Neues Passwort <span style="color:#555;">(min. 6 Zeichen)</span></label>
                        <input type="password" name="neues_passwort" required autocomplete="new-password" minlength="6">
                    </div>
                    <div class="form-group">
                        <label>Neues Passwort wiederholen</label>
                        <input type="password" name="passwort_wiederholen" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn-submit" style="width:100%;margin-top:4px;">
                        <i class="fas fa-key"></i> Passwort ändern
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
function previewImg(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const prev = document.getElementById('img-preview');
            prev.src = e.target.result;
            prev.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
</body>
</html>
