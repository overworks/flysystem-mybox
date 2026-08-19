# minhyung/flysystem-mybox

[English](README.md) | **한국어**

[![CI](https://github.com/overworks/flysystem-mybox/actions/workflows/ci.yml/badge.svg)](https://github.com/overworks/flysystem-mybox/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/minhyung/flysystem-mybox)](https://packagist.org/packages/minhyung/flysystem-mybox)
[![PHP version](https://img.shields.io/packagist/dependency-v/minhyung/flysystem-mybox/php)](https://packagist.org/packages/minhyung/flysystem-mybox)
[![License](https://img.shields.io/packagist/l/minhyung/flysystem-mybox)](LICENSE)

[네이버 MYBOX](https://mybox.naver.com)용
[Flysystem](https://flysystem.thephpleague.com/) v3 어댑터입니다.
[`minhyung/mybox`](https://github.com/overworks/php-mybox) SDK 위에 올렸고,
Flysystem이 제공하는 어댑터 적합성 테스트 스위트를 통과합니다.

```php
use League\Flysystem\Filesystem;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Mybox\MyboxClient;

$filesystem = new Filesystem(new MyboxAdapter(MyboxClient::create($_ENV['MYBOX_PAT'])));

$filesystem->write('reports/2026-08.csv', $csv);
echo $filesystem->read('reports/2026-08.csv');
```

## 요구 사항

- PHP 8.2 이상
- MYBOX 개인 액세스 토큰 (MYBOX 웹 → 설정 → 계정 및 개인 액세스 토큰 관리)
- `ext-intl`은 선택이지만 권장합니다. macOS에서 만든 한글 이름(NFD)과 MYBOX가
  저장하는 이름(NFC)을 맞추는 데 씁니다.

## 설치

```bash
composer require minhyung/flysystem-mybox
```

SDK는 PSR-18을 쓰며 HTTP 클라이언트를 직접 들고 오지 않습니다. 프로젝트에 아직
없다면:

```bash
composer require guzzlehttp/guzzle
```

## 사용법

### 특정 폴더로 범위 제한하기

```php
$adapter = new MyboxAdapter($client, 'app-data/uploads');
```

모든 경로가 그 폴더 아래에서 해석되고, 폴더는 첫 쓰기 때 만들어집니다. 바깥은
접근할 수 없으므로, 한 계정을 애플리케이션과 사람이 함께 쓸 때 가장 안전한
방법입니다.

### 옵션

```php
use Minhyung\Flysystem\Mybox\Enum\DeletionMode;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;

$adapter = new MyboxAdapter($client, options: new MyboxAdapterOptions(
    deletionMode: DeletionMode::Purge,
    visibility: League\Flysystem\Visibility::PUBLIC,
));
```

| 옵션 | 기본값 | 설명 |
|---|---|---|
| `deletionMode` | `Trash` | `Trash`는 웹 UI처럼 휴지통으로 보냅니다. `Purge`는 휴지통에서도 비웁니다. |
| `visibility` | `private` | `visibility()`가 돌려줄 값. MYBOX에는 이런 개념이 없습니다 — [제약](#제약) 참고. |
| `failOnSetVisibility` | `true` | `setVisibility()`가 예외를 던질지, 조용히 무시할지. |
| `unknownSize` | `Buffer` | 스트림 길이를 알 수 없을 때 `writeStream()`의 동작. `Fail`은 버퍼링 대신 거부합니다. |
| `bufferThresholdBytes` | 2 MiB | 이 크기 이하면 메모리에, 넘으면 `php://temp`가 디스크로 흘립니다. |
| `listPageSize` | 1000 | 리스팅 한 번에 가져올 항목 수. MYBOX 최대치이며 자체 기본값의 10배입니다. |
| `cacheTtlSeconds` | 10 | 캐시된 디렉터리 리스팅을 신뢰하는 시간. |
| `cacheMaxDirectories` | 128 | 캐시가 보관할 디렉터리 수. 넘으면 오래된 것부터 버립니다. |
| `strictTemporaryUrlExpiry` | `true` | MYBOX가 주는 것보다 긴 만료를 요구하면 거부할지 여부. |
| `lockedRetries` | 2 | 423 응답 재시도 횟수. 중단된 업로드 직후 잠깐 발생합니다. |

### 큰 파일을 버퍼링 없이 올리기

MYBOX는 선언한 바이트 길이로 업로드를 예약하고, 실제 바이트가 다르면 HTTP 500을
냅니다. 그래서 어댑터는 절대 추측하지 않습니다. 탐색 가능한 정규 파일이면 길이를
읽고, 그 외(소켓·파이프·네트워크로 들어오는 업로드)는 `php://temp`로 먼저
버퍼링합니다. 길이를 직접 알려주면 그 과정을 건너뜁니다:

```php
$filesystem->writeStream('video.mp4', $stream, ['size' => $lengthInBytes]);
```

### 임시 URL

```php
$url = $filesystem->temporaryUrl('reports/2026-08.csv', new DateTimeImmutable('+5 minutes'));
```

> **이 URL은 1회용입니다.** 두 번째 요청은 실패하고, MYBOX는 요청한 만료 시각을
> 무시한 채 10분을 줍니다. 클라이언트 하나에게 즉시 건네는 용도입니다. 캐시되는
> 페이지, 이메일, 재시도되는 작업에는 절대 넣지 마세요.

### Laravel에서 쓰기

이 패키지는 프레임워크 비종속입니다. 서비스 프로바이더에서 디스크 드라이버로
등록하세요:

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

## 경로 해석 방식

MYBOX는 경로가 아니라 불투명한 리소스 id로 주소를 지정합니다. 경로를 아는 유일한
엔드포인트인 검색은 요금제에 따라 분당 10회로 제한되고, 인덱스가 방금 만든 폴더를
놓칩니다. 그래서 이 어댑터는 검색을 쓰지 않습니다. 드라이브 루트부터 리스팅으로
걸어 내려가되, 찾던 id 하나만 남기지 않고 **리스팅 전체를 보관**합니다.

리스팅 한 번이면 그 폴더의 모든 자식에 대한 존재·크기·수정시각·종류가 전부
답해지기 때문입니다:

```php
$filesystem->fileExists('reports/2026-08.csv');   // 경로를 걸어감: 요청 2회
$filesystem->fileSize('reports/2026-08.csv');     // 0회
$filesystem->lastModified('reports/2026-08.csv'); // 0회
$filesystem->mimeType('reports/2026-08.csv');     // 0회
$filesystem->fileExists('reports/2026-07.csv');   // 0회
```

캐시는 어댑터 인스턴스·프로세스 단위이고 10초 동안 신뢰합니다. 이 창이 감지할 수
없는 유일한 실패 — 다른 클라이언트가 폴더 이름을 바꿔 id는 살아 있지만 가리키는
곳이 달라지는 경우 — 를 제한합니다. SDK로 드라이브를 직접 변경했다면
`$adapter->clearCache()`를 부르고, 캐시가 아예 싫다면 `new NullDirectoryCache()`를
넘기세요. 대신 `fileExists('a/b/c.txt')` 한 번마다 리스팅 3회를 치릅니다.

## 제약

아래는 MYBOX API의 성질이지 어댑터의 미완성 부분이 아닙니다.

- **가시성 없음.** MYBOX에는 파일별 권한 개념이 전혀 없습니다. `visibility()`는
  설정한 값을 그대로 돌려주고, `setVisibility()`는 흉내 내는 대신 예외를 던집니다.
  `write()`의 `visibility` 옵션은 받되 무시하므로 Laravel의
  `Storage::put($path, $contents, 'public')`이 깨지지 않습니다.
- **공개 URL 없음.** 공유 엔드포인트가 없어 `PublicUrlGenerator`를 의도적으로
  구현하지 않았습니다. 캐시되는 페이지에 넣는 순간 `temporaryUrl()`은 거짓말이
  됩니다. 고정 URL이 필요하면 자체 컨트롤러로 서빙하고 Flysystem의
  `PrefixPublicUrlGenerator`를 설정하세요.
- **체크섬 없음.** MYBOX는 어떤 해시도 노출하지 않습니다. `Filesystem::checksum()`은
  여전히 동작하지만 파일을 통째로 내려받아 로컬에서 계산합니다. 그 비용을
  `ChecksumProvider` 뒤에 숨기지 않으려고 구현하지 않았습니다.
- **MIME 타입 없음.** API는 거친 카테고리만 알려주므로 확장자로 감지합니다.
  `MimeTypeDetector`를 직접 주입해 바꿀 수 있습니다.
- **`move()`는 원자적이지 않습니다.** MYBOX는 부모 변경과 이름 변경을 나눠 놓았고,
  이름 변경에는 덮어쓰기 플래그가 없어 목적지가 차 있으면 먼저 지웁니다. 그 사이에
  프로세스가 죽으면 파일이 중간 경로에 남습니다.
- **삭제는 휴지통을 채웁니다.** 기본값 `DeletionMode::Trash`에서는 지운 파일이
  MYBOX가 휴지통을 자동으로 비울 때까지 용량을 계속 차지합니다. `DeletionMode::Purge`를
  쓰거나 SDK의 `DriveApi::setTrashAutoDeleteDays()`로 보관 기간을 설정하세요.
- **요청 한도.** 다운로드는 요금제에 따라 하루 500~50,000회, 삭제를 비롯한 대부분은
  분당 60~240회입니다. 리스팅 캐시는 상당 부분 이 한도를 넘기지 않기 위해 있습니다.
- **파일 이름은 대소문자를 구분하지 않습니다.** `Case.txt`가 있는 폴더에
  `case.txt`를 쓰면 덮어쓰되 MYBOX는 원래 이름 `Case.txt`를 유지합니다. 그래서
  `listContents()`에는 `Case.txt`로 나옵니다. 어댑터가 이름을 대조할 때 대소문자를
  접으므로 어느 쪽 철자로 읽어도 동작합니다.

실계정으로 측정해 [docs/adapter-notes.md](docs/adapter-notes.md)에 기록했습니다:
Flysystem이 시험하는 특수문자는 파일 이름에 전부 허용되고, 0바이트 업로드도
되며, 폴더 리스팅은 최대 페이지 크기 1000을 받습니다.

## 개발

```bash
composer test      # 유닛 스위트, 네트워크 불필요
composer analyse   # PHPStan level 9
composer cs        # PHP-CS-Fixer, 검사만
```

[CONTRIBUTING.md](CONTRIBUTING.md)를 참고하세요. 통합 스위트는 실제 MYBOX 계정에
접속하며 기본적으로 꺼져 있습니다.

## 라이선스

MIT. [LICENSE](LICENSE)를 보세요.
