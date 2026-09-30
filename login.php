<?php
/**
 * Sign-in screen.
 */

require_once 'includes/bootstrap.php';

if ($auth->isLoggedIn()) {
    $auth->redirect('dashboard.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($auth->login($email, $password)) {
        // Return the visitor to whatever page sent them here, but only if it
        // is an in-app path: an absolute URL would turn this into an open
        // redirect right after authentication.
        $target = $_SESSION['redirect_after_login'] ?? null;
        unset($_SESSION['redirect_after_login']);

        if (is_string($target) && $target !== '' && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) && strpos($target, '//') !== 0) {
            if (!headers_sent()) {
                header('Location: ' . $target);
                exit;
            }
        }

        $auth->redirect('dashboard.php');
    }

    $error = $auth->getLastError() ?: 'Invalid email address or password.';
}

// Consume the one-shot notice set by Auth::logout().
$justLoggedOut = !empty($_SESSION['logged_out']);
unset($_SESSION['logged_out']);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Sign in &middot; e-Learning System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-4164ca6728e93c48c84afe5669153d385791a6893a61cb676260ebe69365c93d9b4635e1d093218d8ea15be00b130207"
          crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
          integrity="sha384-3cf21910660cd6ff33a793f2ed48c56fbf52e7c51ea822fda5856754f511284aaf8a83d139a54024a28bcef1f6ac39c8"
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?php echo e_attr(asset_url('css/app.css')); ?>">
</head>
<body>
<div class="auth-bg">
    <div class="container" style="max-width:26rem">
        <div class="text-center mb-4">
            <a class="app-brand justify-content-center" href="<?php echo e_attr(app_url('login.php')); ?>" style="color:#fff">
                <span class="app-brand__mark" style="background:rgba(255,255,255,.18);box-shadow:none">
                    <i class="fas fa-graduation-cap" aria-hidden="true"></i>
                </span>
                <span class="app-brand__text" style="color:#fff">
                    e-Learning
                    <small style="color:rgba(255,255,255,.7)">Peer Review Platform</small>
                </span>
            </a>
        </div>

        <div class="auth-card">
            <div class="auth-card__head">
                <span class="auth-mark"><i class="fas fa-right-to-bracket" aria-hidden="true"></i></span>
                <h1>Welcome back</h1>
                <p>Sign in to continue to your courses.</p>
            </div>

            <div class="auth-card__body">
                <?php if ($justLoggedOut): ?>
                    <div class="alert alert-success" role="status" data-autodismiss="7000">
                        <div class="alert-heading">
                            <i class="fas fa-circle-check" aria-hidden="true"></i> Signed out
                        </div>
                        You have been signed out safely.
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <div class="alert-heading">
                            <i class="fas fa-circle-exclamation" aria-hidden="true"></i> Could not sign in
                        </div>
                        <?php echo e($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email address</label>
                        <div class="input-icon">
                            <i class="fas fa-envelope" aria-hidden="true"></i>
                            <input type="email" class="form-control" id="email" name="email"
                                   required autocomplete="email" autofocus
                                   placeholder="you@university.edu"
                                   value="<?php echo e($email); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-icon">
                            <i class="fas fa-lock" aria-hidden="true"></i>
                            <input type="password" class="form-control" id="password" name="password"
                                   required autocomplete="current-password"
                                   placeholder="Your password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-arrow-right-to-bracket me-1" aria-hidden="true"></i> Sign in
                    </button>
                </form>

                <hr class="hr-soft">

                <p class="text-center text-muted mb-0" style="font-size:.8125rem">
                    New here?
                    <a href="<?php echo e_attr(app_url('register.php')); ?>">Create a student account</a>
                </p>
            </div>
        </div>

        <p class="text-center mt-3 mb-0" style="font-size:.75rem;color:rgba(255,255,255,.65)">
            Sessions expire after 8 hours of inactivity.
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-62fa5cad87f4b58de51c1eb434d9265dce6cf5f0d564b112680039e4d0f33b1872f4691c21db252b578decdea321e1f3"
        crossorigin="anonymous"></script>
<script src="<?php echo e_attr(asset_url('js/app.js')); ?>" defer></script>
</body>
</html>
