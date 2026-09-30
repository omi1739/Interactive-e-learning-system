<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('courses.php');
}

$course_id = param_int('id');
$student_id = (int)($_SESSION['user_id'] ?? 0);
$db = new Database();
$conn = $db->getConnection();

// Get course details
$stmt = $conn->prepare("SELECT c.*, u.first_name, u.last_name FROM courses c
                       JOIN users u ON c.instructor_id = u.user_id
                       WHERE c.course_id = ?");
$stmt->execute([$course_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$course) {
    $auth->redirect('courses.php');
}

// The student must actually be enrolled. Everything below, including the
// lesson ids they are allowed to mark complete, is scoped to this course.
if(!student_enrolled_in_course($conn, $course_id, $student_id)) {
    flash_error("You are not enrolled in that course.");
    $auth->redirect('courses.php');
}

// Mark a lesson complete / incomplete.
//
// POST + CSRF, and the lesson id is checked against the published lessons of
// THIS course, so a crafted form cannot mark progress in a course the student
// is not enrolled in.
if($_POST && isset($_POST['toggle_lesson'])) {
    verify_csrf();

    $lesson_id = param_int('lesson_id', 0, $_POST);
    $complete = ($_POST['complete'] ?? '0') === '1';
    $allowed = course_lesson_ids($conn, $course_id);

    if(!in_array($lesson_id, $allowed, true)) {
        flash_error("That lesson is not part of this course.");
    } elseif(!set_lesson_completion($conn, $student_id, $lesson_id, $complete)) {
        flash_error("Could not save your progress. Please try again.");
    } else {
        flash_success($complete ? "Lesson marked complete." : "Lesson marked as not complete.");
    }

    header("Location: course_view.php?id=" . $course_id);
    exit();
}

// Get course modules
$stmt = $conn->prepare("SELECT * FROM modules WHERE course_id = ? AND is_published = TRUE ORDER BY module_order, module_id");
$stmt->execute([$course_id]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load lessons, assignments and the student's own submission for every module
// in one pass each. The old markup ran two queries per module from inside the
// render loop, so a ten-module course issued twenty round trips per page view.
$module_ids = array_map(static fn($m) => (int)$m['module_id'], $modules);
$lessons_by_module = [];
$assignments_by_module = [];

if(!empty($module_ids)) {
    $ph = implode(',', array_fill(0, count($module_ids), '?'));

    $stmt = $conn->prepare("SELECT * FROM lessons
                            WHERE module_id IN ($ph) AND is_published = TRUE
                            ORDER BY module_id, lesson_order, lesson_id");
    $stmt->execute($module_ids);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $lesson) {
        $lessons_by_module[(int)$lesson['module_id']][] = $lesson;
    }

    // LEFT JOIN the student's own submission so each assignment can show
    // whether it is done without a second lookup.
    $stmt = $conn->prepare("SELECT a.*, s.submission_id, s.status AS submission_status,
                                   s.submission_date, s.final_grade
                            FROM assignments a
                            LEFT JOIN submissions s
                                ON s.assignment_id = a.assignment_id AND s.student_id = ?
                            WHERE a.module_id IN ($ph) AND a.is_published = TRUE
                            ORDER BY a.module_id, a.due_date IS NULL, a.due_date, a.assignment_id");
    $stmt->execute(array_merge([$student_id], $module_ids));
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $assignments_by_module[(int)$assignment['module_id']][] = $assignment;
    }
}

// Which lessons are already done.
$all_lesson_ids = [];
foreach($lessons_by_module as $module_lessons) {
    foreach($module_lessons as $lesson) {
        $all_lesson_ids[] = (int)$lesson['lesson_id'];
    }
}
$done_lessons = completed_lesson_ids($conn, $student_id, $all_lesson_ids);

// Real totals for the progress panel.
$total_lessons = count($all_lesson_ids);
$completed_lessons = count(array_filter($all_lesson_ids, static fn($id) => isset($done_lessons[$id])));

$all_assignments = [];
foreach($assignments_by_module as $module_assignments) {
    foreach($module_assignments as $assignment) {
        $all_assignments[] = $assignment;
    }
}
$total_assignments = count($all_assignments);
// A draft is not finished work, so only submitted or graded count.
$submitted_assignments = count(array_filter(
    $all_assignments,
    static fn($a) => $a['submission_id'] !== null
        && in_array($a['submission_status'], ['submitted', 'graded'], true)
));

$total_items = $total_lessons + $total_assignments;
$completed_items = $completed_lessons + $submitted_assignments;

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo e($course['title']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <span class="badge bg-success me-2">Instructor: <?php echo e($course['first_name'] . ' ' . $course['last_name']); ?></span>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Course Content</h5>
            </div>
            <div class="card-body">
                <?php if(count($modules) > 0): ?>
                    <div class="accordion" id="modulesAccordion">
                        <?php foreach($modules as $index => $module): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading<?php echo $module['module_id']; ?>">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" 
                                        data-bs-target="#collapse<?php echo $module['module_id']; ?>" 
                                        aria-expanded="false" aria-controls="collapse<?php echo $module['module_id']; ?>">
                                    Module <?php echo $index + 1; ?>: <?php echo e($module['title']); ?>
                                </button>
                            </h2>
                            <div id="collapse<?php echo $module['module_id']; ?>" class="accordion-collapse collapse" 
                                 aria-labelledby="heading<?php echo $module['module_id']; ?>" data-bs-parent="#modulesAccordion">
                                <div class="accordion-body">
                                    <?php
                                    $mid = (int)$module['module_id'];
                                    $lessons = $lessons_by_module[$mid] ?? [];
                                    $module_assignments = $assignments_by_module[$mid] ?? [];
                                    $module_done = 0;
                                    $module_total = count($lessons) + count($module_assignments);
                                    foreach($lessons as $l) {
                                        if (isset($done_lessons[(int)$l['lesson_id']])) { $module_done++; }
                                    }
                                    foreach($module_assignments as $a) {
                                        if ($a['submission_id'] !== null
                                            && in_array($a['submission_status'], ['submitted', 'graded'], true)) { $module_done++; }
                                    }
                                    ?>
                                    <?php if($module_total > 0): ?>
                                        <p class="small text-muted mb-3">
                                            <?php echo $module_done; ?> of <?php echo $module_total; ?> items complete
                                        </p>
                                    <?php endif; ?>

                                    <?php if(!empty($lessons)): ?>
                                        <ul class="list-unstyled mb-0">
                                            <?php foreach($lessons as $lesson): ?>
                                            <?php $lid = (int)$lesson['lesson_id']; $is_done = isset($done_lessons[$lid]); ?>
                                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                                <div class="min-w-0">
                                                    <i class="fas fa-<?php
                                                        echo $lesson['content_type'] === 'video' ? 'play-circle'
                                                            : ($lesson['content_type'] === 'document' ? 'file-alt'
                                                            : ($lesson['content_type'] === 'images' ? 'images' : 'file-lines'));
                                                    ?> text-primary me-2" aria-hidden="true"></i>
                                                    <?php echo e($lesson['title']); ?>
                                                    <?php if($lesson['duration_minutes']): ?>
                                                        <small class="text-muted">(<?php echo (int)$lesson['duration_minutes']; ?> min)</small>
                                                    <?php endif; ?>
                                                </div>
                                                <form method="POST" class="ms-2">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="lesson_id" value="<?php echo $lid; ?>">
                                                    <input type="hidden" name="complete" value="<?php echo $is_done ? '0' : '1'; ?>">
                                                    <button type="submit" name="toggle_lesson" value="1"
                                                            class="btn btn-sm <?php echo $is_done ? 'btn-success' : 'btn-outline-primary'; ?>">
                                                        <i class="fas fa-<?php echo $is_done ? 'check' : 'circle-notch'; ?>" aria-hidden="true"></i>
                                                        <?php echo $is_done ? 'Completed' : 'Mark complete'; ?>
                                                    </button>
                                                </form>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="text-muted">No lessons available in this module.</p>
                                    <?php endif; ?>

                                    <?php if(!empty($module_assignments)): ?>
                                        <ul class="list-unstyled mb-0 mt-3">
                                            <?php foreach($module_assignments as $assignment): ?>
                                            <?php
                                            $aid = (int)$assignment['assignment_id'];
                                            $state = ui_status_badge($assignment['submission_status'] ?? null, 'Not started');
                                            $submitted = $assignment['submission_id'] !== null
                                                && in_array($assignment['submission_status'], ['submitted', 'graded'], true);
                                            ?>
                                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                                <div class="min-w-0">
                                                    <i class="fas fa-tasks text-warning me-2" aria-hidden="true"></i>
                                                    <?php echo e($assignment['title']); ?>
                                                    <?php if($assignment['due_date']): ?>
                                                        <small class="text-muted">Due: <?php echo ui_date($assignment['due_date'], 'M j, Y'); ?></small>
                                                    <?php endif; ?>
                                                    <?php echo $state; ?>
                                                </div>
                                                <a href="assignment_view.php?id=<?php echo $aid; ?>" class="btn btn-sm btn-warning ms-2">
                                                    <?php echo $submitted ? 'View submission' : 'View assignment'; ?>
                                                </a>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No modules available for this course yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Course Progress</h5>
            </div>
            <div class="card-body">
                <?php echo ui_progress_meter(
                    $completed_items,
                    $total_items,
                    $completed_lessons . ' of ' . $total_lessons . ' lessons, '
                        . $submitted_assignments . ' of ' . $total_assignments . ' assignments submitted',
                    'courseProgress'
                ); ?>

                <hr>

                <p class="mb-2">
                    <strong>Lessons:</strong>
                    <?php echo $completed_lessons; ?>/<?php echo $total_lessons; ?> complete
                </p>
                <p class="mb-0">
                    <strong>Assignments:</strong>
                    <?php echo $submitted_assignments; ?>/<?php echo $total_assignments; ?> submitted
                </p>

                <?php if($total_items > 0 && $completed_items === $total_items): ?>
                    <div class="alert alert-success mt-3 mb-0">
                        <i class="fas fa-graduation-cap me-1" aria-hidden="true"></i>
                        You have finished everything published in this course.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card shadow-sm border-0 mt-3">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold">Quick Actions</h5>
            </div>
            <div class="card-body">
                <a href="assignments.php" class="btn btn-outline-primary w-100 mb-2">
                    <i class="fas fa-tasks me-1"></i> View Assignments
                </a>
                <a href="peer_reviews.php" class="btn btn-outline-warning text-dark w-100 mb-2">
                    <i class="fas fa-user-check me-1"></i> Peer Reviews
                </a>
                <a href="forums.php" class="btn btn-outline-success w-100">
                    <i class="fas fa-comments me-1"></i> Discussion Forums
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
