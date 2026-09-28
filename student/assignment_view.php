<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || $_SESSION['role'] != 'student') {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('assignments.php');
}

$assignment_id = $_GET['id'];
$conn = $db->getConnection();

// Get assignment details
$stmt = $conn->prepare("SELECT a.*, m.title as module_title, c.title as course_title, c.course_id
                       FROM assignments a
                       JOIN modules m ON a.module_id = m.module_id
                       JOIN courses c ON m.course_id = c.course_id
                       WHERE a.assignment_id = ?");
$stmt->execute([$assignment_id]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$assignment) {
    $_SESSION['error'] = "Assignment not found.";
    $auth->redirect('assignments.php');
}

// Check if student is enrolled
$stmt = $conn->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ? AND enrollment_status = 'approved'");
$stmt->execute([$_SESSION['user_id'], $assignment['course_id']]);
$enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$enrollment) {
    $_SESSION['error'] = "You are not enrolled in this course.";
    $auth->redirect('assignments.php');
}

// Get submission if exists
$stmt = $conn->prepare("SELECT * FROM submissions WHERE assignment_id = ? AND student_id = ?");
$stmt->execute([$assignment_id, $_SESSION['user_id']]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

// Error and success messages
$error = '';
$success = '';

// Handle submission
if($_POST && isset($_POST['submit_assignment'])) {
    verify_csrf();
    $submission_text = trim($_POST['submission_text'] ?? '');
    
    // Validate based on submission format
    $has_content = false;
    
    if(in_array($assignment['submission_format'], ['text', 'both']) && !empty($submission_text)) {
        $has_content = true;
    }
    
    if(in_array($assignment['submission_format'], ['file', 'both']) && isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] == 0) {
        $has_content = true;
    }
    
    if(!$has_content) {
        $error = "Please provide either text submission or upload a file as required by the assignment format.";
    } else {
        // Handle file upload
        $file_path = null;
        $file_name = null;
        
        if(isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] == 0) {
            // Uploads live in the directory configured in config/local.php,
            // which on GoogieHost sits OUTSIDE the web root. Nothing here is
            // ever reachable as a plain URL.
            $upload_dir = UPLOAD_DIR;
            if(!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }

            $upload_error = null;

            // 1. Size limit
            $max_file_size = ($assignment['max_file_size'] ?? 10) * 1024 * 1024;
            if($_FILES['submission_file']['size'] > $max_file_size) {
                $upload_error = "File size exceeds maximum allowed size of " . ($assignment['max_file_size'] ?? 10) . "MB.";
            } elseif($_FILES['submission_file']['size'] === 0) {
                $upload_error = "The uploaded file is empty.";
            } else {
                $original_name = basename($_FILES['submission_file']['name']);
                $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                // 2. Extension allowlist. This must ALWAYS be enforced: if the
                //    assignment defines no allowed types we fall back to a
                //    conservative default, otherwise a .php or .phtml upload
                //    would be accepted and could be executed by the server.
                $allowed_extensions = allowed_upload_extensions($assignment['allowed_file_types'] ?? '');
                if(!in_array($file_extension, $allowed_extensions, true)) {
                    $upload_error = "File type not allowed. Permitted types: " . implode(', ', $allowed_extensions) . ".";
                }
                // 3. Never allow double extensions that hide a script
                //    (e.g. "report.php.pdf") or any server-executable form.
                elseif(preg_match('/\.(php|phtml|phar|php[0-9]|cgi|pl|py|sh|htaccess)/i', $original_name)) {
                    $upload_error = "File type not allowed.";
                } else {
                    // 4. Confirm the real content type, so a renamed script
                    //    (evil.pdf containing PHP) is rejected too.
                    $detected = upload_detected_mime($_FILES['submission_file']['tmp_name']);
                    if($detected === null || !in_array($detected, allowed_upload_mimes(), true)) {
                        $upload_error = "File content does not match a permitted file type.";
                    }
                }

                if($upload_error === null) {
                    // Random stored name; the original name is kept only as
                    // display metadata in the database.
                    $safe_base = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);
                    $stored_filename = bin2hex(random_bytes(16)) . '_' . $safe_base;
                    $target = rtrim($upload_dir, '/') . '/' . $stored_filename;

                    if(move_uploaded_file($_FILES['submission_file']['tmp_name'], $target)) {
                        @chmod($target, 0644);
                        // Store ONLY the stored filename. download.php resolves
                        // it against UPLOAD_DIR after an authorization check.
                        $file_path = $stored_filename;
                        $file_name = $original_name;
                    } else {
                        $upload_error = "Failed to save the uploaded file. Please try again.";
                    }
                }
            }

            if($upload_error !== null) {
                $error = $upload_error;
            }
        }
        
        if(empty($error)) {
            try {
                $conn->beginTransaction();
                
                if($submission) {
                    // Update existing submission
                    $stmt = $conn->prepare("UPDATE submissions SET submission_text = ?, file_path = ?, file_name = ?, submission_date = NOW(), status = 'submitted' WHERE submission_id = ?");
                    $result = $stmt->execute([
                        $submission_text, 
                        $file_path, 
                        $file_name, 
                        $submission['submission_id']
                    ]);
                } else {
                    // Create new submission
                    $stmt = $conn->prepare("INSERT INTO submissions (assignment_id, student_id, submission_text, file_path, file_name, submission_date, status) VALUES (?, ?, ?, ?, ?, NOW(), 'submitted')");
                    $result = $stmt->execute([
                        $assignment_id, 
                        $_SESSION['user_id'], 
                        $submission_text, 
                        $file_path, 
                        $file_name
                    ]);
                }
                
                if($result) {
                    $conn->commit();
                    $success = "Assignment submitted successfully!";
                    // Refresh the page to show updated submission
                    header("Location: assignment_view.php?id=" . $assignment_id . "&success=1");
                    exit();
                } else {
                    throw new Exception("Database operation failed");
                }
                
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Failed to submit assignment. Please try again.";
                error_log("Submission error: " . $e->getMessage());
            }
        }
    }
}

// If success parameter is set, show success message
if(isset($_GET['success']) && $_GET['success'] == 1) {
    $success = "Assignment submitted successfully!";
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($assignment['title']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <span class="badge bg-primary me-2">Course: <?php echo htmlspecialchars($assignment['course_title']); ?></span>
        <span class="badge bg-secondary">Module: <?php echo htmlspecialchars($assignment['module_title']); ?></span>
    </div>
</div>

<!-- Display Messages -->
<?php if(!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo e($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if(!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo e($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if(isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Assignment submitted successfully!
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
                        <p><strong>Due Date:</strong>
                            <?php echo $assignment['due_date'] ? date('M j, Y g:i A', strtotime($assignment['due_date'])) : 'No due date'; ?>
                        </p>
                        <p><strong>Max Points:</strong> <?php echo htmlspecialchars($assignment['max_points']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Submission Format:</strong> <?php echo ucfirst($assignment['submission_format']); ?></p>
                        <p><strong>Type:</strong> <?php echo ucfirst($assignment['assignment_type']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Submit Assignment</h5>
            </div>
            <div class="card-body">
                <?php if($submission): ?>
                    <div class="alert alert-info">
                        <h6>Your Submission</h6>
                        <p><strong>Submitted on:</strong> <?php echo date('M j, Y g:i A', strtotime($submission['submission_date'])); ?></p>
                        <p><strong>Status:</strong> <span class="badge bg-<?php echo $submission['status'] == 'graded' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst($submission['status']); ?>
                            </span></p>

                        <?php if($submission['submission_text']): ?>
                            <p><strong>Text Submission:</strong></p>
                            <div class="border p-3 bg-light"><?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?></div>
                        <?php endif; ?>

                        <?php if($submission['file_path']): ?>
                            <p><strong>File:</strong>
                                <a href="../download.php?submission_id=<?php echo (int)$submission['submission_id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download"></i> Download File
                                </a>
                            </p>
                        <?php endif; ?>

                        <?php if($submission['instructor_feedback']): ?>
                            <p><strong>Instructor Feedback:</strong></p>
                            <div class="border p-3 bg-light"><?php echo nl2br(htmlspecialchars($submission['instructor_feedback'])); ?></div>
                        <?php endif; ?>

                        <?php if($submission['final_grade'] !== null): ?>
                            <p><strong>Grade:</strong> <span class="badge bg-success"><?php echo htmlspecialchars($submission['final_grade']); ?>/<?php echo htmlspecialchars($assignment['max_points']); ?></span></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="assignmentForm">
                    <?php echo csrf_field(); ?>
                    <?php if(in_array($assignment['submission_format'], ['text', 'both'])): ?>
                        <div class="mb-3">
                            <label for="submission_text" class="form-label">Text Submission <?php echo e(in_array($assignment['submission_format'], ['text']) ? '<span class="text-danger">*</span>' : ''); ?></label>
                            <textarea class="form-control" id="submission_text" name="submission_text" rows="6"
                                placeholder="Enter your assignment text here..."><?php echo htmlspecialchars($submission['submission_text'] ?? ''); ?></textarea>
                            <div class="form-text"><?php echo e(in_array($assignment['submission_format'], ['text']) ? 'Text submission is required.' : 'Optional text submission'); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if(in_array($assignment['submission_format'], ['file', 'both'])): ?>
                        <div class="mb-3">
                            <label for="submission_file" class="form-label">File Upload <?php echo e(in_array($assignment['submission_format'], ['file']) ? '<span class="text-danger">*</span>' : ''); ?></label>
                            <input type="file" class="form-control" id="submission_file" name="submission_file"
                                accept="<?php echo htmlspecialchars($assignment['allowed_file_types'] ?? '*'); ?>">
                            <div class="form-text">
                                Max file size: <?php echo htmlspecialchars($assignment['max_file_size'] ?? 10); ?>MB
                                <?php if($assignment['allowed_file_types']): ?>
                                    | Allowed types: <?php echo htmlspecialchars($assignment['allowed_file_types']); ?>
                                <?php endif; ?>
                                <?php echo e(in_array($assignment['submission_format'], ['file']) ? '| File upload is required.' : '| Optional file upload'); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" name="submit_assignment" class="btn btn-primary" id="submitBtn">
                        <?php echo $submission ? 'Update Submission' : 'Submit Assignment'; ?>
                    </button>
                    <a href="assignments.php" class="btn btn-secondary">Cancel</a>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Submission Status</h5>
            </div>
            <div class="card-body">
                <?php if($submission): ?>
                    <p><strong>Status:</strong> <span class="badge bg-<?php echo $submission['status'] == 'graded' ? 'success' : 'warning'; ?>">
                            <?php echo ucfirst($submission['status']); ?>
                        </span></p>
                    <p><strong>Submitted:</strong> <?php echo date('M j, Y g:i A', strtotime($submission['submission_date'])); ?></p>

                    <?php if($submission['final_grade'] !== null): ?>
                        <p><strong>Grade:</strong> <?php echo htmlspecialchars($submission['final_grade']); ?>/<?php echo htmlspecialchars($assignment['max_points']); ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted">Not submitted yet</p>
                <?php endif; ?>

                <?php if($assignment['due_date'] && strtotime($assignment['due_date']) < time()): ?>
                    <div class="alert alert-danger mt-3">
                        <i class="fas fa-exclamation-triangle"></i> This assignment is overdue!
                    </div>
                <?php elseif($assignment['due_date']): ?>
                    <p class="text-muted">
                        Time remaining:
                        <?php
                        $time_remaining = strtotime($assignment['due_date']) - time();
                        $days = floor($time_remaining / (60 * 60 * 24));
                        $hours = floor(($time_remaining % (60 * 60 * 24)) / (60 * 60));
                        echo "$days days, $hours hours";
                        ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if($submission): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="card-title mb-0">Peer Reviews</h5>
                </div>
                <div class="card-body">
                    <?php
                    $stmt = $conn->prepare("SELECT pr.*, u.first_name, u.last_name 
                                       FROM peer_reviews pr 
                                       JOIN users u ON pr.reviewer_id = u.user_id 
                                       WHERE pr.submission_id = ?");
                    $stmt->execute([$submission['submission_id']]);
                    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if(count($reviews) > 0):
                        foreach($reviews as $review):
                    ?>
                            <div class="border-bottom pb-2 mb-2">
                                <p><strong>Reviewer:</strong>
                                    <?php echo $review['is_anonymous'] ? 'Anonymous' : htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?>
                                </p>
                                <p><strong>Feedback:</strong> <?php echo substr(htmlspecialchars($review['overall_feedback']), 0, 100) . '...'; ?></p>
                                <a href="review_view.php?id=<?php echo $review['review_id']; ?>" class="btn btn-sm btn-outline-primary">View Details</a>
                            </div>
                        <?php endforeach;
                    else: ?>
                        <p class="text-muted">No peer reviews yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Debug Section - Remove this after testing -->
<div class="card mt-4 bg-light">
    <div class="card-header">
        <h6 class="card-title mb-0">Debug Information</h6>
    </div>
    <div class="card-body">
        <p><strong>Assignment ID:</strong> <?php echo $assignment_id; ?></p>
        <p><strong>User ID:</strong> <?php echo $_SESSION['user_id']; ?></p>
        <p><strong>Submission Format:</strong> <?php echo e($assignment['submission_format']); ?></p>
        <p><strong>Existing Submission:</strong> <?php echo $submission ? 'Yes (ID: ' . $submission['submission_id'] . ')' : 'No'; ?></p>
        <p><strong>PHP Upload Max Size:</strong> <?php echo ini_get('upload_max_filesize'); ?></p>
        <p><strong>PHP Post Max Size:</strong> <?php echo ini_get('post_max_size'); ?></p>
    </div>
</div>

<script>
    function validateForm() {
        const submissionFormat = '<?php echo e($assignment['submission_format']); ?>';
        const hasText = document.getElementById('submission_text') && document.getElementById('submission_text').value.trim() !== '';
        const hasFile = document.getElementById('submission_file') && document.getElementById('submission_file').files.length > 0;

        let isValid = true;
        let errorMessage = '';

        if(submissionFormat === 'text' && !hasText) {
            isValid = false;
            errorMessage = 'Text submission is required for this assignment.';
        } else if(submissionFormat === 'file' && !hasFile) {
            isValid = false;
            errorMessage = 'File upload is required for this assignment.';
        } else if(submissionFormat === 'both' && !hasText && !hasFile) {
            isValid = false;
            errorMessage = 'Please provide either text submission or upload a file.';
        }

        if(!isValid) {
            alert(errorMessage);
            return false;
        }

        // Disable button to prevent double submission
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

        return true;
    }

    // Add event listener for form submission
    document.getElementById('assignmentForm').addEventListener('submit', function(e) {
        return validateForm();
    });
</script>

<?php require_once '../includes/footer.php'; ?>
