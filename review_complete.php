<?php
require_once 'includes/bootstrap.php';

$query_string = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
$auth->redirect('student/review_complete.php' . $query_string);