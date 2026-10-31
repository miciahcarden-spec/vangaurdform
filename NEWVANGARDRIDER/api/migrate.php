<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

api_load_environment();

try {
    $missing = api_database_missing();
    if ($missing !== []) {
        throw new RuntimeException('MySQL connection is not configured. Check ' . implode(', ', $missing) . '.');
    }

    $db = api_db();
    $schemaPath = dirname(__DIR__) . '/server/schema.sql';
    $schema = file_get_contents($schemaPath);
    if (!is_string($schema) || trim($schema) === '') {
        throw new RuntimeException('Could not read server/schema.sql.');
    }
    foreach (explode(';', $schema) as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }
    fwrite(STDOUT, "MySQL schema applied successfully.\n");
} catch (Throwable $error) {
    error_log('MySQL schema setup failed: ' . get_class($error) . ': ' . $error->getMessage());
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
