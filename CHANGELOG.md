# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.2.0] - 2026-10-05

### Changed

- Renamed from `contenir/storage` to `contenir/contenir-storage`. The package
  declares `replace` for the old name; require `contenir/contenir-storage`
  instead. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- Removed dead code that only Infection exclusions kept covered; behaviour is
  unchanged:
  - the unknown-variant check in `variantUrls()` on `CloudflareImages`,
    `InMemoryStorage` and `S3` (`VariantRegistry::get()` throws the same
    `InvalidArgumentException` with the same message);
  - `setBackgroundColor('transparent')` in the Imagick resize pipeline, which
    nothing in the pipeline reads;
  - `'version' => 'latest'` in the S3 client config, the AWS SDK default;
  - the second, dash-collapsing pass in `PathResolver::sanitiseBasename()`,
    since the first pass already turns each run of non-word characters into a
    single dash.

## [2.1.1] - 2026-10-05

### Fixed

- `S3` left its downloaded source in the system temp directory when the
  download failed part-way, whether the stream copy reported failure or the
  stream threw. The temp file is now removed in both cases before the error
  is passed on.

## [2.1.0] - 2026-10-05

### Added

- Infection mutation testing in CI, MSI 100%.
- `ImageResizer` takes an optional `binaryCandidates` list of install paths
  to probe for the ImageMagick CLI; it defaults to the new
  `ImageResizer::DEFAULT_BINARY_CANDIDATES`, the previous built-in list.

### Fixed

- The `imagick` backend could resize a one-sided `Contain` request a pixel
  short of the given side (e.g. 70×40 at width 6 gave 5×3); it now matches
  the CLI (6×3).

## [2.0.0] - 2026-10-05

The public API is unchanged apart from the typing noted below. The major
version marks the move to PHP 8.3+ and the php-db QA toolchain shared by all
Contenir 2.x packages. See [UPGRADE-2.0.md](UPGRADE-2.0.md).

### Changed

- Requires PHP 8.3, 8.4 or 8.5. PHP 8.1 and 8.2 are no longer supported.
- Requires `league/flysystem` ^3.29, the first release free of PHP 8.4
  implicit-nullable deprecations.
- Every concrete class is `final`. `ImageResizer` is final and implements the
  new `Image\ImageResizerInterface`; `LocalFilesystem`, `S3`, `PathResolver`
  and `StorageConfig` accept any `ImageResizerInterface`, and
  `StubImageResizer` implements the interface instead of extending
  `ImageResizer`.
- `Exception\StorageException` is abstract. Every exception the package
  throws still extends it, so `catch (StorageException)` is unchanged.
- An empty `binaryPath` passed to `ImageResizer` disables the CLI instead of
  running an empty command.
- Class constants are typed (`StorageInterface::THUMBNAIL_VARIANT`,
  `StorageManager::DEFAULT_PROFILE`, `PathVariantResolver::WILDCARD`).
- The `Thumbnail` trait declares the `url()` method it calls as abstract.
- No `@` operator remains. Warnings from filesystem and image calls whose
  failure is reported by their return value are discarded by an error handler
  scoped to the call.
- `S3::delete()` removes variant siblings with one idempotent delete per key
  instead of an existence check followed by a delete.
- `StorageConfig` ignores non-scalar entries in `paths.*.variants` and
  `variants.*.formats` instead of casting them to strings.
- `@throws` documentation on `StorageInterface` and the adapters now lists
  every exception they raise (`InvalidPathException`, `UnsupportedTypeException`,
  and Flysystem's `FilesystemException` on `S3`).

### Fixed

- `CloudflareImages::missingVariants()` raised an `Error` for a missing asset
  when the wrapped store did not implement `MissingVariantsReporterInterface`.
  It now raises `NotFoundException` itself.
- `LocalFilesystem` ignored `Variant::$quality` when generating variants.
- `S3` let a failed variant upload escape as a Flysystem exception; it is now
  a `WriteException`, like a failed original upload.
- A `'/'` entry in `storage.paths` only matched the path `/` itself instead of
  owning every path below it.

### Added

- `Image\ImageResizerInterface` and `ImageResizer::binaryPath()`.
- `docs/` pages for each feature area.
- Continuous integration on PHP 8.3, 8.4 and 8.5 against lowest, locked and
  latest dependencies, with coverage reported to Codecov.
- Separate unit (no I/O, in-memory bucket) and integration (real filesystem
  and ImageMagick) suites.

### Licence

- Still MIT. The copyright holder is now Contenir, and the permission notice
  restores the missing "USE OR OTHER" wording.

### Removed

- `squizlabs/php_codesniffer` and `phpcs.xml`, replaced by Mago via
  `php-db/phpdb-qa-tools`.
- The `gumlet/php-image-resize` and `cloudflare/sdk` dev dependencies and
  suggestions; nothing used them.
- The integration test that wrote to a live R2 bucket. S3 behaviour is tested
  against an in-memory Flysystem.

## [0.6.x] - 2026-08-25 to 2026-09-04

- `MissingVariantsReporterInterface`, `BulkDeleteInterface`, the S3
  `auto_generate` option, `public_base_url` as an alias of `publicUrl`,
  psr/log 1–3, PHP 8.1 support. Generation materialises the source format and
  honours path ownership.

## [0.5.x] - 2026-06-26 to 2026-08-21

- Flat `backend`/`variants`/`paths` config schema, `PathVariantResolver`,
  `StorageConfig::variantNamesForBackend()` and `primaryBackendConfig()`, and
  the imagick extension preferred over the CLI in `ImageResizer`.

## [0.4.x] - 2026-06-24 to 2026-06-26

- Art-directed variant profiles (dimension ladders); the local root inherited
  from the shared asset block.

## [0.3.x] - 2026-05-15 to 2026-06-23

- Per-variant output formats and alpha preservation,
  `regenerateMissingVariants()`, streamed source downloads and
  `clearKeyCache()`, the `_variant` directory, byte-detected upload naming
  (`DefaultUploadResolver`), deterministic `variantUrls()`, and on-demand
  single-variant generation on S3.

## [0.2.x] - 2026-05-06 to 2026-05-12

- `thumbnailUrl()`, lazy variant generation in `LocalFilesystem::url()`, the
  licence file, and a fix for contain-resizing with one dimension.

## [0.1.0] - 2026-05-05

- Initial release: `StorageInterface` with local, S3 and Cloudflare Images
  backends and a shared variant pipeline.
