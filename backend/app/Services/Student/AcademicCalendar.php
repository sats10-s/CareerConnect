<?php

namespace App\Services\Student;

use App\Models\Batch;
use App\Models\Student;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

/**
 * Batch and current-year arithmetic for college-record students.
 *
 * The current academic year comes from the `academic_year` setting
 * ("2026-27" -> 2026); when that is unset it falls back to the calendar
 * (an academic year starts in July). A 2023 batch is then in year
 * 2026 - 2023 + 1 = 4.
 */
class AcademicCalendar
{
    public function __construct(private readonly SettingsService $settings) {}

    /** B.Tech only - M.Tech students are not imported, so every batch is four years. */
    public const PROGRAM_YEARS = 4;

    public function academicStartYear(): int
    {
        $setting = $this->settings->get('academic_year');

        if (is_string($setting) && preg_match('/^(\d{4})-/', $setting, $m)) {
            return (int) $m[1];
        }

        $now = Carbon::now();

        return $now->month >= 7 ? $now->year : $now->year - 1;
    }

    /** 1..program length; null when the year of joining is unknown. */
    public function currentYear(?int $yearOfJoining): ?int
    {
        if ($yearOfJoining === null) {
            return null;
        }

        $year = $this->academicStartYear() - $yearOfJoining + 1;

        return max(1, min(self::PROGRAM_YEARS, $year));
    }

    /** Finds or creates the batch for a joining year: 2023 -> 2023-2027, 2024 -> 2024-2028. */
    public function batchFor(int $yearOfJoining, bool $create = true): ?Batch
    {
        $endYear = $yearOfJoining + self::PROGRAM_YEARS;
        $query = Batch::query()->where('start_year', $yearOfJoining)->where('end_year', $endYear);

        if (! $create) {
            return $query->first();
        }

        return $query->first() ?? Batch::create([
            'name' => "{$yearOfJoining}-{$endYear}",
            'start_year' => $yearOfJoining,
            'end_year' => $endYear,
        ]);
    }

    /** Recomputes students.year for every student with a known year of joining (after the academic year setting changes). */
    public function refreshCurrentYears(): int
    {
        $updated = 0;

        Student::query()->whereNotNull('year_of_joining')->chunkById(500, function ($students) use (&$updated) {
            foreach ($students as $student) {
                $year = $this->currentYear($student->year_of_joining);

                if ($year !== $student->year) {
                    $student->forceFill(['year' => $year])->saveQuietly();
                    $updated++;
                }
            }
        });

        return $updated;
    }
}
