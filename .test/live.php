<?php

declare(strict_types=1);

/**
 * Live-Test ohne Symcon. Stellt IPSModule und die IPS-Funktionen minimal nach.
 * Nach test/live.php kopieren, unten Modulpfad, Klasse und Pruefungen anpassen.
 *
 *   php -d zend.assertions=1 -d assert.exception=1 test/live.php [args]
 *   DEBUG=1 ... zeigt SendDebug-Ausgaben
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KL_MESSAGE = 10206;
const KL_WARNING = 10;
const KL_ERROR = 20;
const KR_READY = 10103;
function IPS_VariableProfileExists($n) { global $profiles; return isset($profiles[$n]); }
function IPS_CreateVariableProfile($n, $t) { global $profiles; $profiles[$n] = []; }
function IPS_DeleteVariableProfile($n) { global $profiles; unset($profiles[$n]); }
function IPS_SetVariableProfileAssociation($n, $v, $name, $i, $c) { global $profiles; $profiles[$n][$v] = $name; }
function IPS_SetVariableProfileValues($n, $min, $max, $step) {}
function IPS_SetVariableProfileText($n, $prefix, $suffix) {}
function IPS_SetVariableProfileIcon($n, $icon) {}
function IPS_GetKernelRunlevel() { return KR_READY; }

class IPSModule
{
    public $InstanceID = 12345;
    private $props = [], $attrs = [], $values = [];
    public $status = 0;
    public function Create(): void {}
    public function Destroy(): void {}
    public function ApplyChanges(): void {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyFloat($n, $v) { $this->props[$n] = $v; }
    public function SetProperty($n, $v) { $this->props[$n] = $v; }
    protected function ReadPropertyString($n) { return $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return $this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return $this->props[$n]; }
    protected function ReadPropertyFloat($n) { return $this->props[$n]; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function ReadAttributeString($n) { return $this->attrs[$n]; }
    public function ReadAttributeInteger($n) { return $this->attrs[$n]; }
    public function ReadAttributeBoolean($n) { return $this->attrs[$n]; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) {}
    protected function SetTimerInterval($n, $i) {}
    protected function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= false; }
    protected function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= 0; }
    protected function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= 0.0; }
    protected function RegisterVariableString($i, $n, $p = '', $pos = 0) { $this->values[$i] ??= ''; }
    protected function EnableAction($i) {}
    protected function SetValue($i, $v) { $this->values[$i] = $v; }
    public function GetValue($i) { return $this->values[$i]; }
    protected function SetStatus($s) { $this->status = $s; }
    protected function Translate($s) { return $s; }
    protected function LogMessage($m, $t) { fwrite(STDERR, "LOG: $m\n"); }
    protected function SendDebug($t, $m, $f) { if (getenv('DEBUG')) fwrite(STDERR, "DEBUG $t: " . substr((string) $m, 0, 400) . "\n"); }
}

// --- cFos ------------------------------------------------------------------

require __DIR__ . '/../CfosWallbox/module.php';

class TestBox extends cFosWallbox
{
    public ?array $device = null;   // null = echte Box, nur lesend
    public array $params = ['max_total_evse_current' => 16000];
    public array $cmds = [];
    public int $clock = 1794150338;
    public bool $down = false;

    protected function now(): int { return $this->clock; }

    protected function http(string $query, ?string $post = null): ?string
    {
        if ($this->device === null) {
            if ($query === 'get_dev_info') {
                return parent::http($query, $post);
            }
            $this->cmds[] = $query;
            return 'ok';
        }
        if ($this->down) {
            $this->SetStatus(201);
            return null;
        }
        if ($query === 'get_dev_info') {
            return json_encode(['params' => $this->params, 'devices' => [$this->device]]);
        }
        $this->cmds[] = $post === null ? $query : "$query $post";
        return 'ok';
    }

    public function take(): array { $c = $this->cmds; $this->cmds = []; return $c; }
}

function device(int $state, int $total, int $phases = 0, $max = 16000): array
{
    return ['dev_id' => 'E1', 'is_evse' => true, 'address' => 'evse:', 'state' => $state, 'total_energy' => $total,
        'power_w' => 0, 'current_l1' => 0, 'current_l2' => 0, 'current_l3' => 0, 'phases' => $phases,
        'min_charging_cur' => 6000, 'max_charging_cur' => $max, 'evse' => ['phase_switch_allowed' => true]];
}

function box(): TestBox
{
    $b = new TestBox();
    $b->Create();
    $b->SetProperty('Host', 'fake');
    $b->SetProperty('DevId', 'E1');
    $b->device = device(1, 1000000);
    $b->ApplyChanges();
    assert($b->status === 102);
    assert($b->Update());
    assert($b->GetValue('Mode') === CfosLogic::MODE_SURPLUS);
    assert($b->take() === [], 'ohne Fahrzeug keine Befehle');
    return $b;
}

// Ueberschuss, erneutes Senden
$b = box();
$b->device = device(2, 1000000);
$b->RequestAction('TargetPower', 99999);
assert($b->GetValue('TargetPower') === 11040, 'Sollwert auf Maximale Leistung begrenzt');

$b = box();
$b->device = device(2, 1000000);
$b->RequestAction('TargetPower', 4600);
assert($b->take() === ['override_device&dev_id=E1&flags=E', 'override_device&dev_id=E1&flags=c&mamps=6000']);
$b->clock += 59; $b->Update();
assert($b->take() === []);
$b->clock += 1; $b->Update();
assert($b->take() === ['override_device&dev_id=E1&flags=c&mamps=6000']);

// Unter Minimum pausieren
$b->RequestAction('TargetPower', 1000);
assert($b->take() === [], 'Haltezeit gegen Takten');
$b->clock += 60; $b->Update();
assert($b->take() === ['override_device&dev_id=E1&flags=C']);

// Sofort mit kWh-Ziel, Abstecken
$b->RequestAction('ImmediateCurrent', 10);
$b->RequestAction('ImmediateTarget', 1.0);
$b->take();
$b->RequestAction('Mode', CfosLogic::MODE_IMMEDIATE);
assert($b->take() === ['override_device&dev_id=E1&flags=c&mamps=10000']);
$b->device = device(3, 1001000, 7);
$b->Update();
assert($b->GetValue('Mode') === CfosLogic::MODE_SURPLUS, 'kWh-Ziel erreicht');
assert(abs($b->GetValue('EnergySession') - 1.0) < 0.001);

$b->RequestAction('ImmediateTarget', 0.0);
$b->RequestAction('Mode', CfosLogic::MODE_IMMEDIATE);
$b->device = device(6, 1001000);
$b->Update();
assert($b->GetValue('Mode') === CfosLogic::MODE_IMMEDIATE, 'offline ist kein Abstecken');
$b->device = device(1, 1001000);
$b->Update();
assert($b->GetValue('Mode') === CfosLogic::MODE_SURPLUS, 'Abstecken');

// Maximum als Formel
$b = box();
$b->device = device(2, 1000000, 0, 'M1.current');
$b->RequestAction('ImmediateCurrent', 32);
$b->take();
$b->RequestAction('Mode', CfosLogic::MODE_IMMEDIATE);
assert($b->take() === ['override_device&dev_id=E1&flags=c&mamps=16000'], 'Formel: nicht 63 A');

// Fremdgeraet ohne Mindeststrom, Summenlimit als Formel
$b = box();
$b->params = ['max_total_evse_current' => 'M1.current'];
$b->device = device(2, 1000000, 1);
unset($b->device['min_charging_cur']);
@$b->RequestAction('TargetPower', 700);
assert(!in_array('override_device&dev_id=E1&flags=c&mamps=1000', $b->take(), true), 'nie unter 6 A');

// Summenlimit nur bei Aenderung
$b = box();
$b->RequestAction('TotalLimit', 16);
assert($b->take() === []);
$b->RequestAction('TotalLimit', 20);
assert($b->take() === ['set_params {"max_total_evse_current":20000}']);

// Phasen: nur freigegeben, mit Haltezeit
$b = box();
$b->SetProperty('PhaseSwitch', true);
$b->ApplyChanges();
$b->device = device(3, 1000000, 7);
$b->RequestAction('TargetPower', 2000);
assert(in_array('modbus&device=evse&write=8087&value=1', $b->take(), true));
$b->RequestAction('TargetPower', 6000);
assert(!in_array('modbus&device=evse&write=8087&value=0', $b->take(), true), 'Haltezeit');
$b->clock += 600; $b->Update();
assert(in_array('modbus&device=evse&write=8087&value=0', $b->take(), true));

// Vor dem Laden: Phasenwunsch zaehlt, sonst blockiert 1P-Ueberschuss den Start
$b = box();
$b->SetProperty('PhaseSwitch', true);
$b->ApplyChanges();
$b->device = device(2, 1000000);
$b->RequestAction('TargetPower', 1380);
$c = $b->take();
assert(in_array('modbus&device=evse&write=8087&value=1', $c, true) && in_array('override_device&dev_id=E1&flags=c&mamps=6000', $c, true), '1P-Start bei unbekannten Phasen');

// Fremdbox schaltet keine Phasen
$b = box();
$b->SetProperty('PhaseSwitch', true);
$b->ApplyChanges();
$b->device = device(3, 1000000, 7);
$b->device['address'] = 'COM1,9600,8,n,1';
unset($b->device['evse']);
$b->RequestAction('TargetPower', 2000);
assert(!in_array('modbus&device=evse&write=8087&value=1', $b->take(), true));

// Host-Wechsel verwirft Startwerte
$b = box();
$b->device = device(2, 1000000);
$b->Update();
$b->device = device(3, 1005000);
$b->Update();
assert(abs($b->GetValue('EnergySession') - 5.0) < 0.001);
$b->SetProperty('Host', 'andere');
$b->ApplyChanges();
$b->device = device(1, 50);
$b->Update();
assert($b->GetValue('EnergySession') === 0.0 && $b->GetValue('EnergyToday') === 0.0);

// Ausfall
$b = box();
$b->down = true;
assert($b->Update() === false && $b->status === 201);
$b->down = false;
assert($b->Update() && $b->status === 102);

// Unbekannter Ladepunkt
$b = box();
$b->device['dev_id'] = 'E9';
assert($b->Update() === false && $b->status === 202);

// Echte Box, nur lesend: php ... test/live.php <host> <dev_id>
if (isset($argv[1])) {
    $b = new TestBox();
    $b->Create();
    $b->SetProperty('Host', $argv[1]);
    $b->SetProperty('DevId', $argv[2] ?? 'E1');
    $b->ApplyChanges();
    assert($b->Update(), 'Abfrage');
    foreach (['State', 'Power', 'ActivePhases', 'EnergyTotal', 'TotalLimit'] as $i) {
        echo $i, ' = ', var_export($b->GetValue($i), true), "\n";
    }
    echo 'Blockierte Befehle: ', count($b->cmds), "\n";
}
echo "OK\n";
