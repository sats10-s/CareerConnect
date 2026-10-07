<?php

namespace App\Services\StudentImport;

use App\Enums\EntryType;
use App\Enums\Gender;
use App\Enums\InterType;
use App\Enums\Program;
use App\Enums\SscScale;
use App\Models\Department;
use App\Models\DepartmentAlias;
use App\Support\JntuNumber;

/**
 * The cleaning rules for one row of the college's student file.
 *
 * M.Tech students (JNTU No with "1D") are out of scope and always skipped.
 *
 * A bad JNTU No, name, department or section skips the whole row (ERROR).
 * A bad academic cell (CGPA, backlogs, 10th, Inter, mobile, gender) only
 * empties that one field and adds a warning - the student is still
 * created. Blank academic cells stay null ("not reported"), never 0.
 */
class StudentRowCleaner
{
    /** @var array<string, array{id: int, name: string}> normalized department spelling => department */
    private array $departments = [];

    public function __construct()
    {
        foreach (Department::query()->get(['id', 'name', 'code']) as $department) {
            $this->departments[DepartmentAlias::normalize($department->code)] = ['id' => $department->id, 'name' => $department->name];
            $this->departments[DepartmentAlias::normalize($department->name)] = ['id' => $department->id, 'name' => $department->name];
        }

        // Aliases win over a code/name that happens to normalize the same.
        foreach (DepartmentAlias::query()->with('department:id,name')->get() as $alias) {
            if ($alias->department) {
                $this->departments[$alias->alias] = ['id' => $alias->department->id, 'name' => $alias->department->name];
            }
        }
    }

    /**
     * @param  array<string, int|float|string|null>  $data
     * @return array{skip: bool, errors: list<string>, warnings: list<string>, unknown_department: ?string, needs_one_time_password: bool, values: array<string, mixed>}
     */
    public function cleanStudent(array $data): array
    {
        $errors = [];
        $warnings = [];
        $unknownDepartment = null;
        $values = [];

        // --- JNTU No (the key) ---------------------------------------------
        $rawRoll = self::text($data['roll_number'] ?? null);
        $roll = JntuNumber::normalize($rawRoll);
        if ($rawRoll === null) {
            $errors[] = 'No JNTU No on this row.';
        } elseif (! JntuNumber::isValid($roll)) {
            $errors[] = "\"{$rawRoll}\" is not a valid JNTU No.";
        }
        $values['roll_number'] = $roll;
        if ($error = self::mtechError($roll)) {
            // Out of scope: one clear reason, not a list of department/section problems.
            $name = self::text($data['name'] ?? null);

            return [
                'skip' => true,
                'errors' => [$error],
                'warnings' => [],
                'unknown_department' => null,
                'needs_one_time_password' => false,
                'values' => ['roll_number' => $roll, 'name' => $name === null ? null : (string) preg_replace('/\s+/u', ' ', $name)],
            ];
        }

        // --- Name -------------------------------------------------------------
        $name = self::text($data['name'] ?? null);
        $name = $name === null ? null : (string) preg_replace('/\s+/u', ' ', $name);
        if ($name === null) {
            $errors[] = 'Name is blank.';
        }
        $values['name'] = $name;

        // --- Department -------------------------------------------------------
        $rawDepartment = self::text($data['department'] ?? null);
        $department = $rawDepartment === null || self::isExcelError($rawDepartment) ? null : ($this->departments[DepartmentAlias::normalize($rawDepartment)] ?? null);
        if ($rawDepartment === null || self::isExcelError($rawDepartment)) {
            $errors[] = 'Department is blank.';
        } elseif ($department === null) {
            $errors[] = "Unknown department \"{$rawDepartment}\". Add it (or an alias for it) in Student Data settings, then re-check.";
            $unknownDepartment = $rawDepartment;
        }
        $values['department_id'] = $department['id'] ?? null;
        $values['department'] = $department['name'] ?? $rawDepartment;

        // --- Section ----------------------------------------------------------
        $rawSection = self::text($data['section'] ?? null);
        $section = $rawSection === null ? null : mb_strtoupper(trim((string) preg_replace('/^\s*sec(tion)?[\s.:\-]*/i', '', $rawSection)));
        if ($section === null || $section === '' || self::isExcelError($rawSection)) {
            $errors[] = $rawSection !== null && self::isExcelError($rawSection) ? "Section is {$rawSection}." : 'Section is blank.';
            $section = null;
        } elseif (mb_strlen($section) > 10) {
            $errors[] = "Section \"{$rawSection}\" is not a section.";
            $section = null;
        } elseif (! preg_match('/^[A-D]$/', $section)) {
            $warnings[] = "Section \"{$section}\" is outside A-D - check it.";
        }
        $values['section'] = $section;

        $validRoll = JntuNumber::isValid($roll);
        $entryType = $validRoll ? JntuNumber::entryType($roll) : null;
        $values['entry_type'] = $entryType?->value;
        $values['program'] = $validRoll ? JntuNumber::program($roll)->value : null;

        // --- Year of Joining: the file wins; blank -> derived from the JNTU No.
        $rawYear = $data['year_of_joining'] ?? null;
        $year = null;
        if ($rawYear !== null && ! self::isExcelError((string) $rawYear) && preg_match('/(19|20)\d{2}/', (string) $rawYear, $m)) {
            $year = (int) $m[0];
        } elseif ($rawYear !== null && trim((string) $rawYear) !== '') {
            $warnings[] = "Year of Joining \"{$rawYear}\" isn't a year; used the year from the JNTU No.";
        }
        if ($year === null && $validRoll) {
            $year = JntuNumber::derivedYearOfJoining($roll);
        }
        $values['year_of_joining'] = $year;

        // --- Gender -----------------------------------------------------------
        $rawGender = self::text($data['gender'] ?? null);
        $gender = match (mb_strtolower((string) $rawGender)) {
            'f', 'female' => Gender::FEMALE,
            'm', 'male' => Gender::MALE,
            default => null,
        };
        if ($rawGender !== null && $gender === null) {
            $warnings[] = "Gender \"{$rawGender}\" wasn't recognised; left empty.";
        }
        $values['gender'] = $gender?->value;

        // --- Mobile: digits only, exactly 10 --------------------------------
        $rawPhone = $data['phone'] ?? null;
        $digits = $rawPhone === null ? '' : self::digits($rawPhone);
        $phoneValid = strlen($digits) === 10;
        if (! $phoneValid) {
            $warnings[] = $digits === ''
                ? 'No mobile number - a one-time password will be generated.'
                : "Mobile \"{$digits}\" is not 10 digits - left empty and a one-time password will be generated.";
        }
        $values['phone'] = $phoneValid ? $digits : null;

        // --- CGPA 0-10 --------------------------------------------------------
        [$values['cgpa'], $warning] = self::cgpa($data['cgpa'] ?? null);
        if ($warning) {
            $warnings[] = $warning;
        }

        // --- Backlogs: integer >= 0; blank = not reported -------------------
        [$values['backlogs'], $warning] = self::backlogs($data['backlogs'] ?? null);
        if ($warning) {
            $warnings[] = $warning;
        }

        // --- 10th: <= 10 or "points" -> 10-point CGPA, else a percentage ----
        $values['ssc_score'] = null;
        $values['ssc_scale'] = null;
        $rawSsc = self::text($data['ssc'] ?? null);
        if ($rawSsc !== null && ! self::isExcelError($rawSsc)) {
            $number = self::firstNumber($rawSsc);
            $saysPoints = str_contains(mb_strtolower($rawSsc), 'point');
            if ($number === null) {
                $warnings[] = "10th score \"{$rawSsc}\" isn't a number; left empty.";
            } elseif ($saysPoints || $number <= 10) {
                if ($number > 10) {
                    $warnings[] = "10th score \"{$rawSsc}\" is above 10 points; left empty.";
                } else {
                    $values['ssc_score'] = $number;
                    $values['ssc_scale'] = SscScale::CGPA10->value;
                }
            } elseif ($number <= 100) {
                $values['ssc_score'] = $number;
                $values['ssc_scale'] = SscScale::PERCENT->value;
            } else {
                $warnings[] = "10th score \"{$rawSsc}\" is above 100; left empty.";
            }
        }

        // --- Inter / Diploma: laterals came through a diploma ---------------
        $values['inter_score'] = null;
        $values['inter_type'] = null;
        $rawInter = self::text($data['inter'] ?? null);
        if ($rawInter !== null && ! self::isExcelError($rawInter)) {
            $number = self::firstNumber($rawInter);
            if ($number === null || $number > 100) {
                $warnings[] = "Inter/Diploma score \"{$rawInter}\" isn't a valid score; left empty.";
            } else {
                $values['inter_score'] = $number;
                $values['inter_type'] = ($entryType === EntryType::LATERAL ? InterType::DIPLOMA : InterType::INTER)->value;
            }
        }

        return [
            'skip' => $errors !== [],
            'errors' => $errors,
            'warnings' => $warnings,
            'unknown_department' => $unknownDepartment,
            'needs_one_time_password' => ! $phoneValid,
            'values' => $values,
        ];
    }

    /**
     * A results-update row: JNTU No, CGPA, Backlogs, optional Semester.
     *
     * @param  array<string, int|float|string|null>  $data
     * @return array{skip: bool, errors: list<string>, warnings: list<string>, values: array{roll_number: string, cgpa: ?float, backlogs: ?int, semester: ?string}}
     */
    public function cleanAcademic(array $data): array
    {
        $errors = [];
        $warnings = [];

        $rawRoll = self::text($data['roll_number'] ?? null);
        $roll = JntuNumber::normalize($rawRoll);
        if ($rawRoll === null) {
            $errors[] = 'No JNTU No on this row.';
        } elseif (! JntuNumber::isValid($roll)) {
            $errors[] = "\"{$rawRoll}\" is not a valid JNTU No.";
        }

        if ($error = self::mtechError($roll)) {
            $errors[] = $error;
        }

        [$cgpa, $warning] = self::cgpa($data['cgpa'] ?? null);
        if ($warning) {
            $warnings[] = $warning;
        }
        [$backlogs, $warning] = self::backlogs($data['backlogs'] ?? null);
        if ($warning) {
            $warnings[] = $warning;
        }

        $semester = self::text($data['semester'] ?? null);

        return [
            'skip' => $errors !== [],
            'errors' => $errors,
            'warnings' => $warnings,
            'values' => ['roll_number' => $roll, 'cgpa' => $cgpa, 'backlogs' => $backlogs, 'semester' => $semester === null ? null : mb_substr($semester, 0, 40)],
        ];
    }

    /**
     * A Placement Admin row: Name, Department, College Email, Password.
     * All four are required; a bad one skips the row. The password is the
     * account's first password (changed at first sign-in), so it is checked
     * for length only and never echoed back in a message.
     *
     * @param  array<string, int|float|string|null>  $data
     * @return array{skip: bool, errors: list<string>, warnings: list<string>, unknown_department: ?string, values: array<string, mixed>, password: ?string}
     */
    public function cleanPlacementAdmin(array $data): array
    {
        $errors = [];

        $name = self::text($data['name'] ?? null);
        $name = $name === null ? null : (string) preg_replace('/\s+/u', ' ', $name);
        if ($name === null) {
            $errors[] = 'Name is blank.';
        }

        $email = self::text($data['email'] ?? null);
        $email = $email === null ? null : mb_strtolower($email);
        if ($email === null) {
            $errors[] = 'College email is blank.';
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors[] = "\"{$email}\" is not a valid email address.";
        }

        $rawDepartment = self::text($data['department'] ?? null);
        $department = $rawDepartment === null || self::isExcelError($rawDepartment) ? null : ($this->departments[DepartmentAlias::normalize($rawDepartment)] ?? null);
        $unknownDepartment = null;
        if ($rawDepartment === null || self::isExcelError($rawDepartment)) {
            $errors[] = 'Department is blank.';
        } elseif ($department === null) {
            $errors[] = "Unknown department \"{$rawDepartment}\". Add it (or an alias for it) in Student Data settings, then re-check.";
            $unknownDepartment = $rawDepartment;
        }

        $password = self::text($data['password'] ?? null);
        if ($password === null) {
            $errors[] = 'Password is blank.';
        } elseif (mb_strlen($password) < 6) {
            $errors[] = 'Password is shorter than 6 characters.';
        } elseif (mb_strlen($password) > 255) {
            $errors[] = 'Password is too long.';
        }

        return [
            'skip' => $errors !== [],
            'errors' => $errors,
            'warnings' => [],
            'unknown_department' => $unknownDepartment,
            'values' => [
                'name' => $name,
                'email' => $email,
                'department_id' => $department['id'] ?? null,
                'department' => $department['name'] ?? $rawDepartment,
            ],
            'password' => $password,
        ];
    }

    /** The portal covers B.Tech only (the 2023-27 and 2024-28 batches); M.Tech ("1D") rows are skipped. */
    private static function mtechError(string $roll): ?string
    {
        return JntuNumber::isValid($roll) && JntuNumber::program($roll) === Program::MTECH ? 'M.Tech students are not imported.' : null;
    }

    /** @return array{0: ?float, 1: ?string} */
    private static function cgpa(int|float|string|null $raw): array
    {
        $text = self::text($raw);
        if ($text === null || self::isExcelError($text) || in_array($text, ['-', 'NA', 'N/A'], true)) {
            return [null, null];
        }

        $number = is_numeric($text) ? (float) $text : null;
        if ($number === null) {
            return [null, "CGPA \"{$text}\" isn't a number; left empty."];
        }
        if ($number < 0 || $number > 10) {
            return [null, "CGPA {$text} is outside 0-10; left empty."];
        }

        return [round($number, 2), null];
    }

    /** @return array{0: ?int, 1: ?string} */
    private static function backlogs(int|float|string|null $raw): array
    {
        $text = self::text($raw);
        if ($text === null || self::isExcelError($text) || in_array($text, ['-', 'NA', 'N/A'], true)) {
            return [null, null];
        }

        if (! is_numeric($text) || (float) $text < 0 || floor((float) $text) != (float) $text) {
            return [null, "Backlogs \"{$text}\" isn't a whole number; left empty."];
        }

        return [(int) $text, null];
    }

    private static function text(int|float|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value) && floor($value) == $value && abs($value) < 1e15) {
            $value = sprintf('%.0f', $value);
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Mobile numbers typed as numbers come back as int or float (9876543210.0) - never 9.87E+9. */
    private static function digits(int|float|string $value): string
    {
        if (is_float($value)) {
            $value = sprintf('%.0f', $value);
        }

        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    private static function firstNumber(string $value): ?float
    {
        return preg_match('/\d+(\.\d+)?/', $value, $m) ? round((float) $m[0], 2) : null;
    }

    private static function isExcelError(string $value): bool
    {
        return (bool) preg_match('/^#(N\/A|REF!|VALUE!|DIV\/0!|NAME\?|NULL!|NUM!)$/i', trim($value));
    }
}
