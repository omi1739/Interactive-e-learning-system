<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

// Get database connection
$conn = $db->getConnection();

// Get instructor's courses
$courses = $functions->getCourses($_SESSION['user_id']);

// Handle assignment creation - FIXED: Use POST-Redirect-GET pattern
if($_POST && isset($_POST['create_assignment'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $module_id = $_POST['module_id'];
    $max_points = $_POST['max_points'];
    $due_date = $_POST['due_date'] ?: null;
    $assignment_type = $_POST['assignment_type'];
    $submission_format = $_POST['submission_format'];
    $max_file_size = $_POST['max_file_size'] ?? 10;
    $allowed_file_types = trim($_POST['allowed_file_types'] ?? '');
    
    // Validate input
    if(empty($title) || empty($module_id) || empty($max_points)) {
        $_SESSION['error'] = "Title, module, and max points are required.";
    } else {
        // Create the assignment
        $stmt = $conn->prepare("INSERT INTO assignments (module_id, title, description, assignment_type, max_points, due_date, submission_format, max_file_size, allowed_file_types, is_published) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)");
        
        if($stmt->execute([$module_id, $title, $description, $assignment_type, $max_points, $due_date, $submission_format, $max_file_size, $allowed_file_types])) {
            $_SESSION['success'] = "Assignment created successfully!";
            
            // REDIRECT after successful creation to prevent duplicate submissions
            header("Location: assignments.php?success=1");
            exit();
        } else {
            $_SESSION['error'] = "Failed to create assignment. Please try again.";
        }
    }
}

// Handle assignment deletion - FIXED: Use GET with confirmation
if(isset($_GET['delete'])) {
    $assignment_id = $_GET['delete'];
    
    // Verify the assignment belongs to instructor's course
    $stmt = $conn->prepare("SELECT a.assignment_id 
                           FROM assignments a 
                           JOIN modules m ON a.module_id = m.module_id 
                           JOIN courses c ON m.course_id = c.course_id 
                           WHERE a.assignment_id = ? AND c.instructor_id = ?");
    $stmt->execute([$assignment_id, $_SESSION['user_id']]);
    
    if($stmt->rowCount() > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM assignments WHERE assignment_id = ?");
        if($delete_stmt->execute([$assignment_id])) {
            $_SESSION['success'] = "Assignment deleted successfully!";
            header("Location: assignments.php?success=1");
            exit();
        } else {
            $_SESSION['error'] = "Failed to delete assignment.";
        }
    } else {
        $_SESSION['error'] = "Assignment not found or you don't have permission to delete it.";
    }
}

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
// Clear the messages after displaying
unset($_SESSION['success']);
unset($_SESSION['error']);

// Get instructor's assignments with course and module info
$stmt = $conn->prepare("SELECT a.*, m.title as module_title, c.title as course_title, c.course_id,
                       (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.assignment_id) as submission_count
                       FROM assignments a
                       JOIN modules m ON a.module_id = m.module_id
                       JOIN courses c ON m.course_id = c.course_id
                       WHERE c.instructor_id = ?
                       ORDER BY a.created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Assignment Management</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAssignmentModal">
        <i class="fas fa-plus"></i> Create New Assignment
    </button>
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

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">My Assignments</h5>
            </div>
            <div class="card-body">
                <?php if(count($assignments) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Course</th>
                                    <th>Module</th>
                                    <th>Due Date</th>
                                    <th>Max Points</th>
                                    <th>Submissions</th>
                                    <th>Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($assignments as $assignment): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($assignment['title']); ?></strong>
                                        <?php if(!$assignment['is_published']): ?>
                                            <span class="badge bg-warning ms-1">Draft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($assignment['course_title']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['module_title']); ?></td>
                                    <td>
                                        <?php if($assignment['due_date']): ?>
                                            <?php echo date('M j, Y g:i A', strtotime($assignment['due_date'])); ?>
                                            <?php if(strtotime($assignment['due_date']) < time()): ?>
                                                <span class="badge bg-danger">Overdue</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            No due date
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($assignment['max_points']); ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $assignment['submission_count']; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo ucfirst($assignment['assignment_type']); ?></span>
                                    </td>
                                    <td>
                                        <a href="assignment_view.php?id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="assignment_submissions.php?id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-list"></i> Submissions
                                        </a>
                                        <a href="assignments.php?delete=<?php echo $assignment['assignment_id']; ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Are you sure you want to delete this assignment? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Assignments Yet</h5>
                        <p class="text-muted">Create your first assignment to get started.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAssignmentModal">
                            <i class="fas fa-plus"></i> Create First Assignment
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Create Assignment Modal -->
<div class="modal fade" id="createAssignmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Assignment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createAssignmentForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="title" class="form-label">Assignment Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" required maxlength="200">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="module_id" class="form-label">Module <span class="text-danger">*</span></label>
                                <select class="form-select" id="module_id" name="module_id" required>
                                    <option value="">Select Module</option>
                                    <?php foreach($courses as $course): 
                                        // Get modules for this course
                                        $module_stmt = $conn->prepare("SELECT * FROM modules WHERE course_id = ? AND is_published = TRUE ORDER BY module_order");
                                        $module_stmt->execute([$course['course_id']]);
                                        $modules = $module_stmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        if(count($modules) > 0): ?>
                                            <optgroup label="<?php echo htmlspecialchars($course['title']); ?>">
                                            <?php foreach($modules as $module): ?>
                                                <option value="<?php echo $module['module_id']; ?>">
                                                    <?php echo htmlspecialchars($module['title']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif;
                                    endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Assignment Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"
                                  placeholder="Describe the assignment requirements..."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="max_points" class="form-label">Max Points <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="max_points" name="max_points" 
                                       value="100" min="1" max="1000" step="0.5" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="assignment_type" class="form-label">Assignment Type</label>
                                <select class="form-select" id="assignment_type" name="assignment_type">
                                    <option value="individual" selected>Individual</option>
                                    <option value="group">Group</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="submission_format" class="form-label">Submission Format</label>
                                <select class="form-select" id="submission_format" name="submission_format">
                                    <option value="file" selected>File Upload</option>
                                    <option value="text">Text Only</option>
                                    <option value="both">Both</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="due_date" class="form-label">Due Date</label>
                                <input type="datetime-local" class="form-control" id="due_date" name="due_date">
                                <div class="form-text">Leave empty for no due date</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="max_file_size" class="form-label">Max File Size (MB)</label>
                                <input type="number" class="form-control" id="max_file_size" name="max_file_size" 
                                       value="10" min="1" max="100">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="allowed_file_types" class="form-label">Allowed File Types</label>
                                <input type="text" class="form-control" id="allowed_file_types" name="allowed_file_types"
                                       value="pdf,doc,docx,txt,zip"
                                       placeholder="pdf,doc,docx,txt">
                                <div class="form-text">Comma separated</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_assignment" class="btn btn-primary">Create Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Clear form when modal is closed
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('createAssignmentModal');
    const form = document.getElementById('createAssignmentForm');
    
    modal.addEventListener('hidden.bs.modal', function () {
        form.reset();
        // Reset select elements to default values
        document.getElementById('max_points').value = '100';
        document.getElementById('assignment_type').value = 'individual';
        document.getElementById('submission_format').value = 'file';
        document.getElementById('max_file_size').value = '10';
        document.getElementById('allowed_file_types').value = 'pdf,doc,docx,txt,zip';
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>