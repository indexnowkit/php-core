<?php

declare(strict_types=1);

/**
 * IndexNow mock server (PHP built-in server edition). Run: php -S 127.0.0.1:8089 tools/mock-server/router.php
 * Scenario via header X-Mock-Scenario or ?scenario=. Request log at GET /_mock/requests, DELETE clears.
 * MOCK_KEYS env (comma separated) makes GET /{key}.txt answer 200.
 */
$logFile = sys_get_temp_dir() . '/indexnow-mock-requests.json';
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$body = file_get_contents('php://input') ?: '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
$scenario = $headers['X-Mock-Scenario'] ?? $_GET['scenario'] ?? 'ok200';

$readLog = static fn(): array => is_file($logFile) ? (json_decode((string) file_get_contents($logFile), true) ?: []) : [];
$writeLog = static fn(array $log) => file_put_contents($logFile, json_encode($log));
$respond = static function (int $status, string $text = '', array $extra = []): void {
    http_response_code($status);
    foreach ($extra as $k => $v) {
        header("$k: $v");
    }
    header('Content-Type: text/plain');
    echo $text;
};

/**
 * A urlset sitemap deliberately larger than 100 KB, used to regression-test that GET responses are
 * not truncated at the old 2 KB POST-diagnostics limit.
 */
$sitemapXml = static function (): string {
    $urls = '';
    for ($i = 0; $i < 3000; ++$i) {
        $urls .= '<url><loc>https://www.example.com/page-' . $i . '</loc></url>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';
};

if ($method === 'GET' && $path === '/sitemap.xml') {
    $respond(200, $sitemapXml());

    return;
}

if ($method === 'GET' && $path === '/sitemap.xml.gz') {
    $respond(200, (string) gzencode($sitemapXml()));

    return;
}

if ($path === '/_mock/requests') {
    if ($method === 'DELETE') {
        @unlink($logFile);
        $respond(204);

        return;
    }
    header('Content-Type: application/json');
    echo json_encode($readLog());

    return;
}

if ($method === 'GET' && preg_match('#^/([A-Za-z0-9-]{8,128})\.txt$#', $path, $m)) {
    $keys = array_filter(array_map('trim', explode(',', (string) getenv('MOCK_KEYS'))));
    if (in_array($m[1], $keys, true)) {
        $respond(200, $m[1]);
    } else {
        $respond(404, 'not found');
    }

    return;
}

if ($path !== '/indexnow') {
    $respond(404, 'not found');

    return;
}

$log = $readLog();
$log[] = ['method' => $method, 'path' => $path, 'query' => $_GET, 'body' => $body, 'json' => json_decode($body, true), 'headers' => $headers, 'time' => microtime(true), 'scenario' => $scenario];
$writeLog($log);
$countForScenario = count(array_filter($log, static fn($r) => $r['scenario'] === $scenario));

if (!in_array($method, ['GET', 'POST'], true)) {
    $respond(405);

    return;
}
if ($method === 'POST') {
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['host'], $json['key'], $json['urlList']) || !is_array($json['urlList'])) {
        $respond(400, 'invalid body');

        return;
    }
    if (count($json['urlList']) > 10000) {
        $respond(400, 'too many urls');

        return;
    }
    foreach ($json['urlList'] as $u) {
        if (!is_string($u) || strcasecmp((string) parse_url($u, PHP_URL_HOST), $json['host']) !== 0) {
            $respond(422, 'url host mismatch');

            return;
        }
    }
}

$n = (int) ($_GET['n'] ?? 1);
switch ($scenario) {
    case 'ok200': $respond(200);
        break;
    case 'pending202': $respond(202);
        break;
    case 'bad400': $respond(400, 'bad');
        break;
    case 'forbidden403': $respond(403, 'forbidden');
        break;
    case 'unprocessable422': $respond(422, 'unprocessable');
        break;
    case 'ratelimit429': $respond(429, 'slow down', ['Retry-After' => '2']);
        break;
    case 'ratelimit429-then-ok': $countForScenario <= $n ? $respond(429, 'slow down', ['Retry-After' => '1']) : $respond(200);
        break;
    case 'flaky500-then-ok': $countForScenario <= $n ? $respond(503, 'oops') : $respond(200);
        break;
    case 'timeout': sleep(30);
        $respond(200);
        break;
    default: $respond(400, 'unknown scenario');
}
