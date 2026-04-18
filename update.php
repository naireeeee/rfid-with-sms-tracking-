<?php
session_start();

// Database connection
$conn = new mysqli("localhost", "root", "", "rfid_db");

if ($conn->connect_error) {
    die("error");
}

// Kunin ang pinaka-latest na attendance ID mula sa database
$result = $conn->query("SELECT id FROM attendance ORDER BY id DESC LIMIT 1");

if ($row = $result->fetch_assoc()) {
    $currentLatestId = $row['id'];

    // Kung wala pang 'last_id' sa session, i-set muna natin ito
    if (!isset($_SESSION['last_id'])) {
        $_SESSION['last_id'] = $currentLatestId;
        echo "wait"; 
    } 
    // Kapag ang ID sa DB ay mas malaki sa huling alam ng Session, may nag-tap!
    else if ($currentLatestId > $_SESSION['last_id']) {
        $_SESSION['last_id'] = $currentLatestId; // I-update ang session para sa susunod
        echo "refresh"; 
    } 
    else {
        echo "wait";
    }
} else {
    echo "wait";
}

$conn->close();
?>