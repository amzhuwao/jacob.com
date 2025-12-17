<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /auth/login.php');
        exit();
    }
}

function getUserRole() {
    return $_SESSION['role'] ?? null;
}

function requireRole($role) {
    requireLogin();
    if (getUserRole() !== $role) {
        header('Location: /index.php');
        exit();
    }
}
?>
