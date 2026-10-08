<?php
/**
 * BYD Autos - Ansage auf Zuruf des Abrufdienstes (Nr. 36 b, Stufe 2, seit 0.9.23)
 *
 * Aufruf:  php by_ansage.php <Pluginordner>      (Auftrag als JSON auf der Standardeingabe)
 *
 * Der Dienst bin/byd.py ist in Python geschrieben; die gemeinsame Sprachausgabe
 * der Plugins dieses Hauses (sprachausgabe.php neben by_lib.php) gibt es nur in
 * PHP. Diese Bruecke nimmt einen Anlass entgegen, baut den Satz aus der
 * Sprachdatei (Abschnitt BY_ANSAGE) und spricht ihn mit ansage_cli() ueber die
 * eingestellte Ausgabeart (Block tts der Konfiguration, ab Werk aus).
 *
 * Der Auftrag kommt auf der STANDARDEINGABE, nie auf der Kommandozeile - die
 * sieht jeder in der Prozessliste:
 *   {"anlass": "laden_fertig", "nr": 1, "name": "Seal", "soc": 80, "grenze": 80}
 * Angenommen werden nur die Anlaesse aus by_ansage_anlaesse(); der Satz entsteht
 * hier, der Dienst schickt keinen freien Text.
 *
 * Antwort: EINE Zeile ohne Text und ohne Token, z. B.
 *   ANSAGE;STAND=1;ART=musicserver;KENNUNG=-;HTTP=200;ZEICHEN=39
 * Rueckgabewert wie ansage_cli(): 0 gesendet, 1 gescheitert, 3 nichts gesendet
 * ohne Fehler (Ausgabe aus), 2 Aufruf falsch. 1 auch, wenn die Bibliothek fehlt.
 *
 * Der Pluginordner wird mitgegeben wie bei den *_notify.php-Stuecken anderer
 * Linien: dem Dienst koennen die LoxBerry-Umgebungsvariablen fehlen, und bei
 * einer Zweitinstallation heisst der Ordner bydautos_01.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "ANSAGE;OK=0;GRUND=KEIN_ENDPUNKT\n";
    exit;
}

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen - dieselbe Regel
 * wie by_lib.php. DIESER BLOCK STEHT VOR SEINEM AUFRUF: PHP zieht Funktionen in
 * einem if-Block nicht vor. */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

$by_home = getenv('LBHOMEDIR');
if (!$by_home || !is_dir($by_home . '/config/plugins') || !is_dir($by_home . '/data/plugins')) {
    $by_home = lb_wurzel_ermitteln();
}
$by_paket = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[1]) : '';
if ($by_paket === '') {
    $by_paket = preg_replace('/[^A-Za-z0-9_\-]/', '', basename(rtrim((string) getenv('LBPPLUGINDIR'), '/')));
}
if ($by_paket === '') {
    $by_paket = 'bydautos';
}

/* Die Bibliothek: installiert unter <home>/webfrontend/html/plugins/<ordner>/,
 * im Archiv unter ../webfrontend/html/. */
$by_lib = '';
foreach (array(
    $by_home ? $by_home . '/webfrontend/html/plugins/' . $by_paket . '/by_lib.php' : '',
    dirname(__DIR__) . '/webfrontend/html/by_lib.php',
) as $by_kandidat) {
    if ($by_kandidat !== '' && is_file($by_kandidat)) {
        $by_lib = $by_kandidat;
        break;
    }
}
if ($by_lib === '') {
    fwrite(STDERR, "by_lib.php nicht gefunden - es wurde nichts angesagt.\n");
    echo "ANSAGE;STAND=0;KENNUNG=BIBLIOTHEK\n";
    exit(1);
}
/* Die Sprache des LoxBerry (Base.Lang) kennt erst LBSystem. */
if ($by_home && is_file($by_home . '/libs/phplib/loxberry_system.php')) {
    require_once $by_home . '/libs/phplib/loxberry_system.php';
}
require_once $by_lib;

$by_roh = stream_get_contents(STDIN);
$by_d = is_string($by_roh) && strlen($by_roh) <= 4096 ? json_decode($by_roh, true) : null;
$by_anlaesse = by_ansage_anlaesse();
$by_zahl = function ($w) {
    if ($w === null) { return null; }
    if (!is_int($w) && !is_float($w)) { return false; }
    $i = (int) round((float) $w);
    return ($i >= 0 && $i <= 100) ? $i : false;
};
$by_ok = is_array($by_d)
    && isset($by_d['anlass']) && is_string($by_d['anlass']) && isset($by_anlaesse[$by_d['anlass']])
    && isset($by_d['nr']) && is_int($by_d['nr']) && $by_d['nr'] >= 0 && $by_d['nr'] <= 99
    && (!isset($by_d['name']) || is_string($by_d['name']));
$by_soc = $by_ok ? $by_zahl(isset($by_d['soc']) ? $by_d['soc'] : null) : false;
$by_grenze = $by_ok ? $by_zahl(isset($by_d['grenze']) ? $by_d['grenze'] : null) : false;
if (!$by_ok || $by_soc === false || $by_grenze === false) {
    fwrite(STDERR, "Auftrag fehlt, ist kein JSON oder nennt einen unbekannten Anlass.\n");
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(2);
}
/* Der Fahrzeugname kommt aus dem Konto; nur Steuerzeichen fallen weg, und er
 * wird auf 60 Zeichen begrenzt - er wird gesprochen, nicht gespeichert. */
$by_name = isset($by_d['name']) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $by_d['name'])) : '';
if (preg_match('//u', $by_name) !== 1) {
    $by_name = '';
}
if (function_exists('mb_substr')) {
    $by_name = mb_substr($by_name, 0, 60, 'UTF-8');
} elseif (strlen($by_name) > 60) {
    $by_name = '';
}

$by_text = by_ansage_satz($by_d['anlass'], $by_d['nr'], $by_name, $by_soc, $by_grenze);
list($by_rc, $by_zeile) = ansage_cli(json_encode(array('text' => $by_text), JSON_UNESCAPED_UNICODE),
                                     by_tts(false), by_ansage_k());
echo $by_zeile, "\n";
exit($by_rc);
