<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

$review_id = intval($_GET['id'] ?? 0);
$conn = $db->getConnection();

// Get review details.
// pr.reviewer_id = ? scopes this to the signed-in reviewer, so one student
// cannot open another student's review by guessing the id.
$stmt = $conn->prepare("SELECT pr.*, 
                               s.submission_id, s.submission_text, s.file_path, s.file_name, s.submission_date,
                               s.student_id as author_id,
                               a.title as assignment_title, a.assignment_id, a.max_points, a.description as assignment_description,
                               u.first_name, u.last_name, u.username,
                               c.title as course_title, c.course_id
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

// A student must never review their own work.
if((int)$review['author_id'] === (int)$_SESSION['user_id']) {
    $_SESSION['error'] = "You cannot review your own submission.";
    header("Location: peer_reviews.php");
    exit();
}

// Check if review is already completed
if($review['status'] == 'completed') {
    $_SESSION['error'] = "This review has already been completed.";
    header("Location: peer_reviews.php");
    exit();
}

// Get rubrics for this assignment
$stmt = $conn->prepare("SELECT * FROM rubrics WHERE assignment_id = ? ORDER BY rubric_order");
$stmt->execute([$review['assignment_id']]);
$rubrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle review submission
if($_POST && isset($_POST['submit_review'])) {
    verify_csrf();
    $overall_feedback = trim($_POST['overall_feedback']);
    
    // Validate all rubric scores are provided
    $all_scores_provided = true;
    foreach($rubrics as $rubric) {
        if(!isset($_POST['score_' . $rubric['rubric_id']]) || $_POST['score_' . $rubric['rubric_id']] === '') {
            $all_scores_provided = false;
            break;
        }
    }
    
    if(!$all_scores_provided) {
        $error = "Please provide scores for all rubric criteria.";
    } elseif(empty($overall_feedback)) {
        $error = "Overall feedback is required.";
    } elseif(strlen($overall_feedback) < 20) {
        $error = "Overall feedback must be at least 20 characters long.";
    } else {
        // Begin transaction
        $conn->beginTransaction();
        
        try {
            // Update peer review
            $stmt = $conn->prepare("UPDATE peer_reviews SET overall_feedback = ?, status = 'completed', review_date = NOW() WHERE review_id = ?");
            $stmt->execute([$overall_feedback, $review_id]);
            
            // Save rubric scores
            $total_score = 0;
            $max_possible = 0;
            
            foreach($rubrics as $rubric) {
                $score = floatval($_POST['score_' . $rubric['rubric_id']]);
                $feedback = trim($_POST['feedback_' . $rubric['rubric_id']] ?? '');
                
                // Validate score range
                if($score < 0 || $score > $rubric['max_score']) {
                    throw new Exception("Invalid score for criterion: " . $rubric['criterion_name']);
                }
                
                $stmt = $conn->prepare("INSERT INTO review_scores (review_id, rubric_id, score, feedback) VALUES (?, ?, ?, ?)");
                $stmt->execute([$review_id, $rubric['rubric_id'], $score, $feedback]);
                
                $total_score += $score;
                $max_possible += $rubric['max_score'];
            }
            
            // Calculate percentage score
            $percentage_score = $max_possible > 0 ? ($total_score / $max_possible) * 100 : 0;
            
            $conn->commit();
            
            $_SESSION['success'] = "Review submitted successfully! Overall score: " . number_format($percentage_score, 1) . "%";
            header("Location: peer_reviews.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Failed to submit review: " . $e->getMessage();
        }
    }
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

<?php if(isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php echo e($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <!-- Submission to Review -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-file-alt"></i> Submission to Review
                </h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6><?php echo htmlspecialchars($review['assignment_title']); ?></h6>
                        <p class="mb-1"><strong>Course:</strong> <?php echo htmlspecialchars($review['course_title']); ?></p>
                        <p class="mb-1"><strong>Student:</strong> <?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></p>
                        <p class="mb-0"><strong>Submitted:</strong> <?php echo date('M j, Y g:i A', strtotime($review['submission_date'])); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Assignment Description:</strong></p>
                        <p class="text-muted"><?php echo nl2br(htmlspecialchars($review['assignment_description'])); ?></p>
                    </div>
                </div>

                <?php if($review['submission_text']): ?>
                <div class="mb-3">
                    <label class="form-label"><strong>Submission Content:</strong></label>
                    <div class="border p-3 bg-light" style="max-height: 300px; overflow-y: auto;">
                        <?php echo nl2br(htmlspecialchars($review['submission_text'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if($review['file_path']): ?>
                <div class="mb-3">
                    <label class="form-label"><strong>Submitted File:</strong></label>
                    <a href="../download.php?submission_id=<?php echo (int)$review['submission_id']; ?>&amp;review_id=<?php echo (int)$review['review_id']; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-download"></i> Download: <?php echo htmlspecialchars($review['file_name']); ?>
                    </a>
                </div>
                <?php endif; ?>

                <?php if(!$review['submission_text'] && !$review['file_path']): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    No submission content available for review.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Review Form -->
        <form method="POST" id="reviewForm">
            <?php echo csrf_field(); ?>
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-clipboard-check"></i> Review Criteria
                        <?php if(count($rubrics) > 0): ?>
                            <span class="badge bg-light text-dark"><?php echo count($rubrics); ?> criteria</span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if(count($rubrics) > 0): ?>
                        <?php foreach($rubrics as $index => $rubric): ?>
                        <div class="mb-4 p-3 border rounded bg-light">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-0 text-primary">
                                    <?php echo ($index + 1) . '. ' . htmlspecialchars($rubric['criterion_name']); ?>
                                </h6>
                                <span class="badge bg-secondary">Max: <?php echo $rubric['max_score']; ?> points</span>
                            </div>
                            
                            <?php if($rubric['description']): ?>
                            <p class="text-muted mb-3"><?php echo htmlspecialchars($rubric['description']); ?></p>
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="score_<?php echo $rubric['rubric_id']; ?>" class="form-label">
                                            <strong>Score</strong> (0 - <?php echo $rubric['max_score']; ?>)
                                        </label>
                                        <input type="number" class="form-control rubric-score" 
                                               id="score_<?php echo $rubric['rubric_id']; ?>" 
                                               name="score_<?php echo $rubric['rubric_id']; ?>" 
                                               min="0" max="<?php echo $rubric['max_score']; ?>" 
                                               step="0.1" required
                                               onchange="updateTotalScore()"
                                               placeholder="Enter score">
                                        <div class="form-text">Must be between 0 and <?php echo $rubric['max_score']; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="feedback_<?php echo $rubric['rubric_id']; ?>" class="form-label">
                                            <strong>Specific Feedback</strong>
                                        </label>
                                        <textarea class="form-control" id="feedback_<?php echo $rubric['rubric_id']; ?>" 
                                                  name="feedback_<?php echo $rubric['rubric_id']; ?>" rows="3"
                                                  placeholder="Provide constructive feedback for this specific criterion..."></textarea>
                                        <div class="form-text">Be specific about what was done well and what could be improved.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- Total Score Display -->
                        <div class="mb-3 p-3 border rounded bg-info text-white">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h6 class="mb-1">Overall Score Summary</h6>
                                    <div class="d-flex align-items-center">
                                        <div id="totalScoreDisplay" class="h4 mb-0 me-3">0 / 0</div>
                                        <div id="percentageScore" class="h5 mb-0">0%</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="progress" style="height: 20px;">
                                        <div id="scoreProgress" class="progress-bar" role="progressbar" style="width: 0%">
                                            <span id="progressText" class="px-2">0%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            No rubric criteria have been set up for this assignment. Please contact your instructor.
                        </div>
                    <?php endif; ?>
                    
                    <!-- Overall Feedback -->
                    <div class="mb-3">
                        <label for="overall_feedback" class="form-label">
                            <strong>Overall Feedback</strong> <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="overall_feedback" name="overall_feedback" rows="5" required
                                  placeholder="Provide comprehensive overall feedback. What are the main strengths? What areas need improvement? Be constructive and specific..."></textarea>
                        <div class="form-text">
                            <span id="feedbackCount">0</span> characters (minimum 20 required). 
                            Provide balanced feedback that highlights both strengths and areas for improvement.
                        </div>
                    </div>
                    
                    <!-- Submission Warning -->
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Important:</strong> Once submitted, you cannot modify this review. 
                        Please double-check all scores and feedback before submitting.
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="peer_reviews.php" class="btn btn-secondary me-md-2">Cancel</a>
                        <button type="submit" name="submit_review" class="btn btn-success btn-lg" 
                                <?php echo count($rubrics) === 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-check-circle"></i> Submit Final Review
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Sidebar -->
    <div class="col-md-4">
        <!-- Review Guidelines -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0">
                    <i class="fas fa-lightbulb"></i> Review Guidelines
                </h5>
            </div>
            <div class="card-body">
                <h6>How to provide effective feedback:</h6>
                <ul class="small">
                    <li><strong>Be constructive:</strong> Focus on improvement, not just criticism</li>
                    <li><strong>Be specific:</strong> Point to exact parts of the submission</li>
                    <li><strong>Be balanced:</strong> Mention both strengths and weaknesses</li>
                    <li><strong>Be professional:</strong> Respectful and objective tone</li>
                    <li><strong>Be actionable:</strong> Suggest concrete improvements</li>
                </ul>
                
                <div class="alert alert-info mt-3">
                    <small>
                        <strong>Remember:</strong> Your feedback should help your peer improve their work. 
                        Quality reviews benefit everyone in the learning process.
                    </small>
                </div>
            </div>
        </div>

        <!-- Review Information -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Review Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Assignment:</strong> <?php echo htmlspecialchars($review['assignment_title']); ?></p>
                <p><strong>Course:</strong> <?php echo htmlspecialchars($review['course_title']); ?></p>
                <p><strong>Student Being Reviewed:</strong> <?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></p>
                <p><strong>Review Assigned:</strong> <?php echo date('M j, Y', strtotime($review['review_date'])); ?></p>
                <p><strong>Status:</strong> <span class="badge bg-warning">In Progress</span></p>
                
                <hr>
                
                <div class="alert alert-light">
                    <small>
                        <i class="fas fa-info-circle"></i>
                        This review will be <?php echo $review['is_anonymous'] ? 'anonymous' : 'visible with your name'; ?> to the student.
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Update total score calculation
function updateTotalScore() {
    let totalScore = 0;
    let maxScore = 0;
    
    // Calculate scores
    document.querySelectorAll('.rubric-score').forEach(input => {
        const score = parseFloat(input.value) || 0;
        const max = parseFloat(input.max) || 0;
        totalScore += score;
        maxScore += max;
    });
    
    // Update display
    document.getElementById('totalScoreDisplay').textContent = totalScore.toFixed(1) + ' / ' + maxScore.toFixed(1);
    
    // Update percentage and progress bar
    const percentage = maxScore > 0 ? (totalScore / maxScore) * 100 : 0;
    document.getElementById('percentageScore').textContent = percentage.toFixed(1) + '%';
    
    const progressBar = document.getElementById('scoreProgress');
    const progressText = document.getElementById('progressText');
    progressBar.style.width = percentage + '%';
    progressText.textContent = percentage.toFixed(1) + '%';
    
    // Update progress bar color based on percentage
    if(percentage >= 80) {
        progressBar.className = 'progress-bar bg-success';
    } else if(percentage >= 60) {
        progressBar.className = 'progress-bar bg-info';
    } else if(percentage >= 40) {
        progressBar.className = 'progress-bar bg-warning';
    } else {
        progressBar.className = 'progress-bar bg-danger';
    }
}

// Character count for overall feedback
document.getElementById('overall_feedback').addEventListener('input', function() {
    const count = this.value.length;
    document.getElementById('feedbackCount').textContent = count;
    
    // Update character count color
    const countElement = document.getElementById('feedbackCount');
    if(count < 20) {
        countElement.className = 'text-danger';
    } else if(count < 50) {
        countElement.className = 'text-warning';
    } else {
        countElement.className = 'text-success';
    }
});

// Form validation
document.getElementById('reviewForm').addEventListener('submit', function(e) {
    const overallFeedback = document.getElementById('overall_feedback').value.trim();
    
    // Check minimum feedback length
    if(overallFeedback.length < 20) {
        e.preventDefault();
        alert('Overall feedback must be at least 20 characters long. Currently: ' + overallFeedback.length + ' characters.');
        document.getElementById('overall_feedback').focus();
        return false;
    }
    
    // Check all rubric scores are provided
    let allScoresProvided = true;
    document.querySelectorAll('.rubric-score').forEach(input => {
        if(!input.value || input.value === '') {
            allScoresProvided = false;
            input.classList.add('is-invalid');
        } else {
            input.classList.remove('is-invalid');
        }
    });
    
    if(!allScoresProvided) {
        e.preventDefault();
        alert('Please provide scores for all rubric criteria.');
        return false;
    }
    
    // Final confirmation
    if(!confirm('Are you sure you want to submit this review? You cannot make changes after submission.')) {
        e.preventDefault();
        return false;
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateTotalScore();
    
    // Add input validation for rubric scores
    document.querySelectorAll('.rubric-score').forEach(input => {
        input.addEventListener('blur', function() {
            const value = parseFloat(this.value);
            const max = parseFloat(this.max);
            
            if(value < 0 || value > max) {
                this.classList.add('is-invalid');
                alert('Score must be between 0 and ' + max);
            } else {
                this.classList.remove('is-invalid');
            }
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
