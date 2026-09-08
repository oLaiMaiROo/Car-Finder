<?php
session_start();
require 'db.php';

// เช็คว่ามีการล็อกอินหรือยัง ถ้ายังให้เด้งกลับไปหน้า login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'];
$current_user_id = $_SESSION['user_id'];

// --- ดึงข้อมูลสถิติจากฐานข้อมูล ---
if ($role === 'USER') {
    $stmt_cases = $conn->prepare("SELECT COUNT(*) FROM case_file WHERE assigned_to = ?");
    $stmt_cases->execute([$current_user_id]);
    
    $stmt_videos = $conn->prepare("SELECT COUNT(*) FROM videos v JOIN case_file c ON v.case_file_id = c.case_file_id WHERE c.assigned_to = ?");
    $stmt_videos->execute([$current_user_id]);
    
    $stmt_detections = $conn->prepare("SELECT COUNT(*) FROM detections d JOIN images i ON d.image_id = i.image_id JOIN videos v ON i.video_id = v.video_id JOIN case_file c ON v.case_file_id = c.case_file_id WHERE c.assigned_to = ?");
    $stmt_detections->execute([$current_user_id]);
} else {
    // ฝั่ง ADMIN และ LEADER เห็นรวมทั้งหมด
    $stmt_cases = $conn->query("SELECT COUNT(*) FROM case_file");
    $stmt_videos = $conn->query("SELECT COUNT(*) FROM videos");
    $stmt_detections = $conn->query("SELECT COUNT(*) FROM detections");
}

$total_cases = $stmt_cases->fetchColumn();
$total_videos = $stmt_videos->fetchColumn();
$total_detections = $stmt_detections->fetchColumn();

// รถต้องสงสัย (ดูได้ทุกคน)
$stmt_suspects = $conn->query("SELECT COUNT(*) FROM suspect_vehicle");
$total_suspects = $stmt_suspects->fetchColumn();

$total_users = 0;
if ($role === 'ADMIN') {
    $stmt_users = $conn->query("SELECT COUNT(*) FROM users");
    $total_users = $stmt_users->fetchColumn();
}

// ✨ ดึงข้อมูล 5 รายการล่าสุดที่ AI ตรวจพบ ✨
$recent_sql = "
    SELECT d.detected_license_plate, d.detected_vehicle_type, d.detected_color, d.confidence_score, d.detected_at, 
           i.image_path, c.case_name, c.official_case_no, c.case_file_id 
    FROM detections d
    JOIN images i ON d.image_id = i.image_id
    JOIN videos v ON i.video_id = v.video_id
    JOIN case_file c ON v.case_file_id = c.case_file_id
";

if ($role === 'USER') {
    $recent_sql .= " WHERE c.assigned_to = :user_id";
}

$recent_sql .= " ORDER BY d.detected_at DESC LIMIT 5";
$stmt_recent = $conn->prepare($recent_sql);
if ($role === 'USER') {
    $stmt_recent->bindValue(':user_id', $current_user_id, PDO::PARAM_INT);
}
$stmt_recent->execute();
$recent_detections = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Car Finder | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Prompt', sans-serif; 
            background-color: #0f172a; 
            margin: 0; 
            padding: 0 0 40px 0; 
            color: #cbd5e1; 
            position: relative;
            overflow-x: hidden;
        }

        .ambient-blob {
            position: fixed; 
            border-radius: 50%;
            filter: blur(100px);
            z-index: -1; 
            opacity: 0.35;
            animation: drift alternate infinite ease-in-out;
        }

        .blob-1 { width: 550px; height: 550px; background-color: #3b82f6; top: -10%; left: -5%; animation-duration: 20s; }
        .blob-2 { width: 450px; height: 450px; background-color: #8b5cf6; bottom: -10%; right: -5%; animation-duration: 24s; animation-direction: alternate-reverse; }

        @keyframes drift {
            0% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(40px, 30px) scale(1.1); }
            100% { transform: translate(-30px, 40px) scale(0.9); }
        }

        .container { 
            width: 90%; max-width: 1150px; margin: 40px auto 40px; flex: 1; 
            background-color: rgba(30, 41, 59, 0.75); backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px); padding: 40px; border-radius: 12px; 
            border: 1px solid rgba(255, 255, 255, 0.05); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); 
            position: relative; z-index: 10;
        }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        h2 { margin: 0; color: #f8fafc; font-weight: 600; font-size: 24px; }
        
        .role-badge { 
            background-color: #3b82f6; color: #ffffff; padding: 4px 12px; 
            border-radius: 20px; font-size: 12px; font-weight: 600; letter-spacing: 0.5px;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);
        }
        
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px; margin-top: 30px; margin-bottom: 40px; }
        .stat-card { 
            background-color: rgba(15, 23, 42, 0.6); padding: 24px; border-radius: 10px; 
            border: 1px solid rgba(255, 255, 255, 0.05); text-align: left; transition: transform 0.2s ease, box-shadow 0.2s ease; 
            text-decoration: none; display: block; color: inherit; cursor: pointer; 
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); background-color: rgba(30, 41, 59, 0.8); }
        .stat-card h3 { margin: 0; font-size: 32px; font-weight: 700; }
        .stat-card p { margin: 5px 0 0; font-size: 14px; color: #94a3b8; font-weight: 500; }
        
        .card-primary { border-left: 4px solid #3b82f6; } .card-primary h3 { color: #60a5fa; }
        .card-info { border-left: 4px solid #06b6d4; } .card-info h3 { color: #22d3ee; }
        .card-success { border-left: 4px solid #10b981; } .card-success h3 { color: #34d399; }
        .card-danger { border-left: 4px solid #f43f5e; } .card-danger h3 { color: #fb7185; }
        .card-warning { border-left: 4px solid #f59e0b; } .card-warning h3 { color: #fbbf24; }
        
        .recent-box {
            background-color: rgba(15, 23, 42, 0.5); border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 40px; overflow: hidden;
        }
        .recent-header {
            background-color: rgba(30, 41, 59, 0.8); padding: 15px 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            display: flex; justify-content: space-between; align-items: center;
        }
        .recent-header h3 { margin: 0; color: #f8fafc; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .recent-header .live-dot { width: 8px; height: 8px; background-color: #10b981; border-radius: 50%; box-shadow: 0 0 8px #10b981; animation: blink 1.5s infinite ease-in-out; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        
        .recent-table { width: 100%; border-collapse: collapse; }
        .recent-table th, .recent-table td { padding: 12px 20px; text-align: left; border-bottom: 1px solid rgba(255, 255, 255, 0.05); font-size: 14px; vertical-align: middle; }
        .recent-table th { color: #94a3b8; font-weight: 500; font-size: 13px; }
        .recent-table tr:hover { background-color: rgba(30, 41, 59, 0.5); }
        .recent-table tr:last-child td { border-bottom: none; }
        
        .recent-img { width: 60px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #334155; }
        .plate-badge { background-color: #334155; color: #f8fafc; padding: 4px 8px; border-radius: 4px; font-weight: 600; border: 1px solid #475569; font-size: 13px;}
        .view-link { color: #3b82f6; text-decoration: none; font-weight: 500; font-size: 13px; transition: color 0.2s; }
        .view-link:hover { color: #60a5fa; text-decoration: underline; }

        .section-title { color: #f8fafc; font-size: 16px; font-weight: 600; margin-bottom: 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 10px; margin-top: 20px;}
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; }
        .menu-item { text-decoration: none; display: flex; align-items: center; padding: 16px 20px; background-color: rgba(15, 23, 42, 0.5); border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.05); transition: all 0.2s ease; }
        .menu-item:hover { background-color: rgba(51, 65, 85, 0.8); border-color: rgba(255, 255, 255, 0.1); transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .menu-icon { font-size: 20px; margin-right: 15px; width: 30px; text-align: center; }
        .menu-text { color: #e2e8f0; font-size: 15px; font-weight: 500; }
        .menu-desc { display: block; color: #94a3b8; font-size: 12px; margin-top: 4px; }
    </style>
</head>
<body>

<div class="ambient-blob blob-1"></div>
<div class="ambient-blob blob-2"></div>

<?php include 'navbar.php'; ?>

<div class="container">
    <div class="header">
        <div>
            <h2>ภาพรวมระบบ (System Overview)</h2>
            <p style="margin: 5px 0 0; color: #94a3b8; font-size: 14px;">ยินดีต้อนรับ, <?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
        </div>
        <div>
            <span class="role-badge"><?php echo htmlspecialchars($_SESSION['role']); ?></span>
        </div>
    </div>

    <div class="stat-grid">
        <a href="cases.php" class="stat-card card-primary">
            <h3><?php echo $total_cases; ?></h3>
            <p><?php echo ($role === 'USER') ? 'แฟ้มคดีของคุณ' : 'แฟ้มคดีทั้งหมด'; ?></p>
        </a>
        <a href="cases.php" class="stat-card card-info">
            <h3><?php echo $total_videos; ?></h3>
            <p><?php echo ($role === 'USER') ? 'วิดีโอในคดีของคุณ' : 'วิดีโอหลักฐาน'; ?></p>
        </a>
        <a href="search.php" class="stat-card card-success">
            <h3><?php echo $total_detections; ?></h3>
            <p><?php echo ($role === 'USER') ? 'AI ตรวจพบจากคดีคุณ' : 'AI ตรวจพบ (คัน)'; ?></p>
        </a>
        <?php $suspect_link = ($_SESSION['role'] === 'ADMIN') ? 'manage_suspect.php' : 'search.php'; ?>
        <a href="<?php echo $suspect_link; ?>" class="stat-card card-danger">
            <h3><?php echo $total_suspects; ?></h3>
            <p>รถต้องสงสัย (คัน)</p>
        </a>
        <?php if ($_SESSION['role'] === 'ADMIN'): ?>
        <a href="user_list.php" class="stat-card card-warning">
            <h3><?php echo $total_users; ?></h3>
            <p>ผู้ใช้งานในระบบ</p>
        </a>
        <?php endif; ?>
    </div>
    
    <div class="recent-box">
        <div class="recent-header">
            <h3><div class="live-dot"></div> ข้อมูลยานพาหนะที่ตรวจพบล่าสุด (Live Feed)</h3>
            <a href="search.php" class="view-link">ดูทั้งหมด »</a>
        </div>
        <table class="recent-table">
            <thead>
                <tr>
                    <th width="80">ภาพถ่าย</th>
                    <th>ป้ายทะเบียน</th>
                    <th>ประเภท / สีรถ</th>
                    <th>ความแม่นยำ AI</th>
                    <th>อ้างอิงเลขคดี</th>
                    <th>เวลาที่ตรวจพบ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($recent_detections) > 0): ?>
                    <?php foreach ($recent_detections as $ai): ?>
                        <tr>
                            <td><img src="<?php echo htmlspecialchars($ai['image_path']); ?>" class="recent-img" alt="Car"></td>
                            <td><span class="plate-badge"><?php echo htmlspecialchars($ai['detected_license_plate']); ?></span></td>
                            <td><span style="color:#e2e8f0;"><?php echo htmlspecialchars($ai['detected_vehicle_type']); ?></span><br><span style="color:#94a3b8; font-size:12px;">สี: <?php echo htmlspecialchars($ai['detected_color']); ?></span></td>
                            <td><span style="color:#10b981; font-weight:600;"><?php echo number_format($ai['confidence_score'], 1); ?>%</span></td>
                            <td style="color:#cbd5e1;">
                                <?php echo !empty($ai['official_case_no']) ? htmlspecialchars($ai['official_case_no']) : htmlspecialchars($ai['case_name']); ?>
                            </td>
                            <td style="color:#94a3b8; font-size: 13px;"><?php echo date('d/m/Y H:i', strtotime($ai['detected_at'])); ?></td>
                            <td><a href="case_detail.php?id=<?php echo $ai['case_file_id']; ?>" class="view-link">เปิดแฟ้ม 📂</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #64748b; padding: 30px;">ยังไม่มีข้อมูลการตรวจจับในระบบ</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div class="section-title">เมนูการจัดการระบบ (System Modules)</div>
    <div class="menu-grid">
        <a href="cases.php" class="menu-item">
            <span class="menu-icon">📁</span>
            <div><span class="menu-text">จัดการแฟ้มคดี</span><span class="menu-desc">สร้างและตรวจสอบข้อมูลวิดีโอในแต่ละคดี</span></div>
        </a>
        <a href="search.php" class="menu-item">
            <span class="menu-icon">🔍</span>
            <div><span class="menu-text">ค้นหารถย้อนหลัง</span><span class="menu-desc">สืบค้นข้อมูลจากฐานข้อมูล AI ทั้งระบบ</span></div>
        </a>
        
        <a href="manage_suspect.php" class="menu-item">
            <span class="menu-icon">🚨</span>
            <div><span class="menu-text">ข้อมูลรถต้องสงสัย</span><span class="menu-desc">ตรวจสอบรถที่ถูกขโมยหรือแจ้งเบาะแส</span></div>
        </a>

        <?php if ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'LEADER'): ?>
            <a href="assign_case.php" class="menu-item">
                <span class="menu-icon">📋</span>
                <div><span class="menu-text">มอบหมายงานคดี</span><span class="menu-desc">ระบุเจ้าหน้าที่ผู้รับผิดชอบในแต่ละแฟ้มคดี</span></div>
            </a>

            <a href="statistics.php" class="menu-item">
                <span class="menu-icon">📊</span>
                <div><span class="menu-text">สถิติระบบ</span><span class="menu-desc">ดูข้อมูลภาพรวมและการทำงานของระบบ</span></div>
            </a>
            
            <a href="report_history.php" class="menu-item">
                <span class="menu-icon">📄</span>
                <div><span class="menu-text">ประวัติการออกรายงาน</span><span class="menu-desc">ตรวจสอบข้อมูลการออกรายงาน PDF ของคดีต่างๆ</span></div>
            </a>
        <?php endif; ?>

        <?php if ($_SESSION['role'] === 'ADMIN'): ?>
            <a href="user_list.php" class="menu-item">
                <span class="menu-icon">👥</span>
                <div><span class="menu-text">รายชื่อผู้ใช้งาน</span><span class="menu-desc">ตรวจสอบรายชื่อเจ้าหน้าที่ทั้งหมดในระบบ</span></div>
            </a>
            <a href="add_user.php" class="menu-item">
                <span class="menu-icon">➕</span>
                <div><span class="menu-text">เพิ่มผู้ใช้งานใหม่</span><span class="menu-desc">ลงทะเบียนบัญชีใหม่ให้กับเจ้าหน้าที่</span></div>
            </a>
            <a href="view_logs.php" class="menu-item">
                <span class="menu-icon">📝</span>
                <div><span class="menu-text">ประวัติการใช้งาน</span><span class="menu-desc">ตรวจสอบ Log การเข้าสู่ระบบและการทำงาน</span></div>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    function checkRealtimeAlerts() {
        fetch('check_alerts.php')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success' && data.has_alert) {
                    data.alerts.forEach(alert => {
                        let statusText = alert.suspect_type === 'STOLEN' ? 'รถถูกขโมย (STOLEN)' : 'แจ้งเบาะแส (REPORTED)';
                        let iconColor = alert.suspect_type === 'STOLEN' ? '#ef4444' : '#f59e0b';
                        
                        Swal.fire({
                            title: '🚨 ตรวจพบรถเป้าหมาย!',
                            html: `
                                <div style="text-align: left; background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 10px; border-left: 4px solid ${iconColor};">
                                    <p style="margin: 5px 0; color: #333;"><b>ป้ายทะเบียน:</b> <span style="color: #0f172a; font-size: 16px; font-weight: 600;">${alert.detected_license_plate}</span></p>
                                    <p style="margin: 5px 0; color: #333;"><b>ประเภท:</b> ${alert.detected_vehicle_type}</p>
                                    <p style="margin: 5px 0; color: #333;"><b>สีรถ:</b> ${alert.detected_color}</p>
                                    <p style="margin: 5px 0; color: #333;"><b>แฟ้มคดี:</b> ${alert.case_name}</p>
                                    <p style="margin: 5px 0; color: #333;"><b>สถานะ:</b> <span style="color: ${iconColor}; font-weight: 600;">${statusText}</span></p>
                                </div>
                            `,
                            icon: 'warning',
                            confirmButtonText: 'รับทราบ',
                            confirmButtonColor: '#3b82f6',
                            allowOutsideClick: false 
                        });
                    });
                }
            })
            .catch(error => console.error('Error fetching alerts:', error));
    }

    setInterval(checkRealtimeAlerts, 3000);
</script>
</body>
</html>