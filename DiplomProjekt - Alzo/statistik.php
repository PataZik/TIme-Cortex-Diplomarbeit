<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: login.php"); exit;
}
require_once 'db_config.php';

$rolle       = $_SESSION['rolle'];
$benutzer_id = $_SESSION['id'];
$benutzer_name = $_SESSION['username'];

// --- HILFSFUNKTIONEN ---
function toSek($t): int {
    if (!$t || $t === '00:00:00') return 0;
    $p = explode(':', $t);
    return ($p[0]*3600)+($p[1]*60)+($p[2]??0);
}
function formatH(int $sek): string {
    $sek = max(0, $sek);
    return sprintf('%02d:%02d', floor($sek/3600), floor(($sek%3600)/60));
}
function getPercent(int $sek): float {
    return min(100, ($sek / (12*3600)) * 100);
}

// --- WARTUNGSPROTOKOLLE ---
$wartungen = mysqli_fetch_all(mysqli_query($link,
    "SELECT beschreibung FROM wartungsprotokolle WHERE start_zeitpunkt >= NOW() ORDER BY start_zeitpunkt ASC LIMIT 5"
), MYSQLI_ASSOC);

// --- NACHRICHTEN (nur ungelesen) ---
$st_n = mysqli_prepare($link, "SELECT n.benachrichtigung_id, n.nachricht, n.zeitstempel, n.gelesen, b.name AS von_name FROM benachrichtigungen n LEFT JOIN benutzer b ON n.von_benutzer_id = b.benutzer_id WHERE n.benutzer_id=? AND n.zeitstempel >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY n.zeitstempel DESC LIMIT 20");
mysqli_stmt_bind_param($st_n, "i", $benutzer_id);
mysqli_stmt_execute($st_n);
$nachrichten_user = mysqli_fetch_all(mysqli_stmt_get_result($st_n), MYSQLI_ASSOC);
mysqli_stmt_close($st_n);
$nachrichten_count = count(array_filter($nachrichten_user, fn($n) => !$n['gelesen']));

// --- PROFIL & SOLL ---
$sql_profil = "SELECT p.anstellungs_art_id, a.soll_stunden_pro_tag, p.urlaubstage_gesamt
               FROM benutzerprofile p
               JOIN anstellungsarten a ON p.anstellungs_art_id = a.art_id
               WHERE p.benutzer_id = ?";
$st_p = mysqli_prepare($link, $sql_profil);
mysqli_stmt_bind_param($st_p, "i", $benutzer_id);
mysqli_stmt_execute($st_p);
mysqli_stmt_bind_result($st_p, $art_id, $soll_string, $urlaubstage_gesamt);
mysqli_stmt_fetch($st_p);
mysqli_stmt_close($st_p);
$brutto_soll_tag_sek = toSek($soll_string);
$pausen_pro_tag_sek  = ($art_id == 1) ? 1800 : 0;
$urlaubstage_gesamt  = (int)($urlaubstage_gesamt ?? 25);

// --- ANSTELLUNGSART-VERLAUF (für datumsgenaue Soll-Berechnung) ---
$st_vlf = mysqli_prepare($link,
    "SELECT v.gueltig_ab, v.anstellungs_art_id, a.soll_stunden_pro_tag
     FROM anstellungsart_verlauf v
     JOIN anstellungsarten a ON v.anstellungs_art_id = a.art_id
     WHERE v.benutzer_id = ?
     ORDER BY v.gueltig_ab ASC");
mysqli_stmt_bind_param($st_vlf, "i", $benutzer_id);
mysqli_stmt_execute($st_vlf);
$verlauf_eintraege = mysqli_fetch_all(mysqli_stmt_get_result($st_vlf), MYSQLI_ASSOC);
mysqli_stmt_close($st_vlf);

// Gibt das Netto-Soll in Sekunden für ein bestimmtes Datum zurück
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

// --- WOCHEN-NAVIGATION ---
$week_offset = isset($_GET['week']) ? (int)$_GET['week'] : 0;
$monday      = date('Y-m-d', strtotime("monday this week $week_offset weeks"));
$sunday      = date('Y-m-d', strtotime("sunday this week $week_offset weeks"));
$heute_datum = date('Y-m-d');
$friday      = date('Y-m-d', strtotime("$monday +4 days"));
$kw_bis      = min($friday, $heute_datum);

// --- FILTER-MODUS (für Detailtabelle) ---
$mode    = in_array($_GET['mode'] ?? '', ['woche','monat','custom']) ? $_GET['mode'] : 'woche';
// Monat/Jahr aus aktueller Woche ableiten, wenn nicht explizit gesetzt
$f_monat = isset($_GET['f_monat']) ? (int)$_GET['f_monat'] : (int)date('n', strtotime($monday));
$f_jahr  = isset($_GET['f_jahr'])  ? (int)$_GET['f_jahr']  : (int)date('Y', strtotime($monday));
$f_von   = $_GET['f_von'] ?? date('Y-m-01');
$f_bis   = $_GET['f_bis'] ?? date('Y-m-d');

if ($mode === 'woche') {
    $detail_von = $monday;
    $detail_bis = $sunday;
} elseif ($mode === 'monat') {
    $detail_von = sprintf('%04d-%02d-01', $f_jahr, $f_monat);
    $detail_bis = date('Y-m-t', strtotime($detail_von));
} else {
    $detail_von = $f_von;
    $detail_bis = $f_bis;
}

// --- FIRMENGRÜNDUNG ---
$firma_start = '2026-01-01';

// --- ALLE FEIERTAGE ---
$alle_feiertage = [];
$res_af = mysqli_query($link, "SELECT datum FROM feiertage");
if ($res_af) while ($af = mysqli_fetch_assoc($res_af)) $alle_feiertage[$af['datum']] = true;

// --- URLAUBSANSPRUCH (nur Werktage, keine Feiertage) ---
$st_u = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND status='Genehmigt' AND abwesenheit_typ='Urlaub'");
mysqli_stmt_bind_param($st_u, "i", $benutzer_id);
mysqli_stmt_execute($st_u);
$urlaub_genehmigt = 0;
foreach (mysqli_fetch_all(mysqli_stmt_get_result($st_u), MYSQLI_ASSOC) as $urow) {
    $d = $urow['abwesenheit_beginn'];
    while ($d <= $urow['abwesenheit_ende']) {
        if ((int)date('N', strtotime($d)) <= 5 && !isset($alle_feiertage[$d])) $urlaub_genehmigt++;
        $d = date('Y-m-d', strtotime("$d +1 day"));
    }
}

// --- ARBEITSZEITKONTO = fixer wöchentlicher Soll-Wert ---
$netto_soll_tag_sek = max(0, $brutto_soll_tag_sek - $pausen_pro_tag_sek);
$saldo_gesamt_sek   = $netto_soll_tag_sek * 5;

// --- ÜBERSTUNDEN GESAMT (alle Einträge, echte Pausen) ---
$st_ug = mysqli_prepare($link,
    "SELECT a.anwesenheits_datum,
            TIME_TO_SEC(a.stunden_differenz) as brutto_sek,
            COALESCE((SELECT SUM(TIMESTAMPDIFF(SECOND, p.start_pause, COALESCE(p.ende_pause, a.ende_arbeitszeit)))
                      FROM pausen p WHERE p.anwesenheit_id = a.anwesenheit_id), 0) as pause_sek
     FROM anwesenheitsaufzeichnungen a
     WHERE a.benutzer_id = ? AND a.stunden_differenz IS NOT NULL AND a.stunden_differenz != '00:00:00'
       AND NOT EXISTS (
           SELECT 1 FROM abwesenheiten ab
           WHERE ab.benutzer_id = a.benutzer_id AND ab.abwesenheit_typ = 'Urlaub' AND ab.status = 'Genehmigt'
             AND a.anwesenheits_datum BETWEEN ab.abwesenheit_beginn AND ab.abwesenheit_ende
       )");
mysqli_stmt_bind_param($st_ug, "i", $benutzer_id);
mysqli_stmt_execute($st_ug);
$ug_res = mysqli_stmt_get_result($st_ug);
$ueberstunden_gesamt_sek = 0;
$ug_day_netto = [];
while ($ugrow = mysqli_fetch_assoc($ug_res)) {
    $date      = $ugrow['anwesenheits_datum'];
    $netto_ist = max(0, (int)$ugrow['brutto_sek'] - (int)$ugrow['pause_sek']);
    $ug_day_netto[$date] = ($ug_day_netto[$date] ?? 0) + $netto_ist;
}
foreach ($ug_day_netto as $date => $total_netto) {
    $soll_d = netto_soll_for_date($verlauf_eintraege, $date, $netto_soll_tag_sek);
    $ueberstunden_gesamt_sek += ($total_netto - $soll_d);
}

// Genehmigte Verspätungen: Credit addieren, Mehrversp. abziehen
$st_vz = mysqli_prepare($link,
    "SELECT v.verspätungszeit, a.start_arbeitszeit
     FROM abwesenheiten v
     LEFT JOIN anwesenheitsaufzeichnungen a ON a.benutzer_id=v.benutzer_id AND a.anwesenheits_datum=v.abwesenheit_beginn
     WHERE v.benutzer_id=? AND v.abwesenheit_typ='Verspätung' AND v.status='Genehmigt' AND v.verspätungszeit IS NOT NULL
       AND v.abwesenheit_beginn <= ?");
mysqli_stmt_bind_param($st_vz, "is", $benutzer_id, $heute_datum);
mysqli_stmt_execute($st_vz);
$res_vz = mysqli_stmt_get_result($st_vz);
while ($vz = mysqli_fetch_assoc($res_vz)) {
    $vp = explode(':', $vz['verspätungszeit']);
    $approved_sek = ($vp[0]*3600)+($vp[1]*60)+($vp[2]??0);
    if (!empty($vz['start_arbeitszeit']) && $vz['start_arbeitszeit'] !== '00:00:00') {
        $sp = explode(':', $vz['start_arbeitszeit']);
        $actual_late_sek = max(0, ($sp[0]*3600)+($sp[1]*60) - 8*3600);
        // Früher → voller approved Credit; Später → approved minus Strafabzug
        $credit = $approved_sek - max(0, $actual_late_sek - $approved_sek);
        $ueberstunden_gesamt_sek += $credit;
    } else {
        // Nicht erschienen: nur genehmigte Zeit entschuldigt, Rest als Fehlzeit
        $ueberstunden_gesamt_sek -= max(0, $netto_soll_tag_sek - $approved_sek);
    }
}

// --- FEIERTAGE DER WOCHE LADEN ---
$feiertage_woche = [];
$st_fw = mysqli_prepare($link, "SELECT datum FROM feiertage WHERE datum BETWEEN ? AND ?");
mysqli_stmt_bind_param($st_fw, "ss", $monday, $sunday);
mysqli_stmt_execute($st_fw);
$res_fw = mysqli_stmt_get_result($st_fw);
while ($fw = mysqli_fetch_assoc($res_fw)) $feiertage_woche[$fw['datum']] = true;
mysqli_stmt_close($st_fw);


// --- ZEITAUSGLEICH AKTUELLE WOCHE ---
$za_woche = [];
$st_za_w = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Zeitausgleich' AND status='Genehmigt' AND abwesenheit_ende >= ? AND abwesenheit_beginn <= ?");
mysqli_stmt_bind_param($st_za_w, "iss", $benutzer_id, $monday, $sunday);
mysqli_stmt_execute($st_za_w);
$res_za_w = mysqli_stmt_get_result($st_za_w);
if ($res_za_w) while ($za = mysqli_fetch_assoc($res_za_w)) {
    $d = max($za['abwesenheit_beginn'], $monday); $e_d = min($za['abwesenheit_ende'], $sunday);
    while ($d <= $e_d) {
        if ((int)date('N', strtotime($d)) <= 5 && !isset($feiertage_woche[$d])) $za_woche[$d] = true;
        $d = date('Y-m-d', strtotime("$d +1 day"));
    }
}

// --- PERSÖNLICHER FEIERTAG AKTUELLE WOCHE ---
$pf_woche = [];
$st_pf_w = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Persönlicher Feiertag' AND status='Genehmigt' AND abwesenheit_ende >= ? AND abwesenheit_beginn <= ?");
mysqli_stmt_bind_param($st_pf_w, "iss", $benutzer_id, $monday, $sunday);
mysqli_stmt_execute($st_pf_w);
$res_pf_w = mysqli_stmt_get_result($st_pf_w);
if ($res_pf_w) while ($pf = mysqli_fetch_assoc($res_pf_w)) {
    $d = max($pf['abwesenheit_beginn'], $monday); $e_d = min($pf['abwesenheit_ende'], $sunday);
    while ($d <= $e_d) {
        if ((int)date('N', strtotime($d)) <= 5 && !isset($feiertage_woche[$d])) $pf_woche[$d] = true;
        $d = date('Y-m-d', strtotime("$d +1 day"));
    }
}
mysqli_stmt_close($st_pf_w);

// --- URLAUB AKTUELLE WOCHE ---
$urlaub_woche = [];
$st_url_w = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Urlaub' AND status='Genehmigt' AND abwesenheit_ende >= ? AND abwesenheit_beginn <= ?");
mysqli_stmt_bind_param($st_url_w, "iss", $benutzer_id, $monday, $sunday);
mysqli_stmt_execute($st_url_w);
$res_url_w = mysqli_stmt_get_result($st_url_w);
while ($ur = mysqli_fetch_assoc($res_url_w)) {
    $d = max($ur['abwesenheit_beginn'], $monday); $e_d = min($ur['abwesenheit_ende'], $sunday);
    while ($d <= $e_d) {
        if ((int)date('N', strtotime($d)) <= 5 && !isset($feiertage_woche[$d])) $urlaub_woche[$d] = true;
        $d = date('Y-m-d', strtotime("$d +1 day"));
    }
}
mysqli_stmt_close($st_url_w);

// --- VERSPÄTUNGEN DER WOCHE (tatsächliche Ankunft) ---
$verspaetung_woche = [];
$st_vw = mysqli_prepare($link,
    "SELECT v.abwesenheit_beginn, v.verspätungszeit, a.start_arbeitszeit
     FROM abwesenheiten v
     LEFT JOIN anwesenheitsaufzeichnungen a ON a.benutzer_id=v.benutzer_id AND a.anwesenheits_datum=v.abwesenheit_beginn
     WHERE v.benutzer_id=? AND v.abwesenheit_typ='Verspätung' AND v.status='Genehmigt'
       AND v.abwesenheit_beginn BETWEEN ? AND ? AND v.verspätungszeit IS NOT NULL");
mysqli_stmt_bind_param($st_vw, "iss", $benutzer_id, $monday, $sunday);
mysqli_stmt_execute($st_vw);
$res_vw = mysqli_stmt_get_result($st_vw);
while ($vw = mysqli_fetch_assoc($res_vw)) {
    $vp = explode(':', $vw['verspätungszeit']);
    $approved_sek = ($vp[0]*3600)+($vp[1]*60)+($vp[2]??0);
    if (!empty($vw['start_arbeitszeit']) && $vw['start_arbeitszeit'] !== '00:00:00') {
        $sp = explode(':', $vw['start_arbeitszeit']);
        $actual_late_sek = max(0, ($sp[0]*3600)+($sp[1]*60) - 8*3600);
        $verspaetung_woche[$vw['abwesenheit_beginn']] = min($approved_sek, $actual_late_sek);
    } else {
        $verspaetung_woche[$vw['abwesenheit_beginn']] = $approved_sek;
    }
}
mysqli_stmt_close($st_vw);

// --- ZEITAUSGLEICH GESAMTKOSTEN (Abzug von Überstunden) ---
$za_kosten_sek = 0;
$st_za_all = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Zeitausgleich' AND status='Genehmigt'");
mysqli_stmt_bind_param($st_za_all, "i", $benutzer_id);
mysqli_stmt_execute($st_za_all);
$res_za_all = mysqli_stmt_get_result($st_za_all);
if ($res_za_all) while ($za = mysqli_fetch_assoc($res_za_all)) {
    $d = $za['abwesenheit_beginn'];
    while ($d <= $za['abwesenheit_ende'] && $d <= $heute_datum) {
        if ((int)date('N', strtotime($d)) <= 5 && !isset($alle_feiertage[$d]) && $d >= $firma_start) {
            $za_kosten_sek += netto_soll_for_date($verlauf_eintraege, $d, $netto_soll_tag_sek);
        }
        $d = date('Y-m-d', strtotime("$d +1 day"));
    }
}
$ueberstunden_gesamt_sek -= $za_kosten_sek;

// Fehlende Werktage abziehen (kein Eintrag, keine genehmigte Abwesenheit)
$st_ed2 = mysqli_prepare($link, "SELECT DISTINCT anwesenheits_datum FROM anwesenheitsaufzeichnungen WHERE benutzer_id=?");
mysqli_stmt_bind_param($st_ed2, "i", $benutzer_id);
mysqli_stmt_execute($st_ed2);
$entry_days = [];
$res_ed2 = mysqli_stmt_get_result($st_ed2);
while ($ed = mysqli_fetch_assoc($res_ed2)) $entry_days[$ed['anwesenheits_datum']] = true;

$abs_days = [];
$st_abd2 = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND status='Genehmigt'");
mysqli_stmt_bind_param($st_abd2, "i", $benutzer_id);
mysqli_stmt_execute($st_abd2);
$res_abd2 = mysqli_stmt_get_result($st_abd2);
while ($ab2 = mysqli_fetch_assoc($res_abd2)) {
    $dd = $ab2['abwesenheit_beginn'];
    while ($dd <= $ab2['abwesenheit_ende']) { $abs_days[$dd] = true; $dd = date('Y-m-d', strtotime("$dd +1 day")); }
}
$dd = $firma_start;
while ($dd <= $heute_datum) {
    if ((int)date('N', strtotime($dd)) <= 5 && !isset($alle_feiertage[$dd]) && !isset($entry_days[$dd]) && !isset($abs_days[$dd])) {
        $ueberstunden_gesamt_sek -= netto_soll_for_date($verlauf_eintraege, $dd, $netto_soll_tag_sek);
    }
    $dd = date('Y-m-d', strtotime("$dd +1 day"));
}

// Werktage + Wochenziel mit datumsgenauen Soll-Stunden
$werktage_woche        = 0;
$wochen_ziel_netto_sek = 0;
for ($i = 0; $i < 5; $i++) {
    $d = date('Y-m-d', strtotime("$monday +$i days"));
    if ($d >= $firma_start && $d <= $heute_datum && !isset($feiertage_woche[$d]) && !isset($za_woche[$d]) && !isset($urlaub_woche[$d])) {
        $werktage_woche++;
        $wochen_ziel_netto_sek += netto_soll_for_date($verlauf_eintraege, $d, $netto_soll_tag_sek);
    }
}

// --- WOCHENDATEN FÜR CHART ---
$tages_daten           = [];
$brutto_gesamt_sek     = 0;
$echte_pausen_woche_sek = 0;

// Echte Pausen der Woche vorab laden (eine Abfrage)
$week_pauses = [];
$st_wp = mysqli_prepare($link,
    "SELECT DATE(a.anwesenheits_datum) as datum,
            COALESCE(SUM(TIMESTAMPDIFF(SECOND, p.start_pause, COALESCE(p.ende_pause, a.ende_arbeitszeit))), 0) as pause_sek
     FROM anwesenheitsaufzeichnungen a
     LEFT JOIN pausen p ON p.anwesenheit_id = a.anwesenheit_id
     WHERE a.benutzer_id = ? AND a.anwesenheits_datum BETWEEN ? AND ?
     GROUP BY a.anwesenheits_datum");
mysqli_stmt_bind_param($st_wp, "iss", $benutzer_id, $monday, $sunday);
mysqli_stmt_execute($st_wp);
$wp_res = mysqli_stmt_get_result($st_wp);
while ($wp_row = mysqli_fetch_assoc($wp_res)) {
    $week_pauses[$wp_row['datum']] = (int)$wp_row['pause_sek'];
}
mysqli_stmt_close($st_wp);

for ($i = 0; $i < 5; $i++) {
    $date    = date('Y-m-d', strtotime("$monday +$i days"));
    $val_str = '00:00:00';
    $st_v = mysqli_prepare($link, "SELECT SEC_TO_TIME(SUM(TIME_TO_SEC(stunden_differenz))) FROM anwesenheitsaufzeichnungen WHERE benutzer_id=? AND anwesenheits_datum=?");
    mysqli_stmt_bind_param($st_v, "is", $benutzer_id, $date);
    mysqli_stmt_execute($st_v);
    mysqli_stmt_bind_result($st_v, $res_v);
    if (mysqli_stmt_fetch($st_v) && $res_v !== null) $val_str = $res_v;
    mysqli_stmt_close($st_v);

    $ist_feiertag   = isset($feiertage_woche[$date]);
    $ist_vor_start  = ($date < $firma_start);
    $ist_pf         = isset($pf_woche[$date]) && !$ist_feiertag && !$ist_vor_start;
    $ist_urlaub     = isset($urlaub_woche[$date]) && !$ist_feiertag && !$ist_vor_start && !$ist_pf;
    $ist_za         = isset($za_woche[$date]) && !$ist_feiertag && !$ist_vor_start && !$ist_pf && !$ist_urlaub;
    $ist_sek        = toSek($val_str);
    $tag_pause_sek  = $week_pauses[$date] ?? 0;
    $ist_netto_sek  = max(0, $ist_sek - $tag_pause_sek);
    $netto_soll_d   = netto_soll_for_date($verlauf_eintraege, $date, $netto_soll_tag_sek);
    $ist_fehlend    = !$ist_feiertag && !$ist_vor_start && !$ist_za && !$ist_urlaub
                      && $ist_sek === 0
                      && $date < $heute_datum
                      && (int)date('N', strtotime($date)) <= 5
                      && !isset($abs_days[$date]);

    if (!$ist_za && !$ist_urlaub) {
        $brutto_gesamt_sek      += $ist_sek;
        $echte_pausen_woche_sek += $tag_pause_sek;
    }

    $tages_daten[] = [
        'tag_name'     => ['Mo','Di','Mi','Do','Fr','Sa','So'][(int)date('N', strtotime($date))-1],
        'datum'        => date('d.m.', strtotime($date)),
        'hoehe'        => ($ist_za || $ist_pf || $ist_urlaub || $ist_feiertag || $ist_fehlend) ? getPercent($netto_soll_d) : getPercent($ist_netto_sek),
        'ist'          => ($ist_za || $ist_pf || $ist_urlaub) ? '—' : formatH($ist_netto_sek),
        'ist_sek'      => $ist_netto_sek,
        'is_today'     => ($date == $heute_datum),
        'is_feiertag'  => $ist_feiertag,
        'is_vor_start' => $ist_vor_start,
        'is_pf'        => $ist_pf,
        'is_za'        => $ist_za,
        'is_urlaub'    => $ist_urlaub,
        'is_fehlend'   => $ist_fehlend,
        'ueber'          => ($ist_feiertag || $ist_vor_start || $ist_za || $ist_pf || $ist_urlaub) ? 0 : ($ist_netto_sek - $netto_soll_d),
        'verspaetung_sek' => $verspaetung_woche[$date] ?? 0,
    ];
}

$netto_arbeitszeit_sek = $brutto_gesamt_sek - $echte_pausen_woche_sek;
$w_ueber_sek = max(0, $netto_arbeitszeit_sek - $wochen_ziel_netto_sek);
$w_fehlt_sek = max(0, $wochen_ziel_netto_sek - $netto_arbeitszeit_sek);

// --- DETAILTABELLE: Einträge im gewählten Zeitraum ---
$st_de = mysqli_prepare($link,
    "SELECT a.anwesenheit_id, a.anwesenheits_datum, a.start_arbeitszeit, a.ende_arbeitszeit, a.stunden_differenz,
            COALESCE((SELECT SUM(TIMESTAMPDIFF(SECOND, p.start_pause, COALESCE(p.ende_pause, NOW())))
                      FROM pausen p WHERE p.anwesenheit_id = a.anwesenheit_id), 0) as pause_sek
     FROM anwesenheitsaufzeichnungen a
     WHERE a.benutzer_id = ?
       AND a.anwesenheits_datum BETWEEN ? AND ?
     ORDER BY a.anwesenheits_datum ASC, a.start_arbeitszeit ASC");
mysqli_stmt_bind_param($st_de, "iss", $benutzer_id, $detail_von, $detail_bis);
mysqli_stmt_execute($st_de);
$detail_eintraege = mysqli_fetch_all(mysqli_stmt_get_result($st_de), MYSQLI_ASSOC);
mysqli_stmt_close($st_de);

// --- DETAIL-ZUSAMMENFASSUNG ---
$detail_netto_sek  = 0;
$detail_pause_sek  = 0;
$detail_soll_sek   = 0;
$arbeitstage       = 0;

// Vorab: Tages-Netto-Summen und letzten Index pro Tag ermitteln
$day_netto = [];
$last_idx  = [];
foreach ($detail_eintraege as $idx => $e) {
    $date  = $e['anwesenheits_datum'];
    $netto = max(0, toSek($e['stunden_differenz']) - (int)$e['pause_sek']);
    $day_netto[$date] = ($day_netto[$date] ?? 0) + $netto;
    $last_idx[$date]  = $idx;
}

$seen_days   = [];
$day_running = [];
foreach ($detail_eintraege as $idx => &$e) {
    $date   = $e['anwesenheits_datum'];
    $brutto = toSek($e['stunden_differenz']);
    $pause  = (int)$e['pause_sek'];
    $netto  = max(0, $brutto - $pause);
    $soll_d = netto_soll_for_date($verlauf_eintraege, $date, $netto_soll_tag_sek);

    $detail_netto_sek += $netto;
    $detail_pause_sek += $pause;

    if (!isset($seen_days[$date])) {
        if ($day_netto[$date] > 0) {
            $detail_soll_sek += $soll_d;
            $arbeitstage++;
        }
        $seen_days[$date] = true;
        $day_running[$date] = 0;
    }

    $day_running[$date] += $netto;

    $e['netto_sek']         = $netto;
    $e['soll_sek']          = $soll_d;
    $e['running_netto_sek'] = $day_running[$date];
    // Diff nur beim letzten Eintrag des Tages: Tages-Summe vs. Soll
    $e['diff_sek'] = ($last_idx[$date] === $idx) ? ($day_netto[$date] - $soll_d) : null;
}
unset($e);

$detail_ueber_sek = max(0, $detail_netto_sek - $detail_soll_sek);
$detail_fehlt_sek = max(0, $detail_soll_sek - $detail_netto_sek);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Statistik | Zeiterfassung</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="statistik.css?v=4">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* KPI HORIZONTALBAR OBEN */
.kpi-hbar { display:flex; background:var(--card-bg); border:1px solid #333; border-radius:14px; overflow:hidden; margin-bottom:25px; }
.kpi-hbar-item { flex:1; padding:18px 20px; text-align:center; border-right:1px solid #333; }
.kpi-hbar-item:last-child { border-right:none; }
.kpi-hbar-item .kh-lbl { font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
.kpi-hbar-item .kh-val { font-size:1.6rem; font-weight:bold; font-family:'Courier New',monospace; }

/* LAYOUT: 2-Spalten auf breitem Bildschirm, gestapelt auf schmalem */
.stats-two-col { display:flex; gap:25px; align-items:flex-start; }
.stats-left  { flex:1; min-width:0; }
.stats-right { flex:1; min-width:0; }

@media(max-width:1200px){
    .stats-two-col { flex-direction:column; }
    .stats-left, .stats-right { width:100%; flex:none; }
    .kpi-hbar { flex-wrap:wrap; }
    .kpi-hbar-item { border-bottom:1px solid #333; min-width:45%; }
}
@media(max-width:600px){ .kpi-hbar-item { min-width:100%; } }

/* Fix: Bell-Container Alignment (statistik.css override korrigieren) */
.bell-container { position:relative !important; display:flex !important; align-items:center !important; justify-content:center !important; }

/* FILTER TABS */
.filter-tabs { display:flex; gap:0; margin-bottom:20px; border:1px solid #333; border-radius:10px; overflow:hidden; max-width:500px; }
.filter-tab { flex:1; padding:10px; text-align:center; color:#aaa; text-decoration:none; font-size:0.88rem; border-right:1px solid #333; background:#1a1a1a; transition:0.2s; }
.filter-tab:last-child { border-right:none; }
.filter-tab.active { background:#007bff; color:#fff; font-weight:600; }
.filter-tab:hover:not(.active) { background:#252525; color:#fff; }

/* FILTER FORM */
.detail-filter { background:#1a1a1a; border:1px solid #333; border-radius:10px; padding:14px 18px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; }
.detail-filter label { display:flex; flex-direction:column; gap:5px; font-size:0.85rem; color:#aaa; }
.detail-filter select, .detail-filter input { background:#252525; border:1px solid #444; border-radius:8px; padding:8px 12px; color:#fff; }
.detail-filter button { padding:9px 18px; background:#007bff; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600; }
.detail-filter button:hover { filter:brightness(1.1); }

/* DETAIL TABELLE */
.detail-table-card { max-width:100%; margin:0; }
.diff-plus  { color:#2ecc71; font-weight:600; }
.diff-minus { color:#ff4d4d; font-weight:600; }
.pause-col  { color:#ff8800; }
.summary-bar { display:flex; gap:16px; margin:16px 0 8px; flex-wrap:wrap; }
.sum-box { background:#1a1a1a; border:1px solid #333; border-radius:8px; padding:10px 16px; flex:1; min-width:120px; text-align:center; }
.sum-box .sval { font-size:1.2rem; font-weight:bold; font-family:'Courier New',monospace; }
.sum-box .slbl { font-size:0.75rem; color:#888; text-transform:uppercase; }
.bar-over      { background:linear-gradient(to top,#2ecc71,#00ff88) !important; }
.bar-under     { background:linear-gradient(to top,#ff4d4d,#ff8080) !important; }
.bar-feiertag  { background:repeating-linear-gradient(45deg,#2a2a2a,#2a2a2a 4px,#333 4px,#333 8px) !important; border:1px dashed #ffcc00 !important; min-height:60px; }
.bar-vor-start { background:repeating-linear-gradient(45deg,#1a1a1a,#1a1a1a 4px,#222 4px,#222 8px) !important; border:1px dashed #555 !important; min-height:8px; opacity:0.5; }
.bar-za        { background:linear-gradient(to top,#0055cc,#6bc5f8) !important; opacity:0.75; min-height:60px; }
.bar-pf        { background:linear-gradient(to top,#a07800,#ffcc00) !important; opacity:0.85; min-height:60px; }
.bar-fehlend      { background:repeating-linear-gradient(45deg,#4a0000,#4a0000 4px,#660000 4px,#660000 8px) !important; border:1px dashed #ff4d4d !important; min-height:60px; }
.bar-urlaub       { background:linear-gradient(to top,#007a5a,#00c994) !important; opacity:0.85; min-height:60px; }
.bar-verspaetung  { background:linear-gradient(to top,#cc4400,#ff7700) !important; border-radius:0 0 6px 6px !important; min-height:2px; opacity:0.85; }
.bar-inner-label { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:0.68rem; text-align:center; white-space:nowrap; line-height:1.5; pointer-events:none; z-index:1; font-weight:600; }
/* Soll-Linie: Tooltip nur bei Hover */
.soll-tooltip  { display:none; }
.soll-line:hover .soll-tooltip { display:inline-block; }

@media(max-width:768px){ .detail-table-card table { font-size:0.8rem; } }
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
        <div class="bell-container">
            <i class="fas fa-bell"></i>
            <div class="bell-dropdown">
                <?php if (!empty($wartungen)): ?>
                    <?php foreach ($wartungen as $w): ?>
                        <div class="bell-item">
                            <div><?php echo htmlspecialchars($w['beschreibung']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?><div class="bell-item">Keine geplanten Wartungen</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="bell-container msg-container" style="color:#6bc5f8;" onmouseenter="this.querySelector('.bell-badge')?.remove();fetch('mark_nachrichten.php')">
            <i class="fas fa-envelope"></i>
            <?php if ($nachrichten_count > 0): ?>
                <span class="bell-badge"><?php echo $nachrichten_count; ?></span>
            <?php endif; ?>
            <div class="bell-dropdown" style="width:320px;">
                <div style="padding:10px 14px;font-weight:600;border-bottom:1px solid #333;color:#fff;font-size:0.9rem;">Meine Nachrichten</div>
                <?php if (empty($nachrichten_user)): ?>
                    <div class="bell-item" style="color:#888;">Keine Nachrichten</div>
                <?php else: ?>
                    <?php foreach ($nachrichten_user as $n): ?>
                        <div class="bell-item" data-id="<?php echo (int)$n['benachrichtigung_id']; ?>">
                            <small><?php echo date('d.m.Y H:i', strtotime($n['zeitstempel'])); ?><?php if (!empty($n['von_name'])): ?> · Von: <?php echo htmlspecialchars($n['von_name']); ?><?php endif; ?></small>
                            <div><?php echo htmlspecialchars($n['nachricht']); ?></div>
                            <button class="msg-del-btn" title="Löschen"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php include '_navbar_profile.php'; ?>
    </div>
</nav>

<div class="container" style="display:block;padding-top:40px;">

    <!-- KPI HORIZONTAL BAR -->
    <div class="kpi-hbar">
        <div class="kpi-hbar-item">
            <div class="kh-lbl"><i class="fas fa-umbrella-beach"></i> Urlaubstage</div>
            <div class="kh-val"><?php echo max(0, $urlaubstage_gesamt - $urlaub_genehmigt); ?> / <?php echo $urlaubstage_gesamt; ?></div>
        </div>
        <div class="kpi-hbar-item">
            <div class="kh-lbl"><i class="fas fa-history"></i> Arbeitszeitkonto</div>
            <div class="kh-val"><?php echo formatH($saldo_gesamt_sek); ?>h</div>
        </div>
        <div class="kpi-hbar-item">
            <div class="kh-lbl"><i class="fas fa-arrow-up"></i> Überstunden Woche</div>
            <div class="kh-val" style="color:<?php echo $w_ueber_sek>0?'#2ecc71':'#ff4d4d'; ?>;">
                <?php echo $w_ueber_sek > 0 ? '+'.formatH($w_ueber_sek) : '-'.formatH($w_fehlt_sek); ?>h
            </div>
        </div>
        <div class="kpi-hbar-item">
            <div class="kh-lbl"><i class="fas fa-chart-line"></i> Überstunden Gesamt</div>
            <div class="kh-val" style="color:<?php echo $ueberstunden_gesamt_sek >= 0 ? '#2ecc71' : '#ff4d4d'; ?>;">
                <?php echo ($ueberstunden_gesamt_sek >= 0 ? '+' : '-') . formatH(abs($ueberstunden_gesamt_sek)); ?>h
            </div>
        </div>
    </div>

    <!-- HAUPTINHALT: 2 SPALTEN -->
    <div class="stats-two-col">

    <!-- LINKE SPALTE: Wochenchart + neuer KPI Container -->
    <div class="stats-left">
        <div class="dashboard-card stats-card-wide" style="max-width:100%!important;padding:30px!important;">
            <div class="header-flex-nav">
                <?php
                $prev_mon = date('Y-m-d', strtotime("monday this week " . ($week_offset-1) . " weeks"));
                $next_mon = date('Y-m-d', strtotime("monday this week " . ($week_offset+1) . " weeks"));
                $prev_fm  = date('n', strtotime($prev_mon)); $prev_fj = date('Y', strtotime($prev_mon));
                $next_fm  = date('n', strtotime($next_mon)); $next_fj = date('Y', strtotime($next_mon));
                ?>
                <a href="?week=<?php echo $week_offset-1; ?>&mode=<?php echo $mode; ?>&f_monat=<?php echo $prev_fm; ?>&f_jahr=<?php echo $prev_fj; ?>" class="nav-arrow"><i class="fas fa-chevron-left"></i></a>
                <h3 style="margin:0;">Woche: <?php echo date('d.m.', strtotime($monday)); ?> – <?php echo date('d.m.', strtotime($sunday)); ?></h3>
                <a href="?week=<?php echo $week_offset+1; ?>&mode=<?php echo $mode; ?>&f_monat=<?php echo $next_fm; ?>&f_jahr=<?php echo $next_fj; ?>" class="nav-arrow"><i class="fas fa-chevron-right"></i></a>
            </div>
            <p style="text-align:center;color:#888;margin-top:-5px;margin-bottom:20px;">
                Wochenziel (Netto): <?php echo formatH($wochen_ziel_netto_sek); ?>h
            </p>
            <div class="stats-main-wrapper" style="margin-top:20px;">
                <div class="chart-container-css">
                    <div class="y-axis"></div>
                    <div class="bars-area">
                        <span class="y-label" style="bottom:calc(30px + 100% * 0.9)">12h</span>
                        <span class="y-label" style="bottom:calc(30px + 66.667% * 0.9)">8h</span>
                        <span class="y-label" style="bottom:calc(30px + 33.333% * 0.9)">4h</span>
                        <?php $soll_linie_sek = netto_soll_for_date($verlauf_eintraege, $monday, $netto_soll_tag_sek); ?>
                        <div class="soll-line" style="bottom:calc(30px + <?php echo getPercent($soll_linie_sek); ?>% * 0.9);">
                            <span class="soll-tooltip">Soll: <?php echo formatH($soll_linie_sek); ?>h</span>
                        </div>
                        <?php foreach ($tages_daten as $tag): ?>
                            <?php
                                if ($tag['is_feiertag']) {
                                    $bar_class = 'bar bar-feiertag';
                                } elseif ($tag['is_vor_start']) {
                                    $bar_class = 'bar bar-vor-start';
                                } elseif ($tag['is_pf']) {
                                    $bar_class = 'bar bar-pf';
                                } elseif ($tag['is_za']) {
                                    $bar_class = 'bar bar-za';
                                } elseif ($tag['is_urlaub']) {
                                    $bar_class = 'bar bar-urlaub';
                                } elseif ($tag['is_fehlend']) {
                                    $bar_class = 'bar bar-fehlend';
                                } else {
                                    $bar_class = 'bar';
                                    if ($tag['ist_sek'] > 0) {
                                        $bar_class .= ' bar-over';
                                    }
                                }
                                if ($tag['is_today']) $bar_class .= ' today-bar';
                            ?>
                            <div class="bar-wrapper">
                                <div class="<?php echo $bar_class; ?>" style="height:<?php echo $tag['hoehe']; ?>%;">
                                    <div class="bar-tooltip-box">
                                        <?php if ($tag['is_feiertag']): ?>
                                            🎉 Feiertag
                                        <?php elseif ($tag['is_vor_start']): ?>
                                            Vor Firmengründung
                                        <?php elseif ($tag['is_pf']): ?>
                                            🎉 Persönlicher Feiertag
                                        <?php elseif ($tag['is_za']): ?>
                                            ⏱️ Zeitausgleich
                                        <?php elseif ($tag['is_urlaub']): ?>
                                            🏖️ Urlaub
                                        <?php elseif ($tag['is_fehlend']): ?>
                                            ❗ Kein Eintrag<br>
                                            <span style="color:#ff8080;">−<?php echo formatH($netto_soll_tag_sek); ?>h</span>
                                        <?php else: ?>
                                            Ist: <?php echo $tag['ist']; ?>h<br>
                                            <?php if ($tag['ist_sek'] > 0): ?>
                                                Diff: <?php echo ($tag['ueber']>=0?'+':'').formatH(abs($tag['ueber'])); ?>h
                                            <?php endif; ?>
                                            <?php if ($tag['verspaetung_sek'] > 0): ?>
                                                <br>⏰ Verspätung: −<?php echo formatH($tag['verspaetung_sek']); ?>h
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($tag['is_feiertag']): ?>
                                        <span class="bar-inner-label" style="color:#ffcc00;">🎉<br>Feiertag</span>
                                    <?php elseif ($tag['is_pf']): ?>
                                        <span class="bar-inner-label" style="color:#ffcc00;">🎉<br>Pers. FT</span>
                                    <?php elseif ($tag['is_za']): ?>
                                        <span class="bar-inner-label" style="color:#e0f4ff;">⏱️<br>ZA</span>
                                    <?php elseif ($tag['is_urlaub']): ?>
                                        <span class="bar-inner-label" style="color:#e0fff5;">🏖️<br>Urlaub</span>
                                    <?php elseif ($tag['is_fehlend']): ?>
                                        <span class="bar-inner-label" style="color:#ff8080;">❗<br>Fehlt</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($tag['verspaetung_sek'] > 0 && !$tag['is_feiertag'] && !$tag['is_za'] && !$tag['is_pf'] && !$tag['is_fehlend']): ?>
                                <div class="bar bar-verspaetung" style="height:<?php echo getPercent($tag['verspaetung_sek']); ?>%;"></div>
                                <?php endif; ?>
                                <span class="bar-label <?php echo $tag['is_today']?'active-label':''; ?>"><?php echo $tag['tag_name']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="info-sidebar">
                    <div class="info-item"><span>Arbeitszeit:</span><span><?php echo formatH($netto_arbeitszeit_sek); ?>h</span></div>
                    <?php $week_diff_sek = $w_ueber_sek - $w_fehlt_sek; ?>
                    <div class="info-item"><span>Überstunden:</span><span style="color:<?php echo $week_diff_sek >= 0 ? '#2ecc71' : '#ff4d4d'; ?>;"><?php echo ($week_diff_sek >= 0 ? '+' : '-') . formatH(abs($week_diff_sek)); ?>h</span></div>
                </div>
            </div>
        </div>

    </div><!-- /stats-left -->

    <!-- RECHTE SPALTE: Detailtabelle -->
    <div class="stats-right">
    <div class="detail-table-card" style="margin:0;">
        <div class="dashboard-card" id="detail-section">
            <h3 class="section-title" style="margin-bottom:18px;">
                <i class="fas fa-table" style="color:var(--primary-color);"></i>
                Detaillierte Übersicht
            </h3>

            <!-- FILTER TABS -->
            <div class="filter-tabs">
                <a href="?week=<?php echo $week_offset; ?>&mode=woche" class="filter-tab <?php echo $mode=='woche'?'active':''; ?>">
                    <i class="fas fa-calendar-week"></i> Woche
                </a>
                <a href="?week=<?php echo $week_offset; ?>&mode=monat&f_monat=<?php echo $f_monat; ?>&f_jahr=<?php echo $f_jahr; ?>" class="filter-tab <?php echo $mode=='monat'?'active':''; ?>">
                    <i class="fas fa-calendar-alt"></i> Monat
                </a>
                <a href="?week=<?php echo $week_offset; ?>&mode=custom&f_von=<?php echo $f_von; ?>&f_bis=<?php echo $f_bis; ?>" class="filter-tab <?php echo $mode=='custom'?'active':''; ?>">
                    <i class="fas fa-sliders-h"></i> Zeitraum
                </a>
            </div>

            <!-- FILTER FORM -->
            <?php if ($mode === 'monat'): ?>
            <form method="GET" class="detail-filter">
                <input type="hidden" name="mode" value="monat">
                <label>Monat
                    <select name="f_monat">
                        <?php for ($m=1;$m<=12;$m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $f_monat===$m?'selected':''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label>Jahr
                    <select name="f_jahr">
                        <?php for ($y=2024;$y<=2026;$y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $f_jahr===$y?'selected':''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <button type="submit"><i class="fas fa-search"></i> Anzeigen</button>
            </form>

            <?php elseif ($mode === 'custom'): ?>
            <form method="GET" class="detail-filter">
                <input type="hidden" name="mode" value="custom">
                <label>Von<input type="date" name="f_von" value="<?php echo htmlspecialchars($f_von); ?>"></label>
                <label>Bis<input type="date" name="f_bis" value="<?php echo htmlspecialchars($f_bis); ?>"></label>
                <button type="submit"><i class="fas fa-search"></i> Anzeigen</button>
            </form>

            <?php else: ?>
            <p style="color:#666;font-size:0.85rem;margin-bottom:16px;">
                Zeige Woche <?php echo date('d.m.', strtotime($monday)); ?> – <?php echo date('d.m.Y', strtotime($sunday)); ?>
            </p>
            <?php endif; ?>

            <!-- ZUSAMMENFASSUNG -->
            <?php if (!empty($detail_eintraege)): ?>
            <div class="summary-bar">
                <div class="sum-box">
                    <div class="sval"><?php echo formatH($detail_netto_sek); ?>h</div>
                    <div class="slbl">Netto gesamt</div>
                </div>
                <div class="sum-box">
                    <div class="sval" style="color:#ff8800;"><?php echo formatH($detail_pause_sek); ?>h</div>
                    <div class="slbl">Pausen gesamt</div>
                </div>
                <div class="sum-box">
                    <div class="sval" style="color:#888;"><?php echo formatH($detail_soll_sek); ?>h</div>
                    <div class="slbl">Soll gesamt</div>
                </div>
                <div class="sum-box">
                    <div class="sval <?php echo $detail_ueber_sek>0?'diff-plus':'diff-minus'; ?>">
                        <?php echo $detail_ueber_sek>0 ? '+'.formatH($detail_ueber_sek) : '-'.formatH($detail_fehlt_sek); ?>h
                    </div>
                    <div class="slbl"><?php echo $detail_ueber_sek>0?'Überstunden':'Fehlstunden'; ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- TABELLE -->
            <?php
            // Verspätungs-Tage im Detailzeitraum laden (für ⏰ Anzeige)
            $verspaetung_detail = [];
            $st_vdet = mysqli_prepare($link,
                "SELECT v.abwesenheit_beginn, v.verspätungszeit, a.start_arbeitszeit
                 FROM abwesenheiten v
                 LEFT JOIN anwesenheitsaufzeichnungen a ON a.benutzer_id=v.benutzer_id AND a.anwesenheits_datum=v.abwesenheit_beginn
                 WHERE v.benutzer_id=? AND v.abwesenheit_typ='Verspätung' AND v.status='Genehmigt'
                   AND v.abwesenheit_beginn BETWEEN ? AND ? AND v.verspätungszeit IS NOT NULL");
            mysqli_stmt_bind_param($st_vdet, "iss", $benutzer_id, $detail_von, $detail_bis);
            mysqli_stmt_execute($st_vdet);
            $res_vdet = mysqli_stmt_get_result($st_vdet);
            while ($vd = mysqli_fetch_assoc($res_vdet)) {
                $vp = explode(':', $vd['verspätungszeit']);
                $approved_sek = ($vp[0]*3600)+($vp[1]*60)+($vp[2]??0);
                $actual_late_sek = $approved_sek;
                $ankunft_str = null;
                if (!empty($vd['start_arbeitszeit']) && $vd['start_arbeitszeit'] !== '00:00:00') {
                    $sp = explode(':', $vd['start_arbeitszeit']);
                    $actual_late_sek = max(0, ($sp[0]*3600)+($sp[1]*60) - 8*3600);
                    $ankunft_str = substr($vd['start_arbeitszeit'], 0, 5);
                }
                $verspaetung_detail[$vd['abwesenheit_beginn']] = [
                    'approved_sek'  => $approved_sek,
                    'actual_sek'    => $actual_late_sek,
                    'ankunft'       => $ankunft_str,
                    'fruehzeitig'   => ($actual_late_sek < $approved_sek),
                    'ersparnis_sek' => max(0, $approved_sek - $actual_late_sek),
                ];
            }
            mysqli_stmt_close($st_vdet);

            // ZA-Tage im Detailzeitraum ermitteln
            $za_detail_tage = [];
            $st_za_d = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Zeitausgleich' AND status='Genehmigt' AND abwesenheit_ende >= ? AND abwesenheit_beginn <= ?");
            mysqli_stmt_bind_param($st_za_d, "iss", $benutzer_id, $detail_von, $detail_bis);
            mysqli_stmt_execute($st_za_d);
            $res_za_d = mysqli_stmt_get_result($st_za_d);
            if ($res_za_d) while ($za = mysqli_fetch_assoc($res_za_d)) {
                $d = max($za['abwesenheit_beginn'], $detail_von); $e_d = min($za['abwesenheit_ende'], $detail_bis);
                while ($d <= $e_d) {
                    if ((int)date('N', strtotime($d)) <= 5 && !isset($alle_feiertage[$d])) $za_detail_tage[$d] = true;
                    $d = date('Y-m-d', strtotime("$d +1 day"));
                }
            }

            // Urlaub-Tage im Detailzeitraum ermitteln
            $urlaub_detail_tage = [];
            $st_url_d = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Urlaub' AND status='Genehmigt' AND abwesenheit_ende >= ? AND abwesenheit_beginn <= ?");
            mysqli_stmt_bind_param($st_url_d, "iss", $benutzer_id, $detail_von, $detail_bis);
            mysqli_stmt_execute($st_url_d);
            $res_url_d = mysqli_stmt_get_result($st_url_d);
            while ($ur = mysqli_fetch_assoc($res_url_d)) {
                $d = max($ur['abwesenheit_beginn'], $detail_von); $e_d = min($ur['abwesenheit_ende'], $detail_bis);
                while ($d <= $e_d) {
                    if ((int)date('N', strtotime($d)) <= 5 && !isset($alle_feiertage[$d])) $urlaub_detail_tage[$d] = true;
                    $d = date('Y-m-d', strtotime("$d +1 day"));
                }
            }
            mysqli_stmt_close($st_url_d);

            // Merged: ZA/Urlaub-Tage als eigene Zeilen, keine Arbeitszeilen für diese Tage
            $merged_items = [];
            foreach ($detail_eintraege as $e) {
                if (!isset($za_detail_tage[$e['anwesenheits_datum']]) && !isset($urlaub_detail_tage[$e['anwesenheits_datum']])) {
                    $merged_items[] = ['type'=>'work','datum'=>$e['anwesenheits_datum'],'data'=>$e];
                }
            }
            foreach (array_keys($za_detail_tage) as $zd) {
                $merged_items[] = ['type'=>'za','datum'=>$zd];
            }
            foreach (array_keys($urlaub_detail_tage) as $ud) {
                $merged_items[] = ['type'=>'urlaub','datum'=>$ud];
            }
            usort($merged_items, fn($a,$b) => strcmp($a['datum'],$b['datum']));

            // Kumulierte Überstunden VOR dem Detailzeitraum berechnen (pro Tag gruppiert)
            $st_base_e = mysqli_prepare($link,
                "SELECT a.anwesenheits_datum,
                        TIME_TO_SEC(a.stunden_differenz) as brutto_sek,
                        COALESCE((SELECT SUM(TIMESTAMPDIFF(SECOND,p.start_pause,COALESCE(p.ende_pause,a.ende_arbeitszeit)))
                                  FROM pausen p WHERE p.anwesenheit_id=a.anwesenheit_id),0) as pause_sek
                 FROM anwesenheitsaufzeichnungen a
                 WHERE a.benutzer_id=? AND a.anwesenheits_datum < ?
                   AND a.stunden_differenz IS NOT NULL AND a.stunden_differenz != '00:00:00'
                   AND NOT EXISTS (
                       SELECT 1 FROM abwesenheiten ab
                       WHERE ab.benutzer_id=a.benutzer_id AND ab.abwesenheit_typ='Urlaub' AND ab.status='Genehmigt'
                         AND a.anwesenheits_datum BETWEEN ab.abwesenheit_beginn AND ab.abwesenheit_ende
                   )");
            mysqli_stmt_bind_param($st_base_e, "is", $benutzer_id, $detail_von);
            mysqli_stmt_execute($st_base_e);
            $base_day_netto  = [];
            $base_entry_days = [];
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($st_base_e), MYSQLI_ASSOC) as $br) {
                $bd = $br['anwesenheits_datum'];
                $base_day_netto[$bd]  = ($base_day_netto[$bd] ?? 0) + max(0, (int)$br['brutto_sek'] - (int)$br['pause_sek']);
                $base_entry_days[$bd] = true;
            }
            mysqli_stmt_close($st_base_e);

            $kumulativ_sek = 0;
            foreach ($base_day_netto as $bd => $dn) {
                $kumulativ_sek += $dn - netto_soll_for_date($verlauf_eintraege, $bd, $netto_soll_tag_sek);
            }

            // Fehlende Werktage vor detail_von abziehen (kein Eintrag, keine Abwesenheit)
            $base_abs_days = [];
            $st_base_abs = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND status='Genehmigt' AND abwesenheit_beginn < ?");
            mysqli_stmt_bind_param($st_base_abs, "is", $benutzer_id, $detail_von);
            mysqli_stmt_execute($st_base_abs);
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($st_base_abs), MYSQLI_ASSOC) as $ab) {
                $bd2 = $ab['abwesenheit_beginn'];
                while ($bd2 < $detail_von && $bd2 <= $ab['abwesenheit_ende']) {
                    $base_abs_days[$bd2] = true;
                    $bd2 = date('Y-m-d', strtotime("$bd2 +1 day"));
                }
            }
            mysqli_stmt_close($st_base_abs);

            $bd = $firma_start;
            while ($bd < $detail_von) {
                if ((int)date('N', strtotime($bd)) <= 5 && !isset($alle_feiertage[$bd]) && !isset($base_entry_days[$bd]) && !isset($base_abs_days[$bd])) {
                    $kumulativ_sek -= netto_soll_for_date($verlauf_eintraege, $bd, $netto_soll_tag_sek);
                }
                $bd = date('Y-m-d', strtotime("$bd +1 day"));
            }

            // ZA-Kosten VOR Detailzeitraum abziehen
            $st_za_vor = mysqli_prepare($link, "SELECT abwesenheit_beginn, abwesenheit_ende FROM abwesenheiten WHERE benutzer_id=? AND abwesenheit_typ='Zeitausgleich' AND status='Genehmigt' AND abwesenheit_beginn < ?");
            mysqli_stmt_bind_param($st_za_vor, "is", $benutzer_id, $detail_von);
            mysqli_stmt_execute($st_za_vor);
            $res_za_vor = mysqli_stmt_get_result($st_za_vor);
            if ($res_za_vor) while ($za = mysqli_fetch_assoc($res_za_vor)) {
                $d = $za['abwesenheit_beginn']; $e_d = min($za['abwesenheit_ende'], date('Y-m-d', strtotime("$detail_von -1 day")));
                while ($d <= $e_d) {
                    if ((int)date('N', strtotime($d)) <= 5 && !isset($alle_feiertage[$d]) && $d >= $firma_start)
                        $kumulativ_sek -= netto_soll_for_date($verlauf_eintraege, $d, $netto_soll_tag_sek);
                    $d = date('Y-m-d', strtotime("$d +1 day"));
                }
            }
            ?>
            <div style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Start</th>
                            <th>Ende</th>
                            <th>Pause</th>
                            <th>Netto</th>
                            <th>Differenz</th>
                            <th>Ges. Überstunden</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($merged_items)): ?>
                        <tr><td colspan="7" class="muted-text" style="text-align:center;padding:30px;">Keine Einträge im gewählten Zeitraum.</td></tr>
                    <?php endif; ?>
                    <?php $day_base_kumulativ = [];
                    foreach ($merged_items as $item):
                        if ($item['type'] === 'za'):
                            $kumulativ_sek -= $netto_soll_tag_sek;
                    ?>
                        <tr style="background:rgba(0,123,255,0.06);">
                            <td><?php echo (['Mo','Di','Mi','Do','Fr','Sa','So'][(int)date('N',strtotime($item['datum']))-1]).' '.date('d.m.Y',strtotime($item['datum'])); ?></td>
                            <td colspan="3" style="color:#6bc5f8;font-style:italic;">⏱️ Zeitausgleich</td>
                            <td><span style="color:#6bc5f8;">—</span></td>
                            <td><span style="color:#6bc5f8;">0:00h</span></td>
                            <td><span class="<?php echo $kumulativ_sek >= 0 ? 'diff-plus' : 'diff-minus'; ?>">
                                <?php echo ($kumulativ_sek >= 0 ? '+' : '-') . formatH(abs($kumulativ_sek)); ?>h
                            </span></td>
                        </tr>
                    <?php elseif ($item['type'] === 'urlaub'): ?>
                        <tr style="background:rgba(0,180,130,0.06);">
                            <td><?php echo (['Mo','Di','Mi','Do','Fr','Sa','So'][(int)date('N',strtotime($item['datum']))-1]).' '.date('d.m.Y',strtotime($item['datum'])); ?></td>
                            <td colspan="3" style="color:#00c994;font-style:italic;">🏖️ Urlaub</td>
                            <td><span style="color:#00c994;">—</span></td>
                            <td><span style="color:#00c994;">0:00h</span></td>
                            <td><span class="<?php echo $kumulativ_sek >= 0 ? 'diff-plus' : 'diff-minus'; ?>">
                                <?php echo ($kumulativ_sek >= 0 ? '+' : '-') . formatH(abs($kumulativ_sek)); ?>h
                            </span></td>
                        </tr>
                    <?php else:
                        $e        = $item['data'];
                        $diff_sek = $e['diff_sek'];
                        $offen    = ($e['ende_arbeitszeit'] === '00:00:00' || !$e['ende_arbeitszeit']);
                        $date_e   = $e['anwesenheits_datum'];
                        if (!isset($day_base_kumulativ[$date_e])) $day_base_kumulativ[$date_e] = $kumulativ_sek;
                        if (!$offen && $diff_sek !== null) $kumulativ_sek += $diff_sek;
                        $display_kumulativ = $day_base_kumulativ[$date_e] - $e['soll_sek'] + $e['running_netto_sek'];
                    ?>
                        <tr>
                            <td>
                            <?php
                            echo (['Mo','Di','Mi','Do','Fr','Sa','So'][(int)date('N',strtotime($e['anwesenheits_datum']))-1]).' '.date('d.m.Y',strtotime($e['anwesenheits_datum']));
                            if (isset($verspaetung_detail[$e['anwesenheits_datum']])):
                                $vdi = $verspaetung_detail[$e['anwesenheits_datum']];
                            ?>
                            <br><span style="font-size:0.75rem;color:#ff8800;">
                                ⏰ Verspätung −<?php echo formatH($vdi['actual_sek']); ?>h
                                <?php if ($vdi['fruehzeitig'] && $vdi['ersparnis_sek'] > 0): ?>
                                    <span style="color:#2ecc71;">(+<?php echo formatH($vdi['ersparnis_sek']); ?>h früher)</span>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                        </td>
                            <td><?php echo substr($e['start_arbeitszeit'],0,5); ?></td>
                            <td>
                                <?php if ($offen): ?>
                                    <span class="warning-badge">offen</span>
                                <?php else: ?>
                                    <?php echo substr($e['ende_arbeitszeit'],0,5); ?>
                                <?php endif; ?>
                            </td>
                            <td class="pause-col">
                                <?php echo (int)$e['pause_sek'] > 0 ? formatH((int)$e['pause_sek']).'h' : '—'; ?>
                            </td>
                            <td><strong><?php echo formatH($e['netto_sek']); ?>h</strong></td>
                            <td>
                                <?php if ($diff_sek === null || $e['netto_sek'] === 0): ?>
                                    <span style="color:#555;">—</span>
                                <?php elseif ($diff_sek >= 0): ?>
                                    <span class="diff-plus">+<?php echo formatH($diff_sek); ?>h</span>
                                <?php else: ?>
                                    <span class="diff-minus">-<?php echo formatH(abs($diff_sek)); ?>h</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($offen): ?>
                                    <span style="color:#555;">—</span>
                                <?php else: ?>
                                    <span class="<?php echo $display_kumulativ >= 0 ? 'diff-plus' : 'diff-minus'; ?>">
                                        <?php echo ($display_kumulativ >= 0 ? '+' : '-') . formatH(abs($display_kumulativ)); ?>h
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
    </div><!-- /detail-table-card -->
    </div><!-- /stats-right -->

    </div><!-- /stats-two-col -->

</div>
</body>
</html>
