<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('instructor')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['assignment_id'])) {
    $_SESSION['error'] = "Assignment ID is required.";
    header("Location: assignments.php");
    exit();
}

$assignment_id = intval($_GET['assignment_id'] ?? 0);
$conn = $db->getConnection();

// Get assignment details
$stmt = $conn->prepare("SELECT a.*, m.title as module_title, c.title as course_title, c.course_id
                       FROM assignments a
                       JOIN modules m ON a.module_id = m.module_id
                       JOIN courses c ON m.course_id = c.course_id
                       WHERE a.assignment_id = ?");
$stmt->execute([$assignment_id]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$assignment) {
    $_SESSION['error'] = "Assignment not found.";
    header("Location: assignments.php");
    exit();
}

// Check if instructor owns this course
$stmt = $conn->prepare("SELECT * FROM courses WHERE course_id = ? AND instructor_id = ?");
$stmt->execute([$assignment['course_id'], $_SESSION['user_id']]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$course) {
    $_SESSION['error'] = "You are not authorized to manage rubrics for this assignment.";
    header("Location: assignments.php");
    exit();
}

// Get existing rubrics
$stmt = $conn->prepare("SELECT * FROM rubrics WHERE assignment_id = ? ORDER BY rubric_order");
$stmt->execute([$assignment_id]);
$rubrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form actions
if($_POST) {
    verify_csrf();
    if(isset($_POST['add_rubric'])) {
        $criterion_name = trim($_POST['criterion_name']);
        $description = trim($_POST['description']);
        $max_score = floatval($_POST['max_score']);
        $weight = floatval($_POST['weight'] ?? 1.0);
        
        if(empty($criterion_name) || $max_score <= 0) {
            $_SESSION['error'] = "Criterion name and max score are required.";
        } else {
            // Get next order
            $stmt = $conn->prepare("SELECT MAX(rubric_order) as max_order FROM rubrics WHERE assignment_id = ?");
            $stmt->execute([$assignment_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $next_order = $result['max_order'] + 1;
            
            $stmt = $conn->prepare("INSERT INTO rubrics (assignment_id, criterion_name, description, max_score, weight, rubric_order) VALUES (?, ?, ?, ?, ?, ?)");
            if($stmt->execute([$assignment_id, $criterion_name, $description, $max_score, $weight, $next_order])) {
                $_SESSION['success'] = "Rubric criterion added successfully.";
            } else {
                $_SESSION['error'] = "Failed to add rubric criterion.";
            }
        }
    } elseif(isset($_POST['update_rubric'])) {
        $rubric_id = $_POST['rubric_id'];
        $criterion_name = trim($_POST['criterion_name']);
        $description = trim($_POST['description']);
        $max_score = floatval($_POST['max_score']);
        $weight = floatval($_POST['weight'] ?? 1.0);
        
        if(empty($criterion_name) || $max_score <= 0) {
            $_SESSION['error'] = "Criterion name and max score are required.";
        } else {
            $stmt = $conn->prepare("UPDATE rubrics SET criterion_name = ?, description = ?, max_score = ?, weight = ? WHERE rubric_id = ?");
            if($stmt->execute([$criterion_name, $description, $max_score, $weight, $rubric_id])) {
                $_SESSION['success'] = "Rubric criterion updated successfully.";
            } else {
                $_SESSION['error'] = "Failed to update rubric criterion.";
            }
        }
    } elseif(isset($_POST['delete_rubric'])) {
        $rubric_id = $_POST['rubric_id'];
        
        $stmt = $conn->prepare("DELETE FROM rubrics WHERE rubric_id = ?");
        if($stmt->execute([$rubric_id])) {
            $_SESSION['success'] = "Rubric criterion deleted successfully.";
        } else {
            $_SESSION['error'] = "Failed to delete rubric criterion.";
        }
    } elseif(isset($_POST['reorder_rubrics'])) {
        // The client posts a comma-separated id list (see the JS at the bottom
        // of this file). Accept both that and a plain array.
        $raw_order = $_POST['order'] ?? [];
        if(is_string($raw_order)) {
            $raw_order = array_filter(array_map('trim', explode(',', $raw_order)), static fn($v) => $v !== '');
        }
        if(!is_array($raw_order)) {
            $raw_order = [];
        }

        // Only accept ids that really belong to this assignment, and set the
        // order from the array position (re-numbering 0..n-1 in the DB).
        $position = 1;
        foreach($raw_order as $rubric_id) {
            $rubric_id = intval($rubric_id);
            if($rubric_id <= 0) {
                continue;
            }
            $stmt = $conn->prepare("UPDATE rubrics SET rubric_order = ?
                                    WHERE rubric_id = ? AND assignment_id = ?");
            $stmt->execute([$position, $rubric_id, $assignment_id]);
            $position++;
        }
        $_SESSION['success'] = "Rubrics reordered successfully.";
    }
    
    header("Location: rubrics.php?assignment_id=" . $assignment_id);
    exit();
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Manage Rubrics</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="assignment_view.php?id=<?php echo $assignment_id; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Assignment
        </a>
    </div>
</div>

<?php if(isset($_SESSION['success'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?php echo e($_SESSION['success']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['success']); endif; ?>

<?php if(isset($_SESSION['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php echo e($_SESSION['error']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['error']); endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Rubric Criteria</h5>
            </div>
            <div class="card-body">
                <?php if(count($rubrics) > 0): ?>
                    <form method="POST" id="reorderForm">
                        <?php echo csrf_field(); ?>
                        <ul id="rubricsList" class="list-group">
                            <?php foreach($rubrics as $rubric): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center" data-id="<?php echo $rubric['rubric_id']; ?>">
                                <div class="flex-grow-1">
                                    <h6><?php echo htmlspecialchars($rubric['criterion_name']); ?></h6>
                                    <p class="mb-1"><?php echo htmlspecialchars($rubric['description']); ?></p>
                                    <small class="text-muted">Max Score: <?php echo $rubric['max_score']; ?> | Weight: <?php echo $rubric['weight']; ?></small>
                                </div>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-primary edit-rubric" data-bs-toggle="modal" data-bs-target="#editRubricModal" 
                                            data-id="<?php echo $rubric['rubric_id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($rubric['criterion_name']); ?>" 
                                            data-desc="<?php echo htmlspecialchars($rubric['description']); ?>" 
                                            data-max="<?php echo $rubric['max_score']; ?>" 
                                            data-weight="<?php echo $rubric['weight']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="submit" name="delete_rubric" class="btn btn-sm btn-outline-danger" 
                                            onclick="return confirm('Are you sure you want to delete this rubric criterion?')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <input type="hidden" name="rubric_id" value="<?php echo $rubric['rubric_id']; ?>">
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <input type="hidden" name="reorder_rubrics" value="1">
                        <input type="hidden" name="order" id="rubricOrder">
                    </form>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Rubric Criteria</h5>
                        <p class="text-muted">Get started by adding your first rubric criterion.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Add New Criterion</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label for="criterion_name" class="form-label">Criterion Name *</label>
                        <input type="text" class="form-control" id="criterion_name" name="criterion_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="max_score" class="form-label">Max Score *</label>
                                <input type="number" class="form-control" id="max_score" name="max_score" step="0.1" min="0.1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="weight" class="form-label">Weight</label>
                                <input type="number" class="form-control" id="weight" name="weight" step="0.1" min="0.1" value="1.0">
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="add_rubric" class="btn btn-primary w-100">Add Criterion</button>
                </form>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0">Assignment Information</h5>
            </div>
            <div class="card-body">
                <h6><?php echo htmlspecialchars($assignment['title']); ?></h6>
                <p class="mb-1"><strong>Course:</strong> <?php echo htmlspecialchars($assignment['course_title']); ?></p>
                <p class="mb-1"><strong>Max Points:</strong> <?php echo $assignment['max_points']; ?></p>
                <p class="mb-0"><strong>Due Date:</strong> 
                    <?php echo $assignment['due_date'] ? date('M j, Y g:i A', strtotime($assignment['due_date'])) : 'No due date'; ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Edit Rubric Modal -->
<div class="modal fade" id="editRubricModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Rubric Criterion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <input type="hidden" name="rubric_id" id="edit_rubric_id">
                    <div class="mb-3">
                        <label for="edit_criterion_name" class="form-label">Criterion Name *</label>
                        <input type="text" class="form-control" id="edit_criterion_name" name="criterion_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_max_score" class="form-label">Max Score *</label>
                                <input type="number" class="form-control" id="edit_max_score" name="max_score" step="0.1" min="0.1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_weight" class="form-label">Weight</label>
                                <input type="number" class="form-control" id="edit_weight" name="weight" step="0.1" min="0.1" value="1.0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_rubric" class="btn btn-primary">Update Criterion</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Drag-and-drop reordering needs jQuery and the jQuery UI sortable widget.
// Bootstrap does not ship these, so they must be loaded explicitly; without
// them the reorder feature silently does nothing.
?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.13.2/jquery-ui.min.js"></script>
<script>
// Rubric reordering
$(document).ready(function() {
    var $list = $('#rubricsList');

    if($list.length && $.fn.sortable) {
        $list.sortable({
            update: function() {
                var order = [];
                $list.find('li').each(function() {
                    order.push($(this).data('id'));
                });
                $('#rubricOrder').val(order.join(','));
                $('#reorderForm').submit();
            }
        });
    }

    // Edit rubric modal
    $('.edit-rubric').on('click', function() {
        var $btn = $(this);
        $('#edit_rubric_id').val($btn.data('id'));
        $('#edit_criterion_name').val($btn.data('name'));
        $('#edit_description').val($btn.data('desc'));
        $('#edit_max_score').val($btn.data('max'));
        $('#edit_weight').val($btn.data('weight'));
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
