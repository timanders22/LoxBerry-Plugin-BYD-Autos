#!/bin/bash
# BYD Autos - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System. Grund: LoxBerry::System leitet den Pluginordner aus dem
# Aufrufort ab; wird dieses Skript aus postinstall.sh oder aus dem Cron
# gestartet, kommt dort ueberall Leerstring zurueck - das Skript werkelt dann
# gegen /-Pfade und meldet trotzdem Erfolg.

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
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
LOGDATEI="$PLOG/byd.log"
PY="$SELF/venv/bin/python3"
SKRIPT="$SELF/byd.py"

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
    #
    # Kein grep ueber die ganze Befehlszeile: /proc/<pid>/cmdline trennt die
    # Argumente mit Nullbytes, und ein grep darueber trifft JEDEN Prozess, der
    # den Pfad irgendwo fuehrt - auch einen Editor mit byd.py offen.
    # Zwei Bedingungen, nicht eine: das zweite Argument ist genau unser Skript,
    # und das erste ist ein Python. Die zweite braucht es, weil
    # "nano <pfad>/byd.py" ebenfalls den vollen Pfad als zweites Argument
    # fuehrt. Der Dienst laeuft immer als "<venv>/bin/python3 <pfad>/byd.py".
    ARGS=$(tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null)
    [ "$(echo "$ARGS" | sed -n '2p')" = "$SKRIPT" ] || return 1
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)python[0-9.]*$' || return 1
    return 0
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
    JETZT=$(date +%s)
    # 300 s: zehnmal der Schlagtakt. Weit genug weg von einer kurzen
    # Verzoegerung, eng genug, um ein Haengen in wenigen Minuten zu bemerken.
    [ $((JETZT - T)) -lt 300 ]
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
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
    # Ausgabe geht in die Logdatei. Das Python-Skript protokolliert deshalb
    # NICHT zusaetzlich nach stdout - sonst stuende jede Zeile doppelt darin,
    # und nach der Rotation schriebe der Shell-Deskriptor in die umbenannte
    # Datei weiter.
    nohup "$PY" "$SKRIPT" >> "$LOGDATEI" 2>&1 &
    echo $! > "$PID"
    sleep 2
    if laeuft; then
        # Der Sollmerker wird erst NACH dem gelungenen Start gesetzt.
        #
        # Andersherum - Merker vor dem Startversuch - macht aus einem
        # gescheiterten Start eine Endlosschleife: der minuetliche Waechter
        # findet den Merker, versucht es jede Minute erneut, und die
        # Oberflaeche zeigt trotzdem "gestoppt".
        touch "$SOLL"
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen. Die letzten Zeilen des Protokolls:"
    tail -n 5 "$LOGDATEI" 2>/dev/null | sed 's/^/        /'
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    P=$(cat "$PID")
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
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
            if ! laeuft; then
                GRUND="Dienst lief nicht"
            elif ! arbeitet; then
                GRUND="Dienst lief, aber sein Lebenszeichen ist ueber 300 s alt - er haengt"
                # Erst anhalten: ein zweiter Prozess neben dem haengenden
                # brauechte dieselben Dateien und dieselbe PID-Datei.
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: $GRUND. Wird angehalten." >> "$LOGDATEI"
                anhalten >> "$LOGDATEI" 2>&1 || true
            fi
        fi
        if [ -n "$GRUND" ]; then
            ZAEHLER="$PDATA/.waechter_fehl"
            N=$(cat "$ZAEHLER" 2>/dev/null || echo 0)
            case "$N" in ''|*[!0-9]*) N=0 ;; esac
            if [ "$N" -ge 3 ]; then
                LETZT=$(cat "$PDATA/.waechter_zeit" 2>/dev/null || echo 0)
                case "$LETZT" in ''|*[!0-9]*) LETZT=0 ;; esac
                JETZT=$(date +%s)
                if [ $((JETZT - LETZT)) -lt 1800 ]; then
                    exit 0
                fi
            fi
            date +%s > "$PDATA/.waechter_zeit"
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: $GRUND, wird neu gestartet (Fehlversuche bisher: $N)." >> "$LOGDATEI"
            if starten >> "$LOGDATEI" 2>&1; then
                rm -f "$ZAEHLER"
            else
                echo $((N + 1)) > "$ZAEHLER"
            fi
        else
            rm -f "$PDATA/.waechter_fehl"
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
