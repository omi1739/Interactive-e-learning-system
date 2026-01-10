<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

// Get database connection - use existing one from bootstrap
$conn = $db->getConnection();

// Get instructor's courses
$courses = $functions->getCourses($_SESSION['user_id']);

$selected_course = $_GET['course_id'] ?? null;
$course_students = [];

if($selected_course) {
    $course_students = $functions->getCourseStudents($selected_course);
}

// Handle enrollment status update
if($_POST && isset($_POST['update_status'])) {
    $user_id = $_POST['user_id'];
    $course_id = $_POST['course_id'];
    $status = $_POST['status'];
    
    if($functions->updateEnrollmentStatus($user_id, $course_id, $status)) {
        $success = "Enrollment status updated successfully!";
        // Refresh students list
        $course_students = $functions->getCourseStudents($selected_course);
    } else {
        $error = "Failed to update enrollment status.";
    }
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Student Management</h1>
</div>

<?php if(isset($success)): ?>
<div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if(isset($error)): ?>
<div class="alert alert-danger"><?php echo $error; ?></div>
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
                        <a href="students.php?course_id=<?php echo $course['course_id']; ?>" 
                           class="list-group-item list-group-item-action <?php echo $selected_course == $course['course_id'] ? 'active' : ''; ?>">
                            <?php echo $course['title']; ?>
                            <small class="d-block text-muted"><?php echo $course['course_code']; ?></small>
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
                        echo "Students - " . ($current_course['title'] ?? 'Unknown Course');
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
                                            <?php echo $student['grade'] !== null ? $student['grade'] : '-'; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="user_id" value="<?php echo $student['user_id']; ?>">
                                                <input type="hidden" name="course_id" value="<?php echo $selected_course; ?>">
                                                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                    <option value="pending" <?php echo $student['enrollment_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="approved" <?php echo $student['enrollment_status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                                    <option value="rejected" <?php echo $student['enrollment_status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                </select>
                                                <button type="submit" name="update_status" class="d-none">Update</button>
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