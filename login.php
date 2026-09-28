<?php
require_once 'includes/bootstrap.php';

if ($auth->isLoggedIn()) {
    $auth->redirect('dashboard.php');
}

$error = '';
if ($_POST) {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($auth->login($email, $password)) {
        $auth->redirect('dashboard.php');
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Interactive e-Learning System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4338ca 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.25);
            border: none;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 2.5rem 2rem 2rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-5">
                <div class="card login-card">
                    <div class="login-header">
                        <i class="fas fa-graduation-cap fa-3x mb-2"></i>
                        <h2 class="h4 fw-bold mb-1">e-Learning & Peer Review</h2>
                        <p class="text-white-50 mb-0 small">Interactive Academic Assessment Platform</p>
                    </div>

                    <div class="card-body p-4 p-md-5">
                        <?php if(!empty($error)): ?>
                            <div class="alert alert-danger py-2 small mb-4">
                                <i class="fas fa-exclamation-circle me-1"></i> <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Standard Login Form -->
                        <form method="POST" id="loginForm">
                            <?php echo csrf_field(); ?>
                            <div class="mb-3">
                                <label for="email" class="form-label fw-bold small text-secondary">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="name@elearning.com" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <label for="password" class="form-label fw-bold small text-secondary">Password</label>
                                </div>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold mb-3 shadow-sm">
                                <i class="fas fa-sign-in-alt me-2"></i> Sign In
                            </button>
                            
                            <div class="text-center mb-4">
                                <a href="register.php" class="text-decoration-none small text-primary fw-bold">
                                    Don't have an account? Register here
                                </a>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>