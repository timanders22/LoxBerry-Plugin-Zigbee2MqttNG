# Zigbee2MqttNG – LoxBerry-Plugin

Zigbee2MqttNG bringt [Zigbee2MQTT](https://www.zigbee2mqtt.io/) als Plugin auf den LoxBerry und ist auf das
Zusammenspiel mit anderen Plugins ausgelegt – insbesondere mit
[Matter2Lox (Matter to Loxone)](https://github.com/timanders22/LoxBerry-Plugin-Matter2Lox).

Zigbee2MqttNG ist ein Fork des Plugins [Zigbee2Mqtt](https://github.com/romanlum/LoxBerry-Plugin-Zigbee2Mqtt) (Apache-2.0, siehe `LICENSE`).
Es ist ein **eigenständiges Plugin** (Name/Ordner `zigbee2mqttng`, Dienst `zigbee2mqttng`, Installation in `/opt/zigbee2mqttng`)
und kollidiert deshalb nicht mit dem Original. Bis Version 4.0.0 hieß das Plugin **Zigbee2Lox**.

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

Die Oberfläche folgt dem Hausstandard: **Einstellungen**, **MQTT**, **Einbindung in Loxone**, **Test**,
**Logdateien** – dazu, wie „Geräte anlernen“ bei Matter2Lox, der Reiter **Geräte**.

| Reiter | Inhalt |
|---|---|
| Einstellungen | Koordinator-Vorlage, Adapter-Pfad mit Verbindungstest, Adapter-Typ, Funkkanal (mit Thread- und WLAN-Kanal), Erreichbarkeit, Zigbee2mqtt UI mit Token, Benachrichtigungen, Dienst starten/neu starten/anhalten, Einstellungen sichern/zurückspielen, Sicherung des Netzes als ZIP |
| Geräte | Anlernen für 254 s, Liste aller Geräte mit letzten Werten und Erreichbarkeit, Netzwerkkarte, Zigbee2mqtt UI, `devices.yaml` |
| MQTT | Broker, Topic, Weiterleitung an den Miniserver, Haus-Themen |
| Einbindung in Loxone | sieben Schritte: Weg, Abo, Eingänge (Vorlagen HTTP und UDP), Befehle, Ausfallerkennung, Baustein-Liste, Gegenprobe |
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

### Haus-Themen für Türen und Schlösser (optional)
Tür-/Fensterkontakte und Schlösser werden zusätzlich gemeldet unter

| Thema | Wert | |
|---|---|---|
| `haus/tuer/<name>/offen` | 1 offen, 0 zu, `-` ohne Aussage | retained |
| `haus/tuer/<name>/verriegelt` | 1 verriegelt, 0 nicht, `-` ohne Aussage | retained |

Das ist dieselbe Hausvereinbarung wie in Matter2Lox, gelesen von Funkwacht und Beschattungswächter, mit denselben
Regeln (Matter2Lox ab 0.9.35):

* `<name>`: klein, Umlaute ausgeschrieben, alles außer `a-z 0-9 _ -` wird `_`. Einmal vergeben, **bleibt der Name
  fest**, auch wenn das Gerät umbenannt wird (`data/zigbee2mqttng_haus_namen.json`).
* Steht unter einem Thema schon ein zurückbehaltener Wert eines **anderen Anbieters**, wird er nicht
  überschrieben; der Reiter Test nennt den Konflikt und Namen, die auch Matter2Lox vergibt.
* Verschwindet ein Gerät, geht einmal `-` hinaus.
* Abschalten und Deinstallation löschen die gesendeten Themen.

### Benachrichtigungen
Optional schickt das Plugin LoxBerry-Benachrichtigungen, wenn ein Gerät nicht mehr erreichbar ist oder eine
Batterie unter die Schwelle fällt (einmal je Batterie, Meldungen einer Minute zusammengefasst).

### Loxone-Vorlagen
Der Reiter „Einbindung in Loxone“ erzeugt aus der Geräteliste Vorlagen für Loxone Config:

* **Virtueller UDP-Eingang** – Befehlserkennung `<topic>/<gerät>/<wert>=\v` für das UDP-Format des Gateways
* **Virtueller Ausgang** – JSON-Befehle `{"topic":"<topic>/<gerät>/set/<wert>","value":…}` an den UDP-Eingang des
  Gateways. Anders als `publish <topic> <wert>` funktionieren damit auch Gerätenamen mit Leerzeichen.
* Tabelle mit den Namen der virtuellen HTTP-Eingänge, falls das Gateway per HTTP sendet (das Gateway ersetzt nur
  `/` und `%` durch `_`)

Namensschema `ZIGBEE_<GERÄT>_<WERT>` – passend zu `MATTER_<N>_<E>_<THEMA>` aus Matter2Lox. Werte, die das Gateway
nicht in 1/0 umwandelt (Text, Aufzählungen, `LOCK`/`UNLOCK`), sind als „kommt als Text an“ markiert.

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
