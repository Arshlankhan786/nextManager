<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['student_id']) || !isset($_GET['days'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

$student_id = (int)$_GET['student_id'];
$days = (int)$_GET['days'];

if ($days <= 0) {
    $days = 30;
}

// Generate date range
$dates = [];
$present_data = [];
$absent_data = [];
$present_count = 0;
$absent_count = 0;

for ($i = $days - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dates[] = date('M d', strtotime($date));
    
    // Check attendance
    $stmt = $conn->prepare("
        SELECT status FROM student_attendance 
        WHERE student_id = ? 
        AND attendance_date = ?
    ");
    $stmt->bind_param("is", $student_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $present_data[] = 1;
        $absent_data[] = null;
        $present_count++;
    } else {
        $present_data[] = null;
        $absent_data[] = 1;
        $absent_count++;
    }
    $stmt->close();
}

$total_days = $days;
$attendance_rate = $total_days > 0 ? round(($present_count / $total_days) * 100, 1) : 0;

echo json_encode([
    'success' => true,
    'dates' => $dates,
    'present' => $present_data,
    'absent' => $absent_data,
    'present_count' => $present_count,
    'absent_count' => $absent_count,
    'total_days' => $total_days,
    'attendance_rate' => $attendance_rate
]);