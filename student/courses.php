<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

$conn = $db->getConnection();

// Handle course enrollment
if ($_POST && isset($_POST['enroll_course'])) {
    verify_csrf();
    $course_id = intval($_POST['course_id'] ?? 0);

    if ($course_id <= 0) {
        $_SESSION['error'] = "Invalid course selection.";
    } else {
        // Only published courses can be enrolled in.
        $course_stmt = $conn->prepare("SELECT course_id FROM courses
                                       WHERE course_id = ? AND is_published = 1");
        $course_stmt->execute([$course_id]);
        $course_exists = $course_stmt->rowCount() > 0;

        // Check if already enrolled
        $check_stmt = $conn->prepare("SELECT enrollment_id, enrollment_status FROM enrollments
                                      WHERE user_id = ? AND course_id = ?");
        $check_stmt->execute([$_SESSION['user_id'], $course_id]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course_exists) {
            $_SESSION['error'] = "That course is not available.";
        } elseif ($existing && in_array($existing['enrollment_status'], ['pending', 'approved', 'completed'], true)) {
            $_SESSION['error'] = "You are already enrolled in this course.";
        } elseif ($existing) {
            // Previously rejected: let the student request access again.
            $stmt = $conn->prepare("UPDATE enrollments
                                    SET enrollment_status = 'pending', enrolled_at = NOW()
                                    WHERE user_id = ? AND course_id = ?");
            if ($stmt->execute([$_SESSION['user_id'], $course_id])) {
                $_SESSION['success'] = "Your enrollment request was sent to the instructor.";
                header("Location: courses.php");
                exit();
            }
            $_SESSION['error'] = "Failed to enroll in course. Please try again.";
        } else {
            // Enroll as "pending": the instructor must approve before any
            // course content becomes visible to the student.
            $stmt = $conn->prepare("INSERT INTO enrollments (user_id, course_id, enrollment_status)
                                    VALUES (?, ?, 'pending')");
            if ($stmt->execute([$_SESSION['user_id'], $course_id])) {
                $_SESSION['success'] = "Enrollment requested! The instructor must approve your access before you can view the course.";
                header("Location: courses.php");
                exit();
            } else {
                $_SESSION['error'] = "Failed to enroll in course. Please try again.";
            }
        }
    }
}

// Get enrolled courses
$enrolled_courses = $functions->getEnrolledCourses($_SESSION['user_id']);

// Get available courses
$available_courses = $functions->getAvailableCourses($_SESSION['user_id']);

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$approved_courses = array_values(array_filter($enrolled_courses, static function($c) {
    return ($c['enrollment_status'] ?? '') === 'approved';
}));
$pending_courses = array_values(array_filter($enrolled_courses, static function($c) {
    return ($c['enrollment_status'] ?? '') === 'pending';
}));

require_once '../includes/header.php';
?>

<?php
echo ui_page_header(
    'Courses',
    count($approved_courses) > 0
        ? count($approved_courses) . ' active'
            . (count($pending_courses) > 0 ? ', ' . count($pending_courses) . ' awaiting approval' : '')
        : 'Your enrollments and the course catalogue',
    '',
    'Learning'
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

<?php // Truncation must happen before escaping. The previous
      // substr(htmlspecialchars($description), 0, 110) cut the escaped
      // string, so a description containing "&" could render a
      // half-written entity like "&am". ui_truncate() truncates first
      // and escapes once. ?>
?>

<ul class="nav nav-pills mb-4" id="courseTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="enrolled-tab" data-bs-toggle="tab"
                data-bs-target="#enrolled-content" type="button" role="tab"
                aria-controls="enrolled-content" aria-selected="true">
            <i class="fas fa-graduation-cap me-1" aria-hidden="true"></i>
            My courses <span class="badge badge-soft-primary ms-1"><?php echo count($enrolled_courses); ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="catalog-tab" data-bs-toggle="tab"
                data-bs-target="#catalog-content" type="button" role="tab"
                aria-controls="catalog-content" aria-selected="false">
            <i class="fas fa-search me-1" aria-hidden="true"></i>
            Catalogue <span class="badge badge-soft-neutral ms-1"><?php echo count($available_courses); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content" id="courseTabContent">

    <div class="tab-pane fade show active" id="enrolled-content" role="tabpanel" aria-labelledby="enrolled-tab">
        <?php if(count($enrolled_courses) > 0): ?>
            <div class="row g-3">
                <?php foreach($enrolled_courses as $course): ?>
                <?php $cid = (int)$course['course_id']; ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <span class="badge badge-soft-neutral"><?php echo e($course['course_code']); ?></span>
                                <?php echo ui_status_badge($course['enrollment_status'] ?? null); ?>
                            </div>

                            <h2 class="h5 mb-2"><?php echo e($course['title']); ?></h2>
                            <p class="text-muted small flex-grow-1 mb-3">
                                <?php echo e(ui_truncate($course['description'] ?? 'No description available.', 110)); ?>
                            </p>

                            <div class="border-top pt-3">
                                <?php echo ui_detail_row('Instructor', e(ui_user_name($course))); ?>
                                <?php echo ui_detail_row('Enrolled', e(ui_date($course['enrolled_at'] ?? null, 'M j, Y', 'Not recorded'))); ?>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <?php if(($course['enrollment_status'] ?? '') === 'approved'): ?>
                                <a href="course_view.php?id=<?php echo $cid; ?>" class="btn btn-primary w-100">
                                    <i class="fas fa-arrow-right me-1" aria-hidden="true"></i> Enter course
                                </a>
                            <?php elseif(($course['enrollment_status'] ?? '') === 'pending'): ?>
                                <button class="btn btn-outline-warning w-100" disabled>
                                    <i class="fas fa-clock me-1" aria-hidden="true"></i> Awaiting approval
                                </button>
                            <?php else: ?>
                                <span class="d-block text-center text-muted small py-2">
                                    This enrollment is <?php echo e($course['enrollment_status'] ?? 'inactive'); ?>.
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php
            echo ui_empty_state(
                'fa-user-graduate',
                'You are not enrolled in any courses yet',
                'Browse the catalogue to find a topic and request access.',
                // A button, not an anchor without href: an <a> with no href is
                // not focusable, so the empty-state shortcut would be
                // unreachable by keyboard.
                '<button type="button" class="btn btn-primary" data-bs-toggle="tab"'
                    . ' data-bs-target="#catalog-content">'
                    . 'Browse the catalogue</button>'
            );
            ?>
        <?php endif; ?>
    </div>

    <div class="tab-pane fade" id="catalog-content" role="tabpanel" aria-labelledby="catalog-tab">
        <?php if(count($available_courses) > 0): ?>
            <div class="row g-3">
                <?php foreach($available_courses as $course): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <span class="badge badge-soft-neutral align-self-start mb-2">
                                <?php echo e($course['course_code']); ?>
                            </span>
                            <h2 class="h5 mb-2"><?php echo e($course['title']); ?></h2>
                            <p class="text-muted small flex-grow-1 mb-3">
                                <?php echo e(ui_truncate($course['description'] ?? 'No description available.', 120)); ?>
                            </p>
                            <div class="border-top pt-3">
                                <?php echo ui_detail_row('Instructor', e(ui_user_name($course))); ?>
                                <?php echo ui_detail_row('Capacity', e((string)($course['max_students'] ?? 0)) . ' students'); ?>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="course_id" value="<?php echo (int)$course['course_id']; ?>">
                                <button type="submit" name="enroll_course" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-user-plus me-1" aria-hidden="true"></i> Request enrollment
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php echo ui_empty_state(
                'fa-check-circle',
                count($enrolled_courses) > 0 ? 'Nothing left in the catalogue' : 'The catalogue is empty',
                count($enrolled_courses) > 0
                    ? 'You have an enrollment in every published course.'
                    : 'No published courses are available right now. Check back later.'
            ); ?>
        <?php endif; ?>
    </div>

</div>

<?php require_once '../includes/footer.php'; ?>
