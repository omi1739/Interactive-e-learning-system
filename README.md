# Interactive e-Learning System & Peer Review Platform

A web-based e-learning platform with collaborative peer assessment, rubric-based
grading, discussion forums, and progress tracking, built with **PHP (PDO)** and
**MySQL**.

---

## Requirements

- PHP 8.1+ with `pdo_mysql`, `mbstring` and **`fileinfo`** (the fileinfo
  extension is required for upload MIME validation; see
  [Uploads](#uploads-and-permissions))
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite`/`mod_headers` (optional but recommended) or Nginx

---

## Installation

There is **no web installer**. Configuration happens through a local file that
is excluded from version control, and the first account is created from the
command line.

### 1. Import the schema

```bash
mysql -u DB_USER -p DB_NAME < database/schema-only.sql
```

`database/schema-only.sql` contains the 12 table definitions and **no seed
data**. No demo users, no sample courses, no default passwords.

### 2. Create the local configuration

Copy `config/local.example.php` to `config/local.php` and fill in your values:

```bash
cp config/local.example.php config/local.php
```

`config/local.php` holds the database password and the cron token. It is listed
in `.gitignore` and additionally blocked from being served by
`config/.htaccess`, so it must never be committed.

### 3. Create the first administrator

```bash
php install/create_admin.php
```

`install/.htaccess` denies all HTTP access, so this script only runs from a
shell/terminal. Use SSH or your hosting control panel's terminal.

### 4. Make the upload and log directories writable

By default the application writes to `uploads/` and `logs/` inside the web
root. For a real deployment, put them **outside** the web root and point the
config at them instead:

```php
define('UPLOAD_DIR', '/home/YOURUSER/data/uploads');
define('LOG_DIR',    '/home/YOURUSER/data/logs');
```

Then:

```bash
mkdir -p /home/YOURUSER/data/uploads /home/YOURUSER/data/logs
chmod 750 /home/YOURUSER/data/uploads
chmod 750 /home/YOURUSER/data/logs
chown -R YOURUSER:YOURUSER /home/YOURUSER/data
```

---

## Uploads and permissions

Student submissions are private. They are stored with a generated filename and
are **only** readable through `download.php`, which verifies that the requester
is the owner, the reviewing instructor, or an administrator. The `uploads/`
directory additionally carries a `.htaccess` that denies all direct access, as a
second layer in case a file is ever moved back into the web root.

Uploads are validated on:

- extension allow-list (per assignment, default `pdf,doc,docx,zip,txt`)
- a rejection of any filename containing a double extension
  (`report.php.pdf`)
- a real MIME type check via `fileinfo`, not the browser-supplied
  `Content-Type`
- a maximum size, and a maximum enforced by PHP's own `upload_max_filesize`

Executable extensions (`php`, `phtml`, `phar`, `cgi`, `pl`, `py`, `sh`, ...) are
always rejected, including when they appear as a double extension.

If `fileinfo` is unavailable the upload handler refuses the upload rather than
trusting the extension alone.

---

## Scheduled peer review assignment

`cron/auto_assign_reviews.php` assigns peer reviews automatically for published
assignments whose due date has passed and that do not yet have enough reviews.

**Option A - server cron (preferred if available):**

```bash
0 2 * * * /usr/bin/php /home/YOURUSER/htdocs/cron/auto_assign_reviews.php
```

**Option B - external cron service over HTTPS.** Shared hosting often has no
shell access. Set a long random `CRON_TOKEN` in `config/local.php`, then have
[cron-job.org](https://cron-job.org) call, daily at 02:00:

```
https://YOURDOMAIN/cron/auto_assign_reviews.php?token=YOUR_CRON_TOKEN
```

The endpoint refuses to run without a matching token, accepts the token via the
`token` query parameter or an `X-Cron-Token` header, and returns plain text. It
does not require a session and never prints HTML.

---

## Roles and workflow

| Step | Who | Action |
| :--- | :--- | :--- |
| 1 | Instructor | Creates a course, adds modules and lessons. |
| 2 | Instructor | Creates an assignment and defines rubric criteria. |
| 3 | Student | Enrolls; the enrollment stays **pending** until the instructor approves it. |
| 4 | Student | Submits text and/or a file. |
| 5 | Instructor | Approves enrollments, assigns reviews manually or via cron. |
| 6 | Student | Reviews peers' work anonymously against the rubric. |
| 7 | Instructor | Reviews peer scores and sets the final grade. |

---

## Security notes

- Passwords are stored with `password_hash()` and verified with
  `password_verify()`; login regenerates the session ID.
- Every state-changing form is CSRF protected (`csrf_token()` /
  `verify_csrf()`), and destructive actions require POST rather than a GET
  link.
- All output that can contain user-supplied data is escaped with `e()`
  (`htmlspecialchars` with `ENT_QUOTES`).
- Every SQL statement uses PDO prepared statements.
- All queries are scoped to the current user, so one student cannot read
  another's submissions, reviews, or course content by changing a URL
  parameter.
- `.htaccess` sets `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, disables directory listing, blocks dotfiles and database
  dumps, and blocks direct access to `includes/`, `install/` and `database/`.

> `.htaccess` files only apply to Apache. If GoogieHost serves your site through
> Nginx, these rules are ignored and you must configure equivalent rules in the
> hosting control panel or `.htaccess` will have no effect.

---

## Database schema

12 tables:

- `users` - profiles, credential hashes, roles (`student`, `instructor`, `admin`)
- `courses` - metadata, codes, capacity, dates, publication status
- `enrollments` - student enrollments and approval status
- `modules` - course modules and ordering
- `lessons` - lesson content, type, duration
- `assignments` - due date, points, accepted formats, size limits
- `submissions` - student work, stored file reference, grade, feedback
- `rubrics` - criteria, weights, maximum scores
- `peer_reviews` - review assignments, status, overall feedback
- `review_scores` - per-criterion scores and comments
- `forums` - course discussion forums
- `forum_posts` - threads and replies

---

## Repository layout

```
.htaccess              Apache hardening rules
config/                database class + local.php (git-ignored, web-blocked)
includes/              bootstrap, auth, functions, helpers
install/               create_admin.php (CLI only)
database/              schema-only.sql (no demo data)
cron/                  auto_assign_reviews.php
uploads/               stored submissions (web-blocked)
logs/                  application logs (web-blocked)
student/ instructor/   role dashboards
```

> Six files with student names in their filenames were previously committed
> under `student/`. They were removed from the repository index, but **they
> remain in the Git history**. If this repository is ever made public, treat
> those names as disclosed and rotate the affected accounts. Rewriting history
> is the only way to remove them completely.

---

## License

Open-source educational platform for academic assessment and collaborative learning.
