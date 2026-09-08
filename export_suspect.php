<?php
session_start();
require 'db.php';

// ปลดล็อกให้ทุกคนที่ล็อกอินสามารถโหลดได้ (ลบการเช็ก $_SESSION['role'] !== 'ADMIN' ออก)
if (!isset($_SESSION['user_id'])) {
    exit('Access Denied: กรุณาเข้าสู่ระบบ');
}

// 1. ตั้งค่า Header ให้เบราว์เซอร์รู้ว่านี่คือการดาวน์โหลดไฟล์ CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=Suspect_Vehicles_' . date('Ymd_Hi') . '.csv');

// 2. ป้องกันภาษาไทยเพี้ยน (พิมพ์ BOM สำหรับ UTF-8 ให้ Excel อ่านออก)
echo "\xEF\xBB\xBF";

// 3. เปิดไฟล์จำลองในหน่วยความจำ
$output = fopen('php://output', 'w');

// 4. สร้างหัวตาราง (Header Row)
fputcsv($output, array('รหัสอ้างอิง', 'ป้ายทะเบียน', 'ประเภทรถ', 'สีรถ', 'สถานะเบาะแส', 'วันที่บันทึกระบบ'));

// 5. ดึงข้อมูลจากฐานข้อมูล
$stmt = $conn->query("SELECT suspect_id, license_plate, vehicle_type, color, suspect_type, created_at FROM suspect_vehicle ORDER BY created_at DESC");
$suspects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. วนลูปนำข้อมูลใส่ลงไปทีละบรรทัด
foreach ($suspects as $row) {
    // แปลงสถานะให้เป็นภาษาไทยที่อ่านง่าย
    $status = ($row['suspect_type'] == 'STOLEN') ? 'ถูกขโมย (STOLEN)' : 'แจ้งเบาะแส (REPORTED)';
    
    // จัดรูปแบบแถวเพื่อเขียนลงไฟล์
    fputcsv($output, array(
        '#' . $row['suspect_id'],
        $row['license_plate'],
        $row['vehicle_type'],
        $row['color'] ? $row['color'] : '-',
        $status,
        date('d/m/Y H:i', strtotime($row['created_at']))
    ));
}

// บันทึก Log คนโหลด Excel
if (function_exists('saveLog')) {
    saveLog($conn, $_SESSION['user_id'], 'ดาวน์โหลด Excel ฐานข้อมูลรถเป้าหมาย');
}

fclose($output);
exit();
?>