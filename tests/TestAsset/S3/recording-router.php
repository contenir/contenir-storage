<?php

/**
 * Router for PHP's built-in web server standing in for an S3 endpoint: it
 * appends each request's method, host, path and Authorization header to the
 * file named by S3_REQUEST_LOG, and answers as an empty bucket would.
 */

declare(strict_types=1);

file_put_contents(
    (string) getenv('S3_REQUEST_LOG'),
    json_encode([
        'method'        => $_SERVER['REQUEST_METHOD'] ?? '',
        'host'          => $_SERVER['HTTP_HOST'] ?? '',
        'path'          => parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    ])
        . "\n",
    FILE_APPEND,
);

http_response_code(404);
header('Content-Type: application/xml');
echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchKey</Code></Error>';
