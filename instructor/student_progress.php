<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['course_id']) || !isset($_GET['student_id'])) {
    $auth->redirect('courses.php');
}

$course_id = $_GET['course_id'];
$student_id = $_GET['student_id'];
$db = new Database();
$conn = $db->getConnection();

// Verify course ownership and get student details
$stmt = $conn->prepare("SELECT c.*, u.first_name, u.last_name, u.email, u.username, e.enrollment_status
                       FROM courses c 
                       JOIN users u ON u.user_id = ?
                       JOIN enrollments e ON e.user_id = u.user_id AND e.course_id = c.course_id
                       WHERE c.course_id = ? AND c.instructor_id = ?");
$stmt->execute([$student_id, $course_id, $_SESSION['user_id']]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$data) {
    $auth->redirect('courses.php');
}

// Get student submissions for this course
$stmt = $conn->prepare("SELECT a.*, s.submission_id, s.submission_date, s.status as submission_status, s.final_grade
                       FROM assignments a
                       JOIN modules m ON a.module_id = m.module_id
                       LEFT JOIN submissions s ON a.assignment_id = s.assignment_id AND s.student_id = ?
                       WHERE m.course_id = ? AND a.is_published = TRUE
                       ORDER BY m.module_order, a.due_date");
$stmt->execute([$student_id, $course_id]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate progress statistics
$total_assignments = count($assignments);
$submitted_assignments = count(array_filter($assignments, function($a) {
    return !empty($a['submission_id']);
}));
$graded_assignments = count(array_filter($assignments, function($a) {
    return $a['submission_status'] == 'graded';
}));
$average_grade = 0;

if ($graded_assignments > 0) {
    $total_grade = 0;
    foreach($assignments as $assignment) {
        if($assignment['submission_status'] == 'graded' && $assignment['final_grade'] !== null) {
            $total_grade += $assignment['final_grade'];
        }
    }
    $average_grade = $total_grade / $graded_assignments;
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Student Progress: <?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="course_manage.php?id=<?php echo $course_id; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Course
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
            <div class="card-body text-center">
                <h4 class="card-title"><?php echo $submitted_assignments; ?>/<?php echo $total_assignments; ?></h4>
                <p class="card-text">Assignments Submitted</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-success mb-3">
            <div class="card-body text-center">
                <h4 class="card-title"><?php echo $graded_assignments; ?></h4>
                <p class="card-text">Assignments Graded</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-info mb-3">
            <div class="card-body text-center">
                <h4 class="card-title"><?php echo number_format($average_grade, 1); ?>%</h4>
                <p class="card-text">Average Grade</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-warning mb-3">
            <div class="card-body text-center">
                <h4 class="card-title"><?php echo number_format(($submitted_assignments / max($total_assignments, 1)) * 100, 1); ?>%</h4>
                <p class="card-text">Completion Rate</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Student Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Name:</strong> <?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($data['email']); ?></p>
                <p><strong>Username:</strong> <?php echo htmlspecialchars($data['username']); ?></p>
                <p><strong>Enrollment Status:</strong> 
                    <span class="badge bg-<?php 
                        switch($data['enrollment_status']) {
                            case 'approved': echo 'success'; break;
                            case 'pending': echo 'warning'; break;
                            case 'rejected': echo 'danger'; break;
                            default: echo 'secondary';
                        }
                    ?>">
                        <?php echo ucfirst($data['enrollment_status']); ?>
                    </span>
                </p>
                <p><strong>Course:</strong> <?php echo htmlspecialchars($data['title']); ?></p>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Assignment Progress</h5>
            </div>
            <div class="card-body">
                <?php if(count($assignments) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Assignment</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Grade</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($assignments as $assignment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($assignment['title']); ?></td>
                                    <td>
                                        <?php if($assignment['due_date']): ?>
                                            <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">No due date</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($assignment['submission_id']): ?>
                                            <span class="badge bg-<?php 
                                                switch($assignment['submission_status']) {
                                                    case 'graded': echo 'success'; break;
                                                    case 'submitted': echo 'warning'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst($assignment['submission_status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not Submitted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($assignment['final_grade'] !== null): ?>
                                            <strong><?php echo htmlspecialchars($assignment['final_grade']); ?>/<?php echo htmlspecialchars($assignment['max_points']); ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($assignment['submission_date']): ?>
                                            <?php echo date('M j, Y', strtotime($assignment['submission_date'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($assignment['submission_id']): ?>
                                            <a href="submission_view.php?id=<?php echo $assignment['submission_id']; ?>" class="btn btn-primary btn-sm">
                                                View
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No assignments available for this course.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>