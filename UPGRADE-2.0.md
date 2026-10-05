# Upgrading from 0.x to 2.0

2.0 keeps the 0.6 API. The platform requirement changes, a few signatures
gain native types, and four bugs are fixed in ways that can change output.

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.1 | 8.3, 8.4 or 8.5 |
| league/flysystem | ^3.0 | ^3.0 |
| psr/log | ^1.1 \|\| ^2.0 \|\| ^3.0 | unchanged |

```bash
composer require contenir/storage:^2.0
```

Projects that must stay on PHP 8.1 or 8.2 can keep using `^0.6`, which is
maintained on the `0.x` branch.

## Typed class constants

`StorageInterface::THUMBNAIL_VARIANT`, `StorageManager::DEFAULT_PROFILE` and
`PathVariantResolver::WILDCARD` are declared `const string`. Only code that
redeclares them in an implementing class is affected; such a redeclaration
must now also be a string.

```php
// 0.x
public const THUMBNAIL_VARIANT = 'admin-thumb';

// 2.0
public const string THUMBNAIL_VARIANT = 'admin-thumb';
```

## The Thumbnail trait declares url()

The trait now declares the method it relies on:

```php
abstract public function url(string $path, ?string $variant = null): ?string;
```

Every `StorageInterface` implementation already has this method. A class that
uses the trait without implementing `StorageInterface` must provide a
compatible `url()`.

## CloudflareImages::missingVariants() for a missing asset

```php
// 0.x: Error "Call to undefined method …::missingVariants()" when the wrapped
// store does not implement MissingVariantsReporterInterface.
$cloudflare->missingVariants('gone.jpg');

// 2.0: NotFoundException, whatever the wrapped store implements.
```

## S3 variant upload failures

```php
try {
    $s3->store($upload, 'gallery');
} catch (\League\Flysystem\FilesystemException $e) {
    // 0.x: a failed *variant* upload landed here
} catch (\Contenir\Storage\Exception\WriteException $e) {
    // 2.0: failed original and variant uploads both land here
}
```

Bucket queries (existence checks and metadata reads) still let
`FilesystemException` through, as in 0.x.

## Local variants honour Variant::$quality

`LocalFilesystem` now passes `Variant::$quality` to the resizer, as `S3`
always did. Local variants of a variant declared with a `quality` are encoded
at that quality instead of the default 85. Run `regenerateMissingVariants()`
only if you want existing files rewritten; it skips variants already present.

## A '/' path entry owns every path

```php
$paths = new PathVariantResolver(['/' => ['og-image']]);

$paths->familiesFor('/blog/a.jpg');
// 0.x: []
// 2.0: ['og-image']
```

Sites that declared `'/'` expecting it to match nothing below it should
remove the entry; `'*'` remains the way to grant families to every path.

## Configuration entries that are not scalars

`StorageConfig` skips array or object entries in `paths.*.variants` and
`variants.*.formats`. 0.x cast them to the string `"Array"` with a warning.
