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
    exit 1
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
    rm -f "$2.neu" 2>/dev/null
    if ( umask 077; cp -p "$1" "$2.neu" ) 2>/dev/null \
       && chmod 600 "$2.neu" 2>/dev/null \
       && cmp -s "$1" "$2.neu" \
       && mv -f "$2.neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$2.neu" 2>/dev/null
    return 1
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
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if grep -Eq '^[0-9]+$' "$MARKE" 2>/dev/null; then
    echo "<OK> Bis zum Ende der Installation speichert die Plugin-Seite nichts"
    echo "<OK> und der Dienst wird nicht gestartet."
else
    echo "<WARNING> Die Marke fuer die laufende Aktualisierung liess sich nicht"
    echo "<WARNING> anlegen: $MARKE"
    echo "<WARNING> Bitte die Plugin-Seite erst nach dem Ende der Installation oeffnen."
fi

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
if [ -x "$PBIN/dienst.sh" ] && "$PBIN/dienst.sh" status >/dev/null 2>&1; then
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
if [ -x "$PBIN/dienst.sh" ]; then
    "$PBIN/dienst.sh" stop >/dev/null 2>&1
    if [ -f "$MERKER" ]; then
        echo "<INFO> Laufender Dienst angehalten."
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
            if [ -f "$SICHERUNG" ]; then
                echo "<WARNING> Die Ladehistorie liess sich nicht neu sichern."
                by_bleibt
            else
                echo "<INFO> Die Ladehistorie liess sich nicht sichern; sie geht bei"
                echo "<INFO> diesem Update verloren."
            fi
        fi
    fi
elif [ -f "$SICHERUNG" ]; then
    # Kein Datenordner, aber eine Sicherung: so sieht der zweite Anlauf nach
    # einem abgebrochenen Update aus. Sie ist jetzt die einzige Abschrift.
    echo "<INFO> Im Datenordner liegt keine Ladehistorie, wohl aber die Sicherung eines"
    echo "<INFO> frueheren, nicht zu Ende gebrachten Updates."
    by_bleibt
fi

# Alte Python-Zwischendateien wegraeumen. Eine .pyc, die aelter ist als der
# Quelltext daneben, kann im ungluecklichen Fall statt des neuen Codes geladen
# werden.
rm -rf "$PBIN/__pycache__" 2>/dev/null

echo "<OK> preupgrade abgeschlossen."
exit 0
