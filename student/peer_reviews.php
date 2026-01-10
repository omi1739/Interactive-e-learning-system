<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

$conn = $db->getConnection();

// Get reviews to complete - improved query
$stmt = $conn->prepare("SELECT pr.*, 
                               s.submission_id, s.submission_text, s.file_path, s.file_name,
                               a.title as assignment_title, 
                               a.assignment_id, a.max_points,
                               u.first_name, u.last_name, 
                               c.title as course_title,
                               c.course_id
                        FROM peer_reviews pr
                        JOIN submissions s ON pr.submission_id = s.submission_id
                        JOIN assignments a ON s.assignment_id = a.assignment_id
                        JOIN modules m ON a.module_id = m.module_id
                        JOIN courses c ON m.course_id = c.course_id
                        JOIN users u ON s.student_id = u.user_id
                        WHERE pr.reviewer_id = ? 
                        ORDER BY pr.status ASC, pr.review_date DESC");
$stmt->execute([$_SESSION['user_id']]);
$all_reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate pending and completed reviews
$pending_reviews = array_filter($all_reviews, function($review) {
    return $review['status'] == 'in_progress';
});

$completed_reviews = array_filter($all_reviews, function($review) {
    return $review['status'] == 'completed';
});

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Peer Reviews</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <span class="badge bg-primary"><?php echo count($pending_reviews); ?> pending</span>
    </div>
</div>

<?php if(empty($pending_reviews) && empty($completed_reviews)): ?>
<div class="alert alert-info">
    <h5><i class="fas fa-info-circle"></i> No Peer Reviews Assigned</h5>
    <p class="mb-0">You don't have any peer reviews to complete at the moment. Reviews are automatically assigned when other students submit their work.</p>
</div>
<?php endif; ?>

<div class="row">
    <!-- Pending Reviews -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0">
                    <i class="fas fa-clock"></i> Pending Reviews
                    <span class="badge bg-dark"><?php echo count($pending_reviews); ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php if(count($pending_reviews) > 0): ?>
                    <?php foreach($pending_reviews as $review): ?>
                    <div class="border-bottom pb-3 mb-3">
                        <h6 class="text-primary"><?php echo htmlspecialchars($review['assignment_title']); ?></h6>
                        <p class="mb-1"><small><strong>Course:</strong> <?php echo htmlspecialchars($review['course_title']); ?></small></p>
                        <p class="mb-1"><small><strong>Student:</strong> <?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></small></p>
                        <p class="mb-2"><small><strong>Assigned:</strong> <?php echo date('M j, Y', strtotime($review['review_date'])); ?></small></p>
                        
                        <!-- Preview submission content -->
                        <?php if($review['submission_text']): ?>
                            <div class="mb-2">
                                <small><strong>Submission Preview:</strong></small>
                                <div class="border p-2 bg-light small">
                                    <?php echo substr(strip_tags($review['submission_text']), 0, 100); ?>...
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="d-flex gap-2">
                            <a href="review_complete.php?id=<?php echo $review['review_id']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-edit"></i> Complete Review
                            </a>
                            <a href="submission_preview.php?id=<?php echo $review['submission_id']; ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-eye"></i> View Full Submission
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-3">
                        <i class="fas fa-check-circle fa-2x text-muted mb-2"></i>
                        <p class="text-muted mb-0">No pending peer reviews</p>
                        <small class="text-muted">You're all caught up!</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Completed Reviews -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-check-circle"></i> Completed Reviews
                    <span class="badge bg-light text-dark"><?php echo count($completed_reviews); ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php if(count($completed_reviews) > 0): ?>
                    <?php foreach($completed_reviews as $review): ?>
                    <div class="border-bottom pb-3 mb-3">
                        <h6 class="text-success"><?php echo htmlspecialchars($review['assignment_title']); ?></h6>
                        <p class="mb-1"><small><strong>Course:</strong> <?php echo htmlspecialchars($review['course_title']); ?></small></p>
                        <p class="mb-1"><small><strong>Student:</strong> <?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></small></p>
                        <p class="mb-2"><small><strong>Completed:</strong> <?php echo date('M j, Y', strtotime($review['review_date'])); ?></small></p>
                        
                        <?php if($review['overall_feedback']): ?>
                            <div class="mb-2">
                                <small><strong>Your Feedback:</strong></small>
                                <div class="border p-2 bg-light small">
                                    <?php echo substr(htmlspecialchars($review['overall_feedback']), 0, 100); ?>...
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="d-flex gap-2">
                            <a href="review_view.php?id=<?php echo $review['review_id']; ?>" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-eye"></i> View Review
                            </a>
                            <?php if($review['submission_text'] || $review['file_path']): ?>
                                <a href="submission_preview.php?id=<?php echo $review['submission_id']; ?>" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-file"></i> View Submission
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-3">
                        <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                        <p class="text-muted mb-0">No completed reviews yet</p>
                        <small class="text-muted">Complete your first review to see it here</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Statistics Card -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Review Statistics</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h4 class="text-primary"><?php echo count($all_reviews); ?></h4>
                        <p class="text-muted">Total Reviews</p>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-warning"><?php echo count($pending_reviews); ?></h4>
                        <p class="text-muted">Pending</p>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-success"><?php echo count($completed_reviews); ?></h4>
                        <p class="text-muted">Completed</p>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-info"><?php echo count($completed_reviews) > 0 ? round((count($completed_reviews) / count($all_reviews)) * 100) : 0; ?>%</h4>
                        <p class="text-muted">Completion Rate</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>