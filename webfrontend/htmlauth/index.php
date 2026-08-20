<?php
/**
 * BYD Autos - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Der Datenabruf laeuft im Dienst
 * (bin/byd.py), der Miniserver spricht mit webfrontend/html/index.php.
 * Ein Plugin, das den Abruf hier erledigt, ist falsch gebaut - auch wenn es
 * funktioniert.
 *
 * Praefix 'by_' an JEDER Variablen des Hauptteils, nicht nur an den Funktionen.
 * Grund, am Geraet gemessen: loxberry_system.php bindet im Variablenraum des
 * Aufrufers ein und setzt dort 31 Namen, darunter die kurzen $p, $cfg,
 * $format und $message. Ein Plugin, das seine Pfade in $p haelt, verliert sie
 * beim Einbinden - und die naechste Zeile sucht ab dem Wurzelverzeichnis. Am
 * Geraet endete das mit HTTP 500 und leerem Rumpf, waehrend die Pruefkette in
 * allen Reitern gruen war.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Bibliothek einbinden. Sie liegt unter webfrontend/html/, weil der
 * Miniserver-Endpunkt sie ebenfalls braucht - installiert unter
 * .../html/plugins/<ordner>/, im entpackten Archiv unter ../html/. Eine feste
 * Zahl von ".." trifft nur einen der beiden Faelle. */
$by_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/by_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/by_lib.php',
    dirname(__DIR__) . '/html/by_lib.php',
) as $by_kandidat) {
    if (is_file($by_kandidat)) {
        require_once $by_kandidat;
        $by_gefunden = true;
        break;
    }
}
if (!$by_gefunden) {
    echo '<p><b>Fehler:</b> by_lib.php wurde nicht gefunden. Bitte das Plugin neu '
       . 'installieren.</p>';
    exit;
}
require_once __DIR__ . '/by_test.php';

/* Der Pfad wird VOR dem Einbinden gerettet und danach neu geholt - dann traegt
 * es auch, wenn eine kuenftige Bibliotheksfassung etwas anderes ueberschreibt.
 * Jede Kerndatei einzeln pruefen; der Kern ist optional, solange alle Aufrufe
 * hinter class_exists('LBWeb') stehen. */
$by_p = by_paths();
$by_home = (string) $by_p['home'];
if ($by_home !== '' && is_file($by_home . '/libs/phplib/loxberry_system.php')) {
    require_once $by_home . '/libs/phplib/loxberry_system.php';
    if (is_file($by_home . '/libs/phplib/loxberry_web.php')) {
        require_once $by_home . '/libs/phplib/loxberry_web.php';
    }
    $by_p = by_paths();
}

/**
 * Die Reiter - EINE Quelle fuer Positivliste, Leiste und Bereiche.
 *
 * Die Liste steht hier als Literal und die Leiste weiter unten
 * ausgeschrieben. Beides mit Absicht: hausstandard_pruefen.py sucht die Reiter
 * woertlich und kennt genau zwei Schreibweisen der Positivliste. Eine in einer
 * Schleife erzeugte Leiste findet es nicht und setzt die Spalte auf einen
 * Strich - was wie "trifft nicht zu" aussieht und "blind" heisst.
 *
 * Ausschreiben allein genuegt aber nicht. Dass die drei Stellen dieselben
 * Namen fuehren, misst der Reiter Test nach (by_reiter_lesen): fehlt ein Name
 * in der Liste, ist der Reiter sichtbar und anklickbar - und die Seite springt
 * nach jedem Absenden zurueck auf Einstellungen.
 */
function by_reiter_liste()
{
    return array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-ladungen', 'tab-test',
                 'tab-log');
}

$by_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'],
        by_reiter_liste(), true)) {
    $by_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form'])
    && in_array('tab-' . (string) $_GET['form'], by_reiter_liste(), true)) {
    $by_tab = 'tab-' . (string) $_GET['form'];
}

$by_meldungen = array();   // Erfolgsmeldungen
$by_fehler = array();      // Beanstandungen - gesammelt, nicht ueberschrieben
$by_ausgabe = '';
$by_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ================= Ein zentraler Schutz vor jedem Handler =================
 *
 * Der angemeldete Bereich ist durch die Anmeldung des LoxBerry geschuetzt -
 * gegen eine fremde Seite schuetzt das nicht: der Browser schickt die
 * hinterlegten Zugangsdaten bei einer Anfrage von aussen mit. Ein
 * untergeschobenes Formular koennte "Neues Token erzeugen" ausloesen; danach
 * beantwortet der Endpunkt jeden virtuellen Ausgang mit 403 - und ein
 * virtueller Ausgang wertet die Antwort nicht aus, der Ausfall bliebe still.
 *
 * EINE Pruefung, bevor irgendein Handler laeuft: einen Handler kann man
 * vergessen. Faellt sie durch, wird der POST zurueckgenommen UND gemeldet -
 * ein Formular, das wortlos nichts tut, schickt den Anwender auf die Suche
 * nach einem Fehler, den es nicht gibt.
 */
if ($by_post) {
    if (!by_formtoken_pruefen()) {
        $by_fehler[] = by_t('ALLG.FEHLER_FORMTOKEN');
        $by_post = false;
    }
}

/* Jedes Formular fuehrt ein verstecktes formular=<name>, und jeder Zweig fasst
 * nur seine eigenen Schluessel an. Fehlt die Angabe oder ist sie unbekannt,
 * wird ABGEWIESEN statt geraten: ein falsch geratenes Formular loescht genau
 * das, was der Benutzer behalten wollte. In einem Schwesterplugin trugen zwei
 * Formulare denselben Knopfnamen und liefen in denselben Zweig - ein Druck auf
 * Speichern im kleinen Reiter loeschte alle Messquellen, und die Seite meldete
 * "Die Einstellungen wurden gespeichert". */
$by_formular = ($by_post && isset($_POST['formular'])) ? (string) $_POST['formular'] : '';
$by_formulare = array('einstellungen', 'mqtt', 'dienst', 'token', 'log', 'test',
                      'vorlage', 'selbsttest');
if ($by_post && !in_array($by_formular, $by_formulare, true)) {
    $by_fehler[] = by_t('ALLG.FEHLER_FORMULAR');
    $by_post = false;
}

/* ---------------- Vorlage herunterladen ----------------
 * Eigenes Formular, damit der Download nicht am Speichern haengt.
 *
 * Die drei Aktionsformulare (Vorlage, Token, Protokoll) fuehren ZUSAETZLICH zu
 * "formular" den in dieser Reihe ueblichen Marker - download, token_neu,
 * log_leeren. Zwei Gruende, und keiner davon ist Zierde:
 *
 *   1. Der Handler VERLANGT ihn. Ein Formular, das sich als "vorlage"
 *      ausgibt, aber keinen Marker mitbringt, wird abgewiesen statt geraten.
 *   2. Die Werkzeugkette erkennt daran, dass eine Aenderung ABSICHT ist.
 *      wirkungstest.py drueckt jeden Knopf und meldet jede Aenderung an der
 *      Konfiguration; ein Formular, dessen Zweck die Aenderung ist, waere
 *      damit bei jedem Lauf rot - und eine Warnung, die man bei jedem Lauf
 *      mitlesen muss, ist eine Regel in Prosa. Uebersprungen wird nur ein
 *      Formular ohne sichtbares Bedienelement, dessen Marker auf der Seite
 *      genau einmal vorkommt; deshalb heissen sie so und nicht anders.
 */
if ($by_post && $by_formular === 'vorlage') {
    if (!isset($_POST['download'])) {
        $by_fehler[] = by_t('ALLG.FEHLER_FORMULAR');
        $by_post = false;
    }
}
if ($by_post && $by_formular === 'vorlage') {
    $by_nr = (isset($_POST['nummer']) && preg_match('/^[0-9]{1,2}$/', (string) $_POST['nummer']))
        ? (int) $_POST['nummer'] : 1;
    $by_art = (string) $_POST['download'];
    list($by_name, $by_inhalt) = ($by_art === 'vo') ? by_vorlage_vo($by_nr) : by_vorlage($by_nr);
    header('Content-Type: application/x-download');
    // Die Anfuehrungszeichen um den Dateinamen sind Pflicht: ohne sie bricht
    // jeder Name, der ein Leerzeichen enthaelt.
    header('Content-Disposition: attachment; filename="' . $by_name . '"');
    echo $by_inhalt;
    exit;
}

/* ---------------- Einstellungen speichern ---------------- */
if ($by_post && $by_formular === 'einstellungen') {
    $by_cfg = by_config();

    foreach (array(
        'intervall'        => array(120, 3600),
        'temp_min'         => array(10, 32),
        'temp_max'         => array(10, 32),
        'verlauf_tage'     => array(1, 90),
        'wartezeit'        => array(0, 30),
        'abfahrt_vorlauf'  => array(1, 120),
        'abfahrt_temp'     => array(10, 32),
        'abfahrt_alter'    => array(60, 3600),
        'abfahrt_fahrzeug' => array(1, 99),
        'ladeempf_alter'   => array(60, 86400),
        'kapazitaet'       => array(0, 500),
        'heim_radius'      => array(20, 5000),
    ) as $by_feld => $by_grenzen) {
        $by_wert = isset($_POST[$by_feld]) ? trim((string) $_POST[$by_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $by_wert)) {
            $by_fehler[] = sprintf(by_t('EINST.FEHLER_ZAHL'),
                by_t('EINST.L_' . strtoupper($by_feld)));
            continue;
        }
        $by_zahl = (int) $by_wert;
        if ($by_zahl < $by_grenzen[0] || $by_zahl > $by_grenzen[1]) {
            $by_fehler[] = sprintf(by_t('EINST.FEHLER_BEREICH'),
                by_t('EINST.L_' . strtoupper($by_feld)), $by_grenzen[0], $by_grenzen[1]);
            continue;
        }
        $by_cfg[$by_feld] = $by_zahl;
    }
    // Abweisen, nicht still tauschen: der Dienst kappt zwar auch, aber zwei
    // Verhaltensweisen fuer denselben Fall ergeben andere Grenzen als
    // angezeigt.
    if (isset($by_cfg['temp_min'], $by_cfg['temp_max'])
        && $by_cfg['temp_min'] > $by_cfg['temp_max']) {
        $by_fehler[] = by_t('EINST.FEHLER_TEMP_TAUSCH');
    }

    $by_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $by_cfg['gps_ein'] = isset($_POST['gps_ein']) ? 1 : 0;
    $by_cfg['mqtt_bibliothek'] = isset($_POST['mqtt_bibliothek']) ? 1 : 0;
    $by_cfg['abfahrt_ein'] = isset($_POST['abfahrt_ein']) ? 1 : 0;
    $by_cfg['ladeempf_ein'] = isset($_POST['ladeempf_ein']) ? 1 : 0;
    $by_cfg['ladeempf_unter'] = isset($_POST['ladeempf_unter']) ? 1 : 0;

    /* Die Zieltemperatur der Vorklimatisierung muss in die Grenzen passen, die
     * fuer schreibende Befehle gelten. Sie wird ABGEWIESEN und nicht gekappt:
     * ein still veraenderter Sollwert fuehrt zu einem Fahrzeug, das etwas
     * anderes tut als angezeigt. */
    if (isset($by_cfg['abfahrt_temp'], $by_cfg['temp_min'], $by_cfg['temp_max'])
        && ($by_cfg['abfahrt_temp'] < $by_cfg['temp_min']
            || $by_cfg['abfahrt_temp'] > $by_cfg['temp_max'])) {
        $by_fehler[] = sprintf(by_t('EINST.FEHLER_ABFAHRT_TEMP'),
            (int) $by_cfg['abfahrt_temp'], (int) $by_cfg['temp_min'],
            (int) $by_cfg['temp_max']);
    }

    /* Themenpfade: dasselbe Muster wie beim eigenen Praefix. Wildcards sind
     * nicht erlaubt - ein Abo auf "#" liefert alles, was im Broker steht, und
     * die Ladeempfehlung rechnete dann mit dem erstbesten Wert. */
    foreach (array('abfahrt_praefix' => 'EINST.L_ABFAHRT_PRAEFIX',
                   'ladeempf_thema'  => 'EINST.L_LADEEMPF_THEMA') as $by_f => $by_bez) {
        $by_w = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
            isset($_POST[$by_f]) ? (string) $_POST[$by_f] : ''));
        if ($by_w === '') {
            // Leer ist erlaubt: die zugehoerige Funktion ist dann ohne Thema
            // und wird - sichtbar - nichts tun.
            $by_cfg[$by_f] = '';
            continue;
        }
        if (!preg_match('#^[A-Za-z0-9_/\-]{1,128}$#', $by_w)) {
            $by_fehler[] = sprintf(by_t('EINST.FEHLER_THEMA'), by_t($by_bez));
            continue;
        }
        $by_cfg[$by_f] = trim($by_w, '/');
    }

    /* Dezimalzahlen: Grenze und Heimatposition. Ein Komma wird zum Punkt -
     * das ist keine stille Zurechtbiegung, sondern die deutsche Schreibweise
     * derselben Zahl. Alles andere wird abgewiesen. */
    foreach (array(
        'ladeempf_grenze' => array(-100000, 100000, 'EINST.L_LADEEMPF_GRENZE'),
        'heim_breite'     => array(-90, 90, 'EINST.L_HEIM_BREITE'),
        'heim_laenge'     => array(-180, 180, 'EINST.L_HEIM_LAENGE'),
    ) as $by_f => $by_g) {
        $by_w = str_replace(',', '.', trim(isset($_POST[$by_f]) ? (string) $_POST[$by_f] : ''));
        if ($by_w === '') {
            $by_cfg[$by_f] = ($by_f === 'ladeempf_grenze') ? 0 : '';
            continue;
        }
        if (!preg_match('/^-?[0-9]{1,6}(\.[0-9]{1,8})?$/', $by_w)) {
            $by_fehler[] = sprintf(by_t('EINST.FEHLER_DEZIMAL'), by_t($by_g[2]));
            continue;
        }
        if ((float) $by_w < $by_g[0] || (float) $by_w > $by_g[1]) {
            $by_fehler[] = sprintf(by_t('EINST.FEHLER_BEREICH'), by_t($by_g[2]),
                $by_g[0], $by_g[1]);
            continue;
        }
        $by_cfg[$by_f] = $by_w;
    }
    /* Eine halbe Heimatposition ist keine. Ohne beide Werte bleibt das Feld
     * ZUHAUSE leer - und dass es leer bleibt, soll man hier erfahren und nicht
     * erst in Loxone. */
    if (($by_cfg['heim_breite'] === '') !== ($by_cfg['heim_laenge'] === '')) {
        $by_fehler[] = by_t('EINST.FEHLER_HEIM_HALB');
    }
    if (!empty($by_cfg['ladeempf_ein']) && trim((string) $by_cfg['ladeempf_thema']) === '') {
        $by_fehler[] = by_t('EINST.FEHLER_LADEEMPF_OHNE_THEMA');
    }

    /* Zugangsdaten: eigene Datei mit Rechten 0600. Ein leer zurueckgegebenes
     * Passwortfeld loescht nichts - sonst stuende irgendwann ein leeres
     * Passwort in der Datei, ohne dass es jemand merkt. */
    $by_benutzer = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        isset($_POST['benutzer']) ? (string) $_POST['benutzer'] : ''));
    $by_pw = isset($_POST['passwort']) ? (string) $_POST['passwort'] : '';
    $by_pin = isset($_POST['pin']) ? trim((string) $_POST['pin']) : '';
    $by_land = strtoupper(trim(preg_replace('/[^A-Za-z]/', '',
        isset($_POST['land']) ? (string) $_POST['land'] : '')));

    if (isset($_POST['zugang_loeschen'])) {
        // Ausdruecklich gewollt: alles weg. Was im selben Absenden in den
        // Feldern stand, wird verworfen - sonst waere unklar, ob Loeschen oder
        // Eintragen gewonnen hat.
        if (by_zugang_loeschen()) {
            $by_meldungen[] = by_t('EINST.ZUGANG_GELOESCHT');
        } else {
            $by_fehler[] = by_t('EINST.FEHLER_ZUGANG_LOESCHEN');
        }
    } else {
        // Ist die FORM eines Geheimnisses erkennbar falsch, wird beim Speichern
        // abgewiesen, statt den Benutzer in eine Fehlermeldung des Anbieters
        // laufen zu lassen.
        if ($by_pin !== '' && !preg_match('/^[0-9]{4,8}$/', $by_pin)) {
            $by_fehler[] = by_t('EINST.FEHLER_PIN');
        } elseif ($by_land !== '' && !preg_match('/^[A-Z]{2}$/', $by_land)) {
            $by_fehler[] = by_t('EINST.FEHLER_LAND');
        } elseif (!by_zugang_speichern($by_benutzer, $by_pw, $by_pin, $by_land)) {
            $by_fehler[] = by_t('EINST.FEHLER_ZUGANG_SPEICHERN');
        }
    }
    $by_zg = by_zugang();
    if ($by_zg['laenge'] > 0 && $by_zg['benutzer'] === '') {
        $by_fehler[] = by_t('EINST.WARN_PW_OHNE_KONTO');
    }
    // Schreibende Befehle ohne Steuer-PIN sind eine Beanstandung, aber kein
    // Grund, das Speichern zu verweigern: was sich zurechtruecken laesst, wird
    // gespeichert, und die Beanstandung erscheint daneben.
    if (!empty($by_cfg['steuerung_ein']) && $by_zg['pin_laenge'] === 0) {
        $by_meldungen[] = by_t('EINST.HINWEIS_PIN_FEHLT');
    }

    if (!$by_fehler) {
        if (by_config_speichern($by_cfg)) {
            $by_meldungen[] = by_t('EINST.GESPEICHERT');
        } else {
            $by_fehler[] = sprintf(by_t('EINST.FEHLER_SPEICHERN'), $by_p['config']);
        }
    }
    $by_tab = 'tab-settings';

    /* mqtt_ein und mqtt_topic werden hier bewusst NICHT angefasst: sie wohnen
     * im Reiter MQTT und haben dort ein eigenes Formular. Stuende hier weiter
     * "isset($_POST['mqtt_ein']) ? 1 : 0", schaltete jedes Speichern der
     * Einstellungen MQTT stillschweigend ab. */
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ---------------- */
if ($by_post && $by_formular === 'mqtt') {
    $by_mcfg = by_config();
    $by_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $by_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($by_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $by_mtopic)) {
        $by_fehler[] = by_t('EINST.FEHLER_TOPIC');
    } else {
        $by_mcfg['mqtt_topic'] = trim($by_mtopic, '/');
    }
    if (!$by_fehler && by_config_speichern($by_mcfg)) {
        $by_meldungen[] = by_t('EINST.GESPEICHERT');
    }
    $by_tab = 'tab-mqtt';
}

/* ---------------- Dienst starten, anhalten, neu starten ---------------- */
if ($by_post && $by_formular === 'dienst') {
    $by_befehl = isset($_POST['dienst']) ? (string) $_POST['dienst'] : '';
    list($by_ok, $by_text) = by_dienst($by_befehl);
    if ($by_ok) {
        $by_meldungen[] = by_t('EINST.DIENST_' . strtoupper($by_befehl)) . ' '
                        . by_e($by_text);
    } else {
        $by_fehler[] = by_e($by_text);
    }
    $by_tab = 'tab-settings';
}

/* ---------------- Neues Token ---------------- */
if ($by_post && $by_formular === 'token' && !isset($_POST['token_neu'])) {
    $by_fehler[] = by_t('ALLG.FEHLER_FORMULAR');
    $by_post = false;
}
if ($by_post && $by_formular === 'token') {
    $by_cfg = by_config();
    $by_cfg['aktionstoken'] = by_token_erzeugen();
    if (by_config_speichern($by_cfg)) {
        $by_meldungen[] = by_t('LOX.TOKEN_NEU');
        by_log('Ein neues Aktionstoken wurde erzeugt. Die bisherigen Adressen im '
             . 'Miniserver sind damit ungueltig.');
    } else {
        $by_fehler[] = sprintf(by_t('EINST.FEHLER_SPEICHERN'), $by_p['config']);
    }
    $by_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($by_post && $by_formular === 'log' && !isset($_POST['log_leeren'])) {
    $by_fehler[] = by_t('ALLG.FEHLER_FORMULAR');
    $by_post = false;
}
if ($by_post && $by_formular === 'log') {
    @mkdir(dirname($by_p['log']), 0775, true);
    @file_put_contents($by_p['log'], '[' . date('Y-m-d H:i:s') . '] '
        . by_t('LOG.GELEERT') . "\n");
    // Der Merker der Wiederholungsbremse gehoert mit weg: sonst unterdrueckt
    // sie ausgerechnet die erste Zeile in der leeren Datei.
    @unlink($by_p['log'] . '.wdh');
    $by_meldungen[] = by_t('LOG.GELEERT');
    $by_tab = 'tab-log';
}

/* ---------------- Aktionen des Reiters Test ---------------- */
if ($by_post && $by_formular === 'test') {
    list($by_stand, $by_text) = by_test_aktion(isset($_POST['test'])
        ? (string) $_POST['test'] : '');
    if ($by_stand === 1) {
        $by_meldungen[] = by_e($by_text);
    } elseif ($by_stand === 2) {
        // Rueckgabe 2 heisst "Ergebnis unbekannt" - das ist weder ein Erfolg
        // noch eine Ablehnung, und es wird auch nicht als eines dargestellt.
        $by_meldungen[] = '<b>' . by_e(by_t('TEST.UNBEKANNT')) . '</b> ' . by_e($by_text);
    } else {
        $by_fehler[] = by_e($by_text);
    }
    if (strpos($by_text, "\n") !== false) {
        $by_ausgabe = $by_text;
        array_pop($by_meldungen);
    }
    $by_tab = 'tab-test';
}
if ($by_post && $by_formular === 'selbsttest') {
    $by_ausgabe = by_selbsttest();
    $by_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$by_cfg = by_config();
$by_token = by_token();
$by_ftoken = by_formtoken($by_cfg);
$by_zg = by_zugang();
$by_fahrzeuge = by_fahrzeuge();
$by_zustand = by_zustand();
$by_alter = by_alter();
$by_pid = by_dienst_pid();
$by_mqtt = by_mqtt_zustand();
$by_libv = by_bibliothek_fassung();
$by_host = by_host();
$by_basis = 'http://' . $by_host . '/plugins/' . $by_p['plugin'] . '/index.php';
$by_logzeilen = is_file($by_p['log']) ? by_log_ende($by_p['log'], 400) : array();
$by_pruefungen = by_pruefungen();
$by_bilanz = by_pruefbilanz($by_pruefungen);

$by_rahmen = class_exists('LBWeb', false);
if ($by_rahmen) {
    LBWeb::lbheader('BYD Autos', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
/* Rollbehaelter fuer breite Tabellen. Ohne ihn steht die letzte Spalte auf
   einem schmalen Bildschirm AUSSERHALB und ist nicht erreichbar, nicht bloss
   unbequem: .sm-tbl hat width:100%, und .sm-wrap hat max-width ohne Ueberlauf.
   Jede Tabelle mit mehr als sechs Spalten oder mit Eingabefeldern kommt
   hinein. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Die Rahmen-CSS des
   LoxBerry setzt appearance:none, und damit verschwindet der Pfeil, den sonst
   der Browser zeichnet - das Feld sieht aus wie ein Textfeld, und wer nicht
   hineinklickt, erfaehrt nie, dass etwas dahintersteht. Der Pfeil wird deshalb
   selbst gezeichnet. Die Raute in der SVG-Adresse steht als %23: eine rohe
   Raute beendet den CSS-Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* sm-auswahl ist das Merkmal im Quelltext, an dem sich pruefen laesst, dass
   ein Auswahlfeld als solches gebaut wurde - ob es auf dem Bildschirm auch so
   AUSSIEHT, sieht kein Werkzeug dieser Kette.
   Die Klasse traegt dabei eine eigene Aufgabe und ist keine leere Marke: ein
   <select data-role="none"> bekommt von jQuery Mobile KEINEN Behaelter, und
   die Breitenbegrenzung der Hausvorlage greift nur am Behaelter
   (.sm-feld .ui-select). Ohne diese Zeile laeuft das Feld ueber die ganze
   Breite des Kastens. */
.sm-wrap .sm-auswahl { max-width: 520px; }
</style>
<div class="sm-wrap">

<?php foreach ($by_meldungen as $by_m) { ?>
<div class="sm-hinweis"><?= $by_m ?></div>
<?php } ?>
<?php if ($by_fehler) { ?>
<div class="sm-fehler"><b><?= by_e(by_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($by_fehler as $by_f) { ?><li><?= $by_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<!-- ================= Statuskacheln ================= -->
<div class="sm-kacheln">
  <div class="sm-kachel"><?= by_e(by_t('ALLG.DIENST')) ?>
    <b class="<?= $by_pid ? 'sm-an' : 'sm-aus' ?>"><?= $by_pid ? by_e(by_t('ALLG.LAEUFT')) : by_e(by_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $by_pid ? 'PID ' . (int) $by_pid : by_e(by_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= by_e(by_t('ALLG.LETZTER_ABRUF')) ?>
    <b><?= $by_alter < 0 ? '&ndash;' : (int) $by_alter . ' s' ?></b>
    <span class="sm-hilfe"><?= $by_alter < 0 ? by_e(by_t('ALLG.NIE')) : by_e(date('d.m.Y H:i:s', time() - $by_alter)) ?></span>
  </div>
  <div class="sm-kachel"><?= by_e(by_t('ALLG.FAHRZEUGE')) ?>
    <b><?= count($by_fahrzeuge) ?></b>
    <span class="sm-hilfe"><?= $by_libv !== '' ? 'pybyd ' . by_e($by_libv) : by_e(by_t('ALLG.LIB_FEHLT')) ?></span>
  </div>
  <div class="sm-kachel"><?= by_e(by_t('ALLG.SELBSTPRUEFUNG')) ?>
    <b class="<?= $by_bilanz['nein'] ? 'sm-aus' : 'sm-an' ?>"><?= (int) $by_bilanz['ja'] ?>/<?= (int) $by_bilanz['summe'] ?></b>
    <span class="sm-hilfe"><?= sprintf(by_e(by_t('ALLG.HINWEISE')), (int) $by_bilanz['hinweis']) ?></span>
  </div>
</div>

<?php if (!empty($by_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= by_e(by_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= by_e($by_zustand['fehler']) ?></div>
<?php } ?>

<?php foreach ($by_fahrzeuge as $by_nr => $by_fz) { ?>
<div class="sm-hinweis">
<b><?= by_e(trim(($by_fz['marke'] !== '' ? $by_fz['marke'] . ' ' : '') . $by_fz['modell'])) !== ''
    ? by_e(trim($by_fz['marke'] . ' ' . $by_fz['modell'])) : by_e(by_t('ALLG.OHNE_NAMEN')) ?></b>
(<?= by_e(by_t('ALLG.FAHRZEUG')) ?> <?= by_e($by_nr) ?><?= !empty($by_fz['kennzeichen']) ? ', ' . by_e($by_fz['kennzeichen']) : '' ?>)
&middot; <?= by_e(by_t('ALLG.SOC')) ?> <b><?= !isset($by_fz['SOC']) || $by_fz['SOC'] === null ? '&ndash;' : by_e($by_fz['SOC']) . ' %' ?></b>
&middot; <?= by_e(by_t('ALLG.REICHWEITE')) ?> <?= !isset($by_fz['REICHW']) || $by_fz['REICHW'] === null ? '&ndash;' : by_e($by_fz['REICHW']) . ' km' ?>
&middot; <?= by_e(by_t('ALLG.KM')) ?> <?= !isset($by_fz['KM']) || $by_fz['KM'] === null ? '&ndash;' : by_e($by_fz['KM']) . ' km' ?>
<div style="margin-top:8px;"><?= by_soc_svg(by_verlauf_lesen((int) $by_nr)) ?></div>
<div class="sm-hilfe"><?= by_t('ALLG.VERLAUF_HINWEIS') ?></div>
<?php if (!empty($by_fz['offen']) && is_array($by_fz['offen'])) { ?>
<div class="sm-hilfe"><b><?= by_e(by_t('ALLG.OHNE_TREFFER')) ?></b>
<span class="sm-mono"><?= by_e(implode(', ', $by_fz['offen'])) ?></span><br>
<?= by_t('ALLG.OHNE_TREFFER_HINWEIS') ?>
</div>
<?php } ?>
<?php if (!empty($by_fz['ausfaelle']) && is_array($by_fz['ausfaelle'])) { ?>
<div class="sm-hilfe"><b><?= by_e(by_t('ALLG.AUSFAELLE')) ?></b>
<?php foreach ($by_fz['ausfaelle'] as $by_a) { ?><br><?= by_e($by_a) ?><?php } ?>
</div>
<?php } ?>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, ein Skript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar, Eingaben in anderen Reitern gehen nicht verloren, und
     die Zuruecktaste tut das Erwartete.

     WELCHER REITER OFFEN IST, ENTSCHEIDET DER SERVER. Die Klasse sm-active
     steht schon im ausgelieferten HTML - an der Leiste UND am Bereich. Ohne
     das ist die Seite ohne JavaScript vollstaendig leer, denn .sm-seite steht
     auf display:none.

     Ausgeschrieben, nicht in einer Schleife erzeugt: das Pruefwerkzeug sucht
     die Reiter woertlich. Die Uebereinstimmung mit der Liste und den Bereichen
     misst der Reiter Test nach. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $by_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= by_e(by_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $by_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $by_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?form=loxone"><?= by_e(by_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $by_tab === 'tab-ladungen' ? ' sm-active' : '' ?>" data-ziel="tab-ladungen" href="index.php?form=ladungen"><?= by_e(by_t('REITER.LADUNGEN')) ?></a>
	<a class="sm-tab<?= $by_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?form=test"><?= by_e(by_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $by_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?form=log"><?= by_e(by_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $by_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<div class="sm-warnung"><?= by_t('EINST.UNGEPRUEFT') ?></div>

<h2><?= by_e(by_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= by_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= by_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= by_t('LEGENDE.AKTION') ?></span>
</div>
<!-- Die Knopfklassen stehen AUSGESCHRIEBEN, nicht aus einer Schleife
     zusammengesetzt. Eine Klasse, die zur Laufzeit entsteht, kann kein
     Pruefwerkzeug sehen: hausstandard_pruefen.py fand daraufhin keinen gruenen
     Knopf und meldete die Legende als falsch - ein Befund, der keiner war, und
     ein blinder Fleck an der Stelle, an der die Farbe die Wirkung ankuendigt.
     Der erste Entwurf dieser Reihe hatte genau das. -->
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="dienst">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= by_e(by_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="dienst">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= by_e(by_t('EINST.K_RESTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="dienst">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= by_e(by_t('EINST.K_STOP')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="formular" value="einstellungen">
<input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= by_e(by_t('EINST.H_KONTO')) ?></h2>
<div class="sm-warnung"><?= by_t('EINST.KONTO_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="benutzer"><?= by_e(by_t('EINST.L_BENUTZER')) ?></label>
  <input data-role="none" type="text" id="benutzer" name="benutzer" value="<?= by_e($by_zg['benutzer']) ?>">
  <div class="sm-hilfe"><?= by_t('EINST.H_BENUTZER') ?></div>
</div>
<div class="sm-feld">
  <label for="passwort"><?= by_e(by_t('EINST.L_PASSWORT')) ?></label>
  <input data-role="none" type="password" id="passwort" name="passwort" value="" placeholder="<?= $by_zg['laenge'] > 0 ? by_e(sprintf(by_t('EINST.PW_GESETZT'), $by_zg['laenge'])) : by_e(by_t('EINST.PW_LEER')) ?>">
  <div class="sm-hilfe"><?= by_t('EINST.H_PASSWORT') ?></div>
</div>
<div class="sm-feld">
  <label for="pin"><?= by_e(by_t('EINST.L_PIN')) ?></label>
  <input data-role="none" type="password" id="pin" name="pin" value="" maxlength="8" placeholder="<?= $by_zg['pin_laenge'] > 0 ? by_e(by_t('EINST.PIN_GESETZT')) : by_e(by_t('EINST.PIN_LEER')) ?>">
  <div class="sm-hilfe"><?= by_t('EINST.H_PIN') ?></div>
</div>
<div class="sm-feld">
  <label for="land"><?= by_e(by_t('EINST.L_LAND')) ?></label>
  <input data-role="none" type="text" id="land" name="land" value="<?= by_e($by_zg['land']) ?>" maxlength="2" placeholder="DE">
  <div class="sm-hilfe"><?= by_t('EINST.H_LAND') ?></div>
</div>
<?php if ($by_zg['benutzer'] !== '' || $by_zg['laenge'] > 0 || $by_zg['pin_laenge'] > 0) { ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="zugang_loeschen" value="1">
    <?= by_e(by_t('EINST.L_ZUGANG_LOESCHEN')) ?>
  </label>
  <div class="sm-hilfe"><?= by_t('EINST.H_ZUGANG_LOESCHEN') ?></div>
</div>
<?php } ?>

<h2><?= by_e(by_t('EINST.H_TAKT')) ?></h2>
<div class="sm-warnung"><?= by_t('EINST.TAKT_WARNUNG') ?></div>
<div class="sm-feld">
  <label for="intervall"><?= by_e(by_t('EINST.L_INTERVALL')) ?></label>
  <input data-role="none" type="number" id="intervall" name="intervall" value="<?= (int) $by_cfg['intervall'] ?>" min="120" max="3600">
  <div class="sm-hilfe"><?= by_t('EINST.H_INTERVALL') ?></div>
</div>
<div class="sm-feld">
  <label for="verlauf_tage"><?= by_e(by_t('EINST.L_VERLAUF_TAGE')) ?></label>
  <input data-role="none" type="number" id="verlauf_tage" name="verlauf_tage" value="<?= (int) $by_cfg['verlauf_tage'] ?>" min="1" max="90">
  <div class="sm-hilfe"><?= by_t('EINST.H_VERLAUF_TAGE') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="gps_ein" value="1" <?= !empty($by_cfg['gps_ein']) ? 'checked' : '' ?>>
    <?= by_e(by_t('EINST.L_GPS_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= by_t('EINST.H_GPS_EIN') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_bibliothek" value="1" <?= !empty($by_cfg['mqtt_bibliothek']) ? 'checked' : '' ?>>
    <?= by_e(by_t('EINST.L_MQTT_BIBLIOTHEK')) ?>
  </label>
  <div class="sm-hilfe"><?= by_t('EINST.H_MQTT_BIBLIOTHEK') ?></div>
</div>

<h2><?= by_e(by_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= by_t('EINST.STEUERUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty($by_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= by_e(by_t('EINST.L_STEUERUNG_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="temp_min"><?= by_e(by_t('EINST.L_TEMP_MIN')) ?></label>
  <input data-role="none" type="number" id="temp_min" name="temp_min" value="<?= (int) $by_cfg['temp_min'] ?>" min="10" max="32">
</div>
<div class="sm-feld">
  <label for="temp_max"><?= by_e(by_t('EINST.L_TEMP_MAX')) ?></label>
  <input data-role="none" type="number" id="temp_max" name="temp_max" value="<?= (int) $by_cfg['temp_max'] ?>" min="10" max="32">
  <div class="sm-hilfe"><?= by_t('EINST.H_TEMP') ?></div>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= by_e(by_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $by_cfg['wartezeit'] ?>" min="0" max="30">
  <div class="sm-hilfe"><?= by_t('EINST.H_WARTEZEIT') ?></div>
</div>

<h2><?= by_e(by_t('EINST.H_ABFAHRT')) ?></h2>
<div class="sm-warnung"><?= by_t('EINST.ABFAHRT_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="abfahrt_ein" value="1" <?= !empty($by_cfg['abfahrt_ein']) ? 'checked' : '' ?>>
    <?= by_e(by_t('EINST.L_ABFAHRT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="abfahrt_praefix"><?= by_e(by_t('EINST.L_ABFAHRT_PRAEFIX')) ?></label>
  <input data-role="none" type="text" id="abfahrt_praefix" name="abfahrt_praefix" value="<?= by_e($by_cfg['abfahrt_praefix']) ?>" placeholder="abfahrt">
  <div class="sm-hilfe"><?= by_t('EINST.H_ABFAHRT_PRAEFIX') ?></div>
</div>
<div class="sm-feld">
  <label for="abfahrt_vorlauf"><?= by_e(by_t('EINST.L_ABFAHRT_VORLAUF')) ?></label>
  <input data-role="none" type="number" id="abfahrt_vorlauf" name="abfahrt_vorlauf" value="<?= (int) $by_cfg['abfahrt_vorlauf'] ?>" min="1" max="120">
  <div class="sm-hilfe"><?= by_t('EINST.H_ABFAHRT_VORLAUF') ?></div>
</div>
<div class="sm-feld">
  <label for="abfahrt_temp"><?= by_e(by_t('EINST.L_ABFAHRT_TEMP')) ?></label>
  <input data-role="none" type="number" id="abfahrt_temp" name="abfahrt_temp" value="<?= (int) $by_cfg['abfahrt_temp'] ?>" min="10" max="32">
</div>
<div class="sm-feld">
  <label for="abfahrt_fahrzeug"><?= by_e(by_t('EINST.L_ABFAHRT_FAHRZEUG')) ?></label>
  <input data-role="none" type="number" id="abfahrt_fahrzeug" name="abfahrt_fahrzeug" value="<?= (int) $by_cfg['abfahrt_fahrzeug'] ?>" min="1" max="99">
  <div class="sm-hilfe"><?= by_t('EINST.H_ABFAHRT_FAHRZEUG') ?></div>
</div>
<div class="sm-feld">
  <label for="abfahrt_alter"><?= by_e(by_t('EINST.L_ABFAHRT_ALTER')) ?></label>
  <input data-role="none" type="number" id="abfahrt_alter" name="abfahrt_alter" value="<?= (int) $by_cfg['abfahrt_alter'] ?>" min="60" max="3600">
  <div class="sm-hilfe"><?= by_t('EINST.H_ABFAHRT_ALTER') ?></div>
</div>

<h2><?= by_e(by_t('EINST.H_LADEEMPF')) ?></h2>
<div class="sm-hinweis"><?= by_t('EINST.LADEEMPF_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ladeempf_ein" value="1" <?= !empty($by_cfg['ladeempf_ein']) ? 'checked' : '' ?>>
    <?= by_e(by_t('EINST.L_LADEEMPF_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="ladeempf_thema"><?= by_e(by_t('EINST.L_LADEEMPF_THEMA')) ?></label>
  <input data-role="none" type="text" id="ladeempf_thema" name="ladeempf_thema" value="<?= by_e($by_cfg['ladeempf_thema']) ?>" placeholder="awattar/preis_ct">
  <div class="sm-hilfe"><?= by_t('EINST.H_LADEEMPF_THEMA') ?></div>
</div>
<div class="sm-feld">
  <label for="ladeempf_grenze"><?= by_e(by_t('EINST.L_LADEEMPF_GRENZE')) ?></label>
  <input data-role="none" type="text" id="ladeempf_grenze" name="ladeempf_grenze" value="<?= by_e($by_cfg['ladeempf_grenze']) ?>">
  <div class="sm-hilfe"><?= by_t('EINST.H_LADEEMPF_GRENZE') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ladeempf_unter" value="1" <?= !empty($by_cfg['ladeempf_unter']) ? 'checked' : '' ?>>
    <?= by_e(by_t('EINST.L_LADEEMPF_UNTER')) ?>
  </label>
  <div class="sm-hilfe"><?= by_t('EINST.H_LADEEMPF_UNTER') ?></div>
</div>
<div class="sm-feld">
  <label for="ladeempf_alter"><?= by_e(by_t('EINST.L_LADEEMPF_ALTER')) ?></label>
  <input data-role="none" type="number" id="ladeempf_alter" name="ladeempf_alter" value="<?= (int) $by_cfg['ladeempf_alter'] ?>" min="60" max="86400">
  <div class="sm-hilfe"><?= by_t('EINST.H_LADEEMPF_ALTER') ?></div>
</div>

<h2><?= by_e(by_t('EINST.H_GERECHNET')) ?></h2>
<div class="sm-hinweis"><?= by_t('EINST.GERECHNET_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="kapazitaet"><?= by_e(by_t('EINST.L_KAPAZITAET')) ?></label>
  <input data-role="none" type="number" id="kapazitaet" name="kapazitaet" value="<?= (int) $by_cfg['kapazitaet'] ?>" min="0" max="500">
  <div class="sm-hilfe"><?= by_t('EINST.H_KAPAZITAET') ?></div>
</div>
<div class="sm-feld">
  <label for="heim_breite"><?= by_e(by_t('EINST.L_HEIM_BREITE')) ?></label>
  <input data-role="none" type="text" id="heim_breite" name="heim_breite" value="<?= by_e($by_cfg['heim_breite']) ?>" placeholder="48.137">
</div>
<div class="sm-feld">
  <label for="heim_laenge"><?= by_e(by_t('EINST.L_HEIM_LAENGE')) ?></label>
  <input data-role="none" type="text" id="heim_laenge" name="heim_laenge" value="<?= by_e($by_cfg['heim_laenge']) ?>" placeholder="11.575">
  <div class="sm-hilfe"><?= by_t('EINST.H_HEIM') ?></div>
</div>
<div class="sm-feld">
  <label for="heim_radius"><?= by_e(by_t('EINST.L_HEIM_RADIUS')) ?></label>
  <input data-role="none" type="number" id="heim_radius" name="heim_radius" value="<?= (int) $by_cfg['heim_radius'] ?>" min="20" max="5000">
  <div class="sm-hilfe"><?= by_t('EINST.H_HEIM_RADIUS') ?></div>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= by_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= by_e(by_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= by_e(by_t('EINST.H_ERKANNT')) ?></h2>
<?php if (!$by_fahrzeuge) { ?>
<div class="sm-warnung"><?= by_t('EINST.KEINE_FAHRZEUGE') ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= by_e(by_t('EINST.T_NR')) ?></th><th><?= by_e(by_t('EINST.T_MARKE')) ?></th>
    <th><?= by_e(by_t('EINST.T_MODELL')) ?></th><th><?= by_e(by_t('EINST.T_NAME')) ?></th>
    <th><?= by_e(by_t('EINST.T_KENNZEICHEN')) ?></th><th><?= by_e(by_t('EINST.T_VIN')) ?></th>
    <th><?= by_e(by_t('EINST.T_ANTRIEB')) ?></th><th><?= by_e(by_t('EINST.T_TBOX')) ?></th></tr>
<?php foreach ($by_fahrzeuge as $by_nr => $by_fz) { ?>
<tr><td><?= by_e($by_nr) ?></td><td><?= by_e($by_fz['marke']) ?></td>
    <td><?= by_e($by_fz['modell']) ?></td><td><?= by_e($by_fz['name']) ?></td>
    <td><?= by_e($by_fz['kennzeichen']) ?></td>
    <td><span class="sm-mono"><?= by_e($by_fz['vin']) ?></span></td>
    <td><?= by_e($by_fz['antriebsart']) ?></td><td><?= by_e($by_fz['tbox']) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= by_t('EINST.VIN_HINWEIS') ?></p>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $by_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2>MQTT</h2>
<p class="sm-hilfe"><?= by_t('MQTT.GATEWAY_ERKLAERUNG') ?></p>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formular" value="mqtt">
<input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($by_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= by_e(by_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= by_e(by_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= by_e($by_cfg['mqtt_topic']) ?>" placeholder="byd">
  <div class="sm-hilfe"><?= by_t('EINST.H_MQTT_TOPIC') ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= by_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= by_e(by_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= by_e(by_t('MQTT.H_ZUSTAND')) ?></h2>
<?php if (!$by_mqtt['gefunden']) { ?>
<div class="sm-fehler"><?= by_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$by_mqtt['autostart']) { ?>
<div class="sm-fehler"><?= by_t('MQTT.AUTOSTART_AUS') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= by_t('MQTT.AUTOSTART_EIN') ?></div>
<?php } ?>

<table class="sm-tbl">
<tr><th><?= by_e(by_t('ALLG.EIGENSCHAFT')) ?></th><th><?= by_e(by_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= by_e(by_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $by_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $by_mqtt['autostart'] ? by_e(by_t('ALLG.EIN')) : by_e(by_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= by_e(by_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= by_e($by_mqtt['broker']) ?>:<?= by_e($by_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= by_e(by_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $by_mqtt['udpport'] ?></span></td></tr>
<tr><td><?= by_e(by_t('MQTT.T_PLUGIN')) ?></td><td class="<?= !empty($by_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($by_cfg['mqtt_ein']) ? by_e(by_t('ALLG.EIN')) : by_e(by_t('ALLG.AUS')) ?></td></tr>
</table>

<h2><?= by_e(by_t('MQTT.H_ABO')) ?></h2>
<div class="sm-warnung"><?= by_t('MQTT.ABO_WARNUNG') ?></div>
<div class="sm-step">
<?= by_t('MQTT.ABO_SCHRITTE') ?>
<p><span class="sm-mono"><?= by_e($by_cfg['mqtt_topic']) ?>/#</span></p>
</div>

<h2><?= by_e(by_t('MQTT.H_THEMEN')) ?></h2>
<p class="sm-hilfe"><?= by_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= by_e(by_t('MQTT.T_THEMA')) ?></th><th><?= by_e(by_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (by_mqtt_themen() as $by_thema => $by_schluessel) { ?>
<tr><td><span class="sm-mono"><?= by_e($by_cfg['mqtt_topic'] . '/' . $by_thema) ?></span></td>
    <td><?= by_t($by_schluessel) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= by_t('MQTT.PLATZHALTER') ?></p>
<div class="sm-hinweis"><?= by_t('MQTT.UMBENENNUNG') ?></div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $by_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= by_e(by_t('LOX.H_TITEL')) ?></h2>
<p><?= by_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= by_e(by_t('LOX.S1_TITEL')) ?></b><br>
<?= by_t('LOX.S1_TEXT') ?>
</div>

<div class="sm-step"><b><?= by_e(by_t('LOX.S2_TITEL')) ?></b><br>
<?= by_t('LOX.S2_TEXT') ?>
<p><span class="sm-mono"><?= by_e($by_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= by_t('LOX.S2_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= by_e(by_t('LOX.S3_TITEL')) ?></b><br>
<?= by_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= by_e(by_t('ALLG.EIGENSCHAFT')) ?></th><th><?= by_e(by_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= by_e(by_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= by_e($by_basis) ?>?token=<?= by_e($by_token) ?>&amp;aktion=status&amp;fahrzeug=1</span></td></tr>
<tr><td><?= by_e(by_t('LOX.T_ZYKLUS')) ?></td><td>300 <?= by_e(by_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?= by_t('LOX.S3_BEFEHLE') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= by_e(by_t('LOX.T_TITEL')) ?></th><th><?= by_e(by_t('LOX.T_BEFEHL')) ?></th>
    <th><?= by_e(by_t('LOX.T_EINHEIT')) ?></th><th><?= by_e(by_t('LOX.T_BEDEUTUNG')) ?></th>
    <th><?= by_e(by_t('LOX.T_HERKUNFT')) ?></th></tr>
<?php foreach (by_felder_zeile() as $by_feld => $by_info) { ?>
<tr><td><span class="sm-mono">BYD 1 <?= by_e(by_klartext(by_t($by_info['bez']))) ?></span></td>
    <td><span class="sm-mono"><?= by_e(by_check($by_feld)) ?></span></td>
    <td><?= $by_info['einheit'] ?></td><td><?= by_t($by_info['bez']) ?></td>
    <td><?= by_e(by_herkunft_text($by_info['quelle'])) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><?= by_t('LOX.S3_STRICH') ?></div>
<div class="sm-hinweis"><?= by_t('LOX.S3_HERKUNFT') ?></div>
<?php if (count($by_fahrzeuge) > 1) { ?>
<p><b><?= by_e(by_t('LOX.MEHRERE_FAHRZEUGE')) ?></b></p>
<table class="sm-tbl">
<tr><th><?= by_e(by_t('ALLG.FAHRZEUG')) ?></th><th><?= by_e(by_t('EINST.T_MODELL')) ?></th><th><?= by_e(by_t('LOX.T_ADRESSE')) ?></th></tr>
<?php foreach ($by_fahrzeuge as $by_nr => $by_fz) { ?>
<tr><td><?= by_e($by_nr) ?></td><td><?= by_e($by_fz['modell']) ?></td>
    <td><span class="sm-mono"><?= by_e($by_basis) ?>?token=<?= by_e($by_token) ?>&amp;aktion=status&amp;fahrzeug=<?= by_e($by_nr) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>
<h3><?= by_e(by_t('LOX.H_ALLES')) ?></h3>
<p><?= by_t('LOX.ALLES_TEXT') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= by_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="vorlage">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="download" value="vi">
    <input data-role="none" type="hidden" name="nummer" value="1">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= by_e(by_t('LOX.K_VORLAGE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="vorlage">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="download" value="vo">
    <input data-role="none" type="hidden" name="nummer" value="1">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= by_e(by_t('LOX.K_VORLAGE_VO')) ?></button>
  </form>
</div>
<div class="sm-warnung"><?= by_t('LOX.VORLAGE_ZWEIMAL') ?></div>
</div>

<div class="sm-step"><b><?= by_e(by_t('LOX.S4_TITEL')) ?></b><br>
<?= by_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><td><span class="sm-mono"><?= by_e($by_basis) ?>?token=<?= by_e($by_token) ?>&amp;aktion=position&amp;fahrzeug=1</span></td>
    <td><span class="sm-mono"><?= by_e(by_check('BREITE')) ?></span> /
        <span class="sm-mono"><?= by_e(by_check('LAENGE')) ?></span></td></tr>
</table>
</div>

<div class="sm-step"><b><?= by_e(by_t('LOX.S5_TITEL')) ?></b><br>
<?= by_t('LOX.S5_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= by_e(by_t('LOX.T_BEFEHL')) ?></th><th><?= by_e(by_t('LOX.T_ADRESSE')) ?></th></tr>
<?php foreach (by_befehle() as $by_aktion => $by_eig) { ?>
<tr><td><?= by_t($by_eig['bez']) ?></td>
    <td><span class="sm-mono">/plugins/<?= by_e($by_p['plugin']) ?>/index.php?token=<?= by_e($by_token) ?>&amp;aktion=<?= by_e($by_aktion) ?><?= $by_aktion === 'abruf' ? '' : '&amp;fahrzeug=1' ?><?php
    if ($by_eig['zusatz'] === 'temp') { echo '&amp;temp=&lt;v&gt;'; }
    elseif ($by_eig['zusatz'] === 'stufe') { echo '&amp;stufe=&lt;v&gt;'; } ?></span></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><?= by_t('LOX.S5_WARNUNG') ?></div>
<div class="sm-hinweis"><?= by_t('LOX.S5_KEIN_LADEN') ?></div>
</div>

<div class="sm-step"><b><?= by_e(by_t('LOX.S6_TITEL')) ?></b><br>
<?= by_t('LOX.S6_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= by_e(by_t('ALLG.EIGENSCHAFT')) ?></th><th><?= by_e(by_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= by_e(by_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= by_e($by_token) ?></span></td></tr>
<tr><td><?= by_e(by_t('LOX.T_SELBSTTEST')) ?></td>
    <td><span class="sm-mono"><?= by_e($by_basis) ?>?selftest=1&amp;token=<?= by_e($by_token) ?></span></td></tr>
</table>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= by_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="token">
    <input data-role="none" type="hidden" name="token_neu" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= by_e(by_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
</div>

<div class="sm-step"><b><?= by_e(by_t('LOX.S7_TITEL')) ?></b><br>
<?= by_t('LOX.S7_TEXT') ?>
</div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 *
 * Anspruch: Wer die Tabelle von oben nach unten abarbeitet, hat die Funktion
 * nachgebaut, ohne nachzudenken. Loxone Config fuehrt alle Bausteine in der
 * Baustein-Suche (F5).
 *
 * ZWEI EIGENSCHAFTEN, DIE HIER TRAGEN:
 *
 * 1. KEIN Baustein hat mehr als ZWEI Eingaenge. And und Or fuehren in Loxone
 *    genau I1 und I2 - die Zahl ist eine Baustein-Eigenschaft, die Config aus
 *    seinem eigenen Modell setzt. Eine Bauanleitung, die einen ODER-Baustein
 *    mit vier Eingaengen zeichnet, ist nicht nachbaubar; drei Bedingungen
 *    ergeben eine Kaskade aus zwei Bausteinen.
 * 2. KEINE Vorwaertsverweise. Jede Zeile bezieht sich nur auf kleinere
 *    Nummern - wer die Tabelle von oben nach unten abarbeitet, hat nie einen
 *    Eingang ohne Quelle.
 */
function by_bausteine()
{
    $b = array();
    $n = 0;
    // Je Feld der Statuszeile ein virtueller Eingang. Aus derselben Quelle wie
    // die Zeile selbst - eine getippte Liste liefe damit auseinander, und die
    // Verweise darunter (#5, #16 ...) wuerden sich lautlos verschieben.
    foreach (by_felder_zeile() as $feld => $eig) {
        $n++;
        $b[] = array($n, 'BAUSTEIN.T_VE', by_klartext(by_t($eig['bez'])),
                     'BYD 1 ' . $feld, '&mdash;');
    }
    /* Die Nummern werden GERECHNET, nicht getippt.
     *
     * Eine Zahl, die auf eine erzeugte Liste zeigt, ist eine Zeitbombe: kommt
     * ein Feld dazu, verschiebt sich jeder Verweis um eins - lautlos, denn
     * eine Zahl sieht immer richtig aus. Genau das ist in einem
     * Schwesterplugin passiert, als ein neuntes Feld dazukam. */
    $nr = array();
    $i = 0;
    foreach (array_keys(by_felder_zeile()) as $feld) {
        $i++;
        $nr[$feld] = $i;
    }
    $nr_ok = isset($nr['OK']) ? $nr['OK'] : 1;
    $nr_soc = isset($nr['SOC']) ? $nr['SOC'] : 1;
    $nr_alter = isset($nr['ALTER']) ? $nr['ALTER'] : $n;
    $nr_schloss = isset($nr['SCHLOSSVL']) ? $nr['SCHLOSSVL'] : 1;
    $nr_ladezust = isset($nr['LADEZUST']) ? $nr['LADEZUST'] : 1;
    $pf = '&larr; #';
    $b[] = array(++$n, 'BAUSTEIN.T_NICHT',   'BAUSTEIN.N_NICHT',  '',
                 'I ' . $pf . $nr_ok);
    $b[] = array(++$n, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N_ALT',    'BAUSTEIN.P_ALT',
                 'I ' . $pf . $nr_alter);
    $b[] = array(++$n, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N_STOER',  '',
                 'I1 ' . $pf . ($n - 2) . ', I2 ' . $pf . ($n - 1));
    $b[] = array(++$n, 'BAUSTEIN.T_EVZ',     'BAUSTEIN.N_EVZ',    'BAUSTEIN.P_EVZ',
                 'I ' . $pf . ($n - 1));
    $b[] = array(++$n, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N_MELD1',  'BAUSTEIN.P_MELD',
                 'I ' . $pf . ($n - 1));
    $b[] = array(++$n, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N_SOC',    'BAUSTEIN.P_SOC',
                 'I ' . $pf . $nr_soc);
    $b[] = array(++$n, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N_MELD2',  'BAUSTEIN.P_MELD',
                 'I ' . $pf . ($n - 1));
    $b[] = array(++$n, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N_OFFEN',  'BAUSTEIN.P_OFFEN',
                 'I ' . $pf . $nr_schloss);
    $b[] = array(++$n, 'BAUSTEIN.T_UND',     'BAUSTEIN.N_UND',    '',
                 'I1 ' . $pf . ($n - 1) . ', I2 ' . $pf . by_t('BAUSTEIN.ANWESEND'));
    $b[] = array(++$n, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N_MELD3',  'BAUSTEIN.P_MELD',
                 'I ' . $pf . ($n - 1));
    $b[] = array(++$n, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N_STATUS', 'BAUSTEIN.P_STATUS',
                 'I1 ' . $pf . $nr_ladezust);
    $b[] = array(++$n, 'BAUSTEIN.T_WOCHE',   'BAUSTEIN.N_WOCHE',  'BAUSTEIN.P_WOCHE',
                 '&mdash;');
    $b[] = array(++$n, 'BAUSTEIN.T_TASTER',  'BAUSTEIN.N_TASTER', 'BAUSTEIN.P_TASTER',
                 '&mdash;');
    $b[] = array(++$n, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N_ODER2',  '',
                 'I1 ' . $pf . ($n - 2) . ', I2 ' . $pf . ($n - 1));
    $b[] = array(++$n, 'BAUSTEIN.T_VA',      'BAUSTEIN.N_VA_KLIMA', 'BAUSTEIN.P_VA_KLIMA',
                 'I ' . $pf . ($n - 1));
    $b[] = array(++$n, 'BAUSTEIN.T_VA',      'BAUSTEIN.N_VA_VERR', 'BAUSTEIN.P_VA_VERR',
                 by_t('BAUSTEIN.MANUELL'));
    $b[] = array(++$n, 'BAUSTEIN.T_VA',      'BAUSTEIN.N_VA_ABRUF', 'BAUSTEIN.P_VA_ABRUF',
                 by_t('BAUSTEIN.MANUELL'));
    return $b;
}
?>

<div class="sm-step"><b><?= by_e(by_t('LOX.S8_TITEL')) ?></b><br>
<?= by_t('LOX.S8_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= by_e(by_t('LOX.T_BAUSTEIN')) ?></th><th><?= by_e(by_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= by_e(by_t('LOX.T_PARAMETER')) ?></th><th><?= by_e(by_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (by_bausteine() as $by_b) { ?>
<tr><td><?= (int) $by_b[0] ?></td><td><?= by_t($by_b[1]) ?></td>
    <td><?= strpos($by_b[2], 'BAUSTEIN.') === 0 ? by_t($by_b[2]) : by_e($by_b[2]) ?></td>
    <td><?= $by_b[3] === '' ? '&mdash;' : (strpos($by_b[3], 'BAUSTEIN.') === 0 ? by_t($by_b[3]) : '<span class="sm-mono">' . by_e($by_b[3]) . '</span>') ?></td>
    <td><?= $by_b[4] ?></td></tr>
<?php } ?>
</table>
</div>
<?= by_t('LOX.S8_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= by_e(by_t('LOX.S9_TITEL')) ?></b><br>
<?= by_t('LOX.S9_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= by_e(by_t('LOX.T_PRUEFUNG')) ?></th><th><?= by_e(by_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= by_e($by_basis) ?>?token=<?= by_e($by_token) ?>&amp;aktion=status</span></td>
    <td><span class="sm-mono">BYD;OK=1;SOC=...</span></td></tr>
<tr><td><span class="sm-mono"><?= by_e($by_basis) ?>?selftest=1&amp;token=<?= by_e($by_token) ?></span></td>
    <td><span class="sm-mono">SELFTEST;OK=1;TOKEN=OK</span></td></tr>
<tr><td><span class="sm-mono"><?= by_e($by_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">BYD;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= by_e($by_basis) ?>?token=<?= by_e($by_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">BYD;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
</table>
</div>
</div>
</div>

<!-- ================= Reiter: Ladevorgänge ================= -->
<div class="sm-seite<?= $by_tab === 'tab-ladungen' ? ' sm-active' : '' ?>" id="tab-ladungen">
<h2><?= by_e(by_t('LADUNG.H_TITEL')) ?></h2>
<p class="sm-hilfe"><?= by_t('LADUNG.ERKLAERUNG') ?></p>
<?php
$by_ladungen = by_ladungen_lesen(200);
if (!$by_ladungen) { ?>
<div class="sm-hinweis"><?= by_t('LADUNG.LEER') ?></div>
<?php } else { ?>
<div class="sm-kacheln">
<?php
/* Die Zusammenfassung urteilt ueber eine Menge - also wird zuerst geprueft,
   ob die Menge leer ist. Und gerechnet wird nur ueber die Zeilen, die die
   Zutat wirklich tragen: eine Summe ueber Ladevorgaenge ohne kWh waere eine
   Zahl, die aussieht wie eine Messung. Wie viele Zeilen eingegangen sind,
   steht daneben. */
$by_mit_kwh = array_values(array_filter($by_ladungen, function ($x) {
    return $x['kwh'] !== null;
}));
$by_summe = 0.0;
foreach ($by_mit_kwh as $by_x) { $by_summe += $by_x['kwh']; }
?>
  <div class="sm-kachel"><?= by_e(by_t('LADUNG.K_ANZAHL')) ?>
    <b><?= count($by_ladungen) ?></b>
    <span class="sm-hilfe"><?= by_e(by_t('LADUNG.K_ANZAHL_H')) ?></span>
  </div>
  <div class="sm-kachel"><?= by_e(by_t('LADUNG.K_SUMME')) ?>
    <b><?= count($by_mit_kwh) ? by_e(number_format($by_summe, 1, ',', '.')) . ' kWh' : '&ndash;' ?></b>
    <span class="sm-hilfe"><?= sprintf(by_e(by_t('LADUNG.K_SUMME_H')), count($by_mit_kwh), count($by_ladungen)) ?></span>
  </div>
</div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= by_e(by_t('ALLG.FAHRZEUG')) ?></th><th><?= by_e(by_t('LADUNG.T_START')) ?></th>
    <th><?= by_e(by_t('LADUNG.T_ENDE')) ?></th><th><?= by_e(by_t('LADUNG.T_DAUER')) ?></th>
    <th><?= by_e(by_t('LADUNG.T_SOC')) ?></th><th><?= by_e(by_t('LADUNG.T_KWH')) ?></th>
    <th><?= by_e(by_t('LADUNG.T_KM')) ?></th></tr>
<?php foreach ($by_ladungen as $by_l) { ?>
<tr><td><?= by_e($by_l['fahrzeug']) ?></td>
    <td><?= by_e(date('d.m.Y H:i', $by_l['start'])) ?></td>
    <td><?= by_e(date('H:i', $by_l['ende'])) ?></td>
    <td><?= $by_l['dauer'] === null ? '&ndash;' : (int) $by_l['dauer'] . ' min' ?></td>
    <td><?= $by_l['soc_start'] === null ? '&ndash;'
        : by_e(rtrim(rtrim(number_format($by_l['soc_start'], 1, ',', ''), '0'), ',')) . ' &rarr; '
        . by_e(rtrim(rtrim(number_format($by_l['soc_ende'], 1, ',', ''), '0'), ',')) . ' %' ?></td>
    <td><?= $by_l['kwh'] === null ? '&ndash;' : by_e(number_format($by_l['kwh'], 2, ',', '')) ?></td>
    <td><?= $by_l['km'] === null ? '&ndash;' : by_e(number_format($by_l['km'], 0, ',', '.')) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= by_t('LADUNG.GENAUIGKEIT') ?></p>
<?php } ?>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $by_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= by_e(by_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= by_t('TEST.EINLEITUNG') ?></p>
<p><b><?= sprintf(by_e(by_t('TEST.BILANZ')), (int) $by_bilanz['ja'], (int) $by_bilanz['summe'],
    (int) $by_bilanz['hinweis']) ?></b></p>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= by_e(by_t('TEST.T_FRAGE')) ?></th><th><?= by_e(by_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach ($by_pruefungen as $by_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($by_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($by_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $by_z['frage'] ?></td><td><?= $by_z['antwort'] ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= by_t('TEST.LEGENDE_PUNKT') ?></p>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= by_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= by_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= by_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= by_e(by_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= by_e($by_basis) ?>?token=<?= by_e($by_token) ?>&amp;aktion=status&amp;fahrzeug=1" target="_blank"><?= by_e(by_t('TEST.K_STATUS')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= by_e($by_basis) ?>?token=<?= by_e($by_token) ?>&amp;aktion=position&amp;fahrzeug=1" target="_blank"><?= by_e(by_t('TEST.K_POSITION')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= by_e($by_basis) ?>?token=<?= by_e($by_token) ?>&amp;aktion=fahrzeuge" target="_blank"><?= by_e(by_t('TEST.K_FAHRZEUGE')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= by_e($by_basis) ?>?selftest=1&amp;token=<?= by_e($by_token) ?>" target="_blank"><?= by_e(by_t('TEST.K_SELFTEST')) ?></a>
</div>

<h3><?= by_e(by_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="selbsttest">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= by_e(by_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="endpunkt"><?= by_e(by_t('TEST.K_ENDPUNKT')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="felder"><?= by_e(by_t('TEST.K_FELDER')) ?></button>
  </form>
  <a data-role="none" class="sm-btn sm-b-technik" href="<?= by_e($by_basis) ?>?token=<?= by_e($by_token) ?>&amp;aktion=json" target="_blank"><?= by_e(by_t('TEST.K_JSON')) ?></a>
  <a data-role="none" class="sm-btn sm-b-technik" href="index.php?form=test&amp;rohdaten=1"><?= by_e(by_t('TEST.K_ROH')) ?></a>
</div>
<p class="sm-hilfe"><?= by_t('TEST.H_FELDER_ERKLAERUNG') ?></p>
<?php if ($by_ausgabe !== '') { ?>
<div class="sm-pre"><?= by_e($by_ausgabe) ?></div>
<?php } ?>

<?php
/* Die Rohdaten der Gegenstelle - mit den ECHTEN Feldnamen.
 *
 * Bis 0.9.0 versprach der Knopf "Rohdaten als JSON ansehen" genau das und
 * zeigte aktion=json, also das bereits umgesetzte Abbild mit den Namen
 * dieses Plugins. by_rohdaten() gab es, aufgerufen hat sie niemand.
 * Gefunden mit Werkzeuge/tote_helfer.py am 20.08.2026.
 *
 * Die Anzeige gibt es NUR hier im angemeldeten Bereich: bin/byd.py legt
 * rohdaten.json mit Rechten 0600 ab, weil Fahrzeugkennung und Standort
 * darin stehen. Ueber den tokengeschuetzten Endpunkt, der im
 * unangemeldeten Bereich liegt, geht sie deshalb nicht hinaus. */
if (isset($_GET['rohdaten'])) {
    $by_roh = by_rohdaten();
    echo '<h3>' . by_e(by_t('TEST.H_ROHDATEN')) . '</h3>';
    echo '<div class="sm-warnung">' . by_t('TEST.ROHDATEN_WARNUNG') . '</div>';
    if (!$by_roh) {
        echo '<div class="sm-hinweis">' . by_t('TEST.ROHDATEN_LEER') . '</div>';
    } else {
        echo '<p class="sm-hilfe">' . sprintf(by_t('TEST.ROHDATEN_STAND'),
            by_e(date('d.m.Y H:i:s', (int) (isset($by_roh['ts']) ? $by_roh['ts'] : 0)))) . '</p>';
        echo '<div class="sm-pre" style="max-height:520px;">'
           . by_e(json_encode(isset($by_roh['roh']) ? $by_roh['roh'] : $by_roh,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
           . '</div>';
    }
}
?>

<h3><?= by_e(by_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= by_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($by_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= by_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formular" value="test">
<input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="test_fahrzeug"><?= by_e(by_t('TEST.L_FAHRZEUG')) ?></label>
  <select data-role="none" class="sm-auswahl" id="test_fahrzeug" name="test_fahrzeug">
<?php if (!$by_fahrzeuge) { ?>
    <option value="1">1</option>
<?php } else { foreach ($by_fahrzeuge as $by_nr => $by_fz) { ?>
    <option value="<?= by_e($by_nr) ?>"><?= by_e($by_nr . ' - ' . trim($by_fz['marke'] . ' ' . $by_fz['modell'])) ?></option>
<?php } } ?>
  </select>
  <div class="sm-hilfe"><?= by_t('TEST.H_FAHRZEUG') ?></div>
</div>
<div class="sm-feld">
  <label for="test_temp"><?= by_e(by_t('TEST.L_TEMP')) ?></label>
  <input data-role="none" type="text" id="test_temp" name="test_temp" value="<?= (int) $by_cfg['temp_min'] ?>">
  <div class="sm-hilfe"><?= by_t('TEST.H_TEMP') ?></div>
</div>
<div class="sm-feld">
  <label for="test_minuten"><?= by_e(by_t('TEST.L_MINUTEN')) ?></label>
  <input data-role="none" type="number" id="test_minuten" name="test_minuten" value="" min="1" max="60">
  <div class="sm-hilfe"><?= by_t('TEST.H_MINUTEN') ?></div>
</div>
<div class="sm-feld">
  <label for="test_stufe"><?= by_e(by_t('TEST.L_STUFE')) ?></label>
  <select data-role="none" class="sm-auswahl" id="test_stufe" name="test_stufe">
    <option value="0">0 &ndash; <?= by_e(by_t('ALLG.AUS')) ?></option>
    <option value="1">1</option>
    <option value="2">2</option>
    <option value="3">3</option>
  </select>
  <div class="sm-hilfe"><?= by_t('TEST.H_STUFE') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="test_probe" value="1">
    <?= by_e(by_t('TEST.L_PROBE')) ?>
  </label>
  <div class="sm-hilfe"><?= by_t('TEST.H_PROBE') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abruf"><?= by_e(by_t('TEST.K_ABRUF')) ?></button>
<?php foreach (by_befehle() as $by_aktion => $by_eig) { if ($by_aktion === 'abruf') { continue; } ?>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="<?= by_e($by_aktion) ?>"><?= by_e(by_klartext(by_t($by_eig['bez']))) ?></button>
<?php } ?>
</div>
</form>

<div class="sm-warnung"><b><?= by_e(by_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= by_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $by_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= by_e(by_t('LOG.H_TITEL')) ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= by_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= by_e($by_p['log']) ?></span></p>
<?php if ($by_logzeilen) { ?>
<div class="sm-log"><?= by_e(implode("\n", $by_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= by_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= by_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formular" value="log">
    <input data-role="none" type="hidden" name="log_leeren" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= by_e($by_ftoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= by_e(by_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($by_tab) ?>);
})();
</script>
<?php
if ($by_rahmen) {
    LBWeb::lbfooter();
}
