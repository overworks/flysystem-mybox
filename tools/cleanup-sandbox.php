<?php

/**
 * Removes any test sandbox this package left behind in a MYBOX account.
 *
 * An upload cut mid-flight leaves the resource locked (HTTP 423) until the
 * server gives up on it, which blocks deletion for a while, so this retries
 * until the lock clears. A half-deleted sandbox also hides in the trash, which
 * is why both are scanned.
 *
 * Usage:
 *   MYBOX_PAT=mbx_pat_xxx php tools/cleanup-sandbox.php [--dry-run]
 */

declare(strict_types=1);

use Minhyung\Flysystem\Mybox\Tests\Integration\MyboxCredentials;
use Minhyung\Mybox\Exception\ApiException;

require __DIR__ . '/../vendor/autoload.php';

$sandboxNames = [MyboxCredentials::SANDBOX];
$dryRun = in_array('--dry-run', $argv, true);

$token = MyboxCredentials::token();

if ($token === null) {
    fwrite(STDERR, "Set MYBOX_PAT, or put it in a .env file at the project root.\n");

    exit(1);
}

$mybox = MyboxCredentials::client($token);

$deadline = time() + 600;
$pending = [];

echo "Scanning the drive root...\n";

foreach ($mybox->drive()->listRootAll() as $item) {
    if (in_array($item->name, $sandboxNames, true)) {
        printf("  found %s (%s)\n", $item->name, $item->resourceId);
        $pending[$item->resourceId] = $item->name;
    }
}

echo "Scanning the trash...\n";

foreach ($mybox->trash()->listAll() as $item) {
    if (in_array($item->name, $sandboxNames, true)) {
        printf("  found in trash: %s (%s)\n", $item->name, $item->resourceId);
        $pending[$item->resourceId] = $item->name;
    }
}

if ($pending === []) {
    echo "\nNothing to clean up — the account is already clear.\n";

    exit(0);
}

if ($dryRun) {
    printf("\n--dry-run: %d sandbox folder(s) would be removed.\n", count($pending));

    exit(0);
}

echo "\nRemoving (retrying while anything is still locked):\n";

while ($pending !== [] && time() < $deadline) {
    foreach ($pending as $id => $name) {
        try {
            try {
                $mybox->files()->delete($id);
            } catch (ApiException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }

            $mybox->trash()->purge($id);
            printf("  removed %s\n", $name);
            unset($pending[$id]);
        } catch (ApiException $e) {
            printf("  %s: HTTP %d %s — retrying\n", $name, $e->status, (string) $e->errorMessage);
        }
    }

    if ($pending !== []) {
        sleep(15);
    }
}

if ($pending === []) {
    echo "\nAccount is clean.\n";

    exit(0);
}

echo "\nStill present after 10 minutes:\n";

foreach ($pending as $id => $name) {
    printf("  %s (%s) — remove it from the MYBOX web UI\n", $name, $id);
}

exit(1);
