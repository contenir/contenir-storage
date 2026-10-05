# Variants

## Variant

```php
new Variant(
    name: 'card',
    width: 600,
    height: 400,
    fit: VariantFit::Cover,      // Cover (crop to fill), Contain (fit inside), Fill (stretch)
    formats: ['avif', 'webp'],   // extra output formats; the source format is always added
    quality: 80,                 // encoder quality, default 85
);

$variant->targetFormats();       // ['avif', 'webp', null]; null = the source extension
```

`Cover` and `Fill` need both dimensions. `Contain` accepts one of them as 0
and keeps the source aspect ratio.

## VariantRegistry

```php
$registry = new VariantRegistry($thumb, $card);
$registry->has('card');
$registry->get('card');                    // InvalidArgumentException when unknown
$registry->all();                          // list<Variant>
$registry->allowedFor($paths, 'news/a.jpg'); // the variants that path owns
```

## VariantProfile: dimension ladders

A variant declared with `dimensions` compiles to one variant per rung, named
`<profile>-<width>` (or `<profile>-x<height>` for height-only rungs):

```php
$profile = VariantProfile::fromArray('card', [
    'dimensions' => ['320x320', '480x480', '768x' => ['quality' => 70]],
    'fit'        => 'cover',
    'formats'    => ['avif'],
    'sizes'      => '(min-width: 768px) 50vw, 100vw',
]);

$profile->variants;   // card-320, card-480, card-768
$profile->sizes;      // the front-end `sizes` attribute
$profile->isPreview;  // true for 'role' => 'preview' (allows height-only rungs)
```

Each rung may override `fit`, `quality` and `formats`. A front-end profile
needs a width on every rung, and rung names must be unique.

## PathVariantResolver: ownership

```php
$paths = new PathVariantResolver([
    '*'               => ['admin-thumb'],
    '/'               => ['og-image'],
    '/asset/news/lg'  => ['gallery'],
]);

$paths->familiesFor('/asset/news/lg/a.jpg'); // ['gallery', 'admin-thumb']
$paths->allows('/asset/news/lg/a.jpg', 'gallery-480'); // rung → family "gallery"
PathVariantResolver::family('gallery-x300');           // "gallery"
$paths->isConfigured();
```

The longest base path matching at a segment boundary wins, unioned with the
`'*'` families. `'/'` is a base path like any other and owns every path
without a more specific entry. An unconfigured resolver allows every variant.

Generation (`store()`, `regenerateMissingVariants()`, `generateForKey()`)
honours ownership. Cleanup (`delete()`, `rename()`) deliberately does not, so
siblings written before an ownership change are still removed or moved.
