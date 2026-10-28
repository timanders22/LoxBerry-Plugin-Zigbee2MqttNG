# Zigbee2MqttNG – LoxBerry-Plugin

Zigbee2MqttNG bringt [Zigbee2MQTT](https://www.zigbee2mqtt.io/) als Plugin auf den LoxBerry und ist auf das
Zusammenspiel mit anderen Plugins ausgelegt – insbesondere mit
[Matter2Lox (Matter to Loxone)](https://github.com/timanders22/LoxBerry-Plugin-Matter2Lox).

Zigbee2MqttNG ist ein Fork des Plugins [Zigbee2Mqtt](https://github.com/romanlum/LoxBerry-Plugin-Zigbee2Mqtt) (Apache-2.0, siehe `LICENSE`).
Es ist ein **eigenständiges Plugin** (Name/Ordner `zigbee2mqttng`, Dienst `zigbee2mqttng`, Installation in `/opt/zigbee2mqttng`)
und kollidiert deshalb nicht mit dem Original. Bis Version 4.0.0 hieß das Plugin **Zigbee2Lox**.

## Neu in 4.2.5

Netzschutz, sichereres Update und neue Auskünfte für andere Plugins (Gruppe 38).

* **Neuer Bezugsweg:** Das Update kommt jetzt als Tag-Archiv der Release-Seite – es gibt kein `plugin.zip` mehr.
  Wer von Hand installiert, nimmt auf der Release-Seite „Source code (zip)“ oder das Tag-Archiv. Bestehende
  Installationen finden das Update wie bisher von selbst.
* **Netzschutz:** Die Zweitschrift der `configuration.yaml` nimmt nie mehr `GENERATE` auf, und solange
  `coordinator_backup.json` da ist, schreibt das Plugin eine Zweitschrift mit `GENERATE` nie zurück – es sperrt den
  Start mit klarer Meldung, statt ein neues Zigbee-Netz anzulegen. Ein Wechsel von `pan_id` von einer Zahl auf Text
  gilt als Verlust.
* **Update:** Einstellungen und Netzdaten werden mit Punktdateien und Zeitstempeln zurückkopiert und geprüft; die
  Sicherung unter `/tmp` bleibt bei einem Fehler liegen, und zigbee2mqtt startet dann nicht. Ein vor dem Update
  angehaltener Dienst bleibt angehalten. Apache wird nur neu geladen, wenn `php-yaml` frisch kam – kein
  Neustart-Hinweis mehr.
* **Haus-Themen:** neben `haus/tuer` jetzt `haus/klima`, `haus/wetter` und `haus/alarm` (je eigener Haken, ab Werk
  aus), mit Lebenszeichen `haus/<familie>/zaehler/zigbee2mqttng` und Abräumen mit Rücklesen.
* **Meldungen** per Signal (SignalBot) und Sprache (gemeinsame Sprachausgabe), je Anlass wählbar, mit Ruhezeit.
* **Firmware-Anzeige:** Spalte „Firmware“ im Reiter Geräte, `…/update_verfuegbar` je Gerät mit OTA und
  `…/zigbee2mqttng/updates` für Loxone (Gateway V2: anhaken). Eingespielt wird in der Zigbee2MQTT-Oberfläche.
* **Kanalwahl:** ein Hinweissatz, welcher der Kanäle 15, 20 und 25 zu WLAN und Thread passt – kein Knopf.
* Kleinere Dinge: `devices.yaml` wird unteilbar geschrieben, die Abfrage an zigbee2mqtt bricht ab, wenn der Broker
  verschwindet, Sicherungen lassen sich nicht von fremden Seiten abrufen, die Formulare zeigen nach dem
  Zurückspielen die neuen Werte, die Fassung des Plugins steht im Reiter Test.

## Neu in 4.2.4

Umstellung auf MQTT nach der Hausform (Gruppe 5): Werte in einfache Virtuelle Eingänge, Hakenliste für
Gateway V2, Befehle weiter direkt an zigbee2mqtt.

* **Hakenliste für Gateway V2** im Reiter „Einbindung in Loxone“, Schritt 2: je Gerät das Abo mit den
  JSON-Feldern, erreichbar und je Tasterwert `…/aktion/<wert>` mit **Reset** – ohne Reset wirkt ein Taster
  unter V2 nur beim ersten Druck. Für die zwei Musterseiten 11 Datenpunkte auf 7 Abos. Kein Datenpunkt
  braucht „No Cache“.
* **Namenstabelle** mit Kopierknopf (Schritt 3): je Wert ein einfacher Virtueller Eingang mit dem Namen, den
  das Gateway vergibt. Die Vorlagen (virtueller HTTP- bzw. UDP-Eingang) stehen zugeklappt unter „Bestehende
  Anlagen“.
* **Gateway-Name mit Leerzeichen:** Plugin und Erweiterung ersetzen jetzt wie das Gateway auch Leerzeichen
  durch `_` (bisher nur `/` und `%`). Für Geräte mit Leerzeichen im Namen stimmen Tabelle, Vorlage und
  `mqtt_resetaftersend.cfg` damit erst jetzt; für Namen ohne Leerzeichen ändert sich nichts.
* **Befehle in der publish-Form:** die Ausgangsvorlage schreibt `publish zigbee2mqtt/<Gerät>/set/<Eigenschaft>
  <Wert>` wie die anderen Plugins; nur bei einem Leerzeichen in Thema oder Wert bleibt die JSON-Form. Wirkt
  nur bei einem neuen Import. Befehle gehen weiter direkt an zigbee2mqtt (keine Befehlsbrücke): zigbee2mqtt
  nimmt sie von jedem an, der am Broker schreiben darf – das Plugin kann das nicht abschalten.
* **Nach dem Deinstallieren des Original-Plugins Zigbee2Mqtt** Zigbee2MqttNG einmal neu darüber installieren
  (Upgrade-Weg, Daten bleiben): LoxBerry löscht beim Deinstallieren des Originals auch das
  Deinstallationsskript dieses Plugins. Der Reiter Test prüft es („Ist das Deinstallationsskript da?“).
* Musterseiten wortgleich zur Liste: Virtuelle Eingänge statt des HTTP-Behälters, die drei Befehle am
  gemeinsamen Ausgang „LoxBerry MQTT-Gateway“ in der publish-Form. Die Gerätewerte kommen unverändert von
  zigbee2mqtt (kein −1). Gerendert unter PHP 7.4, 8.4 und 8.5; nicht am Gerät gemessen.

## Neu in 4.2.3

Einbindung in Loxone in Vollausstattung: zwei Seiten aus dem LoxBerry-Plugins Musterprojekt mit Bauanleitung
und zwei Bildern; die Ausgangsvorlage kann jetzt umschalten.

* Schritt 6 des Reiters „Einbindung in Loxone“ zeigt statt einer Tabelle mit 12 Zeilen zwei Seiten, wortgleich
  mit der Liste des [LoxBerry-Plugins Musterprojekts](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt):
  **„Zigbee2MqttNG“** (15 Zeilen: Lebenszeichen und zwei überwachte Geräte, Status „Zigbee-Zustand“ in
  Klartext mit Temperatur, Feuchte und Batterie) und **„Zigbee2MqttNG Geräte“** (18 Zeilen: Taster schaltet
  eine Zigbee-Steckdose um, die Steckdose folgt der Anwesenheit, Fensterkontakt meldet bei Abwesenheit,
  Helligkeit einer Zigbee-Lampe aus der App). Je Seite ein Kasten „So übernehmen Sie diese Seite“, Hinweise
  und ein Bild. Die bisherigen Beispielzeilen Lichtsteuerung und Alarmanlage entfallen.
* **Umschaltbefehl:** die Vorlage „Alle Ausgänge (virtueller Ausgang)“ enthält je Schalter, den zigbee2mqtt
  mit `value_toggle` beschreibt, zusätzlich den Befehl `ZIGBEE_<GERÄT>_<WERT>_TOGGLE` (Befehl bei EIN
  `TOGGLE`, bei AUS leer). zigbee2mqtt schaltet damit am echten Zustand des Geräts um. Die übrigen Befehle
  bleiben gleich. Wer die Vorlage schon importiert hat, legt den einen Befehl von Hand an (Kasten der Seite
  „Zigbee2MqttNG Geräte“).
* **Zahlenbefehle** schicken den Wert mit `<v.0>` statt `<v>` (ganzzahlig, z. B. die Helligkeit 0–254).
  Schon importiert: beim Befehl `<v>` durch `<v.0>` ersetzen.
* **Taster-Eingänge** (`…_aktion_<aktion>`) in den Eingangsvorlagen mit MaxVal 2147483647 statt 1: der
  Zähler je Taste wächst bis zum nächsten Neustart von zigbee2mqtt, und ein Wert über MaxVal wird in Loxone
  zu 0. Ob der Miniserver das auch bei Werten aus dem Gateway tut, ist nicht gemessen. Schon importiert:
  MaxVal am Eingang von Hand setzen.
* Textberichtigungen der Tabelle: Parameter von #2 ohne die Namen aus der Projektdatei, #2 En = #8 (die
  Konstante 1 ist jetzt eine eigene Zeile); #1/#3 als Virtueller HTTP-Eingang, #7 mit Meldetext; Gegenprobe Schritt 7 eine Zeile
  mehr (Taster).
* Gerendert unter PHP 7.4, 8.4 und 8.5 (deutsch und englisch), Vorlagen an einer Attrappe erzeugt; nicht am
  Gerät angesehen.

## Neu in 4.2.2

Einbindung in Loxone: Eingang En der Analogwertvalidierung nicht negieren (Hinweis und Bild); ohne diese
Korrektur löste die Ausfallmeldung nie aus. Wer die Seite schon gebaut hat: Haken bei ‚En‘ entfernen.

* Config legt den Eingang En der Analogwertvalidierung negiert an (Haken bei ‚En‘ im Eigenschaftsfenster
  unter Parameter). Die Konstante 1 kam dadurch als 0 an, die Validierung prüfte die Zeit Tmc nicht.
  Der Reiter „Einbindung in Loxone“ sagt das jetzt unter der Baustein-Liste bei Zeile 2; der Hinweis
  zu #2 ist berichtigt (unbeschaltet steht En auf EIN).
* Das Bild der Seite „Zigbee2MqttNG“ aus dem
  [LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt)
  ist neu: En ohne Negation.
* Baustein-Liste unverändert.
* Gerendert unter PHP 7.4, 8.4 und 8.5 (deutsch und englisch), nicht am Gerät angesehen.

## Neu in 4.2.1

Reiter „Einbindung in Loxone“ zeigt ein Bild der Bausteine aus dem gemeinsamen Musterprojekt und
verlinkt die Projektdatei.

* Unter der Baustein-Liste (Schritt 6) steht das Bild der Seite „Zigbee2MqttNG“ aus dem
  [LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt); das Bild liegt im Plugin,
  nachgeladen wird nichts. Config kürzt lange Bausteinnamen, die vollen Namen stehen in der Tabelle.
* Baustein-Liste unverändert.
* Gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 4.2.0

* **Eine Seite mit grünen Reitern** wie in den übrigen Plugins des Hauses, statt der LoxBerry-Navigationsleiste
  mit sechs Einzelseiten. Über den Reitern steht eine **Statusübersicht** (Dienst zigbee2mqttng, Koordinator,
  erreichbare Geräte, MQTT-Weg), oben im Reiter Einstellungen ein Kasten mit der Kurzbeschreibung des Plugins.
* Alte Adressen und Lesezeichen (`devices.php`, `mqtt.php`, `ui.php`, `loxone.php`, `test.php`, `log.php`,
  `backup.php`) leiten auf den passenden Reiter um.
* Knopffarben, Legende und Tabellen nach dem Hausstandard; die Auswahlfelder zeigen einen Pfeil. Die Formulare
  (Einstellungen, MQTT) stehen in der Hausform: Beschriftung über dem Feld, Hilfe darunter, kleine
  Kontrollkästchen. Das Token der Zigbee2mqtt UI erscheint maskiert, ein Knopf deckt es zum Kopieren auf.
* Der Reiter Test prüft zusätzlich, ob Reiterleiste, Bereiche und Liste der Reiter zusammenpassen.
* **`erreichbar` kommt nach dem Anlernen in Loxone an.** Ändert sich die Geräteliste, schreibt das Plugin die
  Abo-Liste des MQTT-Gateways neu; das Gateway meldet dabei alle Abos ab und einige Sekunden später wieder an.
  Die Meldung `erreichbar` = 1 beim Anlernen fiel in diese Lücke, und in Loxone blieb 0 stehen. Jetzt wird der
  Stand aller Geräte 30 s nach der letzten Änderung und zusätzlich alle 15 Minuten erneut gesendet (nicht
  retained).
* Die Funktionen sind unverändert: Speichern, Anlernen, Geräteliste, Netzwerkkarte, `devices.yaml`, Sicherung,
  Einbindung in Loxone, Test und Logdateien arbeiten wie in 4.1.1; die Zigbee2mqtt UI ist eine Ansicht im Reiter
  Geräte. Jeder Reiterwechsel lädt die Seite neu – aufgebaut wird nur der offene Reiter.

## Neu in 4.1.1

* **Konfiguration geht nicht mehr verloren.** Ist `mqtt.json`, `service.json` oder `configuration.yaml` vorhanden, aber
  nicht lesbar (etwa nach einem Stromausfall beim Schreiben), schreibt das Plugin sie nicht mehr mit Werkseinstellungen
  neu. Bis 4.1.0 geschah das vor jedem Start – bei `configuration.yaml` samt Verlust des Netzwerkschlüssels. Jetzt
  bleibt die beschädigte Datei als `….kaputt` liegen, wird aus ihrer Zweitschrift wiederhergestellt, und gibt es
  keine lesbare Zweitschrift, startet zigbee2mqtt nicht, bis die Datei lesbar ist. Einstellungsseite, Reiter Test
  und eine LoxBerry-Benachrichtigung sagen es.
* **Zweitschriften** von `mqtt.json`, `service.json`, `configuration.yaml` und `coordinator_backup.json` liegen neben
  dem Konfigurationsordner (`config/plugins/zigbee2mqttng.backup.*`) und überstehen jedes Update.
* **Update bricht ab, statt das Netz zu verlieren:** `preupgrade.sh` prüft die Kopie der Daten Byte für Byte und
  bricht das Update ab, wenn sie unvollständig ist (vorher lief es weiter).
* **Dateien mit Zugangsdaten nur für loxberry (0600)** – Broker-Kennwort, Token der Oberfläche, Netzwerkschlüssel; alle
  Schreibwege schreiben unteilbar.
* **Einstellungen sichern / zurückspielen** im Reiter Einstellungen: eine JSON-Datei mit allen Einstellungen samt
  Zugangsdaten; beim Zurückspielen wird jeder Wert geprüft, eine halb gültige oder fremde Datei ändert nichts.
  Dazu die Anleitung, wie die ZIP-Sicherung von zigbee2mqtt zurückgespielt wird.
* **Dienst starten, neu starten, anhalten** im Reiter Einstellungen; „Speichern und aktualisieren“ meldet, ob
  zigbee2mqtt danach wirklich läuft (bis 4.1.0 stand dort immer „Erfolgreich gespeichert“).
* **Lebenszeichen für Loxone:** `<topic>/zigbee2mqttng/ts` (Unix-Sekunden) und `<topic>/zigbee2mqttng/zaehler`
  (0…999), jede Minute, nie retained. Stirbt zigbee2mqtt, bleiben Gerätewerte und `erreichbar` in Loxone stehen –
  nur das Lebenszeichen nicht.
* **Reiter „Einbindung in Loxone“ in sieben Schritten** mit Baustein-Liste und Ausfallerkennung; neue Vorlage
  **virtueller HTTP-Eingang** für das MQTT-Gateway im HTTP-Betrieb; in allen Vorlagen kurze Kachelnamen
  (`Gerät: Wert`, höchstens 40 Zeichen) und Grenzen je Einheit. Titel und Befehle der bisherigen Vorlagen bleiben
  gleich.
* **Deinstallation:** hält zigbee2mqtt mit Zeitgrenze an, räumt die zurückbehaltenen Themen von zigbee2mqtt nur, wenn
  kein Vorgänger dasselbe Topic nutzt, warnt vor einem bei der Übernahme abgeschalteten Vorgänger und entfernt die
  Zweitschriften.
* Kleinere Berichtigungen: Installer-Ausgabe (leere Zeilen „Installation folder“/„Plugin version“), Hinweis auf die
  RAM-Disk im Reiter Logdateien, Knopffarben mit Legende, Wertprüfung ohne stilles Umdeuten (Kanal „15.7“ wurde
  15), Archiv ohne `Dockerfile-php-build`.

* **Deinstallieren des Original-Plugins entfernt Zigbee2MqttNG nicht mehr.** LoxBerry ruft beim Deinstallieren
  jedes Skript auf, dessen Name mit dem Plugin-Namen beginnt – `zigbee2mqtt` trifft also auch `zigbee2mqttng`.
  Bis 4.1.0 entfernte die Deinstallation des Originals deshalb Dienst und Programmordner von Zigbee2MqttNG
  (am LoxBerry gemessen; die Daten blieben, eine erneute Installation von 4.1.0 stellte alles wieder her).
  Jetzt prüft das Skript Namen und Ordner und tut für ein anderes Plugin nichts. **Wer noch 4.1.0 hat:** erst auf
  4.1.1 aktualisieren, dann das Original deinstallieren.

## Umstieg vom Original-Plugin oder von Zigbee2Lox

Bei der Erstinstallation übernimmt Zigbee2MqttNG automatisch das Zigbee-Netz des Vorgängers – zuerst von
Zigbee2Lox, sonst vom Original-Plugin Zigbee2Mqtt (`configuration.yaml`, Datenbank, Netzwerkschlüssel,
`devices.yaml`, `groups.yaml` sowie die MQTT- und Dienst-Einstellungen). Kein Gerät muss neu angelernt werden,
das MQTT-Topic bleibt gleich. Der Dienst des Vorgängers (`zigbee2lox` bzw. `zigbee2mqtt`) wird dabei gestoppt
und deaktiviert, seine automatischen Updates werden abgeschaltet und seine Abos beim MQTT Gateway geleert – sonst
bliebe z. B. `zigbee2mqtt/#` des Originals registriert, bis es deinstalliert ist.

Zigbee2Lox 4.0.0 holt seine Updates aus diesem Repository. Sein automatisches Update installiert deshalb
Zigbee2MqttNG als **neues** Plugin daneben, das dann wie oben das Netz übernimmt. Die Haus-Themen gehen dabei auf
Zigbee2MqttNG über, und die automatischen Updates von Zigbee2Lox werden abgeschaltet, damit es Zigbee2MqttNG
nicht jede Nacht erneut installiert.

**Danach bitte den Vorgänger deinstallieren.** Ein Update des Vorgängers würde seinen Dienst sonst wieder
starten, und zwei Dienste können nicht denselben Zigbee-Adapter benutzen. Die Einstellungsseite warnt, solange
ein Vorgänger noch installiert ist.

## Reiter

Die Oberfläche ist eine Seite mit grünen Reitern nach dem Hausstandard: **Einstellungen**, **Geräte** (wie
„Geräte anlernen“ bei Matter2Lox), **MQTT**, **Einbindung in Loxone**, **Test**, **Logdateien**. Darüber steht
eine Statusübersicht: Dienst, Koordinator, erreichbare Geräte, MQTT.

| Reiter | Inhalt |
|---|---|
| Einstellungen | Koordinator-Vorlage, Adapter-Pfad mit Verbindungstest, Adapter-Typ, Funkkanal (mit Thread- und WLAN-Kanal), Erreichbarkeit, Zigbee2mqtt UI mit Token, Benachrichtigungen, Dienst starten/neu starten/anhalten, Einstellungen sichern/zurückspielen, Sicherung des Netzes als ZIP |
| Geräte | Anlernen für 254 s, Liste aller Geräte mit letzten Werten und Erreichbarkeit, Netzwerkkarte, Zigbee2mqtt UI, `devices.yaml` |
| MQTT | Broker, Topic, Weiterleitung an den Miniserver, Haus-Themen |
| Einbindung in Loxone | sieben Schritte: Weg, Abo, Eingänge (Vorlagen HTTP und UDP), Befehle, Ausfallerkennung, Baustein-Listen zweier Seiten aus dem Musterprojekt (Ausfall; Geräte) mit Bauanleitung und Bild, Gegenprobe |
| Test | prüft die ganze Kette ohne Loxone (Dienst, Broker, Erweiterung, Lebenszeichen, Adapter, Funk, Gateway, Haus-Themen, Konfiguration, Zweitschriften, Rechte, Vorlagen) |
| Logdateien | Plugin-Protokolle und `zigbee2mqtt.log` |

## Was Zigbee2MqttNG zusätzlich kann

### USB-Adapter mit festem Pfad
Die Einstellungsseite listet alle Adapter unter `/dev/serial/by-id/` und warnt bei Namen wie `/dev/ttyACM0`.
Diese können sich bei jedem Neustart vertauschen, sobald ein zweiter Stick steckt – etwa ein Thread-Stick für den
Border-Router von Matter2Lox. Ein Klick übernimmt den festen Pfad. Der Reiter Test zeigt, welcher Prozess den
Adapter offen hat.

### Funkkanäle: Zigbee, Thread und WLAN
Zigbee und Thread funken im selben 2,4-GHz-Band mit denselben Kanalnummern (11–26), WLAN liegt im selben Band.
Der Zigbee-Kanal lässt sich einstellen; daneben zeigt das Plugin den Thread-Kanal aus dem Dataset von Matter2Lox
(oder eines OpenThread-Border-Routers auf Port 8081) und den WLAN-Kanal des LoxBerry und warnt bei Überschneidung.
Achtung: Nach einem Kanalwechsel müssen alle Zigbee-Geräte neu angelernt werden.

### Weniger Last am MQTT Gateway
Standardmäßig registriert Zigbee2MqttNG beim MQTT Gateway nur die Zustands-Themen der Geräte und Gruppen
(plus `bridge/state`, `erreichbar`, Taster) statt `<topic>/#`. Die großen `bridge/*`-Nachrichten (Geräteliste,
Logging) erreichen den Miniserver nicht mehr. Die Liste wird automatisch nachgeführt, wenn Geräte dazukommen,
gehen oder umbenannt werden. Wer das alte Verhalten braucht, stellt „An den Miniserver weiterleiten“ auf „alles“.

### Erreichbarkeit: `<topic>/<gerät>/erreichbar`
1 erreichbar, 0 nicht – derselbe Name wie bei Matter2Lox. Bis 4.0.0 wandelte das Plugin `online`/`offline` über
`mqtt_conversions.cfg` um; das Gateway wendet solche Umwandlungen aber auf die Werte **aller** Plugins an. Deshalb
sendet die Erweiterung jetzt einen eigenen Wert. Wer in Loxone `…_availability_state` nutzt, stellt auf
`…_erreichbar` um.

### Taster: `<topic>/<gerät>/aktion/<aktion>`
Jeder Tastendruck (`single`, `double`, `hold`, …) zählt einen Zähler hoch, das Gateway setzt ihn nach dem Senden
auf 0 zurück (`mqtt_resetaftersend.cfg`). In Loxone kommt so je Druck ein Impuls an – auch beim zweiten gleichen
Druck hintereinander.

### Haus-Themen für andere Plugins (optional)
Andere Plugins lesen Werte des Hauses, ohne Zigbee zu kennen. Jede Familie hat im Reiter MQTT ihren eigenen Haken,
ab Werk aus:

| Thema | Wert | |
|---|---|---|
| `haus/tuer/<name>/offen` | 1 offen, 0 zu, `-` ohne Aussage | retained |
| `haus/tuer/<name>/verriegelt` | 1 verriegelt, 0 nicht, `-` ohne Aussage | retained |
| `haus/klima/<name>/temperatur` | °C, eine Nachkommastelle, −999 ohne Aussage | retained (4.2.5) |
| `haus/klima/<name>/feuchte`, `…/druck`, `…/helligkeit`, `…/co2`, `…/pm25` | %, hPa, lx, ppm, µg/m³; −1 ohne Aussage | retained (4.2.5) |
| `haus/klima/<name>/voc_ugm3` | VOC in µg/m³ (Standard von zigbee2mqtt), unverändert wie das Gerät es meldet; −1 ohne Aussage | retained (4.2.5) |
| `haus/klima/<name>/voc_ppb` | VOC in ppb (Geräte, die VOC in ppb melden), unverändert; −1 ohne Aussage. Keine Umrechnung zwischen ppb und µg/m³ (sie hängt vom Gasgemisch ab); VOC ohne oder mit anderer Einheit liefert kein Feld | retained (4.2.5) |
| `haus/klima/<name>/zeit` | Unix-Zeit der letzten Meldung des Geräts, −1 ohne Aussage | retained (4.2.5) |
| `haus/wetter/<name>/…` | wie `haus/klima`, nur Temperatur, Feuchte, Druck, Helligkeit – nur Fühler, die als „außen“ markiert sind | retained (4.2.5) |
| `haus/alarm/<name>/rauch`, `…/wasser`, `…/gas`, `…/co` | 1 Alarm, 0 ruhig, −1 ohne Aussage | retained (4.2.5) |
| `haus/<familie>/<name>/anbieter` | `zigbee2mqttng` | retained (4.2.5) |
| `haus/<familie>/zaehler/zigbee2mqttng` | Lebenszeichen 0…999 je Minute | **nie** retained (4.2.5) |

Dieselbe Hausvereinbarung wie in Matter2Lox (Regeln/07 Nr. 15), mit denselben Regeln:

* `<name>`: klein, Umlaute ausgeschrieben, alles außer `a-z 0-9 _ -` wird `_`. Einmal vergeben, **bleibt der Name
  fest**, auch wenn das Gerät umbenannt wird (`data/zigbee2mqttng_haus_namen.json`); ein Gerät hat in allen
  Familien denselben Namen. `zaehler` und `anbieter` sind reserviert (`zaehler_2` …).
* Steht unter einem Thema schon ein zurückbehaltener Wert eines **anderen Anbieters**, wird er nicht
  überschrieben; der Reiter Test nennt den Konflikt und Namen, die auch Matter2Lox vergibt.
* Nur die Felder, die das Gerät liefert; Ausreißer (Batterietod: 0 %, −40 °C …) außerhalb plausibler Grenzen
  werden „ohne Aussage“. Ein Abnehmer verwendet `klima`/`wetter` nur, solange `zeit` jünger als eine Stunde ist
  und sich der Zähler binnen 120 s ändert.
* **Stumme Geräte:** Liegt die letzte Meldung eines Geräts (`zeit`) über eine Stunde zurück oder ist sie unbekannt,
  sendet das Plugin selbst „ohne Aussage“: `temperatur` −999, alle übrigen Felder in `klima`/`wetter` −1, `alarm`
  −1, `tuer` `-`. `zeit` bleibt der echte Zeitpunkt. Geprüft wird jede Minute; mit der nächsten Meldung kommen die
  echten Werte zurück.
* Welches Gerät wohin meldet (Raumklima, Wetter außen, nicht melden; Alarm melden ja/nein), steht in der Tabelle
  „Geräte und Bereiche“ im Reiter MQTT.
* Verschwindet ein Gerät, geht einmal „ohne Aussage“ hinaus.
* Ausschalten einer Familie, Abwählen eines Geräts und die Deinstallation räumen die eigenen Themen am Broker ab
  und **lesen zurück**, ob sie wirklich weg sind (`bin/haus-leeren.php`); fremde Themen bleiben unberührt.
* Gesendet wird über die Verbindung von zigbee2mqtt direkt an den Broker; die Haus-Themen gehen nicht an das
  MQTT-Gateway V2 und nicht nach Loxone (die Linien-Themen `zigbee2mqtt/<gerät>` tragen dieselben Werte).

### Meldungen: LoxBerry, Signal, Sprache
Reiter Einstellungen, Abschnitt „Meldungen“: je Anlass (Gerät nicht mehr erreichbar, Batterie schwach, Alarm,
Firmware-Update verfügbar) LoxBerry-Benachrichtigung, Signal über das Plugin SignalBot und Sprache über die
gemeinsame Sprachausgabe (bis zu sechs Ziele). Signal und Sprache sind ab Werk aus. Für Signal muss im SignalBot
„Befehle über MQTT annehmen“ an und zigbee2mqttng unter „Meldungen anderer Plugins annehmen von“ angehakt sein. In
der Ruhezeit (ab Werk 22:00–07:00) wird nicht gesprochen – außer bei Alarm. Höchstens 10 Signal-Meldungen und 10
Ansagen je Stunde.

### Loxone-Vorlagen
Der Reiter „Einbindung in Loxone“ erzeugt aus der Geräteliste Vorlagen für Loxone Config:

* **Virtueller UDP-Eingang** – Befehlserkennung `<topic>/<gerät>/<wert>=\v` für das UDP-Format des Gateways
* **Virtueller Ausgang** – JSON-Befehle `{"topic":"<topic>/<gerät>/set/<wert>","value":…}` an den UDP-Eingang des
  Gateways. Anders als `publish <topic> <wert>` funktionieren damit auch Gerätenamen mit Leerzeichen. Je Schalter
  mit `value_toggle` zusätzlich ein Umschaltbefehl `…_TOGGLE`; Zahlen gehen mit `<v.0>` (ab 4.2.3).
* Tabelle mit den Namen der virtuellen HTTP-Eingänge, falls das Gateway per HTTP sendet (das Gateway ersetzt nur
  `/` und `%` durch `_`)

Namensschema `ZIGBEE_<GERÄT>_<WERT>` – passend zu `MATTER_<N>_<E>_<THEMA>` aus Matter2Lox. Werte, die das Gateway
nicht in 1/0 umwandelt (Text, Aufzählungen, `LOCK`/`UNLOCK`), sind als „kommt als Text an“ markiert.

Die Bausteine der Baustein-Listen stehen fertig verbunden auf den Seiten „Zigbee2MqttNG“ und „Zigbee2MqttNG Geräte“ im
[LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt), einer gemeinsamen
Projektdatei mit allen Plugin-Seiten und Vorlagen.

### Zigbee2mqtt UI
Port einstellbar (Standard 8881), standardmäßig mit Token (`frontend.auth_token`) geschützt.

### Sicherung, Netzwerkkarte, Koordinator-Vorlagen
* Sicherung der zigbee2mqtt-Daten als ZIP (von zigbee2mqtt selbst erstellt)
* Netzwerkkarte mit Verbindungsqualität (LQI) auf Knopfdruck
* Vorlagen für SONOFF Dongle Max/Dongle-M, Dongle-E, Dongle-P und ConBee

## Technik

* Die Verbindung zwischen zigbee2mqtt und dem Plugin übernimmt eine externe Erweiterung
  (`bin/zigbee2mqttng_extension.mjs`, wird nach `data/external_extensions/zigbee2mqttng.mjs` kopiert).
  Sie schreibt die Geräteliste, pflegt die Dateien des MQTT Gateways, sendet `erreichbar`, Taster-Impulse und
  Haus-Themen und löst Benachrichtigungen aus. Gesteuert wird sie über `data/zigbee2mqttng.json`, das
  `bin/update-config.php` schreibt.
* `bin/update-config.php` läuft beim Speichern, bei der Installation und vor jedem Start des Dienstes
  (`ExecStartPre`) – geänderte Zugangsdaten des MQTT Gateways kommen so automatisch an. Ist eine
  Konfigurationsdatei unlesbar und ohne lesbare Zweitschrift, legt es die Datei `startsperre` an; die zweite
  `ExecStartPre`-Zeile startet zigbee2mqtt dann nicht (Rückgabe 3).
* Die Installation baut zigbee2mqtt in `/opt/zigbee2mqttng.new` und tauscht erst nach Erfolg. Scheitert der Bau
  (z. B. ohne Internet), läuft die bisherige Fassung weiter.
* Eine neue Installation erzeugt eigenen Netzwerkschlüssel, PAN-ID und erweiterte PAN-ID.
