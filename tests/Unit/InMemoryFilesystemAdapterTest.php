<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;
use Minhyung\Flysystem\Mybox\Tests\Double\InMemoryMyboxServer;
use Minhyung\Flysystem\Mybox\Tests\MyboxConformanceOverrides;
use Minhyung\Mybox\ClientConfig;
use Minhyung\Mybox\Http\RetryPolicy;
use Minhyung\Mybox\MyboxClient;

/**
 * Runs Flysystem's own adapter conformance suite against an in-memory MYBOX.
 *
 * The double reproduces the API's awkward parts — trashed resources stay
 * readable by id, folder creation 409s on a collision, a download URL works
 * once — so passing here is a real signal rather than a tautology. It needs no
 * network and no quota, which is why it runs on every push while the live suite
 * does not.
 */
final class InMemoryFilesystemAdapterTest extends FilesystemAdapterTestCase
{
    use MyboxConformanceOverrides;

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $factory = new HttpFactory();
        $client = new MyboxClient(
            new ClientConfig('mbx_pat_test', retryPolicy: RetryPolicy::none()),
            new InMemoryMyboxServer(),
            $factory,
            $factory,
        );

        // Visibility::PUBLIC because several upstream tests write with a public
        // visibility and then assert it comes back; MYBOX stores no such thing, so
        // the adapter reports whatever it was configured to report.
        return new MyboxAdapter($client, options: new MyboxAdapterOptions(visibility: Visibility::PUBLIC));
    }

    /**
     * @test
     */
    public function a_file_written_under_another_casing_is_still_found(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();

            $adapter->write('Case.txt', 'first', new Config());
            $adapter->write('case.txt', 'second', new Config());

            // MYBOX overwrote the existing file and kept its spelling, so both
            // spellings have to reach the same bytes.
            self::assertSame('second', $adapter->read('Case.txt'));
            self::assertSame('second', $adapter->read('case.txt'));
            self::assertCount(1, iterator_to_array($adapter->listContents('', false)));
        });
    }

    /**
     * @test
     */
    public function generating_a_temporary_url(): void
    {
        // The upstream test fetches the URL over the network. The URL itself is
        // covered by MyboxAdapterUrlTest.
        $this->markTestSkipped('The in-memory storage host is not reachable over HTTP.');
    }
}
