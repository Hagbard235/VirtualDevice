<?php

/**
 * Kuehlschrank-PV-Optimierung
 *
 * Nutzt die Kaeltekapazitaet von Eis- und Kuehlfach als thermischen Speicher:
 * Gekuehlt wird bevorzugt dann, wenn die PV-Anlage Ueberschuss liefert. In der
 * Zeit ohne Ueberschuss bleibt der Kuehlschrank stromlos und zehrt vom Puffer.
 * Erst wenn eine Grenztemperatur erreicht wird, gibt es kurzzeitig Netzstrom.
 *
 * Der Kuehlschrank regelt seine Temperatur weiterhin selbst - dieses Modul
 * schaltet ausschliesslich die Stromzufuhr. Der interne Thermostat des Geraets
 * sollte deshalb auf die "Tiefsttemperatur" eingestellt sein.
 */
class KuehlschrankPV extends IPSModule {

    // Betriebsmodus (Variable "Mode")
    const MODE_AUTO   = 0; // PV-Optimierung aktiv
    const MODE_NORMAL = 1; // Dauerhaft Strom, keine Optimierung
    const MODE_OFF    = 2; // Dauerhaft aus (nur fuer Wartung/Urlaub)

    // Aktueller Zustand der Automatik (Variable "State")
    const STATE_NORMAL     = 0;
    const STATE_PV_COOL    = 1;
    const STATE_PRECOOL    = 2;
    const STATE_BUFFER     = 3;
    const STATE_GRID_COOL  = 4;
    const STATE_LOCKED     = 5;
    const STATE_MANUAL_OFF = 6;
    const STATE_FAULT      = 7;

    // Ab dieser Leistungsaufnahme gilt der Verdichter als laufend.
    const COMPRESSOR_ON_WATT = 15.0;

    // Plausibilitaetsgrenzen fuer die Temperatursensoren. Werte ausserhalb
    // bedeuten Sensordefekt -> Notbetrieb.
    const TEMP_PLAUSIBLE_MIN = -45.0;
    const TEMP_PLAUSIBLE_MAX = 30.0;

    // Grenzen fuer gelernte Erwaermungsraten (Grad/h). Ausreisser stammen aus
    // Tueroeffnungen, Abtauvorgaengen oder Sensorfehlern und werden verworfen.
    const DRIFT_MIN = 0.05;
    const DRIFT_MAX = 10.0;

    // Eine Aus-Phase muss mindestens so lange laufen, bevor daraus eine
    // Erwaermungsrate abgeleitet wird.
    const DRIFT_MIN_MINUTES = 20;

    // Nach dieser Zeit gilt eine laufende Aus-Phase als lang genug, um schon
    // waehrenddessen einmalig zu lernen.
    const DRIFT_LEARN_AFTER_MINUTES = 60;

    // Zeitfenster, in dem nach einem Schaltbefehl auf die Rueckmeldung der
    // Steckdose gewartet wird.
    const SWITCH_TIMEOUT = 15.0;

    public function Create() {
        parent::Create();

        // --- Hardware ---
        $this->RegisterPropertyInteger("PowerSwitchID", 0);
        $this->RegisterPropertyBoolean("InvertSwitch", false);
        $this->RegisterPropertyInteger("ConsumptionID", 0);
        $this->RegisterPropertyInteger("MeterID", 0);
        $this->RegisterPropertyBoolean("MeterFeedInNegative", true);
        $this->RegisterPropertyBoolean("MeterIncludesFridge", true);

        // --- Eisfach ---
        $this->RegisterPropertyBoolean("FreezerEnabled", true);
        $this->RegisterPropertyInteger("TempFreezerID", 0);
        $this->RegisterPropertyFloat("FreezerTempMin", -20.0);
        $this->RegisterPropertyFloat("FreezerTempTarget", -15.0);
        $this->RegisterPropertyFloat("FreezerTempMax", -10.0);

        // --- Kuehlfach ---
        $this->RegisterPropertyBoolean("FridgeEnabled", true);
        $this->RegisterPropertyInteger("TempFridgeID", 0);
        $this->RegisterPropertyFloat("FridgeTempMin", 3.0);
        $this->RegisterPropertyFloat("FridgeTempTarget", 5.0);
        $this->RegisterPropertyFloat("FridgeTempMax", 8.0);

        // --- PV-Ueberschuss ---
        $this->RegisterPropertyInteger("SurplusThreshold", 50);
        $this->RegisterPropertyInteger("SurplusHysteresis", 100);
        $this->RegisterPropertyInteger("SurplusStableSeconds", 180);
        $this->RegisterPropertyInteger("FridgePowerEstimate", 80);

        // --- Prognose ---
        $this->RegisterPropertyInteger("PVForecastStartID", 0);
        $this->RegisterPropertyString("DefaultPVStart", "09:00");
        $this->RegisterPropertyString("DefaultPVEnd", "17:00");
        $this->RegisterPropertyInteger("LearnDays", 7);
        $this->RegisterPropertyInteger("PreCoolMinutes", 90);
        $this->RegisterPropertyInteger("PreCoolMaxGridW", 0);

        // --- Schutz und Zeiten ---
        $this->RegisterPropertyInteger("MinOffMinutes", 10);
        $this->RegisterPropertyInteger("MinOnMinutes", 5);
        $this->RegisterPropertyInteger("MaxOffMinutes", 720);
        $this->RegisterPropertyInteger("SensorTimeoutMinutes", 180);
        $this->RegisterPropertyInteger("CheckInterval", 60);
        $this->RegisterPropertyFloat("DefaultDriftFreezer", 1.0);
        $this->RegisterPropertyFloat("DefaultDriftFridge", 1.5);

        // --- Attribute (interner Speicher) ---
        // Zeitpunkt der letzten echten Schalthandlung (Verdichterschutz).
        $this->RegisterAttributeFloat("LastSwitchTime", 0.0);

        // Entprellter Ueberschuss-Zustand und der Zeitpunkt, seit dem der
        // Rohwert dem entprellten Zustand widerspricht.
        $this->RegisterAttributeBoolean("SurplusState", false);
        $this->RegisterAttributeFloat("SurplusPendingSince", 0.0);

        // Laufende Netz-Nachkuehlung: einmal ausgeloest, wird bis zum Zielwert
        // durchgekuehlt, damit der Verdichter nicht im Sekundentakt taktet.
        $this->RegisterAttributeBoolean("GridCoolActive", false);
        $this->RegisterAttributeBoolean("GridCoolDeep", false);

        // Beginn der aktuellen Aus-Phase, zum Lernen der Erwaermungsrate.
        $this->RegisterAttributeFloat("CoastStart", 0.0);
        $this->RegisterAttributeFloat("CoastTempFreezer", 0.0);
        $this->RegisterAttributeFloat("CoastTempFridge", 0.0);
        $this->RegisterAttributeBoolean("CoastLearned", false);

        // Gelernte Werte.
        $this->RegisterAttributeFloat("DriftFreezer", 0.0);
        $this->RegisterAttributeFloat("DriftFridge", 0.0);
        $this->RegisterAttributeFloat("LearnedFridgePower", 0.0);

        // PV-Fenster: heutiger Mitschnitt und Historie der Vortage.
        $this->RegisterAttributeString("TodayDate", "");
        $this->RegisterAttributeInteger("TodayPVStart", -1);
        $this->RegisterAttributeInteger("TodayPVEnd", -1);
        $this->RegisterAttributeString("PVHistory", "[]");

        // Zaehler fuer ausgebliebene Rueckmeldungen der Steckdose.
        $this->RegisterAttributeInteger("SwitchFailures", 0);

        $this->CreateProfiles();

        // --- Statusvariablen ---
        $this->RegisterVariableInteger("Mode", "Modus", "PVKS.Mode", 10);
        $this->EnableAction("Mode");
        $this->RegisterVariableInteger("State", "Zustand", "PVKS.State", 20);
        $this->RegisterVariableString("Info", "Erlaeuterung", "", 30);
        $this->RegisterVariableBoolean("PowerState", "Strom", "~Switch", 40);
        $this->RegisterVariableFloat("Consumption", "Verbrauch", "~Watt", 50);
        $this->RegisterVariableFloat("TempFreezer", "Eisfach", "~Temperature", 60);
        $this->RegisterVariableFloat("TempFridge", "Kuehlfach", "~Temperature", 70);
        $this->RegisterVariableFloat("Surplus", "PV-Ueberschuss", "~Watt", 80);
        $this->RegisterVariableBoolean("SurplusOK", "PV-Ueberschuss reicht", "~Switch", 90);
        $this->RegisterVariableInteger("NextPVStart", "Naechster PV-Start", "~UnixTimestamp", 100);
        $this->RegisterVariableFloat("BufferHours", "Puffer-Restzeit", "PVKS.Hours", 110);

        // --- Timer ---
        $this->RegisterTimer("CycleTimer", 0, 'PVKS_Cycle($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Alte Registrierungen loesen, damit ein Variablenwechsel keine
        // Karteileichen hinterlaesst. Inzwischen geloeschte Variablen stehen
        // ggf. noch in der Liste, deshalb unterdrueckte Fehler.
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                @$this->UnregisterMessage($senderID, $message);
            }
        }

        foreach ($this->GetWatchedVariables() as $id) {
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Der Verdichterschutz braucht einen Startzeitpunkt. Nach einem
        // IPS-Neustart ist der Attributwert zwar erhalten, kann aber aus einer
        // laengst vergangenen Sitzung stammen - das ist unkritisch, weil eine
        // alte Zeit die Sperre nur fruehzeitig aufhebt.
        if ($this->ReadAttributeFloat("LastSwitchTime") <= 0) {
            $this->WriteAttributeFloat("LastSwitchTime", microtime(true));
        }

        $this->SetBuffer("PendingSwitch", "");

        $interval = $this->ReadPropertyInteger("CheckInterval");
        if ($interval < 10) $interval = 10;
        $this->SetTimerInterval("CycleTimer", $interval * 1000);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Cycle();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != VM_UPDATE) return;

        $switchID = $this->ReadPropertyInteger("PowerSwitchID");
        if ($SenderID == $switchID) {
            // Rueckmeldung der Steckdose ist die verbindliche Quelle.
            $this->SetValue("PowerState", $this->ReadPowerState());
            $this->ConfirmSwitch();
            return;
        }

        // Temperaturen: sofort reagieren, wenn eine Grenze gerissen wird -
        // darauf bis zum naechsten Timer-Tick zu warten waere zu langsam.
        $freezerID = $this->ReadPropertyInteger("TempFreezerID");
        $fridgeID  = $this->ReadPropertyInteger("TempFridgeID");
        if ($SenderID == $freezerID || $SenderID == $fridgeID) {
            $value = (float)$Data[0];
            $isFreezer = ($SenderID == $freezerID);
            $max = $isFreezer ? $this->ReadPropertyFloat("FreezerTempMax") : $this->ReadPropertyFloat("FridgeTempMax");
            $enabled = $isFreezer ? $this->ReadPropertyBoolean("FreezerEnabled") : $this->ReadPropertyBoolean("FridgeEnabled");
            $this->SetValue($isFreezer ? "TempFreezer" : "TempFridge", $value);

            if ($enabled && $value >= $max && !$this->GetValue("PowerState")) {
                $this->SendDebug("MessageSink", "Grenztemperatur erreicht ($value >= $max) -> sofortige Neubewertung", 0);
                $this->Cycle();
            }
        }
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case "Mode":
                $this->SetMode((int)$Value);
                break;
        }
    }

    /**
     * Betriebsmodus umschalten. Aufruf aus Scripten:
     * PVKS_SetMode(InstanceID, 0|1|2);
     */
    public function SetMode(int $Mode) {
        if ($Mode < self::MODE_AUTO || $Mode > self::MODE_OFF) return;
        $this->SendDebug("Mode", "Moduswechsel auf $Mode", 0);
        $this->SetValue("Mode", $Mode);

        // Ein Moduswechsel beendet eine laufende Netz-Nachkuehlung.
        $this->WriteAttributeBoolean("GridCoolActive", false);
        $this->Cycle();
    }

    /**
     * Gelernte Werte (Erwaermungsraten, Leistungsaufnahme, PV-Fenster) verwerfen.
     */
    public function ResetLearning() {
        $this->WriteAttributeFloat("DriftFreezer", 0.0);
        $this->WriteAttributeFloat("DriftFridge", 0.0);
        $this->WriteAttributeFloat("LearnedFridgePower", 0.0);
        $this->WriteAttributeString("PVHistory", "[]");
        $this->WriteAttributeString("TodayDate", "");
        $this->WriteAttributeInteger("TodayPVStart", -1);
        $this->WriteAttributeInteger("TodayPVEnd", -1);
        $this->SendDebug("Learning", "Lernwerte zurueckgesetzt", 0);
        echo "Lernwerte wurden zurueckgesetzt.\n";
    }

    /**
     * Aktuelle Lern- und Prognosewerte im Konfigurationsformular ausgeben.
     */
    public function DumpState() {
        $history = json_decode($this->ReadAttributeString("PVHistory"), true);
        if (!is_array($history)) $history = [];

        echo "Gelernte Erwaermung Eisfach:  " . $this->FormatDrift($this->ReadAttributeFloat("DriftFreezer"), $this->ReadPropertyFloat("DefaultDriftFreezer")) . "\n";
        echo "Gelernte Erwaermung Kuehlfach: " . $this->FormatDrift($this->ReadAttributeFloat("DriftFridge"), $this->ReadPropertyFloat("DefaultDriftFridge")) . "\n";
        $power = $this->ReadAttributeFloat("LearnedFridgePower");
        echo "Gelernte Leistungsaufnahme:   " . ($power > 0 ? round($power, 1) . " W" : "noch nicht gelernt, Schaetzwert " . $this->ReadPropertyInteger("FridgePowerEstimate") . " W") . "\n";
        echo "Prognose naechster PV-Start:  " . date("d.m.Y H:i", $this->PredictPVStart()) . "\n";
        echo "Prognose naechstes PV-Ende:   " . date("d.m.Y H:i", $this->PredictPVEnd()) . "\n\n";
        echo "Aufgezeichnete PV-Fenster:\n";
        if (count($history) == 0) {
            echo "  (noch keine - es wird der Standardwert aus der Konfiguration genutzt)\n";
        }
        foreach ($history as $day) {
            echo "  " . $day['d'] . ": " . $this->FormatSeconds($day['s']) . " - " . $this->FormatSeconds($day['e']) . "\n";
        }
    }

    /**
     * Hauptschleife. Wird zyklisch vom Timer und bei Grenzwertverletzungen
     * aufgerufen.
     */
    public function Cycle() {
        if (!$this->CheckConfiguration()) return;

        $meas = $this->ReadMeasurements();

        $this->SetValue("PowerState", $meas['powerOn']);
        $this->SetValue("Consumption", $meas['fridgeW']);
        $this->SetValue("Surplus", $meas['surplus']);
        if ($meas['freezerValid']) $this->SetValue("TempFreezer", $meas['freezer']);
        if ($meas['fridgeValid'])  $this->SetValue("TempFridge", $meas['fridge']);

        $this->ConfirmSwitch();

        // Lernen und Prognose laufen in jedem Modus mit, damit die Automatik
        // nach einer Handschaltung sofort brauchbare Werte hat.
        $this->LearnFridgePower($meas);
        $this->TrackPVWindow($meas);
        $this->SetValue("NextPVStart", $this->PredictPVStart());

        $mode = $this->GetValue("Mode");

        // Eine Sensorstoerung aus der Automatik darf nicht stehen bleiben, wenn
        // danach von Hand umgeschaltet wird - in den Handmodi wird sie unten
        // gar nicht mehr geprueft.
        if ($mode != self::MODE_AUTO && $this->GetStatus() == 202) {
            $this->SetStatus(102);
        }

        if ($mode == self::MODE_NORMAL) {
            if ($this->ApplyPower(true, true)) {
                $this->SetState(self::STATE_NORMAL, "Normalbetrieb: Kuehlschrank haengt dauerhaft am Netz.");
            } else {
                $this->SetState(self::STATE_LOCKED, sprintf(
                    "Normalbetrieb gewaehlt, Verdichterschutz laesst das Einschalten erst in %s zu.",
                    $this->FormatHours($this->GetLockRemaining(true) / 3600.0)
                ));
            }
            return;
        }

        if ($mode == self::MODE_OFF) {
            $this->ApplyPower(false, true);
            $this->SetState(self::STATE_MANUAL_OFF, "Handabschaltung: Kuehlschrank ist stromlos, die Temperaturen werden nicht ueberwacht.");
            return;
        }

        // --- Automatik ---
        if (!$meas['sensorsOK']) {
            $this->ApplyPower(true, true);
            $this->SetState(self::STATE_FAULT, "Notbetrieb: " . $meas['sensorError'] . " Kuehlschrank bleibt am Netz.");
            $this->SetStatus(202);
            return;
        }
        if ($this->GetStatus() == 202) $this->SetStatus(102);

        $this->LearnDrift($meas);
        $this->UpdateBufferEstimate($meas);
        $this->Decide($meas);
    }

    // ------------------------------------------------------------------
    // Entscheidungslogik
    // ------------------------------------------------------------------

    private function Decide(array $meas) {
        $need      = $this->GetFridgeDemand();
        $threshold = $this->ReadPropertyInteger("SurplusThreshold");
        $hyst      = $this->ReadPropertyInteger("SurplusHysteresis");
        $available = $meas['available'];

        // Hysterese: laeuft der Kuehlschrank bereits, darf der Ueberschuss
        // etwas einbrechen, ohne dass sofort abgeschaltet wird.
        $limit = $need + $threshold - ($meas['powerOn'] ? $hyst : 0);
        $surplusOK = $this->StabilizeSurplus($available >= $limit);
        $this->SetValue("SurplusOK", $surplusOK);

        $tooWarm    = $this->AnyAtOrAbove($meas, 'max');
        $bufferFull = $this->AllAtOrBelow($meas, 'min');

        $now = time();
        $pvEnd = $this->PredictPVEnd();
        $preCoolWindow = ($pvEnd - $now) <= ($this->ReadPropertyInteger("PreCoolMinutes") * 60);
        $preCoolPower  = $available >= ($need - $this->ReadPropertyInteger("PreCoolMaxGridW"));

        // Sicherheitsnetz gegen einen Sensor, der plausible aber eingefrorene
        // Werte liefert: dann bliebe der Kuehlschrank sonst unbegrenzt aus.
        $maxOff = $this->ReadPropertyInteger("MaxOffMinutes");
        $offSeconds = $meas['powerOn'] ? 0 : (microtime(true) - $this->ReadAttributeFloat("LastSwitchTime"));
        $maxOffExceeded = ($maxOff > 0) && ($offSeconds > ($maxOff * 60));

        if ($surplusOK) {
            // Solange Ueberschuss da ist, bekommt der Kuehlschrank Strom. Wann
            // der Verdichter laeuft, entscheidet weiterhin sein eigener Thermostat.
            $this->WriteAttributeBoolean("GridCoolActive", false);
            $this->Command(true, self::STATE_PV_COOL, sprintf(
                "PV-Kuehlung: %d W Ueberschuss verfuegbar (benoetigt %d W).%s",
                round($available), round($need + $threshold),
                $bufferFull ? " Puffer ist geladen." : " Puffer wird geladen."
            ));
            return;
        }

        if ($preCoolWindow && !$bufferFull && $preCoolPower) {
            // Kurz vor PV-Ende zaehlt jedes Grad Puffer mehr als der
            // Ueberschuss-Aufschlag - deshalb hier die niedrigere Huerde.
            $this->WriteAttributeBoolean("GridCoolActive", false);
            $this->Command(true, self::STATE_PRECOOL, sprintf(
                "Vorkuehlen vor PV-Ende (%s): Puffer wird noch aufgeladen.",
                date("H:i", $pvEnd)
            ));
            return;
        }

        if ($this->ReadAttributeBoolean("GridCoolActive")) {
            $deep = $this->ReadAttributeBoolean("GridCoolDeep");
            if ($this->AllAtOrBelow($meas, $deep ? 'min' : 'target')) {
                $this->WriteAttributeBoolean("GridCoolActive", false);
                $this->Command(false, self::STATE_BUFFER, "Netz-Nachkuehlung beendet, Puffer wird wieder genutzt.");
            } else {
                $this->Command(true, self::STATE_GRID_COOL, sprintf(
                    "Netz-Nachkuehlung laeuft bis %s.",
                    $deep ? "Tiefsttemperatur" : "Solltemperatur"
                ));
            }
            return;
        }

        if ($tooWarm || $maxOffExceeded) {
            $deep = $this->ShouldCoolDeep($meas);
            $this->WriteAttributeBoolean("GridCoolActive", true);
            $this->WriteAttributeBoolean("GridCoolDeep", $deep);
            $reason = $tooWarm
                ? "Grenztemperatur erreicht"
                : "Sicherheitsabschaltung: maximale Aus-Zeit von " . $this->ReadPropertyInteger("MaxOffMinutes") . " min ueberschritten";
            $this->Command(true, self::STATE_GRID_COOL, sprintf(
                "%s - Netz-Nachkuehlung bis %s.",
                $reason, $deep ? "Tiefsttemperatur" : "Solltemperatur"
            ));
            return;
        }

        $this->Command(false, self::STATE_BUFFER, sprintf(
            "Kein PV-Ueberschuss (%d W von %d W). Puffer reicht noch ca. %s, PV zurueck gegen %s.",
            round($available), round($need + $threshold),
            $this->FormatHours($this->GetValue("BufferHours")),
            date("H:i", $this->PredictPVStart())
        ));
    }

    /**
     * Entscheidet, ob eine Netz-Nachkuehlung bis zur Tiefsttemperatur laufen
     * soll. Das lohnt sich, wenn die PV erst spaeter zurueckkommt - sonst
     * muesste der Verdichter in derselben Nacht mehrfach anlaufen.
     */
    private function ShouldCoolDeep(array $meas): bool {
        $hoursToPV = ($this->PredictPVStart() - time()) / 3600.0;
        $runtime = $this->EstimateHours($meas, 'target', 'max');
        if ($runtime <= 0) return true;
        return $hoursToPV > (2 * $runtime);
    }

    /**
     * Setzt den Schaltwunsch um und schreibt Zustand und Erlaeuterung.
     * Greift der Verdichterschutz, bleibt der bisherige Zustand bestehen und
     * wird als Sperrzeit ausgewiesen.
     */
    private function Command(bool $wantPower, int $state, string $info) {
        if ($this->ApplyPower($wantPower, false)) {
            $this->SetState($state, $info);
            return;
        }

        $wait = $this->GetLockRemaining($wantPower);
        $this->SetState(self::STATE_LOCKED, sprintf(
            "Verdichterschutz: %s in %s moeglich. Geplant: %s",
            $wantPower ? "Einschalten" : "Abschalten",
            $this->FormatHours($wait / 3600.0), $info
        ));
    }

    // ------------------------------------------------------------------
    // Messwerte
    // ------------------------------------------------------------------

    private function ReadMeasurements(): array {
        $meas = [
            'powerOn'      => $this->ReadPowerState(),
            'fridgeW'      => 0.0,
            'surplus'      => 0.0,
            'available'    => 0.0,
            'freezer'      => 0.0,
            'fridge'       => 0.0,
            'freezerValid' => false,
            'fridgeValid'  => false,
            'sensorsOK'    => true,
            'sensorError'  => ""
        ];

        $consumptionID = $this->ReadPropertyInteger("ConsumptionID");
        if ($consumptionID > 0 && IPS_VariableExists($consumptionID)) {
            $meas['fridgeW'] = (float)GetValue($consumptionID);
        }

        // Vorzeichen normalisieren: intern ist "surplus" positiv, wenn
        // eingespeist wird.
        $meterID = $this->ReadPropertyInteger("MeterID");
        if ($meterID > 0 && IPS_VariableExists($meterID)) {
            $raw = (float)GetValue($meterID);
            if (!$this->ReadPropertyBoolean("MeterFeedInNegative")) $raw = -$raw;
            $meas['surplus'] = -$raw;
        }

        // Der Zaehler misst den Kuehlschrank mit. Fuer die Frage "reicht die
        // Sonne fuer den Kuehlschrank" muss sein eigener Verbrauch deshalb
        // herausgerechnet werden - aber nur, wenn der Verdichter laeuft.
        $meas['available'] = $meas['surplus'];
        if ($this->ReadPropertyBoolean("MeterIncludesFridge")
            && $meas['powerOn']
            && $meas['fridgeW'] >= self::COMPRESSOR_ON_WATT) {
            $meas['available'] += $meas['fridgeW'];
        }

        $errors = [];
        $this->ReadTemperature("Freezer", $meas, $errors);
        $this->ReadTemperature("Fridge", $meas, $errors);
        if (count($errors) > 0) {
            $meas['sensorsOK'] = false;
            $meas['sensorError'] = implode(" ", $errors);
        }

        return $meas;
    }

    private function ReadTemperature(string $which, array &$meas, array &$errors) {
        $isFreezer = ($which === "Freezer");
        $key   = $isFreezer ? 'freezer' : 'fridge';
        $label = $isFreezer ? "Eisfach" : "Kuehlfach";

        if (!$this->ReadPropertyBoolean($which . "Enabled")) return;

        $id = $this->ReadPropertyInteger("Temp" . $which . "ID");
        if ($id <= 0 || !IPS_VariableExists($id)) {
            $errors[] = "Temperatursensor $label fehlt.";
            return;
        }

        // Ausfallerkennung nur waehrend der Aus-Phasen. Fuehler melden meist nur
        // bei relevanter Aenderung, und solange der Kuehlschrank Strom hat,
        // haelt der Thermostat die Temperatur - stundenlanges Schweigen ist dann
        // normal und ein ausgefallener Fuehler ohnehin ungefaehrlich, weil das
        // Geraet selbst regelt. Erst ohne Strom steigt die Temperatur zwangs-
        // laeufig, und dann muss sich ein funktionierender Sensor melden.
        $timeout = $this->ReadPropertyInteger("SensorTimeoutMinutes");
        if ($timeout > 0 && !$meas['powerOn']) {
            $variable = IPS_GetVariable($id);
            $lastUpdate = $variable['VariableUpdated'];

            // Gemessen wird ab Beginn der Aus-Phase: vorher darf der Fuehler
            // beliebig lange geschwiegen haben.
            $coastStart = $this->ReadAttributeFloat("CoastStart");
            $reference = ($coastStart > $lastUpdate) ? $coastStart : $lastUpdate;

            $silentMinutes = (time() - $reference) / 60.0;
            if ($silentMinutes > $timeout) {
                $errors[] = sprintf("Sensor %s meldet seit %d min nichts mehr, obwohl der Kuehlschrank stromlos ist.", $label, round($silentMinutes));
                return;
            }
        }

        $value = (float)GetValue($id);
        if ($value < self::TEMP_PLAUSIBLE_MIN || $value > self::TEMP_PLAUSIBLE_MAX) {
            $errors[] = sprintf("Sensor %s liefert unplausible %.1f Grad.", $label, $value);
            return;
        }

        $meas[$key] = $value;
        $meas[$key . 'Valid'] = true;
    }

    private function ReadPowerState(): bool {
        $id = $this->ReadPropertyInteger("PowerSwitchID");
        if ($id <= 0 || !IPS_VariableExists($id)) return true;
        $raw = GetValueBoolean($id);
        return $this->ReadPropertyBoolean("InvertSwitch") ? !$raw : $raw;
    }

    // ------------------------------------------------------------------
    // Schalten
    // ------------------------------------------------------------------

    /**
     * Schaltet die Stromzufuhr, sofern der Verdichterschutz es zulaesst.
     * $force ueberspringt nur die Mindestlaufzeit (relevant bei Handbedienung),
     * niemals die Mindestpause vor einem Anlauf.
     *
     * @return bool true, wenn der gewuenschte Zustand erreicht ist oder bereits anliegt.
     */
    private function ApplyPower(bool $on, bool $force): bool {
        $id = $this->ReadPropertyInteger("PowerSwitchID");
        if ($id <= 0 || !IPS_VariableExists($id)) return false;

        if ($this->ReadPowerState() === $on) return true;

        $remaining = $this->GetLockRemaining($on);
        if ($remaining > 0 && !($force && !$on)) {
            $this->SendDebug("Power", sprintf("Schaltwunsch %s blockiert, noch %d s Sperrzeit", $on ? "EIN" : "AUS", round($remaining)), 0);
            return false;
        }

        $raw = $this->ReadPropertyBoolean("InvertSwitch") ? !$on : $on;
        $this->SendDebug("Power", "Schalte Kuehlschrank " . ($on ? "EIN" : "AUS") . " (Variable $id auf " . ($raw ? "TRUE" : "FALSE") . ")", 0);

        // Aus-Phase protokollieren, damit daraus die Erwaermungsrate gelernt
        // werden kann.
        if (!$on) {
            $this->WriteAttributeFloat("CoastStart", microtime(true));
            $this->WriteAttributeFloat("CoastTempFreezer", $this->GetValue("TempFreezer"));
            $this->WriteAttributeFloat("CoastTempFridge", $this->GetValue("TempFridge"));
            $this->WriteAttributeBoolean("CoastLearned", false);
        } else {
            $this->WriteAttributeFloat("CoastStart", 0.0);
        }

        $this->WriteAttributeFloat("LastSwitchTime", microtime(true));
        $this->SetBuffer("PendingSwitch", json_encode(['state' => $on, 'ts' => microtime(true)]));
        @RequestAction($id, $raw);

        // Vorlaeufig den Wunschzustand anzeigen; die Rueckmeldung des Aktors
        // korrigiert das im MessageSink beziehungsweise in ConfirmSwitch.
        $this->SetValue("PowerState", $on);
        return true;
    }

    /**
     * Restliche Sperrzeit des Verdichterschutzes in Sekunden.
     */
    private function GetLockRemaining(bool $wantOn): float {
        $minutes = $wantOn ? $this->ReadPropertyInteger("MinOffMinutes") : $this->ReadPropertyInteger("MinOnMinutes");
        $elapsed = microtime(true) - $this->ReadAttributeFloat("LastSwitchTime");
        $remaining = ($minutes * 60) - $elapsed;
        return $remaining > 0 ? $remaining : 0.0;
    }

    /**
     * Prueft, ob ein abgesetzter Schaltbefehl tatsaechlich angekommen ist.
     * Eine Steckdose, die nicht schaltet, waere sonst nicht zu bemerken - der
     * Kuehlschrank bliebe unbemerkt stromlos.
     */
    private function ConfirmSwitch() {
        $pending = json_decode($this->GetBuffer("PendingSwitch"), true);
        if (!is_array($pending)) return;

        $actual = $this->ReadPowerState();
        if ($actual === $pending['state']) {
            $this->SetBuffer("PendingSwitch", "");
            $this->WriteAttributeInteger("SwitchFailures", 0);
            if ($this->GetStatus() == 203) $this->SetStatus(102);
            return;
        }

        if ((microtime(true) - $pending['ts']) < self::SWITCH_TIMEOUT) return;

        $this->SetBuffer("PendingSwitch", "");
        $failures = $this->ReadAttributeInteger("SwitchFailures") + 1;
        $this->WriteAttributeInteger("SwitchFailures", $failures);
        $this->SendDebug("Power", sprintf("WARNUNG: Steckdose hat den Schaltbefehl (%s) nicht uebernommen (%d. Mal)", $pending['state'] ? "EIN" : "AUS", $failures), 0);
        $this->SetValue("PowerState", $actual);

        if ($failures >= 3) $this->SetStatus(203);
    }

    // ------------------------------------------------------------------
    // Temperaturauswertung
    // ------------------------------------------------------------------

    /**
     * true, wenn mindestens ein aktives Fach die genannte Schwelle erreicht hat.
     */
    private function AnyAtOrAbove(array $meas, string $level): bool {
        foreach ($this->GetActiveCompartments($meas) as $c) {
            if ($meas[$c['key']] >= $this->GetLimit($c['prefix'], $level)) return true;
        }
        return false;
    }

    /**
     * true, wenn alle aktiven Faecher die genannte Schwelle unterschritten haben.
     */
    private function AllAtOrBelow(array $meas, string $level): bool {
        $compartments = $this->GetActiveCompartments($meas);
        if (count($compartments) == 0) return false;
        foreach ($compartments as $c) {
            if ($meas[$c['key']] > $this->GetLimit($c['prefix'], $level)) return false;
        }
        return true;
    }

    private function GetLimit(string $prefix, string $level): float {
        switch ($level) {
            case 'min':    return $this->ReadPropertyFloat($prefix . "TempMin");
            case 'target': return $this->ReadPropertyFloat($prefix . "TempTarget");
            default:       return $this->ReadPropertyFloat($prefix . "TempMax");
        }
    }

    private function GetActiveCompartments(array $meas): array {
        $result = [];
        if ($this->ReadPropertyBoolean("FreezerEnabled") && $meas['freezerValid']) {
            $result[] = ['key' => 'freezer', 'prefix' => 'Freezer', 'drift' => 'DriftFreezer', 'default' => 'DefaultDriftFreezer'];
        }
        if ($this->ReadPropertyBoolean("FridgeEnabled") && $meas['fridgeValid']) {
            $result[] = ['key' => 'fridge', 'prefix' => 'Fridge', 'drift' => 'DriftFridge', 'default' => 'DefaultDriftFridge'];
        }
        return $result;
    }

    /**
     * Geschaetzte Stunden, bis das erste aktive Fach von $fromLevel auf
     * $toLevel erwaermt ist. $fromLevel = "" bedeutet: ab Istwert.
     */
    private function EstimateHours(array $meas, string $fromLevel, string $toLevel): float {
        $result = -1.0;
        foreach ($this->GetActiveCompartments($meas) as $c) {
            $from = ($fromLevel === "") ? $meas[$c['key']] : $this->GetLimit($c['prefix'], $fromLevel);
            $span = $this->GetLimit($c['prefix'], $toLevel) - $from;
            if ($span <= 0) return 0.0;

            $drift = $this->ReadAttributeFloat($c['drift']);
            if ($drift < self::DRIFT_MIN) $drift = $this->ReadPropertyFloat($c['default']);
            if ($drift < self::DRIFT_MIN) continue;

            $hours = $span / $drift;
            if ($result < 0 || $hours < $result) $result = $hours;
        }
        return $result < 0 ? 0.0 : $result;
    }

    private function UpdateBufferEstimate(array $meas) {
        $this->SetValue("BufferHours", round($this->EstimateHours($meas, "", 'max'), 2));
    }

    // ------------------------------------------------------------------
    // Lernen
    // ------------------------------------------------------------------

    /**
     * Erwaermungsrate waehrend der Aus-Phasen lernen. Pro Aus-Phase wird
     * hoechstens einmal gelernt, damit lange Phasen den Mittelwert nicht
     * dominieren.
     */
    private function LearnDrift(array $meas) {
        if ($meas['powerOn']) return;
        if ($this->ReadAttributeBoolean("CoastLearned")) return;

        $start = $this->ReadAttributeFloat("CoastStart");
        if ($start <= 0) return;

        $hours = (microtime(true) - $start) / 3600.0;
        if ($hours < (self::DRIFT_LEARN_AFTER_MINUTES / 60.0)) return;

        foreach ($this->GetActiveCompartments($meas) as $c) {
            $startTemp = $this->ReadAttributeFloat($c['key'] === 'freezer' ? "CoastTempFreezer" : "CoastTempFridge");
            $rate = ($meas[$c['key']] - $startTemp) / $hours;
            if ($rate < self::DRIFT_MIN || $rate > self::DRIFT_MAX) {
                $this->SendDebug("Learn", sprintf("Erwaermungsrate %s verworfen: %.2f Grad/h", $c['prefix'], $rate), 0);
                continue;
            }
            $old = $this->ReadAttributeFloat($c['drift']);
            $new = ($old < self::DRIFT_MIN) ? $rate : (0.7 * $old + 0.3 * $rate);
            $this->WriteAttributeFloat($c['drift'], $new);
            $this->SendDebug("Learn", sprintf("Erwaermung %s: gemessen %.2f, neuer Mittelwert %.2f Grad/h", $c['prefix'], $rate, $new), 0);
        }

        $this->WriteAttributeBoolean("CoastLearned", true);
    }

    /**
     * Typische Leistungsaufnahme des laufenden Verdichters lernen.
     */
    private function LearnFridgePower(array $meas) {
        if ($meas['fridgeW'] < self::COMPRESSOR_ON_WATT) return;
        $old = $this->ReadAttributeFloat("LearnedFridgePower");
        $new = ($old <= 0) ? $meas['fridgeW'] : (0.9 * $old + 0.1 * $meas['fridgeW']);
        $this->WriteAttributeFloat("LearnedFridgePower", $new);
    }

    private function GetFridgeDemand(): float {
        $learned = $this->ReadAttributeFloat("LearnedFridgePower");
        if ($learned >= self::COMPRESSOR_ON_WATT) return $learned;
        return (float)$this->ReadPropertyInteger("FridgePowerEstimate");
    }

    /**
     * Entprellt den Ueberschuss-Zustand. Einzelne Wolken duerfen den
     * Kuehlschrank nicht takten lassen.
     */
    private function StabilizeSurplus(bool $raw): bool {
        $current = $this->ReadAttributeBoolean("SurplusState");
        if ($raw === $current) {
            $this->WriteAttributeFloat("SurplusPendingSince", 0.0);
            return $current;
        }

        $now = microtime(true);
        $since = $this->ReadAttributeFloat("SurplusPendingSince");
        if ($since <= 0) {
            $this->WriteAttributeFloat("SurplusPendingSince", $now);
            return $current;
        }

        if (($now - $since) >= $this->ReadPropertyInteger("SurplusStableSeconds")) {
            $this->WriteAttributeBoolean("SurplusState", $raw);
            $this->WriteAttributeFloat("SurplusPendingSince", 0.0);
            $this->SendDebug("Surplus", "Ueberschuss-Zustand wechselt auf " . ($raw ? "verfuegbar" : "nicht verfuegbar"), 0);
            return $raw;
        }

        return $current;
    }

    // ------------------------------------------------------------------
    // PV-Prognose
    // ------------------------------------------------------------------

    /**
     * Schreibt mit, in welchem Zeitfenster heute genug Ueberschuss fuer den
     * Kuehlschrank vorhanden war. Aus diesen Fenstern entsteht die Prognose.
     */
    private function TrackPVWindow(array $meas) {
        $today = date("Y-m-d");
        if ($this->ReadAttributeString("TodayDate") !== $today) {
            $this->FlushPVDay();
            $this->WriteAttributeString("TodayDate", $today);
            $this->WriteAttributeInteger("TodayPVStart", -1);
            $this->WriteAttributeInteger("TodayPVEnd", -1);
        }

        $limit = $this->GetFridgeDemand() + $this->ReadPropertyInteger("SurplusThreshold");
        if ($meas['available'] < $limit) return;

        $secondsOfDay = time() - strtotime("today");
        if ($this->ReadAttributeInteger("TodayPVStart") < 0) {
            $this->WriteAttributeInteger("TodayPVStart", $secondsOfDay);
            $this->SendDebug("Prognose", "PV-Fenster heute begonnen um " . $this->FormatSeconds($secondsOfDay), 0);
        }
        $this->WriteAttributeInteger("TodayPVEnd", $secondsOfDay);
    }

    private function FlushPVDay() {
        $date  = $this->ReadAttributeString("TodayDate");
        $start = $this->ReadAttributeInteger("TodayPVStart");
        $end   = $this->ReadAttributeInteger("TodayPVEnd");
        if ($date === "" || $start < 0 || $end < 0) return;

        $history = json_decode($this->ReadAttributeString("PVHistory"), true);
        if (!is_array($history)) $history = [];
        $history[] = ['d' => $date, 's' => $start, 'e' => $end];

        $keep = $this->ReadPropertyInteger("LearnDays");
        if ($keep < 1) $keep = 1;
        while (count($history) > $keep) array_shift($history);

        $this->WriteAttributeString("PVHistory", json_encode($history));
        $this->SendDebug("Prognose", "PV-Fenster $date gespeichert: " . $this->FormatSeconds($start) . " - " . $this->FormatSeconds($end), 0);
    }

    /**
     * Naechster erwarteter PV-Start als Unix-Zeitstempel.
     */
    private function PredictPVStart(): int {
        $forecastID = $this->ReadPropertyInteger("PVForecastStartID");
        if ($forecastID > 0 && IPS_VariableExists($forecastID)) {
            $value = (int)GetValue($forecastID);
            // Nur plausible, in der Zukunft liegende Zeitstempel uebernehmen.
            if ($value > time() && $value < (time() + 7 * 86400)) return $value;
        }

        return $this->NextOccurrence($this->GetMedian('s'), $this->ReadPropertyString("DefaultPVStart"));
    }

    /**
     * Naechstes erwartetes PV-Ende als Unix-Zeitstempel.
     */
    private function PredictPVEnd(): int {
        return $this->NextOccurrence($this->GetMedian('e'), $this->ReadPropertyString("DefaultPVEnd"));
    }

    /**
     * Rechnet eine Tageszeit (Sekunden seit Mitternacht) in den naechsten in
     * der Zukunft liegenden Zeitpunkt um.
     */
    private function NextOccurrence(int $secondsOfDay, string $fallback): int {
        if ($secondsOfDay < 0) $secondsOfDay = $this->ParseTime($fallback);

        $timestamp = strtotime("today") + $secondsOfDay;
        if ($timestamp <= time()) $timestamp += 86400;
        return $timestamp;
    }

    /**
     * Median der aufgezeichneten Start- beziehungsweise Endzeiten.
     * Der Median ist robuster als der Mittelwert - ein durchgehend truebes
     * Regenfenster verschiebt die Prognose sonst zu stark.
     */
    private function GetMedian(string $field): int {
        $history = json_decode($this->ReadAttributeString("PVHistory"), true);
        if (!is_array($history) || count($history) == 0) return -1;

        $values = [];
        foreach ($history as $day) {
            if (isset($day[$field])) $values[] = (int)$day[$field];
        }
        if (count($values) == 0) return -1;

        sort($values);
        $middle = intdiv(count($values), 2);
        if (count($values) % 2 == 1) return $values[$middle];
        return (int)round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    private function ParseTime(string $time): int {
        $parts = explode(":", trim($time));
        $hours = isset($parts[0]) ? (int)$parts[0] : 0;
        $minutes = isset($parts[1]) ? (int)$parts[1] : 0;
        if ($hours < 0 || $hours > 23) $hours = 0;
        if ($minutes < 0 || $minutes > 59) $minutes = 0;
        return ($hours * 3600) + ($minutes * 60);
    }

    // ------------------------------------------------------------------
    // Infrastruktur
    // ------------------------------------------------------------------

    private function CheckConfiguration(): bool {
        $missing = [];
        if ($this->ReadPropertyInteger("PowerSwitchID") <= 0) $missing[] = "Schaltvariable";
        if ($this->ReadPropertyInteger("MeterID") <= 0) $missing[] = "Stromzaehler";
        if (!$this->ReadPropertyBoolean("FreezerEnabled") && !$this->ReadPropertyBoolean("FridgeEnabled")) {
            $missing[] = "mindestens ein ueberwachtes Fach";
        }

        if (count($missing) > 0) {
            $this->SetStatus(201);
            $this->SetState(self::STATE_FAULT, "Konfiguration unvollstaendig: " . implode(", ", $missing) . ".");
            return false;
        }

        if ($this->GetStatus() == 201) $this->SetStatus(102);
        return true;
    }

    /**
     * Variablen, auf die eine Sofortreaktion noetig ist. Zaehler und
     * Verbrauchsmessung stehen bewusst nicht darin: die aktualisieren teils
     * sekuendlich, und fuer sie genuegt der Pruefzyklus.
     */
    private function GetWatchedVariables(): array {
        return [
            $this->ReadPropertyInteger("PowerSwitchID"),
            $this->ReadPropertyInteger("TempFreezerID"),
            $this->ReadPropertyInteger("TempFridgeID")
        ];
    }

    private function SetState(int $state, string $info) {
        if ($this->GetValue("State") != $state) {
            $this->SendDebug("State", "Zustandswechsel: $info", 0);
        }
        $this->SetValue("State", $state);
        $this->SetValue("Info", $info);
    }

    private function FormatHours(float $hours): string {
        if ($hours <= 0) return "0 min";
        if ($hours < 1) return round($hours * 60) . " min";
        return round($hours, 1) . " h";
    }

    private function FormatSeconds(int $secondsOfDay): string {
        return sprintf("%02d:%02d", intdiv($secondsOfDay, 3600), intdiv($secondsOfDay % 3600, 60));
    }

    private function FormatDrift(float $learned, float $default): string {
        if ($learned >= self::DRIFT_MIN) return round($learned, 2) . " Grad/h";
        return "noch nicht gelernt, Schaetzwert " . round($default, 2) . " Grad/h";
    }

    private function CreateProfiles() {
        if (!IPS_VariableProfileExists("PVKS.Mode")) {
            IPS_CreateVariableProfile("PVKS.Mode", 1);
            IPS_SetVariableProfileIcon("PVKS.Mode", "Gear");
            IPS_SetVariableProfileAssociation("PVKS.Mode", self::MODE_AUTO, "PV-Automatik", "Sun", 0x00AA00);
            IPS_SetVariableProfileAssociation("PVKS.Mode", self::MODE_NORMAL, "Normalbetrieb", "Plug", 0x0088FF);
            IPS_SetVariableProfileAssociation("PVKS.Mode", self::MODE_OFF, "Aus", "Power", 0xFF0000);
        }

        if (!IPS_VariableProfileExists("PVKS.State")) {
            IPS_CreateVariableProfile("PVKS.State", 1);
            IPS_SetVariableProfileIcon("PVKS.State", "Information");
            IPS_SetVariableProfileAssociation("PVKS.State", self::STATE_NORMAL, "Normalbetrieb", "Plug", 0x0088FF);
            IPS_SetVariableProfileAssociation("PVKS.State", self::STATE_PV_COOL, "PV-Kuehlung", "Sun", 0x00AA00);
            IPS_SetVariableProfileAssociation("PVKS.State", self::STATE_PRECOOL, "Vorkuehlen", "Sun", 0xAACC00);
            IPS_SetVariableProfileAssociation("PVKS.State", self::STATE_BUFFER, "Puffer aktiv", "Snowflake", 0x00CCCC);
            IPS_SetVariableProfileAssociation("PVKS.State", self::STATE_GRID_COOL, "Netz-Nachkuehlung", "Plug", 0xFF8800);
            IPS_SetVariableProfileAssociation("PVKS.State", self::STATE_LOCKED, "Sperrzeit", "Clock", 0x888888);
            IPS_SetVariableProfileAssociation("PVKS.State", self::STATE_MANUAL_OFF, "Handabschaltung", "Power", 0xFF0000);
            IPS_SetVariableProfileAssociation("PVKS.State", self::STATE_FAULT, "Stoerung", "Alert", 0xFF0000);
        }

        if (!IPS_VariableProfileExists("PVKS.Hours")) {
            IPS_CreateVariableProfile("PVKS.Hours", 2);
            IPS_SetVariableProfileText("PVKS.Hours", "", " h");
            IPS_SetVariableProfileDigits("PVKS.Hours", 1);
            IPS_SetVariableProfileValues("PVKS.Hours", 0, 48, 0.1);
            IPS_SetVariableProfileIcon("PVKS.Hours", "Clock");
        }
    }
}
?>
