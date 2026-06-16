<?php
session_start();
require_once 'db_config.php';

$name = "";
$name_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST["name"] ?? "");

    if (empty($name)) {
        $name_err = "Bitte geben Sie Ihren vollständigen Namen ein.";
    } else {
        $st = mysqli_prepare($link, "SELECT b.benutzer_id, b.sicherheitsfrage FROM benutzer b JOIN login_daten ld ON b.benutzer_id = ld.benutzer_id WHERE b.email = ? AND b.sicherheitsfrage IS NOT NULL AND b.sicherheitsantwort IS NOT NULL LIMIT 1");
        mysqli_stmt_bind_param($st, "s", $name);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($st);

        if ($row) {
            $_SESSION['reset_benutzer_id'] = $row['benutzer_id'];
            header("location: sicherheitsfrage.php");
            exit;
        } else {
            $name_err = "Kein Konto mit dieser E-Mail-Adresse gefunden oder keine Sicherheitsfrage hinterlegt.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort vergessen</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { display:flex; justify-content:center; align-items:center; min-height:100vh; background-color:#111; }
        .container { width:100%; max-width:420px; padding:36px; background:#1a1a1a; border:1px solid #333; box-shadow:0 4px 24px rgba(0,0,0,0.5); border-radius:12px; color:#ddd; }
        h2 { text-align:center; color:#ffcc00; margin-bottom:8px; }
        p { text-align:center; color:#888; margin-bottom:24px; font-size:0.9rem; }
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
        <h2>Passwort vergessen</h2>
        <p>Geben Sie Ihre E-Mail-Adresse ein, um fortzufahren.</p>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label for="name">E-Mail-Adresse</label>
                <input type="email" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($name); ?>" autocomplete="email">
                <?php if ($name_err): ?><span class="invalid-feedback"><?php echo htmlspecialchars($name_err); ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <button type="submit" class="btn-primary">Weiter</button>
            </div>
        </form>
        <div class="link-text">
            <a href="login.php">Zurück zur Anmeldung</a>
        </div>
    </div>
</body>
</html>
