<?

// Klassendefinition
class VirtualLicht extends IPSModule
{
    
    
    // Der Konstruktor des Moduls
    // Überschreibt den Standard Kontruktor von IPS
    public function __construct($InstanceID)
    {
        // Diese Zeile nicht löschen
        parent::__construct($InstanceID);
        
        // Selbsterstellter Code
    }
    
    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();
        
        $this->RegisterPropertyInteger("PropertyInstanceID", 0);
        /* $this->RegisterPropertyInteger("PropertyInstanceIDgelesen", -1); */
        $this->RegisterPropertyInteger("TimeOut", 5);
        $this->RegisterPropertyBoolean("Dimmbar", false);
		
        
        $this->RegisterPropertyInteger("ToggleScriptID", 0);
        $doThis = 'VIR_LichtUmschalten($_IPS[\'TARGET\']);';
        $this->RegisterTimer("Umschalten", 0, $doThis);
        $doThis = 'VIR_LichtAnschalten($_IPS[\'TARGET\']);';
        $this->RegisterTimer("Anschalten", 0, $doThis);
        $doThis = 'VIR_LichtAusschalten($_IPS[\'TARGET\']);';
        $this->RegisterTimer("Ausschalten", 0, $doThis);
        
        //Variablenprofil anlegen ($name, $ProfileType, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits, $Icon)
        $profilename = "VIR.Licht";
		if (IPS_VariableProfileExists($profilename)) {
			IPS_DeleteVariableProfile ($profilename ) ;
		}
        if (!IPS_VariableProfileExists($profilename)) {
            IPS_CreateVariableProfile($profilename, 0);
            IPS_SetVariableProfileIcon($profilename, "Bulb");
            IPS_SetVariableProfileAssociation($profilename, true, "An", "", 0xEA1A07);
            IPS_SetVariableProfileAssociation($profilename, false, "Aus", "", 0x62F442);
            //IPS_SetVariableProfileAssociation($profilename, 1, "gedimmt", "", 0xFFFF00);
            
        }
		 $profilename = "VIR.Dimmer";
        if (IPS_VariableProfileExists($profilename)) {
			IPS_DeleteVariableProfile ($profilename ) ;
		}
		if (!IPS_VariableProfileExists($profilename)) {
            IPS_CreateVariableProfile($profilename, 1);
            IPS_SetVariableProfileIcon($profilename, "Intensity");
			IPS_SetVariableProfileValues ($profilename, 0, 100, 1 );
			IPS_SetVariableProfileText ($profilename, "", " %");
            
        }
        $proberty_name = "Status";
        $varID         = @$this->GetIDForIdent($proberty_name);
        if (IPS_VariableExists($varID)) {
            
            
            
        } else {
            $VarID_NEU = $this->RegisterVariableBoolean($proberty_name, "Status", "VIR.Licht", 0);
            $this->EnableAction($proberty_name);
            
            
            
        }
  // Beispiel für das 'endlos'-Applychanges
        $this->SetBuffer('Apply', '0');
    
		
        
        
        
    }
    
    public function RequestAction($Ident, $Value)
    {
		if ($Ident == "Dimmen") {
			$this->LichtDimmen($Value);
			
		}
        //Hier auf klick reagieren
		if ($Ident == "Status") {
			
			if ($Value) {
				$this->LichtAnschalten();
			} else {
				$this->LichtAusschalten();
			}
        }
    }
    
    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        
        parent::ApplyChanges();
        //Instanz ist aktiv
        $apply = $this->GetBuffer('Apply');
		if ($apply == 0 ) {
			$this->SetStatus(102);
			
			if ($this->ReadPropertyInteger("PropertyInstanceID") != 0) {
				
				//Prüfen ob Instanz alle notwendigen Elemente hat
				$O_ID = $this->ReadPropertyInteger("PropertyInstanceID");
				
				//Status-Variable
				$hw_statusvar = @IPS_GetObjectIDByName("Status", $O_ID);
				if ($hw_statusvar == false) {
					$this->SetStatus(201);
					echo ("Status-Variable nicht gefunden");
					IPS_LogMessage($this->InstanceID, "Variable Status nicht gefunden");
				}
				//An-Script
				$hw_an = @IPS_GetObjectIDByName("An", $O_ID);
				if ($hw_an == false) {
					$this->SetStatus(202);
					echo ("An-Script nicht gefunden");
					IPS_LogMessage($this->InstanceID, "Script An nicht gefunden");
				}
				//Aus-Script
				$hw_aus = @IPS_GetObjectIDByName("Aus", $O_ID);
				if ($hw_aus == false) {
					$this->SetStatus(203);
					echo ("Aus-Script nicht gefunden");
					IPS_LogMessage($this->InstanceID, "Script Aus nicht gefunden");
				}
				//An_Force-Script
				$hw_an_force = @IPS_GetObjectIDByName("An_Force", $O_ID);
				if ($hw_an_force == false) {
					$this->SetStatus(204);
					echo ("An_Force-Script nicht gefunden");
					IPS_LogMessage($this->InstanceID, "Script An_Force nicht gefunden");
				}
				//Aus_Force-Script
				$hw_aus_force = @IPS_GetObjectIDByName("Aus_Force", $O_ID);
				if ($hw_aus_force == false) {
					$this->SetStatus(205);
					echo ("Aus_Force-Script nicht gefunden");
					IPS_LogMessage($this->InstanceID, "Script Aus_Force nicht gefunden");
				}
				//Toggle-Script
				$hw_toggle = @IPS_GetObjectIDByName("Toggle", $O_ID);
				if ($hw_toggle == false) {
					$this->SetStatus(206);
					echo ("Toggle-Script nicht gefunden");
					IPS_LogMessage($this->InstanceID, "Script Toggle nicht gefunden");
				}
				/* Instanz eingelesen?
				// $O2_ID = $this->ReadPropertyInteger("PropertyInstanceIDgelesen");
				// if ($O_ID != $O2_ID) {
					// $this->SetStatus(207);
				   echo ("Instanz nicht eingelesen");
					// IPS_LogMessage($this->InstanceID, "Instanz muss erst eingelesen werden");
				// } */


				//Dimm-Script
				$hw_dimm = @IPS_GetObjectIDByName("Dimmen", $O_ID);
				if ($hw_dimm != false) {
					
					$varID = @$this->GetIDForIdent("Dimmen");
					if (IPS_VariableExists($varID)) {
						
						
						
					} else {
						$VarID_NEU = $this->RegisterVariableInteger("Dimmen", "Dimmer", "VIR.Dimmer", 0);
						$this->EnableAction("Dimmen");
					}
					
				}
				
				//Altes Event löschen vor Neuanlage
				$alt_event = @IPS_GetObjectIDByName("aktualisieren", $this->InstanceID);
				if ($alt_event > 0) {
					IPS_DeleteEvent($alt_event);
				}
				
				$alt_eventd = @IPS_GetObjectIDByName("aktdimmer", $this->InstanceID);
				if ($alt_eventd > 0) {
					IPS_DeleteEvent($alt_eventd);
				}
				
				//Neues Event anlegen
				$O_ID         = $this->ReadPropertyInteger("PropertyInstanceID");
				$hw_statusvar = @IPS_GetObjectIDByName("Status", $O_ID);
				$eid          = IPS_CreateEvent(0); //Ausgelöstes Ereignis
				IPS_SetEventTrigger($eid, 1, $hw_statusvar); //Bei Änderung von Variable mit ID 15754
				IPS_SetEventScript($eid, "VIR_Statusaktualisieren($this->InstanceID);");
				IPS_SetParent($eid, $this->InstanceID); //Ereignis zuordnen
				IPS_SetEventActive($eid, true); //Ereignis aktivieren
				IPS_SetName($eid, "aktualisieren");

				$O_ID = $this->ReadPropertyInteger("PropertyInstanceID");
					if ($O_ID != 0) {
					 /* IPS_SetProperty($this->InstanceID, 'PropertyInstanceIDgelesen', $O_ID); */
						$hw_dimm = @IPS_GetObjectIDByName("Dimmen", $O_ID);
						if ($hw_dimm == false) {
						IPS_SetProperty($this->InstanceID, 'Dimmbar', false);	
						} else {
						IPS_SetProperty($this->InstanceID, 'Dimmbar', true);	
						}
					 $this->SetBuffer('Apply', 1);
					 
					 
				}
				
				 //Neues Eventfür Dimmer anlegen
				if (@IPS_VariableExists(IPS_GetObjectIDByName("Intensity", $O_ID))) {
					$O_ID         = $this->ReadPropertyInteger("PropertyInstanceID");
					$hw_dimvar = @IPS_GetObjectIDByName("Intensity", $O_ID);
					$eid          = IPS_CreateEvent(0); //Ausgelöstes Ereignis
					IPS_SetEventTrigger($eid, 1, $hw_dimvar); //Bei Änderung von Variable mit ID 15754
					IPS_SetEventScript($eid, "VIR_Statusaktualisieren($this->InstanceID);");
					IPS_SetParent($eid, $this->InstanceID); //Ereignis zuordnen
					IPS_SetEventActive($eid, true); //Ereignis aktivieren
					IPS_SetName($eid, "aktdimmer");
				}

				$dimmvalue = $this->GetIDForIdent("Dimmen");
				if (!$hw_dimm==false ){
				IPS_SetHidden($dimmvalue, false);

				} else {
				
				IPS_SetHidden($dimmvalue, true);
				
				}
			}
			IPS_ApplyChanges($this->InstanceID);

        } else {
			//tue nix, weil er hier nur durch läuft wegen dem unnötigen ApplyChanges
			// nur Puffer zurücksetzen
			$this->SetBuffer('Apply',0);
			}
        
        //IPS_ApplyChanges($this->InstanceID); //Neue Konfiguration übernehmen
        
    }
    
    /**
     * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
     * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:
     *
     * ABC_MeineErsteEigeneFunktion($id);
     *
     */
	public function leseGeraet() {
    
		$hw_dimm = @IPS_GetObjectIDByName("Dimmen", $O_ID);
		
		IPS_SetProperty($this->InstanceID, 'Dimmbar', !$this->ReadPropertyBoolean("Dimmbar"));	
		
	 
	 IPS_ApplyChanges($this->InstanceID);
	

	}
    public function Statusaktualisieren()
    {
        $O_ID         = $this->ReadPropertyInteger("PropertyInstanceID");
        $hw_statusvar = @IPS_GetObjectIDByName("Status", $O_ID);
        $statusvar    = $this->GetIDForIdent("Status");
        SetValueBoolean($statusvar, GetValueBoolean($hw_statusvar));
        $dimmvar    = @$this->GetIDForIdent("Dimmen");
		if ($dimmvar) {
			$hw_dimmvar = @IPS_GetObjectIDByName("Intensity", $O_ID);
			SetValueInteger($dimmvar, GetValueInteger($hw_dimmvar));
		}
        
    }
     public function LichtDimmen($Wert)
    {
        $O_ID         = $this->ReadPropertyInteger("PropertyInstanceID");
        $hw_statusvar = @IPS_GetObjectIDByName("Dimmen", $O_ID);
		IPS_RunScriptEx($hw_statusvar,Array(
				"Ziel" => $Wert));
        
        
    }
    public function LichtUmschalten()
    {
		$this->SetTimerInterval("Anschalten", 0);
		$this->SetTimerInterval("Ausschalten", 0);
		$this->SetTimerInterval("Umschalten", 0);
		
        if (($_IPS['SENDER'] == 'WebFront') or ($_IPS['SENDER'] == "AlexaSmartHome")) {
            if ($_IPS['VALUE'] === true) {
                LichtAnschalten();
            }
            
            else {
                LichtAusschalten();
            }
        } else {
            if ($_IPS['SENDER'] == "TimerEvent") {
                
                // Timer ausschalten
                
                $this->SetTimerInterval("Umschalten", 0);
                // Vergleichen und ggf. Fehlermeldung oder nochmal versuchen
                
                $statusvar = $this->GetIDForIdent("Status");
                if (IPS_VariableExists($statusvar)) {
                    
                    
                    
                } else {
                    $VarID_NEU = $this->RegisterVariableBoolean("Status", "Status");
                    
                    
                }
                $O_ID         = $this->ReadPropertyInteger("PropertyInstanceID");
                $hw_statusvar = @IPS_GetObjectIDByName("Status", $O_ID);
                if ($hw_statusvar === false) {
                    //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable_Hardware nicht gefunden', '', 0);
                    //    return false;
                }
                
                if (GetValueBoolean($hw_statusvar) != GetValueBoolean($statusvar)) {
                    
                    // Differenz entedeckt!
                    ////TODO anderen Variablen-Typ wählen
                    
                    $CountVar = @$this->GetIDForIdent("Count");
                    
                    if ($CountVar === false) {
                        
                        // 1. Versuch
                        
                        //    sendDBMessage('ERROR', 'V1-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet', '', 0);
                        IPS_LogMessage($_IPS['SELF'], "DEBUG:Schalten im 1. Versuch nicht erfolgreich");
                        
                        $VarID_Count = $this->RegisterVariableBoolean("Count", "Count");
                        
                        SetValueBoolean($VarID_Count, false);
                        IPS_SetHidden($VarID_Count, true);
                        
                        // 2. Versuch starten
                        
                        $Scriptname = "Toggle";
                        $O_ID       = $this->ReadPropertyInteger("PropertyInstanceID");
                        $scriptAn   = @IPS_GetObjectIDByName($Scriptname, $O_ID);
                        $statusvar  = $this->GetIDForIdent("Status");
                        if ($statusvar === false) {
                            //        sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
                            //        return false;
                        }
                        
                        if (IPS_ScriptExists($scriptAn)) {
                            @IPS_RunScript($scriptAn);
                            SetValueBoolean($statusvar, !GetValueBoolean($statusvar));
                        } else {
                            //        sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
                        }
                        
                        // Neuen Timer aufziehen für 2. Versuch
                        $timeout = $this->ReadPropertyInteger("TimeOut") * 2 * 1000;
                        $this->SetTimerInterval("Umschalten", $timeout);
                    } else {
                        
                        // 2. Durchlauf und immer noch nich geschaltet
                        
                        //    sendDBMessage('ERROR', 'V2-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet nach Wiederholung', '', 0);
                        
                        // IPS_DeleteVariable($CountVar);
                        // Keinen neuen Timer, hier Ende
                        
                    }
                } else {
                    
                    // Keine Differenz (mehr) entdeckt, mögliche Variable löschen
                    
                    $CountVar = $this->GetIDForIdent("Count");
                    if ($CountVar === false) {
                        
                        // war gar nicht da, also nie Differenz gehabt
                        
                    } else {
                        UnregisterVariable("Count");
                    }
                }
            } else {
                $Scriptname = "Toggle";
                $O_ID       = $this->ReadPropertyInteger("PropertyInstanceID");
                $scriptAn   = @IPS_GetObjectIDByName($Scriptname, $O_ID);
                $statusvar  = $this->GetIDForIdent("Status");
                if ($statusvar === false) {
                    //        sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
                    //        return false;
                }
                
                if (IPS_ScriptExists($scriptAn)) {
                    @IPS_RunScript($scriptAn);
                    SetValueBoolean($statusvar, !GetValueBoolean($statusvar));
                } else {
                    //        sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
                }
                
                // Timer anschalten
                
                $timeout = $this->ReadPropertyInteger("TimeOut") * 1000;
                $this->SetTimerInterval("Umschalten", $timeout);
                //        return false;
            }
        }
        
        
        
    }
    public function LichtAnschalten()
    {
		$this->SetTimerInterval("Anschalten", 0);
		$this->SetTimerInterval("Ausschalten", 0);
		$this->SetTimerInterval("Umschalten", 0);
		
        if ($_IPS['SENDER'] == "TimerEvent") {
            
            // Timer ausschalten
            
            $this->SetTimerInterval("Anschalten", 0);
            
            
            // Vergleichen und ggf. Fehlermeldung oder nochmal versuchen
            
            $statusvar = $this->GetIDForIdent("Status");
            if ($statusvar === false) {
                //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
                //    return false;
            }
            
            $O_ID         = $this->ReadPropertyInteger("PropertyInstanceID");
            $hw_statusvar = @IPS_GetObjectIDByName("Status", $O_ID);
            if ($hw_statusvar === false) {
                //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable_Hardware nicht gefunden', '', 0);
                //    return false;
            }
            
            if (GetValueBoolean($hw_statusvar) != GetValueBoolean($statusvar)) {
                
                // Differenz entedeckt!
                
                $CountVar = @$this->GetIDForIdent("Count");
                if ($CountVar === false) {
                    
                    // 1. Versuch
                    
                    //sendDBMessage('ERROR', 'V1-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet', '', 0);
                    IPS_LogMessage($_IPS['SELF'], "DEBUG:Schalten im 1. Versuch nicht erfolgreich");
                    $VarID_Count = $this->RegisterVariableBoolean("Count", "Count");
                    SetValueBoolean($VarID_Count, false);
                    IPS_SetHidden($VarID_Count, true);
                    
                    // 2. Schaltversuch
                    
                    $Scriptname = "An_Force";
                    $O_ID       = $this->ReadPropertyInteger("PropertyInstanceID");
                    $scriptAn   = @IPS_GetObjectIDByName($Scriptname, $O_ID);
                    $statusvar  = $this->GetIDForIdent("Status");
                    if ($statusvar === false) {
                        //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
                        //    return false;
                    }
                    
                    if (IPS_ScriptExists($scriptAn)) {
                        @IPS_RunScript($scriptAn);
                        SetValueBoolean($statusvar, true);
                    } else {
                        //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
                    }
                    
                    // Neuen Timer aufziehen für 2. Versuch
                    $timeout = $this->ReadPropertyInteger("TimeOut") * 2 * 1000;
                    $this->SetTimerInterval("Anschalten", $timeout);
                    
                } else {
                    
                    // 2. Durchlauf und immer noch nich geschaltet
                    
                    //        sendDBMessage('ERROR', 'V2-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet nach Wiederholung', '', 0);
                    
                    // IPS_DeleteVariable($CountVar);
                    // Keinen neuen Timer, hier Ende
                    
                }
            } else {
                
                // Keine Differenz (mehr) entdeckt, mögliche Variable löschen
                
                $CountVar = $this->GetIDForIdent("Count");
                if ($CountVar === false) {
                    
                    // war gar nicht da, also nie Differenz gehabt
                    
                } else {
                    UnregisterVariable("Count");
                }
            }
        } else {
            $Scriptname = "An";
            $O_ID       = $this->ReadPropertyInteger("PropertyInstanceID");
            $scriptAn   = @IPS_GetObjectIDByName($Scriptname, $O_ID);
            $statusvar  = $this->GetIDForIdent("Status");
            if ($statusvar === false) {
                //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
                //    return false;
            }
            
            if (IPS_ScriptExists($scriptAn)) {
                @IPS_RunScript($scriptAn);
                SetValueBoolean($statusvar, true);
            } else {
                //        sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
            }
            
            // Timer anschalten
            $timeout = $this->ReadPropertyInteger("TimeOut") * 2 * 1000;
            $this->SetTimerInterval("Anschalten", $timeout);
            
            //return false;
        }
    }
    
    
    function LichtAusschalten()
    {
		$this->SetTimerInterval("Anschalten", 0);
		$this->SetTimerInterval("Ausschalten", 0);
		$this->SetTimerInterval("Umschalten", 0);
		
        if ($_IPS['SENDER'] == "TimerEvent") {
            
            // Timer ausschalten
            
            $this->SetTimerInterval("Ausschalten", 0);
            
            
            // Vergleichen und ggf. Fehlermeldung oder nochmal versuchen
            
            $statusvar = $this->GetIDForIdent("Status");
            if ($statusvar === false) {
                //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
                //    return false;
            }
            
            $O_ID         = $this->ReadPropertyInteger("PropertyInstanceID");
            $hw_statusvar = @IPS_GetObjectIDByName("Status", $O_ID);
            if ($hw_statusvar === false) {
                //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable_Hardware nicht gefunden', '', 0);
                //    return false;
            }
            
            if (GetValueBoolean($hw_statusvar) != GetValueBoolean($statusvar)) {
                
                // Differenz entedeckt!
                
                $CountVar = @$this->GetIDForIdent("Count");
                if ($CountVar === false) {
                    
                    // 1. Versuch
                    
                    //sendDBMessage('ERROR', 'V1-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet', '', 0);
                    IPS_LogMessage($_IPS['SELF'], "DEBUG:Schalten im 1. Versuch nicht erfolgreich");
                    $VarID_Count = $this->RegisterVariableBoolean("Count", "Count");
                    SetValueBoolean($VarID_Count, false);
                    IPS_SetHidden($VarID_Count, true);
                    
                    // 2. Schaltversuch
                    
                    $Scriptname = "Aus_Force";
                    $O_ID       = $this->ReadPropertyInteger("PropertyInstanceID");
                    $scriptAn   = @IPS_GetObjectIDByName($Scriptname, $O_ID);
                    $statusvar  = $this->GetIDForIdent("Status");
                    if ($statusvar === false) {
                        //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
                        //    return false;
                    }
                    
                    if (IPS_ScriptExists($scriptAn)) {
                        @IPS_RunScript($scriptAn);
                        SetValueBoolean($statusvar, true);
                    } else {
                        //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
                    }
                    
                    // Neuen Timer aufziehen für 2. Versuch
                    $timeout = $this->ReadPropertyInteger("TimeOut") * 2 * 1000;
                    $this->SetTimerInterval("Ausschalten", $timeout);
                    
                    
                } else {
                    
                    // 2. Durchlauf und immer noch nich geschaltet
                    
                    //        sendDBMessage('ERROR', 'V2-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet nach Wiederholung', '', 0);
                    
                    // IPS_DeleteVariable($CountVar);
                    // Keinen neuen Timer, hier Ende
                    
                }
            } else {
                
                // Keine Differenz (mehr) entdeckt, mögliche Variable löschen
                
                $CountVar = @$this->GetIDForIdent("Count");
                if ($CountVar === false) {
                    
                    // war gar nicht da, also nie Differenz gehabt
                    
                } else {
                    UnregisterVariable("Count");
                }
            }
        } else {
            $Scriptname = "Aus";
            $O_ID       = $this->ReadPropertyInteger("PropertyInstanceID");
            $scriptAn   = @IPS_GetObjectIDByName($Scriptname, $O_ID);
            $statusvar  = $this->GetIDForIdent("Status");
            if ($statusvar === false) {
                //    sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
                //    return false;
            }
            
            if (IPS_ScriptExists($scriptAn)) {
                @IPS_RunScript($scriptAn);
                SetValueBoolean($statusvar, false);
            } else {
                //        sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
            }
            
            // Timer anschalten
            $timeout = $this->ReadPropertyInteger("TimeOut") * 2 * 1000;
            $this->SetTimerInterval("Ausschalten", $timeout);
            
            
            //return false;
        }
    } // IPS_LichtAus
}

?>