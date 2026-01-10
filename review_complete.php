<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

$review_id = $_GET['id'] ?? 0;
$conn = $db->getConnection();

// Get review details
$stmt = $conn->prepare("SELECT pr.*, 
                               s.submission_id, s.submission_text, s.file_path, s.file_name,
                               a.title as assignment_title, a.assignment_id, a.max_points,
                               u.first_name, u.last_name, u.username,
                               c.title as course_title
                        FROM peer_reviews pr
                        JOIN submissions s ON pr.submission_id = s.submission_id
                        JOIN assignments a ON s.assignment_id = a.assignment_id
                        JOIN modules m ON a.module_id = m.module_id
                        JOIN courses c ON m.course_id = c.course_id
                        JOIN users u ON s.student_id = u.user_id
                        WHERE pr.review_id = ? AND pr.reviewer_id = ?");
$stmt->execute([$review_id, $_SESSION['user_id']]);
$review = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$review) {
    $auth->redirect('peer_reviews.php');
}

// Get rubrics for this assignment
$stmt = $conn->prepare("SELECT * FROM rubrics WHERE assignment_id = ? ORDER BY rubric_order");
$stmt->execute([$review['assignment_id']]);
$rubrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle review submission
if($_POST && isset($_POST['submit_review'])) {
    $overall_feedback = trim($_POST['overall_feedback']);
    
    // Update peer review
    $stmt = $conn->prepare("UPDATE peer_reviews SET overall_feedback = ?, status = 'completed', review_date = NOW() WHERE review_id = ?");
    $stmt->execute([$overall_feedback, $review_id]);
    
    // Save rubric scores
    foreach($rubrics as $rubric) {
        if(isset($_POST['score_' . $rubric['rubric_id']])) {
            $score = $_POST['score_' . $rubric['rubric_id']];
            $feedback = $_POST['feedback_' . $rubric['rubric_id']] ?? '';
            
            $stmt = $conn->prepare("INSERT INTO review_scores (review_id, rubric_id, score, feedback) VALUES (?, ?, ?, ?)");
            $stmt->execute([$review_id, $rubric['rubric_id'], $score, $feedback]);
        }
    }
    
    $_SESSION['success'] = "Review submitted successfully!";
    header("Location: peer_reviews.php");
    exit();
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Complete Peer Review</h1>
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
                <h5 class="card-title mb-0">Submission to Review</h5>
            </div>
            <div class="card-body">
                <h6>Assignment: <?php echo htmlspecialchars($review['assignment_title']); ?></h6>
                <p><strong>Course:</strong> <?php echo htmlspecialchars($review['course_title']); ?></p>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></p>
                
                <?php if($review['submission_text']): ?>
                <div class="mb-3">
                    <label class="form-label"><strong>Submission Text:</strong></label>
                    <div class="border p-3 bg-light">
                        <?php echo nl2br(htmlspecialchars($review['submission_text'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if($review['file_path']): ?>
                <div class="mb-3">
                    <label class="form-label"><strong>Submitted File:</strong></label>
                    <a href="<?php echo htmlspecialchars($review['file_path']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-download"></i> Download: <?php echo htmlspecialchars($review['file_name']); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" class="mt-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Review Criteria</h5>
                </div>
                <div class="card-body">
                    <?php foreach($rubrics as $rubric): ?>
                    <div class="mb-4 p-3 border rounded">
                        <h6><?php echo htmlspecialchars($rubric['criterion_name']); ?></h6>
                        <p class="text-muted"><?php echo htmlspecialchars($rubric['description']); ?></p>
                        <p><strong>Max Score:</strong> <?php echo $rubric['max_score']; ?> points</p>
                        
                        <div class="mb-3">
                            <label for="score_<?php echo $rubric['rubric_id']; ?>" class="form-label">Score (0 - <?php echo $rubric['max_score']; ?>)</label>
                            <input type="number" class="form-control" id="score_<?php echo $rubric['rubric_id']; ?>" 
                                   name="score_<?php echo $rubric['rubric_id']; ?>" min="0" max="<?php echo $rubric['max_score']; ?>" 
                                   step="0.5" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="feedback_<?php echo $rubric['rubric_id']; ?>" class="form-label">Feedback</label>
                            <textarea class="form-control" id="feedback_<?php echo $rubric['rubric_id']; ?>" 
                                      name="feedback_<?php echo $rubric['rubric_id']; ?>" rows="3"
                                      placeholder="Provide specific feedback for this criterion..."></textarea>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="mb-3">
                        <label for="overall_feedback" class="form-label">Overall Feedback</label>
                        <textarea class="form-control" id="overall_feedback" name="overall_feedback" rows="4"
                                  placeholder="Provide overall feedback for this submission..."></textarea>
                    </div>
                    
                    <button type="submit" name="submit_review" class="btn btn-success">
                        <i class="fas fa-check"></i> Submit Review
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Review Guidelines</h5>
            </div>
            <div class="card-body">
                <h6>How to provide effective feedback:</h6>
                <ul class="small">
                    <li>Be constructive and specific</li>
                    <li>Focus on the work, not the person</li>
                    <li>Provide examples for improvement</li>
                    <li>Balance positive and critical feedback</li>
                    <li>Consider the assignment requirements</li>
                </ul>
                
                <div class="alert alert-info mt-3">
                    <small>
                        <strong>Note:</strong> Your review should help the student improve their work. 
                        Provide clear, actionable feedback.
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>