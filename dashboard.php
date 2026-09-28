<?php
require_once 'includes/bootstrap.php';

if(!$auth->isLoggedIn()) {
    $auth->redirect('login.php');
}

$role = $_SESSION['role'] ?? 'student';

// Clean redirection for student and instructor to their dedicated specialized dashboards
if($role === 'instructor') {
    $auth->redirect('instructor/dashboard.php');
} elseif($role === 'student') {
    $auth->redirect('student/dashboard.php');
}

// Admin Dashboard view
$conn = $db->getConnection();
$total_users = $functions->getTotalUsers();
$total_courses = $functions->getTotalCourses();
$pending_enrollments = $functions->getPendingEnrollments();

// Total submissions and reviews
$stmt = $conn->query("SELECT COUNT(*) as cnt FROM submissions");
$total_submissions = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;

$stmt = $conn->query("SELECT COUNT(*) as cnt FROM peer_reviews");
$total_reviews = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;

// Recent users list
$users = $functions->getAllUsers();

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2">Administrator Dashboard</h1>
        <p class="text-muted mb-0">System-wide overview and administrative control</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <?php // The web installer and its "Database Tools & Reset" page were
              // removed: they were reachable by any visitor and could drop all
              // tables. Schema and account management now happen from the
              // command line (see README.md). ?>
    </div>
</div>

<!-- Admin Statistics -->
<div class="row">
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-primary shadow-sm border-0 h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="card-title fw-bold mb-0"><?php echo $total_users; ?></h3>
                    <p class="card-text text-white-50">Total Registered Users</p>
                </div>
                <i class="fas fa-users fa-2x opacity-75"></i>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-success shadow-sm border-0 h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="card-title fw-bold mb-0"><?php echo $total_courses; ?></h3>
                    <p class="card-text text-white-50">Active Courses</p>
                </div>
                <i class="fas fa-book fa-2x opacity-75"></i>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-warning shadow-sm border-0 h-100 text-dark">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="card-title fw-bold mb-0"><?php echo $pending_enrollments; ?></h3>
                    <p class="card-text text-dark">Pending Enrollments</p>
                </div>
                <i class="fas fa-clock fa-2x opacity-75"></i>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-info shadow-sm border-0 h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="card-title fw-bold mb-0"><?php echo $total_reviews; ?></h3>
                    <p class="card-text text-white-50">Peer Reviews Conducted</p>
                </div>
                <i class="fas fa-comments fa-2x opacity-75"></i>
            </div>
        </div>
    </div>
</div>

<!-- All Users Table -->
<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold">
            <i class="fas fa-user-shield text-primary me-2"></i> User Directory
        </h5>
        <span class="badge bg-secondary"><?php echo count($users); ?> accounts</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users as $u): ?>
                    <tr>
                        <td class="ps-4">
                            <strong><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></strong>
                            <small class="d-block text-muted">@<?php echo htmlspecialchars($u['username']); ?></small>
                        </td>
                        <td><code><?php echo htmlspecialchars($u['email']); ?></code></td>
                        <td>
                            <span class="badge bg-<?php 
                                echo $u['role'] === 'instructor' ? 'primary' : ($u['role'] === 'admin' ? 'dark' : 'success'); 
                            ?>">
                                <?php echo ucfirst($u['role']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $u['is_active'] ? 'success' : 'danger'; ?>">
                                <?php echo $u['is_active'] ? 'Active' : 'Disabled'; ?>
                            </span>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($u['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>