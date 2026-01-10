<?php
require_once 'includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('instructor/courses.php');
}

$course_id = $_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get course details and verify ownership
$stmt = $conn->prepare("SELECT c.*, u.first_name, u.last_name 
                       FROM courses c 
                       JOIN users u ON c.instructor_id = u.user_id 
                       WHERE c.course_id = ? AND c.instructor_id = ?");
$stmt->execute([$course_id, $_SESSION['user_id']]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$course) {
    $auth->redirect('instructor/courses.php');
}

// Get modules for this course
$stmt = $conn->prepare("SELECT * FROM modules WHERE course_id = ? ORDER BY module_order");
$stmt->execute([$course_id]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle course update - FIXED: Use POST-Redirect-GET pattern
if($_POST && isset($_POST['update_course'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $course_code = trim($_POST['course_code']);
    $max_students = $_POST['max_students'];
    $start_date = $_POST['start_date'] ?: null;
    $end_date = $_POST['end_date'] ?: null;
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    
    // Validate input
    if(empty($title) || empty($course_code)) {
        $_SESSION['error'] = "Course title and code are required.";
    } else {
        // Check if course code already exists (excluding current course)
        $check_stmt = $conn->prepare("SELECT course_id FROM courses WHERE course_code = ? AND course_id != ?");
        $check_stmt->execute([$course_code, $course_id]);
        
        if($check_stmt->rowCount() > 0) {
            $_SESSION['error'] = "Course code already exists. Please choose a different one.";
        } else {
            // Update the course
            $stmt = $conn->prepare("UPDATE courses SET title = ?, description = ?, course_code = ?, max_students = ?, start_date = ?, end_date = ?, is_published = ?, updated_at = NOW() WHERE course_id = ?");
            if($stmt->execute([$title, $description, $course_code, $max_students, $start_date, $end_date, $is_published, $course_id])) {
                $_SESSION['success'] = "Course updated successfully!";
                // Redirect to prevent resubmission
                header("Location: course_edit.php?id=" . $course_id);
                exit();
            } else {
                $_SESSION['error'] = "Failed to update course. Please try again.";
            }
        }
    }
}

// Handle module creation - FIXED: Use POST-Redirect-GET pattern
if($_POST && isset($_POST['create_module'])) {
    $module_title = trim($_POST['module_title']);
    $module_description = trim($_POST['module_description']);
    $module_order = $_POST['module_order'];
    $is_published = isset($_POST['module_is_published']) ? 1 : 0;
    
    if(empty($module_title)) {
        $_SESSION['module_error'] = "Module title is required.";
    } else {
        $stmt = $conn->prepare("INSERT INTO modules (course_id, title, description, module_order, is_published) VALUES (?, ?, ?, ?, ?)");
        if($stmt->execute([$course_id, $module_title, $module_description, $module_order, $is_published])) {
            $_SESSION['module_success'] = "Module created successfully!";
            // Redirect to prevent resubmission
            header("Location: course_edit.php?id=" . $course_id);
            exit();
        } else {
            $_SESSION['module_error'] = "Failed to create module. Please try again.";
        }
    }
}

// Handle module update - FIXED: Use POST-Redirect-GET pattern
if($_POST && isset($_POST['update_module'])) {
    $module_id = $_POST['module_id'];
    $module_title = trim($_POST['module_title']);
    $module_description = trim($_POST['module_description']);
    $module_order = $_POST['module_order'];
    $is_published = isset($_POST['module_is_published']) ? 1 : 0;
    
    // Verify module belongs to instructor's course
    $check_stmt = $conn->prepare("SELECT m.module_id FROM modules m JOIN courses c ON m.course_id = c.course_id WHERE m.module_id = ? AND c.instructor_id = ?");
    $check_stmt->execute([$module_id, $_SESSION['user_id']]);
    
    if($check_stmt->rowCount() > 0 && !empty($module_title)) {
        $stmt = $conn->prepare("UPDATE modules SET title = ?, description = ?, module_order = ?, is_published = ?, updated_at = NOW() WHERE module_id = ?");
        if($stmt->execute([$module_title, $module_description, $module_order, $is_published, $module_id])) {
            $_SESSION['module_success'] = "Module updated successfully!";
            // Redirect to prevent resubmission
            header("Location: course_edit.php?id=" . $course_id);
            exit();
        } else {
            $_SESSION['module_error'] = "Failed to update module. Please try again.";
        }
    } else {
        $_SESSION['module_error'] = "Module not found or invalid data.";
    }
}

// Handle module deletion - FIXED: Use POST-Redirect-GET pattern
if(isset($_GET['delete_module'])) {
    $module_id = $_GET['delete_module'];
    
    // Verify module belongs to instructor's course
    $check_stmt = $conn->prepare("SELECT m.module_id FROM modules m JOIN courses c ON m.course_id = c.course_id WHERE m.module_id = ? AND c.instructor_id = ?");
    $check_stmt->execute([$module_id, $_SESSION['user_id']]);
    
    if($check_stmt->rowCount() > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM modules WHERE module_id = ?");
        if($delete_stmt->execute([$module_id])) {
            $_SESSION['module_success'] = "Module deleted successfully!";
            // Redirect to prevent resubmission
            header("Location: course_edit.php?id=" . $course_id);
            exit();
        } else {
            $_SESSION['module_error'] = "Failed to delete module. It may have associated content.";
        }
    } else {
        $_SESSION['module_error'] = "Module not found or you don't have permission to delete it.";
    }
}

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
$module_success = $_SESSION['module_success'] ?? '';
$module_error = $_SESSION['module_error'] ?? '';
// Clear the messages after displaying
unset($_SESSION['success']);
unset($_SESSION['error']);
unset($_SESSION['module_success']);
unset($_SESSION['module_error']);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Edit Course: <?php echo htmlspecialchars($course['title']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="instructor/course_manage.php?id=<?php echo $course_id; ?>" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back to Course
        </a>
        <a href="instructor/courses.php" class="btn btn-outline-secondary">
            <i class="fas fa-list"></i> All Courses
        </a>
    </div>
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
    <div class="col-md-8">
        <!-- Course Information Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-info-circle"></i> Course Information
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" id="courseForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="title" class="form-label">Course Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" 
                                       value="<?php echo htmlspecialchars($course['title']); ?>" required maxlength="200">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="course_code" class="form-label">Course Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="course_code" name="course_code" 
                                       value="<?php echo htmlspecialchars($course['course_code']); ?>" required maxlength="20">
                                <div class="form-text">Unique identifier for your course</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Course Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"
                                  placeholder="Describe what students will learn in this course..."><?php echo htmlspecialchars($course['description']); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="max_students" class="form-label">Maximum Students</label>
                                <input type="number" class="form-control" id="max_students" name="max_students" 
                                       value="<?php echo htmlspecialchars($course['max_students']); ?>" min="1" max="1000">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date"
                                       value="<?php echo $course['start_date'] ? date('Y-m-d', strtotime($course['start_date'])) : ''; ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date"
                                       value="<?php echo $course['end_date'] ? date('Y-m-d', strtotime($course['end_date'])) : ''; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_published" name="is_published" 
                               <?php echo $course['is_published'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_published">Publish this course (make it visible to students)</label>
                    </div>
                    
                    <button type="submit" name="update_course" class="btn btn-primary">Update Course</button>
                    <a href="instructor/course_manage.php?id=<?php echo $course_id; ?>" class="btn btn-secondary">Cancel</a>
                </form>
            </div>
        </div>

        <!-- Modules Management Card -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-folder"></i> Course Modules
                    <span class="badge bg-primary"><?php echo count($modules); ?> modules</span>
                </h5>
            </div>
            <div class="card-body">
                <?php if(!empty($module_success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $module_success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if(!empty($module_error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $module_error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Modules List -->
                <?php if(count($modules) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($modules as $module): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($module['module_order']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($module['title']); ?></strong>
                                        <?php if($module['description']): ?>
                                            <br><small class="text-muted"><?php echo substr(htmlspecialchars($module['description']), 0, 100); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $module['is_published'] ? 'success' : 'warning'; ?>">
                                            <?php echo $module['is_published'] ? 'Published' : 'Draft'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($module['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editModuleModal<?php echo $module['module_id']; ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <a href="course_edit.php?id=<?php echo $course_id; ?>&delete_module=<?php echo $module['module_id']; ?>" 
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('Are you sure you want to delete this module? This will also delete all lessons and assignments in this module.')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>

                                <!-- Edit Module Modal -->
                                <div class="modal fade" id="editModuleModal<?php echo $module['module_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Module</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" id="editModuleForm<?php echo $module['module_id']; ?>">
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label for="module_title<?php echo $module['module_id']; ?>" class="form-label">Module Title <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control" id="module_title<?php echo $module['module_id']; ?>" 
                                                               name="module_title" value="<?php echo htmlspecialchars($module['title']); ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="module_description<?php echo $module['module_id']; ?>" class="form-label">Description</label>
                                                        <textarea class="form-control" id="module_description<?php echo $module['module_id']; ?>" 
                                                                  name="module_description" rows="3"><?php echo htmlspecialchars($module['description']); ?></textarea>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="mb-3">
                                                                <label for="module_order<?php echo $module['module_id']; ?>" class="form-label">Order</label>
                                                                <input type="number" class="form-control" id="module_order<?php echo $module['module_id']; ?>" 
                                                                       name="module_order" value="<?php echo htmlspecialchars($module['module_order']); ?>" min="1" required>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="mb-3 form-check pt-4">
                                                                <input type="checkbox" class="form-check-input" id="module_is_published<?php echo $module['module_id']; ?>" 
                                                                       name="module_is_published" <?php echo $module['is_published'] ? 'checked' : ''; ?>>
                                                                <label class="form-check-label" for="module_is_published<?php echo $module['module_id']; ?>">Published</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <input type="hidden" name="module_id" value="<?php echo $module['module_id']; ?>">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="update_module" class="btn btn-primary">Update Module</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Modules Yet</h5>
                        <p class="text-muted">Create your first module to organize your course content.</p>
                    </div>
                <?php endif; ?>

                <!-- Add Module Button -->
                <div class="text-center mt-4">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModuleModal">
                        <i class="fas fa-plus"></i> Add New Module
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Course Statistics -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Course Statistics</h5>
            </div>
            <div class="card-body">
                <?php
                // Get student count
                $stmt = $conn->prepare("SELECT COUNT(*) as student_count FROM enrollments WHERE course_id = ? AND enrollment_status = 'approved'");
                $stmt->execute([$course_id]);
                $student_count = $stmt->fetch(PDO::FETCH_ASSOC)['student_count'];
                
                // Get assignment count
                $stmt = $conn->prepare("SELECT COUNT(*) as assignment_count FROM assignments a JOIN modules m ON a.module_id = m.module_id WHERE m.course_id = ?");
                $stmt->execute([$course_id]);
                $assignment_count = $stmt->fetch(PDO::FETCH_ASSOC)['assignment_count'];
                ?>
                
                <p><strong>Enrolled Students:</strong> <span class="badge bg-primary"><?php echo $student_count; ?></span></p>
                <p><strong>Total Modules:</strong> <span class="badge bg-success"><?php echo count($modules); ?></span></p>
                <p><strong>Total Assignments:</strong> <span class="badge bg-info"><?php echo $assignment_count; ?></span></p>
                <p><strong>Course Status:</strong> 
                    <span class="badge bg-<?php echo $course['is_published'] ? 'success' : 'warning'; ?>">
                        <?php echo $course['is_published'] ? 'Published' : 'Draft'; ?>
                    </span>
                </p>
                <p><strong>Created:</strong> <?php echo date('M j, Y', strtotime($course['created_at'])); ?></p>
                <?php if($course['updated_at'] != $course['created_at']): ?>
                    <p><strong>Last Updated:</strong> <?php echo date('M j, Y', strtotime($course['updated_at'])); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <a href="instructor/course_manage.php?id=<?php echo $course_id; ?>" class="btn btn-outline-primary w-100 mb-2">
                    <i class="fas fa-users"></i> Manage Students
                </a>
                <a href="instructor/assignments.php?course=<?php echo $course_id; ?>" class="btn btn-outline-success w-100 mb-2">
                    <i class="fas fa-tasks"></i> Manage Assignments
                </a>
                <a href="instructor/students.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline-info w-100 mb-2">
                    <i class="fas fa-chart-bar"></i> View Analytics
                </a>
                <button class="btn btn-outline-warning w-100" data-bs-toggle="modal" data-bs-target="#createModuleModal">
                    <i class="fas fa-plus"></i> Add Module
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Create Module Modal -->
<div class="modal fade" id="createModuleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Module</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createModuleForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="new_module_title" class="form-label">Module Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="new_module_title" name="module_title" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_module_description" class="form-label">Description</label>
                        <textarea class="form-control" id="new_module_description" name="module_description" rows="3"
                                  placeholder="Brief description of what this module covers..."></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="new_module_order" class="form-label">Order</label>
                                <input type="number" class="form-control" id="new_module_order" name="module_order" 
                                       value="<?php echo count($modules) + 1; ?>" min="1" required>
                                <div class="form-text">Position in the course sequence</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3 form-check pt-4">
                                <input type="checkbox" class="form-check-input" id="new_module_is_published" 
                                       name="module_is_published" checked>
                                <label class="form-check-label" for="new_module_is_published">Publish module</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_module" class="btn btn-primary">Create Module</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Clear form when modal is closed
document.addEventListener('DOMContentLoaded', function() {
    const createModal = document.getElementById('createModuleModal');
    const createForm = document.getElementById('createModuleForm');
    
    if (createModal && createForm) {
        createModal.addEventListener('hidden.bs.modal', function () {
            createForm.reset();
            // Reset to default values
            document.getElementById('new_module_order').value = <?php echo count($modules) + 1; ?>;
            document.getElementById('new_module_is_published').checked = true;
        });
    }
    
    // Auto-calculate next module order when page loads
    const moduleCount = <?php echo count($modules); ?>;
    const orderInput = document.getElementById('new_module_order');
    if (orderInput) {
        orderInput.value = moduleCount + 1;
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>