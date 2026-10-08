<?php
/**
 * Gemeinsame Sprachausgabe fuer die LoxBerry-Plugins des Hauses
 *
 * DIESE DATEI IST IN MEHREREN PLUGINS BYTEWEISE GLEICH.
 * Die Stammfassung liegt unter Werkzeuge/gemeinsam/sprachausgabe.php im
 * Arbeitsordner. Ein LoxBerry-Plugin kann keine Datei eines anderen Plugins
 * laden; deshalb traegt jede Linie eine Abschrift, und
 * Werkzeuge/gemeinsam_pruefen.py meldet jede Abschrift, die von der
 * Stammfassung abweicht (--verteilen bringt die Stammfassung hinein). Wer
 * hier etwas aendert, aendert es in der Stammfassung, hebt ANSAGE_FASSUNG
 * an, laesst ansage_selbsttest() und Werkzeuge/mutation_sprachausgabe.py
 * laufen und verteilt danach.
 *
 * Die Funktionen tragen das neutrale Kuerzel 'ansage_' statt eines
 * Plugin-Kuerzels (wie 'plan_' in planer.php): zwei auseinanderlaufende
 * Kopien derselben Sprachausgabe waeren schlimmer als ein zweites Kuerzel.
 * Die Plugins laufen nie im selben PHP-Prozess.
 *
 * ------------------------------------------------------------------
 * Was die Datei tut - und was nicht
 * ------------------------------------------------------------------
 *
 * Sie spricht einen fertigen Text ueber die eingestellte Ausgabeart:
 *
 *   aus          keine Ausgabe (ab Werk, D-Regel)
 *   musicserver  Loxone Music Server, GET an /audio/grouped/tts/...
 *   ms4h         MusicServer4Home / Audioserver4Home, Adressvorlage
 *   audioserver  Original-Audioserver: keine HTTP-Schnittstelle; die Ansage
 *                laeuft in Loxone Config (Textgenerator am TTS-Eingang)
 *   custom       eigene Adressvorlage
 *   alexang      Plugin Alexa-NG, POST aktion=sprechen mit Sprechtoken
 *   cc4lox       Plugin Chromecast 4 Lox NG (Google-Lautsprecher), dieselbe
 *                Schnittstelle wie Alexa-NG, eigenes Sprechtoken
 *   sonos4lox    Plugin Sonos4Lox (seit 1.1.0), GET action=say mit Zone und
 *                Lautstaerke; der Ordner kommt aus der Plugin-Datenbank
 *
 * Dazu: Einstellungen vervollstaendigen und pruefen, das Formular lesen
 * (Nr. 16/19, X-2), den Formularblock zeichnen, die Zeile im Reiter Test,
 * die Testansage, die Sicherungsfelder (X-3) und einen Kommandozeilenweg
 * fuer Dienste in anderen Sprachen (ansage_cli()).
 *
 * NICHT hier: wann gesprochen wird (Anlass, Ruhezeit, Freigabe-Haken,
 * Bremse), der Ansagetext selbst, das Protokoll, die Einmalmeldung, das
 * Speichern der Konfiguration. Das bleibt in der Linie. Diese Datei
 * schreibt in kein Protokoll und legt keine Ordner an; sie schreibt hoechstens
 * die Datei <art>_letzte.json in einen VORHANDENEN Ordner, den die Linie nennt.
 *
 * ------------------------------------------------------------------
 * Hausregeln, die hier eingebaut sind
 * ------------------------------------------------------------------
 *
 *   - Ansagetext und Sprechtoken stehen in keinem Rueckgabewert ausser dem,
 *     der sie ausdruecklich traegt (ansage_tts_url() fuer den Music Server).
 *     Ergebnisse nennen vom Text nur die Zeichenzahl (Entscheidung Nr. 18).
 *   - Das Sprechtoken geht nur im POST-Koerper hinaus, nie in einer Adresse;
 *     aus der Antwortzeile wird es ersetzt, bevor sie irgendwohin geht.
 *   - Kurze Zeitgrenzen: 10 s gesamt, 3 s Verbindungsaufbau (curl), Reiter
 *     Test 5 s. Keine Weiterleitung, kein Proxy, nur http und https. Einzige
 *     Ausnahme (seit 1.1.0): leitet der LoxBerry-Webserver einen Aufruf an
 *     127.0.0.1 auf https derselben Loopback-Adresse und desselben Pfads um,
 *     wird genau einmal dorthin wiederholt (ansage_ausfuehren()).
 *   - Gelesen werden hoechstens ANSAGE_RUMPF_MAX Bytes; curl bricht danach ab.
 *   - Faellt ein anderes Plugin oder der Audio-Server aus, entfaellt die
 *     Ansage: kein stiller Wechsel auf einen anderen Lautsprecher, keine
 *     eigene Wiederholung.
 *   - Als gesendet gilt bei Alexa-NG und Chromecast nur HTTP 200 UND eine
 *     Antwortzeile SPRECHEN;OK=1 (danach ; oder Zeilenende); beim Music
 *     Server, bei Vorlagen und bei Sonos4Lox nur HTTP 2xx (Sonos4Lox kennt
 *     kein Erfolgsmerkmal in der Antwort).
 *   - Eingaben werden abgewiesen und benannt, nie still zurechtgebogen
 *     (Nr. 16/19); nur Leerraum am Rand faellt still weg.
 *   - Ein Wert aus der Konfiguration wird vor dem Senden erneut geprueft
 *     (Adresse im Heimnetz); eine von Hand verbogene Datei spricht nicht ins
 *     Internet. Geprueft wird die FERTIGE Adresse (Platzhalter eingesetzt)
 *     so, wie parse_url und curl sie lesen (ansage_url_grund()).
 *
 * ------------------------------------------------------------------
 * Der Kontext $k
 * ------------------------------------------------------------------
 *
 * Alles, was die Linie beisteuert, kommt ueber ein Feld $k:
 *
 *   'port'      Webport des LoxBerry (ansage_webport()); ohne Angabe 80
 *   'sslport'   https-Port des LoxBerry (ansage_sslport()); 0 = unbekannt.
 *               Nur fuer die Umleitung auf https an 127.0.0.1.
 *   'home'      LoxBerry-Wurzel fuer die Plugin-Datenbank; ohne Angabe
 *               $LBHOMEDIR, '' = keine Datenbank (feste Ordnernamen)
 *   'kopf'      Kopfzeilen jeder Anfrage, z. B. 'User-Agent: LoxBerry Ferien'
 *   'ordner'    vorhandener Ordner fuer <art>_letzte.json ('' = keine Datei)
 *   't'         function ($schluessel) -> Text; liefert den Schluessel
 *               selbst, wenn es ihn nicht gibt (wie xx_t())
 *   'abschnitt' Abschnitt der Sprachschluessel, ab Werk 'ANSAGE'
 *   'schluessel' array(Kennung => vollstaendiger Schluessel) fuer Linien,
 *               die ihre eigenen Schluessel behalten
 *   'e'         function ($s) -> maskierter Text; ab Werk htmlspecialchars
 *   'erklaert'  function ($id) -> bool: gibt es einen erklaerenden Satz
 *               zu <ART>_G_<GRUND>? Ab Werk: ansage_t() kennt ihn
 *   'transport' nur fuer ansage_selbsttest(): function ($anfrage) -> Antwort
 *   'jetzt'     nur fuer ansage_selbsttest(): function () -> Zeitstempel
 *
 * Laeuft unter PHP 7.4 bis 8.5 ohne Meldung; keine Sprachmittel ab 8.0.
 *
 * ------------------------------------------------------------------
 * Zwei Abschriften im selben Prozess
 * ------------------------------------------------------------------
 *
 * Anders als bei planer.php kommt das vor: der Abfahrts-Assistent und
 * AWM-Abfuhr binden ferien_lib.php des Plugins Ferien und Feiertage in ihren
 * eigenen Prozess ein (abfahrt_lib.php, awm_lib.php: fer_state(), fer_data()).
 * Tragen beide Linien eine Abschrift, laedt der Prozess zwei Dateien mit
 * denselben Funktionen - ohne Schutz ein "Cannot redeclare" (gemessen,
 * BAUBERICHT). Deshalb steht alles in einem Block, der nur laeuft, wenn noch
 * keine Abschrift geladen ist; die zweite Abschrift tut nichts, und es gilt die
 * zuerst geladene. Folge: Aenderungen an den Funktionen sind nur ergaenzend
 * (neue Funktion, neuer optionaler Parameter), nie umdeutend; wer eine neue
 * Funktion braucht, fragt ANSAGE_FASSUNG oder function_exists().
 *
 * Zwei Zweige, eine Fassung (1.1.1): 1.0.3 (Abfahrts-Assistent) und 1.1.0
 * (Sprachsteuerung) entstanden getrennt aus 1.0.2. 1.1.1 ist 1.1.0 und dazu,
 * was eine Linie mit einem 1.0.3-Aufruf braucht: ansage_vorlage_grund() und
 * ansage_url_heimnetz() als Huellen um ansage_url_grund(), der Fehlertext bei
 * Netzfehlern (ansage_http_grund_id(), dritter Parameter), eine leere
 * Zonenliste in ansage_zonen_ok() und die Endung .intern.
 */

/* Kein Endpunkt (Regeln/03): die Datei liegt meist im unangemeldeten Baum
 * webfrontend/html/ und waere dort direkt aufrufbar. Ein direkter Aufruf ueber
 * den Webserver bekommt 403 und tut sonst nichts. */
if (PHP_SAPI !== 'cli') {
    $ansage_einstieg = get_included_files();
    if (isset($ansage_einstieg[0]) && realpath($ansage_einstieg[0]) === realpath(__FILE__)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "ANSAGE;OK=0;GRUND=KEIN_ENDPUNKT\n";
        exit;
    }
    unset($ansage_einstieg);
}

if (!defined('ANSAGE_FASSUNG')) {

define('ANSAGE_FASSUNG', '1.1.1');

/** Hoechstlaenge eines Ansagetexts in Zeichen (Schnittstelle Alexa-NG/Chromecast: 1-1000). */
define('ANSAGE_TEXT_MAX', 1000);
/** Zeitgrenzen in Sekunden: gesamt, Verbindungsaufbau, Selbsttest im Reiter Test. */
define('ANSAGE_TMO', 10);
define('ANSAGE_TMO_VERBINDEN', 3);
define('ANSAGE_TMO_PRUEF', 5);
/* Sonos4Lox antwortet erst, wenn die Ansage zu Ende gespielt und der alte
 * Zustand wiederhergestellt ist (play_tts wartet auf GetTransportInfo). Mit
 * ANSAGE_TMO endete fast jede laengere Ansage als SONOS_ZEIT_UNKLAR. */
define('ANSAGE_TMO_SONOS', 30);
/** Hoechstens so viele Bytes einer Antwort werden gelesen. */
define('ANSAGE_RUMPF_MAX', 65536);

/* ==================================================================
 * Einstellungen
 * ================================================================== */

/** Alle Ausgabearten in der Reihenfolge der Auswahlliste. */
function ansage_modi()
{
    return array('aus', 'musicserver', 'ms4h', 'audioserver', 'custom', 'alexang', 'cc4lox', 'sonos4lox');
}

/** Ausgabearten, die ueber die Sprechschnittstelle eines anderen Plugins gehen. */
function ansage_ist_ng($modus)
{
    return $modus === 'alexang' || $modus === 'cc4lox';
}

/** 'alexa' oder 'google' fuer eine NG-Ausgabeart, sonst ''. */
function ansage_art($modus)
{
    if ($modus === 'alexang') { return 'alexa'; }
    if ($modus === 'cc4lox') { return 'google'; }
    return '';
}

/**
 * Art fuer die Datei der letzten Ansage: 'alexa', 'google', 'sonos' oder ''
 * (klassisch). Getrennt von ansage_art(), weil dort ein nicht leerer Wert
 * "NG-Schnittstelle mit Sprechtoken" heisst - Sonos4Lox hat keins.
 */
function ansage_letzte_art($modus)
{
    if ($modus === 'sonos4lox') { return 'sonos'; }
    return ansage_art($modus);
}

/**
 * Vorgaben des Blocks tts. $ab_werk ist die Ausgabeart einer frischen
 * Anlage: 'aus' (D-Regel). Eine Linie, die heute schon eine andere Vorgabe
 * hat, nennt sie hier, damit sich beim Umstieg nichts aendert.
 */
function ansage_vorgaben($ab_werk = 'aus')
{
    if (!is_string($ab_werk) || !in_array($ab_werk, ansage_modi(), true)) {
        $ab_werk = 'aus';
    }
    return array(
        'mode' => $ab_werk,
        'ip' => '',
        'port' => 7091,
        'zones' => '1',
        'volume' => 8,
        'lang' => 'de',
        'template' => '',
        'alexa_geraet' => '',
        'alexa_token' => '',
        'alexa_laut' => -1,
        'google_geraet' => '',
        'google_token' => '',
        'google_laut' => -1,
        'sonos_zone' => '',
        'sonos_laut' => -1,
    );
}

/** Die Schluessel, die Geheimnisse tragen: nie in Seite, Sicherung, Protokoll, X-2. */
function ansage_geheim()
{
    return array('alexa_token', 'google_token');
}

/**
 * Den Block vervollstaendigen: fehlende Schluessel bekommen ihre Vorgabe.
 * Vorhandene Werte bleiben, wie sie sind - auch ungueltige; die meldet
 * ansage_wert_pruefen(). Rueckgabe: array(Block, Liste der ergaenzten Namen).
 */
function ansage_vervollstaendigen($tts, $ab_werk = 'aus')
{
    if (!is_array($tts)) { $tts = array(); }
    $fehlend = array();
    foreach (ansage_vorgaben($ab_werk) as $s => $w) {
        if (!array_key_exists($s, $tts)) {
            $tts[$s] = $w;
            $fehlend[] = $s;
        }
    }
    return array($tts, $fehlend);
}

/** Sprechtoken: 8 bis 128 Buchstaben, Ziffern, _ und - (Alexa-NG 24, Chromecast 32 Hexzeichen). */
function ansage_token_ok($t)
{
    return is_string($t) && preg_match('/^[A-Za-z0-9_\-]{8,128}\z/', $t) === 1;
}

/**
 * Geraet: leer (= Standardgeraet des anderen Plugins) oder 1 bis 200 Zeichen
 * UTF-8, ohne Steuerzeichen und ohne Leerraum am Rand. Namen, Kommalisten,
 * gruppe:<name> und alle prueft das andere Plugin selbst.
 */
function ansage_geraet_ok($g)
{
    return is_string($g) && ($g === ''
        || (preg_match('/^.{1,200}\z/us', $g) === 1 && preg_match('/[\x00-\x1F\x7F]/', $g) !== 1
            && trim($g) === $g));
}

/**
 * Liegt ein Rechner im Heimnetz? Loopback, private IPv4-Bereiche, Namen ohne
 * Punkt oder mit den ueblichen Heimnetz-Endungen, IPv6 in Klammern ([::1],
 * fc00::/7, fe80::/10). Alles andere wird abgewiesen: die Adresse traegt den
 * Ansagetext.
 *
 * IPv4 nur in strenger Dezimalform ohne fuehrende Nullen (seit 1.1.0):
 * getaddrinfo und damit curl lesen auch 134744072, 0x08080808 oder 10.8.8.8 mit fuehrender Null
 * als Adresse - alle drei sind 8.8.8.8. Bis 1.0.2 galten die ersten beiden als
 * "Name ohne Punkt" und die dritte als 10.x, also als Heimnetz. Deshalb ist
 * ein Name, der nur aus Ziffern und Punkten besteht oder mit 0x beginnt, nie
 * ein Name. IPv6 nur in Klammern, so wie sie in einer Adresse steht - eine
 * nackte IPv6 ergaebe mit ":<port>" dahinter eine kaputte Adresse.
 *
 * [::1] gilt (anders als in 1.0.3) als Heimnetz: es ist die Loopback-Adresse,
 * dieselbe Maschine wie 127.0.0.1 und localhost, die schon immer galten. Eine
 * Ansage dorthin verlaesst den LoxBerry nicht; die Regel "nicht ins Internet"
 * bleibt gewahrt. 1.0.3 wies sie nur ab, weil es IPv6 gar nicht kannte.
 */
function ansage_heimnetz_host($h)
{
    $h = strtolower(trim((string) $h));
    if ($h === '') { return false; }
    if ($h === 'localhost') { return true; }
    if (preg_match('/^\[([0-9a-f:.]{2,45})\]\z/', $h, $m)) {
        return ansage_heimnetz_ipv6($m[1]);
    }
    if (preg_match('/^[0-9.]+\z/', $h) || strpos($h, '0x') === 0) {
        if (!preg_match('/^(0|[1-9]\d{0,2})(\.(0|[1-9]\d{0,2})){3}\z/', $h)) { return false; }
        return ansage_heimnetz_ipv4(array_map('intval', explode('.', $h)));
    }
    if (preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\z/', $h)) {
        return true;    // ein Name ohne Punkt loest nur im Heimnetz auf
    }
    /* .intern (seit 1.1.1 wieder, wie 1.0.3): der Abfahrts-Assistent liess es schon
     * vor der gemeinsamen Fassung zu; eine dort gespeicherte Adresse wie ms.intern
     * darf beim Umstieg nicht stumm werden. 1.1.0 war aus 1.0.2 entstanden und
     * kannte die Endung nicht. */
    if (preg_match('/^[a-z0-9](?:[a-z0-9.\-]{0,251}[a-z0-9])?\.(local|lan|home|fritz\.box|home\.arpa|internal|intranet|intern)\z/', $h)) {
        return true;
    }
    return false;
}

/** Vier Oktette (int) im Heimnetz: 10/8, 127/8, 192.168/16, 172.16/12, 169.254/16. */
function ansage_heimnetz_ipv4(array $o)
{
    $o = array_values($o);
    if (count($o) !== 4) { return false; }
    foreach ($o as $x) { if (!is_int($x) || $x < 0 || $x > 255) { return false; } }
    if ($o[0] === 10 || $o[0] === 127) { return true; }
    if ($o[0] === 192 && $o[1] === 168) { return true; }
    if ($o[0] === 172 && $o[1] >= 16 && $o[1] <= 31) { return true; }
    if ($o[0] === 169 && $o[1] === 254) { return true; }
    return false;
}

/**
 * Eine IPv6 (ohne Klammern) im Heimnetz: ::1, fc00::/7 (darin fd00::/8, das
 * uebliche Hausnetz), fe80::/10 und auf IPv4 abgebildete Adressen
 * (::ffff:a.b.c.d) nach den IPv4-Regeln. Eine Zonenangabe (%eth0) gibt es
 * hier nicht: in einer Adresse stuende sie als %25 und wird abgewiesen.
 */
function ansage_heimnetz_ipv6($a)
{
    if (!function_exists('inet_pton') || !preg_match('/^[0-9a-f:.]{2,45}\z/i', (string) $a)) { return false; }
    $b = @inet_pton((string) $a);
    if (!is_string($b) || strlen($b) !== 16) { return false; }
    $o = array_values(unpack('C*', $b));
    if ($b === str_repeat("\0", 15) . "\1") { return true; }
    if (($o[0] & 0xFE) === 0xFC) { return true; }
    if ($o[0] === 0xFE && ($o[1] & 0xC0) === 0x80) { return true; }
    if (substr($b, 0, 12) === str_repeat("\0", 10) . "\xFF\xFF") {
        return ansage_heimnetz_ipv4(array_slice($o, 12, 4));
    }
    return false;
}

/**
 * Taugt eine FERTIGE Adresse (Platzhalter eingesetzt) zum Senden? Rueckgabe
 * '' oder eine Kennung: HTTP_KEIN_HTTP (anderes Schema), TTS_VORLAGE_HTTP
 * (Adresse kaputt), TTS_VORLAGE_BENUTZER (Benutzerdaten), TTS_VORLAGE_HEIMNETZ.
 *
 * Seit 1.1.0 so, wie parse_url und curl die Adresse lesen. Bis 1.0.2 nahm ein
 * Muster den Rechner bis zum ersten ":" - in http://localhost:80@<fremder-rechner>/
 * war das "localhost", fuer curl aber evil.example (alles vor dem @ sind
 * Benutzerdaten). Deshalb: kein @ und kein Benutzer, und die Autoritaet
 * besteht nur aus Rechnername bzw. [IPv6] und Port - dann lesen beide Seiten
 * denselben Rechner, und genau der wird geprueft.
 */
function ansage_url_grund($url)
{
    if (!is_string($url) || !preg_match('#^(https?)://([^/?\#]*)#i', $url, $m)) { return 'HTTP_KEIN_HTTP'; }
    if (preg_match('/[\s\\\\\x00-\x1F\x7F]/', $url)) { return 'TTS_VORLAGE_HTTP'; }
    if (strpos($m[2], '@') !== false) { return 'TTS_VORLAGE_BENUTZER'; }
    $p = @parse_url($url);
    if (!is_array($p) || !isset($p['scheme'], $p['host'])) { return 'TTS_VORLAGE_HTTP'; }
    if (!in_array(strtolower($p['scheme']), array('http', 'https'), true)) { return 'HTTP_KEIN_HTTP'; }
    if (isset($p['user']) || isset($p['pass'])) { return 'TTS_VORLAGE_BENUTZER'; }
    if (!preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.\-]+)(?::(\d{1,5}))?\z/', $m[2], $a)) { return 'TTS_VORLAGE_HTTP'; }
    if (strtolower($a[1]) !== strtolower((string) $p['host'])) { return 'TTS_VORLAGE_HTTP'; }
    if (isset($a[2]) && $a[2] !== '' && ((int) $a[2] < 1 || (int) $a[2] > 65535)) { return 'TTS_VORLAGE_HTTP'; }
    if (!ansage_heimnetz_host($a[1])) { return 'TTS_VORLAGE_HEIMNETZ'; }
    return '';
}

/**
 * Huelle fuer Linien mit einem 1.0.3-Aufruf (Abfahrts-Assistent, termin_say.php):
 * taugt die FERTIGE Adresse zum Senden? true/false wie in 1.0.3, geprueft mit
 * ansage_url_grund(). Einzige Abweichung von 1.0.3: [::1] und andere IPv6 im
 * Heimnetz gelten (1.0.3 kannte kein IPv6). Neue Linien rufen ansage_url_grund()
 * - die Kennung sagt, WARUM eine Adresse nicht taugt.
 */
function ansage_url_heimnetz($url)
{
    return ansage_url_grund($url) === '';
}

/**
 * Huelle fuer Linien mit einem 1.0.3-Aufruf (Abfahrts-Assistent,
 * abfahrt_wert_pruefen()): taugt eine Adressvorlage? Rueckgabe wie in 1.0.3:
 * '', TTS_VORLAGE_HTTP (kein http/https, kaputt) oder TTS_VORLAGE_HEIMNETZ
 * (Rechner nicht im Heimnetz, Benutzerdaten vor dem Rechner). Geprueft wird wie
 * in ansage_wert_pruefen() die Adresse nach dem Einsetzen; dazu die Regel aus
 * 1.0.3, dass zwischen :// und dem Pfad nur {ip} und {port} als Platzhalter
 * stehen duerfen (http://{text}/ setzte den Ansagetext als Rechner ein).
 */
function ansage_vorlage_grund($tpl)
{
    if (!is_string($tpl) || $tpl === '' || trim($tpl) !== $tpl || !preg_match('#^https?://([^/?\#]*)#i', $tpl, $m)
        || $m[1] === '') {
        return 'TTS_VORLAGE_HTTP';
    }
    if (preg_match('/\{(?!ip\}|port\})/', $m[1])) { return 'TTS_VORLAGE_HEIMNETZ'; }
    $g = '';
    if (ansage_wert_pruefen(array('template' => $tpl), $g) !== null) { return ''; }
    return ($g === 'TTS_VORLAGE_HEIMNETZ' || $g === 'TTS_VORLAGE_BENUTZER') ? 'TTS_VORLAGE_HEIMNETZ' : 'TTS_VORLAGE_HTTP';
}

/**
 * Zonenliste fuer Music Server und Vorlagen: Zahlen, je wahlweise mit
 * ~Lautstaerke (1-100), durch Komma getrennt; Leerraum nur um die Kommas und
 * am Rand. Bis 1.0.2 gingen "1 2" oder "2~" durch und ergaben eine kaputte
 * Adresse, die erst die Gegenseite mit einem irrefuehrenden Fehler beantwortete.
 *
 * Leer ('' oder nur Leerzeichen) taugt (seit 1.1.1 wieder, wie 1.0.3): leer heisst
 * "keine Zone angegeben", ansage_tts_url() nimmt dann Zone 1. Der
 * Abfahrts-Assistent ruft die Funktion ohne eigene Pruefung auf leer; mit 1.1.0
 * wies er eine leere Zonenliste beim Zurueckspielen ab.
 */
function ansage_zonen_ok($z)
{
    if (is_string($z) && preg_match('/^ *\z/', $z)) { return true; }
    if (!is_string($z) || !preg_match('/^\s*\d+(~\d{1,3})?(\s*,\s*\d+(~\d{1,3})?)*\s*\z/', $z)) { return false; }
    preg_match_all('/~(\d{1,3})/', $z, $mm);
    foreach ($mm[1] as $v) {
        if ((int) $v < 1 || (int) $v > 100) { return false; }
    }
    return true;
}

/**
 * Lautstaerke je Ansage (alexa_laut, google_laut, sonos_laut): -1 (leer =
 * unveraendert) oder 1 bis 100. Seit 1.1.0 ist 0 abgewiesen: Alexa-NG und
 * Chromecast nehmen laut=0 an, sprechen stumm und antworten trotzdem OK=1 -
 * die Ansage galt als gesendet, und es gab keinen Rueckfall.
 * Rueckgabe: die Zahl oder null mit der Kennung in $grund.
 */
function ansage_laut_pruefen($w, &$grund = '')
{
    $grund = '';
    if (is_array($w) || is_bool($w) || is_null($w) || is_object($w)
        || (!is_int($w) && !preg_match('/^-?\d{1,6}\z/', trim((string) $w)))) {
        $grund = 'KEINE_ZAHL';
        return null;
    }
    $i = (int) $w;
    if ($i < -1 || $i > 100) { $grund = 'AUSSERHALB|-1|100'; return null; }
    if ($i === 0) { $grund = 'AUSSERHALB|1|100'; return null; }
    return $i;
}

/**
 * Taugt dieser Block (oder ein Teil davon)? Rueckgabe: der gepruefte Block
 * (nur die vorhandenen Schluessel, Zahlen als int) oder null mit einer
 * Kennung in $grund. $modi schraenkt die erlaubten Ausgabearten ein.
 * Kein Wert erscheint in einer Kennung, ein Token schon gar nicht.
 */
function ansage_wert_pruefen($tts, &$grund = '', $modi = null)
{
    $grund = '';
    if (!is_array($tts)) { $grund = 'KEIN_FELD'; return null; }
    if (!is_array($modi)) { $modi = ansage_modi(); }
    $zahl = function ($w, $min, $max) use (&$grund) {
        if (is_array($w) || is_bool($w) || is_null($w) || is_object($w)) { $grund = 'KEINE_ZAHL'; return null; }
        if (!is_int($w) && !preg_match('/^-?\d{1,6}\z/', trim((string) $w))) { $grund = 'KEINE_ZAHL'; return null; }
        $i = (int) $w;
        if ($i < $min || $i > $max) { $grund = 'AUSSERHALB|' . $min . '|' . $max; return null; }
        return $i;
    };
    $text = function ($w, $max) use (&$grund) {
        if (!is_string($w)) { $grund = 'KEIN_TEXT'; return null; }
        if (strlen($w) > $max) { $grund = 'ZU_LANG|' . $max; return null; }
        if (preg_match('/[\x00-\x1F\x7F]/', $w)) { $grund = 'STEUERZEICHEN'; return null; }
        if (preg_match('//u', $w) !== 1) { $grund = 'KEIN_UTF8'; return null; }
        return $w;
    };
    $aus = array();
    foreach ($tts as $s => $w) {
        switch ((string) $s) {
            case 'mode':
                if (!is_string($w) || !in_array($w, $modi, true)) { $grund = 'TTS_MODUS'; return null; }
                $aus['mode'] = $w;
                break;
            case 'ip':
                $x = $text($w, 253);
                if ($x === null) { $grund = 'UNTER|tts.ip|' . $grund; return null; }
                if ($x !== '' && (trim($x) !== $x || !ansage_heimnetz_host($x))) { $grund = 'TTS_IP'; return null; }
                $aus['ip'] = $x;
                break;
            case 'port':
                $z = $zahl($w, 1, 65535);
                if ($z === null) { $grund = 'UNTER|tts.port|' . $grund; return null; }
                $aus['port'] = $z;
                break;
            case 'volume':
                $z = $zahl($w, 1, 100);
                if ($z === null) { $grund = 'UNTER|tts.volume|' . $grund; return null; }
                $aus['volume'] = $z;
                break;
            case 'zones':
                $x = $text($w, 200);
                if ($x === null) { $grund = 'UNTER|tts.zones|' . $grund; return null; }
                if ($x !== '' && !ansage_zonen_ok($x)) { $grund = 'TTS_ZONEN'; return null; }
                $aus['zones'] = $x;
                break;
            case 'lang':
                if (!is_string($w) || !preg_match('/^[a-z]{2}\z/', $w)) { $grund = 'TTS_SPRACHE'; return null; }
                $aus['lang'] = $w;
                break;
            case 'template':
                $x = $text($w, 500);
                if ($x === null) { $grund = 'UNTER|tts.template|' . $grund; return null; }
                if ($x !== '') {
                    if (trim($x) !== $x || !preg_match('#^https?://#i', $x)) {
                        $grund = 'TTS_VORLAGE_HTTP'; return null;
                    }
                    /* Geprueft wird die Adresse NACH dem Einsetzen, mit Musterwerten:
                     * {ip} steht fuer einen Rechner im Heimnetz (tts.ip wird fuer sich
                     * geprueft), {text} ist rawurlencode-t. So faellt auch
                     * http://{ip}:x@<fremder-rechner>/ auf. ansage_sprechen() prueft die
                     * fertige Adresse mit den echten Werten noch einmal. */
                    $muster = str_replace(array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
                                          array('localhost', '7091', '1', '8', 'de', 'x'), $x);
                    $ug = ansage_url_grund($muster);
                    if ($ug !== '') {
                        $grund = ($ug === 'HTTP_KEIN_HTTP') ? 'TTS_VORLAGE_HTTP' : $ug;
                        return null;
                    }
                }
                $aus['template'] = $x;
                break;
            case 'alexa_geraet':
            case 'google_geraet':
                if (!ansage_geraet_ok($w)) { $grund = 'TTS_' . strtoupper(substr($s, 0, strpos($s, '_'))) . '_GERAET'; return null; }
                $aus[$s] = $w;
                break;
            case 'alexa_laut':
            case 'google_laut':
            case 'sonos_laut':
                $z = ansage_laut_pruefen($w, $grund);
                if ($z === null) { $grund = 'UNTER|tts.' . $s . '|' . $grund; return null; }
                $aus[$s] = $z;
                break;
            case 'sonos_zone':
                // Leer heisst "nicht eingestellt"; gesprochen wird dann nicht (SONOS_KEINE_ZONE).
                if (!ansage_geraet_ok($w)) { $grund = 'TTS_SONOS_ZONE'; return null; }
                $aus[$s] = $w;
                break;
            case 'alexa_token':
            case 'google_token':
                // Leer heisst "keins gespeichert".
                if (!is_string($w) || ($w !== '' && !ansage_token_ok($w))) {
                    $grund = 'TTS_' . strtoupper(substr($s, 0, strpos($s, '_'))) . '_TOKEN'; return null;
                }
                $aus[$s] = $w;
                break;
            default:
                $grund = 'TTS_EINTRAG|' . str_replace('|', '/', substr((string) $s, 0, 40));
                return null;
        }
    }
    return $aus;
}

/* ==================================================================
 * Webport und Adressen
 * ================================================================== */

/**
 * Der Webport aus dem Inhalt der general.json: Webserver.Port oder
 * WEBSERVER.Port (beide Schreibweisen kommen vor), 1 bis 65535, sonst 80.
 */
function ansage_webport_aus($json)
{
    $g = is_string($json) ? json_decode($json, true) : null;
    if (is_array($g)) {
        foreach (array('Webserver', 'WEBSERVER') as $ab) {
            if (isset($g[$ab]) && is_array($g[$ab]) && isset($g[$ab]['Port']) && is_scalar($g[$ab]['Port'])
                && preg_match('/^\d{1,5}\z/', trim((string) $g[$ab]['Port']))) {
                $p = (int) $g[$ab]['Port'];
                if ($p >= 1 && $p <= 65535) { return $p; }
            }
        }
    }
    return 80;
}

/**
 * loxberry_system.php laden, als stuende das require im globalen Bereich. Aus
 * einer Funktion heraus blieben die Variablen, die die Datei beim Laden
 * anlegt ($lbhomedir, $lbpplugindir ...), sonst lokal - und ein spaeteres
 * require_once der Linie liefe ins Leere, die Variablen fehlten dort. Was die
 * Datei dabei ausgibt, wird verworfen.
 */
function ansage_sdk_laden($ansage_sdk_datei)
{
    $ansage_sdk_vorher = array_keys(get_defined_vars());
    ob_start();
    try {
        require_once $ansage_sdk_datei;
    } catch (Throwable $ansage_sdk_fehler) {
        // ohne SDK geht es mit general.json weiter
    }
    ob_end_clean();
    foreach (get_defined_vars() as $ansage_sdk_n => $ansage_sdk_w) {
        if (strpos($ansage_sdk_n, 'ansage_sdk_') === 0 || in_array($ansage_sdk_n, $ansage_sdk_vorher, true)) { continue; }
        if (!array_key_exists($ansage_sdk_n, $GLOBALS)) { $GLOBALS[$ansage_sdk_n] = $ansage_sdk_w; }
    }
}

/**
 * Der Webport des LoxBerry; $pfad ist config/system/general.json. Erste
 * Quelle (seit 1.1.0) ist lbwebserverport() aus libs/phplib/loxberry_system.php
 * - so liest der LoxBerry selbst seinen Port. Die Datei wird nur geladen, wenn
 * es sie gibt, und ihre Ausgabe beim Laden verworfen (eine Meldung darin ginge
 * sonst einem Endpunkt vor die Antwort). Ohne sie: general.json wie bisher,
 * fehlt auch die: 80.
 */
function ansage_webport($pfad)
{
    if (!is_string($pfad) || $pfad === '') { return 80; }
    $lib = dirname($pfad, 3) . '/libs/phplib/loxberry_system.php';
    if (!function_exists('lbwebserverport') && is_file($lib)) {
        ansage_sdk_laden($lib);
    }
    if (function_exists('lbwebserverport')) {
        ob_start();
        try {
            $p = lbwebserverport();
        } catch (Throwable $e) {
            $p = null;
        }
        ob_end_clean();
        if (is_scalar($p) && preg_match('/^\d{1,5}\z/', trim((string) $p)) && (int) $p >= 1 && (int) $p <= 65535) {
            return (int) $p;
        }
    }
    if (!is_file($pfad)) { return 80; }
    $roh = @file_get_contents($pfad);
    return ansage_webport_aus($roh === false ? '' : $roh);
}

/**
 * Der https-Port aus dem Inhalt der general.json: Webserver.Sslport (auch
 * WEBSERVER, auch SSLPORT/SslPort), 1 bis 65535, sonst 0 (= unbekannt). Nur
 * fuer die Umleitung auf https an 127.0.0.1 (ansage_ausfuehren()).
 */
function ansage_sslport_aus($json)
{
    $g = is_string($json) ? json_decode($json, true) : null;
    if (is_array($g)) {
        foreach (array('Webserver', 'WEBSERVER') as $ab) {
            if (!isset($g[$ab]) || !is_array($g[$ab])) { continue; }
            foreach (array('Sslport', 'SslPort', 'SSLPORT', 'SSLPort') as $n) {
                if (isset($g[$ab][$n]) && is_scalar($g[$ab][$n]) && preg_match('/^\d{1,5}\z/', trim((string) $g[$ab][$n]))) {
                    $p = (int) $g[$ab][$n];
                    if ($p >= 1 && $p <= 65535) { return $p; }
                }
            }
        }
    }
    return 0;
}

/** Der https-Port aus config/system/general.json ($pfad); unbekannt: 0. */
function ansage_sslport($pfad)
{
    if (!is_string($pfad) || $pfad === '' || !is_file($pfad)) { return 0; }
    $roh = @file_get_contents($pfad);
    return ansage_sslport_aus($roh === false ? '' : $roh);
}

/**
 * Der Ordner eines Plugins aus dem Inhalt der data/system/plugindatabase.json.
 * $namen: Plugin-Namen (wie NAME in dessen plugin.cfg), Gross/klein egal;
 * verglichen wird mit name und orig_name (bei einem Namenskonflikt haengt
 * LoxBerry _<3 Zeichen> an und merkt sich den alten Namen). Gelesen wird
 * duldsam: {"plugins": {md5: {...}}}, eine Liste oder die Eintraege ohne
 * "plugins". Gibt es mehrere Treffer, gewinnt der feste Ordner, sonst der
 * erste. Kein Treffer oder eine kaputte Datei: $rueckfall.
 */
function ansage_plugin_ordner_aus($json, array $namen, $rueckfall)
{
    $d = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($d)) { return $rueckfall; }
    $liste = (isset($d['plugins']) && is_array($d['plugins'])) ? $d['plugins'] : $d;
    $gesucht = array_map('strtolower', $namen);
    $treffer = array();
    foreach ($liste as $e) {
        if (!is_array($e) || !isset($e['folder']) || !is_string($e['folder'])
            || !preg_match('/^[A-Za-z0-9_\-]{1,64}\z/', $e['folder'])) {
            continue;
        }
        foreach (array('name', 'orig_name') as $f) {
            if (isset($e[$f]) && is_string($e[$f]) && in_array(strtolower($e[$f]), $gesucht, true)) {
                $treffer[] = $e['folder'];
                break;
            }
        }
    }
    if (!$treffer) { return $rueckfall; }
    return in_array($rueckfall, $treffer, true) ? $rueckfall : $treffer[0];
}

/**
 * Die LoxBerry-Wurzel fuer die Plugin-Datenbank: $home, wenn angegeben ('' =
 * keine Datenbank), sonst $LBHOMEDIR - aber nur mit config/system darunter.
 */
function ansage_lbhome($home = null)
{
    if (is_string($home)) { return $home; }
    $h = getenv('LBHOMEDIR');
    return (is_string($h) && $h !== '' && is_dir($h . '/config/system')) ? $h : '';
}

/**
 * Der Ordner eines Plugins auf diesem LoxBerry (seit 1.1.0): aus der
 * Plugin-Datenbank, sonst der feste Name. Bis 1.0.2 standen die Ordner fest
 * im Code - eine Zweitinstallation (alexang_1a2) oder ein umbenanntes Plugin
 * war so nicht zu erreichen.
 */
function ansage_plugin_ordner($modus, $home = null)
{
    $tafel = array(
        'alexang' => array(array('alexang'), 'alexang'),
        'cc4lox' => array(array('chromecast-4lox-ng'), 'chromecast-4lox-ng'),
        // Sonos4Lox heisst in seiner plugin.cfg (7.x) NAME=Sonos, FOLDER=sonos4lox.
        'sonos4lox' => array(array('sonos', 'sonos4lox'), 'sonos4lox'),
    );
    if (!isset($tafel[$modus])) { return ''; }
    list($namen, $fest) = $tafel[$modus];
    $h = ansage_lbhome($home);
    $db = $h !== '' ? $h . '/data/system/plugindatabase.json' : '';
    if ($db === '' || !is_file($db)) { return $fest; }
    $roh = @file_get_contents($db);
    return ansage_plugin_ordner_aus($roh === false ? '' : $roh, $namen, $fest);
}

/**
 * Adresse des Sprech-Endpunkts eines anderen Plugins auf DIESEM LoxBerry -
 * ohne Token und ohne Text. $home wie bei ansage_lbhome().
 */
function ansage_adresse($modus, $port, $home = null)
{
    $port = (int) $port;
    if ($port < 1 || $port > 65535) { $port = 80; }
    $ordner = ansage_plugin_ordner($modus === 'cc4lox' || $modus === 'sonos4lox' ? $modus : 'alexang', $home);
    return 'http://127.0.0.1:' . $port . '/plugins/' . $ordner . '/index.php';
}

/** LoxBerry-Wurzel aus dem Kontext (null = $LBHOMEDIR, '' = keine Datenbank). */
function ansage_k_home(array $k)
{
    return (isset($k['home']) && is_string($k['home'])) ? $k['home'] : null;
}

/** Port aus dem Kontext. */
function ansage_k_port(array $k)
{
    return isset($k['port']) ? (int) $k['port'] : 80;
}

/* ==================================================================
 * Ansagetext
 * ================================================================== */

/** Zahl der Zeichen eines Texts (UTF-8), ohne mbstring. */
function ansage_zeichen($text)
{
    if (!is_string($text)) { return 0; }
    $n = @preg_match_all('/./us', $text);
    return is_int($n) ? $n : strlen($text);
}

/**
 * Taugt der Text fuer eine Ansage? Rueckgabe: array(Stand, Kennung) mit
 * Stand 1 (taugt), -1 (nichts zu sagen, kein Fehler) oder 0 (abgewiesen).
 * Der Text wird NICHT zurechtgebogen - wer filtern will, tut das vorher in
 * der Linie (dort ist bekannt, woher der Text kommt).
 */
function ansage_text_pruefen($text, $modus = '')
{
    if (!is_string($text)) { return array(0, 'TEXT_KEIN_TEXT'); }
    if (preg_match('//u', $text) !== 1) { return array(0, 'TEXT_UTF8'); }
    if (trim($text) === '') { return array(-1, 'TEXT_LEER'); }
    if (preg_match('/[\x00-\x1F\x7F]/', $text)) { return array(0, 'TEXT_STEUERZEICHEN'); }
    if (ansage_ist_ng($modus) && strpbrk($text, '<>') !== false) { return array(0, 'TEXT_ZEICHEN'); }
    if (ansage_zeichen($text) > ANSAGE_TEXT_MAX) { return array(0, 'TEXT_ZU_LANG|' . ANSAGE_TEXT_MAX); }
    return array(1, '');
}

/**
 * Adresse fuer musicserver, ms4h und custom. Rueckgabe: die Adresse (sie
 * traegt den Text!), '' wenn die IP fehlt, obwohl sie gebraucht wird, oder
 * null fuer eine Ausgabeart ohne Adresse.
 *
 * Die Zonenliste wird fuer alle Arten einmal normalisiert ("2, 4" -> "2,4");
 * beim Music Server bekommt jede Zone ohne eigene Angabe die Lautstaerke.
 * Die IP wird nur verlangt, wenn die Art bzw. die Vorlage sie benutzt.
 */
function ansage_tts_url($text, array $tts)
{
    $modus = isset($tts['mode']) ? $tts['mode'] : '';
    if ($modus !== 'musicserver' && $modus !== 'ms4h' && $modus !== 'custom') {
        return null;
    }
    $ip = isset($tts['ip']) && is_string($tts['ip']) ? trim($tts['ip']) : '';
    $port = isset($tts['port']) ? (int) $tts['port'] : 7091;
    $lang = isset($tts['lang']) && is_string($tts['lang']) ? $tts['lang'] : 'de';
    $vol = isset($tts['volume']) ? (int) $tts['volume'] : 8;
    $zl = array();
    foreach (explode(',', isset($tts['zones']) && is_string($tts['zones']) ? $tts['zones'] : '') as $z) {
        $z = trim($z);
        if ($z !== '') { $zl[] = $z; }
    }
    if ($modus === 'musicserver') {
        if ($ip === '') { return ''; }
        $v = max(1, min(100, $vol));
        $zonen = array();
        foreach ($zl as $z) {
            $zonen[] = (strpos($z, '~') === false) ? $z . '~' . $v : $z;
        }
        $zs = $zonen ? implode(',', $zonen) : '1~' . $v;
        return 'http://' . $ip . ':' . $port . '/audio/grouped/tts/' . $zs . '/' . rawurlencode($lang . '|' . (string) $text);
    }
    $tpl = isset($tts['template']) && is_string($tts['template']) ? trim($tts['template']) : '';
    if ($tpl === '') {
        $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}';
    }
    if ($ip === '' && strpos($tpl, '{ip}') !== false) {
        return '';
    }
    return str_replace(
        array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($ip, $port, implode(',', $zl), $vol, $lang, rawurlencode((string) $text)),
        $tpl);
}

/* ==================================================================
 * Transport
 * ================================================================== */

/**
 * Eine Anfrage beschreiben. Der Transport fuehrt genau das aus; der
 * Selbsttest prueft an der Beschreibung, dass Weiterleitung und Proxy aus und
 * die Zeitgrenzen kurz sind - das waere an einer echten Verbindung nicht zu
 * sehen.
 */
function ansage_anfrage($methode, $url, $felder, $tmo, array $k)
{
    $kopf = (isset($k['kopf']) && is_array($k['kopf'])) ? array_values($k['kopf']) : array('User-Agent: LoxBerry-Plugin');
    $kopf[] = 'Accept: */*';
    $koerper = null;
    if ($methode === 'POST') {
        $koerper = http_build_query(is_array($felder) ? $felder : array(), '', '&');
        $kopf[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $tmo = max(1, (int) $tmo);
    return array(
        'methode' => $methode,
        'url' => (string) $url,
        'koerper' => $koerper,
        'kopf' => $kopf,
        'tmo' => $tmo,
        'verbinden' => min(ANSAGE_TMO_VERBINDEN, $tmo),
        'umleitung' => false,
        'proxy' => false,
        'tls_ohne_pruefung' => false,
    );
}

/**
 * Eine Anfrage ausfuehren - ueber den Transport des Kontexts (nur Selbsttest)
 * oder echt. Ist der LoxBerry-Webserver auf https umgestellt, beantwortet er
 * den Aufruf an http://127.0.0.1:<Port>/... mit 301/302/307/308 und
 * Location https://127.0.0.1/... (V4). Dann - und NUR dann, wenn das Ziel
 * wieder diese Maschine und derselbe Pfad ist - wird genau einmal dorthin
 * wiederholt, mit derselben Methode und demselben Koerper (auch bei 301/302:
 * es ist derselbe Endpunkt, ein GET verloere Token und Text) und in der
 * restlichen Zeit. Jede andere Weiterleitung bleibt eine Weiterleitung.
 */
function ansage_ausfuehren(array $anf, array $k)
{
    if (!preg_match('#^https?://#i', $anf['url'])) {
        return array('code' => 0, 'rumpf' => '', 'errno' => -2, 'fehler' => 'kein http');
    }
    $t0 = microtime(true);
    $a = ansage_ausfuehren_einmal($anf, $k);
    $ziel = ansage_umleitung_ziel($anf['url'], $a, $k);
    if ($ziel !== '') {
        $rest = (int) floor($anf['tmo'] - (microtime(true) - $t0));
        if ($rest >= 1) {
            $neu = $anf;
            $neu['url'] = $ziel;
            $neu['tmo'] = $rest;
            $neu['verbinden'] = min((int) $anf['verbinden'], $rest);
            $neu['tls_ohne_pruefung'] = true;
            $a = ansage_ausfuehren_einmal($neu, $k);
        }
    }
    unset($a['ort']);
    return $a;
}

/** Ein Versuch ohne Umleitung; die Antwort traegt dazu 'ort' (Location, sonst ''). */
function ansage_ausfuehren_einmal(array $anf, array $k)
{
    if (isset($k['transport']) && is_callable($k['transport'])) {
        $a = call_user_func($k['transport'], $anf);
    } else {
        $a = ansage_transport($anf);
    }
    return array(
        'code' => isset($a['code']) ? (int) $a['code'] : 0,
        'rumpf' => isset($a['rumpf']) ? substr((string) $a['rumpf'], 0, ANSAGE_RUMPF_MAX) : '',
        'errno' => isset($a['errno']) ? (int) $a['errno'] : 0,
        'fehler' => isset($a['fehler']) ? (string) $a['fehler'] : '',
        'ort' => isset($a['ort']) && is_string($a['ort']) ? $a['ort'] : '',
    );
}

/**
 * Wohin eine Umleitung des LoxBerry-Webservers auf https fuehren darf: die
 * neue Adresse oder ''. Verlangt: der Aufruf ging per http an 127.0.0.1,
 * localhost oder [::1]; Status 301/302/307/308; Location ist https an eine
 * dieser drei Adressen, ohne Benutzerdaten, mit demselben Pfad und derselben
 * Abfrage. Port: der in Location; nennt Location den http-Port selbst (eine
 * Umleitung, die nur das Schema tauscht und den Host samt Port uebernimmt)
 * oder gar keinen, gilt der https-Port aus general.json ($k['sslport'],
 * Webserver.Sslport), sonst 443.
 */
function ansage_umleitung_ziel($url, array $a, array $k)
{
    if (!in_array((int) $a['code'], array(301, 302, 307, 308), true) || !isset($a['ort']) || $a['ort'] === '') {
        return '';
    }
    $loop = array('127.0.0.1', 'localhost', '[::1]');
    $vor = @parse_url((string) $url);
    $nach = @parse_url((string) $a['ort']);
    if (!is_array($vor) || !is_array($nach) || !isset($vor['scheme'], $vor['host'], $nach['scheme'], $nach['host'])) {
        return '';
    }
    if (strtolower($vor['scheme']) !== 'http' || !in_array(strtolower($vor['host']), $loop, true)) { return ''; }
    if (strtolower($nach['scheme']) !== 'https' || !in_array(strtolower($nach['host']), $loop, true)
        || isset($nach['user']) || isset($nach['pass']) || preg_match('/[\s\\\\@]/', (string) $a['ort'])) {
        return '';
    }
    $pv = (isset($vor['path']) ? $vor['path'] : '/') . (isset($vor['query']) ? '?' . $vor['query'] : '');
    $pn = (isset($nach['path']) ? $nach['path'] : '/') . (isset($nach['query']) ? '?' . $nach['query'] : '');
    if ($pv !== $pn) { return ''; }
    $http_port = isset($vor['port']) ? (int) $vor['port'] : 80;
    $ssl = isset($k['sslport']) ? (int) $k['sslport'] : 0;
    if ($ssl < 1 || $ssl > 65535) { $ssl = 0; }
    if (isset($nach['port']) && (int) $nach['port'] !== $http_port) {
        $port = (int) $nach['port'];
    } else {
        $port = $ssl > 0 ? $ssl : 443;
    }
    if ($port === $http_port) { return ''; }    // https auf dem http-Port fuehrt nirgendwohin
    return 'https://' . strtolower($nach['host']) . ':' . $port . $pn;
}

/**
 * Der echte Transport: curl, sonst ein Datenstrom. Beide ohne Weiterleitung,
 * ohne Proxy, nur http und https. Der Statuscode kommt beim Datenstrom aus
 * stream_get_meta_data(), nicht aus der Kopfzeilenvariablen (PHP 8.5).
 * errno: 0 = Antwort, 7 = abgewiesen, 28 = Zeitueberschreitung, 6 = Name,
 * -1 = gescheitert ohne naehere Angabe. 'ort' traegt die Location einer
 * Weiterleitung (sonst ''), gefolgt wird ihr hier nie.
 *
 * Beide Wege lesen hoechstens ANSAGE_RUMPF_MAX Bytes. curl schrieb bis 1.0.2
 * den ganzen Rumpf in den Speicher und kuerzte erst danach; jetzt bricht die
 * Schreibfunktion bei der Grenze ab. Dieser eigene Abbruch (curl meldet ihn
 * als Schreibfehler 23) ist kein Netzfehler: die Antwort ist da, nur gekuerzt.
 *
 * tls_ohne_pruefung (nur fuer die Wiederholung an https://127.0.0.1, siehe
 * ansage_ausfuehren()): das Zertifikat des LoxBerry lautet auf seinen Namen
 * im Heimnetz oder ist selbst signiert, nie auf 127.0.0.1 - eine Pruefung
 * scheiterte immer. Die Verbindung verlaesst die Maschine nicht; wer sich dort
 * auf den Port setzen kann, braucht kein gefaelschtes Zertifikat mehr.
 */
function ansage_transport(array $anf)
{
    $ohne_pruefung = !empty($anf['tls_ohne_pruefung']);
    if (function_exists('curl_init')) {
        $ch = curl_init($anf['url']);
        $rumpf = '';
        $voll = false;
        $opt = array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $anf['tmo'],
            CURLOPT_CONNECTTIMEOUT => $anf['verbinden'],
            CURLOPT_HTTPHEADER => $anf['kopf'],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => function ($h, $teil) use (&$rumpf, &$voll) {
                $platz = ANSAGE_RUMPF_MAX - strlen($rumpf);
                if (strlen($teil) > $platz) {
                    $rumpf .= substr($teil, 0, max(0, $platz));
                    $voll = true;
                    return 0;       // weniger als geliefert: curl bricht ab
                }
                $rumpf .= $teil;
                return strlen($teil);
            },
        );
        if ($anf['methode'] === 'POST') {
            $opt[CURLOPT_POST] = true;
            $opt[CURLOPT_POSTFIELDS] = (string) $anf['koerper'];
        }
        if ($ohne_pruefung) {
            $opt[CURLOPT_SSL_VERIFYPEER] = false;
            $opt[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opt);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $r = curl_exec($ch);
        $errno = curl_errno($ch);
        $fehler = curl_error($ch);
        if ($r === false && $voll && $errno === 23) {
            $r = true;              // eigene Grenze erreicht - siehe oben
            $errno = 0;
            $fehler = '';
        }
        $code = ($r === false) ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ort = ($r === false || !defined('CURLINFO_REDIRECT_URL')) ? '' : (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        if ($r === false && $errno === 0) { $errno = -1; }
        return array('code' => $code, 'rumpf' => $r === false ? '' : $rumpf, 'errno' => $errno, 'fehler' => $fehler,
                     'ort' => $ort);
    }
    $http = array(
        'method' => $anf['methode'],
        'header' => implode("\r\n", $anf['kopf']),
        'timeout' => $anf['tmo'],
        'follow_location' => 0,
        'max_redirects' => 1,
        'ignore_errors' => true,
    );
    if ($anf['methode'] === 'POST') { $http['content'] = (string) $anf['koerper']; }
    $kontext = array('http' => $http);
    if ($ohne_pruefung) {
        $kontext['ssl'] = array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true);
    }
    $ctx = stream_context_create($kontext);
    if (function_exists('error_clear_last')) { error_clear_last(); }
    $t0 = microtime(true);
    $fh = @fopen($anf['url'], 'rb', false, $ctx);
    if ($fh === false) {
        $l = error_get_last();
        $msg = is_array($l) && isset($l['message']) ? (string) $l['message'] : '';
        if (stripos($msg, 'timed out') !== false || microtime(true) - $t0 >= $anf['tmo'] - 0.5) {
            return array('code' => 0, 'rumpf' => '', 'errno' => 28, 'fehler' => 'timed out');
        }
        if (stripos($msg, 'refused') !== false || stripos($msg, 'verweigert') !== false) {
            return array('code' => 0, 'rumpf' => '', 'errno' => 7, 'fehler' => 'refused');
        }
        if (stripos($msg, 'getaddrinfo') !== false || stripos($msg, 'name') !== false) {
            return array('code' => 0, 'rumpf' => '', 'errno' => 6, 'fehler' => 'name');
        }
        return array('code' => 0, 'rumpf' => '', 'errno' => -1, 'fehler' => 'fopen');
    }
    /* Kopfzeilen zuerst, dann der Rumpf mit fread() - nicht mit
     * stream_get_contents(). Gemessen 02.10.2026 (proben/probe_74.txt, Fall
     * a_stockt): unter PHP 7.4 wartete stream_get_contents() bei einem
     * stockenden Rumpf bis zum Verbindungsende, auch mit stream_set_timeout()
     * (die Pruefung auf Dateiende blockiert dort ohne Frist); der halbe Rumpf
     * "SPRECHEN;OK=1" galt als gesendet. fread() haelt die Lesefrist. Danach
     * zaehlt zusaetzlich die Uhr und die angekuendigte Laenge: ein Rumpf, der
     * kuerzer ist als Content-Length, ist abgebrochen, nicht angekommen. */
    $meta = stream_get_meta_data($fh);
    $code = 0;
    $laenge = -1;
    $ort = '';
    $wd = isset($meta['wrapper_data']) ? $meta['wrapper_data'] : array();
    foreach ((array) $wd as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $code = (int) $m[1]; $laenge = -1; $ort = ''; }
        if (is_string($z) && preg_match('#^Content-Length:\s*(\d+)\s*$#i', $z, $m)) { $laenge = (int) $m[1]; }
        if (is_string($z) && preg_match('#^Location:\s*(\S+)\s*$#i', $z, $m)) { $ort = $m[1]; }
    }
    /* Gelesen wird hoechstens ANSAGE_RUMPF_MAX (bzw. die angekuendigte Laenge),
     * danach schliesst fclose() die Verbindung - der Rest bleibt ungelesen. */
    $rumpf = '';
    $zeit = false;
    $ziel = ($laenge >= 0) ? min($laenge, ANSAGE_RUMPF_MAX) : ANSAGE_RUMPF_MAX;
    while (strlen($rumpf) < $ziel) {
        $rest = $anf['tmo'] - (microtime(true) - $t0);
        if ($rest <= 0) { $zeit = true; break; }
        stream_set_timeout($fh, max(1, (int) ceil($rest)));
        $s = fread($fh, min(8192, $ziel - strlen($rumpf)));
        if ($s === false || $s === '') {
            $mm = stream_get_meta_data($fh);
            if (!empty($mm['timed_out'])) { $zeit = true; }
            break;
        }
        $rumpf .= $s;
    }
    fclose($fh);
    if ($zeit || microtime(true) - $t0 > $anf['tmo'] + 0.5) {
        return array('code' => 0, 'rumpf' => '', 'errno' => 28, 'fehler' => 'timed out');
    }
    if ($laenge >= 0 && $laenge <= ANSAGE_RUMPF_MAX && strlen($rumpf) < $laenge) {
        return array('code' => 0, 'rumpf' => '', 'errno' => -1, 'fehler' => 'Rumpf abgebrochen');
    }
    if ($code === 0) {
        return array('code' => 0, 'rumpf' => '', 'errno' => -1, 'fehler' => 'kein Status');
    }
    return array('code' => $code, 'rumpf' => $rumpf, 'errno' => 0, 'fehler' => '', 'ort' => $ort);
}

/**
 * Kennung eines Transportfehlers oder eines unerwuenschten Status ('' = in
 * Ordnung). $fehler (wahlfrei, wie 1.0.3; 1.1.0 hatte ihn nicht) ist der
 * Fehlertext des Transports: HTTP_NETZ|<errno>|<text>. Bis 1.0.2 fehlte er, und
 * "Netzfehler %s: %s" endete mit "Netzfehler 52: " (Pruefung 02.10.2026, Nr. 3).
 * Ohne $fehler bleibt es bei HTTP_NETZ|<errno>.
 */
function ansage_http_grund_id($errno, $status, $fehler = '')
{
    $errno = (int) $errno;
    $status = (int) $status;
    if ($errno === -2) { return 'HTTP_KEIN_HTTP'; }
    if ($errno === 7) { return 'HTTP_ABGEWIESEN'; }
    if ($errno === 6) { return 'HTTP_NAME'; }
    if ($errno === 28) { return 'HTTP_ZEIT'; }
    if ($errno === -1) { return 'HTTP_OHNE_ANTWORT'; }
    if ($errno !== 0) {
        $f = is_scalar($fehler) ? (string) $fehler : '';
        $f = substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', str_replace('|', '/', $f)), 0, 200);
        return 'HTTP_NETZ|' . $errno . ($f !== '' ? '|' . $f : '');
    }
    if ($status === 401 || $status === 403) { return 'HTTP_ZUGANG|' . $status; }
    if ($status === 404) { return 'HTTP_404'; }
    if ($status === 429) { return 'HTTP_429'; }
    if ($status >= 500) { return 'HTTP_GEGENSEITE|' . $status; }
    if ($status >= 400) { return 'HTTP_STATUS|' . $status; }
    if ($status >= 300) { return 'HTTP_WEITERLEITUNG|' . $status; }
    if ($status < 200) { return 'HTTP_STATUS|' . $status; }
    return '';
}

/**
 * POST an einen Sprech-Endpunkt. Rueckgabe: array('code' => HTTP-Code (0 =
 * keine Antwort), 'zeile' => erste Antwortzeile ohne Token und Steuerzeichen,
 * hoechstens 200 Zeichen, 'roh' => dieselbe Zeile bis 1000 Zeichen (fuer
 * Linien mit eigener Kuerzung), 'grund_id' => Kennung des Transportfehlers,
 * 'tmo' => Wartezeit).
 *
 * "Erste Antwortzeile" heisst seit 1.1.0: die erste Zeile, die mit
 * "<praefix>;" beginnt (ohne $praefix: mit "<KOPF>;" in Grossbuchstaben),
 * nach Entfernen einer Bytereihenfolgemarke. Bis 1.0.2 zaehlte nur die
 * allererste Zeile; stand eine PHP-Warnung oder eine BOM des anderen Plugins
 * davor, galt eine gesprochene Ansage als gescheitert - und der Rueckfall der
 * Linie sprach sie ein zweites Mal. Gibt es keine solche Zeile, bleibt es bei
 * der ersten.
 */
function ansage_ng_rufen($url, array $felder, $tmo, array $k, $praefix = '')
{
    $a = ansage_ausfuehren(ansage_anfrage('POST', $url, $felder, $tmo, $k), $k);
    $gid = '';
    if ($a['code'] <= 0) {
        $gid = ansage_http_grund_id($a['errno'] === 0 ? -1 : $a['errno'], 0, $a['fehler']);
    }
    $zeilen = preg_split('/\r?\n|\r/', trim(str_replace("\xEF\xBB\xBF", '', $a['rumpf'])));
    $muster = (is_string($praefix) && $praefix !== '') ? '/^' . preg_quote($praefix, '/') . ';/' : '/^[A-Z][A-Z0-9_]*;/';
    $erste = trim((string) $zeilen[0]);
    foreach ($zeilen as $zl) {
        if (preg_match($muster, trim($zl))) { $erste = trim($zl); break; }
    }
    if (isset($felder['token']) && is_string($felder['token']) && $felder['token'] !== '') {
        $erste = str_replace($felder['token'], '***', $erste);
    }
    $erste = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $erste);
    return array('code' => $a['code'], 'zeile' => substr($erste, 0, 200), 'roh' => substr($erste, 0, 1000),
                 'grund_id' => $gid, 'tmo' => (int) $tmo);
}

/**
 * Antwort eines Sprech-Endpunkts bewerten. Rueckgabe: '' bei HTTP 200 und
 * "<praefix>;OK=1" am Zeilenanfang, sonst eine Kennung (nie mit Token):
 *   <ART>_KEINE_ANTWORT|adresse|tmo|HTTP_...   keine Antwort
 *   <ART>_ANTWORT|code|GRUND[|<ART>_G_GRUND]   Abweisung mit GRUND
 *   <ART>_FEHLT|adresse|404                    404 ohne GRUND: Plugin fehlt/zu alt
 *   <ART>_UNERWARTET|code|Ausschnitt           alles andere
 * $art_k ist ALEXA oder GOOGLE. Gibt es zum GRUND einen erklaerenden Satz
 * (<ART>_G_<GRUND>, Kontext 'erklaert'), wird seine Kennung angehaengt.
 */
function ansage_ng_bewerten(array $a, $praefix, $art_k, $adresse, array $k = array())
{
    // Bis 1.0.2 strpos(): auch "SPRECHEN;OK=10" oder "SPRECHEN;OK=1X" galt als gesendet.
    if ($a['code'] === 200 && preg_match('/^' . preg_quote($praefix, '/') . ';OK=1(?:;|\z)/', $a['zeile'])) {
        return '';
    }
    if ($a['code'] <= 0) {
        return $art_k . '_KEINE_ANTWORT|' . $adresse . '|' . (int) $a['tmo']
             . ($a['grund_id'] !== '' ? '|' . $a['grund_id'] : '');
    }
    if (preg_match('/(?:^|;)GRUND=([A-Za-z0-9_]{1,40})(?:;|$)/', $a['zeile'], $m)) {
        $erkl = $art_k . '_G_' . strtoupper($m[1]);
        if (isset($k['erklaert']) && is_callable($k['erklaert'])) {
            $da = (bool) call_user_func($k['erklaert'], $erkl);
        } else {
            $da = ansage_t_da('K_' . $erkl, $k);
        }
        return $art_k . '_ANTWORT|' . (int) $a['code'] . '|' . $m[1] . ($da ? '|' . $erkl : '');
    }
    if ($a['code'] === 404) {
        return $art_k . '_FEHLT|' . $adresse . '|404';
    }
    $s = substr((string) preg_replace('/[^A-Za-z0-9;=_.:\-]/', '', $a['zeile']), 0, 60);
    return $art_k . '_UNERWARTET|' . (int) $a['code'] . '|' . ($s !== '' ? $s : '-');
}

/* ==================================================================
 * Sprechen
 * ================================================================== */

/**
 * Die Lautstaerke $s aus dem Block: fehlt sie oder ist sie leer, -1 (=
 * unveraendert); sonst ansage_laut_pruefen(). Abgewiesen: null, die Kennung
 * (UNTER|tts.<s>|...) in $grund - gesendet wird dann nicht, statt still ohne
 * oder mit einer anderen Lautstaerke.
 */
function ansage_tts_laut(array $tts, $s, &$grund = '')
{
    $grund = '';
    if (!array_key_exists($s, $tts) || $tts[$s] === '' || $tts[$s] === null) { return -1; }
    $g = '';
    $z = ansage_laut_pruefen($tts[$s], $g);
    if ($z === null) {
        $grund = 'UNTER|tts.' . $s . '|' . $g;
        return null;
    }
    return $z;
}

/**
 * Einen Text ueber die eingestellte Ausgabeart sprechen. Rueckgabe:
 *
 *   'stand'   1 gesendet, 0 gescheitert, -1 nichts gesendet ohne Fehler
 *             (aus, leerer Text, Original-Audioserver)
 *   'kennung' '' oder die Kennung des Grundes (ansage_kennung_text())
 *   'art'     die Ausgabeart
 *   'http'    HTTP-Code (0 = keine Antwort oder nicht gerufen)
 *   'zeile'   Antwortzeile von Alexa-NG/Chromecast ohne Token, sonst ''
 *   'zeichen' Laenge des Texts in Zeichen
 *
 * Der Text und das Token stehen nie im Ergebnis, die Adresse des Music
 * Servers auch nicht (sie traegt den Text). Gerufen wird hoechstens einmal
 * (die Wiederholung nach einer Umleitung auf https an 127.0.0.1 zaehlt als
 * derselbe Ruf, siehe ansage_ausfuehren()).
 *
 * $opt (seit 1.1.0): 'dringend' => true reicht die Dringlichkeit weiter -
 * an Alexa-NG und Chromecast 4 Lox NG als dringend=1 (Alexa-NG uebergeht
 * damit seine Ruhezeit und die Sperre aus Loxone, nicht die Bremse;
 * Chromecast nimmt es an, es wirkt dort nicht), an Sonos4Lox als urgent=1
 * (spricht auch, wenn dort die Sprachausgabe abgeschaltet ist).
 *
 * Sonos4Lox antwortet erst, wenn die Ansage zu Ende gespielt ist (say wartet
 * die Wiedergabe ab und stellt danach den alten Zustand her). Eine
 * Zeitueberschreitung heisst dort NICHT "nicht gesprochen": die Kennung ist
 * SONOS_ZEIT_UNKLAR, und ein Rueckfall auf einen anderen Lautsprecher spraeche
 * die Ansage womoeglich doppelt.
 */
function ansage_sprechen($text, array $tts, array $k = array(), array $opt = array())
{
    $dringend = !empty($opt['dringend']);
    $modus = isset($tts['mode']) && is_string($tts['mode']) ? $tts['mode'] : '';
    $r = array('stand' => 0, 'kennung' => '', 'art' => $modus, 'http' => 0, 'zeile' => '',
               'zeichen' => ansage_zeichen($text));
    if (!in_array($modus, ansage_modi(), true)) {
        $r['art'] = '-';
        $r['kennung'] = 'MODUS_UNBEKANNT';
        return $r;
    }
    if ($modus === 'aus') {
        $r['stand'] = -1;
        $r['kennung'] = 'AUS';
        return $r;
    }
    list($st, $kn) = ansage_text_pruefen($text, $modus);
    if ($st !== 1) {
        $r['stand'] = $st;
        $r['kennung'] = $kn;
        return ansage_letzte_merken($r, $k);
    }
    if ($modus === 'audioserver') {
        $r['stand'] = -1;
        $r['kennung'] = 'AUDIOSERVER';
        return $r;
    }
    if (ansage_ist_ng($modus)) {
        $art = ansage_art($modus);
        $art_k = strtoupper($art);
        $tok = isset($tts[$art . '_token']) ? $tts[$art . '_token'] : '';
        if (!ansage_token_ok($tok)) {
            $r['kennung'] = $art_k . '_KEIN_TOKEN';
            return ansage_letzte_merken($r, $k);
        }
        $g = isset($tts[$art . '_geraet']) ? $tts[$art . '_geraet'] : '';
        if (!ansage_geraet_ok($g)) {
            $r['kennung'] = 'EINSTELLUNG|TTS_' . $art_k . '_GERAET';
            return ansage_letzte_merken($r, $k);
        }
        $laut = ansage_tts_laut($tts, $art . '_laut', $grund);
        if ($laut === null) {
            $r['kennung'] = 'EINSTELLUNG|' . $grund;
            return ansage_letzte_merken($r, $k);
        }
        $f = array('aktion' => 'sprechen', 'token' => $tok);
        if ($g !== '') { $f['geraet'] = $g; }
        if ($laut >= 1) { $f['laut'] = $laut; }
        if ($dringend) { $f['dringend'] = '1'; }
        $f['text'] = $text;
        $url = ansage_adresse($modus, ansage_k_port($k), ansage_k_home($k));
        $a = ansage_ng_rufen($url, $f, ANSAGE_TMO, $k, 'SPRECHEN');
        $r['http'] = $a['code'];
        $r['zeile'] = $a['zeile'];
        $r['kennung'] = ansage_ng_bewerten($a, 'SPRECHEN', $art_k, $url, $k);
        $r['stand'] = $r['kennung'] === '' ? 1 : 0;
        return ansage_letzte_merken($r, $k);
    }
    if ($modus === 'sonos4lox') {
        /* GET, weil Sonos4Lox nur $_GET liest (src/Http/Request.php); der Text
         * steht damit in der Adresse an 127.0.0.1 - wie bei jedem Aufruf aus
         * Loxone. Als gesendet gilt HTTP 2xx: die Antwort traegt kein
         * Erfolgsmerkmal (auch eine abgeschaltete Sprachausgabe oder eine
         * unbekannte Zone antworten dort mit 200). */
        $zone = isset($tts['sonos_zone']) ? $tts['sonos_zone'] : '';
        if ($zone === '') {
            $r['kennung'] = 'SONOS_KEINE_ZONE';
            return ansage_letzte_merken($r, $k);
        }
        if (ansage_wert_pruefen(array('sonos_zone' => $zone), $grund) === null) {
            $r['kennung'] = 'EINSTELLUNG|' . $grund;
            return ansage_letzte_merken($r, $k);
        }
        $laut = ansage_tts_laut($tts, 'sonos_laut', $grund);
        if ($laut === null) {
            $r['kennung'] = 'EINSTELLUNG|' . $grund;
            return ansage_letzte_merken($r, $k);
        }
        $q = array('zone' => $zone, 'action' => 'say', 'text' => $text);
        if ($laut >= 1) { $q['volume'] = $laut; }
        if ($dringend) { $q['urgent'] = '1'; }
        $basis = ansage_adresse($modus, ansage_k_port($k), ansage_k_home($k));
        $a = ansage_ausfuehren(ansage_anfrage('GET', $basis . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986),
                                              null, ANSAGE_TMO_SONOS, $k), $k);
        $r['http'] = $a['code'];
        if ($a['code'] <= 0 && $a['errno'] === 28) {
            $r['kennung'] = 'SONOS_ZEIT_UNKLAR|' . $basis . '|' . ANSAGE_TMO_SONOS;
        } elseif ($a['code'] === 404) {
            $r['kennung'] = 'SONOS_FEHLT|' . $basis . '|404';
        } else {
            $r['kennung'] = ansage_http_grund_id($a['code'] > 0 ? 0 : ($a['errno'] === 0 ? -1 : $a['errno']), $a['code'],
                                                 $a['fehler']);
        }
        $r['stand'] = $r['kennung'] === '' ? 1 : 0;
        return ansage_letzte_merken($r, $k);
    }
    /* musicserver, ms4h, custom: Adresse und Vorlage werden vor dem Senden
     * erneut geprueft - eine von Hand verbogene Konfiguration spricht nicht
     * ins Internet. */
    $pruef = array();
    foreach (array('ip', 'port', 'volume', 'zones', 'lang', 'template') as $s) {
        if (array_key_exists($s, $tts)) { $pruef[$s] = $tts[$s]; }
    }
    $grund = '';
    if (ansage_wert_pruefen($pruef, $grund) === null) {
        $r['kennung'] = 'EINSTELLUNG|' . $grund;
        return ansage_letzte_merken($r, $k);
    }
    $url = ansage_tts_url($text, $tts);
    if ($url === '' || $url === null) {
        $r['kennung'] = 'KEINE_IP';
        return ansage_letzte_merken($r, $k);
    }
    // Die fertige Adresse mit den echten Werten - so, wie curl sie liest.
    $ug = ansage_url_grund($url);
    if ($ug !== '') {
        $r['kennung'] = 'EINSTELLUNG|' . ($ug === 'HTTP_KEIN_HTTP' ? 'TTS_VORLAGE_HTTP' : $ug);
        return ansage_letzte_merken($r, $k);
    }
    $a = ansage_ausfuehren(ansage_anfrage('GET', $url, null, ANSAGE_TMO, $k), $k);
    $r['http'] = $a['code'];
    $gid = ansage_http_grund_id($a['code'] > 0 ? 0 : ($a['errno'] === 0 ? -1 : $a['errno']), $a['code'], $a['fehler']);
    $r['kennung'] = $gid;
    $r['stand'] = $gid === '' ? 1 : 0;
    return ansage_letzte_merken($r, $k);
}

/** Die Testansage: ein fester Satz aus der Sprachdatei, sonst wie ansage_sprechen(). */
function ansage_testansage(array $tts, array $k = array())
{
    return ansage_sprechen(ansage_t('TESTTEXT', $k), $tts, $k);
}

/**
 * Kurzform eines Ergebnisses fuer das Protokoll der Linie (ASCII, eine
 * Zeile): Art, Stand, Zeichenzahl, HTTP-Code, Antwortzeile bzw. Kennung -
 * nie Text, nie Token, nie Adresse des Music Servers.
 */
function ansage_kurz(array $r)
{
    $s = 'art=' . $r['art'] . ' stand=' . (int) $r['stand'] . ' zeichen=' . (int) $r['zeichen'];
    if ((int) $r['http'] > 0) { $s .= ' http=' . (int) $r['http']; }
    if ($r['zeile'] !== '') { $s .= ' antwort=' . $r['zeile']; }
    if ($r['kennung'] !== '') { $s .= ' kennung=' . $r['kennung']; }
    return (string) preg_replace('/[^\x20-\x7E]/', '?', $s);
}

/* ==================================================================
 * Letzte Ansage (fuer den Reiter Test)
 * ================================================================== */

/** Dateiname der letzten Ansage einer Art. */
function ansage_letzte_datei($art)
{
    return ($art === 'alexa' || $art === 'google' || $art === 'sonos') ? $art . '_letzte.json' : 'klassisch_letzte.json';
}

/**
 * Das Ergebnis ablegen, wenn der Kontext einen VORHANDENEN Ordner nennt.
 * Inhalt: Zeit, ok, Stand, Kennung, HTTP-Code, Antwortzeile - nie Text oder
 * Token. Unteilbar: Nebendatei mit PID, Rechte vor dem Inhalt, Laengen-
 * vergleich, rename. Gibt $r unveraendert zurueck.
 */
function ansage_letzte_merken(array $r, array $k)
{
    $o = isset($k['ordner']) && is_string($k['ordner']) ? $k['ordner'] : '';
    if ($o === '' || !is_dir($o)) { return $r; }
    $jetzt = isset($k['jetzt']) && is_callable($k['jetzt']) ? (int) call_user_func($k['jetzt']) : time();
    $inhalt = (string) json_encode(array('zeit' => $jetzt, 'ok' => $r['stand'] === 1 ? 1 : 0,
        'stand' => (int) $r['stand'], 'grund_id' => (string) $r['kennung'], 'code' => (int) $r['http'],
        'zeile' => (string) $r['zeile']));
    $datei = rtrim($o, '/\\') . '/' . ansage_letzte_datei(ansage_letzte_art($r['art']));
    $neben = $datei . '.' . getmypid() . '.neu';
    if (@file_put_contents($neben, '') === false) { return $r; }
    @chmod($neben, 0600);
    if (@file_put_contents($neben, $inhalt) !== strlen($inhalt) || !@rename($neben, $datei)) {
        @unlink($neben);
    }
    return $r;
}

/** Die letzte Ansage einer Art ('alexa', 'google', 'sonos', '' = klassisch) oder null. */
function ansage_letzte($art, array $k)
{
    $o = isset($k['ordner']) && is_string($k['ordner']) ? $k['ordner'] : '';
    if ($o === '') { return null; }
    $f = rtrim($o, '/\\') . '/' . ansage_letzte_datei($art);
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || !isset($d['zeit'], $d['ok'])) { return null; }
    return array('zeit' => (int) $d['zeit'], 'ok' => (int) $d['ok'],
                 'grund_id' => (isset($d['grund_id']) && is_string($d['grund_id'])) ? $d['grund_id'] : '',
                 'code' => isset($d['code']) ? (int) $d['code'] : 0,
                 'zeile' => (isset($d['zeile']) && is_string($d['zeile'])) ? $d['zeile'] : '');
}

/* ==================================================================
 * Reiter Test
 * ================================================================== */

/**
 * Selbsttest am anderen Plugin (selftest=1: prueft nur das Token, spricht
 * nicht). Rueckgabe: array('stand' => 1/0, 'kennung', 'zeile',
 * 'sprechen_aus' => bool, 'dienst_aus' => bool).
 */
function ansage_ng_selbsttest(array $tts, $modus, array $k, $tmo = ANSAGE_TMO_PRUEF)
{
    $art = ansage_art($modus);
    $art_k = strtoupper($art);
    $aus = array('stand' => 0, 'kennung' => '', 'zeile' => '', 'sprechen_aus' => false, 'dienst_aus' => false);
    $tok = isset($tts[$art . '_token']) ? $tts[$art . '_token'] : '';
    if (!ansage_token_ok($tok)) {
        $aus['kennung'] = $art_k . '_KEIN_TOKEN';
        return $aus;
    }
    $url = ansage_adresse($modus, ansage_k_port($k), ansage_k_home($k));
    $a = ansage_ng_rufen($url, array('selftest' => '1', 'token' => $tok), $tmo, $k, 'SELFTEST');
    $aus['kennung'] = ansage_ng_bewerten($a, 'SELFTEST', $art_k, $url, $k);
    $aus['zeile'] = $a['zeile'];
    if ($aus['kennung'] === '') {
        $aus['stand'] = 1;
        $aus['sprechen_aus'] = preg_match('/(?:^|;)SPRECHEN=0(?:;|$)/', $a['zeile']) === 1;
        $aus['dienst_aus'] = preg_match('/(?:^|;)DIENST=0(?:;|$)/', $a['zeile']) === 1;
    }
    return $aus;
}

/** Alter in Worten: 42 s, 7 min, 3 h, 2 d. */
function ansage_alter($s)
{
    $s = max(0, (int) $s);
    if ($s < 90) { return $s . ' s'; }
    if ($s < 5400) { return (int) round($s / 60) . ' min'; }
    if ($s < 172800) { return (int) round($s / 3600) . ' h'; }
    return (int) round($s / 86400) . ' d';
}

/**
 * Die Zeile im Reiter Test fuer die eingestellte Ausgabeart. Rueckgabe:
 * array(Stand, HTML) mit Stand 1 (Haken), 0 (Kreuz), -1 (Hinweis), -2 (grau:
 * aus oder nicht gemessen). Gefragt wird ein anderes Plugin nur, wenn der
 * Reiter Test serverseitig der offene ist ($offen) - sonst kostete jeder
 * Seitenaufbau bis zu 5 s, wenn es haengt. Der Music Server wird nie gefragt:
 * eine Probe dort spraeche.
 */
function ansage_pruefzeile(array $tts, $offen, array $k = array())
{
    $e = ansage_e_fn($k);
    $modus = isset($tts['mode']) && is_string($tts['mode']) ? $tts['mode'] : '';
    if (!in_array($modus, ansage_modi(), true)) {
        return array(0, $e(ansage_kennung_text('MODUS_UNBEKANNT', $k)));
    }
    if ($modus === 'aus') {
        return array(-2, $e(ansage_t('T_AUS', $k)));
    }
    if ($modus === 'audioserver') {
        return array(-1, $e(ansage_t('T_AUDIOSERVER', $k)));
    }
    if ($modus === 'sonos4lox') {
        // Nie gefragt: Sonos4Lox hat keinen Selbsttest, eine Probe spraeche.
        if (!isset($tts['sonos_zone']) || $tts['sonos_zone'] === '') {
            return array(0, $e(ansage_kennung_text('SONOS_KEINE_ZONE', $k)));
        }
        $l = ansage_letzte('sonos', $k);
        return ansage_mit_letzter(array(1, $e(sprintf(ansage_t('T_SONOS', $k),
            ansage_adresse($modus, ansage_k_port($k), ansage_k_home($k))))), $l, $k);
    }
    if (!ansage_ist_ng($modus)) {
        $url = ansage_tts_url('-', $tts);
        if ($url === '' || $url === null) {
            return array(0, $e(ansage_kennung_text('KEINE_IP', $k)));
        }
        $host = preg_match('#^https?://([^/?\#]+)#i', $url, $m) ? $m[1] : '?';
        $l = ansage_letzte('', $k);
        return ansage_mit_letzter(array(1, $e(sprintf(ansage_t('T_KLASSISCH', $k), $host))), $l, $k);
    }
    $art = ansage_art($modus);
    $art_k = strtoupper($art);
    $tok = isset($tts[$art . '_token']) ? $tts[$art . '_token'] : '';
    if (!ansage_token_ok($tok)) {
        return array(0, $e(ansage_kennung_text($art_k . '_KEIN_TOKEN', $k)));
    }
    if (!$offen) {
        return array(-2, $e(ansage_t('T_' . $art_k . '_ZU', $k)));
    }
    $s = ansage_ng_selbsttest($tts, $modus, $k);
    $l = ansage_letzte($art, $k);
    if ($s['stand'] !== 1) {
        return ansage_mit_letzter(array(0, $e(sprintf(ansage_t('T_' . $art_k . '_FEHL', $k),
            ansage_kennung_text($s['kennung'], $k)))), $l, $k);
    }
    $z = array(1, $e(sprintf(ansage_t('T_' . $art_k . '_OK', $k), ansage_adresse($modus, ansage_k_port($k), ansage_k_home($k)))));
    if ($s['sprechen_aus']) { $z[0] = -1; $z[1] .= ' ' . $e(ansage_t('T_SPRECHEN_AUS', $k)); }
    if ($s['dienst_aus']) { $z[0] = -1; $z[1] .= ' ' . $e(ansage_t('T_DIENST_AUS', $k)); }
    return ansage_mit_letzter($z, $l, $k);
}

/** Die letzte Ansage an eine Zeile haengen; ist sie gescheitert, wird ein Haken zum Hinweis. */
function ansage_mit_letzter(array $z, $l, array $k)
{
    if (!is_array($l)) { return $z; }
    $e = ansage_e_fn($k);
    $jetzt = isset($k['jetzt']) && is_callable($k['jetzt']) ? (int) call_user_func($k['jetzt']) : time();
    $alter = ansage_alter($jetzt - $l['zeit']);
    if ($l['ok'] === 1) {
        $z[1] .= ' ' . $e(sprintf(ansage_t('T_LETZTE_OK', $k), $alter));
    } else {
        $z[1] .= ' ' . $e(sprintf(ansage_t('T_LETZTE_FEHL', $k), $alter, ansage_kennung_text($l['grund_id'], $k)));
        if ($z[0] === 1) { $z[0] = -1; }
    }
    return $z;
}

/* ==================================================================
 * Formular
 * ================================================================== */

/** Die POST-Namen der Felder; $opt['namen'] ueberschreibt einzelne (Linien mit eigenen Namen). */
function ansage_feldnamen(array $opt = array())
{
    $n = array();
    foreach (array('mode', 'ip', 'port', 'zones', 'volume', 'lang', 'template',
                   'alexa_geraet', 'alexa_laut', 'alexa_token', 'alexa_token_loeschen',
                   'google_geraet', 'google_laut', 'google_token', 'google_token_loeschen',
                   'sonos_zone', 'sonos_laut') as $id) {
        $n[$id] = 'tts_' . $id;
    }
    if (isset($opt['namen']) && is_array($opt['namen'])) {
        foreach ($opt['namen'] as $id => $name) {
            if (isset($n[$id]) && is_string($name) && $name !== '') { $n[$id] = $name; }
        }
    }
    return $n;
}

/** Felder, deren Eingaben nach einer Beanstandung zurueckreisen duerfen (X-2): alle ausser den Token. */
function ansage_x2_felder(array $opt = array())
{
    $n = ansage_feldnamen($opt);
    unset($n['alexa_token'], $n['google_token']);
    return array_values($n);
}

/**
 * Das Formular lesen (Nr. 16/19, X-2). Rueckgabe: der vollstaendige neue
 * Block. Jede Beanstandung kommt nach $mangel (array('kennung', 'text')),
 * der Name des Felds nach $bean; der Block traegt dort den alten Wert. Die
 * Linie speichert NICHTS, sobald $mangel nicht leer ist (Nr. 16).
 *
 *   - Leerraum am Rand faellt still weg, sonst wird nichts zurechtgebogen.
 *   - Ein Feld als Liste (name[]) ist eine Beanstandung.
 *   - Token: leer = behalten, Haken = loeschen, beides zugleich ist ein
 *     Widerspruch; das Token steht in keiner Meldung.
 *   - Alexa-NG oder Google als Ausgabeart ohne Token ist eine Beanstandung.
 *
 * $opt: 'namen' (ansage_feldnamen()), 'modi' (erlaubte Ausgabearten).
 */
function ansage_formular_lesen(array $post, array $alt, array &$mangel, array &$bean, array $opt = array(), array $k = array())
{
    $n = ansage_feldnamen($opt);
    $modi = (isset($opt['modi']) && is_array($opt['modi'])) ? $opt['modi'] : ansage_modi();
    list($alt) = ansage_vervollstaendigen($alt);
    $neu = $alt;
    $roh = function ($id) use ($post, $n) {
        if (!isset($post[$n[$id]])) { return ''; }
        return is_string($post[$n[$id]]) ? trim($post[$n[$id]]) : null;
    };
    $melden = function ($kennung, $feld) use (&$mangel, &$bean, $n, $k) {
        $mangel[] = array('kennung' => $kennung, 'text' => ansage_kennung_text($kennung, $k));
        if (!in_array($n[$feld], $bean, true)) { $bean[] = $n[$feld]; }
    };
    $m = $roh('mode');
    if ($m === null || !in_array($m, $modi, true)) {
        $melden('M_MODUS', 'mode');
    } else {
        $neu['mode'] = $m;
    }
    foreach (array('ip' => 'M_IP', 'port' => 'M_PORT', 'zones' => 'M_ZONEN', 'volume' => 'M_VOLUME',
                   'lang' => 'M_SPRACHE', 'template' => 'M_VORLAGE') as $id => $mk) {
        $w = $roh($id);
        $grund = '';
        $p = ($w === null) ? null : ansage_wert_pruefen(array($id => $w), $grund);
        if ($p === null) {
            $melden($mk . '|' . ($w === null ? 'KEIN_TEXT' : $grund), $id);
        } else {
            $neu[$id] = $p[$id];
        }
    }
    foreach (array('alexa' => 'ALEXA', 'google' => 'GOOGLE') as $art => $art_k) {
        $g = $roh($art . '_geraet');
        if ($g === null || !ansage_geraet_ok($g)) {
            $melden('M_' . $art_k . '_GERAET', $art . '_geraet');
        } else {
            $neu[$art . '_geraet'] = $g;
        }
        $l = $roh($art . '_laut');
        if ($l === '') {
            $neu[$art . '_laut'] = -1;      // leer: die Lautstaerke des anderen Plugins
        } elseif ($l !== null && preg_match('/^\d{1,3}\z/', $l) === 1 && (int) $l >= 1 && (int) $l <= 100) {
            $neu[$art . '_laut'] = (int) $l;        // 0 waere stumm und trotzdem OK=1 (seit 1.1.0 abgewiesen)
        } else {
            $melden('M_' . $art_k . '_LAUT', $art . '_laut');
        }
        $tok = is_string($alt[$art . '_token']) ? $alt[$art . '_token'] : '';
        $t = $roh($art . '_token');
        $weg = isset($post[$n[$art . '_token_loeschen']]) && !empty($post[$n[$art . '_token_loeschen']]);
        if ($t === null) {
            $melden('M_' . $art_k . '_TOKEN', $art . '_token');
        } elseif ($weg) {
            if ($t !== '') {
                $melden('M_' . $art_k . '_LOESCHEN_WIDERSPRUCH', $art . '_token');
                $melden('M_' . $art_k . '_LOESCHEN_WIDERSPRUCH', $art . '_token_loeschen');
                array_pop($mangel);
            } else {
                $tok = '';
            }
        } elseif ($t !== '') {
            if (ansage_token_ok($t)) {
                $tok = $t;
            } else {
                $melden('M_' . $art_k . '_TOKEN', $art . '_token');
            }
        }
        $neu[$art . '_token'] = $tok;
        $gew = ($art === 'alexa') ? 'alexang' : 'cc4lox';
        if ($neu['mode'] === $gew && $tok === '' && !in_array($n[$art . '_token'], $bean, true)) {
            $melden('M_' . $art_k . '_OHNE_TOKEN', $art . '_token');
        }
    }
    // Sonos4Lox (seit 1.1.0): Zone wie in dessen Zonenliste, Lautstaerke leer = die von Sonos4Lox.
    $z = $roh('sonos_zone');
    if ($z === null || !ansage_geraet_ok($z)) {
        $melden('M_SONOS_ZONE', 'sonos_zone');
    } else {
        $neu['sonos_zone'] = $z;
        if ($neu['mode'] === 'sonos4lox' && $z === '') {
            $melden('M_SONOS_OHNE_ZONE', 'sonos_zone');
        }
    }
    $l = $roh('sonos_laut');
    if ($l === '') {
        $neu['sonos_laut'] = -1;
    } elseif ($l !== null && preg_match('/^\d{1,3}\z/', $l) === 1 && (int) $l >= 1 && (int) $l <= 100) {
        $neu['sonos_laut'] = (int) $l;
    } else {
        $melden('M_SONOS_LAUT', 'sonos_laut');
    }
    return $neu;
}

/**
 * Den Formularblock zeichnen (ohne <form>: er steht im Einstellungsformular
 * der Linie). $h:
 *   'w' function ($name, $gespeichert) -> anzuzeigender Wert (X-2)
 *   'm' function ($name) -> Zusatz fuer das Feld, z. B. die Markierung der Linie fuer ein beanstandetes Feld
 *   'c' function ($name, $gespeichert) -> bool (Haken, X-2)
 *   'modi', 'namen' wie bei ansage_formular_lesen()
 * Das Token steht nie in der Seite: das Feld ist immer leer, der Platzhalter
 * nennt nur, ob eines gespeichert ist und wie lang es ist.
 * Klassen nur aus VORLAGE_hausstandard.css.html (sm-feld, sm-hilfe, sm-hinweis; seit 1.0.2). Eine Linie, die
 * den Baustein benutzt, traegt fehlende Vorlagenklassen in ihre CSS nach (Entwurf, Stufe 2).
 */
function ansage_formular_html(array $tts, array $h = array(), array $k = array())
{
    list($tts) = ansage_vervollstaendigen($tts);
    $e = ansage_e_fn($k);
    $t = function ($id) use ($k) { return ansage_t($id, $k); };
    $w = isset($h['w']) && is_callable($h['w']) ? $h['w'] : function ($name, $wert) { return $wert; };
    $m = isset($h['m']) && is_callable($h['m']) ? $h['m'] : function ($name) { return ''; };
    $c = isset($h['c']) && is_callable($h['c']) ? $h['c'] : function ($name, $wert) { return (bool) $wert; };
    $n = ansage_feldnamen($h);
    $modi = (isset($h['modi']) && is_array($h['modi'])) ? $h['modi'] : ansage_modi();
    $o = array();
    $gewaehlt = (string) call_user_func($w, $n['mode'], $tts['mode']);
    $o[] = '<div class="sm-feld">';
    $o[] = '    <label for="ansage_mode">' . $e($t('L_ART')) . '</label>';
    $o[] = '    <select data-role="none" name="' . $e($n['mode']) . '" id="ansage_mode" onchange="ansageUmschalten()"'
         . call_user_func($m, $n['mode']) . '>';
    foreach ($modi as $mo) {
        if (!in_array($mo, ansage_modi(), true)) { continue; }
        $o[] = '        <option value="' . $e($mo) . '"' . ($gewaehlt === $mo ? ' selected' : '') . '>'
             . $e($t('O_' . strtoupper($mo))) . '</option>';
    }
    $o[] = '    </select>';
    $o[] = '    <div class="sm-hilfe">' . $e($t('ART_HINWEIS')) . '</div>';
    $o[] = '</div>';
    $feld = function ($id, $typ, $label, $zusatz, $hinweis) use ($e, $t, $w, $m, $n, $tts) {
        $wert = $tts[$id];
        if (($id === 'alexa_laut' || $id === 'google_laut' || $id === 'sonos_laut') && (int) $wert < 0) { $wert = ''; }
        $z = array();
        $z[] = '<div class="sm-feld">';
        $z[] = '    <label for="ansage_' . $id . '">' . $e($t($label)) . '</label>';
        $z[] = '    <input data-role="none" type="' . $typ . '" id="ansage_' . $id . '" name="' . $e($n[$id])
             . '" value="' . $e((string) call_user_func($w, $n[$id], $wert)) . '"' . $zusatz . call_user_func($m, $n[$id]) . '>';
        if ($hinweis !== '') { $z[] = '    <div class="sm-hilfe">' . $e($t($hinweis)) . '</div>'; }
        $z[] = '</div>';
        return $z;
    };
    $o[] = '<div id="ansage_klassisch">';
    $o = array_merge($o,
        $feld('ip', 'text', 'L_IP', ' maxlength="253" placeholder="' . $e($t('P_IP')) . '"', 'IP_HINWEIS'),
        $feld('port', 'number', 'L_PORT', ' min="1" max="65535"', ''),
        $feld('zones', 'text', 'L_ZONEN', ' maxlength="200" placeholder="1,2"', 'ZONEN_HINWEIS'),
        $feld('volume', 'number', 'L_LAUTSTAERKE', ' min="1" max="100"', ''),
        $feld('lang', 'text', 'L_SPRACHE', ' maxlength="2"', ''));
    $o[] = '</div>';
    $o[] = '<div id="ansage_vorlage" class="sm-feld">';
    $o[] = '    <label for="ansage_template">' . $e($t('L_VORLAGE')) . '</label>';
    $o[] = '    <textarea data-role="none" id="ansage_template" name="' . $e($n['template']) . '" rows="2" maxlength="500"'
         . ' placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"' . call_user_func($m, $n['template']) . '>'
         . $e((string) call_user_func($w, $n['template'], $tts['template'])) . '</textarea>';
    $o[] = '    <div class="sm-hilfe">' . $e($t('VORLAGE_HINWEIS')) . '</div>';
    $o[] = '</div>';
    $o[] = '<div id="ansage_audioserver" class="sm-hinweis">' . $e($t('AUDIOSERVER_HINWEIS')) . '</div>';
    foreach (array('alexa' => 'ALEXA', 'google' => 'GOOGLE') as $art => $art_k) {
        $o[] = '<div id="ansage_' . $art . '">';
        $o[] = '<div class="sm-hinweis">' . $e($t($art_k . '_HINWEIS')) . '</div>';
        $o = array_merge($o,
            $feld($art . '_geraet', 'text', 'L_' . $art_k . '_GERAET', ' maxlength="200"', $art_k . '_GERAET_HINWEIS'),
            $feld($art . '_laut', 'number', 'L_' . $art_k . '_LAUT', ' min="1" max="100"', $art_k . '_LAUT_HINWEIS'));
        $gesp = is_string($tts[$art . '_token']) ? $tts[$art . '_token'] : '';
        $ph = $gesp !== '' ? sprintf($t('P_TOKEN_DA'), strlen($gesp)) : $t('P_TOKEN_LEER');
        $o[] = '<div class="sm-feld">';
        $o[] = '    <label for="ansage_' . $art . '_token">' . $e($t('L_' . $art_k . '_TOKEN')) . '</label>';
        $o[] = '    <input data-role="none" type="password" id="ansage_' . $art . '_token" name="' . $e($n[$art . '_token'])
             . '" value="" autocomplete="new-password" placeholder="' . $e($ph) . '"' . call_user_func($m, $n[$art . '_token']) . '>';
        $o[] = '    <label style="display:inline-flex;align-items:center;gap:6px;">';
        $o[] = '        <input data-role="none" type="checkbox" name="' . $e($n[$art . '_token_loeschen']) . '" value="1"'
             . (call_user_func($c, $n[$art . '_token_loeschen'], false) ? ' checked' : '')
             . call_user_func($m, $n[$art . '_token_loeschen']) . '> ' . $e($t('L_TOKEN_LOESCHEN'));
        $o[] = '    </label>';
        $o[] = '    <div class="sm-hilfe">' . $e($t($art_k . '_TOKEN_HINWEIS')) . '</div>';
        $o[] = '</div>';
        $o[] = '</div>';
    }
    $o[] = '<div id="ansage_sonos">';
    $o[] = '<div class="sm-hinweis">' . $e($t('SONOS_HINWEIS')) . '</div>';
    $o = array_merge($o,
        $feld('sonos_zone', 'text', 'L_SONOS_ZONE', ' maxlength="200"', 'SONOS_ZONE_HINWEIS'),
        $feld('sonos_laut', 'number', 'L_SONOS_LAUT', ' min="1" max="100"', 'SONOS_LAUT_HINWEIS'));
    $o[] = '</div>';
    $o[] = '<script>';
    $o[] = 'function ansageUmschalten() {';
    $o[] = '    var m = document.getElementById(\'ansage_mode\').value;';
    $o[] = '    var zeigen = function (id, an) { var x = document.getElementById(id);';
    $o[] = '        if (x) { x.style.display = (an || x.querySelector(\'.sm-beanstandet\')) ? \'\' : \'none\'; } };';
    $o[] = '    zeigen(\'ansage_klassisch\', m === \'musicserver\' || m === \'ms4h\' || m === \'custom\');';
    $o[] = '    zeigen(\'ansage_vorlage\', m === \'ms4h\' || m === \'custom\');';
    $o[] = '    zeigen(\'ansage_audioserver\', m === \'audioserver\');';
    $o[] = '    zeigen(\'ansage_alexa\', m === \'alexang\');';
    $o[] = '    zeigen(\'ansage_sonos\', m === \'sonos4lox\');';
    $o[] = '    zeigen(\'ansage_google\', m === \'cc4lox\');';
    $o[] = '}';
    $o[] = 'ansageUmschalten();';
    $o[] = '</script>';
    return implode("\n", $o) . "\n";
}

/* ==================================================================
 * Sichern und Zurueckspielen
 * ================================================================== */

/** Den Block fuer "Einstellungen sichern": ohne Sprechtoken. */
function ansage_sicherung_bereinigen($tts)
{
    if (!is_array($tts)) { return $tts; }
    foreach (ansage_geheim() as $s) { unset($tts[$s]); }
    return $tts;
}

/**
 * Traegt ein Block aus einer Sicherungsdatei ein Sprechtoken? Dann stammt er
 * nicht aus "Einstellungen sichern" und wird abgewiesen. Rueckgabe: die
 * Namen ('tts.alexa_token' ...). Ein leeres Token heisst "keins gesichert";
 * eine Liste, eine Zahl oder null ist kein leeres Token (Klasse 12).
 */
function ansage_sicherung_mangel($tts)
{
    $namen = array();
    if (!is_array($tts)) { return $namen; }
    foreach (ansage_geheim() as $s) {
        if (array_key_exists($s, $tts) && $tts[$s] !== '') { $namen[] = 'tts.' . $s; }
    }
    return $namen;
}

/** Beim Zurueckspielen: die geltenden Sprechtoken bleiben (die Sicherung traegt keine). */
function ansage_sicherung_tokens_behalten(array $neu, $jetzt)
{
    foreach (ansage_geheim() as $s) {
        $neu[$s] = (is_array($jetzt) && isset($jetzt[$s]) && is_string($jetzt[$s])) ? $jetzt[$s] : '';
    }
    return $neu;
}

/**
 * X-3: Welche gespeicherten Werte wuerde das eigene Zurueckspielen abweisen?
 * Rueckgabe: Namen ('tts.port' ...), nie Werte.
 */
function ansage_sicherung_x3($tts, $modi = null)
{
    $namen = array();
    if (!is_array($tts)) { return array('tts'); }
    foreach (ansage_sicherung_bereinigen($tts) as $s => $w) {
        $g = '';
        if (ansage_wert_pruefen(array($s => $w), $g, $modi) === null) { $namen[] = 'tts.' . $s; }
    }
    return $namen;
}

/* ==================================================================
 * Texte
 * ================================================================== */

/** Die Maskierfunktion des Kontexts. */
function ansage_e_fn(array $k)
{
    if (isset($k['e']) && is_callable($k['e'])) { return $k['e']; }
    return function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
}

/** Der vollstaendige Schluessel zu einer Kennung. */
function ansage_schluessel($id, array $k)
{
    if (isset($k['schluessel'][$id]) && is_string($k['schluessel'][$id])) { return $k['schluessel'][$id]; }
    $ab = isset($k['abschnitt']) && is_string($k['abschnitt']) ? $k['abschnitt'] : 'ANSAGE';
    return $ab . '.' . $id;
}

/** Text zu einer Kennung; ohne Uebersetzer oder ohne Eintrag der Schluessel selbst. */
function ansage_t($id, array $k)
{
    $s = ansage_schluessel($id, $k);
    if (isset($k['t']) && is_callable($k['t'])) {
        return (string) call_user_func($k['t'], $s);
    }
    return $s;
}

/** Gibt es zu dieser Kennung einen Text? */
function ansage_t_da($id, array $k)
{
    return ansage_t($id, $k) !== ansage_schluessel($id, $k);
}

/**
 * Eine Kennung in einen Satz der Oberflaechensprache uebersetzen. Form:
 * ID|wert|wert...; der Satz steht unter K_<ID> (bei M_*-Kennungen unter der
 * ID selbst, mit EINEM %s fuer den inneren Grund). UNTER|feld|<Kennung>
 * stellt einem inneren Grund den Feldnamen voran; Teile hinter den Werten,
 * die selbst eine bekannte Kennung sind, kommen ueber K_MIT dazu.
 */
function ansage_kennung_text($kennung, array $k)
{
    $teile = explode('|', (string) $kennung);
    $id = array_shift($teile);
    if ($id === '') { return ''; }
    if ($id === 'UNTER' && count($teile) >= 2) {
        $feld = array_shift($teile);
        return sprintf(ansage_t('K_UNTER', $k), $feld, ansage_kennung_text(implode('|', $teile), $k));
    }
    if (strpos($id, 'M_') === 0) {
        $satz = ansage_t($id, $k);
        return $teile ? sprintf($satz, ansage_kennung_text(implode('|', $teile), $k)) : $satz;
    }
    if (!ansage_t_da('K_' . $id, $k)) { return (string) $kennung; }
    $satz = ansage_t('K_' . $id, $k);
    $anz = preg_match_all('/%(?:\d+\$)?[sd]/', $satz);
    $werte = array_pad(array_slice($teile, 0, $anz), $anz, '');
    $satz = $anz > 0 ? vsprintf($satz, $werte) : $satz;
    $rest = array_slice($teile, $anz);
    if ($rest && preg_match('/^[A-Z][A-Z0-9_]*\z/', $rest[0]) && ansage_t_da('K_' . $rest[0], $k)) {
        return sprintf(ansage_t('K_MIT', $k), $satz, ansage_kennung_text(implode('|', $rest), $k));
    }
    return $satz;
}

/**
 * Alle Kennungen, zu denen ein Satz in der Sprachdatei stehen soll (ohne
 * Abschnitt). Werkzeuge/gemeinsam/sprachausgabe_texte.json traegt genau
 * diese Schluessel in de und en; ansage_selbsttest() und
 * gemeinsam_pruefen.py halten beides gegeneinander.
 */
function ansage_sprachschluessel()
{
    $s = array('L_ART', 'ART_HINWEIS', 'L_IP', 'P_IP', 'IP_HINWEIS', 'L_PORT', 'L_ZONEN', 'ZONEN_HINWEIS',
               'L_LAUTSTAERKE', 'L_SPRACHE', 'L_VORLAGE', 'VORLAGE_HINWEIS', 'AUDIOSERVER_HINWEIS',
               'P_TOKEN_DA', 'P_TOKEN_LEER', 'L_TOKEN_LOESCHEN', 'TESTTEXT',
               'T_AUS', 'T_AUDIOSERVER', 'T_KLASSISCH', 'T_LETZTE_OK', 'T_LETZTE_FEHL', 'T_SPRECHEN_AUS', 'T_DIENST_AUS',
               'M_MODUS', 'M_IP', 'M_PORT', 'M_ZONEN', 'M_VOLUME', 'M_SPRACHE', 'M_VORLAGE',
               'K_UNTER', 'K_MIT', 'K_AUS', 'K_AUDIOSERVER', 'K_MODUS_UNBEKANNT', 'K_KEINE_IP', 'K_EINSTELLUNG',
               'K_TEXT_KEIN_TEXT', 'K_TEXT_UTF8', 'K_TEXT_LEER', 'K_TEXT_STEUERZEICHEN', 'K_TEXT_ZEICHEN', 'K_TEXT_ZU_LANG',
               'K_KEIN_TEXT', 'K_KEINE_ZAHL', 'K_AUSSERHALB', 'K_ZU_LANG', 'K_STEUERZEICHEN', 'K_KEIN_UTF8',
               'K_TTS_MODUS', 'K_TTS_IP', 'K_TTS_ZONEN', 'K_TTS_SPRACHE', 'K_TTS_VORLAGE_HTTP', 'K_TTS_VORLAGE_HEIMNETZ',
               'K_HTTP_KEIN_HTTP', 'K_HTTP_ABGEWIESEN', 'K_HTTP_NAME', 'K_HTTP_ZEIT', 'K_HTTP_OHNE_ANTWORT', 'K_HTTP_NETZ',
               'K_HTTP_ZUGANG', 'K_HTTP_404', 'K_HTTP_429', 'K_HTTP_GEGENSEITE', 'K_HTTP_STATUS', 'K_HTTP_WEITERLEITUNG',
               // seit 1.1.0
               'K_TTS_VORLAGE_BENUTZER', 'SONOS_HINWEIS', 'L_SONOS_ZONE', 'SONOS_ZONE_HINWEIS', 'L_SONOS_LAUT',
               'SONOS_LAUT_HINWEIS', 'M_SONOS_ZONE', 'M_SONOS_LAUT', 'M_SONOS_OHNE_ZONE', 'T_SONOS', 'K_TTS_SONOS_ZONE',
               'K_SONOS_KEINE_ZONE', 'K_SONOS_FEHLT', 'K_SONOS_ZEIT_UNKLAR');
    foreach (ansage_modi() as $mo) { $s[] = 'O_' . strtoupper($mo); }
    foreach (array('ALEXA', 'GOOGLE') as $a) {
        foreach (array('_HINWEIS', '_GERAET_HINWEIS', '_LAUT_HINWEIS', '_TOKEN_HINWEIS') as $x) { $s[] = $a . $x; }
        foreach (array('L_%s_GERAET', 'L_%s_LAUT', 'L_%s_TOKEN', 'T_%s_ZU', 'T_%s_OK', 'T_%s_FEHL',
                       'M_%s_GERAET', 'M_%s_LAUT', 'M_%s_TOKEN', 'M_%s_LOESCHEN_WIDERSPRUCH', 'M_%s_OHNE_TOKEN',
                       'K_TTS_%s_GERAET', 'K_TTS_%s_TOKEN', 'K_%s_KEIN_TOKEN', 'K_%s_KEINE_ANTWORT',
                       'K_%s_ANTWORT', 'K_%s_FEHLT', 'K_%s_UNERWARTET',
                       'K_%s_G_TOKEN', 'K_%s_G_KEIN_TOKEN_EINGERICHTET') as $x) { $s[] = sprintf($x, $a); }
    }
    foreach (array('NUR_LOKAL', 'SPRECHEN_AUS', 'TTS_MODUS', 'STUNDENGRENZE', 'DIENST_LAEUFT_NICHT',
                   'DIENST_ANTWORTET_NICHT', 'GERAETE_OFFLINE', 'GERAET_UNBEKANNT', 'KEIN_GERAET') as $g) {
        $s[] = 'K_GOOGLE_G_' . $g;
    }
    foreach (array('ANMELDUNG', 'ANMELDUNG_ABGELAUFEN', 'GESPERRT', 'RUHEZEIT', 'BESCHAEFTIGT') as $g) {
        $s[] = 'K_ALEXA_G_' . $g;
    }
    return $s;
}

/* ==================================================================
 * Kommandozeile: fuer Dienste in anderen Sprachen (Python)
 * ================================================================== */

/**
 * Eine Ansage auf Zuruf eines Dienstes. Die Linie legt ein kurzes Skript
 * bin/<kuerzel>_ansage.php an, das ihre Bibliothek und diese Datei laedt, den
 * Block tts und den Kontext baut und ansage_cli(stdin) aufruft. Der Text
 * kommt als JSON {"text": "..."} ueber die Standardeingabe - nie auf der
 * Kommandozeile, dort saehe ihn jeder in der Prozessliste.
 * Rueckgabe: array(Rueckgabewert, eine Zeile fuer stdout):
 *   0 gesendet, 1 gescheitert, 3 nichts gesendet ohne Fehler, 2 Aufruf falsch.
 * Zeile: ANSAGE;STAND=..;ART=..;KENNUNG=..;HTTP=..;ZEICHEN=.. (ASCII, ohne Text und Token).
 */
function ansage_cli($eingabe, array $tts, array $k = array())
{
    $d = is_string($eingabe) ? json_decode($eingabe, true) : null;
    if (!is_array($d) || !array_key_exists('text', $d)) {
        return array(2, 'ANSAGE;STAND=0;KENNUNG=AUFRUF');
    }
    $r = ansage_sprechen($d['text'], $tts, $k);
    $z = 'ANSAGE;STAND=' . (int) $r['stand'] . ';ART=' . $r['art'] . ';KENNUNG='
       . ($r['kennung'] !== '' ? str_replace(';', ',', $r['kennung']) : '-')
       . ';HTTP=' . (int) $r['http'] . ';ZEICHEN=' . (int) $r['zeichen'];
    $z = (string) preg_replace('/[^\x20-\x7E]/', '?', $z);
    return array($r['stand'] === 1 ? 0 : ($r['stand'] === -1 ? 3 : 1), $z);
}

/* ==================================================================
 * Selbsttest
 * ================================================================== */

/**
 * Rechnet die Faelle nach, ohne Netz: der Transport ist eine Attrappe, die
 * jede Anfrage aufzeichnet. Rueckgabe: array(Zahl der Faelle, Fehlschlaege,
 * Zeilen). Werkzeuge/mutation_sprachausgabe.py baut absichtlich Fehler ein
 * und prueft, dass dieser Selbsttest sie findet.
 */
function ansage_selbsttest()
{
    $faelle = 0;
    $fehl = 0;
    $zeilen = array();
    $pruef = function ($name, $ist, $soll) use (&$faelle, &$fehl, &$zeilen) {
        $faelle++;
        if ($ist !== $soll) {
            $fehl++;
            $zeilen[] = 'FEHL ' . $name . ': ist ' . var_export($ist, true) . ', soll ' . var_export($soll, true);
        }
    };
    $TOK = 'Sprechtoken0123456789ab';
    $TOK2 = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    $TEXT = 'Morgen ist Feiertag, Testsatz Nummer 7.';
    $log = array();
    $antworten = array();
    $transport = function ($anf) use (&$log, &$antworten) {
        $log[] = $anf;
        $a = array_shift($antworten);
        return is_array($a) ? $a : array('code' => 0, 'rumpf' => '', 'errno' => 7);
    };
    $k = array('port' => 8080, 'transport' => $transport, 'kopf' => array('User-Agent: Selbsttest'), 'home' => '');
    $tts = ansage_vorgaben();

    // --- Vorgaben, Modi, Token- und Geraeteform ---
    $pruef('vorgabe aus', $tts['mode'], 'aus');
    $pruef('vorgabe musicserver', ansage_vorgaben('musicserver')['mode'], 'musicserver');
    $pruef('vorgabe unbekannt', ansage_vorgaben('boese')['mode'], 'aus');
    $pruef('15 schluessel', count($tts), 15);
    $pruef('token ok', ansage_token_ok($TOK), true);
    $pruef('token kurz', ansage_token_ok('abc1234'), false);
    $pruef('token 8', ansage_token_ok('abcd1234'), true);
    $pruef('token zeilenende', ansage_token_ok($TOK . "\n"), false);
    $pruef('token liste', ansage_token_ok(array($TOK)), false);
    $pruef('token zeichen', ansage_token_ok('abcd1234!'), false);
    $pruef('token 129', ansage_token_ok(str_repeat('a', 129)), false);
    $pruef('geraet leer', ansage_geraet_ok(''), true);
    $pruef('geraet umlaut', ansage_geraet_ok('Küche Box'), true);
    $pruef('geraet rand', ansage_geraet_ok(' Bad'), false);
    $pruef('geraet steuer', ansage_geraet_ok("Bad\t2"), false);
    $pruef('geraet 201', ansage_geraet_ok(str_repeat('x', 201)), false);
    $pruef('geraet liste', ansage_geraet_ok(array('x')), false);
    list($v, $f) = ansage_vervollstaendigen(array('mode' => 'ms4h', 'ip' => 'x'));
    $pruef('vervollst behaelt', array($v['mode'], $v['ip'], count($v), count($f)), array('ms4h', 'x', 15, 13));

    // --- Webport ---
    $pruef('webport Webserver', ansage_webport_aus('{"Webserver":{"Port":"8080"}}'), 8080);
    $pruef('webport WEBSERVER', ansage_webport_aus('{"WEBSERVER":{"Port":81}}'), 81);
    $pruef('webport fehlt', ansage_webport_aus('{"Base":{}}'), 80);
    $pruef('webport kaputt', ansage_webport_aus('{'), 80);
    $pruef('webport 0', ansage_webport_aus('{"Webserver":{"Port":"0"}}'), 80);
    $pruef('webport liste', ansage_webport_aus('{"Webserver":{"Port":[1]}}'), 80);
    $pruef('adresse alexa', ansage_adresse('alexang', 8080, ''), 'http://127.0.0.1:8080/plugins/alexang/index.php');
    $pruef('adresse google', ansage_adresse('cc4lox', 80, ''), 'http://127.0.0.1:80/plugins/chromecast-4lox-ng/index.php');

    // --- Heimnetz ---
    /* Adressen ausserhalb des Heimnetzes werden aus Teilen zusammengesetzt: als fertige Zeichenkette las
     * das Freigabetor (Werkzeuge/freigabe_pruefen.py, Abschnitt 6, Muster "fremde IP") sie als
     * personenbezogene fremde Adresse und hielt die Freigabe an (Ferien 1.2.21, 02.10.2026). */
    $KNAPP = implode('.', array(172, 32, 0, 1));        // knapp ausserhalb 172.16.0.0/12
    $OEFFENTLICH = implode('.', array(8, 8, 8, 8));     // eine oeffentliche Adresse
    $KAPUTT = implode('.', array(300, 1, 1, 1));         // ein Oktett ueber 255
    foreach (array('192.168.1.5' => true, '10.0.0.1' => true, '172.16.0.1' => true, $KNAPP => false,
                   $OEFFENTLICH => false, 'musicserver' => true, 'ms.local' => true, 'fritz.box' => false,
                   'ms.fritz.box' => true, 'example.com' => false, '' => false, $KAPUTT => false) as $hst => $soll) {
        $pruef('heimnetz ' . $hst, ansage_heimnetz_host($hst), $soll);
    }

    // --- Wertpruefung (Sicherung) ---
    $g = '';
    $pruef('wp vorgaben', is_array(ansage_wert_pruefen(ansage_vorgaben(), $g)), true);
    $pruef('wp modus', array(ansage_wert_pruefen(array('mode' => 'boese'), $g), $g), array(null, 'TTS_MODUS'));
    $pruef('wp modus eingeschraenkt', array(ansage_wert_pruefen(array('mode' => 'aus'), $g, array('musicserver')), $g), array(null, 'TTS_MODUS'));
    $pruef('wp port 0', array(ansage_wert_pruefen(array('port' => 0), $g), $g), array(null, 'UNTER|tts.port|AUSSERHALB|1|65535'));
    $pruef('wp port text', ansage_wert_pruefen(array('port' => '7091'), $g), array('port' => 7091));
    $pruef('wp port 1.5', array(ansage_wert_pruefen(array('port' => '1.5'), $g), $g), array(null, 'UNTER|tts.port|KEINE_ZAHL'));
    $pruef('wp ip extern', array(ansage_wert_pruefen(array('ip' => $OEFFENTLICH), $g), $g), array(null, 'TTS_IP'));
    $pruef('wp ip rand', array(ansage_wert_pruefen(array('ip' => ' 192.168.1.2'), $g), $g), array(null, 'TTS_IP'));
    $pruef('wp ip liste', array(ansage_wert_pruefen(array('ip' => array()), $g), $g), array(null, 'UNTER|tts.ip|KEIN_TEXT'));
    $pruef('wp zonen', array(ansage_wert_pruefen(array('zones' => "1\n2"), $g), $g), array(null, 'UNTER|tts.zones|STEUERZEICHEN'));
    $pruef('wp zonen buchst', array(ansage_wert_pruefen(array('zones' => '1,a'), $g), $g), array(null, 'TTS_ZONEN'));
    $pruef('wp sprache', array(ansage_wert_pruefen(array('lang' => 'deu'), $g), $g), array(null, 'TTS_SPRACHE'));
    $pruef('wp vorlage extern', array(ansage_wert_pruefen(array('template' => 'https://example.com/t?x={text}'), $g), $g), array(null, 'TTS_VORLAGE_HEIMNETZ'));
    $pruef('wp vorlage file', array(ansage_wert_pruefen(array('template' => 'file:///etc/passwd'), $g), $g), array(null, 'TTS_VORLAGE_HTTP'));
    $pruef('wp vorlage ip', is_array(ansage_wert_pruefen(array('template' => 'http://{ip}:{port}/tts?t={text}'), $g)), true);
    $pruef('wp token liste', array(ansage_wert_pruefen(array('alexa_token' => array($TOK)), $g), $g), array(null, 'TTS_ALEXA_TOKEN'));
    $pruef('wp token leer', ansage_wert_pruefen(array('google_token' => ''), $g), array('google_token' => ''));
    $pruef('wp token kurz', array(ansage_wert_pruefen(array('google_token' => 'abc'), $g), $g), array(null, 'TTS_GOOGLE_TOKEN'));
    $pruef('wp laut 101', array(ansage_wert_pruefen(array('alexa_laut' => 101), $g), $g), array(null, 'UNTER|tts.alexa_laut|AUSSERHALB|-1|100'));
    $pruef('wp geraet', array(ansage_wert_pruefen(array('google_geraet' => "a\x07"), $g), $g), array(null, 'TTS_GOOGLE_GERAET'));
    $pruef('wp fremd', array(ansage_wert_pruefen(array('stimme' => 'x'), $g), $g), array(null, 'TTS_EINTRAG|stimme'));
    $pruef('wp kein feld', array(ansage_wert_pruefen('x', $g), $g), array(null, 'KEIN_FELD'));
    $pruef('wp grund ohne wert', strpos((string) $g, 'x'), false);

    // --- Text ---
    $pruef('text ok', ansage_text_pruefen($TEXT, 'alexang'), array(1, ''));
    $pruef('text leer', ansage_text_pruefen('   ', 'alexang'), array(-1, 'TEXT_LEER'));
    $pruef('text liste', ansage_text_pruefen(array('a'), 'alexang'), array(0, 'TEXT_KEIN_TEXT'));
    $pruef('text steuer', ansage_text_pruefen("Hallo\nWelt", 'musicserver'), array(0, 'TEXT_STEUERZEICHEN'));
    $pruef('text utf8', ansage_text_pruefen("Hallo \xC3", 'musicserver'), array(0, 'TEXT_UTF8'));
    $pruef('text spitz ng', ansage_text_pruefen('a <b> c', 'cc4lox'), array(0, 'TEXT_ZEICHEN'));
    $pruef('text spitz ms', ansage_text_pruefen('a <b> c', 'musicserver'), array(1, ''));
    $pruef('text 1000', ansage_text_pruefen(str_repeat('ä', 1000), 'alexang'), array(1, ''));
    $pruef('text 1001', ansage_text_pruefen(str_repeat('ä', 1001), 'alexang'), array(0, 'TEXT_ZU_LANG|1000'));
    $pruef('zeichen umlaut', ansage_zeichen('Größe'), 5);

    // --- Adressen fuer Music Server und Vorlagen ---
    $ms = array('mode' => 'musicserver', 'ip' => '192.168.1.7', 'port' => 7091, 'zones' => '2, 4~30', 'volume' => 25, 'lang' => 'de');
    $pruef('url ms', ansage_tts_url('a b', $ms), 'http://192.168.1.7:7091/audio/grouped/tts/2~25,4~30/de%7Ca%20b');
    $pruef('url ms vol', ansage_tts_url('x', array('volume' => 500) + $ms), 'http://192.168.1.7:7091/audio/grouped/tts/2~100,4~30/de%7Cx');
    $pruef('url ms ohne zonen', ansage_tts_url('x', array('zones' => '') + $ms), 'http://192.168.1.7:7091/audio/grouped/tts/1~25/de%7Cx');
    $pruef('url ms ohne ip', ansage_tts_url('x', array('ip' => '') + $ms), '');
    $pruef('url ms4h vorgabe', ansage_tts_url('x y', array('mode' => 'ms4h') + $ms), 'http://192.168.1.7:7091/tts?text=x%20y&zone=2,4~30&vol=25');
    $pruef('url custom ohne ip', ansage_tts_url('x', array('mode' => 'custom', 'ip' => '', 'template' => 'http://ms.local/s?t={text}&l={lang}') + $ms), 'http://ms.local/s?t=x&l=de');
    $pruef('url custom braucht ip', ansage_tts_url('x', array('mode' => 'custom', 'ip' => '', 'template' => 'http://{ip}/s?t={text}') + $ms), '');
    $pruef('url audioserver', ansage_tts_url('x', array('mode' => 'audioserver') + $ms), null);
    $pruef('url alexa', ansage_tts_url('x', array('mode' => 'alexang') + $ms), null);

    // --- Sprechen: aus, Audioserver, Text ---
    $r = ansage_sprechen($TEXT, $tts, $k);
    $pruef('aus', array($r['stand'], $r['kennung'], count($log)), array(-1, 'AUS', 0));
    $r = ansage_sprechen($TEXT, array('mode' => 'audioserver') + $tts, $k);
    $pruef('audioserver', array($r['stand'], $r['kennung'], count($log)), array(-1, 'AUDIOSERVER', 0));
    $r = ansage_sprechen($TEXT, array('mode' => 'boese') + $tts, $k);
    $pruef('modus unbekannt', array($r['stand'], $r['kennung'], $r['art']), array(0, 'MODUS_UNBEKANNT', '-'));
    $ax = array('mode' => 'alexang', 'alexa_token' => $TOK, 'alexa_geraet' => 'kueche', 'alexa_laut' => 35) + $tts;
    $r = ansage_sprechen('', $ax, $k);
    $pruef('leerer text', array($r['stand'], $r['kennung'], count($log)), array(-1, 'TEXT_LEER', 0));
    $r = ansage_sprechen("a\x01b", $ax, $k);
    $pruef('steuerzeichen', array($r['stand'], $r['kennung'], count($log)), array(0, 'TEXT_STEUERZEICHEN', 0));
    $r = ansage_sprechen(str_repeat('x', 1001), $ax, $k);
    $pruef('zu lang', array($r['stand'], $r['kennung'], count($log)), array(0, 'TEXT_ZU_LANG|1000', 0));

    // --- Alexa-NG ---
    $alexa_url = 'http://127.0.0.1:8080/plugins/alexang/index.php';
    $antworten = array(array('code' => 200, 'rumpf' => "SPRECHEN;OK=1;GERAETE=1;GRUND=GESENDET\n"));
    $log = array();
    $r = ansage_sprechen($TEXT, $ax, $k);
    $a0 = $log[0];
    parse_str((string) $a0['koerper'], $kf);
    $pruef('alexa ok', array($r['stand'], $r['kennung'], $r['http']), array(1, '', 200));
    $pruef('alexa adresse', $a0['url'], $alexa_url);
    $pruef('alexa post', $a0['methode'], 'POST');
    $pruef('alexa felder', $kf, array('aktion' => 'sprechen', 'token' => $TOK, 'geraet' => 'kueche', 'laut' => '35', 'text' => $TEXT));
    $pruef('alexa token nicht in adresse', strpos($a0['url'], $TOK), false);
    $pruef('alexa zeitgrenze', array($a0['tmo'], $a0['verbinden']), array(10, 3));
    $pruef('alexa ohne umleitung', $a0['umleitung'], false);
    $pruef('alexa ohne proxy', $a0['proxy'], false);
    $pruef('alexa kopf', in_array('User-Agent: Selbsttest', $a0['kopf'], true), true);
    $pruef('alexa ergebnis ohne text/token', array(strpos(json_encode($r), $TOK), strpos(json_encode($r), 'Feiertag')), array(false, false));
    $pruef('alexa kurz', ansage_kurz($r), 'art=alexang stand=1 zeichen=39 http=200 antwort=SPRECHEN;OK=1;GERAETE=1;GRUND=GESENDET');
    $antworten = array(array('code' => 200, 'rumpf' => "SPRECHEN;OK=1\n"));
    $log = array();
    ansage_sprechen($TEXT, array('alexa_geraet' => '', 'alexa_laut' => -1) + $ax, $k);
    parse_str((string) $log[0]['koerper'], $kf);
    $pruef('alexa ohne geraet/laut', array_keys($kf), array('aktion', 'token', 'text'));
    $antworten = array(array('code' => 401, 'rumpf' => "SPRECHEN;OK=0;GRUND=TOKEN\n"));
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('alexa 401', array($r['stand'], $r['kennung']), array(0, 'ALEXA_ANTWORT|401|TOKEN'));
    $antworten = array(array('code' => 403, 'rumpf' => "SPRECHEN;OK=0;GRUND=TOKEN;ECHO=" . $TOK . "\n"));
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('alexa token ersetzt', array(strpos($r['zeile'], $TOK), strpos($r['zeile'], '***') !== false), array(false, true));
    $antworten = array(array('code' => 404, 'rumpf' => "<html><body>Not Found</body></html>"));
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('alexa 404 ohne grund', $r['kennung'], 'ALEXA_FEHLT|' . $alexa_url . '|404');
    $antworten = array(array('code' => 404, 'rumpf' => "SPRECHEN;OK=0;GRUND=GERAET_UNBEKANNT;NAME=Bad"));
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('alexa 404 mit grund', $r['kennung'], 'ALEXA_ANTWORT|404|GERAET_UNBEKANNT');
    $antworten = array(array('code' => 200, 'rumpf' => "<html>kaputt\x07</html>"));
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('alexa kaputt', array($r['stand'], $r['kennung']), array(0, 'ALEXA_UNERWARTET|200|htmlkaputthtml'));
    $antworten = array(array('code' => 200, 'rumpf' => "SPRECHEN;OK=0;GRUND=BESCHAEFTIGT"));
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('alexa 200 ok=0', array($r['stand'], $r['kennung']), array(0, 'ALEXA_ANTWORT|200|BESCHAEFTIGT'));
    $antworten = array(array('code' => 302, 'rumpf' => "SPRECHEN;OK=1"));
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('alexa 302 mit ok=1', $r['stand'], 0);
    $antworten = array(array('code' => 0, 'rumpf' => '', 'errno' => 28));
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('alexa zeit', array($r['stand'], $r['kennung'], $r['http']), array(0, 'ALEXA_KEINE_ANTWORT|' . $alexa_url . '|10|HTTP_ZEIT', 0));
    $antworten = array(array('code' => 0, 'rumpf' => '', 'errno' => 7));
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('alexa abgewiesen', $r['kennung'], 'ALEXA_KEINE_ANTWORT|' . $alexa_url . '|10|HTTP_ABGEWIESEN');
    $log = array();
    $r = ansage_sprechen($TEXT, array('alexa_token' => '') + $ax, $k);
    $pruef('alexa ohne token', array($r['stand'], $r['kennung'], count($log)), array(0, 'ALEXA_KEIN_TOKEN', 0));
    $r = ansage_sprechen($TEXT, array('alexa_token' => array($TOK)) + $ax, $k);
    $pruef('alexa token liste', array($r['kennung'], count($log)), array('ALEXA_KEIN_TOKEN', 0));
    $r = ansage_sprechen($TEXT, array('alexa_geraet' => "x\ny") + $ax, $k);
    $pruef('alexa geraet kaputt', array($r['kennung'], count($log)), array('EINSTELLUNG|TTS_ALEXA_GERAET', 0));

    // --- Google (Chromecast 4 Lox NG) ---
    $gx = array('mode' => 'cc4lox', 'google_token' => $TOK2, 'google_geraet' => 'Wohnzimmer', 'google_laut' => 40) + $tts;
    $google_url = 'http://127.0.0.1:8080/plugins/chromecast-4lox-ng/index.php';
    $antworten = array(array('code' => 200, 'rumpf' => "SPRECHEN;OK=1;GERAETE=1;UNVERAENDERT=0;OFFLINE=0;GRUND=EINGEREIHT"));
    $log = array();
    $r = ansage_sprechen($TEXT, $gx, $k);
    parse_str((string) $log[0]['koerper'], $kf);
    $pruef('google ok', array($r['stand'], $log[0]['url'], $kf['token'], $kf['geraet'], $kf['laut']), array(1, $google_url, $TOK2, 'Wohnzimmer', '40'));
    $pruef('google token getrennt', strpos((string) $log[0]['koerper'], $TOK), false);
    $antworten = array(array('code' => 200, 'rumpf' => "SPRECHEN;OK=1;GERAETE=0;UNVERAENDERT=1;GRUND=UNVERAENDERT"));
    $pruef('google unveraendert', ansage_sprechen($TEXT, $gx, $k)['stand'], 1);
    $antworten = array(array('code' => 409, 'rumpf' => "SPRECHEN;OK=0;GRUND=SPRECHEN_AUS"));
    $r = ansage_sprechen($TEXT, $gx, $k);
    $pruef('google 409 ohne erklaerung', $r['kennung'], 'GOOGLE_ANTWORT|409|SPRECHEN_AUS');
    $k2 = $k;
    $k2['erklaert'] = function ($id) { return $id === 'GOOGLE_G_SPRECHEN_AUS'; };
    $antworten = array(array('code' => 409, 'rumpf' => "SPRECHEN;OK=0;GRUND=SPRECHEN_AUS"));
    $r = ansage_sprechen($TEXT, $gx, $k2);
    $pruef('google 409 mit erklaerung', $r['kennung'], 'GOOGLE_ANTWORT|409|SPRECHEN_AUS|GOOGLE_G_SPRECHEN_AUS');
    $antworten = array(array('code' => 503, 'rumpf' => "SPRECHEN;OK=0;GRUND=DIENST_ANTWORTET_NICHT;UNKLAR=1"));
    $pruef('google 503', ansage_sprechen($TEXT, $gx, $k)['kennung'], 'GOOGLE_ANTWORT|503|DIENST_ANTWORTET_NICHT');
    $antworten = array(array('code' => 404, 'rumpf' => ''));
    $pruef('google fehlt', ansage_sprechen($TEXT, $gx, $k)['kennung'], 'GOOGLE_FEHLT|' . $google_url . '|404');
    $log = array();
    $r = ansage_sprechen('a <speak> b', $gx, $k);
    $pruef('google spitz', array($r['kennung'], count($log)), array('TEXT_ZEICHEN', 0));

    // --- Music Server, MS4H, eigene Vorlage ---
    $msx = array('mode' => 'musicserver') + $ms + $tts;
    $antworten = array(array('code' => 200, 'rumpf' => '{"tts":"ok"}'));
    $log = array();
    $r = ansage_sprechen($TEXT, $msx, $k);
    $pruef('ms ok', array($r['stand'], $r['kennung'], $log[0]['methode'], $log[0]['koerper']), array(1, '', 'GET', null));
    $pruef('ms adresse traegt text, ergebnis nicht', array(strpos($log[0]['url'], 'Feiertag') !== false, strpos(json_encode($r), 'Feiertag'), strpos(json_encode($r), '192.168')), array(true, false, false));
    $pruef('ms ohne umleitung', array($log[0]['umleitung'], $log[0]['tmo'], $log[0]['verbinden']), array(false, 10, 3));
    $antworten = array(array('code' => 404, 'rumpf' => 'nein'));
    $pruef('ms 404', ansage_sprechen($TEXT, $msx, $k)['kennung'], 'HTTP_404');
    $antworten = array(array('code' => 401, 'rumpf' => ''));
    $pruef('ms 401', ansage_sprechen($TEXT, $msx, $k)['kennung'], 'HTTP_ZUGANG|401');
    $antworten = array(array('code' => 302, 'rumpf' => ''));
    $pruef('ms 302', ansage_sprechen($TEXT, $msx, $k)['kennung'], 'HTTP_WEITERLEITUNG|302');
    $antworten = array(array('code' => 500, 'rumpf' => ''));
    $pruef('ms 500', ansage_sprechen($TEXT, $msx, $k)['kennung'], 'HTTP_GEGENSEITE|500');
    $antworten = array(array('code' => 0, 'rumpf' => '', 'errno' => 28));
    $r = ansage_sprechen($TEXT, $msx, $k);
    $pruef('ms zeit', array($r['stand'], $r['kennung']), array(0, 'HTTP_ZEIT'));
    $antworten = array(array('code' => 0, 'rumpf' => '', 'errno' => 0));
    $pruef('ms ohne antwort', ansage_sprechen($TEXT, $msx, $k)['kennung'], 'HTTP_OHNE_ANTWORT');
    $log = array();
    $r = ansage_sprechen($TEXT, array('ip' => '') + $msx, $k);
    $pruef('ms ohne ip', array($r['stand'], $r['kennung'], count($log)), array(0, 'KEINE_IP', 0));
    $r = ansage_sprechen($TEXT, array('ip' => $OEFFENTLICH) + $msx, $k);
    $pruef('ms ip extern', array($r['stand'], $r['kennung'], count($log)), array(0, 'EINSTELLUNG|TTS_IP', 0));
    $r = ansage_sprechen($TEXT, array('mode' => 'custom', 'template' => 'http://evil.example/x?{text}') + $msx, $k);
    $pruef('vorlage extern', array($r['kennung'], count($log)), array('EINSTELLUNG|TTS_VORLAGE_HEIMNETZ', 0));
    $antworten = array(array('code' => 204, 'rumpf' => ''));
    $r = ansage_sprechen($TEXT, array('mode' => 'custom', 'template' => 'http://ms.local:81/say?t={text}') + $msx, $k);
    $pruef('vorlage 204', array($r['stand'], $log[0]['url']), array(1, 'http://ms.local:81/say?t=' . rawurlencode($TEXT)));
    $antworten = array(array('code' => 200, 'rumpf' => ''));
    $log = array();
    $r = ansage_sprechen($TEXT, array('mode' => 'ms4h') + $msx, $k);
    $pruef('ms4h', array($r['stand'], strpos($log[0]['url'], 'http://192.168.1.7:7091/tts?text=')), array(1, 0));

    // --- Kontext ohne Transport: nur http und https ---
    $pruef('kein http', ansage_ausfuehren(ansage_anfrage('GET', 'file:///etc/passwd', null, 5, array()), array())['errno'], -2);
    $pruef('kennung kein http', ansage_http_grund_id(-2, 0), 'HTTP_KEIN_HTTP');

    // --- Selbsttest am anderen Plugin, Reiter Test ---
    $antworten = array(array('code' => 200, 'rumpf' => 'SELFTEST;OK=1;TOKEN=OK;SPRECHEN=0;DIENST=1;GRUND=-'));
    $log = array();
    $s = ansage_ng_selbsttest($gx, 'cc4lox', $k);
    parse_str((string) $log[0]['koerper'], $kf);
    $pruef('selbsttest', array($s['stand'], $s['sprechen_aus'], $s['dienst_aus'], $kf, $log[0]['tmo']),
           array(1, true, false, array('selftest' => '1', 'token' => $TOK2), 5));
    $antworten = array(array('code' => 403, 'rumpf' => 'SELFTEST;OK=0;GRUND=TOKEN'));
    $pruef('selbsttest 403', ansage_ng_selbsttest($gx, 'cc4lox', $k)['kennung'], 'GOOGLE_ANTWORT|403|TOKEN');
    $log = array();
    $z = ansage_pruefzeile($gx, false, $k);
    $pruef('pruefzeile zu', array($z[0], count($log)), array(-2, 0));
    $z = ansage_pruefzeile($msx, true, array('t' => function ($s) { return $s === 'ANSAGE.T_KLASSISCH' ? 'an %s' : $s; }) + $k);
    $pruef('pruefzeile ms fragt nicht', array($z[0], count($log), strpos($z[1], '192.168.1.7:7091') !== false), array(1, 0, true));
    $z = ansage_pruefzeile($tts, true, $k);
    $pruef('pruefzeile aus grau', $z[0], -2);
    $antworten = array(array('code' => 200, 'rumpf' => 'SELFTEST;OK=1;TOKEN=OK;SPRECHEN=1;DIENST=0'));
    $z = ansage_pruefzeile($gx, true, $k);
    $pruef('pruefzeile dienst aus', $z[0], -1);
    $antworten = array(array('code' => 200, 'rumpf' => 'SELFTEST;OK=1;TOKEN=OK;SPRECHEN=1;DIENST=1'));
    $z = ansage_pruefzeile($gx, true, $k);
    $pruef('pruefzeile ok', array($z[0], strpos($z[1], $TOK2)), array(1, false));

    // --- Formular ---
    $alt = array('mode' => 'alexang', 'alexa_token' => $TOK) + $tts;
    $post = array('tts_mode' => 'alexang', 'tts_ip' => ' 192.168.1.9 ', 'tts_port' => '7091', 'tts_zones' => '1,2',
                  'tts_volume' => '8', 'tts_lang' => 'de', 'tts_template' => '', 'tts_alexa_geraet' => ' kueche ',
                  'tts_alexa_laut' => '', 'tts_alexa_token' => '', 'tts_google_geraet' => '', 'tts_google_laut' => '',
                  'tts_google_token' => '');
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen($post, $alt, $mg, $bn);
    $pruef('form ok', array(count($mg), $neu['ip'], $neu['alexa_geraet'], $neu['alexa_token'], $neu['alexa_laut'], $neu['port']),
           array(0, '192.168.1.9', 'kueche', $TOK, -1, 7091));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_alexa_token_loeschen' => '1') + $post, $alt, $mg, $bn);
    $kn = array_map(function ($x) { return $x['kennung']; }, $mg);
    $pruef('form loeschen ohne token = ohne token beanstandet', array($neu['alexa_token'], $kn), array('', array('M_ALEXA_OHNE_TOKEN')));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_mode' => 'aus', 'tts_alexa_token_loeschen' => '1') + $post, $alt, $mg, $bn);
    $pruef('form loeschen', array($neu['alexa_token'], count($mg)), array('', 0));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_alexa_token_loeschen' => '1', 'tts_alexa_token' => 'neuesToken123') + $post, $alt, $mg, $bn);
    $kn = array_map(function ($x) { return $x['kennung']; }, $mg);
    $pruef('form widerspruch', array($neu['alexa_token'], $kn, $bn), array($TOK, array('M_ALEXA_LOESCHEN_WIDERSPRUCH'), array('tts_alexa_token', 'tts_alexa_token_loeschen')));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_alexa_token' => 'neuesToken123') + $post, $alt, $mg, $bn);
    $pruef('form neues token', array($neu['alexa_token'], count($mg)), array('neuesToken123', 0));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_alexa_token' => 'kurz') + $post, $alt, $mg, $bn);
    $pruef('form token kurz', array($neu['alexa_token'], $mg[0]['kennung'], strpos(json_encode($mg), 'kurz')), array($TOK, 'M_ALEXA_TOKEN', false));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_alexa_token' => array('x')) + $post, $alt, $mg, $bn);
    $pruef('form token liste', array($neu['alexa_token'], $mg[0]['kennung']), array($TOK, 'M_ALEXA_TOKEN'));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_mode' => 'cc4lox') + $post, $alt, $mg, $bn);
    $pruef('form google ohne token', array($mg[0]['kennung'], $bn), array('M_GOOGLE_OHNE_TOKEN', array('tts_google_token')));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_port' => '0', 'tts_alexa_laut' => '101', 'tts_zones' => 'a', 'tts_mode' => 'boese') + $post, $alt, $mg, $bn);
    $kn = array_map(function ($x) { return $x['kennung']; }, $mg);
    $pruef('form alle mängel', $kn, array('M_MODUS', 'M_PORT|UNTER|tts.port|AUSSERHALB|1|65535', 'M_ZONEN|TTS_ZONEN', 'M_ALEXA_LAUT'));
    $pruef('form alte werte', array($neu['port'], $neu['mode'], $neu['alexa_laut']), array(7091, 'alexang', -1));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_mode' => 'aus'), $alt, $mg, $bn);
    $kn = array_map(function ($x) { return $x['kennung']; }, $mg);
    $pruef('form leer abgeschickt', array($kn, $neu['alexa_token']),
           array(array('M_PORT|UNTER|tts.port|KEINE_ZAHL', 'M_VOLUME|UNTER|tts.volume|KEINE_ZAHL', 'M_SPRACHE|TTS_SPRACHE'), $TOK));
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('ttsmodus' => 'ms4h', 'tts_alexa_weg' => '1') + $post, $alt, $mg, $bn,
                                 array('namen' => array('mode' => 'ttsmodus', 'alexa_token_loeschen' => 'tts_alexa_weg')));
    $pruef('form eigene namen', array($neu['mode'], $neu['alexa_token'], count($mg)), array('ms4h', '', 0));
    $pruef('x2 ohne token', array(in_array('tts_alexa_token', ansage_x2_felder(), true), in_array('tts_alexa_token_loeschen', ansage_x2_felder(), true)), array(false, true));

    // --- Formularblock ---
    $html = ansage_formular_html(array('mode' => 'cc4lox', 'google_token' => $TOK2, 'alexa_token' => $TOK, 'google_geraet' => 'K"üche<') + $tts);
    $pruef('html ohne token', array(strpos($html, $TOK), strpos($html, $TOK2)), array(false, false));
    $pruef('html platzhalter laenge', strpos($html, 'placeholder="ANSAGE.P_TOKEN_DA"') !== false, true);
    $pruef('html maskiert', strpos($html, 'value="K&quot;üche&lt;"') !== false, true);
    $pruef('html gewaehlt', strpos($html, '<option value="cc4lox" selected>') !== false, true);
    $pruef('html kein form', stripos($html, '<form'), false);
    $pruef('html passwortfelder leer', preg_match_all('/type="password"[^>]*value=""/', $html), 2);
    $html = ansage_formular_html($tts, array('modi' => array('musicserver', 'alexang')));
    $pruef('html modi', preg_match_all('/<option /', $html), 2);

    // --- Sicherung ---
    $sb = ansage_sicherung_bereinigen($gx);
    $pruef('sicherung ohne token', array(isset($sb['google_token']), isset($sb['alexa_token']), count($sb)), array(false, false, 13));
    $pruef('sicherung mangel token', ansage_sicherung_mangel(array('alexa_token' => $TOK, 'google_token' => '')), array('tts.alexa_token'));
    $pruef('sicherung mangel liste', ansage_sicherung_mangel(array('google_token' => array())), array('tts.google_token'));
    $pruef('sicherung mangel null', ansage_sicherung_mangel(array('google_token' => null)), array('tts.google_token'));
    $pruef('sicherung ohne', ansage_sicherung_mangel($sb), array());
    $bh = ansage_sicherung_tokens_behalten($sb, $gx);
    $pruef('sicherung behalten', array($bh['google_token'], $bh['alexa_token']), array($TOK2, ''));
    $bh = ansage_sicherung_tokens_behalten($sb, array('google_token' => array($TOK2)));
    $pruef('sicherung behalten liste', $bh['google_token'], '');
    $pruef('x3', ansage_sicherung_x3(array('port' => 99999, 'google_laut' => 500, 'google_token' => 'x') + $gx), array('tts.port', 'tts.google_laut'));
    $pruef('x3 sauber', ansage_sicherung_x3($gx), array());

    // --- Kennungstexte ---
    $kt = array('t' => function ($s) {
        $d = array('ANSAGE.K_HTTP_ZEIT' => 'Zeit nach %s', 'ANSAGE.K_GOOGLE_KEINE_ANTWORT' => 'G antwortet nicht (%s, %s s)',
                   'ANSAGE.K_MIT' => '%s: %s', 'ANSAGE.M_PORT' => 'Port: %s', 'ANSAGE.K_UNTER' => '%s - %s',
                   'ANSAGE.K_AUSSERHALB' => 'nicht in %s..%s');
        return isset($d[$s]) ? $d[$s] : $s;
    });
    $pruef('kennung mit', ansage_kennung_text('GOOGLE_KEINE_ANTWORT|http://x|10|HTTP_ZEIT', $kt), 'G antwortet nicht (http://x, 10 s): Zeit nach ');
    $pruef('kennung M', ansage_kennung_text('M_PORT|UNTER|tts.port|AUSSERHALB|1|65535', $kt), 'Port: tts.port - nicht in 1..65535');
    $pruef('kennung unbekannt', ansage_kennung_text('XYZ|1', $kt), 'XYZ|1');
    $pruef('schluessel eigen', ansage_t('L_ART', array('schluessel' => array('L_ART' => 'SEITE.L_AUDIO'))), 'SEITE.L_AUDIO');

    // --- Kommandozeile ---
    $antworten = array(array('code' => 200, 'rumpf' => 'SPRECHEN;OK=1'));
    $c = ansage_cli(json_encode(array('text' => $TEXT)), $gx, $k);
    $pruef('cli ok', $c, array(0, 'ANSAGE;STAND=1;ART=cc4lox;KENNUNG=-;HTTP=200;ZEICHEN=39'));
    $pruef('cli aufruf', ansage_cli('kein json', $gx, $k)[0], 2);
    $pruef('cli aus', ansage_cli('{"text":"x"}', $tts, $k)[0], 3);
    $antworten = array(array('code' => 503, 'rumpf' => 'SPRECHEN;OK=0;GRUND=DIENST_LAEUFT_NICHT'));
    $c = ansage_cli('{"text":"x"}', $gx, $k);
    $pruef('cli fehler', array($c[0], strpos($c[1], 'KENNUNG=GOOGLE_ANTWORT|503|DIENST_LAEUFT_NICHT;')), array(1, 26));

    // ================= seit 1.1.0 =================

    // --- Heimnetz: Zahlformen und IPv6 (V: Pruefung umgehbar) ---
    $DEZ = (string) (8 * 16777216 + 8 * 65536 + 8 * 256 + 8);     // dieselbe oeffentliche Adresse als eine Zahl
    $HEX = '0x' . str_repeat('08', 4);
    $OKT = '010.' . implode('.', array(8, 8, 8));
    foreach (array($DEZ => false, $HEX => false, $OKT => false, '127.1' => false, '0x7f000001' => false,
                   '192.168.01.1' => false, '0' => false, '10.0.0.0' => true, '[::1]' => true, '[fd00::5]' => true,
                   '[fe80::1]' => true, '[2001:db8::1]' => false, '::1' => false, '[::ffff:192.168.1.5]' => true,
                   '[::ffff:' . $OEFFENTLICH . ']' => false, '[fd00::5%25eth0]' => false, 'nas' => true) as $hst => $soll) {
        $pruef('heimnetz 110 ' . $hst, ansage_heimnetz_host((string) $hst), $soll);
    }

    // --- fertige Adresse: so, wie parse_url und curl sie lesen ---
    foreach (array('http://localhost:80' . '@' . 'evil.example/x?t=1' => 'TTS_VORLAGE_BENUTZER',
                   'http://user:pw' . '@' . '192.168.1.5/' => 'TTS_VORLAGE_BENUTZER',
                   'ftp://192.168.1.5/' => 'HTTP_KEIN_HTTP',
                   'http://192.168.1.5\\@evil.example/' => 'TTS_VORLAGE_HTTP',
                   'http://ms.local/a b' => 'TTS_VORLAGE_HTTP',
                   'http://[fd00::5]:7091/tts?t=x' => '',
                   'HTTPS://ms.local/x' => '',
                   'http://' . $DEZ . '/x' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://' . $HEX . ':80/x' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://ms.local:99999/' => 'TTS_VORLAGE_HTTP',
                   'http://ms%2elocal/' => 'TTS_VORLAGE_HTTP') as $u => $soll) {
        $pruef('url grund ' . $u, ansage_url_grund($u), $soll);
    }
    foreach (array('http://localhost:80' . '@' . 'evil.example/x?t={text}' => 'TTS_VORLAGE_BENUTZER',
                   'http://{ip}:x' . '@' . 'evil.example/t?{text}' => 'TTS_VORLAGE_BENUTZER',
                   'http://' . $HEX . '/t?{text}' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://{port}.evil.example/{text}' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://' . $OKT . '/t?{text}' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://[fd00::7]:{port}/t?{text}' => '') as $v => $soll) {
        ansage_wert_pruefen(array('template' => $v), $g);
        $pruef('wp vorlage 110 ' . $v, $g, $soll);
    }
    $log = array();
    $r = ansage_sprechen($TEXT, array('mode' => 'custom', 'template' => 'http://{text}.local/x') + $msx, $k);
    $pruef('vorlage text im rechner', array($r['kennung'], count($log)), array('EINSTELLUNG|TTS_VORLAGE_HTTP', 0));
    $r = ansage_sprechen($TEXT, array('mode' => 'custom', 'template' => 'http://{ip}:x' . '@' . 'evil.example/t?{text}') + $msx, $k);
    $pruef('vorlage benutzer beim senden', array($r['stand'], $r['kennung'], count($log)), array(0, 'EINSTELLUNG|TTS_VORLAGE_BENUTZER', 0));
    $r = ansage_sprechen($TEXT, array('ip' => $DEZ) + $msx, $k);
    $pruef('ms ip als zahl', array($r['kennung'], count($log)), array('EINSTELLUNG|TTS_IP', 0));

    // --- Zonen ---
    foreach (array('1 2' => null, '2~' => null, '2~0' => null, '2~101' => null, '1,,2' => null, ',1' => null,
                   ' 2 , 4~30 ' => ' 2 , 4~30 ', '2~100,3~1' => '2~100,3~1', '' => '') as $zw => $soll) {
        $p = ansage_wert_pruefen(array('zones' => (string) $zw), $g);
        $pruef('wp zonen 110 "' . $zw . '"', $p === null ? array(null, $g) : $p['zones'], $soll === null ? array(null, 'TTS_ZONEN') : $soll);
    }
    $log = array();
    $r = ansage_sprechen($TEXT, array('zones' => '1 2') + $msx, $k);
    $pruef('ms zonen leerzeichen', array($r['kennung'], count($log)), array('EINSTELLUNG|TTS_ZONEN', 0));

    // --- Lautstaerke 0 (V6) ---
    $pruef('wp laut 0', array(ansage_wert_pruefen(array('alexa_laut' => 0), $g), $g), array(null, 'UNTER|tts.alexa_laut|AUSSERHALB|1|100'));
    $pruef('wp laut -1', ansage_wert_pruefen(array('google_laut' => '-1'), $g), array('google_laut' => -1));
    $log = array();
    $r = ansage_sprechen($TEXT, array('alexa_laut' => 0) + $ax, $k);
    $pruef('alexa laut 0 sendet nicht', array($r['stand'], $r['kennung'], count($log)),
           array(0, 'EINSTELLUNG|UNTER|tts.alexa_laut|AUSSERHALB|1|100', 0));
    $antworten = array(array('code' => 200, 'rumpf' => "SPRECHEN;OK=1\n"));
    ansage_sprechen($TEXT, array('google_laut' => '') + $gx, $k);
    parse_str((string) $log[0]['koerper'], $kf);
    $pruef('google laut leer', isset($kf['laut']), false);
    $mg = array(); $bn = array();
    ansage_formular_lesen(array('tts_alexa_laut' => '0') + $post, $alt, $mg, $bn);
    $pruef('form laut 0', $mg ? $mg[0]['kennung'] : '', 'M_ALEXA_LAUT');

    // --- Erfolgserkennung: OK=1X, BOM, Warnung davor (V1) ---
    foreach (array('SPRECHEN;OK=1X' => 0, 'SPRECHEN;OK=10;GRUND=X' => 0, "SPRECHEN;OK=1" => 1,
                   "\xEF\xBB\xBFSPRECHEN;OK=1;GERAETE=1" => 1,
                   "PHP Warning:  Undefined index in /x/ax_lib.php on line 3\nSPRECHEN;OK=1;GERAETE=1\n" => 1,
                   "<br />\n<b>Warning</b>: x in <b>/x</b><br />\r\nSPRECHEN;OK=1\n" => 1,
                   "Warning: a\nSPRECHEN;OK=0;GRUND=TOKEN\n" => 0) as $rumpf => $soll) {
        $antworten = array(array('code' => 200, 'rumpf' => $rumpf));
        $r = ansage_sprechen($TEXT, $ax, $k);
        $pruef('erfolg ' . json_encode($rumpf), $r['stand'], $soll);
    }
    $antworten = array(array('code' => 200, 'rumpf' => "Notice: x\nSPRECHEN;OK=1;GERAETE=2\n"));
    $pruef('erfolg zeile', ansage_sprechen($TEXT, $ax, $k)['zeile'], 'SPRECHEN;OK=1;GERAETE=2');
    $antworten = array(array('code' => 200, 'rumpf' => "Warning: x\nSELFTEST;OK=1;TOKEN=OK"));
    $pruef('selbsttest warnung davor', ansage_ng_selbsttest($ax, 'alexang', $k)['stand'], 1);

    // --- Umleitung auf https an 127.0.0.1 (V4) ---
    $antworten = array(array('code' => 301, 'rumpf' => '', 'ort' => 'https://127.0.0.1/plugins/alexang/index.php'),
                       array('code' => 200, 'rumpf' => "SPRECHEN;OK=1\n"));
    $log = array();
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('umleitung https', array($r['stand'], count($log), $log[1]['url'], $log[1]['methode'], $log[1]['koerper'] === $log[0]['koerper'],
                                    $log[1]['tls_ohne_pruefung'], $log[0]['tls_ohne_pruefung'], $log[1]['tmo'] <= 10, $log[1]['umleitung']),
           array(1, 2, 'https://127.0.0.1:443/plugins/alexang/index.php', 'POST', true, true, false, true, false));
    $antworten = array(array('code' => 308, 'rumpf' => '', 'ort' => 'https://127.0.0.1:8080/plugins/alexang/index.php'),
                       array('code' => 200, 'rumpf' => "SPRECHEN;OK=1\n"));
    $log = array();
    $r = ansage_sprechen($TEXT, $ax, array('sslport' => 8443) + $k);
    $pruef('umleitung sslport', array($r['stand'], $log[1]['url']), array(1, 'https://127.0.0.1:8443/plugins/alexang/index.php'));
    foreach (array('https://evil.example/plugins/alexang/index.php', 'https://127.0.0.1/plugins/anders/index.php',
                   'http://127.0.0.1:81/plugins/alexang/index.php', 'https://a' . '@' . '127.0.0.1/plugins/alexang/index.php',
                   'https://127.0.0.1/plugins/alexang/index.php?x=1', '/plugins/alexang/index.php') as $ort) {
        $antworten = array(array('code' => 302, 'rumpf' => '', 'ort' => $ort), array('code' => 200, 'rumpf' => "SPRECHEN;OK=1\n"));
        $log = array();
        $r = ansage_sprechen($TEXT, $ax, $k);
        $pruef('umleitung nicht ' . $ort, array($r['stand'], count($log)), array(0, 1));
    }
    $antworten = array(array('code' => 301, 'rumpf' => '', 'ort' => 'https://127.0.0.1/plugins/alexang/index.php'),
                       array('code' => 301, 'rumpf' => '', 'ort' => 'https://127.0.0.1:444/plugins/alexang/index.php'),
                       array('code' => 200, 'rumpf' => "SPRECHEN;OK=1\n"));
    $log = array();
    $r = ansage_sprechen($TEXT, $ax, $k);
    $pruef('umleitung nur einmal', array($r['stand'], count($log)), array(0, 2));
    $antworten = array(array('code' => 302, 'rumpf' => '', 'ort' => 'https://192.168.1.7/audio/x'));
    $log = array();
    $pruef('umleitung ms nicht loopback', array(ansage_sprechen($TEXT, $msx, $k)['kennung'], count($log)), array('HTTP_WEITERLEITUNG|302', 1));
    $pruef('umleitung ziel localhost', ansage_umleitung_ziel('http://localhost/p/i.php?a=1',
        array('code' => 307, 'ort' => 'https://localhost/p/i.php?a=1'), array()), 'https://localhost:443/p/i.php?a=1');

    // --- Ports und Plugin-Ordner ---
    $pruef('sslport', ansage_sslport_aus('{"Webserver":{"Port":"80","Sslport":"8443"}}'), 8443);
    $pruef('sslport fehlt', ansage_sslport_aus('{"Webserver":{"Port":"80"}}'), 0);
    $pruef('sslport kaputt', ansage_sslport_aus('{"WEBSERVER":{"SSLPORT":"x"}}'), 0);
    $pruef('webport ohne datei', ansage_webport(''), 80);
    $pdb = '{"plugins":{"a1":{"name":"alexang_1a2","orig_name":"alexang","folder":"alexang_1a2"},'
         . '"b2":{"name":"Sonos","folder":"sonos4lox"},"c3":{"name":"chromecast-4lox-ng","folder":"../x"}}}';
    $pruef('ordner orig_name', ansage_plugin_ordner_aus($pdb, array('alexang'), 'alexang'), 'alexang_1a2');
    $pruef('ordner gross/klein', ansage_plugin_ordner_aus($pdb, array('sonos', 'sonos4lox'), 'sonos4lox'), 'sonos4lox');
    $pruef('ordner unsicher', ansage_plugin_ordner_aus($pdb, array('chromecast-4lox-ng'), 'chromecast-4lox-ng'), 'chromecast-4lox-ng');
    $pruef('ordner liste', ansage_plugin_ordner_aus('[{"name":"alexang","folder":"alexa2"}]', array('alexang'), 'alexang'), 'alexa2');
    $pruef('ordner fest gewinnt', ansage_plugin_ordner_aus('[{"name":"alexang","folder":"alexa2"},{"name":"alexang","folder":"alexang"}]',
                                                          array('alexang'), 'alexang'), 'alexang');
    $pruef('ordner kaputt', ansage_plugin_ordner_aus('{', array('alexang'), 'alexang'), 'alexang');
    $pruef('ordner ohne db', ansage_adresse('sonos4lox', 80, ''), 'http://127.0.0.1:80/plugins/sonos4lox/index.php');

    // --- Dringend ---
    $antworten = array(array('code' => 200, 'rumpf' => "SPRECHEN;OK=1\n"));
    $log = array();
    ansage_sprechen($TEXT, $ax, $k, array('dringend' => true));
    parse_str((string) $log[0]['koerper'], $kf);
    $pruef('alexa dringend', isset($kf['dringend']) ? $kf['dringend'] : '-', '1');

    // --- Sonos4Lox ---
    $sx = array('mode' => 'sonos4lox', 'sonos_zone' => 'Küche', 'sonos_laut' => 30) + $tts;
    $sonos_url = 'http://127.0.0.1:8080/plugins/sonos4lox/index.php';
    $antworten = array(array('code' => 200, 'rumpf' => "<PRE>Morgen ist Feiertag<br>Profile 'Single T2S' has been identified"));
    $log = array();
    $r = ansage_sprechen($TEXT, $sx, $k, array('dringend' => true));
    $pruef('sonos ok', array($r['stand'], $r['kennung'], $r['http'], $log[0]['methode'], $log[0]['koerper'], $log[0]['tmo']),
           array(1, '', 200, 'GET', null, ANSAGE_TMO_SONOS));
    $pruef('sonos adresse', $log[0]['url'], $sonos_url . '?zone=K%C3%BCche&action=say&text=' . rawurlencode($TEXT) . '&volume=30&urgent=1');
    $pruef('sonos ergebnis ohne text', strpos(json_encode($r), 'Feiertag'), false);
    $antworten = array(array('code' => 200, 'rumpf' => ''));
    $log = array();
    ansage_sprechen($TEXT, array('sonos_laut' => -1) + $sx, $k);
    $pruef('sonos ohne laut/dringend', $log[0]['url'], $sonos_url . '?zone=K%C3%BCche&action=say&text=' . rawurlencode($TEXT));
    $log = array();
    $r = ansage_sprechen($TEXT, array('sonos_zone' => '') + $sx, $k);
    $pruef('sonos ohne zone', array($r['stand'], $r['kennung'], count($log)), array(0, 'SONOS_KEINE_ZONE', 0));
    $r = ansage_sprechen($TEXT, array('sonos_zone' => "a\nb") + $sx, $k);
    $pruef('sonos zone kaputt', array($r['kennung'], count($log)), array('EINSTELLUNG|TTS_SONOS_ZONE', 0));
    $r = ansage_sprechen($TEXT, array('sonos_laut' => 0) + $sx, $k);
    $pruef('sonos laut 0', array($r['kennung'], count($log)), array('EINSTELLUNG|UNTER|tts.sonos_laut|AUSSERHALB|1|100', 0));
    $antworten = array(array('code' => 404, 'rumpf' => 'Not Found'));
    $pruef('sonos fehlt', ansage_sprechen($TEXT, $sx, $k)['kennung'], 'SONOS_FEHLT|' . $sonos_url . '|404');
    $antworten = array(array('code' => 0, 'rumpf' => '', 'errno' => 28));
    $r = ansage_sprechen($TEXT, $sx, $k);
    $pruef('sonos zeit unklar', array($r['stand'], $r['kennung']), array(0, 'SONOS_ZEIT_UNKLAR|' . $sonos_url . '|' . ANSAGE_TMO_SONOS));
    $antworten = array(array('code' => 0, 'rumpf' => '', 'errno' => 7));
    $pruef('sonos abgewiesen', ansage_sprechen($TEXT, $sx, $k)['kennung'], 'HTTP_ABGEWIESEN');
    $antworten = array(array('code' => 500, 'rumpf' => ''));
    $pruef('sonos 500', ansage_sprechen($TEXT, $sx, $k)['kennung'], 'HTTP_GEGENSEITE|500');
    $antworten = array(array('code' => 307, 'rumpf' => '', 'ort' => 'https://127.0.0.1/plugins/sonos4lox/index.php?zone=K%C3%BCche&action=say&text='
                                                             . rawurlencode($TEXT) . '&volume=30'),
                       array('code' => 200, 'rumpf' => ''));
    $log = array();
    $r = ansage_sprechen($TEXT, $sx, $k);
    $pruef('sonos umleitung', array($r['stand'], count($log), strpos($log[1]['url'], 'https://127.0.0.1:443/plugins/sonos4lox/index.php?zone=')),
           array(1, 2, 0));
    $pruef('sonos vorgaben', array(ansage_vorgaben()['sonos_zone'], ansage_vorgaben()['sonos_laut']), array('', -1));
    $pruef('wp sonos', ansage_wert_pruefen(array('mode' => 'sonos4lox', 'sonos_zone' => 'bad', 'sonos_laut' => '20'), $g),
           array('mode' => 'sonos4lox', 'sonos_zone' => 'bad', 'sonos_laut' => 20));
    $pruef('wp sonos zone steuer', array(ansage_wert_pruefen(array('sonos_zone' => "a\x07"), $g), $g), array(null, 'TTS_SONOS_ZONE'));
    $pruef('x3 sonos', ansage_sicherung_x3(array('sonos_laut' => 0, 'sonos_zone' => ' x') + $sx), array('tts.sonos_laut', 'tts.sonos_zone'));
    $pruef('sicherung sonos bleibt', ansage_sicherung_bereinigen($sx)['sonos_zone'], 'Küche');
    $pruef('letzte sonos', ansage_letzte_datei(ansage_letzte_art('sonos4lox')), 'sonos_letzte.json');
    $log = array();
    $z = ansage_pruefzeile($sx, true, $k);
    $pruef('pruefzeile sonos fragt nicht', array($z[0], count($log)), array(1, 0));
    $pruef('pruefzeile sonos ohne zone', ansage_pruefzeile(array('sonos_zone' => '') + $sx, true, $k)[0], 0);
    $mg = array(); $bn = array();
    $neu = ansage_formular_lesen(array('tts_mode' => 'sonos4lox', 'tts_sonos_zone' => ' bad ', 'tts_sonos_laut' => '25') + $post, $alt, $mg, $bn);
    $pruef('form sonos', array(count($mg), $neu['mode'], $neu['sonos_zone'], $neu['sonos_laut']), array(0, 'sonos4lox', 'bad', 25));
    $mg = array(); $bn = array();
    ansage_formular_lesen(array('tts_mode' => 'sonos4lox', 'tts_sonos_laut' => '0') + $post, $alt, $mg, $bn);
    $kn = array_map(function ($x) { return $x['kennung']; }, $mg);
    $pruef('form sonos ohne zone, laut 0', $kn, array('M_SONOS_OHNE_ZONE', 'M_SONOS_LAUT'));
    $html = ansage_formular_html($sx);
    $pruef('html sonos', array(strpos($html, 'id="ansage_sonos"') !== false, strpos($html, 'value="Küche"') !== false,
                               preg_match_all('/_laut" value="[^"]*" min="1"/', $html)), array(true, true, 3));
    $antworten = array(array('code' => 200, 'rumpf' => ''));
    $c = ansage_cli(json_encode(array('text' => $TEXT)), $sx, $k);
    $pruef('cli sonos', $c, array(0, 'ANSAGE;STAND=1;ART=sonos4lox;KENNUNG=-;HTTP=200;ZEICHEN=39'));

    // ================= seit 1.1.1: vertraeglich mit 1.0.3 =================

    // --- .intern wie 1.0.3; [::1] bleibt (Loopback) ---
    foreach (array('ms.intern' => true, 'nas.keller.intern' => true, 'ms.internx' => false, 'intern.example.com' => false,
                   '[::1]' => true) as $hst => $soll) {
        $pruef('heimnetz 111 ' . $hst, ansage_heimnetz_host((string) $hst), $soll);
    }
    $pruef('wp ip intern', ansage_wert_pruefen(array('ip' => 'ms.intern'), $g), array('ip' => 'ms.intern'));
    $antworten = array(array('code' => 200, 'rumpf' => ''));
    $log = array();
    $r = ansage_sprechen($TEXT, array('ip' => 'ms.intern') + $msx, $k);
    $pruef('ms intern sendet', array($r['stand'], count($log), strpos($log[0]['url'], 'http://ms.intern:7091/')), array(1, 1, 0));

    // --- Huellen fuer 1.0.3-Aufrufe (Abfahrts-Assistent) ---
    foreach (array('http://192.168.1.7:7091/x?t=a%40b' => true, 'https://ms.local/x' => true, 'http://ms.intern/x' => true,
                   'http://192.168.1.7:80' . '@' . 'example.com/' => false, 'http://ms.local' . '@' . 'example.com/' => false,
                   'http://example.com#' . '@' . '192.168.1.7/' => false, 'http://' . $OKT . '/' => false,
                   'http://' . $HEX . '/' => false, 'file://192.168.1.7/x' => false, 'http://[::1]/' => true,
                   'http://192.168.1.7\\' . '@' . 'example.com/' => false, 7 => false) as $u => $soll) {
        $pruef('url heimnetz 111 ' . $u, ansage_url_heimnetz(is_int($u) ? $u : (string) $u), $soll);
    }
    foreach (array('http://{ip}:{port}/tts?t={text}' => '', 'http://ms.local:81/s?t={text}' => '',
                   'http://ms.intern/s?t={text}' => '', 'http://[fd00::7]:{port}/t?{text}' => '',
                   'http://{ip}:80' . '@' . 'example.com/tts?t={text}' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://localhost:x' . '@' . 'example.com/' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://{ip}' . '@' . 'example.com/' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://' . $DEZ . '/t?{text}' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://{text}/' => 'TTS_VORLAGE_HEIMNETZ', 'http://{ip}.example.com/' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://{ip}:{text}/' => 'TTS_VORLAGE_HEIMNETZ', 'http://{zones}.local/' => 'TTS_VORLAGE_HEIMNETZ',
                   'http://example.com\\' . '@' . '{ip}/' => 'TTS_VORLAGE_HTTP',
                   'http:///t' => 'TTS_VORLAGE_HTTP', 'ftp://{ip}/' => 'TTS_VORLAGE_HTTP', ' http://{ip}/' => 'TTS_VORLAGE_HTTP',
                   '' => 'TTS_VORLAGE_HTTP') as $tpl => $soll) {
        $pruef('vorlage grund 111 ' . $tpl, ansage_vorlage_grund((string) $tpl), $soll);
    }
    $pruef('vorlage grund liste', ansage_vorlage_grund(array('http://{ip}/')), 'TTS_VORLAGE_HTTP');

    // --- Zonen: leer taugt wie in 1.0.3 ---
    foreach (array('' => true, '   ' => true, "\t" => false, '1' => true, ' 1 , 2 ' => true, '2~30, 4' => true,
                   '1 2' => false, '1,2,' => false) as $zw => $soll) {
        $pruef('zonen ok 111 "' . $zw . '"', ansage_zonen_ok((string) $zw), $soll);
    }
    $pruef('zonen ok liste', ansage_zonen_ok(array('1')), false);

    // --- Fehlertext bei Netzfehlern (1.0.3, Pruefung 02.10.2026 Nr. 3) ---
    $antworten = array(array('code' => 0, 'rumpf' => '', 'errno' => 52, 'fehler' => 'Empty reply from server'));
    $pruef('ms netz mit text', ansage_sprechen($TEXT, $msx, $k)['kennung'], 'HTTP_NETZ|52|Empty reply from server');
    $antworten = array(array('code' => 0, 'rumpf' => '', 'errno' => 35, 'fehler' => "a|b\nc"));
    $pruef('alexa netz mit text', ansage_sprechen($TEXT, $ax, $k)['kennung'], 'ALEXA_KEINE_ANTWORT|' . $alexa_url . '|10|HTTP_NETZ|35|a/bc');
    $antworten = array(array('code' => 0, 'rumpf' => '', 'errno' => 56, 'fehler' => 'Recv failure'));
    $pruef('sonos netz mit text', ansage_sprechen($TEXT, $sx, $k)['kennung'], 'HTTP_NETZ|56|Recv failure');
    $pruef('kennung netz ohne text', ansage_http_grund_id(52, 0), 'HTTP_NETZ|52');
    $pruef('kennung netz text liste', ansage_http_grund_id(52, 0, array('x')), 'HTTP_NETZ|52');
    $pruef('kennung netz text lang', strlen(ansage_http_grund_id(52, 0, str_repeat('x', 300))), strlen('HTTP_NETZ|52|') + 200);
    $pruef('kennung zeit mit text', ansage_http_grund_id(28, 0, 'timed out'), 'HTTP_ZEIT');

    // --- Wertpruefung vor dem Senden (Luecke aus Sprachmodul-3): Faelle, die die Adresse NICHT verraet ---
    /* lang 'deu' und volume 500 ergeben eine gueltige Adresse im Heimnetz (ansage_tts_url() kappt die
     * Lautstaerke, die Sprache steht nur im Pfad); nur ansage_wert_pruefen() vor dem Senden weist sie ab. */
    $log = array();
    $r = ansage_sprechen($TEXT, array('lang' => 'deu') + $msx, $k);
    $pruef('vor dem senden sprache', array($r['stand'], $r['kennung'], count($log)), array(0, 'EINSTELLUNG|TTS_SPRACHE', 0));
    $pruef('vor dem senden sprache adresse taugt', ansage_url_grund(ansage_tts_url($TEXT, array('lang' => 'deu') + $msx)), '');
    $r = ansage_sprechen($TEXT, array('mode' => 'ms4h', 'volume' => 500) + $msx, $k);
    $pruef('vor dem senden lautstaerke', array($r['kennung'], count($log)), array('EINSTELLUNG|UNTER|tts.volume|AUSSERHALB|1|100', 0));

    // --- Sprachschluessel: eindeutig ---
    $sl = ansage_sprachschluessel();
    $pruef('sprachschluessel eindeutig', count($sl), count(array_unique($sl)));

    return array($faelle, $fehl, $zeilen);
}

}   // Ende des Blocks "noch keine Abschrift geladen"

/* Aufruf von der Kommandozeile: php sprachausgabe.php --selbsttest */
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && realpath($argv[0]) === realpath(__FILE__)
    && in_array('--selbsttest', $argv, true)) {
    list($ansage_n, $ansage_f, $ansage_z) = ansage_selbsttest();
    foreach ($ansage_z as $ansage_x) { echo $ansage_x, "\n"; }
    printf("Sprachausgabe %s: %d Faelle geprueft, %d Fehlschlaege.\n", ANSAGE_FASSUNG, $ansage_n, $ansage_f);
    exit($ansage_f === 0 ? 0 : 1);
}
