<?php
require_once 'includes/bootstrap.php';

if(!$auth->isLoggedIn()) {
    $auth->redirect('login.php');
}

// Get user profile
$user_profile = $functions->getUserProfile($_SESSION['user_id']);

// Handle profile update
if($_POST && isset($_POST['update_profile'])) {
    verify_csrf();
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    
    if($functions->updateUserProfile($_SESSION['user_id'], $first_name, $last_name, $email)) {
        // Update session
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['email'] = $email;
        
        $success = "Profile updated successfully!";
        // Refresh profile data
        $user_profile = $functions->getUserProfile($_SESSION['user_id']);
    } else {
        $error = "Failed to update profile. Please try again.";
    }
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">My Profile</h1>
</div>

<?php if(isset($success)): ?>
<div class="alert alert-success"><?php echo e($success); ?></div>
<?php endif; ?>

<?php if(isset($error)): ?>
<div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Profile Information</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="first_name" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" 
                                       value="<?php echo htmlspecialchars($user_profile['first_name']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="last_name" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" 
                                       value="<?php echo htmlspecialchars($user_profile['last_name']); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($user_profile['email']); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user_profile['username']); ?>" readonly>
                        <div class="form-text">Username cannot be changed.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <input type="text" class="form-control" id="role" value="<?php echo ucfirst($user_profile['role']); ?>" readonly>
                    </div>
                    
                    <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Account Statistics</h5>
            </div>
            <div class="card-body">
                <?php if($_SESSION['role'] == 'student'): ?>
                    <?php $enrolled_courses = $functions->getEnrolledCourses($_SESSION['user_id']); ?>
                    <p><strong>Enrolled Courses:</strong> <?php echo count($enrolled_courses); ?></p>
                    <p><strong>Pending Assignments:</strong> 
                        <?php 
                        $assignments = $functions->getStudentAssignments($_SESSION['user_id']);
                        $pending = 0;
                        foreach($assignments as $assignment) {
                            if(!$assignment['submission_id']) $pending++;
                        }
                        echo $pending;
                        ?>
                    </p>
                <?php elseif($_SESSION['role'] == 'instructor'): ?>
                    <?php $my_courses = $functions->getCourses($_SESSION['user_id']); ?>
                    <p><strong>My Courses:</strong> <?php echo count($my_courses); ?></p>
                    <p><strong>Total Students:</strong> 
                        <?php 
                        $total_students = 0;
                        foreach($my_courses as $course) {
                            $students = $functions->getCourseStudents($course['course_id']);
                            $total_students += count($students);
                        }
                        echo $total_students;
                        ?>
                    </p>
                <?php endif; ?>
                
                <p><strong>Member Since:</strong> <?php echo date('M j, Y', strtotime($user_profile['created_at'])); ?></p>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <?php if($_SESSION['role'] == 'student'): ?>
                    <a href="student/courses.php" class="btn btn-outline-primary w-100 mb-2">My Courses</a>
                    <a href="student/assignments.php" class="btn btn-outline-success w-100 mb-2">Assignments</a>
                <?php elseif($_SESSION['role'] == 'instructor'): ?>
                    <a href="instructor/courses.php" class="btn btn-outline-primary w-100 mb-2">My Courses</a>
                    <a href="instructor/students.php" class="btn btn-outline-success w-100 mb-2">Students</a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn btn-outline-info w-100">Dashboard</a>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
