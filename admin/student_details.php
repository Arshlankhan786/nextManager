<?php
session_start();
require_once 'config/database.php';
require_once 'config/auth.php';
requireLogin();

$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;


if ($student_id === 0) {
    header('Location: students.php');
    exit();
}

// Handle batch toggle
if (isset($_POST['toggle_batch'])) {
    $current_batch = $_POST['current_batch'];
    $new_batch = ($current_batch === 'Morning') ? 'Evening' : 'Morning';
    
    $stmt = $conn->prepare("UPDATE students SET batch = ? WHERE id = ?");
    $stmt->bind_param("si", $new_batch, $student_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Batch changed to $new_batch successfully!";
    } else {
        $_SESSION['error'] = "Failed to change batch.";
    }
    $stmt->close();
    header("Location: student_details.php?id=$student_id");
    exit();
}

// ---- handle update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_student') {
        $full_name = sanitize($_POST['full_name']);
        $email = sanitize($_POST['email']);
        $phone = sanitize($_POST['phone']);
        $address = sanitize($_POST['address']);
        $birthdate = sanitize($_POST['birthdate']);
        $category_id = (int)$_POST['category_id'];
        $course_id = (int)$_POST['course_id'];
        $duration_months = (int)$_POST['duration_months'];
        $total_fees = (float)$_POST['total_fees'];

        $stmt = $conn->prepare("UPDATE students SET full_name=?, email=?, phone=?, address=?, birthdate=?, category_id=?, course_id=?, duration_months=?, total_fees=? WHERE id=?");
        $stmt->bind_param("sssssiidii", $full_name, $email, $phone, $address, $birthdate, $category_id, $course_id, $duration_months, $total_fees, $student_id);

        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = "Student details updated successfully!";
        header("Location: student_details.php?id=$student_id");
        exit();
    }
}

// ---- handle photo upload ----
// ---- handle photo upload ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['student_photo'])) {
    $upload_dir = __DIR__ . '/uploads/students/';
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file = $_FILES['student_photo'];
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    // Validation
    if ($file['error'] === UPLOAD_ERR_OK) {
        if (!in_array($file['type'], $allowed_types)) {
            $_SESSION['error'] = "Invalid file type. Only JPG, PNG, GIF allowed.";
        } elseif ($file['size'] > $max_size) {
            $_SESSION['error'] = "File too large. Maximum 5MB allowed.";
        } else {
            // Delete old photo
            $old_photo = $conn->query("SELECT photo FROM students WHERE id=$student_id")->fetch_assoc()['photo'];
            if ($old_photo && file_exists($old_photo)) {
                unlink($old_photo);
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'student_' . $student_id . '_' . time() . '.' . $extension;
            $target_path = $upload_dir . $filename;
            $db_path = 'uploads/students/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $stmt = $conn->prepare("UPDATE students SET photo = ? WHERE id = ?");
                $stmt->bind_param("si", $db_path, $student_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Photo uploaded successfully!";
                } else {
                    $_SESSION['error'] = "Failed to save photo to database.";
                    unlink($target_path);
                }
                $stmt->close();
            } else {
                $_SESSION['error'] = "Failed to upload photo. Check folder permissions.";
            }
        }
    } else {
        $_SESSION['error'] = "Upload error: " . $file['error'];
    }
    
    header("Location: student_details.php?id=$student_id");
    exit();
}

// ---- handle delete photo ----
if (isset($_GET['delete_photo'])) {
    $photo = $conn->query("SELECT photo FROM students WHERE id=$student_id")->fetch_assoc()['photo'];
    if ($photo && file_exists($photo)) unlink($photo);
    $conn->query("UPDATE students SET photo=NULL WHERE id=$student_id");
    $_SESSION['success']="Photo deleted!";
    header("Location: student_details.php?id=$student_id");
    exit();
}

// Get student details with category
$student = $conn->query("SELECT s.*, c.name as course_name, cat.id as category_id, cat.name as category_name
                        FROM students s
                        JOIN courses c ON s.course_id = c.id
                        JOIN categories cat ON s.category_id = cat.id
                        WHERE s.id = $student_id AND s.status = 'Active'")->fetch_assoc();

if (!$student) {
    $_SESSION['error'] = "Student not found!";
    header('Location: students.php');
    exit();
}

// Get all categories and courses for edit
$categories = $conn->query("SELECT id, name FROM categories WHERE status = 'Active' ORDER BY name");
$all_courses = $conn->query("SELECT id, category_id, name FROM courses WHERE status = 'Active' ORDER BY name");

$has_photo = !empty($student['photo']) && file_exists($student['photo']);

// Get payment summary
$payment_summary = $conn->query("SELECT 
                                COALESCE(SUM(amount_paid), 0) as total_paid,
                                COUNT(*) as payment_count
                                FROM payments 
                                WHERE student_id = $student_id")->fetch_assoc();

$total_paid = $payment_summary['total_paid'] ?? 0;
$pending = $student['total_fees'] - $total_paid;

$paid_this_month = $conn->query("SELECT COUNT(*) as count FROM payments 
                                 WHERE student_id = $student_id 
                                 AND YEAR(payment_date) = YEAR(CURDATE())
                                 AND MONTH(payment_date) = MONTH(CURDATE())")->fetch_assoc()['count'] > 0;

$is_overdue = (!$paid_this_month && $pending > 0);

// Get payment history
$payments = $conn->query("SELECT p.*, a.full_name as admin_name 
                         FROM payments p 
                         JOIN admins a ON p.created_by = a.id 
                         WHERE p.student_id = $student_id 
                         ORDER BY p.payment_date DESC, p.created_at DESC");

$payment_status = 'Pending';
if ($pending <= 0) $payment_status = 'Paid';
elseif ($total_paid > 0) $payment_status = 'Partial';

// Calculate age if birthdate exists
$age = '';
if ($student['birthdate']) {
    $birthdate = new DateTime($student['birthdate']);
    $today = new DateTime();
    $age = $today->diff($birthdate)->y;
}


if ($_POST['action'] === 'verify_project') {
    $project_id = (int)$_POST['project_id'];

    $stmt = $conn->prepare("
        UPDATE student_projects 
        SET status = 'Completed' 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $project_id);

    echo json_encode([
        'success' => $stmt->execute()
    ]);
    exit;
}

?>
<?php include 'includes/header.php'; ?>

<div class="page-header">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2">
            <li class="breadcrumb-item"><a href="students.php">Students</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($student['full_name']); ?></li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center">
        <h2><i class="fas fa-user-graduate text-purple"></i> Student Details</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editStudentModal">
            <i class="fas fa-edit"></i> Edit Details
        </button>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php
// After existing queries, add these:

// Get course progress
$course_end_date = date('Y-m-d', strtotime($student['enrollment_date'] . ' + ' . $student['duration_months'] . ' months'));
$total_days = (strtotime($course_end_date) - strtotime($student['enrollment_date'])) / 86400;
$days_elapsed = (strtotime('now') - strtotime($student['enrollment_date'])) / 86400;
$course_progress = min(100, ($days_elapsed / $total_days) * 100);

// Get topics
// $topics = $conn->query("SELECT * FROM course_topics WHERE student_id = $student_id ORDER BY created_at DESC");
// $topics_completed = $conn->query("SELECT COUNT(*) as count FROM course_topics WHERE student_id = $student_id AND status = 'Completed'")->fetch_assoc()['count'];
// $topics_total = $topics->num_rows;

// Get projects
$projects = $conn->query("SELECT * FROM student_projects WHERE student_id = $student_id ORDER BY created_at DESC");
?>

<!-- Course Timeline Card -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="table-card">
            <!--<h5 class="text-purple mb-3"><i class="fas fa-calendar-alt"></i> Course Timeline</h5>-->
            <div class="row">
                <div class="col-md-3">
                    <label class="text-muted mb-1">Enrollment Date</label>
                    <p class="mb-0"><strong><?php echo date('d M Y', strtotime($student['enrollment_date'])); ?></strong></p>
                </div>
                <div class="col-md-3">
                    <label class="text-muted mb-1">Course Duration</label>
                    <p class="mb-0"><strong><?php echo $student['duration_months']; ?> Months</strong></p>
                </div>
                <div class="col-md-3">
                    <label class="text-muted mb-1">Expected End Date</label>
                    <p class="mb-0"><strong><?php echo date('d M Y', strtotime($course_end_date)); ?></strong></p>
                </div>
                <div class="col-md-3">
                    <label class="text-muted mb-1">Days Remaining</label>
                    <?php 
                    $days_remaining = (strtotime($course_end_date) - strtotime('now')) / 86400;
                    $badge_class = $days_remaining < 0 ? 'danger' : ($days_remaining < 30 ? 'warning' : 'success');
                    ?>
                    <p class="mb-0">
                        <span class="badge bg-<?php echo $badge_class; ?>">
                            <?php echo $days_remaining < 0 ? 'Expired ' . abs(round($days_remaining)) . ' days ago' : round($days_remaining) . ' days left'; ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- INSERT BEFORE Student Information Card (around line 200) -->
<!-- Payment Summary Cards - Move to TOP -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card dashboard-card">
            <div class="card-body">
                <p class="text-muted mb-1">Total Fees</p>
                <h4 class="mb-0 text-purple">₹<?php echo number_format($student['total_fees'], 2); ?></h4>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card dashboard-card">
            <div class="card-body">
                <p class="text-muted mb-1">Amount Paid</p>
                <h4 class="mb-0 text-success">₹<?php echo number_format($total_paid, 2); ?></h4>
                <small class="text-muted"><?php echo $payment_summary['payment_count']; ?> payments</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card dashboard-card">
            <div class="card-body">
                <p class="text-muted mb-1">Pending Amount</p>
                <h4 class="mb-0 text-danger">₹<?php echo number_format($pending, 2); ?></h4>
                <span class="badge status-<?php echo strtolower($payment_status); ?>"><?php echo $payment_status; ?></span>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card dashboard-card">
            <div class="card-body">
                <p class="text-muted mb-1">Course Progress</p>
                <h4 class="mb-0 text-info"><?php echo number_format($course_progress, 1); ?>%</h4>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-info" style="width: <?php echo $course_progress; ?>%"></div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- THEN continue with existing Student Information Card -->

<div class="row g-4">
    <!-- Student Information Card -->
    <div class="col-lg-4">
        <div class="table-card">
            <div class="text-center mb-3">
                <?php if ($has_photo): ?>
                    <img src="<?php echo htmlspecialchars($student['photo']); ?>" 
                         alt="Student Photo" 
                         class="rounded-circle mb-3" 
                         style="width: 150px; height: 150px; object-fit: cover; border: 5px solid <?php echo $is_overdue ? '#ef4444' : '#7c3aed'; ?>;">
                <?php else: ?>
                    <div class="<?php echo $is_overdue ? 'bg-danger' : 'bg-purple'; ?> text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 100px; height: 100px; font-size: 40px;">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                <?php endif; ?>
                
                <h4 class="mt-3 mb-1"><?php echo htmlspecialchars($student['full_name']); ?></h4>
                <p class="text-muted mb-2"><?php echo $student['student_code']; ?></p>
                <?php if ($age): ?>
                    <p class="text-muted mb-2"><i class="fas fa-birthday-cake"></i> <?php echo $age; ?> years old</p>
                <?php endif; ?>
                <span class="badge status-<?php echo strtolower($student['status']); ?>"><?php echo $student['status']; ?></span>
                <?php if ($is_overdue): ?>
                    <br><span class="badge bg-danger mt-2"><i class="fas fa-exclamation-triangle"></i> OVERDUE - No payment this month</span>
                <?php endif; ?>
                
                <div class="mt-3">
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal">
                        <i class="fas fa-camera"></i> <?php echo $has_photo ? 'Change' : 'Upload'; ?> Photo
                    </button>
                    <?php if ($has_photo): ?>
                    <a href="?id=<?php echo $student_id; ?>&delete_photo=1" 
                       class="btn btn-sm btn-danger" 
                       onclick="return confirm('Delete this photo?')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <hr>
            
            <div class="mb-3">
                <label class="text-muted mb-1"><i class="fas fa-phone"></i> Phone</label>
                <p class="mb-0"><strong><?php echo htmlspecialchars($student['phone']); ?></strong></p>
            </div>
            
            <?php if ($student['email']): ?>
            <div class="mb-3">
                <label class="text-muted mb-1"><i class="fas fa-envelope"></i> Email</label>
                <p class="mb-0"><?php echo htmlspecialchars($student['email']); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($student['birthdate']): ?>
            <div class="mb-3">
                <label class="text-muted mb-1"><i class="fas fa-birthday-cake"></i> Date of Birth</label>
                <p class="mb-0"><?php echo date('d M Y', strtotime($student['birthdate'])); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($student['address']): ?>
            <div class="mb-3">
                <label class="text-muted mb-1"><i class="fas fa-map-marker-alt"></i> Address</label>
                <p class="mb-0"><?php echo htmlspecialchars($student['address']); ?></p>
            </div>
            <?php endif; ?>
            
            <hr>
            
            <div class="mb-3">
                <label class="text-muted mb-1"><i class="fas fa-folder"></i> Category</label>
                <p class="mb-0"><span class="badge bg-secondary"><?php echo htmlspecialchars($student['category_name']); ?></span></p>
            </div>
            
            <div class="mb-3">
                <label class="text-muted mb-1"><i class="fas fa-book"></i> Course</label>
                <p class="mb-0"><strong><?php echo htmlspecialchars($student['course_name']); ?></strong></p>
            </div>
            
            <div class="mb-3">
                <label class="text-muted mb-1"><i class="fas fa-clock"></i> Duration</label>
                <p class="mb-0"><?php echo $student['duration_months']; ?> months</p>
            </div>
            
            <div class="mb-3">
                <label class="text-muted mb-1"><i class="fa-solid fa-indian-rupee-sign"></i> Total Fees</label>
                <p class="mb-0"><strong>₹<?php echo number_format($student['total_fees'], 2); ?></strong></p>
            </div>
            
            <div class="mb-3">
                <label class="text-muted mb-1"><i class="fas fa-calendar"></i> Enrollment Date</label>
                <p class="mb-0"><?php echo date('d M Y', strtotime($student['enrollment_date'])); ?></p>
            </div>
            
            <hr>
            
            <!-- Student Portal Access Section (keep existing code) -->
            <h5 class="text-purple mb-3"><i class="fas fa-key"></i> Student Portal Access</h5>
            
            <?php if ($student['login_enabled']): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <strong>Portal Access Enabled</strong>
                <div class="mt-2">
                    <strong>Username:</strong> <?php echo htmlspecialchars($student['username']); ?>
                </div>
            </div>
            
             <!-- NEW: Display and Toggle Batch -->
        <div class="mb-3">
            <label class="text-muted mb-1"><i class="fas fa-clock"></i> Batch</label>
            <div class="d-flex align-items-center justify-content-between">
                <span class="badge <?php echo $student['batch'] === 'Morning' ? 'bg-warning' : 'bg-info'; ?> fs-6">
                    <i class="fas <?php echo $student['batch'] === 'Morning' ? 'fa-sun' : 'fa-moon'; ?>"></i>
                    <?php echo $student['batch']; ?> Batch
                </span>
                
                <!-- Batch Toggle Switch -->
                <form method="POST" class="d-inline">
                    <input type="hidden" name="toggle_batch" value="1">
                    <input type="hidden" name="current_batch" value="<?php echo $student['batch']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary" 
                            onclick="return confirm('Change batch to <?php echo $student['batch'] === 'Morning' ? 'Evening' : 'Morning'; ?>?')">
                        <i class="fas fa-exchange-alt"></i> Switch
                    </button>
                </form>
            </div>
        </div>
            <!-- HOLD / RESUME SECTION -->
<hr>
<h5 class="text-purple mb-3"><i class="fas fa-pause-circle"></i> Enrollment Status</h5>

<?php if ($student['status'] === 'Hold'): ?>
<div class="alert alert-warning">
    <h6><i class="fas fa-pause-circle"></i> <strong>STUDENT ON HOLD</strong></h6>
    <p class="mb-2">This student's enrollment is currently paused.</p>
    <div class="mb-2">
        <strong>Hold Date:</strong> <?php echo date('d M Y', strtotime($student['hold_start_date'])); ?><br>
        <strong>Days on Hold:</strong> 
        <?php 
        $hold_days = (new DateTime())->diff(new DateTime($student['hold_start_date']))->days;
        echo $hold_days;
        ?> days
        <?php if ($student['hold_reason']): ?>
        <br><strong>Reason:</strong> <?php echo htmlspecialchars($student['hold_reason']); ?>
        <?php endif; ?>
    </div>
    <p class="mb-2"><i class="fas fa-info-circle"></i> <strong>While on hold:</strong></p>
    <ul class="mb-3">
        <li>Attendance tracking is paused</li>
        <li>Ranking points are paused</li>
        <li>Course duration is paused</li>
        <li>Does not appear in active reports</li>
    </ul>
    
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#resumeStudentModal">
        <i class="fas fa-play"></i> Resume Student
    </button>
</div>
<?php else: ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i> <strong>Student is Active</strong>
    <p class="mb-0">Enrollment is currently active. You can place this student on hold if needed.</p>
</div>

<button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#holdStudentModal">
    <i class="fas fa-pause"></i> Hold Student
</button>
<?php endif; ?>

<?php if ($student['total_hold_days'] > 0): ?>
<div class="mt-3">
    <small class="text-muted">
        <i class="fas fa-history"></i> <strong>Total Hold History:</strong> <?php echo $student['total_hold_days']; ?> days
    </small>
</div>
<?php endif; ?>
            <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#resetPasswordModal">
                <i class="fas fa-sync"></i> Reset Password
            </button>
            <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#disableLoginModal">
                <i class="fas fa-lock"></i> Disable Access
            </button>
            
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> <strong>Portal Access Disabled</strong>
            </div>
            
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#enableLoginModal">
                <i class="fas fa-unlock"></i> Enable Access
            </button>
            <?php endif; ?>
            
            <hr class="mt-4">
            
            <div class="d-grid gap-2">
                <a href="add_payment.php?student_id=<?php echo $student_id; ?>" class="btn btn-purple">
                   <i class="fa-solid fa-indian-rupee-sign"></i> Add Payment
                </a>
                <a href="send_notification.php?student_id=<?php echo $student_id; ?>" class="btn btn-info">
                    <i class="fas fa-bell"></i> Send Notification
                </a>
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Details
                </button>
            </div>
        </div>
    </div>
    
    <!-- Fee Summary and Payments (keep existing code) -->
    <div class="col-lg-8">
        <!-- Fee Summary Cards -->
        <div class="row g-3 mb-4">

<!-- Manual Points Management -->
<div class="col-lg-12 mt-4">
    <div class="table-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="text-purple mb-0"><i class="fas fa-star"></i> Manual Ranking Points</h5>
            <button class="btn btn-sm btn-purple" onclick="showAddPointsModal()">
                <i class="fas fa-plus"></i> Add Points
            </button>
        </div>
        
        <div id="manualPointsHistory">
            <!--<div class="text-center py-3">-->
            <!--    <div class="spinner-border text-purple" role="status">-->
            <!--        <span class="visually-hidden">Loading...</span>-->
            <!--    </div>-->
            <!--</div>-->
        </div>
    </div>
</div>


<!-- Add Manual Points Modal -->
<div class="modal fade" id="addPointsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-star"></i> Add Manual Points</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Manual points directly affect student ranking. Use positive values to reward, negative to penalize.
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Points *</label>
                    <input type="number" class="form-control" id="manual_points" placeholder="e.g., +10 or -5" required>
                    <small class="text-muted">Positive for reward, negative for penalty</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Reason *</label>
                    <textarea class="form-control" id="points_reason" rows="3" placeholder="Explain why these points are being awarded..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-purple" onclick="addManualPoints()">Award Points</button>
            </div>
        </div>
    </div>
</div>


<script>
// Load manual points history on page load
document.addEventListener('DOMContentLoaded', function() {
    loadManualPointsHistory();
});

function showAddPointsModal() {
    document.getElementById('manual_points').value = '';
    document.getElementById('points_reason').value = '';
    new bootstrap.Modal(document.getElementById('addPointsModal')).show();
}

function addManualPoints() {
    const points = parseInt(document.getElementById('manual_points').value);
    const reason = document.getElementById('points_reason').value.trim();
    
    if (isNaN(points) || points === 0) {
        alert('Please enter a valid points value (not zero)');
        return;
    }
    
    if (!reason) {
        alert('Please provide a reason');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_points');
    formData.append('student_id', studentId);
    formData.append('points', points);
    formData.append('reason', reason);
    
    fetch('ajax/manage_manual_points.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('addPointsModal')).hide();
            loadManualPointsHistory();
            
            // Show success message
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="fas fa-check-circle"></i> ${data.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.page-header').after(alertDiv);
            
            // Auto dismiss after 3 seconds
            setTimeout(() => alertDiv.remove(), 3000);
        } else {
            alert(data.message || 'Failed to add points');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('An error occurred');
    });
}

function loadManualPointsHistory() {
    fetch(`ajax/manage_manual_points.php?action=get_history&student_id=${studentId}`)
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            displayManualPointsHistory(data.history);
        }
    })
    .catch(err => {
        console.error('Error:', err);
        document.getElementById('manualPointsHistory').innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> Failed to load points history
            </div>
        `;
    });
}

function displayManualPointsHistory(history) {
    const container = document.getElementById('manualPointsHistory');
    
    if (history.length === 0) {
        container.innerHTML = `
            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle"></i> No manual points awarded yet.
            </div>
        `;
        return;
    }
    
    // Calculate total
    const total = history.reduce((sum, item) => sum + parseInt(item.points), 0);
    
    let html = `
        <div class="alert ${total >= 0 ? 'alert-success' : 'alert-danger'} mb-3">
            <strong>Total Manual Points: ${total > 0 ? '+' : ''}${total}</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Points</th>
                        <th>Reason</th>
                        <th>Awarded By</th>
                        <?php if (isSuperAdmin()): ?>
                        <th>Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
    `;
    
    history.forEach(item => {
        const pointsClass = item.points > 0 ? 'success' : 'danger';
        const pointsSign = item.points > 0 ? '+' : '';
        const date = new Date(item.created_at).toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        html += `
            <tr>
                <td><small>${date}</small></td>
                <td>
                    <span class="badge bg-${pointsClass}">
                        ${pointsSign}${item.points}
                    </span>
                </td>
                <td><small>${item.reason}</small></td>
                <td><small>${item.admin_name}</small></td>
                <?php if (isSuperAdmin()): ?>
                <td>
                    <button class="btn btn-sm btn-danger" onclick="deleteManualPoint(${item.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
                <?php endif; ?>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
        </div>
    `;
    
    container.innerHTML = html;
}

<?php if (isSuperAdmin()): ?>
function deleteManualPoint(pointId) {
    if (!confirm('Delete this manual point entry?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_point');
    formData.append('point_id', pointId);
    
    fetch('ajax/manage_manual_points.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadManualPointsHistory();
        } else {
            alert(data.message || 'Failed to delete');
        }
    });
}
<?php endif; ?>
</script>
            
            <!-- ADD BEFORE </div> closing row tag (around line 380) -->

    <!-- Course Topics Management -->
    
    
    <!-- Projects Management -->
    <div class="col-lg-6 mt-4">
        <div class="table-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="text-purple mb-0"><i class="fas fa-project-diagram"></i> Projects (<?php echo $projects->num_rows; ?>)</h5>
                <button class="btn btn-sm btn-purple" onclick="showAddProjectModal()">
                    <i class="fas fa-plus"></i> Add Project
                </button>
            </div>
            
            <?php if ($projects->num_rows > 0): ?>
            <div class="list-group">
                <?php while ($project = $projects->fetch_assoc()): 
                    $status_colors = [
                        'Not Started' => 'secondary',
                        'In Progress' => 'primary',
                        'Completed' => 'success',
                        'On Hold' => 'warning'
                    ];
                ?>
               <div class="list-group-item">
    <div class="d-flex justify-content-between align-items-start">
        
        <!-- LEFT -->
        <div class="flex-grow-1">
            <!-- Project Name (CLICKABLE) -->
            <a href="<?php echo htmlspecialchars($project['project_link']); ?>"
               target="_blank"
               class="fw-bold text-decoration-none text-dark">
                <?php echo htmlspecialchars($project['project_name']); ?>
            </a>

            <!-- Dates -->
            <?php if ($project['start_date'] || $project['end_date']): ?>
                <div class="text-muted small mt-1">
                    <i class="fas fa-calendar"></i>
                    <?php
                        echo $project['start_date']
                            ? date('d M Y', strtotime($project['start_date']))
                            : '—';
                        echo ' → ';
                        echo $project['end_date']
                            ? date('d M Y', strtotime($project['end_date']))
                            : '—';
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT -->
        <div class="ms-3">
            <?php if ($project['status'] === 'Completed'): ?>
                <span class="btn btn-sm btn-success disabled">
                    <i class="fas fa-check-circle"></i> Verified
                </span>
            <?php else: ?>
                <button class="btn btn-sm btn-outline-primary"
                        onclick="verifyProject(<?php echo $project['id']; ?>)">
                    <i class="fas fa-check"></i> Verify
                </button>
            <?php endif; ?>
        </div>

    </div>
</div>
<script>
function verifyProject(projectId) {
    if (!confirm('Mark this project as Completed?')) return;

    const formData = new FormData();
    formData.append('action', 'verify_project');
    formData.append('project_id', projectId);

    fetch('ajax/manage_projects.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload(); // refresh UI
        } else {
            alert('Verification failed');
        }
    });
}
</script>

                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No projects assigned yet.
            </div>
            <?php endif; ?>
        </div>
    </div>
    
<!-- ============================================ -->
<!-- ATTENDANCE REPORT WITH FILTERS -->
<!-- ============================================ -->
<div class="col-12 mt-4">
    <div class="table-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="text-purple mb-0"><i class="fas fa-calendar-check"></i> Attendance Report</h5>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-sm btn-outline-purple" onclick="loadAttendanceReport(7)" id="btn7">
                        Last 7 Days
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-purple" onclick="loadAttendanceReport(15)" id="btn15">
                        Last 15 Days
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-purple active" onclick="loadAttendanceReport(30)" id="btn30">
                        Last 30 Days
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-purple" onclick="showCustomDaysInput()" id="btnCustom">
                        Custom
                    </button>
                </div>
                <div id="customDaysInput" style="display: none;">
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control" id="customDaysValue" min="1" max="365" placeholder="Days" style="width: 80px;">
                        <button class="btn btn-purple" onclick="loadCustomDays()">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn btn-secondary" onclick="hideCustomDaysInput()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Loading Indicator -->
        <div id="attendanceLoading" class="text-center py-4" style="display: none;">
            <div class="spinner-border text-purple" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
        
        <!-- Chart -->
        <div class="mb-4" id="chartContainer">
            <canvas id="attendanceChart" height="140"></canvas>
        </div>
        
        <!-- Stats -->
        <div class="row g-3" id="attendanceStats">
            <div class="col-md-4">
                <div class="text-center p-3 bg-light rounded">
                    <h4 class="text-success mb-0" id="presentDays">-</h4>
                    <small class="text-muted">Present Days</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-3 bg-light rounded">
                    <h4 class="text-danger mb-0" id="absentDays">-</h4>
                    <small class="text-muted">Absent Days</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-3 bg-light rounded">
                    <h4 class="text-purple mb-0" id="attendanceRate">-</h4>
                    <small class="text-muted">Attendance Rate</small>
                </div>
            </div>
        </div>
        
        <!-- Period Info -->
        <div class="mt-3">
            <small class="text-muted" id="periodInfo">
                <i class="fas fa-calendar"></i> Loading...
            </small>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const studentIdForAttendance = <?php echo $student_id; ?>;
let attendanceChartInstance = null;
let currentDays = 30;

function loadAttendanceReport(days) {
    currentDays = days;
    
    // Show loading
    document.getElementById('attendanceLoading').style.display = 'block';
    document.getElementById('chartContainer').style.display = 'none';
    
    // Update active button
    document.querySelectorAll('.btn-group button').forEach(btn => btn.classList.remove('active'));
    if (days === 7) document.getElementById('btn7').classList.add('active');
    else if (days === 15) document.getElementById('btn15').classList.add('active');
    else if (days === 30) document.getElementById('btn30').classList.add('active');
    else document.getElementById('btnCustom').classList.add('active');
    
    // Hide custom input if showing
    hideCustomDaysInput();
    
    // Fetch data
    fetch(`ajax/get_student_attendance.php?student_id=${studentIdForAttendance}&days=${days}`)
        .then(r => {
            if (!r.ok) throw new Error('Network response was not ok');
            return r.json();
        })
        .then(data => {
            console.log('Attendance data:', data); // Debug
            
            if (data.success) {
                updateAttendanceChart(data);
                updateAttendanceStats(data);
                updatePeriodInfo(days, data);
            } else {
                console.error('Error:', data.message);
                alert('Error loading attendance data: ' + (data.message || 'Unknown error'));
            }
            
            // Hide loading
            document.getElementById('attendanceLoading').style.display = 'none';
            document.getElementById('chartContainer').style.display = 'block';
        })
        .catch(err => {
            console.error('Fetch error:', err);
            alert('Failed to load attendance data. Check console for details.');
            
            // Hide loading
            document.getElementById('attendanceLoading').style.display = 'none';
            document.getElementById('chartContainer').style.display = 'block';
        });
}

function showCustomDaysInput() {
    document.getElementById('customDaysInput').style.display = 'block';
    document.getElementById('customDaysValue').focus();
    
    // Deactivate other buttons
    document.querySelectorAll('.btn-group button').forEach(btn => btn.classList.remove('active'));
    document.getElementById('btnCustom').classList.add('active');
}

function hideCustomDaysInput() {
    document.getElementById('customDaysInput').style.display = 'none';
    document.getElementById('customDaysValue').value = '';
}

function loadCustomDays() {
    const customDays = parseInt(document.getElementById('customDaysValue').value);
    
    if (!customDays || customDays < 1) {
        alert('Please enter a valid number of days (minimum 1)');
        return;
    }
    
    if (customDays > 365) {
        alert('Maximum 365 days allowed');
        return;
    }
    
    loadAttendanceReport(customDays);
}

// Allow Enter key to submit custom days
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('customDaysValue')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            loadCustomDays();
        }
    });
});

function updateAttendanceChart(data) {
    const ctx = document.getElementById('attendanceChart');

    if (attendanceChartInstance) {
        attendanceChartInstance.destroy();
    }

    attendanceChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.dates,
            datasets: [
                {
                    label: 'Present',
                    data: data.present,
                    backgroundColor: 'rgba(16, 185, 129, 0.9)',
                    borderRadius: 2,
                    barThickness: 10
                },
                {
                    label: 'Absent',
                    data: data.absent,
                    backgroundColor: 'rgba(239, 68, 68, 0.9)',
                    borderRadius: 2,
                    barThickness: 10
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        boxWidth: 14,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.raw === 1 ? ctx.dataset.label : '';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 1,
                    ticks: {
                        stepSize: 1,
                        callback: v => v === 1 ? 'Present' : 'Absent'
                    }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
}


function updateAttendanceStats(data) {
    document.getElementById('presentDays').textContent = data.present_count;
    document.getElementById('absentDays').textContent = data.absent_count;
    document.getElementById('attendanceRate').textContent = data.attendance_rate + '%';
}

function updatePeriodInfo(days, data) {
    const startDate = new Date();
    startDate.setDate(startDate.getDate() - (days - 1));
    const endDate = new Date();
    
    const formatDate = (date) => {
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return `${date.getDate()} ${months[date.getMonth()]} ${date.getFullYear()}`;
    };
    
    const periodText = `<i class="fas fa-calendar"></i> Showing: <strong>${formatDate(startDate)}</strong> to <strong>${formatDate(endDate)}</strong> (${days} days)`;
    document.getElementById('periodInfo').innerHTML = periodText;
}

// Load default (30 days) on page load
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure page is fully loaded
    setTimeout(() => {
        loadAttendanceReport(30);
    }, 500);
});
</script>


</div>


<!-- Add Topic Modal -->
<div class="modal fade" id="addTopicModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Add Course Topic</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Topic Name *</label>
                    <input type="text" class="form-control" id="topic_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="topic_description" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-purple" onclick="addTopic()">Add Topic</button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Project Modal -->
<div class="modal fade" id="projectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="projectModalTitle"><i class="fas fa-plus"></i> Add Project</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="project_id">
                <div class="mb-3">
                    <label class="form-label">Project Name *</label>
                    <input type="text" class="form-control" id="project_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="project_description" rows="2"></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="project_start_date">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" id="project_end_date">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Status *</label>
                    <select class="form-select" id="project_status">
                        <option value="Not Started">Not Started</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="On Hold">On Hold</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Remarks</label>
                    <textarea class="form-control" id="project_remarks" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-purple" onclick="saveProject()">Save Project</button>
            </div>
        </div>
    </div>
</div>

<script>
const studentId = <?php echo $student_id; ?>;

// Topics Management
function showAddTopicModal() {
    document.getElementById('topic_name').value = '';
    document.getElementById('topic_description').value = '';
    new bootstrap.Modal(document.getElementById('addTopicModal')).show();
}

function addTopic() {
    const topicName = document.getElementById('topic_name').value;
    const description = document.getElementById('topic_description').value;
    
    if (!topicName) {
        alert('Topic name is required');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_topic');
    formData.append('student_id', studentId);
    formData.append('topic_name', topicName);
    formData.append('description', description);
    
    fetch('ajax/manage_topics.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to add topic');
        }
    });
}

function toggleTopic(topicId, checked) {
    const formData = new FormData();
    formData.append('action', 'toggle_topic');
    formData.append('topic_id', topicId);
    formData.append('status', checked ? 'Completed' : 'Pending');
    
    fetch('ajax/manage_topics.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function deleteTopic(topicId) {
    if (!confirm('Delete this topic?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_topic');
    formData.append('topic_id', topicId);
    
    fetch('ajax/manage_topics.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// Projects Management
function showAddProjectModal() {
    document.getElementById('projectModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Project';
    document.getElementById('project_id').value = '';
    document.getElementById('project_name').value = '';
    document.getElementById('project_description').value = '';
    document.getElementById('project_start_date').value = '';
    document.getElementById('project_end_date').value = '';
    document.getElementById('project_status').value = 'Not Started';
    document.getElementById('project_remarks').value = '';
    new bootstrap.Modal(document.getElementById('projectModal')).show();
}

function editProject(project) {
    document.getElementById('projectModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Project';
    document.getElementById('project_id').value = project.id;
    document.getElementById('project_name').value = project.project_name;
    document.getElementById('project_description').value = project.description || '';
    document.getElementById('project_start_date').value = project.start_date || '';
    document.getElementById('project_end_date').value = project.end_date || '';
    document.getElementById('project_status').value = project.status;
    document.getElementById('project_remarks').value = project.remarks || '';
    new bootstrap.Modal(document.getElementById('projectModal')).show();
}

function saveProject() {
    const projectId = document.getElementById('project_id').value;
    const projectName = document.getElementById('project_name').value;
    
    if (!projectName) {
        alert('Project name is required');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', projectId ? 'update_project' : 'add_project');
    if (projectId) formData.append('project_id', projectId);
    formData.append('student_id', studentId);
    formData.append('project_name', projectName);
    formData.append('description', document.getElementById('project_description').value);
    formData.append('start_date', document.getElementById('project_start_date').value);
    formData.append('end_date', document.getElementById('project_end_date').value);
    formData.append('status', document.getElementById('project_status').value);
    formData.append('remarks', document.getElementById('project_remarks').value);
    
    fetch('ajax/manage_projects.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to save project');
        }
    });
}

function deleteProject(projectId) {
    if (!confirm('Delete this project?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_project');
    formData.append('project_id', projectId);
    
    fetch('ajax/manage_projects.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}
</script>
        </div>
        
        <!-- Payment History -->
        <div class="table-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="text-purple mb-0"><i class="fas fa-history"></i> Payment History</h5>
                <?php if ($payments->num_rows > 0): ?>
                <button class="btn btn-sm btn-secondary" onclick="exportTableToCSV('paymentHistoryTable', 'student_payments.csv')">
                    <i class="fas fa-download"></i> Export
                </button>
                <?php endif; ?>
            </div>
            
            <?php if ($payments->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover" id="paymentHistoryTable">
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Received By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($payment = $payments->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                            <td><span class="badge bg-success fs-6">₹<?php echo number_format($payment['amount_paid'], 2); ?></span></td>
                            <td><span class="badge bg-info"><?php echo $payment['payment_method']; ?></span></td>
                            <td><small><?php echo htmlspecialchars($payment['admin_name']); ?></small></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="window.open('receipt.php?id=<?php echo $payment['id']; ?>', '_blank')">
                                    <i class="fas fa-receipt"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> No payments recorded yet.
                <a href="add_payment.php?student_id=<?php echo $student_id; ?>" class="alert-link">Add first payment</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Student Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editStudentForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_student">
                    
                    <h6 class="text-purple mb-3">Personal Information</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($student['full_name']); ?>" required>
                        </div>
                         <hr>
        
       
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone *</label>
                            <input type="tel" class="form-control" name="phone" value="<?php echo htmlspecialchars($student['phone']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($student['email']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="birthdate" value="<?php echo $student['birthdate']; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"><?php echo htmlspecialchars($student['address']); ?></textarea>
                    </div>
                    
                    <hr>
                    <h6 class="text-purple mb-3">Course & Fees Information</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">Category *</label>
                        <select class="form-select" name="category_id" id="edit_category" required>
                            <option value="">Select Category</option>
                            <?php 
                            $categories->data_seek(0);
                            while ($cat = $categories->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo ($cat['id'] == $student['category_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Course *</label>
                        <select class="form-select" name="course_id" id="edit_course" required>
                            <option value="">Select Course</option>
                            <?php 
                            $all_courses->data_seek(0);
                            while ($course = $all_courses->fetch_assoc()): 
                            ?>
                            <option value="<?php echo $course['id']; ?>" 
                                    data-category="<?php echo $course['category_id']; ?>"
                                    <?php echo ($course['id'] == $student['course_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Duration (Months) *</label>
                            <select class="form-select" name="duration_months" id="edit_duration" required>
                                <option value="">Select Duration</option>
                                <?php foreach ([3, 6, 9, 12, 18, 24] as $d): ?>
                                <option value="<?php echo $d; ?>" <?php echo ($d == $student['duration_months']) ? 'selected' : ''; ?>>
                                    <?php echo $d; ?> Months
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Fees (₹) *</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" class="form-control" name="total_fees" id="edit_total_fees" 
                                       value="<?php echo $student['total_fees']; ?>" step="0.01" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> Changing course fees will not affect existing payment records. Only the pending amount calculation will be updated.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-purple">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Photo Modal (keep existing) -->
<div class="modal fade" id="uploadPhotoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-camera"></i> <?php echo $has_photo ? 'Change' : 'Upload'; ?> Student Photo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="student_photo" class="form-label">Select Photo</label>
                        <input type="file" 
                               class="form-control" 
                               id="student_photo" 
                               name="student_photo" 
                               accept="image/jpeg,image/jpg,image/png,image/gif"
                               required>
                        <div class="form-text">
                            <i class="fas fa-info-circle"></i> Accepted formats: JPG, JPEG, PNG, GIF (Max 5MB)
                        </div>
                    </div>
                    
                    <div id="imagePreview" class="text-center" style="display: none;">
                        <img id="previewImg" src="" alt="Preview" class="img-thumbnail" style="max-width: 100%; max-height: 300px;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload Photo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>



<!-- Portal Access Modals (keep existing Enable/Reset/Disable modals) -->
<!-- Enable Login Modal -->
<div class="modal fade" id="enableLoginModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-unlock"></i> Enable Student Portal Access</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="student_portal_actions.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="enable_login">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> This will create login credentials for the student.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Username *</label>
                        <input type="text" class="form-control" name="username" 
                               value="<?php echo strtolower(str_replace(' ', '', $student['student_code'])); ?>" required>
                        <small class="text-muted">Student will use this to login</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Password *</label>
                        <input type="text" class="form-control" name="password" 
                               value="<?php echo substr($student['phone'], -6); ?>" required>
                        <small class="text-muted">Default: Last 6 digits of phone number</small>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Important:</strong> Share these credentials with the student securely.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Enable Portal Access</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sync"></i> Reset Student Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="student_portal_actions.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    
                    <div class="alert alert-warning">
                        Resetting password for: <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Password *</label>
                        <input type="text" class="form-control" name="new_password" 
                               value="<?php echo substr($student['phone'], -6); ?>" required>
                        <small class="text-muted">Recommended: Last 6 digits of phone</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Disable Login Modal -->
<div class="modal fade" id="disableLoginModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-lock"></i> Disable Portal Access</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="student_portal_actions.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="disable_login">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Warning!</strong><br>
                        This will disable portal access for <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>.
                        The student will not be able to login.
                    </div>
                    
                    <p>Are you sure you want to continue?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Yes, Disable Access</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hold Student Modal -->
<div class="modal fade" id="holdStudentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-pause-circle"></i> Hold Student</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="student_hold_actions.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="hold">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Warning!</strong><br>
                        This will pause <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>'s enrollment.
                    </div>
                    
                    <h6>What happens when you hold a student?</h6>
                    <ul>
                        <li>Student status changes to <span class="badge bg-warning">HOLD</span></li>
                        <li>Attendance tracking is paused (won't count as present/absent)</li>
                        <li>Ranking points calculation is paused</li>
                        <li>Course duration timer is paused</li>
                        <li>Student won't appear in daily attendance or active reports</li>
                        <li>Student portal access remains active</li>
                    </ul>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Hold (Optional)</label>
                        <textarea class="form-control" name="hold_reason" rows="3" placeholder="e.g., Medical leave, Personal reasons, Financial issues..."></textarea>
                    </div>
                    
                    <p class="mb-0"><strong>Note:</strong> You can resume the student anytime. The enrollment will continue from where it was paused.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-pause"></i> Hold Student
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Resume Student Modal -->
<div class="modal fade" id="resumeStudentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-play"></i> Resume Student</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <strong>Confirmation</strong><br>
                    Resume enrollment for <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>?
                </div>
                
                <h6>What happens when you resume?</h6>
                <ul>
                    <li>Student status changes back to <span class="badge bg-success">ACTIVE</span></li>
                    <li>Attendance tracking resumes</li>
                    <li>Ranking points calculation resumes</li>
                    <li>Course continues from where it was paused</li>
                    <li>Student appears in all active reports</li>
                </ul>
                
                <div class="alert alert-info">
                    <strong>Hold Period:</strong> 
                    <?php 
                    $hold_days = (new DateTime())->diff(new DateTime($student['hold_start_date']))->days;
                    echo $hold_days;
                    ?> days will be added to total hold history.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="student_hold_actions.php?action=resume&student_id=<?php echo $student_id; ?>" class="btn btn-success">
                    <i class="fas fa-play"></i> Resume Student
                </a>
            </div>
        </div>
    </div>
</div>
<script>
// Image preview functionality
document.getElementById('student_photo').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        // Validate file size
        if (file.size > 5000000) {
            alert('File size must be less than 5MB');
            this.value = '';
            document.getElementById('imagePreview').style.display = 'none';
            return;
        }
        
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            alert('Only JPG, JPEG, PNG, and GIF files are allowed');
            this.value = '';
            document.getElementById('imagePreview').style.display = 'none';
            return;
        }
        
        // Show preview
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('previewImg').src = e.target.result;
            document.getElementById('imagePreview').style.display = 'block';
        }
        reader.readAsDataURL(file);
    }
});
</script>

<?php include 'includes/footer.php'; ?>