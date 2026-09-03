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
ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ]
}
wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if ist_wurzel "$v"; then echo "$v"; return 0; fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
BASE="${ARGV5:-$LBHOMEDIR}"
ist_wurzel "$BASE" || BASE=$(wurzel_suchen)
if ! ist_wurzel "$BASE"; then
    echo "<FAIL> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen"
    echo "<FAIL> (gesucht wurde ein Verzeichnis mit config/plugins und data/plugins)."
    echo "<FAIL> Es wurde nichts gesichert und nichts angehalten."
    exit 1
fi

PDATA="$BASE/data/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"
CFGDIR="$BASE/config/plugins/$PFOLDER"
MERKER="$BASE/config/plugins/$PFOLDER.lief_vorher"

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
rm -f "$MERKER"
if [ -x "$PBIN/dienst.sh" ] && "$PBIN/dienst.sh" status >/dev/null 2>&1; then
    touch "$MERKER"
    echo "<INFO> Der Dienst laeuft - er wird nach dem Update wieder gestartet."
fi

# ---------- 2. Dienst anhalten ----------
# Ueber dienst.sh, nicht mit einem eigenen kill: dort steht die argumentweise
# Pruefung, dass die Prozessnummer wirklich zu unserem Skript gehoert. Ein
# blankes "kill $(cat pid)" traefe bei wiederverwendeter Nummer einen fremden
# Prozess.
if [ -x "$PBIN/dienst.sh" ]; then
    "$PBIN/dienst.sh" stop >/dev/null 2>&1
    echo "<INFO> Laufender Dienst angehalten."
fi

# ---------- 3. Konfiguration sichern ----------
for f in byd.json zugang.json; do
    if [ -f "$CFGDIR/$f" ] && [ -s "$CFGDIR/$f" ]; then
        cp -p "$CFGDIR/$f" "$BASE/config/plugins/$PFOLDER.backup.$f" || \
            echo "<INFO> $f liess sich nicht sichern."
    fi
done
# Die Zweitschrift bekommt DIESELBEN Rechte wie das Original - sie enthaelt
# dasselbe Passwort.
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
VERLAUF="$PDATA/verlauf"
SICHERUNG="$BASE/config/plugins/$PFOLDER.backup.verlauf.tar"
rm -f "$SICHERUNG"
if [ -d "$VERLAUF" ] && [ -n "$(ls -A "$VERLAUF" 2>/dev/null)" ]; then
    if ! command -v tar >/dev/null 2>&1; then
        # Abgewiesen statt geraten: ohne tar wird nichts gesichert, und der
        # Anwender erfaehrt es, statt die Liste stillschweigend zu verlieren.
        echo "<INFO> tar ist nicht vorhanden - die Ladehistorie konnte NICHT"
        echo "<INFO> gesichert werden und geht bei diesem Update verloren."
    else
        # Groesse zuerst: eine Historie ist wenige Kilobyte gross. Alles
        # darueber ist nicht die Historie, und ein Archiv neben dem
        # Konfigordner soll nicht unbemerkt wachsen.
        KB=$(du -sk "$VERLAUF" 2>/dev/null | cut -f1)
        case "$KB" in ''|*[!0-9]*) KB=0 ;; esac
        if [ "$KB" -gt 20480 ]; then
            echo "<INFO> Der Ordner verlauf/ ist ${KB} kB gross - das ist mehr als"
            echo "<INFO> erwartet. Er wird NICHT gesichert; bitte von Hand kopieren."
        elif tar -cf "$SICHERUNG" -C "$PDATA" verlauf 2>/dev/null; then
            chmod 600 "$SICHERUNG" 2>/dev/null
            echo "<OK> Ladehistorie gesichert (${KB} kB) - sie wird nach dem Update"
            echo "<OK> zurueckgespielt."
        else
            rm -f "$SICHERUNG"
            echo "<INFO> Die Ladehistorie liess sich nicht sichern; sie geht bei"
            echo "<INFO> diesem Update verloren."
        fi
    fi
fi

# Alte Python-Zwischendateien wegraeumen. Eine .pyc, die aelter ist als der
# Quelltext daneben, kann im ungluecklichen Fall statt des neuen Codes geladen
# werden.
rm -rf "$PBIN/__pycache__" 2>/dev/null

echo "<OK> preupgrade abgeschlossen."
exit 0
