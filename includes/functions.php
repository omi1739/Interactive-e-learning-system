<?php
class Functions {
    private $db;
    private $conn;

    public function __construct($database) {
        $this->db = $database;
        $this->conn = $this->db->getConnection();
    }

    // Get courses (for instructor or all courses)
    public function getCourses($instructor_id = null) {
        try {
            $query = "SELECT c.*, u.first_name, u.last_name 
                      FROM courses c 
                      JOIN users u ON c.instructor_id = u.user_id 
                      WHERE 1=1";
            
            if($instructor_id) {
                $query .= " AND c.instructor_id = :instructor_id";
            }
            
            $query .= " ORDER BY c.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if($instructor_id) {
                $stmt->bindParam(":instructor_id", $instructor_id);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getCourses error: " . $e->getMessage());
            return [];
        }
    }

    // Get enrolled courses for student - FIXED: Added ORDER BY
    public function getEnrolledCourses($user_id) {
        try {
            $query = "SELECT c.*, u.first_name, u.last_name, e.enrollment_status, e.enrolled_at, e.grade
                      FROM enrollments e 
                      JOIN courses c ON e.course_id = c.course_id 
                      JOIN users u ON c.instructor_id = u.user_id 
                      WHERE e.user_id = :user_id
                      ORDER BY e.enrolled_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $user_id);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getEnrolledCourses error: " . $e->getMessage());
            return [];
        }
    }

    // Enroll student in course
    public function enrollInCourse($user_id, $course_id) {
        try {
            // Check if already enrolled
            $check_stmt = $this->conn->prepare("SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?");
            $check_stmt->execute([$user_id, $course_id]);
            
            if($check_stmt->rowCount() > 0) {
                return false;
            }
            
            $query = "INSERT INTO enrollments (user_id, course_id, enrollment_status) 
                      VALUES (:user_id, :course_id, 'pending')";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $user_id);
            $stmt->bindParam(":course_id", $course_id);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("enrollInCourse error: " . $e->getMessage());
            return false;
        }
    }

    // Get forums for a course
    public function getCourseForums($course_id) {
        try {
            $query = "SELECT f.*, 
                         COUNT(DISTINCT fp.post_id) as post_count,
                         MAX(fp.created_at) as last_activity
                  FROM forums f
                  LEFT JOIN forum_posts fp ON f.forum_id = fp.forum_id
                  WHERE f.course_id = :course_id
                  GROUP BY f.forum_id
                  ORDER BY f.title";
        
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":course_id", $course_id);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getCourseForums error: " . $e->getMessage());
            return [];
        }
    }

    // Get forum posts with pagination
    public function getForumPosts($forum_id, $limit = 20, $offset = 0) {
        try {
            $query = "SELECT fp.*, u.first_name, u.last_name, u.username,
                         (SELECT COUNT(*) FROM forum_posts fp2 WHERE fp2.parent_post_id = fp.post_id) as reply_count
                  FROM forum_posts fp
                  JOIN users u ON fp.user_id = u.user_id
                  WHERE fp.forum_id = :forum_id AND fp.parent_post_id IS NULL
                  ORDER BY fp.is_pinned DESC, fp.created_at DESC
                  LIMIT :limit OFFSET :offset";
        
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":forum_id", $forum_id);
            $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getForumPosts error: " . $e->getMessage());
            return [];
        }
    }




    // Auto-assign peer reviews for an assignment
public function assignPeerReviews($assignment_id, $reviews_per_submission = 2) {
    try {
        $conn = $this->conn;
        
        // Begin transaction
        $conn->beginTransaction();
        
        // Get all submissions for this assignment
        $stmt = $conn->prepare("SELECT submission_id, student_id FROM submissions WHERE assignment_id = ?");
        $stmt->execute([$assignment_id]);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if(count($submissions) < 2) {
            throw new Exception("Need at least 2 submissions for peer review assignment");
        }
        
        // Get all students who submitted (for exclusion)
        $submitting_students = array_column($submissions, 'student_id');
        
        // Get all enrolled students in the course
        $stmt = $conn->prepare("SELECT DISTINCT u.user_id 
                               FROM users u 
                               JOIN enrollments e ON u.user_id = e.user_id 
                               JOIN assignments a ON a.assignment_id = ?
                               JOIN modules m ON a.module_id = m.module_id 
                               WHERE e.course_id = m.course_id 
                               AND e.enrollment_status = 'approved'");
        $stmt->execute([$assignment_id]);
        $all_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $potential_reviewers = array_diff($all_students, $submitting_students);
        
        $assignments_made = 0;
        
        foreach($submissions as $submission) {
            $submission_id = $submission['submission_id'];
            $author_id = $submission['student_id'];
            
            // Get current reviews for this submission to avoid duplicates
            $stmt = $conn->prepare("SELECT reviewer_id FROM peer_reviews WHERE submission_id = ?");
            $stmt->execute([$submission_id]);
            $current_reviewers = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Available reviewers (not author, not already assigned)
            $available_reviewers = array_diff($potential_reviewers, [$author_id], $current_reviewers);
            
            // Shuffle to randomize assignment
            shuffle($available_reviewers);
            
            // Assign required number of reviews
            $reviewers_to_assign = array_slice($available_reviewers, 0, $reviews_per_submission);
            
            foreach($reviewers_to_assign as $reviewer_id) {
                $stmt = $conn->prepare("INSERT INTO peer_reviews (submission_id, reviewer_id, status) VALUES (?, ?, 'in_progress')");
                $stmt->execute([$submission_id, $reviewer_id]);
                $assignments_made++;
            }
        }
        
        $conn->commit();
        return $assignments_made;
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("assignPeerReviews error: " . $e->getMessage());
        return false;
    }
}

// Get assignment details for auto-assignment
public function getAssignmentForReview($assignment_id) {
    try {
        $stmt = $this->conn->prepare("SELECT a.*, m.course_id, 
                                     (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.assignment_id) as submission_count,
                                     (SELECT COUNT(*) FROM peer_reviews pr 
                                      JOIN submissions s ON pr.submission_id = s.submission_id 
                                      WHERE s.assignment_id = a.assignment_id) as review_count
                              FROM assignments a
                              JOIN modules m ON a.module_id = m.module_id
                              WHERE a.assignment_id = ?");
        $stmt->execute([$assignment_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getAssignmentForReview error: " . $e->getMessage());
        return null;
    }
}





    // Create new forum post
    public function createForumPost($forum_id, $user_id, $title, $content, $parent_post_id = null) {
        try {
            $query = "INSERT INTO forum_posts (forum_id, user_id, parent_post_id, title, content) 
                  VALUES (:forum_id, :user_id, :parent_post_id, :title, :content)";
        
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":forum_id", $forum_id);
            $stmt->bindParam(":user_id", $user_id);
            $stmt->bindParam(":parent_post_id", $parent_post_id);
            $stmt->bindParam(":title", $title);
            $stmt->bindParam(":content", $content);
        
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("createForumPost error: " . $e->getMessage());
            return false;
        }
    }

    // Get students for a course (for instructor)
    public function getCourseStudents($course_id) {
        try {
            $query = "SELECT u.user_id, u.first_name, u.last_name, u.email, u.username, 
                             e.enrollment_status, e.enrolled_at, e.grade
                      FROM enrollments e 
                      JOIN users u ON e.user_id = u.user_id 
                      WHERE e.course_id = :course_id
                      ORDER BY e.enrolled_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":course_id", $course_id);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getCourseStudents error: " . $e->getMessage());
            return [];
        }
    }

    // Update enrollment status
    public function updateEnrollmentStatus($user_id, $course_id, $status) {
        try {
            $query = "UPDATE enrollments SET enrollment_status = :status WHERE user_id = :user_id AND course_id = :course_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":user_id", $user_id);
            $stmt->bindParam(":course_id", $course_id);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("updateEnrollmentStatus error: " . $e->getMessage());
            return false;
        }
    }

    // Create new course - FIXED: Set is_published = TRUE by default
    public function createCourse($instructor_id, $title, $description, $course_code) {
        try {
            $query = "INSERT INTO courses (instructor_id, title, description, course_code, is_published) 
                      VALUES (:instructor_id, :title, :description, :course_code, TRUE)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":instructor_id", $instructor_id);
            $stmt->bindParam(":title", $title);
            $stmt->bindParam(":description", $description);
            $stmt->bindParam(":course_code", $course_code);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("createCourse error: " . $e->getMessage());
            return false;
        }
    }

    // Get user profile
    public function getUserProfile($user_id) {
        try {
            $query = "SELECT user_id, username, email, first_name, last_name, role, profile_picture, created_at 
                      FROM users WHERE user_id = :user_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $user_id);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getUserProfile error: " . $e->getMessage());
            return null;
        }
    }

    // Update user profile
    public function updateUserProfile($user_id, $first_name, $last_name, $email) {
        try {
            $query = "UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email 
                      WHERE user_id = :user_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":first_name", $first_name);
            $stmt->bindParam(":last_name", $last_name);
            $stmt->bindParam(":email", $email);
            $stmt->bindParam(":user_id", $user_id);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("updateUserProfile error: " . $e->getMessage());
            return false;
        }
    }

    // Get assignments for student
// In functions.php - Update the getStudentAssignments function
public function getStudentAssignments($user_id) {
    try {
        $query = "SELECT a.*, m.title as module_title, c.title as course_title, c.course_id,
                         s.submission_id, s.status as submission_status, s.final_grade, s.instructor_feedback,
                         s.submission_date, s.file_path, s.file_name
                  FROM assignments a
                  JOIN modules m ON a.module_id = m.module_id
                  JOIN courses c ON m.course_id = c.course_id
                  JOIN enrollments e ON c.course_id = e.course_id
                  LEFT JOIN submissions s ON a.assignment_id = s.assignment_id AND s.student_id = :user_id
                  WHERE e.user_id = :user_id 
                  AND e.enrollment_status = 'approved' 
                  AND a.is_published = TRUE
                  ORDER BY a.due_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getStudentAssignments error: " . $e->getMessage());
        return [];
    }
}

    // Get course by ID
    public function getCourse($course_id) {
        try {
            $query = "SELECT c.*, u.first_name, u.last_name 
                      FROM courses c 
                      JOIN users u ON c.instructor_id = u.user_id 
                      WHERE c.course_id = :course_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":course_id", $course_id);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getCourse error: " . $e->getMessage());
            return null;
        }
    }

    // Check if user is enrolled in course
    public function isEnrolled($user_id, $course_id) {
        try {
            $query = "SELECT * FROM enrollments 
                      WHERE user_id = :user_id AND course_id = :course_id AND enrollment_status = 'approved'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $user_id);
            $stmt->bindParam(":course_id", $course_id);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("isEnrolled error: " . $e->getMessage());
            return false;
        }
    }

    // Get forum by ID
    public function getForum($forum_id) {
        try {
            $query = "SELECT f.*, c.title as course_title 
                      FROM forums f 
                      JOIN courses c ON f.course_id = c.course_id 
                      WHERE f.forum_id = :forum_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":forum_id", $forum_id);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getForum error: " . $e->getMessage());
            return null;
        }
    }

    // Get available courses for enrollment - FIXED: Added ORDER BY
    public function getAvailableCourses($user_id) {
        try {
            $query = "SELECT c.*, u.first_name, u.last_name 
                      FROM courses c 
                      JOIN users u ON c.instructor_id = u.user_id 
                      WHERE c.is_published = TRUE 
                      AND c.course_id NOT IN (
                          SELECT course_id FROM enrollments WHERE user_id = :user_id
                      )
                      ORDER BY c.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $user_id);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getAvailableCourses error: " . $e->getMessage());
            return [];
        }
    }

    // Get total users count (for admin dashboard)
    public function getTotalUsers() {
        try {
            $query = "SELECT COUNT(*) as total FROM users";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['total'] ?? 0;
        } catch (PDOException $e) {
            error_log("getTotalUsers error: " . $e->getMessage());
            return 0;
        }
    }

    // Get total courses count (for admin dashboard)  
    public function getTotalCourses() {
        try {
            $query = "SELECT COUNT(*) as total FROM courses";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['total'] ?? 0;
        } catch (PDOException $e) {
            error_log("getTotalCourses error: " . $e->getMessage());
            return 0;
        }
    }

    // Get pending enrollments count (for admin dashboard)
    public function getPendingEnrollments() {
        try {
            $query = "SELECT COUNT(*) as total FROM enrollments WHERE enrollment_status = 'pending'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['total'] ?? 0;
        } catch (PDOException $e) {
            error_log("getPendingEnrollments error: " . $e->getMessage());
            return 0;
        }
    }

    // Get all users (for admin user management)
    public function getAllUsers() {
        try {
            $query = "SELECT user_id, username, email, first_name, last_name, role, is_active, created_at 
                  FROM users ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getAllUsers error: " . $e->getMessage());
            return [];
        }
    }

    // NEW: Get modules for a course
    public function getCourseModules($course_id) {
        try {
            $query = "SELECT * FROM modules WHERE course_id = :course_id AND is_published = TRUE ORDER BY module_order";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":course_id", $course_id);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getCourseModules error: " . $e->getMessage());
            return [];
        }
    }

    // NEW: Get assignments for a course
    public function getCourseAssignments($course_id) {
        try {
            $query = "SELECT a.*, m.title as module_title 
                      FROM assignments a
                      JOIN modules m ON a.module_id = m.module_id
                      WHERE m.course_id = :course_id AND a.is_published = TRUE
                      ORDER BY a.due_date";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":course_id", $course_id);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getCourseAssignments error: " . $e->getMessage());
            return [];
        }
    }

    // NEW: Get submission for a specific assignment and student
    public function getStudentSubmission($assignment_id, $student_id) {
        try {
            $query = "SELECT * FROM submissions WHERE assignment_id = :assignment_id AND student_id = :student_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":assignment_id", $assignment_id);
            $stmt->bindParam(":student_id", $student_id);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getStudentSubmission error: " . $e->getMessage());
            return null;
        }
    }

    // NEW: Create a new module
    public function createModule($course_id, $title, $description, $module_order, $is_published = true) {
        try {
            $query = "INSERT INTO modules (course_id, title, description, module_order, is_published) 
                      VALUES (:course_id, :title, :description, :module_order, :is_published)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":course_id", $course_id);
            $stmt->bindParam(":title", $title);
            $stmt->bindParam(":description", $description);
            $stmt->bindParam(":module_order", $module_order);
            $stmt->bindParam(":is_published", $is_published);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("createModule error: " . $e->getMessage());
            return false;
        }
    }

    // NEW: Create a new assignment
    public function createAssignment($module_id, $title, $description, $assignment_type, $max_points, $due_date = null, $submission_format = 'file', $max_file_size = 10, $allowed_file_types = '') {
        try {
            $query = "INSERT INTO assignments (module_id, title, description, assignment_type, max_points, due_date, submission_format, max_file_size, allowed_file_types, is_published) 
                      VALUES (:module_id, :title, :description, :assignment_type, :max_points, :due_date, :submission_format, :max_file_size, :allowed_file_types, TRUE)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":module_id", $module_id);
            $stmt->bindParam(":title", $title);
            $stmt->bindParam(":description", $description);
            $stmt->bindParam(":assignment_type", $assignment_type);
            $stmt->bindParam(":max_points", $max_points);
            $stmt->bindParam(":due_date", $due_date);
            $stmt->bindParam(":submission_format", $submission_format);
            $stmt->bindParam(":max_file_size", $max_file_size);
            $stmt->bindParam(":allowed_file_types", $allowed_file_types);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("createAssignment error: " . $e->getMessage());
            return false;
        }
    }

    // NEW: Submit assignment
    public function submitAssignment($assignment_id, $student_id, $submission_text = null, $file_path = null, $file_name = null) {
        try {
            // Check if submission already exists
            $existing = $this->getStudentSubmission($assignment_id, $student_id);
            
            if ($existing) {
                // Update existing submission
                $query = "UPDATE submissions SET submission_text = :submission_text, file_path = :file_path, file_name = :file_name, submission_date = NOW(), status = 'submitted' WHERE submission_id = :submission_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":submission_text", $submission_text);
                $stmt->bindParam(":file_path", $file_path);
                $stmt->bindParam(":file_name", $file_name);
                $stmt->bindParam(":submission_id", $existing['submission_id']);
            } else {
                // Create new submission
                $query = "INSERT INTO submissions (assignment_id, student_id, submission_text, file_path, file_name, status) VALUES (:assignment_id, :student_id, :submission_text, :file_path, :file_name, 'submitted')";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":assignment_id", $assignment_id);
                $stmt->bindParam(":student_id", $student_id);
                $stmt->bindParam(":submission_text", $submission_text);
                $stmt->bindParam(":file_path", $file_path);
                $stmt->bindParam(":file_name", $file_name);
            }
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("submitAssignment error: " . $e->getMessage());
            return false;
        }
    }
}
?>