<?php
session_start();
require 'db.php';

// 1. ล็อกประตู: เช็คว่าได้ล็อกอินหรือยัง และ เป็น ADMIN หรือไม่?
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าถึงหน้านี้! เฉพาะ Admin เท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

$message = "";

// 2. เมื่อ Admin กดปุ่ม "บันทึกข้อมูลผู้ใช้" (ส่งฟอร์ม)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $full_name = trim($_POST['full_name']);

    // เช็คก่อนว่ามี Username นี้ซ้ำในระบบหรือยัง
    $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $check->execute([$username]);

    if ($check->rowCount() > 0) {
        $message = "<div class='error'>❌ ชื่อผู้ใช้นี้ (Username) มีในฐานข้อมูลแล้ว กรุณาตั้งชื่ออื่น</div>";
    } else {
        // เข้ารหัสผ่าน (Hash) เพื่อความปลอดภัยก่อนลง Database
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // บันทึกข้อมูลลงตาราง users
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, full_name) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$username, $hashed_password, $role, $full_name])) {
            $message = "<div class='success'>✅ ลงทะเบียนบัญชีเจ้าหน้าที่ใหม่สำเร็จ!</div>";
        } else {
            $message = "<div class='error'>❌ เกิดข้อผิดพลาดในระบบฐานข้อมูล</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>เพิ่มผู้ใช้งานใหม่ | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Prompt', sans-serif; 
            background-color: #0f172a; /* Slate 900 */
            margin: 0; 
            padding: 0 0 40px 0; 
            color: #cbd5e1; 
        }
        .container { 
            max-width: 650px; 
            margin: 40px auto; 
            background: #1e293b; /* Slate 800 */
            padding: 40px; 
            border-radius: 12px; 
            border: 1px solid #334155;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2); 
        }
        h2 { 
            color: #f8fafc; 
            margin-top: 0; 
            border-bottom: 1px solid #334155; 
            padding-bottom: 15px; 
            font-size: 24px;
            font-weight: 600;
        }
        
        /* กล่องฟอร์ม */
        .form-box { 
            background-color: #0f172a; 
            padding: 30px; 
            border-radius: 8px; 
            border: 1px solid #334155; 
            border-top: 4px solid #3b82f6; /* เน้นขอบบนสีฟ้าองค์กร */
            margin-top: 25px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        
        label { 
            display: block; 
            margin-top: 20px; 
            margin-bottom: 8px; 
            font-weight: 500; 
            color: #e2e8f0; 
            font-size: 14px; 
        }
        label:first-child { margin-top: 0; }
        
        /* Input สไตล์ Enterprise */
        input[type="text"], input[type="password"], select { 
            width: 100%; 
            padding: 12px 15px; 
            border: 1px solid #475569; 
            border-radius: 6px; 
            background-color: #1e293b; 
            color: #f8fafc; 
            font-family: 'Prompt', sans-serif; 
            font-size: 15px; 
            box-sizing: border-box; 
            transition: all 0.2s ease;
        }
        input[type="text"]:focus, input[type="password"]:focus, select:focus { 
            border-color: #3b82f6; 
            outline: none; 
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); 
        }
        
        /* เปลี่ยนสี Option ให้กลืนกับตีม */
        select option { 
            background: #1e293b; 
            color: #f8fafc; 
        }
        
        /* ปุ่มตกลง */
        button { 
            width: 100%; 
            padding: 14px; 
            margin-top: 30px; 
            background-color: #3b82f6; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 16px; 
            font-weight: 500; 
            transition: all 0.2s ease; 
        }
        button:hover { 
            background-color: #2563eb; 
            transform: translateY(-1px);
        }
        
        /* แจ้งเตือน (Alerts) */
        .success { 
            color: #10b981; 
            background-color: rgba(16, 185, 129, 0.1); 
            border: 1px solid rgba(16, 185, 129, 0.2); 
            padding: 14px 20px; 
            border-radius: 6px; 
            margin-bottom: 25px; 
            font-size: 15px;
            font-weight: 500;
        }
        .error { 
            color: #ef4444; 
            background-color: rgba(239, 68, 68, 0.1); 
            border: 1px solid rgba(239, 68, 68, 0.2); 
            padding: 14px 20px; 
            border-radius: 6px; 
            margin-bottom: 25px; 
            font-size: 15px;
            font-weight: 500;
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <h2>➕ ลงทะเบียนเจ้าหน้าที่ใหม่ (Add New User)</h2>

    <?php echo $message; ?>

    <div class="form-box">
        <form method="POST" action="add_user.php">
            <label for="full_name">ชื่อ-นามสกุลเจ้าหน้าที่</label>
            <input type="text" id="full_name" name="full_name" required placeholder="เช่น ร.ต.อ. สมชาย ใจดี" autocomplete="off">

            <label for="username">ชื่อผู้ใช้งาน (Username)</label>
            <input type="text" id="username" name="username" required placeholder="สำหรับใช้เข้าสู่ระบบ" autocomplete="off">

            <label for="password">รหัสผ่าน (Password)</label>
            <input type="password" id="password" name="password" required placeholder="ตั้งรหัสผ่านเริ่มต้น">

            <label for="role">ระดับสิทธิ์การใช้งาน (Role)</label>
            <select id="role" name="role" required>
                <option value="USER">เจ้าหน้าที่ทั่วไป (USER) - ดูและจัดการคดีของตัวเอง</option>
                <option value="LEADER">หัวหน้าทีม (LEADER) - ดูรายงานและคดีทั้งหมดได้</option>
                <option value="ADMIN">ผู้ดูแลระบบ (ADMIN) - จัดการผู้ใช้และดูข้อมูลได้ทั้งระบบ</option>
            </select>

            <button type="submit">บันทึกข้อมูลเจ้าหน้าที่</button>
        </form>
    </div>
</div>

</body>
</html>