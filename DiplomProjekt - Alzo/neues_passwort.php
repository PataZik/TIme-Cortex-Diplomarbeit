<?php
// neues_passwort.php
require_once 'db_config.php';

$token = $password = $confirm_password = "";
$password_err = $confirm_password_err = $general_err = "";
$valid_request = false;
$benutzer_id = 0;
$benutzer_name = 'Unbekannter Benutzer'; // Standardwert

// ##################################################################
// 1. TOKEN-VALIDIERUNG & BENUTZER-ID ABRUFEN
// ##################################################################
if (isset($_GET['token']) && !empty(trim($_GET['token']))) {
    $token = trim($_GET['token']);

    // Finde den Benutzer, dessen Antrag genehmigt und aktiv ist
    $sql_check_token = "SELECT benutzer_id FROM passwort_reset_antraege WHERE token = ? AND status = 'Genehmigt'";
    
    if ($stmt = mysqli_prepare($link, $sql_check_token)) {
        mysqli_stmt_bind_param($stmt, "s", $param_token);
        $param_token = $token;

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_store_result($stmt);
            
            if (mysqli_stmt_num_rows($stmt) == 1) {
                mysqli_stmt_bind_result($stmt, $benutzer_id);
                mysqli_stmt_fetch($stmt);
                $valid_request = true;
            } else {
                $general_err = "Ungültiger oder abgelaufener Reset-Link. Bitte stellen Sie einen neuen Antrag.";
            }
        } else {
            $general_err = "Fehler bei der Datenbankabfrage.";
        }
        mysqli_stmt_close($stmt);
    }
} else {
    $general_err = "Kein gültiger Token angegeben.";
}

// ##################################################################
// NEU: VOLLSTÄNDIGEN NAMEN ABRUFEN, WENN ID GEFUNDEN WURDE
// ##################################################################
if ($valid_request && $benutzer_id > 0) {
    $sql_get_name = "SELECT name FROM benutzer WHERE benutzer_id = ?";
    if ($stmt_name = mysqli_prepare($link, $sql_get_name)) {
        mysqli_stmt_bind_param($stmt_name, "i", $benutzer_id);
        if (mysqli_stmt_execute($stmt_name)) {
            mysqli_stmt_bind_result($stmt_name, $retrieved_name);
            mysqli_stmt_fetch($stmt_name);
            $benutzer_name = htmlspecialchars($retrieved_name);
        }
        mysqli_stmt_close($stmt_name);
    }
}

// ##################################################################
// 2. PASSWORT-ÄNDERUNG (bleibt unverändert)
// ##################################################################
if ($_SERVER["REQUEST_METHOD"] == "POST" && $valid_request) {

    $password = trim($_POST["password"]);
    $confirm_password = trim($_POST["confirm_password"]);
    // benutzer_id stammt aus dem Token-Lookup oben – POST-Wert wird ignoriert

    // Validierung
    if (empty($password)) {
        $password_err = "Bitte geben Sie ein neues Passwort ein.";
    } elseif (strlen($password) < 6) {
        $password_err = "Das Passwort muss mindestens 6 Zeichen lang sein.";
    }
    if (empty($confirm_password) || ($password != $confirm_password)) {
        $confirm_password_err = "Die Passwörter stimmen nicht überein.";
    }

    if (empty($password_err) && empty($confirm_password_err)) {
        
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $sql_update_pass = "UPDATE login_daten SET passwort_hash = ? WHERE benutzer_id = ?";
        $sql_clear_token = "UPDATE passwort_reset_antraege SET status = 'Abgeschlossen', token = NULL WHERE benutzer_id = ? AND token = ?";

        mysqli_begin_transaction($link);
        $success = false;

        if ($stmt_pass = mysqli_prepare($link, $sql_update_pass)) {
            mysqli_stmt_bind_param($stmt_pass, "si", $hashed_password, $benutzer_id);
            if (mysqli_stmt_execute($stmt_pass)) {
                
                if ($stmt_clear = mysqli_prepare($link, $sql_clear_token)) {
                    mysqli_stmt_bind_param($stmt_clear, "is", $benutzer_id, $token);
                    if (mysqli_stmt_execute($stmt_clear)) {
                        mysqli_commit($link);
                        $success = true;
                    } else {
                        mysqli_rollback($link);
                    }
                    mysqli_stmt_close($stmt_clear);
                }
            }
            mysqli_stmt_close($stmt_pass);
        }
        
        if ($success) {
            header("location: login.php?reset=success");
            exit;
        } else {
            $general_err = "Fehler beim Setzen des Passworts. Bitte versuchen Sie es erneut.";
        }
    }
}

// ##################################################################
// 3. HTML-Ausgabe
// ##################################################################
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neues Passwort setzen</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* CSS-Styles wie zuvor */
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f4f7f9;
        }
        .container {
            width: 100%;
            max-width: 400px;
            padding: 30px;
            background-color: #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        .container h2 {
            text-align: center;
            color: #007bff;
            margin-bottom: 25px;
        }
        .form-group {
            margin-bottom: 20px;
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
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.2s;
        }
        .invalid-feedback {
            color: #dc3545;
            font-size: 0.9em;
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
    </style>
</head>
<body>
    <div class="container">
        <h2>Passwort festlegen</h2>
        
        <?php 
        if (!empty($general_err)) {
            echo '<div class="alert-danger">' . $general_err . '</div>';
        }
        
        if ($valid_request && empty($general_err)):
        ?>
            <p>Geben Sie Ihr neues Passwort für <strong><?php echo $benutzer_name; ?></strong> ein.</p>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . "?token=" . urlencode($token); ?>" method="post">
                <input type="hidden" name="benutzer_id" value="<?php echo $benutzer_id; ?>">
                
                <div class="form-group">
                    <label for="password">Neues Passwort</label>
                    <input type="password" name="password" id="password" class="form-control">
                    <span class="invalid-feedback"><?php echo $password_err; ?></span>
                </div>    
                <div class="form-group">
                    <label for="confirm_password">Passwort bestätigen</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control">
                    <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                </div>
                
                <div class="form-group">
                    <input type="submit" class="btn-primary" value="Passwort speichern">
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>