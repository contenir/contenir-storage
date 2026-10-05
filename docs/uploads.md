# Uploads

## UploadInput

```php
$upload = new UploadInput('/tmp/php123', 'Holiday Photo.JPG', 'image/jpeg');
$upload = UploadInput::fromFilesArray($_FILES['image']);
```

The source file must be readable. The client filename and MIME are hints
only; they never decide the stored name or type.

## DefaultUploadResolver

`store()` asks an `UploadResolverInterface` for the stored name. The default
one:

1. detects the type from the bytes (`getimagesize()`, then `finfo`);
2. maps the detected MIME to one canonical extension; the map is the
   allowlist, and anything outside it raises `UnsupportedTypeException`;
3. slugs the client basename (`Holiday Photo.JPG` + PNG bytes → `holiday-photo.png`).
   A name with no slug-safe characters raises `InvalidPathException`.

For raster images it also returns `ImageMeta` (width, height, MIME), which
`store()` puts on the returned `Entry::$image`.

```php
new DefaultUploadResolver(['image/png' => 'png', 'image/jpeg' => 'jpg']); // narrow the allowlist
```

See the [naming and provenance spec](SPEC-asset-naming-and-provenance.md).

## Entry

`store()` and `list()` return `Entry` objects: `id` (md5 of the name), `name`,
`path`, `isDir`, `size`, `mtime`, `mime`, `image`, plus `isFile()` and
`isImage()`.

## ListOptions

```php
new ListOptions(
    keyword: 'report',                  // case-insensitive substring of the name
    sortField: SortField::Time,         // Name, Time, Size, Type
    sortDirection: SortDirection::Desc,
    includeDirectories: true,
);
```

## PathResolver (legacy field configs)

`PathResolver` serves the legacy field-config option bags that list one
derived file per option array:

```php
$resolver = new PathResolver('/var/www/public', new ImageResizer());
$resolver->resolve(
    ['path' => 'asset/products', 'prefix' => 'shoes', 'suffix' => '-lg', 'width' => 1200, 'height' => 800, 'mimeType' => 'image/jpeg'],
    '/tmp/upload',
    'red-shoe',
); // "/asset/products/shoes/red-shoe-lg.jpg"

$resolver->sanitiseBasename('Red Shoe!'); // "red-shoe-"
```

JPEG, PNG and GIF with a width or height are resized (contain); everything
else is copied. It is transitional and goes away with the field-config
redesign.
