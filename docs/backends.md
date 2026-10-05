# Backends

Every backend implements `Contenir\Storage\StorageInterface`. Paths are
relative to the storage root, use forward slashes and never start with a
slash; leading/trailing slashes and backslashes are normalised away.

## LocalFilesystem

```php
new LocalFilesystem(
    rootPath: '/var/www/public',      // where bytes live
    publicPath: '',                   // URL prefix ('' gives root-relative URLs)
    variants: $variants,              // VariantRegistry
    resizer: new ImageResizer(),
    resolver: null,                   // UploadResolverInterface, default DefaultUploadResolver
    paths: $pathVariantResolver,      // optional ownership map
);
```

Layout:

```
<root>/<dir>/<file>                      the asset
<root>/<dir>/_variant/<variant>/<file>   each materialised variant, source extension
```

- `store()` copies the upload, resolving name collisions as `name_1.ext`,
  `name_2.ext`… and generates the variants its path owns (images only).
- `url($path, $variant)` lazily generates a missing variant for a real image;
  a resizer failure returns null so the caller can fall back to the original.
- `variantUrls()` returns the same URL for every declared format, because the
  local layout keeps one file per variant.
- `list()` skips dot files, `Thumbs.db` and `_variant`.
- `delete()` refuses directories; `rename()` refuses an existing destination.
- `localPath($path)` returns the absolute path, for code that needs a real file.
- Paths containing a null byte, `..` (also `%2e%2e`) or resolving outside the
  root through a symlink raise `InvalidPathException`.

## S3

```php
new S3(
    fs: $flysystem,                   // FilesystemOperator on an S3-compatible bucket
    publicUrlBase: 'https://cdn.example.com',
    variants: $variants,
    resizer: new ImageResizer(),
    resolver: null,
    paths: $pathVariantResolver,
    autoGenerate: false,
);
```

Variants are sibling objects: `photo.jpg` → `photo__card.avif`,
`photo__card.jpg`. The source format is always materialised so `<img>`
fallbacks resolve.

- Existence checks are cached per instance; `list()` warms the cache for every
  key it sees. Long-running workers call `clearKeyCache()`.
- `autoGenerate: true` stores only the original; an edge worker materialises
  variants later through `generateForKey()` or `regenerateMissingVariants()`.
- `imageMeta()` downloads the object to read its dimensions.
- Writes that fail raise `WriteException`. Bucket queries that fail (existence
  checks, metadata) let `League\Flysystem\FilesystemException` through.
- Implements `BulkDeleteInterface`, `MissingVariantsReporterInterface` and
  `OnDemandVariantGeneratorInterface`.

## CloudflareImages

Wraps another backend (normally an `S3` pointed at R2 with an empty
`VariantRegistry`) and serves variants through Cloudflare URL transforms:

```
https://cdn.example.com/cdn-cgi/image/width=180,height=180,fit=contain/products/hero.jpg
```

`Cover` maps to `fit=cover`, `Contain` to `fit=contain` and `Fill` to
`fit=crop`. Every non-URL method delegates to the wrapped store.
`regenerateMissingVariants()` and `missingVariants()` always return `[]` for
an existing asset and raise `NotFoundException` for a missing one.

## InMemoryStorage

A fake for tests that need the contract without disk or network. Seed it with
`makeDirectory()` and `putFile($path, $bytes, $mime, $width, $height)`;
variant URLs are deterministic (`memory://a.png?v=thumb&f=avif`) and no image
is ever transformed. `store()` still reads the upload from disk.

## StorageManager

A registry of named backends ("profiles"). The first registration, or the one
registered with `isPrimary: true`, is the primary.

```php
$manager->register('r2', $s3, isPrimary: true);
$manager->primary();       // StorageInterface
$manager->primaryKey();    // "r2"
$manager->get('local');    // InvalidArgumentException when unknown
$manager->has('local');
$manager->profiles();      // ['local', 'r2']
```
