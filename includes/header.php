<?php
/**
 * Application shell: <head>, topbar, sidebar and the opening of <main>.
 *
 * Every authenticated page includes this at the top and includes/footer.php
 * at the bottom. Both are layout-only: no page should print a <div> or
 * <section> that this file does not close, because the closing tags live in
 * footer.php.
 */

if (!defined('BOOTSTRAP_LOADED')) {
    require_once __DIR__ . '/bootstrap.php';
}

$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));
$app_root     = defined('APP_ROOT') ? APP_ROOT : '';

// basename(dirname()) yields the physical folder name, which is only a useful
// role segment when the app runs in a subdirectory. Derive the path relative
// to the app root instead so the current section is always '', 'student' or
// 'instructor' regardless of where the project is deployed.
$script_path = str_replace('\\', '/', $_SERVER['PHP_SELF']);
if ($app_root !== '' && strpos($script_path, $app_root) === 0) {
    $script_path = substr($script_path, strlen($app_root));
}
$script_path = ltrim($script_path, '/');
$current_page = basename($script_path);
$current_dir  = strpos($script_path, '/') !== false
    ? substr($script_path, 0, strpos($script_path, '/'))
    : '';

$current_user = $auth->isLoggedIn() ? $_SESSION : [];
$current_role = $current_user['role'] ?? null;

/**
 * The navigation tree for a role.
 *
 * Each item lists the sibling pages that should also light it up, so a detail
 * page such as course_view.php still highlights "My Courses" instead of
 * leaving the sidebar with nothing selected.
 */
function app_nav_items($role) {
    if ($role === 'instructor') {
        return [
            [
                'label' => 'Dashboard',
                'icon'  => 'fa-chart-line',
                'url'   => 'instructor/dashboard.php',
                'match' => ['dashboard'],
            ],
            [
                'label' => 'My Courses',
                'icon'  => 'fa-chalkboard-user',
                'url'   => 'instructor/courses.php',
                'match' => ['courses', 'course_manage', 'course_edit'],
            ],
            [
                'label' => 'Assignments',
                'icon'  => 'fa-clipboard-check',
                'url'   => 'instructor/assignments.php',
                'match' => ['assignments', 'assignment_view', 'assignment_submissions', 'rubrics', 'submission_view'],
            ],
            [
                'label' => 'Students',
                'icon'  => 'fa-user-group',
                'url'   => 'instructor/students.php',
                'match' => ['students', 'student_progress'],
            ],
        ];
    }

    return [
        [
            'label' => 'Dashboard',
            'icon'  => 'fa-chart-pie',
            'url'   => 'student/dashboard.php',
            'match' => ['dashboard'],
        ],
        [
            'label' => 'My Courses',
            'icon'  => 'fa-book-reader',
            'url'   => 'student/courses.php',
            'match' => ['courses', 'course_view'],
        ],
        [
            'label' => 'Assignments',
            'icon'  => 'fa-list-check',
            'url'   => 'student/assignments.php',
            'match' => ['assignments', 'assignment_view', 'submission_preview'],
        ],
        [
            'label' => 'Peer Reviews',
            'icon'  => 'fa-user-check',
            'url'   => 'student/peer_reviews.php',
            'match' => ['peer_reviews', 'review_view', 'review_complete'],
        ],
        [
            'label' => 'Forums',
            'icon'  => 'fa-comments',
            'url'   => 'student/forums.php',
            'match' => ['forums', 'forum_view', 'post_view'],
        ],
    ];
}

/**
 * True when $item should be rendered as the current page.
 */
function app_nav_is_active(array $item, $current_dir, $current_page) {
    // Student pages live in student/, instructor pages in instructor/, and the
    // two dashboards share a filename. The directory disambiguates them.
    $item_dir = strpos($item['url'], 'instructor/') === 0 ? 'instructor' : 'student';
    if ($item_dir !== $current_dir) {
        return false;
    }
    return in_array($current_page, $item['match'], true);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff">
    <title><?php echo e($page_title ?? 'Dashboard'); ?> &middot; e-Learning System</title>

    <!-- Set the theme before first paint so a dark-mode visitor never sees a
         white flash. Kept inline and tiny on purpose. -->
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('ils-theme');
                var dark = stored
                    ? stored === 'dark'
                    : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
            } catch (e) { /* default to light */ }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-4164ca6728e93c48c84afe5669153d385791a6893a61cb676260ebe69365c93d9b4635e1d093218d8ea15be00b130207"
          crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
          integrity="sha384-3cf21910660cd6ff33a793f2ed48c56fbf52e7c51ea822fda5856754f511284aaf8a83d139a54024a28bcef1f6ac39c8"
          crossorigin="anonymous" referrerpolicy="no-referrer">

    <!-- Loaded last so every rule here can override Bootstrap. -->
    <link rel="stylesheet" href="<?php echo e_attr(asset_url('css/app.css')); ?>">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>

<header class="app-topbar">
    <button type="button" class="icon-btn d-lg-none" data-sidebar-toggle
            aria-controls="appSidebar" aria-label="Open navigation menu">
        <i class="fas fa-bars" aria-hidden="true"></i>
    </button>

    <a class="app-brand" href="<?php echo e_attr(app_url('dashboard.php')); ?>">
        <span class="app-brand__mark"><i class="fas fa-graduation-cap" aria-hidden="true"></i></span>
        <span class="app-brand__text">
            e-Learning
            <small>Peer Review Platform</small>
        </span>
    </a>

    <span class="topbar-spacer"></span>

    <button type="button" class="icon-btn" data-theme-toggle aria-label="Switch to dark mode" aria-pressed="false">
        <i class="fas fa-moon" aria-hidden="true"></i>
    </button>

    <?php if (!empty($current_user['user_id'])): ?>
        <div class="dropdown">
            <button class="user-chip dropdown-toggle" type="button" data-bs-toggle="dropdown"
                    aria-expanded="false" id="userMenu">
                <?php echo ui_avatar($current_user['first_name'] ?? '', $current_user['last_name'] ?? '', 'sm'); ?>
                <span class="user-chip__meta">
                    <span class="user-chip__name"><?php echo e(trim(($current_user['first_name'] ?? '') . ' ' . ($current_user['last_name'] ?? ''))); ?></span>
                    <span class="role"><?php echo e(ucfirst((string) $current_role)); ?></span>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                <li class="px-2 py-1">
                    <div class="fw-semibold text-strong" style="font-size:.8125rem"><?php echo e($current_user['email'] ?? ''); ?></div>
                    <div class="text-subtle" style="font-size:.6875rem">@<?php echo e($current_user['username'] ?? ''); ?></div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="<?php echo e_attr(app_url('profile.php')); ?>">
                        <i class="fas fa-user" aria-hidden="true"></i> My profile
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="<?php echo e_attr(app_url('dashboard.php')); ?>">
                        <i class="fas fa-gauge-high" aria-hidden="true"></i> Dashboard
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item text-danger" href="<?php echo e_attr(app_url('logout.php')); ?>">
                        <i class="fas fa-right-from-bracket" aria-hidden="true"></i> Sign out
                    </a>
                </li>
            </ul>
        </div>
    <?php else: ?>
        <a class="btn btn-primary btn-sm" href="<?php echo e_attr(app_url('login.php')); ?>">Sign in</a>
    <?php endif; ?>
</header>

<div class="app-layout">
    <button type="button" class="sidebar-backdrop" id="sidebarBackdrop" tabindex="-1" aria-hidden="true"></button>

    <?php if (!empty($current_user['user_id']) && $current_role): ?>
        <aside class="app-sidebar offcanvas offcanvas-start" tabindex="-1" id="appSidebar"
               aria-labelledby="sidebarLabel">
            <div class="offcanvas-header px-3">
                <h2 class="offcanvas-title h6 mb-0" id="sidebarLabel">Navigation</h2>
                <button type="button" class="btn-close sidebar-close" data-sidebar-close aria-label="Close menu"></button>
            </div>

            <nav class="sidebar-nav" aria-label="Main">
                <div class="sidebar-section"><?php echo e($current_role === 'instructor' ? 'Teaching' : 'Learning'); ?></div>

                <?php foreach (app_nav_items($current_role) as $item): ?>
                    <a class="sidebar-link<?php echo app_nav_is_active($item, $current_dir, $current_page) ? ' is-active' : ''; ?>"
                       href="<?php echo e_attr(app_url($item['url'])); ?>"
                       <?php echo app_nav_is_active($item, $current_dir, $current_page) ? ' aria-current="page"' : ''; ?>>
                        <i class="fas <?php echo e_attr($item['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo e($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>

                <div class="sidebar-section">Account</div>
                <a class="sidebar-link<?php echo ($current_dir === '' && $current_page === 'profile.php') ? ' is-active' : ''; ?>"
                   href="<?php echo e_attr(app_url('profile.php')); ?>">
                    <i class="fas fa-id-card" aria-hidden="true"></i>
                    <span>My profile</span>
                </a>
                <a class="sidebar-link is-danger" href="<?php echo e_attr(app_url('logout.php')); ?>">
                    <i class="fas fa-power-off" aria-hidden="true"></i>
                    <span>Sign out</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <?php if ($current_role === 'instructor'): ?>
                    <i class="fas fa-chalkboard-user me-1" aria-hidden="true"></i> Instructor workspace
                <?php else: ?>
                    <i class="fas fa-user-graduate me-1" aria-hidden="true"></i> Student workspace
                <?php endif; ?>
            </div>
        </aside>
    <?php endif; ?>

    <main class="app-content" id="main-content" tabindex="-1">
        <?php flash_render(); ?>
