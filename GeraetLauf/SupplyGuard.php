<?php
declare(strict_types=1);

// Optional guard: existing appliance instances without these bindings keep their behaviour.
trait ApplianceSupplyGuard
{
    private function CreateSupplyGuard(): void
    {
        $this->RegisterPropertyInteger('SocketStateID', 0);
        $this->RegisterPropertyInteger('OnlineID', 0);
        $this->RegisterPropertyInteger('OnlineGraceSeconds', 600);
        $this->RegisterPropertyInteger('PowerMaxAgeSeconds', 0);
        $this->RegisterAttributeBoolean('SupplyNeedsLoad', false);
        $this->RegisterAttributeInteger('SupplyLastCheck', 0);
        $this->RegisterAttributeInteger('OnlineOfflineSince', 0);
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
        $socketID=$this->ReadPropertyInteger('SocketStateID');
        if ($socketID > 0 && (!IPS_VariableExists($socketID) || IPS_GetVariable($socketID)['VariableType'] !== 0 || GetValue($socketID) !== true)) {
            $reason='Steckdose ausgeschaltet';
        }
        $onlineID=$this->ReadPropertyInteger('OnlineID');
        if ($onlineID > 0) {
            $onlineOk = IPS_VariableExists($onlineID) && IPS_GetVariable($onlineID)['VariableType'] === 0 && GetValue($onlineID) === true;
            if ($onlineOk) {
                $this->WriteAttributeInteger('OnlineOfflineSince', 0);
            } else {
                $grace = max(0, $this->ReadPropertyInteger('OnlineGraceSeconds'));
                $since = $this->ReadAttributeInteger('OnlineOfflineSince');
                if ($since <= 0 || $since > $now) {
                    $since = $now;
                    $this->WriteAttributeInteger('OnlineOfflineSince', $since);
                }
                if ($grace === 0 || ($now - $since) >= $grace) {
                    $reason = $reason ?: 'Steckdose nicht erreichbar';
                }
            }
        }
        $powerID=$this->ReadPropertyInteger('PowerID');
        $age=$this->ReadPropertyInteger('PowerMaxAgeSeconds');
        $power=IPS_VariableExists($powerID)?GetValue($powerID):null;
        $stamp=IPS_VariableExists($powerID)?IPS_GetVariable($powerID)['VariableUpdated']:0;
        if (!is_numeric($power) || !is_finite((float)$power) || $power < 0 || !$stamp || $stamp>$now || ($age>0 && $now-$stamp>$age)) $reason=$reason?:'Leistungsmessung fehlt oder ist veraltet';
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
