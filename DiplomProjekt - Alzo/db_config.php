
<?php
/**
 * db_config.php
 * Konfigurationsdatei für die Datenbankverbindung
 * ERSETZEN SIE DIE PLATZHALTER MIT IHREN ZUGANGSDATEN!
 */

// Datenbankverbindungsdaten
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); // Ihr MySQL-Benutzername
define('DB_PASSWORD', '');     // Ihr MySQL-Passwort
define('DB_NAME', 'diplomprojekt');       // Ihr Datenbankname (aus dem SQL-Skript)

// Verbindung herstellen (MySQLi-Erweiterung)
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Prüfen, ob die Verbindung erfolgreich war
if($link === false){
    die("FEHLER: Konnte keine Verbindung zur Datenbank aufbauen. " . mysqli_connect_error());
}

// Setze den Zeichensatz auf UTF-8
mysqli_set_charset($link, "utf8mb4");

// Spalte gelesen für Benachrichtigungen (einmalig anlegen)
mysqli_query($link, "ALTER TABLE benachrichtigungen ADD COLUMN IF NOT EXISTS gelesen TINYINT DEFAULT 0");
// Spalte von_benutzer_id für Absender (einmalig anlegen)
mysqli_query($link, "ALTER TABLE benachrichtigungen ADD COLUMN IF NOT EXISTS von_benutzer_id INT NULL DEFAULT NULL");
// Sicherheitsfragen für Passwort-Reset (einmalig anlegen)
mysqli_query($link, "ALTER TABLE benutzer ADD COLUMN IF NOT EXISTS sicherheitsfrage VARCHAR(255) NULL DEFAULT NULL");
mysqli_query($link, "ALTER TABLE benutzer ADD COLUMN IF NOT EXISTS sicherheitsantwort VARCHAR(255) NULL DEFAULT NULL");
// Spalte grund für Abwesenheiten (einmalig anlegen)
mysqli_query($link, "ALTER TABLE abwesenheiten ADD COLUMN IF NOT EXISTS grund TEXT NULL DEFAULT NULL");
// Spalte profilbild für Benutzer (einmalig anlegen)
mysqli_query($link, "ALTER TABLE benutzer ADD COLUMN IF NOT EXISTS profilbild VARCHAR(255) DEFAULT NULL");
// Auto-Pause-Flag für Pausen (einmalig anlegen)
mysqli_query($link, "ALTER TABLE pausen ADD COLUMN IF NOT EXISTS is_auto TINYINT DEFAULT 0");

// Profilbild und Rolle des eingeloggten Benutzers laden (für Navbar + Rechtekontrolle)
$profilbild = null;
if (isset($_SESSION['id'])) {
    $tmp = mysqli_query($link, "SELECT b.profilbild, bp.rolle FROM benutzer b LEFT JOIN benutzerprofile bp ON b.benutzer_id=bp.benutzer_id WHERE b.benutzer_id=" . (int)$_SESSION['id']);
    if ($tmp) {
        $pbrow = mysqli_fetch_assoc($tmp);
        $profilbild = $pbrow['profilbild'] ?? null;
        if (!empty($pbrow['rolle'])) $_SESSION['rolle'] = $pbrow['rolle'];
    }
}
?>