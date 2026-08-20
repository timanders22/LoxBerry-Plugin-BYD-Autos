#!/bin/bash
# BYD Autos - postupgrade
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
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
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
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

echo "<OK> postupgrade abgeschlossen."
exit 0
