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
], JSON_UNESCAPED_UNICODE));

$d = json_decode($ergebnis, true);
if (!is_array($d) || !($d['ok'] ?? false)) {
    IPS_LogMessage('MZ-Uebergabe', 'Meldung nicht angenommen: ' . $ergebnis);
}
