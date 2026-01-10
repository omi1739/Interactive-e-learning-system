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

$pending_assignments = 0;
$submitted_assignments = 0;
$graded_assignments = 0;
$total_grade = 0;
$graded_count = 0;

foreach($assignments as $assignment) {
    if(!$assignment['submission_id']) {
        $pending_assignments++;
    } else {
        $submitted_assignments++;
        if($assignment['submission_status'] == 'graded' && $assignment['final_grade'] !== null) {
            $graded_assignments++;
            $total_grade += $assignment['final_grade'];
            $graded_count++;
        }
    }
}

$average_grade = $graded_count > 0 ? $total_grade / $graded_count : 0;

// Get pending peer reviews
$stmt = $conn->prepare("SELECT COUNT(*) as pending_reviews 
                       FROM peer_reviews pr 
                       JOIN submissions s ON pr.submission_id = s.submission_id
                       WHERE pr.reviewer_id = ? AND pr.status = 'in_progress'");
$stmt->execute([$_SESSION['user_id']]);
$pending_reviews = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reviews'];

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

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Student Dashboard</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="btn-group me-2">
            <a href="courses.php" class="btn btn-sm btn-outline-secondary">My Courses</a>
            <a href="assignments.php" class="btn btn-sm btn-outline-secondary">Assignments</a>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $approved_courses; ?></h4>
                        <p class="card-text">Enrolled Courses</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-book fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-warning mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $pending_assignments; ?></h4>
                        <p class="card-text">Pending Assignments</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-tasks fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-info mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $pending_reviews; ?></h4>
                        <p class="card-text">Peer Reviews</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-comments fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-success mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo number_format($average_grade, 1); ?>%</h4>
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

<div class="row mt-4">
    <div class="col-md-8">
        <!-- My Courses -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-book"></i> My Courses
                    <span class="badge bg-primary"><?php echo $approved_courses; ?> courses</span>
                </h5>
            </div>
            <div class="card-body">
                <?php if($approved_courses > 0): ?>
                    <div class="row">
                        <?php foreach($enrolled_courses as $course): ?>
                            <?php if($course['enrollment_status'] == 'approved'): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h6 class="card-title"><?php echo htmlspecialchars($course['title']); ?></h6>
                                        <p class="card-text small text-muted">
                                            <?php echo substr($course['description'] ?? 'No description', 0, 80) . '...'; ?>
                                        </p>
                                        <p class="card-text">
                                            <small class="text-muted">
                                                Instructor: <?php echo htmlspecialchars($course['first_name'] . ' ' . $course['last_name']); ?>
                                            </small>
                                        </p>
                                    </div>
                                    <div class="card-footer">
                                        <a href="course_view.php?id=<?php echo $course['course_id']; ?>" class="btn btn-primary btn-sm">Enter Course</a>
                                        <a href="forums.php?course=<?php echo $course['course_id']; ?>" class="btn btn-outline-secondary btn-sm">Forums</a>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Courses Enrolled</h5>
                        <p class="text-muted">Browse available courses and enroll to get started!</p>
                        <a href="courses.php" class="btn btn-primary">Browse Courses</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Grades -->
        <?php if(count($recent_grades) > 0): ?>
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-award"></i> Recently Graded Assignments
                    <span class="badge bg-light text-dark"><?php echo count($recent_grades); ?> graded</span>
                </h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach($recent_grades as $assignment): ?>
                    <div class="list-group-item">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1"><?php echo htmlspecialchars($assignment['title']); ?></h6>
                            <strong class="text-success"><?php echo $assignment['final_grade']; ?>/<?php echo $assignment['max_points']; ?></strong>
                        </div>
                        <p class="mb-1 small text-muted"><?php echo htmlspecialchars($assignment['course_title']); ?></p>
                        <?php if($assignment['instructor_feedback']): ?>
                            <p class="mb-1 small"><strong>Feedback:</strong> <?php echo substr(htmlspecialchars($assignment['instructor_feedback']), 0, 100); ?>...</p>
                        <?php endif; ?>
                        <a href="assignment_view.php?id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-sm btn-outline-success mt-2">View Details</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Upcoming Assignments -->
        <?php if(count($upcoming_assignments) > 0): ?>
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0">
                    <i class="fas fa-clock"></i> Upcoming Assignments
                    <span class="badge bg-dark">Next 7 days</span>
                </h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach($upcoming_assignments as $assignment): ?>
                    <div class="list-group-item">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1"><?php echo htmlspecialchars($assignment['title']); ?></h6>
                            <small class="text-muted">
                                Due: <?php echo date('M j, g:i A', strtotime($assignment['due_date'])); ?>
                            </small>
                        </div>
                        <p class="mb-1 small text-muted"><?php echo htmlspecialchars($assignment['course_title']); ?></p>
                        <a href="assignment_view.php?id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-sm btn-outline-warning mt-2">View Assignment</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-md-4">
        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <a href="courses.php" class="btn btn-outline-primary w-100 mb-2">
                    <i class="fas fa-book"></i> My Courses
                </a>
                <a href="assignments.php" class="btn btn-outline-success w-100 mb-2">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="forums.php" class="btn btn-outline-info w-100 mb-2">
                    <i class="fas fa-comments"></i> Discussion Forums
                </a>
                <a href="peer_reviews.php" class="btn btn-outline-warning w-100">
                    <i class="fas fa-users"></i> Peer Reviews
                </a>
            </div>
        </div>

        <!-- Assignment Progress -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Assignment Progress</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <p class="mb-1">Pending: <span class="badge bg-warning"><?php echo $pending_assignments; ?></span></p>
                    <div class="progress mb-2" style="height: 8px;">
                        <div class="progress-bar bg-warning" style="width: <?php echo $pending_assignments > 0 ? '100' : '0'; ?>%"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <p class="mb-1">Submitted: <span class="badge bg-info"><?php echo $submitted_assignments; ?></span></p>
                    <div class="progress mb-2" style="height: 8px;">
                        <div class="progress-bar bg-info" style="width: <?php echo $submitted_assignments > 0 ? '100' : '0'; ?>%"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <p class="mb-1">Graded: <span class="badge bg-success"><?php echo $graded_assignments; ?></span></p>
                    <div class="progress mb-2" style="height: 8px;">
                        <div class="progress-bar bg-success" style="width: <?php echo $graded_assignments > 0 ? '100' : '0'; ?>%"></div>
                    </div>
                </div>
                
                <?php if($graded_assignments > 0): ?>
                <div class="text-center mt-3">
                    <h5>Average Grade</h5>
                    <div class="display-4 text-success"><?php echo number_format($average_grade, 1); ?>%</div>
                    <small class="text-muted">Based on <?php echo $graded_assignments; ?> graded assignments</small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Grade Distribution -->
        <?php if($graded_assignments > 0): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Grade Distribution</h5>
            </div>
            <div class="card-body">
                <?php
                $grade_ranges = [
                    'A (90-100)' => 0,
                    'B (80-89)' => 0,
                    'C (70-79)' => 0,
                    'D (60-69)' => 0,
                    'F (0-59)' => 0
                ];
                
                foreach($assignments as $assignment) {
                    if($assignment['final_grade'] !== null) {
                        $percentage = ($assignment['final_grade'] / $assignment['max_points']) * 100;
                        
                        if($percentage >= 90) $grade_ranges['A (90-100)']++;
                        elseif($percentage >= 80) $grade_ranges['B (80-89)']++;
                        elseif($percentage >= 70) $grade_ranges['C (70-79)']++;
                        elseif($percentage >= 60) $grade_ranges['D (60-69)']++;
                        else $grade_ranges['F (0-59)']++;
                    }
                }
                
                foreach($grade_ranges as $range => $count):
                    if($count > 0):
                        $percentage = ($count / $graded_assignments) * 100;
                ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between">
                        <span><?php echo $range; ?></span>
                        <span><?php echo $count; ?></span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                    </div>
                </div>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>