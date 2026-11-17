<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isAuth()
{
    return isset($_SESSION['user_id']);
}

function isAdmin()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isEmployee()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'employee';
}

function requireAuth()
{
    if (!isAuth()) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin()
{
    if (!isAdmin()) {
        header('Location: ../login.php');
        exit;
    }
}
?>