<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Enum;

/**
 * What `writeStream()` does when the payload's exact byte length cannot be read
 * off the stream.
 *
 * MYBOX reserves an upload against a declared length and answers HTTP 500
 * `File Storage Error` if the bytes disagree, so guessing is not an option.
 */
enum UnknownSizeStrategy: string
{
    /** Copy the stream through `php://temp` first, then upload the buffer. */
    case Buffer = 'buffer';

    /** Refuse, so the caller has to pass the length explicitly. */
    case Fail = 'fail';
}
