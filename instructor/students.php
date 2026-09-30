<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

// Get database connection - use existing one from bootstrap
$conn = $db->getConnection();

// Get instructor's courses
$courses = $functions->getCourses($_SESSION['user_id']);

// Ids of the courses this instructor actually owns. Every course_id coming
// from the request is checked against this list, otherwise any teacher could
// pass another teacher's course_id and read their student roster.
$my_course_ids = array_map('intval', array_column($courses, 'course_id'));

$selected_course = intval($_GET['course_id'] ?? 0);
$course_students = [];

// Drain the session error into the local $error the page already renders.
// These four messages were written to $_SESSION['error'] and never read back,
// so an access denial or invalid status produced no visible feedback at all.
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

if($selected_course && !in_array($selected_course, $my_course_ids, true)) {
    $error = "You do not have access to that course.";
    $selected_course = 0;
}

if($selected_course) {
    $course_students = $functions->getCourseStudents($selected_course);
}

// Handle enrollment status update
if($_POST && isset($_POST['update_status'])) {
    verify_csrf();
    $user_id = intval($_POST['user_id'] ?? 0);
    $course_id = intval($_POST['course_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    $allowed_statuses = ['pending', 'approved', 'rejected', 'completed'];

    if(!in_array($course_id, $my_course_ids, true)) {
        $error = "You can only manage students in your own courses.";
    } elseif(!in_array($status, $allowed_statuses, true)) {
        $error = "Invalid enrollment status.";
    } elseif($user_id <= 0) {
        $error = "Invalid student.";
    } else {
        if($functions->updateEnrollmentStatus($user_id, $course_id, $status)) {
            $success = "Enrollment status updated successfully!";
            // Refresh the roster. Use the course that was actually updated,
            // not the GET value, so the page always shows the rows it changed.
            $selected_course = $course_id;
            $course_students = $functions->getCourseStudents($selected_course);
        } else {
            $error = "Failed to update enrollment status.";
        }
    }
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Student Management</h1>
</div>

<?php if(isset($success)): ?>
<div class="alert alert-success"><?php echo e($success); ?></div>
<?php endif; ?>

<?php if(isset($error)): ?>
<div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-3">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">My Courses</h5>
            </div>
            <div class="card-body">
                <?php if(count($courses) > 0): ?>
                    <div class="list-group">
                        <a href="students.php" class="list-group-item list-group-item-action <?php echo !$selected_course ? 'active' : ''; ?>">
                            Select a Course
                        </a>
                        <?php foreach($courses as $course): ?>
                        <a href="students.php?course_id=<?php echo (int)$course['course_id']; ?>" 
                           class="list-group-item list-group-item-action <?php echo $selected_course === (int)$course['course_id'] ? 'active' : ''; ?>">
                            <?php echo e($course['title']); ?>
                            <small class="d-block text-muted"><?php echo e($course['course_code']); ?></small>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">You haven't created any courses yet.</p>
                    <a href="courses.php" class="btn btn-primary btn-sm">Create Course</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <?php 
                    if($selected_course) {
                        $current_course = array_filter($courses, function($c) use ($selected_course) {
                            return $c['course_id'] == $selected_course;
                        });
                        $current_course = reset($current_course);
                        echo "Students - " . e($current_course['title'] ?? 'Unknown Course');
                    } else {
                        echo "Students";
                    }
                    ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if($selected_course): ?>
                    <?php if(count($course_students) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th>Enrollment Date</th>
                                        <th>Status</th>
                                        <th>Grade</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($course_students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><?php echo htmlspecialchars($student['username']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($student['enrolled_at'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                switch($student['enrollment_status']) {
                                                    case 'approved': echo 'success'; break;
                                                    case 'pending': echo 'warning'; break;
                                                    case 'rejected': echo 'danger'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst($student['enrollment_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if($student['grade'] !== null && $student['grade'] !== ''): ?>
                                                <?php echo e(ui_num($student['grade'], 2)); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php // Progress was previously unreachable: student_progress.php
                                                  // existed but nothing linked to it. ?>
                                            <a href="student_progress.php?course_id=<?php echo (int)$selected_course; ?>&amp;student_id=<?php echo (int)$student['user_id']; ?>"
                                               class="btn btn-outline-primary btn-sm mb-2">
                                                <i class="fas fa-chart-line"></i> Progress
                                            </a>
                                            <form method="POST" class="d-block" data-auto-submit>
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="user_id" value="<?php echo (int)$student['user_id']; ?>">
                                                <input type="hidden" name="course_id" value="<?php echo (int)$selected_course; ?>">
                                                <label class="visually-hidden" for="status<?php echo (int)$student['user_id']; ?>">
                                                    Enrollment status for <?php echo e($student['first_name'] . ' ' . $student['last_name']); ?>
                                                </label>
                                                <select name="status" id="status<?php echo (int)$student['user_id']; ?>" class="form-select form-select-sm" data-auto-submit>
                                                    <option value="pending" <?php echo $student['enrollment_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="approved" <?php echo $student['enrollment_status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                                    <option value="rejected" <?php echo $student['enrollment_status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                    <option value="completed" <?php echo $student['enrollment_status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                </select>
                                                <?php // A programmatic form.submit() does not send a
                                                      // submit button's name, so flag the action here. ?>
                                                <input type="hidden" name="update_status" value="1">
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No students enrolled in this course yet.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted">Please select a course to view its students.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
