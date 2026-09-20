<?php
// Push-Kanal der Meldungszentrale -> Notification Control (27850).
//
// Jeder Kanal hat unter diesem Skript eigene Variablen:
//   Ziele_<Kanal>      kommagetrennte Visualisierungen, an die gepusht wird
//   Sprungziel_<Kanal> Objekt, das beim Antippen geoeffnet wird (0 = keins)
// Ein weiterer Push-Weg ist damit ein Kanaleintrag und zwei Variablen, kein
// neues Skript. Zwei Kanaele teilen sich nie versehentlich ein Ziel - das
// wuerde vertrauliche Hinweise an Geraete tragen, fuer die sie nicht gedacht
// sind.
//
// Symcon kennt zwei Visualisierungsarten mit verschiedener Push-API. Der
// Adapter waehlt sie je Ziel anhand des Modultyps:
//   WebFront        WFC_PushNotification(ID, Titel, Text, Sound, Ziel) -> bool
//                   Sprungziel muss im mobilen Bereich des WebFronts liegen.
//   Kachel-Visu     VISU_PostNotification(ID, Titel, Text, Typ, Ziel) -> ID
//
// Dieser Adapter trifft KEINE Politik. Er kann keine Aktionen (Push kennt
// keine Knoepfe) und nichts zurueckholen (eine Push bleibt auf dem Geraet).
// Deshalb traegt jeder Text seinen Zeitpunkt: Er wird womoeglich gelesen,
// wenn die Sache laengst erledigt ist.

const WEBFRONT = "{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}";
const KACHEL   = "{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}";

$mz = 21816;
$self = $_IPS["SELF"];

if (isset($_IPS["Ruecknahme"])) return;
$auftrag = (string)($_IPS["ZustellauftragID"] ?? "");
$kanal   = (string)($_IPS["Kanal"] ?? "");

function kanalWert(int $self, string $name) {
    $id = @IPS_GetObjectIDByIdent($name, $self);
    return ($id !== false && $id > 0) ? GetValue($id) : null;
}

$ziele = [];
foreach (explode(",", (string)kanalWert($self, "Ziele_" . $kanal)) as $z) {
    $z = (int)trim($z);
    if ($z > 0 && IPS_InstanceExists($z)) $ziele[] = $z;
}
$sprung = (int)kanalWert($self, "Sprungziel_" . $kanal);

if (count($ziele) === 0) {
    if ($auftrag !== "") MZ_Zustellstatus($mz, $auftrag, "fehlgeschlagen", "keine_ziele");
    return;
}

$titel = trim((string)($_IPS["Titel"] ?? ""));
$text  = trim((string)($_IPS["Text"] ?? ""));
if ($titel === "") $titel = trim((string)($_IPS["Ereignis"] ?? "Meldung"));
// Symcon begrenzt Titel auf 32 und Text auf 256 BYTES, nicht Zeichen. Ein
// Umlaut belegt in UTF-8 zwei Bytes - "Wartung: Funkausloeser Rauchmel" mit
// echtem oe waren 33 Bytes, und WFC_PushNotification lehnte ohne Ausnahme mit
// false ab. mb_strcut kuerzt auf Bytes, ohne ein Zeichen zu zerschneiden.
$stempel = " (" . date("H:i") . ")";
if (strlen($text . $stempel) <= 256) $text .= $stempel;
$titel = mb_strcut($titel, 0, 32, "UTF-8");
$text  = mb_strcut($text, 0, 256, "UTF-8");

$erreicht = 0;
foreach ($ziele as $z) {
    $art = IPS_GetInstance($z)["ModuleInfo"]["ModuleID"];
    try {
        if ($art === WEBFRONT) {
            $ok = WFC_PushNotification($z, $titel, $text, "", $sprung);
        } elseif ($art === KACHEL) {
            $ok = VISU_PostNotification($z, $titel, $text, "Information", $sprung) > 0;
        } else {
            IPS_LogMessage("MZ-Push", "Kanal " . $kanal . ": Ziel " . $z . " ist keine Visualisierung");
            $ok = false;
        }
    } catch (\Throwable $e) {
        IPS_LogMessage("MZ-Push", "Kanal " . $kanal . ", Ziel " . $z . ": " . $e->getMessage());
        $ok = false;
    }
    if ($ok) $erreicht++;
}

if ($auftrag !== "") {
    // Fluechtig: Was Notification Control uebernommen hat, gilt als
    // zugestellt. Teilausfaelle stehen im Log.
    MZ_Zustellstatus($mz, $auftrag, $erreicht > 0 ? "transport_bestaetigt" : "fehlgeschlagen",
                     $erreicht > 0 ? "" : "push_fehler");
}
