<?php
/**
 * Gemeinsames Sicherungsmodul fuer die LoxBerry-Plugins des Hauses:
 * "Einstellungen sichern" und "Einstellungen zurueckspielen"
 *
 * DIESE DATEI IST IN MEHREREN PLUGINS BYTEWEISE GLEICH.
 * Die Stammfassung liegt unter Werkzeuge/gemeinsam/sicherung.php im
 * Arbeitsordner. Ein LoxBerry-Plugin kann keine Datei eines anderen Plugins
 * laden; deshalb traegt jede Linie eine Abschrift neben ihrer Bibliothek.
 * Werkzeuge/sicherung_einbauen.py legt sie byte-gleich hinein und meldet mit
 * --pruefen jede Abschrift, die von der Stammfassung abweicht, und jeden
 * Abschnitt [SICHERUNG] der Sprachdateien, der nicht zu
 * Werkzeuge/gemeinsam/sicherung_texte.json passt. Wer hier etwas aendert,
 * aendert es in der Stammfassung, hebt SICHERUNG_FASSUNG an, laesst
 * sicherung_selbsttest() (php sicherung.php --selbsttest) und
 * Werkzeuge/sicherung_pruefstand.py laufen und verteilt danach.
 *
 * Auftrag des Hausherrn vom 12.11.2026: eine gemeinsame Vorlage, damit sich
 * alle Plugins beim Sichern und Zurueckspielen gleich verhalten. Die Regeln,
 * die dieses Modul festschreibt (Entscheidungen des Hausherrn 12.11.2026):
 *
 *   1. Kennzeichen. Jede Sicherung traegt einen lesbaren Kopf mit dem
 *      Kennzeichen des Plugins (_plugin) und der Fassung (_fassung). Eine
 *      Datei mit falschem oder fehlendem Kennzeichen wird als Ganzes
 *      abgewiesen. Ausnahme: eine alte Sicherung der eigenen Linie, die das
 *      Kennzeichen noch nicht kennt - die Linie erkennt sie ueber ihren
 *      Uebersetzer ('uebersetzer'), und die Meldung sagt es.
 *   2. Unbekannte Schluessel einer eigenen Sicherung, oben und in Bloecken,
 *      werden verworfen und mit Namen gemeldet, nie mit Wert. Die Datei wird
 *      trotzdem zurueckgespielt.
 *   3. Ein leerer Wert ('' - genau die leere Zeichenkette) ueberschreibt
 *      keinen bestehenden. Er bleibt stehen und wird mit Namen genannt.
 *      Beidseits leer: keine Meldung. Listen und Felder sind keine leeren
 *      Werte in diesem Sinn; ein leeres Feld [] wird wie bisher uebernommen.
 *   4. Halb gueltig: wird ein bekannter Wert beanstandet, aendert sich gar
 *      nichts. Alle Beanstandungen werden gesammelt, nicht nur die erste.
 *   5. Inhalt: Aktionstoken und Zugangsdaten gehoeren hinein; der
 *      Formulartoken und die Sprechtoken nicht (das bestimmt die Linie in
 *      'sichern' und 'nie_aus_datei'). Ein selbsterzeugtes Geheimnis wird hier
 *      nie erzeugt.
 *   6. Danach zieht die Zweitschrift mit (Speicherfunktion der Linie, das
 *      Modul prueft es, wenn 'zweitschrift' genannt ist), der Dienst wird
 *      nachgezogen ('nachziehen'), und die Meldung sagt, was mit ihm geschah.
 *   7. Alles uebrige verhaelt sich wie bisher in der Linie: welche Schluessel
 *      fehlen duerfen ('fehlt'), welche Werte gelten ('pruefen', 'quer'),
 *      was vor dem Speichern noch geschieht ('vorbereiten').
 *   8. Seit 1.1.0 (Entscheidung des Hausherrn 12.11.2026, Welle 1): der Knopf
 *      "Einstellungen zurueckspielen" wirkt nur mit gesetztem Haken
 *      (Feld SICHERUNG_HAKEN = zurueck_ok, Wert 1). Ohne Haken wird nichts
 *      gelesen und nichts geaendert, und die Meldung sagt es. Zeile, Feldname,
 *      Text und Pruefung kommen aus diesem Modul (sicherung_haken_zeile(),
 *      sicherung_eingang()); keine Linie baut den Haken selbst. Ebenso prueft
 *      sicherung_eingang() die hochgeladene Datei (is_uploaded_file(), Groesse
 *      'max_byte') fuer alle Linien gleich, bevor sie gelesen wird.
 *   9. Seit 1.2.0 (Entscheidung des Hausherrn 12.11.2026: "Ja, alle acht ins
 *      Modul"): was die Linien in Welle 1 um das Modul herum bauten, kann das
 *      Modul selbst - Rohwert an 'pruefen' und 'quer', flache Teile
 *      ('teile_flach'), eigene Warnsaetze ('warnung'), ein Muster fuer fruehere
 *      Kennzeichen ('kennzeichen_frueher_muster'), 'umbenennen', 'fehlt' fuer
 *      Unterschluessel in Bloecken, Hinweise aus Teilen und fehlt_vorgabe in der
 *      Meldung, und 'vorbedingung'. Alles wahlfrei; ohne die Schluessel bleibt
 *      alles wie in 1.1.0.
 *  10. Seit 1.2.1 (Entscheidung des Hausherrn 12.11.2026: "Ja, 1.2.1 bauen"):
 *      ein Schluessel aus 'nie_aus_datei' (ein Vermerk) behaelt beim
 *      Zurueckspielen seinen geltenden Wert, wenn er in der geltenden
 *      Konfiguration steht; sonst bleibt alles wie bisher (Vorgabe, wenn er in
 *      'vorgaben' steht, sonst fehlt er). Er kommt nie aus der Datei und wird
 *      nie gesichert. Bis 1.2.0 begann die neue Konfiguration bei den Vorgaben,
 *      und der Vermerk ging verloren (gemessen am Geraet 12.11.2026, AWM-Abfuhr:
 *      ansage_token_herkunft fehlte danach, uebernommene Sprechtoken galten als
 *      von Hand eingetragen). Setzt 'vorbehandeln' ihn, gilt dieser Wert.
 *  11. Seit 1.2.2 (Entscheidung des Hausherrn 13.11.2026: "Weg (b), Modul
 *      1.2.2"): fehlt ein Schluessel mit 'fehlt' => 'behalten' in der Datei
 *      und hat der Rueckruf 'vorbehandeln' der Linie fuer ihn einen Wert
 *      gesetzt (eine Uebergangsregel der Linie, etwa aus einem anderen
 *      Schluessel derselben Datei), bleibt dieser Wert; er ging wie jeder Wert
 *      durch Regel 4. Die Meldung nennt ihn mit K_FEHLT_UEBERGANG, nicht als
 *      Werkseinstellung und nicht als behalten ($r['fehlt_uebergang']). Bis
 *      1.2.1 setzte 'behalten' auch dann den geltenden Wert und ueberschrieb
 *      die Regel (Alexa-NG Gruppe 6a, Chromecast4lox Gruppe 6b fuehrten deshalb
 *      'vorgabe', und die Meldung sagte faelschlich "Werkseinstellung").
 *      Setzt 'vorbehandeln' nichts, bleibt alles wie bisher.
 *
 * Die Funktionen tragen das neutrale Kuerzel 'sicherung_' (wie 'ansage_' in
 * sprachausgabe.php). Laufen zwei Abschriften in einem PHP-Prozess, laedt sich
 * das Modul nur einmal, und es gilt die zuerst geladene Abschrift. Deshalb:
 * keine Funktion entfernen oder umbenennen, keine Bedeutung eines Parameters
 * oder einer Rueckgabe aendern; Neues kommt als neue Funktion oder als neuer,
 * wahlfreier Schluessel der Beschreibung.
 *
 * Laeuft unter PHP 7.4 bis 8.5 ohne Meldung; keine Sprachmittel ab 8.0
 * (kein match, kein str_contains, keine nullbaren Typen).
 *
 * ------------------------------------------------------------------
 * Die Beschreibung $b, die die Linie uebergibt
 * ------------------------------------------------------------------
 *
 *   'kennzeichen'  Pflicht. Wert von _plugin, /^[A-Za-z0-9_.\-]{1,80}\z/.
 *                  Ein bestehendes Kennzeichen einer Linie bleibt, wie es ist.
 *   'kennzeichen_frueher'  Liste frueherer Kennzeichen derselben Linie.
 *   'kennzeichen_frueher_muster'  (1.2.0) regulaerer Ausdruck fuer fruehere
 *                  Kennzeichen, die einen Teil der Anlage trugen (Ordner).
 *   'umbenennen'   (1.2.0) Name in der Datei => heutiger Name, vor allen Regeln;
 *                  nur wenn der heutige Name in der Datei fehlt (z. B. _amazon).
 *   'teile_flach'  (1.2.0) Teil => Praefix: der Teil steht in der Datei als
 *                  flache Schluessel <praefix><feld> (BYD zugang_benutzer ...);
 *                  Regel 2, 3 und 'fehlt' gelten je Feld wie in einem Block,
 *                  Namen in den Meldungen wie in der Datei.
 *   'warnung'      (1.2.0) function ($cfg) -> Liste von Saetzen (ohne Werte),
 *                  die sicherung_bauen() an _warnung anhaengt.
 *   'vorbedingung' (1.2.0) function ($neu, $jetzt, $r) -> Liste von Saetzen:
 *                  nicht leer heisst, es wird nicht gespeichert; die Meldung
 *                  nennt diese Saetze statt K_SCHREIBFEHLER.
 *   'hinweis'      Text fuer _hinweis (sagt, von welchem Plugin die Datei ist
 *                  und dass sie Geheimnisse traegt). Ohne Angabe K_HINWEIS.
 *   'fassung'      Fassung fuer _fassung: Text oder function () -> Text.
 *                  Ohne Angabe sicherung_fassung_lesen($b).
 *   'home', 'ordner', 'plugin_cfg'  fuer sicherung_fassung_lesen(): LoxBerry-
 *                  Wurzel (Plugin-Datenbank), Plugin-Ordner, plugin.cfg als
 *                  Rueckfall im entpackten Archiv.
 *   'kopf'         weitere Kopffelder (Schluessel mit _), nach _stand.
 *   'vorgaben'     Pflicht. Die Vorgaben der Linie; jeder Schluessel darin
 *                  ist bekannt. Der Ausgangspunkt beim Zurueckspielen.
 *   'schluessel'   Name => Knoten, verfeinert einzelne Schluessel:
 *                    'art'     'wert' (ab Werk) | 'block' | 'liste' | 'offen'
 *                    'schluessel' (block) Name => Knoten der Unterschluessel
 *                    'eintrag' (liste) Knoten eines Eintrags (meist block)
 *                    'zuordnung' (liste) Feld, ueber das ein Eintrag der
 *                              Datei seinem Gegenstueck am Ziel zugeordnet
 *                              wird (fuer Regel 3); '' = keine Zuordnung
 *                    'fehlt'   oben: 'pflicht' (ab Werk) | 'vorgabe' |
 *                              'behalten' (geltender Wert, wird genannt);
 *                              seit 1.2.0 auch an Unterschluesseln eines
 *                              Blocks: 'behalten' (Name block.feld genannt);
 *                              seit 1.2.2 oben: setzt 'vorbehandeln' den
 *                              fehlenden Schluessel, bleibt dessen Wert
 *                              (Name in 'fehlt_uebergang')
 *                    'pruefen' function ($wert, $name, $neu, $roh) -> '' | Text |
 *                              Liste von Texten; ersetzt $b['pruefen']. $roh
 *                              (seit 1.2.0): der Wert, wie er in der Datei
 *                              steht, vor Regel 2 und 3 (null = fehlt)
 *                    'wandeln' function ($wert) -> Wert, nach bestandener
 *                              Pruefung (Normalform der Linie)
 *                    'sichern' function ($wert) -> Wert beim Sichern
 *                              (z. B. ohne Sprechtoken)
 *                  Hilfen: sicherung_block(), sicherung_liste(), sicherung_offen().
 *                  'offen' heisst: Unterschluessel werden nicht gegen eine
 *                  Liste gehalten (dynamische Namen); die Pruefung der Linie
 *                  entscheidet.
 *   'alt'          Schluessel frueherer Fassungen, die still uebergangen
 *                  werden (wie bisher in der Linie).
 *   'nie_aus_datei' Schluessel, die nie aus einer Datei kommen (Vermerke);
 *                  still uebergangen, nie gesichert. Seit 1.2.1 behaelt ein
 *                  solcher Schluessel beim Zurueckspielen seinen geltenden
 *                  Wert ($jetzt), wenn er dort steht.
 *   'teile'        Name => function ($wert, $jetzt) -> array(Wert|null,
 *                  Beanstandungen[] [, Hinweise[] seit 1.2.0]); Teile der Datei, die nicht in die
 *                  Konfiguration gehen (eigene Geheimnisdatei). Ergebnis in
 *                  $r['teile'][Name]. Steht der Name auch in 'schluessel'
 *                  (als block), gelten Regel 2 und 3 auch dort; der geltende
 *                  Stand kommt aus 'teile_jetzt'[Name] (Wert oder Funktion).
 *   'pruefen'      function ($schluessel, $wert, $neu, $roh) -> '' | Text | Liste.
 *   'quer'         function ($neu, $r, $daten) -> Liste von array(Name, Text);
 *                  $daten (seit 1.2.0) die Datei vor Regel 2 und 3.
 *   'uebersetzer'  function ($daten) -> array|null: nur fuer eine Datei OHNE
 *                  Kennzeichen. Eine alte eigene Sicherung kommt (gegebenenfalls
 *                  umgeschrieben) zurueck, alles andere null.
 *   'vorbehandeln' function ($daten, $jetzt, $roh) -> $daten: nach Regel 2
 *                  und 3, vor der Pruefung (Bloecke vervollstaendigen ...).
 *                  Seit 1.2.2: ein Wert, den sie fuer einen in der Datei
 *                  fehlenden Schluessel mit 'fehlt' => 'behalten' setzt
 *                  (Uebergangsregel), bleibt und wird so genannt.
 *   'jetzt'        function () -> die geltende Konfiguration.
 *   't'            function ($schluessel) -> Text (wie xx_t()); Abschnitt
 *                  'abschnitt', ab Werk SICHERUNG.
 *   'e'            function ($s) -> maskierter Text; ab Werk htmlspecialchars.
 *   'max_byte'     Obergrenze der Datei, ab Werk SICHERUNG_MAX_BYTE.
 * Fuer sicherung_zurueckspielen() dazu:
 *   'sperre'       function () -> Griff | null;  'freigeben' function ($griff)
 *   'vorbereiten'  function ($neu, $jetzt, $r) -> $neu (vor dem Speichern)
 *   'speichern'    function ($neu, $jetzt, $r) -> bool (zieht die Zweitschrift)
 *   'zweitschrift' function () -> array(Konfigurationsdatei, Zweitschrift)
 *   'nachziehen'   function ($neu, $jetzt, $r) -> Liste von Meldungen
 *   'log'          function ($zeile)
 *
 * ------------------------------------------------------------------
 * Das Ergebnis $r von sicherung_lesen()
 * ------------------------------------------------------------------
 *
 *   'ok'            true = zurueckspielbar
 *   'neu'           die neue Konfiguration (null, wenn nicht ok)
 *   'anzahl'        uebernommene Schluessel der obersten Ebene
 *   'eigen'         'kennzeichen' | 'frueher' | 'uebersetzt' | ''
 *   'fremd'         '' | 'form' | 'gross' | 'kennzeichen' | 'ohne'
 *   'kennzeichen_datei'  Kennzeichen der Datei (nur in Namensform, sonst '?')
 *   'fassung_datei' _fassung der Datei (nur in Namensform)
 *   'beanstandet'   Liste von array('name' => ..., 'text' => ...)
 *   'verworfen'     Namen unbekannter Schluessel (Regel 2)
 *   'leer_behalten' Namen leerer Werte, deren bisheriger Wert bleibt (Regel 3)
 *   'fehlt_behalten' Namen, die fehlen und den geltenden Wert behalten
 *   'fehlt_vorgabe' Namen, die fehlen und auf die Vorgabe gehen
 *   'fehlt_uebergang' (1.2.2) Namen, die fehlen und deren Wert 'vorbehandeln'
 *                   gesetzt hat ('fehlt' => 'behalten'; Uebergangsregel der Linie)
 *   'teile'         Ergebnisse der Teile
 *   'teile_hinweise' (1.2.0) Hinweise der Teile (fertige Saetze)
 * Ein Ergebnis von sicherung_zurueckspielen() traegt seit 1.2.0 dazu
 *   'vorbedingung'  Saetze der Vorbedingung (leer = erfuellt oder nicht gefragt)
 * Ein Ergebnis von sicherung_eingang(), das vor dem Lesen endete, traegt dazu
 *   'abbruch'       'haken' | 'datei' | 'gross' (sonst fehlt der Schluessel)
 * und die Felder von sicherung_zurueckspielen() ('gespeichert' false).
 * Alle Namen stammen aus der Datei und gehen durch sicherung_name(): kein
 * Zeilenumbruch, kein Semikolon, hoechstens 64 Zeichen. Werte stehen in
 * keinem Feld ausser 'neu' und 'teile'.
 */

/* Kein Endpunkt (Regeln/03): die Datei liegt meist im unangemeldeten Baum
 * webfrontend/html/ und waere dort direkt aufrufbar. Ein direkter Aufruf ueber
 * den Webserver bekommt 403 und tut sonst nichts. */
if (PHP_SAPI !== 'cli') {
    $sicherung_einstieg = get_included_files();
    if (isset($sicherung_einstieg[0]) && realpath($sicherung_einstieg[0]) === realpath(__FILE__)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "SICHERUNG;OK=0;GRUND=KEIN_ENDPUNKT\n";
        exit;
    }
    unset($sicherung_einstieg);
}

if (!defined('SICHERUNG_FASSUNG')) {

define('SICHERUNG_FASSUNG', '1.2.2');
/** Groesste Sicherungsdatei, die gelesen wird (Byte). */
define('SICHERUNG_MAX_BYTE', 262144);
/** Feldname des Hakens "Zurueckspielen bestaetigen" (seit 1.1.0; Wert 1). */
define('SICHERUNG_HAKEN', 'zurueck_ok');

/* ==================================================================
 * Bausteine der Beschreibung
 * ================================================================== */

/** Ein Block (Feld mit festen Unterschluesseln). $knoten verfeinert einzelne. */
function sicherung_block(array $namen, array $knoten = array())
{
    $s = array();
    foreach ($namen as $n) { $s[(string) $n] = array('art' => 'wert'); }
    foreach ($knoten as $n => $k) { $s[(string) $n] = $k; }
    return array('art' => 'block', 'schluessel' => $s);
}

/** Eine Liste von Eintraegen mit festen Feldern; $zuordnung = Feld fuer Regel 3. */
function sicherung_liste(array $namen, $zuordnung = '', array $knoten = array())
{
    return array('art' => 'liste', 'eintrag' => sicherung_block($namen, $knoten),
                 'zuordnung' => is_string($zuordnung) ? $zuordnung : '');
}

/** Ein Feld mit dynamischen Unterschluesseln: die Pruefung der Linie entscheidet. */
function sicherung_offen()
{
    return array('art' => 'offen');
}

/* ==================================================================
 * Kleinigkeiten
 * ================================================================== */

/** Text zu einer Kennung; ohne Uebersetzer oder ohne Eintrag der Schluessel selbst. */
function sicherung_t($id, array $b)
{
    $ab = (isset($b['abschnitt']) && is_string($b['abschnitt'])) ? $b['abschnitt'] : 'SICHERUNG';
    $s = $ab . '.' . $id;
    if (isset($b['t']) && is_callable($b['t'])) { return (string) call_user_func($b['t'], $s); }
    return $s;
}

/** Maskieren fuer die Ausgabe. */
function sicherung_e($s, array $b)
{
    if (isset($b['e']) && is_callable($b['e'])) { return (string) call_user_func($b['e'], (string) $s); }
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Ein Name aus der Datei fuer Meldung und Protokoll: nur Namenszeichen, hoechstens 64. */
function sicherung_name($n)
{
    $n = (string) preg_replace('/[^A-Za-z0-9_.\-\[\]]/', '?', (string) $n);
    if ($n === '') { $n = '?'; }
    return strlen($n) > 64 ? substr($n, 0, 64) . '...' : $n;
}

/** Ist der Text ein Name in Namensform? (Kennzeichen, Fassung) */
function sicherung_namensform($s)
{
    return is_string($s) && preg_match('/^[A-Za-z0-9_.\-]{1,80}\z/', $s) === 1;
}

/** Regel 3: bleibt der bisherige Wert, weil die Datei an dieser Stelle leer ist? */
function sicherung_leer_bleibt($datei, $jetzt)
{
    if ($datei !== '') { return false; }
    if (is_bool($jetzt)) { return $jetzt; }
    return (is_string($jetzt) || is_int($jetzt) || is_float($jetzt)) && (string) $jetzt !== '';
}

/** Der Knoten zu einem Schluessel der obersten Ebene. */
function sicherung_knoten(array $b, $k)
{
    if (isset($b['schluessel'][$k]) && is_array($b['schluessel'][$k])) { return $b['schluessel'][$k]; }
    return array('art' => 'wert');
}

/** Liste der Namen ohne Doppel, Reihenfolge des ersten Auftretens. */
function sicherung_einmal(array $l)
{
    $aus = array();
    foreach ($l as $x) { if (!in_array($x, $aus, true)) { $aus[] = $x; } }
    return $aus;
}

/**
 * Die Fassung des Plugins ohne zweite Quelle: zuerst die Plugin-Datenbank des
 * LoxBerry (data/system/plugindatabase.json, Eintrag mit 'folder' = $b['ordner']),
 * dann die Zeile VERSION= der plugin.cfg ($b['plugin_cfg'], fuer das entpackte
 * Archiv). '' = nicht feststellbar. Eine feste Nummer im Code waere eine fuenfte
 * Stelle neben plugin.cfg, release.cfg, prerelease.cfg und README.
 */
function sicherung_fassung_lesen(array $b)
{
    $home = isset($b['home']) && is_string($b['home']) ? $b['home'] : '';
    $ordner = isset($b['ordner']) && is_string($b['ordner']) ? $b['ordner'] : '';
    if ($home !== '' && $ordner !== '' && is_file($home . '/data/system/plugindatabase.json')) {
        $d = json_decode((string) @file_get_contents($home . '/data/system/plugindatabase.json'), true);
        if (isset($d['plugins']) && is_array($d['plugins'])) {
            foreach ($d['plugins'] as $e) {
                if (is_array($e) && isset($e['folder']) && $e['folder'] === $ordner
                    && isset($e['version']) && sicherung_namensform((string) $e['version'])) {
                    return (string) $e['version'];
                }
            }
        }
    }
    $cfg = isset($b['plugin_cfg']) && is_string($b['plugin_cfg']) ? $b['plugin_cfg'] : '';
    if ($cfg !== '' && is_file($cfg)) {
        foreach ((array) @file($cfg, FILE_IGNORE_NEW_LINES) as $z) {
            if (preg_match('/^\s*VERSION\s*=\s*([A-Za-z0-9_.\-]{1,40})\s*$/', (string) $z, $m)) { return $m[1]; }
        }
    }
    return '';
}

/** Die Fassung fuer den Kopf. */
function sicherung_fassung(array $b)
{
    if (isset($b['fassung'])) {
        $f = is_callable($b['fassung']) ? call_user_func($b['fassung']) : $b['fassung'];
        if (is_string($f) && $f !== '') { return $f; }
    }
    return sicherung_fassung_lesen($b);
}

/** Die Beschreibung taugt? Rueckgabe: Liste der Maengel (Namen), leer = ja. */
function sicherung_beschreibung_mangel(array $b)
{
    $m = array();
    if (!isset($b['kennzeichen']) || !sicherung_namensform($b['kennzeichen'])) { $m[] = 'kennzeichen'; }
    if (!isset($b['vorgaben']) || !is_array($b['vorgaben']) || !$b['vorgaben']) { $m[] = 'vorgaben'; }
    foreach (array('pruefen', 'quer', 'uebersetzer', 'vorbehandeln', 'jetzt', 't', 'e', 'sperre', 'freigeben',
                   'vorbereiten', 'speichern', 'zweitschrift', 'nachziehen', 'log', 'fassung', 'warnung',
                   'vorbedingung') as $f) {
        if (isset($b[$f]) && $f !== 'fassung' && !is_callable($b[$f])) { $m[] = $f; }
    }
    // 1.2.0: die wahlfreien Angaben muessen die beschriebene Form haben (sonst fiele ein Tippfehler still durch)
    if (isset($b['umbenennen']) && !is_array($b['umbenennen'])) { $m[] = 'umbenennen'; }
    if (isset($b['teile_flach']) && (!is_array($b['teile_flach']) || !isset($b['teile']) || !is_array($b['teile'])
        || array_diff_key($b['teile_flach'], $b['teile']))) { $m[] = 'teile_flach'; }
    if (isset($b['kennzeichen_frueher_muster'])
        && (!is_string($b['kennzeichen_frueher_muster']) || @preg_match($b['kennzeichen_frueher_muster'], '') === false)) {
        $m[] = 'kennzeichen_frueher_muster';
    }
    return $m;
}

/* ==================================================================
 * Sichern
 * ================================================================== */

/** Der Inhalt eines Knotens fuer die Sicherung. */
function sicherung_knoten_sichern(array $knoten, $w)
{
    if (isset($knoten['sichern']) && is_callable($knoten['sichern'])) { $w = call_user_func($knoten['sichern'], $w); }
    return $w;
}

/**
 * Die Sicherung als Feld: lesbarer Kopf (_hinweis, _plugin, _fassung, _stand,
 * dann $b['kopf']) und alle bekannten Schluessel aus $cfg in der Reihenfolge
 * der Vorgaben - auch die, die gleich der Vorgabe sind. Was $cfg nicht traegt,
 * fehlt auch in der Datei (dann sagt es _warnung). Ohne 'nie_aus_datei' und
 * 'alt'. $teile: weitere Teile der Datei (Name => Wert, z. B. Zugangsdaten
 * aus einer eigenen Datei). Traegt die Datei etwas, das das eigene
 * Zurueckspielen abwiese, steht in _warnung, was - nur Namen.
 */
function sicherung_bauen(array $b, array $cfg, array $teile = array())
{
    $kopf = array(
        '_hinweis' => (isset($b['hinweis']) && is_string($b['hinweis'])) ? $b['hinweis'] : sicherung_t('K_HINWEIS', $b),
        '_plugin'  => (string) $b['kennzeichen'],
        '_fassung' => sicherung_fassung($b),
        '_stand'   => date('Y-m-d H:i:s'),
    );
    if (isset($b['kopf']) && is_array($b['kopf'])) {
        foreach ($b['kopf'] as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] === '_' && !isset($kopf[$k])) { $kopf[$k] = $v; }
        }
    }
    $nie = isset($b['nie_aus_datei']) && is_array($b['nie_aus_datei']) ? $b['nie_aus_datei'] : array();
    $inhalt = array();
    foreach (array_keys($b['vorgaben']) as $k) {
        if (in_array($k, $nie, true) || !array_key_exists($k, $cfg)) { continue; }
        $inhalt[$k] = sicherung_knoten_sichern(sicherung_knoten($b, $k), $cfg[$k]);
    }
    $flach = isset($b['teile_flach']) && is_array($b['teile_flach']) ? $b['teile_flach'] : array();
    foreach ($teile as $k => $v) {
        if (!is_string($k) || !isset($b['teile'][$k])) { continue; }
        if (isset($flach[$k]) && is_array($v)) {
            // 1.2.0: ein flacher Teil steht als <praefix><feld> in der Datei
            foreach ($v as $f => $fw) { $inhalt[(string) $flach[$k] . (string) $f] = $fw; }
            continue;
        }
        $inhalt[$k] = $v;
    }
    $daten = $kopf + $inhalt;
    $namen = sicherung_selbstpruefung($b, $daten, $cfg);
    $saetze = array();
    if ($namen) { $saetze[] = sprintf(sicherung_t('K_WARNUNG', $b), implode(', ', $namen)); }
    if (isset($b['warnung']) && is_callable($b['warnung'])) {
        // 1.2.0: eigene Warnsaetze der Linie (nur Namen und Gruende, nie Werte) haengen an _warnung
        foreach ((array) call_user_func($b['warnung'], $cfg) as $s) {
            if (is_string($s) && $s !== '') { $saetze[] = $s; }
        }
    }
    if ($saetze) {
        $daten = $kopf + array('_warnung' => implode(' ', $saetze)) + $inhalt;
    }
    return $daten;
}

/** Das Feld als Datei: lesbar eingerueckt; '' wenn es sich nicht bilden laesst. */
function sicherung_json(array $daten)
{
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($js) ? $js : '';
}

/**
 * Wuerde das eigene Zurueckspielen diese Daten abweisen? Rueckgabe: die Namen der
 * beanstandeten Schluessel (leer = sie liesse sich zurueckspielen), nie Werte.
 * Dieselbe Pruefung wie beim Zurueckspielen, gegen $jetzt als Ziel.
 */
function sicherung_selbstpruefung(array $b, array $daten, $jetzt = null)
{
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($js)) { return array('-'); }
    $r = sicherung_lesen($b, $js, is_array($jetzt) ? $jetzt : array());
    if ($r['ok']) { return array(); }
    $namen = array();
    foreach ($r['beanstandet'] as $x) { $namen[] = $x['name']; }
    return $namen ? sicherung_einmal($namen) : array('-');
}

/* ==================================================================
 * Lesen und pruefen
 * ================================================================== */

/** Ein leeres Ergebnis. */
function sicherung_ergebnis()
{
    return array('ok' => false, 'neu' => null, 'anzahl' => 0, 'eigen' => '', 'fremd' => '',
                 'kennzeichen_datei' => '', 'fassung_datei' => '', 'beanstandet' => array(),
                 'verworfen' => array(), 'leer_behalten' => array(), 'fehlt_behalten' => array(),
                 'fehlt_vorgabe' => array(), 'teile' => array(), 'teile_hinweise' => array(),
                 'fehlt_uebergang' => array());
}

/** Eine Beanstandung aufnehmen ($text: '' | Text | Liste). */
function sicherung_beanstanden(array &$r, $name, $text)
{
    if (is_array($text)) {
        foreach ($text as $t) { $r['beanstandet'][] = array('name' => (string) $name, 'text' => (string) $t); }
        return;
    }
    $r['beanstandet'][] = array('name' => (string) $name, 'text' => (string) $text);
}

/**
 * Regel 2 und 3 an einem Knoten: unbekannte Unterschluessel verwerfen (Name in
 * $r['verworfen']), leere Werte durch den bisherigen ersetzen (Name in
 * $r['leer_behalten']). Rueckgabe: der bereinigte Wert. Was keine Form eines
 * Blocks oder einer Liste hat, bleibt unveraendert - das entscheidet die Pruefung.
 */
function sicherung_knoten_reinigen(array $knoten, $w, $j, $pfad, array &$r, $trenner = '.')
{
    $art = isset($knoten['art']) ? $knoten['art'] : 'wert';
    if ($art === 'block' && is_array($w)) {
        $schl = isset($knoten['schluessel']) && is_array($knoten['schluessel']) ? $knoten['schluessel'] : array();
        /* 1.2.0: ein Unterschluessel mit 'fehlt' => 'behalten', den die Datei nicht traegt, bekommt den geltenden
         * Wert und wird genannt (wie oben; ein Feld, das eine fruehere Fassung noch nicht kannte). */
        foreach ($schl as $sk => $sknoten) {
            if (is_array($sknoten) && isset($sknoten['fehlt']) && $sknoten['fehlt'] === 'behalten'
                && !array_key_exists($sk, $w) && is_array($j) && array_key_exists($sk, $j)) {
                $w[$sk] = $j[$sk];
                $r['fehlt_behalten'][] = $pfad . $trenner . sicherung_name($sk);
            }
        }
        foreach (array_keys($w) as $sk) {
            $name = $pfad . $trenner . sicherung_name($sk);
            if (!array_key_exists((string) $sk, $schl)) {
                $r['verworfen'][] = $name;
                unset($w[$sk]);
                continue;
            }
            $js = (is_array($j) && array_key_exists($sk, $j)) ? $j[$sk] : null;
            if (sicherung_leer_bleibt($w[$sk], $js)) {
                $w[$sk] = $js;
                $r['leer_behalten'][] = $name;
                continue;
            }
            $w[$sk] = sicherung_knoten_reinigen($schl[(string) $sk], $w[$sk], $js, $name, $r);
        }
        return $w;
    }
    if ($art === 'liste' && is_array($w)) {
        $eintrag = isset($knoten['eintrag']) && is_array($knoten['eintrag']) ? $knoten['eintrag'] : array('art' => 'offen');
        $zu = isset($knoten['zuordnung']) && is_string($knoten['zuordnung']) ? $knoten['zuordnung'] : '';
        foreach (array_keys($w) as $i) {
            if (!is_array($w[$i])) { continue; }
            $name = $pfad . '[' . (is_int($i) ? (string) ($i + 1) : sicherung_name($i)) . ']';
            $je = null;
            if ($zu !== '' && isset($w[$i][$zu]) && is_scalar($w[$i][$zu]) && (string) $w[$i][$zu] !== '' && is_array($j)) {
                foreach ($j as $kand) {
                    if (is_array($kand) && isset($kand[$zu]) && is_scalar($kand[$zu])
                        && (string) $kand[$zu] === (string) $w[$i][$zu]) { $je = $kand; break; }
                }
            }
            $w[$i] = sicherung_knoten_reinigen($eintrag, $w[$i], $je, $name, $r);
        }
        return $w;
    }
    return $w;
}

/**
 * Eine Sicherungsdatei lesen und pruefen, ohne etwas zu schreiben.
 * $jetzt: die geltende Konfiguration (fuer Regel 3 und 'behalten'); ohne
 * Angabe $b['jetzt'](). Rueckgabe: das Ergebnis $r (siehe Kopf).
 */
function sicherung_lesen(array $b, $roh, $jetzt = null)
{
    $r = sicherung_ergebnis();
    if ($jetzt === null && isset($b['jetzt']) && is_callable($b['jetzt'])) { $jetzt = call_user_func($b['jetzt']); }
    if (!is_array($jetzt)) { $jetzt = array(); }
    $bm = sicherung_beschreibung_mangel($b);
    if ($bm) {
        $r['fremd'] = 'form';
        sicherung_beanstanden($r, '-', sprintf(sicherung_t('K_BESCHREIBUNG', $b), implode(', ', $bm)));
        return $r;
    }
    $max = isset($b['max_byte']) && is_int($b['max_byte']) && $b['max_byte'] > 0 ? $b['max_byte'] : SICHERUNG_MAX_BYTE;
    if (!is_string($roh) || strlen($roh) > $max) {
        $r['fremd'] = 'gross';
        sicherung_beanstanden($r, '-', sprintf(sicherung_t('K_ZU_GROSS', $b), (int) ($max / 1024)));
        return $r;
    }
    $daten = json_decode($roh, true);
    if (!is_array($daten) || ($daten !== array() && array_keys($daten) === range(0, count($daten) - 1))) {
        $r['fremd'] = 'form';
        sicherung_beanstanden($r, '-', sicherung_t('K_KEIN_JSON', $b));
        return $r;
    }

    /* ---- Regel 1: das Kennzeichen ---- */
    $frueher = isset($b['kennzeichen_frueher']) && is_array($b['kennzeichen_frueher']) ? $b['kennzeichen_frueher'] : array();
    if (array_key_exists('_plugin', $daten)) {
        $kz = $daten['_plugin'];
        $r['kennzeichen_datei'] = sicherung_namensform($kz) ? $kz : '?';
        $muster = isset($b['kennzeichen_frueher_muster']) && is_string($b['kennzeichen_frueher_muster'])
            ? $b['kennzeichen_frueher_muster'] : '';
        if ($kz === $b['kennzeichen']) {
            $r['eigen'] = 'kennzeichen';
        } elseif (is_string($kz) && in_array($kz, $frueher, true)) {
            $r['eigen'] = 'frueher';
        } elseif (is_string($kz) && $muster !== '' && strlen($kz) <= 200 && preg_match($muster, $kz) === 1) {
            $r['eigen'] = 'frueher';     // 1.2.0: ein frueheres Kennzeichen nach Muster (Teil der Anlage darin)
        } else {
            $r['fremd'] = 'kennzeichen';
            sicherung_beanstanden($r, '_plugin', $r['kennzeichen_datei'] !== '?'
                ? sprintf(sicherung_t('K_FREMD', $b), sicherung_e($r['kennzeichen_datei'], $b))
                : sicherung_t('K_FREMD_OHNE_NAME', $b));
            return $r;
        }
    } else {
        $ue = (isset($b['uebersetzer']) && is_callable($b['uebersetzer'])) ? call_user_func($b['uebersetzer'], $daten) : null;
        if (!is_array($ue)) {
            $r['fremd'] = 'ohne';
            sicherung_beanstanden($r, '_plugin', sicherung_t('K_OHNE_KENNZEICHEN', $b));
            return $r;
        }
        $daten = $ue;
        $r['eigen'] = 'uebersetzt';
    }
    if (isset($daten['_fassung']) && sicherung_namensform($daten['_fassung'])) { $r['fassung_datei'] = $daten['_fassung']; }

    $vorgaben = $b['vorgaben'];
    $alt = isset($b['alt']) && is_array($b['alt']) ? $b['alt'] : array();
    $nie = isset($b['nie_aus_datei']) && is_array($b['nie_aus_datei']) ? $b['nie_aus_datei'] : array();
    $teile = isset($b['teile']) && is_array($b['teile']) ? $b['teile'] : array();

    /* 1.2.0: Namen frueherer Fassungen umbenennen (vor allen Regeln, nur wenn der heutige fehlt) und flache Teile
     * (<praefix><feld>) zu ihrem Teil sammeln. */
    if (isset($b['umbenennen']) && is_array($b['umbenennen'])) {
        $um = array();
        foreach ($daten as $k => $w) {
            $k = (string) $k;
            $n = isset($b['umbenennen'][$k]) && is_string($b['umbenennen'][$k]) ? $b['umbenennen'][$k] : $k;
            if ($n !== $k && array_key_exists($n, $daten)) { $n = $k; }
            $um[$n] = $w;
        }
        $daten = $um;
    }
    $flach = isset($b['teile_flach']) && is_array($b['teile_flach']) ? $b['teile_flach'] : array();
    foreach ($flach as $tn => $px) {
        $px = (string) $px;
        if ($px === '' || array_key_exists($tn, $daten)) { continue; }
        $sammel = array();
        foreach ($daten as $k => $w) {
            $k = (string) $k;
            if (strpos($k, $px) === 0 && strlen($k) > strlen($px)) { $sammel[substr($k, strlen($px))] = $w; unset($daten[$k]); }
        }
        if ($sammel) { $daten[$tn] = $sammel; }
    }

    /* ---- Regel 2 und 3: Unbekanntes verwerfen, Leeres stehen lassen ---- */
    $bereit = array();
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }               // der lesbare Kopf
        if (in_array($k, $alt, true) || in_array($k, $nie, true)) { continue; }
        if (isset($teile[$k])) {
            if (!is_callable($teile[$k])) { continue; }
            /* Ein Teil mit Knoten in 'schluessel' durchlaeuft Regel 2 und 3 wie ein Block; der geltende
             * Stand des Teils kommt aus 'teile_jetzt' (Wert oder function () -> Wert). */
            if (isset($b['schluessel'][$k]) && is_array($b['schluessel'][$k])) {
                $tj = isset($b['teile_jetzt'][$k]) ? $b['teile_jetzt'][$k] : null;
                if (is_callable($tj)) { $tj = call_user_func($tj); }
                $w = isset($flach[$k])      // 1.2.0: Namen eines flachen Teils wie in der Datei (zugang_benutzer)
                    ? sicherung_knoten_reinigen($b['schluessel'][$k], $w, $tj, '', $r, (string) $flach[$k])
                    : sicherung_knoten_reinigen($b['schluessel'][$k], $w, $tj, $k, $r);
            }
            $tw = call_user_func($teile[$k], $w, $jetzt);
            $tw = is_array($tw) ? array_pad($tw, 3, array()) : array(null, array(), array());
            if ($tw[1]) {
                sicherung_beanstanden($r, $k, $tw[1]);
            } else {
                $r['teile'][$k] = $tw[0];
                $r['anzahl']++;
                foreach ((array) $tw[2] as $th) { if (is_string($th) && $th !== '') { $r['teile_hinweise'][] = $th; } }
            }
            continue;
        }
        if (!array_key_exists($k, $vorgaben)) {
            $r['verworfen'][] = sicherung_name($k);
            continue;
        }
        $j = array_key_exists($k, $jetzt) ? $jetzt[$k] : null;
        if (sicherung_leer_bleibt($w, $j)) {
            $r['leer_behalten'][] = $k;
            $bereit[$k] = $j;
            continue;
        }
        $bereit[$k] = sicherung_knoten_reinigen(sicherung_knoten($b, $k), $w, $j, $k, $r);
    }
    if (isset($b['vorbehandeln']) && is_callable($b['vorbehandeln'])) {
        $vb = call_user_func($b['vorbehandeln'], $bereit, $jetzt, $daten);
        if (is_array($vb)) { $bereit = $vb; }
    }

    /* ---- Regel 4: jeden Wert pruefen, alles sammeln ---- */
    $neu = $vorgaben;
    /* 1.2.1: ein Vermerk ('nie_aus_datei') behaelt seinen geltenden Wert; aus der Datei kommt er nie (Regel 2 oben
     * uebergeht ihn). Steht er nicht in $jetzt, bleibt es wie bis 1.2.0. Was 'vorbehandeln' setzt, gilt danach. */
    foreach ($nie as $k) {
        if (is_string($k) && array_key_exists($k, $jetzt)) { $neu[$k] = $jetzt[$k]; }
    }
    foreach ($bereit as $k => $w) {
        if (!array_key_exists($k, $vorgaben)) { continue; }
        if (in_array($k, $r['leer_behalten'], true)) { $neu[$k] = $w; $r['anzahl']++; continue; }
        $knoten = sicherung_knoten($b, $k);
        $grund = '';
        $roh = array_key_exists($k, $daten) ? $daten[$k] : null;     // 1.2.0: wie in der Datei, vor Regel 2 und 3
        if (isset($knoten['pruefen']) && is_callable($knoten['pruefen'])) {
            $grund = call_user_func($knoten['pruefen'], $w, $k, $neu, $roh);
        } elseif (isset($b['pruefen']) && is_callable($b['pruefen'])) {
            $grund = call_user_func($b['pruefen'], $k, $w, $neu, $roh);
        }
        if ($grund !== '' && $grund !== array() && $grund !== null) {
            sicherung_beanstanden($r, $k, $grund);
            continue;
        }
        if (isset($knoten['wandeln']) && is_callable($knoten['wandeln'])) { $w = call_user_func($knoten['wandeln'], $w); }
        $neu[$k] = $w;
        $r['anzahl']++;
    }

    /* ---- Fehlende Schluessel ---- */
    $fehlend = array();
    foreach (array_keys($vorgaben) as $k) {
        if (array_key_exists($k, $daten) || in_array($k, $nie, true)) { continue; }
        $knoten = sicherung_knoten($b, $k);
        $f = isset($knoten['fehlt']) ? $knoten['fehlt'] : 'pflicht';
        if ($f === 'vorgabe') {
            $r['fehlt_vorgabe'][] = $k;
        } elseif ($f === 'behalten') {
            /* 1.2.2: der Schluessel fehlt in der Datei; steht er trotzdem in $bereit, hat ihn 'vorbehandeln'
             * gesetzt (vor 'vorbehandeln' traegt $bereit nur Schluessel aus der Datei). Dieser Wert ging oben
             * durch Regel 4 und bleibt; genannt wird er als solcher, nicht als behalten. */
            if (array_key_exists($k, $bereit)) {
                $r['fehlt_uebergang'][] = $k;
                continue;
            }
            if (array_key_exists($k, $jetzt)) { $neu[$k] = $jetzt[$k]; }
            $r['fehlt_behalten'][] = $k;
        } else {
            $fehlend[] = $k;
        }
    }
    if ($fehlend) {
        sicherung_beanstanden($r, implode(',', $fehlend),
            sprintf(sicherung_t('K_FEHLEND', $b), count($fehlend), sicherung_e(implode(', ', $fehlend), $b)));
    }
    // 1.2.0: ein flacher Teil, von dem die Datei kein Feld traegt - seine Felder mit 'fehlt' => 'behalten' werden
    // genannt wie in einer Fassung, die den Teil noch nicht kannte (der Teil selbst bleibt dann unberuehrt).
    foreach ($flach as $tn => $px) {
        if (array_key_exists($tn, $daten) || !isset($b['schluessel'][$tn]['schluessel'])
            || !is_array($b['schluessel'][$tn]['schluessel'])) { continue; }
        foreach ($b['schluessel'][$tn]['schluessel'] as $sk => $sknoten) {
            if (is_array($sknoten) && isset($sknoten['fehlt']) && $sknoten['fehlt'] === 'behalten') {
                $r['fehlt_behalten'][] = (string) $px . sicherung_name($sk);
            }
        }
    }
    if (isset($b['quer']) && is_callable($b['quer'])) {
        foreach ((array) call_user_func($b['quer'], $neu, $r, $daten) as $q) {
            if (is_array($q) && count($q) >= 2) { sicherung_beanstanden($r, (string) $q[0], (string) $q[1]); }
        }
    }
    if ($r['anzahl'] === 0 && !$r['beanstandet']) {
        sicherung_beanstanden($r, '-', sicherung_t('K_LEER', $b));
    }
    $r['verworfen'] = sicherung_einmal($r['verworfen']);
    $r['leer_behalten'] = sicherung_einmal($r['leer_behalten']);
    $r['ok'] = !$r['beanstandet'];
    $r['neu'] = $r['ok'] ? $neu : null;
    if (!$r['ok']) { $r['teile'] = array(); $r['teile_hinweise'] = array(); }
    return $r;
}

/* ==================================================================
 * Zurueckspielen
 * ================================================================== */

/**
 * Lesen, pruefen, (unter der Sperre der Linie) speichern, Zweitschrift
 * nachmessen, Dienst nachziehen. Rueckgabe: das Ergebnis $r von
 * sicherung_lesen() und dazu 'gespeichert' (bool), 'zweitschrift'
 * ('' = nicht gefragt, 'mit', 'abweichend', 'fehlt') und 'dienst' (Liste der
 * Meldungen aus 'nachziehen'). Eine halb gueltige Datei aendert nichts.
 */
function sicherung_zurueckspielen(array $b, $roh)
{
    $griff = (isset($b['sperre']) && is_callable($b['sperre'])) ? call_user_func($b['sperre']) : null;
    $jetzt = (isset($b['jetzt']) && is_callable($b['jetzt'])) ? call_user_func($b['jetzt']) : array();
    if (!is_array($jetzt)) { $jetzt = array(); }
    $r = sicherung_lesen($b, $roh, $jetzt);
    $r['gespeichert'] = false;
    $r['zweitschrift'] = '';
    $r['dienst'] = array();
    $r['vorbedingung'] = array();
    if ($r['ok']) {
        $neu = $r['neu'];
        if (isset($b['vorbereiten']) && is_callable($b['vorbereiten'])) {
            $vb = call_user_func($b['vorbereiten'], $neu, $jetzt, $r);
            if (is_array($vb)) { $neu = $vb; }
        }
        $r['neu'] = $neu;
        if (isset($b['vorbedingung']) && is_callable($b['vorbedingung'])) {
            // 1.2.0: was vor dem Speichern erfuellt sein muss; nicht erfuellt heisst nicht gespeichert, mit diesen Saetzen
            foreach ((array) call_user_func($b['vorbedingung'], $neu, $jetzt, $r) as $s) {
                if (is_string($s) && $s !== '') { $r['vorbedingung'][] = $s; }
            }
        }
        $r['gespeichert'] = (!$r['vorbedingung'] && isset($b['speichern']) && is_callable($b['speichern']))
            ? (bool) call_user_func($b['speichern'], $neu, $jetzt, $r) : false;
        if ($r['gespeichert'] && isset($b['zweitschrift']) && is_callable($b['zweitschrift'])) {
            $r['zweitschrift'] = sicherung_zweitschrift_lage(call_user_func($b['zweitschrift']));
        }
    }
    if ($griff !== null && isset($b['freigeben']) && is_callable($b['freigeben'])) { call_user_func($b['freigeben'], $griff); }
    if ($r['gespeichert'] && isset($b['nachziehen']) && is_callable($b['nachziehen'])) {
        $r['dienst'] = array_values(array_filter((array) call_user_func($b['nachziehen'], $r['neu'], $jetzt, $r), 'is_string'));
    }
    if (isset($b['log']) && is_callable($b['log'])) { call_user_func($b['log'], sicherung_protokollzeile($r)); }
    return $r;
}

/** Steht die Zweitschrift inhaltsgleich neben der Konfiguration? $p = array(Datei, Zweitschrift). */
function sicherung_zweitschrift_lage($p)
{
    if (!is_array($p) || count($p) < 2 || !is_string($p[0]) || !is_string($p[1])) { return 'fehlt'; }
    if (!is_file($p[1])) { return 'fehlt'; }
    $a = @file_get_contents($p[0]);
    $z = @file_get_contents($p[1]);
    if (!is_string($a) || !is_string($z)) { return 'fehlt'; }
    if ($a === $z) { return 'mit'; }
    $ja = json_decode($a, true);
    $jz = json_decode($z, true);
    return (is_array($ja) && $ja === $jz) ? 'mit' : 'abweichend';
}

/** Eine Protokollzeile: Zahlen und Namen, nie Werte. */
function sicherung_protokollzeile(array $r)
{
    if (!$r['ok']) {
        $n = array();
        foreach ($r['beanstandet'] as $x) { $n[] = sicherung_name($x['name']); }
        return 'Sicherung abgewiesen (' . ($r['fremd'] !== '' ? $r['fremd'] : 'Werte') . '): '
             . implode(', ', array_slice(sicherung_einmal($n), 0, 20));
    }
    $z = 'Sicherung zurueckgespielt: ' . (int) $r['anzahl'] . ' Werte'
       . ($r['eigen'] === 'uebersetzt' ? ', aeltere Sicherung ohne Kennzeichen' : '')
       . ($r['fassung_datei'] !== '' ? ', aus Fassung ' . $r['fassung_datei'] : '');
    foreach (array('verworfen' => 'verworfen', 'leer_behalten' => 'leer, bisheriger Wert bleibt',
                   'fehlt_behalten' => 'fehlt, bisheriger Wert bleibt',
                   'fehlt_uebergang' => 'fehlt, Wert aus einer Uebergangsregel der Linie') as $f => $t) {
        if (!empty($r[$f])) { $z .= '; ' . $t . ': ' . implode(', ', array_slice($r[$f], 0, 20)); }
    }
    if (isset($r['gespeichert']) && !$r['gespeichert']) {
        $z .= '; NICHT gespeichert' . (!empty($r['vorbedingung']) ? ' (Vorbedingung nicht erfuellt)' : '');
    }
    if (isset($r['zweitschrift']) && $r['zweitschrift'] !== '' && $r['zweitschrift'] !== 'mit') {
        $z .= '; Zweitschrift ' . $r['zweitschrift'];
    }
    return (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $z);
}

/**
 * Die Meldungen zu einem Ergebnis. Rueckgabe: array('fehler' => Texte,
 * 'hinweise' => Texte), fertig maskiert. Beanstandungen der Linie kommen mit
 * ihrem eigenen Text (die Linie hat ihn maskiert); Namen gehen durch 'e'.
 */
function sicherung_meldungen(array $b, array $r)
{
    $f = array();
    $h = array();
    if (!$r['ok']) {
        $t = array();
        foreach ($r['beanstandet'] as $x) {
            $t[] = $x['text'] !== '' ? $x['text'] : sprintf(sicherung_t('K_WERT', $b), sicherung_e($x['name'], $b));
        }
        $f[] = sicherung_t('K_ABGELEHNT', $b) . ' ' . implode(' ', sicherung_einmal($t));
        return array('fehler' => $f, 'hinweise' => $h);
    }
    if (isset($r['gespeichert']) && !$r['gespeichert']) {
        if (!empty($r['vorbedingung']) && is_array($r['vorbedingung'])) {
            // 1.2.0: die Saetze der Vorbedingung sagen, warum nicht gespeichert wurde - kein zweiter Satz dazu
            foreach ($r['vorbedingung'] as $s) { $f[] = (string) $s; }
        } else {
            $f[] = sicherung_t('K_SCHREIBFEHLER', $b);
        }
        return array('fehler' => $f, 'hinweise' => $h);
    }
    $h[] = sprintf(sicherung_t('K_UEBERNOMMEN', $b), (int) $r['anzahl']);
    if ($r['eigen'] === 'uebersetzt') { $h[] = sicherung_t('K_ALT_ERKANNT', $b); }
    if (!empty($r['teile_hinweise']) && is_array($r['teile_hinweise'])) {
        foreach ($r['teile_hinweise'] as $s) { $h[] = (string) $s; }     // 1.2.0: Hinweise der Teile, fertige Saetze
    }
    $listen = array('verworfen' => 'K_VERWORFEN', 'leer_behalten' => 'K_LEER_BEHALTEN', 'fehlt_behalten' => 'K_FEHLT_BEHALTEN',
                    'fehlt_uebergang' => 'K_FEHLT_UEBERGANG', 'fehlt_vorgabe' => 'K_FEHLT_VORGABE');
    foreach ($listen as $feld => $id) {
        if (!empty($r[$feld])) { $h[] = sprintf(sicherung_t($id, $b), sicherung_e(implode(', ', $r[$feld]), $b)); }
    }
    if (isset($r['zweitschrift']) && ($r['zweitschrift'] === 'abweichend' || $r['zweitschrift'] === 'fehlt')) {
        $f[] = sicherung_t('K_ZWEITSCHRIFT', $b);
    }
    if (isset($r['dienst']) && is_array($r['dienst'])) {
        foreach ($r['dienst'] as $d) { $h[] = $d; }
    }
    return array('fehler' => $f, 'hinweise' => $h);
}

/* ==================================================================
 * Der Knopf "Einstellungen zurueckspielen": Haken und Datei (seit 1.1.0)
 * ================================================================== */

/**
 * Die Formularzeile mit dem Haken, fertig maskiert. Sie steht in jeder Linie im
 * Formular des Knopfes "Einstellungen zurueckspielen", unmittelbar vor dem Knopf.
 * Ab Werk ist der Haken aus.
 */
function sicherung_haken_zeile(array $b)
{
    return '<div class="sm-feld"><label><input data-role="none" type="checkbox" name="' . SICHERUNG_HAKEN
         . '" value="1"> ' . sicherung_e(sicherung_t('K_HAKEN', $b), $b) . '</label></div>';
}

/**
 * Der Eingang des Knopfes: ist der Haken gesetzt, kam eine Datei ueber das Formular
 * (is_uploaded_file()) und ist sie nicht groesser als 'max_byte'? Erst dann wird
 * sie gelesen. $post = $_POST, $files = $_FILES, $feld = Name des Dateifelds.
 * Rueckgabe array(Text der Datei, null) - dann ruft die Linie
 * sicherung_zurueckspielen() mit dem Text - oder array(null, Ergebnis) mit
 * 'abbruch' (siehe Kopf); die Meldung dazu gibt sicherung_meldungen(). Ohne
 * Haken wird die Datei nicht angesehen. Schreibt nichts.
 */
function sicherung_eingang(array $b, array $post, array $files, $feld)
{
    $datei = (is_string($feld) && isset($files[$feld]) && is_array($files[$feld])) ? $files[$feld] : null;
    return sicherung_eingang_lage($b, $post, $datei, 'is_uploaded_file');
}

/**
 * Wie sicherung_eingang() fuer eine Datei-Angabe $datei (ein Eintrag von $_FILES
 * oder null); $hochgeladen ist die Pruefung "kam ueber das Formular", in der
 * Linie immer is_uploaded_file (der Selbsttest gibt eine eigene).
 */
function sicherung_eingang_lage(array $b, array $post, $datei, $hochgeladen)
{
    $max = isset($b['max_byte']) && is_int($b['max_byte']) && $b['max_byte'] > 0 ? $b['max_byte'] : SICHERUNG_MAX_BYTE;
    $abbruch = function ($art, $text) {
        $r = sicherung_ergebnis();
        $r['abbruch'] = $art;
        $r['fremd'] = $art === 'gross' ? 'gross' : '';
        $r['gespeichert'] = false;
        $r['zweitschrift'] = '';
        $r['dienst'] = array();
        sicherung_beanstanden($r, '-', $text);
        return array(null, $r);
    };
    if (!isset($post[SICHERUNG_HAKEN]) || $post[SICHERUNG_HAKEN] !== '1') {
        return $abbruch('haken', sicherung_t('K_HAKEN_FEHLT', $b));
    }
    $gross = sprintf(sicherung_t('K_ZU_GROSS', $b), (int) ($max / 1024));
    if (is_array($datei) && isset($datei['error'])
        && in_array((int) $datei['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
        return $abbruch('gross', $gross);
    }
    if (!is_array($datei) || !isset($datei['tmp_name']) || !is_string($datei['tmp_name']) || $datei['tmp_name'] === ''
        || !is_callable($hochgeladen) || !call_user_func($hochgeladen, $datei['tmp_name'])) {
        return $abbruch('datei', sicherung_t('K_KEINE_DATEI', $b));
    }
    if (!isset($datei['size']) || (int) $datei['size'] > $max) {
        return $abbruch('gross', $gross);
    }
    $roh = @file_get_contents($datei['tmp_name'], false, null, 0, $max + 1);
    if (!is_string($roh)) {
        return $abbruch('datei', sicherung_t('K_KEINE_DATEI', $b));
    }
    if (strlen($roh) > $max) {
        return $abbruch('gross', $gross);
    }
    return array($roh, null);
}

/* ==================================================================
 * Texte
 * ================================================================== */

/**
 * Alle Kennungen, zu denen ein Satz im Abschnitt [SICHERUNG] der Sprachdateien
 * stehen soll. Werkzeuge/gemeinsam/sicherung_texte.json traegt genau diese
 * Schluessel in de und en; sicherung_selbsttest() und
 * Werkzeuge/sicherung_einbauen.py halten beides gegeneinander.
 */
function sicherung_sprachschluessel()
{
    return array('K_HINWEIS', 'K_WARNUNG', 'K_BESCHREIBUNG', 'K_ZU_GROSS', 'K_KEIN_JSON', 'K_FREMD',
                 'K_FREMD_OHNE_NAME', 'K_OHNE_KENNZEICHEN', 'K_FEHLEND', 'K_LEER', 'K_WERT', 'K_ABGELEHNT',
                 'K_SCHREIBFEHLER', 'K_UEBERNOMMEN', 'K_ALT_ERKANNT', 'K_VERWORFEN', 'K_LEER_BEHALTEN',
                 'K_FEHLT_BEHALTEN', 'K_ZWEITSCHRIFT', 'K_HAKEN', 'K_HAKEN_FEHLT', 'K_KEINE_DATEI', 'K_FEHLT_VORGABE',
                 'K_FEHLT_UEBERGANG');
}

/* ==================================================================
 * Selbsttest
 * ================================================================== */

/**
 * Rechnet die Regeln an einer erfundenen Linie nach, ohne Dateien und ohne Netz.
 * Rueckgabe: array(Zahl der Faelle, Fehlschlaege, Zeilen).
 */
function sicherung_selbsttest()
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
    $TOK = 'pruefzeichen0123456789ab';
    $GEHEIM = 'Kennwort-Selbsttest-77';
    $gespeichert = array();
    $b = array(
        'kennzeichen' => 'LoxBerry-Plugin-Selbsttest',
        'kennzeichen_frueher' => array('selbsttest'),
        'fassung' => '9.8.7',
        'vorgaben' => array('aktionstoken' => '', 'thema' => 'st', 'takt' => 60, 'ein' => 0, 'spaet' => 1,
                            'werk' => 5, 'tts' => array('mode' => 'aus', 'ip' => '', 'alexa_token' => ''),
                            'liste' => array(), 'frei' => array()),
        'schluessel' => array(
            'tts' => sicherung_block(array('mode', 'ip', 'alexa_token'), array()) + array(
                'sichern' => function ($w) { if (is_array($w)) { unset($w['alexa_token']); } return $w; }),
            'liste' => sicherung_liste(array('name', 'wert', 'kennwort'), 'name'),
            'frei' => sicherung_offen(),
            'zugang' => sicherung_block(array('kennwort')),
            'spaet' => array('fehlt' => 'behalten'),
            'werk' => array('fehlt' => 'vorgabe'),
        ),
        'alt' => array('altschluessel'),
        'nie_aus_datei' => array('vermerk'),
        'teile' => array('zugang' => function ($w) {
            if (!is_array($w) || !isset($w['kennwort']) || !is_string($w['kennwort'])) { return array(null, array('zugang ungueltig')); }
            return array(array('kennwort' => $w['kennwort']), array());
        }),
        'teile_jetzt' => array('zugang' => function () use ($GEHEIM) { return array('kennwort' => $GEHEIM); }),
        'pruefen' => function ($k, $w) {
            if ($k === 'takt') { return (is_int($w) && $w >= 10 && $w <= 3600) ? '' : 'takt ausserhalb'; }
            if ($k === 'thema') { return (is_string($w) && preg_match('/^[a-z]{1,20}\z/', $w)) ? '' : 'thema unzulaessig'; }
            if ($k === 'aktionstoken') { return (is_string($w) && preg_match('/^[a-z0-9]{0,64}\z/', $w)) ? '' : 'token unzulaessig'; }
            if ($k === 'tts') { return (is_array($w) && isset($w['mode']) && in_array($w['mode'], array('aus', 'musicserver'), true)) ? '' : 'tts unzulaessig'; }
            if ($k === 'liste') { return is_array($w) ? '' : 'liste unzulaessig'; }
            return '';
        },
        'quer' => function ($neu) { return ($neu['takt'] === 13) ? array(array('takt', 'takt 13 geht nicht mit thema')) : array(); },
        'uebersetzer' => function ($d) {
            return (isset($d['_hinweis']) && is_string($d['_hinweis']) && strpos($d['_hinweis'], 'Selbsttest-Sicherung') === 0) ? $d : null;
        },
        'speichern' => function ($neu) use (&$gespeichert) { $gespeichert[] = $neu; return true; },
        'nachziehen' => function () { return array('Dienst neu gestartet.'); },
        't' => function ($s) { return $s . ' [%s]'; },
    );
    $jetzt = array('aktionstoken' => $TOK, 'thema' => 'alt', 'takt' => 120, 'ein' => 1, 'spaet' => 0, 'werk' => 7,
                   'tts' => array('mode' => 'musicserver', 'ip' => '192.168.1.5', 'alexa_token' => 'Sprech0123456789'),
                   'liste' => array(array('name' => 'a', 'wert' => 'x', 'kennwort' => $GEHEIM)), 'frei' => array('z1' => 1),
                   'vermerk' => 'v');
    $b['jetzt'] = function () use ($jetzt) { return $jetzt; };

    // --- Bauen: Kopf, Inhalt, keine Sprechtoken, keine Vermerke ---
    $daten = sicherung_bauen($b, $jetzt, array('zugang' => array('kennwort' => $GEHEIM)));
    $pruef('kopf plugin', $daten['_plugin'], 'LoxBerry-Plugin-Selbsttest');
    $pruef('kopf fassung', $daten['_fassung'], '9.8.7');
    $pruef('kopf reihenfolge', array_slice(array_keys($daten), 0, 4), array('_hinweis', '_plugin', '_fassung', '_stand'));
    $pruef('token drin', $daten['aktionstoken'], $TOK);
    $pruef('zugang drin', $daten['zugang']['kennwort'], $GEHEIM);
    $pruef('sprechtoken nicht drin', isset($daten['tts']['alexa_token']), false);
    $pruef('vermerk nicht drin', isset($daten['vermerk']), false);
    $pruef('keine warnung', isset($daten['_warnung']), false);
    $js = sicherung_json($daten);
    $pruef('json', is_string($js) && $js !== '', true);

    // --- Unveraendert zurueck ---
    $r = sicherung_lesen($b, $js, $jetzt);
    $pruef('eigen ok', $r['ok'], true);
    $pruef('eigen kennzeichen', $r['eigen'], 'kennzeichen');
    $pruef('eigen anzahl', $r['anzahl'], 10);
    $pruef('eigen nichts verworfen', $r['verworfen'], array());
    $pruef('eigen thema', $r['neu']['thema'], 'alt');
    $pruef('eigen zugang', $r['teile']['zugang']['kennwort'], $GEHEIM);
    $pruef('eigen fassung datei', $r['fassung_datei'], '9.8.7');
    // 1.2.1: der Vermerk steht nicht in der Datei und behaelt den geltenden Wert (bis 1.2.0 fehlte er danach)
    $pruef('eigen vermerk behaelt den geltenden wert', $r['neu']['vermerk'] ?? 'fehlt', 'v');

    // --- Regel 2: Unbekanntes oben, im Block, in der Liste ---
    $d2 = $daten;
    $d2['zz_oben'] = 'boese';
    $d2['tts']['zz_tief'] = 'boese';
    $d2['liste'][0]['zz_zeile'] = 'boese';
    $d2['frei']['zz_offen'] = 2;
    $r = sicherung_lesen($b, json_encode($d2), $jetzt);
    $pruef('r2 ok', $r['ok'], true);
    $pruef('r2 namen (Reihenfolge der Datei)', $r['verworfen'], array('tts.zz_tief', 'liste[1].zz_zeile', 'zz_oben'));
    $pruef('r2 tts ohne', array_key_exists('zz_tief', $r['neu']['tts']), false);
    $pruef('r2 zeile ohne', array_key_exists('zz_zeile', $r['neu']['liste'][0]), false);
    $pruef('r2 oben ohne', array_key_exists('zz_oben', $r['neu']), false);
    $pruef('r2 offen bleibt', $r['neu']['frei']['zz_offen'], 2);
    $pruef('r2 kein wert in namen', strpos(implode(',', $r['verworfen']), 'boese'), false);
    $d2t = $daten;
    $d2t['zugang']['zz_teil'] = 'boese';
    $d2t['zugang']['kennwort'] = '';
    $r = sicherung_lesen($b, json_encode($d2t), $jetzt);
    $pruef('r2 im teil verworfen', $r['verworfen'], array('zugang.zz_teil'));
    $pruef('r3 im teil: bisheriges kennwort bleibt', array($r['teile']['zugang']['kennwort'], $r['leer_behalten']),
           array($GEHEIM, array('zugang.kennwort')));
    $d2b = $daten;
    $d2b["zz\nZEILE;X=1"] = 1;
    $r = sicherung_lesen($b, json_encode($d2b), $jetzt);
    $pruef('r2 name bereinigt', $r['verworfen'], array('zz?ZEILE?X?1'));

    // --- Regel 3: leer ---
    $d3 = $daten;
    $d3['aktionstoken'] = '';
    $d3['tts']['ip'] = '';
    $d3['liste'][0]['kennwort'] = '';
    $r = sicherung_lesen($b, json_encode($d3), $jetzt);
    $pruef('r3 ok', $r['ok'], true);
    $pruef('r3 token bleibt', $r['neu']['aktionstoken'], $TOK);
    $pruef('r3 ip bleibt', $r['neu']['tts']['ip'], '192.168.1.5');
    $pruef('r3 kennwort der zeile bleibt (zuordnung name)', $r['neu']['liste'][0]['kennwort'], $GEHEIM);
    $pruef('r3 namen', $r['leer_behalten'], array('aktionstoken', 'tts.ip', 'liste[1].kennwort'));
    $leer = $jetzt;
    $leer['aktionstoken'] = '';
    $leer['tts']['ip'] = '';
    $r = sicherung_lesen($b, json_encode($d3), $leer);
    $pruef('r3 beidseits leer still', $r['leer_behalten'], array('liste[1].kennwort'));
    $pruef('r3 beidseits leer bleibt leer', $r['neu']['aktionstoken'], '');
    $d3b = $daten;
    $d3b['liste'][0]['name'] = 'b';
    $d3b['liste'][0]['kennwort'] = '';
    $r = sicherung_lesen($b, json_encode($d3b), $jetzt);
    $pruef('r3 ohne gegenstueck leer', $r['neu']['liste'][0]['kennwort'], '');
    $d3c = $daten;
    $d3c['liste'] = array();
    $r = sicherung_lesen($b, json_encode($d3c), $jetzt);
    $pruef('r3 leere liste wird uebernommen', $r['neu']['liste'], array());
    $pruef('leer bleibt: 0 ist kein leerer wert', sicherung_leer_bleibt('', 0), true);
    $pruef('leer bleibt: null', sicherung_leer_bleibt('', null), false);
    $pruef('leer bleibt: feld', sicherung_leer_bleibt('', array(1)), false);
    $pruef('leer bleibt: 0 in der datei', sicherung_leer_bleibt(0, 'x'), false);

    // --- Regel 4: halb gueltig, alles gesammelt ---
    $d4 = $daten;
    $d4['takt'] = 5;
    $d4['thema'] = 'A B';
    $d4['zz_oben'] = 1;
    $r = sicherung_lesen($b, json_encode($d4), $jetzt);
    $pruef('r4 abgewiesen', $r['ok'], false);
    $pruef('r4 neu null', $r['neu'], null);
    $pruef('r4 beide gesammelt', count($r['beanstandet']), 2);
    $pruef('r4 teile leer', $r['teile'], array());
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($b, json_encode($d4));
    $pruef('r4 nichts gespeichert', count($gespeichert), $n);
    $d4b = $daten;
    $d4b['takt'] = 13;
    $r = sicherung_lesen($b, json_encode($d4b), $jetzt);
    $pruef('r4 querpruefung', $r['beanstandet'], array(array('name' => 'takt', 'text' => 'takt 13 geht nicht mit thema')));

    // --- Regel 1: Kennzeichen ---
    $d1 = $daten;
    $d1['_plugin'] = 'octopus';
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 fremd', array($r['ok'], $r['fremd'], $r['kennzeichen_datei']), array(false, 'kennzeichen', 'octopus'));
    $d1['_plugin'] = array('x');
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 fremd ohne namensform', array($r['ok'], $r['kennzeichen_datei']), array(false, '?'));
    $d1 = $daten;
    unset($d1['_plugin']);
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 ohne', array($r['ok'], $r['fremd']), array(false, 'ohne'));
    $d1['_hinweis'] = 'Selbsttest-Sicherung von frueher';
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 alt erkannt', array($r['ok'], $r['eigen']), array(true, 'uebersetzt'));
    $d1 = $daten;
    $d1['_plugin'] = 'selbsttest';
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 frueheres kennzeichen', array($r['ok'], $r['eigen']), array(true, 'frueher'));
    $r = sicherung_lesen($b, '[1,2]', $jetzt);
    $pruef('form liste', $r['fremd'], 'form');
    $r = sicherung_lesen($b, 'kein json', $jetzt);
    $pruef('form text', $r['fremd'], 'form');
    $r = sicherung_lesen($b, str_repeat(' ', SICHERUNG_MAX_BYTE + 1), $jetzt);
    $pruef('zu gross', $r['fremd'], 'gross');

    // --- Fehlende Schluessel ---
    $d5 = $daten;
    unset($d5['spaet'], $d5['werk'], $d5['ein']);
    $r = sicherung_lesen($b, json_encode($d5), $jetzt);
    $pruef('fehlt pflicht', array($r['ok'], $r['beanstandet'][0]['name']), array(false, 'ein'));
    $d5['ein'] = 0;
    $r = sicherung_lesen($b, json_encode($d5), $jetzt);
    $pruef('fehlt behalten', array($r['ok'], $r['neu']['spaet'], $r['fehlt_behalten']), array(true, 0, array('spaet')));
    $pruef('fehlt vorgabe', array($r['neu']['werk'], $r['fehlt_vorgabe']), array(5, array('werk')));
    $pruef('alt still', sicherung_lesen($b, json_encode($daten + array('altschluessel' => 1)), $jetzt)['verworfen'], array());
    $nurkopf = array('_plugin' => 'LoxBerry-Plugin-Selbsttest');
    $r = sicherung_lesen($b, json_encode($nurkopf), $jetzt);
    $pruef('nur kopf abgewiesen', $r['ok'], false);

    // --- Zurueckspielen, Meldungen, Protokoll: keine Werte ---
    $prot = array();
    $b['log'] = function ($z) use (&$prot) { $prot[] = $z; };
    $r = sicherung_zurueckspielen($b, json_encode($d3 + array('zz_oben' => $GEHEIM)));
    $pruef('zurueck gespeichert', $r['gespeichert'], true);
    $pruef('zurueck dienst', $r['dienst'], array('Dienst neu gestartet.'));
    $m = sicherung_meldungen($b, $r);
    $alles = implode("\n", array_merge($m['fehler'], $m['hinweise'], $prot));
    $pruef('meldung ohne token', strpos($alles, $TOK), false);
    $pruef('meldung ohne kennwort', strpos($alles, $GEHEIM), false);
    $pruef('meldung nennt verworfen', strpos($alles, 'zz_oben') !== false, true);
    $pruef('meldung nennt leer', strpos($alles, 'tts.ip') !== false, true);
    $pruef('protokoll eine zeile', count($prot), 1);
    $r = sicherung_lesen($b, json_encode($d4), $jetzt);
    $m = sicherung_meldungen($b, $r);
    $pruef('meldung abgelehnt', count($m['fehler']), 1);

    // --- Selbstpruefung beim Sichern ---
    $kaputt = $jetzt;
    $kaputt['takt'] = 1;
    $daten_k = sicherung_bauen($b, $kaputt);
    $pruef('warnung bei eigener unbrauchbarer', isset($daten_k['_warnung']) && strpos($daten_k['_warnung'], 'takt') !== false, true);
    $pruef('warnung ohne wert', isset($daten_k['_warnung']) && strpos($daten_k['_warnung'], $TOK) === false, true);

    // --- Beschreibung, Fassung, Texte ---
    $pruef('beschreibung ohne kennzeichen', sicherung_beschreibung_mangel(array('vorgaben' => array('a' => 1))), array('kennzeichen'));
    $pruef('fassung ohne quelle', sicherung_fassung_lesen(array()), '');
    $pruef('namensform', array(sicherung_namensform('a.b-c_1'), sicherung_namensform("a\n"), sicherung_namensform('')), array(true, false, false));
    $pruef('schluessel zahl', count(sicherung_sprachschluessel()), 24);
    $pruef('schluessel eindeutig', count(array_unique(sicherung_sprachschluessel())), 24);

    // --- 1.2.0 (a) Rohwert an 'pruefen' und 'quer' ---
    $gesehen = array();
    $ba = $b;
    $ba['schluessel']['tts']['pruefen'] = function ($w, $k, $neu, $roh = 'fehlt') use (&$gesehen) {
        $gesehen['tts'] = array(is_array($w) ? $w['ip'] : '?', is_array($roh) ? $roh['ip'] : $roh);
        return '';
    };
    $ba['quer'] = function ($neu, $r, $daten = 'fehlt') use (&$gesehen) {
        $gesehen['quer'] = is_array($daten) && isset($daten['tts']['ip']) ? $daten['tts']['ip'] : $daten;
        return array();
    };
    $r = sicherung_lesen($ba, json_encode($d3), $jetzt);
    $pruef('roh: pruefen sieht Wert nach Regel 3 und Rohwert', isset($gesehen['tts']) ? $gesehen['tts'] : null,
           array('192.168.1.5', ''));
    $pruef('roh: quer sieht die Datei vor Regel 3', isset($gesehen['quer']) ? $gesehen['quer'] : null, '');
    $gesehen = array();
    $bo = $b;
    $bo['pruefen'] = function ($k, $w, $neu, $roh = 'fehlt') use (&$gesehen) {
        if ($k === 'tts') { $gesehen['oben'] = array(isset($w['zz_tief']), is_array($roh) && isset($roh['zz_tief'])); }
        return '';
    };
    sicherung_lesen($bo, json_encode($d2), $jetzt);
    $pruef('roh: oberes pruefen sieht den Rohwert (vor Regel 2)', isset($gesehen['oben']) ? $gesehen['oben'] : null,
           array(false, true));

    // --- 1.2.0 (b) flache Teile, (f) 'fehlt' im Block, (g) Hinweise aus Teilen ---
    $ZJ = array('a' => 'Alt-A', 'b' => 'Alt-B');
    $bf = array('kennzeichen' => 'flach', 'vorgaben' => array('x' => 1),
                'schluessel' => array('z' => sicherung_block(array('a', 'b'), array('b' => array('fehlt' => 'behalten')))),
                'teile' => array('z' => function ($w) {
                    if (!is_array($w) || !isset($w['a']) || !isset($w['b'])) { return array(null, array('z unvollstaendig')); }
                    return array($w, array(), array('Hinweis aus Teil z.'));
                }),
                'teile_flach' => array('z' => 'z_'), 'teile_jetzt' => array('z' => $ZJ),
                't' => function ($s) { return $s . ' [%s]'; });
    $df = sicherung_bauen($bf, array('x' => 2), array('z' => array('a' => 'A1', 'b' => 'B1')));
    $pruef('flach: bauen schreibt z_a und z_b', array(isset($df['z_a']) ? $df['z_a'] : null, isset($df['z_b']) ? $df['z_b'] : null,
           isset($df['z'])), array('A1', 'B1', false));
    $r = sicherung_lesen($bf, json_encode($df), array('x' => 1));
    $pruef('flach: zurueck als Teil', array($r['ok'], $r['teile']['z']), array(true, array('a' => 'A1', 'b' => 'B1')));
    $pruef('flach: Hinweis des Teils im Ergebnis', $r['teile_hinweise'], array('Hinweis aus Teil z.'));
    $m = sicherung_meldungen($bf, $r);
    $pruef('flach: Hinweis des Teils in der Meldung', in_array('Hinweis aus Teil z.', $m['hinweise'], true), true);
    $df2 = $df;
    $df2['z_a'] = '';
    $df2['z_q'] = 'boese';
    unset($df2['z_b']);
    $r = sicherung_lesen($bf, json_encode($df2), array('x' => 1));
    $pruef('flach: Regel 3, Regel 2 und fehlt je Feld, Namen wie in der Datei',
           array($r['ok'], $r['teile']['z'], $r['leer_behalten'], $r['verworfen'], $r['fehlt_behalten']),
           array(true, array('a' => 'Alt-A', 'b' => 'Alt-B'), array('z_a'), array('z_q'), array('z_b')));
    $df3 = $df;
    unset($df3['z_a'], $df3['z_b']);
    $r = sicherung_lesen($bf, json_encode($df3), array('x' => 1));
    $pruef('flach: Teil fehlt ganz - Teil unberuehrt, Felder mit behalten genannt',
           array($r['ok'], isset($r['teile']['z']), $r['fehlt_behalten']), array(true, false, array('z_b')));
    $bf2 = $bf;
    $bf2['teile_flach'] = array('fremd' => 'q_');
    $pruef('flach: Teil ohne Eintrag in teile ist ein Mangel', sicherung_beschreibung_mangel($bf2), array('teile_flach'));
    $d6 = $daten;
    unset($d6['tts']['mode']);
    $bb = $b;
    $bb['schluessel']['tts']['schluessel']['mode'] = array('fehlt' => 'behalten');
    $r = sicherung_lesen($bb, json_encode($d6), $jetzt);
    $pruef('fehlt im Block: geltender Wert und Name', array($r['ok'], $r['neu']['tts']['mode'], in_array('tts.mode', $r['fehlt_behalten'], true)),
           array(true, 'musicserver', true));
    $r = sicherung_lesen($b, json_encode($d6), $jetzt);
    $pruef('fehlt im Block: ohne Angabe wie bisher (kein Name)', in_array('tts.mode', $r['fehlt_behalten'], true), false);

    // --- 1.2.0 (g) fehlt_vorgabe in der Meldung ---
    $r = sicherung_lesen($b, json_encode($d5), $jetzt);
    $m = sicherung_meldungen($b, $r);
    $pruef('fehlt_vorgabe in der Meldung', strpos(implode("\n", $m['hinweise']), 'K_FEHLT_VORGABE') !== false
           && strpos(implode("\n", $m['hinweise']), 'werk') !== false, true);

    // --- 1.2.0 (c) eigene Warnsaetze an _warnung ---
    $bw = $b;
    $bw['warnung'] = function () { return array('Eigener Satz der Linie.'); };
    $dw = sicherung_bauen($bw, $jetzt);
    $pruef('warnung: eigener Satz allein', isset($dw['_warnung']) ? $dw['_warnung'] : null, 'Eigener Satz der Linie.');
    $dw = sicherung_bauen($bw, $kaputt);
    $pruef('warnung: nach dem Satz des Moduls', isset($dw['_warnung']) && strpos($dw['_warnung'], 'K_WARNUNG') !== false
           && substr($dw['_warnung'], -23) === 'Eigener Satz der Linie.', true);
    $pruef('warnung: ohne Angabe kein Kopf', isset($daten['_warnung']), false);

    // --- 1.2.0 (d) Muster fuer fruehere Kennzeichen ---
    $bk = $b;
    $bk['kennzeichen_frueher_muster'] = '/^Selbsttest \([a-z0-9_-]{1,40}\)\z/';
    $d1 = $daten;
    $d1['_plugin'] = 'Selbsttest (meinordner)';
    $r = sicherung_lesen($bk, json_encode($d1), $jetzt);
    $pruef('muster: frueheres Kennzeichen erkannt', array($r['ok'], $r['eigen']), array(true, 'frueher'));
    $d1['_plugin'] = 'Selbsttest (mein ordner)';
    $r = sicherung_lesen($bk, json_encode($d1), $jetzt);
    $pruef('muster: anderes bleibt fremd', array($r['ok'], $r['fremd']), array(false, 'kennzeichen'));
    $r = sicherung_lesen($b, json_encode(array('_plugin' => 'Selbsttest (meinordner)') + $daten), $jetzt);
    $pruef('muster: ohne Angabe fremd', $r['fremd'], 'kennzeichen');
    $bk['kennzeichen_frueher_muster'] = '/[/';
    $pruef('muster: unbrauchbares Muster ist ein Mangel', sicherung_beschreibung_mangel($bk), array('kennzeichen_frueher_muster'));

    // --- 1.2.0 (e) umbenennen ---
    $bu = $b;
    $bu['umbenennen'] = array('_zugang' => 'zugang', '_thema' => 'thema');
    $du = $daten;
    $du['_zugang'] = $du['zugang'];
    unset($du['zugang']);
    $du['_thema'] = 'neu';
    $r = sicherung_lesen($bu, json_encode($du), $jetzt);
    $pruef('umbenennen: alter Name gilt als neuer', array($r['ok'], $r['teile']['zugang']['kennwort']), array(true, $GEHEIM));
    $pruef('umbenennen: heutiger Name schon da - der alte bleibt Kopf', $r['neu']['thema'], 'alt');
    $r = sicherung_lesen($b, json_encode($du), $jetzt);
    $pruef('umbenennen: ohne Angabe bleibt _zugang Kopf', isset($r['teile']['zugang']), false);

    // --- 1.2.0 (h) vorbedingung ---
    $bv = $b;
    $gesp = 0;
    $bv['speichern'] = function () use (&$gesp) { $gesp++; return true; };
    $bv['vorbedingung'] = function () { return array('Vorher geht etwas nicht.'); };
    $bv['log'] = function () {};
    $r = sicherung_zurueckspielen($bv, json_encode($daten));
    $m = sicherung_meldungen($bv, $r);
    $pruef('vorbedingung: nicht gespeichert', array($gesp, $r['gespeichert'], $r['vorbedingung']), array(0, false, array('Vorher geht etwas nicht.')));
    $pruef('vorbedingung: Meldung ohne Schreibfehler', $m['fehler'], array('Vorher geht etwas nicht.'));
    $bv['vorbedingung'] = function () { return array(); };
    $r = sicherung_zurueckspielen($bv, json_encode($daten));
    $pruef('vorbedingung erfuellt: gespeichert', array($gesp, $r['gespeichert']), array(1, true));
    $bv['speichern'] = function () { return false; };
    $r = sicherung_zurueckspielen($bv, json_encode($daten));
    $m = sicherung_meldungen($bv, $r);
    $pruef('ohne vorbedingung-Satz: Schreibfehler wie bisher', strpos(implode(' ', $m['fehler']), 'K_SCHREIBFEHLER') !== false, true);

    // --- 1.2.1: ein Vermerk ('nie_aus_datei') behaelt beim Zurueckspielen seinen geltenden Wert ---
    $dn = $daten;
    $dn['vermerk'] = 'aus-der-datei';
    $r = sicherung_lesen($b, json_encode($dn), $jetzt);
    $pruef('nie_aus_datei: Datei traegt ihn - der geltende gilt, nicht genannt',
           array($r['ok'], isset($r['neu']['vermerk']) ? $r['neu']['vermerk'] : 'fehlt', $r['verworfen'], $r['fehlt_behalten'],
                 $r['fehlt_vorgabe']),
           array(true, 'v', array(), array(), array()));
    $jn = $jetzt;
    unset($jn['vermerk']);
    $r = sicherung_lesen($b, json_encode($dn), $jn);
    $pruef('nie_aus_datei: nicht in der geltenden Konfiguration - fehlt wie bisher', array($r['ok'], isset($r['neu']['vermerk'])),
           array(true, false));
    $bn = $b;
    $bn['vorgaben']['vermerk'] = 'werk';
    $r = sicherung_lesen($bn, json_encode($dn), $jn);
    $pruef('nie_aus_datei: in den Vorgaben, nicht geltend - Vorgabe wie bisher', $r['neu']['vermerk'], 'werk');
    $r = sicherung_lesen($bn, json_encode($dn), $jetzt);
    $pruef('nie_aus_datei: in den Vorgaben und geltend - der geltende', $r['neu']['vermerk'], 'v');
    $bn = $b;
    $bn['vorgaben']['vermerk'] = 'werk';
    $bn['vorbehandeln'] = function ($bereit) { $bereit['vermerk'] = 'linie'; return $bereit; };
    $r = sicherung_lesen($bn, json_encode($dn), $jetzt);
    $pruef('nie_aus_datei: vorbehandeln setzt ihn - der Wert der Linie', $r['neu']['vermerk'], 'linie');
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($b + array('log' => function () {}), json_encode($dn));
    $pruef('nie_aus_datei: gespeichert mit dem geltenden Vermerk',
           array($r['gespeichert'], count($gespeichert) - $n, isset($gespeichert[$n]['vermerk']) ? $gespeichert[$n]['vermerk'] : 'fehlt'),
           array(true, 1, 'v'));
    $pruef('nie_aus_datei: nie gesichert, auch wenn er in den Vorgaben steht', array_key_exists('vermerk', sicherung_bauen($bn, $jetzt)), false);

    // --- 1.2.2: ein Wert, den 'vorbehandeln' fuer einen fehlenden Schluessel setzt, bleibt ('behalten') ---
    $du = $daten;
    unset($du['spaet'], $du['werk']);
    $bu2 = $b;
    $bu2['vorbehandeln'] = function ($bereit, $jetzt, $daten) {
        if (!array_key_exists('spaet', $daten)) { $bereit['spaet'] = 9; }
        if (!array_key_exists('werk', $daten)) { $bereit['werk'] = 8; }
        return $bereit;
    };
    $r = sicherung_lesen($bu2, json_encode($du), $jetzt);
    $pruef('uebergang: behalten - der Wert der Linie bleibt, eigens genannt',
           array($r['ok'], $r['neu']['spaet'], $r['fehlt_uebergang'], $r['fehlt_behalten']), array(true, 9, array('spaet'), array()));
    $pruef('uebergang: vorgabe wie bisher - Wert der Linie, als vorgabe genannt',
           array($r['neu']['werk'], $r['fehlt_vorgabe'], in_array('werk', $r['fehlt_uebergang'], true)), array(8, array('werk'), false));
    $m = sicherung_meldungen($bu2, $r);
    $h = implode("\n", $m['hinweise']);
    $pruef('uebergang: Meldung mit K_FEHLT_UEBERGANG, nicht als behalten',
           array(strpos($h, 'K_FEHLT_UEBERGANG [spaet]') !== false, strpos($h, 'K_FEHLT_BEHALTEN') === false), array(true, true));
    $z = sicherung_protokollzeile($r);
    $pruef('uebergang: Protokoll nennt die Uebergangsregel, nicht den bisherigen Wert',
           array(strpos($z, 'Uebergangsregel der Linie: spaet') !== false, strpos($z, 'bisheriger Wert bleibt') === false), array(true, true));
    $bu3 = $b;
    $bu3['vorbehandeln'] = function ($bereit) { return $bereit; };
    $r = sicherung_lesen($bu3, json_encode($du), $jetzt);
    $pruef('uebergang: setzt vorbehandeln nichts - geltender Wert wie bisher',
           array($r['ok'], $r['neu']['spaet'], $r['fehlt_behalten'], $r['fehlt_uebergang']), array(true, 0, array('spaet'), array()));
    $dp = $daten;
    unset($dp['ein']);
    $bu4 = $b;
    $bu4['vorbehandeln'] = function ($bereit) { $bereit['ein'] = 1; return $bereit; };
    $r = sicherung_lesen($bu4, json_encode($dp), $jetzt);
    $pruef('uebergang: pflicht bleibt pflicht, auch wenn vorbehandeln ihn setzt',
           array($r['ok'], isset($r['beanstandet'][0]['name']) ? $r['beanstandet'][0]['name'] : ''), array(false, 'ein'));
    $bu5 = $bu2;
    $bu5['schluessel']['spaet']['pruefen'] = function ($w) { return $w === 9 ? 'spaet unzulaessig' : ''; };
    $r = sicherung_lesen($bu5, json_encode($du), $jetzt);
    $pruef('uebergang: der Wert der Linie geht durch die Pruefung wie bisher',
           array($r['ok'], isset($r['beanstandet'][0]['name']) ? $r['beanstandet'][0]['name'] : ''), array(false, 'spaet'));
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($bu2 + array('log' => function () {}), json_encode($du));
    $pruef('uebergang: gespeichert mit dem Wert der Linie',
           array($r['gespeichert'], count($gespeichert) - $n, isset($gespeichert[$n]['spaet']) ? $gespeichert[$n]['spaet'] : 'fehlt'),
           array(true, 1, 9));

    // --- 1.1.0: der Haken und der Eingang des Knopfes (ohne hochgeladene Datei: __FILE__ steht fuer eine Datei) ---
    // Die Texte kommen ueber $b['t'] (hier: Kennung mit Anhang); keine Kennung steht woertlich im Code, damit die
    // Schluesselpruefungen der Linien sie nicht fuer einen benutzten Sprachschluessel halten.
    $zeile = sicherung_haken_zeile($b);
    $pruef('haken zeile', $zeile, '<div class="sm-feld"><label><input data-role="none" type="checkbox" name="zurueck_ok"'
           . ' value="1"> ' . htmlspecialchars(sicherung_t('K_HAKEN', $b), ENT_QUOTES, 'UTF-8') . '</label></div>');
    $pruef('haken zeile traegt den text', strpos($zeile, 'K_HAKEN [') !== false, true);
    $pruef('haken feldname', SICHERUNG_HAKEN, 'zurueck_ok');
    $ja = function () { return true; };
    $nein = function () { return false; };
    $gelesen = 0;
    $zaehlt = function ($p) use (&$gelesen) { $gelesen++; return true; };
    $art = function ($ab) { return (is_array($ab) && isset($ab['abbruch'])) ? $ab['abbruch'] : '(kein Abbruch)'; };
    $ich = array('tmp_name' => __FILE__, 'size' => (int) filesize(__FILE__), 'error' => 0);
    list($roh, $ab) = sicherung_eingang_lage($b, array(), $ich, $zaehlt);
    $pruef('ohne haken: abbruch', array($roh, $art($ab), is_array($ab) && $ab['ok'] === false && $ab['gespeichert'] === false),
           array(null, 'haken', true));
    $pruef('ohne haken: datei nicht angesehen', $gelesen, 0);
    $m = is_array($ab) ? sicherung_meldungen($b, $ab) : array('fehler' => array(''), 'hinweise' => array('-'));
    $pruef('ohne haken: eine meldung mit dem modultext', array(count($m['fehler']),
           strpos($m['fehler'][0], sicherung_t('K_HAKEN_FEHLT', $b)) !== false,
           strpos($m['fehler'][0], sicherung_t('K_ABGELEHNT', $b)) === 0, $m['hinweise']), array(1, true, true, array()));
    foreach (array('0', 'ja', '', array('1')) as $w) {
        list($roh, $ab) = sicherung_eingang_lage($b, array('zurueck_ok' => $w), $ich, $ja);
        $pruef('haken mit wert ' . var_export($w, true) . ' zaehlt nicht', $art($ab), 'haken');
    }
    list($roh, $ab) = sicherung_eingang_lage($b, array('anderes' => '1'), $ich, $ja);
    $pruef('anderes feld ist kein haken', $art($ab), 'haken');
    $mit = array('zurueck_ok' => '1');
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, null, $ja);
    $pruef('haken, keine datei', array($roh, $art($ab)), array(null, 'datei'));
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, $ich, $nein);
    $pruef('haken, datei nicht hochgeladen', array($roh, $art($ab)), array(null, 'datei'));
    list($roh, $ab) = sicherung_eingang($b, $mit, array('f' => $ich), 'f');
    $pruef('oeffentlich: is_uploaded_file weist eine Serverdatei ab', array($roh, $art($ab)), array(null, 'datei'));
    list($roh, $ab) = sicherung_eingang($b, $mit, array(), 'f');
    $pruef('oeffentlich: feld fehlt', $art($ab), 'datei');
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, array('tmp_name' => '', 'size' => 0, 'error' => 4), $ja);
    $pruef('haken, leeres feld', $art($ab), 'datei');
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, array('tmp_name' => '', 'size' => 0, 'error' => 1), $ja);
    $pruef('haken, ueber upload_max_filesize', array($art($ab), is_array($ab) ? $ab['fremd'] : ''), array('gross', 'gross'));
    list($roh, $ab) = sicherung_eingang_lage($b + array('max_byte' => 1024), $mit, $ich, $ja);
    $pruef('haken, groesser als max_byte', array($roh, $art($ab)), array(null, 'gross'));
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, array('tmp_name' => __FILE__ . '.fehlt', 'size' => SICHERUNG_MAX_BYTE + 1,
                                                             'error' => 0), $ja);
    $pruef('haken, angegebene groesse zu gross: nicht erst gelesen', $art($ab), 'gross');
    $klein = $ich;
    $klein['size'] = 10;
    list($roh, $ab) = sicherung_eingang_lage($b + array('max_byte' => 1024), $mit, $klein, $ja);
    $pruef('haken, groesse falsch angegeben: gelesen wird hoechstens max_byte + 1', array($roh, $art($ab)), array(null, 'gross'));
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, $ich, $ja);
    $pruef('haken und datei: text zurueck', array($roh === file_get_contents(__FILE__), $ab), array(true, null));
    $n = count($gespeichert);
    foreach (array(array(), array('zurueck_ok' => '0')) as $p) { sicherung_eingang_lage($b, $p, $ich, $ja); }
    $pruef('eingang speichert nie', count($gespeichert), $n);
    return array($faelle, $fehl, $zeilen);
}

}   // Ende: if (!defined('SICHERUNG_FASSUNG'))

/* Kommandozeile: php sicherung.php --selbsttest (nur, wenn diese Datei selbst gestartet wurde). */
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && isset($argv[0])
    && realpath($argv[0]) === realpath(__FILE__) && in_array('--selbsttest', $argv, true)) {
    list($sicherung_n, $sicherung_f, $sicherung_z) = sicherung_selbsttest();
    foreach ($sicherung_z as $sicherung_zz) { echo $sicherung_zz, "\n"; }
    echo 'Rechenkern sicherung.php ' . SICHERUNG_FASSUNG . ': ' . $sicherung_n . ' Faelle geprueft, ' . $sicherung_f . " Fehlschlaege.\n";
    exit($sicherung_f === 0 ? 0 : 1);
}
