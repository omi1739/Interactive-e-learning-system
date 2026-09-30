<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('courses.php');
}

$course_id = intval($_GET['id']);
$conn = $db->getConnection();

// Get course details and verify ownership
$stmt = $conn->prepare("SELECT c.*, u.first_name, u.last_name 
                       FROM courses c 
                       JOIN users u ON c.instructor_id = u.user_id 
                       WHERE c.course_id = ? AND c.instructor_id = ?");
$stmt->execute([$course_id, $_SESSION['user_id']]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$course) {
    $_SESSION['error'] = "Course not found or access denied.";
    $auth->redirect('courses.php');
}

// Handle course update
if($_POST && isset($_POST['update_course'])) {
    verify_csrf();
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $course_code = trim($_POST['course_code']);
    $max_students = intval($_POST['max_students'] ?? 50);
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
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
    verify_csrf();
    $module_title = trim($_POST['module_title']);
    $module_description = trim($_POST['module_description'] ?? '');
    $module_order = intval($_POST['module_order'] ?? 1);
    $is_published = isset($_POST['module_is_published']) ? 1 : 0;
    
    if(empty($module_title)) {
        $_SESSION['error'] = "Module title is required.";
    } else {
        $stmt = $conn->prepare("INSERT INTO modules (course_id, title, description, module_order, is_published) VALUES (?, ?, ?, ?, ?)");
        if($stmt->execute([$course_id, $module_title, $module_description, $module_order, $is_published])) {
            $_SESSION['success'] = "Module created successfully!";
            header("Location: course_edit.php?id=" . $course_id);
            exit();
        } else {
            $_SESSION['error'] = "Failed to create module. Please try again.";
        }
    }
}

// Handle module update
if($_POST && isset($_POST['update_module'])) {
    verify_csrf();
    $module_id = intval($_POST['module_id']);
    $module_title = trim($_POST['module_title']);
    $module_description = trim($_POST['module_description'] ?? '');
    $module_order = intval($_POST['module_order'] ?? 1);
    $is_published = isset($_POST['module_is_published']) ? 1 : 0;
    
    // Verify module belongs to instructor's course
    $check_stmt = $conn->prepare("SELECT m.module_id FROM modules m JOIN courses c ON m.course_id = c.course_id WHERE m.module_id = ? AND c.instructor_id = ?");
    $check_stmt->execute([$module_id, $_SESSION['user_id']]);
    
    if($check_stmt->rowCount() > 0 && !empty($module_title)) {
        $stmt = $conn->prepare("UPDATE modules SET title = ?, description = ?, module_order = ?, is_published = ?, updated_at = NOW() WHERE module_id = ?");
        if($stmt->execute([$module_title, $module_description, $module_order, $is_published, $module_id])) {
            $_SESSION['success'] = "Module updated successfully!";
            header("Location: course_edit.php?id=" . $course_id);
            exit();
        } else {
            $_SESSION['error'] = "Failed to update module.";
        }
    } else {
        $_SESSION['error'] = "Module not found or invalid title.";
    }
}

// Handle module deletion.
// POST + CSRF rather than GET: a GET delete can be triggered by any page the
// instructor visits (a stray <img src="...delete_module=3"> is enough).
if($_POST && isset($_POST['delete_module'])) {
    verify_csrf();
    $module_id = intval($_POST['delete_module'] ?? 0);
    
    $check_stmt = $conn->prepare("SELECT m.module_id FROM modules m JOIN courses c ON m.course_id = c.course_id WHERE m.module_id = ? AND c.instructor_id = ?");
    $check_stmt->execute([$module_id, $_SESSION['user_id']]);
    
    if($check_stmt->rowCount() > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM modules WHERE module_id = ?");
        if($delete_stmt->execute([$module_id])) {
            $_SESSION['success'] = "Module deleted successfully!";
            header("Location: course_edit.php?id=" . $course_id);
            exit();
        } else {
            $_SESSION['error'] = "Failed to delete module.";
        }
    } else {
        $_SESSION['error'] = "Module not found or unauthorized.";
    }
}

// Handle lesson creation
if($_POST && isset($_POST['create_lesson'])) {
    verify_csrf();
    $module_id = intval($_POST['module_id'] ?? 0);
    $lesson_title = trim($_POST['lesson_title'] ?? '');
    $duration = max(0, min(600, intval($_POST['duration_minutes'] ?? 15)));
    $content = trim($_POST['lesson_content'] ?? '');

    // content_type is an ENUM column. Passing the raw POST value let an
    // arbitrary string reach MySQL: in strict mode the insert failed with a
    // driver error, and in non-strict mode it silently became an empty value.
    $valid_types = ['text', 'video', 'document', 'images'];
    $content_type = in_array($_POST['content_type'] ?? '', $valid_types, true)
        ? $_POST['content_type']
        : 'text';

    if($module_id <= 0) {
        $_SESSION['error'] = "Choose a module for the lesson.";
    } elseif($lesson_title === '') {
        $_SESSION['error'] = "Lesson title is required.";
    } elseif(mb_strlen($lesson_title) > 200) {
        $_SESSION['error'] = "Lesson title must be 200 characters or fewer.";
    } elseif(!instructor_owns_module($conn, $module_id, $_SESSION['user_id'])) {
        // The module_id is a form field, so it must be proven to belong to a
        // course this instructor owns. Without this check any instructor could
        // POST someone else's module_id and write a lesson into their course.
        $_SESSION['error'] = "That module was not found in one of your courses.";
        error_log("Rejected cross-course lesson insert: user {$_SESSION['user_id']} -> module {$module_id}");
    } else {
        // Append after the current last lesson so ordering stays stable.
        $order_stmt = $conn->prepare("SELECT COALESCE(MAX(lesson_order), 0) + 1 FROM lessons WHERE module_id = ?");
        $order_stmt->execute([$module_id]);
        $lesson_order = (int) $order_stmt->fetchColumn();

        $stmt = $conn->prepare("INSERT INTO lessons (module_id, title, content, content_type, duration_minutes, lesson_order, is_published) VALUES (?, ?, ?, ?, ?, ?, 1)");
        if($stmt->execute([$module_id, $lesson_title, $content, $content_type, $duration, $lesson_order])) {
            $_SESSION['success'] = "Lesson added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add the lesson. Please try again.";
            error_log("Lesson insert failed: " . json_encode($stmt->errorInfo()));
        }
    }
    header("Location: course_edit.php?id=" . $course_id);
    exit();
}

// Get modules plus their lessons and assignments in three queries rather than
// two extra round trips per module (the previous per-module loop issued 2*N
// queries, which is slow on a course with many modules).
$modules = [];
$lessons_by_module = [];
$assignments_by_module = [];

$stmt = $conn->prepare("SELECT * FROM modules WHERE course_id = ? ORDER BY module_order ASC, module_id ASC");
$stmt->execute([$course_id]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$module_ids = array_column($modules, 'module_id');

if ($module_ids > 0) {
    $in = implode(',', array_fill(0, count($module_ids), '?'));

    $stmt = $conn->prepare("SELECT * FROM lessons WHERE module_id IN ($in) ORDER BY lesson_order ASC, lesson_id ASC");
    $stmt->execute($module_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lesson) {
        $lessons_by_module[$lesson['module_id']][] = $lesson;
    }

    $stmt = $conn->prepare("SELECT * FROM assignments WHERE module_id IN ($in) ORDER BY due_date ASC");
    $stmt->execute($module_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $assignments_by_module[$assignment['module_id']][] = $assignment;
    }
}

foreach ($modules as $i => $mod) {
    $mid = (int) $mod['module_id'];
    $modules[$i]['lessons'] = $lessons_by_module[$mid] ?? [];
    $modules[$i]['assignments'] = $assignments_by_module[$mid] ?? [];
}
unset($mod);

// This page reports every outcome through $_SESSION['error'] / $_SESSION['success']
// (20 write sites) and renders $error / $success further down, but nothing ever
// assigned those locals from the session - so every message was invisible and the
// alerts never rendered. Drain them here, and clear the keys so a reload does not
// replay an old message.
$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2">Edit Course: <?php echo htmlspecialchars($course['title']); ?></h1>
        <p class="text-muted mb-0">Course Code: <code><?php echo htmlspecialchars($course['course_code']); ?></code></p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="course_manage.php?id=<?php echo $course_id; ?>" class="btn btn-outline-secondary me-2">
            <i class="fas fa-users me-1"></i> Manage Students
        </a>
        <a href="courses.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i> All Courses
        </a>
    </div>
</div>

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

<div class="row">
    <!-- Left Column: Course Settings Form -->
    <div class="col-lg-5 mb-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold">
                    <i class="fas fa-sliders-h text-primary me-2"></i> Course Settings
                </h5>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label for="title" class="form-label fw-bold">Course Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="title" name="title" 
                               value="<?php echo htmlspecialchars($course['title']); ?>" required maxlength="200">
                    </div>
                    
                    <div class="mb-3">
                        <label for="course_code" class="form-label fw-bold">Course Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="course_code" name="course_code" 
                               value="<?php echo htmlspecialchars($course['course_code']); ?>" required maxlength="20">
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label fw-bold">Course Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($course['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="max_students" class="form-label fw-bold">Max Students</label>
                            <input type="number" class="form-control" id="max_students" name="max_students" 
                                   value="<?php echo htmlspecialchars($course['max_students']); ?>" min="1" max="1000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Publication Status</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="is_published" name="is_published" 
                                       <?php echo $course['is_published'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_published">Published</label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label fw-bold">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date"
                                   value="<?php echo $course['start_date'] ? date('Y-m-d', strtotime($course['start_date'])) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label fw-bold">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date"
                                   value="<?php echo $course['end_date'] ? date('Y-m-d', strtotime($course['end_date'])) : ''; ?>">
                        </div>
                    </div>
                    
                    <button type="submit" name="update_course" class="btn btn-primary w-100 py-2 fw-bold">
                        <i class="fas fa-save me-1"></i> Save Course Information
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Modules & Structure -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fw-bold">
                    <i class="fas fa-layer-group text-primary me-2"></i> Course Curriculum & Modules
                </h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModuleModal">
                    <i class="fas fa-plus me-1"></i> Add Module
                </button>
            </div>
            <div class="card-body p-4">
                <?php if(count($modules) > 0): ?>
                    <div class="accordion" id="modulesAccordion">
                        <?php foreach($modules as $index => $module): ?>
                        <div class="accordion-item mb-3 border rounded shadow-sm overflow-hidden">
                            <h2 class="accordion-header" id="heading<?php echo $module['module_id']; ?>">
                                <button class="accordion-button collapsed py-3" type="button" data-bs-toggle="collapse" 
                                        data-bs-target="#collapse<?php echo $module['module_id']; ?>">
                                    <div class="d-flex justify-content-between align-items-center w-100 me-3">
                                        <span class="fw-bold">
                                            <span class="badge bg-secondary me-2">Module <?php echo $index + 1; ?></span>
                                            <?php echo htmlspecialchars($module['title']); ?>
                                        </span>
                                        <div>
                                            <span class="badge bg-info text-dark me-1"><?php echo count($module['lessons']); ?> lessons</span>
                                            <span class="badge bg-primary"><?php echo count($module['assignments']); ?> assignments</span>
                                        </div>
                                    </div>
                                </button>
                            </h2>
                            <div id="collapse<?php echo $module['module_id']; ?>" class="accordion-collapse collapse" 
                                 data-bs-parent="#modulesAccordion">
                                <div class="accordion-body bg-light">
                                    <?php if($module['description']): ?>
                                        <p class="text-muted small mb-3"><?php echo htmlspecialchars($module['description']); ?></p>
                                    <?php endif; ?>

                                    <!-- Lessons List -->
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="fw-bold mb-0 text-secondary"><i class="fas fa-book-open me-1"></i> Lessons</h6>
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addLessonModal<?php echo $module['module_id']; ?>">
                                            <i class="fas fa-plus me-1"></i> Add Lesson
                                        </button>
                                    </div>

                                    <?php if(count($module['lessons']) > 0): ?>
                                        <ul class="list-group mb-3">
                                            <?php foreach($module['lessons'] as $lesson): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                                <div>
                                                    <i class="fas fa-play-circle text-primary me-2"></i>
                                                    <strong><?php echo htmlspecialchars($lesson['title']); ?></strong>
                                                    <span class="badge bg-light text-dark border ms-2"><?php echo htmlspecialchars($lesson['content_type']); ?></span>
                                                </div>
                                                <small class="text-muted"><?php echo $lesson['duration_minutes']; ?> mins</small>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="text-muted small mb-3 fst-italic">No lessons in this module yet.</p>
                                    <?php endif; ?>

                                    <!-- Action Buttons for Module -->
                                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                                        <a href="assignments.php?module_id=<?php echo $module['module_id']; ?>" class="btn btn-outline-success btn-sm">
                                            <i class="fas fa-tasks me-1"></i> Create Assignment
                                        </a>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Are you sure you want to delete this module and its content?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="delete_module" value="<?php echo (int)$module['module_id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                <i class="fas fa-trash me-1"></i> Delete Module
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Add Lesson Modal for this Module -->
                        <div class="modal fade" id="addLessonModal<?php echo $module['module_id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Add Lesson to Module <?php echo $index + 1; ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="module_id" value="<?php echo $module['module_id']; ?>">
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Lesson Title <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="lesson_title" required>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">Content Type</label>
                                                    <select class="form-select" name="content_type">
                                                        <option value="text">Text / Notes</option>
                                                        <option value="video">Video</option>
                                                        <option value="document">Document / PDF</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">Duration (mins)</label>
                                                    <input type="number" class="form-control" name="duration_minutes" value="15" min="1">
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Lesson Content / Description</label>
                                                <textarea class="form-control" name="lesson_content" rows="3"></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="create_lesson" class="btn btn-primary">Add Lesson</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-layer-group fa-3x text-muted mb-3"></i>
                        <h5>No Modules Created Yet</h5>
                        <p class="text-muted">Break your course into learning modules to organize lessons and assignments.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModuleModal">
                            <i class="fas fa-plus me-1"></i> Create First Module
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Module Modal -->
<div class="modal fade" id="addModuleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Module</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="module_title" class="form-label fw-bold">Module Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="module_title" name="module_title" placeholder="e.g. Module 1: Introduction" required>
                    </div>
                    <div class="mb-3">
                        <label for="module_description" class="form-label fw-bold">Module Description</label>
                        <textarea class="form-control" id="module_description" name="module_description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="module_order" class="form-label fw-bold">Sequence Order</label>
                            <input type="number" class="form-control" id="module_order" name="module_order" value="<?php echo count($modules) + 1; ?>" min="1">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Visibility</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="module_is_published" name="module_is_published" checked>
                                <label class="form-check-label" for="module_is_published">Published</label>
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

<?php require_once '../includes/footer.php'; ?>
