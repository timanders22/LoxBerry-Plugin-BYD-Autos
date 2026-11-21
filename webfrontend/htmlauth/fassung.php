<?php
/**
 * Gemeinsame Fassungsanzeige fuer die LoxBerry-Plugins des Hauses (Fassung 1.0.0)
 *
 * DIESE DATEI IST IN MEHREREN PLUGINS BYTEWEISE GLEICH.
 * Die Stammfassung liegt unter Werkzeuge/gemeinsam/fassung.php im
 * Arbeitsordner. Ein LoxBerry-Plugin kann keine Datei eines anderen Plugins
 * laden; deshalb traegt jede Linie eine Abschrift, und
 * Werkzeuge/gemeinsam_pruefen.py sowie Werkzeuge/fassung_titel_pruefen.py
 * melden jede Abschrift, die von der Stammfassung abweicht (--verteilen bringt
 * die Stammfassung hinein). Wer hier etwas aendert, aendert es in der
 * Stammfassung, hebt PLUGIN_FASSUNG_MODUL an, laesst plugin_fassung_selbsttest()
 * und die Eichung laufen und verteilt danach. Geaendert wird nur ergaenzend:
 * keine Funktion entfernen oder umbenennen, keine Bedeutung eines Parameters
 * oder einer Rueckgabe aendern.
 *
 * Herkunft: Entscheidung des Hausherrn 21.11.2026 ("vereinheitliche die
 * Anzeige der Fassungsnummer ueber alle Linien"). Der Weg ist der aus
 * Pumpenwacht pw_fassung() und Creality2Lox cr_pluginversion(), ergaenzt um
 * den Rueckfall auf die Plugin-Datenbank aus Spotpreis-Octopus oc_version(),
 * Smartmeter-classic sm_fassung() und Saugroboter-Valetudo ro_pluginversion()
 * (fuer Einstiege, die das SDK nicht laden).
 *
 * ------------------------------------------------------------------
 * Was die Datei tut - und was nicht
 * ------------------------------------------------------------------
 *
 *   - plugin_fassung($verzeichnis) nennt die installierte Fassung des Plugins,
 *     zu dem $verzeichnis gehoert. $verzeichnis ist das Verzeichnis der
 *     aufrufenden Datei, wenn es direkt webfrontend/htmlauth, webfrontend/html
 *     oder bin ist (__DIR__), sonst das dieser drei, in dem sie liegt
 *     (dirname(__DIR__) aus einem Unterordner). Installiert ist sein letzter
 *     Teil der Ordnername des Plugins (.../htmlauth/plugins/<ordner>).
 *     Reihenfolge:
 *       1. LBSystem::pluginversion(<ordner>), wenn das SDK geladen ist. Ohne
 *          Argument haengt die Antwort am ersten eingebundenen Skript (am
 *          Geraet gemessen 17.09.2026, Pumpenwacht: aus einem fremden
 *          Einstieg NULL, mit dem Ordnernamen die installierte Fassung).
 *       2. data/system/plugindatabase.json ueber den Ordnernamen (Feld
 *          folder, nie der MD5-Schluessel, Regeln/03). Die Wurzel kommt aus
 *          LBHOMEDIR oder aus der Gestalt des Pfades
 *          <wurzel>/(webfrontend/html|webfrontend/htmlauth|bin)/plugins/<ordner>.
 *       3. plugin.cfg, Abschnitt [PLUGIN], Zeile VERSION=, zeilenweise gelesen
 *          (parse_ini_file scheitert an den '#'-Kommentaren der plugin.cfg).
 *          Gesucht eine und zwei Ebenen ueber $verzeichnis: das trifft nur
 *          das entpackte Archiv und den Arbeitsordner - installiert wird die
 *          plugin.cfg nicht (Regeln/03, am Geraet gemessen 07.09.2026).
 *     Angenommen wird nur eine Fassung der Form [0-9][0-9A-Za-z.-]{0,31};
 *     eine andere Antwort einer Stufe gilt als "nichts gefunden" dieser
 *     Stufe. Findet keine Stufe etwas, ist die Antwort '' - nie eine
 *     geratene Nummer. Das Ergebnis wird je Verzeichnis zwischengespeichert.
 *   - plugin_kopftitel($titel, $verzeichnis) baut den Titel der Kopfzeile
 *     (erstes Argument von LBWeb::lbheader()) in der Form aller Linien:
 *     "<Titel> <Fassung>", genau ein Leerzeichen, kein "V". Ist die Fassung
 *     leer, steht nur der Titel da. Der Titel wird nicht veraendert (auch
 *     nicht maskiert: er geht so an lbheader() wie bisher).
 *   - plugin_fassung_herkunft($verzeichnis) nennt die Stufe, die geantwortet
 *     hat: 'lbsystem', 'datenbank', 'plugincfg' oder '' (keine) - fuer den
 *     Reiter Test.
 *   - Keine Meldung, kein Notice, keine Warnung, kein Protokoll, kein
 *     Schreiben. Die Datei laedt das SDK nicht und fasst keine Globalen an;
 *     deshalb darf sie auch innerhalb einer Funktion geladen werden (eine
 *     Fassungsfunktion der Linie: require_once __DIR__ . '/fassung.php';
 *     return plugin_fassung(__DIR__);).
 *   - Wo die Abschrift liegt: im Baum jeder Datei, die sie laedt -
 *     webfrontend/htmlauth/fassung.php fuer die Kopfzeile, dazu
 *     webfrontend/html/fassung.php, wenn eine Fassungsfunktion der Linie in
 *     webfrontend/html sie ruft (installiert sind die Baeume getrennt).
 *     Laufen zwei Abschriften in einem Prozess, gilt die zuerst geladene;
 *     sie sind byte-gleich, und das Verzeichnis geht als Argument mit.
 *
 * ------------------------------------------------------------------
 * Aufrufe
 * ------------------------------------------------------------------
 *
 *   require_once __DIR__ . '/fassung.php';   (die Abschrift liegt im selben
 *                                             Baum wie die aufrufende Datei)
 *   LBWeb::lbheader(plugin_kopftitel(xx_t('ALLG.TITEL'), __DIR__), ...);
 *   $fassung = plugin_fassung(__DIR__);
 *
 *   Selbsttest: php fassung.php --selbsttest
 */

/* Kein Endpunkt (Regeln/03): die Datei kann im unangemeldeten Baum
 * webfrontend/html/ liegen und waere dort direkt aufrufbar. Ein direkter
 * Aufruf ueber den Webserver bekommt 403 und tut sonst nichts. */
if (PHP_SAPI !== 'cli') {
    $plugin_fassung_einstieg = get_included_files();
    if (isset($plugin_fassung_einstieg[0]) && realpath($plugin_fassung_einstieg[0]) === realpath(__FILE__)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "FASSUNG;OK=0;GRUND=KEIN_ENDPUNKT\n";
        exit;
    }
    unset($plugin_fassung_einstieg);
}

if (!function_exists('plugin_fassung')) {

define('PLUGIN_FASSUNG_MODUL', '1.0.0');

/** Ist $v eine Fassung, die angezeigt werden darf? */
function plugin_fassung_gueltig($v)
{
    return is_string($v) && preg_match('/^[0-9][0-9A-Za-z.\-]{0,31}\z/', $v) === 1;
}

/** Verzeichnis ohne abschliessenden Schraegstrich, mit '/' als Trenner. */
function plugin_fassung_pfad($verzeichnis)
{
    if (!is_string($verzeichnis) || $verzeichnis === '') {
        $verzeichnis = __DIR__;
    }
    $p = rtrim(str_replace('\\', '/', $verzeichnis), '/');
    return $p === '' ? '/' : $p;
}

/** Die Wurzel des LoxBerry fuer die Plugin-Datenbank, oder ''. */
function plugin_fassung_wurzel($pfad)
{
    $h = getenv('LBHOMEDIR');
    if (is_string($h) && $h !== '') {
        return rtrim(str_replace('\\', '/', $h), '/');
    }
    if (preg_match('#^(.+)/(webfrontend/html|webfrontend/htmlauth|bin)/plugins/[^/]+\z#', $pfad, $m)) {
        return $m[1];
    }
    return '';
}

/** Stufe 2: die Fassung des Ordners $ordner aus <wurzel>/data/system/plugindatabase.json, oder ''. */
function plugin_fassung_aus_datenbank($wurzel, $ordner)
{
    if ($wurzel === '' || $ordner === '') {
        return '';
    }
    $f = $wurzel . '/data/system/plugindatabase.json';
    if (!@is_file($f) || !@is_readable($f)) {
        return '';
    }
    $roh = @file_get_contents($f);
    if (!is_string($roh) || $roh === '') {
        return '';
    }
    $d = @json_decode($roh, true);
    if (!is_array($d) || !isset($d['plugins']) || !is_array($d['plugins'])) {
        return '';
    }
    foreach ($d['plugins'] as $e) {
        if (is_array($e) && isset($e['folder'], $e['version']) && is_scalar($e['folder'])
            && is_scalar($e['version']) && (string) $e['folder'] === $ordner) {
            $v = trim((string) $e['version']);
            return plugin_fassung_gueltig($v) ? $v : '';
        }
    }
    return '';
}

/** Stufe 3: VERSION= aus dem Abschnitt [PLUGIN] einer plugin.cfg, oder ''. */
function plugin_fassung_aus_cfg($datei)
{
    if (!@is_file($datei) || !@is_readable($datei)) {
        return '';
    }
    $zeilen = @file($datei, FILE_IGNORE_NEW_LINES);
    if (!is_array($zeilen)) {
        return '';
    }
    $abschnitt = '';
    foreach ($zeilen as $i => $z) {
        $z = trim((string) $z);
        if ($i === 0) {
            $z = preg_replace('/^\xEF\xBB\xBF/', '', $z);
        }
        if ($z === '') {
            continue;
        }
        if (preg_match('/^\[([^\]]*)\]\z/', $z, $m)) {
            $abschnitt = strtoupper(trim($m[1]));
            continue;
        }
        if ($abschnitt === 'PLUGIN' && preg_match('/^VERSION\s*=\s*(.*)\z/', $z, $m)) {
            $v = trim(trim($m[1]), "\"'");
            return plugin_fassung_gueltig($v) ? $v : '';
        }
    }
    return '';
}

/**
 * Die drei Stufen, ohne Zwischenspeicher. $abfrage: null oder eine Funktion
 * function ($ordner) -> Antwort von LBSystem::pluginversion(). Rueckgabe
 * array(fassung, herkunft). Fuer plugin_fassung() und den Selbsttest.
 */
function plugin_fassung_bestimmen($verzeichnis, $abfrage)
{
    $pfad = plugin_fassung_pfad($verzeichnis);
    $ordner = basename($pfad);
    if ($abfrage !== null && is_callable($abfrage) && $ordner !== '') {
        $a = null;
        try {
            $a = @call_user_func($abfrage, $ordner);
        } catch (Throwable $e) {
            $a = null;
        }
        if (is_scalar($a)) {
            $a = trim((string) $a);
            if (plugin_fassung_gueltig($a)) {
                return array($a, 'lbsystem');
            }
        }
    }
    $v = plugin_fassung_aus_datenbank(plugin_fassung_wurzel($pfad), $ordner);
    if ($v !== '') {
        return array($v, 'datenbank');
    }
    foreach (array(dirname($pfad), dirname(dirname($pfad))) as $d) {
        $v = plugin_fassung_aus_cfg($d . '/plugin.cfg');
        if ($v !== '') {
            return array($v, 'plugincfg');
        }
    }
    return array('', '');
}

/** Zwischengespeichert je Verzeichnis: array(fassung, herkunft). */
function plugin_fassung_eintrag($verzeichnis)
{
    static $ablage = array();
    $pfad = plugin_fassung_pfad($verzeichnis);
    if (!isset($ablage[$pfad])) {
        $abfrage = null;
        if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
            $abfrage = function ($ordner) { return LBSystem::pluginversion($ordner); };
        }
        $ablage[$pfad] = plugin_fassung_bestimmen($pfad, $abfrage);
    }
    return $ablage[$pfad];
}

/** Die installierte Fassung des Plugins von $verzeichnis, oder '' (nichts gefunden). */
function plugin_fassung($verzeichnis = null)
{
    $e = plugin_fassung_eintrag($verzeichnis);
    return $e[0];
}

/** Welche Stufe geantwortet hat: 'lbsystem', 'datenbank', 'plugincfg' oder ''. */
function plugin_fassung_herkunft($verzeichnis = null)
{
    $e = plugin_fassung_eintrag($verzeichnis);
    return $e[1];
}

/** Titel der Kopfzeile in der Form aller Linien: "<Titel> <Fassung>", ohne Fassung nur der Titel. */
function plugin_kopftitel($titel, $verzeichnis = null)
{
    $t = is_scalar($titel) ? (string) $titel : '';
    $v = plugin_fassung($verzeichnis);
    return $v === '' ? $t : $t . ' ' . $v;
}

/**
 * Selbsttest an Zeitordnern unter sys_get_temp_dir(). Rueckgabe
 * array(faelle, fehlschlaege, zeilen). Schreibt nur in den eigenen
 * Zeitordner und raeumt ihn ab.
 */
function plugin_fassung_selbsttest()
{
    $n = 0; $f = 0; $z = array();
    $pruef = function ($name, $ist, $soll) use (&$n, &$f, &$z) {
        $n++;
        $ok = ($ist === $soll);
        if (!$ok) { $f++; }
        $z[] = ($ok ? 'ok   ' : 'FEHL ') . $name . ($ok ? '' : ' (ist ' . var_export($ist, true) . ', soll ' . var_export($soll, true) . ')');
    };
    $tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/fassung_selbsttest_' . getmypid() . '_' . mt_rand(1000, 9999);
    $baue = function ($rel, $inhalt) use ($tmp) {
        $p = $tmp . '/' . $rel;
        if (!is_dir(dirname($p))) { @mkdir(dirname($p), 0700, true); }
        @file_put_contents($p, $inhalt);
    };
    $cfg = "[AUTHOR]\nNAME=Pruef\n# Kommentar mit ! Ausrufezeichen\n[PLUGIN]\nVERSION=1.2.3\nNAME=pruef\nFOLDER=pruef\n";
    // Arbeitsordner-Lage: plugin.cfg zwei Ebenen ueber webfrontend/htmlauth, eine ueber bin
    $baue('arch/plugin.cfg', $cfg);
    $baue('arch/webfrontend/htmlauth/x', '');
    $baue('arch/webfrontend/html/x', '');
    $baue('arch/bin/x', '');
    $pruef('archiv htmlauth', plugin_fassung_bestimmen($tmp . '/arch/webfrontend/htmlauth', null), array('1.2.3', 'plugincfg'));
    $pruef('archiv html', plugin_fassung_bestimmen($tmp . '/arch/webfrontend/html', null), array('1.2.3', 'plugincfg'));
    $pruef('archiv bin', plugin_fassung_bestimmen($tmp . '/arch/bin', null), array('1.2.3', 'plugincfg'));
    $pruef('archiv mit Rueckstrich', plugin_fassung_bestimmen(str_replace('/', '\\', $tmp . '/arch/webfrontend/htmlauth/'), null), array('1.2.3', 'plugincfg'));
    // LBSystem antwortet: geht vor, mit dem Ordnernamen gefragt
    $gefragt = array();
    $ab = function ($o) use (&$gefragt) { $gefragt[] = $o; return '9.8.7'; };
    $pruef('lbsystem vor plugin.cfg', plugin_fassung_bestimmen($tmp . '/arch/webfrontend/htmlauth', $ab), array('9.8.7', 'lbsystem'));
    $pruef('lbsystem mit Ordnername gefragt', $gefragt, array('htmlauth'));
    // LBSystem ohne Antwort, mit Unsinn, mit Ausnahme: naechste Stufe
    $pruef('lbsystem null', plugin_fassung_bestimmen($tmp . '/arch/webfrontend/htmlauth', function ($o) { return null; }), array('1.2.3', 'plugincfg'));
    $pruef('lbsystem leer', plugin_fassung_bestimmen($tmp . '/arch/webfrontend/htmlauth', function ($o) { return ''; }), array('1.2.3', 'plugincfg'));
    $pruef('lbsystem html-zeichen', plugin_fassung_bestimmen($tmp . '/arch/webfrontend/htmlauth', function ($o) { return '1.0<b>'; }), array('1.2.3', 'plugincfg'));
    $pruef('lbsystem feld', plugin_fassung_bestimmen($tmp . '/arch/webfrontend/htmlauth', function ($o) { return array('1.0'); }), array('1.2.3', 'plugincfg'));
    $pruef('lbsystem ausnahme', plugin_fassung_bestimmen($tmp . '/arch/webfrontend/htmlauth', function ($o) { throw new Exception('x'); }), array('1.2.3', 'plugincfg'));
    $pruef('lbsystem leerraum', plugin_fassung_bestimmen($tmp . '/arch/webfrontend/htmlauth', function ($o) { return " 2.0.1\n"; }), array('2.0.1', 'lbsystem'));
    // Installierte Lage: keine plugin.cfg, Datenbank ueber den Ordnernamen (Wurzel aus der Pfadgestalt)
    $alt = getenv('LBHOMEDIR');
    putenv('LBHOMEDIR');
    $db = json_encode(array('plugins' => array(
        'aaaa' => array('folder' => 'anderes', 'version' => '5.5.5'),
        'bbbb' => array('folder' => 'pruef', 'version' => '4.3.2'))));
    $baue('lb/data/system/plugindatabase.json', $db);
    foreach (array('webfrontend/htmlauth', 'webfrontend/html', 'bin') as $baum) {
        $baue('lb/' . $baum . '/plugins/pruef/x', '');
        $pruef('installiert ' . $baum, plugin_fassung_bestimmen($tmp . '/lb/' . $baum . '/plugins/pruef', null), array('4.3.2', 'datenbank'));
    }
    $pruef('installiert, Pfad mit Rueckstrichen', plugin_fassung_bestimmen(str_replace('/', '\\', $tmp . '/lb/webfrontend/htmlauth/plugins/pruef'), null), array('4.3.2', 'datenbank'));
    $baue('lb/webfrontend/htmlauth/plugins/fehlt/x', '');
    $pruef('installiert, Ordner nicht in der Datenbank', plugin_fassung_bestimmen($tmp . '/lb/webfrontend/htmlauth/plugins/fehlt', null), array('', ''));
    $baue('lb/webfrontend/htmlauth/plugins/pruef/sub/x', '');
    $pruef('Unterordner statt Baum: nichts', plugin_fassung_bestimmen($tmp . '/lb/webfrontend/htmlauth/plugins/pruef/sub', null), array('', ''));
    // LBHOMEDIR geht vor der Pfadgestalt
    $baue('lb2/data/system/plugindatabase.json', json_encode(array('plugins' => array('c' => array('folder' => 'pruef', 'version' => '7.0.0')))));
    putenv('LBHOMEDIR=' . $tmp . '/lb2');
    $pruef('LBHOMEDIR', plugin_fassung_bestimmen($tmp . '/lb/webfrontend/htmlauth/plugins/pruef', null), array('7.0.0', 'datenbank'));
    putenv('LBHOMEDIR');
    // kaputte Datenbank, ungueltige Fassung in der Datenbank
    $baue('lb3/data/system/plugindatabase.json', '{kaputt');
    $baue('lb3/webfrontend/htmlauth/plugins/pruef/x', '');
    $pruef('datenbank kaputt', plugin_fassung_bestimmen($tmp . '/lb3/webfrontend/htmlauth/plugins/pruef', null), array('', ''));
    $baue('lb4/data/system/plugindatabase.json', json_encode(array('plugins' => array('c' => array('folder' => 'pruef', 'version' => 'V1.0')))));
    $baue('lb4/webfrontend/htmlauth/plugins/pruef/x', '');
    $pruef('datenbank ungueltige fassung', plugin_fassung_bestimmen($tmp . '/lb4/webfrontend/htmlauth/plugins/pruef', null), array('', ''));
    if ($alt !== false) { putenv('LBHOMEDIR=' . $alt); }
    // plugin.cfg-Varianten
    $varianten = array(
        'crlf und BOM' => array("\xEF\xBB\xBF[PLUGIN]\r\nVERSION=3.0.1\r\n", '3.0.1'),
        'anfuehrungszeichen' => array("[PLUGIN]\nVERSION=\"3.0.2\"\n", '3.0.2'),
        'leerraum um =' => array("[PLUGIN]\nVERSION = 3.0.3\n", '3.0.3'),
        'VERSION in anderem abschnitt' => array("[AUTHOR]\nVERSION=9.9.9\n[PLUGIN]\nNAME=x\n", ''),
        'kommentierte VERSION' => array("[PLUGIN]\n#VERSION=1.0.0\n", ''),
        'ungueltige VERSION' => array("[PLUGIN]\nVERSION=V1.2\n", ''),
        'leere VERSION' => array("[PLUGIN]\nVERSION=\n", ''),
        'abschnitt klein' => array("[plugin]\nVERSION=3.0.4\n", '3.0.4'),
    );
    $i = 0;
    foreach ($varianten as $name => $vw) {
        $i++;
        $baue('cfg' . $i . '/plugin.cfg', $vw[0]);
        $baue('cfg' . $i . '/webfrontend/htmlauth/x', '');
        $pruef('plugin.cfg ' . $name, plugin_fassung_bestimmen($tmp . '/cfg' . $i . '/webfrontend/htmlauth', null)[0], $vw[1]);
    }
    $pruef('keine plugin.cfg', plugin_fassung_bestimmen($tmp . '/nichts/da', null), array('', ''));
    // Kopftitel: genau ein Leerzeichen, kein V, Titel unveraendert, ohne Fassung nur der Titel
    $pruef('kopftitel mit fassung', plugin_kopftitel('Abfahrts-Assistent', $tmp . '/arch/webfrontend/htmlauth'), 'Abfahrts-Assistent 1.2.3');
    $pruef('kopftitel titel unveraendert', plugin_kopftitel('Abfuhrkalender (AWM &amp; iCal)', $tmp . '/arch/webfrontend/htmlauth'), 'Abfuhrkalender (AWM &amp; iCal) 1.2.3');
    $pruef('kopftitel ohne fassung', plugin_kopftitel('Titel', $tmp . '/nichts/da'), 'Titel');
    if (!class_exists('LBSystem', false)) {
        $pruef('herkunft plugincfg', plugin_fassung_herkunft($tmp . '/arch/webfrontend/htmlauth'), 'plugincfg');
    }
    $pruef('herkunft keine', plugin_fassung_herkunft($tmp . '/nichts/da'), '');
    $pruef('fassung des moduls gueltig', plugin_fassung_gueltig(PLUGIN_FASSUNG_MODUL), true);
    // aufraeumen
    $weg = function ($d) use (&$weg) {
        foreach ((array) @scandir($d) as $e) {
            if ($e === '.' || $e === '..' || $e === false) { continue; }
            $p = $d . '/' . $e;
            if (is_dir($p)) { $weg($p); } else { @unlink($p); }
        }
        @rmdir($d);
    };
    $weg($tmp);
    $pruef('zeitordner abgeraeumt', is_dir($tmp), false);
    return array($n, $f, $z);
}

}   // Ende des Blocks "noch keine Abschrift geladen"

/* Aufruf von der Kommandozeile: php fassung.php --selbsttest */
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)
    && in_array('--selbsttest', $argv, true)) {
    list($plugin_fassung_n, $plugin_fassung_f, $plugin_fassung_z) = plugin_fassung_selbsttest();
    foreach ($plugin_fassung_z as $plugin_fassung_x) { echo $plugin_fassung_x, "\n"; }
    printf("Fassungsanzeige %s: %d Faelle geprueft, %d Fehlschlaege.\n", PLUGIN_FASSUNG_MODUL, $plugin_fassung_n, $plugin_fassung_f);
    exit($plugin_fassung_f === 0 ? 0 : 1);
}
