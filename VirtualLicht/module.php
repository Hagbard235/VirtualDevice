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
			$togglescript = $this->ReadPropertyString("ToggleScriptID");
			IPS_SetProperty($this->InstanceID, "ToggleScriptID", 99);
			//IPS_ApplyChanges($this->InstanceID); //Neue Konfiguration übernehmen
			
        }
 
        /**
        * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
        * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:
        *
        * ABC_MeineErsteEigeneFunktion($id);
        *
        */
        public function LichtSchalten() {
            //Eigener Coder
				

        }
		
    }
?>
