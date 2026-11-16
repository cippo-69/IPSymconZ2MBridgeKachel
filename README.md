# Z2M Bridge Kachel

Bedienkachel für die Symcon-Kachel-Visualisierung (HTML-SDK) zu einer Instanz „Zigbee2MQTT Bridge“
aus dem Modul [Schnittcher/IPS-Zigbee2MQTT](https://github.com/Schnittcher/IPS-Zigbee2MQTT).

## Inhalt der Kachel

- **Status** der Bridge (Online / Offline / Neustart läuft) mit Zeitpunkt der letzten Änderung
- Zigbee2MQTT-Version, Netzwerkkanal und Anzahl der Zigbee2MQTT-Geräteinstanzen am selben MQTT-Server
- Hinweise: Bridge-Instanz gestört, Neustart erforderlich, Symcon-Erweiterung fehlt oder ist veraltet
- **Anlernen** (permit_join) ein/aus mit Countdown
- **Protokollierung**: Fehler / Warnung / Info / Debug
- **Neustart** von Zigbee2MQTT mit Rückfrage (verfällt nach 8 s)
- Versionen: Zigbee2MQTT, Zigbee Herdsman, Herdsman Converters, Symcon-Erweiterung

Kleine Kachel (unter 300 px Höhe): nur Status und Anlernen. Protokollierung, Neustart und Versionen erscheinen ab 300 px Höhe.

Geschaltet wird über die Aktionen der Bridge-Variablen (`RequestAction`), also genau wie in der Konsole.

## Einrichtung

1. Modulverwaltung → Repository dieser Bibliothek hinzufügen.
2. Instanz „Z2M Bridge Kachel“ anlegen, die Zigbee2MQTT-Bridge auswählen und in der Kachel-Visualisierung platzieren.

## Eigenschaften

| Eigenschaft | Bedeutung |
|---|---|
| Zigbee2MQTT Bridge | Instanz des Moduls „Zigbee2MQTT Bridge“ |
| Anlernzeit | Dauer, die die Bridge beim Anlernen sendet (Standard 254 s); nur für den Countdown. Gibt die Bridge eine Variable `permit_join_timeout` aus, gilt deren Wert. |
| Akzentfarbe | Farbe der Schalter und der Auswahl |

## Voraussetzungen

IP-Symcon ab 8.0, Modul IPS-Zigbee2MQTT mit Bridge-Instanz.
