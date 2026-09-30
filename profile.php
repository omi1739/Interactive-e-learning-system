<?php
require_once 'includes/bootstrap.php';

if(!$auth->isLoggedIn()) {
    $auth->redirect('login.php');
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';
$conn = $db->getConnection();

// Get user profile
$user_profile = $functions->getUserProfile($user_id);

if(!$user_profile) {
    flash_error("Your account could not be loaded.");
    $auth->redirect('dashboard.php');
}

$first_name = (string)($user_profile['first_name'] ?? '');
$last_name = (string)($user_profile['last_name'] ?? '');
$email = (string)($user_profile['email'] ?? '');
$success = '';
$error = '';

// Handle profile update
if($_POST && isset($_POST['update_profile'])) {
    verify_csrf();

    // Without ?? '' a crafted POST omitting a field raises an
    // undefined-key warning and passes null to trim().
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));

    $too_long = static function($value, $max) {
        return mb_strlen($value) > $max;
    };

    if($first_name === '' || $last_name === '') {
        $error = "First and last name are both required.";
    } elseif($too_long($first_name, 50) || $too_long($last_name, 50)) {
        // first_name and last_name are VARCHAR(50). Without this check a
        // longer value reaches the UPDATE and MySQL either truncates it
        // under a non-strict sql_mode or errors out.
        $error = "Names must be 50 characters or fewer.";
    } elseif($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif(mb_strlen($email) > 100) {
        // users.email is VARCHAR(100).
        $error = "Email address must be 100 characters or fewer.";
    } else {
        // email carries a UNIQUE index. updateUserProfile() swallows the
        // resulting PDOException and returns false, so without this check
        // colliding with another account was reported as a generic
        // "Failed to update profile", which reads like a server fault.
        $taken = $conn->prepare("SELECT 1 FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
        $taken->execute([$email, $user_id]);

        if((bool)$taken->fetchColumn()) {
            $error = "That email address is already used by another account.";
        } elseif($functions->updateUserProfile($user_id, $first_name, $last_name, $email)) {
            // Keep the session in step with the row so the header and any
            // page reading $_SESSION shows the new name straight away.
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['email'] = $email;

            $success = "Profile updated.";
            $user_profile = $functions->getUserProfile($user_id);
        } else {
            $error = "Failed to update profile. Please try again.";
        }
    }
}

// Account statistics, computed without per-course round trips.
$stats = [];
if($role === 'student') {
    $enrolled = $functions->getEnrolledCourses($user_id);
    $stats[] = ['label' => 'Enrolled courses', 'value' => count($enrolled), 'icon' => 'fa-graduation-cap',
        'tone' => 'brand'];

    $assignments = $functions->getStudentAssignments($user_id);
    $not_submitted = 0;
    foreach($assignments as $assignment) {
        if(empty($assignment['submission_id'])) {
            $not_submitted++;
        }
    }
    $stats[] = ['label' => 'Not submitted', 'value' => $not_submitted, 'icon' => 'fa-file-circle-exclamation',
        'tone' => $not_submitted > 0 ? 'warning' : 'info'];
} elseif($role === 'instructor') {
    $my_courses = $functions->getCourses($user_id);
    $stats[] = ['label' => 'My courses', 'value' => count($my_courses), 'icon' => 'fa-layer-group', 'tone' => 'brand'];

    // One grouped query. The old loop called getCourseStudents() per course,
    // which loaded a full users row for every enrollment on the profile page.
    $total_students = 0;
    $pending_requests = 0;
    if(count($my_courses) > 0) {
        $ids = array_map(static fn($c) => (int)$c['course_id'], $my_courses);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $count_stmt = $conn->prepare("SELECT
                                          SUM(enrollment_status IN ('approved','completed')) AS enrolled,
                                          SUM(enrollment_status = 'pending') AS pending
                                      FROM enrollments WHERE course_id IN ($in)");
        $count_stmt->execute($ids);
        $row = $count_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total_students = (int)($row['enrolled'] ?? 0);
        $pending_requests = (int)($row['pending'] ?? 0);
    }
    $stats[] = ['label' => 'Enrolled students', 'value' => $total_students, 'icon' => 'fa-users', 'tone' => 'info'];
    $stats[] = ['label' => 'Pending requests', 'value' => $pending_requests, 'icon' => 'fa-clock',
        'tone' => $pending_requests > 0 ? 'warning' : 'info'];
}

require_once 'includes/header.php';
?>

<?php
echo ui_page_header(
    'My profile',
    'Update the name and email address other people see for your work',
    '',
    'Account'
);
?>

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

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-3">
                <?php echo ui_avatar($user_profile['first_name'], $user_profile['last_name'], 'lg'); ?>
                <div class="min-w-0">
                    <h2 class="h5 mb-0 text-truncate"><?php echo e(ui_user_name($user_profile)); ?></h2>
                    <div class="small text-muted">@<?php echo e($user_profile['username']); ?></div>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">First name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name"
                                   maxlength="50" required autocomplete="given-name"
                                   value="<?php echo ui_form_value('first_name', $first_name); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">Last name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                   maxlength="50" required autocomplete="family-name"
                                   value="<?php echo ui_form_value('last_name', $last_name); ?>">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required
                               maxlength="100" autocomplete="email"
                               value="<?php echo ui_form_value('email', $email); ?>">
                        <div class="form-text">
                            Used to sign in and to find your account. It must be unique across the platform.
                        </div>
                    </div>

                    <?php // readonly rather than disabled: a disabled control is
                          // not submitted, and these are the fields the reader
                          // most needs to compare against what they are editing.
                          // They carry no name attribute on purpose, so they
                          // cannot be altered by posting. ?>
                    <div class="mt-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" readonly
                               value="<?php echo e($user_profile['username']); ?>">
                        <div class="form-text">Set at sign-up and cannot be changed here.</div>
                    </div>

                    <div class="mt-3">
                        <span class="form-label d-block">Role</span>
                        <div class="d-flex align-items-center gap-2">
                            <?php echo ui_status_badge($user_profile['role']); ?>
                            <span class="small text-muted">Assigned by an administrator.</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save me-1" aria-hidden="true"></i> Save changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if(count($stats) > 0): ?>
        <div class="row g-3 mb-3">
            <?php foreach($stats as $stat): ?>
            <div class="col-6">
                <?php echo ui_stat($stat['label'], (string)$stat['value'], $stat['icon'], $stat['tone']); ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Account</h2>
            </div>
            <div class="card-body">
                <dl class="mb-0">
                    <?php echo ui_detail_row('Member since', e(ui_date($user_profile['created_at'] ?? null, 'M j, Y', 'Unknown'))); ?>
                    <?php echo ui_detail_row('Last sign-in', e(ui_datetime($user_profile['last_login'] ?? null, 'M j, Y g:i A', 'First sign-in'))); ?>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="h6 mb-0">Quick links</h2>
            </div>
            <div class="list-group list-group-flush">
                <?php if($role === 'student'): ?>
                    <a href="student/dashboard.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-gauge me-2" aria-hidden="true"></i> Dashboard
                    </a>
                    <a href="student/courses.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-graduation-cap me-2" aria-hidden="true"></i> My courses
                    </a>
                    <a href="student/assignments.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-file-lines me-2" aria-hidden="true"></i> Assignments
                    </a>
                    <a href="student/forums.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-comments me-2" aria-hidden="true"></i> Forums
                    </a>
                <?php elseif($role === 'instructor'): ?>
                    <a href="instructor/dashboard.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-gauge me-2" aria-hidden="true"></i> Dashboard
                    </a>
                    <a href="instructor/courses.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-layer-group me-2" aria-hidden="true"></i> My courses
                    </a>
                    <a href="instructor/students.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-users me-2" aria-hidden="true"></i> Students
                    </a>
                    <a href="instructor/assignments.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-file-lines me-2" aria-hidden="true"></i> Assignments
                    </a>
                <?php else: ?>
                    <a href="dashboard.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-gauge me-2" aria-hidden="true"></i> Admin dashboard
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
