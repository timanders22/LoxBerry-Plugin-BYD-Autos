<?php
/**
 * Gemeinsames Sicherungsmodul fuer die LoxBerry-Plugins des Hauses:
 * "Einstellungen sichern" und "Einstellungen zurueckspielen"
 *
 * DIESE DATEI IST IN MEHREREN PLUGINS BYTEWEISE GLEICH.
 * Die Stammfassung liegt unter Werkzeuge/gemeinsam/sicherung.php im
 * Arbeitsordner. Ein LoxBerry-Plugin kann keine Datei eines anderen Plugins
 * laden; deshalb traegt jede Linie eine Abschrift neben ihrer Bibliothek.
 * Werkzeuge/sicherung_einbauen.py legt sie byte-gleich hinein und meldet mit
 * --pruefen jede Abschrift, die von der Stammfassung abweicht, und jeden
 * Abschnitt [SICHERUNG] der Sprachdateien, der nicht zu
 * Werkzeuge/gemeinsam/sicherung_texte.json passt. Wer hier etwas aendert,
 * aendert es in der Stammfassung, hebt SICHERUNG_FASSUNG an, laesst
 * sicherung_selbsttest() (php sicherung.php --selbsttest) und
 * Werkzeuge/sicherung_pruefstand.py laufen und verteilt danach.
 *
 * Auftrag des Hausherrn vom 12.11.2026: eine gemeinsame Vorlage, damit sich
 * alle Plugins beim Sichern und Zurueckspielen gleich verhalten. Die Regeln,
 * die dieses Modul festschreibt (Entscheidungen des Hausherrn 12.11.2026):
 *
 *   1. Kennzeichen. Jede Sicherung traegt einen lesbaren Kopf mit dem
 *      Kennzeichen des Plugins (_plugin) und der Fassung (_fassung). Eine
 *      Datei mit falschem oder fehlendem Kennzeichen wird als Ganzes
 *      abgewiesen. Ausnahme: eine alte Sicherung der eigenen Linie, die das
 *      Kennzeichen noch nicht kennt - die Linie erkennt sie ueber ihren
 *      Uebersetzer ('uebersetzer'), und die Meldung sagt es.
 *   2. Unbekannte Schluessel einer eigenen Sicherung, oben und in Bloecken,
 *      werden verworfen und mit Namen gemeldet, nie mit Wert. Die Datei wird
 *      trotzdem zurueckgespielt.
 *   3. Ein leerer Wert ('' - genau die leere Zeichenkette) ueberschreibt
 *      keinen bestehenden. Er bleibt stehen und wird mit Namen genannt.
 *      Beidseits leer: keine Meldung. Listen und Felder sind keine leeren
 *      Werte in diesem Sinn; ein leeres Feld [] wird wie bisher uebernommen.
 *   4. Halb gueltig: wird ein bekannter Wert beanstandet, aendert sich gar
 *      nichts. Alle Beanstandungen werden gesammelt, nicht nur die erste.
 *   5. Inhalt: Aktionstoken und Zugangsdaten gehoeren hinein; der
 *      Formulartoken und die Sprechtoken nicht (das bestimmt die Linie in
 *      'sichern' und 'nie_aus_datei'). Ein selbsterzeugtes Geheimnis wird hier
 *      nie erzeugt.
 *   6. Danach zieht die Zweitschrift mit (Speicherfunktion der Linie, das
 *      Modul prueft es, wenn 'zweitschrift' genannt ist), der Dienst wird
 *      nachgezogen ('nachziehen'), und die Meldung sagt, was mit ihm geschah.
 *   7. Alles uebrige verhaelt sich wie bisher in der Linie: welche Schluessel
 *      fehlen duerfen ('fehlt'), welche Werte gelten ('pruefen', 'quer'),
 *      was vor dem Speichern noch geschieht ('vorbereiten').
 *   8. Seit 1.1.0 (Entscheidung des Hausherrn 12.11.2026, Welle 1): der Knopf
 *      "Einstellungen zurueckspielen" wirkt nur mit gesetztem Haken
 *      (Feld SICHERUNG_HAKEN = zurueck_ok, Wert 1). Ohne Haken wird nichts
 *      gelesen und nichts geaendert, und die Meldung sagt es. Zeile, Feldname,
 *      Text und Pruefung kommen aus diesem Modul (sicherung_haken_zeile(),
 *      sicherung_eingang()); keine Linie baut den Haken selbst. Ebenso prueft
 *      sicherung_eingang() die hochgeladene Datei (is_uploaded_file(), Groesse
 *      'max_byte') fuer alle Linien gleich, bevor sie gelesen wird.
 *   9. Seit 1.2.0 (Entscheidung des Hausherrn 12.11.2026: "Ja, alle acht ins
 *      Modul"): was die Linien in Welle 1 um das Modul herum bauten, kann das
 *      Modul selbst - Rohwert an 'pruefen' und 'quer', flache Teile
 *      ('teile_flach'), eigene Warnsaetze ('warnung'), ein Muster fuer fruehere
 *      Kennzeichen ('kennzeichen_frueher_muster'), 'umbenennen', 'fehlt' fuer
 *      Unterschluessel in Bloecken, Hinweise aus Teilen und fehlt_vorgabe in der
 *      Meldung, und 'vorbedingung'. Alles wahlfrei; ohne die Schluessel bleibt
 *      alles wie in 1.1.0.
 *  10. Seit 1.2.1 (Entscheidung des Hausherrn 12.11.2026: "Ja, 1.2.1 bauen"):
 *      ein Schluessel aus 'nie_aus_datei' (ein Vermerk) behaelt beim
 *      Zurueckspielen seinen geltenden Wert, wenn er in der geltenden
 *      Konfiguration steht; sonst bleibt alles wie bisher (Vorgabe, wenn er in
 *      'vorgaben' steht, sonst fehlt er). Er kommt nie aus der Datei und wird
 *      nie gesichert. Bis 1.2.0 begann die neue Konfiguration bei den Vorgaben,
 *      und der Vermerk ging verloren (gemessen am Geraet 12.11.2026, AWM-Abfuhr:
 *      ansage_token_herkunft fehlte danach, uebernommene Sprechtoken galten als
 *      von Hand eingetragen). Setzt 'vorbehandeln' ihn, gilt dieser Wert.
 *  11. Seit 1.2.2 (Entscheidung des Hausherrn 13.11.2026: "Weg (b), Modul
 *      1.2.2"): fehlt ein Schluessel mit 'fehlt' => 'behalten' in der Datei
 *      und hat der Rueckruf 'vorbehandeln' der Linie fuer ihn einen Wert
 *      gesetzt (eine Uebergangsregel der Linie, etwa aus einem anderen
 *      Schluessel derselben Datei), bleibt dieser Wert; er ging wie jeder Wert
 *      durch Regel 4. Die Meldung nennt ihn mit K_FEHLT_UEBERGANG, nicht als
 *      Werkseinstellung und nicht als behalten ($r['fehlt_uebergang']). Bis
 *      1.2.1 setzte 'behalten' auch dann den geltenden Wert und ueberschrieb
 *      die Regel (Alexa-NG Gruppe 6a, Chromecast4lox Gruppe 6b fuehrten deshalb
 *      'vorgabe', und die Meldung sagte faelschlich "Werkseinstellung").
 *      Setzt 'vorbehandeln' nichts, bleibt alles wie bisher.
 *  12. Seit 1.2.3 (Entscheidung des Hausherrn 15.11.2026: "Ja, Modul 1.2.3
 *      bauen"): eine UTF-8-BOM am Anfang der Datei wird vor json_decode()
 *      entfernt, in allen Linien gleich. Bis 1.2.2 wies das Modul eine solche
 *      Datei als "keine lesbare Sicherung" ab; Abfahrtsassistent hatte die BOM
 *      bis 1.6.40 selbst entfernt (Wunsch der Gruppe 4a, 14.11.2026). Eine Datei
 *      ohne BOM bleibt byte-gleich behandelt.
 *  13. Seit 1.2.4 (Entscheidung des Hausherrn 15.11.2026: "Ja, so umsetzen"):
 *      Schluessel der GELTENDEN Konfiguration, die die Beschreibung nicht kennt
 *      (oben: nicht in 'vorgaben' und kein Teil; in einem Block: ein
 *      Unterschluessel, den der Knoten nicht nennt), fallen beim Zurueckspielen
 *      weiter weg, werden aber mit Namen genannt, nie mit Wert
 *      ($r['jetzt_entfernt'], Text K_JETZT_ENTFERNT, Protokollzeile) - in allen
 *      Linien gleich, ohne Wahl je Linie. Bis 1.2.3 begann der neue Stand bei
 *      den Vorgaben, und ein solcher Schluessel (etwa aus einer Bearbeitung von
 *      Hand) fiel oben still weg; im Block nur dann nicht still, wenn die Datei
 *      ihn selbst trug (dann als verworfen genannt). Genannt wird, was nach
 *      'vorbereiten' nicht mehr im neuen Stand steht; ein Vermerk
 *      ('nie_aus_datei') bleibt seit 1.2.1 und wird deshalb nicht genannt.
 *      Was die Lesefunktion der Linie ('jetzt') nicht liefert, kann das Modul
 *      nicht nennen; was ihre Speicherfunktion ueber den neuen Stand hinaus
 *      behaelt, faellt nicht weg - beides misst der Pruefstand (geltend.fremd).
 *      Nicht nachgemessen ueber 'jetzt': das ist in Linien ein Schnappschuss
 *      vor dem Zurueckspielen (gemessen 15.11.2026, MGiSmart 1.1.45).
 *      Ohne Speichern (halb gueltig, Vorbedingung, Schreibfehler) ist die Liste
 *      leer, denn dann wurde nichts entfernt.
 *  14. Seit 1.2.5 (Entscheidung des Hausherrn 15.11.2026: "Modul 1.2.5 mit
 *      huelle"): eine Linie, deren fruehere Sicherungen die Einstellungen unter
 *      EINEM Schluessel ablegten (Huelle; Zendure SolarFlow bis 0.9.48:
 *      konfiguration), nennt ihn in 'huelle'. Traegt eine Datei, die Regel 1
 *      als eigene annimmt (Kennzeichen, frueheres Kennzeichen oder vom
 *      Uebersetzer erkannt), diesen Schluessel, wird er ausgepackt: sein Inhalt
 *      tritt an die oberste Ebene, der Kopf (_-Schluessel) bleibt, und danach
 *      gelten alle Regeln wie bei einer flachen Datei (umbenennen, Regel 2 und
 *      3, vorbehandeln, Regel 4, fehlende Schluessel, Vermerke). Als Ganzes
 *      abgewiesen wird sie, wenn oben neben der Huelle ein weiterer Schluessel
 *      ohne _ steht, die Huelle kein Feld mit Namen oder leer ist, oder ein
 *      _-Schluessel in ihr einen Kopfschluessel oben doppelt; die Meldung nennt
 *      Grund und Namen, nie Werte (K_HUELLE_*). Ausgepackt wird einmal, nicht
 *      verschachtelt. Eine Datei ohne den Schluessel wird wie bisher gelesen;
 *      sicherung_bauen() schreibt weiter flach. Ohne 'huelle' bleibt alles wie
 *      in 1.2.4. Bis 1.2.4 verwarf das Modul die Huelle als unbekannten
 *      Schluessel, und die Datei wurde mangels jeder Einstellung abgewiesen
 *      (gemessen 15.11.2026 an der Form von Zendure SolarFlow 0.9.47).
 *  15. Seit 1.2.5 (Entscheidung des Hausherrn 15.11.2026: "Ja, so umsetzen"):
 *      eine unbekannte Einstellung wird in Meldung und Protokollzeile genau
 *      einmal genannt. Ein Name, der schon als verworfen genannt ist (aus der
 *      Datei, Regel 2), steht nicht noch einmal in 'jetzt_entfernt'
 *      (sicherung_ohne_verworfen()). Bis 1.2.4 erschien ein unbekannter
 *      Blockschluessel zweimal, wenn die Sicherung aus dem Stand mit diesem
 *      Schluessel stammte. Ein Name, der nur in der geltenden Konfiguration
 *      steht, wird weiter mit K_JETZT_ENTFERNT genannt; Werte nie.
 *  16. Seit 1.2.6 (Entscheidung des Hausherrn 15.11.2026: Entwurf
 *      'neben_huelle' angenommen): eine Linie, deren fruehere Sicherungen neben
 *      der Huelle Datenbestaende als eigene Abschnitte trugen (Midea2Lox:
 *      geraete; Bewaesserung: zonen, quellen; Sprachsteuerung: saetze;
 *      Hausansicht: modell), nennt diese Teile in 'neben_huelle'. Ein solcher
 *      Teil darf oben neben der Huelle stehen; er bleibt beim Auspacken oben und
 *      laeuft danach wie jeder Teil ('teile'). Steht derselbe Name zugleich in
 *      der Huelle, wird die Datei als Ganzes abgewiesen (K_HUELLE_DOPPELT,
 *      gesammelt mit den uebrigen Gruenden der Regel 14; Namen, nie Werte). Jeder
 *      andere Schluessel ohne _ neben der Huelle bleibt "gemischt". Ohne
 *      'neben_huelle' bleibt alles wie in 1.2.5.
 *  17. Seit 1.2.7 (Anlass Zigbee2MqttNG 4.2.14: drei Konfigurationsdateien
 *      mqtt.json, service.json, meldungen.json, je mit eigener Zweitschrift,
 *      die Sicherung als Bloecke mqtt, service, meldungen; der Vermerk
 *      ansage_token_herkunft steht im Block meldungen):
 *      (a) 'zweitschrift' darf eine Liste von Paaren (Datei, Zweitschrift)
 *      liefern. Geurteilt wird ueber jedes Paar; die Zusammenfassung ist der
 *      schlechteste Punkt (fehlt vor abweichend vor mit), eine leere Liste
 *      und ein unbrauchbares Paar heissen "fehlt". Das Ergebnis traegt dann
 *      dazu 'zweitschrift_paare' (je Paar die Lage), die Protokollzeile zaehlt
 *      die Paare. Ein einzelnes Paar wird wie bis 1.2.6 beurteilt.
 *      (b) 'nie_aus_datei' nimmt Pfade block.feld (auch block.block.feld) an:
 *      der Wert kommt nie aus der Datei (dort still uebergangen, wie oben),
 *      bleibt aus der geltenden Konfiguration (sonst die Vorgabe des Blocks,
 *      sonst fehlt er), wird nie gesichert und nie genannt; setzt
 *      'vorbehandeln' ihn, gilt dieser Wert. Bis 1.2.6 uebergingen sicherung_lesen()
 *      und sicherung_bauen() eine solche Angabe STILL: der Vermerk wurde als
 *      verworfen genannt oder kam aus der Datei (gemessen 16.11.2026,
 *      Zigbee2MqttNG). Seit 1.2.7 ist jeder Eintrag mit Punkt, der weder
 *      Vorgabe oben noch gueltiger Blockpfad ist, ein Mangel der Beschreibung
 *      ('nie_aus_datei'); ein Eintrag ohne Punkt bleibt wie bis 1.2.6.
 *      Ohne Liste von Paaren und ohne Pfad bleibt alles wie in 1.2.6.
 *  18. Seit 1.2.8 (Entscheidung des Hausherrn 16.11.2026: "Ja, in 1.2.8"):
 *      'fehlt' => 'pflicht' auch an Unterschluesseln eines Blocks (auch in
 *      Bloecken in Bloecken und in den Eintraegen einer Liste), ausdruecklich je
 *      Unterschluessel gesetzt. Traegt die Datei den Block (bzw. den Eintrag),
 *      aber nicht diesen Unterschluessel, wird sie abgewiesen wie bei einem
 *      fehlenden Pflichtschluessel oben: Name block.feld (block.block.feld,
 *      liste[n].feld) im Satz K_FEHLEND, alle Beanstandungen gesammelt, nichts
 *      geaendert. Setzt 'vorbehandeln' ihn (Uebergangsregel der Linie), bleibt
 *      dessen Wert, er ging durch Regel 4, und die Meldung nennt ihn mit
 *      K_FEHLT_UEBERGANG (oben bis 1.2.8 nur fuer 'behalten', ein
 *      Pflichtschluessel oben wurde abgewiesen; seit 1.2.9 auch er, Regel 19).
 *      Fehlt der ganze Block,
 *      entscheidet die Angabe des Blocks selbst (oben: 'pflicht' ab Werk,
 *      'vorgabe', 'behalten'; im Block: 'pflicht', 'behalten' oder ohne Angabe);
 *      seine Unterschluessel werden dann nicht einzeln verlangt. Ein Wert an
 *      der Stelle des Blocks, der kein Feld ist (auch ''; Regel 3 haelt nur
 *      Einzelwerte), traegt keinen Unterschluessel. Bis 1.2.7 fehlte ein solcher
 *      Unterschluessel danach still im Block (gemessen 16.11.2026).
 *      Beschreibungspruefung (Mangel 'fehlt', K_BESCHREIBUNG, jede Datei
 *      abgewiesen): unterhalb der obersten Ebene ist 'fehlt' nur 'behalten' oder
 *      'pflicht' (null = nicht angegeben). Jeder andere Wert ist ein Mangel - auch
 *      'vorgabe', das ein Block nie umsetzte (bis 1.2.7 still wirkungslos, wie
 *      jeder Tippfehler). 'pflicht' ist ein Mangel unter einem Teil ('teile',
 *      auch flach), unter einem Schluessel aus 'alt' oder 'nie_aus_datei' und am
 *      Knoten eines Vermerks im Block (ein Vermerk kommt nie aus der Datei). Ohne
 *      'pflicht' und ohne ungueltigen Wert bleibt alles wie in 1.2.7; oben bleibt
 *      'fehlt' ungeprueft wie bisher (jeder andere Wert wirkt dort als 'pflicht').
 *  19. Seit 1.2.9 (Entscheidung des Hausherrn 17.11.2026: "Ja, beides in 1.2.9
 *      umsetzen", "Ich folge deinen Empfehlungen"): ein Pflichtschluessel oben
 *      ('pflicht' ab Werk, ausdruecklich oder jeder andere Wert als 'vorgabe'
 *      und 'behalten'), der in der Datei fehlt und den 'vorbehandeln' gesetzt
 *      hat, ist ein Uebergang wie bei 'behalten' (1.2.2) und im Block (1.2.8,
 *      Regel 18): sein Wert ging durch Regel 4 und bleibt, die Meldung nennt
 *      ihn mit K_FEHLT_UEBERGANG ('fehlt_uebergang'). Setzt 'vorbehandeln' ihn
 *      nicht, wird die Datei abgewiesen wie bisher; bis 1.2.8 wurde sie auch
 *      dann abgewiesen. Gemessen vorher (17.11.2026, Quelltext aller Linien):
 *      keine Linie setzt in 'vorbehandeln' einen Pflichtschluessel oben.
 *      'vorgabe' bleibt im Block ein Mangel (Regel 18). Ohne 'vorbehandeln',
 *      das einen fehlenden Pflichtschluessel oben setzt, wie in 1.2.8.
 *
 * Die Funktionen tragen das neutrale Kuerzel 'sicherung_' (wie 'ansage_' in
 * sprachausgabe.php). Laufen zwei Abschriften in einem PHP-Prozess, laedt sich
 * das Modul nur einmal, und es gilt die zuerst geladene Abschrift. Deshalb:
 * keine Funktion entfernen oder umbenennen, keine Bedeutung eines Parameters
 * oder einer Rueckgabe aendern; Neues kommt als neue Funktion oder als neuer,
 * wahlfreier Schluessel der Beschreibung.
 *
 * Laeuft unter PHP 7.4 bis 8.5 ohne Meldung; keine Sprachmittel ab 8.0
 * (kein match, kein str_contains, keine nullbaren Typen).
 *
 * ------------------------------------------------------------------
 * Die Beschreibung $b, die die Linie uebergibt
 * ------------------------------------------------------------------
 *
 *   'kennzeichen'  Pflicht. Wert von _plugin, /^[A-Za-z0-9_.\-]{1,80}\z/.
 *                  Ein bestehendes Kennzeichen einer Linie bleibt, wie es ist.
 *   'kennzeichen_frueher'  Liste frueherer Kennzeichen derselben Linie.
 *   'kennzeichen_frueher_muster'  (1.2.0) regulaerer Ausdruck fuer fruehere
 *                  Kennzeichen, die einen Teil der Anlage trugen (Ordner).
 *   'umbenennen'   (1.2.0) Name in der Datei => heutiger Name, vor allen Regeln;
 *                  nur wenn der heutige Name in der Datei fehlt (z. B. _amazon).
 *   'huelle'       (1.2.5) Name des Schluessels, unter dem fruehere Sicherungen
 *                  der Linie die Einstellungen ablegten (z. B. 'konfiguration');
 *                  Regel 14 im Kopf. Ein Name in Namensform ohne fuehrendes _,
 *                  der keine Vorgabe, kein Teil, kein Feld eines flachen Teils,
 *                  kein Schluessel aus 'alt' oder 'nie_aus_datei' und kein alter
 *                  Name aus 'umbenennen' ist - sonst ist die Beschreibung
 *                  mangelhaft ('huelle'), denn eine flache Datei koennte ihn
 *                  oben tragen. Wahlfrei; ohne ihn wie in 1.2.4.
 *   'neben_huelle' (1.2.6) Liste von Teilnamen (Schluessel aus 'teile'), die in
 *                  frueheren Sicherungen oben NEBEN der Huelle standen
 *                  (Datenbestaende in eigenen Dateien); Regel 16 im Kopf. Nur
 *                  mit tauglicher 'huelle'; eine nicht leere Liste ohne Doppel,
 *                  jeder Name ein Teil (damit nie die Huelle) - sonst ist die
 *                  Beschreibung mangelhaft ('neben_huelle'). null gilt als nicht
 *                  angegeben. Wahlfrei; ohne sie wie in 1.2.5.
 *   'teile_flach'  (1.2.0) Teil => Praefix: der Teil steht in der Datei als
 *                  flache Schluessel <praefix><feld> (BYD zugang_benutzer ...);
 *                  Regel 2, 3 und 'fehlt' gelten je Feld wie in einem Block,
 *                  Namen in den Meldungen wie in der Datei.
 *   'warnung'      (1.2.0) function ($cfg) -> Liste von Saetzen (ohne Werte),
 *                  die sicherung_bauen() an _warnung anhaengt.
 *   'vorbedingung' (1.2.0) function ($neu, $jetzt, $r) -> Liste von Saetzen:
 *                  nicht leer heisst, es wird nicht gespeichert; die Meldung
 *                  nennt diese Saetze statt K_SCHREIBFEHLER.
 *   'hinweis'      Text fuer _hinweis (sagt, von welchem Plugin die Datei ist
 *                  und dass sie Geheimnisse traegt). Ohne Angabe K_HINWEIS.
 *   'fassung'      Fassung fuer _fassung: Text oder function () -> Text.
 *                  Ohne Angabe sicherung_fassung_lesen($b).
 *   'home', 'ordner', 'plugin_cfg'  fuer sicherung_fassung_lesen(): LoxBerry-
 *                  Wurzel (Plugin-Datenbank), Plugin-Ordner, plugin.cfg als
 *                  Rueckfall im entpackten Archiv.
 *   'kopf'         weitere Kopffelder (Schluessel mit _), nach _stand.
 *   'vorgaben'     Pflicht. Die Vorgaben der Linie; jeder Schluessel darin
 *                  ist bekannt. Der Ausgangspunkt beim Zurueckspielen.
 *   'schluessel'   Name => Knoten, verfeinert einzelne Schluessel:
 *                    'art'     'wert' (ab Werk) | 'block' | 'liste' | 'offen'
 *                    'schluessel' (block) Name => Knoten der Unterschluessel
 *                    'eintrag' (liste) Knoten eines Eintrags (meist block)
 *                    'zuordnung' (liste) Feld, ueber das ein Eintrag der
 *                              Datei seinem Gegenstueck am Ziel zugeordnet
 *                              wird (fuer Regel 3); '' = keine Zuordnung
 *                    'fehlt'   oben: 'pflicht' (ab Werk) | 'vorgabe' |
 *                              'behalten' (geltender Wert, wird genannt);
 *                              seit 1.2.0 auch an Unterschluesseln eines
 *                              Blocks: 'behalten' (Name block.feld genannt);
 *                              seit 1.2.2 oben: setzt 'vorbehandeln' den
 *                              fehlenden Schluessel, bleibt dessen Wert
 *                              (Name in 'fehlt_uebergang');
 *                              seit 1.2.9 ebenso bei 'pflicht' oben
 *                              (Regel 19);
 *                              seit 1.2.8 auch an Unterschluesseln eines
 *                              Blocks (auch Block im Block, Eintrag einer
 *                              Liste): 'pflicht' - fehlt er in einem Block,
 *                              den die Datei traegt, wird sie abgewiesen
 *                              (Name block.feld); unterhalb der obersten
 *                              Ebene ist jeder andere Wert als 'behalten'
 *                              und 'pflicht' ein Mangel (Regel 18)
 *                    'pruefen' function ($wert, $name, $neu, $roh) -> '' | Text |
 *                              Liste von Texten; ersetzt $b['pruefen']. $roh
 *                              (seit 1.2.0): der Wert, wie er in der Datei
 *                              steht, vor Regel 2 und 3 (null = fehlt)
 *                    'wandeln' function ($wert) -> Wert, nach bestandener
 *                              Pruefung (Normalform der Linie)
 *                    'sichern' function ($wert) -> Wert beim Sichern
 *                              (z. B. ohne Sprechtoken)
 *                  Hilfen: sicherung_block(), sicherung_liste(), sicherung_offen().
 *                  'offen' heisst: Unterschluessel werden nicht gegen eine
 *                  Liste gehalten (dynamische Namen); die Pruefung der Linie
 *                  entscheidet.
 *   'alt'          Schluessel frueherer Fassungen, die still uebergangen
 *                  werden (wie bisher in der Linie).
 *   'nie_aus_datei' Schluessel, die nie aus einer Datei kommen (Vermerke);
 *                  still uebergangen, nie gesichert. Seit 1.2.1 behaelt ein
 *                  solcher Schluessel beim Zurueckspielen seinen geltenden
 *                  Wert ($jetzt), wenn er dort steht. Seit 1.2.7 auch ein
 *                  Pfad block.feld in einem Block (Knoten 'block' oben, in
 *                  'vorgaben'; Bloecke in Bloecken mit weiteren Punkten), Regel
 *                  17 (b) im Kopf; ein Eintrag mit Punkt, der weder Vorgabe
 *                  noch gueltiger Blockpfad ist, ist ein Mangel.
 *   'teile'        Name => function ($wert, $jetzt) -> array(Wert|null,
 *                  Beanstandungen[] [, Hinweise[] seit 1.2.0]); Teile der Datei, die nicht in die
 *                  Konfiguration gehen (eigene Geheimnisdatei). Ergebnis in
 *                  $r['teile'][Name]. Steht der Name auch in 'schluessel'
 *                  (als block), gelten Regel 2 und 3 auch dort; der geltende
 *                  Stand kommt aus 'teile_jetzt'[Name] (Wert oder Funktion).
 *   'pruefen'      function ($schluessel, $wert, $neu, $roh) -> '' | Text | Liste.
 *   'quer'         function ($neu, $r, $daten) -> Liste von array(Name, Text);
 *                  $daten (seit 1.2.0) die Datei vor Regel 2 und 3.
 *   'uebersetzer'  function ($daten) -> array|null: nur fuer eine Datei OHNE
 *                  Kennzeichen. Eine alte eigene Sicherung kommt (gegebenenfalls
 *                  umgeschrieben) zurueck, alles andere null.
 *   'vorbehandeln' function ($daten, $jetzt, $roh) -> $daten: nach Regel 2
 *                  und 3, vor der Pruefung (Bloecke vervollstaendigen ...).
 *                  Seit 1.2.2: ein Wert, den sie fuer einen in der Datei
 *                  fehlenden Schluessel mit 'fehlt' => 'behalten' setzt
 *                  (Uebergangsregel), bleibt und wird so genannt.
 *   'jetzt'        function () -> die geltende Konfiguration.
 *   't'            function ($schluessel) -> Text (wie xx_t()); Abschnitt
 *                  'abschnitt', ab Werk SICHERUNG.
 *   'e'            function ($s) -> maskierter Text; ab Werk htmlspecialchars.
 *   'max_byte'     Obergrenze der Datei, ab Werk SICHERUNG_MAX_BYTE.
 * Fuer sicherung_zurueckspielen() dazu:
 *   'sperre'       function () -> Griff | null;  'freigeben' function ($griff)
 *   'vorbereiten'  function ($neu, $jetzt, $r) -> $neu (vor dem Speichern)
 *   'speichern'    function ($neu, $jetzt, $r) -> bool (zieht die Zweitschrift)
 *   'zweitschrift' function () -> array(Konfigurationsdatei, Zweitschrift);
 *                  seit 1.2.7 auch eine Liste solcher Paare (eine Konfiguration
 *                  aus mehreren Dateien), Regel 17 (a) im Kopf
 *   'nachziehen'   function ($neu, $jetzt, $r) -> Liste von Meldungen
 *   'log'          function ($zeile)
 *
 * ------------------------------------------------------------------
 * Das Ergebnis $r von sicherung_lesen()
 * ------------------------------------------------------------------
 *
 *   'ok'            true = zurueckspielbar
 *   'neu'           die neue Konfiguration (null, wenn nicht ok)
 *   'anzahl'        uebernommene Schluessel der obersten Ebene
 *   'eigen'         'kennzeichen' | 'frueher' | 'uebersetzt' | ''
 *   'fremd'         '' | 'form' | 'gross' | 'kennzeichen' | 'ohne' ('form' seit
 *                   1.2.5 auch: die Huelle passt nicht, Regel 14; seit 1.2.6 auch:
 *                   ein Teil steht neben und in der Huelle, Regel 16)
 *   'kennzeichen_datei'  Kennzeichen der Datei (nur in Namensform, sonst '?')
 *   'fassung_datei' _fassung der Datei (nur in Namensform)
 *   'beanstandet'   Liste von array('name' => ..., 'text' => ...)
 *   'verworfen'     Namen unbekannter Schluessel (Regel 2)
 *   'leer_behalten' Namen leerer Werte, deren bisheriger Wert bleibt (Regel 3)
 *   'fehlt_behalten' Namen, die fehlen und den geltenden Wert behalten
 *   'fehlt_vorgabe' Namen, die fehlen und auf die Vorgabe gehen
 *   'fehlt_uebergang' (1.2.2) Namen, die fehlen und deren Wert 'vorbehandeln'
 *                   gesetzt hat ('fehlt' => 'behalten'; Uebergangsregel der Linie);
 *                   seit 1.2.8 auch block.feld mit 'fehlt' => 'pflicht' (Regel 18);
 *                   seit 1.2.9 auch Pflichtschluessel oben (Regel 19)
 *   'teile'         Ergebnisse der Teile
 *   'teile_hinweise' (1.2.0) Hinweise der Teile (fertige Saetze)
 *   'jetzt_entfernt' (1.2.4) Namen der Schluessel der geltenden Konfiguration, die
 *                   die Beschreibung nicht kennt und die der neue Stand nicht mehr
 *                   traegt (oben: Name; im Block: block.feld); in
 *                   sicherung_zurueckspielen() nach 'vorbereiten' gebildet; leer, wenn nicht
 *                   zurueckgespielt oder nicht gespeichert wurde; seit 1.2.5 ohne
 *                   die Namen, die schon in 'verworfen' stehen (Regel 15)
 * Ein Ergebnis von sicherung_zurueckspielen() traegt seit 1.2.0 dazu
 *   'vorbedingung'  Saetze der Vorbedingung (leer = erfuellt oder nicht gefragt)
 * und seit 1.2.7, nur wenn 'zweitschrift' eine Liste von Paaren lieferte,
 *   'zweitschrift_paare'  je Paar die Lage ('mit' | 'abweichend' | 'fehlt'), in der
 *                   Reihenfolge der Liste; 'zweitschrift' ist dann der schlechteste Punkt
 * Ein Ergebnis von sicherung_eingang(), das vor dem Lesen endete, traegt dazu
 *   'abbruch'       'haken' | 'datei' | 'gross' (sonst fehlt der Schluessel)
 * und die Felder von sicherung_zurueckspielen() ('gespeichert' false).
 * Alle Namen stammen aus der Datei und gehen durch sicherung_name(): kein
 * Zeilenumbruch, kein Semikolon, hoechstens 64 Zeichen. Werte stehen in
 * keinem Feld ausser 'neu' und 'teile'.
 */

/* Kein Endpunkt (Regeln/03): die Datei liegt meist im unangemeldeten Baum
 * webfrontend/html/ und waere dort direkt aufrufbar. Ein direkter Aufruf ueber
 * den Webserver bekommt 403 und tut sonst nichts. */
if (PHP_SAPI !== 'cli') {
    $sicherung_einstieg = get_included_files();
    if (isset($sicherung_einstieg[0]) && realpath($sicherung_einstieg[0]) === realpath(__FILE__)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "SICHERUNG;OK=0;GRUND=KEIN_ENDPUNKT\n";
        exit;
    }
    unset($sicherung_einstieg);
}

if (!defined('SICHERUNG_FASSUNG')) {

define('SICHERUNG_FASSUNG', '1.2.9');
/** Groesste Sicherungsdatei, die gelesen wird (Byte). */
define('SICHERUNG_MAX_BYTE', 262144);
/** Feldname des Hakens "Zurueckspielen bestaetigen" (seit 1.1.0; Wert 1). */
define('SICHERUNG_HAKEN', 'zurueck_ok');

/* ==================================================================
 * Bausteine der Beschreibung
 * ================================================================== */

/** Ein Block (Feld mit festen Unterschluesseln). $knoten verfeinert einzelne. */
function sicherung_block(array $namen, array $knoten = array())
{
    $s = array();
    foreach ($namen as $n) { $s[(string) $n] = array('art' => 'wert'); }
    foreach ($knoten as $n => $k) { $s[(string) $n] = $k; }
    return array('art' => 'block', 'schluessel' => $s);
}

/** Eine Liste von Eintraegen mit festen Feldern; $zuordnung = Feld fuer Regel 3. */
function sicherung_liste(array $namen, $zuordnung = '', array $knoten = array())
{
    return array('art' => 'liste', 'eintrag' => sicherung_block($namen, $knoten),
                 'zuordnung' => is_string($zuordnung) ? $zuordnung : '');
}

/** Ein Feld mit dynamischen Unterschluesseln: die Pruefung der Linie entscheidet. */
function sicherung_offen()
{
    return array('art' => 'offen');
}

/* ==================================================================
 * Kleinigkeiten
 * ================================================================== */

/** Text zu einer Kennung; ohne Uebersetzer oder ohne Eintrag der Schluessel selbst. */
function sicherung_t($id, array $b)
{
    $ab = (isset($b['abschnitt']) && is_string($b['abschnitt'])) ? $b['abschnitt'] : 'SICHERUNG';
    $s = $ab . '.' . $id;
    if (isset($b['t']) && is_callable($b['t'])) { return (string) call_user_func($b['t'], $s); }
    return $s;
}

/** Maskieren fuer die Ausgabe. */
function sicherung_e($s, array $b)
{
    if (isset($b['e']) && is_callable($b['e'])) { return (string) call_user_func($b['e'], (string) $s); }
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Ein Name aus der Datei fuer Meldung und Protokoll: nur Namenszeichen, hoechstens 64. */
function sicherung_name($n)
{
    $n = (string) preg_replace('/[^A-Za-z0-9_.\-\[\]]/', '?', (string) $n);
    if ($n === '') { $n = '?'; }
    return strlen($n) > 64 ? substr($n, 0, 64) . '...' : $n;
}

/** Ist der Text ein Name in Namensform? (Kennzeichen, Fassung) */
function sicherung_namensform($s)
{
    return is_string($s) && preg_match('/^[A-Za-z0-9_.\-]{1,80}\z/', $s) === 1;
}

/** Regel 3: bleibt der bisherige Wert, weil die Datei an dieser Stelle leer ist? */
function sicherung_leer_bleibt($datei, $jetzt)
{
    if ($datei !== '') { return false; }
    if (is_bool($jetzt)) { return $jetzt; }
    return (is_string($jetzt) || is_int($jetzt) || is_float($jetzt)) && (string) $jetzt !== '';
}

/** Der Knoten zu einem Schluessel der obersten Ebene. */
function sicherung_knoten(array $b, $k)
{
    if (isset($b['schluessel'][$k]) && is_array($b['schluessel'][$k])) { return $b['schluessel'][$k]; }
    return array('art' => 'wert');
}

/** Liste der Namen ohne Doppel, Reihenfolge des ersten Auftretens. */
function sicherung_einmal(array $l)
{
    $aus = array();
    foreach ($l as $x) { if (!in_array($x, $aus, true)) { $aus[] = $x; } }
    return $aus;
}

/**
 * Die Fassung des Plugins ohne zweite Quelle: zuerst die Plugin-Datenbank des
 * LoxBerry (data/system/plugindatabase.json, Eintrag mit 'folder' = $b['ordner']),
 * dann die Zeile VERSION= der plugin.cfg ($b['plugin_cfg'], fuer das entpackte
 * Archiv). '' = nicht feststellbar. Eine feste Nummer im Code waere eine fuenfte
 * Stelle neben plugin.cfg, release.cfg, prerelease.cfg und README.
 */
function sicherung_fassung_lesen(array $b)
{
    $home = isset($b['home']) && is_string($b['home']) ? $b['home'] : '';
    $ordner = isset($b['ordner']) && is_string($b['ordner']) ? $b['ordner'] : '';
    if ($home !== '' && $ordner !== '' && is_file($home . '/data/system/plugindatabase.json')) {
        $d = json_decode((string) @file_get_contents($home . '/data/system/plugindatabase.json'), true);
        if (isset($d['plugins']) && is_array($d['plugins'])) {
            foreach ($d['plugins'] as $e) {
                if (is_array($e) && isset($e['folder']) && $e['folder'] === $ordner
                    && isset($e['version']) && sicherung_namensform((string) $e['version'])) {
                    return (string) $e['version'];
                }
            }
        }
    }
    $cfg = isset($b['plugin_cfg']) && is_string($b['plugin_cfg']) ? $b['plugin_cfg'] : '';
    if ($cfg !== '' && is_file($cfg)) {
        foreach ((array) @file($cfg, FILE_IGNORE_NEW_LINES) as $z) {
            if (preg_match('/^\s*VERSION\s*=\s*([A-Za-z0-9_.\-]{1,40})\s*$/', (string) $z, $m)) { return $m[1]; }
        }
    }
    return '';
}

/** Die Fassung fuer den Kopf. */
function sicherung_fassung(array $b)
{
    if (isset($b['fassung'])) {
        $f = is_callable($b['fassung']) ? call_user_func($b['fassung']) : $b['fassung'];
        if (is_string($f) && $f !== '') { return $f; }
    }
    return sicherung_fassung_lesen($b);
}

/** Die Beschreibung taugt? Rueckgabe: Liste der Maengel (Namen), leer = ja. */
function sicherung_beschreibung_mangel(array $b)
{
    $m = array();
    if (!isset($b['kennzeichen']) || !sicherung_namensform($b['kennzeichen'])) { $m[] = 'kennzeichen'; }
    if (!isset($b['vorgaben']) || !is_array($b['vorgaben']) || !$b['vorgaben']) { $m[] = 'vorgaben'; }
    foreach (array('pruefen', 'quer', 'uebersetzer', 'vorbehandeln', 'jetzt', 't', 'e', 'sperre', 'freigeben',
                   'vorbereiten', 'speichern', 'zweitschrift', 'nachziehen', 'log', 'fassung', 'warnung',
                   'vorbedingung') as $f) {
        if (isset($b[$f]) && $f !== 'fassung' && !is_callable($b[$f])) { $m[] = $f; }
    }
    // 1.2.0: die wahlfreien Angaben muessen die beschriebene Form haben (sonst fiele ein Tippfehler still durch)
    if (isset($b['umbenennen']) && !is_array($b['umbenennen'])) { $m[] = 'umbenennen'; }
    if (isset($b['teile_flach']) && (!is_array($b['teile_flach']) || !isset($b['teile']) || !is_array($b['teile'])
        || array_diff_key($b['teile_flach'], $b['teile']))) { $m[] = 'teile_flach'; }
    if (isset($b['kennzeichen_frueher_muster'])
        && (!is_string($b['kennzeichen_frueher_muster']) || @preg_match($b['kennzeichen_frueher_muster'], '') === false)) {
        $m[] = 'kennzeichen_frueher_muster';
    }
    // 1.2.5: die Huelle muss ein Name sein, den eine flache Datei oben nie traegt (sonst waere sie nicht zu unterscheiden)
    if (isset($b['huelle']) && !sicherung_huelle_taugt($b)) { $m[] = 'huelle'; }
    // 1.2.6: Teile neben der Huelle nur als Liste von Teilen und nur mit tauglicher Huelle
    if (isset($b['neben_huelle']) && !sicherung_neben_huelle_taugt($b)) { $m[] = 'neben_huelle'; }
    // 1.2.7: ein Vermerk mit Punkt ist eine Vorgabe oben oder ein gueltiger Blockpfad - sonst fiele er still durch
    if (isset($b['nie_aus_datei']) && is_array($b['nie_aus_datei']) && !sicherung_nie_aus_datei_taugt($b)) {
        $m[] = 'nie_aus_datei';
    }
    // 1.2.8: 'fehlt' unterhalb der obersten Ebene nur 'behalten' oder 'pflicht' (Regel 18) - sonst fiele er still durch
    if (!sicherung_fehlt_taugt($b)) { $m[] = 'fehlt'; }
    return $m;
}

/**
 * 1.2.7: der Pfad eines Vermerks in einem Block ('nie_aus_datei' => 'block.feld', Regel 17 (b) im Kopf) als Liste der
 * Namen - oder null, wenn er keiner ist. Ein Pfad: eine Zeichenkette mit Punkt, die keine Vorgabe oben ist; jeder Teil
 * in Namensform; der erste ein Schluessel aus 'vorgaben' mit Knoten 'block' (kein Teil, kein Schluessel aus 'alt' oder
 * 'nie_aus_datei'); jeder weitere bis auf den letzten ein Unterschluessel mit Knoten 'block'. Der letzte darf, muss
 * aber nicht im Knoten stehen.
 */
function sicherung_nie_pfad(array $b, $x)
{
    if (!is_string($x) || strpos($x, '.') === false) { return null; }
    $vorgaben = isset($b['vorgaben']) && is_array($b['vorgaben']) ? $b['vorgaben'] : array();
    if (array_key_exists($x, $vorgaben)) { return null; }       // eine Vorgabe oben mit Punkt im Namen - wie bis 1.2.6
    $teile = explode('.', $x);
    foreach ($teile as $t) {
        if (!sicherung_namensform($t)) { return null; }
    }
    $k = $teile[0];
    if (!array_key_exists($k, $vorgaben)) { return null; }
    if (isset($b['teile']) && is_array($b['teile']) && array_key_exists($k, $b['teile'])) { return null; }
    foreach (array('alt', 'nie_aus_datei') as $f) {
        if (isset($b[$f]) && is_array($b[$f]) && in_array($k, $b[$f], true)) { return null; }
    }
    $knoten = sicherung_knoten($b, $k);
    for ($i = 1; $i < count($teile); $i++) {
        if (!isset($knoten['art']) || $knoten['art'] !== 'block') { return null; }
        if ($i === count($teile) - 1) { break; }
        $schl = isset($knoten['schluessel']) && is_array($knoten['schluessel']) ? $knoten['schluessel'] : array();
        if (!isset($schl[$teile[$i]]) || !is_array($schl[$teile[$i]])) { return null; }
        $knoten = $schl[$teile[$i]];
    }
    return $teile;
}

/** 1.2.7: taugt 'nie_aus_datei'? Jeder Eintrag mit Punkt ist eine Vorgabe oben oder ein gueltiger Blockpfad. */
function sicherung_nie_aus_datei_taugt(array $b)
{
    $vorgaben = isset($b['vorgaben']) && is_array($b['vorgaben']) ? $b['vorgaben'] : array();
    foreach ($b['nie_aus_datei'] as $x) {
        // Ein Eintrag ohne Punkt (oder eine Vorgabe mit Punkt) bleibt wie bis 1.2.6 - auch einer ausserhalb der Vorgaben.
        if (!is_string($x) || strpos($x, '.') === false || array_key_exists($x, $vorgaben)) { continue; }
        if (sicherung_nie_pfad($b, $x) === null) { return false; }
    }
    return true;
}

/** 1.2.7: die gueltigen Blockpfade aus 'nie_aus_datei', je Block oben: Name => Liste der Restpfade (Namenslisten). */
function sicherung_nie_pfade(array $b)
{
    $aus = array();
    $nie = isset($b['nie_aus_datei']) && is_array($b['nie_aus_datei']) ? $b['nie_aus_datei'] : array();
    foreach ($nie as $x) {
        $p = sicherung_nie_pfad($b, $x);
        if ($p !== null) { $aus[$p[0]][] = array_slice($p, 1); }
    }
    return $aus;
}

/** 1.2.7: steht unter $w der Pfad $p (Namensliste)? Rueckgabe array(bool, Wert). */
function sicherung_pfad_holen($w, array $p)
{
    foreach ($p as $t) {
        if (!is_array($w) || !array_key_exists($t, $w)) { return array(false, null); }
        $w = $w[$t];
    }
    return array(true, $w);
}

/** 1.2.7: $w ohne den Pfad $p; was unterwegs kein Feld ist, bleibt, wie es ist. */
function sicherung_pfad_ohne($w, array $p)
{
    if (!is_array($w) || !$p) { return $w; }
    $t = array_shift($p);
    if (!array_key_exists($t, $w)) { return $w; }
    if (!$p) {
        unset($w[$t]);
        return $w;
    }
    $w[$t] = sicherung_pfad_ohne($w[$t], $p);
    return $w;
}

/** 1.2.7: $w mit dem Wert $v am Pfad $p; fehlende Felder unterwegs werden angelegt, ein Wert unterwegs bleibt. */
function sicherung_pfad_mit($w, array $p, $v)
{
    if (!is_array($w) || !$p) { return $w; }
    $t = array_shift($p);
    if (!$p) {
        $w[$t] = $v;
        return $w;
    }
    $w[$t] = sicherung_pfad_mit(array_key_exists($t, $w) ? $w[$t] : array(), $p, $v);
    return $w;
}

/** 1.2.7: der Knoten eines Blocks ohne die Unterschluessel der Pfade $pfade (dann nennt Regel 2 und 'fehlt' sie nicht). */
function sicherung_knoten_ohne(array $knoten, array $pfade)
{
    foreach ($pfade as $p) {
        $k = &$knoten;
        $n = count($p);
        foreach ($p as $i => $t) {
            if (!isset($k['schluessel']) || !is_array($k['schluessel'])) { break; }
            if ($i === $n - 1) {
                unset($k['schluessel'][$t]);
                break;
            }
            if (!isset($k['schluessel'][$t]) || !is_array($k['schluessel'][$t])) { break; }
            $k = &$k['schluessel'][$t];
        }
        unset($k);
    }
    return $knoten;
}

/**
 * 1.2.7: der Wert eines Vermerks im Block, wie er nach dem Zurueckspielen gilt: der geltende ($jetzt), sonst die
 * Vorgabe des Blocks, sonst keiner. Rueckgabe array(bool, Wert) - false heisst, er fehlt danach.
 */
function sicherung_nie_wert(array $vorgaben, array $jetzt, $k, array $p)
{
    $j = array_key_exists($k, $jetzt) ? sicherung_pfad_holen($jetzt[$k], $p) : array(false, null);
    if ($j[0]) { return $j; }
    return array_key_exists($k, $vorgaben) ? sicherung_pfad_holen($vorgaben[$k], $p) : array(false, null);
}

/**
 * 1.2.6: taugt $b['neben_huelle']? Eine nicht leere Liste (Schluessel 0..n-1) von Zeichenketten ohne Doppel, jede ein
 * Schluessel aus 'teile' (damit nie der Name der Huelle); dazu eine taugliche 'huelle'. Sonst fiele ein Tippfehler still
 * durch, oder eine Einstellung stuende neben der Huelle, und welche gilt, waere nicht zu entscheiden.
 */
function sicherung_neben_huelle_taugt(array $b)
{
    $n = isset($b['neben_huelle']) ? $b['neben_huelle'] : null;
    if (!is_array($n) || $n === array() || array_keys($n) !== range(0, count($n) - 1)) { return false; }
    if (!isset($b['huelle']) || !sicherung_huelle_taugt($b)) { return false; }
    $teile = isset($b['teile']) && is_array($b['teile']) ? $b['teile'] : array();
    $gesehen = array();
    foreach ($n as $x) {
        // (Der Name der Huelle ist nie ein Teil - das sichert schon sicherung_huelle_taugt().)
        if (!is_string($x) || !array_key_exists($x, $teile) || isset($gesehen[$x])) { return false; }
        $gesehen[$x] = true;
    }
    return true;
}

/** 1.2.6: die Teile, die neben der Huelle stehen duerfen (leer = keine; nur nach bestandener Beschreibungspruefung). */
function sicherung_neben_huelle(array $b)
{
    return (isset($b['neben_huelle']) && is_array($b['neben_huelle'])) ? array_values($b['neben_huelle']) : array();
}

/** 1.2.5: der Name der Huelle aus der Beschreibung, '' = keine. */
function sicherung_huelle(array $b)
{
    return (isset($b['huelle']) && is_string($b['huelle'])) ? $b['huelle'] : '';
}

/**
 * 1.2.5: taugt $b['huelle'] als Name einer Huelle? Ein Name in Namensform ohne fuehrendes _, den eine flache Datei
 * dieser Linie oben nicht tragen kann: keine Vorgabe, kein Teil, kein Feld eines flachen Teils, kein Schluessel aus
 * 'alt' oder 'nie_aus_datei', kein alter Name aus 'umbenennen'.
 */
function sicherung_huelle_taugt(array $b)
{
    $h = isset($b['huelle']) ? $b['huelle'] : null;
    if (!is_string($h) || !sicherung_namensform($h) || $h[0] === '_') { return false; }
    if (isset($b['vorgaben']) && is_array($b['vorgaben']) && array_key_exists($h, $b['vorgaben'])) { return false; }
    if (isset($b['teile']) && is_array($b['teile']) && array_key_exists($h, $b['teile'])) { return false; }
    foreach (array('alt', 'nie_aus_datei') as $f) {
        if (isset($b[$f]) && is_array($b[$f]) && in_array($h, $b[$f], true)) { return false; }
    }
    if (isset($b['umbenennen']) && is_array($b['umbenennen']) && array_key_exists($h, $b['umbenennen'])) { return false; }
    if (isset($b['teile_flach']) && is_array($b['teile_flach'])) {
        foreach ($b['teile_flach'] as $px) {
            $px = (string) $px;
            if ($px !== '' && strpos($h, $px) === 0 && strlen($h) > strlen($px)) { return false; }
        }
    }
    return true;
}

/**
 * 1.2.8: taugen die Angaben 'fehlt' unterhalb der obersten Ebene (Regel 18 im Kopf)? Im Block (auch Block im Block und
 * Eintrag einer Liste) nur 'behalten' oder 'pflicht', null = nicht angegeben; 'pflicht' nicht unter einem Teil, einem
 * Schluessel aus 'alt' oder 'nie_aus_datei' und nicht am Knoten eines Vermerks im Block. Sonst fiele die Angabe still
 * durch - bis 1.2.7 tat jeder andere Wert als 'behalten' im Block nichts (gemessen 16.11.2026).
 */
function sicherung_fehlt_taugt(array $b)
{
    $schl = isset($b['schluessel']) && is_array($b['schluessel']) ? $b['schluessel'] : array();
    $teile = isset($b['teile']) && is_array($b['teile']) ? $b['teile'] : array();
    $ohne = array();        // oben: darunter gibt es kein 'pflicht' (der Wert kommt nie aus der Datei oder geht nirgends hin)
    foreach (array('alt', 'nie_aus_datei') as $f) {
        if (isset($b[$f]) && is_array($b[$f])) {
            foreach ($b[$f] as $x) { if (is_string($x)) { $ohne[$x] = true; } }
        }
    }
    foreach ($schl as $k => $kn) {
        if (!is_array($kn)) { continue; }
        $k = (string) $k;
        if (!sicherung_fehlt_knoten_taugt($kn, !array_key_exists($k, $teile) && !isset($ohne[$k]))) { return false; }
    }
    // ein Vermerk im Block ('nie_aus_datei' mit Pfad) kann nicht Pflicht sein: er kommt nie aus der Datei
    foreach (isset($b['nie_aus_datei']) && is_array($b['nie_aus_datei']) ? $b['nie_aus_datei'] : array() as $x) {
        $p = sicherung_nie_pfad($b, $x);
        if ($p === null) { continue; }
        $kn = sicherung_knoten($b, $p[0]);
        for ($i = 1; $i < count($p) && is_array($kn); $i++) {
            $kn = (isset($kn['schluessel'][$p[$i]]) && is_array($kn['schluessel'][$p[$i]])) ? $kn['schluessel'][$p[$i]] : null;
        }
        if (is_array($kn) && isset($kn['fehlt']) && $kn['fehlt'] === 'pflicht') { return false; }
    }
    return true;
}

/** 1.2.8: die Unterschluessel eines Knotens (Block, Eintrag einer Liste) und ihre Angaben 'fehlt', rekursiv. */
function sicherung_fehlt_knoten_taugt(array $knoten, $pflicht_erlaubt)
{
    $art = isset($knoten['art']) ? $knoten['art'] : 'wert';
    if ($art === 'liste') {
        return !isset($knoten['eintrag']) || !is_array($knoten['eintrag'])
            || sicherung_fehlt_knoten_taugt($knoten['eintrag'], $pflicht_erlaubt);
    }
    if ($art !== 'block' || !isset($knoten['schluessel']) || !is_array($knoten['schluessel'])) { return true; }
    foreach ($knoten['schluessel'] as $sk) {
        if (!is_array($sk)) { continue; }
        if (isset($sk['fehlt'])) {
            if ($sk['fehlt'] === 'pflicht') {
                if (!$pflicht_erlaubt) { return false; }
            } elseif ($sk['fehlt'] !== 'behalten') {
                return false;
            }
        }
        if (!sicherung_fehlt_knoten_taugt($sk, $pflicht_erlaubt)) { return false; }
    }
    return true;
}

/**
 * 1.2.8: Pflichtschluessel in einem Block, den die Datei traegt (Regel 18 im Kopf). $wd: der Wert in der Datei, $wb:
 * derselbe Wert nach 'vorbehandeln' (null = keiner). Ein Unterschluessel mit 'fehlt' => 'pflicht', den $wd nicht
 * traegt, kommt nach $fehlend (Name block.feld) - oder nach $uebergang, wenn 'vorbehandeln' ihn gesetzt hat. Bloecke in
 * Bloecken und Eintraege einer Liste ebenso, soweit die Datei sie traegt. Ein Wert, der kein Feld ist (auch ''), traegt
 * keinen Unterschluessel: Regel 3 haelt nur Einzelwerte, '' ersetzt den Block (gemessen 16.11.2026).
 */
function sicherung_pflicht_fehlend(array $knoten, $wd, $wb, $pfad, array &$fehlend, array &$uebergang)
{
    $art = isset($knoten['art']) ? $knoten['art'] : 'wert';
    if ($art === 'liste') {
        if (!is_array($wd) || !isset($knoten['eintrag']) || !is_array($knoten['eintrag'])) { return; }
        foreach ($wd as $i => $e) {
            $name = $pfad . '[' . (is_int($i) ? (string) ($i + 1) : sicherung_name($i)) . ']';
            sicherung_pflicht_fehlend($knoten['eintrag'], $e, (is_array($wb) && array_key_exists($i, $wb)) ? $wb[$i] : null,
                                      $name, $fehlend, $uebergang);
        }
        return;
    }
    if ($art !== 'block' || !isset($knoten['schluessel']) || !is_array($knoten['schluessel'])) { return; }
    foreach ($knoten['schluessel'] as $sk => $sknoten) {
        if (!is_array($sknoten)) { continue; }
        $sk = (string) $sk;
        $name = $pfad . '.' . sicherung_name($sk);
        if (!is_array($wd) || !array_key_exists($sk, $wd)) {
            if (isset($sknoten['fehlt']) && $sknoten['fehlt'] === 'pflicht') {
                if (is_array($wb) && array_key_exists($sk, $wb)) { $uebergang[] = $name; } else { $fehlend[] = $name; }
            }
            continue;
        }
        sicherung_pflicht_fehlend($sknoten, $wd[$sk], (is_array($wb) && array_key_exists($sk, $wb)) ? $wb[$sk] : null,
                                  $name, $fehlend, $uebergang);
    }
}

/* ==================================================================
 * Sichern
 * ================================================================== */

/** Der Inhalt eines Knotens fuer die Sicherung. */
function sicherung_knoten_sichern(array $knoten, $w)
{
    if (isset($knoten['sichern']) && is_callable($knoten['sichern'])) { $w = call_user_func($knoten['sichern'], $w); }
    return $w;
}

/**
 * Die Sicherung als Feld: lesbarer Kopf (_hinweis, _plugin, _fassung, _stand,
 * dann $b['kopf']) und alle bekannten Schluessel aus $cfg in der Reihenfolge
 * der Vorgaben - auch die, die gleich der Vorgabe sind. Was $cfg nicht traegt,
 * fehlt auch in der Datei (dann sagt es _warnung). Ohne 'nie_aus_datei' und
 * 'alt'. $teile: weitere Teile der Datei (Name => Wert, z. B. Zugangsdaten
 * aus einer eigenen Datei). Traegt die Datei etwas, das das eigene
 * Zurueckspielen abwiese, steht in _warnung, was - nur Namen.
 */
function sicherung_bauen(array $b, array $cfg, array $teile = array())
{
    $kopf = array(
        '_hinweis' => (isset($b['hinweis']) && is_string($b['hinweis'])) ? $b['hinweis'] : sicherung_t('K_HINWEIS', $b),
        '_plugin'  => (string) $b['kennzeichen'],
        '_fassung' => sicherung_fassung($b),
        '_stand'   => date('Y-m-d H:i:s'),
    );
    if (isset($b['kopf']) && is_array($b['kopf'])) {
        foreach ($b['kopf'] as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] === '_' && !isset($kopf[$k])) { $kopf[$k] = $v; }
        }
    }
    $nie = isset($b['nie_aus_datei']) && is_array($b['nie_aus_datei']) ? $b['nie_aus_datei'] : array();
    $nie_pfade = sicherung_nie_pfade($b);       // 1.2.7: Vermerke in Bloecken werden nie gesichert
    $inhalt = array();
    foreach (array_keys($b['vorgaben']) as $k) {
        if (in_array($k, $nie, true) || !array_key_exists($k, $cfg)) { continue; }
        $inhalt[$k] = sicherung_knoten_sichern(sicherung_knoten($b, $k), $cfg[$k]);
        if (isset($nie_pfade[$k])) {
            foreach ($nie_pfade[$k] as $p) { $inhalt[$k] = sicherung_pfad_ohne($inhalt[$k], $p); }
        }
    }
    $flach = isset($b['teile_flach']) && is_array($b['teile_flach']) ? $b['teile_flach'] : array();
    foreach ($teile as $k => $v) {
        if (!is_string($k) || !isset($b['teile'][$k])) { continue; }
        if (isset($flach[$k]) && is_array($v)) {
            // 1.2.0: ein flacher Teil steht als <praefix><feld> in der Datei
            foreach ($v as $f => $fw) { $inhalt[(string) $flach[$k] . (string) $f] = $fw; }
            continue;
        }
        $inhalt[$k] = $v;
    }
    $daten = $kopf + $inhalt;
    $namen = sicherung_selbstpruefung($b, $daten, $cfg);
    $saetze = array();
    if ($namen) { $saetze[] = sprintf(sicherung_t('K_WARNUNG', $b), implode(', ', $namen)); }
    if (isset($b['warnung']) && is_callable($b['warnung'])) {
        // 1.2.0: eigene Warnsaetze der Linie (nur Namen und Gruende, nie Werte) haengen an _warnung
        foreach ((array) call_user_func($b['warnung'], $cfg) as $s) {
            if (is_string($s) && $s !== '') { $saetze[] = $s; }
        }
    }
    if ($saetze) {
        $daten = $kopf + array('_warnung' => implode(' ', $saetze)) + $inhalt;
    }
    return $daten;
}

/** Das Feld als Datei: lesbar eingerueckt; '' wenn es sich nicht bilden laesst. */
function sicherung_json(array $daten)
{
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($js) ? $js : '';
}

/**
 * Wuerde das eigene Zurueckspielen diese Daten abweisen? Rueckgabe: die Namen der
 * beanstandeten Schluessel (leer = sie liesse sich zurueckspielen), nie Werte.
 * Dieselbe Pruefung wie beim Zurueckspielen, gegen $jetzt als Ziel.
 */
function sicherung_selbstpruefung(array $b, array $daten, $jetzt = null)
{
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($js)) { return array('-'); }
    $r = sicherung_lesen($b, $js, is_array($jetzt) ? $jetzt : array());
    if ($r['ok']) { return array(); }
    $namen = array();
    foreach ($r['beanstandet'] as $x) { $namen[] = $x['name']; }
    return $namen ? sicherung_einmal($namen) : array('-');
}

/* ==================================================================
 * Lesen und pruefen
 * ================================================================== */

/** Ein leeres Ergebnis. */
function sicherung_ergebnis()
{
    return array('ok' => false, 'neu' => null, 'anzahl' => 0, 'eigen' => '', 'fremd' => '',
                 'kennzeichen_datei' => '', 'fassung_datei' => '', 'beanstandet' => array(),
                 'verworfen' => array(), 'leer_behalten' => array(), 'fehlt_behalten' => array(),
                 'fehlt_vorgabe' => array(), 'teile' => array(), 'teile_hinweise' => array(),
                 'fehlt_uebergang' => array(), 'jetzt_entfernt' => array());
}

/** Eine Beanstandung aufnehmen ($text: '' | Text | Liste). */
function sicherung_beanstanden(array &$r, $name, $text)
{
    if (is_array($text)) {
        foreach ($text as $t) { $r['beanstandet'][] = array('name' => (string) $name, 'text' => (string) $t); }
        return;
    }
    $r['beanstandet'][] = array('name' => (string) $name, 'text' => (string) $text);
}

/**
 * Regel 2 und 3 an einem Knoten: unbekannte Unterschluessel verwerfen (Name in
 * $r['verworfen']), leere Werte durch den bisherigen ersetzen (Name in
 * $r['leer_behalten']). Rueckgabe: der bereinigte Wert. Was keine Form eines
 * Blocks oder einer Liste hat, bleibt unveraendert - das entscheidet die Pruefung.
 */
function sicherung_knoten_reinigen(array $knoten, $w, $j, $pfad, array &$r, $trenner = '.')
{
    $art = isset($knoten['art']) ? $knoten['art'] : 'wert';
    if ($art === 'block' && is_array($w)) {
        $schl = isset($knoten['schluessel']) && is_array($knoten['schluessel']) ? $knoten['schluessel'] : array();
        /* 1.2.0: ein Unterschluessel mit 'fehlt' => 'behalten', den die Datei nicht traegt, bekommt den geltenden
         * Wert und wird genannt (wie oben; ein Feld, das eine fruehere Fassung noch nicht kannte). */
        foreach ($schl as $sk => $sknoten) {
            if (is_array($sknoten) && isset($sknoten['fehlt']) && $sknoten['fehlt'] === 'behalten'
                && !array_key_exists($sk, $w) && is_array($j) && array_key_exists($sk, $j)) {
                $w[$sk] = $j[$sk];
                $r['fehlt_behalten'][] = $pfad . $trenner . sicherung_name($sk);
            }
        }
        foreach (array_keys($w) as $sk) {
            $name = $pfad . $trenner . sicherung_name($sk);
            if (!array_key_exists((string) $sk, $schl)) {
                $r['verworfen'][] = $name;
                unset($w[$sk]);
                continue;
            }
            $js = (is_array($j) && array_key_exists($sk, $j)) ? $j[$sk] : null;
            if (sicherung_leer_bleibt($w[$sk], $js)) {
                $w[$sk] = $js;
                $r['leer_behalten'][] = $name;
                continue;
            }
            $w[$sk] = sicherung_knoten_reinigen($schl[(string) $sk], $w[$sk], $js, $name, $r);
        }
        return $w;
    }
    if ($art === 'liste' && is_array($w)) {
        $eintrag = isset($knoten['eintrag']) && is_array($knoten['eintrag']) ? $knoten['eintrag'] : array('art' => 'offen');
        $zu = isset($knoten['zuordnung']) && is_string($knoten['zuordnung']) ? $knoten['zuordnung'] : '';
        foreach (array_keys($w) as $i) {
            if (!is_array($w[$i])) { continue; }
            $name = $pfad . '[' . (is_int($i) ? (string) ($i + 1) : sicherung_name($i)) . ']';
            $je = null;
            if ($zu !== '' && isset($w[$i][$zu]) && is_scalar($w[$i][$zu]) && (string) $w[$i][$zu] !== '' && is_array($j)) {
                foreach ($j as $kand) {
                    if (is_array($kand) && isset($kand[$zu]) && is_scalar($kand[$zu])
                        && (string) $kand[$zu] === (string) $w[$i][$zu]) { $je = $kand; break; }
                }
            }
            $w[$i] = sicherung_knoten_reinigen($eintrag, $w[$i], $je, $name, $r);
        }
        return $w;
    }
    return $w;
}

/**
 * 1.2.4: die Namen der Schluessel der geltenden Konfiguration $jetzt, die die Beschreibung nicht kennt und die der
 * neue Stand $neu nicht mehr traegt - nur Namen, nie Werte (Regel 13 im Kopf). Oben: nicht in 'vorgaben', kein Teil
 * ('teile') und kein Feld eines flachen Teils ('teile_flach'). In einem Block (Knoten 'block'): ein Unterschluessel,
 * den der Knoten nicht nennt; Bloecke in Bloecken ebenso. Listen und 'offen' werden nicht untersucht (ihre Eintraege
 * lassen sich keinem Gegenstueck sicher zuordnen bzw. tragen dynamische Namen).
 */
function sicherung_jetzt_entfernt(array $b, array $jetzt, $neu)
{
    $aus = array();
    if (!is_array($neu)) { return $aus; }
    $vorgaben = isset($b['vorgaben']) && is_array($b['vorgaben']) ? $b['vorgaben'] : array();
    $teile = isset($b['teile']) && is_array($b['teile']) ? $b['teile'] : array();
    $flach = isset($b['teile_flach']) && is_array($b['teile_flach']) ? $b['teile_flach'] : array();
    foreach ($jetzt as $k => $w) {
        $k = (string) $k;
        if (isset($teile[$k])) { continue; }
        if (!array_key_exists($k, $vorgaben)) {
            $teil_feld = false;
            foreach ($flach as $px) {
                $px = (string) $px;
                if ($px !== '' && strpos($k, $px) === 0 && strlen($k) > strlen($px)) { $teil_feld = true; break; }
            }
            if (!$teil_feld && !array_key_exists($k, $neu)) { $aus[] = sicherung_name($k); }
            continue;
        }
        if (is_array($w) && isset($neu[$k]) && is_array($neu[$k])) {
            sicherung_jetzt_entfernt_block(sicherung_knoten($b, $k), $w, $neu[$k], sicherung_name($k), $aus);
        }
    }
    return $aus;
}

/** 1.2.4: wie sicherung_jetzt_entfernt() fuer die Unterschluessel eines Blocks ($pfad: Name des Blocks). */
function sicherung_jetzt_entfernt_block(array $knoten, array $j, array $n, $pfad, array &$aus)
{
    if (!isset($knoten['art']) || $knoten['art'] !== 'block') { return; }
    $schl = isset($knoten['schluessel']) && is_array($knoten['schluessel']) ? $knoten['schluessel'] : array();
    foreach ($j as $sk => $sw) {
        $name = $pfad . '.' . sicherung_name($sk);
        if (!array_key_exists((string) $sk, $schl)) {
            if (!array_key_exists($sk, $n)) { $aus[] = $name; }
            continue;
        }
        if (is_array($sw) && is_array($schl[(string) $sk]) && isset($n[$sk]) && is_array($n[$sk])) {
            sicherung_jetzt_entfernt_block($schl[(string) $sk], $sw, $n[$sk], $name, $aus);
        }
    }
}

/**
 * 1.2.5 (Entscheidung des Hausherrn 15.11.2026: "Ja, so umsetzen"): ein Name, den $r schon als verworfen nennt (aus
 * der Datei), wird nicht noch einmal als entfernt genannt (aus der geltenden Konfiguration) - in Meldung und
 * Protokollzeile genau einmal (Regel 15 im Kopf). Bis 1.2.4 nannte die Meldung einen unbekannten Blockschluessel
 * zweimal, wenn die Sicherung aus dem Stand mit diesem Schluessel stammte.
 */
function sicherung_ohne_verworfen(array $namen, array $r)
{
    $verworfen = isset($r['verworfen']) && is_array($r['verworfen']) ? $r['verworfen'] : array();
    $aus = array();
    foreach ($namen as $n) { if (!in_array($n, $verworfen, true)) { $aus[] = $n; } }
    return $aus;
}

/**
 * 1.2.5: eine Datei mit Huelle $hn auspacken (Regel 14 im Kopf). Rueckgabe: die Datei mit dem Inhalt der Huelle an
 * der obersten Ebene und dem Kopf (_-Schluessel) davor - oder null, dann ist $r beanstandet ('fremd' = 'form') und
 * die ganze Datei abgewiesen. Die Meldung nennt den Grund, den Namen der Huelle und Namen aus der Datei, nie Werte.
 */
function sicherung_huelle_auspacken(array $b, array $daten, $hn, array &$r)
{
    $kopf = array();
    $andere = array();
    $neben = sicherung_neben_huelle($b);       // 1.2.6: Teile, die neben der Huelle stehen duerfen
    $daneben = array();
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k === $hn) { continue; }
        if ($k !== '' && $k[0] === '_') { $kopf[$k] = $w; continue; }
        if (in_array($k, $neben, true)) { $daneben[$k] = $w; continue; }
        $andere[] = sicherung_name($k);
    }
    $inhalt = $daten[$hn];
    $name = sicherung_e(sicherung_name($hn), $b);
    $texte = array();       // alle Gruende gesammelt, nicht nur der erste
    if ($andere) {
        // neben der Huelle oben noch eine Einstellung: welche gilt, ist nicht zu entscheiden
        $texte[] = sprintf(sicherung_t('K_HUELLE_GEMISCHT', $b), $name,
                           sicherung_e(implode(', ', array_slice(sicherung_einmal($andere), 0, 20)), $b));
    }
    if (!is_array($inhalt)) {
        $texte[] = sprintf(sicherung_t('K_HUELLE_KEIN_OBJEKT', $b), $name);
    } elseif ($inhalt === array()) {
        $texte[] = sprintf(sicherung_t('K_HUELLE_LEER', $b), $name);
    } elseif (array_keys($inhalt) === range(0, count($inhalt) - 1)) {
        $texte[] = sprintf(sicherung_t('K_HUELLE_KEIN_OBJEKT', $b), $name);     // eine Liste, keine Einstellungen mit Namen
    } else {
        $doppelt = array();
        foreach (array_keys($inhalt) as $k) {
            if (array_key_exists($k, $kopf)) { $doppelt[] = sicherung_name($k); }
        }
        if ($doppelt) {
            $texte[] = sprintf(sicherung_t('K_HUELLE_KOPF', $b), $name, sicherung_e(implode(', ', $doppelt), $b));
        }
        // 1.2.6: ein Teil neben der Huelle und zugleich darin - welcher gilt, ist nicht zu entscheiden
        $beide = array();
        foreach (array_keys($inhalt) as $k) {
            if (array_key_exists((string) $k, $daneben)) { $beide[] = sicherung_name($k); }
        }
        if ($beide) {
            $texte[] = sprintf(sicherung_t('K_HUELLE_DOPPELT', $b), $name, sicherung_e(implode(', ', $beide), $b));
        }
    }
    if ($texte) {
        $r['fremd'] = 'form';
        sicherung_beanstanden($r, sicherung_name($hn), $texte);
        return null;
    }
    return $kopf + $daneben + $inhalt;
}

/**
 * Eine Sicherungsdatei lesen und pruefen, ohne etwas zu schreiben.
 * $jetzt: die geltende Konfiguration (fuer Regel 3 und 'behalten'); ohne
 * Angabe $b['jetzt'](). Rueckgabe: das Ergebnis $r (siehe Kopf).
 */
function sicherung_lesen(array $b, $roh, $jetzt = null)
{
    $r = sicherung_ergebnis();
    if ($jetzt === null && isset($b['jetzt']) && is_callable($b['jetzt'])) { $jetzt = call_user_func($b['jetzt']); }
    if (!is_array($jetzt)) { $jetzt = array(); }
    $bm = sicherung_beschreibung_mangel($b);
    if ($bm) {
        $r['fremd'] = 'form';
        sicherung_beanstanden($r, '-', sprintf(sicherung_t('K_BESCHREIBUNG', $b), implode(', ', $bm)));
        return $r;
    }
    $max = isset($b['max_byte']) && is_int($b['max_byte']) && $b['max_byte'] > 0 ? $b['max_byte'] : SICHERUNG_MAX_BYTE;
    if (!is_string($roh) || strlen($roh) > $max) {
        $r['fremd'] = 'gross';
        sicherung_beanstanden($r, '-', sprintf(sicherung_t('K_ZU_GROSS', $b), (int) ($max / 1024)));
        return $r;
    }
    // 1.2.3: eine UTF-8-BOM vor dem Lesen entfernen (Editoren unter Windows schreiben sie gern), fuer alle Linien gleich.
    if (substr($roh, 0, 3) === "\xEF\xBB\xBF") { $roh = substr($roh, 3); }
    $daten = json_decode($roh, true);
    if (!is_array($daten) || ($daten !== array() && array_keys($daten) === range(0, count($daten) - 1))) {
        $r['fremd'] = 'form';
        sicherung_beanstanden($r, '-', sicherung_t('K_KEIN_JSON', $b));
        return $r;
    }

    /* ---- Regel 1: das Kennzeichen ---- */
    $frueher = isset($b['kennzeichen_frueher']) && is_array($b['kennzeichen_frueher']) ? $b['kennzeichen_frueher'] : array();
    if (array_key_exists('_plugin', $daten)) {
        $kz = $daten['_plugin'];
        $r['kennzeichen_datei'] = sicherung_namensform($kz) ? $kz : '?';
        $muster = isset($b['kennzeichen_frueher_muster']) && is_string($b['kennzeichen_frueher_muster'])
            ? $b['kennzeichen_frueher_muster'] : '';
        if ($kz === $b['kennzeichen']) {
            $r['eigen'] = 'kennzeichen';
        } elseif (is_string($kz) && in_array($kz, $frueher, true)) {
            $r['eigen'] = 'frueher';
        } elseif (is_string($kz) && $muster !== '' && strlen($kz) <= 200 && preg_match($muster, $kz) === 1) {
            $r['eigen'] = 'frueher';     // 1.2.0: ein frueheres Kennzeichen nach Muster (Teil der Anlage darin)
        } else {
            $r['fremd'] = 'kennzeichen';
            sicherung_beanstanden($r, '_plugin', $r['kennzeichen_datei'] !== '?'
                ? sprintf(sicherung_t('K_FREMD', $b), sicherung_e($r['kennzeichen_datei'], $b))
                : sicherung_t('K_FREMD_OHNE_NAME', $b));
            return $r;
        }
    } else {
        $ue = (isset($b['uebersetzer']) && is_callable($b['uebersetzer'])) ? call_user_func($b['uebersetzer'], $daten) : null;
        if (!is_array($ue)) {
            $r['fremd'] = 'ohne';
            sicherung_beanstanden($r, '_plugin', sicherung_t('K_OHNE_KENNZEICHEN', $b));
            return $r;
        }
        $daten = $ue;
        $r['eigen'] = 'uebersetzt';
    }
    if (isset($daten['_fassung']) && sicherung_namensform($daten['_fassung'])) { $r['fassung_datei'] = $daten['_fassung']; }
    /* 1.2.5: eine eigene Datei mit Huelle ('huelle') auspacken - nach Regel 1, vor allen anderen Regeln; danach ist sie
     * eine flache Datei. Eine Datei ohne den Schluessel bleibt, wie sie ist. */
    $hn = sicherung_huelle($b);
    if ($hn !== '' && array_key_exists($hn, $daten)) {
        $daten = sicherung_huelle_auspacken($b, $daten, $hn, $r);
        if ($daten === null) { return $r; }
    }

    $vorgaben = $b['vorgaben'];
    $alt = isset($b['alt']) && is_array($b['alt']) ? $b['alt'] : array();
    $nie = isset($b['nie_aus_datei']) && is_array($b['nie_aus_datei']) ? $b['nie_aus_datei'] : array();
    $nie_pfade = sicherung_nie_pfade($b);       // 1.2.7: Vermerke in Bloecken (Regel 17 (b) im Kopf)
    $teile = isset($b['teile']) && is_array($b['teile']) ? $b['teile'] : array();

    /* 1.2.0: Namen frueherer Fassungen umbenennen (vor allen Regeln, nur wenn der heutige fehlt) und flache Teile
     * (<praefix><feld>) zu ihrem Teil sammeln. */
    if (isset($b['umbenennen']) && is_array($b['umbenennen'])) {
        $um = array();
        foreach ($daten as $k => $w) {
            $k = (string) $k;
            $n = isset($b['umbenennen'][$k]) && is_string($b['umbenennen'][$k]) ? $b['umbenennen'][$k] : $k;
            if ($n !== $k && array_key_exists($n, $daten)) { $n = $k; }
            $um[$n] = $w;
        }
        $daten = $um;
    }
    $flach = isset($b['teile_flach']) && is_array($b['teile_flach']) ? $b['teile_flach'] : array();
    foreach ($flach as $tn => $px) {
        $px = (string) $px;
        if ($px === '' || array_key_exists($tn, $daten)) { continue; }
        $sammel = array();
        foreach ($daten as $k => $w) {
            $k = (string) $k;
            if (strpos($k, $px) === 0 && strlen($k) > strlen($px)) { $sammel[substr($k, strlen($px))] = $w; unset($daten[$k]); }
        }
        if ($sammel) { $daten[$tn] = $sammel; }
    }

    /* ---- Regel 2 und 3: Unbekanntes verwerfen, Leeres stehen lassen ---- */
    $bereit = array();
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }               // der lesbare Kopf
        if (in_array($k, $alt, true) || in_array($k, $nie, true)) { continue; }
        if (isset($teile[$k])) {
            if (!is_callable($teile[$k])) { continue; }
            /* Ein Teil mit Knoten in 'schluessel' durchlaeuft Regel 2 und 3 wie ein Block; der geltende
             * Stand des Teils kommt aus 'teile_jetzt' (Wert oder function () -> Wert). */
            if (isset($b['schluessel'][$k]) && is_array($b['schluessel'][$k])) {
                $tj = isset($b['teile_jetzt'][$k]) ? $b['teile_jetzt'][$k] : null;
                if (is_callable($tj)) { $tj = call_user_func($tj); }
                $w = isset($flach[$k])      // 1.2.0: Namen eines flachen Teils wie in der Datei (zugang_benutzer)
                    ? sicherung_knoten_reinigen($b['schluessel'][$k], $w, $tj, '', $r, (string) $flach[$k])
                    : sicherung_knoten_reinigen($b['schluessel'][$k], $w, $tj, $k, $r);
            }
            $tw = call_user_func($teile[$k], $w, $jetzt);
            $tw = is_array($tw) ? array_pad($tw, 3, array()) : array(null, array(), array());
            if ($tw[1]) {
                sicherung_beanstanden($r, $k, $tw[1]);
            } else {
                $r['teile'][$k] = $tw[0];
                $r['anzahl']++;
                foreach ((array) $tw[2] as $th) { if (is_string($th) && $th !== '') { $r['teile_hinweise'][] = $th; } }
            }
            continue;
        }
        if (!array_key_exists($k, $vorgaben)) {
            $r['verworfen'][] = sicherung_name($k);
            continue;
        }
        $j = array_key_exists($k, $jetzt) ? $jetzt[$k] : null;
        if (sicherung_leer_bleibt($w, $j)) {
            $r['leer_behalten'][] = $k;
            $bereit[$k] = $j;
            continue;
        }
        $knoten_k = sicherung_knoten($b, $k);
        if (isset($nie_pfade[$k]) && is_array($w)) {
            /* 1.2.7: ein Vermerk im Block kommt nie aus der Datei - still uebergangen wie oben, also weder verworfen
             * (Regel 2) noch als fehlend behalten genannt; sein Wert kommt unten aus der geltenden Konfiguration. */
            foreach ($nie_pfade[$k] as $p) { $w = sicherung_pfad_ohne($w, $p); }
            $knoten_k = sicherung_knoten_ohne($knoten_k, $nie_pfade[$k]);
        }
        $bereit[$k] = sicherung_knoten_reinigen($knoten_k, $w, $j, $k, $r);
    }
    if (isset($b['vorbehandeln']) && is_callable($b['vorbehandeln'])) {
        $vb = call_user_func($b['vorbehandeln'], $bereit, $jetzt, $daten);
        if (is_array($vb)) { $bereit = $vb; }
    }
    /* 1.2.7: Vermerke in Bloecken - steht einer jetzt im Block, hat 'vorbehandeln' ihn gesetzt (aus der Datei kam er
     * oben nicht), und dieser Wert gilt. Sonst bekommt der Block vor der Pruefung den Wert, der danach gilt (der
     * geltende, sonst die Vorgabe), damit die Pruefung den ganzen Block sieht. */
    $nie_linie = array();
    foreach ($nie_pfade as $k => $pfade) {
        foreach ($pfade as $p) {
            if (!isset($bereit[$k]) || !is_array($bereit[$k])) { continue; }
            $h = sicherung_pfad_holen($bereit[$k], $p);
            if ($h[0]) {
                $nie_linie[$k . '.' . implode('.', $p)] = true;
                continue;
            }
            $nw = sicherung_nie_wert($vorgaben, $jetzt, $k, $p);
            if ($nw[0]) { $bereit[$k] = sicherung_pfad_mit($bereit[$k], $p, $nw[1]); }
        }
    }

    /* ---- Regel 4: jeden Wert pruefen, alles sammeln ---- */
    $neu = $vorgaben;
    /* 1.2.1: ein Vermerk ('nie_aus_datei') behaelt seinen geltenden Wert; aus der Datei kommt er nie (Regel 2 oben
     * uebergeht ihn). Steht er nicht in $jetzt, bleibt es wie bis 1.2.0. Was 'vorbehandeln' setzt, gilt danach. */
    foreach ($nie as $k) {
        if (is_string($k) && array_key_exists($k, $jetzt)) { $neu[$k] = $jetzt[$k]; }
    }
    foreach ($bereit as $k => $w) {
        if (!array_key_exists($k, $vorgaben)) { continue; }
        if (in_array($k, $r['leer_behalten'], true)) { $neu[$k] = $w; $r['anzahl']++; continue; }
        $knoten = sicherung_knoten($b, $k);
        $grund = '';
        $roh = array_key_exists($k, $daten) ? $daten[$k] : null;     // 1.2.0: wie in der Datei, vor Regel 2 und 3
        if (isset($knoten['pruefen']) && is_callable($knoten['pruefen'])) {
            $grund = call_user_func($knoten['pruefen'], $w, $k, $neu, $roh);
        } elseif (isset($b['pruefen']) && is_callable($b['pruefen'])) {
            $grund = call_user_func($b['pruefen'], $k, $w, $neu, $roh);
        }
        if ($grund !== '' && $grund !== array() && $grund !== null) {
            sicherung_beanstanden($r, $k, $grund);
            continue;
        }
        if (isset($knoten['wandeln']) && is_callable($knoten['wandeln'])) { $w = call_user_func($knoten['wandeln'], $w); }
        $neu[$k] = $w;
        $r['anzahl']++;
    }

    /* ---- Fehlende Schluessel ---- */
    $fehlend = array();
    foreach (array_keys($vorgaben) as $k) {
        if (array_key_exists($k, $daten)) {
            /* 1.2.8: traegt die Datei den Block, muss sie jeden Unterschluessel mit 'fehlt' => 'pflicht' tragen
             * (Regel 18 im Kopf); setzt 'vorbehandeln' ihn, ist es ein Uebergang. */
            sicherung_pflicht_fehlend(sicherung_knoten($b, $k), $daten[$k], array_key_exists($k, $bereit) ? $bereit[$k] : null,
                                      $k, $fehlend, $r['fehlt_uebergang']);
        }
        if (array_key_exists($k, $daten) || in_array($k, $nie, true)) { continue; }
        $knoten = sicherung_knoten($b, $k);
        $f = isset($knoten['fehlt']) ? $knoten['fehlt'] : 'pflicht';
        if ($f === 'vorgabe') {
            $r['fehlt_vorgabe'][] = $k;
        } elseif ($f === 'behalten') {
            /* 1.2.2: der Schluessel fehlt in der Datei; steht er trotzdem in $bereit, hat ihn 'vorbehandeln'
             * gesetzt (vor 'vorbehandeln' traegt $bereit nur Schluessel aus der Datei). Dieser Wert ging oben
             * durch Regel 4 und bleibt; genannt wird er als solcher, nicht als behalten. */
            if (array_key_exists($k, $bereit)) {
                $r['fehlt_uebergang'][] = $k;
                continue;
            }
            if (array_key_exists($k, $jetzt)) { $neu[$k] = $jetzt[$k]; }
            $r['fehlt_behalten'][] = $k;
        } else {
            /* 1.2.9: auch ein Pflichtschluessel oben (ab Werk, ausdruecklich oder unbekannte Angabe), den
             * 'vorbehandeln' gesetzt hat, ist ein Uebergang wie bei 'behalten' (1.2.2) und im Block (1.2.8): der
             * Wert ging durch Regel 4 und bleibt (Regel 19 im Kopf); bis 1.2.8 wurde die Datei abgewiesen. */
            if (array_key_exists($k, $bereit)) {
                $r['fehlt_uebergang'][] = $k;
                continue;
            }
            $fehlend[] = $k;
        }
    }
    if ($fehlend) {
        sicherung_beanstanden($r, implode(',', $fehlend),
            sprintf(sicherung_t('K_FEHLEND', $b), count($fehlend), sicherung_e(implode(', ', $fehlend), $b)));
    }
    // 1.2.0: ein flacher Teil, von dem die Datei kein Feld traegt - seine Felder mit 'fehlt' => 'behalten' werden
    // genannt wie in einer Fassung, die den Teil noch nicht kannte (der Teil selbst bleibt dann unberuehrt).
    foreach ($flach as $tn => $px) {
        if (array_key_exists($tn, $daten) || !isset($b['schluessel'][$tn]['schluessel'])
            || !is_array($b['schluessel'][$tn]['schluessel'])) { continue; }
        foreach ($b['schluessel'][$tn]['schluessel'] as $sk => $sknoten) {
            if (is_array($sknoten) && isset($sknoten['fehlt']) && $sknoten['fehlt'] === 'behalten') {
                $r['fehlt_behalten'][] = (string) $px . sicherung_name($sk);
            }
        }
    }
    /* 1.2.7: Vermerke in Bloecken nach allen Regeln - gleich, woher der Block kam (Datei, Vorgabe, geltender Stand):
     * der geltende Wert, sonst die Vorgabe des Blocks (aus der Datei kam er oben nie); nur ein Wert aus 'vorbehandeln'
     * bleibt. */
    foreach ($nie_pfade as $k => $pfade) {
        foreach ($pfade as $p) {
            if (isset($nie_linie[$k . '.' . implode('.', $p)]) || !isset($neu[$k]) || !is_array($neu[$k])) { continue; }
            $nw = sicherung_nie_wert($vorgaben, $jetzt, $k, $p);
            if ($nw[0]) { $neu[$k] = sicherung_pfad_mit($neu[$k], $p, $nw[1]); }
        }
    }
    if (isset($b['quer']) && is_callable($b['quer'])) {
        foreach ((array) call_user_func($b['quer'], $neu, $r, $daten) as $q) {
            if (is_array($q) && count($q) >= 2) { sicherung_beanstanden($r, (string) $q[0], (string) $q[1]); }
        }
    }
    if ($r['anzahl'] === 0 && !$r['beanstandet']) {
        sicherung_beanstanden($r, '-', sicherung_t('K_LEER', $b));
    }
    $r['verworfen'] = sicherung_einmal($r['verworfen']);
    $r['leer_behalten'] = sicherung_einmal($r['leer_behalten']);
    $r['ok'] = !$r['beanstandet'];
    $r['neu'] = $r['ok'] ? $neu : null;
    if (!$r['ok']) { $r['teile'] = array(); $r['teile_hinweise'] = array(); }
    // 1.2.4: was aus der geltenden Konfiguration wegfaellt, weil die Beschreibung es nicht kennt (nur Namen)
    $r['jetzt_entfernt'] = $r['ok'] ? sicherung_ohne_verworfen(sicherung_jetzt_entfernt($b, $jetzt, $neu), $r) : array();
    return $r;
}

/* ==================================================================
 * Zurueckspielen
 * ================================================================== */

/**
 * Lesen, pruefen, (unter der Sperre der Linie) speichern, Zweitschrift
 * nachmessen, Dienst nachziehen. Rueckgabe: das Ergebnis $r von
 * sicherung_lesen() und dazu 'gespeichert' (bool), 'zweitschrift'
 * ('' = nicht gefragt, 'mit', 'abweichend', 'fehlt') und 'dienst' (Liste der
 * Meldungen aus 'nachziehen'). Eine halb gueltige Datei aendert nichts.
 */
function sicherung_zurueckspielen(array $b, $roh)
{
    $griff = (isset($b['sperre']) && is_callable($b['sperre'])) ? call_user_func($b['sperre']) : null;
    $jetzt = (isset($b['jetzt']) && is_callable($b['jetzt'])) ? call_user_func($b['jetzt']) : array();
    if (!is_array($jetzt)) { $jetzt = array(); }
    $r = sicherung_lesen($b, $roh, $jetzt);
    $r['gespeichert'] = false;
    $r['zweitschrift'] = '';
    $r['dienst'] = array();
    $r['vorbedingung'] = array();
    if ($r['ok']) {
        $neu = $r['neu'];
        if (isset($b['vorbereiten']) && is_callable($b['vorbereiten'])) {
            $vb = call_user_func($b['vorbereiten'], $neu, $jetzt, $r);
            if (is_array($vb)) { $neu = $vb; }
        }
        $r['neu'] = $neu;
        // 1.2.4: gegen den Stand nach 'vorbereiten' - was die Linie dort aus der geltenden Konfiguration behaelt, faellt nicht weg
        $r['jetzt_entfernt'] = sicherung_ohne_verworfen(sicherung_jetzt_entfernt($b, $jetzt, $neu), $r);
        if (isset($b['vorbedingung']) && is_callable($b['vorbedingung'])) {
            // 1.2.0: was vor dem Speichern erfuellt sein muss; nicht erfuellt heisst nicht gespeichert, mit diesen Saetzen
            foreach ((array) call_user_func($b['vorbedingung'], $neu, $jetzt, $r) as $s) {
                if (is_string($s) && $s !== '') { $r['vorbedingung'][] = $s; }
            }
        }
        $r['gespeichert'] = (!$r['vorbedingung'] && isset($b['speichern']) && is_callable($b['speichern']))
            ? (bool) call_user_func($b['speichern'], $neu, $jetzt, $r) : false;
        if (!$r['gespeichert']) { $r['jetzt_entfernt'] = array(); }     // 1.2.4: nicht gespeichert, nichts entfernt
        if ($r['gespeichert'] && isset($b['zweitschrift']) && is_callable($b['zweitschrift'])) {
            // 1.2.7: eine Liste von Paaren wird je Paar beurteilt; zusammengefasst zaehlt der schlechteste Punkt
            $zp = call_user_func($b['zweitschrift']);
            $lagen = sicherung_zweitschrift_lagen($zp);
            $r['zweitschrift'] = sicherung_zweitschrift_schlechteste($lagen);
            if (sicherung_zweitschrift_ist_liste($zp)) { $r['zweitschrift_paare'] = $lagen; }
        }
    }
    if ($griff !== null && isset($b['freigeben']) && is_callable($b['freigeben'])) { call_user_func($b['freigeben'], $griff); }
    if ($r['gespeichert'] && isset($b['nachziehen']) && is_callable($b['nachziehen'])) {
        $r['dienst'] = array_values(array_filter((array) call_user_func($b['nachziehen'], $r['neu'], $jetzt, $r), 'is_string'));
    }
    if (isset($b['log']) && is_callable($b['log'])) { call_user_func($b['log'], sicherung_protokollzeile($r)); }
    return $r;
}

/**
 * Steht die Zweitschrift inhaltsgleich neben der Konfiguration? $p = array(Datei, Zweitschrift); seit 1.2.7 auch eine
 * Liste solcher Paare - dann der schlechteste Punkt aller Paare (Regel 17 (a) im Kopf).
 */
function sicherung_zweitschrift_lage($p)
{
    return sicherung_zweitschrift_schlechteste(sicherung_zweitschrift_lagen($p));
}

/** 1.2.7: ist $p eine Liste von Paaren (nicht leer, jedes Element ein Feld)? Sonst ist es ein Paar wie bis 1.2.6. */
function sicherung_zweitschrift_ist_liste($p)
{
    if (!is_array($p) || $p === array()) { return false; }
    foreach ($p as $x) { if (!is_array($x)) { return false; } }
    return true;
}

/**
 * 1.2.7: die Lage je Paar - fuer ein einzelnes Paar eine Liste mit einem Eintrag. $paar ist die Beurteilung eines
 * Paars (in der Linie immer sicherung_zweitschrift_paar; der Selbsttest gibt eine eigene, ohne Dateien).
 */
function sicherung_zweitschrift_lagen($p, $paar = 'sicherung_zweitschrift_paar')
{
    $aus = array();
    foreach ((sicherung_zweitschrift_ist_liste($p) ? $p : array($p)) as $x) {
        $aus[] = is_callable($paar) ? call_user_func($paar, $x) : 'fehlt';
    }
    return $aus;
}

/**
 * 1.2.7: die Zusammenfassung darf nicht besser aussehen als ihr schlechtester Punkt: 'fehlt' vor 'abweichend' vor 'mit'.
 * Eine leere Liste und jede unbekannte Lage heissen 'fehlt' (geschlossen ausfallen).
 */
function sicherung_zweitschrift_schlechteste(array $lagen)
{
    if (!$lagen) { return 'fehlt'; }
    $abweichend = false;
    foreach ($lagen as $l) {
        if ($l === 'abweichend') { $abweichend = true; continue; }
        if ($l !== 'mit') { return 'fehlt'; }
    }
    return $abweichend ? 'abweichend' : 'mit';
}

/** Ein Paar: steht die Zweitschrift inhaltsgleich neben der Konfiguration? $p = array(Datei, Zweitschrift). Bis 1.2.6
 * der Rumpf von sicherung_zweitschrift_lage(), unveraendert. */
function sicherung_zweitschrift_paar($p)
{
    if (!is_array($p) || count($p) < 2 || !is_string($p[0]) || !is_string($p[1])) { return 'fehlt'; }
    if (!is_file($p[1])) { return 'fehlt'; }
    $a = @file_get_contents($p[0]);
    $z = @file_get_contents($p[1]);
    if (!is_string($a) || !is_string($z)) { return 'fehlt'; }
    if ($a === $z) { return 'mit'; }
    $ja = json_decode($a, true);
    $jz = json_decode($z, true);
    return (is_array($ja) && $ja === $jz) ? 'mit' : 'abweichend';
}

/** Eine Protokollzeile: Zahlen und Namen, nie Werte. */
function sicherung_protokollzeile(array $r)
{
    if (!$r['ok']) {
        $n = array();
        foreach ($r['beanstandet'] as $x) { $n[] = sicherung_name($x['name']); }
        return 'Sicherung abgewiesen (' . ($r['fremd'] !== '' ? $r['fremd'] : 'Werte') . '): '
             . implode(', ', array_slice(sicherung_einmal($n), 0, 20));
    }
    $z = 'Sicherung zurueckgespielt: ' . (int) $r['anzahl'] . ' Werte'
       . ($r['eigen'] === 'uebersetzt' ? ', aeltere Sicherung ohne Kennzeichen' : '')
       . ($r['fassung_datei'] !== '' ? ', aus Fassung ' . $r['fassung_datei'] : '');
    foreach (array('verworfen' => 'verworfen',
                   'jetzt_entfernt' => 'unbekannt, aus der geltenden Konfiguration entfernt',
                   'leer_behalten' => 'leer, bisheriger Wert bleibt',
                   'fehlt_behalten' => 'fehlt, bisheriger Wert bleibt',
                   'fehlt_uebergang' => 'fehlt, Wert aus einer Uebergangsregel der Linie') as $f => $t) {
        if (!empty($r[$f])) { $z .= '; ' . $t . ': ' . implode(', ', array_slice($r[$f], 0, 20)); }
    }
    if (isset($r['gespeichert']) && !$r['gespeichert']) {
        $z .= '; NICHT gespeichert' . (!empty($r['vorbedingung']) ? ' (Vorbedingung nicht erfuellt)' : '');
    }
    if (isset($r['zweitschrift']) && $r['zweitschrift'] !== '' && $r['zweitschrift'] !== 'mit') {
        $z .= '; Zweitschrift ' . $r['zweitschrift'];
        if (isset($r['zweitschrift_paare']) && is_array($r['zweitschrift_paare'])) {
            // 1.2.7: mehrere Paare - wie viele nicht inhaltsgleich sind (Zahlen, keine Pfade)
            $nicht = 0;
            foreach ($r['zweitschrift_paare'] as $l) { if ($l !== 'mit') { $nicht++; } }
            $z .= ' (' . $nicht . ' von ' . count($r['zweitschrift_paare']) . ' Paaren)';
        }
    }
    return (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $z);
}

/**
 * Die Meldungen zu einem Ergebnis. Rueckgabe: array('fehler' => Texte,
 * 'hinweise' => Texte), fertig maskiert. Beanstandungen der Linie kommen mit
 * ihrem eigenen Text (die Linie hat ihn maskiert); Namen gehen durch 'e'.
 */
function sicherung_meldungen(array $b, array $r)
{
    $f = array();
    $h = array();
    if (!$r['ok']) {
        $t = array();
        foreach ($r['beanstandet'] as $x) {
            $t[] = $x['text'] !== '' ? $x['text'] : sprintf(sicherung_t('K_WERT', $b), sicherung_e($x['name'], $b));
        }
        $f[] = sicherung_t('K_ABGELEHNT', $b) . ' ' . implode(' ', sicherung_einmal($t));
        return array('fehler' => $f, 'hinweise' => $h);
    }
    if (isset($r['gespeichert']) && !$r['gespeichert']) {
        if (!empty($r['vorbedingung']) && is_array($r['vorbedingung'])) {
            // 1.2.0: die Saetze der Vorbedingung sagen, warum nicht gespeichert wurde - kein zweiter Satz dazu
            foreach ($r['vorbedingung'] as $s) { $f[] = (string) $s; }
        } else {
            $f[] = sicherung_t('K_SCHREIBFEHLER', $b);
        }
        return array('fehler' => $f, 'hinweise' => $h);
    }
    $h[] = sprintf(sicherung_t('K_UEBERNOMMEN', $b), (int) $r['anzahl']);
    if ($r['eigen'] === 'uebersetzt') { $h[] = sicherung_t('K_ALT_ERKANNT', $b); }
    if (!empty($r['teile_hinweise']) && is_array($r['teile_hinweise'])) {
        foreach ($r['teile_hinweise'] as $s) { $h[] = (string) $s; }     // 1.2.0: Hinweise der Teile, fertige Saetze
    }
    $listen = array('verworfen' => 'K_VERWORFEN', 'jetzt_entfernt' => 'K_JETZT_ENTFERNT',
                    'leer_behalten' => 'K_LEER_BEHALTEN', 'fehlt_behalten' => 'K_FEHLT_BEHALTEN',
                    'fehlt_uebergang' => 'K_FEHLT_UEBERGANG', 'fehlt_vorgabe' => 'K_FEHLT_VORGABE');
    foreach ($listen as $feld => $id) {
        if (!empty($r[$feld])) { $h[] = sprintf(sicherung_t($id, $b), sicherung_e(implode(', ', $r[$feld]), $b)); }
    }
    if (isset($r['zweitschrift']) && ($r['zweitschrift'] === 'abweichend' || $r['zweitschrift'] === 'fehlt')) {
        $f[] = sicherung_t('K_ZWEITSCHRIFT', $b);
    }
    if (isset($r['dienst']) && is_array($r['dienst'])) {
        foreach ($r['dienst'] as $d) { $h[] = $d; }
    }
    return array('fehler' => $f, 'hinweise' => $h);
}

/* ==================================================================
 * Der Knopf "Einstellungen zurueckspielen": Haken und Datei (seit 1.1.0)
 * ================================================================== */

/**
 * Die Formularzeile mit dem Haken, fertig maskiert. Sie steht in jeder Linie im
 * Formular des Knopfes "Einstellungen zurueckspielen", unmittelbar vor dem Knopf.
 * Ab Werk ist der Haken aus.
 */
function sicherung_haken_zeile(array $b)
{
    return '<div class="sm-feld"><label><input data-role="none" type="checkbox" name="' . SICHERUNG_HAKEN
         . '" value="1"> ' . sicherung_e(sicherung_t('K_HAKEN', $b), $b) . '</label></div>';
}

/**
 * Der Eingang des Knopfes: ist der Haken gesetzt, kam eine Datei ueber das Formular
 * (is_uploaded_file()) und ist sie nicht groesser als 'max_byte'? Erst dann wird
 * sie gelesen. $post = $_POST, $files = $_FILES, $feld = Name des Dateifelds.
 * Rueckgabe array(Text der Datei, null) - dann ruft die Linie
 * sicherung_zurueckspielen() mit dem Text - oder array(null, Ergebnis) mit
 * 'abbruch' (siehe Kopf); die Meldung dazu gibt sicherung_meldungen(). Ohne
 * Haken wird die Datei nicht angesehen. Schreibt nichts.
 */
function sicherung_eingang(array $b, array $post, array $files, $feld)
{
    $datei = (is_string($feld) && isset($files[$feld]) && is_array($files[$feld])) ? $files[$feld] : null;
    return sicherung_eingang_lage($b, $post, $datei, 'is_uploaded_file');
}

/**
 * Wie sicherung_eingang() fuer eine Datei-Angabe $datei (ein Eintrag von $_FILES
 * oder null); $hochgeladen ist die Pruefung "kam ueber das Formular", in der
 * Linie immer is_uploaded_file (der Selbsttest gibt eine eigene).
 */
function sicherung_eingang_lage(array $b, array $post, $datei, $hochgeladen)
{
    $max = isset($b['max_byte']) && is_int($b['max_byte']) && $b['max_byte'] > 0 ? $b['max_byte'] : SICHERUNG_MAX_BYTE;
    $abbruch = function ($art, $text) {
        $r = sicherung_ergebnis();
        $r['abbruch'] = $art;
        $r['fremd'] = $art === 'gross' ? 'gross' : '';
        $r['gespeichert'] = false;
        $r['zweitschrift'] = '';
        $r['dienst'] = array();
        sicherung_beanstanden($r, '-', $text);
        return array(null, $r);
    };
    if (!isset($post[SICHERUNG_HAKEN]) || $post[SICHERUNG_HAKEN] !== '1') {
        return $abbruch('haken', sicherung_t('K_HAKEN_FEHLT', $b));
    }
    $gross = sprintf(sicherung_t('K_ZU_GROSS', $b), (int) ($max / 1024));
    if (is_array($datei) && isset($datei['error'])
        && in_array((int) $datei['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
        return $abbruch('gross', $gross);
    }
    if (!is_array($datei) || !isset($datei['tmp_name']) || !is_string($datei['tmp_name']) || $datei['tmp_name'] === ''
        || !is_callable($hochgeladen) || !call_user_func($hochgeladen, $datei['tmp_name'])) {
        return $abbruch('datei', sicherung_t('K_KEINE_DATEI', $b));
    }
    if (!isset($datei['size']) || (int) $datei['size'] > $max) {
        return $abbruch('gross', $gross);
    }
    $roh = @file_get_contents($datei['tmp_name'], false, null, 0, $max + 1);
    if (!is_string($roh)) {
        return $abbruch('datei', sicherung_t('K_KEINE_DATEI', $b));
    }
    if (strlen($roh) > $max) {
        return $abbruch('gross', $gross);
    }
    return array($roh, null);
}

/* ==================================================================
 * Texte
 * ================================================================== */

/**
 * Alle Kennungen, zu denen ein Satz im Abschnitt [SICHERUNG] der Sprachdateien
 * stehen soll. Werkzeuge/gemeinsam/sicherung_texte.json traegt genau diese
 * Schluessel in de und en; sicherung_selbsttest() und
 * Werkzeuge/sicherung_einbauen.py halten beides gegeneinander.
 */
function sicherung_sprachschluessel()
{
    return array('K_HINWEIS', 'K_WARNUNG', 'K_BESCHREIBUNG', 'K_ZU_GROSS', 'K_KEIN_JSON', 'K_FREMD',
                 'K_FREMD_OHNE_NAME', 'K_OHNE_KENNZEICHEN', 'K_FEHLEND', 'K_LEER', 'K_WERT', 'K_ABGELEHNT',
                 'K_SCHREIBFEHLER', 'K_UEBERNOMMEN', 'K_ALT_ERKANNT', 'K_VERWORFEN', 'K_LEER_BEHALTEN',
                 'K_FEHLT_BEHALTEN', 'K_ZWEITSCHRIFT', 'K_HAKEN', 'K_HAKEN_FEHLT', 'K_KEINE_DATEI', 'K_FEHLT_VORGABE',
                 'K_FEHLT_UEBERGANG', 'K_JETZT_ENTFERNT', 'K_HUELLE_GEMISCHT', 'K_HUELLE_KEIN_OBJEKT', 'K_HUELLE_LEER',
                 'K_HUELLE_KOPF', 'K_HUELLE_DOPPELT');
}

/* ==================================================================
 * Selbsttest
 * ================================================================== */

/**
 * Rechnet die Regeln an einer erfundenen Linie nach, ohne Dateien und ohne Netz.
 * Rueckgabe: array(Zahl der Faelle, Fehlschlaege, Zeilen).
 */
function sicherung_selbsttest()
{
    $faelle = 0;
    $fehl = 0;
    $zeilen = array();
    $pruef = function ($name, $ist, $soll) use (&$faelle, &$fehl, &$zeilen) {
        $faelle++;
        if ($ist !== $soll) {
            $fehl++;
            $zeilen[] = 'FEHL ' . $name . ': ist ' . var_export($ist, true) . ', soll ' . var_export($soll, true);
        }
    };
    $TOK = 'pruefzeichen0123456789ab';
    $GEHEIM = 'Kennwort-Selbsttest-77';
    $gespeichert = array();
    $b = array(
        'kennzeichen' => 'LoxBerry-Plugin-Selbsttest',
        'kennzeichen_frueher' => array('selbsttest'),
        'fassung' => '9.8.7',
        'vorgaben' => array('aktionstoken' => '', 'thema' => 'st', 'takt' => 60, 'ein' => 0, 'spaet' => 1,
                            'werk' => 5, 'tts' => array('mode' => 'aus', 'ip' => '', 'alexa_token' => ''),
                            'liste' => array(), 'frei' => array()),
        'schluessel' => array(
            'tts' => sicherung_block(array('mode', 'ip', 'alexa_token'), array()) + array(
                'sichern' => function ($w) { if (is_array($w)) { unset($w['alexa_token']); } return $w; }),
            'liste' => sicherung_liste(array('name', 'wert', 'kennwort'), 'name'),
            'frei' => sicherung_offen(),
            'zugang' => sicherung_block(array('kennwort')),
            'spaet' => array('fehlt' => 'behalten'),
            'werk' => array('fehlt' => 'vorgabe'),
        ),
        'alt' => array('altschluessel'),
        'nie_aus_datei' => array('vermerk'),
        'teile' => array('zugang' => function ($w) {
            if (!is_array($w) || !isset($w['kennwort']) || !is_string($w['kennwort'])) { return array(null, array('zugang ungueltig')); }
            return array(array('kennwort' => $w['kennwort']), array());
        }),
        'teile_jetzt' => array('zugang' => function () use ($GEHEIM) { return array('kennwort' => $GEHEIM); }),
        'pruefen' => function ($k, $w) {
            if ($k === 'takt') { return (is_int($w) && $w >= 10 && $w <= 3600) ? '' : 'takt ausserhalb'; }
            if ($k === 'thema') { return (is_string($w) && preg_match('/^[a-z]{1,20}\z/', $w)) ? '' : 'thema unzulaessig'; }
            if ($k === 'aktionstoken') { return (is_string($w) && preg_match('/^[a-z0-9]{0,64}\z/', $w)) ? '' : 'token unzulaessig'; }
            if ($k === 'tts') { return (is_array($w) && isset($w['mode']) && in_array($w['mode'], array('aus', 'musicserver'), true)) ? '' : 'tts unzulaessig'; }
            if ($k === 'liste') { return is_array($w) ? '' : 'liste unzulaessig'; }
            return '';
        },
        'quer' => function ($neu) { return ($neu['takt'] === 13) ? array(array('takt', 'takt 13 geht nicht mit thema')) : array(); },
        'uebersetzer' => function ($d) {
            return (isset($d['_hinweis']) && is_string($d['_hinweis']) && strpos($d['_hinweis'], 'Selbsttest-Sicherung') === 0) ? $d : null;
        },
        'speichern' => function ($neu) use (&$gespeichert) { $gespeichert[] = $neu; return true; },
        'nachziehen' => function () { return array('Dienst neu gestartet.'); },
        't' => function ($s) { return $s . ' [%s]'; },
    );
    $jetzt = array('aktionstoken' => $TOK, 'thema' => 'alt', 'takt' => 120, 'ein' => 1, 'spaet' => 0, 'werk' => 7,
                   'tts' => array('mode' => 'musicserver', 'ip' => '192.168.1.5', 'alexa_token' => 'Sprech0123456789'),
                   'liste' => array(array('name' => 'a', 'wert' => 'x', 'kennwort' => $GEHEIM)), 'frei' => array('z1' => 1),
                   'vermerk' => 'v');
    $b['jetzt'] = function () use ($jetzt) { return $jetzt; };

    // --- Bauen: Kopf, Inhalt, keine Sprechtoken, keine Vermerke ---
    $daten = sicherung_bauen($b, $jetzt, array('zugang' => array('kennwort' => $GEHEIM)));
    $pruef('kopf plugin', $daten['_plugin'], 'LoxBerry-Plugin-Selbsttest');
    $pruef('kopf fassung', $daten['_fassung'], '9.8.7');
    $pruef('kopf reihenfolge', array_slice(array_keys($daten), 0, 4), array('_hinweis', '_plugin', '_fassung', '_stand'));
    $pruef('token drin', $daten['aktionstoken'], $TOK);
    $pruef('zugang drin', $daten['zugang']['kennwort'], $GEHEIM);
    $pruef('sprechtoken nicht drin', isset($daten['tts']['alexa_token']), false);
    $pruef('vermerk nicht drin', isset($daten['vermerk']), false);
    $pruef('keine warnung', isset($daten['_warnung']), false);
    $js = sicherung_json($daten);
    $pruef('json', is_string($js) && $js !== '', true);

    // --- Unveraendert zurueck ---
    $r = sicherung_lesen($b, $js, $jetzt);
    $pruef('eigen ok', $r['ok'], true);
    $pruef('eigen kennzeichen', $r['eigen'], 'kennzeichen');
    $pruef('eigen anzahl', $r['anzahl'], 10);
    $pruef('eigen nichts verworfen', $r['verworfen'], array());
    $pruef('eigen thema', $r['neu']['thema'], 'alt');
    $pruef('eigen zugang', $r['teile']['zugang']['kennwort'], $GEHEIM);
    $pruef('eigen fassung datei', $r['fassung_datei'], '9.8.7');
    // 1.2.1: der Vermerk steht nicht in der Datei und behaelt den geltenden Wert (bis 1.2.0 fehlte er danach)
    $pruef('eigen vermerk behaelt den geltenden wert', $r['neu']['vermerk'] ?? 'fehlt', 'v');

    // --- Regel 2: Unbekanntes oben, im Block, in der Liste ---
    $d2 = $daten;
    $d2['zz_oben'] = 'boese';
    $d2['tts']['zz_tief'] = 'boese';
    $d2['liste'][0]['zz_zeile'] = 'boese';
    $d2['frei']['zz_offen'] = 2;
    $r = sicherung_lesen($b, json_encode($d2), $jetzt);
    $pruef('r2 ok', $r['ok'], true);
    $pruef('r2 namen (Reihenfolge der Datei)', $r['verworfen'], array('tts.zz_tief', 'liste[1].zz_zeile', 'zz_oben'));
    $pruef('r2 tts ohne', array_key_exists('zz_tief', $r['neu']['tts']), false);
    $pruef('r2 zeile ohne', array_key_exists('zz_zeile', $r['neu']['liste'][0]), false);
    $pruef('r2 oben ohne', array_key_exists('zz_oben', $r['neu']), false);
    $pruef('r2 offen bleibt', $r['neu']['frei']['zz_offen'], 2);
    $pruef('r2 kein wert in namen', strpos(implode(',', $r['verworfen']), 'boese'), false);
    $d2t = $daten;
    $d2t['zugang']['zz_teil'] = 'boese';
    $d2t['zugang']['kennwort'] = '';
    $r = sicherung_lesen($b, json_encode($d2t), $jetzt);
    $pruef('r2 im teil verworfen', $r['verworfen'], array('zugang.zz_teil'));
    $pruef('r3 im teil: bisheriges kennwort bleibt', array($r['teile']['zugang']['kennwort'], $r['leer_behalten']),
           array($GEHEIM, array('zugang.kennwort')));
    $d2b = $daten;
    $d2b["zz\nZEILE;X=1"] = 1;
    $r = sicherung_lesen($b, json_encode($d2b), $jetzt);
    $pruef('r2 name bereinigt', $r['verworfen'], array('zz?ZEILE?X?1'));

    // --- Regel 3: leer ---
    $d3 = $daten;
    $d3['aktionstoken'] = '';
    $d3['tts']['ip'] = '';
    $d3['liste'][0]['kennwort'] = '';
    $r = sicherung_lesen($b, json_encode($d3), $jetzt);
    $pruef('r3 ok', $r['ok'], true);
    $pruef('r3 token bleibt', $r['neu']['aktionstoken'], $TOK);
    $pruef('r3 ip bleibt', $r['neu']['tts']['ip'], '192.168.1.5');
    $pruef('r3 kennwort der zeile bleibt (zuordnung name)', $r['neu']['liste'][0]['kennwort'], $GEHEIM);
    $pruef('r3 namen', $r['leer_behalten'], array('aktionstoken', 'tts.ip', 'liste[1].kennwort'));
    $leer = $jetzt;
    $leer['aktionstoken'] = '';
    $leer['tts']['ip'] = '';
    $r = sicherung_lesen($b, json_encode($d3), $leer);
    $pruef('r3 beidseits leer still', $r['leer_behalten'], array('liste[1].kennwort'));
    $pruef('r3 beidseits leer bleibt leer', $r['neu']['aktionstoken'], '');
    $d3b = $daten;
    $d3b['liste'][0]['name'] = 'b';
    $d3b['liste'][0]['kennwort'] = '';
    $r = sicherung_lesen($b, json_encode($d3b), $jetzt);
    $pruef('r3 ohne gegenstueck leer', $r['neu']['liste'][0]['kennwort'], '');
    $d3c = $daten;
    $d3c['liste'] = array();
    $r = sicherung_lesen($b, json_encode($d3c), $jetzt);
    $pruef('r3 leere liste wird uebernommen', $r['neu']['liste'], array());
    $pruef('leer bleibt: 0 ist kein leerer wert', sicherung_leer_bleibt('', 0), true);
    $pruef('leer bleibt: null', sicherung_leer_bleibt('', null), false);
    $pruef('leer bleibt: feld', sicherung_leer_bleibt('', array(1)), false);
    $pruef('leer bleibt: 0 in der datei', sicherung_leer_bleibt(0, 'x'), false);

    // --- Regel 4: halb gueltig, alles gesammelt ---
    $d4 = $daten;
    $d4['takt'] = 5;
    $d4['thema'] = 'A B';
    $d4['zz_oben'] = 1;
    $r = sicherung_lesen($b, json_encode($d4), $jetzt);
    $pruef('r4 abgewiesen', $r['ok'], false);
    $pruef('r4 neu null', $r['neu'], null);
    $pruef('r4 beide gesammelt', count($r['beanstandet']), 2);
    $pruef('r4 teile leer', $r['teile'], array());
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($b, json_encode($d4));
    $pruef('r4 nichts gespeichert', count($gespeichert), $n);
    $d4b = $daten;
    $d4b['takt'] = 13;
    $r = sicherung_lesen($b, json_encode($d4b), $jetzt);
    $pruef('r4 querpruefung', $r['beanstandet'], array(array('name' => 'takt', 'text' => 'takt 13 geht nicht mit thema')));

    // --- Regel 1: Kennzeichen ---
    $d1 = $daten;
    $d1['_plugin'] = 'octopus';
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 fremd', array($r['ok'], $r['fremd'], $r['kennzeichen_datei']), array(false, 'kennzeichen', 'octopus'));
    $d1['_plugin'] = array('x');
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 fremd ohne namensform', array($r['ok'], $r['kennzeichen_datei']), array(false, '?'));
    $d1 = $daten;
    unset($d1['_plugin']);
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 ohne', array($r['ok'], $r['fremd']), array(false, 'ohne'));
    $d1['_hinweis'] = 'Selbsttest-Sicherung von frueher';
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 alt erkannt', array($r['ok'], $r['eigen']), array(true, 'uebersetzt'));
    $d1 = $daten;
    $d1['_plugin'] = 'selbsttest';
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 frueheres kennzeichen', array($r['ok'], $r['eigen']), array(true, 'frueher'));
    $r = sicherung_lesen($b, '[1,2]', $jetzt);
    $pruef('form liste', $r['fremd'], 'form');
    $r = sicherung_lesen($b, 'kein json', $jetzt);
    $pruef('form text', $r['fremd'], 'form');
    $r = sicherung_lesen($b, str_repeat(' ', SICHERUNG_MAX_BYTE + 1), $jetzt);
    $pruef('zu gross', $r['fremd'], 'gross');

    // --- Fehlende Schluessel ---
    $d5 = $daten;
    unset($d5['spaet'], $d5['werk'], $d5['ein']);
    $r = sicherung_lesen($b, json_encode($d5), $jetzt);
    $pruef('fehlt pflicht', array($r['ok'], $r['beanstandet'][0]['name']), array(false, 'ein'));
    $d5['ein'] = 0;
    $r = sicherung_lesen($b, json_encode($d5), $jetzt);
    $pruef('fehlt behalten', array($r['ok'], $r['neu']['spaet'], $r['fehlt_behalten']), array(true, 0, array('spaet')));
    $pruef('fehlt vorgabe', array($r['neu']['werk'], $r['fehlt_vorgabe']), array(5, array('werk')));
    $pruef('alt still', sicherung_lesen($b, json_encode($daten + array('altschluessel' => 1)), $jetzt)['verworfen'], array());
    $nurkopf = array('_plugin' => 'LoxBerry-Plugin-Selbsttest');
    $r = sicherung_lesen($b, json_encode($nurkopf), $jetzt);
    $pruef('nur kopf abgewiesen', $r['ok'], false);

    // --- Zurueckspielen, Meldungen, Protokoll: keine Werte ---
    $prot = array();
    $b['log'] = function ($z) use (&$prot) { $prot[] = $z; };
    $r = sicherung_zurueckspielen($b, json_encode($d3 + array('zz_oben' => $GEHEIM)));
    $pruef('zurueck gespeichert', $r['gespeichert'], true);
    $pruef('zurueck dienst', $r['dienst'], array('Dienst neu gestartet.'));
    $m = sicherung_meldungen($b, $r);
    $alles = implode("\n", array_merge($m['fehler'], $m['hinweise'], $prot));
    $pruef('meldung ohne token', strpos($alles, $TOK), false);
    $pruef('meldung ohne kennwort', strpos($alles, $GEHEIM), false);
    $pruef('meldung nennt verworfen', strpos($alles, 'zz_oben') !== false, true);
    $pruef('meldung nennt leer', strpos($alles, 'tts.ip') !== false, true);
    $pruef('protokoll eine zeile', count($prot), 1);
    $r = sicherung_lesen($b, json_encode($d4), $jetzt);
    $m = sicherung_meldungen($b, $r);
    $pruef('meldung abgelehnt', count($m['fehler']), 1);

    // --- Selbstpruefung beim Sichern ---
    $kaputt = $jetzt;
    $kaputt['takt'] = 1;
    $daten_k = sicherung_bauen($b, $kaputt);
    $pruef('warnung bei eigener unbrauchbarer', isset($daten_k['_warnung']) && strpos($daten_k['_warnung'], 'takt') !== false, true);
    $pruef('warnung ohne wert', isset($daten_k['_warnung']) && strpos($daten_k['_warnung'], $TOK) === false, true);

    // --- Beschreibung, Fassung, Texte ---
    $pruef('beschreibung ohne kennzeichen', sicherung_beschreibung_mangel(array('vorgaben' => array('a' => 1))), array('kennzeichen'));
    $pruef('fassung ohne quelle', sicherung_fassung_lesen(array()), '');
    $pruef('namensform', array(sicherung_namensform('a.b-c_1'), sicherung_namensform("a\n"), sicherung_namensform('')), array(true, false, false));
    $pruef('schluessel zahl', count(sicherung_sprachschluessel()), 30);     // 1.2.6: K_HUELLE_DOPPELT (bis 1.2.5: 29)
    $pruef('schluessel eindeutig', count(array_unique(sicherung_sprachschluessel())), 30);

    // --- 1.2.0 (a) Rohwert an 'pruefen' und 'quer' ---
    $gesehen = array();
    $ba = $b;
    $ba['schluessel']['tts']['pruefen'] = function ($w, $k, $neu, $roh = 'fehlt') use (&$gesehen) {
        $gesehen['tts'] = array(is_array($w) ? $w['ip'] : '?', is_array($roh) ? $roh['ip'] : $roh);
        return '';
    };
    $ba['quer'] = function ($neu, $r, $daten = 'fehlt') use (&$gesehen) {
        $gesehen['quer'] = is_array($daten) && isset($daten['tts']['ip']) ? $daten['tts']['ip'] : $daten;
        return array();
    };
    $r = sicherung_lesen($ba, json_encode($d3), $jetzt);
    $pruef('roh: pruefen sieht Wert nach Regel 3 und Rohwert', isset($gesehen['tts']) ? $gesehen['tts'] : null,
           array('192.168.1.5', ''));
    $pruef('roh: quer sieht die Datei vor Regel 3', isset($gesehen['quer']) ? $gesehen['quer'] : null, '');
    $gesehen = array();
    $bo = $b;
    $bo['pruefen'] = function ($k, $w, $neu, $roh = 'fehlt') use (&$gesehen) {
        if ($k === 'tts') { $gesehen['oben'] = array(isset($w['zz_tief']), is_array($roh) && isset($roh['zz_tief'])); }
        return '';
    };
    sicherung_lesen($bo, json_encode($d2), $jetzt);
    $pruef('roh: oberes pruefen sieht den Rohwert (vor Regel 2)', isset($gesehen['oben']) ? $gesehen['oben'] : null,
           array(false, true));

    // --- 1.2.0 (b) flache Teile, (f) 'fehlt' im Block, (g) Hinweise aus Teilen ---
    $ZJ = array('a' => 'Alt-A', 'b' => 'Alt-B');
    $bf = array('kennzeichen' => 'flach', 'vorgaben' => array('x' => 1),
                'schluessel' => array('z' => sicherung_block(array('a', 'b'), array('b' => array('fehlt' => 'behalten')))),
                'teile' => array('z' => function ($w) {
                    if (!is_array($w) || !isset($w['a']) || !isset($w['b'])) { return array(null, array('z unvollstaendig')); }
                    return array($w, array(), array('Hinweis aus Teil z.'));
                }),
                'teile_flach' => array('z' => 'z_'), 'teile_jetzt' => array('z' => $ZJ),
                't' => function ($s) { return $s . ' [%s]'; });
    $df = sicherung_bauen($bf, array('x' => 2), array('z' => array('a' => 'A1', 'b' => 'B1')));
    $pruef('flach: bauen schreibt z_a und z_b', array(isset($df['z_a']) ? $df['z_a'] : null, isset($df['z_b']) ? $df['z_b'] : null,
           isset($df['z'])), array('A1', 'B1', false));
    $r = sicherung_lesen($bf, json_encode($df), array('x' => 1));
    $pruef('flach: zurueck als Teil', array($r['ok'], $r['teile']['z']), array(true, array('a' => 'A1', 'b' => 'B1')));
    $pruef('flach: Hinweis des Teils im Ergebnis', $r['teile_hinweise'], array('Hinweis aus Teil z.'));
    $m = sicherung_meldungen($bf, $r);
    $pruef('flach: Hinweis des Teils in der Meldung', in_array('Hinweis aus Teil z.', $m['hinweise'], true), true);
    $df2 = $df;
    $df2['z_a'] = '';
    $df2['z_q'] = 'boese';
    unset($df2['z_b']);
    $r = sicherung_lesen($bf, json_encode($df2), array('x' => 1));
    $pruef('flach: Regel 3, Regel 2 und fehlt je Feld, Namen wie in der Datei',
           array($r['ok'], $r['teile']['z'], $r['leer_behalten'], $r['verworfen'], $r['fehlt_behalten']),
           array(true, array('a' => 'Alt-A', 'b' => 'Alt-B'), array('z_a'), array('z_q'), array('z_b')));
    $df3 = $df;
    unset($df3['z_a'], $df3['z_b']);
    $r = sicherung_lesen($bf, json_encode($df3), array('x' => 1));
    $pruef('flach: Teil fehlt ganz - Teil unberuehrt, Felder mit behalten genannt',
           array($r['ok'], isset($r['teile']['z']), $r['fehlt_behalten']), array(true, false, array('z_b')));
    $bf2 = $bf;
    $bf2['teile_flach'] = array('fremd' => 'q_');
    $pruef('flach: Teil ohne Eintrag in teile ist ein Mangel', sicherung_beschreibung_mangel($bf2), array('teile_flach'));
    $d6 = $daten;
    unset($d6['tts']['mode']);
    $bb = $b;
    $bb['schluessel']['tts']['schluessel']['mode'] = array('fehlt' => 'behalten');
    $r = sicherung_lesen($bb, json_encode($d6), $jetzt);
    $pruef('fehlt im Block: geltender Wert und Name', array($r['ok'], $r['neu']['tts']['mode'], in_array('tts.mode', $r['fehlt_behalten'], true)),
           array(true, 'musicserver', true));
    $r = sicherung_lesen($b, json_encode($d6), $jetzt);
    $pruef('fehlt im Block: ohne Angabe wie bisher (kein Name)', in_array('tts.mode', $r['fehlt_behalten'], true), false);

    // --- 1.2.0 (g) fehlt_vorgabe in der Meldung ---
    $r = sicherung_lesen($b, json_encode($d5), $jetzt);
    $m = sicherung_meldungen($b, $r);
    $pruef('fehlt_vorgabe in der Meldung', strpos(implode("\n", $m['hinweise']), 'K_FEHLT_VORGABE') !== false
           && strpos(implode("\n", $m['hinweise']), 'werk') !== false, true);

    // --- 1.2.0 (c) eigene Warnsaetze an _warnung ---
    $bw = $b;
    $bw['warnung'] = function () { return array('Eigener Satz der Linie.'); };
    $dw = sicherung_bauen($bw, $jetzt);
    $pruef('warnung: eigener Satz allein', isset($dw['_warnung']) ? $dw['_warnung'] : null, 'Eigener Satz der Linie.');
    $dw = sicherung_bauen($bw, $kaputt);
    $pruef('warnung: nach dem Satz des Moduls', isset($dw['_warnung']) && strpos($dw['_warnung'], 'K_WARNUNG') !== false
           && substr($dw['_warnung'], -23) === 'Eigener Satz der Linie.', true);
    $pruef('warnung: ohne Angabe kein Kopf', isset($daten['_warnung']), false);

    // --- 1.2.0 (d) Muster fuer fruehere Kennzeichen ---
    $bk = $b;
    $bk['kennzeichen_frueher_muster'] = '/^Selbsttest \([a-z0-9_-]{1,40}\)\z/';
    $d1 = $daten;
    $d1['_plugin'] = 'Selbsttest (meinordner)';
    $r = sicherung_lesen($bk, json_encode($d1), $jetzt);
    $pruef('muster: frueheres Kennzeichen erkannt', array($r['ok'], $r['eigen']), array(true, 'frueher'));
    $d1['_plugin'] = 'Selbsttest (mein ordner)';
    $r = sicherung_lesen($bk, json_encode($d1), $jetzt);
    $pruef('muster: anderes bleibt fremd', array($r['ok'], $r['fremd']), array(false, 'kennzeichen'));
    $r = sicherung_lesen($b, json_encode(array('_plugin' => 'Selbsttest (meinordner)') + $daten), $jetzt);
    $pruef('muster: ohne Angabe fremd', $r['fremd'], 'kennzeichen');
    $bk['kennzeichen_frueher_muster'] = '/[/';
    $pruef('muster: unbrauchbares Muster ist ein Mangel', sicherung_beschreibung_mangel($bk), array('kennzeichen_frueher_muster'));

    // --- 1.2.0 (e) umbenennen ---
    $bu = $b;
    $bu['umbenennen'] = array('_zugang' => 'zugang', '_thema' => 'thema');
    $du = $daten;
    $du['_zugang'] = $du['zugang'];
    unset($du['zugang']);
    $du['_thema'] = 'neu';
    $r = sicherung_lesen($bu, json_encode($du), $jetzt);
    $pruef('umbenennen: alter Name gilt als neuer', array($r['ok'], $r['teile']['zugang']['kennwort']), array(true, $GEHEIM));
    $pruef('umbenennen: heutiger Name schon da - der alte bleibt Kopf', $r['neu']['thema'], 'alt');
    $r = sicherung_lesen($b, json_encode($du), $jetzt);
    $pruef('umbenennen: ohne Angabe bleibt _zugang Kopf', isset($r['teile']['zugang']), false);

    // --- 1.2.0 (h) vorbedingung ---
    $bv = $b;
    $gesp = 0;
    $bv['speichern'] = function () use (&$gesp) { $gesp++; return true; };
    $bv['vorbedingung'] = function () { return array('Vorher geht etwas nicht.'); };
    $bv['log'] = function () {};
    $r = sicherung_zurueckspielen($bv, json_encode($daten));
    $m = sicherung_meldungen($bv, $r);
    $pruef('vorbedingung: nicht gespeichert', array($gesp, $r['gespeichert'], $r['vorbedingung']), array(0, false, array('Vorher geht etwas nicht.')));
    $pruef('vorbedingung: Meldung ohne Schreibfehler', $m['fehler'], array('Vorher geht etwas nicht.'));
    $bv['vorbedingung'] = function () { return array(); };
    $r = sicherung_zurueckspielen($bv, json_encode($daten));
    $pruef('vorbedingung erfuellt: gespeichert', array($gesp, $r['gespeichert']), array(1, true));
    $bv['speichern'] = function () { return false; };
    $r = sicherung_zurueckspielen($bv, json_encode($daten));
    $m = sicherung_meldungen($bv, $r);
    $pruef('ohne vorbedingung-Satz: Schreibfehler wie bisher', strpos(implode(' ', $m['fehler']), 'K_SCHREIBFEHLER') !== false, true);

    // --- 1.2.1: ein Vermerk ('nie_aus_datei') behaelt beim Zurueckspielen seinen geltenden Wert ---
    $dn = $daten;
    $dn['vermerk'] = 'aus-der-datei';
    $r = sicherung_lesen($b, json_encode($dn), $jetzt);
    $pruef('nie_aus_datei: Datei traegt ihn - der geltende gilt, nicht genannt',
           array($r['ok'], isset($r['neu']['vermerk']) ? $r['neu']['vermerk'] : 'fehlt', $r['verworfen'], $r['fehlt_behalten'],
                 $r['fehlt_vorgabe']),
           array(true, 'v', array(), array(), array()));
    $jn = $jetzt;
    unset($jn['vermerk']);
    $r = sicherung_lesen($b, json_encode($dn), $jn);
    $pruef('nie_aus_datei: nicht in der geltenden Konfiguration - fehlt wie bisher', array($r['ok'], isset($r['neu']['vermerk'])),
           array(true, false));
    $bn = $b;
    $bn['vorgaben']['vermerk'] = 'werk';
    $r = sicherung_lesen($bn, json_encode($dn), $jn);
    $pruef('nie_aus_datei: in den Vorgaben, nicht geltend - Vorgabe wie bisher', $r['neu']['vermerk'], 'werk');
    $r = sicherung_lesen($bn, json_encode($dn), $jetzt);
    $pruef('nie_aus_datei: in den Vorgaben und geltend - der geltende', $r['neu']['vermerk'], 'v');
    $bn = $b;
    $bn['vorgaben']['vermerk'] = 'werk';
    $bn['vorbehandeln'] = function ($bereit) { $bereit['vermerk'] = 'linie'; return $bereit; };
    $r = sicherung_lesen($bn, json_encode($dn), $jetzt);
    $pruef('nie_aus_datei: vorbehandeln setzt ihn - der Wert der Linie', $r['neu']['vermerk'], 'linie');
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($b + array('log' => function () {}), json_encode($dn));
    $pruef('nie_aus_datei: gespeichert mit dem geltenden Vermerk',
           array($r['gespeichert'], count($gespeichert) - $n, isset($gespeichert[$n]['vermerk']) ? $gespeichert[$n]['vermerk'] : 'fehlt'),
           array(true, 1, 'v'));
    $pruef('nie_aus_datei: nie gesichert, auch wenn er in den Vorgaben steht', array_key_exists('vermerk', sicherung_bauen($bn, $jetzt)), false);

    // --- 1.2.2: ein Wert, den 'vorbehandeln' fuer einen fehlenden Schluessel setzt, bleibt ('behalten') ---
    $du = $daten;
    unset($du['spaet'], $du['werk']);
    $bu2 = $b;
    $bu2['vorbehandeln'] = function ($bereit, $jetzt, $daten) {
        if (!array_key_exists('spaet', $daten)) { $bereit['spaet'] = 9; }
        if (!array_key_exists('werk', $daten)) { $bereit['werk'] = 8; }
        return $bereit;
    };
    $r = sicherung_lesen($bu2, json_encode($du), $jetzt);
    $pruef('uebergang: behalten - der Wert der Linie bleibt, eigens genannt',
           array($r['ok'], $r['neu']['spaet'], $r['fehlt_uebergang'], $r['fehlt_behalten']), array(true, 9, array('spaet'), array()));
    $pruef('uebergang: vorgabe wie bisher - Wert der Linie, als vorgabe genannt',
           array($r['neu']['werk'], $r['fehlt_vorgabe'], in_array('werk', $r['fehlt_uebergang'], true)), array(8, array('werk'), false));
    $m = sicherung_meldungen($bu2, $r);
    $h = implode("\n", $m['hinweise']);
    $pruef('uebergang: Meldung mit K_FEHLT_UEBERGANG, nicht als behalten',
           array(strpos($h, 'K_FEHLT_UEBERGANG [spaet]') !== false, strpos($h, 'K_FEHLT_BEHALTEN') === false), array(true, true));
    $z = sicherung_protokollzeile($r);
    $pruef('uebergang: Protokoll nennt die Uebergangsregel, nicht den bisherigen Wert',
           array(strpos($z, 'Uebergangsregel der Linie: spaet') !== false, strpos($z, 'bisheriger Wert bleibt') === false), array(true, true));
    $bu3 = $b;
    $bu3['vorbehandeln'] = function ($bereit) { return $bereit; };
    $r = sicherung_lesen($bu3, json_encode($du), $jetzt);
    $pruef('uebergang: setzt vorbehandeln nichts - geltender Wert wie bisher',
           array($r['ok'], $r['neu']['spaet'], $r['fehlt_behalten'], $r['fehlt_uebergang']), array(true, 0, array('spaet'), array()));
    $dp = $daten;
    unset($dp['ein']);
    $bu4 = $b;
    $bu4['vorbehandeln'] = function ($bereit) { $bereit['ein'] = 3; return $bereit; };
    $r = sicherung_lesen($bu4, json_encode($dp), $jetzt);
    $pruef('uebergang: pflicht oben seit 1.2.9 ebenso - der Wert der Linie, eigens genannt (bis 1.2.8 abgewiesen)',
           array($r['ok'], (isset($r['neu']['ein']) ? $r['neu']['ein'] : null), $r['fehlt_uebergang'], $r['beanstandet'], $r['fehlt_behalten']),
           array(true, 3, array('ein'), array(), array()));
    $m = sicherung_meldungen($bu4, $r);
    $h = implode("\n", $m['hinweise']);
    $pruef('uebergang: pflicht oben - Meldung mit K_FEHLT_UEBERGANG, ohne K_FEHLEND',
           array(strpos($h, 'K_FEHLT_UEBERGANG [ein]') !== false, strpos(implode("\n", $m['fehler']) . $h, 'K_FEHLEND') === false),
           array(true, true));
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($bu4 + array('log' => function () {}), json_encode($dp));
    $pruef('uebergang: pflicht oben - gespeichert mit dem Wert der Linie',
           array($r['gespeichert'], count($gespeichert) - $n, isset($gespeichert[$n]['ein']) ? $gespeichert[$n]['ein'] : 'fehlt'),
           array(true, 1, 3));
    $r = sicherung_lesen($bu3, json_encode($dp), $jetzt);
    $pruef('uebergang: pflicht oben, vorbehandeln setzt nichts - abgewiesen wie bisher',
           array($r['ok'], isset($r['beanstandet'][0]['name']) ? $r['beanstandet'][0]['name'] : '', count($r['beanstandet']),
                 $r['fehlt_uebergang']),
           array(false, 'ein', 1, array()));
    $bu6 = $bu4;
    $bu6['schluessel']['ein'] = array('fehlt' => 'pflicht');
    $r = sicherung_lesen($bu6, json_encode($dp), $jetzt);
    $pruef('uebergang: pflicht oben ausdruecklich - ebenso Uebergang',
           array($r['ok'], (isset($r['neu']['ein']) ? $r['neu']['ein'] : null), $r['fehlt_uebergang']), array(true, 3, array('ein')));
    $bu6['schluessel']['ein'] = array('fehlt' => 'pflict');
    $r = sicherung_lesen($bu6, json_encode($dp), $jetzt);
    $pruef('uebergang: unbekannte Angabe oben wirkt als pflicht - ebenso Uebergang',
           array($r['ok'], (isset($r['neu']['ein']) ? $r['neu']['ein'] : null), $r['fehlt_uebergang']), array(true, 3, array('ein')));
    $d = $dp;
    unset($d['thema']);
    $r = sicherung_lesen($bu4, json_encode($d), $jetzt);
    $pruef('uebergang: zwei Pflichtschluessel fehlen, vorbehandeln setzt einen - nur der andere abgewiesen',
           array($r['ok'], isset($r['beanstandet'][0]['name']) ? $r['beanstandet'][0]['name'] : '', count($r['beanstandet'])),
           array(false, 'thema', 1));
    $d = $daten;
    unset($d['takt']);
    $bu7 = $b;
    $bu7['vorbehandeln'] = function ($bereit) { $bereit['takt'] = 5; return $bereit; };
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($bu7 + array('log' => function () {}), json_encode($d));
    $pruef('uebergang: pflicht oben, Wert der Linie taugt nicht - abgewiesen (Regel 4), nichts gespeichert',
           array($r['ok'], isset($r['beanstandet'][0]['name']) ? $r['beanstandet'][0]['name'] : '', count($r['beanstandet']),
                 count($gespeichert) - $n),
           array(false, 'takt', 1, 0));
    $bu5 = $bu2;
    $bu5['schluessel']['spaet']['pruefen'] = function ($w) { return $w === 9 ? 'spaet unzulaessig' : ''; };
    $r = sicherung_lesen($bu5, json_encode($du), $jetzt);
    $pruef('uebergang: der Wert der Linie geht durch die Pruefung wie bisher',
           array($r['ok'], isset($r['beanstandet'][0]['name']) ? $r['beanstandet'][0]['name'] : ''), array(false, 'spaet'));
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($bu2 + array('log' => function () {}), json_encode($du));
    $pruef('uebergang: gespeichert mit dem Wert der Linie',
           array($r['gespeichert'], count($gespeichert) - $n, isset($gespeichert[$n]['spaet']) ? $gespeichert[$n]['spaet'] : 'fehlt'),
           array(true, 1, 9));

    // --- 1.2.3: eine UTF-8-BOM am Anfang der Datei stoert nicht ---
    $r = sicherung_lesen($b, "\xEF\xBB\xBF" . json_encode($daten), $jetzt);
    $pruef('bom: Datei mit UTF-8-BOM wird gelesen wie ohne', array($r['ok'], $r['fremd']), array(true, ''));
    $r = sicherung_lesen($b, "\xEF\xBB" . json_encode($daten), $jetzt);
    $pruef('bom: eine halbe BOM bleibt kein JSON', array($r['ok'], $r['fremd']), array(false, 'form'));

    // --- 1.2.4: Schluessel der geltenden Konfiguration, die die Beschreibung nicht kennt, fallen weg und werden genannt ---
    $WO = 'GeltendOben-Wert-91';
    $WT = 'GeltendTief-Wert-92';
    $jf = $jetzt;
    $jf['tts']['zz_geltend_tief'] = $WT;
    $jf['zz_geltend_oben'] = $WO;
    $r = sicherung_lesen($b, $js, $jf);
    $pruef('geltend: oben und im Block genannt, Reihenfolge der Konfiguration', array($r['ok'], $r['jetzt_entfernt']),
           array(true, array('tts.zz_geltend_tief', 'zz_geltend_oben')));
    $pruef('geltend: beide fehlen im neuen Stand', array(array_key_exists('zz_geltend_oben', $r['neu']),
           array_key_exists('zz_geltend_tief', $r['neu']['tts'])), array(false, false));
    $pruef('geltend: Vermerk bleibt und wird nicht genannt', array($r['neu']['vermerk'], in_array('vermerk', $r['jetzt_entfernt'], true)),
           array('v', false));
    $pruef('geltend: ohne Zusatzschluessel nichts genannt', sicherung_lesen($b, $js, $jetzt)['jetzt_entfernt'], array());
    $r = sicherung_lesen($b, $js, $jf + array('zugang' => array('kennwort' => $GEHEIM)));
    $pruef('geltend: ein Teil der geltenden Konfiguration wird nicht genannt', $r['jetzt_entfernt'],
           array('tts.zz_geltend_tief', 'zz_geltend_oben'));
    $r = sicherung_lesen($b, $js, $jetzt + array('_notiz' => 'x', 'altschluessel' => 1, "zz\nX;Y" => 1));
    $pruef('geltend: _-Schluessel, frueherer Schluessel und bereinigter Name genannt', $r['jetzt_entfernt'],
           array('_notiz', 'altschluessel', 'zz?X?Y'));
    $jo = $jetzt;
    $jo['frei']['z_neu'] = 3;
    $jo['liste'][0]['zz_zeile'] = 1;
    $r = sicherung_lesen($b, $js, $jo);
    $pruef('geltend: offen und Listen werden nicht untersucht', array($r['ok'], $r['jetzt_entfernt']), array(true, array()));
    $r = sicherung_lesen($bf, json_encode($df), array('x' => 1, 'z_a' => 'Alt-A', 'zz_f' => 1));
    $pruef('geltend: Feld eines flachen Teils nicht genannt, anderes schon', $r['jetzt_entfernt'], array('zz_f'));
    $bt = array('kennzeichen' => 'tief', 'vorgaben' => array('a' => array('b' => array('c' => 1))),
                'schluessel' => array('a' => sicherung_block(array(), array('b' => sicherung_block(array('c'))))),
                't' => function ($s) { return $s . ' [%s]'; });
    $r = sicherung_lesen($bt, json_encode(array('_plugin' => 'tief', 'a' => array('b' => array('c' => 2)))),
                         array('a' => array('b' => array('c' => 1, 'zz' => 2), 'zz2' => 3)));
    $pruef('geltend: Block im Block', array($r['ok'], $r['jetzt_entfernt']), array(true, array('a.b.zz', 'a.zz2')));
    $r = sicherung_lesen($b, sicherung_json(sicherung_bauen($b, $jf)), $jf);
    // 1.2.5 (Entscheidung des Hausherrn 15.11.2026): bis 1.2.4 hier array('tts.zz_geltend_tief', 'zz_geltend_oben')
    $pruef('geltend: Datei aus diesem Stand - im Block verworfen (seit 1.2.5 nicht auch entfernt), oben entfernt',
           array($r['ok'], $r['verworfen'], $r['jetzt_entfernt']),
           array(true, array('tts.zz_geltend_tief'), array('zz_geltend_oben')));
    // --- 1.2.5: ein Name genau einmal - verworfen (aus der Datei) geht vor entfernt (aus der geltenden Konfiguration) ---
    $alle = array_merge($r['verworfen'], $r['jetzt_entfernt']);
    $pruef('einmal: Datei aus diesem Stand - jeder Name genau einmal',
           array(count(array_keys($alle, 'tts.zz_geltend_tief', true)), count(array_keys($alle, 'zz_geltend_oben', true))), array(1, 1));
    $r = sicherung_lesen($b, $js, $jf);
    $pruef('einmal: Gegenprobe - nur geltend, weiter als entfernt genannt', array($r['verworfen'], $r['jetzt_entfernt']),
           array(array(), array('tts.zz_geltend_tief', 'zz_geltend_oben')));
    $r = sicherung_lesen($b, json_encode($daten + array('zz_geltend_oben' => 'aus-der-datei')), $jf);
    $pruef('einmal: oben in Datei und geltend - nur verworfen', array($r['verworfen'], $r['jetzt_entfernt']),
           array(array('zz_geltend_oben'), array('tts.zz_geltend_tief')));
    $r = sicherung_lesen($b, json_encode($d4), $jf);
    $pruef('geltend: halb gueltig - nichts genannt', array($r['ok'], $r['jetzt_entfernt']), array(false, array()));
    $prot = array();
    $stand = $jf;       // die geltende Konfiguration der erfundenen Linie: vor dem Speichern $jf, danach das Gespeicherte
    $bj = $b;
    $bj['jetzt'] = function () use (&$stand) { return $stand; };
    $bj['speichern'] = function ($neu) use (&$gespeichert, &$stand) { $gespeichert[] = $neu; $stand = $neu; return true; };
    $bj['log'] = function ($z) use (&$prot) { $prot[] = $z; };
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($bj, $js);
    $m = sicherung_meldungen($bj, $r);
    $alles = implode("\n", array_merge($m['fehler'], $m['hinweise'], $prot));
    $pruef('geltend zurueck: gespeichert ohne beide', array($r['gespeichert'], count($gespeichert) - $n,
           isset($gespeichert[$n]) && !array_key_exists('zz_geltend_oben', $gespeichert[$n])
           && !array_key_exists('zz_geltend_tief', $gespeichert[$n]['tts'])), array(true, 1, true));
    $pruef('geltend zurueck: Meldung nennt beide mit K_JETZT_ENTFERNT',
           in_array('SICHERUNG.K_JETZT_ENTFERNT [tts.zz_geltend_tief, zz_geltend_oben]', $m['hinweise'], true), true);
    $pruef('geltend zurueck: Protokoll nennt beide',
           strpos(implode("\n", $prot), 'unbekannt, aus der geltenden Konfiguration entfernt: tts.zz_geltend_tief, zz_geltend_oben') !== false, true);
    $pruef('geltend zurueck: kein Wert in Meldung und Protokoll', array(strpos($alles, $WO), strpos($alles, $WT)), array(false, false));
    $bj2 = $bj;
    $bj2['vorbereiten'] = function ($neu, $jetzt) { $neu['zz_geltend_oben'] = $jetzt['zz_geltend_oben']; return $neu; };
    $stand = $jf;
    $r = sicherung_zurueckspielen($bj2, $js);
    $pruef('geltend zurueck: was vorbereiten behaelt, wird nicht genannt', $r['jetzt_entfernt'], array('tts.zz_geltend_tief'));
    $stand = $jf;
    $bj3 = $bj;
    $bj3['speichern'] = function () { return false; };
    $r = sicherung_zurueckspielen($bj3, $js);
    $m = sicherung_meldungen($bj3, $r);
    $pruef('geltend zurueck: Schreibfehler - nichts entfernt, nichts genannt',
           array($r['jetzt_entfernt'], strpos(implode("\n", $m['fehler']), 'K_JETZT_ENTFERNT')), array(array(), false));
    $bj4 = $bj;
    $bj4['vorbedingung'] = function () { return array('Vorher geht etwas nicht.'); };
    $r = sicherung_zurueckspielen($bj4, $js);
    $pruef('geltend zurueck: Vorbedingung - nichts entfernt', $r['jetzt_entfernt'], array());
    $prot = array();
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($bj, json_encode($d4));
    $pruef('geltend zurueck: halb gueltig - nichts gespeichert, Protokoll ohne die Namen',
           array(count($gespeichert) - $n, $r['jetzt_entfernt'], strpos(implode("\n", $prot), 'zz_geltend')), array(0, array(), false));
    // 1.2.5: zurueckgespielt aus dem Stand mit dem Blockschluessel - in Meldung und Protokoll genau einmal genannt, als verworfen
    $prot = array();
    $stand = $jf;
    $r = sicherung_zurueckspielen($bj, sicherung_json(sicherung_bauen($b, $jf)));
    $m = sicherung_meldungen($bj, $r);
    $h = implode("\n", $m['hinweise']);
    $pz = implode("\n", $prot);
    $pruef('einmal zurueck: Meldung nennt den Blockschluessel genau einmal, als verworfen',
           array(substr_count($h, 'tts.zz_geltend_tief'), strpos($h, 'K_VERWORFEN [tts.zz_geltend_tief]') !== false,
                 in_array('SICHERUNG.K_JETZT_ENTFERNT [zz_geltend_oben]', $m['hinweise'], true)), array(1, true, true));
    $pruef('einmal zurueck: Protokoll nennt ihn genau einmal', array(substr_count($pz, 'tts.zz_geltend_tief'),
           strpos($pz, 'verworfen: tts.zz_geltend_tief') !== false), array(1, true));
    $pruef('einmal zurueck: kein Wert', array(strpos($h . $pz, $WO), strpos($h . $pz, $WT)), array(false, false));
    $prot = array();
    $stand = $jf;
    $r = sicherung_zurueckspielen($bj, $js);
    $m = sicherung_meldungen($bj, $r);
    $pruef('einmal zurueck: Gegenprobe - Datei von vorher, beide als entfernt genannt',
           in_array('SICHERUNG.K_JETZT_ENTFERNT [tts.zz_geltend_tief, zz_geltend_oben]', $m['hinweise'], true), true);

    // --- 1.2.5: Huelle ('huelle') - fruehere Sicherungen mit den Einstellungen unter einem Schluessel ---
    $HN = 'konfiguration';
    // eine flache Datei in die Form frueherer Fassungen legen: Kopf (_-Schluessel) oben, alles andere in die Huelle
    $huellen = function ($d, $hn = 'konfiguration') {
        $kopf = array();
        $in = array();
        foreach ($d as $k => $w) {
            if (is_string($k) && $k !== '' && $k[0] === '_') { $kopf[$k] = $w; } else { $in[$k] = $w; }
        }
        return $kopf + array($hn => $in);
    };
    $bh = $b + array('huelle' => $HN);
    $pruef('huelle: die vier Texte stehen in sicherung_sprachschluessel()', array_values(array_intersect(sicherung_sprachschluessel(),
           array('K_HUELLE_GEMISCHT', 'K_HUELLE_KEIN_OBJEKT', 'K_HUELLE_LEER', 'K_HUELLE_KOPF'))),
           array('K_HUELLE_GEMISCHT', 'K_HUELLE_KEIN_OBJEKT', 'K_HUELLE_LEER', 'K_HUELLE_KOPF'));
    $pruef('huelle: Beschreibung mit gueltigem Namen ohne Mangel', sicherung_beschreibung_mangel($bh), array());
    $pruef('huelle: null gilt als nicht angegeben', sicherung_beschreibung_mangel($b + array('huelle' => null)), array());
    foreach (array(array('x'), false, 0, '', '_konfiguration', 'a b', 'thema', 'zugang', 'altschluessel', 'vermerk') as $x) {
        $pruef('huelle: Mangel bei ' . str_replace("\n", '', var_export($x, true)),
               sicherung_beschreibung_mangel($b + array('huelle' => $x)), array('huelle'));
    }
    $bum = $b;
    $bum['umbenennen'] = array('konf_alt' => 'thema');
    $pruef('huelle: Mangel bei einem alten Namen aus umbenennen', sicherung_beschreibung_mangel($bum + array('huelle' => 'konf_alt')),
           array('huelle'));
    $pruef('huelle: Mangel bei einem Feld eines flachen Teils', sicherung_beschreibung_mangel($bf + array('huelle' => 'z_x')),
           array('huelle'));
    $r = sicherung_lesen($b + array('huelle' => 'thema'), json_encode($huellen($daten)), $jetzt);
    $pruef('huelle: mangelhafte Beschreibung liest nichts', array($r['ok'], $r['fremd']), array(false, 'form'));

    // angenommen: das ganze Ergebnis gleich dem der flachen Datei
    $dh = $huellen($daten);
    $pruef('huelle: die Datei der Probe hat die Form frueherer Fassungen',
           array(array_keys($dh), count($dh[$HN])), array(array('_hinweis', '_plugin', '_fassung', '_stand', $HN), count($daten) - 4));
    $r = sicherung_lesen($bh, json_encode($dh), $jetzt);
    $pruef('huelle: angenommen, Ergebnis gleich dem der flachen Datei', $r, sicherung_lesen($b, $js, $jetzt));
    $pruef('huelle: angenommen mit Werten und Teil', array($r['ok'], $r['eigen'], $r['anzahl'], $r['neu']['thema'],
           $r['teile']['zugang']['kennwort'], $r['neu']['vermerk']), array(true, 'kennzeichen', 10, 'alt', $GEHEIM, 'v'));
    $pruef('huelle: flache Datei wie bisher', sicherung_lesen($bh, $js, $jetzt), sicherung_lesen($b, $js, $jetzt));
    $r = sicherung_lesen($b, json_encode($dh), $jetzt);
    $pruef('huelle: ohne Angabe wie in 1.2.4 - verworfen, abgewiesen', array($r['ok'], $r['fremd'], $r['verworfen']),
           array(false, '', array($HN)));
    // danach gelten alle Regeln wie bei der flachen Datei
    foreach (array('Regel 2' => $d2, 'Regel 2 im Teil' => $d2t, 'Name bereinigt' => $d2b, 'Regel 3' => $d3,
                   'halb gueltig' => $d4, 'quer' => $d4b, 'fehlt' => $d5, 'Pflicht fehlt' => $dp, 'Vermerk' => $dn,
                   'alt still' => $daten + array('altschluessel' => 1)) as $n => $dx) {
        $pruef('huelle: wie flach - ' . $n, sicherung_lesen($bh, json_encode($huellen($dx)), $jetzt),
               sicherung_lesen($b, json_encode($dx), $jetzt));
    }
    $pruef('huelle: wie flach - geltende Konfiguration mit unbekannten Schluesseln',
           sicherung_lesen($bh, json_encode($dh), $jf), sicherung_lesen($b, $js, $jf));
    $pruef('huelle: wie flach - Uebergangsregel (vorbehandeln)',
           sicherung_lesen($bu2 + array('huelle' => $HN), json_encode($huellen($du)), $jetzt),
           sicherung_lesen($bu2, json_encode($du), $jetzt));
    $pruef('huelle: wie flach - flacher Teil', sicherung_lesen($bf + array('huelle' => 'konf'), json_encode($huellen($df2, 'konf')),
           array('x' => 1)), sicherung_lesen($bf, json_encode($df2), array('x' => 1)));
    $dum = $daten;
    $dum['_zugang'] = $dum['zugang'];
    unset($dum['zugang']);
    $r = sicherung_lesen($bu + array('huelle' => $HN), json_encode($huellen($dum)), $jetzt);
    $pruef('huelle: umbenennen nach dem Auspacken (alter Name im Kopf)', array($r['ok'], $r['teile']['zugang']['kennwort']),
           array(true, $GEHEIM));
    $dum2 = $huellen($daten);
    $dum2[$HN]['_zugang'] = $dum2[$HN]['zugang'];
    unset($dum2[$HN]['zugang']);
    $r = sicherung_lesen($bu + array('huelle' => $HN), json_encode($dum2), $jetzt);
    $pruef('huelle: umbenennen nach dem Auspacken (alter Name in der Huelle)', array($r['ok'], $r['teile']['zugang']['kennwort']),
           array(true, $GEHEIM));
    $dn2 = $dh;
    $dn2[$HN]['_notiz'] = 'x';
    $pruef('huelle: _-Schluessel in der Huelle ohne Doppel wird Kopf', sicherung_lesen($bh, json_encode($dn2), $jetzt),
           sicherung_lesen($b, json_encode($daten + array('_notiz' => 'x')), $jetzt));

    // Kennzeichen: Regel 1 vor der Huelle
    $df_ = $dh;
    $df_['_plugin'] = 'octopus';
    $r = sicherung_lesen($bh, json_encode($df_), $jetzt);
    $pruef('huelle: fremdes Kennzeichen mit Huelle abgewiesen wie bisher', array($r['ok'], $r['fremd'], $r['kennzeichen_datei']),
           array(false, 'kennzeichen', 'octopus'));
    $df_[$HN . '_daneben'] = 1;
    $r = sicherung_lesen($bh, json_encode($df_), $jetzt);
    $pruef('huelle: fremdes Kennzeichen, gemischt - Grund bleibt das Kennzeichen', array($r['ok'], $r['fremd']),
           array(false, 'kennzeichen'));
    $d1 = $daten;
    $d1['_plugin'] = 'selbsttest';
    $r = sicherung_lesen($bh, json_encode($huellen($d1)), $jetzt);
    $pruef('huelle: frueheres Kennzeichen mit Huelle angenommen', $r, sicherung_lesen($b, json_encode($d1), $jetzt));
    $pruef('huelle: frueheres Kennzeichen - eigen frueher', array($r['ok'], $r['eigen']), array(true, 'frueher'));
    $d1['_plugin'] = 'Selbsttest (meinordner)';
    $bk['kennzeichen_frueher_muster'] = '/^Selbsttest \([a-z0-9_-]{1,40}\)\z/';
    $r = sicherung_lesen($bk + array('huelle' => $HN), json_encode($huellen($d1)), $jetzt);
    $pruef('huelle: frueheres Kennzeichen nach Muster mit Huelle angenommen', array($r['ok'], $r['eigen'], $r['neu']['thema']),
           array(true, 'frueher', 'alt'));
    $d1 = $daten;
    unset($d1['_plugin']);
    $d1['_hinweis'] = 'Selbsttest-Sicherung von frueher';
    $r = sicherung_lesen($bh, json_encode($huellen($d1)), $jetzt);
    $pruef('huelle: vom Uebersetzer erkannt, dann ausgepackt', array($r['ok'], $r['eigen'], $r['neu']['thema']),
           array(true, 'uebersetzt', 'alt'));
    unset($d1['_hinweis']);
    $r = sicherung_lesen($bh, json_encode($huellen($d1)), $jetzt);
    $pruef('huelle: ohne Kennzeichen und nicht erkannt - abgewiesen wie bisher', array($r['ok'], $r['fremd']), array(false, 'ohne'));

    // abgewiesen: gemischt, kein Feld mit Namen, leer, Kopf doppelt - Grund und Namen, nie Werte
    $th = function ($s) {
        return (substr($s, -9) === '_GEMISCHT' || substr($s, -5) === '_KOPF') ? $s . ' [%s] (%s)' : $s . ' [%s]';
    };
    $bht = array('t' => $th) + $bh;
    $abgewiesen = function ($bx, $roh, $id) use ($jetzt, $HN) {
        $r = sicherung_lesen($bx, $roh, $jetzt);
        $t = implode(' ', array_map(function ($x) { return $x['text']; }, $r['beanstandet']));
        return array($r['ok'], $r['fremd'], $r['neu'], isset($r['beanstandet'][0]['name']) ? $r['beanstandet'][0]['name'] : '',
                     strpos($t, 'SICHERUNG.' . $id . ' [' . $HN . ']') !== false);
    };
    $soll = function () use ($HN) { return array(false, 'form', null, $HN, true); };
    $dg = $dh;
    $dg['thema'] = 'neu';
    $pruef('huelle: gemischt mit einer Einstellung oben abgewiesen', $abgewiesen($bht, json_encode($dg), 'K_HUELLE_GEMISCHT'),
           $soll('K_HUELLE_GEMISCHT'));
    $r = sicherung_lesen($bht, json_encode($dg), $jetzt);
    $pruef('huelle: gemischt - der Name daneben genannt', strpos($r['beanstandet'][0]['text'], '(thema)') !== false, true);
    $dg = $dh;
    $dg['zz_unbekannt'] = $GEHEIM;
    $pruef('huelle: gemischt mit einem unbekannten Schluessel oben abgewiesen',
           $abgewiesen($bht, json_encode($dg), 'K_HUELLE_GEMISCHT'), $soll('K_HUELLE_GEMISCHT'));
    $dg = $dh;
    $dg[''] = 1;
    $pruef('huelle: gemischt mit leerem Namen oben abgewiesen', $abgewiesen($bht, json_encode($dg), 'K_HUELLE_GEMISCHT'),
           $soll('K_HUELLE_GEMISCHT'));
    $dg = $dh;
    $dg["zz\nX;Y"] = 1;
    $r = sicherung_lesen($bht, json_encode($dg), $jetzt);
    $pruef('huelle: gemischt - Name bereinigt', strpos($r['beanstandet'][0]['text'], '(zz?X?Y)') !== false, true);
    foreach (array('Text' => 'text', 'Zahl' => 5, 'null' => null, 'wahr' => true, 'Liste' => array(1, 2),
                   'Liste mit Feld' => array($dh[$HN])) as $n => $x) {
        $dk = $dh;
        $dk[$HN] = $x;
        $pruef('huelle: kein Feld mit Namen (' . $n . ') abgewiesen', $abgewiesen($bht, json_encode($dk), 'K_HUELLE_KEIN_OBJEKT'),
               $soll('K_HUELLE_KEIN_OBJEKT'));
    }
    $dk = $dh;
    $dk[$HN] = new stdClass();
    $pruef('huelle: leer ({}) abgewiesen', $abgewiesen($bht, json_encode($dk), 'K_HUELLE_LEER'), $soll('K_HUELLE_LEER'));
    $dk[$HN] = array();
    $pruef('huelle: leer ([]) abgewiesen', $abgewiesen($bht, json_encode($dk), 'K_HUELLE_LEER'), $soll('K_HUELLE_LEER'));
    $dk = $dh;
    $dk[$HN]['_plugin'] = 'octopus';
    $pruef('huelle: _plugin in der Huelle doppelt den Kopf - abgewiesen', $abgewiesen($bht, json_encode($dk), 'K_HUELLE_KOPF'),
           $soll('K_HUELLE_KOPF'));
    $r = sicherung_lesen($bht, json_encode($dk), $jetzt);
    $pruef('huelle: Kopf doppelt - der Name genannt, nicht der Wert', array(strpos($r['beanstandet'][0]['text'], '(_plugin)') !== false,
           strpos($r['beanstandet'][0]['text'], 'octopus')), array(true, false));
    $dk = $dh;
    $dk[$HN]['_fassung'] = '0.0.1';
    $pruef('huelle: _fassung in der Huelle doppelt den Kopf - abgewiesen', $abgewiesen($bht, json_encode($dk), 'K_HUELLE_KOPF'),
           $soll('K_HUELLE_KOPF'));
    $dk = $dh;
    $dk['thema'] = 'neu';
    $dk[$HN] = 'text';
    $r = sicherung_lesen($bht, json_encode($dk), $jetzt);
    $pruef('huelle: alle Gruende gesammelt (gemischt und kein Feld)', count($r['beanstandet']), 2);
    $r = sicherung_lesen($bht, json_encode($huellen($dh)), $jetzt);
    $pruef('huelle: einmal ausgepackt, nicht verschachtelt', array($r['ok'], in_array($HN, $r['verworfen'], true)), array(false, true));

    // Zurueckspielen: halb gueltig aendert nichts; keine Werte in Meldung und Protokoll; flach und Huelle speichern dasselbe
    $prot = array();
    $bhz = array('log' => function ($z) use (&$prot) { $prot[] = $z; }, 't' => $th) + $bh;     // $th: Namen daneben im Text
    $n = count($gespeichert);
    $dg = $dh;
    $dg['zz_unbekannt'] = $GEHEIM;
    $dg[$HN]['_plugin'] = $GEHEIM;
    $r = sicherung_zurueckspielen($bhz, json_encode($dg));
    $m = sicherung_meldungen($bhz, $r);
    $alles = implode("\n", array_merge($m['fehler'], $m['hinweise'], $prot));
    $pruef('huelle zurueck: abgewiesen - nichts gespeichert, eine Meldung', array($r['gespeichert'], count($gespeichert) - $n,
           count($m['fehler'])), array(false, 0, 1));
    $pruef('huelle zurueck: kein Wert in Meldung und Protokoll', array(strpos($alles, $GEHEIM), strpos($alles, $TOK)), array(false, false));
    $pruef('huelle zurueck: Protokoll nennt Form und Huelle', strpos(implode("\n", $prot), 'Sicherung abgewiesen (form): ' . $HN) !== false,
           true);
    $r = sicherung_zurueckspielen($bhz, json_encode($huellen($d4)));
    $pruef('huelle zurueck: halb gueltig in der Huelle - nichts gespeichert', array($r['gespeichert'], count($gespeichert) - $n),
           array(false, 0));
    $r = sicherung_zurueckspielen($bhz, json_encode($dh));
    $r0 = sicherung_zurueckspielen(array('log' => function () {}) + $b, $js);
    $pruef('huelle zurueck: angenommen - gespeichert wie die flache Datei', array($r['gespeichert'], count($gespeichert) - $n,
           $gespeichert[$n] === $gespeichert[$n + 1]), array(true, 2, true));
    $pruef('huelle: sicherung_bauen() schreibt weiter flach', array(array_keys(sicherung_bauen($bh, $jetzt, array('zugang' => array('kennwort' => $GEHEIM)))),
           isset($dh[$HN]['thema'])), array(array_keys($daten), true));

    // --- 1.2.6: Teile neben der Huelle ('neben_huelle', Regel 16) - Datenbestaende, die frueher oben neben der Huelle standen ---
    $NB = array('zugang');
    $bn = $bh + array('neben_huelle' => $NB);
    // die Form frueherer Fassungen mit Datenbestand: Kopf oben, die Huelle, daneben der Teil 'zugang'
    $neben_legen = function ($d) use ($HN) {
        $kopf = array();
        $dn = array();
        $in = array();
        foreach ($d as $k => $w) {
            if (is_string($k) && $k !== '' && $k[0] === '_') { $kopf[$k] = $w; } elseif ($k === 'zugang') { $dn[$k] = $w; } else { $in[$k] = $w; }
        }
        return $kopf + array($HN => $in) + $dn;
    };
    $dnb = $neben_legen($daten);
    $pruef('neben: die Datei der Probe traegt den Teil neben der Huelle', array(array_keys($dnb), isset($dnb[$HN]['zugang']),
           isset($dnb[$HN]['thema'])), array(array('_hinweis', '_plugin', '_fassung', '_stand', $HN, 'zugang'), false, true));
    $pruef('neben: der Text steht in sicherung_sprachschluessel()', in_array('K_HUELLE_DOPPELT', sicherung_sprachschluessel(), true), true);
    $pruef('neben: Beschreibung ohne Mangel', sicherung_beschreibung_mangel($bn), array());
    $pruef('neben: null gilt als nicht angegeben', sicherung_beschreibung_mangel($bh + array('neben_huelle' => null)), array());
    foreach (array('Text' => 'zugang', 'leere Liste' => array(), 'Einstellung' => array('thema'), 'Huelle' => array($HN),
                   'unbekannt' => array('zz'), 'Doppel' => array('zugang', 'zugang'), 'Zahl' => array(5),
                   'Feld mit Namen' => array('a' => 'zugang'), 'false' => false, 'Liste ab 1' => array(1 => 'zugang'),
                   'Feld in der Liste' => array(array('zugang'))) as $n => $x) {
        $pruef('neben: Mangel bei ' . $n, sicherung_beschreibung_mangel($bh + array('neben_huelle' => $x)), array('neben_huelle'));
    }
    $pruef('neben: Mangel ohne Huelle', sicherung_beschreibung_mangel($b + array('neben_huelle' => $NB)), array('neben_huelle'));
    $pruef('neben: Mangel bei untauglicher Huelle', sicherung_beschreibung_mangel($b + array('huelle' => 'thema', 'neben_huelle' => $NB)),
           array('huelle', 'neben_huelle'));
    $r = sicherung_lesen($b + array('neben_huelle' => $NB), json_encode($dnb), $jetzt);
    $pruef('neben: mangelhafte Beschreibung liest nichts', array($r['ok'], $r['fremd'], $r['neu']), array(false, 'form', null));

    // angenommen: das ganze Ergebnis gleich dem der flachen Datei
    $r = sicherung_lesen($bn, json_encode($dnb), $jetzt);
    $pruef('neben: angenommen, Ergebnis gleich dem der flachen Datei', $r, sicherung_lesen($b, $js, $jetzt));
    $pruef('neben: angenommen mit Werten und Teil', array($r['ok'], $r['eigen'], $r['neu']['thema'], $r['teile']['zugang']['kennwort']),
           array(true, 'kennzeichen', 'alt', $GEHEIM));
    $pruef('neben: ohne Angabe wie in 1.2.5 - gemischt abgewiesen', $abgewiesen($bht, json_encode($dnb), 'K_HUELLE_GEMISCHT'),
           $soll('K_HUELLE_GEMISCHT'));
    $pruef('neben: Teil nur in der Huelle wie in 1.2.5', sicherung_lesen($bn, json_encode($dh), $jetzt), sicherung_lesen($b, $js, $jetzt));
    $pruef('neben: flache Datei wie bisher', sicherung_lesen($bn, $js, $jetzt), sicherung_lesen($b, $js, $jetzt));
    $dohne = $daten;
    unset($dohne['zugang']);
    $pruef('neben: Huelle ohne den Teil wie flach ohne ihn', sicherung_lesen($bn, json_encode($neben_legen($dohne)), $jetzt),
           sicherung_lesen($b, json_encode($dohne), $jetzt));
    foreach (array('Regel 2' => $d2, 'Regel 2 und 3 im Teil daneben' => $d2t, 'Regel 3' => $d3, 'halb gueltig' => $d4, 'quer' => $d4b,
                   'fehlt' => $d5, 'Pflicht fehlt' => $dp) as $n => $dx) {
        $pruef('neben: wie flach - ' . $n, sicherung_lesen($bn, json_encode($neben_legen($dx)), $jetzt),
               sicherung_lesen($b, json_encode($dx), $jetzt));
    }
    $dzt = $daten;
    $dzt['zugang'] = 'kein Feld';
    $r = sicherung_lesen($bn, json_encode($neben_legen($dzt)), $jetzt);
    $pruef('neben: Teil daneben beanstandet - die ganze Datei abgewiesen', array($r['ok'], $r['neu'], $r['teile'],
           isset($r['beanstandet'][0]['name']) ? $r['beanstandet'][0]['name'] : ''), array(false, null, array(), 'zugang'));
    // Kennzeichen: Regel 1 vor der Huelle, auch mit Teil daneben
    $df_ = $dnb;
    $df_['_plugin'] = 'octopus';
    $r = sicherung_lesen($bn, json_encode($df_), $jetzt);
    $pruef('neben: fremdes Kennzeichen abgewiesen wie bisher', array($r['ok'], $r['fremd']), array(false, 'kennzeichen'));
    $d1 = $daten;
    unset($d1['_plugin']);
    $d1['_hinweis'] = 'Selbsttest-Sicherung von frueher';
    $r = sicherung_lesen($bn, json_encode($neben_legen($d1)), $jetzt);
    $pruef('neben: vom Uebersetzer erkannt, ausgepackt, Teil daneben gelesen', array($r['ok'], $r['eigen'], $r['neu']['thema'],
           $r['teile']['zugang']['kennwort']), array(true, 'uebersetzt', 'alt', $GEHEIM));

    // abgewiesen: derselbe Teil neben und in der Huelle - Grund und Namen, nie Werte; alle Gruende gesammelt
    $thn = function ($s) {
        foreach (array('_GEMISCHT', '_KOPF', '_DOPPELT') as $e) { if (substr($s, -strlen($e)) === $e) { return $s . ' [%s] (%s)'; } }
        return $s . ' [%s]';
    };
    $bnt = array('t' => $thn) + $bn;
    $dd = $dnb;
    $dd[$HN]['zugang'] = array('kennwort' => $GEHEIM);
    $pruef('neben: Teil daneben und in der Huelle - abgewiesen', $abgewiesen($bnt, json_encode($dd), 'K_HUELLE_DOPPELT'),
           $soll('K_HUELLE_DOPPELT'));
    $r = sicherung_lesen($bnt, json_encode($dd), $jetzt);
    $pruef('neben: doppelt - der Name genannt, nicht der Wert', array(strpos($r['beanstandet'][0]['text'], '(zugang)') !== false,
           strpos($r['beanstandet'][0]['text'], $GEHEIM)), array(true, false));
    $pruef('neben: doppelt - kein Teil gelesen', $r['teile'], array());
    $bx = $bnt;
    $bx['teile']["zz;x"] = $b['teile']['zugang'];
    $bx['neben_huelle'] = array("zz;x");
    $dx = $dh;
    $dx["zz;x"] = array('kennwort' => 'a');
    $dx[$HN]["zz;x"] = array('kennwort' => 'b');
    $r = sicherung_lesen($bx, json_encode($dx), $jetzt);
    $t = implode(' ', array_map(function ($x) { return $x['text']; }, $r['beanstandet']));
    $pruef('neben: doppelt - Name bereinigt', array($r['ok'], count($r['beanstandet']), strpos($t, '(zz?x)') !== false,
           strpos($t, 'zz;x')), array(false, 1, true, false));
    $dg = $dnb;
    $dg['zz_unbekannt'] = $GEHEIM;
    $r = sicherung_lesen($bnt, json_encode($dg), $jetzt);
    $pruef('neben: gemischt nennt nur den fremden Schluessel, nicht den Teil', array($r['ok'], $r['fremd'],
           strpos($r['beanstandet'][0]['text'], '(zz_unbekannt)') !== false, strpos($r['beanstandet'][0]['text'], 'zugang')),
           array(false, 'form', true, false));
    $dg = $dd;
    $dg['thema'] = 'neu';
    $dg[$HN]['_plugin'] = 'x';
    $r = sicherung_lesen($bnt, json_encode($dg), $jetzt);
    $pruef('neben: alle Gruende gesammelt (gemischt, Kopf doppelt, Teil doppelt)', count($r['beanstandet']), 3);

    // Zurueckspielen: doppelt aendert nichts; keine Werte in Meldung und Protokoll; angenommen speichert wie flach
    $prot = array();
    $bnz = array('log' => function ($z) use (&$prot) { $prot[] = $z; }, 't' => $thn) + $bn;
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($bnz, json_encode($dd));
    $m = sicherung_meldungen($bnz, $r);
    $alles = implode("\n", array_merge($m['fehler'], $m['hinweise'], $prot));
    $pruef('neben zurueck: doppelt - nichts gespeichert, eine Meldung', array($r['gespeichert'], count($gespeichert) - $n,
           count($m['fehler'])), array(false, 0, 1));
    $pruef('neben zurueck: kein Wert in Meldung und Protokoll', array(strpos($alles, $GEHEIM), strpos($alles, $TOK)), array(false, false));
    $pruef('neben zurueck: Protokoll nennt Form und Huelle', strpos(implode("\n", $prot), 'Sicherung abgewiesen (form): ' . $HN) !== false,
           true);
    $r = sicherung_zurueckspielen($bnz, json_encode($dnb));
    sicherung_zurueckspielen(array('log' => function () {}) + $b, $js);
    $pruef('neben zurueck: angenommen - gespeichert wie die flache Datei, Teil im Ergebnis', array($r['gespeichert'],
           count($gespeichert) - $n, $gespeichert[$n] === $gespeichert[$n + 1], $r['teile']['zugang']['kennwort']),
           array(true, 2, true, $GEHEIM));
    $pruef('neben: sicherung_bauen() schreibt weiter flach, Teil oben',
           array_keys(sicherung_bauen($bn, $jetzt, array('zugang' => array('kennwort' => $GEHEIM)))), array_keys($daten));

    // --- 1.1.0: der Haken und der Eingang des Knopfes (ohne hochgeladene Datei: __FILE__ steht fuer eine Datei) ---
    // Die Texte kommen ueber $b['t'] (hier: Kennung mit Anhang); keine Kennung steht woertlich im Code, damit die
    // Schluesselpruefungen der Linien sie nicht fuer einen benutzten Sprachschluessel halten.
    $zeile = sicherung_haken_zeile($b);
    $pruef('haken zeile', $zeile, '<div class="sm-feld"><label><input data-role="none" type="checkbox" name="zurueck_ok"'
           . ' value="1"> ' . htmlspecialchars(sicherung_t('K_HAKEN', $b), ENT_QUOTES, 'UTF-8') . '</label></div>');
    $pruef('haken zeile traegt den text', strpos($zeile, 'K_HAKEN [') !== false, true);
    $pruef('haken feldname', SICHERUNG_HAKEN, 'zurueck_ok');
    $ja = function () { return true; };
    $nein = function () { return false; };
    $gelesen = 0;
    $zaehlt = function ($p) use (&$gelesen) { $gelesen++; return true; };
    $art = function ($ab) { return (is_array($ab) && isset($ab['abbruch'])) ? $ab['abbruch'] : '(kein Abbruch)'; };
    $ich = array('tmp_name' => __FILE__, 'size' => (int) filesize(__FILE__), 'error' => 0);
    list($roh, $ab) = sicherung_eingang_lage($b, array(), $ich, $zaehlt);
    $pruef('ohne haken: abbruch', array($roh, $art($ab), is_array($ab) && $ab['ok'] === false && $ab['gespeichert'] === false),
           array(null, 'haken', true));
    $pruef('ohne haken: datei nicht angesehen', $gelesen, 0);
    $m = is_array($ab) ? sicherung_meldungen($b, $ab) : array('fehler' => array(''), 'hinweise' => array('-'));
    $pruef('ohne haken: eine meldung mit dem modultext', array(count($m['fehler']),
           strpos($m['fehler'][0], sicherung_t('K_HAKEN_FEHLT', $b)) !== false,
           strpos($m['fehler'][0], sicherung_t('K_ABGELEHNT', $b)) === 0, $m['hinweise']), array(1, true, true, array()));
    foreach (array('0', 'ja', '', array('1')) as $w) {
        list($roh, $ab) = sicherung_eingang_lage($b, array('zurueck_ok' => $w), $ich, $ja);
        $pruef('haken mit wert ' . var_export($w, true) . ' zaehlt nicht', $art($ab), 'haken');
    }
    list($roh, $ab) = sicherung_eingang_lage($b, array('anderes' => '1'), $ich, $ja);
    $pruef('anderes feld ist kein haken', $art($ab), 'haken');
    $mit = array('zurueck_ok' => '1');
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, null, $ja);
    $pruef('haken, keine datei', array($roh, $art($ab)), array(null, 'datei'));
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, $ich, $nein);
    $pruef('haken, datei nicht hochgeladen', array($roh, $art($ab)), array(null, 'datei'));
    list($roh, $ab) = sicherung_eingang($b, $mit, array('f' => $ich), 'f');
    $pruef('oeffentlich: is_uploaded_file weist eine Serverdatei ab', array($roh, $art($ab)), array(null, 'datei'));
    list($roh, $ab) = sicherung_eingang($b, $mit, array(), 'f');
    $pruef('oeffentlich: feld fehlt', $art($ab), 'datei');
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, array('tmp_name' => '', 'size' => 0, 'error' => 4), $ja);
    $pruef('haken, leeres feld', $art($ab), 'datei');
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, array('tmp_name' => '', 'size' => 0, 'error' => 1), $ja);
    $pruef('haken, ueber upload_max_filesize', array($art($ab), is_array($ab) ? $ab['fremd'] : ''), array('gross', 'gross'));
    list($roh, $ab) = sicherung_eingang_lage($b + array('max_byte' => 1024), $mit, $ich, $ja);
    $pruef('haken, groesser als max_byte', array($roh, $art($ab)), array(null, 'gross'));
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, array('tmp_name' => __FILE__ . '.fehlt', 'size' => SICHERUNG_MAX_BYTE + 1,
                                                             'error' => 0), $ja);
    $pruef('haken, angegebene groesse zu gross: nicht erst gelesen', $art($ab), 'gross');
    $klein = $ich;
    $klein['size'] = 10;
    list($roh, $ab) = sicherung_eingang_lage($b + array('max_byte' => 1024), $mit, $klein, $ja);
    $pruef('haken, groesse falsch angegeben: gelesen wird hoechstens max_byte + 1', array($roh, $art($ab)), array(null, 'gross'));
    list($roh, $ab) = sicherung_eingang_lage($b, $mit, $ich, $ja);
    $pruef('haken und datei: text zurueck', array($roh === file_get_contents(__FILE__), $ab), array(true, null));
    $n = count($gespeichert);
    foreach (array(array(), array('zurueck_ok' => '0')) as $p) { sicherung_eingang_lage($b, $p, $ich, $ja); }
    $pruef('eingang speichert nie', count($gespeichert), $n);

    // --- 1.2.7 (a): 'zweitschrift' als Liste von Paaren - je Paar beurteilt, zusammengefasst der schlechteste Punkt.
    // Ohne Dateien: __FILE__ steht fuer eine vorhandene Datei, __FILE__ . '.fehlt' fuer eine fehlende; 'abweichend'
    // liefert eine eigene Beurteilung je Paar (wie die austauschbare Hochlade-Pruefung oben). ---
    $F = __FILE__;
    $X = __FILE__ . '.fehlt';
    $pruef('zweitschrift: ein Paar wie bis 1.2.6 (gleich)', sicherung_zweitschrift_lage(array($F, $F)), 'mit');
    $pruef('zweitschrift: ein Paar wie bis 1.2.6 (Zweitschrift fehlt)', sicherung_zweitschrift_lage(array($F, $X)), 'fehlt');
    $pruef('zweitschrift: ein Paar wie bis 1.2.6 (kein Paar)', array(sicherung_zweitschrift_lage(array()),
           sicherung_zweitschrift_lage(null), sicherung_zweitschrift_lage(array($F))), array('fehlt', 'fehlt', 'fehlt'));
    $pruef('zweitschrift: ein Paar ist keine Liste', sicherung_zweitschrift_ist_liste(array($F, $F)), false);
    $pruef('zweitschrift: drei Paare gleich', sicherung_zweitschrift_lage(array(array($F, $F), array($F, $F), array($F, $F))), 'mit');
    $pruef('zweitschrift: drei Paare, das zweite fehlt', sicherung_zweitschrift_lage(array(array($F, $F), array($F, $X), array($F, $F))),
           'fehlt');
    $pruef('zweitschrift: drei Paare, nur das letzte fehlt', sicherung_zweitschrift_lage(array(array($F, $F), array($F, $F), array($F, $X))),
           'fehlt');
    $pruef('zweitschrift: je Paar die Lage, in der Reihenfolge der Liste',
           sicherung_zweitschrift_lagen(array(array($F, $F), array($F, $X), array($F, $F))), array('mit', 'fehlt', 'mit'));
    $pruef('zweitschrift: ein unbrauchbares Paar in der Liste', sicherung_zweitschrift_lage(array(array($F, $F), array($F))), 'fehlt');
    $pruef('zweitschrift: Liste mit einem Element, das kein Paar ist', sicherung_zweitschrift_lage(array(array($F, $F), $F)), 'fehlt');
    $lage_je = function ($p) { return (is_array($p) && isset($p[0]) && is_string($p[0])) ? $p[0] : 'fehlt'; };
    $pruef('zweitschrift: mit, abweichend, mit - abweichend', sicherung_zweitschrift_schlechteste(
           sicherung_zweitschrift_lagen(array(array('mit'), array('abweichend'), array('mit')), $lage_je)), 'abweichend');
    $pruef('zweitschrift: abweichend, fehlt - fehlt', sicherung_zweitschrift_schlechteste(
           sicherung_zweitschrift_lagen(array(array('abweichend'), array('fehlt')), $lage_je)), 'fehlt');
    $pruef('zweitschrift: fehlt, abweichend - fehlt (Reihenfolge zaehlt nicht)', sicherung_zweitschrift_schlechteste(
           sicherung_zweitschrift_lagen(array(array('fehlt'), array('abweichend'), array('mit')), $lage_je)), 'fehlt');
    $pruef('zweitschrift: nur mit - mit', sicherung_zweitschrift_schlechteste(array('mit', 'mit')), 'mit');
    $pruef('zweitschrift: leere Menge - fehlt', sicherung_zweitschrift_schlechteste(array()), 'fehlt');
    $pruef('zweitschrift: unbekannte Lage - fehlt', sicherung_zweitschrift_schlechteste(array('mit', 'gleich', 'mit')), 'fehlt');
    $zprot = array();
    $bz = array('log' => function ($z) use (&$zprot) { $zprot[] = $z; }, 'speichern' => function () { return true; }) + $b;
    $bz['zweitschrift'] = function () use ($F) { return array(array($F, $F), array($F, $F)); };
    $r = sicherung_zurueckspielen($bz, $js);
    $m = sicherung_meldungen($bz, $r);
    $pruef('zweitschrift zurueck: zwei Paare gleich - mit, je Paar, keine Warnung', array($r['zweitschrift'],
           isset($r['zweitschrift_paare']) ? $r['zweitschrift_paare'] : 'fehlt', $m['fehler'], strpos(end($zprot), 'Zweitschrift')),
           array('mit', array('mit', 'mit'), array(), false));
    $bz['zweitschrift'] = function () use ($F, $X) { return array(array($F, $F), array($F, $X), array($F, $F)); };
    $r = sicherung_zurueckspielen($bz, $js);
    $m = sicherung_meldungen($bz, $r);
    $pruef('zweitschrift zurueck: ein Paar fehlt - fehlt, Warnung, Protokoll zaehlt die Paare', array($r['zweitschrift'],
           isset($r['zweitschrift_paare']) ? $r['zweitschrift_paare'] : 'fehlt', count($m['fehler']),
           strpos(implode(' ', $m['fehler']), sicherung_t('K_ZWEITSCHRIFT', $bz)) !== false,
           strpos(end($zprot), '; Zweitschrift fehlt (1 von 3 Paaren)') !== false),
           array('fehlt', array('mit', 'fehlt', 'mit'), 1, true, true));
    $pruef('zweitschrift zurueck: kein Pfad im Protokoll', strpos(end($zprot), basename($F)), false);
    $bz['zweitschrift'] = function () { return array(); };
    $r = sicherung_zurueckspielen($bz, $js);
    $pruef('zweitschrift zurueck: leere Liste - fehlt', array($r['zweitschrift'], isset($r['zweitschrift_paare'])),
           array('fehlt', false));
    $bz['zweitschrift'] = function () use ($F, $X) { return array($F, $X); };
    $r = sicherung_zurueckspielen($bz, $js);
    $pruef('zweitschrift zurueck: ein Paar wie bis 1.2.6 - ohne zweitschrift_paare, Protokoll wie bisher',
           array($r['zweitschrift'], isset($r['zweitschrift_paare']), substr(end($zprot), -20)),
           array('fehlt', false, '; Zweitschrift fehlt'));
    $bz['speichern'] = function () { return false; };
    $bz['zweitschrift'] = function () use ($F) { return array(array($F, $F)); };
    $r = sicherung_zurueckspielen($bz, $js);
    $pruef('zweitschrift zurueck: nicht gespeichert - nicht gefragt', array($r['zweitschrift'], isset($r['zweitschrift_paare'])),
           array('', false));

    // --- 1.2.7 (b): Vermerke in Bloecken ('nie_aus_datei' mit Pfad). meldungen nennt den Vermerk im Knoten (mit
    // 'behalten'), mqtt nennt ihn nicht (bis 1.2.6: verworfen), tief traegt ihn im Block im Block. ---
    $GV = array('alexa_token' => 'nachbar');
    $DV = array('google_token' => 'nachbar');
    $gesehen = array();
    $bv = array(
        'kennzeichen' => 'LoxBerry-Plugin-Selbsttest',
        'vorgaben' => array('a' => 1, 'meldungen' => array('ein' => 0, 'herkunft' => array()),
                            'mqtt' => array('server' => ''), 'tief' => array('innen' => array('x' => 1, 'merk' => 'werk')),
                            'liste' => array(), 'frei' => array()),
        'schluessel' => array(
            'a' => array('fehlt' => 'behalten'),
            'meldungen' => sicherung_block(array('ein'), array('herkunft' => array('art' => 'offen', 'fehlt' => 'behalten')))
                           + array('fehlt' => 'behalten'),
            'mqtt' => sicherung_block(array('server')) + array('fehlt' => 'behalten'),
            'tief' => sicherung_block(array(), array('innen' => sicherung_block(array('x', 'merk')))) + array('fehlt' => 'vorgabe'),
            'liste' => sicherung_liste(array('name')) + array('fehlt' => 'behalten'),
            'frei' => sicherung_offen() + array('fehlt' => 'behalten'),
        ),
        'nie_aus_datei' => array('meldungen.herkunft', 'mqtt.merk', 'tief.innen.merk', 'vermerk_oben'),
        'pruefen' => function ($k, $w) use (&$gesehen) {
            if ($k === 'meldungen') {
                $gesehen[] = is_array($w) && array_key_exists('herkunft', $w) ? $w['herkunft'] : 'fehlt';
                return is_array($w) ? '' : 'meldungen kein Block';
            }
            return '';
        },
        't' => function ($s) { return $s . ' [%s]'; },
    );
    $jv = array('a' => 1, 'meldungen' => array('ein' => 0, 'herkunft' => $GV), 'mqtt' => array('server' => 's', 'merk' => 'GELTM'),
                'tief' => array('innen' => array('x' => 1, 'merk' => 'GELTT')), 'liste' => array(), 'frei' => array(),
                'vermerk_oben' => 'GELTO');
    $dv = array('_plugin' => 'LoxBerry-Plugin-Selbsttest', 'a' => 2, 'meldungen' => array('ein' => 1, 'herkunft' => $DV),
                'mqtt' => array('server' => 't', 'merk' => 'DATEIM'), 'tief' => array('innen' => array('x' => 2, 'merk' => 'DATEIT')),
                'liste' => array(), 'frei' => array(), 'vermerk_oben' => 'DATEIO');
    $nur = function ($r) {
        return array($r['verworfen'], $r['fehlt_behalten'], $r['fehlt_vorgabe'], $r['leer_behalten'], $r['jetzt_entfernt']);
    };
    $pruef('vermerk block: Beschreibung ohne Mangel', sicherung_beschreibung_mangel($bv), array());
    $r = sicherung_lesen($bv, json_encode($dv), $jv);
    $pruef('vermerk block: Datei mit anderem Wert - angenommen, der geltende gilt (Knoten nennt ihn)',
           array($r['ok'], $r['neu']['meldungen']['herkunft'], $r['neu']['meldungen']['ein']), array(true, $GV, 1));
    $pruef('vermerk block: Knoten nennt ihn nicht - der geltende gilt, nicht verworfen',
           array($r['neu']['mqtt']['merk'], $r['neu']['mqtt']['server']), array('GELTM', 't'));
    $pruef('vermerk block: Block im Block', array($r['neu']['tief']['innen']['merk'], $r['neu']['tief']['innen']['x']), array('GELTT', 2));
    $pruef('vermerk block: oben unveraendert wie seit 1.2.1', $r['neu']['vermerk_oben'], 'GELTO');
    $pruef('vermerk block: nichts genannt', $nur($r), array(array(), array(), array(), array(), array()));
    $pruef('vermerk block: die Pruefung sieht den geltenden Wert, nicht den der Datei', end($gesehen), $GV);
    $d2v = $dv;
    unset($d2v['meldungen']['herkunft'], $d2v['mqtt']['merk'], $d2v['tief']['innen']['merk']);
    $r = sicherung_lesen($bv, json_encode($d2v), $jv);
    $pruef('vermerk block: Datei ohne Vermerke - der geltende gilt, nichts genannt (1.2.6: herkunft als behalten)',
           array($r['ok'], $r['neu']['meldungen']['herkunft'], $r['neu']['mqtt']['merk'], $r['neu']['tief']['innen']['merk'], $nur($r)),
           array(true, $GV, 'GELTM', 'GELTT', array(array(), array(), array(), array(), array())));
    $jv2 = $jv;
    unset($jv2['meldungen']['herkunft'], $jv2['mqtt']['merk'], $jv2['tief']['innen']['merk']);
    $r = sicherung_lesen($bv, json_encode($dv), $jv2);
    $pruef('vermerk block: nicht geltend - die Vorgabe des Blocks, sonst fehlt er; nie aus der Datei',
           array($r['ok'], $r['neu']['meldungen']['herkunft'], array_key_exists('merk', $r['neu']['mqtt']),
                 $r['neu']['tief']['innen']['merk']), array(true, array(), false, 'werk'));
    $d3v = $dv;
    unset($d3v['meldungen'], $d3v['tief']);
    $r = sicherung_lesen($bv, json_encode($d3v), $jv);
    $pruef('vermerk block: Block fehlt in der Datei (behalten bzw. Vorgabe) - der geltende Vermerk',
           array($r['ok'], $r['neu']['meldungen'], $r['neu']['tief']['innen']['merk'], $r['neu']['tief']['innen']['x'],
                 $r['fehlt_behalten'], $r['fehlt_vorgabe']),
           array(true, $jv['meldungen'], 'GELTT', 1, array('meldungen'), array('tief')));
    $bvb = $bv;
    $bvb['vorbehandeln'] = function ($bereit) {
        if (isset($bereit['meldungen']) && is_array($bereit['meldungen'])) { $bereit['meldungen']['herkunft'] = array('linie' => 1); }
        return $bereit;
    };
    $r = sicherung_lesen($bvb, json_encode($dv), $jv);
    $pruef('vermerk block: vorbehandeln setzt ihn - der Wert der Linie', array($r['ok'], $r['neu']['meldungen']['herkunft'],
           $r['neu']['mqtt']['merk']), array(true, array('linie' => 1), 'GELTM'));
    $jv3 = $jv;
    $jv3['mqtt']['zz_hand'] = 'h';
    $r = sicherung_lesen($bv, json_encode($dv), $jv3);
    $pruef('vermerk block: ein unbekannter Schluessel daneben wird weiter als entfernt genannt (1.2.4)',
           array($r['jetzt_entfernt'], $r['neu']['mqtt']['merk']), array(array('mqtt.zz_hand'), 'GELTM'));
    $sv = sicherung_bauen($bv, $jv);
    $pruef('vermerk block: nie gesichert', array(array_keys($sv['meldungen']), array_keys($sv['mqtt']), array_keys($sv['tief']['innen']),
           array_key_exists('vermerk_oben', $sv), isset($sv['_warnung'])),
           array(array('ein'), array('server'), array('x'), false, false));
    $r = sicherung_lesen($bv, sicherung_json($sv), $jv);
    $pruef('vermerk block: eigene Sicherung zurueck - angenommen, nichts genannt, Vermerke geltend',
           array($r['ok'], $nur($r), $r['neu']['meldungen']['herkunft'], $r['neu']['mqtt']['merk'], $r['neu']['tief']['innen']['merk']),
           array(true, array(array(), array(), array(), array(), array()), $GV, 'GELTM', 'GELTT'));
    $vgesp = array();
    $vprot = array();
    $bvz = $bv + array('speichern' => function ($neu) use (&$vgesp) { $vgesp[] = $neu; return true; },
                       'log' => function ($z) use (&$vprot) { $vprot[] = $z; }, 'jetzt' => function () use ($jv) { return $jv; });
    $r = sicherung_zurueckspielen($bvz, json_encode($dv));
    $m = sicherung_meldungen($bvz, $r);
    $valles = implode("\n", array_merge($m['fehler'], $m['hinweise'], $vprot));
    $pruef('vermerk block zurueck: gespeichert mit den geltenden Vermerken', array($r['gespeichert'], count($vgesp),
           $vgesp[0]['meldungen']['herkunft'], $vgesp[0]['mqtt']['merk'], $vgesp[0]['tief']['innen']['merk']),
           array(true, 1, $GV, 'GELTM', 'GELTT'));
    $pruef('vermerk block zurueck: weder Name noch Wert in Meldung und Protokoll', array(strpos($valles, 'herkunft'),
           strpos($valles, 'merk'), strpos($valles, 'DATEI'), strpos($valles, 'GELT'), strpos($valles, 'nachbar')),
           array(false, false, false, false, false));
    $d4v = $dv;
    $d4v['meldungen'] = 'kein Block';
    $r = sicherung_lesen($bv, json_encode($d4v), $jv);
    $pruef('vermerk block: Block der Datei ist kein Feld - die Pruefung entscheidet, nichts angenommen',
           array($r['ok'], $r['neu'], count($r['beanstandet'])), array(false, null, 1));
    // Beschreibungspruefung: jeder Eintrag mit Punkt ist eine Vorgabe oben oder ein gueltiger Blockpfad (fail closed)
    foreach (array('zz.feld' => 'Block nicht in den Vorgaben', 'a.feld' => 'kein Block (Wert)', 'liste.feld' => 'Liste',
                   'frei.feld' => 'offen', 'meldungen.' => 'leerer Name', '.herkunft' => 'leerer Block',
                   'meldungen..herkunft' => 'leerer Teil', 'tief.y.merk' => 'Zwischenblock unbekannt',
                   'tief.innen.x.y' => 'Zwischenschluessel kein Block', 'meldungen.her kunft' => 'keine Namensform',
                   'zugang.kennwort' => 'Teil') as $x => $grund) {
        $bm = $bv;
        $bm['nie_aus_datei'] = array('meldungen.herkunft', $x);
        $bm['teile'] = array('zugang' => function ($w) { return array($w, array()); });
        $pruef('vermerk block: Mangel - ' . $grund, sicherung_beschreibung_mangel($bm), array('nie_aus_datei'));
    }
    $bm = $bv;
    $bm['nie_aus_datei'] = array('mqtt', 'mqtt.merk');
    $pruef('vermerk block: Mangel - der Block selbst ist ein Vermerk', sicherung_beschreibung_mangel($bm), array('nie_aus_datei'));
    $bm = $bv;
    $bm['teile'] = array('meldungen' => function ($w) { return array($w, array()); });
    $pruef('vermerk block: Mangel - der Block ist zugleich ein Teil', sicherung_beschreibung_mangel($bm), array('nie_aus_datei'));
    $bm = $bv;
    $bm['alt'] = array('mqtt');
    $pruef('vermerk block: Mangel - der Block ist ein frueherer Schluessel', sicherung_beschreibung_mangel($bm), array('nie_aus_datei'));
    $bm = $bv;
    $bm['schluessel']['nur_knoten'] = sicherung_block(array('f'));
    $bm['nie_aus_datei'] = array('nur_knoten.f');
    $pruef('vermerk block: Mangel - Block nur im Knoten, nicht in den Vorgaben', sicherung_beschreibung_mangel($bm),
           array('nie_aus_datei'));
    $bm = $bv;
    $bm['vorgaben']['p.q'] = 1;
    $bm['nie_aus_datei'] = array('p.q', 'unbekannt_oben', 7);
    $pruef('vermerk block: kein Mangel - Vorgabe mit Punkt, Name ohne Punkt, kein Text (alles wie bis 1.2.6)',
           sicherung_beschreibung_mangel($bm), array());
    $bm = $bv;
    $bm['nie_aus_datei'] = array('zz.feld');
    $r = sicherung_lesen($bm, json_encode($dv), $jv);
    $pruef('vermerk block: Mangel weist jede Datei ab (form, K_BESCHREIBUNG mit dem Namen)', array($r['ok'], $r['fremd'],
           strpos($r['beanstandet'][0]['text'], 'K_BESCHREIBUNG') !== false, strpos($r['beanstandet'][0]['text'], 'nie_aus_datei') !== false),
           array(false, 'form', true, true));
    $pruef('vermerk block: Mangel - bauen warnt, statt still zu sichern', array_key_exists('_warnung', sicherung_bauen($bm, $jv)), true);
    $pruef('vermerk block: Pfad als Namensliste', array(sicherung_nie_pfad($bv, 'tief.innen.merk'), sicherung_nie_pfad($bv, 'mqtt.merk'),
           sicherung_nie_pfad($bv, 'vermerk_oben')), array(array('tief', 'innen', 'merk'), array('mqtt', 'merk'), null));
    // ---- 1.2.8: 'fehlt' => 'pflicht' an Unterschluesseln eines Blocks (Regel 18 im Kopf) ----
    $bp = array(
        'kennzeichen' => 'LoxBerry-Plugin-Selbsttest',
        'vorgaben' => array('a' => 1, 'blk' => array('x' => 1, 'y' => 2, 'z' => 3),
                            'tief' => array('innen' => array('u' => 1, 'v' => 2)), 'liste' => array(),
                            'ganz' => array('p' => 1), 'opt' => array('q' => 1)),
        'schluessel' => array(
            'blk' => sicherung_block(array('x'), array('y' => array('art' => 'wert', 'fehlt' => 'pflicht'),
                                                         'z' => array('art' => 'wert', 'fehlt' => 'behalten'))),
            'tief' => sicherung_block(array(), array('innen' => sicherung_block(array('u'),
                                                     array('v' => array('art' => 'wert', 'fehlt' => 'pflicht'))))),
            'liste' => sicherung_liste(array('n'), '', array('m' => array('art' => 'wert', 'fehlt' => 'pflicht'))),
            'ganz' => sicherung_block(array(), array('p' => array('art' => 'wert', 'fehlt' => 'pflicht')))
                      + array('fehlt' => 'behalten'),
            'opt' => sicherung_block(array(), array('q' => array('art' => 'wert', 'fehlt' => 'pflicht')))
                     + array('fehlt' => 'vorgabe'),
        ),
        'pruefen' => function ($k, $w) {
            return ($k === 'blk' && is_array($w) && isset($w['x']) && $w['x'] === 'falsch') ? 'blk.x falsch' : '';
        },
        't' => function ($s) { return $s . ' [%s]'; },
    );
    $jp = array('a' => 1, 'blk' => array('x' => 5, 'y' => 6, 'z' => 7), 'tief' => array('innen' => array('u' => 5, 'v' => 6)),
                'liste' => array(array('n' => 1, 'm' => 1)), 'ganz' => array('p' => 5), 'opt' => array('q' => 5));
    $dp = array('_plugin' => 'LoxBerry-Plugin-Selbsttest', 'a' => 2, 'blk' => array('x' => 8, 'y' => 9, 'z' => 10),
                'tief' => array('innen' => array('u' => 8, 'v' => 9)),
                'liste' => array(array('n' => 2, 'm' => 3), array('n' => 4, 'm' => 5)), 'ganz' => array('p' => 8),
                'opt' => array('q' => 8));
    $pnamen = function ($r) {
        $n = array();
        foreach ($r['beanstandet'] as $x) { $n[] = $x['name']; }
        return $n;
    };
    $pnur = function ($r) {
        return array($r['verworfen'], $r['fehlt_behalten'], $r['fehlt_vorgabe'], $r['fehlt_uebergang'], $r['leer_behalten']);
    };
    $pruef('pflicht block: Beschreibung ohne Mangel', sicherung_beschreibung_mangel($bp), array());
    $r = sicherung_lesen($bp, json_encode($dp), $jp);
    $pruef('pflicht block: Datei mit allen Pflichtschluesseln - angenommen, nichts genannt',
           array($r['ok'], $r['neu']['blk'], $r['neu']['tief'], $pnur($r)),
           array(true, array('x' => 8, 'y' => 9, 'z' => 10), array('innen' => array('u' => 8, 'v' => 9)),
                 array(array(), array(), array(), array(), array())));
    $d = $dp;
    unset($d['blk']['y']);
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: Unterschluessel fehlt - abgewiesen, Name block.feld', array($r['ok'], $r['neu'], $pnamen($r)),
           array(false, null, array('blk.y')));
    $pruef('pflicht block: Satz K_FEHLEND wie oben', strpos($r['beanstandet'][0]['text'], 'K_FEHLEND') !== false, true);
    $bpt = array('t' => function ($s) { return $s === 'SICHERUNG.K_FEHLEND' ? '%d fehlen: %s' : $s . ' [%s]'; }) + $bp;
    $r = sicherung_lesen($bpt, json_encode($d), $jp);
    $m = sicherung_meldungen($bpt, $r);
    $pruef('pflicht block: die Meldung nennt den Namen', array(count($m['fehler']), strpos($m['fehler'][0], 'blk.y') !== false),
           array(1, true));
    $d = $dp;
    unset($d['a'], $d['blk']['y'], $d['tief']['innen']['v'], $d['liste'][1]['m']);
    $d['blk']['x'] = 'falsch';
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: alles gesammelt - oben, Block, Block im Block, Liste, dazu ein falscher Wert',
           array($r['ok'], $pnamen($r)), array(false, array('blk', 'a,blk.y,tief.innen.v,liste[2].m')));
    $d = $dp;
    unset($d['blk']['z']);
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: daneben behalten wie in 1.2.7', array($r['ok'], $r['neu']['blk']['z'], $r['fehlt_behalten']),
           array(true, 7, array('blk.z')));
    $d = $dp;
    unset($d['ganz'], $d['opt']);
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: ganzer Block fehlt - die Angabe des Blocks entscheidet (behalten, Vorgabe)',
           array($r['ok'], $r['neu']['ganz'], $r['neu']['opt'], $r['fehlt_behalten'], $r['fehlt_vorgabe'], $pnamen($r)),
           array(true, array('p' => 5), array('q' => 1), array('ganz'), array('opt'), array()));
    $d = $dp;
    unset($d['blk']);
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: Block mit Pflicht ab Werk fehlt - genannt der Block, nicht seine Unterschluessel',
           array($r['ok'], $pnamen($r)), array(false, array('blk')));
    $d = $dp;
    unset($d['tief']['innen']);
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: Block im Block fehlt ganz (ohne Angabe) - wie 1.2.7, nicht einzeln verlangt',
           array($r['ok'], $r['neu']['tief'], $pnamen($r)), array(true, array(), array()));
    $d = $dp;
    unset($d['tief']['innen']['v']);
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: Block im Block - Unterschluessel fehlt', array($r['ok'], $pnamen($r)),
           array(false, array('tief.innen.v')));
    $d = $dp;
    unset($d['liste'][1]['m']);
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: Liste - im zweiten Eintrag fehlt das Feld', array($r['ok'], $pnamen($r)),
           array(false, array('liste[2].m')));
    $d = $dp;
    $d['liste'] = array();
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: Liste leer - nichts verlangt', array($r['ok'], $r['neu']['liste']), array(true, array()));
    $bpv = $bp;
    $bpv['vorbehandeln'] = function ($bereit) {
        if (isset($bereit['blk']) && is_array($bereit['blk']) && !array_key_exists('y', $bereit['blk'])) { $bereit['blk']['y'] = 42; }
        return $bereit;
    };
    $d = $dp;
    unset($d['blk']['y']);
    $r = sicherung_lesen($bpv, json_encode($d), $jp);
    $m = sicherung_meldungen($bpv, $r);
    $pruef('pflicht block: vorbehandeln setzt ihn - Uebergang, der Wert der Linie, genannt',
           array($r['ok'], $r['neu']['blk']['y'], $r['fehlt_uebergang'], $r['fehlt_behalten'],
                 count(preg_grep('/K_FEHLT_UEBERGANG.*blk\.y/', $m['hinweise']))),
           array(true, 42, array('blk.y'), array(), 1));
    $bpo = $bp;
    $bpo['vorbehandeln'] = function ($bereit) { $bereit['a'] = 3; return $bereit; };
    $d = $dp;
    unset($d['a']);
    $r = sicherung_lesen($bpo, json_encode($d), $jp);
    $pruef('pflicht block: oben seit 1.2.9 ebenso Uebergang, wenn vorbehandeln den Schluessel setzt (bis 1.2.8 abgewiesen)',
           array($r['ok'], $pnamen($r), isset($r['neu']['a']) ? $r['neu']['a'] : null, $r['fehlt_uebergang']), array(true, array(), 3, array('a')));
    $d = $dp;
    $d['blk'] = 'kein Block';
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: Block der Datei ist kein Feld - er traegt den Pflichtschluessel nicht', array($r['ok'], $pnamen($r)),
           array(false, array('blk.y')));
    $d = $dp;
    $d['blk'] = '';
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: Block leer in der Datei - ersetzte den Block (Regel 3 nur fuer Einzelwerte), traegt ihn nicht',
           array($r['ok'], $pnamen($r)), array(false, array('blk.y')));
    $d = $dp;
    $d['tief']['innen'] = '';
    $r = sicherung_lesen($bp, json_encode($d), $jp);
    $pruef('pflicht block: Block im Block leer in der Datei - traegt ihn nicht', array($r['ok'], $pnamen($r)),
           array(false, array('tief.innen.v')));
    $bp0 = $bp;
    $bp0['schluessel']['blk'] = sicherung_block(array('x', 'y'), array('z' => array('art' => 'wert', 'fehlt' => 'behalten')));
    $d = $dp;
    unset($d['blk']['y']);
    $r = sicherung_lesen($bp0, json_encode($d), $jp);
    $pruef('pflicht block: ohne die Angabe wie 1.2.7 - fehlt still', array($r['ok'], $r['neu']['blk'], $pnur($r)),
           array(true, array('x' => 8, 'z' => 10), array(array(), array(), array(), array(), array())));
    $pgesp = array();
    $pprot = array();
    $bpz = $bp + array('speichern' => function ($neu) use (&$pgesp) { $pgesp[] = $neu; return true; },
                       'log' => function ($z) use (&$pprot) { $pprot[] = $z; }, 'jetzt' => function () use ($jp) { return $jp; });
    $d = $dp;
    unset($d['blk']['y']);
    $r = sicherung_zurueckspielen($bpz, json_encode($d));
    $pruef('pflicht block zurueck: nichts gespeichert, Protokoll nennt den Namen', array($r['ok'], $r['gespeichert'],
           count($pgesp), strpos(end($pprot), 'abgewiesen') !== false, strpos(end($pprot), 'blk.y') !== false),
           array(false, false, 0, true, true));
    $jp2 = $jp;
    unset($jp2['blk']['y']);
    $pruef('pflicht block: bauen warnt, wenn die geltende Konfiguration den Pflichtschluessel nicht traegt',
           array(array_key_exists('_warnung', sicherung_bauen($bp, $jp2)), array_key_exists('_warnung', sicherung_bauen($bp, $jp))),
           array(true, false));
    $r = sicherung_lesen($bp, sicherung_json(sicherung_bauen($bp, $jp)), $jp);
    $pruef('pflicht block: eigene Sicherung zurueck - angenommen, nichts genannt', array($r['ok'], $pnur($r)),
           array(true, array(array(), array(), array(), array(), array())));
    // Beschreibungspruefung: unterhalb der obersten Ebene nur 'behalten' oder 'pflicht' (fail closed)
    foreach (array('pflict' => 'Tippfehler', 'vorgabe' => 'vorgabe im Block', 'PFLICHT' => 'Grossschreibung',
                   '' => 'leer') as $f => $grund) {
        $bm = $bp;
        $bm['schluessel']['blk']['schluessel']['y']['fehlt'] = $f;
        $pruef('pflicht block: Mangel - ' . $grund, sicherung_beschreibung_mangel($bm), array('fehlt'));
    }
    $bm = $bp;
    $bm['schluessel']['blk']['schluessel']['y']['fehlt'] = true;
    $pruef('pflicht block: Mangel - kein Text', sicherung_beschreibung_mangel($bm), array('fehlt'));
    $bm = $bp;
    $bm['schluessel']['blk']['schluessel']['y']['fehlt'] = null;
    $pruef('pflicht block: kein Mangel - null gilt als nicht angegeben', sicherung_beschreibung_mangel($bm), array());
    $bm = $bp;
    $bm['schluessel']['tief']['schluessel']['innen']['schluessel']['v']['fehlt'] = 'vorgabe';
    $pruef('pflicht block: Mangel - im Block im Block', sicherung_beschreibung_mangel($bm), array('fehlt'));
    $bm = $bp;
    $bm['schluessel']['liste']['eintrag']['schluessel']['m']['fehlt'] = 'pflict';
    $pruef('pflicht block: Mangel - im Eintrag einer Liste', sicherung_beschreibung_mangel($bm), array('fehlt'));
    $bm = $bp;
    $bm['teile'] = array('zug' => function ($w) { return array($w, array()); });
    $bm['schluessel']['zug'] = sicherung_block(array('n'), array('k' => array('art' => 'wert', 'fehlt' => 'behalten')));
    $pruef('pflicht block: kein Mangel - behalten unter einem Teil (wie 1.2.7)', sicherung_beschreibung_mangel($bm), array());
    $bm['schluessel']['zug']['schluessel']['k']['fehlt'] = 'pflicht';
    $pruef('pflicht block: Mangel - pflicht unter einem Teil', sicherung_beschreibung_mangel($bm), array('fehlt'));
    $bm['teile_flach'] = array('zug' => 'zug_');
    $pruef('pflicht block: Mangel - pflicht unter einem flachen Teil', sicherung_beschreibung_mangel($bm), array('fehlt'));
    $bm = $bp;
    $bm['alt'] = array('blk');
    $pruef('pflicht block: Mangel - pflicht unter einem frueheren Schluessel', sicherung_beschreibung_mangel($bm), array('fehlt'));
    $bm = $bp;
    $bm['nie_aus_datei'] = array('blk');
    $pruef('pflicht block: Mangel - pflicht unter einem Vermerk oben', sicherung_beschreibung_mangel($bm), array('fehlt'));
    $bm = $bp;
    $bm['nie_aus_datei'] = array('blk.y');
    $pruef('pflicht block: Mangel - ein Vermerk im Block ist Pflicht', sicherung_beschreibung_mangel($bm), array('fehlt'));
    $bm = $bp;
    $bm['nie_aus_datei'] = array('tief.innen.v');
    $pruef('pflicht block: Mangel - ein Vermerk im Block im Block ist Pflicht', sicherung_beschreibung_mangel($bm), array('fehlt'));
    $bm = $bp;
    $bm['nie_aus_datei'] = array('blk.z');
    $pruef('pflicht block: kein Mangel - Vermerk mit behalten im Knoten (wie 1.2.7)', sicherung_beschreibung_mangel($bm), array());
    $bm = $bp;
    $bm['schluessel']['a'] = array('art' => 'wert', 'fehlt' => 'pflict');
    $pruef('pflicht block: kein Mangel - oben bleibt fehlt ungeprueft (wirkt als pflicht)', sicherung_beschreibung_mangel($bm),
           array());
    $bm = $bp;
    $bm['schluessel']['blk']['schluessel']['y']['fehlt'] = 'vorgabe';
    $r = sicherung_lesen($bm, json_encode($dp), $jp);
    $pruef('pflicht block: Mangel weist jede Datei ab (form, K_BESCHREIBUNG mit dem Namen)', array($r['ok'], $r['fremd'],
           strpos($r['beanstandet'][0]['text'], 'K_BESCHREIBUNG') !== false, strpos($r['beanstandet'][0]['text'], 'fehlt') !== false),
           array(false, 'form', true, true));
    $pruef('pflicht block: Mangel - bauen warnt, statt still zu sichern', array_key_exists('_warnung', sicherung_bauen($bm, $jp)),
           true);
    return array($faelle, $fehl, $zeilen);
}

}   // Ende: if (!defined('SICHERUNG_FASSUNG'))

/* Kommandozeile: php sicherung.php --selbsttest (nur, wenn diese Datei selbst gestartet wurde). */
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && isset($argv[0])
    && realpath($argv[0]) === realpath(__FILE__) && in_array('--selbsttest', $argv, true)) {
    list($sicherung_n, $sicherung_f, $sicherung_z) = sicherung_selbsttest();
    foreach ($sicherung_z as $sicherung_zz) { echo $sicherung_zz, "\n"; }
    echo 'Rechenkern sicherung.php ' . SICHERUNG_FASSUNG . ': ' . $sicherung_n . ' Faelle geprueft, ' . $sicherung_f . " Fehlschlaege.\n";
    exit($sicherung_f === 0 ? 0 : 1);
}
