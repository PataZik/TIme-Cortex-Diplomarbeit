<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: login.php"); exit;
}
require_once 'db_config.php';

// DB-Migration: verspätungszeit Spalte hinzufügen falls nicht vorhanden
mysqli_query($link, "ALTER TABLE abwesenheiten ADD COLUMN IF NOT EXISTS verspätungszeit TIME NULL DEFAULT NULL");

$benutzer_id   = $_SESSION['id'];
$benutzer_name = $_SESSION['username'];
$rolle         = $_SESSION['rolle'];
$success = '';
$error   = '';

// --- ANTRAG EINREICHEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'antrag') {
    $typ   = trim($_POST['abwesenheit_typ'] ?? '');
    $von   = trim($_POST['abwesenheit_beginn'] ?? '');
    $bis   = trim($_POST['abwesenheit_ende'] ?? '');
    $grund = trim($_POST['grund'] ?? '');

    $erlaubte_typen = ['Urlaub', 'Krank', 'Pflegeurlaub', 'Zeitausgleich', 'Verspätung', 'Persönlicher Feiertag', 'Sonstiges'];

    if ($typ === 'Persönlicher Feiertag') {
        $min_datum = date('Y-m-d', strtotime('+3 months'));
        if ($von !== $bis) {
            $error = "Persönlicher Feiertag kann nur 1 Tag dauern.";
        } elseif ($von < $min_datum) {
            $error = "Muss mindestens 3 Monate im Voraus beantragt werden (frühestens " . date('d.m.Y', strtotime($min_datum)) . ").";
        } else {
            $pf_jahr = (int)date('Y', strtotime($von));
            $pf_chk = mysqli_prepare($link, "SELECT COUNT(*) AS cnt FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Persönlicher Feiertag' AND YEAR(abwesenheit_beginn)=? AND status != 'Abgelehnt'");
            mysqli_stmt_bind_param($pf_chk, "ii", $benutzer_id, $pf_jahr);
            mysqli_stmt_execute($pf_chk);
            if ((int)mysqli_stmt_get_result($pf_chk)->fetch_assoc()['cnt'] > 0) {
                $error = "Du hast für $pf_jahr bereits einen Persönlichen Feiertag beantragt.";
            }
        }
    }

    $verspaetungszeit = null;
    if ($typ === 'Verspätung') {
        $vt = trim($_POST['verspaetungszeit'] ?? '');
        if (!$vt) {
            $error = "Bitte die Ankommenszeit angeben.";
        } else {
            $ankunft_sek = strtotime("1970-01-01 $vt:00");
            $start_sek   = strtotime("1970-01-01 08:00:00");
            $late_sek    = $ankunft_sek - $start_sek;
            if ($late_sek <= 0) {
                $error = "Ankommenszeit muss nach 08:00 liegen.";
            } else {
                $verspaetungszeit = sprintf('%02d:%02d:00', floor($late_sek/3600), floor(($late_sek%3600)/60));
                // Bei Verspätung: Datum kommt aus "bis"-Feld (als "Datum"-Feld angezeigt)
                $von = $bis;
            }
        }
    }

    if (!$error && !in_array($typ, $erlaubte_typen)) {
        $error = "Ungültiger Abwesenheitstyp.";
    } elseif (!$error && (!$von || !$bis)) {
        $error = "Bitte Von- und Bis-Datum angeben.";
    } elseif (!$error && $von > $bis) {
        $error = "Das Von-Datum darf nicht nach dem Bis-Datum liegen.";
    } elseif (!$error) {
        // Überschneidungs-Check
        $ov_st = mysqli_prepare($link,
            "SELECT abwesenheit_typ, abwesenheit_beginn, abwesenheit_ende
             FROM abwesenheiten
             WHERE benutzer_id=? AND status != 'Abgelehnt'
               AND abwesenheit_beginn <= ? AND abwesenheit_ende >= ?
             LIMIT 1");
        mysqli_stmt_bind_param($ov_st, "iss", $benutzer_id, $bis, $von);
        mysqli_stmt_execute($ov_st);
        $ov_row = mysqli_stmt_get_result($ov_st)->fetch_assoc();
        mysqli_stmt_close($ov_st);
        if ($ov_row) {
            $error = "Überschneidung mit bestehender Abwesenheit ("
                . htmlspecialchars($ov_row['abwesenheit_typ']) . ", "
                . date('d.m.Y', strtotime($ov_row['abwesenheit_beginn'])) . " – "
                . date('d.m.Y', strtotime($ov_row['abwesenheit_ende'])) . ").";
        } else {
            $st = mysqli_prepare($link,
                "INSERT INTO abwesenheiten (benutzer_id, abwesenheit_beginn, abwesenheit_ende, abwesenheit_typ, status, grund, verspätungszeit)
                 VALUES (?, ?, ?, ?, 'Ausstehend', ?, ?)");
            mysqli_stmt_bind_param($st, "isssss", $benutzer_id, $von, $bis, $typ, $grund, $verspaetungszeit);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            $success = "Antrag eingereicht! Er wird vom Admin geprüft.";
        }
    }
}

// --- ANTRAG STORNIEREN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stornieren') {
    $aid = (int)$_POST['abwesenheit_id'];
    $st = mysqli_prepare($link,
        "DELETE FROM abwesenheiten WHERE abwesenheit_id=? AND benutzer_id=? AND status='Ausstehend'");
    mysqli_stmt_bind_param($st, "ii", $aid, $benutzer_id);
    mysqli_stmt_execute($st);
    $success = "Antrag storniert.";
}

// --- MEINE ANTRÄGE LADEN ---
$st_ma = mysqli_prepare($link, "SELECT * FROM abwesenheiten WHERE benutzer_id = ? ORDER BY abwesenheit_beginn DESC LIMIT 30");
mysqli_stmt_bind_param($st_ma, "i", $benutzer_id);
mysqli_stmt_execute($st_ma);
$meine_antraege = mysqli_fetch_all(mysqli_stmt_get_result($st_ma), MYSQLI_ASSOC);
mysqli_stmt_close($st_ma);

// --- ÜBERSTUNDEN-SALDO FÜR ZEITAUSGLEICH ---
// Soll pro Tag holen
$st_p = mysqli_prepare($link,
    "SELECT p.anstellungs_art_id, a.soll_stunden_pro_tag
     FROM benutzerprofile p
     JOIN anstellungsarten a ON p.anstellungs_art_id = a.art_id
     WHERE p.benutzer_id = ?");
mysqli_stmt_bind_param($st_p, "i", $benutzer_id);
mysqli_stmt_execute($st_p);
mysqli_stmt_bind_result($st_p, $za_art_id, $za_soll_string);
mysqli_stmt_fetch($st_p);
mysqli_stmt_close($st_p);
$za_brutto_soll_sek = 0;
if ($za_soll_string) {
    $p = explode(':', $za_soll_string);
    $za_brutto_soll_sek = ($p[0]*3600)+($p[1]*60)+($p[2]??0);
}
$za_pausen_sek     = ($za_art_id == 1) ? 1800 : 0;
$za_netto_soll_sek = max(0, $za_brutto_soll_sek - $za_pausen_sek);

// Verlaufshistorie für datumsgenaues Soll
$st_vlf2 = mysqli_prepare($link,
    "SELECT v.gueltig_ab, v.anstellungs_art_id, a.soll_stunden_pro_tag
     FROM anstellungsart_verlauf v
     JOIN anstellungsarten a ON v.anstellungs_art_id = a.art_id
     WHERE v.benutzer_id = ?
     ORDER BY v.gueltig_ab ASC");
mysqli_stmt_bind_param($st_vlf2, "i", $benutzer_id);
mysqli_stmt_execute($st_vlf2);
$za_verlauf = mysqli_fetch_all(mysqli_stmt_get_result($st_vlf2), MYSQLI_ASSOC);
mysqli_stmt_close($st_vlf2);

if (!function_exists('netto_soll_for_date')) {
    function netto_soll_for_date(array $verlauf, string $date, int $fallback): int {
        $result = $fallback;
        foreach ($verlauf as $v) {
            if ($v['gueltig_ab'] <= $date) {
                $p = explode(':', $v['soll_stunden_pro_tag']);
                $brutto = ($p[0]*3600)+($p[1]*60)+($p[2]??0);
                $result = max(0, $brutto - ((int)$v['anstellungs_art_id'] === 1 ? 1800 : 0));
            } else { break; }
        }
        return $result;
    }
}

// Alle Ist-Stunden summieren
$st_ug = mysqli_prepare($link,
    "SELECT a.anwesenheits_datum,
            TIME_TO_SEC(a.stunden_differenz) as brutto_sek,
            COALESCE((SELECT SUM(TIMESTAMPDIFF(SECOND, p.start_pause, COALESCE(p.ende_pause, a.ende_arbeitszeit)))
                      FROM pausen p WHERE p.anwesenheit_id = a.anwesenheit_id), 0) as pause_sek
     FROM anwesenheitsaufzeichnungen a
     WHERE a.benutzer_id = ? AND a.stunden_differenz IS NOT NULL AND a.stunden_differenz != '00:00:00'");
mysqli_stmt_bind_param($st_ug, "i", $benutzer_id);
mysqli_stmt_execute($st_ug);
$ug_res = mysqli_stmt_get_result($st_ug);
$za_ueberstunden_sek = 0;
while ($ugrow = mysqli_fetch_assoc($ug_res)) {
    $netto_ist = max(0, (int)$ugrow['brutto_sek'] - (int)$ugrow['pause_sek']);
    $soll_d    = netto_soll_for_date($za_verlauf, $ugrow['anwesenheits_datum'], $za_netto_soll_sek);
    $za_ueberstunden_sek += ($netto_ist - $soll_d);
}

// Feiertage laden
$alle_feiertage_za = [];
$res_fj = mysqli_query($link, "SELECT datum FROM feiertage");
if ($res_fj) while ($fj = mysqli_fetch_assoc($res_fj)) $alle_feiertage_za[$fj['datum']] = true;

// Genehmigte ZA-Abwesenheiten abziehen
$st_za_all = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Zeitausgleich' AND status='Genehmigt'");
mysqli_stmt_bind_param($st_za_all, "i", $benutzer_id);
mysqli_stmt_execute($st_za_all);
$res_za_all = mysqli_stmt_get_result($st_za_all);
if ($res_za_all) while ($za_row = mysqli_fetch_assoc($res_za_all)) {
    $d = new DateTime($za_row['abwesenheit_beginn']);
    $end = new DateTime($za_row['abwesenheit_ende']);
    while ($d <= $end) {
        $ds = $d->format('Y-m-d');
        if ((int)$d->format('N') <= 5 && !isset($alle_feiertage_za[$ds])) {
            $za_ueberstunden_sek -= netto_soll_for_date($za_verlauf, $ds, $za_netto_soll_sek);
        }
        $d->modify('+1 day');
    }
}

// Genehmigte Verspätungen abziehen — nur tatsächliche Verspätung
$st_vz = mysqli_prepare($link,
    "SELECT v.abwesenheit_beginn, v.verspätungszeit, a.start_arbeitszeit
     FROM abwesenheiten v
     LEFT JOIN anwesenheitsaufzeichnungen a ON a.benutzer_id=v.benutzer_id AND a.anwesenheits_datum=v.abwesenheit_beginn
     WHERE v.benutzer_id=? AND v.abwesenheit_typ='Verspätung' AND v.status='Genehmigt' AND v.verspätungszeit IS NOT NULL");
mysqli_stmt_bind_param($st_vz, "i", $benutzer_id);
mysqli_stmt_execute($st_vz);
$res_vz = mysqli_stmt_get_result($st_vz);
while ($vz = mysqli_fetch_assoc($res_vz)) {
    $vp = explode(':', $vz['verspätungszeit']);
    $approved_sek = ($vp[0]*3600)+($vp[1]*60)+($vp[2]??0);
    $soll_vz = netto_soll_for_date($za_verlauf, $vz['abwesenheit_beginn'] ?? date('Y-m-d'), $za_netto_soll_sek);
    if (!empty($vz['start_arbeitszeit']) && $vz['start_arbeitszeit'] !== '00:00:00') {
        $sp = explode(':', $vz['start_arbeitszeit']);
        $actual_late_sek = max(0, ($sp[0]*3600)+($sp[1]*60) - 8*3600);
        $credit = $approved_sek - max(0, $actual_late_sek - $approved_sek);
        $za_ueberstunden_sek += $credit;
    } else {
        $za_ueberstunden_sek -= max(0, $soll_vz - $approved_sek);
    }
}

// Fehlende Werktage abziehen (Tage ohne Eintrag und ohne genehmigte Abwesenheit)
$firma_start_za = '2026-01-01';
$st_ed = mysqli_prepare($link, "SELECT DISTINCT anwesenheits_datum FROM anwesenheitsaufzeichnungen WHERE benutzer_id=?");
mysqli_stmt_bind_param($st_ed, "i", $benutzer_id);
mysqli_stmt_execute($st_ed);
$res_ed = mysqli_stmt_get_result($st_ed);
$entry_days_za = [];
while ($ed = mysqli_fetch_assoc($res_ed)) $entry_days_za[$ed['anwesenheits_datum']] = true;

$abs_days_za = [];
$st_abd = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND status='Genehmigt'");
mysqli_stmt_bind_param($st_abd, "i", $benutzer_id);
mysqli_stmt_execute($st_abd);
$res_abd = mysqli_stmt_get_result($st_abd);
while ($ab = mysqli_fetch_assoc($res_abd)) {
    $dd = $ab['abwesenheit_beginn'];
    while ($dd <= $ab['abwesenheit_ende']) { $abs_days_za[$dd] = true; $dd = date('Y-m-d', strtotime("$dd +1 day")); }
}
$dd = $firma_start_za;
$heute_za = date('Y-m-d');
while ($dd <= $heute_za) {
    if ((int)date('N', strtotime($dd)) <= 5 && !isset($alle_feiertage_za[$dd]) && !isset($entry_days_za[$dd]) && !isset($abs_days_za[$dd])) {
        $za_ueberstunden_sek -= netto_soll_for_date($za_verlauf, $dd, $za_netto_soll_sek);
    }
    $dd = date('Y-m-d', strtotime("$dd +1 day"));
}

// Persönlicher Feiertag – bereits beantragt dieses Jahr?
$pf_verwendet = false;
$pf_st = mysqli_prepare($link, "SELECT COUNT(*) AS cnt FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Persönlicher Feiertag' AND YEAR(abwesenheit_beginn)=? AND status != 'Abgelehnt'");
$pf_year = (int)date('Y');
mysqli_stmt_bind_param($pf_st, "ii", $benutzer_id, $pf_year);
mysqli_stmt_execute($pf_st);
$pf_verwendet = (int)mysqli_stmt_get_result($pf_st)->fetch_assoc()['cnt'] > 0;
$pf_min_datum = date('Y-m-d', strtotime('+3 months'));
$pf_min_label = date('d.m.Y', strtotime('+3 months'));

// Feiertage als JSON für JS
$feiertage_json = json_encode(array_keys($alle_feiertage_za));
$ueberstunden_sek_js = $za_ueberstunden_sek;
$netto_soll_sek_js   = $za_netto_soll_sek;

// --- NAVBAR ---
$wartungen = mysqli_fetch_all(mysqli_query($link,
    "SELECT beschreibung FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"
), MYSQLI_ASSOC);
$st_n = mysqli_prepare($link, "SELECT n.benachrichtigung_id, n.nachricht, n.zeitstempel, n.gelesen, b.name AS von_name FROM benachrichtigungen n LEFT JOIN benutzer b ON n.von_benutzer_id = b.benutzer_id WHERE n.benutzer_id=? AND n.zeitstempel >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY n.zeitstempel DESC LIMIT 20");
mysqli_stmt_bind_param($st_n, "i", $benutzer_id);
mysqli_stmt_execute($st_n);
$nachrichten_user = mysqli_fetch_all(mysqli_stmt_get_result($st_n), MYSQLI_ASSOC);
mysqli_stmt_close($st_n);
$nachrichten_count = count(array_filter($nachrichten_user, fn($n) => !$n['gelesen']));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Abwesenheitsantrag | Zeiterfassung</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.antrag-card { background:var(--card-bg); border:1px solid #333; border-radius:16px; padding:28px; margin-bottom:25px; }
.antrag-card h3 { margin:0 0 20px; font-size:1rem; display:flex; align-items:center; gap:8px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px; }
.form-group { display:flex; flex-direction:column; gap:5px; }
.form-group label { font-size:0.85rem; color:#aaa; }
.form-group select, .form-group input, .form-group textarea {
    background:#1a1a1a; border:1px solid #444; border-radius:8px; padding:9px 12px; color:#fff; font-family:inherit;
}
.form-group textarea { resize:vertical; height:70px; }
.btn-submit { padding:11px 28px; background:#007bff; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:0.95rem; }
.btn-submit:hover { filter:brightness(1.1); }
.alert-success { background:rgba(46,204,113,0.15); border:1px solid #2ecc71; color:#2ecc71; padding:12px 16px; border-radius:8px; margin-bottom:20px; }
.alert-error   { background:rgba(255,77,77,0.15); border:1px solid #ff4d4d; color:#ff4d4d; padding:12px 16px; border-radius:8px; margin-bottom:20px; }
.badge { padding:3px 10px; border-radius:12px; font-size:0.75rem; font-weight:600; }
.badge-Ausstehend  { background:rgba(255,204,0,0.15); color:#ffcc00; border:1px solid #ffcc00; }
.badge-Genehmigt   { background:rgba(46,204,113,0.15); color:#2ecc71; border:1px solid #2ecc71; }
.badge-Abgelehnt   { background:rgba(255,77,77,0.15); color:#ff4d4d; border:1px solid #ff4d4d; }
.btn-sm { padding:4px 10px; border-radius:6px; border:1px solid #ff4d4d; color:#ff4d4d; background:none; cursor:pointer; font-size:0.8rem; }
.btn-sm:hover { background:#ff4d4d; color:#fff; }
@media(max-width:700px){ .form-row { grid-template-columns:1fr; } }
#za-info-panel {
    display:none;
    background:rgba(0,119,255,0.08);
    border:1px solid #0055cc;
    border-radius:10px;
    padding:16px 20px;
    margin-bottom:16px;
}
#za-info-panel .za-balance { font-size:1.1rem; font-weight:700; color:#6bc5f8; margin-bottom:8px; }
#za-info-panel .za-calc { font-size:0.88rem; color:#aaa; }
#za-info-panel .za-warning { color:#ff4d4d; font-weight:600; margin-top:8px; display:none; }
#za-info-panel .za-ok { color:#2ecc71; font-weight:600; margin-top:8px; display:none; }
#pf-info-panel {
    display:none;
    background:rgba(255,204,0,0.07);
    border:1px solid #a07800;
    border-radius:10px;
    padding:16px 20px;
    margin-bottom:16px;
    font-size:0.88rem;
    color:#ccc;
}
#pf-info-panel strong { color:#ffcc00; }
#pf-info-panel ul { margin:8px 0 0 18px; padding:0; line-height:1.8; }
#pf-info-panel .pf-used { color:#ff4d4d; font-weight:600; margin-top:10px; }
#pf-info-panel .pf-ok  { color:#2ecc71; font-weight:600; margin-top:10px; }
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
            <a href="admin_dashboard.php">Admin</a>
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
        <div class="bell-container msg-container" style="color:#6bc5f8;"
             onmouseenter="this.querySelector('.bell-badge')?.remove();fetch('mark_nachrichten.php')">
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

<div class="container" style="display:block;padding-top:40px;">
<div style="max-width:860px;margin:0 auto;">

    <h1 class="page-title">Abwesenheitsantrag</h1>

    <?php if ($success): ?><div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <!-- ANTRAG STELLEN -->
    <div class="antrag-card">
        <h3><i class="fas fa-paper-plane" style="color:#007bff;"></i> Neuen Antrag stellen</h3>
        <form method="POST">
            <input type="hidden" name="action" value="antrag">
            <div class="form-row">
                <div class="form-group">
                    <label>Art der Abwesenheit</label>
                    <select name="abwesenheit_typ" id="typ-select" onchange="onTypChange()" required>
                        <option value="Urlaub">🏖️ Urlaub</option>
                        <option value="Krank">🤒 Krankenstand</option>
                        <option value="Pflegeurlaub">👶 Pflegeurlaub</option>
                        <option value="Zeitausgleich">⏱️ Zeitausgleich</option>
                        <option value="Verspätung">⏰ Verspätung</option>
                        <option value="Persönlicher Feiertag">🎉 Persönlicher Feiertag</option>
                        <option value="Sonstiges">📋 Sonstiges</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>&nbsp;</label><!-- spacer -->
                </div>
            </div>

            <!-- PERSÖNLICHER FEIERTAG INFO PANEL -->
            <div id="pf-info-panel">
                <strong>🎉 Persönlicher Feiertag</strong> – ersetzt den Karfreitag als gesetzlichen Feiertag in Österreich.
                <ul>
                    <li>Maximal <strong>1 Tag pro Urlaubsjahr</strong></li>
                    <li>Muss <strong>mindestens 3 Monate im Voraus</strong> schriftlich beantragt werden</li>
                    <li>Der Arbeitgeber kann diesen Urlaub <strong>nicht verweigern</strong></li>
                    <li>Frühestmögliches Datum: <strong><?php echo $pf_min_label; ?></strong></li>
                </ul>
                <?php if ($pf_verwendet): ?>
                <div class="pf-used"><i class="fas fa-times-circle"></i> Du hast für <?php echo $pf_year; ?> bereits einen Persönlichen Feiertag beantragt.</div>
                <?php else: ?>
                <div class="pf-ok"><i class="fas fa-check-circle"></i> Du hast für <?php echo $pf_year; ?> noch keinen Persönlichen Feiertag verwendet.</div>
                <?php endif; ?>
            </div>

            <!-- ZA INFO PANEL -->
            <div id="za-info-panel">
                <div class="za-balance" id="za-balance-text"></div>
                <div class="za-calc" id="za-calc-text"></div>
                <div class="za-warning" id="za-warning">Nicht genug Überstunden für diesen Zeitraum!</div>
                <div class="za-ok" id="za-ok"><i class="fas fa-check-circle"></i> Zeitausgleich möglich</div>
            </div>

            <div class="form-row" id="datum-row">
                <!-- Linke Spalte: normal = Von-Datum; bei Verspätung = Ankommenszeit -->
                <div class="form-group" id="verspaetung-row" style="display:none;">
                    <label>Ankommenszeit <span style="color:#aaa;font-size:0.78rem;font-weight:400;">(Verspätungszeit wird ab 08:00 berechnet)</span></label>
                    <input type="time" name="verspaetungszeit" id="verspaetungszeit" step="60" style="background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:9px 12px;color:#fff;width:100%;box-sizing:border-box;">
                </div>
                <div class="form-group" id="von-group">
                    <label id="label-von">Von</label>
                    <input type="date" name="abwesenheit_beginn" id="datum-von" required min="<?php echo date('Y-m-d'); ?>">
                </div>
                <!-- Rechte Spalte: Datum (bei Verspätung = Datum des Tages) -->
                <div class="form-group" id="bis-group">
                    <label id="label-bis">Bis</label>
                    <input type="date" name="abwesenheit_ende" id="datum-bis" required min="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:18px;">
                <label>Begründung <span style="color:#555;">(optional)</span></label>
                <textarea name="grund" placeholder="z.B. geplante Reise, Arzttermin..."></textarea>
            </div>
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Antrag einreichen</button>
        </form>
    </div>

    <!-- MEINE ANTRÄGE -->
    <div class="antrag-card">
        <h3><i class="fas fa-list" style="color:#ffcc00;"></i> Meine Anträge</h3>
        <?php if (empty($meine_antraege)): ?>
            <p style="color:#555;text-align:center;padding:20px 0;">Noch keine Anträge vorhanden.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Typ</th>
                    <th>Von</th>
                    <th>Bis</th>
                    <th>Tage</th>
                    <th>Status</th>
                    <th>Begründung</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($meine_antraege as $a):
                $isVerspaet = $a['abwesenheit_typ'] === 'Verspätung';
                if ($isVerspaet) {
                    $tage = '—';
                } else {
                    $tage = 0;
                    $d = $a['abwesenheit_beginn'];
                    while ($d <= $a['abwesenheit_ende']) {
                        if ((int)date('N', strtotime($d)) <= 5 && !isset($alle_feiertage_za[$d])) $tage++;
                        $d = date('Y-m-d', strtotime("$d +1 day"));
                    }
                }
            ?>
                <tr>
                    <td><?php
                        echo htmlspecialchars($a['abwesenheit_typ']);
                        if ($isVerspaet && !empty($a['verspätungszeit'])) {
                            $vp = explode(':', $a['verspätungszeit']);
                            $ankunft_sek = 8*3600 + ($vp[0]*3600)+($vp[1]*60);
                            $ankunft = sprintf('%02d:%02d', floor($ankunft_sek/3600), floor(($ankunft_sek%3600)/60));
                            echo ' <span style="color:#ff8800;font-size:0.78rem;">(08:00 – '.$ankunft.')</span>';
                        }
                    ?></td>
                    <td><?php echo date('d.m.Y', strtotime($a['abwesenheit_beginn'])); ?></td>
                    <td><?php echo date('d.m.Y', strtotime($isVerspaet ? $a['abwesenheit_beginn'] : $a['abwesenheit_ende'])); ?></td>
                    <td style="color:#aaa;"><?php echo $tage; ?></td>
                    <td><span class="badge badge-<?php echo htmlspecialchars($a['status']); ?>"><?php echo htmlspecialchars($a['status']); ?></span></td>
                    <td style="color:#888;font-size:0.85rem;max-width:200px;word-break:break-word;">
                        <?php echo htmlspecialchars($a['grund'] ?? '—'); ?>
                    </td>
                    <td>
                        <?php if ($a['status'] === 'Ausstehend'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Antrag stornieren?')">
                            <input type="hidden" name="action" value="stornieren">
                            <input type="hidden" name="abwesenheit_id" value="<?php echo (int)$a['abwesenheit_id']; ?>">
                            <button type="submit" class="btn-sm"><i class="fas fa-times"></i> Stornieren</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>
</div>

<script>
const FEIERTAGE = new Set(<?php echo $feiertage_json; ?>);
const UEBERSTUNDEN_SEK = <?php echo (int)$ueberstunden_sek_js; ?>;
const NETTO_SOLL_SEK   = <?php echo (int)$netto_soll_sek_js; ?>;

function countWorkdays(von, bis) {
    if (!von || !bis || von > bis) return 0;
    let count = 0;
    let d = new Date(von + 'T00:00:00');
    const end = new Date(bis + 'T00:00:00');
    while (d <= end) {
        const dow = d.getDay(); // 0=So,6=Sa
        const ds = d.toISOString().slice(0, 10);
        if (dow !== 0 && dow !== 6 && !FEIERTAGE.has(ds)) count++;
        d.setDate(d.getDate() + 1);
    }
    return count;
}

function formatH(sek) {
    sek = Math.max(0, sek);
    const h = Math.floor(sek / 3600);
    const m = Math.floor((sek % 3600) / 60);
    return h + 'h ' + String(m).padStart(2, '0') + 'min';
}

const PF_MIN       = '<?php echo $pf_min_datum; ?>';
const PF_VERWENDET = <?php echo $pf_verwendet ? 'true' : 'false'; ?>;

function onTypChange() {
    const typ         = document.getElementById('typ-select').value;
    const verspaetRow = document.getElementById('verspaetung-row');
    const vonGroup    = document.getElementById('von-group');
    const bisGroup    = document.getElementById('bis-group');
    const labelBis    = document.getElementById('label-bis');
    const datumBis    = document.getElementById('datum-bis');
    const datumVon    = document.getElementById('datum-von');
    const pfPanel     = document.getElementById('pf-info-panel');

    // Reset
    verspaetRow.style.display = 'none';
    vonGroup.style.display    = '';
    bisGroup.style.display    = '';
    labelBis.textContent      = 'Bis';
    datumVon.min              = '<?php echo date('Y-m-d'); ?>';
    datumBis.min              = '<?php echo date('Y-m-d'); ?>';
    datumBis.readOnly         = false;
    datumVon.required         = true;
    pfPanel.style.display     = 'none';

    if (typ === 'Verspätung') {
        verspaetRow.style.display = '';
        vonGroup.style.display    = 'none';
        bisGroup.style.display    = '';
        labelBis.textContent      = 'Datum';
        datumVon.required         = false;
    } else if (typ === 'Persönlicher Feiertag') {
        pfPanel.style.display = 'block';
        datumVon.min          = PF_MIN;
        datumBis.min          = PF_MIN;
        labelBis.textContent  = 'Bis (gleicher Tag)';
        datumBis.readOnly     = true;
        datumBis.style.opacity = '0.5';
        if (PF_VERWENDET) {
            pfPanel.style.border = '1px solid #ff4d4d';
            pfPanel.style.background = 'rgba(255,77,77,0.07)';
            const usedEl = pfPanel.querySelector('.pf-used');
            if (usedEl) { usedEl.style.fontSize = '1rem'; usedEl.textContent = '❌ 1 Tag wurde schon benutzt – kein weiterer Persönlicher Feiertag in ' + new Date().getFullYear() + ' möglich.'; }
        }
        // Bis synchron zu Von halten
        datumVon.addEventListener('change', syncPfBis);
        syncPfBis();
    } else {
        datumBis.style.opacity = '1';
    }
    updateZAPanel();
}

function syncPfBis() {
    const von = document.getElementById('datum-von').value;
    document.getElementById('datum-bis').value = von;
}

function updateZAPanel() {
    const typ = document.getElementById('typ-select').value;
    const panel = document.getElementById('za-info-panel');
    if (typ !== 'Zeitausgleich') { panel.style.display = 'none'; return; }
    panel.style.display = 'block';

    const von = document.getElementById('datum-von').value;
    const bis = document.getElementById('datum-bis').value;

    const balSign = UEBERSTUNDEN_SEK >= 0 ? '+' : '-';
    const balAbs  = Math.abs(UEBERSTUNDEN_SEK);
    document.getElementById('za-balance-text').textContent =
        '⏱️ Aktuelles Überstundenkonto: ' + balSign + formatH(balAbs);

    const warn = document.getElementById('za-warning');
    const ok   = document.getElementById('za-ok');
    const calc = document.getElementById('za-calc-text');
    warn.style.display = 'none';
    ok.style.display   = 'none';

    if (!von || !bis || von > bis) {
        calc.textContent = 'Bitte Von- und Bis-Datum wählen um den Verbrauch zu berechnen.';
        return;
    }

    const tage = countWorkdays(von, bis);
    const benoetigt_sek = tage * NETTO_SOLL_SEK;
    const verbleibend   = UEBERSTUNDEN_SEK - benoetigt_sek;

    calc.textContent = tage + ' Arbeitstag(e) × ' + formatH(NETTO_SOLL_SEK) + ' = ' +
        formatH(benoetigt_sek) + ' Verbrauch → Verbleibend: ' + formatH(Math.abs(verbleibend));

    if (benoetigt_sek > UEBERSTUNDEN_SEK) {
        warn.style.display = 'block';
        ok.style.display   = 'none';
    } else {
        ok.style.display   = 'block';
        warn.style.display = 'none';
    }
}

document.getElementById('datum-von').addEventListener('change', updateZAPanel);
document.getElementById('datum-bis').addEventListener('change', updateZAPanel);
onTypChange();
</script>
</body>
</html>
