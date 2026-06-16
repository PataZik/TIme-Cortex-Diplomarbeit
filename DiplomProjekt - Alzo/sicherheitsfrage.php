<?php
session_start();
require_once 'db_config.php';

if (empty($_SESSION['reset_benutzer_id'])) {
    header("location: passwort_vergessen.php"); exit;
}

$benutzer_id = (int)$_SESSION['reset_benutzer_id'];

$st = mysqli_prepare($link, "SELECT sicherheitsfrage, sicherheitsantwort FROM benutzer WHERE benutzer_id=?");
mysqli_stmt_bind_param($st, "i", $benutzer_id);
mysqli_stmt_execute($st);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
mysqli_stmt_close($st);

if (!$row || empty($row['sicherheitsfrage'])) {
    session_unset();
    header("location: passwort_vergessen.php"); exit;
}

$frage  = $row['sicherheitsfrage'];
$fehler = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $antwort = trim($_POST["antwort"] ?? "");

    if (empty($antwort)) {
        $fehler = "Bitte geben Sie Ihre Antwort ein.";
    } elseif (!password_verify(strtolower($antwort), $row['sicherheitsantwort'])) {
        $fehler = "Die Antwort ist falsch. Bitte versuchen Sie es erneut.";
    } else {
        // Antrag anlegen
        $st2 = mysqli_prepare($link, "INSERT INTO passwort_reset_antraege (benutzer_id) VALUES (?)");
        mysqli_stmt_bind_param($st2, "i", $benutzer_id);
        mysqli_stmt_execute($st2);
        $antrag_id = mysqli_insert_id($link);
        mysqli_stmt_close($st2);

        // Admin benachrichtigen
        $st_name = mysqli_prepare($link, "SELECT name FROM benutzer WHERE benutzer_id=?");
        mysqli_stmt_bind_param($st_name, "i", $benutzer_id);
        mysqli_stmt_execute($st_name);
        $name_row = mysqli_fetch_assoc(mysqli_stmt_get_result($st_name));
        mysqli_stmt_close($st_name);
        $mitarbeiter_name = $name_row['name'] ?? 'Unbekannt';

        $nachricht = "Passwort-Reset-Antrag von " . $mitarbeiter_name . " wartet auf Genehmigung.";
        $zeitstempel = date('Y-m-d H:i:s');
        $st_adm = mysqli_prepare($link, "SELECT b.benutzer_id FROM benutzer b JOIN benutzerprofile bp ON b.benutzer_id=bp.benutzer_id WHERE bp.rolle IN ('Admin','Manager')");
        mysqli_stmt_execute($st_adm);
        $admins = mysqli_fetch_all(mysqli_stmt_get_result($st_adm), MYSQLI_ASSOC);
        mysqli_stmt_close($st_adm);
        foreach ($admins as $adm) {
            $st_n = mysqli_prepare($link, "INSERT INTO benachrichtigungen (benutzer_id, nachricht, zeitstempel, status) VALUES (?,?,?,'Gesendet')");
            mysqli_stmt_bind_param($st_n, "iss", $adm['benutzer_id'], $nachricht, $zeitstempel);
            mysqli_stmt_execute($st_n);
            mysqli_stmt_close($st_n);
        }

        $_SESSION['reset_antrag_id'] = $antrag_id;
        unset($_SESSION['reset_benutzer_id']);
        header("location: reset_warten.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sicherheitsfrage</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { display:flex; justify-content:center; align-items:center; min-height:100vh; background-color:#111; }
        .container { width:100%; max-width:420px; padding:36px; background:#1a1a1a; border:1px solid #333; box-shadow:0 4px 24px rgba(0,0,0,0.5); border-radius:12px; color:#ddd; }
        h2 { text-align:center; color:#ffcc00; margin-bottom:8px; }
        .frage-box { background:#222; border:1px solid #444; border-radius:8px; padding:14px 16px; margin-bottom:20px; color:#fff; font-size:0.95rem; }
        .form-group { margin-bottom:18px; }
        label { display:block; margin-bottom:6px; font-size:0.85rem; color:#aaa; }
        .form-control { width:100%; padding:10px 12px; border:1px solid #444; background:#111; color:#fff; border-radius:8px; box-sizing:border-box; font-size:0.95rem; }
        .form-control:focus { outline:none; border-color:#ffcc00; }
        .btn-primary { width:100%; padding:11px; background:#ffcc00; color:#111; border:none; border-radius:8px; cursor:pointer; font-size:1rem; font-weight:700; margin-top:4px; transition:filter 0.2s; }
        .btn-primary:hover { filter:brightness(1.1); }
        .invalid-feedback { color:#ff4d4d; font-size:0.85rem; display:block; margin-top:5px; }
        .link-text { text-align:center; margin-top:20px; font-size:0.88rem; }
        .link-text a { color:#007bff; text-decoration:none; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Sicherheitsfrage</h2>
        <div class="frage-box"><?php echo htmlspecialchars($frage); ?></div>

        <form method="post">
            <div class="form-group">
                <label for="antwort">Ihre Antwort</label>
                <input type="text" name="antwort" id="antwort" class="form-control" autocomplete="off">
                <?php if ($fehler): ?><span class="invalid-feedback"><?php echo htmlspecialchars($fehler); ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <button type="submit" class="btn-primary">Antrag stellen</button>
            </div>
        </form>
        <div class="link-text">
            <a href="passwort_vergessen.php">Zurück</a>
        </div>
    </div>
</body>
</html>
