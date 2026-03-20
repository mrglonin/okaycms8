<?php

declare(strict_types=1);

use Okay\Core\Routes\AllBlogRoute;
use Okay\Core\Routes\AllBrandsRoute;
use Okay\Core\Routes\AllProductsRoute;
use Okay\Core\Routes\BlogCategoryRoute;
use Okay\Core\Routes\BrandRoute;
use Okay\Core\Routes\CategoryRoute;
use Okay\Core\Routes\PageRoute;
use Okay\Core\Routes\PostRoute;
use Okay\Core\Routes\ProductRoute;
use Okay\Core\Routes\RouteParams;

$rootDir = dirname(__DIR__);
chdir($rootDir);

$parsedBaseUrl = parse_url($argv[1] ?? 'http://okaycms.local');
$baseHost = $parsedBaseUrl['host'] ?? 'okaycms.local';
$baseScheme = $parsedBaseUrl['scheme'] ?? 'http';
$basePort = (int) ($parsedBaseUrl['port'] ?? ($baseScheme === 'https' ? 443 : 80));

$_SERVER['HTTP_HOST'] = $baseHost;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['SERVER_PORT'] = $basePort;
$_SERVER['DOCUMENT_ROOT'] = $rootDir;
$_SERVER['REQUEST_METHOD'] = 'GET';

if ($baseScheme === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

require $rootDir . '/vendor/autoload.php';
require $rootDir . '/Okay/Core/compat/vendor_compat.php';

include $rootDir . '/Okay/Core/config/container.php';

$baseUrl = $argv[1] ?? 'http://okaycms.local';
$baseUrl = rtrim($baseUrl, '/');
$sampleLimit = max(1, (int) ($argv[2] ?? 5));
$logFile = $rootDir . '/cache/php85-fpm-worker.log';
$transportBaseUrl = $baseUrl;
$hostHeader = null;

$baseUrlParts = parse_url($baseUrl);
$baseUrlHost = $baseUrlParts['host'] ?? '';
if ($baseUrlHost !== '' && preg_match('~\.local$~i', $baseUrlHost)) {
    $transportBaseUrl = ($baseUrlParts['scheme'] ?? 'http') . '://127.0.0.1';
    if (!empty($baseUrlParts['port'])) {
        $transportBaseUrl .= ':' . $baseUrlParts['port'];
    }

    $hostHeader = $baseUrlHost;
    if (!empty($baseUrlParts['port'])) {
        $hostHeader .= ':' . $baseUrlParts['port'];
    }
}

$config = parse_ini_file($rootDir . '/config/config.php', true);
$localConfig = is_file($rootDir . '/config/config.local.php')
    ? parse_ini_file($rootDir . '/config/config.local.php', true)
    : [];

$dbConfig = array_merge($config['database'] ?? [], $localConfig['database'] ?? []);
$dbPrefix = $dbConfig['db_prefix'] ?? 'ok_';

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['db_server'] ?? '127.0.0.1',
        $dbConfig['db_name'] ?? ''
    ),
    $dbConfig['db_user'] ?? '',
    $dbConfig['db_password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if (is_file($logFile)) {
    file_put_contents($logFile, '');
}

$paths = [];

foreach (buildStaticPaths($rootDir) as $path) {
    $paths[$path] = 'static';
}

foreach (buildCollectionPaths() as $path) {
    $paths[$path] = 'collection';
}

foreach (buildEntityPaths($pdo, $dbPrefix, $sampleLimit) as $path => $type) {
    $paths[$path] = $type;
}

$results = [];
foreach (array_keys($paths) as $path) {
    $results[] = requestUrl($baseUrl . $path, $transportBaseUrl . $path, $paths[$path], $hostHeader);
}

$failures = array_filter($results, static function (array $result): bool {
    return $result['ok'] === false;
});

echo 'Smoke routes for ' . $baseUrl . PHP_EOL;
echo 'Checked: ' . count($results) . PHP_EOL;
echo 'Failures: ' . count($failures) . PHP_EOL;
echo PHP_EOL;

foreach ($results as $result) {
    printf(
        "[%s] %3d %s (%s, %.3fs)%s\n",
        $result['ok'] ? 'OK' : 'FAIL',
        $result['status'],
        $result['path'],
        $result['type'],
        $result['time'],
        $result['redirect_url'] !== '' ? ' -> ' . $result['redirect_url'] : ''
    );
}

$logOutput = '';
if (is_file($logFile)) {
    $logOutput = trim((string) file_get_contents($logFile));
}

echo PHP_EOL;
if ($logOutput === '') {
    echo 'php-fpm log: clean' . PHP_EOL;
} else {
    echo 'php-fpm log: not clean' . PHP_EOL;
    echo $logOutput . PHP_EOL;
}

exit((count($failures) === 0 && $logOutput === '') ? 0 : 1);

function buildStaticPaths(string $rootDir): array
{
    $routes = include $rootDir . '/Okay/Core/config/routes.php';
    $paths = [];

    foreach ($routes as $route) {
        $slug = (string) ($route['slug'] ?? '');

        if ($slug === '' || strpos($slug, '{$') !== false) {
            continue;
        }

        if (preg_match('~(^|/)(ajax|dynamic_js|common_js|files/resized)~', $slug)) {
            continue;
        }

        if (in_array($slug, ['user/logout', '/support.php'], true)) {
            continue;
        }

        $paths[] = normalizePath($slug);
    }

    return array_values(array_unique($paths));
}

function buildCollectionPaths(): array
{
    $paths = [];

    $paths[] = routeParamsToPath((new AllProductsRoute())->generateRouteParams(''));
    $paths[] = routeParamsToPath((new AllBrandsRoute())->generateRouteParams(''));
    $paths[] = routeParamsToPath((new AllBlogRoute())->generateRouteParams(''));

    return array_values(array_unique(array_filter($paths)));
}

function buildEntityPaths(PDO $pdo, string $prefix, int $limit): array
{
    $paths = [];

    foreach (fetchUrls($pdo, "SELECT url FROM {$prefix}pages WHERE visible = 1 AND url <> '404' ORDER BY id DESC LIMIT {$limit}") as $url) {
        $paths[buildPathFromRouteTemplate(new PageRoute(), $url)] = 'page';
    }

    foreach (fetchUrls($pdo, "SELECT url FROM {$prefix}brands WHERE visible = 1 ORDER BY id DESC LIMIT {$limit}") as $url) {
        $paths[buildPathFromRouteTemplate(new BrandRoute(), $url)] = 'brand';
    }

    foreach (fetchUrls($pdo, "SELECT url FROM {$prefix}categories WHERE visible = 1 ORDER BY id DESC LIMIT {$limit}") as $url) {
        $paths[buildPathFromRouteTemplate(new CategoryRoute(), $url)] = 'category';
    }

    foreach (fetchUrls($pdo, "SELECT url FROM {$prefix}products WHERE visible = 1 ORDER BY id DESC LIMIT {$limit}") as $url) {
        $paths[buildPathFromRouteTemplate(new ProductRoute(), $url)] = 'product';
    }

    foreach (fetchUrls($pdo, "SELECT url FROM {$prefix}authors WHERE visible = 1 ORDER BY id DESC LIMIT {$limit}") as $url) {
        $paths[normalizePath('authors/' . $url)] = 'author';
    }

    foreach (fetchUrls($pdo, "SELECT url FROM {$prefix}blog_categories WHERE visible = 1 ORDER BY id DESC LIMIT {$limit}") as $url) {
        $paths[normalizePath((new BlogCategoryRoute())->generateSlugUrl($url))] = 'blog_category';
    }

    foreach (fetchUrls($pdo, "SELECT url FROM {$prefix}blog WHERE visible = 1 ORDER BY id DESC LIMIT {$limit}") as $url) {
        $paths[normalizePath((new PostRoute())->generateSlugUrl($url))] = 'post';
    }

    return $paths;
}

function fetchUrls(PDO $pdo, string $sql): array
{
    $statement = $pdo->query($sql);
    $values = $statement->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_filter(array_map('strval', $values)));
}

function routeParamsToPath($routeParams): string
{
    if ($routeParams instanceof RouteParams) {
        $slug = (string) $routeParams->getSlug();
        $patterns = (array) $routeParams->getPatterns();
    } else {
        [$slug, $patterns] = $routeParams;
        $slug = (string) $slug;
        $patterns = (array) $patterns;
    }

    $path = $slug;

    if (preg_match_all('~{\$(.+?)}~', $path, $matches)) {
        foreach ($matches[0] as $index => $placeholder) {
            $pattern = $patterns[$placeholder] ?? '';

            if ($pattern !== '' && preg_match('~^[a-z0-9/_-]+$~i', $pattern)) {
                $path = str_replace($placeholder, $pattern, $path);
                continue;
            }

            $path = str_replace(['/?' . $placeholder, '/' . $placeholder, $placeholder], '', $path);
        }
    }

    return normalizePath($path);
}

function buildPathFromRouteTemplate($route, string $entityUrl): string
{
    $routeParams = $route->generateRouteParams('');

    if ($routeParams instanceof RouteParams) {
        return routeParamsToPath([
            str_replace('{$url}', $entityUrl, (string) $routeParams->getSlug()),
            $routeParams->getPatterns(),
        ]);
    }

    [$slug, $patterns] = $routeParams;

    return routeParamsToPath([
        str_replace('{$url}', $entityUrl, (string) $slug),
        $patterns,
    ]);
}

function normalizePath(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '/';
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return preg_replace('~/+~', '/', $path) ?: '/';
}

function requestUrl(string $displayUrl, string $requestUrl, string $type, ?string $hostHeader): array
{
    $ch = curl_init($requestUrl);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADER => false,
        CURLOPT_NOBODY => false,
        CURLOPT_USERAGENT => 'OkayCMS PHP85 smoke routes/1.0',
    ];

    if (!empty($hostHeader)) {
        $options[CURLOPT_HTTPHEADER] = ['Host: ' . $hostHeader];
    }

    curl_setopt_array($ch, $options);

    curl_exec($ch);

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $totalTime = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $error = curl_error($ch);

    $path = parse_url($displayUrl, PHP_URL_PATH) ?: '/';
    $redirectUrl = '';
    if ($effectiveUrl !== '' && $effectiveUrl !== $requestUrl) {
        $redirectUrl = $effectiveUrl;
    }

    $ok = ($error === '' && $status >= 200 && $status < 400)
        || isExpectedRedirect($path, $redirectUrl);

    return [
        'type' => $type,
        'path' => $path,
        'status' => $status,
        'time' => $totalTime,
        'redirect_url' => $redirectUrl,
        'ok' => $ok,
    ];
}

function isExpectedRedirect(string $path, string $redirectUrl): bool
{
    if ($redirectUrl === '') {
        return false;
    }

    $redirectPath = parse_url($redirectUrl, PHP_URL_PATH) ?: '';

    $expectedRedirects = [
        '/user' => '/user/login',
        '/user/orders' => '/user/login',
        '/user/comments' => '/user/login',
        '/user/favorites' => '/user/login',
        '/user/browsed' => '/user/login',
        '/.well-known/change-password' => '/user',
    ];

    return isset($expectedRedirects[$path]) && $expectedRedirects[$path] === $redirectPath;
}
