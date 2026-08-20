#!REPLACELBPBINDIR/venv/bin/python3
"""BYD Autos - Abrufdienst fuer LoxBerry.

Holt die Werte der BYD-Fahrzeugschnittstelle ueber die freie Bibliothek
"pybyd", legt sie als JSON-Zwischenspeicher ab, gibt sie auf Wunsch ueber das
LoxBerry-MQTT-Gateway weiter und arbeitet Schreibbefehle aus einer
Warteschlange ab, die der Loxone-Endpunkt fuellt.

Drei Aufgaben, drei Dateien - dieses Skript ist der Dienst. Die Oberflaeche
(webfrontend/htmlauth/index.php) und der Miniserver-Endpunkt
(webfrontend/html/index.php) rufen es nie direkt auf, sondern lesen den
Zwischenspeicher beziehungsweise legen Befehle ab.

WARUM DIE FELDZUORDNUNG UEBER KANDIDATENLISTEN LAEUFT
-----------------------------------------------------
BYD veroeffentlicht keine Schnittstellenbeschreibung. Was hier ueber Felder
und Befehle steht, stammt aus zwei offenen Quellen (siehe README) und ist an
KEINEM Fahrzeug dieses Hauses gemessen. pybyd ist ausserdem als Alpha
gekennzeichnet ("API may evolve before 1.0"), die Schreibweise eines Feldes
kann sich also aendern.

Einen einzelnen Namen zu raten waere hier derselbe Fehler wie eine geratene
Registeradresse. Deshalb:

  * Jedes Feld nennt MEHRERE zulaessige Schreibweisen (Kandidaten). Verglichen
    wird ohne Unterstriche und ohne Gross-/Kleinschreibung, damit
    "elecPercent", "elec_percent" und "ELEC_PERCENT" dasselbe treffen.
  * Welcher Kandidat wirklich getroffen hat, steht im Abbild (Feld
    "getroffen") und im Reiter Test. Ein Feld, das nichts getroffen hat,
    bleibt LEER - es wird keine 0 erfunden.
  * Die ganze Antwort der Bibliothek wird zusaetzlich unveraendert unter "roh"
    abgelegt. Der Knopf "Feldzuordnung vorschlagen" in der Oberflaeche listet
    daraus jedes Blatt mit seinem Pfad auf. Damit beantwortet das GERAET die
    Frage nach den Namen, nicht ich.

Aufrufe:
    byd.py                 Dienst (Dauerbetrieb)
    byd.py --einmal        ein einzelner Abruf, dann Ende
    byd.py --selbsttest    Pruefungen ohne Netz, Ausgabe als Klartext
    byd.py --felder        zeigt, welche Felder die Bibliothek gerade liefert
                           (braucht Zugangsdaten und Netz)
"""

from __future__ import annotations

import asyncio
import inspect
import json
import logging
import os
import signal
import socket
import sys
import time
from datetime import datetime, timezone
from logging.handlers import RotatingFileHandler
from pathlib import Path


def lb_wurzel_ermitteln() -> str:
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Eine feste Zahl von ".." ist nur
    die naechste Wette: LoxBerry legt Daemons als Symlink unter
    system/daemons/plugins ab, und dort stimmt sie nicht mehr.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


def mqtt_wert_saeubern(wert) -> str:
    """Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.

    Das Gateway liest zeilenweise. Ein Zeilenumbruch im Wert zerlegt die
    Uebertragung, und aus den Bruchstuecken bildet das Gateway erfundene
    Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und Wert
    trennt.
    """
    text = str(wert)
    for zeichen in ("\r\n", "\r", "\n", "\t"):
        text = text.replace(zeichen, " ")
    while "  " in text:
        text = text.replace("  ", " ")
    return text.strip()


# ---------------------------------------------------------------------------
# Pfade aus dem EIGENEN Ablageort ableiten.
#
# Nicht ueber LoxBerry::System: das leitet den Pluginordner aus dem Aufrufort
# ab und liefert bei einem Start aus postinstall.sh oder aus dem Cron ueberall
# Leerstring. Sichtbare Folge waere ein Dienst, der gegen /-Pfade werkelt und
# trotzdem Erfolg meldet.
# ---------------------------------------------------------------------------
SELF = Path(__file__).resolve().parent            # <home>/bin/plugins/<ordner>


def _ist_wurzel(p) -> bool:
    """Sieht dieses Verzeichnis nachweislich wie eine LoxBerry-Wurzel aus?"""
    try:
        return bool(p) and (p / "config" / "plugins").is_dir() \
            and (p / "webfrontend").is_dir()
    except OSError:
        return False


# Drei Quellen in dieser Reihenfolge, und jede wird GEPRUEFT statt angenommen:
#
#   1. LBHOMEDIR aus der Umgebung - die Auskunft von LoxBerry selbst.
#   2. Drei Ebenen ueber dem eigenen Ablageort. Das trifft die installierte
#      Lage <home>/bin/plugins/<ordner>.
#   3. Aufwaerts suchen, bis ein Verzeichnis gefunden ist, das eine Wurzel IST.
#
# Der erste Entwurf nahm Punkt 2 ungeprueft. Im entpackten Archiv liegt bin/
# aber unmittelbar unter der Plugin-Wurzel, und dann ergab die Rechnung einen
# Pluginnamen "bin" und ein Wurzelverzeichnis zwei Ebenen zu hoch. Gesehen hat
# das kein Werkzeug, sondern der Blick auf die Ausgabe von --selbsttest: dort
# standen Pfade wie config/plugins/bin. Deshalb nennt der Selbsttest jetzt
# auch, WELCHE Quelle gegriffen hat - ein Prueflauf sagt, was er geladen hat,
# nicht was er laden wollte.
_umg = os.environ.get("LBHOMEDIR") or ""
_kandidaten = [Path(_umg) if _umg else None,
               SELF.parents[2] if len(SELF.parents) >= 3 else None]
LBHOME = None
LBHOME_QUELLE = ""
for _i, _k in enumerate(_kandidaten):
    if _ist_wurzel(_k):
        LBHOME = _k
        LBHOME_QUELLE = "LBHOMEDIR" if _i == 0 else "drei Ebenen ueber bin/"
        break
if LBHOME is None:
    _gefunden = lb_wurzel_ermitteln()
    if _gefunden:
        LBHOME = Path(_gefunden)
        LBHOME_QUELLE = "aufwaerts gesucht"
    else:
        # Keine Wurzel gefunden - das ist der Fall "entpacktes Archiv, nicht
        # installiert". Es wird NICHT geraten: gearbeitet wird neben dem
        # Plugin, und der Selbsttest sagt es.
        LBHOME = SELF.parent
        LBHOME_QUELLE = "keine LoxBerry-Wurzel gefunden - neben dem Plugin"
# Der Pluginordner kommt von LoxBerry, wenn LoxBerry ihn nennt. Sonst aus dem
# Ablageort - und "bin" ist keiner: das ist die Archivlage.
PNAME = os.environ.get("LBPPLUGINDIR") or SELF.name
if PNAME in ("", ".", "/", "bin"):
    PNAME = SELF.parent.name
PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME

DATEI_CONFIG = PCONFIG / "byd.json"
DATEI_ZUGANG = PCONFIG / "zugang.json"
DATEI_CACHE = PDATA / "cache.json"
DATEI_LOXONE = PDATA / "loxone.json"
DATEI_ZUSTAND = PDATA / "zustand.json"
DATEI_ROH = PDATA / "rohdaten.json"
ORDNER_BEFEHLE = PDATA / "befehle"
ORDNER_ANTWORTEN = PDATA / "antworten"
DATEI_LOG = PLOG / "byd.log"

# Muessen zu by_vorgaben() in webfrontend/html/by_lib.php passen. Der Reiter
# Test vergleicht beide Listen gegeneinander - ein Kommentar "muss zur anderen
# passen" ist eine Hoffnung, keine Pruefung.
VORGABEN = {
    "intervall": 300,
    "mqtt_ein": 0,
    "mqtt_topic": "byd",
    "steuerung_ein": 0,
    "temp_min": 16,
    "temp_max": 30,
    "verlauf_tage": 8,
    "gps_ein": 1,
    "mqtt_bibliothek": 1,
    # --- Vorklimatisierung am Abfahrtsassistenten (ab Werk AUS) ---
    #
    # Eine neue Funktion, die schaltet, steht ab Werk aus: ein Vorgabewert, der
    # beim ersten Lauf ungefragt die Klimaanlage anwirft, ist ein Fehler.
    "abfahrt_ein": 0,
    "abfahrt_praefix": "abfahrt",
    "abfahrt_vorlauf": 20,
    "abfahrt_temp": 21,
    "abfahrt_alter": 300,
    "abfahrt_fahrzeug": 1,
    # --- Ladeempfehlung aus einem fremden Thema (ab Werk AUS) ---
    "ladeempf_ein": 0,
    "ladeempf_thema": "",
    "ladeempf_grenze": 0,
    "ladeempf_unter": 1,
    "ladeempf_alter": 900,
    # --- gerechnete Groessen ---
    # Beide sind LEER, solange die Zutat fehlt. Eine Kapazitaet, die niemand
    # eingetragen hat, wird nicht geraten - und ohne sie gibt es keinen
    # Verbrauch in kWh.
    "kapazitaet": 0,
    "heim_breite": "",
    "heim_laenge": "",
    "heim_radius": 150,
}

# Untergrenze des Abruftakts.
#
# Sie ist NICHT gemessen und stammt nicht von BYD, sondern ist eine eigene
# Wahl - deshalb steht sie hier als solche gekennzeichnet. Begruendung: die
# Abfrage weckt das Fahrzeug (der Weg ist "vehicleRealTimeRequest", also eine
# Aufforderung an das Auto, nicht ein Blick in einen Zwischenspeicher der
# Wolke). Ein zu dichter Takt kostet Ruhestrom und kann in eine Sperre laufen.
# Der ioBroker-Adapter derselben Schnittstelle setzt seine Vorgabe auf 300 s
# und seine Untergrenze auf 30 s.
TAKT_MIN = 120

# Zeitgrenze fuer einen Abruf. Ohne sie haelt eine Gegenstelle, die die
# Verbindung annimmt und dann schweigt, den ganzen Dienst an - samt
# Befehlswarteschlange, die im selben Ablauf abgearbeitet wird.
GRENZE_ABRUF = 180

_LAUF = True
_LOG = logging.getLogger("bydautos")
_LETZTE_MELDUNG: dict[str, float] = {}


# ===========================================================================
# Die Feldtabelle
#
# Je Feld:
#   kandidaten  zulaessige Schreibweisen in der Antwort der Bibliothek.
#               Verglichen wird ohne Unterstriche und ohne Gross-/
#               Kleinschreibung; die Reihenfolge ist die Rangfolge.
#   einheit     nur fuer die Anzeige und die Loxone-Vorlage
#   quelle      'doku'    aus einer offenen Quelle uebernommen, an KEINEM
#                         Fahrzeug dieses Hauses gemessen
#               'bestand' im Betrieb gegen eine echte Antwort geprueft
#               (Stand 20.08.2026: alle 'doku'. Wer ein Feld an seinem
#               Fahrzeug bestaetigt hat, aendert es hier auf 'bestand' -
#               der Reiter Test zaehlt beide getrennt.)
#   zeile       1 = geht in die Statuszeile fuer Loxone, 0 = nur MQTT und JSON
#
# Ein Feld, das niemand gemessen hat, darf nicht aussehen wie eines, das
# jemand gemessen hat (Hausregel, BatterieBMS).
# ===========================================================================
FELDER = {
    "SOC": {
        "kandidaten": ("elec_percent", "elecPercent", "soc", "battery_percent"),
        "einheit": "%", "quelle": "doku", "zeile": 1,
    },
    "KM": {
        "kandidaten": ("total_mileage", "totalMileage", "mileage", "odometer"),
        "einheit": "km", "quelle": "doku", "zeile": 1,
    },
    "REICHW": {
        "kandidaten": ("range", "remaining_range", "elec_mileage", "electric_range",
                       "range_detail_list.0.range"),
        "einheit": "km", "quelle": "doku", "zeile": 1,
    },
    "TEMPO": {
        "kandidaten": ("speed",),
        "einheit": "km/h", "quelle": "doku", "zeile": 1,
    },
    "LADEZUST": {
        # Der Rohwert, nicht umgerechnet. Bedeutung siehe LADEN/KABEL unten.
        "kandidaten": ("charge_state", "chargeState"),
        "einheit": "", "quelle": "doku", "zeile": 1,
    },
    "FAHRZUST": {
        "kandidaten": ("vehicle_state", "vehicleState"),
        "einheit": "", "quelle": "doku", "zeile": 1,
    },
    "ONLINE": {
        "kandidaten": ("online_state", "onlineState"),
        "einheit": "", "quelle": "doku", "zeile": 1,
    },
    "ZUENDUNG": {
        "kandidaten": ("engine_status", "engineStatus"),
        "einheit": "", "quelle": "doku", "zeile": 1,
    },
    "SCHLOSSVL": {
        # BEZEICHNUNG WOERTLICH: die Schnittstelle fuehrt genau EIN Tuerschloss,
        # das der Fahrertuer (links vorn). Das ist NICHT "Fahrzeug verriegelt" -
        # ein Name, der beides verwischt, ist eine stille Falschaussage.
        "kandidaten": ("left_front_door_lock", "leftFrontDoorLock"),
        "einheit": "", "quelle": "doku", "zeile": 1,
    },
    "BATTHEIZ": {
        "kandidaten": ("battery_heat_state", "batteryHeatState"),
        "einheit": "", "quelle": "doku", "zeile": 1,
    },
    "SITZHEIZ": {
        "kandidaten": ("main_seat_heat_state", "mainSeatHeatState"),
        "einheit": "", "quelle": "doku", "zeile": 1,
    },
    "BREITE": {
        "kandidaten": ("latitude", "lat"),
        "einheit": "", "quelle": "doku", "zeile": 0,
    },
    "LAENGE": {
        "kandidaten": ("longitude", "lon", "lng"),
        "einheit": "", "quelle": "doku", "zeile": 0,
    },
}

# Werte, die das Plugin selbst bildet - sie stehen nicht in der Antwort und
# tragen deshalb keine Kandidatenliste. Auch sie sind 'doku', solange die
# Zutaten es sind; was das Plugin ganz aus eigenen Angaben rechnet, ist
# 'gerechnet'.
#
# NEUE FELDER GEHOEREN ANS ENDE - auch hier. Die Reihenfolge der Statuszeile
# ergibt sich aus by_felder() in by_lib.php; eine Einfuegung in der Mitte
# verschiebt beim Anwender jede Befehlserkennung.
ABGELEITET = {
    "LAEDT": {"einheit": "", "quelle": "doku", "zeile": 1},
    "KABEL": {"einheit": "", "quelle": "doku", "zeile": 1},
    "RESTMIN": {"einheit": "min", "quelle": "doku", "zeile": 1},
    "FEHLFOLGE": {"einheit": "", "quelle": "gerechnet", "zeile": 1},
    "ZUHAUSE": {"einheit": "", "quelle": "gerechnet", "zeile": 1},
    "VERBRAUCH": {"einheit": "kWh/100km", "quelle": "gerechnet", "zeile": 1},
    "LADEEMPF": {"einheit": "", "quelle": "gerechnet", "zeile": 1},
    "LADEKWH": {"einheit": "kWh", "quelle": "gerechnet", "zeile": 1},
}

# Bedeutung von charge_state.
#
# Belegt aus dem ioBroker-Adapter derselben Schnittstelle (TA2k/ioBroker.byd):
#   0  nicht verbunden
#   1  laedt
#   15 Stecker steckt, laedt nicht
# Dort steht ausserdem, dass die Felder chargingState und connectState auf
# manchen Modellen dauerhaft -1 liefern und deshalb NICHT ausgewertet werden.
# Genau deshalb fragt dieses Plugin sie auch nicht ab.
LADEZUSTAND_LAEDT = (1,)
LADEZUSTAND_KABEL = (1, 15)

# Befehle: Name in der Warteschlange -> zulaessige Methodennamen in pybyd.
#
# Auch hier Kandidaten statt eines geratenen Namens. Welche Methode wirklich
# vorhanden ist, sagt die installierte Bibliothek; der Reiter Test listet es
# auf. Was sie nicht anbietet, wird ABGEWIESEN und nicht heimlich uebergangen.
#
# Bewusst NICHT dabei: Laden starten und anhalten. Die Bibliothek nennt dafuer
# keine Methode. Ein Bedienelement ohne Wirkung ist schlimmer als keines, und
# ein Loxone-Ausgang, der nur Absagen erntet, ist schlimmer als keiner.
BEFEHLE = {
    "verriegeln":      {"methoden": ("lock",),               "pin": 1},
    "entriegeln":      {"methoden": ("unlock",),             "pin": 1},
    "klima_start":     {"methoden": ("start_climate",),      "pin": 1},
    "klima_stop":      {"methoden": ("stop_climate",),       "pin": 1},
    "klima_plan":      {"methoden": ("schedule_climate",),   "pin": 1},
    "sitzklima":       {"methoden": ("set_seat_climate",),   "pin": 1},
    "batterieheizung": {"methoden": ("set_battery_heat",),   "pin": 1},
    "suchen":          {"methoden": ("find_car",),           "pin": 1},
    "blinken":         {"methoden": ("flash_lights",),       "pin": 1},
    "fenster_zu":      {"methoden": ("close_windows",),      "pin": 1},
}

# Themen, die ueber MQTT hinausgehen. Sie tragen dieselben Werte wie die
# HTTP-Zeile - ein Plugin, dessen MQTT-Meldung weniger enthaelt als seine
# HTTP-Zeile, macht die Umstellung unmoeglich, und zwar unauffaellig.
# Geprueft wird das im Reiter Test.
MQTT_ZUSATZ = ("ok", "ts", "fahrzeuge")


# ---------------------------------------------------------------------------
# Protokollierung
#
# Ausschliesslich in die Datei. Das Startskript leitet die Ausgabe des Dienstes
# ohnehin in dieselbe Datei um - ein zweiter Kanal nach stdout schriebe jede
# Zeile doppelt hinein. Nur --selbsttest und --felder reden auf den Bildschirm,
# und die liest die Oberflaeche per exec() ein.
# ---------------------------------------------------------------------------
def log_einrichten() -> None:
    PLOG.mkdir(parents=True, exist_ok=True)
    _LOG.setLevel(logging.INFO)
    try:
        h: logging.Handler = RotatingFileHandler(
            DATEI_LOG, maxBytes=512000, backupCount=1, encoding="utf-8")
    except OSError as err:
        h = logging.StreamHandler(sys.stderr)
        print("Logdatei nicht beschreibbar (%s) - schreibe nach stderr." % err,
              file=sys.stderr)
    h.setFormatter(logging.Formatter("[%(asctime)s] %(levelname)s %(message)s",
                                     "%Y-%m-%d %H:%M:%S"))
    _LOG.handlers = [h]
    _LOG.propagate = False
    # Die Bibliothek protokolliert in ihren eigenen Wurzel-Logger. Ohne diesen
    # Umweg landet nichts davon in der Plugin-Logdatei, und mit DEBUG waere sie
    # unlesbar.
    for fremd in ("pybyd", "aiohttp", "asyncio", "paho", "paho.mqtt"):
        f = logging.getLogger(fremd)
        f.handlers = [h]
        f.setLevel(logging.WARNING)
        f.propagate = False


def melde_gebremst(schluessel: str, text: str, sekunden: int = 3600) -> None:
    """Dieselbe Meldung hoechstens einmal je Zeitfenster - sonst wird die
    Logdatei durch eine Dauerstoerung unlesbar.

    Der Merker wird zurueckgesetzt, sobald die Protokolldatei fehlt: nach einem
    Neustart liegt log/plugins auf einer Ramdisk und ist leer, und dann
    unterdrueckte die Bremse ausgerechnet die ERSTE Zeile.
    """
    jetzt = time.time()
    if not DATEI_LOG.exists():
        _LETZTE_MELDUNG.clear()
    if jetzt - _LETZTE_MELDUNG.get(schluessel, 0) >= sekunden:
        _LETZTE_MELDUNG[schluessel] = jetzt
        _LOG.warning(text)


# ---------------------------------------------------------------------------
# Dateien
# ---------------------------------------------------------------------------
def json_lesen(pfad: Path) -> dict:
    try:
        with pfad.open("r", encoding="utf-8") as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def json_schreiben(pfad: Path, daten, rechte: int | None = None) -> bool:
    """Erst in eine Nebendatei, dann umbenennen.

    Die Nebendatei traegt die Prozessnummer im Namen: schreiben Dienst,
    Oberflaeche und Endpunkt dieselben Dateien, ueberschreibt sonst einer die
    Nebendatei des anderen, und umbenannt wird eine Mischung. Die Rechte werden
    auf der NEBENDATEI gesetzt, nicht danach - sonst liegt die Datei einen
    Augenblick lang mit den Rechten der umask da.
    """
    tmp = pfad.with_name(pfad.name + ".tmp." + str(os.getpid()))
    try:
        pfad.parent.mkdir(parents=True, exist_ok=True)
        # Erst kodieren, dann schreiben: json.dump wuerde eine halb
        # geschriebene Datei hinterlassen, wenn ein Wert nicht darstellbar ist.
        text = json.dumps(daten, ensure_ascii=False, indent=1, default=str)
        with tmp.open("w", encoding="utf-8") as f:
            if rechte is not None:
                try:
                    os.chmod(tmp, rechte)
                except OSError:
                    pass
            geschrieben = f.write(text)
        if geschrieben != len(text):
            tmp.unlink(missing_ok=True)
            _LOG.error("Datei %s: nur %d von %d Zeichen geschrieben.",
                       pfad, geschrieben, len(text))
            return False
        os.replace(tmp, pfad)
        return True
    except (OSError, TypeError, ValueError) as err:
        try:
            tmp.unlink(missing_ok=True)
        except OSError:
            pass
        _LOG.error("Datei %s konnte nicht geschrieben werden: %s", pfad, err)
        return False


def ganz(wert, ersatz: int) -> int:
    try:
        return int(wert)
    except (TypeError, ValueError):
        return ersatz


def config() -> dict:
    c = dict(VORGABEN)
    c.update(json_lesen(DATEI_CONFIG))
    c["intervall"] = max(TAKT_MIN, min(3600, ganz(c.get("intervall"), 300)))
    c["temp_min"] = max(10, min(32, ganz(c.get("temp_min"), 16)))
    c["temp_max"] = max(10, min(32, ganz(c.get("temp_max"), 30)))
    if c["temp_min"] > c["temp_max"]:
        c["temp_min"], c["temp_max"] = c["temp_max"], c["temp_min"]
    c["verlauf_tage"] = max(1, min(90, ganz(c.get("verlauf_tage"), 8)))
    c["abfahrt_vorlauf"] = max(1, min(120, ganz(c.get("abfahrt_vorlauf"), 20)))
    c["abfahrt_temp"] = max(c["temp_min"], min(c["temp_max"],
                                               ganz(c.get("abfahrt_temp"), 21)))
    c["abfahrt_alter"] = max(60, min(3600, ganz(c.get("abfahrt_alter"), 300)))
    c["ladeempf_alter"] = max(60, min(86400, ganz(c.get("ladeempf_alter"), 900)))
    c["kapazitaet"] = max(0, min(500, ganz(c.get("kapazitaet"), 0)))
    c["heim_radius"] = max(20, min(5000, ganz(c.get("heim_radius"), 150)))
    return c


def zugang() -> dict:
    z = json_lesen(DATEI_ZUGANG)
    return {
        "benutzer": str(z.get("benutzer") or "").strip(),
        "passwort": str(z.get("passwort") or ""),
        "pin": str(z.get("pin") or ""),
        "land": str(z.get("land") or "").strip(),
    }


# ---------------------------------------------------------------------------
# MQTT ueber das LoxBerry-Gateway
#
# Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
# Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
# eingeschaltet.
#
# Achtung: Mqtt.Brokerhost ist ab Werk gesetzt ("localhost"). Eine Pruefung
# darauf beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen.
# Massgeblich ist Gatewayautostart - der Schluessel heisst genau so, nicht
# "Autostart" (fuenf Plugins des Bestands haben das falsch gehabt).
# ---------------------------------------------------------------------------
def mqtt_zustand() -> dict:
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}
    autostart = m.get("Gatewayautostart", m.get("gatewayautostart"))
    udp = m.get("Udpinport", m.get("udpinport"))
    try:
        udp = int(udp)
    except (TypeError, ValueError):
        udp = 0
    return {
        "gefunden": bool(m),
        "autostart": 1 if str(autostart) in ("1", "true", "True") else 0,
        "udpport": udp,
        "broker": str(m.get("Brokerhost", m.get("brokerhost", ""))),
        "brokerport": str(m.get("Brokerport", m.get("brokerport", ""))),
    }


def mqtt_senden(paare: dict, praefix: str) -> tuple[int, int]:
    """Rueckgabe: (versucht, gescheitert).

    Beide Zahlen, nicht eine: ein Zaehler, der Schleifendurchlaeufe zaehlt
    statt Zustellungen, meldet "n Werte versendet" auch dann, wenn das Gateway
    gar nicht eingerichtet ist (Befund aus der Gardena-Sitzung).
    """
    z = mqtt_zustand()
    if not z["udpport"]:
        melde_gebremst("mqtt_kein_port",
                       "MQTT: kein UDP-Eingangsport in general.json gefunden - "
                       "nichts gesendet.")
        return (0, 0)
    if not z["autostart"]:
        melde_gebremst(
            "mqtt_aus",
            "Das MQTT-Gateway steht nicht auf Autostart (System -> MQTT Gateway). "
            "Es wird gesendet, aber vermutlich hoert niemand zu.")
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        melde_gebremst("mqtt_socket", "MQTT: Socket nicht moeglich (%s)." % err)
        return (0, 0)
    versucht = 0
    schlecht = 0
    try:
        for k, v in paare.items():
            if v is None:
                # Was die Gegenstelle nicht geliefert hat, wird NICHT gesendet.
                # Der virtuelle Eingang behaelt seinen letzten Wert, und dass er
                # alt ist, beantwortet das Lebenszeichen. Eine leere Nutzlast
                # laese Loxone als 0.
                continue
            versucht += 1
            try:
                s.sendto(("publish %s/%s %s" % (praefix, k, mqtt_wert_saeubern(v))
                          ).encode("utf-8"), ("127.0.0.1", z["udpport"]))
            except OSError:
                schlecht += 1
    finally:
        s.close()
    if schlecht:
        melde_gebremst("mqtt_senden",
                       "MQTT: %d von %d Werten liessen sich nicht absetzen."
                       % (schlecht, versucht))
    return (versucht, schlecht)


# ===========================================================================
# Der MQTT-Horcher
#
# Bis hierher hat das Plugin nur GESENDET, und zwar ueber den UDP-Eingang des
# LoxBerry-Gateways. Zwei neue Funktionen brauchen die andere Richtung:
#
#   * die Vorklimatisierung liest die Abfahrtszeit vom Abfahrtsassistenten,
#   * die Ladeempfehlung liest einen Preis oder einen Ueberschuss aus einem
#     beliebigen Thema.
#
# Beides ueber EINEN Horcher, nicht zwei: zwei Verbindungen zum selben Broker
# waeren zwei Stellen, die auseinanderlaufen.
#
# Drei Dinge, die dabei nicht vergessen werden duerfen:
#
#  1. ANMELDEN. Der Broker des LoxBerry verlangt ab Werk eine Anmeldung. Ein
#     Dienst, der Brokeruser und Brokerpass in der general.json liest und
#     dann anonym verbindet, bekommt NIE eine Nachricht - und im Protokoll
#     stuende "Broker nicht erreichbar", also eine Meldung, die auf ein
#     Netzproblem deutet statt auf eine abgelehnte Anmeldung.
#  2. ALTERSGRENZE. Ein Wert, der einmal ankam und dann nie wieder, ist kein
#     Messwert mehr. Jeder Wert traegt seinen Zeitstempel, und wer ihn liest,
#     prueft das Alter.
#  3. NACHABONNIEREN statt neu verbinden. Aendert sich die Themenliste in der
#     Oberflaeche, wird abgemeldet und neu abonniert; ein Neuaufbau kostet die
#     bereits empfangenen zurueckbehaltenen Werte.
# ===========================================================================
def mqtt_broker() -> dict:
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}

    def hol(gross, klein, ersatz=""):
        if gross in m:
            return m[gross]
        return m.get(klein, ersatz)

    return {
        "host": str(hol("Brokerhost", "brokerhost", "localhost")) or "localhost",
        "port": ganz(hol("Brokerport", "brokerport", 1883), 1883),
        "benutzer": str(hol("Brokeruser", "brokeruser", "")),
        "passwort": str(hol("Brokerpass", "brokerpass", "")),
        "lokal": str(hol("Uselocalbroker", "uselocalbroker", "")),
    }


class Horcher:
    """Haelt eine Verbindung zum Broker und die zuletzt empfangenen Werte."""

    def __init__(self):
        self.client = None
        self.werte: dict = {}          # thema -> (wert, zeitstempel)
        self.themen: set = set()
        self.fehler = ""
        self.verbunden = False

    def moeglich(self) -> tuple[bool, str]:
        try:
            import paho.mqtt.client as mqtt  # noqa: F401,PLC0415
        except ImportError:
            return (False, "Das Python-Modul paho-mqtt fehlt. Es kommt normalerweise "
                           "mit pybyd; ohne es kann das Plugin keine fremden Themen "
                           "lesen.")
        return (True, "")

    def sicherstellen(self, themen: set) -> None:
        """Verbindet, falls noetig, und richtet die Abos auf $themen aus."""
        themen = {t for t in themen if t}
        if not themen:
            self.schliessen()
            return
        ok, grund = self.moeglich()
        if not ok:
            if self.fehler != grund:
                self.fehler = grund
                melde_gebremst("horcher_modul", grund, 86400)
            return
        import paho.mqtt.client as mqtt  # noqa: PLC0415

        if self.client is None:
            b = mqtt_broker()
            try:
                # Ein eigener Client-Name je Prozess: zwei Clients mit
                # demselben Namen werfen sich gegenseitig vom Broker.
                self.client = mqtt.Client(
                    mqtt.CallbackAPIVersion.VERSION2,
                    client_id="bydautos-%s-%d" % (PNAME, os.getpid()))
            except (AttributeError, TypeError):
                # Aeltere paho-Fassungen kennen die Aufzaehlung nicht.
                self.client = mqtt.Client(client_id="bydautos-%s-%d"
                                          % (PNAME, os.getpid()))
            if b["benutzer"] != "":
                self.client.username_pw_set(b["benutzer"], b["passwort"])
            self.client.on_message = self._nachricht
            self.client.on_connect = self._verbunden_cb
            self.client.on_disconnect = self._getrennt_cb
            try:
                self.client.connect(b["host"], b["port"], keepalive=60)
                self.client.loop_start()
            except Exception as err:  # noqa: BLE001
                grund = ("Der Broker %s:%d hat die Verbindung nicht angenommen: %s. "
                         "Ist ein Benutzer hinterlegt? Der Broker des LoxBerry "
                         "verlangt ab Werk eine Anmeldung."
                         % (b["host"], b["port"], err))
                self.fehler = grund
                melde_gebremst("horcher_verbindung", grund, 900)
                self.client = None
                return
            self.fehler = ""

        neu = themen - self.themen
        fort = self.themen - themen
        for t in sorted(fort):
            try:
                self.client.unsubscribe(t)
            except Exception:  # noqa: BLE001
                pass
            self.werte.pop(t, None)
        for t in sorted(neu):
            try:
                self.client.subscribe(t, qos=0)
                _LOG.info("Horcher abonniert %s", t)
            except Exception as err:  # noqa: BLE001
                melde_gebremst("horcher_abo", "Abo %s nicht moeglich: %s" % (t, err), 900)
        self.themen = set(themen)

    def _verbunden_cb(self, *args, **kwargs):
        self.verbunden = True
        # Nach jedem Verbindungsaufbau werden die Abos neu gesetzt: der Broker
        # kennt sie nach einer Trennung nicht mehr.
        for t in sorted(self.themen):
            try:
                self.client.subscribe(t, qos=0)
            except Exception:  # noqa: BLE001
                pass

    def _getrennt_cb(self, *args, **kwargs):
        self.verbunden = False

    def _nachricht(self, client, userdata, nachricht):
        try:
            text = nachricht.payload.decode("utf-8", "replace").strip()
        except Exception:  # noqa: BLE001
            return
        self.werte[nachricht.topic] = (text, time.time())

    def wert(self, thema: str, hoechstalter: int):
        """Wert und Alter, oder (None, -1) wenn nichts Frisches vorliegt."""
        eintrag = self.werte.get(thema)
        if not eintrag:
            return (None, -1)
        alter = int(time.time() - eintrag[1])
        if alter > hoechstalter:
            return (None, alter)
        return (eintrag[0], alter)

    def schliessen(self) -> None:
        if self.client is not None:
            try:
                self.client.loop_stop()
                self.client.disconnect()
            except Exception:  # noqa: BLE001
                pass
        self.client = None
        self.themen = set()
        self.verbunden = False


def horcher_themen(cfg: dict) -> set:
    """Welche fremden Themen braucht das Plugin gerade?"""
    t = set()
    if cfg.get("abfahrt_ein"):
        p = str(cfg.get("abfahrt_praefix") or "abfahrt").strip("/")
        if p:
            t.add(p + "/ABFAHRT_IN")
            t.add(p + "/OK")
    if cfg.get("ladeempf_ein"):
        th = str(cfg.get("ladeempf_thema") or "").strip()
        if th:
            t.add(th)
    return t


def ladeempfehlung(horcher: Horcher, cfg: dict):
    """1 = laden empfohlen, 0 = nicht empfohlen, None = Funktion aus.

    Bewusst NICHT None bei einem veralteten Wert, und das ist eine Abweichung
    von der Hausregel "was nicht gemessen ist, wird nicht gesendet". Der Grund
    steht in der Richtung des Fehlers: ein virtueller Eingang behaelt seinen
    letzten Wert. Bliebe die Empfehlung bei einem veralteten Preis leer,
    stuende in Loxone weiter die 1 - und die Anlage lud weiter, weil niemand
    mehr widersprochen hat. Eine Empfehlung, die verstummt, muss also 0 sagen,
    nicht schweigen. Ist die Funktion ganz ausgeschaltet, ist das Feld leer:
    dann gibt es keine Aussage, und das ist etwas anderes als "nein".
    """
    if not cfg.get("ladeempf_ein"):
        return None
    thema = str(cfg.get("ladeempf_thema") or "").strip()
    if thema == "":
        return None
    roh, alter = horcher.wert(thema, ganz(cfg.get("ladeempf_alter"), 900))
    if roh is None:
        melde_gebremst("ladeempf_alt",
                       "Ladeempfehlung: zum Thema %s liegt kein frischer Wert vor "
                       "(Alter %s s). Es wird 0 gesendet, nicht geschwiegen - ein "
                       "virtueller Eingang behielte sonst seine letzte 1." % (thema, alter),
                       1800)
        return 0
    z = zahl(roh)
    if z is None:
        melde_gebremst("ladeempf_zahl",
                       "Ladeempfehlung: der Wert zum Thema %s ist keine Zahl (%r)."
                       % (thema, roh[:40]), 1800)
        return 0
    grenze = zahl(cfg.get("ladeempf_grenze"))
    if grenze is None:
        return 0
    return 1 if (z <= grenze if cfg.get("ladeempf_unter") else z >= grenze) else 0


def entfernung_m(b1, l1, b2, l2):
    """Entfernung zweier Punkte in Metern, oder None.

    Haversine mit dem mittleren Erdradius. Fehlt eine Zutat, entsteht KEIN
    Wert - und schon gar keine 0: eine 0 hiesse "das Auto steht genau hier".
    """
    import math  # noqa: PLC0415
    for w in (b1, l1, b2, l2):
        if w is None:
            return None
    r = 6371000.0
    p1 = math.radians(float(b1))
    p2 = math.radians(float(b2))
    dp = p2 - p1
    dl = math.radians(float(l2) - float(l1))
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(min(1.0, math.sqrt(a)))


# ---------------------------------------------------------------------------
# Antworten der Bibliothek in ein flaches Verzeichnis bringen
# ---------------------------------------------------------------------------
def zu_dict(objekt) -> dict:
    """Ein Objekt der Bibliothek in ein Verzeichnis verwandeln.

    pybyd arbeitet mit Pydantic-Modellen; die kennen model_dump(). Aeltere
    Fassungen kennen dict(). Beides wird versucht, danach __dict__. Eine
    Fassung, die keines davon anbietet, ergibt ein leeres Verzeichnis - und
    das faellt im Reiter Test als "0 Felder aufgeloest" auf, statt still
    falsche Werte zu erzeugen.
    """
    if objekt is None:
        return {}
    if isinstance(objekt, dict):
        return objekt
    for name in ("model_dump", "dict", "_asdict"):
        f = getattr(objekt, name, None)
        if callable(f):
            try:
                d = f()
            except TypeError:
                continue
            except Exception:  # noqa: BLE001 - eine Fassung, die hier wirft, ist kein Wert
                continue
            if isinstance(d, dict):
                return d
    if hasattr(objekt, "__dict__"):
        return {k: v for k, v in vars(objekt).items() if not k.startswith("_")}
    return {}


def flach(d: dict, praefix: str = "") -> dict:
    """Verschachtelte Verzeichnisse und Listen zu Punktpfaden aufloesen.

    Aus {"a": {"b": 1}} wird {"a.b": 1}, aus {"a": [{"b": 2}]} wird
    {"a.0.b": 2}. Damit kann eine Kandidatenliste auch auf einen Pfad zeigen,
    ohne dass der Leser die Struktur kennen muss.
    """
    aus: dict = {}
    for k, v in d.items():
        name = ("%s.%s" % (praefix, k)) if praefix else str(k)
        if isinstance(v, dict):
            aus.update(flach(v, name))
        elif isinstance(v, (list, tuple)):
            for i, e in enumerate(v):
                if isinstance(e, dict):
                    aus.update(flach(e, "%s.%d" % (name, i)))
                elif isinstance(e, (list, tuple)):
                    continue
                else:
                    aus["%s.%d" % (name, i)] = e
        else:
            aus[name] = v
    return aus


def normal(name: str) -> str:
    """Vergleichsform eines Schluessels: klein, ohne Unterstriche.

    Damit treffen elecPercent, elec_percent und ELEC_PERCENT dasselbe. Der
    Punkt bleibt stehen, weil er einen Pfad trennt.
    """
    return str(name).replace("_", "").lower()


def hole(roh: dict, kandidaten) -> tuple[object, str]:
    """Ersten treffenden Kandidaten holen. Rueckgabe: (Wert, getroffener Name).

    Kein Treffer ergibt (None, '') - und None heisst am Endpunkt ein Strich,
    nicht 0. Eine 0 waere eine stille Falschaussage.
    """
    if not roh:
        return (None, "")
    karte = {}
    for k, v in roh.items():
        karte.setdefault(normal(k), (k, v))
    for kandidat in kandidaten:
        treffer = karte.get(normal(kandidat))
        if treffer is not None and treffer[1] is not None:
            return (treffer[1], treffer[0])
    return (None, "")


def zahl(wert):
    """Zahl aus einem Rohwert, oder None. Keine Umwandlung ins Blaue:
    NaN und Unendlich sind KEINE Messwerte (w != w ist der Test, der immer
    geht), und eine Zeichenkette, die keine Zahl ist, ergibt None."""
    if wert is None or isinstance(wert, bool):
        return None
    if isinstance(wert, (int, float)):
        if wert != wert or wert in (float("inf"), float("-inf")):
            return None
        return wert
    s = str(wert).strip().replace(",", ".")
    if s == "" or s.lower() in ("nan", "inf", "-inf", "none", "null"):
        return None
    try:
        f = float(s)
    except ValueError:
        return None
    if f != f:
        return None
    return int(f) if f == int(f) else f


# ---------------------------------------------------------------------------
# Fehlermeldungen, die sagen, wer geantwortet hat
# ---------------------------------------------------------------------------
def fehlertext(err: BaseException) -> str:
    name = type(err).__name__
    inhalt = str(err) or name
    klein = inhalt.lower()

    if name in ("BydAuthenticationError",):
        return ("Anmeldung abgewiesen: Benutzername oder Passwort stimmen nicht. Es sind "
                "die Zugangsdaten des BYD-Kontos aus der BYD-App, nicht die eines "
                "Haendlerportals. Wenn beides stimmt: manche Konten verlangen eine "
                "Bestaetigung in der App, die sich hier nicht automatisieren laesst.")
    if name in ("BydRemoteControlError",):
        return ("Der Befehl wurde nicht angenommen: %s. Haeufigste Ursache ist eine "
                "falsche Steuer-PIN oder ein Fahrzeug, das diesen Befehl nicht "
                "anbietet." % inhalt)
    if name in ("BydApiError",):
        return "Die BYD-Schnittstelle hat abgewiesen: %s" % inhalt
    if name == "TimeoutError" or isinstance(err, asyncio.TimeoutError):
        return ("Zeitueberlauf: BYD hat nicht geantwortet. Meist eine gestoerte "
                "Internetverbindung oder eine Stoerung beim Anbieter.")

    grund = getattr(err, "os_error", None)
    errno = getattr(grund, "errno", None) if grund is not None else getattr(err, "errno", None)
    if errno == 111:
        return ("Verbindung abgewiesen (ECONNREFUSED): der Gegenstelle ist der Port "
                "bekannt, aber es lauscht nichts.")
    if errno == 113:
        return ("Kein Weg zum Ziel (EHOSTUNREACH): Netzwerk und Standardroute des "
                "LoxBerry pruefen.")
    if errno in (-2, -3):
        return ("Namensaufloesung fehlgeschlagen: der DNS-Server des LoxBerry antwortet "
                "nicht. Ohne DNS erreicht das Plugin die BYD-Schnittstelle nicht.")
    if "timed out" in klein:
        return ("Zeitueberlauf: BYD hat nicht geantwortet. Meist eine gestoerte "
                "Internetverbindung oder eine Stoerung beim Anbieter.")
    if "<html" in klein or "<!doctype" in klein:
        return ("Es kam HTML statt JSON zurueck - geantwortet hat also ein vorgelagerter "
                "Dienst (Proxy, Portal, Fehlerseite), nicht die BYD-Schnittstelle. Die "
                "Anmeldung selbst ist damit nicht der Fehler.")
    if "429" in inhalt or "too many" in klein:
        return ("BYD hat wegen zu vieler Anfragen abgewiesen (HTTP 429). Den Takt in den "
                "Einstellungen vergroessern; der naechste Abruf wird ohnehin gestreckt.")
    return "%s: %s" % (name, inhalt)


# ---------------------------------------------------------------------------
# Die Bibliothek vorbereiten
#
# WARUM HIER NICHTS FEST VERDRAHTET IST
#
# pybyd ist Alpha. Wie die Konfiguration heisst, welche Felder sie kennt und
# welche Methoden der Client anbietet, sagt die INSTALLIERTE Fassung - nicht
# eine Erinnerung. Deshalb wird beides zur Laufzeit erfragt und nur gesetzt,
# was es wirklich gibt. Was nicht gesetzt werden konnte, steht im Selbsttest.
# ---------------------------------------------------------------------------
FELDNAMEN = {
    "benutzer": ("username", "user", "account", "email", "login"),
    "passwort": ("password", "passwd", "pwd"),
    "pin":      ("control_pin", "pin", "control_password", "command_pin",
                 "controlpin"),
    "land":     ("country_code", "country", "region", "language"),
    "mqtt":     ("mqtt_enabled", "use_mqtt", "mqtt", "enable_mqtt"),
}


def bekannte_felder(klasse) -> dict:
    """Welche Konfigurationsfelder kennt diese Fassung der Bibliothek?

    Rueckgabe: normalisierter Name -> wirklicher Name.
    """
    namen: list[str] = []
    mf = getattr(klasse, "model_fields", None)          # Pydantic v2
    if isinstance(mf, dict):
        namen.extend(mf.keys())
    df = getattr(klasse, "__dataclass_fields__", None)  # dataclass
    if isinstance(df, dict):
        namen.extend(df.keys())
    if not namen:
        try:
            namen.extend(p for p in inspect.signature(klasse).parameters
                         if p not in ("self", "args", "kwargs"))
        except (TypeError, ValueError):
            pass
    return {normal(n): n for n in namen}


def konfiguration_bauen(z: dict, cfg: dict):
    """Die Konfiguration fuer pybyd. Rueckgabe: (Objekt, Bericht).

    Der Bericht sagt je Angabe, unter welchem Namen sie untergebracht wurde -
    oder dass die Bibliothek keinen passenden Namen kennt. Zugangsdaten stehen
    dabei nur im Arbeitsspeicher, nie in einer Datei, die die Oberflaeche
    anzeigt.
    """
    from pybyd import BydConfig  # noqa: PLC0415 - erst hier, damit --selbsttest ohne Bibliothek laeuft

    werte = {
        "benutzer": z["benutzer"],
        "passwort": z["passwort"],
        "pin": z["pin"],
        "land": z["land"],
        "mqtt": bool(cfg.get("mqtt_bibliothek")),
    }
    vorhanden = bekannte_felder(BydConfig)
    args: dict = {}
    bericht: dict = {}
    for eigen, kandidaten in FELDNAMEN.items():
        wert = werte.get(eigen)
        if wert is None or wert == "":
            bericht[eigen] = "nicht hinterlegt"
            continue
        ziel = ""
        for k in kandidaten:
            if normal(k) in vorhanden:
                ziel = vorhanden[normal(k)]
                break
        if ziel == "":
            bericht[eigen] = "die Bibliothek kennt keinen passenden Namen"
            continue
        args[ziel] = wert
        bericht[eigen] = ziel

    try:
        return (BydConfig(**args), bericht)
    except Exception as err:  # noqa: BLE001
        # Ersatzweg ueber die Umgebung - und er wird ANGEZEIGT, sonst wird aus
        # dem Ersatz unbemerkt der Normalfall.
        _LOG.warning("BydConfig(**args) hat abgewiesen (%s) - es wird der Weg ueber "
                     "die Umgebungsvariablen versucht.", err)
        for ziel, wert in args.items():
            os.environ["BYD_" + ziel.upper()] = str(wert)
        bericht["_weg"] = "Umgebungsvariablen BYD_*"
        return (BydConfig.from_env(), bericht)


def methode_finden(client, kandidaten):
    """Erste vorhandene Methode. Rueckgabe: (Funktion, Name) oder (None, '')."""
    for name in kandidaten:
        f = getattr(client, name, None)
        if callable(f):
            return (f, name)
    return (None, "")


async def methode_rufen(f, vin: str, zusatz: dict | None = None):
    """Eine Methode der Bibliothek aufrufen, ohne ihre Signatur zu raten.

    Uebergeben wird die Fahrgestellnummer als erstes Argument und von den
    Zusatzangaben nur, was die Signatur wirklich kennt. Ein Zusatz, der nicht
    unterzubringen ist, wird GEMELDET - stilles Uebergehen machte aus einer
    gesetzten Zieltemperatur eine, die nie ankommt.
    """
    zusatz = zusatz or {}
    try:
        sig = inspect.signature(f)
        namen = {normal(p): p for p in sig.parameters}
    except (TypeError, ValueError):
        namen = {}
    args: dict = {}
    fehlt: list[str] = []
    for k, v in zusatz.items():
        if v is None:
            continue
        ziel = ""
        for kandidat in (k,) + ZUSATZNAMEN.get(k, ()):
            if normal(kandidat) in namen:
                ziel = namen[normal(kandidat)]
                break
        if ziel:
            args[ziel] = v
        else:
            fehlt.append(k)
    erg = f(vin, **args) if namen else f(vin)
    if inspect.isawaitable(erg):
        erg = await erg
    return (erg, fehlt)


# Zusaetzliche Schreibweisen fuer die Argumente der Befehlsmethoden.
ZUSATZNAMEN = {
    "temperature": ("temp", "target_temperature", "target_temp"),
    "time_span": ("duration", "minutes", "span"),
    "enable": ("on", "state", "active"),
    "level": ("value", "stufe"),
}


# ---------------------------------------------------------------------------
# Ein Fahrzeug abbilden
# ---------------------------------------------------------------------------
def fahrzeug_abbilden(stamm: dict, echtzeit: dict, gps: dict) -> dict:
    """Setzt das Abbild eines Fahrzeugs aus den drei Antworten zusammen.

    Alle drei Verzeichnisse sind bereits flach. Was nicht getroffen wird,
    bleibt leer; welcher Kandidat getroffen hat, steht in 'getroffen'.
    """
    roh: dict = {}
    roh.update(stamm)
    roh.update(echtzeit)   # Echtzeit sticht Stammdaten (totalMileage steht in beiden)
    roh.update(gps)

    d: dict = {}
    getroffen: dict = {}
    offen: list[str] = []
    for name, eig in FELDER.items():
        wert, treffer = hole(roh, eig["kandidaten"])
        if treffer == "":
            offen.append(name)
            d[name] = None
            continue
        getroffen[name] = treffer
        d[name] = wert if eig["einheit"] == "" and not isinstance(wert, (int, float)) \
            else zahl(wert) if zahl(wert) is not None else wert

    # ---- abgeleitete Werte ----
    lz = zahl(d.get("LADEZUST"))
    d["LAEDT"] = 1 if lz in LADEZUSTAND_LAEDT else (0 if lz is not None else None)
    d["KABEL"] = 1 if lz in LADEZUSTAND_KABEL else (0 if lz is not None else None)

    # Restzeit des Ladevorgangs. Fehlt eine Zutat, entsteht KEIN Wert - nicht 0.
    std, _ = hole(roh, ("remaining_hours", "remainingHours"))
    minu, _ = hole(roh, ("remaining_minutes", "remainingMinutes"))
    std = zahl(std)
    minu = zahl(minu)
    if std is None and minu is None:
        d["RESTMIN"] = None
    else:
        d["RESTMIN"] = int((std or 0) * 60 + (minu or 0))

    # ---- Stammangaben fuer die Oberflaeche (nicht fuer Loxone) ----
    for ziel, kandidaten in (
        ("vin", ("vin",)),
        ("name", ("auto_alias", "autoAlias", "alias", "name")),
        ("kennzeichen", ("auto_plate", "autoPlate", "plate", "license_plate")),
        ("marke", ("brand_name", "brandName", "brand")),
        ("modell", ("model_name", "modelName", "model")),
        ("antriebsart", ("energy_type", "energyType", "car_type", "carType")),
        ("tbox", ("tbox_version", "tboxVersion")),
    ):
        wert, treffer = hole(roh, kandidaten)
        d[ziel] = "" if wert is None else str(wert)
        if treffer:
            getroffen[ziel] = treffer

    d["getroffen"] = getroffen
    d["offen"] = offen
    # ok bezieht sich auf DIESES Fahrzeug: hat der Abruf ueberhaupt einen
    # Messwert gebracht? Eine Antwort ist kein Lebenszeichen.
    d["ok"] = 1 if any(d.get(n) is not None for n in ("SOC", "KM", "FAHRZUST")) else 0
    return d


# ===========================================================================
# Gerechnete Groessen und die Ladevorgaenge
#
# Drei Regeln, die hier ueberall gelten:
#
#   * Fehlt eine Zutat, entsteht KEIN Wert. Nicht 0 - eine 0 steht in Loxone
#     und sieht richtig aus.
#   * Gerechnet wird nur ueber ABGESCHLOSSENE Abschnitte. Eine mittlere
#     Groesse aus dem laufenden Abschnitt behauptet im Grenzfall das Gegenteil.
#   * Die Messgenauigkeit gehoert in die Oberflaeche, nicht in eine Fussnote:
#     gezaehlt wird im Abruftakt, ein Ladevorgang unter einem Takt faellt
#     zwischen zwei Abrufe.
# ===========================================================================
DATEI_MERKER = PDATA / "merker.json"


def merker_lesen() -> dict:
    return json_lesen(DATEI_MERKER)


def merker_schreiben(m: dict) -> None:
    json_schreiben(DATEI_MERKER, m)


def ladung_anhaengen(zeile: dict, tage: int) -> None:
    """Einen abgeschlossenen Ladevorgang in die CSV schreiben.

    Anders als beim Schwesterplugin Renault, dessen Ladehistorie NUR als
    MQTT-Wert und als Klartext eines Handaufrufs existiert, wird hier wirklich
    abgelegt - sonst ist "Ladehistorie" der Name einer Anzeige und nicht einer
    Aufzeichnung.
    """
    ordner = PDATA / "verlauf"
    try:
        ordner.mkdir(parents=True, exist_ok=True)
    except OSError:
        return
    datei = ordner / "ladungen.csv"
    neu = not datei.is_file()
    try:
        with datei.open("a", encoding="utf-8") as f:
            if neu:
                f.write("# fahrzeug;start;ende;dauer_min;soc_start;soc_ende;km;kwh\n")
            f.write("%s;%d;%d;%s;%s;%s;%s;%s\n" % (
                zeile["fahrzeug"], zeile["start"], zeile["ende"], zeile["dauer"],
                zeile["soc_start"], zeile["soc_ende"],
                zeile["km"] if zeile["km"] is not None else "",
                zeile["kwh"] if zeile["kwh"] is not None else ""))
    except OSError:
        return
    # Kappung: die Datei liegt im Datenordner (keine Ramdisk), waechst aber
    # unbegrenzt. Aufbewahrt wird, was in das eingestellte Fenster passt.
    grenze = time.time() - max(30, tage * 4) * 86400
    try:
        zeilen = datei.read_text(encoding="utf-8").splitlines()
    except OSError:
        return
    behalten = [z for z in zeilen if z.startswith("#")]
    for z in zeilen:
        if z.startswith("#") or ";" not in z:
            continue
        teile = z.split(";")
        if len(teile) > 2 and ganz(teile[2], 0) >= grenze:
            behalten.append(z)
    if len(behalten) < len(zeilen):
        try:
            datei.write_text("\n".join(behalten) + "\n", encoding="utf-8")
        except OSError:
            pass


def abgeleitetes_ergaenzen(nummer: str, d: dict, cfg: dict, merker: dict,
                           fehler_folge: int, empfehlung) -> None:
    """Fuellt die gerechneten Felder und fuehrt die Ladevorgaenge nach."""
    m = merker.setdefault("fz" + str(nummer), {})

    # ---- Stoerungszaehler ----
    # Er gilt fuer den ganzen Dienst, nicht je Fahrzeug - er steht trotzdem in
    # jeder Statuszeile, weil Loxone je Fahrzeug EINEN Eingang liest. Dass es
    # derselbe Wert ist, sagt der Hilfetext.
    d["FEHLFOLGE"] = int(fehler_folge)
    d["LADEEMPF"] = empfehlung

    # ---- Geofence ----
    hb = zahl(cfg.get("heim_breite"))
    hl = zahl(cfg.get("heim_laenge"))
    if hb is None or hl is None:
        d["ZUHAUSE"] = None          # keine Heimatposition hinterlegt
    else:
        e = entfernung_m(zahl(d.get("BREITE")), zahl(d.get("LAENGE")), hb, hl)
        d["ZUHAUSE"] = None if e is None else (1 if e <= cfg["heim_radius"] else 0)

    # ---- Ladevorgaenge ----
    laedt = d.get("LAEDT")
    soc = zahl(d.get("SOC"))
    km = zahl(d.get("KM"))
    kap = cfg["kapazitaet"]
    jetzt = int(time.time())
    vorher = m.get("laedt")
    if laedt in (0, 1):
        if vorher != 1 and laedt == 1:
            # Ladevorgang beginnt. Damit endet gleichzeitig ein Fahrabschnitt.
            if soc is not None:
                m["l_start"] = jetzt
                m["l_soc"] = soc
                m["l_km"] = km
            # Verbrauch aus dem abgeschlossenen Fahrabschnitt.
            fs = m.get("f_soc")
            fk = m.get("f_km")
            if (kap > 0 and fs is not None and fk is not None
                    and soc is not None and km is not None):
                dkm = km - fk
                dsoc = fs - soc
                # Unter 20 km ist die Zahl Rauschen: der Ladezustand wird in
                # ganzen Prozent gemeldet, ein Prozent sind bei 60 kWh schon
                # 0,6 kWh.
                if dkm >= 20 and dsoc > 0:
                    m["verbrauch"] = round(dsoc / 100.0 * kap / dkm * 100.0, 1)
                    m["verbrauch_km"] = int(dkm)
            m.pop("f_soc", None)
            m.pop("f_km", None)
        if vorher == 1 and laedt == 0:
            # Ladevorgang endet.
            start = m.get("l_start")
            ssoc = m.get("l_soc")
            if start and ssoc is not None and soc is not None:
                dauer = int(round((jetzt - int(start)) / 60.0))
                kwh = round((soc - ssoc) / 100.0 * kap, 2) if kap > 0 and soc > ssoc \
                    else None
                ladung_anhaengen({
                    "fahrzeug": nummer, "start": int(start), "ende": jetzt,
                    "dauer": dauer, "soc_start": ssoc, "soc_ende": soc,
                    "km": km, "kwh": kwh}, cfg["verlauf_tage"])
                if kwh is not None:
                    m["ladekwh"] = kwh
                _LOG.info("Fahrzeug %s: Ladevorgang beendet, %d Minuten, %s -> %s %%%s",
                          nummer, dauer, ssoc, soc,
                          "" if kwh is None else (", %.2f kWh" % kwh))
            m.pop("l_start", None)
            m.pop("l_soc", None)
            m.pop("l_km", None)
            # Ein neuer Fahrabschnitt beginnt.
            if soc is not None and km is not None:
                m["f_soc"] = soc
                m["f_km"] = km
        m["laedt"] = laedt

    d["VERBRAUCH"] = m.get("verbrauch")
    d["LADEKWH"] = m.get("ladekwh")


# ---------------------------------------------------------------------------
# Verlauf (Ladezustand ueber den Tag)
# ---------------------------------------------------------------------------
def verlauf_anhaengen(nummer: int, stand, reichweite, tage: int) -> None:
    if stand is None:
        return
    ordner = PDATA / "verlauf"
    try:
        ordner.mkdir(parents=True, exist_ok=True)
    except OSError:
        return
    datei = ordner / ("fahrzeug%d_%s.csv" % (nummer, time.strftime("%Y%m%d")))
    marke = PDATA / (".verlauf_ts_%d" % nummer)
    letzte = 0
    try:
        letzte = int(marke.read_text())
    except (OSError, ValueError):
        pass
    if time.time() - letzte < 240:
        return
    try:
        with datei.open("a", encoding="utf-8") as f:
            f.write("%d;%s;%s\n" % (int(time.time()), stand,
                                    reichweite if reichweite is not None else ""))
        marke.write_text(str(int(time.time())))
    except OSError:
        return
    grenze = time.time() - tage * 86400
    for alt in ordner.glob("fahrzeug*_*.csv"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


# ---------------------------------------------------------------------------
# Schreibbefehle aus der Warteschlange
#
# Der Loxone-Endpunkt legt hier eine JSON-Datei ab, der Dienst arbeitet sie ab
# und legt die Antwort daneben. Der Endpunkt selbst spricht NIE mit BYD.
# ---------------------------------------------------------------------------
def antwort_schreiben(kennung: str, ok: int, meldung: str, zusatz: dict | None = None) -> None:
    try:
        ORDNER_ANTWORTEN.mkdir(parents=True, exist_ok=True)
    except OSError:
        return
    d = {"ok": ok, "meldung": meldung, "ts": int(time.time())}
    if zusatz:
        d.update(zusatz)
    json_schreiben(ORDNER_ANTWORTEN / ("%s.json" % kennung), d)
    grenze = time.time() - 900
    for alt in ORDNER_ANTWORTEN.glob("*.json"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


def vorklimatisierung(horcher: Horcher, cfg: dict, merker: dict):
    """Ist es Zeit fuer die Vorklimatisierung? Rueckgabe: Befehl oder None.

    Gelesen wird die Abfahrtszeit des Abfahrtsassistenten aus dem Broker.
    GEMESSEN an dessen Quelltext (abfahrt_lib.php:1814 und :1685-1698):
    er veroeffentlicht unter dem eingestellten Praefix - ab Werk "abfahrt" -
    unter anderem

        ABFAHRT_IN   Minuten bis zur ABFAHRT (nicht bis zum Termin);
                     9999 heisst "kein Termin", negative Werte heissen
                     "der Abfahrtszeitpunkt ist vorbei"
        OK           1 = die Werte gelten
        MINSTART     Minuten bis zum TERMINBEGINN - bewusst NICHT benutzt

    Warum ein Zustandsautomat und keine einfache Schwelle: der Assistent sendet
    bei jeder Aenderung, also im Minutentakt. Eine reine Schwelle wuerde
    zwanzig Minuten lang zwanzig Befehle absetzen. Es wird deshalb genau
    einmal je Abfahrt ausgeloest und erst wieder scharf gestellt, wenn die
    Abfahrt vorbei oder weit genug entfernt ist.
    """
    if not cfg.get("abfahrt_ein"):
        return None
    p = str(cfg.get("abfahrt_praefix") or "abfahrt").strip("/")
    if p == "":
        return None
    grenze = ganz(cfg.get("abfahrt_alter"), 300)
    ok_roh, ok_alter = horcher.wert(p + "/OK", grenze)
    ai_roh, ai_alter = horcher.wert(p + "/ABFAHRT_IN", grenze)
    m = merker.setdefault("abfahrt", {})

    if ok_roh is None or ai_roh is None:
        melde_gebremst("abfahrt_still",
                       "Vorklimatisierung: zum Thema %s/ABFAHRT_IN liegt kein frischer "
                       "Wert vor (Alter OK %s s, ABFAHRT_IN %s s). Es wird nichts "
                       "ausgeloest. Ist das Abo im Broker vorhanden und laeuft der "
                       "Abfahrtsassistent?" % (p, ok_alter, ai_alter), 3600)
        return None
    if ganz(ok_roh, 0) != 1:
        m["scharf"] = 1              # der Assistent meldet einen Fehler
        return None
    ai = zahl(ai_roh)
    if ai is None:
        return None
    ai = int(ai)
    vorlauf = ganz(cfg.get("abfahrt_vorlauf"), 20)
    # Kein Termin, oder der Abfahrtszeitpunkt ist vorbei: wieder scharf.
    if ai >= 9999 or ai < 0 or ai > vorlauf + 5:
        m["scharf"] = 1
        return None
    if not m.get("scharf", 1):
        return None
    m["scharf"] = 0
    m["letzte"] = int(time.time())
    m["letzte_ai"] = ai
    if not cfg.get("steuerung_ein"):
        # Nicht heimlich absetzen: der Befehl wuerde abgewiesen, und der
        # Anwender saehe nur, dass nichts passiert.
        _LOG.warning("Vorklimatisierung: Abfahrt in %d Minuten, aber schreibende "
                     "Befehle sind gesperrt - es wurde nichts abgesetzt.", ai)
        return None
    nr = str(max(1, ganz(cfg.get("abfahrt_fahrzeug"), 1)))
    _LOG.info("Vorklimatisierung: Abfahrt in %d Minuten (Vorlauf %d), Fahrzeug %s, "
              "Zieltemperatur %d Grad.", ai, vorlauf, nr, cfg["abfahrt_temp"])
    return {"aktion": "klima_start", "fahrzeug": nr, "temp": cfg["abfahrt_temp"],
            "anlass": "abfahrt"}


def vin_waehlen(fahrzeuge: dict, schluessel) -> str:
    """Nimmt entweder die laufende Nummer (1-basiert) oder die VIN."""
    s = str(schluessel or "1").strip()
    for f in fahrzeuge.values():
        if str(f.get("vin", "")).upper() == s.upper() and s != "":
            return str(f.get("vin"))
    f = fahrzeuge.get(s)
    return str(f.get("vin", "")) if isinstance(f, dict) else ""


async def befehl_ausfuehren(client, fahrzeuge: dict, cfg: dict, z: dict,
                            b: dict, freigeschaltet: set) -> tuple[int, str, dict]:
    """Rueckgabe: (ok, Meldung, Zusatzfelder). ok = 1 angenommen, 0 abgelehnt.

    Was "angenommen" heisst: die Bibliothek hat den Auftrag bei BYD abgesetzt
    und BYD hat ihn entgegengenommen. Ob das Fahrzeug ihn ausfuehrt, zeigt erst
    der naechste Abruf - das steht auch so in jeder Antwort. Die Quittung ist
    nicht die Wirkung.
    """
    aktion = str(b.get("aktion") or "")
    # TROCKENLAUF
    #
    # Er ist ein Parameter DIESER Funktion und keine zweite, die den Vorgang
    # beschreibt: zwei Stellen, die dasselbe erzeugen, laufen auseinander, und
    # dann zeigt die Vorschau etwas anderes an, als der Ernstfall tut. Der
    # normale Weg wird also gegangen, alle Wachen greifen echt, nur das Senden
    # unterbleibt.
    #
    # Und der Bericht uebernimmt NICHT den Wortlaut des Ernstfalls. Eine Probe,
    # die "ausgefuehrt" meldet, waehrend nichts ausgefuehrt wurde, ist eine
    # stille Falschaussage.
    probe = bool(b.get("probe"))
    vorsatz = "PROBE - es wurde NICHTS gesendet. " if probe else ""
    hemmnisse = []

    if aktion == "abruf":
        if probe:
            return (1, vorsatz + "Ein Sofortabruf wuerde eingeplant.", {})
        return (1, "Sofortabruf eingeplant.", {})

    if aktion not in BEFEHLE:
        return (0, "Unbekannte Aktion '%s'." % aktion, {})

    # Im Trockenlauf werden die Wachen GEPRUEFT, aber sie brechen nicht ab -
    # gerade wenn die Steuerung noch gesperrt ist, will man wissen, was ein
    # Befehl taete. Was greifen wuerde, steht dann im Bericht.
    if not cfg.get("steuerung_ein"):
        if not probe:
            return (0, "Die Steuerung ist ausgeschaltet. Reiter Einstellungen, Haken "
                       "'Schreibende Befehle zulassen'.", {})
        hemmnisse.append("die Steuerung ist ausgeschaltet - der echte Befehl wuerde "
                         "abgewiesen")
    if not fahrzeuge:
        return (0, "Es ist noch kein Fahrzeug bekannt. Erst einen Abruf abwarten.", {})
    if client is None:
        if not probe:
            return (0, "Die Verbindung zur BYD-Schnittstelle steht nicht. Siehe Reiter "
                       "Logdateien.", {})
        hemmnisse.append("die Verbindung zur BYD-Schnittstelle steht nicht")

    vin = vin_waehlen(fahrzeuge, b.get("fahrzeug"))
    if vin == "":
        return (0, "Fahrzeug '%s' gibt es nicht. Bekannt sind %d Fahrzeuge."
                   % (b.get("fahrzeug"), len(fahrzeuge)), {})

    eig = BEFEHLE[aktion]
    # Im Trockenlauf ohne Verbindung gibt es kein Client-Objekt; dann wird die
    # KLASSE gefragt. Das beantwortet dieselbe Frage - bietet die installierte
    # Fassung diese Methode an? - ohne eine Verbindung zu brauchen.
    ziel = client
    if ziel is None and probe:
        try:
            from pybyd import BydClient  # noqa: PLC0415
            ziel = BydClient
        except Exception:  # noqa: BLE001
            ziel = None
    f, gefunden = methode_finden(ziel, eig["methoden"]) if ziel is not None else (None, "")
    if f is None:
        if probe:
            hemmnisse.append("die installierte Fassung von pybyd bietet '%s' nicht an "
                             "(gesucht: %s)" % (aktion, ", ".join(eig["methoden"])))
        else:
            return (0, "Die installierte Fassung von pybyd bietet '%s' nicht an "
                       "(gesucht: %s). Der Befehl wird abgewiesen, statt etwas anderes "
                       "zu tun." % (aktion, ", ".join(eig["methoden"])), {})

    # Die Steuer-PIN wird EINMAL je Fahrzeug freigeschaltet. Ohne sie weist
    # BYD jeden schreibenden Befehl ab; ohne diese Zeile sucht der Anwender den
    # Fehler beim Befehl statt bei der PIN.
    if eig.get("pin"):
        if z.get("pin", "") == "":
            if not probe:
                return (0, "Fuer schreibende Befehle verlangt BYD die Steuer-PIN des "
                           "Kontos. Sie ist nicht hinterlegt - Reiter Einstellungen.", {})
            hemmnisse.append("es ist keine Steuer-PIN hinterlegt - BYD wuerde den "
                             "Befehl abweisen")
        if probe:
            pass                     # keine Freischaltung im Trockenlauf
        elif vin not in freigeschaltet:
            pruef, name = methode_finden(client, ("verify_command_access",
                                                  "verify_control_password"))
            if pruef is None:
                return (0, "Die installierte Fassung von pybyd bietet keine Freischaltung "
                           "fuer schreibende Befehle an (gesucht: verify_command_access).",
                        {})
            try:
                erg = pruef(vin)
                if inspect.isawaitable(erg):
                    erg = await erg
            except Exception as err:  # noqa: BLE001
                return (0, "Die Steuer-PIN wurde nicht angenommen (%s): %s"
                           % (name, fehlertext(err)), {})
            freigeschaltet.add(vin)

    zusatz: dict = {}
    if aktion in ("klima_start", "klima_plan"):
        t = zahl(b.get("temp"))
        if t is None:
            return (0, "Die Zieltemperatur fehlt oder ist keine Zahl.", {})
        lo, hi = cfg["temp_min"], cfg["temp_max"]
        if t < lo or t > hi:
            # Abweisen, nicht zurechtbiegen: ein still gekappter Sollwert
            # fuehrt zu einem Fahrzeug, das etwas anderes tut als angezeigt.
            return (0, "Zieltemperatur %s Grad liegt ausserhalb der eingestellten Grenzen "
                       "(%s bis %s Grad). Grenzen im Reiter Einstellungen anpassen."
                       % (t, lo, hi), {})
        zusatz["temperature"] = t
        m = zahl(b.get("minuten"))
        if m is not None:
            if m < 1 or m > 60:
                return (0, "Die Laufzeit %s Minuten ist unzulaessig; erlaubt sind 1 bis 60."
                        % m, {})
            zusatz["time_span"] = int(m)
    if aktion in ("sitzklima", "batterieheizung"):
        s = b.get("stufe")
        if s is not None and s != "":
            n = zahl(s)
            if n is None:
                return (0, "Die Stufe ist keine Zahl.", {})
            zusatz["level"] = int(n)
        else:
            zusatz["enable"] = 1 if b.get("ein", 1) else 0

    if probe:
        # Hier endet der Trockenlauf: gegangen ist er denselben Weg, gesendet
        # hat er nichts. Was der Ernstfall aufrufen wuerde, steht im Klartext -
        # samt der Angaben, die die Bibliothek gar nicht annehmen kann.
        namen = []
        if f is not None:
            try:
                sig = inspect.signature(f)
                namen = [p for p in sig.parameters if p != "self"]
            except (TypeError, ValueError):
                namen = []
        unterkommt = {}
        fehlt_probe = []
        for k, v in zusatz.items():
            ziel = ""
            for kandidat in (k,) + ZUSATZNAMEN.get(k, ()):
                if normal(kandidat) in {normal(n) for n in namen}:
                    ziel = kandidat
                    break
            if ziel:
                unterkommt[ziel] = v
            else:
                fehlt_probe.append("%s=%s" % (k, v))
        text = vorsatz + ("Der echte Befehl wuerde %s aufrufen: %s(%r%s)."
                          % (aktion, gefunden or "<keine Methode gefunden>", vin,
                             "".join(", %s=%r" % (k, v) for k, v in sorted(unterkommt.items()))))
        if fehlt_probe:
            text += (" NICHT uebergeben werden koennte: %s - die installierte Fassung "
                     "von pybyd kennt dafuer keinen Parameter." % ", ".join(sorted(fehlt_probe)))
        if hemmnisse:
            text += " Es wuerde aber greifen: " + "; ".join(hemmnisse) + "."
        else:
            text += " Es steht ihm nichts entgegen."
        return (1, text, {"vin": vin, "methode": gefunden, "probe": 1})

    try:
        erg, fehlt = await methode_rufen(f, vin, zusatz)
    except Exception as err:  # noqa: BLE001
        return (0, fehlertext(err), {"vin": vin, "methode": gefunden})

    nachsatz = (" BYD hat den Auftrag angenommen; ob das Fahrzeug ihn ausfuehrt, zeigt "
                "der naechste Abruf.")
    if fehlt:
        # Eine Angabe, die nicht ankommt, wird GENANNT. Sonst steht in der
        # Oberflaeche eine Zieltemperatur, die das Fahrzeug nie erfahren hat.
        nachsatz += (" Nicht uebergeben werden konnte: %s - die installierte Fassung "
                     "von pybyd kennt dafuer keinen Parameter." % ", ".join(sorted(fehlt)))
    text = "%s ausgefuehrt (%s)." % (aktion, gefunden)
    if isinstance(erg, (str, int, float)):
        text += " Antwort: %s." % mqtt_wert_saeubern(erg)
    return (1, text + nachsatz, {"vin": vin, "methode": gefunden})


async def warteschlange(client, fahrzeuge: dict, cfg: dict, z: dict,
                        freigeschaltet: set) -> bool:
    """Arbeitet alle vorliegenden Befehle ab. True, wenn ein Sofortabruf
    angefordert wurde."""
    try:
        ORDNER_BEFEHLE.mkdir(parents=True, exist_ok=True)
    except OSError:
        return False
    sofort = False
    for datei in sorted(ORDNER_BEFEHLE.glob("*.json")):
        b = json_lesen(datei)
        kennung = datei.stem
        try:
            datei.unlink()
        except OSError:
            pass
        if not b:
            antwort_schreiben(kennung, 0, "Befehlsdatei war leer oder unlesbar.")
            continue
        try:
            ok, meldung, zusatz = await befehl_ausfuehren(
                client, fahrzeuge, cfg, z, b, freigeschaltet)
        except Exception as err:  # noqa: BLE001 - jeder Fehler gehoert gemeldet
            ok, meldung, zusatz = 0, fehlertext(err), {}
        antwort_schreiben(kennung, ok, meldung, zusatz)
        _LOG.info("Befehl %s (%s): ok=%s %s", kennung, b.get("aktion"), ok, meldung)
        if b.get("aktion") == "abruf" and ok:
            sofort = True
    return sofort


# ---------------------------------------------------------------------------
# Abbild schreiben
# ---------------------------------------------------------------------------
def abbild_schreiben(stand: dict, cfg: dict, ok: int, fehler: str = "") -> dict:
    """Schreibt den Zwischenspeicher.

    Bei einem fehlgeschlagenen Abruf bleiben die zuletzt gueltigen Werte
    stehen, und der Zeitstempel wird NICHT aufgefrischt. Beides mit Absicht:
    sonst meldete der Endpunkt ploetzlich FAHRZEUG_UNBEKANNT, obwohl nur eine
    Anfrage schiefging - und ALTER bliebe klein, woran aber die
    Ausfallerkennung in Loxone haengt. Der Zeitstempel gehoert zur MESSUNG,
    nicht zum Schreibvorgang.
    """
    fahrzeuge = stand.get("fahrzeuge") or {}
    lox = {
        "ok": ok,
        "fehler": fehler,
        "letzter_versuch": int(time.time()),
        "anzahl_fahrzeuge": len(fahrzeuge),
        "fahrzeuge": fahrzeuge,
    }
    if stand.get("ts"):
        lox["ts"] = int(stand["ts"])
    json_schreiben(DATEI_LOXONE, lox, 0o600)
    json_schreiben(DATEI_CACHE, {"ts": int(stand.get("ts") or 0), "ok": ok,
                                 "fehler": fehler, "fahrzeuge": fahrzeuge}, 0o600)

    praefix = str(cfg.get("mqtt_topic") or "byd").strip("/") or "byd"

    if ok:
        for nummer, f in fahrzeuge.items():
            try:
                verlauf_anhaengen(int(nummer), f.get("SOC"), f.get("REICHW"),
                                  cfg["verlauf_tage"])
            except (TypeError, ValueError):
                pass

    if not cfg.get("mqtt_ein"):
        return lox

    # Bei JEDEM Durchlauf gehen ok und ts hinaus, auch bei einer Stoerung.
    #
    # ts statt ALTER: ueber MQTT ist das Alter beim Senden immer null. Wer die
    # beiden Wege gleich behandeln will, veroeffentlicht den Zeitstempel und
    # laesst die Gegenseite rechnen. Ohne ihn ist ein toter Dienst von einem
    # gesunden nicht zu unterscheiden - es wird schlicht nichts mehr gesendet,
    # und die letzten Werte stehen weiter im Broker.
    paare: dict = {"ok": ok, "ts": int(stand.get("ts") or 0),
                   "fahrzeuge": len(fahrzeuge)}
    if ok:
        for nummer, f in fahrzeuge.items():
            for feld in list(FELDER) + list(ABGELEITET):
                paare["fahrzeug%s/%s" % (nummer, feld)] = f.get(feld)
    versucht, schlecht = mqtt_senden(paare, praefix)
    lox["mqtt_versucht"] = versucht
    lox["mqtt_gescheitert"] = schlecht
    return lox


def zustand_schreiben(**felder) -> None:
    z = json_lesen(DATEI_ZUSTAND)
    z.update(felder)
    z["ts"] = int(time.time())
    json_schreiben(DATEI_ZUSTAND, z)


# ---------------------------------------------------------------------------
# Ein Abruf
# ---------------------------------------------------------------------------
async def einmal_abrufen(client, cfg: dict) -> tuple[dict, str, dict]:
    """Rueckgabe: (Fahrzeuge, Fehlertext, Rohdaten)."""
    roh_alle: dict = {}
    liste = await client.get_vehicles()
    if not liste:
        return ({}, "Das BYD-Konto fuehrt kein Fahrzeug.", roh_alle)

    fahrzeuge: dict = {}
    # Nach VIN sortieren, damit die laufenden Nummern stabil bleiben. Eine
    # Nummer, die aus einer Aufzaehlung entsteht, ist keine Adresse: fiele ein
    # Fahrzeug weg, rueckte jedes nachfolgende um eins vor - und der virtuelle
    # Eingang in Loxone zeigte still auf ein anderes Auto.
    def vin_von(f) -> str:
        d = flach(zu_dict(f))
        w, _ = hole(d, ("vin",))
        return str(w or "")

    for i, fz in enumerate(sorted(liste, key=vin_von), start=1):
        stamm = flach(zu_dict(fz))
        vin = str(hole(stamm, ("vin",))[0] or "")
        echtzeit: dict = {}
        gps: dict = {}
        fehler_teil: list[str] = []
        if vin:
            try:
                echtzeit = flach(zu_dict(await client.get_vehicle_realtime(vin)))
            except Exception as err:  # noqa: BLE001 - ein Abschnitt darf ausfallen
                fehler_teil.append("Echtzeitwerte: " + fehlertext(err))
            if cfg.get("gps_ein"):
                try:
                    gps = flach(zu_dict(await client.get_gps_info(vin)))
                except Exception as err:  # noqa: BLE001
                    fehler_teil.append("Standort: " + fehlertext(err))
        abbild = fahrzeug_abbilden(stamm, echtzeit, gps)
        if fehler_teil:
            abbild["ausfaelle"] = fehler_teil
            melde_gebremst("abschnitt_%s" % vin, "; ".join(fehler_teil), 900)
        fahrzeuge[str(i)] = abbild
        roh_alle[str(i)] = {"stamm": stamm, "echtzeit": echtzeit, "gps": gps}
    return (fahrzeuge, "", roh_alle)


class Zeitgrenze:
    """Bricht einen haengenden Aufruf nach $sekunden ab.

    Der Wecker (signal.alarm) wirkt auch dort, wo eine Zeitgrenze der
    Bibliothek nicht greift. Er geht nur im Hauptstrang - dienst() laeuft
    dort, und SIGALRM ist sonst unbenutzt; belegt sind nur SIGTERM und SIGINT.
    """

    def __init__(self, sekunden: int, was: str = "Abruf"):
        self.sekunden = int(sekunden)
        self.was = was
        self.alt = None

    def __enter__(self):
        if self.sekunden > 0 and hasattr(signal, "SIGALRM"):
            self.alt = signal.signal(signal.SIGALRM, self._schlagen)
            signal.alarm(self.sekunden)
        return self

    def _schlagen(self, *_):
        raise TimeoutError("%s hat laenger als %d s gebraucht - abgebrochen."
                           % (self.was, self.sekunden))

    def __exit__(self, *_):
        if self.alt is not None:
            signal.alarm(0)
            signal.signal(signal.SIGALRM, self.alt)
            self.alt = None
        return False


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------
def signal_behandeln(*_):
    global _LAUF
    _LAUF = False
    _LOG.info("Beendigungssignal erhalten - Dienst haelt an.")


async def dienst_lauf(einmal: bool) -> int:
    from pybyd import BydClient  # noqa: PLC0415

    cfg = config()
    z = zugang()
    if not z["benutzer"] or not z["passwort"]:
        _LOG.error("Zugangsdaten fehlen. Reiter Einstellungen der Plugin-Oberflaeche "
                   "oeffnen.")
        zustand_schreiben(ok=0, fehler="Zugangsdaten fehlen.")
        return 1

    try:
        konf, bericht = konfiguration_bauen(z, cfg)
    except Exception as err:  # noqa: BLE001
        meldung = fehlertext(err)
        _LOG.error("Die Bibliothek liess sich nicht einrichten: %s", meldung)
        zustand_schreiben(ok=0, fehler=meldung)
        return 1
    _LOG.info("Dienst startet (Takt %s s, Steuerung %s, MQTT %s).",
              cfg["intervall"], "ein" if cfg.get("steuerung_ein") else "aus",
              "ein" if cfg.get("mqtt_ein") else "aus")
    for k, v in sorted(bericht.items()):
        _LOG.info("Konfiguration: %s -> %s", k, v)

    stand: dict = {"ts": 0, "fahrzeuge": {}}
    fehler_folge = 0
    freigeschaltet: set = set()
    horcher = Horcher()
    merker = merker_lesen()

    client = None
    try:
        async with BydClient(konf) as client:
            while _LAUF:
                cfg = config()   # Aenderungen aus der Oberflaeche ohne Neustart
                # Abos nachziehen, nicht die Verbindung neu aufbauen: ein
                # Neuaufbau kostet die bereits empfangenen zurueckbehaltenen
                # Werte.
                horcher.sicherstellen(horcher_themen(cfg))
                ok = 0
                fehler = ""
                fahrzeuge: dict = {}
                try:
                    with Zeitgrenze(GRENZE_ABRUF, "Der Abruf bei BYD"):
                        fahrzeuge, fehler, roh = await einmal_abrufen(client, cfg)
                    if roh:
                        json_schreiben(DATEI_ROH, {"ts": int(time.time()), "roh": roh},
                                       0o600)
                    ok = 1 if fahrzeuge and any(f.get("ok") for f in fahrzeuge.values()) else 0
                    fehler_folge = 0 if ok else fehler_folge + 1
                except Exception as err:  # noqa: BLE001
                    fehler = fehlertext(err)
                    fehler_folge += 1
                    melde_gebremst("abruf", "Abruf fehlgeschlagen: " + fehler, 900)

                # Die gerechneten Groessen erst NACH dem Abruf und nur auf
                # frischen Werten: sie leiten aus SOC und Kilometerstand ab, und
                # aus alten Zahlen entstuende ein Verbrauch, der aussieht wie
                # gemessen.
                empfehlung = ladeempfehlung(horcher, cfg)
                if ok and fahrzeuge:
                    for nummer, fz in fahrzeuge.items():
                        try:
                            abgeleitetes_ergaenzen(nummer, fz, cfg, merker,
                                                   fehler_folge, empfehlung)
                        except Exception as err:  # noqa: BLE001
                            _LOG.error("Gerechnete Groessen, Fahrzeug %s: %s",
                                       nummer, fehlertext(err))
                    merker_schreiben(merker)
                    stand = {"ts": int(time.time()), "fahrzeuge": fahrzeuge}
                else:
                    # Bei einer Stoerung bleiben die Messwerte stehen - aber der
                    # Stoerungszaehler und die Ladeempfehlung gelten JETZT und
                    # werden fortgeschrieben. Sonst meldete Loxone bei einem
                    # Ausfall unveraendert FEHLFOLGE=0.
                    for fz in (stand.get("fahrzeuge") or {}).values():
                        fz["FEHLFOLGE"] = int(fehler_folge)
                        fz["LADEEMPF"] = empfehlung
                abbild_schreiben(stand, cfg, ok, fehler)
                zustand_schreiben(ok=ok, fehler=fehler, fehler_folge=fehler_folge,
                                  pid=os.getpid(), intervall=cfg["intervall"],
                                  anzahl_fahrzeuge=len(stand["fahrzeuge"]),
                                  konfiguration=bericht,
                                  horcher=sorted(horcher.themen),
                                  horcher_verbunden=1 if horcher.verbunden else 0,
                                  horcher_fehler=horcher.fehler)

                # Vorklimatisierung. Sie steht NACH dem Abbild, damit die
                # Oberflaeche den Stand schon zeigt, und VOR der Wartezeit,
                # damit sie nicht einen Takt zu spaet kommt.
                try:
                    auftrag = vorklimatisierung(horcher, cfg, merker)
                    if auftrag:
                        ok2, meldung, _z = await befehl_ausfuehren(
                            client, stand["fahrzeuge"], cfg, z, auftrag, freigeschaltet)
                        _LOG.info("Vorklimatisierung: ok=%s %s", ok2, meldung)
                        merker_schreiben(merker)
                except Exception as err:  # noqa: BLE001
                    _LOG.error("Vorklimatisierung: %s", fehlertext(err))

                if einmal:
                    return 0 if ok else 1

                rest = cfg["intervall"]
                if fehler_folge >= 3:
                    rest = min(3600, cfg["intervall"] * min(8, fehler_folge))
                    melde_gebremst("bremse",
                                   "%d Fehlversuche - naechster Abruf erst in %d s."
                                   % (fehler_folge, rest), 1800)
                while rest > 0 and _LAUF:
                    try:
                        if await warteschlange(client, stand["fahrzeuge"], cfg, z,
                                               freigeschaltet):
                            break     # Sofortabruf angefordert
                    except Exception as err:  # noqa: BLE001
                        _LOG.error("Warteschlange: %s", fehlertext(err))
                    await asyncio.sleep(1)
                    rest -= 1
    except Exception as err:  # noqa: BLE001
        meldung = fehlertext(err)
        _LOG.error("Dienst abgebrochen: %s", meldung)
        zustand_schreiben(ok=0, fehler=meldung)
        return 1
    finally:
        # Jeder Fehlerweg schliesst zu. Ein Horcher, der an einem
        # Abbruch haengen bleibt, haelt eine Verbindung zum Broker offen -
        # eine Ressource, die niemand zaehlt.
        horcher.schliessen()
        merker_schreiben(merker)
    _LOG.info("Dienst beendet.")
    return 0


# ---------------------------------------------------------------------------
# --felder: was liefert die Bibliothek wirklich?
#
# Die Frage "was soll ich hier eintragen" beantwortet kein Hilfetext, sondern
# die Antwort des Geraets selbst. Diese Betriebsart holt sie und listet jedes
# Blatt mit seinem Pfad auf - samt der Angabe, welches Feld der Feldtabelle
# darauf getroffen hat.
# ---------------------------------------------------------------------------
async def felder_zeigen() -> int:
    from pybyd import BydClient  # noqa: PLC0415

    cfg = config()
    z = zugang()
    if not z["benutzer"] or not z["passwort"]:
        print("[FEHL] Es sind keine Zugangsdaten hinterlegt.")
        return 1
    konf, bericht = konfiguration_bauen(z, cfg)
    for k, v in sorted(bericht.items()):
        print("[INFO] Konfiguration: %-9s -> %s" % (k, v))
    async with BydClient(konf) as client:
        fahrzeuge, fehler, roh = await einmal_abrufen(client, cfg)
    if fehler:
        print("[INFO] " + fehler)
    if not roh:
        print("[FEHL] Es kam keine Antwort - es gibt nichts zu zeigen.")
        return 1

    # Welcher Pfad ist welchem Feld zugeordnet?
    for nummer, teile in sorted(roh.items()):
        print("")
        print("=== Fahrzeug %s" % nummer)
        zuordnung = (fahrzeuge.get(nummer) or {}).get("getroffen") or {}
        rueck = {}
        for feld, treffer in zuordnung.items():
            rueck.setdefault(treffer, []).append(feld)
        for abschnitt in ("stamm", "echtzeit", "gps"):
            d = teile.get(abschnitt) or {}
            print("--- %s (%d Blaetter)" % (abschnitt, len(d)))
            for k in sorted(d):
                zu = rueck.get(k)
                print("    %-40s = %-24s %s"
                      % (k, mqtt_wert_saeubern(d[k])[:24],
                         ("-> " + ", ".join(zu)) if zu else ""))
        offen = (fahrzeuge.get(nummer) or {}).get("offen") or []
        if offen:
            print("--- Ohne Treffer geblieben: %s" % ", ".join(offen))
            print("    Diese Felder bleiben LEER. Wer den richtigen Namen oben findet,")
            print("    traegt ihn in FELDER in bin/byd.py als Kandidaten nach.")
    return 0


# ---------------------------------------------------------------------------
# Selbsttest - beantwortet ohne Netz und ohne Loxone, ob die Einrichtung traegt
# ---------------------------------------------------------------------------
def selbsttest() -> int:
    zeilen = []
    fehler = 0

    # Zuerst: WO arbeitet dieser Lauf? Ein Prueflauf, der das nicht sagt,
    # laesst offen, ob er ueberhaupt den richtigen Baum angesehen hat - und
    # eine falsche Pfadableitung sieht in jeder anderen Zeile wie ein
    # Rechteproblem aus.
    zeilen.append("[INFO] Wurzel %s (%s), Pluginordner %s"
                  % (LBHOME, LBHOME_QUELLE, PNAME))
    if "keine LoxBerry-Wurzel" in LBHOME_QUELLE:
        zeilen.append("[INFO] Das Plugin liegt offenbar als entpacktes Archiv da und ist "
                      "nicht installiert. Die folgenden Pfadzeilen sagen dann nichts "
                      "ueber eine Installation.")

    v = sys.version_info
    if v >= (3, 11):
        zeilen.append("[OK]   Python %d.%d.%d (pybyd verlangt 3.11 oder neuer)"
                      % (v.major, v.minor, v.micro))
    else:
        fehler += 1
        zeilen.append("[FEHL] Python %d.%d.%d ist zu alt - pybyd verlangt 3.11 oder "
                      "neuer. Auf LoxBerry 3.0.0 (Debian 11) gibt es nur 3.9; dieses "
                      "Plugin setzt deshalb LB_MINIMUM=3.0.1." % (v.major, v.minor, v.micro))

    venv = SELF / "venv" / "bin" / "python3"
    zeilen.append("[%s Virtuelle Umgebung: %s" % ("OK]  " if venv.exists() else "FEHL]", venv))
    if not venv.exists():
        fehler += 1

    import importlib.metadata as md  # noqa: PLC0415
    fassung = ""
    try:
        import pybyd  # noqa: PLC0415,F401
        try:
            fassung = md.version("pybyd")
        except Exception:  # noqa: BLE001
            fassung = "unbekannt"
        zeilen.append("[OK]   Bibliothek pybyd geladen, Fassung %s" % fassung)
    except Exception as err:  # noqa: BLE001
        fehler += 1
        zeilen.append("[FEHL] Bibliothek pybyd laesst sich nicht laden: %s" % err)

    # Welche Konfigurationsfelder und welche Befehle kennt die installierte
    # Fassung? Das ist die wichtigste Zeile dieses Selbsttests: pybyd ist
    # Alpha, und eine geratene Schreibweise faellt sonst erst am Fahrzeug auf.
    if fassung:
        try:
            from pybyd import BydClient, BydConfig  # noqa: PLC0415
            felder = sorted(bekannte_felder(BydConfig).values())
            zeilen.append("[INFO] BydConfig kennt %d Felder: %s"
                          % (len(felder), ", ".join(felder) if felder else "keine erkannt"))
            fehlend = []
            for aktion, eig in sorted(BEFEHLE.items()):
                if not any(hasattr(BydClient, m) for m in eig["methoden"]):
                    fehlend.append(aktion)
            if fehlend:
                zeilen.append("[INFO] Diese Befehle bietet die installierte Fassung NICHT "
                              "an und werden abgewiesen: %s" % ", ".join(fehlend))
            else:
                zeilen.append("[OK]   Alle %d Befehle sind in der installierten Fassung "
                              "vorhanden" % len(BEFEHLE))
        except Exception as err:  # noqa: BLE001
            zeilen.append("[INFO] Die Felder der Bibliothek liessen sich nicht erfragen: %s"
                          % err)

    for name, pfad in (("Konfiguration", PCONFIG), ("Daten", PDATA), ("Log", PLOG)):
        schreibbar = os.access(pfad, os.W_OK) if pfad.exists() else False
        zeilen.append("[%s Ordner %s beschreibbar: %s"
                      % ("OK]  " if schreibbar else "FEHL]", name, pfad))
        if not schreibbar:
            fehler += 1

    z = zugang()
    # Ein Pruefknopf darf die FORM eines Geheimnisses beurteilen, nie seinen Wert zeigen.
    if z["benutzer"]:
        zeilen.append("[OK]   BYD-Benutzername hinterlegt (%d Zeichen)" % len(z["benutzer"]))
    else:
        fehler += 1
        zeilen.append("[FEHL] Kein Benutzername hinterlegt")
    if z["passwort"]:
        zeilen.append("[OK]   Passwort hinterlegt (%d Zeichen, Inhalt wird nicht angezeigt)"
                      % len(z["passwort"]))
    else:
        fehler += 1
        zeilen.append("[FEHL] Kein Passwort hinterlegt")
    if z["pin"]:
        if z["pin"].isdigit():
            zeilen.append("[OK]   Steuer-PIN hinterlegt (%d Ziffern)" % len(z["pin"]))
        else:
            fehler += 1
            zeilen.append("[FEHL] Die Steuer-PIN enthaelt Zeichen, die keine Ziffern sind")
    else:
        zeilen.append("[INFO] Keine Steuer-PIN hinterlegt - ohne sie weist BYD jeden "
                      "schreibenden Befehl ab. Fuer das reine Ablesen braucht es sie nicht.")

    try:
        rechte = DATEI_ZUGANG.stat().st_mode & 0o777
        passt = (rechte & 0o077) == 0
        zeilen.append("[%s Rechte Zugangsdatei: %s (erwartet 0o600)"
                      % ("OK]  " if passt else "FEHL]", oct(rechte)))
        if not passt:
            fehler += 1
    except OSError:
        fehler += 1
        zeilen.append("[FEHL] Zugangsdatei fehlt: %s" % DATEI_ZUGANG)

    c = config()
    zeilen.append("[INFO] Takt %d s (eigene Untergrenze: %d s, nicht von BYD)"
                  % (c["intervall"], TAKT_MIN))
    zeilen.append("[INFO] Schreibende Befehle: %s, Zieltemperatur erlaubt von %d bis %d Grad"
                  % ("zugelassen" if c.get("steuerung_ein") else "gesperrt",
                     c["temp_min"], c["temp_max"]))

    # Vorgaben gegen die PHP-Seite halten. Zwei Vorgabelisten laufen
    # auseinander, wenn niemand sie gegeneinander haelt - der Kommentar
    # "muss zur anderen passen" ist eine Hoffnung.
    lib = LBHOME / "webfrontend" / "html" / "plugins" / PNAME / "by_lib.php"
    if not lib.is_file():
        lib = SELF.parents[2] / "webfrontend" / "html" / "by_lib.php" \
            if len(SELF.parents) >= 3 else lib
    if lib.is_file():
        try:
            text = lib.read_text(encoding="utf-8", errors="replace")
            import re  # noqa: PLC0415
            teil = text.split("function by_vorgaben", 1)
            gefunden = set()
            if len(teil) == 2:
                for m in re.finditer(r"'([a-z_]+)'\s*=>", teil[1].split("}", 1)[0]):
                    gefunden.add(m.group(1))
            nur_py = sorted(set(VORGABEN) - gefunden)
            if gefunden and nur_py:
                fehler += 1
                zeilen.append("[FEHL] Vorgaben nur im Dienst, nicht in by_vorgaben(): %s"
                              % ", ".join(nur_py))
            elif gefunden:
                zeilen.append("[OK]   Alle %d Vorgaben des Dienstes stehen auch in "
                              "by_vorgaben()" % len(VORGABEN))
            else:
                zeilen.append("[INFO] by_vorgaben() liess sich nicht auslesen - "
                              "Vergleich nicht gemacht")
        except OSError as err:
            zeilen.append("[INFO] by_lib.php nicht lesbar (%s)" % err)
    else:
        zeilen.append("[INFO] by_lib.php nicht gefunden - Vergleich der Vorgaben nicht "
                      "gemacht")

    # ---- die neuen Funktionen ----
    h = Horcher()
    moeglich, grund = h.moeglich()
    if moeglich:
        zeilen.append("[OK]   Das Modul paho-mqtt ist vorhanden - fremde Themen sind "
                      "lesbar")
    else:
        zeilen.append("[INFO] " + grund)
    b = mqtt_broker()
    if b["benutzer"]:
        zeilen.append("[OK]   Broker fuer den Horcher: %s:%d, Anmeldung als %s"
                      % (b["host"], b["port"], b["benutzer"]))
    else:
        zeilen.append("[INFO] Broker fuer den Horcher: %s:%d, es ist KEIN Benutzer "
                      "hinterlegt. Der Broker des LoxBerry verlangt ab Werk eine "
                      "Anmeldung; ohne sie kommt keine Nachricht an, und die Meldung "
                      "lautet dann 'nicht erreichbar' statt 'abgelehnt'."
                      % (b["host"], b["port"]))
    themen = horcher_themen(c)
    zu = json_lesen(DATEI_ZUSTAND)
    if not themen:
        zeilen.append("[INFO] Keine fremden Themen abonniert (Vorklimatisierung und "
                      "Ladeempfehlung sind aus)")
    else:
        zeilen.append("[%s Abonnierte Themen: %s%s"
                      % ("OK]  " if zu.get("horcher_verbunden") else "INFO]",
                         ", ".join(sorted(themen)),
                         "" if zu.get("horcher_verbunden")
                         else " - Verbindung zum Broker steht (noch) nicht"))
        if zu.get("horcher_fehler"):
            fehler += 1
            zeilen.append("[FEHL] Horcher: %s" % zu.get("horcher_fehler"))
    if c.get("abfahrt_ein"):
        zeilen.append("[INFO] Vorklimatisierung: Praefix %s, Vorlauf %d min, "
                      "Zieltemperatur %d Grad, Fahrzeug %s%s"
                      % (c.get("abfahrt_praefix"), c["abfahrt_vorlauf"],
                         c["abfahrt_temp"], c.get("abfahrt_fahrzeug"),
                         "" if c.get("steuerung_ein")
                         else " - ABER schreibende Befehle sind gesperrt, es wird "
                              "nichts ausgeloest"))
    else:
        zeilen.append("[INFO] Vorklimatisierung ist ausgeschaltet")
    if c.get("ladeempf_ein"):
        zeilen.append("[INFO] Ladeempfehlung: Thema %s, Grenze %s, Bedingung %s, "
                      "Hoechstalter %d s"
                      % (c.get("ladeempf_thema") or "(keines)", c.get("ladeempf_grenze"),
                         "kleiner oder gleich" if c.get("ladeempf_unter")
                         else "groesser oder gleich", c["ladeempf_alter"]))
    else:
        zeilen.append("[INFO] Ladeempfehlung ist ausgeschaltet")
    if c["kapazitaet"] > 0:
        zeilen.append("[OK]   Batteriekapazitaet %d kWh hinterlegt - Verbrauch und "
                      "geladene Menge werden gerechnet" % c["kapazitaet"])
    else:
        zeilen.append("[INFO] Keine Batteriekapazitaet hinterlegt. Verbrauch und "
                      "geladene Menge bleiben LEER - sie werden nicht geraten.")
    if zahl(c.get("heim_breite")) is not None and zahl(c.get("heim_laenge")) is not None:
        zeilen.append("[OK]   Heimatposition hinterlegt, Radius %d m" % c["heim_radius"])
    else:
        zeilen.append("[INFO] Keine Heimatposition hinterlegt - das Feld ZUHAUSE bleibt "
                      "leer")
    lad = PDATA / "verlauf" / "ladungen.csv"
    if lad.is_file():
        try:
            n = len([x for x in lad.read_text(encoding="utf-8").splitlines()
                     if x and not x.startswith("#")])
        except OSError:
            n = -1
        zeilen.append("[INFO] Aufgezeichnete Ladevorgaenge: %d" % n)
    else:
        zeilen.append("[INFO] Noch kein Ladevorgang aufgezeichnet. Er entsteht, wenn "
                      "das Plugin einen Wechsel von 'laedt' auf 'laedt nicht' sieht - "
                      "ein Ladevorgang, der kuerzer ist als ein Abruftakt, faellt "
                      "zwischen zwei Abrufe.")

    m = mqtt_zustand()
    if not m["gefunden"]:
        fehler += 1
        zeilen.append("[FEHL] Im general.json des LoxBerry ist kein MQTT-Abschnitt zu finden")
    elif m["autostart"]:
        zeilen.append("[OK]   MQTT-Gateway auf Autostart, Broker %s:%s, UDP-Eingang %d"
                      % (m["broker"], m["brokerport"], m["udpport"]))
    else:
        fehler += 1
        zeilen.append("[FEHL] Das MQTT-Gateway steht nicht auf Autostart "
                      "(System -> MQTT Gateway). Ohne das kommt am Miniserver nichts an.")

    lox = json_lesen(DATEI_LOXONE)
    if lox:
        alter = int(time.time()) - ganz(lox.get("ts"), 0)
        anzahl = ganz(lox.get("anzahl_fahrzeuge"), 0)
        zeilen.append("[INFO] Letzter erfolgreicher Abruf vor %d s, ok=%s, %d Fahrzeug(e)"
                      % (alter, lox.get("ok"), anzahl))
        # Jede Zeile, die ueber eine Menge urteilt, prueft zuerst, ob die Menge
        # leer ist. "Alle 0 von 0 aufgeloest" ist kein Haken.
        if anzahl == 0:
            zeilen.append("[INFO] Es ist kein Fahrzeug im Abbild - ueber die "
                          "Feldzuordnung sagt dieser Lauf deshalb nichts")
        else:
            for nummer, f in sorted((lox.get("fahrzeuge") or {}).items()):
                getroffen = f.get("getroffen") or {}
                offen = f.get("offen") or []
                zeilen.append("[%s Fahrzeug %s: %d von %d Feldern aufgeloest%s"
                              % ("OK]  " if not offen else "INFO]", nummer,
                                 len(FELDER) - len(offen), len(FELDER),
                                 (", ohne Treffer: " + ", ".join(offen)) if offen else ""))
                if getroffen:
                    zeilen.append("[INFO] Getroffene Namen: %s"
                                  % ", ".join("%s=%s" % (k, v)
                                              for k, v in sorted(getroffen.items())))
    else:
        zeilen.append("[INFO] Es hat noch kein Abruf stattgefunden")

    zeilen.append("")
    zeilen.append("Nicht geprueft, weil dafuer ein BYD-Konto und ein Fahrzeug noetig sind:")
    zeilen.append("  - ob die Anmeldung an der BYD-Schnittstelle gelingt")
    zeilen.append("  - ob die Feldnamen dieser Feldtabelle bei diesem Fahrzeug zutreffen")
    zeilen.append("    (sie stammen aus offenen Quellen, nicht aus einer Messung)")
    zeilen.append("  - ob die schreibenden Befehle am Fahrzeug die erwartete Wirkung haben")
    print("\n".join(zeilen))
    return 1 if fehler else 0


def main() -> int:
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    if "--felder" in sys.argv:
        try:
            return asyncio.run(felder_zeigen())
        except Exception as err:  # noqa: BLE001
            print("[FEHL] " + fehlertext(err))
            return 1
    if hasattr(signal, "SIGTERM"):
        signal.signal(signal.SIGTERM, signal_behandeln)
    signal.signal(signal.SIGINT, signal_behandeln)
    try:
        return asyncio.run(dienst_lauf("--einmal" in sys.argv))
    except KeyboardInterrupt:
        return 0
    except Exception as err:  # noqa: BLE001
        _LOG.error("Dienst abgebrochen: %s", fehlertext(err))
        zustand_schreiben(ok=0, fehler=fehlertext(err))
        return 1


if __name__ == "__main__":
    sys.exit(main())
