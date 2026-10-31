<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const RIDER_COLUMNS = [
    'full_name', 'date_of_birth', 'place_of_birth', 'state_of_origin',
    'state_of_origin_city', 'state_of_origin_address', 'phone_number',
    'email_address', 'residential_address', 'residential_state', 'residential_city',
    'residential_postal_code', 'emergency_contact_name', 'emergency_contact_phone',
    'relationship_to_emergency_contact', 'marital_status', 'has_delivery_experience',
    'employment_history', 'communication_skills', 'motorcycle_safety', 'motorcycle_maintenance',
    'health_challenge', 'health_details', 'skills_experience', 'nin', 'nin_document_path',
    'identity_card_front_path', 'identity_card_back_path', 'video_path',
    'location_latitude', 'location_longitude', 'location_accuracy_meters',
    'location_captured_at', 'work_policy_accepted', 'terms_accepted', 'terms_signature',
];
const GUARANTOR_EDITABLE_COLUMNS = [
    'name', 'phone_number', 'status', 'submitted_at', 'guarantor_address',
    'guarantor_state', 'guarantor_city', 'guarantor_postal_code',
    'state_of_origin', 'state_of_origin_city', 'state_of_origin_address',
    'guarantor_phone_confirmed', 'relationship', 'occupation', 'nin',
    'nin_document_path', 'identity_card_front_path', 'video_path',
    'digital_signature', 'confirmation', 'work_policy_accepted',
    'location_latitude', 'location_longitude', 'location_accuracy_meters',
    'location_captured_at',
];
const JSON_COLUMNS = ['employment_history'];
const NIGERIAN_STATES = [
    'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue',
    'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'Gombe',
    'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara',
    'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau',
    'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara', 'Federal Capital Territory',
];

function api_rider_editable_columns(): array
{
    return array_merge(RIDER_COLUMNS, ['status', 'terms_signed_at', 'company_representative_signature']);
}

function api_decode_binary(mixed $value): string
{
    if (is_resource($value)) {
        $value = stream_get_contents($value);
    }
    if (!is_string($value)) {
        return '';
    }
    return $value;
}

function api_uuid(string $value): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
}

function api_file(string $field, bool $required, int $maxBytes = API_MAX_UPLOAD_BYTES): ?array
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new ApiException(400, 'Required uploaded file is missing.');
        }
        return null;
    }
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ApiException(413, 'Uploaded file exceeds the server upload limit.');
        }
        throw new ApiException(400, 'File upload did not complete successfully.');
    }
    $tmpName = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    api_require(is_uploaded_file($tmpName), 'Uploaded file is invalid.');
    api_require($size > 0 && $size <= $maxBytes, $maxBytes === 2097152
        ? 'Upload a PNG, JPEG, or WebP logo (maximum 2 MB).'
        : 'Uploaded file must be no larger than 50 MB.', $size > $maxBytes ? 413 : 400);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)$finfo->file($tmpName));
    $name = basename((string)($file['name'] ?? 'upload'));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $videoExtensions = ['mp4', 'm4v', 'mov', 'webm', 'mkv', '3gp', 'ogv', 'avi'];
    $isVideo = str_starts_with($mime, 'video/') || in_array($extension, $videoExtensions, true);
    $isImageOrPdf = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
    if ($maxBytes === 2097152) {
        api_require(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 'Upload a PNG, JPEG, or WebP logo (maximum 2 MB).');
    } else {
        api_require($isImageOrPdf || ($isVideo && str_ends_with($field, '_video')),
            'Unsupported file type. Use a JPEG, PNG, WebP, PDF, or a supported video recording.');
    }

    return ['filename' => $name, 'content_type' => $mime, 'size' => $size, 'tmp_name' => $tmpName];
}

function api_upload_count(): void
{
    api_require(count($_FILES) <= 4, 'Too many uploaded files.');
}

function api_store_file(PDO $db, ?array $file): ?string
{
    if ($file === null) {
        return null;
    }
    $contents = file_get_contents($file['tmp_name']);
    api_require(is_string($contents) && strlen($contents) === $file['size'], 'Could not read the uploaded file.');
    $id = api_uuid_v4();
    $statement = $db->prepare(
        'insert into application_files (id, filename, content_type, size_bytes, file_data)
         values (:id, :filename, :content_type, :size, :data)'
    );
    $statement->bindValue(':id', $id);
    $statement->bindValue(':filename', $file['filename']);
    $statement->bindValue(':content_type', $file['content_type']);
    $statement->bindValue(':size', $file['size'], PDO::PARAM_INT);
    $statement->bindValue(':data', $contents, PDO::PARAM_LOB);
    $statement->execute();
    unset($contents);
    return $id;
}

function api_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function api_parse_form_json(string $name): array
{
    $value = $_POST[$name] ?? '';
    api_require(is_string($value) && $value !== '', "Invalid {$name} data.");
    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiException(400, "Invalid {$name} data.");
    }
    api_require(is_array($decoded), "Invalid {$name} data.");
    return $decoded;
}

function api_validate_location(array $payload): void
{
    $latitude = $payload['location_latitude'] ?? null;
    $longitude = $payload['location_longitude'] ?? null;
    $accuracy = $payload['location_accuracy_meters'] ?? null;
    $capturedAt = $payload['location_captured_at'] ?? null;
    if ($latitude === null && $longitude === null && $accuracy === null && $capturedAt === null) {
        return;
    }

    $captured = is_string($capturedAt) ? strtotime($capturedAt) : false;
    api_require(is_numeric($latitude) && is_numeric($longitude) && is_numeric($accuracy)
        && is_finite((float)$latitude) && is_finite((float)$longitude) && is_finite((float)$accuracy)
        && (float)$latitude >= -90 && (float)$latitude <= 90
        && (float)$longitude >= -180 && (float)$longitude <= 180
        && (float)$accuracy >= 0
        && $captured !== false && $captured > 0,
        'Location data is incomplete or invalid. Submit again to retry automatic location capture.');
}

function api_validate_rider(array $rider, array $guarantors, array $files): void
{
    api_require(isset($rider['full_name']) && is_string($rider['full_name']) && trim($rider['full_name']) !== '', 'Rider name is required.');
    api_require(isset($rider['phone_number']) && is_string($rider['phone_number']) && trim($rider['phone_number']) !== '', 'Rider phone is required.');
    api_require(in_array($rider['state_of_origin'] ?? null, NIGERIAN_STATES, true), 'Select a valid Nigerian state or the Federal Capital Territory.');
    api_require(is_string($rider['state_of_origin_city'] ?? null) && trim($rider['state_of_origin_city']) !== ''
        && is_string($rider['state_of_origin_address'] ?? null) && trim($rider['state_of_origin_address']) !== '',
        'State of origin city and address are required.');
    api_require(in_array($rider['residential_state'] ?? null, NIGERIAN_STATES, true)
        && is_string($rider['residential_city'] ?? null) && trim($rider['residential_city']) !== '',
        'Select a residential state and city or area.');
    api_require(is_string($rider['nin'] ?? null) && preg_match('/^\d{11}$/', $rider['nin']) === 1,
        'Rider NIN must contain exactly 11 digits.');
    foreach (['ninDocument', 'identityCardFront', 'identityCardBack', 'video'] as $requiredFile) {
        api_require(isset($files[$requiredFile]), 'Rider required document or video is missing.');
    }
    api_require(($rider['work_policy_accepted'] ?? false) === true, 'Rider must accept the earnings and work policy.');
    api_require(($rider['terms_accepted'] ?? false) === true
        && is_string($rider['terms_signature'] ?? null) && trim($rider['terms_signature']) !== '',
        'Terms acceptance and signature are required.');
    foreach (['has_delivery_experience', 'communication_skills', 'motorcycle_safety', 'motorcycle_maintenance', 'health_challenge'] as $key) {
        api_require(is_bool($rider[$key] ?? null), 'Please answer every experience and skills question.');
    }
    api_require(is_array($rider['employment_history'] ?? null), 'Employment history must be a list.');
    api_require(count($guarantors) === 2, 'Exactly two guarantors are required.');
    foreach ($guarantors as $guarantor) {
        api_require(is_array($guarantor)
            && is_string($guarantor['name'] ?? null) && trim($guarantor['name']) !== ''
            && is_string($guarantor['phone_number'] ?? null) && trim($guarantor['phone_number']) !== '',
            'Both guarantor names and phone numbers are required.');
    }
    api_validate_location($rider);
}

function api_validate_guarantor(array $payload, array $files): void
{
    foreach (['guarantor_address', 'guarantor_state', 'guarantor_city', 'state_of_origin',
        'state_of_origin_city', 'state_of_origin_address', 'guarantor_phone_confirmed',
        'relationship', 'occupation', 'digital_signature'] as $key) {
        api_require(is_string($payload[$key] ?? null) && trim($payload[$key]) !== '',
            'Required guarantor information and signature must be provided.');
    }
    api_require(in_array($payload['guarantor_state'], NIGERIAN_STATES, true), 'Select a valid Nigerian residential state.');
    api_require(in_array($payload['state_of_origin'], NIGERIAN_STATES, true), 'Select a valid state of origin.');
    api_require(is_string($payload['nin'] ?? null) && preg_match('/^\d{11}$/', $payload['nin']) === 1,
        'Guarantor NIN must contain exactly 11 digits.');
    api_require(isset($files['ninDocument']) && isset($files['video']), 'Guarantor required document or video is missing.');
    api_require(($payload['confirmation'] ?? false) === true, 'Guarantor confirmation is required.');
    api_require(($payload['work_policy_accepted'] ?? false) === true, 'Guarantor must accept the rider work policy.');
    api_validate_location($payload);
}

function api_is_truthy_mysql(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
}

function api_decode_rider(array $rider): array
{
    foreach (['employment_history', 'guarantors'] as $key) {
        if (isset($rider[$key]) && is_string($rider[$key])) {
            $decoded = json_decode($rider[$key], true);
            $rider[$key] = is_array($decoded) ? $decoded : [];
        }
    }
    foreach (['has_delivery_experience', 'communication_skills', 'motorcycle_safety',
        'motorcycle_maintenance', 'health_challenge', 'work_policy_accepted', 'terms_accepted'] as $key) {
        if (array_key_exists($key, $rider)) {
            $rider[$key] = api_is_truthy_mysql($rider[$key]);
        }
    }
    return $rider;
}

function api_collect_file_ids(array $record): array
{
    $ids = [];
    $visit = static function (mixed $value) use (&$visit, &$ids): void {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && str_ends_with($key, '_path') && is_string($item) && api_uuid($item)) {
                $ids[$item] = true;
            } elseif (is_array($item)) {
                $visit($item);
            }
        }
    };
    $visit($record);
    return array_keys($ids);
}

function api_delete_files(PDO $db, array $ids): void
{
    $ids = array_values(array_filter($ids, 'api_uuid'));
    if ($ids === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $db->prepare("delete from application_files where id in ({$placeholders})");
    $statement->execute($ids);
}

function api_encrypt_telegram(array $credentials): array
{
    $encoded = api_env('TELEGRAM_SETTINGS_ENCRYPTION_KEY');
    $key = is_string($encoded) ? base64_decode($encoded, true) : false;
    if (!is_string($key) || strlen($key) !== 32) {
        throw new ApiException(503, 'Telegram settings are unavailable until TELEGRAM_SETTINGS_ENCRYPTION_KEY is configured as a base64-encoded 32-byte key.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(json_encode($credentials, JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    api_require(is_string($ciphertext), 'Could not encrypt Telegram settings.', 500);
    return ['encrypted' => $ciphertext, 'nonce' => $nonce, 'tag' => $tag];
}

function api_decrypt_telegram(array $row): array
{
    $encoded = api_env('TELEGRAM_SETTINGS_ENCRYPTION_KEY');
    $key = is_string($encoded) ? base64_decode($encoded, true) : false;
    if (!is_string($key) || strlen($key) !== 32) {
        throw new ApiException(503, 'Telegram settings cannot be decrypted. Configure the original TELEGRAM_SETTINGS_ENCRYPTION_KEY.');
    }
    $plaintext = openssl_decrypt(
        api_decode_binary($row['encrypted_credentials']),
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        api_decode_binary($row['nonce']),
        api_decode_binary($row['auth_tag']),
    );
    api_require(is_string($plaintext), 'Telegram settings could not be decrypted.', 500);
    $credentials = json_decode($plaintext, true);
    api_require(is_array($credentials), 'Telegram settings could not be decrypted.', 500);
    return $credentials;
}

function api_clean_telegram_value(string $value, int $maxLength): string
{
    $clean = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');
    return function_exists('mb_substr') ? mb_substr($clean, 0, $maxLength, 'UTF-8') : substr($clean, 0, $maxLength);
}

function api_telegram_credentials(): ?array
{
    $environmentToken = trim((string)api_env('TELEGRAM_BOT_TOKEN', ''));
    $environmentChatId = trim((string)api_env('TELEGRAM_CHAT_ID', ''));
    if ($environmentToken !== '' || $environmentChatId !== '') {
        api_require(
            preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $environmentToken) === 1
            && preg_match('/^(?:-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/', $environmentChatId) === 1,
            'Both TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID must be valid when configured.',
            500
        );
        return ['bot_token' => $environmentToken, 'chat_id' => $environmentChatId];
    }

    $statement = api_db()->query('select encrypted_credentials, nonce, auth_tag from telegram_settings where id = 1');
    $row = $statement->fetch();
    if (!$row) {
        return null;
    }

    return api_decrypt_telegram($row);
}

function api_admin_application_url(string $riderId): string
{
    $baseUrl = rtrim((string)api_env('APP_URL', ''), '/');
    if ($baseUrl === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        api_require(
            preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9a-fA-F:]+\])(?::\d{1,5})?$/', $host) === 1,
            'Set APP_URL to a valid public application URL.',
            500
        );
        $forwardedProtocol = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $isSecure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || $forwardedProtocol === 'https';
        $baseUrl = ($isSecure ? 'https://' : 'http://') . $host;
    } else {
        $parts = parse_url($baseUrl);
        api_require(
            is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && !array_key_exists('user', $parts)
            && !array_key_exists('pass', $parts)
            && !array_key_exists('query', $parts)
            && !array_key_exists('fragment', $parts),
            'APP_URL must be an absolute HTTP or HTTPS URL without credentials or query parameters.',
            500
        );
    }

    return $baseUrl . '/admin/dashboard?application=' . rawurlencode($riderId);
}

function api_send_telegram(array $rider, string $riderId, array $guarantors, ?array $submittedGuarantor = null): string
{
    $credentials = api_telegram_credentials();
    if ($credentials === null) {
        return 'not_configured';
    }
    $lines = [
        $submittedGuarantor === null ? 'New rider application received' : 'Guarantor form submitted',
        'Application ID: ' . $riderId,
    ];
    if ($submittedGuarantor === null) {
        $lines[] = 'Applicant: ' . api_clean_telegram_value((string)$rider['full_name'], 200);
        $lines[] = 'Phone: ' . api_clean_telegram_value((string)$rider['phone_number'], 80);
        $lines[] = 'Email: ' . api_clean_telegram_value((string)(($rider['email_address'] ?? '') ?: 'Not provided'), 200);
        $lines[] = 'State of origin: ' . api_clean_telegram_value((string)$rider['state_of_origin'], 100);
        $lines[] = 'Origin city: ' . api_clean_telegram_value((string)$rider['state_of_origin_city'], 150);
        $lines[] = 'Residential location: ' . api_clean_telegram_value((string)$rider['residential_city'] . ', ' . (string)$rider['residential_state'], 250);
        foreach ($guarantors as $index => $guarantor) {
            $lines[] = 'Guarantor ' . ($index + 1) . ': '
                . api_clean_telegram_value((string)$guarantor['name'], 200) . ' ('
                . api_clean_telegram_value((string)$guarantor['phone_number'], 80) . ')';
        }
    } else {
        $lines[] = 'Applicant: ' . api_clean_telegram_value((string)$rider['full_name'], 200);
        $lines[] = 'Guarantor: ' . api_clean_telegram_value((string)$submittedGuarantor['name'], 200);
        $lines[] = 'Phone: ' . api_clean_telegram_value((string)$submittedGuarantor['phone_number'], 80);
        $lines[] = 'Occupation: ' . api_clean_telegram_value((string)$submittedGuarantor['occupation'], 150);
        $lines[] = 'Location: ' . api_clean_telegram_value((string)$submittedGuarantor['guarantor_city'] . ', ' . (string)$submittedGuarantor['guarantor_state'], 250);
    }
    $lines[] = 'Submitted: ' . gmdate('c');
    $lines[] = 'Admin review: ' . api_admin_application_url($riderId);
    $payload = json_encode(['chat_id' => $credentials['chat_id'], 'text' => implode("\n", $lines)], JSON_THROW_ON_ERROR);
    $url = 'https://api.telegram.org/bot' . $credentials['bot_token'] . '/sendMessage';
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($curl);
        curl_close($curl);
        if ($response === false || $curlError !== 0 || $status < 200 || $status >= 300) {
            throw new RuntimeException('Telegram notification request failed.');
        }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (!is_string($response) || !preg_match('/\s2\d\d\s/', $statusLine)) {
            throw new RuntimeException('Telegram notification request failed.');
        }
    }
    $result = json_decode((string)$response, true);
    if (!is_array($result) || ($result['ok'] ?? false) !== true) {
        throw new RuntimeException('Telegram notification was rejected.');
    }
    return 'sent';
}



function api_response_file(array $row, bool $secure): never
{
    header('Content-Type: ' . $row['content_type']);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($secure) {
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string)$row['filename']));
        header('Content-Security-Policy: sandbox');
    }
    echo api_decode_binary($row['file_data'] ?? $row['image_data'] ?? '');
    exit;
}

function api_db_value(string $column, mixed $value): mixed
{
    if (($column === 'date_of_birth' || str_ends_with($column, '_at')) && $value === '') {
        return null;
    }
    if (str_ends_with($column, '_at') && is_string($value) && $value !== '') {
        $timestamp = strtotime($value);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
    if (in_array($column, JSON_COLUMNS, true)) {
        return json_encode($value ?? [], JSON_THROW_ON_ERROR);
    }
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    return $value;
}

function api_update_statement(string $table, string $idColumn, string $id, array $fields, array $allowed): ?array
{
    $entries = [];
    foreach ($fields as $key => $value) {
        if (is_string($key) && in_array($key, $allowed, true)) {
            $entries[$key] = $value;
        }
    }
    if ($entries === []) {
        return null;
    }
    $assignments = [];
    $values = [];
    foreach ($entries as $key => $value) {
        $assignments[] = $key . ' = ?';
        $values[] = api_db_value($key, $value);
    }
    if ($table === 'riders') {
        $assignments[] = 'updated_at = CURRENT_TIMESTAMP';
    }
    $values[] = $id;
    return [
        'sql' => "update {$table} set " . implode(', ', $assignments) . " where {$idColumn} = ?",
        'values' => $values,
    ];
}

function api_encode_rider_row(array $rider): array
{
    return api_decode_rider($rider);
}

function api_dispatch_admin(string $method, string $path, array $body): never
{
    $db = api_db();

    if ($method === 'POST' && $path === '/api/admin/login') {
        api_ensure_same_origin();
        api_rate_limit();
        $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        api_require($email !== '' && $password !== '', 'Email and password are required.');
        api_require(strlen($password) <= 1024, 'Password is too long.');
        $statement = $db->prepare('select id, password_hash from admin_users where email = ?');
        $statement->execute([$email]);
        $admin = $statement->fetch();
        $storedHash = is_array($admin) ? (string)$admin['password_hash'] : '';
        if (str_starts_with($storedHash, '$2a$') || str_starts_with($storedHash, '$2b$')) {
            $storedHash = '$2y$' . substr($storedHash, 4);
        }
        if (!$admin || !password_verify($password, $storedHash)) {
            api_error(401, 'Email or password is incorrect.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
           $db->exec('delete from admin_sessions where expires_at <= CURRENT_TIMESTAMP');
        $statement = $db->prepare(
            "insert into admin_sessions (admin_user_id, session_hash, expires_at)
               values (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 12 HOUR))"
        );
        $statement->execute([$admin['id'], api_token_hash($token)]);
        api_set_session_cookie($token, API_SESSION_SECONDS);
        api_json(['success' => true]);
    }

    if ($method === 'GET' && $path === '/api/admin/registration-status') {
        $statement = $db->prepare(
            "select (select count(*) from admin_users) = 0 as bootstrap_open,
                    exists (select 1 from admin_sessions s
                    join admin_users u on u.id = s.admin_user_id
                    where s.session_hash = ? and s.expires_at > CURRENT_TIMESTAMP) as authenticated"
        );
        $token = api_cookie_token();
        $statement->execute([$token !== '' ? api_token_hash($token) : '']);
        $row = $statement->fetch() ?: [];
        api_json([
            'bootstrap_open' => api_is_truthy_mysql($row['bootstrap_open'] ?? false),
            'authenticated' => api_is_truthy_mysql($row['authenticated'] ?? false),
        ]);
    }

    if ($method === 'POST' && $path === '/api/admin/register') {
        api_ensure_same_origin();
        api_rate_limit();
        $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        api_require(preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email) === 1 && strlen($email) <= 254, 'Enter a valid email address.');
        api_require(strlen($password) >= 12 && strlen($password) <= 1024, 'Password must be at least 12 characters.');
        $createdAdmin = null;
        $bootstrap = false;
        try {
            $db->beginTransaction();
            $db->query("select version from schema_migrations where version = 'mysql_full_schema_001' for update");
            $count = (int)$db->query('select count(*) from admin_users')->fetchColumn();
            $bootstrap = $count === 0;
            if (!$bootstrap) {
                $token = api_cookie_token();
                $statement = $db->prepare(
                    'select u.id from admin_sessions s join admin_users u on u.id = s.admin_user_id
                     where s.session_hash = ? and s.expires_at > CURRENT_TIMESTAMP'
                );
                $statement->execute([$token !== '' ? api_token_hash($token) : '']);
                api_require((bool)$statement->fetch(), 'Only a signed-in administrator can register another administrator.', 401);
            }
            $createdAdmin = api_uuid_v4();
            $statement = $db->prepare('insert into admin_users (id, email, password_hash) values (?, ?, ?)');
            $statement->execute([$createdAdmin, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])]);
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ((int)($error->errorInfo[1] ?? 0) === 1062) {
                throw new ApiException(409, 'An administrator with this email already exists.');
            }
            throw $error;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        if ($bootstrap) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $statement = $db->prepare(
                'insert into admin_sessions (admin_user_id, session_hash, expires_at)
                 values (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 12 HOUR))'
            );
            $statement->execute([$createdAdmin, api_token_hash($token)]);
            api_set_session_cookie($token, API_SESSION_SECONDS);
        }
        api_json(['success' => true, 'bootstrap' => $bootstrap], 201);
    }

    if ($method === 'POST' && $path === '/api/admin/logout') {
        api_ensure_same_origin();
        $token = api_cookie_token();
        if ($token !== '') {
            $statement = $db->prepare('delete from admin_sessions where session_hash = ?');
            $statement->execute([api_token_hash($token)]);
        }
        api_clear_session_cookie();
        api_json(['success' => true]);
    }

    if ($method === 'GET' && $path === '/api/admin/me') {
        $admin = api_require_admin();
        api_json(['user_id' => $admin['id'], 'email' => $admin['email']]);
    }

    $admin = api_require_admin();

    if ($method === 'GET' && $path === '/api/admin/riders') {
        $rows = $db->query('select * from riders order by created_at desc')->fetchAll();
        $guarantorRows = $db->query('select * from guarantors order by created_at')->fetchAll();
        $guarantorsByRider = [];
        foreach ($guarantorRows as $guarantor) {
            $guarantorsByRider[$guarantor['rider_id']][] = $guarantor;
        }
        foreach ($rows as &$row) {
            $row['guarantors'] = $guarantorsByRider[$row['id']] ?? [];
            $row = api_encode_rider_row($row);
            foreach ($row['guarantors'] as &$guarantor) {
                foreach (['confirmation', 'work_policy_accepted'] as $boolean) {
                    if (array_key_exists($boolean, $guarantor)) {
                        $guarantor[$boolean] = api_is_truthy_mysql($guarantor[$boolean]);
                    }
                }
            }
            unset($guarantor);
        }
        unset($row);
        api_json($rows);
    }

    if ($method === 'GET' && $path === '/api/admin/form-content') {
        $row = $db->query('select content from form_content where id = 1')->fetch();
        $content = is_array($row) ? json_decode((string)$row['content'], true) : [];
        api_json(is_array($content) ? $content : []);
    }

    if ($method === 'GET' && $path === '/api/admin/telegram-settings') {
        $databaseConfigured = api_is_truthy_mysql($db->query('select exists(select 1 from telegram_settings where id = 1)')->fetchColumn());
        $environmentToken = trim((string)api_env('TELEGRAM_BOT_TOKEN', ''));
        $environmentChatId = trim((string)api_env('TELEGRAM_CHAT_ID', ''));
        $environmentProvided = $environmentToken !== '' || $environmentChatId !== '';
        $environmentConfigured = preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $environmentToken) === 1
            && preg_match('/^(?:-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/', $environmentChatId) === 1;
        $environmentIncomplete = $environmentProvided && !$environmentConfigured;
        $encodedKey = api_env('TELEGRAM_SETTINGS_ENCRYPTION_KEY');
        $key = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;
        api_json([
            'configured' => $environmentProvided ? $environmentConfigured : $databaseConfigured,
            'environment_configured' => $environmentConfigured,
            'environment_incomplete' => $environmentIncomplete,
            'encryption_key_configured' => is_string($key) && strlen($key) === 32,
        ]);
    }

    if ($method === 'PUT' && $path === '/api/admin/telegram-settings') {
        api_ensure_same_origin();
        $botToken = is_string($body['bot_token'] ?? null) ? trim($body['bot_token']) : '';
        $chatId = is_string($body['chat_id'] ?? null) ? trim($body['chat_id']) : '';
        api_require(preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $botToken) === 1 && strlen($botToken) <= 256, 'Enter a valid Telegram bot token.');
        api_require(preg_match('/^(?:-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/', $chatId) === 1, 'Enter a numeric Telegram chat ID or @channel username.');
        $encrypted = api_encrypt_telegram(['bot_token' => $botToken, 'chat_id' => $chatId]);
        $statement = $db->prepare(
            'insert into telegram_settings (id, encrypted_credentials, nonce, auth_tag, updated_by)
             values (1, ?, ?, ?, ?)
                         on duplicate key update encrypted_credentials = values(encrypted_credentials),
                             nonce = values(nonce), auth_tag = values(auth_tag), updated_by = values(updated_by), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([$encrypted['encrypted'], $encrypted['nonce'], $encrypted['tag'], $admin['id']]);
        api_json(['success' => true]);
    }

    if ($method === 'DELETE' && $path === '/api/admin/telegram-settings') {
        api_ensure_same_origin();
        $db->exec('delete from telegram_settings where id = 1');
        api_json(['success' => true]);
    }

    if ($method === 'PUT' && $path === '/api/admin/form-content') {
        api_ensure_same_origin();
        $content = $body['content'] ?? null;
        api_require(is_array($content) && ($content === [] || !array_is_list($content)), 'Form content must be an object.');
        api_require(count($content) <= 300, 'Too many form content entries.');
        foreach ($content as $key => $value) {
            api_require(is_string($key) && strlen($key) <= 10000, 'A form content key is invalid.');
            api_require(is_string($value) && strlen($value) <= 10000, "Form content \"{$key}\" must be text of 10,000 characters or less.");
        }
        $statement = $db->prepare(
                        'insert into form_content (id, content, updated_by) values (1, ?, ?)
                         on duplicate key update content = values(content),
                             updated_by = values(updated_by), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([json_encode($content, JSON_THROW_ON_ERROR), $admin['id']]);
        api_json(['success' => true]);
    }

    if ($method === 'POST' && $path === '/api/admin/company-logo') {
        api_ensure_same_origin();
        $logo = api_file('logo', true, 2097152);
        $signature = file_get_contents($logo['tmp_name']);
        $type = match (true) {
            is_string($signature) && strlen($signature) >= 8 && substr($signature, 0, 8) === "\x89PNG\r\n\x1a\n" => 'image/png',
            is_string($signature) && strlen($signature) >= 3 && substr($signature, 0, 3) === "\xff\xd8\xff" => 'image/jpeg',
            is_string($signature) && strlen($signature) >= 12 && substr($signature, 0, 4) === 'RIFF' && substr($signature, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
        api_require(is_string($type) && $type === $logo['content_type'], 'The uploaded file is not a valid PNG, JPEG, or WebP image.');
        $statement = $db->prepare(
            'insert into company_branding (id, content_type, image_data, updated_by) values (1, ?, ?, ?)
                         on duplicate key update content_type = values(content_type),
                             image_data = values(image_data), updated_by = values(updated_by), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([$type, $signature, $admin['id']]);
        api_json(['success' => true]);
    }

    if ($method === 'DELETE' && $path === '/api/admin/company-logo') {
        api_ensure_same_origin();
        $db->exec('delete from company_branding where id = 1');
        api_json(['success' => true]);
    }

    if (preg_match('#^/api/admin/riders/([0-9a-f-]+)$#i', $path, $match)) {
        $riderId = $match[1];
        api_require(api_uuid($riderId), 'Application ID is invalid.');
        if ($method === 'PUT') {
            api_ensure_same_origin();
            api_require(($body['id'] ?? null) === $riderId, 'Record ID cannot be changed.');
            api_require(in_array($body['status'] ?? null, ['pending', 'under_review', 'approved', 'rejected'], true), 'Application status is invalid.');
            api_require(is_array($body['guarantors'] ?? null) && array_is_list($body['guarantors']), 'Guarantors must be a list.');
            try {
                $db->beginTransaction();
                $exists = $db->prepare('select id from riders where id = ? for update');
                $exists->execute([$riderId]);
                api_require((bool)$exists->fetch(), 'Application not found.', 404);
                $update = api_update_statement('riders', 'id', $riderId, $body, api_rider_editable_columns());
                if ($update !== null) {
                    $statement = $db->prepare($update['sql']);
                    $statement->execute($update['values']);
                }
                $current = $db->prepare('select id from guarantors where rider_id = ?');
                $current->execute([$riderId]);
                $existingIds = array_column($current->fetchAll(), 'id');
                $keepIds = [];
                foreach ($body['guarantors'] as $guarantor) {
                    api_require(is_array($guarantor) && is_string($guarantor['name'] ?? null)
                        && trim($guarantor['name']) !== '' && is_string($guarantor['phone_number'] ?? null)
                        && trim($guarantor['phone_number']) !== '', 'Guarantor name and phone are required.');
                    if (is_string($guarantor['id'] ?? null) && in_array($guarantor['id'], $existingIds, true)) {
                        $keepIds[] = $guarantor['id'];
                        $update = api_update_statement('guarantors', 'id', $guarantor['id'], $guarantor, GUARANTOR_EDITABLE_COLUMNS);
                        if ($update !== null) {
                            $update['sql'] .= ' and rider_id = ?';
                            $update['values'][] = $riderId;
                            $statement = $db->prepare($update['sql']);
                            $statement->execute($update['values']);
                        }
                    } else {
                        $fields = [];
                        foreach ($guarantor as $key => $value) {
                            if (is_string($key) && in_array($key, GUARANTOR_EDITABLE_COLUMNS, true)) {
                                $fields[$key] = $value;
                            }
                        }
                        $columns = array_merge(['id', 'rider_id', 'verification_token'], array_keys($fields));
                        $values = array_merge([api_uuid_v4(), $riderId, api_uuid_v4()], array_map(
                            static fn(string $column, mixed $value): mixed => api_db_value($column, $value),
                            array_keys($fields),
                            array_values($fields),
                        ));
                        $placeholders = array_map(
                            static fn(string $column): string => '?',
                            $columns,
                        );
                        $statement = $db->prepare('insert into guarantors (' . implode(', ', $columns) . ') values (' . implode(', ', $placeholders) . ')');
                        $statement->execute($values);
                    }
                }
                $removedIds = array_values(array_diff($existingIds, $keepIds));
                if ($removedIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
                    $statement = $db->prepare(
                        "select nin_document_path, identity_card_front_path, identity_card_back_path, video_path
                         from guarantors where id in ({$placeholders})"
                    );
                    $statement->execute($removedIds);
                    $fileIds = api_collect_file_ids($statement->fetchAll());
                    $statement = $db->prepare("delete from guarantors where rider_id = ? and id in ({$placeholders})");
                    $statement->execute(array_merge([$riderId], $removedIds));
                    api_delete_files($db, $fileIds);
                }
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }
            api_json(['success' => true]);
        }
        if ($method === 'DELETE') {
            api_ensure_same_origin();
            try {
                $db->beginTransaction();
                $statement = $db->prepare(
                    'select nin_document_path, identity_card_front_path, identity_card_back_path, video_path
                     from riders where id = ? for update'
                );
                $statement->execute([$riderId]);
                $riderRows = $statement->fetchAll();
                api_require($riderRows !== [], 'Application not found.', 404);
                $statement = $db->prepare(
                    'select nin_document_path, identity_card_front_path, identity_card_back_path, video_path
                     from guarantors where rider_id = ?'
                );
                $statement->execute([$riderId]);
                $fileIds = api_collect_file_ids(array_merge($riderRows, $statement->fetchAll()));
                $statement = $db->prepare('delete from riders where id = ?');
                $statement->execute([$riderId]);
                api_delete_files($db, $fileIds);
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }
            api_json(['success' => true]);
        }
    }

    if ($method === 'PATCH' && preg_match('#^/api/admin/riders/([0-9a-f-]+)/status$#i', $path, $match)) {
        api_ensure_same_origin();
        api_require(api_uuid($match[1]), 'Application ID is invalid.');
        $status = $body['status'] ?? null;
        api_require(in_array($status, ['pending', 'under_review', 'approved', 'rejected'], true), 'Application status is invalid.');
        $exists = $db->prepare('select 1 from riders where id = ?');
        $exists->execute([$match[1]]);
        api_require((bool)$exists->fetchColumn(), 'Application not found.', 404);
        $statement = $db->prepare('update riders set status = ?, updated_at = CURRENT_TIMESTAMP where id = ?');
        $statement->execute([$status, $match[1]]);
        api_json(['success' => true]);
    }

    if ($method === 'GET' && preg_match('#^/api/admin/files/([0-9a-f-]+)$#i', $path, $match)) {
        api_require(api_uuid($match[1]), 'File ID is invalid.');
        $statement = $db->prepare('select filename, content_type, file_data from application_files where id = ?');
        $statement->execute([$match[1]]);
        $file = $statement->fetch();
        if (!$file) {
            api_error(404, 'Uploaded file not found.');
        }
        api_response_file($file, true);
    }

    api_error(404, 'API route not found.');
}

function api_run(): never
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = (string)ini_get('post_max_size');
    $postMaxBytes = preg_match('/^\s*(\d+)\s*([KMG])?B?\s*$/i', $postMax, $matches)
        ? (int)$matches[1] * match (strtoupper($matches[2] ?? '')) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        }
        : 0;
    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        throw new ApiException(413, 'Request exceeds the configured PHP post_max_size.');
    }
    $uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/api'), PHP_URL_PATH);
    $path = is_string($uriPath) ? $uriPath : '/api';
    $path = rtrim($path, '/') ?: '/';
    if ($path === '/api/index.php') {
        $path = '/api';
    }
    $isJsonRequest = str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
    $body = $isJsonRequest ? api_read_json() : $_POST;
    if (!is_array($body)) {
        $body = [];
    }

    if ($method === 'GET' && $path === '/api/health') {
        $missing = api_database_missing();
        if ($missing !== []) {
            api_json(['status' => 'not_configured', 'database' => false, 'schema' => false, 'missing' => $missing], 503);
        }
        $db = api_db();
        $db->query('select 1');
                $statement = $db->query(
                        "select count(*) from information_schema.tables
                         where table_schema = database()
                             and table_name in ('riders', 'guarantors', 'admin_users', 'application_files')"
                );
                $ready = (int)$statement->fetchColumn() === 4;
        api_json(['status' => $ready ? 'ok' : 'schema_missing', 'database' => true, 'schema' => $ready], $ready ? 200 : 503);
    }

    if ($method === 'GET' && $path === '/api/company-logo') {
        $row = api_db()->query('select content_type, image_data from company_branding where id = 1')->fetch();
        if (!$row) {
            http_response_code(404);
            exit;
        }
        $row['file_data'] = $row['image_data'];
        api_response_file(['content_type' => $row['content_type'], 'file_data' => $row['image_data']], false);
    }

    if ($method === 'GET' && $path === '/api/form-content') {
        $row = api_db()->query('select content from form_content where id = 1')->fetch();
        $content = is_array($row) ? json_decode((string)$row['content'], true) : [];
        api_json(is_array($content) ? $content : []);
    }

    if ($method === 'GET' && preg_match('#^/api/guarantors/([0-9a-f-]+)$#i', $path, $match)) {
        $statement = api_db()->prepare(
            'select g.id, g.status, r.full_name as rider_name,
                    g.name as guarantor_name, g.phone_number as guarantor_phone
             from guarantors g join riders r on r.id = g.rider_id
             where g.verification_token = :token'
        );
        $statement->execute(['token' => $match[1]]);
        $row = $statement->fetch();
        if (!$row) {
            api_error(404, 'Verification link not found.');
        }
        api_json($row);
    }

    if ($method === 'POST' && $path === '/api/applications') {
        api_upload_count();
        $rider = api_parse_form_json('rider');
        $guarantors = api_parse_form_json('guarantors');
        api_require(array_is_list($guarantors), 'Guarantors must be a list.');
        $files = [
            'ninDocument' => api_file('rider_nin_document', true),
            'identityCardFront' => api_file('rider_identity_card_front', true),
            'identityCardBack' => api_file('rider_identity_card_back', true),
            'video' => api_file('rider_video', true),
        ];
        api_validate_rider($rider, $guarantors, $files);
        $db = api_db();
        $db->beginTransaction();
        $createdGuarantors = [];
        try {
            $rider['nin_document_path'] = api_store_file($db, $files['ninDocument']);
            $rider['identity_card_front_path'] = api_store_file($db, $files['identityCardFront']);
            $rider['identity_card_back_path'] = api_store_file($db, $files['identityCardBack']);
            $rider['video_path'] = api_store_file($db, $files['video']);
            $values = [];
            $placeholders = [];
            foreach (RIDER_COLUMNS as $column) {
                $value = api_db_value($column, $rider[$column] ?? null);
                if ($column === 'employment_history') {
                    $placeholders[] = '?';
                } else {
                    $placeholders[] = '?';
                }
                $values[] = $value;
            }
            $riderId = api_uuid_v4();
            $sql = 'insert into riders (id, ' . implode(', ', RIDER_COLUMNS) . ') values (?, ' . implode(', ', $placeholders) . ')';
            $statement = $db->prepare($sql);
            $statement->execute(array_merge([$riderId], $values));
            $insertGuarantor = $db->prepare(
                'insert into guarantors (id, rider_id, name, phone_number, verification_token)
                 values (:id, :rider_id, :name, :phone, :verification_token)'
            );
            foreach ($guarantors as $guarantor) {
                $createdGuarantor = [
                    'id' => api_uuid_v4(),
                    'rider_id' => $riderId,
                    'name' => trim($guarantor['name']),
                    'phone_number' => trim($guarantor['phone_number']),
                    'verification_token' => api_uuid_v4(),
                ];
                $insertGuarantor->execute([
                    'id' => $createdGuarantor['id'],
                    'rider_id' => $createdGuarantor['rider_id'],
                    'name' => $createdGuarantor['name'],
                    'phone' => $createdGuarantor['phone_number'],
                    'verification_token' => $createdGuarantor['verification_token'],
                ]);
                $createdGuarantors[] = $createdGuarantor;
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        api_json_after_response([
            'rider_id' => $riderId,
            'guarantors' => $createdGuarantors,
            'telegram_notification' => 'queued',
        ], 201, static function () use ($rider, $riderId, $createdGuarantors): void {
            try {
                api_send_telegram($rider, $riderId, $createdGuarantors);
            } catch (Throwable $error) {
                error_log('Rider application saved; Telegram notification failed: ' . get_class($error));
            }
        });
    }

    if ($method === 'POST' && preg_match('#^/api/guarantors/([0-9a-f-]+)$#i', $path, $match)) {
        api_upload_count();
        $rawPayload = $_POST['payload'] ?? '';
        try {
            $payload = json_decode(is_string($rawPayload) ? $rawPayload : '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'Invalid payload data.');
        }
        api_require(is_array($payload), 'Guarantor details are required.');
        $files = [
            'ninDocument' => api_file('guarantor_nin_document', true),
            'video' => api_file('guarantor_video', true),
        ];
        api_validate_guarantor($payload, $files);
        $db = api_db();
        $db->beginTransaction();
        try {
            $documentId = api_store_file($db, $files['ninDocument']);
            $videoId = api_store_file($db, $files['video']);
            $statement = $db->prepare(
                "update guarantors set status = 'submitted', submitted_at = now(),
                 guarantor_address = :address, guarantor_phone_confirmed = :phone,
                 guarantor_state = :state, guarantor_city = :city, guarantor_postal_code = :postal,
                 state_of_origin = :origin, state_of_origin_city = :origin_city, state_of_origin_address = :origin_address,
                 relationship = :relationship, occupation = :occupation, nin = :nin,
                 nin_document_path = :document, video_path = :video, digital_signature = :signature,
                 confirmation = true, work_policy_accepted = true,
                 location_latitude = :latitude, location_longitude = :longitude,
                 location_accuracy_meters = :accuracy, location_captured_at = :captured
                 where verification_token = :token and status = 'pending'"
            );
            $statement->execute([
                'address' => $payload['guarantor_address'], 'phone' => $payload['guarantor_phone_confirmed'],
                'state' => $payload['guarantor_state'], 'city' => $payload['guarantor_city'],
                'postal' => $payload['guarantor_postal_code'] ?? '',
                'origin' => $payload['state_of_origin'], 'origin_city' => $payload['state_of_origin_city'],
                'origin_address' => $payload['state_of_origin_address'], 'relationship' => $payload['relationship'],
                'occupation' => $payload['occupation'], 'nin' => $payload['nin'], 'document' => $documentId,
                'video' => $videoId, 'signature' => $payload['digital_signature'],
                'latitude' => $payload['location_latitude'], 'longitude' => $payload['location_longitude'],
                'accuracy' => $payload['location_accuracy_meters'], 'captured' => $payload['location_captured_at'],
                'token' => $match[1],
            ]);
            if ($statement->rowCount() !== 1) {
                $db->rollBack();
                api_error(409, 'This verification link has already been used or is invalid.');
            }
            $guarantorStatement = $db->prepare(
                'select g.id, g.name, g.phone_number, g.occupation, g.guarantor_city, g.guarantor_state,
                        r.id as rider_id, r.full_name as rider_name
                 from guarantors g inner join riders r on r.id = g.rider_id
                 where g.verification_token = ?'
            );
            $guarantorStatement->execute([$match[1]]);
            $submittedGuarantor = $guarantorStatement->fetch();
            if (!is_array($submittedGuarantor)) {
                throw new RuntimeException('Submitted guarantor record could not be loaded for notification.');
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        api_json_after_response(['success' => true, 'telegram_notification' => 'queued'], 200, static function () use ($submittedGuarantor): void {
            try {
                api_send_telegram(
                    ['full_name' => $submittedGuarantor['rider_name']],
                    (string)$submittedGuarantor['rider_id'],
                    [],
                    $submittedGuarantor
                );
            } catch (Throwable $error) {
                error_log('Guarantor submission saved; Telegram notification failed: ' . get_class($error));
            }
        });
    }

    api_dispatch_admin($method, $path, $body);
}

try {
    api_load_environment();
    api_run();
} catch (ApiException $error) {
    api_error($error->statusCode, $error->getMessage());
} catch (PDOException $error) {
    $sqlState = (string)($error->errorInfo[0] ?? $error->getCode());
    $driverMessage = (string)($error->errorInfo[2] ?? $error->getMessage());
    $driverCode = (int)($error->errorInfo[1] ?? 0);
    error_log('Vanguard MySQL failure [' . $sqlState . '/' . $driverCode . ']: ' . $driverMessage);
    $message = match ($driverCode) {
        1044 => 'MySQL authenticated the user but denied access to MYSQL_DATABASE. Add this exact user to this exact database in cPanel and save All Privileges.',
        1045 => 'MySQL rejected the login. Verify MYSQL_USER and MYSQL_PASSWORD exactly as shown in cPanel, including the account prefix; reset the password if unsure.',
        default => match ($sqlState) {
            '28000' => 'MySQL rejected the login. Verify MYSQL_USER and MYSQL_PASSWORD exactly as shown in cPanel.',
            '42S02' => 'Required MySQL tables are missing. Import server/schema.sql with phpMyAdmin or run php api/migrate.php.',
            default => match ($driverCode) {
                1049 => 'The configured MySQL database does not exist. Check MYSQL_DATABASE.',
                2002, 2003, 2005 => 'Could not connect to MySQL. Check MYSQL_HOST, MYSQL_PORT, and the hosting provider network settings.',
                1142 => 'The MySQL user lacks permission for a required operation. Check its database grants.',
                default => 'The MySQL request failed (SQLSTATE ' . $sqlState . '). Check the cPanel PHP error log for details.',
            },
        },
    };
    api_error(503, $message);
} catch (Throwable $error) {
    error_log('Vanguard API failure: ' . get_class($error) . ': ' . $error->getMessage()
        . ' in ' . $error->getFile() . ':' . $error->getLine());
    api_error(500, 'The request could not be completed.');
}
