<?php

class BewegungsmelderProxy extends IPSModule {

    // Konstanten für den Modus
    const MODE_AUTO_LUX = 0;
    const MODE_ALWAYS_ON = 1;
    const MODE_ALWAYS_OFF = 2;
    const MODE_AUTO_NOLUX = 3;

    public function Create() {
        parent::Create();

        // 1. Properties
        $this->RegisterPropertyInteger("ButtonTopID", 0);
        $this->RegisterPropertyInteger("ButtonBottomID", 0);
        
        $this->RegisterPropertyInteger("TargetLightID", 0);
        $this->RegisterPropertyInteger("SourceMotionID", 0);
        $this->RegisterPropertyInteger("SourceBrightnessID", 0);
        $this->RegisterPropertyInteger("SourceIsDarkID", 0);
        $this->RegisterPropertyInteger("Threshold", 120);
        $this->RegisterPropertyInteger("Duration", 300);

        // 2. Attribute
        $this->RegisterAttributeInteger("SavedMode", self::MODE_AUTO_LUX);

        // 3. Profil
        if (!IPS_VariableProfileExists("BWM.Mode")) {
            IPS_CreateVariableProfile("BWM.Mode", 1);
            IPS_SetVariableProfileAssociation("BWM.Mode", 0, "Auto (Lux)", "Motion", -1);
            IPS_SetVariableProfileAssociation("BWM.Mode", 1, "Dauer Ein", "Light", 0x00FF00);
            IPS_SetVariableProfileAssociation("BWM.Mode", 2, "Dauer Aus", "Sleep", 0xFF0000);
            IPS_SetVariableProfileAssociation("BWM.Mode", 3, "Auto (Tag+Nacht)", "Sun", 0xFFFF00);
            IPS_SetVariableProfileIcon("BWM.Mode", "Gear");
        }

        // 4. Variablen
        $this->RegisterVariableBoolean("Status", "Licht Status", "~Switch", 10);
        $this->RegisterVariableBoolean("Motion", "Bewegung", "~Motion", 20);
        $this->RegisterVariableInteger("Brightness", "Helligkeit", "~Illumination", 30);
        $this->RegisterVariableInteger("Mode", "Modus", "BWM.Mode", 0);

        // 5. Aktionen
        $this->EnableAction("Status");
        $this->EnableAction("Mode");

        // 6. Timer
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

        // Registrierungen
        if ($motionID > 0) $this->RegisterMessage($motionID, VM_UPDATE);
        if ($lightID > 0) $this->RegisterMessage($lightID, VM_UPDATE);
        if ($luxID > 0) $this->RegisterMessage($luxID, VM_UPDATE);
        if ($extDarkID > 0) $this->RegisterMessage($extDarkID, VM_UPDATE);
        if ($btnTopID > 0) $this->RegisterMessage($btnTopID, VM_UPDATE);
        if ($btnBottomID > 0) $this->RegisterMessage($btnBottomID, VM_UPDATE);

        // --- NEU: Helper Scripte anlegen ---
        $this->CreateHelperScripts();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        
        $motionID = $this->ReadPropertyInteger("SourceMotionID");
        $lightID = $this->ReadPropertyInteger("TargetLightID");
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        $btnTopID = $this->ReadPropertyInteger("ButtonTopID");
        $btnBottomID = $this->ReadPropertyInteger("ButtonBottomID");
        
        $value = $Data[0];

        // TASTER
        if ($SenderID == $btnTopID && $value === true) {
            $currentMode = $this->GetValue("Mode");
            if ($currentMode != self::MODE_ALWAYS_ON) {
                $this->WriteAttributeInteger("SavedMode", $currentMode);
                $this->ChangeMode(self::MODE_ALWAYS_ON);
            }
            return;
        }

        if ($SenderID == $btnBottomID && $value === true) {
            $savedMode = $this->ReadAttributeInteger("SavedMode");
            if ($savedMode == self::MODE_ALWAYS_ON) {
                $savedMode = self::MODE_AUTO_LUX;
            }
            $this->ChangeMode($savedMode);
            return;
        }

        // STANDARD
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

    private function ChangeMode($newMode) {
        $this->SetValue("Mode", $newMode);
        
        if ($newMode == self::MODE_ALWAYS_ON) {
            $this->SwitchLight(true);
            $this->SetTimerInterval("AutoOffTimer", 0);
        } elseif ($newMode == self::MODE_ALWAYS_OFF) {
            $this->SwitchLight(false);
            $this->SetTimerInterval("AutoOffTimer", 0);
        } elseif ($newMode == self::MODE_AUTO_LUX || $newMode == self::MODE_AUTO_NOLUX) {
            if (!$this->GetValue("Motion")) {
                 $this->SwitchLight(false);
            } else {
                 $this->CheckLogic();
            }
        }
    }

    private function CheckLogic() {
        $mode = $this->GetValue("Mode");
        
        if ($mode == self::MODE_ALWAYS_OFF) return;
        
        if ($mode == self::MODE_ALWAYS_ON) {
            $this->SwitchLight(true);
            return;
        }

        $shouldSwitch = false;
        if ($mode == self::MODE_AUTO_NOLUX) {
            $shouldSwitch = true;
        } elseif ($mode == self::MODE_AUTO_LUX) {
            if ($this->IsDarkEnough()) {
                $shouldSwitch = true;
            }
        }

        if ($shouldSwitch) {
            $this->SwitchLight(true);
            $duration = $this->ReadPropertyInteger("Duration") * 1000;
            $this->SetTimerInterval("AutoOffTimer", $duration);
        }
    }

    private function IsDarkEnough() {
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");
        if ($extDarkID > 0 && IPS_VariableExists($extDarkID)) {
            return GetValueBoolean($extDarkID);
        }

        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        if ($luxID > 0 && IPS_VariableExists($luxID)) {
            $lux = GetValue($luxID);
            $threshold = $this->ReadPropertyInteger("Threshold");
            return ($lux <= $threshold);
        }
        return true; 
    }

    private function SwitchLight($state) {
        $targetID = $this->ReadPropertyInteger("TargetLightID");
        if ($targetID > 0 && IPS_VariableExists($targetID)) {
            $this->SetValue("Status", $state);
            @RequestAction($targetID, $state);
        }
    }

    public function TimerEvent() {
        $mode = $this->GetValue("Mode");
        if ($mode == self::MODE_AUTO_LUX || $mode == self::MODE_AUTO_NOLUX) {
            $this->SwitchLight(false);
        }
        $this->SetTimerInterval("AutoOffTimer", 0);
    }

    // --- NEUE HILFSFUNKTION FÜR SCRIPTE ---
    private function CreateHelperScripts() {
        // Script "An"
        $sidAn = @IPS_GetObjectIDByIdent("ScriptAn", $this->InstanceID);
        if ($sidAn === false) {
            $sidAn = IPS_CreateScript(0);
            IPS_SetParent($sidAn, $this->InstanceID);
            IPS_SetIdent($sidAn, "ScriptAn");
            IPS_SetName($sidAn, "An");
            IPS_SetHidden($sidAn, true); // Auf false setzen, wenn du sie sehen willst
            IPS_SetPosition($sidAn, 100);
            
            // Inhalt: Ruft RequestAction auf Parent auf
            $content = "<?php\nRequestAction(IPS_GetParent(\$_IPS['SELF']), 'Status', true);\n?>";
            IPS_SetScriptContent($sidAn, $content);
        }

        // Script "Aus"
        $sidAus = @IPS_GetObjectIDByIdent("ScriptAus", $this->InstanceID);
        if ($sidAus === false) {
            $sidAus = IPS_CreateScript(0);
            IPS_SetParent($sidAus, $this->InstanceID);
            IPS_SetIdent($sidAus, "ScriptAus");
            IPS_SetName($sidAus, "Aus");
            IPS_SetHidden($sidAus, true); // Auf false setzen, wenn du sie sehen willst
            IPS_SetPosition($sidAus, 101);

            // Inhalt: Ruft RequestAction auf Parent auf
            $content = "<?php\nRequestAction(IPS_GetParent(\$_IPS['SELF']), 'Status', false);\n?>";
            IPS_SetScriptContent($sidAus, $content);
        }
    }
}
?>
