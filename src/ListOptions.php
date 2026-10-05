<?php

declare(strict_types=1);

namespace Contenir\Storage;

/**
 * Filter and sort options for StorageInterface::list().
 *
 * $keyword performs a case-insensitive substring match on the entry name.
 * $includeDirectories defaults to false to match the existing FileBrowser UI,
 * which lists files only.
 */
final class ListOptions
{
    public function __construct(
        public readonly ?string $keyword = null,
        public readonly SortField $sortField = SortField::Name,
        public readonly SortDirection $sortDirection = SortDirection::Asc,
        public readonly bool $includeDirectories = false,
    ) {}
}
