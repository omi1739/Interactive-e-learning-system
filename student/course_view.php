<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/bootstrap.php';
if(!$auth->isLoggedIn() || $_SESSION['role'] != 'student') {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('courses.php');
}

$course_id = $_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get course details
$stmt = $conn->prepare("SELECT c.*, u.first_name, u.last_name FROM courses c 
                       JOIN users u ON c.instructor_id = u.user_id 
                       WHERE c.course_id = ?");
$stmt->execute([$course_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if student is enrolled
$stmt = $conn->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ? AND enrollment_status = 'approved'");
$stmt->execute([$_SESSION['user_id'], $course_id]);
$enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$enrollment) {
    $auth->redirect('courses.php');
}

// Get course modules
$stmt = $conn->prepare("SELECT * FROM modules WHERE course_id = ? AND is_published = TRUE ORDER BY module_order");
$stmt->execute([$course_id]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo $course['title']; ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <span class="badge bg-success me-2">Instructor: <?php echo $course['first_name'] . ' ' . $course['last_name']; ?></span>
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
                                    Module <?php echo $index + 1; ?>: <?php echo $module['title']; ?>
                                </button>
                            </h2>
                            <div id="collapse<?php echo $module['module_id']; ?>" class="accordion-collapse collapse" 
                                 aria-labelledby="heading<?php echo $module['module_id']; ?>" data-bs-parent="#modulesAccordion">
                                <div class="accordion-body">
                                    <?php 
                                    // Get lessons for this module
                                    $stmt = $conn->prepare("SELECT * FROM lessons WHERE module_id = ? AND is_published = TRUE ORDER BY lesson_order");
                                    $stmt->execute([$module['module_id']]);
                                    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    if(count($lessons) > 0): 
                                        foreach($lessons as $lesson): 
                                    ?>
                                        <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                                            <div>
                                                <i class="fas fa-<?php echo $lesson['content_type'] == 'video' ? 'play-circle' : ($lesson['content_type'] == 'document' ? 'file' : 'images'); ?> text-primary me-2"></i>
                                                <?php echo $lesson['title']; ?>
                                                <?php if($lesson['duration_minutes']): ?>
                                                    <small class="text-muted">(<?php echo $lesson['duration_minutes']; ?> min)</small>
                                                <?php endif; ?>
                                            </div>
                                            <button class="btn btn-sm btn-outline-primary" onclick="markAsCompleted(<?php echo $lesson['lesson_id']; ?>, 'lesson')">
                                                Mark Complete
                                            </button>
                                        </div>
                                    <?php endforeach; 
                                    else: ?>
                                        <p class="text-muted">No lessons available in this module.</p>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Get assignments for this module
                                    $stmt = $conn->prepare("SELECT * FROM assignments WHERE module_id = ? AND is_published = TRUE");
                                    $stmt->execute([$module['module_id']]);
                                    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    if(count($assignments) > 0): 
                                        foreach($assignments as $assignment): 
                                    ?>
                                        <div class="d-flex justify-content-between align-items-center p-2 border-bottom mt-2">
                                            <div>
                                                <i class="fas fa-tasks text-warning me-2"></i>
                                                <?php echo $assignment['title']; ?>
                                                <?php if($assignment['due_date']): ?>
                                                    <small class="text-muted">Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <a href="assignment_view.php?id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-sm btn-warning">
                                                View Assignment
                                            </a>
                                        </div>
                                    <?php endforeach; 
                                    endif; ?>
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
                <div class="progress mb-3">
                    <div class="progress-bar" role="progressbar" style="width: 25%;">25%</div>
                </div>
                <p><strong>Completed:</strong> 3/12 lessons</p>
                <p><strong>Assignments:</strong> 1/4 submitted</p>
                <p><strong>Quizzes:</strong> 0/2 completed</p>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <a href="assignments.php?course=<?php echo $course_id; ?>" class="btn btn-outline-primary w-100 mb-2">
                    <i class="fas fa-tasks"></i> View Assignments
                </a>
                <a href="forums.php?course=<?php echo $course_id; ?>" class="btn btn-outline-success w-100 mb-2">
                    <i class="fas fa-comments"></i> Discussion Forum
                </a>
                <a href="quizzes.php?course=<?php echo $course_id; ?>" class="btn btn-outline-warning w-100">
                    <i class="fas fa-question-circle"></i> Take Quizzes
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function markAsCompleted(id, type) {
    // Implement progress tracking
    alert('Marked as completed!');
}
</script>

<?php require_once '../includes/footer.php'; ?>