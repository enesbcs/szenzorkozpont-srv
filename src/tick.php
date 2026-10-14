<?php
// SzenzorKozpont — "poor man's cron": run due background jobs
// after incoming web/API requests, not from system cron.
//
// Operation: bootstrap registers this as shutdown function at each
// web request end. Response already sent (fastcgi_finish_request), client
// feels no extra work. File lock excludes parallel runs,
// cron_state table tracks due-ness.
// Without fastcgi (e.g. mod_php), only fast jobs run to keep
// latency low — heavy ones stay for cron.php.

declare(strict_types=1);

function szk_tick(): void
{
    $lockFile = rtrim(cfg()['data_dir'], '/') . '/cron.lock';
    $fp = @fopen($lockFile, 'c');
    if (!$fp) {
        return;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return; // another process already working
    }
    try {
        @set_time_limit(0);
        $async = function_exists('fastcgi_finish_request');
        if ($async) {
            fastcgi_finish_request(); // response closed, client free
        }
        if (cron_due('alarms')) {
            job_alarms();
            job_notify();
            cron_mark('alarms');
        }
        if (!$async) {
            return; // sync mode: fast jobs only
        }
        if (cron_due('hourly')) {
            job_hourly();
            cron_mark('hourly');
        }
        if (cron_due('expire')) {
            job_expire();
            cron_mark('expire');
        }
        if (cron_due('cleanup')) {
            job_cleanup();
            cron_mark('cleanup');
        }
        // Mail digest (slow: SMTP) — async mode only, at most once per minute.
        if (cron_due('mail')) {
            job_mail();
            cron_mark('mail');
        }
    } catch (Throwable $e) {
        error_log('SzenzorKozpont tick: ' . $e->getMessage());
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
