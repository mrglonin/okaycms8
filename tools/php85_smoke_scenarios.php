<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);
chdir($rootDir);

$baseUrl = rtrim($argv[1] ?? 'http://okaycms.local', '/');
$baseParts = parse_url($baseUrl);
$baseHost = $baseParts['host'] ?? 'okaycms.local';
$baseScheme = $baseParts['scheme'] ?? 'http';
$basePort = (int) ($baseParts['port'] ?? ($baseScheme === 'https' ? 443 : 80));

$transportBaseUrl = $baseUrl;
$hostHeader = null;
if ($baseHost !== '' && preg_match('~\.local$~i', $baseHost)) {
    $transportBaseUrl = $baseScheme . '://127.0.0.1';
    if (!empty($baseParts['port'])) {
        $transportBaseUrl .= ':' . $baseParts['port'];
    }

    $hostHeader = $baseHost;
    if (!empty($baseParts['port'])) {
        $hostHeader .= ':' . $baseParts['port'];
    }
}

$config = parse_ini_file($rootDir . '/config/config.php', true, INI_SCANNER_RAW);
$localConfig = is_file($rootDir . '/config/config.local.php')
    ? parse_ini_file($rootDir . '/config/config.local.php', true, INI_SCANNER_RAW)
    : [];

$dbConfig = array_merge($config['database'] ?? [], $localConfig['database'] ?? []);
$dbPrefix = $dbConfig['db_prefix'] ?? 'ok_';
$designImagesDir = $rootDir . '/' . trim((string) ($config['images']['design_images'] ?? 'files/images/'), '/');
$logFile = $rootDir . '/cache/php85-fpm-worker.log';

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['db_server'] ?? '127.0.0.1',
        $dbConfig['db_name'] ?? ''
    ),
    $dbConfig['db_user'] ?? '',
    $dbConfig['db_password'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

if (is_file($logFile)) {
    file_put_contents($logFile, '');
}

$variant = fetchVariantSample($pdo, $dbPrefix);
$managerLogin = fetchManagerLogin($pdo, $dbPrefix);

$runId = date('Ymd_His');
$registerEmail = "php85smoke_user_{$runId}@example.com";
$subscribeEmail = "php85smoke_subscribe_{$runId}@example.com";
$feedbackEmail = "php85smoke_feedback_{$runId}@example.com";
$orderEmail = "php85smoke_order_{$runId}@example.com";

$adminUserAgent = 'CodexAdminSmoke/2.0';
$adminSessionId = 'codexadminsmoke-' . substr(md5($runId), 0, 24);
$adminCookieName = prepareAdminSession($adminUserAgent, $adminSessionId, $managerLogin);

$userClient = new SmokeHttpClient($baseUrl, $transportBaseUrl, $hostHeader, 'CodexFrontUserSmoke/2.0');
$guestClient = new SmokeHttpClient($baseUrl, $transportBaseUrl, $hostHeader, 'CodexFrontGuestSmoke/2.0');
$adminClient = new SmokeHttpClient(
    $baseUrl,
    $transportBaseUrl,
    $hostHeader,
    $adminUserAgent,
    [$adminCookieName => $adminSessionId]
);

$results = [];
$cleanup = [];

try {
    $results[] = runScenario('Frontend register', static function () use ($userClient, $pdo, $dbPrefix, $registerEmail): string {
        $response = $userClient->request('POST', '/user/register', [
            'form' => [
                'name' => 'PHP85 Smoke',
                'last_name' => 'Register',
                'email' => $registerEmail,
                'phone' => '+77010000001',
                'password' => 'SmokePass!123',
                'register' => '1',
            ],
        ]);

        assertStatus($response, [302], 'register should redirect to /user');
        assertHeaderContains($response, 'location', '/user', 'register redirect');

        if ((int) fetchValue($pdo, "SELECT COUNT(*) FROM {$dbPrefix}users WHERE email = ?", [$registerEmail]) !== 1) {
            throw new RuntimeException('user row was not created');
        }

        $cabinet = $userClient->request('GET', '/user');
        assertStatus($cabinet, [200], 'user cabinet should be accessible after register');

        return 'user created and authorized session opened';
    });

    $cleanup[] = static function () use ($pdo, $dbPrefix, $registerEmail): void {
        executeStatement($pdo, "DELETE FROM {$dbPrefix}users WHERE email = ?", [$registerEmail]);
    };

    $results[] = runScenario('Frontend logout/login', static function () use ($userClient, $registerEmail): string {
        $logout = $userClient->request('GET', '/user/logout');
        assertStatus($logout, [302], 'logout should redirect');

        $login = $userClient->request('POST', '/user/login', [
            'form' => [
                'email' => $registerEmail,
                'password' => 'SmokePass!123',
                'login' => '1',
            ],
        ]);

        assertStatus($login, [302], 'login should redirect to /user');
        assertHeaderContains($login, 'location', '/user', 'login redirect');

        $cabinet = $userClient->request('GET', '/user');
        assertStatus($cabinet, [200], 'user cabinet should open after login');

        return 'login flow stays valid on PHP 8.5';
    });

    $results[] = runScenario('Password remind', static function () use ($userClient, $pdo, $dbPrefix, $registerEmail): string {
        $response = $userClient->request('POST', '/user/password_remind', [
            'form' => [
                'email' => $registerEmail,
            ],
        ]);

        assertStatus($response, [200], 'password remind page should render');

        $remindCode = (string) fetchValue(
            $pdo,
            "SELECT remind_code FROM {$dbPrefix}users WHERE email = ?",
            [$registerEmail]
        );

        if ($remindCode === '') {
            throw new RuntimeException('remind_code was not generated');
        }

        return 'remind code generated and controller completed without fatal';
    });

    $results[] = runScenario('Subscribe AJAX', static function () use ($guestClient, $pdo, $dbPrefix, $subscribeEmail): string {
        $response = $guestClient->request('POST', '/ajax/subscribe', [
            'form' => [
                'subscribe' => '1',
                'subscribe_email' => $subscribeEmail,
            ],
        ]);

        assertStatus($response, [200], 'subscribe AJAX should return 200');
        $json = decodeJsonResponse($response, 'subscribe');
        if (empty($json['success'])) {
            throw new RuntimeException('subscribe response does not contain success=true');
        }

        if ((int) fetchValue($pdo, "SELECT COUNT(*) FROM {$dbPrefix}subscribe_mailing WHERE email = ?", [$subscribeEmail]) !== 1) {
            throw new RuntimeException('subscribe row was not created');
        }

        return 'JSON success and DB row created';
    });

    $cleanup[] = static function () use ($pdo, $dbPrefix, $subscribeEmail): void {
        executeStatement($pdo, "DELETE FROM {$dbPrefix}subscribe_mailing WHERE email = ?", [$subscribeEmail]);
    };

    $results[] = runScenario('Feedback form', static function () use ($guestClient, $pdo, $dbPrefix, $feedbackEmail): string {
        $response = $guestClient->request('POST', '/contact', [
            'form' => [
                'name' => 'PHP85 Smoke Feedback',
                'email' => $feedbackEmail,
                'message' => 'Feedback smoke test for PHP 8.5 migration',
                'feedback' => '1',
            ],
        ]);

        assertStatus($response, [200], 'feedback page should render');

        if ((int) fetchValue($pdo, "SELECT COUNT(*) FROM {$dbPrefix}feedbacks WHERE email = ?", [$feedbackEmail]) !== 1) {
            throw new RuntimeException('feedback row was not created');
        }

        return 'feedback saved and notify path completed';
    });

    $cleanup[] = static function () use ($pdo, $dbPrefix, $feedbackEmail): void {
        executeStatement($pdo, "DELETE FROM {$dbPrefix}feedbacks WHERE email = ?", [$feedbackEmail]);
    };

    $results[] = runScenario('Cart AJAX add/update', static function () use ($guestClient, $variant): string {
        $add = $guestClient->request('GET', '/ajax/cart_ajax.php', [
            'query' => [
                'action' => 'add_citem',
                'variant_id' => (string) $variant['variant_id'],
                'amount' => '2',
            ],
        ]);

        assertStatus($add, [200], 'cart AJAX add should return 200');
        $addJson = decodeJsonResponse($add, 'cart add');
        if ((int) ($addJson['result'] ?? 0) !== 1 || (int) ($addJson['total_products'] ?? 0) < 1) {
            throw new RuntimeException('cart add response is invalid');
        }

        $update = $guestClient->request('GET', '/ajax/cart_ajax.php', [
            'query' => [
                'action' => 'update_citem',
                'variant_id' => (string) $variant['variant_id'],
                'amount' => '3',
            ],
        ]);

        assertStatus($update, [200], 'cart AJAX update should return 200');
        $updateJson = decodeJsonResponse($update, 'cart update');
        if ((int) ($updateJson['total_products'] ?? 0) !== 3) {
            throw new RuntimeException('cart total_products was not updated to 3');
        }

        return 'cart AJAX state changed correctly';
    });

    $results[] = runScenario('Wishlist AJAX', static function () use ($guestClient, $variant): string {
        $add = $guestClient->request('GET', '/ajax/wishlist.php', [
            'query' => [
                'id' => (string) $variant['product_id'],
                'action' => 'add',
            ],
        ]);

        assertStatus($add, [200], 'wishlist AJAX add should return 200');
        $addJson = decodeJsonResponse($add, 'wishlist add');
        if (empty($addJson['wishlist_informer'])) {
            throw new RuntimeException('wishlist informer is empty');
        }

        $delete = $guestClient->request('GET', '/ajax/wishlist.php', [
            'query' => [
                'id' => (string) $variant['product_id'],
                'action' => 'delete',
            ],
        ]);

        assertStatus($delete, [200], 'wishlist AJAX delete should return 200');
        decodeJsonResponse($delete, 'wishlist delete');

        return 'wishlist add/delete responded with JSON payload';
    });

    $results[] = runScenario('Comparison AJAX', static function () use ($guestClient, $variant): string {
        $add = $guestClient->request('GET', '/ajax/comparison.php', [
            'query' => [
                'product' => (string) $variant['product_id'],
                'action' => 'add',
            ],
        ]);

        assertStatus($add, [200], 'comparison AJAX add should return 200');
        $addJson = decodeJsonResponse($add, 'comparison add');
        if (empty($addJson['success']) || empty($addJson['template'])) {
            throw new RuntimeException('comparison add response is incomplete');
        }

        $delete = $guestClient->request('GET', '/ajax/comparison.php', [
            'query' => [
                'product' => (string) $variant['product_id'],
                'action' => 'delete',
            ],
        ]);

        assertStatus($delete, [200], 'comparison AJAX delete should return 200');
        decodeJsonResponse($delete, 'comparison delete');

        return 'comparison add/delete responded with JSON payload';
    });

    $results[] = runScenario('Checkout AJAX', static function () use ($guestClient, $pdo, $dbPrefix, $orderEmail): string {
        $cartPage = $guestClient->request('GET', '/cart');
        assertStatus($cartPage, [200], 'cart page should open before checkout');

        $form = parseForm($cartPage['body']);
        $fields = $form['fields'];

        $fields['name'] = 'PHP85';
        $fields['last_name'] = 'Checkout';
        $fields['email'] = $orderEmail;
        $fields['phone'] = '+77010000002';
        $fields['comment'] = 'Checkout smoke test';
        $fields['ajax'] = '1';

        if (empty($fields['delivery_id']) || empty($fields['payment_method_id'])) {
            throw new RuntimeException('delivery_id or payment_method_id was not detected on cart form');
        }

        $checkout = $guestClient->request('POST', '/cart', [
            'form' => $fields,
        ]);

        assertStatus($checkout, [200], 'checkout AJAX should return 200');
        $json = decodeJsonResponse($checkout, 'checkout');
        if (empty($json['url']) && empty($json['form'])) {
            throw new RuntimeException('checkout response does not contain payment data');
        }

        if ((int) fetchValue($pdo, "SELECT COUNT(*) FROM {$dbPrefix}orders WHERE email = ?", [$orderEmail]) !== 1) {
            throw new RuntimeException('order row was not created');
        }

        return 'guest checkout created an order and returned payment payload';
    });

    $results[] = runScenario('Admin save: general', static function () use ($adminClient, $pdo, $dbPrefix): string {
        return adminRoundTripSave(
            $adminClient,
            $pdo,
            $dbPrefix,
            '/backend/index.php?controller=SettingsGeneralAdmin',
            'captcha_type',
            static function (string $current, array $options): string {
                foreach (['v2', 'v3', 'invisible', 'default'] as $candidate) {
                    if (in_array($candidate, $options, true) && $candidate !== $current) {
                        return $candidate;
                    }
                }

                throw new RuntimeException('no alternative captcha_type found');
            }
        );
    });

    $results[] = runScenario('Admin save: notify', static function () use ($adminClient, $pdo, $dbPrefix, $runId): string {
        return adminRoundTripSave(
            $adminClient,
            $pdo,
            $dbPrefix,
            '/backend/index.php?controller=SettingsNotifyAdmin',
            'smtp_server',
            static fn(string $current): string => $current === "smtp-smoke-{$runId}" ? "smtp-smoke-{$runId}-alt" : "smtp-smoke-{$runId}"
        );
    });

    $results[] = runScenario('Admin save: theme', static function () use ($adminClient, $pdo, $dbPrefix, $runId): string {
        return adminRoundTripSave(
            $adminClient,
            $pdo,
            $dbPrefix,
            '/backend/index.php?controller=SettingsThemeAdmin',
            'site_email',
            static fn(string $current): string => $current === "theme-smoke-{$runId}@example.com" ? "theme-smoke-{$runId}-alt@example.com" : "theme-smoke-{$runId}@example.com"
        );
    });

    $results[] = runScenario('Admin save: OpenAI', static function () use ($adminClient, $pdo, $dbPrefix): string {
        return adminRoundTripSave(
            $adminClient,
            $pdo,
            $dbPrefix,
            '/backend/index.php?controller=SettingsOpenAiAdmin',
            'open_ai_temperature',
            static function (string $current): string {
                $currentValue = (float) $current;
                $nextValue = ($currentValue >= 1.0) ? ($currentValue - 0.1) : ($currentValue + 0.1);
                return number_format($nextValue, 1, '.', '');
            }
        );
    });

    $results[] = runScenario('Admin upload: favicon', static function () use ($adminClient, $pdo, $dbPrefix, $designImagesDir, $runId): string {
        $page = $adminClient->request('GET', '/backend/index.php?controller=SettingsThemeAdmin');
        assertStatus($page, [200], 'settings theme page should open for upload');

        $form = parseFormContainingField($page['body'], 'site_favicon');
        $fields = $form['fields'];
        $backupFavicon = getRawSetting($pdo, $dbPrefix, 'site_favicon');
        $backupVersion = getRawSetting($pdo, $dbPrefix, 'site_favicon_version');

        $originalFilename = $backupFavicon['exists'] ? (string) $backupFavicon['value'] : '';
        $originalPath = $originalFilename !== '' ? $designImagesDir . '/' . ltrim($originalFilename, '/') : '';
        $originalContents = ($originalPath !== '' && is_file($originalPath)) ? file_get_contents($originalPath) : false;

        $tempFile = createTinyPngTempFile($runId);

        try {
            $upload = $adminClient->request('POST', '/backend/index.php?controller=SettingsThemeAdmin', [
                'form' => $fields,
                'files' => [
                    'site_favicon' => $tempFile,
                ],
            ]);

            assertStatus($upload, [200], 'favicon upload should return 200');

            $currentFavicon = getRawSetting($pdo, $dbPrefix, 'site_favicon');
            if (!$currentFavicon['exists'] || (string) $currentFavicon['value'] === '') {
                throw new RuntimeException('site_favicon was not stored after upload');
            }

            $uploadedPath = $designImagesDir . '/' . ltrim((string) $currentFavicon['value'], '/');
            if (!is_file($uploadedPath)) {
                throw new RuntimeException('uploaded favicon file was not found on disk');
            }
        } finally {
            @unlink($tempFile);

            restoreRawSetting($pdo, $dbPrefix, 'site_favicon', $backupFavicon);
            restoreRawSetting($pdo, $dbPrefix, 'site_favicon_version', $backupVersion);

            $currentFavicon = getRawSetting($pdo, $dbPrefix, 'site_favicon');
            $currentFilename = $currentFavicon['exists'] ? (string) $currentFavicon['value'] : '';
            $currentPath = $currentFilename !== '' ? $designImagesDir . '/' . ltrim($currentFilename, '/') : '';

            if ($originalFilename === '') {
                if ($currentPath !== '' && is_file($currentPath)) {
                    @unlink($currentPath);
                }
            } elseif ($originalContents !== false) {
                file_put_contents($originalPath, $originalContents);

                if ($currentPath !== '' && $currentPath !== $originalPath && is_file($currentPath)) {
                    @unlink($currentPath);
                }
            }
        }

        return 'multipart upload completed and original favicon state restored';
    });

    $results[] = runScenario('Security: block POST without session_id on fetch action', static function () use ($adminClient, $pdo, $dbPrefix, $runId): string {
        $page = $adminClient->request('GET', '/backend/index.php?controller=SettingsThemeAdmin');
        assertStatus($page, [200], 'settings theme page should open before security test');

        $form = parseFormContainingField($page['body'], 'site_email');
        $fields = $form['fields'];
        unset($fields['session_id']);

        $originalValue = (string) fetchValue($pdo, "SELECT value FROM {$dbPrefix}settings WHERE param = 'site_email'");
        $blockedValue = "csrf-blocked-{$runId}@example.com";
        $fields['site_email'] = $blockedValue;

        $post = $adminClient->request('POST', '/backend/index.php?controller=SettingsThemeAdmin', [
            'form' => $fields,
        ]);

        assertStatus($post, [200], 'invalid session POST on fetch action should degrade to GET');

        $actualValue = (string) fetchValue($pdo, "SELECT value FROM {$dbPrefix}settings WHERE param = 'site_email'");
        if ($actualValue !== $originalValue) {
            throw new RuntimeException('site_email changed without session_id');
        }

        return 'settings were not changed without session_id';
    });

    $results[] = runScenario('Security: block POST without session_id on custom action', static function () use ($adminClient): string {
        $response = $adminClient->request('POST', '/backend/index.php?controller=SettingsNotifyAdmin@testSMTP', [
            'form' => [
                'smtp_server' => 'blocked.example.test',
                'smtp_port' => '25',
                'smtp_user' => 'blocked',
                'smtp_pass' => 'blocked',
            ],
        ]);

        assertStatus($response, [403], 'invalid session custom action should return 403');
        $json = decodeJsonResponse($response, 'custom action session block');
        if (($json['error'] ?? '') !== 'Session expired') {
            throw new RuntimeException('403 response does not contain Session expired error');
        }

        return 'custom POST action is blocked before controller execution';
    });
} finally {
    foreach (array_reverse($cleanup) as $cleanupStep) {
        try {
            $cleanupStep();
        } catch (Throwable $e) {
            $results[] = [
                'name' => 'Cleanup',
                'ok' => false,
                'details' => $e->getMessage(),
            ];
        }
    }

    $userClient->close();
    $guestClient->close();
    $adminClient->close();
}

$logOutput = is_file($logFile) ? trim((string) file_get_contents($logFile)) : '';
$failures = array_filter($results, static fn(array $result): bool => $result['ok'] === false);

echo 'Smoke scenarios for ' . $baseUrl . PHP_EOL;
echo 'Checked: ' . count($results) . PHP_EOL;
echo 'Failures: ' . count($failures) . PHP_EOL;
echo PHP_EOL;

foreach ($results as $result) {
    printf(
        "[%s] %s%s\n",
        $result['ok'] ? 'OK' : 'FAIL',
        $result['name'],
        $result['details'] !== '' ? ' - ' . $result['details'] : ''
    );
}

echo PHP_EOL;
if ($logOutput === '') {
    echo "php-fpm log: clean\n";
} else {
    echo "php-fpm log: not clean\n";
    echo $logOutput . PHP_EOL;
}

exit((count($failures) === 0 && $logOutput === '') ? 0 : 1);

function runScenario(string $name, callable $callback): array
{
    try {
        return [
            'name' => $name,
            'ok' => true,
            'details' => (string) $callback(),
        ];
    } catch (Throwable $e) {
        return [
            'name' => $name,
            'ok' => false,
            'details' => $e->getMessage(),
        ];
    }
}

function fetchVariantSample(PDO $pdo, string $dbPrefix): array
{
    $statement = $pdo->query(
        "SELECT p.id AS product_id, p.url AS product_url, v.id AS variant_id
         FROM {$dbPrefix}products p
         INNER JOIN {$dbPrefix}variants v ON v.product_id = p.id
         WHERE p.visible = 1
         ORDER BY p.id
         LIMIT 1"
    );

    $row = $statement->fetch();
    if (!$row) {
        throw new RuntimeException('No visible product variant found for smoke test');
    }

    return $row;
}

function fetchManagerLogin(PDO $pdo, string $dbPrefix): string
{
    $statement = $pdo->query("SELECT login FROM {$dbPrefix}managers ORDER BY id LIMIT 1");
    $login = $statement->fetchColumn();
    if (!is_string($login) || $login === '') {
        throw new RuntimeException('No manager login found for admin smoke test');
    }

    return $login;
}

function prepareAdminSession(string $userAgent, string $sessionId, string $managerLogin): string
{
    $previousUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $_SERVER['HTTP_USER_AGENT'] = $userAgent;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $sessionName = md5($userAgent);
    session_name($sessionName);
    session_id($sessionId);
    session_start();

    $_SESSION['admin'] = $managerLogin;
    $_SESSION['lang_id'] = 'ru';
    $_SESSION['admin_lang_id'] = 'ru';
    $_SESSION['id'] = session_id();

    session_write_close();

    if ($previousUserAgent === null) {
        unset($_SERVER['HTTP_USER_AGENT']);
    } else {
        $_SERVER['HTTP_USER_AGENT'] = $previousUserAgent;
    }

    return $sessionName;
}

function adminRoundTripSave(
    SmokeHttpClient $client,
    PDO $pdo,
    string $dbPrefix,
    string $path,
    string $fieldName,
    callable $nextValueResolver
): string {
    $page = $client->request('GET', $path);
    assertStatus($page, [200], "admin page {$path} should open");

    $form = parseFormContainingField($page['body'], $fieldName);
    $originalFields = $form['fields'];
    if (!array_key_exists($fieldName, $originalFields)) {
        throw new RuntimeException("field {$fieldName} was not found on {$path}");
    }

    $options = $form['select_options'][$fieldName] ?? [];
    $originalValue = flattenFieldValue($originalFields[$fieldName]);
    $newValue = (string) $nextValueResolver($originalValue, $options);

    if ($newValue === $originalValue) {
        throw new RuntimeException("field {$fieldName} did not get a new value");
    }

    $rawBackup = getRawSetting($pdo, $dbPrefix, $fieldName);

    $changedFields = $originalFields;
    $changedFields[$fieldName] = $newValue;

    $post = $client->request('POST', $path, [
        'form' => $changedFields,
    ]);
    assertStatus($post, [200], "saving {$fieldName} should return 200");

    $updatedRawValue = getRawSetting($pdo, $dbPrefix, $fieldName);
    if (!$updatedRawValue['exists'] || (string) $updatedRawValue['value'] !== $newValue) {
        throw new RuntimeException("field {$fieldName} value was not persisted");
    }

    $restore = $client->request('POST', $path, [
        'form' => $originalFields,
    ]);
    assertStatus($restore, [200], "restoring {$fieldName} should return 200");

    restoreRawSetting($pdo, $dbPrefix, $fieldName, $rawBackup);
    $restoredRawValue = getRawSetting($pdo, $dbPrefix, $fieldName);
    $restoredValue = $restoredRawValue['exists'] ? (string) $restoredRawValue['value'] : '';
    $expectedValue = !empty($rawBackup['exists']) ? (string) $rawBackup['value'] : '';
    if ($restoredValue !== $expectedValue || (bool) $restoredRawValue['exists'] !== (bool) $rawBackup['exists']) {
        throw new RuntimeException("field {$fieldName} was not restored");
    }

    return "{$fieldName}: {$originalValue} -> {$newValue} -> restored";
}

function parseForm(string $html, int $formIndex = 0): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $forms = $xpath->query('//form');
    if (!$forms || $forms->length <= $formIndex) {
        throw new RuntimeException("form #{$formIndex} not found");
    }

    $form = $forms->item($formIndex);
    $fields = [];
    $selectOptions = [];

    foreach ($xpath->query('.//input', $form) as $input) {
        if (!$input instanceof DOMElement || $input->hasAttribute('disabled')) {
            continue;
        }

        $name = trim($input->getAttribute('name'));
        if ($name === '') {
            continue;
        }

        $type = strtolower($input->getAttribute('type') ?: 'text');
        if (in_array($type, ['button', 'submit', 'reset', 'image', 'file'], true)) {
            continue;
        }

        if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) {
            continue;
        }

        addFieldValue($fields, $name, $input->getAttribute('value'));
    }

    foreach ($xpath->query('.//textarea', $form) as $textarea) {
        if (!$textarea instanceof DOMElement || $textarea->hasAttribute('disabled')) {
            continue;
        }

        $name = trim($textarea->getAttribute('name'));
        if ($name === '') {
            continue;
        }

        addFieldValue($fields, $name, $textarea->textContent);
    }

    foreach ($xpath->query('.//select', $form) as $select) {
        if (!$select instanceof DOMElement || $select->hasAttribute('disabled')) {
            continue;
        }

        $name = trim($select->getAttribute('name'));
        if ($name === '') {
            continue;
        }

        $options = [];
        $selected = [];

        foreach ($xpath->query('.//option', $select) as $option) {
            if (!$option instanceof DOMElement) {
                continue;
            }

            $value = $option->getAttribute('value');
            $options[] = $value;
            if ($option->hasAttribute('selected')) {
                $selected[] = $value;
            }
        }

        if ($selected === [] && $options !== []) {
            $selected[] = $options[0];
        }

        $selectOptions[$name] = $options;

        if ($select->hasAttribute('multiple')) {
            foreach ($selected as $value) {
                addFieldValue($fields, $name, $value);
            }
            continue;
        }

        addFieldValue($fields, $name, $selected[0] ?? '');
    }

    return [
        'fields' => $fields,
        'select_options' => $selectOptions,
    ];
}

function parseFormContainingField(string $html, string $fieldName): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $forms = $xpath->query('//form');
    if (!$forms) {
        throw new RuntimeException('no forms found');
    }

    for ($i = 0; $i < $forms->length; $i++) {
        $form = parseForm($html, $i);
        if (array_key_exists($fieldName, $form['fields'])) {
            return $form;
        }
    }

    throw new RuntimeException("field {$fieldName} was not found in any form");
}

function addFieldValue(array &$fields, string $name, string $value): void
{
    if (!array_key_exists($name, $fields)) {
        $fields[$name] = $value;
        return;
    }

    if (!is_array($fields[$name])) {
        $fields[$name] = [$fields[$name]];
    }

    $fields[$name][] = $value;
}

function flattenFieldValue($value): string
{
    if (is_array($value)) {
        return implode(',', array_map('strval', $value));
    }

    return (string) $value;
}

function assertStatus(array $response, array $allowedStatuses, string $context): void
{
    if (!in_array($response['status'], $allowedStatuses, true)) {
        throw new RuntimeException($context . ", got HTTP {$response['status']}");
    }
}

function assertHeaderContains(array $response, string $headerName, string $needle, string $context): void
{
    $headerValue = strtolower((string) ($response['headers'][strtolower($headerName)] ?? ''));
    if ($headerValue === '' || strpos($headerValue, strtolower($needle)) === false) {
        throw new RuntimeException($context . " header does not contain {$needle}");
    }
}

function decodeJsonResponse(array $response, string $context): array
{
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException($context . ' response is not valid JSON');
    }

    return $decoded;
}

function fetchValue(PDO $pdo, string $sql, array $params = [])
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

function executeStatement(PDO $pdo, string $sql, array $params = []): void
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
}

function getRawSetting(PDO $pdo, string $dbPrefix, string $param): array
{
    $statement = $pdo->prepare("SELECT value FROM {$dbPrefix}settings WHERE param = ?");
    $statement->execute([$param]);
    $value = $statement->fetchColumn();

    return [
        'exists' => $value !== false,
        'value' => $value !== false ? (string) $value : null,
    ];
}

function restoreRawSetting(PDO $pdo, string $dbPrefix, string $param, array $backup): void
{
    if (!empty($backup['exists'])) {
        $statement = $pdo->prepare(
            "INSERT INTO {$dbPrefix}settings (param, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        $statement->execute([$param, (string) $backup['value']]);
        return;
    }

    $statement = $pdo->prepare("DELETE FROM {$dbPrefix}settings WHERE param = ?");
    $statement->execute([$param]);
}

function createTinyPngTempFile(string $suffix): string
{
    $path = sys_get_temp_dir() . '/okaycms_php85_smoke_' . $suffix . '.png';
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn8h4sAAAAASUVORK5CYII=',
        true
    );

    if ($png === false) {
        throw new RuntimeException('Failed to decode temporary PNG');
    }

    file_put_contents($path, $png);
    return $path;
}

final class SmokeHttpClient
{
    private string $baseUrl;
    private string $transportBaseUrl;
    private ?string $hostHeader;
    private string $userAgent;
    private string $cookieJar;
    private array $initialCookies;

    public function __construct(
        string $baseUrl,
        string $transportBaseUrl,
        ?string $hostHeader,
        string $userAgent,
        array $initialCookies = []
    ) {
        $this->baseUrl = $baseUrl;
        $this->transportBaseUrl = $transportBaseUrl;
        $this->hostHeader = $hostHeader;
        $this->userAgent = $userAgent;
        $this->initialCookies = $initialCookies;
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'okaycms_smoke_cookie_');

        if ($this->cookieJar === false) {
            throw new RuntimeException('Failed to create cookie jar');
        }

        if ($this->initialCookies !== []) {
            $this->seedCookies();
        }
    }

    public function request(string $method, string $path, array $options = []): array
    {
        $query = $options['query'] ?? [];
        $transportUrl = $this->buildUrl($this->transportBaseUrl, $path, $query);
        $publicUrl = $this->buildUrl($this->baseUrl, $path, $query);

        $headers = [];
        if ($this->hostHeader !== null) {
            $headers[] = 'Host: ' . $this->hostHeader;
        }
        foreach ($options['headers'] ?? [] as $header) {
            $headers[] = $header;
        }

        $ch = curl_init($transportUrl);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $files = $options['files'] ?? [];
        if ($files !== []) {
            $postFields = $options['form'] ?? [];
            foreach ($files as $name => $pathToFile) {
                $postFields[$name] = new CURLFile(
                    $pathToFile,
                    mime_content_type($pathToFile) ?: 'application/octet-stream',
                    basename($pathToFile)
                );
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        } elseif (!empty($options['form'])) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($options['form']));
        }

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            throw new RuntimeException('curl error for ' . $publicUrl . ': ' . $error);
        }

        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $rawHeaders = substr($rawResponse, 0, $headerSize);
        $body = substr($rawResponse, $headerSize);

        return [
            'status' => $status,
            'headers' => $this->parseHeaders($rawHeaders),
            'body' => $body,
            'url' => $publicUrl,
        ];
    }

    public function close(): void
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    private function buildUrl(string $baseUrl, string $path, array $query = []): string
    {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        return $url;
    }

    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $blocks = preg_split("/\r\n\r\n|\n\n|\r\r/", trim($rawHeaders));
        $lastBlock = $blocks ? end($blocks) : '';

        foreach (preg_split("/\r\n|\n|\r/", (string) $lastBlock) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $headers;
    }

    private function seedCookies(): void
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: 'localhost';
        $content = '';
        foreach ($this->initialCookies as $name => $value) {
            $content .= implode("\t", [$host, 'FALSE', '/', 'FALSE', '0', $name, $value]) . PHP_EOL;
        }

        file_put_contents($this->cookieJar, $content);
    }
}
