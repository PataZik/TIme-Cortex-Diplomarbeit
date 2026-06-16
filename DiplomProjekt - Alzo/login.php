<?php
// login.php
session_start();

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("location: index.php");
    exit;
}
require_once 'db_config.php';

$username = $password = "";
$username_err = $password_err = $login_err = "";

// Login-Logik... (der restliche PHP-Code bleibt wie zuvor)
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. Validierung
    if (empty(trim($_POST["username"]))) {
        $username_err = "Bitte geben Sie Ihren Benutzernamen ein.";
    } else {
        $username = trim($_POST["username"]);
    }
    if (empty(trim($_POST["password"]))) {
        $password_err = "Bitte geben Sie Ihr Passwort ein.";
    } else {
        $password = trim($_POST["password"]);
    }

    // 2. Überprüfung in der Datenbank
    if (empty($username_err) && empty($password_err)) {
        
        $sql = "SELECT 
                    ld.benutzer_id, 
                    ld.benutzername, 
                    ld.passwort_hash, 
                    bp.rolle 
                FROM login_daten ld
                JOIN benutzerprofile bp ON ld.benutzer_id = bp.benutzer_id 
                WHERE ld.benutzername = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            $param_username = $username;

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_store_result($stmt);

                if (mysqli_stmt_num_rows($stmt) == 1) {
                    mysqli_stmt_bind_result($stmt, $id, $username, $hashed_password, $rolle);
                    if (mysqli_stmt_fetch($stmt)) {
                        
                        if (password_verify($password, $hashed_password)) {
                            // Passwort korrekt, Session starten
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["username"] = $username;
                            $_SESSION["rolle"] = $rolle; 
                            
                            // Letzten Login aktualisieren
                            $update_sql = "UPDATE login_daten SET letzter_login = NOW() WHERE benutzer_id = ?";
                            $update_stmt = mysqli_prepare($link, $update_sql);
                            mysqli_stmt_bind_param($update_stmt, "i", $id);
                            mysqli_stmt_execute($update_stmt);
                            mysqli_stmt_close($update_stmt);
                            
                            header("location: index.php");
                            exit;

                        } else {
                            $login_err = "Ungültiger Benutzername oder Passwort.";
                        }
                    }
                } else {
                    $login_err = "Ungültiger Benutzername oder Passwort.";
                }
            } else {
                echo "Hoppla! Etwas ist schiefgelaufen. Bitte versuchen Sie es später erneut.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Zeiterfassung</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* CSS-Styles... */
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f4f7f9;
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 30px;
            background-color: #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        .login-container h2 {
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
        .link-text {
            text-align: center;
            margin-top: 15px;
        }
        .forgot-password {
            float: right;
            font-size: 0.9em;
            margin-top: -15px; /* Etwas nach oben verschieben, damit es zur group gehört */
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Anmeldung zum System</h2>
        <p>Bitte melden Sie sich mit Ihren Zugangsdaten an.</p>

        <?php 
        if (!empty($login_err)) {
            echo '<div class="alert-danger">' . $login_err . '</div>';
        }        
        ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label for="username">Benutzername</label>
                <input type="text" name="username" id="username" class="form-control" value="<?php echo htmlspecialchars($username); ?>">
                <span class="invalid-feedback"><?php echo $username_err; ?></span>
            </div>    
            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" name="password" id="password" class="form-control">
                <span class="invalid-feedback"><?php echo $password_err; ?></span>
            </div>
            
            <div class="form-group">
                <input type="submit" class="btn-primary" value="Anmelden">
            </div>
        </form>
        <div class="link-text">
            <p>Noch nicht registriert? <a href="register.php">Hier neues Login erstellen</a>.</p>
        </div>
        <div class="forgot-password">
                <a href="passwort_vergessen.php">Passwort vergessen?</a>
                </div>
    </div>
</body>
</html>