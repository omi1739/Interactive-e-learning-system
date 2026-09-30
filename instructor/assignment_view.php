<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('assignments.php');
}

$assignment_id = intval($_GET['id'] ?? 0);
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

// Edit the assignment.
//
// The page has already proven this instructor owns the assignment, so the
// update is scoped to the id rather than re-deriving ownership per field.
// Values are validated against the column definitions, because MySQL in strict
// mode would otherwise reject the whole statement with a generic error.
if($_POST && isset($_POST['update_assignment'])) {
    verify_csrf();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    // Read from $_POST only: $_REQUEST would let a query string value shadow
    // the submitted body.
    $module_id = param_int('module_id', 0, $_POST);
    $max_points = trim($_POST['max_points'] ?? '');
    $due_date = mysql_datetime($_POST['due_date'] ?? '');
    $assignment_type = $_POST['assignment_type'] ?? 'individual';
    $submission_format = $_POST['submission_format'] ?? 'file';
    $max_file_size = param_int('max_file_size', 0, $_POST);
    $allowed_file_types = trim($_POST['allowed_file_types'] ?? '');
    $is_published = isset($_POST['is_published']) ? 1 : 0;

    $errors = [];

    if($title === '' || mb_strlen($title) > 200) {
        $errors[] = 'Title is required and must be 200 characters or fewer.';
    }
    if($module_id <= 0) {
        $errors[] = 'Choose a module for this assignment.';
    } elseif(!instructor_owns_module($conn, $module_id, $_SESSION['user_id'])) {
        $errors[] = 'That module does not belong to one of your courses.';
    }
    if($max_points === '' || !is_numeric($max_points) || (float)$max_points <= 0 || (float)$max_points > 100000) {
        $errors[] = 'Max points must be a positive number.';
    }
    if(!in_array($assignment_type, ['individual', 'group'], true)) {
        $errors[] = 'Invalid assignment type.';
    }
    if(!in_array($submission_format, ['text', 'file', 'both'], true)) {
        $errors[] = 'Invalid submission format.';
    }
    if($max_file_size < 1 || $max_file_size > 100) {
        $errors[] = 'Maximum file size must be between 1 and 100 MB.';
    }
    if($allowed_file_types !== '' && !valid_extension_list($allowed_file_types)) {
        // Stored as a comma list and matched with LIKE, so keep it to plain
        // extensions rather than free text that could widen the match.
        $errors[] = 'File types must be a comma-separated list of extensions, without dots.';
    }

    if(!empty($errors)) {
        $error = implode(' ', $errors);
    } else {
        try {
            $stmt = $conn->prepare("UPDATE assignments
                                    SET title = ?, description = ?, module_id = ?, max_points = ?, due_date = ?,
                                        assignment_type = ?, submission_format = ?, max_file_size = ?,
                                        allowed_file_types = ?, is_published = ?
                                    WHERE assignment_id = ?");
            $stmt->execute([
                $title,
                $description !== '' ? $description : null,
                $module_id,
                $max_points,
                $due_date !== '' ? $due_date : null,
                $assignment_type,
                $submission_format,
                $max_file_size,
                $allowed_file_types !== '' ? $allowed_file_types : null,
                $is_published,
                $assignment_id
            ]);

            flash_success("Assignment updated.");
            header("Location: assignment_view.php?id=" . (int)$assignment_id);
            exit();
        } catch(PDOException $e) {
            error_log("Assignment update failed: " . $e->getMessage());
            $error = "The assignment could not be saved. Check the values and try again.";
        }
    }
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
    verify_csrf();
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
    verify_csrf();
    $submission_id = intval($_POST['submission_id'] ?? 0);
    $reviewer_id = intval($_POST['reviewer_id'] ?? 0);

    // Both ids arrive from the form. Check that the submission really belongs
    // to this assignment and that the reviewer is an approved enrolled student
    // of this course; otherwise one instructor could create reviews in another
    // teacher's course, or let a non-participant grade work.
    $submission_ok = false;
    if($submission_id > 0) {
        $s = $conn->prepare("SELECT submission_id FROM submissions WHERE submission_id = ? AND assignment_id = ?");
        $s->execute([$submission_id, $assignment_id]);
        $submission_ok = (bool)$s->fetch();
    }

    $reviewer_ok = false;
    if($reviewer_id > 0) {
        $r = $conn->prepare("SELECT e.user_id
                             FROM enrollments e
                             WHERE e.course_id = ? AND e.user_id = ? AND e.enrollment_status = 'approved'");
        $r->execute([$assignment['course_id'], $reviewer_id]);
        $reviewer_ok = (bool)$r->fetch();
    }

    if(!$submission_ok) {
        $error = "That submission does not belong to this assignment.";
    } elseif(!$reviewer_ok) {
        $error = "The selected reviewer is not an approved student in this course.";
    } else {
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
        }   // end: check_stmt else
    }       // end: submission/reviewer validation else
}           // end: POST handler

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

// Get review statistics.
//
// These are two separate aggregates on purpose. Joining review_scores into the
// same query as peer_reviews fans the rows out, so a review covering four
// criteria used to be counted four times in both COUNT(*) and the SUM(CASE...).
$stmt = $conn->prepare("SELECT
        COUNT(*) AS total_reviews,
        SUM(CASE WHEN pr.status = 'completed' THEN 1 ELSE 0 END) AS completed_reviews
    FROM peer_reviews pr
    JOIN submissions s ON pr.submission_id = s.submission_id
    WHERE s.assignment_id = ?");
$stmt->execute([$assignment_id]);
$review_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_reviews' => 0, 'completed_reviews' => 0];

// Average score per completed review: sum each review's criteria first, then
// average those totals. Averaging the criteria marks directly would let a
// one-criterion review count the same as a five-criterion one.
$stmt = $conn->prepare("SELECT AVG(per_review.total_score) FROM (
        SELECT SUM(rs.score) AS total_score
        FROM peer_reviews pr
        JOIN submissions s ON pr.submission_id = s.submission_id
        JOIN review_scores rs ON rs.review_id = pr.review_id
        WHERE s.assignment_id = ? AND pr.status = 'completed'
        GROUP BY pr.review_id
    ) AS per_review");
$stmt->execute([$assignment_id]);
$review_stats['avg_score'] = $stmt->fetchColumn();
$review_stats['avg_score'] = $review_stats['avg_score'] === null ? null : (float)$review_stats['avg_score'];

// What that average is out of, so it can be shown as a percentage.
$stmt = $conn->prepare("SELECT COALESCE(SUM(max_score), 0) FROM rubrics WHERE assignment_id = ?");
$stmt->execute([$assignment_id]);
$review_stats['rubric_total'] = (float)$stmt->fetchColumn();

// Modules the instructor can move this assignment into, for the edit dialog.
$stmt = $conn->prepare("SELECT m.module_id, m.title, c.title AS course_title
                        FROM modules m
                        JOIN courses c ON m.course_id = c.course_id
                        WHERE c.instructor_id = ?
                        ORDER BY c.title, m.module_order, m.title");
$stmt->execute([$_SESSION['user_id']]);
$own_modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <?php echo e($success); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if(isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php echo e($error); ?>
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
                            <?php echo csrf_field(); ?>
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
                            <?php echo csrf_field(); ?>
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
                                <h4>
                                    <?php
                                    if($review_stats['avg_score'] !== null && $review_stats['rubric_total'] > 0) {
                                        $peer_pct = ($review_stats['avg_score'] / $review_stats['rubric_total']) * 100;
                                        echo ui_num($peer_pct, 0) . '%';
                                    } else {
                                        echo '<span class="text-muted">&mdash;</span>';
                                    }
                                    ?>
                                </h4>
                                <p class="mb-0">
                                    Avg Peer Score
                                    <?php if($review_stats['rubric_total'] > 0): ?>
                                        <small class="text-muted">of <?php echo ui_num($review_stats['rubric_total'], 2); ?> rubric pts</small>
                                    <?php endif; ?>
                                </p>
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
                <button type="button" class="btn btn-outline-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#editAssignmentModal">
                    <i class="fas fa-edit"></i> Edit Assignment
                </button>
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
<div class="modal fade" id="editAssignmentModal" tabindex="-1" aria-labelledby="editAssignmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editAssignmentModalLabel">Edit Assignment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editAssignmentForm" data-bs-dismiss="modal">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_title" class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_title" name="title" maxlength="200" required
                               value="<?php echo e_attr($assignment['title']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="5"
                                  data-counter="#editDescriptionCount"><?php echo e($assignment['description'] ?? ''); ?></textarea>
                        <div class="form-text"><span id="editDescriptionCount">0</span> characters</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_module_id" class="form-label">Module <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_module_id" name="module_id" required>
                                <?php if(empty($own_modules)): ?>
                                    <option value="">You have no modules yet</option>
                                <?php endif; ?>
                                <?php foreach($own_modules as $mod): ?>
                                    <option value="<?php echo (int)$mod['module_id']; ?>"
                                        <?php echo (int)$mod['module_id'] === (int)$assignment['module_id'] ? 'selected' : ''; ?>>
                                        <?php echo e($mod['course_title'] . ' - ' . $mod['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Moving an assignment does not move its submissions.</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_assignment_type" class="form-label">Type</label>
                            <select class="form-select" id="edit_assignment_type" name="assignment_type">
                                <option value="individual" <?php echo $assignment['assignment_type'] === 'individual' ? 'selected' : ''; ?>>Individual</option>
                                <option value="group" <?php echo $assignment['assignment_type'] === 'group' ? 'selected' : ''; ?>>Group</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_max_points" class="form-label">Max points <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit_max_points" name="max_points" min="0.5" step="0.5" required
                                   value="<?php echo e_attr($assignment['max_points']); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_due_date" class="form-label">Due date</label>
                            <input type="datetime-local" class="form-control" id="edit_due_date" name="due_date"
                                   value="<?php echo e_attr(mysql_datetime_input($assignment['due_date'] ?? '')); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_submission_format" class="form-label">Submission format</label>
                            <select class="form-select" id="edit_submission_format" name="submission_format">
                                <option value="file" <?php echo $assignment['submission_format'] === 'file' ? 'selected' : ''; ?>>File upload only</option>
                                <option value="text" <?php echo $assignment['submission_format'] === 'text' ? 'selected' : ''; ?>>Text only</option>
                                <option value="both" <?php echo $assignment['submission_format'] === 'both' ? 'selected' : ''; ?>>Text and/or file</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_max_file_size" class="form-label">Max file size (MB)</label>
                            <input type="number" class="form-control" id="edit_max_file_size" name="max_file_size"
                                   min="1" max="100" step="1" value="<?php echo (int)$assignment['max_file_size']; ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_allowed_file_types" class="form-label">Allowed file extensions</label>
                        <input type="text" class="form-control" id="edit_allowed_file_types" name="allowed_file_types"
                               value="<?php echo e_attr($assignment['allowed_file_types'] ?? ''); ?>"
                               placeholder="pdf,doc,docx,zip,txt">
                        <div class="form-text">Comma separated, no dots. Leave empty to allow the server default.</div>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="edit_is_published" name="is_published" value="1"
                               <?php echo $assignment['is_published'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="edit_is_published">
                            Published &mdash; students can see and submit to this assignment
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_assignment" value="1" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save changes
                    </button>
                </div>
            </form>
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
                <?php echo csrf_field(); ?>
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
