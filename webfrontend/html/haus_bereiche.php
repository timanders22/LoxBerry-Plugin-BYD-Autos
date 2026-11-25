<?php
/**
 * Gemeinsame Bereiche unter haus/ - die Praefixregel der Linien
 *
 * Grundlage: Regeln/07, Abschnitt "Haus-Themen fuer das Zusammenspiel der Linien", Absatz "Praefix einer Linie im
 * gemeinsamen Bereich" (Entscheidung des Hausherrn 23.11.2026 "Nur gemeinsame Bereiche"). Unter haus/<bereich>
 * liefern die Linien einander Zustaende ohne eigenes Praefix; das einstellbare MQTT-Praefix einer Linie darf dort
 * nicht hineinfallen.
 *
 * Stammfassung: Werkzeuge/gemeinsam/haus_bereiche.php. In jeder Linie mit einstellbarem MQTT-Praefix BYTE-GLEICH neben
 * der Bibliothek der Linie (webfrontend/html/ oder webfrontend/htmlauth/, bei Kodi-NG bin/). Die Python-Fassung
 * haus_bereiche.py traegt dieselbe Liste fuer die Dienste; Werkzeuge/gemeinsam_pruefen.py haelt beide Abschriften
 * byte-gleich und beide Listen gegen Regeln/07. Die Texte stehen im Abschnitt [HAUSBEREICH] der Sprachdateien, gebaut
 * aus haus_bereiche_texte.json (gemeinsam_pruefen.py --hb-abschnitt de|en; geprueft wie [ANSAGE]).
 *
 * Die Regel:
 *   - Ein Praefix, das haus/<bereich> ist oder damit beginnt (haus/<bereich>/...), weisen Speichern und
 *     Zurueckspielen ab und nennen es mit Namen (hb_bereich() !== '').
 *   - Ein schon gespeichertes Praefix bleibt in Betrieb. Die Linie meldet es im Reiter Test rot und einmal je
 *     Aenderung im Protokoll (hb_melden_faellig(), Text hb_logtext()).
 *   - haus allein und andere Praefixe unter haus/ (haus/fenster, haus/alexa-ng) bleiben erlaubt.
 *   - Verglichen wird genau: MQTT unterscheidet Gross und Klein, "Haus/tuer" ist ein anderes Praefix. Schraegstriche
 *     und Leerraum am Rand zaehlen nicht ("/haus/tuer/" faellt auf haus/tuer).
 * Ein neuer Bereich kommt zuerst nach Regeln/07, dann hierher und nach haus_bereiche.py; HB_FASSUNG anheben und in
 * alle Linien verteilen (gemeinsam_pruefen.py --verteilen).
 *
 * NUR REINE FUNKTIONEN bis auf hb_melden_faellig(), die eine Merkdatei der Linie liest und schreibt. Kein Netz, kein
 * Broker, keine Sprachdatei.
 *
 * Laeuft auf: PHP 7.4 bis 8.5. Schuetzt sich gegen doppeltes Laden; ein direkter Aufruf ueber den Webserver
 * antwortet mit 403.
 */

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
        && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}
// Doppeltes Laden: Funktionen auf oberster Ebene legt PHP schon beim Uebersetzen an, ein 'return' davor schuetzt
// nicht (gemessen 23.11.2026 mit PHP 7.4/8.4/8.5: "Cannot redeclare"). Deshalb steht alles im Bedingungsblock.
if (!defined('HB_FASSUNG')) {
define('HB_FASSUNG', '1.0.0');

/** Die gemeinsamen Bereiche unter haus/ (Regeln/07, gemessen 23.11.2026), alphabetisch. */
function hb_bereiche()
{
    return array('abfahrt', 'abfuhr', 'alarm', 'ansage', 'anwesenheit', 'energie', 'fahrzeug', 'kalender', 'klima', 'pv',
                 'raumklima', 'sonne', 'speicher', 'strompreis', 'szene', 'tag', 'tuer', 'usv', 'wetter');
}

/**
 * Faellt das Praefix auf einen gemeinsamen Bereich? Rueckgabe der Bereich (etwa 'tuer'), sonst ''. Ein Wert, der
 * keine Zeichenkette und keine Zahl ist, faellt auf keinen Bereich (die Linie weist ihn mit ihrem Muster ab).
 */
function hb_bereich($praefix)
{
    if (!is_string($praefix) && !is_int($praefix)) {
        return '';
    }
    $t = explode('/', trim((string) $praefix, "/ \t\r\n"));
    return count($t) >= 2 && $t[0] === 'haus' && in_array($t[1], hb_bereiche(), true) ? $t[1] : '';
}

/**
 * Ist jetzt eine Protokollzeile faellig? Rueckgabe der Bereich, wenn das Praefix auf einen gemeinsamen Bereich faellt
 * und seit der letzten Meldung ein anderes ist; sonst ''. Gemerkt wird das zuletzt gemeldete Praefix in $merkdatei
 * (von der Linie in ihrem Datenordner gewaehlt), damit auch ein Minutentakt nur einmal je Aenderung meldet; faellt
 * das Praefix auf keinen Bereich, wird die Merkdatei geleert (angelegt wird sie dann nicht). $merkdatei '' merkt im
 * laufenden Prozess (Dienst mit Dauerlauf: einmal je Start und Aenderung). Laesst sich die Merkdatei nicht schreiben,
 * wird bei jedem Aufruf gemeldet - lieber zu oft als nie.
 */
function hb_melden_faellig($praefix, $merkdatei = '')
{
    static $im_prozess = '';
    $bereich = hb_bereich($praefix);
    $jetzt = $bereich !== '' ? trim((string) $praefix) : '';
    if ((string) $merkdatei === '') {
        $vorher = $im_prozess;
        $im_prozess = $jetzt;
    } else {
        $vorher = is_file($merkdatei) ? trim((string) @file_get_contents($merkdatei)) : '';
        if ($vorher !== $jetzt) {
            @file_put_contents($merkdatei, $jetzt, LOCK_EX);
        }
    }
    return ($jetzt !== '' && $jetzt !== $vorher) ? $bereich : '';
}

/** Die Protokollzeile (ASCII, gleich in allen Linien und in haus_bereiche.py). */
function hb_logtext($praefix, $bereich)
{
    return 'MQTT: das Themen-Praefix ' . trim((string) $praefix) . ' faellt auf den gemeinsamen Bereich haus/'
        . $bereich . ' - dort liefern die Plugins einander Zustaende ohne eigenes Praefix. Der Betrieb laeuft '
        . 'unveraendert weiter; bitte in den Einstellungen ein anderes Praefix eintragen (Speichern weist es ab).';
}
}
