<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Integration;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Visibility;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;
use Minhyung\Mybox\Exception\MyboxException;
use Minhyung\Mybox\MyboxClient;
use PHPUnit\Framework\Attributes\Group;

/**
 * Flysystem's conformance suite against a real MYBOX account.
 *
 * It performs several hundred operations, so it needs two gates rather than one:
 * `MYBOX_PAT` for a token and `MYBOX_ADAPTER_LIVE_SUITE=1` to say you meant it.
 * CI never runs it. The offline equivalent is
 * {@see \Minhyung\Flysystem\Mybox\Tests\Unit\InMemoryFilesystemAdapterTest}.
 *
 * Every test is confined by construction: the adapter is built with the sandbox
 * folder as its root, so even a bug in path handling cannot reach the account's
 * real files.
 */
#[Group('integration')]
final class MyboxFilesystemAdapterTest extends FilesystemAdapterTestCase
{
    private static ?MyboxClient $client = null;

    private static ?string $sandboxId = null;

    private static string $sandboxPath = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $token = MyboxCredentials::token();

        if ($token === null || getenv('MYBOX_ADAPTER_LIVE_SUITE') !== '1') {
            return;
        }

        self::$client = MyboxCredentials::client($token);

        $root = self::findOrCreateFolder(MyboxCredentials::SANDBOX, null);
        $run = uniqid('run-', true);
        self::$sandboxId = self::findOrCreateFolder($run, $root);
        self::$sandboxPath = MyboxCredentials::SANDBOX . '/' . $run;

        // A fatal skips tearDownAfterClass; leave the operator a way back.
        register_shutdown_function(static function (): void {
            if (self::$sandboxId !== null) {
                fwrite(STDERR, "\nA MYBOX sandbox folder was left behind. Run: php tools/cleanup-sandbox.php\n");
            }
        });
    }

    public static function tearDownAfterClass(): void
    {
        $client = self::$client;
        $sandboxId = self::$sandboxId;

        if ($client !== null && $sandboxId !== null) {
            try {
                $client->files()->delete($sandboxId);
                $client->trash()->purge($sandboxId);
            } catch (MyboxException $exception) {
                fwrite(STDERR, sprintf(
                    "\nCould not remove the sandbox folder: %s\nRun: php tools/cleanup-sandbox.php\n",
                    $exception->getMessage(),
                ));
            }
        }

        self::$client = null;
        self::$sandboxId = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        if (self::$client === null) {
            self::markTestSkipped('Set MYBOX_PAT and MYBOX_ADAPTER_LIVE_SUITE=1 to run the live suite.');
        }

        parent::setUp();

        // A live drive is eventually consistent in places; one retry beats a flake.
        $this->retryOnException(FilesystemException::class, 3);
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $client = self::$client;

        if ($client === null) {
            self::markTestSkipped('Set MYBOX_PAT and MYBOX_ADAPTER_LIVE_SUITE=1 to run the live suite.');
        }

        return new MyboxAdapter(
            $client,
            self::$sandboxPath,
            new MyboxAdapterOptions(visibility: Visibility::PUBLIC),
        );
    }

    /**
     * @test
     */
    public function setting_visibility(): void
    {
        $this->markTestSkipped('MYBOX has no per-file visibility model.');
    }

    private static function findOrCreateFolder(string $name, ?string $parentId): string
    {
        $client = self::$client;
        self::assertNotNull($client);

        $listing = $parentId === null
            ? $client->drive()->listRootAll()
            : $client->drive()->listFolderAll($parentId);

        foreach ($listing as $item) {
            if ($item->isFolder() && $item->name === $name) {
                return $item->resourceId;
            }
        }

        return $client->files()->createFolder($name, $parentId)->resourceId;
    }
}
