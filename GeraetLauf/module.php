<?php

/**
 * Geraetelauf-Erkennung
 *
 * Erkennt am Stromverbrauch, ob ein Haushaltsgeraet laeuft oder fertig ist,
 * schaetzt die Restlaufzeit und meldet die passenden Aufgaben an die
 * ToDo-Zentrale. Eine Instanz je Geraet - Waschmaschine, Trockner und
 * Spuelmaschine unterscheiden sich nur in der Konfiguration.
 *
 * Die Zustandswechsel verlangen Haltezeiten: erst wenn die Leistung
 * durchgehend ueber beziehungsweise unter der Schwelle liegt, wird
 * umgeschaltet. Einweichpausen koennen so kein Programmende vortaeuschen.
 */
class GeraetLauf extends IPSModule {

    const STATE_BEREIT = 0;
    const STATE_LAEUFT = 1;
    const STATE_FERTIG = 2;

    // Vergleichsoperatoren der ToDo-Zentrale (dort gespiegelt).
    const CMP_WAHR   = 4;
    const CMP_FALSCH = 5;

    // Quittierungsarten der ToDo-Zentrale.
    const ACK_AUTOMATISCH = 1;
    const ACK_BEIDES      = 2;
    const ACK_INFO        = 3;

    // Sprech-Bitmaske der ToDo-Zentrale.
    const SPEAK_NEU        = 1;
    const SPEAK_ERINNERUNG = 2;

    public function Create() {
        parent::Create();

        $this->RegisterPropertyString("DeviceName", "Waschmaschine");
        $this->RegisterPropertyInteger("PowerID", 0);
        $this->RegisterPropertyInteger("DoorID", 0);
        $this->RegisterPropertyBoolean("DoorOpenWhenFalse", true);

        // Erkennung
        $this->RegisterPropertyFloat("StartWatt", 10.0);
        $this->RegisterPropertyInteger("StartHoldSeconds", 60);
        $this->RegisterPropertyFloat("EndWatt", 3.0);
        $this->RegisterPropertyInteger("EndHoldSeconds", 600);
        $this->RegisterPropertyInteger("MinRunMinutes", 15);
        $this->RegisterPropertyFloat("MinPeakWatt", 500.0);

        // Restlaufzeit
        $this->RegisterPropertyInteger("LearnRuns", 10);
        $this->RegisterPropertyInteger("DefaultRunMinutes", 90);
        $this->RegisterPropertyFloat("SpinWatt", 300.0);
        $this->RegisterPropertyInteger("SpinRemainingMinutes", 8);

        // Anbindung an die ToDo-Zentrale
        $this->RegisterPropertyInteger("ToDoInstanceID", 0);
        $this->RegisterPropertyString("IdentRunning", "WMLAEUFT");
        $this->RegisterPropertyString("IdentDone", "WMFERTIG");
        $this->RegisterPropertyString("Category", "");
        $this->RegisterPropertyBoolean("ShowRunningTile", true);
        $this->RegisterPropertyString("TextRunning", "Waschmaschine laeuft");
        $this->RegisterPropertyString("TextDone", "Waschmaschine ausraeumen");
        $this->RegisterPropertyString("SpeechDone", "Die Waschmaschine ist fertig");
        $this->RegisterPropertyInteger("DonePriority", 2);
        $this->RegisterPropertyInteger("RemindMinutes", 30);
        $this->RegisterPropertyInteger("RemindMax", 0);
        $this->RegisterPropertyInteger("SuppressVarID", 0);
        $this->RegisterPropertyBoolean("SuppressWhenTrue", true);

        $this->RegisterPropertyInteger("CheckInterval", 30);

        // --- Attribute ---
        // Zeitpunkt, seit dem die Leistung durchgehend ueber/unter der
        // jeweiligen Schwelle liegt. 0 = Bedingung gerade nicht erfuellt.
        $this->RegisterAttributeFloat("AboveSince", 0.0);
        $this->RegisterAttributeFloat("BelowSince", 0.0);

        $this->RegisterAttributeFloat("RunStart", 0.0);
        $this->RegisterAttributeFloat("PeakWatt", 0.0);
        $this->RegisterAttributeFloat("EnergyWs", 0.0);
        $this->RegisterAttributeFloat("LastSample", 0.0);
        // Zeitpunkt des erkannten Schleuderns. 0 = nicht erkannt oder verworfen.
        $this->RegisterAttributeFloat("SpinAt", 0.0);
        $this->RegisterAttributeString("History", "[]");

        $this->CreateProfiles();

        $this->RegisterVariableInteger("State", "Zustand", "GLF.State", 10);
        $this->RegisterVariableString("Info", "Erlaeuterung", "", 20);
        $this->RegisterVariableFloat("Power", "Leistung", "~Watt", 30);
        $this->RegisterVariableBoolean("DoorOpen", "Tuer offen", "~Switch", 40);
        $this->RegisterVariableInteger("RunStartVar", "Start", "~UnixTimestamp", 50);
        $this->RegisterVariableFloat("RunMinutes", "Laufzeit", "GLF.Minuten", 60);
        $this->RegisterVariableFloat("RemainingMinutes", "Restlaufzeit", "GLF.Minuten", 70);
        $this->RegisterVariableFloat("TypicalMinutes", "Typische Dauer", "GLF.Minuten", 80);
        $this->RegisterVariableFloat("EnergyRun", "Energie letzter Lauf", "~Electricity", 90);

        $this->RegisterTimer("CycleTimer", 0, 'GLF_Cycle($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                @$this->UnregisterMessage($senderID, $message);
            }
        }

        // Auf die Tuer sofort reagieren - eine Erinnerung, die nach dem
        // Oeffnen noch einmal kommt, waere genau der alte Fehler.
        $doorID = $this->ReadPropertyInteger("DoorID");
        if ($doorID > 0 && IPS_VariableExists($doorID)) {
            $this->RegisterMessage($doorID, VM_UPDATE);
        }

        $interval = $this->ReadPropertyInteger("CheckInterval");
        if ($interval < 10) $interval = 10;
        $this->SetTimerInterval("CycleTimer", $interval * 1000);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Cycle();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != VM_UPDATE) return;
        $this->Cycle();
    }

    /**
     * Hauptschleife. Erkennung, Prognose und Aufgabenpflege.
     */
    public function Cycle() {
        $powerID = $this->ReadPropertyInteger("PowerID");
        if ($powerID <= 0 || !IPS_VariableExists($powerID)) {
            $this->SetStatus(201);
            return;
        }
        if ($this->GetStatus() == 201) $this->SetStatus(102);

        $power = (float)GetValue($powerID);
        $this->SetValue("Power", $power);

        $doorOpen = $this->IsDoorOpen();
        $this->SetValue("DoorOpen", $doorOpen);

        $this->IntegrateEnergy($power);
        $this->TrackThresholds($power);

        switch ($this->GetValue("State")) {
            case self::STATE_BEREIT: $this->HandleBereit($power); break;
            case self::STATE_LAEUFT: $this->HandleLaeuft($power); break;
            case self::STATE_FERTIG: $this->HandleFertig($power, $doorOpen); break;
        }
    }

    // ------------------------------------------------------------------
    // Zustaende
    // ------------------------------------------------------------------

    private function HandleBereit(float $power) {
        $this->SetValue("RunMinutes", 0);
        $this->SetValue("RemainingMinutes", 0);

        if ($this->HeldFor("AboveSince", $this->ReadPropertyInteger("StartHoldSeconds"))) {
            $this->StartRun();
            return;
        }

        $this->SetValue("Info", sprintf(
            "Bereit. %s zieht %.0f W, Startschwelle sind %.0f W.",
            $this->ReadPropertyString("DeviceName"), $power, $this->ReadPropertyFloat("StartWatt")
        ));
    }

    private function HandleLaeuft(float $power) {
        // Spitzenleistung mitschreiben - daran erkennt man spaeter, ob es ein
        // echter Programmlauf war oder nur die Elektronik.
        if ($power > $this->ReadAttributeFloat("PeakWatt")) {
            $this->WriteAttributeFloat("PeakWatt", $power);
        }

        $elapsed = $this->ElapsedMinutes();
        $this->SetValue("RunMinutes", round($elapsed, 1));

        $this->UpdateRemaining($power, $elapsed);

        if ($this->HeldFor("BelowSince", $this->ReadPropertyInteger("EndHoldSeconds"))) {
            $this->FinishRun($elapsed);
            return;
        }

        $remaining = $this->GetValue("RemainingMinutes");
        $this->SetValue("Info", sprintf(
            "%s laeuft seit %s, aktuell %.0f W.%s",
            $this->ReadPropertyString("DeviceName"),
            $this->FormatMinutes($elapsed),
            $power,
            $remaining > 0 ? " Voraussichtlich noch " . $this->FormatMinutes($remaining) . "." : ""
        ));
    }

    private function HandleFertig(float $power, bool $doorOpen) {
        // Tuer auf heisst: jemand war da. Damit ist nichts mehr auszuraeumen.
        if ($doorOpen) {
            $this->SendDebug("Zustand", "Tuer geoeffnet - Waesche gilt als entnommen", 0);
            $this->ClearToDo($this->ReadPropertyString("IdentDone"));
            $this->SetState(self::STATE_BEREIT, $this->ReadPropertyString("DeviceName") . " ist leergeraeumt.");
            return;
        }

        // Ein neuer Programmstart beendet den Fertig-Zustand ebenfalls.
        if ($this->HeldFor("AboveSince", $this->ReadPropertyInteger("StartHoldSeconds"))) {
            $this->ClearToDo($this->ReadPropertyString("IdentDone"));
            $this->StartRun();
            return;
        }

        $this->SetValue("Info", sprintf(
            "%s ist fertig, Tuer noch geschlossen - Waesche wartet.",
            $this->ReadPropertyString("DeviceName")
        ));
    }

    private function StartRun() {
        $now = microtime(true);
        $this->WriteAttributeFloat("RunStart", $now);
        $this->WriteAttributeFloat("PeakWatt", 0.0);
        $this->WriteAttributeFloat("EnergyWs", 0.0);
        $this->WriteAttributeFloat("SpinAt", 0.0);

        $this->SetValue("RunStartVar", (int)$now);
        $this->SetValue("RunMinutes", 0);
        $this->SetState(self::STATE_LAEUFT, $this->ReadPropertyString("DeviceName") . " hat gestartet.");

        $this->ClearToDo($this->ReadPropertyString("IdentDone"));

        if ($this->ReadPropertyBoolean("ShowRunningTile")) {
            $this->SetToDo($this->ReadPropertyString("IdentRunning"), $this->ReadPropertyString("TextRunning"), [
                'kategorie'   => $this->ReadPropertyString("Category"),
                'quittierung' => self::ACK_INFO,
                'prio'        => 0,
                'farbe'       => "GELB",
                'schalter'    => "...laeuft...",
                'erinnerung'  => 0,
                'sprechen'    => 0
            ]);
        }
    }

    private function FinishRun(float $elapsed) {
        $peak = $this->ReadAttributeFloat("PeakWatt");
        $minMinutes = $this->ReadPropertyInteger("MinRunMinutes");
        $minPeak = $this->ReadPropertyFloat("MinPeakWatt");

        $this->ClearToDo($this->ReadPropertyString("IdentRunning"));

        // Plausibilitaet: zu kurz oder ohne echte Last war kein Programmlauf,
        // sondern zum Beispiel nur das Display oder ein Abpumpvorgang.
        if ($elapsed < $minMinutes || $peak < $minPeak) {
            $this->SendDebug("Zustand", sprintf(
                "Kein echter Lauf: %.0f min (min. %d), Spitze %.0f W (min. %.0f W)",
                $elapsed, $minMinutes, $peak, $minPeak
            ), 0);
            $this->SetState(self::STATE_BEREIT, sprintf(
                "Kurzer Verbrauch von %s ignoriert (%s, Spitze %.0f W).",
                $this->ReadPropertyString("DeviceName"), $this->FormatMinutes($elapsed), $peak
            ));
            return;
        }

        $kwh = $this->ReadAttributeFloat("EnergyWs") / 3600000.0;
        $this->SetValue("EnergyRun", round($kwh, 3));
        $this->RecordRun($elapsed, $kwh, $peak);

        $this->SetValue("RemainingMinutes", 0);
        $this->SetState(self::STATE_FERTIG, sprintf(
            "%s ist fertig (%s, %.2f kWh).",
            $this->ReadPropertyString("DeviceName"), $this->FormatMinutes($elapsed), $kwh
        ));

        // Die Aufgabe erledigt sich selbst, sobald die Tuer aufgeht. Das ist
        // eine Bedingung auf den Zustand, kein einmaliges Ereignis.
        $doorID = $this->ReadPropertyInteger("DoorID");
        $options = [
            'kategorie'   => $this->ReadPropertyString("Category"),
            // Antippen erledigt sie ebenso wie das Oeffnen der Tuer - je
            // nachdem, was zuerst passiert.
            'quittierung' => self::ACK_BEIDES,
            'prio'        => $this->ReadPropertyInteger("DonePriority"),
            'farbe'       => "ROT",
            'schalter'    => "...Fertig!...",
            'sprache'     => $this->ReadPropertyString("SpeechDone"),
            'erinnerung'  => $this->ReadPropertyInteger("RemindMinutes") * 60,
            'erinnerungMax' => $this->ReadPropertyInteger("RemindMax"),
            'sprechen'    => self::SPEAK_NEU | self::SPEAK_ERINNERUNG
        ];

        if ($doorID > 0) {
            $options['clearVar'] = $doorID;
            $options['clearMode'] = $this->ReadPropertyBoolean("DoorOpenWhenFalse") ? self::CMP_FALSCH : self::CMP_WAHR;
        }

        $suppressID = $this->ReadPropertyInteger("SuppressVarID");
        if ($suppressID > 0) {
            $options['stummVar'] = $suppressID;
            $options['stummMode'] = $this->ReadPropertyBoolean("SuppressWhenTrue") ? self::CMP_WAHR : self::CMP_FALSCH;
        }

        $this->SetToDo($this->ReadPropertyString("IdentDone"), $this->ReadPropertyString("TextDone"), $options);
    }

    private function SetState(int $state, string $info) {
        if ($this->GetValue("State") != $state) {
            $this->SendDebug("Zustand", "Wechsel: $info", 0);
        }
        $this->SetValue("State", $state);
        $this->SetValue("Info", $info);
    }

    // ------------------------------------------------------------------
    // Messung
    // ------------------------------------------------------------------

    /**
     * Merkt sich, seit wann die Leistung durchgehend ueber der Start- bzw.
     * unter der Endschwelle liegt. Ein einzelner Ausreisser setzt den
     * jeweiligen Zeitstempel zurueck.
     */
    private function TrackThresholds(float $power) {
        $now = microtime(true);

        if ($power >= $this->ReadPropertyFloat("StartWatt")) {
            if ($this->ReadAttributeFloat("AboveSince") <= 0) $this->WriteAttributeFloat("AboveSince", $now);
        } else {
            $this->WriteAttributeFloat("AboveSince", 0.0);
        }

        if ($power < $this->ReadPropertyFloat("EndWatt")) {
            if ($this->ReadAttributeFloat("BelowSince") <= 0) $this->WriteAttributeFloat("BelowSince", $now);
        } else {
            $this->WriteAttributeFloat("BelowSince", 0.0);
        }
    }

    private function HeldFor(string $attribute, int $seconds): bool {
        $since = $this->ReadAttributeFloat($attribute);
        if ($since <= 0) return false;
        return (microtime(true) - $since) >= $seconds;
    }

    /**
     * Energie mitzaehlen. Grob, weil nur im Pruefintervall abgetastet wird -
     * fuer die Fortschrittsabschaetzung reicht das.
     */
    private function IntegrateEnergy(float $power) {
        $now = microtime(true);
        $last = $this->ReadAttributeFloat("LastSample");
        $this->WriteAttributeFloat("LastSample", $now);

        if ($last <= 0) return;
        if ($this->GetValue("State") != self::STATE_LAEUFT) return;

        $dt = $now - $last;
        // Nach einem Neustart oder langer Pause nicht hochrechnen.
        if ($dt <= 0 || $dt > 600) return;

        $this->WriteAttributeFloat("EnergyWs", $this->ReadAttributeFloat("EnergyWs") + ($power * $dt));
    }

    private function ElapsedMinutes(): float {
        $start = $this->ReadAttributeFloat("RunStart");
        if ($start <= 0) return 0.0;
        return (microtime(true) - $start) / 60.0;
    }

    private function IsDoorOpen(): bool {
        $doorID = $this->ReadPropertyInteger("DoorID");
        if ($doorID <= 0 || !IPS_VariableExists($doorID)) return false;
        $raw = GetValueBoolean($doorID);
        return $this->ReadPropertyBoolean("DoorOpenWhenFalse") ? !$raw : $raw;
    }

    // ------------------------------------------------------------------
    // Restlaufzeit
    // ------------------------------------------------------------------

    /**
     * Schaetzt die Restlaufzeit aus drei Quellen: der typischen Dauer
     * vergangener Laeufe, dem Energiefortschritt und - am Ende - der
     * erkannten Schleuderphase.
     */
    private function UpdateRemaining(float $power, float $elapsed) {
        $typical = $this->TypicalMinutes();
        $this->SetValue("TypicalMinutes", round($typical, 0));

        if ($typical <= 0) {
            $this->SetValue("RemainingMinutes", 0);
            return;
        }

        $remaining = $typical - $elapsed;

        // Energiefortschritt ist aussagekraeftiger als reine Zeit, weil er die
        // Heizphase mitbewertet. Erst ab etwas Fortschritt sinnvoll.
        $typicalKwh = $this->TypicalKwh();
        if ($typicalKwh > 0) {
            $kwh = $this->ReadAttributeFloat("EnergyWs") / 3600000.0;
            $progress = $kwh / $typicalKwh;
            if ($progress > 0.05 && $progress < 1.0) {
                $byEnergy = $typical * (1.0 - $progress);
                $remaining = ($remaining + $byEnergy) / 2.0;
            }
        }

        // Schleudern: kurze, hohe Last in der zweiten Programmhaelfte. Die
        // Zeitbedingung trennt es von der Heizphase am Anfang, die noch mehr
        // zieht.
        $spinWatt = $this->ReadPropertyFloat("SpinWatt");
        if ($spinWatt > 0) {
            if ($power >= $spinWatt && $elapsed > ($typical * 0.5) && $this->ReadAttributeFloat("SpinAt") <= 0) {
                $this->WriteAttributeFloat("SpinAt", microtime(true));
                $this->SendDebug("Prognose", sprintf("Schleudern erkannt bei %.0f W nach %.0f min", $power, $elapsed), 0);
            }

            $spinAt = $this->ReadAttributeFloat("SpinAt");
            if ($spinAt > 0) {
                $spinTotal = (float)$this->ReadPropertyInteger("SpinRemainingMinutes");
                $sinceSpin = (microtime(true) - $spinAt) / 60.0;

                if ($sinceSpin > $spinTotal) {
                    // Laeuft laenger als nach dem Schleudern zu erwarten - es war
                    // ein Zwischenschleudern. Annahme verwerfen, statt bis zum
                    // Programmende eine falsche Restzeit anzuzeigen.
                    $this->WriteAttributeFloat("SpinAt", 0.0);
                    $this->SendDebug("Prognose", "Geraet laeuft weiter - war Zwischenschleudern, Restzeitannahme verworfen", 0);
                } else {
                    $rest = $spinTotal - $sinceSpin;
                    if ($remaining > $rest) $remaining = $rest;
                }
            }
        }

        if ($remaining < 0) $remaining = 0;
        $this->SetValue("RemainingMinutes", round($remaining, 0));
    }

    private function RecordRun(float $minutes, float $kwh, float $peak) {
        $history = json_decode($this->ReadAttributeString("History"), true);
        if (!is_array($history)) $history = [];

        $history[] = ['m' => round($minutes, 1), 'k' => round($kwh, 3), 'p' => round($peak, 0)];

        $keep = $this->ReadPropertyInteger("LearnRuns");
        if ($keep < 1) $keep = 1;
        while (count($history) > $keep) array_shift($history);

        $this->WriteAttributeString("History", json_encode($history));
        $this->SendDebug("Lernen", sprintf("Lauf gespeichert: %.0f min, %.2f kWh, Spitze %.0f W", $minutes, $kwh, $peak), 0);
    }

    private function TypicalMinutes(): float {
        $median = $this->Median('m');
        if ($median > 0) return $median;
        return (float)$this->ReadPropertyInteger("DefaultRunMinutes");
    }

    private function TypicalKwh(): float {
        return $this->Median('k');
    }

    private function Median(string $field): float {
        $history = json_decode($this->ReadAttributeString("History"), true);
        if (!is_array($history) || count($history) == 0) return 0.0;

        $values = [];
        foreach ($history as $entry) {
            if (isset($entry[$field]) && $entry[$field] > 0) $values[] = (float)$entry[$field];
        }
        if (count($values) == 0) return 0.0;

        sort($values);
        $middle = intdiv(count($values), 2);
        if (count($values) % 2 == 1) return $values[$middle];
        return ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    // ------------------------------------------------------------------
    // Anbindung an die ToDo-Zentrale
    // ------------------------------------------------------------------

    private function SetToDo(string $ident, string $text, array $options) {
        $instanceID = $this->ReadPropertyInteger("ToDoInstanceID");
        if ($ident === "" || $instanceID <= 0 || !IPS_InstanceExists($instanceID)) return;
        if (!function_exists("TODO_Set")) {
            $this->SendDebug("ToDo", "TODO_Set nicht verfuegbar - ist die ToDo-Zentrale installiert?", 0);
            return;
        }
        $this->SendDebug("ToDo", "Setze '$ident': $text", 0);
        @TODO_Set($instanceID, $ident, $text, json_encode($options));
    }

    private function ClearToDo(string $ident) {
        $instanceID = $this->ReadPropertyInteger("ToDoInstanceID");
        if ($ident === "" || $instanceID <= 0 || !IPS_InstanceExists($instanceID)) return;
        if (!function_exists("TODO_Clear")) return;
        @TODO_Clear($instanceID, $ident);
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    /**
     * Setzt die gelernten Laufzeiten zurueck.
     */
    public function ResetLearning() {
        $this->WriteAttributeString("History", "[]");
        echo "Gelernte Laufzeiten wurden zurueckgesetzt.\n";
    }

    /**
     * Zeigt die aufgezeichneten Laeufe.
     */
    public function DumpHistory() {
        $history = json_decode($this->ReadAttributeString("History"), true);
        if (!is_array($history) || count($history) == 0) {
            echo "Noch keine Laeufe aufgezeichnet. Es gilt der Vorgabewert von "
                . $this->ReadPropertyInteger("DefaultRunMinutes") . " Minuten.\n";
            return;
        }
        echo "Aufgezeichnete Laeufe:\n";
        foreach ($history as $entry) {
            echo sprintf("  %6.1f min   %6.3f kWh   Spitze %5.0f W\n", $entry['m'], $entry['k'], $entry['p']);
        }
        echo sprintf("\nTypische Dauer (Median): %.0f min\n", $this->TypicalMinutes());
        echo sprintf("Typische Energie (Median): %.2f kWh\n", $this->TypicalKwh());
    }

    private function FormatMinutes(float $minutes): string {
        if ($minutes < 1) return "weniger als eine Minute";
        if ($minutes < 60) return round($minutes) . " min";
        $hours = floor($minutes / 60);
        $rest = round($minutes - ($hours * 60));
        return $rest > 0 ? "$hours h $rest min" : "$hours h";
    }

    private function CreateProfiles() {
        if (!IPS_VariableProfileExists("GLF.State")) {
            IPS_CreateVariableProfile("GLF.State", VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon("GLF.State", "Information");
            IPS_SetVariableProfileAssociation("GLF.State", self::STATE_BEREIT, "Bereit", "Ok", 0x888888);
            IPS_SetVariableProfileAssociation("GLF.State", self::STATE_LAEUFT, "Laeuft", "Pants", 0xFFFF00);
            IPS_SetVariableProfileAssociation("GLF.State", self::STATE_FERTIG, "Fertig", "Alert", 0xFF0000);
        }

        if (!IPS_VariableProfileExists("GLF.Minuten")) {
            IPS_CreateVariableProfile("GLF.Minuten", VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText("GLF.Minuten", "", " min");
            IPS_SetVariableProfileDigits("GLF.Minuten", 0);
            IPS_SetVariableProfileValues("GLF.Minuten", 0, 1440, 1);
            IPS_SetVariableProfileIcon("GLF.Minuten", "Clock");
        }
    }
}
?>
