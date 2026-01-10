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

// Get assignment details
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

// Get assignment details for peer review
$assignment_review = $functions->getAssignmentForReview($assignment_id);

// Get submission count
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM submissions WHERE assignment_id = ?");
$stmt->execute([$assignment_id]);
$submission_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get pending submission count
$stmt = $conn->prepare("SELECT COUNT(*) as pending FROM submissions WHERE assignment_id = ? AND status = 'submitted'");
$stmt->execute([$assignment_id]);
$pending_count = $stmt->fetch(PDO::FETCH_ASSOC)['pending'];

// Handle peer review assignment
if($_POST && isset($_POST['assign_reviews'])) {
    $reviews_per_submission = $_POST['reviews_per_submission'] ?? 2;
    
    $assignments_made = $functions->assignPeerReviews($assignment_id, $reviews_per_submission);
    
    if($assignments_made !== false) {
        $success = "Peer reviews assigned successfully! {$assignments_made} review assignments created.";
        // Refresh counts
        $assignment_review = $functions->getAssignmentForReview($assignment_id);
    } else {
        $error = "Failed to assign peer reviews. Please try again.";
    }
}

// Handle manual review assignment
if($_POST && isset($_POST['assign_manual_review'])) {
    $submission_id = $_POST['submission_id'];
    $reviewer_id = $_POST['reviewer_id'];
    
    // Check if review already exists
    $check_stmt = $conn->prepare("SELECT * FROM peer_reviews WHERE submission_id = ? AND reviewer_id = ?");
    $check_stmt->execute([$submission_id, $reviewer_id]);
    
    if($check_stmt->rowCount() > 0) {
        $error = "This review assignment already exists.";
    } else {
        $stmt = $conn->prepare("INSERT INTO peer_reviews (submission_id, reviewer_id, status) VALUES (?, ?, 'in_progress')");
        if($stmt->execute([$submission_id, $reviewer_id])) {
            $success = "Manual review assignment created successfully!";
            // Refresh assignment data
            $assignment_review = $functions->getAssignmentForReview($assignment_id);
        } else {
            $error = "Failed to assign review. Please try again.";
        }
    }
}

// Get students available for review assignment
$stmt = $conn->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email
                       FROM users u
                       JOIN enrollments e ON u.user_id = e.user_id
                       WHERE e.course_id = ? AND e.enrollment_status = 'approved'
                       AND u.user_id != ?
                       ORDER BY u.first_name, u.last_name");
$stmt->execute([$assignment['course_id'], $_SESSION['user_id']]);
$available_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get submissions for manual assignment
$stmt = $conn->prepare("SELECT s.submission_id, u.first_name, u.last_name, u.username
                       FROM submissions s
                       JOIN users u ON s.student_id = u.user_id
                       WHERE s.assignment_id = ?");
$stmt->execute([$assignment_id]);
$submissions_for_review = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get review statistics
$stmt = $conn->prepare("SELECT 
    COUNT(*) as total_reviews,
    SUM(CASE WHEN pr.status = 'completed' THEN 1 ELSE 0 END) as completed_reviews,
    AVG(rs.score) as avg_score
    FROM peer_reviews pr
    JOIN submissions s ON pr.submission_id = s.submission_id
    LEFT JOIN review_scores rs ON pr.review_id = rs.review_id
    WHERE s.assignment_id = ?");
$stmt->execute([$assignment_id]);
$review_stats = $stmt->fetch(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($assignment['title']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="assignment_submissions.php?id=<?php echo $assignment_id; ?>" class="btn btn-primary me-2">
            <i class="fas fa-list"></i> View Submissions (<?php echo $submission_count; ?>)
        </a>
        <a href="assignments.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Assignments
        </a>
    </div>
</div>

<?php if(isset($success)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?php echo $success; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if(isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Assignment Details</h5>
            </div>
            <div class="card-body">
                <p><strong>Description:</strong></p>
                <p><?php echo nl2br(htmlspecialchars($assignment['description'])); ?></p>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <p><strong>Course:</strong> <?php echo htmlspecialchars($assignment['course_title']); ?></p>
                        <p><strong>Module:</strong> <?php echo htmlspecialchars($assignment['module_title']); ?></p>
                        <p><strong>Due Date:</strong> 
                            <?php echo $assignment['due_date'] ? date('M j, Y g:i A', strtotime($assignment['due_date'])) : 'No due date'; ?>
                            <?php if($assignment['due_date'] && strtotime($assignment['due_date']) < time()): ?>
                                <span class="badge bg-danger">Past Due</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Max Points:</strong> <?php echo htmlspecialchars($assignment['max_points']); ?></p>
                        <p><strong>Submission Format:</strong> <?php echo ucfirst($assignment['submission_format']); ?></p>
                        <p><strong>Type:</strong> <?php echo ucfirst($assignment['assignment_type']); ?></p>
                    </div>
                </div>
                
                <?php if($assignment['allowed_file_types']): ?>
                <div class="mt-3">
                    <p><strong>Allowed File Types:</strong> <?php echo htmlspecialchars($assignment['allowed_file_types']); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if($assignment['max_file_size']): ?>
                <div class="mt-2">
                    <p><strong>Max File Size:</strong> <?php echo htmlspecialchars($assignment['max_file_size']); ?> MB</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Peer Review Management Section -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-users"></i> Peer Review Management
                    <span class="badge bg-light text-dark">
                        <?php echo $assignment_review['review_count'] ?? 0; ?> reviews assigned
                    </span>
                </h5>
            </div>
            <div class="card-body">
                <!-- Auto Assignment -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h6>Automatic Review Assignment</h6>
                        <p class="text-muted">
                            Automatically assign each submission to be reviewed by other students in the course.
                            Currently: <?php echo $assignment_review['submission_count'] ?? 0; ?> submissions, 
                            <?php echo $assignment_review['review_count'] ?? 0; ?> reviews assigned.
                        </p>
                        
                        <form method="POST" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="reviews_per_submission" class="form-label">Reviews per Submission</label>
                                <select class="form-select" id="reviews_per_submission" name="reviews_per_submission">
                                    <option value="2">2 reviews</option>
                                    <option value="3">3 reviews</option>
                                    <option value="4">4 reviews</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <button type="submit" name="assign_reviews" class="btn btn-primary" 
                                        onclick="return confirm('This will assign peer reviews to all submissions. Continue?')">
                                    <i class="fas fa-robot"></i> Auto-Assign Peer Reviews
                                </button>
                                <small class="text-muted ms-2">Each submission will be assigned to the specified number of reviewers</small>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Manual Assignment -->
                <div class="row">
                    <div class="col-12">
                        <h6>Manual Review Assignment</h6>
                        <form method="POST" class="row g-3">
                            <div class="col-md-5">
                                <label for="submission_id" class="form-label">Select Submission</label>
                                <select class="form-select" id="submission_id" name="submission_id" required>
                                    <option value="">Choose submission...</option>
                                    <?php foreach($submissions_for_review as $submission): ?>
                                    <option value="<?php echo $submission['submission_id']; ?>">
                                        <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="reviewer_id" class="form-label">Assign to Reviewer</label>
                                <select class="form-select" id="reviewer_id" name="reviewer_id" required>
                                    <option value="">Choose reviewer...</option>
                                    <?php foreach($available_students as $student): ?>
                                    <option value="<?php echo $student['user_id']; ?>">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="assign_manual_review" class="btn btn-outline-primary mt-4">
                                    Assign
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Review Statistics -->
                <div class="row mt-4 text-center">
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h4><?php echo $review_stats['total_reviews'] ?? 0; ?></h4>
                                <p class="mb-0">Total Reviews</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h4><?php echo $review_stats['completed_reviews'] ?? 0; ?></h4>
                                <p class="mb-0">Completed</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h4><?php echo number_format($review_stats['avg_score'] ?? 0, 1); ?></h4>
                                <p class="mb-0">Avg Score</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="assignment_submissions.php?id=<?php echo $assignment_id; ?>" class="btn btn-outline-info me-md-2">
                                <i class="fas fa-chart-bar"></i> View Review Analytics
                            </a>
                            <a href="rubrics.php?assignment_id=<?php echo $assignment_id; ?>" class="btn btn-outline-success">
                                <i class="fas fa-clipboard-list"></i> Manage Rubrics
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Assignment Statistics</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <p><strong>Total Submissions:</strong> <span class="badge bg-primary"><?php echo $submission_count; ?></span></p>
                    <p><strong>Pending Review:</strong> <span class="badge bg-warning"><?php echo $pending_count; ?></span></p>
                    <p><strong>Graded:</strong> <span class="badge bg-success"><?php echo $submission_count - $pending_count; ?></span></p>
                    <p><strong>Peer Reviews:</strong> <span class="badge bg-info"><?php echo $assignment_review['review_count'] ?? 0; ?></span></p>
                </div>
                
                <?php if($assignment['due_date']): ?>
                <div class="mb-3">
                    <p><strong>Due Date Status:</strong> 
                        <?php if(strtotime($assignment['due_date']) < time()): ?>
                            <span class="badge bg-danger">Past Due</span>
                        <?php else: ?>
                            <?php 
                            $time_remaining = strtotime($assignment['due_date']) - time();
                            $days = floor($time_remaining / (60 * 60 * 24));
                            $hours = floor(($time_remaining % (60 * 60 * 24)) / (60 * 60));
                            ?>
                            <span class="badge bg-success">Due in <?php echo "$days days, $hours hours"; ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
                
                <div class="mt-3">
                    <a href="assignment_submissions.php?id=<?php echo $assignment_id; ?>" class="btn btn-primary w-100 mb-2">
                        <i class="fas fa-list"></i> View All Submissions
                    </a>
                    <a href="assignments.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-arrow-left"></i> Back to Assignments
                    </a>
                </div>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <a href="#" class="btn btn-outline-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#editAssignmentModal">
                    <i class="fas fa-edit"></i> Edit Assignment
                </a>
                <a href="rubrics.php?assignment_id=<?php echo $assignment_id; ?>" class="btn btn-outline-success w-100 mb-2">
                    <i class="fas fa-clipboard-list"></i> Manage Rubric
                </a>
                <a href="assignment_submissions.php?id=<?php echo $assignment_id; ?>" class="btn btn-outline-info w-100 mb-2">
                    <i class="fas fa-chart-bar"></i> View Analytics
                </a>
                <button type="button" class="btn btn-outline-warning w-100" data-bs-toggle="modal" data-bs-target="#autoAssignModal">
                    <i class="fas fa-users"></i> Setup Peer Review
                </button>
            </div>
        </div>

        <!-- Course Information -->
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0">Course Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Course:</strong> <?php echo htmlspecialchars($assignment['course_title']); ?></p>
                <p><strong>Module:</strong> <?php echo htmlspecialchars($assignment['module_title']); ?></p>
                <p><strong>Available Reviewers:</strong> <span class="badge bg-secondary"><?php echo count($available_students); ?></span></p>
                <p><strong>Submissions:</strong> <span class="badge bg-primary"><?php echo count($submissions_for_review); ?></span></p>
            </div>
        </div>
    </div>
</div>

<!-- Edit Assignment Modal -->
<div class="modal fade" id="editAssignmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Assignment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Assignment editing functionality will be implemented here.</p>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    To edit this assignment, go to the assignments list and use the edit option there.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="assignments.php?edit=<?php echo $assignment_id; ?>" class="btn btn-primary">Edit Assignment</a>
            </div>
        </div>
    </div>
</div>

<!-- Auto Assign Peer Reviews Modal -->
<div class="modal fade" id="autoAssignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Setup Peer Reviews</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Configure automatic peer review assignment for this assignment.</p>
                    
                    <div class="mb-3">
                        <label for="modal_reviews_per_submission" class="form-label">Reviews per Submission</label>
                        <select class="form-select" id="modal_reviews_per_submission" name="reviews_per_submission">
                            <option value="2">2 reviews per submission</option>
                            <option value="3">3 reviews per submission</option>
                            <option value="4">4 reviews per submission</option>
                        </select>
                        <div class="form-text">Each submission will be reviewed by this many peers.</div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Note:</strong> This will assign reviews for all current submissions. 
                        New submissions will need manual review assignment or you can run this again later.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_reviews" class="btn btn-primary">Assign Reviews</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-focus on modal inputs
document.addEventListener('DOMContentLoaded', function() {
    const autoAssignModal = document.getElementById('autoAssignModal');
    if (autoAssignModal) {
        autoAssignModal.addEventListener('shown.bs.modal', function () {
            document.getElementById('modal_reviews_per_submission').focus();
        });
    }
});

// Form validation for manual assignment
document.addEventListener('DOMContentLoaded', function() {
    const manualForm = document.querySelector('form[action*="assign_manual_review"]');
    if (manualForm) {
        manualForm.addEventListener('submit', function(e) {
            const submissionId = document.getElementById('submission_id').value;
            const reviewerId = document.getElementById('reviewer_id').value;
            
            if (!submissionId || !reviewerId) {
                e.preventDefault();
                alert('Please select both a submission and a reviewer.');
                return false;
            }
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>