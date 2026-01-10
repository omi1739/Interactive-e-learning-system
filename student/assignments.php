<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || $_SESSION['role'] != 'student') {
    $auth->redirect('../login.php');
}

// Get database connection - use existing one from bootstrap
$conn = $db->getConnection();

// Get student's assignments -  instructor_feedback
$stmt = $conn->prepare("SELECT a.*, m.title as module_title, c.title as course_title, c.course_id,
                       s.submission_id, s.status as submission_status, s.final_grade, s.instructor_feedback,
                       s.submission_date, s.file_path, s.file_name
                       FROM assignments a
                       JOIN modules m ON a.module_id = m.module_id
                       JOIN courses c ON m.course_id = c.course_id
                       JOIN enrollments e ON c.course_id = e.course_id
                       LEFT JOIN submissions s ON a.assignment_id = s.assignment_id AND s.student_id = ?
                       WHERE e.user_id = ? AND e.enrollment_status = 'approved' AND a.is_published = TRUE
                       ORDER BY a.due_date");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Check what data we're getting
error_log("Student assignments query returned: " . count($assignments) . " assignments");
foreach($assignments as $assignment) {
    error_log("Assignment: {$assignment['title']}, Grade: " . ($assignment['final_grade'] ?? 'NULL') . ", Status: {$assignment['submission_status']}");
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">My Assignments</h1>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">All Assignments</h5>
    </div>
    <div class="card-body">
        <?php if(count($assignments) > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Assignment</th>
                            <th>Course</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Grade</th>
                            <th>Feedback</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($assignments as $assignment): 
                            // Calculate percentage for color coding
                            $percentage = null;
                            if($assignment['final_grade'] !== null && $assignment['max_points'] > 0) {
                                $percentage = ($assignment['final_grade'] / $assignment['max_points']) * 100;
                            }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($assignment['title']); ?></strong>
                                <?php if($assignment['submission_date'] && $assignment['due_date'] && strtotime($assignment['submission_date']) > strtotime($assignment['due_date'])): ?>
                                    <br><small class="text-danger"><i class="fas fa-clock"></i> Submitted late</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($assignment['course_title']); ?></td>
                            <td>
                                <?php if($assignment['due_date']): ?>
                                    <?php echo date('M j, Y g:i A', strtotime($assignment['due_date'])); ?>
                                    <?php if(strtotime($assignment['due_date']) < time() && !$assignment['submission_id']): ?>
                                        <br><span class="badge bg-danger">Overdue</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">No due date</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($assignment['submission_id']): ?>
                                    <?php if($assignment['final_grade'] !== null): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check"></i> Graded
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-<?php echo $assignment['submission_status'] == 'graded' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $assignment['submission_status'])); ?>
                                        </span>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted">
                                        <?php echo date('M j', strtotime($assignment['submission_date'])); ?>
                                    </small>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Not Submitted</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($assignment['final_grade'] !== null): ?>
                                    <?php 
                                    $grade_class = 'text-success';
                                    if($percentage !== null) {
                                        if($percentage < 60) $grade_class = 'text-danger';
                                        elseif($percentage < 70) $grade_class = 'text-warning';
                                        elseif($percentage < 80) $grade_class = 'text-info';
                                    }
                                    ?>
                                    <strong class="<?php echo $grade_class; ?>">
                                        <?php echo htmlspecialchars($assignment['final_grade']); ?>/<?php echo htmlspecialchars($assignment['max_points']); ?>
                                    </strong>
                                    <?php if($percentage !== null): ?>
                                        <br>
                                        <small class="text-muted">
                                            (<?php echo number_format($percentage, 1); ?>%)
                                            <?php if($percentage >= 90): ?>
                                                <span class="badge bg-success">A</span>
                                            <?php elseif($percentage >= 80): ?>
                                                <span class="badge bg-info">B</span>
                                            <?php elseif($percentage >= 70): ?>
                                                <span class="badge bg-warning">C</span>
                                            <?php elseif($percentage >= 60): ?>
                                                <span class="badge bg-orange">D</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">F</span>
                                            <?php endif; ?>
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($assignment['instructor_feedback']): ?>
                                    <button class="btn btn-sm btn-outline-info" 
                                            data-bs-toggle="tooltip" 
                                            title="<?php echo htmlspecialchars($assignment['instructor_feedback']); ?>">
                                        <i class="fas fa-comment"></i> View Feedback
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="assignment_view.php?id=<?php echo $assignment['assignment_id']; ?>" 
                                       class="btn btn-<?php echo $assignment['submission_id'] ? 'outline-primary' : 'primary'; ?>">
                                        <?php echo $assignment['submission_id'] ? 
                                            ($assignment['final_grade'] !== null ? 'Review' : 'View') : 
                                            'Submit'; ?>
                                    </a>
                                    <?php if($assignment['submission_id'] && $assignment['final_grade'] !== null): ?>
                                        <button type="button" class="btn btn-outline-success" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#gradeDetailsModal<?php echo $assignment['assignment_id']; ?>">
                                            <i class="fas fa-chart-line"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <!-- Grade Details Modal -->
                                <?php if($assignment['submission_id'] && $assignment['final_grade'] !== null): ?>
                                <div class="modal fade" id="gradeDetailsModal<?php echo $assignment['assignment_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Grade Details</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <h6><?php echo htmlspecialchars($assignment['title']); ?></h6>
                                                <div class="row text-center mb-3">
                                                    <div class="col-6">
                                                        <div class="display-6 text-success">
                                                            <?php echo htmlspecialchars($assignment['final_grade']); ?>/<?php echo htmlspecialchars($assignment['max_points']); ?>
                                                        </div>
                                                        <small>Final Grade</small>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="display-6 text-primary">
                                                            <?php echo number_format($percentage, 1); ?>%
                                                        </div>
                                                        <small>Percentage</small>
                                                    </div>
                                                </div>
                                                
                                                <?php if($assignment['instructor_feedback']): ?>
                                                <div class="mb-3">
                                                    <label class="form-label"><strong>Instructor Feedback:</strong></label>
                                                    <div class="border p-3 bg-light rounded">
                                                        <?php echo nl2br(htmlspecialchars($assignment['instructor_feedback'])); ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div class="text-muted small">
                                                    <i class="fas fa-info-circle"></i>
                                                    Submitted on: <?php echo date('F j, Y \a\t g:i A', strtotime($assignment['submission_date'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No Assignments Found</h5>
                <p class="text-muted">You don't have any assignments for your enrolled courses yet.</p>
                <a href="courses.php" class="btn btn-primary">Browse Courses</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Assignment Statistics -->
<?php
$total_assignments = count($assignments);
$submitted_assignments = count(array_filter($assignments, function($a) { return $a['submission_id'] !== null; }));
$graded_assignments = count(array_filter($assignments, function($a) { return $a['final_grade'] !== null; }));
$average_grade = 0;

if($graded_assignments > 0) {
    $total_grade = 0;
    foreach($assignments as $assignment) {
        if($assignment['final_grade'] !== null) {
            $total_grade += $assignment['final_grade'];
        }
    }
    $average_grade = $total_grade / $graded_assignments;
}
?>

<div class="row mt-4">
    <div class="col-md-3">
        <div class="card text-white bg-primary">
            <div class="card-body text-center">
                <h4><?php echo $total_assignments; ?></h4>
                <p class="mb-0">Total Assignments</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-info">
            <div class="card-body text-center">
                <h4><?php echo $submitted_assignments; ?></h4>
                <p class="mb-0">Submitted</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-success">
            <div class="card-body text-center">
                <h4><?php echo $graded_assignments; ?></h4>
                <p class="mb-0">Graded</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-warning">
            <div class="card-body text-center">
                <h4><?php echo $graded_assignments > 0 ? number_format($average_grade, 1) : '0'; ?></h4>
                <p class="mb-0">Avg Grade</p>
            </div>
        </div>
    </div>
</div>

<style>
.bg-orange {
    background-color: #ff9800 !important;
}
</style>

<script>
// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>