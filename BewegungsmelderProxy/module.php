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
        $this->RegisterPropertyInteger("TargetLightID", 0);
        $this->RegisterPropertyInteger("SourceMotionID", 0);
        $this->RegisterPropertyInteger("SourceBrightnessID", 0);
        $this->RegisterPropertyInteger("SourceIsDarkID", 0);
        $this->RegisterPropertyInteger("Threshold", 120);
        $this->RegisterPropertyInteger("Duration", 300);

        // 2. Profil erstellen
        if (!IPS_VariableProfileExists("BWM.Mode")) {
            IPS_CreateVariableProfile("BWM.Mode", 1);
            IPS_SetVariableProfileAssociation("BWM.Mode", 0, "Auto (Lux)", "Motion", -1);
            IPS_SetVariableProfileAssociation("BWM.Mode", 1, "Dauer Ein", "Light", 0x00FF00);
            IPS_SetVariableProfileAssociation("BWM.Mode", 2, "Dauer Aus", "Sleep", 0xFF0000);
            IPS_SetVariableProfileAssociation("BWM.Mode", 3, "Auto (Tag+Nacht)", "Sun", 0xFFFF00);
            IPS_SetVariableProfileIcon("BWM.Mode", "Gear");
        }

        // 3. Status-Variablen registrieren
        $this->RegisterVariableBoolean("Status", "Licht Status", "~Switch", 10);
        $this->RegisterVariableBoolean("Motion", "Bewegung", "~Motion", 20);
        $this->RegisterVariableInteger("Brightness", "Helligkeit", "~Illumination", 30);
        $this->RegisterVariableInteger("Mode", "Modus", "BWM.Mode", 0);

        // 4. Aktionen aktivieren
        $this->EnableAction("Status");
        $this->EnableAction("Mode");

        // 5. Timer registrieren
        $this->RegisterTimer("AutoOffTimer", 0, 'BWMProxy_TimerEvent($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        $motionID = $this->ReadPropertyInteger("SourceMotionID");
        $lightID = $this->ReadPropertyInteger("TargetLightID");
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");

        // Messages registrieren
        if ($motionID > 0) $this->RegisterMessage($motionID, VM_UPDATE);
        if ($lightID > 0) $this->RegisterMessage($lightID, VM_UPDATE);
        if ($luxID > 0) $this->RegisterMessage($luxID, VM_UPDATE);
        if ($extDarkID > 0) $this->RegisterMessage($extDarkID, VM_UPDATE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        
        $motionID = $this->ReadPropertyInteger("SourceMotionID");
        $lightID = $this->ReadPropertyInteger("TargetLightID");
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        $value = $Data[0];

        // 1. Bewegungsmelder hat sich geändert
        if ($SenderID == $motionID) {
            $this->SetValue("Motion", $value);
            if ($value === true) {
                $this->CheckLogic();
            }
        }

        // 2. Licht wurde extern geschaltet
        if ($SenderID == $lightID) {
            $this->SetValue("Status", $value);
        }

        // 3. Helligkeit hat sich geändert
        if ($SenderID == $luxID) {
            $this->SetValue("Brightness", $value);
        }
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case "Status":
                $this->SwitchLight($Value);
                break;
            case "Mode":
                $this->SetValue("Mode", $Value);
                if ($Value == self::MODE_ALWAYS_ON) {
                    $this->SwitchLight(true);
                    $this->SetTimerInterval("AutoOffTimer", 0);
                } elseif ($Value == self::MODE_ALWAYS_OFF) {
                    $this->SwitchLight(false);
                    $this->SetTimerInterval("AutoOffTimer", 0);
                }
                break;
        }
    }

    private function CheckLogic() {
        $mode = $this->GetValue("Mode");
        
        if ($mode == self::MODE_ALWAYS_OFF) {
            return;
        }

        if ($mode == self::MODE_ALWAYS_ON) {
            $this->SwitchLight(true);
            $this->SetTimerInterval("AutoOffTimer", 0);
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
}
?>