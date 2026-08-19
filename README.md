# minhyung/flysystem-mybox

**English** | [한국어](README.ko.md)

[![CI](https://github.com/overworks/flysystem-mybox/actions/workflows/ci.yml/badge.svg)](https://github.com/overworks/flysystem-mybox/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/minhyung/flysystem-mybox)](https://packagist.org/packages/minhyung/flysystem-mybox)
[![PHP version](https://img.shields.io/packagist/dependency-v/minhyung/flysystem-mybox/php)](https://packagist.org/packages/minhyung/flysystem-mybox)
[![License](https://img.shields.io/packagist/l/minhyung/flysystem-mybox)](LICENSE)

A [Flysystem](https://flysystem.thephpleague.com/) v3 adapter for
[Naver MYBOX](https://mybox.naver.com), built on the
[`minhyung/mybox`](https://github.com/overworks/php-mybox) SDK. It passes
Flysystem's own adapter conformance suite.

```php
use League\Flysystem\Filesystem;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Mybox\MyboxClient;

$filesystem = new Filesystem(new MyboxAdapter(MyboxClient::create($_ENV['MYBOX_PAT'])));

$filesystem->write('reports/2026-08.csv', $csv);
echo $filesystem->read('reports/2026-08.csv');
```

## Requirements

- PHP 8.2+
- A MYBOX personal access token (MYBOX web → 설정 → 계정 및 개인 액세스 토큰 관리)
- `ext-intl` is optional but recommended, so Hangul names created on macOS (NFD)
  match what MYBOX stores (NFC)

## Installation

```bash
composer require minhyung/flysystem-mybox
```

The SDK speaks PSR-18, and brings no HTTP client of its own. If your project has
none yet:

```bash
composer require guzzlehttp/guzzle
```

## Usage

### Confining the adapter to a folder

```php
$adapter = new MyboxAdapter($client, 'app-data/uploads');
```

Every path is resolved under that folder, and it is created on the first write.
Nothing outside it is reachable, which makes this the safest way to share an
account between an application and a human.

### Options

```php
use Minhyung\Flysystem\Mybox\Enum\DeletionMode;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;

$adapter = new MyboxAdapter($client, options: new MyboxAdapterOptions(
    deletionMode: DeletionMode::Purge,
    visibility: League\Flysystem\Visibility::PUBLIC,
));
```

| Option | Default | What it does |
|---|---|---|
| `deletionMode` | `Trash` | `Trash` moves deleted files to the MYBOX trash, like the web UI. `Purge` also empties them from it. |
| `visibility` | `private` | What `visibility()` reports. MYBOX stores no such thing — see [Limitations](#limitations). |
| `failOnSetVisibility` | `true` | Whether `setVisibility()` throws or is a silent no-op. |
| `unknownSize` | `Buffer` | What `writeStream()` does when a stream's length cannot be read. `Fail` refuses instead of buffering. |
| `bufferThresholdBytes` | 2 MiB | Below this a buffered upload stays in memory; above it, `php://temp` spills to disk. |
| `listPageSize` | 1000 | Entries per listing request. The maximum MYBOX allows, and ten times its own default. |
| `cacheTtlSeconds` | 10 | How long a cached directory listing is trusted. |
| `cacheMaxDirectories` | 128 | How many directories the cache holds before evicting the least recently used. |
| `strictTemporaryUrlExpiry` | `true` | Whether to refuse a temporary URL asked to live longer than MYBOX allows. |
| `lockedRetries` | 2 | Retries when MYBOX answers 423, which it does briefly after an interrupted upload. |

### Uploading a large file without buffering

MYBOX reserves an upload against a declared byte length and answers HTTP 500 if
the bytes disagree, so the adapter never guesses. It reads the length off a
seekable regular file; for anything else — a socket, a pipe, an upload arriving
over the network — it buffers through `php://temp` first. Skip that by saying
how long the payload is:

```php
$filesystem->writeStream('video.mp4', $stream, ['size' => $lengthInBytes]);
```

### Temporary URLs

```php
$url = $filesystem->temporaryUrl('reports/2026-08.csv', new DateTimeImmutable('+5 minutes'));
```

> **The URL is single-use.** A second request for it fails, and MYBOX ignores the
> expiry you ask for — it grants ten minutes. Hand it to one client immediately;
> never put it in a cached page, an email, or a job that might be retried.

### With Laravel

The package is framework-agnostic. Register it as a disk driver in a service
provider:

```php
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Mybox\MyboxClient;

Storage::extend('mybox', function (array $app, array $config) {
    $adapter = new MyboxAdapter(MyboxClient::create($config['token']), $config['root'] ?? '');

    return new \Illuminate\Filesystem\FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
});
```

```php
// config/filesystems.php
'mybox' => [
    'driver' => 'mybox',
    'token' => env('MYBOX_PAT'),
    'root' => env('MYBOX_ROOT', ''),
],
```

## How path resolution works

MYBOX addresses everything by an opaque resource id, not by a path, and its only
path-aware endpoint — search — is capped at ten calls a minute and served from an
index that lags a just-created folder. So the adapter never uses search. It walks
down from the drive root with listing calls, and keeps the *whole* listing rather
than the one id it was after.

That matters because a listing already answers exists, size, last-modified and
type for every child at once:

```php
$filesystem->fileExists('reports/2026-08.csv');   // walks: 2 requests
$filesystem->fileSize('reports/2026-08.csv');     // 0 requests
$filesystem->lastModified('reports/2026-08.csv'); // 0 requests
$filesystem->mimeType('reports/2026-08.csv');     // 0 requests
$filesystem->fileExists('reports/2026-07.csv');   // 0 requests
```

Cached listings are per-adapter-instance, per-process, and trusted for ten
seconds. That window is what bounds the one failure this cannot detect: another
client renaming a folder, which leaves the id valid but pointing elsewhere. Call
`$adapter->clearCache()` after mutating the drive through the SDK directly, or
pass `new NullDirectoryCache()` to turn caching off entirely — at the cost of
three listing round-trips for every `fileExists('a/b/c.txt')`.

## Limitations

These are properties of the MYBOX API, not gaps in the adapter.

- **No visibility.** MYBOX has no per-file permission model of any kind.
  `visibility()` returns whatever you configured; `setVisibility()` throws rather
  than pretending. The `visibility` option on `write()` is accepted and ignored,
  so Laravel's `Storage::put($path, $contents, 'public')` does not break.
- **No public URLs.** There is no sharing endpoint, so `PublicUrlGenerator` is
  deliberately not implemented — `temporaryUrl()` would be a lie in a page that
  gets cached. Serve files through your own controller and configure Flysystem's
  `PrefixPublicUrlGenerator` if you need stable URLs.
- **No checksums.** MYBOX exposes no hash. `Filesystem::checksum()` still works;
  it downloads the file and hashes it locally, which the adapter leaves visible
  rather than hiding behind a `ChecksumProvider`.
- **No mime types.** The API reports only a coarse category, so mime types are
  detected from the file extension. Pass your own `MimeTypeDetector` to change
  that.
- **`move()` is not atomic.** MYBOX splits it: one call changes the parent,
  another changes the name, and the second has no overwrite flag — so an occupied
  destination is deleted first. A crash in between leaves the file at an
  intermediate path.
- **Deleting fills the trash.** With the default `DeletionMode::Trash`, deleted
  files keep counting against the account quota until MYBOX auto-empties the
  trash. Use `DeletionMode::Purge`, or set an auto-delete window with the SDK's
  `DriveApi::setTrashAutoDeleteDays()`.
- **Rate limits.** Downloads are 500–50,000 a day depending on plan; deletes and
  most other calls 60–240 a minute. The listing cache exists largely to keep you
  under them.
- **File names are case-insensitive.** Writing `case.txt` into a folder that
  already holds `Case.txt` overwrites it, and MYBOX keeps the original spelling —
  so the file comes back from `listContents()` as `Case.txt`. The adapter folds
  case when matching, so reading it back by either spelling works.

Measured against a live account and recorded in
[docs/adapter-notes.md](docs/adapter-notes.md): every special character
Flysystem tests for is accepted in a file name, zero-byte uploads work, and a
folder listing takes the maximum page size of 1000.

## Contributing

```bash
composer test      # unit suite, no network
composer analyse   # PHPStan level 9
composer cs        # PHP-CS-Fixer, dry run
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Note that the integration suite talks to a
real MYBOX account and is off by default.

## License

MIT. See [LICENSE](LICENSE).
