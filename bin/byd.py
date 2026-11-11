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
                           (braucht Zugangsdaten und Netz; laeuft der Dienst,
                           zeigt es dessen letzte Antwort)
    byd.py --mqtt-leeren [--praefix=<thema>]
                           raeumt die behaltenen eigenen Themen am Broker ab
Jeder andere Schalter endet mit Rueckgabe 2, ohne etwas zu tun.
"""

from __future__ import annotations

import asyncio
import hashlib
import inspect
import json
import logging
import math
import os
import re
import signal
import socket
import subprocess
import sys
import time
from logging.handlers import RotatingFileHandler
from pathlib import Path


def lb_wurzel_ermitteln() -> str:
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Eine feste Zahl von ".." ist nur
    die naechste Wette: LoxBerry legt Daemons als Symlink unter
    system/daemons/plugins ab, und dort stimmt sie nicht mehr.

    Zusaetzlich traegt der Kandidat config/system/general.json - ohne
    Ausnahme, auch nicht fuer die Lage <Wurzel>/bin/plugins/<ordner> (siehe
    _lbhome_ermitteln()). Ohne diese Bedingung fand die Suche jedes
    Verzeichnis mit config/plugins und webfrontend; auf einem Pruefrechner
    sind das Reste frueherer Pruefstaende (Regeln/06). Gemessen am 18.09.2026 (Pruefung-BYD-Autos-0.9.16, Fall H4):
    "--selbsttest" aus einem ausgepackten Archiv legte in einem solchen
    fremden Baum log/plugins/<archivname> an.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")) \
                and os.path.isfile(os.path.join(d, "config", "system", "general.json")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


def mqtt_thema_saeubern(roh: str) -> str:
    """Einen Themenpraefix aus der Oberflaeche unschaedlich machen.

    Der Wert wurde bisher nur an den Raendern von Schraegstrichen befreit.
    Das genuegt nicht: das Gateway liest "publish <thema> <wert>" zeilenweise
    und trennt mit einem LEERZEICHEN. Ein Praefix "mein haus" wird damit zum
    Thema "mein" mit dem Wert "haus/ok 1" - und das Plugin sendet ab da in ein
    Thema, das niemand abonniert hat, ohne jede Meldung. Ein Zeilenumbruch
    zerlegt die Uebertragung vollends.

    Ausserdem fallen # und + heraus: das sind die Jokerzeichen von MQTT und in
    einem Sendethema nicht zulaessig. Broker weisen solche Nachrichten ab -
    wieder still, denn ueber UDP kommt keine Antwort zurueck.

    Bleibt nichts uebrig, gilt "byd". Ein leeres Thema waere schlimmer als ein
    unerwuenschtes.
    """
    text = str(roh)
    for zeichen in ("\r\n", "\r", "\n", "\t", " "):
        text = text.replace(zeichen, "")
    for zeichen in ("#", "+"):
        text = text.replace(zeichen, "")
    # Mehrfache Schraegstriche zusammenziehen: "a//b" waere eine leere Ebene.
    while "//" in text:
        text = text.replace("//", "/")
    text = text.strip("/")
    return text or "byd"


def mqtt_wert_saeubern(wert) -> str:
    """Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.

    Das Gateway liest zeilenweise. Ein Zeilenumbruch im Wert zerlegt die
    Uebertragung, und aus den Bruchstuecken bildet das Gateway erfundene
    Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und Wert
    trennt.

    Wahrheitswerte werden zu 1 und 0. str(True) ergaebe "True", und ein
    virtueller Eingang in Loxone macht daraus 0 - der eingeschaltete Zustand
    kaeme als "aus" an. Die Abfrage steht VOR jeder Zahlbehandlung, weil bool
    in Python eine Unterart von int ist.

    Das ist eine ABSICHERUNG und kein behobener Fehler: nachgemessen wurde,
    dass heute kein einziges Feld einen Wahrheitswert fuehrt. LAEDT, KABEL und
    ZUHAUSE sind an ihren Rechenstellen bereits 1/0 (byd.py:1245, :1246,
    :1371). Die Zeile faengt den Tag ab, an dem ein neues Feld schlicht
    "wert == 1" zurueckgibt.
    """
    if isinstance(wert, bool):
        return "1" if wert else "0"
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


# ---------------------------------------------------------------------------
# Wurzel und Ordnername: GELESEN, nicht geraten.
#
# Bis 0.9.16 standen hier drei Quellen: $LBHOMEDIR, dann "drei Ebenen ueber
# dem Ablageort" - auch OHNE general.json, sobald dieses Skript dort unter
# bin/plugins/ lag -, dann die Suche, und hinter der Suche ein Rueckfall
# "neben dem Plugin". Beide Rueckfaelle nahmen der Suche ihre Wirkung: in
# einem fremden Baum ohne general.json wurde der Ablageort wieder zur Wurzel
# (Stand-Protokolle/2026-09-18_Welle1, "Neue Lehre fuer alle H1-Linien").
# In WSL gemessen am 24.09.2026 (Pruefung-BYD-Autos-0.9.17, Faelle Y1 und
# Y5): "--selbsttest" legte im fremden Baum log/plugins/bydautos an, aus
# einem Archiv ohne jede Wurzel log/plugins/<archivname> im Archiv selbst.
#
# Zwei Stufen, wie bin/dienst.sh und by_paths() in der Oberflaeche
# (Regeln/03, Regeln/06); Vorbild Skoda-Connect-NG 0.9.25:
#   1. $LBHOMEDIR, wenn es config/plugins und data/plugins traegt -
#      general.json wird dort nicht verlangt, die Pruefwerkzeuge setzen
#      LBHOMEDIR auf eine Attrappe;
#   2. aufwaerts suchen (lb_wurzel_ermitteln(), mit general.json).
# Eine dritte Stufe gibt es nicht. Ohne Wurzel: Meldung, Abbruch, nichts
# angelegt und nichts gelesen.
# ---------------------------------------------------------------------------
def _lbhome_ermitteln() -> tuple:
    umgebung = os.environ.get("LBHOMEDIR") or ""
    if umgebung and os.path.isdir(os.path.join(umgebung, "config", "plugins")) \
            and os.path.isdir(os.path.join(umgebung, "data", "plugins")):
        return (Path(umgebung).resolve(), "LBHOMEDIR")
    gesucht = lb_wurzel_ermitteln()
    if gesucht:
        return (Path(gesucht), "aufwaerts gesucht")
    sys.stderr.write(
        "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden. LBHOMEDIR "
        "bezeichnet keines, und oberhalb von %s traegt kein Verzeichnis "
        "config/plugins, webfrontend und config/system/general.json. Es wurde "
        "nichts angelegt und nichts gelesen.\n" % SELF)
    raise SystemExit(1)


LBHOME, LBHOME_QUELLE = _lbhome_ermitteln()
# Der Ordnername: $LBPPLUGINDIR, sonst der Ablageort. Bis 0.9.16 wurde aus
# "bin" (Archivlage) der Name des Archivordners; hiess der "bydautos" und lag
# er unter einer Wurzel mit dieser Installation, arbeitete ein Lauf aus dem
# Archiv mit deren Daten- und Protokollordner (in WSL gemessen 24.09.2026,
# Fall Y8). Jetzt bleibt es "bin", und INSTALLIERT sagt, ob hier eine Anlage
# ist.
PNAME = (os.environ.get("LBPPLUGINDIR") or "").rstrip("/").rsplit("/", 1)[-1] or SELF.name
# Liegt diese Datei im bin-Ordner der Anlage - oder nennt der Aufrufer ein
# dort eingerichtetes Plugin (so rufen uninstall/uninstall und die
# Pruefwerkzeuge, mit LBPPLUGINDIR)? Sonst ist es ein Aufruf aus einem
# ausgepackten Archiv oder einem Pruefordner: dann legt dieses Skript nichts
# an, startet keinen Dienst und leert keinen Broker (log_einrichten(),
# main()). Bis 0.9.16 nahm ein Archiv unter einer echten Wurzel deren Ordner
# (in WSL gemessen 24.09.2026, Faelle Y2, Y3, Y8). Bauart Skoda-Connect-NG
# 0.9.25.
INSTALLIERT = (SELF == (LBHOME / "bin" / "plugins" / PNAME).resolve()
               or (LBHOME / "config" / "plugins" / PNAME).is_dir())
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

# Lebenszeichen der Hauptschleife. Es liegt bei den Protokollen und NICHT im
# Datenordner: die Datei wird alle 30 s neu geschrieben, und das gehoert auf
# die Ramdisk und nicht auf die SD-Karte.
#
# Wozu: der Waechter im Cron fragte bisher nur, ob der PROZESS lebt (PID da,
# kill -0, richtige Befehlszeile). Das ist die erste Ebene der Dreiteilung aus
# REGELN_1 - ein Dienst, der in einem Aufruf haengt, erfuellt sie tadellos und
# liefert trotzdem nichts. Der Herzschlag beantwortet die zweite Frage:
# ARBEITET die Schleife noch?
#
# Nicht der Zeitstempel des Abrufs, denn der steht bei einer Stoerung mit
# Absicht still (Bremse bis zu einer Stunde). Der Herzschlag schlaegt auch
# waehrend der Wartezeit weiter - er misst die Schleife, nicht den Erfolg.
DATEI_HERZ = PLOG / "herzschlag"

# Muessen zu by_vorgaben() in webfrontend/html/by_lib.php passen. Der Reiter
# Test vergleicht beide Listen gegeneinander - ein Kommentar "muss zur anderen
# passen" ist eine Hoffnung, keine Pruefung.
VORGABEN = {
    "intervall": 300,
    "mqtt_ein": 0,
    "mqtt_topic": "byd",
    "steuerung_ein": 0,
    # Welle Bild 17 (0.9.26, Hausherr 17.10.2026): ZWEITER Haken fuer die eingreifenden Befehle
    # (verriegeln, entriegeln, suchen, blinken) - ab Werk AUS. Gleich in by_vorgaben().
    "gefahr_ein": 0,
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
    # --- Laden-1: Ladeempfehlung nach Strompreis aus Spotpreis aWATTar oder
    # Tibber (ab Werk AUS, Verbesserungsbau 30.09.2026). Praefix leer = der
    # Vorgabepraefix der gewaehlten Quelle (PREIS_QUELLEN).
    "preisempf_ein": 0,
    "preisempf_quelle": "awattar",
    "preisempf_praefix": "",
    "preisempf_stunden": 4,
    # --- gerechnete Groessen ---
    # Beide sind LEER, solange die Zutat fehlt. Eine Kapazitaet, die niemand
    # eingetragen hat, wird nicht geraten - und ohne sie gibt es keinen
    # Verbrauch in kWh.
    "kapazitaet": 0,
    "heim_breite": "",
    "heim_laenge": "",
    "heim_radius": 150,
    # --- Nr. 36 b (seit 0.9.23): Ansageanlaesse - dieselben Werte wie in by_vorgaben().
    # Der Block tts gehoert der Bruecke (bin/by_ansage.php).
    "ansage_laden_fertig": 1,
    "ansage_ausfall": 1,
    # --- Gruppe 42 (0.9.29): Hausform - dieselben Werte wie in by_vorgaben(), alles ab Werk aus ---
    "befehle_mqtt_ein": 0,
    "befehle_mqtt_steuern": 0,
    "endpunkt_ein": 0,
    # Haus-Themen (Regeln/07 Nr. 15) ueber die Dauerverbindung des Horchers.
    "haus_abfahrt_ein": 0,
    "vorklima_anwesend_ein": 0,
    "vorklima_wetter_ein": 0,
    "vorklima_kalt_unter": 5,
    "vorklima_heiss_ueber": 25,
    "ankunft_ein": 0,
    "ankunft_quelle": "",
    "haus_strompreis_ein": 0,
    # Zweite Quelle der Ladeempfehlung aus einem Thema: "thema" (wie bisher) oder "haus" (haus/strompreis/aktuell).
    # Laden-1 hat dafuer preisempf_quelle = "haus" (E9a).
    "ladeempf_quelle": "thema",
    # Meldungen an SignalBot (E10): Hauptschalter aus, je Ereignis abwaehlbar.
    "signal_ein": 0,
    "signal_praefix": "signalbot",
    "signal_laden_fertig": 1,
    "signal_ausfall": 1,
    "signal_vorklima": 1,
    # Sammelthemen und Kabel-Warnung (0.9.31, Regeln/07): dieselben Werte wie die PHP-Vorgaben.
    "haus_fahrzeug_ein": 1,
    "kabel_soc": 30,
    "kabel_uhr": "20:00",
    "kabel_abfahrt_min": 60,
    "signal_kabel": 1,
    "vorklima_min_soc": 20,
    "vorklima_nur_kabel": 0,
    "ansage_kabel_vergessen": 1,
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

# Drei weitere Fristen. Bisher hing nur der Abruf an einer Grenze - die
# Anmeldung, die Schreibbefehle und die PIN-Freischaltung hatten keine. Genau
# die drei sprechen aber mit derselben Gegenstelle, die schweigen kann, und
# sie liegen im selben Ablauf: haengt eine, steht der ganze Dienst, und mit
# ihm der naechste Abruf und die Warteschlange.
#
# Die Zahlen sind gesetzt, nicht gemessen: eine Anmeldung darf laenger dauern
# als ein Schaltbefehl, und die Freischaltung ist ein einzelner Aufruf. Wie
# lange die BYD-Gegenstelle wirklich braucht, ist an dieser Anlage nicht
# gemessen - es gibt kein Konto zum Messen.
GRENZE_ANMELDUNG = 120
GRENZE_BEFEHL = 90
GRENZE_FREIGABE = 60

# Deckel und Hoechstalter der Befehlswarteschlange.
#
# Bisher arbeitete der Dienst ab, was da lag - alles, und ohne aufs Alter zu
# sehen. Stand er eine Stunde still (Neustart, Netzausfall, haengende
# Gegenstelle), dann ging danach jeder in dieser Stunde angeklickte Befehl
# nacheinander ans Fahrzeug: die Klimaanlage, die um sieben Uhr gewollt war,
# lief um acht. Bei 90 s Frist je Befehl haelt schon ein Dutzend alter
# Auftraege den naechsten Abruf ueber zwanzig Minuten auf.
#
# Beide Zahlen sind gesetzt, nicht gemessen. 300 s deshalb, weil ein
# Schaltbefehl an ein Auto eine Absicht von JETZT ist; 20 deshalb, weil mehr
# als das in einem Takt ohnehin nicht abzuarbeiten waeren.
BEFEHLE_JE_LAUF = 20
BEFEHL_HOECHSTALTER = 300

# C3 (Durchgang 29.09.2026): Anmeldebremse. Bis 0.9.19 wurde eine abgewiesene
# Anmeldung nirgends vermerkt; der Dienst endete mit rc 1, und der Waechter
# startete ihn jede Minute neu - mit falschem Passwort bis zu 1 440
# Anmeldeversuche am Tag (in WSL gemessen, Code-Pruefer T3). Das ist der Weg
# in eine Kontosperre bei BYD, die auch die BYD-App trifft. Jetzt: hoechstens
# ANMELDE_GRENZE abgewiesene Anmeldungen in ANMELDE_FENSTER Sekunden, danach
# meldet sich das Plugin nicht mehr an, bis neue Zugangsdaten gespeichert
# sind (erkannt an der Pruefsumme von zugang.json). Die Zahlen sind gesetzt,
# nicht gemessen: ab wie vielen Fehlversuchen BYD sperrt, ist nicht bekannt.
ANMELDE_GRENZE = 5
ANMELDE_FENSTER = 86400
DATEI_ANMELDUNG = PDATA / "anmeldung.json"
# BYD-a1 (Verbesserungsbau 30.09.2026): die Sperre meldet sich einmal je
# Sperre im Benachrichtigungsbereich - ueber dieses PHP-Zwischenstueck, weil
# notify_ext() nur in der PHP- und Perl-Bibliothek des LoxBerry steht.
SKRIPT_MELDEN = SELF / "by_notify.php"
ANMELDESPERRE_TEXT = ("Die Anmeldung bei BYD wurde %d-mal innerhalb von 24 Stunden "
                      "abgewiesen. Das Plugin meldet sich nicht mehr an, bis im Reiter "
                      "Einstellungen neue Zugangsdaten gespeichert sind - so sperrt BYD "
                      "das Konto nicht." % ANMELDE_GRENZE)

# C5: PIN-Bremse und Obergrenze schaltender Befehle. Bis 0.9.19 wurde eine
# gescheiterte Freischaltung nicht vermerkt: 12 Befehle mit falscher PIN
# ergaben 12 Freischaltversuche in 8 s (gemessen, Code-Pruefer T5). Nach
# PIN_GRENZE Fehlschlaegen sind alle schaltenden Befehle PIN_SPERRE Sekunden
# gesperrt; eine neu gespeicherte PIN hebt die Sperre auf. Hoechstens
# BEFEHLE_JE_STUNDE schaltende Befehle je Stunde (Bauform AudiConnect 0.9.22).
# Gesetzt, nicht gemessen.
PIN_GRENZE = 3
PIN_SPERRE = 3600
BEFEHLE_JE_STUNDE = 30
DATEI_BEFEHLSBREMSE = PDATA / "befehlsbremse.json"

# C4: Zeitpunkt des letzten Echtzeitabrufs aus --felder (der Dienst fuehrt
# seinen in rohdaten.json).
DATEI_FELDER_ZEIT = PDATA / "felder_zeit"

# Klartext der Ablehnungsgruende beim Anmelden am Broker, fuer BEIDE
# Zaehlweisen: MQTT 3.1.1 (paho 1.x, Codes 1-5) und die Ursachencodes von
# MQTT 5, auf die paho 2.x dieselben Faelle abbildet (132-136). Am Geraet
# steht paho 2.1.0 (gemessen 11.09.2026) - bis 0.9.8 kannte der Horcher nur
# 4 und 5, und dort stand deshalb nur "Code 135" ohne Bedeutung.
CONNACK_KLARTEXT = {
    1: "Protokollfassung abgelehnt", 132: "Protokollfassung abgelehnt",
    2: "Client-Kennung abgelehnt", 133: "Client-Kennung abgelehnt",
    3: "Broker nicht verfuegbar", 136: "Broker nicht verfuegbar",
    4: "Benutzer oder Passwort falsch", 134: "Benutzer oder Passwort falsch",
    5: "nicht berechtigt", 135: "nicht berechtigt",
}

# M7 (Durchgang 29.09.2026): der Hinweis auf Brokeruser und Brokerpass gehoert
# nur zu den Codes, die eine abgelehnte ANMELDUNG bedeuten (4/5 bzw. 134/135).
# Bis 0.9.19 stand er auch bei "Broker nicht verfuegbar" und schickte den
# Anwender zu Zugangsdaten, die stimmen (gemessen, MQTT-Pruefer C3).
CONNACK_RAT = {
    1: "Der Broker spricht eine andere MQTT-Fassung als die paho-Fassung dieses Plugins.",
    132: "Der Broker spricht eine andere MQTT-Fassung als die paho-Fassung dieses Plugins.",
    2: "Der Broker lehnt die Kennung dieses Clients ab.",
    133: "Der Broker lehnt die Kennung dieses Clients ab.",
    3: "Der Broker ist gerade nicht verfuegbar; es wird spaeter erneut versucht.",
    136: "Der Broker ist gerade nicht verfuegbar; es wird spaeter erneut versucht.",
    4: "Stehen Brokeruser und Brokerpass in der general.json des LoxBerry?",
    134: "Stehen Brokeruser und Brokerpass in der general.json des LoxBerry?",
    5: "Stehen Brokeruser und Brokerpass in der general.json des LoxBerry?",
    135: "Stehen Brokeruser und Brokerpass in der general.json des LoxBerry?",
}

_LAUF = True
_LOG = logging.getLogger("bydautos")
_LETZTE_MELDUNG: dict[str, float] = {}
# Hat die Protokolldatei in diesem Lauf schon einmal existiert? Siehe
# melde_gebremst(): nur ihr VERSCHWINDEN heisst Neustart, ihr Nie-Dasein nicht.
_LOG_GESEHEN = False
_HERZ_ZULETZT = 0.0


def herzschlag(erzwingen: bool = False) -> None:
    """Lebenszeichen der Hauptschleife auffrischen - hoechstens alle 30 s.

    Die Bremse steckt hier drin, damit der Aufrufer die Funktion bedenkenlos
    in eine Sekundenschleife stellen kann. Begruendung bei DATEI_HERZ.

    Schlaegt das Schreiben fehl, wird geschwiegen: der Waechter faellt dann in
    "kein Urteil" zurueck, und ein Dienst, der nur seine Ramdisk nicht
    beschreiben kann, wird nicht deswegen neu gestartet.
    """
    global _HERZ_ZULETZT
    jetzt = time.time()
    if not erzwingen and jetzt - _HERZ_ZULETZT < 30:
        return
    _HERZ_ZULETZT = jetzt
    try:
        DATEI_HERZ.parent.mkdir(parents=True, exist_ok=True)
        DATEI_HERZ.write_text("%d\n" % int(jetzt), encoding="utf-8")
    except OSError:
        pass


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
#               (Stand 15.09.2026: die Felder unten, die 'bestand' tragen,
#               sind in der anonymisierten Rohausgabe eines BYD Seal U
#               Design vom 14.09.2026 aufgeloest worden (Issue #1).
#               Die Messung laeuft ueber hole() selbst und
#               laesst sich wiederholen:
#               Pruefung-BYD-Autos-0.9.11/rohdaten_messen.py.
#               'bestand' sagt: DIESER Schluesselname kam in einer echten
#               Antwort vor und trug einen Wert. Es sagt NICHT, was die
#               Zahl bedeutet - wo die Schnittstelle nur eine Kennzahl
#               liefert, steht das in der Bezeichnung und in der Hilfe.
#               Der Reiter Test zaehlt beide getrennt.)
#   zeile       1 = geht in die Statuszeile fuer Loxone, 0 = nur MQTT und JSON
#
# Ein Feld, das niemand gemessen hat, darf nicht aussehen wie eines, das
# jemand gemessen hat (Hausregel, BatterieBMS).
# ===========================================================================
FELDER = {
    "SOC": {
        "kandidaten": ("elec_percent", "elecPercent", "soc", "battery_percent"),
        "einheit": "%", "quelle": "bestand", "zeile": 1,
    },
    "KM": {
        "kandidaten": ("total_mileage", "totalMileage", "mileage", "odometer"),
        "einheit": "km", "quelle": "bestand", "zeile": 1,
    },
    "REICHW": {
        # endurance_mileage steht VORN - ERGAENZT 13.09.2026.
        #
        # Bis 0.9.9 fehlte dieser Name. Auf einem BYD Seal U Design blieb
        # REICHW deshalb leer: der Reiter Test meldete "21 von 23 Feldern
        # aufgeloest - REICHW, TEMPO", waehrend die Rohdaten derselben
        # Gegenstelle "enduranceMileage": 232 fuehrten. Gemeldet in Issue #1
        # (10.09.2026) samt Rohausgabe; die uebrigen vier Namen
        # bleiben stehen, weil sie fuer andere Modelle belegt sind.
        "kandidaten": ("endurance_mileage", "enduranceMileage", "ev_endurance",
                       "range", "remaining_range", "elec_mileage", "electric_range",
                       "range_detail_list.0.range"),
        "einheit": "km", "quelle": "bestand", "zeile": 1,
    },
    "TEMPO": {
        "kandidaten": ("speed",),
        "einheit": "km/h", "quelle": "bestand", "zeile": 1,
    },
    "LADEZUST": {
        # Der Rohwert, nicht umgerechnet. Bedeutung siehe LADEN/KABEL unten.
        "kandidaten": ("charge_state", "chargeState"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "FAHRZUST": {
        "kandidaten": ("vehicle_state", "vehicleState"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "ONLINE": {
        "kandidaten": ("online_state", "onlineState"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "ZUENDUNG": {
        "kandidaten": ("engine_status", "engineStatus"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "SCHLOSSVL": {
        # BEZEICHNUNG WOERTLICH: die Schnittstelle fuehrt genau EIN Tuerschloss,
        # das der Fahrertuer (links vorn). Das ist NICHT "Fahrzeug verriegelt" -
        # ein Name, der beides verwischt, ist eine stille Falschaussage.
        "kandidaten": ("left_front_door_lock", "leftFrontDoorLock"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "BATTHEIZ": {
        "kandidaten": ("battery_heat_state", "batteryHeatState"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "SITZHEIZ": {
        "kandidaten": ("main_seat_heat_state", "mainSeatHeatState"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "BREITE": {
        "kandidaten": ("latitude", "lat"),
        "einheit": "", "quelle": "bestand", "zeile": 0,
    },
    "LAENGE": {
        "kandidaten": ("longitude", "lon", "lng"),
        "einheit": "", "quelle": "bestand", "zeile": 0,
    },
    # ---- Reifendruecke, NEU 13.09.2026 -----------------------------------
    # Die vier Namen stammen aus der Rohausgabe eines BYD Seal U Design
    # (Issue #1): "leftFrontTirepressure": 2.7. Beachte das
    # kleine p in "Tirepressure" - hole() vergleicht ohne Unterstriche und
    # ohne Gross-/Kleinschreibung, deshalb traegt beide Schreibweisen
    # dieselbe Zeile.
    #
    # Die EINHEIT steht in tire_press_unit (REIFENEH). Ausgegeben wird
    # IMMER bar: reifendruck_bar() rechnet in fahrzeug_abbilden() um
    # (0.9.28, Issue #1 - siehe REIFEN_FAKTOR unten). "bar" hier ist also
    # die Einheit der AUSGABE, nicht die der Antwort.
    "REIFENVL": {
        "kandidaten": ("left_front_tire_pressure", "leftFrontTirepressure"),
        "einheit": "bar", "quelle": "bestand", "zeile": 1,
    },
    "REIFENVR": {
        "kandidaten": ("right_front_tire_pressure", "rightFrontTirepressure"),
        "einheit": "bar", "quelle": "bestand", "zeile": 1,
    },
    "REIFENHL": {
        "kandidaten": ("left_rear_tire_pressure", "leftRearTirepressure"),
        "einheit": "bar", "quelle": "bestand", "zeile": 1,
    },
    "REIFENHR": {
        "kandidaten": ("right_rear_tire_pressure", "rightRearTirepressure"),
        "einheit": "bar", "quelle": "bestand", "zeile": 1,
    },
    # ---- Aus einer echten Antwort, NEU 15.09.2026 ------------------------
    # Alle Namen hier unten sind in der anonymisierten Rohausgabe eines BYD
    # Seal U Design vom 14.09.2026 aufgeloest worden (Issue #1).
    # Sie tragen deshalb 'bestand' - der Schluessel kam vor und trug einen
    # Wert.
    #
    # Was damit NICHT gesagt ist: was die Zahlen BEDEUTEN. Ein Tuerschloss
    # meldete 2, ein Fenster 1, die Fahrstufe 3; eine Tabelle dazu nennt die
    # Schnittstelle nicht. Deshalb heissen sie in der Oberflaeche
    # ausdruecklich "als Kennzahl der Schnittstelle" - genauso wie das
    # bestehende SCHLOSSVL. Wer sie in Loxone legt, haelt sie einmal gegen
    # das Fahrzeug.
    #
    # ANGEHAENGT, nicht eingefuegt: die Reihenfolge der Statuszeile ergibt
    # sich aus by_felder() in by_lib.php, und dort stehen sie ebenfalls am
    # Ende. Kein bestehendes Feld wandert.
    # Innenraumtemperatur. Messwert, deshalb nicht retained.
    "INNENTEMP": {
        "kandidaten": ("temp_in_car", "tempInCar"),
        "einheit": "\u00b0C", "quelle": "bestand", "zeile": 1,
    },
    # Fahrstufe als Kennzahl - die Schnittstelle nennt keine Tabelle dazu.
    "FAHRSTUFE": {
        "kandidaten": ("power_gear", "powerGear"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "SCHLOSSVR": {
        "kandidaten": ("right_front_door_lock", "rightFrontDoorLock"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "SCHLOSSHL": {
        "kandidaten": ("left_rear_door_lock", "leftRearDoorLock"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "SCHLOSSHR": {
        "kandidaten": ("right_rear_door_lock", "rightRearDoorLock"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "TUERVL": {
        "kandidaten": ("left_front_door", "leftFrontDoor"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "TUERVR": {
        "kandidaten": ("right_front_door", "rightFrontDoor"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "TUERHL": {
        "kandidaten": ("left_rear_door", "leftRearDoor"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "TUERHR": {
        "kandidaten": ("right_rear_door", "rightRearDoor"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "KOFFER": {
        "kandidaten": ("trunk_lid", "trunkLid"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "FENSTERVL": {
        "kandidaten": ("left_front_window", "leftFrontWindow"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "FENSTERVR": {
        "kandidaten": ("right_front_window", "rightFrontWindow"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "FENSTERHL": {
        "kandidaten": ("left_rear_window", "leftRearWindow"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "FENSTERHR": {
        "kandidaten": ("right_rear_window", "rightRearWindow"),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    "DACHFENSTER": {
        "kandidaten": ("skylight",),
        "einheit": "", "quelle": "bestand", "zeile": 1,
    },
    # Die Einheitenkennung der Reifendruecke. Sie steht NUR ueber MQTT und
    # aktion=json zur Verfuegung (zeile 0): in der Statuszeile waere sie
    # eine Zahl mehr, die niemand liest. Sie bleibt die ROHKENNUNG; die
    # vier Druckfelder rechnet reifendruck_bar() nach ihr in bar um
    # (0.9.28; Belege an REIFEN_FAKTOR).
    "REIFENEH": {
        "kandidaten": ("tire_press_unit", "tirePressUnit"),
        "einheit": "", "quelle": "bestand", "zeile": 0,
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
    # Der Durchschnittsverbrauch, den das FAHRZEUG selbst ausweist - NEU
    # 13.09.2026. Er steht hier unter ABGELEITET und nicht unter FELDER,
    # weil der Rohwert KEINE Zahl ist: "totalEnergy": "17.6kW\u00b7h/100km"
    # (Rohausgabe eines Seal U Design, Issue #1). zahl() gibt dafuer None
    # zurueck - float("17.6kW\u00b7h/100km") scheitert -, das Feld bliebe
    # als FELDER-Eintrag also dauerhaft leer. Herausgeloest wird die
    # fuehrende Zahl in fahrzeug_abbilden().
    #
    # VERBRAUCH daneben bleibt, was es war: der vom Plugin aus SOC und
    # Kilometerstand GERECHNETE Wert der letzten Fahrt. Zwei Zahlen, zwei
    # Herkuenfte - deshalb zwei Felder und kein Ueberschreiben.
    "VERBRBYD": {"einheit": "kWh/100km", "quelle": "bestand", "zeile": 1},
    # Der Durchschnittsverbrauch der letzten 50 km - NEU 15.09.2026.
    #
    # Die Bedeutung ist BELEGT, nicht geraten: der Melder hat den Wert in
    # der BYD-App nachgesehen ("durchschnittlicher Energieverbrauch der
    # letzten 50 gefahrenen km", Issue #1, 14.09.2026), und die Antwort
    # selbst nennt ihn beim Namen - derselbe Zahlenwert steht dort als
    # "recent_50km_energy": "15.4kW\u00b7h/100km", "recent_50km_energy_ev":
    # 15.4 mit "recent_50km_energy_ev_unit": "kWh/100km" und schlicht als
    # "energy_consumption": "15.4".
    #
    # Er steht hier unter ABGELEITET und nicht unter FELDER, weil die
    # Kandidaten sich in der FORM unterscheiden: teils Zahl, teils
    # Zeichenkette mit Zahl, teils Zeichenkette mit eingebauter Einheit.
    # zahl_vorn() nimmt alle drei; zahl() nur die ersten beiden.
    #
    # VERBRBYD daneben ist der KUMULIERTE Durchschnitt des Fahrzeugs
    # (17.5 kWh/100 km), VERBRAUCH der vom Plugin aus Ladezustand und
    # Kilometerstand gerechnete Wert der letzten Fahrt. Drei Zahlen, drei
    # Herkuenfte - deshalb drei Felder und kein Ueberschreiben.
    "VERBR50": {"einheit": "kWh/100km", "quelle": "bestand", "zeile": 1},
    # ---- Gruppe 42 (0.9.29), nur MQTT und JSON (zeile 0) ----
    # VORKLIMA: 0 nichts/aus, 1 gesendet, 2 mild, 3 wartet auf Wiederholung, 4 wartet auf Anwesenheit,
    #   -1 gescheitert - eine Aussage des Dienstes, fluechtig.
    # LADEKOSTEN (EUR) / LADEPREIS (ct/kWh): die letzte Ladung zu Hause, -1 ohne Aussage (nie geschaetzt).
    "VORKLIMA": {"einheit": "", "quelle": "gerechnet", "zeile": 0},
    "LADEKOSTEN": {"einheit": "EUR", "quelle": "gerechnet", "zeile": 0},
    "LADEPREIS": {"einheit": "ct/kWh", "quelle": "gerechnet", "zeile": 0},
}

# Welche Fahrzeugfelder gehen ZURUECKBEHALTEN (retain) hinaus?
#
# Hausstandard seit 03.09.2026 (Regeln/07): Zustaende retained, Messwerte mit
# Zeitbezug nicht, das Lebenszeichen nie. Bis 0.9.8 ging hier ALLES mit
# "publish" hinaus. Am Geraet belegt (Regeln/07, 06.09.2026): ein "publish"
# ueber den UDP-Eingang von Gateway V1 liegt danach NICHT retained im Broker,
# ein "retain" schon. Nach einem Neustart des Miniservers oder des Gateways
# standen damit alle Eingaenge bis zum naechsten Abruf leer - auch Schloss
# und Zuendung, die sich stundenlang nicht aendern.
#
# Retained: Lade-, Fahr- und Onlinezustand, Zuendung, Schloss, beide
# Heizungen, laedt/Kabel und Zuhause. ONLINE bleibt retained: online_state
# kommt aus der Cloud und ist ein Zustand des FAHRZEUGS, keine Aussage dieses
# Dienstes (Regeln/07, Prueffrage "wer stellt es fest?").
# NICHT retained, und warum:
#   FEHLFOLGE - der Zaehler erfolgloser Abrufe ist eine Aussage des DIENSTES
#     ueber sich selbst (Regeln/07, entschieden am 19.09.2026). Retained
#     blieb nach dem Ende des Dienstes "0 Fehler" fuer immer stehen; bis
#     0.9.16 ging er dazu gerade im Stoerungszweig retained hinaus. Der
#     Altwert wird einmal am Broker abgeraeumt (mqtt_altlast_abraeumen()).
#   SOC, KM, REICHW, TEMPO, RESTMIN, VERBRAUCH, LADEKWH, BREITE, LAENGE -
#     Messwerte mit Zeitbezug; ein alter Wert saehe nach einem Ausfall
#     aktuell aus.
#   LADEEMPF - haengt an einem Preis der Stunde; eine zurueckbehaltene 1
#     wirkte nach einem Neustart weiter, obwohl niemand mehr widerspricht.
#   OK - sagt, ob DIESER Abruf etwas gebracht hat; zurueckbehalten hiesse
#     es nach dem Tod des Dienstes fuer immer "ok".
#   ts und ok (Sammelthemen) - das Lebenszeichen; retained zeigte es immer
#     "lebt".
# Die Zahl der Fahrzeuge (Thema "fahrzeuge") ist ein Zustand und geht
# retained; das steht in abbild_schreiben().
#
# Gegenstueck in der Oberflaeche: by_mqtt_retain() in by_lib.php. Dass beide
# dasselbe sagen, prueft Pruefung-BYD-Autos-0.9.9/retain_themen.py.
RETAIN_FELDER = frozenset({
    "LADEZUST", "FAHRZUST", "ONLINE", "ZUENDUNG", "SCHLOSSVL", "BATTHEIZ",
    "SITZHEIZ", "LAEDT", "KABEL", "ZUHAUSE",
    # NEU 15.09.2026, nach derselben Regel: Zustaende ja, Messwerte nein.
    # Tueren, Schloesser, Fenster, Heckklappe, Schiebedach und die Fahrstufe
    # aendern sich selten und sollen einen Neustart des Miniservers
    # ueberstehen; die Einheitenkennung der Reifendruecke ist eine
    # Einstellung des Fahrzeugs und aendert sich fast nie.
    "SCHLOSSVR", "SCHLOSSHL", "SCHLOSSHR",
    "TUERVL", "TUERVR", "TUERHL", "TUERHR", "KOFFER",
    "FENSTERVL", "FENSTERVR", "FENSTERHL", "FENSTERHR", "DACHFENSTER",
    "FAHRSTUFE", "REIFENEH",
    # Gruppe 42: Kosten und Preis der letzten Ladung sind ein Zustand bis zur naechsten Ladung.
    "LADEKOSTEN", "LADEPREIS",
    # NICHT retained: INNENTEMP und VERBR50 - Messwerte mit Zeitbezug. Ein
    # alter Wert saehe nach einem Ausfall aktuell aus.
})

# Bedeutung von charge_state.
#
# Belegt aus dem ioBroker-Adapter derselben Schnittstelle (TA2k/ioBroker.byd):
#   0  nicht verbunden
#   1  laedt
#   15 Stecker steckt, laedt nicht
# Dort steht ausserdem, dass die Felder chargingState und connectState auf
# manchen Modellen dauerhaft -1 liefern und deshalb NICHT ausgewertet werden.
# Genau deshalb fragt dieses Plugin sie auch nicht ab.

# Gruppe 42, Nachtrag 29.10.2026 - die -1-Regel (Hausherr; Regeln/07 "MinVal=-1 fuer Felder, die -1 als nicht bekannt
# senden", Entscheidungen Gruppe 1-3 "ein Zahlenthema sendet bei fehlendem Wert -1"): ein MESSWERT (nicht retained),
# fuer den ein gelungener Abruf keine Aussage hat, geht ueber MQTT als -1 hinaus. Bis hier ging er gar nicht - in
# Loxone stand dann weiter der letzte Wert (oder 0 nach einem Neustart des Miniservers) und sah aus wie gemessen.
# Ausnahmen, je mit Grund: INNENTEMP -999 (-1 Grad ist eine echte Temperatur; Hausform wie haus/klima/.../temperatur
# und Robonect), BREITE und LAENGE gehen weiter nicht hinaus (-1 Grad ist ein echter Ort). Retained ZUSTAENDE ohne
# Aussage bleiben "-" (Entscheidung 5, mqtt_senden()). Nur MQTT: das Abbild (loxone.json) und damit die HTTP-Statuszeile
# und aktion=json bleiben byte-gleich (dort weiter ein Strich - Bestand der HTTP-Vorlagen).
# Welle Mehrfach-Ziele Los B, Zusatz "Ladeempfehlung ohne -1" (29.10.2026): die Ladeempfehlung ist ein Schaltsignal
# (1 = jetzt laden, 0 = nicht) - ohne Aussage geht 0 hinaus, nie -1 (in Loxone hiesse -1 "ungleich 0" = laden).
# Regel wie Tibber 0.9.33 "Schaltsignale ohne -1". Das Abbild (HTTP) bleibt unveraendert.
MQTT_FEHLWERT = {"INNENTEMP": -999, "BREITE": None, "LAENGE": None, "LADEEMPF": 0}


def mqtt_messwert(feld: str, wert):
    """Der Wert eines Fahrzeugthemas fuer MQTT nach der -1-Regel (rein)."""
    if feld in RETAIN_FELDER:
        return wert
    if wert is None or (isinstance(wert, str) and wert.strip() == ""):
        return MQTT_FEHLWERT.get(feld, -1)
    return wert


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
    # "eingreifend": braucht ZUSAETZLICH den zweiten Haken gefahr_ein (Welle Bild 17, 0.9.26) - wie
    # by_befehle() in by_lib.php.
    "verriegeln":      {"methoden": ("lock",),               "pin": 1, "eingreifend": 1},
    "entriegeln":      {"methoden": ("unlock",),             "pin": 1, "eingreifend": 1},
    "klima_start":     {"methoden": ("start_climate",),      "pin": 1},
    "klima_stop":      {"methoden": ("stop_climate",),       "pin": 1},
    "klima_plan":      {"methoden": ("schedule_climate",),   "pin": 1},
    "sitzklima":       {"methoden": ("set_seat_climate",),   "pin": 1},
    "batterieheizung": {"methoden": ("set_battery_heat",),   "pin": 1},
    "suchen":          {"methoden": ("find_car",),           "pin": 1, "eingreifend": 1},
    "blinken":         {"methoden": ("flash_lights",),       "pin": 1, "eingreifend": 1},
    "fenster_zu":      {"methoden": ("close_windows",),      "pin": 1},
}

class WachsameRotation(RotatingFileHandler):
    """Umlaufender Protokollhandler, der eine geloeschte Datei neu oeffnet.

    `log/plugins` liegt auf einer Ramdisk (zram). Wird sie geleert, raeumt
    LoxBerrys `log_maint` auf, oder loescht jemand die Datei von Hand, dann
    schreibt ein einmal geoeffneter Handler bis zum Prozessende in einen
    Inode, den es nicht mehr gibt - ohne Fehlermeldung, ohne Datei, ohne
    Hinweis. Am Geraet gemessen (06.09.2026, Python 3.13.5): FileHandler und
    RotatingFileHandler verlieren die Zeile, WatchedFileHandler nicht.

    Die Standardbibliothek hat den WatchedFileHandler, aber nicht zusammen
    mit dem Umlauf. Deshalb hier beides: vor jeder Zeile Geraetenummer und
    Inode vergleichen, bei Abweichung neu oeffnen, nach jedem Umlauf die
    Kennung nachfuehren.
    """

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._kennung = self._kennung_lesen()

    def _kennung_lesen(self):
        """(Geraetenummer, Inode) der Datei - None, wenn es sie nicht gibt."""
        try:
            s = os.stat(self.baseFilename)
        except OSError:
            return None
        return (s.st_dev, s.st_ino)

    def _nachfassen(self):
        """Neu oeffnen, wenn unter dem offenen Deskriptor eine andere (oder
        gar keine) Datei mehr liegt."""
        if self._kennung_lesen() == self._kennung:
            return
        if self.stream is not None:
            try:
                self.stream.flush()
            finally:
                self.stream.close()
                self.stream = None
        self.stream = self._open()
        self._kennung = self._kennung_lesen()

    def emit(self, record):
        try:
            self._nachfassen()
        except Exception:
            # Ein Fehlschlag beim Nachfassen darf die Zeile nicht kosten:
            # lieber in den alten Deskriptor schreiben als gar nicht.
            pass
        super().emit(record)

    def doRollover(self):
        super().doRollover()
        self._kennung = self._kennung_lesen()


# ---------------------------------------------------------------------------
# Protokollierung
#
# Ausschliesslich in die Datei. Das Startskript leitet die Ausgabe des Dienstes
# ohnehin in dieselbe Datei um - ein zweiter Kanal nach stdout schriebe jede
# Zeile doppelt hinein. Nur --selbsttest und --felder reden auf den Bildschirm,
# und die liest die Oberflaeche per exec() ein.
# ---------------------------------------------------------------------------
def log_einrichten() -> None:
    # Der mkdir stand ohne Wache da. Laesst sich der Protokollordner nicht
    # anlegen - falsche Rechte, volle Ramdisk, ein Pfad, der aus einer
    # verrutschten Wurzel abgeleitet wurde -, starb der Dienst hier mit einem
    # Traceback: bevor ein Protokoll eingerichtet war, also ohne jede Spur
    # ausser dem, was das Systemprotokoll zufaellig auffing. Genau der Fall,
    # fuer den der stderr-Rueckfall drei Zeilen weiter unten gedacht ist.
    # Angelegt wird nur in der Anlage (INSTALLIERT). Aus einem ausgepackten
    # Archiv unter einer echten Wurzel legte "--selbsttest" bis 0.9.16
    # log/plugins/<archivname> in der Anlage an (in WSL gemessen 24.09.2026,
    # Fall Y2); jetzt scheitert dort der Handler, und geschrieben wird nach
    # stderr.
    try:
        if INSTALLIERT:
            PLOG.mkdir(parents=True, exist_ok=True)
    except OSError as err:
        print("Protokollordner %s nicht anlegbar (%s) - es wird nach stderr "
              "geschrieben." % (PLOG, err), file=sys.stderr)
    _LOG.setLevel(logging.INFO)
    try:
        h: logging.Handler = WachsameRotation(
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

    Der Merker wird zurueckgesetzt, sobald die Protokolldatei VERSCHWINDET:
    nach einem Neustart liegt log/plugins auf einer Ramdisk und ist leer, und
    dann unterdrueckte die Bremse ausgerechnet die ERSTE Zeile.

    "Verschwindet" und nicht "fehlt" - das ist der Unterschied. Vorher wurde
    zurueckgesetzt, sooft die Datei nicht dalag. Entsteht sie NIE, weil sich
    der Protokollordner nicht anlegen liess und alles nach stderr geht, hiess
    das: bei jedem einzelnen Aufruf zuruecksetzen, die Bremse wirkte gar nicht,
    und eine Dauerstoerung schrieb im Sekundentakt. Also wird gemerkt, ob die
    Datei je da war; nur dann bedeutet ihr Fehlen einen Neustart.
    """
    global _LOG_GESEHEN
    jetzt = time.time()
    if DATEI_LOG.exists():
        _LOG_GESEHEN = True
    elif _LOG_GESEHEN:
        _LETZTE_MELDUNG.clear()
        _LOG_GESEHEN = False
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
    # Laden-1: dieselben Grenzen wie by_grenzen() und die Oberflaeche.
    c["preisempf_stunden"] = max(1, min(12, ganz(c.get("preisempf_stunden"), 4)))
    # Gruppe 42 (E9a): "haus" = Rang aus haus/strompreis.
    if c.get("preisempf_quelle") not in PREIS_QUELLEN and c.get("preisempf_quelle") != "haus":
        c["preisempf_quelle"] = "awattar"
    if c.get("ladeempf_quelle") not in ("thema", "haus"):
        c["ladeempf_quelle"] = "thema"
    c["vorklima_kalt_unter"] = max(-30, min(45, ganz(c.get("vorklima_kalt_unter"), 5)))
    c["vorklima_heiss_ueber"] = max(-30, min(45, ganz(c.get("vorklima_heiss_ueber"), 25)))
    # 0.9.31: Sammelthemen, Kabel-Warnung, Mindest-Akku. "0" waere in Python wahr, in PHP leer.
    for s in ("haus_fahrzeug_ein", "signal_kabel", "vorklima_nur_kabel", "ansage_kabel_vergessen"):
        c[s] = 1 if str(c.get(s)).strip() == "1" else 0
    c["kabel_soc"] = max(1, min(100, ganz(c.get("kabel_soc"), 30)))
    c["kabel_abfahrt_min"] = max(0, min(720, ganz(c.get("kabel_abfahrt_min"), 60)))
    c["vorklima_min_soc"] = max(0, min(100, ganz(c.get("vorklima_min_soc"), 20)))
    if c["vorklima_kalt_unter"] >= c["vorklima_heiss_ueber"]:
        c["vorklima_kalt_unter"], c["vorklima_heiss_ueber"] = 5, 25
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,40}", str(c.get("signal_praefix") or "")):
        c["signal_praefix"] = "signalbot"
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


def zugang_kennung() -> str:
    """Pruefsumme der Zugangsdatei, so wie sie auf der Karte liegt (sha256
    ueber die Bytes). Dieselbe Rechnung machen bin/dienst.sh
    (anmeldung_gesperrt) und by_lib.php (by_anmeldesperre): wird im Reiter
    Einstellungen etwas Neues gespeichert, aendert sich die Datei, und eine
    Anmeldesperre gilt nicht mehr. Der Inhalt selbst wird nirgends abgelegt."""
    try:
        return hashlib.sha256(DATEI_ZUGANG.read_bytes()).hexdigest()
    except OSError:
        return ""


def ist_anmeldefehler(err: BaseException) -> bool:
    """Wurde die ANMELDUNG abgewiesen - und nicht bloss das Netz gestoert?

    Erkannt an der Fehlerklasse, nicht am Text: ein Netz- oder Zeitfehler
    darf nicht zaehlen, sonst legte eine Stoerung beim Anbieter das Plugin
    still, bis jemand die Zugangsdaten neu speichert. Den Namen
    BydAuthenticationError fuehrt schon fehlertext(); an der echten
    Bibliothek ist er NICHT nachgemessen (kein BYD-Konto)."""
    for k in type(err).__mro__:
        if k.__name__.endswith("AuthenticationError"):
            return True
    return False


def anmeldefehler_klassen() -> list:
    """BYD-a2 (Verbesserungsbau 30.09.2026): welche Ausnahmeklassen der
    installierten pybyd enden auf 'AuthenticationError'? Nur daran erkennt
    ist_anmeldefehler() eine ABGEWIESENE Anmeldung. Ob die echte Bibliothek
    bei falschem Passwort so wirft, ist ohne Konto nicht belegt - dass sie
    eine solche Klasse ueberhaupt fuehrt, laesst sich aber ohne Netz sehen.
    Rueckgabe: sortierte Liste 'modul.Klasse'; leer = keine gefunden.
    Wirft nicht (pybyd fehlt -> leere Liste)."""
    import importlib  # noqa: PLC0415
    import importlib.util  # noqa: PLC0415
    try:
        paket = importlib.import_module("pybyd")
    except Exception:  # noqa: BLE001
        return []
    module = [paket]
    for name in ("exceptions", "errors", "exception", "error"):
        voll = "pybyd." + name
        try:
            if importlib.util.find_spec(voll) is not None:
                module.append(importlib.import_module(voll))
        except Exception:  # noqa: BLE001
            continue
    gefunden = set()
    for m in module:
        for n in dir(m):
            k = getattr(m, n, None)
            if (isinstance(k, type) and issubclass(k, BaseException)
                    and k.__name__.endswith("AuthenticationError")):
                gefunden.add("%s.%s" % (k.__module__, k.__name__))
    return sorted(gefunden)


def anmeldung_lesen() -> dict:
    """Stand der Anmeldebremse fuer die GELTENDEN Zugangsdaten.

    Rueckgabe {"fehl": [Zeitpunkte der letzten 24 h], "gesperrt": 0|1}. Gehoert
    der Vermerk zu anderen Zugangsdaten (Pruefsumme weicht ab), gilt er nicht.
    Einmal gesperrt bleibt gesperrt, bis neue Zugangsdaten gespeichert sind -
    auch nach Ablauf der 24 Stunden."""
    a = json_lesen(DATEI_ANMELDUNG)
    if not a or a.get("zugang") != zugang_kennung():
        return {"fehl": [], "gesperrt": 0, "gemeldet": 0}
    jetzt = time.time()
    fehl = [int(t) for t in (a.get("fehl") or [])
            if isinstance(t, (int, float)) and not isinstance(t, bool)
            and 0 <= jetzt - t < ANMELDE_FENSTER]
    # BYD-a1: wurde DIESE Sperre schon gemeldet? Der Vermerk gehoert zu den
    # Zugangsdaten wie die Sperre selbst - neue Zugangsdaten heben beides auf.
    return {"fehl": fehl, "gesperrt": 1 if a.get("gesperrt") else 0,
            "gemeldet": 1 if a.get("gemeldet") else 0}


def anmeldung_abgewiesen(text: str) -> bool:
    """Eine abgewiesene Anmeldung DAUERHAFT vermerken (Datenordner, 0600;
    ueberlebt Neustart und Waechter). True, wenn die Sperre jetzt gilt."""
    a = anmeldung_lesen()
    fehl = a["fehl"] + [int(time.time())]
    gesperrt = 1 if (a["gesperrt"] or len(fehl) >= ANMELDE_GRENZE) else 0
    gemeldet = a["gemeldet"]
    # BYD-a1: genau eine Benachrichtigung je Sperre. Gemeldet wird VOR dem
    # Schreiben, damit der Vermerk mit der Sperre in DERSELBEN Datei steht;
    # misslingt die Meldung, bleibt er aus (Protokollzeile in
    # anmeldesperre_melden()).
    if gesperrt and not gemeldet:
        gemeldet = 1 if anmeldesperre_melden() else 0
    json_schreiben(DATEI_ANMELDUNG, {"zugang": zugang_kennung(), "fehl": fehl,
                                     "gesperrt": gesperrt, "gemeldet": gemeldet,
                                     "grund": str(text)[:300]}, 0o600)
    if gesperrt:
        _LOG.error(ANMELDESPERRE_TEXT)
    else:
        _LOG.error("Anmeldung bei BYD abgewiesen (%d von hoechstens %d in 24 Stunden): %s",
                   len(fehl), ANMELDE_GRENZE, text)
    return bool(gesperrt)


def anmeldesperre_melden() -> bool:
    """BYD-a1: die Anmeldesperre in den Benachrichtigungsbereich des LoxBerry
    legen (bin/by_notify.php, Schwere 3 = Fehler). True = abgelegt.

    Scheitert es, ist das kein Fehler des Dienstes - es wird im Protokoll
    gesagt, und die Sperre gilt trotzdem. Der Text ist ALLG.ANMELDESPERRE der
    Sprachdatei, derselbe Satz wie oben im Reiter Einstellungen."""
    if not SKRIPT_MELDEN.is_file():
        _LOG.warning("Anmeldesperre: %s fehlt - es wurde keine Benachrichtigung "
                     "abgelegt.", SKRIPT_MELDEN)
        return False
    try:
        e = subprocess.run(["php", str(SKRIPT_MELDEN), "3", "ALLG.ANMELDESPERRE", PNAME,
                            str(ANMELDE_GRENZE)],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20,
                           check=False)
    except (OSError, subprocess.SubprocessError) as err:
        _LOG.warning("Anmeldesperre: die Benachrichtigung liess sich nicht ablegen "
                     "(%s).", err)
        return False
    if e.returncode != 0:
        _LOG.warning("Anmeldesperre: die Benachrichtigung wurde nicht abgelegt "
                     "(Rueckgabe %s): %s", e.returncode,
                     e.stderr.decode("utf-8", "replace").strip()[:300])
        return False
    _LOG.info("Anmeldesperre: im Benachrichtigungsbereich des LoxBerry gemeldet.")
    return True


def anmeldung_gelungen() -> None:
    """Eine angenommene Anmeldung loescht den Vermerk."""
    try:
        DATEI_ANMELDUNG.unlink()
    except OSError:
        pass


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


def mqtt_senden(paare: dict, praefix: str,
                retain=frozenset()) -> tuple[int, int]:
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
            ist_retain = k in retain
            text = None if v is None else mqtt_wert_saeubern(v)
            if text is None or text == "":
                # Seit dem Nachtrag 29.10.2026 kommt ein Fahrzeug-Messwert ohne Aussage aus
                # abbild_schreiben() schon als -1 an (mqtt_messwert()); None heisst hier nur noch
                # BREITE/LAENGE (Ausnahme) oder ein Thema ausserhalb der Fahrzeugfelder.
                if not ist_retain:
                    # Ein MESSWERT ohne Wert wird nicht gesendet - keine
                    # erfundene 0, und nie eine leere Nutzlast (das Gateway
                    # reicht sie als leeren Wert an den Miniserver weiter,
                    # Regeln/07).
                    continue
                # M1 (Entscheidung 5 vom 29.09.2026): ein ZUSTAND ohne
                # Aussage geht als "-" retained hinaus - nie als leere
                # Nutzlast und nie als stehenbleibender Altwert. Bis 0.9.19
                # ging fuer None nichts hinaus, und der alte retained Wert
                # (Tuer zu, Kabel steckt, zu Hause) blieb im Broker stehen
                # (gemessen, MQTT-Pruefer E2/E3).
                text = "-"
            versucht += 1
            try:
                # Gateway V1 kennt am UDP-Eingang "publish" und "retain"
                # (mqttgateway.pl, am Geraet nachgelesen, Regeln/07). Welche
                # Themen retained gehen, entscheidet der Aufrufer.
                befehl = "retain" if ist_retain else "publish"
                s.sendto(("%s %s/%s %s" % (befehl, praefix, k, text)
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


def bekannte_nummern(merker: dict) -> list:
    """Die Fahrzeugnummern, die der merker je vergeben hat (nummern_zuordnen()).
    Sie sind die Adressen in Loxone - auch fuer ein Fahrzeug, das gerade
    schweigt oder aus dem Konto verschwunden ist."""
    tab = merker.get("nummern") if isinstance(merker, dict) else None
    if not isinstance(tab, dict):
        return []
    aus = set()
    for v in tab.values():
        try:
            n = int(v)
        except (TypeError, ValueError):
            continue
        if 1 <= n <= 99:
            aus.add(n)
    return [str(n) for n in sorted(aus)]


def mqtt_ausfall_melden(cfg: dict, merker: dict) -> None:
    """M6 (Durchgang 29.09.2026): ohne Zugangsdaten, bei einer abgewiesenen
    Anmeldung und bei jedem Abbruch des Dienstes gehen ok 0 und je bekanntem
    Fahrzeug fahrzeugN/OK 0 hinaus - fluechtig, wie im Regelbetrieb. Bis
    0.9.19 ging in diesen Faellen nichts hinaus, und Loxone behielt das letzte
    OK=1 (gemessen, MQTT-Pruefer N). Der Zeitstempel ts wird dabei nicht
    aufgefrischt; die Zustaende bleiben stehen (Frage 11)."""
    if not cfg or not cfg.get("mqtt_ein"):
        return
    paare = {"ok": 0}
    for n in bekannte_nummern(merker):
        paare["fahrzeug%s/OK" % n] = 0
    # Laden-1: laeuft der Dienst nicht, rechnet niemand die Empfehlung - "-"
    # statt einer stehengebliebenen 1 (fluechtig, wie im Regelbetrieb).
    if cfg.get("preisempf_ein"):
        paare["lade_empfehlung"] = "-"
        paare["lade_empfehlung_grund"] = "DIENST_AUS"
    try:
        mqtt_senden(paare, mqtt_thema_saeubern(cfg.get("mqtt_topic") or "byd"))
    except Exception as err:  # noqa: BLE001
        melde_gebremst("mqtt_ausfall", "MQTT: ok 0 liess sich nicht senden (%s)."
                       % fehlertext(err))


def abodatei_nachfuehren(cfg: dict) -> None:
    """M5 (Durchgang 29.09.2026): config/plugins/<ordner>/mqtt_subscriptions.cfg
    traegt "<praefix>/#". Das MQTT-Gateway V1 liest diese Datei und abonniert
    daraus (Regeln/07, am Geraet belegt an Midea2Lox 4.5.4, 13.09.2026); ohne
    sie kam unter V1 ohne Handeintrag nichts am Miniserver an. Geschrieben wird
    beim Dienststart und beim Speichern im Reiter MQTT, nur wenn sie abweicht,
    mit einer Protokollzeile (Bauart Einspeisebremse 0.9.20)."""
    datei = PCONFIG / "mqtt_subscriptions.cfg"
    soll = "%s/#\n" % mqtt_thema_saeubern(cfg.get("mqtt_topic") or "byd")
    try:
        ist = datei.read_text(encoding="utf-8")
    except OSError:
        ist = None
    if ist == soll:
        return
    tmp = datei.with_name(datei.name + ".tmp." + str(os.getpid()))
    try:
        with tmp.open("w", encoding="utf-8") as f:
            try:
                os.chmod(tmp, 0o644)
            except OSError:
                pass
            geschrieben = f.write(soll)
        if geschrieben != len(soll):
            raise OSError("nur %d von %d Zeichen geschrieben" % (geschrieben, len(soll)))
        os.replace(tmp, datei)
        _LOG.info("MQTT: Abodatei %s auf '%s' gesetzt.", datei, soll.strip())
    except OSError as err:
        try:
            tmp.unlink()
        except OSError:
            pass
        melde_gebremst("abodatei", "MQTT: Abodatei %s liess sich nicht schreiben (%s)."
                       % (datei, err), 86400)


# ---------------------------------------------------------------------------
# Zurueckbehaltene Themen: Altwerte einmal am Broker abraeumen, bei der
# Deinstallation alles Eigene
#
# Welche Themen sendet dieses Plugin? MQTT_OBEN unter dem Praefix, dazu je
# Fahrzeug fahrzeugN/<Feld> fuer FELDER, ABGELEITET und OK (abbild_schreiben()).
# Nur diese erkennt mqtt_eigenes_thema() als eigen - und nur die raeumen der
# Dienst und die Deinstallation ab. Wer in abbild_schreiben() ein Thema
# dazunimmt, traegt es hier ein.
#
# Die ALTLAST: fahrzeugN/FEHLFOLGE ging von 0.9.9 bis 0.9.16 retained hinaus
# (RETAIN_FELDER, in den Archiven 0.9.8 bis 0.9.16 nachgelesen am
# 24.09.2026); die uebrigen fluechtigen Themen gingen nie retained. Ein
# fluechtiges publish ersetzt einen behaltenen Wert nicht - fort ist er erst,
# wenn eine LEERE Nutzlast mit retain auf dasselbe Thema faellt. Abgeraeumt
# wird am Broker, nicht ueber den UDP-Eingang: nur dort laesst sich
# nachlesen, ob es gewirkt hat (der UDP-Eingang verwirft unter Last
# Datagramme und bestaetigt nichts, Regeln/07). Bauart VolkswagenID 0.9.24
# mqtt_altlast_abraeumen(), Skoda-Connect-NG 0.9.25.
#
# Der Merker 'retain_altlast' steht in zustand.json im Datenordner. Den
# raeumt der Installer bei jedem Upgrade ab (purge_installation, Regeln/06);
# nach einem Upgrade wird deshalb einmal nachgesehen, ob noch etwas behalten
# liegt - geloescht wird dabei nur, was wirklich dasteht.
# ---------------------------------------------------------------------------
MQTT_OBEN = ("ok", "ts", "fahrzeuge", "lade_empfehlung", "lade_empfehlung_grund", "zaehler")
# Gruppe 42 (B5): zaehler ist das Lebenszeichen (0...999 je Durchgang, fluechtig) - die Ausfallerkennung im
# Musterprojekt haengt an <p>_zaehler (Gateway V2, Noncached). ok und ts bleiben (Regeln/07 :295).
# Laden-1: lade_empfehlung und lade_empfehlung_grund stehen hier, damit
# Themenliste (by_themen_dienst()), Abraeumen und Deinstallation sie als eigen
# kennen. Beide gehen FLUECHTIG hinaus (nicht in RETAIN_FELDER, nicht
# "fahrzeuge"): die Empfehlung haengt an der Stunde - eine zurueckbehaltene 1
# wirkte nach einem Neustart weiter (dieselbe Begruendung wie bei LADEEMPF).
RETAIN_ALTLAST_KENNUNG = "am-broker-nachgelesen"


def mqtt_eigenes_thema(praefix: str, thema: str) -> str:
    """Der Feldname eines EIGENEN Themas unter <praefix>/, sonst ''."""
    if not thema.startswith(praefix + "/"):
        return ""
    rest = thema[len(praefix) + 1:]
    if rest in MQTT_OBEN:
        return rest
    teile = rest.split("/")
    if len(teile) != 2 or not teile[0].startswith("fahrzeug"):
        return ""
    nummer = teile[0][len("fahrzeug"):]
    if not (nummer.isdigit() and 1 <= len(nummer) <= 2):
        return ""
    if teile[1] == "OK" or teile[1] in FELDER or teile[1] in ABGELEITET:
        return teile[1]
    return ""


def mqtt_fluechtig(feld: str) -> bool:
    """Geht dieses eigene Thema OHNE retain hinaus? Gegenstueck zur Menge
    retain in abbild_schreiben()."""
    return feld != "fahrzeuge" and feld not in RETAIN_FELDER


def _mqtt_fluechtige_namen() -> list:
    return sorted(n for n in list(MQTT_OBEN) + ["OK"] + list(FELDER) + list(ABGELEITET)
                  if mqtt_fluechtig(n))


def _broker_leeren(praefix: str, auswahl, warten: float = 3.0) -> dict:
    """Behaltene Themen unter <praefix>/ am Broker loeschen und NACHLESEN.

    paho mit Brokerhost, Brokerport, Brokeruser und Brokerpass aus
    general.json (mqtt_broker(), derselbe Weg wie der Horcher):
      1. geloescht wird nur, was WIRKLICH behalten im Broker liegt - gefunden
         ueber ein Abonnement - und was auswahl(thema) freigibt;
      2. danach ein zweites Abonnement: was dann noch behalten ankommt, ist
         stehengeblieben.
    Rueckgabe {"rc", "geleert", "rest", "grund", "ohne_paho"}: rc 0 = nichts
    (mehr) behalten, 1 = nach dem Loeschen stand noch etwas, 2 = nicht
    moeglich (keine Bibliothek, Broker fort, Anmeldung abgewiesen).
    """
    erg = {"rc": 2, "geleert": [], "rest": [], "grund": "", "ohne_paho": False}
    if not praefix or "#" in praefix or "+" in praefix:
        erg["grund"] = "das Themenpraefix '%s' taugt nicht fuer ein Abonnement" % praefix
        return erg
    try:
        import paho.mqtt.client as mqtt  # noqa: PLC0415
    except ImportError:
        erg["grund"] = "das Python-Modul paho-mqtt fehlt in der virtuellen Umgebung"
        erg["ohne_paho"] = True
        return erg
    import threading  # noqa: PLC0415
    b = mqtt_broker()
    wo = "%s:%s" % (b["host"], b["port"])
    gesehen: set = set()
    angemeldet = threading.Event()
    code = {"wert": None}

    def bei_verbindung(_k, _d, _f, *rest):
        # paho 1.x und CallbackAPIVersion.VERSION1: rc als Zahl; VERSION2:
        # ein ReasonCode mit .value.
        try:
            code["wert"] = int(getattr(rest[0], "value", rest[0]) or 0) if rest else 0
        except (TypeError, ValueError):
            code["wert"] = 0
        angemeldet.set()

    def bei_nachricht(_k, _d, n):
        # Nur BEHALTENES mit Inhalt. Ein live gesendeter Wert (retain=0) ist
        # keine Altlast, und ein leeres Thema ist schon geloescht.
        if n.retain and n.payload and auswahl(n.topic):
            gesehen.add(n.topic)

    name = "bydautos-leeren-%s-%d" % (PNAME, os.getpid())
    try:
        k = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=name)
    except (AttributeError, TypeError):
        k = mqtt.Client(client_id=name)
    k.on_connect = bei_verbindung
    k.on_message = bei_nachricht
    if b["benutzer"] != "":
        k.username_pw_set(b["benutzer"], b["passwort"] or None)
    try:
        k.connect(b["host"], b["port"], 30)
    except Exception as err:  # noqa: BLE001
        erg["grund"] = "der Broker %s ist nicht erreichbar (%s)" % (wo, fehlertext(err))
        return erg
    k.loop_start()
    try:
        if not angemeldet.wait(10):
            erg["grund"] = "der Broker %s hat auf die Verbindung nicht geantwortet" % wo
            return erg
        if code["wert"]:
            erg["grund"] = ("der Broker %s hat die Anmeldung abgewiesen: %s (CONNACK %d)"
                            % (wo, CONNACK_KLARTEXT.get(code["wert"], "unbekannter Grund"),
                               code["wert"]))
            return erg
        k.subscribe(praefix + "/#")
        time.sleep(warten)
        k.unsubscribe(praefix + "/#")
        zu_leeren = sorted(gesehen)
        for thema in zu_leeren:
            info = k.publish(thema, b"", qos=1, retain=True)
            try:
                info.wait_for_publish(5)
            except TypeError:           # paho 1.x vor 1.6 kennt kein timeout
                info.wait_for_publish()
        # NACHLESEN: ein neues Abonnement bekommt alles, was noch behalten ist.
        gesehen.clear()
        k.subscribe(praefix + "/#")
        time.sleep(warten)
        erg["geleert"] = zu_leeren
        erg["rest"] = sorted(gesehen)
        erg["rc"] = 1 if erg["rest"] else 0
    except Exception as err:  # noqa: BLE001
        erg["rc"] = 2
        erg["grund"] = "das Loeschen am Broker %s scheiterte (%s)" % (wo, fehlertext(err))
    finally:
        # ERST abmelden, DANN den Netzstrang anhalten - wie Horcher.schliessen().
        try:
            k.disconnect()
        except Exception:  # noqa: BLE001
            pass
        k.loop_stop()
    return erg


def mqtt_altlast_abraeumen(praefix: str, warten: float = 3.0) -> int:
    """Einmal je Praefix die zurueckbehaltenen Altwerte frueherer Fassungen
    am Broker loeschen - Merker ERST nach dem Nachlesen.

    Die Kennung traegt Praefix UND die Liste der fluechtigen Namen: ein
    anderer Praefix oder eine geaenderte Liste raeumt erneut ab, und kein
    Merker einer Vorfassung gilt als "schon erledigt". Solange es nicht
    nachweislich durch ist, laeuft es beim naechsten Durchgang wieder; die
    Meldung dazu ist gedrosselt (fehlt paho: einmal am Tag). Rueckgabe 0
    erledigt oder nichts zu tun, 1 es blieb etwas stehen, 2 nicht moeglich.
    """
    kennung = "|".join((RETAIN_ALTLAST_KENNUNG, praefix, ",".join(_mqtt_fluechtige_namen())))
    if json_lesen(DATEI_ZUSTAND).get("retain_altlast") == kennung:
        return 0

    def altlast(thema: str) -> bool:
        feld = mqtt_eigenes_thema(praefix, thema)
        return bool(feld) and mqtt_fluechtig(feld)

    erg = _broker_leeren(praefix, altlast, warten)
    if erg["rc"] == 2:
        melde_gebremst("retain_altlast",
                       "MQTT: zurueckbehaltene Altwerte frueherer Fassungen liessen sich "
                       "nicht abraeumen - %s. Es wird bei jedem Durchgang erneut versucht."
                       % erg["grund"], 86400 if erg["ohne_paho"] else 3600)
        return 2
    if erg["rc"] == 1:
        melde_gebremst("retain_altlast",
                       "MQTT: %d von %d zurueckbehaltenen Altwerten stehen noch im Broker "
                       "(zum Beispiel %s) - es wird beim naechsten Durchgang erneut "
                       "versucht." % (len(erg["rest"]), len(erg["geleert"]), erg["rest"][0]))
        return 1
    zustand_schreiben(retain_altlast=kennung)
    if erg["geleert"]:
        _LOG.info("MQTT: %d zurueckbehaltene Altwerte frueherer Fassungen geloescht und "
                  "nachgelesen (%s).", len(erg["geleert"]), ", ".join(erg["geleert"]))
    return 0


def _udp_leeren(praefix: str) -> int:
    """Rueckfall der Deinstallation, wenn der Broker nicht erreichbar ist
    oder paho fehlt: je eigenem Thema ein leeres 'retain' an den UDP-Eingang.
    UDP bestaetigt nichts, und die Ausgabe sagt es. Fahrzeugnummern aus dem
    letzten Abbild, sonst 1 bis 4 (ein Loeschbefehl fuer ein Thema, das es nie
    gab, ist folgenlos)."""
    z = mqtt_zustand()
    if not z["udpport"]:
        print("<INFO> MQTT: auch kein UDP-Eingang des Gateways in general.json - die "
              "behaltenen Themen bleiben stehen und sind im Broker von Hand zu loeschen.")
        return 2
    nummern: list = []
    for q in (json_lesen(DATEI_LOXONE).get("fahrzeuge"), json_lesen(DATEI_CACHE).get("fahrzeuge")):
        if isinstance(q, dict):
            for n in q:
                n = str(n)
                if n.isdigit() and 1 <= len(n) <= 2 and n not in nummern:
                    nummern.append(n)
    if not nummern:
        nummern = ["1", "2", "3", "4"]
    themen = list(MQTT_OBEN)
    for n in nummern:
        themen += ["fahrzeug%s/%s" % (n, f) for f in ["OK"] + list(FELDER) + list(ABGELEITET)]
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        print("<INFO> MQTT: kein Socket (%s) - die behaltenen Themen bleiben stehen." % err)
        return 2
    geschickt = 0
    try:
        for th in themen:
            try:
                s.sendto(("retain %s/%s " % (praefix, th)).encode("utf-8"),
                         ("127.0.0.1", z["udpport"]))
                geschickt += 1
            except OSError:
                pass
    finally:
        s.close()
    print("<INFO> MQTT: Rueckfall ueber den UDP-Eingang %d des Gateways: %d Loeschbefehle "
          "unter '%s/' geschickt (leere Nutzlast, 'retain')." % (z["udpport"], geschickt, praefix))
    print("<INFO> MQTT: UDP bestaetigt nichts. Stehen die Themen danach noch, sind sie im "
          "Broker von Hand zu loeschen.")
    return 2


def mqtt_leeren(warten: float = 3.0, praefix: str = "") -> int:
    """Fuer die Deinstallation (uninstall/uninstall, Abschnitt 1b): alle
    behaltenen EIGENEN Themen am Broker loeschen und nachmessen.

    Unabhaengig vom Schalter "MQTT ein": die Werte koennen aus der Zeit
    stammen, als er an war, und geloescht wird ohnehin nur, was wirklich
    behalten liegt. Ausgabe im Format des Installers (<OK>, <INFO>,
    <WARNING>). Rueckgabe 0 geleert/nichts zu leeren, 1 es blieb etwas
    stehen, 2 nicht am Broker moeglich (dann der UDP-Rueckfall).

    M9 (Durchgang 29.09.2026): mit praefix (Schalter --praefix=<thema>) wird
    unter diesem Praefix abgeraeumt statt unter dem eingestellten - so raeumt
    die Oberflaeche nach einem Praefixwechsel und beim Abschalten von MQTT die
    Themen unter dem ALTEN Praefix ab. Bis 0.9.19 blieben sie stehen.
    """
    praefix = mqtt_thema_saeubern(praefix or config().get("mqtt_topic") or "byd")
    erg = _broker_leeren(praefix, lambda t: bool(mqtt_eigenes_thema(praefix, t)), warten)
    if erg["rc"] == 0:
        if erg["geleert"]:
            print("<OK> MQTT: %d behaltene Themen unter '%s/' am Broker geloescht und "
                  "nachgemessen." % (len(erg["geleert"]), praefix))
        else:
            print("<INFO> MQTT: unter '%s/' war am Broker nichts von diesem Plugin behalten "
                  "- nachgemessen, nichts zu loeschen." % praefix)
        return 0
    if erg["rc"] == 1:
        print("<WARNING> MQTT: %d von %d behaltenen Themen unter '%s/' stehen nach dem "
              "Loeschen noch im Broker, zum Beispiel %s - bitte von Hand loeschen."
              % (len(erg["rest"]), len(erg["geleert"]), praefix, erg["rest"][0]))
        return 1
    print("<INFO> MQTT: am Broker nicht moeglich - %s." % erg["grund"])
    return _udp_leeren(praefix)


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
#     Oberflaeche, wird abgemeldet und neu abonniert.
#
#     Was ein Neuaufbau kostet, ist genau dies: schliessen() leert den
#     Zwischenspeicher self.werte, und alles, was seither einmal ankam, ist
#     fort. Ob der Broker etwas davon beim erneuten Abonnieren nachliefert,
#     haengt daran, ob der SENDER seine Nachricht zurueckbehalten laesst -
#     das entscheidet nicht dieses Plugin, und an dieser Anlage ist es NICHT
#     gemessen. Bis 0.9.5 stand hier, ein Neuaufbau koste "die bereits
#     empfangenen zurueckbehaltenen Werte"; das war eine Aussage ueber
#     fremdes Verhalten, die niemand geprueft hatte.
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
    }


class Horcher:
    """Haelt eine Verbindung zum Broker und die zuletzt empfangenen Werte."""

    def __init__(self):
        self.client = None
        self.werte: dict = {}          # thema -> (wert, zeitstempel)
        # Gruppe 42: haus/<quelle>/... mit Retain-Bit; Frische nur ueber den live gesehenen Zaehler.
        self.haus: dict = {}
        self.themen: set = set()
        self.fehler = ""
        self.verbunden = False
        # Wann wurde zuletzt ein Verbindungsaufbau VERSUCHT - gelungen oder
        # nicht. Siehe die Wiederholungsbremse in sicherstellen().
        self._letzter_versuch = 0.0

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
            # WIEDERHOLUNGSBREMSE. connect() blockiert den Hauptstrang, und
            # zwar bei jedem Durchlauf neu, solange der Broker nicht antwortet
            # - nach einem Fehlschlag wird self.client wieder None, und beim
            # naechsten Takt geht es von vorn los.
            #
            # Wie lange es blockiert, haengt an der paho-Fassung. GEMESSEN mit
            # paho 1.6.1 an einem nicht routbaren Ziel: 5,0 s, denn diese
            # Fassung fuehrt _connect_timeout = 5.0. Was eine aeltere oder eine
            # 2.x-Fassung auf dem Geraet tut, ist NICHT gemessen; ohne diese
            # Eigenschaft laeuft der Versuch in die Zeitgrenze des
            # Betriebssystems und dauert Minuten.
            #
            # Darum wird die Wiederholung hier begrenzt, statt sich auf ein
            # Verhalten der Bibliothek zu verlassen: nach einem Fehlschlag
            # eine Minute Ruhe. Der Horcher ist eine Zusatzfunktion - der
            # Abruf bei BYD darf nicht daran haengen, dass ein Broker
            # falsch eingetragen ist.
            if time.time() - self._letzter_versuch < 60:
                return
            self._letzter_versuch = time.time()
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
            # Den Fehler des vorigen Anlaufs HIER loeschen und nicht nach
            # loop_start(): loop_start() startet den Netzstrang, das CONNACK
            # kann sofort danach eintreffen, und _verbunden_cb traegt den Grund
            # einer Ablehnung ein. Eine Loeschung dahinter nimmt ihn wieder
            # fort. Gemessen in horcher_connack.py: der Grund fehlte je nach
            # Lauf mal beim Code 4, mal beim Code 5 - ein Wettlauf zwischen dem
            # Haupt- und dem Netzstrang, nicht ein Unterschied der Codes.
            self.fehler = ""
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

        neu = themen - self.themen
        fort = self.themen - themen
        for t in sorted(fort):
            try:
                self.client.unsubscribe(t)
            except Exception:  # noqa: BLE001
                pass
            self.werte.pop(t, None)
        # self.themen VOR der Abo-Schleife setzen: feuert _verbunden_cb
        # dazwischen (paho ruft es aus dem Netzstrang), faende es die Liste
        # sonst noch leer und abonnierte nichts nach.
        self.themen = set(themen)
        for t in sorted(neu):
            self._abonnieren(t, melden=True)

    def _abonnieren(self, thema: str, melden: bool = False) -> bool:
        """Ein Abo absetzen und den RUECKGABEWERT ansehen.

        paho wirft nicht, wenn keine Verbindung steht - subscribe() liefert
        dann (MQTT_ERR_NO_CONN, None). Ein try/except faengt hier also nichts,
        und die Erfolgszeile im Protokoll stand bis 0.9.5 auch dann da, wenn
        gar nichts abonniert wurde.
        """
        try:
            erg = self.client.subscribe(thema, qos=0)
        except Exception as err:  # noqa: BLE001
            melde_gebremst("horcher_abo", "Abo %s nicht moeglich: %s" % (thema, err), 900)
            return False
        rc = erg[0] if isinstance(erg, (tuple, list)) and erg else erg
        try:
            rc = int(getattr(rc, "value", rc))
        except (TypeError, ValueError):
            rc = -1
        if rc != 0:
            melde_gebremst("horcher_abo_rc",
                           "Das Abo auf %s wurde nicht abgesetzt (Rueckgabe %s). "
                           "Meist steht die Verbindung zum Broker nicht."
                           % (thema, rc), 900)
            return False
        if melden:
            _LOG.info("Horcher abonniert %s", thema)
        return True

    def _verbunden_cb(self, *args, **kwargs):
        """Rueckmeldung des Brokers - MIT Auswertung des Codes.

        DER BEFUND, gegen den das steht (03.09.2026): hier stand
        self.verbunden = True, ohne den Code anzusehen. paho ruft on_connect
        AUCH bei einer abgelehnten Anmeldung - rc 4 (Benutzer/Passwort falsch)
        und rc 5 (nicht berechtigt). connect() wirft dabei nicht, denn die
        TCP-Verbindung kam ja zustande; self.fehler blieb leer.

        Ergebnis: der Zustand meldete horcher_verbunden=1, der Selbsttest gab
        "[OK] Abonnierte Themen: ..." aus, der Reiter Test setzte einen gruenen
        Haken - und es kam nie eine Nachricht an. Die Vorklimatisierung loeste
        nie aus, und die einzige Spur zeigte auf den Abfahrtsassistenten statt
        auf die abgewiesene Anmeldung.

        Das ist genau die Dreiteilung aus REGELN_1: der Prozess laeuft, das
        Netz antwortet, und die ANMELDUNG ist abgelehnt. Eine PID beantwortet
        nur die erste Frage, ein gelungenes connect() nur die zweite.

        Die Rueckrufform unterscheidet sich zwischen paho 1.x und 2.x; der Code
        steht in beiden an vierter Stelle (rc bzw. reason_code). Gelesen wird
        deshalb positionsunabhaengig ueber das erste Argument, das sich als
        Zahl lesen laesst.
        """
        rc = None
        for a in args[2:]:
            wert = getattr(a, "value", a)
            if isinstance(wert, bool):
                continue
            if isinstance(wert, int):
                rc = int(wert)
                break
        if rc is None:
            rc = kwargs.get("rc", kwargs.get("reason_code"))
            rc = getattr(rc, "value", rc)
            rc = int(rc) if isinstance(rc, int) and not isinstance(rc, bool) else None
        if rc is None:
            # Nicht messbar ist nicht "in Ordnung": ohne Code wird nichts
            # behauptet, und der Selbsttest sagt es.
            self.verbunden = False
            self.fehler = ("Der Broker hat geantwortet, aber der Rueckmeldecode "
                           "liess sich nicht lesen. Ob die Anmeldung angenommen "
                           "wurde, ist damit UNBEKANNT.")
            melde_gebremst("horcher_rc_unlesbar", self.fehler, 900)
            return
        if rc != 0:
            self.verbunden = False
            # Klartext fuer BEIDE Zaehlweisen. paho 1.x liefert die Codes aus
            # MQTT 3.1.1 (1-5), paho 2.x bildet dieselben Faelle auf die
            # Ursachencodes von MQTT 5 ab (132-136). Bis 0.9.8 kannte diese
            # Stelle nur 4 und 5 - am Geraet (paho 2.1.0, 11.09.2026) stand
            # deshalb nur "Code 135", ohne Bedeutung. Gemessen am echten
            # Mosquitto: falsches Kennwort UND anonyme Anmeldung ergeben beide
            # 135 (nicht berechtigt), nicht 134.
            grund = CONNACK_KLARTEXT.get(rc, "")
            self.fehler = ("Der Broker hat die Anmeldung ABGELEHNT (Code %d%s). "
                           "Es wird nichts empfangen. %s"
                           % (rc, (" - " + grund) if grund else "",
                              CONNACK_RAT.get(rc, "Die Bedeutung dieses Codes ist hier "
                                                  "nicht hinterlegt.")))
            melde_gebremst("horcher_connack", self.fehler, 900)
            return
        self.verbunden = True
        self.fehler = ""
        # Nach jedem Verbindungsaufbau werden die Abos neu gesetzt: der Broker
        # kennt sie nach einer Trennung nicht mehr.
        for t in sorted(self.themen):
            self._abonnieren(t)

    def _getrennt_cb(self, *args, **kwargs):
        self.verbunden = False

    def _nachricht(self, client, userdata, nachricht):
        try:
            text = nachricht.payload.decode("utf-8", "replace").strip()
        except Exception:  # noqa: BLE001
            return
        retained = bool(getattr(nachricht, "retain", False))
        # Gruppe 42 (G4): Haus-Themen (Regeln/07 Nr. 15) werden auch retained angenommen - sie gelten aber
        # erst mit einem LIVE gesehenen Zaehlerwechsel ihrer Quelle.
        if str(nachricht.topic).startswith("haus/"):
            haus_merken(self.haus, nachricht.topic, text, retained, time.time())
            return
        # B4 (Gruppe 42, Regeln/07 :221): eine retained Nachricht kommt beim Abonnieren mit beliebigem Alter
        # an. Bis 0.9.28 galt sie als frisch zum Empfangszeitpunkt (abfahrt/ABFAHRT_IN, Empfehlungsthema).
        # Seit 0.9.29 hat sie die Zeit 0 (nie frisch); erst eine live empfangene Nachricht zaehlt.
        self.werte[nachricht.topic] = (text, 0.0 if retained else time.time())

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
                # ERST abmelden, DANN den Netzstrang anhalten. Umgekehrt - so
                # stand es bis 0.9.5 - wird das DISCONNECT-Paket unter
                # Umstaenden nicht mehr abgearbeitet, und der Socket bleibt
                # liegen, bis der Aufraeumer ihn holt.
                self.client.disconnect()
                self.client.loop_stop()
            except Exception:  # noqa: BLE001
                pass
        self.client = None
        self.themen = set()
        self.verbunden = False
        # Die empfangenen Werte gehoeren mit weg. Sonst gilt nach einem Aus-
        # und Wiedereinschalten der Ladeempfehlung ein alter Wert als frisch,
        # solange er innerhalb von ladeempf_alter liegt - und das sind bis zu
        # 86400 Sekunden.
        self.werte = {}
        self.haus = {}
        self.fehler = ""
        # Die Wiederholungsbremse zuruecksetzen: dieses Schliessen war
        # gewollt (die Themenliste wurde leer). Wer danach wieder ein Thema
        # eintraegt, soll nicht bis zu einer Minute auf die erste Verbindung
        # warten - die Bremse ist gegen einen unerreichbaren Broker gedacht,
        # nicht gegen eine Aenderung in der Oberflaeche.
        self._letzter_versuch = 0.0


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
        if th and cfg.get("ladeempf_quelle") != "haus":
            t.add(th)
    # Laden-1: Rang und Lebenszeichen der Preisquelle.
    t |= preis_themen(cfg)
    # Gruppe 42: Haus-Themen - dieselbe Bedingung wie by_haus_quellen() in by_lib.php.
    t |= set(haus_themen(cfg))
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
    if cfg.get("ladeempf_quelle") == "haus":
        # Gruppe 42 (G7): Endpreis jetzt aus haus/strompreis/aktuell - nur bei frischer Quelle (Zaehler live
        # binnen 120 s). Schweigt sie oder kommt '-', geht wie beim eigenen Thema eine 0 hinaus.
        grenze = zahl(cfg.get("ladeempf_grenze"))
        if grenze is None:
            return 0
        if not haus_frisch(horcher.haus, "strompreis", time.time()):
            melde_gebremst("ladeempf_haus_still", "Ladeempfehlung: haus/strompreis schweigt (kein Zaehlerwechsel "
                           "binnen 120 s). Es wird 0 gesendet.", 1800)
            return 0
        v = zahl(haus_wert(horcher.haus, "strompreis", "haus/strompreis/aktuell"))
        if v is None:
            return 0
        return 1 if (v <= grenze if cfg.get("ladeempf_unter") else v >= grenze) else 0
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
        # Die beiden Faelle darueber melden, dieser schwieg. Dabei ist er der
        # einzige, den der Anwender selbst verursacht hat und selbst beheben
        # kann: die Empfehlung steht auf ein, ein Thema ist eingetragen, ein
        # frischer Wert kommt an - und die Schwelle daneben ist keine Zahl.
        # Sichtbar war nur eine Empfehlung, die dauerhaft 0 sagt.
        melde_gebremst("ladeempf_grenze",
                       "Ladeempfehlung: die Schwelle ist keine Zahl (%r). Es wird "
                       "dauerhaft 0 gesendet. Reiter Einstellungen, Feld "
                       "Schwelle." % (str(cfg.get("ladeempf_grenze"))[:40],),
                       1800)
        return 0
    return 1 if (z <= grenze if cfg.get("ladeempf_unter") else z >= grenze) else 0


# ---------------------------------------------------------------------------
# Laden-1 (Verbesserungsbau 30.09.2026, D-Punkt): Ladeempfehlung nach
# Strompreis aus Spotpreis aWATTar oder Spotpreis Tibber
#
# Die Kopplung laeuft ueber die MQTT-Themen der anderen Linie, nie ueber deren
# Dateien (vb_RAHMEN.md, D-Punkte). Gelesen wird der RANG der laufenden Stunde
# unter den naechsten 24 Stunden (1 = guenstigste) und die Laenge der
# Rangfolge. Nachgelesen in Spotpreis-aWATTar 1.2.30 und Spotpreis-Tibber
# 0.9.24:
#   rank   beide gleich: 1 + Zahl der guenstigeren Eintraege
#   rankd  aWATTar: n + 1 - rank     Tibber: n (Zahl der Eintraege)
# Beide senden rank/rankd FLUECHTIG und nur bei Aenderung - aWATTar dazu den
# vollen Satz halbstuendlich, Tibber bei jeder Aenderung der Signatur (in der
# Praxis stuendlich). Das Lebenszeichen (status/ts, status/zaehler,
# status/ok) geht bei jedem Lauf der anderen Linie hinaus, also jede Minute.
#
# Folge fuer den Anlauf: nach einem Start dieses Dienstes kommt der Rang erst
# mit der naechsten Aenderung oder dem naechsten vollen Satz - bis zu 30 min
# (aWATTar) bzw. bis zur naechsten Stunde (Tibber). Bis dahin "-" mit
# KEIN_RANG. Das steht so in Hilfe und README.
#
# Schweigt die Quelle (kein Lebenszeichen seit PREIS_LEBEN_S), meldet sie
# keine Preise (status/ok 0) oder fehlt ein frischer Rang, geht "-" hinaus
# (Entscheidung 5: ein Zustand ohne Aussage ist "-", nie leer) - und der Reiter
# Test zeigt es gelb. Das ist der Rueckfall auf das bisherige Verhalten: es
# gibt dann keine Empfehlung. Geschaltet wird am Auto nichts.
# ---------------------------------------------------------------------------
PREIS_QUELLEN = {"awattar": "spot_awattar", "tibber": "tibber"}
# Lebenszeichen der Quelle: sie sendet es jede Minute; 300 s sind fuenf
# ausgefallene Laeufe (Regeln/07: der UDP-Eingang verwirft unter Last).
PREIS_LEBEN_S = 300
# Hoechstalter des Rangs: aWATTar sendet ihn spaetestens alle 30 min neu,
# Tibber mit jeder Signaturaenderung (stuendlich). 3900 s = eine Stunde und
# fuenf Minuten Spielraum.
PREIS_RANG_S = 3900
# Gerechnet wird alle 30 s, gesendet bei jeder Aenderung und sonst alle 300 s
# (fluechtige Themen: nach einem Neustart von Gateway oder Miniserver stehen
# sie spaetestens nach fuenf Minuten wieder da).
PREISEMPF_TAKT = 30
PREISEMPF_WIEDERHOLEN = 300
# Nach dem Einschalten bzw. Dienststart kommt das Lebenszeichen der Quelle
# erst mit ihrem naechsten Minutenlauf. So lange wird ein "-" nicht gesendet
# (Grund ANLAUF, nur im Reiter Test) - sonst stuende bei jedem Start kurz
# ein "-" in Loxone.
PREISEMPF_ANLAUF = 120
DATEI_PREISEMPF = PDATA / "preisempfehlung.json"


def preis_praefix(cfg: dict) -> str:
    """Themenpraefix der Preisquelle: der eingetragene, sonst der Vorgabepraefix
    der gewaehlten Linie. Ein Eintrag mit Joker- oder Trennzeichen gilt nicht
    (die Oberflaeche weist ihn ab; von Hand in byd.json geschrieben faellt er
    hier auf die Vorgabe zurueck)."""
    quelle = str(cfg.get("preisempf_quelle") or "awattar")
    if quelle == "haus":
        return "haus/strompreis"     # Gruppe 42 (E9a): nur fuer Anzeige und Anlauf
    vorgabe = PREIS_QUELLEN.get(quelle, PREIS_QUELLEN["awattar"])
    roh = str(cfg.get("preisempf_praefix") or "").strip().strip("/")
    if not roh or any(z in roh for z in "#+ \t\r\n") or "//" in roh:
        return vorgabe
    return roh


def preis_themen(cfg: dict) -> set:
    """Die fremden Themen, die Laden-1 braucht - leer, wenn aus.
    Gegenstueck in der Oberflaeche: by_preis_themen() in by_lib.php."""
    if not cfg.get("preisempf_ein") or cfg.get("preisempf_quelle") == "haus":
        return set()                 # Gruppe 42: die Haus-Themen bringt haus_themen()
    p = preis_praefix(cfg)
    return {p + "/rank", p + "/rankd", p + "/status/ok", p + "/status/ts",
            p + "/status/zaehler"}


def _preis_ganzzahl(roh):
    """'3' -> 3, '3.0' -> 3; alles andere (auch '', '-', -1) -> None."""
    try:
        z = float(str(roh).strip())
    except (TypeError, ValueError):
        return None
    if z != z or not z.is_integer():
        return None
    return int(z)


def preisempfehlung(horcher, cfg: dict) -> dict:
    """Laden-1: die Empfehlung aus dem Rang der laufenden Stunde.

    Rueckgabe: {"wert": 1|0|"-", "grund": Kennwort, ...Angaben fuer den Reiter
    Test}. Kennwoerter: GUENSTIG, TEUER (mit Wert), HORCHER, QUELLE_SCHWEIGT,
    QUELLE_OHNE_PREISE, KEIN_RANG (mit "-")."""
    quelle = str(cfg.get("preisempf_quelle") or "awattar")
    if quelle not in PREIS_QUELLEN and quelle != "haus":
        quelle = "awattar"
    p = preis_praefix(cfg)
    n_std = max(1, min(12, ganz(cfg.get("preisempf_stunden"), 4)))
    erg = {"ts": int(time.time()), "wert": "-", "grund": "", "quelle": quelle,
           "praefix": p, "stunden": n_std, "rang": None, "von": None,
           "grenze": None, "alter_status": None, "alter_rang": None}
    if not horcher.verbunden:
        erg["grund"] = "HORCHER"
        return erg
    if quelle == "haus":
        # Gruppe 42 (E9a): Rang der laufenden Stunde aus haus/strompreis/vorschau (Stundenmittel), frisch nur
        # ueber den live gesehenen Zaehler. Rueckfall auf aWATTar/Tibber ist die Einstellung selbst.
        jetzt = time.time()
        if not haus_frisch(horcher.haus, "strompreis", jetzt):
            erg["grund"] = "QUELLE_SCHWEIGT"
            return erg
        h = horcher.haus
        rang, von = haus_preisrang(haus_wert(h, "strompreis", "haus/strompreis/vorschau"),
                                   haus_wert(h, "strompreis", "haus/strompreis/ab"),
                                   haus_wert(h, "strompreis", "haus/strompreis/raster"), jetzt)
        if rang is None:
            erg["grund"] = "KEIN_RANG"
            return erg
        erg.update({"rang": rang, "von": von, "grenze": n_std, "alter_status": 0})
        erg["wert"], erg["grund"] = (1, "GUENSTIG") if rang <= n_std else (0, "TEUER")
        return erg
    alter = []
    for t in ("status/zaehler", "status/ts"):
        w, a = horcher.wert(p + "/" + t, PREIS_LEBEN_S)
        if w is not None:
            alter.append(a)
    if not alter:
        erg["grund"] = "QUELLE_SCHWEIGT"
        return erg
    erg["alter_status"] = min(alter)
    ok_roh, _a = horcher.wert(p + "/status/ok", PREIS_LEBEN_S)
    if ok_roh is not None and _preis_ganzzahl(ok_roh) == 0:
        erg["grund"] = "QUELLE_OHNE_PREISE"
        return erg
    rang_roh, a_rang = horcher.wert(p + "/rank", PREIS_RANG_S)
    rangd_roh, _a = horcher.wert(p + "/rankd", PREIS_RANG_S)
    if a_rang >= 0:
        erg["alter_rang"] = a_rang
    rang = _preis_ganzzahl(rang_roh) if rang_roh is not None else None
    rangd = _preis_ganzzahl(rangd_roh) if rangd_roh is not None else None
    if rang is None or rangd is None or rang < 1 or rangd < 1:
        erg["grund"] = "KEIN_RANG"
        return erg
    von = rangd if quelle == "tibber" else rang + rangd - 1
    if von < 1 or rang > von:
        erg["grund"] = "KEIN_RANG"
        return erg
    # Eintraege feiner als eine Stunde (Viertelstundenpreise): n > 24. Dann
    # gilt die Grenze anteilig, sonst waeren "4 Stunden" nur 4 Viertelstunden.
    grenze = n_std if von <= 24 else -(-n_std * von // 24)
    erg.update({"rang": rang, "von": von, "grenze": grenze})
    if rang <= grenze:
        erg["wert"], erg["grund"] = 1, "GUENSTIG"
    else:
        erg["wert"], erg["grund"] = 0, "TEUER"
    return erg


class Preisempfehlung:
    """Laden-1 im laufenden Dienst: rechnet alle PREISEMPF_TAKT Sekunden, sendet
    bei jeder Aenderung und sonst alle PREISEMPF_WIEDERHOLEN Sekunden, schreibt
    den Stand fuer die Oberflaeche nach DATEI_PREISEMPF (bei Aenderung, sonst
    hoechstens alle 300 s - der Datenordner liegt auf der Karte)."""

    def __init__(self):
        self.gerechnet = 0.0
        self.gesendet_um = 0.0
        self.gesendet = None       # (wert, grund) zuletzt hinausgegangen
        self.geschrieben_um = 0.0
        self.geschrieben = None
        self.ein_seit = 0.0        # 0 = aus; sonst Beginn des Anlaufs
        self.anlauf_praefix = ""
        self.anlauf_vorbei = False
        self.hat_gesendet = False

    def _senden(self, cfg: dict, wert, grund: str) -> bool:
        if not cfg.get("mqtt_ein"):
            return False
        try:
            versucht, schlecht = mqtt_senden(
                {"lade_empfehlung": wert, "lade_empfehlung_grund": grund},
                mqtt_thema_saeubern(cfg.get("mqtt_topic") or "byd"))
        except Exception as err:  # noqa: BLE001
            melde_gebremst("preisempf_senden", "Ladeempfehlung nach Strompreis: "
                           "Senden gescheitert (%s)." % fehlertext(err), 3600)
            return False
        return versucht > 0 and schlecht == 0

    def takt(self, horcher, erzwingen: bool = False) -> None:
        jetzt = time.time()
        if not erzwingen and jetzt - self.gerechnet < PREISEMPF_TAKT:
            return
        self.gerechnet = jetzt
        # Die Einstellung wird hier nachgelesen, nicht erst im naechsten
        # Abruftakt (bis 3600 s): wer sie einschaltet, sieht nach spaetestens
        # 30 s die Abos und im Reiter Test den Stand.
        cfg = config()
        horcher.sicherstellen(horcher_themen(cfg))
        if not cfg.get("preisempf_ein"):
            if self.ein_seit and self.hat_gesendet:
                # Im Lauf ausgeschaltet: EINMAL "-", sonst stuende in Loxone
                # die letzte 1 (fluechtige Themen bleiben am Eingang stehen).
                self._senden(cfg, "-", "AUS")
                _LOG.info("Ladeempfehlung nach Strompreis ausgeschaltet - einmal '-' "
                          "gesendet.")
            self.ein_seit = 0.0
            self.gesendet = None
            self.hat_gesendet = False
            self.geschrieben = None
            try:
                DATEI_PREISEMPF.unlink()
            except OSError:
                pass
            return
        # Anlauf: nach dem Einschalten, dem Dienststart und einem Wechsel der
        # Quelle (anderer Praefix: neue Abos, das erste Lebenszeichen fehlt
        # noch). Er endet mit der ersten echten Aussage (0/1) oder nach
        # PREISEMPF_ANLAUF Sekunden - danach geht jedes "-" hinaus. Im
        # Pruefstand gemessen: ohne das Ende bei der ersten Aussage wurde ein
        # "-" (status/ok 0) zwei Minuten lang verschluckt, ohne den
        # Praefixwechsel ging beim Umstellen auf Tibber kurz ein "-" hinaus.
        praefix = preis_praefix(cfg)
        if not self.ein_seit or praefix != self.anlauf_praefix:
            self.ein_seit = jetzt
            self.anlauf_praefix = praefix
            self.anlauf_vorbei = False
        erg = preisempfehlung(horcher, cfg)
        if erg["wert"] in (0, 1):
            self.anlauf_vorbei = True
        anlauf = (erg["wert"] == "-" and not self.anlauf_vorbei
                  and jetzt - self.ein_seit < PREISEMPF_ANLAUF)
        if anlauf:
            erg["grund_eigentlich"] = erg["grund"]
            erg["grund"] = "ANLAUF"
        erg["mqtt"] = 1 if cfg.get("mqtt_ein") else 0
        paar = (erg["wert"], erg["grund"])
        if paar != self.geschrieben or jetzt - self.geschrieben_um >= 300:
            if json_schreiben(DATEI_PREISEMPF, erg):
                self.geschrieben = paar
                self.geschrieben_um = jetzt
        if anlauf:
            return
        if paar != self.gesendet or jetzt - self.gesendet_um >= PREISEMPF_WIEDERHOLEN:
            if paar != self.gesendet:
                _LOG.info("Ladeempfehlung nach Strompreis: %s (%s, Rang %s von %s, "
                          "Grenze %s, Quelle %s)", erg["wert"], erg["grund"],
                          erg["rang"], erg["von"], erg["grenze"], erg["praefix"])
            if self._senden(cfg, erg["wert"], erg["grund"]):
                self.hat_gesendet = True
            # Auch ohne MQTT als erledigt vermerken: sonst stuende bei MQTT
            # aus jede halbe Minute dieselbe Protokollzeile da.
            self.gesendet = paar
            self.gesendet_um = jetzt

    def abschied(self) -> None:
        """Der Dienst endet: einmal "-" mit DIENST_AUS, wenn in diesem Lauf
        eine Empfehlung hinausging (fluechtig - Loxone behielte sonst den
        letzten Wert)."""
        if not self.hat_gesendet:
            return
        cfg = config()
        if cfg.get("preisempf_ein"):
            self._senden(cfg, "-", "DIENST_AUS")


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
        roh = vars(objekt)
        aus = {k: v for k, v in roh.items() if not k.startswith("_")}
        # Was mit einem Unterstrich beginnt, wird uebergangen - das ist so
        # gewollt. Bisher geschah es aber STILL, und der Fall, in dem es weh
        # tut, sieht von aussen aus wie ein Fahrzeug ohne Werte: haelt eine
        # kuenftige pybyd-Fassung ihre Nutzdaten in _daten oder __fields, bleibt
        # hier ein leeres Verzeichnis, und im Reiter Test steht "0 Felder
        # aufgeloest" - ohne einen Hinweis, woran es liegt. Darum wird gezaehlt
        # und gesagt, sobald nichts uebrig bleibt.
        if not aus and roh:
            melde_gebremst(
                "zu_dict_privat",
                "Die Bibliothek liefert ein Objekt (%s), dessen %d Attribute "
                "alle mit einem Unterstrich beginnen. Uebergangen wurden sie "
                "deshalb alle, sichtbar blieb kein einziger Wert. Das ist kein "
                "Fahrzeug ohne Daten, sondern eine pybyd-Fassung, die ihre "
                "Werte anders ablegt."
                % (type(objekt).__name__, len(roh)), 3600)
        return aus
    melde_gebremst(
        "zu_dict_unbekannt",
        "Die Bibliothek liefert ein Objekt (%s), das weder model_dump() noch "
        "dict() noch __dict__ anbietet. Es wird als leer behandelt - es werden "
        "keine Werte erfunden." % type(objekt).__name__, 3600)
    return {}


# Grenzen fuer flach(). Die Antwort der Gegenstelle ist fremde Eingabe, und
# ihre Groesse bestimmt niemand hier: das Ergebnis von flach() landet als
# Rohdaten in einer Datei auf der Ramdisk des LoxBerry und wird bei --felder
# vollstaendig ausgegeben. Ohne Grenzen genuegt eine tief verschachtelte oder
# sehr lange Antwort, um entweder den Rekursionsanschlag von Python zu
# treffen (der Dienst stirbt) oder die Ramdisk zu fuellen (dann schreibt kein
# Plugin des Geraets mehr, nicht nur dieses).
#
# Die Zahlen sind gesetzt, nicht gemessen. Gemessen ist nur, was eine echte
# BYD-Antwort heute braucht - und das ist nicht gemessen, weil es kein Konto
# zum Messen gibt. 12 und 5000 liegen weit ueber allem, was die Feldtabelle
# ansteuert (tiefster Kandidat: range_detail_list.0.range, also drei Ebenen).
FLACH_TIEFE = 12
FLACH_FELDER = 5000


def flach(d: dict, praefix: str = "", tiefe: int = 0, aus: dict = None) -> dict:
    """Verschachtelte Verzeichnisse und Listen zu Punktpfaden aufloesen.

    Aus {"a": {"b": 1}} wird {"a.b": 1}, aus {"a": [{"b": 2}]} wird
    {"a.0.b": 2}. Damit kann eine Kandidatenliste auch auf einen Pfad zeigen,
    ohne dass der Leser die Struktur kennen muss.

    Tiefe und Feldzahl sind begrenzt - Begruendung bei FLACH_TIEFE. Wird eine
    Grenze erreicht, bricht das Aufloesen an dieser Stelle ab und meldet es;
    es wird NICHT stillschweigend weniger geliefert. Was schon gefunden ist,
    bleibt brauchbar.
    """
    if aus is None:
        aus = {}
    if tiefe > FLACH_TIEFE:
        melde_gebremst("flach_tiefe",
                       "Die Antwort der Gegenstelle ist tiefer als %d Ebenen "
                       "verschachtelt. Ab hier wird nicht weiter aufgeloest "
                       "(Pfad %s)." % (FLACH_TIEFE, praefix or "(Wurzel)"), 3600)
        return aus
    for k, v in d.items():
        if len(aus) >= FLACH_FELDER:
            melde_gebremst("flach_felder",
                           "Die Antwort der Gegenstelle traegt mehr als %d "
                           "Felder. Der Rest wird nicht mehr aufgeloest."
                           % FLACH_FELDER, 3600)
            return aus
        name = ("%s.%s" % (praefix, k)) if praefix else str(k)
        if isinstance(v, dict):
            flach(v, name, tiefe + 1, aus)
        elif isinstance(v, (list, tuple)):
            for i, e in enumerate(v):
                if isinstance(e, dict):
                    flach(e, "%s.%d" % (name, i), tiefe + 1, aus)
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


def einmischen(ziel: dict, quelle: dict) -> None:
    """Legt 'quelle' ueber 'ziel' - aber ein None loescht nie einen Wert.

    GEMESSEN am 14.09.2026 an der anonymisierten Rohausgabe eines BYD Seal U
    Design (Issue #1): der Echtzeit-Abschnitt fuehrt
    "speed": 84, der GPS-Abschnitt fuehrt denselben Schluessel als
    "speed": null. Ein gewoehnliches dict.update() setzt damit die 84 wieder
    auf None - und TEMPO blieb bei JEDEM Abruf leer, auch waehrend der Fahrt.
    Der Reiter Test meldete "21 von 23 Feldern aufgeloest - REICHW, TEMPO";
    bei TEMPO war es kein fehlendes Feld, sondern ein zerstoerter Wert.

    Genau EIN Schluessel war in dieser Antwort betroffen - nachgezaehlt ueber
    alle drei Abschnitte (Pruefung-BYD-Autos-0.9.11/rohdaten_messen.py).

    Ein None kostet dabei nichts: hole() ueberspringt einen Kandidaten mit
    dem Wert None ohnehin und geht zum naechsten. Ein Abschnitt, der einen
    Schluessel gar nicht fuehrt, und einer, der ihn mit None fuehrt, sind
    fuer die Feldaufloesung also dasselbe - deshalb darf der frueher
    gemessene Wert stehen bleiben.
    """
    for schluessel, wert in quelle.items():
        if wert is None and ziel.get(schluessel) is not None:
            continue
        ziel[schluessel] = wert


def _zahl_roh(wert):
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


def zahl(wert, nachkomma=None):
    """Gruppe 42: _zahl_roh(), wahlweise gerundet - die aus VolkswagenID uebernommenen Funktionen rufen
    zahl(w, 1). Ohne zweites Argument genau wie bis 0.9.28."""
    z = _zahl_roh(wert)
    return z if z is None or nachkomma is None else round(z, nachkomma)


def zahl_vorn(wert):
    """Fuehrende Zahl aus einer Zeichenkette, die ihre Einheit mitfuehrt.

    Gebraucht fuer Rohwerte wie "17.6kW\u00b7h/100km" (Durchschnittsverbrauch,
    Rohausgabe eines Seal U Design). zahl() gibt dafuer None zurueck, weil
    float() an der Einheit scheitert.

    BEWUSST OHNE re: dieses Modul importiert re nur LOKAL in einer Funktion
    (siehe unten, noqa PLC0415). Ein re.match hier haette bei JEDEM Abruf
    einen NameError geworfen - also von Hand, mit den Mitteln, die hier oben
    zur Verfuegung stehen.

    Steht vorn keine Zahl, ist das Ergebnis None - nicht 0.
    """
    if wert is None or isinstance(wert, bool):
        return None
    if isinstance(wert, (int, float)):
        return zahl(wert)
    s = str(wert).strip()
    i = 0
    if i < len(s) and s[i] in "+-":
        i += 1
    punkt = False
    while i < len(s):
        c = s[i]
        if c.isdigit():
            i += 1
        elif c in ".," and not punkt:
            punkt = True
            i += 1
        else:
            break
    kopf = s[:i]
    if kopf in ("", "+", "-", ".", ",", "+.", "-.", "+,", "-,"):
        return None
    return zahl(kopf)


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
# ---------------------------------------------------------------------------
# Sprachausgabe (Nr. 36 b, Stufe 2, seit 0.9.23)
#
# Der Dienst sagt einzelne Ereignisse an - ueber die gemeinsame Sprachausgabe
# der Plugins dieses Hauses (webfrontend/html/sprachausgabe.php). Die gibt es
# nur in PHP; gesprochen wird deshalb ueber die Bruecke bin/by_ansage.php. Der
# Auftrag geht ueber die STANDARDEINGABE, nie ueber die Kommandozeile (die
# sieht jeder in der Prozessliste), und er traegt keinen Text: den Satz baut
# die Bruecke aus der Sprachdatei. Ob und wohin gesprochen wird, entscheidet
# die Bruecke aus dem Block tts der Konfiguration (ab Werk aus). Der Dienst
# sieht dort nur nach, ob die Ausgabe aus ist - dann ruft er gar nicht erst.
# Die Benachrichtigungen ueber by_notify.php bleiben, wie sie sind.
#
# Angesagt wird nur ein WECHSEL, nie ein Wert im Takt:
#   laden_fertig   LAEDT 1 -> 0 (charge_state 1 -> 0 oder 15). Der Satz nennt
#                  den Ladestand; ob die Ladung am Ziel war, weiss BYD nicht zu
#                  sagen - die Schnittstelle fuehrt keine Ladegrenze.
#   ausfall        der dritte gescheiterte Abruf in Folge (dieselbe Grenze wie
#                  die Bremse), erst nachdem seit dem Start wenigstens einer
#                  gelungen ist
# Nicht gebaut: "offen", "Licht", "Klima" - Tueren und Schloss liefert BYD als
# geraetespezifische Rohwerte (erst am Fahrzeug abzulesen), Licht und
# Klimatisierung gar nicht.
# Die erste Beobachtung eines Fahrzeugs nach dem Start setzt nur den
# Ausgangsstand: ein Neustart spricht nie. Jeder Anlass hat einen eigenen Haken
# (ansage_<anlass>, genau "1" heisst an) und eine Wiederholsperre je Anlass und
# Fahrzeug (ANSAGE_SPERRE_S), die einen Neustart des Dienstes ueberlebt.
# ---------------------------------------------------------------------------
SKRIPT_ANSAGE = SELF / "by_ansage.php"
DATEI_ANSAGE_SPERRE = PDATA / "ansage_sperre.json"
ANSAGE_ANLAESSE = ("laden_fertig", "ausfall", "kabel_vergessen")
ANSAGE_SPERRE_S = 3600     # nur noch SignalBot: je Ereignis und Fahrzeug einmal je Stunde. Die Ansage regelt der Stamm (Welle S4).
ANSAGE_FRIST_S = 25        # Bruecke: PHP-Start plus Sprechfrist 20 s (by_ansage_k(); Modul 1.2.1: in jedem Weg, B1)
ANSAGE_AUSFALL_FOLGE = 3
_ANSAGE_ZEICHEN = frozenset("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.:,|/-")
_ANSAGE_STAND: dict = {}   # je Fahrzeugnummer der zuletzt gesehene Stand - nur im Speicher
_ANSAGE_DIENST = {"ok_gesehen": False, "fehler_folge": 0}


def ansage_ereignisse(nr: str, f: dict) -> list:
    """Die Anlaesse eines Fahrzeugs aus dem Vergleich mit dem zuletzt gesehenen
    Stand. Nur ein frisches Abbild (ok=1) kommt hierher. Rueckgabe: Liste von
    (anlass, daten) - daten ohne Text, nur Name und Ladestand."""
    alt = _ANSAGE_STAND.get(nr)
    vorher = alt or {}
    # Ein unbekannter Zustand (None) laesst den bekannten stehen.
    neu = {"laedt": f.get("LAEDT") if f.get("LAEDT") is not None else vorher.get("laedt")}
    _ANSAGE_STAND[nr] = neu
    if alt is None:
        return []
    name = str(f.get("name") or f.get("modell") or "")[:60]
    aus = []
    if vorher.get("laedt") == 1 and neu["laedt"] == 0:
        aus.append(("laden_fertig", {"name": name, "soc": f.get("SOC")}))
    return aus


def ansage_ausfall(ok: int, fehler_folge: int) -> bool:
    """Ist das der Abruf, mit dem die Daten als ausgefallen gelten?"""
    d = _ANSAGE_DIENST
    vorher = d["fehler_folge"]
    d["fehler_folge"] = fehler_folge
    if ok:
        d["ok_gesehen"] = True
        return False
    return bool(d["ok_gesehen"]) and vorher < ANSAGE_AUSFALL_FOLGE <= fehler_folge


def _ansage_aus(cfg: dict) -> bool:
    """Ist die Ausgabe aus? Nur der Modus wird angesehen (config() reicht den Block
    tts unveraendert durch); geprueft wird er in der Bruecke. Fehlt er oder ist er
    unlesbar, gilt er als aus (Vorgabe)."""
    t = cfg.get("tts")
    # Stamm 1.3.2: nie gespeichert heisst ab Werk an; ein gespeichertes "aus" bleibt.
    return isinstance(t, dict) and t.get("mode", "aus") == "aus"


def _ansage_sperre(schluessel: str, jetzt: int) -> bool:
    """Gesperrt? Sonst wird die Sperre gesetzt (und abgelaufene Eintraege fallen weg)."""
    s = json_lesen(DATEI_ANSAGE_SPERRE)
    t = s.get(schluessel)
    if isinstance(t, (int, float)) and not isinstance(t, bool) and 0 <= jetzt - t < ANSAGE_SPERRE_S:
        return True
    s = {k: v for k, v in s.items()
         if isinstance(v, (int, float)) and not isinstance(v, bool) and 0 <= jetzt - v < ANSAGE_SPERRE_S}
    s[schluessel] = jetzt
    json_schreiben(DATEI_ANSAGE_SPERRE, s)
    return False


def ansage_senden(anlass: str, nr: str, daten: dict, cfg: dict) -> None:
    """Einen Anlass ueber die Bruecke ansagen. Ins Protokoll kommen Anlass,
    Fahrzeugnummer und das Ergebnis (Stand, Art, HTTP, Zeichenzahl, Kennung) -
    nie der Text, nie ein Token."""
    if anlass not in ANSAGE_ANLAESSE or str(cfg.get("ansage_" + anlass)).strip() != "1":
        return
    if _ansage_aus(cfg):
        return
    # Stamm 1.3.2: Sperre, Nachtruhe und Schalter stehen im Stamm; die Bruecke legt nur hin.
    auftrag = {"anlass": anlass, "nr": ganz(nr, 0), "name": str(daten.get("name") or "")}
    for k in ("soc", "grenze"):
        v = daten.get(k)
        # Nur ein Prozentwert 0..100 reist mit; alles andere gilt als unbekannt.
        auftrag[k] = v if (isinstance(v, (int, float)) and not isinstance(v, bool)
                           and 0 <= v <= 100) else None
    try:
        e = subprocess.run(["php", str(SKRIPT_ANSAGE), PNAME],
                           input=json.dumps(auftrag, ensure_ascii=False).encode("utf-8"),
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                           timeout=ANSAGE_FRIST_S, check=False)
    except (OSError, subprocess.SubprocessError) as err:
        melde_gebremst("ansage_ruf", "Ansage %s (Fahrzeug %s): die Bruecke by_ansage.php war "
                                     "nicht aufrufbar (%s)." % (anlass, nr, type(err).__name__), 3600)
        return
    zeilen = e.stdout.decode("ascii", "replace").strip().splitlines()
    zeile = zeilen[-1] if zeilen else ""
    f = {}
    if zeile.startswith("ANSAGE;"):
        for teil in zeile.split(";")[1:]:
            if "=" in teil:
                k, v = teil.split("=", 1)
                f[k] = "".join(c if c in _ANSAGE_ZEICHEN else "?" for c in v)[:120]
    _LOG.log(logging.INFO if e.returncode in (0, 3) else logging.WARNING,
             "Ansage %s (Fahrzeug %s): rc=%d stand=%s art=%s http=%s zeichen=%s kennung=%s",
             anlass, nr, e.returncode, f.get("STAND", "?"), f.get("ART", "?"),
             f.get("HTTP", "?"), f.get("ZEICHEN", "?"), f.get("KENNUNG", "?"))


def ansagen(cfg: dict, fahrzeuge: dict, ok: int, fehler_folge: int) -> None:
    """Nach jedem Abruf: Anlaesse erkennen und ansagen."""
    if ansage_ausfall(ok, fehler_folge):
        ansage_senden("ausfall", "0", {}, cfg)
        signal_senden(cfg, "ausfall", "0", SIGNAL_TEXTE["ausfall"])
    for nr in sorted(fahrzeuge, key=lambda n: ganz(n, 0)):
        f = fahrzeuge[nr]
        # Ein Fahrzeug ohne frischen Stand liefert keinen Wechsel.
        if not isinstance(f, dict) or not f.get("ok"):
            continue
        for anlass, daten in ansage_ereignisse(nr, f):
            ansage_senden(anlass, nr, daten, cfg)
            # Gruppe 42 (E10): dieselben Ereignisse an SignalBot - eine Erkennung fuer beide Wege.
            if anlass in SIGNAL_TEXTE:
                wer = str(daten.get("name") or "") or ("Fahrzeug %s" % nr)
                signal_senden(cfg, anlass, nr, SIGNAL_TEXTE[anlass] % wer)


# ---------------------------------------------------------------------------
# Reifendruecke IMMER in bar (0.9.28).
#
# Die Schnittstelle meldet die Einheit der vier Druecke selbst, als
# tire_press_unit (Feld REIFENEH). BELEGT an EINEM Fahrzeug, einem BYD Seal U
# (Issue #1):
#   14.09.2026  Kennung 1, Druecke 2.7 / 2.8, die App zeigt bar  -> 1 = bar
#   19.10.2026  Kennung 3, Druecke wie 277 (Kommentar im Issue)   -> 3 = kPa
# Kennung 2 = psi ist NICHT gemessen; sie ist die dritte Einheit, die eine
# Fahrzeug-App anbietet, und 40 psi ergeben 2.76 bar - ein Wert im
# selben Bereich wie die beiden belegten.
#
# Fehlt die Kennung oder ist sie unbekannt, wird NICHT umgerechnet - geraten
# wird nichts. Ein Wert ueber REIFEN_GRENZE_ROH ist dann aber sicher kein bar
# (277 waeren 277 bar) und geht als -1 hinaus (Hausregel: fehlender
# Zahlenwert -1); ebenso ein negativer Wert oder einer, der keine Zahl ist.
# Gerundet auf zwei Nachkommastellen. Die Rohwerte stehen weiter unveraendert
# unter "roh".
# ---------------------------------------------------------------------------
# ---------------------------------------------------------------------------
# Gruppe 42 (0.9.29): Hausform im Dienst - Haus-Themen (Regeln/07 Nr. 15),
# Vorklimatisierung nach MG-Regeln, Ankunft, Ladekosten, Ladeempfehlung und
# Laden-1 aus haus/strompreis, Meldungen an SignalBot, Lebenszeichen zaehler.
# Bauvorschrift Pruefung-Durchgang-2026-09-29/wz_bild/GRUPPE42_BAUVORSCHRIFT.md
# (Anhang zu GRUPPE41_BAUVORSCHRIFT.md, G4-G8). Die reinen Funktionen sind aus
# VolkswagenID 0.9.33 uebernommen (stueck_dienst_bauen.py; wortgleich, nur der
# Name des Zustands _G41 -> _G42), der Ladekosten-Kern WORTGLEICH zwischen den
# Marken. Hier die Rahmen fuer BYD: Horcher mit werte (text, zeit) und haus,
# befehl_ausfuehren() ist async (client, PIN-Freischaltung), Ladeprotokoll in
# abgeleitetes_ergaenzen(), Merker der Vorklimatisierung in merker.json (B2).
# ---------------------------------------------------------------------------
HAUS_FRIST = 120                 # Nr. 15: ein Wert gilt, solange der Zaehler der Quelle binnen 120 s live wechselt
VORKLIMA_VERSUCHE = 3            # 1 senden, 2 Abruf statt senden, 3 senden - hoechstens zwei "Klima ein"
VORKLIMA_ABSTAND = 120           # Sekunden zwischen zwei Versuchen
VORKLIMA_FAHRZEUG_FRISCH = 3600  # (G41) - BYD liefert keine Aussentemperatur des Fahrzeugs
AUSSEN_LOXONE_FRISCH = 1800      # G39 E-E4: Aussentemperatur aus Loxone gilt 30 min
ANKUNFT_ABSTAND = 1800           # hoechstens ein Abruf je 30 min wegen einer Ankunft
ANKUNFT_STAND_ALT = 300          # nur, wenn der Fahrzeugstand aelter als 5 min ist
DATEI_AUSSEN = PDATA / "aussentemp.json"            # schreibt die Bruecke (by_aussentemp_merken())
DATEI_LADEKOSTEN = PDATA / "ladekosten.json"        # Kosten und Preis der letzten Ladung je Fahrzeug
DATEI_MITSCHRIFT = PDATA / "ladung_mitschrift.json" # offene Mitschrift - uebersteht einen Neustart
SIGNAL_TEST_WARTEN = 5           # Sekunden, die --signal-test auf die Quittung der SignalBot-Bruecke wartet
_BROKER_SENDEN = None            # Nahtstelle fuer Proben: Funktion (thema, nutzlast) -> bool
# vorklima: Fahrzeugnummer -> Wert des Themas VORKLIMA; ladung: Kosten/Preis der letzten Ladung;
# mitschrift: offene Ladungen zu Hause; zaehler: Lebenszeichen <p>/zaehler (0...999 je Durchgang);
# abruf_offen: ein Sofortabruf (Vorklima Versuch 2, Ankunft), der auf den Mindesttakt wartet.
_G42 = {"vorklima": {}, "ladung": {}, "mitschrift": {}, "zaehler": -1, "abruf_offen": False}


def zaehler_weiter() -> int:
    """Gruppe 42 (B5): das Lebenszeichen <p>/zaehler - 0...999, je Durchgang eins weiter, nie retained."""
    _G42["zaehler"] = (int(_G42["zaehler"]) + 1) % 1000
    return _G42["zaehler"]


def haus_quelle(thema: str) -> str:
    """'haus/abfahrt/in_min' -> 'abfahrt'; haus/tag/<n>/da gehoert zum Anbieter der Anwesenheit (BLE-Scanner)."""
    teile = str(thema).split("/")
    if len(teile) < 3 or teile[0] != "haus":
        return ""
    return "anwesenheit" if teile[1] == "tag" else teile[1]
def haus_merken(stand: dict, thema: str, wert: str, retained: bool, jetzt: float) -> None:
    """Eine Nachricht unter haus/ einarbeiten (rein bis auf stand).

    B3 (Gruppe 41): ein Wert wird angenommen, auch retained - er gilt aber erst, wenn
    der Zaehler seiner Quelle LIVE wechselt. Ein retained Zaehler zaehlt nie; ein live
    gesehener Zaehler mit neuem Wert setzt 'seit' (Form mg_haus_merken())."""
    q = haus_quelle(thema)
    if not q:
        return
    e = stand.setdefault(q, {"werte": {}, "zaehler": None, "seit": 0})
    if str(thema).endswith("/zaehler"):
        if not retained and str(wert) != str(e.get("zaehler")):
            e["zaehler"] = str(wert)
            e["seit"] = int(jetzt)
    else:
        e.setdefault("werte", {})[thema] = str(wert)
def haus_frisch(stand: dict, q: str, jetzt: float) -> bool:
    """Frisch: 0 <= jetzt - seit <= HAUS_FRIST, seit gesetzt und nicht in der Zukunft (Uhr zurueck -> nicht frisch)."""
    seit = int((stand.get(q) or {}).get("seit") or 0)
    return 0 < seit <= int(jetzt) and int(jetzt) - seit <= HAUS_FRIST
def haus_wert(stand: dict, q: str, thema: str):
    return ((stand.get(q) or {}).get("werte") or {}).get(thema)
def ankunft_themen(quelle) -> list:
    """'tag:<name>,person:<name>' -> Themen; dieselbe Pruefung wie die Oberflaeche (reserviert jemand, zaehler)."""
    q = str(quelle or "").strip()
    if not re.fullmatch(r"(tag|person):[A-Za-z0-9_-]{1,40}(,(tag|person):[A-Za-z0-9_-]{1,40}){0,3}", q):
        return []
    if re.search(r"(^|,)person:(jemand|zaehler)(,|$)", q):
        return []
    aus = []
    for teil in q.split(","):
        art, name = teil.split(":", 1)
        aus.append("haus/tag/%s/da" % name if art == "tag" else "haus/anwesenheit/%s" % name)
    return aus


def haus_themen(cfg: dict) -> list:
    """Die Haus-Themen, die der Horcher abonniert - dieselbe Bedingung wie by_haus_quellen() in by_lib.php."""
    t = []
    if cfg.get("abfahrt_ein") and cfg.get("haus_abfahrt_ein"):
        t += ["haus/abfahrt/in_min", "haus/abfahrt/zaehler"]
    anw = False
    if cfg.get("abfahrt_ein") and cfg.get("vorklima_anwesend_ein"):
        t.append("haus/anwesenheit/jemand")
        anw = True
    if cfg.get("ankunft_ein"):
        for x in ankunft_themen(cfg.get("ankunft_quelle")):
            t.append(x)
            anw = True
    if anw:
        t.append("haus/anwesenheit/zaehler")
    if (cfg.get("haus_strompreis_ein")
            or (cfg.get("ladeempf_ein") and cfg.get("ladeempf_quelle") == "haus")
            or (cfg.get("preisempf_ein") and cfg.get("preisempf_quelle") == "haus")):
        t += ["haus/strompreis/" + x for x in ("vorschau", "ab", "raster", "aktuell", "zaehler")]
    return t


def haus_zustand(stand: dict, jetzt: float) -> dict:
    """Fuer zustand.json (Reiter Test): je Quelle frisch, Alter, seit - nie Werte."""
    aus = {}
    for q, e in (stand or {}).items():
        seit = int((e or {}).get("seit") or 0)
        aus[q] = {"frisch": 1 if haus_frisch(stand, q, jetzt) else 0, "seit": seit,
                  "alter": int(jetzt) - seit if seit else -1}
    return aus


def haus_preisrang(vorschau, ab, raster, jetzt):
    """Laden-1 aus haus/strompreis (E9a, rein): Rang der laufenden Stunde (1 = guenstigste) unter
    hoechstens 24 Stunden ab ihr, und deren Zahl. Viertelstunden werden zu Stundenmitteln (wie
    Marstek N1); die laufende Stunde darf angebrochen sein (vorschau beginnt mit der laufenden
    Scheibe), jede spaetere zaehlt nur vollstaendig und ohne Luecke ('-'). Rueckgabe (rang, von)
    oder (None, None) ohne Aussage - nie geraten."""
    try:
        a = int(float(str(ab).strip()))
        r = int(float(str(raster).strip()))
    except (TypeError, ValueError):
        return (None, None)
    if r not in (900, 3600) or a <= 0 or a % r:
        return (None, None)
    teile = [x.strip() for x in str(vorschau or "").split(";")] if str(vorschau or "").strip() not in ("", "-") else []
    if not teile:
        return (None, None)
    je = 3600 // r
    std = {}
    for i, x in enumerate(teile):
        s = (a + i * r) // 3600 * 3600
        std.setdefault(s, []).append(zahl(x))
    s = int(jetzt) // 3600 * 3600
    mittel = []
    while s in std and len(mittel) < 24:
        werte = std[s]
        if any(w is None for w in werte) or (mittel and len(werte) != je):
            break
        mittel.append(sum(werte) / float(len(werte)))
        s += 3600
    if not mittel:
        return (None, None)
    return (1 + sum(1 for m in mittel if m < mittel[0]), len(mittel))


# ---- G5: Vorklimatisierung nach MG-Regeln (B1, B2, B3, B4) ----
def vorklima_kennung(jetzt: float, in_min: int) -> int:
    """Kennung einer Abfahrt: Abfahrtszeit auf 5 min gerundet (wie PHP round, halbe nach oben)."""
    return int(math.floor((int(jetzt) + int(in_min) * 60) / 300.0 + 0.5) * 300)
def vorklima_neu(k: int) -> dict:
    return {"kennung": int(k), "versuche": 0, "letzter": 0, "erfolg": 0, "abgerufen": 0,
            "aufgegeben": 0, "mild": 0}
def vorklima_schritt(m: dict, in_min, jetzt: float, vorlauf: int, letztes_ok=None):
    """Ein Schritt (rein; Abschrift mg_vorklima_schritt()). Rueckgabe (aktion, neuer_merker),
    aktion nichts | senden | abruf | aufgeben | erledigt.
    - Ausloesen nur bei 1 <= in_min <= vorlauf (0 = ueberfaellig und -1 nie).
    - Eine Kennung, die hoechstens 5 min von der gemerkten abweicht, ist dieselbe Abfahrt.
    - Hoechstens VORKLIMA_VERSUCHE Versuche, Abstand >= VORKLIMA_ABSTAND: 1 senden, 2 Abruf
      (gesendet wird in diesem Schritt nicht), 3 senden; danach aufgeben. Hoechstens zwei "Klima ein".
    - B1: erledigt erst nach einem Erfolg (letztes_ok is True), nie vorher."""
    m = dict(m or {})
    jetzt = int(jetzt)
    if letztes_ok is True and "kennung" in m:
        m["erfolg"] = 1
    try:
        n = int(str(in_min).strip())
    except (TypeError, ValueError):
        return ("nichts", m)
    if n < 1 or n > max(1, int(vorlauf)):
        return ("nichts", m)
    k = vorklima_kennung(jetzt, n)
    if "kennung" not in m or abs(k - int(m["kennung"])) > 300:
        m = vorklima_neu(k)
    for x, v in vorklima_neu(k).items():
        m.setdefault(x, v)
    if m["erfolg"] or m["mild"]:
        return ("erledigt", m)
    if m["aufgegeben"]:
        return ("nichts", m)
    if int(m["versuche"]) >= VORKLIMA_VERSUCHE:
        m["aufgegeben"] = 1
        return ("aufgeben", m)
    if int(m["versuche"]) >= 1:
        if jetzt < int(m["letzter"]):
            m["letzter"] = jetzt          # Uhr zurueck: neu zaehlen, nicht senden
            return ("nichts", m)
        if jetzt - int(m["letzter"]) < VORKLIMA_ABSTAND:
            return ("nichts", m)
    m["versuche"] = int(m["versuche"]) + 1
    m["letzter"] = jetzt
    if m["versuche"] == 2 and not m["abgerufen"]:
        m["abgerufen"] = 1
        return ("abruf", m)
    return ("senden", m)
def vorklima_wert(m, jetzt: float) -> int:
    """Thema fahrzeug<n>/vorklima: 0 nichts/aus, 1 gesendet, 2 mild, 3 wartet auf Wiederholung,
    4 wartet auf Anwesenheit, -1 gescheitert (rein, wie mg_vorklima_wert())."""
    if not isinstance(m, dict) or "kennung" not in m:
        return 0
    if int(m["kennung"]) + 1800 < int(jetzt):
        return 0
    if m.get("aufgegeben"):
        return -1
    if m.get("erfolg"):
        return 1
    if m.get("mild"):
        return 2
    if m.get("warten_anwesend"):
        return 4
    if m.get("sperre"):
        return 5 if m.get("sperre") == "akku" else 6
    if m.get("versuche"):
        return 3
    return 0
def vorklima_wetter(cfg: dict, aussen_lox, alter_lox, aussen_fz, alter_fz) -> str:
    """klimatisieren | mild | ohne_aussage (rein). Zuerst Loxone (<= AUSSEN_LOXONE_FRISCH s), sonst das
    Fahrzeug (Abbild <= VORKLIMA_FAHRZEUG_FRISCH s), sonst ohne_aussage - dann wird klimatisiert
    (nie "nicht klimatisiert" ohne Aussage). Haken aus -> klimatisieren."""
    if not cfg.get("vorklima_wetter_ein"):
        return "klimatisieren"
    wert = None
    for w, a, grenze in ((aussen_lox, alter_lox, AUSSEN_LOXONE_FRISCH),
                         (aussen_fz, alter_fz, VORKLIMA_FAHRZEUG_FRISCH)):
        z, al = zahl(w, 1), zahl(a)
        if z is not None and z > -99 and al is not None and 0 <= al <= grenze:
            wert = z
            break
    if wert is None:
        return "ohne_aussage"
    kalt = zahl(cfg.get("vorklima_kalt_unter"), 1)
    heiss = zahl(cfg.get("vorklima_heiss_ueber"), 1)
    kalt = 5.0 if kalt is None else kalt
    heiss = 25.0 if heiss is None else heiss
    return "mild" if kalt <= wert <= heiss else "klimatisieren"
def aussentemp_lesen(jetzt: float):
    """Die Aussentemperatur aus Loxone (Bruecke): (wert, alter_s) oder (None, -1)."""
    d = json_lesen(DATEI_AUSSEN)
    w, t = zahl(d.get("wert"), 1), zahl(d.get("t"))
    if w is None or t is None or t > jetzt:
        return (None, -1)
    return (w, int(jetzt - t))


def vorklima_minuten(horcher, cfg: dict, jetzt: float):
    """(in_min oder None, Herkunft). Haus-Thema bei frischer Quelle; sonst das bisherige Thema
    <abfahrt_praefix>/ABFAHRT_IN als Rueckfall (E7, entfaellt mit der naechsten Fassung) - mit OK = 1,
    nur LIVE empfangen (B4: retained gilt nie als frisch) und hoechstens abfahrt_alter s alt.
    9999 (kein Termin) und negative Werte (vorbei) sind -1 = keine Abfahrt."""
    if cfg.get("haus_abfahrt_ein") and haus_frisch(horcher.haus, "abfahrt", jetzt):
        v = str(haus_wert(horcher.haus, "abfahrt", "haus/abfahrt/in_min") or "").strip()
        try:
            return (int(float(v)), "haus")
        except ValueError:
            return (-1, "haus")            # '-' oder leer: keine Abfahrt bekannt
    p = str(cfg.get("abfahrt_praefix") or "abfahrt").strip("/")
    if not p:
        return (None, "aus")
    grenze = ganz(cfg.get("abfahrt_alter"), 300)
    ok_roh, _a = horcher.wert(p + "/OK", grenze)
    ai_roh, _b = horcher.wert(p + "/ABFAHRT_IN", grenze)
    if ok_roh is None or ai_roh is None:
        return (None, "still")
    if ganz(ok_roh, 0) != 1:
        return (-1, "thema")
    ai = zahl(ai_roh)
    if ai is None:
        return (None, "thema")
    ai = int(ai)
    return (-1, "thema") if (ai >= 9999 or ai < 0) else (ai, "thema")


def vorklima_takt(horcher, cfg: dict, merker: dict, stand: dict, jetzt: float):
    """Ein Schritt im Wartetakt (Rahmen um vorklima_schritt()). Rueckgabe: Auftrag oder None."""
    if not cfg.get("abfahrt_ein"):
        return None
    nr = str(max(1, ganz(cfg.get("abfahrt_fahrzeug"), 1)))
    vk = merker.setdefault("vorklima", {})
    m = dict(vk.get(nr) or {})
    in_min, herkunft = vorklima_minuten(horcher, cfg, jetzt)
    if in_min is None:
        melde_gebremst("abfahrt_still",
                       "Vorklimatisierung: es kommt keine frische Abfahrtszeit an (haus/abfahrt schweigt oder "
                       "ist aus, und das bisherige Thema ist still oder nur behalten). Es wird nichts ausgeloest.",
                       3600)
        return None
    vorlauf = ganz(cfg.get("abfahrt_vorlauf"), 20)
    if not 1 <= in_min <= vorlauf:
        aktion, m = vorklima_schritt(m, in_min, jetzt, vorlauf, None)
        vk[nr] = m
        return None
    if not cfg.get("steuerung_ein"):
        melde_gebremst("vorklima_steuerung", "Vorklimatisierung: die Abfahrt liegt im Vorlauf, aber "
                       "'Schreibende Befehle zulassen' ist aus - es wird nichts gesendet.", 3600)
        return None
    fz = stand.get("fahrzeuge") or {}
    if nr not in fz:
        melde_gebremst("vorklima_fz_%s" % nr, "Vorklimatisierung: Fahrzeug %s gibt es im Konto (noch) nicht "
                       "(Reiter Einstellungen, 'Fahrzeug') - es wird nichts gesendet." % nr, 3600)
        return None
    k = vorklima_kennung(jetzt, in_min)
    if "kennung" not in m or abs(k - int(m["kennung"])) > 300:
        m = vorklima_neu(k)
    # E8a: live 0 an haus/anwesenheit/jemand -> warten (kein Versuch verbraucht).
    if (cfg.get("vorklima_anwesend_ein") and haus_frisch(horcher.haus, "anwesenheit", jetzt)
            and str(haus_wert(horcher.haus, "anwesenheit", "haus/anwesenheit/jemand")).strip() == "0"
            and not m.get("erfolg") and not m.get("mild") and not m.get("aufgegeben")):
        if not m.get("warten_anwesend"):
            _LOG.info("Vorklimatisierung Fahrzeug %s: niemand zu Hause - sie wartet.", nr)
        m["warten_anwesend"] = 1
        vk[nr] = m
        return None
    m.pop("warten_anwesend", None)
    # 0.9.31 (Form MGiSmart 1.1.31): Mindest-Akku und "nur mit Kabel". Kein Versuch wird verbraucht, unbekannt sperrt nie.
    sperre = "" if (m.get("versuche") or m.get("erfolg") or m.get("mild")) else vorklima_sperre(cfg, fz.get(nr) or {})
    if sperre:
        if m.get("sperre") != sperre:
            _LOG.info("Vorklimatisierung Fahrzeug %s: ausgesetzt (%s).", nr,
                      "Akku unter der Grenze" if sperre == "akku" else "Kabel steckt nicht")
        m["sperre"] = sperre
        vk[nr] = m
        return None
    m.pop("sperre", None)
    aktion, m = vorklima_schritt(m, in_min, jetzt, vorlauf, None)
    if aktion in ("senden", "abruf") and int(m.get("versuche", 0)) == 1 and not m.get("wetter"):
        m["wetter"] = 1
        aw, al = aussentemp_lesen(jetzt)
        # BYD liefert keine Aussentemperatur des Fahrzeugs (nur INNENTEMP) - Quelle nur Loxone.
        if vorklima_wetter(cfg, aw, al, None, -1) == "mild":
            m.update({"mild": 1, "versuche": 0, "letzter": 0})
            vk[nr] = m
            _LOG.info("Vorklimatisierung Fahrzeug %s: mild (%s Grad aus Loxone) - keine Klimatisierung.", nr, aw)
            return None
    vk[nr] = m
    if aktion == "senden":
        _LOG.info("Vorklimatisierung Fahrzeug %s: Abfahrt in %s min (Quelle %s), Versuch %s, Zieltemperatur %s Grad.",
                  nr, in_min, herkunft, m["versuche"], cfg.get("abfahrt_temp"))
        return {"aktion": "klima_start", "fahrzeug": nr, "temp": cfg.get("abfahrt_temp", 21), "anlass": "abfahrt"}
    if aktion == "abruf":
        _LOG.info("Vorklimatisierung Fahrzeug %s: Versuch 2 ist ein Abruf.", nr)
        return {"aktion": "abruf", "anlass": "abfahrt"}
    if aktion == "aufgeben":
        text = "BYD Autos: die Vorklimatisierung fuer Fahrzeug %s ist gescheitert (zwei Versuche)." % nr
        melde_gebremst("vorklima_%s" % m.get("kennung"), text, 86400)
        signal_senden(cfg, "vorklima", nr, text)
    return None


def vorklima_ergebnis(merker: dict, auftrag: dict, ok) -> None:
    """B1: der Merker gilt als erledigt erst nach ok 1 von befehl_ausfuehren()."""
    if auftrag.get("aktion") != "klima_start":
        return
    m = (merker.get("vorklima") or {}).get(str(auftrag.get("fahrzeug")))
    if isinstance(m, dict) and ok == 1:
        m["erfolg"] = 1


def vorklima_melden(cfg: dict, merker: dict, jetzt: float) -> None:
    """fahrzeug<n>/VORKLIMA bei jedem Wechsel sofort senden (nicht erst mit dem naechsten Abruf), nie retained."""
    nr = str(max(1, ganz(cfg.get("abfahrt_fahrzeug"), 1)))
    w = vorklima_wert((merker.get("vorklima") or {}).get(nr), jetzt) if cfg.get("abfahrt_ein") else 0
    alt = dict(_G42["vorklima"])
    _G42["vorklima"] = {nr: w}
    if cfg.get("mqtt_ein") and alt.get(nr, 0) != w:
        mqtt_senden({"fahrzeug%s/VORKLIMA" % nr: w}, mqtt_thema_saeubern(cfg.get("mqtt_topic") or "byd"))


# ---- G4/E8b: Abruf bei Ankunft ----
def ankunft_flanke(alt, wert, frisch: bool) -> dict:
    """Live-Flanke 0 -> 1 zaehlen (rein, wie mg_ankunft_flanke())."""
    wert = "" if wert is None else str(wert).strip()
    if not isinstance(alt, dict) or "wert" not in alt:
        return {"wert": wert, "frisch": bool(frisch), "nr": 0}
    nr = int(alt.get("nr") or 0)
    if alt.get("frisch") and frisch and str(alt.get("wert")) == "0" and wert == "1":
        nr += 1
    return {"wert": wert, "frisch": bool(frisch), "nr": nr}
def ankunft_entscheiden(flanke_nr, ausgefuehrt, letzte_abfrage, jetzt, stand_alter):
    """(aktion, neue_ausgefuehrt) mit aktion nichts | abfragen | gedrosselt | frisch (rein, wie
    mg_ankunft_entscheiden(); Alter in Sekunden). Jede Flanke genau einmal."""
    if int(flanke_nr) <= int(ausgefuehrt):
        return ("nichts", int(ausgefuehrt))
    neu = int(flanke_nr)
    if int(letzte_abfrage) > 0 and int(jetzt) >= int(letzte_abfrage) \
            and int(jetzt) - int(letzte_abfrage) < ANKUNFT_ABSTAND:
        return ("gedrosselt", neu)
    if stand_alter is not None and 0 <= int(stand_alter) <= ANKUNFT_STAND_ALT:
        return ("frisch", neu)
    return ("abfragen", neu)
def ankunft_takt(horcher, cfg: dict, merker: dict, stand: dict, jetzt: float) -> bool:
    """True, wenn wegen einer Ankunft abgefragt werden soll. Der Sofortabruf ist kontoweit (ein Durchgang
    fuer alle Fahrzeuge) - deshalb eine Quelle je Konto, wie bei Audi."""
    if not cfg.get("ankunft_ein"):
        return False
    a = merker.setdefault("ankunft", {})
    fl = a.setdefault("flanken", {})
    frisch = haus_frisch(horcher.haus, "anwesenheit", jetzt)
    soll = False
    stand_alter = int(jetzt - ganz(stand.get("ts"), 0)) if ganz(stand.get("ts"), 0) > 0 else None
    for t in ankunft_themen(cfg.get("ankunft_quelle")):
        fl[t] = ankunft_flanke(fl.get(t), haus_wert(horcher.haus, "anwesenheit", t), frisch)
        aktion, neu = ankunft_entscheiden(fl[t]["nr"], int((a.get("ausgefuehrt") or {}).get(t, 0)),
                                          int(a.get("letzte") or 0), jetzt, stand_alter)
        if aktion != "nichts":
            a.setdefault("ausgefuehrt", {})[t] = neu
            _LOG.info("Ankunft (%s): %s.", t, {"abfragen": "Abruf", "gedrosselt": "kein Abruf - der letzte "
                      "liegt keine 30 Minuten zurueck", "frisch": "kein Abruf - der Stand ist juenger als "
                      "5 Minuten"}[aktion])
        if aktion == "abfragen":
            soll = True
    if soll:
        a["letzte"] = int(jetzt)
    return soll


# --- LADEKOSTEN-KERN G41 (wortgleich in audi.py, skoda.py, vw.py) ---
LADUNG_TAKT_S = 60          # eine Probe je Minute; die erste steht fuer 60 s
LADUNG_LUECKE_S = 120       # ein groesserer Abstand ist eine Luecke ohne Energie
LADUNG_ABDECKUNG = 0.9      # fehlt der Preis fuer mehr als 10 % der Energie: Kosten -1, nie geschaetzt


def _ladung_zahl(w):
    if w is None or isinstance(w, bool):
        return None
    try:
        return float(str(w).strip().replace(",", "."))
    except (TypeError, ValueError):
        return None


def preis_zum_zeitpunkt(vorschau, ab, raster, aktuell, frisch, t):
    """Endpreis in ct/kWh zum Zeitpunkt t (rein, wie mg_preis_zum_zeitpunkt()): vorschau[(t - ab) // raster]
    bei Raster 900/3600 und ab im Raster; sonst aktuell, aber nur bei frischer Quelle; sonst None."""
    import re
    v = vorschau.strip() if isinstance(vorschau, str) else ""
    if v and len(v) <= 4096 and re.fullmatch(r"-?\d{1,4}(\.\d{1,3})?(;-?\d{1,4}(\.\d{1,3})?)*", v):
        a, r = _ladung_zahl(ab), _ladung_zahl(raster)
        if a is not None and r is not None and int(r) in (900, 3600) and int(a) > 0 \
                and int(a) % int(r) == 0 and int(t) >= int(a):
            liste = v.split(";")
            i = (int(t) - int(a)) // int(r)
            if i < len(liste):
                return float(liste[i])
    if frisch:
        return _ladung_zahl(aktuell)
    return None


def ladung_kosten(proben, kwh_gemeldet=None):
    """Kosten einer Ladung aus Minutenproben [t, kw, preis] (rein).

    Jede Probe steht fuer die Zeit seit der vorigen (die erste fuer LADUNG_TAKT_S); ein Abstand ueber
    LADUNG_LUECKE_S ist eine Luecke. Gewicht = gemeldete Ladeleistung x Dauer; meldet keine Probe eine
    Leistung, wird nach Dauer verteilt (Renault 2.1.20, Abweichung 4b). Energie = kwh_gemeldet (Akkustand-
    Differenz x Kapazitaet), sonst die Summe der Leistungsgewichte. Abdeckung = Anteil des Gewichts mit
    Preis; unter LADUNG_ABDECKUNG Kosten und Preis -1 (nie geschaetzt).
    Rueckgabe (kwh, kosten_eur, preis_ct, abdeckung, grund)."""
    p = {}
    for x in proben or []:
        try:
            t = int(x[0])
        except (TypeError, ValueError, IndexError):
            continue
        p.setdefault(t, x)
    mit_kw = any((_ladung_zahl(x[1]) or 0) > 0 for x in p.values() if len(x) > 1)
    gesamt = mit = cent = 0.0
    vorher = None
    for t in sorted(p):
        x = p[t]
        dt = LADUNG_TAKT_S if vorher is None else t - vorher
        vorher = t
        if dt <= 0 or dt > LADUNG_LUECKE_S:
            continue
        kw = _ladung_zahl(x[1]) if len(x) > 1 else None
        if mit_kw and (kw is None or kw <= 0):
            continue
        g = (kw if mit_kw else 1.0) * dt / 3600.0
        gesamt += g
        pr = _ladung_zahl(x[2]) if len(x) > 2 else None
        if pr is not None:
            mit += g
            cent += pr * g
    kwh = _ladung_zahl(kwh_gemeldet)
    if kwh is None or kwh <= 0:
        kwh = gesamt if mit_kw else None
    if gesamt <= 0 or kwh is None or kwh <= 0:
        return (-1, -1, -1, 0, "leistung_fehlt")
    abd = mit / gesamt
    if abd < LADUNG_ABDECKUNG:
        return (round(kwh, 3), -1, -1, round(abd, 3), "preis_fehlt")
    preis = cent / mit
    return (round(kwh, 3), round(kwh * preis / 100.0, 2), round(preis, 1), round(abd, 3), "ok")


# Offene Mitschrift ueber einen Neustart retten (Zusatz VW 0.9.33, Hausherr 29.10.; danach wortgleich in
# audi.py und skoda.py zurueckzutragen). Datei je Linie, Inhalt {"fassung": 1, "t": <unix>, "offen":
# {<schluessel>: {..., "start": <unix>, "proben": [[t, kw, preis], ...]}}} - der Schluessel ist die
# Fahrzeugnummer der Linie, der Eintrag gehoert der Linie (nur start und proben prueft der Kern).
LADUNG_MITSCHRIFT_ALTER = 43200   # eine Mitschrift, deren letzte Probe aelter als 12 h ist, wird verworfen


def ladung_mitschrift_speichern(pfad, offen, jetzt) -> bool:
    """Die offenen Mitschriften atomar schreiben (Nebendatei, 0600, os.replace). False bei Fehler."""
    import json as _json
    import os as _os
    tmp = "%s.tmp.%d" % (pfad, _os.getpid())
    try:
        text = _json.dumps({"fassung": 1, "t": int(jetzt), "offen": offen}, ensure_ascii=False, sort_keys=True)
        fd = _os.open(tmp, _os.O_WRONLY | _os.O_CREAT | _os.O_TRUNC, 0o600)
        with _os.fdopen(fd, "w", encoding="utf-8") as h:
            h.write(text)
            h.flush()
            _os.fsync(h.fileno())
        _os.replace(tmp, str(pfad))
        return True
    except (OSError, TypeError, ValueError):
        try:
            _os.unlink(tmp)     # nur die eigene Nebendatei
        except OSError:
            pass
        return False


def ladung_mitschrift_laden(pfad, jetzt):
    """(offen, lage) mit lage keine | ok | teils_verworfen | beschaedigt. Verworfen wird ein Eintrag ohne
    gueltigen Beginn, mit Proben in der Zukunft (> 300 s, Uhr zurueck) oder dessen letzte Zeit aelter als
    LADUNG_MITSCHRIFT_ALTER ist; eine unlesbare Datei gilt als leer (beschaedigt - der Aufrufer meldet es)."""
    import json as _json
    import os as _os
    if not _os.path.exists(str(pfad)):
        return ({}, "keine")
    try:
        with open(str(pfad), encoding="utf-8") as h:
            d = _json.loads(h.read())
        roh = d["offen"]
        if not isinstance(roh, dict) or d.get("fassung") != 1:
            raise ValueError
    except (OSError, ValueError, KeyError, TypeError):
        return ({}, "beschaedigt")
    offen, weg = {}, 0
    for k, e in roh.items():
        try:
            start = int(e["start"])
            proben = [p for p in e.get("proben") or [] if isinstance(p, list) and len(p) >= 3]
            zuletzt = max([start] + [int(p[0]) for p in proben])
        except (TypeError, ValueError, KeyError, AttributeError):
            weg += 1
            continue
        if start <= 0 or zuletzt > int(jetzt) + 300 or int(jetzt) - zuletzt > LADUNG_MITSCHRIFT_ALTER:
            weg += 1
            continue
        e = dict(e)
        e["proben"] = proben[-2000:]
        offen[str(k)] = e
    return (offen, "teils_verworfen" if weg else "ok")
# --- ENDE LADEKOSTEN-KERN ---


def ladung_mitschrift_sichern(jetzt: float) -> None:
    """Die offenen Mitschriften in DATEI_MITSCHRIFT (Kern: atomar, 0600)."""
    if not ladung_mitschrift_speichern(DATEI_MITSCHRIFT, _G42["mitschrift"], jetzt):
        melde_gebremst("mitschrift_schreiben", "Ladekosten: die offene Mitschrift liess sich nicht speichern - "
                       "ein Neustart waehrend der Ladung verliert sie.", 3600)
def ladung_mitschrift_holen(jetzt: float) -> None:
    """Beim Dienststart: offene Mitschriften zurueckholen (Kern); beschaedigt oder zu alt -> verworfen, gemeldet."""
    offen, lage = ladung_mitschrift_laden(DATEI_MITSCHRIFT, jetzt)
    _G42["mitschrift"] = offen
    if lage == "beschaedigt":
        melde_gebremst("mitschrift_kaputt", "Ladekosten: die gespeicherte Mitschrift ist beschaedigt und wird "
                       "verworfen - eine laufende Ladung bekommt keine Kosten (-1).", 3600)
    elif lage == "teils_verworfen":
        _LOG.info("Ladekosten: eine gespeicherte Mitschrift war aelter als 12 h oder ungueltig und wurde verworfen.")
    elif offen:
        _LOG.info("Ladekosten: %d offene Mitschrift(en) nach dem Neustart uebernommen.", len(offen))


def ladung_beginnen(nummer, d: dict, cfg: dict, jetzt: float) -> None:
    """Beim Ladebeginn (abgeleitetes_ergaenzen()): Mitschrift anlegen. 1 zu Hause, 0 unterwegs, -1 ohne
    Heimzone, -2 aus. BYD meldet keine Ladeleistung - die Proben tragen None (Gewicht nach Dauer)."""
    z = d.get("ZUHAUSE")
    haus = ((1 if z == 1 else (0 if z == 0 else -1)) if cfg.get("haus_strompreis_ein") else -2)
    _G42["mitschrift"][str(nummer)] = {"start": int(jetzt), "haus": haus, "proben": [], "kw_jetzt": None}
    ladung_mitschrift_sichern(jetzt)


def ladung_probe(horcher, cfg: dict, jetzt: float) -> None:
    """Je Minute einer Ladung zu Hause eine Probe [t, kW, Preis] in die offene Mitschrift (und in die Datei)."""
    if not cfg.get("haus_strompreis_ein"):
        return
    h = horcher.haus
    frisch = haus_frisch(h, "strompreis", jetzt)
    neu = False
    for offen in _G42["mitschrift"].values():
        if offen.get("haus") != 1:
            continue
        l = offen.setdefault("proben", [])
        if l and int(jetzt) - int(l[-1][0]) < LADUNG_TAKT_S - 5:
            continue
        preis = preis_zum_zeitpunkt(haus_wert(h, "strompreis", "haus/strompreis/vorschau"),
                                    haus_wert(h, "strompreis", "haus/strompreis/ab"),
                                    haus_wert(h, "strompreis", "haus/strompreis/raster"),
                                    haus_wert(h, "strompreis", "haus/strompreis/aktuell"), frisch, jetzt)
        l.append([int(jetzt), offen.get("kw_jetzt"), preis])
        del l[:-2000]
        neu = True
    if neu:
        ladung_mitschrift_sichern(jetzt)
def ladung_kosten_abschliessen(nummer, kwh, jetzt: float):
    """Beim Ladeende (fortschreiben()): (kosten, preis) fuer CSV und Themen; -1 ohne Aussage, nie geschaetzt.
    Energie = Akkustand-Differenz x Kapazitaet (wie bisher kwh), sonst gemeldete Leistung x Zeit."""
    offen = _G42["mitschrift"].pop(str(nummer), None)
    if offen is None:
        kwh_k, kosten, preis, grund, lh = -1, -1, -1, "mitschrift_fehlt", None
    else:
        lh = offen.get("haus", -2)
        if lh == 1:
            kwh_k, kosten, preis, _abd, grund = ladung_kosten(offen.get("proben") or [], kwh)
        else:
            kwh_k, kosten, preis = -1, -1, -1
            grund = {0: "unterwegs", -1: "heimzone_fehlt"}.get(lh, "aus")
        ladung_mitschrift_sichern(jetzt)
    _G42["ladung"][str(nummer)] = {"kosten": kosten, "preis": preis}
    json_schreiben(DATEI_LADEKOSTEN, _G42["ladung"], 0o600)
    if lh in (0, 1, -1) or grund == "mitschrift_fehlt":
        _LOG.info("Fahrzeug %s: Ladekosten %s EUR, Preis %s ct/kWh, %s kWh (%s).", nummer, kosten, preis, kwh_k, grund)
    return (kosten, preis)


def ladung_felder(nummer, d: dict) -> None:
    """Die drei Fahrzeugthemen der Gruppe 42 ins Abbild: LADEKOSTEN/LADEPREIS (-1 ohne Aussage), VORKLIMA."""
    e = _G42["ladung"].get(str(nummer)) or {}
    for feld, k in (("LADEKOSTEN", "kosten"), ("LADEPREIS", "preis")):
        w = e.get(k)
        d[feld] = -1 if w is None else w
    d["VORKLIMA"] = _G42["vorklima"].get(str(nummer), 0)


# ---- G8: Meldungen an SignalBot (E10, wie Renault X4) ----
def signal_nutzlast(text: str, dringend: int = 0) -> str:
    """JSON {"text", "dringend"} fuer signalbot/befehl/plugin/<ordner> (rein), hoechstens 4000 Byte."""
    t = str(text or "").replace("\r", " ")
    while True:
        s = json.dumps({"text": t, "dringend": 1 if dringend else 0}, ensure_ascii=False)
        if len(s.encode("utf-8")) <= 4000 or len(t) <= 1:
            return s
        t = t[:max(1, len(t) - max(1, len(s.encode("utf-8")) - 4000) - 2)] + "…"
def signal_thema(praefix, ordner) -> str:
    """'<praefix>/befehl/plugin/<ordner>' oder '' (ungueltig)."""
    p, o = str(praefix or ""), str(ordner or "")
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,40}", p) or not re.fullmatch(r"[a-z0-9_]{1,40}", o):
        return ""
    return "%s/befehl/plugin/%s" % (p, o)


# ---------------------------------------------------------------------------
# Sammelthemen haus/fahrzeug/<name>/... (0.9.31; Regeln/07, Vorbild MGiSmart 1.1.31)
#
# soc, reichweite_km, kabel, laedt, zuhause: retained, ohne Aussage "-" (nie 0, nie leer; Entscheidung Nr. 5).
# zaehler: 0...999 je Minute, NIE retained. Eine kurze Sitzung direkt am Broker mit qos 1, nicht ueber den
# UDP-Eingang (Regeln/07 "Technik"); unabhaengig vom Haken mqtt_ein und vom eigenen Praefix. Zustaende bei einer
# Aenderung und alle 30 Minuten voll, der Zaehler aus dem Wartetakt, also auch zwischen zwei Abrufen.
# Kein Standort, keine VIN. Ein Stand, der aelter als HAUS_FZ_ALT_S ist, ist keine Aussage mehr: "-".
# Abraeumen mit Nachlesen: Haken aus, geaenderter Name, Fahrzeug nicht mehr im Konto, Deinstallation.
# ---------------------------------------------------------------------------
DATEI_HAUS_FZ = PDATA / "haus_fahrzeug.json"
DATEI_KABEL = PDATA / "kabel.json"
HAUS_FZ_FELDER = ("soc", "reichweite_km", "kabel", "laedt", "zuhause")
HAUS_FZ_TAKT_S = 55            # der Wartetakt ruft alle 10 s; so entsteht ein Minutentakt
HAUS_FZ_VOLL_S = 1800
HAUS_FZ_ALT_S = 21600          # 6 h ohne gelungenen Abruf: keine Aussage mehr
KABEL_FRISCH_S = 43200         # die Warnung braucht einen Fahrzeugstand bis 12 h (wie MGiSmart)
DATEI_HAUS_FZ_FREMD = PDATA / "haus_fahrzeug_fremd.json"
HAUS_FZ_LAUSCH_S = 62          # so lange hoert die Pruefung auf fremde Zaehler: jede Linie sendet ihn einmal je Minute
HAUS_FZ_LAUSCH_ABSTAND_S = 3600
_HAUS_FZ_SITZUNG = None        # Nahtstelle fuer Proben: Funktion (nachrichten, lesen, warten, gesehen) -> (ok, behalten)
_HAUS_FZ = {"zaehler": -1, "letzt": 0.0, "lausch": 0.0, "eigene": {}}


def haus_fz_name(name, nr) -> str:
    """<name> nach der Namensregel von Regeln/07; reserviert: zaehler, anbieter. Sonst by<n>. Rein."""
    s = str(name or "")
    for a, b in (("\u00c4", "ae"), ("\u00d6", "oe"), ("\u00dc", "ue"), ("\u00e4", "ae"), ("\u00f6", "oe"),
                 ("\u00fc", "ue"), ("\u00df", "ss")):
        s = s.replace(a, b)
    s = "".join(c if ("a" <= c <= "z" or "0" <= c <= "9" or c in "_-") else "_" for c in s.lower())[:40]
    if s.strip("_") == "" or s in ("zaehler", "anbieter"):
        return "by%s" % nr
    return s


def haus_fz_namen(fahrzeuge: dict) -> dict:
    """Nummer -> <name>, je Fahrzeug eindeutig (ein doppelter Name bekommt _<n>). Rein."""
    aus: dict = {}
    for nr in sorted(fahrzeuge, key=lambda n: ganz(n, 0)):
        f = fahrzeuge.get(nr) if isinstance(fahrzeuge.get(nr), dict) else {}
        n = haus_fz_name(f.get("name") or f.get("modell"), nr)
        aus[str(nr)] = "%s_%s" % (n[:36], nr) if n in aus.values() else n
    return aus


def haus_fz_zahl(w) -> str:
    """Zahl >= 0 mit hoechstens einer Nachkommastelle, sonst "-". Rein."""
    if isinstance(w, bool):
        return "-"
    try:
        v = float(w)
    except (TypeError, ValueError):
        return "-"
    if v != v or v < 0 or v == float("inf"):
        return "-"
    return ("%.1f" % v).rstrip("0").rstrip(".")


def haus_fz_ja(w) -> str:
    """Nur 1 oder 0 ist eine Aussage. Rein."""
    return str(int(w)) if isinstance(w, (int, float)) and w in (0, 1) else "-"


def haus_fz_alter(f: dict, stand: dict, jetzt: float):
    """Sekunden seit dem letzten gelungenen Abruf dieses Fahrzeugs, None = unbekannt. Rein."""
    ts = ganz(f.get("ts"), 0) or ganz((stand or {}).get("ts"), 0)
    return None if ts <= 0 else jetzt - ts


def haus_fz_werte(f: dict, stand: dict, jetzt: float) -> dict:
    """Die retained Zustaende eines Fahrzeugs. Ein zu alter oder unbekannter Stand ist ohne Aussage. Rein."""
    alter = haus_fz_alter(f, stand, jetzt)
    if alter is None or alter > HAUS_FZ_ALT_S:
        return {n: "-" for n in HAUS_FZ_FELDER}
    soc = haus_fz_zahl(f.get("SOC"))
    if soc != "-" and float(soc) > 100:
        soc = "-"
    return {"soc": soc, "reichweite_km": haus_fz_zahl(f.get("REICHW")),
            "kabel": haus_fz_ja(f.get("KABEL")), "laedt": haus_fz_ja(f.get("LAEDT")),
            "zuhause": haus_fz_ja(f.get("ZUHAUSE"))}


def haus_fz_sitzung(nachrichten: list, lesen=None, warten: float = 0.0, gesehen=None) -> tuple:
    """Eine kurze Sitzung am Broker (Anmeldung aus general.json, nie in einer Befehlszeile).
    nachrichten: [(thema, nutzlast, retain)], qos 1, gewartet wird auf die Bestaetigung (hoechstens 8 s).
    lesen: Themen, deren retained Werte danach gefragt werden. gesehen: Liste, die die fluechtigen Nachrichten dieser
    Themen aufnimmt (thema, wert). Rueckgabe (ok, {thema: wert})."""
    if _HAUS_FZ_SITZUNG is not None:
        return _HAUS_FZ_SITZUNG(nachrichten, lesen, warten, gesehen)
    try:
        import threading  # noqa: PLC0415
        import paho.mqtt.client as mqtt  # noqa: PLC0415
    except ImportError:
        melde_gebremst("haus_fz_paho", "haus/fahrzeug nicht gesendet: paho-mqtt fehlt.", 86400)
        return (False, {})
    b = broker_anmeldung()
    # Jede Sitzung ihre eigene Kennung: die Pruefung auf fremde Zaehler laeuft neben dem Minutentakt, und eine doppelte Kennung wirft der Broker hinaus.
    kennung = "bydautos-hausfz-%d-%d" % (os.getpid(), int(time.time() * 1000) % 1000000)
    try:
        k = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=kennung)     # paho 2.x
    except (AttributeError, TypeError):
        k = mqtt.Client(client_id=kennung)                                       # paho 1.x
    if b["auth"]:
        k.username_pw_set(b["auth"]["username"], b["auth"]["password"])
    da, abo = threading.Event(), threading.Event()
    antwort: dict = {"rc": None}
    behalten: dict = {}

    def bei_verbindung(_k, _u, _f, rc, *_r):
        antwort["rc"] = getattr(rc, "value", rc)
        da.set()

    def bei_nachricht(_k, _u, n):
        if getattr(n, "retain", False):
            if n.payload:
                behalten[n.topic] = n.payload.decode("utf-8", "replace")
        elif gesehen is not None:
            gesehen.append((n.topic, n.payload.decode("utf-8", "replace")))

    def bei_abo(*_a):
        abo.set()

    k.on_connect, k.on_message, k.on_subscribe = bei_verbindung, bei_nachricht, bei_abo
    laeuft = False
    try:
        k.connect(b["host"], b["port"], 30)
        k.loop_start()
        laeuft = True
        if not da.wait(5) or antwort["rc"] != 0:
            raise ConnectionError("Broker antwortet nicht oder weist ab")
        marken = [k.publish(t, w if w != "" else None, qos=1, retain=bool(r)) for t, w, r in nachrichten]
        ende = time.time() + 8
        for m in marken:
            while not m.is_published():
                if time.time() > ende:
                    raise TimeoutError("keine Bestaetigung")
                time.sleep(0.02)
        if lesen:
            k.subscribe([(t, 0) for t in lesen])
            abo.wait(3)
            time.sleep(max(0.0, warten))
        return (True, behalten)
    except Exception as err:  # noqa: BLE001
        melde_gebremst("haus_fz_senden", "haus/fahrzeug: Sitzung am Broker gescheitert (%s)." % type(err).__name__, 3600)
        return (False, {})
    finally:
        try:
            k.disconnect()
            if laeuft:
                k.loop_stop()
        except Exception:  # noqa: BLE001
            pass


def haus_fz_staemme(merker: dict) -> list:
    """Die Staemme aus dem Merker, nur unterhalb von haus/fahrzeug/. Rein."""
    aus: list = []
    fz = merker.get("fahrzeuge") if isinstance(merker, dict) else None
    for e in (fz.values() if isinstance(fz, dict) else ()):
        s = e.get("stamm") if isinstance(e, dict) else None
        if isinstance(s, str) and s.startswith("haus/fahrzeug/") and s not in aus:
            aus.append(s)
    return aus


def haus_fz_abraeumen(staemme: list, warten: float = 3.0) -> tuple:
    """Die retained Themen dieser Staemme loeschen und nachlesen. Rueckgabe (rc, Zahl, Rest): 0 bestaetigt, 1 Rest, 2 Broker."""
    themen = [s + "/" + n for s in staemme for n in HAUS_FZ_FELDER]
    if not themen:
        return (0, 0, [])
    ok, _b = haus_fz_sitzung([(t, "", True) for t in themen])
    if not ok:
        return (2, len(themen), themen)
    ok, b = haus_fz_sitzung([], themen, warten)
    if not ok:
        return (2, len(themen), themen)
    rest = sorted(t for t in b if t in themen)
    return (1 if rest else 0, len(themen), rest)


def haus_fz_fremde(staemme: list, warten: float, eigene=None) -> tuple:
    """Hoert warten Sekunden auf <stamm>/zaehler. Ein Wert, den diese Linie nicht gesendet hat, ist ein fremder Zaehler: Unter
    diesem Stamm sendet noch eine andere Linie. Rueckgabe (ok, Liste der Staemme mit fremdem Zaehler)."""
    gesehen: list = []
    ok, _b = haus_fz_sitzung([], [s + "/zaehler" for s in staemme], warten, gesehen)
    if not ok:
        return (False, [])
    eigene = eigene or {}
    aus: list = []
    for t, w in gesehen:
        s = t.rsplit("/", 1)[0]
        if s in staemme and s not in aus and w not in eigene.get(s, ()):
            aus.append(s)
    return (True, aus)


def haus_fz_fremd_lesen() -> dict:
    """Die zuletzt gefundenen Staemme mit fremdem Zaehler: {stamm: Zeit}."""
    d = json_lesen(DATEI_HAUS_FZ_FREMD)
    return {k: v for k, v in d.items() if isinstance(k, str) and k.startswith("haus/fahrzeug/")}


def haus_fz_lausch_lauf(staemme: list) -> None:
    """Einmal je Stunde neben dem Minutentakt. Das Ergebnis liest der Reiter Test; ein sauberer Lauf nimmt den Vermerk zurueck."""
    ok, fremd = haus_fz_fremde(staemme, HAUS_FZ_LAUSCH_S, _HAUS_FZ["eigene"])
    if not ok:
        return
    alt = haus_fz_fremd_lesen()
    neu = {s: int(time.time()) for s in fremd}
    if neu or alt:
        json_schreiben(DATEI_HAUS_FZ_FREMD, neu, 0o600)
    for s in fremd:
        if s not in alt:
            _LOG.warning("%s: hier sendet auch eine andere Linie (fremder Zaehler). Bitte einem Fahrzeug einen anderen Namen geben.", s)


def haus_fz_leeren(warten: float = 3.0, lausch=None) -> int:
    """Deinstallation: die Staemme aus dem Merker und aus dem letzten Stand. Der eigene Dienst ist angehalten: Laeuft unter einem
    Stamm noch ein Zaehler, gehoert er einer anderen Linie, und ihre Themen bleiben stehen. Ausgabe im Format des Installers."""
    staemme = haus_fz_staemme(json_lesen(DATEI_HAUS_FZ))
    fz = json_lesen(DATEI_LOXONE).get("fahrzeuge")
    if isinstance(fz, dict):
        for n in haus_fz_namen(fz).values():
            if "haus/fahrzeug/" + n not in staemme:
                staemme.append("haus/fahrzeug/" + n)
    if not staemme:
        print("<INFO> MQTT: kein Fahrzeug bekannt - unter haus/fahrzeug ist nichts abzuraeumen.")
        return 0
    ok, behalten = haus_fz_sitzung([], [s + "/" + n for s in staemme for n in HAUS_FZ_FELDER], warten)
    if not ok:
        print("<WARNING> MQTT: der Broker war nicht zu erreichen - unter haus/fahrzeug kann noch etwas stehen.")
        return 1
    staemme = [s for s in staemme if any(t.startswith(s + "/") for t in behalten)]
    if not staemme:
        print("<INFO> MQTT: unter haus/fahrzeug steht nichts von diesem Plugin - nachgemessen.")
        return 0
    ok, fremd = haus_fz_fremde(staemme, HAUS_FZ_LAUSCH_S if lausch is None else lausch)
    for s in fremd:
        print("<WARNING> MQTT: unter %s/ sendet noch eine andere Linie (fremder Zaehler) - diese Themen bleiben stehen." % s)
    staemme = [s for s in staemme if s not in fremd]
    if not staemme:
        return 0
    rc, n, rest = haus_fz_abraeumen(staemme, warten)
    if rc == 0:
        print("<OK> MQTT: %d Themen unter haus/fahrzeug geleert (vom Broker bestaetigt)." % n)
        return 0
    if rc == 1:
        print("<WARNING> MQTT: %d Themen unter haus/fahrzeug stehen weiter im Broker." % len(rest))
        return 1
    print("<WARNING> MQTT: der Broker war nicht zu erreichen - unter haus/fahrzeug kann noch etwas stehen.")
    return 1


def haus_fz_takt(cfg: dict, stand: dict, jetzt: float) -> int:
    """Je Minute: Zustaende bei einer Aenderung und alle 30 min voll, der Zaehler immer. Rueckgabe 1 = gesendet."""
    merker = json_lesen(DATEI_HAUS_FZ)
    fremd = haus_fz_fremd_lesen()
    if not cfg.get("haus_fahrzeug_ein"):
        if DATEI_HAUS_FZ.exists():
            # Ein Stamm mit fremdem Zaehler gehoert auch einer anderen Linie: Er bleibt stehen.
            rc, n, _rest = haus_fz_abraeumen([s for s in haus_fz_staemme(merker) if s not in fremd], 1.5)
            if rc == 0:
                _LOG.info("haus/fahrzeug: Haken aus, %d Themen abgeraeumt.", n)
                try:
                    DATEI_HAUS_FZ.unlink()
                except OSError:
                    pass
            else:
                melde_gebremst("haus_fz_aus", "haus/fahrzeug: Haken aus, das Abraeumen gelang noch nicht.", 3600)
        return 0
    fahrzeuge = (stand or {}).get("fahrzeuge") or {}
    if not fahrzeuge:
        return 0                     # kein Fahrzeug: der Zaehler schweigt, das ist die Aussage
    namen = haus_fz_namen(fahrzeuge)
    alt = merker.get("fahrzeuge") if isinstance(merker.get("fahrzeuge"), dict) else {}
    aktuell = ["haus/fahrzeug/" + n for n in namen.values()]
    weg = [s for s in haus_fz_staemme(merker) if s not in aktuell and s not in fremd]
    nachrichten = [(s + "/" + n, "", True) for s in weg for n in HAUS_FZ_FELDER]
    _HAUS_FZ["zaehler"] = (_HAUS_FZ["zaehler"] + 1) % 1000
    neu: dict = {}
    for nr, name in namen.items():
        stamm = "haus/fahrzeug/" + name
        e = alt.get(nr) if isinstance(alt.get(nr), dict) else {}
        werte = haus_fz_werte(fahrzeuge.get(nr) or fahrzeuge.get(int(nr)) or {}, stand, jetzt)
        vorher = e.get("werte") if isinstance(e.get("werte"), dict) else None
        voll = e.get("stamm") != stamm or vorher is None or jetzt - ganz(e.get("zeit"), 0) > HAUS_FZ_VOLL_S
        for n, w in werte.items():
            if voll or str(vorher.get(n)) != w:
                nachrichten.append((stamm + "/" + n, w, True))
        nachrichten.append((stamm + "/zaehler", str(_HAUS_FZ["zaehler"]), False))
        _HAUS_FZ["eigene"][stamm] = (_HAUS_FZ["eigene"].get(stamm, []) + [str(_HAUS_FZ["zaehler"])])[-5:]
        neu[nr] = {"stamm": stamm, "zeit": int(jetzt) if voll else ganz(e.get("zeit"), 0), "werte": werte}
    ok, _b = haus_fz_sitzung(nachrichten)
    if not ok:
        return 0                     # nichts gemerkt: der naechste Lauf sendet es noch einmal
    if neu != alt:
        json_schreiben(DATEI_HAUS_FZ, {"fahrzeuge": neu}, 0o600)
    return 1


def kabel_regeln(cfg: dict) -> tuple:
    """Die Nachtruhe aus den Regeln des Stamms (Block ansage_regeln der Konfiguration): (an, von, bis). Fehlt er oder ist er verbogen: 22 bis 7 Uhr."""
    r = cfg.get("ansage_regeln")
    if isinstance(r, dict):
        v, b = str(r.get("von") or ""), str(r.get("bis") or "")
        if len(v) == 5 and len(b) == 5 and v[2] == ":" and b[2] == ":":
            return (bool(r.get("nachtruhe")), v, b)
    return (True, "22:00", "07:00")


def kabel_nacht(cfg: dict, hm: str) -> bool:
    """Wie ansage_nachtruhe() im Stamm."""
    an, v, b = kabel_regeln(cfg)
    if not an:
        return False
    return (v <= hm < b) if v < b else (hm >= v or hm < b)


def kabel_entscheiden(f: dict, m: dict, cfg: dict, abfahrt_in, jetzt: float, alter) -> tuple:
    """Die Warnung "Kabel vergessen" (Form mg_kabel_entscheiden()): zu Hause, Kabel nicht gesteckt, Akku unter der
    Schwelle, Stand bis 12 h; abends (ab kabel_uhr), am Morgen nach der Nachtruhe oder wenn die Abfahrt naht.
    Unbekannt heisst nie warnen. Hoechstens einmal je Standzeit und Tag. In der Nachtruhe wird nichts verbraucht.
    Rein bis auf die Uhr im Argument. Rueckgabe (faellig, neuer Merker)."""
    m = dict(m or {})
    zu, ka = f.get("ZUHAUSE"), f.get("KABEL")
    if zu == 0 or ka == 1 or haus_fz_zahl(f.get("TEMPO")) not in ("-", "0"):
        m["seit"], m["gesagt"] = 0, 0
        return (False, m)
    if zu != 1 or ka != 0:
        return (False, m)
    if not m.get("seit"):
        m["seit"] = int(jetzt)
    soc = haus_fz_zahl(f.get("SOC"))
    if soc == "-" or float(soc) >= float(ganz(cfg.get("kabel_soc"), 30)) or alter is None or alter > KABEL_FRISCH_S:
        return (False, m)
    z = time.localtime(jetzt)
    hm, tag = time.strftime("%H:%M", z), time.strftime("%Y%m%d", z)
    if m.get("gesagt") or kabel_nacht(cfg, hm) or str(m.get("tag")) == tag:
        return (False, m)
    uhr = str(cfg.get("kabel_uhr") or "")
    if len(uhr) != 5 or uhr[2] != ":":
        uhr = "20:00"
    abends = hm >= uhr
    an, _v, bis = kabel_regeln(cfg)
    morgen = an and hm < "12:00" and int(m["seit"]) < time.mktime(time.strptime(tag + " " + bis, "%Y%m%d %H:%M"))
    grenze = ganz(cfg.get("kabel_abfahrt_min"), 0)
    abfahrt = grenze > 0 and abfahrt_in is not None and 1 <= int(abfahrt_in) <= grenze
    if not abends and not morgen and not abfahrt:
        return (False, m)
    m["gesagt"], m["tag"] = 1, tag
    return (True, m)


def kabel_takt(horcher, cfg: dict, stand: dict, jetzt: float) -> list:
    """Je Minute je Fahrzeug die Warnung pruefen. Ohne Haken geschieht nichts. Rueckgabe: die gewarnten Nummern."""
    if not cfg.get("ansage_kabel_vergessen") and not (cfg.get("signal_ein") and cfg.get("signal_kabel")):
        return []
    fahrzeuge = (stand or {}).get("fahrzeuge") or {}
    alt = json_lesen(DATEI_KABEL)
    neu = dict(alt)
    in_min = None
    if ganz(cfg.get("kabel_abfahrt_min"), 0) > 0 and cfg.get("abfahrt_ein") and horcher is not None:
        in_min, _h = vorklima_minuten(horcher, cfg, jetzt)
    gewarnt = []
    for nr in sorted(fahrzeuge, key=lambda n: ganz(n, 0)):
        f = fahrzeuge[nr]
        if not isinstance(f, dict):
            continue
        faellig, m = kabel_entscheiden(f, alt.get(str(nr)) or {}, cfg, in_min, jetzt, haus_fz_alter(f, stand, jetzt))
        neu[str(nr)] = m
        if faellig:
            gewarnt.append(str(nr))
            name = str(f.get("name") or f.get("modell") or "")[:60]
            soc = int(round(float(f.get("SOC"))))
            _LOG.info("Kabel vergessen: Fahrzeug %s steht zu Hause ohne Kabel (Akku %s Prozent).", nr, soc)
            ansage_senden("kabel_vergessen", str(nr), {"name": name, "soc": soc}, cfg)
            text = "%s steht ohne Ladekabel zu Hause, der Akku steht bei %d Prozent." % (name or ("Fahrzeug %s" % nr), soc)
            signal_senden(cfg, "kabel", str(nr), text)
    if neu != alt:
        json_schreiben(DATEI_KABEL, neu, 0o600)
    return gewarnt


def sammel_takt(horcher, cfg: dict, stand: dict) -> None:
    """Aus dem Wartetakt des Dienstes. Eine Stoerung hier haelt nie den Dienst an."""
    jetzt = time.time()
    if jetzt - _HAUS_FZ["letzt"] < HAUS_FZ_TAKT_S:
        return
    _HAUS_FZ["letzt"] = jetzt
    try:
        haus_fz_takt(cfg, stand, jetzt)
    except Exception as err:  # noqa: BLE001
        melde_gebremst("haus_fz", "haus/fahrzeug: %s" % type(err).__name__, 3600)
    try:
        kabel_takt(horcher, cfg, stand, jetzt)
    except Exception as err:  # noqa: BLE001
        melde_gebremst("kabel", "Kabel vergessen: %s" % type(err).__name__, 3600)
    # Einmal je Stunde (und nach dem Start) auf fremde Zaehler hoeren, im eigenen Faden: Der Takt wartet nicht.
    staemme = haus_fz_staemme(json_lesen(DATEI_HAUS_FZ))
    if cfg.get("haus_fahrzeug_ein") and staemme and jetzt - _HAUS_FZ["lausch"] >= HAUS_FZ_LAUSCH_ABSTAND_S:
        import threading  # noqa: PLC0415
        _HAUS_FZ["lausch"] = jetzt
        threading.Thread(target=haus_fz_lausch_lauf, args=(staemme,), daemon=True).start()


def vorklima_sperre(cfg: dict, f: dict) -> str:
    """Warum diese Abfahrt nicht klimatisiert wird: "" (doch), "akku", "kabel". Unbekannt sperrt nie. Rein."""
    grenze = ganz(cfg.get("vorklima_min_soc"), 0)
    soc = haus_fz_zahl(f.get("SOC"))
    if grenze > 0 and soc != "-" and float(soc) < grenze:
        return "akku"
    if cfg.get("vorklima_nur_kabel") and f.get("KABEL") == 0:
        return "kabel"
    return ""


def broker_anmeldung() -> dict:
    """Broker und Anmeldung aus general.json (mqtt_broker()) - nie in einer Befehlszeile."""
    b = mqtt_broker()
    return {"host": b["host"] or "127.0.0.1", "port": ganz(b["port"], 1883) or 1883,
            "auth": ({"username": b["benutzer"], "password": b["passwort"] or None} if b["benutzer"] else None)}


def broker_senden(thema: str, nutzlast: str) -> bool:
    """Kurze Sitzung direkt am Broker (Regeln/07 Nr. 15 Technik), qos 0, NIE retained."""
    if _BROKER_SENDEN is not None:
        return bool(_BROKER_SENDEN(thema, nutzlast))
    try:
        import paho.mqtt.publish as pub  # noqa: PLC0415
    except ImportError:
        melde_gebremst("signal_paho", "SignalBot-Meldung nicht gesendet: paho-mqtt fehlt.", 86400)
        return False
    b = broker_anmeldung()
    try:
        pub.single(thema, nutzlast, qos=0, retain=False, hostname=b["host"], port=b["port"], auth=b["auth"],
                   client_id="bydautos-signal-%d" % os.getpid())
        return True
    except Exception as err:  # noqa: BLE001
        melde_gebremst("signal_senden", "SignalBot-Meldung nicht gesendet: %s" % type(err).__name__, 3600)
        return False


# BYD erkennt nur diese Ereignisse: keine Ladegrenze (kein laden_abbruch), Tuer-/Schlosswerte sind
# Kennzahlen ohne belegte Bedeutung (kein offen).
SIGNAL_TEXTE = {
    "laden_fertig": "%s: Die Ladung ist beendet.",
    "ausfall": "BYD Autos: seit drei Abrufen keine Daten mehr.",
}


def signal_senden(cfg: dict, anlass: str, nr, text: str) -> bool:
    """Ein Ereignis an SignalBot (ab Werk aus, je Ereignis abwaehlbar, je Ereignis und Fahrzeug 1x je Stunde)."""
    if not cfg.get("signal_ein") or str(cfg.get("signal_" + anlass)).strip() != "1":
        return False
    if _ansage_sperre("signal:%s:%s" % (anlass, nr), int(time.time())):
        return False
    thema = signal_thema(cfg.get("signal_praefix"), PNAME)
    if not thema:
        melde_gebremst("signal_thema", "SignalBot-Meldung nicht gesendet: Praefix oder Ordnername ungueltig.", 86400)
        return False
    ok = broker_senden(thema, signal_nutzlast(text, 0))
    _LOG.info("SignalBot-Meldung %s (Fahrzeug %s): %s.", anlass, nr, "gesendet" if ok else "NICHT gesendet")
    return ok


# ---- G8-Zusatz: Testmeldung an SignalBot (Reiter Einstellungen, byd.py --signal-test) ----
SIGNAL_TEST_TEXT = "Testmeldung von BYD Autos (LoxBerry): der Meldeweg an SignalBot funktioniert."
_SIGNAL_TEST_SITZUNG = None      # Nahtstelle fuer Proben: Funktion (thema, text, warten) -> Quittungstext oder None


def signal_test_thema(praefix) -> str:
    """'<praefix>/befehl/senden' (Befehl 'senden' der SignalBot-Bruecke, Text als Nutzlast) oder '' (rein)."""
    p = str(praefix or "")
    return "%s/befehl/senden" % p if re.fullmatch(r"[A-Za-z0-9_-]{1,40}", p) else ""
def signal_test_auswerten(quittung) -> str:
    """Quittung der Bruecke -> angenommen | abgelehnt | keine_antwort (rein). Die Bruecke 1.0.2 quittiert
    'angenommen' oder 'abgelehnt: <grund>'; alles andere ist keine verwertbare Antwort."""
    if quittung is None or str(quittung).strip() == "":
        return "keine_antwort"
    q = str(quittung).strip().lower()
    if q.startswith("angenommen"):
        return "angenommen"
    if q.startswith("abgelehnt"):
        return "abgelehnt"
    return "keine_antwort"


def _signal_test_sitzung(thema: str, text: str, warten: float):
    """Eine kurze Sitzung: <thema>/quittung abonnieren, Text senden (retain 0), bis 'warten' s auf die Quittung."""
    import threading  # noqa: PLC0415
    import paho.mqtt.client as mqtt  # noqa: PLC0415
    b = broker_anmeldung()
    ergebnis = {"q": None}
    fertig = threading.Event()
    try:
        k = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="bydautos-signaltest-%d" % os.getpid())
    except (AttributeError, TypeError):
        k = mqtt.Client(client_id="bydautos-signaltest-%d" % os.getpid())
    if b["auth"]:
        k.username_pw_set(b["auth"]["username"], b["auth"]["password"])

    def bei_nachricht(_k, _u, m):
        if not getattr(m, "retain", False):
            ergebnis["q"] = m.payload.decode("utf-8", "replace")
            fertig.set()
    k.on_message = bei_nachricht
    k.connect(b["host"], b["port"], 30)
    k.loop_start()
    try:
        k.subscribe(thema + "/quittung", qos=0)
        time.sleep(0.3)
        k.publish(thema, text, qos=0, retain=False)
        fertig.wait(warten)
    finally:
        k.loop_stop()
        k.disconnect()
    return ergebnis["q"]


def signal_test() -> tuple:
    """(ergebnis, text): aus | nicht_moeglich | angenommen | abgelehnt | keine_antwort. Ab Werk wirkungslos."""
    cfg = config()
    if not cfg.get("signal_ein"):
        return ("aus", "Meldungen an SignalBot sind aus - es wurde nichts gesendet.")
    thema = signal_test_thema(cfg.get("signal_praefix"))
    if not thema:
        return ("nicht_moeglich", "Das Praefix des SignalBot-Plugins ist ungueltig.")
    try:
        if _SIGNAL_TEST_SITZUNG is not None:
            q = _SIGNAL_TEST_SITZUNG(thema, SIGNAL_TEST_TEXT, SIGNAL_TEST_WARTEN)
        else:
            q = _signal_test_sitzung(thema, SIGNAL_TEST_TEXT, SIGNAL_TEST_WARTEN)
    except ImportError:
        return ("nicht_moeglich", "paho-mqtt fehlt in der virtuellen Umgebung.")
    except Exception as err:  # noqa: BLE001
        return ("nicht_moeglich", "Broker nicht erreichbar (%s)." % type(err).__name__)
    e = signal_test_auswerten(q)
    return (e, mqtt_wert_saeubern(str(q or ""))[:120].replace(";", " ").replace("\n", " "))


# ---- Der Wartetakt (alle 10 s) ----
async def g42_takt(client, horcher, stand: dict, cfg: dict, z: dict, freigeschaltet: set, merker: dict,
                   letzter_versuch: int) -> bool:
    """Vorklimatisierung, Ankunft, Preisprobe, Thema VORKLIMA. True = Sofortabruf jetzt (Wartezeit verlassen) -
    nur, wenn der Mindesttakt TAKT_MIN seit dem letzten Abruf um ist; sonst bleibt er vorgemerkt."""
    jetzt = time.time()
    vorher = json.dumps([merker.get("vorklima"), merker.get("ankunft")], sort_keys=True)
    sammel_takt(horcher, cfg, stand)     # 0.9.31: Sammelthemen und Kabel-Warnung, je Minute
    auftrag = vorklima_takt(horcher, cfg, merker, stand, jetzt)
    if auftrag is not None:
        if auftrag.get("aktion") == "abruf":
            e_ok, e_meldung = 1, "Sofortabruf vorgemerkt."
            _G42["abruf_offen"] = True
        else:
            try:
                e_ok, e_meldung, _zs = await mit_frist(
                    befehl_ausfuehren(client, stand.get("fahrzeuge") or {}, cfg, z, auftrag, freigeschaltet),
                    GRENZE_BEFEHL, "Die Vorklimatisierung")
            except Exception as err:  # noqa: BLE001
                e_ok, e_meldung = 0, fehlertext(err)
        _LOG.info("Vorklimatisierung: ok=%s %s", e_ok, e_meldung)
        vorklima_ergebnis(merker, auftrag, e_ok)
    if ankunft_takt(horcher, cfg, merker, stand, jetzt):
        _G42["abruf_offen"] = True
    ladung_probe(horcher, cfg, jetzt)
    vorklima_melden(cfg, merker, jetzt)
    if json.dumps([merker.get("vorklima"), merker.get("ankunft")], sort_keys=True) != vorher:
        merker_schreiben(merker)
    if _G42["abruf_offen"] and (not letzter_versuch or jetzt - int(letzter_versuch) >= TAKT_MIN):
        _G42["abruf_offen"] = False
        return True
    return False


REIFEN_FELDER = ("REIFENVL", "REIFENVR", "REIFENHL", "REIFENHR")
REIFEN_FAKTOR = {1: 1, 2: 0.0689476, 3: 0.01}   # Kennung -> Faktor nach bar
REIFEN_GRENZE_ROH = 20


def reifendruck_bar(wert, kennung):
    """Ein Reifendruck in bar, nach der Einheitenkennung der Schnittstelle.

    None bleibt None (das Feld kam nicht vor - am Endpunkt ein Strich, wie
    bei jedem anderen Feld). -1 heisst: ein Wert kam, ist aber nicht
    plausibel. Alles andere ist bar, auf zwei Nachkommastellen.
    """
    if wert is None:
        return None
    w = zahl(wert)
    if w is None or w < 0:
        return -1
    k = zahl(kennung)
    faktor = REIFEN_FAKTOR.get(k) if isinstance(k, int) else None
    if faktor is None:
        if w > REIFEN_GRENZE_ROH:
            return -1
        faktor = 1
    bar = round(w * faktor, 2)
    return int(bar) if bar == int(bar) else bar


def fahrzeug_abbilden(stamm: dict, echtzeit: dict, gps: dict) -> dict:
    """Setzt das Abbild eines Fahrzeugs aus den drei Antworten zusammen.

    Alle drei Verzeichnisse sind bereits flach. Was nicht getroffen wird,
    bleibt leer; welcher Kandidat getroffen hat, steht in 'getroffen'.
    """
    roh: dict = {}
    einmischen(roh, stamm)
    einmischen(roh, echtzeit)  # Echtzeit sticht Stammdaten (totalMileage steht in beiden)
    einmischen(roh, gps)       # ... aber ein None loescht nie einen gemessenen Wert:
                               # der GPS-Abschnitt fuehrt "speed": null (siehe
                               # einmischen(), gemessen 14.09.2026)

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

    # ---- Reifendruecke immer in bar (0.9.28, siehe reifendruck_bar()) ----
    # Die EINZIGE Stelle der Umrechnung: MQTT, die HTTP-Zeile, aktion=json
    # und die Oberflaeche lesen alle dieses Abbild. REIFENEH bleibt roh.
    for name in REIFEN_FELDER:
        d[name] = reifendruck_bar(d.get(name), d.get("REIFENEH"))

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

    # Durchschnittsverbrauch des Fahrzeugs. Der Rohwert traegt seine Einheit
    # in der Zeichenkette ("17.6kW\u00b7h/100km"), deshalb wird die fuehrende
    # Zahl herausgeloest. Steht dort keine, entsteht KEIN Wert - nicht 0.
    roh_verbr, _ = hole(roh, ("total_energy", "totalEnergy"))
    d["VERBRBYD"] = zahl_vorn(roh_verbr)

    # Der Durchschnitt der letzten 50 km. Dieselbe Vorsicht, und aus
    # demselben Grund: die Antwort fuehrt denselben Wert unter mehreren
    # Namen und in drei Formen - 15.4 als Zahl, "15.4" als Zeichenkette und
    # "15.4kW\u00b7h/100km" mit eingebauter Einheit. Die Reihenfolge der
    # Kandidaten ist die Rangfolge: erst die Namen, die den Zeitraum selbst
    # nennen, dann die allgemeineren.
    roh_50, _ = hole(roh, ("recent_50km_energy_ev", "recent50kmEnergyEv",
                           "recent_50km_energy", "recent50kmEnergy",
                           "nearest_energy_consumption_ev",
                           "nearestEnergyConsumptionEv",
                           "nearest_energy_consumption",
                           "nearestEnergyConsumption",
                           "energy_consumption_ev", "energyConsumptionEv",
                           "energy_consumption", "energyConsumption"))
    d["VERBR50"] = zahl_vorn(roh_50)

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


def nummern_zuordnen(vins: list, merker: dict) -> tuple[dict, bool]:
    """VIN -> laufende Nummer, DAUERHAFT. Rueckgabe: (Zuordnung, geaendert).

    Die Nummer ist eine ADRESSE: sie steht in Loxone als virtueller Eingang
    fahrzeug1/SOC verdrahtet und im MQTT-Thema. Bisher entstand sie aus einer
    Aufzaehlung ueber die nach VIN sortierte Fahrzeugliste. Der Kommentar dort
    nannte den Schaden richtig - "fiele ein Fahrzeug weg, rueckte jedes
    nachfolgende um eins vor" - und die Sortierung verhindert ihn NICHT. Sie
    macht die Reihenfolge stabil, nicht die Nummern: verschwindet das
    alphabetisch erste Fahrzeug aus dem Konto, wird das zweite zur Nummer 1,
    und der Eingang in Loxone zeigt ohne jede Meldung auf ein anderes Auto.

    Darum steht die Zuordnung im merker und ueberlebt jeden Neustart. Wer
    einmal Nummer 2 war, bleibt Nummer 2 - auch wenn Nummer 1 fortfaellt und
    erst recht, wenn sie zurueckkommt.

    ERSTER LAUF: die Nummern werden genau so vergeben, wie es die bisherige
    Fassung tat - nach VIN sortiert, beginnend bei 1. Eine bestehende Anlage
    behaelt damit ihre Verdrahtung; der Umbau ist von aussen nicht zu merken.

    FAHRZEUG OHNE VIN: es bekommt keine dauerhafte Nummer, denn es gibt nichts,
    woran sie haengen koennte. Solche Fahrzeuge kommen NACH den zugeordneten in
    der Reihenfolge der Liste dran. Das wird gemeldet, statt eine Stabilitaet
    vorzutaeuschen, die es nicht gibt.
    """
    tab = merker.get("nummern")
    if not isinstance(tab, dict):
        tab = {}
    tab = {str(k): int(v) for k, v in tab.items()
           if str(k) and str(v).lstrip("-").isdigit() and int(v) > 0}
    geaendert = False
    mit_vin = sorted({v for v in vins if v})

    if not tab and mit_vin:
        # Erstlauf: die bisherige Vergabe nachbilden.
        for i, v in enumerate(mit_vin, start=1):
            tab[v] = i
        geaendert = True
    else:
        belegt = set(tab.values())
        for v in mit_vin:
            if v in tab:
                continue
            n = 1
            while n in belegt:
                n += 1
            tab[v] = n
            belegt.add(n)
            geaendert = True

    zuordnung = {v: tab[v] for v in vins if v and v in tab}
    ohne = [v for v in vins if not v]
    if ohne:
        melde_gebremst(
            "vin_fehlt",
            "%d Fahrzeug(e) liefern keine VIN. Sie bekommen KEINE feste Nummer "
            "und koennen bei einer Aenderung am Konto die Nummer wechseln. "
            "Die virtuellen Eingaenge in Loxone waeren dann still vertauscht."
            % len(ohne), 86400)

    if geaendert:
        merker["nummern"] = tab
    return (zuordnung, geaendert)


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
                f.write("# fahrzeug;start;ende;dauer_min;soc_start;soc_ende;km;kwh;kosten_eur;preis_ct\n")
            # Gruppe 42: kosten_eur;preis_ct hinten angehaengt - alte Zeilen bleiben, leer = unbekannt.
            f.write("%s;%d;%d;%s;%s;%s;%s;%s;%s;%s\n" % (
                zeile["fahrzeug"], zeile["start"], zeile["ende"], zeile["dauer"],
                zeile["soc_start"], zeile["soc_ende"],
                zeile["km"] if zeile["km"] is not None else "",
                zeile["kwh"] if zeile["kwh"] is not None else "",
                "" if zeile.get("kosten") in (None, -1) else zeile["kosten"],
                "" if zeile.get("preis") in (None, -1) else zeile["preis"]))
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
        # UEBER EINE NEBENDATEI, nicht mit write_text auf das Original. Das
        # ist die einzige Datei des Plugins, die eine Aufzeichnung ueber
        # Monate fuehrt - und die einzige, die preupgrade.sh eigens ueber ein
        # Update rettet. Ein write_text kappt die Datei auf null und fuellt sie
        # dann neu: bricht der Strom oder das Dateisystem in diesem Augenblick
        # weg, ist die gesamte Ladehistorie fort. Ausserdem sieht die
        # Oberflaeche, die parallel liest, sonst eine halbe Datei.
        text = "\n".join(behalten) + "\n"
        tmp = datei.with_name(datei.name + ".tmp." + str(os.getpid()))
        try:
            with tmp.open("w", encoding="utf-8") as f:
                geschrieben = f.write(text)
            if geschrieben != len(text):
                tmp.unlink(missing_ok=True)
                _LOG.error("Ladehistorie: nur %d von %d Zeichen geschrieben - "
                           "die alte Datei bleibt unangetastet.",
                           geschrieben, len(text))
                return
            os.replace(tmp, datei)
        except OSError as err:
            _LOG.error("Ladehistorie liess sich nicht kappen (%s) - die alte "
                       "Datei bleibt unangetastet.", err)
            try:
                tmp.unlink(missing_ok=True)
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
            # Gruppe 42 (G6): Mitschrift fuer die Ladekosten (nur zu Hause und mit dem Haken; uebersteht Neustarts).
            ladung_beginnen(nummer, d, cfg, jetzt)
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
                # Gruppe 42 (G6): Kosten und Durchschnittspreis aus den Minutenproben (-1 ohne Aussage).
                kosten, preis = ladung_kosten_abschliessen(nummer, kwh, jetzt)
                ladung_anhaengen({
                    "fahrzeug": nummer, "start": int(start), "ende": jetzt,
                    "dauer": dauer, "soc_start": ssoc, "soc_ende": soc,
                    "km": km, "kwh": kwh, "kosten": kosten, "preis": preis}, cfg["verlauf_tage"])
                if kwh is not None:
                    m["ladekwh"] = kwh
                _LOG.info("Fahrzeug %s: Ladevorgang beendet, %d Minuten, %s -> %s %%%s",
                          nummer, dauer, ssoc, soc,
                          "" if kwh is None else (", %.2f kWh" % kwh))
            elif str(nummer) in _G42["mitschrift"]:
                # Ohne Beginn kein Ladeprotokoll - die offene Mitschrift faellt mit (-1, mitschrift_fehlt nie).
                ladung_kosten_abschliessen(nummer, None, jetzt)
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
    # Gruppe 42: LADEKOSTEN, LADEPREIS, VORKLIMA.
    ladung_felder(nummer, d)


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


# Gruppe 42 (0.9.29): vorklimatisierung() ist fort. Befunde (Entwurf G42): B1 - der Merker "scharf = 0" stand
# VOR dem Ergebnis (ein Fehlschlag wurde nie wiederholt); B2 - wieder scharf erst ueber Vorlauf + 5, also bis zu
# 5 min zu frueh; B3 - ABFAHRT_IN = 0 loeste noch aus; B4 - ein retained Wert galt als frisch. Ersetzt durch
# vorklima_takt() im Wartetakt (g42_takt()), Merker merker["vorklima"] in merker.json.


def vin_waehlen(fahrzeuge: dict, schluessel) -> str:
    """Nimmt entweder die laufende Nummer (1-basiert) oder die VIN."""
    s = str(schluessel or "1").strip()
    for f in fahrzeuge.values():
        if str(f.get("vin", "")).upper() == s.upper() and s != "":
            return str(f.get("vin"))
    f = fahrzeuge.get(s)
    return str(f.get("vin", "")) if isinstance(f, dict) else ""


def befehlsbremse_lesen() -> dict:
    """C5: Stand der PIN-Bremse und der Stundenobergrenze.

    pin_fehl: gescheiterte Freischaltungen der letzten Stunde, pin_bis: Ende
    der PIN-Sperre, befehle: abgesetzte schaltende Befehle der letzten Stunde.
    PIN-Angaben gelten nur fuer die Zugangsdatei, unter der sie entstanden -
    eine neu gespeicherte PIN hebt die Sperre auf."""
    b = json_lesen(DATEI_BEFEHLSBREMSE)
    jetzt = time.time()

    def zeiten(werte, fenster):
        return [int(t) for t in (werte or []) if isinstance(t, (int, float))
                and not isinstance(t, bool) and 0 <= jetzt - t < fenster]

    gleich = b.get("zugang") == zugang_kennung()
    return {
        "pin_fehl": zeiten(b.get("pin_fehl"), PIN_SPERRE) if gleich else [],
        "pin_bis": ganz(b.get("pin_bis"), 0) if gleich else 0,
        "befehle": zeiten(b.get("befehle"), 3600),
    }


def befehlsbremse_schreiben(d: dict) -> None:
    d = dict(d)
    d["zugang"] = zugang_kennung()
    json_schreiben(DATEI_BEFEHLSBREMSE, d, 0o600)


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
    # Welle Bild 17 (0.9.26, Hausherr 17.10.2026): der ZWEITE Haken. Der Endpunkt weist diese Befehle schon
    # ab; hier faengt es auch den Weg ueber den Reiter Test und jede andere Warteschlangendatei.
    if BEFEHLE[aktion].get("eingreifend") and not cfg.get("gefahr_ein"):
        if not probe:
            return (0, "EINGRIFF_GESPERRT: Dieser Befehl greift in ein Fahrzeug ein, das im "
                       "oeffentlichen Raum steht. Reiter Einstellungen, zweiter Haken "
                       "'Eingreifende Befehle zulassen'.", {})
        hemmnisse.append("eingreifende Befehle sind gesperrt (zweiter Haken) - der echte "
                         "Befehl wuerde abgewiesen")
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
    # C5: PIN-Sperre und Stundenobergrenze VOR jedem Kontakt mit BYD. Im
    # Trockenlauf wird beides nur benannt.
    bremse = befehlsbremse_lesen()
    rest_pin = bremse["pin_bis"] - int(time.time())
    if rest_pin > 0:
        text_pin = ("PIN_GESPERRT: Die Steuer-PIN wurde %d-mal nicht angenommen. Schaltende "
                    "Befehle sind noch %d Minuten gesperrt, damit BYD die Fernsteuerung "
                    "nicht sperrt. Die PIN im Reiter Einstellungen pruefen; eine neu "
                    "gespeicherte PIN hebt die Sperre auf."
                    % (PIN_GRENZE, (rest_pin + 59) // 60))
        if not probe:
            return (0, text_pin, {})
        hemmnisse.append(text_pin)
    if len(bremse["befehle"]) >= BEFEHLE_JE_STUNDE:
        text_std = ("STUNDENGRENZE: In der letzten Stunde wurden schon %d schaltende Befehle "
                    "abgesetzt; mehr laesst das Plugin je Stunde nicht zu."
                    % BEFEHLE_JE_STUNDE)
        if not probe:
            return (0, text_std, {})
        hemmnisse.append(text_std)
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
                    # Unter eine Frist stellen: schweigt die Gegenstelle hier,
                    # steht der ganze Dienst - die Freischaltung laeuft im
                    # selben Ablauf wie der naechste Abruf.
                    erg = await mit_frist(erg, GRENZE_FREIGABE,
                                          "Die Freischaltung der Steuer-PIN")
            except Exception as err:  # noqa: BLE001
                # C5: die gescheiterte Freischaltung wird VERMERKT. Nach
                # PIN_GRENZE Fehlschlaegen gilt die Sperre fuer alle
                # schaltenden Befehle.
                bremse = befehlsbremse_lesen()
                bremse["pin_fehl"].append(int(time.time()))
                zusatz_text = ""
                if len(bremse["pin_fehl"]) >= PIN_GRENZE:
                    bremse["pin_bis"] = int(time.time()) + PIN_SPERRE
                    bremse["pin_fehl"] = []
                    zusatz_text = (" PIN_GESPERRT: nach %d Fehlschlaegen sind schaltende Befehle "
                                   "%d Minuten gesperrt." % (PIN_GRENZE, PIN_SPERRE // 60))
                    _LOG.error("Steuer-PIN %d-mal nicht angenommen - schaltende Befehle "
                               "fuer %d Minuten gesperrt.", PIN_GRENZE, PIN_SPERRE // 60)
                befehlsbremse_schreiben(bremse)
                return (0, "Die Steuer-PIN wurde nicht angenommen (%s): %s%s"
                           % (name, fehlertext(err), zusatz_text), {})
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

    # C5: jeder abgesetzte schaltende Befehl zaehlt gegen die Stundenobergrenze
    # - gezaehlt VOR dem Absenden, auch ein gescheiterter Versuch ging an BYD.
    bremse = befehlsbremse_lesen()
    bremse["befehle"].append(int(time.time()))
    befehlsbremse_schreiben(bremse)
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
                        freigeschaltet: set, letzter_versuch: int = 0,
                        kontingent: dict | None = None) -> bool:
    """Arbeitet die vorliegenden Befehle ab. True, wenn ein Sofortabruf
    angefordert wurde UND die Bremse ihn durchlaesst.

    Drei Sperren, die es vorher nicht gab - Begruendung bei BEFEHLE_JE_LAUF,
    BEFEHL_HOECHSTALTER und TAKT_MIN:

      * hoechstens BEFEHLE_JE_LAUF Befehle je Durchlauf,
      * nichts, was aelter als BEFEHL_HOECHSTALTER Sekunden ist,
      * und kein Sofortabruf schneller als der Mindesttakt.

    Abgewiesene Befehle werden BEANTWORTET und ihre Datei geloescht. Ein
    Befehl, der stumm liegen bleibt, staut sich sonst auf und geht beim
    naechsten Mal doch noch ans Fahrzeug.
    """
    try:
        ORDNER_BEFEHLE.mkdir(parents=True, exist_ok=True)
    except OSError:
        return False
    sofort = False
    jetzt = int(time.time())
    dateien = sorted(ORDNER_BEFEHLE.glob("*.json"))
    # C5: BEFEHLE_JE_LAUF gilt je TAKT, nicht je Aufruf. Bis 0.9.19 wurde
    # gezaehlt, sooft diese Funktion lief - in der Wartezeit jede Sekunde.
    # Das Kontingent fuehrt dienst_lauf() und setzt es je Takt zurueck.
    if kontingent is None:
        kontingent = {"rest": BEFEHLE_JE_LAUF}
    if len(dateien) > kontingent["rest"]:
        melde_gebremst(
            "warteschlange_voll",
            "Es liegen %d Befehle vor. Hoechstens %d werden je Takt "
            "ausgefuehrt; die uebrigen werden abgewiesen und nicht "
            "aufgehoben." % (len(dateien), BEFEHLE_JE_LAUF), 900)

    for datei in dateien:
        b = json_lesen(datei)
        kennung = datei.stem
        try:
            datei.unlink()
        except OSError:
            pass
        if not b:
            antwort_schreiben(kennung, 0, "Befehlsdatei war leer oder unlesbar.")
            continue
        if kontingent["rest"] <= 0:
            antwort_schreiben(kennung, 0,
                              "Abgewiesen: in diesem Takt wurden schon %d Befehle "
                              "ausgefuehrt; mehr laesst das Plugin je Takt nicht zu."
                              % BEFEHLE_JE_LAUF)
            continue
        # Das Alter nur pruefen, wenn ein Zeitstempel da ist. Fehlt er, ist
        # der Befehl von Hand oder aus einer aelteren Fassung - dann wird er
        # ausgefuehrt, statt ihn wegen einer fehlenden Angabe zu verwerfen.
        alter = jetzt - int(b.get("ts") or 0)
        if b.get("ts") and alter > BEFEHL_HOECHSTALTER:
            antwort_schreiben(kennung, 0,
                              "Abgewiesen: der Befehl ist %d s alt (erlaubt sind "
                              "%d). Ein Schaltbefehl an ein Auto ist eine Absicht "
                              "von jetzt." % (alter, BEFEHL_HOECHSTALTER))
            _LOG.info("Befehl %s (%s) verworfen: %d s alt.",
                      kennung, b.get("aktion"), alter)
            continue
        if b.get("aktion") == "abruf":
            # Bremse gegen den Mindesttakt. Ohne sie umgeht jeder Klick auf
            # "Jetzt abrufen" die Untergrenze, die fuer den Takt gilt - und
            # zwar beliebig oft. Das BYD-Konto sieht dann eine Abrufrate, die
            # ueber der liegt, die die Einstellungen ueberhaupt zulassen.
            rest = TAKT_MIN - (jetzt - int(letzter_versuch or 0))
            if letzter_versuch and rest > 0:
                antwort_schreiben(kennung, 0,
                                  "Abgewiesen: der letzte Abruf ist erst %d s her. "
                                  "Der Mindestabstand betraegt %d s; der naechste "
                                  "Abruf ist in %d s moeglich."
                                  % (jetzt - int(letzter_versuch), TAKT_MIN, rest))
                continue
        kontingent["rest"] -= 1
        try:
            # Unter eine Frist: die Warteschlange laeuft im selben Ablauf wie
            # der Abruf. Ein Schaltbefehl, der nie zurueckkommt, hielte auch
            # jede Messung an - und die Oberflaeche zeigte weiter alte Werte,
            # ohne dass jemand saehe, warum.
            ok, meldung, zusatz = await mit_frist(
                befehl_ausfuehren(client, fahrzeuge, cfg, z, b, freigeschaltet),
                GRENZE_BEFEHL, "Der Befehl an BYD")
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
def stand_zusammenfuehren(alt: dict, neu: dict) -> dict:
    """Bei einem TEILAUSFALL die bewahrten Werte mit den frischen mischen.

    Ein Fahrzeug antwortet, ein zweites nicht. Der Sammelabruf gilt dann als
    gelungen (ok=1), denn irgendetwas kam ja. Wuerde der neue Stand den alten
    vollstaendig ersetzen, staende das ausgefallene Fahrzeug danach mit lauter
    Leerwerten da, obwohl Minuten zuvor gute Messwerte vorlagen - und der
    aufgefrischte Zeitstempel liesse es in Loxone frisch aussehen. ALTER waere
    klein, die Werte waeren fort: die Ausfallerkennung schluege gerade dann
    nicht an, wofuer sie da ist.

    Darum: wer geliefert hat, kommt neu herein; wer nicht, behaelt seine
    letzten Messwerte und wird mit ok=0 gekennzeichnet. Ueber dieses ok geht
    fahrzeugN/OK=0 hinaus - daran, und nur daran, ist ein Teilausfall in Loxone
    zu erkennen.

    FEHLFOLGE bleibt dabei UNVERAENDERT stehen. Der Zaehler zaehlt Ausfaelle
    DES ABRUFS, nicht die eines einzelnen Fahrzeugs; ihn hier hochzuzaehlen
    oder auf 0 zu setzen waere beides eine Behauptung. Wer die Dauer eines
    Teilausfalls braucht, bildet sie in Loxone aus dem Verlauf von OK.
    """
    alt = alt or {}
    zusammen: dict = {}
    for nummer, fz in (neu or {}).items():
        vorher = alt.get(nummer)
        if fz.get("ok") or not vorher:
            # Geliefert - oder noch nie etwas gehabt, dann gibt es nichts zu
            # bewahren, und die Leerwerte sind die Wahrheit.
            zusammen[nummer] = fz
            continue
        behalten = dict(vorher)
        behalten["ok"] = 0
        # Die Stammangaben duerfen aus der frischen Antwort kommen: Name,
        # Kennzeichen und Modell sind keine Messwerte und altern nicht.
        for s in ("vin", "name", "kennzeichen", "marke", "modell",
                  "antriebsart", "tbox"):
            if fz.get(s):
                behalten[s] = fz[s]
        behalten["offen"] = fz.get("offen") or []
        zusammen[nummer] = behalten
    return zusammen


def abbild_schreiben(stand: dict, cfg: dict, ok: int, fehler: str = "",
                     bekannte=(), weg=()) -> dict:
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

    praefix = mqtt_thema_saeubern(cfg.get("mqtt_topic") or "byd")

    if ok:
        for nummer, f in fahrzeuge.items():
            # Auch hier je Fahrzeug: bei einem Teilausfall stehen im Satz des
            # ausgefallenen Fahrzeugs die BEWAHRTEN Werte des letzten guten
            # Abrufs. Sie noch einmal anzuhaengen erfaende eine Messung, die es
            # nicht gab - der Verlauf zeigte eine waagerechte Linie statt einer
            # Luecke.
            if not f.get("ok", 1):
                continue
            try:
                verlauf_anhaengen(int(nummer), f.get("SOC"), f.get("REICHW"),
                                  cfg["verlauf_tage"])
            except (TypeError, ValueError):
                pass

    if not cfg.get("mqtt_ein"):
        return lox

    # Bei JEDEM Durchlauf gehen ok, ts und die Fahrzeugzahl hinaus, auch bei
    # einer Stoerung. Die drei tragen dieselbe Aussage wie die Statuszeile des
    # HTTP-Endpunkts: ein Plugin, dessen MQTT-Meldung weniger enthaelt als
    # seine Zeile, macht die Umstellung von dem einen auf den anderen Weg
    # unmoeglich - und zwar unauffaellig. Dass sich beide Wege decken, prueft
    # der Reiter Test an den erzeugten Stuecken (Pruefung 13, by_test.php);
    # hier stand dafuer frueher eine Namensliste, die niemand las.
    #
    # ts statt ALTER: ueber MQTT ist das Alter beim Senden immer null. Wer die
    # beiden Wege gleich behandeln will, veroeffentlicht den Zeitstempel und
    # laesst die Gegenseite rechnen. Ohne ihn ist ein toter Dienst von einem
    # gesunden nicht zu unterscheiden - es wird schlicht nichts mehr gesendet,
    # und die letzten Werte stehen weiter im Broker.
    paare: dict = {"ok": ok, "ts": int(stand.get("ts") or 0), "zaehler": zaehler_weiter()}
    # M2 (Durchgang 29.09.2026): die Fahrzeugzahl geht erst hinaus, wenn ein
    # Abruf in diesem Lauf Fahrzeuge geliefert hat. Bis 0.9.19 ging nach einem
    # Neustart mit gedrosseltem ersten Abruf "fahrzeuge 0" RETAINED hinaus -
    # eine falsche Zustandsaussage, die auch einen Broker-Neustart uebersteht
    # (gemessen, MQTT-Pruefer E4).
    if fahrzeuge:
        paare["fahrzeuge"] = len(fahrzeuge)
    else:
        # ... und je BEKANNTEM Fahrzeug fahrzeugN/OK 0 (fluechtig): sonst
        # behielten die Fahrzeug-OK-Eingaenge in Loxone ihre letzte 1.
        for n in bekannte:
            paare["fahrzeug%s/OK" % n] = 0
    for nummer, f in fahrzeuge.items():
        # Je Fahrzeug entscheiden und nicht am Sammelergebnis: bei einem
        # Teilausfall ist ok=1 (irgendetwas kam), aber fuer das ausgefallene
        # Fahrzeug ist nichts Neues zu senden. Sein ok steht im Fahrzeugsatz.
        # get("ok", 1) und nicht get("ok"): fehlt das Feld ganz, gilt der
        # Sammelstatus. Es fehlt in Fahrzeugsaetzen, die nicht aus
        # fahrzeug_abbilden() stammen - etwa aus einem Zwischenspeicher, der
        # noch von einer aelteren Fassung liegt. Ein Teilausfall setzt ok
        # ausdruecklich auf 0; nur das Fehlen wird wohlwollend gelesen. Sonst
        # verstummte das Plugin nach einem Update, bis der erste Abruf durch
        # ist - und niemand saehe, warum.
        fz_ok = 1 if (ok and f.get("ok", 1)) else 0
        if fz_ok:
            for feld in list(FELDER) + list(ABGELEITET):
                # -1-Regel (Nachtrag 29.10.2026): Messwert ohne Aussage -> -1 (MQTT_FEHLWERT).
                paare["fahrzeug%s/%s" % (nummer, feld)] = mqtt_messwert(feld, f.get(feld))
        else:
            # Bei einer Stoerung bleiben die MESSWERTE stehen - sie sind das
            # zuletzt Gemessene, und ein alter Messwert ist ehrlicher als eine
            # erfundene Null. FEHLFOLGE und LADEEMPF sind aber keine Messwerte:
            # die Hauptschleife schreibt sie im Stoerungszweig JETZT fort. Sie
            # gingen bisher nur in die Datei und nicht ueber MQTT, weil die
            # ganze Feldschleife hinter "if ok" hing. Loxone bekam ueber MQTT
            # bei einem Ausfall unveraendert FEHLFOLGE=0 - also genau in dem
            # Fall nicht, fuer den der Zaehler da ist.
            for feld in ("FEHLFOLGE", "LADEEMPF"):
                if feld in f:
                    paare["fahrzeug%s/%s" % (nummer, feld)] = f.get(feld)
        # OK je Fahrzeug: das globale ok liegt in Loxone auf einer anderen
        # Adresse als der Fahrzeugbaustein. Ohne diese Zeile muesste jeder
        # Baustein zusaetzlich das Sammel-ok verdrahtet bekommen - und selbst
        # dann bliebe der Teilausfall unsichtbar, weil das Sammel-ok dabei 1
        # ist. Was es NICHT sagt: wie lange dieses Fahrzeug schon ausfaellt.
        # Diese Dauer ist in Loxone aus dem Verlauf von OK zu bilden.
        paare["fahrzeug%s/OK" % nummer] = fz_ok
    # M3 (Entscheidung 5): ein Fahrzeug, das aus dem Konto VERSCHWUNDEN ist,
    # bekommt seine retained Zustaende einmal als "-" (None wird in
    # mqtt_senden() zu "-") und OK 0. Bis 0.9.19 blieben seine 25 Zustaende
    # retained stehen und wurden nach jedem Neustart von Broker oder Gateway
    # weiter gemeldet (gemessen, MQTT-Pruefer E3). Ein nur zeitweise
    # SCHWEIGENDES Fahrzeug ist etwas anderes (Frage 11): seine Zustaende
    # bleiben, nur OK geht auf 0 - das leistet der Zweig darueber.
    for n in weg:
        for feld in sorted(RETAIN_FELDER):
            paare["fahrzeug%s/%s" % (n, feld)] = None
        paare["fahrzeug%s/OK" % n] = 0
    # Welche Themen zurueckbehalten hinausgehen - Begruendung je Feld bei
    # RETAIN_FELDER. Die Fahrzeugzahl ist ein Zustand; ok und ts sind das
    # Lebenszeichen und gehen nie retained, FEHLFOLGE ebenso nicht.
    retain = {"fahrzeuge"} | {k for k in paare
                              if k.startswith("fahrzeug") and "/" in k
                              and k.split("/", 1)[1] in RETAIN_FELDER}
    # ERST die Altwerte frueherer Fassungen am Broker abraeumen, DANN senden:
    # der Broker reicht die Loeschung an das Gateway weiter, das Gateway als
    # leeren Wert an den Miniserver (Regeln/07) - der gueltige Wert muss
    # unmittelbar hinterher, und das leistet mqtt_senden() im selben Lauf.
    # Ein Fehlschlag haelt den Lauf nicht an.
    try:
        mqtt_altlast_abraeumen(praefix)
    except Exception as err:  # noqa: BLE001
        melde_gebremst("retain_altlast",
                       "MQTT: Abraeumen der Altwerte uebersprungen (%s)." % fehlertext(err))
    versucht, schlecht = mqtt_senden(paare, praefix, retain)
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
async def einmal_abrufen(client, cfg: dict, merker: dict = None) -> tuple[dict, str, dict]:
    """Rueckgabe: (Fahrzeuge, Fehlertext, Rohdaten)."""
    roh_alle: dict = {}
    liste = await client.get_vehicles()
    if not liste:
        return ({}, "Das BYD-Konto fuehrt kein Fahrzeug.", roh_alle)

    fahrzeuge: dict = {}

    def vin_von(f) -> str:
        d = flach(zu_dict(f))
        w, _ = hole(d, ("vin",))
        return str(w or "")

    # Die laufende Nummer ist eine ADRESSE, keine Zaehlung. Sie kommt aus der
    # dauerhaften Zuordnung im merker; warum das Sortieren allein nicht
    # genuegt, steht bei nummern_zuordnen(). Ohne merker - beim Handaufruf -
    # bleibt es bei der Aufzaehlung, dort wird nichts verdrahtet.
    geordnet = sorted(liste, key=vin_von)
    zuordnung: dict = {}
    if merker is not None:
        zuordnung, neu = nummern_zuordnen([vin_von(f) for f in geordnet], merker)
        if neu:
            # Sofort ablegen und nicht auf das Ende des Durchlaufs warten: eine
            # Zuordnung, die einen Absturz nicht ueberlebt, ist keine.
            merker_schreiben(merker)
    naechste = max(list(zuordnung.values()) + [0]) + 1

    for fz in geordnet:
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
        if vin and vin in zuordnung:
            nummer = str(zuordnung[vin])
        else:
            # Kein VIN, keine Adresse: hinten anhaengen. nummern_zuordnen()
            # hat das bereits gemeldet.
            nummer = str(naechste)
            naechste += 1
        fahrzeuge[nummer] = abbild
        roh_alle[nummer] = {"stamm": stamm, "echtzeit": echtzeit, "gps": gps}
    return (fahrzeuge, "", roh_alle)


async def mit_frist(coro, sekunden: int, was: str = "Abruf"):
    """Bricht eine haengende Coroutine nach $sekunden geordnet ab.

    Vorher stand hier ein Wecker (signal.alarm). Der bricht zwar auch ab, aber
    falsch: SIGALRM wirft die Ausnahme irgendwo im Hauptstrang, mitten in einen
    laufenden await hinein. Die Bibliothek unter uns bekommt keine Gelegenheit
    aufzuraeumen - offene Verbindungen und halbfertige Sitzungen bleiben
    zurueck, und beim naechsten Durchlauf faengt es damit an. Ausserdem gibt es
    SIGALRM nur dort, wo es SIGALRM gibt; die Abfrage hasattr(signal, "SIGALRM")
    hiess in Wahrheit: auf manchen Systemen wirkt die Grenze gar nicht, ohne
    dass jemand es merkt.

    asyncio.wait_for bricht dagegen die AUFGABE ab. Der Abbruch kommt an der
    Stelle an, an der die Coroutine gerade wartet, und die Bibliothek darf ihre
    finally-Zweige laufen lassen.

    Der Fehlertext bleibt derselbe, damit die Meldung im Protokoll nicht mit
    dem Umbau eine andere wird.
    """
    sekunden = int(sekunden)
    if sekunden <= 0:
        return await coro
    try:
        return await asyncio.wait_for(coro, sekunden)
    except asyncio.TimeoutError:
        # In eine TimeoutError des Sprachkerns wandeln: die Aufrufer fangen
        # Exception, und die Meldung soll sagen, WAS zu lange gebraucht hat.
        # (Ab Python 3.11 ist asyncio.TimeoutError ohnehin dasselbe.)
        raise TimeoutError("%s hat laenger als %d s gebraucht - abgebrochen."
                           % (was, sekunden)) from None


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
        mqtt_ausfall_melden(cfg, merker_lesen())
        return 1
    # C3: gilt die Anmeldesperre, meldet sich der Dienst gar nicht erst an.
    if anmeldung_lesen()["gesperrt"]:
        melde_gebremst("anmeldesperre", ANMELDESPERRE_TEXT, 3600)
        zustand_schreiben(ok=0, fehler=ANMELDESPERRE_TEXT)
        mqtt_ausfall_melden(cfg, merker_lesen())
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
    # Laden-1: rechnet in der Wartezeit alle 30 s (Preisempfehlung.takt()).
    preis = Preisempfehlung()
    merker = merker_lesen()
    # Gruppe 42 (B2/G6): Merker der Vorklimatisierung liegen in merker.json; Kosten der letzten Ladung je
    # Fahrzeug (Thema bis zur naechsten Ladung); offene Mitschriften (uebersteht Neustarts).
    lk = json_lesen(DATEI_LADEKOSTEN)
    _G42["ladung"] = {str(k): v for k, v in lk.items() if isinstance(v, dict)}
    ladung_mitschrift_holen(time.time())
    # M3: welche verschwundenen Fahrzeuge in DIESEM Lauf schon "-" bekamen.
    # Bewusst im Speicher und nicht im merker: ueber UDP bestaetigt nichts, ob
    # es ankam (Regeln/07) - nach einem Neustart des Dienstes geht es noch
    # einmal hinaus.
    weg_gemeldet: set = set()
    # M5: die Abodatei beim Dienststart.
    abodatei_nachfuehren(cfg)

    client = None
    _sitzung = None
    try:
        # Die ANMELDUNG unter eine Frist stellen. Sie steckt im Betreten des
        # Kontextmanagers - und ein "async with" laesst sich von aussen nicht
        # befristen. asyncio.timeout als Kontextmanager gaebe es, aber erst ab
        # Python 3.11; die Zielsysteme fuehren auch aeltere. Darum wird der
        # Manager hier von Hand betreten und im finally von Hand verlassen,
        # damit die Sitzung auf JEDEM Weg zugeht.
        #
        # Ohne diese Frist hielt eine Gegenstelle, die die Verbindung annimmt
        # und dann schweigt, den Dienst schon VOR dem ersten Abruf an - und
        # zwar unbegrenzt. Die Grenze am Abruf half da nicht, denn es kam nie
        # zu einem Abruf.
        _sitzung = BydClient(konf)
        try:
            client = await mit_frist(_sitzung.__aenter__(), GRENZE_ANMELDUNG,
                                     "Die Anmeldung bei BYD")
        except Exception as err:  # noqa: BLE001
            # C3: eine ABGEWIESENE Anmeldung wird dauerhaft vermerkt; ein
            # Netz- oder Zeitfehler nicht (ist_anmeldefehler()).
            if ist_anmeldefehler(err):
                anmeldung_abgewiesen(fehlertext(err))
            raise
        anmeldung_gelungen()
        # Wann wurde zuletzt bei BYD angefragt - gelungen ODER nicht. Nicht
        # stand["ts"]: der bleibt bei einer Stoerung absichtlich stehen, und
        # die Bremse gegen den Mindesttakt oeffnete dann gerade dann, wenn die
        # Gegenstelle ohnehin zickt.
        letzter_versuch = 0
        # Einmal erzwungen, bevor der erste Abruf laeuft: sonst gaebe es beim
        # Start eine Luecke von der Laenge eines Abrufs, in der der Waechter
        # eine alte oder gar keine Datei saehe.
        herzschlag(True)
        while _LAUF:
            herzschlag()
            cfg = config()   # Aenderungen aus der Oberflaeche ohne Neustart
            # Abos nachziehen, nicht die Verbindung neu aufbauen: ein
            # Neuaufbau leert den Zwischenspeicher des Horchers. Ausfuehrlich
            # bei der Klasse Horcher, Punkt 3.
            horcher.sicherstellen(horcher_themen(cfg))
            ok = 0
            fehler = ""
            fahrzeuge: dict = {}
            try:
                # VOR dem Abruf setzen und nicht danach: gelingt er nicht,
                # zaehlt der Versuch trotzdem gegen die Bremse.
                letzter_versuch = int(time.time())
                fahrzeuge, fehler, roh = await mit_frist(
                    einmal_abrufen(client, cfg, merker),
                    GRENZE_ABRUF, "Der Abruf bei BYD")
                if roh:
                    json_schreiben(DATEI_ROH, {"ts": int(time.time()), "roh": roh},
                                   0o600)
                ok = 1 if fahrzeuge and any(f.get("ok") for f in fahrzeuge.values()) else 0
                fehler_folge = 0 if ok else fehler_folge + 1
            except Exception as err:  # noqa: BLE001
                fehler = fehlertext(err)
                fehler_folge += 1
                melde_gebremst("abruf", "Abruf fehlgeschlagen: " + fehler, 900)
                # C3: weist BYD waehrend des Betriebs die Anmeldung ab (etwa
                # nach einer Passwortaenderung in der App), zaehlt das ebenso;
                # gilt danach die Sperre, endet der Dienst.
                if ist_anmeldefehler(err) and anmeldung_abgewiesen(fehler):
                    raise

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
                # Teilausfall: die bewahrten Werte mit den frischen
                # mischen. Warum ersetzen falsch waere, steht bei
                # stand_zusammenfuehren().
                stand = {"ts": int(time.time()),
                         "fahrzeuge": stand_zusammenfuehren(
                             stand.get("fahrzeuge") or {}, fahrzeuge)}
            else:
                # Bei einer Stoerung bleiben die Messwerte stehen - aber der
                # Stoerungszaehler und die Ladeempfehlung gelten JETZT und
                # werden fortgeschrieben. Sonst meldete Loxone bei einem
                # Ausfall unveraendert FEHLFOLGE=0.
                for fz in (stand.get("fahrzeuge") or {}).values():
                    fz["FEHLFOLGE"] = int(fehler_folge)
                    fz["LADEEMPF"] = empfehlung
            # M3: verschwunden ist ein bekanntes Fahrzeug, das ein Abruf mit
            # Fahrzeugliste NICHT mehr fuehrt. Taucht es wieder auf, darf es
            # beim naechsten Verschwinden erneut gemeldet werden.
            weg: list = []
            if fahrzeuge:
                for n in bekannte_nummern(merker):
                    if n in fahrzeuge:
                        weg_gemeldet.discard(n)
                    elif n not in weg_gemeldet:
                        weg.append(n)
            abbild_schreiben(stand, cfg, ok, fehler,
                             bekannte=bekannte_nummern(merker), weg=weg)
            if cfg.get("mqtt_ein"):
                weg_gemeldet.update(weg)
            zustand_schreiben(ok=ok, fehler=fehler, fehler_folge=fehler_folge,
                              pid=os.getpid(), intervall=cfg["intervall"],
                              anzahl_fahrzeuge=len(stand["fahrzeuge"]),
                              konfiguration=bericht,
                              horcher=sorted(horcher.themen),
                              horcher_verbunden=1 if horcher.verbunden else 0,
                              horcher_fehler=horcher.fehler,
                              haus=haus_zustand(horcher.haus, time.time()))

            # Nr. 36 b: Ansagen - nach Abbild, MQTT und Zustand, in einem Hilfsfaden:
            # die Bruecke ist ein Prozessaufruf (bis ANSAGE_FRIST_S), und die Schleife
            # bleibt fuer Befehle, Herzschlag und Vorklimatisierung frei.
            try:
                await asyncio.get_running_loop().run_in_executor(
                    None, ansagen, cfg, fahrzeuge if ok else {}, ok, fehler_folge)
            except Exception as err:  # noqa: BLE001
                melde_gebremst("ansage", "Ansage: %s" % fehlertext(err), 3600)

            # Gruppe 42: Vorklimatisierung, Ankunft, Preisprobe - einmal nach dem Abbild (damit sie nicht einen
            # Takt zu spaet kommt), danach alle 10 s in der Wartezeit (g42_takt()).
            try:
                await g42_takt(client, horcher, stand, cfg, z, freigeschaltet, merker, letzter_versuch)
            except Exception as err:  # noqa: BLE001
                _LOG.error("Gruppe 42: %s", fehlertext(err))

            if einmal:
                return 0 if ok else 1

            rest = cfg["intervall"]
            # C5: das Kontingent schaltender Befehle gilt je Takt.
            kontingent = {"rest": BEFEHLE_JE_LAUF}
            if fehler_folge >= 3:
                rest = min(3600, cfg["intervall"] * min(8, fehler_folge))
                melde_gebremst("bremse",
                               "%d Fehlversuche - naechster Abruf erst in %d s."
                               % (fehler_folge, rest), 1800)
            while rest > 0 and _LAUF:
                # Auch waehrend der Wartezeit schlagen: sie kann nach mehreren
                # Fehlversuchen bis zu einer Stunde dauern, und ein Dienst, der
                # planmaessig wartet, ist kein haengender.
                herzschlag()
                # Laden-1: die Stunde wechselt unabhaengig vom Abruftakt (bis
                # 3600 s) - gerechnet wird deshalb hier, in der Wartezeit.
                try:
                    preis.takt(horcher)
                except Exception as err:  # noqa: BLE001
                    melde_gebremst("preisempf", "Ladeempfehlung nach Strompreis: %s"
                                   % fehlertext(err), 3600)
                try:
                    # letzter_versuch mitgeben: daran haengt die Bremse gegen
                    # den Mindesttakt. Die Zahl stand bisher nur im Abbild und
                    # wurde von niemandem gelesen.
                    if await warteschlange(client, stand["fahrzeuge"], cfg, z,
                                           freigeschaltet, letzter_versuch,
                                           kontingent):
                        break     # Sofortabruf angefordert und durchgelassen
                except Exception as err:  # noqa: BLE001
                    _LOG.error("Warteschlange: %s", fehlertext(err))
                # Gruppe 42: alle 10 s Vorklimatisierung, Ankunft, Preisprobe; alle 30 s die Frische der
                # Haus-Quellen fuer den Reiter Test.
                if rest % 10 == 0:
                    try:
                        if rest % 30 == 0:
                            zustand_schreiben(haus=haus_zustand(horcher.haus, time.time()))
                        if await g42_takt(client, horcher, stand, cfg, z, freigeschaltet, merker,
                                          letzter_versuch):
                            break     # Sofortabruf (Vorklima Versuch 2 oder Ankunft), Mindesttakt um
                    except Exception as err:  # noqa: BLE001
                        _LOG.error("Gruppe 42: %s", fehlertext(err))
                await asyncio.sleep(1)
                rest -= 1
    except Exception as err:  # noqa: BLE001
        meldung = fehlertext(err)
        _LOG.error("Dienst abgebrochen: %s", meldung)
        zustand_schreiben(ok=0, fehler=meldung)
        # M6: der Dienst endet - Loxone erfaehrt es ueber ok 0 (fluechtig).
        mqtt_ausfall_melden(cfg, merker)
        return 1
    finally:
        # Die Sitzung von Hand schliessen - das Gegenstueck zum Betreten
        # oben. Ein eigenes try darum: schlaegt das Schliessen fehl, darf es
        # den Horcher nicht mit sich reissen.
        if _sitzung is not None:
            try:
                await _sitzung.__aexit__(None, None, None)
            except Exception as err:  # noqa: BLE001
                _LOG.error("Die BYD-Sitzung liess sich nicht schliessen: %s",
                           fehlertext(err))
        # Laden-1: endet der Dienst, geht einmal "-" hinaus.
        try:
            preis.abschied()
        except Exception as err:  # noqa: BLE001
            _LOG.error("Ladeempfehlung nach Strompreis beim Ende: %s", fehlertext(err))
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
async def felder_zeigen(frei: bool = True) -> int:
    """C4 (Durchgang 29.09.2026): bis 0.9.19 meldete sich jeder Aufruf neu an
    und weckte das Fahrzeug - dreimal den Knopf gedrueckt, vier Anmeldungen
    und vier Echtzeitabrufe in 4 s (gemessen, Code-Pruefer). Jetzt:
      * haelt ein anderer Lauf die Sperre (der Dienst), wird seine letzte
        Antwort gezeigt (rohdaten.json) - kein Abruf;
      * liegt der letzte Echtzeitabruf keine TAKT_MIN Sekunden zurueck, ebenso;
      * sonst gilt dieselbe Anmeldebremse wie im Dienst (C3)."""
    roh_datei = json_lesen(DATEI_ROH)
    try:
        felder_ts = int(DATEI_FELDER_ZEIT.read_text(encoding="utf-8").strip() or 0)
    except (OSError, ValueError):
        felder_ts = 0
    jetzt = int(time.time())
    zuletzt = max(ganz(roh_datei.get("ts"), 0), felder_ts)
    if not frei or (zuletzt and 0 <= jetzt - zuletzt < TAKT_MIN):
        if not frei:
            print("[INFO] Ein anderer Lauf dieses Plugins (der Abrufdienst) haelt die Sperre. "
                  "Gezeigt wird seine letzte Antwort - es wurde nichts neu abgerufen, das "
                  "Fahrzeug wird nicht geweckt.")
        else:
            print("[INFO] Der letzte Abruf ist erst %d s her (Mindestabstand %d s). Gezeigt "
                  "wird die letzte gespeicherte Antwort - es wurde nichts neu abgerufen."
                  % (jetzt - zuletzt, TAKT_MIN))
        roh = roh_datei.get("roh") if isinstance(roh_datei.get("roh"), dict) else {}
        if not roh:
            print("[FEHL] Es liegt noch keine gespeicherte Antwort vor. Nach dem ersten "
                  "Abruf des Dienstes erneut versuchen.")
            return 1
        print("[INFO] Stand der gezeigten Antwort: %s"
              % time.strftime("%d.%m.%Y %H:%M:%S", time.localtime(ganz(roh_datei.get("ts"), 0))))
        fahrzeuge = json_lesen(DATEI_LOXONE).get("fahrzeuge")
        return felder_ausgeben(fahrzeuge if isinstance(fahrzeuge, dict) else {}, roh)

    from pybyd import BydClient  # noqa: PLC0415

    cfg = config()
    z = zugang()
    if not z["benutzer"] or not z["passwort"]:
        print("[FEHL] Es sind keine Zugangsdaten hinterlegt.")
        return 1
    if anmeldung_lesen()["gesperrt"]:
        print("[FEHL] " + ANMELDESPERRE_TEXT)
        return 1
    konf, bericht = konfiguration_bauen(z, cfg)
    for k, v in sorted(bericht.items()):
        print("[INFO] Konfiguration: %-9s -> %s" % (k, v))
    # VOR dem Abruf vermerken: auch ein gescheiterter zaehlt gegen die Bremse.
    try:
        PDATA.mkdir(parents=True, exist_ok=True)
        DATEI_FELDER_ZEIT.write_text("%d\n" % jetzt, encoding="utf-8")
    except OSError:
        pass
    try:
        async with BydClient(konf) as client:
            anmeldung_gelungen()
            # Den merker MITGEBEN, damit dieser Handaufruf dieselben Nummern
            # zeigt wie der laufende Dienst. Ohne ihn zaehlte er neu durch, und
            # wer die Ausgabe zum Verdrahten benutzt, verdrahtete bei mehreren
            # Fahrzeugen womoeglich das falsche.
            fahrzeuge, fehler, roh = await einmal_abrufen(client, cfg, merker_lesen())
    except Exception as err:  # noqa: BLE001
        if ist_anmeldefehler(err):
            anmeldung_abgewiesen(fehlertext(err))
        raise
    if fehler:
        print("[INFO] " + fehler)
    if not roh:
        print("[FEHL] Es kam keine Antwort - es gibt nichts zu zeigen.")
        return 1
    return felder_ausgeben(fahrzeuge, roh)


def felder_ausgeben(fahrzeuge: dict, roh: dict) -> int:
    """Die Ausgabe von --felder: jedes Blatt mit Pfad und getroffenem Feld."""
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
    if not INSTALLIERT:
        zeilen.append("[INFO] Dieses byd.py liegt nicht im bin-Ordner der Anlage "
                      "(ausgepacktes Archiv oder Pruefordner). Es legt nichts an; die "
                      "folgenden Pfadzeilen sagen nichts ueber eine Installation.")

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
        # BYD-a2: ohne eine Klasse *AuthenticationError erkennt der Dienst eine
        # abgewiesene Anmeldung nicht - dann greift die Anmeldesperre (C3) nie.
        # Eine WARNUNG, kein Fehler: abrufen kann die Bibliothek trotzdem.
        klassen = anmeldefehler_klassen()
        if klassen:
            zeilen.append("[OK]   pybyd fuehrt die Ausnahmeklasse %s - eine abgewiesene "
                          "Anmeldung zaehlt fuer die Anmeldesperre (hoechstens %d in 24 "
                          "Stunden)" % (", ".join(klassen), ANMELDE_GRENZE))
        else:
            zeilen.append("[WARN] pybyd (Fassung %s) fuehrt keine Ausnahmeklasse *AuthenticationError. "
                          "Die Anmeldesperre (hoechstens %d abgewiesene Anmeldungen in 24 "
                          "Stunden, dann Stillstand bis zu neuen Zugangsdaten) erkennt "
                          "eine abgewiesene Anmeldung NUR an dieser Klasse und greift mit "
                          "dieser Fassung nie. Falsche Zugangsdaten bremst dann nur der "
                          "Waechter: drei Neustarts, danach einer je 30 Minuten - rund 50 "
                          "Anmeldeversuche am Tag, und BYD kann das Konto sperren. "
                          "Zugangsdaten sorgfaeltig pruefen und den Dienst anhalten, "
                          "wenn die Anmeldung scheitert." % (fassung, ANMELDE_GRENZE))

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
    zeilen.append("[INFO] Eingreifende Befehle (Verriegeln/Entriegeln, Suchen, Blinken): %s"
                  % ("zugelassen (zweiter Haken gesetzt)" if c.get("gefahr_ein")
                     else "gesperrt (zweiter Haken aus, ab Werk)"))

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
    if c.get("preisempf_ein"):
        pe = json_lesen(DATEI_PREISEMPF)
        zeilen.append("[INFO] Ladeempfehlung nach Strompreis: Quelle %s (Themen %s/rank, "
                      "%s/rankd), guenstigste %d Stunden; letzter Stand: %s"
                      % (c["preisempf_quelle"], preis_praefix(c), preis_praefix(c),
                         c["preisempf_stunden"],
                         ("%s (%s)" % (pe.get("wert"), pe.get("grund"))) if pe
                         else "noch keiner - der Dienst rechnet alle 30 s"))
    else:
        zeilen.append("[INFO] Ladeempfehlung nach Strompreis ist ausgeschaltet")
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
                # Getrennt ausgeben. Die Zahl darueber zaehlt FELDER; das
                # Verzeichnis 'getroffen' fuehrt aber auch die sieben
                # Stammangaben (vin, name, kennzeichen, marke, modell,
                # antriebsart, tbox). Zusammen ausgegeben las sich das wie ein
                # Widerspruch: "8 von 14 Feldern aufgeloest" und darunter 15
                # getroffene Namen. Wer nachzaehlte, zaehlte zwei verschiedene
                # Mengen.
                tr_feld = {k: v for k, v in getroffen.items() if k in FELDER}
                tr_stamm = {k: v for k, v in getroffen.items() if k not in FELDER}
                if tr_feld:
                    zeilen.append("[INFO] Getroffene Feldnamen (%d): %s"
                                  % (len(tr_feld),
                                     ", ".join("%s=%s" % (k, v)
                                               for k, v in sorted(tr_feld.items()))))
                if tr_stamm:
                    zeilen.append("[INFO] Getroffene Stammangaben (%d, zaehlen "
                                  "nicht zu den Feldern): %s"
                                  % (len(tr_stamm),
                                     ", ".join("%s=%s" % (k, v)
                                               for k, v in sorted(tr_stamm.items()))))
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


# C2 (Durchgang 29.09.2026): bis 0.9.19 lief jeder andere Aufruf als Dienst -
# "byd.py --gibtsnicht" meldete sich an und lief bis zur Zeitgrenze (gemessen,
# Code-Pruefer T1). Erlaubt sind genau diese Schalter; --praefix=<thema> nur
# zusammen mit --mqtt-leeren (M9).
SCHALTER = ("--einmal", "--selbsttest", "--felder", "--mqtt-leeren", "--signal-test")
_SPERRE = None


def dienst_sperre():
    """C1: nicht blockierende Sperre auf dieser Datei - ein Dienst, ein
    --einmal oder ein --felder zur Zeit. Zwei gleichzeitige Starts ergaben bis
    0.9.19 zwei Dienste und zwei Anmeldungen (gemessen, Code-Pruefer T2). Die
    Sperre haengt an dieser Datei: sie liegt immer da, es muss nichts angelegt
    werden, und eine zweite Installation (anderer Ordner) sperrt eine andere
    Datei. Bauform AudiConnect 0.9.22.
    Rueckgabe: der Griff (haelt die Sperre), None = ein anderer haelt sie."""
    import fcntl  # noqa: PLC0415
    try:
        griff = open(os.path.abspath(__file__), "rb")
    except OSError as err:
        sys.stderr.write("WARNUNG: Sperrdatei nicht lesbar (%s) - ohne Sperre weiter.\n" % err)
        return True
    try:
        fcntl.flock(griff.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        griff.close()
        return None
    return griff


VERVOLLSTAENDIGEN_PHP = "php"     # die Pruefstaende setzen hier eine Attrappe ein


def konfiguration_vervollstaendigen() -> str:
    """Seit 0.9.39 (Regeln/05): fehlende Vorgaben einmal in die Datei schreiben - beim Dienststart.

    Geschrieben wird ueber bin/by_vervollstaendigen.php und damit ueber by_lib.php, nicht von hier:
    ein Schreiber fuer ein Format (Bauart BLE-Scanner 1.3.40). Die Zeile mit den Namen schreibt
    by_config_vervollstaendigen() selbst ins Protokoll; hier steht nur eine Zeile, wenn der Aufruf
    scheiterte - nie ein Wert. Waehrend einer Aktualisierung (Marke) schreibt die PHP-Seite nichts;
    dann traegt postupgrade.sh nach. Rueckgabe: die Ausgabezeile des Skripts oder "" (fuer die Pruefung)."""
    skript = Path(__file__).resolve().parent / "by_vervollstaendigen.php"
    try:
        r = subprocess.run([VERVOLLSTAENDIGEN_PHP, str(skript), PNAME], stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, timeout=20, check=False)
    except (OSError, subprocess.SubprocessError) as err:
        _LOG.warning("Konfiguration nicht vervollstaendigt: Aufruf von %s scheiterte (%s)", skript.name,
                     type(err).__name__)
        return ""
    zeilen = (r.stdout or b"").decode("utf-8", "replace").strip().splitlines()
    zeile = zeilen[-1] if zeilen else ""
    if r.returncode != 0 or not zeile.startswith("VERVOLLSTAENDIGEN;"):
        _LOG.warning("Konfiguration nicht vervollstaendigt: Rueckgabe %s, Ausgabe %r", r.returncode, zeile[:200])
    return zeile


def main() -> int:
    global _SPERRE
    praefix_arg = [a for a in sys.argv[1:] if a.startswith("--praefix=")]
    fremd = [a for a in sys.argv[1:] if a not in SCHALTER and a not in praefix_arg]
    if praefix_arg and "--mqtt-leeren" not in sys.argv:
        fremd += praefix_arg
    if fremd:
        sys.stderr.write("FEHLER: unbekannter Schalter %s. Erlaubt: %s (--praefix=<thema> nur "
                         "mit --mqtt-leeren), oder ohne Schalter fuer den Dienst. Es wurde "
                         "nichts gestartet und nichts veraendert.\n"
                         % (" ".join(fremd), ", ".join(SCHALTER)))
        return 2
    # --mqtt-leeren kommt aus uninstall/uninstall und redet im Format des
    # Installers auf stdout - deshalb VOR log_einrichten(). Nur aus der
    # Anlage: aus einem Archiv unter einer echten Wurzel leerte es sonst die
    # Themen der Anlage unter dem Vorgabepraefix (Fall Y4).
    if "--mqtt-leeren" in sys.argv:
        if not INSTALLIERT:
            print("<WARNING> MQTT: dieses byd.py (%s) liegt nicht im bin-Ordner der "
                  "Anlage - es wird nichts geleert." % SELF)
            return 2
        if praefix_arg:
            return mqtt_leeren(praefix=praefix_arg[-1][len("--praefix="):])
        return max(mqtt_leeren(), haus_fz_leeren())     # 0.9.31: auch haus/fahrzeug/<name>/; nicht bei --praefix=
    # Gruppe 42: Testmeldung an SignalBot (Knopf im Reiter Einstellungen). Ohne Protokoll, ohne Sperre - er
    # laeuft neben dem Dienst; eine Zeile fuer die Oberflaeche (by_signal_test_lesen()).
    if "--signal-test" in sys.argv:
        e, text = signal_test()
        print("SIGNAL_TEST;ERGEBNIS=%s;TEXT=%s" % (e, text))
        return 0 if e == "angenommen" else 1
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    if "--felder" in sys.argv:
        # C4: dieselbe Sperre wie der Dienst; haelt sie ein anderer, wird
        # dessen letzte Antwort gezeigt.
        _SPERRE = dienst_sperre()
        try:
            return asyncio.run(felder_zeigen(_SPERRE is not None))
        except Exception as err:  # noqa: BLE001
            print("[FEHL] " + fehlertext(err))
            return 1
    # Ein Dienst laeuft nur aus der Anlage. Bis 0.9.16 lief er auch aus einem
    # ausgepackten Archiv unter einer echten Wurzel und schrieb in deren
    # Datenordner (in WSL gemessen 24.09.2026, Faelle Y3 und Y8).
    if not INSTALLIERT:
        print("FEHLER: Dieses byd.py (%s) liegt nicht im bin-Ordner der Anlage %s - es "
              "wird kein Dienst gestartet und nichts angelegt." % (SELF, LBHOME),
              file=sys.stderr)
        return 1
    _SPERRE = dienst_sperre()
    if _SPERRE is None:
        text = ("Ein anderer Lauf dieses Plugins (Dienst, --einmal oder --felder) haelt die "
                "Sperre - dieser Aufruf endet, ohne sich bei BYD anzumelden.")
        sys.stderr.write("FEHLER: " + text + "\n")
        _LOG.warning(text)
        return 3
    # 0.9.39 (Regeln/05): fehlende Vorgaben einmal in die Datei - vor dem ersten Lesen in dienst_lauf().
    konfiguration_vervollstaendigen()
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
