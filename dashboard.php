<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

/* DATABASE CONNECTION */
$conn = new mysqli("localhost", "root", "", "rfid_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Kunin ang Role at Section ng User mula sa Session
$current_user = $_SESSION['user'];
$current_role = $_SESSION['role'] ?? 'Staff'; 

// Kunin ang assignment mula sa session
$user_grade   = $_SESSION['grade_level'] ?? 'N/A'; 
$user_section = $_SESSION['section'] ?? 'N/A'; 

// Filter Logic: Kung Admin, lahat makikita. Kung Staff, yung assigned lang.
if ($current_role === 'Admin') {
    $roleFilter = ""; 
} else {
    $roleFilter = " AND p.grade_level = '$user_grade' AND p.section = '$user_section'";
}

// Siguraduhin na ang database ay updated
$conn->query("ALTER TABLE parents ADD COLUMN IF NOT EXISTS lrn VARCHAR(20) AFTER student_name");
$conn->query("ALTER TABLE parents ADD COLUMN IF NOT EXISTS grade_level VARCHAR(20) AFTER lrn");
$conn->query("ALTER TABLE parents ADD COLUMN IF NOT EXISTS section VARCHAR(50) AFTER grade_level");
$conn->query("ALTER TABLE parents ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0");

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS grade_level VARCHAR(20) AFTER role");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS section VARCHAR(50) AFTER grade_level");

$schoolName = "Jaen National High School";
$message = "";

/* --- LOG ACTIVITY FUNCTION --- */
function logActivity($conn, $action, $details = '') {
    $user_type = $_SESSION['role'] ?? 'Admin';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_type, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $user_type, $action, $details);
    $stmt->execute();
    $stmt->close();
}

/* --- HANDLE ADD NEW USER (ADMIN ONLY) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_user']) && $current_role === 'Admin') {
    $new_user = trim($_POST['new_username']);
    $new_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $role = $_POST['user_role'];
    $grade = $_POST['grade_level'] ?? '';
    $section = $_POST['user_section'] ?? '';

    $stmt = $conn->prepare("INSERT INTO users (username, password, role, grade_level, section) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $new_user, $new_pass, $role, $grade, $section);

    if ($stmt->execute()) {
        logActivity($conn, "Added new system user", "Username: $new_user, Role: $role, Grade: $grade, Sec: $section");
        $_SESSION['action_success'] = "New user account created successfully!";
        header("Location: dashboard.php?section=settings");
        exit();
    } else {
        $message = "Error: Username might already exist.";
    }
    $stmt->close();
}

/* --- PRINT LOGIC --- */
if (isset($_GET['print_report'])) {
    $type = $_GET['print_type'];
    $filter_section = !empty($_GET['filter_section']) ? $conn->real_escape_string($_GET['filter_section']) : '';
    $filter_grade = !empty($_GET['filter_grade']) ? $conn->real_escape_string($_GET['filter_grade']) : '';
    
    $where_clause = " WHERE 1=1";
    $report_title = "";

    if ($current_role !== 'Admin') {
        $where_clause .= " AND p.grade_level = '$user_grade' AND p.section = '$user_section'";
    } else {
        if ($filter_section !== '') $where_clause .= " AND p.section = '$filter_section'";
        if ($filter_grade !== '') $where_clause .= " AND p.grade_level = '$filter_grade'";
    }

    if ($type === 'daily') {
        $selected_date = !empty($_GET['daily_date']) ? $_GET['daily_date'] : date('Y-m-d');
        $where_clause .= " AND DATE(a.`time in`) = '$selected_date'";
        $report_title = "Daily Attendance Report - " . date('F d, Y', strtotime($selected_date));
    } else {
        $selected_month = !empty($_GET['monthly_date']) ? $_GET['monthly_date'] : date('Y-m');
        $where_clause .= " AND DATE_FORMAT(a.`time in`, '%Y-%m') = '$selected_month'";
        $report_title = "Monthly Attendance Report - " . date('F Y', strtotime($selected_month . "-01"));
    }

    $sql = "SELECT a.student_name, p.section, p.grade_level, a.`time in`, a.`time out`, a.present 
            FROM attendance a 
            LEFT JOIN parents p ON a.student_name = p.student_name 
            $where_clause 
            ORDER BY a.student_name ASC";
    $res = $conn->query($sql);

    echo "<html><head><title>Print Report - $schoolName</title><style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 30px; color: #333; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #999; padding: 10px; text-align: left; font-size: 12px; }
            th { background-color: #f2f2f2; }
            .header { display: flex; align-items: center; justify-content: center; margin-bottom: 30px; border-bottom: 2px solid #444; padding-bottom: 15px; }
            .logo-container { margin-right: 20px; }
            .logo-img { width: 80px; height: 80px; object-fit: cover; border-radius: 5px; }
            .header-text { text-align: center; }
            .footer { margin-top: 30px; font-size: 11px; font-style: italic; }
            @media print { button { display: none; } body { padding: 0; } }
        </style></head><body onload='window.print()'>
        <div class='header'>
            <div class='logo-container'><img src='bg3.jpg' class='logo-img' alt='Logo'></div>
            <div class='header-text'>
                <h1 style='margin:0;'>$schoolName</h1>
                <h2 style='margin:5px 0;'>$report_title</h2>
                <p style='margin:0;'>Filter: " . ($current_role === 'Admin' ? ($filter_grade || $filter_section ? "$filter_grade $filter_section" : 'All Records') : "$user_grade - $user_section") . "</p>
                <p style='margin:0;'>Generated on: " . date('Y-m-d h:i A') . "</p>
            </div>
        </div>
        <table><thead><tr><th>#</th><th>Student Name</th><th>Grade & Section</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead><tbody>";
    $count = 1;
    while ($r = $res->fetch_assoc()) {
        echo "<tr><td>$count</td><td>{$r['student_name']}</td><td>{$r['grade_level']} - {$r['section']}</td><td>" . ($r['time in'] ? date('h:i A', strtotime($r['time in'])) : '---') . "</td><td>" . ($r['time out'] ? date('h:i A', strtotime($r['time out'])) : '---') . "</td><td>{$r['present']}</td></tr>";
        $count++;
    }
    echo "</tbody></table><div class='footer'><p>Total Records: " . ($count-1) . "</p><p>End of Report</p></div></body></html>";
    exit();
}

/* HANDLE RESTORE STUDENT */
if (isset($_GET['restore_id'])) {
    $id = intval($_GET['restore_id']);
    $stmt = $conn->prepare("UPDATE parents SET is_deleted = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        logActivity($conn, "Restored student record", "Restored Student ID: $id");
        $_SESSION['action_success'] = "Record restored successfully!";
        header("Location: dashboard.php?section=parent");
        exit();
    }
}

/* HANDLE ADD STUDENT */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $student_name = trim($_POST['student_name']);
    $lrn = trim($_POST['lrn']);
    $section = trim($_POST['section']);
    $grade_level = trim($_POST['grade_level'] ?? '');
    $parent_name = trim($_POST['parent_name']);
    $contact = trim($_POST['contact_number']);
    $address = trim($_POST['address']);
    $rfid_uid = trim($_POST['rfid_uid']);

    $stmt = $conn->prepare("INSERT INTO parents (student_name, lrn, grade_level, section, parent_name, contact_number, address, rfid_uid) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $student_name, $lrn, $grade_level, $section, $parent_name, $contact, $address, $rfid_uid);

    if ($stmt->execute()) {
        logActivity($conn, "Added new student", "Student: $student_name (LRN: $lrn)");
        $_SESSION['action_success'] = "Student added successfully!";
        header("Location: dashboard.php?section=parent");
        exit();
    } else {
        $message = "Error: " . $conn->error;
    }
    $stmt->close();
}

/* HANDLE ARCHIVE STUDENT */
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    $stmt = $conn->prepare("SELECT * FROM parents WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($data) {
        $backup = "Deleted Data Backup: " . json_encode($data);
        $stmt = $conn->prepare("UPDATE parents SET is_deleted = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            logActivity($conn, "Archived student record", $backup);
            $_SESSION['action_success'] = "Record archived successfully!";
            header("Location: dashboard.php?section=parent");
            exit();
        }
    }
}

/* HANDLE EDIT STUDENT */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    $id = intval($_POST['student_id']);
    $s_name = trim($_POST['student_name']);
    $lrn = trim($_POST['lrn']);
    $grade_l = trim($_POST['grade_level']);
    $section = trim($_POST['section']);
    $p_name = trim($_POST['parent_name']);
    $contact = trim($_POST['contact_number']);
    $addr = trim($_POST['address']);
    $uid = trim($_POST['rfid_uid']);

    $stmt = $conn->prepare("UPDATE parents SET student_name=?, lrn=?, grade_level=?, section=?, parent_name=?, contact_number=?, address=?, rfid_uid=? WHERE id=?");
    $stmt->bind_param("ssssssssi", $s_name, $lrn, $grade_l, $section, $p_name, $contact, $addr, $uid, $id);
    if ($stmt->execute()) {
        logActivity($conn, "Updated student record", "Student: $s_name (ID: $id)");
        $_SESSION['action_success'] = "Record updated successfully!";
        header("Location: dashboard.php?section=parent");
        exit();
    }
    $stmt->close();
}

/* --- STATS LOGIC (ACCURACY & COLOR FIX) --- */
$roleFilter = ($current_role !== 'Admin') ? " AND p.grade_level = '$user_grade' AND p.section = '$user_section'" : "";

function getCount($conn, $sql) {
    $result = $conn->query($sql);
    return ($result && $row = $result->fetch_assoc()) ? (int)$row['total'] : 0;
}

// Kabuuang bilang ng lahat ng estudyante na hindi deleted
$totalRegisteredStudents = getCount($conn, "SELECT COUNT(*) as total FROM parents p WHERE p.is_deleted = 0 $roleFilter");

// Ngayon, bibilangin lang ang Present o Late (HINDI kasama ang Absent sa "Total Present Today")
$totalPresentToday = getCount($conn, "SELECT COUNT(DISTINCT a.student_name) as total FROM attendance a LEFT JOIN parents p ON a.student_name = p.student_name WHERE DATE(a.`time in`) = CURDATE() AND (a.present = 'Present' OR a.late = 'Late') $roleFilter");

$presentOnlyCount = getCount($conn, "SELECT COUNT(*) as total FROM attendance a LEFT JOIN parents p ON a.student_name = p.student_name WHERE a.present = 'Present' AND DATE(a.`time in`) = CURDATE() $roleFilter");
$lateCount = getCount($conn, "SELECT COUNT(*) as total FROM attendance a LEFT JOIN parents p ON a.student_name = p.student_name WHERE a.late = 'Late' AND DATE(a.`time in`) = CURDATE() $roleFilter");
$absentCount = getCount($conn, "SELECT COUNT(*) as total FROM attendance a LEFT JOIN parents p ON a.student_name = p.student_name WHERE a.present = 'Absent' AND DATE(a.`time in`) = CURDATE() $roleFilter");

// Accurate Attendance Rate: (Present + Late) / Total Registered Students
$overallRate = ($totalRegisteredStudents > 0) ? round(($totalPresentToday / $totalRegisteredStudents) * 100, 1) : 0;

// Logic para sa kulay ng rate circle
$rateColor = "#ef4444"; // Default: Red
if ($overallRate >= 85) {
    $rateColor = "#10b981"; // Green (Mataas)
} elseif ($overallRate >= 60) {
    $rateColor = "#f59e0b"; // Yellow/Orange (Moderate)
}

$showSuccessAlert = isset($_SESSION['action_success']);
$alertText = $showSuccessAlert ? $_SESSION['action_success'] : "";
if ($showSuccessAlert) unset($_SESSION['action_success']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFID Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .search-wrapper { position: relative; display: flex; align-items: center; width: 100%; }
        .search-icon { position: absolute; left: 15px; color: #94a3b8; pointer-events: none; }
        .search-input { width: 100%; padding: 12px 12px 12px 45px !important; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; }
        .search-input:focus { border-color: #4f46e5; }
        .report-form-box { flex: 1; background: #f9fafb; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; }
        .report-form-box label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; }
        .report-form-box select, .report-form-box input { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 8px; }
        .user-card { background: #fff; border: 1px solid #edf2f7; padding: 15px; border-radius: 12px; display: flex; align-items: center; gap: 15px; margin-bottom: 10px; }
        .user-avatar { width: 45px; height: 45px; background: #e0e7ff; color: #4338ca; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .user-info h4 { margin: 0; font-size: 15px; color: #1e293b; }
        .user-info p { margin: 0; font-size: 12px; color: #64748b; }
        .role-badge { font-size: 10px; padding: 2px 8px; border-radius: 20px; background: #f1f5f9; color: #475569; font-weight: 600; text-transform: uppercase; }
        
        /* Progress Circle Color Fix */
        .big-progress {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 20px auto;
            position: relative;
            background: radial-gradient(closest-side, white 82%, transparent 0 100%), 
                        conic-gradient(<?php echo $rateColor; ?> <?php echo $overallRate; ?>%, #e2e8f0 0);
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .big-progress::after {
            content: "";
            position: absolute;
            width: 82%;
            height: 82%;
            background: white;
            border-radius: 50%;
            z-index: -1;
        }
    </style>
</head>
<body class="dashboard-page">

<?php if ($showSuccessAlert): ?>
    <div id="successAlertOverlay" class="center-alert-overlay" onclick="this.style.display='none'">
        <div class="center-alert-box">
            <div style="font-size: 40px; color: #10b981; margin-bottom: 10px;"><i class="fa-solid fa-circle-check"></i></div>
            <h3>Success</h3>
            <p><?php echo $alertText; ?></p>
            <button class="login-button" style="width: auto; padding: 8px 30px; margin-top: 15px;">OK</button>
        </div>
    </div>
<?php endif; ?>

<div class="dashboard-container">
    <div class="sidebar">
        <div class="sidebar-logo">
            <img src="bg3.jpg" alt="Logo">
            <div>
                <h2>RFID <?php echo ($current_role === 'Admin') ? 'Admin' : 'Staff'; ?></h2>
                <small style="color:rgba(255,255,255,0.7);">
                    <?php echo ($current_role !== 'Admin') ? "$user_grade - $user_section" : "Attendance System"; ?>
                </small>
            </div>
        </div>
        <div class="menu-title">Main Menu</div>
        <ul>
            <li id="li-dashboard" onclick="showSection(event,'dashboard')"><i class="fa-solid fa-house"></i> Dashboard</li>
            <li id="li-students" onclick="showSection(event,'students')"><i class="fa-solid fa-user-graduate"></i> Students</li>
            <li id="li-parent" onclick="showSection(event,'parent')"><i class="fa-solid fa-users"></i> Parent Record</li>
            <li id="li-reports" onclick="showSection(event,'reports')"><i class="fa-solid fa-file-lines"></i> Reports</li>
            <?php if ($current_role === 'Admin'): ?>
            <li id="li-logs" onclick="showSection(event,'logs')"><i class="fa-solid fa-list-check"></i> Activity Logs</li>
            <li id="li-settings" onclick="showSection(event,'settings')"><i class="fa-solid fa-gear"></i> Settings</li>
            <?php endif; ?>
        </ul>
        <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <h1>Welcome, <?php echo htmlspecialchars($current_user); ?>!</h1>
                <p><?php echo ($current_role === 'Admin') ? "Integrated RFID and SMS Tracking System" : "Staff Monitoring - Section: <strong>$user_grade - $user_section</strong>"; ?></p>
            </div>
            <div class="topbar-right">
                <div class="admin-badge">
                    <i class="fa-solid <?php echo ($current_role === 'Admin') ? 'fa-user-shield' : 'fa-user'; ?>"></i> 
                    <span><?php echo ($current_role === 'Admin') ? 'Administrator' : htmlspecialchars($current_role); ?></span>
                </div>
            </div>
        </div>

        <div id="dashboard" class="section">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-head"><div class="stat-title">Total Present Today</div><div class="stat-mini-icon"><i class="fa-solid fa-users"></i></div></div>
                    <div class="stat-body"><div class="stat-icon-large"><i class="fa-solid fa-user-group"></i></div><div class="stat-value"><?php echo $totalPresentToday; ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-head"><div class="stat-title">Late</div><div class="stat-mini-icon"><i class="fa-solid fa-clock"></i></div></div>
                    <div class="stat-body">
                        <div class="ring" style="--value: <?php echo ($totalPresentToday > 0 ? round(($lateCount / $totalPresentToday) * 100) : 0); ?>%; color: #f59e0b;"><i class="fa-solid fa-clock"></i></div>
                        <div class="stat-value"><?php echo $lateCount; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-head"><div class="stat-title">Absent</div><div class="stat-mini-icon"><i class="fa-solid fa-user-xmark"></i></div></div>
                    <div class="stat-body">
                        <div class="ring" style="--value: <?php echo ($totalRegisteredStudents > 0 ? round(($absentCount / $totalRegisteredStudents) * 100) : 0); ?>%; color: #ef4444;"><i class="fa-solid fa-user-xmark"></i></div>
                        <div class="stat-value"><?php echo $absentCount; ?></div>
                    </div>
                </div>
            </div>
            <div class="grid-2">
                <div class="box"><div class="box-title">Student Attendance Analytics</div><canvas id="attendanceChart" height="110"></canvas></div>
                <div class="box progress-panel">
                    <div class="box-title">Attendance Rate Today</div>
                    <div class="big-progress"><span><?php echo $overallRate; ?>%</span></div>
                    <p style="text-align: center; color: #64748b; margin-top: 10px;">
                        <strong><?php echo $totalPresentToday; ?></strong> of <strong><?php echo $totalRegisteredStudents; ?></strong> Students
                    </p>
                </div>
            </div>
        </div>

        <div id="students" class="section" style="display:none">
            <div class="box">
                <div class="box-title">Student Attendance List (Today)</div>
                <div class="filter-group">
                    <div class="search-wrapper">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" class="search-input" id="searchStudents" onkeyup="multiFilter('searchStudents', 'filterSectionStudents', 'studentsTable')" placeholder="Search student name...">
                    </div>
                    <?php if ($current_role === 'Admin'): ?>
                    <select class="filter-select" id="filterSectionStudents" onchange="multiFilter('searchStudents', 'filterSectionStudents', 'studentsTable')">
                        <option value="">All Sections</option>
                        <option value="Section 1">Section 1</option><option value="Section 2">Section 2</option>
                        <option value="Section 3">Section 3</option><option value="Section 4">Section 4</option>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="table-wrap">
                    <table id="studentsTable">
                        <thead><tr><th>Name</th><th>Grade & Section</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php
                            $sqlAtt = "SELECT a.*, p.section, p.grade_level FROM attendance a LEFT JOIN parents p ON a.student_name = p.student_name WHERE DATE(a.`time in`) = CURDATE() $roleFilter ORDER BY a.`time in` DESC";
                            $attResult = $conn->query($sqlAtt);
                            while ($row = $attResult->fetch_assoc()) {
                                $tIn = date('h:i A', strtotime($row['time in']));
                                $tOut = (!empty($row['time out']) && $row['time out'] != '0000-00-00 00:00:00') ? date('h:i A', strtotime($row['time out'])) : '---';
                                echo "<tr><td><strong>".htmlspecialchars($row['student_name'])."</strong></td><td>".htmlspecialchars($row['grade_level'])." - ".htmlspecialchars($row['section'])."</td><td>$tIn</td><td>$tOut</td><td>{$row['present']}</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="parent" class="section" style="display:none">
            <div class="box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                    <div class="box-title" style="margin:0;">Student & Parent Records</div>
                    <button onclick="document.getElementById('addStudentModal').style.display='flex'" class="login-button" style="width:auto; height: 44px; padding: 0 20px; background: #00b4b6;"><i class="fa-solid fa-user-plus"></i> Add Student</button>
                </div>
                <div class="filter-group">
                    <div class="search-wrapper">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" class="search-input" id="searchParents" onkeyup="multiFilter('searchParents', 'filterSectionParents', 'parentsTable')" placeholder="Search LRN, name, or RFID...">
                    </div>
                    <?php if ($current_role === 'Admin'): ?>
                    <select class="filter-select" id="filterSectionParents" onchange="multiFilter('searchParents', 'filterSectionParents', 'parentsTable')">
                        <option value="">All Sections</option>
                        <option value="Section 1">Section 1</option><option value="Section 2">Section 2</option>
                        <option value="Section 3">Section 3</option><option value="Section 4">Section 4</option>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="table-wrap">
                    <table id="parentsTable">
                        <thead><tr><th>LRN</th><th>Name</th><th>Grade & Section</th><th>Parent</th><th>Contact</th><th>RFID UID</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php
                            $parentResult = $conn->query("SELECT * FROM parents p WHERE is_deleted = 0 $roleFilter ORDER BY student_name ASC");
                            while ($row = $parentResult->fetch_assoc()) {
                                $jsData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                                echo "<tr>
                                    <td>{$row['lrn']}</td>
                                    <td>".htmlspecialchars($row['student_name'])."</td>
                                    <td>".htmlspecialchars($row['grade_level'])." - ".htmlspecialchars($row['section'])."</td>
                                    <td>".htmlspecialchars($row['parent_name'])."</td>
                                    <td>{$row['contact_number']}</td>
                                    <td><span class='badge-section' style='background:#f3f4f6; color:#374151; border:1px solid #d1d5db;'>".htmlspecialchars($row['rfid_uid'])."</span></td>
                                    <td class='action-cell'>
                                        <button class='btn-edit' onclick='openEditModal($jsData)'><i class='fa-solid fa-pen-to-square'></i></button>
                                        <a href='?delete_id=".$row['id']."' class='btn-delete' onclick=\"return confirm('Archive this record?')\"><i class='fa-solid fa-box-archive'></i></a>
                                    </td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="reports" class="section" style="display:none">
             <div class="box">
                <div class="box-title">Generate Print Reports</div>
                <div style="display: flex; gap: 20px;">
                    <form method="GET" target="_blank" class="report-form-box">
                        <label><i class="fa-solid fa-calendar-day"></i> Daily Attendance</label>
                        <input type="hidden" name="print_report" value="1"><input type="hidden" name="print_type" value="daily">
                        <input type="date" name="daily_date" value="<?php echo date('Y-m-d'); ?>">
                        <?php if($current_role === 'Admin'): ?>
                            <label>Grade Level</label>
                            <select name="filter_grade">
                                <option value="">All Grades</option>
                                <option value="Grade 7">Grade 7</option><option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option><option value="Grade 10">Grade 10</option>
                            </select>
                            <label>Section</label>
                            <select name="filter_section">
                                <option value="">All Sections</option>
                                <option value="Section 1">Section 1</option><option value="Section 2">Section 2</option>
                                <option value="Section 3">Section 3</option><option value="Section 4">Section 4</option>
                            </select>
                        <?php endif; ?>
                        <button type="submit" class="login-button" style="width:100%; background: #4f46e5;"><i class="fa-solid fa-print"></i> Print Daily</button>
                    </form>
                    <form method="GET" target="_blank" class="report-form-box">
                        <label><i class="fa-solid fa-calendar-days"></i> Monthly Attendance</label>
                        <input type="hidden" name="print_report" value="1"><input type="hidden" name="print_type" value="monthly">
                        <input type="month" name="monthly_date" value="<?php echo date('Y-m'); ?>">
                        <?php if($current_role === 'Admin'): ?>
                            <label>Grade Level</label>
                            <select name="filter_grade">
                                <option value="">All Grades</option>
                                <option value="Grade 7">Grade 7</option><option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option><option value="Grade 10">Grade 10</option>
                            </select>
                            <label>Section</label>
                            <select name="filter_section">
                                <option value="">All Sections</option>
                                <option value="Section 1">Section 1</option><option value="Section 2">Section 2</option>
                                <option value="Section 3">Section 3</option><option value="Section 4">Section 4</option>
                            </select>
                        <?php endif; ?>
                        <button type="submit" class="login-button" style="width:100%; background: #0891b2;"><i class="fa-solid fa-print"></i> Print Monthly</button>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($current_role === 'Admin'): ?>
        <div id="logs" class="section" style="display:none">
            <div class="box">
                <div class="box-title">System Activity Logs</div>
                <div class="search-wrapper" style="max-width:350px; margin-bottom:20px;">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-input" id="searchLogs" onkeyup="filterTable('searchLogs', 'logsTable')" placeholder="Search logs...">
                </div>
                <div class="table-wrap">
                    <table id="logsTable">
                        <thead><tr><th>Timestamp</th><th>Action</th><th>Details</th><th>Recovery</th></tr></thead>
                        <tbody>
                            <?php
                           $logRes = $conn->query("SELECT * FROM activity_logs ORDER BY timestamp DESC LIMIT 50");
                           while ($log = $logRes->fetch_assoc()) {
                               $restore_btn = "";
                               if (strpos($log['action'], 'Archived') !== false) {
                                   $json_part = str_replace(['Deleted Data Backup: ', 'Archived Data Backup: '], '', $log['details']);
                                   $details = json_decode($json_part, true);
                                   if ($details && isset($details['id'])) {
                                       $restore_btn = "<a href='?restore_id=".$details['id']."' class='badge-section' style='background:#10b981; color:white; text-decoration:none; padding: 5px 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px; font-size: 12px;'><i class='fa-solid fa-rotate-left'></i> Restore</a>";
                                   }
                               }
                               echo "<tr><td>{$log['timestamp']}</td><td><span class='badge-section' style='background:#e2e8f0; padding:3px 8px; border-radius:5px;'>{$log['action']}</span></td><td style='font-size:11px;'>".htmlspecialchars($log['details'])."</td><td>$restore_btn</td></tr>";
                           }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="settings" class="section" style="display:none">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="box">
                    <div class="box-title"><i class="fa-solid fa-user-plus" style="color:#4f46e5;"></i> Create Staff Account</div>
                    <form method="POST">
                        <input type="hidden" name="add_new_user" value="1">
                        <div style="margin-bottom:15px;"><label>Username</label><input type="text" name="new_username" class="search-input" style="padding: 10px !important;" placeholder="Enter username" required></div>
                        <div style="margin-bottom:15px;"><label>Password</label><input type="password" name="new_password" class="search-input" style="padding: 10px !important;" placeholder="Enter password" required></div>
                        
                        <div style="display: flex; gap: 15px; margin-bottom:15px;">
                            <div style="flex: 1;"><label>Grade Level</label>
                                <select name="grade_level" class="filter-select" style="width:100%;">
                                    <option value="Grade 7">Grade 7</option><option value="Grade 8">Grade 8</option>
                                    <option value="Grade 9">Grade 9</option><option value="Grade 10">Grade 10</option>
                                </select>
                            </div>
                            <div style="flex: 1;"><label>Section</label>
                                <select name="user_section" class="filter-select" style="width:100%;">
                                    <option value="Section 1">Section 1</option><option value="Section 2">Section 2</option>
                                    <option value="Section 3">Section 3</option><option value="Section 4">Section 4</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-bottom:20px;">
                            <label>Role</label>
                            <select name="user_role" class="filter-select" style="width:100%;">
                                <option value="Staff">Staff</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>
                        <button type="submit" class="login-button" style="width:100%; background: #4f46e5;">Create Account</button>
                    </form>
                </div>
                <div class="box">
                    <div class="box-title"><i class="fa-solid fa-users-gear" style="color:#64748b;"></i> Active Accounts</div>
                    <?php
                    $uRes = $conn->query("SELECT username, role, grade_level, section FROM users ORDER BY role ASC");
                    while($u = $uRes->fetch_assoc()):
                    ?>
                        <div class="user-card">
                            <div class="user-avatar"><i class="fa-solid fa-user"></i></div>
                            <div class="user-info">
                                <h4><?php echo htmlspecialchars($u['username']); ?> <span class="role-badge"><?php echo $u['role']; ?></span></h4>
                                <p><?php echo ($u['role'] === 'Admin') ? 'System Access' : "{$u['grade_level']} - {$u['section']}"; ?></p>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function showSection(e, id) {
        document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
        document.getElementById(id).style.display = 'block';
        document.querySelectorAll('.sidebar ul li').forEach(li => li.classList.remove('active'));
        if(e) e.currentTarget.classList.add('active');
    }

    const urlParams = new URLSearchParams(window.location.search);
    const section = urlParams.get('section');
    if(section) {
        showSection(null, section);
        const li = document.getElementById('li-' + section);
        if(li) li.classList.add('active');
    } else {
        document.getElementById('li-dashboard').classList.add('active');
    }

    function multiFilter(inputID, selectID, tableID) {
        let input = document.getElementById(inputID).value.toUpperCase();
        let select = document.getElementById(selectID) ? document.getElementById(selectID).value.toUpperCase() : "";
        let tr = document.getElementById(tableID).getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) {
            let text = tr[i].textContent.toUpperCase();
            tr[i].style.display = (text.includes(input) && text.includes(select)) ? "" : "none";
        }
    }

    function filterTable(inputID, tableID) {
        let input = document.getElementById(inputID).value.toUpperCase();
        let tr = document.getElementById(tableID).getElementsByTagName("tr");
        for (let i = 1; i < tr.length; i++) {
            tr[i].style.display = tr[i].textContent.toUpperCase().includes(input) ? "" : "none";
        }
    }
</script>

<div id="addStudentModal" class="center-alert-overlay" style="display:none; background: rgba(0,0,0,0.5);">
    <div class="box" style="width: 500px; max-height: 90vh; overflow-y: auto;">
        <div class="box-title">Add New Student</div>
        <form method="POST">
            <input type="hidden" name="add_student" value="1">
            <div style="margin-bottom:10px;"><label>Student Full Name</label><input type="text" name="student_name" class="search-input" style="padding:10px !important;" required></div>
            <div style="margin-bottom:10px;"><label>LRN</label><input type="text" name="lrn" class="search-input" style="padding:10px !important;" required></div>
            <div style="display:flex; gap:10px; margin-bottom:10px;">
                <div style="flex:1;"><label>Grade Level</label><input type="text" name="grade_level" class="search-input" style="padding:10px !important;" placeholder="Grade 7" required></div>
                <div style="flex:1;"><label>Section</label><input type="text" name="section" class="search-input" style="padding:10px !important;" required></div>
            </div>
            <div style="margin-bottom:10px;"><label>Parent/Guardian Name</label><input type="text" name="parent_name" class="search-input" style="padding:10px !important;" required></div>
            <div style="margin-bottom:10px;"><label>Contact Number</label><input type="text" name="contact_number" class="search-input" style="padding:10px !important;" required></div>
            <div style="margin-bottom:10px;"><label>Address</label><input type="text" name="address" class="search-input" style="padding:10px !important;" required></div>
            <div style="margin-bottom:20px;"><label>RFID UID</label><input type="text" name="rfid_uid" class="search-input" style="padding:10px !important;" required></div>
            <div style="display:flex; gap:10px;">
                <button type="submit" class="login-button" style="background:#00b4b6;">Save Student</button>
                <button type="button" onclick="document.getElementById('addStudentModal').style.display='none'" class="login-button" style="background:#64748b;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="editStudentModal" class="center-alert-overlay" style="display:none; background: rgba(0,0,0,0.5);">
    <div class="box" style="width: 500px;">
        <div class="box-title">Edit Student Record</div>
        <form method="POST">
            <input type="hidden" name="edit_student" value="1">
            <input type="hidden" name="student_id" id="edit_id">
            <div style="margin-bottom:10px;"><label>Student Name</label><input type="text" name="student_name" id="edit_name" class="search-input" style="padding:10px !important;" required></div>
            <div style="margin-bottom:10px;"><label>LRN</label><input type="text" name="lrn" id="edit_lrn" class="search-input" style="padding:10px !important;" required></div>
            <div style="display:flex; gap:10px; margin-bottom:10px;">
                <div style="flex:1;"><label>Grade</label><input type="text" name="grade_level" id="edit_grade" class="search-input" style="padding:10px !important;" required></div>
                <div style="flex:1;"><label>Section</label><input type="text" name="section" id="edit_section" class="search-input" style="padding:10px !important;" required></div>
            </div>
            <div style="margin-bottom:10px;"><label>Parent Name</label><input type="text" name="parent_name" id="edit_parent" class="search-input" style="padding:10px !important;" required></div>
            <div style="margin-bottom:10px;"><label>Contact</label><input type="text" name="contact_number" id="edit_contact" class="search-input" style="padding:10px !important;" required></div>
            <div style="margin-bottom:10px;"><label>Address</label><input type="text" name="address" id="edit_address" class="search-input" style="padding:10px !important;" required></div>
            <div style="margin-bottom:20px;"><label>RFID UID</label><input type="text" name="rfid_uid" id="edit_uid" class="search-input" style="padding:10px !important;" required></div>
            <div style="display:flex; gap:10px;">
                <button type="submit" class="login-button">Update Record</button>
                <button type="button" onclick="document.getElementById('editStudentModal').style.display='none'" class="login-button" style="background:#64748b;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(data) {
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_name').value = data.student_name;
        document.getElementById('edit_lrn').value = data.lrn;
        document.getElementById('edit_grade').value = data.grade_level;
        document.getElementById('edit_section').value = data.section;
        document.getElementById('edit_parent').value = data.parent_name;
        document.getElementById('edit_contact').value = data.contact_number;
        document.getElementById('edit_address').value = data.address;
        document.getElementById('edit_uid').value = data.rfid_uid;
        document.getElementById('editStudentModal').style.display = 'flex';
    }

    const ctx = document.getElementById('attendanceChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Present', 'Late', 'Absent'],
            datasets: [{
                label: 'Today\'s Attendance',
                data: [<?php echo $presentOnlyCount; ?>, <?php echo $lateCount; ?>, <?php echo $absentCount; ?>],
                backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                borderRadius: 8
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { display: false } }, x: { grid: { display: false } } }
        }
    });

let hulingID = null;
function monitorTap() {
    fetch('update.php?cache_bust=' + Date.now())
        .then(response => response.text())
        .then(data => {
            let currentLatest = data.trim();
            if (hulingID === null) {
                hulingID = currentLatest;
            } else if (currentLatest !== hulingID) {
                hulingID = currentLatest;
                window.location.reload(); 
            }
        })
        .catch(err => console.error("System Error:", err));
}
setInterval(monitorTap, 200);
</script>
</body>
</html>
