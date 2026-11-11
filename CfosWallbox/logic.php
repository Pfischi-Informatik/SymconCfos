<?php

declare(strict_types=1);

/** Regeln der cFos Wallbox ohne Abhaengigkeit von Symcon. */
final class CfosLogic
{
    public const MODE_OFF = 0;
    public const MODE_SURPLUS = 1;
    public const MODE_IMMEDIATE = 2;

    private const PHASE_DOWN_W = 4140;
    private const PHASE_UP_W = 4440;
    private const RESEND_SECONDS = 60;
    private const FALLBACK_MAX_MA = 16000;
    private const MIN_MA = 6000;

    public static function plugged(int $state): bool
    {
        return $state >= 2 && $state <= 4;
    }

    public static function phaseCount(int $bits): int
    {
        return ($bits & 1) + (($bits >> 1) & 1) + (($bits >> 2) & 1);
    }

    public static function resolvePhases(int $bits, int $last, int $default): int
    {
        $count = self::phaseCount($bits);
        return $count > 0 ? $count : ($last > 0 ? $last : $default);
    }

    /** $boxMax kann laut cFos auch eine Formel sein. */
    public static function maxMilliamps(mixed $boxMax, int $limitA): int
    {
        if (!is_numeric($boxMax)) {
            return $limitA > 0 ? $limitA * 1000 : self::FALLBACK_MAX_MA;
        }
        return $limitA > 0 ? min((int) $boxMax, $limitA * 1000) : (int) $boxMax;
    }

    /** Unter 6 A laedt kein Fahrzeug, fehlende Werte zaehlen als 6 A. */
    public static function minMilliamps(mixed $boxMin): int
    {
        return is_numeric($boxMin) ? max(self::MIN_MA, (int) $boxMin) : self::MIN_MA;
    }

    /** Haelt Wechsel zwischen Laden und Pause zurueck, damit das Schuetz nicht taktet. */
    public static function holdCommand(int $command, int $last, int $now, int $lastChange, int $hold): int
    {
        if ($last >= 0 && ($command === 0) !== ($last === 0) && $now - $lastChange < $hold) {
            return $last;
        }
        return $command;
    }

    /** 0 bedeutet: unter Minimalstrom, also pausieren. */
    public static function wattsToMilliamps(int $watts, int $phases, float $voltage, int $minMa, int $maxMa): int
    {
        if ($watts <= 0 || $phases <= 0 || $voltage <= 0) {
            return 0;
        }
        $ma = (int) floor($watts / ($voltage * $phases)) * 1000;
        return $ma < $minMa ? 0 : min($ma, $maxMa);
    }

    /** Modus des Energie-Managers nach Beschriftung, null = unbekannt. */
    public static function optimizerMode(string $caption): ?int
    {
        $c = mb_strtolower(trim($caption));
        foreach (['inaktiv' => self::MODE_OFF, 'inactive' => self::MODE_OFF, 'aus' => self::MODE_OFF, 'off' => self::MODE_OFF,
            'auto' => self::MODE_SURPLUS, 'über' => self::MODE_SURPLUS, 'ueber' => self::MODE_SURPLUS, 'surplus' => self::MODE_SURPLUS,
            'manu' => self::MODE_IMMEDIATE, 'aktiv' => self::MODE_IMMEDIATE, 'active' => self::MODE_IMMEDIATE, 'on' => self::MODE_IMMEDIATE] as $prefix => $mode) {
            if (str_starts_with($c, $prefix)) {
                return $mode;
            }
        }
        return null;
    }

    public static function command(int $mode, int $watts, int $immediateA, int $phases, float $voltage, int $minMa, int $maxMa): int
    {
        return match ($mode) {
            self::MODE_SURPLUS => self::wattsToMilliamps($watts, $phases, $voltage, $minMa, $maxMa),
            self::MODE_IMMEDIATE => max($minMa, min($immediateA * 1000, $maxMa)),
            default => 0,
        };
    }

    public static function wantedPhases(int $mode, int $watts, int $current): int
    {
        if ($mode === self::MODE_IMMEDIATE) {
            return 3;
        }
        if ($mode !== self::MODE_SURPLUS || $watts <= 0) {
            return $current;
        }
        if ($watts < self::PHASE_DOWN_W) {
            return 1;
        }
        return $watts >= self::PHASE_UP_W ? 3 : $current;
    }

    public static function nextMode(int $mode, int $lastState, int $state, float $sessionKwh, float $targetKwh): int
    {
        if (self::plugged($lastState) && $state === 1) {
            return self::MODE_SURPLUS;
        }
        if ($mode === self::MODE_IMMEDIATE && $targetKwh > 0 && $sessionKwh >= $targetKwh) {
            return self::MODE_SURPLUS;
        }
        return $mode;
    }

    public static function shouldSend(int $command, int $lastCommand, int $now, int $lastSent): bool
    {
        return $command !== $lastCommand || $now - $lastSent >= self::RESEND_SECONDS;
    }

    public static function trackEnergy(array $s, int $state, int $totalWh, string $today): array
    {
        // Fehler (5), offline (6) und Nullwerte sagen nichts ueber das Fahrzeug aus
        if ($state >= 1 && $state <= 4 && $totalWh > 0) {
            if ($s['sessionStart'] < 0 || $totalWh < $s['sessionStart'] || ($s['lastState'] === 1 && self::plugged($state))) {
                $s['sessionStart'] = $totalWh;
            }
            if ($s['day'] !== $today || $totalWh < $s['dayStart']) {
                $s['day'] = $today;
                $s['dayStart'] = $totalWh;
            }
        }
        if ($state >= 1 && $state <= 4) {
            $s['lastState'] = $state;
        }
        $s['sessionWh'] = $totalWh > 0 && $s['sessionStart'] >= 0 ? max(0, $totalWh - $s['sessionStart']) : 0;
        $s['todayWh'] = $totalWh > 0 && $s['day'] === $today ? max(0, $totalWh - $s['dayStart']) : 0;
        return $s;
    }
}
