<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

// Get database connection
$conn = $db->getConnection();

// Handle course creation - FIXED: Set is_published = TRUE
if($_POST && isset($_POST['create_course'])) {
    verify_csrf();
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $course_code = trim($_POST['course_code']);
    
    // Validate input
    if(empty($title) || empty($course_code)) {
        $_SESSION['error'] = "Course title and code are required.";
    } else {
        // Check if course code already exists
        $check_stmt = $conn->prepare("SELECT course_id FROM courses WHERE course_code = ?");
        $check_stmt->execute([$course_code]);
        
        if($check_stmt->rowCount() > 0) {
            $_SESSION['error'] = "Course code already exists. Please choose a different one.";
        } else {
            // Create the course - FIXED: Set is_published = TRUE
            $stmt = $conn->prepare("INSERT INTO courses (instructor_id, title, description, course_code, is_published) VALUES (?, ?, ?, ?, TRUE)");
            if($stmt->execute([$_SESSION['user_id'], $title, $description, $course_code])) {
                $_SESSION['success'] = "Course created successfully and published!";
                header("Location: courses.php");
                exit();
            } else {
                $_SESSION['error'] = "Failed to create course. Please try again.";
            }
        }
    }
}

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success']);
unset($_SESSION['error']);

// Get instructor's courses
$courses = $functions->getCourses($_SESSION['user_id']);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">My Courses</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCourseModal">
        <i class="fas fa-plus"></i> Create New Course
    </button>
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
    <?php if(count($courses) > 0): ?>
        <?php foreach($courses as $course): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card course-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><?php echo htmlspecialchars($course['title']); ?></h5>
                    <p class="card-text"><?php echo e(substr($course['description'] ?? 'No description', 0, 100) . '...'); ?></p>
                    <p class="card-text">
                        <small class="text-muted">
                            Code: <?php echo htmlspecialchars($course['course_code']); ?>
                        </small>
                    </p>
                    <p class="card-text">
                        <span class="badge bg-<?php echo $course['is_published'] ? 'success' : 'warning'; ?>">
                            <?php echo $course['is_published'] ? 'Published' : 'Draft'; ?>
                        </span>
                    </p>
                    <?php 
                    // Get student count for this course
                    $student_count = count($functions->getCourseStudents($course['course_id']));
                    ?>
                    <p class="card-text">
                        <small class="text-muted">
                            Students: <?php echo $student_count; ?>
                        </small>
                    </p>
                </div>
                <div class="card-footer bg-white border-top-0 pt-0 pb-3 d-flex gap-2">
                    <a href="course_manage.php?id=<?php echo $course['course_id']; ?>" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="fas fa-cog me-1"></i> Manage
                    </a>
                    <a href="course_edit.php?id=<?php echo $course['course_id']; ?>" class="btn btn-outline-secondary btn-sm" title="Edit Course & Modules">
                        <i class="fas fa-edit me-1"></i> Edit & Modules
                    </a>
                    <a href="students.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-outline-info btn-sm" title="Enrolled Students">
                        <i class="fas fa-users"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="alert alert-info">
                <h5>No Courses Yet</h5>
                <p>You haven't created any courses. Click the "Create New Course" button to get started.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Create Course Modal -->
<div class="modal fade" id="createCourseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Course</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createCourseForm">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Course Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="title" name="title" required maxlength="200">
                        <div class="form-text">Maximum 200 characters</div>
                    </div>
                    <div class="mb-3">
                        <label for="course_code" class="form-label">Course Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="course_code" name="course_code" required maxlength="20">
                        <div class="form-text">Unique identifier for your course (e.g., CS101)</div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4" placeholder="Describe what students will learn in this course..."></textarea>
                        <div class="form-text">Optional course description</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_course" class="btn btn-primary">Create Course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-generate course code suggestion
document.getElementById('title').addEventListener('input', function() {
    const title = this.value;
    const courseCodeField = document.getElementById('course_code');
    
    if (title && !courseCodeField.value) {
        const words = title.split(' ');
        let code = '';
        
        if (words.length >= 2) {
            code = words[0].substring(0, 2).toUpperCase() + words[1].substring(0, 3).toUpperCase();
        } else if (words.length === 1) {
            code = words[0].substring(0, 5).toUpperCase();
        }
        
        if (code) {
            const randomNum = Math.floor(Math.random() * 100);
            courseCodeField.value = code + randomNum;
        }
    }
});

// Clear form when modal is closed
document.getElementById('createCourseModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('createCourseForm').reset();
});
</script>

<?php require_once '../includes/footer.php'; ?>
