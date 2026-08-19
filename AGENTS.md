# Notes for coding agents

Read [CONTRIBUTING.md](CONTRIBUTING.md) first — it is the full guide, and
everything below is a pointer to the parts most easily got wrong.

## Before you say a change is done

```bash
composer test && composer analyse && composer cs
```

All three, actually run. `composer test` needs no network.

## Hard rules

- **Never commit `.env`, a personal access token, or any value starting
  `mbx_pat_`.** Do not print one in tool output either.
- **Never silence PHPStan.** No `@phpstan-ignore`, no baseline, no inline
  `@var`, no cast added to quiet a report. Fix the underlying type hole.
- **The integration suite and everything in `tools/` hit a real MYBOX
  account.** Ask before running them. Work only inside the sandbox folder
  (`__flysystem_mybox_test__`) and clean up afterwards —
  `php tools/cleanup-sandbox.php` if a run died partway.
- **Do not deliberately trip a rate limit.** MYBOX restricts accounts it reads
  as abusive, without warning. Search is the tightest: as few as 10 calls a
  minute, which is why this adapter never calls it.
- **Commits use Conventional Commits and carry no `Co-Authored-By:` trailer.**

## Things that look like bugs but are not

- **`fileExists()` must never use `DriveApi::get()`.** A trashed resource is
  still readable by id; only its `parentId` changes. Existence is answered from
  the parent listing, always.
- **`SearchApi` is deliberately not a dependency.** It is the only path-aware
  endpoint and it looks like the obvious way to resolve a path. It is capped at
  10 calls a minute and served from an index that lags a just-created folder,
  so it breaks Flysystem's write-then-read contract. Walk with listings.
- **Uploads must declare the exact byte length.** MYBOX answers HTTP 500
  `File Storage Error` on a mismatch, so `writeStream()` buffers rather than
  guesses. See `Upload/UploadSize`.
- **`isOverwrite: true` is passed on every write, copy and same-name move.**
  Flysystem's contract is overwrite-always; without the flag MYBOX returns 409.
- **`FileApi::rename()` has no overwrite flag**, which is why `move()`
  pre-deletes an occupied destination. That step is not redundant — a live probe
  confirmed the 409.
- **`Path\NameKey` lower-cases as well as NFC-normalises.** MYBOX matches names
  case-insensitively and keeps the spelling it already had, so writing
  `case.txt` over `Case.txt` leaves the listing reporting `Case.txt`. Drop the
  case fold and `fileExists()` starts missing files it just wrote.
- The rest of the undocumented API behaviour lives in the SDK's
  [docs/transfer-protocol.md](https://github.com/overworks/php-mybox/blob/0.x/docs/transfer-protocol.md).
  Check there before "fixing" any of it.

## When you touch prose

[README.md](README.md) and [README.ko.md](README.ko.md) mirror each other.
Update both, or neither.
