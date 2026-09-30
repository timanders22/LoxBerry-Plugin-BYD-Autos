<?php
/**
 * BYD Autos - Meldung in den LoxBerry-Benachrichtigungsbereich legen
 *
 * Aufruf:  php by_notify.php <Schwere 1-7> <Sprachschluessel> <Pluginordner> [Zahl]
 *
 * BYD-a1 (Verbesserungsbau 30.09.2026): die Anmeldesperre des Dienstes (C3)
 * meldet sich hier, nicht nur oben im Reiter Einstellungen. Der Dienst ist in
 * Python geschrieben; fuer Benachrichtigungen gibt es dort keine
 * LoxBerry-Schnittstelle. Bauform: AudiConnect 0.9.23 bin/au_notify.php (dort
 * aus APC-UPS uebernommen) - mit zwei Unterschieden:
 *
 *   1. Der Text kommt als SPRACHSCHLUESSEL, nicht als fertiger Satz. So steht
 *      die Meldung in der Sprache des LoxBerry (by_t() ueber by_lib.php), und
 *      es ist derselbe Satz wie oben im Reiter (ALLG.ANMELDESPERRE). Erlaubt
 *      sind nur die Schluessel der Positivliste unten.
 *   2. loxberry_log.php wird AUSDRUECKLICH geladen. notify_ext() steht dort,
 *      und keine andere phplib laedt die Datei nach (am Geraet gemessen,
 *      13.09.2026: drei Linien schwiegen deshalb).
 *
 * Der Pluginordner kommt als drittes Argument: der Dienst startet aus dem Cron
 * oder aus postinstall.sh, und dabei fehlen die LoxBerry-Umgebungsvariablen.
 *
 * Rueckgabewert 0 = abgelegt, 1 = nicht moeglich (Grund auf stderr).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen - vom eigenen
 * Ablageort aufwaerts, bis ein Verzeichnis config/plugins, data/plugins UND
 * config/system/general.json traegt (wie au_notify.php 0.9.20: mit weniger
 * galt jeder Rest eines frueheren Pruefstands als Wurzel). Der Block steht
 * VOR seinem Aufruf - PHP zieht Funktionen in einem if-Block nicht vor. */
if (!function_exists('by_notify_wurzel')) {
    function by_notify_wurzel()
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

$by_n_home = (string) getenv('LBHOMEDIR');
if ($by_n_home === '' || !is_dir($by_n_home . '/config/plugins')
    || !is_dir($by_n_home . '/data/plugins')) {
    $by_n_home = by_notify_wurzel();
}
if ($by_n_home === '') {
    fwrite(STDERR, "Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - "
        . "es wurde keine Meldung abgesetzt.\n");
    exit(1);
}
$by_n_sdk = $by_n_home . '/libs/phplib/loxberry_log.php';
if (!is_file($by_n_sdk) || !is_file($by_n_home . '/libs/phplib/loxberry_system.php')) {
    fwrite(STDERR, "LoxBerry-Bibliothek nicht gefunden: " . $by_n_sdk . "\n");
    exit(1);
}
require_once $by_n_home . '/libs/phplib/loxberry_system.php';
require_once $by_n_sdk;

$by_n_schwere = (isset($argv[1]) && preg_match('/^[1-7]$/', (string) $argv[1]))
    ? (int) $argv[1] : 4;
$by_n_schluessel = isset($argv[2]) ? (string) $argv[2] : '';
$by_n_paket = isset($argv[3]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[3]) : '';
if ($by_n_paket === '') {
    $by_n_paket = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
}
if ($by_n_paket === '' || $by_n_paket === '.') {
    $by_n_paket = 'bydautos';
}
$by_n_zahl = (isset($argv[4]) && preg_match('/^[0-9]{1,6}$/', (string) $argv[4]))
    ? (int) $argv[4] : 0;

/* Nur diese Schluessel - jeder traegt hoechstens einen Platzhalter %d. */
if (!in_array($by_n_schluessel, array('ALLG.ANMELDESPERRE'), true)) {
    fwrite(STDERR, "Unbekannter Meldungsschluessel - es wurde keine Meldung abgesetzt.\n");
    exit(1);
}

/* by_lib.php liefert by_t() in der Sprache des LoxBerry. Installiert liegt sie
 * unter webfrontend/html/plugins/<ordner>/, im ausgepackten Archiv unter
 * ../webfrontend/html/. */
$by_n_lib = '';
foreach (array($by_n_home . '/webfrontend/html/plugins/' . $by_n_paket . '/by_lib.php',
               dirname(__DIR__) . '/webfrontend/html/by_lib.php') as $by_n_k) {
    if (is_file($by_n_k)) {
        $by_n_lib = $by_n_k;
        break;
    }
}
if ($by_n_lib === '') {
    fwrite(STDERR, "by_lib.php nicht gefunden - es wurde keine Meldung abgesetzt.\n");
    exit(1);
}
require_once $by_n_lib;
$by_n_text = sprintf(by_t($by_n_schluessel), $by_n_zahl);

if (!function_exists('notify_ext')) {
    fwrite(STDERR, "notify_ext() steht in dieser LoxBerry-Fassung nicht bereit.\n");
    exit(1);
}
notify_ext(array(
    'PACKAGE'  => $by_n_paket,
    'NAME'     => 'BYD Autos',
    'MESSAGE'  => $by_n_text,
    'SEVERITY' => $by_n_schwere,
));
exit(0);
