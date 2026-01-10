<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

$conn = $db->getConnection();

// Handle course enrollment
if ($_POST && isset($_POST['enroll_course'])) {
    $course_id = $_POST['course_id'];

    if (empty($course_id)) {
        $_SESSION['error'] = "Invalid course selection.";
    } else {
        // Check if already enrolled
        $check_stmt = $conn->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?");
        $check_stmt->execute([$_SESSION['user_id'], $course_id]);

        if ($check_stmt->rowCount() > 0) {
            $_SESSION['error'] = "You are already enrolled in this course.";
        } else {
            // Enroll the student
            $stmt = $conn->prepare("INSERT INTO enrollments (user_id, course_id, enrollment_status) VALUES (?, ?, 'pending')");
            if ($stmt->execute([$_SESSION['user_id'], $course_id])) {
                $_SESSION['success'] = "Successfully enrolled in course! Waiting for instructor approval.";
                header("Location: courses.php");
                exit();
            } else {
                $_SESSION['error'] = "Failed to enroll in course. Please try again.";
            }
        }
    }
}

// Get available courses
$available_courses = $functions->getAvailableCourses($_SESSION['user_id']);

// Get enrolled courses
$enrolled_courses = $functions->getEnrolledCourses($_SESSION['user_id']);

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success']);
unset($_SESSION['error']);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Course Catalog</h1>
</div>

<?php if(!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?php echo $success; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if(!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Available Courses Section -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-book"></i> Available Courses
            <span class="badge bg-primary"><?php echo count($available_courses); ?> courses</span>
        </h5>
    </div>
    <div class="card-body">
        <?php if(count($available_courses) > 0): ?>
            <div class="row">
                <?php foreach($available_courses as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($course['title']); ?></h5>
                            <p class="card-text text-muted">
                                <small>Code: <?php echo htmlspecialchars($course['course_code']); ?></small>
                            </p>
                            <p class="card-text">
                                <?php 
                                $description = $course['description'] ?? 'No description available.';
                                echo substr(htmlspecialchars($description), 0, 100) . (strlen($description) > 100 ? '...' : '');
                                ?>
                            </p>
                            <p class="card-text">
                                <small class="text-muted">
                                    <i class="fas fa-chalkboard-teacher"></i> 
                                    Instructor: <?php echo htmlspecialchars($course['first_name'] . ' ' . $course['last_name']); ?>
                                </small>
                            </p>
                        </div>
                        <div class="card-footer">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="course_id" value="<?php echo $course['course_id']; ?>">
                                <button type="submit" name="enroll_course" class="btn btn-primary btn-sm">
                                    <i class="fas fa-user-plus"></i> Enroll Now
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No Available Courses</h5>
                <p class="text-muted">All published courses are either full or you're already enrolled.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Enrolled Courses Section -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-user-graduate"></i> My Enrolled Courses
            <span class="badge bg-success"><?php echo count($enrolled_courses); ?> courses</span>
        </h5>
    </div>
    <div class="card-body">
        <?php if(count($enrolled_courses) > 0): ?>
            <div class="row">
                <?php foreach($enrolled_courses as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($course['title']); ?></h5>
                            <p class="card-text text-muted">
                                <small>Code: <?php echo htmlspecialchars($course['course_code']); ?></small>
                            </p>
                            <p class="card-text">
                                <span class="badge bg-<?php 
                                    switch($course['enrollment_status']) {
                                        case 'approved': echo 'success'; break;
                                        case 'pending': echo 'warning'; break;
                                        case 'rejected': echo 'danger'; break;
                                        case 'completed': echo 'info'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo ucfirst($course['enrollment_status']); ?>
                                </span>
                            </p>
                            <p class="card-text">
                                <small class="text-muted">
                                    Enrolled: <?php echo date('M j, Y', strtotime($course['enrolled_at'])); ?>
                                </small>
                            </p>
                        </div>
                        <div class="card-footer">
                            <?php if($course['enrollment_status'] == 'approved'): ?>
                                <a href="course_view.php?id=<?php echo $course['course_id']; ?>" class="btn btn-success btn-sm">
                                    <i class="fas fa-play-circle"></i> Enter Course
                                </a>
                            <?php elseif($course['enrollment_status'] == 'pending'): ?>
                                <span class="text-warning">
                                    <i class="fas fa-clock"></i> Waiting for approval
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">Not Enrolled in Any Courses</h5>
                <p class="text-muted">Browse available courses above and enroll to get started!</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>