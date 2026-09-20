# Meldungszentrale

Nimmt Meldungen aus dem Haus entgegen und entscheidet, wer sie wann ueber welchen Kanal erhaelt.

Konzept, Anforderungen und Pruefhistorie: `11_Konzept_Meldungszentrale.md` im Projektordner.

## Grundsaetze

- Quellen kennen keine Kanaele. Kanaele treffen keine Politik und fuehren keine Aktionsskripte aus.
- Jede angenommene Meldung wird gespeichert, **bevor** ein Kanal versucht wird. Die Zustellgarantie haengt an der Meldungsdatei, nicht an einem Kanal.
- Harte Schutzfilter (Adressierung, Darstellbarkeit, Vertraulichkeit, Aktionserlaubnis) gelten immer - auch bei abgeschalteter Politik und im Alarm-Fallback.
- Aktionen wirken **hoechstens einmal**. Meldungen tragen niemals eine Skript-ID, nur Kennungen aus der Allowlist.

## Stand

Phase 1: Annahme, Persistenz, Journal, Recovery, WebHook, Anzeige. Die weichen Politikfilter sind implementiert und ueber `PolitikAktiv` abschaltbar.

## Erinnern statt neu melden

Eine Erinnerung ist **keine neue Meldung**. Es gibt kein neues Ereignis — dieselbe
Sache wird nur ein zweites Mal zugestellt. Dafür gibt es zwei Funktionen:

```php
MZ_Erinnern($id, 'todozentrale', 'todo:WMFERTIG');   // erneut zustellen
MZ_Erledigt($id, 'todozentrale', 'todo:WMFERTIG');   // Meldung zurückziehen
```

Beide finden die Meldung über das Feld `sache`, eine stabile Kennung, die die
Quelle beim Melden mitgibt und die über Erstansage, Erinnerungen und Erledigung
hinweg gleich bleibt. Es ist ausdrücklich **nicht** der `dedupKey`: Der fängt nur
einen wiederholten Zustellversuch desselben Ereignisses ab.

Beim Erinnern entsteht kein neuer Eintrag, kein neuer Flutschutz-Vorfall und keine
zweite Zeile in der Anzeige. Bedient werden nur Kanäle mit `Lebensdauer =
fluechtig` — Sprache und Push —, denn die Anzeige führt die Meldung bereits. Die
Filterkette gilt unverändert: Wer nachts nicht gestört werden darf, wird auch von
einer Erinnerung nicht gestört.

**Den Takt gibt die Quelle.** Sie kennt Intervall, Obergrenze und ihre eigenen
Bedingungen — etwa dass an die Waschmaschine nicht erinnert wird, solange der
Trockner läuft. Die Meldungszentrale zählt nur mit (`erinnerungen`,
`letzteErinnerung`).

Ohne diese Trennung entstand für jede Erinnerung eine eigene Meldung: Am
20.09.2026 standen zwölf Einträge „Waschmaschine ausräumen" nebeneinander, jeder
mit 24 Stunden Gültigkeit, während die Wäsche längst draußen war.

### Was der Zähler zählt

Die Variable „Offene Meldungen" zählt genau das, was die gemeinsame Anzeige auch
zeigt: die **nicht vertraulichen** Meldungen. Zählte sie alle mit, verriete die
Zahl die Existenz einer vertraulichen Meldung, und die Kachel widerspräche sich
selbst — „1" über „Keine offenen Meldungen". Wer eine vertrauliche Meldung
bekommt, sieht sie in seiner eigenen Visualisierung und als Push.

Die Fälligkeitsberechnung umfasst weiterhin **alle** Meldungen, auch die
vertraulichen — sonst liefe eine davon über ihre Gültigkeit hinaus.
