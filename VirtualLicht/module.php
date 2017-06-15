<?
     
    // Klassendefinition
    class VirtualLicht extends IPSModule {

 
        // Der Konstruktor des Moduls
        // Überschreibt den Standard Kontruktor von IPS
        public function __construct($InstanceID) {
            // Diese Zeile nicht löschen
            parent::__construct($InstanceID);
 
            // Selbsterstellter Code
        }
 
        // Überschreibt die interne IPS_Create($id) Funktion
        public function Create() {
            // Diese Zeile nicht löschen.
            parent::Create();
            
			$this->RegisterPropertyInteger("PropertyInstanceID",0); 
			$this->RegisterPropertyInteger("ToggleScriptID",0); 
			
			//Variablenprofil anlegen ($name, $ProfileType, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits, $Icon)
		$profilename = "VIR.Licht";
		if (!IPS_VariableProfileExists($profilename)) {
			IPS_CreateVariableProfile($profilename, 1);
			IPS_SetVariableProfileIcon($profilename, "Bulb");
			IPS_SetVariableProfileAssociation($profilename, 0, "an", "", 0xFFFF00);
			IPS_SetVariableProfileAssociation($profilename, 99, "aus", "", 0xFFFF00);
			IPS_SetVariableProfileAssociation($profilename, 1, "gedimmt", "", 0xFFFF00);
			
		}
				$proberty_name = "action";
				$varID = @$this->GetIDForIdent($proberty_name);
				if (IPS_VariableExists($varID)) {
					
			

				}
				else {
				$VarID_NEU = $this->RegisterVariableInteger($proberty_name,"Aktion","VIR.Licht",0);
				$this->EnableAction($proberty_name);
		
				
				
				}
				
				
 
        }
		
		public function RequestAction($Ident, $Value) {
		 //Hier auf klick reagieren
	}
 
        // Überschreibt die intere IPS_ApplyChanges($id) Funktion
        public function ApplyChanges() {
            // Diese Zeile nicht löschen
            
			parent::ApplyChanges();
			//Instanz ist aktiv
			$this->SetStatus(102);
			
			//IPS_ApplyChanges($this->InstanceID); //Neue Konfiguration übernehmen
			
        }
 
        /**
        * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
        * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:
        *
        * ABC_MeineErsteEigeneFunktion($id);
        *
        */
        public function LichtUmschalten() {
            if (($_IPS['SENDER'] == 'WebFront') or ($_IPS['SENDER'] == "AlexaSmartHome"))
		{
		if ($_IPS['VALUE'] === true)
			{
			LichtAnschalten();
			}
			
		  else
			{
			LichtAusschalten();
			}
		}
	  else
		{
		if ($_IPS['SENDER'] == "TimerEvent")
			{

			// Timer ausschalten

			$this->SetTimerInterval("Umschalten", 0);
			// Vergleichen und ggf. Fehlermeldung oder nochmal versuchen

			$statusvar = GetIDForIdent("Status");
			if (IPS_VariableExists($statusvar)) {
				
			

				}
				else {
				$VarID_NEU = $this->RegisterVariableBoolean("Status","Status");
				
				
				}
			$O_ID = $this->ReadPropertyString("PropertyInstanceID");
			$hw_statusvar = @IPS_GetObjectIDByName("Status", $O_ID);
			if ($hw_statusvar === false)
				{
			//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable_Hardware nicht gefunden', '', 0);
			//	return false;
				}

			if (GetValueBoolean($hw_statusvar) != GetValueBoolean($statusvar))
				{

				// Differenz entedeckt!
////TODO anderen Variablen-Typ wählen

				$CountVar = GetIDForIdent("Count");
				
				if ($CountVar === false)
					{

					// 1. Versuch

				//	sendDBMessage('ERROR', 'V1-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet', '', 0);
								IPS_LogMessage($_IPS['SELF'], "DEBUG:Schalten im 1. Versuch nicht erfolgreich");

					$VarID_Count = $this->RegisterVariableBoolean("Count","Count");
					
					SetValueBoolean($VarID_Count, false);

					// 2. Versuch starten

					$Scriptname = "Toggle";
					$O_ID = $this->ReadPropertyString("PropertyInstanceID");
					$scriptAn = @IPS_GetObjectIDByName($Scriptname, $O_ID);
					$statusvar = GetIDForIdent("Status");
					if ($statusvar === false)
						{
				//		sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
				//		return false;
						}

					if (IPS_ScriptExists($scriptAn))
						{
						@IPS_RunScript($scriptAn);
						SetValueBoolean($statusvar, !GetValueBoolean($statusvar));
						}
					  else
						{
				//		sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
						}

					// Neuen Timer aufziehen für 2. Versuch
					$this->SetTimerInterval("Umschalten", 20000);
					}
				  else
					{

					// 2. Durchlauf und immer noch nich geschaltet

				//	sendDBMessage('ERROR', 'V2-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet nach Wiederholung', '', 0);

					// IPS_DeleteVariable($CountVar);
					// Keinen neuen Timer, hier Ende

					}
				}
			  else
				{

				// Keine Differenz (mehr) entdeckt, mögliche Variable löschen

				$CountVar = GetIDForIdent("Count");
				if ($CountVar === false)
					{

					// war gar nicht da, also nie Differenz gehabt

					}
				  else
					{
					UnregisterVariable("Count");
					}
				}
			}
		  else
			{
			$Scriptname = "Toggle";
			$O_ID = $this->ReadPropertyString("PropertyInstanceID");
			$scriptAn = @IPS_GetObjectIDByName($Scriptname, $O_ID);
			$statusvar = GetIDForIdent("Status");
			if ($statusvar === false)
				{
		//		sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
		//		return false;
				}

			if (IPS_ScriptExists($scriptAn))
				{
				@IPS_RunScript($scriptAn);
				SetValueBoolean($statusvar, !GetValueBoolean($statusvar));
				}
			  else
				{
		//		sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
				}

			// Timer anschalten

			$this->SetTimerInterval("Umschalten", 14000);
	//		return false;
			}
		}
	
				

        }
		 public function LichtAnschalten() {
			 if ($_IPS['SENDER'] == "TimerEvent")
		{

		// Timer ausschalten

		$this->SetTimerInterval("Anschalten", 0);
		

		// Vergleichen und ggf. Fehlermeldung oder nochmal versuchen

		$statusvar = GetIDForIdent("Status");
		if ($statusvar === false)
			{
		//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
		//	return false;
			}

		$O_ID =  $this->ReadPropertyString("PropertyInstanceID");
		$hw_statusvar = @IPS_GetObjectIDByName("Status", $O_ID);
		if ($hw_statusvar === false)
			{
		//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable_Hardware nicht gefunden', '', 0);
		//	return false;
			}

		if (GetValueBoolean($hw_statusvar) != GetValueBoolean($statusvar))
			{

			// Differenz entedeckt!

			$CountVar = GetIDForIdent("Count");
			if ($CountVar === false)
				{

				// 1. Versuch

				//sendDBMessage('ERROR', 'V1-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet', '', 0);
				IPS_LogMessage($_IPS['SELF'], "DEBUG:Schalten im 1. Versuch nicht erfolgreich");
				$VarID_Count =  $this->RegisterVariableBoolean("Count","Count");
				SetValueBoolean($VarID_Count, false);

				// 2. Schaltversuch

				$Scriptname = "An_Force";
				$O_ID =  $this->ReadPropertyString("PropertyInstanceID");
				$scriptAn = @IPS_GetObjectIDByName($Scriptname, $O_ID);
				$statusvar = GetIDForIdent("Status");
				if ($statusvar === false)
					{
				//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
				//	return false;
					}

				if (IPS_ScriptExists($scriptAn))
					{
					@IPS_RunScript($scriptAn);
					SetValueBoolean($statusvar, true);
					}
				  else
					{
				//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
					}

				// Neuen Timer aufziehen für 2. Versuch

				$this->SetTimerInterval("Anschalten", 20000);
				}
			  else
				{

				// 2. Durchlauf und immer noch nich geschaltet

		//		sendDBMessage('ERROR', 'V2-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet nach Wiederholung', '', 0);

				// IPS_DeleteVariable($CountVar);
				// Keinen neuen Timer, hier Ende

				}
			}
		  else
			{

			// Keine Differenz (mehr) entdeckt, mögliche Variable löschen

			$CountVar = GetIDForIdent("Count");
			if ($CountVar === false)
				{

				// war gar nicht da, also nie Differenz gehabt

				}
			  else
				{
				UnregisterVariable("Count");
				}
			}
		}
	  else
		{
		$Scriptname = "An";
		$O_ID =  $this->ReadPropertyString("PropertyInstanceID");
		$scriptAn = @IPS_GetObjectIDByName($Scriptname, $O_ID);
		$statusvar = GetIDForIdent("Status");
		if ($statusvar === false)
			{
		//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
		//	return false;
			}

		if (IPS_ScriptExists($scriptAn))
			{
			@IPS_RunScript($scriptAn);
			SetValueBoolean($statusvar, true);
			}
		  else
			{
	//		sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
			}

		// Timer anschalten

		$this->SetTimerInterval("Anschalten", 14000);
		//return false;
		}
                   }
				   
				   
	 function LichtAusschalten()	{ 
	 if ($_IPS['SENDER'] == "TimerEvent")
		{

		// Timer ausschalten

		$this->SetTimerInterval("Ausschalten", 0);
		

		// Vergleichen und ggf. Fehlermeldung oder nochmal versuchen

		$statusvar = GetIDForIdent("Status");
		if ($statusvar === false)
			{
		//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
		//	return false;
			}

		$O_ID =  $this->ReadPropertyString("PropertyInstanceID");
		$hw_statusvar = @IPS_GetObjectIDByName("Status", $O_ID);
		if ($hw_statusvar === false)
			{
		//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable_Hardware nicht gefunden', '', 0);
		//	return false;
			}

		if (GetValueBoolean($hw_statusvar) != GetValueBoolean($statusvar))
			{

			// Differenz entedeckt!

			$CountVar = GetIDForIdent("Count");
			if ($CountVar === false)
				{

				// 1. Versuch

				//sendDBMessage('ERROR', 'V1-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet', '', 0);
				IPS_LogMessage($_IPS['SELF'], "DEBUG:Schalten im 1. Versuch nicht erfolgreich");
				$VarID_Count =  $this->RegisterVariableBoolean("Count","Count");
				SetValueBoolean($VarID_Count, false);

				// 2. Schaltversuch

				$Scriptname = "Aus_Force";
				$O_ID =  $this->ReadPropertyString("PropertyInstanceID");
				$scriptAn = @IPS_GetObjectIDByName($Scriptname, $O_ID);
				$statusvar = GetIDForIdent("Status");
				if ($statusvar === false)
					{
				//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
				//	return false;
					}

				if (IPS_ScriptExists($scriptAn))
					{
					@IPS_RunScript($scriptAn);
					SetValueBoolean($statusvar, true);
					}
				  else
					{
				//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
					}

				// Neuen Timer aufziehen für 2. Versuch

				$this->SetTimerInterval("Ausschalten", 20000);
				}
			  else
				{

				// 2. Durchlauf und immer noch nich geschaltet

		//		sendDBMessage('ERROR', 'V2-In ' . $_IPS['SELF'] . ' wurde nicht richtig geschaltet nach Wiederholung', '', 0);

				// IPS_DeleteVariable($CountVar);
				// Keinen neuen Timer, hier Ende

				}
			}
		  else
			{

			// Keine Differenz (mehr) entdeckt, mögliche Variable löschen

			$CountVar = GetIDForIdent("Count");
			if ($CountVar === false)
				{

				// war gar nicht da, also nie Differenz gehabt

				}
			  else
				{
				UnregisterVariable("Count");
				}
			}
		}
	  else
		{
		$Scriptname = "Aus";
		$O_ID =  $this->ReadPropertyString("PropertyInstanceID");
		$scriptAn = @IPS_GetObjectIDByName($Scriptname, $O_ID);
		$statusvar = GetIDForIdent("Status");
		if ($statusvar === false)
			{
		//	sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde die Statusvariable nicht gefunden', '', 0);
		//	return false;
			}

		if (IPS_ScriptExists($scriptAn))
			{
			@IPS_RunScript($scriptAn);
			SetValueBoolean($statusvar, true);
			}
		  else
			{
	//		sendDBMessage('ERROR', 'In ' . $_IPS['SELF'] . ' wurde das passende Script nicht gefunden', '', 0);
			}

		// Timer anschalten

		$this->SetTimerInterval("Ausschalten", 14000);
		//return false;
		}	} // IPS_LichtAus
	}
    
?>
