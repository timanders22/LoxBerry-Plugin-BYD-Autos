<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie BYD Autos (Gruppe 42, 0.9.29).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php und .sh, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/, 1.0.2)
 * abonniert <mqtt_topic>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE
 * Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - by_befehl_annehmen() in by_lib.php, Quelle 'mqtt'
 * (Reihenfolge wie dort: Muster, Steuerung, zweiter Haken, Zusatzwert, Fahrzeug, X-7-Bremse, Dienst) - und gibt
 * array(ok, grund) fuer die Quittung zurueck (by_befehl_quittung()). Ein Token gibt es auf diesem Weg nicht; an
 * seiner Stelle stehen die Haken "Befehle ueber MQTT annehmen" (befehle_mqtt_ein) und, fuer die eingreifenden
 * Befehle (Ver-/Entriegeln, Suchen, Blinken), "Eingreifende Befehle auch ueber MQTT annehmen"
 * (befehle_mqtt_steuern) ZUSAMMEN mit "Eingreifende Befehle zulassen" (gefahr_ein) - alle ab Werk aus
 * (Entscheidung E5/E6 Gruppe 42; UDP 11884 ist im Heimnetz ohne Anmeldung erreichbar). Die PIN-Bremse und die
 * Stundenbremse (30/h) des Dienstes gelten fuer alle Wege gemeinsam (eine Warteschlange).
 *
 * Quittungsart "uebergeben" (E4): die Bruecke wartet nie - angenommen heisst, der Befehl liegt in der
 * Warteschlange des Dienstes (oder die X-7-Bremse meldet "unveraendert"). Der Dienst prueft danach ein zweites Mal
 * (Steuerung, zweiter Haken, Temperaturgrenzen, PIN, Stundenbremse) und schreibt das Ergebnis ins Protokoll.
 *
 * Sperrzeiten (Bauvorschrift G41 G2): Sollwerte und Zustaende 0 s - die 60-s-Gleichwert-Bremse X-7 des Plugins
 * bleibt die eine Pruefung; sie antwortet UNVERAENDERT, also angenommen. Ereignisse (auffrischen, suchen, blinken)
 * 60 s (Vorgabe der Bruecke). Fahrzeug 1 unter <p>/befehl/<name>, Fahrzeug 2-9 unter <p>/befehl/<n>/<name>.
 * klima_plan bleibt nur HTTP (Plan mit Laufzeit). Laden starten/stoppen bietet pybyd nicht an.
 * Die Datei setzt keine Konfiguration und schreibt nichts; wie der unangemeldete Endpunkt heilt die Bruecke
 * nichts (by_config(false)).
 */
if (!function_exists('by_befehl_annehmen')) {
    $by_kand = array();
    $by_kand[] = 'REPLACELBPHTMLDIR/by_lib.php';
    $by_kand[] = dirname(__DIR__) . '/webfrontend/html/by_lib.php';
    if (basename(dirname(__DIR__)) === 'plugins') {
        $by_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/by_lib.php';
    }
    foreach ($by_kand as $by_k) {
        if (strpos($by_k, 'REPLACE') !== 0 && is_file($by_k)) {
            require_once $by_k;
            break;
        }
    }
}
$by_grund_soll = 'Die 60-s-Gleichwert-Bremse X-7 des Plugins bleibt die eine Pruefung; sie antwortet UNVERAENDERT, '
    . 'also angenommen (Bauvorschrift Gruppe 41/42, G2).';
/* Ein Befehl der Bruecke -> (Aktion des Endpunkts, Zusatzwerte) fuer by_befehl_annehmen(). */
$by_tun = function ($wie) {
    return function ($w, $t) use ($wie) {
        $nr = isset($t['nr']) ? (string) (int) $t['nr'] : '1';
        list($aktion, $param) = $wie((string) $w);
        if ($aktion === 'aussentemp') {
            return by_befehl_quittung(by_aussentemp_merken($param['wert']));
        }
        return by_befehl_quittung(by_befehl_annehmen($aktion, $param, $nr, 'mqtt'));
    };
};
$by_fest = function ($a) { return function ($w) use ($a) { return array($a, array()); }; };
$by_mit = function ($a, $feld) { return function ($w) use ($a, $feld) { return array($a, array($feld => $w)); }; };
/* Name => array(Art, eingreifend, sperre_s|null fuer die Vorgabe 60, Titel in Loxone, Kachel, Zusatz, Aufruf) */
$by_def = array(
    'auffrischen'     => array('puls', false, null, 'BYD Werte abfragen (MQTT)', 'BEFEHL.K_AUFFRISCHEN', array(), $by_fest('abruf')),
    'klima_an'        => array('zahl', false, 0, 'BYD Klimatisierung ein (MQTT)', 'BEFEHL.K_KLIMA_AN', array('min' => 10, 'max' => 32), $by_mit('klima_start', 'temp')),
    'klima_aus'       => array('puls', false, 0, 'BYD Klimatisierung aus (MQTT)', 'BEFEHL.K_KLIMA_AUS', array(), $by_fest('klima_stop')),
    'sitzklima'       => array('ganz', false, 0, 'BYD Sitzklima (MQTT)', 'BEFEHL.K_SITZKLIMA', array('min' => 0, 'max' => 10), $by_mit('sitzklima', 'stufe')),
    'batterieheizung' => array('ganz', false, 0, 'BYD Batterieheizung (MQTT)', 'BEFEHL.K_BATTHEIZ', array('min' => 0, 'max' => 10), $by_mit('batterieheizung', 'stufe')),
    'fenster_zu'      => array('puls', false, 0, 'BYD Fenster schliessen (MQTT)', 'BEFEHL.K_FENSTER_ZU', array(), $by_fest('fenster_zu')),
    // E6 (Gruppe 42): eingreifend - nur mit befehle_mqtt_steuern UND gefahr_ein; PIN-Bremse bleibt im Dienst.
    'verriegeln'      => array('puls', true, 0, 'BYD Verriegeln (MQTT)', 'BEFEHL.K_VERRIEGELN', array(), $by_fest('verriegeln')),
    'entriegeln'      => array('puls', true, 0, 'BYD Entriegeln (MQTT)', 'BEFEHL.K_ENTRIEGELN', array(), $by_fest('entriegeln')),
    'suchen'          => array('puls', true, null, 'BYD Fahrzeug suchen (MQTT)', 'BEFEHL.K_SUCHEN', array(), $by_fest('suchen')),
    'blinken'         => array('puls', true, null, 'BYD Blinken (MQTT)', 'BEFEHL.K_BLINKEN', array(), $by_fest('blinken')),
);
$by_befehle_mqtt = array();
foreach (array('' => '', '{nr}/' => ' Fahrzeug N') as $by_vor => $by_zus) {
    foreach ($by_def as $by_name => $by_d) {
        $by_e = array('art' => $by_d[0], 'eingreifend' => $by_d[1],
                      'titel' => $by_zus === '' ? $by_d[3] : preg_replace('/^BYD /', 'BYD Fahrzeug N ', $by_d[3]),
                      'kachel' => $by_d[4], 'quittung' => 'uebergeben', 'tun' => $by_tun($by_d[6]));
        if ($by_d[2] !== null) {
            $by_e['sperre_s'] = $by_d[2];
            $by_e['begruendung'] = $by_grund_soll;
        }
        $by_befehle_mqtt[$by_vor . $by_name] = $by_e + $by_d[5];
    }
}
/* G39 E-E4 / G41 G5: die Aussentemperatur der Hauswetterstation aus Loxone - nur einmal, gilt fuer alle
 * Fahrzeuge (Wettergrenze der Vorklimatisierung, 30 min gueltig). */
$by_befehle_mqtt['aussentemp'] = array('art' => 'zahl', 'eingreifend' => false, 'min' => -40, 'max' => 60,
    'sperre_s' => 0, 'begruendung' => 'Ein Messwert: jeder neue Wert zaehlt, auch ein gleicher (frischt das Alter auf).',
    'titel' => 'BYD Außentemperatur aus Loxone (MQTT)', 'kachel' => 'BEFEHL.K_AUSSENTEMP', 'quittung' => 'uebergeben',
    'tun' => $by_tun(function ($w) { return array('aussentemp', array('wert' => $w)); }));
return array(
    'fassung' => 1,
    'linie'   => 'bydautos',
    'plugin'  => 'BYD',
    'geladen' => function_exists('by_befehl_annehmen') && function_exists('by_befehl_quittung')
                 && function_exists('by_config') && function_exists('by_aussentemp_merken'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        $c = by_config(false);   // wie der unangemeldete Endpunkt: nichts heilen, nichts anlegen
        $p = trim((string) $c['mqtt_topic'], '/ ');
        return array('praefix'     => $p === '' ? 'byd' : $p,
                     'annehmen'    => !empty($c['befehle_mqtt_ein']),
                     // E6: eingreifend nur mit dem zweiten Haken UND "Eingreifende Befehle zulassen"
                     'eingreifend' => !empty($c['befehle_mqtt_steuern']) && !empty($c['gefahr_ein']),
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    'protokoll' => function ($stufe, $text) {
        by_log('Befehlsbruecke: ' . $text, $stufe === 'INFO' ? 'INFO' : $stufe, 0);
    },
    'teile' => array('nr' => '[2-9]'),
    'befehle' => $by_befehle_mqtt,
);
