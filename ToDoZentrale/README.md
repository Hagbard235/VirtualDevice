# ToDoZentrale

Verwaltet offene Aufgaben im Haus, stellt sie im Webfront dar, erinnert daran und
sagt sie bei Bedarf an. Andere Module und Skripte legen Aufgaben über eine
Funktion an — die Zentrale kümmert sich um Darstellung, Erinnerung und Aufräumen.

## Grundgedanke

> Die Wahrheit ist die Liste. Die Kacheln sind nur ihre Darstellung.

Die Aufgabenliste liegt als Daten in der Instanz. Bei jeder Änderung gleicht das
Modul die sichtbaren Objekte gegen den Soll-Zustand ab: fehlende Kacheln werden
angelegt, überzählige samt Profil entfernt. Damit kann es keine Karteileichen
geben — der Soll-Zustand ist jederzeit bekannt und neu herleitbar. Entfernt
werden dabei ausschließlich Objekte, die das Modul selbst angelegt hat.

Der zweite tragende Gedanke betrifft die automatische Erledigung. Sie hängt an
einer **Bedingung auf den Zustand** einer Variablen, die zyklisch geprüft wird —
nicht an einem einmaligen Ereignis. Daraus folgen zwei Eigenschaften, die mit
einem Ereignis nicht zu haben sind: Eine bereits erfüllte Bedingung verhindert,
dass die Aufgabe überhaupt entsteht. Und es gibt keinen „einzigen Schuss", der
danebengehen kann.

## Die zwei Achsen

**Quittierungsart** — wie wird die Aufgabe erledigt?

| Wert | Bedeutung |
|---|---|
| 0 | Manuell: Antippen erledigt sie |
| 1 | Automatisch: nur die Bedingung erledigt sie |
| 2 | Beides: was zuerst eintritt |
| 3 | Info: reine Anzeige, nicht antippbar |

**Kategorie** — wohin gehört sie? Frei konfigurierbare Liste, jede Kategorie mit
eigenem Ort im Objektbaum, eigener Farbe und eigenem Icon. Aufgaben ohne
Kategorie landen am eingestellten Standardort.

Diese Trennung ist Absicht: Wie etwas quittiert wird und wozu es gehört, sind
zwei unabhängige Fragen.

## Aufgaben anlegen

```php
TODO_Set(InstanceID, "MUELL", "Gelbe Tonne rausstellen", json_encode([
    "kategorie"  => "Haushalt",
    "prio"       => 2,
    "sprache"    => "Die gelbe Tonne muss raus",
    "erinnerung" => 1800,
    "sprechen"   => 3
]));
```

Alle Optionen sind freiwillig. Existiert der Ident bereits, wird die Aufgabe
aktualisiert statt verdoppelt, und nicht angegebene Felder bleiben erhalten —
`TODO_Set` dient also zugleich zum Ändern von Text, Farbe, Icon oder Priorität
einer laufenden Aufgabe. Eine eigene „Ändern"-Funktion braucht es nicht.

| Option | Bedeutung |
|---|---|
| `kategorie` | Schlüssel aus der Kategorienliste |
| `prio` | 0 Niedrig, 1 Normal, 2 Wichtig, 3 Kritisch |
| `quittierung` | siehe Tabelle oben |
| `sprache` | abweichender Text für die Ansage |
| `farbe` | `ROT`, `GELB`, `GRUEN`, `BLAU`, `LILA`, `ORANGE`, `GRAU`, `WEISS`, `SCHWARZ` oder ein Hexwert wie `#FF8800` |
| `icon` | IP-Symcon-Iconname |
| `schalter` | Beschriftung der Kachel |
| `faellig` | Unix-Zeitstempel |
| `clearVar` / `clearMode` / `clearWert` | Bedingung, die die Aufgabe erledigt |
| `stummVar` / `stummMode` / `stummWert` | Bedingung, die Erinnerungen unterdrückt |
| `erinnerung` | Intervall in Sekunden, 0 = keine Erinnerung |
| `erinnerungMax` | maximale Anzahl Erinnerungen, 0 = unbegrenzt |
| `sprechen` | Bitmaske: 1 bei Entstehung, 2 bei Erinnerung, 4 bei Erledigung |
| `sprachziele` | Array von Ziel-Schlüsseln, leer = alle |

Vergleichsoperatoren für `clearMode` und `stummMode`: 0 gleich, 1 ungleich,
2 größer, 3 kleiner, 4 ist wahr, 5 ist falsch.

Ohne Angabe von `erinnerung` ergibt sich das Intervall aus der Priorität —
60, 30, 15 beziehungsweise 5 Minuten.

## Weitere Funktionen

```php
TODO_Clear(InstanceID, "MUELL");           // still entfernen
TODO_Acknowledge(InstanceID, "MUELL");     // als erledigt quittieren
TODO_Snooze(InstanceID, "MUELL", 60);      // 60 Minuten Ruhe
TODO_Exists(InstanceID, "MUELL");          // bool
TODO_ListJSON(InstanceID);                 // alle offenen Aufgaben als JSON
TODO_Speak(InstanceID, "Das Essen ist fertig", "");   // freie Ansage
TODO_Cycle(InstanceID);                    // sofort neu bewerten
```

`TODO_Speak` nutzt dieselben Kanäle wie die Aufgaben und ignoriert bewusst die
Ruhezeit — es ist für ausdrücklich angeforderte Ansagen gedacht. Der zweite
Parameter ist eine kommaseparierte Liste von Ziel-Schlüsseln, leer bedeutet alle.

## Sprachausgabe

Sprachziele werden als Liste konfiguriert. Ein Ziel ist entweder ein **Skript**,
das mit den Parametern `Titel` und `Text` aufgerufen wird, oder eine **Variable**,
in die der Text per `RequestAction` geschrieben wird. Damit lässt sich jede
Ansagetechnik anbinden, ohne das Modul zu ändern.

Vier Mechanismen halten die Ansagen erträglich, wenn Sprache im Haus häufiger
wird:

- **Ruhezeit** — in ihr wird nichts gesprochen, die Kacheln bleiben sichtbar.
- **Zusammenfassung** — werden mehrere Erinnerungen gleichzeitig fällig, gibt es
  ab der eingestellten Anzahl eine Sammelansage statt mehrerer Einzelmeldungen.
- **Wiederholungsdämpfung** — derselbe Satz wird innerhalb der eingestellten
  Zeitspanne nicht erneut gesprochen.
- **Prioritätsschwelle** — unterhalb davon erscheint eine Aufgabe nur sichtbar.

## Migration

Zwei Schaltflächen im Konfigurationsformular helfen beim Umstieg von einem
skriptbasierten ToDo-System:

**Vorhandene Kacheln übernehmen** liest die Variablen einer bestehenden
ToDo-Kategorie ein und stellt sie unter die Verwaltung des Moduls. Idents und
Namen bleiben erhalten, die Visualisierung läuft also unverändert weiter.

**Verwaiste ToDo-Profile aufräumen** entfernt Variablenprofile mit dem Präfix
`ToDo.`, zu denen es keine Variable mehr gibt.

> **Hinweis zur Reihenfolge:** Beim Entfernen einer Kachel löst das Modul zuerst
> das Profil von der Variablen, löscht dann die Variable und erst danach das
> Profil. IP-Symcon verweigert das Löschen eines Profils, das noch in Benutzung
> ist — die umgekehrte Reihenfolge scheitert.
