<?php
session_start();
if (empty($_SESSION['reset_antrag_id'])) {
    header("location: passwort_vergessen.php"); exit;
}
$antrag_id = (int)$_SESSION['reset_antrag_id'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bitte warten...</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { display:flex; justify-content:center; align-items:center; min-height:100vh; background-color:#111; }
        .container { width:100%; max-width:460px; padding:40px; background:#1a1a1a; border:1px solid #333; box-shadow:0 4px 24px rgba(0,0,0,0.5); border-radius:12px; color:#ddd; text-align:center; }
        h2 { color:#ffcc00; margin-bottom:12px; }
        .status-box { margin:28px 0; }
        .spinner { display:inline-block; width:48px; height:48px; border:5px solid #333; border-top:5px solid #ffcc00; border-radius:50%; animation:spin 1s linear infinite; margin-bottom:16px; }
        @keyframes spin { to { transform:rotate(360deg); } }
        #status-text { font-size:1rem; color:#aaa; }
        .alert-success { background:rgba(46,204,113,0.15); border:1px solid #2ecc71; color:#2ecc71; padding:16px; border-radius:8px; margin-top:20px; display:none; }
        .alert-rejected { background:rgba(255,77,77,0.12); border:1px solid #ff4d4d; color:#ff4d4d; padding:16px; border-radius:8px; margin-top:20px; display:none; }
        .btn { display:inline-block; margin-top:16px; padding:10px 28px; background:#007bff; color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:0.95rem; font-weight:600; text-decoration:none; }
        .btn-yellow { background:#ffcc00; color:#111; }
        .link-text { margin-top:24px; font-size:0.88rem; }
        .link-text a { color:#555; text-decoration:none; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Antrag wird geprüft</h2>
        <p style="color:#888;font-size:0.9rem;">Ihr Passwort-Reset-Antrag wurde an einen Administrator gesendet. Bitte warten Sie auf die Genehmigung.</p>

        <div class="status-box" id="waiting-box">
            <div class="spinner"></div>
            <div id="status-text">Warte auf Administrator...</div>
        </div>

        <div class="alert-success" id="box-approved">
            <strong>Genehmigt!</strong> Ihr Antrag wurde genehmigt.<br>
            <a href="#" id="reset-link" class="btn btn-yellow" style="margin-top:14px;">Neues Passwort setzen</a>
        </div>

        <div class="alert-rejected" id="box-rejected">
            <strong>Abgelehnt.</strong> Ihr Antrag wurde abgelehnt.<br>
            <a href="passwort_vergessen.php" class="btn" style="margin-top:14px;">Erneut versuchen</a>
        </div>

        <div class="link-text">
            <a href="login.php">Abbrechen und zurück zur Anmeldung</a>
        </div>
    </div>

<script>
(function() {
    var antragId = <?php echo $antrag_id; ?>;
    var interval;

    function check() {
        fetch('reset_status.php?id=' + antragId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'Genehmigt' && data.token) {
                    clearInterval(interval);
                    window.location.href = 'neues_passwort.php?token=' + encodeURIComponent(data.token);
                } else if (data.status === 'Abgelehnt') {
                    clearInterval(interval);
                    document.getElementById('waiting-box').style.display = 'none';
                    document.getElementById('box-rejected').style.display = 'block';
                }
            })
            .catch(function() {});
    }

    check();
    interval = setInterval(check, 3000);
})();
</script>
</body>
</html>
