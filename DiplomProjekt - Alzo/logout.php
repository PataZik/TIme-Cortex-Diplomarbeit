<?php
session_start();

/* Alle Session Variablen löschen */
$_SESSION = [];

/* Session zerstören */
session_destroy();

/* Zur Login Seite zurück */
header("Location: login.php");
exit;
?>