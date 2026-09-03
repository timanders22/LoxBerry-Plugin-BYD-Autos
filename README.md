# LoxBerry-Plugin: BYD Autos

Version 0.9.6

Bindet **Fahrzeuge von BYD** über das BYD-Konto an Loxone an: Ladezustand,
Kilometerstand, Reichweite, Ladezustand des Steckers, Restladezeit,
Geschwindigkeit, Fahrzeugzustand, Erreichbarkeit, Zündung, Türschloss der
Fahrertür, Sitz- und Batterieheizung sowie den Standort. Auf Wunsch lassen sich
Verriegelung, Klimatisierung, Sitz- und Batterieheizung schalten, das Fahrzeug
suchen lassen, blinken und die Fenster schließen. Dazu kommen fünf
**gerechnete** Felder, eine Liste der erkannten Ladevorgänge, eine
Vorklimatisierung am Abfahrtsassistenten und ein **Trockenlauf** für jeden
schreibenden Befehl.

> ## Fassung 0.9.x — ungeprüft, und das ist wörtlich gemeint
>
> **BYD veröffentlicht keine Beschreibung seiner Schnittstelle.** Das Plugin
> wurde ohne BYD-Konto und ohne Fahrzeug gebaut. Was es über Feldnamen und
> Befehle weiß, stammt aus zwei offenen Quellen (unten benannt) und ist an
> **keinem** Fahrzeug gemessen.
>
> Geprüft ist alles, was ohne Konto und ohne Auto prüfbar ist: PHP-Syntax gegen
> beide Fassungen, die Oberfläche unter 7.4 und 8.4 gerendert, der
> Aktualisierungsfall, der Formularschutz, die Sprachdateien in allen drei
> Lesemodi, die Installationslage mit getrennten Bäumen, die Zeilenenden. Dazu
> der **Trockenlauf**: gemessen ist, dass er ohne Verbindung antwortet, dass er
> mit `PROBE` beginnt und dass derselbe Befehl **ohne** den Haken abgewiesen
> wird. Was das alles nicht ersetzt, steht unten unter „Was offen ist".
>
> Deshalb 0.9.x und nicht 1.0.0, deshalb sind schreibende Befehle ab Werk
> gesperrt, und deshalb trägt die Feldtabelle im Reiter *Einbindung in Loxone*
> eine Spalte **Herkunft**. Ein Feld, das niemand gemessen hat, darf nicht
> aussehen wie eines, das jemand gemessen hat.

## Woher das Wissen über die Schnittstelle stammt

Zwei freie Arbeiten, beide öffentlich:

* **[pybyd](https://github.com/jkaberg/pyBYD)** — ein asynchroner Python-Client
  für die BYD-Fahrzeugschnittstelle, MIT-Lizenz. Dieses Plugin benutzt ihn als
  Bibliothek; installiert wird die Fassung **0.0.73** (die neueste zum
  Bauzeitpunkt). Der Autor kennzeichnet sie ausdrücklich als **Alpha**: „API
  may evolve before 1.0". Voraussetzung ist **Python 3.11 oder neuer**.
* **[ioBroker.byd](https://github.com/TA2k/ioBroker.byd)** — ein
  ioBroker-Adapter für dieselbe Schnittstelle. Aus seinem Quelltext stammen die
  Feldnamen, die Bedeutung von `chargeState` (0 nicht verbunden, 1 lädt, 15
  Stecker steckt) und der Hinweis, dass die Felder `chargingState` und
  `connectState` auf manchen Modellen dauerhaft `-1` liefern und deshalb
  **nicht** ausgewertet werden. Genau deshalb fragt dieses Plugin sie auch
  nicht ab. Von dort kommt auch die Vorgabe von 300 Sekunden für den Takt.

Beide bauen auf einer Untersuchung des Krypto-Wegs der BYD-App auf. Für das
Plugin heißt das: **die Anmeldung und die Verschlüsselung werden nicht
nachgebaut**, sondern der Bibliothek überlassen. Ein selbst nachgebautes
Protokoll ohne Prüfwerte aus dem Original wäre geraten, nicht gemessen.

## Warum die Feldzuordnung über Kandidatenlisten läuft

Einen einzelnen Feldnamen zu raten wäre hier derselbe Fehler wie eine geratene
Registeradresse. pybyd ist Alpha; die Schreibweise eines Feldes kann sich
ändern. Deshalb:

* Jedes Feld nennt **mehrere zulässige Schreibweisen**. Verglichen wird ohne
  Unterstriche und ohne Groß-/Kleinschreibung, damit `elecPercent`,
  `elec_percent` und `ELEC_PERCENT` dasselbe treffen.
* Welcher Kandidat wirklich getroffen hat, steht im Abbild und im Reiter *Test*.
* Ein Feld, das nichts getroffen hat, bleibt **leer**. Es wird keine 0
  erfunden: eine 0 sähe in Loxone aus wie ein Messwert.
* Die ganze Antwort der Bibliothek wird zusätzlich unverändert abgelegt. Der
  Knopf **„Feldzuordnung vorschlagen"** im Reiter Test listet daraus **jedes
  Blatt mit seinem Pfad** auf, samt der Angabe, welches Feld darauf getroffen
  hat. Damit beantwortet das Gerät die Frage nach den Namen.

Dasselbe gilt für die Bibliothek selbst: welche Konfigurationsfelder und welche
Befehlsmethoden die **installierte** Fassung anbietet, wird zur Laufzeit
erfragt und nicht angenommen. Was sie nicht anbietet, wird **abgewiesen** und
nicht heimlich übergangen — der Selbsttest listet es auf.

## Aufbau

Drei Aufgaben, drei Dateien — nie vermischt:

| Datei | Aufgabe | Aufrufer |
|---|---|---|
| `bin/byd.py` | Abrufdienst, Dauerlauf | Cron-Wächter, Oberfläche |
| `webfrontend/htmlauth/index.php` | **nur** Bedienoberfläche | der Mensch |
| `webfrontend/html/index.php` | Endpunkt | der Miniserver |

Der Endpunkt liest ausschließlich den Zwischenspeicher und antwortet dem
Miniserver damit in Millisekunden statt in Sekunden. Schreibende Befehle laufen
über eine Dateiwarteschlange: der Endpunkt legt eine Datei ab, der Dienst
arbeitet sie ab und legt die Antwort daneben. **Der Endpunkt spricht nie selbst
mit BYD.**

Die gemeinsame Bibliothek `by_lib.php` liegt unter `webfrontend/html/`, weil
der Endpunkt sie ebenso braucht wie die Oberfläche — eine Datei statt zweier
Kopien, die auseinanderlaufen. Installiert liegen `html/` und `htmlauth/` in
**getrennten Bäumen**; die Oberfläche sucht sie deshalb über eine
Kandidatenliste.

## Voraussetzungen

* **LoxBerry 3.0.1 oder neuer.** Nicht 3.0.0: das läuft auf Debian 11 und
  bringt Python 3.9 mit, und pybyd verlangt 3.11. Auf einem LoxBerry 3.0.0
  ließe sich das Plugin installieren und der Dienst könnte nie starten — eine
  benannte Absage ist besser als ein stillschweigend totes Plugin.
* **Internetverbindung bei der Installation.** pybyd kommt über pip in eine
  eigene virtuelle Umgebung unter `bin/plugins/<ordner>/venv`. Systemweites
  `pip3 install` wäre auf Debian 12 und 13 ohnehin abgewiesen (PEP 668).
* **Die Debian-Pakete `python3-venv` und `python3-pip`** stehen in `dpkg/apt`;
  LoxBerry spielt sie während der Installation als root ein.
* Ein **BYD-Konto** mit mindestens einem eingetragenen Fahrzeug. Für schreibende
  Befehle zusätzlich die **Steuer-PIN** des Kontos.

## Erste Einrichtung

1. Reiter *Einstellungen*: Benutzername und Passwort des BYD-Kontos eintragen —
   dieselben wie in der BYD-App. Für schreibende Befehle die Steuer-PIN dazu.
2. Dienst starten, einen Takt abwarten. Danach steht in der Tabelle *Erkannte
   Fahrzeuge*, was das Konto führt.
3. Reiter *Test*: die Selbstprüfung ansehen. Die **erste** Zeile beantwortet, ob
   der eigene Endpunkt über HTTP antwortet.
4. Reiter *Einbindung in Loxone*: entweder das MQTT-Abo eintragen oder die
   Importdatei für Loxone Config erzeugen.
5. Erst danach die Zusatzfunktionen: Vorklimatisierung und Ladeempfehlung sind
   ab Werk **aus**, und sie brauchen ein laufendes MQTT-Gateway. Der Reiter
   Test vergleicht dann, welche fremden Themen abonniert **sein sollten** und
   welche der Dienst wirklich abonniert hat.

## Der Takt

Jeder Abruf **weckt das Fahrzeug**: die Schnittstelle fordert das Auto zur
Meldung auf (`vehicleRealTimeRequest`), statt in einen Zwischenspeicher der
Wolke zu sehen. Ein zu dichter Takt kostet Ruhestrom und kann in eine Sperre
laufen.

* Vorgabe **300 s** — dieselbe Vorgabe wie im ioBroker-Adapter derselben
  Schnittstelle.
* Untergrenze **120 s**. Diese Zahl ist eine **eigene Wahl** und keine Angabe
  von BYD; sie steht als solche im Quelltext und in der Oberfläche.
* Nach drei Fehlversuchen in Folge streckt der Dienst den Takt selbst, bis zu
  einer Stunde.

## Ausfallerkennung

**Virtuelle Eingänge behalten ihren letzten Wert.** Fällt der Dienst aus, sieht
in der App alles normal aus. Deshalb:

* Bei einem fehlgeschlagenen Abruf bleiben die Werte stehen und der Zeitstempel
  wird **nicht** aufgefrischt. Der Zeitstempel gehört zur Messung, nicht zum
  Schreibvorgang.
* Über MQTT gehen bei **jedem** Durchlauf `ok` und `ts` hinaus, auch bei einer
  Störung. Über MQTT wird der Zeitstempel gesendet und nicht das Alter: beim
  Senden ist das Alter immer null.
* In Loxone werden zwei Werte verdrahtet: `OK` und `ALTER`. Der Sonderfall
  `ALTER = -1` heißt „es hat noch nie einen erfolgreichen Abruf gegeben" und
  sieht frischer aus als jeder echte Wert — `OK` ist deshalb immer mit
  auszuwerten. Die Baustein-Liste im Reiter *Einbindung in Loxone* macht das so.

## Was zusätzlich mitgerechnet wird — und woran es hängt

Fünf Felder kommen **nicht** vom Fahrzeug. Sie stehen mit der Herkunft
`gerechnet` in der Feldtabelle, damit niemand sie für eine Messung hält:

| Feld | Woraus | Fehlt die Zutat |
|---|---|---|
| `FEHLFOLGE` | aufeinanderfolgende erfolglose Abrufe | entsteht immer |
| `ZUHAUSE` | Standort und eingetragene Heimatposition | bleibt leer |
| `VERBRAUCH` | abgeschlossene Fahrt ≥ 20 km und Kapazität | bleibt leer |
| `LADEEMPF` | fremdes MQTT-Thema mit Preis oder Überschuss | bleibt leer |
| `LADEKWH` | erkannter Ladevorgang und Kapazität | bleibt leer |

„Bleibt leer" heißt wörtlich leer und nicht 0: eine 0 sähe in Loxone aus wie
ein Messwert. **Eine Ausnahme, und sie ist bewusst:** ist die Ladeempfehlung
eingeschaltet und der Preiswert veraltet, wird `LADEEMPF = 0` gesendet und
nicht geschwiegen. Ein virtueller Eingang behält seinen letzten Wert — bliebe
das Feld leer, stünde in Loxone weiter die 1, und die Anlage lüde weiter, weil
niemand mehr widersprochen hat. Eine Empfehlung, die verstummt, muss 0 sagen.
Ist die Funktion ganz aus, ist das Feld leer: dann gibt es keine Aussage, und
das ist etwas anderes als „nein".

### Vorklimatisierung am Abfahrtsassistenten

Ist sie eingeschaltet, hört der Dienst zwei Themen des Abfahrtsassistenten im
Broker mit — die Minuten bis zur Abfahrt und einen Freigabewert — und setzt
zur eingestellten Vorlaufzeit den
Klimabefehl ab. Ein Zustandsautomat, keine Schwelle: der Assistent sendet im
Minutentakt, eine reine Schwelle setzte zwanzig Minuten lang zwanzig Befehle
ab. Ausgelöst wird **einmal je Abfahrt**.

Ab Werk **aus**, und sie ist die einzige Funktion, die von selbst schreibt.
Sind schreibende Befehle gesperrt, setzt sie nichts ab und **sagt das** im
Reiter Test — eine eingeschaltete Funktion, die nichts tut, fällt sonst
niemandem auf.

### Reiter *Ladevorgänge*

Ein Ladevorgang wird am Wechsel des Feldes `LAEDT` erkannt; BYD meldet ihn
nicht. Die Liste liegt in `verlauf/ladungen.csv` im Datenordner.

Bis 0.9.5 stand hier, sie überlebe eine Aktualisierung, „der Installateur
kopiert über `data/`, er räumt es nicht aus (nachgelesen in
`sbin/plugininstall.pl`)". Das war falsch, und der Beleg machte es schlimmer:
`plugininstall.pl` ruft `purge_installation` an **zwei** Stellen auf — beim
Deinstallieren (`:233`) und im Upgrade-Zweig (`:886`) —, und `:1626` löscht
`data/plugins/<ordner>/` ohne jede Bedingung. Der Datenordner wird also bei
**jeder** Aktualisierung vollständig abgeräumt.

Seit 0.9.6 trägt das Plugin die Liste selbst hinüber: `preupgrade.sh` legt sie
als `config/plugins/<ordner>.backup.verlauf.tar` **neben** den
Konfigurationsordner, `postinstall.sh` holt sie zurück. Sie überlebt eine
Aktualisierung also, weil das Plugin sie trägt — nicht, weil der Ordner bliebe.
Eine **Deinstallation** überlebt sie nicht; `uninstall` entfernt auch diese
Zweitschrift.
Beginn und Ende sind die Zeitpunkte der *Abrufe*, an denen der Wechsel auffiel;
sie liegen bis zu einem Taktabstand neben der Wirklichkeit. Die kWh sind
gerechnet, nicht gemessen: Ladeverluste stecken nicht darin.

### Trockenlauf

Jeder schreibende Befehl im Reiter Test lässt sich mit dem Haken *Trockenlauf*
absetzen. Er geht denselben Weg bis unmittelbar vor das Senden — die Wachen
greifen, Fahrzeug und Bibliotheksmethode werden gesucht, die Parameter werden
geprüft —, nur abgesetzt wird nichts. Der Bericht übernimmt **nicht** den
Wortlaut des Ernstfalls, sondern beginnt mit `PROBE`; was im Ernstfall greifen
würde, steht darin. Es ist ein Parameter derselben Funktion und keine zweite,
die den Vorgang beschreibt: zwei Stellen, die dasselbe erzeugen, laufen
auseinander, und dann zeigt die Vorschau etwas anderes an, als der Ernstfall
tut.

Damit ist der Trockenlauf der Weg, die offenen Punkte 3 und 4 unten an einem
echten Fahrzeug zu klären, **ohne es zu bewegen**.

## Was das Plugin nicht anbietet, und warum

* **Laden starten und anhalten.** pybyd nennt dafür keine Methode. Ein
  Bedienelement ohne Wirkung ist schlimmer als keines, und ein Loxone-Ausgang,
  der nur Absagen erntet, ist schlimmer als keiner.
* **`chargingState` und `connectState`.** Sie liefern auf manchen Modellen
  dauerhaft `-1`. Ein Feld, das immer dasselbe sagt, sagt nichts.

## Datenschutz

* Benutzername, Passwort und Steuer-PIN liegen in einer **eigenen Datei mit den
  Rechten 0600**, nicht in der Konfiguration, die die Oberfläche anzeigt. Sie
  werden nie angezeigt — nur ihre Länge.
* Ein leer gelassenes Passwortfeld löscht nichts. Zum Löschen gibt es ein
  eigenes Häkchen, und es räumt **auch die Zweitschrift** neben dem
  Konfigurationsordner ab. Ein Löschen, das nicht löscht, ist schlimmer als
  keines.
* Die Zweitschrift liegt **neben** dem Konfigurationsordner
  (`config/plugins/<ordner>.backup.<datei>`), nicht darin: LoxBerry entfernt
  beim Upgrade und beim Deinstallieren das Verzeichnis. Das
  Deinstallationsskript räumt sie ausdrücklich ab und überschreibt sie vorher.
* Der **Standort** ist eine eigene Abfrage und lässt sich abschalten. Dann
  verlässt die Position des Fahrzeugs den Wagen nicht.
* Das Token des unangemeldeten Endpunkts steht in `byd.json`, und die Datei
  bekommt deshalb ebenfalls 0600: wer es lesen kann, kann über HTTP das
  Fahrzeug schalten.

## Was offen ist

Diese Punkte sind **nicht** geprüft und lassen sich ohne Konto und Fahrzeug
auch nicht prüfen. Sie stehen hier als Auftrag, nicht als Ergebnis:

1. Ob die **Anmeldung** an der BYD-Schnittstelle gelingt.
2. Ob die **Feldnamen** dieser Feldtabelle bei einem echten Fahrzeug zutreffen.
   Der Knopf *Feldzuordnung vorschlagen* beantwortet das in einem Aufruf; was
   dabei herauskommt, gehört in die Kandidatenlisten in `bin/byd.py` und die
   Herkunft des Feldes von `doku` auf `bestand` gesetzt.
3. Ob die **Befehlsmethoden** in der installierten pybyd-Fassung so heißen wie
   erwartet, und mit welchen Parametern. Der Selbsttest listet auf, was sie
   anbietet.
4. Welche **Stufen** Sitz- und Batterieheizung kennen. Nicht dokumentiert.
5. Ob `left_front_door_lock` wirklich nur die **Fahrertür** meint. Das Feld
   heißt so; ob die Schnittstelle darin den Zustand des ganzen Fahrzeugs führt,
   ist offen. Deshalb heißt das Loxone-Feld `SCHLOSSVL` und nicht `VERRIEGELT` —
   ein Name, der Fahrertür und Fahrzeug verwischt, wäre eine stille
   Falschaussage.
6. Was die Kennzahlen von `vehicle_state`, `online_state`, `engine_status`,
   `battery_heat_state` und `main_seat_heat_state` im Einzelnen bedeuten. Sie
   gehen als **Rohwert** nach Loxone; erfunden wird keine Umrechnung.
7. Ob die **Vorklimatisierung** am Fahrzeug ankommt. Gemessen ist nur, welche
   Themen der Abfahrtsassistent führt (`ABFAHRT_IN` und `OK` unter seinem
   Präfix, aus `abfahrt_lib.php`) — nicht, dass BYD den Klimabefehl annimmt.
   Der Trockenlauf beantwortet die zweite Hälfte, ohne das Auto zu bewegen.
8. Ob die **Erkennung der Ladevorgänge** am echten Fahrzeug trägt. Sie hängt
   ganz an `LAEDT`, und `LAEDT` ist selbst ein ungeprüftes Feld: liefert die
   Schnittstelle dort etwas anderes als erwartet, entsteht **keine** falsche
   Liste, sondern gar keine — das ist die gewollte Richtung des Fehlers.
9. Die **nutzbare Kapazität** kennt nur der Halter; BYD liefert sie nicht.
   Ohne sie bleiben `VERBRAUCH` und `LADEKWH` leer. Ein falsch eingetragener
   Wert verfälscht jede Zeile gleichmäßig — er wird nicht geraten.

## Selbstaktualisierung

`AUTOMATIC_UPDATES` steht auf **true**, und `release.cfg` wie `prerelease.cfg`
führen Fassung und Adressen des veröffentlichten Standes.

Dieser Abschnitt beschrieb bis 0.9.5 den Zustand vor der ersten
Veröffentlichung — „steht auf false", „die Adressen sind leer", „kein
Repository, kein Tag, kein Release". Zu dem Zeitpunkt war jedes der drei
bereits überholt: `plugin.cfg` trug `AUTOMATIC_UPDATES=true`, beide `.cfg`
trugen die Adressen, und Tag wie Release lagen vor. Ein Abschnitt, der den
eigenen Auslieferungszustand falsch beschreibt, ist schlimmer als keiner: er
wird geglaubt.

**Die Nummern in `release.cfg` und `prerelease.cfg` gehen der Fassung bewusst
hinterher.** Sie nennen, was auf GitHub als Tag wirklich liegt — nicht, was im
Arbeitsordner entsteht. Angehoben werden sie erst, wenn der neue Tag existiert
und sein Archiv gemessen ist (HTTP 200). Die Reihenfolge ist: Inhalt schieben,
taggen, Tag-Archiv messen, **danach** beide `.cfg` setzen — am besten mit
`Werkzeuge/fassung_setzen.py ORDNER <nummer> --auch-release`, das sich
weigert, solange der Tag fehlt.

## Werkzeuge im Arbeitsordner — nicht im Archiv

Der Abschnitt hieß bis 0.9.5 „Mitgelieferte Werkzeuge" und sagte im nächsten
Satz, dass sie nicht ins Archiv gehören. Beides zusammen ergibt keinen Sinn:
wer das Plugin installiert, bekommt sie nicht. Sie liegen im Arbeitsordner
unter `Werkzeuge/` und dienen dem Bauen, nicht dem Betrieb:

* `byd_sprache_erzeugen.py` — erzeugt beide Sprachdateien aus **einer** Quelle
  und weist ab, statt die Hälfte zu schreiben: fehlende Schlüssel, Auszeichnung
  in maskierten Werten, gerade Anführungszeichen, auseinanderlaufende
  Platzhalter. In vier Richtungen geeicht.
* `byd_symbol_erzeugen.py` — erzeugt `icon.svg` **und** die vier PNG aus
  derselben Geometrietabelle, damit Vektor und Bild nicht auseinanderlaufen.

## Fassung 0.9.6 — eine Durchsicht Zeile für Zeile

Diese Fassung ändert keine Funktion und keine Adresse. Sie behebt, was eine
Durchsicht des veröffentlichten Standes 0.9.5 gefunden hat. Jeder Punkt hat
einen Prüfstand in `Pruefung-BYD-Autos-0.9.6/`, und jeder ist **in beide
Richtungen** geeicht: gegen 0.9.6 grün, gegen 0.9.5 rot.

**Was falsch war und jetzt stimmt**

* **Der Horcher meldete „verbunden", wenn der Broker die Anmeldung ablehnte.**
  `on_connect` feuert auch bei einem abgelehnten CONNACK (Code 4, Code 5), und
  der Rückgabecode wurde nicht angesehen. Der Zustand meldete
  `horcher_verbunden=1`, der Reiter Test setzte einen grünen Haken, und es kam
  nie eine Nachricht an. Jetzt wird der Code ausgewertet und der Grund benannt.
  (`horcher_connack.py`)
* **Bei einer Störung gingen `FEHLFOLGE` und `LADEEMPF` nicht über MQTT
  hinaus.** Die Feldschleife hing hinter `if ok`. Loxone las bei einem Ausfall
  unverändert `FEHLFOLGE=0` — also genau dann nicht, wofür der Zähler da ist.
  (`abbild_mqtt.py`)
* **Ein Teilausfall ließ ein Fahrzeug frisch aussehen, das nichts geliefert
  hatte.** Antwortete ein Fahrzeug und ein zweites nicht, ersetzte der neue
  Stand den alten vollständig: die Messwerte des ausgefallenen waren fort, der
  Zeitstempel aber aufgefrischt. Jetzt wird zusammengeführt, und `fahrzeugN/OK`
  sagt je Fahrzeug, ob dieser Abruf etwas gebracht hat. (`teilausfall.py`)
* **Die Fahrzeugnummer rückte vor, wenn ein Auto aus dem Konto fiel.** Sie
  entstand aus einer Aufzählung; die Sortierung nach VIN machte die
  *Reihenfolge* stabil, nicht die *Nummern*. Der virtuelle Eingang in Loxone
  zeigte danach ohne Meldung auf ein anderes Auto. Die Zuordnung steht jetzt im
  `merker.json` und überlebt jeden Neustart; beim ersten Lauf vergibt sie
  genau die Nummern, die die alte Fassung vergeben hätte. (`fahrzeugnummer.py`)
* **Nur der Abruf hing an einer Zeitgrenze.** Anmeldung, Schreibbefehle und die
  Freischaltung der Steuer-PIN hatten keine — schwieg die Gegenstelle bei der
  Anmeldung, stand der Dienst schon vor dem ersten Abruf. Dazu war die Grenze
  ein Wecker (`signal.alarm`), der die Ausnahme irgendwo hineinwirft, statt die
  Aufgabe geordnet abzubrechen. Jetzt `asyncio.wait_for` und drei weitere
  Fristen. (`fristen.py`)
* **Die Befehlswarteschlange hatte weder Deckel noch Höchstalter.** Nach einem
  Stillstand ging jeder in dieser Zeit angeklickte Befehl nacheinander ans
  Fahrzeug — die Klimaanlage, die um sieben Uhr gewollt war, lief um acht. Und
  jeder Klick auf *Jetzt abrufen* umging den Mindesttakt, beliebig oft.
  (`warteschlange.py`)
* **Der Wächter im Cron fragte nur, ob der Prozess lebt.** Ein Dienst, der in
  einem Aufruf hängt, erfüllte das tadellos und lieferte nichts. Die
  Hauptschleife frischt jetzt alle 30 s ein Lebenszeichen auf, und der Wächter
  misst dessen Alter. (`waechter_herzschlag.py`)
* **Zwei Knöpfe im Reiter Einstellungen taten gar nichts.** *Sichern* und
  *Zurückspielen* trugen keine Formularmarke; der Wachposten wies den POST ab,
  und die Seite lud aus, als wäre nichts gewesen. Der Reiter Test prüft das
  jetzt (Prüfung 17) — gemessen: 0.9.5 führt 15 Formulare und 13 Marken.
  (`formularmarken.py`, `sicherung_knopf.py`)
* **Ein einziger Einwand verwarf das ganze Einstellungsformular.** Wer das
  Abrufintervall ändert und sich dabei in der Heimatposition vertippt, verlor
  auch das Intervall. Jetzt wird gespeichert, was gültig ist; die Beanstandung
  steht daneben, und die betroffenen Felder behalten ihren bisherigen Wert.
* **Der Reiter Test meldete ein Kreuz bei den Auswahlfeldern**, weil er den
  eigenen CSS-Kommentar mitzählte, der ein `<select>` als Beispiel enthält.
  Gemessen: 3 gezählte Felder, 2 Merkmale — bei zwei einwandfreien Feldern.
  (`auswahlfeld_zaehlung.py`)
* **Der Knopf *Felder zeigen* meldete immer Erfolg**, auch wenn die virtuelle
  Python-Umgebung ganz fehlte. Jetzt entscheidet der Rückgabecode.
* **Fünf Stellen versprachen, die Liste der Ladevorgänge überlebe eine
  Aktualisierung** — mit einem Beleg, der nicht trug. Sie überlebt jetzt
  wirklich, weil `preupgrade.sh` sie neben den Konfigurationsordner legt und
  `postinstall.sh` sie zurückholt. Siehe den Abschnitt *Reiter Ladevorgänge*.
* **Die Hilfe widersprach sich selbst:** K24 bestritt den Trockenlauf, den
  K31 und K32 zwei Zeilen darunter beschreiben.
* Kleineres: die Protokollbremse wirkte nie, wenn die Protokolldatei nie
  entstand; das Kappen der Ladeliste schrieb nicht über eine Nebendatei; der
  MQTT-Präfix wurde nicht gesäubert (ein Leerzeichen darin zerlegt die Zeile
  am UDP-Eingang); `flach()` hatte weder Tiefen- noch Größengrenze; der Knopf
  *Dienst starten* war grün, obwohl grün laut Legende „verändert nichts" heißt;
  ein Schreibfehler beim Speichern der MQTT-Einstellungen blieb unerwähnt.

**Was weiterhin nicht gemessen ist**

Unverändert alles, wofür ein BYD-Konto, ein Fahrzeug oder echte Hardware nötig
wäre: die Anmeldung bei BYD, die Feldnamen an einem echten Fahrzeug, die
Wirkung der Schaltbefehle, die Installation auf einem LoxBerry. Dazu am
laufenden MQTT-Gateway: das Mithören fremder Themen und das Verhalten von
`retain`. Der V2-Zweig in `by_abo_text()` ist ungemessen — hier läuft
Gateway **Version 1**. Die Zeitgrenzen (120/90/60 s) und die Grenzen der
Warteschlange (20 Befehle, 300 s) sind **gesetzt, nicht gemessen**; wie lange
die BYD-Gegenstelle wirklich braucht, weiß hier niemand.

Ein Punkt ist ausdrücklich **keine** Behebung: die Umwandlung von
Wahrheitswerten in 1/0 vor dem Senden ist eine Absicherung. Nachgemessen wurde,
dass heute kein einziges Feld einen Wahrheitswert führt.

## Fassung 0.9.4 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/by_lib.php:335`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT, siehe `LICENSE`. Die beiden genannten Vorarbeiten stehen ebenfalls unter
MIT; dieses Plugin enthält keinen ihrer Quelltexte, sondern benutzt pybyd als
Bibliothek.
