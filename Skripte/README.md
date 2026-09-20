# Skripte

Sicherung der Symcon-Skripte, die zu den Modulen dieser Bibliothek gehören, aber
selbst **keine Module** sind. Symcon lädt aus diesem Ordner nichts — er hat keine
`module.json`.

**Maßgeblich ist das Skript in Symcon.** Dieser Ordner ist die versionierte
Sicherung. Wer ein Skript in Symcon ändert, zieht die Datei hier nach.

Stand: 20.09.2026

## Übersicht

| Datei | Symcon-ID | Name in Symcon | Ort im Objektbaum |
|---|---|---|---|
| `57703_Uebergabe_ToDoZentrale_an_Meldungszentrale.php` | 57703 | Ansage Ultimate Voice (ToDo-Zentrale) | Unsortiert \ VoiceAgent |
| `15190_Meldungszentrale_Sprachkanal.php` | 15190 | MZ Sprachkanal (alle Raeume) | Hilfsscripte \ ToDoListe |
| `25621_Meldungszentrale_Pushkanal.php` | 25621 | MZ Push-Kanal | Hilfsscripte \ ToDoListe |
| `54194_Durchsage_alle_Echos.php` | 54194 | Durchsage auf allen Echos | Unsortiert \ VoiceAgent |

## 57703 — Übergabe ToDo-Zentrale an Meldungszentrale

Sprachziel `uv` der ToDo-Zentrale (38670). Übersetzt eine Aufgabe in eine Meldung
an die Meldungszentrale (21816): Priorität der Aufgabe → Dringlichkeit, Zone
Wohnzimmer, `anAlle`, minutengenauer Dedup-Schlüssel. Trifft selbst keine
Entscheidung über Ausgabe.

Variablen unter dem Skript: _keine_

## 15190 — Sprachkanal der Meldungszentrale

Adapter für alle Sprachkanäle (`sprache_*`). Mit Ereigniskennung spricht Ultimate
Voice (59348), ohne sprechen die Echos den Text über `ECHOREMOTE_TextToSpeech`.
Welche Echos je Kanal, steht in Variablen unter dem Skript:

| Ident | Typ | Wert |
|---|---|---|
| `Echos_sprache_wz` | String | `34274` |
| `Echos_sprache_sz` | String | `57384` |
| `Echos_sprache_fz` | String | `45302` |

## 25621 — Push-Kanal der Meldungszentrale

Adapter für alle Push-Kanäle (`push_*`). Ziele und Sprungziel je Kanal in Variablen
unter dem Skript. Wählt je Ziel die API nach Visualisierungstyp:
`WFC_PushNotification` für WebFront, `VISU_PostNotification` für
Kachel-Visualisierungen.

| Ident | Typ | Wert |
|---|---|---|
| `Ziele_push_andre` | String | `38777` |
| `Sprungziel_push_andre` | Integer | `0` |
| `Ziele_push_vivien` | String | `49268` |
| `Sprungziel_push_vivien` | Integer | `0` |
| `Ziele_push_alle` | String | `38777,49268` |
| `Sprungziel_push_alle` | Integer | `0` |

## 54194 — Durchsage auf allen Echos

Spricht eine Ultimate-Voice-Ansage auf allen Echo-Geräten. Zum Ausführen von Hand
im Objektbaum oder aus einem anderen Skript heraus:

```php
IPS_RunScriptEx(54194, ["Ereignis" => "fruehstueck"]);
```

Ohne Parameter sagt es „Frühstück ist fertig". Der Parameter `Raum` ist optional
und färbt nur die Formulierung. Ultimate Voice kennt nur vorbereitete Ereignisse,
keinen Freitext — welche es gibt, steht in der Instanzkonfiguration von 59348.

Dieses Skript fragt weder Ruhezeit noch Anwesenheit ab und spricht überall. Für
Meldungen mit Regeln ist die Meldungszentrale zuständig, nicht dieser Weg.

Die Echo-Liste steht im Skript. Nicht darin stehen mit Absicht die Gruppen 15810
und 34601 (sonst sprechen Geräte doppelt) sowie 54495 TestAlt1.

Variablen unter dem Skript: _keine_

## Wiederherstellen

1. In Symcon ein Skript anlegen und den Dateiinhalt einfügen.
2. Die Variablen aus der jeweiligen Tabelle als Kinder des Skripts anlegen, mit
   genau diesem **Ident** — die Adapter finden sie darüber, nicht über den Namen.
3. Hat das Skript eine neue ID, sie in der Meldungszentrale beim Kanal (`ScriptID`)
   bzw. in der ToDo-Zentrale beim Sprachziel `uv` eintragen.
