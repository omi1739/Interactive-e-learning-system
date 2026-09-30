<?php
require_once 'includes/bootstrap.php';

if(!$auth->isLoggedIn()) {
    $auth->redirect('login.php');
}

// Explicit guard. The page used to fall through to the admin view for any
// logged-in role that was not student or instructor, so the authorization
// decision was an accident of the redirect list rather than a check. A new
// role added to the users ENUM would have landed here as an administrator.
$role = $_SESSION['role'] ?? 'student';
if($role === 'instructor') {
    $auth->redirect('instructor/dashboard.php');
} elseif($role === 'student') {
    $auth->redirect('student/dashboard.php');
} elseif(!$auth->hasRole('admin')) {
    $auth->redirect('login.php');
}

$conn = $db->getConnection();

$total_users = (int)$functions->getTotalUsers();
$pending_enrollments = (int)$functions->getPendingEnrollments();

$stmt = $conn->query("SELECT COUNT(*) AS cnt FROM submissions");
$total_submissions = (int)($stmt->fetchColumn() ?: 0);

$stmt = $conn->query("SELECT
                             COUNT(*) AS reviews,
                             SUM(status = 'completed') AS reviews_completed
                      FROM peer_reviews");
$review_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$total_reviews = (int)($review_row['reviews'] ?? 0);
$completed_reviews = (int)($review_row['reviews_completed'] ?? 0);

// getTotalCourses() counts every row, drafts included, but the tile labelled
// the result "Active Courses". A draft is not active: it is hidden from the
// student catalogue. Both numbers are reported so the tile cannot mislead.
$stmt = $conn->query("SELECT
                             COUNT(*) AS total,
                             SUM(is_published = 1) AS published
                      FROM courses");
$course_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$total_courses = (int)($course_row['total'] ?? 0);
$published_courses = (int)($course_row['published'] ?? 0);
$draft_courses = $total_courses - $published_courses;

// User directory, filtered server-side. getAllUsers() returns the whole table
// with no limit, so the page grew without bound as accounts were added.
$search = trim((string)($_GET['q'] ?? ''));
$role_filter = (string)($_GET['role'] ?? '');
if(!in_array($role_filter, ['student', 'instructor', 'admin'], true)) {
    $role_filter = '';
}

$where = [];
$params = [];
if($search !== '') {
    $where[] = "(u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if($role_filter !== '') {
    $where[] = "u.role = ?";
    $params[] = $role_filter;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $conn->prepare("SELECT COUNT(*) FROM users u $where_sql");
$count_stmt->execute($params);
$matching_users = (int)$count_stmt->fetchColumn();

$page_size = 50;
$page_count = max(1, (int)ceil($matching_users / $page_size));
$page = (int)($_GET['page'] ?? 1);
$page = max(1, min($page, $page_count));
$offset = ($page - 1) * $page_size;

$list_stmt = $conn->prepare("SELECT u.user_id, u.username, u.email, u.first_name, u.last_name,
                                    u.role, u.is_active, u.created_at, u.last_login
                             FROM users u
                             $where_sql
                             ORDER BY u.created_at DESC, u.user_id DESC
                             LIMIT $page_size OFFSET $offset");
$list_stmt->execute($params);
$users = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

$role_counts = ['student' => 0, 'instructor' => 0, 'admin' => 0];
$active_users = 0;
$stmt = $conn->query("SELECT role, SUM(is_active = 1) AS active, COUNT(*) AS total
                      FROM users GROUP BY role");
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $r = (string)$row['role'];
    if(isset($role_counts[$r])) {
        $role_counts[$r] = (int)$row['total'];
        $active_users += (int)$row['active'];
    }
}

function admin_page_url(array $params): string
{
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return 'dashboard.php' . ($params ? '?' . http_build_query($params) : '');
}

require_once 'includes/header.php';
?>

<?php
// The web installer and its "Database Tools & Reset" page were removed: they
// were reachable by any visitor and could drop all tables. Schema and account
// management now happen from the command line (see README.md).
echo ui_page_header(
    'Administrator dashboard',
    'System-wide overview',
    '',
    'Administration'
);
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <?php echo ui_stat('Registered users', (string)$total_users, 'fa-users', 'brand',
            $active_users . ' active, ' . ($total_users - $active_users) . ' disabled'); ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php echo ui_stat('Courses', (string)$total_courses, 'fa-book', 'info',
            $published_courses . ' published, ' . $draft_courses . ' draft'); ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php echo ui_stat('Pending enrollments', (string)$pending_enrollments, 'fa-clock',
            $pending_enrollments > 0 ? 'warning' : 'info',
            $pending_enrollments > 0 ? 'Awaiting an instructor decision' : 'None waiting'); ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php echo ui_stat('Peer reviews', (string)$total_reviews, 'fa-comments', 'success',
            $completed_reviews . ' of ' . $total_reviews . ' completed, from '
                . $total_submissions . ' submissions'); ?>
    </div>
</div>

<?php if($draft_courses > 0): ?>
<div class="alert alert-info" role="alert">
    <i class="fas fa-circle-info me-1" aria-hidden="true"></i>
    <strong><?php echo $draft_courses; ?></strong> of <?php echo $total_courses; ?> courses are still drafts and
    are not visible to students. Drafts are counted in the total but are not active courses.
</div>
<?php endif; ?>

<!-- User Directory -->
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h5 mb-0">
            <i class="fas fa-user-shield me-1" aria-hidden="true"></i> User directory
        </h2>
        <form method="GET" class="d-flex flex-wrap align-items-center gap-2" role="search" data-auto-submit>
            <label class="visually-hidden" for="q">Search users</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q"
                   placeholder="Name, username or email" value="<?php echo e($search); ?>"
                   style="min-width: 200px;">
            <label class="visually-hidden" for="role">Filter by role</label>
            <select class="form-select form-select-sm" id="role" name="role" data-auto-submit>
                <option value="">All roles</option>
                <?php foreach(['student', 'instructor', 'admin'] as $r): ?>
                    <option value="<?php echo $r; ?>" <?php echo $role_filter === $r ? 'selected' : ''; ?>>
                        <?php echo ucfirst($r); ?> (<?php echo $role_counts[$r]; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
            <?php if($search !== '' || $role_filter !== ''): ?>
                <a href="dashboard.php" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body">
        <p class="small text-muted">
            <?php if($search !== '' || $role_filter !== ''): ?>
                <?php echo $matching_users; ?> account<?php echo $matching_users === 1 ? '' : 's'; ?> match
                your filter.
            <?php else: ?>
                All <?php echo $total_users; ?> accounts, newest first.
            <?php endif; ?>
        </p>

        <?php if(count($users) > 0): ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <caption class="visually-hidden">Platform user accounts</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                            <th scope="col">Last sign-in</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $u): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php echo ui_avatar($u['first_name'], $u['last_name'], 'sm'); ?>
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-truncate"><?php echo e(ui_user_name($u)); ?></div>
                                        <div class="small text-muted">@<?php echo e($u['username']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="small text-muted text-break"><?php echo e($u['email']); ?></td>
                            <td><?php echo ui_badge(ucfirst((string)$u['role']),
                                    $u['role'] === 'admin' ? 'danger' : ($u['role'] === 'instructor' ? 'primary' : 'neutral')); ?></td>
                            <td>
                                <?php echo (int)$u['is_active'] === 1
                                    ? ui_badge('Active', 'success', 'fa-circle-check')
                                    : ui_badge('Disabled', 'danger', 'fa-ban'); ?>
                            </td>
                            <td class="text-nowrap small text-muted">
                                <?php echo e(ui_date($u['created_at'], 'M j, Y', 'Unknown')); ?>
                            </td>
                            <td class="text-nowrap small text-muted">
                                <?php echo e(ui_ago($u['last_login'] ?? null, 'Never')); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if($page_count > 1): ?>
            <nav class="mt-3" aria-label="User directory pages">
                <ul class="pagination pagination-sm mb-0 justify-content-end">
                    <?php if($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo e_attr(admin_page_url([
                                'q' => $search, 'role' => $role_filter, 'page' => $page - 1,
                            ])); ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <li class="page-item disabled" aria-current="page">
                        <span class="page-link">Page <?php echo $page; ?> of <?php echo $page_count; ?></span>
                    </li>

                    <?php if($page < $page_count): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo e_attr(admin_page_url([
                                'q' => $search, 'role' => $role_filter, 'page' => $page + 1,
                            ])); ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        <?php else: ?>
            <?php
            echo ui_empty_state(
                'fa-user-slash',
                'No accounts match',
                ($search !== '' || $role_filter !== '')
                    ? 'Try a different search term or clear the role filter.'
                    : 'There are no accounts on the platform yet.'
            );
            ?>
        <?php endif; ?>
    </div>
    <div class="card-footer bg-transparent">
        <p class="small text-muted mb-0">
            <i class="fas fa-circle-info me-1" aria-hidden="true"></i>
            Account creation, deactivation and password resets are handled from the command line. See README.md.
        </p>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
