<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$alert_script = "";

// ---------------------------------------------------------
//  1. จัดการเมื่อมีการกดปุ่ม (ส่งตรวจ / อนุมัติ / ลบทิ้ง) จากหน้าค้นหา
// ---------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_detection'])) {
    $det_id = $_POST['detection_id'];
    $action = $_POST['action_type'];

    if ($action === 'verify') {
        $stmt = $conn->prepare("UPDATE detections SET is_verified = 1 WHERE detection_id = ?");
        if ($stmt->execute([$det_id])) {
            if(function_exists('saveLog')) saveLog($conn, $_SESSION['user_id'], "อนุมัติความถูกต้องหลักฐาน AI (รหัส: " . $det_id . ")");
            $alert_script = "Swal.fire({icon: 'success', title: 'อนุมัติ/ยืนยัน สำเร็จ!', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#10b981', timer: 1500, showConfirmButton: false});";
        }
    } elseif ($action === 'submit_check') {
        $stmt = $conn->prepare("UPDATE detections SET is_verified = 2 WHERE detection_id = ?");
        if ($stmt->execute([$det_id])) {
            if(function_exists('saveLog')) saveLog($conn, $_SESSION['user_id'], "ส่งหลักฐานให้หัวหน้าตรวจสอบ (รหัส: " . $det_id . ")");
            $alert_script = "Swal.fire({icon: 'info', title: 'ส่งตรวจเรียบร้อย!', text: 'ข้อมูลถูกอัปเดตสถานะรอหัวหน้าตรวจสอบ', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#3b82f6', timer: 1500, showConfirmButton: false});";
        }
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM detections WHERE detection_id = ?");
        if ($stmt->execute([$det_id])) {
            if(function_exists('saveLog')) saveLog($conn, $_SESSION['user_id'], "ลบหลักฐานที่ AI ตรวจจับผิดพลาด (รหัส: " . $det_id . ")");
            $alert_script = "Swal.fire({icon: 'success', title: 'ลบข้อมูลสำเร็จ', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#ef4444', timer: 1500, showConfirmButton: false});";
        }
    }
}

// 2. ดึงข้อมูลรถต้องสงสัยทั้งหมด
$stmt_suspect = $conn->query("SELECT * FROM suspect_vehicle ORDER BY created_at DESC");
$suspect_list = $stmt_suspect->fetchAll();

// 3. รับค่าจากฟอร์มค้นหา AI
$search_plate = $_GET['plate'] ?? '';
$search_type = $_GET['type'] ?? '';
$search_color = $_GET['color'] ?? '';
$search_date = $_GET['date'] ?? '';
$search_time_str = $_GET['time_str'] ?? ''; 
$search_case = $_GET['case_id'] ?? '';
$search_verified = $_GET['verified'] ?? '';

$search_second = '';
if ($search_time_str !== '') {
    if (strpos($search_time_str, ':') !== false) {
        $parts = explode(':', $search_time_str);
        $search_second = ((int)$parts[0] * 60) + (int)$parts[1];
    } else { $search_second = (int)$search_time_str; }
}

// 4. ดึงรายชื่อแฟ้มคดีสำหรับทำ Dropdown (USER เห็นเฉพาะงานที่ได้รับมอบหมาย)
$case_sql = "SELECT case_file_id, case_name, official_case_no FROM case_file";
$case_params = [];
if ($_SESSION['role'] === 'USER') {
    $case_sql .= " WHERE assigned_to = ?";
    $case_params[] = $_SESSION['user_id'];
}
$case_sql .= " ORDER BY case_file_id DESC";
$stmt_cases = $conn->prepare($case_sql);
$stmt_cases->execute($case_params);
$case_list_dropdown = $stmt_cases->fetchAll(PDO::FETCH_ASSOC);

// 5. ระบบแบ่งหน้า AI Results
$records_per_page = 12; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

// ✨ เพิ่ม LEFT JOIN คดีต้นทาง (sc) เข้าไป
$base_sql = "FROM detections d
             JOIN images i ON d.image_id = i.image_id
             JOIN videos v ON i.video_id = v.video_id
             JOIN case_file c ON v.case_file_id = c.case_file_id
             LEFT JOIN suspect_vehicle sv ON REPLACE(d.detected_license_plate, ' ', '') = REPLACE(sv.license_plate, ' ', '')
             LEFT JOIN case_file sc ON sv.case_file_id = sc.case_file_id
             WHERE 1=1"; 
$params = [];

if ($_SESSION['role'] === 'USER') {
    $base_sql .= " AND c.assigned_to = ?";
    $params[] = $_SESSION['user_id'];
}

if (!empty($search_plate)) { $base_sql .= " AND d.detected_license_plate LIKE ?"; $params[] = "%$search_plate%"; }
if (!empty($search_type)) { $base_sql .= " AND d.detected_vehicle_type LIKE ?"; $params[] = "%$search_type%"; }
if (!empty($search_color)) { $base_sql .= " AND d.detected_color LIKE ?"; $params[] = "%$search_color%"; }
if (!empty($search_date)) { $base_sql .= " AND DATE(d.detected_at) = ?"; $params[] = $search_date; }
if ($search_case !== '') { $base_sql .= " AND c.case_file_id = ?"; $params[] = $search_case; }
if ($search_verified !== '') { $base_sql .= " AND d.is_verified = ?"; $params[] = $search_verified; }
if ($search_second !== '') { 
    $base_sql .= " AND FLOOR(i.frame_number / COALESCE(NULLIF(v.fps, 0), 30)) = ?"; 
    $params[] = $search_second; 
}

$count_sql = "SELECT COUNT(*) " . $base_sql;
$stmt_count = $conn->prepare($count_sql);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// ✨ ดึงข้อมูล suspect_official_no และ suspect_case_name ออกมา
$main_sql = "SELECT d.*, i.image_path, i.frame_number, v.video_path, v.fps, 
                    c.case_name, c.official_case_no, c.case_file_id, 
                    sv.suspect_type, sc.official_case_no AS suspect_official_no, sc.case_name AS suspect_case_name " 
            . $base_sql . " ORDER BY d.is_verified DESC, d.detected_at DESC LIMIT $offset, $records_per_page";
$stmt = $conn->prepare($main_sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

$qs_export = http_build_query(array_merge($_GET, ['page' => '']));
$qs_page = $qs_export ? '&' . $qs_export : '';
$current_qs = $_SERVER['QUERY_STRING'] ? '?'.$_SERVER['QUERY_STRING'] : '';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>AI Search Engine | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; padding: 0; color: #cbd5e1; min-height: 100vh; position: relative; overflow-x: hidden; }
        .ambient-blob { position: fixed; border-radius: 50%; filter: blur(100px); z-index: -1; opacity: 0.25; animation: drift alternate infinite ease-in-out; }
        .blob-1 { width: 500px; height: 500px; background-color: #3b82f6; top: -10%; left: -5%; }
        .blob-2 { width: 400px; height: 400px; background-color: #8b5cf6; bottom: -10%; right: -5%; }
        @keyframes drift { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(30px, 30px) scale(1.1); } }
        .container { width: 90%; max-width: 1200px; margin: 40px auto; background-color: rgba(30, 41, 59, 0.7); backdrop-filter: blur(16px); padding: 35px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        h2, h3 { color: #f8fafc; margin-top: 0; }
        .suspect-table-container { background-color: rgba(15, 23, 42, 0.6); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); border-left: 4px solid #ef4444; padding: 20px; margin-bottom: 30px; overflow-x: auto; }
        .suspect-table { width: 100%; border-collapse: collapse; min-width: 700px; }
        .suspect-table th { text-align: left; padding: 12px; color: #94a3b8; font-size: 13px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .suspect-table td { padding: 12px; font-size: 14px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; color: #f8fafc; }
        .bg-danger { background-color: #ef4444; } .bg-warning { background-color: #f59e0b; color: #1e293b; }
        .plate-text { color: #f8fafc; font-weight: 600; background-color: #334155; padding: 4px 10px; border-radius: 4px; border: 1px solid #475569; }
        .search-box { background-color: rgba(15, 23, 42, 0.5); padding: 24px; border-radius: 8px; margin-bottom: 30px; border: 1px solid rgba(255,255,255,0.05); border-top: 4px solid #3b82f6; }
        .search-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        @media (min-width: 1024px) { .search-grid { grid-template-columns: repeat(4, 1fr); } }
        .form-group { display: flex; flex-direction: column; }
        label { font-weight: 500; color: #e2e8f0; margin-bottom: 8px; font-size: 14px; display: block; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #475569; border-radius: 6px; background-color: #1e293b; color: #f8fafc; font-size: 14px; box-sizing: border-box; }
        .status-content-mini { display: flex; align-items: center; justify-content: center; gap: 8px; background: rgba(16, 185, 129, 0.05); border: 1px dashed rgba(16, 185, 129, 0.4); border-radius: 6px; height: 42px; width: 100%; }
        .mini-scanner { position: relative; width: 22px; height: 22px; min-width: 22px; border-radius: 4px; background: rgba(15, 23, 42, 0.5); overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 12px; border: 1px solid rgba(16, 185, 129, 0.2); }
        .mini-scan-line { position: absolute; top: 0; left: 0; width: 100%; height: 2px; background: #10b981; box-shadow: 0 0 5px #10b981; animation: miniScan 2s infinite linear; }
        @keyframes miniScan { 0% { top: 0; opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } 100% { top: 100%; opacity: 0; } }
        .ai-status-text { color: #10b981; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .live-dot { width: 6px; height: 6px; background-color: #10b981; border-radius: 50%; animation: blink 1.5s infinite; }
        .btn-primary { background-color: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: 600; width: 100%; transition: 0.2s; }
        .btn-primary:hover { background-color: #2563eb; }
        .results-header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .btn-export { background-color: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2); transition: 0.2s; }
        .btn-export:hover { background-color: #059669; transform: translateY(-2px); }
        .result-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
        .card { background-color: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.05); border-radius: 10px; overflow: hidden; transition: 0.2s; }
        .card.alert-card { border: 1px solid #ef4444; }
        .card img { width: 100%; height: 160px; object-fit: cover; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        .card-body { padding: 15px; }
        .plate-badge-card { background-color: #334155; color: #f8fafc; font-size: 18px; font-weight: 600; padding: 6px 12px; border-radius: 6px; display: inline-block; margin-bottom: 12px; border: 1px solid #475569; }
        .alert-text { background: rgba(239, 68, 68, 0.1); color: #ef4444; padding: 8px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 10px; border: 1px solid rgba(239, 68, 68, 0.2); }
        .btn-case { width: 100%; background-color: transparent; border: 1px solid #3b82f6; color: #3b82f6; box-sizing: border-box; font-size: 13px; padding: 8px; display: block; text-align: center; text-decoration: none; border-radius: 6px; transition: 0.2s; margin-top: 10px; font-weight: 500;}
        .btn-case:hover { background-color: #3b82f6; color: white; }
        .btn-outline { width: 100%; background-color: transparent; border: 1px solid; box-sizing: border-box; font-size: 13px; padding: 6px; display: block; text-align: center; border-radius: 6px; transition: 0.2s; cursor: pointer; font-weight: 500;}
        .btn-outline:hover { opacity: 0.8; }
        .pagination { display: flex; justify-content: center; align-items: center; margin-top: 40px; gap: 10px; }
        .page-btn { background-color: #0f172a; color: #94a3b8; border: 1px solid #334155; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; transition: 0.2s; }
        .page-btn:hover:not(.disabled) { background-color: #3b82f6; color: white; }
        .page-btn.disabled { opacity: 0.4; cursor: not-allowed; }
        .page-info { color: #cbd5e1; font-size: 14px; background-color: #1e293b; padding: 8px 16px; border-radius: 6px; border: 1px solid #334155; }
    </style>
</head>
<body>

<div class="ambient-blob blob-1"></div>
<div class="ambient-blob blob-2"></div>

<?php include 'navbar.php'; ?>

<div class="container">
    
    <div class="suspect-table-container">
        <h3 style="font-size: 18px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">🚨 รายการเฝ้าระวังป้ายทะเบียน</h3>
        <table class="suspect-table">
            <thead>
                <tr><th>ทะเบียน</th><th>ประเภทรถ</th><th>สีรถ</th><th>สถานะ</th><th>วันที่บันทึก</th></tr>
            </thead>
            <tbody>
                <?php if(count($suspect_list) > 0): ?>
                    <?php foreach($suspect_list as $s): ?>
                    <tr>
                        <td><span class="plate-text"><?php echo htmlspecialchars($s['license_plate']); ?></span></td>
                        <td><?php echo htmlspecialchars($s['vehicle_type']); ?></td>
                        <td><?php echo htmlspecialchars($s['color']); ?></td>
                        <td>
                            <?php if ($s['suspect_type'] == 'STOLEN'): ?>
                                <span class="badge bg-danger">ถูกขโมย (STOLEN)</span>
                            <?php else: ?>
                                <span class="badge bg-warning">แจ้งเบาะแส (REPORTED)</span>
                            <?php endif; ?>
                        </td>
                        <td style="color: #64748b;"><?php echo date('d/m/Y H:i', strtotime($s['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align:center; padding: 20px; color: #64748b;">ไม่มีข้อมูลรถเป้าหมาย</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2>🔍 สืบค้นข้อมูลยานพาหนะจาก AI</h2>
    <div class="search-box">
        <form method="GET" action="search.php">
            <div class="search-grid">
                
                <div class="form-group">
                    <label>สถานะการตรวจสอบ</label>
                    <select name="verified">
                        <option value="">-- สถานะทั้งหมด --</option>
                        <option value="1" <?php if($search_verified === '1') echo 'selected'; ?>>✅ ยืนยันแล้ว</option>
                        <option value="2" <?php if($search_verified === '2') echo 'selected'; ?>>📤 ส่งตรวจแล้ว</option>
                        <option value="0" <?php if($search_verified === '0') echo 'selected'; ?>>⏳ รอตรวจสอบ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>ค้นหาจากแฟ้มคดี (ที่พบรถ)</label>
                    <select name="case_id">
                        <option value="">-- ทุกแฟ้มคดี --</option>
                        <?php foreach ($case_list_dropdown as $c): ?>
                            <?php $case_label = !empty($c['official_case_no']) ? $c['official_case_no'] . ' - ' . $c['case_name'] : $c['case_name']; ?>
                            <option value="<?php echo $c['case_file_id']; ?>" <?php echo ($search_case == $c['case_file_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($case_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group"><label>ป้ายทะเบียน</label><input type="text" name="plate" value="<?php echo htmlspecialchars($search_plate); ?>" placeholder="ค้นหา..."></div>
                
                <div class="form-group">
                    <label>ประเภทรถ</label>
                    <select name="type">
                        <option value="">-- ประเภททั้งหมด --</option>
                        <option value="รถยนต์" <?php if($search_type=='รถยนต์') echo 'selected'; ?>>รถยนต์</option>
                        <option value="รถกระบะ" <?php if($search_type=='รถกระบะ') echo 'selected'; ?>>รถกระบะ</option>
                        <option value="รถจักรยานยนต์" <?php if($search_type=='รถจักรยานยนต์') echo 'selected'; ?>>รถจักรยานยนต์</option>
                    </select>
                </div>
                <div class="form-group"><label>สีรถ</label><input type="text" name="color" value="<?php echo htmlspecialchars($search_color); ?>" placeholder="เช่น ดำ, ขาว..."></div>
                <div class="form-group"><label>วันที่บันทึก (AI)</label><input type="date" name="date" value="<?php echo htmlspecialchars($search_date); ?>"></div>
                <div class="form-group"><label>เวลา (นาที:วินาที)</label><input type="text" name="time_str" value="<?php echo htmlspecialchars($search_time_str); ?>" placeholder="เช่น 01:15"></div>

                <div class="form-group">
                    <label style="visibility: hidden;">Status</label>
                    <div class="status-content-mini">
                        <div class="mini-scanner"><div class="mini-scan-line"></div>🚘</div>
                        <div class="ai-status-text">
                            <div class="live-dot"></div> AI SYSTEM ACTIVE
                        </div>
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:12px;">
                <button type="submit" class="btn-primary" style="flex: 4;">🔍 เริ่มการประมวลผลการค้นหา</button>
                <a href="search.php" class="btn-primary" style="flex: 1; background-color: #475569; text-align: center; text-decoration: none; padding: 10px 0; box-sizing: border-box;">ล้างค่า</a>
            </div>
        </form>
    </div>

    <div class="results-header-box">
        <p class="results-count" style="color: #94a3b8; font-size: 15px; margin: 0;">พบข้อมูลทั้งหมด <span style="color:#f8fafc; font-weight:600;"><?php echo $total_records; ?></span> รายการ</p>
        <?php if ($total_records > 0): ?>
        <a href="export_search.php?<?php echo $qs_export; ?>" class="btn-export">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
        </a>
        <?php endif; ?>
    </div>

    <div class="result-grid">
        <?php foreach ($results as $row): ?>
            <div class="card <?php echo !empty($row['suspect_type']) ? 'alert-card' : ''; ?>">
                <img src="<?php echo htmlspecialchars($row['image_path']); ?>">
                <div class="card-body">
                    <?php if (!empty($row['suspect_type'])): ?>
                        <div class="alert-text" style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">
                            <span>🚨 ตรวจพบเป้าหมาย! (<?php echo $row['suspect_type'] == 'STOLEN' ? 'รถขโมย' : 'แจ้งเบาะแส'; ?>)</span>
                            <!-- ✨ โชว์ว่ารถที่เจอ หายมาจากคดีไหน ✨ -->
                            <span style="font-size: 11px; color: #f8fafc; font-weight: 500;">
                                📌 จากคดี: <?php echo !empty($row['suspect_official_no']) ? htmlspecialchars($row['suspect_official_no']) : (!empty($row['suspect_case_name']) ? htmlspecialchars($row['suspect_case_name']) : 'ไม่ระบุคดีต้นทาง'); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <div>
                        <div class="plate-badge-card"><?php echo htmlspecialchars($row['detected_license_plate']); ?></div>
                        
                        <?php if ($row['is_verified'] == 1): ?>
                            <span style="font-size: 11px; padding: 3px 8px; border-radius: 4px; background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); vertical-align: super; margin-left: 5px;">✅ ยืนยันแล้ว</span>
                        <?php elseif ($row['is_verified'] == 2): ?>
                            <span style="font-size: 11px; padding: 3px 8px; border-radius: 4px; background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); vertical-align: super; margin-left: 5px;">📤 ส่งตรวจแล้ว</span>
                        <?php else: ?>
                            <span style="font-size: 11px; padding: 3px 8px; border-radius: 4px; background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); vertical-align: super; margin-left: 5px;">⏳ รอตรวจสอบ</span>
                        <?php endif; ?>
                    </div>

                    <p style="font-size:13px; margin: 4px 0; color:#94a3b8;"><b>ประเภท:</b> <?php echo htmlspecialchars($row['detected_vehicle_type']); ?></p>
                    <p style="font-size:13px; margin: 4px 0; color:#94a3b8;"><b>สีรถ:</b> <?php echo htmlspecialchars($row['detected_color']); ?></p>
                    <p style="font-size:13px; margin: 4px 0; color:#94a3b8;"><b>พบในวิดีโอของคดี:</b> <span style="color:#cbd5e1; font-weight:500;"><?php echo !empty($row['official_case_no']) ? htmlspecialchars($row['official_case_no']) : htmlspecialchars($row['case_name']); ?></span></p>
                    
                    <?php 
                        $fps = (!empty($row['fps']) && $row['fps'] > 0) ? $row['fps'] : 30;
                        $seconds = floor(($row['frame_number'] ?? 0) / $fps); 
                        $time_display = gmdate("i:s", $seconds); 
                    ?>
                    <div style="background:#1e293b; color:#e2e8f0; padding:6px; text-align:center; border-radius:6px; font-size:12px; margin:10px 0;">⏱️ พบที่นาที: <?php echo $time_display; ?></div>
                    <a href="case_detail.php?id=<?php echo $row['case_file_id']; ?>" class="btn-case">📂 เข้าดูแฟ้มคดีนี้</a>

                    <div style="display: flex; gap: 8px; margin-top: 10px;">
                        <?php if ($_SESSION['role'] === 'USER'): ?>
                            <?php if ($row['is_verified'] == 0): ?>
                                <form method="POST" action="search.php<?php echo $current_qs; ?>" style="flex: 1;">
                                    <input type="hidden" name="detection_id" value="<?php echo $row['detection_id']; ?>">
                                    <input type="hidden" name="action_type" value="submit_check">
                                    <button type="submit" name="action_detection" class="btn-outline" style="border-color: #f59e0b; color: #f59e0b; background: rgba(245, 158, 11, 0.05);">📤 ส่งตรวจ</button>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'LEADER'): ?>
                            <?php if ($row['is_verified'] == 2 || $row['is_verified'] == 0): ?>
                                <form method="POST" action="search.php<?php echo $current_qs; ?>" style="flex: 1;">
                                    <input type="hidden" name="detection_id" value="<?php echo $row['detection_id']; ?>">
                                    <input type="hidden" name="action_type" value="verify">
                                    <button type="submit" name="action_detection" class="btn-outline" style="border-color: #10b981; color: #10b981; background: rgba(16, 185, 129, 0.05);">✅ <?php echo $row['is_verified'] == 2 ? 'อนุมัติ' : 'ยืนยัน'; ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <form method="POST" action="search.php<?php echo $current_qs; ?>" style="flex: 1;" onsubmit="return confirm('⚠️ คุณแน่ใจหรือไม่ว่าต้องการลบข้อมูลนี้ทิ้ง?');">
                            <input type="hidden" name="detection_id" value="<?php echo $row['detection_id']; ?>">
                            <input type="hidden" name="action_type" value="delete">
                            <button type="submit" name="action_detection" class="btn-outline" style="border-color: #ef4444; color: #ef4444; background: rgba(239, 68, 68, 0.05);">❌ ลบทิ้ง</button>
                        </form>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <a href="?page=<?php echo $page - 1; ?><?php echo $qs_page; ?>" class="page-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>">« ก่อนหน้า</a>
        <span class="page-info">หน้า <b><?php echo $page; ?></b> จาก <?php echo $total_pages; ?></span>
        <a href="?page=<?php echo $page + 1; ?><?php echo $qs_page; ?>" class="page-btn <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">ถัดไป »</a>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>

<script>
    <?php if(!empty($alert_script)) echo "document.addEventListener('DOMContentLoaded', function() { $alert_script });"; ?>
</script>

</body>
</html>