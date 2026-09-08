<?php
session_start();
require 'db.php';

// ล็อกประตู: ต้องล็อกอินก่อนถึงจะโหลดได้
if (!isset($_SESSION['user_id'])) {
    exit('Access Denied: กรุณาเข้าสู่ระบบ');
}

// 1. รับค่าเงื่อนไขการค้นหา
$search_plate = $_GET['plate'] ?? '';
$search_type  = $_GET['type'] ?? '';
$search_color = $_GET['color'] ?? '';
$search_date  = $_GET['date'] ?? '';
$search_time_str = $_GET['time_str'] ?? ''; 

// แปลงเวลา (นาที:วินาที) เป็นวินาทีล้วนๆ
$search_second = '';
if ($search_time_str !== '') {
    if (strpos($search_time_str, ':') !== false) {
        $parts = explode(':', $search_time_str);
        $search_second = ((int)$parts[0] * 60) + (int)$parts[1];
    } else {
        $search_second = (int)$search_time_str; 
    }
}

// 2. สร้าง Query สำหรับดึงข้อมูล (✨ เพิ่ม c.official_case_no)
$base_sql = "SELECT d.detected_license_plate, d.detected_vehicle_type, d.detected_color, d.confidence_score, 
                    d.detected_at, c.case_name, c.official_case_no, v.video_id, i.frame_number, v.fps, sv.suspect_type, d.is_verified 
             FROM detections d
             JOIN images i ON d.image_id = i.image_id
             JOIN videos v ON i.video_id = v.video_id
             JOIN case_file c ON v.case_file_id = c.case_file_id
             LEFT JOIN suspect_vehicle sv ON REPLACE(d.detected_license_plate, ' ', '') = REPLACE(sv.license_plate, ' ', '')
             WHERE 1=1"; 
$params = [];

// ดักจับสิทธิ์: ถ้าเป็น USER บังคับให้โหลดเฉพาะคดีของตัวเอง
if ($_SESSION['role'] === 'USER') {
    $base_sql .= " AND c.assigned_to = ?";
    $params[] = $_SESSION['user_id'];
}

if (!empty($search_plate)) { $base_sql .= " AND d.detected_license_plate LIKE ?"; $params[] = "%$search_plate%"; }
if (!empty($search_type)) { $base_sql .= " AND d.detected_vehicle_type LIKE ?"; $params[] = "%$search_type%"; }
if (!empty($search_color)) { $base_sql .= " AND d.detected_color LIKE ?"; $params[] = "%$search_color%"; }
if (!empty($search_date)) { $base_sql .= " AND DATE(d.detected_at) = ?"; $params[] = $search_date; }

if ($search_second !== '') { 
    $base_sql .= " AND FLOOR(i.frame_number / COALESCE(NULLIF(v.fps, 0), 30)) = ?"; 
    $params[] = $search_second; 
}

$base_sql .= " ORDER BY d.detected_at DESC";

$stmt = $conn->prepare($base_sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. ตั้งค่าให้โหลดเป็นไฟล์ CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=AI_Search_Results_' . date('Ymd_Hi') . '.csv');

// พิมพ์ BOM ป้องกัน Excel อ่านภาษาไทยเพี้ยน
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// ✨ สร้างหัวตาราง (เพิ่มคอลัมน์ เลขคดีอ้างอิง)
fputcsv($output, array('ลำดับ', 'ป้ายทะเบียน', 'ประเภทรถ', 'สีรถ', 'ความแม่นยำ AI', 'สถานะตรวจสอบ', 'สถานะรถเป้าหมาย', 'อ้างอิงเลขคดี', 'วิดีโอ', 'เวลาในวิดีโอ', 'วันที่ตรวจพบ'));

// 4. วนลูปยัดข้อมูลลง Excel
$i = 1;
foreach ($results as $row) {
    $fps = (!empty($row['fps']) && $row['fps'] > 0) ? $row['fps'] : 30;
    $frame_num = !empty($row['frame_number']) ? $row['frame_number'] : 0;
    $seconds = floor($frame_num / $fps);
    $time_display = gmdate("i:s", $seconds);

    $suspect_status = '-';
    if (!empty($row['suspect_type'])) {
        $suspect_status = ($row['suspect_type'] == 'STOLEN') ? '🚨 ถูกขโมย' : '⚠️ แจ้งเบาะแส';
    }

    $verified_status = ($row['is_verified'] == 1) ? '✅ ยืนยันแล้ว' : '⏳ รอตรวจสอบ';
    
    // เลือกว่าจะโชว์เลขคดี หรือ ชื่อคดี
    $case_ref = !empty($row['official_case_no']) ? $row['official_case_no'] : $row['case_name'];

    fputcsv($output, array(
        $i++,
        $row['detected_license_plate'],
        $row['detected_vehicle_type'],
        $row['detected_color'],
        number_format($row['confidence_score'], 2) . '%',
        $verified_status,
        $suspect_status,
        $case_ref,
        'V-' . $row['video_id'],
        $time_display,
        date('d/m/Y H:i:s', strtotime($row['detected_at']))
    ));
}

fclose($output);
// บันทึก Log 
if (function_exists('saveLog')) {
    saveLog($conn, $_SESSION['user_id'], 'ดาวน์โหลด Excel ข้อมูลค้นหา (จำนวน ' . count($results) . ' รายการ)');
}
exit();
?>