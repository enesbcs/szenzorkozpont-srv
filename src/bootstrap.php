<?php
// SzenzorKozpont — bootstrap: config, error handling, module load.

declare(strict_types=1);

require __DIR__ . '/util.php';
require_once __DIR__ . '/lang.php'; // require_once: install.php may preload
require __DIR__ . '/db.php';

$c = cfg();
@mkdir($c['data_dir'], 0770, true);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', rtrim($c['data_dir'], '/') . '/php-errors.log');

set_exception_handler(static function (Throwable $ex): void {
    error_log('SzenzorKozpont: ' . $ex->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'HIBA: ' . $ex->getMessage() . "\n");
        exit(1);
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    // API/mobile/OTA: JSON error (stable contract). All other web
    // surfaces: readable error page instead of raw JSON.
    $wantsJson = (bool)preg_match('#/(api|ota)(/|$)|/m/api(/|$)#', $uri);
    if ($wantsJson) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'err' => 'internal']);
        exit;
    }
    // Details for logged-in admins only (no schema info leaked).
    $detail = '';
    try {
        if (!empty($_SESSION['uid'])) {
            $me = q1('SELECT role FROM users WHERE id=?', [(int)$_SESSION['uid']]);
            if ($me && $me['role'] === 'admin') {
                $detail = $ex->getMessage();
            }
        }
    } catch (Throwable $ignored) {
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html lang="' . e(lang_current()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . t('errpage.title') . '</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:2em auto">'
        . '<h1>' . t('errpage.h1') . '</h1>'
        . ($detail !== ''
            ? '<p><code>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</code></p>'
              . '<p><small>' . t('errpage.log') . '</small></p>'
            : '<p><small>' . t('errpage.admin') . '</small></p>')
        . '<p><a href="./">' . t('errpage.home') . '</a></p></body></html>';
    exit;
});

require __DIR__ . '/auth_api.php';
require __DIR__ . '/auth_web.php';
require __DIR__ . '/acl.php';
require __DIR__ . '/api.php';
require __DIR__ . '/jobs.php';
require __DIR__ . '/rules.php';
require __DIR__ . '/smtp.php';
require __DIR__ . '/tick.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/ui_admin.php';
require __DIR__ . '/ui_user.php';
require __DIR__ . '/mapi.php';

// Global UI language (settings; 'hu' on upgrades). Install locks its own
// choice beforehand (first-writer-wins), so this is a no-op there.
lang_set(setting('lang', 'hu'));

// After web requests run due background jobs (no cron needed).
// Not in CLI (cron.php, install.php): run directly there.
if (PHP_SAPI !== 'cli') {
    szk_migrate_throttled();
    register_shutdown_function('szk_tick');
}
