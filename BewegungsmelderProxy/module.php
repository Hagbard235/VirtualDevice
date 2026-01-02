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
        $this->RegisterPropertyInteger("ButtonTopID", 0);
        $this->RegisterPropertyInteger("ButtonBottomID", 0);
        
        $this->RegisterPropertyInteger("TargetLightID", 0);
        $this->RegisterPropertyInteger("SourceMotionID", 0);
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

        // IDs lesen
        $motionID = $this->ReadPropertyInteger("SourceMotionID");
        $lightID = $this->ReadPropertyInteger("TargetLightID");
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");
        $btnTopID = $this->ReadPropertyInteger("ButtonTopID");
        $btnBottomID = $this->ReadPropertyInteger("ButtonBottomID");

        // Messages registrieren
        // Wir nutzen VM_UPDATE, damit auch Taster erkannt werden, die ihren Wert nur aktualisieren (Timestamp), aber nicht ändern.
        if ($motionID > 0) $this->RegisterMessage($motionID, VM_UPDATE);
        if ($lightID > 0) $this->RegisterMessage($lightID, VM_UPDATE);
        if ($luxID > 0) $this->RegisterMessage($luxID, VM_UPDATE);
        if ($extDarkID > 0) $this->RegisterMessage($extDarkID, VM_UPDATE);
        if ($btnTopID > 0) $this->RegisterMessage($btnTopID, VM_UPDATE);
        if ($btnBottomID > 0) $this->RegisterMessage($btnBottomID, VM_UPDATE);

        // Helper Scripte (An/Aus) anlegen oder aktualisieren
        $this->CreateHelperScripts();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        
        $motionID = $this->ReadPropertyInteger("SourceMotionID");
        $lightID = $this->ReadPropertyInteger("TargetLightID");
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");
        $btnTopID = $this->ReadPropertyInteger("ButtonTopID");
        $btnBottomID = $this->ReadPropertyInteger("ButtonBottomID");
        
        $value = $Data[0];
        
        $senderName = "Unknown";
        if ($SenderID == $motionID) $senderName = "Motion Sensor";
        elseif ($SenderID == $lightID) $senderName = "Target Light State";
        elseif ($SenderID == $luxID) $senderName = "Brightness Sensor";
        elseif ($SenderID == $extDarkID) $senderName = "External Dark Trigger";
        elseif ($SenderID == $btnTopID) $senderName = "Button Top";
        elseif ($SenderID == $btnBottomID) $senderName = "Button Bottom";

        $this->SendDebug("MessageSink", "Event from $senderName ($SenderID), Value: " . json_encode($value), 0);

        // --- TASTER LOGIK ---
        
        // Taste OBEN: Modus auf "Dauer Ein"
        // Check auf $value === true, um sicherzugehen, dass es ein "Drücken" ist (und kein Loslassen bei Toggles)
        if ($SenderID == $btnTopID && $value === true) {
            $currentMode = $this->GetValue("Mode");
            if ($currentMode != self::MODE_ALWAYS_ON) {
                $this->SendDebug("Button", "Top Button pressed. Switching to ALWAYS_ON", 0);
                $this->WriteAttributeInteger("SavedMode", $currentMode);
                $this->ChangeMode(self::MODE_ALWAYS_ON);
            }
            return;
        }

        // Taste UNTEN: Zurück zum alten Modus
        if ($SenderID == $btnBottomID && $value === true) {
            $savedMode = $this->ReadAttributeInteger("SavedMode");
            
            // Fallback, falls SavedMode ungültig oder unlogisch wäre
            if ($savedMode == self::MODE_ALWAYS_ON) {
                $savedMode = self::MODE_AUTO_LUX;
            }
            
            $this->SendDebug("Button", "Bottom Button pressed. Restoring mode: " . $savedMode, 0);
            $this->ChangeMode($savedMode);
            return;
        }

        // --- STANDARD LOGIK ---

        if ($SenderID == $motionID) {
            $this->SetValue("Motion", $value);
            if ($value === true) {
                $this->CheckLogic();
            }
        } elseif ($SenderID == $lightID) {
            $this->SetValue("Status", $value);
        } elseif ($SenderID == $luxID) {
            $this->SetValue("Brightness", $value);
        }
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case "Status":
                $this->SwitchLight($Value);
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

    private function CheckLogic() {
        $mode = $this->GetValue("Mode");
        $this->SendDebug("Logic", "CheckLogic triggered. Current Mode: " . $mode, 0);
        
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
            if ($this->IsDarkEnough() || $this->GetValue("Status")) {
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

    private function IsDarkEnough() {
        $this->SendDebug("IsDarkEnough", "Checking if it's dark enough.", 0);
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
