#!/bin/bash
# BYD Autos - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade kommen aus $LBHOMEDIR oder, wenn das fehlt, aus einer geprueften
# Suche vom eigenen Ablageort aufwaerts (Abschnitt "Wurzel und Ordnername"
# unten) - nicht ueber LoxBerry::System. Grund: LoxBerry::System leitet den
# Pluginordner aus dem Aufrufort ab; wird dieses Skript aus postinstall.sh
# oder aus dem Cron gestartet, kommt dort ueberall Leerstring zurueck - das
# Skript werkelt dann gegen /-Pfade und meldet trotzdem Erfolg.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von dort
# aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins, der
# Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker und
# Logdatei landeten neben dem eigenen Ordner statt darin. Die Oberflaeche
# saehe den Dienst dann nie laufen, und der Waechter startete ihn im
# Minutentakt ein zweites Mal.
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)          # <home>/bin/plugins/<ordner>

# ---------- Wurzel und Ordnername: GELESEN, nicht geraten ----------
#
# Bis 0.9.15 stand hier
#     PNAME=$(basename "$SELF")
#     LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
# und darunter ein "mkdir -p" auf oberster Ebene. Ein gesetztes $LBHOMEDIR
# wurde damit ueberschrieben, der Ordnername kam aus dem Verzeichnisnamen,
# und der geratene Pfad wurde bei JEDEM Aufruf angelegt, auch bei "status".
# Gemessen am 18.09.2026 in WSL (Pruefung-BYD-Autos-0.9.16; dieselbe Bauart
# im Bestand: Bestand-2026-09-18/klasse-H, H1):
#   H1a  aus einem ausgepackten Archiv, LBHOMEDIR und LBPPLUGINDIR gesetzt:
#        Wurzel drei Ebenen ueber bin/, Ordnername "bin", data/ und log/
#        dort ANGELEGT, und der laufende Dienst galt als "gestoppt";
#   H1b  aus <Wurzel>/pruefung/bydautos/bin: data/plugins/bin und
#        log/plugins/bin in der INSTALLATION angelegt;
#   H1c  nach purge_installation legten status/stop/waechter den
#        Datenordner wieder an.
#
# Hausform (Regeln/03, Regeln/06): zuerst die gelesene Umgebung, dann die
# Suche aufwaerts nach einem Verzeichnis, das nachweislich eine Wurzel IST -
# mit config/system/general.json, ohne Ausnahme. Bis 0.9.16 galt daneben
# "genau die Wurzel, unter deren bin/plugins/ dieses Skript liegt", auch ohne
# general.json - ein fester Rueckfall auf drei Ebenen unter anderem Namen: in
# einem fremden Baum ohne general.json starteten start und waechter dessen
# Dienst, und stop hielt ihn an (in WSL gemessen 24.09.2026,
# Pruefung-BYD-Autos-0.9.17, Faelle F1 bis F4; Lehre aus
# Stand-Protokolle/2026-09-18_Welle1, "Neue Lehre fuer alle H1-Linien").
lb_wurzel_taugt() {   # $1 Kandidat
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ]
}
lb_wurzel_suchen() {
    v="$SELF"
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if lb_wurzel_taugt "$v" && [ -f "$v/config/system/general.json" ]; then
            echo "$v"; return 0
        fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
lb_wurzel_taugt "${LBHOMEDIR:-}" || LBHOMEDIR=$(lb_wurzel_suchen)
# $LBPPLUGINDIR steht am Geraet zwar nie in der Umgebung (Regeln/03, am
# 17.09.2026 gemessen) - wer sie setzt, meint sie aber ernst.
if [ -n "${LBPPLUGINDIR:-}" ]; then
    PNAME=$(basename "$LBPPLUGINDIR")
else
    PNAME=$(basename "$SELF")
fi
# Ohne Wurzel wird abgebrochen, statt mit Pfaden ab "/" weiterzuarbeiten
# (Fall H1f).
if [ -z "$LBHOMEDIR" ] || [ ! -d "$LBHOMEDIR" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden. \$LBHOMEDIR ist"
    echo "        nicht gesetzt, und oberhalb von $SELF liegt keine Wurzel."
    echo "        Es wurde nichts angelegt und nichts gestartet."
    exit 1
fi
PBIN_R=$(readlink -f "$LBHOMEDIR/bin/plugins/$PNAME" 2>/dev/null)
# Dienst und venv kommen aus dem bin-Ordner der INSTALLATION, nicht aus dem
# Ablageort dieser Datei - sonst verwaltete eine Kopie aus dem Archiv einen
# Dienst, den es nicht gibt. Im Regelfall ist beides derselbe, aufgeloeste
# Pfad; die Befehlszeile des Dienstes bleibt damit dieselbe wie bis 0.9.15.
[ -n "$PBIN_R" ] || PBIN_R="$LBHOMEDIR/bin/plugins/$PNAME"
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
# Die Marke der laufenden Aktualisierung. Sie liegt NEBEN dem Datenordner:
# purge_installation loescht den Ordner selbst, eine Marke darin waere genau
# in der Lage fort, fuer die es sie gibt (Regeln/06).
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
LOGDATEI="$PLOG/byd.log"
# Eigene Datei fuer alles, was NEBEN dem Protokoll anfaellt: Meldungen des
# Starts und alles, was das Programm nach stderr schreibt, bevor sein
# Protokoll steht (Syntaxfehler, fehlende Bibliothek, Abbruch im Importpfad).
#
# Bis 0.9.7 ging diese Ausgabe mit ">> $LOGDATEI" in DIESELBE Datei, die
# bin/byd.py mit einem umlaufenden Handler fuehrt. Das haelt einen zweiten,
# anhaengenden Deskriptor auf diese Datei offen. Beim Ueberlauf benennt der
# Handler um, beim Leeren der Ramdisk verschwindet die Datei ganz - der
# Deskriptor dieser Shell zeigt danach weiter auf die weggeschobene oder
# geloeschte Datei, und was er traegt, sieht niemand mehr. Am Geraet gemessen
# (06.09.2026): sieben Dienste hielten so eine geloeschte Protokolldatei offen.
# Regel: genau einer schreibt in eine Protokolldatei.
STARTLOG="$PLOG/byd_start.log"
PY="$PBIN_R/venv/bin/python3"
SKRIPT="$PBIN_R/byd.py"

# Angelegt wird nur, wo geschrieben wird: beim Start und beim Waechter, wenn
# der Dienst laufen SOLL. status und stop legen nichts an - in der
# Upgrade-Luecke hiesse ein wieder angelegter Datenordner sonst, dass "der
# Ordner ist da" nichts mehr ueber eine gelungene Ruecksicherung sagt
# (Fall H1c).
ordner_anlegen() {
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
}

# C1 (Durchgang 29.09.2026): bis 0.9.19 kannte dieses Skript nur den Dienst
# aus der PID-Datei. Zwei gleichzeitige Starts ergaben zwei Dienste; "stop"
# meldete "angehalten", einer lief weiter, und "status" sagte "gestoppt"
# (gemessen, Code-Pruefer T2). Jetzt wird argumentweise ueber /proc gesucht:
# ist_dienst prueft eine Nummer, dienste_suchen findet alle, dienst_pid nimmt
# zuerst die PID-Datei, dann die Suche. Bauform AudiConnect 0.9.22.
ist_dienst() {   # $1 Prozessnummer
    [ -n "$1" ] || return 1
    case "$1" in *[!0-9]*) return 1 ;; esac
    [ -r "/proc/$1/cmdline" ] || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
    #
    # Kein grep ueber die ganze Befehlszeile: /proc/<pid>/cmdline trennt die
    # Argumente mit Nullbytes, und ein grep darueber trifft JEDEN Prozess, der
    # den Pfad irgendwo fuehrt - auch einen Editor mit byd.py offen.
    # Zwei Bedingungen, nicht eine: das zweite Argument ist genau unser Skript,
    # und das erste ist ein Python. Die zweite braucht es, weil
    # "nano <pfad>/byd.py" ebenfalls den vollen Pfad als zweites Argument
    # fuehrt. Der Dienst laeuft immer als "<venv>/bin/python3 <pfad>/byd.py".
    ARGS=$(tr '\0' '\n' 2>/dev/null < "/proc/$1/cmdline")
    [ "$(echo "$ARGS" | sed -n '2p')" = "$SKRIPT" ] || return 1
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)python[0-9.]*$' || return 1
    # Und GENAU zwei Argumente, kein drittes. Sonst ist es ein Einmallauf und
    # kein Dienst: "<venv>/bin/python3 <pfad>/byd.py --selbsttest" laeuft
    # Sekunden und gehoert niemandem. Hier greift das nur bei einer
    # wiederverwendeten Prozessnummer aus der eigenen PID-Datei - in
    # uninstall/uninstall, das ueber /proc sucht, war es am 18.09.2026
    # messbar (Pruefung-BYD-Autos-0.9.15, Fall D1). Dieselbe Bauart bekommt
    # dieselbe Bedingung, damit nicht die eine Stelle richtig und die andere
    # falsch prueft.
    [ -z "$(echo "$ARGS" | sed -n '3p')" ] || return 1
    return 0
}

dienste_suchen() {
    for D in /proc/[0-9]*; do
        ist_dienst "${D#/proc/}" && echo "${D#/proc/}"
    done
    return 0
}

dienst_pid() {
    if [ -f "$PID" ]; then
        P=$(cat "$PID" 2>/dev/null)
        if ist_dienst "$P"; then
            printf '%s\n' "$P"
            return 0
        fi
    fi
    P=$(dienste_suchen | sed -n '1p')
    [ -n "$P" ] || return 1
    printf '%s\n' "$P"
    return 0
}

laeuft() {
    dienst_pid >/dev/null
}

# C3: gilt die Anmeldesperre von bin/byd.py fuer die GELTENDEN Zugangsdaten?
# Dieselbe Rechnung wie anmeldung_lesen() dort: anmeldung.json traegt die
# Pruefsumme von zugang.json und "gesperrt". Gelesen mit dem Python der
# eigenen Umgebung; ohne sie oder ohne Datei gilt keine Sperre.
anmeldung_gesperrt() {
    [ -f "$PDATA/anmeldung.json" ] || return 1
    [ -x "$PY" ] || return 1
    "$PY" -c 'import hashlib, json, sys
try:
    a = json.load(open(sys.argv[1], encoding="utf-8"))
    k = hashlib.sha256(open(sys.argv[2], "rb").read()).hexdigest()
except Exception:
    sys.exit(1)
sys.exit(0 if isinstance(a, dict) and a.get("gesperrt") and a.get("zugang") == k else 1)' \
        "$PDATA/anmeldung.json" "$PCONFIG/zugang.json" 2>/dev/null
}

arbeitet() {
    # Laeuft der Dienst nicht nur, sondern ARBEITET er auch?
    #
    # laeuft() beantwortet die erste Frage der Dreiteilung aus REGELN_1: der
    # Prozess ist da. Ein Dienst, der in einem Aufruf haengt, erfuellt das
    # tadellos und liefert trotzdem nichts - der Waechter meldete "in Ordnung",
    # waehrend seit Stunden kein Wert mehr ankam.
    #
    # Gemessen wird am Lebenszeichen, das die Hauptschleife alle 30 s
    # auffrischt (byd.py, DATEI_HERZ). NICHT am Zeitstempel des letzten
    # Abrufs: der steht bei einer Stoerung mit Absicht still, und die Bremse
    # nach mehreren Fehlversuchen reicht bis zu einer Stunde. Ein planmaessig
    # wartender Dienst ist kein haengender.
    #
    # Fehlt die Datei, wird KEIN Urteil gefaellt (Rueckgabe 0). Sie fehlt beim
    # allerersten Start, und sie fehlt, wenn sich der Protokollordner nicht
    # beschreiben laesst. Ein Dienst, der nur seine Ramdisk nicht beschreiben
    # kann, darf deswegen nicht im Minutentakt neu gestartet werden.
    HERZ="$PLOG/herzschlag"
    [ -f "$HERZ" ] || return 0
    T=$(cat "$HERZ" 2>/dev/null)
    case "$T" in ''|*[!0-9]*) return 0 ;; esac
    JETZT=$(date +%s 2>/dev/null)
    # Auch die Uhr ist erst eine Zahl, wenn sie als Zahl geprueft ist: bash
    # wertet in $(( )) den INHALT einer Variablen aus, und eine Ausgabe wie
    # a[$(befehl)] fuehrt den Befehl aus (Bestand-2026-09-18/klasse-M; hier in
    # WSL gemessen 24.09.2026, Pruefung-BYD-Autos-0.9.17, Fall Q1). Ohne
    # lesbare Uhr kein Urteil - wie bei fehlendem Lebenszeichen.
    case "$JETZT" in ''|*[!0-9]*) return 0 ;; esac
    # 300 s: zehnmal der Schlagtakt. Weit genug weg von einer kurzen
    # Verzoegerung, eng genug, um ein Haengen in wenigen Minuten zu bemerken.
    [ $((JETZT - T)) -lt 300 ]
}

upgrade_laeuft() {
    # Laeuft gerade eine Aktualisierung dieses Plugins?
    #
    # preupgrade.sh legt die Marke als Erstes an, postupgrade.sh - das letzte
    # Hakenskript dieser Linie - entfernt sie NACH dem Wiederanlauf. Der
    # Wiederanlauf selbst kommt aus postinstall.sh und setzt dazu
    # BY_START_TROTZ_MARKE=1: dort ist die Marke die eigene.
    #
    # Warum die Marke waehrend des Starts liegen BLEIBT und nicht vorher
    # faellt: zwischen dem Entfernen und dem Augenblick, in dem der neue
    # Dienst dasteht, saehe ein Waechterlauf weder Marke noch Dienst und
    # startete einen zweiten (an Chromecast4lox 1.3.10 in WSL gemessen,
    # 17.09.2026: umgekehrte Reihenfolge vier Dienste, diese Reihenfolge
    # einer).
    #
    # Drei Ausgaenge, und jeder ist Absicht:
    #   keine Marke / kein gueltiger Inhalt -> sie gilt NICHT. Eine
    #       abgebrochene Installation darf den Dienst nicht fuer immer
    #       stilllegen; dasselbe leistet die Frist von 3600 s.
    #   Marke da, Uhr nicht lesbar          -> sie GILT. Ein Schutz faellt
    #       geschlossen aus (CLAUDE.md, Abschnitt 4). Ohne diesen Zweig
    #       rechnete die Schale mit einer leeren Zeichenkette, das Alter
    #       wuerde negativ, die Bedingung fiele durch - und der Dienst
    #       startete mitten in der Aktualisierung.
    #   Marke da, Alter zwischen -300 s und 3600 s -> sie GILT. Ein paar
    #       Minuten "Zukunft" sind eine nachgestellte Uhr, keine Luege.
    [ "${BY_START_TROTZ_MARKE:-0}" = "1" ] && return 1
    [ -f "$MARKE" ] || return 1
    SEIT=$(cat "$MARKE" 2>/dev/null)
    case "$SEIT" in ''|*[!0-9]*) return 1 ;; esac
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in ''|*[!0-9]*) return 0 ;; esac
    ALTER=$((JETZT - SEIT))
    [ "$ALTER" -gt -300 ] && [ "$ALTER" -lt 3600 ]
}

starten() {
    # C1: ein Start zur Zeit. Gesperrt wird auf diesem Skript selbst, es muss
    # nichts angelegt werden. Der Dienst bekommt den Griff NICHT vererbt
    # (8<&- an der nohup-Zeile), sonst hielte er die Sperre, solange er
    # laeuft (Regeln/03, "Sperre vererbt sich an Kinder"). byd.py sperrt
    # zusaetzlich selbst. Ohne flock bleibt es beim bisherigen Weg.
    if command -v flock >/dev/null 2>&1; then
        exec 8<"$0"
        if ! flock -n 8; then
            echo "Ein anderer Start dieses Dienstes laeuft gerade - dieser Aufruf startet nichts."
            return 1
        fi
    fi
    # Vor allem anderen: waehrend einer Aktualisierung wird nichts gestartet.
    # Der Rueckgabewert ist 0 und kein Fehler - es ist nichts schiefgegangen,
    # es ist nur nicht der Augenblick dafuer. Ein Fehler hier liesse den
    # Waechter seinen Fehlversuchszaehler hochzaehlen und danach eine halbe
    # Stunde bremsen, ausgerechnet nach dem Update.
    if upgrade_laeuft; then
        echo "Start uebersprungen: eine Aktualisierung dieses Plugins laeuft."
        echo "        Der Dienst wird am Ende der Installation gestartet."
        return 0
    fi
    if P=$(dienst_pid); then
        echo "laeuft bereits (PID $P)"
        return 0
    fi
    if [ ! -x "$PY" ]; then
        echo "FEHLER: virtuelle Python-Umgebung fehlt ($PY). Plugin neu installieren."
        return 1
    fi
    # Die Zugangsdatei muss nicht nur DA sein, sondern auch etwas enthalten.
    # postinstall.sh legt sie als "{}" an; eine Pruefung auf ihr Vorhandensein
    # geht damit immer gut aus, und der Dienst stirbt eine Sekunde spaeter mit
    # "Zugangsdaten fehlen". Der Waechter startete ihn dann im Minutentakt neu
    # und schrieb dabei zwei Zeilen ins Protokoll - dauerhaft.
    if [ ! -s "$PCONFIG/zugang.json" ] || \
       [ "$(tr -d ' \t\r\n' < "$PCONFIG/zugang.json")" = "{}" ]; then
        echo "FEHLER: Es sind keine Zugangsdaten hinterlegt. Erst im Reiter"
        echo "        Einstellungen Benutzername und Passwort des BYD-Kontos eintragen."
        return 1
    fi
    # C3: gilt die Anmeldesperre, wird nicht gestartet - und nicht "gestartet"
    # gemeldet. Neue Zugangsdaten im Reiter Einstellungen heben sie auf.
    if anmeldung_gesperrt; then
        echo "FEHLER: Die Anmeldung bei BYD wurde wiederholt abgewiesen. Das Plugin meldet"
        echo "        sich nicht mehr an, bis im Reiter Einstellungen neue Zugangsdaten"
        echo "        gespeichert sind. Es wurde nichts gestartet."
        return 1
    fi
    # Die Ausgabe des Dienstes geht in die Startdatei, NICHT in das Protokoll:
    # dort schreibt allein der Handler des Programms. Beim Start gekappt, damit
    # sie nur die Ausgabe EINES Laufes sammelt und nicht unbegrenzt waechst.
    ordner_anlegen
    : > "$STARTLOG"
    nohup "$PY" "$SKRIPT" >> "$STARTLOG" 2>&1 8<&- &
    echo $! > "$PID"
    # C3: "gestartet" erst nach drei Sekunden Lebenszeit (Regeln/03, an Python
    # gemessen). Bis 0.9.19 stand hier "sleep 2".
    NEU=$(cat "$PID" 2>/dev/null)
    for i in 1 2 3; do
        sleep 1
        ist_dienst "$NEU" || break
    done
    if ist_dienst "$NEU" && ! anmeldung_gesperrt; then
        # Der Sollmerker wird erst NACH dem gelungenen Start gesetzt.
        #
        # Andersherum - Merker vor dem Startversuch - macht aus einem
        # gescheiterten Start eine Endlosschleife: der minuetliche Waechter
        # findet den Merker, versucht es jede Minute erneut, und die
        # Oberflaeche zeigt trotzdem "gestoppt".
        touch "$SOLL"
        echo "gestartet (PID $NEU)"
        return 0
    fi
    # Endete der neue Prozess, weil ein ANDERER Dienst die Sperre von byd.py
    # haelt (Rueckgabe 3), laeuft der Dienst - nur nicht dieser. Seine Nummer
    # kommt zurueck in die PID-Datei, die eben ueberschrieben wurde.
    if ANDERER=$(dienst_pid) && [ "$ANDERER" != "$NEU" ]; then
        echo "$ANDERER" > "$PID"
        echo "laeuft bereits (PID $ANDERER)"
        return 0
    fi
    if anmeldung_gesperrt; then
        echo "FEHLER: Die Anmeldung bei BYD wurde abgewiesen, die Anmeldesperre gilt jetzt."
        echo "        Neue Zugangsdaten im Reiter Einstellungen speichern."
    fi
    echo "FEHLER: Start fehlgeschlagen. Die letzten Zeilen der Startdatei:"
    tail -n 5 "$STARTLOG" 2>/dev/null | sed 's/^/        /'
    echo "    ... und des Protokolls:"
    tail -n 5 "$LOGDATEI" 2>/dev/null | sed 's/^/        /'
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    # C1: ALLE eigenen Dienste, nicht nur den aus der PID-Datei, und gemeldet
    # wird nur, was geschah - nachgesehen, nicht angenommen.
    LISTE=$(dienste_suchen)
    if [ -z "$LISTE" ]; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    ALLE=$(printf '%s\n' "$LISTE" | tr '\n' ' ')
    ALLE=${ALLE% }
    kill $LISTE 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -n "$(dienste_suchen)" ] || break
        sleep 1
    done
    REST=$(dienste_suchen)
    if [ -n "$REST" ]; then
        kill -9 $REST 2>/dev/null
        sleep 1
    fi
    UEBRIG=$(dienste_suchen)
    if [ -n "$UEBRIG" ]; then
        echo "FEHLER: Vorgang $(printf '%s\n' "$UEBRIG" | tr '\n' ' ')laeuft weiter - die PID-Datei bleibt stehen."
        echo "        Gehoert er einem anderen Benutzer? ps -o user= -p $(printf '%s' "$UEBRIG" | sed -n '1p')"
        return 1
    fi
    rm -f "$PID"
    echo "angehalten ($ALLE)"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if P=$(dienst_pid); then
            echo "laeuft $P"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        #
        # Dazu eine Bremse: hilft der Neustart nicht, darf der Waechter nicht
        # im Minutentakt nachsetzen und das Protokoll fluten. Nach drei
        # Fehlversuchen in Folge wird nur noch alle 30 Minuten versucht - und
        # gesagt, dass gebremst wird. Ein Waechter, der schweigend nichts tut,
        # ist schlimmer als keiner.
        #
        # Zwei Gruende fuer einen Neustart: der Prozess ist fort - ODER er ist
        # da und ruehrt sich nicht mehr. Der zweite Fall war bisher blind.
        GRUND=""
        if [ -f "$SOLL" ]; then
            # Der Protokollordner liegt auf der Ramdisk und kann fehlen.
            ordner_anlegen
            if ! laeuft && anmeldung_gesperrt; then
                # C3: gilt die Anmeldesperre, startet der Waechter nicht neu
                # und zaehlt nicht. Einmal je Stunde steht es im Protokoll.
                # Speichert der Anwender neue Zugangsdaten, gilt die Sperre
                # nicht mehr, und der naechste Lauf startet den Dienst.
                SPERRMERK="$PDATA/.waechter_anmeldesperre"
                LETZT=$(cat "$SPERRMERK" 2>/dev/null || echo 0)
                case "$LETZT" in ''|*[!0-9]*) LETZT=0 ;; esac
                JETZT=$(date +%s 2>/dev/null)
                case "$JETZT" in ''|*[!0-9]*) exit 0 ;; esac
                if [ $((JETZT - LETZT)) -ge 3600 ]; then
                    echo "$JETZT" > "$SPERRMERK"
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: die Anmeldesperre gilt - kein Neustart, bis neue Zugangsdaten gespeichert sind." >> "$LOGDATEI"
                fi
                exit 0
            fi
            rm -f "$PDATA/.waechter_anmeldesperre"
            if ! laeuft; then
                GRUND="Dienst lief nicht"
            elif ! arbeitet; then
                GRUND="Dienst lief, aber sein Lebenszeichen ist ueber 300 s alt - er haengt"
                # Erst anhalten: ein zweiter Prozess neben dem haengenden
                # brauechte dieselben Dateien und dieselbe PID-Datei.
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: $GRUND. Wird angehalten." >> "$LOGDATEI"
                anhalten >> "$STARTLOG" 2>&1 || true
            fi
        fi
        if [ -n "$GRUND" ]; then
            ZAEHLER="$PDATA/.waechter_fehl"
            N=$(cat "$ZAEHLER" 2>/dev/null || echo 0)
            case "$N" in ''|*[!0-9]*) N=0 ;; esac
            if [ "$N" -ge 3 ]; then
                LETZT=$(cat "$PDATA/.waechter_zeit" 2>/dev/null || echo 0)
                case "$LETZT" in ''|*[!0-9]*) LETZT=0 ;; esac
                JETZT=$(date +%s 2>/dev/null)
                # Ohne lesbare Uhr faellt die Bremse geschlossen aus: kein
                # Neustart (Fall Q2; Begruendung bei arbeitet()).
                case "$JETZT" in ''|*[!0-9]*) exit 0 ;; esac
                if [ $((JETZT - LETZT)) -lt 1800 ]; then
                    exit 0
                fi
            fi
            date +%s > "$PDATA/.waechter_zeit"
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: $GRUND, wird neu gestartet (Fehlversuche bisher: $N)." >> "$LOGDATEI"
            # C3: der Zaehler zaehlt JEDEN Neustart seit dem letzten Lauf, der
            # den Dienst laufen sah - auch einen, den starten() "gestartet"
            # nannte. Bis 0.9.19 loeschte ein solcher Start den Zaehler, und
            # ein Dienst, der nach drei Sekunden an der Anmeldung starb, wurde
            # jede Minute neu gestartet (gemessen, Code-Pruefer T3). Geloescht
            # wird er nur im Zweig darunter.
            echo $((N + 1)) > "$ZAEHLER"
            starten >> "$STARTLOG" 2>&1 || true
        else
            rm -f "$PDATA/.waechter_fehl"
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
