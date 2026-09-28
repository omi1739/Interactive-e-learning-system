<?php
/**
 * Local configuration template.
 *
 * INSTRUCTIONS:
 *   1. Copy this file to config/local.php   (config/local.php is gitignored)
 *   2. Fill in the real values for your host
 *   3. Never commit config/local.php
 *
 * On GoogieHost (DirectAdmin):
 *   - MySQL Databases -> create a database, e.g. myuser_elearning
 *   - copy the database name, username and password into the constants below
 */

// 'production' hides all PHP errors from visitors and logs them to the log
// directory below. Use 'development' locally only if you want errors on screen.
define('APP_ENV_LOCAL', 'production');

// Database credentials for your host.
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

/*
 * Writable data directories.
 *
 * RECOMMENDED: keep both of these OUTSIDE the web root, because GoogieHost
 * (like most shared hosting) allows PHP to write there and the directories
 * then cannot be fetched over HTTP at all. This is the single most important
 * setting for protecting student submissions and error logs.
 *
 *   1. In DirectAdmin -> File Manager, create  /home/YOURUSER/data/uploads
 *      and /home/YOURUSER/data/logs
 *   2. Make them writable by PHP (chmod 755 is usually enough for DirectAdmin;
 *      if writes fail, try 775)
 *   3. Put the absolute paths below
 *
 * The trailing slash is required.
 */
define('UPLOAD_DIR_LOCAL', '/home/YOURUSER/data/uploads/');
define('LOG_DIR_LOCAL',    '/home/YOURUSER/data/logs/');

// Shared secret used to authenticate the external cron trigger
// (cron/auto_assign_reviews.php). Use a long random string.
// e.g. generate with:  php -r "echo bin2hex(random_bytes(32));"
define('CRON_TOKEN', 'change-me-to-a-long-random-string');
