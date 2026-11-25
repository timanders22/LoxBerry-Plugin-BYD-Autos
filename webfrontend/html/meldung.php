<?php
/**
 * Gemeinsame Statusmeldungen an SignalBot fuer die LoxBerry-Plugins des Hauses
 *
 * Grundlage: Auftrag des Hausherrn 23.11.2026 ("Diese Funktion sollte in alle Plugins eingebaut werden (aktivierbar /
 * deaktivierbar)"), Plan signal_meldungen_plan_2026-11-23.md, Entscheidungen des Hausherrn 23.11.2026: Weg
 * haus/meldung/<ordner> ("haus/meldung (Empfehlung)"), Ereignisse und Drosselung wie empfohlen ("Wie empfohlen").
 *
 * Stammfassung: Werkzeuge/gemeinsam/meldung.php. In jeder Linie BYTE-GLEICH neben der Bibliothek der Linie (dort, wo
 * auch sprachausgabe.php liegt); Werkzeuge/gemeinsam_pruefen.py haelt die Abschriften gleich und den Abschnitt
 * [SIGNALMELDUNG] der Sprachdateien gegen meldung_texte.json. Wer hier etwas aendert, hebt MELD_FASSUNG an, laesst
 * meld_selbsttest() laufen und verteilt. Neues kommt als neue Funktion oder als wahlfreier Parameter mit dem bisherigen
 * Verhalten als Vorgabe; keine Funktion wird entfernt oder umbenannt (zwei Abschriften koennen in einem Prozess laufen).
 *
 * Was die Datei tut:
 *   - Der Block 'meldung' der Konfiguration einer Linie: {ein: 0|1, haken: {Anlass: 0|1}, sperre_min: 0..1440}.
 *     Ab Werk ist 'ein' 0; die Haken stehen ab Werk wie die Ansage-Haken der Linie (die Linie nennt sie).
 *   - meld_regel_senden(): nimmt dieselben Eintraege wie ansage_regel_sprechen() (schluessel, text, dringend,
 *     sperre_min, nur_nach; wahlfrei 'anlass') und schickt die zugelassenen als EINE Nachricht an
 *     haus/meldung/<ordner> - JSON {"text", "dringend", "anlass", "zeit"}, QoS 0, NIE retained (ein Ereignis, kein
 *     Zustand: retained kaeme jede Meldung bei jedem Neustart von SignalBot noch einmal). Danach wird bis
 *     MELD_QUITTUNG_FRIST Sekunden auf haus/meldung/<ordner>/quittung gewartet ("angenommen" oder
 *     "abgelehnt: <Grund>", vom Empfaenger nie retained).
 *   - Drosselung: je Schluessel (Anlass und Geraet) eine Sperre (Vorgabe 60 min, je Eintrag ueberschreibbar), "wieder
 *     da" nur nach einer gesendeten Stoerung (nur_nach), hoechstens MELD_JE_STUNDE Nachrichten je Stunde und Linie;
 *     was die Bremse zurueckhaelt, nennt die naechste Nachricht in einer Sammelzeile. Die Nachtruhe stellt SignalBot.
 *   - Formular (meld_formular_html/-lesen), Zeile im Reiter Test (meld_pruefzeile), Testmeldung (meld_testmeldung),
 *     Knoten fuer das Sicherungsmodul (meld_sicherung_knoten).
 *
 * Was NICHT hier: der Anlass selbst, der Text, das Protokoll der Linie, das Speichern der Konfiguration.
 *
 * Hausregeln, die hier eingebaut sind:
 *   - Kein Rueckgabewert und keine Datei traegt den Meldungstext (Ergebnisse nennen Anlass, Lage und Grund).
 *   - Ein Text, der wie ein Zugangsdatum aussieht (token=, passwort:, ein GitHub-Zugangsschluessel), wird nicht gesendet.
 *   - Die Broker-Anmeldung kommt aus config/system/general.json und erscheint nirgends.
 *   - Eine ungueltige gespeicherte Einstellung wird nicht still ersetzt: Sie gilt als "aus", der Reiter Test nennt
 *     sie (Regel "Lade-Beanstandungen", Regeln/05).
 *
 * Schreibt nur meldung_stand.json und meldung_letzte.json in einen VORHANDENEN Ordner, den die Linie nennt ($k['ordner']).
 * Laeuft auf: PHP 7.4 bis 8.5. Schuetzt sich gegen doppeltes Laden; ein direkter Aufruf ueber den Webserver antwortet 403.
 * Kommandozeile: php meldung.php --selbsttest
 */

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
        && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}
// Doppeltes Laden: Funktionen auf oberster Ebene legt PHP schon beim Uebersetzen an, ein 'return' davor schuetzt
// nicht (gemessen 23.11.2026 an haus_bereiche.php). Deshalb steht alles im Bedingungsblock.
if (!function_exists('meld_fassung')) {

if (!defined('MELD_FASSUNG')) { define('MELD_FASSUNG', '1.0.0'); }
if (!defined('MELD_WURZEL')) { define('MELD_WURZEL', 'haus/meldung'); }
if (!defined('MELD_QUITTUNG_FRIST')) { define('MELD_QUITTUNG_FRIST', 5); }
if (!defined('MELD_JE_STUNDE')) { define('MELD_JE_STUNDE', 10); }
if (!defined('MELD_SPERRE_VORGABE')) { define('MELD_SPERRE_VORGABE', 60); }
if (!defined('MELD_TEXT_MAX')) { define('MELD_TEXT_MAX', 1800); }

function meld_fassung()
{
    return MELD_FASSUNG;
}

/* ------------------------------------------------------------------
 * Texte
 * ------------------------------------------------------------------ */

/** Text zu einer Kennung des Abschnitts [SIGNALMELDUNG]; ohne Uebersetzer der Schluessel selbst. */
function meld_t($id, array $k)
{
    $s = 'SIGNALMELDUNG.' . $id;
    if (isset($k['t']) && is_callable($k['t'])) { return (string) call_user_func($k['t'], $s); }
    return $s;
}

function meld_e($s, array $k = array())
{
    if (isset($k['e']) && is_callable($k['e'])) { return (string) call_user_func($k['e'], $s); }
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------
 * Der Block
 * ------------------------------------------------------------------ */

/** Ein gueltiger Anlassname (wie die Linien ihn fuer die Ansage fuehren). */
function meld_anlass_ok($a)
{
    return is_string($a) && preg_match('/^[a-z0-9_]{1,40}\z/', $a) === 1;
}

/**
 * Die Vorgabe: aus, Haken wie $vorgabe_haken (Anlass => 0|1; fehlt einer, gilt 1 wie bei ansage_regeln_html()),
 * Sperre MELD_SPERRE_VORGABE. $anlaesse: Anlass => Beschriftung (nur die Namen zaehlen).
 */
function meld_vorgaben(array $anlaesse, array $vorgabe_haken = array())
{
    $h = array();
    foreach (array_keys($anlaesse) as $a) {
        $h[(string) $a] = (!array_key_exists($a, $vorgabe_haken) || !empty($vorgabe_haken[$a])) ? 1 : 0;
    }
    return array('ein' => 0, 'haken' => $h, 'sperre_min' => MELD_SPERRE_VORGABE);
}

/** Eine ganze Zahl 0..1440 aus int oder Ziffernfolge, sonst null. */
function meld_sperre_wert($w)
{
    if (is_int($w)) { return ($w >= 0 && $w <= 1440) ? $w : null; }
    if (is_string($w) && preg_match('/^\d{1,4}\z/', $w)) { $i = (int) $w; return $i <= 1440 ? $i : null; }
    return null;
}

/**
 * Den gespeicherten Block pruefen. Rueckgabe: Liste der Beanstandungen (Kennung|wert), leer = gueltig. Fehlende Haken
 * bekannter Anlaesse sind keine Beanstandung (ein neuer Anlass einer spaeteren Fassung, siehe meld_block()).
 */
function meld_block_pruefen($b, array $anlaesse)
{
    if (!is_array($b)) { return array('B_FORM'); }
    $f = array();
    if (!array_key_exists('ein', $b) || !in_array($b['ein'], array(0, 1, '0', '1', true, false), true)) { $f[] = 'B_EIN'; }
    if (!array_key_exists('sperre_min', $b) || meld_sperre_wert($b['sperre_min']) === null) { $f[] = 'B_SPERRE'; }
    if (!array_key_exists('haken', $b) || !is_array($b['haken'])) {
        $f[] = 'B_HAKEN';
    } else {
        foreach ($b['haken'] as $a => $v) {
            if (!in_array($v, array(0, 1, '0', '1', true, false), true)) { $f[] = 'B_HAKEN'; break; }
        }
        foreach (array_keys($b['haken']) as $a) {
            if (!array_key_exists($a, $anlaesse)) { $f[] = 'B_ANLASS|' . $a; }
        }
    }
    foreach (array_keys($b) as $s) {
        if (!in_array($s, array('ein', 'haken', 'sperre_min'), true)) { $f[] = 'B_FORM'; break; }
    }
    return $f;
}

/** Eine Beanstandung (Kennung|wert) als Satz. */
function meld_beanstandung_text($kennung, array $k)
{
    $t = explode('|', (string) $kennung, 2);
    $s = meld_t($t[0], $k);
    return isset($t[1]) ? sprintf($s, $t[1]) : $s;
}

/**
 * Der wirksame Block fuer den Betrieb: array(Block, Beanstandungen).
 *   - nie gespeichert (null): die Vorgabe (aus).
 *   - gueltig: der gespeicherte, fehlende Haken neuer Anlaesse aus der Vorgabe ergaenzt.
 *   - ungueltig: der gespeicherte bleibt in der Datei; fuer den Betrieb gilt er als AUS ('ein' 0), die Beanstandungen
 *     nennt der Reiter Test. Nichts wird still zurechtgebogen.
 */
function meld_block($gespeichert, array $anlaesse, array $vorgabe_haken = array())
{
    $v = meld_vorgaben($anlaesse, $vorgabe_haken);
    if ($gespeichert === null) { return array($v, array()); }
    $f = meld_block_pruefen($gespeichert, $anlaesse);
    if ($f) {
        $v['ein'] = 0;
        return array($v, $f);
    }
    $b = array('ein' => in_array($gespeichert['ein'], array(1, '1', true), true) ? 1 : 0,
               'haken' => array(), 'sperre_min' => meld_sperre_wert($gespeichert['sperre_min']));
    foreach ($v['haken'] as $a => $vor) {
        $b['haken'][$a] = array_key_exists($a, $gespeichert['haken'])
            ? (in_array($gespeichert['haken'][$a], array(1, '1', true), true) ? 1 : 0) : $vor;
    }
    return array($b, array());
}

/** Ist der Anlass in einem wirksamen Block eingeschaltet (Hauptschalter und Haken)? */
function meld_anlass_an(array $b, $anlass)
{
    return !empty($b['ein']) && isset($b['haken'][$anlass]) && !empty($b['haken'][$anlass]);
}

/** Irgendein Anlass an? (fuer eine Linie, die vor dem Legen fragt, ob ueberhaupt jemand zuhoert) */
function meld_irgendein_an(array $b)
{
    if (empty($b['ein']) || empty($b['haken']) || !is_array($b['haken'])) { return false; }
    foreach ($b['haken'] as $v) { if (!empty($v)) { return true; } }
    return false;
}

/**
 * Der Anlass eines Eintrags: 'anlass', sonst das erste Stueck des Schluessels vor '|' oder ':'; beim Schluessel
 * 'wieder|...' das erste Stueck von 'nur_nach' (die Rueckkehr gehoert zum Anlass der Stoerung).
 */
function meld_anlass_von(array $e)
{
    if (isset($e['anlass']) && is_string($e['anlass']) && $e['anlass'] !== '') { return $e['anlass']; }
    $s = isset($e['schluessel']) && is_string($e['schluessel']) ? $e['schluessel'] : '';
    $erst = (string) preg_replace('/[|:].*\z/s', '', $s);
    if ($erst === 'wieder' && isset($e['nur_nach']) && is_string($e['nur_nach'])) {
        $erst = (string) preg_replace('/[|:].*\z/s', '', $e['nur_nach']);
    }
    return $erst;
}

/* ------------------------------------------------------------------
 * Formular
 * ------------------------------------------------------------------ */

/** Die Feldnamen des Blocks (fuer Einmalmeldung und X-2-Listen der Linie). */
function meld_feldnamen(array $anlaesse)
{
    $f = array('meld_da', 'meld_ein', 'meld_sperre_min');
    foreach (array_keys($anlaesse) as $a) { $f[] = 'meld_anlass_' . $a; }
    return $f;
}

/**
 * Den Block aus dem Formular lesen. Fehlt 'meld_da', trug das Formular den Block nicht: der alte bleibt. Eine
 * ungueltige Sperre wird beanstandet ($mangel: array('kennung', 'text'); $bean: Feldnamen) und behaelt den alten Wert.
 * $alt: der gespeicherte Block (oder null). Rueckgabe: der neue Block (immer gueltig).
 */
function meld_formular_lesen(array $post, $alt, array $anlaesse, array &$mangel, array &$bean, array $k = array(), array $vorgabe_haken = array())
{
    list($b) = meld_block($alt, $anlaesse, $vorgabe_haken);
    if (empty($post['meld_da'])) {
        return is_array($alt) ? $alt : $b;      // Dateiwert behalten (Regel "Lade-Beanstandungen")
    }
    $b['ein'] = !empty($post['meld_ein']) ? 1 : 0;
    foreach (array_keys($anlaesse) as $a) { $b['haken'][$a] = !empty($post['meld_anlass_' . $a]) ? 1 : 0; }
    if (isset($post['meld_sperre_min'])) {
        $w = is_string($post['meld_sperre_min']) ? trim($post['meld_sperre_min']) : null;
        $s = meld_sperre_wert($w);
        if ($s === null) {
            $mangel[] = array('kennung' => 'M_SPERRE', 'text' => meld_t('M_SPERRE', $k));
            $bean[] = 'meld_sperre_min';
        } else {
            $b['sperre_min'] = $s;
        }
    }
    return $b;
}

/**
 * Den Block zeichnen. $b: der wirksame Block (meld_block()). $anlaesse: Anlass => Beschriftung.
 * $h: wie bei ansage_regeln_html(): 'w' (Wert nach Beanstandung), 'm' (Markierung), 'c' (Haken nach Beanstandung).
 */
function meld_formular_html(array $b, array $anlaesse, array $h = array(), array $k = array())
{
    $w = isset($h['w']) && is_callable($h['w']) ? $h['w'] : function ($name, $wert) { return $wert; };
    $m = isset($h['m']) && is_callable($h['m']) ? $h['m'] : function ($name) { return ''; };
    $c = isset($h['c']) && is_callable($h['c']) ? $h['c'] : function ($name, $wert) { return (bool) $wert; };
    $ordner = meld_ordner($k);
    $haken = function ($name, $an, $text) use ($c, $m, $k) {
        return '    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;"><input data-role="none" type="checkbox" name="'
             . meld_e($name, $k) . '" value="1"' . (call_user_func($c, $name, $an) ? ' checked' : '') . call_user_func($m, $name) . '> '
             . meld_e($text, $k) . '</label>';
    };
    $o = array();
    $o[] = '<div class="sm-feld" id="meldung_block">';
    $o[] = '    <input data-role="none" type="hidden" name="meld_da" value="1">';
    $o[] = $haken('meld_ein', !empty($b['ein']), meld_t('L_EIN', $k));
    $o[] = '    <div class="sm-hilfe">' . meld_e(sprintf(meld_t('HINWEIS', $k), MELD_WURZEL . '/' . ($ordner !== '' ? $ordner : '<ordner>'),
                                                    $ordner !== '' ? $ordner : '?'), $k) . '</div>';
    $o[] = '</div>';
    if ($anlaesse) {
        $o[] = '<div class="sm-feld" id="meldung_anlaesse">';
        $o[] = '    <label>' . meld_e(meld_t('L_ANLAESSE', $k), $k) . '</label>';
        foreach ($anlaesse as $a => $text) {
            $o[] = $haken('meld_anlass_' . $a, !empty($b['haken'][$a]), (string) $text) . '<br>';
        }
        $o[] = '    <div class="sm-hilfe">' . meld_e(meld_t('ANLAESSE_HINWEIS', $k), $k) . '</div>';
        $o[] = '</div>';
    }
    $o[] = '<div class="sm-feld">';
    $o[] = '    <label for="meld_sperre_min">' . meld_e(meld_t('L_SPERRE', $k), $k) . '</label>';
    $o[] = '    <input data-role="none" type="number" min="0" max="1440" id="meld_sperre_min" name="meld_sperre_min" value="'
         . meld_e((string) call_user_func($w, 'meld_sperre_min', $b['sperre_min']), $k) . '"' . call_user_func($m, 'meld_sperre_min') . '>';
    $o[] = '    <div class="sm-hilfe">' . meld_e(sprintf(meld_t('SPERRE_HINWEIS', $k), MELD_JE_STUNDE), $k) . '</div>';
    $o[] = '</div>';
    return implode("\n", $o) . "\n";
}

/* ------------------------------------------------------------------
 * Sicherung
 * ------------------------------------------------------------------ */

/**
 * Der Knoten fuer das Sicherungsmodul (sicherung.php): ein Block mit 'ein', 'sperre_min' und den Haken (offen, die
 * Pruefung entscheidet); fehlt der Block in einer aelteren Sicherung, bleibt der geltende. Ungueltig = abgewiesen.
 */
function meld_sicherung_knoten(array $anlaesse, array $k = array())
{
    return array(
        'art' => 'block',
        'schluessel' => array('ein' => array('art' => 'wert'), 'sperre_min' => array('art' => 'wert'), 'haken' => array('art' => 'offen')),
        'fehlt' => 'behalten',
        'pruefen' => function ($wert) use ($anlaesse, $k) {
            $f = meld_block_pruefen($wert, $anlaesse);
            $aus = array();
            foreach ($f as $x) { $aus[] = meld_beanstandung_text($x, $k); }
            return $aus;
        },
    );
}

/* ------------------------------------------------------------------
 * Ordner, Stand, Broker
 * ------------------------------------------------------------------ */

/** Ein gueltiger Plugin-Ordnername fuer das Thema. */
function meld_ordner_ok($o)
{
    return is_string($o) && preg_match('/^[a-z0-9][a-z0-9_-]{0,39}\z/', $o) === 1;
}

/**
 * Der Ordnername der Linie: $k['plugin'], sonst die SDK-Konstante LBPPLUGINDIR, sonst der letzte Teil des Datenordners ($k['ordner'] = data/plugins/<ordner>),
 * sonst der Ordner dieser Datei, wenn sie in einem Plugin-Baum liegt (.../plugins/<ordner>/meldung.php). '' = keiner.
 */
function meld_ordner(array $k)
{
    if (isset($k['plugin']) && meld_ordner_ok($k['plugin'])) { return $k['plugin']; }
    /* Die Konstante des LoxBerry-SDK (Ordnername), wenn die Linie es geladen hat. Gemessen 24.11.2026: MarstekVenus legt
     * ihren Datenordner als data/plugins/<ordner>.verlauf an - daraus allein ergibt sich kein Absender. */
    if (defined('LBPPLUGINDIR') && meld_ordner_ok((string) constant('LBPPLUGINDIR'))) { return (string) constant('LBPPLUGINDIR'); }
    if (isset($k['ordner']) && is_string($k['ordner']) && $k['ordner'] !== '') {
        $o = basename(rtrim(str_replace('\\', '/', $k['ordner']), '/'));
        /* Ein Ordnername traegt nie einen Punkt; MarstekVenus legt ihren Datenordner als <ordner>.verlauf an (gemessen
         * 24.11.2026) - dann gilt der Teil vor dem ersten Punkt. */
        if (strpos($o, '.') !== false) { $o = (string) substr($o, 0, (int) strpos($o, '.')); }
        if (meld_ordner_ok($o) && basename(dirname(rtrim(str_replace('\\', '/', $k['ordner']), '/'))) === 'plugins') { return $o; }
    }
    $d = str_replace('\\', '/', __DIR__);
    if (basename(dirname($d)) === 'plugins' && meld_ordner_ok(basename($d))) { return basename($d); }
    return '';
}

function meld_thema(array $k)
{
    $o = meld_ordner($k);
    return $o === '' ? '' : MELD_WURZEL . '/' . $o;
}

function meld_jetzt(array $k)
{
    return (isset($k['jetzt']) && is_callable($k['jetzt'])) ? (int) call_user_func($k['jetzt']) : time();
}

/** Den Stand unter einer Sperre aendern: $f($stand) -> array(neuer Stand|null, Ergebnis). null = nicht schreibbar. */
function meld_stand_aendern($f, array $k)
{
    $d = isset($k['ordner']) && is_string($k['ordner']) ? $k['ordner'] : '';
    if ($d === '' || !is_dir($d)) { return null; }
    $p = $d . '/meldung_stand.json';
    $h = @fopen($p, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $s = json_decode((string) stream_get_contents($h), true);
    $s = is_array($s) ? $s : array();
    list($neu, $erg) = call_user_func($f, $s);
    $ok = true;
    if ($neu !== null) {
        rewind($h);
        ftruncate($h, 0);
        $ok = fwrite($h, (string) json_encode($neu)) !== false;
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($p, 0600);
    return $ok ? array($erg) : null;
}

/** Das letzte Ergebnis merken (Zeit, Lage, Grund, Zahl der Anlaesse; nie der Text). */
function meld_letzte_merken(array $q, $anzahl, array $k, $test = false)
{
    $d = isset($k['ordner']) && is_string($k['ordner']) ? $k['ordner'] : '';
    if ($d === '' || !is_dir($d)) { return false; }
    $p = $d . '/meldung_letzte.json';
    $js = json_encode(array('zeit' => meld_jetzt($k), 'lage' => (string) $q['lage'], 'grund' => (string) $q['grund'],
                            'anzahl' => (int) $anzahl, 'test' => $test ? 1 : 0));
    $tmp = $p . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $js) === false) { return false; }
    @chmod($tmp, 0600);
    return @rename($tmp, $p);
}

function meld_letzte(array $k)
{
    $d = isset($k['ordner']) && is_string($k['ordner']) ? $k['ordner'] : '';
    if ($d === '' || !is_file($d . '/meldung_letzte.json')) { return null; }
    $j = json_decode((string) @file_get_contents($d . '/meldung_letzte.json'), true);
    return (is_array($j) && isset($j['zeit'], $j['lage'])) ? $j : null;
}

/** Sieht der Text wie ein Zugangsdatum aus? Dann wird er nicht gesendet. */
function meld_text_geheim($text)
{
    return preg_match('/(token|passwor[dt]|kennwort|passwd|secret|apikey|api_key)\s*[=:]\s*\S|gh[p]_[A-Za-z0-9]{8}|github_[p]at_|x-access-tok[e]n/i',
                      (string) $text) === 1;
}

/** Text auf hoechstens $n Zeichen kuerzen (UTF-8, ohne mbstring). */
function meld_kuerzen($s, $n)
{
    if (preg_match_all('/./us', (string) $s, $m) === false) { return ''; }
    if (count($m[0]) <= $n) { return (string) $s; }
    return implode('', array_slice($m[0], 0, max(0, $n - 1))) . '…';
}

/** Die Broker-Anmeldung aus general.json: array(host, port, user, pass) oder null. Nie ausgegeben. */
function meld_broker(array $k)
{
    if (isset($k['broker']) && is_array($k['broker'])) { return $k['broker']; }
    $home = (isset($k['home']) && is_string($k['home']) && $k['home'] !== '') ? $k['home']
          : (getenv('LBHOMEDIR') ? (string) getenv('LBHOMEDIR') : '/opt/loxberry');
    $f = rtrim($home, '/') . '/config/system/general.json';
    $g = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    $m = (is_array($g) && isset($g['Mqtt']) && is_array($g['Mqtt'])) ? $g['Mqtt'] : null;
    if ($m === null || !isset($m['Brokerhost']) || !is_string($m['Brokerhost']) || $m['Brokerhost'] === '') { return null; }
    return array($m['Brokerhost'], isset($m['Brokerport']) ? (int) $m['Brokerport'] : 1883,
                 isset($m['Brokeruser']) ? (string) $m['Brokeruser'] : '', isset($m['Brokerpass']) ? (string) $m['Brokerpass'] : '');
}

function meld_mqtt_bauen($kopf, $rumpf)
{
    $n = strlen($rumpf);
    $l = '';
    do {
        $b = $n % 128;
        $n = (int) ($n / 128);
        $l .= chr($n > 0 ? $b | 128 : $b);
    } while ($n > 0);
    return chr($kopf) . $l . $rumpf;
}

/** Ein Paket aus dem Puffer: array(Kopfbyte, Rumpf, Rest) oder null. */
function meld_mqtt_paket($p)
{
    $n = strlen($p);
    if ($n < 2) { return null; }
    $len = 0;
    $mal = 1;
    $i = 1;
    do {
        if ($i >= $n || $i > 4) { return null; }
        $b = ord($p[$i]);
        $len += ($b & 127) * $mal;
        $mal *= 128;
        $i++;
    } while ($b & 128);
    if ($n < $i + $len) { return null; }
    return array(ord($p[0]), (string) substr($p, $i, $len), (string) substr($p, $i + $len));
}

/**
 * Eine Nachricht senden und die Quittung lesen (MQTT 3.1.1, QoS 0, saubere Sitzung): anmelden, die Quittung abonnieren,
 * nicht retained veroeffentlichen, bis $frist Sekunden auf eine NICHT retained Quittung warten, abmelden. Kein
 * mosquitto_pub: das Kennwort stuende sonst in der Prozessliste. $k['senden'] ersetzt den Weg in den Proben
 * (function ($thema, $nutzlast) -> Quittung|null|false; false = kein Broker).
 * Rueckgabe array('lage' => angenommen|abgelehnt|keine|kein_broker, 'grund' => Text hinter "abgelehnt: ").
 */
function meld_mqtt_senden($thema, $nutzlast, array $k, $frist = MELD_QUITTUNG_FRIST)
{
    $aus = array('lage' => 'kein_broker', 'grund' => '');
    if (isset($k['senden']) && is_callable($k['senden'])) {
        $q = call_user_func($k['senden'], $thema, $nutzlast);
    } else {
        $q = false;
        $b = meld_broker($k);
        if ($b !== null) { $q = meld_mqtt_sitzung($b, $thema, $nutzlast, $frist); }
    }
    if ($q === false) { return $aus; }
    if ($q === null) { $aus['lage'] = 'keine'; return $aus; }
    $q = trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $q));
    if ($q === 'angenommen') { $aus['lage'] = 'angenommen'; return $aus; }
    $aus['lage'] = 'abgelehnt';
    $aus['grund'] = meld_kuerzen(trim((string) preg_replace('/^abgelehnt:\s*/', '', $q)), 120);
    return $aus;
}

/** Die Sitzung selbst: Quittung (string), null (keine), false (kein Broker / Anmeldung abgelehnt). */
function meld_mqtt_sitzung(array $b, $thema, $nutzlast, $frist)
{
    list($host, $port, $user, $pass) = $b;
    $s16 = function ($x) { return pack('n', strlen($x)) . $x; };
    $q_thema = $thema . '/quittung';
    $bis = microtime(true) + max(2, (int) $frist + 2);
    set_error_handler(function () { return true; });
    $h = stream_socket_client('tcp://' . (strpos($host, ':') !== false ? '[' . $host . ']' : $host) . ':' . (int) $port, $en, $es, 3,
                              STREAM_CLIENT_CONNECT);
    restore_error_handler();
    if ($h === false) { return false; }
    stream_set_blocking($h, false);
    $senden = function ($d) use ($h, $bis) {
        while ($d !== '') {
            set_error_handler(function () { return true; });
            $n = fwrite($h, $d);
            restore_error_handler();
            if ($n === false || microtime(true) > $bis) { return false; }
            $d = (string) substr($d, (int) $n);
        }
        return true;
    };
    $flaggen = 2 | ($user !== '' ? 128 : 0) | ($pass !== '' ? 64 : 0);
    $rumpf = $s16('MQTT') . chr(4) . chr($flaggen) . pack('n', 30) . $s16('meldung-' . getmypid() . '-' . mt_rand(1000, 9999))
           . ($user !== '' ? $s16($user) : '') . ($pass !== '' ? $s16($pass) : '');
    $erg = false;
    $puffer = '';
    $angemeldet = false;
    $gesendet = false;
    $warte_bis = 0.0;
    if ($senden(meld_mqtt_bauen(0x10, $rumpf))) {
        while (microtime(true) < ($warte_bis > 0 ? $warte_bis : $bis)) {
            $r = array($h);
            $w = null;
            $x = null;
            set_error_handler(function () { return true; });
            $c = stream_select($r, $w, $x, 0, 50000);
            $teil = ($c > 0) ? fread($h, 4096) : '';
            restore_error_handler();
            if ($c > 0 && ($teil === false || $teil === '') && feof($h)) { break; }
            $puffer .= (string) $teil;
            if (strlen($puffer) > 65536) { break; }
            while (($p = meld_mqtt_paket($puffer)) !== null) {
                $puffer = $p[2];
                $art = $p[0] >> 4;
                if ($art === 2 && !$angemeldet) {
                    if (strlen($p[1]) < 2 || ord($p[1][1]) !== 0) { $bis = 0.0; break; }      // Anmeldung abgelehnt
                    $angemeldet = true;
                    if (!$senden(meld_mqtt_bauen(0x82, pack('n', 1) . $s16($q_thema) . chr(0)))) { $bis = 0.0; break; }
                } elseif ($art === 9 && !$gesendet) {
                    if (!$senden(meld_mqtt_bauen(0x30, $s16($thema) . $nutzlast))) { $bis = 0.0; break; }
                    $gesendet = true;
                    $erg = null;
                    $warte_bis = microtime(true) + max(1, (int) $frist);
                } elseif ($art === 3 && $gesendet) {
                    $retain = ($p[0] & 1) === 1;
                    $qos = ($p[0] >> 1) & 3;
                    $r2 = $p[1];
                    if (strlen($r2) < 2) { continue; }
                    $l = (ord($r2[0]) << 8) | ord($r2[1]);
                    $t = (string) substr($r2, 2, $l);
                    $pl = (string) substr($r2, 2 + $l + ($qos > 0 ? 2 : 0));
                    if ($t === $q_thema && !$retain) { $erg = $pl; $warte_bis = microtime(true) - 1; break; }
                }
            }
            if ($bis === 0.0) { break; }
        }
        set_error_handler(function () { return true; });
        fwrite($h, meld_mqtt_bauen(0xE0, ''));
        restore_error_handler();
    }
    fclose($h);
    return $angemeldet ? $erg : false;
}

/* ------------------------------------------------------------------
 * Senden nach den Regeln
 * ------------------------------------------------------------------ */

/**
 * Die Eintraege einer Linie melden. $eintraege wie bei ansage_regel_sprechen(); ein Eintrag mit 'meldung' => false
 * wird uebergangen. $b: der wirksame Block (meld_block()). $k: 'ordner' (Datenordner), 't', wahlfrei 'plugin',
 * 'name' (Anzeigename der Linie fuer die Testmeldung), 'jetzt', 'senden', 'broker', 'home'.
 * Rueckgabe: 'regel' ('' = gesendet, AUS, ORDNER, KEIN_TEXT, ANLASS (alle durch Haken/Sperre zurueckgehalten),
 * BREMSE, STAND), 'einzeln' (Schluessel => '' oder Grund: HAKEN, SPERRE, NUR_NACH, GEHEIM, BREMSE), 'q' (Quittung oder
 * null), 'anlaesse' (gesendete Anlaesse). Nie der Text.
 */
function meld_regel_senden(array $eintraege, array $b, array $k)
{
    $aus = array('regel' => '', 'einzeln' => array(), 'q' => null, 'anlaesse' => array());
    if (empty($b['ein'])) { $aus['regel'] = 'AUS'; return $aus; }
    $thema = meld_thema($k);
    if ($thema === '') { $aus['regel'] = 'ORDNER'; return $aus; }
    $gueltig = array();
    foreach ($eintraege as $e) {
        if (!is_array($e) || !isset($e['schluessel'], $e['text']) || !is_string($e['schluessel']) || !is_string($e['text'])) { continue; }
        if (array_key_exists('meldung', $e) && $e['meldung'] === false) { continue; }
        if (trim($e['text']) === '') { continue; }
        $a = meld_anlass_von($e);
        if (!meld_anlass_an($b, $a)) { $aus['einzeln'][$e['schluessel']] = 'HAKEN'; continue; }
        if (meld_text_geheim($e['text'])) { $aus['einzeln'][$e['schluessel']] = 'GEHEIM'; continue; }
        $e['anlass'] = $a;
        $gueltig[] = $e;
    }
    if (!$gueltig) {
        $aus['regel'] = $aus['einzeln'] ? 'ANLASS' : 'KEIN_TEXT';
        return $aus;
    }
    $jetzt = meld_jetzt($k);
    $sperre = isset($b['sperre_min']) ? (int) $b['sperre_min'] : MELD_SPERRE_VORGABE;
    $erg = meld_stand_aendern(function ($st) use ($gueltig, $jetzt, $sperre) {
        foreach (array('z', 'g', 'b') as $f) { if (!isset($st[$f]) || !is_array($st[$f])) { $st[$f] = array(); } }
        $st['r'] = isset($st['r']) ? max(0, (int) $st['r']) : 0;
        $st['b'] = array_values(array_filter($st['b'], function ($t) use ($jetzt) { return is_int($t) && $jetzt - $t < 3600 && $t <= $jetzt; }));
        $einzeln = array();
        $wahl = array();
        foreach ($gueltig as $e) {
            $s = $e['schluessel'];
            $sp = (isset($e['sperre_min']) && is_int($e['sperre_min']) && $e['sperre_min'] >= 0) ? $e['sperre_min'] : $sperre;
            if (isset($e['nur_nach']) && is_string($e['nur_nach']) && empty($st['g'][$e['nur_nach']])) { $einzeln[$s] = 'NUR_NACH'; continue; }
            if ($sp > 0 && isset($st['z'][$s]) && is_int($st['z'][$s]) && $jetzt - $st['z'][$s] < $sp * 60) { $einzeln[$s] = 'SPERRE'; continue; }
            $einzeln[$s] = '';
            $wahl[] = $e;
        }
        if (!$wahl) { return array(null, array($einzeln, array(), false, 0, false)); }
        if (count($st['b']) >= MELD_JE_STUNDE) {
            foreach ($wahl as $e) { $einzeln[$e['schluessel']] = 'BREMSE'; }
            $st['r'] += count($wahl);
            return array($st, array($einzeln, array(), false, 0, true));
        }
        $dringend = false;
        $texte = array();
        foreach ($wahl as $e) {
            $st['z'][$e['schluessel']] = $jetzt;
            $st['g'][$e['schluessel']] = 1;
            if (isset($e['nur_nach']) && is_string($e['nur_nach'])) { unset($st['g'][$e['nur_nach']]); }
            if (!empty($e['dringend'])) { $dringend = true; }
            $texte[] = $e;
        }
        $zurueck = $st['r'];
        $st['r'] = 0;
        $st['b'][] = $jetzt;
        foreach ($st['z'] as $s => $t) { if (!is_int($t) || $jetzt - $t > 86400 * 2) { unset($st['z'][$s]); } }
        return array($st, array($einzeln, $texte, $dringend, $zurueck, false));
    }, $k);
    if ($erg === null) {
        foreach ($gueltig as $e) { $aus['einzeln'][$e['schluessel']] = 'STAND'; }
        $aus['regel'] = 'STAND';
        return $aus;
    }
    list($einzeln, $texte, $dringend, $zurueck, $gebremst) = $erg[0];
    $aus['einzeln'] = $einzeln + $aus['einzeln'];
    if ($gebremst) { $aus['regel'] = 'BREMSE'; return $aus; }
    if (!$texte) { $aus['regel'] = 'ANLASS'; return $aus; }
    $zeilen = array();
    $anl = array();
    foreach ($texte as $e) { $zeilen[] = trim($e['text']); $anl[$e['anlass']] = true; }
    if ($zurueck > 0) { $zeilen[] = sprintf(meld_t('SAMMEL', $k), $zurueck, MELD_JE_STUNDE); }
    $text = meld_kuerzen(implode("\n", $zeilen), MELD_TEXT_MAX);
    $aus['anlaesse'] = array_keys($anl);
    $nutzlast = json_encode(array('text' => $text, 'dringend' => $dringend ? 1 : 0, 'anlass' => implode(',', $aus['anlaesse']),
                                  'zeit' => $jetzt), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $aus['q'] = meld_mqtt_senden($thema, (string) $nutzlast, $k);
    meld_letzte_merken($aus['q'], count($aus['anlaesse']), $k);
    return $aus;
}

/** Fuers Protokoll der Linie: eine Zeile ohne Text. */
function meld_regel_kurz(array $a)
{
    if ($a['regel'] !== '') {
        $z = array();
        foreach ($a['einzeln'] as $s => $g) { if ($g !== '') { $z[] = $g; } }
        return 'SignalBot: ' . $a['regel'] . ($z ? ' (' . implode(', ', array_unique($z)) . ')' : '');
    }
    $q = is_array($a['q']) ? $a['q'] : array('lage' => '?', 'grund' => '');
    return 'SignalBot: ' . implode(',', $a['anlaesse']) . ' -> ' . $q['lage'] . ($q['grund'] !== '' ? ': ' . $q['grund'] : '');
}

/**
 * Die Testmeldung des Reiters Test: geht an Haken, Sperre und Bremse vorbei (auch bei ausgeschaltetem Hauptschalter),
 * sonst derselbe Weg. Rueckgabe array(ok, Satz).
 */
function meld_testmeldung(array $k)
{
    $thema = meld_thema($k);
    if ($thema === '') { return array(false, meld_t('A_ORDNER', $k)); }
    $name = (isset($k['name']) && is_string($k['name']) && $k['name'] !== '') ? $k['name'] : meld_ordner($k);
    $text = sprintf(meld_t('TESTTEXT', $k), $name);
    $nutzlast = json_encode(array('text' => $text, 'dringend' => 0, 'anlass' => 'test', 'zeit' => meld_jetzt($k)),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $q = meld_mqtt_senden($thema, (string) $nutzlast, $k);
    meld_letzte_merken($q, 0, $k, true);
    if ($q['lage'] === 'angenommen') { return array(true, meld_t('T_OK', $k)); }
    return array(false, sprintf(meld_t('T_NEIN', $k), meld_lage_text($q, meld_jetzt($k), $k)));
}

/** Eine Lage als Satz (fuer Test und Pruefzeile). */
function meld_lage_text(array $q, $zeit, array $k)
{
    $wann = date('d.m.Y H:i', (int) $zeit);
    switch ($q['lage']) {
        case 'angenommen': return sprintf(meld_t('A_ANGENOMMEN', $k), $wann);
        case 'abgelehnt':  return sprintf(meld_t('A_ABGELEHNT', $k), $wann, $q['grund']);
        case 'keine':      return sprintf(meld_t('A_KEINE', $k), $wann, MELD_QUITTUNG_FRIST, meld_ordner($k));
        default:           return sprintf(meld_t('A_BROKER', $k), $wann);
    }
}

/**
 * Die Zeile im Reiter Test: array(Stand, Satz) mit Stand 1 (in Ordnung), 0 (Fehler), -1 (Hinweis/grau).
 * $bean: die Beanstandungen aus meld_block(). Fragt nichts am Netz (der Knopf "Testmeldung senden" tut das).
 */
function meld_pruefzeile(array $b, array $bean, array $k)
{
    if ($bean) {
        $t = array();
        foreach ($bean as $x) { $t[] = meld_beanstandung_text($x, $k); }
        return array(0, sprintf(meld_t('A_BLOCK', $k), implode('; ', $t)));
    }
    if (empty($b['ein'])) { return array(-1, meld_t('A_AUS', $k)); }
    if (meld_ordner($k) === '') { return array(0, meld_t('A_ORDNER', $k)); }
    $l = meld_letzte($k);
    if ($l === null) { return array(-1, meld_t('A_NOCH_NICHT', $k)); }
    return array($l['lage'] === 'angenommen' ? 1 : 0,
                 meld_lage_text(array('lage' => (string) $l['lage'], 'grund' => isset($l['grund']) ? (string) $l['grund'] : ''), (int) $l['zeit'], $k));
}

/* ------------------------------------------------------------------
 * Selbsttest (php meldung.php --selbsttest)
 * ------------------------------------------------------------------ */

function meld_selbsttest()
{
    $n = 0;
    $fehl = array();
    $pruef = function ($name, $ist, $soll) use (&$n, &$fehl) {
        $n++;
        if ($ist !== $soll) { $fehl[] = $name . ': ist ' . var_export($ist, true) . ', soll ' . var_export($soll, true); }
    };
    $dir = sys_get_temp_dir() . '/meld_st_' . getmypid() . '_' . mt_rand(1000, 9999);
    $daten = $dir . '/data/plugins/probe_ordner';
    @mkdir($daten, 0700, true);
    $uhr = 1800000000;
    $gesendet = array();
    $antwort = 'angenommen';
    $k = array('ordner' => $daten, 'jetzt' => function () use (&$uhr) { return $uhr; },
               'senden' => function ($t, $p) use (&$gesendet, &$antwort) { $gesendet[] = array($t, $p); return $antwort; });
    $an = array('ausfall' => 'Ausfall', 'alarm' => 'Alarm');
    // Vorgaben und Block
    $v = meld_vorgaben($an, array('alarm' => 0));
    $pruef('vorgabe aus', $v['ein'], 0);
    $pruef('vorgabe haken wie ansage', $v['haken'], array('ausfall' => 1, 'alarm' => 0));
    $pruef('vorgabe sperre', $v['sperre_min'], 60);
    list($b, $f) = meld_block(null, $an);
    $pruef('nie gespeichert', array($b['ein'], $f), array(0, array()));
    list($b, $f) = meld_block(array('ein' => 1, 'haken' => array('ausfall' => 1), 'sperre_min' => 30), $an, array('alarm' => 0));
    $pruef('neuer anlass aus vorgabe', $b['haken'], array('ausfall' => 1, 'alarm' => 0));
    $pruef('gueltig ein', array($b['ein'], $b['sperre_min'], $f), array(1, 30, array()));
    list($b, $f) = meld_block(array('ein' => 1, 'haken' => array('fremd' => 1), 'sperre_min' => 30), $an);
    $pruef('fremder anlass beanstandet', array($b['ein'], $f), array(0, array('B_ANLASS|fremd')));
    list($b, $f) = meld_block(array('ein' => 2, 'haken' => array(), 'sperre_min' => 'x'), $an);
    $pruef('ungueltig gilt aus', array($b['ein'], $f), array(0, array('B_EIN', 'B_SPERRE')));
    list($b, $f) = meld_block('kaputt', $an);
    $pruef('kein block', $f, array('B_FORM'));
    list($b, $f) = meld_block(array('ein' => 1, 'haken' => array(), 'sperre_min' => 5, 'x' => 1), $an);
    $pruef('fremder schluessel', $f, array('B_FORM'));
    $pruef('sperre 1441', meld_sperre_wert(1441), null);
    $pruef('sperre "0"', meld_sperre_wert('0'), 0);
    $pruef('sperre -1', meld_sperre_wert(-1), null);
    // Anlass eines Eintrags
    $pruef('anlass erstes stueck', meld_anlass_von(array('schluessel' => 'alarm|3|trocken')), 'alarm');
    $pruef('anlass doppelpunkt', meld_anlass_von(array('schluessel' => 'ladeziel:1')), 'ladeziel');
    $pruef('anlass wieder', meld_anlass_von(array('schluessel' => 'wieder|ausfall|ab', 'nur_nach' => 'ausfall|ab')), 'ausfall');
    $pruef('anlass ausdruecklich', meld_anlass_von(array('schluessel' => 'x', 'anlass' => 'alarm')), 'alarm');
    // Ordner
    $pruef('ordner aus datenordner', meld_ordner(array('ordner' => '/opt/loxberry/data/plugins/chromecast-4lox-ng')), 'chromecast-4lox-ng');
    $pruef('ordner ohne plugins', meld_ordner(array('ordner' => '/tmp/abc')), '');
    $pruef('ordner mit zusatz', meld_ordner(array('ordner' => '/opt/loxberry/data/plugins/marstekvenus.verlauf')), 'marstekvenus');
    $pruef('ordner ausdruecklich', meld_ordner(array('plugin' => 'zigbee2mqttng')), 'zigbee2mqttng');
    $pruef('ordner ungueltig', meld_ordner(array('plugin' => 'A B')), '');
    // Geheimnisse
    $pruef('geheim token', meld_text_geheim('Adresse ?token=abc123'), true);
    $pruef('geheim ghp', meld_text_geheim('gh' . 'p_ABCDEFGH1234'), true);
    $pruef('geheim pat', meld_text_geheim('github' . '_pat_11ABC'), true);
    $pruef('geheim harmlos', meld_text_geheim('Die Pumpe Keller meldet Trockenlauf.'), false);
    // Formular
    $mangel = array(); $bean = array();
    $nb = meld_formular_lesen(array('meld_da' => '1', 'meld_ein' => '1', 'meld_anlass_alarm' => '1', 'meld_sperre_min' => '15'), null, $an, $mangel, $bean);
    $pruef('formular', array($nb, $mangel), array(array('ein' => 1, 'haken' => array('ausfall' => 0, 'alarm' => 1), 'sperre_min' => 15), array()));
    $mangel = array(); $bean = array();
    $nb = meld_formular_lesen(array('meld_da' => '1', 'meld_sperre_min' => '9999'), array('ein' => 1, 'haken' => array('ausfall' => 1, 'alarm' => 1), 'sperre_min' => 20), $an, $mangel, $bean);
    $pruef('formular sperre beanstandet', array($nb['sperre_min'], $bean, count($mangel)), array(20, array('meld_sperre_min'), 1));
    $mangel = array(); $bean = array();
    $alt = array('ein' => 1, 'haken' => array('ausfall' => 1, 'alarm' => 1), 'sperre_min' => 20);
    $pruef('formular ohne block', meld_formular_lesen(array('x' => 1), $alt, $an, $mangel, $bean), $alt);
    $kaputt = array('ein' => 1, 'haken' => array('fremd' => 1), 'sperre_min' => 20);
    $pruef('formular ohne block behaelt ungueltigen dateiwert', meld_formular_lesen(array('x' => 1), $kaputt, $an, $mangel, $bean), $kaputt);
    $h = meld_formular_html(meld_vorgaben($an), $an, array(), array('ordner' => $daten,
        't' => function ($s) { return $s === 'SIGNALMELDUNG.HINWEIS' ? 'Thema %s Ordner %s' : $s; }));
    $pruef('html felder', substr_count($h, 'name="meld_'), 5);
    $pruef('html thema', strpos($h, 'haus/meldung/probe_ordner') !== false, true);
    // Senden
    $bl = array('ein' => 1, 'haken' => array('ausfall' => 1, 'alarm' => 0), 'sperre_min' => 60);
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|a', 'text' => 'Gerät A ist ausgefallen.')), array('ein' => 0) + $bl, $k);
    $pruef('aus sendet nicht', array($r['regel'], count($gesendet)), array('AUS', 0));
    $r = meld_regel_senden(array(array('schluessel' => 'alarm|a', 'text' => 'Alarm.')), $bl, $k);
    $pruef('haken aus', array($r['regel'], $r['einzeln'], count($gesendet)), array('ANLASS', array('alarm|a' => 'HAKEN'), 0));
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|a', 'text' => 'Gerät A ist ausgefallen.', 'dringend' => true),
                                 array('schluessel' => 'ausfall|b', 'text' => 'Gerät B ist ausgefallen.')), $bl, $k);
    $pruef('gesendet', array($r['regel'], $r['q']['lage'], count($gesendet)), array('', 'angenommen', 1));
    $pruef('thema', $gesendet[0][0], 'haus/meldung/probe_ordner');
    $j = json_decode($gesendet[0][1], true);
    $pruef('eine nachricht zwei zeilen', array($j['text'], $j['dringend'], $j['anlass']), array("Gerät A ist ausgefallen.\nGerät B ist ausgefallen.", 1, 'ausfall'));
    $pruef('nie text im ergebnis', strpos(json_encode($r), 'ausgefallen'), false);
    $uhr += 600;
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|a', 'text' => 'Gerät A ist ausgefallen.')), $bl, $k);
    $pruef('sperre', array($r['regel'], $r['einzeln']['ausfall|a'], count($gesendet)), array('ANLASS', 'SPERRE', 1));
    $r = meld_regel_senden(array(array('schluessel' => 'wieder|c', 'text' => 'C wieder da.', 'nur_nach' => 'ausfall|c')), $bl, $k);
    $pruef('nur_nach ohne stoerung', array($r['regel'], $r['einzeln']['wieder|c']), array('ANLASS', 'NUR_NACH'));
    $r = meld_regel_senden(array(array('schluessel' => 'wieder|a', 'text' => 'A wieder da.', 'nur_nach' => 'ausfall|a')), $bl, $k);
    $pruef('wieder nach stoerung', array($r['regel'], count($gesendet)), array('', 2));
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|g', 'text' => 'Zugang token=geheim')), $bl, $k);
    $pruef('geheim verworfen', array($r['regel'], $r['einzeln']['ausfall|g'], count($gesendet)), array('ANLASS', 'GEHEIM', 2));
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|k', 'text' => 'Klingel', 'sperre_min' => 0)), $bl, $k);
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|k', 'text' => 'Klingel', 'sperre_min' => 0)), $bl, $k);
    $pruef('sperre 0 je eintrag', array($r['regel'], count($gesendet)), array('', 4));
    // Bremse: hoechstens MELD_JE_STUNDE je Stunde, dann Sammelzeile
    for ($i = 0; $i < 12; $i++) { meld_regel_senden(array(array('schluessel' => 'ausfall|n' . $i, 'text' => 'N' . $i)), $bl, $k); }
    $pruef('bremse', count($gesendet), MELD_JE_STUNDE);
    $uhr += 3601;
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|z', 'text' => 'Z')), $bl, $k);
    $j = json_decode($gesendet[count($gesendet) - 1][1], true);
    $pruef('sammelzeile', array($r['regel'], substr_count($j['text'], "\n"), strpos($j['text'], 'SIGNALMELDUNG.SAMMEL') !== false),
           array('', 1, true));
    // Quittungen
    $antwort = 'abgelehnt: ABSENDER_NICHT_FREIGEGEBEN';
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|q', 'text' => 'Q')), $bl, $k);
    $pruef('abgelehnt', array($r['q']['lage'], $r['q']['grund']), array('abgelehnt', 'ABSENDER_NICHT_FREIGEGEBEN'));
    $pz = meld_pruefzeile($bl, array(), $k);
    $pruef('pruefzeile abgelehnt rot', $pz[0], 0);
    $antwort = null;
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|r', 'text' => 'R')), $bl, $k);
    $pruef('keine quittung', $r['q']['lage'], 'keine');
    $antwort = false;
    $r = meld_regel_senden(array(array('schluessel' => 'ausfall|s', 'text' => 'S')), $bl, $k);
    $pruef('kein broker', $r['q']['lage'], 'kein_broker');
    $antwort = 'angenommen';
    list($ok) = meld_testmeldung($k);
    $pruef('testmeldung', $ok, true);
    $pz = meld_pruefzeile($bl, array(), $k);
    $pruef('pruefzeile angenommen', $pz[0], 1);
    $pruef('pruefzeile aus', meld_pruefzeile(array('ein' => 0) + $bl, array(), $k)[0], -1);
    $pruef('pruefzeile beanstandet', meld_pruefzeile($bl, array('B_EIN'), $k)[0], 0);
    $lj = json_decode((string) file_get_contents($daten . '/meldung_letzte.json'), true);
    $pruef('letzte ohne text', array_keys($lj), array('zeit', 'lage', 'grund', 'anzahl', 'test'));
    $pruef('ohne ordner', meld_regel_senden(array(array('schluessel' => 'ausfall|x', 'text' => 'X')), $bl, array('senden' => $k['senden']))['regel'], 'ORDNER');
    $k2 = $k;
    $k2['ordner'] = $dir . '/data/plugins/fehlt';
    $k2['plugin'] = 'fehlt';
    $pruef('stand nicht schreibbar', meld_regel_senden(array(array('schluessel' => 'ausfall|y', 'text' => 'Y')), $bl, $k2)['regel'], 'STAND');
    // Sicherung
    $kn = meld_sicherung_knoten($an);
    $pruef('sicherung gueltig', call_user_func($kn['pruefen'], $bl), array());
    $pruef('sicherung ungueltig', count(call_user_func($kn['pruefen'], array('ein' => 1))), 2);
    // MQTT-Paket
    $pk = meld_mqtt_bauen(0x30, str_repeat('a', 300));
    $p = meld_mqtt_paket($pk);
    $pruef('paket lang', array($p[0], strlen($p[1]), $p[2]), array(0x30, 300, ''));
    $pruef('paket halb', meld_mqtt_paket(substr($pk, 0, 10)), null);
    $pruef('kuerzen', meld_kuerzen('äöüß', 3), 'äö…');
    foreach (array('meldung_stand.json', 'meldung_letzte.json') as $x) { @unlink($daten . '/' . $x); }
    @rmdir($daten); @rmdir($dir . '/data/plugins'); @rmdir($dir . '/data'); @rmdir($dir);
    return array($n, $fehl);
}

}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--selbsttest'
        && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    list($n, $f) = meld_selbsttest();
    echo 'meldung.php ' . MELD_FASSUNG . ': ' . $n . ' Faelle geprueft, ' . count($f) . " Fehlschlaege\n";
    foreach ($f as $z) { echo '  FEHL ' . $z . "\n"; }
    exit($f ? 1 : 0);
}
