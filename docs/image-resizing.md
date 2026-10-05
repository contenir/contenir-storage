# Image resizing

`ImageResizer::resize($source, $dest, $width, $height, $fit, $quality)` writes
a resized copy, creating the destination directory. The output format follows
the destination extension.

```php
$resizer = new ImageResizer();                         // auto-detect per call
$resizer = new ImageResizer(binaryPath: '/usr/bin/convert', useExtension: false);
```

- With `useExtension` left null, the `imagick` extension is used when it is
  loaded and its ImageMagick build supports the destination format; otherwise
  the `magick`/`convert` CLI is used.
- Without a `binaryPath`, the CLI is looked up with `which magick`, then
  `which convert`, then a few common install paths.
- Failures raise `WriteException`; invalid dimensions raise
  `InvalidArgumentException` (at least one dimension, and both for `Cover`
  and `Fill`).

## StubImageResizer

A drop-in for tests: records each call in `$calls` and writes a small
placeholder file instead of resizing.
