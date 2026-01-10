<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interactive e-Learning System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .navbar-brand { font-weight: bold; }
        .course-card { transition: transform 0.2s; }
        .course-card:hover { transform: translateY(-5px); }
        .sidebar { min-height: calc(100vh - 56px); }
        
        .dark-mode {
            background-color: #1a1a1a;
            color: #ffffff;
        }
        .dark-mode .card {
            background-color: #2d3748;
            color: #ffffff;
            border-color: #4a5568;
        }
        .dark-mode .sidebar {
            background-color: #2d3748 !important;
        }
        .dark-mode .nav-link {
            color: #e2e8f0 !important;
        }
        .dark-mode .nav-link.active {
            background-color: #4a5568;
        }
        .dark-mode .table {
            color: #ffffff;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="/Interactive-e-learning-system/dashboard.php">
                <i class="fas fa-graduation-cap"></i> e-Learning System
            </a>
            
            <?php if(isset($_SESSION['user_id'])): ?>
            <div class="navbar-nav ms-auto">
                <button class="btn btn-outline-light me-2" id="darkModeToggle">
                    <i class="fas fa-moon"></i> Dark Mode
                </button>
                <span class="navbar-text me-3">
                    Welcome, <?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?>
                    (<?php echo ucfirst($_SESSION['role']); ?>)
                </span>
                <a class="nav-link" href="/Interactive-e-learning-system/logout.php">Logout</a>
            </div>
            <?php endif; ?>
        </div>
    </nav>
    
    <div class="container-fluid">
        <div class="row">
            <?php if(isset($_SESSION['user_id'])): ?>
            <nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="/Interactive-e-learning-system/dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        
                        <?php if($_SESSION['role'] == 'student'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/student/dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Student Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/student/courses.php">
                                <i class="fas fa-book"></i> My Courses
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/student/assignments.php">
                                <i class="fas fa-tasks"></i> Assignments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/student/forums.php">
                                <i class="fas fa-comments"></i> Discussion Forums
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/student/peer_reviews.php">
                                <i class="fas fa-comments"></i> Peer Reviews
                            </a>
                        </li>

                        <?php elseif($_SESSION['role'] == 'instructor'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/instructor/dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Instructor Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/instructor/courses.php">
                                <i class="fas fa-book"></i> My Courses
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/instructor/students.php">
                                <i class="fas fa-users"></i> Students
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/instructor/assignments.php">
                                <i class="fas fa-tasks"></i> Assignments
                            </a>
                        </li>

                        <?php elseif($_SESSION['role'] == 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/admin/users.php">
                                <i class="fas fa-users"></i> User Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/admin/courses.php">
                                <i class="fas fa-book"></i> Course Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/admin/analytics.php">
                                <i class="fas fa-chart-bar"></i> Analytics
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <li class="nav-item">
                            <a class="nav-link" href="/Interactive-e-learning-system/profile.php">
                                <i class="fas fa-user"></i> Profile
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
            <?php endif; ?>
            
            <main class="<?php echo isset($_SESSION['user_id']) ? 'col-md-9 ms-sm-auto col-lg-10 px-md-4' : 'col-12'; ?>">