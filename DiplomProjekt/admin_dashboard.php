<?php
/**
 * admin_dashboard.php - Zentrale Verwaltung für Admins & Manager
 */
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['loggedin']) || ($_SESSION['rolle'] != 'Admin' && $_SESSION['rolle'] != 'Manager')) {
    header("location: index.php"); exit;
}

$benutzer_name = $_SESSION['username'];
$rolle = $_SESSION['rolle'];

// --- NAVBAR ---
$wartungen = mysqli_fetch_all(mysqli_query($link,
    "SELECT beschreibung FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"
), MYSQLI_ASSOC);
$session_id = (int)$_SESSION['id'];
$st_n = mysqli_prepare($link, "SELECT n.benachrichtigung_id, n.nachricht, n.zeitstempel, n.gelesen, b.name AS von_name FROM benachrichtigungen n LEFT JOIN benutzer b ON n.von_benutzer_id = b.benutzer_id WHERE n.benutzer_id=? AND n.zeitstempel >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY n.zeitstempel DESC LIMIT 20");
mysqli_stmt_bind_param($st_n, "i", $session_id);
mysqli_stmt_execute($st_n);
$nachrichten_user = mysqli_fetch_all(mysqli_stmt_get_result($st_n), MYSQLI_ASSOC);
mysqli_stmt_close($st_n);
$nachrichten_count = count(array_filter($nachrichten_user, fn($n) => !$n['gelesen']));

// --- FEHLENDE AUSSTEMPELUNGEN ---
$res_open_sessions = mysqli_query($link,
    "SELECT b.name, a.anwesenheits_datum, a.start_arbeitszeit,
            CASE WHEN p.pause_id IS NOT NULL THEN 1 ELSE 0 END as in_pause
     FROM anwesenheitsaufzeichnungen a
     JOIN benutzer b ON a.benutzer_id = b.benutzer_id
     LEFT JOIN pausen p ON p.anwesenheit_id = a.anwesenheit_id AND p.ende_pause IS NULL
     WHERE (a.ende_arbeitszeit = '00:00:00' OR a.ende_arbeitszeit IS NULL)
     ORDER BY a.anwesenheits_datum DESC, a.start_arbeitszeit DESC"
);

// --- HEUTIGE ARBEITSZEIT ---
$res_today = mysqli_query($link,
    "SELECT b.name,
        COALESCE(SUM(TIME_TO_SEC(a.stunden_differenz)),0) AS stunden_sek,
        COALESCE((
            SELECT SUM(TIMESTAMPDIFF(SECOND, p.start_pause, COALESCE(p.ende_pause, NOW())))
            FROM pausen p
            JOIN anwesenheitsaufzeichnungen a2 ON p.anwesenheit_id = a2.anwesenheit_id
            WHERE a2.benutzer_id = b.benutzer_id AND a2.anwesenheits_datum = CURDATE()
        ),0) AS pause_sek
     FROM benutzer b
     JOIN anwesenheitsaufzeichnungen a ON b.benutzer_id=a.benutzer_id AND a.anwesenheits_datum=CURDATE()
     WHERE NOT EXISTS (
         SELECT 1 FROM abwesenheiten ab
         WHERE ab.benutzer_id = b.benutzer_id AND ab.abwesenheit_typ = 'Urlaub'
           AND ab.status = 'Genehmigt' AND CURDATE() BETWEEN ab.abwesenheit_beginn AND ab.abwesenheit_ende
     )
     GROUP BY b.benutzer_id, b.name
     HAVING stunden_sek > 0
     ORDER BY stunden_sek DESC, b.name ASC"
);

// --- MEHR ALS 10h HEUTE ---
$res_10h = mysqli_query($link,
    "SELECT b.name,
        ROUND(SUM(TIME_TO_SEC(a.stunden_differenz))/3600, 2) AS stunden_heute
     FROM benutzer b
     JOIN anwesenheitsaufzeichnungen a ON b.benutzer_id=a.benutzer_id
     WHERE a.anwesenheits_datum=CURDATE()
       AND a.stunden_differenz IS NOT NULL AND a.stunden_differenz != '00:00:00'
       AND NOT EXISTS (
           SELECT 1 FROM abwesenheiten ab
           WHERE ab.benutzer_id = b.benutzer_id AND ab.abwesenheit_typ = 'Urlaub'
             AND ab.status = 'Genehmigt' AND CURDATE() BETWEEN ab.abwesenheit_beginn AND ab.abwesenheit_ende
       )
     GROUP BY b.benutzer_id, b.name
     HAVING SUM(TIME_TO_SEC(a.stunden_differenz)) > 36000
     ORDER BY stunden_heute DESC"
);
$zehn_h_warnungen = mysqli_fetch_all($res_10h, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin-Bereich | Zeiterfassung</title>
    <link rel="stylesheet" href="index.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<?php $page = basename($_SERVER['PHP_SELF']); ?>
<nav class="navbar">
    <div class="nav-links">
        <a href="index.php">Dashboard</a>
        <a href="statistik.php">Statistik</a>
        <a href="abwesenheit_antrag.php">Abwesenheit</a>
        <?php if ($rolle != 'Mitarbeiter'): ?>
            <a href="admin_dashboard.php" class="active">Admin</a>
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
        <?php include '_navbar_profile.php'; ?>
    </div>
</nav>

<div style="background:#111;border-bottom:1px solid #333;padding:0 30px;display:flex;gap:5px;">
    <a href="admin_dashboard.php" style="color:#007bff;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid #007bff;display:inline-block;">Übersicht</a>
    <a href="admin_benutzer.php" style="color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;">Benutzer</a>
    <a href="admin_zeiterfassung.php" style="color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;">Zeiterfassung</a>
    <a href="admin_abwesenheiten.php" style="color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;">Abwesenheiten</a>
    <a href="admin_sicherheit.php" style="color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;">Sicherheit</a>
    <a href="admin_benachrichtigungen.php" style="color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;">Benachrichtigungen</a>
    <a href="admin_berichte.php" style="color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;">Berichte</a>
    <a href="admin_wartung.php" style="color:#aaa;text-decoration:none;padding:12px 16px;font-size:0.88rem;border-bottom:3px solid transparent;display:inline-block;">Wartung</a>
</div>

<div class="container">
    <div class="page-width">
        <h1 class="page-title">Übersicht</h1>


        <div class="admin-grid">

            <!-- LINKE SPALTE: >10h heute + Sessions -->
            <div class="dashboard-card">
                <?php if (!empty($zehn_h_warnungen)): ?>
                <h3 class="section-title">
                    <i class="fas fa-triangle-exclamation" style="color:var(--danger);"></i>
                    Heute &gt; 10h gearbeitet
                </h3>
                <table class="admin-table" style="margin-bottom:6px;">
                    <thead><tr><th>Mitarbeiter</th><th>Stunden heute</th></tr></thead>
                    <tbody>
                    <?php foreach ($zehn_h_warnungen as $z): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($z['name']); ?></td>
                            <td style="color:var(--danger);font-weight:700;"><?php echo number_format((float)$z['stunden_heute'], 2, ',', '.'); ?> h</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <hr class="soft-divider">
                <?php endif; ?>

                <h3 class="section-title">
                    <i class="fas fa-triangle-exclamation" style="color:var(--warning);"></i>
                    Nicht beendete Sessions
                </h3>
                <table class="admin-table">
                    <thead><tr><th>Name</th><th>Datum</th><th>Beginn</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php
                    $open_rows = mysqli_fetch_all($res_open_sessions, MYSQLI_ASSOC);
                    if ($open_rows): foreach ($open_rows as $open): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($open['name']); ?></td>
                            <td><?php echo date('d.m.Y', strtotime($open['anwesenheits_datum'])); ?></td>
                            <td><span class="status-badge warning-badge"><?php echo substr($open['start_arbeitszeit'],0,5); ?></span></td>
                            <td>
                                <?php if ($open['in_pause']): ?>
                                    <span style="background:rgba(255,136,0,0.15);color:#ff8800;border:1px solid #ff8800;padding:2px 10px;border-radius:10px;font-size:0.75rem;font-weight:600;">In Pause</span>
                                <?php else: ?>
                                    <span style="background:rgba(46,204,113,0.15);color:#2ecc71;border:1px solid #2ecc71;padding:2px 10px;border-radius:10px;font-size:0.75rem;font-weight:600;">Aktiv</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" class="muted-text">Keine offenen Sessions.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- RECHTE SPALTE: Heutige Arbeitszeit -->
            <div class="dashboard-card">
                <h3 class="section-title">
                    <i class="fas fa-clock" style="color:var(--primary-color);"></i>
                    Heutige Arbeitszeit
                </h3>
                <table class="admin-table">
                    <thead><tr><th>Mitarbeiter</th><th>Arbeitszeit</th><th>Pause</th></tr></thead>
                    <tbody>
                    <?php
                    $today_rows = mysqli_fetch_all($res_today, MYSQLI_ASSOC);
                    if ($today_rows): foreach ($today_rows as $td):
                        $ws = (int)$td['stunden_sek'];
                        $ps = (int)$td['pause_sek'];
                        $wfmt = sprintf('%d:%02d h', floor($ws/3600), floor(($ws%3600)/60));
                        $pfmt = $ps > 0 ? sprintf('%d:%02d h', floor($ps/3600), floor(($ps%3600)/60)) : '—';
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($td['name']); ?></td>
                            <td><?php echo $wfmt; ?></td>
                            <td style="color:#aaa;"><?php echo $pfmt; ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3" class="muted-text">Heute keine Einträge.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

</body>
</html>
