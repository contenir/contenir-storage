<?php

declare(strict_types=1);

namespace Contenir\Storage;

final class ImageMeta
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $mime,
    ) {}
}
