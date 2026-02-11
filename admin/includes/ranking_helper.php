<?php
/**
 * SINGLE SOURCE OF TRUTH FOR STUDENT RANKING
 * Use this helper everywhere - DO NOT duplicate ranking logic
 * 
 * Location: /admin/includes/ranking_helper.php
 */

/**
 * Get monthly ranking for all active students
 * @param mysqli $conn Database connection
 * @param string $month_start Start date (Y-m-d)
 * @param string $month_end End date (Y-m-d)
 * @return array Associative array [student_id => rank data]
 */
function getMonthlyRanking($conn, $month_start, $month_end) {
    $ranking_query = $conn->query("
        SELECT 
            s.id,
            s.full_name,
            s.photo,
            
            -- Payment points (10 if paid this month)
            (CASE 
                WHEN EXISTS(
                    SELECT 1 FROM payments p 
                    WHERE p.student_id = s.id 
                    AND p.payment_date BETWEEN '$month_start' AND '$month_end'
                ) THEN 10 ELSE 0 
            END) as payment_points,
            
            -- Project points (15 for Web Dev, 6 for others)
            (SELECT COUNT(*) 
             FROM student_projects sp 
             WHERE sp.student_id = s.id 
             AND sp.status = 'Completed'
            ) * (CASE WHEN cat.name = 'Web Development' THEN 15 ELSE 6 END) as project_points,
            
            -- Attendance points (1 per day this month)
            (SELECT COUNT(*) 
             FROM student_attendance sa 
             WHERE sa.student_id = s.id 
             AND sa.status = 'Present'
             AND sa.attendance_date BETWEEN '$month_start' AND '$month_end'
            ) as attendance_points,
            
            -- Manual points (cumulative, no date filter)
            COALESCE((SELECT SUM(points) 
             FROM student_manual_points smp 
             WHERE smp.student_id = s.id
            ), 0) as manual_points,
            
            -- TOTAL POINTS
            (
                (CASE 
                    WHEN EXISTS(
                        SELECT 1 FROM payments p 
                        WHERE p.student_id = s.id 
                        AND p.payment_date BETWEEN '$month_start' AND '$month_end'
                    ) THEN 10 ELSE 0 
                END) +
                (SELECT COUNT(*) 
                 FROM student_projects sp 
                 WHERE sp.student_id = s.id 
                 AND sp.status = 'Completed'
                ) * (CASE WHEN cat.name = 'Web Development' THEN 15 ELSE 6 END) +
                (SELECT COUNT(*) 
                 FROM student_attendance sa 
                 WHERE sa.student_id = s.id 
                 AND sa.status = 'Present'
                 AND sa.attendance_date BETWEEN '$month_start' AND '$month_end'
                ) +
                COALESCE((SELECT SUM(points) 
                 FROM student_manual_points smp 
                 WHERE smp.student_id = s.id
                ), 0)
            ) as total_points
            
        FROM students s
        JOIN courses c ON s.course_id = c.id
        JOIN categories cat ON s.category_id = cat.id
        WHERE s.status = 'Active' AND s.login_enabled = 1
        ORDER BY total_points DESC, s.full_name ASC
    ");
    
    $ranking = [];
    $rank = 1;
    
    while ($row = $ranking_query->fetch_assoc()) {
        $ranking[$row['id']] = [
            'rank' => $rank,
            'total_points' => $row['total_points'],
            'payment_points' => $row['payment_points'],
            'project_points' => $row['project_points'],
            'attendance_points' => $row['attendance_points'],
            'manual_points' => $row['manual_points'],
            'full_name' => $row['full_name'],
            'photo' => $row['photo']
        ];
        $rank++;
    }
    
    return $ranking;
}

/**
 * Get rank for specific student
 * @param array $ranking Ranking array from getMonthlyRanking()
 * @param int $student_id Student ID
 * @return int Rank number (0 if not ranked)
 */
function getStudentRank($ranking, $student_id) {
    return $ranking[$student_id]['rank'] ?? 0;
}

/**
 * Get total active students count
 * @param mysqli $conn Database connection
 * @return int Total count
 */
function getTotalActiveStudents($conn) {
    $result = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'Active' AND login_enabled = 1");
    return $result->fetch_assoc()['count'];
}
?>