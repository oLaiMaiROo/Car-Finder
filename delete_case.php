<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    die("สิทธิ์ไม่เพียงพอ");
}

$id = $_GET['id'];

// 1. ลบไฟล์ในโฟลเดอร์ก่อน (ฟังก์ชัน Recursive ลบทั้งโฟลเดอร์)
function deleteFolder(string $dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteFolder($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

$target_dir = "uploads/case_" . $id;
deleteFolder($target_dir);

// 2. ลบข้อมูลจากฐานข้อมูล
$stmt = $conn->prepare("DELETE FROM case_file WHERE case_file_id = ?");
if ($stmt->execute([$id])) {
    // ✨ แทรกโค้ด Log ตรงนี้
    saveLog($conn, $_SESSION['user_id'], "ลบแฟ้มคดีถาวร (รหัสอ้างอิง: #" . $id . ")");
    
    header("Location: cases.php?status=deleted");
}
?>