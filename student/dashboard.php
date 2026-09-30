<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

$conn = $db->getConnection();

// Get student statistics
$enrolled_courses = $functions->getEnrolledCourses($_SESSION['user_id']);
$assignments = $functions->getStudentAssignments($_SESSION['user_id']);

// Calculate statistics
$total_courses = count($enrolled_courses);
$approved_courses = count(array_filter($enrolled_courses, function($course) {
    return $course['enrollment_status'] == 'approved';
}));

// Average grade. This was a raw point average printed with a "%" sign, which
// claimed a scale the number was never on: a 17/20 assignment rendered as
// "85.0" only by luck, and a 17/40 one rendered as a wildly wrong 42.5%.
$earned_points = 0.0;
$possible_points = 0.0;
$graded_assignments = 0;
$pending_assignments = 0;
$submitted_assignments = 0;

foreach($assignments as $assignment) {
    if(!$assignment['submission_id']) {
        $pending_assignments++;
        continue;
    }
    $submitted_assignments++;
    if($assignment['submission_status'] == 'graded' && $assignment['final_grade'] !== null) {
        $graded_assignments++;
        $earned_points += (float)$assignment['final_grade'];
        $possible_points += (float)($assignment['max_points'] ?? 0);
    }
}

$average_grade = $graded_assignments > 0 ? $earned_points / $graded_assignments : null;
$average_grade_pct = ui_percent($earned_points, $possible_points);

// Per-course progress for the course cards, including real lesson completion.
$approved_enrollments = array_values(array_filter($enrolled_courses, static function($c) {
    return ($c['enrollment_status'] ?? '') === 'approved';
}));

$course_progress = [];
if(count($approved_enrollments) > 0) {
    $course_ids = array_map('intval', array_column($approved_enrollments, 'course_id'));
    $in = implode(',', array_fill(0, count($course_ids), '?'));

    $stmt = $conn->prepare("SELECT m.course_id, COUNT(*) AS total_lessons,
                                  COALESCE(SUM(CASE WHEN lp.is_completed = 1 THEN 1 ELSE 0 END), 0) AS done_lessons
                           FROM lessons l
                           JOIN modules m ON l.module_id = m.module_id
                           LEFT JOIN lesson_progress lp
                                ON lp.lesson_id = l.lesson_id AND lp.user_id = ?
                           WHERE m.course_id IN ($in) AND l.is_published = TRUE AND m.is_published = TRUE
                           GROUP BY m.course_id");
    $stmt->execute(array_merge([(int)$_SESSION['user_id']], $course_ids));
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $course_progress[(int)$row['course_id']]['lessons_total'] = (int)$row['total_lessons'];
        $course_progress[(int)$row['course_id']]['lessons_done'] = (int)$row['done_lessons'];
    }

    $stmt = $conn->prepare("SELECT m.course_id, COUNT(*) AS total_assignments,
                                  COALESCE(SUM(CASE WHEN s.submission_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS done_assignments
                           FROM assignments a
                           JOIN modules m ON a.module_id = m.module_id
                           LEFT JOIN submissions s
                                ON s.assignment_id = a.assignment_id AND s.student_id = ?
                           WHERE m.course_id IN ($in) AND a.is_published = TRUE AND m.is_published = TRUE
                           GROUP BY m.course_id");
    $stmt->execute(array_merge([(int)$_SESSION['user_id']], $course_ids));
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $course_progress[(int)$row['course_id']]['assignments_total'] = (int)$row['total_assignments'];
        $course_progress[(int)$row['course_id']]['assignments_done'] = (int)$row['done_assignments'];
    }
}

// Get pending peer reviews. Reviews start as 'assigned' and only become
// 'in_progress' once opened, so counting only 'in_progress' hid every review
// the student had not started yet.
$stmt = $conn->prepare("SELECT COUNT(*) as pending_reviews
                       FROM peer_reviews
                       WHERE reviewer_id = ? AND status IN ('assigned', 'in_progress')");
$stmt->execute([$_SESSION['user_id']]);
$pending_reviews = (int)$stmt->fetchColumn();

// Get recent assignments (next 7 days)
$stmt = $conn->prepare("SELECT a.*, c.title as course_title 
                       FROM assignments a
                       JOIN modules m ON a.module_id = m.module_id
                       JOIN courses c ON m.course_id = c.course_id
                       JOIN enrollments e ON c.course_id = e.course_id
                       WHERE e.user_id = ? AND e.enrollment_status = 'approved' 
                       AND a.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
                       AND a.is_published = TRUE
                       ORDER BY a.due_date ASC
                       LIMIT 5");
$stmt->execute([$_SESSION['user_id']]);
$upcoming_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recently graded assignments
$stmt = $conn->prepare("SELECT a.*, c.title as course_title, s.final_grade, s.instructor_feedback
                       FROM assignments a
                       JOIN modules m ON a.module_id = m.module_id
                       JOIN courses c ON m.course_id = c.course_id
                       JOIN submissions s ON a.assignment_id = s.assignment_id
                       WHERE s.student_id = ? 
                       AND s.status = 'graded' 
                       AND s.final_grade IS NOT NULL
                       ORDER BY s.submission_date DESC
                       LIMIT 5");
$stmt->execute([$_SESSION['user_id']]);
$recent_grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<?php
echo ui_page_header(
    'Student Dashboard',
    $pending_assignments > 0 || $pending_reviews > 0
        ? $pending_assignments . ' assignment' . ($pending_assignments === 1 ? '' : 's')
            . ' and ' . $pending_reviews . ' peer review' . ($pending_reviews === 1 ? '' : 's') . ' need attention.'
        : 'You are all caught up.',
    '<a href="courses.php" class="btn btn-outline-secondary">'
        . '<i class="fas fa-book me-1" aria-hidden="true"></i> My Courses</a>'
        . '<a href="assignments.php" class="btn btn-primary">'
        . '<i class="fas fa-tasks me-1" aria-hidden="true"></i> Assignments</a>',
    'Overview'
);
?>

<div class="row g-3 mb-4">
    <?php
    echo ui_stat('Enrolled courses', (string)$approved_courses, 'fa-book', 'brand',
        $total_courses > $approved_courses
            ? ($total_courses - $approved_courses) . ' awaiting approval'
            : '');
    echo ui_stat('Assignments to submit', (string)$pending_assignments, 'fa-tasks',
        $pending_assignments > 0 ? 'warning' : 'success',
        $submitted_assignments . ' of ' . count($assignments) . ' submitted');
    echo ui_stat('Peer reviews due', (string)$pending_reviews, 'fa-comments',
        $pending_reviews > 0 ? 'accent' : 'info',
        $pending_reviews > 0 ? 'Waiting on you' : 'None waiting');
    echo ui_stat('Average grade', $average_grade_pct === null ? '--' : ui_num($average_grade_pct, 1) . '%',
        'fa-chart-line', 'success',
        $average_grade === null
            ? 'No grades yet'
            : ui_num($average_grade, 1) . ' pts across ' . $graded_assignments . ' graded');
    ?>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <!-- My Courses -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-book me-2" aria-hidden="true"></i> My Courses
                    <span class="badge badge-soft-primary ms-2"><?php echo $approved_courses; ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php if($approved_courses > 0): ?>
                    <div class="row g-3">
                        <?php foreach($approved_enrollments as $course): ?>
                            <?php
                            $cid = (int)$course['course_id'];
                            $p = $course_progress[$cid] ?? [
                                'lessons_total' => 0, 'lessons_done' => 0,
                                'assignments_total' => 0, 'assignments_done' => 0
                            ];
                            $pct = ui_percent(
                                $p['lessons_done'] + $p['assignments_done'],
                                $p['lessons_total'] + $p['assignments_total']
                            );
                            ?>
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h6 class="card-title mb-1"><?php echo e($course['title']); ?></h6>
                                        <p class="card-text small text-muted mb-2">
                                            <?php echo e(ui_truncate($course['description'] ?? '', 90)); ?>
                                        </p>
                                        <p class="card-text small mb-3">
                                            <i class="fas fa-user-tie me-1" aria-hidden="true"></i>
                                            <span class="text-muted"><?php echo e(ui_user_name($course)); ?></span>
                                        </p>
                                        <?php
                                        echo ui_progress_meter(
                                            $p['lessons_done'] + $p['assignments_done'],
                                            $p['lessons_total'] + $p['assignments_total'],
                                            $p['lessons_done'] . '/' . $p['lessons_total'] . ' lessons, '
                                                . $p['assignments_done'] . '/' . $p['assignments_total'] . ' assignments',
                                            'course' . $cid
                                        );
                                        ?>
                                    </div>
                                    <div class="card-footer bg-transparent d-flex gap-2">
                                        <a href="course_view.php?id=<?php echo $cid; ?>" class="btn btn-primary btn-sm">
                                            Open course
                                        </a>
                                        <a href="forums.php?course=<?php echo $cid; ?>" class="btn btn-outline-secondary btn-sm">
                                            Forums
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php
                    echo ui_empty_state(
                        'fa-book-open',
                        'No courses yet',
                        'Browse the catalogue and request an enrollment to get started.',
                        '<a href="courses.php" class="btn btn-primary">Browse Courses</a>'
                    );
                    ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Grades -->
        <?php if(count($recent_grades) > 0): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-award me-2" aria-hidden="true"></i> Recently Graded
                    <span class="badge badge-soft-success ms-2"><?php echo count($recent_grades); ?></span>
                </h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach($recent_grades as $assignment): ?>
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between align-items-start gap-2">
                        <div>
                            <h6 class="mb-1"><?php echo e($assignment['title']); ?></h6>
                            <p class="mb-0 small text-muted"><?php echo e($assignment['course_title']); ?></p>
                        </div>
                        <?php echo ui_grade_badge($assignment['final_grade'], $assignment['max_points']); ?>
                    </div>
                    <?php if(!empty($assignment['instructor_feedback'])): ?>
                        <p class="mb-0 small mt-2">
                            <strong>Feedback:</strong>
                            <?php echo e(ui_truncate($assignment['instructor_feedback'], 140)); ?>
                        </p>
                    <?php endif; ?>
                    <a href="assignment_view.php?id=<?php echo (int)$assignment['assignment_id']; ?>"
                       class="btn btn-sm btn-outline-success mt-2">
                        View assignment
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Upcoming Assignments -->
        <?php if(count($upcoming_assignments) > 0): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-clock me-2" aria-hidden="true"></i> Due in the next 7 days
                    <span class="badge badge-soft-warning ms-2"><?php echo count($upcoming_assignments); ?></span>
                </h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach($upcoming_assignments as $assignment): ?>
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between align-items-start gap-2">
                        <div>
                            <h6 class="mb-1"><?php echo e($assignment['title']); ?></h6>
                            <p class="mb-0 small text-muted"><?php echo e($assignment['course_title']); ?></p>
                        </div>
                        <?php echo ui_due_badge($assignment['due_date']); ?>
                    </div>
                    <a href="assignment_view.php?id=<?php echo (int)$assignment['assignment_id']; ?>"
                       class="btn btn-sm btn-outline-warning mt-2">
                        View assignment
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Quick actions</h5>
            </div>
            <div class="card-body d-grid gap-2">
                <a href="courses.php" class="btn btn-outline-primary text-start">
                    <i class="fas fa-book me-2" aria-hidden="true"></i> My Courses
                </a>
                <a href="assignments.php" class="btn btn-outline-success text-start">
                    <i class="fas fa-tasks me-2" aria-hidden="true"></i> Assignments
                </a>
                <a href="forums.php" class="btn btn-outline-info text-start">
                    <i class="fas fa-comments me-2" aria-hidden="true"></i> Discussion Forums
                </a>
                <a href="peer_reviews.php" class="btn btn-outline-warning text-start">
                    <i class="fas fa-users me-2" aria-hidden="true"></i> Peer Reviews
                </a>
            </div>
        </div>

        <!-- Assignment breakdown -->
        <?php
        $assignment_total = count($assignments);
        echo ui_progress_meter(
            $submitted_assignments,
            $assignment_total,
            $submitted_assignments . ' of ' . $assignment_total . ' assignments submitted',
            'dashSubmissions'
        );
        ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Where you stand</h5>
            </div>
            <div class="card-body">
                <div class="row text-center g-3">
                    <div class="col-4">
                        <div class="fs-4 fw-bold text-warning"><?php echo $pending_assignments; ?></div>
                        <div class="small text-muted">Not submitted</div>
                    </div>
                    <div class="col-4">
                        <div class="fs-4 fw-bold text-info">
                            <?php echo max(0, $submitted_assignments - $graded_assignments); ?>
                        </div>
                        <div class="small text-muted">Awaiting grade</div>
                    </div>
                    <div class="col-4">
                        <div class="fs-4 fw-bold text-success"><?php echo $graded_assignments; ?></div>
                        <div class="small text-muted">Graded</div>
                    </div>
                </div>
                <hr>
                <?php if($graded_assignments > 0): ?>
                    <?php
                    echo ui_detail_row('Points earned', ui_num($earned_points, 2));
                    echo ui_detail_row('Points possible', ui_num($possible_points, 2));
                    echo ui_detail_row('Average per assignment', ui_num($average_grade, 2));
                    echo ui_detail_row('Average as a percentage',
                        $average_grade_pct === null ? '--' : ui_num($average_grade_pct, 1) . '%');
                    ?>
                <?php else: ?>
                    <p class="mb-0 text-muted small">Nothing has been graded yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Grade Distribution -->
        <?php if($graded_assignments > 0): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Grade distribution</h5>
            </div>
            <div class="card-body">
                <?php
                // Only rows that actually count as graded, and the same guard as
                // everywhere else: an assignment with no max_points cannot be
                // turned into a percentage.
                $grade_ranges = [
                    'A (90-100)' => 0,
                    'B (80-89)' => 0,
                    'C (70-79)' => 0,
                    'D (60-69)' => 0,
                    'F (under 60)' => 0
                ];
                $distribution_total = 0;

                foreach($assignments as $assignment) {
                    if(($assignment['submission_status'] ?? '') !== 'graded'
                        || $assignment['final_grade'] === null) {
                        continue;
                    }
                    $pct = ui_percent($assignment['final_grade'], $assignment['max_points'] ?? 0);
                    if($pct === null) {
                        continue;
                    }
                    $distribution_total++;
                    if($pct >= 90)      $grade_ranges['A (90-100)']++;
                    elseif($pct >= 80)  $grade_ranges['B (80-89)']++;
                    elseif($pct >= 70)  $grade_ranges['C (70-79)']++;
                    elseif($pct >= 60)  $grade_ranges['D (60-69)']++;
                    else                $grade_ranges['F (under 60)']++;
                }
                ?>
                <?php if($distribution_total > 0): ?>
                    <?php foreach($grade_ranges as $range => $count): ?>
                        <?php // ui_progress() takes a percentage, not a done/total pair. ?>
                        <?php echo ui_progress(($count / $distribution_total) * 100, $range, 'primary'); ?>
                    <?php endforeach; ?>
                    <p class="mb-0 mt-2 small text-muted">
                        Based on <?php echo $distribution_total; ?> graded
                        assignment<?php echo $distribution_total === 1 ? '' : 's'; ?>.
                    </p>
                <?php else: ?>
                    <p class="mb-0 text-muted small">
                        No graded assignment has a usable point total yet.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
