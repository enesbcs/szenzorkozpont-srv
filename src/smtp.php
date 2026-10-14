<?php
// SzenzorKozpont — minimal SMTP client (STARTTLS/AUTH LOGIN), no
// external deps. For alarm notifications and test mail.
// Credentials never land in error messages.

declare(strict_types=1);

/** SMTP settings from settings table. */
function smtp_config(): array
{
    return [
        'host' => (string)(setting('smtp_host', '') ?? ''),
        'port' => (int)(setting('smtp_port', '') ?: 587),
        'user' => (string)(setting('smtp_user', '') ?? ''),
        'pass' => (string)(setting('smtp_pass', '') ?? ''),
        'from' => (string)(setting('smtp_from', '') ?? ''),
        'secure' => (string)(setting('smtp_secure', '') ?: 'starttls'),
    ];
}

/** Send mail via configured SMTP. Error text in $err (no password). */
function smtp_send(string $to, string $subject, string $body, ?string &$err = null): bool
{
    $c = smtp_config();
    if ($c['host'] === '' || $to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        $err = t('smtp.e_cfg');
        return false;
    }
    if ($c['from'] === '' || filter_var($c['from'], FILTER_VALIDATE_EMAIL) === false) {
        $err = t('smtp.e_from');
        return false;
    }
    $secure = in_array($c['secure'], ['starttls', 'ssl', 'none'], true) ? $c['secure'] : 'starttls';
    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $c['host'] . ':' . $c['port'];
    $fp = @stream_socket_client($remote, $errno, $errstr, 10);
    if (!$fp) {
        $err = t('smtp.e_conn', $c['host'], (int)$c['port']);
        return false;
    }
    stream_set_timeout($fp, 10);
    $fail = static function (string $msg) use (&$err, $fp): bool {
        $err = $msg;
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return false;
    };
    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 512)) !== false) {
            $out .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        return $out;
    };
    $cmd = static function (string $c, string $expect) use ($fp, $read): bool {
        fwrite($fp, $c . "\r\n");
        return str_starts_with($read(), $expect);
    };
    $greet = $read();
    if (!str_starts_with($greet, '220')) {
        return $fail('A szerver nem fogadta a kapcsolatot.');
    }
    $ehlo = gethostname() ?: 'szenzorkozpont';
    if (!$cmd('EHLO ' . $ehlo, '250')) {
        return $fail(t('smtp.e_ehlo'));
    }
    if ($secure === 'starttls') {
        if (!$cmd('STARTTLS', '220')) {
            return $fail(t('smtp.e_starttls'));
        }
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
            return $fail(t('smtp.e_tls'));
        }
        if (!$cmd('EHLO ' . $ehlo, '250')) {
            return $fail(t('smtp.e_ehlo2'));
        }
    }
    if ($c['user'] !== '') {
        if (!$cmd('AUTH LOGIN', '334')) {
            return $fail(t('smtp.e_auth'));
        }
        if (!$cmd(base64_encode($c['user']), '334')) {
            return $fail(t('smtp.e_user'));
        }
        if (!$cmd(base64_encode($c['pass']), '235')) {
            return $fail(t('smtp.e_authfail'));
        }
    }
    if (!$cmd('MAIL FROM:<' . $c['from'] . '>', '250')) {
        return $fail(t('smtp.e_sender'));
    }
    if (!$cmd('RCPT TO:<' . $to . '>', '250') && !$cmd('RCPT TO:<' . $to . '>', '251')) {
        return $fail(t('smtp.e_rcpt'));
    }
    if (!$cmd('DATA', '354')) {
        return $fail(t('smtp.e_data'));
    }
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
    $data = 'From: ' . $c['from'] . "\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: ' . $subj . "\r\n"
        . 'Content-Type: text/plain; charset=utf-8' . "\r\n"
        . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n";
    foreach ($lines as $ln) {
        // Dot-stuffing (SMTP dot-stuffing).
        $data .= (str_starts_with($ln, '.') ? '.' : '') . $ln . "\r\n";
    }
    fwrite($fp, $data . ".\r\n");
    $resp = $read();
    if (!str_starts_with($resp, '250')) {
        return $fail(t('smtp.e_msg'));
    }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}
