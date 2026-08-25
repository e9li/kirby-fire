<?php

namespace E9li\Fire\Tests;

use E9li\Fire\Progress;

class ProgressTest extends TestCase
{
    public function testBarRendersRatioAndCounts(): void
    {
        $this->assertSame('[░░░░░░░░░░] 0/100', Progress::bar(0, 100, 0, 10));
        $this->assertSame('[█████░░░░░] 50/100', Progress::bar(50, 100, 0, 10));
        $this->assertSame('[██████████] 100/100', Progress::bar(100, 100, 0, 10));
    }

    public function testBarShowsFailures(): void
    {
        $this->assertSame('[██████████] 5/5 — 2 failed', Progress::bar(5, 5, 2, 10));
    }

    public function testBarSurvivesZeroTotals(): void
    {
        $this->assertSame('[██████████] 0/0', Progress::bar(0, 0, 0, 10));
    }
}
