# LoxBerry-Plugin: BYD Autos

Version 0.9.16

Bindet **Fahrzeuge von BYD** über das BYD-Konto an Loxone an: Ladezustand,
Kilometerstand, Reichweite, Ladezustand des Steckers, Restladezeit,
Geschwindigkeit, Fahrzeugzustand, Erreichbarkeit, Zündung, die vier
Türschlösser, die vier Türen, Heckklappe, die vier Fenster und das
Schiebedach, Sitz- und Batterieheizung, Fahrstufe, Innenraumtemperatur, die
vier Reifendrücke, zwei Durchschnittsverbräuche des Fahrzeugs sowie den
Standort. Auf Wunsch lassen sich
Verriegelung, Klimatisierung, Sitz- und Batterieheizung schalten, das Fahrzeug
suchen lassen, blinken und die Fenster schließen. Dazu kommen fünf
**gerechnete** Felder, eine Liste der erkannten Ladevorgänge, eine
Vorklimatisierung am Abfahrtsassistenten und ein **Trockenlauf** für jeden
schreibenden Befehl.

> ## Fassung 0.9.x — ungeprüft, und das ist wörtlich gemeint
>
> **BYD veröffentlicht keine Beschreibung seiner Schnittstelle.** Das Plugin
> wurde ohne BYD-Konto und ohne Fahrzeug gebaut. Was es über Feldnamen und
> Befehle weiß, stammt aus zwei offenen Quellen (unten benannt).
>
> Seit 0.9.11 gilt das **für die Befehle allein**. Die **Feldnamen** sind
> gegen die vollständige Rohausgabe eines BYD Seal U Design gehalten, die ein
> Anwender beigesteuert hat (Issue #1, 14.09.2026): 33 von 33 Feldern der
> Tabelle haben dort einen Schlüssel getroffen. Das belegt die **Namen** — was
> die Kennzahlen dahinter *bedeuten*, belegt es nicht.
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

## Neu in 0.9.16

Alle Änderungen dieser Fassung betreffen das Sichern und Zurückspielen und die
Frage, wo die LoxBerry-Wurzel liegt. Sie sind in WSL nachgestellt und
gemessen (18.09.2026), nicht am Gerät.

### Eine abgeschnittene Datei ist nicht leer

Ob eine Zweitschrift überschrieben oder zurückgespielt wird, entschied bis
0.9.15 die **Größe**: `[ -s ]` und der Vergleich mit `{}`. Eine
abgeschnittene `zugang.json` (Stromausfall, volle Karte) besteht beides.
Gemessen:

* `preupgrade.sh` kopierte eine abgeschnittene `zugang.json` oder `byd.json`
  über die heile Zweitschrift, ebenso eine `zugang.json` mit leerem Passwort.
  Passwort, Steuer-PIN bzw. Aktionstoken standen danach nirgends mehr.
* `postinstall.sh` hielt eine abgeschnittene `zugang.json` und eine mit
  leerem Passwort für brauchbar und spielte die heile Zweitschrift **nicht**
  zurück. Eine abgeschnittene Zweitschrift spielte es dagegen zurück und
  meldete `<OK> zugang.json aus der Sicherung wiederhergestellt`.
* `postupgrade.sh` meldete bei abgeschnittener `byd.json`
  `<OK> Die Konfiguration ist vorhanden.`
* Die Oberfläche zog beim Speichern jeden Stand in die Zweitschrift nach,
  auch einen ohne Passwort — etwa wenn `zugang.json` unlesbar war und das
  Passwortfeld leer blieb.

Ab 0.9.16 entscheidet der **Inhalt**: ein lesbares JSON-Objekt, und darin bei
`zugang.json` Benutzername **und** Passwort, bei `byd.json` das
Aktionstoken. Eine Zweitschrift mit Inhalt wird nie durch einen Stand ohne
Inhalt ersetzt. Wird zurückgespielt, bleibt der verdrängte Stand als
`<datei>.kaputt` (Rechte 0600) liegen. `postupgrade.sh` nennt jetzt auch, ob
Benutzername und Passwort hinterlegt sind.

### Die alte Sicherung fällt erst, wenn die neue steht

Bricht ein Update ab, nachdem der Installer die Plugin-Ordner geräumt hat,
und wird es erneut angestoßen, sind die Sicherungen neben dem Konfigordner
die einzige Abschrift. Bis 0.9.15 löschte `preupgrade.sh` beim zweiten
Anlauf

* die gesicherte **Ladehistorie** (`bydautos.backup.verlauf.tar`) — sie
  wurde vor allem anderen gelöscht, auch wenn gar keine neue entstehen
  konnte;
* den **Merker**, dass der Dienst vorher lief — danach startete ihn niemand
  wieder (gemessen: 0 statt 1 Dienst nach dem Update).

Und die beiden Zweitschriften wurden mit `cp -p` direkt überschrieben;
scheiterte das Schreiben (volle Karte), blieben sie mit **0 Byte** zurück.

Ab 0.9.16 entsteht jede neue Sicherung zuerst in einer Nebendatei, wird
geprüft (Zweitschriften byteweise, die Ladehistorie an der Zahl der Dateien)
und erst dann umbenannt. Der Merker wird in `preupgrade.sh` nur noch gesetzt,
nie gelöscht; eingelöst wird er wie bisher von `postinstall.sh`.

### Die LoxBerry-Wurzel wird gelesen, nicht geraten

* `bin/dienst.sh` rechnete die Wurzel bis 0.9.15 **immer** drei Ebenen über
  dem eigenen Ablageort und überschrieb dabei ein gesetztes `LBHOMEDIR`; den
  Ordnernamen nahm es aus dem Verzeichnisnamen, und bei **jedem** Aufruf —
  auch bei `status` — legte es die errechneten Ordner an. Aus einem
  Prüfarchiv unter `<LoxBerry-Wurzel>/pruefung/bydautos/bin` entstanden so
  `data/plugins/bin` und `log/plugins/bin` in der laufenden Installation.
  Ab 0.9.16 gilt zuerst `LBHOMEDIR` (und `LBPPLUGINDIR`), dann eine geprüfte
  Suche aufwärts; angelegt wird nur beim Start und vom Wächter, wenn der
  Dienst laufen soll. Ohne Wurzel bricht das Skript mit einer Meldung ab.
* Die Suche aufwärts in allen vier Hakenskripten, in der Oberfläche und in
  `bin/byd.py` verlangt jetzt zusätzlich `config/system/general.json`, wie
  jede LoxBerry-Wurzel sie trägt. Vorher genügte ein Verzeichnis mit
  `config/plugins` und `data/plugins` (bzw. `webfrontend`). Gemessen an einem
  solchen fremden Baum: `uninstall` löschte dort eine Zweitschrift,
  `preupgrade.sh` legte seine Marke an, `postinstall.sh` legte die
  Plugin-Ordner an, `postupgrade.sh` löschte eine fremde Marke, die
  Oberfläche legte `byd.json` samt Zweitschrift an und `byd.py --selbsttest`
  einen Protokollordner. Die installierte Lage
  `<Wurzel>/bin/plugins/<ordner>` bzw. `webfrontend/html/plugins/<ordner>`
  gilt weiter auch ohne `general.json`.

Was nach dem Update zu tun ist: nichts.

## Neu in 0.9.15

### Während einer Aktualisierung speichert die Oberfläche nichts mehr

Beim Aktualisieren räumt der LoxBerry-Installer `config/plugins/bydautos/` und
`data/plugins/bydautos/` ab, **bevor** `postinstall.sh` die Sicherungen
zurückholt. Zwischen der neu angelegten Cron-Datei und `postinstall.sh` liegt
am Gerät fast eine Minute. Wer die Plugin-Seite ausgerechnet in dieser Zeit
öffnet, sieht eine leere Einrichtung — und ein Druck auf **Speichern** im
Reiter Einstellungen hatte bis 0.9.14 diese Wirkung:

* `zugang.json` wurde mit **leerem Passwort und leerer Steuer-PIN** neu
  geschrieben. Das ist kein Fehler der Speicherfunktion: sie behält ein leer
  abgesendetes Passwortfeld absichtlich bei — nur gab es in diesem Augenblick
  nichts zu behalten.
* Derselbe leere Stand wurde in die Zweitschrift
  `config/plugins/bydautos.backup.zugang.json` nachgezogen.
* `postinstall.sh` holte Sekunden später genau diesen leeren Stand zurück und
  meldete `<OK> zugang.json aus der Sicherung wiederhergestellt`.

Benutzername, Passwort und Steuer-PIN des BYD-Kontos waren damit endgültig
fort, ohne dass irgendwo etwas davon gestanden hätte. In WSL nachgestellt und
gemessen am 18.09.2026.

Ab 0.9.15 legt `preupgrade.sh` als Erstes die Marke
`data/plugins/bydautos.upgrade_laeuft` mit der Unixzeit an — **neben** den
Datenordner, weil der Installer den Ordner selbst löscht. Solange sie gilt:

* zeigt die Plugin-Seite nur einen Hinweis und nimmt kein Formular an,
* startet `bin/dienst.sh` den Abrufdienst nicht.

Das letzte Hakenskript dieser Linie, `postupgrade.sh`, entfernt die Marke —
und zwar **nach** dem Wiederanlauf, den `postinstall.sh` erledigt. Andersherum
sähe ein Wächterlauf zwischen dem Entfernen und dem dastehenden Dienst weder
das eine noch das andere und startete einen zweiten. Der Wiederanlauf selbst
kommt mit `BY_START_TROTZ_MARKE=1` an der Marke vorbei.

Eine Marke, die älter als eine Stunde ist, aus der Zukunft stammt oder keine
Unixzeit enthält, **gilt nicht** — eine abgebrochene Installation darf das
Plugin nicht für immer stilllegen. Bricht `postinstall.sh` ab, räumt es sie
über seinen `EXIT`-Trap gleich selbst weg; `uninstall` räumt sie ebenfalls ab.
Lässt sich die Uhr nicht lesen, gilt die Marke — ein Schutz fällt geschlossen
aus.

Im Reiter **Test** steht dazu eine neue Zeile: *Liegt eine Marke
„Aktualisierung läuft“?* Sie meldet vor allem den Fall, dass eine solche Marke
nach einer abgebrochenen Installation liegengeblieben ist.

### Ein Selbsttest ist kein Dienst — `uninstall` beendete ihn mit

Beim Messen der Marke fiel eine zweite Stelle auf. Drei Stellen erkennen den
eigenen Dienst argumentweise über `/proc/<pid>/cmdline`: `bin/dienst.sh`,
`uninstall/uninstall` und die Oberfläche. Alle drei prüften, dass `argv[0]`
ein Python ist und `argv[1]` genau `bin/byd.py` — aber keine prüfte, dass es
**kein drittes Argument** gibt.

Gemessen am 18.09.2026 in WSL (Fall D1): neben dem laufenden Dienst standen
zwei Köder, die den Dienstpfad in ihrer Befehlszeile führen —
`tail -f <pfad>/byd.py` und der Selbsttest
`<venv>/bin/python3 <pfad>/byd.py --selbsttest`. `dienst.sh stop` ließ beide
stehen und beendete nur den Dienst; `uninstall` ließ `tail` stehen und
**beendete den Selbsttest mit**. Ein Einmallauf mit zusätzlichen Argumenten
ist kein Dienst.

Ab 0.9.15 verlangen alle drei Stellen genau zwei Argumente. Der Dienst selbst
startet immer mit genau zweien.

### Was die Marke hier NICHT abwendet

Die Startwege dieses Plugins laufen in der Lücke ohnehin nicht an, und das ist
gemessen, nicht angenommen: der minütliche Wächter steigt ohne seinen Merker
`data/plugins/bydautos/soll_laufen` aus (sechs Läufe, null Prozesse), und
`dienst.sh start` scheitert an der fehlenden `zugang.json`. Für den Dienststart
ist die Marke **Vorsorge** — sie deckt den Systemstart mitten im Update und
jeden künftigen Startweg ab. Der gemessene Schaden lag allein an der
Oberfläche.

## Neu in 0.9.12

### Der Melder wird nicht mehr namentlich genannt

Die Kommentare in `bin/byd.py` und `webfrontend/html/by_lib.php` nannten an
zehn Stellen den GitHub-Namen des Anwenders, der die Rohausgaben zu 0.9.10 und
0.9.11 beigesteuert hat. Sie verweisen jetzt nur noch auf **Issue #1**.

Das ist keine Änderung am Verhalten: betroffen sind ausschließlich
Kommentarzeilen, keine Zeile Code, keine Feldtabelle, keine Sprachdatei. Die
Herkunftsangabe bleibt nachvollziehbar, das Issue steht öffentlich im selben
Repositorium.

~~Die veröffentlichten Fassungen 0.9.10 und 0.9.11 bleiben unverändert.~~
**Berichtigt am 16.09.2026:** Auf Wunsch des Hausherrn ist der Name auch aus
der Geschichte des Repositoriums entfernt. Die Tags `v0.9.10` und `v0.9.11`
tragen ihn nicht mehr; geändert wurden nur die betroffenen Kommentarzeilen,
jeder andere Inhalt ist gleich. Weil der erste Commit eine Signatur trug, die
beim Umschreiben nicht erhalten bleibt, haben **alle** Commits und Tags neue
Prüfsummen. Tag-Namen und Download-Adressen bleiben gleich; eine Anlage, die
schon installiert hat, ist nicht betroffen.

## Neu in 0.9.11

Wieder **Issue #1**, und wieder derselbe Melder: diesmal hat er die
**vollständige** Rohausgabe seines BYD Seal U Design beigesteuert, alle drei
Abschnitte. Damit ließ sich das nachrechnen, was 0.9.10 noch offenlassen
musste — und dabei kam ein Fehler heraus, den man einem Auszug nicht ansieht.

### `TEMPO` war kein fehlendes Feld, sondern ein zerstörter Wert

Die Rohausgabe führt die Geschwindigkeit an zwei Stellen:

```
Abschnitt echtzeit:   "speed": 84
Abschnitt gps:        "speed": null
```

Das Plugin legt die drei Abschnitte übereinander, und zwar in dieser
Reihenfolge — Stammdaten, Echtzeit, GPS. Ein gewöhnliches `dict.update()`
setzt damit die **84 wieder auf `null`**. `TEMPO` konnte deshalb nie einen
Wert tragen, auch nicht während der Fahrt: nicht weil der Name fehlte,
sondern weil der gemessene Wert eine Zeile später gelöscht wurde.

Seit 0.9.11 löscht ein `null` keinen Wert mehr, der schon dasteht. Das kostet
nichts: die Feldsuche überspringt einen Kandidaten mit dem Wert `null`
ohnehin. Nachgezählt über alle drei Abschnitte war **genau ein** Schlüssel
betroffen — `speed`.

Gemessen mit `Pruefung-BYD-Autos-0.9.11/rohdaten_messen.py`, und zwar in
beide Richtungen: mit der alten Mischung *muss* `TEMPO` leer bleiben, mit der
neuen *muss* dort 84 stehen. Sonst misst das Werkzeug seinen eigenen Wunsch.

### Der Verbrauch der letzten 50 km — das Feld, das 0.9.10 draußen ließ

0.9.10 ließ `"energyConsumption": "33.3"` absichtlich weg: die Zahl war
lesbar, ihre Bedeutung nicht. Jetzt ist sie belegt, und gleich doppelt. Der
Melder hat sie in der BYD-App nachgesehen — *durchschnittlicher
Energieverbrauch der letzten 50 gefahrenen Kilometer* —, und die vollständige
Rohausgabe nennt denselben Zahlenwert beim Namen:

```
"energy_consumption":      "15.4"
"recent_50km_energy":      "15.4kW·h/100km"
"recent_50km_energy_ev":   15.4      "recent_50km_energy_ev_unit": "kWh/100km"
```

Das Feld heißt **`VERBR50`**, Einheit kWh/100 km. Damit stehen jetzt drei
Verbrauchszahlen nebeneinander, und das ist Absicht:

| Feld | Was es ist |
|---|---|
| `VERBR50` | Durchschnitt der **letzten 50 km**, vom Fahrzeug gerechnet |
| `VERBRBYD` | Durchschnitt über die **ganze Laufleistung**, vom Fahrzeug gerechnet |
| `VERBRAUCH` | Verbrauch der **letzten Fahrt**, vom **Plugin** aus Ladezustand und Kilometerstand gerechnet |

### Die Reifendrücke stehen in bar — bestätigt

Der Melder hat die Werte gegen die BYD-App gehalten: dort stehen sie in
**bar**. Die vier Felder tragen deshalb nicht mehr `doku`, sondern `bestand`.

Dazu kommt ein Fund aus der Rohausgabe: die Schnittstelle führt die Einheit
selbst, als `"tire_press_unit": 1`. Bei diesem Fahrzeug — dessen App bar
anzeigt — steht dort **1**. Das ist **ein** Fahrzeug und noch keine Tabelle,
deshalb wird nichts umgerechnet; die Kennung geht als `REIFENEH` über MQTT
hinaus, damit der nächste Melder sie mitschicken kann. In der Statuszeile
steht sie nicht.

### Zwölf weitere Werte, die in der Antwort standen und niemand las

Alle aus derselben Rohausgabe, alle mit einem Schlüssel getroffen:

| Feld | Quelle | Bei diesem Fahrzeug |
|---|---|---|
| `INNENTEMP` | `temp_in_car` | 23 °C |
| `FAHRSTUFE` | `power_gear` | 3 |
| `SCHLOSSVR`, `SCHLOSSHL`, `SCHLOSSHR` | `right_front_door_lock` usw. | je 2 |
| `TUERVL`, `TUERVR`, `TUERHL`, `TUERHR` | `left_front_door` usw. | je 0 |
| `KOFFER` | `trunk_lid` | 0 |
| `FENSTERVL`, `FENSTERVR`, `FENSTERHL`, `FENSTERHR` | `left_front_window` usw. | je 1 |
| `DACHFENSTER` | `skylight` | 1 |

Bis 0.9.10 kannte das Plugin **ein** Türschloss, das der Fahrertür. Jetzt
kennt es alle vier.

Was diese Zahlen **bedeuten**, ist damit nicht gesagt: ein Türschloss meldete
2, ein Fenster 1, die Fahrstufe 3 — eine Tabelle dazu nennt die Gegenstelle
nicht. Die Felder heißen in der Oberfläche deshalb ausdrücklich „als Kennzahl
der Schnittstelle", genau wie das bestehende `SCHLOSSVL`. Wer sie in Loxone
legt, hält sie einmal gegen sein Fahrzeug.

**Zurückbehalten** gehen die neuen Zustände (Türen, Schlösser, Fenster,
Heckklappe, Schiebedach, Fahrstufe, Einheitenkennung); `INNENTEMP` und
`VERBR50` nicht — Messwerte mit Zeitbezug.

### Was die Spalte *Herkunft* jetzt zeigt

Vor 0.9.10 trug jedes Feld vom Fahrzeug `doku`. Jetzt sind es von den 45
Feldern der Tabelle **37 mit `bestand`**, weil ihr Schlüsselname in einer
echten Antwort vorkam und einen Wert trug — gezählt, nicht geschätzt.

`doku` bleiben genau drei, und mit Grund: `LAEDT`, `KABEL` und `RESTMIN`
entstehen nicht aus einem Schlüssel, sondern aus der **Deutung** von
Kennzahlen (welcher Ladezustand heißt „lädt"?) beziehungsweise aus Feldern,
die bei diesem Fahrzeug leer standen, weil es nicht lud. Die übrigen fünf
rechnet das Plugin selbst und heißen deshalb `gerechnet`.

### Was Sie nach dem Update tun sollten

Die neuen Felder hängen **am Ende** der Statuszeile — keine vorhandene
Befehlserkennung verschiebt sich. Wer die neuen Werte im Miniserver haben
will, erzeugt die Loxone-Vorlage im Reiter *Einbindung in Loxone* **neu**.

## Neu in 0.9.10

Alles in dieser Fassung geht auf **Issue #1** zurück — die erste Rückmeldung
von einem fremden Fahrzeug, einem **BYD Seal U Design**. Der Melder hat die
Rohausgabe aus *Test → Rohdaten der Gegenstelle anzeigen* mitgeschickt, und
damit ließ sich etwas messen statt raten.

### Die Reichweite blieb leer — und das war ein Fehler, keine Lücke

Der Reiter *Test* meldete dort „21 von 23 Feldern aufgelöst — REICHW, TEMPO".
In denselben Rohdaten stand aber `"enduranceMileage": 232`. Die
Kandidatenliste für `REICHW` kannte fünf Schreibweisen — **diese nicht**.

`endurance_mileage` und `enduranceMileage` stehen jetzt **vorn** in der Liste,
`ev_endurance` dahinter. Die bisherigen fünf Namen bleiben stehen: sie sind für
andere Modelle belegt, und eine Kandidatenliste ist kein Entweder-oder.

**`TEMPO` bleibt offen.** Die Rohausgabe im Issue ist ein Auszug; ein Feld für
die Geschwindigkeit ist darin nicht zu sehen. Geraten wird hier nichts — wer
den Namen in seinen Rohdaten findet, möge ihn melden.

> Nachtrag 0.9.11: der Name stimmte die ganze Zeit. `speed` stand in der
> vollständigen Rohausgabe — und wurde vom GPS-Abschnitt wieder gelöscht.
> Siehe oben, *Neu in 0.9.11*.

### Vier Reifendrücke

`REIFENVL`, `REIFENVR`, `REIFENHL`, `REIFENHR` — vorne links, vorne rechts,
hinten links, hinten rechts. Die Namen der Gegenstelle stammen aus derselben
Rohausgabe, `"leftFrontTirepressure": 2.7`.

Die **Einheit ist nicht belegt**. Die Schnittstelle nennt keine; 2,7 ist als
bar plausibel und als psi unmöglich, deshalb steht „bar" da. Das Feld trägt
`quelle = doku` — wer es am Bordcomputer gegenhält und bestätigt, darf es auf
`bestand` setzen, vorher niemand.

> Nachtrag 0.9.11: genau das ist geschehen. Die BYD-App zeigt bar; die vier
> Felder tragen jetzt `bestand`.

### Durchschnittsverbrauch laut Fahrzeug

`VERBRBYD`, in kWh/100 km. Der Rohwert ist **keine Zahl**, sondern eine
Zeichenkette mit eingebauter Einheit: `"totalEnergy": "17.6kW·h/100km"`. Die
übliche Umwandlung gibt dafür nichts zurück — `float()` scheitert an der
Einheit —, das Feld wäre als gewöhnlicher Eintrag dauerhaft leer geblieben.
Herausgelöst wird jetzt die führende Zahl; steht dort keine, entsteht **kein**
Wert und keine 0.

`VERBRAUCH` daneben bleibt, was es war: der vom Plugin aus Ladezustand und
Kilometerstand **gerechnete** Wert der letzten Fahrt. Zwei Zahlen, zwei
Herkünfte — deshalb zwei Felder und kein Überschreiben. Wer beide in Loxone
legt, sieht, wie weit die Bordrechnung und die Rechnung aus dem Ladezustand
auseinanderliegen.

### Ein Feld, das absichtlich fehlt

In derselben Rohausgabe steht `"energyConsumption": "33.3"`. Die Zahl ist
lesbar, ihre **Bedeutung nicht**: 33,3 könnte eine geladene Energiemenge in
kWh sein, ein Verbrauch einer einzelnen Fahrt oder etwas Drittes. Ein Feld mit
geratener Bedeutung ist schlimmer als keins — es sieht in Loxone genauso
richtig aus wie ein belegtes. Es bleibt draußen, bis jemand sagt, was sein
Bordcomputer an dieser Stelle anzeigt.

> Nachtrag 0.9.11: jemand hat es gesagt. Es ist der Durchschnitt der letzten
> 50 km und heißt jetzt `VERBR50`.

### Was Sie nach dem Update tun sollten

Die fünf neuen Felder hängen **am Ende** der Statuszeile. Das verschiebt keine
vorhandene Befehlserkennung — aber die Loxone-Vorlage im Reiter *Einbindung in
Loxone* ist neu zu erzeugen, wenn Sie die neuen Werte im Miniserver haben
wollen.

## Neu in 0.9.9

Eine Messrunde am LoxBerry — ohne BYD-Konto, das es hier nicht gibt — und
drei Befunde daraus.

### Zustände gehen zurückbehalten hinaus

Bis 0.9.8 ging jedes Thema mit `publish` über den UDP-Eingang des
MQTT-Gateways. Gateway V1 legt so ein Thema **nicht** retained im Broker ab (am
Gerät belegt, 06.09.2026). Nach einem Neustart des Miniservers oder des
Gateways standen damit auch Schloss und Zündung leer, bis der nächste Abruf
kam.

Jetzt gilt der Hausstandard vom 03.09.2026. **Zustände** gehen mit `retain`
hinaus: Lade-, Fahr- und Onlinezustand, Zündung, Schloss, beide Heizungen,
`LAEDT`, `KABEL`, `ZUHAUSE`, `FEHLFOLGE` und die Zahl der Fahrzeuge.
**Messwerte mit Zeitbezug** gehen ohne: Ladezustand, Kilometerstand,
Reichweite, Tempo, Restzeit, Verbrauch, geladene Menge, Standort — und die
Ladeempfehlung, die an einem Preis der Stunde hängt. Das **Lebenszeichen**
(`ts`, `ok` und `OK` je Fahrzeug) geht nie zurückbehalten: retained zeigte es
nach dem Tod des Dienstes für immer „lebt". Die Themen-Tabelle im Reiter MQTT
hat dafür eine eigene Spalte.

Eine Folge, die man kennen muss: ein zurückbehaltener Zustand bleibt im Broker
stehen, auch wenn der Dienst ausfällt. Ob er frisch ist, sagen `ALTER` und
`OK` — so steht es im Abschnitt *Ausfallerkennung*, und daran ändert sich
nichts.

### Der Grund einer abgelehnten Broker-Anmeldung steht wieder im Klartext

paho 2.x — auf dem LoxBerry steckt 2.1.0 — liefert für eine abgelehnte
Anmeldung die Ursachencodes von MQTT 5 (134, 135) statt der von MQTT 3.1.1
(4, 5). 0.9.8 kannte nur die alten Nummern; am Gerät stand deshalb „Code 135"
ohne Bedeutung. Das Verhalten war richtig — der Horcher meldete nie
„verbunden" —, nur die Erklärung fehlte. Am echten Mosquitto gemessen:
falsches Kennwort **und** anonyme Anmeldung ergeben beide 135, „nicht
berechtigt".

Der Prüfstand, der das hätte finden sollen, verlangte nur *irgendeinen*
Fehlertext. Er verlangt jetzt die Bedeutung — und fällt gegen 0.9.8 nur unter
paho 2.1.0 durch, nicht unter paho 1.6.1 auf einem Arbeitsplatz. Ohne die
Messung am Gerät wäre der Befund unsichtbar geblieben.

### Kleinigkeit im Cron-Wächter

Der Kommentar in `cron/cron.01min` nannte den Platzhalter des Installers beim
Namen. Der Installer ersetzt ihn in der ganzen Datei — auf dem Gerät erklärte
der Kommentar danach den fertigen Pfad als „Platzhalter".

### Eine Meldung im Installationsprotokoll stimmte nicht

`preupgrade.sh` schrieb bei jedem Update „Laufender Dienst angehalten." ins
Protokoll — auch wenn gar kein Dienst lief. Die Antwort von `dienst.sh stop`
(„laeuft nicht") ging nach `/dev/null`, und die Meldung hing an nichts. Der
Merker, der weiß, ob der Dienst lief, steht zwei Zeilen darüber; an ihm hängt
die Meldung jetzt. Aufgefallen am Protokoll des ersten echten Upgrades am
Gerät.

### Am LoxBerry gemessen (11.09.2026)

LoxBerry 4.0.0.15, Python 3.13.5, pybyd 0.0.73, paho 2.1.0. Installiert war
0.9.8, byteweise gleich mit dem Ordner bis auf die Platzhalter, die der
Installer ersetzt.

* Der Horcher am **echten** Mosquitto: mit den Zugangsdaten aus der
  `general.json` verbindet er und hört fremde Themen mit — vier
  zurückbehaltene Werte des Abfahrtsassistenten kamen sofort an. Falsches
  Kennwort und anonym: abgewiesen, kein einziges Mal „verbunden".
* `abfahrt/ABFAHRT_IN` geht beim Abfahrtsassistenten **nicht** retained. Nach
  einem Neustart des BYD-Dienstes fehlt der Wert bis zum nächsten Vollversand
  des Assistenten, und die Vorklimatisierung löst bis dahin nicht aus — die
  gewollte Richtung des Fehlers.
* `byd.py --selbsttest`: alle zehn Befehlsmethoden sind in pybyd 0.0.73
  vorhanden. Ob sie am Fahrzeug wirken, ist damit nicht gesagt.
* Der Wächter entscheidet unter der Shell des Geräts (dash) richtig zwischen
  „arbeitet", „hängt" und „kein Urteil".
* Der Reiter Test, erstmals auf dem Gerät gerendert: 17 von 22 bestanden; alle
  fünf Kreuze folgen daraus, dass kein BYD-Konto hinterlegt ist.
* Der Endpunkt antwortet mit Token `SELFTEST;OK=1`, ohne und mit falschem
  Token `403`.
* Ohne Zugangsdaten verweigert `dienst.sh start` den Start und hinterlässt
  keine Spur.
* **Das erste echte Upgrade am Gerät, 0.9.8 → 0.9.9:** der Installer hat
  `verlauf/ladungen.csv` gelöscht (Protokoll: `removed …/verlauf/ladungen.csv`)
  und ebenso `byd.json` und `zugang.json`. `preupgrade.sh` hatte die
  Ladehistorie vorher gesichert, `postinstall.sh` hat sie zurückgespielt —
  byteweise gleich, mit derselben Zeitangabe —, und beide Konfigurationsdateien
  aus ihrer Zweitschrift. Das Token ist unverändert, die Sicherung neben dem
  Konfigordner danach wieder fort. Damit ist am Gerät belegt, was 0.9.5 falsch
  versprochen und 0.9.6 gebaut hat.

## Neu in 0.9.7

### Der Dienst konnte sein Protokoll verlieren, ohne dass es auffiel

`log/plugins` liegt auf einer Ramdisk (`/dev/zram0`). Wird sie geleert — beim
Neustart, durch LoxBerrys `log_maint`, oder von Hand —, ist die Datei fort. Ein
`RotatingFileHandler`, der sie beim Start **einmal** geöffnet hat, schreibt
danach bis zum nächsten Neustart in einen gelöschten Inode: keine
Fehlermeldung, keine Datei, kein Hinweis. Auch die Rotation greift dann nicht
mehr.

Diese Fassung benutzt deshalb `WachsameRotation` in `bin/byd.py` — einen
umlaufenden Handler, der vor jeder Zeile Gerätenummer und Inode vergleicht und
nötigenfalls neu öffnet. Die Standardbibliothek hat für den einen Fall den
`WatchedFileHandler` und für den anderen den `RotatingFileHandler`, aber
nichts, was beides kann; deshalb die eigene Klasse.

Auf dem LoxBerry geeicht, vier Prüfungen und in beide Richtungen: schreiben,
nach dem Löschen weiterschreiben, Umlauf bei Überlänge, nach dem Umlauf erneut
löschen. Mit dem alten Handler ist die Zeile nach dem Löschen verloren und
bleibt es, mit dem neuen steht sie in der wieder angelegten Datei. Auf einem
Windows-Arbeitsplatz lässt sich das nicht messen — dort kann eine offene Datei
gar nicht gelöscht werden.

Aufgefallen ist die Bauart am Heimkino-Plugin, dessen Dienst sieben Stunden
ohne Protokolldatei lief, und am laufenden Gerät belegt: der
Midea2Lox-Dienst hielt `midea2lox.log (deleted)` offen, während unter
demselben Namen längst eine neue Datei fortgeschrieben wurde — von außen sah
das Plugin gesund aus. Elf Linien tragen dieselbe Bauart; alle elf sind am
06.09.2026 nachgezogen worden.

**Die zweite Hälfte gehört dem Startskript.** `bin/dienst.sh` hängte die
Ausgabe des Dienstes mit `nohup … >> "$LOGDATEI"` an **dieselbe** Datei, die
der Handler führt. Damit hält die Shell einen zweiten, anhängenden Deskriptor
darauf — und der bleibt auf der gelöschten Datei stehen, gleich wie gut das
Programm nachfasst. Am Gerät gemessen (06.09.2026): sieben laufende Dienste
hielten so eine gelöschte Protokolldatei offen. Die Ausgabe geht jetzt in
`byd_start.log`, das bei jedem Start geleert wird; das Protokoll gehört
allein dem Handler. Übernommen von AnkerSolix, das es seit 0.9.6 so macht.

Im Sandkasten am Gerät geprüft, in beide Richtungen: mit dem alten Skript
steht die Dienstausgabe im Protokoll und es gibt keine Startdatei, mit dem
neuen ist es umgekehrt — Start, Startdatei, unberührtes Protokoll und Stopp
je sechs von sechs.


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
2. Ob die **Feldnamen** dieser Feldtabelle bei **weiteren Modellen**
   zutreffen. An einem BYD Seal U Design sind sie gemessen (Issue #1,
   14.09.2026, alle 33 getroffen) — an einem Atto 3, Dolphin, Han oder Tang
   nicht. Der Knopf *Feldzuordnung vorschlagen* beantwortet das in einem
   Aufruf; was dabei herauskommt, gehört in die Kandidatenlisten in
   `bin/byd.py`.
3. Mit welchen **Parametern** die Befehlsmethoden aufzurufen sind. Dass es
   sie in pybyd 0.0.73 gibt, ist am Gerät gemessen (11.09.2026: alle zehn
   vorhanden) — ob BYD sie so annimmt, nicht.
4. Welche **Stufen** Sitz- und Batterieheizung kennen. Nicht dokumentiert.
5. Ob `left_front_door_lock` wirklich nur die **Fahrertür** meint. Das Feld
   heißt so; ob die Schnittstelle darin den Zustand des ganzen Fahrzeugs führt,
   ist offen. Deshalb heißt das Loxone-Feld `SCHLOSSVL` und nicht `VERRIEGELT` —
   ein Name, der Fahrertür und Fahrzeug verwischt, wäre eine stille
   Falschaussage.
6. Was die Kennzahlen von `vehicle_state`, `online_state`, `engine_status`,
   `battery_heat_state`, `main_seat_heat_state`, den Tür-, Schloss- und
   Fensterfeldern, `power_gear` und `tire_press_unit` im Einzelnen bedeuten.
   Sie gehen als **Rohwert** nach Loxone; erfunden wird keine Umrechnung.
   Gemessen ist nur, welche Zahl an einem Fahrzeug dastand — nicht, wofür sie
   steht.
7. Ob die **Vorklimatisierung** am Fahrzeug ankommt. Gemessen ist, welche
   Themen der Abfahrtsassistent führt (`ABFAHRT_IN` und `OK` unter seinem
   Präfix) und dass der Horcher sie am echten Broker empfängt (11.09.2026) —
   nicht, dass BYD den Klimabefehl annimmt. Der Trockenlauf beantwortet die
   zweite Hälfte, ohne das Auto zu bewegen.
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
