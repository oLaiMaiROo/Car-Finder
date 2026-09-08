<?php
session_start();
require 'db.php'; 

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // 1. บันทึกประวัติ Log ขาออก
    try {
        $log_stmt = $conn->prepare("INSERT INTO user_log (user_id, action_type) VALUES (?, 'ออกจากระบบ (Logout)')");
        $log_stmt->execute([$user_id]);
    } catch (PDOException $e) {}

    // 2. ✨ บันทึกเวลาปัจจุบันไว้ และปิดสวิตช์เป็นออฟไลน์ทันที ✨
    try {
        $stmt = $conn->prepare("UPDATE users SET last_seen = NOW(), is_online = 0 WHERE user_id = ?");
        $stmt->execute([$user_id]);
    } catch (PDOException $e) {}
}

// 3. ล้างค่า Session ทั้งหมดทิ้ง
session_unset();
session_destroy();

// 4. เด้งกลับไปหน้า Login
header("Location: login.php");
exit();
?>