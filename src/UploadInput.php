<?php

declare(strict_types=1);

namespace Contenir\Storage;

/**
 * Framework-agnostic upload descriptor consumed by StorageInterface::store().
 *
 * Callers construct this from $_FILES (ZF1) or PSR-7 UploadedFileInterface
 * (Mezzio) and hand it to the storage backend. The source file at $sourcePath
 * must already be readable; the backend takes ownership and may move or copy
 * it as required.
 */
final class UploadInput
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $clientFilename,
        public readonly ?string $clientMime = null,
    ) {}

    /**
     * Translate a single $_FILES["..."] entry into an UploadInput.
     *
     * Accepts the per-field array shape PHP populates for a successful upload:
     *   ['name' => string, 'tmp_name' => string, 'type' => string, ...].
     *
     * @param array<string, mixed> $file
     */
    public static function fromFilesArray(array $file): self
    {
        return new self(
            sourcePath: (string) ($file['tmp_name'] ?? ''),
            clientFilename: (string) ($file['name'] ?? ''),
            clientMime: null === ($file['type'] ?? null) ? null : (string) $file['type'],
        );
    }
}
