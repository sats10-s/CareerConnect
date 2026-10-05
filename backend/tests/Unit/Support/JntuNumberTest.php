<?php

namespace Tests\Unit\Support;

use App\Enums\EntryType;
use App\Enums\Program;
use App\Support\JntuNumber;
use PHPUnit\Framework\TestCase;

class JntuNumberTest extends TestCase
{
    public function test_validates_and_normalizes(): void
    {
        $this->assertTrue(JntuNumber::isValid('23341A0501'));
        $this->assertTrue(JntuNumber::isValid(' 23341a05b1 '));
        $this->assertTrue(JntuNumber::isValid('24341D5701'));
        $this->assertFalse(JntuNumber::isValid('2334A0501'));
        $this->assertFalse(JntuNumber::isValid('23341A050'));
        $this->assertFalse(JntuNumber::isValid(''));
        $this->assertFalse(JntuNumber::isValid(null));
        $this->assertSame('23341A05B1', JntuNumber::normalize(' 23341a05 b1 '));
    }

    public function test_regular_lateral_and_mtech_derivation(): void
    {
        $this->assertSame(EntryType::REGULAR, JntuNumber::entryType('23341A0501'));
        $this->assertSame(Program::BTECH, JntuNumber::program('23341A0501'));
        $this->assertSame(2023, JntuNumber::derivedYearOfJoining('23341A0501'));

        // Lateral entry joins 2nd year: admission 2024 -> 2023 batch.
        $this->assertSame(EntryType::LATERAL, JntuNumber::entryType('24345A0501'));
        $this->assertSame(Program::BTECH, JntuNumber::program('24345A0501'));
        $this->assertSame(2023, JntuNumber::derivedYearOfJoining('24345A0501'));

        $this->assertSame(EntryType::REGULAR, JntuNumber::entryType('24341D5701'));
        $this->assertSame(Program::MTECH, JntuNumber::program('24341D5701'));
        $this->assertSame(2024, JntuNumber::derivedYearOfJoining('24341D5701'));
    }
}
