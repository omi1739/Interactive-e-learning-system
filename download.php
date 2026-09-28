<?php
/**
 * download.php - the ONLY way a submission file is ever served.
 *
 * Uploaded files are kept outside the web root (see config/local.php), and
 * uploads/ is additionally denied by .htaccess, so there is no public URL for
 * a submission. This script authorizes the requester and then streams the file.
 *
 * Who may download what:
 *   - the student who submitted it
 *   - the instructor who owns the course it belongs to
 *   - an admin
 *
 * A reviewer's access comes through student/review_complete.php, which links
 * here with the review id so the reviewer can only see the submissions they
 * were actually assigned.
 *
 * Usage: download.php?submission_id=123
 *         download.php?file=<stored name>&review_id=45
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (!$auth->isLoggedIn()) {
    $auth->redirect('login.php');
}

$viewer_id = (int) $_SESSION['user_id'];
$viewer_role = $_SESSION['role'] ?? '';
$submission_id = intval($_GET['submission_id'] ?? 0);
$review_id = intval($_GET['review_id'] ?? 0);
$conn = $db->getConnection();

/**
 * Load a submission joined to its course, only if the viewer may read it.
 * Returns the row, or null when the file must not be disclosed.
 */
function load_authorized_submission(PDO $conn, array $viewer, int $submission_id, int $review_id = 0) {
    $viewer_id = $viewer['id'];
    $role = $viewer['role'];

    $stmt = $conn->prepare("SELECT s.submission_id, s.student_id, s.file_path, s.file_name,
                                   c.course_id, c.instructor_id
                            FROM submissions s
                            JOIN assignments a ON s.assignment_id = a.assignment_id
                            JOIN modules m ON a.module_id = m.module_id
                            JOIN courses c ON m.course_id = c.course_id
                            WHERE s.submission_id = ?");
    $stmt->execute([$submission_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    // Admin
    if ($role === 'admin') {
        return $row;
    }

    // Owner of the submission
    if ((int) $row['student_id'] === $viewer_id) {
        return $row;
    }

    // Instructor who owns the course
    if ($role === 'instructor' && (int) $row['instructor_id'] === $viewer_id) {
        return $row;
    }

    // Assigned peer reviewer: the review must be this reviewer's, still in
    // progress (a completed review is no longer being worked on), and must
    // point at this exact submission.
    if ($role === 'student' && $review_id > 0) {
        $rv = $conn->prepare("SELECT submission_id, status FROM peer_reviews
                              WHERE review_id = ? AND reviewer_id = ?");
        $rv->execute([$review_id, $viewer_id]);
        $review = $rv->fetch(PDO::FETCH_ASSOC);
        if ($review
            && (int) $review['submission_id'] === (int) $row['submission_id']
            && $review['status'] !== 'completed'
            && (int) $row['student_id'] !== $viewer_id) {
            return $row;
        }
    }

    return null;
}

// Resolve the requested file.
$file = null;

if ($submission_id > 0) {
    $row = load_authorized_submission($conn, ['id' => $viewer_id, 'role' => $viewer_role], $submission_id, $review_id);
    if ($row) {
        $file = $row;
    }
} elseif (!empty($_GET['file'])) {
    // Filename-based lookup for legacy links; still fully authorized because
    // we search only within submissions the viewer may read.
    $requested = basename((string) $_GET['file']);
    $stmt = $conn->prepare("SELECT submission_id, student_id, file_path, file_name, course_id, instructor_id
                            FROM submissions s
                            JOIN assignments a ON s.assignment_id = a.assignment_id
                            JOIN modules m ON a.module_id = m.module_id
                            JOIN courses c ON m.course_id = c.course_id
                            WHERE s.file_path = ?");
    $stmt->execute([$requested]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = load_authorized_submission($conn, ['id' => $viewer_id, 'role' => $viewer_role], (int) $row['submission_id'], $review_id);
        if ($row) {
            $file = $row;
            break;
        }
    }
}

if (!$file || empty($file['file_path'])) {
    http_response_code(404);
    echo 'File not found or you do not have permission to download it.';
    exit;
}

// Resolve to a real path and make sure it cannot escape UPLOAD_DIR.
$stored = basename((string) $file['file_path']);
$base = realpath(rtrim(UPLOAD_DIR, '/'));
$target = $base ? $base . DIRECTORY_SEPARATOR . $stored : null;

if ($target === null || !is_file($target)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// Defence in depth: confirm the resolved path really is inside UPLOAD_DIR.
// A plain prefix test is not enough: with a base of "/home/u/data/uploads" it
// would also accept "/home/u/data/uploads_evil/x", so compare against the base
// plus a trailing separator. This also catches a stored file that is a symlink
// pointing outside the upload directory.
$real_target = realpath($target);
if ($base === null || $real_target === false || strpos($real_target, $base . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$download_name = $file['file_name'] ? basename((string) $file['file_name']) : $stored;

// Only hand back types that are safe to download; never let the server guess
// and execute something.
$safe_types = [
    'pdf' => 'application/pdf',
    'txt' => 'text/plain',
    'csv' => 'text/csv',
    'md'  => 'text/markdown',
    'zip' => 'application/zip',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
$ext = strtolower(pathinfo($download_name, PATHINFO_EXTENSION));
$mime = $safe_types[$ext] ?? 'application/octet-stream';

$size = filesize($target);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
// Force a download and prevent any inline execution/rendering.
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $download_name) . '"');
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');

readfile($target);
exit;
