<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests;

use League\Flysystem\Config;

/**
 * The places where Flysystem's conformance suite asks for something MYBOX does
 * not have.
 *
 * Shared by the in-memory and the live suite so the two cannot drift: a
 * deviation that is real belongs to both, and one that is only true offline is
 * a bug in the double.
 */
trait MyboxConformanceOverrides
{
    /**
     * MYBOX has no per-file permission model, so `setVisibility()` throws rather
     * than pretending to have stored something.
     *
     * @test
     */
    public function setting_visibility(): void
    {
        $this->markTestSkipped('MYBOX has no per-file visibility model.');
    }

    /**
     * The upstream version also asserts that the visibility given to the second
     * write comes back. No adapter reporting a fixed visibility can satisfy that,
     * and the overwrite itself is the part worth keeping.
     *
     * @test
     */
    public function overwriting_a_file(): void
    {
        $this->runScenario(function (): void {
            $this->givenWeHaveAnExistingFile('path.txt', 'contents');
            $adapter = $this->adapter();

            $adapter->write('path.txt', 'new contents', new Config());

            self::assertSame('new contents', $adapter->read('path.txt'));
        });
    }
}
