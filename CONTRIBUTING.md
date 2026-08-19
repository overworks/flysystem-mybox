# Contributing to flysystem-mybox

This package is a [Flysystem](https://flysystem.thephpleague.com/) adapter over
[`minhyung/mybox`](https://github.com/overworks/php-mybox). Bug reports, ideas
and pull requests are all welcome.

## Getting set up

```bash
git clone git@github.com:overworks/flysystem-mybox.git
cd flysystem-mybox
composer install
```

PHP 8.2 or newer. `ext-intl` is optional but recommended — without it, Hangul
file names created on macOS (NFD) will not match what MYBOX stores (NFC).

## The three checks

```bash
composer test      # PHPUnit, unit suite only, no network
composer analyse   # PHPStan level 9 over src and tests
composer cs        # PHP-CS-Fixer, dry run; `composer cs:fix` to apply
```

All three must pass before a change is done. CI runs exactly these on PHP 8.2,
8.3 and 8.4, plus a `--prefer-lowest` leg.

## Integration tests

The unit suite is hermetic. The integration suite talks to a real MYBOX account
and is excluded from `composer test`:

```bash
echo 'MYBOX_PAT=mbx_pat_…' > .env      # .env is gitignored
composer test:integration
```

Rules for anything that touches a live account:

- The suite creates a sandbox folder (`__flysystem_mybox_test__`) and builds the
  adapter with that folder as its root, so the adapter under test cannot address
  anything outside it even with a bug. Keep it that way.
- Clean up, trash included. If a run dies partway,
  `php tools/cleanup-sandbox.php` removes what was left; it retries while a
  resource is still locked. `--dry-run` shows what it would remove.
- Never commit a token, paste one into a commit message, or echo one in output
  a tool prints.
- Do not deliberately exhaust a rate limit. MYBOX states that bursts it reads as
  abuse can restrict an account without prior warning.

The full upstream `FilesystemAdapterTestCase` is gated behind a second
environment variable, `MYBOX_ADAPTER_LIVE_SUITE=1`, because it performs several
hundred operations. The same suite runs offline in CI against an in-memory
MYBOX double.

## Layout

```
src/
  MyboxAdapter.php          the FilesystemAdapter implementation
  MyboxAdapterOptions.php   immutable knobs
  Enum/                     DeletionMode, UnknownSizeStrategy
  Path/                     path -> resource id resolution
  Cache/                    per-directory listing snapshots
  Stream/                   PSR-7 stream -> PHP resource
  Upload/                   exact-length resolution for writeStream()
  Support/                  exception reason rendering
```

## Conventions

- `declare(strict_types=1)` in every file; classes `final` unless there is a
  reason not to; readonly promoted constructor properties; backed enums.
- Docblocks explain *why*, not *what*. Type-only tags that restate the signature
  are removed by the fixer.
- Comments and exception messages are in English.
- PHPUnit 11, `public function testXxx(): void`, `#[CoversClass]` on the class,
  `self::assertSame()` over `$this->assertSame()`.
- HTTP is mocked at the PSR-18 layer. Never mock the SDK's classes — they are
  `final`, and the bugs worth catching here are request-shaped.

## Commits

Conventional Commits (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`),
written in English, with no `Co-Authored-By:` trailer.
