#!/bin/bash
# BYD Autos - preupgrade
# Aufrufform des Installers:
#   $1 KENNUNG (zehnstellig, KEIN Pfad)   $2 NAME   $3 FOLDER
#   $4 VERSION                            $5 BASEFOLDER (LoxBerry-Wurzel)
#   $6 WORKDIR (der Arbeitsordner des Installers)
#
# Vor dem Upgrade: merken, ob der Dienst lief, ihn anhalten und die
# Konfiguration ausserhalb des Plugin-Ordners sichern.
#
# Die Reihenfolge des Installers ist:
#   preupgrade
#   -> Removing old installation: rm -rf config/plugins/<ordner>/
#                                 rm -rf data/plugins/<ordner>/  ...
#   -> config/* aus dem Archiv kopieren
#   -> postinstall
#   -> postupgrade
# Alles, was das Upgrade ueberleben soll, muss also HIER geschrieben werden -
# und NEBEN die Ordner, nicht darin.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-bydautos}"
# 05.11.2026: ein Ordnername mit "/" oder "." waere ein Pfad hinaus (dieselbe Formel wie MGiSmart und Govee).
case "$PFOLDER" in
    ''|.|..|*/*) echo "<FAIL> Unbrauchbarer Ordnername '$PFOLDER' - nichts angefasst."; exit 2 ;;
esac

# Die Wurzel wird GEPRUEFT, nicht angenommen.
#
# Ein Verzeichnis, das existiert, ist noch keine LoxBerry-Wurzel. Eine
# vorhandene Wurzel traegt config/plugins UND data/plugins - das ist dasselbe
# Merkmal, das bin/byd.py und by_lib.php benutzen, und es ist billiger als
# jede Wette auf eine feste Zahl von "..".
#
# Ohne diese Pruefung sicherte das Skript im Fehlerfall nach
# <irgendwo>/config/plugins/... , meldete <OK> und der Anwender verlor beim
# naechsten Update Zugangsdaten und Ladehistorie, ohne dass irgendwo etwas
# stand.
#
# Die Suche AUFWAERTS verlangt zusaetzlich config/system/general.json: ein
# Verzeichnis mit config/plugins und data/plugins ist auf einem Pruefrechner
# schnell gefunden (Reste frueherer Pruefstaende, Regeln/06), ein LoxBerry hat
# general.json immer. Gemessen am 18.09.2026 (Pruefung-BYD-Autos-0.9.16, Fall
# H2b): ohne $5 und ohne LBHOMEDIR legte dieses Skript seine Marke in einem
# fremden Baum an, der nur config/plugins und data/plugins trug.
ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ]
}
wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if ist_wurzel "$v" && [ -f "$v/config/system/general.json" ]; then
            echo "$v"; return 0
        fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
BASE="${ARGV5:-$LBHOMEDIR}"
ist_wurzel "$BASE" || BASE=$(wurzel_suchen)
if ! ist_wurzel "$BASE"; then
    echo "<FAIL> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen"
    echo "<FAIL> (gesucht wurde ein Verzeichnis mit config/plugins, data/plugins"
    echo "<FAIL> und config/system/general.json)."
    echo "<FAIL> Es wurde nichts gesichert und nichts angehalten."
    exit 2
fi

PDATA="$BASE/data/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"
CFGDIR="$BASE/config/plugins/$PFOLDER"
MERKER="$BASE/config/plugins/$PFOLDER.lief_vorher"
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"

# ---------- INHALT statt GROESSE ----------
# Eine ABGESCHNITTENE Datei ist nicht leer: sie besteht "[ -s ]" und den
# Vergleich mit "{}". Bis 0.9.15 entschied genau das, ob die Zweitschrift
# ueberschrieben wird. Gemessen am 18.09.2026 (Pruefung-BYD-Autos-0.9.16,
# Faelle C1-C3): eine abgeschnittene zugang.json, eine abgeschnittene
# byd.json und eine zugang.json mit leerem Passwort verdraengten je die heile
# Zweitschrift - Passwort, Steuer-PIN bzw. Aktionstoken waren danach
# nirgends mehr.
#
# "Inhalt" heisst: ein lesbares JSON-Objekt UND das Geheimnis darin -
#   zugang.json: Benutzername und Passwort (ohne Passwort kann der Dienst
#                sich nicht anmelden; genau diese Form entstand in 0.9.14 in
#                der Upgrade-Luecke, README "Neu in 0.9.15")
#   byd.json:    das Aktionstoken (by_config_speichern() zieht die
#                Zweitschrift nach derselben Regel nach, by_lib.php)
# Rueckgabe: 0 Inhalt, 1 kein Inhalt, 2 nicht pruefbar (kein php).
# Wortgleich in preupgrade.sh, postinstall.sh und postupgrade.sh - ein
# Hakenskript kann sich nichts aus dem Plugin-Ordner holen, den der Installer
# gerade erst auspackt. Vorbild: Raumklima 0.11.10 rk_inhalt().
by_inhalt() {   # $1 Datei, $2 Art: zugang | byd
    [ -f "$1" ] && [ -s "$1" ] || return 1
    [ "$(tr -d ' \t\r\n' < "$1" 2>/dev/null)" = "{}" ] && return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        $da = function ($k) use ($d) {
            return isset($d[$k]) && is_string($d[$k]) && trim($d[$k]) !== "";
        };
        if ($argv[2] === "zugang") { exit(($da("benutzer") && $da("passwort")) ? 0 : 1); }
        if ($argv[2] === "byd") { exit($da("aktionstoken") ? 0 : 1); }
        exit(1);
    ' -- "$1" "$2" 2>/dev/null
    by_rc=$?
    [ "$by_rc" = 0 ] || [ "$by_rc" = 1 ] || return 2
    return "$by_rc"
}
# Kopieren, ohne das Ziel vorher zu kappen: erst eine Nebendatei (Rechte 0600
# von Anfang an), byteweise gegen die Quelle pruefen, dann umbenennen. Ein
# "cp -p" direkt auf die Zweitschrift oeffnet sie mit O_TRUNC - scheitert das
# Schreiben danach (volle Karte), bleibt eine LEERE Zweitschrift. Gemessen am
# 18.09.2026 (Fall D2): beide Zweitschriften danach 0 Byte.
by_kopie() {   # $1 Quelle, $2 Ziel
    zs_hinlegen "$1" "$2"
}

# ---------- 0. Marke "Aktualisierung laeuft" ----------
# Als Erstes, vor dem Anhalten und vor jeder Sicherung.
#
# Zwischen der neuen Cron-Datei und postinstall.sh liegt fast eine Minute
# (am Geraet an der Einspeisebremse gemessen, 08.09.2026, Regeln/06). In
# dieser Zeit sind config/plugins/<ordner>/ und data/plugins/<ordner>/ weg.
#
# Gemessen fuer DIESE Linie (WSL, 18.09.2026, Pruefung-BYD-Autos-0.9.15,
# messe_luecke.sh): kein Startweg laeuft in der Luecke an - der Waechter aus
# dem Cron findet seinen Merker soll_laufen nicht (Fall A1, 0 Prozesse nach
# sechs Laeufen), und 'dienst.sh start' scheitert an der fehlenden
# zugang.json (Fall A2). Die OBERFLAECHE dagegen richtet Schaden an: ein
# Druck auf "Speichern" im Reiter Einstellungen schrieb in der Luecke
#   {"benutzer":"...","passwort":"","pin":"","land":"DE"}
# nach zugang.json UND zog das in die Zweitschrift nach (Fall A5). Danach
# holte postinstall.sh genau diesen leeren Stand zurueck - Passwort und
# Steuer-PIN des BYD-Kontos waren endgueltig fort (Fall A9).
#
# Solange die Marke gilt, zeigt die Oberflaeche nur einen Hinweis und
# speichert nichts, und kein Startweg startet den Dienst. Sie liegt NEBEN
# dem Datenordner, weil purge_installation den Ordner selbst loescht.
# Aelter als 3600 s oder unlesbar gilt sie nicht - eine abgebrochene
# Installation darf das Plugin nicht fuer immer stilllegen. Entfernt wird
# sie vom LETZTEN Hakenskript, postupgrade.sh; postinstall.sh nimmt sie bei
# einem Abbruch ueber seinen EXIT-Trap mit.
# I3 (Durchgang 29.09.2026): VOR dem Anlegen festhalten, ob die Marke schon
# lag. Nur dann ist dies der zweite Anlauf eines Updates, das nicht zu Ende
# kam, und eine Sicherung ohne Datenordner die einzige Abschrift. Bis 0.9.19
# legte die naechste Zeile die Marke an, bevor irgendetwas nach ihr fragen
# konnte, und eine Sicherung aus einem frueheren Vorgang wurde eingespielt
# (gemessen, Installer-Pruefer Fall S1).
VORHER_MARKE=0
[ -f "$MARKE" ] && VORHER_MARKE=1
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if grep -Eq '^[0-9]+$' "$MARKE" 2>/dev/null; then
    echo "<OK> Bis zum Ende der Installation speichert die Plugin-Seite nichts"
    echo "<OK> und der Dienst wird nicht gestartet."
else
    # 05.11.2026: ohne Marke haelt die Installation das Update fuer eine Neuinstallation und legt die Einstellungen beiseite; bis hierher nur eine Warnung.
    [ "$VORHER_MARKE" = 1 ] || rm -f "$MARKE" 2>/dev/null
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi
# ---------- Update-Schutz (05.11.2026, Bauart Govee 0.9.34) ----------
# plugininstall.pl bricht nur bei Rueckgabewert >1 vor purge_installation ab. Die Kopie entsteht als <Ziel>.neu.<pid>, traegt 0600,
# BEVOR Inhalt hineinkommt, wird mit cmp verglichen und erst dann per mv an ihren Platz gestellt: eine heile Sicherung bleibt heil.
ZS_FEHL=""
zs_hinlegen() {   # $1 Quelle, $2 Ziel
    [ -d "$2" ] && return 1
    zs_neu="$2.neu.$$"
    rm -f "$zs_neu" 2>/dev/null
    if { : > "$zs_neu"; } 2>/dev/null && chmod 600 "$zs_neu" 2>/dev/null \
       && cp "$1" "$zs_neu" 2>/dev/null && cmp -s "$1" "$zs_neu" \
       && mv -f "$zs_neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$zs_neu" 2>/dev/null
    return 1
}
zs_schluss() {   # Fehlschlag gesammelt: Marke weg, Abbruch vor purge_installation
    [ -n "$ZS_FEHL" ] || return 0
    echo "<FAIL> Nicht gesichert:$ZS_FEHL - die Aktualisierung wird abgebrochen, damit der Purge die Originale nicht loescht."
    echo "<FAIL> Die bisherige Fassung bleibt installiert. Bitte Platz und Rechte unter config/plugins und data/plugins pruefen."
    [ "$VORHER_MARKE" = 1 ] || rm -f "$MARKE" 2>/dev/null
    [ "$LIEF_JETZT" = "1" ] && [ -x "$PBIN/dienst.sh" ] && "$PBIN/dienst.sh" start >/dev/null 2>&1
    exit 2
}

# ---------- 1. Lief der Dienst? ----------
# Der Merker liegt NEBEN dem Konfigordner, und das ist zwingend.
#
# BERICHTIGT am 03.09.2026. Hier stand seit dem 20.08.2026 das Gegenteil:
# der Installer raeume data/plugins/<ordner>/ beim Upgrade NICHT ab, geloescht
# werde nur beim Deinstallieren. Dieser Satz trat als "Berichtigung" einer
# frueheren, richtigen Aussage auf - und war falsch.
#
# Wie er entstand: jemand hat purge_installation gesucht, EINE Aufrufstelle
# gefunden und daraus "es gibt nur eine" gemacht. Es sind ZWEI:
#
#   Aufruf im Deinstallations-Zweig   mit dem Argument "all"
#   Aufruf im Upgrade-Zweig           unmittelbar hinter den preupgrade-Skripten
#
# Und im Rumpf der Subroutine steht das rm -rf auf config/plugins/<ordner>/
# UND data/plugins/<ordner>/ OHNE Pruefung auf dieses Argument - das "all"
# schaltet nur zusaetzlich Crontab-Datei und uninstall-Skript frei.
#
# Beim Upgrade ist der Datenordner also zwischen diesem Skript und
# postinstall.sh vollstaendig weg. Wer eine Zahl aus dieser Datei zitiert,
# nennt den Stand dazu, gegen den sie gemessen wurde (Zweig master); die
# Zeilennummern wandern mit jeder fremden Fassung, die Aufrufstellen nicht.
#
# ZWEI FOLGEN, und beide sind hier umgesetzt:
#
#  1. Der Merker "lief_vorher" MUSS neben dem Konfigordner liegen. Ein Merker
#     im Datenordner - wie es hier bis 0.9.5 als gangbar empfohlen wurde -
#     ueberlebt das Upgrade nicht. Und ohne ihn startet niemand den Dienst
#     wieder: dieses preupgrade haelt ihn ueber 'dienst.sh stop' an, und
#     anhalten() entfernt dabei absichtlich den Sollmerker, an dem der
#     Cron-Waechter haengt. Das Plugin stuende nach jedem Update still, bis
#     jemand die Oberflaeche oeffnet - die unauffaelligste Art von Ausfall.
#  2. Die Ladehistorie unter data/plugins/<ordner>/verlauf/ geht denselben Weg
#     und wird deshalb in Schritt 4 mit herausgetragen.
#
# Der Merker wird hier nur GESETZT, nie geloescht. Eingeloest und entfernt
# wird er von postinstall.sh, weggeraeumt von uninstall. Bis 0.9.15 stand
# hier vorher ein "rm -f": brach ein Update hinter purge_installation ab und
# wurde erneut angestossen, gab es kein bin/plugins/<ordner>/dienst.sh mehr,
# der Dienst galt als "lief nicht" - und der Merker des ersten Laufs, die
# einzige Auskunft, dass er lief, war fort. Gemessen am 18.09.2026
# (Pruefung-BYD-Autos-0.9.16, Fall D3): nach dem zweiten Lauf kein Merker,
# nach postinstall.sh 0 statt 1 Dienst.
# Liegt ein Merker, lief der Dienst vor einem Update, das nicht zu Ende kam;
# er wird dann nach DIESEM Update gestartet, und das Protokoll sagt es.
# I4: ob der Dienst JETZT lief, haelt LIEF_JETZT fest - daran haengt in
# Schritt 2 die Meldung, nicht am Merker.
LIEF_JETZT=0
if [ -x "$PBIN/dienst.sh" ] && "$PBIN/dienst.sh" status >/dev/null 2>&1; then
    LIEF_JETZT=1
    touch "$MERKER"
    echo "<INFO> Der Dienst laeuft - er wird nach dem Update wieder gestartet."
elif [ -f "$MERKER" ]; then
    echo "<INFO> Der Dienst laeuft nicht, aber der Merker eines frueheren, nicht zu"
    echo "<INFO> Ende gebrachten Updates liegt noch: der Dienst lief davor und wird"
    echo "<INFO> nach diesem Update wieder gestartet."
fi

# ---------- 2. Dienst anhalten ----------
# Ueber dienst.sh, nicht mit einem eigenen kill: dort steht die argumentweise
# Pruefung, dass die Prozessnummer wirklich zu unserem Skript gehoert. Ein
# blankes "kill $(cat pid)" traefe bei wiederverwendeter Nummer einen fremden
# Prozess.
#
# Die Meldung haengt am Merker aus Schritt 1 und nicht am Aufruf. Bis 0.9.9
# stand hier ein bedingungsloses "Laufender Dienst angehalten." - auch dann,
# wenn gar kein Dienst lief; die Antwort von dienst.sh ("laeuft nicht") ging
# nach /dev/null. Aufgefallen am ersten echten Upgrade am Geraet
# (11.09.2026): der Dienst war gestoppt, das Protokoll meldete ihn angehalten.
# Angehalten wird trotzdem in jedem Fall - stop entfernt auch den Sollmerker.
#
# I4 (Durchgang 29.09.2026): die Meldung haengt am Ergebnis von "status" -
# vorher (LIEF_JETZT) und nachher. Bis 0.9.19 hing sie am Merker; lag der aus
# einem frueheren Lauf, stand "Laufender Dienst angehalten.", ohne dass einer
# lief (gemessen, Installer-Pruefer Fall P2).
if [ -x "$PBIN/dienst.sh" ]; then
    "$PBIN/dienst.sh" stop >/dev/null 2>&1
    if [ "$LIEF_JETZT" = "1" ]; then
        if "$PBIN/dienst.sh" status >/dev/null 2>&1; then
            echo "<WARNING> Der Dienst liess sich nicht anhalten - er laeuft noch."
        else
            echo "<INFO> Laufender Dienst angehalten."
        fi
    else
        echo "<INFO> Der Dienst lief nicht - es war nichts anzuhalten."
    fi
fi

# ---------- 3. Konfiguration sichern ----------
# Nach INHALT (by_inhalt oben), nicht nach Groesse, und ueber by_kopie, nicht
# mit "cp -p" direkt auf die Zweitschrift. Eine Zweitschrift mit Inhalt wird
# nie durch einen Stand ohne Inhalt ersetzt.
for f in byd.json zugang.json; do
    case "$f" in byd.json) ART=byd ;; *) ART=zugang ;; esac
    QU="$CFGDIR/$f"
    ZW="$BASE/config/plugins/$PFOLDER.backup.$f"
    by_inhalt "$QU" "$ART"
    RC=$?
    if [ "$RC" = 0 ] || { [ "$RC" = 2 ] && [ ! -e "$ZW" ]; }; then
        # Bei 2 (kein php) nur, wenn es noch keine Zweitschrift gibt: dann
        # wird nichts verdraengt.
        if by_kopie "$QU" "$ZW"; then
            echo "<INFO> Zweitschrift von $f angelegt."
        else
            echo "<WARNING> Die Zweitschrift von $f liess sich nicht anlegen; eine"
            echo "<WARNING> vorhandene bleibt unveraendert: $ZW"
            ZS_FEHL="$ZS_FEHL $f"
        fi
    elif [ -e "$ZW" ]; then
        if [ "$RC" = 2 ]; then
            echo "<WARNING> Der Inhalt von $f liess sich nicht pruefen (kein php) - die"
        else
            echo "<WARNING> $f fehlt, ist unvollstaendig oder traegt keine Zugangsdaten bzw."
            echo "<WARNING> kein Aktionstoken - die"
        fi
        echo "<WARNING> vorhandene Zweitschrift bleibt unveraendert: $ZW"
    fi
done
# Die Zweitschrift traegt DIESELBEN Rechte wie das Original - sie enthaelt
# dasselbe Passwort. by_kopie legt sie schon so an; das hier zieht eine
# Zweitschrift aus einer frueheren Fassung nach.
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.zugang.json" 2>/dev/null
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.byd.json" 2>/dev/null

# ---------- 4. Ladehistorie herausretten ----------
# data/plugins/<ordner>/verlauf/ enthaelt ladungen.csv und die Tagesdateien.
# Der Installer raeumt den ganzen Datenordner ab (siehe Schritt 1); was
# ueberleben soll, muss NEBEN den Ordner. postinstall.sh holt es zurueck,
# uninstall raeumt es ab.
#
# Bis 0.9.5 gab es diese Rettung nicht, und fuenf Textstellen versprachen dem
# Anwender trotzdem, die Liste ueberstehe eine Aktualisierung. Entweder die
# Aussage oder die Sicherung war falsch - hier wird die Sicherung gebaut und
# die Aussage damit wahr.
#
# Die vorhandene Sicherung faellt erst, wenn die neue VOLLSTAENDIG steht. Bis
# 0.9.15 stand hier vor allem anderen "rm -f $SICHERUNG", und "tar -cf"
# schrieb danach direkt auf denselben Namen. Brach ein Update hinter
# purge_installation ab und wurde erneut angestossen, gab es keinen
# Datenordner mehr - und die einzige Abschrift der Historie wurde geloescht
# (Bestand-2026-09-18/klasse-D; in WSL nachgemessen 18.09.2026,
# Pruefung-BYD-Autos-0.9.16, Faelle D1 und D2). Jetzt: in eine Nebendatei
# packen, die Zahl der Dateien darin gegen den Ordner halten, umbenennen.
VERLAUF="$PDATA/verlauf"
SICHERUNG="$BASE/config/plugins/$PFOLDER.backup.verlauf.tar"
NEU="$SICHERUNG.neu"
rm -f "$NEU" 2>/dev/null
by_bleibt() {
    if [ -f "$SICHERUNG" ]; then
        echo "<INFO> Die vorhandene Sicherung bleibt unveraendert liegen und wird nach"
        echo "<INFO> dem Update zurueckgespielt: $SICHERUNG"
    fi
}
if [ -d "$VERLAUF" ] && [ -n "$(ls -A "$VERLAUF" 2>/dev/null)" ]; then
    if ! command -v tar >/dev/null 2>&1; then
        # Abgewiesen statt geraten: ohne tar wird nichts gesichert, und der
        # Anwender erfaehrt es, statt die Liste stillschweigend zu verlieren.
        echo "<INFO> tar ist nicht vorhanden - die Ladehistorie konnte NICHT"
        echo "<INFO> gesichert werden und geht bei diesem Update verloren."
        ZS_FEHL="$ZS_FEHL Ladehistorie"
        by_bleibt
    else
        # Groesse zuerst: eine Historie ist wenige Kilobyte gross. Alles
        # darueber ist nicht die Historie, und ein Archiv neben dem
        # Konfigordner soll nicht unbemerkt wachsen.
        KB=$(du -sk "$VERLAUF" 2>/dev/null | cut -f1)
        case "$KB" in ''|*[!0-9]*) KB=0 ;; esac
        SOLL_N=$(find "$VERLAUF" -type f 2>/dev/null | wc -l | tr -d ' ')
        if [ "$KB" -gt 20480 ]; then
            echo "<INFO> Der Ordner verlauf/ ist ${KB} kB gross - das ist mehr als"
            echo "<INFO> erwartet. Er wird NICHT gesichert; bitte von Hand kopieren."
            by_bleibt
        elif ( umask 077; tar -cf "$NEU" -C "$PDATA" verlauf ) 2>/dev/null \
             && tar -tf "$NEU" >/dev/null 2>&1 \
             && IST_N=$(tar -tf "$NEU" 2>/dev/null | awk '!/\/$/ { n++ } END { print n + 0 }') \
             && [ "$IST_N" = "$SOLL_N" ] \
             && mv -f "$NEU" "$SICHERUNG" 2>/dev/null; then
            chmod 600 "$SICHERUNG" 2>/dev/null
            echo "<OK> Ladehistorie gesichert (${KB} kB, $SOLL_N Dateien) - sie wird nach"
            echo "<OK> dem Update zurueckgespielt."
        else
            rm -f "$NEU" 2>/dev/null
            ZS_FEHL="$ZS_FEHL Ladehistorie"
            if [ -f "$SICHERUNG" ]; then
                echo "<WARNING> Die Ladehistorie liess sich nicht neu sichern."
                by_bleibt
            else
                echo "<INFO> Die Ladehistorie liess sich nicht sichern; sie geht bei"
                echo "<INFO> diesem Update verloren."
            fi
        fi
    fi
elif [ -f "$SICHERUNG" ] && [ "$VORHER_MARKE" = "1" ]; then
    # Kein Datenordner, aber eine Sicherung, und die Marke lag schon vor
    # diesem Lauf: so sieht der zweite Anlauf nach einem abgebrochenen Update
    # aus. Sie ist jetzt die einzige Abschrift.
    echo "<INFO> Im Datenordner liegt keine Ladehistorie, wohl aber die Sicherung eines"
    echo "<INFO> frueheren, nicht zu Ende gebrachten Updates."
    by_bleibt
elif [ -f "$SICHERUNG" ]; then
    # I3 (Entscheidung 1): ohne Marke vor diesem Lauf stammt die Sicherung aus
    # einem FRUEHEREN Vorgang - sie wird nicht eingespielt, sondern nach .alt
    # gelegt (die Deinstallation raeumt es ab). Der Startmerker .lief_vorher
    # bleibt davon unberuehrt: nach einem Abbruch in postinstall.sh hat dessen
    # EXIT-Trap die Marke schon entfernt, und der Merker ist die einzige
    # Auskunft, dass der Dienst vorher lief (Faelle P1 -> P2).
    rm -f "${SICHERUNG:?}.alt" 2>/dev/null
    if mv -f "$SICHERUNG" "$SICHERUNG.alt" 2>/dev/null; then
        chmod 600 "$SICHERUNG.alt" 2>/dev/null
        echo "<WARNING> Eine gesicherte Ladehistorie aus einem frueheren Vorgang lag noch neben"
        echo "<WARNING> dem Konfigordner. Sie wird NICHT eingespielt und liegt beiseite:"
        echo "<WARNING> $SICHERUNG.alt (die Deinstallation raeumt sie ab)."
    else
        echo "<WARNING> Eine gesicherte Ladehistorie aus einem frueheren Vorgang liess sich nicht"
        echo "<WARNING> beiseitelegen und wird nach dem Update eingespielt: $SICHERUNG"
    fi
fi

# ---------- 4b. Laufzeitdaten herausretten (Nachtrag 29.10.2026) ----------
# Bis hier ging nur verlauf/ ueber ein Update. Bestandsaufnahme aller Dateien, die Dienst, Oberflaeche, Bruecke und
# Sprachmodul in data/plugins/<ordner>/ schreiben (Bericht Gruppe 42, Nachtrag). Gerettet wird, was nach dem Update
# gebraucht wird:
#   merker.json             Fahrzeugnummern (die Adressen in Loxone), Ladevorgang, Verbrauch, Vorklima-Merker
#   anmeldung.json          die Anmeldesperre (C3) - ohne sie meldete sich der Dienst nach dem Update mit denselben
#                           abgewiesenen Zugangsdaten wieder an, bis BYD das Konto sperrt
#   befehlsbremse.json      PIN-Fehlschlaege und Befehle je Stunde (C5) - sonst begaenne die Zaehlung neu
#   ladung_mitschrift.json  die offene Mitschrift einer laufenden Ladung (Ladekosten)
#   ladekosten.json         Kosten und Preis der letzten Ladung (retained Thema bis zur naechsten Ladung)
#   ansage_sperre.json      Wiederholsperre der Ansagen und Signal-Nachrichten (soll einen Neustart ueberstehen)
# Bewusst NICHT: loxone.json, cache.json, zustand.json, rohdaten.json, felder_zeit (entstehen beim naechsten Abruf),
# preisempfehlung.json (Anzeige, rechnet der Dienst neu), dienst.pid, soll_laufen (dafuer .lief_vorher),
# .waechter_* und .verlauf_ts_* (Laufzeit des alten Prozesses; ein frisch installierter Dienst bekommt frische
# Neustartversuche), befehle/ und antworten/ (ein Befehl von vor dem Update soll danach nicht mehr laufen),
# aussentemp.json (kommt mit dem naechsten Wert ueber die Bruecke), gleichwert.json (60 s), einmalmeldung.json,
# endpunkt_probe.json, *_letzte.json (Ergebnis der letzten Ansage fuer den Reiter).
# Bauart wie Schritt 4: unter .neu packen, Zahl der Eintraege gegen die Liste halten, dann umbenennen; die vorhandene
# Sicherung faellt erst, wenn die neue steht; ohne Datenordner (zweiter Anlauf) bleibt sie; ohne Marke vor diesem Lauf
# stammt eine liegende aus einem frueheren Vorgang und geht nach .alt. Der Name liegt NEBEN dem Konfigordner.
DSICHERUNG="$BASE/config/plugins/$PFOLDER.backup.daten.tar"
DNEU="$DSICHERUNG.neu"
rm -f "$DNEU" 2>/dev/null
BY_DLISTE=""
for f in merker.json anmeldung.json befehlsbremse.json ladung_mitschrift.json ladekosten.json ansage_sperre.json; do
    [ -f "$PDATA/$f" ] && [ ! -L "$PDATA/$f" ] && BY_DLISTE="$BY_DLISTE $f"
done
if [ -n "$BY_DLISTE" ]; then
    BY_DSOLL=$(echo $BY_DLISTE | wc -w | tr -d ' ')
    if command -v tar >/dev/null 2>&1 \
       && ( umask 077; tar -cf "$DNEU" -C "$PDATA" $BY_DLISTE ) 2>/dev/null \
       && [ "$(tar -tf "$DNEU" 2>/dev/null | grep -c .)" = "$BY_DSOLL" ] \
       && mv -f "$DNEU" "$DSICHERUNG" 2>/dev/null; then
        chmod 600 "$DSICHERUNG" 2>/dev/null
        echo "<OK> Laufzeitdaten gesichert:$BY_DLISTE - sie werden nach dem Update zurueckgespielt."
    else
        rm -f "$DNEU" 2>/dev/null
        ZS_FEHL="$ZS_FEHL Laufzeitdaten"
        if [ -f "$DSICHERUNG" ]; then
            echo "<WARNING> Die Laufzeitdaten liessen sich nicht neu sichern; die vorhandene Sicherung bleibt: $DSICHERUNG"
        else
            echo "<WARNING> Die Laufzeitdaten (Merker, Anmeldesperre, offene Ladung) liessen sich nicht sichern;"
            echo "<WARNING> sie gehen bei diesem Update verloren."
        fi
    fi
elif [ -f "$DSICHERUNG" ] && [ "$VORHER_MARKE" = "1" ]; then
    echo "<INFO> Im Datenordner liegen keine Laufzeitdaten, wohl aber die Sicherung eines frueheren, nicht zu"
    echo "<INFO> Ende gebrachten Updates - sie bleibt und wird nach dem Update zurueckgespielt: $DSICHERUNG"
elif [ -f "$DSICHERUNG" ]; then
    rm -f "${DSICHERUNG:?}.alt" 2>/dev/null
    if mv -f "$DSICHERUNG" "$DSICHERUNG.alt" 2>/dev/null; then
        chmod 600 "$DSICHERUNG.alt" 2>/dev/null
        echo "<WARNING> Gesicherte Laufzeitdaten aus einem frueheren Vorgang werden NICHT eingespielt und liegen"
        echo "<WARNING> beiseite: $DSICHERUNG.alt (die Deinstallation raeumt sie ab)."
    else
        echo "<WARNING> Gesicherte Laufzeitdaten aus einem frueheren Vorgang liessen sich nicht beiseitelegen: $DSICHERUNG"
    fi
fi

# Gruppe 42 (0.9.29): die Befehlsbruecke anhalten - der Waechter startet sie nach dem Update wieder,
# wenn der Haken gesetzt ist. Die Marke .upgrade_laeuft (oben) haelt ihn bis dahin an.
BBSH="$PBIN/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    if bash "$BBSH" stop >/dev/null 2>&1; then
        echo "<INFO> Befehlsbruecke angehalten (der Waechter startet sie nach dem Update, wenn der Haken gesetzt ist)."
    else
        echo "<INFO> Befehlsbruecke: stop meldete einen Fehler - sie lief vermutlich nicht."
    fi
else
    echo "<INFO> Keine Befehlsbruecke installiert (Fassung vor 0.9.29) - nichts anzuhalten."
fi

# Alte Python-Zwischendateien wegraeumen. Eine .pyc, die aelter ist als der
# Quelltext daneben, kann im ungluecklichen Fall statt des neuen Codes geladen
# werden.
rm -rf "$PBIN/__pycache__" 2>/dev/null

zs_schluss
echo "<OK> preupgrade abgeschlossen."
exit 0
