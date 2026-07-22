<?php

/**
 * ToDo-Zentrale
 *
 * Verwaltet offene Aufgaben als Datenliste und stellt sie im Webfront dar.
 *
 * Grundgedanke: Die Wahrheit ist die Liste, die Kacheln sind nur ihre
 * Darstellung. Bei jeder Aenderung gleicht das Modul die sichtbaren Objekte
 * gegen den Soll-Zustand ab - fehlende werden angelegt, ueberzaehlige samt
 * Profil entfernt. Dadurch kann es keine Karteileichen geben: der Soll-Zustand
 * ist jederzeit bekannt und neu herleitbar.
 *
 * Automatisch erledigte Aufgaben haengen an einer *Bedingung*, die zyklisch
 * geprueft wird, nicht an einem einmaligen Ereignis. Ist die Bedingung schon
 * beim Anlegen erfuellt, entsteht die Aufgabe gar nicht erst.
 */
class ToDoZentrale extends IPSModule {

    // Quittierungsart
    const ACK_MANUELL     = 0; // Antippen erledigt die Aufgabe
    const ACK_AUTOMATISCH = 1; // nur die Bedingung erledigt sie
    const ACK_BEIDES      = 2; // was zuerst eintritt
    const ACK_INFO        = 3; // reine Anzeige, nicht antippbar

    // Prioritaet
    const PRIO_NIEDRIG = 0;
    const PRIO_NORMAL  = 1;
    const PRIO_WICHTIG = 2;
    const PRIO_KRITISCH = 3;

    // Vergleichsoperator fuer Erledigungs- und Unterdrueckungsbedingungen
    const CMP_GLEICH   = 0;
    const CMP_UNGLEICH = 1;
    const CMP_GROESSER = 2;
    const CMP_KLEINER  = 3;
    const CMP_WAHR     = 4;
    const CMP_FALSCH   = 5;

    // Wann gesprochen wird (Bitmaske)
    const SPEAK_NEU       = 1;
    const SPEAK_ERINNERUNG = 2;
    const SPEAK_ERLEDIGT  = 4;

    // Voreingestellte Erinnerungsintervalle je Prioritaet (Sekunden).
    const DEFAULT_INTERVALS = [3600, 1800, 900, 300];

    // Praefix aller vom Modul verwalteten Variablenprofile.
    const PROFILE_PREFIX = "ToDo.";

    public function Create() {
        parent::Create();

        $this->RegisterPropertyString("Categories", "[]");
        $this->RegisterPropertyString("VoiceTargets", "[]");
        $this->RegisterPropertyInteger("DefaultParentID", 0);

        $this->RegisterPropertyString("QuietStart", "22:00");
        $this->RegisterPropertyString("QuietEnd", "07:00");
        $this->RegisterPropertyBoolean("QuietEnabled", true);
        $this->RegisterPropertyInteger("VoiceMinPriority", self::PRIO_NORMAL);
        $this->RegisterPropertyInteger("SummaryThreshold", 3);
        $this->RegisterPropertyInteger("RepeatSuppressSeconds", 600);
        $this->RegisterPropertyInteger("CheckInterval", 60);

        // Die Aufgabenliste selbst: Ident => Datensatz.
        $this->RegisterAttributeString("Items", "{}");

        // Was das Modul im Webfront angelegt hat: Ident => VariablenID.
        // Nur was hier steht, wird beim Abgleich auch wieder entfernt - fremde
        // Objekte fasst das Modul nicht an.
        $this->RegisterAttributeString("Rendered", "{}");

        $this->CreateProfiles();

        $this->RegisterVariableInteger("OpenCount", "Offene Aufgaben", "TODO.Anzahl", 10);
        $this->RegisterVariableString("Summary", "Uebersicht", "", 20);
        $this->RegisterVariableBoolean("QuietNow", "Ruhezeit aktiv", "~Switch", 30);

        $this->RegisterTimer("CycleTimer", 0, 'TODO_Cycle($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                @$this->UnregisterMessage($senderID, $message);
            }
        }

        // Auf die Bedingungsvariablen aller Aufgaben hoeren, damit eine
        // erfuellte Bedingung sofort wirkt und nicht erst im naechsten Zyklus.
        foreach ($this->GetItems() as $item) {
            foreach ([$item['clearVarID'], $item['suppressVarID']] as $id) {
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }

        $this->CreateAckScript();

        $interval = $this->ReadPropertyInteger("CheckInterval");
        if ($interval < 10) $interval = 10;
        $this->SetTimerInterval("CycleTimer", $interval * 1000);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Cycle();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != VM_UPDATE) return;
        // Eine Bedingungsvariable hat sich gemeldet - sofort neu bewerten.
        $this->Cycle();
    }

    // ------------------------------------------------------------------
    // Oeffentliche Schnittstelle
    // ------------------------------------------------------------------

    /**
     * Aufgabe anlegen oder aktualisieren.
     *
     * TODO_Set(InstanceID, "WMFERTIG", "Waschmaschine ausraeumen", json_encode([
     *     "kategorie"   => "Geraete",
     *     "prio"        => 2,
     *     "quittierung" => 1,
     *     "sprache"     => "Die Waschmaschine ist fertig",
     *     "clearVar"    => 21715, "clearMode" => 5,
     *     "erinnerung"  => 1800,
     *     "sprechen"    => 7
     * ]));
     *
     * Alle Optionen sind freiwillig. Vollstaendige Liste siehe README.
     */
    public function Set(string $Ident, string $Text, string $Options = "") {
        $Ident = $this->NormalizeIdent($Ident);
        if ($Ident === "") {
            $this->SendDebug("Set", "Abbruch: leerer Ident", 0);
            return false;
        }

        $opt = json_decode($Options, true);
        if (!is_array($opt)) $opt = [];

        $items = $this->GetItems();
        $existing = isset($items[$Ident]) ? $items[$Ident] : null;

        $item = [
            'ident'       => $Ident,
            'text'        => $Text,
            'speech'      => $this->Opt($opt, 'sprache', $existing, 'speech', ""),
            'category'    => $this->Opt($opt, 'kategorie', $existing, 'category', ""),
            'ack'         => (int)$this->Opt($opt, 'quittierung', $existing, 'ack', self::ACK_MANUELL),
            'priority'    => (int)$this->Opt($opt, 'prio', $existing, 'priority', self::PRIO_NORMAL),
            'color'       => $this->Opt($opt, 'farbe', $existing, 'color', ""),
            'icon'        => $this->Opt($opt, 'icon', $existing, 'icon', ""),
            'button'      => $this->Opt($opt, 'schalter', $existing, 'button', ""),
            'due'         => (int)$this->Opt($opt, 'faellig', $existing, 'due', 0),
            'clearVarID'  => (int)$this->Opt($opt, 'clearVar', $existing, 'clearVarID', 0),
            'clearMode'   => (int)$this->Opt($opt, 'clearMode', $existing, 'clearMode', self::CMP_WAHR),
            'clearValue'  => $this->Opt($opt, 'clearWert', $existing, 'clearValue', ""),
            'suppressVarID' => (int)$this->Opt($opt, 'stummVar', $existing, 'suppressVarID', 0),
            'suppressMode'  => (int)$this->Opt($opt, 'stummMode', $existing, 'suppressMode', self::CMP_WAHR),
            'suppressValue' => $this->Opt($opt, 'stummWert', $existing, 'suppressValue', ""),
            'remindInterval' => (int)$this->Opt($opt, 'erinnerung', $existing, 'remindInterval', -1),
            'remindMax'   => (int)$this->Opt($opt, 'erinnerungMax', $existing, 'remindMax', 0),
            'speakOn'     => (int)$this->Opt($opt, 'sprechen', $existing, 'speakOn', self::SPEAK_NEU),
            'voiceTargets' => $this->Opt($opt, 'sprachziele', $existing, 'voiceTargets', []),
            'created'     => $existing ? $existing['created'] : time(),
            'remindCount' => $existing ? $existing['remindCount'] : 0,
            'lastReminder' => $existing ? $existing['lastReminder'] : 0,
            'snoozeUntil' => $existing ? $existing['snoozeUntil'] : 0
        ];

        // Standardintervall aus der Prioritaet ableiten, wenn nichts gesetzt.
        if ($item['remindInterval'] < 0) {
            $prio = max(0, min(3, $item['priority']));
            $item['remindInterval'] = self::DEFAULT_INTERVALS[$prio];
        }

        // Ist die Erledigungsbedingung schon erfuellt, entsteht die Aufgabe
        // gar nicht erst. Das ist der Fall "Tuer stand beim Programmende
        // bereits offen - es kann niemand mehr etwas ausraeumen muessen".
        if ($this->ConditionMet($item['clearVarID'], $item['clearMode'], $item['clearValue'])) {
            $this->SendDebug("Set", "'$Ident' nicht angelegt: Erledigungsbedingung ist bereits erfuellt", 0);
            if ($existing) $this->Clear($Ident);
            return false;
        }

        $isNew = ($existing === null);
        $items[$Ident] = $item;
        $this->PutItems($items);

        $this->SendDebug("Set", ($isNew ? "Neu: " : "Aktualisiert: ") . "$Ident ($Text)", 0);

        if ($isNew && ($item['speakOn'] & self::SPEAK_NEU)) {
            $this->SpeakItem($item, $this->SpeechText($item));
        }

        // Neue Bedingungsvariablen brauchen eine Registrierung.
        if ($isNew) {
            foreach ([$item['clearVarID'], $item['suppressVarID']] as $id) {
                if ($id > 0 && IPS_VariableExists($id)) $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        $this->Reconcile();
        $this->UpdateStatusVariables();
        return true;
    }

    /**
     * Aufgabe entfernen, ohne sie als erledigt zu melden.
     */
    public function Clear(string $Ident) {
        $Ident = $this->NormalizeIdent($Ident);
        $items = $this->GetItems();
        if (!isset($items[$Ident])) return false;

        $this->SendDebug("Clear", "Entferne '$Ident'", 0);
        unset($items[$Ident]);
        $this->PutItems($items);
        $this->Reconcile();
        $this->UpdateStatusVariables();
        return true;
    }

    /**
     * Aufgabe als erledigt quittieren (inklusive Erledigt-Ansage).
     */
    public function Acknowledge(string $Ident) {
        $Ident = $this->NormalizeIdent($Ident);
        $items = $this->GetItems();
        if (!isset($items[$Ident])) return false;

        $item = $items[$Ident];
        $this->SendDebug("Acknowledge", "'$Ident' erledigt", 0);

        if ($item['speakOn'] & self::SPEAK_ERLEDIGT) {
            $this->SpeakItem($item, "Erledigt: " . $this->SpeechText($item));
        }

        unset($items[$Ident]);
        $this->PutItems($items);
        $this->Reconcile();
        $this->UpdateStatusVariables();
        return true;
    }

    /**
     * Wird vom Hilfsskript aufgerufen, wenn eine Kachel angetippt wurde.
     */
    public function AcknowledgeByVariable(int $VariableID) {
        $rendered = $this->GetRendered();
        foreach ($rendered as $ident => $varID) {
            if ($varID == $VariableID) {
                $this->Acknowledge($ident);
                return true;
            }
        }
        $this->SendDebug("Acknowledge", "Variable $VariableID gehoert zu keiner bekannten Aufgabe", 0);
        return false;
    }

    /**
     * Aufgabe voruebergehend stummschalten (Erinnerungen pausieren).
     */
    public function Snooze(string $Ident, int $Minutes) {
        $Ident = $this->NormalizeIdent($Ident);
        $items = $this->GetItems();
        if (!isset($items[$Ident])) return false;

        $items[$Ident]['snoozeUntil'] = time() + ($Minutes * 60);
        $this->PutItems($items);
        $this->SendDebug("Snooze", "'$Ident' fuer $Minutes min stumm", 0);
        return true;
    }

    public function Exists(string $Ident): bool {
        $items = $this->GetItems();
        return isset($items[$this->NormalizeIdent($Ident)]);
    }

    /**
     * Alle offenen Aufgaben als JSON, z.B. fuer eigene Visualisierungen.
     */
    public function ListJSON(): string {
        return json_encode(array_values($this->GetItems()));
    }

    /**
     * Freie Sprachausgabe ueber dieselben Kanaele wie die Aufgaben.
     * $Targets ist eine kommaseparierte Liste von Ziel-Schluesseln,
     * leer bedeutet "alle aktiven Ziele".
     */
    public function Speak(string $Text, string $Targets = "") {
        $keys = [];
        foreach (explode(",", $Targets) as $k) {
            $k = trim($k);
            if ($k !== "") $keys[] = $k;
        }
        $this->SpeakRaw($Text, $keys, false);
    }

    /**
     * Uebernimmt vorhandene ToDo-Kacheln einer Kategorie in die Verwaltung
     * des Moduls. Gedacht fuer die Migration vom bisherigen Skript-System.
     */
    public function AdoptLegacy(int $ParentID) {
        if (!$this->CanHoldChildren($ParentID)) {
            echo "Objekt $ParentID existiert nicht oder kann keine Kacheln enthalten.\n";
            return;
        }

        $items = $this->GetItems();
        $rendered = $this->GetRendered();
        $count = 0;

        foreach (IPS_GetChildrenIDs($ParentID) as $childID) {
            if (!IPS_VariableExists($childID)) continue;
            $object = IPS_GetObject($childID);
            $ident = $object['ObjectIdent'];
            if ($ident === "" || isset($items[$ident])) continue;

            $items[$ident] = [
                'ident' => $ident,
                'text' => IPS_GetName($childID),
                'speech' => "",
                'category' => "",
                'ack' => self::ACK_MANUELL,
                'priority' => self::PRIO_NORMAL,
                'color' => "", 'icon' => "", 'button' => "",
                'due' => 0,
                'clearVarID' => 0, 'clearMode' => self::CMP_WAHR, 'clearValue' => "",
                'suppressVarID' => 0, 'suppressMode' => self::CMP_WAHR, 'suppressValue' => "",
                'remindInterval' => 0, 'remindMax' => 0,
                'speakOn' => 0, 'voiceTargets' => [],
                'created' => time(), 'remindCount' => 0, 'lastReminder' => 0, 'snoozeUntil' => 0
            ];
            $rendered[$ident] = $childID;
            $count++;
            echo "Uebernommen: $ident (" . IPS_GetName($childID) . ")\n";
        }

        $this->PutItems($items);
        $this->PutRendered($rendered);
        $this->Reconcile();
        $this->UpdateStatusVariables();
        echo "\n$count Aufgabe(n) uebernommen.\n";
    }

    /**
     * Entfernt Variablenprofile mit dem Praefix "ToDo.", zu denen es keine
     * Variable mehr gibt. Raeumt die Reste des alten Skript-Systems auf.
     */
    public function CleanupProfiles() {
        $used = [];
        foreach (IPS_GetVariableList() as $varID) {
            $variable = IPS_GetVariable($varID);
            if ($variable['VariableCustomProfile'] !== "") $used[$variable['VariableCustomProfile']] = true;
            if ($variable['VariableProfile'] !== "") $used[$variable['VariableProfile']] = true;
        }

        $removed = 0;
        foreach (IPS_GetVariableProfileList() as $profile) {
            if (strpos($profile, self::PROFILE_PREFIX) !== 0) continue;
            if (isset($used[$profile])) continue;
            @IPS_DeleteVariableProfile($profile);
            echo "Verwaistes Profil entfernt: $profile\n";
            $removed++;
        }
        echo "\n$removed verwaiste Profil(e) entfernt.\n";
    }

    // ------------------------------------------------------------------
    // Zyklus
    // ------------------------------------------------------------------

    public function Cycle() {
        $items = $this->GetItems();
        if (count($items) == 0) {
            $this->Reconcile();
            $this->UpdateStatusVariables();
            return;
        }

        $now = time();
        $quiet = $this->IsQuietTime();
        $dueReminders = [];
        $changed = false;

        foreach ($items as $ident => $item) {
            // 1. Erledigungsbedingung
            if ($this->ConditionMet($item['clearVarID'], $item['clearMode'], $item['clearValue'])) {
                $this->SendDebug("Cycle", "'$ident' automatisch erledigt (Bedingung erfuellt)", 0);
                if ($item['speakOn'] & self::SPEAK_ERLEDIGT) {
                    $this->SpeakItem($item, "Erledigt: " . $this->SpeechText($item));
                }
                unset($items[$ident]);
                $changed = true;
                continue;
            }

            // 2. Unterdrueckung
            if ($this->ConditionMet($item['suppressVarID'], $item['suppressMode'], $item['suppressValue'])) {
                continue;
            }

            // 3. Erinnerung faellig?
            if ($item['remindInterval'] <= 0) continue;
            if ($item['snoozeUntil'] > $now) continue;
            if ($item['remindMax'] > 0 && $item['remindCount'] >= $item['remindMax']) continue;

            $reference = $item['lastReminder'] > 0 ? $item['lastReminder'] : $item['created'];
            if (($now - $reference) < $item['remindInterval']) continue;

            $items[$ident]['lastReminder'] = $now;
            $items[$ident]['remindCount'] = $item['remindCount'] + 1;
            $changed = true;
            $dueReminders[] = $items[$ident];
        }

        if ($changed) $this->PutItems($items);

        if (count($dueReminders) > 0) {
            $this->SendDebug("Cycle", count($dueReminders) . " Erinnerung(en) faellig" . ($quiet ? " (Ruhezeit - nur sichtbar)" : ""), 0);
            $this->DeliverReminders($dueReminders);
        }

        $this->Reconcile();
        $this->UpdateStatusVariables();
    }

    /**
     * Erinnerungen ausliefern. Ab der eingestellten Anzahl wird zu einer
     * Sammelansage zusammengefasst, statt mehrfach hintereinander zu reden.
     */
    private function DeliverReminders(array $reminders) {
        $speakable = [];
        foreach ($reminders as $item) {
            if (!($item['speakOn'] & self::SPEAK_ERINNERUNG)) continue;
            if ($item['priority'] < $this->ReadPropertyInteger("VoiceMinPriority")) continue;
            $speakable[] = $item;
        }
        if (count($speakable) == 0) return;

        $threshold = $this->ReadPropertyInteger("SummaryThreshold");
        if ($threshold > 0 && count($speakable) >= $threshold) {
            $texts = [];
            foreach ($speakable as $item) $texts[] = $this->SpeechText($item);
            $text = "Es stehen " . count($texts) . " Dinge an: " . implode(", ", $texts) . ".";
            $this->SpeakRaw($text, [], true);
            return;
        }

        foreach ($speakable as $item) {
            $this->SpeakItem($item, $this->SpeechText($item));
        }
    }

    // ------------------------------------------------------------------
    // Bedingungen
    // ------------------------------------------------------------------

    /**
     * Wertet eine Bedingung gegen den *aktuellen Zustand* einer Variablen aus.
     * Bewusst kein Ereignis auf Aenderung: eine bereits erfuellte Bedingung
     * muss auch dann greifen, wenn sich nie wieder etwas bewegt.
     */
    private function ConditionMet(int $varID, int $mode, $value): bool {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return false;

        $actual = GetValue($varID);

        switch ($mode) {
            case self::CMP_WAHR:     return (bool)$actual === true;
            case self::CMP_FALSCH:   return (bool)$actual === false;
            case self::CMP_GROESSER: return (float)$actual > (float)$value;
            case self::CMP_KLEINER:  return (float)$actual < (float)$value;
            case self::CMP_UNGLEICH: return (string)$actual !== (string)$value;
            default:                 return (string)$actual === (string)$value;
        }
    }

    // ------------------------------------------------------------------
    // Darstellung im Webfront
    // ------------------------------------------------------------------

    /**
     * Bringt die sichtbaren Objekte auf den Stand der Liste. Das ist der Kern
     * gegen Karteileichen: es wird nicht "geloescht was weg soll", sondern
     * hergestellt "was da sein soll".
     */
    private function Reconcile() {
        $items = $this->GetItems();
        $rendered = $this->GetRendered();
        $categories = $this->GetCategories();

        // 1. Ueberzaehliges entfernen - nur was das Modul selbst angelegt hat.
        foreach ($rendered as $ident => $varID) {
            if (isset($items[$ident])) continue;
            $this->RemoveTile($varID, $ident);
            unset($rendered[$ident]);
        }

        // 2. Fehlendes anlegen und Bestehendes auffrischen.
        $position = 0;
        foreach ($this->SortItems($items) as $item) {
            $ident = $item['ident'];
            $parentID = $this->ResolveParent($item, $categories);
            if ($parentID <= 0) continue;

            $varID = isset($rendered[$ident]) ? $rendered[$ident] : 0;
            if ($varID > 0 && !IPS_VariableExists($varID)) $varID = 0;

            // Eine gleichnamige Variable am Zielort uebernehmen, statt eine
            // zweite anzulegen.
            if ($varID == 0) {
                $found = @IPS_GetObjectIDByIdent($ident, $parentID);
                if ($found !== false) $varID = $found;
            }

            if ($varID == 0) {
                $varID = IPS_CreateVariable(VARIABLETYPE_INTEGER);
                IPS_SetParent($varID, $parentID);
                IPS_SetIdent($varID, $ident);
                $this->SendDebug("Reconcile", "Kachel angelegt: $ident (ID $varID)", 0);
            } elseif (IPS_GetParent($varID) != $parentID) {
                IPS_SetParent($varID, $parentID);
            }

            IPS_SetName($varID, $item['text']);
            IPS_SetPosition($varID, $position++);

            $this->ApplyTileProfile($varID, $item, $categories);

            // Antippbar nur, wenn die Aufgabe manuell quittiert werden darf.
            $clickable = ($item['ack'] == self::ACK_MANUELL || $item['ack'] == self::ACK_BEIDES);
            $actionID = $clickable ? $this->GetAckScriptID() : 0;
            $variable = IPS_GetVariable($varID);
            if ($variable['VariableCustomAction'] != $actionID) {
                IPS_SetVariableCustomAction($varID, $actionID);
            }

            @SetValue($varID, 0);
            $rendered[$ident] = $varID;
        }

        $this->PutRendered($rendered);
    }

    /**
     * Profil der Kachel setzen. Ein Profil je Aufgabe, weil Text, Icon und
     * Farbe pro Aufgabe unterschiedlich sind.
     */
    private function ApplyTileProfile(int $varID, array $item, array $categories) {
        $profile = self::PROFILE_PREFIX . $item['ident'];
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
        }

        $category = isset($categories[$item['category']]) ? $categories[$item['category']] : null;

        $icon = $item['icon'] !== "" ? $item['icon'] : ($category ? $category['Icon'] : "Ok");
        if ($icon === "") $icon = "Ok";

        $color = $this->ResolveColor($item, $category);
        $button = $item['button'] !== "" ? $item['button'] : "...erledigen...";

        IPS_SetVariableProfileIcon($profile, $icon);
        IPS_SetVariableProfileAssociation($profile, 0, $button, $icon, $color);

        $variable = IPS_GetVariable($varID);
        if ($variable['VariableCustomProfile'] !== $profile) {
            IPS_SetVariableCustomProfile($varID, $profile);
        }
    }

    /**
     * Kachel und zugehoeriges Profil entfernen.
     * Reihenfolge ist wichtig: ein Profil, das noch an einer Variablen haengt,
     * laesst sich nicht loeschen.
     */
    private function RemoveTile(int $varID, string $ident) {
        if ($varID > 0 && IPS_VariableExists($varID)) {
            @IPS_SetVariableCustomProfile($varID, "");
            @IPS_DeleteVariable($varID);
            $this->SendDebug("Reconcile", "Kachel entfernt: $ident (ID $varID)", 0);
        }

        $profile = self::PROFILE_PREFIX . $ident;
        if (IPS_VariableProfileExists($profile)) {
            @IPS_DeleteVariableProfile($profile);
        }
    }

    private function ResolveParent(array $item, array $categories): int {
        if (isset($categories[$item['category']])) {
            $parentID = (int)$categories[$item['category']]['ParentID'];
            if ($this->CanHoldChildren($parentID)) return $parentID;
        }
        $fallback = $this->ReadPropertyInteger("DefaultParentID");
        if ($this->CanHoldChildren($fallback)) return $fallback;
        return $this->InstanceID;
    }

    /**
     * Kacheln koennen unter einer Kategorie oder unter einer Instanz haengen -
     * etwa einem Dummy-Modul, was in der Visualisierung einen zusaetzlichen
     * Navigationsknoten spart. Alles andere kann keine Kinder aufnehmen.
     */
    private function CanHoldChildren(int $objectID): bool {
        if ($objectID <= 0 || !IPS_ObjectExists($objectID)) return false;
        $type = IPS_GetObject($objectID)['ObjectType'];
        return ($type == 0 || $type == 1);
    }

    private function ResolveColor(array $item, $category): int {
        if ($item['color'] !== "") {
            $named = $this->NamedColor($item['color']);
            if ($named >= 0) return $named;
        }
        if ($category && isset($category['Color'])) {
            $named = $this->NamedColor((string)$category['Color']);
            if ($named >= 0) return $named;
        }
        // Ohne Vorgabe faerbt die Prioritaet.
        switch ($item['priority']) {
            case self::PRIO_KRITISCH: return 0xFF0000;
            case self::PRIO_WICHTIG:  return 0xFF8800;
            case self::PRIO_NIEDRIG:  return 0x888888;
            default:                  return 0xFFFF00;
        }
    }

    /**
     * Akzeptiert sowohl Klartextfarben (kompatibel zum alten Skript) als auch
     * Hexwerte wie "0x00FF00" oder "#00FF00".
     */
    private function NamedColor(string $value): int {
        $key = strtoupper(trim($value));
        switch ($key) {
            case "GRUEN": case "GRÜN": return 0x00FF00;
            case "GELB":  return 0xFFFF00;
            case "ROT":   return 0xFF0000;
            case "BLAU":  return 0x0000FF;
            case "LILA":  case "VIOLETT": return 0xBF00FF;
            case "ORANGE": return 0xFF8800;
            case "GRAU":  return 0x888888;
            case "WEISS": return 0xFFFFFF;
            case "SCHWARZ": return 0x000000;
        }
        if (preg_match('/^(0X|#)?([0-9A-F]{6})$/', $key, $m)) {
            return (int)hexdec($m[2]);
        }
        return -1;
    }

    private function SortItems(array $items): array {
        $list = array_values($items);
        usort($list, function ($a, $b) {
            if ($a['priority'] != $b['priority']) return $b['priority'] - $a['priority'];
            return $a['created'] - $b['created'];
        });
        return $list;
    }

    // ------------------------------------------------------------------
    // Sprachausgabe
    // ------------------------------------------------------------------

    private function SpeechText(array $item): string {
        return $item['speech'] !== "" ? $item['speech'] : $item['text'];
    }

    private function SpeakItem(array $item, string $text) {
        if ($item['priority'] < $this->ReadPropertyInteger("VoiceMinPriority")) {
            $this->SendDebug("Sprache", "Unterdrueckt (Prioritaet zu niedrig): $text", 0);
            return;
        }
        $targets = is_array($item['voiceTargets']) ? $item['voiceTargets'] : [];
        $this->SpeakRaw($text, $targets, true);
    }

    /**
     * @param bool $respectQuiet Ruhezeit beachten. Direkte Aufrufe ueber
     *                           TODO_Speak sprechen bewusst auch nachts.
     */
    private function SpeakRaw(string $text, array $targetKeys, bool $respectQuiet) {
        $text = trim($text);
        if ($text === "") return;

        if ($respectQuiet && $this->IsQuietTime()) {
            $this->SendDebug("Sprache", "Ruhezeit - nicht gesprochen: $text", 0);
            return;
        }

        // Wiederholungsdaempfung: derselbe Satz nicht mehrfach kurz nacheinander.
        $suppress = $this->ReadPropertyInteger("RepeatSuppressSeconds");
        if ($suppress > 0) {
            $last = json_decode($this->GetBuffer("LastSpeech"), true);
            if (is_array($last) && $last['text'] === $text && (time() - $last['ts']) < $suppress) {
                $this->SendDebug("Sprache", "Wiederholung unterdrueckt: $text", 0);
                return;
            }
            $this->SetBuffer("LastSpeech", json_encode(['text' => $text, 'ts' => time()]));
        }

        $spoken = 0;
        foreach ($this->GetVoiceTargets() as $target) {
            if (!$target['Enabled']) continue;
            if (count($targetKeys) > 0 && !in_array($target['Key'], $targetKeys)) continue;

            $scriptID = (int)$target['ScriptID'];
            $variableID = (int)$target['VariableID'];

            if ($scriptID > 0 && IPS_ScriptExists($scriptID)) {
                // Parameternamen bewusst wie im bisherigen Ansageskript.
                @IPS_RunScriptEx($scriptID, ['Titel' => $target['Title'], 'Text' => $text]);
                $spoken++;
            } elseif ($variableID > 0 && IPS_VariableExists($variableID)) {
                @RequestAction($variableID, $text);
                $spoken++;
            }
        }

        $this->SendDebug("Sprache", "Gesprochen ueber $spoken Ziel(e): $text", 0);
    }

    private function IsQuietTime(): bool {
        if (!$this->ReadPropertyBoolean("QuietEnabled")) return false;

        $start = $this->ParseTime($this->ReadPropertyString("QuietStart"));
        $end   = $this->ParseTime($this->ReadPropertyString("QuietEnd"));
        if ($start == $end) return false;

        $now = (int)date("H") * 3600 + (int)date("i") * 60;

        // Ruhezeit laeuft in der Regel ueber Mitternacht.
        if ($start < $end) return ($now >= $start && $now < $end);
        return ($now >= $start || $now < $end);
    }

    private function ParseTime(string $time): int {
        $parts = explode(":", trim($time));
        $h = isset($parts[0]) ? (int)$parts[0] : 0;
        $m = isset($parts[1]) ? (int)$parts[1] : 0;
        if ($h < 0 || $h > 23) $h = 0;
        if ($m < 0 || $m > 59) $m = 0;
        return $h * 3600 + $m * 60;
    }

    // ------------------------------------------------------------------
    // Infrastruktur
    // ------------------------------------------------------------------

    private function UpdateStatusVariables() {
        $items = $this->GetItems();
        $this->SetValue("OpenCount", count($items));
        $this->SetValue("QuietNow", $this->IsQuietTime());

        if (count($items) == 0) {
            $this->SetValue("Summary", "Nichts zu tun.");
            return;
        }

        $lines = [];
        foreach ($this->SortItems($items) as $item) {
            $lines[] = $this->PriorityLabel($item['priority']) . ": " . $item['text'];
        }
        $this->SetValue("Summary", implode("\n", $lines));
    }

    private function PriorityLabel(int $priority): string {
        switch ($priority) {
            case self::PRIO_KRITISCH: return "Kritisch";
            case self::PRIO_WICHTIG:  return "Wichtig";
            case self::PRIO_NIEDRIG:  return "Niedrig";
            default:                  return "Normal";
        }
    }

    private function GetItems(): array {
        $items = json_decode($this->ReadAttributeString("Items"), true);
        return is_array($items) ? $items : [];
    }

    private function PutItems(array $items) {
        $this->WriteAttributeString("Items", json_encode($items));
    }

    private function GetRendered(): array {
        $rendered = json_decode($this->ReadAttributeString("Rendered"), true);
        return is_array($rendered) ? $rendered : [];
    }

    private function PutRendered(array $rendered) {
        $this->WriteAttributeString("Rendered", json_encode($rendered));
    }

    private function GetCategories(): array {
        $list = json_decode($this->ReadPropertyString("Categories"), true);
        $result = [];
        if (is_array($list)) {
            foreach ($list as $entry) {
                if (!isset($entry['Key']) || $entry['Key'] === "") continue;
                $result[$entry['Key']] = $entry;
            }
        }
        return $result;
    }

    private function GetVoiceTargets(): array {
        $list = json_decode($this->ReadPropertyString("VoiceTargets"), true);
        $result = [];
        if (is_array($list)) {
            foreach ($list as $entry) {
                $result[] = [
                    'Key'        => isset($entry['Key']) ? $entry['Key'] : "",
                    'Title'      => isset($entry['Title']) && $entry['Title'] !== "" ? $entry['Title'] : "Hinweis",
                    'ScriptID'   => isset($entry['ScriptID']) ? (int)$entry['ScriptID'] : 0,
                    'VariableID' => isset($entry['VariableID']) ? (int)$entry['VariableID'] : 0,
                    'Enabled'    => isset($entry['Enabled']) ? (bool)$entry['Enabled'] : true
                ];
            }
        }
        return $result;
    }

    /**
     * Optionswert bestimmen: neue Angabe schlaegt bestehenden Wert,
     * bestehender Wert schlaegt Vorgabe.
     */
    private function Opt(array $opt, string $key, $existing, string $field, $default) {
        if (array_key_exists($key, $opt)) return $opt[$key];
        if (is_array($existing) && array_key_exists($field, $existing)) return $existing[$field];
        return $default;
    }

    /**
     * Idents duerfen in IP-Symcon nur Buchstaben, Ziffern und Unterstrich
     * enthalten und nicht mit einer Ziffer beginnen.
     */
    private function NormalizeIdent(string $ident): string {
        $ident = preg_replace('/[^A-Za-z0-9_]/', '_', trim($ident));
        if ($ident === "") return "";
        if (preg_match('/^[0-9]/', $ident)) $ident = "T" . $ident;
        return $ident;
    }

    private function GetAckScriptID(): int {
        $id = @IPS_GetObjectIDByIdent("AckScript", $this->InstanceID);
        return ($id === false) ? 0 : $id;
    }

    /**
     * Hilfsskript, das eine angetippte Kachel an das Modul zurueckmeldet.
     */
    private function CreateAckScript() {
        $id = @IPS_GetObjectIDByIdent("AckScript", $this->InstanceID);
        if ($id === false) {
            $id = IPS_CreateScript(0);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, "AckScript");
            IPS_SetName($id, "Quittieren");
            IPS_SetHidden($id, true);
            IPS_SetPosition($id, 100);
        }

        $content = "<?php\n" .
                   "// Wird beim Antippen einer ToDo-Kachel aufgerufen.\n" .
                   "TODO_AcknowledgeByVariable(IPS_GetParent(\$_IPS['SELF']), \$_IPS['VARIABLE']);\n";
        if (IPS_GetScriptContent($id) !== $content) {
            IPS_SetScriptContent($id, $content);
        }
    }

    private function CreateProfiles() {
        if (!IPS_VariableProfileExists("TODO.Anzahl")) {
            IPS_CreateVariableProfile("TODO.Anzahl", VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon("TODO.Anzahl", "Notification");
            IPS_SetVariableProfileValues("TODO.Anzahl", 0, 999, 1);
        }
    }
}
?>
