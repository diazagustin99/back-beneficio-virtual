<?php

namespace App\Enums;

/**
 * The canonical Spanish day names every scraper normalizes `valid_days`
 * entries to (see `NaranjaXScraper::WEEKDAY_NAMES`, `ModoScraper::DAY_LETTERS`).
 */
enum Weekday: string
{
    case Monday = 'Lunes';
    case Tuesday = 'Martes';
    case Wednesday = 'Miércoles';
    case Thursday = 'Jueves';
    case Friday = 'Viernes';
    case Saturday = 'Sábado';
    case Sunday = 'Domingo';
}
