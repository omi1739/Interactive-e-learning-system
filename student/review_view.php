<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

$review_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if(!$review_id) {
    $_SESSION['error'] = "Invalid review ID.";
    $auth->redirect('peer_reviews.php');
}

$conn = $db->getConnection();

// Fetch review details: either user is the reviewer, OR user is the author of the submission being reviewed
$stmt = $conn->prepare("SELECT pr.*, 
                               s.submission_id, s.student_id, s.submission_text, s.file_path, s.file_name, s.submission_date,
                               a.assignment_id, a.title as assignment_title, a.max_points, a.description as assignment_description,
                               c.course_id, c.title as course_title,
                               author.first_name as author_first_name, author.last_name as author_last_name,
                               reviewer.first_name as reviewer_first_name, reviewer.last_name as reviewer_last_name
                        FROM peer_reviews pr
                        JOIN submissions s ON pr.submission_id = s.submission_id
                        JOIN assignments a ON s.assignment_id = a.assignment_id
                        JOIN modules m ON a.module_id = m.module_id
                        JOIN courses c ON m.course_id = c.course_id
                        JOIN users author ON s.student_id = author.user_id
                        JOIN users reviewer ON pr.reviewer_id = reviewer.user_id
                        WHERE pr.review_id = ?");
$stmt->execute([$review_id]);
$review = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$review) {
    $_SESSION['error'] = "Review not found.";
    $auth->redirect('peer_reviews.php');
}

// Security: User must be either the reviewer or the submission author (or instructor/admin)
$is_reviewer = ($review['reviewer_id'] == $_SESSION['user_id']);
$is_author = ($review['student_id'] == $_SESSION['user_id']);

if(!$is_reviewer && !$is_author) {
    $_SESSION['error'] = "You are not authorized to view this review.";
    $auth->redirect('peer_reviews.php');
}

// Fetch rubric scores and criteria
$stmt = $conn->prepare("SELECT r.*, rs.score, rs.feedback as score_feedback
                       FROM rubrics r
                       LEFT JOIN review_scores rs ON r.rubric_id = rs.rubric_id AND rs.review_id = ?
                       WHERE r.assignment_id = ?
                       ORDER BY r.rubric_order ASC");
$stmt->execute([$review_id, $review['assignment_id']]);
$rubrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_score_awarded = 0;
$total_score_possible = 0;
foreach($rubrics as $rubric) {
    $total_score_possible += (float)$rubric['max_score'];
    if($rubric['score'] !== null) {
        $total_score_awarded += (float)$rubric['score'];
    }
}
$score_percentage = ($total_score_possible > 0) ? round(($total_score_awarded / $total_score_possible) * 100, 1) : 0;

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2">Peer Review Details</h1>
        <p class="text-muted mb-0">
            <?php echo e($is_reviewer ? 'Review you conducted for a peer' : 'Feedback you received on your submission'); ?>
        </p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="peer_reviews.php" class="btn btn-outline-secondary me-2">
            <i class="fas fa-arrow-left me-1"></i> Back to Reviews
        </a>
        <a href="assignment_view.php?id=<?php echo $review['assignment_id']; ?>" class="btn btn-primary">
            <i class="fas fa-tasks me-1"></i> View Assignment
        </a>
    </div>
</div>

<div class="row">
    <!-- Left Column: Review Results & Rubrics -->
    <div class="col-lg-8">
        
        <!-- Score Summary Banner -->
        <div class="card mb-4 border-0 shadow-sm <?php echo $review['status'] == 'completed' ? 'bg-light' : 'border-warning'; ?>">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-<?php echo $review['status'] == 'completed' ? 'success' : 'warning text-dark'; ?> me-2 px-3 py-2">
                                <i class="fas <?php echo $review['status'] == 'completed' ? 'fa-check-circle' : 'fa-clock'; ?> me-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $review['status'])); ?>
                            </span>
                            <span class="text-muted small">
                                <i class="fas fa-calendar-alt me-1"></i>
                                <?php echo date('M j, Y g:i A', strtotime($review['review_date'])); ?>
                            </span>
                        </div>
                        <h4 class="mb-1 fw-bold"><?php echo htmlspecialchars($review['assignment_title']); ?></h4>
                        <p class="text-muted mb-0"><i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($review['course_title']); ?></p>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <?php if($review['status'] == 'completed'): ?>
                            <div class="display-6 fw-bold text-primary"><?php echo $total_score_awarded; ?> <span class="fs-6 text-muted">/ <?php echo $total_score_possible; ?> pts</span></div>
                            <span class="badge bg-info text-dark fs-6"><?php echo $score_percentage; ?>%</span>
                        <?php else: ?>
                            <span class="text-warning fw-bold"><i class="fas fa-hourglass-half me-1"></i> Awaiting Completion</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overall Feedback Card -->
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold">
                    <i class="fas fa-comment-dots text-primary me-2"></i> Overall Feedback
                </h5>
            </div>
            <div class="card-body p-4">
                <?php if($review['overall_feedback']): ?>
                    <div class="p-3 bg-light rounded-3 border-start border-4 border-primary">
                        <p class="mb-0" style="white-space: pre-wrap;"><?php echo htmlspecialchars($review['overall_feedback']); ?></p>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0 fst-italic">No general comments provided yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rubric Breakdown -->
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fw-bold">
                    <i class="fas fa-clipboard-check text-success me-2"></i> Rubric Evaluation Breakdown
                </h5>
                <span class="badge bg-secondary"><?php echo count($rubrics); ?> Criteria</span>
            </div>
            <div class="card-body p-4">
                <?php if(count($rubrics) > 0): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach($rubrics as $index => $rubric): ?>
                        <div class="list-group-item px-0 py-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="mb-1 fw-bold text-dark">
                                        <?php echo ($index + 1) . '. ' . htmlspecialchars($rubric['criterion_name']); ?>
                                    </h6>
                                    <?php if($rubric['description']): ?>
                                        <p class="text-muted small mb-2"><?php echo htmlspecialchars($rubric['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-primary fs-6 px-3 py-2">
                                        <?php echo ($rubric['score'] !== null) ? htmlspecialchars($rubric['score']) : '-'; ?> / <?php echo htmlspecialchars($rubric['max_score']); ?> pts
                                    </span>
                                </div>
                            </div>
                            <?php if(!empty($rubric['score_feedback'])): ?>
                                <div class="bg-light p-3 rounded-2 mt-2 small">
                                    <strong class="text-secondary"><i class="fas fa-quote-left me-1"></i> Reviewer Criterion Comment:</strong>
                                    <p class="mb-0 mt-1"><?php echo htmlspecialchars($rubric['score_feedback']); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No specific rubric criteria were defined for this assignment.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Right Column: Submission Information & Metadata -->
    <div class="col-lg-4">
        
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold">
                    <i class="fas fa-info-circle text-primary me-2"></i> Review Context
                </h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-3">
                        <small class="text-muted d-block">Reviewed Submission Author</small>
                        <?php if($is_reviewer): ?>
                            <strong><?php echo htmlspecialchars($review['author_first_name'] . ' ' . $review['author_last_name']); ?></strong>
                        <?php else: ?>
                            <strong class="text-primary">You (Your Submission)</strong>
                        <?php endif; ?>
                    </li>

                    <li class="mb-3">
                        <small class="text-muted d-block">Reviewer Identity</small>
                        <?php if($is_reviewer): ?>
                            <strong class="text-primary">You (Self)</strong>
                        <?php else: ?>
                            <?php if($review['is_anonymous']): ?>
                                <span class="badge bg-secondary"><i class="fas fa-user-secret me-1"></i> Anonymous Peer</span>
                            <?php else: ?>
                                <strong><?php echo htmlspecialchars($review['reviewer_first_name'] . ' ' . $review['reviewer_last_name']); ?></strong>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>

                    <li class="mb-3">
                        <small class="text-muted d-block">Submission Date</small>
                        <span><?php echo date('M j, Y g:i A', strtotime($review['submission_date'])); ?></span>
                    </li>

                    <li class="mb-0">
                        <small class="text-muted d-block">Review Status</small>
                        <span class="badge bg-<?php echo $review['status'] == 'completed' ? 'success' : 'warning text-dark'; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $review['status'])); ?>
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Submission Content Preview -->
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold">
                    <i class="fas fa-file-alt text-primary me-2"></i> Submitted Content
                </h5>
            </div>
            <div class="card-body">
                <?php if($review['submission_text']): ?>
                    <h6 class="text-secondary small fw-bold">Text Submission:</h6>
                    <div class="p-3 bg-light rounded-3 small mb-3 border" style="max-height: 200px; overflow-y: auto;">
                        <?php echo nl2br(htmlspecialchars($review['submission_text'])); ?>
                    </div>
                <?php endif; ?>

                <?php if($review['file_path']): ?>
                    <h6 class="text-secondary small fw-bold">Attached File:</h6>
                    <a href="../download.php?submission_id=<?php echo (int)$review['submission_id']; ?>&amp;review_id=<?php echo (int)$review['review_id']; ?>" class="btn btn-outline-primary btn-sm w-100">
                        <i class="fas fa-download me-1"></i> Download <?php echo htmlspecialchars($review['file_name']); ?>
                    </a>
                <?php endif; ?>

                <?php if(!$review['submission_text'] && !$review['file_path']): ?>
                    <p class="text-muted small mb-0">No submission files or text attached.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
