<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('peer_reviews.php');
}

$submission_id = $_GET['id'];
$conn = $db->getConnection();

// Get submission details
$stmt = $conn->prepare("SELECT s.*, a.title as assignment_title, a.assignment_id,
                               u.first_name, u.last_name, u.username,
                               c.title as course_title, c.course_id
                        FROM submissions s
                        JOIN assignments a ON s.assignment_id = a.assignment_id
                        JOIN modules m ON a.module_id = m.module_id
                        JOIN courses c ON m.course_id = c.course_id
                        JOIN users u ON s.student_id = u.user_id
                        WHERE s.submission_id = ?");
$stmt->execute([$submission_id]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$submission) {
    $auth->redirect('peer_reviews.php');
}

// Check if user has permission to view this submission (either owner or assigned reviewer)
$is_owner = $submission['student_id'] == $_SESSION['user_id'];
$is_reviewer = false;

if(!$is_owner) {
    $stmt = $conn->prepare("SELECT * FROM peer_reviews WHERE submission_id = ? AND reviewer_id = ?");
    $stmt->execute([$submission_id, $_SESSION['user_id']]);
    $is_reviewer = $stmt->rowCount() > 0;
}

if(!$is_owner && !$is_reviewer) {
    $auth->redirect('peer_reviews.php');
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Submission Preview</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="peer_reviews.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Reviews
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Submission Details</h5>
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <h6>Assignment Information</h6>
                    <p><strong>Assignment:</strong> <?php echo htmlspecialchars($submission['assignment_title']); ?></p>
                    <p><strong>Course:</strong> <?php echo htmlspecialchars($submission['course_title']); ?></p>
                    <p><strong>Student:</strong> <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></p>
                    <p><strong>Submitted:</strong> <?php echo date('M j, Y g:i A', strtotime($submission['submission_date'])); ?></p>
                    <p><strong>Status:</strong> 
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
                    </p>
                </div>

                <?php if($submission['submission_text']): ?>
                <div class="mb-4">
                    <h6>Text Submission</h6>
                    <div class="border p-3 bg-light">
                        <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if($submission['file_path']): ?>
                <div class="mb-4">
                    <h6>Submitted File</h6>
                    <a href="../download.php?submission_id=<?php echo (int)$submission['submission_id']; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-download"></i> Download File: <?php echo htmlspecialchars($submission['file_name']); ?>
                    </a>
                </div>
                <?php endif; ?>

                <?php if($submission['instructor_feedback']): ?>
                <div class="mb-4">
                    <h6>Instructor Feedback</h6>
                    <div class="border p-3 bg-light">
                        <?php echo nl2br(htmlspecialchars($submission['instructor_feedback'])); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if($submission['final_grade'] !== null): ?>
                <div class="mb-4">
                    <h6>Grade</h6>
                    <div class="alert alert-success">
                        <strong>Final Grade:</strong> <?php echo htmlspecialchars($submission['final_grade']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Actions</h5>
            </div>
            <div class="card-body">
                <?php if($is_reviewer): ?>
                    <?php 
                    // Check if review already exists
                    $stmt = $conn->prepare("SELECT * FROM peer_reviews WHERE submission_id = ? AND reviewer_id = ?");
                    $stmt->execute([$submission_id, $_SESSION['user_id']]);
                    $review = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if($review && $review['status'] == 'in_progress'): ?>
                        <a href="review_complete.php?id=<?php echo $review['review_id']; ?>" class="btn btn-primary w-100 mb-2">
                            <i class="fas fa-edit"></i> Complete Review
                        </a>
                    <?php elseif($review && $review['status'] == 'completed'): ?>
                        <a href="review_view.php?id=<?php echo $review['review_id']; ?>" class="btn btn-success w-100 mb-2">
                            <i class="fas fa-eye"></i> View Your Review
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                
                <a href="peer_reviews.php" class="btn btn-outline-secondary w-100 mb-2">
                    <i class="fas fa-arrow-left"></i> Back to Reviews
                </a>
                
                <?php if(!$is_owner): ?>
                <div class="alert alert-info mt-3">
                    <small>
                        <strong>Note:</strong> You are reviewing this submission as a peer. 
                        Provide constructive feedback to help your classmate improve.
                    </small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>