<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Minhyung\Mybox\ClientConfig;
use Minhyung\Mybox\Http\RetryPolicy;
use Minhyung\Mybox\MyboxClient;

/**
 * Where a live MYBOX client comes from, for the tests that need one.
 *
 * The token is read from `MYBOX_PAT`, exported or written to a `.env` file at
 * the project root — the same spelling `minhyung/mybox` uses, so one `.env`
 * serves both repositories and nobody has to put a token in shell history.
 */
final class MyboxCredentials
{
    /** Everything a live test does happens inside this folder. */
    public const SANDBOX = '__flysystem_mybox_test__';

    public static function token(): ?string
    {
        $token = getenv('MYBOX_PAT');

        if (is_string($token) && trim($token) !== '') {
            return trim($token);
        }

        $envFile = dirname(__DIR__, 2) . '/.env';

        if (!is_readable($envFile)) {
            return null;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*MYBOX_PAT\s*=\s*(.+?)\s*$/', $line, $matches) === 1) {
                return trim($matches[1], "\"'");
            }
        }

        return null;
    }

    public static function client(string $token): MyboxClient
    {
        $factory = new HttpFactory();

        return new MyboxClient(
            new ClientConfig($token, retryPolicy: new RetryPolicy(maxAttempts: 5)),
            new GuzzleClient(['http_errors' => false, 'timeout' => 300]),
            $factory,
            $factory,
        );
    }
}
