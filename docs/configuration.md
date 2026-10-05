# Configuration

`StorageConfig::fromArray($config, $resizer, $defaultRootPath)` builds a
`StorageManager` from the flat `storage` array. Three sibling keys:

```php
'storage' => [
    // WHERE bytes live. A `local` backend rooted at $defaultRootPath is always
    // pre-wired. The primary is the backend flagged 'default' => true, else `local`.
    'backend' => [
        'r2' => [
            'type'                 => 's3',             // local | s3 | cloudflare-images
            'default'              => true,
            'endpoint'             => 'https://<account>.r2.cloudflarestorage.com',
            'region'               => 'auto',
            'bucket'               => 'assets',
            'key'                  => '…',
            'secret'               => '…',
            'publicUrl'            => 'https://cdn.example.com', // or 'public_base_url'
            'usePathStyleEndpoint' => false,
            'auto_generate'        => false,            // s3: skip variant generation at upload
        ],
        'cf' => [
            'type'            => 'cloudflare-images',   // same keys as s3, plus:
            'deliveryBaseUrl' => 'https://cdn.example.com',
        ],
        // 'local' => ['type' => 'local', 'root_path' => '/abs/override', 'public_path' => '/media'],
    ],

    // WHAT transforms exist. Each may pin a 'backend' (default: the primary).
    'variants' => [
        'admin-thumb' => ['width' => 180, 'height' => 180, 'fit' => 'contain'],
        'hero'        => ['width' => 1600, 'height' => 900, 'fit' => 'cover', 'formats' => ['avif', 'webp'], 'quality' => 80],
        'gallery'     => ['dimensions' => ['320x', '640x', '1280x'], 'fit' => 'contain'],
    ],

    // WHICH variants a path owns. '*' is owned by every path.
    'paths' => [
        '*'                      => ['variants' => ['admin-thumb']],
        '/asset/library/news/lg' => ['variants' => ['gallery']],
    ],
],
```

Malformed configuration raises `InvalidArgumentException`: a backend or
variant that is not an array, an unknown backend type or fit, a variant
targeting an unknown backend, more than one `'default' => true`, or a missing
required key (`endpoint`, `key`, `secret`, `bucket`, `publicUrl`,
`deliveryBaseUrl`, `width`, `height`).

`usePathStyleEndpoint` and `auto_generate` follow PHP truthiness, so `1` and
`"1"` enable them.

## Helpers

| Method | Returns |
| --- | --- |
| `StorageConfig::default($resizer, $rootPath)` | A manager with only the implicit local backend |
| `StorageConfig::resolverFromArray($config)` | The `PathVariantResolver` for `storage.paths` |
| `StorageConfig::primaryBackendConfig($config)` | The primary backend's raw config block |
| `StorageConfig::variantNamesForBackend($config, $backend)` | The declared variant names assigned to a backend |

Variant names returned by `variantNamesForBackend()` are the declared names;
a `dimensions` ladder is reported once, not per rung.
