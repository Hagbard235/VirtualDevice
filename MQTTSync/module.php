<?php

class MQTTSync extends IPSModule {

    // GUIDs
    const GUID_MQTT_SEND = "{043EA491-0325-4ADD-8C59-7D1B96546575}";

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("GroupTopic", "symcon");
        $this->RegisterPropertyString("SyncItems", "[]");
        $this->RegisterPropertyBoolean("EnableSet", true);
        
        // Connect to parent handled by RequireParent in module.json
        // $this->ConnectParent("{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}");
        
        // Attributes for internal storage
        $this->RegisterAttributeString("RegisteredMessages", "[]");
        $this->RegisterAttributeString("TopicMap", "[]");
        $this->RegisterAttributeString("ReverseTopicMap", "[]");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        
        // 1. Unregister old messages
        $oldVars = json_decode($this->ReadAttributeString("RegisteredMessages"), true);
        if (is_array($oldVars)) {
            foreach ($oldVars as $vid) {
                $this->RegisterMessage($vid, 0);
            }
        }
        
        // 2. Build new map
        $rootList = json_decode($this->ReadPropertyString("SyncItems"), true);
        $prefix = $this->ReadPropertyString("GroupTopic");
        // Ensure prefix doesn't end with /
        $prefix = rtrim($prefix, '/');
        
        $map = []; // VarID -> Topic
        $revMap = []; // SetTopic -> VarID
        $registeredVars = [];

        if (is_array($rootList)) {
            foreach ($rootList as $item) {
                $id = $item['ObjectID'];
                if ($id <= 0) continue;
                
                $customTopic = isset($item['CustomTopic']) ? $item['CustomTopic'] : "";
                
                // Start traversal
                $this->TraverseAndMap($id, $prefix, $customTopic, $map, $revMap, $registeredVars);
            }
        }
        
        // 3. Register Messages & Save Map
        foreach ($registeredVars as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }
        
        $this->WriteAttributeString("RegisteredMessages", json_encode($registeredVars));
        $this->WriteAttributeString("TopicMap", json_encode($map));
        $this->WriteAttributeString("ReverseTopicMap", json_encode($revMap));
        
        // 4. Update ReceiveFilter
        if ($this->ReadPropertyBoolean("EnableSet")) {
            // We filter for topics starting with Prefix and ending with /set
            // Regex for JSON: "Topic":"prefix\/.*\/set"
            $escapedPrefix = str_replace('/', '\\/', $prefix);
            $filter = '.*"Topic":"' . $escapedPrefix . '\/.*\/set".*';
            $this->SetReceiveDataFilter($filter);
            $this->SendDebug("Filter", "Set Filter to: " . $filter, 0);
        } else {
             // Block all
             $this->SetReceiveDataFilter(".*IsThisValueImpossibleToMatchREALLY.*");
        }
    }
    
    private function TraverseAndMap($id, $parentPath, $pathOverride, &$map, &$revMap, &$registeredVars) {
        if (!IPS_ObjectExists($id)) return;
        
        $object = IPS_GetObject($id);
        
        // Determine Segment
        if ($pathOverride !== "") {
            $segment = $pathOverride;
        } else {
            $segment = IPS_GetName($id);
            // Sanitize: Replace spaces with underscore
            $segment = str_replace(' ', '_', $segment);
            // Replace dots?
            $segment = str_replace('.', '_', $segment);
        }
        
        $myTopic = $parentPath . "/" . $segment;
        
        if ($object['ObjectType'] == 2) { // Variable
            $map[$id] = $myTopic;
            $revMap[$myTopic . "/set"] = $id;
            $registeredVars[] = $id; // Only register variables
            
        } elseif ($object['ObjectType'] == 1 || $object['ObjectType'] == 0) { // Instance or Category
             $children = IPS_GetChildrenIDs($id);
             foreach ($children as $childID) {
                 $this->TraverseAndMap($childID, $myTopic, "", $map, $revMap, $registeredVars);
             }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message == VM_UPDATE) {
            $val = $Data[0];
            $map = json_decode($this->ReadAttributeString("TopicMap"), true);
            if (isset($map[$SenderID])) {
                $topic = $map[$SenderID];
                $this->Publish($topic, $val);
            }
        }
    }
    
    public function ReceiveData($JSONString) {
        $this->SendDebug("ReceiveData", $JSONString, 0);
        $data = json_decode($JSONString);
        
        if (!isset($data->Topic) || !isset($data->Payload)) return;
        
        $topic = $data->Topic;
        $payload = $data->Payload;
        
        $revMap = json_decode($this->ReadAttributeString("ReverseTopicMap"), true);
        
        if (isset($revMap[$topic])) {
            $id = $revMap[$topic];
            $this->SetValueSmart($id, $payload);
        }
    }
    
    private function SetValueSmart($id, $value) {
        if (!IPS_VariableExists($id)) return;
        
        $var = IPS_GetVariable($id);
        $targetVal = $value; // default
        
        // Type Conversion
        switch ($var['VariableType']) {
            case 0: // Boolean
                $v = strtolower((string)$value);
                $targetVal = ($v == "1" || $v == "true" || $v == "on");
                break;
            case 1: // Integer
                $targetVal = intval($value);
                break;
            case 2: // Float
                $targetVal = floatval($value);
                break;
            case 3: // String
                $targetVal = (string)$value;
                break;
        }
        
        $this->SendDebug("Action", "RequestAction($id, ".json_encode($targetVal).")", 0);
        RequestAction($id, $targetVal);
    }

    private function Publish($topic, $value) {
        if (is_bool($value)) $strValue = $value ? "true" : "false"; 
        elseif (is_float($value)) $strValue = str_replace(',', '.', (string)$value);
        else $strValue = (string)$value;
        
        $payload = json_encode([
            'DataID' => self::GUID_MQTT_SEND,
            'Topic' => $topic,
            'Payload' => $strValue,
            'Retain' => false
        ]);
        
        $this->SendDebug("Publish", "Topic: $topic, Val: $strValue", 0);
        $this->SendDataToParent($payload);
    }
    
    public function DumpTopics() {
        $map = json_decode($this->ReadAttributeString("TopicMap"), true);
        $rev = json_decode($this->ReadAttributeString("ReverseTopicMap"), true);
        
        echo "Registered Topics (Publication):\n";
        foreach ($map as $id => $topic) {
            echo "ID $id => $topic\n";
        }
        echo "\nListening Topics (Commands):\n";
        foreach ($rev as $topic => $id) {
            echo "$topic => ID $id\n";
        }
    }
}
?>
