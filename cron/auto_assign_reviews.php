<?php
/**
 * Auto Peer Review Assignment
 *
 * Assigns peer reviews automatically for assignments whose due date has passed.
 *
 * Two ways to run it:
 *
 * 1. From the server's own cron (preferred, if the host allows it):
 *      0 2 * * * /usr/bin/php /home/YOURUSER/htdocs/cron/auto_assign_reviews.php
 *
 * 2. From an external cron service (cron-job.org) over HTTPS, which is how
 *    shared hosting without shell cron usually works:
 *      https://YOURDOMAIN/cron/auto_assign_reviews.php?token=YOUR_CRON_TOKEN
 *
 * The web endpoint requires a secret token defined in config/local.php as
 * CRON_TOKEN. Without it the script refuses to run over HTTP, because this job
 * mutates the database and must not be triggerable by anonymous visitors.
 *
 * Note: it intentionally produces no HTML. Any internal error text is written
 * to the log file instead of being echoed to the caller.
 */

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/includes/bootstrap.php';

// This runs unattended, so never print errors to the response body. On a
// shared host display_errors can leak absolute paths and SQL fragments.
ini_set('display_errors', '0');
error_reporting(E_ALL);

/**
 * Decide whether this request is allowed to run the job.
 *
 * @return string|null Null when allowed, otherwise the reason it was refused.
 */
function cron_authorize() {
    // Running from the server's own cron: no token needed, the OS already
    // restricted who can execute it.
    if (php_sapi_name() === 'cli') {
        return null;
    }

    $configured = defined('CRON_TOKEN') ? trim((string) CRON_TOKEN) : '';
    $expected_default = 'change-me-to-a-long-random-string';

    if ($configured === '' || $configured === $expected_default) {
        error_log('cron/auto_assign_reviews: CRON_TOKEN is not configured in config/local.php, refusing HTTP request.');
        return 'This job is not available because CRON_TOKEN is not configured.';
    }

    $supplied = '';
    // Accept the token from the query string (cron-job.org cannot set headers)
    // or from a header, for services that allow custom headers.
    if (isset($_GET['token'])) {
        $supplied = trim((string) $_GET['token']);
    } elseif (isset($_SERVER['HTTP_X_CRON_TOKEN'])) {
        $supplied = trim((string) $_SERVER['HTTP_X_CRON_TOKEN']);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        // "Authorization: Bearer <token>"
        $supplied = trim((string) preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']));
    }

    // hash_equals avoids leaking the token through response timing.
    if ($supplied === '' || !hash_equals($configured, $supplied)) {
        error_log('cron/auto_assign_reviews: rejected request with missing or invalid token.');
        return 'Invalid or missing token.';
    }

    return null;
}

/**
 * Assign peer reviews for every published assignment that is past its due date
 * and does not yet have enough reviews.
 *
 * @return array
 */
function process_due_assignments() {
    $conn = $GLOBALS['db']->getConnection();

    $results = [
        'processed' => 0,
        'assignments' => [],
        'errors'    => [],
    ];

    try {
        // At least two submissions are needed before peer review makes sense:
        // with a single submission there is nobody to review it.
        $stmt = $conn->prepare("SELECT a.assignment_id, a.title, a.due_date,
                                       COUNT(DISTINCT s.submission_id) AS submission_count
                                FROM assignments a
                                JOIN submissions s ON s.assignment_id = a.assignment_id
                                WHERE a.due_date IS NOT NULL
                                  AND a.due_date < NOW()
                                  AND a.is_published = TRUE
                                GROUP BY a.assignment_id, a.title, a.due_date
                                HAVING submission_count >= 2");
        $stmt->execute();
        $due_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($due_assignments as $assignment) {
            $assignment_id = (int) $assignment['assignment_id'];

            try {
                // How many submissions already have at least one review?
                $review_stmt = $conn->prepare("SELECT COUNT(DISTINCT s.submission_id) AS with_reviews
                                               FROM submissions s
                                               JOIN peer_reviews pr ON pr.submission_id = s.submission_id
                                               WHERE s.assignment_id = ?");
                $review_stmt->execute([$assignment_id]);
                $with_reviews = (int) $review_stmt->fetchColumn();
                $total = (int) $assignment['submission_count'];

                if ($with_reviews >= (int) ceil($total * 0.5)) {
                    $results['assignments'][$assignment_id] = [
                        'title'      => $assignment['title'],
                        'status'     => 'skipped',
                        'reason'     => 'Enough submissions already have reviews',
                    ];
                    continue;
                }

                $assigned = $GLOBALS['functions']->assignPeerReviews($assignment_id, 2);

                if ($assigned === false) {
                    $results['errors'][] = 'Could not assign reviews for assignment #' . $assignment_id;
                    $results['assignments'][$assignment_id] = [
                        'title'  => $assignment['title'],
                        'status' => 'failed',
                    ];
                    continue;
                }

                $results['processed']++;
                $results['assignments'][$assignment_id] = [
                    'title'            => $assignment['title'],
                    'status'           => 'success',
                    'reviews_assigned' => $assigned,
                ];

                error_log(sprintf(
                    'cron/auto_assign_reviews: assigned %d reviews for assignment #%d (%s)',
                    $assigned,
                    $assignment_id,
                    $assignment['title']
                ));
            } catch (Exception $e) {
                $results['errors'][] = 'Assignment #' . $assignment_id . ': ' . $e->getMessage();
                error_log('cron/auto_assign_reviews: ' . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        $results['errors'][] = 'Database error: ' . $e->getMessage();
        error_log('cron/auto_assign_reviews: database error: ' . $e->getMessage());
    }

    return $results;
}

$denied = cron_authorize();

if ($denied !== null) {
    if (php_sapi_name() !== 'cli') {
        // 403 rather than 404: the operator needs to see that the job exists
        // but was refused, and the reason is safe to display.
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $denied . "\n";
    exit(1);
}

$results = process_due_assignments();

if (php_sapi_name() === 'cli') {
    echo "Auto peer review assignment finished.\n";
    echo 'Processed: ' . $results['processed'] . " assignment(s)\n";
    foreach ($results['assignments'] as $assignment_id => $data) {
        echo '  #' . $assignment_id . ' ' . $data['title'] . ' -> ' . $data['status'];
        if (isset($data['reviews_assigned'])) {
            echo ' (' . $data['reviews_assigned'] . ' reviews)';
        }
        if (isset($data['reason'])) {
            echo ' - ' . $data['reason'];
        }
        echo "\n";
    }
    foreach ($results['errors'] as $error) {
        echo '  ERROR: ' . $error . "\n";
    }
    exit(empty($results['errors']) ? 0 : 1);
}

// Plain-text success response for the external cron service. Deliberately
// minimal: no HTML, no absolute paths, no database detail.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "OK - processed {$results['processed']} assignment(s)\n";
foreach ($results['errors'] as $error) {
    echo "ERROR: {$error}\n";
}
