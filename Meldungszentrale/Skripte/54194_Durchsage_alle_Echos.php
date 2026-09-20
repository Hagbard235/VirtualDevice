<?php
// Durchsage auf allen Echos (Ultimate Voice)
//
// Sprechen laesst sich dieses Skript auf zwei Wegen:
//
//   - Im Objektbaum ausfuehren: sagt "Fruehstueck ist fertig".
//   - Aus einem anderen Skript:
//         IPS_RunScriptEx(<ID dieses Skripts>, ["Ereignis" => "trockner_fertig"]);
//     Der Raum ist optional und faerbt nur die Formulierung:
//         IPS_RunScriptEx(<ID>, ["Ereignis" => "window_open", "Raum" => "Bad"]);
//
// Ultimate Voice kennt nur vorbereitete Ereignisse, keinen Freitext. Die Liste
// steht in der Instanzkonfiguration von 59348 unter "Test-Event", heute:
//   doorbell, battery_low, washer_done, window_open, motion_detected, welcome,
//   goodbye, temperature_alert, rain_alert, fruehstueck, garage_offen,
//   handy_schauen, timer_done, trockner_fertig
// Fuer Freitext gibt es ECHOREMOTE_TextToSpeech, siehe Skript 15190.
//
// Dieses Skript trifft KEINE Politik: es fragt weder Ruhezeit noch Praesenz ab
// und spricht ueberall. Fuer Meldungen mit Regeln ist die Meldungszentrale
// (21816) zustaendig, nicht dieser Weg hier.

$uv = 59348;   // Instanz Ultimate Voice

// Nur echte Einzelgeraete. Nicht in der Liste stehen mit Absicht:
//   15810 Buero Lautsprecher und 34601 BenachrichtigungsgruppeG - das sind
//         Gruppen (Geraetetyp A3C9PE6TNYLTCH). Ueber sie wuerden die Echos
//         ein zweites Mal sprechen.
//   54495 TestAlt1 - Altbestand.
$echos = [
    57384,   // Schlafzimmer Pop
    58473,   // Andres Echo Spot
    45302,   // Andres Echo Show Hogwards
    18769,   // Andres 2. Echo Pop
    59264,   // Buero Dot
    34274,   // Echo Dot Wohnzimmer
];

$ereignis = trim((string)($_IPS["Ereignis"] ?? "fruehstueck"));
$raum     = trim((string)($_IPS["Raum"] ?? ""));

$ziele = [];
foreach ($echos as $e) {
    if (IPS_InstanceExists($e)) $ziele[] = $e;
    else IPS_LogMessage("Durchsage", "Echo " . $e . " gibt es nicht mehr - uebersprungen");
}
if (count($ziele) === 0) {
    IPS_LogMessage("Durchsage", "Kein Echo erreichbar, nichts gesprochen");
    return false;
}

try {
    $ok = UVD_SpeakMulti($uv, $ereignis, $raum, $ziele);
} catch (\Throwable $e) {
    IPS_LogMessage("Durchsage", "Fehler bei '" . $ereignis . "': " . $e->getMessage());
    return false;
}

IPS_LogMessage("Durchsage", ($ok ? "gesprochen" : "abgelehnt") . ": '" . $ereignis . "'"
    . ($raum !== "" ? " (" . $raum . ")" : "") . " auf " . count($ziele) . " Echos");

return $ok;
