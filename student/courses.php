<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

$conn = $db->getConnection();

// Handle course enrollment
if ($_POST && isset($_POST['enroll_course'])) {
    verify_csrf();
    $course_id = intval($_POST['course_id'] ?? 0);

    if ($course_id <= 0) {
        $_SESSION['error'] = "Invalid course selection.";
    } else {
        // Only published courses can be enrolled in.
        $course_stmt = $conn->prepare("SELECT course_id FROM courses
                                       WHERE course_id = ? AND is_published = 1");
        $course_stmt->execute([$course_id]);
        $course_exists = $course_stmt->rowCount() > 0;

        // Check if already enrolled
        $check_stmt = $conn->prepare("SELECT enrollment_id, enrollment_status FROM enrollments
                                      WHERE user_id = ? AND course_id = ?");
        $check_stmt->execute([$_SESSION['user_id'], $course_id]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course_exists) {
            $_SESSION['error'] = "That course is not available.";
        } elseif ($existing && in_array($existing['enrollment_status'], ['pending', 'approved', 'completed'], true)) {
            $_SESSION['error'] = "You are already enrolled in this course.";
        } elseif ($existing) {
            // Previously rejected: let the student request access again.
            $stmt = $conn->prepare("UPDATE enrollments
                                    SET enrollment_status = 'pending', enrolled_at = NOW()
                                    WHERE user_id = ? AND course_id = ?");
            if ($stmt->execute([$_SESSION['user_id'], $course_id])) {
                $_SESSION['success'] = "Your enrollment request was sent to the instructor.";
                header("Location: courses.php");
                exit();
            }
            $_SESSION['error'] = "Failed to enroll in course. Please try again.";
        } else {
            // Enroll as "pending": the instructor must approve before any
            // course content becomes visible to the student.
            $stmt = $conn->prepare("INSERT INTO enrollments (user_id, course_id, enrollment_status)
                                    VALUES (?, ?, 'pending')");
            if ($stmt->execute([$_SESSION['user_id'], $course_id])) {
                $_SESSION['success'] = "Enrollment requested! The instructor must approve your access before you can view the course.";
                header("Location: courses.php");
                exit();
            } else {
                $_SESSION['error'] = "Failed to enroll in course. Please try again.";
            }
        }
    }
}

// Get enrolled courses
$enrolled_courses = $functions->getEnrolledCourses($_SESSION['user_id']);

// Get available courses
$available_courses = $functions->getAvailableCourses($_SESSION['user_id']);

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Courses & Catalog</h1>
        <p class="text-muted mb-0">Access your active courses or explore new learning topics</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <ul class="nav nav-pills" id="courseTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="enrolled-tab" data-bs-toggle="tab" data-bs-target="#enrolled-content" type="button">
                    <i class="fas fa-graduation-cap me-1"></i> My Enrolled (<?php echo count($enrolled_courses); ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="catalog-tab" data-bs-toggle="tab" data-bs-target="#catalog-content" type="button">
                    <i class="fas fa-search me-1"></i> Course Catalog (<?php echo count($available_courses); ?>)
                </button>
            </li>
        </ul>
    </div>
</div>

<?php if(!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-1"></i> <?php echo e($success); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if(!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-1"></i> <?php echo e($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="tab-content" id="courseTabContent">

    <!-- Tab 1: Enrolled Courses -->
    <div class="tab-pane fade show active" id="enrolled-content" role="tabpanel">
        <?php if(count($enrolled_courses) > 0): ?>
            <div class="row">
                <?php foreach($enrolled_courses as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card h-100 shadow-sm border-0">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-light text-primary border"><?php echo htmlspecialchars($course['course_code']); ?></span>
                                <span class="badge bg-<?php 
                                    switch($course['enrollment_status']) {
                                        case 'approved': echo 'success'; break;
                                        case 'pending': echo 'warning text-dark'; break;
                                        case 'rejected': echo 'danger'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo ucfirst($course['enrollment_status']); ?>
                                </span>
                            </div>

                            <h5 class="card-title fw-bold text-dark mb-2"><?php echo htmlspecialchars($course['title']); ?></h5>
                            <p class="card-text text-muted small flex-grow-1">
                                <?php 
                                $description = $course['description'] ?? 'No description available.';
                                echo substr(htmlspecialchars($description), 0, 110) . (strlen($description) > 110 ? '...' : '');
                                ?>
                            </p>

                            <div class="border-top pt-3 mt-3">
                                <small class="text-muted d-block mb-1">
                                    <i class="fas fa-chalkboard-teacher me-1"></i> Instructor: <?php echo htmlspecialchars($course['first_name'] . ' ' . $course['last_name']); ?>
                                </small>
                                <small class="text-muted d-block">
                                    <i class="fas fa-calendar-alt me-1"></i> Enrolled: <?php echo date('M j, Y', strtotime($course['enrolled_at'])); ?>
                                </small>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-top-0 p-4 pt-0">
                            <?php if($course['enrollment_status'] == 'approved'): ?>
                                <a href="course_view.php?id=<?php echo $course['course_id']; ?>" class="btn btn-primary w-100 fw-bold">
                                    <i class="fas fa-arrow-right me-1"></i> Enter Course
                                </a>
                            <?php else: ?>
                                <button class="btn btn-outline-warning text-dark w-100" disabled>
                                    <i class="fas fa-clock me-1"></i> Pending Approval
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-5 bg-white rounded-3 shadow-sm p-4">
                <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                <h5 class="fw-bold">You are not enrolled in any courses yet</h5>
                <p class="text-muted">Browse the course catalog to discover available topics and start learning.</p>
                <button class="btn btn-primary" onclick="document.getElementById('catalog-tab').click();">
                    <i class="fas fa-search me-1"></i> Browse Available Courses
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab 2: Available Course Catalog -->
    <div class="tab-pane fade" id="catalog-content" role="tabpanel">
        <?php if(count($available_courses) > 0): ?>
            <div class="row">
                <?php foreach($available_courses as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card h-100 shadow-sm border-0">
                        <div class="card-body p-4 d-flex flex-column">
                            <span class="badge bg-light text-secondary border align-self-start mb-2"><?php echo htmlspecialchars($course['course_code']); ?></span>
                            <h5 class="card-title fw-bold text-dark mb-2"><?php echo htmlspecialchars($course['title']); ?></h5>
                            <p class="card-text text-muted small flex-grow-1">
                                <?php 
                                $description = $course['description'] ?? 'No description available.';
                                echo substr(htmlspecialchars($description), 0, 120) . (strlen($description) > 120 ? '...' : '');
                                ?>
                            </p>

                            <div class="border-top pt-3 mt-3">
                                <small class="text-muted d-block mb-1">
                                    <i class="fas fa-chalkboard-teacher me-1"></i> Instructor: <?php echo htmlspecialchars($course['first_name'] . ' ' . $course['last_name']); ?>
                                </small>
                                <small class="text-muted d-block">
                                    <i class="fas fa-users me-1"></i> Capacity: <?php echo htmlspecialchars($course['max_students']); ?> students
                                </small>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-top-0 p-4 pt-0">
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="course_id" value="<?php echo $course['course_id']; ?>">
                                <button type="submit" name="enroll_course" class="btn btn-outline-primary w-100 fw-bold">
                                    <i class="fas fa-user-plus me-1"></i> Enroll in Course
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-5 bg-white rounded-3 shadow-sm p-4">
                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                <h5 class="fw-bold">All Available Courses Enrolled</h5>
                <p class="text-muted">You are currently enrolled in all published courses available on the platform.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once '../includes/footer.php'; ?>
