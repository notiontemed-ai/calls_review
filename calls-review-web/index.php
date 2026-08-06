<?php
declare(strict_types=1);

$cfgPath = __DIR__ . '/config.php';
if (!is_file($cfgPath)) { http_response_code(500); exit('Нет config.php — скопируйте config.example.php в config.php и заполните.'); }
$cfg = require $cfgPath;

require __DIR__ . '/src/Sheets.php';
require __DIR__ . '/src/Data.php';
require __DIR__ . '/src/Bitrix.php';

// Приложение живёт в подпапке (например /internal/calls-review-web) — все пути относительно неё.
// База — путь к каталогу index.php относительно корня сайта.
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
$appDir  = str_replace('\\', '/', __DIR__);
if ($docRoot !== '' && str_starts_with($appDir, $docRoot)) {
    $base = rtrim(substr($appDir, strlen($docRoot)), '/');
} else {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
}

session_set_cookie_params(['lifetime' => (int)$cfg['session_lifetime'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

$uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($base !== '' && str_starts_with($uriPath, $base)) $uriPath = substr($uriPath, strlen($base));
if ($uriPath === '' ) { header('Location: ' . $base . '/'); exit; } // /qa → /qa/
$path   = $uriPath;
$method = $_SERVER['REQUEST_METHOD'];

function json_out(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function require_user(): array {
    if (empty($_SESSION['user'])) json_out(['error' => 'UNAUTHORIZED'], 401);
    return $_SESSION['user'];
}
/** index.html с версионированной статикой: app.css/app.js получают ?v=filemtime. */
function serve_index(): never {
    $html = (string)file_get_contents(__DIR__ . '/index.html');
    foreach (['app.css', 'app.js'] as $f) {
        $v = (string)@filemtime(__DIR__ . '/' . $f);
        $html = str_replace('"' . $f . '"', '"' . $f . '?v=' . $v . '"', $html);
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    echo $html;
    exit;
}
function app_log(array $cfg, string $line): void {
    @file_put_contents(rtrim($cfg['cache_dir'], '/') . '/app.log',
        date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

try {
    // ---------- Авторизация ----------
    if ($path === '/auth/login') {
        if (empty($cfg['bitrix_redirect_uri'])) {
            $cfg['bitrix_redirect_uri'] = 'https://' . $_SERVER['HTTP_HOST'] . $base . '/auth/callback';
        }
        $bx = new Bitrix($cfg);
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        header('Location: ' . $bx->authorizeUrl($_SESSION['oauth_state']));
        exit;
    }
    if ($path === '/auth/callback') {
        if (($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? null)) { http_response_code(403); exit('Неверный state.'); }
        if (empty($cfg['bitrix_redirect_uri'])) {
            $cfg['bitrix_redirect_uri'] = 'https://' . $_SERVER['HTTP_HOST'] . $base . '/auth/callback';
        }
        $bx = new Bitrix($cfg);
        $_SESSION['user'] = $bx->userByCode((string)($_GET['code'] ?? ''));
        app_log($cfg, 'LOGIN user=' . $_SESSION['user']['id'] . ' ' . $_SESSION['user']['name']);
        header('Location: ' . $base . '/');
        exit;
    }
    if ($path === '/auth/logout') {
        session_destroy();
        header('Location: ' . $base . '/');
        exit;
    }

    // ---------- API ----------
    if (str_starts_with($path, '/api/')) {
        $user = null;
        if ($path === '/api/me') {
            json_out(['user' => $_SESSION['user'] ?? null]);
        }
        $user = require_user();
        $sheets = new Sheets($cfg);
        $data = new Data($sheets->readMain());

        if ($path === '/api/summary' && $method === 'GET') {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
            json_out($data->summary($date));
        }

        if ($path === '/api/calls' && $method === 'GET') {
            json_out($data->calls($_GET));
        }

        if ($path === '/api/calls/keys' && $method === 'GET') {
            // call_key всех подтверждаемых строк текущего фильтра («выбрать всё по фильтру»)
            json_out(['keys' => $data->confirmableKeys($_GET)]);
        }

        if (preg_match('#^/api/calls/([^/]+)$#', $path, $m) && $method === 'GET') {
            $detail = $data->callDetail(urldecode($m[1]));
            $detail === null ? json_out(['error' => 'NOT_FOUND'], 404) : json_out($detail);
        }

        if ($path === '/api/operators' && $method === 'GET') {
            json_out($data->operatorsAndGroups());
        }

        if ($path === '/api/verdicts' && $method === 'POST') {
            $body = json_decode((string)file_get_contents('php://input'), true);
            $items = $body['items'] ?? null;
            if (!is_array($items) || !$items) json_out(['error' => 'VALIDATION', 'message' => 'items пуст'], 422);
            if (count($items) > 500) json_out(['error' => 'VALIDATION', 'message' => 'Не более 500 за раз'], 422);

            // Свежие данные для проверки review_status (минимизируем гонки).
            $data = new Data($sheets->readMain(true));

            $prepared = []; $results = [];
            foreach ($items as $it) {
                $key    = trim((string)($it['call_key'] ?? ''));
                $action = (string)($it['action'] ?? '');
                $score  = $it['new_score'] ?? null;
                $comment = trim((string)($it['comment'] ?? ''));

                $status = $key !== '' ? $data->reviewStatus($key) : null;
                if ($status === null)            { $results[] = ['call_key' => $key, 'ok' => false, 'code' => 'NOT_FOUND']; continue; }
                if ($status !== '')              { $results[] = ['call_key' => $key, 'ok' => false, 'code' => 'ALREADY_REVIEWED']; continue; }
                if (!in_array($action, ['CONFIRM', 'CHANGE_SCORE'], true)) { $results[] = ['call_key' => $key, 'ok' => false, 'code' => 'VALIDATION']; continue; }
                if ($action === 'CHANGE_SCORE') {
                    $scoreInt = (int)$score;
                    if ($scoreInt < 1 || $scoreInt > 5 || $comment === '') { $results[] = ['call_key' => $key, 'ok' => false, 'code' => 'VALIDATION']; continue; }
                    $score = $scoreInt;
                } else {
                    $score = null;
                }
                $prepared[] = ['call_key' => $key, 'action' => $action, 'new_score' => $score, 'comment' => $comment]
                    + ['snapshot' => $data->snapshotForJournal($key)];
                $results[] = ['call_key' => $key, 'ok' => true];
            }

            if ($prepared) {
                $payload = json_encode([
                    'reviewer' => ['bitrix_user_id' => $user['id'], 'name' => $user['name']],
                    'items'    => $prepared,
                ], JSON_UNESCAPED_UNICODE);

                $ch = curl_init($cfg['n8n_verdict_webhook_url']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'X-Auth-Token: ' . $cfg['n8n_webhook_secret'],
                    ],
                ]);
                $resp = curl_exec($ch);
                $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                app_log($cfg, sprintf('VERDICTS user=%s sent=%d http=%d', $user['id'], count($prepared), $http));
                if ($resp === false || $http >= 300) {
                    json_out(['error' => 'N8N_WRITE_FAILED', 'message' => 'Запись вердиктов не выполнена, попробуйте ещё раз.'], 502);
                }
                $sheets->invalidate();
            }
            json_out(['results' => $results]);
        }

        json_out(['error' => 'NOT_FOUND'], 404);
    }

    // ---------- Статика SPA ----------
    // Служебные файлы напрямую не отдаём никогда.
    if (preg_match('#^/(config|service-account|src/|storage/|n8n/|apps_script/)#', $path)) {
        http_response_code(403); exit('Forbidden');
    }
    // index.html всегда через serve_index() — иначе браузер получит ссылки без версий.
    if ($path === '/' || $path === '/index.html') serve_index();
    $real = realpath(__DIR__ . $path);
    if ($real && str_starts_with($real, __DIR__) && is_file($real)) {
        $ext = pathinfo($real, PATHINFO_EXTENSION);
        $mime = ['html' => 'text/html', 'js' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($real);
        exit;
    }
    // SPA fallback
    serve_index();

} catch (Throwable $e) {
    app_log($cfg, 'ERROR ' . $e->getMessage());
    if (str_starts_with($path, '/api/')) json_out(['error' => 'SERVER', 'message' => $e->getMessage()], 500);
    http_response_code(500);
    echo 'Ошибка сервера. Подробности в логе.';
}
