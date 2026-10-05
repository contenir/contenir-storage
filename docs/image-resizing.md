# Image resizing

Backends depend on `Contenir\Storage\Image\ImageResizerInterface`, which has
one method, `resize()`. `ImageResizer` is the ImageMagick implementation;
supply your own implementation to resize some other way. Both bundled
implementations are `final`.

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
- `binaryPath()` reports the CLI in use. An empty `binaryPath` disables the
  CLI, so formats the extension cannot write fail with `WriteException`.
- Without a `binaryPath`, the CLI is looked up with `which magick`, then
  `which convert`, then the install paths in `binaryCandidates`, in order.
  These default to `ImageResizer::DEFAULT_BINARY_CANDIDATES`
  (`/usr/local/bin/magick`, `/usr/bin/magick`, `/opt/homebrew/bin/magick`,
  `/usr/local/bin/convert`, `/usr/bin/convert`); pass your own list for a
  host that installs ImageMagick elsewhere:

  ```php
  $resizer = new ImageResizer(binaryCandidates: ['/opt/imagemagick/bin/magick']);
  ```
- Failures raise `WriteException`; invalid dimensions raise
  `InvalidArgumentException` (at least one dimension, and both for `Cover`
  and `Fill`).

## StubImageResizer

An `ImageResizerInterface` for tests: records each call in `$calls` and writes a small
placeholder file instead of resizing.
