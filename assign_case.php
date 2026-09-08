<?php
session_start();
require 'db.php';

// ล็อกสิทธิ์: เข้าได้เฉพาะ ADMIN กับ LEADER
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'ADMIN' && $_SESSION['role'] !== 'LEADER')) {
    echo "<script>alert('Access Denied: คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location.href='index.php';</script>";
    exit();
}

// จัดการเมื่อมีการกดปุ่ม "บันทึกการมอบหมาย"
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_case'])) {
    $case_id = $_POST['case_file_id'];
    $user_id = $_POST['assigned_to']; // ไอดีของ User ที่ถูกเลือก

    // อัปเดตข้อมูลลงฐานข้อมูล
    $stmt = $conn->prepare("UPDATE case_file SET assigned_to = ? WHERE case_file_id = ?");
    if ($stmt->execute([$user_id, $case_id])) {
        // บันทึก Log
        saveLog($conn, $_SESSION['user_id'], "มอบหมายคดี #" . $case_id . " ให้ User ID: " . $user_id);
        echo "<script>alert('✅ มอบหมายงานสำเร็จ!'); window.location.href='assign_case.php';</script>";
        exit();
    }
}

// ดึงรายชื่อแฟ้มคดีทั้งหมด (เรียงคดีที่ยังไม่มอบหมายขึ้นก่อน)
$stmt_cases = $conn->query("
    SELECT c.*, u.full_name as creator_name, a.full_name as assigned_name 
    FROM case_file c 
    LEFT JOIN users u ON c.created_by = u.user_id 
    LEFT JOIN users a ON c.assigned_to = a.user_id
    ORDER BY c.assigned_to IS NULL DESC, c.created_at DESC
");
$cases = $stmt_cases->fetchAll(PDO::FETCH_ASSOC);

// ดึงรายชื่อเจ้าหน้าที่ (USER) ทั้งหมดมาใส่ใน Dropdown
$stmt_users = $conn->query("SELECT user_id, full_name, role FROM users WHERE role IN ('USER', 'LEADER') ORDER BY role ASC, full_name ASC");
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>มอบหมายงานคดี | Car Finder</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; color: #cbd5e1; padding-bottom: 40px;}
        .container { width: 90%; max-width: 1050px; margin: 40px auto; background: #1e293b; padding: 35px; border-radius: 12px; border: 1px solid #334155; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); }
        h2 { color: #f8fafc; border-bottom: 1px solid #334155; padding-bottom: 15px; margin-top: 0; font-size: 24px;}
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background-color: #0f172a; border-radius: 8px; overflow: hidden; border: 1px solid #334155; }
        th, td { padding: 15px 20px; text-align: left; border-bottom: 1px solid #334155; font-size: 14px; }
        th { background-color: #1e293b; color: #94a3b8; font-weight: 500;}
        tr:hover { background-color: #1e293b; }
        .select-user { width: 100%; padding: 10px; border-radius: 6px; background-color: #1e293b; color: #f8fafc; border: 1px solid #475569; font-family: 'Prompt'; font-size: 14px; }
        .btn-assign { background-color: #3b82f6; color: white; border: none; padding: 10px 16px; border-radius: 6px; cursor: pointer; font-family: 'Prompt'; font-weight: 500; transition: 0.2s; }
        .btn-assign:hover { background-color: #2563eb; }
        .status-badge { padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; display: inline-block;}
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <h2>📋 ระบบมอบหมายแฟ้มคดีให้เจ้าหน้าที่</h2>
    <p style="color: #94a3b8; margin-bottom: 25px;">เลือกคดีและระบุชื่อเจ้าหน้าที่ที่ต้องการให้รับผิดชอบในการตรวจสอบหลักฐาน AI (เจ้าหน้าที่จะมองเห็นคดีที่ถูกมอบหมายในระบบของตนเอง)</p>

    <table>
        <thead>
            <tr>
                <th width="10%">รหัสคดี</th>
                <th width="30%">ชื่อคดีสืบสวน</th>
                <th width="20%">สถานะมอบหมาย</th>
                <th width="30%">เลือกผู้รับผิดชอบ</th>
                <th width="10%">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cases as $c): ?>
            <tr>
                <td style="color:#94a3b8;">#<?= $c['case_file_id'] ?></td>
                <td style="color:#f8fafc; font-weight: 500;"><?= htmlspecialchars($c['case_name']) ?></td>
                <td>
                    <?php if ($c['assigned_to']): ?>
                        <span class="status-badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">✅ <?= htmlspecialchars($c['assigned_name']) ?></span>
                    <?php else: ?>
                        <span class="status-badge" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);">⏳ ยังไม่มอบหมาย</span>
                    <?php endif; ?>
                </td>
                <form method="POST">
                    <td>
                        <input type="hidden" name="case_file_id" value="<?= $c['case_file_id'] ?>">
                        <select name="assigned_to" class="select-user" required>
                            <option value="">-- ค้นหา / เลือกรายชื่อเจ้าหน้าที่ --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['user_id'] ?>" <?= ($c['assigned_to'] == $u['user_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['full_name']) ?> (<?= $u['role'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <button type="submit" name="assign_case" class="btn-assign">บันทึก</button>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'footer.php'; ?>
</body>
</html>