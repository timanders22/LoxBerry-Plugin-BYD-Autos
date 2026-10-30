<?php
/**
 * BYD Autos - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit - ein einfaches == liesse sich
 * ueber die Antwortzeit Zeichen fuer Zeichen erraten.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesende Aktionen (aendern nichts):
 *   status   [&fahrzeug=N]   die Statuszeile fuer Loxone
 *   position [&fahrzeug=N]   Standort
 *   fahrzeuge                Liste der erkannten Fahrzeuge
 *   json                     vollstaendiges Abbild als JSON (Fehlersuche)
 *
 * Ausloesende Aktionen (verlangen ein Token; die schaltenden zusaetzlich den
 * Haken im Reiter Einstellungen):
 *   abruf                    sofortiger Abruf statt Warten auf den Takt
 *   verriegeln / entriegeln
 *   klima_start &temp=<Grad> [&minuten=<n>]   klima_stop   klima_plan
 *   sitzklima &stufe=<n>     batterieheizung &stufe=<n>
 *   suchen  blinken  fenster_zu
 * Eingreifende Aktionen brauchen seit 0.9.26 (Welle Bild 17) ZUSAETZLICH den zweiten Haken
 * 'Eingreifende Befehle zulassen' (gefahr_ein, ab Werk aus), sonst 403 SET;OK=0;GRUND=EINGRIFF_GESPERRT:
 *   verriegeln / entriegeln   suchen   blinken
 *
 * Gleichwert-Unterdrueckung (X-7, B-Nachzug 01.10.2026, Entscheidung Nr. 19):
 * derselbe Sollwert fuer dasselbe Fahrzeug innerhalb von 60 s geht nicht noch
 * einmal hinaus - HTTP 200, SET;OK=1;AKTION=..;UNVERAENDERT=1;SEIT_S=n, nichts
 * eingereiht. Gilt fuer klima_start/klima_stop (samt Temperatur und Laufzeit),
 * klima_plan, verriegeln/entriegeln, sitzklima, batterieheizung und
 * fenster_zu; nicht fuer abruf, suchen und blinken. Ein anderer Wert geht
 * sofort hinaus (kein 429); die Stundengrenze des Dienstes bleibt. Laesst sich
 * der Merker nicht fuehren: HTTP 503, GRUND=BREMSE_MERKER.
 *
 * Und einer, der nichts tut:
 *   ?selftest=1&token=<TOKEN>
 *
 * Gruppe 42 (0.9.29, Hausform): der Endpunkt haengt am Haken endpunkt_ein (Reiter MQTT). Neue
 * Anlagen haben ihn AUS (Werte ueber das MQTT-Gateway V2, Befehle ueber die Befehlsbruecke);
 * beim Update bleibt er an (postinstall.sh). Aus: jeder Aufruf nach der Tokenpruefung antwortet
 * 403 FEHLER;OK=0;GRUND=ENDPUNKT_AUS - nur ?selftest=1 bleibt offen. Die schaltenden Befehle
 * prueft by_befehl_annehmen() in by_lib.php - dieselbe Funktion wie fuer die Bruecke.
 *
 * Der Endpunkt spricht NIE selbst mit der BYD-Schnittstelle. Lesende Aktionen
 * beantwortet er aus dem Zwischenspeicher, ausloesende legt er in einer
 * Warteschlange ab, die der Dienst abarbeitet. Deshalb antwortet er dem
 * Miniserver in Millisekunden statt in Sekunden.
 *
 * Ein Strich als Wert bedeutet: dieser Wert liegt nicht vor. Es wird bewusst
 * keine 0 gesendet - eine 0 waere eine stille Falschaussage. Loxone behaelt
 * dann den letzten gueltigen Wert; genau das ist bei einem fehlenden Messwert
 * richtig.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* display_errors ausdruecklich AUS, und das ist kein Widerspruch zur Hausregel
 * "ein Absturz darf kein leerer HTTP 500 sein".
 *
 * Steht die Anzeige auf der Anlage an, landet eine PHP-Warnung im RUMPF - also
 * vor der Statuszeile, die Loxone mit einer Befehlserkennung liest. Der Wert
 * kaeme zwar trotzdem an (Loxone sucht das Muster in der ganzen Antwort), aber
 * eine Warnung, die ein Semikolon oder ein Gleichheitszeichen enthaelt, schoebe
 * zusaetzliche Paare in die Zeile. Der Absturz selbst wird weiter unten
 * abgefangen und als lesbare Zeile beantwortet; alles Uebrige gehoert ins
 * Fehlerprotokoll des Webservers, nicht in die Antwort. */
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (!is_file(__DIR__ . '/by_lib.php')) {
    /* Lesbar antworten statt zu schweigen: der Miniserver ist der einzige
     * Aufrufer und liest kein Apache-Protokoll. Ein leerer HTTP 500 sieht in
     * Loxone aus wie "kein Wert", und der virtuelle Eingang behaelt seinen
     * letzten Stand - in der App wirkt alles normal.
     *
     * BERICHTIGT 03.09.2026: der gesuchte PFAD stand bis 0.9.5 in der
     * HTTP-Antwort. Dieser Zweig liegt VOR jeder Tokenpruefung - die Auskunft
     * ging also an jeden, der die Adresse kennt. Sie gehoert ins Protokoll des
     * Webservers, wo der Betreiber sie findet und sonst niemand; in der Antwort
     * steht nur die Kennung. */
    @error_log('BYD Autos: by_lib.php nicht gefunden. Gesucht in: '
               . __DIR__ . '/by_lib.php');
    http_response_code(500);
    echo "BYD;OK=0;GRUND=BIBLIOTHEK_FEHLT\n";
    exit;
}
require_once __DIR__ . '/by_lib.php';

/**
 * Antwort abschliessen. Der Text steht in einer Zeile, damit Loxone ihn mit
 * einer Befehlserkennung lesen kann.
 */
function by_ende($code, $zeile, $zusatz = '')
{
    if ($code !== 200) {
        http_response_code($code);
    }
    /* Hier wird NUR der Zeilenumbruch entfernt, und das ist Absicht: $zeile IST
     * die Statuszeile, und die trennt ihre Paare selbst mit ";". Wer das
     * Semikolon hier herausnaehme, zerstoerte jede Antwort.
     *
     * Gesaeubert gehoert der EINGESETZTE Text - dafuer gibt es by_sicher(). */
    echo str_replace(array("\r", "\n"), ' ', $zeile) . "\n";
    if ($zusatz !== '') {
        echo $zusatz . "\n";
    }
    exit;
}

/* Gruppe 42 (0.9.29): by_sicher() steht jetzt in by_lib.php - die Antwortzeilen der schaltenden Befehle
 * baut by_befehl_annehmen() fuer Endpunkt und Befehlsbruecke (ein Ort, wortgleich verlegt). */

/* ---------------- Konfiguration lesen, NICHT anlegen ----------------
 *
 * by_config(false): ein Aufruf ohne Ausweis darf nichts anlegen - auch nichts
 * Harmloses. In einem Schwesterplugin hinterliess ein einziger, korrekt mit
 * 403 abgewiesener Aufruf eine frisch erzeugte Konfiguration samt Token und
 * Zweitschrift; gemessen mit leerem Konfigurationsordner.
 *
 * BERICHTIGT 03.09.2026: hier stand "by_config(false) und by_token(false)".
 * by_token() wird in dieser Datei NIE gerufen - das Soll-Token kommt aus der
 * gelesenen Konfiguration. Folgenlos, aber falsch: ein Kommentar, der einen
 * Aufruf nennt, den es nicht gibt, schickt den naechsten Leser suchen.
 */
$by_cfg = by_config(false);
$by_p = by_paths();
$by_soll = (string) $by_cfg['aktionstoken'];

/* ---------------- Selbsttest ----------------
 *
 * WARUM ES DEN GIBT: Ein Token muss sich pruefen lassen, ohne dass etwas
 * passiert. Ohne diesen Zweig gibt es nur zwei schlechte Moeglichkeiten -
 * entweder man schaltet wirklich (dann faehrt die Klimaanlage an), oder man
 * erfaehrt nie, ob die Adresse im Miniserver noch stimmt. Beides ist
 * unbrauchbar, wenn man eine Anlage pruefen will.
 *
 * Der Zweig steht VOR jeder Wirkung, aber die Tokenpruefung greift trotzdem:
 * ein falsches Token bekommt dieselbe Abweisung wie sonst auch. Der Selbsttest
 * darf keine Abkuerzung an der Sicherheit vorbei sein. Kein Geraetekontakt,
 * kein Schreibzugriff.
 */
/* Der Kopf dieser Datei verspricht ?selftest=1 - also wird auch das gemessen
 * und nicht bloss die Anwesenheit des Parameters. isset() nahm bis 0.9.5 auch
 * "selftest=0" und "selftest=" an, und ein Anwender, der ihn ausschalten will,
 * indem er 0 schreibt, bekam den Selbsttest trotzdem. */
if (isset($_GET['selftest']) && (string) $_GET['selftest'] === '1') {
    $by_ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
    if ($by_soll === '') {
        by_ende(403, 'SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET',
            'Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.');
    }
    if (!hash_equals($by_soll, $by_ist)) {
        by_ende(403, 'SELFTEST;OK=0;ERR=TOKEN');
    }
    by_ende(200, sprintf('SELFTEST;OK=1;TOKEN=OK;STEUERUNG=%s;FAHRZEUGE=%d;ALTER=%d',
        empty($by_cfg['steuerung_ein']) ? 'AUS' : 'EIN',
        count(by_fahrzeuge()), by_alter()));
}

/* ---------------- Token ----------------
 *
 * Gelesen wird ausschliesslich aus $_GET, nie aus $_REQUEST: was in $_REQUEST
 * steht, haengt von request_order ab, und die Vorgabe schliesst Cookies ein -
 * ein Cookie namens "token" haette die Pruefung gefuettert.
 */
$by_ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
if ($by_soll === '') {
    by_ende(403, 'BYD;OK=0;GRUND=KEIN_TOKEN_GESETZT',
        'Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.');
}
if (!hash_equals($by_soll, $by_ist)) {
    by_ende(403, 'BYD;OK=0;GRUND=TOKEN');
}
/* Gruppe 42 (G3): endpunkt_ein = 0 sperrt den Endpunkt (lesend und schaltend) - nach dem Token, damit
 * ein falsches Token weiter TOKEN heisst. Die Befehlsbruecke haengt nicht daran. */
if (empty($by_cfg['endpunkt_ein'])) {
    by_ende(403, 'FEHLER;OK=0;GRUND=ENDPUNKT_AUS',
        'Der HTTP-Endpunkt ist ausgeschaltet (Reiter MQTT, Haken "HTTP-Endpunkt fuer Loxone"). '
        . 'Werte kommen ueber das MQTT-Gateway V2, Befehle ueber die Befehlsbruecke.');
}

/* ---------------- Aktion (Weissliste) ---------------- */
$by_lesend = array('status', 'position', 'fahrzeuge', 'json');
$by_ausloesend = array_keys(by_befehle());
$by_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($by_aktion, array_merge($by_lesend, $by_ausloesend), true)) {
    by_ende(400, 'BYD;OK=0;GRUND=UNBEKANNTE_AKTION',
        'Erlaubt sind: ' . implode(', ', array_merge($by_lesend, $by_ausloesend)));
}

/* ---------------- Parameter pruefen ----------------
 *
 * Was nicht ins Muster passt, wird abgewiesen und gemeldet. Nie Zeichen
 * entfernen, nie zurechtbiegen - ein still veraenderter Wert fuehrt zu einem
 * Fahrzeug, das etwas anderes tut, als die Adresse sagt.
 *
 * Zuerst is_string: "?fahrzeug[]=x" macht aus dem Parameter ein Feld, und ein
 * trim() darauf ist unter PHP 8 ein TypeError - die Anfrage endete dann mit
 * HTTP 500 und leerem Rumpf, der Miniserver bekaeme also gar nichts zu lesen.
 */
function by_param($name, $muster, $vorgabe = '')
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $vorgabe;
    }
    if (!is_string($_GET[$name])) {
        by_ende(400, 'BYD;OK=0;GRUND=PARAMETER',
            'Der Parameter ' . $name . ' ist kein einzelner Wert.');
    }
    $w = (string) $_GET[$name];
    if (strlen($w) > 32 || !preg_match($muster, $w)) {
        by_ende(400, 'BYD;OK=0;GRUND=PARAMETER',
            'Der Wert von ' . $name . ' passt nicht ins erlaubte Muster.');
    }
    return $w;
}

// Die laufende Nummer oder eine Fahrgestellnummer (17 Zeichen).
$by_fahrzeug = by_param('fahrzeug', '/^([0-9]{1,2}|[A-Za-z0-9]{17})$/', '1');
$by_temp     = by_param('temp', '/^[0-9]{1,2}([.,][05])?$/', '');
$by_stufe    = by_param('stufe', '/^[0-9]{1,2}$/', '');
$by_minuten  = by_param('minuten', '/^[0-9]{1,2}$/', '');

/* ---------------- Zwischenspeicher ---------------- */
$by_lox = by_loxone();
$by_alter = by_alter();
$by_alle = by_fahrzeuge();
/* C6 (Entscheidung 4 vom 29.09.2026): OK=0, sobald das Abbild aelter ist als
 * das Dreifache des eingestellten Abruftakts. Bis 0.9.19 blieb OK=1 stehen,
 * gleich wie alt (gemessen: ALTER=4000 bei Takt 300, OK=1). ALTER bleibt
 * unveraendert daneben. Der Takt wird wie im Dienst auf 120 bis 3600 s
 * begrenzt (config() in bin/byd.py). */
$by_takt = max(120, min(3600, (int) $by_cfg['intervall']));
$by_frisch = ($by_alter >= 0 && $by_alter <= 3 * $by_takt);

/** Findet das Fahrzeug zur laufenden Nummer oder zur Fahrgestellnummer. */
function by_waehlen($alle, $schluessel)
{
    if (isset($alle[$schluessel])) {
        return $alle[$schluessel];
    }
    foreach ($alle as $f) {
        if (isset($f['vin']) && $f['vin'] !== ''
            && strcasecmp((string) $f['vin'], (string) $schluessel) === 0) {
            return $f;
        }
    }
    return null;
}

/* ================= Lesende Aktionen ================= */

if ($by_aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok'                  => isset($by_lox['ok']) ? (int) $by_lox['ok'] : 0,
        'alter'               => $by_alter,
        'fahrzeuge'           => $by_alle,
        'fehler'              => isset($by_lox['fehler']) ? $by_lox['fehler'] : '',
        // Was niemand gemessen hat, wird gekennzeichnet - auch ein Feld.
        'aus_der_dokumentation' => by_doku_felder(),
        /* Laden-1: der zuletzt gerechnete Stand der Ladeempfehlung nach
         * Strompreis - null, wenn die Einstellung aus ist. Dieselben Werte
         * wie die MQTT-Themen lade_empfehlung und lade_empfehlung_grund. */
        'lade_empfehlung'     => empty($by_cfg['preisempf_ein']) ? null
                                 : (by_preisempfehlung_stand() ?: null),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Namen der Felder, deren Herkunft eine offene Quelle ist, keine Messung. */
function by_doku_felder()
{
    $aus = array();
    foreach (by_felder() as $name => $eig) {
        if ($eig['quelle'] === 'doku') {
            $aus[] = $name;
        }
    }
    return $aus;
}

/* C7: ohne Daten - noch kein Abruf, oder das Abbild fuehrt kein Fahrzeug -
 * antwortet der Endpunkt mit HTTP 503 und nennt den Grund (Regeln/07, "Faellt
 * die Quelle ganz aus ..., auch vor dem ersten Abruf"). Bis 0.9.19 kam HTTP 200
 * mit FAHRZEUG_UNBEKANNT; das sieht in Loxone aus wie eine Antwort. Loxone
 * behaelt bei 503 die letzten Werte und zeigt den Ausfall. FAHRZEUG_UNBEKANNT
 * bleibt fuer den Fall, dass es Fahrzeuge gibt, die gewaehlte Nummer aber nicht. */
if (in_array($by_aktion, array('status', 'position', 'fahrzeuge'), true) && count($by_alle) === 0) {
    by_ende(503, sprintf('%s;OK=0;GRUND=KEINE_DATEN;N=0;ALTER=%d',
        strtoupper($by_aktion), $by_alter));
}

if ($by_aktion === 'fahrzeuge') {
    echo sprintf("FAHRZEUGE;OK=%d;N=%d;ALTER=%d\n",
        (!empty($by_lox['ok']) && $by_frisch) ? 1 : 0, count($by_alle), $by_alter);
    foreach ($by_alle as $by_nr => $by_f) {
        echo $by_nr . ';'
           . (isset($by_f['marke']) ? $by_f['marke'] : '') . ' '
           . (isset($by_f['modell']) ? $by_f['modell'] : '') . ';'
           . (isset($by_f['kennzeichen']) ? $by_f['kennzeichen'] : '') . ';'
           . (isset($by_f['vin']) ? $by_f['vin'] : '') . ';'
           . 'OK=' . (isset($by_f['ok']) ? (int) $by_f['ok'] : 0) . ';'
           . 'OhneTreffer=' . (isset($by_f['offen']) && is_array($by_f['offen'])
                               ? count($by_f['offen']) : 0) . "\n";
    }
    exit;
}

$by_f = by_waehlen($by_alle, $by_fahrzeug);

if (in_array($by_aktion, array('status', 'position'), true) && $by_f === null) {
    by_ende(200, sprintf('%s;OK=0;GRUND=FAHRZEUG_UNBEKANNT;N=%d;ALTER=%d',
        strtoupper($by_aktion), count($by_alle), $by_alter));
}

/* OK ist je Fahrzeug, nicht global.
 *
 * Ein globales ok, das schon dann 1 ist, wenn EIN Fahrzeug in Ordnung war,
 * meldet fuer das ausgefallene Fahrzeug OK=1 mit veralteten Werten. Das ist
 * eine stille Falschaussage, und sie ist ohne Blick in den Code nicht zu
 * erkennen. Verlangt werden deshalb drei Dinge zugleich: der Lauf war in
 * Ordnung, das Abbild ist hoechstens dreimal so alt wie der Abruftakt (C6),
 * und DIESES Fahrzeug hat Werte gebracht.
 */
$by_ok = (!empty($by_lox['ok']) && $by_frisch
          && is_array($by_f) && !empty($by_f['ok'])) ? 1 : 0;

if ($by_aktion === 'status') {
    echo by_statuszeile($by_f, $by_alter, $by_ok) . "\n";
    exit;
}

if ($by_aktion === 'position') {
    echo sprintf("POSITION;OK=%d;BREITE=%s;LAENGE=%s;ALTER=%d\n", $by_ok,
        by_wert_aus(isset($by_f['BREITE']) ? $by_f['BREITE'] : null),
        by_wert_aus(isset($by_f['LAENGE']) ? $by_f['LAENGE'] : null), $by_alter);
    exit;
}

/* ================= Ausloesende Aktionen ================= */

/* Gruppe 42 (0.9.29, G2): die Pruefungen, die bis 0.9.28 hier inline standen (Steuerungshaken,
 * zweiter Haken gefahr_ein, Zusatzwerte, X-7-Gleichwert-Unterdrueckung, Einreihen), stehen jetzt in
 * by_befehl_annehmen() - in DERSELBEN Reihenfolge und mit denselben Antwortzeilen. Die Befehlsbruecke
 * ruft dieselbe Funktion mit der Quelle 'mqtt'. Rueckgabe 2 heisst "eingereiht, Ergebnis unbekannt"
 * (HTTP 202), 0 abgelehnt (400), 1 erledigt oder unveraendert (200). */
$by_erg = by_befehl_annehmen($by_aktion, array('temp' => $by_temp, 'stufe' => $by_stufe,
    'minuten' => $by_minuten), $by_fahrzeug, 'http');
by_ende((int) $by_erg['http'], $by_erg['zeilen'][0], (string) $by_erg['zeilen'][1]);
