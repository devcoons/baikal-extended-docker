<?php

declare(strict_types=1);

namespace BaikalExt;

/**
 * Parses the many shapes a vCard BDAY value can take into month/day/year.
 *
 * Handles, among others:
 *   19850412            (basic date)
 *   1985-04-12          (extended date)
 *   1985-04-12T09:30:00 (date-time, time ignored)
 *   --0412 / --04-12    (recurring birthday, year omitted)
 *   20000229            (leap day)
 */
final class BirthdayParser
{
    /**
     * @return array{month:int,day:int,year:int|null}|null
     */
    public static function parse(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Drop any time component.
        if (($tPos = strpos($value, 'T')) !== false) {
            $value = substr($value, 0, $tPos);
        }

        // Year omitted: --MMDD or --MM-DD
        if (preg_match('/^--(\d{2})-?(\d{2})$/', $value, $m) === 1) {
            return self::make((int) $m[1], (int) $m[2], null);
        }

        // Full date: YYYYMMDD or YYYY-MM-DD
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $value, $m) === 1) {
            $year = (int) $m[1];

            return self::make((int) $m[2], (int) $m[3], $year > 0 ? $year : null);
        }

        // Year + month only, or other partials we cannot turn into a day: give up.
        return null;
    }

    /**
     * @return array{month:int,day:int,year:int|null}|null
     */
    private static function make(int $month, int $day, ?int $year): ?array
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        // Validate using a leap year so Feb 29 (year-less) is accepted.
        if (!checkdate($month, $day, $year ?? 2000)) {
            return null;
        }

        return ['month' => $month, 'day' => $day, 'year' => $year];
    }
}
