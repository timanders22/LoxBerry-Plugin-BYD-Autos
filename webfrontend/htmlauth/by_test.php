<?php
/**
 * BYD Autos - die Aktionen und die Selbstpruefung des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone und ohne BYD-Konto, ob die
 * Einrichtung traegt. Was sich nur mit Fahrzeug pruefen liesse, wird als
 * solches benannt statt geraten.
 *
 * Drei Grundsaetze, jeder aus einem Vorfall:
 *
 *  1. Eine Pruefzeile, die ueber eine MENGE urteilt, prueft zuerst, ob die
 *     Menge leer ist - und sagt dann, dass sie nichts sagen kann. "Alle 0 von
 *     0 sind in Ordnung" ist kein Haken.
 *  2. Ein Hinweis (grauer Punkt) ist fuer "geht mich nichts an" da, nicht fuer
 *     "ich weiss es nicht". Wo etwas nicht messbar ist, wird das gesagt.
 *  3. Eine Zusammenfassung darf nicht besser aussehen als ihr schlechtester
 *     Punkt.
 */

/** Eine Zeile der Selbstpruefung. $stand: 1 = ja, 0 = nein, -1 = Hinweis. */
function by_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

/**
 * Ruft den EIGENEN Endpunkt wirklich ueber HTTP auf.
 *
 * Das ist die einzige Pruefzeile, die die teuerste Fehlerklasse dieses Hauses
 * findet: installiert liegen html/ und htmlauth/ in getrennten Baeumen, und
 * ein Endpunkt, der seine Bibliothek dort nicht findet, antwortet mit HTTP 500
 * und leerem Rumpf. In Loxone sieht das aus wie "kein Wert" - der virtuelle
 * Eingang behaelt seinen letzten Stand, in der App wirkt alles normal. Keine
 * Lesepruefung und keine Syntaxpruefung sieht das.
 *
 * Serverseitig ist 127.0.0.1 dabei die RICHTIGE Adresse. Das widerspricht
 * nicht der Regel "ein Knopf auf 127.0.0.1 kann nie funktionieren" - die gilt
 * fuer einen Link, den ein Mensch im Browser anklickt.
 *
 * Drei Ausgaenge, nicht zwei. Der dritte ist wichtig: ein Webserver, der nur
 * eine Anfrage zugleich bearbeitet, kann sich waehrend des Seitenaufbaus nicht
 * selbst aufrufen. Ein Kreuz waere dort ein Kreuz, das nichts bedeutet.
 *
 * Zwischengespeichert, weil alle Reiter mitgerendert werden: ohne den Speicher
 * ruft sich der Webserver bei JEDEM Klick selbst auf. Das Alter der Antwort
 * steht dabei.
 */
function by_endpunkt_pruefen($frisch = false)
{
    $p = by_paths();
    $speicher = $p['datadir'] . '/endpunkt_probe.json';
    if (!$frisch && is_file($speicher)) {
        $alt = by_json_lesen($speicher);
        if (isset($alt['ts']) && (time() - (int) $alt['ts']) < 900) {
            $alt['alter'] = time() - (int) $alt['ts'];
            return $alt;
        }
    }
    $token = by_token();
    $url = 'http://127.0.0.1/plugins/' . $p['plugin'] . '/index.php?selftest=1&token='
         . rawurlencode($token);
    $rumpf = false;
    $code = 0;
    $weg = '';
    if (function_exists('curl_init')) {
        $weg = 'curl';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Drei Sekunden, nicht acht: die Zeile laeuft bei jedem Seitenaufbau,
        // und im Fehlerfall wartet der Anwender genau dann, wenn etwas nicht
        // stimmt.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $rumpf = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $weg = 'file_get_contents';
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 3, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 1)));
        // Ein eigener Fehler-Aufnehmer: das @ unterdrueckt die Anzeige, aber
        // ein ueber set_error_handler eingehaengter Aufnehmer wird trotzdem
        // gerufen - und im Pruefaufbau ist "kein Webserver erreichbar" der
        // Normalfall, nicht ein Befund.
        set_error_handler(function () { return true; });
        $rumpf = file_get_contents($url, false, $ctx);
        restore_error_handler();
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $z) {
                if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
                    $code = (int) $m[1];   // bei Weiterleitung gilt die letzte
                }
            }
        }
    }
    $d = array(
        'ts'    => time(),
        'alter' => 0,
        'weg'   => $weg,
        'code'  => $code,
        'rumpf' => is_string($rumpf) ? substr(trim($rumpf), 0, 200) : '',
        'da'    => is_string($rumpf) ? 1 : 0,
    );
    // Der Speicher liegt im Datenordner; ein Fehlschlag beim Schreiben ist
    // kein Grund, die Messung zu verwerfen.
    @by_json_schreiben_probe($speicher, $d);
    return $d;
}

function by_json_schreiben_probe($pfad, $d)
{
    $js = json_encode($d);
    if ($js === false) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $js) !== strlen($js)) {
        @unlink($tmp);
        return false;
    }
    return @rename($tmp, $pfad);
}

/**
 * Liest die Vorgabeliste aus bin/byd.py.
 *
 * Fast jedes Plugin dieser Reihe fuehrt die Vorgabewerte zweimal: in VORGABEN
 * des Python-Dienstes und in by_vorgaben() der PHP-Bibliothek. Der Kommentar
 * "muss zur anderen passen" ist eine Hoffnung. Gelesen werden nur Zeilen, die
 * wirklich einen Schluessel setzen - ein Kommentar, der einen Schluessel
 * erwaehnt, darf nicht mitzaehlen.
 */
function by_vorgaben_dienst()
{
    $f = by_paths()['bindir'] . '/byd.py';
    if (!is_file($f)) {
        return null;
    }
    $t = (string) @file_get_contents($f);
    $pos = strpos($t, 'VORGABEN = {');
    if ($pos === false) {
        return null;
    }
    $ende = strpos($t, '}', $pos);
    if ($ende === false) {
        return null;
    }
    $block = substr($t, $pos, $ende - $pos);
    $aus = array();
    foreach (explode("\n", $block) as $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '' || strpos($zeile, '#') === 0) {
            continue;
        }
        if (preg_match('/^"([a-z_]+)"\s*:/', $zeile, $m)) {
            $aus[] = $m[1];
        }
    }
    return $aus;
}

/**
 * Welche Reiter kennen Leiste, Bereiche und Positivliste?
 *
 * Drei Stellen, die dasselbe fuehren muessen: die Positivliste (sonst springt
 * die Seite nach jedem Absenden auf Einstellungen zurueck), die Reiterleiste
 * und die id der Bereiche. Nur zwei davon zu vergleichen genuegt nicht - eine
 * Gegenprobe, die einen Namen aus der Quelle entfernte, liess den Reiter
 * unerreichbar werden und die Pruefung gruen.
 */
function by_reiter_lesen()
{
    $f = __DIR__ . '/index.php';
    if (!is_file($f)) {
        return null;
    }
    $t = (string) @file_get_contents($f);
    $aus = array('liste' => array(), 'leiste' => array(), 'bereiche' => array());
    if (preg_match('/by_reiter_liste\(\)\s*\{\s*return\s+array\((.*?)\);/s', $t, $m)) {
        preg_match_all("/'tab-([a-z]+)'/", $m[1], $x);
        $aus['liste'] = $x[1];
    }
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $t, $y);
    $aus['leiste'] = $y[1];
    preg_match_all('/id="tab-([a-z]+)"/', $t, $z);
    $aus['bereiche'] = $z[1];
    return $aus;
}

/* Die Positivliste der Formularmarken aus index.php lesen.
 *
 * Aus dem QUELLTEXT und nicht durch Einbinden: index.php ist die Seite selbst,
 * sie einzubinden hiesse, sie ein zweites Mal zu rendern. Dasselbe Verfahren
 * wie bei by_reiter_lesen() daneben.
 *
 * null heisst "nicht gefunden" und ist etwas anderes als eine leere Liste.
 * Wer das gleichsetzt, meldet bei einer umbenannten Variablen "alle Marken
 * sind fremd" statt "hier ist nichts zu messen". */
function by_formularliste()
{
    $f = __DIR__ . '/index.php';
    if (!is_file($f)) {
        return null;
    }
    $t = (string) @file_get_contents($f);
    if (!preg_match('/\$by_formulare\s*=\s*array\((.*?)\);/s', $t, $m)) {
        return null;
    }
    preg_match_all("/'([a-z_]+)'/", $m[1], $x);
    return $x[1] ? $x[1] : null;
}

function by_pruefungen()
{
    $p = by_paths();
    $cfg = by_config();
    $z = by_zugang();
    $zeilen = array();

    /* ---- 1. Der eigene Endpunkt ----
     * Bewusst als ERSTE Zeile: die Ursache gehoert vor die Wirkung. Wer bei
     * "keine Fahrzeuge" anfaengt, schickt den Leser in die Grundeinrichtung,
     * obwohl der Endpunkt gar nicht antwortet. */
    $e = by_endpunkt_pruefen();
    /* Das Alter gehoert an ALLE DREI Ausgaenge und nicht nur an den mit dem
     * Haken. Der Kommentar bei by_endpunkt_pruefen() versprach es fuer jede
     * Antwort ("Das Alter der Antwort steht dabei"), gezeigt wurde es aber nur
     * im Erfolgsfall. Gerade beim Kreuz ist es die wichtigere Angabe: eine
     * Fehlermeldung, die aus dem Zwischenspeicher stammt, kann vierzehn
     * Minuten alt sein und laengst behoben. Wer das nicht sieht, sucht einen
     * Fehler, den es nicht mehr gibt. Bei einer frischen Messung ist das Alter
     * 0 - dann steht auch nichts da. */
    $alt_zusatz = ((int) $e['alter'] > 0)
        ? ' &mdash; ' . sprintf(by_t('TEST.A_ENDPUNKT_AUS_SPEICHER'), (int) $e['alter'])
        : '';
    if (!$e['da'] && $e['code'] === 0) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_ENDPUNKT'),
            by_t('TEST.A_ENDPUNKT_UNKLAR')
            . ($e['weg'] !== '' ? ' (' . $e['weg'] . ')' : '') . $alt_zusatz);
    } elseif ($e['code'] === 200 && strpos($e['rumpf'], 'SELFTEST;OK=1') !== false) {
        $zeilen[] = by_pruefzeile(1, by_t('TEST.F_ENDPUNKT'),
            sprintf(by_t('TEST.A_ENDPUNKT_OK'), (int) $e['alter']));
    } else {
        $zeilen[] = by_pruefzeile(0, by_t('TEST.F_ENDPUNKT'),
            sprintf(by_t('TEST.A_ENDPUNKT_FEHL'), (int) $e['code'],
                by_e($e['rumpf']) !== '' ? by_e($e['rumpf']) : by_t('TEST.A_LEER'))
            . $alt_zusatz);
    }

    /* ---- 2. Python und Bibliothek ---- */
    $venv = $p['bindir'] . '/venv/bin/python3';
    $zeilen[] = by_pruefzeile(is_file($venv) ? 1 : 0, by_t('TEST.F_VENV'),
        is_file($venv) ? by_e($venv) : by_t('TEST.A_VENV_FEHLT'));

    $pyv = by_python_fassung();
    $pyok = 0;
    if ($pyv !== '') {
        $teile = explode('.', $pyv);
        $pyok = ((int) $teile[0] > 3
                 || ((int) $teile[0] === 3 && isset($teile[1]) && (int) $teile[1] >= 11)) ? 1 : 0;
    }
    $zeilen[] = by_pruefzeile($pyv === '' ? 0 : $pyok, by_t('TEST.F_PYTHON'),
        $pyv !== '' ? by_e($pyv) . ($pyok ? '' : ' &mdash; ' . by_t('TEST.A_PYTHON_ZU_ALT'))
                    : by_t('TEST.A_PYTHON_UNBEKANNT'));

    $libv = by_bibliothek_fassung();
    $zeilen[] = by_pruefzeile($libv !== '' ? 1 : 0, by_t('TEST.F_LIB'),
        $libv !== '' ? 'pybyd ' . by_e($libv) : by_t('TEST.A_LIB_FEHLT'));

    /* ---- 3. Dienst ---- */
    $pid = by_dienst_pid();
    $zeilen[] = by_pruefzeile($pid > 0 ? 1 : 0, by_t('TEST.F_DIENST'),
        $pid > 0 ? by_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (by_dienst_soll() ? by_t('TEST.A_DIENST_SOLL_TOT')
                                     : by_t('TEST.A_DIENST_GESTOPPT')));

    /* ---- 4. Zugangsdaten ---- */
    $zeilen[] = by_pruefzeile($z['benutzer'] !== '' ? 1 : 0, by_t('TEST.F_KONTO'),
        $z['benutzer'] !== '' ? by_e($z['benutzer']) : by_t('TEST.A_KONTO_FEHLT'));
    $zeilen[] = by_pruefzeile($z['laenge'] > 0 ? 1 : 0, by_t('TEST.F_PASSWORT'),
        $z['laenge'] > 0 ? sprintf(by_t('TEST.A_PASSWORT_DA'), $z['laenge'])
                         : by_t('TEST.A_PASSWORT_FEHLT'));
    // Die Steuer-PIN ist nur fuer schreibende Befehle noetig. Ein Kreuz waere
    // hier ein Kreuz, das nichts bedeutet, solange die Steuerung aus ist.
    if (!empty($cfg['steuerung_ein'])) {
        $zeilen[] = by_pruefzeile($z['pin_laenge'] > 0 ? 1 : 0, by_t('TEST.F_PIN'),
            $z['pin_laenge'] > 0 ? sprintf(by_t('TEST.A_PIN_DA'), $z['pin_laenge'])
                                 : by_t('TEST.A_PIN_FEHLT'));
    } else {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_PIN'), by_t('TEST.A_PIN_UNNOETIG'));
    }

    $rechte = is_file($p['zugang']) ? (fileperms($p['zugang']) & 0777) : -1;
    $zeilen[] = by_pruefzeile(($rechte >= 0 && ($rechte & 0077) === 0) ? 1 : 0,
        by_t('TEST.F_RECHTE'),
        $rechte >= 0 ? '0' . decoct($rechte) : by_t('TEST.A_ZUGANGSDATEI_FEHLT'));

    /* ---- 5. Zweitschrift ---- */
    $zeilen[] = by_pruefzeile(is_file($p['sicherung']) ? 1 : -1, by_t('TEST.F_SICHERUNG'),
        is_file($p['sicherung']) ? by_e(basename($p['sicherung']))
                                 : by_t('TEST.A_SICHERUNG_FEHLT'));

    /* ---- 6. Abbild und Feldzuordnung ----
     * Jede Zeile, die ueber eine Menge urteilt, prueft zuerst, ob die Menge
     * leer ist. */
    $fahrzeuge = by_fahrzeuge();
    $zeilen[] = by_pruefzeile(count($fahrzeuge) > 0 ? 1 : 0, by_t('TEST.F_FAHRZEUGE'),
        count($fahrzeuge) > 0 ? sprintf(by_t('TEST.A_FAHRZEUGE'), count($fahrzeuge))
                              : by_t('TEST.A_KEINE_FAHRZEUGE'));

    $anzahl_felder = count(by_felder());
    if (!$fahrzeuge) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_ZUORDNUNG'),
            by_t('TEST.A_ZUORDNUNG_LEER'));
    } else {
        foreach ($fahrzeuge as $nr => $f) {
            /* FEHLT die Liste 'offen' ganz, ist das kein "nichts offen".
             * Vorher wurde sie zu einem leeren Feld gemacht, daraus ergab sich
             * "14 von 14 aufgeloest" und ein HAKEN - fuer ein Fahrzeug, ueber
             * dessen Feldzuordnung nichts bekannt ist. Genau so sieht ein Satz
             * aus, der aus einem Zwischenspeicher einer aelteren Fassung
             * stammt oder von Hand entstanden ist. Ein Haken ueber einer
             * unbekannten Menge ist schlimmer als kein Haken. */
            if (!isset($f['offen']) || !is_array($f['offen'])) {
                $zeilen[] = by_pruefzeile(-1,
                    sprintf(by_t('TEST.F_ZUORDNUNG_N'), by_e($nr)),
                    by_t('TEST.A_ZUORDNUNG_UNBEKANNT'));
                continue;
            }
            $offen = $f['offen'];
            $getroffen = $anzahl_felder - count($offen);
            $zeilen[] = by_pruefzeile(count($offen) === 0 ? 1 : -1,
                sprintf(by_t('TEST.F_ZUORDNUNG_N'), by_e($nr)),
                sprintf(by_t('TEST.A_ZUORDNUNG'), $getroffen, $anzahl_felder)
                . (count($offen) ? ' &mdash; ' . by_e(implode(', ', $offen)) : ''));
        }
    }

    /* ---- 7. Herkunft der Felder ----
     * Ein Feld, das niemand gemessen hat, darf nicht aussehen wie eines, das
     * jemand gemessen hat. Diese Zeile ist bewusst ein HINWEIS und nie ein
     * Kreuz: dass die Feldnamen aus einer offenen Quelle stammen, ist kein
     * Defekt des Plugins - es ist eine Eigenschaft dieser Fassung. */
    $nach_quelle = array();
    foreach (by_felder() as $eig) {
        $q = (string) $eig['quelle'];
        $nach_quelle[$q] = (isset($nach_quelle[$q]) ? $nach_quelle[$q] : 0) + 1;
    }
    $hol = function ($k) use ($nach_quelle) {
        return isset($nach_quelle[$k]) ? $nach_quelle[$k] : 0;
    };
    // Gezaehlt wird nach der Liste, nicht nach zwei erwarteten Namen: eine
    // Herkunft, die niemand vorgesehen hat, wuerde sonst still in einer der
    // beiden Zahlen verschwinden.
    $rest = array();
    foreach ($nach_quelle as $q => $n) {
        if ($q !== 'doku' && $q !== 'bestand' && $q !== 'gerechnet') {
            $rest[] = $q . ': ' . $n;
        }
    }
    $zeilen[] = by_pruefzeile($rest ? 0 : -1, by_t('TEST.F_HERKUNFT'),
        $rest
            ? sprintf(by_t('TEST.A_HERKUNFT_UNBEKANNT'), by_e(implode(', ', $rest)))
            : sprintf(by_t('TEST.A_HERKUNFT'), $hol('bestand'), $hol('doku'),
                      $hol('gerechnet')));

    /* ---- 8. Alter des Abbilds ---- */
    $alter = by_alter();
    if ($alter < 0) {
        $zeilen[] = by_pruefzeile(0, by_t('TEST.F_ABRUF'), by_t('TEST.A_NIE_ABGERUFEN'));
    } else {
        $frisch = $alter <= max(600, 3 * (int) $cfg['intervall']);
        $zeilen[] = by_pruefzeile($frisch ? 1 : 0, by_t('TEST.F_ABRUF'),
            sprintf(by_t('TEST.A_ABRUF_ALTER'), $alter));
    }

    $zu = by_zustand();
    if (!empty($zu['fehler'])) {
        $zeilen[] = by_pruefzeile(0, by_t('TEST.F_LETZTER_FEHLER'), by_e($zu['fehler']));
    }

    /* ---- 9. MQTT ----
     * Ein Kreuz nur dann, wenn MQTT auch benutzt werden SOLL. Steht der Haken
     * aus und holt der Anwender seine Werte ueber den HTTP-Endpunkt, ist der
     * Zustand des Gateways kein Mangel dieses Plugins. */
    $m = by_mqtt_zustand();
    if (empty($cfg['mqtt_ein'])) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_MQTT'), by_t('TEST.A_MQTT_UNBENUTZT'));
    } elseif (!$m['gefunden']) {
        $zeilen[] = by_pruefzeile(0, by_t('TEST.F_MQTT'), by_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = by_pruefzeile(1, by_t('TEST.F_MQTT'),
            by_e($m['broker']) . ':' . by_e($m['brokerport'])
            . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = by_pruefzeile(0, by_t('TEST.F_MQTT'), by_t('TEST.A_MQTT_AUS'));
    }

    /* ---- 10. Vorgaben in Dienst und Oberflaeche ---- */
    $vd = by_vorgaben_dienst();
    if ($vd === null) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_VORGABEN'), by_t('TEST.A_VORGABEN_UNKLAR'));
    } else {
        $eigen = array_keys(by_vorgaben());
        $fehlt = array_diff($vd, $eigen);
        $zeilen[] = by_pruefzeile(count($fehlt) === 0 ? 1 : 0, by_t('TEST.F_VORGABEN'),
            count($fehlt) === 0
                ? sprintf(by_t('TEST.A_VORGABEN_OK'), count($vd))
                : sprintf(by_t('TEST.A_VORGABEN_FEHLT'), by_e(implode(', ', $fehlt))));
    }

    /* ---- 11. Reiterleiste, Bereiche und Positivliste ---- */
    $r = by_reiter_lesen();
    if ($r === null || !$r['liste']) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_REITER'), by_t('TEST.A_REITER_UNKLAR'));
    } else {
        $gleich = ($r['liste'] === $r['leiste'] && $r['liste'] === $r['bereiche']);
        $zeilen[] = by_pruefzeile($gleich ? 1 : 0, by_t('TEST.F_REITER'),
            $gleich ? sprintf(by_t('TEST.A_REITER_OK'), count($r['liste']))
                    : sprintf(by_t('TEST.A_REITER_FEHL'), count($r['liste']),
                              count($r['leiste']), count($r['bereiche'])));
    }

    /* ---- 12. Eindeutigkeit der Suchmuster ----
     * Loxone sucht woertlich und nimmt den ersten Treffer. Ohne das Semikolon
     * im Muster steckte "KM=" auch in einem kuenftigen "INSPKM=". Geprueft
     * wird deshalb, dass kein Muster in der erzeugten Statuszeile mehr als
     * einmal vorkommt - und zwar an der WIRKLICHEN Zeile, nicht an der
     * Feldliste. Wer die Muster gegen sich selbst prueft, prueft nichts. */
    $zeile = by_statuszeile(array(), 0, 0);
    $doppelt = array();
    foreach (array_keys(by_felder_zeile()) as $feld) {
        if (substr_count($zeile, ';' . $feld . '=') !== 1) {
            $doppelt[] = $feld;
        }
    }
    $zeilen[] = by_pruefzeile(count($doppelt) === 0 ? 1 : 0, by_t('TEST.F_MUSTER'),
        count($doppelt) === 0
            ? sprintf(by_t('TEST.A_MUSTER_OK'), count(by_felder_zeile()))
            : sprintf(by_t('TEST.A_MUSTER_FEHL'), by_e(implode(', ', $doppelt))));

    /* ---- 13. Statuszeile, MQTT-Themen und Vorlage aus EINER Quelle ----
     * In einem Schwesterplugin legte die Vorlage 20 virtuelle Eingaenge an,
     * die Zeile lieferte 17 und MQTT 15 - und die drei fehlenden waren
     * ausgerechnet die gerechneten Groessen. Der Anwender importiert dann eine
     * Reichweite, die dauerhaft auf 0 steht, und sucht den Fehler bei seinem
     * Auto. Geprueft wird an den ERZEUGTEN Stuecken. */
    $inzeile = array_keys(by_felder_zeile());
    $themen = array();
    foreach (array_keys(by_mqtt_themen()) as $t) {
        if (strpos($t, 'fahrzeugN/') === 0) {
            $themen[] = substr($t, strlen('fahrzeugN/'));
        }
    }
    // OK und ALTER stehen ueber MQTT als eigene Themen (ok, ts) - sie gehoeren
    // hier nicht in den Vergleich.
    $inzeile_ohne = array_values(array_diff($inzeile, array('OK', 'ALTER')));
    $fehlt_mqtt = array_diff($inzeile_ohne, $themen);
    $zeilen[] = by_pruefzeile(count($fehlt_mqtt) === 0 ? 1 : 0, by_t('TEST.F_WEGE'),
        count($fehlt_mqtt) === 0
            ? sprintf(by_t('TEST.A_WEGE_OK'), count($inzeile), count($themen))
            : sprintf(by_t('TEST.A_WEGE_FEHL'), by_e(implode(', ', $fehlt_mqtt))));

    /* ---- 14. Jeder Feldname hat einen Satz ----
     * Die Oberflaeche gibt bei einem fehlenden Schluessel den Schluessel selbst
     * aus - dann stuende in der Tabelle buchstaeblich BY_FELD.SOC. Die
     * Sprachdateien wachsen an einer anderen Stelle als der Code. */
    $ohne = array();
    foreach (by_felder() as $name => $eig) {
        if (by_t($eig['bez']) === $eig['bez']) {
            $ohne[] = $name;
        }
    }
    foreach (by_befehle() as $aktion => $eig) {
        if (by_t($eig['bez']) === $eig['bez']) {
            $ohne[] = $aktion;
        }
    }
    $zeilen[] = by_pruefzeile(count($ohne) === 0 ? 1 : 0, by_t('TEST.F_TEXTE'),
        count($ohne) === 0
            ? sprintf(by_t('TEST.A_TEXTE_OK'), count(by_felder()) + count(by_befehle()))
            : sprintf(by_t('TEST.A_TEXTE_FEHL'), by_e(implode(', ', $ohne))));

    /* ---- 15. Auswahlfelder sind als solche erkennbar ----
     * Die Rahmen-CSS des LoxBerry (jQuery Mobile) setzt fuer Auswahlfelder
     * appearance:none, und damit verschwindet der Pfeil, den sonst der Browser
     * zeichnet. Zweimal hat ein Anwender gemeldet, ein Auswahlfeld sehe aus
     * wie ein Textfeld - kein Werkzeug dieser Kette sieht das, denn rendern.py
     * sieht HTML, kein Bild.
     *
     * Pruefbar ist die halbe Frage: dass die Seite den Pfeil selbst mitbringt
     * und dass jedes <select> ein Merkmal im Quelltext traegt. */
    $selbst = (string) @file_get_contents(__DIR__ . '/index.php');
    $hat_css = (strpos($selbst, 'appearance: none') !== false
                && strpos($selbst, 'background-image: url("data:image/svg+xml') !== false);
    /* OHNE die Kommentare zaehlen. Genau daran ist diese Pruefung selbst
     * gescheitert: in index.php erklaert ein CSS-Kommentar, warum die Klasse
     * sm-auswahl noetig ist, und schreibt dabei "<select data-role=none>" hin.
     * Gezaehlt wurden damit 3 Auswahlfelder und 2 Merkmale - der Reiter Test
     * setzte ein Kreuz, obwohl beide wirklichen Felder in Ordnung waren.
     * Ein Werkzeug, das an der Erklaerung seiner selbst scheitert, meldet
     * einen Fehler, den es nicht gibt, und verdeckt den Tag, an dem es einen
     * gibt. Entfernt werden Block- und HTML-Kommentare; Zeilenkommentare
     * NICHT, denn ein // steckt auch in jeder URL. */
    $ohne = preg_replace('#/\*.*?\*/#s', '', $selbst);
    $ohne = preg_replace('#<!--.*?-->#s', '', (string) $ohne);
    $ohne = (string) $ohne;
    $anzahl_select = preg_match_all('/<select/', $ohne);
    $anzahl_merkmal = preg_match_all('/<select[^>]*class="[^"]*sm-auswahl/', $ohne);
    $ok_sel = $hat_css && $anzahl_select === $anzahl_merkmal;
    $zeilen[] = by_pruefzeile($anzahl_select === 0 ? -1 : ($ok_sel ? 1 : 0),
        by_t('TEST.F_AUSWAHL'),
        $anzahl_select === 0 ? by_t('TEST.A_AUSWAHL_KEINE')
            : sprintf(by_t('TEST.A_AUSWAHL'), $anzahl_merkmal, $anzahl_select,
                      $hat_css ? by_t('ALLG.JA') : by_t('ALLG.NEIN')));

    /* ---- 16. Doppelte Maskierung ----
     * Ein Wert, der durch by_e() laeuft und selbst eine Entitaet oder
     * Auszeichnung enthaelt, erscheint im Browser woertlich als "l&auml;uft".
     * Das ist der Befund mit 40 Fundstellen in 13 Plugins. Er ist beim
     * Einlesen unsichtbar und faellt erst am Bildschirm auf. */
    preg_match_all("/by_e\(\s*(?:sprintf\(\s*)?by_t\('([A-Z0-9_]+\.[A-Z0-9_]+)'\)/",
        $selbst, $mm);
    $schlecht = array();
    foreach (array_unique($mm[1]) as $s) {
        $w = by_t($s);
        if ($w !== $s && (strpos($w, '&') !== false || strpos($w, '<') !== false)) {
            $schlecht[] = $s;
        }
    }
    $zeilen[] = by_pruefzeile(count($schlecht) === 0 ? 1 : 0, by_t('TEST.F_MASKE'),
        count($schlecht) === 0
            ? sprintf(by_t('TEST.A_MASKE_OK'), count(array_unique($mm[1])))
            : sprintf(by_t('TEST.A_MASKE_FEHL'), by_e(implode(', ', $schlecht))));

    /* ---- 17. Jedes Formular traegt seine Marke ----
     * DIESE ZEILE HAETTE EINEN BEFUND GEFUNDEN, den keine andere fand.
     *
     * Der Beitrag prueft jedes abgeschickte Formular gegen eine Positivliste
     * ($by_formulare). Ein Formular, dessen Marke dort NICHT steht, wird
     * abgewiesen - lautlos, denn abgewiesen heisst: die Seite laedt neu und
     * sieht aus wie vorher. Genau das war bis 0.9.5 mit den beiden Knoepfen
     * zum Sichern und Zuruecksetzen der Einstellungen los: sie trugen gar
     * keine Marke, die Positivliste kannte sie nicht, und ein Klick tat
     * nichts. Kein Werkzeug sah es, weil beide Seiten fuer sich in Ordnung
     * waren - das Formular gab es, den Verarbeiter auch, nur zusammen kamen
     * sie nie.
     *
     * Zwei Fragen, und beide muessen stimmen:
     *   a) traegt JEDES <form> eine Marke?
     *   b) steht JEDE Marke in der Positivliste?
     *
     * Gezaehlt wird ohne Kommentare - aus demselben Grund wie bei den
     * Auswahlfeldern eine Pruefung weiter oben. */
    $anz_form = preg_match_all('/<form\b/', $ohne);
    preg_match_all('/name="formular"\s+value="([a-z_]+)"/', $ohne, $mf);
    $marken = array_values(array_unique($mf[1]));
    $liste = by_formularliste();
    $fremd = ($liste === null) ? array() : array_values(array_diff($marken, $liste));
    if ($liste === null) {
        // Ohne die Positivliste laesst sich (b) nicht beantworten. Dann wird
        // auch (a) nicht als Haken verkauft.
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_FORMULARE'),
            by_t('TEST.A_FORMULARE_UNKLAR'));
    } elseif ($anz_form === 0) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_FORMULARE'),
            by_t('TEST.A_FORMULARE_KEINE'));
    } else {
        $anz_marke = count($mf[1]);
        $gut = ($anz_form === $anz_marke) && !$fremd;
        $zeilen[] = by_pruefzeile($gut ? 1 : 0, by_t('TEST.F_FORMULARE'),
            $gut ? sprintf(by_t('TEST.A_FORMULARE_OK'), $anz_form, count($liste))
                 : sprintf(by_t('TEST.A_FORMULARE_FEHL'), $anz_marke, $anz_form,
                           $fremd ? by_e(implode(', ', $fremd)) : by_t('TEST.A_LEER')));
    }

    /* ---- 25. Traegt jeder Reiter die Bedingung fuer sm-active? ----
     * WELCHER REITER OFFEN IST, ENTSCHEIDET DER SERVER. Jeder Anker in der
     * Leiste und jeder Bereich bekommt die Klasse sm-active genau dann, wenn
     * $by_tab auf ihn zeigt - ohne diese Bedingung bleibt der Bereich auf
     * display:none stehen und ist ueber die Leiste NICHT ERREICHBAR.
     *
     * Pruefung 11 vergleicht die NAMEN von Liste, Leiste und Bereichen. Das
     * findet einen vergessenen Reiter, aber nicht einen, der zwar ueberall
     * genannt ist und dem nur die Bedingung fehlt. Der waere unsichtbar, und
     * zwar ohne jede Fehlermeldung. */
    $anker = preg_match_all('/data-ziel="tab-[a-z]+"/', $ohne);
    $bereiche = preg_match_all('/id="tab-[a-z]+"/', $ohne);
    /* EINFACHE Anfuehrungszeichen. Mit doppelten interpoliert PHP das
     * $by_tab im Muster - unter 8.4 gemessen: "Warning: Undefined variable
     * $by_tab". Das Muster misst dann etwas anderes, als dort steht, und
     * trifft trotzdem, weil der Rest allein schon passt. Ein Ausdruck, der aus
     * dem falschen Grund die richtige Zahl liefert, ist die unangenehmste
     * Sorte: er faellt erst auf, wenn sich der Quelltext aendert. */
    $bedingt = preg_match_all('/\$by_tab === \'tab-[a-z]+\' \? \' sm-active\'/', $ohne);
    if ($anker === 0 && $bereiche === 0) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_AKTIV'), by_t('TEST.A_AKTIV_KEINE'));
    } else {
        // Jeder Anker UND jeder Bereich braucht die Bedingung.
        $soll = $anker + $bereiche;
        $zeilen[] = by_pruefzeile($bedingt === $soll ? 1 : 0, by_t('TEST.F_AKTIV'),
            $bedingt === $soll
                ? sprintf(by_t('TEST.A_AKTIV_OK'), $anker, $bereiche)
                : sprintf(by_t('TEST.A_AKTIV_FEHL'), $bedingt, $soll));
    }

    /* ---- 26. Fuehrt die gespeicherte Konfiguration jeden Schluessel? ----
     * by_config() ergaenzt fehlende Schluessel aus den Vorgaben - im Speicher.
     * Die DATEI kann trotzdem unvollstaendig sein, etwa nach einem
     * Zurueckspielen aus einer aelteren Sicherung. Sichtbar wird das nie: die
     * Oberflaeche zeigt den Vorgabewert, und wer ihn nicht anfasst, speichert
     * ihn auch nicht. Beim naechsten Zurueckspielen fehlt er wieder.
     *
     * Gelesen wird die Datei selbst und nicht by_config(). Wer die ergaenzte
     * Fassung prueft, prueft die Ergaenzung. */
    $roh = is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
    $gespeichert = ($roh === '') ? null : json_decode($roh, true);
    if (!is_array($gespeichert)) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_VOLLSTAENDIG'),
            by_t('TEST.A_VOLLSTAENDIG_KEINE'));
    } else {
        $fehlend = array_keys(array_diff_key(by_vorgaben(), $gespeichert));
        $zeilen[] = by_pruefzeile(count($fehlend) === 0 ? 1 : -1,
            by_t('TEST.F_VOLLSTAENDIG'),
            count($fehlend) === 0
                ? sprintf(by_t('TEST.A_VOLLSTAENDIG_OK'), count($gespeichert))
                : sprintf(by_t('TEST.A_VOLLSTAENDIG_FEHLT'),
                          by_e(implode(', ', $fehlend))));
    }

    /* ---- 24. Eine Legende je Reiter, und sie nennt die richtigen Farben ----
     * REGELN_2, "Farblegende der Knoepfe": EINE gesammelte Legende oben im
     * Reiter, nicht je Knopfreihe eine eigene - "dieselbe Zeile drei Mal
     * untereinander stiftet mehr Unruhe als Nutzen". Und sie nennt genau die
     * Farben, die in DIESEM Reiter vorkommen; Farben, die es dort nicht gibt,
     * bleiben weg.
     *
     * Bis 0.9.5 fuehrte der Reiter Einstellungen VIER Legenden und der Reiter
     * Loxone zwei - jede ueber ihrer eigenen Knopfreihe. Kein Werkzeug sah es:
     * die Hausstandard-Pruefung fragt, OB eine Legende da ist, nicht wie viele.
     *
     * Zwei Fragen, und beide muessen stimmen:
     *   a) genau eine Legende in jedem Reiter, der Knoepfe fuehrt
     *   b) ihre Farben decken sich mit denen der Knoepfe - in BEIDE
     *      Richtungen: keine unerklaerte Farbe, keine erklaerte, die fehlt */
    $reiter = preg_split('/<div class="sm-seite/', $ohne);
    $schlecht_anz = array();
    $schlecht_farb = array();
    $gezaehlt = 0;
    foreach (array_slice($reiter, 1) as $stueck) {
        if (!preg_match('/id="(tab-[a-z]+)"/', $stueck, $mid)) {
            continue;
        }
        $name = $mid[1];
        preg_match_all('/sm-btn (sm-b-[a-z]+)/', $stueck, $mk);
        $knopffarben = array_unique($mk[1]);
        if (!$knopffarben) {
            continue;      // ein Reiter ohne Knoepfe braucht keine Legende
        }
        $gezaehlt++;
        $anz = preg_match_all('/class="sm-legende"/', $stueck);
        if ($anz !== 1) {
            $schlecht_anz[] = $name . ' (' . $anz . ')';
            continue;      // ueber die Farben ist dann nichts zu sagen
        }
        preg_match_all('/sm-punkt (sm-b-[a-z]+)/', $stueck, $ml);
        $legendenfarben = array_unique($ml[1]);
        sort($knopffarben);
        sort($legendenfarben);
        if ($knopffarben !== $legendenfarben) {
            $schlecht_farb[] = $name;
        }
    }
    if ($gezaehlt === 0) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_LEGENDEN'),
            by_t('TEST.A_LEGENDEN_KEINE'));
    } else {
        $gut = !$schlecht_anz && !$schlecht_farb;
        $zeilen[] = by_pruefzeile($gut ? 1 : 0, by_t('TEST.F_LEGENDEN'),
            $gut ? sprintf(by_t('TEST.A_LEGENDEN_OK'), $gezaehlt)
                 : sprintf(by_t('TEST.A_LEGENDEN_FEHL'),
                           by_e(implode(', ', $schlecht_anz) ?: by_t('TEST.A_LEER')),
                           by_e(implode(', ', $schlecht_farb) ?: by_t('TEST.A_LEER'))));
    }

    /* ---- 18. Mithoeren am Broker ----
     * Zwei Funktionen brauchen FREMDE Themen. Verglichen wird die Erwartung
     * der Oberflaeche (by_horcher_themen) mit dem, was der Dienst wirklich
     * abonniert hat. Der Fehler, den nur dieser Vergleich findet: nach einer
     * Aenderung der Einstellungen zieht der Dienst das Abo nicht nach. Dann
     * loest die Vorklimatisierung nie aus, und das sieht von aussen so aus,
     * als sende der Abfahrtsassistent nichts. */
    $soll_themen = by_horcher_themen($cfg);
    $ho = by_horcher_zustand();
    if (!$soll_themen) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_HORCHER'),
            by_t('TEST.A_HORCHER_UNNOETIG'));
    } elseif ($ho['fehler'] !== '') {
        $zeilen[] = by_pruefzeile(0, by_t('TEST.F_HORCHER'),
            sprintf(by_t('TEST.A_HORCHER_FEHLER'), by_e($ho['fehler'])));
    } elseif (by_dienst_pid() === 0) {
        // Kein Kreuz: ohne laufenden Dienst gibt es nichts zu vergleichen.
        // Ein Kreuz hier bedeutete "das Abo fehlt", und das waere falsch.
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_HORCHER'),
            sprintf(by_t('TEST.A_HORCHER_KEIN_DIENST'), count($soll_themen)));
    } else {
        $ist = $ho['themen'];
        sort($ist);
        $fehlt = array_values(array_diff($soll_themen, $ist));
        $zuviel = array_values(array_diff($ist, $soll_themen));
        if ($fehlt || $zuviel) {
            $zeilen[] = by_pruefzeile(0, by_t('TEST.F_HORCHER'),
                sprintf(by_t('TEST.A_HORCHER_ABWEICHUNG'),
                    by_e(implode(', ', $fehlt) ?: '-'),
                    by_e(implode(', ', $zuviel) ?: '-')));
        } else {
            $zeilen[] = by_pruefzeile($ho['verbunden'] ? 1 : 0, by_t('TEST.F_HORCHER'),
                $ho['verbunden']
                    ? sprintf(by_t('TEST.A_HORCHER_OK'), count($soll_themen),
                              by_e(implode(', ', $soll_themen)))
                    : by_t('TEST.A_HORCHER_GETRENNT'));
        }
    }

    /* ---- 19. Hoert das Plugin auf sich selbst? ----
     * Ein fremdes Thema, das unter dem EIGENEN Themenpfad liegt, macht aus
     * dem Plugin seinen eigenen Zulieferer. Die Ladeempfehlung rechnete dann
     * mit einem Wert, den sie selbst gesendet hat, und die Vorklimatisierung
     * wartete auf eine Abfahrt, die niemand ankuendigt. Beides bleibt
     * dauerhaft still - der teuerste Befund ist der, der nichts tut. */
    $eigen = trim((string) $cfg['mqtt_topic'], '/');
    $selbstbezug = array();
    foreach ($soll_themen as $t) {
        if ($eigen !== '' && ($t === $eigen || strpos($t, $eigen . '/') === 0)) {
            $selbstbezug[] = $t;
        }
    }
    if (!$soll_themen) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_SELBSTBEZUG'),
            by_t('TEST.A_SELBSTBEZUG_UNNOETIG'));
    } else {
        $zeilen[] = by_pruefzeile(count($selbstbezug) === 0 ? 1 : 0,
            by_t('TEST.F_SELBSTBEZUG'),
            count($selbstbezug) === 0
                ? sprintf(by_t('TEST.A_SELBSTBEZUG_OK'), by_e($eigen))
                : sprintf(by_t('TEST.A_SELBSTBEZUG_FEHL'),
                          by_e(implode(', ', $selbstbezug)), by_e($eigen)));
    }

    /* ---- 20. Kann die Vorklimatisierung ueberhaupt schalten? ----
     * Sie ist die einzige Funktion, die von SELBST einen schreibenden Befehl
     * absetzt. Ist die Steuerung gesperrt, ist sie eingeschaltet und wirkungs-
     * los - eine eingeschaltete Funktion, die nichts tut, ist schlimmer als
     * eine ausgeschaltete, weil niemand nachsieht. */
    if (empty($cfg['abfahrt_ein'])) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_ABFAHRT'), by_t('TEST.A_ABFAHRT_AUS'));
    } elseif (empty($cfg['steuerung_ein'])) {
        $zeilen[] = by_pruefzeile(0, by_t('TEST.F_ABFAHRT'), by_t('TEST.A_ABFAHRT_GESPERRT'));
    } elseif ((int) $cfg['abfahrt_temp'] < (int) $cfg['temp_min']
              || (int) $cfg['abfahrt_temp'] > (int) $cfg['temp_max']) {
        $zeilen[] = by_pruefzeile(0, by_t('TEST.F_ABFAHRT'),
            sprintf(by_t('TEST.A_ABFAHRT_TEMP'), (int) $cfg['abfahrt_temp'],
                    (int) $cfg['temp_min'], (int) $cfg['temp_max']));
    } else {
        $zeilen[] = by_pruefzeile(1, by_t('TEST.F_ABFAHRT'),
            sprintf(by_t('TEST.A_ABFAHRT_OK'), (int) $cfg['abfahrt_vorlauf'],
                    (int) $cfg['abfahrt_temp'], (int) $cfg['abfahrt_fahrzeug']));
    }

    /* ---- 21. Die Zutaten der gerechneten Groessen ----
     * Ohne Kapazitaet bleiben VERBRAUCH und LADEKWH leer, ohne Heimatposition
     * bleibt ZUHAUSE leer. Das ist erlaubt und wird als Hinweis gefuehrt -
     * aber es wird GESAGT, damit niemand die leere Spalte fuer eine Stoerung
     * haelt. Eine halbe Heimatposition ist dagegen ein Kreuz: dort ist etwas
     * eingetragen, das nicht wirkt. */
    $kap = (int) $cfg['kapazitaet'];
    $hb = trim((string) $cfg['heim_breite']);
    $hl = trim((string) $cfg['heim_laenge']);
    if (($hb === '') !== ($hl === '')) {
        $zeilen[] = by_pruefzeile(0, by_t('TEST.F_ZUTATEN'), by_t('TEST.A_ZUTATEN_HALB'));
    } elseif ($kap > 0 && $hb !== '') {
        $zeilen[] = by_pruefzeile(1, by_t('TEST.F_ZUTATEN'),
            sprintf(by_t('TEST.A_ZUTATEN_OK'), $kap, (int) $cfg['heim_radius']));
    } else {
        $offen = array();
        if ($kap <= 0) { $offen[] = by_t('TEST.Z_KAPAZITAET'); }
        if ($hb === '') { $offen[] = by_t('TEST.Z_HEIM'); }
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_ZUTATEN'),
            sprintf(by_t('TEST.A_ZUTATEN_OFFEN'), by_e(implode(', ', $offen))));
    }

    /* ---- 22. Die Liste der Ladevorgaenge ----
     * Geprueft wird nicht "gibt es die Datei", sondern "laesst sie sich
     * lesen". Eine vorhandene Datei, aus der keine einzige Zeile herausfaellt,
     * ist der Befund - der Reiter zeigte dann eine leere Liste und behauptete
     * damit, es habe nie geladen. */
    $lad_datei = $p['datadir'] . '/verlauf/ladungen.csv';
    if (!is_file($lad_datei)) {
        $zeilen[] = by_pruefzeile(-1, by_t('TEST.F_LADUNGEN'), by_t('TEST.A_LADUNGEN_KEINE'));
    } else {
        $lad = by_ladungen_lesen(5000);
        $roh = 0;
        $fh = @fopen($lad_datei, 'r');
        if ($fh) {
            while (fgets($fh) !== false) { $roh++; }
            fclose($fh);
        }
        $roh = max(0, $roh - 1);            // die Kopfzeile zaehlt nicht mit
        $zeilen[] = by_pruefzeile(($roh === 0 || count($lad) > 0) ? 1 : 0,
            by_t('TEST.F_LADUNGEN'),
            ($roh === 0 || count($lad) > 0)
                ? sprintf(by_t('TEST.A_LADUNGEN_OK'), count($lad), $roh)
                : sprintf(by_t('TEST.A_LADUNGEN_UNLESBAR'), $roh));
    }

    /* ---- 23. Steuerung ---- */
    $zeilen[] = by_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1,
        by_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? by_t('TEST.A_STEUERUNG_EIN')
                                      : by_t('TEST.A_STEUERUNG_AUS'));

    return $zeilen;
}

/**
 * Zaehlt die Zeilen der Selbstpruefung zusammen.
 *
 * Eine Zusammenfassung darf nicht besser aussehen als ihr schlechtester Punkt.
 * Hinweise werden deshalb GETRENNT gezaehlt und nicht als bestanden verbucht -
 * "22 von 22 bestanden" entstand in einem Schwesterplugin genau dadurch, dass
 * jede unklare Lage als Hinweis statt als Kreuz zaehlte.
 */
function by_pruefbilanz($zeilen)
{
    $ja = 0; $nein = 0; $hinweis = 0;
    foreach ($zeilen as $z) {
        if ($z['stand'] === 1) { $ja++; }
        elseif ($z['stand'] === 0) { $nein++; }
        else { $hinweis++; }
    }
    return array('ja' => $ja, 'nein' => $nein, 'hinweis' => $hinweis,
                 'summe' => $ja + $nein);
}

/**
 * Fuehrt eine Aktion des Reiters Test aus.
 * Rueckgabe: array(stand, Meldung) - stand wie bei by_befehl_absetzen.
 */
function by_test_aktion($aktion)
{
    $cfg = by_config();
    $nr = isset($_POST['test_fahrzeug']) ? (string) $_POST['test_fahrzeug'] : '1';
    if (!preg_match('/^[0-9]{1,2}$/', $nr)) {
        return array(0, by_t('TEST.M_FAHRZEUG_UNGUELTIG'));
    }
    /* Der Trockenlauf ist EIN Haken an dieser Reihe und kein zweiter Knopf
       neben jedem Befehl: sonst gibt es zu jedem Befehl zwei Knoepfe, die
       gleich aussehen, und der falsche ist einen Fingerbreit vom richtigen
       entfernt. Der Haken reist mit dem Auftrag mit; der Dienst geht damit
       denselben Weg und laesst nur das Senden weg. */
    $probe = !empty($_POST['test_probe']);

    if ($aktion === 'endpunkt') {
        $e = by_endpunkt_pruefen(true);
        if (!$e['da'] && $e['code'] === 0) {
            return array(2, by_t('TEST.A_ENDPUNKT_UNKLAR'));
        }
        return array($e['code'] === 200 ? 1 : 0,
            sprintf('HTTP %d: %s', (int) $e['code'], $e['rumpf']));
    }
    if ($aktion === 'felder') {
        /* Der RUECKGABECODE entscheidet, nicht die Tatsache, dass ein Aufruf
         * stattgefunden hat. Vorher stand hier eine fest verdrahtete 1: fehlte
         * die virtuelle Python-Umgebung oder war exec() gesperrt, meldete die
         * Oberflaeche "gelungen" und zeigte darunter die Fehlermeldung an.
         * Ein gruener Haken ueber einem [FEHL] ist schlimmer als gar keiner. */
        $rc = 1;
        $txt = by_python_ruf('--felder', $rc);
        return array($rc === 0 ? 1 : 0,
            $txt !== '' ? $txt : by_t('TEST.M_FELDER_STUMM'));
    }
    if ($aktion === 'abruf') {
        $b = array('aktion' => 'abruf');
        if ($probe) { $b['probe'] = 1; }
        return by_befehl_absetzen($b, 10);
    }

    $befehle = by_befehle();
    if (!isset($befehle[$aktion])) {
        return array(0, by_t('TEST.M_UNBEKANNT'));
    }
    $b = array('aktion' => $aktion, 'fahrzeug' => $nr);
    if ($probe) { $b['probe'] = 1; }
    if ($befehle[$aktion]['zusatz'] === 'temp') {
        $temp = isset($_POST['test_temp'])
            ? str_replace(',', '.', (string) $_POST['test_temp']) : '';
        if (!preg_match('/^[0-9]{1,2}(\.[05])?$/', $temp)) {
            return array(0, by_t('TEST.M_TEMP_UNGUELTIG'));
        }
        $b['temp'] = $temp;
        $min = isset($_POST['test_minuten']) ? (string) $_POST['test_minuten'] : '';
        if ($min !== '' && preg_match('/^[0-9]{1,2}$/', $min)) {
            $b['minuten'] = (int) $min;
        }
    } elseif ($befehle[$aktion]['zusatz'] === 'stufe') {
        $st = isset($_POST['test_stufe']) ? (string) $_POST['test_stufe'] : '';
        if (!preg_match('/^[0-9]{1,2}$/', $st)) {
            return array(0, by_t('TEST.M_STUFE_UNGUELTIG'));
        }
        $b['stufe'] = (int) $st;
    }
    return by_befehl_absetzen($b);
}

/** Mini-SVG: Ladezustand ueber den heutigen Tag (0 bis 24 h, 0 bis 100 %). */
function by_soc_svg($punkte)
{
    $w = 720; $h = 120; $x0 = 34; $y0 = 8; $pw = $w - $x0 - 8; $ph = $h - $y0 - 20;
    $tag0 = strtotime('today 00:00');
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w
         . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;"'
         . ' xmlns="http://www.w3.org/2000/svg">';
    foreach (array(0, 25, 50, 75, 100) as $pct) {
        $y = $y0 + $ph - $ph * $pct / 100;
        $svg .= '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . ($x0 + $pw) . '" y2="' . $y
              . '" stroke="#e5e5e5" stroke-width="1"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . ($y + 3)
              . '" font-size="9" fill="#999" text-anchor="end">' . $pct . '</text>';
    }
    foreach (array(0, 6, 12, 18, 24) as $hh) {
        $x = $x0 + $pw * $hh / 24;
        $svg .= '<line x1="' . $x . '" y1="' . $y0 . '" x2="' . $x . '" y2="' . ($y0 + $ph)
              . '" stroke="#eeeeee" stroke-width="1"/>';
        $svg .= '<text x="' . $x . '" y="' . ($h - 6)
              . '" font-size="9" fill="#999" text-anchor="middle">' . $hh . ':00</text>';
    }
    $poly = array();
    foreach ($punkte as $pt) {
        $anteil = ($pt[0] - $tag0) / 86400;
        if ($anteil < 0 || $anteil > 1) {
            continue;
        }
        $poly[] = round($x0 + $pw * $anteil, 1) . ','
                . round($y0 + $ph - $ph * max(0, min(100, $pt[1])) / 100, 1);
    }
    if (count($poly) >= 2) {
        $erst = explode(',', $poly[0]);
        $letzt = explode(',', $poly[count($poly) - 1]);
        $svg .= '<polygon points="' . $erst[0] . ',' . ($y0 + $ph) . ' '
              . implode(' ', $poly) . ' ' . $letzt[0] . ',' . ($y0 + $ph)
              . '" fill="#6dac20" opacity="0.15"/>';
        $svg .= '<polyline points="' . implode(' ', $poly)
              . '" fill="none" stroke="#6dac20" stroke-width="2"/>';
        $svg .= '<circle cx="' . $letzt[0] . '" cy="' . $letzt[1] . '" r="3" fill="#6dac20"/>';
    } else {
        $svg .= '<text x="' . ($x0 + $pw / 2) . '" y="' . ($y0 + $ph / 2)
              . '" font-size="11" fill="#aaa" text-anchor="middle">'
              . by_e(by_t('TEST.KEINE_MESSPUNKTE')) . '</text>';
    }
    return $svg . '</svg>';
}
