<?php
set_time_limit(0);
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>alert('ไม่พบแฟ้มคดีที่ระบุ'); window.location.href='cases.php';</script>";
    exit();
}

$case_id = $_GET['id'];
$alert_script = "";

try { $conn->exec("ALTER TABLE detections ADD COLUMN is_verified TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}

$stmt = $conn->prepare("SELECT * FROM case_file WHERE case_file_id = ?");
$stmt->execute([$case_id]);
$case_info = $stmt->fetch();

if (!$case_info) { die("<div style='color:red; text-align:center; margin-top:50px;'>ไม่พบข้อมูลคดีในระบบ</div>"); }

// 1. จัดการการอัปโหลดวิดีโอ
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["video_file"])) {
    $target_dir = "uploads/case_" . $case_id . "/"; 
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true); 
    
    $file_name = basename($_FILES["video_file"]["name"]);
    $unique_file_name = time() . "_" . $file_name; 
    $target_file = $target_dir . $unique_file_name;
    $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
    
    if (in_array($file_type, ["mp4", "avi", "mov"])) {
        if (move_uploaded_file($_FILES["video_file"]["tmp_name"], $target_file)) {
            $uploaded_by = $_SESSION['user_id'];
            $stmt = $conn->prepare("INSERT INTO videos (case_file_id, video_path, uploaded_by) VALUES (?, ?, ?)");
            if ($stmt->execute([$case_id, $target_file, $uploaded_by])) {
                $alert_script = "Swal.fire({icon: 'success', title: 'อัปโหลดสำเร็จ!', text: 'นำเข้าวิดีโอเข้าสู่แฟ้มคดีเรียบร้อยแล้ว', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#3b82f6'});";
            }
        }
    }
}

// 2. ระบบรัน AI (แยกโหมดชัดเจน)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['simulate_ai'])) {
    $video_id = $_POST['video_id'];
    $ai_mode = isset($_POST['ai_mode']) ? $_POST['ai_mode'] : 'quick';

    $stmt_vid = $conn->prepare("SELECT video_path FROM videos WHERE video_id = ? AND case_file_id = ?");
    $stmt_vid->execute([$video_id, $case_id]);
    $vid_info = $stmt_vid->fetch();
    if ($vid_info) {
        $video_path = $vid_info['video_path'];
        $python_path = '"C:\Users\NITRO V15\AppData\Local\Programs\Python\Python311\python.exe"';
        $command = $python_path . " ai_engine.py " . escapeshellarg($video_id) . " " . escapeshellarg($video_path) . " " . escapeshellarg($ai_mode);
        shell_exec($command . " 2>&1"); 
        
        $msg_text = ($ai_mode === 'quick') ? 'ดึงข้อมูลภาพเป้าหมายสำเร็จแล้ว' : 'สร้างวิดีโอ AI ตีกรอบสำเร็จแล้ว';
        $alert_script = "Swal.fire({icon: 'success', title: 'วิเคราะห์เสร็จสิ้น!', text: '🤖 $msg_text', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#3b82f6'});";
    }
}

// 3. ระบบจัดการ ยืนยัน/ลบ ข้อมูล AI
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_detection'])) {
    $det_id = $_POST['detection_id'];
    $action = $_POST['action_type'];

    if ($action === 'verify') {
        $stmt = $conn->prepare("UPDATE detections SET is_verified = 1 WHERE detection_id = ?");
        if ($stmt->execute([$det_id])) {
            if (function_exists('saveLog')) saveLog($conn, $_SESSION['user_id'], "อนุมัติความถูกต้องหลักฐาน AI (รหัส: " . $det_id . " ในคดี #" . $case_id . ")");
            $alert_script = "Swal.fire({icon: 'success', title: 'อนุมัติหลักฐานสำเร็จ!', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#10b981', timer: 1500, showConfirmButton: false});";
        }
    } elseif ($action === 'submit_check') {
        $stmt = $conn->prepare("UPDATE detections SET is_verified = 2 WHERE detection_id = ?");
        if ($stmt->execute([$det_id])) {
            if (function_exists('saveLog')) saveLog($conn, $_SESSION['user_id'], "ส่งหลักฐาน AI ให้หัวหน้าตรวจสอบ (รหัส: " . $det_id . " ในคดี #" . $case_id . ")");
            $alert_script = "Swal.fire({icon: 'info', title: 'ส่งตรวจเรียบร้อย!', text: 'ข้อมูลถูกส่งให้หัวหน้างานตรวจสอบแล้ว', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#3b82f6', timer: 1500, showConfirmButton: false});";
        }
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM detections WHERE detection_id = ?");
        if ($stmt->execute([$det_id])) {
            if (function_exists('saveLog')) saveLog($conn, $_SESSION['user_id'], "ลบหลักฐานที่ AI ตรวจจับผิดพลาด (รหัส: " . $det_id . " ในคดี #" . $case_id . ")");
            $alert_script = "Swal.fire({icon: 'success', title: 'ลบข้อมูลสำเร็จ', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#ef4444', timer: 1500, showConfirmButton: false});";
        }
    }
}

$stmt = $conn->prepare("SELECT v.*, u.full_name FROM videos v LEFT JOIN users u ON v.uploaded_by = u.user_id WHERE v.case_file_id = ? ORDER BY v.video_id DESC");
$stmt->execute([$case_id]);
$videos = $stmt->fetchAll();

// 4. ระบบค้นหาแบบละเอียด & แบ่งหน้า
$search_plate    = isset($_GET['plate']) ? trim($_GET['plate']) : '';
$search_type     = isset($_GET['type']) ? trim($_GET['type']) : '';
$search_color    = isset($_GET['color']) ? trim($_GET['color']) : '';
$search_date     = isset($_GET['date']) ? trim($_GET['date']) : '';
$search_video    = isset($_GET['video_id']) ? trim($_GET['video_id']) : '';
$search_time_str = isset($_GET['time_str']) ? trim($_GET['time_str']) : '';
$search_verified = isset($_GET['verified']) ? trim($_GET['verified']) : ''; 

$search_second = '';
if ($search_time_str !== '') {
    if (strpos($search_time_str, ':') !== false) {
        $parts = explode(':', $search_time_str);
        $search_second = ((int)$parts[0] * 60) + (int)$parts[1];
    } else {
        $search_second = (int)$search_time_str; 
    }
}

$records_per_page = 12; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

// JOIN คดีต้นทาง (sc)
$base_sql = "FROM detections d 
             JOIN images i ON d.image_id = i.image_id 
             JOIN videos v ON i.video_id = v.video_id 
             JOIN case_file c ON v.case_file_id = c.case_file_id
             LEFT JOIN suspect_vehicle sv ON REPLACE(d.detected_license_plate, ' ', '') = REPLACE(sv.license_plate, ' ', '')
             LEFT JOIN case_file sc ON sv.case_file_id = sc.case_file_id
             WHERE v.case_file_id = ?";
$params = [$case_id];

if ($search_plate !== '') { $base_sql .= " AND d.detected_license_plate LIKE ?"; $params[] = "%$search_plate%"; }
if ($search_type !== '') { $base_sql .= " AND d.detected_vehicle_type LIKE ?"; $params[] = "%$search_type%"; }
if ($search_color !== '') { $base_sql .= " AND d.detected_color LIKE ?"; $params[] = "%$search_color%"; }
if ($search_date !== '') { $base_sql .= " AND DATE(d.detected_at) = ?"; $params[] = $search_date; }
if ($search_video !== '') { $base_sql .= " AND v.video_id = ?"; $params[] = $search_video; }
if ($search_second !== '') { $base_sql .= " AND FLOOR(i.frame_number / COALESCE(NULLIF(v.fps, 0), 30)) = ?"; $params[] = $search_second; }
if ($search_verified !== '') { $base_sql .= " AND d.is_verified = ?"; $params[] = $search_verified; }

$count_sql = "SELECT COUNT(*) " . $base_sql;
$stmt_count = $conn->prepare($count_sql);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

$main_sql = "SELECT d.*, i.image_path, i.frame_number, v.video_id, v.video_path, v.fps, 
                    c.case_name, c.official_case_no, c.case_file_id, 
                    sv.suspect_type, sc.official_case_no AS suspect_official_no, sc.case_name AS suspect_case_name " 
            . $base_sql . " ORDER BY d.is_verified DESC, d.detected_at DESC LIMIT $offset, $records_per_page";
$stmt_ai = $conn->prepare($main_sql);
$stmt_ai->execute($params);
$detections = $stmt_ai->fetchAll();

$current_get = $_GET;
unset($current_get['page']);
$qs = http_build_query($current_get);
$qs = $qs ? '&' . $qs : '';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายละเอียดคดี | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; padding: 0; color: #cbd5e1; min-height: 100vh; display: flex; flex-direction: column; }
        .container { width: 90%; max-width: 1050px; margin: 40px auto; background: #1e293b; padding: 35px; border-radius: 12px; border: 1px solid #334155; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2); flex: 1; box-sizing: border-box; }
        h2, h3, h4 { color: #f8fafc; font-weight: 600; }
        
        .case-header { background-color: #0f172a; padding: 20px 25px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #334155; border-left: 4px solid #3b82f6;}
        .case-header h3 { margin-top: 0; margin-bottom: 15px; font-size: 20px; border-bottom: 1px dashed #334155; padding-bottom: 10px;}
        
        .upload-box { border: 2px dashed #475569; padding: 30px; text-align: center; border-radius: 8px; margin-bottom: 30px; background-color: #0f172a; transition: all 0.2s ease;}
        .upload-box:hover { border-color: #3b82f6; background-color: rgba(59, 130, 246, 0.05); }
        .upload-box input[type="file"] { color: #94a3b8; margin-bottom: 15px;}

        .btn { border-radius: 6px; cursor: pointer; font-family: 'Prompt', sans-serif; font-size: 14px; font-weight: 500; padding: 8px 16px; border: none; transition: all 0.2s; }
        .btn-primary { background-color: #3b82f6; color: white; }
        .btn-primary:hover { background-color: #2563eb; transform: translateY(-1px); }
        .btn-success { background-color: #10b981; color: white; }
        .btn-success:hover { background-color: #059669; }
        .btn-outline { background-color: transparent; border: 1px solid #475569; color: #cbd5e1; }
        .btn-outline:hover { background-color: #334155; color: #f8fafc; }
        
        .btn-pdf { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background-color: #f59e0b; color: white; border: none; padding: 10px 20px; font-family: 'Prompt', sans-serif; font-size: 15px; font-weight: 500; border-radius: 6px; transition: all 0.2s; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.2);}
        .btn-pdf:hover { background-color: #d97706; transform: translateY(-2px); box-shadow: 0 6px 8px -1px rgba(245, 158, 11, 0.3);}

        table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 30px; background: #0f172a; border-radius: 8px; overflow: hidden; border: 1px solid #334155;}
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid #334155; vertical-align: middle; }
        th { background-color: #1e293b; color: #94a3b8; font-weight: 500; font-size: 14px;}
        tr:hover { background-color: #1e293b; }
        
        .search-container { background-color: #0f172a; padding: 24px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #334155; }
        .search-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .search-item label { font-weight: 500; margin-bottom: 8px; color: #e2e8f0; font-size: 14px; display: block; }
        .search-item input, .search-item select { width: 100%; padding: 10px 12px; border: 1px solid #475569; border-radius: 6px; background-color: #1e293b; color: #f8fafc; font-family: 'Prompt', sans-serif; box-sizing: border-box; transition: border-color 0.2s;}
        .search-item input:focus, .search-item select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }
        .search-actions { display: flex; gap: 12px; margin-top: 10px; }
        .search-actions .btn { flex: 1; padding: 12px; font-size: 15px; }

        .ai-results { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }
        .card { background-color: #0f172a; border: 1px solid #334155; border-radius: 10px; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; position: relative; }
        .card:hover { transform: translateY(-4px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); border-color: #475569;}
        .card.alert-card { border: 1px solid #ef4444; }
        
        .card-img-wrapper { position: relative; }
        .card img { width: 100%; height: 160px; object-fit: cover; border-bottom: 1px solid #334155; display: block;}
        .clickable-img { cursor: pointer; transition: opacity 0.2s ease; }
        .clickable-img:hover { opacity: 0.8; }
        .detect-check { position: absolute; top: 10px; left: 10px; z-index: 10; cursor: pointer; width: 22px; height: 22px; accent-color: #f59e0b; box-shadow: 0 0 10px rgba(0,0,0,0.8); border-radius: 4px; }

        .card-body { padding: 15px; }
        .plate-badge { background-color: #334155; color: #f8fafc; font-size: 18px; font-weight: 600; padding: 6px 12px; border-radius: 6px; display: inline-block; border: 1px solid #475569;}
        .plate-alert { background-color: rgba(239, 68, 68, 0.1); color: #ef4444; border-color: #ef4444; }
        .info { margin: 4px 0; font-size: 13px; color: #94a3b8; }
        .info b { color: #cbd5e1; font-weight: 500;}
        .confidence { font-weight: 600; color: #10b981; }
        .alert-text { color: #ef4444; font-weight: 600; font-size: 13px; margin-bottom: 12px; background: rgba(239, 68, 68, 0.1); padding: 8px; border-radius: 6px; display: flex; align-items: center; gap: 8px; border: 1px solid rgba(239, 68, 68, 0.2);}

        .pagination { display: flex; justify-content: center; align-items: center; margin-top: 40px; gap: 10px; }
        .page-btn { background-color: #0f172a; color: #94a3b8; border: 1px solid #334155; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s ease; }
        .page-btn:hover { background-color: #3b82f6; color: #ffffff; border-color: #3b82f6; }
        .page-btn.disabled { opacity: 0.5; pointer-events: none; }
        .page-info { color: #cbd5e1; font-size: 14px; background-color: #1e293b; padding: 8px 16px; border-radius: 6px; border: 1px solid #334155; }

        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(15, 23, 42, 0.9); backdrop-filter: blur(4px); }
        .modal-content-video { background-color: #1e293b; margin: 40px auto; padding: 20px; border-radius: 12px; width: 80%; max-width: 1000px; position: relative; border: 1px solid #334155; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .close-btn { color: #94a3b8; position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer; transition: color 0.2s; z-index: 1101;}
        .close-btn:hover { color: #f8fafc; }
        
        video { width: 100%; max-height: 70vh; border-radius: 8px; outline: none; margin-top: 10px; background-color: #000; object-fit: contain; }

        #galleryModal { z-index: 1100; background-color: rgba(15, 23, 42, 0.95); backdrop-filter: blur(8px); }
        .gallery-container { display: flex; width: 90%; max-width: 1000px; height: 75vh; margin: 5% auto; background-color: #1e293b; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.8); border: 1px solid #334155; overflow: hidden; position: relative; }
        .gallery-details { width: 320px; background-color: #1e293b; padding: 30px; border-right: 1px solid #334155; display: flex; flex-direction: column; overflow-y: auto; }
        .gallery-image-box { flex: 1; background-color: #0f172a; display: flex; align-items: center; justify-content: center; padding: 20px; position: relative; }
        
        .gal-img-wrapper { position: relative; display: inline-block; line-height: 0; }
        #galFullImage { max-width: 100%; max-height: 70vh; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); cursor: crosshair; }
        
        .img-zoom-lens { position: absolute; border: 2px solid #3b82f6; border-radius: 12px; width: 150px; height: 150px; background-repeat: no-repeat; display: none; box-shadow: 0 0 15px rgba(59, 130, 246, 0.4), inset 0 0 10px rgba(59, 130, 246, 0.2); z-index: 20; pointer-events: none; }

        .nav-btn { position: absolute; top: 50%; transform: translateY(-50%); background-color: rgba(30, 41, 59, 0.8); color: white; border: 1px solid #475569; width: 50px; height: 50px; border-radius: 50%; font-size: 20px; font-weight: bold; cursor: pointer; transition: all 0.2s; display: flex; justify-content: center; align-items: center; z-index: 100; }
        .nav-btn:hover { background-color: #3b82f6; border-color: #3b82f6; }
        .prev-btn { left: 20px; }
        .next-btn { right: 20px; }

        #reportModal .modal-content { background-color: #1e293b; margin: 5% auto; padding: 30px; border-radius: 12px; width: 90%; max-width: 600px; position: relative; border: 1px solid #334155; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .report-textarea { width: 100%; height: 150px; padding: 15px; border: 1px solid #475569; border-radius: 8px; background-color: #0f172a; color: #f8fafc; font-family: 'Prompt', sans-serif; font-size: 14px; box-sizing: border-box; resize: vertical; margin-bottom: 20px;}
        .report-textarea:focus { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2); }
        .info-alert { background-color: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2); color: #93c5fd; padding: 12px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; }
        .warning-alert { background-color: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2); color: #fcd34d; padding: 12px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; }

        .status-content-mini { display: flex; align-items: center; justify-content: center; gap: 8px; background: rgba(16, 185, 129, 0.05); border: 1px dashed rgba(16, 185, 129, 0.4); border-radius: 6px; padding: 0 10px; height: 42px; box-sizing: border-box; width: 100%; }
        .mini-scanner-small { position: relative; width: 24px; height: 24px; min-width: 24px; border-radius: 4px; background: rgba(15, 23, 42, 0.5); overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 14px; border: 1px solid rgba(16, 185, 129, 0.2); }
        .mini-scan-line-small { position: absolute; top: 0; left: 0; width: 100%; height: 2px; background: #10b981; box-shadow: 0 0 5px #10b981; animation: miniScan 2s infinite linear; }
        @keyframes miniScan { 0% { top: 0; opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } 100% { top: 100%; opacity: 0; } }
        
        .ai-status-text-mini { display: flex; align-items: center; gap: 6px; color: #10b981; font-size: 12px; font-weight: 600; letter-spacing: 0.5px; white-space: nowrap; }
        .live-dot-small { width: 6px; height: 6px; background-color: #10b981; border-radius: 50%; box-shadow: 0 0 6px #10b981; animation: blink 1.5s infinite ease-in-out; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        @keyframes drive { 0% { transform: translateX(-40px) scaleX(1); } 45% { transform: translateX(40px) scaleX(1); } 50% { transform: translateX(40px) scaleX(-1); } 95% { transform: translateX(-40px) scaleX(-1); } 100% { transform: translateX(-40px) scaleX(1); } }
        .police-car-loader { font-size: 60px; display: inline-block; animation: drive 2s infinite linear; margin-bottom: 10px; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <div class="case-header">
        <h3>แฟ้มคดี: <?php echo htmlspecialchars($case_info['case_name']); ?></h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
            <div>
                <p style="color: #94a3b8; margin: 0; font-size: 14px; line-height: 1.8;">
                    <b style="color:#f8fafc;">เลขที่รับแจ้งความ:</b> <?php echo !empty($case_info['official_case_no']) ? "<span style='color:#3b82f6;'>".htmlspecialchars($case_info['official_case_no'])."</span>" : '<span style="color:#64748b;">(รอระบุ)</span>'; ?> <br>
                    <b style="color:#f8fafc;">ชื่อผู้แจ้ง:</b> <?php echo !empty($case_info['reporter_name']) ? htmlspecialchars($case_info['reporter_name']) : '-'; ?> <br>
                    <b style="color:#f8fafc;">เบอร์โทรศัพท์:</b> <?php echo !empty($case_info['reporter_phone']) ? htmlspecialchars($case_info['reporter_phone']) : '-'; ?>
                </p>
            </div>
            <div>
                <p style="color: #94a3b8; margin: 0; font-size: 14px; line-height: 1.8;">
                    <b style="color:#f8fafc;">วันที่เกิดเหตุ:</b> <?php echo !empty($case_info['incident_date']) ? date('d/m/Y', strtotime($case_info['incident_date'])) : '-'; ?> <br>
                    <b style="color:#f8fafc;">จังหวัด (ป้าย):</b> <?php echo !empty($case_info['plate_province']) ? htmlspecialchars($case_info['plate_province']) : '-'; ?> <br>
                    <b style="color:#f8fafc;">สร้างข้อมูลเมื่อ:</b> <?php echo date('d/m/Y H:i', strtotime($case_info['created_at'])); ?>
                </p>
            </div>
        </div>
        
        <?php if (!empty($case_info['case_details'])): ?>
        <div style="background: rgba(15, 23, 42, 0.5); padding: 10px 15px; border-radius: 6px; border-left: 3px solid #f59e0b; font-size: 14px;">
            <b style="color:#f59e0b;">พฤติการณ์/รายละเอียด:</b> <?php echo nl2br(htmlspecialchars($case_info['case_details'])); ?>
        </div>
        <?php endif; ?>
    </div>

    <div style="margin-bottom: 25px;">
        <button type="button" class="btn-pdf" onclick="openReportModal()">📄 ร่างรายงาน PDF</button>
    </div>
    
    <?php if ($_SESSION['role'] !== 'LEADER'): ?>
    <div class="upload-box">
        <h4 style="margin-top:0;">อัปโหลดวิดีโอหลักฐาน</h4>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="video_file" accept=".mp4,.avi,.mov" required> <br>
            <button type="submit" class="btn btn-primary">⬆️ อัปโหลดไฟล์</button>
        </form>
    </div>
    <?php endif; ?>

    <h3>วิดีโอในระบบ (คดี #<?php echo $case_id; ?>)</h3>
    
    <!-- ✨ ตารางวิดีโอ: ปุ่มเก่าอยู่ครบทั้งหมด ✨ -->
    <table>
        <tr>
            <th width="10%">รหัส</th>
            <th width="30%">ชื่อไฟล์</th>
            <th width="15%">ผู้บันทึก</th>
            <th width="15%">วิดีโอต้นฉบับ</th>
            <th width="30%">การวิเคราะห์ AI</th>
        </tr>
        <?php foreach ($videos as $row): ?>
        <tr>
            <td style="color: #cbd5e1;">V-<?php echo $row['video_id']; ?></td>
            <td style="color: #f8fafc; word-break: break-all;"><?php echo htmlspecialchars(basename($row['video_path'])); ?></td>
            <td><?php echo htmlspecialchars($row['full_name']); ?></td>
            
            <td>
                <button class="btn btn-outline" style="border-color: #3b82f6; color: #3b82f6;" onclick="openVideo('<?php echo htmlspecialchars($row['video_path']); ?>', 0)">▶️ เล่นต้นฉบับ</button>
            </td>
            
            <td>
                <?php if ($_SESSION['role'] !== 'LEADER'): ?>
                <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                    <!-- ปุ่มสแกนด่วน -->
                    <form id="ai-quick-<?php echo $row['video_id']; ?>" method="POST" style="margin: 0;">
                        <input type="hidden" name="video_id" value="<?php echo $row['video_id']; ?>">
                        <input type="hidden" name="simulate_ai" value="1">
                        <input type="hidden" name="ai_mode" value="quick">
                        <button type="button" class="btn btn-outline" style="border-color: #10b981; color: #10b981; padding: 6px 10px; font-size: 13px;" onclick="startAIQuick(<?php echo $row['video_id']; ?>)">🤖 สแกนหาเป้าหมาย</button>
                    </form>

                    <!-- เช็กไฟล์ตีกรอบ เพื่อโชว์ปุ่มดู หรือปุ่มสร้างวิดีโอใหม่ -->
                    <?php 
                        $path_parts = pathinfo($row['video_path']);
                        $detected_path = $path_parts['dirname'] . '/' . $path_parts['filename'] . '_annotated.mp4';
                    ?>
                    <?php if (file_exists($detected_path)): ?>
                        <button class="btn btn-outline" style="border-color: #f59e0b; color: #f59e0b; padding: 6px 10px; font-size: 13px;" onclick="openVideo('<?php echo htmlspecialchars($detected_path); ?>', 0)">▶️ ดูวิดีโอ AI</button>
                    <?php else: ?>
                        <form id="ai-full-<?php echo $row['video_id']; ?>" method="POST" style="margin: 0;">
                            <input type="hidden" name="video_id" value="<?php echo $row['video_id']; ?>">
                            <input type="hidden" name="simulate_ai" value="1">
                            <input type="hidden" name="ai_mode" value="full">
                            <button type="button" class="btn btn-outline" style="border-color: #f59e0b; color: #f59e0b; padding: 6px 10px; font-size: 13px;" onclick="startAIFull(<?php echo $row['video_id']; ?>)">🎬 สร้างวิดีโอตีกรอบ</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div style="border-top: 1px solid #334155; margin: 40px 0;"></div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h3 style="margin: 0;">ผลการวิเคราะห์จาก AI <span style="font-size: 16px; color: #94a3b8; font-weight: 400;">(ทั้งหมด <?php echo $total_records; ?> คัน)</span></h3>
        <?php if (count($detections) > 0 && ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'LEADER')): ?>
            <button type="button" class="btn btn-outline" style="padding: 6px 12px; font-size: 13px;" onclick="toggleSelectAll()">☑️ เลือก/ยกเลิก ทั้งหมดหน้าปัจจุบัน</button>
        <?php endif; ?>
    </div>

    <div class="search-container">
        <form method="GET" action="case_detail.php">
            <input type="hidden" name="id" value="<?php echo $case_id; ?>">
            <div class="search-grid">
                
                <div class="search-item">
                    <label>สถานะการตรวจสอบ</label>
                    <select name="verified">
                        <option value="">-- ทั้งหมด --</option>
                        <option value="1" <?php if($search_verified === '1') echo 'selected'; ?>>✅ ยืนยันแล้ว</option>
                        <option value="2" <?php if($search_verified === '2') echo 'selected'; ?>>📤 ส่งตรวจแล้ว</option>
                        <option value="0" <?php if($search_verified === '0') echo 'selected'; ?>>⏳ รอตรวจสอบ</option>
                    </select>
                </div>

                <div class="search-item">
                    <label>รหัสวิดีโอ</label>
                    <select name="video_id">
                        <option value="">-- ทุกวิดีโอ --</option>
                        <?php foreach ($videos as $v): ?>
                            <option value="<?php echo $v['video_id']; ?>" <?php echo ($search_video == $v['video_id']) ? 'selected' : ''; ?>>
                                V-<?php echo $v['video_id']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-item"><label>ระบุเวลา (นาที:วินาที)</label><input type="text" name="time_str" placeholder="เช่น 01:15" value="<?php echo htmlspecialchars($search_time_str); ?>"></div>
                <div class="search-item"><label>ป้ายทะเบียน</label><input type="text" name="plate" placeholder="ค้นหาบางส่วน..." value="<?php echo htmlspecialchars($search_plate); ?>"></div>
                
                <div class="search-item">
                    <label>ประเภทรถ</label>
                    <select name="type">
                        <option value="">-- ทุกประเภท --</option>
                        <option value="รถยนต์" <?php if($search_type=='รถยนต์') echo 'selected'; ?>>รถยนต์</option>
                        <option value="รถจักรยานยนต์" <?php if($search_type=='รถจักรยานยนต์') echo 'selected'; ?>>รถจักรยานยนต์</option>
                        <option value="รถบรรทุก" <?php if($search_type=='รถบรรทุก') echo 'selected'; ?>>รถบรรทุก</option>
                        <option value="รถบัส" <?php if($search_type=='รถบัส') echo 'selected'; ?>>รถบัส</option>
                    </select>
                </div>
                <div class="search-item"><label>สีรถ</label><input type="text" name="color" placeholder="เช่น ดำ, ขาว" value="<?php echo htmlspecialchars($search_color); ?>"></div>
                <div class="search-item"><label>วันที่บันทึก (AI)</label><input type="date" name="date" value="<?php echo htmlspecialchars($search_date); ?>"></div>
                
                <div class="search-item">
                    <label style="visibility: hidden;">Status</label>
                    <div class="status-content-mini">
                        <div class="mini-scanner-small"><div class="mini-scan-line-small"></div>🚘</div>
                        <div class="ai-status-text-mini">
                            <div class="live-dot-small"></div> AI SYSTEM ACTIVE
                        </div>
                    </div>
                </div>

            </div>
            <div class="search-actions">
                <button type="submit" class="btn btn-primary">🔍 ค้นหาและกรองข้อมูล</button>
                <a href="case_detail.php?id=<?php echo $case_id; ?>" class="btn btn-outline" style="text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; box-sizing: border-box;">ล้างค่า</a>
            </div>
        </form>
    </div>

    <div class="ai-results">
        <?php if (count($detections) > 0): ?>
            <?php 
            $index = 0; 
            foreach ($detections as $ai): 
            ?>
                <div class="card <?php echo $ai['suspect_type'] ? 'alert-card' : ''; ?>">
                    
                    <div class="card-img-wrapper">
                        <input type="checkbox" class="detect-check" value="<?php echo $ai['detection_id']; ?>" title="เลือกคันนี้เพื่อพริ้นต์ PDF">
                        <!-- กดที่รูป เปิดหน้าต่าง Gallery เหมือนเดิม -->
                        <img src="<?php echo htmlspecialchars($ai['image_path']); ?>" alt="Vehicle Image" class="clickable-img" onclick="openGallery(<?php echo $index; ?>)" title="คลิกเพื่อดูรายละเอียด">
                    </div>
                    
                    <div class="card-body">
                        <?php if ($ai['suspect_type']): ?><div class="alert-text">🚨 ตรวจพบรถต้องสงสัย!</div><?php endif; ?>
                        
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div class="plate-badge <?php echo $ai['suspect_type'] ? 'plate-alert' : ''; ?>"><?php echo htmlspecialchars($ai['detected_license_plate']); ?></div>
                            
                            <?php if (isset($ai['is_verified']) && $ai['is_verified'] == 1): ?>
                                <span style="background: rgba(16, 185, 129, 0.2); color: #10b981; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; border: 1px solid rgba(16, 185, 129, 0.3);">✅ ยืนยันแล้ว</span>
                            <?php elseif (isset($ai['is_verified']) && $ai['is_verified'] == 2): ?>
                                <span style="background: rgba(59, 130, 246, 0.2); color: #60a5fa; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; border: 1px solid rgba(59, 130, 246, 0.3);">📤 ส่งตรวจแล้ว</span>
                            <?php else: ?>
                                <span style="font-size: 11px; padding: 3px 8px; border-radius: 4px; background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); vertical-align: super; margin-left: 5px;">⏳ รอตรวจสอบ</span>
                            <?php endif; ?>
                        </div>

                        <p class="info"><b>ประเภท:</b> <?php echo htmlspecialchars($ai['detected_vehicle_type']); ?></p>
                        <p class="info"><b>สีรถ:</b> <?php echo htmlspecialchars($ai['detected_color']); ?></p>
                        <p class="info"><b>ความแม่นยำ AI:</b> <span class="confidence"><?php echo number_format($ai['confidence_score'], 2); ?>%</span></p>
                        
                        <?php 
                            $fps = (!empty($ai['fps']) && $ai['fps'] > 0) ? $ai['fps'] : 30;
                            $frame_num = !empty($ai['frame_number']) ? $ai['frame_number'] : 0;
                            $seconds = floor($frame_num / $fps); 
                            $time_display = gmdate("i:s", $seconds); 
                        ?>
                        <div style="background-color: #1e293b; padding: 8px; border-radius: 6px; margin-top: 10px; border: 1px solid #334155;">
                            <p class="info" style="color: #e2e8f0; font-weight: 500; text-align: center; margin: 0 0 8px 0;">⏱️ นาทีที่: <?php echo $time_display; ?> (V-<?php echo $ai['video_id']; ?>)</p>
                            <!-- ✨ นำปุ่มเปิดดูวิดีโอเดิมกลับมาในการ์ดด้วย ✨ -->
                            <button class="btn btn-outline" style="width: 100%; border-color: #3b82f6; color: #3b82f6; padding: 6px;" onclick="openVideo('<?php echo htmlspecialchars($ai['video_path']); ?>', <?php echo $seconds; ?>)">▶️ เปิดดูวิดีโอต้นฉบับ</button>
                        </div>

                        <div style="display: flex; gap: 8px; margin-top: 10px;">
                            <?php if ($_SESSION['role'] === 'USER'): ?>
                                <?php if (!isset($ai['is_verified']) || $ai['is_verified'] == 0): ?>
                                    <form method="POST" style="flex: 1;">
                                        <input type="hidden" name="detection_id" value="<?php echo $ai['detection_id']; ?>">
                                        <input type="hidden" name="action_type" value="submit_check">
                                        <button type="submit" name="action_detection" class="btn btn-outline" style="width: 100%; border-color: #f59e0b; color: #f59e0b; padding: 6px; font-size: 13px; background: rgba(245, 158, 11, 0.05);">📤 ส่งตรวจ</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'LEADER'): ?>
                                <?php if (isset($ai['is_verified']) && $ai['is_verified'] == 2): ?>
                                    <form method="POST" style="flex: 1;">
                                        <input type="hidden" name="detection_id" value="<?php echo $ai['detection_id']; ?>">
                                        <input type="hidden" name="action_type" value="verify">
                                        <button type="submit" name="action_detection" class="btn btn-outline" style="width: 100%; border-color: #10b981; color: #10b981; padding: 6px; font-size: 13px; background: rgba(16, 185, 129, 0.05);">✅ อนุมัติ</button>
                                    </form>
                                <?php elseif (!isset($ai['is_verified']) || $ai['is_verified'] == 0): ?>
                                    <form method="POST" style="flex: 1;">
                                        <input type="hidden" name="detection_id" value="<?php echo $ai['detection_id']; ?>">
                                        <input type="hidden" name="action_type" value="verify">
                                        <button type="submit" name="action_detection" class="btn btn-outline" style="width: 100%; border-color: #10b981; color: #10b981; padding: 6px; font-size: 13px; background: rgba(16, 185, 129, 0.05);">✅ ยืนยัน</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <form method="POST" style="flex: 1;" onsubmit="return confirm('⚠️ คุณแน่ใจหรือไม่ว่าต้องการลบข้อมูลนี้ทิ้ง?');">
                                <input type="hidden" name="detection_id" value="<?php echo $ai['detection_id']; ?>">
                                <input type="hidden" name="action_type" value="delete">
                                <button type="submit" name="action_detection" class="btn btn-outline" style="width: 100%; border-color: #ef4444; color: #ef4444; padding: 6px; font-size: 13px; background: rgba(239, 68, 68, 0.05);">❌ ลบทิ้ง</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php 
            $index++; 
            endforeach; 
            ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; text-align: center; background-color: #0f172a; padding: 60px 20px; border-radius: 8px; border: 1px dashed #475569;">
                <div class="radar-box"><div class="radar-scanner"></div></div>
                <p style="color: #cbd5e1; font-size: 18px; font-weight: 500; margin: 0 0 5px 0;">ไม่พบข้อมูลเป้าหมายในเวลาหรือเงื่อนไขที่ระบุ</p>
                <p style="color: #64748b; font-size: 14px; margin: 0;">ระบบ AI กำลังเฝ้าระวังและสแกนข้อมูลอย่างต่อเนื่อง...</p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <a href="?page=<?php echo $page - 1; ?><?php echo $qs; ?>" class="page-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>">« ก่อนหน้า</a>
        <span class="page-info">หน้า <b><?php echo $page; ?></b> จาก <?php echo $total_pages; ?></span>
        <a href="?page=<?php echo $page + 1; ?><?php echo $qs; ?>" class="page-btn <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">ถัดไป »</a>
    </div>
    <?php endif; ?>

</div>

<div id="reportModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeReportModal()">✖</span>
        <h3 style="margin-top: 0; margin-bottom: 20px; color: #f8fafc; border-bottom: 1px solid #334155; padding-bottom: 15px;">
            📄 สร้างรายงานการสืบสวน (PDF)
        </h3>
        <div id="reportSelectionInfo"></div>
        <form id="pdfForm" action="report.php" method="POST" target="_blank">
            <input type="hidden" name="case_id" value="<?php echo $case_id; ?>">
            <input type="hidden" name="plate" value="<?php echo htmlspecialchars($search_plate); ?>">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($search_type); ?>">
            <input type="hidden" name="color" value="<?php echo htmlspecialchars($search_color); ?>">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($search_date); ?>">
            <input type="hidden" name="video_id" value="<?php echo htmlspecialchars($search_video); ?>">
            <input type="hidden" name="second" value="<?php echo htmlspecialchars($search_second); ?>">

            <label style="display: block; color: #cbd5e1; font-weight: 500; margin-bottom: 10px;">📝 บันทึกความเห็นเจ้าหน้าที่ / รายละเอียดเพิ่มเติม</label>
            <textarea name="report_remark" class="report-textarea" placeholder="พิมพ์สรุปข้อความที่จะแนบไปในเอกสาร PDF..."></textarea>

            <div style="text-align: right;">
                <button type="button" class="btn btn-outline" style="margin-right: 10px;" onclick="closeReportModal()">ยกเลิก</button>
                <button type="button" class="btn btn-pdf" onclick="submitPDFForm()">🖨️ ยืนยันสร้างรายงาน PDF</button>
            </div>
        </form>
    </div>
</div>

<div id="videoModal" class="modal">
    <div class="modal-content-video">
        <span class="close-btn" onclick="closeVideo()">✖</span>
        <h4 style="margin-top: 0; border-bottom: 1px solid #334155; padding-bottom: 10px;">เล่นวิดีโอหลักฐาน</h4>
        <video id="myVideo" controls></video>
    </div>
</div>

<div id="galleryModal" class="modal">
    <div class="gallery-container">
        <div class="gallery-details">
            <h4 style="margin-top: 0; margin-bottom: 20px; font-size: 18px; color: #f8fafc; border-bottom: 1px solid #334155; padding-bottom: 10px;">รายละเอียดเป้าหมาย</h4>
            <div id="galAlert" class="alert-text" style="display:none;"></div>
            <div id="galPlate" class="plate-badge" style="font-size: 22px; text-align: center; margin-bottom: 20px;"></div>
            <p class="info"><b>ประเภทยานพาหนะ:</b><br> <span id="galType" style="color:#e2e8f0; font-size: 15px;"></span></p>
            <p class="info"><b>สีรถที่ตรวจจับได้:</b><br> <span id="galColor" style="color:#e2e8f0; font-size: 15px;"></span></p>
            <p class="info"><b>ความแม่นยำ AI:</b><br> <span id="galConf" class="confidence" style="font-size: 15px;"></span>%</p>
            
            <div style="background-color: #0f172a; padding: 15px; border-radius: 6px; margin-top: auto; border: 1px solid #334155;">
                <p class="info" style="color: #e2e8f0; font-weight: 500; text-align: center; margin: 0 0 10px 0;">⏱️ พบในวิดีโอนาทีที่: <span id="galTime"></span></p>
                
                <!-- 🎯 จุดเพิ่มปุ่มจัดการวิดีโอในหน้า Modal -->
                <div id="galVideoButtons"></div>
                
                <p class="info" style="font-size: 12px; text-align: center; margin:0;">บันทึกระบบ: <span id="galDate"></span></p>
            </div>
            
            <div id="galActionBox" style="margin-top: 15px; border-top: 1px solid #334155; padding-top: 15px;"></div>
        </div>
        <div class="gallery-image-box">
            <span class="close-btn" style="top: 10px; right: 15px;" onclick="closeGallery()">✖</span>
            <button class="nav-btn prev-btn" id="btnPrev" onclick="prevImage()">&#10094;</button>
            <button class="nav-btn next-btn" id="btnNext" onclick="nextImage()">&#10095;</button>
            <div class="gal-img-wrapper">
                <div id="gallery-zoom-lens" class="img-zoom-lens"></div>
                <img id="galFullImage" src="" alt="Full View">
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        var img = document.getElementById("galFullImage");
        var lens = document.getElementById("gallery-zoom-lens");
        var cx = 2.5; 
        var cy = 2.5;

        img.addEventListener("mousemove", moveLens);
        lens.addEventListener("mousemove", moveLens);
        
        img.addEventListener("mouseenter", function() {
            lens.style.display = "block";
            lens.style.backgroundImage = "url('" + img.src + "')";
            lens.style.backgroundSize = (img.width * cx) + "px " + (img.height * cy) + "px";
        });
        
        img.addEventListener("mouseleave", function() {
            lens.style.display = "none";
        });

        function moveLens(e) {
            var pos, x, y;
            e.preventDefault();
            pos = getCursorPos(e);
            x = pos.x - (lens.offsetWidth / 2);
            y = pos.y - (lens.offsetHeight / 2);
            
            if (x > img.width - lens.offsetWidth) {x = img.width - lens.offsetWidth;}
            if (x < 0) {x = 0;}
            if (y > img.height - lens.offsetHeight) {y = img.height - lens.offsetHeight;}
            if (y < 0) {y = 0;}
            
            lens.style.left = x + "px";
            lens.style.top = y + "px";
            lens.style.backgroundPosition = "-" + (x * cx) + "px -" + (y * cy) + "px";
        }

        function getCursorPos(e) {
            var a, x = 0, y = 0;
            e = e || window.event;
            a = img.getBoundingClientRect();
            x = e.pageX - a.left;
            y = e.pageY - a.top;
            x = x - window.pageXOffset;
            y = y - window.pageYOffset;
            return {x : x, y : y};
        }
    });

    function toggleSelectAll() {
        const checkboxes = document.querySelectorAll('.detect-check');
        let allChecked = true;
        checkboxes.forEach(cb => { if (!cb.checked) allChecked = false; });
        checkboxes.forEach(cb => { cb.checked = !allChecked; });
    }
    
    function openReportModal() {
        const selected = document.querySelectorAll('.detect-check:checked');
        const count = selected.length;
        const infoDiv = document.getElementById('reportSelectionInfo');
        if (count > 0) { infoDiv.innerHTML = `<div class="info-alert">✅ คุณเลือกเป้าหมายไว้ <b>${count}</b> คัน</div>`; } 
        else { infoDiv.innerHTML = `<div class="warning-alert">⚠️ ระบบจะพิมพ์ข้อมูลรถทั้งหมดตามเงื่อนไขค้นหา</div>`; }
        document.getElementById('reportModal').style.display = 'block';
    }
    
    function closeReportModal() { document.getElementById('reportModal').style.display = 'none'; }
    
    function submitPDFForm() {
        const form = document.getElementById('pdfForm');
        document.querySelectorAll('.hidden-detect-id').forEach(el => el.remove());
        document.querySelectorAll('.detect-check:checked').forEach(cb => {
            let input = document.createElement('input');
            input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = cb.value; input.className = 'hidden-detect-id';
            form.appendChild(input);
        });
        form.submit(); closeReportModal(); 
    }

    const aiData = <?php 
        $js_data = [];
        foreach ($detections as $ai) {
            $fps = (!empty($ai['fps']) && $ai['fps'] > 0) ? $ai['fps'] : 30;
            $seconds = floor((!empty($ai['frame_number']) ? $ai['frame_number'] : 0) / $fps); 
            
            // เช็กชื่อไฟล์วิดีโอตีกรอบที่ Python จะทำ (ลงท้ายด้วย _annotated.mp4)
            $path_parts = pathinfo($ai['video_path']);
            $annotated_path = $path_parts['dirname'] . '/' . $path_parts['filename'] . '_annotated.mp4';
            $has_annotated = file_exists($annotated_path) ? true : false;
            
            $js_data[] = [
                'id' => $ai['detection_id'],
                'image' => htmlspecialchars($ai['image_path']),
                'plate' => htmlspecialchars($ai['detected_license_plate']),
                'type' => htmlspecialchars($ai['detected_vehicle_type']),
                'color' => htmlspecialchars($ai['detected_color']),
                'confidence' => number_format($ai['confidence_score'], 2),
                'suspect' => $ai['suspect_type'] ? ($ai['suspect_type'] == 'STOLEN' ? 'รถถูกขโมย' : 'แจ้งเบาะแส') : '',
                'suspect_case' => $ai['suspect_type'] ? (!empty($ai['suspect_official_no']) ? htmlspecialchars($ai['suspect_official_no']) : (!empty($ai['suspect_case_name']) ? htmlspecialchars($ai['suspect_case_name']) : 'ไม่ระบุคดีต้นทาง')) : '',
                'time' => gmdate("i:s", $seconds),
                'seconds' => $seconds,
                'video' => htmlspecialchars($ai['video_path']),
                'video_id' => $ai['video_id'],
                'has_annotated' => $has_annotated,
                'annotated_video' => htmlspecialchars($annotated_path),
                'date' => date('d/m/Y H:i', strtotime($ai['detected_at'])),
                'is_verified' => isset($ai['is_verified']) ? (int)$ai['is_verified'] : 0
            ];
        }
        echo json_encode($js_data);
    ?>;

    let currentImgIndex = 0; 
    function openGallery(index) { currentImgIndex = index; updateGalleryUI(); document.getElementById('galleryModal').style.display = 'block'; }
    
    function updateGalleryUI() {
        const data = aiData[currentImgIndex];
        const userRole = '<?php echo $_SESSION['role']; ?>';
        
        document.getElementById('galFullImage').src = data.image; 
        
        var lens = document.getElementById("gallery-zoom-lens");
        var img = document.getElementById("galFullImage");
        if (lens.style.display === "block") {
            lens.style.backgroundImage = "url('" + data.image + "')";
            img.onload = function() {
                lens.style.backgroundSize = (this.width * 2.5) + "px " + (this.height * 2.5) + "px";
            }
        }

        document.getElementById('galPlate').innerText = data.plate; 
        document.getElementById('galType').innerText = data.type; 
        document.getElementById('galColor').innerText = data.color; 
        document.getElementById('galConf').innerText = data.confidence; 
        document.getElementById('galTime').innerText = data.time; 
        document.getElementById('galDate').innerText = data.date;
        
        const alertBox = document.getElementById('galAlert'); 
        const plateBadge = document.getElementById('galPlate');
        
        if(data.suspect !== '') { 
            alertBox.style.display = 'flex'; 
            alertBox.style.flexDirection = 'column';
            alertBox.innerHTML = `<span>🚨 เป้าหมาย: ${data.suspect}</span><span style="font-size:11px; color:#f8fafc; font-weight:500;">📌 จากคดี: ${data.suspect_case}</span>`; 
            plateBadge.classList.add('plate-alert'); 
        } else { 
            alertBox.style.display = 'none'; plateBadge.classList.remove('plate-alert'); 
        }
        
        document.getElementById('btnPrev').style.display = (currentImgIndex === 0) ? 'none' : 'flex'; 
        document.getElementById('btnNext').style.display = (currentImgIndex === aiData.length - 1) ? 'none' : 'flex';

        // 🎯 สคริปต์เสกปุ่มเล่นวิดีโอ/เรนเดอร์วิดีโอในหน้า Modal 
        let videoBtns = `<button class="btn btn-primary" style="width: 100%; margin-bottom: ${data.has_annotated || userRole !== 'LEADER' ? '8px' : '15px'};" onclick="playGalleryVideo()">▶️ เปิดดูวิดีโอต้นฉบับ</button>`;
        
        if (userRole !== 'LEADER') {
            if (data.has_annotated) {
                // ถ้ามีคลิปตีกรอบแล้ว ให้โชว์ปุ่มดูคลิป
                videoBtns += `<button class="btn btn-outline" style="width: 100%; border-color: #f59e0b; color: #f59e0b; margin-bottom: 15px; padding: 6px;" onclick="closeGallery(); openVideo('${data.annotated_video}', ${data.seconds})">▶️ ดูวิดีโอ AI ตีกรอบ</button>`;
            } else {
                // ถ้ายังไม่มีคลิปตีกรอบ ให้โชว์ปุ่มสั่งเรนเดอร์
                videoBtns += `<button class="btn btn-outline" style="width: 100%; border-color: #f59e0b; color: #f59e0b; margin-bottom: 15px; padding: 6px;" onclick="closeGallery(); startAIFull(${data.video_id})">🎬 สร้างวิดีโอตีกรอบ</button>`;
            }
        }
        document.getElementById('galVideoButtons').innerHTML = videoBtns;

        const actionBox = document.getElementById('galActionBox');
        let actionHtml = `<div style="display:flex; gap:8px; width: 100%;">`;
        
        if (userRole === 'USER') {
            if (data.is_verified === 0) {
                actionHtml += `<button class="btn btn-outline" style="flex:1; border-color:#f59e0b; color:#f59e0b;" onclick="submitModalAction(${data.id}, 'submit_check')">📤 ส่งให้หัวหน้าตรวจ</button>`;
            } else if (data.is_verified === 2) {
                actionHtml += `<span style="flex:1; text-align:center; padding:8px; background:rgba(59,130,246,0.2); color:#60a5fa; border-radius:6px; font-size:13px; font-weight:600;">📤 ส่งตรวจแล้ว</span>`;
            } else if (data.is_verified === 1) {
                actionHtml += `<span style="flex:1; text-align:center; padding:8px; background:rgba(16,185,129,0.2); color:#10b981; border-radius:6px; font-size:13px; font-weight:600;">✅ ยืนยันแล้ว</span>`;
            }
        } else if (userRole === 'LEADER' || userRole === 'ADMIN') {
            if (data.is_verified === 2) {
                actionHtml += `<button class="btn btn-outline" style="flex:1; border-color:#10b981; color:#10b981;" onclick="submitModalAction(${data.id}, 'verify')">✅ อนุมัติ</button>`;
            } else if (data.is_verified === 0) {
                actionHtml += `<button class="btn btn-outline" style="flex:1; border-color:#10b981; color:#10b981;" onclick="submitModalAction(${data.id}, 'verify')">✅ ยืนยัน</button>`;
            } else if (data.is_verified === 1) {
                actionHtml += `<span style="flex:1; text-align:center; padding:8px; background:rgba(16,185,129,0.2); color:#10b981; border-radius:6px; font-size:13px; font-weight:600;">✅ ยืนยันแล้ว</span>`;
            }
        }
        
        actionHtml += `<button class="btn btn-outline" style="flex:1; border-color:#ef4444; color:#ef4444;" onclick="submitModalAction(${data.id}, 'delete')">❌ ลบทิ้ง</button>`;
        actionHtml += `</div>`;
        actionBox.innerHTML = actionHtml;
    }

    function submitModalAction(id, action) {
        if(action === 'delete' && !confirm('⚠️ คุณแน่ใจหรือไม่ว่าต้องการลบข้อมูลนี้ทิ้ง?')) return;
        
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = 'case_detail.php?id=<?php echo $case_id; ?>';
        
        let idInput = document.createElement('input');
        idInput.type = 'hidden'; idInput.name = 'detection_id'; idInput.value = id;
        form.appendChild(idInput);
        
        let actionInput = document.createElement('input');
        actionInput.type = 'hidden'; actionInput.name = 'action_type'; actionInput.value = action;
        form.appendChild(actionInput);
        
        let btnInput = document.createElement('input');
        btnInput.type = 'hidden'; btnInput.name = 'action_detection'; btnInput.value = '1';
        form.appendChild(btnInput);
        
        document.body.appendChild(form);
        form.submit();
    }

    function prevImage() { if(currentImgIndex > 0) { currentImgIndex--; updateGalleryUI(); } }
    function nextImage() { if(currentImgIndex < aiData.length - 1) { currentImgIndex++; updateGalleryUI(); } }
    function closeGallery() { document.getElementById('galleryModal').style.display = "none"; }
    function playGalleryVideo() { closeGallery(); const data = aiData[currentImgIndex]; openVideo(data.video, data.seconds); }
    
    // ✨ ฟังก์ชันสแกนแบบไม่เรนเดอร์วิดีโอ (Quick Mode)
    function startAIQuick(videoId) {
        Swal.fire({
            title: 'กำลังสแกนหาป้ายทะเบียน...',
            html: '<div class="police-car-loader">🚓💨</div><br><span style="color:#94a3b8; font-size:14px;">ระบบกำลังดึงภาพยานพาหนะและสีรถ (ไม่เรนเดอร์วิดีโอ)</span>',
            background: '#1e293b',
            color: '#f8fafc',
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false
        });
        setTimeout(() => { document.getElementById('ai-quick-' + videoId).submit(); }, 800);
    }

    // ✨ ฟังก์ชันสแกนและเรนเดอร์วิดีโอตีกรอบ (Full Mode)
    function startAIFull(videoId) {
        Swal.fire({
            title: 'กำลังสร้างวิดีโอ AI ตีกรอบ...',
            html: '<div class="police-car-loader">🚓💨</div><br><span style="color:#f59e0b; font-size:14px;">ขั้นตอนนี้ใช้เวลานาน ระบบกำลังเรนเดอร์วิดีโอใหม่...</span>',
            background: '#1e293b',
            color: '#f8fafc',
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false
        });
        setTimeout(() => { document.getElementById('ai-full-' + videoId).submit(); }, 800);
    }
    
    function openVideo(path, seconds) { 
        var player = document.getElementById('myVideo'); 
        player.src = path; 
        player.load(); 
        document.getElementById('videoModal').style.display = "block"; 
        player.onloadedmetadata = function() { 
            player.currentTime = seconds !== undefined ? seconds : 0; 
            player.play(); 
        }; 
    }
    
    function closeVideo() { 
        document.getElementById('videoModal').style.display = "none"; 
        var player = document.getElementById('myVideo'); 
        player.pause(); 
        player.removeAttribute('src');
        player.load(); 
    }
</script>

</body>
</html>