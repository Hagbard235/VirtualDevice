# Komponentenwatchdog

Prueft eine gepflegte Liste von Komponenten - jede mit der Regel ihres Systems - und meldet,
wenn eine ausfaellt oder zurueckkommt.

Konzept und Bestandsaufnahme: `21_Konzept_Komponentenwatchdog.md` und
`20_Watchdog_Bestandsaufnahme.md` im Projektordner.

## Grundsaetze

- **Wechsel statt Zustand.** Gemeldet wird der Uebergang von lebend nach gestoert und zurueck,
  jeweils einmal, nach einer Entprellung. Was beim Aufnehmen schon gestoert ist, ist Altbestand.
- **Eine Pruefregel je System.** Keine gemeinsame Regel fuer Homematic und Zigbee.
- **Bruecke vor Geraet.** Ist eine Bruecke gestoert, bleiben die Geraete dahinter still. Verstummt
  die Mehrheit der Geraete hinter einer Bruecke gleichzeitig, gilt die Bruecke als ausgefallen,
  auch wenn sie selbst gruen meldet.
- **Zustellung ueber die Meldungszentrale**, vertraulich an eine Person.

## Pruefregeln (Phase 1)

| Regel | Objekt | gestoert, wenn | Wartung, wenn |
|---|---|---|---|
| `instanzstatus` | Instanz | Status >= 200 | - |
| `homematic` | Wartungskanal `:0` / `MAINTENANCE` | `UNREACH` oder `SABOTAGE` wahr | `LOWBAT`/`LOW_BAT`, `CONFIG_PENDING` laenger als eingestellt |
| `zigbee2mqtt` | Geraeteinstanz (Modul oder Topic) | `availability` offline, sonst `last_seen` aelter als Schwelle | `battery_low`, Batterie unter Schwelle |
| `wertalter` | Variable | Aktualisierung aelter als Schwelle | - |

Ein Signal, das sich nicht lesen laesst, ist ein Wartungsfall (meist falsches Objekt in der Liste).

## Gewichte

| Gewicht | Ausfall | Wartung |
|---|---|---|
| `sicherheit` | sofort, `wichtig` | sofort |
| `funktion` | sofort | Tagesuebersicht |
| `allgemein` | Tagesuebersicht | Tagesuebersicht |

Bruecken melden ihren Ausfall immer sofort.

## Funktionen

- `KWD_Pruefen($id)` - ein Pruefzyklus (laeuft per Timer)
- `KWD_TagesuebersichtSenden($id)` - Sammelmeldung (laeuft per Timer)
- `KWD_Status($id)` - Zustaende, Tagesliste und Protokoll als JSON
- `KWD_Zuruecksetzen($id, $Key)` - Komponente neu bewerten lassen, leerer Schluessel = alle
