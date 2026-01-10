<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('assignments.php');
}

$submission_id = $_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get submission details with assignment and student info
$stmt = $conn->prepare("SELECT s.*, a.*, u.first_name, u.last_name, u.username, u.email,
                       m.title as module_title, c.title as course_title, c.course_id, c.instructor_id
                       FROM submissions s
                       JOIN assignments a ON s.assignment_id = a.assignment_id
                       JOIN users u ON s.student_id = u.user_id
                       JOIN modules m ON a.module_id = m.module_id
                       JOIN courses c ON m.course_id = c.course_id
                       WHERE s.submission_id = ?");
$stmt->execute([$submission_id]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$submission || $submission['instructor_id'] != $_SESSION['user_id']) {
    $auth->redirect('assignments.php');
}

// Get peer reviews for this submission
$stmt = $conn->prepare("SELECT pr.*, u.first_name, u.last_name, u.username
                       FROM peer_reviews pr
                       JOIN users u ON pr.reviewer_id = u.user_id
                       WHERE pr.submission_id = ?
                       ORDER BY pr.review_date DESC");
$stmt->execute([$submission_id]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Submission Details</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="assignment_submissions.php?id=<?php echo $submission['assignment_id']; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Submissions
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Student Work</h5>
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <h6>Student Information</h6>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($submission['email']); ?></p>
                    <p><strong>Submitted:</strong> <?php echo date('M j, Y g:i A', strtotime($submission['submission_date'])); ?></p>
                    <?php if($submission['due_date'] && strtotime($submission['submission_date']) > strtotime($submission['due_date'])): ?>
                        <p><strong class="text-danger">Status: Late Submission</strong></p>
                    <?php endif; ?>
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
                    <a href="<?php echo htmlspecialchars($submission['file_path']); ?>" target="_blank" class="btn btn-outline-primary">
                        <i class="fas fa-download"></i> Download File: <?php echo htmlspecialchars($submission['file_name']); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Peer Reviews Section -->
        <?php if(count($reviews) > 0): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-comments"></i> Peer Reviews
                    <span class="badge bg-primary"><?php echo count($reviews); ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php foreach($reviews as $review): ?>
                <div class="border-bottom pb-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6>
                            Review by: 
                            <?php if($review['is_anonymous']): ?>
                                <span class="text-muted">Anonymous</span>
                            <?php else: ?>
                                <?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?>
                            <?php endif; ?>
                        </h6>
                        <small class="text-muted"><?php echo date('M j, Y', strtotime($review['review_date'])); ?></small>
                    </div>
                    
                    <?php if($review['overall_feedback']): ?>
                    <p><strong>Overall Feedback:</strong></p>
                    <div class="border p-3 bg-light">
                        <?php echo nl2br(htmlspecialchars($review['overall_feedback'])); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mt-2">
                        <span class="badge bg-<?php echo $review['status'] == 'completed' ? 'success' : 'warning'; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $review['status'])); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Assignment Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Course:</strong> <?php echo htmlspecialchars($submission['course_title']); ?></p>
                <p><strong>Assignment:</strong> <?php echo htmlspecialchars($submission['title']); ?></p>
                <p><strong>Due Date:</strong> 
                    <?php echo $submission['due_date'] ? date('M j, Y g:i A', strtotime($submission['due_date'])) : 'No due date'; ?>
                </p>
                <p><strong>Max Points:</strong> <?php echo htmlspecialchars($submission['max_points']); ?></p>
                
                <hr>
                
                <div class="mb-3">
                    <label class="form-label"><strong>Current Grade</strong></label>
                    <?php if($submission['final_grade'] !== null): ?>
                        <h4 class="text-success"><?php echo htmlspecialchars($submission['final_grade']); ?>/<?php echo htmlspecialchars($submission['max_points']); ?></h4>
                    <?php else: ?>
                        <p class="text-muted">Not graded yet</p>
                    <?php endif; ?>
                </div>
                
                <?php if($submission['instructor_feedback']): ?>
                <div class="mb-3">
                    <label class="form-label"><strong>Your Feedback</strong></label>
                    <div class="border p-2 bg-light">
                        <?php echo nl2br(htmlspecialchars($submission['instructor_feedback'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <a href="assignment_submissions.php?id=<?php echo $submission['assignment_id']; ?>" 
                   class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#gradeModal">
                    <i class="fas fa-edit"></i> Update Grade & Feedback
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Grade Modal -->
<div class="modal fade" id="gradeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Grade Submission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="assignment_submissions.php">
                <div class="modal-body">
                    <p><strong>Student:</strong> <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></p>
                    
                    <div class="mb-3">
                        <label for="grade" class="form-label">Grade (0 - <?php echo $submission['max_points']; ?>)</label>
                        <input type="number" class="form-control" id="grade" name="grade" 
                               value="<?php echo $submission['final_grade'] ?? ''; ?>" 
                               min="0" max="<?php echo $submission['max_points']; ?>" step="0.5" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="feedback" class="form-label">Instructor Feedback</label>
                        <textarea class="form-control" id="feedback" name="feedback" rows="4"><?php echo htmlspecialchars($submission['instructor_feedback'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="submission_id" value="<?php echo $submission_id; ?>">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_grade" class="btn btn-primary">Save Grade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>