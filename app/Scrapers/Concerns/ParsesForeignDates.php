<?php

namespace App\Scrapers\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Best-effort date parsing for the handful of non-standard formats the source
 * sites use. Every method returns null on anything unparseable instead of
 * throwing — a single malformed date must never abort a scrape.
 */
trait ParsesForeignDates
{
    /**
     * ASP.NET's `/Date(1612148400000)/` wire format (Cuenta DNI).
     */
    protected function parseDotNetDate(?string $value): ?DateTimeInterface
    {
        if ($value === null || ! preg_match('/\/Date\((-?\d+)\)\//', $value, $matches)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestampMs((int) $matches[1]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Free-text Spanish dates like "31 de julio 2026" (Ualá).
     */
    protected function parseSpanishDate(?string $value): ?DateTimeInterface
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $months = [
            'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
            'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
            'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
            'noviembre' => 11, 'diciembre' => 12,
        ];

        if (! preg_match('/(\d{1,2})\s+de\s+([a-záéíóúñ]+)\s+(\d{4})/iu', $value, $matches)) {
            return null;
        }

        $month = $months[mb_strtolower($matches[2])] ?? null;

        if ($month === null) {
            return null;
        }

        try {
            return CarbonImmutable::create((int) $matches[3], $month, (int) $matches[1])?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Machine-readable dates (ISO-8601 and similar) that a source already
     * hands us cleanly — still guarded, since "clean" sources occasionally
     * send an empty string or a placeholder.
     */
    protected function parseIsoDate(?string $value): ?DateTimeInterface
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
