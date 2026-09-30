<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn()) {
    $auth->redirect('../login.php');
}

if(!isset($_GET['id'])) {
    $auth->redirect('forums.php');
}

$post_id = intval($_GET['id']);
$viewer_id = intval($_SESSION['user_id']);
$viewer_role = $_SESSION['role'] ?? 'student';
$db = new Database();
$conn = $db->getConnection();

// Get main post details. Columns are named explicitly because fp.* also
// carries forum_id, which collided with the forums row.
$stmt = $conn->prepare("SELECT fp.post_id, fp.forum_id, fp.user_id, fp.parent_post_id,
                               fp.title, fp.content, fp.is_pinned, fp.is_locked,
                               fp.created_at, fp.updated_at,
                               u.first_name, u.last_name, u.username,
                               f.title AS forum_title, f.is_locked AS forum_locked,
                               c.course_id, c.title AS course_title, c.instructor_id
                        FROM forum_posts fp
                        JOIN users u ON fp.user_id = u.user_id
                        JOIN forums f ON fp.forum_id = f.forum_id
                        JOIN courses c ON f.course_id = c.course_id
                        WHERE fp.post_id = ?");
$stmt->execute([$post_id]);
$main_post = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$main_post) {
    $auth->redirect($viewer_role === 'instructor' ? '../instructor/courses.php' : 'forums.php');
}

// Access is role-aware, matching forum_view.php. That page links here for
// both roles, so requiring the student role bounced every instructor who
// clicked through from a forum they own.
$back_url = 'forums.php';
$can_access = false;

if($viewer_role === 'instructor') {
    $can_access = (int)$main_post['instructor_id'] === $viewer_id;
    $back_url = '../instructor/course_manage.php?id=' . (int)$main_post['course_id'];
} else {
    $stmt = $conn->prepare("SELECT 1 FROM enrollments
                            WHERE user_id = ? AND course_id = ? AND enrollment_status = 'approved'");
    $stmt->execute([$viewer_id, $main_post['course_id']]);
    $can_access = (bool)$stmt->fetchColumn();
}

if(!$can_access) {
    $auth->redirect($viewer_role === 'instructor' ? '../instructor/courses.php' : 'forums.php');
}

$forum_url = 'forum_view.php?id=' . (int)$main_post['forum_id'];

// A post or its forum can be locked independently; both block replies.
$post_locked = (int)$main_post['is_locked'] === 1;
$forum_locked = (int)$main_post['forum_locked'] === 1;
$can_reply = !$post_locked && !$forum_locked;

// Get replies to this post
$stmt = $conn->prepare("SELECT fp.post_id, fp.user_id, fp.content, fp.created_at, fp.updated_at,
                               u.first_name, u.last_name, u.username
                        FROM forum_posts fp
                        JOIN users u ON fp.user_id = u.user_id
                        WHERE fp.parent_post_id = ?
                        ORDER BY fp.created_at ASC");
$stmt->execute([$post_id]);
$replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle new reply
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
$reply_draft = '';

if($_POST && isset($_POST['create_reply'])) {
    verify_csrf();

    // Without ?? '' a crafted POST omitting content raises an undefined-key
    // warning and passes null to trim().
    $reply_draft = trim((string)($_POST['content'] ?? ''));
    $max_length = 10000;

    if(!$can_reply) {
        $error = "This thread is locked, so replies are closed.";
    } elseif($reply_draft === '') {
        $error = "Reply content is required.";
    } elseif(mb_strlen($reply_draft) > $max_length) {
        $error = "Replies are limited to " . number_format($max_length) . " characters.";
    } else {
        $stmt = $conn->prepare("INSERT INTO forum_posts (forum_id, user_id, parent_post_id, content)
                                VALUES (?, ?, ?, ?)");
        if($stmt->execute([(int)$main_post['forum_id'], $viewer_id, $post_id, $reply_draft])) {
            // Flash rather than setting a local: the old code assigned
            // $success and then immediately redirected, so the message was
            // discarded and the student never saw confirmation.
            $_SESSION['success'] = "Reply posted successfully.";
            header("Location: post_view.php?id=" . $post_id);
            exit();
        }
        $error = "Failed to post reply. Please try again.";
    }
}

require_once '../includes/header.php';
?>

<?php
$actions = '<a href="' . e($back_url) . '" class="btn btn-secondary">'
    . '<i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back</a>';

if($can_reply) {
    $actions .= ' <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#replyModal">'
        . '<i class="fas fa-reply me-1" aria-hidden="true"></i> Reply</button>';
}

echo ui_page_header(
    $main_post['title'] ?: 'Discussion post',
    $main_post['forum_title'] . ' - ' . $main_post['course_title'],
    $actions,
    'Discussion'
);
?>

<?php if(!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-1" aria-hidden="true"></i> <?php echo e($success); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
</div>
<?php endif; ?>

<?php if(!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-1" aria-hidden="true"></i> <?php echo e($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
</div>
<?php endif; ?>

<?php if($post_locked || $forum_locked): ?>
<div class="alert alert-warning border-0 d-flex align-items-center gap-2" role="status">
    <i class="fas fa-lock" aria-hidden="true"></i>
    <span>
        <?php if($forum_locked && $post_locked): ?>
            This forum and this post are both locked. New replies are closed.
        <?php elseif($forum_locked): ?>
            The <strong><?php echo e($main_post['forum_title']); ?></strong> forum is locked. New replies are closed.
        <?php else: ?>
            This post is locked. New replies are closed.
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>

<?php
echo ui_breadcrumbs([
    ['label' => 'Forums', 'url' => $viewer_role === 'instructor' ? '../instructor/courses.php' : 'forums.php'],
    ['label' => $main_post['forum_title'], 'url' => $forum_url],
], 'Dashboard', $viewer_role === 'instructor' ? '../instructor/dashboard.php' : 'dashboard.php');
?>

<!-- Main Post -->
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <?php if($post_locked): ?>
                <?php echo ui_badge('Locked', 'danger', 'fa-lock'); ?>
            <?php endif; ?>
            <?php if((int)$main_post['is_pinned'] === 1): ?>
                <?php echo ui_badge('Pinned', 'warning', 'fa-thumbtack'); ?>
            <?php endif; ?>
            <h2 class="h5 mb-0 text-truncate"><?php echo e($main_post['title'] ?: 'Discussion post'); ?></h2>
        </div>
        <span class="text-muted small">
            <i class="fas fa-book me-1" aria-hidden="true"></i><?php echo e($main_post['course_title']); ?>
        </span>
    </div>
    <div class="card-body">
        <div class="d-flex gap-3">
            <div class="flex-shrink-0 text-center d-none d-sm-block" style="width: 96px;">
                <?php echo ui_avatar($main_post['first_name'], $main_post['last_name'], 'lg'); ?>
                <div class="mt-2 small fw-semibold text-truncate">
                    <?php echo e(ui_user_name($main_post)); ?>
                </div>
                <div class="small text-muted">@<?php echo e($main_post['username']); ?></div>
            </div>
            <div class="flex-grow-1 min-w-0">
                <div class="d-sm-none mb-3 d-flex align-items-center gap-2">
                    <?php echo ui_avatar($main_post['first_name'], $main_post['last_name']); ?>
                    <div>
                        <div class="small fw-semibold"><?php echo e(ui_user_name($main_post)); ?></div>
                        <div class="small text-muted">@<?php echo e($main_post['username']); ?></div>
                    </div>
                </div>
                <div class="post-content">
                    <?php echo nl2br(e($main_post['content'])); ?>
                </div>
                <div class="mt-3 pt-3 border-top d-flex flex-wrap gap-3 small text-muted">
                    <span>
                        <i class="fas fa-clock me-1" aria-hidden="true"></i>
                        Posted <?php echo e(ui_datetime($main_post['created_at'])); ?>
                    </span>
                    <?php if(!empty($main_post['updated_at']) && $main_post['updated_at'] !== $main_post['created_at']): ?>
                        <span>
                            <i class="fas fa-edit me-1" aria-hidden="true"></i>
                            Edited <?php echo e(ui_datetime($main_post['updated_at'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Replies -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">
            <i class="fas fa-reply me-1" aria-hidden="true"></i> Replies
        </h2>
        <?php echo ui_badge((string)count($replies), 'neutral'); ?>
    </div>
    <div class="card-body">
        <?php if(count($replies) > 0): ?>
            <div class="d-flex flex-column gap-3">
                <?php $last_reply_index = count($replies) - 1; ?>
                <?php foreach($replies as $reply_index => $reply): ?>
                <div class="d-flex gap-3 pb-3 <?php echo $reply_index < $last_reply_index ? 'border-bottom' : ''; ?>"><?php // last reply has no divider; padding keeps the block from touching the card edge ?>
                    <div class="flex-shrink-0 d-none d-sm-block">
                        <?php echo ui_avatar($reply['first_name'], $reply['last_name']); ?>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
                            <span class="fw-semibold"><?php echo e(ui_user_name($reply)); ?></span>
                            <span class="small text-muted">@<?php echo e($reply['username']); ?></span>
                            <span class="small text-muted ms-auto">
                                <?php echo e(ui_ago($reply['created_at'], 'just now')); ?>
                            </span>
                        </div>
                        <div class="reply-content">
                            <?php echo nl2br(e($reply['content'])); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php
            echo ui_empty_state(
                'fa-comments',
                'No replies yet',
                $can_reply ? 'Be the first to reply to this post.' : 'Replies are closed for this thread.'
            );
            ?>
        <?php endif; ?>
    </div>
    <?php if($can_reply): ?>
    <div class="card-footer bg-transparent d-none d-lg-block">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#replyModal">
            <i class="fas fa-reply me-1" aria-hidden="true"></i> Reply to this post
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Reply Modal -->
<?php if($can_reply): ?>
<div class="modal fade" id="replyModal" tabindex="-1" aria-labelledby="replyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="replyModalLabel">Post a reply</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="content" class="form-label">Your reply <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="content" name="content" rows="6" required
                                  maxlength="10000"
                                  placeholder="Write your reply here..."><?php echo ui_textarea_value($reply_draft); ?></textarea>
                        <div class="form-text">Be respectful and constructive. Up to 10,000 characters.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_reply" class="btn btn-primary">Post reply</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
