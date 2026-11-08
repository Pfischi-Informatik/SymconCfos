<?php

declare(strict_types=1);

require_once __DIR__ . '/logic.php';

/**
 * cFos Charging Manager ueber die lokale HTTP-API.
 * Quellen: cfos-emobility.de/en/cfos-power-brain/http-api.htm und modbus-registers.htm
 */
class cFosWallbox extends IPSModule
{
    // ====================================================================
    // Symcon
    // ====================================================================

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('DevId', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Interval', 5);
        $this->RegisterPropertyInteger('MaxCurrent', 0);
        $this->RegisterPropertyFloat('Voltage', 230.0);
        $this->RegisterPropertyInteger('DefaultPhases', 3);
        $this->RegisterPropertyBoolean('PhaseSwitch', false);
        $this->RegisterPropertyInteger('PhaseHold', 600);
        $this->RegisterPropertyInteger('StartStopHold', 120);

        $this->RegisterAttributeBoolean('Initialized', false);
        $this->RegisterAttributeString('BaseKey', '');
        $this->RegisterAttributeString('Day', '');
        $this->RegisterAttributeInteger('DayStart', 0);
        $this->RegisterAttributeInteger('SessionStart', -1);
        $this->RegisterAttributeInteger('LastState', 0);
        $this->RegisterAttributeInteger('LastCommand', -1);
        $this->RegisterAttributeInteger('LastSent', 0);
        $this->RegisterAttributeInteger('LastChange', 0);
        $this->RegisterAttributeInteger('LastPhases', 0);
        $this->RegisterAttributeInteger('PhaseTarget', 0);
        $this->RegisterAttributeInteger('LastPhaseSwitch', 0);

        $this->RegisterTimer('CfosUpdate', 0, 'CFOS_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->createProfiles();

        $this->RegisterVariableInteger('Mode', 'Modus', 'CFOS.Mode', 1);
        $this->RegisterVariableInteger('ImmediateCurrent', 'Sofort-Strom', 'CFOS.Ampere', 2);
        $this->RegisterVariableFloat('ImmediateTarget', 'Sofort-kWh-Ziel (0 = kein Limit)', '~Electricity', 3);
        $this->RegisterVariableInteger('TargetPower', 'Leistung (Soll)', 'CFOS.Watt', 4);
        $this->RegisterVariableInteger('Power', 'Leistung (Ist)', 'CFOS.Watt', 5);
        $this->RegisterVariableFloat('CurrentL1', 'Strom L1', '~Ampere', 6);
        $this->RegisterVariableFloat('CurrentL2', 'Strom L2', '~Ampere', 7);
        $this->RegisterVariableFloat('CurrentL3', 'Strom L3', '~Ampere', 8);
        $this->RegisterVariableInteger('ActivePhases', 'Aktive Phasen', '', 9);
        $this->RegisterVariableInteger('State', 'Status', 'CFOS.State', 10);
        $this->RegisterVariableFloat('EnergySession', 'Energie Ladung', '~Electricity', 11);
        $this->RegisterVariableFloat('EnergyToday', 'Energie heute', '~Electricity', 12);
        $this->RegisterVariableFloat('EnergyTotal', 'Energie gesamt', '~Electricity', 13);
        $this->RegisterVariableInteger('TotalLimit', 'Summenlimit Zuleitung', 'CFOS.Ampere', 14);
        foreach (['Mode', 'ImmediateCurrent', 'ImmediateTarget', 'TargetPower', 'TotalLimit'] as $ident) {
            $this->EnableAction($ident);
        }

        if (!$this->ReadAttributeBoolean('Initialized')) {
            $this->WriteAttributeBoolean('Initialized', true);
            $this->SetValue('Mode', CfosLogic::MODE_SURPLUS);
            $this->SetValue('ImmediateCurrent', 16);
        }

        $host = $this->ReadPropertyString('Host');
        $dev = $this->ReadPropertyString('DevId');
        if ("$host|$dev" !== $this->ReadAttributeString('BaseKey')) {
            $this->WriteAttributeString('BaseKey', "$host|$dev");
            $this->WriteAttributeString('Day', '');
            $this->WriteAttributeInteger('SessionStart', -1);
            $this->WriteAttributeInteger('LastState', 0);
            $this->WriteAttributeInteger('LastCommand', -1);
        }

        if ($host === '' || $dev === '') {
            $this->SetTimerInterval('CfosUpdate', 0);
            $this->SetStatus(104);
            return;
        }
        $this->SetTimerInterval('CfosUpdate', max(2, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $current = $this->ReadPropertyString('DevId');
        $options = [];
        if ($this->ReadPropertyString('Host') !== '') {
            foreach ($this->fetch()['devices'] ?? [] as $d) {
                if (!empty($d['is_evse'])) {
                    $options[] = ['caption' => $d['dev_id'] . ' ' . ($d['name'] ?? ''), 'value' => $d['dev_id']];
                }
            }
        }
        if ($current !== '' && !in_array($current, array_column($options, 'value'), true)) {
            $options[] = ['caption' => $current, 'value' => $current];
        }
        if ($options !== []) {
            foreach ($form['elements'] as &$element) {
                if ($element['name'] === 'DevId') {
                    $element['options'] = $options;
                }
            }
        }
        return (string) json_encode($form);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Mode':
                $this->SetValue('Mode', max(0, min(2, (int) $Value)));
                break;
            case 'ImmediateCurrent':
                $this->SetValue('ImmediateCurrent', max(6, min(63, (int) $Value)));
                break;
            case 'ImmediateTarget':
                $this->SetValue('ImmediateTarget', max(0.0, (float) $Value));
                break;
            case 'TargetPower':
                $this->SetValue('TargetPower', max(0, (int) $Value));
                break;
            case 'TotalLimit':
                $this->setTotalLimit(max(6, min(63, (int) $Value)));
                return;
            default:
                throw new Exception("Unbekannte Aktion: $Ident");
        }
        $this->Update();
    }

    public function Update(): bool
    {
        $info = $this->fetch();
        if ($info === null) {
            return false;
        }
        $dev = $this->ReadPropertyString('DevId');
        foreach ($info['devices'] ?? [] as $d) {
            if (($d['dev_id'] ?? '') === $dev) {
                $this->SetStatus(102);
                $this->process($d, $info['params'] ?? []);
                return true;
            }
        }
        $this->SetStatus(202);
        return false;
    }

    // ====================================================================
    // Ablauf
    // ====================================================================

    private function process(array $d, array $params): void
    {
        $state = (int) $d['state'];
        $total = (int) $d['total_energy'];
        $lastState = $this->ReadAttributeInteger('LastState');

        $energy = CfosLogic::trackEnergy([
            'lastState' => $lastState,
            'sessionStart' => $this->ReadAttributeInteger('SessionStart'),
            'dayStart' => $this->ReadAttributeInteger('DayStart'),
            'day' => $this->ReadAttributeString('Day'),
        ], $state, $total, date('Y-m-d', $this->now()));
        $this->WriteAttributeInteger('LastState', $energy['lastState']);
        $this->WriteAttributeInteger('SessionStart', $energy['sessionStart']);
        $this->WriteAttributeInteger('DayStart', $energy['dayStart']);
        $this->WriteAttributeString('Day', $energy['day']);

        $this->SetValue('State', $state);
        $this->SetValue('Power', (int) $d['power_w']);
        foreach ([1, 2, 3] as $l) {
            $this->SetValue("CurrentL$l", round($d["current_l$l"] / 1000, 2));
        }
        $bits = (int) $d['phases'];
        $this->SetValue('ActivePhases', CfosLogic::phaseCount($bits));
        $this->SetValue('EnergySession', round($energy['sessionWh'] / 1000, 3));
        $this->SetValue('EnergyToday', round($energy['todayWh'] / 1000, 3));
        $this->SetValue('EnergyTotal', round($total / 1000, 3));
        if (is_numeric($params['max_total_evse_current'] ?? null)) {
            $this->SetValue('TotalLimit', (int) ($params['max_total_evse_current'] / 1000));
        }

        $mode = CfosLogic::nextMode($this->GetValue('Mode'), $lastState, $state, $energy['sessionWh'] / 1000, $this->GetValue('ImmediateTarget'));
        $this->SetValue('Mode', $mode);

        if (!CfosLogic::plugged($state)) {
            $this->WriteAttributeInteger('LastCommand', -1);
            $this->WriteAttributeInteger('PhaseTarget', 0);
            return;
        }
        $this->control($d, $mode, $bits);
    }

    private function control(array $d, int $mode, int $bits): void
    {
        $phases = CfosLogic::resolvePhases($bits, $this->ReadAttributeInteger('LastPhases'), $this->ReadPropertyInteger('DefaultPhases'));
        $this->WriteAttributeInteger('LastPhases', $phases);
        $watts = $this->GetValue('TargetPower');
        $this->switchPhases($d, $mode, $watts, $phases);

        $min = CfosLogic::minMilliamps($d['min_charging_cur'] ?? null);
        $max = CfosLogic::maxMilliamps($d['max_charging_cur'] ?? null, $this->ReadPropertyInteger('MaxCurrent'));
        $command = CfosLogic::command($mode, $watts, $this->GetValue('ImmediateCurrent'), $phases, $this->ReadPropertyFloat('Voltage'), $min, $max);
        $last = $this->ReadAttributeInteger('LastCommand');
        if ($mode === CfosLogic::MODE_SURPLUS) {
            $command = CfosLogic::holdCommand($command, $last, $this->now(), $this->ReadAttributeInteger('LastChange'), $this->ReadPropertyInteger('StartStopHold'));
        }
        if (!CfosLogic::shouldSend($command, $last, $this->now(), $this->ReadAttributeInteger('LastSent'))) {
            return;
        }

        $dev = urlencode($this->ReadPropertyString('DevId'));
        if ($last === -1 && $this->http("override_device&dev_id=$dev&flags=E") === null) {
            return;
        }
        $flags = $command === 0 ? 'flags=C' : "flags=c&mamps=$command";
        if ($this->http("override_device&dev_id=$dev&$flags") === null) {
            return; // LastCommand bleibt, der naechste Lauf sendet neu
        }
        if ($last === -1 || ($command === 0) !== ($last === 0)) {
            $this->WriteAttributeInteger('LastChange', $this->now());
        }
        $this->WriteAttributeInteger('LastCommand', $command);
        $this->WriteAttributeInteger('LastSent', $this->now());
    }

    private function switchPhases(array $d, int $mode, int $watts, int $phases): void
    {
        if (!$this->ReadPropertyBoolean('PhaseSwitch') || ($d['address'] ?? '') !== 'evse:' || empty($d['evse']['phase_switch_allowed'])) {
            return;
        }
        $known = $this->ReadAttributeInteger('PhaseTarget') ?: $phases;
        $wanted = CfosLogic::wantedPhases($mode, $watts, $known);
        if ($wanted === $known || $this->now() - $this->ReadAttributeInteger('LastPhaseSwitch') < $this->ReadPropertyInteger('PhaseHold')) {
            return;
        }
        $value = $wanted === 1 ? 1 : 0;
        if ($this->http("modbus&device=evse&write=8087&value=$value") !== null) {
            $this->WriteAttributeInteger('PhaseTarget', $wanted);
            $this->WriteAttributeInteger('LastPhaseSwitch', $this->now());
        }
    }

    private function setTotalLimit(int $amps): void
    {
        if ($amps === $this->GetValue('TotalLimit')) {
            return;
        }
        if ($this->http('set_params', (string) json_encode(['max_total_evse_current' => $amps * 1000])) === null) {
            throw new Exception('Summenlimit wurde nicht uebernommen');
        }
        $this->SetValue('TotalLimit', $amps);
    }

    // ====================================================================
    // HTTP
    // ====================================================================

    private function fetch(): ?array
    {
        $body = $this->http('get_dev_info');
        $data = $body === null ? null : json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    protected function now(): int
    {
        return time();
    }

    protected function http(string $query, ?string $post = null): ?string
    {
        $ch = curl_init('http://' . $this->ReadPropertyString('Host') . '/cnf?cmd=' . $query);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
        $password = $this->ReadPropertyString('Password');
        if ($password !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, 'admin:' . $password);
        }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->SendDebug('HTTP', "$query -> $code", 0);
        if ($code === 401 || $code === 403) {
            $this->SetStatus(203);
            return null;
        }
        if ($body === false || $code !== 200) {
            $this->SetStatus(201);
            return null;
        }
        return (string) $body;
    }

    // ====================================================================
    // Profile
    // ====================================================================

    private function createProfiles(): void
    {
        $this->profile('CFOS.Mode', [0 => 'Aus', 1 => 'Ueberschuss', 2 => 'Sofort']);
        $this->profile('CFOS.State', [1 => 'Bereit', 2 => 'Fahrzeug erkannt', 3 => 'Laedt', 4 => 'Laedt (Lueftung)', 5 => 'Fehler', 6 => 'Offline']);
        foreach (['CFOS.Ampere' => [' A', 6, 63, 1], 'CFOS.Watt' => [' W', 0, 0, 0]] as $name => [$suffix, $min, $max, $step]) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
                IPS_SetVariableProfileText($name, '', $suffix);
                IPS_SetVariableProfileValues($name, $min, $max, $step);
            }
        }
    }

    private function profile(string $name, array $values): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            foreach ($values as $value => $caption) {
                IPS_SetVariableProfileAssociation($name, $value, $caption, '', -1);
            }
        }
    }
}
