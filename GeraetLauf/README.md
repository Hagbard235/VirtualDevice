# GeraetLauf

Erkennt am Stromverbrauch, ob ein Haushaltsgerät läuft oder fertig ist, schätzt
die Restlaufzeit und meldet die passenden Aufgaben an die [ToDoZentrale](../ToDoZentrale/README.md).

Eine Instanz je Gerät. Waschmaschine, Trockner und Spülmaschine sind dasselbe
Problem und unterscheiden sich nur in der Konfiguration.

## Erkennung

Drei Zustände: **Bereit**, **Läuft**, **Fertig**.

Umgeschaltet wird erst, wenn die Leistung die jeweilige Schwelle **durchgehend
für eine Haltezeit** über- beziehungsweise unterschreitet. Ein einzelner
Messwert setzt die laufende Haltezeit zurück. Damit kann eine Einweich- oder
Weichspülpause kein Programmende vortäuschen — voreingestellt sind zehn Minuten
unter der Endschwelle.

Beim Übergang nach *Fertig* kommen zwei Plausibilitätsprüfungen dazu: Der Lauf
muss eine Mindestdauer erreicht und zwischendurch eine Mindest-Spitzenleistung
gezogen haben. Ein kurzes Aufblinken des Displays oder ein reiner Abpumpvorgang
zählt damit nicht als Programmlauf.

## Türlogik

Ist ein Türkontakt konfiguriert, gelten zwei Regeln:

Öffnet sich die Tür im Zustand *Fertig*, gilt die Wäsche als entnommen — die
Aufgabe verschwindet und der Zustand geht zurück auf *Bereit*.

Steht die Tür beim Programmende bereits offen, entsteht die Aufgabe gar nicht
erst. Wer die Tür offen hat, war offensichtlich dort.

Technisch wird das nicht über ein Ereignis gelöst, sondern über eine Bedingung,
die die ToDo-Zentrale zyklisch prüft. Deshalb gibt es keinen verpassten
Auslöser: Auch wenn sich der Kontakt nie wieder bewegt, bleibt der Zustand
korrekt.

> Zigbee-Kontakte melden üblicherweise `true` für *geschlossen*. Dafür ist die
> Option „Tür ist offen, wenn die Variable False ist" voreingestellt. Prüfe das
> an deinem Sensor, bevor du dich darauf verlässt.

## Restlaufzeit

Die Schätzung speist sich aus drei Quellen, die einander ergänzen.

Die **typische Dauer** ist der Median der zuletzt aufgezeichneten Läufe. Solange
noch nichts gelernt wurde, gilt der eingestellte Vorgabewert.

Der **Energiefortschritt** verfeinert das: die bisher verbrauchten Wattstunden im
Verhältnis zur typischen Gesamtenergie sind ein besserer Indikator als reine
Zeit, weil sie die Heizphase mitbewerten. Er fließt ein, sobald etwas Fortschritt
messbar ist.

Die **Schleudererkennung** liefert das Ende: hohe Leistung in der zweiten
Programmhälfte bedeutet Schleudern, und danach sind es nur noch wenige Minuten.
Ab dann gilt die kurze Restzeit.

Die Schätzung ist ehrlich gesagt eine Schätzung — bei stark unterschiedlichen
Programmen streut sie. Erst nach einigen Läufen wird sie brauchbar.

## Aufgaben

Beim Start entsteht optional eine Info-Kachel („läuft"), beim Programmende die
eigentliche Aufgabe („ausräumen").

Deren Idents und Texte werden **aus dem Gerätenamen abgeleitet**, solange die
Felder leer bleiben — eine Instanz namens „Trockner" erzeugt also von selbst
`TROCKNER_LAEUFT`, `TROCKNER_FERTIG` und „Trockner ausräumen". Das ist Absicht:
So bekommt jede Instanz eigene Kennungen, und zwei Geräte können nicht auf
derselben Aufgabe kollidieren. Wer eine bestehende Visualisierung
weiterverwenden will, trägt den vorhandenen Ident (etwa `WMFERTIG`) explizit
ein — ein gesetzter Wert hat immer Vorrang vor der Ableitung.

> **Wichtig bei mehreren Geräten:** Verlass dich nicht darauf, die Idents später
> zu ändern — vergisst du es bei der zweiten Instanz, schreiben beide Geräte auf
> dieselbe Aufgabe. Entweder die Felder leer lassen (dann sorgt der Gerätename
> für eindeutige Kennungen) oder von Anfang an unterschiedliche Idents eintragen.

Über „Erinnerung unterdrücken, solange diese Variable gilt" lässt sich ein
zweites Gerät berücksichtigen — etwa keine Erinnerung an die Waschmaschine,
solange der Trockner läuft.

## Statusvariablen

Zustand, Erläuterung im Klartext, aktuelle Leistung, Türzustand, Startzeit,
bisherige Laufzeit, geschätzte Restlaufzeit, typische Dauer und der
Energieverbrauch des letzten Laufs.

## Script-Schnittstelle

```php
GLF_Cycle(InstanceID);          // sofort neu bewerten
GLF_DumpHistory(InstanceID);    // aufgezeichnete Läufe anzeigen
GLF_ResetLearning(InstanceID);  // gelernte Laufzeiten verwerfen
```

## Einrichtung

Zuerst die ToDo-Zentrale anlegen und konfigurieren, dann diese Instanz — sie
verweist auf die Zentrale. Für die Waschmaschine sind die Voreinstellungen als
Startpunkt gedacht; Start- und Endschwelle solltest du am tatsächlichen
Verbrauchsverlauf deines Geräts prüfen.
