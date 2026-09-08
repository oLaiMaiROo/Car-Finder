<?php
session_start();
require 'db.php';

// เฉพาะ ADMIN เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    exit('Access Denied: คุณไม่มีสิทธิ์ดาวน์โหลดข้อมูล');
}

// ตั้งค่า Header สำหรับโหลดไฟล์ CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=System_Audit_Logs_' . date('Ymd_Hi') . '.csv');

// ป้องกันภาษาไทยเพี้ยนใน Excel (BOM)
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// หัวตาราง
fputcsv($output, array('ลำดับ', 'วัน-เวลาที่บันทึก', 'เจ้าหน้าที่', 'ระดับสิทธิ์', 'รายละเอียดการทำงาน'));

// ดึงข้อมูลจากฐานข้อมูลทั้งหมด
$stmt = $conn->query("
    SELECT l.action_timestamp, u.full_name, u.role, l.action_type 
    FROM user_log l 
    LEFT JOIN users u ON l.user_id = u.user_id 
    ORDER BY l.action_timestamp DESC
");

$i = 1;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, array(
        $i++,
        date('d/m/Y H:i:s', strtotime($row['action_timestamp'])),
        $row['full_name'],
        $row['role'],
        $row['action_type']
    ));
}

fclose($output);
exit();
?>