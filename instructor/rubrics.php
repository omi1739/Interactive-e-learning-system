<?php
/**
 * Rubric criteria for one assignment.
 *
 * Rubric rows are addressed by rubric_id in the URL/form. Every write
 * therefore re-checks that the rubric belongs to the assignment the
 * instructor is actually allowed to manage - not merely that the assignment
 * belongs to them. Filtering on rubric_id alone let any signed-in instructor
 * rewrite or delete a rubric belonging to a different instructor's course.
 */

require_once '../includes/bootstrap.php';

$auth->requireRole('instructor');

$conn = $db->getConnection();
$instructor_id = (int) $_SESSION['user_id'];
$assignment_id = param_int('assignment_id', 0, $_GET);

if ($assignment_id <= 0) {
    flash_error('Choose an assignment before managing its rubrics.');
    redirect_to(app_url('assignments.php'));
}

$assignment = require_assignment_ownership($conn, $assignment_id, $instructor_id, 'assignments.php');

/* ------------------------------------------------------------------ */
/* Handle actions                                                      */
/* ------------------------------------------------------------------ */

if (is_post()) {
    verify_csrf();

    // A rubric id that is not part of this assignment is rejected outright
    // rather than silently ignored, so a stale page cannot half-succeed.
    $rubric_belongs = static function ($conn, $rubric_id, $assignment_id) {
        $stmt = $conn->prepare('SELECT rubric_id FROM rubrics WHERE rubric_id = ? AND assignment_id = ? LIMIT 1');
        $stmt->execute([(int) $rubric_id, (int) $assignment_id]);
        return $stmt->fetchColumn() !== false;
    };

    if (isset($_POST['add_rubric'])) {
        $criterion_name = param_str('criterion_name');
        $description = param_str('description');
        $max_score = (float) param_str('max_score', '0');
        $weight = (float) param_str('weight', '1.0');

        if ($criterion_name === '') {
            flash_error('Give the criterion a name.');
        } elseif ($max_score <= 0) {
            flash_error('The maximum score must be greater than zero.');
        } elseif ($weight <= 0) {
            flash_error('The weight must be greater than zero.');
        } else {
            $stmt = $conn->prepare(
                'SELECT COALESCE(MAX(rubric_order), 0) + 1 FROM rubrics WHERE assignment_id = ?'
            );
            $stmt->execute([$assignment_id]);
            $next_order = (int) $stmt->fetchColumn();

            $stmt = $conn->prepare(
                'INSERT INTO rubrics (assignment_id, criterion_name, description, max_score, weight, rubric_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$assignment_id, $criterion_name, $description, $max_score, $weight, $next_order]);

            flash_success('Criterion "' . $criterion_name . '" added.');
        }
    } elseif (isset($_POST['update_rubric'])) {
        $rubric_id = param_int('rubric_id', 0, $_POST);
        $criterion_name = param_str('criterion_name');
        $description = param_str('description');
        $max_score = (float) param_str('max_score', '0');
        $weight = (float) param_str('weight', '1.0');

        if (!$rubric_belongs($conn, $rubric_id, $assignment_id)) {
            flash_error('That criterion does not belong to this assignment.');
        } elseif ($criterion_name === '') {
            flash_error('Give the criterion a name.');
        } elseif ($max_score <= 0) {
            flash_error('The maximum score must be greater than zero.');
        } elseif ($weight <= 0) {
            flash_error('The weight must be greater than zero.');
        } else {
            $stmt = $conn->prepare(
                'UPDATE rubrics
                    SET criterion_name = ?, description = ?, max_score = ?, weight = ?
                  WHERE rubric_id = ? AND assignment_id = ?'
            );
            $stmt->execute([$criterion_name, $description, $max_score, $weight, $rubric_id, $assignment_id]);

            flash_success('Criterion updated.');
        }
    } elseif (isset($_POST['delete_rubric'])) {
        $rubric_id = param_int('rubric_id', 0, $_POST);

        if (!$rubric_belongs($conn, $rubric_id, $assignment_id)) {
            flash_error('That criterion does not belong to this assignment.');
        } else {
            $stmt = $conn->prepare('DELETE FROM rubrics WHERE rubric_id = ? AND assignment_id = ?');
            $stmt->execute([$rubric_id, $assignment_id]);
            flash_success('Criterion deleted.');
        }
    } elseif (isset($_POST['reorder_rubrics'])) {
        // The client posts a comma-separated id list, or a plain array.
        $raw_order = $_POST['order'] ?? [];
        if (is_string($raw_order)) {
            $raw_order = array_filter(array_map('trim', explode(',', $raw_order)), static fn($v) => $v !== '');
        }
        if (!is_array($raw_order)) {
            $raw_order = [];
        }

        // Only ids that really belong to this assignment are renumbered, so a
        // crafted order list cannot reorder another instructor's criteria.
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare(
                'UPDATE rubrics SET rubric_order = ? WHERE rubric_id = ? AND assignment_id = ?'
            );

            $position = 1;
            foreach ($raw_order as $rubric_id) {
                $rubric_id = (int) $rubric_id;
                if ($rubric_id <= 0) {
                    continue;
                }
                $stmt->execute([$position, $rubric_id, $assignment_id]);
                $position++;
            }
            $conn->commit();
            flash_success('Criteria reordered.');
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('Rubric reorder failed: ' . $e->getMessage());
            flash_error('Could not save the new order. Please try again.');
        }
    }

    // PRG: a reload must not repeat the write.
    redirect_to(app_url('rubrics.php', ['assignment_id' => $assignment_id]));
}

/* ------------------------------------------------------------------ */
/* Read                                                                */
/* ------------------------------------------------------------------ */

$stmt = $conn->prepare('SELECT * FROM rubrics WHERE assignment_id = ? ORDER BY rubric_order, rubric_id');
$stmt->execute([$assignment_id]);
$rubrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

$criteria_total = 0.0;
$criteria_weight = 0.0;
foreach ($rubrics as $r) {
    $criteria_total += (float) $r['max_score'];
    $criteria_weight += (float) $r['weight'];
}

$page_title = 'Rubric: ' . $assignment['title'];
require_once '../includes/header.php';
?>

<?php echo ui_breadcrumbs([
    ['label' => 'Assignments', 'url' => 'assignments.php'],
    ['label' => $assignment['title'], 'url' => 'assignment_view.php?id=' . (int) $assignment_id],
    ['label' => 'Rubric'],
], 'My Courses', 'courses.php'); ?>

<?php
echo ui_page_header(
    'Rubric criteria',
    'Criteria are shown to students when they review a peer submission, so keep each one specific and easy to judge.',
    '<a class="btn btn-outline-secondary" href="' . e_attr(app_url('assignment_view.php', ['id' => $assignment_id])) . '">'
        . '<i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back to assignment</a>',
    $assignment['course_title']
);
?>

<div class="row g-3 g-lg-4">
    <div class="col-lg-8">
        <section class="surface" aria-labelledby="criteriaHeading">
            <div class="surface__head">
                <h2 class="surface__title" id="criteriaHeading">
                    <i class="fas fa-list-check" aria-hidden="true"></i> Criteria
                </h2>
                <?php if ($rubrics): ?>
                    <span class="surface__meta"><?php echo count($rubrics) ?> criterion<?php echo count($rubrics) === 1 ? '' : 'a'; ?></span>
                <?php endif; ?>
            </div>

            <?php if (!$rubrics): ?>
                <div class="surface__body">
                    <?php echo ui_empty_state(
                        'fa-clipboard-list',
                        'No criteria yet',
                        'Add the first criterion using the form beside this panel. Students will see these while reviewing a peer.'
                    ); ?>
                </div>
            <?php else: ?>
                <div class="surface__body">
                    <p class="text-muted mb-3" style="font-size:.8125rem">
                        <i class="fas fa-hand-pointer me-1" aria-hidden="true"></i>
                        Drag a criterion by its handle to change the order students see it in.
                    </p>

                    <form method="POST" data-sortable data-sortable-field="#rubricOrder"
                          data-sortable-handle=".sortable-handle" data-sortable-autosubmit
                          id="reorderForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="reorder_rubrics" value="1">
                        <input type="hidden" name="order" id="rubricOrder">

                        <div data-sortable-container>
                            <?php foreach ($rubrics as $rubric): ?>
                                <?php $rid = (int) $rubric['rubric_id']; ?>
                                <div class="sortable-item" draggable="true" data-sortable-item="<?php echo $rid; ?>">
                                    <div class="d-flex align-items-start gap-2">
                                        <button type="button" class="sortable-handle" aria-label="Reorder <?php echo e($rubric['criterion_name']); ?>">
                                            <i class="fas fa-grip-vertical" aria-hidden="true"></i>
                                        </button>

                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <span class="chip-mono">#<?php echo (int) $rubric['rubric_order']; ?></span>
                                                <h3 class="h6 mb-0"><?php echo e($rubric['criterion_name']); ?></h3>
                                            </div>

                                            <?php if (trim((string) $rubric['description']) !== ''): ?>
                                                <p class="text-muted mb-2 break-words-anywhere" style="font-size:.8125rem">
                                                    <?php echo e($rubric['description']); ?>
                                                </p>
                                            <?php endif; ?>

                                            <div class="d-flex flex-wrap gap-2">
                                                <?php echo ui_badge('Max ' . ui_num($rubric['max_score']), 'primary', 'fa-bullseye'); ?>
                                                <?php echo ui_badge('Weight x' . ui_num($rubric['weight']), 'neutral', 'fa-scale-balanced'); ?>
                                            </div>
                                        </div>

                                        <div class="d-flex flex-column flex-sm-row gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-secondary edit-rubric"
                                                    data-bs-toggle="modal" data-bs-target="#editRubricModal"
                                                    data-id="<?php echo $rid; ?>"
                                                    data-name="<?php echo e_attr($rubric['criterion_name']); ?>"
                                                    data-desc="<?php echo e_attr($rubric['description']); ?>"
                                                    data-max="<?php echo e_attr($rubric['max_score']); ?>"
                                                    data-weight="<?php echo e_attr($rubric['weight']); ?>"
                                                    aria-label="Edit <?php echo e_attr($rubric['criterion_name']); ?>">
                                                <i class="fas fa-pen" aria-hidden="true"></i>
                                            </button>

                                            <button type="submit" name="delete_rubric" value="<?php echo $rid; ?>"
                                                    class="btn btn-sm btn-outline-danger"
                                                    formnovalidate
                                                    data-confirm="Delete the criterion &quot;<?php echo e_attr($rubric['criterion_name']); ?>&quot;? Reviews already scored with it keep their numbers, but the criterion will be removed from the form."
                                                    aria-label="Delete <?php echo e_attr($rubric['criterion_name']); ?>">
                                                <i class="fas fa-trash" aria-hidden="true"></i>
                                            </button>
                                            <input type="hidden" name="rubric_id" value="<?php echo $rid; ?>">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </form>

                    <div class="surface surface__body--flush mt-3" style="background:var(--bg-inset)">
                        <dl class="detail-list p-3 mb-0">
                            <?php echo ui_detail_row('Total points', e(ui_num($criteria_total))); ?>
                            <?php echo ui_detail_row(
                                'Points against the assignment maximum',
                                ui_badge(
                                    $criteria_total > 0
                                        ? (string) ui_percent($criteria_total, $assignment['max_points']) . '%'
                                        : 'n/a',
                                    ui_grade_tone(ui_percent($criteria_total, $assignment['max_points']))
                                )
                            ); ?>
                            <?php echo ui_detail_row('Combined weight', e(ui_num($criteria_weight))); ?>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-4">
        <section class="surface" aria-labelledby="addHeading">
            <div class="surface__head">
                <h2 class="surface__title" id="addHeading">
                    <i class="fas fa-plus" aria-hidden="true"></i> Add a criterion
                </h2>
            </div>
            <div class="surface__body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label for="criterion_name" class="form-label">Criterion name <span class="req" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control" id="criterion_name" name="criterion_name"
                               required maxlength="150" placeholder="e.g. Code quality">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">What good looks like</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                                  maxlength="1000" data-autogrow
                                  placeholder="Describe this criterion so two reviewers grade it the same way."></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="max_score" class="form-label">Max points <span class="req" aria-hidden="true">*</span></label>
                            <input type="number" class="form-control" id="max_score" name="max_score"
                                   step="0.5" min="0.5" required>
                        </div>
                        <div class="col-6">
                            <label for="weight" class="form-label">Weight</label>
                            <input type="number" class="form-control" id="weight" name="weight"
                                   step="0.1" min="0.1" value="1.0">
                        </div>
                    </div>

                    <button type="submit" name="add_rubric" class="btn btn-primary w-100 mt-3">
                        <i class="fas fa-plus me-1" aria-hidden="true"></i> Add criterion
                    </button>
                </form>
            </div>
        </section>

        <section class="surface mt-3" aria-labelledby="assignmentInfoHeading">
            <div class="surface__head">
                <h2 class="surface__title" id="assignmentInfoHeading">
                    <i class="fas fa-circle-info" aria-hidden="true"></i> Assignment
                </h2>
            </div>
            <div class="surface__body">
                <dl class="detail-list mb-0">
                    <?php echo ui_detail_row('Title', e($assignment['title'])); ?>
                    <?php echo ui_detail_row('Course', e($assignment['course_title'])); ?>
                    <?php echo ui_detail_row('Module', e($assignment['module_title'])); ?>
                    <?php echo ui_detail_row('Max points', e(ui_num($assignment['max_points']))); ?>
                    <?php echo ui_detail_row('Due', e(ui_datetime($assignment['due_date'], 'M j, Y g:i A', 'No deadline'))); ?>
                </dl>
            </div>
        </section>
    </div>
</div>

<div class="modal fade" id="editRubricModal" tabindex="-1" aria-labelledby="editRubricModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editRubricForm">
                <?php echo csrf_field(); ?>
                <div class="modal-header">
                    <h2 class="modal-title" id="editRubricModalLabel">Edit criterion</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="rubric_id" id="edit_rubric_id">
                    <div class="mb-3">
                        <label for="edit_criterion_name" class="form-label">Criterion name <span class="req" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control" id="edit_criterion_name" name="criterion_name" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">What good looks like</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3" maxlength="1000" data-autogrow></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label for="edit_max_score" class="form-label">Max points <span class="req" aria-hidden="true">*</span></label>
                            <input type="number" class="form-control" id="edit_max_score" name="max_score" step="0.5" min="0.5" required>
                        </div>
                        <div class="col-6">
                            <label for="edit_weight" class="form-label">Weight</label>
                            <input type="number" class="form-control" id="edit_weight" name="weight" step="0.1" min="0.1" value="1.0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_rubric" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Populate the edit modal. Plain vanilla, so no jQuery is required.
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.edit-rubric');
    if (!btn) return;

    document.getElementById('edit_rubric_id').value = btn.dataset.id;
    document.getElementById('edit_criterion_name').value = btn.dataset.name || '';
    document.getElementById('edit_description').value = btn.dataset.desc || '';
    document.getElementById('edit_max_score').value = btn.dataset.max || '';
    document.getElementById('edit_weight').value = btn.dataset.weight || '1.0';
});
</script>

<?php require_once '../includes/footer.php'; ?>
