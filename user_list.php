<?php
session_start();
require 'db.php';

// บังคับให้เฉพาะ ADMIN เข้าหน้านี้ได้
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    echo "<script>alert('Access Denied: เฉพาะผู้ดูแลระบบเท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

// ดึงข้อมูลผู้ใช้ทั้งหมด
$stmt = $conn->query("SELECT * FROM users ORDER BY role ASC, full_name ASC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายชื่อเจ้าหน้าที่ | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; padding: 0 0 40px 0; color: #cbd5e1; min-height: 100vh; }
        .container { width: 95%; max-width: 1100px; margin: 40px auto; background-color: rgba(30, 41, 59, 0.7); padding: 35px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); }
        h2 { color: #f8fafc; margin-top: 0; font-size: 24px; border-bottom: 1px solid #334155; padding-bottom: 15px; display: flex; justify-content: space-between; align-items: center;}
        
        .btn-add { background-color: #3b82f6; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500; transition: 0.2s; }
        .btn-add:hover { background-color: #2563eb; }

        .table-container { background-color: rgba(15, 23, 42, 0.6); border-radius: 10px; border: 1px solid #334155; overflow: hidden; margin-top: 20px;}
        .data-table { width: 100%; border-collapse: collapse; text-align: left; }
        .data-table th, .data-table td { padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 14px; }
        .data-table th { background-color: rgba(30, 41, 59, 0.8); color: #94a3b8; font-weight: 500; }
        .data-table tr:hover { background-color: rgba(30, 41, 59, 0.4); }

        .role-badge { padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .role-admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .role-leader { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .role-user { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }

        /* ✨ สไตล์สำหรับสถานะ Online / Offline ✨ */
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .status-online { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; }
        .status-offline { background: rgba(100, 116, 139, 0.1); border: 1px solid rgba(100, 116, 139, 0.3); color: #94a3b8; }
        .dot { width: 8px; height: 8px; border-radius: 50%; }
        .dot-online { background-color: #10b981; box-shadow: 0 0 5px #10b981; animation: blink 2s infinite; }
        .dot-offline { background-color: #64748b; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <h2>
        👥 รายชื่อเจ้าหน้าที่ในระบบ (User Database)
        <a href="add_user.php" class="btn-add">➕ เพิ่มเจ้าหน้าที่ใหม่</a>
    </h2>

    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ลำดับ</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>ชื่อผู้ใช้ (Username)</th>
                    <th>สิทธิ์การใช้งาน</th>
                    <th>สถานะ (Status)</th>
                    <th>ใช้งานล่าสุด</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($users as $user): ?>
                    <tr>
                        <td style="color: #64748b;"><?php echo $i++; ?></td>
                        <td style="color: #f8fafc; font-weight: 500;"><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td>
                            <?php if ($user['role'] == 'ADMIN'): ?>
                                <span class="role-badge role-admin">ADMIN</span>
                            <?php elseif ($user['role'] == 'LEADER'): ?>
                                <span class="role-badge role-leader">LEADER</span>
                            <?php else: ?>
                                <span class="role-badge role-user">USER</span>
                            <?php endif; ?>
                        </td>
                        
                        <td>
                            <?php 
                                $is_online = false;
                                $time_text = "ไม่เคยเข้าใช้งาน";
                                
                                if (!empty($user['last_seen'])) {
                                    $last_seen_time = strtotime($user['last_seen']);
                                    $current_time = time();
                                    $diff_seconds = $current_time - $last_seen_time;
                                    
                                    // ✨ ถ้าระบบเปิดสวิตช์ออนไลน์ไว้ และเพิ่งขยับเมาส์ใน 5 นาที (300 วิ) ถือว่าออนไลน์ ✨
                                    if (isset($user['is_online']) && $user['is_online'] == 1 && $diff_seconds <= 300) {
                                        $is_online = true;
                                    }
                                    
                                    // แปลงเวลาให้ดูง่ายขึ้น (จะแสดงเวลาเสมอ ไม่หายไปไหนแล้ว)
                                    $time_text = date('d/m/Y H:i', $last_seen_time);
                                }
                            ?>

                            <?php if ($is_online): ?>
                                <span class="status-badge status-online">
                                    <div class="dot dot-online"></div> ออนไลน์
                                </span>
                            <?php else: ?>
                                <span class="status-badge status-offline">
                                    <div class="dot dot-offline"></div> ออฟไลน์
                                </span>
                            <?php endif; ?>
                        </td>
                        
                        <td style="color: #64748b; font-size: 13px;"><?php echo $time_text; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>