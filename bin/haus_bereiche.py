"""Gemeinsame Bereiche unter haus/ - die Praefixregel der Linien (Python-Fassung).

Grundlage: Regeln/07, Abschnitt "Haus-Themen fuer das Zusammenspiel der Linien", Absatz "Praefix einer Linie im
gemeinsamen Bereich" (Entscheidung des Hausherrn 23.11.2026 "Nur gemeinsame Bereiche").

Stammfassung: Werkzeuge/gemeinsam/haus_bereiche.py. In jeder Linie, deren Python-Dienst mit dem einstellbaren
MQTT-Praefix sendet, BYTE-GLEICH neben dem Dienst (bin/, bei Midea2Lox data/). Dieselbe Liste und dieselben
Funktionen wie haus_bereiche.php; Werkzeuge/gemeinsam_pruefen.py haelt beide gegen Regeln/07. Ein schon
gespeichertes Praefix sendet der Dienst weiter wie bisher und meldet es nur (hb_melden_faellig(), hb_logtext()).
Verglichen wird genau (MQTT unterscheidet Gross und Klein); Schraegstriche und Leerraum am Rand zaehlen nicht.
Der Dienst schreibt list(HB_BEREICHE) in seinen Zustand ("haus_bereiche"); der Reiter Test vergleicht sie mit
hb_bereiche() der Oberflaeche.

Laeuft auf: Python 3, nur Standardbibliothek (geprueft mit dem Python des Arbeitsplatzes, siehe Eichung).
"""

HB_FASSUNG = "1.0.0"

HB_BEREICHE = ("abfahrt", "abfuhr", "alarm", "ansage", "anwesenheit", "energie", "fahrzeug", "kalender", "klima", "pv",
               "raumklima", "sonne", "speicher", "strompreis", "szene", "tag", "tuer", "usv", "wetter")

_IM_PROZESS = {"gemeldet": ""}


def hb_bereich(praefix):
    """Der gemeinsame Bereich, auf den das Praefix faellt (etwa "tuer"), sonst ""."""
    if not isinstance(praefix, (str, int)) or isinstance(praefix, bool):
        return ""
    t = str(praefix).strip("/ \t\r\n").split("/")
    return t[1] if len(t) >= 2 and t[0] == "haus" and t[1] in HB_BEREICHE else ""


def hb_melden_faellig(praefix, merkdatei=""):
    """Der Bereich, wenn jetzt eine Protokollzeile faellig ist (Praefix im Bereich und seit der letzten Meldung
    ein anderes), sonst "". merkdatei "" merkt im laufenden Prozess; sonst steht das zuletzt gemeldete Praefix in
    der Datei. Laesst sie sich nicht schreiben, wird bei jedem Aufruf gemeldet - lieber zu oft als nie."""
    bereich = hb_bereich(praefix)
    jetzt = str(praefix).strip() if bereich else ""
    if not merkdatei:
        vorher = _IM_PROZESS["gemeldet"]
        _IM_PROZESS["gemeldet"] = jetzt
    else:
        try:
            with open(merkdatei, "r", encoding="utf-8") as f:
                vorher = f.read().strip()
        except OSError:
            vorher = ""
        if vorher != jetzt:
            try:
                with open(merkdatei, "w", encoding="utf-8") as f:
                    f.write(jetzt)
            except OSError:
                pass
    return bereich if jetzt and jetzt != vorher else ""


def hb_logtext(praefix, bereich):
    """Die Protokollzeile (ASCII, gleich in allen Linien und in haus_bereiche.php)."""
    return ("MQTT: das Themen-Praefix " + str(praefix).strip() + " faellt auf den gemeinsamen Bereich haus/"
            + bereich + " - dort liefern die Plugins einander Zustaende ohne eigenes Praefix. Der Betrieb laeuft "
            "unveraendert weiter; bitte in den Einstellungen ein anderes Praefix eintragen (Speichern weist es ab).")
