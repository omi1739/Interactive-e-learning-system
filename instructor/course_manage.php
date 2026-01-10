<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('courses.php');
}

$course_id = $_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get course details and verify ownership
$stmt = $conn->prepare("SELECT c.*, u.first_name, u.last_name 
                       FROM courses c 
                       JOIN users u ON c.instructor_id = u.user_id 
                       WHERE c.course_id = ? AND c.instructor_id = ?");
$stmt->execute([$course_id, $_SESSION['user_id']]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$course) {
    $auth->redirect('courses.php');
}

// Get enrolled students - FIXED: Direct query
$stmt = $conn->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email, u.username, 
                               e.enrollment_status, e.enrolled_at, e.grade
                        FROM enrollments e 
                        JOIN users u ON e.user_id = u.user_id 
                        WHERE e.course_id = ?
                        ORDER BY e.enrolled_at DESC");
$stmt->execute([$course_id]);
$course_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle enrollment status update - FIXED: Proper handling
if($_POST && isset($_POST['update_status'])) {
    $user_id = $_POST['user_id'];
    $status = $_POST['status'];
    
    $update_stmt = $conn->prepare("UPDATE enrollments SET enrollment_status = ? WHERE user_id = ? AND course_id = ?");
    if($update_stmt->execute([$status, $user_id, $course_id])) {
        $_SESSION['success'] = "Enrollment status updated successfully!";
        header("Location: course_manage.php?id=" . $course_id);
        exit();
    } else {
        $_SESSION['error'] = "Failed to update enrollment status.";
    }
}

// Handle manual enrollment
if($_POST && isset($_POST['enroll_student'])) {
    $student_email = trim($_POST['student_email']);
    
    if(!empty($student_email)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND role = 'student'");
        $stmt->execute([$student_email]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($student) {
            $check_stmt = $conn->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?");
            $check_stmt->execute([$student['user_id'], $course_id]);
            
            if($check_stmt->rowCount() > 0) {
                $_SESSION['error'] = "Student is already enrolled in this course.";
            } else {
                $enroll_stmt = $conn->prepare("INSERT INTO enrollments (user_id, course_id, enrollment_status) VALUES (?, ?, 'approved')");
                if($enroll_stmt->execute([$student['user_id'], $course_id])) {
                    $_SESSION['success'] = "Student enrolled successfully!";
                    header("Location: course_manage.php?id=" . $course_id);
                    exit();
                } else {
                    $_SESSION['error'] = "Failed to enroll student.";
                }
            }
        } else {
            $_SESSION['error'] = "No student found with that email address.";
        }
    } else {
        $_SESSION['error'] = "Please enter a student email address.";
    }
}

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success']);
unset($_SESSION['error']);

// Get course statistics
$total_students = count($course_students);
$approved_students = count(array_filter($course_students, function($student) {
    return $student['enrollment_status'] == 'approved';
}));
$pending_students = count(array_filter($course_students, function($student) {
    return $student['enrollment_status'] == 'pending';
}));

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Manage Course: <?php echo htmlspecialchars($course['title']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollStudentModal">
            <i class="fas fa-user-plus"></i> Enroll Student
        </button>
    </div>
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

<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $total_students; ?></h4>
                        <p class="card-text">Total Students</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-users fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-success mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $approved_students; ?></h4>
                        <p class="card-text">Approved</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-warning mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $pending_students; ?></h4>
                        <p class="card-text">Pending</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-clock fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-info mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $course['max_students']; ?></h4>
                        <p class="card-text">Capacity</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-chart-line fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-users"></i> Enrolled Students
                    <span class="badge bg-primary"><?php echo $total_students; ?> students</span>
                </h5>
            </div>
            <div class="card-body">
                <?php if(count($course_students) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Student</th>
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
                                    <td>
                                        <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['email']); ?></td>
                                    <td><?php echo htmlspecialchars($student['username']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($student['enrolled_at'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            switch($student['enrollment_status']) {
                                                case 'approved': echo 'success'; break;
                                                case 'pending': echo 'warning'; break;
                                                case 'rejected': echo 'danger'; break;
                                                case 'completed': echo 'info'; break;
                                                default: echo 'secondary';
                                            }
                                        ?>">
                                            <?php echo ucfirst($student['enrollment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($student['grade'] !== null): ?>
                                            <strong><?php echo htmlspecialchars($student['grade']); ?>%</strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="user_id" value="<?php echo $student['user_id']; ?>">
                                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <option value="pending" <?php echo $student['enrollment_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="approved" <?php echo $student['enrollment_status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                                <option value="rejected" <?php echo $student['enrollment_status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                <option value="completed" <?php echo $student['enrollment_status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
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
                    <div class="text-center py-4">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Students Enrolled</h5>
                        <p class="text-muted">No students have enrolled in this course yet.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollStudentModal">
                            <i class="fas fa-user-plus"></i> Enroll First Student
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Enroll Student Modal -->
<div class="modal fade" id="enrollStudentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Enroll Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="student_email" class="form-label">Student Email Address</label>
                        <input type="email" class="form-control" id="student_email" name="student_email" placeholder="Enter student's email address" required>
                        <div class="form-text">The student must have an existing account in the system.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="enroll_student" class="btn btn-primary">Enroll Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>