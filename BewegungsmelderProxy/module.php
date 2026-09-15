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
    const MAX_SWITCH_ATTEMPTS = 3;
    const DISPATCH_INTERVAL = 100; // ms; keine 1-ms-Schleife bei wartendem I/O.

    // Deckel für den AutoCycleActive-Nachtrigger (siehe CheckLogic/IsFarTooBright):
    // "hell wegen uns" gilt nur bis zu diesem Vielfachen der Schaltschwelle. Darüber
    // ist es zu hell, um noch plausibel von der eigenen Lampe zu stammen (z.B. echtes
    // Tageslicht bei Dauerpräsenz). Gilt für CheckLogic; echte Präsenz verlängert
    // einen bereits laufenden Nachlauf weiterhin unabhängig von der Helligkeit.
    const NACHTRIGGER_CAP_FACTOR = 1.5;

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
        $this->RegisterAttributeInteger("SwitchSequence", 0);

        // Merker, ob die Automatik im laufenden Nachlauf-Zyklus eingeschaltet hat.
        // Nur dann darf die Helligkeitsprüfung übersprungen werden (siehe CheckLogic).
        $this->RegisterAttributeBoolean("AutoCycleActive", false);

        // Zuletzt aus der Konsole übernommener Schwellenwert. Dient in ApplyChanges dazu,
        // eine Änderung in der Instanz-Konfiguration von einem unveränderten Übernehmen
        // zu unterscheiden. -1 bedeutet "noch nie übernommen".
        $this->RegisterAttributeInteger("LastAppliedThreshold", -1);

        // 3. Profil erstellen
        if (!IPS_VariableProfileExists("BWM.Mode")) {
            IPS_CreateVariableProfile("BWM.Mode", 1);
            IPS_SetVariableProfileAssociation("BWM.Mode", 0, "Auto (Lux)", "Motion", -1);
            IPS_SetVariableProfileAssociation("BWM.Mode", 1, "Dauer Ein", "Light", 0x00FF00);
            IPS_SetVariableProfileAssociation("BWM.Mode", 2, "Dauer Aus", "Sleep", 0xFF0000);
            IPS_SetVariableProfileAssociation("BWM.Mode", 3, "Auto (Tag+Nacht)", "Sun", 0xFFFF00);
            IPS_SetVariableProfileIcon("BWM.Mode", "Gear");
        }

        // Profil für die Schaltschwelle, damit das Webfront ein sinnvolles Eingabefeld
        // mit Einheit und Untergrenze anbietet.
        if (!IPS_VariableProfileExists("BWM.Lux")) {
            IPS_CreateVariableProfile("BWM.Lux", 1);
            IPS_SetVariableProfileText("BWM.Lux", "", " Lux");
            IPS_SetVariableProfileValues("BWM.Lux", 0, 100000, 1);
            IPS_SetVariableProfileIcon("BWM.Lux", "Sun");
        }

        // 4. Status-Variablen registrieren
        $this->RegisterVariableBoolean("Status", "Licht Status", "~Switch", 10);
        $this->RegisterVariableBoolean("Motion", "Bewegung", "~Motion", 20);
        $this->RegisterVariableInteger("Brightness", "Helligkeit", "~Illumination", 30);
        $this->RegisterVariableInteger("ThresholdVar", "Schaltschwelle", "BWM.Lux", 35);
        $this->RegisterVariableString("SwitchError", "Schaltfehler", "", 40);
        $this->EnableAction("ThresholdVar");
        
        $this->RegisterVariableInteger("Mode", "Modus", "BWM.Mode", 0);
        $this->EnableAction("Mode");

        // 5. Aktionen aktivieren
        $this->EnableAction("Status");
        $this->EnableAction("Mode");

        // 6. Timer registrieren
        $this->RegisterTimer("AutoOffTimer", 0, 'BWMProxy_TimerEvent($_IPS[\'TARGET\']);');
        $this->RegisterTimer("VerifyTimer", 0, 'BWMProxy_VerifySwitch($_IPS[\'TARGET\']);');
        $this->RegisterTimer("DispatchTimer", 0, 'BWMProxy_DispatchSwitch($_IPS[\'TARGET\']);');
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
        
        // Schaltschwelle: zur Laufzeit ist die Variable die einzige Wahrheit, die Property
        // liefert nur den Startwert. Die Variable wird deshalb nur dann überschrieben,
        // wenn sich der Wert in der Instanz-Konfiguration tatsächlich geändert hat -
        // sonst würde jedes Übernehmen eine im Webfront gesetzte Schwelle verwerfen.
        $thresholdProp = $this->ReadPropertyInteger("Threshold");
        if ($thresholdProp !== $this->ReadAttributeInteger("LastAppliedThreshold")) {
            $this->SendDebug("Threshold", "Konsolenwert geändert -> Schaltschwelle auf $thresholdProp gesetzt", 0);
            $this->SetValue("ThresholdVar", $thresholdProp);
            $this->WriteAttributeInteger("LastAppliedThreshold", $thresholdProp);
        }


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

        $this->WithStateLock(function () use ($lightID) {
            $actualState = $lightID > 0 && IPS_VariableExists($lightID)
                ? GetValueBoolean($lightID) : false;
            $this->SetValue("Status", $actualState);
            $this->SyncMotion();
            $this->WriteAttributeBoolean("AutoCycleActive", false);
            $this->ClearPendingSwitch();
            $this->StopAutoOff();
            if ($actualState && $this->IsAutomatic()) {
                $this->StartAutoOff();
            }
        });

        // Helper Scripte (An/Aus) anlegen oder aktualisieren
        $this->CreateHelperScripts();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != VM_UPDATE || !isset($Data[0])) return;
        $this->WithStateLock(function () use ($TimeStamp, $SenderID, $Message, $Data) {
            $this->HandleMessage($TimeStamp, $SenderID, $Message, $Data);
        });
    }

    private function HandleMessage($TimeStamp, $SenderID, $Message, $Data) {
        
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
            // Aktuellen Variablenwert lesen: eine verzögert abgearbeitete Nachricht
            // darf einen neueren Gerätezustand nicht durch ihren alten Payload ersetzen.
            $actual = GetValueBoolean($lightID);
            $pending = $this->PendingSwitch();
            if ($pending !== null) {
                // Erst nach abgeschlossenem Sendeaufruf quittieren. Sonst könnte ein
                // altes Echo einen noch wartenden Gegenbefehl vorzeitig löschen.
                if ($pending['phase'] === 'sent' && $actual === $pending['state']) {
                    $this->ClearPendingSwitch();
                    $this->SetValue("SwitchError", "");
                    $this->SetValue("Status", $actual);
                }
            } else {
                $this->SetValue("Status", $actual);
                if ($actual && $this->IsAutomatic() && $this->AutoOffDeadline() <= 0) {
                    // Auch direkt am Aktor eingeschaltetes Licht bekommt einen Nachlauf.
                    $this->StartAutoOff();
                }
            }
        } elseif ($SenderID == $luxID || $SenderID == $extDarkID || $isLocalBrightnessSender) {
             if ($SenderID == $luxID) {
                $this->SetValue("Brightness", $value);
             }
             
             // Race Condition Fix:
             // Falls Hardware erst Bewegung meldet (noch zu hell) und millisekunden später den neuen Helligkeitswert,
             // müssen wir hier nach-prüfen, sofern Bewegung noch aktiv ist.
             if ($this->SyncMotion()) {
                 $this->SendDebug("Logic", "Brightness/Darkness update while Motion is active -> Re-evaluating Logic", 0);
                 $recheckID = $isLocalBrightnessSender ? $linkedMotionID : 0;
                 $this->CheckLogic($recheckID);
             }
        }
    }

    public function RequestAction($Ident, $Value) {
        $this->WithStateLock(function () use ($Ident, $Value) {
            $this->HandleAction($Ident, $Value);
        });
    }

    private function HandleAction($Ident, $Value) {
        switch ($Ident) {
            case "Status":
                $Value = (bool)$Value;
                // Eine explizite Ein-/Aus-Bedienung verlässt einen widersprechenden
                // Dauer-Modus. Sonst bliebe z.B. manuell EIN in "Dauer Aus" ohne Ende.
                $mode = $this->GetValue("Mode");
                if (($Value && $mode == self::MODE_ALWAYS_OFF) ||
                    (!$Value && $mode == self::MODE_ALWAYS_ON)) {
                    $saved = $this->ReadAttributeInteger("SavedMode");
                    $this->SetValue("Mode", in_array($saved, [self::MODE_AUTO_LUX, self::MODE_AUTO_NOLUX], true)
                        ? $saved : self::MODE_AUTO_LUX);
                }
                $this->SwitchLight($Value);
                if ($Value && $this->IsAutomatic()) {
                    $this->StartAutoOff();
                } else {
                    $this->StopAutoOff();
                }
                break;
            case "Mode":
                $this->ChangeMode($Value);
                break;
            case "ThresholdVar":
                // Bewusst kein IPS_SetProperty: das wuerde die Instanz als "geaendert"
                // markieren, und ein IPS_ApplyChanges zum Uebernehmen wuerde die Instanz
                // neu laden und dabei den laufenden AutoOffTimer verwerfen.
                $this->SendDebug("Threshold", "Schaltschwelle über Webfront auf $Value gesetzt", 0);
                $this->SetValue("ThresholdVar", $Value);
                break;
        }
    }

    /**
     * Öffentliche Funktion: Kann von Scripten direkt aufgerufen werden.
     * Befehl: BWMProxy_SetLight(InstanceID, true|false);
     */
    public function SetLight(bool $State) {
        $this->RequestAction('Status', $State);
    }

    private function ChangeMode($newMode) {
        if (!in_array($newMode, [0, 1, 2, 3], true)) {
            throw new InvalidArgumentException("Ungültiger Bewegungsmelder-Modus");
        }
        $this->SetValue("Mode", $newMode);
        if ($this->IsAutomatic()) $this->WriteAttributeInteger("SavedMode", $newMode);
        $this->StopAutoOff();
        $motion = $this->SyncMotion();
        if ($newMode == self::MODE_ALWAYS_ON) {
            $this->SwitchLight(true);
        } elseif ($newMode == self::MODE_ALWAYS_OFF) {
            $this->SwitchLight(false);
        } elseif (!$motion) {
            $this->SwitchLight(false, true);
        } else {
            $target = $this->ReadPropertyInteger("TargetLightID");
            $pending = $this->PendingSwitch();
            if ($this->GetValue("Status") ||
                ($target > 0 && IPS_VariableExists($target) && GetValueBoolean($target)) ||
                ($pending !== null && $pending['state'])) {
                // Eintritt in Auto: ein brennendes Licht braucht auch bei Helligkeit
                // einen definierten Nachlauf und ggf. einen Gegenbefehl zu altem AUS.
                $this->SwitchLight(true);
                $this->StartAutoOff();
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
            //
            // Deckel: "hell wegen uns" gilt nur bis NACHTRIGGER_CAP_FACTOR * Schaltschwelle.
            // Betrifft NUR das (Wieder-)Einschalten aus dem Aus-Zustand heraus, wenn
            // AutoCycleActive fälschlich noch true ist (z.B. nach einem Reload, siehe
            // Sicherheitsnetz in ApplyChanges). Ein bereits laufender Zyklus mit echter
            // Dauerpräsenz wird davon NICHT beendet - das übernimmt weiterhin ausschließlich
            // TimerEvent anhand von Bewegung, bewusst ohne Helligkeitsprüfung.
            if ($this->IsDarkEnough($triggerSensorID) ||
                ($this->ReadAttributeBoolean("AutoCycleActive") && !$this->IsFarTooBright($triggerSensorID))) {
                $shouldSwitch = true;
            }
        }

        if ($shouldSwitch) {
            $this->SwitchLight(true);
            $this->StartAutoOff();
        } else {
            $this->SendDebug("CheckLogic", "Conditions not met. No switch/extension.", 0);
        }
    }

    private function IsDarkEnough($triggerSensorID = 0) {
        $this->SendDebug("IsDarkEnough", "Checking if it's dark enough. Trigger: $triggerSensorID", 0);
        
        // Schaltschwelle kommt ausschliesslich aus der Variable. Kein Fallback auf die
        // Property: sonst waere 0 ("nur bei absoluter Dunkelheit schalten") nicht
        // einstellbar, weil es als "nicht gesetzt" gelesen wuerde.
        $threshold = $this->GetValue("ThresholdVar");
        
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

    /**
     * Deckelt den AutoCycleActive-Nachtrigger (siehe CheckLogic und TimerEvent):
     * "hell wegen uns" darf echtes Tageslicht nicht dauerhaft überstimmen. Liefert true,
     * wenn eine gemessene Helligkeit klar über der Schaltschwelle liegt (Vielfaches
     * NACHTRIGGER_CAP_FACTOR) oder eine externe Dunkelheits-Quelle explizit "hell" meldet.
     * Ohne Quelle konservativ false (kein zusätzlicher Deckel) - bestehende Konfigurationen
     * ohne Helligkeitsquelle verhalten sich dadurch unverändert.
     */
    private function IsFarTooBright($triggerSensorID = 0) {
        $threshold = $this->GetValue("ThresholdVar");
        $cap = $threshold * self::NACHTRIGGER_CAP_FACTOR;

        // Zonenspezifische Helligkeit hat Vorrang, analog zu IsDarkEnough.
        if ($triggerSensorID > 0) {
            $motionSensors = json_decode($this->ReadPropertyString("MotionSensors"), true);
            if (is_array($motionSensors)) {
                foreach ($motionSensors as $sensor) {
                    if ($sensor['VariableID'] == $triggerSensorID) {
                        if (isset($sensor['BrightnessVariableID']) && $sensor['BrightnessVariableID'] > 0) {
                            $bID = $sensor['BrightnessVariableID'];
                            if (IPS_VariableExists($bID)) {
                                $lux = GetValue($bID);
                                $tooBright = ($lux > $cap);
                                $this->SendDebug("IsFarTooBright", "Zone ($triggerSensorID) Brightness ($bID): $lux > Cap ($cap) ? " . ($tooBright ? "YES" : "NO"), 0);
                                return $tooBright;
                            }
                        }
                        break;
                    }
                }
            }
        }

        // Externe Dunkelheits-Quelle: ein explizites "hell" ist ein staerkeres Signal als
        // jeder Lux-Vergleich und deckelt ebenfalls.
        $extDarkID = $this->ReadPropertyInteger("SourceIsDarkID");
        if ($extDarkID > 0 && IPS_VariableExists($extDarkID)) {
            $val = GetValueBoolean($extDarkID);
            if ($this->ReadPropertyBoolean("InvertIsDark")) {
                $val = !$val;
            }
            $this->SendDebug("IsFarTooBright", "External Var ($extDarkID) says: " . ($val ? "Dark" : "Bright") . " -> tooBright=" . ($val ? "NO" : "YES"), 0);
            return !$val; // $val === true bedeutet dunkel
        }

        $luxID = $this->ReadPropertyInteger("SourceBrightnessID");
        if ($luxID > 0 && IPS_VariableExists($luxID)) {
            $lux = GetValue($luxID);
            $tooBright = ($lux > $cap);
            $this->SendDebug("IsFarTooBright", "Lux: $lux > Cap: $cap ? " . ($tooBright ? "YES" : "NO"), 0);
            return $tooBright;
        }

        // Keine Quelle -> kein zusaetzlicher Deckel.
        return false;
    }

    // Alle Zustandsentscheidungen laufen unter derselben kurzen Sperre. Keine
    // Geräteaufrufe darunter: deren synchrone Rückmeldungen nutzen MessageSink.
    private function WithStateLock(callable $work) {
        $key = 'BWMProxy.State.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 5000)) {
            throw new RuntimeException("BewegungsmelderProxy: Zustandssperre belegt");
        }
        try { return $work(); } finally { IPS_SemaphoreLeave($key); }
    }

    protected function Now() { return microtime(true); }

    private function IsAutomatic() {
        return in_array($this->GetValue("Mode"), [self::MODE_AUTO_LUX, self::MODE_AUTO_NOLUX], true);
    }

    private function SyncMotion() {
        $motion = $this->GetMotionState();
        $this->SetValue("Motion", $motion);
        return $motion;
    }

    private function AutoOffDeadline() {
        return (float)$this->GetBuffer("AutoOffDeadline");
    }

    private function StartAutoOff() {
        // 0 darf keinen eingeschalteten Automatik-Zyklus ohne Timer erzeugen.
        $seconds = max(1, $this->ReadPropertyInteger("Duration"));
        $this->WriteAttributeBoolean("AutoCycleActive", true);
        $this->SetBuffer("AutoOffDeadline", (string)($this->Now() + $seconds));
        $this->SetTimerInterval("AutoOffTimer", $seconds * 1000);
    }

    private function StopAutoOff() {
        $this->WriteAttributeBoolean("AutoCycleActive", false);
        $this->SetBuffer("AutoOffDeadline", "");
        $this->SetTimerInterval("AutoOffTimer", 0);
    }

    private function PendingSwitch() {
        $pending = json_decode($this->GetBuffer("PendingSwitch"), true);
        return is_array($pending) ? $pending : null;
    }

    private function ClearPendingSwitch() {
        $this->SetBuffer("PendingSwitch", "");
        $this->SetTimerInterval("VerifyTimer", 0);
        $this->SetTimerInterval("DispatchTimer", 0);
    }

    private function SwitchLight($state, $automaticOff = false) {
        $state = (bool)$state;
        $target = $this->ReadPropertyInteger("TargetLightID");
        if ($target <= 0 || !IPS_VariableExists($target)) {
            $this->ClearPendingSwitch();
            $this->SetValue("SwitchError", "Zielvariable fehlt: " . $target);
            return;
        }
        $pending = $this->PendingSwitch();
        if ($pending !== null && $pending['state'] === $state && $pending['target'] === $target) {
            // Bewegung darf eine ausstehende Quittierung nicht immer neu terminieren.
            // Explizites AUS ist stärker als ein automatischer Ausschaltversuch.
            $pending['automaticOff'] = $pending['automaticOff'] && $automaticOff;
            $this->SetBuffer("PendingSwitch", json_encode($pending));
        } elseif ($pending !== null || GetValueBoolean($target) !== $state) {
            $sequence = $this->ReadAttributeInteger("SwitchSequence") + 1;
            $this->WriteAttributeInteger("SwitchSequence", $sequence);
            $this->SetBuffer("PendingSwitch", json_encode([
                'id' => $sequence, 'target' => $target, 'state' => $state,
                'automaticOff' => $automaticOff, 'attempts' => 0,
                'phase' => 'queued', 'ts' => $this->Now()
            ]));
            $this->SetTimerInterval("VerifyTimer", 0);
            $this->SetTimerInterval("DispatchTimer", self::DISPATCH_INTERVAL);
        } else {
            $this->SetValue("SwitchError", "");
        }
        $this->SetValue("Status", $state);
    }

    // Eigener Worker: serialisierte Gerätebefehle, aber kein State-Lock während I/O.
    public function DispatchSwitch() {
        $ioKey = 'BWMProxy.IO.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($ioKey, 0)) return;
        try {
            $command = $this->WithStateLock(function () {
                $pending = $this->PendingSwitch();
                if ($pending === null || $pending['phase'] !== 'queued') {
                    $this->SetTimerInterval("DispatchTimer", 0);
                    return null;
                }
                if ($pending['automaticOff'] && $this->IsAutomatic() && $this->SyncMotion()) {
                    $this->SwitchLight(true);
                    $this->StartAutoOff();
                    return null;
                }
                $pending['phase'] = 'sending';
                $pending['attempts']++;
                $this->SetBuffer("PendingSwitch", json_encode($pending));
                // Falls ein neuer Wunsch während I/O eintrifft, bleibt dessen Timer
                // aktiv. Ein wiederholter Worker darf ihn nicht abschalten.
                $this->SetTimerInterval("DispatchTimer", 0);
                return $pending;
            });
            if ($command === null) return;
            $failure = null;
            try {
                if (!IPS_VariableExists($command['target'])) {
                    throw new RuntimeException("Zielvariable wurde entfernt");
                }
                $result = RequestAction($command['target'], $command['state']);
                if ($result === false) $failure = "RequestAction meldet Fehler";
            } catch (Throwable $e) { $failure = $e->getMessage(); }
            $this->WithStateLock(function () use ($command, $failure) {
                $pending = $this->PendingSwitch();
                // Ein inzwischen neuer Befehl gehört nicht mehr zu diesem Worker.
                if ($pending === null || $pending['id'] !== $command['id']) return;
                $pending['phase'] = 'sent';
                $pending['ts'] = $this->Now();
                $this->SetBuffer("PendingSwitch", json_encode($pending));
                $this->SetTimerInterval("VerifyTimer", (int)(self::SWITCH_TIMEOUT * 1000));
                if ($failure !== null) $this->SetValue("SwitchError", $failure);
            });
        } finally { IPS_SemaphoreLeave($ioKey); }
    }

    public function TimerEvent() {
        $this->WithStateLock(function () {
            if (!$this->IsAutomatic()) { $this->StopAutoOff(); return; }
            $deadline = $this->AutoOffDeadline();
            if ($deadline <= 0) return; // Bereits beendeter/abgebrochener Zyklus.
            $remaining = $deadline - $this->Now();
            if ($remaining > 0) {
                // Ein bereits eingeplanter alter Callback darf keinen neueren
                // Nachlauf vorzeitig beenden.
                $this->SetTimerInterval("AutoOffTimer", max(1, (int)ceil($remaining * 1000)));
                return;
            }
            if ($this->SyncMotion()) { $this->StartAutoOff(); return; }
            // Aufräumen erfolgt vor dem asynchronen Ausschalten und unter Sperre.
            $this->StopAutoOff();
            $this->SwitchLight(false, true);
        });
    }

    public function VerifySwitch() {
        $this->WithStateLock(function () {
            $pending = $this->PendingSwitch();
            if ($pending === null || $pending['phase'] !== 'sent') return;
            $remaining = self::SWITCH_TIMEOUT - ($this->Now() - $pending['ts']);
            if ($remaining > 0) {
                $this->SetTimerInterval("VerifyTimer", max(1, (int)ceil($remaining * 1000)));
                return;
            }
            $target = $pending['target'];
            if (!IPS_VariableExists($target)) {
                $this->ClearPendingSwitch();
                $this->SetValue("SwitchError", "Zielvariable fehlt: " . $target);
                return;
            }
            $actual = GetValueBoolean($target);
            $this->SetValue("Status", $actual);
            if ($actual === $pending['state']) {
                $this->ClearPendingSwitch();
                $this->SetValue("SwitchError", "");
                return;
            }
            if ($pending['automaticOff'] && $this->IsAutomatic() && $this->SyncMotion()) {
                $this->SwitchLight(true);
                $this->StartAutoOff();
                return;
            }
            if ($pending['attempts'] < self::MAX_SWITCH_ATTEMPTS) {
                $pending['phase'] = 'queued';
                $this->SetBuffer("PendingSwitch", json_encode($pending));
                $this->SetTimerInterval("VerifyTimer", 0);
                $this->SetTimerInterval("DispatchTimer", self::DISPATCH_INTERVAL);
            } else {
                $this->ClearPendingSwitch();
                $this->SetValue("SwitchError", "Licht " . $target . ": " .
                    ($pending['state'] ? "EIN" : "AUS") . " nach " .
                    self::MAX_SWITCH_ATTEMPTS . " Versuchen nicht bestätigt");
                if ($pending['state']) $this->StopAutoOff();
            }
        });
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
