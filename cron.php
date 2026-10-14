<?php
// SzenzorKozpont — scheduled jobs from manual/system cron (OPTIONAL).
// By default web tick does background work after API calls
// (see src/tick.php), so system cron is NOT required. cron.php is for
// manual runs, mod_php setups, or forced jobs.
//   php cron.php --job=all      (all jobs now)
//   php cron.php --job=alarms   (alarms + notify now)
//   php cron.php --job=nightly  (hourly + cleanup + expire now)

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/src/bootstrap.php';

$job = 'all';
foreach ($argv as $a) {
    if (str_starts_with($a, '--job=')) {
        $job = substr($a, 6);
    }
}

migrate();

switch ($job) {
    case 'hourly':
        echo 'hourly: ' . job_hourly() . " sensor\n";
        cron_mark('hourly');
        break;
    case 'cleanup':
        [$a, $b] = job_cleanup();
        cron_mark('cleanup');
        echo "cleanup: raw=$a hourly=$b\n";
        break;
    case 'expire':
        [$a, $b] = job_expire();
        cron_mark('expire');
        echo "expire: commands=$a tokens=$b\n";
        break;
    case 'alarms':
        echo 'alarms: new=' . job_alarms() . "\n";
        echo 'notify: sent=' . job_notify() . "\n";
        cron_mark('alarms');
        break;
    case 'mail':
        echo 'mail: sent=' . job_mail() . "\n";
        cron_mark('mail');
        break;
    case 'nightly':
        echo 'hourly: ' . job_hourly() . " sensor\n";
        cron_mark('hourly');
        [$a, $b] = job_cleanup();
        cron_mark('cleanup');
        echo "cleanup: raw=$a hourly=$b\n";
        [$a, $b] = job_expire();
        cron_mark('expire');
        echo "expire: commands=$a tokens=$b\n";
        break;
    default:
        echo 'hourly: ' . job_hourly() . " sensor\n";
        cron_mark('hourly');
        [$a, $b] = job_cleanup();
        cron_mark('cleanup');
        echo "cleanup: raw=$a hourly=$b\n";
        [$a, $b] = job_expire();
        cron_mark('expire');
        echo "expire: commands=$a tokens=$b\n";
        echo 'alarms: new=' . job_alarms() . "\n";
        echo 'notify: sent=' . job_notify() . "\n";
        cron_mark('alarms');
        break;
}
