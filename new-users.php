<?php
session_start();
$conn = new mysqli("localhost", "root", "", "rfid_db");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username']);
    $pass = $_POST['password']; // Ito yung tinype ng user sa form

    // 1. Hanapin muna ang user gamit lang ang USERNAME
    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // 2. DITO ANG MAGIC: I-compare ang tinype na password sa HASHED password sa DB
        if (password_verify($pass, $row['password'])) {
            // TAMA ANG PASSWORD!
            $_SESSION['user'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            header("Location: dashboard.php");
            exit();
        } else {
            // Maling password ang nilagay
            $_SESSION['error'] = "Invalid Password!";
            header("Location: index.php");
            exit();
        }
    } else {
        // Hindi mahanap ang username
        $_SESSION['error'] = "User not found!";
        header("Location: index.php");
        exit();
    }
}
?>