<?php
require_once 'includes/bootstrap.php';

if(!$auth->isLoggedIn()) {
    $auth->redirect('login.php');
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Dashboard</h1>
</div>

<?php if($_SESSION['role'] == 'student'): ?>
<!-- Student Dashboard -->
<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title">
                            <?php 
                            $enrolled_courses = $functions->getEnrolledCourses($_SESSION['user_id']);
                            echo count($enrolled_courses);
                            ?>
                        </h4>
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
        <div class="card text-white bg-success mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title">
                            <?php 
                            $assignments = $functions->getStudentAssignments($_SESSION['user_id']);
                            $pending = 0;
                            foreach($assignments as $assignment) {
                                if(!$assignment['submission_id']) $pending++;
                            }
                            echo $pending;
                            ?>
                        </h4>
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
        <div class="card text-white bg-warning mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title">0</h4>
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
        <div class="card text-white bg-info mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title">0%</h4>
                        <p class="card-text">Overall Progress</p>
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
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">My Courses</h5>
            </div>
            <div class="card-body">
                <?php 
                $enrolled_courses = $functions->getEnrolledCourses($_SESSION['user_id']);
                if(count($enrolled_courses) > 0): ?>
                    <div class="row">
                        <?php foreach($enrolled_courses as $course): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card course-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo $course['title']; ?></h5>
                                    <p class="card-text"><?php echo substr($course['description'] ?? 'No description', 0, 100) . '...'; ?></p>
                                    <p class="card-text">
                                        <small class="text-muted">
                                            Instructor: <?php echo $course['first_name'] . ' ' . $course['last_name']; ?>
                                        </small>
                                    </p>
                                    <?php if($course['enrollment_status'] == 'approved'): ?>
                                        <a href="student/course_view.php?id=<?php echo $course['course_id']; ?>" class="btn btn-primary btn-sm">Enter Course</a>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Pending Approval</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">You are not enrolled in any courses yet.</p>
                    <a href="student/courses.php" class="btn btn-primary">Browse Courses</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Recent Activities</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">No recent activities.</p>
            </div>
        </div>
    </div>
</div>

<?php elseif($_SESSION['role'] == 'instructor'): ?>
<!-- Instructor Dashboard -->
<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title">
                            <?php 
                            $my_courses = $functions->getCourses($_SESSION['user_id']);
                            echo count($my_courses);
                            ?>
                        </h4>
                        <p class="card-text">My Courses</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-book fa-2x"></i>
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
                        <h4 class="card-title">
                            <?php 
                            $total_students = 0;
                            foreach($my_courses as $course) {
                                $students = $functions->getCourseStudents($course['course_id']);
                                $total_students += count($students);
                            }
                            echo $total_students;
                            ?>
                        </h4>
                        <p class="card-text">Total Students</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-users fa-2x"></i>
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
                        <h4 class="card-title">0</h4>
                        <p class="card-text">Pending Reviews</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-comments fa-2x"></i>
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
                        <h4 class="card-title">0</h4>
                        <p class="card-text">Assignments</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-tasks fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Admin Dashboard -->
<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title">0</h4>
                        <p class="card-text">Total Users</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-users fa-2x"></i>
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
                        <h4 class="card-title">0</h4>
                        <p class="card-text">Total Courses</p>
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
                        <h4 class="card-title">0</h4>
                        <p class="card-text">Pending Enrollments</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-user-plus fa-2x"></i>
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
                        <h4 class="card-title">0</h4>
                        <p class="card-text">Active Sessions</p>
                    </div>
                    <div class="align-self-center">
                        <i class="fas fa-chart-line fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>