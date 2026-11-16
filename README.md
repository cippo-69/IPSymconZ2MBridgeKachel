# Z2M Bridge Kachel

Bedienkachel für die Symcon-Kachel-Visualisierung (HTML-SDK) zu einer Instanz „Zigbee2MQTT Bridge“
aus dem Modul [Schnittcher/IPS-Zigbee2MQTT](https://github.com/Schnittcher/IPS-Zigbee2MQTT).

## Inhalt der Kachel

- **Status** der Bridge (Online / Offline / Neustart läuft) mit Zeitpunkt der letzten Änderung
- Zigbee2MQTT-Version, Netzwerkkanal und Anzahl der Zigbee2MQTT-Geräteinstanzen am selben MQTT-Server
- Hinweise: Bridge-Instanz gestört, Neustart erforderlich, Symcon-Erweiterung fehlt oder ist veraltet
- **Anlernen** (permit_join) ein/aus mit Countdown
- **Neue Geräte** (ab 0.3): erscheinen direkt beim Beitritt (Ereignisse aus `zigbee2mqtt/bridge/event`: device_joined,
  device_interview, device_leave) mit Interview-Status, Hersteller, Modell und ob schon eine Symcon-Instanz existiert.
  Sichtbar 7 Tage oder bis „Ausblenden“, auch in der kleinen Kachel.
- **Umbenennen** neuer Geräte über `Z2M_RenameDevice` der Bridge. Danach stellt die Kachel Symcon-Geräteinstanzen mit dem
  alten Topic auf den neuen Namen um (Eigenschaft MQTTTopic, Instanzname nur, wenn er gleich dem alten Namen war). Das gilt auch
  für Umbenennungen, die im Zigbee2MQTT-Frontend gemacht werden.
- **Protokollierung**: Fehler / Warnung / Info / Debug
- **Neustart** von Zigbee2MQTT mit Rückfrage (verfällt nach 8 s)
- Versionen: Zigbee2MQTT, Zigbee Herdsman, Herdsman Converters, Symcon-Erweiterung
- optional **Koordinator** (SMLIGHT, z. B. SLZB-06): Online-Status, Firmware, Zigbee-Firmware, Temperaturen, Laufzeit,
  Verbindung zu Z2M (Socket-Clients) und Neustart mit Rückfrage. Abfrage über `/ha_info`, `/ha_sensors` und `/metrics`,
  Neustart über `/api2?action=4&cmd=3` (wie die SMLIGHT-Bibliothek pysmlight). Ohne Anmeldung am Koordinator.

Kleine Kachel (unter 300 px Höhe): nur Status und Anlernen. Protokollierung, Neustart und Versionen erscheinen ab 300 px Höhe.

Geschaltet wird über die Aktionen der Bridge-Variablen (`RequestAction`), also genau wie in der Konsole.

## Einrichtung

1. Modulverwaltung → Repository dieser Bibliothek hinzufügen.
2. Instanz „Z2M Bridge Kachel“ anlegen, die Zigbee2MQTT-Bridge auswählen und in der Kachel-Visualisierung platzieren.
3. Ab 0.3: Die Instanz mit demselben MQTT-Server verbinden wie die Bridge (Schaltfläche „Gateway ändern“), sonst fehlen die neuen Geräte.

## Eigenschaften

| Eigenschaft | Bedeutung |
|---|---|
| Zigbee2MQTT Bridge | Instanz des Moduls „Zigbee2MQTT Bridge“ |
| Anlernzeit | Dauer, die die Bridge beim Anlernen sendet (Standard 254 s); nur für den Countdown. Gibt die Bridge eine Variable `permit_join_timeout` aus, gilt deren Wert. |
| Akzentfarbe | Farbe der Schalter und der Auswahl |
| Koordinator | optional: IP oder Hostname eines SMLIGHT-Koordinators; leer = kein Koordinator-Abschnitt |
| Abfrage alle | Abfrageintervall des Koordinators (Standard 60 s; nach einem Neustart alle 10 s, bis er wieder antwortet) |

## Voraussetzungen

IP-Symcon ab 8.0, Modul IPS-Zigbee2MQTT mit Bridge-Instanz.
