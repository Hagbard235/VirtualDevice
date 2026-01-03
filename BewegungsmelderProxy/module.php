<?php

class BewegungsmelderProxy extends IPSModule {

    // Konstanten für den Modus
    const MODE_AUTO_LUX = 0;
    const MODE_ALWAYS_ON = 1;
    const MODE_ALWAYS_OFF = 2;
    const MODE_AUTO_NOLUX = 3;

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
        $this->RegisterPropertyInteger("Threshold", 120);
        $this->RegisterPropertyInteger("Duration", 300);

        // 2. Attribute (Interner Speicher für den "letzten Modus")
        $this->RegisterAttributeInteger("SavedMode", self::MODE_AUTO_LUX);

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
        $this->RegisterVariableInteger("Mode", "Modus", "BWM.Mode", 0);

        // 5. Aktionen aktivieren
        $this->EnableAction("Status");
        $this->EnableAction("Mode");

        // 6. Timer registrieren
        $this->RegisterTimer("AutoOffTimer", 0, 'BWMProxy_TimerEvent($_IPS[\'TARGET\']);');
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
            $this->SetValue("Status", $value);
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
                    $duration = $this->ReadPropertyInteger("Duration") * 1000;
                    $this->SendDebug("Manual", "Switched ON manually. Starting Timer: " . ($duration/1000) . "s", 0);
                    $this->SetTimerInterval("AutoOffTimer", $duration);
                } else {
                    // Manuell AUS -> Timer stoppen
                    $this->SendDebug("Manual", "Switched OFF manually. Stopping Timer.", 0);
                    $this->SetTimerInterval("AutoOffTimer", 0);
                }
                break;
            case "Mode":
                $this->ChangeMode($Value);
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
            // Wenn es dunkel genug ist ODER das Licht bereits an ist (dann ist es ja hell wegen uns),
            // dann soll nachgetriggert werden.
            // CheckLogic berücksichtigt jetzt den Trigger-Sensor für lokale Helligkeit
            if ($this->IsDarkEnough($triggerSensorID) || $this->GetValue("Status")) {
                $shouldSwitch = true;
            }
        }

        if ($shouldSwitch) {
            $this->SwitchLight(true);
            $duration = $this->ReadPropertyInteger("Duration") * 1000;
            $this->SendDebug("Logic", "Switching ON (or extending). Timer set to " . ($duration/1000) . "s", 0);
            $this->SetTimerInterval("AutoOffTimer", $duration);
        } else {
            $this->SendDebug("CheckLogic", "Conditions not met. No switch/extension.", 0);
        }
    }

    private function IsDarkEnough($triggerSensorID = 0) {
        $this->SendDebug("IsDarkEnough", "Checking if it's dark enough. Trigger: $triggerSensorID", 0);
        
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
                                 $threshold = $this->ReadPropertyInteger("Threshold");
                                 $isDark = ($lux <= $threshold);
                                 $this->SendDebug("IsDarkEnough", "Zone ($triggerSensorID) Brightness ($bID): $lux <= $threshold ? " . ($isDark ? "YES" : "NO"), 0);
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
            $this->SendDebug("IsDarkEnough", "External Var ($extDarkID) says: " . ($val ? "Dark" : "Bright"), 0);
            return $val;
        }

        // 2. Priorität: Interne Helligkeit vs Threshold
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        if ($luxID > 0 && IPS_VariableExists($luxID)) {
            $lux = GetValue($luxID);
            $threshold = $this->ReadPropertyInteger("Threshold");
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
            $this->SendDebug("SwitchLight", "Setting Device $targetID to " . ($state ? "TRUE" : "FALSE"), 0);
            $this->SetValue("Status", $state);
            @RequestAction($targetID, $state);
        } else {
            $this->SendDebug("SwitchLight", "No TargetLightID configured or variable does not exist. Cannot switch light.", 0);
        }
    }

    public function TimerEvent() {
        $this->SendDebug("Timer", "AutoOffTimer Expired", 0);
        $mode = $this->GetValue("Mode");
        // Nur ausschalten, wenn wir im Auto-Modus sind
        if ($mode == self::MODE_AUTO_LUX || $mode == self::MODE_AUTO_NOLUX) {
            $this->SwitchLight(false);
        }
        $this->SetTimerInterval("AutoOffTimer", 0);
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
