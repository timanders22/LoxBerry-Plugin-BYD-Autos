<?php
/**
 * BYD Autos - gemeinsame Bibliothek
 *
 * Liegt bewusst unter webfrontend/html/, weil der Miniserver-Endpunkt sie
 * ebenso braucht wie die Oberflaeche. Nur so gibt es EINE Datei statt zweier
 * Kopien, die auseinanderlaufen. Die Oberflaeche unter htmlauth/ laedt sie von
 * hier ueber eine Kandidatenliste (installiert und im Archiv liegen die beiden
 * Baeume an verschiedenen Stellen).
 *
 * Die Bibliothek spricht NIE mit der BYD-Schnittstelle. Sie liest den
 * Zwischenspeicher, den bin/byd.py schreibt, und legt Schreibbefehle in einer
 * Warteschlange ab. Ein Plugin, das den Datenabruf in der Oberflaeche oder im
 * Endpunkt erledigt, ist falsch gebaut - auch wenn es funktioniert.
 *
 * Praefix 'by_', weil LBWeb::lbheader() SDK-Globale setzt (unter anderem $p
 * und $cfg) und gleichnamige Plugin-Variablen ueberschreiben wuerde.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('by_e')) {
    function by_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* Gemeinsame Sprachausgabe (Abschrift von Werkzeuge/gemeinsam/sprachausgabe.php, Nr. 36 b, seit
 * 0.9.23; seit 0.9.29 Stamm 1.2.1 mit weiteren Ausgabezielen, by_mit_zielen(), Welle Mehrfach-Ziele).
 * Liegt neben dieser Datei; sie schuetzt sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sprachausgabe.php';


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch den
 * Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet es
 * nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin abfangen
 * muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert.
 *
 * Zusaetzlich muss der Kandidat config/system/general.json tragen, ohne
 * Ausnahme. Ohne diese Bedingung fand die Suche jedes Verzeichnis mit
 * config/plugins und webfrontend; auf einem Pruefrechner sind das Reste
 * frueherer Pruefstaende (Regeln/06). Gemessen am 18.09.2026
 * (Pruefung-BYD-Autos-0.9.16, Fall H3): die Oberflaeche, aus einem
 * ausgepackten Archiv ohne LBHOMEDIR aufgerufen, legte in einem solchen
 * fremden Baum config/plugins/bydautos/byd.json samt Zweitschrift an. Bis
 * 0.9.16 galt daneben "genau die Wurzel, unter deren webfrontend/html/plugins/
 * diese Datei liegt" auch ohne general.json - in einem fremden Baum schrieb
 * die Seite damit dort byd.json (in WSL gemessen 24.09.2026,
 * Pruefung-BYD-Autos-0.9.17, Fall P1).
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')
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

/* Die Wurzel der Oberflaeche - an EINER Stelle, wie by_paths() und by_t()
 * sie beide brauchen.
 *
 * Bis 0.9.16 stand in beiden Funktionen
 *     foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k)
 * und ein gesetztes $LBHOMEDIR galt, sobald es nur ein Verzeichnis war. Unter
 * /home/loxberry/loxberry liegt auf einem LoxBerry keine Wurzel (Regeln/06);
 * der feste Pfad traf nie die eigene Anlage, nur nichts oder einen fremden
 * Baum, und ein leeres $LBHOMEDIR-Verzeichnis bekam byd.json (in WSL gemessen
 * 24.09.2026, Pruefung-BYD-Autos-0.9.17, Faelle P3 und P4). Jetzt:
 * $LBHOMEDIR, wenn es config/plugins und data/plugins traegt (general.json
 * wird dort nicht verlangt - die Pruefwerkzeuge setzen LBHOMEDIR auf eine
 * Attrappe), sonst die Suche. Findet auch die nichts, gibt es KEINE Wurzel,
 * und der Aufrufer arbeitet im Archivmodus. Vorbild Skoda-Connect-NG 0.9.25
 * sk_lbhome(). */
function by_lbhome()
{
    $home = (string) getenv('LBHOMEDIR');
    if ($home !== '' && is_dir($home . '/config/plugins') && is_dir($home . '/data/plugins')) {
        return $home;
    }
    return lb_wurzel_ermitteln();
}

function by_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = by_lbhome();
    /* Der Pluginordner ergibt sich aus LBPPLUGINDIR - der Auskunft von
     * LoxBerry selbst -, sonst aus dem Ablageort dieser Datei. Der
     * MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt:
     * er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
     * sich bei jedem Fork.
     *
     * Der feste Name greift nur dort, wo der ermittelte nachweislich kein
     * Plugin-Ordner sein kann - aus dem ausgepackten Archiv heraus heisst er
     * "html". Faellt man dagegen immer auf den festen Namen zurueck, zeigen
     * bei einer Zweitinstallation (LoxBerry haengt "_01" an) deren Pfade auf
     * die ERSTE Installation: gemeinsame Konfiguration mit den Zugangsdaten,
     * gemeinsame Warteschlange, gemeinsames Protokoll. */
    $eigen = basename(dirname(__FILE__));
    $lbp = (string) getenv('LBPPLUGINDIR');
    $lbp = $lbp !== '' ? basename(rtrim($lbp, '/')) : '';
    $dir = $lbp !== '' ? $lbp : $eigen;
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html') {
        $dir = 'bydautos';
    }
    /* Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek dort installiert
     * liegt (<Wurzel>/webfrontend/html/plugins/<ordner>, physisch verglichen)
     * oder der Aufrufer Wurzel UND Ordner ausdruecklich nennt ($LBHOMEDIR und
     * $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit ihrer Attrappe).
     * Sonst ist es ein ausgepacktes Archiv, und alles bleibt in dessen
     * eigenem Ordner (Archivmodus, 'home' leer). Bis 0.9.16 nahm ein Archiv
     * unter einer echten Wurzel diese Wurzel und den festen Namen
     * "bydautos" - Konfiguration, Token und Zugangsdaten der Anlage (in WSL
     * gemessen 24.09.2026, Pruefung-BYD-Autos-0.9.17, Fall P2). Bauart
     * Einspeisebremse 0.9.22 eb_paths(). */
    if ($home !== '') {
        $soll = realpath($home . '/webfrontend/html/plugins/' . $eigen);
        $installiert = ($soll !== false && $soll === realpath(__DIR__));
        $genannt = ($lbp !== '' && $home === (string) getenv('LBHOMEDIR'));
        if (!$installiert && !$genannt) {
            $home = '';
        }
    }
    if ($home !== '') {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/byd.json',
            'zugang'    => $home . '/config/plugins/' . $dir . '/zugang.json',
            // Die Zweitschrift liegt NEBEN dem Konfigordner, nicht darin:
            // LoxBerry entfernt beim Upgrade und beim Deinstallieren das
            // VERZEICHNIS, und eine Sicherung darin stirbt genau in dem Fall
            // mit, fuer den es sie gibt.
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.byd.json',
            'zugang_sicherung' => $home . '/config/plugins/' . $dir . '.backup.zugang.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/byd.log',
        );
    } else {
        // Nicht installiert (Entwicklung, Attrappe): neben dem Plugin arbeiten.
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/byd.json',
            'zugang'    => $basis . '/config/zugang.json',
            'sicherung' => $basis . '/config/byd.backup.json',
            'zugang_sicherung' => $basis . '/config/zugang.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/byd.log',
        );
    }
    return $p;
}

/**
 * Die Marke "Aktualisierung laeuft".
 *
 * preupgrade.sh legt sie als Erstes an, postupgrade.sh - das letzte
 * Hakenskript dieser Linie - entfernt sie nach dem Wiederanlauf des Dienstes.
 * Sie liegt NEBEN dem Datenordner, weil purge_installation den Ordner selbst
 * loescht.
 *
 * Warum die Oberflaeche sie liest: in der Luecke zwischen der neuen
 * Cron-Datei und postinstall.sh sind config/plugins/<ordner>/ und
 * data/plugins/<ordner>/ weg. Ein Druck auf "Speichern" im Reiter
 * Einstellungen schrieb dann ueber by_zugang_speichern() eine zugang.json
 * OHNE Passwort und OHNE Steuer-PIN - und zog diesen leeren Stand in die
 * Zweitschrift nach. postinstall.sh holte danach genau ihn zurueck; die
 * Zugangsdaten des BYD-Kontos waren endgueltig fort. In WSL gemessen
 * (18.09.2026, Pruefung-BYD-Autos-0.9.15, Faelle A5 und A9).
 */
function by_upgrade_marke()
{
    $d = by_paths()['datadir'];
    return dirname($d) . '/' . basename($d) . '.upgrade_laeuft';
}

/**
 * Gilt die Marke gerade?
 *
 * Aelter als 3600 s, aus der Zukunft oder mit unlesbarem Inhalt: sie gilt
 * NICHT - eine abgebrochene Installation darf die Seite nicht fuer immer
 * stilllegen. Ein paar Minuten "Zukunft" sind eine nachgestellte Uhr und
 * keine Luege; deshalb -300 statt 0.
 *
 * Dieselbe Frist und dieselben drei Ausgaenge wie in bin/dienst.sh
 * (upgrade_laeuft) - eine Seite, die sperrt, waehrend der Dienst startet,
 * waere so falsch wie umgekehrt.
 */
function by_upgrade_laeuft()
{
    $f = by_upgrade_marke();
    $roh = @is_file($f) ? @file_get_contents($f) : false;
    if ($roh === false) {
        return false;
    }
    $roh = trim((string) $roh);
    if (!preg_match('/^[0-9]{1,12}$/', $roh)) {
        return false;
    }
    $alter = time() - (int) $roh;
    return $alter > -300 && $alter < 3600;
}

/**
 * Voreinstellungen.
 *
 * Muessen zu VORGABEN in bin/byd.py passen. Der Reiter Test vergleicht beide
 * Listen gegeneinander und der Selbsttest des Dienstes ebenso - ein Kommentar
 * "muss zur anderen passen" ist eine Hoffnung, keine Pruefung.
 *
 * Alles Ausloesende steht ab Werk AUS: eine Vorgabe "an" erreicht auch jede
 * bestehende Anlage, und zwar beim ersten Aufruf nach dem Update, ohne dass
 * jemand etwas angeklickt hat.
 */
function by_vorgaben()
{
    return array(
        'intervall'       => 300,
        'mqtt_ein'        => 0,
        'mqtt_topic'      => 'byd',
        'steuerung_ein'   => 0,
        // Welle Bild 17 (0.9.26, Hausherr 17.10.2026): ZWEITER Haken fuer die eingreifenden Befehle
        // (Verriegeln/Entriegeln, Suchen, Blinken) - ab Werk AUS. Gleich in VORGABEN, bin/byd.py.
        'gefahr_ein'      => 0,
        'temp_min'        => 16,
        'temp_max'        => 30,
        'verlauf_tage'    => 8,
        'gps_ein'         => 1,
        'mqtt_bibliothek' => 1,
        'aktionstoken'    => '',
        'wartezeit'       => 8,
        // Vorklimatisierung am Abfahrtsassistenten - ab Werk AUS.
        'abfahrt_ein'      => 0,
        'abfahrt_praefix'  => 'abfahrt',
        'abfahrt_vorlauf'  => 20,
        'abfahrt_temp'     => 21,
        'abfahrt_alter'    => 300,
        'abfahrt_fahrzeug' => 1,
        // Ladeempfehlung aus einem fremden Thema - ab Werk AUS.
        'ladeempf_ein'    => 0,
        'ladeempf_thema'  => '',
        'ladeempf_grenze' => 0,
        'ladeempf_unter'  => 1,
        'ladeempf_alter'  => 900,
        // Laden-1: Ladeempfehlung nach Strompreis aus Spotpreis aWATTar oder
        // Tibber - ab Werk AUS. Praefix leer = Vorgabe der Quelle.
        'preisempf_ein'     => 0,
        'preisempf_quelle'  => 'awattar',
        'preisempf_praefix' => '',
        'preisempf_stunden' => 4,
        // Zutaten der gerechneten Groessen. Leer heisst: der Wert entsteht
        // nicht. Geraten wird nichts.
        'kapazitaet'   => 0,
        'heim_breite'  => '',
        'heim_laenge'  => '',
        'heim_radius'  => 150,
        // Nr. 36 b (seit 0.9.23): Ansageanlaesse, je einzeln abwaehlbar - dieselben Werte wie
        // VORGABEN in bin/byd.py. Gesprochen wird erst mit einer Ausgabeart (tts, ab Werk 'aus').
        'ansage_laden_fertig' => 1,
        'ansage_ausfall'      => 1,
        /* Gruppe 42 (0.9.29): Hausform - dieselben Werte wie VORGABEN in bin/byd.py, alles ab Werk aus.
         * endpunkt_ein: neue Anlagen 0 (Gateway V2 und Befehlsbruecke); beim Update setzt postinstall.sh 1,
         * wenn der Schluessel fehlt (Bestand nutzt den HTTP-Weg). befehle_mqtt_steuern ist der zweite
         * Haken fuer eingreifende Befehle ueber MQTT (wirkt nur mit gefahr_ein, E6). */
        'befehle_mqtt_ein'      => 0,
        'befehle_mqtt_steuern'  => 0,
        'endpunkt_ein'          => 0,
        'haus_abfahrt_ein'      => 0,
        'vorklima_anwesend_ein' => 0,
        'vorklima_wetter_ein'   => 0,
        'vorklima_kalt_unter'   => 5,
        'vorklima_heiss_ueber'  => 25,
        'ankunft_ein'           => 0,
        'ankunft_quelle'        => '',
        'haus_strompreis_ein'   => 0,
        // Zweite Quelle der Ladeempfehlung aus einem Thema: thema (wie bisher) oder haus (haus/strompreis/aktuell).
        'ladeempf_quelle'       => 'thema',
        // Meldungen an SignalBot (E10): Hauptschalter aus, je Ereignis abwaehlbar.
        'signal_ein'            => 0,
        'signal_praefix'        => 'signalbot',
        'signal_laden_fertig'   => 1,
        'signal_ausfall'        => 1,
        'signal_vorklima'       => 1,
        // Sammelthemen und Kabel-Warnung (0.9.31, Regeln/07): dieselben Werte wie VORGABEN im Dienst.
        'haus_fahrzeug_ein'      => 1,
        'kabel_soc'              => 30,
        'kabel_uhr'              => '20:00',
        'kabel_abfahrt_min'      => 60,
        'signal_kabel'           => 1,
        'vorklima_min_soc'       => 20,
        'vorklima_nur_kabel'     => 0,
        'ansage_kabel_vergessen' => 1,
        'tts'          => ansage_werk_block(),     // Stamm 1.3.2: ab Werk an, nur fuer einen nie gespeicherten Block
        'ansage_regeln' => ansage_regeln_vorgaben(),
        'ansage_token_herkunft' => array(),
    );
}

function by_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Die Konfiguration.
 *
 * $erzeugen = false schaltet jedes Schreiben ab. Der unangemeldete Endpunkt
 * ruft so auf: ein Aufruf OHNE Token darf nichts anlegen - auch nichts
 * Harmloses. Gemessen an einem Schwesterplugin hinterliess ein einziger,
 * korrekt mit 403 abgewiesener Aufruf eine frisch erzeugte Konfiguration samt
 * Token und Zweitschrift.
 *
 * Die Unterscheidung "unlesbar" haengt an is_file(), nicht am Inhalt: eine
 * Datei, die DA ist und leer, entsteht beim Abbruch eines Schreibvorgangs -
 * und gerade die darf nicht als "noch nichts eingerichtet" gelten.
 *
 * BERICHTIGT am 03.09.2026. Genau das stand hier schon, und der Code hielt es
 * NICHT: die Bedingung unten fragte trim($roh) !== '' und schickte die leere
 * Datei in denselben Zweig wie die fehlende. Gemessen in vier Lagen:
 *
 *     leer, ohne Zweitschrift   Werkseinstellung, Token "", KEINE Protokollzeile
 *     leer, mit Zweitschrift    geheilt und geschrieben, KEINE Protokollzeile
 *     ungueltiges JSON          geheilt, .kaputt angelegt, Protokollzeile
 *     "{}"                      geheilt, Protokollzeile - der Aktualisierungsfall
 *
 * Die ersten beiden Zeilen sind der Befund. Ein abgebrochener Schreibvorgang
 * (Stromausfall, volle Ramdisk) hinterlaesst eine 0-Byte-Datei; ohne
 * Zweitschrift ist danach das Aktionstoken lautlos fort, der Endpunkt
 * antwortet jedem virtuellen Ausgang mit KEIN_TOKEN_GESETZT, in Loxone sieht
 * das aus wie "kein Wert" - und im Protokoll steht nichts.
 *
 * Die vier Zustaende bedeuten Verschiedenes und werden ab 0.9.6 auch
 * verschieden behandelt. Ein ".kaputt" gibt es nur, wo es Inhalt zu retten
 * gibt: eine leere Datei danebenzulegen waere Beiwerk.
 */
function by_config($erzeugen = true)
{
    $p = by_paths();
    $da  = is_file($p['config']);
    $roh = $da ? (string) @file_get_contents($p['config']) : '';

    /* Datei DA und LEER: ein abgebrochener Schreibvorgang, kein leerer
     * Zustand. Gemeldet wird es in jedem Fall (by_log bremst gegen
     * Wiederholungen); geheilt und geschrieben nur, wo Schreiben erlaubt ist. */
    if ($da && trim($roh) === '') {
        if ($erzeugen) {
            by_log('Die Konfigurationsdatei ist vorhanden, aber LEER - das ist der '
                 . 'Rest eines abgebrochenen Schreibvorgangs, kein leerer Zustand.',
                   'WARN');
            $sich = by_json_lesen($p['sicherung']);
            if ($sich) {
                by_log('Aus der Zweitschrift wiederhergestellt.', 'INFO');
                by_config_speichern(array_merge(by_vorgaben(), $sich), false, $p['sicherung']);
                return array_merge(by_vorgaben(), $sich);
            }
            by_log('Es gibt keine Zweitschrift. Das Aktionstoken ist damit fort; '
                 . 'die Adressen im Miniserver sind ungueltig, bis im Reiter '
                 . 'Einstellungen einmal gespeichert wurde.', 'WARN');
        }
        /* Bewusst KEIN Schreiben ohne Zweitschrift: eine aus den Vorgaben
         * entstandene Konfiguration wuerde beim naechsten Speichern die letzte
         * heile Sicherung ueberschreiben. */
        return by_vorgaben();
    }

    if ($da && trim($roh) !== '{}') {
        $cfg = json_decode($roh, true);
        if (!is_array($cfg)) {
            /* Ungueltiges JSON ist ein FEHLER, kein leerer Wert. Wer es
             * stillschweigend als leer behandelt, loest diese Kette aus:
             * Werkseinstellung -> leeres Token -> neues Token erzeugt ->
             * Werkseinstellung gespeichert -> Zweitschrift ueberschrieben.
             * Verloren waeren dann Token (womit jede Loxone-Adresse ungueltig
             * wird), Themenpraefix und Grenzen. */
            if ($erzeugen) {
                if (!is_file($p['config'] . '.kaputt')) {
                    // Dieselben Rechte wie das Original - die kaputte Datei
                    // traegt dasselbe Aktionstoken.
                    by_kopie_geschuetzt($p['config'], $p['config'] . '.kaputt', 0600);
                    by_log('Die Konfiguration war unlesbar (kein gueltiges JSON). Sie liegt '
                         . 'als byd.json.kaputt daneben; weitergearbeitet wird mit der '
                         . 'Zweitschrift.');
                }
                $sich = by_json_lesen($p['sicherung']);
                if ($sich) {
                    // Lesen UND zurueckschreiben - ein reines Lesen liesse die
                    // Datei dauerhaft fehlen, und jeder Aufruf zoege die
                    // Sicherung erneut und schriebe eine Protokollzeile.
                    by_config_speichern(array_merge(by_vorgaben(), $sich), false, $p['sicherung']);
                    return array_merge(by_vorgaben(), $sich);
                }
            }
            return array_merge(by_vorgaben(), array());
        }
        return array_merge(by_vorgaben(), $cfg);
    }

    /* Datei fehlt (Neuinstallation) oder traegt "{}" (Aktualisierungsfall -
     * postinstall.sh legt sie so an). Beides sind ECHTE leere Zustaende, keine
     * Beschaedigung: hier darf ein Token entstehen. Aus der Zweitschrift
     * heilen, wenn es eine gibt. */
    if ($erzeugen && is_file($p['sicherung'])) {
        $sich = by_json_lesen($p['sicherung']);
        if ($sich) {
            by_config_speichern(array_merge(by_vorgaben(), $sich), false, $p['sicherung']);
            return array_merge(by_vorgaben(), $sich);
        }
    }
    return by_vorgaben();
}

/**
 * Schreibt die Konfiguration - erst in eine Nebendatei, dann umbenennen.
 *
 * Die Nebendatei traegt die Prozessnummer im Namen: Oberflaeche, Endpunkt und
 * Dienst schreiben dieselben Dateien, und ohne die Nummer ueberschriebe einer
 * die Nebendatei des anderen - umbenannt wuerde eine Mischung. Die Rechte
 * werden auf der NEBENDATEI gesetzt, nicht danach: darin steht das
 * Aktionstoken.
 *
 * $sicherung = false unterdrueckt das Fortschreiben der Zweitschrift. Das ist
 * fuer den Heilungsfall noetig: dort wird die Zweitschrift gerade gelesen, und
 * sie mit dem eben Gelesenen zu ueberschreiben waere nur Arbeit - schlimmer,
 * es koennte eine gute Sicherung mit einem halben Stand ueberschreiben.
 */
/**
 * Eine Datei geschuetzt kopieren - Rechte VOR dem Inhalt.
 *
 * "Kopieren, dann chmod" laesst die Datei fuer die Dauer des Schreibens mit
 * den Rechten der umask stehen, und zwar bereits mit dem vollen Inhalt. Bei
 * einer Zweitschrift, in der ein Passwort oder das Aktionstoken steht, ist das
 * der Unterschied zwischen "kurz lesbar" und "nie lesbar".
 *
 * ERGAENZT 03.09.2026. Die Nebendatei beim Schreiben der Konfiguration macht
 * es seit jeher richtig (leer anlegen, chmod, dann fuellen) und der Kommentar
 * daneben begruendet genau diese Reihenfolge - fuer die beiden ZWEITSCHRIFTEN
 * galt sie nicht: dort stand @copy() gefolgt von @chmod(). Der Fall trifft die
 * Erstanlage und jede Wiederherstellung nach einem Loeschen; auf einer
 * bestehenden Anlage behaelt copy() die vorhandenen Rechte.
 *
 * Die Nebendatei traegt die Prozessnummer: schreiben Oberflaeche und Dienst
 * gleichzeitig, ueberschriebe sonst einer die Nebendatei des anderen, und
 * umbenannt wuerde eine Mischung.
 */
function by_kopie_geschuetzt($von, $nach, $rechte = 0600)
{
    $inhalt = @file_get_contents($von);
    if ($inhalt === false) {
        return false;
    }
    $tmp = $nach . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, $rechte);
    $ok = ftruncate($fh, 0) && (@fwrite($fh, $inhalt) === strlen($inhalt));
    fflush($fh);
    fclose($fh);
    if (!$ok || !@rename($tmp, $nach)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function by_config_speichern($cfg, $sicherung = true, $gestalt = null)
{
    $p = by_paths();
    if (!is_dir($p['configdir'])) {
        @mkdir($p['configdir'], 0775, true);
    }
    /* Leere Objekte bleiben leere Objekte, in JEDEM Speicherweg (seit 0.9.40, Entscheidung des Hausherrn
     * 11.11.2026). by_config() liest mit json_decode(..., true), dabei wird {} zu einem leeren Feld, und
     * json_encode() schrieb es als [] zurueck. Fuer tts hielt die Vorpruefung _ansage_aus() in byd.py bis
     * 0.9.40 ein [] fuer "an"; die Bruecke liest {} und [] beide als "aus" (gemessen 11.11.2026). Die Gestalt kommt aus dem bisherigen Rohtext der Datei, bei
     * der Selbstheilung ($gestalt) aus der Zweitschrift; was dort kein leeres Objekt war, bleibt so, wie es
     * uebergeben wurde. */
    $by_roh = @file_get_contents($gestalt !== null ? $gestalt : $p['config']);
    if (is_array($cfg) && $cfg !== array() && is_string($by_roh) && $by_roh !== '') {
        $cfg = by_json_leere_objekte($cfg, json_decode($by_roh));
    }
    // Erst kodieren, dann den Rueckgabewert ansehen, dann schreiben:
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // macht daraus eine leere Zeichenkette, schreibt null Byte und meldet das
    // als Erfolg - der Rueckgabewert ist 0, nicht false.
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $p['config'] . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    $ok = ftruncate($fh, 0) && (@fwrite($fh, $json) === strlen($json));
    fflush($fh);
    fclose($fh);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $p['config'])) {
        @unlink($tmp);
        return false;
    }
    if ($sicherung) {
        // Die Zweitschrift wird nur fortgeschrieben, wenn wirklich eine
        // Konfiguration MIT Token gespeichert wurde. Sonst ueberschriebe ein
        // halber Stand die letzte Zuflucht.
        if (trim((string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '')) !== '') {
            by_kopie_geschuetzt($p['config'], $p['sicherung'], 0600);
        }
    }
    return true;
}

/** Leere Objekte des Rohtextes ($o, json_decode ohne true) im Feld $a wieder als Objekt fuehren (11.11.2026),
 *  damit json_encode() sie als {} und nicht als [] schreibt - oben wie verschachtelt, auch unter fremden
 *  Schluesseln. Seit 0.9.40 ruft by_config_speichern() sie fuer jeden Speicherweg auf; der Aufruf in
 *  by_config_vervollstaendigen() bleibt stehen und aendert dort nichts mehr.
 *  Bauart Bewaesserung 0.9.56 (bw_json_leere_objekte()). */
function by_json_leere_objekte($a, $o)
{
    if (!is_array($a)) { return $a; }
    if (is_object($o)) {
        if (!get_object_vars($o) && $a === array()) { return new stdClass(); }
        foreach ($a as $k => $v) {
            $ks = (string) $k;
            if (is_array($v) && property_exists($o, $ks)) { $a[$k] = by_json_leere_objekte($v, $o->$ks); }
        }
    } elseif (is_array($o)) {
        foreach ($a as $k => $v) {
            if (is_array($v) && array_key_exists($k, $o)) { $a[$k] = by_json_leere_objekte($v, $o[$k]); }
        }
    }
    return $a;
}

/**
 * Konfiguration vervollstaendigen (seit 0.9.39, Regeln/05 "Die Konfiguration
 * wird vervollstaendigt, nicht nur ergaenzt"; Bauform Docker NG 1.3.7).
 *
 * Bis 0.9.38 ergaenzten by_config() und config() im Dienst fehlende
 * Schluessel bei jedem Lesen nur im Speicher; in byd.json kamen sie erst mit
 * dem naechsten Speichern in der Oberflaeche. Aufgerufen ueber
 * bin/by_vervollstaendigen.php: beim Dienststart (byd.py main(), vor dem
 * ersten Lesen der Konfiguration in dienst_lauf()) und von postupgrade.sh
 * nach dem Entfernen der Marke - beim Update startet postinstall.sh den
 * Dienst, solange die Marke noch liegt, und waehrend der Marke schreibt diese
 * Funktion nichts. Weil nur bei FEHLENDEN Schluesseln geschrieben wird, bleibt
 * es bei einem Schreibvorgang.
 *
 * - Nur in der Installation (oder mit ausdruecklich genannter Wurzel), nicht
 *   waehrend einer Aktualisierung (by_upgrade_laeuft()), und nur wenn byd.json
 *   ein lesbares Objekt ist und ein Aktionstoken nach dem Muster der
 *   Sicherungspruefung traegt (nicht leer, [A-Za-z0-9_.-]{1,64}). Eine
 *   fehlende, leere, "{}" oder kaputte Datei ist Sache der Selbstheilung in
 *   by_config().
 * - Geschrieben werden die WERTE AUS DER DATEI, nicht die von by_config() bzw.
 *   config() im Dienst aufbereiteten; fremde Schluessel bleiben stehen.
 *   Nachgetragen werden nur Schluessel aus by_vorgaben(), mit deren Wert:
 *   by_config() kennt keine Lese-Regel fuer Bestandsdateien (endpunkt_ein
 *   traegt postinstall.sh beim Update fest ein), und die Vorgaben des Dienstes
 *   (VORGABEN in byd.py) stimmen in allen gemeinsamen Schluesseln ueberein.
 * - Geschrieben ueber by_config_speichern() (0600, Zweitschrift).
 * - Genau eine Protokollzeile mit den Namen, nie mit Werten.
 *
 * Rueckgabe: array('stand' => ruht|ohne_datei|ohne_merkwort|vollstaendig
 *                  |ergaenzt|fehler, 'schluessel' => array(...))
 */
function by_config_vervollstaendigen()
{
    $p = by_paths();
    if ($p['home'] === '' || by_upgrade_laeuft()) {
        return array('stand' => 'ruht', 'schluessel' => array());
    }
    clearstatcache(true, $p['config']);
    if (!is_file($p['config'])) {
        return array('stand' => 'ohne_datei', 'schluessel' => array());
    }
    $roh = @file_get_contents($p['config']);
    $datei = is_string($roh) ? json_decode($roh, true) : null;
    if (!is_array($datei) || !isset($datei['aktionstoken']) || !is_string($datei['aktionstoken'])
            || !preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $datei['aktionstoken'])) {
        return array('stand' => 'ohne_merkwort', 'schluessel' => array());
    }
    // Ein leeres Objekt der Datei ({}) bliebe nach json_decode(..., true) ein leeres Feld und kaeme beim
    // Schreiben als [] zurueck - der Rohwert wird erhalten, bevor ergaenzt wird (11.11.2026).
    $datei = by_json_leere_objekte($datei, json_decode($roh));
    $fehlend = array();
    foreach (by_vorgaben() as $k => $w) {
        /* endpunkt_ein bleibt aussen vor (11.11.2026): den Uebergang fuer Bestandsanlagen setzt das
         * Update; ein hier festgeschriebenes "aus" koennte ein spaeteres Update nicht mehr berichtigen. */
        if ($k === 'endpunkt_ein') {
            continue;
        }
        if (!array_key_exists($k, $datei)) {
            $datei[$k] = $w;
            $fehlend[] = $k;
        }
    }
    if (!$fehlend) {
        return array('stand' => 'vollstaendig', 'schluessel' => array());
    }
    if (!by_config_speichern($datei)) {
        by_log('Konfiguration NICHT vervollstaendigt: byd.json liess sich nicht schreiben ('
             . count($fehlend) . ' fehlende Schluessel).', 'WARN');
        return array('stand' => 'fehler', 'schluessel' => $fehlend);
    }
    by_log('Konfiguration vervollstaendigt - ' . count($fehlend)
         . ' fehlende Vorgaben in byd.json geschrieben: ' . implode(', ', $fehlend), 'INFO');
    return array('stand' => 'ergaenzt', 'schluessel' => $fehlend);
}

/** Eine Zeile ins Plugin-Protokoll, gebremst gegen Wiederholungen. */
function by_log($text, $stufe = 'INFO', $sekunden = 3600)
{
    $p = by_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    $merker = $p['log'] . '.wdh';
    // Ist das Protokoll fort (geleert, gekappt, oder nach einem Neustart der
    // Ramdisk verschwunden), gehoert der Merker zurueckgesetzt - sonst
    // unterdrueckt die Bremse ausgerechnet die ERSTE Zeile in der leeren
    // Datei.
    if (!is_file($p['log'])) {
        @unlink($merker);
    }
    $schluessel = md5($stufe . '|' . $text);
    $alt = is_file($merker) ? (string) @file_get_contents($merker) : '';
    $teile = explode('|', $alt);
    if (count($teile) >= 2 && $teile[0] === $schluessel
        && (time() - (int) $teile[1]) < $sekunden) {
        $n = isset($teile[2]) ? (int) $teile[2] + 1 : 1;
        @file_put_contents($merker, $schluessel . '|' . $teile[1] . '|' . $n);
        return;
    }
    if (count($teile) >= 3 && (int) $teile[2] > 0 && $teile[0] !== $schluessel) {
        @file_put_contents($p['log'], sprintf("%s [INFO] Die vorige Meldung wurde %d "
            . "weitere Male ausgeloest.\n", date('Y-m-d H:i:s'), (int) $teile[2]),
            FILE_APPEND | LOCK_EX);
    }
    @file_put_contents($merker, $schluessel . '|' . time() . '|0');
    // Kappung: ab 500 kB bleiben die letzten 200 Zeilen stehen. log/plugins
    // liegt auf einer Ramdisk - eine unbegrenzt wachsende Datei frisst
    // Arbeitsspeicher, nicht Plattenplatz.
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], sprintf("%s [%s] %s\n", date('Y-m-d H:i:s'),
        $stufe, $text), FILE_APPEND | LOCK_EX);
}

/**
 * Zugangsdaten.
 *
 * Eigene Datei mit Rechten 0600, nicht in der Konfiguration, die die
 * Oberflaeche anzeigt. Passwort und Steuer-PIN werden nie zurueckgegeben -
 * nur ihre Laenge. Ein Pruefknopf darf die FORM eines Geheimnisses beurteilen,
 * nie seinen Wert zeigen.
 */
function by_zugang()
{
    $z = by_json_lesen(by_paths()['zugang']);
    return array(
        'benutzer'    => isset($z['benutzer']) ? (string) $z['benutzer'] : '',
        'laenge'      => isset($z['passwort']) ? strlen((string) $z['passwort']) : 0,
        'pin_laenge'  => isset($z['pin']) ? strlen((string) $z['pin']) : 0,
        'land'        => isset($z['land']) ? (string) $z['land'] : '',
    );
}

/**
 * Speichert die Zugangsdaten.
 *
 * Ein leer zurueckgegebenes Passwortfeld loescht nichts: sonst stuende
 * irgendwann ein leeres Passwort in der Datei, ohne dass es jemand merkt.
 * Genau dieser Fehler hat in einem anderen Plugin 21 vergebliche
 * Anmeldeversuche verursacht - und war von aussen nicht zu sehen, weil das
 * Passwort ja gespeichert war.
 */
function by_zugang_speichern($benutzer, $passwort, $pin, $land)
{
    $p = by_paths();
    if (!is_dir($p['configdir'])) {
        @mkdir($p['configdir'], 0775, true);
    }
    $alt = by_json_lesen($p['zugang']);
    $hol = function ($neu, $name) use ($alt) {
        if ($neu !== null && $neu !== '') {
            return $neu;
        }
        return isset($alt[$name]) ? $alt[$name] : '';
    };
    $neu = array(
        'benutzer' => $benutzer !== null ? $benutzer : $hol(null, 'benutzer'),
        'passwort' => $hol($passwort, 'passwort'),
        'pin'      => $hol($pin, 'pin'),
        'land'     => $land !== null ? $land : $hol(null, 'land'),
    );
    $json = json_encode($neu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    /* Erst daneben schreiben, dann umbenennen. Die Rechte werden auf der
     * NEBENDATEI gesetzt, nicht danach - sonst laege die Datei einen
     * Augenblick lang mit den Rechten der umask da, und in ihr steht ein
     * Passwort. */
    $tmp = $p['zugang'] . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    $ok = ftruncate($fh, 0) && (@fwrite($fh, $json) === strlen($json));
    fflush($fh);
    fclose($fh);
    if (!$ok || !@rename($tmp, $p['zugang'])) {
        @unlink($tmp);
        return false;
    }
    /* Die Zweitschrift wird nur mit einem Stand fortgeschrieben, der INHALT
     * traegt: Benutzername UND Passwort. Bis 0.9.15 ging jeder gespeicherte
     * Stand hinein. War zugang.json unlesbar (abgeschnitten) und wurde mit
     * leerem Passwortfeld gespeichert - die Seite behaelt ein leeres Feld
     * absichtlich bei, nur gab es nichts zu behalten -, verdraengte ein Stand
     * ohne Passwort die heile Zweitschrift. Gemessen am 18.09.2026
     * (Pruefung-BYD-Autos-0.9.16, Fall U1). Dieselbe Regel wie fuer byd.json
     * in by_config_speichern() (dort: das Aktionstoken) und wie in den
     * Hakenskripten (by_inhalt). Geloescht wird die Zweitschrift allein
     * ueber by_zugang_loeschen(). */
    if (trim((string) $neu['benutzer']) !== '' && trim((string) $neu['passwort']) !== '') {
        by_kopie_geschuetzt($p['zugang'], $p['zugang_sicherung'], 0600);
    } elseif (is_file($p['zugang_sicherung'])) {
        by_log('Die Zugangsdaten wurden ohne Benutzername oder ohne Passwort '
             . 'gespeichert. Die Zweitschrift bleibt deshalb auf dem vorigen Stand.',
               'WARN');
    }
    return true;
}

/**
 * Loescht Benutzername, Passwort und Steuer-PIN restlos.
 *
 * Warum das ein eigenes Haekchen braucht: by_zugang_speichern() behaelt ein
 * leeres Passwortfeld absichtlich bei. Genau diese Vorsicht macht den
 * umgekehrten Weg unmoeglich; wer sich vertippt hat oder das Konto aus der
 * Hand gibt, kaeme sonst ueber die Oberflaeche nicht mehr heran.
 *
 * MIT WEG MUSS DIE ZWEITSCHRIFT. Sie liegt neben dem Konfigordner, und
 * postinstall.sh spielt sie zurueck, wenn die richtige Datei fehlt oder leer
 * ist. Wuerde hier nur zugang.json geloescht, stuende das Passwort weiterhin
 * auf der Karte und waere bei der naechsten Neuinstallation wieder da. Ein
 * Loeschen, das nicht loescht, ist schlimmer als keines.
 */
function by_zugang_loeschen()
{
    $p = by_paths();
    $ok = true;
    foreach (array($p['zugang'], $p['zugang_sicherung']) as $f) {
        if (!is_file($f)) {
            continue;
        }
        // Ueberschreiben, dann entfernen: nur ein unlink liesse den Inhalt auf
        // der Karte stehen, bis der Platz neu vergeben wird.
        $laenge = (int) @filesize($f);
        if ($laenge > 0) {
            @file_put_contents($f, str_repeat('0', $laenge));
        }
        $ok = @unlink($f) && $ok;
    }
    by_log('Die Zugangsdaten wurden auf Wunsch geloescht (samt Zweitschrift).');
    return $ok;
}

/**
 * Die letzten $anzahl Zeilen einer Datei, neueste zuerst.
 *
 * Nicht die ganze Datei einlesen und nicht exec("tail"). An einer Datei an der
 * Rotationsgrenze gemessen (12 000 Zeilen, 610 kB, je 20 Durchlaeufe):
 *
 *   file() + array_reverse    0,37 ms   zusaetzlich 2048 kB
 *   exec("tail -n 400")       2,17 ms   zusaetzlich    0 kB
 *   rueckwaerts mit fseek     0,05 ms   zusaetzlich    0 kB
 *
 * Ein Prozessstart kostet mehr, als das Einlesen je gespart hat.
 */
function by_log_ende($datei, $anzahl = 400, $block = 8192)
{
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/** Zufallstoken fuer den unangemeldeten Endpunkt. */
function by_token_erzeugen($laenge = 24)
{
    // Ohne l, o, 0 und 1: das Token wird von Hand in Loxone Config abgetippt.
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/**
 * Gibt das Token zurueck. $erzeugen = false liest nur.
 *
 * Zwei Zugriffswege, mit Absicht: der unangemeldete Endpunkt darf nichts
 * anlegen, die angemeldete Oberflaeche schon.
 */
function by_token($erzeugen = true)
{
    $cfg = by_config($erzeugen);
    $t = trim((string) $cfg['aktionstoken']);
    if ($t === '' && $erzeugen) {
        $cfg['aktionstoken'] = by_token_erzeugen();
        by_config_speichern($cfg);
        /* C9 (Durchgang 29.09.2026): nie still wuerfeln. Bis 0.9.19 entstand
         * nach dem Zurueckspielen einer Sicherung mit leerem Token beim
         * naechsten Seitenaufruf ein neues - ohne eine Protokollzeile, und
         * jede Adresse im Miniserver war stumm ungueltig (gemessen). */
        by_log('Es gab kein Aktionstoken - ein neues wurde erzeugt. Adressen im '
             . 'Miniserver, die ein frueheres Token tragen, sind damit ungueltig.', 'WARN');
        return (string) $cfg['aktionstoken'];
    }
    return $t;
}

/**
 * Merkmal gegen fremde Absender in Formularen.
 *
 * Der angemeldete Bereich ist durch die Anmeldung des LoxBerry geschuetzt -
 * gegen eine fremde Seite schuetzt das nicht: der Browser schickt die
 * hinterlegten Zugangsdaten bei einer Anfrage von aussen mit. Ein
 * untergeschobenes Formular koennte sonst "Neues Token erzeugen" ausloesen;
 * danach beantwortet der Endpunkt jeden virtuellen Ausgang mit 403 - und ein
 * virtueller Ausgang wertet die Antwort nicht aus, der Ausfall bliebe still.
 *
 * Fail closed: ohne hinterlegtes Token gibt es nichts zu vergleichen, und
 * hash_equals('', '') waere wahr.
 */
function by_formtoken($cfg = null)
{
    if ($cfg === null) {
        $cfg = by_config();
    }
    $basis = (string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '');
    if ($basis === '') {
        return '';
    }
    return hash_hmac('sha256', 'formular-v1', $basis);
}

function by_formtoken_pruefen($cfg = null)
{
    $soll = by_formtoken($cfg);
    $ist = isset($_POST['formtoken']) ? (string) $_POST['formtoken'] : '';
    if ($soll === '' || $ist === '') {
        return false;
    }
    return hash_equals($soll, $ist);
}

/**
 * U1 (Durchgang 29.09.2026): das Ergebnis eines POST reist als Einmalmeldung
 * ueber die Umleitung (303) - in data/plugins/<ordner>/einmalmeldung.json,
 * 0600, hoechstens 120 s gueltig, beim GET gelesen und dabei geloescht.
 * Bauform AudiConnect 0.9.22 (au_einmal_schreiben). Scheitert das Schreiben,
 * rendert die Seite wie bisher direkt - lieber ohne Umleitung als ohne Meldung.
 */
/* ==================================================================
 * Eingaben nach einer Beanstandung (Verbesserungsbau 30.09.2026, X-2;
 * Regeln/04 "Nach einer Beanstandung stehen die eingetippten Werte wieder
 * im Formular"). Bauform Heimkino (vb_hk, Welle 1).
 *
 * Nur nach einer Beanstandung, nur das eine Formular und nur seine Felder.
 * Nie Geheimnisse: Passwort und Steuer-PIN stehen in keiner Liste und reisen
 * deshalb nie mit - sie koennen nur markiert werden.
 * ================================================================== */

/** Die Felder je Formular: array(text => [...], haken => [...]). */
function by_eingabe_felder($form)
{
    $felder = array(
        'einstellungen' => array(
            'text'  => array('benutzer', 'land', 'intervall', 'verlauf_tage', 'temp_min',
                             'temp_max', 'wartezeit', 'abfahrt_praefix', 'abfahrt_vorlauf',
                             'abfahrt_temp', 'abfahrt_fahrzeug', 'abfahrt_alter',
                             'ladeempf_thema', 'ladeempf_grenze', 'ladeempf_alter',
                             'preisempf_quelle', 'preisempf_praefix', 'preisempf_stunden',
                             'kapazitaet', 'heim_breite', 'heim_laenge', 'heim_radius'),
            'haken' => array('gps_ein', 'mqtt_bibliothek', 'steuerung_ein', 'gefahr_ein', 'abfahrt_ein',
                             'ladeempf_ein', 'ladeempf_unter', 'preisempf_ein'),
        ),
        'mqtt' => array(
            'text'  => array('mqtt_topic'),
            'haken' => array('mqtt_ein', 'haus_fahrzeug_ein'),
        ),
        'g42' => array(
            'text'  => array('vorklima_kalt_unter', 'vorklima_heiss_ueber', 'ankunft_quelle', 'ladeempf_quelle', 'signal_praefix',
                             'kabel_soc', 'kabel_uhr', 'kabel_abfahrt_min', 'vorklima_min_soc'),
            'haken' => array('haus_abfahrt_ein', 'vorklima_anwesend_ein', 'vorklima_wetter_ein', 'ankunft_ein', 'haus_strompreis_ein',
                             'signal_ein', 'signal_laden_fertig', 'signal_ausfall', 'signal_vorklima', 'signal_kabel', 'vorklima_nur_kabel'),
        ),
    );
    /* Nr. 36 b (seit 0.9.23): die Sprachausgabe und die Anlass-Haken reisen mit - nie die Sprechtoken
     * (ansage_x2_felder() nennt sie nicht; markiert werden koennen sie). */
    $by_th = array('tts_alexa_token_loeschen', 'tts_google_token_loeschen');
    $felder['einstellungen']['text'] = array_merge($felder['einstellungen']['text'],
        array_values(array_diff(ansage_x2_felder(by_ansage_opt()), $by_th)), array('ansage_ruhe_von', 'ansage_ruhe_bis', 'ansage_sperre_min', 'ansage_tafel_namen', 'ansage_homepod_geraet'));     // 0.9.34: Tafel und HomePod dazu: ansage_regeln_formular() beanstandet sie, ohne Eintrag hier ging die Beanstandung samt Eingaben verloren.
    $felder['einstellungen']['haken'] = array_merge($felder['einstellungen']['haken'], $by_th,
        array('ansage_laden_fertig', 'ansage_ausfall', 'ansage_kabel_vergessen', 'ansage_ruhe_ein', 'ansage_tafel_ein', 'ansage_homepod_ein'));
    /* Welle Mehrfach-Ziele (0.9.29): die Tabelle der weiteren Ziele (Felder name[N], N = 2..6) - nur, wenn die
     * Abschrift sie kann; by_eingaben_sammeln() liest sie ueber by_post_feld(). */
    if (by_mit_zielen()) {
        for ($i = 2; $i <= 6; $i++) {
            foreach (array('tts_ziel_art', 'tts_ziel_wo', 'tts_ziel_laut', 'tts_ziel_sprache') as $n) {
                $felder['einstellungen']['text'][] = $n . '[' . $i . ']';
            }
            foreach (array('tts_ziel_ein', 'tts_ziel_loeschen') as $n) {
                $felder['einstellungen']['haken'][] = $n . '[' . $i . ']';
            }
        }
    }
    return isset($felder[$form]) ? $felder[$form] : null;
}

/**
 * Die eingetippten Werte eines Formulars aus $_POST, fuer die Einmalmeldung.
 * Ein Wert, der kein gueltiges UTF-8 ist oder laenger als 256 Byte, reist
 * nicht mit (sonst scheiterte json_encode und mit ihm die Umleitung) - das
 * Feld zeigt dann den gespeicherten Stand.
 */
function by_eingaben_sammeln($form, $beanstandet)
{
    $f = by_eingabe_felder($form);
    if ($f === null || !$beanstandet) {
        return null;
    }
    $werte = array();
    foreach ($f['text'] as $feld) {
        $pw = by_post_feld($feld);     // Welle Mehrfach-Ziele (0.9.29): auch name[N] der Tabelle
        if (is_string($pw) && strlen($pw) <= 256 && preg_match('//u', $pw) === 1) {
            $werte[$feld] = $pw;
        }
    }
    foreach ($f['haken'] as $feld) {
        $werte[$feld] = by_post_feld($feld) !== null ? '1' : '';
    }
    return array('form' => $form, 'werte' => $werte,
                 'beanstandet' => array_values(array_unique(array_map('strval', $beanstandet))));
}

/** Die Eingaben aus der Einmalmeldung annehmen (nur bekannte Felder, nur Text). */
function by_eingaben_setzen($roh = null)
{
    static $ein = array('form' => '', 'werte' => array(), 'beanstandet' => array());
    if ($roh === null) {
        return $ein;
    }
    if (!is_array($roh) || !isset($roh['form']) || !is_string($roh['form'])
        || by_eingabe_felder($roh['form']) === null) {
        return $ein;
    }
    $f = by_eingabe_felder($roh['form']);
    $felder = array_merge($f['text'], $f['haken']);
    // Nr. 36 b: die Sprechtoken werden markiert, ihr Wert reist nie mit.
    $erlaubt = array_merge($felder, array('passwort', 'pin', 'tts_alexa_token', 'tts_google_token'));
    $werte = array();
    if (isset($roh['werte']) && is_array($roh['werte'])) {
        foreach ($roh['werte'] as $k => $v) {
            if (in_array((string) $k, $felder, true) && is_string($v)) {
                $werte[(string) $k] = $v;
            }
        }
    }
    $bean = array();
    if (isset($roh['beanstandet']) && is_array($roh['beanstandet'])) {
        foreach ($roh['beanstandet'] as $b) {
            if (is_string($b) && in_array($b, $erlaubt, true)) {
                $bean[] = $b;
            }
        }
    }
    if ($bean) {
        $ein = array('form' => $roh['form'], 'werte' => $werte, 'beanstandet' => $bean);
    }
    return $ein;
}

/** Welches Formular zeigt gerade Eingaben ('' = keines)? */
function by_eingaben_aktiv()
{
    $ein = by_eingaben_setzen();
    return $ein['form'];
}

/** Wert eines Textfelds: die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function by_eingabe($form, $feld, $gespeichert)
{
    $ein = by_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld];
    }
    return $gespeichert;
}

/** Haken: nach einer Beanstandung der abgeschickte Stand, sonst der gespeicherte. */
function by_eingabe_an($form, $feld, $gespeichert)
{
    $ein = by_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld] === '1';
    }
    return (bool) $gespeichert;
}

/** Das beanstandete Feld wird rot umrandet (Klasse sm-beanstandet). */
function by_markierung($feld)
{
    $ein = by_eingaben_setzen();
    return in_array($feld, $ein['beanstandet'], true)
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

function by_einmal_schreiben($daten)
{
    $p = by_paths();
    if (!is_dir($p['datadir'])) {
        return false;
    }
    $daten['zeit'] = time();
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return false;
    }
    $datei = $p['datadir'] . '/einmalmeldung.json';
    $tmp = $datei . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    $ok = ftruncate($fh, 0) && (@fwrite($fh, $js) === strlen($js));
    fflush($fh);
    fclose($fh);
    if (!$ok || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function by_einmal_lesen()
{
    $f = by_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $aus = array();
    foreach (array('meldungen', 'fehler', 'stoerungen') as $k) {
        $aus[$k] = array();
        if (isset($d[$k]) && is_array($d[$k])) {
            foreach ($d[$k] as $m) {
                if (is_string($m)) {
                    $aus[$k][] = $m;
                }
            }
        }
    }
    $aus['ausgabe'] = isset($d['ausgabe']) && is_string($d['ausgabe']) ? $d['ausgabe'] : '';
    // X-2: die Eingaben eines beanstandeten Formulars (by_eingaben_setzen()
    // prueft Form und Felder erst beim Annehmen).
    $aus['eingaben'] = isset($d['eingaben']) && is_array($d['eingaben']) ? $d['eingaben'] : null;
    return $aus;
}

/**
 * C3 (Durchgang 29.09.2026): gilt die Anmeldesperre des Dienstes fuer die
 * GELTENDEN Zugangsdaten? Dieselbe Rechnung wie anmeldung_lesen() in
 * bin/byd.py: anmeldung.json traegt die sha256-Pruefsumme von zugang.json.
 * Neue Zugangsdaten aendern die Datei - dann gilt die Sperre nicht mehr.
 */
function by_anmeldesperre()
{
    $p = by_paths();
    $aus = array('gesperrt' => 0, 'anzahl' => 0);
    $a = by_json_lesen($p['datadir'] . '/anmeldung.json');
    if (!$a || empty($a['gesperrt']) || !isset($a['zugang']) || !is_string($a['zugang'])
        || !is_file($p['zugang'])) {
        return $aus;
    }
    $k = (string) @hash_file('sha256', $p['zugang']);
    if ($k === '' || !hash_equals($k, $a['zugang'])) {
        return $aus;
    }
    $aus['gesperrt'] = 1;
    $aus['anzahl'] = isset($a['fehl']) && is_array($a['fehl']) ? count($a['fehl']) : 0;
    return $aus;
}

/* ---------------- Zwischenspeicher lesen ---------------- */

function by_loxone()
{
    return by_json_lesen(by_paths()['datadir'] . '/loxone.json');
}

function by_zustand()
{
    return by_json_lesen(by_paths()['datadir'] . '/zustand.json');
}

function by_rohdaten()
{
    return by_json_lesen(by_paths()['datadir'] . '/rohdaten.json');
}

/** Fahrzeuge aus dem Abbild, 1-basiert. */
function by_fahrzeuge()
{
    $l = by_loxone();
    return isset($l['fahrzeuge']) && is_array($l['fahrzeuge']) ? $l['fahrzeuge'] : array();
}

/**
 * Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt.
 *
 * Achtung beim Weiterverwenden: -1 sieht in Loxone frischer aus als jeder
 * echte Wert. Der Endpunkt gibt deshalb bei -1 ausdruecklich OK=0 aus, und die
 * Baustein-Liste verdrahtet OK, nicht nur ALTER.
 */
function by_alter()
{
    $l = by_loxone();
    return isset($l['ts']) && (int) $l['ts'] > 0 ? max(0, time() - (int) $l['ts']) : -1;
}

/* ---------------- Dienst ---------------- */

function by_dienst_pid()
{
    $f = by_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    /* Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
     *
     * KEINE Teilstringsuche ueber die Befehlszeile. /proc/<pid>/cmdline
     * enthaelt alle Argumente, durch Nullbytes getrennt; ein strpos darueber
     * trifft auch einen Editor mit geoeffneter byd.py - und bei zwei
     * installierten Exemplaren (bydautos, bydautos_01) haelt die Oberflaeche
     * den fremden Dienst fuer den eigenen. Der Endpunkt liesse dann
     * Schreibbefehle durch, die niemand abarbeitet.
     *
     * Zwei Bedingungen, nicht eine: argv[1] ist genau unser Skript UND argv[0]
     * ist ein Python. Die zweite braucht es, weil "nano <pfad>/byd.py"
     * ebenfalls den vollen Pfad als zweites Argument fuehrt. */
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    $argv = explode("\0", $cmd);
    /* Die Befehlszeile endet auf ein Nullbyte; explode() haengt daran ein
     * leeres Element. Es wird abgeschnitten, sonst zaehlte jeder Prozess ein
     * Argument zu viel. */
    while ($argv && end($argv) === '') {
        array_pop($argv);
    }
    $skript = by_paths()['bindir'] . '/byd.py';
    /* GENAU zwei Argumente. Ein drittes macht aus dem Treffer einen
     * Einmallauf: "<venv>/bin/python3 <pfad>/byd.py --selbsttest" ist der
     * Selbsttest und laeuft Sekunden. Hier wird nur angezeigt, nicht
     * beendet - aber dieselbe Bauart steht in bin/dienst.sh und in
     * uninstall/uninstall, und dort wird sie zum Signal. In WSL gemessen am
     * 18.09.2026 (Pruefung-BYD-Autos-0.9.15, Fall D1). */
    if (count($argv) === 2
        && $argv[1] === $skript
        && preg_match('#(^|/)python[0-9.]*$#', $argv[0])) {
        return $pid;
    }
    return 0;
}

function by_dienst_soll()
{
    return is_file(by_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function by_dienst($befehl)
{
    /* U10 (Durchgang 29.09.2026): die Texte stehen in den Sprachdateien; bis
     * 0.9.19 kamen sie auch in der englischen Oberflaeche deutsch. */
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, by_t('MELDUNG.DIENST_UNBEKANNT'));
    }
    if (!function_exists('exec')) {
        // Eine Absicherung, die genau dann zuschlaegt, wenn sie nicht messen
        // kann, ist keine - deshalb wird der Grund benannt.
        return array(0, by_t('MELDUNG.EXEC_DIENST'));
    }
    $skript = by_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, sprintf(by_t('MELDUNG.DIENSTSKRIPT_FEHLT'), $skript));
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellarg($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/**
 * Fassung von pybyd in der virtuellen Umgebung, oder ''.
 *
 * Statisch gemerkt: ohne das startet jeder Seitenaufruf mehrere
 * Python-Prozesse (der Kopf der Seite fragt, und der Reiter Test fragt
 * erneut), und jeder kostet rund 100 ms.
 */
function by_bibliothek_fassung()
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $f = '';
    $py = by_paths()['bindir'] . '/venv/bin/python3';
    if (!is_file($py) || !function_exists('exec')) {
        return $f;
    }
    $ausgabe = array();
    @exec(escapeshellarg($py) . ' -c ' . escapeshellarg(
        'import importlib.metadata as m' . "\n"
        . 'try: print(m.version("pybyd"))' . "\n"
        . 'except Exception: print("")'
    ) . ' 2>/dev/null', $ausgabe);
    $f = isset($ausgabe[0]) ? trim($ausgabe[0]) : '';
    return $f;
}

/** Fassung des Python in der virtuellen Umgebung, oder ''. */
function by_python_fassung()
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $v = '';
    $py = by_paths()['bindir'] . '/venv/bin/python3';
    if (!is_file($py) || !function_exists('exec')) {
        return $v;
    }
    $ausgabe = array();
    @exec(escapeshellarg($py) . ' -c ' . escapeshellarg(
        'import sys; print("%d.%d.%d" % sys.version_info[:3])'
    ) . ' 2>/dev/null', $ausgabe);
    $v = trim(implode('', $ausgabe));
    return $v;
}

/** Ruft byd.py mit einem Schalter auf und gibt die Ausgabe zurueck. */
/* $code nimmt den Rueckgabecode des Aufrufs auf - 0 heisst gelungen.
 *
 * Der Parameter ist neu und der Grund dafuer ein Befund: der Aufrufer im
 * Reiter Test verdrahtete eine 1 fest ("Erfolg"), ohne je nachzusehen, was
 * herauskam. Fehlte die virtuelle Umgebung oder war exec() gesperrt, stand ein
 * gruener Haken ueber einer Fehlermeldung. Die beiden Fehlerzweige hier setzen
 * den Code deshalb ausdruecklich - nicht nur den Text. */
function by_python_ruf($schalter, &$code = null)
{
    $code = 1;
    $p = by_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/byd.py';
    if (!is_file($py) || !is_file($skript)) {
        return '[FEHL] ' . sprintf(by_t('MELDUNG.VENV_FEHLT'), $py, $skript);
    }
    if (!function_exists('exec')) {
        return '[FEHL] ' . by_t('MELDUNG.EXEC_GESPERRT');
    }
    $ausgabe = array();
    $rc = 1;
    @exec(escapeshellarg($py) . ' ' . escapeshellarg($skript) . ' '
        . escapeshellarg($schalter) . ' 2>&1', $ausgabe, $rc);
    $code = (int) $rc;
    return implode("\n", $ausgabe);
}

function by_selbsttest()
{
    return by_python_ruf('--selbsttest');
}

/* ---------------- Gleichwert-Unterdrueckung fuer Sollwerte (X-7) ----------------
 *
 * B-Nachzug 01.10.2026, Entscheidung Nr. 19: derselbe Sollwert fuer dasselbe
 * Fahrzeug innerhalb von 60 s wird nicht noch einmal eingereiht - der
 * Endpunkt antwortet HTTP 200 mit UNVERAENDERT=1. Kein zusaetzliches 429:
 * ein anderer Wert geht sofort hinaus. Die Bremse des Dienstes (C5: PIN-
 * Sperre, hoechstens 30 schaltende Befehle je Stunde, 20 je Takt) bleibt,
 * wie sie ist; diese Unterdrueckung kommt davor.
 *
 * Warum: Loxone sendet einen Ausgang bei jeder Aenderung, und ein
 * flatternder Baustein sendet denselben Wert mehrmals. Jeder Befehl weckt das
 * Fahrzeug, verlangt die Steuer-PIN und zaehlt gegen die Stundengrenze -
 * nach 30 gleichen Befehlen waere eine Stunde lang auch der eine noetige
 * gesperrt.
 *
 * Gebremst werden nur Sollwerte: Klima an (samt Temperatur und Laufzeit) und
 * aus, Klimaplan, Ver-/Entriegeln, Sitzklima- und Batterieheizungsstufe,
 * Fenster schliessen. Nicht: Abruf, Suchen (Hupe/Licht), Blinken.
 *
 * Bauform AudiConnect (B-Nachzug 01.10.2026): Merker unter flock, geoeffnet
 * mit "e" (close-on-exec), faellt geschlossen aus (503). Der Befehl wird VOR
 * dem Einreihen vorgemerkt, und die Sperre wird nicht ueber das Warten auf den
 * Dienst gehalten (bis 30 s): ein zweiter gleicher Aufruf waehrend des
 * Wartens ist damit schon "unveraendert". Lehnt der Dienst ab (OK=0), wird
 * der Eintrag wieder verworfen; OK=2 (eingereiht, Ergebnis unbekannt) bleibt
 * gemerkt - ein zweiter Befehl waere genau die Mehrfachanfrage, vor der die
 * Meldung warnt.
 */
define('BY_GLEICHWERT_S', 60);

/** Pfad des Merkers. */
function by_gleichwert_datei()
{
    return by_paths()['datadir'] . '/gleichwert.json';
}

/** Eine Zahl als Vergleichstext: "21", "21.0" und "21,0" sind derselbe Wert. */
function by_gleichwert_zahl($w)
{
    if (!is_scalar($w) || trim((string) $w) === '') {
        return '';
    }
    $s = str_replace(',', '.', trim((string) $w));
    return is_numeric($s) ? (string) (float) $s : $s;
}

/**
 * Gruppe und Sollwert eines Befehls (wie er in die Warteschlange ginge).
 * null = nicht gebremst (Ereignis, Taster, Abruf, Unbekanntes).
 */
function by_gleichwert_gruppe(array $befehl)
{
    $aktion = isset($befehl['aktion']) ? (string) $befehl['aktion'] : '';
    $temp = isset($befehl['temp']) ? by_gleichwert_zahl($befehl['temp']) : '';
    $minuten = isset($befehl['minuten']) ? by_gleichwert_zahl($befehl['minuten']) : '';
    $stufe = isset($befehl['stufe']) ? by_gleichwert_zahl($befehl['stufe']) : '';
    switch ($aktion) {
        case 'klima_start':
            // Die Temperatur gehoert zum Sollwert: 21 und 22 sind zwei Befehle.
            return array('klima', 'an|' . $temp . '|' . $minuten);
        case 'klima_stop':
            return array('klima', 'aus');
        case 'klima_plan':
            return array('klimaplan', $temp . '|' . $minuten);
        case 'verriegeln':
            return array('schloss', 'zu');
        case 'entriegeln':
            return array('schloss', 'auf');
        case 'sitzklima':
        case 'batterieheizung':
            return array($aktion, $stufe);
        case 'fenster_zu':
            return array('fenster', 'zu');
    }
    return null;
}

/**
 * Der Schluessel des Fahrzeugs: die VIN, sonst die Nummer. So trifft ein
 * Aufruf mit fahrzeug=1 denselben Eintrag wie einer mit der VIN.
 */
function by_gleichwert_fz($fz, $ersatz)
{
    if (is_array($fz) && isset($fz['vin']) && is_string($fz['vin']) && trim($fz['vin']) !== '') {
        return strtoupper(trim($fz['vin']));
    }
    return 'nr' . preg_replace('/[^0-9A-Za-z]/', '', (string) $ersatz);
}

/**
 * Das Urteil - rein, ohne Datei.
 * Rueckgabe: array('' | 'UNVERAENDERT', Sekunden seit dem gemerkten Befehl).
 */
function by_gleichwert_urteil(array $m, $schluessel, $wert, $jetzt)
{
    if (!isset($m[$schluessel]) || !is_array($m[$schluessel])
        || !isset($m[$schluessel]['w'], $m[$schluessel]['t'])
        || !is_scalar($m[$schluessel]['w']) || !is_scalar($m[$schluessel]['t'])) {
        return array('', 0);
    }
    $seit = (int) $jetzt - (int) $m[$schluessel]['t'];
    // Uhr zurueckgesprungen: der Merker sagt nichts mehr.
    if ($seit < 0) {
        return array('', 0);
    }
    if ((string) $m[$schluessel]['w'] === (string) $wert && $seit < BY_GLEICHWERT_S) {
        return array('UNVERAENDERT', $seit);
    }
    return array('', $seit);
}

/**
 * Vor dem Einreihen. Rueckgabe: array(Urteil, Sekunden, Marke).
 *   'UNVERAENDERT' - derselbe Wert ging vor weniger als 60 s hinaus;
 *   'MERKER'       - der Merker laesst sich nicht oeffnen, sperren oder
 *                    schreiben: geschlossen ausfallen;
 *   ''             - einreihen; der Befehl ist dann unter der Marke vorgemerkt.
 */
function by_gleichwert_pruefen($schluessel, $wert)
{
    $f = by_gleichwert_datei();
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    $fh = @fopen($f, 'c+e');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        return array('MERKER', 0, '');
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($m)) {
        $m = array();     // unlesbar gilt als leer: im Zweifel senden
    }
    $jetzt = time();
    list($urteil, $seit) = by_gleichwert_urteil($m, $schluessel, $wert, $jetzt);
    if ($urteil !== '') {
        flock($fh, LOCK_UN);
        fclose($fh);
        return array($urteil, $seit, '');
    }
    // Abgelaufene Eintraege fallen heraus - der Merker bleibt klein.
    foreach ($m as $k => $e) {
        if (!is_array($e) || !isset($e['t']) || !is_scalar($e['t'])
            || $jetzt - (int) $e['t'] >= BY_GLEICHWERT_S || (int) $e['t'] > $jetzt) {
            unset($m[$k]);
        }
    }
    $marke = bin2hex(random_bytes(6));
    $m[$schluessel] = array('w' => (string) $wert, 't' => $jetzt, 'm' => $marke);
    $js = json_encode($m);
    $ok = $js !== false && ftruncate($fh, 0) && rewind($fh)
          && fwrite($fh, $js) === strlen($js) && fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (!$ok) {
        // Nicht vermerkt heisst: die Unterdrueckung wuerde den naechsten
        // gleichen Befehl nicht erkennen. Geschlossen ausfallen.
        return array('MERKER', 0, '');
    }
    return array('', 0, $marke);
}

/**
 * Einen Eintrag verwerfen - nach OK=0, oder weil ein Befehl an der
 * Unterdrueckung vorbei ging (Reiter Test). $marke '' verwirft jeden Eintrag
 * des Schluessels, sonst nur den eigenen. Rueckgabe: true, wenn danach kein
 * passender Eintrag mehr steht.
 */
function by_gleichwert_vergessen($schluessel, $marke = '')
{
    $f = by_gleichwert_datei();
    clearstatcache(true, $f);
    if (!is_file($f)) {
        return true;
    }
    $fh = @fopen($f, 'c+e');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        return false;
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    $ok = true;
    if (is_array($m) && isset($m[$schluessel]) && ($marke === ''
            || (is_array($m[$schluessel]) && isset($m[$schluessel]['m'])
                && (string) $m[$schluessel]['m'] === (string) $marke))) {
        unset($m[$schluessel]);
        $js = json_encode($m);
        $ok = $js !== false && ftruncate($fh, 0) && rewind($fh)
              && fwrite($fh, $js) === strlen($js) && fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - also Ergebnis unbekannt.
 * Es wird bewusst kein Erfolg gemeldet, den niemand geprueft hat.
 */
function by_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = by_paths();
    $cfg = by_config(false);
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    $wartezeit = max(0, min(30, (int) $wartezeit));

    // Ohne laufenden Dienst wird NICHT eingereiht: der Befehl laege bis zum
    // naechsten Start in der Warteschlange und wirkte dann Stunden spaeter am
    // Fahrzeug. Ein Befehl mit Verfallsdatum ist besser als eine Ueberraschung.
    if (by_dienst_pid() === 0) {
        return array(0, by_t('MELDUNG.KEIN_DIENST'));
    }

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, sprintf(by_t('MELDUNG.ORDNER_FEHLT'), $ordner));
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp.' . getmypid();
    $befehl['ts'] = time();
    $by_js = json_encode($befehl);
    if ($by_js === false) {
        return array(0, by_t('MELDUNG.KEIN_JSON'));
    }
    if (@file_put_contents($tmp, $by_js) !== strlen($by_js) || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, sprintf(by_t('MELDUNG.NICHT_ABGELEGT'), $datei));
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = by_json_lesen($antwort);
            // Gelesen ist erledigt - sonst sammeln sich die Antworten im
            // Datenordner an, bis der Dienst sie wegraeumt.
            @unlink($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    return array(2, sprintf(by_t('MELDUNG.OHNE_ANTWORT'), $wartezeit));
}

/* ---------------- Verlauf ---------------- */

/** Messpunkte eines Tages: Array von array(ts, ladezustand, reichweite). */
function by_verlauf_lesen($nummer, $tag = '')
{
    if ($tag === '') {
        $tag = date('Ymd');
    }
    $f = by_paths()['datadir'] . '/verlauf/fahrzeug' . (int) $nummer . '_' . $tag . '.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
            $c = explode(';', $zeile);
            if (count($c) >= 2 && $c[1] !== '') {
                $out[] = array((int) $c[0], (float) $c[1],
                               isset($c[2]) && $c[2] !== '' ? (float) $c[2] : 0);
            }
        }
    }
    return $out;
}

/**
 * Die aufgezeichneten Ladevorgaenge, neueste zuerst.
 *
 * Sie werden wirklich abgelegt und nicht nur angezeigt: beim Schwesterplugin
 * Renault holt history.php die Ladevorgaenge alle zehn Minuten und speichert
 * sie NICHT - sie existieren dort nur als MQTT-Wert und als Klartext eines
 * Handaufrufs, waehrend der Reiter "Ladehistorie" eine ganz andere Datei
 * zeichnet. Ein Reiter, der etwas anderes zeigt als sein Name sagt, ist
 * schlimmer als keiner.
 *
 * Rueckgabe je Zeile: fahrzeug, start, ende, dauer, soc_start, soc_ende, km, kwh.
 */
function by_ladungen_lesen($grenze = 200)
{
    $f = by_paths()['datadir'] . '/verlauf/ladungen.csv';
    if (!is_file($f)) {
        return array();
    }
    $aus = array();
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
        if ($zeile === '' || $zeile[0] === '#') {
            continue;
        }
        $c = explode(';', $zeile);
        if (count($c) < 8) {
            continue;
        }
        $aus[] = array(
            'fahrzeug'  => $c[0],
            'start'     => (int) $c[1],
            'ende'      => (int) $c[2],
            'dauer'     => $c[3] === '' ? null : (int) $c[3],
            'soc_start' => $c[4] === '' ? null : (float) $c[4],
            'soc_ende'  => $c[5] === '' ? null : (float) $c[5],
            'km'        => $c[6] === '' ? null : (float) $c[6],
            'kwh'       => $c[7] === '' ? null : (float) $c[7],
            // Gruppe 42 (G6): Kosten und Durchschnittspreis einer Ladung zu Hause; alte Zeilen ohne Spalte -> null.
            'kosten'    => isset($c[8]) && is_numeric($c[8]) ? (float) $c[8] : null,
            'preis'     => isset($c[9]) && is_numeric($c[9]) ? (float) $c[9] : null,
        );
    }
    // Neueste zuerst, und nur so viele, wie die Seite tragen kann.
    usort($aus, function ($a, $b) {
        return $b['start'] - $a['start'];
    });
    return array_slice($aus, 0, max(1, (int) $grenze));
}

/**
 * BYD-b1 (Verbesserungsbau 30.09.2026): die Ladevorgaenge als CSV fuer den
 * Knopf "Ladevorgaenge herunterladen" im Reiter Ladevorgaenge.
 *
 * Dieselbe Lesefunktion wie die Tabelle (by_ladungen_lesen), aber ALLE
 * Zeilen und die aelteste zuerst. Zeiten als Ortszeit 'Y-m-d H:i:s', Zahlen
 * mit Dezimalpunkt, ein leeres Feld heisst "nicht bekannt" (wie in der Datei
 * des Dienstes - eine 0 saehe aus wie eine Messung). Ohne Ladevorgang steht
 * nur die Kopfzeile da. Die Spaltennamen sind Kennungen, keine Saetze; sie
 * sind die der Datei verlauf/ladungen.csv.
 */
function by_ladungen_csv()
{
    $zeilen = array('fahrzeug;start;ende;dauer_min;soc_start;soc_ende;km;kwh');
    $zahl = function ($w) {
        if ($w === null) {
            return '';
        }
        $s = rtrim(rtrim(sprintf('%.3F', (float) $w), '0'), '.');
        return $s === '-0' ? '0' : $s;
    };
    foreach (array_reverse(by_ladungen_lesen(PHP_INT_MAX)) as $l) {
        $zeilen[] = implode(';', array(
            // Nur Kennungszeichen: die Nummer bzw. Fahrgestellnummer. Ein
            // Trennzeichen oder ein Formelanfang kaeme so nie in die Tabelle.
            preg_replace('/[^A-Za-z0-9_.]/', '', (string) $l['fahrzeug']),
            date('Y-m-d H:i:s', (int) $l['start']),
            date('Y-m-d H:i:s', (int) $l['ende']),
            $l['dauer'] === null ? '' : (string) (int) $l['dauer'],
            $zahl($l['soc_start']),
            $zahl($l['soc_ende']),
            $zahl($l['km']),
            $zahl($l['kwh']),
        ));
    }
    return implode("\n", $zeilen) . "\n";
}

/**
 * Der Zustand des Horchers, wie ihn der Dienst hinterlegt hat.
 *
 * Die Oberflaeche baut KEINE eigene Verbindung zum Broker auf: sie liest, was
 * der Dienst zuletzt gesehen hat. Zwei Verbindungen zum selben Broker aus zwei
 * Prozessen waeren zwei Stellen, die auseinanderlaufen - und die Oberflaeche
 * wird bei jedem Klick gerendert.
 */
/**
 * Welche FREMDEN Themen braucht das Plugin gerade?
 *
 * Das ist die Erwartung der Oberflaeche. Der Dienst fuehrt dieselbe Liste in
 * horcher_themen() - und genau deshalb steht sie hier ein zweites Mal: die
 * Selbstpruefung vergleicht diese Erwartung mit dem, was der Dienst wirklich
 * abonniert hat. Ein Abo, das der Dienst nach einer Aenderung nicht
 * nachgezogen hat, ist unsichtbar, solange niemand beide Listen nebeneinander
 * legt: die Vorklimatisierung loest dann einfach nie aus, und das sieht aus
 * wie "der Assistent sendet nichts".
 */
/**
 * Der Klartext einer Herkunft.
 *
 * Bewusst eine Zuordnung und kein zweiwertiger Ausdruck: als die gerechneten
 * Felder dazukamen, stand in der Spalte Herkunft an fuenf Zeilen "gemessen" -
 * ein Ausdruck der Form "ist es doku? sonst gemessen" behauptet fuer jede
 * dritte Klasse etwas Falsches. Eine unbekannte Herkunft wird BENANNT und
 * nicht der harmlosesten Klasse zugeschlagen.
 */
function by_herkunft_text($quelle)
{
    $karte = array(
        'doku'      => 'LOX.HERKUNFT_DOKU',
        'bestand'   => 'LOX.HERKUNFT_BESTAND',
        'gerechnet' => 'LOX.HERKUNFT_GERECHNET',
    );
    $q = (string) $quelle;
    return isset($karte[$q]) ? by_t($karte[$q])
                             : sprintf(by_t('LOX.HERKUNFT_UNBEKANNT'), $q);
}

/**
 * Laden-1 (Verbesserungsbau 30.09.2026): die Preisquellen und ihr
 * Vorgabepraefix - nachgelesen in Spotpreis-aWATTar 1.2.30 (mqtt_topic
 * 'spot_awattar') und Spotpreis-Tibber 0.9.24 (mqtt_topic 'tibber').
 * Gegenstueck zu PREIS_QUELLEN in bin/byd.py.
 */
function by_preis_quellen()
{
    return array('awattar' => 'spot_awattar', 'tibber' => 'tibber');
}

/** Praefix der Preisquelle - dieselbe Regel wie preis_praefix() im Dienst. */
function by_preis_praefix($cfg)
{
    $q = by_preis_quellen();
    if (isset($cfg['preisempf_quelle']) && $cfg['preisempf_quelle'] === 'haus') {
        return 'haus/strompreis';   // Gruppe 42 (E9a): Rang aus haus/strompreis/vorschau
    }
    $quelle = isset($cfg['preisempf_quelle']) && is_string($cfg['preisempf_quelle'])
        && isset($q[$cfg['preisempf_quelle']]) ? $cfg['preisempf_quelle'] : 'awattar';
    $roh = trim(trim((string) (isset($cfg['preisempf_praefix']) && is_scalar($cfg['preisempf_praefix'])
        ? $cfg['preisempf_praefix'] : '')), '/');
    if ($roh === '' || preg_match('#[\#+\s]#', $roh) || strpos($roh, '//') !== false) {
        return $q[$quelle];
    }
    return $roh;
}

/** Die fremden Themen von Laden-1 - Gegenstueck zu preis_themen() im Dienst. */
function by_preis_themen($cfg)
{
    if (empty($cfg['preisempf_ein']) || (isset($cfg['preisempf_quelle']) && $cfg['preisempf_quelle'] === 'haus')) {
        return array();   // Gruppe 42: die Haus-Themen liefert by_haus_quellen()
    }
    $p = by_preis_praefix($cfg);
    return array($p . '/rank', $p . '/rankd', $p . '/status/ok', $p . '/status/ts',
                 $p . '/status/zaehler');
}

/** Der Stand, den der Dienst zuletzt gerechnet hat (preisempfehlung.json). */
function by_preisempfehlung_stand()
{
    return by_json_lesen(by_paths()['datadir'] . '/preisempfehlung.json');
}

/** Schluessel, die in einer aelteren Sicherung fehlen duerfen (Laden-1). */
function by_sicherung_spaeter()
{
    // Nr. 36 b (seit 0.9.23): eine Sicherung von 0.9.22 oder frueher kennt die Sprachausgabe nicht.
    // Gruppe 42 (0.9.29): eine Sicherung von 0.9.28 oder frueher kennt die Schluessel der Hausform nicht -
    // die Anlage behaelt ihren jetzigen Wert (auch endpunkt_ein).
    return array_merge(array('preisempf_ein', 'preisempf_quelle', 'preisempf_praefix',
                             'preisempf_stunden'), by_ansage_neue_schluessel(), by_neue_schluessel_0929());
}

function by_horcher_themen($cfg = null)
{
    if ($cfg === null) {
        $cfg = by_config(false);
    }
    $t = array();
    if (!empty($cfg['abfahrt_ein'])) {
        $pfad = trim((string) (isset($cfg['abfahrt_praefix']) ? $cfg['abfahrt_praefix'] : ''), '/');
        if ($pfad !== '') {
            $t[] = $pfad . '/ABFAHRT_IN';
            $t[] = $pfad . '/OK';
        }
    }
    if (!empty($cfg['ladeempf_ein'])) {
        $th = trim((string) (isset($cfg['ladeempf_thema']) ? $cfg['ladeempf_thema'] : ''));
        if ($th !== '' && (!isset($cfg['ladeempf_quelle']) || $cfg['ladeempf_quelle'] !== 'haus')) {
            $t[] = $th;
        }
    }
    // Laden-1: Rang und Lebenszeichen der Preisquelle.
    $t = array_values(array_unique(array_merge($t, by_preis_themen($cfg))));
    // Gruppe 42: die Haus-Themen - dieselbe Bedingung wie haus_themen() in bin/byd.py.
    $q = by_haus_quellen($cfg);
    if (isset($q['abfahrt'])) {
        $t = array_merge($t, array('haus/abfahrt/in_min', 'haus/abfahrt/zaehler'));
    }
    if (isset($q['anwesenheit'])) {
        if (!empty($cfg['abfahrt_ein']) && !empty($cfg['vorklima_anwesend_ein'])) {
            $t[] = 'haus/anwesenheit/jemand';
        }
        if (!empty($cfg['ankunft_ein']) && preg_match('/^(tag|person):[A-Za-z0-9_-]{1,40}(,(tag|person):[A-Za-z0-9_-]{1,40}){0,3}$/', (string) $cfg['ankunft_quelle'])
            && !preg_match('/(^|,)person:(jemand|zaehler)(,|$)/', (string) $cfg['ankunft_quelle'])) {
            foreach (explode(',', (string) $cfg['ankunft_quelle']) as $by_teil) {
                list($by_art, $by_name) = explode(':', $by_teil, 2);
                $t[] = $by_art === 'tag' ? 'haus/tag/' . $by_name . '/da' : 'haus/anwesenheit/' . $by_name;
            }
        }
        $t[] = 'haus/anwesenheit/zaehler';
    }
    if (isset($q['strompreis'])) {
        foreach (array('vorschau', 'ab', 'raster', 'aktuell', 'zaehler') as $by_x) {
            $t[] = 'haus/strompreis/' . $by_x;
        }
    }
    $t = array_values(array_unique($t));
    sort($t);
    return $t;
}

function by_horcher_zustand()
{
    $z = by_zustand();
    return array(
        'themen'    => isset($z['horcher']) && is_array($z['horcher']) ? $z['horcher'] : array(),
        'verbunden' => !empty($z['horcher_verbunden']) ? 1 : 0,
        'fehler'    => isset($z['horcher_fehler']) ? (string) $z['horcher_fehler'] : '',
    );
}

/* ---------------- MQTT-Gateway ----------------
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
 * eingeschaltet.
 *
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt. Eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen.
 * Massgeblich ist Gatewayautostart - der Schluessel heisst genau so; fuenf
 * Plugins des Bestands haben "Autostart" gesucht, das es nicht gibt, und
 * daraufhin auf JEDER einwandfrei eingerichteten Anlage gewarnt.
 */
function by_mqtt_zustand()
{
    $p = by_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'fassung' => 0, 'udpport' => 0,
                  'broker' => '', 'brokerport' => '', 'websocket' => '');
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = by_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) {
            return $m[$gross];
        }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    $auto = $hol('Gatewayautostart', 'gatewayautostart');
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $auto, array('1', 'true'), true) ? 1 : 0,
        /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
         * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
         * erscheint die Themengruppe von selbst in den Subscriptions.
         * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
         * sondern es werden beide Faelle genannt. */
        'fassung'    => (int) $hol('Gatewayversion', 'gatewayversion'),
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'websocket'  => (string) $hol('Websocketport', 'websocketport'),
    );
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function by_abo_text()
{
    $m = by_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return by_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(by_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return by_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
}


/* ==================================================================
 * DIE FELDTABELLE - EINE QUELLE FUER ALLES
 *
 * Aus dieser einen Tabelle entstehen:
 *   - die Statuszeile des Endpunkts (by_statuszeile)
 *   - die Suchmuster fuer Loxone (by_check)
 *   - die Tabelle im Reiter "Einbindung in Loxone"
 *   - die Importdatei fuer Loxone Config (by_vorlage)
 *   - die MQTT-Themenliste (by_mqtt_themen)
 *
 * In einem Schwesterplugin standen dieselben Feldnamen an vier Stellen: im
 * printf des Endpunkts, in der Feldtabelle, im MQTT-Zweig und in der
 * Themenliste. Gemessen legte die Vorlage 20 virtuelle Eingaenge an, die Zeile
 * lieferte 17 und MQTT 15 - und die drei fehlenden waren ausgerechnet die
 * gerechneten Groessen. Der Anwender importiert eine Reichweite, die dauerhaft
 * auf 0 steht, und sucht den Fehler bei seinem Auto.
 *
 * Je Feld:
 *   einheit  fuer Anzeige und Vorlage
 *   bez      Sprachschluessel der Bedeutung
 *   zeile    1 = in der Statuszeile, 0 = nur MQTT und aktion=json
 *   quelle   'doku'    aus einer offenen Quelle, an KEINEM Fahrzeug dieses
 *                      Hauses gemessen
 *            'bestand' im Betrieb gegen eine echte Antwort geprueft.
 *                      Stand 15.09.2026: die Felder, die das hier tragen,
 *                      sind in der anonymisierten Rohausgabe eines BYD
 *                      Seal U Design vom 14.09.2026 aufgeloest worden
 *                      (Issue #1). 'bestand' sagt: dieser
 *                      Schluessel kam in einer echten Antwort vor und trug
 *                      einen Wert - NICHT, was die Zahl bedeutet. Wo die
 *                      Schnittstelle nur eine Kennzahl liefert, sagt das
 *                      die Bezeichnung.
 *   min/max  Grenzen fuer die Importdatei; null heisst "keine Angabe"
 *
 * NEUE FELDER GEHOEREN ANS ENDE. Sonst verschiebt sich die Reihenfolge in der
 * Statuszeile, und jede beim Anwender eingetragene Befehlserkennung zeigt auf
 * den falschen Wert.
 * ================================================================== */
function by_felder()
{
    return array(
        'OK'        => array('einheit' => '',     'bez' => 'BY_FELD.OK',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 1),
        'SOC'       => array('einheit' => '%',    'bez' => 'BY_FELD.SOC',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 100),
        'KM'        => array('einheit' => 'km',   'bez' => 'BY_FELD.KM',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 2000000),
        'REICHW'    => array('einheit' => 'km',   'bez' => 'BY_FELD.REICHW',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 1500),
        'LAEDT'     => array('einheit' => '',     'bez' => 'BY_FELD.LAEDT',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 1),
        'KABEL'     => array('einheit' => '',     'bez' => 'BY_FELD.KABEL',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 1),
        'LADEZUST'  => array('einheit' => '',     'bez' => 'BY_FELD.LADEZUST',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 255),
        'RESTMIN'   => array('einheit' => 'min',  'bez' => 'BY_FELD.RESTMIN',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 6000),
        'TEMPO'     => array('einheit' => 'km/h', 'bez' => 'BY_FELD.TEMPO',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 300),
        'FAHRZUST'  => array('einheit' => '',     'bez' => 'BY_FELD.FAHRZUST',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 255),
        'ONLINE'    => array('einheit' => '',     'bez' => 'BY_FELD.ONLINE',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 255),
        'ZUENDUNG'  => array('einheit' => '',     'bez' => 'BY_FELD.ZUENDUNG',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 255),
        'SCHLOSSVL' => array('einheit' => '',     'bez' => 'BY_FELD.SCHLOSSVL',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 255),
        'BATTHEIZ'  => array('einheit' => '',     'bez' => 'BY_FELD.BATTHEIZ',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 255),
        'SITZHEIZ'  => array('einheit' => '',     'bez' => 'BY_FELD.SITZHEIZ',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0,  'max' => 255),
        'ALTER'     => array('einheit' => 's',    'bez' => 'BY_FELD.ALTER',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => -1, 'max' => 999999),
        /* --------------------------------------------------------------
         * Ab hier die GERECHNETEN Groessen. Sie stehen am ENDE, und das ist
         * keine Kosmetik: die Reihenfolge dieser Tabelle ist die Reihenfolge
         * der Statuszeile. Eine Einfuegung in der Mitte verschiebt bei jedem
         * Anwender jede Befehlserkennung - lautlos, denn eine Zahl sieht immer
         * richtig aus.
         *
         * 'gerechnet' heisst: das Plugin bildet den Wert selbst. Er ist damit
         * nicht besser belegt als seine Zutaten - fehlt eine, entsteht KEIN
         * Wert und keine 0.
         * -------------------------------------------------------------- */
        'FEHLFOLGE' => array('einheit' => '',     'bez' => 'BY_FELD.FEHLFOLGE',
                             'zeile' => 1, 'quelle' => 'gerechnet', 'min' => 0, 'max' => 9999),
        'ZUHAUSE'   => array('einheit' => '',     'bez' => 'BY_FELD.ZUHAUSE',
                             'zeile' => 1, 'quelle' => 'gerechnet', 'min' => 0, 'max' => 1),
        'VERBRAUCH' => array('einheit' => 'kWh/100km', 'bez' => 'BY_FELD.VERBRAUCH',
                             'zeile' => 1, 'quelle' => 'gerechnet', 'min' => 0, 'max' => 100),
        'LADEEMPF'  => array('einheit' => '',     'bez' => 'BY_FELD.LADEEMPF',
                             'zeile' => 1, 'quelle' => 'gerechnet', 'min' => 0, 'max' => 1),
        'LADEKWH'   => array('einheit' => 'kWh',  'bez' => 'BY_FELD.LADEKWH',
                             'zeile' => 1, 'quelle' => 'gerechnet', 'min' => 0, 'max' => 500),
        // Standort: nur ueber aktion=position und MQTT. Ein Breitengrad in der
        // Statuszeile waere ein Wert mit Punkt zwischen Ganzzahlen - und die
        // Zeile wird von Loxone mit einer Befehlserkennung gelesen.
        'BREITE'    => array('einheit' => '',     'bez' => 'BY_FELD.BREITE',
                             'zeile' => 0, 'quelle' => 'bestand', 'min' => -90,  'max' => 90),
        'LAENGE'    => array('einheit' => '',     'bez' => 'BY_FELD.LAENGE',
                             'zeile' => 0, 'quelle' => 'bestand', 'min' => -180, 'max' => 180),
        /* --------------------------------------------------------------
         * NEU 13.09.2026 - und deshalb hier ganz unten. Die Reihenfolge
         * dieser Tabelle ist die Reihenfolge der Statuszeile; angehaengt
         * verschiebt nichts, eingefuegt verschiebt bei jedem Anwender jede
         * Befehlserkennung.
         *
         * Reifendruecke: die Namen der Gegenstelle stammen aus der
         * Rohausgabe eines BYD Seal U Design (Issue #1),
         * "leftFrontTirepressure": 2.7. Seit 0.9.28 stehen sie IMMER in bar:
         * der Dienst rechnet nach tire_press_unit (REIFENEH: 1 = bar,
         * 2 = psi, 3 = kPa) um, reifendruck_bar() in bin/byd.py (Issue #1:
         * 14.09.2026 Kennung 1 bei 2.7, 19.10.2026 Kennung 3 bei 277).
         * Unplausibel (unbekannte Kennung und mehr als 20) geht -1 hinaus -
         * deshalb min -1: Loxone zieht daraus MinVal/Signed der Vorlage.
         *
         * VERBRBYD ist der Durchschnittsverbrauch, den das FAHRZEUG selbst
         * ausweist ("totalEnergy": "17.6kW-h/100km"). VERBRAUCH weiter oben
         * bleibt der vom Plugin GERECHNETE Wert der letzten Fahrt. Zwei
         * Zahlen, zwei Herkuenfte, zwei Felder.
         * -------------------------------------------------------------- */
        'REIFENVL'  => array('einheit' => 'bar',  'bez' => 'BY_FELD.REIFENVL',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => -1, 'max' => 6),
        'REIFENVR'  => array('einheit' => 'bar',  'bez' => 'BY_FELD.REIFENVR',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => -1, 'max' => 6),
        'REIFENHL'  => array('einheit' => 'bar',  'bez' => 'BY_FELD.REIFENHL',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => -1, 'max' => 6),
        'REIFENHR'  => array('einheit' => 'bar',  'bez' => 'BY_FELD.REIFENHR',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => -1, 'max' => 6),
        'VERBRBYD'  => array('einheit' => 'kWh/100km', 'bez' => 'BY_FELD.VERBRBYD',
                             'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 100),
        /* --------------------------------------------------------------
         * NEU 15.09.2026 - und wieder ganz unten, aus demselben Grund wie
         * der Block darueber: die Reihenfolge dieser Tabelle ist die
         * Reihenfolge der Statuszeile. Angehaengt verschiebt nichts.
         *
         * Jeder dieser Namen ist in einer ECHTEN Antwort aufgeloest worden
         * (BYD Seal U Design, Issue #1, Rohausgabe vom
         * 14.09.2026, anonymisiert). Gemessen wird das mit der Feldtabelle
         * des Dienstes selbst - Pruefung-BYD-Autos-0.9.11/rohdaten_messen.py.
         *
         * INNENTEMP und VERBR50 sind Messwerte. Die uebrigen sind KENNZAHLEN
         * der Schnittstelle: ein Tuerschloss meldete 2, ein Fenster 1, die
         * Fahrstufe 3 - eine Tabelle dazu nennt die Gegenstelle nicht, und
         * eine erfundene waere schlimmer als keine. Die Bezeichnung sagt es
         * deshalb ausdruecklich, genau wie beim bestehenden SCHLOSSVL.
         *
         * REIFENEH traegt 'zeile' => 0: die Einheitenkennung der
         * Reifendruecke geht nur ueber MQTT und aktion=json hinaus. In der
         * Statuszeile waere sie eine Zahl mehr, die niemand liest.
         * -------------------------------------------------------------- */
        'INNENTEMP'   => array('einheit' => "\xc2\xb0C", 'bez' => 'BY_FELD.INNENTEMP',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => -50, 'max' => 80),
        'VERBR50'     => array('einheit' => 'kWh/100km', 'bez' => 'BY_FELD.VERBR50',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 100),
        'FAHRSTUFE'   => array('einheit' => '', 'bez' => 'BY_FELD.FAHRSTUFE',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'SCHLOSSVR'   => array('einheit' => '', 'bez' => 'BY_FELD.SCHLOSSVR',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'SCHLOSSHL'   => array('einheit' => '', 'bez' => 'BY_FELD.SCHLOSSHL',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'SCHLOSSHR'   => array('einheit' => '', 'bez' => 'BY_FELD.SCHLOSSHR',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'TUERVL'      => array('einheit' => '', 'bez' => 'BY_FELD.TUERVL',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'TUERVR'      => array('einheit' => '', 'bez' => 'BY_FELD.TUERVR',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'TUERHL'      => array('einheit' => '', 'bez' => 'BY_FELD.TUERHL',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'TUERHR'      => array('einheit' => '', 'bez' => 'BY_FELD.TUERHR',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'KOFFER'      => array('einheit' => '', 'bez' => 'BY_FELD.KOFFER',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'FENSTERVL'   => array('einheit' => '', 'bez' => 'BY_FELD.FENSTERVL',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'FENSTERVR'   => array('einheit' => '', 'bez' => 'BY_FELD.FENSTERVR',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'FENSTERHL'   => array('einheit' => '', 'bez' => 'BY_FELD.FENSTERHL',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'FENSTERHR'   => array('einheit' => '', 'bez' => 'BY_FELD.FENSTERHR',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'DACHFENSTER' => array('einheit' => '', 'bez' => 'BY_FELD.DACHFENSTER',
                               'zeile' => 1, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        'REIFENEH'    => array('einheit' => '', 'bez' => 'BY_FELD.REIFENEH',
                               'zeile' => 0, 'quelle' => 'bestand', 'min' => 0, 'max' => 255),
        /* Gruppe 42 (0.9.29): nur MQTT und JSON (zeile 0 - die Statuszeile bleibt byte-gleich).
         * VORKLIMA 0 nichts, 1 gesendet, 2 mild, 3 Wiederholung, 4 wartet auf Anwesenheit, -1 gescheitert;
         * LADEKOSTEN/LADEPREIS: letzte Ladung zu Hause, -1 ohne Aussage. */
        'VORKLIMA'    => array('einheit' => '', 'bez' => 'BY_FELD.VORKLIMA',
                               'zeile' => 0, 'quelle' => 'gerechnet', 'min' => -1, 'max' => 4),
        'LADEKOSTEN'  => array('einheit' => 'EUR', 'bez' => 'BY_FELD.LADEKOSTEN',
                               'zeile' => 0, 'quelle' => 'gerechnet', 'min' => -1, 'max' => 10000),
        'LADEPREIS'   => array('einheit' => 'ct/kWh', 'bez' => 'BY_FELD.LADEPREIS',
                               'zeile' => 0, 'quelle' => 'gerechnet', 'min' => -1, 'max' => 1000),
    );
}

/** Nur die Felder, die in der Statuszeile stehen - in genau dieser Reihenfolge. */
function by_felder_zeile()
{
    $aus = array();
    foreach (by_felder() as $name => $eig) {
        if (!empty($eig['zeile'])) {
            $aus[$name] = $eig;
        }
    }
    return $aus;
}

/**
 * Das Suchmuster fuer Loxone.
 *
 * Das Semikolon gehoert INS MUSTER. Loxone sucht woertlich und nimmt den
 * ersten Treffer in der Zeile: ohne das Semikolon steckt "ALTER=" auch in
 * "SOLLALTER=" und "KM=" auch in "INSPKM=". Gemessen hat ein Schwesterplugin
 * dadurch den Wert eines anderen Feldes geliefert - kein Fehler, keine
 * Meldung, nur eine falsche Zahl. Jedes Feld der Statuszeile steht hinter
 * einem Semikolon, also traegt das Muster es mit.
 */
function by_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/**
 * Die Statuszeile fuer Loxone - an EINER Stelle gebildet.
 *
 * $f ist das Abbild eines Fahrzeugs, $alter das Alter in Sekunden, $ok der
 * Sammelmerker. Fehlende Werte werden zu einem Strich, nicht zu 0: eine 0
 * waere eine stille Falschaussage ("Ladezustand 0 %", obwohl niemand es
 * weiss). Loxone behaelt dann den letzten gueltigen Wert - genau das ist bei
 * einem fehlenden Messwert richtig.
 */
function by_wert_aus($v)
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '-';
    }
    return (string) (0 + $v);
}

function by_statuszeile($f, $alter, $ok)
{
    $teile = array('BYD');
    foreach (by_felder_zeile() as $name => $eig) {
        if ($name === 'OK') {
            $teile[] = 'OK=' . (int) $ok;
        } elseif ($name === 'ALTER') {
            $teile[] = 'ALTER=' . (int) $alter;
        } else {
            $teile[] = $name . '=' . by_wert_aus(is_array($f) && isset($f[$name])
                ? $f[$name] : null);
        }
    }
    return implode(';', $teile);
}

/**
 * Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung.
 *
 * Aus derselben Feldtabelle wie die Statuszeile - der MQTT-Weg traegt damit
 * dieselben Werte wie der HTTP-Weg. Ein Plugin, dessen MQTT-Meldung weniger
 * enthaelt als seine HTTP-Zeile, macht die Umstellung unmoeglich, und zwar
 * unauffaellig: es kommen ja Werte an, nur eben nicht alle.
 *
 * ts statt ALTER: ueber MQTT ist das Alter beim Senden immer null. Wer die
 * beiden Wege gleich behandeln will, veroeffentlicht den Zeitstempel und
 * laesst die Gegenseite rechnen (Loxone: Loxone-Zeit + 1230768000 - ts).
 */
function by_mqtt_themen()
{
    $aus = array(
        'ok'        => 'BY_MQTT.OK',
        'ts'        => 'BY_MQTT.TS',
        'fahrzeuge' => 'BY_MQTT.FAHRZEUGE',
        /* M4 (Durchgang 29.09.2026): das OK je Fahrzeug. Der Dienst sendet es
         * seit 0.9.x (abbild_schreiben), in dieser Liste fehlte es bis 0.9.19
         * - ausgerechnet das Thema, an dem ein Teilausfall in Loxone
         * erkennbar ist (gemessen, MQTT- und Oberflaechen-Pruefer). */
        'fahrzeugN/OK' => 'BY_MQTT.FZ_OK',
        /* Laden-1: nur bei eingeschalteter Ladeempfehlung nach Strompreis;
         * fluechtig (by_mqtt_retain() fuehrt sie nicht). */
        'lade_empfehlung'       => 'BY_MQTT.LADE_EMPFEHLUNG',
        'lade_empfehlung_grund' => 'BY_MQTT.LADE_EMPFEHLUNG_GRUND',
        // Gruppe 42 (B5): Lebenszeichen je Durchgang, fluechtig.
        'zaehler'   => 'BY_MQTT.ZAEHLER',
    );
    foreach (by_felder() as $name => $eig) {
        if ($name === 'OK' || $name === 'ALTER') {
            continue;   // OK steht oben je Fahrzeug, ALTER ueber MQTT als ts
        }
        $aus['fahrzeugN/' . $name] = $eig['bez'];
    }
    return $aus;
}

/**
 * M4: die Themen, die der DIENST sendet - aus bin/byd.py gelesen, nicht aus
 * dieser Bibliothek. Pruefung 13 im Reiter Test haelt diese Menge gegen
 * by_mqtt_themen(), in beide Richtungen; eine Tabelle gegen sich selbst zu
 * pruefen hiesse, nichts zu pruefen. null heisst "nicht lesbar".
 */
function by_py_schluessel($text, $name)
{
    $pos = strpos($text, "\n" . $name . ' = {');
    if ($pos === false) {
        return array();
    }
    $ende = strpos($text, "\n}", $pos + 1);
    if ($ende === false) {
        return array();
    }
    preg_match_all('/^    "([A-Z0-9_]+)": \{/m', substr($text, $pos, $ende - $pos), $m);
    return $m[1];
}

function by_themen_dienst()
{
    $f = by_paths()['bindir'] . '/byd.py';
    $t = is_file($f) ? (string) @file_get_contents($f) : '';
    if ($t === '' || !preg_match('/^MQTT_OBEN = \(([^)]*)\)/m', $t, $m)) {
        return null;
    }
    preg_match_all('/"([a-z_]+)"/', $m[1], $x);
    $felder = by_py_schluessel($t, 'FELDER');
    $abgeleitet = by_py_schluessel($t, 'ABGELEITET');
    if (!$x[1] || !$felder || !$abgeleitet) {
        return null;
    }
    $aus = $x[1];
    // Das OK je Fahrzeug setzt abbild_schreiben() von Hand, nicht aus einer
    // Tabelle - gesucht wird die Zuweisung selbst.
    if (preg_match('/paare\["fahrzeug%s\/OK" % nummer\] = fz_ok/', $t)) {
        $aus[] = 'fahrzeugN/OK';
    }
    foreach (array_merge($felder, $abgeleitet) as $k) {
        $aus[] = 'fahrzeugN/' . $k;
    }
    return array_values(array_unique($aus));
}

/**
 * M5: die Abodatei config/plugins/<ordner>/mqtt_subscriptions.cfg auf
 * "<praefix>/#" setzen - nur wenn sie abweicht. Gegenstueck zu
 * abodatei_nachfuehren() in bin/byd.py; das Gateway V1 liest sie
 * (Regeln/07). Rueckgabe 'gleich', 'neu' oder 'fehler'.
 */
function by_abodatei_nachfuehren($praefix)
{
    $p = by_paths();
    $datei = $p['configdir'] . '/mqtt_subscriptions.cfg';
    $soll = trim((string) $praefix, '/') . "/#\n";
    if (is_file($datei) && (string) @file_get_contents($datei) === $soll) {
        return 'gleich';
    }
    if (!is_dir($p['configdir'])) {
        return 'fehler';
    }
    $tmp = $datei . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return 'fehler';
    }
    @chmod($tmp, 0644);
    $ok = ftruncate($fh, 0) && (@fwrite($fh, $soll) === strlen($soll));
    fflush($fh);
    fclose($fh);
    if (!$ok || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return 'fehler';
    }
    by_log('MQTT: Abodatei auf ' . trim($soll) . ' gesetzt.');
    return 'neu';
}

/**
 * Ein Argument fuer die Schale anfuehren, Byte fuer Byte: fuer Themen, Werte und Texte, nicht fuer Pfade.
 * Bis 0.9.30 stand hier escapeshellarg(). Es verwirft unter PHP 7.4 jedes Byte, das im LC_CTYPE kein gueltiges Zeichen ist; Apache startet mit LANG=C, eine
 * Befehlsbruecke ohne LANG: aus "Kueche" mit Umlaut wurde "Kche", ohne Fehler (am Geraet gemessen 01.11.2026). setlocale() fehlt, wo keine UTF-8-Locale
 * erzeugt ist, und aendert basename() und PCRE. Einfache Anfuehrungszeichen sind fuer sh und dash byte-erhaltend; ein Nullbyte kann kein Argument tragen.
 */
function by_shell_arg($s)
{
    return "'" . str_replace("'", "'\\''", str_replace("\0", '', (string) $s)) . "'";
}

/**
 * M9: die behaltenen eigenen Themen unter $praefix am Broker abraeumen -
 * mit der Logik von byd.py --mqtt-leeren (loeschen, nachlesen, UDP nur als
 * Rueckfall). Rueckgabe array(rc, Text ohne die Marken des Installers).
 */
function by_mqtt_abraeumen($praefix)
{
    $p = by_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/byd.py';
    if (!is_file($py) || !is_file($skript)) {
        return array(2, by_t('MELDUNG.VENV_FEHLT_KURZ'));
    }
    if (!function_exists('exec')) {
        return array(2, by_t('MELDUNG.EXEC_GESPERRT'));
    }
    $ausgabe = array();
    $rc = 2;
    @exec(escapeshellarg($py) . ' ' . escapeshellarg($skript) . ' --mqtt-leeren '
        . by_shell_arg('--praefix=' . (string) $praefix) . ' 2>&1', $ausgabe, $rc);
    $zeilen = array();
    foreach ($ausgabe as $z) {
        $z = trim(preg_replace('/^<(OK|INFO|WARNING|FAIL)>\s*/', '', (string) $z));
        if ($z !== '') {
            $zeilen[] = $z;
        }
    }
    return array((int) $rc, implode(' ', $zeilen));
}

/* Welche Themen gehen ZURUECKBEHALTEN (retain) hinaus? Gegenstueck zu
 * RETAIN_FELDER in bin/byd.py - dort steht die Begruendung je Feld. Die
 * Oberflaeche zeigt es in der Themen-Tabelle, weil der Hausstandard das
 * verlangt: wer ein Thema anlegt, schreibt dazu, ob es retained ist
 * (Regeln/07). Dass beide Listen dasselbe sagen, prueft
 * Pruefung-BYD-Autos-0.9.9/retain_themen.py.
 * FEHLFOLGE steht seit 0.9.17 NICHT mehr hier: der Zaehler erfolgloser
 * Abrufe ist eine Aussage des Dienstes ueber sich selbst (Regeln/07,
 * entschieden am 19.09.2026). */
function by_mqtt_retain()
{
    $aus = array('fahrzeuge');
    foreach (array('LADEZUST', 'FAHRZUST', 'ONLINE', 'ZUENDUNG', 'SCHLOSSVL',
                   'BATTHEIZ', 'SITZHEIZ', 'LAEDT', 'KABEL', 'ZUHAUSE',
                   /* NEU 15.09.2026, nach derselben Regel: Zustaende ja,
                    * Messwerte nein. Tueren, Schloesser, Fenster,
                    * Heckklappe, Schiebedach und Fahrstufe aendern sich
                    * selten und sollen einen Neustart des Miniservers
                    * ueberstehen; die Einheitenkennung der Reifendruecke
                    * ist eine Einstellung des Fahrzeugs. NICHT dabei:
                    * INNENTEMP und VERBR50 - Messwerte mit Zeitbezug. */
                   'SCHLOSSVR', 'SCHLOSSHL', 'SCHLOSSHR',
                   'TUERVL', 'TUERVR', 'TUERHL', 'TUERHR', 'KOFFER',
                   'FENSTERVL', 'FENSTERVR', 'FENSTERHL', 'FENSTERHR',
                   'DACHFENSTER', 'FAHRSTUFE', 'REIFENEH',
                   // Gruppe 42: Kosten und Preis der letzten Ladung - Zustand bis zur naechsten Ladung.
                   'LADEKOSTEN', 'LADEPREIS') as $name) {
        $aus[] = 'fahrzeugN/' . $name;
    }
    return $aus;
}

/* ==================================================================
 * Befehlstabelle
 *
 * Je Befehl: Sprachschluessel, ob er schaltet, und welche Zusatzangabe er
 * braucht. Unbekannt gilt als schaltend - fail closed.
 *
 * Bewusst NICHT dabei: Laden starten und anhalten. Die Bibliothek pybyd nennt
 * dafuer keine Methode. Ein Bedienelement ohne Wirkung ist schlimmer als
 * keines, und ein Ausgang, den der Hersteller nicht bedienen kann, wird gar
 * nicht erst in die Importdatei geschrieben - ein Baustein, der nur Absagen
 * erntet, ist schlimmer als keiner.
 * ================================================================== */
function by_befehle()
{
    /* Welle Bild 17 (0.9.26, Hausherr 17.10.2026; Entwurf Welle 17 by, O3): 'eingreifend' = braucht ZUSAETZLICH
     * den zweiten Haken gefahr_ein (ab Werk aus), Form wie AudiConnect (gefahr) und VolkswagenID (eingreifend).
     * Verriegeln und Entriegeln, Suchen (Hupe und Licht) und Blinken wirken auf ein Fahrzeug, das im oeffentlichen
     * Raum steht - ein versehentliches Entriegeln laesst es offen stehen, ohne dass es jemand merkt. Bis 0.9.25
     * hingen sie allein am Steuerungshaken. */
    return array(
        'abruf'           => array('bez' => 'BY_BEF.ABRUF',      'schaltet' => 0, 'zusatz' => '',      'eingreifend' => 0),
        'verriegeln'      => array('bez' => 'BY_BEF.VERRIEGELN', 'schaltet' => 1, 'zusatz' => '',      'eingreifend' => 1),
        'entriegeln'      => array('bez' => 'BY_BEF.ENTRIEGELN', 'schaltet' => 1, 'zusatz' => '',      'eingreifend' => 1),
        'klima_start'     => array('bez' => 'BY_BEF.KLIMA_START', 'schaltet' => 1, 'zusatz' => 'temp', 'eingreifend' => 0),
        'klima_stop'      => array('bez' => 'BY_BEF.KLIMA_STOP', 'schaltet' => 1, 'zusatz' => '',      'eingreifend' => 0),
        'klima_plan'      => array('bez' => 'BY_BEF.KLIMA_PLAN', 'schaltet' => 1, 'zusatz' => 'temp',  'eingreifend' => 0),
        'sitzklima'       => array('bez' => 'BY_BEF.SITZKLIMA',  'schaltet' => 1, 'zusatz' => 'stufe', 'eingreifend' => 0),
        'batterieheizung' => array('bez' => 'BY_BEF.BATTHEIZ',   'schaltet' => 1, 'zusatz' => 'stufe', 'eingreifend' => 0),
        'suchen'          => array('bez' => 'BY_BEF.SUCHEN',     'schaltet' => 1, 'zusatz' => '',      'eingreifend' => 1),
        'blinken'         => array('bez' => 'BY_BEF.BLINKEN',    'schaltet' => 1, 'zusatz' => '',      'eingreifend' => 1),
        'fenster_zu'      => array('bez' => 'BY_BEF.FENSTER_ZU', 'schaltet' => 1, 'zusatz' => '',      'eingreifend' => 0),
    );
}

function by_befehl_schaltet($aktion)
{
    $b = by_befehle();
    if (!isset($b[$aktion])) {
        return true;    // fail closed
    }
    return (bool) $b[$aktion]['schaltet'];
}

/** Welle Bild 17 (0.9.26): braucht der Befehl den zweiten Haken gefahr_ein? Unbekannt gilt als eingreifend -
 *  fail closed wie by_befehl_schaltet(). */
function by_befehl_eingreifend($aktion)
{
    $b = by_befehle();
    if (!isset($b[$aktion])) {
        return true;    // fail closed
    }
    return !empty($b[$aktion]['eingreifend']);
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen den Ausfuhren aus einer laufenden Anlage.
 *
 * Zwei Dinge, die dem geerbten Nachbau fehlten und hier drin sind:
 *   - HintText="" am Wurzelelement, bei VirtualOut zusaetzlich CmdInit=""
 *   - als ERSTES Kindelement <Info templateType="2" minVersion="17010727"/>
 *     (templateType: 1 = UDP-Eingang, 2 = HTTP-Eingang, 3 = Ausgang)
 *   - je VirtualInHttpCmd ein Unit und ein HintText
 * ================================================================== */

function by_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function by_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="' . by_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . by_x($kopf['title']) . '" ';
    $o .= 'Comment="' . by_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . by_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . by_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . by_x($c['title']) . '" ';
        $o .= 'Comment="' . by_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . by_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="' . (isset($c['min']) && $c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        // Grenzen realistisch: Loxone zieht daraus die Reglergrenzen und die
        // Plausibilitaetspruefung. Wer alles offen laesst (+-2147483647),
        // verschenkt beides.
        $o .= 'MinVal="' . by_x(isset($c['min']) && $c['min'] !== null ? $c['min'] : -2147483647) . '" ';
        $o .= 'MaxVal="' . by_x(isset($c['max']) && $c['max'] !== null ? $c['max'] : 2147483647) . '" ';
        $o .= 'Unit="' . by_x(isset($c['unit']) ? $c['unit'] : '<v.1>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function by_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="' . by_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . by_x($kopf['title']) . '" ';
    $o .= 'Comment="' . by_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . by_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . by_x($c['title']) . '" ';
        $o .= 'Comment="' . by_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        /* B-Nachzug 01.10.2026: die Methode ist "GET", wie in den Ausfuhren
         * aus Loxone Config (Regeln/07, VO_Rasenmaeher und VQU_Govee: GET
         * auch ohne Ausbefehl). Bis 0.9.22 stand hier "0" - keine Methode,
         * die Config kennt; vorlagen_pruefen.py meldete sie als unbekannt. */
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . by_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOff="' . by_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        /* U11 (Durchgang 29.09.2026): ein Befehl mit Wertplatzhalter <v>
         * gehoert an einen ANALOGEN Ausgang (Regeln/07, Ausfuhr
         * VO_Rasenmaeher). Bis 0.9.19 stand hier fest "false"; ein digitaler
         * Ausgang setzt fuer <v> nur 1 oder 0 ein - Klima mit temp=1. Der
         * analoge Befehl traegt zwischen RepeatRate und HintText die
         * Skalierung; 0/0/100/100 reicht den Wert unveraendert durch. Am
         * Miniserver NICHT gemessen. */
        $analog = !empty($c['analog']);
        $o .= 'Analog="' . ($analog ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        if ($analog) {
            $o .= 'SourceValLow="0" ';
            $o .= 'DestValLow="0" ';
            $o .= 'SourceValHigh="100" ';
            $o .= 'DestValHigh="100" ';
        }
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Der Rechnername, unter dem der Miniserver diesen LoxBerry erreicht.
 *
 * HTTP_HOST ist das, was der Anwender im Browser eingegeben hat - also
 * derselbe Weg, den auch der Miniserver nehmen wird. gethostname() liefert
 * nicht zwingend einen Namen, der von aussen aufloest.
 */
function by_host()
{
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        return preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST']);
    }
    $h = gethostname();
    return $h !== false && $h !== '' ? $h : 'loxberry';
}

/** Text ohne Auszeichnung und ohne Entitaeten - fuer XML-Attribute. */
function by_klartext($s)
{
    return trim(strip_tags(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8')));
}

/**
 * Importdatei fuer die virtuellen EINGAENGE. Rueckgabe: array(name, inhalt)
 *
 * Der Titel ist fuer Menschen, das Suchmuster fuer die Maschine: der Titel
 * darf sich aendern, ohne dass eine bestehende Anlage etwas merkt, denn
 * gesucht wird weiter nach ";NAME=". Ein Praefix bleibt trotzdem noetig - in
 * der Bausteinsuche steht der Name ohne den Geraeteknoten darueber.
 *
 * Der Kommentar eines Befehls wird in Loxone Config zum ANZEIGENAMEN (Feld
 * Visualisierung), nicht zur Dokumentation. Er ist deshalb eine knappe Zeile,
 * kein Fliesstext; alles, was erklaert werden muss, steht im Kommentar des
 * WURZELELEMENTS, den man einmal liest.
 */
function by_vorlage($nummer = 1)
{
    $p = by_paths();
    $token = by_token();
    $fahrzeuge = by_fahrzeuge();
    $fz = isset($fahrzeuge[(string) (int) $nummer]) ? $fahrzeuge[(string) (int) $nummer] : array();
    $name = isset($fz['name']) && $fz['name'] !== '' ? $fz['name'] : '';

    /* Angelegt wird nur, was das Fahrzeug beim letzten erfolgreichen Abruf
     * wirklich geliefert hat. Loxone traegt sonst DefVal="0" ein - und eine 0
     * sieht aus wie ein Messwert. Hat das Fahrzeug noch NIE geantwortet, wird
     * alles ausgeliefert, mit einem sichtbaren Hinweis IN DER DATEI, sie nach
     * dem ersten Abruf erneut zu holen. */
    $nie_geantwortet = empty($fz);
    $cmds = array();
    foreach (by_felder_zeile() as $feld => $eig) {
        if (!$nie_geantwortet && !in_array($feld, array('OK', 'ALTER'), true)
            && (!isset($fz[$feld]) || $fz[$feld] === null)) {
            continue;
        }
        $einheit = by_klartext($eig['einheit']);
        /* Welle Bild 17 (0.9.26, Entwurf Welle 17 by, E3; Sprachregel 13.10.2026, Regeln/07): der Titel steht fest
         * als BYD_<n>_<FELD> - wie AUDI_1_SOC, SKODA_..., VW_1_SOC -, der Kommentar traegt die uebersetzte
         * Bezeichnung. Bis 0.9.25 war es umgekehrt, und eine englische Oberflaeche legte andere Namen an als die
         * Baustein-Liste und das Musterprojekt. Suchtext und Adresse bleiben: bestehende Anlagen merken nichts.
         * E6: Kennzahl- und 0/1-Felder (ohne Einheit, hoechstens 255) zeigen ganze Zahlen (<v.0>). */
        $ganz = ($einheit === '' && $eig['max'] !== null && $eig['max'] <= 255);
        $cmds[] = array(
            'title'   => 'BYD_' . (int) $nummer . '_' . $feld,
            'comment' => by_klartext(by_t($eig['bez'])),
            'check'   => by_check($feld),
            'min'     => $eig['min'],
            'max'     => $eig['max'],
            'unit'    => $einheit !== '' ? '<v.1> ' . $einheit : ($ganz ? '<v.0>' : '<v.1>'),
        );
    }
    $adresse = 'http://' . by_host() . '/plugins/' . $p['plugin']
             . '/index.php?token=' . $token . '&aktion=status&fahrzeug=' . (int) $nummer;
    $hinweis = by_klartext(by_t('LOX.VORLAGE_HINWEIS'));
    if ($nie_geantwortet) {
        $hinweis .= ' ' . by_klartext(by_t('LOX.VORLAGE_UNGEPRUEFT'));
    }
    return array(
        'VI_BYD_' . (int) $nummer . '.xml',
        by_xml_virtual_in_http(array(
            'title'   => 'BYD ' . (int) $nummer . ($name !== '' ? ' ' . $name : ''),
            'address' => $adresse,
            'polling' => '300',
            'comment' => 'BYD Autos ' . (int) $nummer,
            'hint'    => $hinweis,
        ), $cmds),
    );
}

/** Importdatei fuer die virtuellen AUSGAENGE (Steuerbefehle). */
function by_vorlage_vo($nummer = 1)
{
    /* Welle Bild 17 (0.9.26; Entwurf Welle 17 by E3/E6, gemeinsame Form O2/O3):
     *   - Titel fest BYD_<n>_<BEFEHL>, Kommentar uebersetzt (bis 0.9.25 umgekehrt; Sprachregel 13.10.2026).
     *   - Klima analog mit <v.1> und OHNE Ausbefehl, dazu der eigene Befehl BYD_<n>_KLIMA_AUS (nur Ein): die
     *     Formel im Musterprojekt liefert im Ruhezustand -1 (vom Endpunkt per Muster abgewiesen), und ob Config
     *     beim Wert 0 den Ausbefehl schickt, ist offen.
     *   - Sitz- und Batterieheizung analog mit <v.0> und dem Ausbefehl stufe=0 (bis 0.9.25 leer - "0 = aus"
     *     erreichte das Plugin nur, wenn Loxone bei 0 den Einbefehl schickte). Einzige Verhaltensaenderung.
     *   - Verriegelung, Suchen und Blinken nur mit dem zweiten Haken gefahr_ein (Hausherr 17.10.2026); ohne ihn
     *     weist der Endpunkt sie ohnehin ab. klima_plan steht wie bisher nicht in der Vorlage. */
    $p = by_paths();
    $token = by_token();
    $cfg = by_config(false);
    $eingriff = !empty($cfg['gefahr_ein']);
    $n = (int) $nummer;
    $basis = '/plugins/' . $p['plugin'] . '/index.php?token=' . $token;
    $fz = '&fahrzeug=' . $n;
    $cmds = array();
    foreach (by_befehle() as $aktion => $eig) {
        if (!empty($eig['eingreifend']) && !$eingriff) {
            continue;
        }
        $kommentar = by_klartext(by_t($eig['bez']));
        if ($aktion === 'abruf') {
            $cmds[] = array('title' => 'BYD_' . $n . '_ABRUF', 'comment' => $kommentar,
                            'on' => $basis . '&aktion=abruf', 'off' => '');
            continue;
        }
        if ($aktion === 'klima_start') {
            $cmds[] = array('title' => 'BYD_' . $n . '_KLIMA', 'comment' => $kommentar,
                            'on' => $basis . '&aktion=klima_start' . $fz . '&temp=<v.1>', 'off' => '',
                            'analog' => 1);
            continue;
        }
        if ($aktion === 'klima_stop') {
            $cmds[] = array('title' => 'BYD_' . $n . '_KLIMA_AUS', 'comment' => $kommentar,
                            'on' => $basis . '&aktion=klima_stop' . $fz, 'off' => '');
            continue;
        }
        if ($aktion === 'klima_plan' || $aktion === 'entriegeln') {
            continue;   // klima_plan nicht in der Vorlage; entriegeln steht als Ausbefehl an "Verriegelung"
        }
        if ($aktion === 'verriegeln') {
            // Ein Zustand gehoert an EINEN Ausgang mit Ein- und Ausbefehl, nicht an zwei Ausgaenge.
            $cmds[] = array('title' => 'BYD_' . $n . '_VERRIEGELUNG',
                            'comment' => $kommentar . ' / ' . by_klartext(by_t('BY_BEF.ENTRIEGELN')),
                            'on' => $basis . '&aktion=verriegeln' . $fz,
                            'off' => $basis . '&aktion=entriegeln' . $fz);
            continue;
        }
        if ($eig['zusatz'] === 'stufe') {
            $cmds[] = array('title' => 'BYD_' . $n . '_' . strtoupper($aktion), 'comment' => $kommentar,
                            'on' => $basis . '&aktion=' . $aktion . $fz . '&stufe=<v.0>',
                            'off' => $basis . '&aktion=' . $aktion . $fz . '&stufe=0',
                            'analog' => 1);
            continue;
        }
        $cmds[] = array('title' => 'BYD_' . $n . '_' . strtoupper($aktion), 'comment' => $kommentar,
                        'on' => $basis . '&aktion=' . $aktion . $fz, 'off' => '');
    }
    $hinweis = by_klartext(by_t('LOX.VORLAGE_VO_HINWEIS'));
    if ($eingriff) {
        $hinweis .= ' ' . by_klartext(by_t('LOX.VORLAGE_VO_EINGRIFF'));
    }
    return array(
        'VQ_BYD_' . $n . '_steuern.xml',
        by_xml_virtual_out(array(
            'title'   => 'BYD ' . $n . ' steuern',
            'address' => 'http://' . by_host(),
            'comment' => 'BYD Autos ' . $n . ' Steuerbefehle',
            'hint'    => $hinweis,
        ), $cmds),
    );
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein by_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */

function by_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel 'ABSCHNITT.SCHLUESSEL'.
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt beim
 * Durchsehen sofort auf, was fehlt, statt dass die Seite leer bleibt.
 *
 * Gelesen wird mit INI_SCANNER_RAW, weil die Werte Auszeichnung tragen
 * duerfen. Damit das keine zufaellige Unauffaelligkeit ist, steht in den
 * Sprachdateien KEIN gerades Anfuehrungszeichen innerhalb eines Wertes -
 * HTML-Attribute werden einfach quotiert. Ein Wert, der nur unter einem
 * bestimmten Lesemodus gueltig ist, ist nicht gueltig, sondern zufaellig
 * unauffaellig; geprueft wird das mit Werkzeuge/ini_pruefen.py in allen drei
 * Modi.
 */
function by_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        /* Die Wurzel aus by_lbhome() - dieselbe Regel wie by_paths(). Bis
         * 0.9.16 stand hier ein eigener Rueckfall auf /home/loxberry/loxberry,
         * und ohne Wurzel wurde aus dem leeren $home ein Pfad ab "/"
         * (/templates/plugins/<ordner>/lang). In WSL gemessen 24.09.2026
         * (Pruefung-BYD-Autos-0.9.17, Fall P3, mit open_basedir). */
        $home = by_lbhome();
        $ordner = basename(dirname(__FILE__));
        $pfad = $home !== '' ? $home . '/templates/plugins/' . $ordner . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . by_sprache() . '.ini', true,
                                 INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
        // in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    $a = $teile[0];
    $s = $teile[1];
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
/**
 * Die zulaessigen Bereiche der Zahlenfelder - an EINER Stelle.
 *
 * ERGAENZT 03.09.2026. Bis 0.9.5 stand diese Tabelle im Speichern-Handler der
 * Oberflaeche, und by_sicherung_lesen() prueft die Werte einer
 * zurueckgespielten Datei gar nicht - nur die Schluesselnamen. Eine Sicherung
 * mit "intervall": "abc" oder "aktionstoken": [] wurde angenommen und
 * geschrieben; aus dem Feld wurde beim naechsten Vergleich die Zeichenkette
 * "Array", und jede Adresse im Miniserver war ungueltig.
 *
 * Eine zweite Wahrheit ueber zulaessige Werte gibt es nicht: Formular,
 * Sicherung und Endpunkt fragen dieselbe Tabelle.
 */
function by_grenzen()
{
    return array(
        'intervall'        => array(120, 3600),
        'temp_min'         => array(10, 32),
        'temp_max'         => array(10, 32),
        'verlauf_tage'     => array(1, 90),
        'wartezeit'        => array(0, 30),
        'abfahrt_vorlauf'  => array(1, 120),
        'abfahrt_temp'     => array(10, 32),
        'abfahrt_alter'    => array(60, 3600),
        'abfahrt_fahrzeug' => array(1, 99),
        'ladeempf_alter'   => array(60, 86400),
        'preisempf_stunden' => array(1, 12),
        'kapazitaet'       => array(0, 500),
        'heim_radius'      => array(20, 5000),
        // Gruppe 42 (0.9.29)
        'vorklima_kalt_unter'  => array(-30, 45),
        'vorklima_heiss_ueber' => array(-30, 45),
        // 0.9.31
        'kabel_soc'         => array(1, 100),
        'kabel_abfahrt_min' => array(0, 720),
        'vorklima_min_soc'  => array(0, 100),
    );
}

/**
 * Taugt dieser Wert ueberhaupt fuer eine Konfigurationsdatei?
 *
 * Am Eingang, vor jeder feldbezogenen Pruefung: was kein Skalar ist, hat in
 * einer JSON-Konfiguration nichts zu suchen - ein Feld wird beim naechsten
 * (string)-Zugriff zur Zeichenkette "Array", und unter PHP 8 wirft es.
 * Steuerzeichen fliegen ebenfalls heraus; sie waeren in keiner Anzeige
 * sichtbar und in keiner Meldung erklaerbar.
 */
function by_wert_taugt($w)
{
    if (is_array($w) || is_object($w) || is_null($w) || is_bool($w)) {
        return false;
    }
    $s = (string) $w;
    if (strlen($s) > 4096) {
        return false;
    }
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) !== 1;
}

/**
 * C8/C9 (Durchgang 29.09.2026): die Regel je Schluessel, die nicht schon in
 * by_grenzen() steht - dieselben Regeln wie das Formular. Bis 0.9.19 wurden
 * nur die Zahlenfelder geprueft; eine Sicherung mit steuerung_ein "nein"
 * schaltete die Fahrzeugsteuerung frei, ein Token "Array" liess jede Liste
 * als Token passieren (gemessen, Code- und Oberflaechen-Pruefer).
 */
function by_sicherung_regeln()
{
    return array(
        'aktionstoken'    => 'token',
        'mqtt_ein'        => 'schalter',
        'steuerung_ein'   => 'schalter',
        'gefahr_ein'      => 'schalter',   // Welle Bild 17 (0.9.26): zweiter Haken
        'gps_ein'         => 'schalter',
        'mqtt_bibliothek' => 'schalter',
        'abfahrt_ein'     => 'schalter',
        'ladeempf_ein'    => 'schalter',
        'ladeempf_unter'  => 'schalter',
        'preisempf_ein'   => 'schalter',
        'preisempf_quelle'  => 'quelle',
        'preisempf_praefix' => 'thema',
        'mqtt_topic'      => 'praefix',
        'abfahrt_praefix' => 'thema',
        'ladeempf_thema'  => 'thema',
        'ladeempf_grenze' => 'grenzwert',
        'heim_breite'     => 'breite',
        'heim_laenge'     => 'laenge',
        'ansage_laden_fertig' => 'schalter',
        'ansage_ausfall'      => 'schalter',
        // Gruppe 42 (0.9.29)
        'befehle_mqtt_ein'      => 'schalter',
        'befehle_mqtt_steuern'  => 'schalter',
        'endpunkt_ein'          => 'schalter',
        'haus_abfahrt_ein'      => 'schalter',
        'vorklima_anwesend_ein' => 'schalter',
        'vorklima_wetter_ein'   => 'schalter',
        'ankunft_ein'           => 'schalter',
        'ankunft_quelle'        => 'ankunft',
        'haus_strompreis_ein'   => 'schalter',
        'ladeempf_quelle'       => 'empfquelle',
        'signal_ein'            => 'schalter',
        'signal_praefix'        => 'signalpraefix',
        'signal_laden_fertig'   => 'schalter',
        'signal_ausfall'        => 'schalter',
        'signal_vorklima'       => 'schalter',
        // 0.9.31
        'haus_fahrzeug_ein'      => 'schalter',
        'signal_kabel'           => 'schalter',
        'vorklima_nur_kabel'     => 'schalter',
        'ansage_kabel_vergessen' => 'schalter',
        'kabel_uhr'              => 'uhrzeit',
    );
}

/** Rueckgabe array(taugt, Wert, Sprachschluessel des Grundes). */
function by_sicherung_regel($art, $w)
{
    $s = is_string($w) ? $w : '';
    switch ($art) {
        case 'token':
            // Muster wie Regeln/05: was ohne Kodierung in eine Adresse passt,
            // 1 bis 64 Zeichen (gemessen am Bestand, nicht an der Erzeugung).
            // Leer heisst "kein Token gesichert".
            if (!is_string($w)) {
                return array(false, null, 'EINST.SICH_R_TOKEN');
            }
            if ($w === '' || preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $w)) {
                return array(true, $w, '');
            }
            return array(false, null, 'EINST.SICH_R_TOKEN');
        case 'ankunft':
            // Gruppe 42: tag:<name> oder person:<name>, kommagetrennt, hoechstens vier; leer = aus.
            if ($w === '' || (is_string($w) && preg_match('/^(tag|person):[A-Za-z0-9_-]{1,40}(,(tag|person):[A-Za-z0-9_-]{1,40}){0,3}\z/', $w))) {
                return array(true, $w, '');
            }
            return array(false, null, 'EINST.SICH_R_THEMA');
        case 'uhrzeit':
            // 0.9.31: HH:MM wie by_sammel_wert().
            if (is_string($w) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]\z/', $w)) {
                return array(true, $w, '');
            }
            return array(false, null, 'EINST.SICH_R_QUELLE');
        case 'empfquelle':
            if ($w === 'thema' || $w === 'haus') {
                return array(true, $w, '');
            }
            return array(false, null, 'EINST.SICH_R_QUELLE');
        case 'signalpraefix':
            if (is_string($w) && preg_match('/^[A-Za-z0-9_-]{1,40}\z/', $w)) {
                return array(true, $w, '');
            }
            return array(false, null, 'EINST.SICH_R_PRAEFIX');
        case 'quelle':
            // Laden-1: nur die Linien, deren Schnittstelle nachgelesen ist - seit 0.9.29 auch haus/strompreis.
            if (is_string($w) && (array_key_exists($w, by_preis_quellen()) || $w === 'haus')) {
                return array(true, $w, '');
            }
            return array(false, null, 'EINST.SICH_R_QUELLE');
        case 'schalter':
            if ($w === 0 || $w === 1 || $w === '0' || $w === '1') {
                return array(true, (int) $w, '');
            }
            return array(false, null, 'EINST.SICH_R_SCHALTER');
        case 'praefix':
            // Formular (Reiter MQTT): Buchstaben, Ziffern, _ / -, keine leere
            // Ebene (M8), ohne Schraegstrich am Rand.
            if (is_string($w) && preg_match('#^[A-Za-z0-9_/\-]{1,64}\z#', $w)
                && strpos($w, '//') === false && trim($w, '/') === $w) {
                return array(true, $w, '');
            }
            return array(false, null, 'EINST.SICH_R_PRAEFIX');
        case 'thema':
            if ($w === '' || (is_string($w) && preg_match('#^[A-Za-z0-9_/\-]{1,128}\z#', $w)
                              && trim($w, '/') === $w)) {
                return array(true, $w, '');
            }
            return array(false, null, 'EINST.SICH_R_THEMA');
        case 'grenzwert':
        case 'breite':
        case 'laenge':
            $g = array('grenzwert' => 100000, 'breite' => 90, 'laenge' => 180);
            if ($art !== 'grenzwert' && $w === '') {
                return array(true, '', '');
            }
            if (is_int($w) || is_float($w)) {
                $z = (float) $w;
            } elseif (preg_match('/^-?[0-9]{1,6}(\.[0-9]{1,8})?\z/', $s)) {
                $z = (float) $s;
            } else {
                return array(false, null, 'EINST.SICH_R_DEZIMAL');
            }
            if ($z < -$g[$art] || $z > $g[$art]) {
                return array(false, null, 'EINST.SICH_R_DEZIMAL');
            }
            return array(true, $w, '');
    }
    return array(false, null, 'EINST.SICH_R_UNBEKANNT');
}

/**
 * C10: ein Feld der Zugangsdaten aus der Sicherung - dieselben Regeln wie das
 * Formular (index.php, Reiter Einstellungen). Rueckgabe: Sprachschluessel des
 * Grundes, '' wenn es taugt.
 */
function by_zugang_wert_pruefen($feld, $w)
{
    if (!is_string($w)) {
        return 'EINST.SICH_R_ZUGANG';
    }
    if ($feld === 'benutzer') {
        // Das Formular entfernt Steuerzeichen und Anfuehrungszeichen und
        // meldet es; eine Sicherung wird nicht zurechtgebogen, sondern
        // abgewiesen.
        return preg_match('/[\x00-\x1F\x7F"\']/', $w) ? 'EINST.SICH_R_BENUTZER' : '';
    }
    if ($feld === 'passwort') {
        return preg_match('/[\x00-\x1F\x7F]/', $w) ? 'EINST.SICH_R_ZUGANG' : '';
    }
    if ($feld === 'pin') {
        return ($w === '' || preg_match('/^[0-9]{4,8}\z/', $w)) ? '' : 'EINST.SICH_R_PIN';
    }
    if ($feld === 'land') {
        return ($w === '' || preg_match('/^[A-Z]{2}\z/', $w)) ? '' : 'EINST.SICH_R_LAND';
    }
    return 'EINST.SICH_R_ZUGANG';
}

/**
 * C10: die Zugangsdaten fuer die Sicherungsdatei - Benutzername, Passwort,
 * Steuer-PIN und Land aus zugang.json, unter eigenen Schluesseln.
 */
function by_sicherung_zugang()
{
    $z = by_json_lesen(by_paths()['zugang']);
    $aus = array();
    foreach (array('benutzer', 'passwort', 'pin', 'land') as $k) {
        $aus['zugang_' . $k] = isset($z[$k]) && is_scalar($z[$k]) ? (string) $z[$k] : '';
    }
    return $aus;
}

/* ==================================================================
 * Sprachausgabe (Nr. 36 b, Stufe 2, seit 0.9.23)
 *
 * Der Dienst bin/byd.py erkennt die Anlaesse und ruft bin/by_ansage.php;
 * dort entsteht der Satz aus der Sprachdatei, gesprochen wird mit der
 * gemeinsamen Sprachausgabe (sprachausgabe.php). Ab Werk ist die Ausgabe aus.
 * ================================================================== */

/** Erlaubte Ausgabearten: alle des Moduls ausser 'audioserver' (die Linie gibt keinen Text an Loxone). Weitere
 *  Ausgabeziele (Tabelle, 'ziele') seit 0.9.29 mit Stamm 1.2.1 - by_mit_zielen() unten; ohne Tabelle wie bisher. */
function by_ansage_modi()
{
    /* Gruppe 42 (0.9.29, Sprachmodul 1.2.1, E11): aus ansage_modi() abgeleitet statt abgeschrieben -
     * damit kommen sonos4lox und (ab Modul 1.1.4) sonox dazu. Bis 0.9.28 stand hier eine feste
     * Liste, und Sonos4Lox liess sich nicht waehlen. audioserver bleibt draussen (wie bisher). */
    $m = function_exists('ansage_modi') ? ansage_modi()
        : array('aus', 'musicserver', 'ms4h', 'custom', 'alexang', 'cc4lox');
    $aus = array();
    foreach ($m as $x) {
        if ($x === 'audioserver') {
            continue;
        }
        if ($x === 'sonox' && (!defined('ANSAGE_FASSUNG') || version_compare(ANSAGE_FASSUNG, '1.1.4', '<'))) {
            continue;
        }
        $aus[] = $x;
    }
    return $aus;
}

/** Welle Mehrfach-Ziele (Sprachmodul 1.2.1): bietet diese Linie die Tabelle der weiteren Ausgabeziele an? Nur, wenn die
 *  geladene Abschrift sie kann - laden zwei Abschriften im selben Prozess, gilt die zuerst geladene. */
function by_mit_zielen() {
    return version_compare(ANSAGE_FASSUNG, '1.2.0', '>=') && function_exists('ansage_ziele_html')
        && function_exists('ansage_ziele_pruefen') && function_exists('ansage_testknoepfe_html');
}

/** Welle Mehrfach-Ziele (0.9.29), X-2: aus den gesammelten Eingaben (by_eingaben_sammeln(), Schluessel name[N]) wieder
 *  das POST der Tabelle (ziele_post); null, wenn die Tabelle nicht darin steht (die gespeicherten Ziele bleiben sichtbar). */
function by_ziele_x2_post(array $werte)
{
    $p = array();
    foreach ($werte as $k => $v) {
        if (is_string($v) && preg_match('/^(tts_ziel_[a-z]+)\[([2-9])\]\z/', (string) $k, $m)) { $p[$m[1]][(int) $m[2]] = $v; }
    }
    return isset($p['tts_ziel_art']) ? $p : null;
}

/** Welle Mehrfach-Ziele (0.9.29), X-2: ein POST-Feld - name[N] der Tabelle aus $_POST[name][N], sonst $_POST[name];
 *  null, wenn es fehlt. */
function by_post_feld($feld)
{
    if (preg_match('/^(tts_ziel_[a-z]+)\[([2-9])\]\z/', (string) $feld, $m)) {
        return (isset($_POST[$m[1]]) && is_array($_POST[$m[1]]) && isset($_POST[$m[1]][$m[2]])) ? $_POST[$m[1]][$m[2]] : null;
    }
    return isset($_POST[$feld]) ? $_POST[$feld] : null;
}

/** Optionen fuer Formular-Baustein und Formular-Lesen. */
function by_ansage_opt()
{
    return array('modi' => by_ansage_modi());
}

/**
 * Die Anlaesse: Name => array(Konfigschluessel, Beschriftung, Satz). Dieselbe
 * Liste wie ANSAGE_ANLAESSE in bin/byd.py; die Bruecke nimmt nur diese Namen an.
 * Nur zwei: BYD liefert keine Ladegrenze, kein Licht- und kein Klimafeld, und
 * Tueren und Schloss sind geraetespezifische Rohwerte.
 */
function by_ansage_anlaesse()
{
    return array(
        'laden_fertig' => array('ansage_laden_fertig', 'EINST.L_ANSAGE_LADEN_FERTIG', 'BY_ANSAGE.S_LADEN_FERTIG'),
        'ausfall'      => array('ansage_ausfall', 'EINST.L_ANSAGE_AUSFALL', 'BY_ANSAGE.S_AUSFALL'),
        'kabel_vergessen' => array('ansage_kabel_vergessen', 'EINST.L_ANSAGE_KABEL', 'BY_ANSAGE.S_KABEL'),
    );
}

/** Die Schluessel, die mit 0.9.23 dazukamen - eine aeltere Sicherung kennt sie nicht. */
function by_ansage_neue_schluessel()
{
    $s = array('tts', 'ansage_regeln', 'ansage_token_herkunft');
    foreach (by_ansage_anlaesse() as $a) {
        $s[] = $a[0];
    }
    return $s;
}

/**
 * Der Block tts, geprueft und vervollstaendigt (ab Werk 'aus'). by_config()
 * prueft keine Einzelwerte; ein unzulaessiger Block (von Hand geschrieben)
 * gilt deshalb hier als "aus", und das eigene Zurueckspielen nennt ihn (X-3).
 * $heilen = false schreibt nichts (Bruecke).
 */
function by_tts($heilen = true)
{
    $c = by_config($heilen);
    $t = isset($c['tts']) && is_array($c['tts']) ? $c['tts'] : array();
    $g = '';
    if (ansage_wert_pruefen($t, $g, by_ansage_modi()) === null) {
        $t = array();
    }
    list($t) = ansage_vervollstaendigen($t, 'aus');
    return $t;
}

/** Der Kontext des Moduls: Webport, Kopfzeile, Ordner fuer <art>_letzte.json, Texte. */
/** Der Block tts aus einer schon gelesenen Konfiguration (Stamm 1.3.2): Ein gespeicherter bleibt, auch 'aus'; ein nie gespeicherter kommt aus den Vorgaben, also ab Werk an. */
function by_tts_von($cfg)
{
    list($t) = ansage_vervollstaendigen(isset($cfg['tts']) && is_array($cfg['tts']) ? $cfg['tts'] : ansage_werk_block(), 'aus');
    return $t;
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (0.9.31, Welle S2, Stamm sprachausgabe.php 1.3.2) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('BY_ANSAGE_HOECHSTALTER')) { define('BY_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function by_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = by_config(false); }
    $g = '';
    if (isset($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function by_ansage_warte($fn)
{
    $d = by_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function by_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    by_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; by_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function by_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = by_config(false); }
    list($neu, $her2, $g) = ansage_token_abgleich(by_tts_von($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), by_ansage_k());
    if (!$g) { return false; }
    $cfg = by_config(false);
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!by_config_speichern($cfg)) { return false; }
    by_log('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function by_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = by_config(false); }
    return ansage_tafel_einreihen(ansage_token_fuer_lauf(by_tts_von($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), by_ansage_k()), by_ansage_regeln($cfg), by_ansage_k(), true);     // Stamm 1.4.0: die Tafel, wenn sie bereitsteht
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function by_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = by_config(false); }
    $t = by_tts_von($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(by_ansage_vor_ort($cfg), by_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function by_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = by_config(false); }
    $k = by_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(by_tts_von($cfg), by_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function by_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function by_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = by_config(false);
    $k = by_ansage_k();
    $t = $tts === null ? by_tts_von($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, by_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function by_ansage_senden($jetzt = null)
{
    $d = by_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = by_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= BY_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = by_config(false);
        by_ansage_token_pflegen($conf);
        $conf = by_config(false);
        $a = ansage_regel_sprechen($frisch, by_tts_von($conf), by_ansage_regeln($conf), by_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { by_log('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            by_log('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/** Der Kontext des Moduls. */
function by_ansage_k()
{
    $p = by_paths();
    return array(
        'werk' => ansage_werk_modus(),
        'port'   => ansage_webport(($p['home'] !== '' ? $p['home'] : dirname(dirname(__DIR__)))
                                   . '/config/system/general.json'),
        'kopf'   => array('User-Agent: LoxBerry BYD Autos'),
        'ordner' => @is_dir($p['datadir']) ? $p['datadir'] : '',
        't'      => function ($s) { return by_t($s); },
        /* Gruppe 42 (E11): Sprechfrist 20 s - der Dienst bricht die Bruecke nach ANSAGE_FRIST_S = 25 s ab;
         * 5 s bleiben fuer den PHP-Start. Modul 1.2.1 wendet sie in jedem Weg an (ansage_frist()). */
        'frist'  => 20,
        /* K_TTS_EINTRAG: den Satz bringt das Modul seit 1.1.2 selbst mit; die Umlenkung auf
         * EINST.SICH_TTS_EINTRAG ist seit 0.9.25 gestrichen (X-10). Ab Werk aus - kein 'werk'. */
    );
}

/**
 * Der Satz zu einem Anlass, aus der Sprachdatei. $name ist der Fahrzeugname
 * aus dem Konto; fehlt er, heisst es "Fahrzeug <nr>". Der Ladestand nur, wenn
 * er bekannt ist; $grenze kennt BYD nicht (die Bruecke reicht ihn durch).
 */
function by_ansage_satz($anlass, $nr, $name, $soc = null, $grenze = null)
{
    $a = by_ansage_anlaesse();
    if (!isset($a[$anlass])) {
        return '';
    }
    $wer = ($name !== '') ? $name : sprintf(by_t('BY_ANSAGE.FAHRZEUG_NR'), (int) $nr);
    if ($anlass === 'ausfall') {
        return by_t('BY_ANSAGE.S_AUSFALL');
    }
    if ($anlass === 'kabel_vergessen') {
        return sprintf(by_t('BY_ANSAGE.S_KABEL'), $wer, (int) $soc);
    }
    $s = sprintf(by_t('BY_ANSAGE.S_LADEN_FERTIG'), $wer);
    return $soc === null ? $s : $s . ' ' . sprintf(by_t('BY_ANSAGE.S_LADESTAND'), (int) $soc);
}

/**
 * BYD-b2 (Verbesserungsbau 30.09.2026): die Sicherungsdatei - EINE Stelle
 * fuer den Knopf "Einstellungen sichern" und fuer die Pruefung X-3.
 *
 * Vorne der lesbare Kopf (Regeln/05): _hinweis sagt, was die Datei ist und
 * dass sie Geheimnisse traegt, _stand wann sie entstand. by_sicherung_lesen()
 * uebergeht jeden Schluessel, der mit "_" beginnt. Dahinter die VOLLE
 * Konfiguration samt Aktionstoken und die Zugangsdaten (C10).
 */
function by_sicherung_bauen()
{
    $c = by_config();
    /* Nr. 36 b: die Sprechtoken der Sprachausgabe gehen nie in eine Sicherung (Entwurf 5) -
     * anders als Aktionstoken und Zugangsdaten, die diese Linie bewusst mitsichert (C10). */
    if (isset($c['tts']) && is_array($c['tts'])) {
        $c['tts'] = ansage_sicherung_bereinigen($c['tts']);
    }
    unset($c['ansage_token_herkunft']);     // Stamm 1.3.2: der Vermerk geht nie in eine Sicherung
    return array_merge(array(
        '_hinweis' => by_t('EINST.SICH_KOPF_HINWEIS'),
        '_stand'   => date('Y-m-d H:i'),
    ), $c, by_sicherung_zugang());
}

/**
 * X-3 (Verbesserungsbau 30.09.2026): welche gespeicherten Werte bestuenden
 * das eigene Zurueckspielen nicht? Die Sicherung wird gebaut
 * (by_sicherung_bauen, derselbe Weg wie der Knopf) und durch
 * by_sicherung_lesen() geschickt - dieselbe Pruefung wie beim Zurueckspielen.
 * Rueckgabe: Liste der NAMEN, nie der Werte; leer = die Sicherung liesse sich
 * zurueckspielen. '?' = abgewiesen, ohne dass ein Name zu nennen waere.
 * Der Name traegt bewusst kein "sicherung": Werkzeuge/sicherung_pruefen.py
 * nimmt die erste Funktion *_sicherung* mit json_encode fuer die Ausfuhr
 * (Scheinbefund, gemessen an Heimkino in der Kette am 30.09.2026).
 */
function by_rueckspiel_altwerte($sicherung = null)
{
    $s = is_array($sicherung) ? $sicherung : by_sicherung_bauen();
    $js = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        // Nicht kodierbar: der Knopf meldet das selbst (SICH_SCHREIBFEHLER).
        return array();
    }
    $namen = array();
    $erg = by_sicherung_lesen($js, $namen);
    if ($erg[0] !== null) {
        return array();
    }
    return $namen ? array_values(array_unique($namen)) : array('?');
}

function by_sicherung_lesen($roh, &$namen = null)
{
    $mangel = array();
    $spaet = array();     // Welle Mehrfach-Ziele (0.9.29): Spaetliste - fehlte in der Sicherung, gilt weiter
    // X-3: die Namen der beanstandeten Schluessel, nie ihre Werte.
    $namen = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(by_t('EINST.SICH_KEIN_JSON')), 0, array(), array());
    }
    $neu = by_vorgaben();
    $bekannt = array_keys($neu);
    $grenzen = by_grenzen();
    $regeln = by_sicherung_regeln();
    $zugang = array();
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        /* Der lesbare Kopf einer Sicherungsdatei wird UEBERGANGEN, nicht
         * beanstandet - sonst lehnt die Funktion die Datei ab, die dieselbe
         * Bibliothek zwei Zeilen vorher erzeugt hat. Seit dem
         * Verbesserungsbau vom 30.09.2026 (BYD-b2) schreibt
         * by_sicherung_bauen() _hinweis und _stand, bei X-3 dazu _warnung. */
        if (is_string($k) && $k !== '' && $k[0] === '_') {
            continue;
        }
        /* C10: die Zugangsdaten stehen unter zugang_<feld>. Sie sind
         * freiwillig - eine Sicherung aus 0.9.18 oder frueher traegt sie
         * nicht und bleibt zurueckspielbar. */
        if (in_array($k, array('zugang_benutzer', 'zugang_passwort', 'zugang_pin',
                               'zugang_land'), true)) {
            $feld = substr($k, 7);
            $grund = by_wert_taugt($w) ? by_zugang_wert_pruefen($feld, $w) : 'EINST.SICH_R_ZUGANG';
            if ($grund !== '') {
                $mangel[] = sprintf(by_t('EINST.SICH_REGEL'),
                    htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'), by_t($grund));
                $namen[] = (string) $k;
                continue;
            }
            $zugang[$feld] = $w;
            continue;
        }
        if ((string) $k === 'tts') {
            /* Nr. 36 b (seit 0.9.23): eine Sicherung dieses Plugins traegt nie ein Sprechtoken -
             * traegt die Datei eines (auch als Liste, Zahl oder null), stammt sie nicht aus
             * "Einstellungen sichern" und wird abgewiesen; die geltenden Sprechtoken bleiben.
             * Ausgabeart, Adresse und Vorlage werden wie im Formular geprueft (Heimnetz). */
            $by_tm = ansage_sicherung_mangel($w);
            if ($by_tm) {
                $mangel[] = sprintf(by_t('EINST.SICH_TTS_TOKEN'),
                                    htmlspecialchars(implode(', ', $by_tm), ENT_QUOTES, 'UTF-8'));
                $namen[] = 'tts';
                continue;
            }
            $by_tg = '';
            $by_tp = ansage_wert_pruefen($w, $by_tg, by_ansage_modi());
            if ($by_tp === null) {
                $mangel[] = sprintf(by_t('EINST.SICH_TTS'), htmlspecialchars(
                    ansage_kennung_text($by_tg, by_ansage_k()), ENT_QUOTES, 'UTF-8'));
                $namen[] = 'tts';
                continue;
            }
            $by_tj = by_tts();
            /* Welle Mehrfach-Ziele (0.9.29): "$by_tp + $by_tj" behielt die geltenden weiteren Ziele einer Sicherung ohne
             * Liste schon bisher - still. Jetzt geht der Block vorher durch die Spaetliste (5. Rueckgabe), und die
             * Seite nennt sie. */
            $by_tneu = array('tts' => $by_tp);
            $by_tjetzt = array('tts' => $by_tj);
            /* Welle Mehrfach-Ziele (Sprachmodul 1.2.1, Spaetliste): fehlt die Liste der weiteren Ziele in der Sicherung (Datei einer
             * aelteren Fassung), gelten die jetzigen weiteren Ziele weiter - genannt im Hinweis. Eine Sicherung MIT leerer Liste
             * heisst "keine weiteren Ziele" und wird so uebernommen. */
            $by_spaete = array();
            if (is_array($by_tneu['tts']) && function_exists('ansage_sicherung_spaet')) {
                list($by_tneu['tts'], $by_spaete) = ansage_sicherung_spaet($by_tneu['tts'],
                    (isset($by_tjetzt['tts']) && is_array($by_tjetzt['tts'])) ? $by_tjetzt['tts'] : null);
            }
            foreach ($by_spaete as $by_sn) { $spaet[] = $by_sn; }     // Welle Mehrfach-Ziele: tts.ziele (Spaetliste)
            list($by_tv) = ansage_vervollstaendigen($by_tneu['tts'] + $by_tj, 'aus');
            $neu['tts'] = ansage_sicherung_tokens_behalten($by_tv, $by_tj);
            $anzahl++;
            continue;
        }
        if ($k === 'ansage_token_herkunft') { continue; }     // S2: nie aus einer Datei
        if ($k === 'ansage_regeln') {     // S2: ungueltig = abgewiesen, gueltig = uebernommen
            $s2_g = '';
            $s2_r = ansage_regeln_wert($w, $s2_g);
            if ($s2_r === null) { $mangel[] = by_t('EINST.SICH_REGELN'); $namen[] = 'ansage_regeln'; continue; }
            $neu['ansage_regeln'] = $s2_r;
            $anzahl++;
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(by_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            $namen[] = (string) $k;
            continue;
        }
        if (!by_wert_taugt($w)) {
            $mangel[] = sprintf(by_t('EINST.SICH_WERT'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            $namen[] = (string) $k;
            continue;
        }
        if (isset($grenzen[$k])) {
            $s = trim((string) $w);
            if (!preg_match('/^-?[0-9]+$/', $s)
                || (int) $s < $grenzen[$k][0] || (int) $s > $grenzen[$k][1]) {
                $mangel[] = sprintf(by_t('EINST.SICH_BEREICH'),
                                     htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'),
                                     $grenzen[$k][0], $grenzen[$k][1]);
                $namen[] = (string) $k;
                continue;
            }
            $w = (int) $s;
        } elseif (isset($regeln[$k])) {
            list($by_taugt, $by_wert, $by_grund) = by_sicherung_regel($regeln[$k], $w);
            if (!$by_taugt) {
                $mangel[] = sprintf(by_t('EINST.SICH_REGEL'),
                    htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'), by_t($by_grund));
                $namen[] = (string) $k;
                continue;
            }
            $w = $by_wert;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = by_t('EINST.SICH_LEER');
    }
    /* C9: die Paarregeln des Formulars. temp_min groesser als temp_max wird
     * abgewiesen, nicht getauscht (der Dienst tauschte still); die
     * Zieltemperatur der Vorklimatisierung muss dazwischen liegen. */
    if (!$mangel) {
        if ((int) $neu['temp_min'] > (int) $neu['temp_max']) {
            $mangel[] = by_t('EINST.FEHLER_TEMP_TAUSCH');
            $namen[] = 'temp_min';
            $namen[] = 'temp_max';
        } elseif ((int) $neu['abfahrt_temp'] < (int) $neu['temp_min']
                  || (int) $neu['abfahrt_temp'] > (int) $neu['temp_max']) {
            $mangel[] = sprintf(by_t('EINST.FEHLER_ABFAHRT_TEMP'), (int) $neu['abfahrt_temp'],
                                (int) $neu['temp_min'], (int) $neu['temp_max']);
            $namen[] = 'abfahrt_temp';
        }
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    $spaeter = array();
    foreach (array_keys(by_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            // Laden-1: eine Sicherung aus 0.9.21 oder frueher kennt die
            // Schluessel der Ladeempfehlung nach Strompreis nicht. Sie
            // behalten ihren jetzigen Wert (Bauform Heimkino/Matter2Lox,
            // Verbesserungsbauten Welle 1) - sonst wiese dieser Bau jede
            // aeltere Sicherung ab.
            if (in_array($fk, by_sicherung_spaeter(), true)) {
                $spaeter[] = $fk;
                continue;
            }
            $fehlend[] = $fk;
        }
    }
    if ($spaeter) {
        $geltend = by_config(false);
        foreach ($spaeter as $sk) {
            if (array_key_exists($sk, $geltend)) {
                $neu[$sk] = $geltend[$sk];
            }
        }
    }
    if ($fehlend) {
        $namen = array_merge($namen, $fehlend);
        $mangel[] = sprintf(by_t('EINST.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    /* C9: ein LEERES Token heisst "kein Token gesichert" - eingesetzt wird das
     * geltende. Bis 0.9.19 wurde '' gespeichert, und der naechste
     * Seitenaufruf wuerfelte still ein neues. Gibt es keines, entsteht es beim
     * naechsten Seitenaufruf - mit Protokollzeile (by_token()). */
    if (!$mangel && $neu['aktionstoken'] === '') {
        $geltend = by_config(false);
        $neu['aktionstoken'] = trim((string) (isset($geltend['aktionstoken'])
            ? $geltend['aktionstoken'] : ''));
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $mangel ? array() : $zugang, $mangel ? array() : $spaet);
}

/* ==================================================================
 * Gruppe 42 (0.9.29): Hausform - Gateway V2, Befehlsbruecke 1.0.2,
 * endpunkt_ein, Haus-Themen, SignalBot-Testknopf. Bauvorschrift
 * Pruefung-Durchgang-2026-09-29/wz_bild/GRUPPE42_BAUVORSCHRIFT.md (Anhang zu
 * GRUPPE41_BAUVORSCHRIFT.md), Vorlage VolkswagenID 0.9.33 (vw_v2_*,
 * vw_befehl_annehmen(), vw_bruecke_*), MGiSmart 1.1.28.
 * ================================================================== */

/** Die Schluessel, die mit 0.9.29 dazukamen - eine aeltere Sicherung kennt sie nicht; die Anlage behaelt ihren Wert. */
/* ---------------- Sammelthemen haus/fahrzeug (Regeln/07, Vorbild MGiSmart 1.1.31) ---------------- */

/** Die Werte des Formulars pruefen: normierter Wert oder null. kabel_soc 1..100, kabel_abfahrt_min 0..720, vorklima_min_soc 0..100, kabel_uhr HH:MM. Rein. */
function by_sammel_wert($k, $v)
{
    $v = trim((string) $v);
    if ($k === 'kabel_uhr') {
        return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $v) ? $v : null;
    }
    $g = array('kabel_soc' => array(1, 100), 'kabel_abfahrt_min' => array(0, 720), 'vorklima_min_soc' => array(0, 100));
    if (!isset($g[$k]) || !preg_match('/^[0-9]{1,3}$/', $v) || (int) $v < $g[$k][0] || (int) $v > $g[$k][1]) {
        return null;
    }
    return (int) $v;
}

/** Die Zeile im Reiter Test: array(Stand 1|0|-1, Text). Liest nur die Merker des Dienstes, nennt keinen Wert. 0: fremder Zaehler unter einem eigenen Stamm. */
function by_sammel_lage($cfg)
{
    if (empty($cfg['haus_fahrzeug_ein'])) {
        return array(-1, by_t('EINST.A_HAUS_FAHRZEUG_AUS'));
    }
    $fr = array();
    foreach (array_keys(by_json_lesen(by_paths()['datadir'] . '/haus_fahrzeug_fremd.json')) as $s) {
        if (is_string($s) && strpos($s, 'haus/fahrzeug/') === 0) { $fr[] = substr($s, strlen('haus/fahrzeug/')); }
    }
    if ($fr) {
        return array(0, sprintf(by_t('EINST.A_HAUS_FAHRZEUG_FREMD'), implode(', ', $fr)));
    }
    $m = by_json_lesen(by_paths()['datadir'] . '/haus_fahrzeug.json');
    $namen = array();
    if (isset($m['fahrzeuge']) && is_array($m['fahrzeuge'])) {
        foreach ($m['fahrzeuge'] as $e) {
            if (is_array($e) && isset($e['stamm']) && is_string($e['stamm'])) { $namen[] = substr($e['stamm'], strlen('haus/fahrzeug/')); }
        }
    }
    return $namen ? array(1, sprintf(by_t('EINST.A_HAUS_FAHRZEUG'), implode(', ', $namen))) : array(-1, by_t('EINST.A_HAUS_FAHRZEUG_NOCH'));
}

function by_neue_schluessel_0929()
{
    return array('befehle_mqtt_ein', 'befehle_mqtt_steuern', 'endpunkt_ein', 'haus_abfahrt_ein',
                 'vorklima_anwesend_ein', 'vorklima_wetter_ein', 'vorklima_kalt_unter', 'vorklima_heiss_ueber',
                 'ankunft_ein', 'ankunft_quelle', 'haus_strompreis_ein', 'ladeempf_quelle', 'signal_ein',
                 'signal_praefix', 'signal_laden_fertig', 'signal_ausfall', 'signal_vorklima',
                 /* 0.9.31 */ 'haus_fahrzeug_ein', 'kabel_soc', 'kabel_uhr', 'kabel_abfahrt_min', 'signal_kabel',
                 'vorklima_min_soc', 'vorklima_nur_kabel');
}

/** Sind Zugangsdaten eingetragen? (Benutzer und Passwort.) */
function by_zugang_da()
{
    $z = by_zugang();
    return $z['benutzer'] !== '' && $z['laenge'] > 0;
}

/* ---------------- G1: MQTT-Gateway V2 ---------------- */

/** Der Name, den das MQTT-Gateway vergibt: '/', '%' und Leerzeichen werden '_' (Regeln/07 :69). Rein. */
function by_gateway_name($thema)
{
    return str_replace(array('/', '%', ' '), '_', (string) $thema);
}

/** Die Fahrzeugnummern fuer die Hakenliste: aus dem Abbild, ohne Abbild Fahrzeug 1. */
function by_v2_fahrzeugnummern()
{
    $n = array();
    foreach (array_keys(by_fahrzeuge()) as $k) {
        if (preg_match('/^[0-9]{1,2}$/', (string) $k) && (int) $k >= 1) {
            $n[] = (int) $k;
        }
    }
    sort($n);
    return $n ? array_values(array_unique($n)) : array(1);
}

/**
 * Namenstabelle und V2-Hakenliste - EINE Quelle fuer Reiter MQTT, Reiter Test,
 * Linienpruefer und Messskript (G1). Aus den Tabellen in bin/byd.py (MQTT_OBEN,
 * FELDER, ABGELEITET), gelesen mit der vorhandenen by_themen_dienst() - keine
 * zweite Liste. Nicht: ts (Zeitstempel, Regeln/07 :267) und lade_empfehlung_grund
 * (Text; das Musterprojekt hat keinen Texteingang). Dazu status/befehle der Bruecke.
 * Noncached genau: ok, zaehler, fahrzeug<n>/OK, status/befehle.
 * Rueckgabe Liste von array(thema, name, noncached, bez).
 */
function by_v2_haken($cfg = null)
{
    if ($cfg === null) {
        $cfg = by_config(false);
    }
    $pf = trim((string) (isset($cfg['mqtt_topic']) ? $cfg['mqtt_topic'] : ''), '/ ');
    if ($pf === '') {
        $pf = 'byd';
    }
    $d = by_themen_dienst();
    $aus = array();
    if (!is_array($d) || !$d) {
        return $aus;   // leer -> "nicht feststellbar", nie "0 von 0"
    }
    $bez = by_mqtt_themen();
    $b = function ($k) use ($bez) {
        return isset($bez[$k]) && is_string($bez[$k]) ? $bez[$k] : '';
    };
    $fz = array();
    foreach ($d as $th) {
        if (strpos($th, 'fahrzeugN/') === 0) {
            $fz[] = substr($th, strlen('fahrzeugN/'));
            continue;
        }
        if ($th === 'ts' || $th === 'lade_empfehlung_grund') {
            continue;
        }
        $t = $pf . '/' . $th;
        $aus[] = array('thema' => $t, 'name' => by_gateway_name($t),
                       'noncached' => in_array($th, array('ok', 'zaehler'), true), 'bez' => $b($th));
    }
    if (!$fz) {
        return array();
    }
    foreach (by_v2_fahrzeugnummern() as $nr) {
        foreach ($fz as $feld) {
            $t = $pf . '/fahrzeug' . $nr . '/' . $feld;
            $aus[] = array('thema' => $t, 'name' => by_gateway_name($t), 'noncached' => $feld === 'OK',
                           'bez' => $b('fahrzeugN/' . $feld));
        }
    }
    $t = $pf . '/status/befehle';
    $aus[] = array('thema' => $t, 'name' => by_gateway_name($t), 'noncached' => true, 'bez' => 'BY_MQTT.STATUS_BEFEHLE');
    return $aus;
}

/** Die angehakten Datenpunkte aus subscriptions.json (Rein): Id => noncached. null = unlesbar. Abschrift mg_v2_abos(). */
function by_v2_abos($daten)
{
    if (!is_array($daten)) {
        return null;
    }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) {
            $liste = $daten[$s];
            break;
        }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) {
            continue;
        }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $id = $e[$k];
                break;
            }
        }
        if ($id === '') {
            continue;
        }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    return $abo;
}

/** Soll gegen Ist (Rein): array(1|0, fehlt[], noncached_falsch[], da, soll). Abschrift mg_v2_haken_abgleich(). */
function by_v2_haken_abgleich(array $soll, array $abo)
{
    $fehlt = array();
    $nc = array();
    $da = 0;
    foreach ($soll as $e) {
        if (!array_key_exists($e['thema'], $abo)) {
            $fehlt[] = $e['name'];
            continue;
        }
        $da++;
        if ((bool) $abo[$e['thema']] !== (bool) $e['noncached']) {
            $nc[] = $e['name'] . ($e['noncached'] ? ' (Noncached fehlt)' : ' (Noncached zu viel)');
        }
    }
    return array(($fehlt || $nc) ? 0 : 1, $fehlt, $nc, $da, count($soll));
}

/**
 * Selbsttestzeile "Gateway V2: alle Datenpunkte angehakt?" (einheitliche Pruefzeile, Nachtwelle 29.10.):
 * array(1|0|2, Text) - 2 = grau (nicht feststellbar, Gateway V1, noch nicht eingerichtet). Liest nur.
 * Die Argumente nach $cfg nur fuer Proben; sonst aus byd.py, general.json und subscriptions.json.
 */
function by_v2_haken_lage($cfg = null, $soll = null, $abo = false, $fassung = null, $eingerichtet = null)
{
    if ($cfg === null) {
        $cfg = by_config(false);
    }
    if ($fassung === null) {
        $m = by_mqtt_zustand();
        $fassung = !empty($m['gefunden']) ? (int) $m['fassung'] : 0;
    }
    if ((int) $fassung <= 0) {
        return array(2, by_t('PRUEF.V2_UNKLAR'));
    }
    if ((int) $fassung < 2) {
        return array(2, by_t('PRUEF.V2_V1'));
    }
    if ($soll === null) {
        $soll = by_v2_haken($cfg);
    }
    if (!$soll) {
        return array(2, by_t('PRUEF.V2_LEER'));
    }
    if ($eingerichtet === null) {
        $eingerichtet = by_zugang_da();
    }
    if (!$eingerichtet) {
        return array(2, by_t('PRUEF.V2_NICHT_EINGERICHTET'));
    }
    if ($abo === false) {
        $p = by_paths();
        $f = $p['home'] !== '' ? $p['home'] . '/config/system/subscriptions.json' : '';
        $abo = ($f !== '' && is_file($f)) ? by_v2_abos(json_decode((string) @file_get_contents($f), true)) : null;
    }
    if ($abo === null) {
        return array(2, by_t('PRUEF.V2_UNKLAR'));
    }
    list($ok, $fehlt, $nc, $da, $zahl) = by_v2_haken_abgleich($soll, $abo);
    $txt = sprintf(by_t('PRUEF.V2_ZAHL'), $da, $zahl);
    if ($fehlt) {
        $txt .= ' ' . sprintf(by_t('PRUEF.V2_FEHLT'), implode(', ', array_slice($fehlt, 0, 12))
            . (count($fehlt) > 12 ? ' ...' : ''));
    }
    if ($nc) {
        $txt .= ' ' . sprintf(by_t('PRUEF.V2_NC'), implode(', ', $nc));
    }
    return array($ok, $txt);
}

/* ---------------- G2: eine Annahmefunktion fuer HTTP und MQTT ---------------- */

/** Die lesenden Aktionen des Endpunkts (fuer die Zeile "Erlaubt sind: ..."). */
function by_lesende()
{
    return array('status', 'position', 'fahrzeuge', 'json');
}

/** Die Muster der Zusatzwerte - dieselben wie by_param() im Endpunkt (abweisen, nie zurechtbiegen). */
function by_befehl_muster()
{
    return array('fahrzeug' => '/^([0-9]{1,2}|[A-Za-z0-9]{17})$/', 'temp' => '/^[0-9]{1,2}([.,][05])?$/',
                 'stufe' => '/^[0-9]{1,2}$/', 'minuten' => '/^[0-9]{1,2}$/');
}

/** Fahrzeug zur Nummer oder VIN - dieselbe Suche wie by_waehlen() im Endpunkt. null = unbekannt. */
function by_fahrzeug_suchen(array $alle, $schluessel)
{
    if (isset($alle[$schluessel])) {
        return $alle[$schluessel];
    }
    foreach ($alle as $f) {
        if (isset($f['vin']) && $f['vin'] !== '' && strcasecmp((string) $f['vin'], (string) $schluessel) === 0) {
            return $f;
        }
    }
    return null;
}

/**
 * Einen fremden Text so saeubern, dass er in EIN Feld der Statuszeile passt (kein ';', kein Zeilenumbruch).
 * Gruppe 42 (0.9.29): aus webfrontend/html/index.php hierher verlegt - die Antwortzeilen baut jetzt
 * by_befehl_annehmen(), fuer den Endpunkt UND die Befehlsbruecke. Wortgleich; das '=' bleibt stehen.
 */
function by_sicher($text)
{
    return str_replace(array("\r", "\n", ';'), ' ', (string) $text);
}

/**
 * Einen schaltenden Befehl annehmen - DIESELBE Funktion fuer den HTTP-Endpunkt und
 * die Befehlsbruecke (Bauvorschrift G42/G41 G2, Regeln/07 Abschnitt 3a). Reihenfolge
 * wie der BYD-Endpunkt bis 0.9.28 (dort inline): Aktion bekannt -> Muster der
 * Zusatzwerte -> Steuerung (ausser abruf) -> zweiter Haken (gefahr_ein, EINGRIFF_GESPERRT)
 * -> Zusatzwert vorhanden (TEMP_FEHLT/STUFE_FEHLT) -> nur MQTT: Fahrzeug im Abbild
 * (FAHRZEUG_UNBEKANNT; der HTTP-Weg bekommt die Absage des Dienstes in der Wartezeit)
 * -> X-7-Gleichwert-Bremse (60 s, beide Wege) -> Einreihen (by_befehl_absetzen()
 * prueft den Dienst). HTTP wartet wie bisher ('wartezeit'); MQTT wartet nie (Quittung
 * "uebergeben", E4). Ueber MQTT prueft die Bruecke vorher den zweiten Haken
 * (befehle_mqtt_steuern UND gefahr_ein, E6).
 *
 * Rueckgabe array('ok' => 0|1|2, 'grund', 'http' => Code, 'zeilen' => array(Zeile, Zusatz) der
 * Endpunktantwort, 'text' => Satz, 'art' => abgewiesen|gesetzt|unveraendert).
 */
function by_befehl_annehmen($aktion, array $param, $fahrzeug, $quelle)
{
    $mqtt = ($quelle === 'mqtt');
    $aktion = is_string($aktion) ? $aktion : '';
    $raus = function ($grund, $http, $zeile, $zusatz = '') {
        return array('ok' => 0, 'grund' => $grund, 'http' => $http, 'zeilen' => array($zeile, $zusatz),
                     'text' => $zusatz, 'art' => 'abgewiesen');
    };
    $alle_befehle = by_befehle();
    if (!isset($alle_befehle[$aktion])) {
        return $raus('UNBEKANNTE_AKTION', 400, 'BYD;OK=0;GRUND=UNBEKANNTE_AKTION',
            'Erlaubt sind: ' . implode(', ', array_merge(by_lesende(), array_keys($alle_befehle))));
    }
    $eig = $alle_befehle[$aktion];
    $fahrzeug = is_scalar($fahrzeug) ? (string) $fahrzeug : '';
    if ($fahrzeug === '') {
        $fahrzeug = '1';
    }
    $w = function ($k) use ($param) {
        return (isset($param[$k]) && is_scalar($param[$k])) ? (string) $param[$k] : '';
    };
    $werte = array('fahrzeug' => $fahrzeug, 'temp' => $w('temp'), 'stufe' => $w('stufe'), 'minuten' => $w('minuten'));
    foreach (by_befehl_muster() as $k => $muster) {
        if ($werte[$k] !== '' && (strlen($werte[$k]) > 32 || !preg_match($muster, $werte[$k]))) {
            return $raus('PARAMETER', 400, 'BYD;OK=0;GRUND=PARAMETER',
                'Der Wert von ' . $k . ' passt nicht ins erlaubte Muster.');
        }
    }
    $cfg = by_config(false);
    if (by_befehl_schaltet($aktion) && empty($cfg['steuerung_ein'])) {
        return $raus('STEUERUNG_AUS', 403, 'SET;OK=0;GRUND=STEUERUNG_AUS',
            'Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken "Schreibende Befehle zulassen".');
    }
    if (by_befehl_eingreifend($aktion) && empty($cfg['gefahr_ein'])) {
        return $raus('EINGRIFF_GESPERRT', 403, 'SET;OK=0;GRUND=EINGRIFF_GESPERRT',
            'Dieser Befehl greift in ein Fahrzeug ein, das im oeffentlichen Raum steht. Reiter Einstellungen, '
            . 'zweiter Haken "Eingreifende Befehle zulassen".');
    }
    $befehl = array('aktion' => $aktion, 'fahrzeug' => $fahrzeug);
    if ($mqtt) {
        $befehl['von'] = 'mqtt';
    }
    if ($eig['zusatz'] === 'temp') {
        if ($w('temp') === '') {
            return $raus('TEMP_FEHLT', 400, 'SET;OK=0;GRUND=TEMP_FEHLT',
                'Der Parameter temp fehlt (Zieltemperatur in Grad Celsius).');
        }
        $befehl['temp'] = str_replace(',', '.', $w('temp'));
        if ($w('minuten') !== '') {
            $befehl['minuten'] = (int) $w('minuten');
        }
    } elseif ($eig['zusatz'] === 'stufe') {
        if ($w('stufe') === '') {
            return $raus('STUFE_FEHLT', 400, 'SET;OK=0;GRUND=STUFE_FEHLT',
                'Der Parameter stufe fehlt. 0 schaltet aus, ein hoeherer Wert ein - welche '
                . 'Stufen dieses Fahrzeug kennt, sagt der Reiter Test.');
        }
        $befehl['stufe'] = (int) $w('stufe');
    }
    $alle = by_fahrzeuge();
    $fz = $alle ? by_fahrzeug_suchen($alle, $fahrzeug) : null;
    if ($mqtt && $aktion !== 'abruf' && $alle && $fz === null) {
        // Nur MQTT (Bauvorschrift G42 G2 Schritt 6): nie auf Fahrzeug 1 zurueckfallen.
        return $raus('FAHRZEUG_UNBEKANNT', 404, 'SET;OK=0;GRUND=FAHRZEUG_UNBEKANNT',
            'Fahrzeug ' . by_sicher($fahrzeug) . ' ist nicht bekannt.');
    }
    // X-7: Gleichwert-Unterdrueckung fuer Sollwerte - auf beiden Wegen (ein abgewiesener Aufruf merkt nichts).
    $gw = by_gleichwert_gruppe($befehl);
    $gw_schl = '';
    $gw_marke = '';
    if ($gw !== null) {
        $gw_schl = by_gleichwert_fz($fz, $fahrzeug) . '|' . $gw[0];
        list($urteil, $seit, $gw_marke) = by_gleichwert_pruefen($gw_schl, $gw[1]);
        if ($urteil === 'MERKER') {
            by_log('Endpunkt: der Merker der Gleichwert-Unterdrueckung (' . by_gleichwert_datei()
                 . ') laesst sich nicht oeffnen, sperren oder schreiben - Sollwert-Befehle werden '
                 . 'mit 503 abgewiesen, bis das behoben ist.', 'ERROR');
            return $raus('BREMSE_MERKER', 503, sprintf('SET;OK=0;AKTION=%s;GRUND=BREMSE_MERKER', $aktion),
                'Die Merkerdatei der Gleichwert-Unterdrueckung laesst sich nicht oeffnen oder '
                . 'schreiben - Sollwert-Befehle werden abgewiesen, bis das behoben ist. Pruefen: '
                . 'Platz und Eigentuemer (loxberry) des Datenordners des Plugins.');
        }
        if ($urteil === 'UNVERAENDERT') {
            return array('ok' => 1, 'grund' => 'UNVERAENDERT', 'http' => 200, 'art' => 'unveraendert',
                'zeilen' => array(sprintf('SET;OK=1;AKTION=%s;UNVERAENDERT=1;SEIT_S=%d;MELDUNG=%s', $aktion,
                    (int) $seit, by_sicher('Derselbe Befehl ging vor ' . (int) $seit
                        . ' s hinaus - es wurde nichts gesendet. Gleiche Sollwerte innerhalb von '
                        . BY_GLEICHWERT_S . ' s gehen nur einmal an BYD.')), ''),
                'text' => 'unveraendert');
        }
    }
    try {
        list($erg, $meldung) = by_befehl_absetzen($befehl, $mqtt ? 0 : null);
    } catch (Throwable $t) {
        if ($gw_marke !== '') {
            by_gleichwert_vergessen($gw_schl, $gw_marke);
        }
        by_log('Endpunkt: der Befehl ' . $aktion . ' ist abgestuerzt: ' . $t->getMessage(), 'ERROR');
        return array('ok' => 0, 'grund' => 'ABSTURZ', 'http' => 500, 'art' => 'abgewiesen', 'text' => '',
            'zeilen' => array(sprintf('SET;OK=0;AKTION=%s;ERR=%s (%s:%d)', $aktion,
                by_sicher($t->getMessage()), basename($t->getFile()), $t->getLine()), ''));
    }
    $erg = (int) $erg;
    if ($gw_marke !== '' && $erg === 0) {
        by_gleichwert_vergessen($gw_schl, $gw_marke);
    }
    $grund = '';
    if ($erg === 0) {
        $grund = (by_dienst_pid() === 0) ? 'DIENST_LAEUFT_NICHT' : 'ABGELEHNT';
    }
    if ($mqtt && $erg === 2) {
        $meldung = 'Eingereiht - der Dienst arbeitet den Befehl ab; das Ergebnis steht im Protokoll und im Reiter Test.';
    }
    return array('ok' => $erg, 'grund' => $grund, 'http' => $erg === 0 ? 400 : ($erg === 2 ? 202 : 200),
                 'zeilen' => array(sprintf('SET;OK=%d;AKTION=%s;MELDUNG=%s', $erg, $aktion, by_sicher($meldung)), ''),
                 'text' => (string) $meldung, 'art' => 'gesetzt');
}

/** JSON atomar schreiben (Rechte vor dem Umbenennen), false bei Fehler. */
function by_g42_json_schreiben($ziel, $daten, $rechte = 0644)
{
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $ziel . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, $rechte);
    $ok = @ftruncate($fh, 0) && @fwrite($fh, $json) === strlen($json);
    @fflush($fh);
    @fclose($fh);
    if (!$ok || !@rename($tmp, $ziel)) {
        @unlink($tmp);
        return false;
    }
    @chmod($ziel, $rechte);
    return true;
}

/** Quittung der Bruecke: array(true, '') bei ok >= 1 (eingereiht oder unveraendert), sonst array(false, Grund). */
function by_befehl_quittung($erg)
{
    if (is_array($erg) && isset($erg['ok']) && (int) $erg['ok'] >= 1) {
        return array(true, '');
    }
    return array(false, (is_array($erg) && isset($erg['grund']) && $erg['grund'] !== '') ? (string) $erg['grund'] : 'FEHLER');
}

/** G5/E-E4: die Aussentemperatur aus Loxone merken (Bruecke <p>/befehl/aussentemp). Datei 0600, atomar. */
function by_aussentemp_merken($wert)
{
    $p = by_paths();
    if (!is_numeric($wert) || (float) $wert < -40 || (float) $wert > 60) {
        return array('ok' => 0, 'grund' => 'WERT', 'http' => 400, 'text' => '', 'art' => 'abgewiesen');
    }
    if (!is_dir($p['datadir'])) {
        return array('ok' => 0, 'grund' => 'DATENORDNER_FEHLT', 'http' => 503, 'text' => '', 'art' => 'abgewiesen');
    }
    if (!by_g42_json_schreiben($p['datadir'] . '/aussentemp.json', array('wert' => round((float) $wert, 1), 't' => time()), 0600)) {
        return array('ok' => 0, 'grund' => 'MERKER', 'http' => 503, 'text' => '', 'art' => 'abgewiesen');
    }
    return array('ok' => 1, 'grund' => '', 'http' => 200, 'text' => 'gemerkt', 'art' => 'gesetzt');
}

/* ---------------- G2: Befehlsbruecke fuer die Oberflaeche (Abschrift vw_bruecke_*) ---------------- */

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN). Rueckgabe true, wenn bb_waechter() danach da ist. */
function by_bruecke_laden()
{
    if (function_exists('bb_waechter')) {
        return true;
    }
    $p = by_paths();
    $kand = array();
    if ($p['home'] !== '') {
        $kand[] = $p['home'] . '/bin/plugins/' . $p['plugin'] . '/befehlsbruecke.php';
    }
    $kand[] = dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    foreach ($kand as $k) {
        if (is_file($k)) {
            if (!defined('BB_NUR_LADEN')) {
                define('BB_NUR_LADEN', true);
            }
            require_once $k;
            return function_exists('bb_waechter');
        }
    }
    return false;
}

/** Die Befehlsliste der Bruecke (bb_liste()). array(Liste oder null, Fehlertext). */
function by_bruecke_liste()
{
    if (!by_bruecke_laden()) {
        return array(null, by_t('BEFEHL.T_NICHT_DA'));
    }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) {
        return array(null, $fehler);
    }
    $GLOBALS['bb_zuordnung'] = $z;
    list($fehl) = bb_zuordnung_pruefen($z);
    if ($fehl) {
        return array(null, $fehl[0]);
    }
    return array(bb_liste($z, bb_einstellungen($z)), '');
}

/** Nach dem Speichern im Reiter MQTT: start (Haken an) bzw. stop. array(rc|null, start|stop|'', Ausgabe). */
function by_bruecke_schalten($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = by_config(false);
    }
    if (!by_bruecke_laden()) {
        return array(1, '', by_t('BEFEHL.T_NICHT_DA'));
    }
    $p = bb_pfade();
    if (!$p) {
        return array(1, '', 'keine LoxBerry-Installation erkannt');
    }
    if (!empty($cfg['befehle_mqtt_ein'])) {
        list($rc, $aus) = bb_sh($p, 'start');
        return array($rc, 'start', $aus);
    }
    if (!bb_dienste()) {
        return array(null, '', '');
    }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array($rc, 'stop', $aus);
}

/** Lage der Bruecke (Reiter Test, Reiter MQTT): array(true|false|null, Text); null = aus (Haken). */
function by_bruecke_lage()
{
    if (!by_bruecke_laden()) {
        return array(false, by_t('BEFEHL.T_NICHT_DA'));
    }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) {
        return array(null, by_t('BEFEHL.T_AUS'));
    }
    if ($soll !== 0) {
        return array(false, sprintf(by_t('BEFEHL.T_DEFEKT'), $grund));
    }
    $zu = bb_zustand_lesen();
    $pids = bb_dienste();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if ($pids && $alter !== null && $alter <= BB_WACHE_GRENZE && !empty($zu['abo'])) {
        $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
        $txt = sprintf(by_t('BEFEHL.T_LAEUFT'), count($pids),
            isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0, isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0);
        $lz = isset($zu['letzter']) && is_array($zu['letzter']) ? $zu['letzter'] : null;
        if ($lz !== null && isset($lz['name'])) {
            $txt .= ' ' . sprintf(by_t('BEFEHL.T_LETZTER'), (string) $lz['name'],
                isset($lz['ergebnis']) ? (string) $lz['ergebnis'] : '-',
                isset($lz['zeit']) ? max(0, time() - (int) $lz['zeit']) : 0);
        } else {
            $txt .= ' ' . by_t('BEFEHL.T_KEINER');
        }
        return array(true, $txt);
    }
    $wie = !$pids ? 'kein Dienst' : ($alter === null ? 'kein Zustand' : ($alter > BB_WACHE_GRENZE
        ? 'Zustand ' . $alter . ' s alt' : 'ohne Abo'));
    return array(false, sprintf(by_t('BEFEHL.T_STEHT'), $wie));
}

/** befehlsbruecke.php --pruefen: array(rc, Zeilen). */
function by_bruecke_pruefen()
{
    if (!by_bruecke_laden()) {
        return array(1, array(by_t('BEFEHL.T_NICHT_DA')));
    }
    $p = bb_pfade();
    $skript = $p ? $p['bin'] . '/befehlsbruecke.php' : dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $aus = array();
    $rc = 1;
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($skript) . ' --pruefen 2>&1', $aus, $rc);
    return array((int) $rc, $aus);
}

/** Der Rechnername fuer UDP-Adressen: nie die Rueckschleife (Regeln/07). */
function by_rechnername()
{
    $h = by_host();
    if (substr_count($h, ':') === 1) {
        $h = substr($h, 0, strpos($h, ':'));
    }
    if (in_array(strtolower($h), array('', '127.0.0.1', 'localhost', '::1', '[::1]'), true)) {
        $g = gethostname();
        $h = ($g !== false && $g !== '') ? $g : 'loxberry';
    }
    return $h;
}

/** Die Vorlage "BYD MQTT-Befehle" aus bb_vorlage() (CRLF, /dev/udp/<rechner>/<Udpinport>). array(Name, Inhalt|''). */
function by_vorlage_befehle()
{
    list($l) = by_bruecke_liste();
    if ($l === null) {
        return array('VQ_bydautos_mqtt.xml', '');
    }
    $bp = bb_pfade();
    $port = $bp ? bb_broker($bp)['udpin'] : 11884;
    return array('VQ_bydautos_mqtt.xml', bb_vorlage($l, by_rechnername(), $port));
}

/* ---------------- G4/G5: Haus-Quellen fuer den Reiter Test ---------------- */

/** Welche Haus-Quellen liest der Dienst bei dieser Konfiguration? Name => Satz des Rueckfalls. Dieselbe Bedingung wie haus_themen() in bin/byd.py. */
function by_haus_quellen($cfg = null)
{
    if ($cfg === null) {
        $cfg = by_config(false);
    }
    $q = array();
    if (!empty($cfg['abfahrt_ein']) && !empty($cfg['haus_abfahrt_ein'])) {
        $q['abfahrt'] = 'HAUS.RUECK_ABFAHRT';
    }
    if ((!empty($cfg['abfahrt_ein']) && !empty($cfg['vorklima_anwesend_ein']))
        || (!empty($cfg['ankunft_ein']) && trim((string) $cfg['ankunft_quelle']) !== '')) {
        $q['anwesenheit'] = 'HAUS.RUECK_ANWESENHEIT';
    }
    if (!empty($cfg['haus_strompreis_ein'])
        || (!empty($cfg['ladeempf_ein']) && $cfg['ladeempf_quelle'] === 'haus')
        || (!empty($cfg['preisempf_ein']) && $cfg['preisempf_quelle'] === 'haus')) {
        $q['strompreis'] = 'HAUS.RUECK_STROMPREIS';
    }
    return $q;
}

/** Die Zeilen der Haus-Quellen aus zustand.json ('haus'): Liste von array(Bezeichnung, 1|0, Text). */
function by_haus_pruefzeilen($cfg = null)
{
    $z = by_zustand();
    $h = isset($z['haus']) && is_array($z['haus']) ? $z['haus'] : array();
    $aus = array();
    foreach (by_haus_quellen($cfg) as $q => $rueck) {
        $e = isset($h[$q]) && is_array($h[$q]) ? $h[$q] : array();
        $bez = sprintf(by_t('HAUS.ZEILE'), 'haus/' . $q);
        if (!empty($e['frisch'])) {
            $aus[] = array($bez, 1, sprintf(by_t('HAUS.FRISCH'), isset($e['alter']) ? (int) $e['alter'] : 0));
        } elseif (isset($e['seit']) && (int) $e['seit'] > 0) {
            $aus[] = array($bez, 0, sprintf(by_t('HAUS.SCHWEIGT'), max(0, (int) round((time() - (int) $e['seit']) / 60)))
                . ' ' . by_t($rueck));
        } else {
            $aus[] = array($bez, 0, by_t('HAUS.NIE') . ' ' . by_t($rueck));
        }
    }
    return $aus;
}

/** G5: Zeile Vorklimatisierung im Reiter Test aus merker.json des Dienstes: array(1|0|2, Text) oder null (aus). */
function by_vorklima_pruefzeile($cfg = null)
{
    if ($cfg === null) {
        $cfg = by_config(false);
    }
    if (empty($cfg['abfahrt_ein'])) {
        return null;
    }
    $nr = (string) max(1, (int) $cfg['abfahrt_fahrzeug']);
    $d = by_json_lesen(by_paths()['datadir'] . '/merker.json');
    $m = (isset($d['vorklima'][$nr]) && is_array($d['vorklima'][$nr])) ? $d['vorklima'][$nr] : array();
    if (by_fahrzeuge() && !by_fahrzeug_suchen(by_fahrzeuge(), $nr)) {
        return array(0, sprintf(by_t('HAUS.VK_FZ_FEHLT'), $nr));
    }
    if (!isset($m['kennung'])) {
        return array(2, sprintf(by_t('HAUS.VK_NICHTS'), $nr));
    }
    $st = !empty($m['aufgegeben']) ? 0 : 1;
    return array($st, sprintf(by_t('HAUS.VK_STAND'), $nr, date('H:i', (int) $m['kennung']),
        isset($m['versuche']) ? (int) $m['versuche'] : 0,
        !empty($m['erfolg']) ? by_t('HAUS.VK_ERFOLG') : (!empty($m['mild']) ? by_t('HAUS.VK_MILD')
            : (!empty($m['aufgegeben']) ? by_t('HAUS.VK_AUFGEGEBEN') : by_t('HAUS.VK_OFFEN')))));
}

/* ---------------- G8-Zusatz: Testmeldung an SignalBot (Reiter Einstellungen) ---------------- */

/** Die Zeile "SIGNAL_TEST;ERGEBNIS=<e>;TEXT=<t>" lesen (Rein). Unbekanntes zaehlt als nicht_moeglich - nie als angenommen. */
function by_signal_test_lesen(array $zeilen)
{
    foreach ($zeilen as $z) {
        if (preg_match('/^SIGNAL_TEST;ERGEBNIS=([a-z_]+);TEXT=(.*)$/', trim((string) $z), $m)
            && in_array($m[1], array('angenommen', 'abgelehnt', 'keine_antwort', 'aus', 'nicht_moeglich'), true)) {
            return array($m[1], substr($m[2], 0, 200));
        }
    }
    return array('nicht_moeglich', '');
}

/** Testmeldung an SignalBot ueber byd.py --signal-test (ab Werk wirkungslos). array(Ergebnis, Satz). */
function by_signal_test($cfg = null)
{
    if ($cfg === null) {
        $cfg = by_config(false);
    }
    if (empty($cfg['signal_ein'])) {
        return array('aus', by_t('SIGNAL.T_AUS'));
    }
    $p = by_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/byd.py';
    if (!is_file($py) || !is_file($skript)) {
        return array('nicht_moeglich', by_t('SIGNAL.T_NICHT_MOEGLICH'));
    }
    $aus = array();
    @exec(escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' --signal-test 2>/dev/null', $aus);
    list($e, $text) = by_signal_test_lesen($aus);
    $schl = array('angenommen' => 'SIGNAL.T_ANGENOMMEN', 'abgelehnt' => 'SIGNAL.T_ABGELEHNT',
                  'keine_antwort' => 'SIGNAL.T_KEINE_ANTWORT', 'aus' => 'SIGNAL.T_AUS',
                  'nicht_moeglich' => 'SIGNAL.T_NICHT_MOEGLICH');
    $satz = by_t($schl[$e]);
    return array($e, $e === 'abgelehnt' ? sprintf($satz, $text) : $satz);
}


/* ---------------- Healthcheck (Nachtrag 29.10.2026, bin/healthcheck) ---------------- */

/**
 * Das Urteil des LoxBerry-Healthchecks (bin/healthcheck), rein - Form von Renault NG 2.1.20
 * (rn_gesund_urteil/rn_gesund_fakten). Regeln/03 :73 (Skala 3 Fehler, 4 Warnung, 5 in Ordnung, 6 Hinweis),
 * Regeln/04 :809 (nie auf einen einzelnen Abruf: geurteilt wird ueber das ALTER von Lebenszeichen und letztem
 * gelungenem Abruf), Regeln/04 :826 (ein Text nennt nur Zustaende, die in derselben Verzweigung gemessen sind).
 * $f: eingerichtet (bool), soll (bool, Sollmerker), pid (int; 0 = kein Prozess des Plugins - gefunden ueber
 * /proc/<pid>/cmdline, die PID-Datei allein genuegt nicht), start_alter_s (int, -1 = unbekannt),
 * leben_alter_s (int, -1 = keins), takt_s (int), erfolg_alter_s (int, -1 = noch nie), fehler_folge (int),
 * fahrzeuge (int), mqtt_ein (bool), gateway (array gefunden/autostart/udpport), broker_fehler (bool|null,
 * null = keine Aussage), v2 (array(lage, Text)), bruecke_soll (bool), bruecke_laeuft (bool|null).
 * Rueckgabe array(status, Text); der Text traegt keine Zugangsdaten, VIN, Kennzeichen oder Fahrzeugnamen.
 */
function by_gesund_urteil(array $f)
{
    $f += array('eingerichtet' => false, 'soll' => false, 'pid' => 0, 'start_alter_s' => -1, 'leben_alter_s' => -1,
                'takt_s' => 300, 'erfolg_alter_s' => -1, 'fehler_folge' => 0, 'fahrzeuge' => 0, 'mqtt_ein' => false,
                'gateway' => array(), 'broker_fehler' => null, 'v2' => array(null, ''), 'bruecke_soll' => false,
                'bruecke_laeuft' => null);
    if (!$f['eingerichtet']) {
        return array(6, 'nicht eingerichtet - Zugangsdaten im Reiter Einstellungen eintragen');
    }
    $pid = (int) $f['pid'];
    if (!$f['soll'] && $pid <= 0) {
        return array(6, 'Abrufdienst angehalten (so eingestellt, kein Fehler) - Start im Reiter Einstellungen');
    }
    $befunde = array();
    $status = 5;
    $setze = function ($s, $t) use (&$status, &$befunde) {
        if ($s < $status) {
            $status = $s;
        }
        $befunde[] = $t;
    };
    $takt = max(60, (int) $f['takt_s']);
    if ($pid <= 0) {
        $setze(3, 'Abrufdienst soll laufen, es laeuft aber keiner');
    } else {
        $a = (int) $f['leben_alter_s'];
        $g = max(600, 3 * $takt);
        if ($a < 0) {
            $setze(4, 'Abrufdienst laeuft, hat aber noch kein Lebenszeichen geschrieben');
        } elseif ($a > $g) {
            $setze(3, sprintf('Abrufdienst laeuft, meldet sich aber seit %d min nicht mehr (erwartet hoechstens %d min)',
                (int) round($a / 60), (int) round($g / 60)));
        }
    }
    /* Nach einem Neustart (auch cron.reboot) zaehlt die Zeit SEIT DEM START: ein Erfolg von vor dem Herunterfahren
     * ist kein Befund ueber den laufenden Dienst. */
    $e = (int) $f['erfolg_alter_s'];
    $s0 = (int) $f['start_alter_s'];
    $seit_start = $pid > 0 && $s0 >= 0 && ($e < 0 || $e > $s0);
    $e_eff = $seit_start ? $s0 : $e;
    $folge = (int) $f['fehler_folge'] > 0 ? sprintf(' (%d Fehlversuche in Folge)', (int) $f['fehler_folge']) : '';
    $satz = $seit_start ? 'seit dem Start vor %d min kein gelungener Abruf' : 'letzter gelungener Abruf vor %d min';
    if ($e_eff < 0) {
        $setze(4, 'noch kein gelungener Abruf' . $folge);
    } elseif ($e_eff > max(3600, 6 * $takt)) {
        $setze(3, sprintf($satz, (int) round($e_eff / 60)) . $folge);
    } elseif ($e_eff > max(900, 3 * $takt)) {
        $setze(4, sprintf($satz, (int) round($e_eff / 60)) . $folge);
    }
    if ($f['mqtt_ein']) {
        $gw = is_array($f['gateway']) ? $f['gateway'] : array();
        if (empty($gw['gefunden']) || empty($gw['udpport'])) {
            $setze(4, 'MQTT eingeschaltet, aber kein MQTT-Gateway mit UDP-Eingang eingerichtet - es wird nichts gesendet');
        } elseif (empty($gw['autostart'])) {
            $setze(4, 'MQTT eingeschaltet, aber das MQTT-Gateway steht nicht auf Autostart');
        }
    }
    if ($f['broker_fehler'] === true) {
        $setze(4, 'keine Verbindung zum MQTT-Broker - Haus-Themen (Abfahrt, Anwesenheit, Strompreis) kommen nicht an');
    }
    if (is_array($f['v2']) && isset($f['v2'][0]) && $f['v2'][0] === 0) {
        $setze(4, 'Gateway V2: ' . (isset($f['v2'][1]) ? (string) $f['v2'][1] : ''));
    }
    if ($f['bruecke_soll'] && $f['bruecke_laeuft'] === false) {
        $setze(4, 'Befehle ueber MQTT eingeschaltet, aber die Befehlsbruecke steht');
    }
    if (!$befunde) {
        if ($seit_start) {
            return array(5, sprintf('in Ordnung - Abrufdienst laeuft seit %d min, %d Fahrzeug(e), seit dem Start noch '
                . 'kein gelungener Abruf', (int) round($s0 / 60), (int) $f['fahrzeuge']));
        }
        return array(5, sprintf('in Ordnung - Abrufdienst laeuft, %d Fahrzeug(e), letzter gelungener Abruf vor %d min',
            (int) $f['fahrzeuge'], (int) round($e / 60)));
    }
    return array($status, implode('; ', $befunde));
}

/** Die Fakten des Healthchecks - nur lesend: kein Netz, kein Broker, keine Anmeldung, keine Datei, kein Protokoll. */
function by_gesund_fakten(array $cfg)
{
    clearstatcache();
    $p = by_paths();
    $jetzt = time();
    $f = array();
    $f['eingerichtet'] = (bool) by_zugang_da();
    $f['soll'] = (int) by_dienst_soll() === 1;
    $f['pid'] = (int) by_dienst_pid();
    /* Der Start des laufenden Dienstes: die PID-Datei, wenn sie genau diesen Prozess nennt. */
    $start = 0;
    $pf = $p['datadir'] . '/dienst.pid';
    if ($f['pid'] > 0 && is_file($pf) && (int) trim((string) @file_get_contents($pf)) === $f['pid']) {
        $start = (int) @filemtime($pf);
    }
    $f['start_alter_s'] = $start > 0 ? max(0, $jetzt - $start) : -1;
    $zu = json_decode((string) @file_get_contents($p['datadir'] . '/zustand.json'), true);
    $zu = is_array($zu) ? $zu : array();
    $zts = isset($zu['ts']) && is_numeric($zu['ts']) ? (int) $zu['ts'] : 0;
    $zu_frisch = $zts > 0 && $zts >= $start;          // vom laufenden Dienst geschrieben, nicht von einem frueheren
    $lox = json_decode((string) @file_get_contents($p['datadir'] . '/loxone.json'), true);
    $lox = is_array($lox) ? $lox : array();
    $f['takt_s'] = isset($cfg['intervall']) && is_numeric($cfg['intervall']) ? (int) $cfg['intervall'] : 300;
    if ($zu_frisch && isset($zu['intervall']) && is_numeric($zu['intervall'])) {
        $f['takt_s'] = (int) $zu['intervall'];
    }
    /* BYD: das Lebenszeichen der Hauptschleife ist log/plugins/<ordner>/herzschlag (Unixzeit, alle 30 s; bin/dienst.sh
     * arbeitet() misst dasselbe). Liegt es nicht (Ramdisk nach einem Neustart), zaehlt der Start. */
    $hz = trim((string) @file_get_contents($p['logdir'] . '/herzschlag'));
    $lts = preg_match('/^[0-9]+$/', $hz) ? (int) $hz : 0;
    $lts = max($lts, $start);
    $f['leben_alter_s'] = $lts > 0 ? max(0, $jetzt - $lts) : -1;
    $ts = isset($lox['ts']) && is_numeric($lox['ts']) ? (int) $lox['ts'] : 0;
    $f['erfolg_alter_s'] = $ts > 0 ? max(0, $jetzt - $ts) : -1;
    $f['fahrzeuge'] = isset($lox['fahrzeuge']) && is_array($lox['fahrzeuge']) ? count($lox['fahrzeuge']) : 0;
    $f['fehler_folge'] = ($zu_frisch && isset($zu['fehler_folge']) && is_numeric($zu['fehler_folge']))
        ? (int) $zu['fehler_folge'] : 0;
    $f['mqtt_ein'] = !empty($cfg['mqtt_ein']);
    $f['gateway'] = by_mqtt_zustand();
    $f['broker_fehler'] = null;
    if ($f['pid'] > 0 && $zu_frisch) {
        $fe = isset($zu['horcher_fehler']) ? trim((string) $zu['horcher_fehler']) : '';
        $th = (isset($zu['horcher']) && is_array($zu['horcher'])) ? $zu['horcher'] : array();
        $f['broker_fehler'] = $fe !== '' || ($th && empty($zu['horcher_verbunden']));
    }
    $f['v2'] = by_v2_haken_lage($cfg);
    $f['bruecke_soll'] = !empty($cfg['befehle_mqtt_ein']);
    $f['bruecke_laeuft'] = null;
    if ($f['bruecke_soll'] && by_bruecke_laden()) {
        $l = by_bruecke_lage();
        $f['bruecke_laeuft'] = $l[0] === true;
    }
    return $f;
}
