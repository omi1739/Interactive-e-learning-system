# Deployment guide - GoogieHost (PHP 8.3 / MySQL)

Step-by-step for putting this application on shared hosting where you do not
have shell access to a database client and the server cron is not available.

---

## 1. Before you start

You need from the hosting panel:

- The MySQL **database name**, **username**, **password** and **host**
  (usually `localhost`)
- The PHP version selector (choose **8.3**)
- Either **phpMyAdmin** or a way to run `.sql` files
- The absolute path to your web root (commonly
  `/home/USER/htdocs` or `/home/USER/public_html`)
- The `fileinfo` extension enabled - check in phpMyAdmin/phpinfo or ask support

Confirm PHP's upload limits are adequate. The application refuses files larger
than the per-assignment limit, but PHP's own limits apply first:

```ini
upload_max_filesize = 20M
post_max_size = 24M
max_file_uploads = 5
```

---

## 2. Upload the code

Upload the project into the web root, **excluding**:

- `config/local.php` (you will create it on the server)
- `.git/`
- `logs/*` and `uploads/*` contents (keep the directories and their `.htaccess`
  files, but not any files already inside)

---

## 3. Create the database

Create a database and a user in the hosting panel, grant that user all
privileges on that database, and import the schema through phpMyAdmin:

- **Select your database**
- **Import** &rarr; upload `database/schema-only.sql` &rarr; **Go**

The file contains only `CREATE TABLE` statements. Confirm that 13 tables appear.

Do **not** import `database/schema.sql` from an old checkout that still contains
the old seed block - it creates accounts sharing the password `password123`.

---

## 4. Create `config/local.php`

In the hosting panel's file manager, copy `config/local.example.php` to
`config/local.php` and set:

```php
<?php
define('APP_ENV_LOCAL', 'production');
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// A long random string, e.g. output of: openssl rand -hex 32
define('CRON_TOKEN', 'paste-a-long-random-string-here');

// Recommended: move these outside the web root
define('UPLOAD_DIR_LOCAL', '/home/YOURUSER/data/uploads');
define('LOG_DIR_LOCAL',    '/home/YOURUSER/data/logs');
```

Notes:

- `DB_PASS` must be the real password even if it is empty; use `''`.
- If the database host is not `localhost`, use the host the panel gives you.
- The `_LOCAL` suffix on `APP_ENV_LOCAL`, `UPLOAD_DIR_LOCAL` and `LOG_DIR_LOCAL`
  is **required**. `includes/bootstrap.php` reads only those names. Writing
  `UPLOAD_DIR` or `LOG_DIR` here does nothing at all: the app silently falls
  back to writing uploads and error logs inside the web root, which is exactly
  what this step exists to prevent.
- The `APP_ENV` **environment variable** wins over `APP_ENV_LOCAL`. If your host
  exposes environment variables, make sure `APP_ENV` is not set there, or
  `'production'` below is ignored and errors may be shown to visitors.
- `UPLOAD_DIR_LOCAL` and `LOG_DIR_LOCAL` must be **absolute** paths. A trailing
  slash is fine either way - every consumer `rtrim()`s the value before joining.
- If the panel cannot create directories outside the web root, leave these two
  undefined and the app falls back to `uploads/assignments/` and `logs/`
  inside the root. The `.htaccess` in each still blocks web access, but moving
  them out is the stronger option.

`config/local.php` is in `.gitignore`, and `config/.htaccess` denies HTTP access
to it. Double-check by browsing to
`https://YOURDOMAIN/config/local.php` - you must get a 403/404, never the file
contents.

---

## 5. Writable directories

```bash
mkdir -p /home/YOURUSER/data/uploads /home/YOURUSER/data/logs
chmod 750 /home/YOURUSER/data/uploads /home/YOURUSER/data/logs
chmod 640 /home/YOURUSER/config/local.php
```

If you cannot use a shell, the hosting panel usually has a "permissions" dialog.
Set those two directories to `750` (or `755` if the panel only offers octal
permissions) and `local.php` to `640`.

---

## 6. Create the first administrator

`install/.htaccess` denies all web access to the `install/` folder, so the
script must be run from a terminal. Use SSH if you have it, otherwise the
hosting panel's **Terminal**/**SSH** feature:

```bash
cd /home/YOURUSER/htdocs
php install/create_admin.php
```

It prompts for a username, email and password. Passwords are hashed with
`password_hash()`.

---

## 7. Scheduled peer review assignment

Shared hosting frequently disables cron. Use an external service.

1. Generate a long random token:
   ```bash
   openssl rand -hex 32
   ```
2. Put it in `config/local.php` as `CRON_TOKEN`.
3. At [cron-job.org](https://cron-job.org) create a new HTTPS cron job:
   ```
   https://YOURDOMAIN/cron/auto_assign_reviews.php?token=YOUR_CRON_TOKEN
   ```
   Schedule it daily at 02:00.
4. Execute the job once manually there and check the response. It should be
   plain text such as `OK - processed 0 assignment(s)`. A `403` with
   `Invalid or missing token.` means the token in the URL does not match
   `config/local.php`.

If your host *does* offer real cron, this is better (the token is not in a URL):

```
0 2 * * * /usr/bin/php /home/YOURUSER/htdocs/cron/auto_assign_reviews.php
```

---

## 8. Post-deployment checklist

- [ ] `https://YOURDOMAIN/` redirects to `login.php` when not signed in
- [ ] `https://YOURDOMAIN/config/local.php` is **not** readable
- [ ] `https://YOURDOMAIN/install/create_admin.php` is **not** reachable
- [ ] `https://YOURDOMAIN/database/schema-only.sql` is **not** downloadable
- [ ] `https://YOURDOMAIN/logs/` is **not** browsable
- [ ] `https://YOURDOMAIN/uploads/` is **not** browsable
- [ ] `config/local.php` uses `APP_ENV_LOCAL`, `UPLOAD_DIR_LOCAL` and
      `LOG_DIR_LOCAL` (not the un-suffixed names)
- [ ] A submission file is only downloadable through `download.php`
- [ ] Registering as a student leaves the enrollment **pending** until an
      instructor approves it
- [ ] Marking a lesson complete in a course persists across a page reload
- [ ] The admin account can log in
- [ ] `config/local.php` has not been committed to Git

Run the two static checks from the project root before you call it done:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\lint.ps1
php tools/audit.php
```

---

## 8a. Upgrading a database created before lesson progress

A site deployed from an older checkout has no `lesson_progress` table, and the
course page will then fail on every load. Import
`database/migrations/001_lesson_progress.sql` through phpMyAdmin
(**Select your database** &rarr; **Import**). It contains a single
`CREATE TABLE IF NOT EXISTS`, so re-running it is harmless.

A fresh import of `database/schema-only.sql` already includes the table and
needs no migration.

---

## 9. Troubleshooting

**Blank page or "Application is not configured correctly."**
`config/local.php` is missing or a credential is wrong. Check the file, and
look in the log directory for the underlying PDO error.

**"Upload failed" on every submission.**
`fileinfo` is not enabled, or `UPLOAD_DIR_LOCAL` is not writable. Verify the
extension in phpMyAdmin, then check the directory permissions.

**Uploads work but files 404 when downloaded.**
`UPLOAD_DIR_LOCAL` does not match the directory the files were actually written
to. Check the value in `config/local.php`; a trailing slash is not the cause
either way, since every consumer normalises it.

**"Table 'lesson_progress' doesn't exist".**
The migration in section 8a has not been applied to this database.

**Cron returns 403.**
`CRON_TOKEN` is still the placeholder value, or the token in the URL differs
from the one in `config/local.php`.

**Styles or the dark theme look wrong, but the app runs.**
The `.htaccess` files require Apache. If the host uses Nginx, ask support to
apply equivalent rules, at minimum for `config/`, `install/`, `database/`,
`logs/` and `uploads/`.
