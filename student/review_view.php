<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || $_SESSION['role'] != 'student') {
    $auth->redirect('../login.php');
}

$review_id = $_GET['id'] ?? 0;

require_once '../includes/header.php';
?>

<div class="container">
    <h1>View Peer Review</h1>
    <p>Details of review #<?php echo $review_id; ?></p>
    
    <div class="card">
        <div class="card-body">
            <p class="text-muted">Peer review details will be displayed here.</p>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>