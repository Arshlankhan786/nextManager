<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$admin_id = $_SESSION['admin_id'];

// Start topic
if ($action === 'start_topic') {
    $group_id = (int)$_POST['group_id'];
    $topic_id = (int)$_POST['topic_id'];
    
    // Check if any topic is already active
    $active = $conn->query("SELECT id FROM group_topic_progress WHERE group_id = $group_id AND status = 'active'")->fetch_assoc();
    
    if ($active) {
        echo json_encode(['success' => false, 'message' => 'Another topic is already active. Complete it first.']);
        exit;
    }
    
    // Start topic
    $today = date('Y-m-d');
    $conn->query("UPDATE group_topic_progress SET status = 'active', start_date = '$today' WHERE group_id = $group_id AND topic_id = $topic_id");
    
    echo json_encode(['success' => true]);
    exit;
}

// Complete topic
if ($action === 'complete_topic') {
    $group_id = (int)$_POST['group_id'];
    $topic_id = (int)$_POST['topic_id'];
    
    $today = date('Y-m-d');
    
    // Complete current topic
    $conn->query("UPDATE group_topic_progress SET status = 'completed', end_date = '$today', completed_by = $admin_id WHERE group_id = $group_id AND topic_id = $topic_id");
    
    // Auto-start next topic
    $next = $conn->query("
        SELECT gtp.id, gtp.topic_id
        FROM group_topic_progress gtp
        JOIN course_topics ct ON gtp.topic_id = ct.id
        WHERE gtp.group_id = $group_id 
        AND gtp.status = 'upcoming'
        ORDER BY ct.order_index ASC
        LIMIT 1
    ")->fetch_assoc();
    
    if ($next) {
        $conn->query("UPDATE group_topic_progress SET status = 'active', start_date = '$today' WHERE id = {$next['id']}");
    }
    
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);