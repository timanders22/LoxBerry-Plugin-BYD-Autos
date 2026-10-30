#!/bin/bash
# Befehlsbruecke - starten, anhalten, fragen, waechten (gemeinsam, byte-gleich in jeder Linie;
# Stammfassung Werkzeuge/gemeinsam/befehlsbruecke.sh).
#   befehlsbruecke.sh start | stop | status | waechter
# Rueckgabe: status 0 = laeuft, 1 = steht; start/stop 0 = Wirkung nachgesehen oder Start mit Absicht
# unterlassen (Haken aus, Upgrade-Marke), 1 = nicht gelungen, 2 = Aufruf falsch, 3 = ein anderer
# start/stop haelt gerade die Sperre; waechter = Rueckgabe von befehlsbruecke.php --waechter.
# Aufruf immer mit "bash <pfad>" (das Archiv traegt nicht ueberall das Ausfuehrungsrecht).
#
# DIE SPERRE STEHT HIER, NICHT IM DIENST (Gedaechtnis "Sperre vererbt sich an Kinder"): eine
# flock-Sperre im PHP-Prozess erbte mosquitto_sub und hielt jeden Neustart auf. Der Dienst bekommt
# den Sperr-Deskriptor nicht mit (8<&-). Weil der Waechter aus dem PHP-Minutentakt einer Linie kommt,
# der selbst Sperren halten kann, schliesst dieses Skript zuerst ALLE geerbten Deskriptoren ueber 2 -
# sonst hielte der Dauerdienst die Takt-Sperre der Linie fest. Darum bash (dash kann keine
# Deskriptoren ueber 9 schliessen).
# Doppelstart beim Boot (cron holt Minuten nach, zwei Takte in derselben Sekunde): start nimmt die
# Sperre nicht blockierend (der zweite endet mit 3), und der Dienst prueft beim Start argumentweise.
# "Laeuft" wird argumentweise gemessen: argv[0] ein php, argv[1] genau unser Skript, kein argv[2]
# (Regeln/03, Regeln/06). Eine PID-Datei gibt es nicht - sie waere kein Beleg.
# Ausgaben im Fehlerfall enthalten die Woerter "gestartet" und "laeuft" nicht (postinstall.sh prueft
# auf *gestartet*|*laeuft*, Gedaechtnis "Dienststart").
#
# 1.0.1 (Pflegewelle 22, 25.10.2026), rein ergaenzend:
# - Wurzel geprueft (B1): nur wo config/plugins, data/plugins und config/system/general.json liegen
#   (Regeln/06); Ordnername aus LBPPLUGINDIR gegen die Ausschlussliste. Sonst nichts angelegt, Rueckgabe 1.
# - stop legt nichts an (BB1): uninstall laeuft als root (plugininstall.pl :1608) und ruft stop; ein als
#   root angelegter Ordner befehle/ oder eine root-eigene dienst.lock sperrte jeden spaeteren Start des
#   Benutzers loxberry. stop sperrt nur ueber eine schon vorhandene dienst.lock; start prueft root vor
#   dem Anlegen.
# - befehle/ 0700 statt 0750; leere oder ungueltige Uhr bei liegender Marke: kein Start (B3);
#   befehle_start.log vor jedem Start auf 256 kB gekappt (letzte 300 Zeilen, Regeln/06, BB6).

EIGEN=$(readlink -f "$0" 2>/dev/null)
for fd in /proc/$$/fd/*; do
    n=${fd##*/}
    case "$n" in ''|*[!0-9]*) continue ;; esac
    [ "$n" -gt 2 ] || continue
    [ "$(readlink "$fd" 2>/dev/null)" = "$EIGEN" ] && continue   # bash liest das Skript ueber diesen
    eval "exec $n>&-" 2>/dev/null
done

SELF=$(cd "$(dirname "$0")" 2>/dev/null && pwd -P)
SKRIPT="$SELF/befehlsbruecke.php"
PNAME=$(basename "$SELF")
if [ "$(basename "$(dirname "$SELF")")" = "plugins" ] && [ "$(basename "$(dirname "$(dirname "$SELF")")")" = "bin" ]; then
    BASE=$(cd "$SELF/../../.." 2>/dev/null && pwd -P)
elif [ -n "$LBHOMEDIR" ] && [ -n "$LBPPLUGINDIR" ]; then
    BASE="$LBHOMEDIR"
    PNAME=$(basename "$LBPPLUGINDIR")
else
    echo "befehlsbruecke.sh: keine LoxBerry-Installation erkannt - nichts getan." >&2
    exit 1
fi
case "$PNAME" in
    ''|.|..|/|html|htmlauth|bin|plugins|webfrontend)
        echo "befehlsbruecke.sh: keine LoxBerry-Installation erkannt - nichts getan." >&2
        exit 1 ;;
esac
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "befehlsbruecke.sh: keine LoxBerry-Installation erkannt - nichts getan." >&2
    exit 1
fi
DDIR="$BASE/data/plugins/$PNAME/befehle"
LDIR="$BASE/log/plugins/$PNAME"
OPTDIR="$DDIR/mosquitto"
MARKE="$BASE/data/plugins/$PNAME.upgrade_laeuft"

ist_unser() {   # $1 = /proc/<pid>
    local -a a
    mapfile -d '' -t a 2>/dev/null < "$1/cmdline" || return 1
    [ "${#a[@]}" -eq 2 ] || return 1
    [ "${a[1]}" = "$SKRIPT" ] || return 1
    [[ "${a[0]}" =~ (^|/)php[0-9.]*$ ]] || return 1
    return 0
}

alle_pids() {   # alle laufenden Dienste dieses Ordners
    local d
    for d in /proc/[0-9]*; do
        if ist_unser "$d"; then echo "${d#/proc/}"; fi
    done
}

abo_pids() {    # mosquitto_sub mit UNSERER Optionsdatei - auch verwaiste nach kill -9 des Dienstes
    local d c
    local -a e
    for d in /proc/[0-9]*; do
        c=""
        read -r c 2>/dev/null < "$d/comm"
        [ "$c" = "mosquitto_sub" ] || continue
        mapfile -d '' -t e 2>/dev/null < "$d/environ" || continue
        local x
        for x in "${e[@]}"; do
            if [ "$x" = "XDG_CONFIG_HOME=$OPTDIR" ]; then echo "${d#/proc/}"; break; fi
        done
    done
}

laeuft() { [ -n "$(alle_pids)" ]; }

PHP=$(command -v php 2>/dev/null)

case "$1" in
    status)
        if laeuft; then echo "laeuft (PID $(alle_pids | tr '\n' ' '))"; exit 0; fi
        echo "steht"; exit 1 ;;
    waechter)
        [ -n "$PHP" ] || { echo "php nicht gefunden." >&2; exit 1; }
        exec "$PHP" "$SKRIPT" --waechter ;;
    start|stop) ;;
    *) echo "Aufruf: befehlsbruecke.sh start|stop|status|waechter" >&2; exit 2 ;;
esac

if [ "$1" = "stop" ]; then
    # 1.0.1: stop legt nichts an (auch nicht als root, BB1). Gesperrt wird nur ueber eine vorhandene
    # dienst.lock; fehlt sie, hat kein start je gesperrt, und es geht ohne Sperre weiter.
    if [ -f "$DDIR/dienst.lock" ] && exec 8>>"$DDIR/dienst.lock"; then
        # stop wartet auf die Sperre (Deinstallation darf nicht an einem gleichzeitigen Start scheitern)
        if ! flock -w 15 8; then echo "Ein anderer Start oder Halt haelt die Sperre."; exit 3; fi
    fi
    # Erst der Dienst: er beendet sein mosquitto_sub selbst. Wer zuerst das Kind beendet, laesst den
    # Dienst ein Abo-Ende sehen und "Anmeldung abgewiesen" melden (gemessen 18.10.2026, PHP 8.4).
    for p in $(alle_pids); do kill "$p" 2>/dev/null; done
    i=0
    while laeuft && [ $i -lt 10 ]; do sleep 1; i=$((i + 1)); done
    for p in $(alle_pids); do
        for k in $(pgrep -P "$p" 2>/dev/null); do kill -9 "$k" 2>/dev/null; done
        kill -9 "$p" 2>/dev/null
    done
    for k in $(abo_pids); do kill "$k" 2>/dev/null; done
    i=0
    while [ -n "$(abo_pids)" ] && [ $i -lt 5 ]; do sleep 1; i=$((i + 1)); done
    for k in $(abo_pids); do kill -9 "$k" 2>/dev/null; done
    sleep 0.2
    if laeuft || [ -n "$(abo_pids)" ]; then echo "Befehlsbruecke laesst sich nicht anhalten."; exit 1; fi
    echo "Befehlsbruecke angehalten."
    exit 0
fi

# start (1.0.1: root vor dem Anlegen pruefen - als root entsteht nichts)
if [ "$(id -u)" = "0" ]; then echo "Nicht als root starten." >&2; exit 1; fi
mkdir -p "$LDIR" 2>/dev/null
mkdir -p -m 0700 "$DDIR" 2>/dev/null
chmod 0700 "$DDIR" 2>/dev/null
exec 8>"$DDIR/dienst.lock" || { echo "Sperrdatei nicht anlegbar ($DDIR)."; exit 1; }
if ! flock -n 8; then echo "Ein anderer Start oder Halt haelt die Sperre."; exit 3; fi
if [ -f "$MARKE" ]; then
    jetzt=$(date +%s 2>/dev/null); seit=$(cat "$MARKE" 2>/dev/null)
    case "$seit" in ''|*[!0-9]*) seit=0 ;; esac
    case "$jetzt" in
        ''|*[!0-9]*|0)   # 1.0.1: leere oder ungueltige Uhr - Marke gilt, geschlossen ausfallen (B3)
            echo "Aktualisierung im Gang - die Befehlsbruecke startet danach."; exit 0 ;;
    esac
    if [ "$seit" -le "$jetzt" ] && [ $((jetzt - seit)) -lt 3600 ]; then
        echo "Aktualisierung im Gang - die Befehlsbruecke startet danach."; exit 0
    fi
fi
if laeuft; then echo "Befehlsbruecke laeuft bereits (PID $(alle_pids | tr '\n' ' '))."; exit 0; fi
[ -n "$PHP" ] || { echo "php nicht gefunden."; exit 1; }
SOLL=$("$PHP" "$SKRIPT" --soll 2>&1); RC=$?
if [ "$RC" -eq 1 ]; then echo "Haken aus - kein Start."; exit 0; fi
if [ "$RC" -ne 0 ]; then echo "Kein Start: $SOLL"; exit 1; fi
# 1.0.1 (BB6): die Startausgabe waechst sonst ungekappt (log/plugins liegt im RAM); Hausform Regeln/06.
SLOG="$LDIR/befehle_start.log"
if [ -f "$SLOG" ] && [ "$(wc -c < "$SLOG" 2>/dev/null || echo 0)" -gt 262144 ]; then
    tail -n 300 "$SLOG" > "$SLOG.tmp.$$" 2>/dev/null && cat "$SLOG.tmp.$$" > "$SLOG" 2>/dev/null
    rm -f "$SLOG.tmp.$$" 2>/dev/null
fi
nohup "$PHP" "$SKRIPT" >>"$LDIR/befehle_start.log" 2>&1 </dev/null 8<&- &
# PHP stirbt bei Lade- oder Tabellenfehler nach ~130 ms (Regeln/03, am Geraet gemessen); 1 s reicht.
sleep 1
if laeuft; then echo "Befehlsbruecke gestartet (PID $(alle_pids | tr '\n' ' '))."; exit 0; fi
echo "Befehlsbruecke endete sofort: $(tail -n 2 "$LDIR/befehle_start.log" 2>/dev/null | tr '\n' ' ' | sed 's/laeuft/lief/g; s/gestartet/begonnen/g')"
exit 1
