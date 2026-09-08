<?php
session_start();
require 'db.php';

// ถ้าล็อกอินอยู่แล้ว ให้เด้งไปหน้าหลักเลย
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // ค้นหาผู้ใช้ในระบบ
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // ตรวจสอบรหัสผ่าน
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];

        $log_stmt = $conn->prepare("INSERT INTO user_log (user_id, action_type) VALUES (?, 'เข้าสู่ระบบ (Login)')");
        $log_stmt->execute([$user['user_id']]);

        header("Location: index.php");
        exit();
    } else {
        $error = "ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง โปรดลองอีกครั้ง";
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>เข้าสู่ระบบ | Car Finder </title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Prompt', sans-serif; 
            background-color: #0f172a; /* Slate 900 (สีพื้นหลังสุด) */
            margin: 0; 
            padding: 0;
            color: #cbd5e1; 
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            overflow: hidden; /* ป้องกันไม่ให้เกิด Scrollbar ตอนแสงขยับ */
            position: relative;
        }

        /* 🌟 เอฟเฟกต์แสงออร่าเคลื่อนไหวเบาๆ ด้านหลัง */
        .ambient-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(90px); /* เบลอให้ฟุ้งกลายเป็นแสง */
            z-index: 1;
            opacity: 0.4;
            animation: drift alternate infinite ease-in-out;
        }

        .blob-1 {
            width: 450px;
            height: 450px;
            background-color: #3b82f6; /* สีฟ้า */
            top: -100px;
            left: -100px;
            animation-duration: 18s;
        }

        .blob-2 {
            width: 400px;
            height: 400px;
            background-color: #8b5cf6; /* สีม่วง */
            bottom: -150px;
            right: -50px;
            animation-duration: 22s;
            animation-direction: alternate-reverse;
        }

        .blob-3 {
            width: 350px;
            height: 350px;
            background-color: #0ea5e9; /* สีฟ้าอ่อน */
            top: 30%;
            left: 60%;
            animation-duration: 25s;
            opacity: 0.2;
        }

        /* Keyframes กำหนดการเคลื่อนที่ของแสงแบบสุ่มเบาๆ */
        @keyframes drift {
            0% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(60px, 40px) scale(1.1); }
            100% { transform: translate(-40px, 60px) scale(0.9); }
        }
        
        /* 📦 กล่องล็อกอิน (ดีไซน์กระจกฝ้า Glassmorphism) */
        .login-wrapper {
            width: 100%;
            max-width: 420px;
            padding: 20px;
            box-sizing: border-box;
            position: relative;
            z-index: 10; /* ให้กล่องอยู่เหนือแสง */
        }

        .login-card { 
            /* พื้นหลังกึ่งโปร่งใส และใส่เบลอข้างหลังกล่อง (กระจกฝ้า) */
            background-color: rgba(30, 41, 59, 0.75); 
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            
            padding: 40px; 
            border-radius: 16px; 
            border: 1px solid rgba(255, 255, 255, 0.1); /* ขอบกล่องสีขาวจางๆ ให้ดูคม */
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); 
            text-align: center;
        }

        .logo-icon {
            font-size: 48px;
            margin-bottom: 10px;
            text-shadow: 0 0 20px rgba(59, 130, 246, 0.5); /* แสงเรืองรองใต้โลโก้ */
        }

        h2 { 
            color: #f8fafc; 
            margin-top: 0; 
            margin-bottom: 5px;
            font-size: 24px;
            font-weight: 600;
        }

        .subtitle {
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 30px;
        }
        
        form {
            text-align: left;
        }

        label { 
            display: block; 
            font-weight: 500; 
            color: #e2e8f0; 
            margin-bottom: 8px; 
            font-size: 14px; 
        }
        
        .input-group {
            margin-bottom: 20px;
        }

        input[type="text"], input[type="password"] { 
            width: 100%; 
            padding: 12px 16px; 
            border: 1px solid rgba(255, 255, 255, 0.1); 
            border-radius: 8px; 
            background-color: rgba(15, 23, 42, 0.6); /* สีดำกึ่งโปร่งใส */
            color: #f8fafc; 
            font-family: 'Prompt', sans-serif; 
            font-size: 15px; 
            box-sizing: border-box; 
            transition: all 0.2s ease;
        }
        
        input[type="text"]:focus, input[type="password"]:focus { 
            border-color: #3b82f6; 
            outline: none; 
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); 
            background-color: rgba(15, 23, 42, 0.9);
        }
        
        button { 
            width: 100%; 
            padding: 14px; 
            margin-top: 10px; 
            background-color: #3b82f6; 
            color: white; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 16px; 
            font-weight: 500; 
            transition: all 0.2s ease; 
        }
        button:hover { 
            background-color: #2563eb; 
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.4);
        }
        
        .error-message { 
            color: #ef4444; 
            background-color: rgba(239, 68, 68, 0.1); 
            border: 1px solid rgba(239, 68, 68, 0.2); 
            padding: 12px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
            font-size: 14px;
            font-weight: 500;
        }

        .system-footer {
            margin-top: 25px;
            font-size: 12px;
            color: #64748b;
        }
    </style>
</head>
<body>

<div class="ambient-blob blob-1"></div>
<div class="ambient-blob blob-2"></div>
<div class="ambient-blob blob-3"></div>

<div class="login-wrapper">
    <div class="login-card">
        <div class="logo-icon">🛡️</div>
        <h2>Car Finder</h2>
        <div class="subtitle">ระบบวิเคราะห์และตรวจจับยานพาหนะอัจฉริยะ</div>

        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="input-group">
                <label for="username">ชื่อผู้ใช้งาน (Username)</label>
                <input type="text" id="username" name="username" required placeholder="กรอกชื่อผู้ใช้งาน" autocomplete="off">
            </div>

            <div class="input-group">
                <label for="password">รหัสผ่าน (Password)</label>
                <input type="password" id="password" name="password" required placeholder="กรอกรหัสผ่าน">
            </div>

            <button type="submit">เข้าสู่ระบบ</button>
        </form>
        
        <div class="system-footer">
            &copy; 2026 AI Intelligence System. All rights reserved.
        </div>
    </div>
</div>

</body>
</html>