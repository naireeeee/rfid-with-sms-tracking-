<?php
date_default_timezone_set('Asia/Manila');
$conn = new mysqli("localhost", "root", "", "rfid_db");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rfid_uid'])) {

    $uid = strtoupper(trim($_POST['rfid_uid']));

    // ✅ FIX: 2 TIME VARIABLES
    $nowDisplay = date('h:i A');   // pang display lang
    $now = date('H:i');            // pang logic
    $today = date('Y-m-d');
    $currentTime = date('Y-m-d H:i:s');

    /* =========================
       FIND STUDENT VIA RFID
    ========================= */
    $stmt = $conn->prepare("
        SELECT student_name 
        FROM parents 
        WHERE UPPER(REPLACE(rfid_uid, ' ', '')) = UPPER(REPLACE(?, ' ', '')) 
        AND is_deleted = 0
    ");
    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $user_res = $stmt->get_result();

    if ($row = $user_res->fetch_assoc()) {

        $name = $row['student_name'];

        /* =========================
           FIND TODAY RECORD (SAFE)
        ========================= */
        $check = $conn->prepare("
            SELECT * FROM attendance 
            WHERE student_name = ? 
            AND DATE(COALESCE(`time in`, `pm_in`)) = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $check->bind_param("ss", $name, $today);
        $check->execute();
        $attendance_record = $check->get_result()->fetch_assoc();

        /* =========================
           MORNING IN
        ========================= */
        if ($now >= '06:00' && $now < '12:00') {

            if ($attendance_record && !empty($attendance_record['time in'])) {
                echo "Error: May TIME IN ka na.";
            } else {

                if ($now >= '06:00' && $now <= '07:30') {
                    $status = "Present";
                    $late = "None";
                    $absent = "None";
                } elseif ($now <= '08:30') {
                    $status = "Late";
                    $late = "Late";
                    $absent = "None";
                } else {
                    $status = "Absent";
                    $late = "None";
                    $absent = "Absent";
                }

                $ins = $conn->prepare("
                    INSERT INTO attendance 
                    (student_name, `time in`, present, absent, late) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $ins->bind_param("sssss", $name, $currentTime, $status, $absent, $late);

                echo $ins->execute()
                    ? "IN: $status - $name ($nowDisplay)"
                    : "Insert Error";
            }
        }

        /* =========================
           MORNING OUT
        ========================= */
        elseif ($now >= '12:00' && $now < '13:00') {

            if ($attendance_record) {

                $upd = $conn->prepare("
                    UPDATE attendance 
                    SET `time out` = ? 
                    WHERE id = ?
                ");
                $upd->bind_param("si", $currentTime, $attendance_record['id']);

                echo $upd->execute()
                    ? "OUT: Success - $name ($nowDisplay)"
                    : "Update Error";

            } else {
                echo "Error: Walang TIME IN record.";
            }
        }

        /* =========================
           AFTERNOON IN
        ========================= */
        elseif ($now >= '13:00' && $now < '16:30') {

            if ($now <= '13:30') {
                $status = "Present";
                $late = "None";
                $absent = "None";
            } elseif ($now <= '14:00') {
                $status = "Late";
                $late = "Late";
                $absent = "None";
            } else {
                $status = "Absent";
                $late = "None";
                $absent = "Absent";
            }

            if ($attendance_record) {

                $upd = $conn->prepare("
                    UPDATE attendance 
                    SET `pm_in` = ?, late = ?, present = ?, absent = ?
                    WHERE id = ?
                ");
                $upd->bind_param("ssssi", $currentTime, $late, $status, $absent, $attendance_record['id']);

                echo $upd->execute()
                    ? "PM IN: $status - $name ($nowDisplay)"
                    : "PM IN Error";

            } else {

                $ins = $conn->prepare("
                    INSERT INTO attendance 
                    (student_name, `pm_in`, present, absent, late, `time in`) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param("ssssss", $name, $currentTime, $status, $absent, $late, $currentTime);

                echo $ins->execute()
                    ? "PM IN: $status - $name ($nowDisplay)"
                    : "PM IN Insert Error";
            }
        }

        /* =========================
           AFTERNOON OUT (FIXED)
        ========================= */
        elseif ($now >= '16:30' && $now < '20:00') {

            $check2 = $conn->prepare("
                SELECT * FROM attendance 
                WHERE student_name = ? 
                AND DATE(COALESCE(`time in`, `pm_in`)) = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $check2->bind_param("ss", $name, $today);
            $check2->execute();
            $attendance_record = $check2->get_result()->fetch_assoc();

            if ($attendance_record) {

                $upd = $conn->prepare("
                    UPDATE attendance 
                    SET `pm_out` = ? 
                    WHERE id = ?
                ");
                $upd->bind_param("si", $currentTime, $attendance_record['id']);

                echo $upd->execute()
                    ? "PM OUT: Success - $name ($nowDisplay)"
                    : "PM OUT Error";

            } else {
                echo "Error: Walang attendance record today (IN muna bago OUT).";
            }
        }

        else {
            echo "System Closed. Time: $nowDisplay";
        }

    } else {
        echo "Error: RFID not registered.";
    }
}



$conn->close();
?>