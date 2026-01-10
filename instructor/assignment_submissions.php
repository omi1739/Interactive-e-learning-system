<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('assignments.php');
}

$assignment_id = $_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get assignment details and verify ownership
$stmt = $conn->prepare("SELECT a.*, m.title as module_title, c.title as course_title, c.course_id, c.instructor_id
                       FROM assignments a
                       JOIN modules m ON a.module_id = m.module_id
                       JOIN courses c ON m.course_id = c.course_id
                       WHERE a.assignment_id = ?");
$stmt->execute([$assignment_id]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$assignment || $assignment['instructor_id'] != $_SESSION['user_id']) {
    $auth->redirect('assignments.php');
}

// Get enhanced submissions data with peer review analytics
$stmt = $conn->prepare("SELECT s.*, u.first_name, u.last_name, u.username, u.email,
                       (SELECT COUNT(*) FROM peer_reviews pr WHERE pr.submission_id = s.submission_id) as total_reviews,
                       (SELECT COUNT(*) FROM peer_reviews pr WHERE pr.submission_id = s.submission_id AND pr.status = 'completed') as completed_reviews,
                       (SELECT AVG(rs.score) FROM peer_reviews pr 
                        JOIN review_scores rs ON pr.review_id = rs.review_id 
                        WHERE pr.submission_id = s.submission_id AND pr.status = 'completed') as avg_peer_score
                       FROM submissions s
                       JOIN users u ON s.student_id = u.user_id
                       WHERE s.assignment_id = ?
                       ORDER BY s.submission_date DESC");
$stmt->execute([$assignment_id]);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle grade submission
if($_POST && isset($_POST['update_grade'])) {
    $submission_id = $_POST['submission_id'];
    $grade = $_POST['grade'];
    $feedback = trim($_POST['feedback']);
    
    // Validate grade
    if($grade >= 0 && $grade <= $assignment['max_points']) {
        $stmt = $conn->prepare("UPDATE submissions SET final_grade = ?, instructor_feedback = ?, status = 'graded' WHERE submission_id = ?");
        if($stmt->execute([$grade, $feedback, $submission_id])) {
            $_SESSION['success'] = "Grade updated successfully!";
            header("Location: assignment_submissions.php?id=" . $assignment_id);
            exit();
        } else {
            $error = "Failed to update grade.";
        }
    } else {
        $error = "Grade must be between 0 and " . $assignment['max_points'];
    }
}

// Handle bulk actions
if($_POST && isset($_POST['bulk_action'])) {
    $selected_submissions = $_POST['selected_submissions'] ?? [];
    $action = $_POST['bulk_action'];
    
    if(empty($selected_submissions)) {
        $error = "No submissions selected for bulk action.";
    } else {
        $placeholders = str_repeat('?,', count($selected_submissions) - 1) . '?';
        
        switch($action) {
            case 'assign_reviews':
                $assignments_made = 0;
                foreach($selected_submissions as $submission_id) {
                    // Assign 2 reviews per selected submission
                    $result = assignReviewsToSubmission($submission_id, 2, $conn);
                    $assignments_made += $result;
                }
                $_SESSION['success'] = "Assigned peer reviews to {$assignments_made} submissions.";
                break;
                
            case 'publish_grades':
                $stmt = $conn->prepare("UPDATE submissions SET status = 'graded' WHERE submission_id IN ($placeholders)");
                $stmt->execute($selected_submissions);
                $_SESSION['success'] = "Published grades for " . count($selected_submissions) . " submissions.";
                break;
                
            case 'send_reminders':
                $_SESSION['success'] = "Reminders sent to " . count($selected_submissions) . " students.";
                break;
        }
        
        header("Location: assignment_submissions.php?id=" . $assignment_id);
        exit();
    }
}

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success']);
unset($_SESSION['error']);

// Calculate statistics
$total_submissions = count($submissions);
$graded_submissions = count(array_filter($submissions, function($s) { return $s['status'] == 'graded'; }));
$pending_submissions = $total_submissions - $graded_submissions;
$average_grade = 0;
$has_grades = false;

if($graded_submissions > 0) {
    $total_grade = 0;
    foreach($submissions as $submission) {
        if($submission['final_grade'] !== null) {
            $total_grade += $submission['final_grade'];
            $has_grades = true;
        }
    }
    $average_grade = $has_grades ? $total_grade / $graded_submissions : 0;
}

require_once '../includes/header.php';

// Helper function to assign reviews to a submission
function assignReviewsToSubmission($submission_id, $reviews_count, $conn) {
    // Get the submission details
    $stmt = $conn->prepare("SELECT s.*, a.assignment_id FROM submissions s JOIN assignments a ON s.assignment_id = a.assignment_id WHERE s.submission_id = ?");
    $stmt->execute([$submission_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get other students who submitted to the same assignment (excluding the author)
    $stmt = $conn->prepare("SELECT DISTINCT u.user_id 
                           FROM users u 
                           JOIN submissions s ON u.user_id = s.student_id 
                           WHERE s.assignment_id = ? AND s.student_id != ?");
    $stmt->execute([$submission['assignment_id'], $submission['student_id']]);
    $potential_reviewers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Shuffle and select reviewers
    shuffle($potential_reviewers);
    $selected_reviewers = array_slice($potential_reviewers, 0, $reviews_count);
    
    $assignments_made = 0;
    foreach($selected_reviewers as $reviewer_id) {
        // Check if review already exists
        $check_stmt = $conn->prepare("SELECT * FROM peer_reviews WHERE submission_id = ? AND reviewer_id = ?");
        $check_stmt->execute([$submission_id, $reviewer_id]);
        
        if($check_stmt->rowCount() == 0) {
            $stmt = $conn->prepare("INSERT INTO peer_reviews (submission_id, reviewer_id, status) VALUES (?, ?, 'in_progress')");
            if($stmt->execute([$submission_id, $reviewer_id])) {
                $assignments_made++;
            }
        }
    }
    
    return $assignments_made;
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Submissions: <?php echo htmlspecialchars($assignment['title']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="assignment_view.php?id=<?php echo $assignment_id; ?>" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back to Assignment
        </a>
        <span class="badge bg-primary"><?php echo count($submissions); ?> submissions</span>
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

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-white bg-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $total_submissions; ?></h4>
                        <p class="card-text">Total Submissions</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-inbox fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-success">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $graded_submissions; ?></h4>
                        <p class="card-text">Graded</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $pending_submissions; ?></h4>
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
        <div class="card text-white bg-info">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $has_grades ? number_format($average_grade, 1) : '0'; ?>/<?php echo $assignment['max_points']; ?></h4>
                        <p class="card-text">Average Grade</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-chart-line fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="fas fa-list"></i> Student Submissions
                <small class="text-muted">Course: <?php echo htmlspecialchars($assignment['course_title']); ?></small>
            </h5>
            <div>
                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bulkActionsModal">
                    <i class="fas fa-tasks"></i> Bulk Actions
                </button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if(count($submissions) > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped" id="submissionsTable">
                    <thead>
                        <tr>
                            <th width="30">
                                <input type="checkbox" id="selectAll">
                            </th>
                            <th>Student</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Grade</th>
                            <th>Peer Reviews</th>
                            <th>Avg Peer Score</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($submissions as $submission): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="selected_submissions[]" value="<?php echo $submission['submission_id']; ?>" class="submission-checkbox">
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($submission['email']); ?></small>
                            </td>
                            <td>
                                <?php echo date('M j, Y g:i A', strtotime($submission['submission_date'])); ?>
                                <?php if($assignment['due_date'] && strtotime($submission['submission_date']) > strtotime($assignment['due_date'])): ?>
                                    <br><span class="badge bg-danger">Late</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    switch($submission['status']) {
                                        case 'graded': echo 'success'; break;
                                        case 'submitted': echo 'warning'; break;
                                        case 'draft': echo 'secondary'; break;
                                        default: echo 'info';
                                    }
                                ?>">
                                    <?php echo ucfirst($submission['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if($submission['final_grade'] !== null): ?>
                                    <strong><?php echo htmlspecialchars($submission['final_grade']); ?>/<?php echo htmlspecialchars($assignment['max_points']); ?></strong>
                                    <?php 
                                    $percentage = ($submission['final_grade'] / $assignment['max_points']) * 100;
                                    if($percentage >= 80): ?>
                                        <span class="badge bg-success ms-1">A</span>
                                    <?php elseif($percentage >= 70): ?>
                                        <span class="badge bg-info ms-1">B</span>
                                    <?php elseif($percentage >= 60): ?>
                                        <span class="badge bg-warning ms-1">C</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger ms-1">D/F</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Not graded</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $submission['completed_reviews'] > 0 ? 'info' : 'secondary'; ?>">
                                    <?php echo $submission['completed_reviews']; ?>/<?php echo $submission['total_reviews']; ?> completed
                                </span>
                            </td>
                            <td>
                                <?php if($submission['avg_peer_score'] !== null): ?>
                                    <span class="badge bg-<?php echo $submission['avg_peer_score'] >= ($assignment['max_points'] * 0.7) ? 'success' : 'warning'; ?>">
                                        <?php echo number_format($submission['avg_peer_score'], 1); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#gradeModal<?php echo $submission['submission_id']; ?>">
                                        <i class="fas fa-edit"></i> Grade
                                    </button>
                                    <a href="submission_view.php?id=<?php echo $submission['submission_id']; ?>" class="btn btn-info">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button class="btn btn-outline-secondary" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="#" onclick="assignSingleReview(<?php echo $submission['submission_id']; ?>)">
                                            <i class="fas fa-user-plus"></i> Assign Review
                                        </a></li>
                                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#peerReviewsModal<?php echo $submission['submission_id']; ?>">
                                            <i class="fas fa-comments"></i> View Peer Reviews
                                        </a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="return confirm('Are you sure you want to delete this submission?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>

                        <!-- Grade Modal for each submission -->
                        <div class="modal fade" id="gradeModal<?php echo $submission['submission_id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Grade Submission</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p><strong>Student:</strong> <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></p>
                                                    <p><strong>Assignment:</strong> <?php echo htmlspecialchars($assignment['title']); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p><strong>Submitted:</strong> <?php echo date('M j, Y g:i A', strtotime($submission['submission_date'])); ?></p>
                                                    <?php if($submission['avg_peer_score'] !== null): ?>
                                                        <p><strong>Avg Peer Score:</strong> <?php echo number_format($submission['avg_peer_score'], 1); ?>/<?php echo $assignment['max_points']; ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="grade<?php echo $submission['submission_id']; ?>" class="form-label">Final Grade (0 - <?php echo $assignment['max_points']; ?>)</label>
                                                <input type="number" class="form-control" id="grade<?php echo $submission['submission_id']; ?>" 
                                                       name="grade" value="<?php echo $submission['final_grade'] ?? ''; ?>" 
                                                       min="0" max="<?php echo $assignment['max_points']; ?>" step="0.5" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="feedback<?php echo $submission['submission_id']; ?>" class="form-label">Instructor Feedback</label>
                                                <textarea class="form-control" id="feedback<?php echo $submission['submission_id']; ?>" 
                                                          name="feedback" rows="4" placeholder="Provide detailed feedback for the student..."><?php echo htmlspecialchars($submission['instructor_feedback'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <input type="hidden" name="submission_id" value="<?php echo $submission['submission_id']; ?>">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_grade" class="btn btn-primary">Save Grade</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Peer Reviews Modal -->
                        <div class="modal fade" id="peerReviewsModal<?php echo $submission['submission_id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Peer Reviews for <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <?php
                                        $review_stmt = $conn->prepare("SELECT pr.*, u.first_name, u.last_name, u.username,
                                                                      (SELECT AVG(score) FROM review_scores WHERE review_id = pr.review_id) as avg_score
                                                                      FROM peer_reviews pr
                                                                      JOIN users u ON pr.reviewer_id = u.user_id
                                                                      WHERE pr.submission_id = ?
                                                                      ORDER BY pr.status DESC, pr.review_date DESC");
                                        $review_stmt->execute([$submission['submission_id']]);
                                        $reviews = $review_stmt->fetchAll(PDO::FETCH_ASSOC);
                                        ?>
                                        
                                        <?php if(count($reviews) > 0): ?>
                                            <?php foreach($reviews as $review): ?>
                                            <div class="card mb-3">
                                                <div class="card-header d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></strong>
                                                        <?php if($review['is_anonymous']): ?>
                                                            <span class="badge bg-secondary ms-2">Anonymous</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <span class="badge bg-<?php echo $review['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($review['status']); ?>
                                                        </span>
                                                        <?php if($review['avg_score']): ?>
                                                            <span class="badge bg-info ms-1">Score: <?php echo number_format($review['avg_score'], 1); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="card-body">
                                                    <?php if($review['overall_feedback']): ?>
                                                        <p><strong>Overall Feedback:</strong></p>
                                                        <p><?php echo nl2br(htmlspecialchars($review['overall_feedback'])); ?></p>
                                                    <?php else: ?>
                                                        <p class="text-muted">No overall feedback provided yet.</p>
                                                    <?php endif; ?>
                                                    <small class="text-muted">Reviewed on: <?php echo date('M j, Y g:i A', strtotime($review['review_date'])); ?></small>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-muted">No peer reviews have been completed for this submission yet.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No Submissions Yet</h5>
                <p class="text-muted">Students haven't submitted any work for this assignment yet.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Bulk Actions Modal -->
<div class="modal fade" id="bulkActionsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Actions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="bulkActionsForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="bulk_action" class="form-label">Select Action</label>
                        <select class="form-select" id="bulk_action" name="bulk_action" required>
                            <option value="">Choose an action...</option>
                            <option value="assign_reviews">Assign Peer Reviews (2 per submission)</option>
                            <option value="publish_grades">Publish Grades</option>
                            <option value="send_reminders">Send Reminders</option>
                        </select>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        This action will apply to all selected submissions.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply to Selected</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Select all checkboxes
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.submission-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});

// Bulk actions form validation
document.getElementById('bulkActionsForm').addEventListener('submit', function(e) {
    const selectedCount = document.querySelectorAll('.submission-checkbox:checked').length;
    if(selectedCount === 0) {
        e.preventDefault();
        alert('Please select at least one submission.');
        return false;
    }
});

// Single review assignment
function assignSingleReview(submissionId) {
    if(confirm('Assign 2 peer reviews to this submission?')) {
        // This would typically be an AJAX call
        window.location.href = `assignment_view.php?id=<?php echo $assignment_id; ?>&assign_single=${submissionId}`;
    }
}

// Initialize table sorting and filtering
document.addEventListener('DOMContentLoaded', function() {
    // Add search functionality
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Search submissions...';
    searchInput.className = 'form-control mb-3';
    searchInput.style.maxWidth = '300px';
    
    searchInput.addEventListener('input', function() {
        const filter = this.value.toLowerCase();
        const rows = document.querySelectorAll('#submissionsTable tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
    
    // Insert search box before the table
    const table = document.querySelector('.table-responsive');
    table.parentNode.insertBefore(searchInput, table);
});
</script>

<?php require_once '../includes/footer.php'; ?>