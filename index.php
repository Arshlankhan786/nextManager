<?php
// Include database connection
require_once 'admin/config/database.php';

// Fetch active courses with category information
$featured_courses = $conn->query("
    SELECT c.*, cat.name as category_name, 
           (SELECT MIN(fee_amount) FROM course_fees WHERE course_id = c.id) as min_fee,
           (SELECT MAX(duration_months) FROM course_fees WHERE course_id = c.id) as max_duration
    FROM courses c
    JOIN categories cat ON c.category_id = cat.id
    WHERE c.status = 'Active'
    ORDER BY c.created_at DESC
    LIMIT 6
");

// Get statistics
$stats = [];
$result = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'Active'");
$stats['students'] = $result->fetch_assoc()['count'];

$result = $conn->query("SELECT COUNT(*) as count FROM courses WHERE status = 'Active'");
$stats['courses'] = $result->fetch_assoc()['count'];

$result = $conn->query("SELECT COUNT(*) as count FROM categories WHERE status = 'Active'");
$stats['categories'] = $result->fetch_assoc()['count'];

$total_enrolled = $conn->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
$completed = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'Completed'")->fetch_assoc()['count'];
$stats['success_rate'] = $total_enrolled > 0 ? round(($completed / $total_enrolled) * 100) : 95;

$page_title = "Home - Next Academy";
?>
<?php include 'includes/navbar.php'; ?>

    <!-- Hero Slider -->
    <section class="hero-slider">
        <div id="heroCarousel" class="carousel slide carousel-fade" data-bs-ride="carousel" data-bs-interval="5000">
            <div class="carousel-indicators">
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="0" class="active"></button>
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="1"></button>
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="2"></button>
            </div>
            
            <div class="carousel-inner">
                <!-- Slide 1 -->
                <div class="carousel-item active" style="background: url('assets/images/robot.jpg') center/cover;">
                    <div class="hero-overlay"></div>
                    <div class="hero-content">
                        <div class="container">
                            <div class="row">
                                <div class="col-lg-8">
                                    <h1 class="hero-title">Master MERN Stack Development</h1>
                                    <p class="hero-subtitle">Build modern web applications with MongoDB, Express, React, and Node.js. Learn from industry experts with hands-on projects.</p>
                                    <div class="hero-buttons">
                                        <a href="courses.php" class="btn btn-hero btn-hero-primary">Explore Courses</a>
                                        <a href="contact.php" class="btn btn-hero btn-hero-outline">Get Started</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Slide 2 -->
                <div class="carousel-item" style="background: url('assets/images/robot2.jpg') center/cover;">
                    <div class="hero-overlay"></div>
                    <div class="hero-content">
                        <div class="container">
                            <div class="row">
                                <div class="col-lg-8">
                                    <h1 class="hero-title">Backend Development Excellence</h1>
                                    <p class="hero-subtitle">Become a backend expert with Node.js, Python, and PHP. Build scalable APIs and robust server-side applications.</p>
                                    <div class="hero-buttons">
                                        <a href="courses.php" class="btn btn-hero btn-hero-primary">View Programs</a>
                                        <a href="about.php" class="btn btn-hero btn-hero-outline">Learn More</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Slide 3 -->
                <div class="carousel-item" style="background: url('assets/images/Academy.jpg') center/cover;">
                        <div class="hero-overlay"></div>
                    <div class="hero-content">
                        <div class="container">
                            <div class="row">
                                <div class="col-lg-8">
                                    <h1 class="hero-title">Transform Your Career With Next Academy</h1>
                                    <p class="hero-subtitle">Join 100+ students who are already learning and building amazing projects. Start your journey today!</p>
                                    <div class="hero-buttons">
                                        <a href="contact.php" class="btn btn-hero btn-hero-primary">Contact Us</a>
                                        <a href="gallery.php" class="btn btn-hero btn-hero-outline">View Gallery</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Next</span>
            </button>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <h2 class="section-title" data-aos="fade-up">Why Choose Next Academy?</h2>
            <p class="section-subtitle" data-aos="fade-up" data-aos-delay="100">
                We provide world-class education with industry-relevant curriculum
            </p>
            
            <div class="row g-4">
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-laptop-code"></i>
                        </div>
                        <h3 class="feature-title">Hands-On Learning</h3>
                        <p class="feature-description">
                            Learn by doing with real-world projects and practical assignments that build your portfolio.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="feature-title">Expert Instructors</h3>
                        <p class="feature-description">
                            Learn from industry professionals with years of experience in software development.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="400">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3 class="feature-title">Flexible Schedule</h3>
                        <p class="feature-description">
                            Choose from morning and evening batches that fit your schedule and lifestyle.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="500">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-certificate"></i>
                        </div>
                        <h3 class="feature-title">Industry Certification</h3>
                        <p class="feature-description">
                            Earn recognized certificates that boost your resume and career prospects.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Courses Section -->
    <section class="courses-section">
        <div class="container">
            <h2 class="section-title" data-aos="fade-up">Featured Courses</h2>
            <p class="section-subtitle" data-aos="fade-up" data-aos-delay="100">
                Choose from our wide range of professional courses
            </p>
            
            <div class="row g-4">
                <?php 
                $delay = 200;
                while ($course = $featured_courses->fetch_assoc()): 
                    $min_fee = $course['min_fee'] ? number_format($course['min_fee'], 0) : '15,000';
                    $max_duration = $course['max_duration'] ?: '12';
                    
                    // Assign icons based on category
                    $icons = [
                        'Web Development' => 'fa-code',
                        'Graphics Design' => 'fa-palette',
                        'Digital Marketing' => 'fa-bullhorn',
                        'Mobile Development' => 'fa-mobile-alt'
                    ];
                    $icon = $icons[$course['category_name']] ?? 'fa-book';
                ?>
                <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo $delay; ?>">
                    <div class="course-card">
                        <div class="course-image">
                            <i class="fas <?php echo $icon; ?> course-icon"></i>
                            <span class="course-badge"><?php echo htmlspecialchars($course['category_name']); ?></span>
                        </div>
                        <div class="course-body">
                            <div class="course-category"><?php echo htmlspecialchars($course['category_name']); ?></div>
                            <h3 class="course-title"><?php echo htmlspecialchars($course['name']); ?></h3>
                            <p class="course-description">
                                <?php echo htmlspecialchars(substr($course['description'] ?: 'Professional course with industry-standard curriculum and hands-on projects.', 0, 100)); ?>...
                            </p>
                            <div class="course-meta">
                                <div class="course-duration">
                                    <i class="fas fa-clock me-1"></i>
                                    Up to <?php echo $max_duration; ?> months
                                </div>
                                
                            </div>
                        </div>
                    </div>
                </div>
                <?php 
                $delay += 100;
                endwhile; 
                ?>
            </div>
            
            <div class="text-center mt-5" data-aos="fade-up">
                <a href="courses.php" class="btn btn-hero btn-hero-primary">
                    View All Courses <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="container">
            <div class="row">
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['students']; ?>+</div>
                        <div class="stat-label">Active Students</div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['courses']; ?>+</div>
                        <div class="stat-label">Professional Courses</div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="300">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['categories'] * 5; ?>+</div>
                        <div class="stat-label">Expert Instructors</div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="400">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['success_rate']; ?>%</div>
                        <div class="stat-label">Success Rate</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <h2 class="cta-title" data-aos="fade-up">Ready to Start Learning?</h2>
            <p class="cta-description" data-aos="fade-up" data-aos-delay="100">
                Join thousands of students who have transformed their careers with Next Academy
            </p>
            <div data-aos="fade-up" data-aos-delay="200">
                <a href="contact.php" class="btn btn-hero me-3"  style="background: var(--primary-purple); color: white;">
                    <i class="fas fa-phone me-2"></i> Contact Us
                </a>
                <a href="courses.php" class="btn btn-hero" style="background: var(--primary-purple); color: white;">
                    <i class="fas fa-book me-2"></i> Browse Courses
                </a>
            </div>
        </div>
    </section>

<?php include 'includes/footer.php'; ?>