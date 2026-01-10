<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('forums.php');
}

$post_id = $_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get main post details
$stmt = $conn->prepare("SELECT fp.*, u.first_name, u.last_name, u.username, 
                               f.forum_id, f.title as forum_title, f.is_locked as forum_locked,
                               c.course_id, c.title as course_title
                       FROM forum_posts fp
                       JOIN users u ON fp.user_id = u.user_id
                       JOIN forums f ON fp.forum_id = f.forum_id
                       JOIN courses c ON f.course_id = c.course_id
                       WHERE fp.post_id = ?");
$stmt->execute([$post_id]);
$main_post = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$main_post) {
    $auth->redirect('forums.php');
}

// Check if student is enrolled
$stmt = $conn->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ? AND enrollment_status = 'approved'");
$stmt->execute([$_SESSION['user_id'], $main_post['course_id']]);
$enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$enrollment) {
    $auth->redirect('forums.php');
}

// Get replies to this post
$stmt = $conn->prepare("SELECT fp.*, u.first_name, u.last_name, u.username
                       FROM forum_posts fp
                       JOIN users u ON fp.user_id = u.user_id
                       WHERE fp.parent_post_id = ?
                       ORDER BY fp.created_at ASC");
$stmt->execute([$post_id]);
$replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle new reply
if($_POST && isset($_POST['create_reply'])) {
    $content = trim($_POST['content']);
    
    if(!empty($content)) {
        if(!$main_post['is_locked'] && !$main_post['forum_locked']) {
            $stmt = $conn->prepare("INSERT INTO forum_posts (forum_id, user_id, parent_post_id, content) VALUES (?, ?, ?, ?)");
            if($stmt->execute([$main_post['forum_id'], $_SESSION['user_id'], $post_id, $content])) {
                $success = "Reply posted successfully!";
                // Refresh the page to show the new reply
                header("Location: post_view.php?id=" . $post_id);
                exit();
            } else {
                $error = "Failed to post reply. Please try again.";
            }
        } else {
            $error = "This post or forum is locked. You cannot reply.";
        }
    } else {
        $error = "Reply content is required.";
    }
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Discussion Post</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="forum_view.php?id=<?php echo $main_post['forum_id']; ?>" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back to Forum
        </a>
        <?php if(!$main_post['is_locked'] && !$main_post['forum_locked']): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#replyModal">
                <i class="fas fa-reply"></i> Reply
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if(isset($success)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?php echo $success; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if(isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="forums.php">Forums</a></li>
        <li class="breadcrumb-item"><a href="forum_view.php?id=<?php echo $main_post['forum_id']; ?>"><?php echo htmlspecialchars($main_post['forum_title']); ?></a></li>
        <li class="breadcrumb-item active">Post</li>
    </ol>
</nav>

<!-- Main Post -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="card-title mb-0">
                    <?php if($main_post['is_pinned']): ?>
                        <i class="fas fa-thumbtack text-warning me-2" title="Pinned Post"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($main_post['title']); ?>
                    <?php if($main_post['is_locked']): ?>
                        <span class="badge bg-danger ms-2">Locked</span>
                    <?php endif; ?>
                </h4>
            </div>
            <div class="text-muted">
                <small>
                    <i class="fas fa-book"></i> <?php echo htmlspecialchars($main_post['course_title']); ?>
                </small>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-2 text-center">
                <div class="mb-3">
                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" 
                         style="width: 60px; height: 60px; font-size: 1.5rem;">
                        <?php echo strtoupper(substr($main_post['first_name'], 0, 1) . substr($main_post['last_name'], 0, 1)); ?>
                    </div>
                </div>
                <h6 class="mb-1"><?php echo htmlspecialchars($main_post['first_name'] . ' ' . $main_post['last_name']); ?></h6>
                <small class="text-muted">@<?php echo htmlspecialchars($main_post['username']); ?></small>
            </div>
            <div class="col-md-10">
                <div class="post-content">
                    <?php echo nl2br(htmlspecialchars($main_post['content'])); ?>
                </div>
                <div class="mt-3 pt-3 border-top">
                    <small class="text-muted">
                        <i class="fas fa-clock"></i> Posted on <?php echo date('F j, Y \a\t g:i A', strtotime($main_post['created_at'])); ?>
                    </small>
                    <?php if($main_post['updated_at'] && $main_post['updated_at'] != $main_post['created_at']): ?>
                        <small class="text-muted ms-3">
                            <i class="fas fa-edit"></i> Last edited on <?php echo date('F j, Y \a\t g:i A', strtotime($main_post['updated_at'])); ?>
                        </small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Replies Section -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-reply"></i> Replies
            <span class="badge bg-primary"><?php echo count($replies); ?></span>
        </h5>
    </div>
    <div class="card-body">
        <?php if(count($replies) > 0): ?>
            <?php foreach($replies as $reply): ?>
            <div class="border-bottom pb-3 mb-3">
                <div class="row">
                    <div class="col-md-2 text-center">
                        <div class="mb-3">
                            <div class="bg-secondary text-white rounded-circle d-inline-flex align-items-center justify-content-center" 
                                 style="width: 50px; height: 50px; font-size: 1.2rem;">
                                <?php echo strtoupper(substr($reply['first_name'], 0, 1) . substr($reply['last_name'], 0, 1)); ?>
                            </div>
                        </div>
                        <h6 class="mb-1 small"><?php echo htmlspecialchars($reply['first_name'] . ' ' . $reply['last_name']); ?></h6>
                        <small class="text-muted">@<?php echo htmlspecialchars($reply['username']); ?></small>
                    </div>
                    <div class="col-md-10">
                        <div class="reply-content">
                            <?php echo nl2br(htmlspecialchars($reply['content'])); ?>
                        </div>
                        <div class="mt-2 pt-2">
                            <small class="text-muted">
                                <i class="fas fa-clock"></i> Replied on <?php echo date('F j, Y \a\t g:i A', strtotime($reply['created_at'])); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-comments fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No Replies Yet</h5>
                <p class="text-muted">Be the first to reply to this post!</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reply Modal -->
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Post a Reply</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="content" class="form-label">Your Reply *</label>
                        <textarea class="form-control" id="content" name="content" rows="6" required
                                  placeholder="Write your reply here..."></textarea>
                        <div class="form-text">Be respectful and constructive in your reply.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_reply" class="btn btn-primary">Post Reply</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>