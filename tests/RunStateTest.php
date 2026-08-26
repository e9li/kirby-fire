<?php

namespace E9li\Fire\Tests;

use E9li\Fire\RunState;

class RunStateTest extends TestCase
{
    public function testKeyJoinsIdAndLanguage(): void
    {
        $this->app();

        $this->assertSame('blog/post|de', RunState::key('blog/post', 'de'));
        $this->assertSame('home|', RunState::key('home', null));
    }

    public function testLoadWithoutFile(): void
    {
        $this->app();

        $this->assertSame([], RunState::load());
    }

    public function testUpdateMergesAndClears(): void
    {
        $this->app();

        RunState::update(['a|' => ['state' => 'no-store', 'error' => null, 'time' => 1]]);
        RunState::update(['b|de' => ['state' => 'extinguished', 'error' => 'HTTP 500', 'time' => 2]]);

        $this->assertCount(2, RunState::load());

        // null clears an entry — the page is fine again
        RunState::update(['a|' => null]);

        $state = RunState::load();
        $this->assertArrayNotHasKey('a|', $state);
        $this->assertSame('HTTP 500', $state['b|de']['error']);
    }

    public function testApplyResolvesRowStates(): void
    {
        $this->app();

        RunState::update([
            'error|' => ['state' => 'no-store', 'error' => null, 'time' => 1],
            'about|' => ['state' => 'extinguished', 'error' => 'HTTP 500', 'time' => 1],
        ]);

        $rows = RunState::apply([
            ['url' => self::BASE, 'id' => 'home', 'language' => null, 'isErrorPage' => false, 'cached' => true],
            ['url' => self::BASE . '/about', 'id' => 'about', 'language' => null, 'isErrorPage' => false, 'cached' => false],
            ['url' => self::BASE . '/error', 'id' => 'error', 'language' => null, 'isErrorPage' => true, 'cached' => false],
        ]);

        // disk-cached wins, then the recorded outcome with reason
        $this->assertSame('fire-on', $rows[0]['state']);
        $this->assertNull($rows[0]['error']);

        $this->assertSame('extinguished', $rows[1]['state']);
        $this->assertSame('HTTP 500', $rows[1]['error']);

        $this->assertSame('no-store', $rows[2]['state']);
    }
}
