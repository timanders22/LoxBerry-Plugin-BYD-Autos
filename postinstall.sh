#!/bin/bash
# BYD Autos - postinstall
# Aufrufform des Installers:
#   $1 KENNUNG (zehnstellig, KEIN Pfad)   $2 NAME   $3 FOLDER
#   $4 VERSION                            $5 BASEFOLDER (LoxBerry-Wurzel)
#   $6 WORKDIR (der Arbeitsordner des Installers)
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

# Die Wurzel wird GEPRUEFT, nicht angenommen - siehe preupgrade.sh.
# LoxBerry::System taugt hier ohnehin nicht: es leitet den Pluginordner aus dem
# Aufrufort ab und liefert aus postinstall.sh heraus ueberall Leerstring.
#
# Bis 0.9.5 fiel das Skript auf "$SELF/../.." zurueck und pruefte danach GAR
# NICHT. Lief es aus dem Entpackordner des Installers, entstand ein Pfad
# ausserhalb der Wurzel; mkdir -p gelingt dort, venv und pybyd werden gebaut,
# und die letzte Zeile meldete "Installation abgeschlossen" - eine vollstaendige
# Installation neben dem LoxBerry, mit gruener Rueckmeldung.
ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ]
}
# Die Suche AUFWAERTS verlangt zusaetzlich config/system/general.json - siehe
# preupgrade.sh (Fall H2b des Pruefstands Pruefung-BYD-Autos-0.9.16).
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
    echo "<FAIL> Es wurde NICHTS angelegt und NICHTS installiert."
    exit 1
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"

# ---------- Die Marke bei einem Abbruch mitnehmen ----------
# Im Regelfall entfernt sie postupgrade.sh - das letzte Hakenskript dieser
# Linie, und es laeuft erst NACH dem Wiederanlauf weiter unten.
#
# Dieses Skript steigt aber an sieben Stellen mit "exit 1" aus (gezaehlt am
# 18.09.2026: Wurzel, Ordner, Python, venv anlegen, venv-Interpreter,
# pybyd-Installation, pybyd-Ladeprobe; die erste liegt vor diesem Trap, dort
# steht die Wurzel noch nicht fest). Bliebe die Marke danach liegen, waere
# die Plugin-Seite gesperrt und der Dienst gerade NICHT gestartet - eine
# Stunde lang, ohne dass irgendwo stuende, warum. Deshalb ein EXIT-Trap, der
# genau dann raeumt, wenn dieses Skript NICHT mit 0 endet.
# Gemessen (bash 5.2, Regeln/06): eine Kommandoersetzung und eine
# Unterschale loesen den EXIT-Trap nicht aus - die Marke faellt also nicht
# zu frueh weg.
by_marke_bei_abbruch() {
    BY_RC=$?
    if [ "$BY_RC" -ne 0 ] && [ -f "$MARKE" ]; then
        rm -f "$MARKE" 2>/dev/null
        echo "<WARNING> Die Installation ist abgebrochen. Die Sperre fuer"
        echo "<WARNING> Plugin-Seite und Dienststart wurde wieder aufgehoben."
    fi
    return $BY_RC
}
trap by_marke_bei_abbruch EXIT

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
#
# "Brauchbar" entscheidet der INHALT, nicht die Groesse (by_inhalt, wortgleich
# in preupgrade.sh). Bis 0.9.15 fragte diese Stelle "[ -s ]" und "{}": eine
# abgeschnittene zugang.json und eine mit leerem Passwort galten als
# brauchbar und wurden NICHT ersetzt, und eine abgeschnittene ZWEITSCHRIFT
# wurde zurueckgespielt und mit "<OK> ... wiederhergestellt" gemeldet.
# Gemessen am 18.09.2026 (Pruefung-BYD-Autos-0.9.16, Faelle C5, C6, C7).
# Der verdraengte Stand bleibt als <datei>.kaputt (0600) liegen, wenn er
# mehr war als die frische Vorgabe "{}".
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
by_kopie() {   # $1 Quelle, $2 Ziel - Nebendatei, pruefen, umbenennen
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
for f in byd.json zugang.json; do
    case "$f" in byd.json) ART=byd ;; *) ART=zugang ;; esac
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    [ -f "$BK" ] || continue
    by_inhalt "$CF" "$ART"
    RC_CF=$?
    [ "$RC_CF" = 0 ] && continue
    if [ "$RC_CF" = 2 ]; then
        echo "<WARNING> Der Inhalt von $f liess sich nicht pruefen (kein php) - es wurde"
        echo "<WARNING> nichts zurueckgespielt. Die Zweitschrift bleibt liegen: $BK"
        continue
    fi
    by_inhalt "$BK" "$ART"
    RC_BK=$?
    if [ "$RC_BK" = 1 ]; then
        echo "<WARNING> $f traegt keine Zugangsdaten bzw. kein Aktionstoken, und die"
        echo "<WARNING> Zweitschrift ebenfalls nicht (unvollstaendig oder unlesbar). Es wurde"
        echo "<WARNING> nichts zurueckgespielt; die Zweitschrift bleibt liegen: $BK"
        continue
    fi
    # RC_BK 2: $f ist nachweislich ohne Inhalt, die Zweitschrift nicht
    # pruefbar - zurueckgespielt wird trotzdem, verdraengt wird dabei nichts.
    if [ -s "$CF" ] && [ "$(tr -d ' \t\r\n' < "$CF" 2>/dev/null)" != "{}" ] \
       && [ ! -e "$CF.kaputt" ]; then
        by_kopie "$CF" "$CF.kaputt" \
            && echo "<INFO> Der verdraengte Stand liegt daneben: $f.kaputt"
    fi
    if by_kopie "$BK" "$CF"; then
        echo "<OK> $f aus der Sicherung wiederhergestellt."
        [ "$RC_BK" = 2 ] && echo "<WARNING> Ihr Inhalt liess sich nicht pruefen (kein php)."
    else
        echo "<WARNING> $f liess sich NICHT aus der Sicherung zurueckspielen; sie bleibt"
        echo "<WARNING> liegen: $BK"
    fi
done
chmod 600 "$PCONFIG/zugang.json"

# ---------- Ladehistorie zurueckspielen ----------
# preupgrade.sh hat data/plugins/<ordner>/verlauf/ neben den Konfigordner
# gelegt, weil der Installer den Datenordner dazwischen abraeumt.
#
# Zurueckgespielt wird nur, wenn am Ziel nichts steht: eine Sicherung, die eine
# vorhandene Historie ueberschreibt, ist kein Schutz. Und danach wird sie
# ENTFERNT - eine liegengebliebene Zweitschrift ueberlebt die Deinstallation
# und braechte einer spaeteren Neuinstallation stillschweigend fremde Daten
# zurueck.
VSICHERUNG="$BASE/config/plugins/$PFOLDER.backup.verlauf.tar"
if [ -f "$VSICHERUNG" ] && [ -s "$VSICHERUNG" ]; then
    if [ -d "$PDATA/verlauf" ] && [ -n "$(ls -A "$PDATA/verlauf" 2>/dev/null)" ]; then
        echo "<INFO> Im Datenordner liegt bereits eine Ladehistorie - die"
        echo "<INFO> gesicherte wurde NICHT darueber gespielt und ist entfernt."
    elif ! command -v tar >/dev/null 2>&1; then
        echo "<INFO> tar fehlt - die gesicherte Ladehistorie bleibt liegen unter"
        echo "<INFO> $VSICHERUNG"
        VSICHERUNG=""
    elif tar -xf "$VSICHERUNG" -C "$PDATA" 2>/dev/null; then
        ZEILEN=$(wc -l < "$PDATA/verlauf/ladungen.csv" 2>/dev/null || echo 0)
        case "$ZEILEN" in ''|*[!0-9]*) ZEILEN=0 ;; esac
        echo "<OK> Ladehistorie zurueckgespielt ($ZEILEN Zeilen in ladungen.csv)."
    else
        echo "<INFO> Die gesicherte Ladehistorie liess sich nicht zurueckspielen."
        echo "<INFO> Sie bleibt liegen unter $VSICHERUNG"
        VSICHERUNG=""
    fi
    [ -n "$VSICHERUNG" ] && rm -f "$VSICHERUNG"
fi

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
# KEIN chown. Bis 0.9.5 stand hier
#     chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
# Dieses Skript laeuft als loxberry (siehe Kopfzeile), ein chown durch einen
# Nicht-root scheitert immer, und das 2>/dev/null verschluckte es. Eine Zeile,
# die genau dann nichts tut, wenn sie gebraucht wuerde, ist keine Absicherung,
# sondern eine Absicherung, an die die naechste Hand glaubt.
#
# Gebraucht wird sie auch nicht: alles unter bin/, data/, config/ und log/ des
# Plugins gehoert ohnehin loxberry - der Installer legt es so an, und dieses
# Skript schreibt als derselbe Benutzer.
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
# Dienst vor dem Update lief.
#
# BERICHTIGT am 03.09.2026. Hier stand seit dem 20.08.2026, der Installer
# raeume den Datenordner NICHT ab und der Merker koennte deshalb auch dort
# liegen. Das war falsch - und es widersprach dem Absatz 115 Zeilen weiter
# oben in DIESER Datei, der es richtig sagt. purge_installation hat zwei
# Aufrufstellen, eine davon im Upgrade-Zweig, und ihr rm -rf trifft
# config/plugins/<ordner>/ UND data/plugins/<ordner>/ ohne Pruefung auf das
# Argument "all". Ausfuehrlich in preupgrade.sh, Schritt 1.
#
# Der Merker liegt deshalb NEBEN dem Konfigordner - dort ueberlebt er. Und er
# wird gebraucht: dieses Plugin haelt den Dienst ueber 'dienst.sh stop' an,
# und das entfernt den Sollmerker, an dem der Cron-Waechter haengt. Ohne
# diesen Merker bliebe der Dienst nach jedem Update stehen, bis jemand die
# Oberflaeche oeffnet. Das ist die unauffaelligste Art von Ausfall: der
# Endpunkt antwortet weiter mit dem letzten Stand, und in Loxone sieht das
# nicht nach einem Defekt aus, sondern nach einem ruhigen Tag.
#
# Ein bewusst angehaltener Dienst bleibt angehalten - deshalb ein Merker und
# kein pauschales "start". Eine Neuinstallation startet nichts von selbst:
# dort sollen erst die Zugangsdaten eingetragen werden.
MERKER="$BASE/config/plugins/$PFOLDER.lief_vorher"

# ---------- Waisen bei liegender Marke ----------
# Liegt die Marke aus preupgrade.sh, ist dies ein Upgrade: preupgrade.sh hat
# den Dienst ueber seine PID-Datei angehalten, und purge_installation hat die
# PID-Datei danach mit dem Datenordner geloescht. Ein Dienst, der OHNE
# PID-Datei lief (von Hand gestartet, Datei verloren), laeuft dann noch - mit
# dem Code der alten Fassung. Bis 0.9.16 startete dieses Skript daneben einen
# zweiten: zwei Dienste fragten BYD ab und arbeiteten dieselbe Warteschlange
# ab. In WSL gemessen 24.09.2026 (Pruefung-BYD-Autos-0.9.17, Fall O1: 2
# Dienste nach dem Upgrade). Regeln/06, Bauweise Einspeisebremse 0.9.20,
# Punkt 3 ("hält jeden Dienst an - auch einen ohne PID-Datei").
#
# Erkannt wird ARGUMENTWEISE wie in uninstall/uninstall: argv[0] ein Python,
# argv[1] genau bin/plugins/<ordner>/byd.py, kein drittes Argument (ein
# Einmallauf wie --selbsttest ist kein Dienst), und der Prozess gehoert dem
# Dienstbenutzer (loxberry, sonst dem Benutzer dieses Skripts). Vor JEDEM
# Signal wird erneut geprueft. War ein solcher Dienst da, lief der Dienst vor
# dem Update - er wird unten mit der neuen Fassung wieder gestartet.
by_ist_dienst() {   # $1 Prozessnummer
    [ -r "/proc/$1/cmdline" ] || return 1
    BY_ARGS=$(tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null)
    [ "$(echo "$BY_ARGS" | sed -n '2p')" = "$PBIN/byd.py" ] || return 1
    echo "$BY_ARGS" | sed -n '1p' | grep -qE '(^|/)python[0-9.]*$' || return 1
    [ -z "$(echo "$BY_ARGS" | sed -n '3p')" ] || return 1
    [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$BY_UID" ] || return 1
    return 0
}
BY_UID=$(id -u loxberry 2>/dev/null || id -u)
BY_WAISEN=0
if [ -f "$MARKE" ]; then
    for BY_D in /proc/[0-9]*; do
        BY_P=${BY_D#/proc/}
        by_ist_dienst "$BY_P" || continue
        kill "$BY_P" 2>/dev/null
        for i in 1 2 3 4 5 6 7 8 9 10; do
            by_ist_dienst "$BY_P" || break
            sleep 1
        done
        by_ist_dienst "$BY_P" && kill -9 "$BY_P" 2>/dev/null
        BY_WAISEN=$((BY_WAISEN + 1))
        echo "<INFO> Ein Abrufdienst ohne PID-Datei lief noch (PID $BY_P) und wurde"
        echo "<INFO> beendet; er wird mit der neuen Fassung wieder gestartet."
    done
fi

if [ -f "$MERKER" ] || [ "$BY_WAISEN" -gt 0 ]; then
    rm -f "$MERKER"
    if [ -x "$PBIN/dienst.sh" ]; then
        # BY_START_TROTZ_MARKE=1: dienst.sh startet seit 0.9.15 nicht, solange
        # die Marke aus preupgrade.sh gilt. Hier ist sie die eigene, und
        # dieser Start ist der Wiederanlauf am Ende der Installation. Die
        # Marke bleibt dabei LIEGEN und weist jeden anderen Starter ab, bis
        # postupgrade.sh sie entfernt - sonst saehe ein Waechterlauf zwischen
        # dem Entfernen und dem dastehenden Dienst weder das eine noch das
        # andere und startete einen zweiten (Chromecast4lox 1.3.10, in WSL
        # gemessen 17.09.2026).
        if BY_START_TROTZ_MARKE=1 "$PBIN/dienst.sh" start; then
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
