#!/bin/bash
# BYD Autos - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
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
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Ohne diesen Rueckfall arbeitete das Skript gegen /config/plugins/... und
    # die Sicherung entfiele STILLSCHWEIGEND.
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<FAIL> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen -"
    echo "<FAIL> es wurde nichts gesichert und nichts angehalten."
    exit 1
fi

PDATA="$BASE/data/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"
CFGDIR="$BASE/config/plugins/$PFOLDER"
MERKER="$BASE/config/plugins/$PFOLDER.lief_vorher"

# ---------- 1. Lief der Dienst? ----------
# Der Merker liegt NEBEN dem Konfigordner.
#
# BERICHTIGT am 20.08.2026. Hier stand, der Installer raeume
# data/plugins/<ordner>/ beim Upgrade vollstaendig ab. Das ist FALSCH und
# war nie gemessen. Nachgelesen in sbin/plugininstall.pl: beim Upgrade wird
# der Ordner angelegt, falls er fehlt (make_path, :996), und der
# Archivinhalt darueber kopiert (cp -r, :1000). Geloescht wird er nur in
# purge_installation (:1606), und die laeuft ausschliesslich im
# Deinstallations-Zweig (:233).
#
# Ein Merker im Datenordner taete es also auch - so steht es in REGELN_2 -
# und er verschwaende beim Deinstallieren von selbst. Die Stelle hier ist
# trotzdem geblieben: sie ist erprobt, und ein Steuermerker gehoert nicht in
# die Daten des Anwenders. Der Preis dafuer steht im uninstall, das ihn
# ausdruecklich abraeumt.
#
# Was hier wirklich zaehlt, ist etwas anderes und IST gemessen: dieses
# preupgrade haelt den Dienst ueber 'dienst.sh stop' an, und anhalten()
# entfernt dabei absichtlich den Sollmerker. Ohne ihn startet der
# Cron-Waechter den Dienst NICHT wieder - der Merker hier ist also das
# einzige, was ihn zurueckbringt.
# Alte Begruendung, die sich als falsch erwiesen hat: sie sah gruen aus, weil der
# Pruefstand das Abraeumen nicht nachbildete.
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

# Alte Python-Zwischendateien wegraeumen. Eine .pyc, die aelter ist als der
# Quelltext daneben, kann im unglueckichen Fall statt des neuen Codes geladen
# werden.
rm -rf "$PBIN/__pycache__" 2>/dev/null

echo "<OK> preupgrade abgeschlossen."
exit 0
