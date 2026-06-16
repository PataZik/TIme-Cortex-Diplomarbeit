<?php
// register.php
// Session starten
session_start();

// Wenn eingeloggt, weiterleiten
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("location: index.php");
    exit;
}

require_once 'db_config.php';

$username = $password = $confirm_password = $full_name = "";
$sicherheitsfrage = $sicherheitsantwort = "";
$username_err = $password_err = $confirm_password_err = $general_err = $sicherheit_err = "";
$success_msg = "";

$sicherheitsfragen = [
    "Wie hieß dein erstes Haustier?",
    "Wie lautet der Mädchenname deiner Mutter?",
    "Wie hieß deine erste Schule?",
    "In welcher Stadt bist du geboren?"
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $full_name = trim($_POST["full_name"]);
    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);
    $confirm_password = trim($_POST["confirm_password"]);
    $sicherheitsfrage = $_POST["sicherheitsfrage"] ?? "";
    $sicherheitsantwort = trim($_POST["sicherheitsantwort"] ?? "");
    $benutzer_id = 0;

    // --- 1. Validierung des vollständigen Namens und Auffinden der ID
    if (empty($full_name)) {
        $general_err = "Bitte geben Sie Ihren vollständigen Namen ein.";
    } else {
        // SQL: Finde den Benutzer, der existiert, aber NOCH KEINE Login-Daten hat
        $sql_find_id = "SELECT b.benutzer_id FROM benutzer b 
                        LEFT JOIN login_daten ld ON b.benutzer_id = ld.benutzer_id
                        WHERE b.name = ? AND ld.benutzer_id IS NULL";
        
        if ($stmt_id = mysqli_prepare($link, $sql_find_id)) {
            mysqli_stmt_bind_param($stmt_id, "s", $param_full_name);
            $param_full_name = $full_name;
            if (mysqli_stmt_execute($stmt_id)) {
                mysqli_stmt_store_result($stmt_id);
                if (mysqli_stmt_num_rows($stmt_id) == 1) {
                    mysqli_stmt_bind_result($stmt_id, $benutzer_id);
                    mysqli_stmt_fetch($stmt_id);
                } else {
                    $general_err = "Ihr Name wurde nicht gefunden, oder Sie sind bereits registriert.";
                }
            } else {
                $general_err = "Fehler bei der Namensprüfung.";
            }
            mysqli_stmt_close($stmt_id);
        }
    }

    // --- 2. Validierung des Benutzernamens (wenn Namensprüfung erfolgreich war)
    if (empty($general_err)) {
        if (empty($username)) {
            $username_err = "Bitte geben Sie einen Benutzernamen ein.";
        } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
            $username_err = "Der Benutzername darf nur Buchstaben, Zahlen, Punkte und Unterstriche enthalten.";
        } else {
            // Prüfe, ob der Benutzername bereits vergeben ist (in login_daten)
            $sql_check_user = "SELECT login_id FROM login_daten WHERE benutzername = ?";
            if ($stmt_check = mysqli_prepare($link, $sql_check_user)) {
                mysqli_stmt_bind_param($stmt_check, "s", $param_username);
                $param_username = $username;
                if (mysqli_stmt_execute($stmt_check)) {
                    mysqli_stmt_store_result($stmt_check);
                    if (mysqli_stmt_num_rows($stmt_check) == 1) {
                        $username_err = "Dieser Benutzername ist bereits vergeben.";
                    }
                }
                mysqli_stmt_close($stmt_check);
            }
        }

        // --- 3. Validierung der Passwörter
        if (empty($password)) {
            $password_err = "Bitte geben Sie ein Passwort ein.";
        } elseif (strlen($password) < 6) {
            $password_err = "Das Passwort muss mindestens 6 Zeichen lang sein.";
        }
        if (empty($confirm_password)) {
            $confirm_password_err = "Bitte bestätigen Sie das Passwort.";
        } elseif ($password != $confirm_password) {
            $confirm_password_err = "Die Passwörter stimmen nicht überein.";
        }

        // --- 4. Validierung Sicherheitsfrage
        if (empty($sicherheitsfrage) || !in_array($sicherheitsfrage, $sicherheitsfragen)) {
            $sicherheit_err = "Bitte wählen Sie eine Sicherheitsfrage aus.";
        } elseif (empty($sicherheitsantwort)) {
            $sicherheit_err = "Bitte geben Sie eine Antwort auf die Sicherheitsfrage ein.";
        }
    }

    // --- 5. Registrierung durchführen und HASH speichern
    if (empty($username_err) && empty($password_err) && empty($confirm_password_err) && empty($general_err) && empty($sicherheit_err) && $benutzer_id > 0) {

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $hashed_antwort  = password_hash(strtolower(trim($sicherheitsantwort)), PASSWORD_DEFAULT);

        $sql_insert = "INSERT INTO login_daten (benutzer_id, benutzername, passwort_hash) VALUES (?, ?, ?)";

        if ($stmt_insert = mysqli_prepare($link, $sql_insert)) {
            mysqli_stmt_bind_param($stmt_insert, "iss", $benutzer_id, $username, $hashed_password);

            if (mysqli_stmt_execute($stmt_insert)) {
                mysqli_stmt_close($stmt_insert);

                // Sicherheitsfrage und Antwort in benutzer speichern
                $st_sq = mysqli_prepare($link, "UPDATE benutzer SET sicherheitsfrage=?, sicherheitsantwort=? WHERE benutzer_id=?");
                mysqli_stmt_bind_param($st_sq, "ssi", $sicherheitsfrage, $hashed_antwort, $benutzer_id);
                mysqli_stmt_execute($st_sq);
                mysqli_stmt_close($st_sq);

                header("location: login.php");
                exit;
            } else {
                $general_err = "Fehler beim Speichern der Daten.";
                mysqli_stmt_close($stmt_insert);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrierung - Zeiterfassung</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* CSS-Styling wie zuvor */
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f4f7f9;
        }
        .register-container {
            width: 100%;
            max-width: 450px;
            padding: 30px;
            background-color: #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        .register-container h2 {
            text-align: center;
            color: #28a745;
            margin-bottom: 25px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .btn-primary {
            width: 100%;
            padding: 10px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.2s;
            margin-top: 10px;
        }
        .invalid-feedback {
            color: #dc3545;
            font-size: 0.85em;
            display: block;
            margin-top: 5px;
        }
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            text-align: center;
        }
        .link-text {
            text-align: center;
            margin-top: 20px;
        }
        select.form-control {
            background-color: #fff;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h2>Mitarbeiter-Registrierung</h2>
        <p>Erstellen Sie Ihr persönliches Login.</p>

        <?php 
        // Die Erfolgsmeldung wird nicht mehr angezeigt, da sofort weitergeleitet wird
        if (!empty($general_err)) {
            echo '<div class="alert-danger">' . $general_err . '</div>';
        }
        // $success_msg ist jetzt irrelevant
        ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label for="full_name">Ihr vollständiger Name (muss im System existieren)</label>
                <input type="text" name="full_name" id="full_name" class="form-control" value="<?php echo htmlspecialchars($full_name); ?>">
            </div>

            <div class="form-group">
                <label for="username">Gewünschter Benutzername</label>
                <input type="text" name="username" id="username" class="form-control" value="<?php echo htmlspecialchars($username); ?>">
                <span class="invalid-feedback"><?php echo $username_err; ?></span>
            </div>    
            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" name="password" id="password" class="form-control">
                <span class="invalid-feedback"><?php echo $password_err; ?></span>
            </div>
            <div class="form-group">
                <label for="confirm_password">Passwort bestätigen</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-control">
                <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
            </div>
            <div class="form-group">
                <label for="sicherheitsfrage">Sicherheitsfrage</label>
                <select name="sicherheitsfrage" id="sicherheitsfrage" class="form-control">
                    <option value="">-- Bitte wählen --</option>
                    <?php foreach ($sicherheitsfragen as $frage): ?>
                        <option value="<?php echo htmlspecialchars($frage); ?>" <?php echo ($sicherheitsfrage === $frage) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($frage); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="invalid-feedback"><?php echo $sicherheit_err; ?></span>
            </div>
            <div class="form-group">
                <label for="sicherheitsantwort">Antwort auf Sicherheitsfrage</label>
                <input type="text" name="sicherheitsantwort" id="sicherheitsantwort" class="form-control" value="<?php echo htmlspecialchars($sicherheitsantwort); ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <input type="submit" class="btn-primary" value="Registrieren">
            </div>
        </form>
        <div class="link-text">
            <p>Sie haben bereits ein Konto? <a href="login.php">Hier anmelden</a>.</p>
        </div>  
    </div>
</body>
</html>