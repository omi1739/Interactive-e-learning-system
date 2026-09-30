<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('courses.php');
}

$course_id = intval($_GET['id'] ?? 0);
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

// Everything below is scoped to $course_id, which is ownership-checked
// above, so no handler here can reach another instructor's roster or forums.

$form_email = '';
$form_forum_title = '';
$form_forum_desc = '';
$reopen = '';

// Handle enrollment status update
if($_POST && isset($_POST['update_status'])) {
    verify_csrf();
    $user_id = intval($_POST['user_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');

    // Only accept statuses that exist in the enrollment_status ENUM. An
    // unvalidated value would be truncated by MySQL or rejected outright.
    $allowed_statuses = ['pending', 'approved', 'rejected', 'completed'];
    if(!in_array($status, $allowed_statuses, true)) {
        $_SESSION['error'] = "Invalid enrollment status.";
    } elseif($user_id <= 0) {
        $_SESSION['error'] = "Invalid student.";
    } else {
        // course_id is ownership-checked above, so this update cannot reach
        // another instructor's roster.
        //
        // Success is judged on execute(), not rowCount(): re-selecting the
        // status a student already has is a legitimate no-op that MySQL
        // reports as 0 affected rows, and the old code turned that into a
        // "Failed to update enrollment status." error.
        $update_stmt = $conn->prepare("UPDATE enrollments
                                       SET enrollment_status = ?
                                       WHERE user_id = ? AND course_id = ?");
        if($update_stmt->execute([$status, $user_id, $course_id])) {
            $name_stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
            $name_stmt->execute([$user_id]);
            $who = $name_stmt->fetch(PDO::FETCH_ASSOC);

            $label = $who ? ui_user_name($who) : 'Student';
            $message = "Enrollment status for " . $label . " is now " . $status . ".";

            // Only 'approved' grants access, so say so when access changes.
            if($status === 'approved') {
                $message .= " They can now open the course.";
            } elseif(in_array($status, ['rejected', 'completed'], true)) {
                $message .= " This revokes their access to the course, its assignments and its forums.";
            }

            $_SESSION['success'] = $message;
            header("Location: course_manage.php?id=" . $course_id);
            exit();
        }
        $_SESSION['error'] = "Failed to update enrollment status.";
    }
}

// Handle manual enrollment
if($_POST && isset($_POST['enroll_student'])) {
    verify_csrf();
    // Without ?? '' a crafted POST omitting the field raises an
    // undefined-key warning and passes null to trim().
    $form_email = trim((string)($_POST['student_email'] ?? ''));

    if($form_email === '') {
        $_SESSION['error'] = "Please enter a student email address.";
        $reopen = 'enrollStudentModal';
    } else {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND role = 'student'");
        $stmt->execute([$form_email]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$student) {
            $_SESSION['error'] = "No student account found with that email address.";
            $reopen = 'enrollStudentModal';
        } else {
            $check_stmt = $conn->prepare("SELECT enrollment_status FROM enrollments
                                         WHERE user_id = ? AND course_id = ?");
            $check_stmt->execute([$student['user_id'], $course_id]);
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if($existing) {
                // A rejected enrollment still occupies the unique user/course
                // key, so re-enrolling has to update the row rather than
                // insert, or the instructor is stuck with a rejected student
                // they can never add again.
                if($existing['enrollment_status'] === 'rejected') {
                    $re_enroll = $conn->prepare("UPDATE enrollments
                                                SET enrollment_status = 'approved', enrolled_at = NOW()
                                                WHERE user_id = ? AND course_id = ?");
                    if($re_enroll->execute([$student['user_id'], $course_id])) {
                        $_SESSION['success'] = "Student re-enrolled and approved.";
                        header("Location: course_manage.php?id=" . $course_id);
                        exit();
                    }
                    $_SESSION['error'] = "Failed to re-enroll student.";
                } else {
                    $_SESSION['error'] = "That student is already enrolled ("
                        . $existing['enrollment_status'] . "). Change the status in the table below instead.";
                }
                $reopen = 'enrollStudentModal';
            } else {
                // Manual enrollment approves immediately, so honour capacity
                // here. The page advertises max_students but nothing ever
                // checked it, so a course could be pushed well over its limit.
                $capacity = (int)$course['max_students'];
                $count_stmt = $conn->prepare("SELECT COUNT(*) FROM enrollments
                                              WHERE course_id = ? AND enrollment_status IN ('approved','completed')");
                $count_stmt->execute([$course_id]);
                $seats_taken = (int)$count_stmt->fetchColumn();

                if($capacity > 0 && $seats_taken >= $capacity) {
                    $_SESSION['error'] = "This course is at its capacity of {$capacity} students. "
                        . "Raise the limit on the Edit course page, or set a status on the pending requests below.";
                    $reopen = 'enrollStudentModal';
                } else {
                    $enroll_stmt = $conn->prepare("INSERT INTO enrollments (user_id, course_id, enrollment_status)
                                                  VALUES (?, ?, 'approved')");
                    if($enroll_stmt->execute([$student['user_id'], $course_id])) {
                        $_SESSION['success'] = "Student enrolled and approved.";
                        header("Location: course_manage.php?id=" . $course_id);
                        exit();
                    }
                    $_SESSION['error'] = "Failed to enroll student.";
                    $reopen = 'enrollStudentModal';
                }
            }
        }
    }
}

// Handle forum creation
if($_POST && isset($_POST['create_forum'])) {
    verify_csrf();
    $form_forum_title = trim((string)($_POST['forum_title'] ?? ''));
    $form_forum_desc = trim((string)($_POST['forum_desc'] ?? ''));

    if($form_forum_title === '') {
        $_SESSION['error'] = "Forum title is required.";
        $reopen = 'createForumModal';
    } elseif(mb_strlen($form_forum_title) > 200) {
        $_SESSION['error'] = "Forum title must be 200 characters or fewer.";
        $reopen = 'createForumModal';
    } else {
        if($functions->createForum($course_id, $form_forum_title, $form_forum_desc)) {
            $_SESSION['success'] = "Discussion forum created.";
            header("Location: course_manage.php?id=" . $course_id);
            exit();
        }
        $_SESSION['error'] = "Failed to create forum.";
        $reopen = 'createForumModal';
    }
}

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Get course forums
$course_forums = $functions->getCourseForums($course_id);

// Get enrolled students
$stmt = $conn->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email, u.username,
                               e.enrollment_status, e.enrolled_at, e.grade
                        FROM enrollments e
                        JOIN users u ON e.user_id = u.user_id
                        WHERE e.course_id = ?
                        ORDER BY FIELD(e.enrollment_status, 'pending', 'approved', 'completed', 'rejected'),
                                 e.enrolled_at DESC");
$stmt->execute([$course_id]);
$course_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get course statistics
$by_status = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'completed' => 0];
foreach($course_students as $student) {
    $s = (string)($student['enrollment_status'] ?? '');
    if(isset($by_status[$s])) {
        $by_status[$s]++;
    }
}
$total_students = count($course_students);
$approved_students = $by_status['approved'];
$pending_students = $by_status['pending'];
$capacity = (int)$course['max_students'];

// Seats are consumed by approved and completed students, because only
// 'approved' is treated as access elsewhere in the app.
$seats_taken = $approved_students + $by_status['completed'];
$seats_left = $capacity > 0 ? max(0, $capacity - $seats_taken) : null;
$is_published = (int)$course['is_published'] === 1;

require_once '../includes/header.php';
?>

<?php
$actions = '<a href="course_edit.php?id=' . $course_id . '" class="btn btn-outline-primary">'
    . '<i class="fas fa-edit me-1" aria-hidden="true"></i> Edit curriculum &amp; modules</a>'
    . ' <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#createForumModal">'
    . '<i class="fas fa-comments me-1" aria-hidden="true"></i> Add forum</button>'
    . ' <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollStudentModal">'
    . '<i class="fas fa-user-plus me-1" aria-hidden="true"></i> Enroll student</button>';

echo ui_page_header(
    $course['title'],
    'Code ' . $course['course_code'] . ' - manage roster and forums',
    $actions,
    'Teaching'
);
?>

<?php echo ui_breadcrumbs([
    ['label' => 'My courses', 'url' => 'courses.php'],
    ['label' => $course['course_code']],
], 'Dashboard', 'dashboard.php'); ?>

<?php if(!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert" data-autodismiss="8000">
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

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <?php echo ui_stat('Enrolled', (string)$seats_taken, 'fa-users', 'brand',
            $capacity > 0 ? 'of ' . $capacity . ' seats taken' : 'No capacity limit set'); ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php echo ui_stat('Pending', (string)$pending_students, 'fa-clock', 'warning',
            $pending_students > 0 ? 'Waiting on your approval' : 'No requests waiting'); ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php echo ui_stat('Forums', (string)count($course_forums), 'fa-comments', 'info',
            count($course_forums) > 0 ? 'Discussion spaces' : 'None created yet'); ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php echo ui_stat('Visibility', $is_published ? 'Published' : 'Draft',
            $is_published ? 'fa-circle-check' : 'fa-pen', $is_published ? 'success' : 'warning',
            $is_published ? 'Visible in the student catalogue' : 'Hidden from students'); ?>
    </div>
</div>

<?php if($capacity > 0 && $seats_taken > $capacity): ?>
<div class="alert alert-warning" role="alert">
    <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>
    This course has <strong><?php echo $seats_taken; ?></strong> students enrolled but a capacity of
    <strong><?php echo $capacity; ?></strong>. Raise the limit on the
    <a href="course_edit.php?id=<?php echo $course_id; ?>">Edit course</a> page, or reject an enrollment below.
</div>
<?php endif; ?>

<?php if(!$is_published): ?>
<div class="alert alert-info" role="alert">
    <i class="fas fa-eye-slash me-1" aria-hidden="true"></i>
    This course is a <strong>draft</strong>, so students cannot find or enrol in it. Publish it from
    <a href="course_edit.php?id=<?php echo $course_id; ?>">Edit course</a>.
</div>
<?php endif; ?>

<!-- Enrolled Students -->
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h5 mb-0">
            <i class="fas fa-users me-1" aria-hidden="true"></i> Enrolled students
        </h2>
        <?php echo ui_badge((string)$total_students . ' total', 'neutral'); ?>
    </div>
    <div class="card-body">
        <?php if($total_students > 0): ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <caption class="visually-hidden">
                        Students enrolled in <?php echo e($course['title']); ?>, with enrollment status
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Student</th>
                            <th scope="col">Username</th>
                            <th scope="col">Enrolled</th>
                            <th scope="col">Status</th>
                            <th scope="col">Course grade</th>
                            <th scope="col">Change status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($course_students as $student): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php echo ui_avatar($student['first_name'], $student['last_name'], 'sm'); ?>
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-truncate"><?php echo e(ui_user_name($student)); ?></div>
                                        <div class="small text-muted text-truncate"><?php echo e($student['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-muted small">@<?php echo e($student['username']); ?></td>
                            <td class="text-nowrap small text-muted">
                                <?php echo e(ui_date($student['enrolled_at'], 'M j, Y', 'Unknown')); ?>
                            </td>
                            <td><?php echo ui_status_badge($student['enrollment_status']); ?></td>
                            <td>
                                <?php // enrollments.grade is not written anywhere in
                                      // the application, and nothing defines it as a
                                      // percentage. The old markup appended "%",
                                      // which asserted a unit the column does not
                                      // carry, so it is shown as a plain number. ?>
                                <?php if($student['grade'] !== null && $student['grade'] !== ''): ?>
                                    <?php echo ui_num($student['grade']); ?>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="d-flex align-items-center gap-2" data-auto-submit>
                                    <?php echo csrf_field(); ?>
                                    <?php // onchange="this.form.submit()" does not carry a
                                          // submit button's name/value, so the action flag
                                          // has to be a hidden field or the status is
                                          // never saved. ?>
                                    <input type="hidden" name="update_status" value="1">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$student['user_id']; ?>">
                                    <label class="visually-hidden" for="status_<?php echo (int)$student['user_id']; ?>">
                                        Status for <?php echo e(ui_user_name($student)); ?>
                                    </label>
                                    <select name="status" id="status_<?php echo (int)$student['user_id']; ?>"
                                            class="form-select form-select-sm" data-auto-submit>
                                        <?php foreach(['pending', 'approved', 'rejected', 'completed'] as $opt): ?>
                                            <option value="<?php echo $opt; ?>"
                                                <?php echo $student['enrollment_status'] === $opt ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Save</button>
                                </form>
                                <div class="small text-muted mt-1">
                                    Only <strong>approved</strong> gives access to the course.
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?php
            echo ui_empty_state(
                'fa-users',
                'No students enrolled',
                'Enrol an existing account by email, or wait for students to request a place from the catalogue.',
                '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollStudentModal">'
                    . '<i class="fas fa-user-plus me-1" aria-hidden="true"></i> Enrol the first student</button>'
            );
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- Discussion Forums -->
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h5 mb-0">
            <i class="fas fa-comments me-1" aria-hidden="true"></i> Discussion forums
        </h2>
        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#createForumModal">
            <i class="fas fa-plus me-1" aria-hidden="true"></i> Add forum
        </button>
    </div>
    <div class="card-body">
        <?php if(count($course_forums) > 0): ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <caption class="visually-hidden">Discussion forums in <?php echo e($course['title']); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Forum</th>
                            <th scope="col">Posts</th>
                            <th scope="col">Last activity</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($course_forums as $forum): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo e($forum['title']); ?></div>
                                <div class="small text-muted">
                                    <?php echo e(ui_truncate($forum['description'] ?? 'No description', 110)); ?>
                                </div>
                            </td>
                            <td><?php echo ui_badge((int)($forum['post_count'] ?? 0) . ' posts', 'neutral'); ?></td>
                            <td class="text-nowrap small text-muted">
                                <?php echo e(ui_date($forum['last_activity'] ?? null, 'M j, Y', 'Never')); ?>
                            </td>
                            <td class="text-end">
                                <?php // rel="noopener" stops the opened tab from
                                      // reaching back through window.opener. ?>
                                <a href="../student/forum_view.php?id=<?php echo (int)$forum['forum_id']; ?>"
                                   class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">
                                    View forum
                                    <span class="visually-hidden">
                                        <?php echo e($forum['title']); ?> (opens in a new tab)
                                    </span>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?php
            echo ui_empty_state(
                'fa-comments',
                'No forums yet',
                'Create a discussion space so students can ask questions outside the assignment flow.',
                '<button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#createForumModal">'
                    . '<i class="fas fa-plus me-1" aria-hidden="true"></i> Create the first forum</button>'
            );
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- Enroll Student Modal -->
<div class="modal fade" id="enrollStudentModal" tabindex="-1" aria-labelledby="enrollStudentLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="enrollStudentLabel">Enrol a student</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="student_email" class="form-label">Student email address</label>
                        <input type="email" class="form-control" id="student_email" name="student_email" required
                               autocomplete="off" placeholder="student@example.com"
                               value="<?php echo ui_form_value('student_email', $form_email); ?>">
                        <div class="form-text">
                            The student must already have an account. They are enrolled and approved immediately.
                            <?php if($seats_left !== null): ?>
                                <strong><?php echo $seats_left; ?></strong> of <?php echo $capacity; ?> seats left.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="enroll_student" class="btn btn-primary">Enrol student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Forum Modal -->
<div class="modal fade" id="createForumModal" tabindex="-1" aria-labelledby="createForumLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createForumLabel">Create a discussion forum</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="forum_title" class="form-label">Forum title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="forum_title" name="forum_title" required
                               maxlength="200" placeholder="General course discussion"
                               value="<?php echo ui_form_value('forum_title', $form_forum_title); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="forum_desc" class="form-label">Description or guidelines</label>
                        <textarea class="form-control" id="forum_desc" name="forum_desc" rows="3"
                                  placeholder="What is this space for?"><?php echo ui_textarea_value($form_forum_desc); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_forum" class="btn btn-success">Create forum</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if($reopen !== ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById(<?php echo json_encode($reopen); ?>);
    if (el) { new bootstrap.Modal(el).show(); }
});
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
