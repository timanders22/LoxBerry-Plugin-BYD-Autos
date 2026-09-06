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
 *
 * Und einer, der nichts tut:
 *   ?selftest=1&token=<TOKEN>
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

/**
 * Einen fremden Text so saeubern, dass er in EIN Feld der Statuszeile passt.
 *
 * Die Zeile besteht aus Paaren NAME=WERT, getrennt durch ";". Ein eingesetzter
 * Text, der selbst ein Semikolon oder einen Zeilenumbruch traegt, schiebt
 * zusaetzliche Paare hinein - Loxone liest dann nicht "kein Wert", sondern
 * einen FALSCHEN. Das "=" bleibt stehen: es macht kein neues Paar auf, solange
 * kein Semikolon davor steht, und in Fehlermeldungen fremder Bibliotheken
 * steht es haeufig.
 *
 * ERGAENZT 03.09.2026: bis 0.9.5 saeuberte genau EINE der beiden
 * Einsetzstellen. Die andere - die Absturzmeldung - nahm getMessage() roh.
 */
function by_sicher($text)
{
    return str_replace(array("\r", "\n", ';'), ' ', (string) $text);
}

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
    $by_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
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
$by_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($by_soll === '') {
    by_ende(403, 'BYD;OK=0;GRUND=KEIN_TOKEN_GESETZT',
        'Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.');
}
if (!hash_equals($by_soll, $by_ist)) {
    by_ende(403, 'BYD;OK=0;GRUND=TOKEN');
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

if ($by_aktion === 'fahrzeuge') {
    echo sprintf("FAHRZEUGE;OK=%d;N=%d;ALTER=%d\n",
        (!empty($by_lox['ok']) && $by_alter >= 0) ? 1 : 0, count($by_alle), $by_alter);
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
 * Ordnung, das Abbild hat ein Alter, und DIESES Fahrzeug hat Werte gebracht.
 */
$by_ok = (!empty($by_lox['ok']) && $by_alter >= 0
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

/* Ein Aufruf, der etwas ausloest, verlangt ein Token - das ist oben schon
 * geprueft. Zusaetzlich verlangt jeder SCHALTENDE Befehl den Haken im Reiter
 * Einstellungen. "abruf" schaltet nichts am Fahrzeug und braucht ihn nicht,
 * ist aber trotzdem tokenpflichtig: er loest einen Netzabruf aus. */
if (by_befehl_schaltet($by_aktion) && empty($by_cfg['steuerung_ein'])) {
    by_ende(403, 'SET;OK=0;GRUND=STEUERUNG_AUS',
        'Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken '
        . '"Schreibende Befehle zulassen".');
}

$by_befehl = array('aktion' => $by_aktion, 'fahrzeug' => $by_fahrzeug);
$by_eig = by_befehle()[$by_aktion];

if ($by_eig['zusatz'] === 'temp') {
    if ($by_temp === '') {
        by_ende(400, 'SET;OK=0;GRUND=TEMP_FEHLT',
            'Der Parameter temp fehlt (Zieltemperatur in Grad Celsius).');
    }
    $by_befehl['temp'] = str_replace(',', '.', $by_temp);
    if ($by_minuten !== '') {
        $by_befehl['minuten'] = (int) $by_minuten;
    }
} elseif ($by_eig['zusatz'] === 'stufe') {
    if ($by_stufe === '') {
        by_ende(400, 'SET;OK=0;GRUND=STUFE_FEHLT',
            'Der Parameter stufe fehlt. 0 schaltet aus, ein hoeherer Wert ein - welche '
            . 'Stufen dieses Fahrzeug kennt, sagt der Reiter Test.');
    }
    $by_befehl['stufe'] = (int) $by_stufe;
}

/* Der eigentliche Vorgang laeuft in einem Netz: stirbt etwas darin - eine
 * fehlende Erweiterung, ein TypeError, was auch immer -, bekommt der
 * Miniserver sonst null Byte und HTTP 500. Kein Protokolleintrag, keine
 * Meldung, nichts zum Suchen. Throwable faengt seit PHP 7 auch Error. */
try {
    list($by_erg, $by_meldung) = by_befehl_absetzen($by_befehl);
} catch (Throwable $by_t) {
    by_log('Endpunkt: der Befehl ' . $by_aktion . ' ist abgestuerzt: '
         . $by_t->getMessage(), 'ERROR');
    by_ende(500, sprintf('SET;OK=0;AKTION=%s;ERR=%s (%s:%d)', $by_aktion,
        by_sicher($by_t->getMessage()), basename($by_t->getFile()),
        $by_t->getLine()));
}

/* Rueckgabe 2 heisst "eingereiht, Ergebnis unbekannt" - das ist kein Fehler
 * und kein Erfolg. HTTP 202 sagt genau das; ein 200 wuerde einen Erfolg
 * behaupten, den niemand geprueft hat, ein 500 einen Fehler, den es nicht
 * gibt. */
$by_code = 200;
if ($by_erg === 0) {
    $by_code = 400;
} elseif ($by_erg === 2) {
    $by_code = 202;
}
by_ende($by_code, sprintf('SET;OK=%d;AKTION=%s;MELDUNG=%s', $by_erg, $by_aktion,
    by_sicher($by_meldung)));
