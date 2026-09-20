<?php
// Sprachziel der ToDo-Zentrale -> Meldungszentrale (21816).
//
// Dieses Skript hat frueher selbst entschieden, ob und wohin gesprochen
// wird - inklusive Nachtlogik und Raumauswahl. Das war der falsche Ort:
// Ein Adapter soll uebersetzen, nicht Politik machen. Jetzt meldet es nur
// noch, und die Meldungszentrale entscheidet anhand ihrer Zonen ueber
// Praesenz, Ruhezeit und Dringlichkeit.
//
// Die ToDo-Zentrale uebergibt Titel, Text, die Ansagekennung Event und
// die Prioritaet der Aufgabe.

$mz = 21816;

// Anlass und Sache kommen aus der ToDo-Zentrale. Die Sache bleibt ueber
// Erstansage, Erinnerungen und Erledigung hinweg dieselbe Kennung - nur
// dadurch kann die Meldungszentrale erkennen, dass es um dieselbe Sache geht.
$anlass = isset($_IPS['Anlass']) ? trim((string)$_IPS['Anlass']) : 'neu';
$sache  = isset($_IPS['Sache'])  ? trim((string)$_IPS['Sache'])  : '';

// Erledigt: Die offene Meldung wird zurueckgezogen, es entsteht keine neue.
// Ohne das bliebe "Waschmaschine ausraeumen" bis zum Ablauf der Gueltigkeit
// stehen, obwohl die Waesche laengst draussen ist.
if ($anlass === 'erledigt') {
    if ($sache !== '') MZ_Erledigt($mz, 'todozentrale', $sache);
    return;
}

$event = isset($_IPS['Event']) ? trim($_IPS['Event']) : '';
if ($event === '') {
    // Ohne Kennung kann Ultimate Voice nichts sagen - der Dienst kennt nur
    // Event-Typen, keinen freien Text.
    return;
}

// Prioritaet der Aufgabe (0 niedrig, 1 normal, 2 wichtig, 3 kritisch)
// als Dringlichkeit weitergeben. Frueher stand hier fest 'normal' - jede
// Aufgabe kam in der Zentrale gleich wichtig an.
// Kritisch wird bewusst nur 'wichtig': 'alarm' duerfen nur
// gefahrenberechtigte Quellen, die ToDo-Zentrale ist keine.
$prio = (int)($_IPS['Prioritaet'] ?? -1);
$dringlichkeit = [0 => 'info', 1 => 'normal', 2 => 'wichtig', 3 => 'wichtig'][$prio] ?? 'normal';

// Erinnerung: dieselbe Meldung, ein zweites Mal zugestellt. Gibt es keine
// offene Meldung mehr - abgelaufen, zurueckgezogen, Neustart -, faellt das
// Skript auf eine regulaere Meldung zurueck.
if ($anlass === 'erinnerung' && $sache !== '') {
    $r = json_decode(MZ_Erinnern($mz, 'todozentrale', $sache), true);
    if (is_array($r) && ($r['ok'] ?? false)) return;
    IPS_LogMessage('MZ-Uebergabe', 'Keine offene Meldung zu ' . $sache . ', melde neu');
}

$ergebnis = MZ_Melden($mz, json_encode([
    'quelle'        => 'todozentrale',
    'ereignis'      => $event,
    'titel'         => (string)($_IPS['Titel'] ?? ''),
    'text'          => (string)($_IPS['Text'] ?? ''),
    'dringlichkeit' => $dringlichkeit,
    // Vorerst nur das Wohnzimmer als Zone. Weitere Raeume kommen hinzu,
    // sobald die Zuordnung Echo -> Raum geklaert ist (offener Punkt O2).
    'zone'          => ['wohnzimmer'],
    // Auch an alle: Die Push-Kanaele entscheiden selbst ueber ihre
    // Mindestdringlichkeit. So pusht eine wichtige Aufgabe wie
    // 'Waschmaschine fertig', eine normale wie der Trockner nicht.
    'anAlle'        => true,
    // Wiederholungen derselben Meldung fallen in der Zentrale unter den
    // Flutschutz; ein eigener Schluessel je Ereignis genuegt dafuer.
    // Minutengenau: Eine Erinnerung sendet dieselbe Kennung wie die erste
    // Ansage. Mit Stundenschluessel galt sie innerhalb derselben Stunde als
    // Doppel und wurde verschluckt.
    'dedupKey'      => $event . '-' . date('Y-m-d-H-i'),
    // Stabile Kennung der Sache - ueber sie findet die Zentrale die Meldung
    // bei Erinnerungen und beim Erledigen wieder.
    'sache'         => $sache,
], JSON_UNESCAPED_UNICODE));

$d = json_decode($ergebnis, true);
if (!is_array($d) || !($d['ok'] ?? false)) {
    IPS_LogMessage('MZ-Uebergabe', 'Meldung nicht angenommen: ' . $ergebnis);
}
