#!/bin/bash
# BYD Autos - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Legt an: Konfigurations-, Daten- und Logordner, die Zugangsdatei mit Rechten
# 0600 und die virtuelle Python-Umgebung samt der Bibliothek pybyd.
#
# WICHTIG (PEP 668): Debian 12/13 kennzeichnen die System-Python-Umgebung als
# extern verwaltet. Ein systemweites "pip3 install" wird mit
# "error: externally-managed-environment" abgewiesen - auch mit --user, auch
# als root. Deshalb eine eigene venv, und der Shebang der Skripte zeigt direkt
# darauf. JEDER Rueckgabewert wird geprueft: eine Installation, die "ALLES
# ERLEDIGT" meldet, obwohl die venv fehlschlug, ist schlimmer als ein Abbruch.
#
# Dieses Skript laeuft als Benutzer loxberry, nicht als root (plugininstall.pl
# startet es mit "sudo -n -u loxberry"). Ein apt-get scheitert hier IMMER -
# die beiden benoetigten Debian-Pakete stehen deshalb in dpkg/apt.
#
# postinstall laeuft OHNE Bedingung, auch beim Upgrade (in plugininstall.pl
# steht davor kein "if ($isupgrade)"). Es muss also idempotent sein.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-bydautos}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Ableitung aus dem eigenen Ablageort - LoxBerry::System taugt hier nicht,
    # weil es den Pluginordner aus dem Aufrufort ableitet und aus
    # postinstall.sh heraus ueberall Leerstring liefert.
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"

# Fassung, gegen die dieses Plugin gebaut wurde. Auf einen Stand festgenagelt,
# damit eine Installation von heute morgen und eine von heute abend dasselbe
# ergeben. pybyd ist als Alpha gekennzeichnet ("API may evolve before 1.0") -
# eine neuere Fassung kann andere Feldnamen und andere Methodennamen haben.
#
# Das Plugin ist darauf vorbereitet: es fragt die installierte Fassung nach
# ihren Feld- und Methodennamen, statt sie zu raten (siehe bin/byd.py). Der
# Reiter Test zeigt, was gefunden wurde.
PYBYD="0.0.73"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" "$PDATA/befehle" "$PDATA/antworten" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

# ---------- Konfiguration ----------
[ -f "$PCONFIG/byd.json" ] || echo '{}' > "$PCONFIG/byd.json"
[ -f "$PCONFIG/zugang.json" ] || echo '{}' > "$PCONFIG/zugang.json"
chmod 600 "$PCONFIG/zugang.json"

# Zweitschrift zurueckspielen.
#
# Sie liegt NEBEN dem Konfigordner (config/plugins/<ordner>.backup.<datei>),
# nicht darin: LoxBerry entfernt beim Upgrade und beim Deinstallieren das
# VERZEICHNIS config/plugins/<ordner>/ - eine Sicherung darin stirbt also
# genau in dem Fall mit, fuer den es sie gibt. Dasselbe gilt fuer
# data/plugins/<ordner>/: das raeumt der Installer VOR diesem Skript
# vollstaendig ab (gemessen im Installationsprotokoll vom 18.08.2026).
#
# Zurueckgespielt wird nur, wenn am Ziel nichts Brauchbares steht. Eine
# Sicherung, die eine gute Datei ueberschreibt, ist kein Schutz.
for f in byd.json zugang.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    if [ -f "$BK" ] && [ -s "$BK" ]; then
        INHALT=$(tr -d ' \t\r\n' < "$CF" 2>/dev/null)
        if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
            cp -p "$BK" "$CF" && echo "<OK> $f aus der Sicherung wiederhergestellt."
        fi
    fi
done
chmod 600 "$PCONFIG/zugang.json"

# ---------- Python suchen ----------
# pybyd verlangt Python 3.11 oder neuer (Metadaten von pybyd 0.0.73:
# "Requires-Python >=3.11"). Debian 12 liefert 3.11, Debian 13 liefert 3.13;
# Debian 11 (LoxBerry 3.0.0) liefert 3.9 - deshalb steht LB_MINIMUM auf 3.0.1.
PY=""
for k in python3.13 python3.12 python3.11; do
    if command -v "$k" >/dev/null 2>&1; then PY="$k"; break; fi
done
if [ -z "$PY" ] && command -v python3 >/dev/null 2>&1; then
    if python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3,11) else 1)'; then
        PY="python3"
    fi
fi
if [ -z "$PY" ]; then
    HAVE=$(python3 -V 2>&1 || echo "kein python3")
    echo "<FAIL> Es wurde kein Python 3.11 oder neuer gefunden (gefunden: $HAVE)."
    echo "<FAIL> Die Bibliothek pybyd setzt Python >= 3.11 voraus."
    echo "<FAIL> Auf einem LoxBerry mit Debian 11 (Bullseye) gibt es nur Python 3.9;"
    echo "<FAIL> dort kann dieses Plugin nicht arbeiten. Ein Upgrade des LoxBerry auf"
    echo "<FAIL> Debian 12 loest es."
    echo "<FAIL> Das Plugin bleibt installiert, der Dienst kann aber nicht starten."
    exit 1
fi
echo "<INFO> Verwendetes Python: $PY ($($PY -V 2>&1))"

# ---------- virtuelle Umgebung ----------
BRAUCHBAR=0
if [ -x "$VENV/bin/python3" ]; then
    if "$VENV/bin/python3" -c 'import sys; sys.exit(0 if sys.version_info >= (3,11) else 1)' 2>/dev/null; then
        BRAUCHBAR=1
    fi
fi
if [ "$BRAUCHBAR" -eq 0 ]; then
    rm -rf "$VENV"
    if ! "$PY" -m venv "$VENV"; then
        echo "<FAIL> Virtuelle Umgebung konnte nicht angelegt werden ($VENV)."
        echo "<FAIL> Fehlt das Paket python3-venv? Es steht in dpkg/apt und wird von"
        echo "<FAIL> LoxBerry waehrend der Installation als root eingespielt - wenn das"
        echo "<FAIL> nicht geschehen ist, steht der Grund weiter oben im Protokoll."
        exit 1
    fi
    echo "<OK> Virtuelle Umgebung angelegt: $VENV"
fi
if [ ! -x "$VENV/bin/python3" ]; then
    echo "<FAIL> $VENV/bin/python3 fehlt - Abbruch."
    exit 1
fi

"$VENV/bin/python3" -m pip install --upgrade pip setuptools wheel >/dev/null 2>&1 || \
    echo "<INFO> pip liess sich nicht aktualisieren - es wird mit der vorhandenen Fassung versucht."

echo "<INFO> Installiere pybyd $PYBYD (benoetigt eine Internetverbindung) ..."
if ! "$VENV/bin/python3" -m pip install --no-cache-dir "pybyd==$PYBYD"; then
    echo "<INFO> Die feste Fassung ist nicht installierbar - es wird die neueste versucht."
    if ! "$VENV/bin/python3" -m pip install --no-cache-dir "pybyd"; then
        echo "<FAIL> pybyd konnte nicht installiert werden."
        echo "<FAIL> Haeufigste Ursachen: keine Internetverbindung, oder PyPI war nicht"
        echo "<FAIL> erreichbar."
        exit 1
    fi
    # Ersatzweg gegangen - und ANGEZEIGT, sonst wird aus dem Ersatz unbemerkt
    # der Normalfall. Bei einer anderen Fassung koennen sich Feldnamen und
    # Methodennamen geaendert haben.
    echo "<INFO> ERSATZWEG: Es wurde die neueste Fassung statt $PYBYD installiert."
    echo "<INFO> pybyd ist Alpha. Falls Werte leer bleiben, im Reiter Test den Knopf"
    echo "<INFO> 'Feldzuordnung vorschlagen' aufrufen - er zeigt, wie die Felder in"
    echo "<INFO> DIESER Fassung heissen."
fi

# Der Rueckgabewert allein genuegt nicht - es wird nachgesehen, ob sich das
# Paket auch laden laesst.
if ! "$VENV/bin/python3" -c 'from pybyd import BydClient, BydConfig' 2>/dev/null; then
    echo "<FAIL> pybyd ist installiert, laesst sich aber nicht laden."
    echo "<FAIL> Gesucht wurden die Namen BydClient und BydConfig."
    exit 1
fi
IST=$("$VENV/bin/python3" -c 'import importlib.metadata as m; print(m.version("pybyd"))' 2>/dev/null || echo "unbekannt")
echo "<OK> pybyd geladen, Fassung: $IST"

# ---------- Rechte ----------
chmod 755 "$PBIN/byd.py" 2>/dev/null
chmod 755 "$PBIN/dienst.sh" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
# Rechte am Ende noch einmal festziehen.
#
# byd.json bekommt ebenfalls 0600. Darin stehen zwar keine Passwoerter, aber
# das Token des unangemeldeten Endpunkts - und wer das lesen kann, kann ueber
# HTTP das Fahrzeug schalten. Es gibt keinen Grund, warum ein anderer
# Systembenutzer die Datei lesen koennen muss; der Dienst laeuft als loxberry.
chmod 600 "$PCONFIG/byd.json" 2>/dev/null
chmod 600 "$PCONFIG/zugang.json"

# ---------- Dienst wieder anlaufen lassen ----------
# preupgrade.sh legt den Merker "lief_vorher" NEBEN den Konfigordner, wenn der
# Dienst vor dem Update lief. Warum nicht in den Datenordner: den raeumt der
# Installer VOR diesem Skript vollstaendig ab - ein Merker darin waere hier
# weg, und der Dienst bliebe nach JEDEM Update still stehen, bis jemand die
# Oberflaeche oeffnet. Das ist die unauffaelligste Art von Ausfall: der
# Endpunkt antwortet weiter mit dem letzten Stand, und in Loxone sieht das
# nicht nach einem Defekt aus, sondern nach einem ruhigen Tag.
#
# Ein bewusst angehaltener Dienst bleibt angehalten - deshalb ein Merker und
# kein pauschales "start". Eine Neuinstallation startet nichts von selbst:
# dort sollen erst die Zugangsdaten eingetragen werden.
MERKER="$BASE/config/plugins/$PFOLDER.lief_vorher"
if [ -f "$MERKER" ]; then
    rm -f "$MERKER"
    if [ -x "$PBIN/dienst.sh" ]; then
        if "$PBIN/dienst.sh" start; then
            echo "<OK> Der Dienst lief vor dem Update und wurde wieder gestartet."
        else
            echo "<INFO> Der Dienst lief vor dem Update, liess sich aber nicht wieder"
            echo "<INFO> starten. Der minuetliche Waechter versucht es erneut; die"
            echo "<INFO> Begruendung steht im Reiter Logdateien."
        fi
    fi
else
    echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen, die Zugangsdaten des BYD-Kontos"
    echo "<INFO> eintragen und den Dienst im Reiter Einstellungen starten."
fi

echo "<OK> Installation abgeschlossen."
exit 0
