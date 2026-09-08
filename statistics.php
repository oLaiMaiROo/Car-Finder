<?php
session_start();
require 'db.php';

// ล็อกประตู: หน้าสถิติให้เข้าได้เฉพาะ ADMIN และ LEADER เท่านั้น
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'ADMIN' && $_SESSION['role'] !== 'LEADER')) {
    echo "<script>alert('Access Denied: คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location.href='index.php';</script>";
    exit();
}

// ---------------------------------------------------------
// 0. ดึงข้อมูลสรุปตัวเลข (Overview Cards)
// ---------------------------------------------------------
$total_detections = $conn->query("SELECT COUNT(*) FROM detections WHERE detected_vehicle_type != '-'")->fetchColumn();
$total_suspects = $conn->query("SELECT COUNT(*) FROM suspect_vehicle")->fetchColumn();
$total_cases = $conn->query("SELECT COUNT(*) FROM case_file")->fetchColumn();

// ---------------------------------------------------------
// 1. ดึงข้อมูลกราฟ: ประเภทรถ (✨ อัปเกรด: รวมข้อมูลเก่าและใหม่เข้าด้วยกัน)
// ---------------------------------------------------------
$stmt_type = $conn->query("SELECT detected_vehicle_type, COUNT(*) as count FROM detections GROUP BY detected_vehicle_type");
$type_stats = $stmt_type->fetchAll(PDO::FETCH_ASSOC);

$type_map = [];
foreach ($type_stats as $row) {
    if (empty($row['detected_vehicle_type']) || $row['detected_vehicle_type'] == '-' || $row['detected_vehicle_type'] == 'ไม่ทราบประเภท') continue;
    
    // ตัดภาษาอังกฤษในวงเล็บทิ้ง เพื่อให้ 'รถยนต์ (Car)' รวมยอดกับ 'รถยนต์' ได้
    $clean_type = trim(explode('(', $row['detected_vehicle_type'])[0]);
    
    if (!isset($type_map[$clean_type])) { $type_map[$clean_type] = 0; }
    $type_map[$clean_type] += $row['count'];
}
arsort($type_map); // เรียงจากมากไปน้อย
$type_labels = array_keys($type_map);
$type_data = array_values($type_map);

// ---------------------------------------------------------
// 2. ดึงข้อมูลกราฟ: สีรถ (✨ อัปเกรด: กวาดสีขยะทิ้ง จัดกลุ่มใหม่ให้คลีน)
// ---------------------------------------------------------
$stmt_color = $conn->query("SELECT detected_color, COUNT(*) as count FROM detections GROUP BY detected_color");
$color_stats = $stmt_color->fetchAll(PDO::FETCH_ASSOC);

$color_map = [];
foreach ($color_stats as $row) {
    $raw_color = $row['detected_color'];
    // ข้ามสีขยะจากข้อมูลเก่า
    if (empty($raw_color) || $raw_color == '-' || strpos($raw_color, 'Unknown') !== false || strpos($raw_color, 'รอ AI') !== false) continue;

    // จัดกลุ่มชื่อสี
    if (strpos($raw_color, 'ขาว') !== false || strpos($raw_color, 'White') !== false) { $c = 'ขาว (White)'; }
    elseif (strpos($raw_color, 'ดำ') !== false || strpos($raw_color, 'Black') !== false) { $c = 'ดำ (Black)'; }
    elseif (strpos($raw_color, 'เทา') !== false || strpos($raw_color, 'เงิน') !== false || strpos($raw_color, 'Grey') !== false) { $c = 'เทา/เงิน (Grey)'; }
    elseif (strpos($raw_color, 'แดง') !== false || strpos($raw_color, 'Red') !== false) { $c = 'แดง (Red)'; }
    elseif (strpos($raw_color, 'ส้ม') !== false || strpos($raw_color, 'เหลือง') !== false || strpos($raw_color, 'Yellow') !== false) { $c = 'เหลือง/ส้ม (Yellow/Orange)'; }
    elseif (strpos($raw_color, 'เขียว') !== false || strpos($raw_color, 'Green') !== false) { $c = 'เขียว (Green)'; }
    elseif (strpos($raw_color, 'น้ำเงิน') !== false || strpos($raw_color, 'ฟ้า') !== false || strpos($raw_color, 'Blue') !== false) { $c = 'น้ำเงิน (Blue)'; }
    elseif (strpos($raw_color, 'ชมพู') !== false || strpos($raw_color, 'ม่วง') !== false || strpos($raw_color, 'Pink') !== false) { $c = 'ชมพู/ม่วง (Pink)'; }
    else { $c = 'สีอื่นๆ (Other)'; }

    if (!isset($color_map[$c])) { $color_map[$c] = 0; }
    $color_map[$c] += $row['count'];
}
arsort($color_map);
$color_labels = array_keys($color_map);
$color_data = array_values($color_map);

// แปลงเป็นโค้ดสี HEX
$color_mapping_hex = [
    'ขาว (White)' => '#f8fafc',
    'ดำ (Black)' => '#0f172a',
    'เทา/เงิน (Grey)' => '#94a3b8',
    'แดง (Red)' => '#ef4444',
    'เหลือง/ส้ม (Yellow/Orange)' => '#f59e0b',
    'เขียว (Green)' => '#10b981',
    'น้ำเงิน (Blue)' => '#3b82f6',
    'ชมพู/ม่วง (Pink)' => '#d946ef',
    'สีอื่นๆ (Other)' => '#64748b'
];
$color_bg = [];
foreach ($color_labels as $lbl) {
    $color_bg[] = isset($color_mapping_hex[$lbl]) ? $color_mapping_hex[$lbl] : '#64748b';
}

// ---------------------------------------------------------
// 3. ดึงข้อมูลกราฟ: ช่วงความแม่นยำ AI
// ---------------------------------------------------------
$sql_conf = "SELECT 
    SUM(CASE WHEN confidence_score >= 90 THEN 1 ELSE 0 END) as '90-100%',
    SUM(CASE WHEN confidence_score >= 80 AND confidence_score < 90 THEN 1 ELSE 0 END) as '80-89%',
    SUM(CASE WHEN confidence_score >= 70 AND confidence_score < 80 THEN 1 ELSE 0 END) as '70-79%',
    SUM(CASE WHEN confidence_score < 70 THEN 1 ELSE 0 END) as '< 70%'
    FROM detections";
$stmt_conf = $conn->query($sql_conf);
$conf_stats = $stmt_conf->fetch(PDO::FETCH_ASSOC);
$conf_labels = ['90-100% (ดีเยี่ยม)', '80-89% (ดีมาก)', '70-79% (ปานกลาง)', 'ต่ำกว่า 70% (ต้องตรวจสอบ)'];
$conf_data = [$conf_stats['90-100%'], $conf_stats['80-89%'], $conf_stats['70-79%'], $conf_stats['< 70%']];

// ---------------------------------------------------------
// 4. ดึงข้อมูลกราฟ: สถิติการตรวจพบยานพาหนะทั้งหมด (ย้อนหลัง 7 วัน)
// ✨ อัปเกรด: เอาการ JOIN ทะเบียนรถออกก่อน เพราะระบบ OCR ยังไม่เสร็จ
// ทำให้กราฟนี้แสดงพลังของ AI ในการสแกนเจอรถทั้งหมดแทน กราฟจะได้พุ่งสวยๆ
// ---------------------------------------------------------
$confirmed_labels_map = [];
$confirmed_values_map = [];
for ($i = 6; $i >= 0; $i--) {
    $date_key = date('Y-m-d', strtotime("-$i days"));
    $date_label = date('d/m', strtotime("-$i days"));
    $confirmed_labels_map[$date_key] = $date_label;
    $confirmed_values_map[$date_key] = 0;
}

$stmt_confirmed = $conn->query("
    SELECT DATE(detected_at) as detect_date, COUNT(*) as count 
    FROM detections 
    WHERE detected_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(detected_at) 
");
$confirmed_data = $stmt_confirmed->fetchAll(PDO::FETCH_ASSOC);

foreach ($confirmed_data as $row) {
    $d_key = $row['detect_date'];
    if (isset($confirmed_values_map[$d_key])) {
        $confirmed_values_map[$d_key] = (int)$row['count'];
    }
}
$confirmed_labels = array_values($confirmed_labels_map);
$confirmed_values = array_values($confirmed_values_map);

// ---------------------------------------------------------
// 5. ดึงข้อมูลตาราง: สถิติการทำงานของผู้ใช้งาน
// ---------------------------------------------------------
$sql_user_stats = "
    SELECT 
        u.user_id, u.full_name, u.role,
        (SELECT COUNT(*) FROM case_file c WHERE c.created_by = u.user_id) as case_count,
        (SELECT COUNT(*) FROM videos v WHERE v.uploaded_by = u.user_id) as video_count
    FROM users u
    ORDER BY video_count DESC, case_count DESC
";
$stmt_user_stats = $conn->query($sql_user_stats);
$user_stats_list = $stmt_user_stats->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// 6. ดึงข้อมูลตาราง: การตรวจพบล่าสุด
// ---------------------------------------------------------
$sql_recent = "
    SELECT d.detected_license_plate, d.detected_vehicle_type, d.detected_color, d.confidence_score, d.detected_at, 
           c.case_name, i.frame_number, v.fps
    FROM detections d
    JOIN images i ON d.image_id = i.image_id
    JOIN videos v ON i.video_id = v.video_id
    JOIN case_file c ON v.case_file_id = c.case_file_id
    ORDER BY d.detected_at DESC LIMIT 15
";
$stmt_recent = $conn->query($sql_recent);
$recent_list = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>สถิติและรายงาน | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; padding: 0 0 40px 0; color: #cbd5e1; min-height: 100vh; display: flex; flex-direction: column; }
        .container { width: 100%; max-width: 1200px; margin: 40px auto; background: #1e293b; padding: 35px; border-radius: 12px; border: 1px solid #334155; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); flex: 1; box-sizing: border-box;}
        
        .header-box { border-bottom: 1px solid #334155; padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        h2 { color: #f8fafc; margin: 0; font-size: 24px; font-weight: 600; }
        
        .overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .overview-card { background: rgba(15, 23, 42, 0.6); padding: 25px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.05); text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .overview-card h3 { margin: 0 0 10px 0; font-size: 36px; color: #f8fafc; font-weight: 600; }
        .overview-card p { margin: 0; color: #94a3b8; font-size: 15px; }
        .card-blue { border-bottom: 4px solid #3b82f6; } .card-blue h3 { color: #60a5fa; }
        .card-red { border-bottom: 4px solid #ef4444; } .card-red h3 { color: #f87171; }
        .card-yellow { border-bottom: 4px solid #f59e0b; } .card-yellow h3 { color: #fbbf24; }

        .chart-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 30px; margin-bottom: 40px; }
        @media (max-width: 900px) { .chart-grid { grid-template-columns: 1fr; } }
        
        .chart-card { background-color: #0f172a; padding: 20px; border-radius: 10px; border: 1px solid #334155; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .chart-header { margin-top: 0; margin-bottom: 15px; color: #e2e8f0; font-size: 16px; font-weight: 500; display: flex; align-items: center; gap: 8px; border-bottom: 1px dashed #334155; padding-bottom: 10px; }
        .canvas-container { position: relative; height: 300px; width: 100%; }
        
        .table-container { background-color: #0f172a; border-radius: 10px; border: 1px solid #334155; overflow: hidden; margin-bottom: 40px; }
        .data-table { width: 100%; border-collapse: collapse; text-align: left; }
        .data-table th, .data-table td { padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 14px; }
        .data-table th { background-color: rgba(30, 41, 59, 0.8); color: #94a3b8; font-weight: 500; }
        .data-table tr:hover { background-color: rgba(30, 41, 59, 0.4); }
        
        .plate-badge { background-color: #334155; color: #f8fafc; font-weight: 600; padding: 4px 10px; border-radius: 4px; border: 1px solid #475569; display: inline-block; }
        .conf-badge { font-weight: 600; color: #10b981; }

        .role-badge { padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .role-admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .role-leader { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .role-user { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }

        .btn-action { color: #3b82f6; text-decoration: none; font-size: 13px; border: 1px solid #3b82f6; padding: 4px 12px; border-radius: 4px; transition: 0.2s; background: transparent; }
        .btn-action:hover { background: #3b82f6; color: white; }
        .btn-print { background-color: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-family: 'Prompt'; font-size: 14px; font-weight: 500; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px;}
        .btn-print:hover { background-color: #2563eb; }
        
        @media print {
            body { background-color: white; color: black; }
            .container { box-shadow: none; border: none; width: 100%; max-width: 100%; margin: 0; padding: 0; }
            .navbar, .footer, .btn-print { display: none !important; }
            .chart-card, .table-container, .overview-card { break-inside: avoid; border: 1px solid #ccc; background: white; }
            h2, .chart-header { color: black; border-bottom-color: #ccc; }
            .data-table th { background-color: #f1f5f9; color: black; }
            .plate-badge { background-color: #e2e8f0; color: black; border-color: #ccc; }
            .overview-card h3 { color: black !important; }
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <div class="header-box">
        <h2>📊 สถิติภาพรวมระบบ (System Analytics)</h2>
        <button class="btn-print" onclick="window.print()">🖨️ พิมพ์รายงาน</button>
    </div>

    <div class="overview-grid">
        <div class="overview-card card-blue">
            <h3><?php echo number_format($total_detections); ?></h3>
            <p>ยานพาหนะที่ AI ตรวจพบทั้งหมด (คัน)</p>
        </div>
        <div class="overview-card card-red">
            <h3><?php echo number_format($total_suspects); ?></h3>
            <p>รถเป้าหมายที่ขึ้นทะเบียนบัญชีดำ (คัน)</p>
        </div>
        <div class="overview-card card-yellow">
            <h3><?php echo number_format($total_cases); ?></h3>
            <p>แฟ้มคดีสืบสวนทั้งหมดในระบบ (คดี)</p>
        </div>
    </div>

    <div class="chart-grid">
        <div class="chart-card">
            <h3 class="chart-header">🚘 สัดส่วนประเภทรถที่ตรวจพบ</h3>
            <div class="canvas-container"><canvas id="typeChart"></canvas></div>
        </div>
        <div class="chart-card">
            <h3 class="chart-header">🎨 สัดส่วนสีรถที่ตรวจพบ</h3>
            <div class="canvas-container"><canvas id="colorChart"></canvas></div>
        </div>
        
        <div class="chart-card">
            <!-- ✨ เปลี่ยนหัวข้อเป็นกราฟแสดงแนวโน้มยานพาหนะที่พบทั้งหมด -->
            <h3 class="chart-header" style="color: #60a5fa; border-bottom-color: rgba(59, 130, 246, 0.2);">
                📈 แนวโน้มการตรวจพบยานพาหนะโดย AI (7 วันล่าสุด)
            </h3>
            <div class="canvas-container"><canvas id="confirmedChart"></canvas></div>
        </div>
        
        <div class="chart-card">
            <h3 class="chart-header">🎯 ระดับความแม่นยำของ AI (Confidence Score)</h3>
            <div class="canvas-container"><canvas id="confChart"></canvas></div>
        </div>
    </div>

    <div class="table-container">
        <h3 class="chart-header" style="padding: 20px 20px 10px 20px; border-bottom: none; margin: 0;">👥 สถิติการทำงานของเจ้าหน้าที่ (User Activity)</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ชื่อผู้ใช้งาน</th>
                    <th>สิทธิ์การใช้งาน</th>
                    <th style="text-align: center;">สร้างแฟ้มคดี (คดี)</th>
                    <th style="text-align: center;">อัปโหลดวิดีโอ (ไฟล์)</th>
                    <th style="text-align: center;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($user_stats_list as $usr): ?>
                    <tr>
                        <td style="color: #f8fafc; font-weight: 500;"><?php echo htmlspecialchars($usr['full_name']); ?></td>
                        <td>
                            <?php if ($usr['role'] == 'ADMIN'): ?>
                                <span class="role-badge role-admin">ADMIN</span>
                            <?php elseif ($usr['role'] == 'LEADER'): ?>
                                <span class="role-badge role-leader">LEADER</span>
                            <?php else: ?>
                                <span class="role-badge role-user">USER</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; color: #94a3b8; font-size: 16px; font-weight: 600;"><?php echo $usr['case_count']; ?></td>
                        <td style="text-align: center; color: #10b981; font-size: 16px; font-weight: 600;"><?php echo $usr['video_count']; ?></td>
                        <td style="text-align: center;">
                            <a href="view_logs.php?user_id=<?php echo $usr['user_id']; ?>" class="btn-action">🔍 ดูประวัติ</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-container" style="margin-bottom: 0;">
        <h3 class="chart-header" style="padding: 20px 20px 10px 20px; border-bottom: none; margin: 0;">📋 ข้อมูลยานพาหนะที่ตรวจพบล่าสุด</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ป้ายทะเบียน</th>
                    <th>ประเภทรถ</th>
                    <th>สีรถ</th>
                    <th>ความแม่นยำ AI</th>
                    <th>คดีที่เกี่ยวข้อง</th>
                    <th>เวลาในวิดีโอ</th>
                    <th>วันที่ตรวจพบ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($recent_list) > 0): ?>
                    <?php foreach ($recent_list as $row): ?>
                        <tr>
                            <td><span class="plate-badge"><?php echo htmlspecialchars($row['detected_license_plate']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['detected_vehicle_type']); ?></td>
                            <td><?php echo htmlspecialchars($row['detected_color']); ?></td>
                            <td><span class="conf-badge"><?php echo number_format($row['confidence_score'], 1); ?>%</span></td>
                            <td style="color: #cbd5e1;"><?php echo htmlspecialchars($row['case_name']); ?></td>
                            <?php 
                                $fps = (!empty($row['fps']) && $row['fps'] > 0) ? $row['fps'] : 30;
                                $seconds = floor($row['frame_number'] / $fps);
                                $time_display = gmdate("i:s", $seconds);
                            ?>
                            <td style="color: #94a3b8;"><?php echo $time_display; ?></td>
                            <td style="color: #94a3b8; font-size: 13px;"><?php echo date('d/m/Y H:i', strtotime($row['detected_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center; color: #64748b; padding: 30px;">ยังไม่มีข้อมูลการตรวจจับในระบบ</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php include 'footer.php'; ?>

<script>
    Chart.defaults.color = '#cbd5e1';
    Chart.defaults.font.family = "'Prompt', sans-serif";

    // 1. Bar Chart (ประเภทรถ)
    new Chart(document.getElementById('typeChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($type_labels); ?>,
            datasets: [{ label: ' จำนวนรถ (คัน)', data: <?php echo json_encode($type_data); ?>, backgroundColor: 'rgba(59, 130, 246, 0.8)', borderColor: '#3b82f6', borderWidth: 1, borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.1)' } }, x: { grid: { display: false } } } }
    });

    // 2. Doughnut Chart (สีของรถ)
    new Chart(document.getElementById('colorChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($color_labels); ?>,
            datasets: [{ data: <?php echo json_encode($color_data); ?>, backgroundColor: <?php echo json_encode($color_bg); ?>, borderColor: '#0f172a', borderWidth: 2, hoverOffset: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12 } } }, cutout: '65%' }
    });

    // 3. Area Chart (✨ แก้ไขกราฟเป็นสีฟ้า และแสดงผลรวมยานพาหนะทั้งหมดแทน เพื่อให้กราฟพุ่งสวยงาม)
    new Chart(document.getElementById('confirmedChart'), {
        type: 'line', 
        data: {
            labels: <?php echo json_encode($confirmed_labels); ?>,
            datasets: [{ 
                label: ' ยานพาหนะ (คัน)', 
                data: <?php echo json_encode($confirmed_values); ?>, 
                backgroundColor: 'rgba(59, 130, 246, 0.15)', // สีฟ้าแบบโปร่งแสง
                borderColor: '#3b82f6', 
                borderWidth: 2,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#3b82f6',
                fill: true,
                tension: 0.4 
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }, 
            scales: { 
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(255, 255, 255, 0.05)' } }, 
                x: { grid: { display: false } } 
            },
            interaction: { mode: 'nearest', axis: 'x', intersect: false }
        }
    });

    // 4. Pie Chart (ความแม่นยำ AI)
    new Chart(document.getElementById('confChart'), {
        type: 'pie',
        data: {
            labels: <?php echo json_encode($conf_labels); ?>,
            datasets: [{ data: <?php echo json_encode($conf_data); ?>, backgroundColor: [ '#10b981', '#3b82f6', '#f59e0b', '#ef4444' ], borderColor: '#0f172a', borderWidth: 2 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
    });
</script>

</body>
</html>