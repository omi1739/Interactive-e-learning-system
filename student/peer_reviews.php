<?php
/**
 * The list of peer reviews assigned to the signed-in student.
 *
 * The author of each submission is deliberately NOT joined here. Joining
 * users on s.student_id and printing the name is what previously broke blind
 * review: a reviewer could see exactly whose work they were judging. The
 * identity is resolved by review_author_label(), which hides it unless the
 * viewer is the author or an instructor.
 */

require_once '../includes/bootstrap.php';

$auth->requireRole('student');

$conn = $db->getConnection();
$viewer_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT pr.review_id, pr.status, pr.is_anonymous, pr.review_date, pr.submitted_at, pr.overall_feedback,
            s.submission_id, s.submission_text, s.file_path, s.file_name, s.student_id AS author_id,
            a.title AS assignment_title, a.assignment_id, a.max_points,
            c.title AS course_title, c.course_id
       FROM peer_reviews pr
       JOIN submissions s ON pr.submission_id = s.submission_id
       JOIN assignments a ON s.assignment_id = a.assignment_id
       JOIN modules m ON a.module_id = m.module_id
       JOIN courses c ON m.course_id = c.course_id
      WHERE pr.reviewer_id = ?
      ORDER BY FIELD(pr.status, 'in_progress', 'accepted', 'completed', 'declined'), pr.review_date"
);
$stmt->execute([$viewer_id]);
$all_reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pending_reviews = [];
$completed_reviews = [];
foreach ($all_reviews as $review) {
    if ($review['status'] === 'completed') {
        $completed_reviews[] = $review;
    } else {
        $pending_reviews[] = $review;
    }
}

$total = count($all_reviews);
$done = count($completed_reviews);
$completion_pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;

$page_title = 'Peer Reviews';
require_once '../includes/header.php';
?>

<?php
echo ui_page_header(
    'Peer reviews',
    'Reviewing classmates anonymously. The author of each submission is hidden from you, and your name is hidden from them.',
    '',
    'Assessments'
);
?>

<?php if (!$all_reviews): ?>
    <div class="surface">
        <div class="surface__body">
            <?php echo ui_empty_state(
                'fa-user-check',
                'No reviews assigned yet',
                'Reviews appear here automatically once your classmates start submitting work. Your instructor can also assign them manually.',
                '<a class="btn btn-primary" href="' . e_attr(app_url('assignments.php')) . '">'
                    . '<i class="fas fa-list-check me-1" aria-hidden="true"></i> Go to my assignments</a>'
            ); ?>
        </div>
    </div>
<?php else: ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><?php echo ui_stat('Assigned to you', $total, 'fa-user-check', 'brand'); ?></div>
        <div class="col-6 col-lg-3"><?php echo ui_stat('Still to do', count($pending_reviews), 'fa-hourglass-half', 'warning'); ?></div>
        <div class="col-6 col-lg-3"><?php echo ui_stat('Completed', $done, 'fa-circle-check', 'success'); ?></div>
        <div class="col-6 col-lg-3"><?php echo ui_stat('Completion', $completion_pct . '%', 'fa-gauge-high', 'info'); ?></div>
    </div>

    <div class="surface mb-4">
        <div class="surface__body">
            <?php echo ui_progress($completion_pct, 'Reviews completed'); ?>
        </div>
    </div>

    <div class="row g-3 g-lg-4">
        <div class="col-lg-6">
            <section class="surface h-100" aria-labelledby="pendingHeading">
                <div class="surface__head">
                    <h2 class="surface__title" id="pendingHeading">
                        <i class="fas fa-hourglass-half" aria-hidden="true"></i> To do
                    </h2>
                    <?php if ($pending_reviews): ?>
                        <?php echo ui_badge((string) count($pending_reviews), 'warning'); ?>
                    <?php endif; ?>
                </div>

                <div class="surface__body">
                    <?php if (!$pending_reviews): ?>
                        <?php echo ui_empty_state('fa-circle-check', 'All caught up', 'You have completed every review that was assigned to you.'); ?>
                    <?php else: ?>
                        <div class="record-list">
                            <?php foreach ($pending_reviews as $review): ?>
                                <?php
                                $author_label = review_author_label($review, $viewer_id, 'student');
                                $is_named = $author_label !== 'Anonymous classmate';
                                ?>
                                <article class="record">
                                    <div class="record__main">
                                        <h3 class="record__title"><?php echo e($review['assignment_title']); ?></h3>

                                        <div class="record__meta">
                                            <span class="record__meta-item">
                                                <i class="fas fa-book" aria-hidden="true"></i>
                                                <?php echo e($review['course_title']); ?>
                                            </span>
                                            <span class="record__meta-item">
                                                <i class="fas <?php echo $is_named ? 'fa-user' : 'fa-user-secret'; ?>" aria-hidden="true"></i>
                                                <?php echo e($author_label); ?>
                                            </span>
                                            <span class="record__meta-item">
                                                <i class="fas fa-scale-balanced" aria-hidden="true"></i>
                                                <?php echo e(ui_num($review['max_points'])); ?> points
                                            </span>
                                            <span class="record__meta-item">
                                                <i class="fas fa-calendar" aria-hidden="true"></i>
                                                Assigned <?php echo e(ui_date($review['review_date'])); ?>
                                            </span>
                                        </div>

                                        <?php if (trim((string) $review['submission_text']) !== ''): ?>
                                            <div class="prose-block mt-2">
                                                <?php echo e(ui_truncate($review['submission_text'], 160)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="record__actions">
                                        <a class="btn btn-primary btn-sm" href="<?php echo e_attr(app_url('review_complete.php', ['id' => (int) $review['review_id']])); ?>">
                                            <i class="fas fa-pen me-1" aria-hidden="true"></i> Review
                                        </a>
                                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo e_attr(app_url('submission_preview.php', ['id' => (int) $review['submission_id']])); ?>">
                                            <i class="fas fa-eye me-1" aria-hidden="true"></i> Submission
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="surface h-100" aria-labelledby="doneHeading">
                <div class="surface__head">
                    <h2 class="surface__title" id="doneHeading">
                        <i class="fas fa-circle-check" aria-hidden="true"></i> Completed
                    </h2>
                    <?php if ($completed_reviews): ?>
                        <?php echo ui_badge((string) count($completed_reviews), 'success'); ?>
                    <?php endif; ?>
                </div>

                <div class="surface__body">
                    <?php if (!$completed_reviews): ?>
                        <?php echo ui_empty_state('fa-inbox', 'Nothing completed yet', 'Reviews you submit will be listed here so you can revisit your feedback.'); ?>
                    <?php else: ?>
                        <div class="record-list">
                            <?php foreach ($completed_reviews as $review): ?>
                                <?php $author_label = review_author_label($review, $viewer_id, 'student'); ?>
                                <article class="record">
                                    <div class="record__main">
                                        <h3 class="record__title"><?php echo e($review['assignment_title']); ?></h3>

                                        <div class="record__meta">
                                            <span class="record__meta-item">
                                                <i class="fas fa-book" aria-hidden="true"></i>
                                                <?php echo e($review['course_title']); ?>
                                            </span>
                                            <span class="record__meta-item">
                                                <i class="fas <?php echo $author_label === 'Anonymous classmate' ? 'fa-user-secret' : 'fa-user'; ?>" aria-hidden="true"></i>
                                                <?php echo e($author_label); ?>
                                            </span>
                                            <span class="record__meta-item">
                                                <i class="fas fa-calendar-check" aria-hidden="true"></i>
                                                <?php echo e(ui_date($review['submitted_at'] ?: $review['review_date'])); ?>
                                            </span>
                                            <span class="record__meta-item">
                                                <i class="fas fa-eye-slash" aria-hidden="true"></i>
                                                <?php echo empty($review['is_anonymous'])
                                                    ? 'Shown with your name'
                                                    : 'Shared anonymously'; ?>
                                            </span>
                                        </div>

                                        <?php if (trim((string) $review['overall_feedback']) !== ''): ?>
                                            <div class="prose-block mt-2">
                                                <?php echo e(ui_truncate($review['overall_feedback'], 160)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="record__actions">
                                        <a class="btn btn-outline-primary btn-sm" href="<?php echo e_attr(app_url('review_view.php', ['id' => (int) $review['review_id']])); ?>">
                                            <i class="fas fa-eye me-1" aria-hidden="true"></i> View
                                        </a>
                                        <?php if ($review['file_path'] || trim((string) $review['submission_text']) !== ''): ?>
                                            <a class="btn btn-outline-secondary btn-sm" href="<?php echo e_attr(app_url('submission_preview.php', ['id' => (int) $review['submission_id']])); ?>">
                                                <i class="fas fa-file-lines me-1" aria-hidden="true"></i> Submission
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
