<?php
session_start();
require 'db.php';

// ล็อกประตู: ต้องล็อกอินก่อน
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$alert_script = "";

// 1. เมื่อมีการกดปุ่ม "บันทึกข้อมูล"
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = trim($_POST['full_name']);
    $new_password = $_POST['new_password'];

    // ถ้ามีการกรอกรหัสผ่านใหม่
    if (!empty($new_password)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, password_hash = ? WHERE user_id = ?");
        
        if ($stmt->execute([$full_name, $hashed_password, $user_id])) {
            $_SESSION['full_name'] = $full_name; // อัปเดต Session ให้ชื่อมุมขวาบนเปลี่ยนทันที
            
            // บันทึก Log
            if (function_exists('saveLog')) {
                saveLog($conn, $user_id, 'อัปเดตโปรไฟล์และเปลี่ยนรหัสผ่าน');
            }
            
            $alert_script = "Swal.fire({icon: 'success', title: 'สำเร็จ!', text: 'อัปเดตข้อมูลและเปลี่ยนรหัสผ่านเรียบร้อยแล้ว', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#10b981'});";
        }
    } else {
        // ถ้าไม่ได้กรอกรหัสผ่านใหม่ (แก้แค่ชื่อ)
        $stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE user_id = ?");
        if ($stmt->execute([$full_name, $user_id])) {
            $_SESSION['full_name'] = $full_name; 
            
            if (function_exists('saveLog')) {
                saveLog($conn, $user_id, 'แก้ไขข้อมูลชื่อโปรไฟล์');
            }
            
            $alert_script = "Swal.fire({icon: 'success', title: 'สำเร็จ!', text: 'อัปเดตชื่อ-นามสกุลเรียบร้อยแล้ว', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#10b981'});";
        }
    }
}

// 2. ดึงข้อมูลปัจจุบันมาแสดงในช่องกรอก
$stmt = $conn->prepare("SELECT username, full_name, role FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>โปรไฟล์ส่วนตัว | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { 
            font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; padding: 0 0 40px 0; color: #cbd5e1; min-height: 100vh; display: flex; flex-direction: column; 
        }
        .container { 
            width: 100%; max-width: 600px; margin: 40px auto; background: #1e293b; padding: 40px; border-radius: 12px; border: 1px solid #334155; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2); box-sizing: border-box; flex: 1;
        }
        h2 { color: #f8fafc; margin-top: 0; border-bottom: 1px solid #334155; padding-bottom: 15px; font-size: 24px; font-weight: 600; text-align: center;}
        
        .profile-icon { font-size: 60px; text-align: center; margin-bottom: 10px; }
        
        .form-box { background-color: #0f172a; padding: 30px; border-radius: 8px; border: 1px solid #334155; border-top: 4px solid #10b981; margin-top: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        
        label { display: block; margin-top: 20px; margin-bottom: 8px; font-weight: 500; color: #e2e8f0; font-size: 14px; }
        label:first-child { margin-top: 0; }
        
        input[type="text"], input[type="password"] { 
            width: 100%; padding: 12px 15px; border: 1px solid #475569; border-radius: 6px; background-color: #1e293b; color: #f8fafc; font-family: 'Prompt', sans-serif; font-size: 15px; box-sizing: border-box; transition: all 0.2s ease;
        }
        input[type="text"]:focus, input[type="password"]:focus { border-color: #10b981; outline: none; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); }
        
        input[disabled] { background-color: #0f172a; color: #64748b; cursor: not-allowed; border-style: dashed; }

        .note { font-size: 12px; color: #94a3b8; margin-top: 5px; display: block; }
        
        button { 
            width: 100%; padding: 14px; margin-top: 30px; background-color: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600; transition: all 0.2s ease; 
        }
        button:hover { background-color: #059669; transform: translateY(-2px); box-shadow: 0 6px 8px -1px rgba(16, 185, 129, 0.3);}
        
        .role-badge { background-color: #334155; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; letter-spacing: 0.5px; border: 1px solid #475569;}
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <div class="profile-icon">👤</div>
    <h2>จัดการโปรไฟล์ส่วนตัว</h2>
    
    <div style="text-align: center; margin-bottom: 10px;">
        <span class="role-badge">ระดับสิทธิ์: <?php echo htmlspecialchars($user['role']); ?></span>
    </div>

    <div class="form-box">
        <form method="POST" action="profile.php">
            <label>ชื่อผู้ใช้งาน (Username) - <span style="color:#ef4444;">*ไม่สามารถแก้ไขได้</span></label>
            <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>

            <label for="full_name">ชื่อ-นามสกุล (ที่แสดงในระบบและรายงาน PDF)</label>
            <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>

            <div style="border-top: 1px dashed #475569; margin: 25px 0;"></div>

            <label for="new_password">ตั้งรหัสผ่านใหม่ (เปลี่ยนรหัสผ่าน)</label>
            <input type="password" id="new_password" name="new_password" placeholder="ปล่อยว่างไว้ หากไม่ต้องการเปลี่ยนรหัสผ่าน">
            <span class="note">💡 หากพิมพ์รหัสผ่านใหม่ ระบบจะทำการเข้ารหัส (Hash) ทันทีเพื่อความปลอดภัย</span>

            <button type="submit">💾 บันทึกการเปลี่ยนแปลง</button>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
    <?php if(!empty($alert_script)) echo $alert_script; ?>
</script>

</body>
</html>