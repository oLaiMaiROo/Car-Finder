<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// ป้องกันคนนอกเข้าถึง
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

try {
    // 1. ถ้าเพิ่งล็อกอินเข้ามา ให้หา ID ล่าสุดเก็บไว้ก่อน จะได้ไม่แจ้งเตือนข้อมูลเก่าๆ ซ้ำ
    if (!isset($_SESSION['last_detection_id'])) {
        $stmt = $conn->query("SELECT MAX(detection_id) FROM detections");
        $max_id = $stmt->fetchColumn();
        $_SESSION['last_detection_id'] = $max_id ? $max_id : 0;
        echo json_encode(['status' => 'success', 'has_alert' => false]);
        exit();
    }

    $last_id = $_SESSION['last_detection_id'];

    // 2. ค้นหาข้อมูลใหม่ (ที่ ID มากกว่าของเดิม) และทะเบียนตรงกับตาราง suspect_vehicle
    $sql = "SELECT d.detection_id, d.detected_license_plate, d.detected_vehicle_type, d.detected_color, sv.suspect_type, c.case_name 
            FROM detections d
            JOIN suspect_vehicle sv ON REPLACE(d.detected_license_plate, ' ', '') = REPLACE(sv.license_plate, ' ', '')
            JOIN images i ON d.image_id = i.image_id
            JOIN videos v ON i.video_id = v.video_id
            JOIN case_file c ON v.case_file_id = c.case_file_id
            WHERE d.detection_id > ?";
    
    $params = [$last_id];

    // ดักจับสิทธิ์: USER จะได้รับการแจ้งเตือนเฉพาะคดีที่ตัวเองดูแล
    if ($_SESSION['role'] === 'USER') {
        $sql .= " AND c.created_by = ?";
        $params[] = $_SESSION['user_id'];
    }

    $sql .= " ORDER BY d.detection_id ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. อัปเดต ID ล่าสุดเสมอ เพื่อให้รอบต่อไปเช็กเฉพาะข้อมูลใหม่จริงๆ
    $stmt_max = $conn->query("SELECT MAX(detection_id) FROM detections");
    $current_max_id = $stmt_max->fetchColumn();
    if ($current_max_id > $last_id) {
        $_SESSION['last_detection_id'] = $current_max_id;
    }

    // 4. ส่งผลลัพธ์กลับไปให้ JavaScript หน้าเว็บ
    if (count($alerts) > 0) {
        echo json_encode(['status' => 'success', 'has_alert' => true, 'alerts' => $alerts]);
    } else {
        echo json_encode(['status' => 'success', 'has_alert' => false]);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>