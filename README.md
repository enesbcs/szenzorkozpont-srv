# SzenzorKozpont server — installation

Self-hosted IoT cloud (PHP + nginx).

## Requirements

- PHP 8.1+ (`php-fpm`, `php-sqlite3` **or** `php-mysql`, `php-mbstring`)
- nginx, TLS certificate (e.g. certbot)
- Database: SQLite (fine for small setups) or MySQL/MariaDB

## 1. Files

Copy the **contents** into the webroot (`index.php` must end up at the root).

## 2. Configuration

```bash
cp config.example.php config.php
```

Set up the database (`db_driver`, MySQL credentials or SQLite path).
Everything else starts with defaults.

## 3. Installation

Via CLI (recommended):

```bash
php install.php --admin=NAME --password=SECRET
```

or by opening it in a browser (only for installations copied to the www root).
After a successful install, `install.php` **deletes itself automatically**.

## 4. Verification

Log in, open Settings → Database: the System box must show
everything as fine (PHP version, migration, data dir).

## 5. nginx

Template: `nginx-szenzorkozpont.conf` (replace the `<...>` placeholders),
with details inline:

- **A) Debian + nginx**: the conf's `A)` block, `server_name` + TLS.
- **B) YunoHost + nginx**: install `my_webapp` with MySQL, copy files via
  SFTP into `www/`, the conf's `B)` block + 1 line change in the app conf.
  The app **must be public ("visitors")**, otherwise SSOWAT sends even
  the API calls to the login page. (No SSO/LDAP needed.)

Activate: `nginx -t && systemctl reload nginx`.

## 6. Scheduling (cron)

Background jobs run by themselves after incoming requests (`tick`),
a system cron is **not required**. To run manually:

```bash
php /path/to/www/cron.php --job=all   # or: alarms | nightly | mail
```
