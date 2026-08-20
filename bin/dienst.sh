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
        if [ -f "$SOLL" ] && ! laeuft; then
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
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet (Fehlversuche bisher: $N)." >> "$LOGDATEI"
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
