<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('assignments.php');
}

$assignment_id = intval($_GET['id'] ?? 0);
$db = new Database();
$conn = $db->getConnection();

// Get assignment details and verify ownership
$stmt = $conn->prepare("SELECT a.*, m.title as module_title, c.title as course_title, c.course_id, c.instructor_id
                       FROM assignments a
                       JOIN modules m ON a.module_id = m.module_id
                       JOIN courses c ON m.course_id = c.course_id
                       WHERE a.assignment_id = ?");
$stmt->execute([$assignment_id]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$assignment || $assignment['instructor_id'] != $_SESSION['user_id']) {
    $auth->redirect('assignments.php');
}

// Get submissions for this assignment.
$stmt = $conn->prepare("SELECT s.*, u.first_name, u.last_name, u.username, u.email
                       FROM submissions s
                       JOIN users u ON s.student_id = u.user_id
                       WHERE s.assignment_id = ?
                       ORDER BY s.submission_date DESC");
$stmt->execute([$assignment_id]);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Index by id so handlers and the view can look a submission up in O(1).
$submissions_by_id = [];
foreach($submissions as $row) {
    $submissions_by_id[(int)$row['submission_id']] = $row;
}

// The rubric total is what a peer review is scored out of. Comparing an average
// score against the assignment's max_points only worked if the rubric happened
// to sum to exactly that number.
$rubric_stmt = $conn->prepare("SELECT COALESCE(SUM(max_score), 0) FROM rubrics WHERE assignment_id = ?");
$rubric_stmt->execute([$assignment_id]);
$rubric_total = (float)$rubric_stmt->fetchColumn();

// Every peer review on this assignment, fetched once for the whole page.
//
// The previous version ran a query per row from inside the table body, and
// derived the displayed score with AVG(score) over review_scores. That averages
// the individual criterion marks, so a review of four criteria out of 25 and a
// review of one criterion out of 25 were treated as comparable. Summing per
// review first, then averaging those totals, is what the number claims to be.
$reviews_by_submission = [];
if(!empty($submissions)) {
    $review_stmt = $conn->prepare("SELECT pr.review_id, pr.submission_id, pr.reviewer_id, pr.status,
                                          pr.is_anonymous, pr.overall_feedback, pr.review_date,
                                          u.first_name, u.last_name,
                                          (SELECT SUM(rs.score) FROM review_scores rs WHERE rs.review_id = pr.review_id) AS total_score
                                   FROM peer_reviews pr
                                   JOIN users u ON pr.reviewer_id = u.user_id
                                   WHERE pr.submission_id IN (" . implode(',', array_fill(0, count($submissions), '?')) . ")
                                   ORDER BY pr.status DESC, pr.review_date DESC");
    $review_stmt->execute(array_map(static fn($s) => (int)$s['submission_id'], $submissions));

    foreach($review_stmt->fetchAll(PDO::FETCH_ASSOC) as $review) {
        $reviews_by_submission[(int)$review['submission_id']][] = $review;
    }
}

// Roll the per-review totals up to the submission, and fill in the counters
// the table reads. Doing it here keeps one query and one definition of "score".
foreach($submissions as &$submission) {
    $sid = (int)$submission['submission_id'];
    $reviews = $reviews_by_submission[$sid] ?? [];

    $completed = array_values(array_filter(
        $reviews,
        static fn($r) => $r['status'] === 'completed' && $r['total_score'] !== null
    ));

    $totals = array_map(static fn($r) => (float)$r['total_score'], $completed);

    $submission['total_reviews'] = count($reviews);
    $submission['completed_reviews'] = count($completed);
    $submission['avg_peer_score'] = !empty($totals)
        ? array_sum($totals) / count($totals)
        : null;
}
unset($submission);

/**
 * Assign peer reviewers to one submission.
 *
 * $assignment_id is passed in because the caller has already proven ownership
 * of it; re-deriving it here cost an extra query per row during bulk actions.
 * Reviewers are drawn from students who submitted to the same assignment,
 * never the author, and an existing pair is never duplicated.
 */
function assignReviewsToSubmission(PDO $conn, int $assignment_id, int $submission_id, int $author_id, int $reviews_count): int {
    $stmt = $conn->prepare("SELECT DISTINCT u.user_id
                            FROM users u
                            JOIN submissions s ON u.user_id = s.student_id
                            WHERE s.assignment_id = ? AND s.student_id <> ?");
    $stmt->execute([$assignment_id, $author_id]);
    $potential_reviewers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if(empty($potential_reviewers)) {
        return 0;
    }

    shuffle($potential_reviewers);

    // Skip reviewers who already have a review for this submission, so
    // re-running the action tops up to the target instead of erroring.
    $existing_stmt = $conn->prepare("SELECT reviewer_id FROM peer_reviews WHERE submission_id = ?");
    $existing_stmt->execute([$submission_id]);
    $existing = array_map('intval', $existing_stmt->fetchAll(PDO::FETCH_COLUMN));

    $assigned = 0;
    $insert = $conn->prepare("INSERT INTO peer_reviews (submission_id, reviewer_id, status) VALUES (?, ?, 'in_progress')");
    foreach($potential_reviewers as $reviewer_id) {
        if($assigned >= $reviews_count) {
            break;
        }
        $reviewer_id = (int)$reviewer_id;
        if(in_array($reviewer_id, $existing, true)) {
            continue;
        }
        if($insert->execute([$submission_id, $reviewer_id])) {
            $existing[] = $reviewer_id;
            $assigned++;
        }
    }

    return $assigned;
}

/**
 * Confirm a submission id belongs to the assignment this page is scoped to.
 * Every write action below funnels through this, so a crafted form cannot
 * reach another instructor's course.
 */
function submission_belongs_to_assignment(PDO $conn, int $submission_id, int $assignment_id): ?array {
    if($submission_id <= 0 || $assignment_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("SELECT submission_id, student_id, file_path FROM submissions
                            WHERE submission_id = ? AND assignment_id = ?");
    $stmt->execute([$submission_id, $assignment_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Handle grade submission
if($_POST && isset($_POST['update_grade'])) {
    verify_csrf();
    $submission_id = intval($_POST['submission_id'] ?? 0);
    $grade = $_POST['grade'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');

    $target = submission_belongs_to_assignment($conn, $submission_id, $assignment_id);

    if(!$target) {
        $error = "That submission does not belong to this assignment.";
    } elseif(!is_numeric($grade) || $grade < 0 || $grade > $assignment['max_points']) {
        $error = "Grade must be a number between 0 and " . ui_num($assignment['max_points']);
    } else {
        $stmt = $conn->prepare("UPDATE submissions SET final_grade = ?, instructor_feedback = ?, status = 'graded' WHERE submission_id = ? AND assignment_id = ?");
        if($stmt->execute([$grade, $feedback, $submission_id, $assignment_id])) {
            flash_success("Grade updated for " . $submissions_by_id[$submission_id]['first_name'] . " " . $submissions_by_id[$submission_id]['last_name'] . ".");
            header("Location: assignment_submissions.php?id=" . (int)$assignment_id);
            exit();
        } else {
            $error = "Failed to update grade.";
        }
    }
}

// Assign peer reviews to a single submission from the row menu.
// POST + CSRF: a GET link would let any page the instructor visits trigger it.
if($_POST && isset($_POST['assign_reviews_single'])) {
    verify_csrf();
    $submission_id = intval($_POST['submission_id'] ?? 0);
    $target = submission_belongs_to_assignment($conn, $submission_id, $assignment_id);

    if(!$target) {
        flash_error("That submission does not belong to this assignment.");
    } else {
        $made = assignReviewsToSubmission($conn, $assignment_id, $submission_id, (int)$target['student_id'], 2);
        if($made > 0) {
            flash_success("Assigned {$made} peer review(s).");
        } else {
            flash_error("Could not assign more reviewers. Every eligible classmate already has a review for this submission, or nobody else has submitted yet.");
        }
    }
    header("Location: assignment_submissions.php?id=" . (int)$assignment_id);
    exit();
}

// Delete a submission, with the review records and stored file that hang off it.
if($_POST && isset($_POST['delete_submission'])) {
    verify_csrf();
    $submission_id = intval($_POST['submission_id'] ?? 0);
    $target = submission_belongs_to_assignment($conn, $submission_id, $assignment_id);

    if(!$target) {
        flash_error("That submission does not belong to this assignment.");
    } else {
        try {
            $conn->beginTransaction();

            // Remove the per-review rubric scores before the reviews themselves.
            $review_ids_stmt = $conn->prepare("SELECT review_id FROM peer_reviews WHERE submission_id = ?");
            $review_ids_stmt->execute([$submission_id]);
            $review_ids = array_map('intval', $review_ids_stmt->fetchAll(PDO::FETCH_COLUMN));

            if(!empty($review_ids)) {
                $ph = implode(',', array_fill(0, count($review_ids), '?'));
                $del_scores = $conn->prepare("DELETE FROM review_scores WHERE review_id IN ($ph)");
                $del_scores->execute($review_ids);

                $del_reviews = $conn->prepare("DELETE FROM peer_reviews WHERE submission_id = ?");
                $del_reviews->execute([$submission_id]);
            }

            $del_submission = $conn->prepare("DELETE FROM submissions WHERE submission_id = ? AND assignment_id = ?");
            $del_submission->execute([$submission_id, $assignment_id]);

            if($del_submission->rowCount() === 0) {
                throw new RuntimeException('Submission was not deleted.');
            }

            $conn->commit();

            // Only unlink the upload once the row is really gone. The file must
            // be resolved against UPLOAD_DIR: it is uploads/assignments/ by
            // default and an arbitrary outside-the-web-root path in production.
            // The old code rebuilt __DIR__ . '/../uploads/' instead, so in the
            // default layout it pointed one directory too high (orphaning every
            // deleted upload) and with UPLOAD_DIR_LOCAL set it targeted a path
            // unrelated to the real file.
            if(!empty($target['file_path'])) {
                $base = realpath(rtrim(UPLOAD_DIR, '/'));
                $file = $base ? $base . DIRECTORY_SEPARATOR . basename($target['file_path']) : null;
                if($file !== null && is_file($file) && str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
                    if(!@unlink($file)) {
                        // The row is already gone, so this is only a leftover
                        // file on disk. Log it instead of failing silently.
                        error_log('Submission ' . (int)$target['submission_id']
                            . ' deleted but its upload could not be removed: ' . $file);
                    }
                }
            }

            flash_success("Submission deleted, along with its peer reviews.");
        } catch(Throwable $e) {
            if($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Submission delete failed: " . $e->getMessage());
            flash_error("Could not delete the submission. Nothing was removed.");
        }
    }
    header("Location: assignment_submissions.php?id=" . (int)$assignment_id);
    exit();
}

// Handle bulk actions
if($_POST && isset($_POST['bulk_action'])) {
    verify_csrf();
    $action = $_POST['bulk_action'];

    // Restrict the incoming id list to submissions that really belong to this
    // assignment, so a crafted form cannot reach another course's rows.
    $requested = $_POST['selected_submissions'] ?? [];
    if(!is_array($requested)) {
        $requested = [];
    }
    $selected_submissions = [];
    if(!empty($requested)) {
        $ids = array_values(array_unique(array_map('intval', $requested)));
        $ids = array_filter($ids, static fn($v) => $v > 0);
        if(!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $owned = $conn->prepare("SELECT submission_id FROM submissions WHERE assignment_id = ? AND submission_id IN ($ph)");
            $owned->execute(array_merge([(int)$assignment_id], $ids));
            $selected_submissions = array_map('intval', $owned->fetchAll(PDO::FETCH_COLUMN));
        }
    }

    if(empty($selected_submissions)) {
        $error = "No valid submissions selected for bulk action.";
    } else {
        switch($action) {
            case 'assign_reviews':
                $assignments_made = 0;
                foreach($selected_submissions as $submission_id) {
                    $row = $submissions_by_id[$submission_id] ?? null;
                    if(!$row) {
                        continue;
                    }
                    $assignments_made += assignReviewsToSubmission(
                        $conn,
                        $assignment_id,
                        $submission_id,
                        (int)$row['student_id'],
                        2
                    );
                }
                if($assignments_made > 0) {
                    $_SESSION['success'] = "Assigned {$assignments_made} peer review(s) across the selected submissions.";
                } else {
                    // The old code always claimed success, even when every
                    // eligible classmate already had a review and nothing happened.
                    $_SESSION['error'] = "No new reviews were assigned. Every eligible classmate already has a review, or nobody else has submitted to this assignment yet.";
                }
                break;

            case 'publish_grades':
                // Only release grades that actually exist. The previous
                // version flipped every selected row to 'graded', which told
                // students their work had been marked when it had not.
                $ph = implode(',', array_fill(0, count($selected_submissions), '?'));
                $stmt = $conn->prepare("UPDATE submissions
                                        SET status = 'graded'
                                        WHERE assignment_id = ?
                                          AND submission_id IN ($ph)
                                          AND final_grade IS NOT NULL
                                          AND status <> 'graded'");
                $stmt->execute(array_merge([$assignment_id], $selected_submissions));
                $published = $stmt->rowCount();

                $skipped = count($selected_submissions) - $published;
                if($published > 0) {
                    $_SESSION['success'] = "Published {$published} grade(s).";
                } else {
                    $_SESSION['error'] = "No grades were published. Every selected submission is either already graded or has no grade recorded yet.";
                }
                if($skipped > 0) {
                    $_SESSION['success'] = ($_SESSION['success'] ?? '') . " {$skipped} left unchanged because they had no grade yet.";
                    $_SESSION['success'] = trim($_SESSION['success']);
                }
                break;

            default:
                $_SESSION['error'] = "Unknown bulk action.";
                break;
        }

        header("Location: assignment_submissions.php?id=" . (int)$assignment_id);
        exit();
    }
}

// Get success/error messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success']);
unset($_SESSION['error']);

// Calculate statistics
$total_submissions = count($submissions);
$graded_submissions = count(array_filter($submissions, function($s) { return $s['status'] == 'graded'; }));
$pending_submissions = $total_submissions - $graded_submissions;
$average_grade = 0;
$has_grades = false;

if($graded_submissions > 0) {
    $total_grade = 0;
    foreach($submissions as $submission) {
        if($submission['final_grade'] !== null) {
            $total_grade += $submission['final_grade'];
            $has_grades = true;
        }
    }
    $average_grade = $has_grades ? $total_grade / $graded_submissions : 0;
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Submissions: <?php echo htmlspecialchars($assignment['title']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="assignment_view.php?id=<?php echo $assignment_id; ?>" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back to Assignment
        </a>
        <span class="badge bg-primary"><?php echo count($submissions); ?> submissions</span>
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

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-white bg-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $total_submissions; ?></h4>
                        <p class="card-text">Total Submissions</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-inbox fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-success">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $graded_submissions; ?></h4>
                        <p class="card-text">Graded</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $pending_submissions; ?></h4>
                        <p class="card-text">Pending</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-clock fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-info">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $has_grades ? number_format($average_grade, 1) : '0'; ?>/<?php echo $assignment['max_points']; ?></h4>
                        <p class="card-text">Average Grade</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-chart-line fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="fas fa-list"></i> Student Submissions
                <small class="text-muted">Course: <?php echo htmlspecialchars($assignment['course_title']); ?></small>
            </h5>
            <div>
                <?php if(count($submissions) > 0): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bulkActionsModal">
                        <i class="fas fa-tasks"></i> Bulk Actions
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if(count($submissions) > 0): ?>
            <div class="mb-3">
                <label for="submissionSearch" class="form-label small fw-semibold text-muted">Filter submissions</label>
                <input type="search" id="submissionSearch" class="form-control" data-table-search="#submissionsTable"
                       placeholder="Search by student name or email&hellip;" autocomplete="off">
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle" id="submissionsTable">
                    <thead>
                        <tr>
                            <th width="30">
                                <input type="checkbox" id="selectAll" data-select-all=".submission-checkbox">
                            </th>
                            <th>Student</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Grade</th>
                            <th>Peer Reviews</th>
                            <th>Avg Peer Score</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($submissions as $submission): ?>
                        <?php
                        $sid = (int)$submission['submission_id'];
                        $row_reviews = $reviews_by_submission[$sid] ?? [];
                        $row_name = $submission['first_name'] . ' ' . $submission['last_name'];
                        ?>
                        <tr>
                            <td>
                                <?php // form="bulkForm" associates this box with the form in the
                                      // modal below. The two cannot be nested, and without
                                      // this the browser submits no boxes at all. ?>
                                <input type="checkbox" name="selected_submissions[]" value="<?php echo $sid; ?>"
                                       class="submission-checkbox" form="bulkForm">
                            </td>
                            <td class="min-w-0">
                                <strong><?php echo e($row_name); ?></strong>
                                <br><small class="text-muted"><?php echo e($submission['email']); ?></small>
                            </td>
                            <td class="text-nowrap">
                                <?php echo ui_date($submission['submission_date'], 'M j, Y g:i A'); ?>
                                <?php if(ui_is_late($submission['submission_date'], $assignment['due_date'])): ?>
                                    <br><span class="badge badge-soft-danger"><i class="fas fa-clock" aria-hidden="true"></i> Late</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ui_status_badge($submission['status']); ?></td>
                            <td>
                                <?php echo ui_grade_badge($submission['final_grade'], $assignment['max_points']); ?>
                            </td>
                            <td>
                                <?php
                                $total_reviews = (int)$submission['total_reviews'];
                                $completed_reviews = (int)$submission['completed_reviews'];
                                ?>
                    <span class="badge <?php echo $completed_reviews > 0 ? 'badge-soft-info' : 'badge-soft-neutral'; ?>">
                        <?php echo $completed_reviews; ?>/<?php echo $total_reviews; ?>
                    </span>
                            </td>
                            <td>
                                <?php
                                // Score the average against the rubric total,
                                // not against the assignment's max_points.
                                if($submission['avg_peer_score'] !== null && $rubric_total > 0):
                                    $peer_pct = ((float)$submission['avg_peer_score'] / $rubric_total) * 100;
                                    ?>
                                    <span class="badge <?php echo $peer_pct >= 70 ? 'badge-soft-success' : 'badge-soft-warning'; ?>"
                                          title="<?php echo e(ui_num($submission['avg_peer_score'], 2) . ' of ' . ui_num($rubric_total, 2) . ' rubric points'); ?>">
                                        <?php echo ui_num($peer_pct, 0); ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#gradeModal"
                                            data-submission-id="<?php echo $sid; ?>"
                                            data-student-name="<?php echo e_attr($row_name); ?>"
                                            data-grade="<?php echo e_attr($submission['final_grade'] ?? ''); ?>"
                                            data-feedback="<?php echo e_attr($submission['instructor_feedback'] ?? ''); ?>"
                                            data-peer-score="<?php echo e_attr($submission['avg_peer_score'] === null ? '' : ui_num($submission['avg_peer_score'], 2)); ?>">
                                        <i class="fas fa-edit"></i> Grade
                                    </button>
                                    <a href="submission_view.php?id=<?php echo $sid; ?>" class="btn btn-info">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-ellipsis-v"></i>
                                        <span class="visually-hidden">More actions</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <form method="POST" class="d-block">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="submission_id" value="<?php echo $sid; ?>">
                                                <button type="submit" name="assign_reviews_single" value="1" class="dropdown-item">
                                                    <i class="fas fa-user-plus"></i> Assign Peer Reviews
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item"
                                                    data-bs-toggle="modal" data-bs-target="#peerReviewsModal"
                                                    data-student-name="<?php echo e_attr($row_name); ?>"
                                                    data-reviews="<?php echo e_attr(json_encode($row_reviews, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)); ?>">
                                                <i class="fas fa-comments"></i> View Peer Reviews
                                            </button>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" class="d-block"
                                                  data-confirm="Delete this submission? Its peer reviews will be removed too. This cannot be undone.">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="submission_id" value="<?php echo $sid; ?>">
                                                <button type="submit" name="delete_submission" value="1" class="dropdown-item text-danger">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No Submissions Yet</h5>
                <p class="text-muted">Students haven't submitted any work for this assignment yet.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Grade Modal: one shared dialog, filled from the clicked row's data attributes. -->
<div class="modal fade" id="gradeModal" tabindex="-1" aria-labelledby="gradeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="gradeModalLabel">Grade Submission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" data-bs-dismiss="modal">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Student:</strong> <span id="gradeStudentName">&mdash;</span></p>
                            <p class="mb-0"><strong>Assignment:</strong> <?php echo e($assignment['title']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Average peer score:</strong>
                                <span id="gradePeerScore">&mdash;</span>
                                <?php if($rubric_total > 0): ?>
                                    <small class="text-muted">of <?php echo ui_num($rubric_total, 2); ?> rubric points</small>
                                <?php endif; ?>
                            </p>
                            <p class="mb-0"><strong>Max points:</strong> <?php echo ui_num($assignment['max_points']); ?></p>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="gradeInput" class="form-label">Final Grade (0 - <?php echo ui_num($assignment['max_points']); ?>)</label>
                        <input type="number" class="form-control" id="gradeInput" name="grade"
                               value="" min="0" max="<?php echo e_attr($assignment['max_points']); ?>" step="0.5" required>
                    </div>

                    <div class="mb-3">
                        <label for="feedbackInput" class="form-label">Instructor Feedback</label>
                        <textarea class="form-control" id="feedbackInput" name="feedback" rows="4"
                                  maxlength="1500" data-counter="#feedbackCount"
                                  placeholder="Provide detailed feedback for the student..."></textarea>
                        <div class="form-text"><span id="feedbackCount">0</span> / 1500 characters</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="submission_id" id="gradeSubmissionId" value="">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_grade" value="1" class="btn btn-primary">Save Grade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Peer Reviews Modal: one shared dialog, filled from the clicked row. -->
<div class="modal fade" id="peerReviewsModal" tabindex="-1" aria-labelledby="peerReviewsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="peerReviewsModalLabel">Peer Reviews</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="peerReviewsBody"></div>
        </div>
    </div>
</div>

<!-- Bulk Actions Modal. The table's checkboxes point at this form with
     HTML5's form="bulkForm" attribute, because a form cannot wrap the table
     without swallowing the per-row action forms. -->
<div class="modal fade" id="bulkActionsModal" tabindex="-1" aria-labelledby="bulkActionsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkActionsModalLabel">Bulk Actions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="bulkForm">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <p class="text-muted small" id="bulkSelectionCount" data-bulk-count>
                        No submissions selected.
                    </p>
                    <div class="mb-3">
                        <label for="bulk_action" class="form-label">Select Action</label>
                        <select class="form-select" id="bulk_action" name="bulk_action" required>
                            <option value="">Choose an action...</option>
                            <option value="assign_reviews">Assign Peer Reviews (2 per submission)</option>
                            <option value="publish_grades">Publish existing grades</option>
                        </select>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle"></i>
                        This applies to every selected submission.
                        <strong>Publish existing grades</strong> only releases submissions that already have a grade recorded.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply to Selected</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
