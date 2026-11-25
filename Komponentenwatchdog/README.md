# Komponentenwatchdog

Prueft eine gepflegte Liste von Komponenten - jede mit der Regel ihres Systems - und meldet,
wenn eine ausfaellt oder zurueckkommt.

Konzept und Bestandsaufnahme: `21_Konzept_Komponentenwatchdog.md`,
`20_Watchdog_Bestandsaufnahme.md` sowie `inspection/zigbee-watchdog/ZIGBEE-WATCHDOG-AUSBAU.md`.

## Grundsaetze

- **Wechsel statt Zustand.** Gemeldet wird der Uebergang von lebend nach gestoert und zurueck,
  jeweils einmal, nach einer Entprellung. Was beim Aufnehmen schon gestoert ist, ist Altbestand.
- **Eine Pruefregel je System.** Keine gemeinsame Regel fuer Homematic und Zigbee.
- **Bruecke vor Geraet.** Ist eine Bruecke gestoert, bleiben die Geraete dahinter still. Verstummt
  die Mehrheit der Geraete hinter einer Bruecke gleichzeitig, gilt die Bruecke als ausgefallen,
  auch wenn sie selbst gruen meldet.
- **Zustellung ueber die Meldungszentrale**, vertraulich an eine Person.

## Pruefregeln (v2)

| Regel | Objekt | gestoert, wenn | Wartung, wenn |
|---|---|---|---|
| `instanzstatus` | Instanz | Status >= 200 | - |
| `homematic` | Wartungskanal `:0` / `MAINTENANCE` | `UNREACH` oder `SABOTAGE` wahr | `LOWBAT`/`LOW_BAT`, `CONFIG_PENDING` laenger als eingestellt |
| `zigbee2mqtt` | Geraeteinstanz (Modul oder Topic) | Router still (`> 2 h` / `> 6 h`) oder Batteriegeraet still (`>= 48 h` Sensor / `>= 168 h` Taster) oder `availability`/`device_status` offline | Stille ueber Warnschwelle / gelernten Takt, frische `battery_low`, Spannung `<= BatterieSpannungMin`, frische `%`, `>= FlappingSchwelle24h` Ausfaelle in 24 h |
| `wertalter` | Variable | Aktualisierung aelter als Schwelle | - |

Ein Signal, das sich nicht lesen laesst, oder ein `TopicMismatch` bei veraltetem `last_seen` ist ein Fall unter `unbekannt`/Wartung.

## Zigbee-Klassen & Batterie-Ableitung

- **`router_infra` / `router_licht`:** Dauerstrom-Router werden ueber `last_seen` (Standard `120 min` bzw. `360 min`) und 24-h-Flapping ueberwacht; keine falschen Batteriealarme.
- **`batterie_sensor` / `batterie_taster`:** Batteriezustaende werden primaer aus `last_seen`-Stille und einer lernenden Check-in-Baseline (`ZigbeeTakt`, 85.-Perzentil ruhiger Pausen) abgeleitet (`Batterie pruefen` vs. `Batterie vermutlich leer`).
- **Frischepruefung:** `battery`, `battery_low` und `voltage` zaehlen nur, wenn `VariableUpdated > 0` (`<= 14 Tage`) und `last_seen` aktuell (`<= 7 Tage`).

## Gewichte & Modi

| Gewicht | Ausfall | Wartung |
|---|---|---|
| `sicherheit` | sofort, `wichtig` | sofort |
| `funktion` | sofort | Tagesuebersicht |
| `allgemein` | Tagesuebersicht | Tagesuebersicht |

Modi in der Komponentenliste: `aktiv`, `ruhend`, `saisonal`. Bruecken melden ihren Ausfall immer sofort.

## Funktionen

- `KWD_Pruefen($id)` - ein Pruefzyklus (laeuft per Timer)
- `KWD_TagesuebersichtSenden($id)` - Sammelmeldung (laeuft per Timer)
- `KWD_Status($id)` - Zustaende, Tagesliste und Protokoll als JSON
- `KWD_ZigbeeBericht($id)` - strukturierter Zigbee-Gesundheitsbericht (Klassen, Takte, Flaps, Mismatches, Unangebundene) als JSON
- `KWD_Zuruecksetzen($id, $Key)` - Komponente neu bewerten lassen, leerer Schluessel = alle
