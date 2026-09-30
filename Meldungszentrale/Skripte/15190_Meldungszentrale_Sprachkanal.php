<?php
// Sprachkanal der Meldungszentrale.
//
// Zwei Arten von Lautsprechern, zwei Wege hinaus:
//
//   echo:<ID>   Amazon Echo. Mit Ereigniskennung spricht Ultimate Voice,
//               ohne spricht das Geraet den Text selbst (ECHOREMOTE).
//   cast:<ID>   Google Home, Nest, Google TV ueber das Sidecar. Cast kann
//               keinen Text sprechen, es spielt nur Dateien ab - deshalb
//               laesst dieses Skript die Ansage von Ultimate Voice erzeugen
//               und reicht dem Geraet ihre Adresse im Hausnetz.
//
// Ein Eintrag ohne Praefix gilt als Echo; die bestehenden Kanaele bleiben
// dadurch unveraendert gueltig.
//
// Der Adapter trifft KEINE Politik. Praesenz, Wachsignal, Ruhezeit,
// Dringlichkeit und Vertraulichkeit hat die Meldungszentrale bereits
// geprueft, sonst waere dieses Skript gar nicht aufgerufen worden.
//
// Die Geraete je Kanal stehen in Variablen unterhalb dieses Skripts, benannt
// "Echos_<Kanalschluessel>" - ein weiterer Raum ist damit eine Variable und
// ein Kanaleintrag, aber kein eigenes Skript.

$mz = 21816;
$uv = 59348;   // Ultimate Voice
$self = $_IPS["SELF"];

// Adresse, unter der Symcon sein user-Verzeichnis im Hausnetz ausliefert.
// Das Cast-Geraet holt sich die Datei selbst, es muss also von DORT
// erreichbar sein - localhost oder 127.0.0.1 funktionieren nicht.
$basisURL = "http://192.168.178.90:3777/user/";

if (isset($_IPS["Ruecknahme"])) return;   // Gesprochenes laesst sich nicht zurueckziehen
$auftrag = (string)($_IPS["ZustellauftragID"] ?? "");
$kanal   = (string)($_IPS["Kanal"] ?? "");

$ereignis = trim((string)($_IPS["Ereignis"] ?? ""));
$titel    = trim((string)($_IPS["Titel"] ?? ""));
$text     = trim((string)($_IPS["Text"] ?? ""));

$vid = @IPS_GetObjectIDByIdent("Echos_" . $kanal, $self);
$roh = ($vid !== false && $vid > 0) ? GetValue($vid) : "";

$echos = [];
$casts = [];
foreach (explode(",", (string)$roh) as $eintrag) {
    $eintrag = trim($eintrag);
    if ($eintrag === "") continue;

    $art = "echo";
    if (strpos($eintrag, ":") !== false) {
        list($art, $eintrag) = explode(":", $eintrag, 2);
        $art = strtolower(trim($art));
        $eintrag = trim($eintrag);
    }
    $id = (int)$eintrag;
    if ($id <= 0 || !IPS_InstanceExists($id)) continue;

    if ($art === "cast") $casts[] = $id;
    else                 $echos[] = $id;
}

if (count($echos) === 0 && count($casts) === 0) {
    if ($auftrag !== "") MZ_Zustellstatus($mz, $auftrag, "fehlgeschlagen", "keine_geraete");
    return;
}

$ok = false;
$fehler = "";

try {
    if ($ereignis !== "") {
        // Vor dem Erzeugen merken, was es schon gibt: Ultimate Voice legt die
        // fertige MP3 im user-Verzeichnis ab, gibt ihren Namen aber nicht
        // zurueck. Bis das Modul das kann (Anforderung 24, UVD_PrepareText),
        // wird die juengste Datei nach dem Aufruf genommen.
        $vorher = 0;
        if (count($casts) > 0) $vorher = time();

        // Auch mit leerer Echo-Liste erzeugt Ultimate Voice die Ansage - es
        // spielt sie dann nur nirgends ab. Genau das braucht ein Raum, in dem
        // ausschliesslich Cast-Geraete stehen.
        $gesprochen = UVD_SpeakMulti($uv, $ereignis, "", $echos);
        if (count($echos) > 0 && $gesprochen) $ok = true;

        if (count($casts) > 0) {
            $datei = "";
            $zeit  = 0;
            foreach (glob(IPS_GetKernelDir() . "user/uv_*.mp3") as $f) {
                $m = filemtime($f);
                if ($m > $zeit) { $zeit = $m; $datei = basename($f); }
            }
            if ($datei === "") {
                $fehler = "keine_ansagedatei";
            } else {
                if ($zeit < $vorher - 5) {
                    // Nichts Neues entstanden - vermutlich ein Cache-Treffer,
                    // bei dem die vorhandene Datei unveraendert blieb. Die
                    // juengste ist dann trotzdem die richtige.
                    IPS_LogMessage("MZ-Sprache", "Cast: keine frische Datei, nehme " . $datei);
                }
                $url = $basisURL . $datei;
                $beschriftung = $titel !== "" ? $titel : "Durchsage";
                foreach ($casts as $c) {
                    HAIPSD_PlayMedia($c, $url, "audio/mpeg", $beschriftung, false);
                }
                $ok = true;
            }
        }
    } else {
        // Freier Text. Titel und Text ergeben zusammen einen Satz; der Punkt
        // dazwischen sorgt fuer die Sprechpause, sonst laeuft beides
        // ineinander.
        $satz = $titel;
        if ($text !== "") $satz = ($satz === "") ? $text : rtrim($satz, ".!?") . ". " . $text;
        if ($satz === "") {
            if ($auftrag !== "") MZ_Zustellstatus($mz, $auftrag, "fehlgeschlagen", "nichts_zu_sagen");
            return;
        }
        foreach ($echos as $e) ECHOREMOTE_TextToSpeech($e, $satz);
        if (count($echos) > 0) $ok = true;

        // Cast kann keinen freien Text: Es spielt Dateien ab, und einen
        // Dienst, der hier eine erzeugt, gibt es noch nicht (Anforderung 24,
        // Freitext im Charakter). Bis dahin bleiben diese Geraete bei
        // Textmeldungen stumm - sichtbar im Protokoll, nicht stillschweigend.
        if (count($casts) > 0) {
            IPS_LogMessage("MZ-Sprache", "Kanal " . $kanal . ": " . count($casts)
                . " Cast-Geraet(e) uebersprungen - freier Text wird dort nicht unterstuetzt");
        }
    }
} catch (\Throwable $e) {
    $fehler = $e->getMessage();
    IPS_LogMessage("MZ-Sprache", "Fehler in " . $kanal . ": " . $fehler);
}

if ($auftrag !== "") {
    // Sprache ist fluechtig: Was der Dienst uebernommen hat, gilt als
    // zugestellt - eine Empfangsbestaetigung gibt es nicht. Beim Cast-Weg
    // bestaetigt der Aufruf ohnehin nur die Uebergabe an das Sidecar.
    MZ_Zustellstatus($mz, $auftrag, $ok ? "transport_bestaetigt" : "fehlgeschlagen",
                     $ok ? "" : ($fehler !== "" ? "sprachfehler" : "nicht_zugestellt"));
}
