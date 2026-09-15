<?php
// Standalone regression suite. No network, Symcon server or physical I/O.
define('VM_UPDATE', 10603);
$GLOBALS['locks'] = [];
$GLOBALS['devices'] = [];
$GLOBALS['commands'] = [];
$GLOBALS['onCommand'] = null;
$GLOBALS['drop'] = false;
$GLOBALS['throwCommand'] = false;
function IPS_SemaphoreEnter($name, $timeout) {
    if (!empty($GLOBALS['locks'][$name])) return false;
    $GLOBALS['locks'][$name] = true;
    return true;
}
function IPS_SemaphoreLeave($name) { $GLOBALS['locks'][$name] = false; }
function IPS_VariableExists($id) { return array_key_exists($id, $GLOBALS['devices']); }
function GetValueBoolean($id) { return (bool)$GLOBALS['devices'][$id]; }
function GetValue($id) { return $GLOBALS['devices'][$id]; }
function IPS_VariableProfileExists($name) { return true; }
function IPS_GetObjectIDByIdent($ident, $id) { return $ident === 'ScriptAn' ? 101 : 102; }
function IPS_SetScriptContent($id, $content) {}
function RequestAction($id, $state) {
    expect(empty($GLOBALS['locks']['BWMProxy.State.90000']), 'No state lock during device I/O');
    $GLOBALS['commands'][] = [$id, $state];
    if ($GLOBALS['throwCommand']) throw new RuntimeException('Simulated I/O exception');
    if (!$GLOBALS['drop']) $GLOBALS['devices'][$id] = $state;
    if ($GLOBALS['onCommand']) {
        $hook = $GLOBALS['onCommand'];
        $GLOBALS['onCommand'] = null;
        $hook();
    }
    return !$GLOBALS['drop'];
}
class IPSModule {
    public $InstanceID = 90000;
    public $properties = [];
    public $values = [];
    public $attributes = [];
    public $buffers = [];
    public $timers = [];
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyInteger($key, $value) { $this->properties[$key] = $value; }
    public function RegisterPropertyBoolean($key, $value) { $this->properties[$key] = $value; }
    public function RegisterPropertyString($key, $value) { $this->properties[$key] = $value; }
    public function RegisterAttributeInteger($key, $value) { $this->attributes[$key] = $value; }
    public function RegisterAttributeBoolean($key, $value) { $this->attributes[$key] = $value; }
    public function RegisterVariableBoolean($key, $name, $profile, $position) { $this->values[$key] = false; }
    public function RegisterVariableInteger($key, $name, $profile, $position) { $this->values[$key] = 0; }
    public function RegisterVariableString($key, $name, $profile, $position) { $this->values[$key] = ''; }
    public function RegisterTimer($key, $interval, $script) { $this->timers[$key] = $interval; }
    public function RegisterMessage($id, $message) {}
    public function EnableAction($key) {}
    public function ReadPropertyInteger($key) { return $this->properties[$key]; }
    public function ReadPropertyString($key) { return $this->properties[$key]; }
    public function ReadPropertyBoolean($key) { return $this->properties[$key]; }
    public function GetValue($key) { return $this->values[$key]; }
    public function SetValue($key, $value) { $this->values[$key] = $value; }
    public function ReadAttributeBoolean($key) { return $this->attributes[$key]; }
    public function WriteAttributeBoolean($key, $value) { $this->attributes[$key] = $value; }
    public function ReadAttributeInteger($key) { return $this->attributes[$key]; }
    public function WriteAttributeInteger($key, $value) { $this->attributes[$key] = $value; }
    public function SetBuffer($key, $value) { $this->buffers[$key] = $value; }
    public function GetBuffer($key) { return $this->buffers[$key] ?? ''; }
    public function SetTimerInterval($key, $value) { $this->timers[$key] = $value; }
    public function SendDebug($topic, $message, $format) {}
}
require dirname(__DIR__) . '/BewegungsmelderProxy/module.php';
class TestProxy extends BewegungsmelderProxy {
    public $time = 1000.0;
    protected function Now() { return $this->time; }
}
function expect($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function fixture() {
    $GLOBALS['locks'] = [];
    $GLOBALS['devices'] = [10001 => false, 10002 => false, 10003 => 5];
    $GLOBALS['commands'] = [];
    $GLOBALS['onCommand'] = null;
    $GLOBALS['drop'] = false;
    $GLOBALS['throwCommand'] = false;
    $m = new TestProxy();
    $m->Create();
    $m->properties = array_merge($m->properties, [
        'TargetLightID'=>10001, 'MotionSensors'=>'[{"VariableID":10002}]',
        'SourceBrightnessID'=>10003, 'Duration'=>300, 'Threshold'=>40
    ]);
    $m->ApplyChanges();
    return $m;
}
function motion($m, $value) {
    $GLOBALS['devices'][10002] = $value;
    $m->MessageSink(1, 10002, VM_UPDATE, [$value, true]);
}
function ack($m) { $m->MessageSink(2,10001,VM_UPDATE,[$GLOBALS['devices'][10001],true]); }
function turnOn($m) { motion($m,true); $m->DispatchSwitch(); ack($m); }
function expire($m) { $m->time += 301; $m->TimerEvent(); }
function verify($m) { $m->time += 5; $m->VerifySwitch(); }
function offCommands() { return count(array_filter($GLOBALS['commands'], function ($c) { return !$c[1]; })); }
$tests = [];
$tests['normal motion cycle'] = function () {
    $m=fixture(); turnOn($m); motion($m,false); expire($m); $m->DispatchSwitch(); ack($m);
    expect(!$GLOBALS['devices'][10001] && $m->timers['AutoOffTimer']===0, 'Normal cycle must switch off');
};
$tests['lost off retries and recovers'] = function () {
    $m=fixture(); turnOn($m); motion($m,false); expire($m);
    $GLOBALS['drop']=true; $m->DispatchSwitch(); verify($m);
    expect($m->timers['DispatchTimer']>0, 'Retry must be armed');
    $GLOBALS['drop']=false; $m->DispatchSwitch(); ack($m);
    expect(!$GLOBALS['devices'][10001] && offCommands()===2 && $m->values['SwitchError']==='', 'Second off should recover');
};
$tests['off failure bounded and visible'] = function () {
    $m=fixture(); turnOn($m); motion($m,false); expire($m); $GLOBALS['drop']=true;
    for ($i=0;$i<3;$i++) { $m->DispatchSwitch(); verify($m); }
    expect(offCommands()===3 && $m->values['SwitchError']!=='' && $m->timers['DispatchTimer']===0 && $m->timers['VerifyTimer']===0, 'Exactly three attempts then visible error');
};
$tests['public helper uses manual timer'] = function () {
    $m=fixture(); $m->SetLight(true); $m->DispatchSwitch(); ack($m);
    expect($m->timers['AutoOffTimer']===300000, 'Helper must start timer');
    expire($m); $m->DispatchSwitch(); expect(!$GLOBALS['devices'][10001], 'Helper light must turn off');
};
$tests['bright switch from always on to auto'] = function () {
    $m=fixture(); $GLOBALS['devices'][10003]=100; motion($m,true);
    $m->RequestAction('Mode',1); $m->DispatchSwitch(); ack($m); $m->RequestAction('Mode',0);
    expect($m->timers['AutoOffTimer']===300000, 'Existing bright light must retain bounded cycle');
    motion($m,false); expire($m); $m->DispatchSwitch(); expect(!$GLOBALS['devices'][10001], 'Must turn off after leaving');
};
$tests['stale motion cache is corrected'] = function () {
    $m=fixture(); $m->SetLight(true); $m->DispatchSwitch(); ack($m); $m->values['Motion']=true;
    expire($m); $m->DispatchSwitch();
    expect(!$m->values['Motion'] && !$GLOBALS['devices'][10001], 'Timer must read current sources');
};
$tests['presence continues in bright room'] = function () {
    $m=fixture(); turnOn($m); $GLOBALS['devices'][10003]=10000; expire($m);
    expect($GLOBALS['devices'][10001] && $m->timers['AutoOffTimer']===300000, 'Real presence must extend despite lux');
};
$tests['second presence sensor keeps light on'] = function () {
    $m=fixture(); $m->properties['MotionSensors']='[{"VariableID":10002},{"VariableID":10004}]';
    $GLOBALS['devices'][10004]=true; $m->SetLight(true); $m->DispatchSwitch(); expire($m);
    expect($GLOBALS['devices'][10001] && $m->values['Motion'], 'OR presence must be honored');
};
$tests['old queued timer cannot erase new deadline'] = function () {
    $m=fixture(); turnOn($m); $m->time+=299; motion($m,true); motion($m,false);
    $m->time+=2; $m->TimerEvent();
    expect($GLOBALS['devices'][10001] && $m->timers['AutoOffTimer']>=297000 && offCommands()===0, 'Old callback must not end renewed cycle');
};
$tests['motion during off I/O preserves newer cycle'] = function () {
    $m=fixture(); turnOn($m); motion($m,false); expire($m);
    $GLOBALS['onCommand']=function () use ($m) {
        motion($m,true);
        $m->DispatchSwitch(); // Concurrent worker: I/O semaphore must defer it.
    };
    $m->DispatchSwitch(); $m->DispatchSwitch(); ack($m); motion($m,false);
    expect($GLOBALS['devices'][10001] && $m->timers['AutoOffTimer']===300000, 'Old I/O completion must preserve newer on and timer');
    expire($m); $m->DispatchSwitch(); expect(!$GLOBALS['devices'][10001], 'New cycle must eventually switch off');
};
$tests['presence before automatic off dispatch cancels off'] = function () {
    $m=fixture(); turnOn($m); motion($m,false); expire($m);
    $GLOBALS['devices'][10002]=true; $m->DispatchSwitch(); $m->DispatchSwitch();
    expect(offCommands()===0 && $GLOBALS['devices'][10001] && $m->timers['AutoOffTimer']>0, 'Fresh presence wins even before its message arrives');
};
$tests['presence before off retry cancels retry'] = function () {
    $m=fixture(); turnOn($m); motion($m,false); expire($m); $GLOBALS['drop']=true; $m->DispatchSwitch();
    $GLOBALS['devices'][10002]=true; verify($m); $GLOBALS['drop']=false; $m->DispatchSwitch();
    expect(offCommands()===1 && $m->timers['AutoOffTimer']>0, 'Retry must not extinguish renewed presence');
};
$tests['manual off is not vetoed by presence'] = function () {
    $m=fixture(); turnOn($m); $m->SetLight(false); $m->DispatchSwitch();
    expect(!$GLOBALS['devices'][10001], 'Explicit off must work with presence true');
};
$tests['always off wins over presence and old timer'] = function () {
    $m=fixture(); turnOn($m); $m->RequestAction('Mode',2); $m->DispatchSwitch(); expire($m);
    expect(!$GLOBALS['devices'][10001] && $m->timers['AutoOffTimer']===0, 'Always off must remain off');
};
$tests['always on survives old auto callback'] = function () {
    $m=fixture(); turnOn($m); motion($m,false); $m->RequestAction('Mode',1); expire($m);
    expect($GLOBALS['devices'][10001] && $m->timers['AutoOffTimer']===0, 'Always on must remain on');
};
$tests['zero duration cannot disable safety timer'] = function () {
    $m=fixture(); $m->properties['Duration']=0; $m->SetLight(true); $m->DispatchSwitch();
    expect($m->timers['AutoOffTimer']===1000, 'Zero duration clamps to one second');
};
$tests['external on gets a bounded cycle'] = function () {
    $m=fixture(); $GLOBALS['devices'][10001]=true; ack($m);
    expect($m->timers['AutoOffTimer']===300000, 'Unexplained external on must get timer');
};
$tests['reload synchronizes motion and restores timer'] = function () {
    $m=fixture(); $m->values['Motion']=true; $GLOBALS['devices'][10001]=true; $m->ApplyChanges();
    expect(!$m->values['Motion'] && $m->timers['AutoOffTimer']===300000, 'Reload must sync source state');
};
$tests['failed on is bounded despite repeated motion'] = function () {
    $m=fixture(); $GLOBALS['drop']=true; motion($m,true);
    for ($i=0;$i<3;$i++) { $m->DispatchSwitch(); motion($m,true); verify($m); }
    expect(count($GLOBALS['commands'])===3 && $m->values['SwitchError']!=='' && $m->timers['AutoOffTimer']===0, 'Motion must not reset verification budget');
};
$tests['I/O exception leaves retry and releases locks'] = function () {
    $m=fixture(); $m->SetLight(true); $GLOBALS['throwCommand']=true; $m->DispatchSwitch(); verify($m);
    expect($m->timers['DispatchTimer']>0 && $m->values['SwitchError']!=='' && !array_filter($GLOBALS['locks']), 'Exception must not wedge state or I/O');
};
$tests['old verify callback respects new command deadline'] = function () {
    $m=fixture(); $m->SetLight(true); $m->DispatchSwitch(); $m->time+=4;
    $m->SetLight(false); $m->DispatchSwitch(); $m->time+=1; $m->VerifySwitch();
    expect($m->timers['VerifyTimer']===4000, 'Old verify must wait for new command');
};
$tests['delayed light message reads current value'] = function () {
    $m=fixture(); $GLOBALS['devices'][10001]=false; $m->MessageSink(1,10001,VM_UPDATE,[true,true]);
    expect(!$m->values['Status'] && $m->timers['AutoOffTimer']===0, 'Old payload must not restore obsolete on');
};
$tests['contradicting manual action leaves permanent mode'] = function () {
    $m=fixture(); $m->RequestAction('Mode',2); $m->SetLight(true); $m->DispatchSwitch();
    expect($m->values['Mode']===0 && $m->timers['AutoOffTimer']===300000, 'Manual on in always off must have bounded auto mode');
};
$tests['missing target is visible'] = function () {
    $m=fixture(); unset($GLOBALS['devices'][10001]); $m->SetLight(true);
    expect($m->values['SwitchError']!=='' && count($GLOBALS['commands'])===0, 'Missing target needs visible error');
};
foreach ($tests as $name=>$test) {
    $test();
    echo 'PASS ' . $name . PHP_EOL;
}
echo count($tests) . ' tests passed' . PHP_EOL;
