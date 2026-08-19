<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Enum;

/**
 * What `delete()` and `deleteDirectory()` actually do to a resource.
 */
enum DeletionMode: string
{
    /**
     * Move it to the MYBOX trash, which is what the web UI does and what
     * {@see \Minhyung\Mybox\Api\FileApi::delete()} means. Recoverable, but the
     * trash keeps counting against the account quota until it auto-empties.
     */
    case Trash = 'trash';

    /**
     * Trash it and then purge it, so the bytes are gone. Twice the calls against
     * the tightest quota bracket, and purging is eventually consistent — the id
     * answers for a moment afterwards with a size of zero.
     */
    case Purge = 'purge';
}
