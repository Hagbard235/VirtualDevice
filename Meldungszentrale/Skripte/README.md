# Skripte

Sicherung der Symcon-Skripte, die zu den Modulen dieser Bibliothek gehören, aber
selbst **keine Module** sind. Symcon lädt aus diesem Ordner nichts.

> **Warum dieser Ordner unterhalb der Meldungszentrale liegt:** Symcon prüft jeden
> Ordner im Wurzelverzeichnis einer Bibliothek als Modul und lehnt das Update der
> gesamten Bibliothek ab, wenn dort eine `module.json` fehlt — die Meldung lautet
> „beinhaltet ungültiges Module …". Nicht-Modul-Ordner gehören deshalb unter ein
> Modul, nicht in die Wurzel.

**Maßgeblich ist das Skript in Symcon.** Dieser Ordner ist die versionierte
Sicherung. Wer ein Skript in Symcon ändert, zieht die Datei hier nach.

Stand: 30.09.2026

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

| Ident | Typ | Wert | Raum |
|---|---|---|---|
| `Echos_sprache_wz` | String | `34274` | Wohnzimmer, Echo Dot |
| `Echos_sprache_sz` | String | `57384` | Schlafzimmer, Echo Pop |
| `Echos_sprache_fz` | String | `45302` | Mädchenzimmer, Echo Show |
| `Echos_sprache_buero` | String | `cast:25418` | Büro, Google Home Mini |

**Geräteart als Präfix (seit 30.09.2026):** `echo:<ID>` für Amazon-Geräte,
`cast:<ID>` für Google-Geräte über das Sidecar. Ein Eintrag ohne Präfix gilt als
Echo, die bestehenden Kanäle bleiben dadurch unverändert gültig. Gemischte Räume
sind möglich (`echo:34274, cast:53895`), mit einer Einschränkung: Die Cast-Ausgabe
folgt der Echo-Ausgabe um ein bis zwei Sekunden versetzt, weil die Ansage erst
erzeugt sein muss.

**Wie Cast an die Ansage kommt:** Ultimate Voice erzeugt die MP3 auch dann, wenn
die Echo-Liste leer ist — es spielt sie dann nur nirgends ab. Die Datei landet im
`user`-Verzeichnis, das Symcon im Hausnetz ausliefert, und das Cast-Gerät holt sie
sich selbst. Der Dateiname kommt aus dem Aufruf nicht zurück, deshalb nimmt der
Adapter die jüngste `uv_*.mp3`. Das ist ein Behelf, bis das Modul die Adresse
selbst liefert (Anforderung 24, `UVD_PrepareText`).

**Freien Text kann Cast nicht** — es spielt nur Dateien ab. Ein reiner Cast-Kanal
bekommt deshalb `Kann = ereignis` und wird von Textmeldungen gar nicht erst
angesprochen.

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
