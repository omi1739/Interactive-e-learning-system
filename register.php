<?php
/**
 * Student self-service registration.
 *
 * There is intentionally no role field on this form. Public sign-up can only
 * ever create a student account; instructor and admin accounts are provisioned
 * out of band. See Auth::PUBLIC_REGISTRATION_ROLE.
 */

require_once 'includes/bootstrap.php';

if ($auth->isLoggedIn()) {
    $auth->redirect('dashboard.php');
}

$errors = [];
$old = [
    'first_name'  => '',
    'last_name'   => '',
    'username'    => '',
    'email'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach (array_keys($old) as $field) {
        $old[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    // Password rules are mirrored here so the visitor gets inline feedback
    // before a round trip. Auth re-validates everything regardless, because
    // client-side checks are a convenience, never a control.
    if ($password !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }
    if (mb_strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    } elseif (preg_match('/^(.)\1+$/', $password)) {
        $errors['password'] = 'Avoid using a single repeated character.';
    }
    if (empty($old['username']) && empty($errors['username'])) {
        $errors['username'] = 'Choose a username.';
    }
    foreach ($old as $field => $value) {
        if ($value === '' && empty($errors[$field])) {
            $errors[$field] = 'This field is required.';
        }
    }

    if (!$errors) {
        // No role argument: the role is not a form field, it is a constant.
        if ($auth->register(
            $old['username'],
            $old['email'],
            $password,
            $old['first_name'],
            $old['last_name']
        )) {
            flash_success('Account created. Sign in to get started.');
            $auth->redirect('login.php');
        }

        // The reason comes from the model so there is a single source of truth
        // for why registration was rejected.
        $message = $auth->getLastError() ?: 'Registration failed. Please try again.';

        // Map the message back onto the field that caused it where possible.
        if (stripos($message, 'username') !== false || stripos($message, 'email') !== false) {
            if (stripos($message, 'username') !== false) {
                $errors['username'] = $message;
            } else {
                $errors['email'] = $message;
            }
        } elseif (stripos($message, 'password') !== false) {
            $errors['password'] = $message;
        } else {
            $errors['form'] = $message;
        }
    }
}

/**
 * Renders a Bootstrap validation class for a field.
 */
function reg_invalid($errors, $field) {
    return isset($errors[$field]) ? ' is-invalid' : '';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Create your account &middot; e-Learning System</title>
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
    <div class="container" style="max-width:30rem">
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
                <span class="auth-mark"><i class="fas fa-user-plus" aria-hidden="true"></i></span>
                <h1>Create your account</h1>
                <p>Join as a student to submit work and review classmates.</p>
            </div>

            <div class="auth-card__body">
                <?php if (!empty($errors['form'])): ?>
                    <div class="alert alert-danger" role="alert">
                        <div class="alert-heading">
                            <i class="fas fa-circle-exclamation" aria-hidden="true"></i> Registration failed
                        </div>
                        <?php echo e($errors['form']); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label for="first_name" class="form-label">First name <span class="req" aria-hidden="true">*</span></label>
                            <input type="text" class="form-control<?php echo reg_invalid($errors, 'first_name'); ?>"
                                   id="first_name" name="first_name" required maxlength="100"
                                   autocomplete="given-name" value="<?php echo e($old['first_name']); ?>">
                        </div>
                        <div class="col-sm-6">
                            <label for="last_name" class="form-label">Last name <span class="req" aria-hidden="true">*</span></label>
                            <input type="text" class="form-control<?php echo reg_invalid($errors, 'last_name'); ?>"
                                   id="last_name" name="last_name" required maxlength="100"
                                   autocomplete="family-name" value="<?php echo e($old['last_name']); ?>">
                        </div>

                        <div class="col-12">
                            <label for="username" class="form-label">Username <span class="req" aria-hidden="true">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">@</span>
                                <input type="text" class="form-control<?php echo reg_invalid($errors, 'username'); ?>"
                                       id="username" name="username" required
                                       minlength="3" maxlength="20" pattern="[A-Za-z0-9_]{3,20}"
                                       autocomplete="username" value="<?php echo e($old['username']); ?>"
                                       aria-describedby="usernameHelp">
                            </div>
                            <div class="form-text" id="usernameHelp">
                                3&ndash;20 characters: letters, numbers and underscores.
                            </div>
                        </div>

                        <div class="col-12">
                            <label for="email" class="form-label">Email address <span class="req" aria-hidden="true">*</span></label>
                            <input type="email" class="form-control<?php echo reg_invalid($errors, 'email'); ?>"
                                   id="email" name="email" required maxlength="190"
                                   autocomplete="email" value="<?php echo e($old['email']); ?>"
                                   aria-describedby="emailHelp">
                            <div class="form-text" id="emailHelp">Used to sign in and receive course updates.</div>
                        </div>

                        <div class="col-sm-6">
                            <label for="password" class="form-label">Password <span class="req" aria-hidden="true">*</span></label>
                            <div class="input-icon">
                                <i class="fas fa-lock" aria-hidden="true"></i>
                                <input type="password" class="form-control<?php echo reg_invalid($errors, 'password'); ?>"
                                       id="password" name="password" required minlength="8"
                                       autocomplete="new-password" aria-describedby="passwordHelp">
                            </div>
                            <div class="form-text" id="passwordHelp">At least 8 characters.</div>
                        </div>

                        <div class="col-sm-6">
                            <label for="confirm_password" class="form-label">Confirm password <span class="req" aria-hidden="true">*</span></label>
                            <div class="input-icon">
                                <i class="fas fa-lock" aria-hidden="true"></i>
                                <input type="password" class="form-control<?php echo reg_invalid($errors, 'confirm_password'); ?>"
                                       id="confirm_password" name="confirm_password" required
                                       autocomplete="new-password">
                            </div>
                        </div>
                    </div>

                    <div class="notice-inline mt-3">
                        <i class="fas fa-circle-info" aria-hidden="true"></i>
                        <span>
                            New accounts are created with the <strong>Student</strong> role.
                            Teaching staff accounts are set up by a site administrator.
                        </span>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 mt-3">
                        <i class="fas fa-arrow-right-to-bracket me-1" aria-hidden="true"></i> Create account
                    </button>
                </form>

                <p class="text-center text-muted mt-3 mb-0" style="font-size:.8125rem">
                    Already registered?
                    <a href="<?php echo e_attr(app_url('login.php')); ?>">Sign in instead</a>
                </p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-62fa5cad87f4b58de51c1eb434d9265dce6cf5f0d564b112680039e4d0f33b1872f4691c21db252b578decdea321e1f3"
        crossorigin="anonymous"></script>
<script src="<?php echo e_attr(asset_url('js/app.js')); ?>" defer></script>
</body>
</html>
