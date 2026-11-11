<?php
/**
 * BYD Autos - Konfiguration vervollstaendigen, Kommandozeile (seit 0.9.39).
 *
 * Aufgerufen vom Dienst beim Start (bin/byd.py main(), vor dem ersten
 * Lesen der Konfiguration) und von postupgrade.sh. Geschrieben wird
 * ausschliesslich ueber by_config_vervollstaendigen() in by_lib.php - der
 * Dienst schreibt die Konfiguration nicht selbst (ein Schreiber fuer ein
 * Format; Bauart BLE-Scanner 1.3.40). Wurzel: LBHOMEDIR, sonst drei Ebenen
 * ueber dem Ablageort - beides nur mit config/plugins, data/plugins und
 * config/system/general.json.
 *
 * Aufruf: php by_vervollstaendigen.php <pluginordner>
 * Ausgabe genau eine Zeile:
 *   VERVOLLSTAENDIGEN;STAND=<ruht|ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<AUFRUF|WURZEL|MODUL_FEHLT>
 * Rueckgabe 0 ausser bei fehler und den Kennungen (1). Die Zeile nennt nur
 * Schluesselnamen, nie einen Wert.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$by_o = isset($argv[1]) ? (string) $argv[1] : '';
if (count($argv) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $by_o) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$by_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$by_h = getenv('LBHOMEDIR');
if (!$by_wurzel($by_h)) {
    $by_h = dirname(dirname(dirname(__DIR__)));
    if (!$by_wurzel($by_h)) {
        echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$by_lib = $by_h . '/webfrontend/html/plugins/' . $by_o . '/by_lib.php';
if (!is_file($by_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $by_h);
putenv('LBPPLUGINDIR=' . $by_o);
require_once $by_lib;
$by_e = by_config_vervollstaendigen();
echo 'VERVOLLSTAENDIGEN;STAND=' . $by_e['stand'] . ';SCHLUESSEL=' . implode(',', $by_e['schluessel']) . "\n";
exit($by_e['stand'] === 'fehler' ? 1 : 0);
