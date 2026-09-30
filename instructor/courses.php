<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

// Get database connection
$conn = $db->getConnection();

$form_values = ['title' => '', 'course_code' => '', 'description' => ''];
$form_published = true;
$form_error = false;

// Handle course creation
if($_POST && isset($_POST['create_course'])) {
    verify_csrf();

    // Without ?? '' a crafted POST omitting a field raises an undefined-key
    // warning and passes null to trim().
    $form_values['title'] = trim((string)($_POST['title'] ?? ''));
    $form_values['course_code'] = trim((string)($_POST['course_code'] ?? ''));
    $form_values['description'] = trim((string)($_POST['description'] ?? ''));

    $is_published = isset($_POST['is_published']) ? 1 : 0;
    $form_published = (bool)$is_published;
    $title = $form_values['title'];
    $course_code = $form_values['course_code'];

    if($title === '' || $course_code === '') {
        $_SESSION['error'] = "Course title and code are both required.";
        $form_error = true;
    } elseif(mb_strlen($title) > 200) {
        $_SESSION['error'] = "Course title must be 200 characters or fewer.";
        $form_error = true;
    } elseif(mb_strlen($course_code) > 20) {
        $_SESSION['error'] = "Course code must be 20 characters or fewer.";
        $form_error = true;
    } else {
        // Duplicate codes are also blocked by a UNIQUE index, so the check
        // below is a convenience for the common case; the insert is wrapped
        // to turn a lost race into the same friendly message instead of a 500.
        $check_stmt = $conn->prepare("SELECT course_id FROM courses WHERE course_code = ?");
        $check_stmt->execute([$course_code]);

        if($check_stmt->rowCount() > 0) {
            $_SESSION['error'] = "That course code is already taken. Please choose a different one.";
            $form_error = true;
        } else {
            $stmt = $conn->prepare("INSERT INTO courses (instructor_id, title, description, course_code, is_published)
                                    VALUES (?, ?, ?, ?, ?)");
            try {
                if($stmt->execute([
                    $_SESSION['user_id'],
                    $title,
                    $form_values['description'],
                    $course_code,
                    $is_published
                ])) {
                    $_SESSION['success'] = $is_published
                        ? "Course created and published. Students can now find it in the catalogue."
                        : "Course created as a draft. Publish it from Edit course when you are ready.";
                    header("Location: courses.php");
                    exit();
                }
                $_SESSION['error'] = "Failed to create course. Please try again.";
            } catch(PDOException $ex) {
                if($ex->getCode() === '23000') {
                    $_SESSION['error'] = "That course code is already taken. Please choose a different one.";
                } else {
                    error_log("createCourse failed: " . $ex->getMessage());
                    $_SESSION['error'] = "Failed to create course. Please try again.";
                }
            }
            $form_error = true;
        }
    }
}

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Get instructor's courses
$courses = $functions->getCourses($_SESSION['user_id']);

// Per-course counts in a single query. The old page called
// getCourseStudents() once per card, which pulled a full users row for every
// enrollment purely to count them, so an instructor with 10 courses and 200
// enrollments ran 10 queries returning 200 rows to display 10 numbers.
$course_counts = [];
if(count($courses) > 0) {
    $ids = array_map(static fn($c) => (int)$c['course_id'], $courses);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT c.course_id,
                                  (SELECT COUNT(*) FROM enrollments e
                                    WHERE e.course_id = c.course_id
                                      AND e.enrollment_status = 'approved') AS approved_students,
                                  (SELECT COUNT(*) FROM enrollments e
                                    WHERE e.course_id = c.course_id
                                      AND e.enrollment_status = 'pending') AS pending_students,
                                  (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.course_id) AS module_count,
                                  (SELECT COUNT(*) FROM assignments a
                                     JOIN modules m2 ON a.module_id = m2.module_id
                                    WHERE m2.course_id = c.course_id) AS assignment_count
                           FROM courses c WHERE c.course_id IN ($in)");
    $stmt->execute($ids);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $course_counts[(int)$row['course_id']] = $row;
    }
}

require_once '../includes/header.php';
?>

<?php
echo ui_page_header(
    'My courses',
    count($courses) > 0
        ? count($courses) . ' course' . (count($courses) === 1 ? '' : 's')
            . ', ' . count(array_filter($courses, static fn($c) => (int)$c['is_published'] === 1)) . ' published'
        : 'Create your first course to start building content',
    '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCourseModal">'
        . '<i class="fas fa-plus me-1" aria-hidden="true"></i> Create new course</button>',
    'Teaching'
);
?>

<?php if(!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-1" aria-hidden="true"></i> <?php echo e($success); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
</div>
<?php endif; ?>

<?php if(!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-1" aria-hidden="true"></i> <?php echo e($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
</div>
<?php endif; ?>

<?php if(count($courses) > 0): ?>
    <div class="row g-3">
        <?php foreach($courses as $course): ?>
        <?php
        $cid = (int)$course['course_id'];
        $stats = $course_counts[$cid] ?? ['approved_students' => 0, 'pending_students' => 0, 'module_count' => 0, 'assignment_count' => 0];
        $is_published = (int)$course['is_published'] === 1;
        $pending = (int)$stats['pending_students'];
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <span class="badge badge-soft-neutral"><?php echo e($course['course_code']); ?></span>
                        <?php echo ui_badge($is_published ? 'Published' : 'Draft', $is_published ? 'success' : 'warning',
                            $is_published ? 'fa-circle-check' : 'fa-pen'); ?>
                    </div>

                    <h2 class="h5 mb-2"><?php echo e($course['title']); ?></h2>
                    <p class="text-muted small flex-grow-1">
                        <?php echo e(ui_truncate($course['description'] ?? 'No description', 120)); ?>
                    </p>

                    <?php // Truncation must happen before escaping. The old page
                          // did substr($description, 0, 100) . '...', which always
                          // appended an ellipsis even to a short description and
                          // escaped afterwards. ?>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php echo ui_badge((int)$stats['module_count'] . ' modules', 'neutral', 'fa-layer-group'); ?>
                        <?php echo ui_badge((int)$stats['assignment_count'] . ' assignments', 'neutral', 'fa-file-lines'); ?>
                        <?php echo ui_badge((int)$stats['approved_students'] . ' students', 'primary', 'fa-users'); ?>
                        <?php if($pending > 0): ?>
                            <?php echo ui_badge($pending . ' pending', 'warning', 'fa-clock'); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer bg-transparent d-flex gap-2">
                    <a href="course_manage.php?id=<?php echo $cid; ?>" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="fas fa-cog me-1" aria-hidden="true"></i> Manage
                    </a>
                    <a href="course_edit.php?id=<?php echo $cid; ?>" class="btn btn-outline-secondary btn-sm flex-grow-1"
                       title="Edit course details and modules">
                        <i class="fas fa-edit me-1" aria-hidden="true"></i> Edit
                    </a>
                    <a href="students.php?course_id=<?php echo $cid; ?>" class="btn btn-outline-secondary btn-sm"
                       title="Enrolled students" aria-label="Enrolled students for <?php echo e_attr($course['title']); ?>">
                        <i class="fas fa-users" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?php
    echo ui_empty_state(
        'fa-layer-group',
        'No courses yet',
        'Create a course, add modules and lessons, then publish it for students to enrol.',
        '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCourseModal">'
            . '<i class="fas fa-plus me-1" aria-hidden="true"></i> Create your first course</button>'
    );
    ?>
<?php endif; ?>

<!-- Create Course Modal -->
<div class="modal fade" id="createCourseModal" tabindex="-1" aria-labelledby="createCourseLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="createCourseForm" novalidate>
                <?php echo csrf_field(); ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createCourseLabel">Create new course</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Course title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="title" name="title" required maxlength="200"
                               value="<?php echo ui_form_value('title', $form_values['title']); ?>">
                        <div class="form-text">Up to 200 characters.</div>
                    </div>
                    <div class="mb-3">
                        <label for="course_code" class="form-label">Course code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="course_code" name="course_code" required
                               maxlength="20" autocomplete="off"
                               value="<?php echo ui_form_value('course_code', $form_values['course_code']); ?>">
                        <div class="form-text">Unique across the platform, for example CS101. A code is suggested from the title as you type.</div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"
                                  placeholder="What will students learn in this course?"><?php echo ui_textarea_value($form_values['description']); ?></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_published" name="is_published" value="1"
                               <?php echo $form_published ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_published">
                            Publish immediately
                        </label>
                        <div class="form-text">
                            Leave unchecked to build the course as a draft. Unpublished courses are hidden from the
                            student catalogue but are still visible to you.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_course" class="btn btn-primary">Create course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var titleField = document.getElementById('title');
    var codeField = document.getElementById('course_code');
    var modal = document.getElementById('createCourseModal');
    var form = document.getElementById('createCourseForm');
    if (!titleField || !codeField || !modal || !form) { return; }

    // Suggest a code from the title, but never overwrite one the instructor
    // has already typed. The old version appended Math.random() * 100, so
    // retyping the same title usually produced a different code and the field
    // fought the user; the server rejects duplicates either way.
    titleField.addEventListener('input', function () {
        if (codeField.value.trim() !== '') { return; }
        var words = this.value.trim().split(/\s+/).filter(Boolean);
        var code = '';
        if (words.length >= 2) {
            code = (words[0].slice(0, 2) + words[1].slice(0, 3)).toUpperCase();
        } else if (words.length === 1) {
            code = words[0].slice(0, 5).toUpperCase();
        }
        code = code.replace(/[^A-Z0-9]/g, '').slice(0, 20);
        if (code) { codeField.value = code; }
    });

    // A failed submit re-renders the page, which closes the modal and loses
    // the reason the user was looking at, so reopen it.
    <?php if($form_error): ?>
    new bootstrap.Modal(modal).show();
    <?php endif; ?>

    // Only clear the form when the user actually dismissed a filled-in modal.
    form.addEventListener('submit', function () {
        form.dataset.dirty = '1';
    });
    modal.addEventListener('hidden.bs.modal', function () {
        if (form.dataset.dirty === '1') {
            form.reset();
            delete form.dataset.dirty;
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
