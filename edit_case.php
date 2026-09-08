<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$id = $_GET['id'];
$stmt = $conn->prepare("SELECT * FROM case_file WHERE case_file_id = ?");
$stmt->execute([$id]);
$case = $stmt->fetch();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_name = $_POST['case_name'];
    $update = $conn->prepare("UPDATE case_file SET case_name = ? WHERE case_file_id = ?");
    if ($update->execute([$new_name, $id])) {
        echo "<script>alert('อัปเดตชื่อคดีเรียบร้อย'); window.location.href='cases.php';</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แก้ไขชื่อคดี | AI Vehicle Tracking</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; color: #cbd5e1; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .edit-card { background: #1e293b; padding: 30px; border-radius: 12px; border: 1px solid #334155; width: 400px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); }
        input { width: 100%; padding: 10px; margin-top: 10px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: white; box-sizing: border-box; }
        .btn-group { display: flex; gap: 10px; margin-top: 20px; }
        .btn { flex: 1; padding: 10px; border: none; border-radius: 6px; cursor: pointer; color: white; text-align: center; text-decoration: none; }
    </style>
</head>
<body>
    <div class="edit-card">
        <h3 style="margin-top:0;">✏️ แก้ไขชื่อแฟ้มคดี</h3>
        <form method="POST">
            <label>ชื่อคดีใหม่:</label>
            <input type="text" name="case_name" value="<?php echo htmlspecialchars($case['case_name']); ?>" required>
            <div class="btn-group">
                <a href="cases.php" class="btn" style="background: #475569;">ยกเลิก</a>
                <button type="submit" class="btn" style="background: #3b82f6;">บันทึกข้อมูล</button>
            </div>
        </form>
    </div>
</body>
</html>