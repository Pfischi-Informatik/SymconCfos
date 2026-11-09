# cFos Wallbox für IP-Symcon

Steuert Ladepunkte an einem cFos Charging Manager über dessen lokale HTTP-API:
Überschussladen über den Symcon Energie-Manager, Sofortladen mit einstellbarem
Strom, Leistungs- und kWh-Werte sowie das Summenlimit einer gemeinsamen Zuleitung.
Das funktioniert auch für Fremd-Wallboxen, die am cFos-Master hängen.

## Installation

Das Repository in der Modulverwaltung hinzufügen oder nach `modules/SymconCfos`
kopieren und den Symcon-Dienst neu starten. Danach pro Ladepunkt eine Instanz
„cFos Wallbox“ anlegen.

## Einrichtung

1. In der Instanz die IP des Masters eintragen (z. B. `192.0.2.10`) und speichern.
2. Das Formular neu öffnen, den Ladepunkt wählen, das Admin-Passwort eintragen.
3. Im cFos muss am Master das Lastmanagement aktiv sein. Die Begrenzung der
   Zuleitung rechnet der Charging Manager, auch wenn Symcon nicht läuft.
4. Eigene Ladepunkt-Regeln im cFos und externe Steuerungen abschalten, sonst regeln
   sie gegen das Modul.

### Energie-Manager

Unter „Elektrofahrzeuge“ die Variable **Leistung (Soll)** der Instanz als
„Leistung (Soll)“ eintragen. Der Wert ist in Watt. Überschuss, Hausspeicher und
Priorität stellst du im Energie-Manager selbst ein. Damit der Sollwert wirkt, muss
der Modus auf **Überschuss** stehen.

Optional lässt sich in der Instanz die Variable „Modus“ dieses Geräts im Energie-Manager
auswählen. Endet Sofort (kWh-Ziel erreicht oder abgesteckt), setzt das Modul sie auf
**Automatisch**.

## Variablen

| Name | Einheit | Schaltbar | Bedeutung |
|---|---|---|---|
| Modus | | ja | Aus, Überschuss, Sofort; beim Abstecken zurück auf Überschuss |
| Sofort-Strom | A | ja | Strom im Modus Sofort |
| Sofort-kWh-Ziel | kWh | ja | 0 = kein Limit, zählt ab Start von Sofort, danach zurück auf Überschuss; beim Abstecken wieder 0 |
| Leistung (Soll) | W | ja | Vorgabe des Energie-Managers, begrenzt auf „Maximale Leistung“ der Instanz |
| Leistung (Ist) | W | nein | Aktuelle Ladeleistung |
| Strom L1 bis L3 | A | nein | Strom je Phase |
| Aktive Phasen | | nein | Anzahl genutzter Phasen |
| Status | | nein | Bereit, Fahrzeug erkannt, Lädt, Fehler, Offline |
| Energie Ladung | kWh | nein | Seit dem letzten Einstecken |
| Energie heute | kWh | nein | Seit Mitternacht (Zeitzone des Symcon-Servers) |
| Energie gesamt | kWh | nein | Gesamtzähler der Box |
| Summenlimit Zuleitung | A | ja | Grenze aller Ladepunkte am Master |

Die Phasenumschaltung ist standardmäßig aus. Sie wirkt nur bei der im Master
eingebauten Box und unterbricht die Ladung jedes Mal für etwa zwei Minuten.

Im Überschuss liegen zwischen Start und Stopp mindestens 120 Sekunden (einstellbar),
damit das Schütz bei Wolken nicht taktet. Meldet die Box ihr Maximum als Formel,
gilt der Wert „Maximaler Strom“ der Instanz, sonst 16 A.

## Status

| Code | Bedeutung |
|---|---|
| 102 | Verbunden |
| 104 | Host oder Ladepunkt fehlt |
| 201 | Charging Manager nicht erreichbar |
| 202 | Ladepunkt nicht gefunden |
| 203 | Befehl abgelehnt, Passwort prüfen |

## Funktionen

```php
CFOS_Update(int $InstanceID): bool; // sofort abfragen
```

## Tests

```bash
php -d zend.assertions=1 -d assert.exception=1 .test/logic.php
```

```bash
php -d zend.assertions=1 -d assert.exception=1 .test/live.php
```

Mit `.test/live.php <host> <ladepunkt>` wird zusätzlich eine echte Box gelesen.
Schaltende Befehle blockiert der Test immer.

## Noch nicht am Fahrzeug getestet

Geprüft sind die Regeln an einer simulierten Box und das Lesen an einer echten
Anlage (Firmware 2.12.7). Offen ist:

1. Ob `mamps` wirkt, wie lange und ob es Ladepunkt-Regeln übersteuert.
2. Ob `mamps` bei Fremd-Ladepunkten wirkt.
3. Ob das `set_params`-Format für das Summenlimit stimmt und das Passwort braucht.
4. Ob die Phasenumschaltung bei eingestecktem Fahrzeug sofort greift.
5. Ob der Energie-Manager `RequestAction` auf der Soll-Variable auslöst.
