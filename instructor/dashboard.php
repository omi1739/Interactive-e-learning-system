<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

$conn = $db->getConnection();

// Get instructor statistics
$courses = $functions->getCourses($_SESSION['user_id']);
$total_courses = count($courses);

// Calculate statistics
$total_students = 0;
$total_assignments = 0;
$pending_submissions = 0;

foreach($courses as $course) {
    $students = $functions->getCourseStudents($course['course_id']);
    $total_students += count($students);
    
    // Get assignments count
    $stmt = $conn->prepare("SELECT COUNT(*) as assignment_count 
                           FROM assignments a 
                           JOIN modules m ON a.module_id = m.module_id 
                           WHERE m.course_id = ?");
    $stmt->execute([$course['course_id']]);
    $assignment_count = $stmt->fetch(PDO::FETCH_ASSOC)['assignment_count'];
    $total_assignments += $assignment_count;
    
    // Get pending submissions
    $stmt = $conn->prepare("SELECT COUNT(*) as pending_count 
                           FROM submissions s 
                           JOIN assignments a ON s.assignment_id = a.assignment_id 
                           JOIN modules m ON a.module_id = m.module_id 
                           WHERE m.course_id = ? AND s.status = 'submitted'");
    $stmt->execute([$course['course_id']]);
    $pending_count = $stmt->fetch(PDO::FETCH_ASSOC)['pending_count'];
    $pending_submissions += $pending_count;
}

// Get recent enrollments
$stmt = $conn->prepare("SELECT e.*, u.first_name, u.last_name, c.title as course_title 
                       FROM enrollments e 
                       JOIN users u ON e.user_id = u.user_id 
                       JOIN courses c ON e.course_id = c.course_id 
                       WHERE c.instructor_id = ? 
                       ORDER BY e.enrolled_at DESC 
                       LIMIT 5");
$stmt->execute([$_SESSION['user_id']]);
$recent_enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get courses needing attention
$courses_needing_attention = [];
foreach($courses as $course) {
    $stmt = $conn->prepare("SELECT COUNT(*) as pending_count 
                           FROM submissions s 
                           JOIN assignments a ON s.assignment_id = a.assignment_id 
                           JOIN modules m ON a.module_id = m.module_id 
                           WHERE m.course_id = ? AND s.status = 'submitted'");
    $stmt->execute([$course['course_id']]);
    $pending_count = $stmt->fetch(PDO::FETCH_ASSOC)['pending_count'];
    
    if($pending_count > 0) {
        $courses_needing_attention[] = [
            'course' => $course,
            'pending_submissions' => $pending_count
        ];
    }
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Instructor Dashboard</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="btn-group me-2">
            <a href="courses.php" class="btn btn-sm btn-outline-secondary">My Courses</a>
            <a href="assignments.php" class="btn btn-sm btn-outline-secondary">Assignments</a>
            <a href="students.php" class="btn btn-sm btn-outline-secondary">Students</a>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCourseModal">
            <i class="fas fa-plus"></i> New Course
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $total_courses; ?></h4>
                        <p class="card-text">My Courses</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-book fa-2x"></i>
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
        <div class="card text-white bg-warning mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $pending_submissions; ?></h4>
                        <p class="card-text">Pending Submissions</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-tasks fa-2x"></i>
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
                        <h4 class="card-title"><?php echo $total_assignments; ?></h4>
                        <p class="card-text">Total Assignments</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-clipboard-list fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-8">
        <!-- My Courses -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-book"></i> My Courses
                    <span class="badge bg-primary"><?php echo $total_courses; ?> courses</span>
                </h5>
            </div>
            <div class="card-body">
                <?php if($total_courses > 0): ?>
                    <div class="row">
                        <?php foreach($courses as $course): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title"><?php echo htmlspecialchars($course['title']); ?></h6>
                                    <p class="card-text small text-muted">
                                        <?php echo substr($course['description'] ?? 'No description', 0, 80) . '...'; ?>
                                    </p>
                                    <p class="card-text">
                                        <small class="text-muted">
                                            Code: <?php echo htmlspecialchars($course['course_code']); ?>
                                        </small>
                                    </p>
                                    <p class="card-text">
                                        <span class="badge bg-<?php echo $course['is_published'] ? 'success' : 'warning'; ?>">
                                            <?php echo $course['is_published'] ? 'Published' : 'Draft'; ?>
                                        </span>
                                    </p>
                                </div>
                                <div class="card-footer">
                                    <a href="course_manage.php?id=<?php echo $course['course_id']; ?>" class="btn btn-primary btn-sm">Manage</a>
                                    <a href="course_edit.php?id=<?php echo $course['course_id']; ?>" class="btn btn-outline-secondary btn-sm">Edit</a>
                                    <a href="students.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-outline-info btn-sm">Students</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Courses Created</h5>
                        <p class="text-muted">Create your first course to get started!</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCourseModal">
                            <i class="fas fa-plus"></i> Create First Course
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Courses Needing Attention -->
        <?php if(count($courses_needing_attention) > 0): ?>
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="card-title mb-0">
                    <i class="fas fa-exclamation-triangle"></i> Courses Needing Attention
                    <span class="badge bg-danger"><?php echo count($courses_needing_attention); ?> courses</span>
                </h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach($courses_needing_attention as $item): ?>
                    <div class="list-group-item">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1"><?php echo htmlspecialchars($item['course']['title']); ?></h6>
                            <span class="badge bg-warning"><?php echo $item['pending_submissions']; ?> pending</span>
                        </div>
                        <p class="mb-1 small text-muted">Submissions waiting for grading</p>
                        <a href="assignment_submissions.php?course=<?php echo $item['course']['course_id']; ?>" class="btn btn-sm btn-warning mt-2">Grade Submissions</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-md-4">
        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <button class="btn btn-outline-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#createCourseModal">
                    <i class="fas fa-plus"></i> Create Course
                </button>
                <a href="courses.php" class="btn btn-outline-success w-100 mb-2">
                    <i class="fas fa-book"></i> My Courses
                </a>
                <a href="assignments.php" class="btn btn-outline-info w-100 mb-2">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="students.php" class="btn btn-outline-warning w-100">
                    <i class="fas fa-users"></i> Students
                </a>
            </div>
        </div>

        <!-- Recent Enrollments -->
        <?php if(count($recent_enrollments) > 0): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user-plus"></i> Recent Enrollments
                </h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach($recent_enrollments as $enrollment): ?>
                    <div class="list-group-item">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1"><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></h6>
                            <span class="badge bg-<?php 
                                switch($enrollment['enrollment_status']) {
                                    case 'approved': echo 'success'; break;
                                    case 'pending': echo 'warning'; break;
                                    case 'rejected': echo 'danger'; break;
                                    default: echo 'secondary';
                                }
                            ?>">
                                <?php echo ucfirst($enrollment['enrollment_status']); ?>
                            </span>
                        </div>
                        <p class="mb-1 small text-muted"><?php echo htmlspecialchars($enrollment['course_title']); ?></p>
                        <small class="text-muted"><?php echo date('M j, Y', strtotime($enrollment['enrolled_at'])); ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Course Modal -->
<div class="modal fade" id="createCourseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Course</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="courses.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Course Title</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="course_code" class="form-label">Course Code</label>
                        <input type="text" class="form-control" id="course_code" name="course_code" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_course" class="btn btn-primary">Create Course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>