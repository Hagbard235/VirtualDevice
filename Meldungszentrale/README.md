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
