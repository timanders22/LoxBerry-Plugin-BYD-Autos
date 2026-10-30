<?php
/**
 * Befehlsbruecke - gemeinsames Modul fuer Befehle aus Loxone ueber MQTT.
 *
 * Stammfassung: Werkzeuge/gemeinsam/befehlsbruecke.php. In jeder Linie byte-gleich unter
 * bin/befehlsbruecke.php (Abgleich: Werkzeuge/gemeinsam_pruefen.py). Linieneigen ist nur
 * bin/befehle_zuordnung.php (Tabelle der Befehle, Uebergabe an die Funktion der Linie).
 * Grundlage: Musterprojekt/entwurf_gruppe1_gemeinsam.txt (E1-E17), Bauvorschrift 18.10.2026.
 *
 * Weg eines Befehls:
 *   Loxone (VirtualOut, UDP) -> MQTT-Gateway 11884 "publish <p>/befehl/<name> <wert>" -> Broker
 *   -> mosquitto_sub (Kind dieses Dienstes, -V mqttv5 --retain-as-published -F '%r %t %x')
 *   -> Pruefung -> tun() der Zuordnung
 *   -> dieselbe Funktion wie der HTTP-Endpunkt der Linie (Quelle 'mqtt')
 *   -> Quittung <p>/befehl/<name>/quittung ueber mosquitto_pub, NIE retained.
 * Pruefreihenfolge (Regeln/07 Abschnitt 3a, Entwurf 2.2):
 *   retained -> verwerfen ohne Quittung | eigene Quittung -> still uebergehen | Haken aus ->
 *   "abgelehnt: ausgeschaltet", Dienst endet | unbekannter Name -> ablehnen (hoechstens 10
 *   Quittungen je Minute) | Wert nicht in Art und Bereich -> ablehnen | Zusammenlegen: je Name
 *   gewinnt der neueste Wert im Lesepuffer (schalter/ganz/zahl/auswahl) | Sperrminute: gleicher
 *   Name und gleicher normierter Wert binnen sperre_s (Vorgabe 60) -> ablehnen | eingreifend ohne
 *   den zweiten Haken der Linie -> ablehnen | tun().
 *
 * Aufrufformen:
 *   php befehlsbruecke.php                Dienst (gestartet NUR ueber befehlsbruecke.sh)
 *   php befehlsbruecke.php --selbsttest   Rechenkern ohne Broker: "N Faelle geprueft, M Fehlschlaege."
 *   php befehlsbruecke.php --pruefen      Einrichtungspruefung [OK]/[FEHL]/[INFO], Rueckgabe 0/1
 *   php befehlsbruecke.php --soll         Rueckgabe 0 = soll laufen, 1 = soll nicht, 3 = defekt
 *   php befehlsbruecke.php --liste        JSON: Haken und Befehle (Reiter, Vorlage, Musterprojekt)
 *   php befehlsbruecke.php --vorlage [h]  Loxone-Vorlage (VirtualOut) aus der Tabelle; h = LoxBerry
 *   php befehlsbruecke.php --waechter     ein Waechterlauf (fuer Linien ohne PHP-Minutentakt)
 *   unbekannter Schalter -> Satz auf STDERR, Rueckgabe 2.
 * Bibliotheksform: define('BB_NUR_LADEN', true); require .../befehlsbruecke.php; dann
 *   bb_waechter() im Minutentakt der Linie, bb_liste(), bb_vorlage(), bb_zustand_lesen().
 *
 * Bauweise:
 * - KEINE flock-Sperre in diesem Prozess: mosquitto_sub erbte sie (Gedaechtnis "Sperre vererbt sich
 *   an Kinder"). Die Sperre steht in befehlsbruecke.sh, der Dienst prueft beim Start nur
 *   argumentweise auf einen Zwilling (Rueckgabe 3).
 * - Kennwort nur in der Optionsdatei data/plugins/<ordner>/befehle/mosquitto/mosquitto_sub|_pub
 *   (Datei 0600, Ordner 0700), Aufruf mit XDG_CONFIG_HOME, nie -P in einer Befehlszeile.
 * - Kindprozesse ueber proc_open mit Feld (ohne Schale); Optionsordner, Benutzer und Rechte
 *   werden nie ausgegeben, nur der Benutzername in der Anmeldemeldung.
 * - Ereignisgetrieben (stream_select), kein Takt; zustand.json spaetestens alle 30 s.
 * Laeuft auf: LoxBerry (PHP 7.4, 8.4, 8.5); --selbsttest, --liste, --vorlage auch unter Windows.
 *
 * 1.0.1 (Pflegewelle 22, 25.10.2026), rein ergaenzend, Tabellenfassung bleibt 1:
 * - Wurzel geprueft (B1): nur wo config/plugins, data/plugins und config/system/general.json liegen
 *   (Regeln/06), sonst keine Pfade und nichts angelegt; Ordnernamen aus LBPPLUGINDIR gegen die
 *   Ausschlussliste ('', '.', '..', '/', html, htmlauth, bin, plugins, webfrontend).
 * - Ordner befehle/ 0700 statt 0750 (am Geraet 0750 gemessen, BLE 1.3.28 / WiFi 3.2.15).
 * - Markenpruefung mit leerer oder ungueltiger Uhr: Marke gilt (geschlossen ausfallen, B3).
 *   Eine Marke aus der Zukunft gilt weiter nicht (Hausregel Regeln/06).
 *
 * 1.0.2 (Gruppe 36, Fensterbilanz 0.12.20, 28.10.2026), nur Zeilenenden, Tabellenfassung bleibt 1:
 * - bb_vorlage() verbindet mit CRLF wie die _Test.xml-Ausfuhren der Loxone Config (Regeln/07);
 *   Inhalt sonst byte-gleich. Selbsttest: Schlussprobe auf CRLF, Zahl der Faelle unveraendert.
 */

define('BB_FASSUNG', '1.0.2');
define('BB_ZUORDNUNG_FASSUNG', 1);
define('BB_SPERRE_VORGABE', 60);
define('BB_SPERRE_MAX', 3600);
define('BB_NUTZLAST_MAX', 4000);
define('BB_UNBEKANNT_JE_MINUTE', 10);
define('BB_ZUSTAND_TAKT', 30);
define('BB_WACHE_GRENZE', 180);
define('BB_WACHE_BREMSE', 600);
define('BB_MARKE_GILT', 3600);
define('BB_EBENEN_MAX', 8);
define('BB_ARTEN', 'puls schalter ganz zahl auswahl text');
define('BB_ZUSAMMENLEGEN', 'schalter ganz zahl auswahl');

// ------------------------------------------------------------------------------------------
// Pfade, Dateien, Protokoll
// ------------------------------------------------------------------------------------------

/** Pfade aus dem eigenen Ablageort (bin/plugins/<ordner>), Rueckfall LBHOMEDIR/LBPPLUGINDIR. */
function bb_pfade()
{
    static $p = null;
    if ($p !== null) { return $p; }
    $p = bb_pfade_aus(__DIR__, (string) getenv('LBHOMEDIR'), (string) getenv('LBPPLUGINDIR'));
    return $p;
}

/** Ordnername aus LBPPLUGINDIR, der nie ein Pluginordner ist (1.0.1). */
function bb_ordner_ungueltig($ordner)
{
    return in_array((string) $ordner, array('', '.', '..', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true);
}

/** Wurzel ist, was config/plugins UND data/plugins UND config/system/general.json traegt (Regeln/06). */
function bb_wurzel_ok($base)
{
    return $base !== '' && is_dir($base . '/config/plugins') && is_dir($base . '/data/plugins')
        && is_file($base . '/config/system/general.json');
}

/** Pfade rechnen (ohne Zwischenspeicher, fuer den Selbsttest); array() = keine LoxBerry-Lage. */
function bb_pfade_aus($bin, $lbhome, $lbpdir)
{
    if (basename(dirname($bin)) === 'plugins' && basename(dirname(dirname($bin))) === 'bin') {
        $base = dirname(dirname(dirname($bin)));
        $ordner = basename($bin);
    } elseif ($lbhome !== '' && $lbpdir !== '') {
        $base = rtrim($lbhome, '/');
        $ordner = basename($lbpdir);
        if (bb_ordner_ungueltig($ordner) || bb_ordner_ungueltig($lbpdir)) { return array(); }
    } else {
        return array();
    }
    if (!bb_wurzel_ok($base)) { return array(); }
    $daten = $base . '/data/plugins/' . $ordner;
    $p = array(
        'base' => $base, 'ordner' => $ordner, 'bin' => $bin, 'sh' => $bin . '/befehlsbruecke.sh',
        'daten' => $daten . '/befehle', 'optionen' => $daten . '/befehle/mosquitto',
        'zustand' => $daten . '/befehle/zustand.json', 'waechter' => $daten . '/befehle/waechter.json',
        'marke' => $daten . '.upgrade_laeuft', 'log' => $base . '/log/plugins/' . $ordner,
        'general' => $base . '/config/system/general.json',
    );
    return $p;
}

/** Der eigene Skriptpfad, so wie befehlsbruecke.sh ihn als argv[1] uebergibt (physisch). */
function bb_skript()
{
    $r = realpath(__FILE__);
    return $r === false ? __FILE__ : $r;
}

/** Atomar schreiben: Nebendatei mit PID, Rechte vor Inhalt, rename (Regeln/03). */
function bb_atomar($datei, $inhalt, $rechte)
{
    $tmp = $datei . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    @chmod($tmp, $rechte);
    @ftruncate($fh, 0);
    $n = @fwrite($fh, $inhalt);
    @fclose($fh);
    if ($n !== strlen($inhalt) || !@rename($tmp, $datei)) { @unlink($tmp); return false; }
    return true;
}

function bb_json_lesen($datei)
{
    if (!is_file($datei)) { return null; }
    $j = json_decode((string) @file_get_contents($datei), true);
    return is_array($j) ? $j : null;
}

function bb_json_schreiben($datei, array $daten)
{
    $js = json_encode($daten);
    if ($js === false) { return false; }
    return bb_atomar($datei, $js . "\n", 0640);
}

/** Protokoll ueber die Funktion der Linie; faellt sie aus, nach STDERR. Nie ein Kennwort. */
function bb_log($stufe, $text)
{
    $z = isset($GLOBALS['bb_zuordnung']) ? $GLOBALS['bb_zuordnung'] : null;
    if (is_array($z) && isset($z['protokoll']) && is_callable($z['protokoll'])) {
        try {
            call_user_func($z['protokoll'], $stufe, 'Befehlsbruecke: ' . $text);
            return;
        } catch (Throwable $t) {
            // weiter nach STDERR
        }
    }
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ' . $stufe . ' Befehlsbruecke: ' . $text . "\n");
}

/** Dieselbe Meldung hoechstens einmal je $sek Sekunden (Dauerzustand, Regeln/03). */
function bb_log_gebremst(array &$st, $schluessel, $stufe, $text, $sek)
{
    $jetzt = time();
    if (isset($st['gebremst'][$schluessel]) && $jetzt - $st['gebremst'][$schluessel] < $sek) { return; }
    $st['gebremst'][$schluessel] = $jetzt;
    bb_log($stufe, $text);
}

// ------------------------------------------------------------------------------------------
// Zuordnungstabelle laden und pruefen
// ------------------------------------------------------------------------------------------

function bb_einbinden($datei)
{
    return include $datei;
}

/** Rueckgabe array(Tabelle|null, Fehlertext). Ein Syntaxfehler kommt als ParseError. */
function bb_zuordnung_laden($datei = null)
{
    if ($datei === null) { $datei = __DIR__ . '/befehle_zuordnung.php'; }
    if (!is_file($datei)) { return array(null, 'Zuordnung fehlt: ' . basename($datei)); }
    try {
        $z = bb_einbinden($datei);
    } catch (Throwable $t) {
        return array(null, 'Zuordnung nicht ladbar: ' . get_class($t) . ': ' . $t->getMessage()
            . ' (Zeile ' . $t->getLine() . ')');
    }
    if (!is_array($z)) { return array(null, 'Zuordnung gibt kein Feld zurueck'); }
    return array($z, '');
}

function bb_teil_name($ebene)
{
    return preg_match('/^\{([a-z_][a-z0-9_]{0,19})\}\z/', $ebene, $m) ? $m[1] : null;
}

function bb_ebene_gueltig($ebene)
{
    return (bool) preg_match('/^[a-z0-9_]{1,40}\z/', $ebene);
}

/** Ein Befehlsname aus dem Thema: Ebenen aus [a-z0-9_]{1,40}, hoechstens 8. */
function bb_name_gueltig($name)
{
    if (!is_string($name) || $name === '') { return false; }
    $e = explode('/', $name);
    if (count($e) > BB_EBENEN_MAX) { return false; }
    foreach ($e as $x) {
        if (!bb_ebene_gueltig($x)) { return false; }
    }
    return true;
}

function bb_praefix_gueltig($p)
{
    return is_string($p) && strlen($p) <= 100
        && (bool) preg_match('#^[A-Za-z0-9_\-]{1,40}(/[A-Za-z0-9_\-]{1,40}){0,4}\z#', $p);
}

/** Passt eine feste Ebene zu einem {teil}? (Muster der Tabelle, ausser-Liste) */
function bb_teil_passt($wert, $teil, array $z)
{
    if (!isset($z['teile'][$teil]) || !is_string($z['teile'][$teil])) { return false; }
    if (!bb_ebene_gueltig($wert)) { return false; }
    if (@preg_match('#^(?:' . $z['teile'][$teil] . ')\z#', $wert) !== 1) { return false; }
    if (isset($z['ausser'][$teil]) && is_array($z['ausser'][$teil]) && in_array($wert, $z['ausser'][$teil], true)) {
        return false;
    }
    return true;
}

/** Name -> array(Schluessel, Teile) oder null. Feste Namen vor Mustern. */
function bb_name_finden($name, array $z)
{
    if (!bb_name_gueltig($name)) { return null; }
    $bef = $z['befehle'];
    if (isset($bef[$name]) && strpos($name, '{') === false) { return array($name, array()); }
    $ebenen = explode('/', $name);
    foreach ($bef as $schl => $e) {
        $schl = (string) $schl;
        if (strpos($schl, '{') === false) { continue; }
        $se = explode('/', $schl);
        if (count($se) !== count($ebenen)) { continue; }
        $teile = array();
        $ok = true;
        foreach ($se as $i => $s) {
            $t = bb_teil_name($s);
            if ($t !== null) {
                if (!bb_teil_passt($ebenen[$i], $t, $z)) { $ok = false; break; }
                $teile[$t] = $ebenen[$i];
            } elseif ($s !== $ebenen[$i]) {
                $ok = false;
                break;
            }
        }
        if ($ok) { return array($schl, $teile); }
    }
    return null;
}

/**
 * Koennen zwei Schluessel dasselbe Thema treffen? Zwei Muster auf derselben Ebene gelten als
 * ueberschneidend (Vorsicht vor Gruendlichkeit): getrennt werden sie durch eine feste Ebene.
 */
function bb_ueberschneiden($a, $b, array $z)
{
    $ea = explode('/', $a);
    $eb = explode('/', $b);
    if (count($ea) !== count($eb)) { return false; }
    foreach ($ea as $i => $x) {
        $y = $eb[$i];
        $tx = bb_teil_name($x);
        $ty = bb_teil_name($y);
        if ($tx === null && $ty === null) {
            if ($x !== $y) { return false; }
            continue;
        }
        if ($tx !== null && $ty !== null) { continue; }
        if ($tx === null) {
            if (!bb_teil_passt($x, $ty, $z)) { return false; }
        } elseif (!bb_teil_passt($y, $tx, $z)) {
            return false;
        }
    }
    return true;
}

/** Rueckgabe array(Fehlerliste, Hinweisliste). Leere Fehlerliste = Tabelle gueltig. */
function bb_zuordnung_pruefen($z)
{
    $fehl = array();
    $info = array();
    if (!is_array($z)) { return array(array('Zuordnung ist kein Feld'), $info); }
    if (!isset($z['fassung']) || $z['fassung'] !== BB_ZUORDNUNG_FASSUNG) {
        $fehl[] = "'fassung' ist nicht " . BB_ZUORDNUNG_FASSUNG . ' - diese Bruecke (' . BB_FASSUNG
            . ') kennt nur Tabellenfassung ' . BB_ZUORDNUNG_FASSUNG;
    }
    if (!isset($z['linie']) || !is_string($z['linie']) || $z['linie'] === '') { $fehl[] = "'linie' fehlt"; }
    if (!isset($z['geladen']) || $z['geladen'] !== true) {
        $fehl[] = "Bibliothek der Linie nicht geladen ('geladen' ist nicht true)";
    }
    foreach (array('einstellungen', 'protokoll') as $k) {
        if (!isset($z[$k]) || !is_callable($z[$k])) { $fehl[] = "'" . $k . "' ist keine Funktion"; }
    }
    if (isset($z['teile'])) {
        if (!is_array($z['teile'])) {
            $fehl[] = "'teile' ist kein Feld";
        } else {
            foreach ($z['teile'] as $t => $re) {
                if (!preg_match('/^[a-z_][a-z0-9_]{0,19}\z/', (string) $t)) { $fehl[] = "Teil '" . $t . "': Name ungueltig"; }
                if (!is_string($re) || @preg_match('#^(?:' . $re . ')\z#', 'x') === false) {
                    $fehl[] = "Teil '" . $t . "': Muster ist kein gueltiger Ausdruck";
                }
            }
        }
    }
    if (isset($z['ausser']) && !is_array($z['ausser'])) { $fehl[] = "'ausser' ist kein Feld"; }
    if (!isset($z['befehle']) || !is_array($z['befehle']) || !$z['befehle']) {
        $fehl[] = "'befehle' fehlt oder ist leer";
        return array($fehl, $info);
    }
    $arten = explode(' ', BB_ARTEN);
    $eingreifend = false;
    foreach ($z['befehle'] as $schl => $e) {
        $schl = (string) $schl;
        $wo = "Befehl '" . $schl . "': ";
        $ebenen = explode('/', $schl);
        if (count($ebenen) > BB_EBENEN_MAX) { $fehl[] = $wo . 'mehr als ' . BB_EBENEN_MAX . ' Ebenen'; }
        foreach ($ebenen as $x) {
            $t = bb_teil_name($x);
            if ($t !== null) {
                if (!isset($z['teile'][$t])) { $fehl[] = $wo . 'Teil {' . $t . "} steht nicht unter 'teile'"; }
            } elseif (!bb_ebene_gueltig($x)) {
                $fehl[] = $wo . "Ebene '" . $x . "' ungueltig (a-z, 0-9, _; 1-40 Zeichen)";
            }
        }
        if (end($ebenen) === 'quittung') { $fehl[] = $wo . "endet auf 'quittung' - waere nie ausfuehrbar"; }
        if (!is_array($e)) { $fehl[] = $wo . 'kein Feld'; continue; }
        $art = isset($e['art']) ? $e['art'] : null;
        if (!in_array($art, $arten, true)) { $fehl[] = $wo . "'art' ist nicht " . str_replace(' ', '|', BB_ARTEN); }
        if ($art === 'ganz' || $art === 'zahl') {
            $zahl = $art === 'ganz' ? 'is_int' : 'is_numeric';
            if (!isset($e['min'], $e['max']) || !$zahl($e['min']) || !$zahl($e['max'])) {
                $fehl[] = $wo . "'min'/'max' fehlen oder sind keine " . ($art === 'ganz' ? 'ganzen Zahlen' : 'Zahlen');
            } elseif ($e['min'] > $e['max']) {
                $fehl[] = $wo . "'min' groesser als 'max'";
            } elseif (abs($e['min']) > 999999999 || abs($e['max']) > 999999999) {
                $fehl[] = $wo . "'min'/'max' ausserhalb von 9 Stellen";
            }
        }
        if ($art === 'auswahl') {
            $ok = isset($e['werte']) && is_array($e['werte']) && $e['werte'];
            if ($ok) {
                foreach ($e['werte'] as $w) {
                    if (!is_string($w) || !bb_ebene_gueltig($w)) { $ok = false; }
                }
            }
            if (!$ok) { $fehl[] = $wo . "'werte' fehlt oder enthaelt etwas anderes als Woerter aus a-z, 0-9, _"; }
        }
        if ($art === 'text' && (!isset($e['max_byte']) || !is_int($e['max_byte']) || $e['max_byte'] < 1
                || $e['max_byte'] > BB_NUTZLAST_MAX)) {
            $fehl[] = $wo . "'max_byte' fehlt oder liegt nicht in 1-" . BB_NUTZLAST_MAX;
        }
        if (!isset($e['eingreifend']) || !is_bool($e['eingreifend'])) {
            $fehl[] = $wo . "'eingreifend' fehlt (true/false)";
        } elseif ($e['eingreifend']) {
            $eingreifend = true;
        }
        if (isset($e['sperre_s'])) {
            if (!is_int($e['sperre_s']) || $e['sperre_s'] < 0 || $e['sperre_s'] > BB_SPERRE_MAX) {
                $fehl[] = $wo . "'sperre_s' liegt nicht in 0-" . BB_SPERRE_MAX;
            } elseif ($e['sperre_s'] !== BB_SPERRE_VORGABE
                    && (!isset($e['begruendung']) || !is_string($e['begruendung']) || trim($e['begruendung']) === '')) {
                $fehl[] = $wo . "'sperre_s' weicht von " . BB_SPERRE_VORGABE . " ab, 'begruendung' fehlt";
            }
        }
        if (!isset($e['titel']) || !is_string($e['titel']) || trim($e['titel']) === '') {
            $fehl[] = $wo . "'titel' fehlt";
        } elseif (substr($e['titel'], -7) !== ' (MQTT)') {
            $fehl[] = $wo . "'titel' endet nicht auf ' (MQTT)' (Form '<Plugin> <Bedeutung> (MQTT)')";
        }
        if (!isset($e['kachel']) || !is_string($e['kachel']) || $e['kachel'] === '' || strlen($e['kachel']) > 40) {
            $fehl[] = $wo . "'kachel' fehlt oder ist laenger als 40 Zeichen";
        }
        if (!isset($e['quittung']) || !in_array($e['quittung'], array('ausgefuehrt', 'uebergeben'), true)) {
            $fehl[] = $wo . "'quittung' ist nicht ausgefuehrt|uebergeben";
        }
        if (!isset($e['tun']) || !is_callable($e['tun'])) { $fehl[] = $wo . "'tun' ist keine Funktion"; }
    }
    if ($eingreifend && (!isset($z['zweiter_haken']) || !is_string($z['zweiter_haken']) || $z['zweiter_haken'] === '')) {
        $fehl[] = "eingreifende Befehle vorhanden, aber 'zweiter_haken' nennt den zweiten Haken der Linie nicht";
    }
    $schl = array_map('strval', array_keys($z['befehle']));
    $n = count($schl);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (bb_ueberschneiden($schl[$i], $schl[$j], $z)) {
                $fehl[] = "Befehle '" . $schl[$i] . "' und '" . $schl[$j] . "' koennen dasselbe Thema treffen";
            }
        }
    }
    return array($fehl, $info);
}

/** Einstellungen der Linie frisch holen; null bei Ausnahme oder falscher Form. */
function bb_einstellungen(array $z)
{
    try {
        $e = call_user_func($z['einstellungen']);
    } catch (Throwable $t) {
        bb_log('ERROR', 'einstellungen() warf ' . get_class($t) . ': ' . $t->getMessage());
        return null;
    }
    if (!is_array($e) || !isset($e['praefix'])) { return null; }
    return array(
        'praefix' => (string) $e['praefix'],
        'annehmen' => !empty($e['annehmen']),
        'eingreifend' => !empty($e['eingreifend']),
        'mqtt_ein' => array_key_exists('mqtt_ein', $e) ? !empty($e['mqtt_ein']) : true,
    );
}

// ------------------------------------------------------------------------------------------
// Rechenkern: Zeile, Wert, Stapel (vom Selbsttest gedeckt)
// ------------------------------------------------------------------------------------------

/** Eine Zeile von mosquitto_sub -F '%r %t %x' -> array(retain, thema, hex) oder null. */
function bb_zeile_lesen($zeile)
{
    if (!preg_match('/^([01]) (.+) ([0-9a-fA-F]*)\z/s', $zeile, $m)) { return null; }
    return array('retain' => $m[1] === '1', 'thema' => $m[2], 'hex' => $m[3]);
}

function bb_zahl_text($f)
{
    $s = rtrim(rtrim(sprintf('%.3F', (float) $f), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

/** Wert nach Art pruefen und normieren -> array(true, normiert) oder array(false, erwartet). */
function bb_wert_pruefen(array $e, $roh)
{
    if (!is_string($roh)) { return array(false, 'Text'); }
    if (strlen($roh) > BB_NUTZLAST_MAX) { return array(false, 'hoechstens ' . BB_NUTZLAST_MAX . ' Byte'); }
    $art = $e['art'];
    if ($art === 'text') {
        if (!preg_match('//u', $roh)) { return array(false, 'Text in UTF-8'); }
        $t = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $roh));
        $max = (int) $e['max_byte'];
        if ($t === '') { return array(false, 'Text, nicht leer'); }
        if (strlen($t) > $max) { return array(false, 'Text bis ' . $max . ' Byte'); }
        return array(true, $t);
    }
    $w = trim($roh);
    if ($art === 'puls') { return $w === '1' ? array(true, '1') : array(false, '1'); }
    if ($art === 'schalter') { return ($w === '0' || $w === '1') ? array(true, $w) : array(false, '0 oder 1'); }
    if ($art === 'ganz') {
        $er = 'ganze Zahl ' . $e['min'] . ' bis ' . $e['max'];
        if (!preg_match('/^-?[0-9]{1,9}\z/', $w)) { return array(false, $er); }
        $n = (int) $w;
        if ($n < $e['min'] || $n > $e['max']) { return array(false, $er); }
        return array(true, (string) $n);
    }
    if ($art === 'zahl') {
        $er = 'Zahl ' . bb_zahl_text($e['min']) . ' bis ' . bb_zahl_text($e['max']);
        if (!preg_match('/^-?[0-9]{1,9}(?:[.,][0-9]{1,3})?\z/', $w)) { return array(false, $er); }
        $f = (float) str_replace(',', '.', $w);
        if ($f < $e['min'] || $f > $e['max']) { return array(false, $er); }
        return array(true, bb_zahl_text($f));
    }
    if ($art === 'auswahl') {
        $k = strtolower($w);
        return in_array($k, $e['werte'], true) ? array(true, $k) : array(false, implode('|', $e['werte']));
    }
    return array(false, 'unbekannte Art');
}

/** Grund der Linie fuer die Quittung: ASCII, hoechstens 120 Zeichen. */
function bb_grund_ascii($g)
{
    $g = preg_replace('/[^A-Za-z0-9 _:.,()\/=+-]/', '_', (string) $g);
    $g = trim(substr($g, 0, 120));
    return $g === '' ? 'ohne Grund' : $g;
}

function bb_neuer_zustand()
{
    return array(
        'sperre' => array(), 'unbekannt' => array(), 'gebremst' => array(),
        'zaehler' => array('empfangen' => 0, 'angenommen' => 0, 'abgelehnt' => 0, 'verworfen' => 0,
                           'quittungen_gesehen' => 0, 'gedrosselt' => 0),
        'letzter' => null,
    );
}

/**
 * Einen Stapel Zeilen bearbeiten (alles, was im Lesepuffer stand).
 *   $e       Einstellungen; 'praefix' ist das des laufenden Abos
 *   $senden  function($name, $text) - Quittung auf <p>/befehl/<name>/quittung
 *   $uhr     function() - Sekunden (float); der Selbsttest gibt eine eigene Uhr
 * Rueckgabe array('ende' => bool) - true, wenn der Haken aus ist (Dienst endet).
 */
function bb_stapel(array $zeilen, array &$st, array $z, array $e, $senden, $uhr)
{
    $vor = $e['praefix'] . '/befehl/';
    $ende = false;
    $kand = array();
    $quitt = function ($name, $text, $wert = null) use (&$st, $senden, $uhr) {
        call_user_func($senden, $name, $text);
        $st['letzter'] = array('name' => $name, 'ergebnis' => $text, 'zeit' => (int) call_user_func($uhr));
        if ($wert !== null) { $st['letzter'] += $wert; }
    };
    foreach ($zeilen as $zeile) {
        $st['zaehler']['empfangen']++;
        $r = bb_zeile_lesen($zeile);
        if ($r === null) {
            $st['zaehler']['verworfen']++;
            bb_log_gebremst($st, 'form', 'WARN', 'Zeile in unerwarteter Form verworfen (mosquitto_sub -F).', 3600);
            continue;
        }
        if (strpos($r['thema'], $vor) !== 0) {
            $st['zaehler']['verworfen']++;
            bb_log_gebremst($st, 'fremd', 'INFO', 'Nachricht ausserhalb ' . $vor . '# verworfen.', 3600);
            continue;
        }
        $name = substr($r['thema'], strlen($vor));
        if ($r['retain']) {
            $st['zaehler']['verworfen']++;
            bb_log_gebremst($st, 'retain_' . md5($r['thema']), 'WARN', 'zurueckbehaltener Befehl auf ' . substr($r['thema'], 0, 160)
                . " verworfen, nicht ausgefuehrt (in Loxone 'retain' durch 'publish' ersetzen).", 3600);
            continue;
        }
        $ebenen = explode('/', $name);
        if (end($ebenen) === 'quittung') { $st['zaehler']['quittungen_gesehen']++; continue; }
        if (!bb_name_gueltig($name)) {
            $st['zaehler']['verworfen']++;
            bb_log_gebremst($st, 'name', 'WARN', 'Thema mit ungueltigem Befehlsnamen verworfen: ' . substr($r['thema'], 0, 160), 3600);
            continue;
        }
        if (!$e['annehmen']) {
            $st['zaehler']['abgelehnt']++;
            $quitt($name, 'abgelehnt: ausgeschaltet');
            $ende = true;
            continue;
        }
        $f = bb_name_finden($name, $z);
        if ($f === null) {
            $st['zaehler']['abgelehnt']++;
            $jetzt = call_user_func($uhr);
            $st['unbekannt'] = array_values(array_filter($st['unbekannt'], function ($t) use ($jetzt) { return $jetzt - $t < 60; }));
            if (count($st['unbekannt']) < BB_UNBEKANNT_JE_MINUTE) {
                $st['unbekannt'][] = $jetzt;
                $quitt($name, 'abgelehnt: unbekannter Befehl');
            } else {
                $st['zaehler']['gedrosselt']++;
            }
            bb_log_gebremst($st, 'unbekannt', 'WARN', 'unbekannter Befehl ' . $name . ' (weitere hoechstens stuendlich gemeldet).', 3600);
            continue;
        }
        $eint = $z['befehle'][$f[0]];
        $nutz = (strlen($r['hex']) % 2 === 0) ? hex2bin($r['hex']) : false;
        list($ok, $w) = bb_wert_pruefen($eint, $nutz === false ? null : $nutz);
        if (!$ok) {
            $st['zaehler']['abgelehnt']++;
            $quitt($name, 'abgelehnt: Wert ungueltig (' . $w . ')');
            continue;
        }
        $kand[] = array('name' => $name, 'teile' => $f[1], 'wert' => $w, 'e' => $eint);
    }
    // Zusammenlegen (E16): je Name gilt der neueste Wert; puls und text einzeln.
    $letzter = array();
    $zus = explode(' ', BB_ZUSAMMENLEGEN);
    foreach ($kand as $i => $k) {
        if (in_array($k['e']['art'], $zus, true)) { $letzter[$k['name']] = $i; }
    }
    foreach ($kand as $i => $k) {
        $name = $k['name'];
        $merk = ($k['e']['art'] === 'text') ? array('laenge' => strlen($k['wert'])) : array('wert' => $k['wert']);
        if (isset($letzter[$name]) && $letzter[$name] !== $i) {
            $st['zaehler']['abgelehnt']++;
            $quitt($name, 'abgelehnt: ersetzt durch neueren Wert', $merk);
            continue;
        }
        $sperre = isset($k['e']['sperre_s']) ? (int) $k['e']['sperre_s'] : BB_SPERRE_VORGABE;
        $sk = $name . "\n" . $k['wert'];
        $jetzt = call_user_func($uhr);
        if ($sperre > 0 && isset($st['sperre'][$sk]) && $jetzt - $st['sperre'][$sk] < $sperre) {
            $st['zaehler']['abgelehnt']++;
            $quitt($name, 'abgelehnt: wiederholt (vor ' . (int) floor($jetzt - $st['sperre'][$sk]) . ' s)', $merk);
            continue;
        }
        if (!empty($k['e']['eingreifend']) && empty($e['eingreifend'])) {
            $st['zaehler']['abgelehnt']++;
            $quitt($name, 'abgelehnt: zweiter Haken aus', $merk);
            continue;
        }
        $erg = null;
        try {
            $erg = call_user_func($k['e']['tun'], $k['wert'], $k['teile']);
        } catch (Throwable $t) {
            bb_log('ERROR', 'tun() fuer ' . $name . ' warf ' . get_class($t) . ': ' . substr($t->getMessage(), 0, 200));
            $erg = 'Ausnahme';
        }
        $st['sperre'][$sk] = $jetzt;
        if (!is_array($erg) || !array_key_exists(0, $erg) || !is_bool($erg[0])) {
            if ($erg !== 'Ausnahme') { bb_log('ERROR', 'tun() fuer ' . $name . ' gab keine Form array(bool, grund) zurueck.'); }
            $text = 'abgelehnt: interner Fehler';
        } elseif ($erg[0]) {
            $text = 'angenommen';
        } else {
            $text = 'abgelehnt: ' . bb_grund_ascii(isset($erg[1]) ? $erg[1] : '');
        }
        $st['zaehler'][$text === 'angenommen' ? 'angenommen' : 'abgelehnt']++;
        $quitt($name, $text, $merk);
        bb_log('INFO', $name . ' (' . ($k['e']['art'] === 'text' ? strlen($k['wert']) . ' Byte Text' : $k['wert']) . '): ' . $text);
    }
    // Sperrliste klein halten
    $jetzt = call_user_func($uhr);
    foreach ($st['sperre'] as $sk => $t) {
        if ($jetzt - $t > BB_SPERRE_MAX) { unset($st['sperre'][$sk]); }
    }
    return array('ende' => $ende);
}

// ------------------------------------------------------------------------------------------
// Broker, Optionsdatei, Prozesse
// ------------------------------------------------------------------------------------------

/** Broker aus config/system/general.json (Regeln/07 Abschnitt 2); localhost -> 127.0.0.1. */
function bb_broker(array $p)
{
    $aus = array('host' => '127.0.0.1', 'port' => 1883, 'user' => '', 'pass' => '', 'udpin' => 11884);
    $g = bb_json_lesen($p['general']);
    if (!$g || !isset($g['Mqtt']) || !is_array($g['Mqtt'])) { return $aus; }
    $m = $g['Mqtt'];
    $hol = function ($k) use ($m) { return isset($m[$k]) && is_scalar($m[$k]) ? trim((string) $m[$k]) : ''; };
    $h = $hol('Brokerhost');
    if ($h === '' || $h === 'localhost') { $h = '127.0.0.1'; }
    $pt = (int) $hol('Brokerport');
    $ui = (int) $hol('Udpinport');
    // Benutzer und Kennwort NICHT kuerzen: mosquitto liest "-P <kennwort>" samt Leerzeichen im und am
    // Ende richtig aus der Optionsdatei (Probe P2, 18.10.2026).
    $roh = function ($k) use ($m) { return isset($m[$k]) && is_scalar($m[$k]) ? (string) $m[$k] : ''; };
    return array('host' => $h, 'port' => ($pt > 0 && $pt < 65536) ? $pt : 1883,
                 'user' => $roh('Brokeruser'), 'pass' => $roh('Brokerpass'),
                 'udpin' => ($ui > 0 && $ui < 65536) ? $ui : 11884);
}

/** Optionsdateien (0600, Ordner 0700), atomar und nur bei Aenderung. Rueckgabe Ordner oder ''. */
function bb_optionen(array $p, array $b)
{
    $o = $p['optionen'];
    // Ein Zeilenumbruch im Benutzer oder Kennwort wuerde in der Optionsdatei eine weitere Option einschleusen.
    if (preg_match('/[\r\n]/', $b['user'] . $b['pass'])) { return ''; }
    if (!is_dir($o) && !@mkdir($o, 0700, true)) { return ''; }
    @chmod($o, 0700);
    $zeilen = '';
    if ($b['user'] !== '') { $zeilen .= '-u ' . $b['user'] . "\n"; }
    if ($b['pass'] !== '') { $zeilen .= '-P ' . $b['pass'] . "\n"; }
    foreach (array('mosquitto_sub', 'mosquitto_pub') as $n) {
        $f = $o . '/' . $n;
        clearstatcache(true, $f);
        if (!is_file($f) || (string) @file_get_contents($f) !== $zeilen || (fileperms($f) & 0777) !== 0600) {
            if (!bb_atomar($f, $zeilen, 0600)) { return ''; }
        }
    }
    return $o;
}

function bb_programm($name)
{
    foreach (array('/usr/bin', '/usr/local/bin', '/bin') as $d) {
        if (is_file($d . '/' . $name) && is_executable($d . '/' . $name)) { return $d . '/' . $name; }
    }
    return '';
}

function bb_umgebung($opt)
{
    $u = array('XDG_CONFIG_HOME' => $opt, 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C');
    if ((string) getenv('HOME') !== '') { $u['HOME'] = (string) getenv('HOME'); }
    return $u;
}

/** Eine Nachricht senden, NIE retained (kein -r). Rueckgabe true bei Rueckgabewert 0. */
function bb_mqtt_senden(array $b, $opt, $thema, $text)
{
    $pub = bb_programm('mosquitto_pub');
    if ($pub === '' || $opt === '') { return false; }
    $kmd = array($pub, '-h', $b['host'], '-p', (string) $b['port'], '-q', '0', '-t', $thema, '-m', $text);
    $rohr = array();
    $pr = @proc_open($kmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'),
                                 2 => array('file', '/dev/null', 'w')), $rohr, null, bb_umgebung($opt));
    if (!is_resource($pr)) { return false; }
    $bis = microtime(true) + 5;
    do {
        $s = proc_get_status($pr);
        if (!$s['running']) { proc_close($pr); return $s['exitcode'] === 0; }
        usleep(5000);
    } while (microtime(true) < $bis);
    @proc_terminate($pr, 9);
    @proc_close($pr);
    return false;
}

/** Lebenszeichen <p>/status/befehle: 1 laeuft, 0 aus, -1 Fehler; nie retained. */
function bb_status_senden(array $p, $e, $wert)
{
    if (!is_array($e) || empty($e['mqtt_ein']) || !bb_praefix_gueltig($e['praefix'])) { return false; }
    $b = bb_broker($p);
    $opt = bb_optionen($p, $b);
    return bb_mqtt_senden($b, $opt, $e['praefix'] . '/status/befehle', (string) (int) $wert);
}

/** argv aus /proc/<pid>/cmdline */
function bb_argv($pid)
{
    $c = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($c === false || $c === '') { return array(); }
    if (substr($c, -1) === "\0") { $c = substr($c, 0, -1); }
    return explode("\0", $c);
}

/** Laufende Dienste dieses Skripts: argv[0] ein php, argv[1] genau das Skript, kein argv[2]. */
function bb_dienste($skript = null)
{
    if ($skript === null) { $skript = bb_skript(); }
    $aus = array();
    foreach ((array) @glob('/proc/[0-9]*', GLOB_ONLYDIR) as $d) {
        $pid = (int) basename($d);
        $a = bb_argv($pid);
        if (count($a) !== 2 || !preg_match('#(^|/)php[0-9.]*\z#', $a[0])) { continue; }
        $a1 = $a[1];
        if ($a1 !== '' && $a1[0] !== '/') {
            $cwd = @readlink('/proc/' . $pid . '/cwd');
            if ($cwd === false) { continue; }
            $a1 = $cwd . '/' . $a1;
        }
        if ($a1 === $skript) { $aus[] = $pid; }
    }
    sort($aus);
    return $aus;
}

/** mosquitto_sub-Prozesse mit UNSERER Optionsdatei (XDG_CONFIG_HOME) - auch verwaiste. */
function bb_abos($opt)
{
    $aus = array();
    foreach ((array) @glob('/proc/[0-9]*', GLOB_ONLYDIR) as $d) {
        if (trim((string) @file_get_contents($d . '/comm')) !== 'mosquitto_sub') { continue; }
        $env = @file_get_contents($d . '/environ');
        if ($env !== false && in_array('XDG_CONFIG_HOME=' . $opt, explode("\0", $env), true)) {
            $aus[] = (int) basename($d);
        }
    }
    return $aus;
}

// ------------------------------------------------------------------------------------------
// Dienst
// ------------------------------------------------------------------------------------------

$GLOBALS['bb_halt'] = false;
$GLOBALS['bb_kind'] = null;

function bb_signal($sig)
{
    $GLOBALS['bb_halt'] = true;
}

function bb_kind_beenden()
{
    $k = $GLOBALS['bb_kind'];
    $GLOBALS['bb_kind'] = null;
    if (!is_array($k) || !is_resource($k['proz'])) { return; }
    @proc_terminate($k['proz'], 15);
    $bis = microtime(true) + 2;
    while (microtime(true) < $bis) {
        $s = @proc_get_status($k['proz']);
        if (!$s || !$s['running']) { break; }
        usleep(20000);
    }
    $s = @proc_get_status($k['proz']);
    if ($s && $s['running']) { @proc_terminate($k['proz'], 9); }
    foreach ($k['rohr'] as $r) { if (is_resource($r)) { @fclose($r); } }
    @proc_close($k['proz']);
}

function bb_zustand_schreiben(array $p, array $st, array $extra)
{
    $j = array('fassung' => BB_FASSUNG, 'pid' => getmypid(), 'ts' => time()) + $extra
       + array('zaehler' => $st['zaehler'], 'letzter' => $st['letzter']);
    bb_json_schreiben($p['zustand'], $j);
}

function bb_beim_ende()
{
    $f = error_get_last();
    $fatal = $f && in_array($f['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true);
    bb_kind_beenden();
    if ($fatal && !empty($GLOBALS['bb_dienst_laeuft'])) {
        $text = 'abgestuerzt: ' . substr($f['message'], 0, 200) . ' (' . basename($f['file']) . ':' . $f['line'] . ')';
        bb_log('ERROR', $text . ' - der Waechter startet neu.');
        $p = bb_pfade();
        if ($p) {
            $z = bb_json_lesen($p['zustand']);
            if (is_array($z)) {
                $z['fehler'] = $text;
                $z['abo'] = false;
                bb_json_schreiben($p['zustand'], $z);
            }
            if (isset($GLOBALS['bb_einst'])) { bb_status_senden($p, $GLOBALS['bb_einst'], -1); }
        }
    }
}

/** Warten in Sekundenschritten, Halt beachten, zustand.json frisch halten (Waechter misst ts). */
function bb_warten($sek, array $p, array &$st, array $extra)
{
    $bis = time() + $sek;
    $letzt = 0;
    while (time() < $bis && !$GLOBALS['bb_halt']) {
        if (time() - $letzt >= BB_ZUSTAND_TAKT) { bb_zustand_schreiben($p, $st, $extra); $letzt = time(); }
        sleep(1);
    }
}

function bb_dienst()
{
    $p = bb_pfade();
    if (!$p) { fwrite(STDERR, "befehlsbruecke: keine LoxBerry-Installation erkannt - nichts getan.\n"); return 1; }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        fwrite(STDERR, "befehlsbruecke: nicht als root starten.\n");
        return 1;
    }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) { fwrite(STDERR, 'befehlsbruecke: ' . $fehler . "\n"); return 1; }
    $GLOBALS['bb_zuordnung'] = $z;
    list($fehl, $info) = bb_zuordnung_pruefen($z);
    if ($fehl) { bb_log('ERROR', 'Zuordnung defekt: ' . $fehl[0] . ' - Dienst endet.'); return 1; }
    $ich = getmypid();
    foreach (bb_dienste() as $pid) {
        if ($pid < $ich) { bb_log('WARN', 'laeuft schon (PID ' . $pid . ') - dieser Start endet.'); return 3; }
    }
    if (!is_dir($p['daten']) && !@mkdir($p['daten'], 0700, true)) {
        bb_log('ERROR', 'Datenordner nicht anlegbar - Dienst endet.');
        return 1;
    }
    @chmod($p['daten'], 0700);   // 1.0.1: auch ein von 1.0.0 mit 0750 angelegter Ordner
    foreach (bb_abos($p['optionen']) as $w) {
        if (function_exists('posix_kill')) { posix_kill($w, 15); }
        bb_log('WARN', 'verwaistes mosquitto_sub (PID ' . $w . ') eines frueheren Laufs beendet.');
    }
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, 'bb_signal');
        pcntl_signal(SIGINT, 'bb_signal');
        pcntl_signal(SIGHUP, SIG_IGN);
    }
    $GLOBALS['bb_dienst_laeuft'] = true;
    register_shutdown_function('bb_beim_ende');
    $st = bb_neuer_zustand();
    $start = time();
    $extra = array('start' => $start, 'abo' => false, 'kind_pid' => 0, 'linie' => (string) $z['linie'], 'fehler' => '');
    bb_zustand_schreiben($p, $st, $extra);
    bb_log('INFO', 'gestartet (PID ' . $ich . ', Fassung ' . BB_FASSUNG . ', PHP ' . PHP_VERSION . ').');
    $warte = 10;
    $uhr = function () { return microtime(true); };
    while (!$GLOBALS['bb_halt']) {
        $e = bb_einstellungen($z);
        if ($e === null) {
            $extra['fehler'] = 'einstellungen() lieferte nichts';
            bb_log_gebremst($st, 'einst', 'ERROR', 'einstellungen() der Zuordnung lieferte nichts - neuer Versuch in 30 s.', 3600);
            bb_warten(30, $p, $st, $extra);
            continue;
        }
        $GLOBALS['bb_einst'] = $e;
        if (!$e['annehmen']) {
            bb_log('INFO', 'Haken aus - der Dienst endet.');
            $extra['abo'] = false;
            bb_zustand_schreiben($p, $st, $extra);
            bb_status_senden($p, $e, 0);
            return 0;
        }
        if (!bb_praefix_gueltig($e['praefix'])) {
            $extra['fehler'] = 'Praefix ungueltig';
            bb_log_gebremst($st, 'praefix', 'ERROR', 'Praefix ungueltig - kein Abo; neuer Versuch in 60 s.', 3600);
            bb_warten(60, $p, $st, $extra);
            continue;
        }
        $b = bb_broker($p);
        $opt = bb_optionen($p, $b);
        $sub = bb_programm('mosquitto_sub');
        if ($opt === '' || $sub === '' || bb_programm('mosquitto_pub') === '') {
            $extra['fehler'] = 'mosquitto_sub/_pub fehlt oder Optionsordner nicht schreibbar';
            bb_log_gebremst($st, 'mosq', 'ERROR', $extra['fehler'] . ' - neuer Versuch in 60 s.', 3600);
            bb_warten(60, $p, $st, $extra);
            continue;
        }
        $praefix = $e['praefix'];
        // MQTT 5 mit retain-as-published: unter 3.1.1 stellt mosquitto 2.0.21 eine retained Nachricht,
        // die WAEHREND des Abos kommt, mit Retain-Marke 0 zu - weder -R noch %r saehen sie (gemessen
        // 18.10.2026, Probe P1). Ohne -R: die Bruecke sieht jede retained Nachricht selbst (%r = 1),
        // verwirft sie und protokolliert sie (Reiter Test nennt sie).
        $kmd = array($sub, '-h', $b['host'], '-p', (string) $b['port'], '-V', 'mqttv5', '--retain-as-published',
                     '-F', '%r %t %x', '-t', $praefix . '/befehl/#');
        $rohr = array();
        $proz = @proc_open($kmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
                           $rohr, null, bb_umgebung($opt));
        if (!is_resource($proz)) {
            $extra['fehler'] = 'mosquitto_sub liess sich nicht starten';
            bb_log_gebremst($st, 'start', 'ERROR', $extra['fehler'] . ' - neuer Versuch in ' . $warte . ' s.', 3600);
            bb_warten($warte, $p, $st, $extra);
            $warte = min(60, $warte * 2);
            continue;
        }
        $GLOBALS['bb_kind'] = array('proz' => $proz, 'rohr' => $rohr);
        $ks = proc_get_status($proz);
        $extra['abo'] = true;
        $extra['kind_pid'] = (int) $ks['pid'];
        $extra['praefix'] = $praefix;
        $extra['fehler'] = '';
        stream_set_blocking($rohr[1], false);
        stream_set_blocking($rohr[2], false);
        $seit = time();
        $puf = '';
        $epuf = '';
        $eof = false;
        $naechst = time() + BB_ZUSTAND_TAKT;
        bb_zustand_schreiben($p, $st, $extra);
        while (!$GLOBALS['bb_halt']) {
            $lesen = array($rohr[1]);
            if (is_resource($rohr[2]) && !feof($rohr[2])) { $lesen[] = $rohr[2]; }
            $w = null;
            $x = null;
            $n = @stream_select($lesen, $w, $x, max(0, $naechst - time()));
            if ($n === false) {
                if ($GLOBALS['bb_halt']) { break; }
                usleep(100000);
                continue;
            }
            foreach ($lesen as $s) {
                if ($s === $rohr[2]) {
                    $t = fread($rohr[2], 8192);
                    if ($t !== false && $t !== '') {
                        $epuf .= $t;
                        while (($pos = strpos($epuf, "\n")) !== false) {
                            $zl = trim(substr($epuf, 0, $pos));
                            $epuf = substr($epuf, $pos + 1);
                            if ($zl !== '') {
                                bb_log_gebremst($st, 'kind_' . md5($zl), 'WARN', 'mosquitto_sub meldet: ' . substr($zl, 0, 200), 3600);
                            }
                        }
                    }
                    continue;
                }
                $t = fread($rohr[1], 65536);
                if ($t === false || $t === '') {
                    if (feof($rohr[1])) { $eof = true; }
                } else {
                    $puf .= $t;
                }
            }
            while (!$eof) {   // alles mitnehmen, was schon ansteht: Grundlage des Zusammenlegens
                $l2 = array($rohr[1]);
                $w = null;
                $x = null;
                if (@stream_select($l2, $w, $x, 0) < 1) { break; }
                $t = fread($rohr[1], 65536);
                if ($t === false || $t === '') {
                    if (feof($rohr[1])) { $eof = true; }
                    break;
                }
                $puf .= $t;
            }
            $zeilen = array();
            while (($pos = strpos($puf, "\n")) !== false) {
                $zeilen[] = rtrim(substr($puf, 0, $pos), "\r");
                $puf = substr($puf, $pos + 1);
            }
            if (strlen($puf) > 1048576) {
                bb_log('WARN', 'Zeile ueber 1 MB ohne Umbruch verworfen.');
                $puf = '';
            }
            if ($zeilen) {
                $e2 = bb_einstellungen($z);
                if ($e2 === null) { $e2 = $e; }
                $GLOBALS['bb_einst'] = $e2;
                $e2['praefix'] = $praefix;   // die Zeilen gehoeren zum laufenden Abo
                $senden = function ($name, $text) use ($b, $opt, $praefix, &$st) {
                    if (!bb_mqtt_senden($b, $opt, $praefix . '/befehl/' . $name . '/quittung', $text)) {
                        bb_log_gebremst($st, 'quittung', 'WARN', 'Quittung liess sich nicht senden (mosquitto_pub).', 3600);
                    }
                };
                $erg = bb_stapel($zeilen, $st, $z, $e2, $senden, $uhr);
                bb_zustand_schreiben($p, $st, $extra);
                if ($erg['ende']) {
                    bb_log('INFO', 'Haken aus - der Dienst endet.');
                    bb_kind_beenden();
                    $extra['abo'] = false;
                    $extra['kind_pid'] = 0;
                    bb_zustand_schreiben($p, $st, $extra);
                    bb_status_senden($p, $e2, 0);
                    return 0;
                }
            }
            if ($eof) { break; }
            if (time() >= $naechst) {
                $e2 = bb_einstellungen($z);
                if ($e2 !== null && !$e2['annehmen']) {
                    bb_log('INFO', 'Haken aus - der Dienst endet.');
                    bb_kind_beenden();
                    $extra['abo'] = false;
                    $extra['kind_pid'] = 0;
                    bb_zustand_schreiben($p, $st, $extra);
                    bb_status_senden($p, $e2, 0);
                    return 0;
                }
                if ($e2 !== null && $e2['praefix'] !== $praefix) {
                    bb_log('INFO', 'Praefix geaendert - neues Abo.');
                    break;
                }
                bb_zustand_schreiben($p, $st, $extra);
                $naechst = time() + BB_ZUSTAND_TAKT;
            }
        }
        bb_kind_beenden();
        $extra['abo'] = false;
        $extra['kind_pid'] = 0;
        if ($eof && !$GLOBALS['bb_halt']) { usleep(300000); }   // kam das Ende mit einem Halt? Signal abwarten
        if ($GLOBALS['bb_halt']) { break; }
        if ($eof && time() - $seit < 60) {
            $extra['fehler'] = 'Anmeldung abgewiesen oder Broker nicht erreichbar';
            bb_log_gebremst($st, 'anmeldung', 'WARN', "Anmeldung als '" . $b['user'] . "' abgewiesen oder Broker nicht erreichbar"
                . ' (mosquitto_sub endete nach ' . (time() - $seit) . ' s) - neuer Versuch in ' . $warte . ' s.', 3600);
            bb_warten($warte, $p, $st, $extra);
            $warte = min(60, $warte * 2);
        } else {
            $warte = 10;
        }
    }
    bb_kind_beenden();
    $extra['abo'] = false;
    $extra['kind_pid'] = 0;
    bb_zustand_schreiben($p, $st, $extra);
    bb_log('INFO', 'angehalten (Signal).');
    return 0;
}

// ------------------------------------------------------------------------------------------
// Soll, Waechter, Liste, Vorlage, Einrichtungspruefung
// ------------------------------------------------------------------------------------------

/**
 * Soll die Bruecke laufen? Rueckgabe array(rc, grund, einstellungen|null):
 * 0 = soll laufen, 1 = soll nicht (Haken aus), 3 = Zuordnung oder Einrichtung defekt.
 */
function bb_soll_ermitteln()
{
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) { return array(3, $fehler, null); }
    $GLOBALS['bb_zuordnung'] = $z;
    list($fehl, $info) = bb_zuordnung_pruefen($z);
    if ($fehl) { return array(3, 'Zuordnung defekt: ' . $fehl[0], null); }
    $e = bb_einstellungen($z);
    if ($e === null) { return array(3, 'einstellungen() der Zuordnung lieferte nichts', null); }
    if (!$e['annehmen']) { return array(1, 'Haken aus', $e); }
    if (!bb_praefix_gueltig($e['praefix'])) { return array(3, 'Praefix ungueltig', $e); }
    return array(0, 'soll laufen', $e);
}

function bb_zustand_lesen()
{
    $p = bb_pfade();
    return $p ? bb_json_lesen($p['zustand']) : null;
}

/**
 * Upgrade-Marke: gilt, wenn sie hoechstens BB_MARKE_GILT alt ist. Unlesbar oder aus der Zukunft: gilt
 * nicht (Hausregel Regeln/06). 1.0.1: ist die Uhr leer oder ungueltig ($jetzt nur fuer den Selbsttest),
 * gilt eine vorhandene Marke - geschlossen ausfallen, nicht starten (B3).
 */
function bb_marke_liegt(array $p, $jetzt = null)
{
    if (!is_file($p['marke'])) { return false; }
    if ($jetzt === null) { $jetzt = time(); }
    if (!is_int($jetzt) || $jetzt <= 0) { return true; }
    $t = trim((string) @file_get_contents($p['marke']));
    if (!preg_match('/^[0-9]{1,12}\z/', $t)) { return false; }
    $t = (int) $t;
    return $t <= $jetzt && $jetzt - $t < BB_MARKE_GILT;
}

/** befehlsbruecke.sh aufrufen; Rueckgabe array(rc, Ausgabe). */
function bb_sh($p, $befehl)
{
    $aus = array();
    $rc = 1;
    exec('bash ' . escapeshellarg($p['sh']) . ' ' . escapeshellarg($befehl) . ' 2>&1', $aus, $rc);
    return array($rc, trim(implode(' ', $aus)));
}

/**
 * Ein Waechterlauf (Entwurf 2.8). Fuer den PHP-Minutentakt der Linie (BB_NUR_LADEN) oder
 * befehlsbruecke.sh waechter. Misst das Erzeugnis (zustand.json ts <= 180 s), nicht die PID;
 * Neustart hoechstens alle 600 s, nie bei Upgrade-Marke; sendet <p>/status/befehle (nie retained).
 * Rueckgabe array(status 1|0|-1|null, Text).
 */
function bb_waechter()
{
    $p = bb_pfade();
    if (!$p) { return array(null, 'keine LoxBerry-Installation erkannt'); }
    // Zwei Takte in derselben Sekunde (Boot): der zweite Waechterlauf geht still. Die Sperre haelt nur
    // dieser PHP-Prozess; befehlsbruecke.sh schliesst geerbte Deskriptoren, der Dienst erbt sie nicht.
    if (!is_dir($p['daten'])) { @mkdir($p['daten'], 0700, true); }
    @chmod($p['daten'], 0700);   // 1.0.1: 0700 statt 0750, auch fuer den Bestand
    $wsperre = @fopen($p['daten'] . '/waechter.lock', 'c');
    if ($wsperre === false || !flock($wsperre, LOCK_EX | LOCK_NB)) { return array(null, 'anderer Waechterlauf'); }
    $erg = bb_waechter_lauf($p);
    flock($wsperre, LOCK_UN);
    fclose($wsperre);
    return $erg;
}

function bb_waechter_lauf(array $p)
{
    list($soll, $grund, $e) = bb_soll_ermitteln();
    if ($e === null && isset($GLOBALS['bb_zuordnung']['einstellungen'])) {
        $e = bb_einstellungen($GLOBALS['bb_zuordnung']);   // fuer das Lebenszeichen auch bei defekter Tabelle
    }
    $laeuft = (bool) bb_dienste();
    $zu = bb_json_lesen($p['zustand']);
    $alter = ($zu && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    $w = bb_json_lesen($p['waechter']);
    if (!$w) { $w = array(); }
    $jetzt = time();
    $status = null;
    $text = '';
    if ($soll === 0) {
        if ($laeuft && $alter !== null && $alter <= BB_WACHE_GRENZE) {
            $status = !empty($zu['abo']) ? 1 : -1;
            $text = !empty($zu['abo']) ? 'laeuft' : 'laeuft, ohne Abo (' . (isset($zu['fehler']) ? $zu['fehler'] : '') . ')';
        } elseif (bb_marke_liegt($p)) {
            $text = 'Aktualisierung im Gang - kein Start';
        } elseif (isset($w['letzter_start']) && $w['letzter_start'] <= $jetzt && $jetzt - $w['letzter_start'] < BB_WACHE_BREMSE) {
            $status = -1;
            $text = ($laeuft ? 'haengt seit ' . $alter . ' s' : 'steht') . ' - naechster Versuch in '
                  . (BB_WACHE_BREMSE - ($jetzt - $w['letzter_start'])) . ' s (Bremse)';
        } else {
            if ($laeuft) {
                list($rc, $aus) = bb_sh($p, 'stop');
                bb_log('WARN', 'Waechter: Dienst laeuft, hat aber seit ' . $alter . ' s nichts geschrieben (Grenze '
                    . BB_WACHE_GRENZE . ' s) - Neustart. stop: ' . $aus);
            }
            $w['letzter_start'] = $jetzt;
            bb_json_schreiben($p['waechter'], $w);
            list($rc, $aus) = bb_sh($p, 'start');
            if ($rc === 3) {   // ein anderer start/stop hielt die Sperre - Wirkung nachsehen
                sleep(2);
                $rc = bb_dienste() ? 0 : 3;
            }
            if ($rc === 0 && bb_dienste()) {
                $status = 1;
                $text = 'nachgestartet';
                bb_log('WARN', 'Waechter: Dienst nachgestartet (' . $aus . ').');
            } else {
                $status = -1;
                $text = 'Start gescheitert: ' . $aus;
                bb_log('ERROR', 'Waechter: Start gescheitert (Rueckgabe ' . $rc . '): ' . $aus);
            }
        }
    } elseif ($soll === 1) {
        if ($laeuft) {
            list($rc, $aus) = bb_sh($p, 'stop');
            bb_log('INFO', 'Waechter: Haken aus - ' . $aus);
        }
        $status = 0;
        $text = 'aus (Haken)';
    } else {
        if ($laeuft) {
            list($rc, $aus) = bb_sh($p, 'stop');
            bb_log('ERROR', 'Waechter: ' . $grund . ' - Dienst angehalten: ' . $aus);
        } else {
            $st0 = array('gebremst' => isset($w['gebremst']) ? $w['gebremst'] : array());
            bb_log_gebremst($st0, 'defekt', 'ERROR', 'Waechter: ' . $grund . ' - kein Start.', 3600);
            $w['gebremst'] = $st0['gebremst'];
            bb_json_schreiben($p['waechter'], $w);
        }
        $status = -1;
        $text = $grund;
    }
    if ($status !== null) { bb_status_senden($p, $e, $status); }
    return array($status, $text);
}

function bb_thema_text($praefix, $name)
{
    return ($praefix !== '' ? $praefix : '<praefix>') . '/befehl/' . $name;
}

/** Eine Quelle fuer Reiter, Vorlage und Musterprojekt (Entwurf 2.6). */
function bb_liste(array $z, $e = null)
{
    $praefix = (is_array($e) && bb_praefix_gueltig($e['praefix'])) ? $e['praefix'] : '';
    $bef = array();
    foreach ($z['befehle'] as $name => $x) {
        $name = (string) $name;
        $art = $x['art'];
        $l = array('name' => $name, 'thema' => bb_thema_text($praefix, $name), 'art' => $art,
                   'muster' => strpos($name, '{') !== false,
                   'eingreifend' => !empty($x['eingreifend']),
                   'sperre_s' => isset($x['sperre_s']) ? (int) $x['sperre_s'] : BB_SPERRE_VORGABE,
                   'begruendung' => isset($x['begruendung']) ? (string) $x['begruendung'] : '',
                   'titel' => (string) $x['titel'], 'kachel' => (string) $x['kachel'],
                   'quittung' => (string) $x['quittung']);
        if ($art === 'ganz' || $art === 'zahl') { $l['min'] = $x['min']; $l['max'] = $x['max']; }
        if ($art === 'auswahl') { $l['werte'] = array_values($x['werte']); }
        if ($art === 'text') { $l['max_byte'] = (int) $x['max_byte']; }
        $l['loxone'] = bb_loxone_befehl($l['thema'], $art, $x);
        $bef[] = $l;
    }
    return array(
        'bruecke' => BB_FASSUNG, 'linie' => (string) $z['linie'],
        'plugin' => isset($z['plugin']) ? (string) $z['plugin'] : (string) $z['linie'],
        'haken' => array('befehle_mqtt_ein' => 'Befehle ueber MQTT annehmen (ab Werk aus)',
                         'zweiter_haken' => isset($z['zweiter_haken']) ? (string) $z['zweiter_haken'] : ''),
        'abo' => bb_thema_text($praefix, '#'),
        'lebenszeichen' => ($praefix !== '' ? $praefix : '<praefix>') . '/status/befehle',
        'gateway_v2_haken' => array(($praefix !== '' ? $praefix : '<praefix>') . '/status/befehle'),
        'befehle' => $bef,
    );
}

/** Loxone-Befehl je Art: analog <v.0>/<v.1>, digital feste 1 (schalter: Aus 0). */
function bb_loxone_befehl($thema, $art, array $x)
{
    if ($art === 'ganz') { return array('analog' => true, 'ein' => 'publish ' . $thema . ' <v.0>', 'aus' => ''); }
    if ($art === 'zahl') { return array('analog' => true, 'ein' => 'publish ' . $thema . ' <v.1>', 'aus' => ''); }
    if ($art === 'puls') { return array('analog' => false, 'ein' => 'publish ' . $thema . ' 1', 'aus' => ''); }
    if ($art === 'schalter') {
        return array('analog' => false, 'ein' => 'publish ' . $thema . ' 1', 'aus' => 'publish ' . $thema . ' 0');
    }
    if ($art === 'auswahl') {
        $w = array();
        foreach ($x['werte'] as $v) { $w[$v] = 'publish ' . $thema . ' ' . $v; }
        return array('analog' => false, 'je_wert' => $w, 'aus' => '');
    }
    return array('analog' => false, 'ein' => 'publish ' . $thema . ' <fester Text>', 'aus' => '');
}

function bb_xml($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Loxone-Vorlage: eigener Ausgang "<Plugin> MQTT-Befehle" an /dev/udp/<loxberry>/<udpin> (E3). */
function bb_vorlage(array $liste, $host, $port)
{
    $z = array('<?xml version="1.0" encoding="utf-8"?>');
    $z[] = '<VirtualOut HintText="" Title="' . bb_xml($liste['plugin'] . ' MQTT-Befehle') . '" Comment="'
         . bb_xml('UDP-Eingang des MQTT-Gateways auf dem LoxBerry; wirkt nur mit Haken "Befehle ueber MQTT annehmen"')
         . '" Address="' . bb_xml('/dev/udp/' . $host . '/' . (int) $port) . '" CmdInit="" CloseAfterSend="true" CmdSep="">';
    $z[] = "\t" . '<Info templateType="3" minVersion="17010727"/>';
    foreach ($liste['befehle'] as $b) {
        if ($b['muster']) {
            $z[] = "\t" . '<!-- Muster ' . bb_xml($b['name']) . ': je Wert selbst anlegen, Befehl wie '
                 . bb_xml($b['loxone']['ein'] ?? '') . ' -->';
            continue;
        }
        if ($b['art'] === 'text') {
            $z[] = "\t" . '<!-- Text ' . bb_xml($b['name']) . ': selbst anlegen, Befehl bei Ein: publish '
                 . bb_xml($b['thema']) . ' <fester Text>, hoechstens ' . (int) $b['max_byte'] . ' Byte -->';
            continue;
        }
        $kom = $b['art'] . ($b['eingreifend'] ? ', eingreifend' : '') . ', nie retain';
        if ($b['art'] === 'ganz' || $b['art'] === 'zahl') { $kom = $b['min'] . ' bis ' . $b['max'] . ', ' . $kom; }
        if ($b['art'] === 'auswahl') {
            foreach ($b['loxone']['je_wert'] as $wert => $ein) {
                $titel = preg_replace('/ \(MQTT\)\z/', ' ' . $wert . ' (MQTT)', $b['titel']);
                $z[] = bb_vorlage_befehl($titel, $kom, $ein, '', false);
            }
            continue;
        }
        $z[] = bb_vorlage_befehl($b['titel'], $kom, $b['loxone']['ein'], $b['loxone']['aus'], $b['loxone']['analog']);
    }
    $z[] = '</VirtualOut>';
    return implode("\r\n", $z) . "\r\n";
}

function bb_vorlage_befehl($titel, $kom, $ein, $aus, $analog)
{
    return "\t" . '<VirtualOutCmd Title="' . bb_xml($titel) . '" Comment="' . bb_xml($kom) . '" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="'
        . bb_xml($ein) . '" CmdOnHTTP="" CmdOnPost="" CmdOff="' . bb_xml($aus) . '" CmdOffHTTP="" CmdOffPost="" CmdAnswer=""'
        . ($analog ? ' Analog="true"' : '') . ' Repeat="0" RepeatRate="0"'
        . ($analog ? ' SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10"' : '') . ' HintText=""/>';
}

/** Welche Funktionen ruft eine Closure auf? (Tokenizer; Methoden, new und Variablen ausgenommen) */
function bb_aufrufe($fn)
{
    if (!function_exists('token_get_all')) { return null; }
    try {
        $rf = new ReflectionFunction($fn);
    } catch (Throwable $t) {
        return null;
    }
    $datei = $rf->getFileName();
    if (!$datei || !is_file($datei)) { return null; }
    $zeilen = file($datei);
    $code = implode('', array_slice($zeilen, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
    $tok = token_get_all('<?php ' . $code);
    $aus = array();
    $davor = null;
    $n = count($tok);
    $aus_art = array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION);
    if (defined('T_NULLSAFE_OBJECT_OPERATOR')) { $aus_art[] = T_NULLSAFE_OBJECT_OPERATOR; }
    $namen = array(T_STRING);
    if (defined('T_NAME_FULLY_QUALIFIED')) { $namen[] = T_NAME_FULLY_QUALIFIED; }
    for ($i = 0; $i < $n; $i++) {
        $t = $tok[$i];
        if (is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) { continue; }
        if (is_array($t) && in_array($t[0], $namen, true)) {
            $j = $i + 1;
            while ($j < $n && is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) { $j++; }
            $vorher_ok = !(is_array($davor) && in_array($davor[0], $aus_art, true));
            if ($j < $n && $tok[$j] === '(' && $vorher_ok) { $aus[ltrim($t[1], '\\')] = true; }
        }
        $davor = $t;
    }
    return array_keys($aus);
}

/** Einrichtungspruefung; Rueckgabe 0 ohne [FEHL], sonst 1. */
function bb_pruefen()
{
    $fehl = 0;
    $zeile = function ($art, $text) use (&$fehl) {
        if ($art === 'FEHL') { $fehl++; }
        echo str_pad('[' . $art . ']', 7) . $text . "\n";
    };
    $zeile('OK', 'PHP ' . PHP_VERSION . ', Befehlsbruecke ' . BB_FASSUNG);
    foreach (array('mosquitto_sub', 'mosquitto_pub') as $prog) {
        $pf = bb_programm($prog);
        $zeile($pf !== '' ? 'OK' : 'FEHL', $prog . ($pf !== '' ? ' vorhanden (' . $pf . ')' : ' fehlt (Paket mosquitto-clients)'));
    }
    $p = bb_pfade();
    if (!$p) {
        $zeile('INFO', 'keine LoxBerry-Lage erkannt (Archiv oder Arbeitsordner) - Pfade nicht geprueft');
    } else {
        $o = $p['optionen'];
        if (is_dir($o)) {
            $r = fileperms($o) & 0777;
            $zeile(($r === 0700 && is_writable($o)) ? 'OK' : 'FEHL', 'Optionsordner ' . ($r === 0700 ? '0700' : sprintf('%04o statt 0700', $r))
                . (is_writable($o) ? ', schreibbar' : ', NICHT schreibbar'));
            foreach (array('mosquitto_sub', 'mosquitto_pub') as $n) {
                if (is_file($o . '/' . $n)) {
                    $r = fileperms($o . '/' . $n) & 0777;
                    $zeile($r === 0600 ? 'OK' : 'FEHL', 'Optionsdatei ' . $n . ' ' . sprintf('%04o', $r));
                }
            }
        } else {
            $ober = is_dir($p['daten']) ? $p['daten'] : dirname($p['daten']);
            $zeile(is_writable($ober) ? 'INFO' : 'FEHL', 'Optionsordner noch nicht angelegt'
                . (is_writable($ober) ? ' - entsteht beim Start (0700)' : ' und ' . $ober . ' nicht schreibbar'));
        }
    }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) {
        $zeile('FEHL', $fehler);
        return 1;
    }
    $GLOBALS['bb_zuordnung'] = $z;
    list($zf, $zi) = bb_zuordnung_pruefen($z);
    foreach ($zf as $t) { $zeile('FEHL', 'Zuordnung: ' . $t); }
    if ($zf) { return 1; }
    $zeile('OK', "Zuordnung '" . $z['linie'] . "' gueltig, " . count($z['befehle']) . ' Befehle, keine Ueberschneidung');
    foreach ($z['befehle'] as $name => $x) {
        $auf = bb_aufrufe($x['tun']);
        if ($auf === null) { $zeile('INFO', "Befehl '" . $name . "': Aufrufe nicht ermittelbar"); continue; }
        $fehlt = array();
        foreach ($auf as $f) {
            if (!function_exists($f)) { $fehlt[] = $f . '()'; }
        }
        if ($fehlt) {
            $zeile('FEHL', "Befehl '" . $name . "': tun ruft " . implode(', ', $fehlt) . ' - gibt es nicht');
        } else {
            $linie = array_values(array_filter($auf, function ($f) {
                $r = new ReflectionFunction($f);
                return !$r->isInternal();
            }));
            $zeile('OK', "Befehl '" . $name . "' (" . $x['art'] . ') -> ' . ($linie ? implode('(), ', $linie) . '()' : 'keine Funktion der Linie'));
        }
    }
    $e = bb_einstellungen($z);
    if ($e === null) {
        $zeile('FEHL', 'einstellungen() lieferte nichts');
    } else {
        $zeile(bb_praefix_gueltig($e['praefix']) ? 'OK' : 'FEHL', 'Praefix ' . ($e['praefix'] !== '' ? $e['praefix'] : '(leer)')
            . ' - Abo ' . $e['praefix'] . '/befehl/#');
        $zeile('INFO', 'Befehle ueber MQTT annehmen: ' . ($e['annehmen'] ? 'an' : 'aus') . '; zweiter Haken'
            . (isset($z['zweiter_haken']) ? ' (' . $z['zweiter_haken'] . ')' : '') . ': ' . ($e['eingreifend'] ? 'an' : 'aus'));
    }
    if ($p) {
        $d = bb_dienste();
        $zu = bb_json_lesen($p['zustand']);
        $zeile('INFO', 'Dienst: ' . ($d ? 'laeuft (PID ' . implode(' ', $d) . ')' : 'steht')
            . ($zu && isset($zu['ts']) ? ', Zustand vor ' . (time() - (int) $zu['ts']) . ' s geschrieben' : ''));
    }
    return $fehl ? 1 : 0;
}

// ------------------------------------------------------------------------------------------
// Selbsttest (Rechenkern ohne Broker)
// ------------------------------------------------------------------------------------------

function bb_selbsttest()
{
    $n = 0;
    $f = 0;
    $texte = array();
    $pruef = function ($bed, $was) use (&$n, &$f, &$texte) {
        $n++;
        if (!$bed) { $f++; $texte[] = 'FEHLSCHLAG: ' . $was; }
    };
    $GLOBALS['bb_zuordnung'] = array('protokoll' => function ($s, $t) {});
    // --- Zeilenform
    $r = bb_zeile_lesen('0 p/befehl/x 31');
    $pruef($r !== null && !$r['retain'] && $r['thema'] === 'p/befehl/x' && $r['hex'] === '31', 'Zeile normal');
    $r = bb_zeile_lesen('1 p/befehl/x 31');
    $pruef($r !== null && $r['retain'], 'Zeile retained');
    $r = bb_zeile_lesen('0 p/befehl/x ');
    $pruef($r !== null && $r['hex'] === '', 'Zeile leere Nutzlast');
    $r = bb_zeile_lesen('0 p/be fehl/x 3132');
    $pruef($r !== null && $r['thema'] === 'p/be fehl/x', 'Zeile Thema mit Leerzeichen');
    $pruef(bb_zeile_lesen('2 p/befehl/x 31') === null, 'Zeile falsche Retain-Marke');
    $pruef(bb_zeile_lesen('0 p/befehl/x zz') === null, 'Zeile keine Hexnutzlast');
    $pruef(bb_zeile_lesen('') === null, 'Zeile leer');
    // --- Namen, Praefix
    $pruef(bb_name_gueltig('radio/1/laut'), 'Name mehrstufig');
    $pruef(!bb_name_gueltig('Laut'), 'Name Grossbuchstabe');
    $pruef(!bb_name_gueltig('a//b'), 'Name leere Ebene');
    $pruef(!bb_name_gueltig(str_repeat('a', 41)), 'Name 41 Zeichen');
    $pruef(bb_name_gueltig(str_repeat('a', 40)), 'Name 40 Zeichen');
    $pruef(!bb_name_gueltig('a/b/c/d/e/f/g/h/i'), 'Name 9 Ebenen');
    $pruef(!bb_name_gueltig('a+'), 'Name Platzhalter');
    $pruef(bb_praefix_gueltig('alexang') && bb_praefix_gueltig('haus/alexa-ng'), 'Praefix gueltig');
    $pruef(!bb_praefix_gueltig('a/#') && !bb_praefix_gueltig('') && !bb_praefix_gueltig('a b') && !bb_praefix_gueltig('/a'), 'Praefix ungueltig');
    // --- Pruef-Tabelle mit Mitschrift
    $auf = array();
    $antwort = array('ok' => array(true, ''));
    $tun = function ($w, $t) use (&$auf, &$antwort) {
        $auf[] = array($w, $t);
        $a = $antwort['ok'];
        if ($a === 'wirf') { throw new RuntimeException('absichtlich'); }
        return $a;
    };
    $basis = array('eingreifend' => false, 'titel' => 'Pruef X (MQTT)', 'kachel' => 'BEFEHL.K_X', 'quittung' => 'ausgefuehrt', 'tun' => $tun);
    $z = array(
        'fassung' => 1, 'linie' => 'pruef', 'geladen' => true, 'zweiter_haken' => 'steuer_ein',
        'einstellungen' => function () { return array('praefix' => 'p', 'annehmen' => true, 'eingreifend' => false); },
        'protokoll' => function ($s, $t) {},
        'teile' => array('zone' => '[a-z0-9]{1,4}', 'geraet' => '[a-z0-9_]{1,40}'),
        'ausser' => array('geraet' => array('gruppe')),
        'befehle' => array(
            'test' => array('art' => 'puls') + $basis,
            'sperre' => array('art' => 'schalter') + $basis,
            'laut' => array('art' => 'ganz', 'min' => 0, 'max' => 100) + $basis,
            'soll' => array('art' => 'zahl', 'min' => -800, 'max' => 800, 'eingreifend' => true, 'sperre_s' => 50,
                            'begruendung' => 'Loxone erneuert alle 60 s') + $basis,
            'modus' => array('art' => 'auswahl', 'werte' => array('auto', 'aus')) + $basis,
            'ansage' => array('art' => 'text', 'max_byte' => 20) + $basis,
            'roh' => array('art' => 'ganz', 'min' => 0, 'max' => 9, 'sperre_s' => 0, 'begruendung' => 'Pruefung') + $basis,
            'radio/{zone}/laut' => array('art' => 'ganz', 'min' => 0, 'max' => 100) + $basis,
            '{geraet}/sprechen' => array('art' => 'text', 'max_byte' => 50) + $basis,
            'gruppe/{geraet}/sprechen' => array('art' => 'text', 'max_byte' => 50) + $basis,
        ),
    );
    list($zf, $zi) = bb_zuordnung_pruefen($z);
    $pruef(!$zf, 'Tabelle gueltig' . ($zf ? ': ' . $zf[0] : ''));
    // --- Namen finden
    $pruef(bb_name_finden('laut', $z) === array('laut', array()), 'fester Name');
    $pruef(bb_name_finden('radio/12/laut', $z) === array('radio/{zone}/laut', array('zone' => '12')), 'Muster mit Teil');
    $pruef(bb_name_finden('radio/12345/laut', $z) === null, 'Muster Teil zu lang');
    $pruef(bb_name_finden('kueche/sprechen', $z) === array('{geraet}/sprechen', array('geraet' => 'kueche')), 'Muster Geraet');
    $pruef(bb_name_finden('gruppe/sprechen', $z) === null, 'ausser-Liste wirkt');
    $pruef(bb_name_finden('gruppe/oben/sprechen', $z) === array('gruppe/{geraet}/sprechen', array('geraet' => 'oben')), 'Muster dreistufig');
    $pruef(bb_name_finden('lauter', $z) === null, 'unbekannter Name');
    $pruef(bb_name_finden('laut/x', $z) === null, 'Ebenenzahl falsch');
    $pruef(bb_name_finden('radio/1/laut/x', $z) === null, 'Muster: Ebene zu viel');
    // --- Tabellenfehler
    $kaputt = function ($aend) use ($z) { $k = $z; $aend($k); list($zf2) = bb_zuordnung_pruefen($k); return $zf2; };
    $pruef((bool) $kaputt(function (&$k) { $k['fassung'] = 2; }), 'fremde Fassung');
    $pruef((bool) $kaputt(function (&$k) { $k['geladen'] = false; }), 'Bibliothek nicht geladen');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['x/quittung'] = $k['befehle']['test']; }), 'Name endet auf quittung');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['radio/1/laut'] = $k['befehle']['laut']; }), 'fester Name gegen Muster');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['{zone}/sprechen'] = $k['befehle']['ansage']; }), 'zwei Muster gleiche Ebene');
    $pruef(!$kaputt(function (&$k) { $k['befehle']['gruppe/sprechen'] = $k['befehle']['ansage']; }), 'ausser trennt fest und Muster');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['laut']['sperre_s'] = 30; }), 'sperre_s ohne Begruendung');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['laut']['sperre_s'] = 4000; $k['befehle']['laut']['begruendung'] = 'x'; }), 'sperre_s zu gross');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['laut']['min'] = 200; }), 'min groesser max');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['laut']['art'] = 'zahlen'; }), 'Art unbekannt');
    $pruef((bool) $kaputt(function (&$k) { unset($k['zweiter_haken']); }), 'zweiter Haken nicht genannt');
    $pruef((bool) $kaputt(function (&$k) { unset($k['befehle']['laut']['tun']); }), 'tun fehlt');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['laut']['titel'] = 'Pruef Laut'; }), 'Titel ohne (MQTT)');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['laut']['kachel'] = str_repeat('K', 41); }), 'Kachel zu lang');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['Laut'] = $k['befehle']['laut']; }), 'Ebene mit Grossbuchstabe');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['x/{fehlt}'] = $k['befehle']['laut']; }), 'Teil ohne Muster');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['modus']['werte'] = array('Auto'); }), 'Auswahlwert ungueltig');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['ansage']['max_byte'] = 5000; }), 'max_byte zu gross');
    $pruef((bool) $kaputt(function (&$k) { $k['befehle']['laut']['quittung'] = 'fertig'; }), 'Quittungsart unbekannt');
    $pruef((bool) $kaputt(function (&$k) { unset($k['befehle']['laut']['eingreifend']); }), 'eingreifend fehlt');
    // --- Werte
    $W = function ($name, $roh) use ($z) { return bb_wert_pruefen($z['befehle'][$name], $roh); };
    $pruef($W('test', '1') === array(true, '1') && !$W('test', '0')[0], 'puls');
    $pruef($W('sperre', '0') === array(true, '0') && !$W('sperre', '2')[0] && !$W('sperre', '')[0], 'schalter');
    $pruef($W('laut', ' 7 ') === array(true, '7') && $W('laut', '007') === array(true, '7'), 'ganz normiert');
    $pruef($W('laut', '0')[0] && $W('laut', '100')[0] && !$W('laut', '101')[0] && !$W('laut', '-1')[0], 'ganz Grenzen');
    $pruef(!$W('laut', '1,5')[0] && !$W('laut', 'abc')[0] && !$W('laut', '')[0], 'ganz ungueltig');
    $pruef($W('laut', 'x') === array(false, 'ganze Zahl 0 bis 100'), 'ganz Erwartung');
    $pruef($W('soll', '1,5') === array(true, '1.5') && $W('soll', '1.500') === array(true, '1.5'), 'zahl Komma und Nullen');
    $pruef($W('soll', '-800') === array(true, '-800') && !$W('soll', '-800.5')[0] && $W('soll', '-0') === array(true, '0'), 'zahl Grenzen');
    $pruef(!$W('soll', '1.2345')[0] && !$W('soll', '1e3')[0], 'zahl Form');
    $pruef($W('modus', 'AUTO') === array(true, 'auto') && !$W('modus', 'hand')[0], 'auswahl');
    $pruef($W('ansage', " Guten\tMorgen \n") === array(true, 'Guten Morgen'), 'text Steuerzeichen');
    $pruef(!$W('ansage', "\n ")[0] && !$W('ansage', str_repeat('a', 21))[0] && $W('ansage', str_repeat('a', 20))[0], 'text leer und Laenge');
    $pruef(!$W('ansage', "\xC3\x28")[0], 'text kein UTF-8');
    $pruef($W('laut', str_repeat('1', 4001)) === array(false, 'hoechstens 4000 Byte'), 'Nutzlast 4001 Byte');
    $pruef(!$W('laut', null)[0], 'Nutzlast fehlt');
    $pruef(bb_grund_ascii("BREMSE \xC3\xA4") === 'BREMSE __' && bb_grund_ascii('') === 'ohne Grund', 'Grund ASCII');
    // --- Stapel
    $uhrzeit = 1000.0;
    $uhr = function () use (&$uhrzeit) { return $uhrzeit; };
    $q = array();
    $senden = function ($name, $text) use (&$q) { $q[] = $name . '=' . $text; };
    $e = array('praefix' => 'p', 'annehmen' => true, 'eingreifend' => false, 'mqtt_ein' => true);
    $Z = function ($name, $wert, $retain = '0') { return $retain . ' p/befehl/' . $name . ' ' . bin2hex($wert); };
    $lauf = function (array $zeilen, $e2 = null) use (&$st, $z, &$e, $senden, $uhr) {
        return bb_stapel($zeilen, $st, $z, $e2 === null ? $e : $e2, $senden, $uhr);
    };
    $neu = function () use (&$st, &$q, &$auf, &$antwort) {
        $st = bb_neuer_zustand(); $q = array(); $auf = array(); $antwort['ok'] = array(true, '');
    };
    $neu();
    $lauf(array($Z('laut', '42')));
    $pruef(count($auf) === 1 && $auf[0][0] === '42' && $q === array('laut=angenommen'), 'gueltiger Befehl');
    $pruef($st['letzter']['name'] === 'laut' && $st['letzter']['wert'] === '42', 'letzter Befehl');
    $neu();
    $lauf(array($Z('laut', '42', '1')));
    $pruef(!$auf && !$q && $st['zaehler']['verworfen'] === 1, 'retained verworfen, keine Quittung');
    $neu();
    $lauf(array('0 p/befehl/laut/quittung ' . bin2hex('angenommen')));
    $pruef(!$auf && !$q && $st['zaehler']['quittungen_gesehen'] === 1, 'eigene Quittung uebergangen');
    $neu();
    $lauf(array($Z('fremd', '1')));
    $pruef(!$auf && $q === array('fremd=abgelehnt: unbekannter Befehl'), 'unbekannter Befehl');
    $neu();
    $viele = array();
    for ($i = 0; $i < 15; $i++) { $viele[] = $Z('fremd' . $i, '1'); }
    $lauf($viele);
    $pruef(count($q) === 10 && $st['zaehler']['gedrosselt'] === 5, 'unbekannte gedrosselt (10 je Minute)');
    $uhrzeit += 61;
    $lauf(array($Z('fremdx', '1')));
    $pruef(count($q) === 11, 'Drossel nach 60 s offen');
    $neu();
    $erg = $lauf(array($Z('laut', '5')), array('annehmen' => false) + $e);
    $pruef(!$auf && $q === array('laut=abgelehnt: ausgeschaltet') && $erg['ende'], 'Haken aus');
    $neu();
    $lauf(array($Z('laut', '500')));
    $pruef(!$auf && $q === array('laut=abgelehnt: Wert ungueltig (ganze Zahl 0 bis 100)'), 'Wert ausserhalb');
    $neu();
    $lauf(array('0 p/befehl/laut 313'));
    $pruef(!$auf && count($q) === 1 && strpos($q[0], 'Wert ungueltig') !== false, 'ungerade Hexnutzlast');
    $neu();
    $lauf(array('0 andere/befehl/laut 31', 'kaputt'));
    $pruef(!$auf && !$q && $st['zaehler']['verworfen'] === 2, 'fremdes Praefix und kaputte Zeile verworfen');
    $neu();
    $lauf(array($Z('LAUT', '1')));
    $pruef(!$auf && !$q, 'ungueltiger Name ohne Quittung');
    // Sperrminute
    $neu();
    $lauf(array($Z('laut', '42')));
    $uhrzeit += 5;
    $lauf(array($Z('laut', '42')));
    $pruef(count($auf) === 1 && $q[1] === 'laut=abgelehnt: wiederholt (vor 5 s)', 'Sperrminute gleicher Wert');
    $lauf(array($Z('laut', '43')));
    $pruef(count($auf) === 2, 'anderer Wert sofort');
    $uhrzeit += 56;
    $lauf(array($Z('laut', '42')));
    $pruef(count($auf) === 3, 'nach 61 s wieder');
    $neu();
    $e['eingreifend'] = true;
    $lauf(array($Z('soll', '100')));
    $uhrzeit += 49;
    $lauf(array($Z('soll', '100,0')));
    $pruef(count($auf) === 1 && strpos(end($q), 'wiederholt (vor 49 s)') !== false, 'sperre_s 50: nach 49 s gesperrt, normiert');
    $uhrzeit += 2;
    $lauf(array($Z('soll', '100')));
    $pruef(count($auf) === 2, 'sperre_s 50: nach 51 s frei');
    $lauf(array($Z('roh', '3')));
    $lauf(array($Z('roh', '3')));
    $pruef(count($auf) === 4, 'sperre_s 0 sperrt nie');
    $neu();
    $antwort['ok'] = array(false, 'BREMSE');
    $lauf(array($Z('laut', '9')));
    $antwort['ok'] = array(true, '');
    $uhrzeit += 1;
    $lauf(array($Z('laut', '9')));
    $pruef(count($auf) === 1 && $q[0] === 'laut=abgelehnt: BREMSE' && strpos($q[1], 'wiederholt') !== false, 'Ablehnung der Linie sperrt auch');
    // zweiter Haken
    $neu();
    $e['eingreifend'] = false;
    $lauf(array($Z('soll', '10')));
    $pruef(!$auf && $q === array('soll=abgelehnt: zweiter Haken aus'), 'zweiter Haken aus');
    $e['eingreifend'] = true;
    $lauf(array($Z('soll', '10')));
    $pruef(count($auf) === 1 && end($q) === 'soll=angenommen', 'zweiter Haken an');
    $e['eingreifend'] = false;
    // Zusammenlegen
    $neu();
    $uhrzeit += 100;
    $lauf(array($Z('laut', '1'), $Z('laut', '2'), $Z('sperre', '1'), $Z('laut', '3')));
    $pruef(count($auf) === 2 && $auf[1][0] === '3' && $q[0] === 'laut=abgelehnt: ersetzt durch neueren Wert'
        && $q[1] === 'laut=abgelehnt: ersetzt durch neueren Wert', 'Zusammenlegen: neuester Wert gewinnt');
    $neu();
    $uhrzeit += 100;
    $lauf(array($Z('ansage', 'a'), $Z('ansage', 'b')));
    $pruef(count($auf) === 2, 'Text wird nicht zusammengelegt');
    $pruef($st['letzter']['laenge'] === 1 && !isset($st['letzter']['wert']), 'Text: nur Laenge im Zustand');
    $neu();
    $uhrzeit += 100;
    $lauf(array($Z('laut', '1'), $Z('laut', '500')));
    $pruef(count($auf) === 1 && $auf[0][0] === '1', 'Zusammenlegen nur unter gueltigen Werten');
    $neu();
    $uhrzeit += 100;
    $lauf(array($Z('radio/1/laut', '5'), $Z('radio/2/laut', '6')));
    $pruef(count($auf) === 2 && $auf[0][1] === array('zone' => '1'), 'Muster: Teile an tun, je Name getrennt');
    // Fehler der Linie
    $neu();
    $uhrzeit += 100;
    $antwort['ok'] = 'wirf';
    $lauf(array($Z('laut', '7')));
    $pruef($q === array('laut=abgelehnt: interner Fehler'), 'tun wirft');
    $antwort['ok'] = 'Unsinn';
    $lauf(array($Z('laut', '8')));
    $pruef(end($q) === 'laut=abgelehnt: interner Fehler', 'tun falsche Rueckgabe');
    $antwort['ok'] = array('1', '');
    $lauf(array($Z('laut', '12')));
    $pruef(end($q) === 'laut=abgelehnt: interner Fehler', 'tun: erstes Feld kein bool');
    $antwort['ok'] = array(false, "Gr\xC3\xBCnde");
    $lauf(array($Z('laut', '11')));
    $pruef(end($q) === 'laut=abgelehnt: Gr__nde', 'Grund ASCII in Quittung');
    // Quittungstexte ASCII
    $ascii = true;
    foreach ($q as $t) { if (preg_match('/[^\x20-\x7E]/', $t)) { $ascii = false; } }
    $pruef($ascii, 'Quittungen ASCII');
    // --- Liste und Vorlage
    $l = bb_liste($z, $e);
    $pruef(count($l['befehle']) === count($z['befehle']) && $l['befehle'][2]['thema'] === 'p/befehl/laut', 'Liste');
    $pruef($l['haken']['zweiter_haken'] === 'steuer_ein' && $l['lebenszeichen'] === 'p/status/befehle', 'Liste Haken');
    $pruef($l['befehle'][3]['sperre_s'] === 50 && $l['befehle'][2]['sperre_s'] === 60, 'Liste Sperrminute');
    $v = bb_vorlage($l, 'loxberry', 11884);
    $pruef(strpos($v, 'Address="/dev/udp/loxberry/11884"') !== false && strpos($v, 'Title="pruef MQTT-Befehle"') !== false, 'Vorlage Kopf');
    $pruef(strpos($v, 'CmdOn="publish p/befehl/laut &lt;v.0&gt;"') !== false, 'Vorlage analog <v.0>');
    $pruef(strpos($v, 'CmdOn="publish p/befehl/soll &lt;v.1&gt;"') !== false, 'Vorlage zahl <v.1>');
    $pruef(strpos($v, 'CmdOn="publish p/befehl/sperre 1" CmdOnHTTP="" CmdOnPost="" CmdOff="publish p/befehl/sperre 0"') !== false, 'Vorlage schalter');
    $pruef(strpos($v, 'CmdOn="publish p/befehl/test 1" CmdOnHTTP="" CmdOnPost="" CmdOff=""') !== false, 'Vorlage puls');
    $pruef(strpos($v, 'Title="Pruef X auto (MQTT)"') !== false, 'Vorlage auswahl je Wert');
    $pruef(strpos($v, 'CmdOn="retain') === false && strpos($v, 'CmdOff="retain') === false, 'Vorlage ohne retain-Befehl');
    $pruef(strpos($v, 'CmdOn="publish p/befehl/radio') === false, 'Vorlage ohne Muster');
    // PHP 8.5 am Geraet hat kein simplexml/dom/xml (gemessen 18.10.2026) - dann die Zaehlung und eine
    // Zeichenpruefung der Attributwerte (kein rohes < oder & ausserhalb einer Entitaet).
    if (function_exists('simplexml_load_string')) {
        $xml = @simplexml_load_string($v);
        $wohl = $xml !== false && count($xml->VirtualOutCmd) === 7;
    } else {
        $wohl = substr_count($v, '<VirtualOutCmd ') === 7 && substr($v, -15) === "</VirtualOut>\r\n"
            && !preg_match('/="[^"]*(<|&(?!lt;|gt;|amp;|quot;|#039;|apos;))/', $v);
    }
    $pruef($wohl, 'Vorlage wohlgeformt, 7 Befehle');
    $pruef(bb_zahl_text(-0.0001) === '0' && bb_zahl_text(2) === '2' && bb_zahl_text(0.125) === '0.125', 'Zahltext');
    // --- 1.0.1: Wurzel, Ordnernamen, Marke (B1/B3), im Wegwerfbaum unter dem Temp-Ordner
    $tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/bb_st_' . getmypid() . '_' . mt_rand(1000, 9999);
    $baue = function ($wurzel, $voll) {
        @mkdir($wurzel . '/bin/plugins/abf', 0700, true);
        if ($voll) {
            @mkdir($wurzel . '/config/plugins', 0700, true);
            @mkdir($wurzel . '/config/system', 0700, true);
            @mkdir($wurzel . '/data/plugins', 0700, true);
            @file_put_contents($wurzel . '/config/system/general.json', "{}\n");
        }
    };
    $baue($tmp . '/fremd', false);
    $baue($tmp . '/echt', true);
    $baue($tmp . '/halb', false);
    @mkdir($tmp . '/halb/config/plugins', 0700, true);
    @mkdir($tmp . '/halb/data/plugins', 0700, true);   // ohne general.json
    $pruef(bb_pfade_aus($tmp . '/fremd/bin/plugins/abf', '', '') === array(), 'fremder Baum: keine Pfade');
    $pruef(bb_pfade_aus($tmp . '/halb/bin/plugins/abf', '', '') === array(), 'Baum ohne general.json: keine Pfade');
    $baue($tmp . '/ohnedata', true);
    @rmdir($tmp . '/ohnedata/data/plugins');
    @rmdir($tmp . '/ohnedata/data');
    $baue($tmp . '/ohneconfig', true);
    @rmdir($tmp . '/ohneconfig/config/plugins');
    $pruef(bb_pfade_aus($tmp . '/ohnedata/bin/plugins/abf', '', '') === array()
        && bb_pfade_aus($tmp . '/ohneconfig/bin/plugins/abf', '', '') === array(), 'Baum ohne data/plugins bzw. config/plugins: keine Pfade');
    $pe = bb_pfade_aus($tmp . '/echt/bin/plugins/abf', '', '');
    $pruef(isset($pe['daten']) && $pe['daten'] === $tmp . '/echt/data/plugins/abf/befehle' && $pe['ordner'] === 'abf', 'echter Baum: Pfade');
    $pruef(bb_pfade_aus($tmp . '/irgendwo', $tmp . '/fremd', 'abf') === array(), 'LBHOMEDIR fremd: keine Pfade');
    $pl = bb_pfade_aus($tmp . '/irgendwo', $tmp . '/echt/', 'abf');
    $pruef(isset($pl['base']) && $pl['base'] === $tmp . '/echt' && $pl['ordner'] === 'abf', 'LBHOMEDIR echt: Pfade');
    $alle_ab = true;
    foreach (array('.', '..', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend') as $o) {
        if (bb_pfade_aus($tmp . '/irgendwo', $tmp . '/echt', $o) !== array()) { $alle_ab = false; }
    }
    $pruef($alle_ab, 'LBPPLUGINDIR: ungueltige Ordnernamen abgewiesen');
    $pruef(bb_ordner_ungueltig('') && !bb_ordner_ungueltig('abfahrtsassistent'), 'Ordnername leer/gueltig');
    $pruef(!is_dir($tmp . '/fremd/data') && !is_dir($tmp . '/fremd/log'), 'fremder Baum: nichts angelegt');
    // Marke
    $mk = array('marke' => $tmp . '/echt/data/plugins/abf.upgrade_laeuft');
    $pruef(!bb_marke_liegt($mk, 2000000000), 'Marke fehlt: gilt nicht');
    @file_put_contents($mk['marke'], "1999999000\n");
    $pruef(bb_marke_liegt($mk, 2000000000), 'Marke frisch: gilt');
    $pruef(!bb_marke_liegt($mk, 1999998000), 'Marke aus der Zukunft: gilt nicht (Hausregel)');
    $pruef(!bb_marke_liegt($mk, 2000000000 + 3600), 'Marke alt: gilt nicht');
    $pruef(bb_marke_liegt($mk, 0) && bb_marke_liegt($mk, -5) && bb_marke_liegt($mk, ''), 'leere/ungueltige Uhr: Marke gilt (nicht starten)');
    @file_put_contents($mk['marke'], "kaputt\n");
    $pruef(!bb_marke_liegt($mk, 2000000000), 'Marke unlesbar: gilt nicht');
    $pruef(bb_marke_liegt($mk, false), 'Marke unlesbar, Uhr ungueltig: gilt (geschlossen)');
    // aufraeumen (nur der eigene Wegwerfbaum)
    $weg = function ($d) use (&$weg) {
        foreach ((array) @scandir($d) as $e) {
            if ($e === '.' || $e === '..' || $e === false) { continue; }
            is_dir($d . '/' . $e) ? $weg($d . '/' . $e) : @unlink($d . '/' . $e);
        }
        @rmdir($d);
    };
    if (strpos($tmp, '/bb_st_') !== false) { $weg($tmp); }
    unset($GLOBALS['bb_zuordnung']);
    return array($n, $f, $texte);
}

// ------------------------------------------------------------------------------------------
// Hauptteil
// ------------------------------------------------------------------------------------------

function bb_haupt(array $argv)
{
    $erlaubt = array('--selbsttest', '--pruefen', '--soll', '--liste', '--vorlage', '--waechter');
    $schalter = '';
    $rest = array();
    foreach ($argv as $i => $a) {
        if ($i === 0) { continue; }
        if (strncmp((string) $a, '--', 2) === 0) {
            if (!in_array($a, $erlaubt, true) || $schalter !== '') {
                fwrite(STDERR, 'Unbekannter Schalter: ' . $a . "\n");
                return 2;
            }
            $schalter = $a;
        } else {
            $rest[] = $a;
        }
    }
    if ($rest && $schalter !== '--vorlage') {
        fwrite(STDERR, 'Unbekanntes Argument: ' . $rest[0] . "\n");
        return 2;
    }
    if ($schalter === '--selbsttest') {
        list($n, $f, $texte) = bb_selbsttest();
        foreach ($texte as $t) { echo $t . "\n"; }
        echo $n . ' Faelle geprueft, ' . $f . " Fehlschlaege.\n";
        return $f ? 1 : 0;
    }
    if ($schalter === '--pruefen') { return bb_pruefen(); }
    if ($schalter === '--soll') {
        list($rc, $grund) = bb_soll_ermitteln();
        echo $grund . "\n";
        return $rc;
    }
    if ($schalter === '--liste' || $schalter === '--vorlage') {
        list($z, $fehler) = bb_zuordnung_laden();
        if ($z === null) { fwrite(STDERR, $fehler . "\n"); return 3; }
        $GLOBALS['bb_zuordnung'] = $z;
        list($zf) = bb_zuordnung_pruefen($z);
        if ($zf) { fwrite(STDERR, 'Zuordnung defekt: ' . $zf[0] . "\n"); return 3; }
        $l = bb_liste($z, bb_einstellungen($z));
        if ($schalter === '--liste') {
            echo json_encode($l, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }
        $p = bb_pfade();
        $port = $p ? bb_broker($p)['udpin'] : 11884;
        echo bb_vorlage($l, $rest ? $rest[0] : 'loxberry', $port);
        return 0;
    }
    if ($schalter === '--waechter') {
        list($status, $text) = bb_waechter();
        echo 'Waechter: ' . $text . "\n";
        return ($status === -1) ? 1 : 0;
    }
    return bb_dienst();
}

if (!defined('BB_NUR_LADEN') && PHP_SAPI === 'cli' && isset($argv[0])
        && realpath($argv[0]) === realpath(__FILE__)) {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', 'stderr');
    exit(bb_haupt($argv));
}
