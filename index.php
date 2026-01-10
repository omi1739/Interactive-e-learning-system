<?php
require_once 'includes/bootstrap.php';

if($auth->isLoggedIn()) {
    $auth->redirect('dashboard.php');
} else {
    $auth->redirect('login.php');
}
?>