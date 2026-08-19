<?php

/**
 * Measures the MYBOX behaviour this adapter has to guess about.
 *
 * Naver documents none of it, and each answer changes a decision in the adapter,
 * so the answers belong in docs/adapter-notes.md rather than in someone's head.
 * Everything happens inside a throwaway folder at the drive root; nothing else
 * in the account is read or touched.
 *
 * Usage:
 *   MYBOX_PAT=mbx_pat_xxx php tools/probe-adapter.php
 */

declare(strict_types=1);

use Minhyung\Flysystem\Mybox\Tests\Integration\MyboxCredentials;
use Minhyung\Mybox\Exception\ApiException;
use Minhyung\Mybox\Exception\MyboxException;
use Minhyung\Mybox\Request\ListOptions;

require __DIR__ . '/../vendor/autoload.php';

const PROBE_SANDBOX = '__flysystem_mybox_probe__';

$token = MyboxCredentials::token();

if ($token === null) {
    fwrite(STDERR, "Set MYBOX_PAT, or put it in a .env file at the project root.\n");

    exit(1);
}

$mybox = MyboxCredentials::client($token);
$sandboxId = $mybox->files()->createFolder(PROBE_SANDBOX)->resourceId;

register_shutdown_function(static function () use ($mybox, $sandboxId): void {
    try {
        $mybox->files()->delete($sandboxId);
        $mybox->trash()->purge($sandboxId);
    } catch (MyboxException $e) {
        fwrite(STDERR, sprintf("\nCould not remove the probe folder: %s\nRun: php tools/cleanup-sandbox.php\n", $e->getMessage()));
    }
});

/**
 * @param callable(): string $probe
 */
function report(string $question, callable $probe): void
{
    printf("\n%s\n", $question);

    try {
        printf("  -> %s\n", $probe());
    } catch (ApiException $e) {
        printf("  -> HTTP %d %s\n", $e->status, (string) $e->errorMessage);
    } catch (MyboxException $e) {
        printf("  -> %s\n", $e->getMessage());
    }
}

printf("Probing inside %s (%s)\n", PROBE_SANDBOX, $sandboxId);

report(
    'a) Is a top-level item\'s parentId a folder id that move() accepts?',
    static function () use ($mybox, $sandboxId): string {
        // Everything root-level reports the same parentId. If move() takes it, the
        // adapter can move a file back to the drive root; if not, it must fall back
        // to copy + delete.
        $rootChild = null;

        foreach ($mybox->drive()->listRootAll(new ListOptions(count: 1)) as $item) {
            $rootChild = $item;
            break;
        }

        if ($rootChild === null) {
            return 'inconclusive: the drive root is empty, so nothing reports a parentId';
        }

        $inner = $mybox->files()->createFolder('inner', $sandboxId)->resourceId;
        $mybox->files()->move($inner, $rootChild->parentId);
        $mybox->files()->move($inner, $sandboxId);

        return sprintf('yes — move() accepted parentId %s', $rootChild->parentId);
    },
);

report(
    'b) Does a zero-byte upload succeed?',
    static function () use ($mybox, $sandboxId): string {
        $result = $mybox->upload()->fromString('', 'empty.txt', $sandboxId, isOverwrite: true);

        return sprintf('yes — HTTP %d, fileSize %d', $result->status, $result->fileSize);
    },
);

report(
    'c) Which of Flysystem\'s special-path names does MYBOX accept?',
    static function () use ($mybox, $sandboxId): string {
        $names = [
            'file[name].txt', 'file[0].txt', 'file{name}.txt', 'file{0}.txt',
            'file name.txt', 'file-name.txt', 'file+name.txt', 'file#name.txt',
            'file%name.txt', 'file@name.txt', "file'name.txt", 'file(name).txt',
        ];
        $accepted = [];
        $rejected = [];

        foreach ($names as $name) {
            try {
                $mybox->upload()->fromString('x', $name, $sandboxId, isOverwrite: true);
                $accepted[] = $name;
            } catch (MyboxException) {
                $rejected[] = $name;
            }
        }

        return sprintf(
            "accepted: %s\n     rejected: %s",
            $accepted === [] ? '(none)' : implode(', ', $accepted),
            $rejected === [] ? '(none)' : implode(', ', $rejected),
        );
    },
);

report(
    'd) Does rename() reject a name already taken by a sibling?',
    static function () use ($mybox, $sandboxId): string {
        $mybox->upload()->fromString('a', 'taken.txt', $sandboxId, isOverwrite: true);
        $other = $mybox->upload()->fromString('b', 'other.txt', $sandboxId, isOverwrite: true);

        try {
            $mybox->files()->rename($other->resourceId, 'taken.txt');
        } catch (ApiException $e) {
            return sprintf('yes — HTTP %d %s (the adapter must clear a move destination first)', $e->status, (string) $e->errorMessage);
        }

        return 'no — the rename went through, so the destination pre-clear may be unnecessary';
    },
);

report(
    'e) Does a folder listing accept count=1000?',
    static function () use ($mybox, $sandboxId): string {
        $page = $mybox->drive()->listFolder($sandboxId, new ListOptions(count: ListOptions::MAX_COUNT));

        return sprintf('yes — returned %d resources', count($page->items()));
    },
);

report(
    'f) Are names case-sensitive within one folder?',
    static function () use ($mybox, $sandboxId): string {
        $mybox->upload()->fromString('a', 'Case.txt', $sandboxId, isOverwrite: true);
        $mybox->upload()->fromString('b', 'case.txt', $sandboxId, isOverwrite: true);

        $names = [];

        foreach ($mybox->drive()->listFolderAll($sandboxId) as $item) {
            if (strtolower($item->name) === 'case.txt') {
                $names[] = $item->name;
            }
        }

        return count($names) === 2
            ? 'yes — both Case.txt and case.txt exist side by side'
            : sprintf('no — only %s survived, so lookups should fold case', implode(', ', $names));
    },
);

echo "\nDone. Record the answers in docs/adapter-notes.md.\n";
