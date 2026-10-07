# contenir/contenir-storage

Formerly `contenir/storage`; the old package is abandoned in favour of this one.

[![Continuous Integration](https://github.com/contenir/contenir-storage/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/contenir-storage/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/contenir-storage/graph/badge.svg)](https://codecov.io/gh/contenir/contenir-storage)

Framework-agnostic asset storage for [Contenir CMS](https://github.com/contenir).

One `StorageInterface` for storing, listing, renaming and deleting CMS assets
on a local filesystem, an S3-compatible bucket (AWS S3, Cloudflare R2, MinIO)
or behind Cloudflare's URL image transforms, with a shared pipeline of named
image variants (thumbnails, responsive ladders, AVIF/WebP siblings).

- **Backends:** `LocalFilesystem`, `S3`, `CloudflareImages`, and `InMemoryStorage` for tests.
- **Variants:** `Variant`, `VariantRegistry`, art-directed `VariantProfile` ladders and
  per-path ownership through `PathVariantResolver`.
- **Uploads:** `UploadInput` from `$_FILES` or PSR-7, named and typed from the
  detected bytes by `DefaultUploadResolver`.
- **Configuration:** `StorageConfig` builds a `StorageManager` from one flat
  `storage` array.

## Requirements

- PHP 8.3, 8.4 or 8.5
- `league/flysystem` 3.29 or later
- ImageMagick, through the `imagick` extension or the `magick`/`convert` CLI,
  for variant generation
- `league/flysystem-aws-s3-v3` for the `s3` and `cloudflare-images` backends

## Install

```bash
composer require contenir/contenir-storage
```

Add `league/flysystem-aws-s3-v3` when you use an S3-compatible bucket:

```bash
composer require league/flysystem-aws-s3-v3
```

The 0.x releases, which support PHP 8.1, remain available from the `0.x`
branch and `v0.*` tags; see [UPGRADE-2.0.md](UPGRADE-2.0.md).

## Usage

### From configuration

```php
use Contenir\Storage\Config\StorageConfig;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\UploadInput;

$manager = StorageConfig::fromArray($config['storage'] ?? null, new ImageResizer(), '/var/www/public');
$storage = $manager->primary();

$entry = $storage->store(UploadInput::fromFilesArray($_FILES['image']), 'asset/library/news');

$storage->url($entry->path);                     // "/asset/library/news/photo.jpg"
$storage->url($entry->path, 'admin-thumb');      // variant URL, or null when not materialised
$storage->thumbnailUrl($entry->path);            // the same, null when the variant is undeclared
$storage->variantUrls($entry->path, 'card-480'); // ['avif' => …, 'source' => …], no existence checks
```

### By hand

```php
use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\StorageManager;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;

$variants = new VariantRegistry(
    new Variant('admin-thumb', 180, 180, VariantFit::Contain),
    new Variant('card', 600, 400, VariantFit::Cover, formats: ['avif', 'webp'], quality: 80),
);

$manager = new StorageManager();
$manager->register('local', new LocalFilesystem(
    rootPath: '/var/www/public',
    publicPath: '',
    variants: $variants,
    resizer: new ImageResizer(),
), isPrimary: true);
```

### The storage contract

| Method | Purpose |
| --- | --- |
| `store(UploadInput, string $directory): Entry` | Save an upload under a detected-type name and generate its variants |
| `url(string $path, ?string $variant = null): ?string` | Public URL, or null when the asset or variant is missing |
| `thumbnailUrl(string $path): ?string` | `url($path, 'admin-thumb')`, null when the variant is not declared |
| `urlsForKey(string $path): list<string>` | Every URL a CDN might cache for the asset (for purges) |
| `variantUrls(string $path, string $variant): array<string, string>` | Per-format variant URLs, built without I/O |
| `list(string $path, ?ListOptions): iterable<Entry>` | Browse a directory, filtered and sorted |
| `exists(string $path): bool` | Whether the asset exists |
| `delete(string $path): void` | Delete the asset and every variant sibling |
| `rename(string $from, string $to): void` | Move the asset and its variant siblings |
| `imageMeta(string $path): ImageMeta` | Width, height and MIME of an image |
| `regenerateMissingVariants(string $path): list<string>` | Backfill variants the asset is missing |

Optional capabilities are separate interfaces: `MissingVariantsReporterInterface`
(audit without writing), `BulkDeleteInterface` (delete an exact key list) and
`OnDemandVariantGeneratorInterface` (materialise one variant from its key).

## Documentation

- [Backends](docs/backends.md): local, S3, Cloudflare Images and in-memory
- [Configuration](docs/configuration.md): the `storage` array and `StorageConfig`
- [Variants](docs/variants.md): variants, profiles, formats and path ownership
- [Uploads](docs/uploads.md): `UploadInput`, naming, type detection, `PathResolver`
- [Image resizing](docs/image-resizing.md): `ImageResizer` and its test double
- [Backfill and bulk operations](docs/backfill.md)
- [Asset naming and provenance spec](docs/SPEC-asset-naming-and-provenance.md)

## Development

The QA toolchain is [contenir/contenir-qa-tools](https://github.com/contenir/contenir-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: no I/O, the bucket is an in-memory Flysystem
composer test-integration  # integration suite: real filesystem and ImageMagick in a temp directory
composer test-coverage     # both suites, clover.xml for Codecov
composer mutation-test     # Infection over both suites (needs pcov or Xdebug)
```

No test talks to a real cloud service. S3 behaviour is exercised against an
in-memory Flysystem double (`tests/TestAsset/Flysystem/FailingFilesystem`).

## License

MIT. See [LICENSE](LICENSE).
