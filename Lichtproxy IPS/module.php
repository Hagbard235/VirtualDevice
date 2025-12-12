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
        $this->RegisterPropertyInteger("SourceIsDarkID", 0); // Neu: Externe Boolean
        $this->RegisterPropertyInteger("Threshold", 120);
        $this->RegisterPropertyInteger("Duration", 300); // Standard 5 Minuten

        // 2. Profil erstellen (falls nicht vorhanden)
        if (!IPS_VariableProfileExists("BWM.Mode")) {
            IPS_CreateVariableProfile("BWM.Mode", 1); // 1 = Integer
            IPS_SetVariableProfileAssociation("BWM.Mode", 0, "Auto (Lux)", "Motion", -1);
            IPS_SetVariableProfileAssociation("BWM.Mode", 1, "Dauer Ein", "Light", 0x00FF00);
            IPS_SetVariableProfileAssociation("BWM.Mode", 2, "Dauer Aus", "Sleep", 0xFF0000);
            IPS_SetVariableProfileAssociation("BWM.Mode", 3, "Auto (Tag+Nacht)", "Sun", 0xFFFF00);
            IPS_SetVariableProfileIcon("BWM.Mode", "Gear");
        }

        // 3. Status-Variablen registrieren (Das Interface nach außen)
        $this->RegisterVariableBoolean("Status", "Licht Status", "~Switch", 10);
        $this->RegisterVariableBoolean("Motion", "Bewegung", "~Motion", 20);
        $this->RegisterVariableInteger("Brightness", "Helligkeit", "~Illumination", 30);
        $this->RegisterVariableInteger("Mode", "Modus", "BWM.Mode", 0);

        // 4. Aktionen aktivieren (WebFront/Bedienbarkeit)
        $this->EnableAction("Status");
        $this->EnableAction("Mode");

        // 5. Timer registrieren
        $this->RegisterTimer("AutoOffTimer", 0, 'BWM_TimerEvent($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Nachrichten abonnieren (Events ohne sichtbare Event-Objekte)
        $motionID = $this->ReadPropertyInteger("SourceMotionID");
        $lightID = $this->ReadPropertyInteger("TargetLightID");
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");

        // Alte Nachrichtenbindungen löschen ist in ApplyChanges implizit via Kernel meist sauber,
        // aber wir registrieren hier explizit neu.
        if ($motionID > 0) $this->RegisterMessage($motionID, VM_UPDATE);
        if ($lightID > 0) $this->RegisterMessage($lightID, VM_UPDATE);
        if ($luxID > 0) $this->RegisterMessage($luxID, VM_UPDATE);
        if ($extDarkID > 0) $this->RegisterMessage($extDarkID, VM_UPDATE);
    }

    // Hier kommen alle Events an
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        
        $motionID = $this->ReadPropertyInteger("SourceMotionID");
        $lightID = $this->ReadPropertyInteger("TargetLightID");
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");
        $value = $Data[0];

        // 1. Bewegungsmelder hat sich geändert
        if ($SenderID == $motionID) {
            $this->SetValue("Motion", $value); // Proxy updaten
            if ($value === true) {
                // Bewegung erkannt -> Logik prüfen
                $this->CheckLogic();
            }
        }

        // 2. Licht wurde extern geschaltet (Wandschalter) -> Sync Proxy
        if ($SenderID == $lightID) {
            $this->SetValue("Status", $value);
        }

        // 3. Helligkeit hat sich geändert -> Nur Anzeige updaten
        if ($SenderID == $luxID) {
            $this->SetValue("Brightness", $value);
        }
        
        // 4. Externe Dunkelheit hat sich geändert
        // Optional: Wenn es dunkel wird und gerade Bewegung ist, könnte man schalten.
        // Hier implementieren wir es so, dass es erst bei nächster Bewegung/Check greift.
    }

    // WebFront Bedienung
    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case "Status":
                $this->SwitchLight($Value);
                break;
            case "Mode":
                $this->SetValue("Mode", $Value);
                // Sofort-Reaktionen bei Moduswechsel
                if ($Value == self::MODE_ALWAYS_ON) {
                    $this->SwitchLight(true);
                    $this->SetTimerInterval("AutoOffTimer", 0); // Timer aus
                } elseif ($Value == self::MODE_ALWAYS_OFF) {
                    $this->SwitchLight(false);
                    $this->SetTimerInterval("AutoOffTimer", 0); // Timer aus
                }
                break;
        }
    }

    // Die Kern-Logik
    private function CheckLogic() {
        $mode = $this->GetValue("Mode");
        
        // Wenn Modus "Immer Aus" -> Abbruch
        if ($mode == self::MODE_ALWAYS_OFF) {
            return;
        }

        // Wenn Modus "Immer An" -> Timer stoppen (oder verlängern? Hier: Bleibt an)
        if ($mode == self::MODE_ALWAYS_ON) {
            $this->SwitchLight(true);
            $this->SetTimerInterval("AutoOffTimer", 0);
            return;
        }

        // Prüfen ob wir schalten müssen (Auto-Modi)
        $shouldSwitch = false;

        if ($mode == self::MODE_AUTO_NOLUX) {
            // Modus 3: Immer bei Bewegung, egal wie hell
            $shouldSwitch = true;
        } elseif ($mode == self::MODE_AUTO_LUX) {
            // Modus 0: Helligkeit prüfen
            if ($this->IsDarkEnough()) {
                $shouldSwitch = true;
            }
        }

        if ($shouldSwitch) {
            $this->SwitchLight(true);
            
            // Timer starten/verlängern
            $duration = $this->ReadPropertyInteger("Duration") * 1000;
            $this->SetTimerInterval("AutoOffTimer", $duration);
        }
    }

    // Hilfsfunktion: Ist es dunkel?
    private function IsDarkEnough() {
        // Priorität 1: Externe Variable prüfen
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");
        if ($extDarkID > 0 && IPS_VariableExists($extDarkID)) {
            // Wir gehen davon aus: TRUE = Dunkel, FALSE = Hell
            return GetValueBoolean($extDarkID);
        }

        // Priorität 2: Interne Variable vs Threshold
        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        if ($luxID > 0 && IPS_VariableExists($luxID)) {
            $lux = GetValue($luxID); // Kann Integer oder Float sein
            $threshold = $this->ReadPropertyInteger("Threshold");
            return ($lux <= $threshold);
        }

        // Fallback: Wenn nichts konfiguriert ist, nehmen wir an es ist dunkel (Fail-Safe)
        return true; 
    }

    // Hilfsfunktion: Licht Hardware schalten
    private function SwitchLight($state) {
        $targetID = $this->ReadPropertyInteger("TargetLightID");
        if ($targetID > 0 && IPS_VariableExists($targetID)) {
            // Proxy Variable sofort setzen (optimistisch)
            $this->SetValue("Status", $state);
            
            // Hardware schalten (Generisch)
            // RequestAction funktioniert für Variablen mit zugewiesener Aktion (fast alle Module)
            RequestAction($targetID, $state);
        }
    }

    // Timer Event: Licht ausschalten
    public function TimerEvent() {
        // Nur ausschalten, wenn wir im Auto-Modus sind
        $mode = $this->GetValue("Mode");
        if ($mode == self::MODE_AUTO_LUX || $mode == self::MODE_AUTO_NOLUX) {
            
            // Check: Ist noch Bewegung?
            // (Optional: Manche wollen, dass das Licht an bleibt, solange Bewegung ist,
            // auch wenn der Timer abläuft. HomeMatic sendet aber zyklisch Motion-Updates.
            // Daher reicht es oft, den Timer bei jedem "Motion=TRUE" Event neu aufzuziehen,
            // was wir in CheckLogic() tun.)
            
            $this->SwitchLight(false);
        }
        
        // Timer deaktivieren
        $this->SetTimerInterval("AutoOffTimer", 0);
    }
}
?>