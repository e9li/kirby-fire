<?php

namespace E9li\Fire\Tests;

use E9li\Fire\Commands;

class CommandsTest extends TestCase
{
    public function testToBytesParsesIniShorthand(): void
    {
        $this->assertSame(134217728.0, Commands::toBytes('128M'));
        $this->assertSame(1073741824.0, Commands::toBytes('1G'));
        $this->assertSame(524288.0, Commands::toBytes('512K'));
        $this->assertSame(1000.0, Commands::toBytes('1000'));
        $this->assertSame(-1.0, Commands::toBytes('-1'));
    }
}
