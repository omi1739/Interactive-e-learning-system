<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('forums.php');
}

$forum_id = $_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get forum details
$stmt = $conn->prepare("SELECT f.*, c.title as course_title, c.course_id 
                       FROM forums f 
                       JOIN courses c ON f.course_id = c.course_id 
                       WHERE f.forum_id = ?");
$stmt->execute([$forum_id]);
$forum = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$forum) {
    $auth->redirect('forums.php');
}

// Check if student is enrolled in the course
$stmt = $conn->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ? AND enrollment_status = 'approved'");
$stmt->execute([$_SESSION['user_id'], $forum['course_id']]);
$enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$enrollment) {
    $auth->redirect('forums.php');
}

// Get forum posts with user information and reply counts
$stmt = $conn->prepare("SELECT fp.*, u.first_name, u.last_name, u.username,
                       (SELECT COUNT(*) FROM forum_posts fp2 WHERE fp2.parent_post_id = fp.post_id) as reply_count,
                       (SELECT MAX(created_at) FROM forum_posts fp3 WHERE fp3.parent_post_id = fp.post_id OR fp3.post_id = fp.post_id) as last_activity
                       FROM forum_posts fp
                       JOIN users u ON fp.user_id = u.user_id
                       WHERE fp.forum_id = ? AND fp.parent_post_id IS NULL
                       ORDER BY fp.is_pinned DESC, last_activity DESC");
$stmt->execute([$forum_id]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle new post
if($_POST && isset($_POST['create_post'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    
    if(!empty($title) && !empty($content)) {
        $stmt = $conn->prepare("INSERT INTO forum_posts (forum_id, user_id, title, content) VALUES (?, ?, ?, ?)");
        if($stmt->execute([$forum_id, $_SESSION['user_id'], $title, $content])) {
            $success = "Post created successfully!";
            // Refresh the page to show the new post
            header("Location: forum_view.php?id=" . $forum_id);
            exit();
        } else {
            $error = "Failed to create post. Please try again.";
        }
    } else {
        $error = "Title and content are required.";
    }
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><?php echo htmlspecialchars($forum['title']); ?></h1>
    <?php if(!$forum['is_locked']): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPostModal">
            <i class="fas fa-plus"></i> New Post
        </button>
    <?php endif; ?>
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

<!-- Forum Info Card -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <h5 class="card-title"><?php echo htmlspecialchars($forum['title']); ?></h5>
                <p class="card-text"><?php echo htmlspecialchars($forum['description'] ?? 'No description available.'); ?></p>
                <p class="card-text">
                    <small class="text-muted">
                        <i class="fas fa-book"></i> Course: <?php echo htmlspecialchars($forum['course_title']); ?>
                    </small>
                </p>
            </div>
            <div class="col-md-4 text-end">
                <?php if($forum['is_locked']): ?>
                    <span class="badge bg-danger fs-6">
                        <i class="fas fa-lock"></i> Forum Locked
                    </span>
                    <p class="text-muted mt-2">No new posts allowed</p>
                <?php else: ?>
                    <span class="badge bg-success fs-6">
                        <i class="fas fa-comments"></i> Active Forum
                    </span>
                    <p class="text-muted mt-2">You can post and reply</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Posts List -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-list"></i> Discussion Posts
            <span class="badge bg-primary"><?php echo count($posts); ?> posts</span>
        </h5>
    </div>
    <div class="card-body">
        <?php if(count($posts) > 0): ?>
            <?php foreach($posts as $post): ?>
            <div class="border-bottom pb-3 mb-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h5 class="mb-2">
                            <?php if($post['is_pinned']): ?>
                                <i class="fas fa-thumbtack text-warning" title="Pinned Post"></i>
                            <?php endif; ?>
                            <a href="post_view.php?id=<?php echo $post['post_id']; ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($post['title']); ?>
                            </a>
                            <?php if($post['is_locked']): ?>
                                <span class="badge bg-danger ms-1">Locked</span>
                            <?php endif; ?>
                        </h5>
                        
                        <p class="text-muted mb-2">
                            <i class="fas fa-user"></i> 
                            By <?php echo htmlspecialchars($post['first_name'] . ' ' . $post['last_name']); ?> 
                            on <?php echo date('M j, Y g:i A', strtotime($post['created_at'])); ?>
                        </p>
                        
                        <p class="mb-2">
                            <?php 
                            $content = strip_tags($post['content']);
                            echo strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content;
                            ?>
                        </p>
                        
                        <div class="d-flex align-items-center">
                            <span class="badge bg-secondary me-2">
                                <i class="fas fa-reply"></i> <?php echo $post['reply_count']; ?> replies
                            </span>
                            <?php if($post['last_activity']): ?>
                                <small class="text-muted">
                                    Last activity: <?php echo date('M j, Y g:i A', strtotime($post['last_activity'])); ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end ms-3">
                        <a href="post_view.php?id=<?php echo $post['post_id']; ?>" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <?php if($post['reply_count'] > 0): ?>
                            <span class="badge bg-primary mt-1"><?php echo $post['reply_count']; ?> replies</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-comments fa-4x text-muted mb-3"></i>
                <h5 class="text-muted">No Posts Yet</h5>
                <p class="text-muted">Be the first to start a discussion in this forum!</p>
                <?php if(!$forum['is_locked']): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPostModal">
                        <i class="fas fa-plus"></i> Create First Post
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Post Modal -->
<div class="modal fade" id="createPostModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Post</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Post Title *</label>
                        <input type="text" class="form-control" id="title" name="title" required maxlength="200"
                               placeholder="Enter a descriptive title for your post">
                    </div>
                    <div class="mb-3">
                        <label for="content" class="form-label">Post Content *</label>
                        <textarea class="form-control" id="content" name="content" rows="8" required
                                  placeholder="Write your discussion post here..."></textarea>
                        <div class="form-text">You can use basic formatting. Be respectful and on-topic.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_post" class="btn btn-primary">Create Post</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>