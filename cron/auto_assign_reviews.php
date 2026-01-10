<?php
/**
 * Auto Peer Review Assignment Script
 * 
 * This script automatically assigns peer reviews for assignments after their due dates
 * Should be run via cron job daily: 0 2 * * * /usr/bin/php /path/to/auto_assign_reviews.php
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define the base path
define('BASE_PATH', dirname(__DIR__));
define('APP_ROOT', '/Interactive-e-learning-system');

// Include the bootstrap file
require_once BASE_PATH . '/includes/bootstrap.php';

/**
 * Process assignments that are due and need peer review assignment
 */
function processDueAssignments() {
    global $db, $functions;
    
    $conn = $db->getConnection();
    $results = [
        'processed' => 0,
        'assignments' => [],
        'errors' => []
    ];
    
    try {
        // Find assignments where due date has passed and have submissions
        $stmt = $conn->prepare("SELECT DISTINCT a.assignment_id, a.title, a.due_date,
                               COUNT(s.submission_id) as submission_count,
                               COUNT(pr.review_id) as existing_reviews
                       FROM assignments a
                       LEFT JOIN submissions s ON a.assignment_id = s.assignment_id
                       LEFT JOIN peer_reviews pr ON s.submission_id = pr.submission_id
                       WHERE a.due_date < NOW() 
                       AND a.is_published = TRUE
                       AND s.submission_id IS NOT NULL
                       GROUP BY a.assignment_id
                       HAVING submission_count >= 2"); // Need at least 2 submissions for peer review
        
        $stmt->execute();
        $due_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach($due_assignments as $assignment) {
            try {
                $assignment_id = $assignment['assignment_id'];
                
                // Check if reviews are already sufficiently assigned
                $review_stmt = $conn->prepare("SELECT COUNT(DISTINCT s.submission_id) as submissions_with_reviews
                                       FROM submissions s
                                       LEFT JOIN peer_reviews pr ON s.submission_id = pr.submission_id
                                       WHERE s.assignment_id = ? AND pr.review_id IS NOT NULL");
                $review_stmt->execute([$assignment_id]);
                $review_data = $review_stmt->fetch(PDO::FETCH_ASSOC);
                
                $submissions_with_reviews = $review_data['submissions_with_reviews'] ?? 0;
                $total_submissions = $assignment['submission_count'];
                
                // If less than 50% of submissions have reviews, assign them
                if ($submissions_with_reviews < ($total_submissions * 0.5)) {
                    $assignments_made = $functions->assignPeerReviews($assignment_id, 2);
                    
                    $results['assignments'][$assignment_id] = [
                        'title' => $assignment['title'],
                        'due_date' => $assignment['due_date'],
                        'total_submissions' => $total_submissions,
                        'reviews_assigned' => $assignments_made,
                        'status' => $assignments_made !== false ? 'success' : 'failed'
                    ];
                    
                    if ($assignments_made !== false) {
                        $results['processed']++;
                        
                        // Log the assignment
                        error_log("Auto-assigned $assignments_made peer reviews for assignment: " . $assignment['title']);
                    } else {
                        $results['errors'][] = "Failed to assign reviews for assignment: " . $assignment['title'];
                    }
                } else {
                    $results['assignments'][$assignment_id] = [
                        'title' => $assignment['title'],
                        'status' => 'skipped',
                        'reason' => 'Already has sufficient reviews assigned'
                    ];
                }
                
            } catch (Exception $e) {
                $results['errors'][] = "Error processing assignment {$assignment['title']}: " . $e->getMessage();
                error_log("Auto-assignment error for assignment {$assignment_id}: " . $e->getMessage());
            }
        }
        
        return $results;
        
    } catch (Exception $e) {
        $results['errors'][] = "Database error: " . $e->getMessage();
        error_log("Auto-assignment database error: " . $e->getMessage());
        return $results;
    }
}

/**
 * Send notification to instructor about auto-assigned reviews
 */
function notifyInstructor($assignment_id, $reviews_assigned) {
    global $conn;
    
    try {
        // Get assignment and instructor details
        $stmt = $conn->prepare("SELECT a.title, u.user_id, u.email, u.first_name 
                               FROM assignments a 
                               JOIN courses c ON a.course_id = c.course_id 
                               JOIN users u ON c.instructor_id = u.user_id 
                               WHERE a.assignment_id = ?");
        $stmt->execute([$assignment_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            // Create notification (you can extend this to send email)
            $notification_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id) 
                                               VALUES (?, ?, ?, 'peer_review', ?)");
            $message = "Peer reviews have been automatically assigned for assignment '{$data['title']}'. {$reviews_assigned} review assignments were created.";
            $notification_stmt->execute([$data['user_id'], 'Peer Reviews Assigned', $message, $assignment_id]);
        }
        
    } catch (Exception $e) {
        error_log("Notification error for assignment {$assignment_id}: " . $e->getMessage());
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    // Running from command line (cron)
    echo "Starting auto peer review assignment...\n";
    $results = processDueAssignments();
    
    echo "Processed: {$results['processed']} assignments\n";
    
    if (!empty($results['assignments'])) {
        foreach ($results['assignments'] as $assignment_id => $data) {
            echo "Assignment {$assignment_id} ({$data['title']}): {$data['status']}";
            if (isset($data['reviews_assigned'])) {
                echo " - {$data['reviews_assigned']} reviews assigned";
            }
            if (isset($data['reason'])) {
                echo " - {$data['reason']}";
            }
            echo "\n";
        }
    }
    
    if (!empty($results['errors'])) {
        echo "Errors:\n";
        foreach ($results['errors'] as $error) {
            echo "- $error\n";
        }
    }
    
    echo "Auto-assignment completed.\n";
    
} else {
    // Running via web browser (for manual testing)
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Auto Peer Review Assignment</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="container mt-4">
            <h1>Auto Peer Review Assignment</h1>
            <div class="card">
                <div class="card-body">
                    <?php
                    if ($_POST && isset($_POST['run_auto_assign'])) {
                        $results = processDueAssignments();
                        ?>
                        <div class="alert alert-success">
                            <h4>Auto-assignment Completed</h4>
                            <p>Processed: <strong><?php echo $results['processed']; ?></strong> assignments</p>
                        </div>
                        
                        <?php if (!empty($results['assignments'])): ?>
                        <h5>Assignment Details:</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Assignment</th>
                                        <th>Status</th>
                                        <th>Reviews Assigned</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results['assignments'] as $assignment_id => $data): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($data['title']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $data['status'] == 'success' ? 'success' : ($data['status'] == 'skipped' ? 'warning' : 'danger'); ?>">
                                                <?php echo ucfirst($data['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (isset($data['reviews_assigned'])): ?>
                                                <?php echo $data['reviews_assigned']; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (isset($data['reason'])): ?>
                                                <?php echo htmlspecialchars($data['reason']); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($results['errors'])): ?>
                        <div class="alert alert-danger mt-3">
                            <h5>Errors:</h5>
                            <ul>
                                <?php foreach ($results['errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        
                        <?php
                    } else {
                        ?>
                        <p>This script automatically assigns peer reviews for assignments after their due dates.</p>
                        <form method="POST">
                            <button type="submit" name="run_auto_assign" class="btn btn-primary">
                                Run Auto Assignment Now
                            </button>
                        </form>
                        <div class="mt-3 alert alert-info">
                            <small>
                                <strong>Note:</strong> In production, this should be run via cron job daily.
                                Example cron: <code>0 2 * * * /usr/bin/php <?php echo __FILE__; ?></code>
                            </small>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}