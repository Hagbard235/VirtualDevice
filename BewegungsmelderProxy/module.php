<?php

class BewegungsmelderProxy extends IPSModule {

    // Konstanten für den Modus
    const MODE_AUTO_LUX = 0;
    const MODE_ALWAYS_ON = 1;
    const MODE_ALWAYS_OFF = 2;
    const MODE_AUTO_NOLUX = 3;

    // Zeitfenster, in dem nach einem Schaltbefehl auf die Rückmeldung des Geräts
    // gewartet wird. MQTT- und Eltako-Aktoren brauchen rund eine Sekunde.
    const SWITCH_TIMEOUT = 5.0;

    public function Create() {
        parent::Create();

        // 1. Properties registrieren
        $this->RegisterPropertyInteger("ButtonAlwaysOnID", 0);
        $this->RegisterPropertyInteger("ButtonAlwaysOffID", 0);
        $this->RegisterPropertyInteger("ButtonAutoID", 0);
        
        // Veraltete Properties für Migration
        $this->RegisterPropertyInteger("ButtonTopID", 0);
        $this->RegisterPropertyInteger("ButtonBottomID", 0);
        
        $this->RegisterPropertyInteger("TargetLightID", 0);
        $this->RegisterPropertyString("MotionSensors", "[]");
        $this->RegisterPropertyInteger("SourceMotionID", 0); // Veraltet -> Migration
        $this->RegisterPropertyInteger("SourceBrightnessID", 0);
        $this->RegisterPropertyInteger("SourceIsDarkID", 0);
        $this->RegisterPropertyBoolean("InvertIsDark", false);
        $this->RegisterPropertyInteger("Threshold", 120);
        $this->RegisterPropertyInteger("Duration", 300);

        // 2. Attribute (Interner Speicher für den "letzten Modus")
        $this->RegisterAttributeInteger("SavedMode", self::MODE_AUTO_LUX);

        // Merker, ob die Automatik im laufenden Nachlauf-Zyklus eingeschaltet hat.
        // Nur dann darf die Helligkeitsprüfung übersprungen werden (siehe CheckLogic).
        $this->RegisterAttributeBoolean("AutoCycleActive", false);

        // 3. Profil erstellen
        if (!IPS_VariableProfileExists("BWM.Mode")) {
            IPS_CreateVariableProfile("BWM.Mode", 1);
            IPS_SetVariableProfileAssociation("BWM.Mode", 0, "Auto (Lux)", "Motion", -1);
            IPS_SetVariableProfileAssociation("BWM.Mode", 1, "Dauer Ein", "Light", 0x00FF00);
            IPS_SetVariableProfileAssociation("BWM.Mode", 2, "Dauer Aus", "Sleep", 0xFF0000);
            IPS_SetVariableProfileAssociation("BWM.Mode", 3, "Auto (Tag+Nacht)", "Sun", 0xFFFF00);
            IPS_SetVariableProfileIcon("BWM.Mode", "Gear");
        }

        // 4. Status-Variablen registrieren
        $this->RegisterVariableBoolean("Status", "Licht Status", "~Switch", 10);
        $this->RegisterVariableBoolean("Motion", "Bewegung", "~Motion", 20);
        $this->RegisterVariableInteger("Brightness", "Helligkeit", "~Illumination", 30);
        $this->RegisterVariableInteger("ThresholdVar", "Schaltschwelle", "", 35);
        $this->EnableAction("ThresholdVar");
        
        $this->RegisterVariableInteger("Mode", "Modus", "BWM.Mode", 0);
        $this->EnableAction("Mode");

        // 5. Aktionen aktivieren
        $this->EnableAction("Status");
        $this->EnableAction("Mode");

        // 6. Timer registrieren
        $this->RegisterTimer("AutoOffTimer", 0, 'BWMProxy_TimerEvent($_IPS[\'TARGET\']);');
        $this->RegisterTimer("VerifyTimer", 0, 'BWMProxy_VerifySwitch($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Migration Motion ID -> Liste
        $oldMotionID = $this->ReadPropertyInteger("SourceMotionID");
        if ($oldMotionID > 0) {
            $newList = json_encode([['VariableID' => $oldMotionID]]);
            IPS_SetProperty($this->InstanceID, "MotionSensors", $newList);
            IPS_SetProperty($this->InstanceID, "SourceMotionID", 0);
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        // IDs lesen
        $motionSensors = json_decode($this->ReadPropertyString("MotionSensors"), true);
        $lightID = $this->ReadPropertyInteger("TargetLightID");
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");
        
        // Initialisierung ThresholdVar IMMER aus Property beim Übernehmen (User-Intent Konsole)
        $this->SetValue("ThresholdVar", $this->ReadPropertyInteger("Threshold"));

        
        // Migration alter Properties
        $oldTop = $this->ReadPropertyInteger("ButtonTopID");
        if ($oldTop > 0) {
            IPS_SetProperty($this->InstanceID, "ButtonAlwaysOnID", $oldTop);
            IPS_SetProperty($this->InstanceID, "ButtonTopID", 0); // Löschen
            IPS_ApplyChanges($this->InstanceID); // Rekursiver Aufruf für sauberes Reload
            return; 
        }
        $oldBottom = $this->ReadPropertyInteger("ButtonBottomID");
        if ($oldBottom > 0) {
            IPS_SetProperty($this->InstanceID, "ButtonAutoID", $oldBottom);
            IPS_SetProperty($this->InstanceID, "ButtonBottomID", 0); // Löschen
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $btnOnID = $this->ReadPropertyInteger("ButtonAlwaysOnID");
        $btnOffID = $this->ReadPropertyInteger("ButtonAlwaysOffID");
        $btnAutoID = $this->ReadPropertyInteger("ButtonAutoID");

        // Messages registrieren
        // Wir nutzen VM_UPDATE, damit auch Taster erkannt werden, die ihren Wert nur aktualisieren (Timestamp), aber nicht ändern.
        // Messages für alle Motion Sensoren registrieren
        if (is_array($motionSensors)) {
            foreach ($motionSensors as $sensor) {
                $mID = $sensor['VariableID'];
                if ($mID > 0) $this->RegisterMessage($mID, VM_UPDATE);
                if (isset($sensor['BrightnessVariableID'])) {
                    $bID = $sensor['BrightnessVariableID'];
                    if ($bID > 0) $this->RegisterMessage($bID, VM_UPDATE);
                }
            }
        }
        if ($lightID > 0) $this->RegisterMessage($lightID, VM_UPDATE);
        if ($luxID > 0) $this->RegisterMessage($luxID, VM_UPDATE);
        if ($extDarkID > 0) $this->RegisterMessage($extDarkID, VM_UPDATE);
        if ($btnOnID > 0) $this->RegisterMessage($btnOnID, VM_UPDATE);
        if ($btnOffID > 0) $this->RegisterMessage($btnOffID, VM_UPDATE);
        if ($btnAutoID > 0) $this->RegisterMessage($btnAutoID, VM_UPDATE);

        // Status aus dem tatsächlichen Gerätezustand synchronisieren.
        // Nach einem IPS-Neustart stellt Symcon den zuletzt gespeicherten Wert wieder her,
        // der nicht zwingend zum echten Licht passt. Ein stehengebliebenes "AN" würde in
        // CheckLogic die Helligkeitsprüfung überbrücken.
        if ($lightID > 0 && IPS_VariableExists($lightID)) {
            $actualState = GetValueBoolean($lightID);
            if ($this->GetValue("Status") !== $actualState) {
                $this->SendDebug("ApplyChanges", "Status war desynchron. Korrigiert auf " . ($actualState ? "TRUE" : "FALSE"), 0);
            }
            $this->SetValue("Status", $actualState);
        }
        $this->WriteAttributeBoolean("AutoCycleActive", false);
        $this->SetBuffer("PendingSwitch", "");
        $this->SetTimerInterval("VerifyTimer", 0);

        // Helper Scripte (An/Aus) anlegen oder aktualisieren
        $this->CreateHelperScripts();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        
        $motionSensors = json_decode($this->ReadPropertyString("MotionSensors"), true);
        $lightID = $this->ReadPropertyInteger("TargetLightID");
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");
        $btnOnID = $this->ReadPropertyInteger("ButtonAlwaysOnID");
        $btnOffID = $this->ReadPropertyInteger("ButtonAlwaysOffID");
        $btnAutoID = $this->ReadPropertyInteger("ButtonAutoID");
        
        $value = $Data[0];
        
        $senderName = "Unknown";
        $isMotionSender = false;
        
        // Prüfen ob Sender einer der Motion Sensoren ist ODER ein lokaler Helligkeitssensor
        $isLocalBrightnessSender = false;
        $linkedMotionID = 0; // Zuordnung bei Motion oder lokalem Helligkeitssensor

        if (is_array($motionSensors)) {
            foreach ($motionSensors as $sensor) {
                if ($SenderID == $sensor['VariableID']) {
                    $senderName = "Motion Sensor (" . $SenderID . ")";
                    $isMotionSender = true;
                    $linkedMotionID = $SenderID;
                    break;
                }
                if (isset($sensor['BrightnessVariableID']) && $SenderID == $sensor['BrightnessVariableID']) {
                     $senderName = "Local Brightness Sensor (" . $SenderID . ")";
                     $isLocalBrightnessSender = true;
                     $linkedMotionID = $sensor['VariableID']; // Zugehöriger Bewegungsmelder
                     break;
                }
            }
        }
        
        if ($isMotionSender) { /* already handled above */ }
        elseif ($SenderID == $lightID) $senderName = "Target Light State";
        elseif ($SenderID == $luxID) $senderName = "Brightness Sensor";
        elseif ($SenderID == $extDarkID) $senderName = "External Dark Trigger";
        elseif ($SenderID == $btnOnID) $senderName = "Button Always ON";
        elseif ($SenderID == $btnOffID) $senderName = "Button Always OFF";
        elseif ($SenderID == $btnAutoID) $senderName = "Button Auto/Restore";

        $this->SendDebug("MessageSink", "Event from $senderName ($SenderID), Value: " . json_encode($value), 0);

        // --- TASTER LOGIK ---
        
        // --- TASTER LOGIK ---
        
        // Taste DAUER EIN
        if ($SenderID == $btnOnID && $value === true) {
            $currentMode = $this->GetValue("Mode");
            if ($currentMode != self::MODE_ALWAYS_ON) {
                $this->SendDebug("Button", "Switching to ALWAYS_ON", 0);
                $this->WriteAttributeInteger("SavedMode", $currentMode);
                $this->ChangeMode(self::MODE_ALWAYS_ON);
            }
            return;
        }

        // Taste DAUER AUS
        if ($SenderID == $btnOffID && $value === true) {
            $currentMode = $this->GetValue("Mode");
            if ($currentMode != self::MODE_ALWAYS_OFF) {
                $this->SendDebug("Button", "Switching to ALWAYS_OFF", 0);
                $this->WriteAttributeInteger("SavedMode", $currentMode); // AUCH hier speichern, falls man von Auto kommt
                $this->ChangeMode(self::MODE_ALWAYS_OFF);
            }
            return;
        }

        // Taste AUTO / RESTORE
        if ($SenderID == $btnAutoID && $value === true) {
            $savedMode = $this->ReadAttributeInteger("SavedMode");
            
            // Plausibilitätscheck
            if ($savedMode == self::MODE_ALWAYS_ON || $savedMode == self::MODE_ALWAYS_OFF) {
                $savedMode = self::MODE_AUTO_LUX;
            }
            
            $this->SendDebug("Button", "Restore/Auto Button pressed. Mode: " . $savedMode, 0);
            $this->ChangeMode($savedMode);
            return;
        }

        // --- STANDARD LOGIK ---

        // --- STANDARD LOGIK ---

        if ($isMotionSender) {
            // Gesamtzustand ermitteln (ODER Verknüpfung aller Sensoren)
            // Wir nutzen nicht nur $value, da jetzt auch ein anderer Sensor aktiv sein könnte.
            $unifiedMotion = $this->GetMotionState();
            $this->SetValue("Motion", $unifiedMotion);
            
            if ($unifiedMotion === true) {
                $this->CheckLogic($linkedMotionID);
            }
        } elseif ($SenderID == $lightID) {
            // Die Rückmeldung des Geräts ist die verbindliche Quelle für "Status".
            // Solange die Schaltverzögerung läuft, akzeptieren wir aber nur die
            // Bestätigung des gewünschten Zustands - ein widersprechendes Echo ist in
            // diesem Fenster veraltet (z.B. retained MQTT-Topic) und würde Status
            // fälschlich zurückwerfen.
            // Der Merker liegt im Buffer, da Instanz-Properties zwischen zwei
            // MessageSink-Aufrufen nicht erhalten bleiben.
            $pending = json_decode($this->GetBuffer("PendingSwitch"), true);
            $isPending = is_array($pending) && (microtime(true) - $pending['ts']) < self::SWITCH_TIMEOUT;

            if ($isPending && $value !== $pending['state']) {
                $this->SendDebug("MessageSink", "Veraltetes Echo von Licht $lightID verworfen (erwartet: " . ($pending['state'] ? "TRUE" : "FALSE") . ", erhalten: " . ($value ? "TRUE" : "FALSE") . ")", 0);
            } else {
                if ($isPending) {
                    $this->SendDebug("MessageSink", "Schaltvorgang von Gerät $lightID bestätigt: " . ($value ? "TRUE" : "FALSE"), 0);
                    $this->SetBuffer("PendingSwitch", "");
                    $this->SetTimerInterval("VerifyTimer", 0); // Nachkontrolle nicht mehr nötig
                }
                $this->SetValue("Status", $value);
            }
        } elseif ($SenderID == $luxID || $SenderID == $extDarkID || $isLocalBrightnessSender) {
             if ($SenderID == $luxID) {
                $this->SetValue("Brightness", $value);
             }
             
             // Race Condition Fix:
             // Falls Hardware erst Bewegung meldet (noch zu hell) und millisekunden später den neuen Helligkeitswert,
             // müssen wir hier nach-prüfen, sofern Bewegung noch aktiv ist.
             if ($this->GetValue("Motion")) {
                 $this->SendDebug("Logic", "Brightness/Darkness update while Motion is active -> Re-evaluating Logic", 0);
                 $recheckID = $isLocalBrightnessSender ? $linkedMotionID : 0;
                 $this->CheckLogic($recheckID);
             }
        }
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case "Status":
                // Manuelles Schalten der Status-Variable
                $this->SwitchLight($Value);
                if ($Value) {
                    // Manuell AN -> Timer starten (simuliert Bewegung)
                    // Zyklus als aktiv markieren, damit Bewegung den Nachlauf verlängern
                    // kann, ohne an der Helligkeitsprüfung zu scheitern.
                    $duration = $this->ReadPropertyInteger("Duration") * 1000;
                    $this->SendDebug("Manual", "Switched ON manually. Starting Timer: " . ($duration/1000) . "s", 0);
                    $this->WriteAttributeBoolean("AutoCycleActive", true);
                    $this->SetTimerInterval("AutoOffTimer", $duration);
                } else {
                    // Manuell AUS -> Timer stoppen
                    $this->SendDebug("Manual", "Switched OFF manually. Stopping Timer.", 0);
                    $this->WriteAttributeBoolean("AutoCycleActive", false);
                    $this->SetTimerInterval("AutoOffTimer", 0);
                }
                break;
            case "Mode":
                $this->ChangeMode($Value);
                break;
            case "ThresholdVar":
                $this->SetValue("ThresholdVar", $Value);
                // Sync to Property for Consistency (no ApplyChanges)
                IPS_SetProperty($this->InstanceID, "Threshold", $Value);
                break;
        }
    }

    /**
     * Öffentliche Funktion: Kann von Scripten direkt aufgerufen werden.
     * Befehl: BWMProxy_SetLight(InstanceID, true|false);
     */
    public function SetLight(bool $State) {
        $this->SwitchLight($State);
    }

    private function ChangeMode($newMode) {
        $this->SendDebug("Mode", "Changing Mode to: " . $newMode, 0);
        $this->SetValue("Mode", $newMode);

        // Moduswechsel beendet den laufenden Automatik-Zyklus. CheckLogic weiter unten
        // setzt das Flag bei Bedarf neu.
        $this->WriteAttributeBoolean("AutoCycleActive", false);

        // Sofortige Reaktion auf Moduswechsel
        if ($newMode == self::MODE_ALWAYS_ON) {
            $this->SwitchLight(true);
            $this->SetTimerInterval("AutoOffTimer", 0); // Timer aus
            
        } elseif ($newMode == self::MODE_ALWAYS_OFF) {
            $this->SwitchLight(false);
            $this->SetTimerInterval("AutoOffTimer", 0);
            
        } elseif ($newMode == self::MODE_AUTO_LUX || $newMode == self::MODE_AUTO_NOLUX) {
            // Beim Wechsel zurück auf Automatik prüfen wir die aktuelle Lage.
            // Wenn keine Bewegung mehr da ist -> Aus.
            if (!$this->GetValue("Motion")) {
                 $this->SwitchLight(false);
            } else {
                 $this->CheckLogic();
            }
        }
    }

    private function CheckLogic($triggerSensorID = 0) {
        $mode = $this->GetValue("Mode");
        $this->SendDebug("Logic", "CheckLogic triggered. Current Mode: " . $mode . ", Trigger: " . $triggerSensorID, 0);
        
        if ($mode == self::MODE_ALWAYS_OFF) return;
        
        if ($mode == self::MODE_ALWAYS_ON) {
            $this->SwitchLight(true);
            return;
        }

        $shouldSwitch = false;
        if ($mode == self::MODE_AUTO_NOLUX) {
            $shouldSwitch = true;
        } elseif ($mode == self::MODE_AUTO_LUX) {
            // Wenn es dunkel genug ist ODER die Automatik in diesem Zyklus bereits
            // eingeschaltet hat (dann ist es ja hell wegen uns), soll nachgetriggert werden.
            // Bewusst NICHT die Status-Variable: die kann vom echten Gerät abweichen und
            // würde die Helligkeitsprüfung dann dauerhaft aushebeln.
            // CheckLogic berücksichtigt jetzt den Trigger-Sensor für lokale Helligkeit
            if ($this->IsDarkEnough($triggerSensorID) || $this->ReadAttributeBoolean("AutoCycleActive")) {
                $shouldSwitch = true;
            }
        }

        if ($shouldSwitch) {
            $this->SwitchLight(true);
            $this->WriteAttributeBoolean("AutoCycleActive", true);
            $duration = $this->ReadPropertyInteger("Duration") * 1000;
            $this->SendDebug("Logic", "Switching ON (or extending). Timer set to " . ($duration/1000) . "s", 0);
            $this->SetTimerInterval("AutoOffTimer", $duration);
        } else {
            $this->SendDebug("CheckLogic", "Conditions not met. No switch/extension.", 0);
        }
    }

    private function IsDarkEnough($triggerSensorID = 0) {
        $this->SendDebug("IsDarkEnough", "Checking if it's dark enough. Trigger: $triggerSensorID", 0);
        
        // Helper: Ermittle aktuellen Threshold (Variable hat Vorrang vor Property)
        $threshold = $this->GetValue("ThresholdVar");
        if ($threshold == 0) $threshold = $this->ReadPropertyInteger("Threshold"); // Fallback
        
        // 0. Sonderprüfung für Trigger-Sensor (Zone)
        if ($triggerSensorID > 0) {
            $motionSensors = json_decode($this->ReadPropertyString("MotionSensors"), true);
            if (is_array($motionSensors)) {
                foreach ($motionSensors as $sensor) {
                     if ($sensor['VariableID'] == $triggerSensorID) {
                         if (isset($sensor['BrightnessVariableID']) && $sensor['BrightnessVariableID'] > 0) {
                             $bID = $sensor['BrightnessVariableID'];
                             if (IPS_VariableExists($bID)) {
                                 $lux = GetValue($bID);
                                 $this->SetValue("Brightness", $lux); // Update display variable
                                 $isDark = ($lux <= $threshold);
                                 $this->SendDebug("IsDarkEnough", "Zone ($triggerSensorID) Brightness ($bID): $lux <= Threshold ($threshold) ? " . ($isDark ? "YES" : "NO"), 0);
                                 return $isDark;
                             }
                         }
                         break; 
                     }
                }
            }
        }

        // 1. Priorität: Externe Variable
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");
        if ($extDarkID > 0 && IPS_VariableExists($extDarkID)) {
            $val = GetValueBoolean($extDarkID);
            // Invertierung prüfen
            if ($this->ReadPropertyBoolean("InvertIsDark")) {
                $val = !$val;
                //$this->SendDebug("IsDarkEnough", "InvertIsDark active.", 0);
            }
            $this->SendDebug("IsDarkEnough", "External Var ($extDarkID) says: " . ($val ? "Dark" : "Bright"), 0);
            return $val;
        }

        // 2. Priorität: Interne Helligkeit vs Threshold
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        if ($luxID > 0 && IPS_VariableExists($luxID)) {
            $lux = GetValue($luxID);
            $this->SetValue("Brightness", $lux); // Update display
            $isDark = ($lux <= $threshold);
            $this->SendDebug("IsDarkEnough", "Lux: $lux <= Threshold: $threshold ? " . ($isDark ? "YES" : "NO"), 0);
            return $isDark;
        }

        // Fallback: Immer dunkel annehmen
        $this->SendDebug("IsDarkEnough", "No sources defined. Assuming DARK.", 0);
        return true; 
    }

    private function SwitchLight($state) {
        $targetID = $this->ReadPropertyInteger("TargetLightID");
        if ($targetID > 0 && IPS_VariableExists($targetID)) {
            // Traffic-Optimierung: Nur schalten, wenn Zustand abweicht
            $currentState = GetValueBoolean($targetID);

            if ($currentState !== $state) {
                $this->SendDebug("SwitchLight", "Setting Device $targetID to " . ($state ? "TRUE" : "FALSE"), 0);

                // Gewünschten Zustand samt Zeitpunkt hinterlegen. Solange die
                // Schaltverzögerung läuft, dient das dem MessageSink zur Unterscheidung
                // von Bestätigung und veraltetem Echo.
                $this->SetBuffer("PendingSwitch", json_encode(['state' => $state, 'ts' => microtime(true)]));

                // Nachkontrolle anstoßen, falls die Rückmeldung ausbleibt.
                $this->SetTimerInterval("VerifyTimer", (int)(self::SWITCH_TIMEOUT * 1000));

                @RequestAction($targetID, $state);
            } else {
                //$this->SendDebug("SwitchLight", "Device $targetID is already " . ($state ? "TRUE" : "FALSE") . ". Skipping.", 0);
            }

            // Status vorläufig auf den Wunschzustand setzen. Zurücklesen bringt hier nichts:
            // das Gerät (MQTT/Eltako) braucht bis zu einer Sekunde. Die verbindliche
            // Korrektur kommt aus der Rückmeldung im MessageSink.
            $this->SetValue("Status", $state);
        } else {
            $this->SendDebug("SwitchLight", "No TargetLightID configured or variable does not exist. Cannot switch light.", 0);
        }
    }

    public function TimerEvent() {
        $this->SendDebug("Timer", "AutoOffTimer Expired", 0);
        
        // Safety Check: Ist noch Bewegung da?
        // Wenn der Sensor noch "True" meldet (Dauerpräsenz), darf das Licht nicht ausgehen.
        if ($this->GetValue("Motion")) {
             $duration = $this->ReadPropertyInteger("Duration") * 1000;
             $this->SendDebug("Timer", "Motion still active! Extending timer by " . ($duration/1000) . "s", 0);
             $this->SetTimerInterval("AutoOffTimer", $duration);
             return;
        }

        $mode = $this->GetValue("Mode");
        // Nur ausschalten, wenn wir im Auto-Modus sind
        if ($mode == self::MODE_AUTO_LUX || $mode == self::MODE_AUTO_NOLUX) {
            $this->SwitchLight(false);
        }
        // Zyklus ist beendet - der nächste Trigger muss die Helligkeit wieder prüfen.
        // Auch in den Dauer-Modi zurücksetzen, damit kein Flag stehenbleibt.
        $this->WriteAttributeBoolean("AutoCycleActive", false);
        $this->SetTimerInterval("AutoOffTimer", 0);
    }

    /**
     * Nachkontrolle nach einem Schaltbefehl.
     * Läuft nur an, wenn innerhalb von SWITCH_TIMEOUT keine Rückmeldung kam.
     */
    public function VerifySwitch() {
        $this->SetTimerInterval("VerifyTimer", 0);

        $pending = json_decode($this->GetBuffer("PendingSwitch"), true);
        if (!is_array($pending)) {
            // Rückmeldung war schon da und wurde im MessageSink verarbeitet.
            return;
        }
        $this->SetBuffer("PendingSwitch", "");

        $targetID = $this->ReadPropertyInteger("TargetLightID");
        if ($targetID <= 0 || !IPS_VariableExists($targetID)) return;

        $wanted = $pending['state'];
        $actualState = GetValueBoolean($targetID);

        if ($actualState === $wanted) {
            // Gerät hat geschaltet, nur ohne dass uns eine Meldung erreicht hat.
            $this->SendDebug("Verify", "Gerät $targetID steht auf " . ($actualState ? "TRUE" : "FALSE") . " - Rückmeldung blieb aus, Zustand stimmt.", 0);
        } else {
            $this->SendDebug("Verify", "WARNUNG: Gerät $targetID hat den Schaltbefehl nicht übernommen. Gewollt: " . ($wanted ? "TRUE" : "FALSE") . ", tatsächlich: " . ($actualState ? "TRUE" : "FALSE"), 0);

            // Wenn das Einschalten fehlschlug, darf kein Automatik-Zyklus laufen -
            // sonst würde die Helligkeitsprüfung beim nächsten Trigger übersprungen,
            // obwohl gar kein Licht brennt.
            if ($wanted === true) {
                $this->WriteAttributeBoolean("AutoCycleActive", false);
            }
        }

        $this->SetValue("Status", $actualState);
    }

    private function GetMotionState() {
        $motionSensors = json_decode($this->ReadPropertyString("MotionSensors"), true);
        if (!is_array($motionSensors)) return false;
        
        foreach ($motionSensors as $sensor) {
            $id = $sensor['VariableID'];
            if ($id > 0 && IPS_VariableExists($id)) {
                if (GetValueBoolean($id)) return true;
            }
        }
        return false;
    }

    private function CreateHelperScripts() {
        // 1. Script "An"
        $sidAn = @IPS_GetObjectIDByIdent("ScriptAn", $this->InstanceID);
        if ($sidAn === false) {
            $sidAn = IPS_CreateScript(0);
            IPS_SetParent($sidAn, $this->InstanceID);
            IPS_SetIdent($sidAn, "ScriptAn");
            IPS_SetName($sidAn, "An");
            IPS_SetHidden($sidAn, true); 
            IPS_SetPosition($sidAn, 100);
        }
        
        // Inhalt: Direkter Aufruf der Public Function
        $contentAn = "<?php\n" .
                     "BWMProxy_SetLight(IPS_GetParent(\$_IPS['SELF']), true);\n" .
                     "?>";
        IPS_SetScriptContent($sidAn, $contentAn);


        // 2. Script "Aus"
        $sidAus = @IPS_GetObjectIDByIdent("ScriptAus", $this->InstanceID);
        if ($sidAus === false) {
            $sidAus = IPS_CreateScript(0);
            IPS_SetParent($sidAus, $this->InstanceID);
            IPS_SetIdent($sidAus, "ScriptAus");
            IPS_SetName($sidAus, "Aus");
            IPS_SetHidden($sidAus, true);
            IPS_SetPosition($sidAus, 101);
        }

        // Inhalt: Direkter Aufruf der Public Function
        $contentAus = "<?php\n" .
                      "BWMProxy_SetLight(IPS_GetParent(\$_IPS['SELF']), false);\n" .
                      "?>";
        IPS_SetScriptContent($sidAus, $contentAus);
    }
}
?>
