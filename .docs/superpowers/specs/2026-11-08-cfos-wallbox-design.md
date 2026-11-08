# cFos Wallbox für IP-Symcon: Design

Stand: 2026-11-08. Getestete Grundlage: cFos Charging Manager 2.12.7 (nur lesend).

## Ziel

Ein universelles Symcon-Modul für Ladepunkte an einem cFos Charging Manager:
Überschussladen über den Symcon Energie-Manager, Sofortladen mit einstellbarem
Strom, Leistungs- und kWh-Werte in Symcon, Summenlimit für eine gemeinsame
Zuleitung. Keine anlagenspezifischen Werte im Code.

## Abgrenzung

- Das Lastmanagement rechnet der cFos Charging Manager. Das Modul stellt nur das
  Limit ein, damit die Zuleitung auch ohne Symcon geschützt bleibt.
- Das Modul regelt keinen Überschuss. Das macht allein der Energie-Manager. Die
  Quellen (Überschuss, Hausspeicher, Priorität) verknüpft der Anwender dort.
- Kein Konfigurator: eine Instanz pro Ladepunkt.
- SoC gibt es über AC nicht und wird nicht angezeigt.

## Aufbau

```
SymconCfos/
├── library.json
├── README.md
├── CfosWallbox/{module.json, form.json, module.php}
└── test/live.php
```

Modul „cFos Wallbox“, Präfix `CFOS`, Klasse `cFosWallbox`, Hersteller „cFos“.
Autor Dominik Pfister, öffentliches Repo `Pfischi-Informatik/SymconCfos`.

## Konfiguration pro Instanz

| Property | Bedeutung | Standard |
|---|---|---|
| Host | IP oder Name des cFos Charging Managers | leer |
| DevId | Ladepunkt, Auswahl aus `get_dev_info` (`is_evse`) | leer |
| Password | Admin-Passwort, wird in Debug-Ausgaben maskiert | leer |
| Interval | Abfrageintervall in Sekunden | 5 |
| MaxCurrent | Obergrenze dieser Instanz in A, 0 = Wert der Box | 0 |
| Voltage | Nennspannung für die Umrechnung W/A (Kalibrierung) | 230 |
| DefaultPhases | Annahme, solange die Box keine Phasen meldet | 3 |
| PhaseSwitch | Phasenumschaltung durch das Modul erlauben | aus |
| PhaseHold | Mindestzeit zwischen zwei Umschaltungen in s | 600 |

## Variablen

| Ident | Name | Typ | Schaltbar | Quelle |
|---|---|---|---|---|
| Mode | Modus | int | ja | 0 Aus, 1 Überschuss, 2 Sofort |
| ImmediateCurrent | Sofort-Strom | int, A | ja | Grenzen: `min_charging_cur` bis Maximum |
| ImmediateTarget | Sofort-kWh-Ziel | float | ja | 0 = kein Limit |
| TargetPower | Leistung (Soll) | int, W | ja | Energie-Manager |
| Power | Leistung (Ist) | int, W | nein | `power_w` |
| CurrentL1..L3 | Strom L1 bis L3 | float, A | nein | `current_l1..3` / 1000 |
| ActivePhases | Aktive Phasen | int | nein | Bits in `phases` |
| State | Status | int | nein | `state` (1 bereit, 2 Fahrzeug, 3/4 lädt, 5 Fehler, 6 offline) |
| EnergySession | Energie Ladung | float, kWh | nein | berechnet |
| EnergyToday | Energie heute | float, kWh | nein | berechnet |
| EnergyTotal | Energie gesamt | float, kWh | nein | `total_energy` / 1000 |
| TotalLimit | Summenlimit Zuleitung | int, A | ja | `max_total_evse_current` |

Mehrere Instanzen am selben Manager zeigen dasselbe Summenlimit. Geschrieben wird
nur bei einer Änderung per `set_params`.

## Datenfluss

Jede Instanz fragt per Timer `GET /cnf?cmd=get_dev_info` ab und sucht ihren
`dev_id`. Befehle gehen über HTTP Basic Auth (`admin:<Passwort>`).

### Modi

| Modus | Wirkung, solange ein Fahrzeug steckt (`state >= 2`) |
|---|---|
| Aus | `override_device` Flag `C` |
| Überschuss | Sollwert in A umrechnen. Unter Minimalstrom Flag `C`, sonst Flag `c` und `mamps` |
| Sofort | Flag `c` und `mamps` = Sofort-Strom, der Sollwert des Energie-Managers wird ignoriert |

Beim Übergang auf `state == 1` (abgesteckt) wird der Modus Überschuss. Erreicht im
Modus Sofort die Energie der Ladung das kWh-Ziel (`> 0`), ebenfalls.

Annahme: Das Modul löst die bestehende Steuerung ab (Charging-Manager-Variablen
und MQTT-Überschusszähler). Ladepunkt-Regeln werden beim Einstecken mit Flag `E`
übersteuert. Bleibt die alte Steuerung aktiv, regeln beide gegeneinander.

### Vorgabe erneuern

Die Flags enden laut cFos beim Abstecken. Das Modul sendet die Vorgabe bei einer
Änderung, beim Einstecken und alle 60 s, solange ein Fahrzeug steckt. Ein Vergleich
mit `last_set_charging_cur` scheidet aus, weil das Lastmanagement dort berechtigt
niedrigere Werte setzt.

### Umrechnung

`A = floor(W / (Voltage * Phasen))`, begrenzt auf Maximum (kleinster Wert aus
MaxCurrent und `max_charging_cur`). Unter `min_charging_cur` wird pausiert.
Liefert die Box statt eines Maximums eine Formel, gilt MaxCurrent, sonst 16 A. Der
Minimalstrom ist nie kleiner als 6 A. Im Überschuss liegt zwischen Start und Stopp
mindestens `StartStopHold` (Standard 120 s), damit das Schütz nicht taktet.
Phasen: Bitzahl von `phases`, wenn größer 0, sonst die zuletzt bekannte Zahl, sonst
DefaultPhases.

### Energie

Startwerte liegen in Attributen und überstehen einen Neustart.

- Ladung: Beim Übergang von `state <= 1` nach `state >= 2` wird `total_energy`
  gemerkt. Der Wert bleibt nach dem Abstecken bis zum nächsten Einstecken stehen.
- Heute: Hat sich das Datum seit der letzten Abfrage geändert, wird `total_energy`
  als neuer Tagesstart gemerkt.

### Phasenumschaltung

Nur wenn PhaseSwitch an ist, die Box `evse.phase_switch_allowed` meldet und der
Ladepunkt die eingebaute Box ist (`address == "evse:"`). Befehl:
`cmd=modbus&device=evse&write=8087&value=0|1` (0 = 3P, 1 = 1P).

- Überschuss: Sollwert `< 4140 W` wechselt auf 1P, `>= 4440 W` auf 3P.
- Sofort: 3P.
- Zwischen zwei Wechseln liegt mindestens PhaseHold. Jeder Wechsel unterbricht die
  Ladung für etwa 2 Minuten.
- Alle anderen Boxen, etwa Fremd-Ladepunkte: Phasen werden nur erkannt.

## Fehlerbehandlung

| Status | Bedeutung |
|---|---|
| 102 | aktiv |
| 104 | Host oder Ladepunkt nicht eingetragen |
| 201 | Manager nicht erreichbar, Werte bleiben stehen, nächster Timer versucht es neu |
| 202 | Ladepunkt nicht gefunden |
| 203 | Befehl abgelehnt (Passwort prüfen) |

## Tests

`test/live.php` stellt Symcon minimal nach. Host und Passwort kommen nur als
Argumente, nie aus dem Repo.

- Asserts ohne Gerät: Umrechnung W/A samt Grenzen, Phasenzahl, Energie-Startwerte,
  Datumswechsel, Rückfall beim Abstecken, kWh-Ziel, Hysterese und Haltezeit.
- Lesend gegen die echte Box: Felder vorhanden und plausibel.
- Schaltend nur mit ausdrücklichem Schalter `--write` und angestecktem Auto.

Am Gerät noch zu klären, in der README als ungetestet vermerkt:

1. Wirkt `mamps` und wie lange? Übersteuert es Regeln?
2. Wirkt `mamps` bei Fremd-Ladepunkten (EVracing)?
3. Wie sieht das `set_params`-Format für das Summenlimit aus, und braucht es das Passwort?
4. Greift die Phasenumschaltung bei eingestecktem Fahrzeug sofort?
5. Ruft der Energie-Manager `RequestAction` auf der Soll-Variable auf?

## Veröffentlichung

Vor dem ersten Push das Repo auf IPs, Seriennummern und Passwörter durchsuchen.
Das Repo enthält keine anlagenspezifischen Werte.
