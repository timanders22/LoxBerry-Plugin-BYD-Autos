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
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function by_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) {
                $home = $k;
                break;
            }
        }
    }
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
    $dir = basename(dirname(__FILE__));
    $lbp = getenv('LBPPLUGINDIR');
    if ($lbp) {
        $dir = $lbp;
    } elseif ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html') {
        $dir = 'bydautos';
    }
    if ($home) {
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
        // Zutaten der gerechneten Groessen. Leer heisst: der Wert entsteht
        // nicht. Geraten wird nichts.
        'kapazitaet'   => 0,
        'heim_breite'  => '',
        'heim_laenge'  => '',
        'heim_radius'  => 150,
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
 */
function by_config($erzeugen = true)
{
    $p = by_paths();
    $roh = is_file($p['config']) ? (string) @file_get_contents($p['config']) : null;

    if ($roh !== null && trim($roh) !== '' && trim($roh) !== '{}') {
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
                    @copy($p['config'], $p['config'] . '.kaputt');
                    by_log('Die Konfiguration war unlesbar (kein gueltiges JSON). Sie liegt '
                         . 'als byd.json.kaputt daneben; weitergearbeitet wird mit der '
                         . 'Zweitschrift.');
                }
                $sich = by_json_lesen($p['sicherung']);
                if ($sich) {
                    // Lesen UND zurueckschreiben - ein reines Lesen liesse die
                    // Datei dauerhaft fehlen, und jeder Aufruf zoege die
                    // Sicherung erneut und schriebe eine Protokollzeile.
                    by_config_speichern(array_merge(by_vorgaben(), $sich), false);
                    return array_merge(by_vorgaben(), $sich);
                }
            }
            return array_merge(by_vorgaben(), array());
        }
        return array_merge(by_vorgaben(), $cfg);
    }

    // Datei fehlt oder ist leer beziehungsweise "{}": Neuinstallation oder
    // Aktualisierungsfall. Aus der Zweitschrift heilen, wenn es eine gibt.
    if ($erzeugen && is_file($p['sicherung'])) {
        $sich = by_json_lesen($p['sicherung']);
        if ($sich) {
            by_config_speichern(array_merge(by_vorgaben(), $sich), false);
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
function by_config_speichern($cfg, $sicherung = true)
{
    $p = by_paths();
    if (!is_dir($p['configdir'])) {
        @mkdir($p['configdir'], 0775, true);
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
            @copy($p['config'], $p['sicherung']);
            @chmod($p['sicherung'], 0600);
        }
    }
    return true;
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
    @copy($p['zugang'], $p['zugang_sicherung']);
    @chmod($p['zugang_sicherung'], 0600);
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
    $skript = by_paths()['bindir'] . '/byd.py';
    if (isset($argv[0], $argv[1])
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
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    if (!function_exists('exec')) {
        // Eine Absicherung, die genau dann zuschlaegt, wenn sie nicht messen
        // kann, ist keine - deshalb wird der Grund benannt.
        return array(0, 'exec() ist in dieser PHP-Einrichtung gesperrt. Der Dienst '
                      . 'laesst sich von hier aus nicht schalten.');
    }
    $skript = by_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
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
    @exec(escapeshellcmd($py) . ' -c ' . escapeshellarg(
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
    @exec(escapeshellcmd($py) . ' -c ' . escapeshellarg(
        'import sys; print("%d.%d.%d" % sys.version_info[:3])'
    ) . ' 2>/dev/null', $ausgabe);
    $v = trim(implode('', $ausgabe));
    return $v;
}

/** Ruft byd.py mit einem Schalter auf und gibt die Ausgabe zurueck. */
function by_python_ruf($schalter)
{
    $p = by_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/byd.py';
    if (!is_file($py) || !is_file($skript)) {
        return "[FEHL] Die virtuelle Python-Umgebung oder byd.py fehlt.\n"
             . "       Erwartet: " . $py . "\n"
             . "                 " . $skript . "\n"
             . "       Abhilfe: Plugin neu installieren; die Installation legt beides an.";
    }
    if (!function_exists('exec')) {
        return "[FEHL] exec() ist in dieser PHP-Einrichtung gesperrt - der Selbsttest "
             . "laesst sich von hier aus nicht aufrufen.";
    }
    $ausgabe = array();
    @exec(escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' '
        . escapeshellarg($schalter) . ' 2>&1', $ausgabe);
    return implode("\n", $ausgabe);
}

function by_selbsttest()
{
    return by_python_ruf('--selbsttest');
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
        return array(0, 'Der Abrufdienst laeuft nicht - es wurde nichts eingereiht. '
                      . 'Reiter Einstellungen, Knopf "Dienst starten".');
    }

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp.' . getmypid();
    $befehl['ts'] = time();
    $by_js = json_encode($befehl);
    if ($by_js === false) {
        return array(0, 'Der Befehl liess sich nicht als JSON darstellen (ungueltiges UTF-8).');
    }
    if (@file_put_contents($tmp, $by_js) !== strlen($by_js) || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
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
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit
                  . ' s nicht geantwortet. Das Ergebnis ist damit unbekannt - es wird '
                  . 'kein Erfolg gemeldet, den niemand geprueft hat.');
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
        );
    }
    // Neueste zuerst, und nur so viele, wie die Seite tragen kann.
    usort($aus, function ($a, $b) {
        return $b['start'] - $a['start'];
    });
    return array_slice($aus, 0, max(1, (int) $grenze));
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
        if ($th !== '') {
            $t[] = $th;
        }
    }
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
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0,
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
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'websocket'  => (string) $hol('Websocketport', 'websocketport'),
    );
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
 *            'bestand' im Betrieb gegen eine echte Antwort geprueft
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
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 100),
        'KM'        => array('einheit' => 'km',   'bez' => 'BY_FELD.KM',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 2000000),
        'REICHW'    => array('einheit' => 'km',   'bez' => 'BY_FELD.REICHW',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 1500),
        'LAEDT'     => array('einheit' => '',     'bez' => 'BY_FELD.LAEDT',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 1),
        'KABEL'     => array('einheit' => '',     'bez' => 'BY_FELD.KABEL',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 1),
        'LADEZUST'  => array('einheit' => '',     'bez' => 'BY_FELD.LADEZUST',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 255),
        'RESTMIN'   => array('einheit' => 'min',  'bez' => 'BY_FELD.RESTMIN',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 6000),
        'TEMPO'     => array('einheit' => 'km/h', 'bez' => 'BY_FELD.TEMPO',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 300),
        'FAHRZUST'  => array('einheit' => '',     'bez' => 'BY_FELD.FAHRZUST',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 255),
        'ONLINE'    => array('einheit' => '',     'bez' => 'BY_FELD.ONLINE',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 255),
        'ZUENDUNG'  => array('einheit' => '',     'bez' => 'BY_FELD.ZUENDUNG',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 255),
        'SCHLOSSVL' => array('einheit' => '',     'bez' => 'BY_FELD.SCHLOSSVL',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 255),
        'BATTHEIZ'  => array('einheit' => '',     'bez' => 'BY_FELD.BATTHEIZ',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 255),
        'SITZHEIZ'  => array('einheit' => '',     'bez' => 'BY_FELD.SITZHEIZ',
                             'zeile' => 1, 'quelle' => 'doku',    'min' => 0,  'max' => 255),
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
                             'zeile' => 0, 'quelle' => 'doku',    'min' => -90,  'max' => 90),
        'LAENGE'    => array('einheit' => '',     'bez' => 'BY_FELD.LAENGE',
                             'zeile' => 0, 'quelle' => 'doku',    'min' => -180, 'max' => 180),
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
    );
    foreach (by_felder() as $name => $eig) {
        if ($name === 'OK' || $name === 'ALTER') {
            continue;   // stehen als eigene Themen oben
        }
        $aus['fahrzeugN/' . $name] = $eig['bez'];
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
    return array(
        'abruf'           => array('bez' => 'BY_BEF.ABRUF',      'schaltet' => 0, 'zusatz' => ''),
        'verriegeln'      => array('bez' => 'BY_BEF.VERRIEGELN', 'schaltet' => 1, 'zusatz' => ''),
        'entriegeln'      => array('bez' => 'BY_BEF.ENTRIEGELN', 'schaltet' => 1, 'zusatz' => ''),
        'klima_start'     => array('bez' => 'BY_BEF.KLIMA_START', 'schaltet' => 1, 'zusatz' => 'temp'),
        'klima_stop'      => array('bez' => 'BY_BEF.KLIMA_STOP', 'schaltet' => 1, 'zusatz' => ''),
        'klima_plan'      => array('bez' => 'BY_BEF.KLIMA_PLAN', 'schaltet' => 1, 'zusatz' => 'temp'),
        'sitzklima'       => array('bez' => 'BY_BEF.SITZKLIMA',  'schaltet' => 1, 'zusatz' => 'stufe'),
        'batterieheizung' => array('bez' => 'BY_BEF.BATTHEIZ',   'schaltet' => 1, 'zusatz' => 'stufe'),
        'suchen'          => array('bez' => 'BY_BEF.SUCHEN',     'schaltet' => 1, 'zusatz' => ''),
        'blinken'         => array('bez' => 'BY_BEF.BLINKEN',    'schaltet' => 1, 'zusatz' => ''),
        'fenster_zu'      => array('bez' => 'BY_BEF.FENSTER_ZU', 'schaltet' => 1, 'zusatz' => ''),
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
        $o .= 'CmdOnMethod="0" ';
        $o .= 'CmdOffMethod="0" ';
        $o .= 'CmdOn="' . by_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOff="' . by_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="false" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
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
        $cmds[] = array(
            'title'   => 'BYD ' . (int) $nummer . ' ' . by_klartext(by_t($eig['bez'])),
            'comment' => 'BYD_' . (int) $nummer . '_' . $feld,
            'check'   => by_check($feld),
            'min'     => $eig['min'],
            'max'     => $eig['max'],
            'unit'    => $einheit !== '' ? '<v.1> ' . $einheit : '<v.1>',
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
    $p = by_paths();
    $token = by_token();
    $basis = '/plugins/' . $p['plugin'] . '/index.php?token=' . $token;
    $cmds = array();
    foreach (by_befehle() as $aktion => $eig) {
        if ($aktion === 'abruf') {
            $cmds[] = array(
                'title'   => 'BYD ' . (int) $nummer . ' Abruf',
                'comment' => 'BYD_' . (int) $nummer . '_ABRUF',
                'on'      => $basis . '&aktion=abruf',
                'off'     => '',
            );
            continue;
        }
        if ($eig['zusatz'] === 'temp') {
            // Ein Zustand gehoert an EINEN Ausgang mit Ein- und Ausbefehl,
            // nicht an zwei Ausgaenge. Klima ein/aus ist genau so ein Fall.
            if ($aktion !== 'klima_start') {
                continue;
            }
            $cmds[] = array(
                'title'   => 'BYD ' . (int) $nummer . ' Klima',
                'comment' => 'BYD_' . (int) $nummer . '_KLIMA',
                'on'      => $basis . '&aktion=klima_start&fahrzeug=' . (int) $nummer
                           . '&temp=<v>',
                'off'     => $basis . '&aktion=klima_stop&fahrzeug=' . (int) $nummer,
            );
            continue;
        }
        if ($aktion === 'klima_stop') {
            continue;   // steht als Ausbefehl an "Klima"
        }
        if ($aktion === 'verriegeln') {
            $cmds[] = array(
                'title'   => 'BYD ' . (int) $nummer . ' Verriegelung',
                'comment' => 'BYD_' . (int) $nummer . '_VERRIEGELUNG',
                'on'      => $basis . '&aktion=verriegeln&fahrzeug=' . (int) $nummer,
                'off'     => $basis . '&aktion=entriegeln&fahrzeug=' . (int) $nummer,
            );
            continue;
        }
        if ($aktion === 'entriegeln') {
            continue;   // steht als Ausbefehl an "Verriegelung"
        }
        if ($eig['zusatz'] === 'stufe') {
            $cmds[] = array(
                'title'   => 'BYD ' . (int) $nummer . ' ' . by_klartext(by_t($eig['bez'])),
                'comment' => 'BYD_' . (int) $nummer . '_' . strtoupper($aktion),
                'on'      => $basis . '&aktion=' . $aktion . '&fahrzeug=' . (int) $nummer
                           . '&stufe=<v>',
                'off'     => '',
            );
            continue;
        }
        $cmds[] = array(
            'title'   => 'BYD ' . (int) $nummer . ' ' . by_klartext(by_t($eig['bez'])),
            'comment' => 'BYD_' . (int) $nummer . '_' . strtoupper($aktion),
            'on'      => $basis . '&aktion=' . $aktion . '&fahrzeug=' . (int) $nummer,
            'off'     => '',
        );
    }
    return array(
        'VQ_BYD_' . (int) $nummer . '_steuern.xml',
        by_xml_virtual_out(array(
            'title'   => 'BYD ' . (int) $nummer . ' steuern',
            'address' => 'http://' . by_host(),
            'comment' => 'BYD Autos ' . (int) $nummer . ' Steuerbefehle',
            'hint'    => by_klartext(by_t('LOX.VORLAGE_VO_HINWEIS')),
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
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
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
