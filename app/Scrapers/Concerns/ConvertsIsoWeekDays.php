<?php

namespace App\Scrapers\Concerns;

use Illuminate\Support\Str;

/**
 * Every supermarket scraper ported from `proyecto-scrapping-super` inherits
 * that project's own day-of-week convention (ISO-8601 weekday numbers, 1 =
 * Monday ... 7 = Sunday, `null` meaning every day) — this converts it to
 * this app's own `valid_days` vocabulary (Spanish day names, or the
 * "Todos los días" sentinel), identical to what every wallet scraper
 * already produces.
 */
trait ConvertsIsoWeekDays
{
    /**
     * @var array<int, string>
     */
    private const array ISO_DAY_NAMES = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    /**
     * Unaccented, lowercase day name -> ISO number, for
     * `parseIsoWeekDaysFromSpanishText()`.
     *
     * @var array<string, int>
     */
    private const array SPANISH_DAY_NAMES = [
        'lunes' => 1,
        'martes' => 2,
        'miercoles' => 3,
        'jueves' => 4,
        'viernes' => 5,
        'sabado' => 6,
        'domingo' => 7,
    ];

    /**
     * @param  array<int, int>|null  $isoDays
     * @return string[]
     */
    protected function resolveValidDaysFromIso(?array $isoDays): array
    {
        if ($isoDays === null || $isoDays === []) {
            return ['Todos los días'];
        }

        $names = array_values(array_unique(array_filter(
            array_map(fn (int $day) => self::ISO_DAY_NAMES[$day] ?? null, $isoDays),
        )));

        // All 7 days listed individually means the same thing as `null` —
        // confirmed live on the Cencosud family's own feed.
        return count($names) === 7 ? ['Todos los días'] : $names;
    }

    /**
     * The reverse direction: Carrefour's own page never gives a structured
     * day list, only a free Spanish phrase ("Todos los Jueves de Agosto",
     * "Lunes y Miércoles de Agosto", "Martes a Domingo de Agosto") —
     * parses that into the same ISO-8601 weekday numbers
     * `resolveValidDaysFromIso()` expects. Returns `null` (every day) when
     * no specific day is mentioned at all.
     *
     * @return array<int, int>|null
     */
    protected function parseIsoWeekDaysFromSpanishText(string $text): ?array
    {
        $normalized = mb_strtolower(Str::ascii($text));

        if ($range = $this->parseIsoWeekDayRange($normalized)) {
            return $range;
        }

        $days = [];

        foreach (self::SPANISH_DAY_NAMES as $name => $iso) {
            if (preg_match('/\b'.$name.'\b/', $normalized) === 1) {
                $days[] = $iso;
            }
        }

        return $days !== [] ? array_values(array_unique($days)) : null;
    }

    /**
     * @return array<int, int>|null
     */
    private function parseIsoWeekDayRange(string $normalized): ?array
    {
        $names = implode('|', array_keys(self::SPANISH_DAY_NAMES));

        if (preg_match('/\b('.$names.')\b\s+a\s+\b('.$names.')\b/', $normalized, $matches) !== 1) {
            return null;
        }

        $start = self::SPANISH_DAY_NAMES[$matches[1]];
        $end = self::SPANISH_DAY_NAMES[$matches[2]];

        $days = [];
        $current = $start;

        while (true) {
            $days[] = $current;

            if ($current === $end) {
                break;
            }

            $current = $current === 7 ? 1 : $current + 1;
        }

        return array_values(array_unique($days));
    }
}
