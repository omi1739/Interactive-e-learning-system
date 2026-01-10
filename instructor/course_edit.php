<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('courses.php');
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
    $auth->redirect('courses.php');
}

// Get modules for this course
$stmt = $conn->prepare("SELECT * FROM modules WHERE course_id = ? ORDER BY module_order");
$stmt->execute([$course_id]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle course update
if($_POST && isset($_POST['update_course'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $course_code = trim($_POST['course_code']);
    $max_students = $_POST['max_students'];
    $start_date = $_POST['start_date'] ?: null;
    $end_date = $_POST['end_date'] ?: null;
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    
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
                header("Location: course_edit.php?id=" . $course_id);
                exit();
            } else {
                $_SESSION['error'] = "Failed to update course. Please try again.";
            }
        }
    }
}

// Handle module creation
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
            header("Location: course_edit.php?id=" . $course_id);
            exit();
        } else {
            $_SESSION['module_error'] = "Failed to create module. Please try again.";
        }
    }
}

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
$module_success = $_SESSION['module_success'] ?? '';
$module_error = $_SESSION['module_error'] ?? '';
unset($_SESSION['success']);
unset($_SESSION['error']);
unset($_SESSION['module_success']);
unset($_SESSION['module_error']);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Edit Course: <?php echo htmlspecialchars($course['title']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="course_manage.php?id=<?php echo $course_id; ?>" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back to Course
        </a>
        <a href="courses.php" class="btn btn-outline-secondary">
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
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Course Information</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="title" class="form-label">Course Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" 
                                       value="<?php echo htmlspecialchars($course['title']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="course_code" class="form-label">Course Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="course_code" name="course_code" 
                                       value="<?php echo htmlspecialchars($course['course_code']); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($course['description']); ?></textarea>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_published" name="is_published" 
                               <?php echo $course['is_published'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_published">Publish this course</label>
                    </div>
                    
                    <button type="submit" name="update_course" class="btn btn-primary">Update Course</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Course Modules</h5>
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

                <?php if(count($modules) > 0): ?>
                    <div class="list-group">
                        <?php foreach($modules as $module): ?>
                        <div class="list-group-item">
                            <h6><?php echo htmlspecialchars($module['title']); ?></h6>
                            <p class="mb-1"><?php echo htmlspecialchars($module['description']); ?></p>
                            <small class="text-muted">Order: <?php echo $module['module_order']; ?> | Status: <?php echo $module['is_published'] ? 'Published' : 'Draft'; ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No modules yet.</p>
                <?php endif; ?>

                <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#createModuleModal">
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
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="module_title" class="form-label">Module Title</label>
                        <input type="text" class="form-control" id="module_title" name="module_title" required>
                    </div>
                    <div class="mb-3">
                        <label for="module_description" class="form-label">Description</label>
                        <textarea class="form-control" id="module_description" name="module_description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="module_order" class="form-label">Order</label>
                        <input type="number" class="form-control" id="module_order" name="module_order" value="<?php echo count($modules) + 1; ?>" required>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="module_is_published" name="module_is_published" checked>
                        <label class="form-check-label" for="module_is_published">Publish module</label>
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

<?php require_once '../includes/footer.php'; ?>