<?php
declare(strict_types=1);

// Optional guard: existing appliance instances without these bindings keep their behaviour.
trait ApplianceSupplyGuard
{
    private function CreateSupplyGuard(): void
    {
        $this->RegisterPropertyInteger('SocketStateID', 0);
        $this->RegisterPropertyInteger('OnlineID', 0);
        $this->RegisterPropertyInteger('PowerMaxAgeSeconds', 0);
        $this->RegisterAttributeBoolean('SupplyNeedsLoad', false);
        $this->RegisterAttributeInteger('SupplyLastCheck', 0);
    }

    private function SupplyGuardEnabled(): bool
    {
        return $this->ReadPropertyInteger('SocketStateID') > 0 || $this->ReadPropertyInteger('OnlineID') > 0 || $this->ReadPropertyInteger('PowerMaxAgeSeconds') > 0;
    }

    public function Cycle()
    {
        if (!$this->SupplyGuardEnabled()) { $this->CycleCore(); return; }
        $lock = 'ApplianceCycle.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1)) return;
        try {
            if ($this->SupplyReady()) $this->CycleCore();
            elseif ($this->ReadPropertyInteger('DryingHoldSeconds')>0) $this->SetValue('ProgramPhase',4);
        } finally { IPS_SemaphoreLeave($lock); }
    }

    private function SupplyReady(): bool
    {
        $now=time(); $reason='';
        foreach (['SocketStateID'=>'Steckdose ausgeschaltet', 'OnlineID'=>'Steckdose nicht erreichbar'] as $property=>$message) {
            $id=$this->ReadPropertyInteger($property);
            if ($id > 0 && (!IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] !== 0 || GetValue($id) !== true)) $reason=$message;
        }
        $powerID=$this->ReadPropertyInteger('PowerID');
        $age=$this->ReadPropertyInteger('PowerMaxAgeSeconds');
        $power=IPS_VariableExists($powerID)?GetValue($powerID):null;
        $stamp=IPS_VariableExists($powerID)?IPS_GetVariable($powerID)['VariableUpdated']:0;
        if (!is_numeric($power) || !is_finite((float)$power) || $power < 0 || !$stamp || $stamp>$now || ($age>0 && $now-$stamp>$age)) $reason='Leistungsmessung fehlt oder ist veraltet';
        $last=$this->ReadAttributeInteger('SupplyLastCheck');
        $this->WriteAttributeInteger('SupplyLastCheck', $now);
        if ($age>0 && $last>0 && $now-$last>$age) $reason=$reason?:'Unterbrechung der Überwachung';
        if ($reason !== '') {
            foreach (['AboveSince','BelowSince','LastSample'] as $key) $this->WriteAttributeFloat($key,0.0);
            if ($this->GetValue('State') === self::STATE_LAEUFT) $this->WriteAttributeBoolean('SupplyNeedsLoad',true);
            $this->SetValue('Info','Erkennung pausiert: '.$reason.'. Kein Programmende abgeleitet.');
            return false;
        }
        if ($this->ReadAttributeBoolean('SupplyNeedsLoad')) {
            if ($this->GetValue('State') !== self::STATE_LAEUFT || $power >= $this->ReadPropertyFloat('StartWatt')) {
                $this->WriteAttributeBoolean('SupplyNeedsLoad',false);
            } else {
                foreach (['AboveSince','BelowSince','LastSample'] as $key) $this->WriteAttributeFloat($key,0.0);
                $this->SetValue('Info','Versorgung wieder vorhanden; nach Unterbrechung noch keine erneute Laufleistung erkannt. Kein bestätigtes Programmende.');
                return false;
            }
        }
        return true;
    }
}
