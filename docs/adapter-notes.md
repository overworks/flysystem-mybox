# Measured MYBOX behaviour

Naver documents none of this. Each entry changes a decision in the adapter, so
it is recorded here rather than left in someone's head. Re-measure with
`php tools/probe-adapter.php`, which works inside a throwaway folder and cleans
up after itself.

Measured 2026-08-19 against a live account.

| # | Question | Answer | What the adapter does about it |
|---|---|---|---|
| a | Is a top-level item's `parentId` a folder id that `FileApi::move()` accepts? | **Yes** | `ResourceLocator::rootId()` reads it off the root listing, so `move()` into the drive root is a single call. The `copy()` + `delete()` fallback remains for the one case that cannot be learned: an empty drive root, where nothing reports a parent id. |
| b | Does a zero-byte upload succeed? | **Yes** — HTTP 200, `fileSize 0` | Nothing special needed. `writeStream()` with an empty stream declares `fileSize: 0` and works. |
| c | Which special characters does MYBOX accept in a file name? | **All of them** — square brackets, curly brackets, spaces, `+ # % @ ' ( )`; nothing was rejected | `MyboxFilesystemAdapterTest` uses Flysystem's own `filenameProvider()` unchanged. Only *file* names were probed; folder names go through a different endpoint and were not measured separately. |
| d | Does `rename()` reject a name a sibling already holds? | **Yes** — HTTP 409 | `move()` deletes an occupied destination before renaming into it, because `rename()` has no `isOverwrite`. That pre-delete is required, not defensive. |
| e | Does a folder listing accept `count=1000`? | **Yes** | `MyboxAdapterOptions::$listPageSize` defaults to 1000, ten times MYBOX's own default of 100. |
| f | Are names case-sensitive within one folder? | **No** — writing `case.txt` over an existing `Case.txt` overwrote it and kept the spelling `Case.txt` | `Path\NameKey::fold()` lower-cases as well as NFC-normalises, and `ResourceLocator` folds cache keys too. Without it, `fileExists()` would miss the file `write()` had just created, and a snapshot reached through one spelling would go stale when the other spelling was written. |

Behaviour established by the SDK, and reproduced by
`tests/Double/InMemoryMyboxServer.php`:

- A trashed resource stays readable by id; only its `parentId` changes. This is
  why `fileExists()` is answered from a parent listing and never from
  `DriveApi::get()`.
- Purging is eventually consistent: the id answers for under a second
  afterwards, reporting a size of zero.
- An interrupted upload locks its file for a second or two, and re-reserving it
  returns HTTP 423.
- An upload whose declared `fileSize` does not match the bytes sent is answered
  with HTTP 500 `File Storage Error`, not a 4xx.
- `createFolder` has no `isOverwrite`, so a name collision is a hard 409.
