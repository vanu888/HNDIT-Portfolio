<?php
// 1. LOAD DEPENDENCIES
require_once 'db_connect.php';
require_once 'functions.php';

// --- FALLBACK: Ensure Logging Works ---
if (!function_exists('logActivity')) {
    function logActivity($conn, $userId, $action, $details = '') {
        try {
            $ip = $_SERVER['REMOTE_ADDR'];
            $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $userId, $action, $details, $ip);
            $stmt->execute();
            // Auto-Cleanup logs > 1 year
            $conn->query("DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 1 YEAR");
        } catch (Exception $e) { }
    }
}

// 2. HANDLE ACTIONS (POST REQUESTS)

// --- Login Logic ---
if (isset($_POST['login'])) {
    try {
        checkCsrf();
        $u = trim($_POST['username']);
        $p = $_POST['password'];
        
        $stmt = $conn->prepare("SELECT id, username, password, role, name FROM users WHERE username = ?");
        $stmt->bind_param("s", $u);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            if (password_verify($p, $row['password'])) { 
                session_regenerate_id(true); 
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['name'] = $row['name'];
                
                logActivity($conn, $row['id'], 'LOGIN', 'User logged in');
                setFlash('success', "Welcome back, " . $row['name']);
                header("Location: index.php?page=dashboard"); 
                exit();
            }
        }
        setFlash('error', 'Invalid Username or Password');
    } catch (Exception $e) { setFlash('error', 'System Error'); }
    header("Location: index.php?page=login"); 
    exit();
}

// --- Forgot Password: Request Link ---
if (isset($_POST['request_reset'])) {
    try {
        checkCsrf();
        $email = trim($_POST['email']);
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $token = bin2hex(random_bytes(32));
            $expires = date("Y-m-d H:i:s", strtotime('+1 hour'));
            $stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $email, $token, $expires);
            $stmt->execute();
            
            $link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/index.php?page=reset_password&token=$token";
            $message = "Hello {$user['name']},<br>Reset password link (1 hr valid):<br><a href='$link'>Reset Password</a>";
            if(function_exists('sendEmail')) sendEmail($email, "Password Reset", $message);
            
            // Localhost Debug
            $whitelist = ['127.0.0.1', '::1', 'localhost'];
            if (in_array($_SERVER['SERVER_NAME'], $whitelist)) {
                setFlash('success', "Link sent. (Localhost Debug: <a href='$link' class='underline font-bold'>Click Reset</a>)");
                header("Location: index.php?page=login"); exit();
            }
        }
        setFlash('success', "If the email exists, a reset link was sent.");
    } catch (Exception $e) { setFlash('error', "Error requesting reset."); }
    header("Location: index.php?page=login"); exit();
}

// --- Forgot Password: Do Reset ---
if (isset($_POST['do_reset'])) {
    try {
        checkCsrf();
        $token = $_POST['token'];
        $stmt = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $new_hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->bind_param("ss", $new_hash, $row['email']);
            $stmt->execute();
            $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $del->bind_param("s", $row['email']);
            $del->execute();
            setFlash('success', "Password updated! Please login.");
        } else {
            setFlash('error', "Invalid or expired token.");
        }
    } catch (Exception $e) { setFlash('error', "Error updating password."); }
    header("Location: index.php?page=login"); exit();
}

// --- Logout Logic (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    checkCsrf();
    if(isset($_SESSION['user_id'])) logActivity($conn, $_SESSION['user_id'], 'LOGOUT', 'User logged out');
    session_destroy();
    header("Location: index.php"); exit();
}

// --- HOD: Update Email ---
if (isset($_POST['update_email']) && isHod()) {
    try {
        checkCsrf();
        $uid = intval($_POST['user_id']);
        $email = trim($_POST['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', "Invalid email format.");
        } else {
            $stmt = $conn->prepare("UPDATE users SET email=? WHERE id=?");
            $stmt->bind_param("si", $email, $uid);
            $stmt->execute();
            logActivity($conn, $_SESSION['user_id'], 'UPDATE_EMAIL', "Updated email for User $uid");
            setFlash('success', 'Email updated successfully.');
        }
    } catch (Exception $e) {
        if ($conn->errno === 1062) setFlash('error', "Email already registered.");
        else setFlash('error', "DB Error.");
    }
    header("Location: index.php?page=dashboard&tab=users"); exit();
}

// --- HOD: Change Password ---
if (isset($_POST['change_password']) && isHod()) {
    try {
        checkCsrf();
        $pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param("si", $pass, $_POST['user_id']);
        $stmt->execute();
        logActivity($conn, $_SESSION['user_id'], 'CHANGE_PASS', "Reset password for User {$_POST['user_id']}");
        setFlash('success', 'Password updated.');
    } catch (Exception $e) { setFlash('error', "Error updating password."); }
    header("Location: index.php?page=dashboard&tab=users"); exit();
}

// --- HOD: Backup ---
if (isset($_POST['download_backup']) && isHod()) {
    checkCsrf();
    $tables = []; $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) $tables[] = $row[0];
    $sqlScript = "-- HNDIT Backup " . date('Y-m-d') . "\n\n";
    foreach ($tables as $table) {
        $row2 = $conn->query("SHOW CREATE TABLE $table")->fetch_row();
        $sqlScript .= "\n\n" . $row2[1] . ";\n\n";
        $result = $conn->query("SELECT * FROM $table");
        while ($row = $result->fetch_row()) {
            $sqlScript .= "INSERT INTO $table VALUES(";
            for ($j = 0; $j < count($row); $j++) {
                $row[$j] = addslashes($row[$j]);
                $row[$j] = str_replace("\n", "\\n", $row[$j]);
                $sqlScript .= '"' . $row[$j] . '"' . ($j < (count($row) - 1) ? ',' : '');
            }
            $sqlScript .= ");\n";
        }
    }
    logActivity($conn, $_SESSION['user_id'], 'BACKUP', "Downloaded DB backup");
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=backup.sql');
    echo $sqlScript; exit();
}

// --- Rep: Import CSV ---
if (isset($_POST['import_students']) && isRep()) {
    checkCsrf();
    if (!empty($_FILES['csv_file']['name'])) {
        $file = $_FILES['csv_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') { setFlash('error', 'CSV files only.'); header("Location: index.php?page=dashboard&tab=students"); exit(); }
        
        $handle = fopen($file['tmp_name'], "r");
        $success = 0; $error = 0; $row = 0;
        $stmt = $conn->prepare("INSERT INTO students (index_number, full_name, batch_number) VALUES (?, ?, ?)");
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row++;
            if ($row == 1 && (stripos($data[0], 'index') !== false)) continue;
            if (count($data) < 3) { $error++; continue; }
            $idx = trim($data[0]); $name = trim($data[1]); $batch = intval($data[2]);
            if (empty($idx) || empty($name) || empty($batch)) { $error++; continue; }
            try { $stmt->bind_param("ssi", $idx, $name, $batch); $stmt->execute(); $success++; } catch (Exception $e) { $error++; }
        }
        fclose($handle);
        logActivity($conn, $_SESSION['user_id'], 'IMPORT_STUDENTS', "Imported $success students");
        setFlash('success', "Imported: $success, Failed: $error");
        header("Location: index.php?page=dashboard&tab=students"); exit();
    }
}

// --- Rep: Student CRUD ---
if (isset($_POST['save_student']) && isRep()) {
    checkCsrf();
    $idx = trim($_POST['index_number']); $name = trim($_POST['full_name']); $batch = intval($_POST['batch_number']);
    try {
        if (!empty($_POST['student_id'])) {
            $stmt = $conn->prepare("UPDATE students SET index_number=?, full_name=?, batch_number=? WHERE id=?");
            $stmt->bind_param("ssii", $idx, $name, $batch, $_POST['student_id']);
            $msg = "Student Updated";
            logActivity($conn, $_SESSION['user_id'], 'UPDATE_STUDENT', "Updated $idx");
        } else {
            $stmt = $conn->prepare("INSERT INTO students (index_number, full_name, batch_number) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $idx, $name, $batch);
            $msg = "Student Added";
            logActivity($conn, $_SESSION['user_id'], 'ADD_STUDENT', "Added $idx");
        }
        $stmt->execute();
        setFlash('success', $msg);
    } catch (Exception $e) { setFlash('error', "Error saving student."); }
    header("Location: index.php?page=dashboard&tab=students"); exit();
}

if (isset($_GET['delete_student']) && isRep()) {
    $sid = intval($_GET['delete_student']);
    $conn->query("DELETE FROM students WHERE id=" . $sid);
    logActivity($conn, $_SESSION['user_id'], 'DELETE_STUDENT', "Deleted ID $sid");
    setFlash('success', 'Student Deleted');
    header("Location: index.php?page=dashboard&tab=students"); exit();
}

// --- Rep: Post CRUD ---
if (isset($_POST['save_post']) && isRep()) {
    checkCsrf();
    $title = $_POST['title'];
    $desc = $_POST['description_html'] ?? $_POST['description'];
    $desc = strip_tags($desc, '<p><br><b><i><u><strong><em><ul><ol><li><a><h1><h2><h3>');
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $status = isset($_POST['submit_hod']) ? 'pending' : 'draft';

    $conn->begin_transaction();
    try {
        if (!empty($_POST['post_id'])) {
            $post_id = $_POST['post_id'];
            $stmt = $conn->prepare("UPDATE posts SET title=?, description=?, is_featured=?, status=?, rejection_message='' WHERE id=?");
            $stmt->bind_param("ssisi", $title, $desc, $is_featured, $status, $post_id);
            $stmt->execute();
            $conn->query("DELETE FROM post_tags WHERE post_id=$post_id");
            if(isset($_POST['delete_media'])) {
                foreach($_POST['delete_media'] as $mid) {
                    $m = $conn->query("SELECT file_path FROM post_media WHERE id=".intval($mid))->fetch_assoc();
                    if($m && file_exists($m['file_path'])) unlink($m['file_path']);
                    $conn->query("DELETE FROM post_media WHERE id=".intval($mid));
                }
            }
            logActivity($conn, $_SESSION['user_id'], 'UPDATE_POST', "Updated Post $post_id");
        } else {
            $stmt = $conn->prepare("INSERT INTO posts (title, description, is_featured, status, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssisi", $title, $desc, $is_featured, $status, $_SESSION['user_id']);
            $stmt->execute();
            $post_id = $stmt->insert_id;
            logActivity($conn, $_SESSION['user_id'], 'CREATE_POST', "Created Post $post_id");
        }

        // Media Uploads
        if(isset($_FILES['media_files'])) {
            $files = [];
            foreach ($_FILES['media_files'] as $k => $l) { foreach ($l as $i => $v) { if (!array_key_exists($i, $files)) $files[$i] = []; $files[$i][$k] = $v; } }
            foreach($files as $file) {
                if(!empty($file['name'])) {
                    $result = uploadFile($file, "uploads/", "post_media");
                    if(isset($result['error'])) throw new Exception($result['error']);
                    $stmt = $conn->prepare("INSERT INTO post_media (post_id, media_type, file_path) VALUES (?, ?, ?)");
                    $stmt->bind_param("iss", $post_id, $result['type'], $result['path']);
                    $stmt->execute();
                }
            }
        }

        // Video URLs
        if(!empty($_POST['video_urls'])) {
            foreach(explode("\n", $_POST['video_urls']) as $url) {
                if(trim($url)) {
                    $url = trim($url);
                    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                    $type = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? 'image' : 'video';
                    $stmt = $conn->prepare("INSERT INTO post_media (post_id, media_type, file_path) VALUES (?, ?, ?)");
                    $stmt->bind_param("iss", $post_id, $type, $url);
                    $stmt->execute();
                }
            }
        }

        // Tags
        if (isset($_POST['students']) && is_array($_POST['students'])) {
            $stmt = $conn->prepare("INSERT INTO post_tags (post_id, student_id) VALUES (?, ?)");
            foreach ($_POST['students'] as $stud_id) {
                $stmt->bind_param("ii", $post_id, $stud_id);
                $stmt->execute();
            }
        }
        $conn->commit();
        setFlash('success', 'Post saved!');
        header("Location: index.php?page=dashboard&tab=posts&subtab=manage"); exit();
    } catch (Exception $e) {
        $conn->rollback();
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=dashboard&tab=posts"); exit();
    }
}

if (isset($_GET['delete_post']) && isRep()) {
    $pid = intval($_GET['delete_post']);
    $conn->query("DELETE FROM posts WHERE id=$pid AND created_by=".$_SESSION['user_id']);
    logActivity($conn, $_SESSION['user_id'], 'DELETE_POST', "Deleted Post $pid");
    setFlash('success', 'Post Deleted');
    header("Location: index.php?page=dashboard&tab=posts&subtab=manage"); exit();
}

// --- HOD Actions ---
if (isset($_POST['review_post']) && isHod()) {
    checkCsrf();
    $stmt = $conn->prepare("UPDATE posts SET status=?, rejection_message=? WHERE id=?");
    $stmt->bind_param("ssi", $_POST['status'], $_POST['rejection_message'], $_POST['post_id']);
    $stmt->execute();
    logActivity($conn, $_SESSION['user_id'], 'REVIEW_POST', "Post {$_POST['post_id']} set to {$_POST['status']}");
    setFlash('success', 'Review Updated');
    header("Location: index.php?page=dashboard&tab=overview"); exit();
}

// --- Library ---
if (isset($_POST['upload_paper']) && (isRep() || isHod())) {
    checkCsrf();
    $title = $_POST['title']; $subj = $_POST['subject']; 
    $exam = $_POST['exam_year']; $ac = $_POST['academic_year']; $sem = $_POST['semester'];
    if (!empty($_FILES['paper_file']['name'])) {
        $res = uploadFile($_FILES['paper_file'], "papers/", "library");
        if(isset($res['path'])) {
            $stmt = $conn->prepare("INSERT INTO papers (title, subject, exam_year, academic_year, semester, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiisi", $title, $subj, $exam, $ac, $sem, $res['path'], $_SESSION['user_id']);
            $stmt->execute();
            logActivity($conn, $_SESSION['user_id'], 'UPLOAD_LIB', "Uploaded $title");
            setFlash('success', 'File Uploaded');
        } else {
            setFlash('error', $res['error']);
        }
    }
    header("Location: index.php?page=library"); exit();
}

if (isset($_GET['delete_paper']) && (isRep() || isHod())) {
    $pid = intval($_GET['delete_paper']);
    $conn->query("DELETE FROM papers WHERE id=$pid");
    logActivity($conn, $_SESSION['user_id'], 'DELETE_LIB', "Deleted Paper $pid");
    setFlash('success', 'File Deleted');
    header("Location: index.php?page=library"); exit();
}

// 3. RENDER VIEW
$page = $_GET['page'] ?? 'home';
ob_start();
include 'header.php';
$header = ob_get_clean();
$header = str_replace('</head>', '<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet"><script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script></head>', $header);
echo $header;
?>

<!-- Styles -->
<style>
    .media-grid-container { display: grid; gap: 2px; width: 100%; overflow: hidden; border-radius: 4px; border: 1px solid #e2e8f0; }
    .dark .media-grid-container { border-color: #334155; }
    .grid-layout-1 { grid-template-columns: 1fr; height: 400px; }
    .grid-layout-2 { grid-template-columns: 1fr 1fr; height: 350px; }
    .grid-layout-3 { grid-template-columns: 2fr 1fr; grid-template-rows: 1fr 1fr; height: 450px; }
    .grid-layout-3 :first-child { grid-column: span 1; grid-row: span 2; }
    @media (max-width: 767px) {
        .media-grid-container { height: auto; } 
        .grid-layout-1 { height: 250px; } 
        .grid-layout-2 { height: 200px; } 
        .grid-layout-3 { height: 250px; }
    }
    .ql-editor { min-height: 150px; }
</style>

<!-- Mobile Menu -->
<div id="mobileMenu" class="fixed inset-0 bg-slate-900/95 z-[200] transform translate-x-full flex flex-col justify-center items-center text-white gap-8 p-8 md:hidden transition-transform duration-300">
    <button onclick="toggleMobileMenu()" class="absolute top-6 right-6 text-4xl">&times;</button>
    <a href="index.php?page=home" class="text-2xl font-bold uppercase tracking-widest hover:text-blue-400">Home</a>
    <a href="index.php?page=library" class="text-2xl font-bold uppercase tracking-widest hover:text-blue-400">Library</a>
    <a href="index.php?page=about" class="text-2xl font-bold uppercase tracking-widest hover:text-blue-400">About</a>
    <?php if(isLoggedIn()): ?>
        <a href="index.php?page=dashboard" class="text-2xl font-bold uppercase tracking-widest text-blue-400">Dashboard</a>
        <a href="#" onclick="document.getElementById('logoutForm').submit()" class="text-xl text-red-400 border-t border-white/20 pt-6 w-full text-center">Logout</a>
    <?php else: ?>
        <a href="index.php?page=login" class="text-2xl font-bold uppercase tracking-widest border-2 border-white px-8 py-2 rounded">Login</a>
    <?php endif; ?>
    <button onclick="toggleDarkMode(); toggleMobileMenu()" class="mt-8 flex items-center gap-2 text-sm text-slate-400"><i class="fas fa-moon"></i> Theme</button>
</div>

<!-- ABOUT PAGE -->
<?php if ($page == 'about'): ?>
    <div class="max-w-4xl mx-auto bg-white dark:bg-slate-800 p-10 rounded shadow-sm border border-slate-200 dark:border-slate-700 animate-fade-in">
        <h1 class="text-4xl font-bold text-slate-900 dark:text-white mb-6 font-serif">About HNDIT</h1>
        <div class="prose dark:prose-invert max-w-none text-slate-600 dark:text-slate-300">
            <p class="text-lg leading-relaxed mb-4">The Higher National Diploma in Information Technology (HNDIT) program is designed to produce high-quality IT professionals capable of adapting to the dynamic demands of the global tech industry.</p>
            <p class="mb-4">This portfolio system serves as a centralized registry to showcase student projects, academic achievements, and research, providing a verified source of truth for recruiters and industry partners.</p>
            <h3 class="text-xl font-bold mt-8 mb-4 text-slate-800 dark:text-white">Contact Administration</h3>
            <ul class="list-none space-y-2">
                <li><i class="fas fa-envelope w-6 text-uni-blue"></i> hndit@university.ac.lk</li>
                <li><i class="fas fa-phone w-6 text-uni-blue"></i> +94 11 234 5678</li>
                <li><i class="fas fa-map-marker-alt w-6 text-uni-blue"></i> Main Campus, IT Building</li>
            </ul>
        </div>
    </div>
<?php endif; ?>

<!-- FORGOT PASSWORD -->
<?php if ($page == 'forgot_password'): ?>
    <div class="flex justify-center items-center h-[70vh]">
        <div class="bg-white p-10 shadow-2xl border-t-4 border-uni-blue w-full max-w-sm">
            <div class="text-center mb-6">
                <h2 class="text-2xl font-bold text-slate-900">Reset Password</h2>
                <p class="text-xs text-slate-400 mt-1">Enter your email to receive a reset link</p>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div><label class="block text-xs font-bold uppercase text-slate-500 mb-1">Email Address</label><input type="email" name="email" class="w-full border p-3 outline-none focus:border-uni-blue" required></div>
                <button type="submit" name="request_reset" class="btn-primary w-full py-3 font-bold uppercase">Send Reset Link</button>
            </form>
            <div class="text-center mt-4"><a href="index.php?page=login" class="text-xs text-blue-600 hover:underline">Back to Login</a></div>
        </div>
    </div>
<?php endif; ?>
<?php if ($page == 'reset_password'): ?>
    <div class="flex justify-center items-center h-[70vh]">
        <div class="bg-white p-10 shadow-2xl border-t-4 border-uni-blue w-full max-w-sm">
            <div class="text-center mb-6"><h2 class="text-2xl font-bold text-slate-900">New Password</h2></div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>">
                <div><label class="block text-xs font-bold uppercase text-slate-500 mb-1">New Password</label><input type="password" name="new_password" class="w-full border p-3 outline-none focus:border-uni-blue" required></div>
                <button type="submit" name="do_reset" class="btn-primary w-full py-3 font-bold uppercase">Set Password</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- HOME -->
<?php if ($page == 'home'): ?>
    <div class="relative bg-white dark:bg-slate-800 rounded-sm shadow-sm border border-slate-200 dark:border-slate-700 p-8 md:p-20 text-center mb-12 overflow-hidden animate-fade-in">
        <div class="absolute top-0 left-0 w-full h-1 bg-uni-gold"></div>
        <h1 class="text-4xl md:text-6xl text-slate-900 dark:text-white mb-6 leading-tight font-serif">Student Academic <br><span class="text-uni-blue dark:text-blue-400">Portfolio System</span></h1>
        <div class="flex flex-wrap justify-center gap-8 mb-10 text-slate-600 dark:text-slate-300">
            <?php 
                $s_count = $conn->query("SELECT COUNT(*) FROM students")->fetch_row()[0];
                $p_count = $conn->query("SELECT COUNT(*) FROM posts WHERE status='published'")->fetch_row()[0];
                $l_count = $conn->query("SELECT COUNT(*) FROM papers")->fetch_row()[0];
            ?>
            <div class="text-center"><span class="block text-3xl font-bold text-uni-blue dark:text-blue-400"><?php echo $s_count; ?></span> Students</div>
            <div class="text-center"><span class="block text-3xl font-bold text-uni-blue dark:text-blue-400"><?php echo $p_count; ?></span> Projects</div>
            <div class="text-center"><span class="block text-3xl font-bold text-uni-blue dark:text-blue-400"><?php echo $l_count; ?></span> Resources</div>
        </div>
        <form action="index.php" method="GET" class="max-w-2xl mx-auto flex flex-col md:flex-row shadow-lg">
            <input type="hidden" name="page" value="portfolio">
            <div class="flex-1 relative"><input type="text" name="index" placeholder="Enter Student Index (e.g. kan/it/2021/f/045)" required class="w-full md:pl-12 px-4 py-4 border border-r-0 border-slate-300 dark:border-slate-600 focus:border-uni-blue outline-none text-slate-700 dark:text-white bg-slate-50 dark:bg-slate-900 transition rounded-t md:rounded-l md:rounded-tr-none"></div>
            <button type="submit" class="bg-uni-blue dark:bg-blue-600 text-white px-8 py-3 md:py-0 font-bold tracking-wide hover:bg-blue-900 dark:hover:bg-blue-500 transition rounded-b md:rounded-r md:rounded-bl-none">SEARCH</button>
        </form>
    </div>
    <div class="border-b border-slate-200 dark:border-slate-700 pb-2 mb-8 flex items-center justify-between"><h2 class="text-2xl text-slate-800 dark:text-white font-serif">Latest Highlights</h2><div class="h-1 w-20 bg-uni-gold"></div></div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <?php 
        $page_no = isset($_GET['p']) ? (int)$_GET['p'] : 1; $limit = 6; $offset = ($page_no - 1) * $limit;
        $res = $conn->query("SELECT * FROM posts WHERE status='published' AND is_featured=1 ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $total_recs = $conn->query("SELECT COUNT(*) FROM posts WHERE status='published' AND is_featured=1")->fetch_row()[0];
        $total_pages = ceil($total_recs / $limit);
        while($post = $res->fetch_assoc()): 
            $media = $conn->query("SELECT * FROM post_media WHERE post_id=".$post['id']." LIMIT 1")->fetch_assoc();
            $mPath = $media['file_path'] ?? ''; $mType = $media['media_type'] ?? '';
        ?>
            <div class="card group cursor-pointer hover:shadow-lg transition duration-300 flex flex-col h-full" onclick="openMedia('<?php echo $mPath; ?>', '<?php echo $mType; ?>')">
                <div class="h-56 md:h-64 relative bg-black flex items-center justify-center overflow-hidden border-b border-slate-100 dark:border-slate-700">
                    <?php if($mType=='image'): ?><img src="<?php echo $mPath; ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-700" loading="lazy"><?php elseif($mType=='video'): ?><div class="w-full h-full flex items-center justify-center text-white"><i class="fas fa-play-circle text-5xl opacity-80 group-hover:scale-110 transition"></i></div><?php else: ?><div class="w-full h-full flex items-center justify-center text-slate-500 bg-slate-100 dark:bg-slate-800"><i class="fas fa-image text-4xl"></i></div><?php endif; ?>
                    <div class="absolute bottom-0 left-0 bg-uni-blue dark:bg-blue-600 text-white text-xs px-3 py-1 font-bold">FEATURED</div>
                </div>
                <div class="p-6 flex-1 flex flex-col">
                    <h3 class="font-bold text-lg text-slate-800 dark:text-white mb-2 leading-snug line-clamp-2"><?php echo htmlspecialchars($post['title']); ?></h3>
                    <div class="mt-auto pt-4 border-t border-slate-100 dark:border-slate-700 text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide"><?php echo date('F d, Y', strtotime($post['created_at'])); ?></div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    <?php if($total_pages > 1): ?><div class="flex justify-center gap-2 mt-10"><?php if($page_no > 1): ?><a href="index.php?page=home&p=<?php echo $page_no-1; ?>" class="px-4 py-2 bg-white border rounded">Prev</a><?php endif; ?><span class="px-4 py-2 bg-uni-blue text-white rounded"><?php echo $page_no; ?> / <?php echo $total_pages; ?></span><?php if($page_no < $total_pages): ?><a href="index.php?page=home&p=<?php echo $page_no+1; ?>" class="px-4 py-2 bg-white border rounded">Next</a><?php endif; ?></div><?php endif; ?>
<?php endif; ?>

<!-- PORTFOLIO -->
<?php if ($page == 'portfolio' && isset($_GET['index'])): 
    $idx = $conn->real_escape_string($_GET['index']);
    $student = $conn->query("SELECT * FROM students WHERE index_number LIKE '%$idx%' LIMIT 1")->fetch_assoc();
?>
    <?php if ($student): ?>
        <div class="bg-white dark:bg-slate-800 border-t-4 border-uni-blue shadow-sm mb-10 p-8 md:p-10 flex flex-col md:flex-row items-center gap-8 animate-fade-in transition-colors duration-300 relative">
            <div class="w-24 h-24 md:w-32 md:h-32 bg-slate-100 dark:bg-slate-700 rounded-full flex items-center justify-center text-3xl md:text-4xl font-serif font-bold text-uni-blue dark:text-blue-400 border-4 border-white dark:border-slate-600 shadow-md"><?php echo substr($student['full_name'],0,1); ?></div>
            <div class="text-center md:text-left">
                <h1 class="text-2xl md:text-4xl font-bold text-slate-900 dark:text-white mb-2"><?php echo htmlspecialchars($student['full_name']); ?></h1>
                <div class="flex flex-wrap justify-center md:justify-start gap-4 text-sm font-medium text-slate-600 dark:text-slate-300">
                    <span class="bg-slate-100 dark:bg-slate-700 px-3 py-1 border border-slate-200 dark:border-slate-600 rounded">Index: <?php echo htmlspecialchars($student['index_number']); ?></span>
                    <span class="bg-blue-50 dark:bg-blue-900/30 text-uni-blue dark:text-blue-300 px-3 py-1 border border-blue-100 dark:border-blue-800 rounded">Batch <?php echo $student['batch_number']; ?></span>
                </div>
            </div>
        </div>

        <div class="max-w-4xl mx-auto space-y-8">
            <?php
            $posts = $conn->query("SELECT p.* FROM posts p JOIN post_tags pt ON p.id=pt.post_id WHERE pt.student_id={$student['id']} AND p.status='published' ORDER BY created_at DESC");
            if($posts->num_rows == 0) echo "<div class='p-12 text-center text-slate-500 dark:text-slate-400 bg-white dark:bg-slate-800 border border-dashed border-slate-300 dark:border-slate-700 rounded'>No records found in this portfolio.</div>";
            while($post = $posts->fetch_assoc()):
                $media_res = $conn->query("SELECT * FROM post_media WHERE post_id=".$post['id']);
                $medias = []; while($m = $media_res->fetch_assoc()) $medias[] = $m;
                $count = count($medias);
                $layoutClass = ($count == 1) ? 'grid-layout-1' : (($count == 2) ? 'grid-layout-2' : 'grid-layout-3');
            ?>
                <script>window.postMediaData[<?php echo $post['id']; ?>] = <?php echo json_encode($medias); ?>;</script>
                <div class="card animate-fade-in">
                    <div class="p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div><h3 class="text-xl font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($post['title']); ?></h3><div class="text-xs text-slate-500 dark:text-slate-400 uppercase mt-1"><?php echo date('F d, Y', strtotime($post['created_at'])); ?></div></div>
                            <div class="text-uni-gold"><i class="fas fa-certificate text-xl"></i></div>
                        </div>
                        <div class="text-slate-700 dark:text-slate-300 leading-relaxed mb-6 whitespace-pre-wrap prose dark:prose-invert max-w-none"><?php echo $post['description']; ?></div>
                        <?php if($count > 0): ?>
                            <div class="media-grid-container <?php echo $layoutClass; ?>">
                                <?php foreach(array_slice($medias, 0, 3) as $index => $m): ?>
                                    <div class="media-item-<?php echo $index; ?> relative bg-black h-full w-full overflow-hidden group cursor-pointer flex items-center justify-center border border-white/10" onclick="openGallery(<?php echo $post['id']; ?>, <?php echo $index; ?>)">
                                        <?php if($m['media_type']=='video'): ?><div class="absolute inset-0 flex items-center justify-center z-10"><i class="fas fa-play-circle text-6xl text-white/90 drop-shadow-lg transition group-hover:scale-110"></i></div><div class="w-full h-full bg-slate-900 flex items-center justify-center text-white/50 font-bold tracking-widest">VIDEO</div><?php else: ?><img src="<?php echo $m['file_path']; ?>" class="w-full h-full object-cover transition duration-500 group-hover:scale-105" loading="lazy"><?php endif; ?>
                                        <?php if($index === 2 && $count > 3): ?><div class="absolute inset-0 bg-black/60 flex items-center justify-center text-white text-4xl font-bold backdrop-blur-sm z-20">+<?php echo $count - 3; ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?><div class="p-20 text-center bg-white dark:bg-slate-800 border border-red-100 dark:border-red-900/30 rounded-sm"><h2 class="text-2xl text-slate-700 dark:text-slate-200 mb-2">Student Not Found</h2></div><?php endif; ?>
<?php endif; ?>

<!-- === LIBRARY === -->
<?php if ($page == 'library'): ?>
    <div class="bg-white dark:bg-slate-800 p-8 border-t-4 border-uni-gold shadow-sm mb-8 animate-fade-in transition-colors duration-300">
        <div class="flex justify-between items-center mb-6">
            <div><h2 class="text-2xl text-slate-900 dark:text-white">Resource Library</h2><p class="text-slate-500 dark:text-slate-400 text-sm">Official past papers and study materials.</p></div>
            <?php if(isRep() || isHod()): ?><button onclick="document.getElementById('uploadModal').classList.remove('hidden')" class="btn-primary px-6 py-2 text-sm font-bold uppercase tracking-wide">Upload PDF</button><?php endif; ?>
        </div>
        <form method="GET" class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6 bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700">
            <input type="hidden" name="page" value="library">
            <select name="exam_year" class="border p-2 text-sm rounded-sm dark:bg-slate-800 dark:border-slate-600"><option value="">All Exam Years</option><?php for($y=date('Y'); $y>=2020; $y--) echo "<option value='$y'>$y</option>"; ?></select>
            <select name="academic_year" class="border p-2 text-sm rounded-sm dark:bg-slate-800 dark:border-slate-600"><option value="">All Academic Years</option><option value="1">1st Year</option><option value="2">2nd Year</option></select>
            <select name="semester" class="border p-2 text-sm rounded-sm dark:bg-slate-800 dark:border-slate-600"><option value="">All Semesters</option><option value="1">1st Semester</option><option value="2">2nd Semester</option></select>
            <button class="bg-uni-blue text-white font-bold text-sm uppercase rounded-sm hover:bg-blue-900">Filter Records</button>
        </form>
    </div>
    <div class="card overflow-x-auto">
        <table class="w-full text-left text-sm min-w-[600px]">
            <thead class="bg-slate-50 dark:bg-slate-900 text-slate-600 dark:text-slate-300 font-bold uppercase text-xs border-b border-slate-200 dark:border-slate-700"><tr><th class="p-5">Subject</th><th class="p-5">Title</th><th class="p-5">Context</th><th class="p-5 text-right">Actions</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                <?php 
                $sql = "SELECT * FROM papers WHERE 1=1";
                if(!empty($_GET['exam_year'])) $sql .= " AND exam_year = " . intval($_GET['exam_year']);
                if(!empty($_GET['academic_year'])) $sql .= " AND academic_year = " . intval($_GET['academic_year']);
                if(!empty($_GET['semester'])) $sql .= " AND semester = " . intval($_GET['semester']);
                $res = $conn->query($sql . " ORDER BY exam_year DESC");
                if ($res->num_rows > 0): while($row = $res->fetch_assoc()): ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        <td class="p-5 font-bold text-uni-blue dark:text-blue-400"><?php echo htmlspecialchars($row['subject']); ?></td>
                        <td class="p-5 dark:text-slate-300"><?php echo htmlspecialchars($row['title']); ?></td>
                        <td class="p-5 text-slate-500 dark:text-slate-400">Yr <?php echo $row['academic_year']; ?> / Sem <?php echo $row['semester']; ?></td>
                        <td class="p-5 text-right space-x-3">
                             <a href="<?php echo $row['file_path']; ?>" target="_blank" class="text-uni-blue dark:text-blue-400 font-bold text-xs uppercase border border-blue-200 dark:border-blue-800 px-3 py-1 hover:bg-blue-50 dark:hover:bg-blue-900/30">View PDF</a>
                             <?php if(isRep() || isHod()): ?><a href="index.php?delete_paper=<?php echo $row['id']; ?>" class="text-red-500 hover:text-red-700" onclick="return confirm('Delete permanently?')"><i class="fas fa-trash"></i></a><?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; else: ?><tr><td colspan="4" class="p-8 text-center text-slate-400 italic">No resources found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="uploadModal" class="fixed inset-0 bg-black/80 hidden flex items-center justify-center z-[100] p-4 backdrop-blur-sm">
        <form method="POST" enctype="multipart/form-data" class="bg-white dark:bg-slate-800 p-8 w-full max-w-lg shadow-2xl border-t-4 border-uni-gold">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="flex justify-between items-center mb-6"><h3 class="font-bold text-xl text-slate-800 dark:text-white">Add Library Resource</h3><button type="button" onclick="document.getElementById('uploadModal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i class="fas fa-times text-xl"></i></button></div>
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="col-span-2"><label class="block text-xs font-bold uppercase text-slate-500 mb-1">Subject Code/Name</label><input type="text" name="subject" class="w-full border p-2 rounded-sm dark:bg-slate-900 dark:border-slate-700" required></div>
                <div class="col-span-2"><label class="block text-xs font-bold uppercase text-slate-500 mb-1">Document Title</label><input type="text" name="title" class="w-full border p-2 rounded-sm dark:bg-slate-900 dark:border-slate-700" required></div>
                 <div><label class="block text-xs font-bold uppercase text-slate-500 mb-1">Exam Year</label><input type="number" name="exam_year" value="<?php echo date('Y'); ?>" class="w-full border p-2 rounded-sm dark:bg-slate-900 dark:border-slate-700" required></div>
                <div><label class="block text-xs font-bold uppercase text-slate-500 mb-1">Academic Year</label><select name="academic_year" class="w-full border p-2 rounded-sm dark:bg-slate-900 dark:border-slate-700"><option value="1">1st Year</option><option value="2">2nd Year</option></select></div>
                <div class="col-span-2"><label class="block text-xs font-bold uppercase text-slate-500 mb-1">Semester</label><select name="semester" class="w-full border p-2 rounded-sm dark:bg-slate-900 dark:border-slate-700"><option value="1">1st Semester</option><option value="2">2nd Semester</option></select></div>
                <div class="col-span-2 p-4 bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700">
                    <label class="block text-xs font-bold uppercase text-slate-500 mb-1">PDF Document</label>
                    <input type="file" name="paper_file" class="w-full text-sm" accept=".pdf" required>
                </div>
            </div>
            <button type="submit" name="upload_paper" class="btn-primary w-full py-3 font-bold uppercase">Upload Document</button>
        </form>
    </div>
<?php endif; ?>

<!-- 4. DASHBOARD -->
<?php if ($page == 'dashboard' && isLoggedIn()): $tab = $_GET['tab'] ?? (isHod()?'overview':'posts'); $subtab = $_GET['subtab'] ?? 'create'; ?>
    <div class="flex flex-col lg:flex-row gap-8">
        <aside class="w-full lg:w-64 space-y-6">
            <div class="card p-6 text-center border-t-4 border-uni-blue">
                <div class="w-16 h-16 bg-uni-blue text-white rounded-full mx-auto mb-3 flex items-center justify-center text-2xl font-bold font-serif"><?php echo substr($_SESSION['name'],0,1); ?></div>
                <h3 class="font-bold text-lg text-slate-900 dark:text-white"><?php echo htmlspecialchars($_SESSION['name']); ?></h3>
                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mt-1"><?php echo $_SESSION['role'] === 'hod' ? 'Administrator' : 'Representative'; ?></div>
            </div>
            <nav class="card p-2 space-y-1">
                <?php if(isHod()): ?>
                    <a href="index.php?page=dashboard&tab=overview" class="block p-3 text-sm font-bold <?php echo $tab=='overview'?'bg-slate-100 dark:bg-slate-700 text-uni-blue dark:text-blue-400 border-l-4 border-uni-blue':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700'; ?>">Pending Reviews</a>
                    <a href="index.php?page=dashboard&tab=users" class="block p-3 text-sm font-bold <?php echo $tab=='users'?'bg-slate-100 dark:bg-slate-700 text-uni-blue dark:text-blue-400 border-l-4 border-uni-blue':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700'; ?>">Staff Management</a>
                    <a href="index.php?page=dashboard&tab=activity" class="block p-3 text-sm font-bold <?php echo $tab=='activity'?'bg-slate-100 dark:bg-slate-700 text-uni-blue dark:text-blue-400 border-l-4 border-uni-blue':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700'; ?>">Activity Logs</a>
                    <!-- HOD BACKUP BUTTON -->
                    <form method="POST" class="block p-2"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><button name="download_backup" class="w-full bg-slate-800 text-white text-xs font-bold py-2 rounded hover:bg-slate-900">Download DB Backup</button></form>
                <?php endif; ?>
                <?php if(isRep()): ?>
                    <a href="index.php?page=dashboard&tab=posts" class="block p-3 text-sm font-bold <?php echo $tab=='posts'?'bg-slate-100 dark:bg-slate-700 text-uni-blue dark:text-blue-400 border-l-4 border-uni-blue':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700'; ?>">Manage Posts</a>
                    <a href="index.php?page=dashboard&tab=students" class="block p-3 text-sm font-bold <?php echo $tab=='students'?'bg-slate-100 dark:bg-slate-700 text-uni-blue dark:text-blue-400 border-l-4 border-uni-blue':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700'; ?>">Student Registry</a>
                <?php endif; ?>
                <a href="#" onclick="document.getElementById('logoutForm').submit()" class="block p-3 text-sm font-bold text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 mt-4 border-t dark:border-slate-700">Logout</a>
            </nav>
        </aside>

        <div class="flex-1 min-w-0">
            <!-- DASHBOARD CONTENT BLOCKS -->
            <?php if($tab == 'activity' && isHod()): ?>
                <div class="card overflow-x-auto">
                    <div class="p-5 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900"><h2 class="font-bold text-lg">System Activity Log</h2></div>
                    <table class="w-full text-left text-sm min-w-[600px]">
                         <thead class="bg-white dark:bg-slate-800 text-slate-500 uppercase font-bold text-xs border-b"><tr><th class="p-4">User</th><th class="p-4">Action</th><th class="p-4">Details</th><th class="p-4">Date</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <?php 
                                $log_sql = "SELECT a.*, u.name FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 50";
                                $log_res = $conn->query($log_sql);
                                if($log_res): while($log = $log_res->fetch_assoc()): 
                            ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700">
                                    <td class="p-4 text-xs"><?php echo htmlspecialchars($log['name'] ?? 'System'); ?></td>
                                    <td class="p-4"><span class="bg-slate-100 dark:bg-slate-700 border dark:border-slate-600 px-2 py-1 text-[10px] uppercase font-bold"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                    <td class="p-4 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($log['details']); ?></td>
                                    <td class="p-4 text-xs text-slate-400 font-mono"><?php echo $log['created_at']; ?></td>
                                </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($tab == 'overview' && isHod()): 
                 $res = $conn->query("SELECT p.*, u.name as rep_name FROM posts p JOIN users u ON p.created_by=u.id WHERE p.status='pending' ORDER BY p.created_at DESC");
                 if($res->num_rows == 0) echo "<div class='p-12 text-center text-slate-400 card italic'>No pending items for review.</div>";
                 while($post = $res->fetch_assoc()): ?>
                <div class="card mb-6">
                    <div class="bg-yellow-50 dark:bg-yellow-900/30 p-4 border-b border-yellow-100 dark:border-yellow-800 flex justify-between items-center">
                        <span class="text-yellow-700 dark:text-yellow-400 font-bold text-xs uppercase tracking-wide">Review Required</span>
                        <span class="text-xs font-mono text-slate-500 dark:text-slate-400">ID: <?php echo $post['id']; ?></span>
                    </div>
                    <div class="p-6">
                        <h4 class="font-bold text-lg text-slate-900 dark:text-white mb-2"><?php echo htmlspecialchars($post['title']); ?></h4>
                        <p class="text-slate-700 dark:text-slate-300 text-sm bg-slate-50 dark:bg-slate-900 p-4"><?php echo makeLinksClickable($post['description']); ?></p>
                    </div>
                    <form method="POST" class="p-4 bg-slate-50 dark:bg-slate-900 border-t border-slate-200 flex gap-4">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                        <input type="hidden" name="status" value="">
                        <input type="hidden" name="rejection_message" value="">
                        <input type="hidden" name="review_post" value="1">
                        <button type="submit" onclick="this.form.status.value='published'" class="flex-1 btn-primary py-2 font-bold text-sm">Approve</button>
                        <button type="button" onclick="let m=prompt('Reason for rejection:'); if(m){this.form.status.value='rejected'; this.form.rejection_message.value=m; this.form.submit();}" class="flex-1 bg-white border border-red-300 text-red-600 py-2 font-bold text-sm hover:bg-red-50">Reject</button>
                    </form>
                </div>
            <?php endwhile; endif; ?>

            <?php if($tab == 'users' && isHod()): ?>
                <div class="card overflow-x-auto">
                    <div class="p-5 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900"><h2 class="font-bold text-lg">Staff Directory & Access</h2></div>
                    <table class="w-full text-left text-sm min-w-[600px]">
                        <thead class="bg-white dark:bg-slate-800 text-slate-500 uppercase font-bold text-xs border-b"><tr><th class="p-4">Details</th><th class="p-4">Role</th><th class="p-4">Email Address</th><th class="p-4">Password Reset</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <?php $res = $conn->query("SELECT * FROM users"); while($u = $res->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700">
                                    <td class="p-4"><div class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($u['name']); ?></div><div class="text-xs font-mono text-slate-400"><?php echo htmlspecialchars($u['username']); ?></div></td>
                                    <td class="p-4"><span class="bg-slate-100 dark:bg-slate-700 border dark:border-slate-600 px-2 py-1 text-xs uppercase font-bold"><?php echo $u['role']; ?></span></td>
                                    <td class="p-4">
                                        <form method="POST" class="flex gap-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="email" name="email" value="<?php echo htmlspecialchars($u['email']); ?>" class="border dark:border-slate-600 dark:bg-slate-900 p-2 text-xs w-40" required>
                                            <button type="submit" name="update_email" class="text-uni-blue dark:text-blue-400 font-bold text-xs border border-blue-200 dark:border-blue-900 px-3 hover:bg-blue-50 dark:hover:bg-blue-900/30">SAVE</button>
                                        </form>
                                    </td>
                                    <td class="p-4">
                                        <form method="POST" class="flex gap-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="text" name="new_password" placeholder="New Pass" class="border dark:border-slate-600 dark:bg-slate-900 p-2 text-xs w-32" required>
                                            <button type="submit" name="change_password" class="text-red-500 font-bold text-xs border border-red-200 px-3 hover:bg-red-50">RESET</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($tab == 'posts' && isRep()): ?>
                 <div class="flex gap-1 mb-6 bg-white dark:bg-slate-800 p-1 rounded border dark:border-slate-700 inline-flex">
                    <a href="index.php?page=dashboard&tab=posts&subtab=create" class="px-6 py-2 text-sm font-bold rounded-sm <?php echo $subtab=='create'?'bg-uni-blue text-white':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700'; ?>">Create New</a>
                    <a href="index.php?page=dashboard&tab=posts&subtab=manage" class="px-6 py-2 text-sm font-bold rounded-sm <?php echo $subtab=='manage'?'bg-uni-blue text-white':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700'; ?>">Manage History</a>
                </div>
                <?php if($subtab == 'create'): ?>
                     <div class="card p-8">
                        <h3 class="font-bold text-xl mb-6 text-slate-800 dark:text-white border-b dark:border-slate-700 pb-2">Draft New Post</h3>
                        <form method="POST" enctype="multipart/form-data" class="space-y-6" onsubmit="copyQuillContent()">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="grid md:grid-cols-2 gap-8">
                                <div>
                                    <input type="text" name="title" placeholder="Title" class="w-full border p-3 mb-4" required>
                                     <div><label class="block text-xs font-bold uppercase text-slate-500 mb-1">Description</label><div id="quill-editor" class="bg-white dark:bg-slate-900 h-40"></div><input type="hidden" name="description_html" id="description_html"></div>
                                    <div class="p-4 mt-4 bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700">
                                        <label class="block text-xs font-bold uppercase text-slate-500 mb-2">Visual Media (Images/Video)</label>
                                        <input type="file" name="media_files[]" multiple class="w-full text-sm" accept="image/*,video/*">
                                    </div>
                                </div>
                                <div class="flex flex-col h-full border p-4">
                                    <input type="text" id="studentSearch" onkeyup="filterStudents()" placeholder="Search..." class="w-full border p-2 text-sm mb-2 dark:bg-slate-800">
                                    <div class="flex gap-2 mb-2">
                                        <select id="batchFilter" onchange="filterStudents()" class="text-xs border p-2 flex-1 dark:bg-slate-800"><option value="all">All Batches</option></select>
                                        <button type="button" onclick="toggleSelectVisible()" class="bg-slate-100 border px-2 text-xs">Check Visible</button>
                                    </div>
                                    <div id="studentListContainer" class="flex-1 overflow-y-auto h-64 border p-2"></div>
                                </div>
                            </div>
                            <div class="flex justify-between items-center pt-6 border-t mt-4">
                                <label class="flex items-center gap-2"><input type="checkbox" name="is_featured"> Feature on Home</label>
                                <button type="submit" name="save_post" value="1" onclick="this.form.appendChild(document.createElement('input')).setAttribute('name', 'submit_hod')" class="btn-primary px-8 py-3 font-bold">Submit</button>
                            </div>
                        </form>
                     </div>
                <?php else: ?>
                    <!-- Manage posts list -->
                     <?php 
                    $uid = $_SESSION['user_id'];
                    $res = $conn->query("SELECT * FROM posts WHERE created_by=$uid ORDER BY created_at DESC LIMIT 20");
                    while($p = $res->fetch_assoc()): $isRej = $p['status'] === 'rejected'; ?>
                        <div class="card mb-3 p-4 flex justify-between items-center <?php echo $isRej ? 'border-red-300 bg-red-50' : ''; ?>">
                            <div>
                                <div class="font-bold text-slate-800"><?php echo htmlspecialchars($p['title']); ?></div>
                                <div class="text-xs text-slate-500 uppercase mt-1">
                                    <span class="font-bold <?php echo $isRej ? 'text-red-600' : 'text-uni-blue'; ?>"><?php echo $p['status']; ?></span> • <?php echo date('M d, Y', strtotime($p['created_at'])); ?>
                                </div>
                                <?php if($isRej): ?><div class="text-xs text-red-600 mt-2 font-bold bg-white p-2 border border-red-200 inline-block">Reason: <?php echo htmlspecialchars($p['rejection_message']); ?></div><?php endif; ?>
                            </div>
                            <a href="#" onclick="confirmDelete('index.php?delete_post=<?php echo $p['id']; ?>')" class="text-red-400 p-2"><i class="fas fa-trash-alt"></i></a>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- REP: STUDENTS (Same as before) -->
            <?php if($tab == 'students' && isRep()): 
                $edit_stud = null;
                if(isset($_GET['edit_student'])) $edit_stud = $conn->query("SELECT * FROM students WHERE id=".intval($_GET['edit_student']))->fetch_assoc();
            ?>
                <div class="card p-8">
                     <div class="flex justify-between items-center mb-6">
                        <h2 class="font-bold text-xl">Student Registry</h2>
                        <button onclick="document.getElementById('csvModal').classList.remove('hidden')" class="bg-green-600 text-white px-4 py-2 text-xs font-bold uppercase rounded">Import CSV</button>
                     </div>
                     <form method="POST" class="bg-slate-50 p-6 border mb-8 grid md:grid-cols-4 gap-4 items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="student_id" value="<?php echo $edit_stud['id'] ?? ''; ?>">
                        <div class="md:col-span-1"><input type="text" name="index_number" value="<?php echo $edit_stud['index_number'] ?? ''; ?>" class="w-full border p-2" required placeholder="Index No"></div>
                        <div class="md:col-span-1"><input type="text" name="full_name" value="<?php echo $edit_stud['full_name'] ?? ''; ?>" class="w-full border p-2" required placeholder="Full Name"></div>
                        <div class="md:col-span-1"><input type="number" name="batch_number" value="<?php echo $edit_stud['batch_number'] ?? ''; ?>" class="w-full border p-2" required placeholder="Batch No"></div>
                        <button type="submit" name="save_student" class="btn-primary h-[42px] font-bold text-sm uppercase"><?php echo $edit_stud ? 'Update' : 'Add'; ?></button>
                     </form>
                     
                     <div class="overflow-x-auto border border-slate-200">
                         <table class="w-full text-left text-sm min-w-[600px]">
                            <thead class="bg-slate-50 text-slate-500 font-bold uppercase text-xs"><tr><th class="p-3 border-b">Index</th><th class="p-3 border-b">Name</th><th class="p-3 border-b">Batch</th><th class="p-3 border-b text-right">Action</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php 
                                $res = $conn->query("SELECT * FROM students ORDER BY batch_number DESC, index_number ASC");
                                if($res->num_rows > 0): while($s = $res->fetch_assoc()): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 font-mono text-slate-600"><?php echo htmlspecialchars($s['index_number']); ?></td>
                                        <td class="p-3 font-bold text-slate-800"><?php echo htmlspecialchars($s['full_name']); ?></td>
                                        <td class="p-3"><span class="bg-blue-50 text-uni-blue px-2 py-1 text-xs font-bold border border-blue-100">Batch <?php echo $s['batch_number']; ?></span></td>
                                        <td class="p-3 text-right space-x-2">
                                            <a href="index.php?page=dashboard&tab=students&edit_student=<?php echo $s['id']; ?>" class="text-uni-blue"><i class="fas fa-edit"></i></a>
                                            <a href="#" onclick="confirmDelete('index.php?delete_student=<?php echo $s['id']; ?>')" class="text-red-400 hover:text-red-600"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endwhile; else: ?><tr><td colspan="4" class="p-8 text-center text-slate-400">No records found.</td></tr><?php endif; ?>
                            </tbody>
                         </table>
                     </div>
                </div>
                
                <!-- CSV Import Modal -->
                <div id="csvModal" class="fixed inset-0 bg-black/80 hidden flex items-center justify-center z-[100] p-4 backdrop-blur-sm">
                    <form method="POST" enctype="multipart/form-data" class="bg-white dark:bg-slate-800 p-8 w-full max-w-md shadow-2xl border-t-4 border-green-600 rounded-lg">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="font-bold text-xl text-slate-800 dark:text-white">Bulk Import Students</h3>
                            <button type="button" onclick="document.getElementById('csvModal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i class="fas fa-times text-xl"></i></button>
                        </div>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Upload a CSV file with columns: <br><strong>Index Number, Full Name, Batch Number</strong></p>
                        <div class="mb-6">
                            <input type="file" name="csv_file" class="w-full border p-2 rounded-sm dark:bg-slate-900 dark:border-slate-700 text-sm" accept=".csv" required>
                        </div>
                        <button type="submit" name="import_students" class="w-full bg-green-600 text-white py-3 font-bold uppercase rounded hover:bg-green-700 shadow-lg">Start Import</button>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    </div>
<?php endif; ?>

<!-- 5. LOGIN (Standard) -->
<?php if ($page == 'login'): ?>
    <div class="flex justify-center items-center h-[70vh]">
        <div class="bg-white p-10 shadow-2xl border-t-4 border-uni-blue w-full max-w-sm">
            <div class="text-center mb-8">
                <i class="fas fa-shield-alt text-4xl text-uni-blue mb-4"></i>
                <h2 class="text-2xl font-bold text-center mb-6">Staff Login</h2>
            </div>
            <?php if(isset($_GET['error'])): ?><div class="bg-red-50 text-red-600 p-3 text-xs font-bold mb-4 border border-red-100 text-center"><?php echo htmlspecialchars($_GET['error']); ?></div><?php endif; ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="text" name="username" placeholder="Username" class="w-full border p-3" required>
                <input type="password" name="password" placeholder="Password" class="w-full border p-3" required>
                <div class="text-right"><a href="index.php?page=forgot_password" class="text-xs text-blue-600 hover:underline">Forgot Password?</a></div>
                <button type="submit" name="login" class="btn-primary w-full py-3 font-bold uppercase">Login</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if(isRep() && $tab == 'posts' && $subtab == 'create'): ?>
    <!-- JS for student selector logic -->
    <script>
        var quill = new Quill('#quill-editor', {theme: 'snow', modules: { toolbar: [['bold', 'italic', 'underline'], [{ 'list': 'ordered'}, { 'list': 'bullet' }], ['link']] }});
        function copyQuillContent() { document.querySelector('input[name=description_html]').value = quill.root.innerHTML; }
        
        <?php 
            $allStudents = []; $batches = [];
            $sRes = $conn->query("SELECT id, index_number, batch_number, full_name FROM students ORDER BY batch_number DESC, index_number ASC");
            if($sRes) while($row = $sRes->fetch_assoc()) { $allStudents[] = $row; if(!in_array($row['batch_number'], $batches)) $batches[] = $row['batch_number']; }
        ?>
        const students = <?php echo !empty($allStudents) ? json_encode($allStudents) : '[]'; ?>;
        const batches = <?php echo !empty($batches) ? json_encode($batches) : '[]'; ?>;
        const container = document.getElementById('studentListContainer');

        function initSelector() {
            const sel = document.getElementById('batchFilter');
            if(!sel || !container) return; 
            batches.forEach(b => { let opt = document.createElement('option'); opt.value = b; opt.innerText = "Batch " + b; sel.appendChild(opt); });
            
            students.forEach(s => {
                let div = document.createElement('div');
                div.className = "student-row flex items-center gap-2 p-1.5 hover:bg-slate-50 cursor-pointer border-b border-slate-100";
                div.setAttribute('data-batch', s.batch_number);
                div.setAttribute('data-search', (s.index_number + ' ' + s.full_name).toLowerCase());
                div.innerHTML = `<input type="checkbox" name="students[]" value="${s.id}" class="student-checkbox" id="st_${s.id}">
                                 <label for="st_${s.id}" class="flex-1 cursor-pointer flex justify-between items-center">
                                    <div><div class="text-xs font-bold text-slate-700">${s.index_number}</div><div class="text-[10px] text-slate-400">${s.full_name}</div></div>
                                    <span class="text-[9px] bg-slate-100 px-1 border">B${s.batch_number}</span>
                                 </label>`;
                container.appendChild(div);
            });
            filterStudents();
        }
        function filterStudents() {
            const batch = document.getElementById('batchFilter').value;
            const search = document.getElementById('studentSearch').value.toLowerCase();
            document.querySelectorAll('.student-row').forEach(row => {
                const matchBatch = (batch === 'all' || row.getAttribute('data-batch') == batch);
                const matchSearch = row.getAttribute('data-search').includes(search);
                if (matchBatch && matchSearch) row.classList.remove('hidden-row'); else row.classList.add('hidden-row');
            });
        }
        function toggleSelectVisible() {
            const visibleRows = document.querySelectorAll('.student-row:not(.hidden-row) .student-checkbox');
            let allChecked = true; visibleRows.forEach(cb => { if(!cb.checked) allChecked = false; });
            visibleRows.forEach(cb => cb.checked = !allChecked);
        }
        initSelector();
    </script>
<?php endif; ?>

<script>
    function toggleMobileMenu() { document.getElementById('mobileMenu').classList.toggle('translate-x-full'); }
    function confirmDelete(url) { const modal = document.getElementById('deleteModal'); document.getElementById('confirmDeleteBtn').href = url; modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeDeleteModal() { const modal = document.getElementById('deleteModal'); modal.classList.add('hidden'); modal.classList.remove('flex'); }

    document.addEventListener('DOMContentLoaded', function() {
        const mobileBtn = document.querySelector('header button.md\\:hidden');
        if (mobileBtn) { mobileBtn.onclick = function(e) { e.preventDefault(); toggleMobileMenu(); }; }
    });
</script>

<?php include 'footer.php'; ?>
