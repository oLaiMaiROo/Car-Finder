<?php
// 1. บรรทัดนี้จะสั่งให้ PHP แสดง Error ทุกอย่างออกมาทางหน้าจอ (ห้ามลบจนกว่าจะแก้เสร็จ)
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

$host = "localhost";
$dbname = "my_project_db";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ถ้าสำเร็จ ต้องขึ้นข้อความสีเขียวนี้
   // echo "<h1 style='color: green; text-align: center; margin-top: 50px;'>✅ เชื่อมต่อฐานข้อมูลสำเร็จ!</h1>";
   // echo "<p style='text-align: center;'>ระบบพร้อมใช้งานแล้วครับ</p>";

} catch(PDOException $e) {
    // ถ้าพัง ต้องขึ้นข้อความสีแดงนี้
   // echo "<h1 style='color: red; text-align: center; margin-top: 50px;'>❌ เชื่อมต่อไม่ได้</h1>";
    //echo "<h3 style='text-align: center;'>สาเหตุ: " . $e->getMessage() . "</h3>";
}
// ฟังก์ชันสำหรับบันทึก Log การทำงาน
if (!function_exists('saveLog')) {
    function saveLog($conn, $user_id, $action_text) {
        try {
            $stmt = $conn->prepare("INSERT INTO user_log (user_id, action_type) VALUES (?, ?)");
            $stmt->execute([$user_id, $action_text]);
        } catch(PDOException $e) {
            // ไม่ต้องแสดง error ออกหน้าจอถ้าบันทึก log พลาด
        }
    }
}
?>