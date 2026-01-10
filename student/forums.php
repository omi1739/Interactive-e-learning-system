<?php
require_once '../includes/bootstrap.php';

if(!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    $auth->redirect('../login.php');
}

$conn = $db->getConnection();

// Get student's enrolled courses with forums - FIXED QUERY
$stmt = $conn->prepare("SELECT 
    c.course_id, 
    c.title as course_title, 
    c.course_code,
    f.forum_id, 
    f.title as forum_title, 
    f.description as forum_description,
    f.is_locked,
    COALESCE(post_counts.post_count, 0) as post_count,
    COALESCE(post_counts.last_activity, f.created_at) as last_activity
FROM enrollments e
JOIN courses c ON e.course_id = c.course_id
JOIN forums f ON c.course_id = f.course_id
LEFT JOIN (
    SELECT forum_id, 
           COUNT(*) as post_count,
           MAX(created_at) as last_activity
    FROM forum_posts 
    GROUP BY forum_id
) as post_counts ON f.forum_id = post_counts.forum_id
WHERE e.user_id = ? 
AND e.enrollment_status = 'approved'
AND c.is_published = TRUE
ORDER BY c.title, f.title");
$stmt->execute([$_SESSION['user_id']]);
$forums_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group forums by course
$courses_with_forums = [];
foreach($forums_data as $forum) {
    $course_id = $forum['course_id'];
    if(!isset($courses_with_forums[$course_id])) {
        $courses_with_forums[$course_id] = [
            'course_title' => $forum['course_title'],
            'course_code' => $forum['course_code'],
            'forums' => []
        ];
    }
    
    $courses_with_forums[$course_id]['forums'][] = $forum;
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Discussion Forums</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <span class="badge bg-primary"><?php echo count($forums_data); ?> forums</span>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <?php if(count($courses_with_forums) > 0): ?>
            <?php foreach($courses_with_forums as $course_id => $course): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($course['course_title']); ?>
                            <small class="float-end"><?php echo htmlspecialchars($course['course_code']); ?></small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if(count($course['forums']) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Forum</th>
                                            <th>Description</th>
                                            <th>Posts</th>
                                            <th>Last Activity</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($course['forums'] as $forum): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($forum['forum_title']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($forum['forum_description'] ?? 'No description available'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $forum['post_count'] > 0 ? 'primary' : 'secondary'; ?>">
                                                    <?php echo $forum['post_count']; ?> posts
                                                </span>
                                            </td>
                                            <td>
                                                <?php if($forum['last_activity']): ?>
                                                    <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($forum['last_activity'])); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">No activity yet</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($forum['is_locked']): ?>
                                                    <span class="badge bg-danger">Locked</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="forum_view.php?id=<?php echo $forum['forum_id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i> View Forum
                                                </a>
                                                <?php if(!$forum['is_locked']): ?>
                                                    <a href="forum_view.php?id=<?php echo $forum['forum_id']; ?>#new-post" class="btn btn-success btn-sm">
                                                        <i class="fas fa-plus"></i> New Post
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-comments fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Forums Available</h5>
                                <p class="text-muted">This course doesn't have any discussion forums yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-comments fa-4x text-muted mb-4"></i>
                <h3 class="text-muted">No Forums Available</h3>
                <p class="text-muted">
                    You are not enrolled in any courses with discussion forums, or forums are being set up.
                </p>
                <div class="mt-3">
                    <a href="courses.php" class="btn btn-primary">Browse Courses</a>
                    <a href="../instructor/courses.php" class="btn btn-outline-secondary">Contact Instructor</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Stats -->
<?php if(count($forums_data) > 0): ?>
<div class="row mt-4">
    <div class="col-md-3">
        <div class="card text-white bg-primary">
            <div class="card-body text-center">
                <h4><?php echo count($courses_with_forums); ?></h4>
                <p>Courses with Forums</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-success">
            <div class="card-body text-center">
                <h4><?php echo count($forums_data); ?></h4>
                <p>Total Forums</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-info">
            <div class="card-body text-center">
                <h4><?php echo array_sum(array_column($forums_data, 'post_count')); ?></h4>
                <p>Total Posts</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-warning">
            <div class="card-body text-center">
                <h4><?php echo count(array_filter($forums_data, function($f) { return !$f['is_locked']; })); ?></h4>
                <p>Active Forums</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>