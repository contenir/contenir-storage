# Backfill and bulk operations

## regenerateMissingVariants()

On every backend: materialise the variants an asset owns but lacks, and
return the keys created. Idempotent. URL-transform backends return `[]`.

```php
$created = $storage->regenerateMissingVariants('asset/news/a.jpg');
```

## MissingVariantsReporterInterface

Read-only audit: the keys a backfill would create, without downloading or
writing. Implemented by every bundled backend.

```php
if ($storage instanceof MissingVariantsReporterInterface) {
    $missing = $storage->missingVariants('asset/news/a.jpg');
}
```

## BulkDeleteInterface

Delete exactly the given keys, with no variant sweep. Missing keys count as
deleted. Returns the keys that failed, with the backend's reason.

```php
$failed = $s3->deleteMany(['old/a.jpg', 'old/a__thumb.jpg']); // ['key' => 'reason']
```

## OnDemandVariantGeneratorInterface

`S3::generateForKey('gallery/cat__card-480.webp')` materialises one variant
from its key and returns its URL. It returns null for a key that is not a
variant key, an unknown variant, an unsupported format (jpg, jpeg, png, gif,
webp, avif are supported), a missing original, or a family the original's
path does not own.
