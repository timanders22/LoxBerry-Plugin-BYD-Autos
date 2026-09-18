#!/bin/bash
# BYD Autos - postupgrade
# Aufrufform des Installers:
#   $1 KENNUNG (zehnstellig, KEIN Pfad)   $2 NAME   $3 FOLDER
#   $4 VERSION                            $5 BASEFOLDER (LoxBerry-Wurzel)
#   $6 WORKDIR (der Arbeitsordner des Installers)
#
# ---------------------------------------------------------------------------
# WARUM HIER FAST NICHTS STEHT
#
# Der LoxBerry-Installer fuehrt postinstall OHNE Bedingung aus
# (sbin/plugininstall.pl, Abschnitt "Executing postinstall script" - kein
# "if ($isupgrade)" davor) und postupgrade danach ZUSAETZLICH beim Upgrade.
# Ein postupgrade, das postinstall aufruft, fuehrt es also ZWEIMAL aus - und
# postinstall legt hier die virtuelle Umgebung an und holt pybyd ueber pip aus
# dem Netz. Auf einem Raspberry Pi dauert das Minuten.
#
# Alles, was ein Upgrade braucht, hat postinstall zu diesem Zeitpunkt bereits
# erledigt: Ordner, Zurueckspielen der Sicherung, venv, pybyd, Rechte und der
# Wiederanlauf des Dienstes.
# ---------------------------------------------------------------------------

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-bydautos}"
# Die Wurzel wird GEPRUEFT, nicht angenommen (siehe preupgrade.sh). Ein
# Verzeichnis, das existiert, ist noch keine LoxBerry-Wurzel - und dieses
# Skript pruefte bis 0.9.5 gar nicht, sondern nahm den Rueckfall ungesehen.
ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ]
}
BASE="${ARGV5:-$LBHOMEDIR}"
if ! ist_wurzel "$BASE"; then
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    BASE=""
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if ist_wurzel "$v"; then BASE="$v"; break; fi
        v=$(dirname "$v"); i=$((i + 1))
    done
fi
if ! ist_wurzel "$BASE"; then
    # Kein Abbruch mit Rueckgabewert 1: dieses Skript meldet nur, es aendert
    # nichts. Ein Fehlschlag hier darf eine gelungene Installation nicht
    # nachtraeglich als gescheitert erscheinen lassen.
    echo "<INFO> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen -"
    echo "<INFO> die Schlussmeldungen entfallen. Die Installation ist davon"
    echo "<INFO> nicht betroffen."
    exit 0
fi

# Eine Warnung, die bei heiler Konfiguration erscheint, ist ein Fehler: ein
# blinder Alarm entwertet die echte Warnung beim naechsten Mal. Deshalb wird
# hier NACHGESEHEN, wie es steht, statt pauschal zu melden.
CF="$BASE/config/plugins/$PFOLDER/byd.json"
if [ -f "$CF" ] && [ -s "$CF" ] && [ "$(tr -d ' \t\r\n' < "$CF")" != "{}" ]; then
    echo "<OK> Die Konfiguration ist vorhanden."
else
    echo "<INFO> Die Konfiguration ist leer. Beim ersten Aufruf der Oberflaeche"
    echo "<INFO> entsteht sie neu; die Zugangsdaten sind dann erneut einzutragen."
fi

# Der Merker fuer den Wiederanlauf gehoert postinstall. Liegt er hier noch,
# ist postinstall nicht gelaufen - das ist eine Auskunft, kein Aufraeumfall.
if [ -f "$BASE/config/plugins/$PFOLDER.lief_vorher" ]; then
    echo "<INFO> Der Merker fuer den Wiederanlauf liegt noch. Der minuetliche"
    echo "<INFO> Waechter startet den Dienst nicht von sich aus, solange kein"
    echo "<INFO> Sollmerker gesetzt ist - bitte im Reiter Einstellungen starten."
fi

# ---------- Die Marke der laufenden Aktualisierung entfernen ----------
# Dieses Skript ist das LETZTE, das LoxBerry in dieser Linie ruft: die
# Reihenfolge ist preroot, preinstall, preupgrade, postinstall, postupgrade,
# postroot - und ein postroot.sh gibt es hier nicht (gezaehlt am Ordner,
# 18.09.2026).
#
# Entfernt wird NACH dem Wiederanlauf, den postinstall.sh erledigt, und nicht
# davor: zwischen dem Entfernen und dem Augenblick, in dem der neue Dienst
# dasteht, saehe ein Waechterlauf weder die Marke noch einen laufenden Dienst
# und startete einen eigenen (an Chromecast4lox 1.3.10 in WSL gemessen,
# 17.09.2026). Der Wiederanlauf selbst kommt mit BY_START_TROTZ_MARKE=1 an
# der Marke vorbei.
#
# Entfernt wird IMMER - auch wenn der Wiederanlauf unterblieb, weil der
# Dienst vorher schon aus war. Sonst bliebe die Plugin-Seite gesperrt, bis
# die Frist von 3600 s abgelaufen ist.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    if rm -f "$MARKE" 2>/dev/null && [ ! -f "$MARKE" ]; then
        echo "<OK> Die Sperre fuer Plugin-Seite und Dienststart ist aufgehoben."
    else
        echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen."
        echo "<WARNING> Plugin-Seite und Dienststart bleiben gesperrt, bis sie eine"
        echo "<WARNING> Stunde alt ist. Sie darf von Hand geloescht werden."
    fi
fi

echo "<OK> postupgrade abgeschlossen."
exit 0
