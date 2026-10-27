<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use App\Models\Student;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Legacy feature fixtures model ordinary unplaced students.
        // Make that assumption explicit for tests after placement status
        // became fail-closed; tests for UNKNOWN/PLACED set it directly.
        Student::creating(static function (Student $student): void {
            if ($student->placement_status === null) {
                $student->placement_status = 'NOT_PLACED';
            }
        });
    }
}
