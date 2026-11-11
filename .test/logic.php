<?php

declare(strict_types=1);

require __DIR__ . '/../CfosWallbox/logic.php';

$L = CfosLogic::class;

// Phasen
assert($L::phaseCount(7) === 3 && $L::phaseCount(1) === 1 && $L::phaseCount(0) === 0);
assert($L::resolvePhases(1, 3, 3) === 1);
assert($L::resolvePhases(0, 1, 3) === 1);
assert($L::resolvePhases(0, 0, 3) === 3);

// Grenzen, auch Formel statt Zahl
assert($L::maxMilliamps(15600, 0) === 15600);
assert($L::maxMilliamps(16000, 10) === 10000);
assert($L::maxMilliamps('M1.current + 1000', 0) === 16000, 'Formel: vorsichtiger Standard');
assert($L::maxMilliamps(null, 0) === 16000);
assert($L::maxMilliamps('M1.current', 25) === 25000);
assert($L::minMilliamps(null) === 6000 && $L::minMilliamps('x') === 6000);
assert($L::minMilliamps(8000) === 8000 && $L::minMilliamps(1000) === 6000);

// Watt nach Milliampere
assert($L::wattsToMilliamps(4140, 3, 230.0, 6000, 16000) === 6000);
assert($L::wattsToMilliamps(1380, 1, 230.0, 6000, 16000) === 6000);
assert($L::wattsToMilliamps(1379, 1, 230.0, 6000, 16000) === 0);
assert($L::wattsToMilliamps(20000, 3, 230.0, 6000, 15600) === 15600);
assert($L::wattsToMilliamps(-5, 3, 230.0, 6000, 16000) === 0);

// Befehl je Modus (0 = aus)
assert($L::command(CfosLogic::MODE_OFF, 9000, 16, 3, 230.0, 6000, 16000) === 0);
assert($L::command(CfosLogic::MODE_SURPLUS, 4600, 16, 3, 230.0, 6000, 16000) === 6000);
assert($L::command(CfosLogic::MODE_IMMEDIATE, 0, 10, 3, 230.0, 6000, 16000) === 10000);
assert($L::command(CfosLogic::MODE_IMMEDIATE, 0, 40, 3, 230.0, 6000, 16000) === 16000);
assert($L::command(CfosLogic::MODE_IMMEDIATE, 0, 2, 3, 230.0, 6000, 16000) === 6000);

// Phasenwunsch mit Hysterese
assert($L::wantedPhases(CfosLogic::MODE_SURPLUS, 4000, 3) === 1);
assert($L::wantedPhases(CfosLogic::MODE_SURPLUS, 4300, 3) === 3);
assert($L::wantedPhases(CfosLogic::MODE_SURPLUS, 4300, 1) === 1);
assert($L::wantedPhases(CfosLogic::MODE_SURPLUS, 4440, 1) === 3);
assert($L::wantedPhases(CfosLogic::MODE_SURPLUS, 0, 3) === 3);
assert($L::wantedPhases(CfosLogic::MODE_IMMEDIATE, 0, 1) === 3);
assert($L::wantedPhases(CfosLogic::MODE_OFF, 9000, 1) === 1);

// Modus-Rueckfall
assert($L::optimizerMode('Überschuss') === 1);
assert($L::optimizerMode('Automatisch') === 1);
assert($L::optimizerMode('Manuell') === 2);
assert($L::optimizerMode('Aktiv') === 2);
assert($L::optimizerMode('Inaktiv') === 0);
assert($L::optimizerMode('Aus') === 0);
assert($L::optimizerMode('???') === null);
assert($L::nextMode(2, 3, 1, 0.0, 0.0) === 1);
assert($L::nextMode(2, 3, 6, 0.0, 0.0) === 2);
assert($L::nextMode(2, 3, 3, 5.0, 5.0) === 1);
assert($L::nextMode(2, 3, 3, 4.9, 5.0) === 2);
assert($L::nextMode(2, 3, 3, 99.0, 0.0) === 2);
assert($L::nextMode(0, 1, 2, 0.0, 0.0) === 0);

// Senden
assert($L::shouldSend(6000, 6000, 1059, 1000) === false);
assert($L::shouldSend(6000, 6000, 1060, 1000) === true);
assert($L::shouldSend(7000, 6000, 1001, 1000) === true);

// Energie
$s = ['lastState' => 0, 'sessionStart' => -1, 'dayStart' => 0, 'day' => ''];
$s = $L::trackEnergy($s, 1, 1000, '2026-11-08');
assert($s['sessionWh'] === 0 && $s['todayWh'] === 0);
$s = $L::trackEnergy($s, 2, 1500, '2026-11-08');
assert($s['sessionStart'] === 1500 && $s['sessionWh'] === 0 && $s['todayWh'] === 500);
$s = $L::trackEnergy($s, 3, 2500, '2026-11-08');
assert($s['sessionWh'] === 1000);
$s = $L::trackEnergy($s, 6, 2500, '2026-11-08');
$s = $L::trackEnergy($s, 3, 2600, '2026-11-08');
assert($s['sessionStart'] === 1500 && $s['lastState'] === 3, 'Ausfall ist kein neues Einstecken');
$s = $L::trackEnergy($s, 1, 2600, '2026-11-09');
assert($s['sessionWh'] === 1100 && $s['todayWh'] === 0, 'Ladung bleibt stehen, Tag neu');
$s = $L::trackEnergy($s, 1, 10, '2026-11-09');
assert($s['sessionWh'] === 0 && $s['todayWh'] === 0, 'nie negativ');

// Zaehlersprung nach unten: Basis nachziehen
$s = ['lastState' => 3, 'sessionStart' => 1000000, 'dayStart' => 1000000, 'day' => '2026-11-08'];
$s = $L::trackEnergy($s, 3, 50, '2026-11-08');
$s = $L::trackEnergy($s, 3, 900050, '2026-11-08');
assert($s['sessionWh'] === 900000 && $s['todayWh'] === 900000);

// Ungueltige Lesungen setzen keine Basis
$s = ['lastState' => 3, 'sessionStart' => 1000000, 'dayStart' => 1000000, 'day' => '2026-11-08'];
$s = $L::trackEnergy($s, 6, 0, '2026-11-09');
assert($s['dayStart'] === 1000000 && $s['day'] === '2026-11-08' && $s['sessionStart'] === 1000000);
$s = $L::trackEnergy($s, 3, 1000100, '2026-11-09');
assert($s['dayStart'] === 1000100 && $s['todayWh'] === 0 && $s['sessionWh'] === 100);
$s = $L::trackEnergy(['lastState' => 0, 'sessionStart' => -1, 'dayStart' => 0, 'day' => ''], 6, 5000, '2026-11-09');
assert($s['sessionWh'] === 0 && $s['todayWh'] === 0, 'Erststart offline');

// Start/Stopp-Haltezeit
assert($L::holdCommand(0, 6000, 1100, 1000, 120) === 6000);
assert($L::holdCommand(0, 6000, 1120, 1000, 120) === 0);
assert($L::holdCommand(6000, 0, 1100, 1000, 120) === 0);
assert($L::holdCommand(7000, 6000, 1001, 1000, 120) === 7000, 'Stromaenderung ohne Haltezeit');
assert($L::holdCommand(0, -1, 1001, 1000, 120) === 0, 'erster Befehl');

echo "OK\n";
