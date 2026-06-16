<?php
/**
 * index.php - Zeiterfassung mit Pausenfunktion
 */
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: login.php"); exit;
}
require_once 'db_config.php';

$benutzer_id   = $_SESSION['id'];
$benutzer_name = $_SESSION['username'];
$rolle         = $_SESSION['rolle'];
$aktuelles_datum = date('Y-m-d');

// --- PAUSEN-TABELLE AUTO-ERSTELLEN ---
mysqli_query($link, "CREATE TABLE IF NOT EXISTS `pausen` (
    `pause_id`      INT AUTO_INCREMENT PRIMARY KEY,
    `benutzer_id`   INT NOT NULL,
    `anwesenheit_id` INT NOT NULL,
    `start_pause`   DATETIME NOT NULL,
    `ende_pause`    DATETIME DEFAULT NULL,
    KEY `bid` (`benutzer_id`),
    KEY `aid` (`anwesenheit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- WARTUNGSPROTOKOLLE ---
$wartungen = mysqli_fetch_all(mysqli_query($link,
    "SELECT beschreibung FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"
), MYSQLI_ASSOC);

// --- NACHRICHTEN DES BENUTZERS (nur ungelesen) ---
$nachrichten_user = mysqli_fetch_all(mysqli_query($link,
    "SELECT n.benachrichtigung_id, n.nachricht, n.zeitstempel, n.gelesen, b.name AS von_name
     FROM benachrichtigungen n
     LEFT JOIN benutzer b ON n.von_benutzer_id = b.benutzer_id
     WHERE n.benutzer_id = $benutzer_id AND n.zeitstempel >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     ORDER BY n.zeitstempel DESC LIMIT 20"
), MYSQLI_ASSOC);
$nachrichten_count = count(array_filter($nachrichten_user, fn($n) => !$n['gelesen']));

// --- HILFSFUNKTIONEN ---
function secToHMS(int $s): string {
    $s = max(0, $s);
    return sprintf('%02d:%02d:%02d', floor($s/3600), floor(($s%3600)/60), $s%60);
}
function secToHM(int $s): string {
    $s = max(0, $s);
    return sprintf('%02d:%02d', floor($s/3600), floor(($s%3600)/60));
}

// --- AKTIVE SESSION HOLEN ---
function getActiveSession($link, $bid, $datum): ?array {
    $st = mysqli_prepare($link,
        "SELECT anwesenheit_id, start_arbeitszeit FROM anwesenheitsaufzeichnungen
         WHERE benutzer_id=? AND anwesenheits_datum=?
           AND (ende_arbeitszeit='00:00:00' OR ende_arbeitszeit IS NULL) LIMIT 1");
    mysqli_stmt_bind_param($st, "is", $bid, $datum);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st)->fetch_assoc();
    mysqli_stmt_close($st);
    return $r ?: null;
}

// --- OFFENE PAUSE HOLEN ---
function getActivePause($link, $aid): ?array {
    if (!$aid) return null;
    $st = mysqli_prepare($link,
        "SELECT pause_id, start_pause FROM pausen WHERE anwesenheit_id=? AND ende_pause IS NULL LIMIT 1");
    mysqli_stmt_bind_param($st, "i", $aid);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st)->fetch_assoc();
    mysqli_stmt_close($st);
    return $r ?: null;
}

// --- ABGESCHLOSSENE PAUSEN (SEKUNDEN) ---
function getCompletedPauseSek($link, $aid): int {
    if (!$aid) return 0;
    $st = mysqli_prepare($link,
        "SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, start_pause, ende_pause)), 0) as sek
         FROM pausen WHERE anwesenheit_id=? AND ende_pause IS NOT NULL");
    mysqli_stmt_bind_param($st, "i", $aid);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st)->fetch_assoc();
    mysqli_stmt_close($st);
    return (int)($r['sek'] ?? 0);
}

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = key(array_intersect_key($_POST, array_flip(['start','stop','start_pause','end_pause','abort_pause'])));

    if ($action === 'start') {
        // Nur wenn keine offene Session existiert
        $existing = getActiveSession($link, $benutzer_id, $aktuelles_datum);
        if (!$existing) {
            $st = mysqli_prepare($link,
                "INSERT INTO anwesenheitsaufzeichnungen (benutzer_id, anwesenheits_datum, start_arbeitszeit, ende_arbeitszeit)
                 VALUES (?, ?, CURTIME(), '00:00:00')");
            mysqli_stmt_bind_param($st, "is", $benutzer_id, $aktuelles_datum);
            mysqli_stmt_execute($st); mysqli_stmt_close($st);
        }
    }

    elseif ($action === 'stop') {
        $session = getActiveSession($link, $benutzer_id, $aktuelles_datum);
        if ($session) {
            $aid = $session['anwesenheit_id'];

            // Offene Pause schließen
            $st = mysqli_prepare($link, "UPDATE pausen SET ende_pause=NOW() WHERE anwesenheit_id=? AND ende_pause IS NULL");
            mysqli_stmt_bind_param($st, "i", $aid);
            mysqli_stmt_execute($st); mysqli_stmt_close($st);

            // Gesamte Pausenzeit berechnen (alle Pausen inkl. gerade geschlossener)
            $st = mysqli_prepare($link,
                "SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, start_pause, ende_pause)), 0) as sek
                 FROM pausen WHERE anwesenheit_id=?");
            mysqli_stmt_bind_param($st, "i", $aid);
            mysqli_stmt_execute($st);
            $pause_sek = (int)mysqli_stmt_get_result($st)->fetch_assoc()['sek'];
            mysqli_stmt_close($st);

            // Netto-Arbeitszeit
            $start_ts  = strtotime($aktuelles_datum . ' ' . $session['start_arbeitszeit']);
            $brutto_sek = max(0, time() - $start_ts);
            $netto_sek  = max(0, $brutto_sek - $pause_sek);
            $netto_fmt  = secToHMS($netto_sek);
            $now_time   = date('H:i:s');

            $st = mysqli_prepare($link,
                "UPDATE anwesenheitsaufzeichnungen
                 SET ende_arbeitszeit=?, stunden_differenz=?
                 WHERE anwesenheit_id=?");
            mysqli_stmt_bind_param($st, "ssi", $now_time, $netto_fmt, $aid);
            mysqli_stmt_execute($st); mysqli_stmt_close($st);
        }
    }

    elseif ($action === 'start_pause') {
        $session = getActiveSession($link, $benutzer_id, $aktuelles_datum);
        if ($session) {
            $aid = $session['anwesenheit_id'];
            // Nur wenn keine Pause bereits offen
            $open = getActivePause($link, $aid);
            if (!$open) {
                $now = date('Y-m-d H:i:s');
                $st = mysqli_prepare($link,
                    "INSERT INTO pausen (benutzer_id, anwesenheit_id, start_pause) VALUES (?,?,?)");
                mysqli_stmt_bind_param($st, "iis", $benutzer_id, $aid, $now);
                mysqli_stmt_execute($st); mysqli_stmt_close($st);
            }
        }
    }

    elseif ($action === 'end_pause') {
        $session = getActiveSession($link, $benutzer_id, $aktuelles_datum);
        if ($session) {
            $aid = $session['anwesenheit_id'];
            $now = date('Y-m-d H:i:s');
            $st = mysqli_prepare($link,
                "UPDATE pausen SET ende_pause=? WHERE anwesenheit_id=? AND ende_pause IS NULL");
            mysqli_stmt_bind_param($st, "si", $now, $aid);
            mysqli_stmt_execute($st); mysqli_stmt_close($st);
        }
    }

    elseif ($action === 'abort_pause') {
        $session = getActiveSession($link, $benutzer_id, $aktuelles_datum);
        if ($session) {
            $aid = $session['anwesenheit_id'];
            // Nur erlaubt in den ersten 5 Minuten
            $open = getActivePause($link, $aid);
            if ($open && (time() - strtotime($open['start_pause'])) < 300) {
                $st = mysqli_prepare($link,
                    "DELETE FROM pausen WHERE anwesenheit_id=? AND ende_pause IS NULL");
                mysqli_stmt_bind_param($st, "i", $aid);
                mysqli_stmt_execute($st); mysqli_stmt_close($st);
            }
        }
    }

    header("Location: index.php"); exit;
}

// --- STATUS ERMITTELN ---
$laufende_session = getActiveSession($link, $benutzer_id, $aktuelles_datum);
$aktive_pause     = $laufende_session ? getActivePause($link, $laufende_session['anwesenheit_id']) : null;
$in_pause         = (bool)$aktive_pause;
$ist_aktiv        = $laufende_session && !$in_pause;
$nicht_gestartet  = !$laufende_session;

// --- TIMER-SEKUNDEN BERECHNEN ---
$aid = $laufende_session['anwesenheit_id'] ?? null;
$completed_pause_sek = $aid ? getCompletedPauseSek($link, $aid) : 0;

// Abgeschlossene Sessions heute (für Stop→Start Mehrsitzungs-Support)
$st_prev = mysqli_prepare($link,
    "SELECT COALESCE(SUM(TIME_TO_SEC(stunden_differenz)), 0) as sek
     FROM anwesenheitsaufzeichnungen WHERE benutzer_id=? AND anwesenheits_datum=?
       AND ende_arbeitszeit != '00:00:00' AND ende_arbeitszeit IS NOT NULL");
mysqli_stmt_bind_param($st_prev, "is", $benutzer_id, $aktuelles_datum);
mysqli_stmt_execute($st_prev);
$prev_completed_sek = (int)mysqli_stmt_get_result($st_prev)->fetch_assoc()['sek'];
mysqli_stmt_close($st_prev);

if ($laufende_session) {
    $session_start_ts = strtotime($aktuelles_datum . ' ' . $laufende_session['start_arbeitszeit']);

    if ($in_pause) {
        $pause_start_ts = strtotime($aktive_pause['start_pause']);
        $current_sek = max(0, ($pause_start_ts - $session_start_ts) - $completed_pause_sek);
    } else {
        $current_sek = max(0, (time() - $session_start_ts) - $completed_pause_sek);
    }
    $timer_sek = $prev_completed_sek + $current_sek;
} else {
    $timer_sek = $prev_completed_sek;
}

// Aktuelle Pausenzeit (laufende Pause)
$laufende_pause_sek = 0;
if ($in_pause) {
    $laufende_pause_sek = max(0, time() - strtotime($aktive_pause['start_pause']));
}

// Abgeschlossene Pausen aus anderen heutigen Sessions (nicht die aktive)
if ($aid) {
    $st_pp = mysqli_prepare($link,
        "SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND,p.start_pause,p.ende_pause)),0) AS sek
         FROM pausen p JOIN anwesenheitsaufzeichnungen a ON p.anwesenheit_id=a.anwesenheit_id
         WHERE a.benutzer_id=? AND a.anwesenheits_datum=? AND p.ende_pause IS NOT NULL AND a.anwesenheit_id != ?");
    mysqli_stmt_bind_param($st_pp, "isi", $benutzer_id, $aktuelles_datum, $aid);
} else {
    $st_pp = mysqli_prepare($link,
        "SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND,p.start_pause,p.ende_pause)),0) AS sek
         FROM pausen p JOIN anwesenheitsaufzeichnungen a ON p.anwesenheit_id=a.anwesenheit_id
         WHERE a.benutzer_id=? AND a.anwesenheits_datum=? AND p.ende_pause IS NOT NULL");
    mysqli_stmt_bind_param($st_pp, "is", $benutzer_id, $aktuelles_datum);
}
mysqli_stmt_execute($st_pp);
$prev_pause_sek = (int)mysqli_stmt_get_result($st_pp)->fetch_assoc()['sek'];
mysqli_stmt_close($st_pp);

$gesamt_pause_sek = $prev_pause_sek + $completed_pause_sek + $laufende_pause_sek;

// --- BENACHRICHTIGUNGEN: Pause-Erinnerung & 10h-Warnung ---
if ($laufende_session) {
    // Pause-Erinnerung: >6h gearbeitet, weniger als 30 Min Pause gemacht
    if ($timer_sek > 6 * 3600 && $gesamt_pause_sek < 1800) {
        $chk = mysqli_prepare($link,
            "SELECT COUNT(*) AS cnt FROM benachrichtigungen
             WHERE benutzer_id=? AND DATE(zeitstempel)=CURDATE() AND nachricht LIKE '%Pause-Erinnerung%'");
        mysqli_stmt_bind_param($chk, "i", $benutzer_id);
        mysqli_stmt_execute($chk);
        if (!(int)mysqli_stmt_get_result($chk)->fetch_assoc()['cnt']) {
            $msg = "Pause-Erinnerung: Sie arbeiten bereits über 6 Stunden und haben noch keine 30-minütige Pause gemacht. Bitte legen Sie jetzt eine Pause von mindestens 30 Minuten ein!";
            $now = date('Y-m-d H:i:s');
            $ins = mysqli_prepare($link, "INSERT INTO benachrichtigungen (benutzer_id, nachricht, zeitstempel, status) VALUES (?,?,?,'Gesendet')");
            mysqli_stmt_bind_param($ins, "iss", $benutzer_id, $msg, $now);
            mysqli_stmt_execute($ins);
        }
    }
    // 10h-Warnung: >10h netto gearbeitet
    if ($timer_sek > 10 * 3600) {
        $chk = mysqli_prepare($link,
            "SELECT COUNT(*) AS cnt FROM benachrichtigungen
             WHERE benutzer_id=? AND DATE(zeitstempel)=CURDATE() AND nachricht LIKE '%10 Stunden%'");
        mysqli_stmt_bind_param($chk, "i", $benutzer_id);
        mysqli_stmt_execute($chk);
        if (!(int)mysqli_stmt_get_result($chk)->fetch_assoc()['cnt']) {
            $msg = "Warnung: Sie haben heute bereits über 10 Stunden netto gearbeitet. Bitte beenden Sie Ihren Dienst!";
            $now = date('Y-m-d H:i:s');
            $ins = mysqli_prepare($link, "INSERT INTO benachrichtigungen (benutzer_id, nachricht, zeitstempel, status) VALUES (?,?,?,'Gesendet')");
            mysqli_stmt_bind_param($ins, "iss", $benutzer_id, $msg, $now);
            mysqli_stmt_execute($ins);
        }
    }
}

// --- SOLL-STUNDEN (NETTO) ---
$soll_sek = 0;
$netto_soll_sek = 0;
$st = mysqli_prepare($link,
    "SELECT a.soll_stunden_pro_tag, bp.anstellungs_art_id FROM benutzerprofile bp
     JOIN anstellungsarten a ON bp.anstellungs_art_id = a.art_id
     WHERE bp.benutzer_id=?");
mysqli_stmt_bind_param($st, "i", $benutzer_id);
mysqli_stmt_execute($st);
$soll_res = mysqli_stmt_get_result($st)->fetch_assoc();
mysqli_stmt_close($st);
if ($soll_res) {
    $p = explode(':', $soll_res['soll_stunden_pro_tag']);
    $soll_sek = ($p[0]*3600)+($p[1]*60)+($p[2]??0);
    // Netto = Brutto - Pausenabzug (30min für Vollzeit art_id=1)
    $pausenabzug = ($soll_res['anstellungs_art_id'] == 1) ? 1800 : 0;
    $netto_soll_sek = max(0, $soll_sek - $pausenabzug);
}

// --- URLAUB HEUTE ---
$ist_urlaub_heute = false;
$st_url = mysqli_prepare($link, "SELECT abwesenheit_id FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Urlaub' AND status='Genehmigt' AND ? BETWEEN abwesenheit_beginn AND abwesenheit_ende LIMIT 1");
mysqli_stmt_bind_param($st_url, "is", $benutzer_id, $aktuelles_datum);
mysqli_stmt_execute($st_url);
if (mysqli_stmt_get_result($st_url)->fetch_assoc()) $ist_urlaub_heute = true;
mysqli_stmt_close($st_url);

if ($ist_urlaub_heute) {
    $timer_sek        = 0;
    $gesamt_pause_sek = 0;
    $diff_sek         = 0;
    $ist_plus         = true;
} else {
    $diff_sek = $timer_sek - $netto_soll_sek;
    $ist_plus = $diff_sek >= 0;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Dashboard | Zeiterfassung</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* STATUS BADGE */
.status-row { display:flex; align-items:center; justify-content:center; gap:10px; margin-bottom:6px; }
.status-dot { width:12px; height:12px; border-radius:50%; display:inline-block; }
.dot-aktiv  { background:#2ecc71; box-shadow:0 0 8px #2ecc71; }
.dot-pause  { background:#ff8800; box-shadow:0 0 8px #ff8800; }
.dot-off    { background:#ff4d4d; box-shadow:0 0 8px #ff4d4d; }
.status-label { font-size:0.95rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:1px; }

/* TIMER */
.live-timer-aktiv { color:var(--success); }
.live-timer-pause { color:#ff8800; }
.live-timer-off   { color:var(--text-muted); }

/* BUTTONS */
.btn-group { display:flex; gap:14px; justify-content:center; flex-wrap:wrap; margin-top:20px; }
.btn-pause { background:#ff8800; color:#fff; }
.btn-pause:hover { filter:brightness(1.1); }
.btn-end-pause { background:#2ecc71; color:#fff; }
.btn-end-pause:hover { filter:brightness(1.1); }

/* KPI CARDS */
.kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-top:30px; max-width:700px; margin-left:auto; margin-right:auto; }
.kpi-box { background:rgba(255,255,255,0.04); border:1px solid #333; border-radius:12px; padding:16px; text-align:center; }
.kpi-box .kpi-val { font-size:1.5rem; font-weight:bold; margin-top:4px; font-family:'Courier New',monospace; }
.kpi-box .kpi-lbl { font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; }
.kpi-plus  { color:var(--success); }
.kpi-minus { color:var(--danger); }
.kpi-pause-color { color:#ff8800; }

/* PAUSE-TIMER SUB */
.pause-sub { font-size:0.85rem; color:#ff8800; margin-top:4px; font-family:'Courier New',monospace; }

/* LETZTE EINTRÄGE */
.entries-card { max-width:700px; margin:30px auto 0; }
.entries-card h3 { margin:0 0 14px; color:var(--text-muted); font-size:0.95rem; display:flex; align-items:center; gap:8px; }

@media(max-width:600px) {
    .kpi-row { grid-template-columns:1fr 1fr; }
    .btn-group { flex-direction:column; align-items:center; }
    .btn { width:280px; }
}
</style>
</head>
<body>

<?php $page = basename($_SERVER['PHP_SELF']); ?>
<nav class="navbar">
    <div class="nav-links">
        <a href="index.php" class="<?php echo $page=='index.php'?'active':''; ?>">Dashboard</a>
        <a href="statistik.php" class="<?php echo $page=='statistik.php'?'active':''; ?>">Statistik</a>
        <a href="abwesenheit_antrag.php" class="<?php echo $page=='abwesenheit_antrag.php'?'active':''; ?>">Abwesenheit</a>
<?php if ($rolle != 'Mitarbeiter'): ?>
            <a href="admin_dashboard.php" class="<?php echo $page=='admin_dashboard.php'?'active':''; ?>">Admin</a>
        <?php endif; ?>
    </div>
    <div class="user-info">
        <!-- GLOCKE: Wartungsprotokolle -->
        <div class="bell-container">
            <i class="fas fa-bell"></i>
            <div class="bell-dropdown">
                <?php if (!empty($wartungen)): ?>
                    <?php foreach ($wartungen as $w): ?>
                        <div class="bell-item">
                            <div><?php echo htmlspecialchars($w['beschreibung']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bell-item">Keine geplanten Wartungen</div>
                <?php endif; ?>
            </div>
        </div>
        <!-- NACHRICHTEN-ICON -->
        <div class="bell-container msg-container" style="color:#6bc5f8;" onmouseenter="this.querySelector('.bell-badge')?.remove();fetch('mark_nachrichten.php')">
            <i class="fas fa-envelope"></i>
            <?php if ($nachrichten_count > 0): ?>
                <span class="bell-badge"><?php echo $nachrichten_count; ?></span>
            <?php endif; ?>
            <div class="bell-dropdown" style="width:320px;">
                <div style="padding:10px 14px;font-weight:600;font-size:0.85rem;border-bottom:1px solid #333;color:#aaa;">
                    Meine Nachrichten
                </div>
                <?php if (!empty($nachrichten_user)): ?>
                    <?php foreach ($nachrichten_user as $n): ?>
                        <div class="bell-item" data-id="<?php echo (int)$n['benachrichtigung_id']; ?>">
                            <small><?php echo date('d.m.Y H:i', strtotime($n['zeitstempel'])); ?><?php if (!empty($n['von_name'])): ?> · Von: <?php echo htmlspecialchars($n['von_name']); ?><?php endif; ?></small>
                            <div><?php echo htmlspecialchars($n['nachricht']); ?></div>
                            <button class="msg-del-btn" title="Löschen"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bell-item" style="color:#666;">Keine Nachrichten</div>
                <?php endif; ?>
            </div>
        </div>
        <?php include '_navbar_profile.php'; ?>
    </div>
</nav>

<div class="container timer-container">
    <div class="dashboard-card timer-card">

        <!-- STATUS -->
        <div class="status-row">
            <?php if ($ist_aktiv): ?>
                <span class="status-dot dot-aktiv"></span>
                <span class="status-label">Aktiv</span>
            <?php elseif ($in_pause): ?>
                <span class="status-dot dot-pause"></span>
                <span class="status-label">Pause</span>
            <?php else: ?>
                <span class="status-dot dot-off"></span>
                <span class="status-label">Nicht gestartet</span>
            <?php endif; ?>
        </div>

        <!-- TIMER -->
        <div id="live-timer-display" class="live-timer <?php
            echo $ist_aktiv ? 'live-timer-aktiv' : ($in_pause ? 'live-timer-pause' : 'live-timer-off');
        ?>"><?php echo secToHMS($timer_sek); ?></div>

        <?php if ($in_pause): ?>
            <div class="pause-sub" id="pause-timer">
                Pause: <?php echo secToHMS($laufende_pause_sek); ?>
            </div>
        <?php endif; ?>

        <!-- BUTTONS -->
        <div class="btn-group">
            <?php if ($nicht_gestartet): ?>
                <form method="post">
                    <button type="submit" name="start" class="btn btn-start" style="padding:20px 60px;font-size:1.5rem;border-radius:50px;">
                        <i class="fas fa-play"></i> Dienst beginnen
                    </button>
                </form>

            <?php elseif ($ist_aktiv): ?>
                <form method="post">
                    <button type="submit" name="stop" class="btn btn-stop" style="padding:16px 40px;font-size:1.2rem;border-radius:40px;">
                        <i class="fas fa-stop"></i> Dienst beenden
                    </button>
                </form>
                <form method="post">
                    <button type="submit" name="start_pause" class="btn btn-pause" style="padding:16px 40px;font-size:1.2rem;border-radius:40px;">
                        <i class="fas fa-pause"></i> Pause starten
                    </button>
                </form>

            <?php elseif ($in_pause): ?>
                <form method="post">
                    <button type="submit" name="end_pause" class="btn btn-end-pause" style="padding:16px 40px;font-size:1.2rem;border-radius:40px;">
                        <i class="fas fa-play"></i> Pause beenden
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($laufende_session): ?>
            <div style="color:var(--text-muted);font-size:0.85rem;margin-top:12px;">
                Beginn: <?php echo substr($laufende_session['start_arbeitszeit'],0,5); ?> Uhr
            </div>
        <?php endif; ?>

        <!-- KPI CARDS -->
        <div class="kpi-row">
            <div class="kpi-box">
                <div class="kpi-lbl">Gearbeitet</div>
                <div class="kpi-val" id="kpi-gearbeitet"><?php echo secToHM($timer_sek); ?></div>
            </div>
            <div class="kpi-box">
                <div class="kpi-lbl">Soll (Netto)</div>
                <div class="kpi-val" style="color:var(--text-muted);"><?php echo secToHM($netto_soll_sek); ?></div>
            </div>
            <div class="kpi-box">
                <div class="kpi-lbl"><?php echo $ist_plus ? 'Überstunden' : 'Fehlstunden'; ?></div>
                <div class="kpi-val <?php echo $ist_plus ? 'kpi-plus' : 'kpi-minus'; ?>">
                    <?php echo ($ist_plus ? '+' : '-') . secToHM(abs($diff_sek)); ?>
                </div>
            </div>
            <div class="kpi-box">
                <div class="kpi-lbl">Pause heute</div>
                <div class="kpi-val kpi-pause-color" id="kpi-pause"><?php echo secToHM($gesamt_pause_sek); ?></div>
            </div>
        </div>


        <a href="logout.php" style="display:inline-block;margin-top:24px;color:var(--text-muted);font-size:0.85rem;text-decoration:none;">
            <i class="fas fa-sign-out-alt"></i> Abmelden
        </a>
    </div>
</div>

<script>
<?php if ($ist_aktiv): ?>
    // AKTIV: Timer läuft hoch
    let seconds   = <?php echo (int)$timer_sek; ?>;
    let soll      = <?php echo (int)$soll_sek; ?>;

    function fmt(s) {
        s = Math.max(0, s);
        let h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60;
        return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(sec).padStart(2,'0');
    }
    function fmtHM(s) {
        s = Math.max(0, s);
        let h = Math.floor(s/3600), m = Math.floor((s%3600)/60);
        return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0');
    }

    setInterval(() => {
        seconds++;
        document.getElementById('live-timer-display').innerText = fmt(seconds);
        document.getElementById('kpi-gearbeitet').innerText = fmtHM(seconds);
    }, 1000);

<?php elseif ($in_pause): ?>
    // PAUSE: Haupt-Timer eingefroren, Pausen-Timer läuft
    let pauseSek = <?php echo (int)$laufende_pause_sek; ?>;
    let gesamtPauseSek = <?php echo (int)$gesamt_pause_sek; ?>;

    function fmt(s) {
        s = Math.max(0, s);
        let h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60;
        return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(sec).padStart(2,'0');
    }
    function fmtHM(s) {
        s = Math.max(0, s);
        let h = Math.floor(s/3600), m = Math.floor((s%3600)/60);
        return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0');
    }

    setInterval(() => {
        pauseSek++;
        gesamtPauseSek++;
        const el = document.getElementById('pause-timer');
        if (el) el.innerText = 'Pause: ' + fmt(pauseSek);
        const kpi = document.getElementById('kpi-pause');
        if (kpi) kpi.innerText = fmtHM(gesamtPauseSek);
    }, 1000);
<?php endif; ?>
</script>

</body>
</html>
