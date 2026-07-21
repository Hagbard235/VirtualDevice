# KuehlschrankPV

Verschiebt den Stromverbrauch eines Kühlschranks in die Erzeugungszeit der PV-Anlage.
Eis- und Kühlfach werden dabei als thermischer Speicher genutzt: gekühlt wird bevorzugt
bei PV-Überschuss, in der Zeit ohne Überschuss bleibt das Gerät stromlos und zehrt vom
angelegten Kältepuffer. Nur wenn eine Grenztemperatur erreicht wird, gibt es kurzzeitig
Netzstrom.

## Grundprinzip

Das Modul regelt **nicht** die Temperatur — das macht weiterhin der Thermostat des
Kühlschranks. Das Modul schaltet ausschließlich die Stromzufuhr. Daraus folgt die
wichtigste Inbetriebnahme-Regel:

> Der Thermostat des Geräts muss auf die **Tiefsttemperatur** eingestellt werden.

Pro Fach gibt es drei Schwellen. Am Beispiel Eisfach:

| Schwelle | Beispiel | Bedeutung |
|---|---|---|
| Tiefsttemperatur | −20 °C | Ziel bei PV-Überschuss, hier ist der Puffer voll |
| Solltemperatur | −15 °C | eigentlich gewünschte Temperatur, Ziel der Netz-Nachkühlung |
| Grenztemperatur | −10 °C | noch akzeptabel, löst die Netz-Nachkühlung aus |

Die Spanne zwischen Tiefst- und Grenztemperatur ist der nutzbare Puffer.

Stellen Sie die Tiefsttemperatur etwas **über** den tatsächlichen Thermostatwert
(Thermostat −20 °C, Property −19 °C). Sonst wird der Puffer nie als „voll" erkannt,
weil der Fühler den Sollwert des Geräts praktisch nie exakt erreicht.

## Zustände

| Zustand | Wann |
|---|---|
| PV-Kühlung | Überschuss deckt die Leistungsaufnahme plus Aufschlag — Strom an |
| Vorkühlen | kurz vor dem prognostizierten PV-Ende, Puffer noch nicht voll — der Überschuss-Aufschlag entfällt |
| Puffer aktiv | kein Überschuss, Temperaturen im grünen Bereich — Strom aus |
| Netz-Nachkühlung | Grenztemperatur erreicht — Strom an bis zur Soll- oder Tiefsttemperatur |
| Sperrzeit | Schaltwunsch liegt an, der Verdichterschutz blockiert noch |
| Störung | Sensor fehlt, ist veraltet oder unplausibel — Notbetrieb, Strom an |

Wie tief die Netz-Nachkühlung geht, hängt von der Prognose ab: kommt die PV erst in
ferner Zukunft zurück, wird gleich bis zur Tiefsttemperatur durchgekühlt, damit der
Verdichter in derselben Nacht nicht mehrfach anlaufen muss. Steht die PV bald wieder
an, genügt die Solltemperatur.

## Überschussberechnung

Grundlage ist der Gesamtstromzähler. Da dieser den Kühlschrank mit erfasst, wird
dessen eigener Verbrauch herausgerechnet, solange der Verdichter läuft (erkennbar an
der Verbrauchsvariable ≥ 15 W). Die Rechnung lautet also:

```
verfügbar = Einspeisung + Eigenverbrauch des Kühlschranks
einschalten, wenn verfügbar >= Leistungsaufnahme + Aufschlag
```

Die typische Leistungsaufnahme wird im Betrieb gelernt; bis dahin gilt der Startwert
aus der Konfiguration. Damit einzelne Wolken das Gerät nicht takten lassen, muss eine
Zustandsänderung die eingestellte Mindestdauer überdauern (Standard 180 s), zusätzlich
gibt es eine Hysterese im laufenden Betrieb.

## Prognose

Das Modul zeichnet täglich auf, zwischen welchen Uhrzeiten genug Überschuss für den
Kühlschrank vorhanden war, und bildet über die letzten Tage (Standard 7) den Median.
Daraus ergeben sich der nächste PV-Start und das nächste PV-Ende. Der Median ist
robuster als der Mittelwert — ein einzelner Regentag verschiebt die Prognose sonst zu
stark. Bis genug Tage aufgezeichnet sind, gelten die Standardzeiten aus der
Konfiguration.

Optional lässt sich eine externe Prognosevariable angeben, die einen Unix-Zeitstempel
des nächsten PV-Starts enthält (z. B. aus einem Wetter- oder Solarprognose-Modul).
Sie hat Vorrang, sofern ihr Wert in der Zukunft und weniger als sieben Tage entfernt
liegt.

Zusätzlich wird die Erwärmungsrate jedes Fachs (°C/h) aus den Aus-Phasen gelernt.
Daraus berechnet sich die angezeigte Puffer-Restzeit. Pro Aus-Phase wird höchstens
einmal gelernt, damit lange Phasen den Mittelwert nicht dominieren; unplausible Raten
(unter 0,05 oder über 10 °C/h, typisch nach Türöffnungen oder Abtauvorgängen) werden
verworfen.

## Schutzfunktionen

- **Verdichterschutz**: Mindestpause vor dem Wiedereinschalten (Standard 10 min) und
  Mindestlaufzeit vor dem Abschalten (Standard 5 min). Die Mindestpause gilt immer,
  auch im Not- und Handbetrieb — sie schützt die Hardware.
- **Sensorüberwachung**: Liefert ein Fühler unplausible Werte (außerhalb −45 bis
  +30 °C), geht das Modul in den Notbetrieb und lässt den Kühlschrank dauerhaft am
  Netz. Auf Schweigen wird nur während der Aus-Phasen geprüft, und zwar ab deren
  Beginn: die meisten Fühler senden nur bei relevanter Änderung, und solange der
  Kühlschrank Strom hat, hält der Thermostat die Temperatur — stundenlanges
  Schweigen ist dann normal, ein ausgefallener Fühler zudem ungefährlich, weil das
  Gerät selbst regelt. Ohne Strom steigt die Temperatur dagegen zwangsläufig, ein
  funktionierender Sensor muss sich also melden. Bei grob auflösenden Fühlern und
  langsamer Erwärmung den Wert großzügig wählen (Standard 180 min) oder mit 0
  abschalten — die maximale Aus-Zeit schützt dann weiterhin.
- **Maximale Aus-Zeit** (Standard 12 h): Sicherheitsnetz gegen einen Fühler, der
  plausible, aber eingefrorene Werte liefert. 0 schaltet das Limit ab.
- **Schaltkontrolle**: Übernimmt die Steckdose einen Befehl dreimal nicht, geht die
  Instanz auf Fehlerstatus.

> **Wichtig:** Wird die Instanz deaktiviert oder gelöscht, während der Kühlschrank
> ausgeschaltet ist, bleibt er ausgeschaltet — der Timer läuft dann nicht mehr.
> Vorher auf „Normalbetrieb" stellen.

## Konfiguration

Benötigt werden mindestens die Schaltvariable der Stromzufuhr, der Gesamtstromzähler
und ein überwachtes Fach.

- **Stromzufuhr**: schaltbare Boolean-Variable. Ist es ein *Ausschalter* (True =
  Kühlschrank aus), die Option „Invertiert" setzen.
- **Gesamtstromzähler**: Momentanleistung in Watt. Über „Einspeisung wird negativ
  gezählt" wird das Vorzeichen normalisiert.
- **Stromverbrauch Kühlschrank**: optional, verbessert aber die Überschussrechnung
  und das Lernen der Leistungsaufnahme deutlich.

## Bedienung

Die Variable **Modus** ist im Webfront schaltbar:

| Modus | Wirkung |
|---|---|
| PV-Automatik | die oben beschriebene Optimierung |
| Normalbetrieb | dauerhaft Strom, keine Optimierung |
| Aus | dauerhaft stromlos, **keine** Temperaturüberwachung — nur für Wartung oder Urlaub |

Die Variable **Erläuterung** enthält im Klartext, warum der aktuelle Zustand gilt.

## Script-Schnittstelle

```php
PVKS_SetMode(InstanceID, 0);   // 0 = PV-Automatik, 1 = Normalbetrieb, 2 = Aus
PVKS_Cycle(InstanceID);        // sofortige Neubewertung
PVKS_DumpState(InstanceID);    // Lern- und Prognosewerte ausgeben
PVKS_ResetLearning(InstanceID);
```
