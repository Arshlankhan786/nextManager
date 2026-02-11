<?php
include 'includes/header.php';

// Get student's group
$group = $conn->query("
    SELECT sg.id, sg.group_name, c.name as course_name
    FROM student_group_members sgm
    JOIN student_groups sg ON sgm.group_id = sg.id
    JOIN courses c ON sg.course_id = c.id
    WHERE sgm.student_id = {$student['id']}
")->fetch_assoc();

if (!$group) {
    ?>
    <div class="page-header">
        <h2><i class="fas fa-chart-line text-purple"></i> Course Progress</h2>
    </div>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> You are not assigned to any group yet. Contact admin.
    </div>
    <?php
    include 'includes/footer.php';
    exit();
}

// Get topic progress for group
$topics = $conn->query("
    SELECT ct.id, ct.topic_name, ct.order_index,
           gtp.status, gtp.start_date, gtp.end_date,
           (SELECT COUNT(*) FROM course_sub_topics WHERE topic_id = ct.id) as sub_count
    FROM course_topics ct
    JOIN group_topic_progress gtp ON ct.id = gtp.topic_id
    WHERE gtp.group_id = {$group['id']}
    ORDER BY ct.order_index ASC
");

// Count stats
$completed = 0;
$active = 0;
$upcoming = 0;
$total = $topics->num_rows;

$topics->data_seek(0);
while ($t = $topics->fetch_assoc()) {
    if ($t['status'] === 'completed') $completed++;
    elseif ($t['status'] === 'active') $active++;
    else $upcoming++;
}

$progress_percentage = $total > 0 ? ($completed / $total) * 100 : 0;
?>

<div class="page-header">
    <h2><i class="fas fa-chart-line text-purple"></i> Course Progress</h2>
    <p class="text-muted mb-0">
        Group: <strong><?php echo htmlspecialchars($group['group_name']); ?></strong> 
        | Course: <strong><?php echo htmlspecialchars($group['course_name']); ?></strong>
    </p>
</div>

<!-- Progress Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card dashboard-card">
            <div class="card-body text-center">
                <h3 class="text-purple mb-0"><?php echo $total; ?></h3>
                <small class="text-muted">Total Topics</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card dashboard-card">
            <div class="card-body text-center">
                <h3 class="text-success mb-0"><?php echo $completed; ?></h3>
                <small class="text-muted">Completed</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card dashboard-card">
            <div class="card-body text-center">
                <h3 class="text-primary mb-0"><?php echo $active; ?></h3>
                <small class="text-muted">Active</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card dashboard-card">
            <div class="card-body text-center">
                <h3 class="text-info mb-0"><?php echo number_format($progress_percentage, 1); ?>%</h3>
                <small class="text-muted">Progress</small>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-info" style="width: <?php echo $progress_percentage; ?>%"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Topics List -->
<div class="table-card">
    <h5 class="text-purple mb-3"><i class="fas fa-list-check"></i> Course Topics</h5>
    
    <?php if ($total > 0): ?>
    <div class="topic-progress-flow">
        <?php 
        $topics->data_seek(0);
        $index = 0;
        while ($topic = $topics->fetch_assoc()): 
            $index++;
            
            $status_class = 'secondary';
            $status_icon = 'fa-clock';
            $status_text = 'Upcoming';
            $card_class = '';
            
            if ($topic['status'] === 'active') {
                $status_class = 'primary';
                $status_icon = 'fa-play';
                $status_text = 'Currently Learning';
                $card_class = 'border-primary';
            } elseif ($topic['status'] === 'completed') {
                $status_class = 'success';
                $status_icon = 'fa-check-circle';
                $status_text = 'Completed';
                $card_class = 'border-success';
            }
        ?>
        <div class="card mb-3 <?php echo $card_class; ?>">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <div class="topic-number">
                        <div class="badge bg-<?php echo $status_class; ?> p-3" style="font-size: 1.2rem;">
                            <?php echo $index; ?>
                        </div>
                    </div>
                    
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <i class="fas <?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                            </span>
                            <h6 class="mb-0"><?php echo htmlspecialchars($topic['topic_name']); ?></h6>
                        </div>
                        
                        <?php if ($topic['start_date']): ?>
                        <small class="text-muted">
                            <i class="fas fa-calendar"></i> Started: <?php echo date('d M Y', strtotime($topic['start_date'])); ?>
                        </small>
                        <?php endif; ?>
                        
                        <?php if ($topic['end_date']): ?>
                        <small class="text-success ms-3">
                            <i class="fas fa-flag-checkered"></i> Completed: <?php echo date('d M Y', strtotime($topic['end_date'])); ?>
                        </small>
                        <?php endif; ?>
                        
                        <?php if ($topic['sub_count'] > 0 && $topic['status'] !== 'upcoming'): ?>
                        <div class="mt-2">
                            <button class="btn btn-sm btn-outline-info" onclick="loadSubTopics(<?php echo $topic['id']; ?>, '<?php echo htmlspecialchars($topic['topic_name']); ?>')">
                                <i class="fas fa-list-ul"></i> View Sub-Topics (<?php echo $topic['sub_count']; ?>)
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
    <?php else: ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> No topics have been added to your course yet.
    </div>
    <?php endif; ?>
</div>

<!-- Sub-Topics Modal -->
<div class="modal fade" id="subTopicsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-list-ul"></i> <span id="subTopicTitle"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="subTopicsContent">
                <div class="text-center">
                    <div class="spinner-border text-purple"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function loadSubTopics(topicId, topicName) {
    document.getElementById('subTopicTitle').textContent = topicName;
    
    fetch(`../admin/ajax/manage_course_topics.php?action=get_subtopics&topic_id=${topicId}`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('subTopicsContent');
            
            if (data.success && data.subtopics.length > 0) {
                let html = '<div class="list-group">';
                data.subtopics.forEach((sub, index) => {
                    html += `
                    <div class="list-group-item">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary">${index + 1}</span>
                            <span>${sub.sub_topic_name}</span>
                        </div>
                    </div>`;
                });
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="alert alert-info">No sub-topics defined</div>';
            }
        });
    
    new bootstrap.Modal(document.getElementById('subTopicsModal')).show();
}
</script>

<style>
.topic-progress-flow {
    position: relative;
}

.topic-progress-flow::before {
    content: '';
    position: absolute;
    left: 32px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e5e7eb;
    z-index: 0;
}

.topic-progress-flow .card {
    position: relative;
    z-index: 1;
}

.topic-number {
    min-width: 64px;
}
</style>

<?php include 'includes/footer.php'; ?>