#!/bin/bash
# BYD Autos - preinstall
# Aufrufform des Installers:
#   $1 KENNUNG (zehnstellig, KEIN Pfad)   $2 NAME   $3 FOLDER
#   $4 VERSION                            $5 BASEFOLDER (LoxBerry-Wurzel)
#
# Neu in 0.9.19 (I1, Entscheidung 1 vom 29.09.2026), Bauform AudiConnect
# 0.9.22. Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem
# Aufraeumen der alten Fassung und VOR dem Kopieren von Konfiguration,
# Cron-Datei und Oberflaeche (sbin/plugininstall.pl: preupgrade :846,
# purge :874, preinstall :877, Cron :990 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschriften, die
# gesicherte Ladehistorie und der Startmerker gehoeren postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften
# einer frueheren Installation - config/plugins/<ordner>.backup.byd.json,
# .backup.zugang.json und .backup.verlauf.tar - gehen nach <name>.alt (0600),
# der Startmerker .lief_vorher wird entfernt, gemeldet mit genau einer
# <WARNING>. Bis 0.9.19 spielte postinstall.sh sie ungefragt zurueck: Token,
# BYD-Zugang samt Steuer-PIN und Ladehistorie des frueheren Kontos, und der
# Dienst startete und meldete sich damit an (in WSL gemessen,
# Installer-Pruefer Fall F1). Die Bibliothek liest .alt nie (by_paths() kennt
# nur .backup.*); die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-bydautos}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzel wie in den uebrigen Hakenskripten: ohne config/plugins, data/plugins
# UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.byd.json" \
            "$BASE/config/plugins/$PFOLDER.backup.zugang.json" \
            "$BASE/config/plugins/$PFOLDER.backup.verlauf.tar"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -f "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
MERKER="$BASE/config/plugins/$PFOLDER.lief_vorher"
if [ -e "$MERKER" ]; then
    rm -f "$MERKER" && BEISEITE="$BEISEITE (Startmerker $MERKER entfernt)"
fi
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen, Zugangsdaten und Ladehistorie einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
