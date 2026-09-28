<?php
if (!defined('BOOTSTRAP_LOADED')) {
    require_once __DIR__ . '/bootstrap.php';
}

$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
$app_root = defined('APP_ROOT') ? APP_ROOT : '';

// Helper function to check active nav state
function is_nav_active($page, $dir = null) {
    global $current_page, $current_dir;
    if ($dir !== null && $current_dir !== $dir) {
        return '';
    }
    return ($current_page === $page) ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interactive e-Learning & Peer Review</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --brand-primary: #4f46e5;
            --brand-primary-hover: #4338ca;
            --brand-secondary: #06b6d4;
            --brand-dark: #0f172a;
            --sidebar-bg: #ffffff;
            --sidebar-border: #e2e8f0;
            --body-bg: #f8fafc;
            --card-border: #e2e8f0;
            --text-main: #1e293b;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--body-bg);
            color: var(--text-main);
            min-height: 100vh;
        }

        /* Top Navbar */
        .main-navbar {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 0.75rem 1rem;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        /* Sidebar Navigation */
        .sidebar {
            min-height: calc(100vh - 60px);
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--sidebar-border);
            padding: 1.25rem 0.75rem;
        }
        .sidebar .nav-link {
            color: #64748b;
            font-weight: 500;
            font-size: 0.9rem;
            padding: 0.65rem 0.9rem;
            border-radius: 8px;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.15s ease-in-out;
        }
        .sidebar .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
            color: #94a3b8;
            transition: color 0.15s ease-in-out;
        }
        .sidebar .nav-link:hover {
            color: var(--brand-primary);
            background-color: #f1f5f9;
        }
        .sidebar .nav-link:hover i {
            color: var(--brand-primary);
        }
        .sidebar .nav-link.active {
            color: #ffffff !important;
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%) !important;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .sidebar .nav-link.active i {
            color: #ffffff !important;
        }

        /* Cards & Content */
        .card {
            border: 1px solid var(--card-border);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .course-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
        }
        .badge {
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        /* Dark Mode Styles */
        body.dark-mode {
            --body-bg: #090d16;
            --text-main: #f1f5f9;
            --sidebar-bg: #0f172a;
            --sidebar-border: #1e293b;
            --card-border: #1e293b;
            background-color: var(--body-bg);
            color: var(--text-main);
        }
        body.dark-mode .card {
            background-color: #111827;
            border-color: #1f2937;
            color: #f3f4f6;
        }
        body.dark-mode .card-header {
            background-color: #111827 !important;
            border-color: #1f2937 !important;
            color: #f3f4f6 !important;
        }
        body.dark-mode .table {
            color: #f1f5f9;
            border-color: #1e293b;
        }
        body.dark-mode .table-light {
            background-color: #1e293b !important;
            color: #f1f5f9 !important;
        }
        body.dark-mode .border-bottom,
        body.dark-mode .border-top,
        body.dark-mode .border {
            border-color: #1e293b !important;
        }
        body.dark-mode .bg-light {
            background-color: #1e293b !important;
            color: #f1f5f9 !important;
        }
        body.dark-mode .text-dark {
            color: #f1f5f9 !important;
        }
    </style>
</head>
<body>

    <!-- Main Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark main-navbar sticky-top">
        <div class="container-fluid px-3">
            <a class="navbar-brand text-white" href="<?php echo htmlspecialchars($app_root . '/dashboard.php'); ?>">
                <span class="p-2 rounded-3 bg-white text-primary d-inline-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                    <i class="fas fa-graduation-cap"></i>
                </span>
                <span>e-Learning System</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarContent">
                <ul class="navbar-nav ms-auto align-items-center gap-2">
                    <li class="nav-item">
                        <button class="btn btn-sm btn-outline-light px-3 py-1" id="darkModeToggle">
                            <i class="fas fa-moon me-1"></i> Dark Mode
                        </button>
                    </li>

                    <?php if(isset($_SESSION['user_id'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-white d-flex align-items-center gap-2" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <span class="badge bg-primary rounded-pill px-2 py-1">
                                <?php echo ucfirst($_SESSION['role']); ?>
                            </span>
                            <span class="fw-semibold">
                                <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                            <li>
                                <a class="dropdown-item" href="<?php echo htmlspecialchars($app_root . '/profile.php'); ?>">
                                    <i class="fas fa-user-circle me-2 text-muted"></i> My Profile
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="<?php echo htmlspecialchars($app_root . '/logout.php'); ?>">
                                    <i class="fas fa-sign-out-alt me-2"></i> Log Out
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <li class="nav-item">
                        <a class="btn btn-sm btn-light px-3" href="<?php echo htmlspecialchars($app_root . '/login.php'); ?>">
                            <i class="fas fa-sign-in-alt me-1"></i> Sign In
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <?php if(isset($_SESSION['user_id'])): ?>
            <!-- Sidebar Navigation -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar">
                <div class="position-sticky">
                    <ul class="nav flex-column">

                        <?php if($_SESSION['role'] === 'student'): ?>
                            <!-- Student Links -->
                            <li class="nav-item">
                                <a class="nav-link <?php echo is_nav_active('dashboard.php', 'student'); ?>" 
                                   href="<?php echo htmlspecialchars($app_root . '/student/dashboard.php'); ?>">
                                    <i class="fas fa-chart-pie"></i>
                                    <span>Dashboard</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo is_nav_active('courses.php', 'student'); ?>" 
                                   href="<?php echo htmlspecialchars($app_root . '/student/courses.php'); ?>">
                                    <i class="fas fa-book-reader"></i>
                                    <span>My Courses & Catalog</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo is_nav_active('assignments.php', 'student'); ?>" 
                                   href="<?php echo htmlspecialchars($app_root . '/student/assignments.php'); ?>">
                                    <i class="fas fa-tasks"></i>
                                    <span>My Assignments</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo is_nav_active('peer_reviews.php', 'student'); ?>" 
                                   href="<?php echo htmlspecialchars($app_root . '/student/peer_reviews.php'); ?>">
                                    <i class="fas fa-user-check"></i>
                                    <span>Peer Reviews</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo is_nav_active('forums.php', 'student'); ?>" 
                                   href="<?php echo htmlspecialchars($app_root . '/student/forums.php'); ?>">
                                    <i class="fas fa-comments"></i>
                                    <span>Discussion Forums</span>
                                </a>
                            </li>

                        <?php elseif($_SESSION['role'] === 'instructor'): ?>
                            <!-- Instructor Links -->
                            <li class="nav-item">
                                <a class="nav-link <?php echo is_nav_active('dashboard.php', 'instructor'); ?>" 
                                   href="<?php echo htmlspecialchars($app_root . '/instructor/dashboard.php'); ?>">
                                    <i class="fas fa-chart-line"></i>
                                    <span>Dashboard</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo is_nav_active('courses.php', 'instructor') || is_nav_active('course_manage.php', 'instructor') || is_nav_active('course_edit.php', 'instructor'); ?>" 
                                   href="<?php echo htmlspecialchars($app_root . '/instructor/courses.php'); ?>">
                                    <i class="fas fa-chalkboard"></i>
                                    <span>My Courses</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo is_nav_active('assignments.php', 'instructor') || is_nav_active('assignment_view.php', 'instructor') || is_nav_active('assignment_submissions.php', 'instructor'); ?>" 
                                   href="<?php echo htmlspecialchars($app_root . '/instructor/assignments.php'); ?>">
                                    <i class="fas fa-clipboard-list"></i>
                                    <span>Assignments & Reviews</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo is_nav_active('students.php', 'instructor') || is_nav_active('student_progress.php', 'instructor'); ?>" 
                                   href="<?php echo htmlspecialchars($app_root . '/instructor/students.php'); ?>">
                                    <i class="fas fa-user-graduate"></i>
                                    <span>Student Roster</span>
                                </a>
                            </li>

                        <?php endif; ?>

                        <li class="nav-item mt-3 pt-3 border-top">
                            <a class="nav-link <?php echo is_nav_active('profile.php'); ?>" 
                               href="<?php echo htmlspecialchars($app_root . '/profile.php'); ?>">
                                <i class="fas fa-id-card"></i>
                                <span>My Profile</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-danger" href="<?php echo htmlspecialchars($app_root . '/logout.php'); ?>">
                                <i class="fas fa-power-off text-danger"></i>
                                <span>Sign Out</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
            <?php endif; ?>
            
            <!-- Main Content Area -->
            <main class="<?php echo isset($_SESSION['user_id']) ? 'col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4' : 'col-12 py-4'; ?>">