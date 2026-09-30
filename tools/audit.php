<?php
/**
 * tools/audit.php - static checks that do not need a database.
 *
 * Run:  php tools/audit.php
 *
 * Checks:
 *   1. Parse errors (same engine as `php -l`).
 *   2. include/require targets that do not exist on disk.
 *   3. Calls to functions that are neither defined in the project, nor a PHP
 *      builtin, nor a method call.
 *   4. Local href/action targets that point at a file which does not exist.
 *   5. POST forms missing a CSRF token field.
 *   6. POST handlers that read $_POST without calling verify_csrf() first.
 *   7. Superglobals echoed without escaping.
 *
 * Every finding is a starting point for review, not proof of a bug: the tool
 * cannot see through dynamic calls or runtime-built URLs.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$skipDirs = ['.git', 'node_modules', 'vendor'];

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $f) use ($skipDirs) {
            $p = str_replace('\\', '/', $f->getPathname());
            foreach ($skipDirs as $s) {
                if (strpos($p, '/' . $s . '/') !== false) return false;
            }
            return true;
        }
    )
);
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        $files[] = $f->getPathname();
    }
}
sort($files);

$problems = [];
function report(string $level, string $file, string $msg): void {
    global $problems;
    $problems[] = [$level, $file, $msg];
}

$rel = static function (string $abs) use ($root): string {
    return str_replace('\\', '/', substr($abs, strlen($root) + 1));
};

/* ------------------------------------------------------------------ */
/* 1. Parse                                                            */
/* ------------------------------------------------------------------ */
$tmp = tempnam(sys_get_temp_dir(), 'audit');
foreach ($files as $file) {
    exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        report('ERROR', $rel($file), 'parse error: ' . trim(implode(' ', $out)));
    }
    $out = [];
}

/* ------------------------------------------------------------------ */
/* Collect defined functions and classes                              */
/* ------------------------------------------------------------------ */
$definedFns = [];
$definedClasses = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    $tokens = token_get_all($src);
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) continue;

        if ($t[0] === T_FUNCTION) {
            // Find the next meaningful token: the name.
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $definedFns[strtolower($tokens[$j][1])] = true;
                }
                break;
            }
        }
        if ($t[0] === T_CLASS || $t[0] === T_INTERFACE || $t[0] === T_TRAIT) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $definedClasses[strtolower($tokens[$j][1])] = true;
                }
                break;
            }
        }
    }
}

/* Methods do not count as global functions. */
$methodNames = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    if (preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $src, $m)) {
        foreach ($m[1] as $name) {
            $methodNames[strtolower($name)] = true;
        }
    }
}

/* ------------------------------------------------------------------ */
/* 2-3. Includes and function calls, per file                          */
/* ------------------------------------------------------------------ */
foreach ($files as $file) {
    $src = file_get_contents($file);
    $fileDir = dirname($file);

    /* --- include/require targets --- */
    if (preg_match_all(
        '/\b(?:require|require_once|include|include_once)\s*\(?\s*([\'"])(.+?)\1/i',
        $src,
        $m,
        PREG_SET_ORDER
    )) {
        foreach ($m as $hit) {
            $target = $hit[2];
            if (strpos($target, '$') !== false || strpos($target, '://') !== false) {
                continue; // dynamic or remote: cannot resolve statically
            }
            $candidate = $target[0] === '/'
                ? $root . $target
                : $fileDir . '/' . $target;
            if (!file_exists($candidate)) {
                report('ERROR', $rel($file), "include target does not exist: {$target}");
            }
        }
    }

    /* --- function calls --- */
    $tokens = token_get_all($src);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) {
            continue;
        }
        $name = $t[1];
        $lower = strtolower($name);

        // Must be followed by '(' to be a call.
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }
        // Preceded by ->, ?->, :: or function (i.e. a declaration/method).
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
            $k--;
        }
        if ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION], true)) {
            continue;
        }
        if ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_NEW) {
            continue;
        }
        if (isset($definedFns[$lower])) {
            continue;
        }
        if (function_exists($lower)) {
            continue;
        }
        // Dynamic call through a variable, or a method-only name.
        if (isset($methodNames[$lower])) {
            continue;
        }
        // Language construct-ish names that token_get_all reports as T_STRING.
        if (in_array($lower, ['self', 'static', 'parent', 'array', 'list', 'isset', 'empty', 'echo', 'print', 'unset', 'exit', 'die'], true)) {
            continue;
        }
        report('WARN', $rel($file), "call to undefined function: {$name}() at line {$t[2]}");
    }
}

/* ------------------------------------------------------------------ */
/* 4. Local link targets                                               */
/* ------------------------------------------------------------------ */

/**
 * Blank out PHP comments so documentation that mentions src="..." or
 * href="..." is not mistaken for real markup. Newlines are preserved so
 * reported line numbers stay accurate.
 */
function strip_php_comments(string $src): string
{
    $out = '';
    $tokens = @token_get_all($src);
    if (!is_array($tokens) || $tokens === []) {
        // Fall back to the raw source if tokenising failed.
        return $src;
    }
    foreach ($tokens as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($t[1], "\n"));
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

foreach ($files as $file) {
    $src = strip_php_comments(file_get_contents($file));
    $fileDir = dirname($file);

    if (preg_match_all('/\b(?:href|action|src)\s*=\s*([\'"])(.+?)\1/i', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $url = html_entity_decode($hit[2], ENT_QUOTES, 'UTF-8');
            $line = 0;
            $pos = strpos($src, $hit[0]);
            if ($pos !== false) {
                $line = substr_count(substr($src, 0, $pos), "\n") + 1;
            }

            // Skip anything not a plain in-repo path.
            if ($url === '' || $url[0] === '#' || $url[0] === '?') continue;
            if (strpos($url, '://') !== false) continue;
            if (strpos($url, 'mailto:') === 0 || $url === 'tel:') continue;
            if (strpos($url, 'javascript:') === 0) continue;
            if (strpos($url, '$') !== false) continue;   // dynamic
            if (preg_match('/^data:/i', $url)) continue;
            if (preg_match('/^(GET|POST)$/', $url)) continue; // JS in attribute
            // A URL assembled by PHP inside the attribute. The attribute regex
            // can capture the tail of a call such as app_url('x.php') because of
            // quote handling, so reject anything that still looks like code.
            if (preg_match('/[()\'"]/', $url)) continue;
            if (stripos($url, 'echo') !== false) continue;
            if (stripos($url, '<?') !== false) continue;

            $path = $url;
            $query = parse_url($url, PHP_URL_QUERY);
            if ($query !== null && $query !== false) {
                $path = strtok($url, '?');
            }
            $path = html_entity_decode((string) $path, ENT_QUOTES, 'UTF-8');
            $path = rtrim($path, '/');
            if ($path === '') continue;

            // Resolve relative to this file, allowing ../ to walk up.
            $base = $fileDir;
            $rest = $path;
            while (strpos($rest, '../') === 0) {
                $base = dirname($base);
                $rest = substr($rest, 3);
            }
            $candidate = $base . '/' . ltrim($rest, '/');

            if (is_dir($candidate)) {
                if (!file_exists($candidate . '/index.php')) {
                    report('WARN', $rel($file), "link to directory with no index.php: {$url} (line {$line})");
                }
                continue;
            }
            if (!file_exists($candidate)) {
                report('WARN', $rel($file), "broken local link: {$url} (line {$line})");
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* 5-6. CSRF pairing                                                  */
/* ------------------------------------------------------------------ */
foreach ($files as $file) {
    $src = file_get_contents($file);

    // Every <form method="POST"> should embed the token.
    if (preg_match_all('/<form\b[^>]*\bmethod\s*=\s*[\'"]?post[\'"]?[^>]*>(.*?)<\/form>/is', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $form) {
            if (stripos($form[0], 'csrf_field()') === false && stripos($form[0], 'csrf_token') === false) {
                $pos = strpos($src, $form[0]);
                $line = $pos !== false ? substr_count(substr($src, 0, $pos), "\n") + 1 : 0;
                report('ERROR', $rel($file), "POST form without a CSRF token (line {$line})");
            }
        }
    }

    // A file that branches on POST must verify the token before touching $_POST.
    if (preg_match('/REQUEST_METHOD\]\s*===?\s*[\'"]POST[\'"]|if\s*\(\s*\$_POST\s*\)|if\s*\(\s*!\s*empty\s*\(\s*\$_POST/', $src)) {
        if (stripos($src, 'verify_csrf') === false) {
            report('ERROR', $rel($file), 'handles POST but never calls verify_csrf()');
        }
    }
}

/* ------------------------------------------------------------------ */
/* 7. Unescaped superglobals in output                                 */
/* ------------------------------------------------------------------ */
foreach ($files as $file) {
    $src = file_get_contents($file);
    if (preg_match_all('/<\?(=\s*|php\s+echo\s+)\s*\$_(GET|POST|REQUEST|SERVER)\b/i', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $pos = strpos($src, $hit[0]);
            $line = $pos !== false ? substr_count(substr($src, 0, $pos), "\n") + 1 : 0;
            report('WARN', $rel($file), "raw \$_{$hit[2]} in output (line {$line})");
        }
    }
}

/* ------------------------------------------------------------------ */
@unlink($tmp);

$errors = array_filter($problems, static fn($p) => $p[0] === 'ERROR');
$warns  = array_filter($problems, static fn($p) => $p[0] === 'WARN');

foreach ($problems as [$level, $file, $msg]) {
    printf("%-5s %-38s %s\n", $level, $file, $msg);
}

printf(
    "\n%d file(s) checked. %d error(s), %d warning(s).\n",
    count($files),
    count($errors),
    count($warns)
);

exit(count($errors) > 0 ? 1 : 0);
