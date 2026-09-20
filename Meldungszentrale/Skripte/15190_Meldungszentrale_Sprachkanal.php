<?php
// Sprachkanal der Meldungszentrale.
//
// Zwei Wege hinaus, je nachdem was die Meldung mitbringt:
//
//   Ereigniskennung -> Ultimate Voice (59348). Fertige Ansagen mit Klang
//                      und Formulierung, die dort gepflegt werden.
//   freier Text     -> ECHOREMOTE_TextToSpeech direkt am Echo. Fuer alles,
//                      wofuer es keine vorbereitete Ansage gibt.
//
// Der Adapter trifft KEINE Politik. Praesenz, Wachsignal, Ruhezeit,
// Dringlichkeit und Vertraulichkeit hat die Meldungszentrale bereits
// geprueft, sonst waere dieses Skript gar nicht aufgerufen worden.
//
// Die Echos je Kanal stehen in Variablen unterhalb dieses Skripts, benannt
// "Echos_<Kanalschluessel>" - ein weiterer Raum ist damit eine Variable und
// ein Kanaleintrag, aber kein eigenes Skript.

$mz = 21816;
$self = $_IPS["SELF"];

if (isset($_IPS["Ruecknahme"])) return;   // Gesprochenes laesst sich nicht zurueckziehen
$auftrag = (string)($_IPS["ZustellauftragID"] ?? "");
$kanal   = (string)($_IPS["Kanal"] ?? "");

$ereignis = trim((string)($_IPS["Ereignis"] ?? ""));
$titel    = trim((string)($_IPS["Titel"] ?? ""));
$text     = trim((string)($_IPS["Text"] ?? ""));

$vid = @IPS_GetObjectIDByIdent("Echos_" . $kanal, $self);
$roh = ($vid !== false && $vid > 0) ? GetValue($vid) : "";
$echos = [];
foreach (explode(",", (string)$roh) as $e) {
    $e = (int)trim($e);
    if ($e > 0 && IPS_InstanceExists($e)) $echos[] = $e;
}
if (count($echos) === 0) {
    if ($auftrag !== "") MZ_Zustellstatus($mz, $auftrag, "fehlgeschlagen", "keine_echos");
    return;
}

$ok = false;
$fehler = "";
try {
    if ($ereignis !== "") {
        UVD_SpeakMulti(59348, $ereignis, "", $echos);
        $ok = true;
    } else {
        // Titel und Text ergeben zusammen einen Satz. Der Punkt dazwischen
        // sorgt fuer die Sprechpause, sonst laeuft beides ineinander.
        $satz = $titel;
        if ($text !== "") $satz = ($satz === "") ? $text : rtrim($satz, ".!?") . ". " . $text;
        if ($satz === "") {
            if ($auftrag !== "") MZ_Zustellstatus($mz, $auftrag, "fehlgeschlagen", "nichts_zu_sagen");
            return;
        }
        foreach ($echos as $e) ECHOREMOTE_TextToSpeech($e, $satz);
        $ok = true;
    }
} catch (\Throwable $e) {
    $fehler = $e->getMessage();
    IPS_LogMessage("MZ-Sprache", "Fehler in " . $kanal . ": " . $fehler);
}

if ($auftrag !== "") {
    // Sprache ist fluechtig: Was der Dienst uebernommen hat, gilt als
    // zugestellt - eine Empfangsbestaetigung gibt es nicht.
    MZ_Zustellstatus($mz, $auftrag, $ok ? "transport_bestaetigt" : "fehlgeschlagen",
                     $ok ? "" : "sprachfehler");
}
