<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests;

use Minhyung\Mybox\Http\Sleeper;

/**
 * Records requested delays instead of spending them, so retry behaviour can be
 * asserted without slowing the suite down.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $slept = [];

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
    }
}
