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
# Die Suche AUFWAERTS verlangt zusaetzlich config/system/general.json - siehe
# preupgrade.sh (Fall H2b des Pruefstands Pruefung-BYD-Autos-0.9.16).
if ! ist_wurzel "$BASE"; then
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    BASE=""
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if ist_wurzel "$v" && [ -f "$v/config/system/general.json" ]; then
            BASE="$v"; break
        fi
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
#
# Nachgesehen wird der INHALT (by_inhalt, wortgleich in preupgrade.sh). Bis
# 0.9.15 genuegten "[ -s ]" und "nicht {}": eine abgeschnittene byd.json
# wurde mit "<OK> Die Konfiguration ist vorhanden." gemeldet (gemessen am
# 18.09.2026, Pruefung-BYD-Autos-0.9.16, Fall C9) - eine Erfolgsmeldung ohne
# Wirkung. Die Zugangsdaten werden seither ebenfalls genannt: sie sind es,
# die ein Update bis 0.9.14 verlieren konnte.
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
CF="$BASE/config/plugins/$PFOLDER/byd.json"
by_inhalt "$CF" byd
case "$?" in
    0)  echo "<OK> Die Konfiguration ist vorhanden." ;;
    2)  echo "<INFO> Ob die Konfiguration vollstaendig ist, liess sich nicht pruefen"
        echo "<INFO> (kein php)." ;;
    *)  echo "<INFO> Die Konfiguration ist leer oder unvollstaendig. Beim ersten Aufruf"
        echo "<INFO> der Oberflaeche wird sie aus der Zweitschrift geheilt; gibt es keine,"
        echo "<INFO> entsteht ein neues Aktionstoken, und die Adressen in Loxone sind"
        echo "<INFO> anzupassen." ;;
esac
by_inhalt "$BASE/config/plugins/$PFOLDER/zugang.json" zugang
case "$?" in
    0)  echo "<OK> Benutzername und Passwort des BYD-Kontos sind hinterlegt." ;;
    2)  echo "<INFO> Ob die Zugangsdaten vollstaendig sind, liess sich nicht pruefen"
        echo "<INFO> (kein php)." ;;
    *)  echo "<INFO> Benutzername oder Passwort des BYD-Kontos fehlen. Bitte im Reiter"
        echo "<INFO> Einstellungen eintragen." ;;
esac

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
