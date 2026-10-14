<?php
// SzenzorKozpont — installer from CLI AND browser.
//   CLI:     php install.php --admin=NAME --password=SECRET [--driver=sqlite|mysql]
//   Browser: https://server/install.php (form) — ONLY reachable in a
//            www/-root copy install.
// MySQL creds from config.php (my_webapp: filled from db_access.txt).
// SQLite needs nothing (DB auto-created).
//
// On success the file DELETES ITSELF (browser and CLI modes alike).
// For reinstall, re-upload via SFTP.

declare(strict_types=1);

require_once __DIR__ . '/src/lang.php';

$isCli = PHP_SAPI === 'cli';

// Installer language: explicit choice wins (EN default), saved to DB on success.
if ($isCli) {
    $ilang = 'en';
    foreach ($argv as $a) {
        if (str_starts_with($a, '--lang=')) {
            $ilang = substr($a, 7);
        }
    }
} else {
    $ilang = (string)($_GET['lang'] ?? 'en');
}
lang_set($ilang, true);

/** Install core: schema + admin. Returns [ok, message]. */
function install_run(string $admin, string $pass): array
{
    if ($admin === '' || strlen($admin) > 64) {
        return [false, t('install.bad_name')];
    }
    if (strlen($pass) < 12) {
        return [false, t('install.short_pass')];
    }
    require_once __DIR__ . '/src/bootstrap.php';
    if (PHP_SAPI !== 'cli') {
        // Readable error page in browser instead of JSON.
        set_exception_handler(static function (Throwable $ex): void {
            error_log('SzenzorKozpont install: ' . $ex->getMessage());
            http_response_code(500);
            echo '<h1>Telepítési hiba</h1><p>Ellenőrizd a config.php adatbázis-beállításait, majd próbáld újra.</p>';
            exit;
        });
    }
    migrate();
    $n = q1('SELECT COUNT(*) c FROM users');
    if ((int)($n['c'] ?? 0) > 0) {
        return [false, t('install.exists')];
    }
    q('INSERT INTO users (name, pass_hash, role, protected, created_at) VALUES (?,?,?,1,?)',
        [$admin, password_hash($pass, PASSWORD_ARGON2ID), 'admin', time()]);
    audit($admin, 'install', 'db=' . db_driver());
    setting_set('lang', lang_current());
    return [true, t('install.done_msg', db_driver(), $admin)];
}

/** Self-delete — mandatory on success (else anyone could reinstall). */
function install_self_delete(): bool
{
    @unlink(__FILE__);
    return !file_exists(__FILE__);
}

if ($isCli) {
    $opts = ['admin' => '', 'password' => ''];
    foreach ($argv as $a) {
        if (str_starts_with($a, '--admin=')) {
            $opts['admin'] = substr($a, 8);
        }
        if (str_starts_with($a, '--password=')) {
            $opts['password'] = substr($a, 11);
        }
        if (str_starts_with($a, '--driver=')) {
            putenv('SZK_DB_DRIVER=' . substr($a, 9));
        }
    }
    if ($opts['admin'] === '' || $opts['password'] === '') {
        echo t('install.usage');
        exit(1);
    }
    [$ok, $msg] = install_run($opts['admin'], $opts['password']);
    echo ($ok ? t('install.ok') : t('install.fail')) . $msg . "\n";
    if (!$ok) {
        exit(1);
    }
    if (install_self_delete()) {
        echo t('install.deleted') . "\n";
    } else {
        echo t('install.nodelete') . "\n";
    }
    echo t('install.next');
    exit(0);
}

// ------------------------------------------------------------ browser mode ---
// Note: this file lives at www/ ROOT, so it renders its own
// minimal HTML (index.php router not reachable from here).
$c = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php'
    : require __DIR__ . '/config.example.php';
$drv = ($c['db_driver'] ?? 'sqlite') === 'mysql'
    ? 'MySQL (' . ($c['mysql_db'] ?? '?') . '@' . ($c['mysql_host'] ?? '?') . ')'
    : 'SQLite (' . ($c['sqlite_path'] ?? '?') . ')';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = trim($_POST['admin'] ?? '');
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if ($p1 !== $p2) {
        $err = t('install.mismatch');
    } else {
        [$ok, $msg] = install_run($admin, $p1);
        if ($ok) {
            $del = install_self_delete();
            echo '<!DOCTYPE html><html lang="' . lang_current() . '"><head><meta charset="utf-8">'
                . '<title>' . t('install.done_title') . '</title></head><body>'
                . '<h1>' . t('install.done_h1') . '</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
                . ($del ? '<p>' . t('install.done_next') . '</p>'
                    : '<p style="color:red">' . t('install.done_nodelete') . '</p>')
                . '<h2>' . t('install.cron_h2') . '</h2>'
                . '<p>' . t('install.cron_p') . '</p>'
                . '<pre>' . t('install.cron_cmd') . '</pre>'
                . '<p>' . t('install.cron_note') . '</p>'
                . '</body></html>';
            exit;
        }
        $err = $msg;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= lang_current() ?>">
<head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= t('install.title') ?></title></head>
<body style="font-family:system-ui,sans-serif;max-width:640px;margin:2em auto">
<p><?= t('install.lang') ?>: <a href="?lang=hu">Magyar</a> | <a href="?lang=en">English</a></p>
<h1><?= t('install.title') ?></h1>
<p><?= t('install.db') ?> <b><?= htmlspecialchars($drv, ENT_QUOTES, 'UTF-8') ?></b>
(<?= t('install.db_mysql_hint') ?>)</p>
<p><b><?= t('install.autodel') ?></b></p>
<?php if (!empty($err)): ?>
<p style="color:red"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="post">
<label><?= t('install.admin') ?><br><input name="admin" required></label><br><br>
<label><?= t('install.pass1') ?><br><input type="password" name="password" required></label><br><br>
<label><?= t('install.pass2') ?><br><input type="password" name="password2" required></label><br><br>
<button type="submit"><?= t('install.submit') ?></button>
</form>
</body>
</html>
