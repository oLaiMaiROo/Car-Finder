<?php
session_start();
require 'db.php';

// เช็คสิทธิ์ (เข้าได้เฉพาะ ADMIN และ LEADER)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'ADMIN' && $_SESSION['role'] !== 'LEADER')) {
    die("Access Denied: คุณไม่มีสิทธิ์เข้าถึงหน้านี้");
}

// คิวรีดึงประวัติ PDF จากตาราง report โดยตรง
try {
    $stmt_pdf = $conn->query("
        SELECT r.*, c.case_name, u.full_name 
        FROM report r 
        JOIN case_file c ON r.case_file_id = c.case_file_id 
        JOIN users u ON r.generated_by = u.user_id 
        ORDER BY r.created_at DESC
    ");
    $reports = $stmt_pdf->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ดัก Error ไว้เผื่อตารางมีปัญหา หน้าเว็บจะได้ไม่ขาว
    $reports = [];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>คลังประวัติเอกสาร PDF | Car Finder</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; padding: 0 0 40px 0; color: #cbd5e1; }
        .container { 
            width: 90%; max-width: 1150px; margin: 40px auto; 
            background-color: rgba(30, 41, 59, 0.75); padding: 40px; border-radius: 12px; 
            border: 1px solid rgba(255, 255, 255, 0.05); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); 
        }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 20px;}
        h2 { margin: 0; color: #f8fafc; font-weight: 600; font-size: 24px; }
        .btn-back { background: transparent; color: #94a3b8; border: 1px solid #475569; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; transition: 0.2s;}
        .btn-back:hover { background: #334155; color: #f8fafc; }
        
        .table-wrapper { background-color: rgba(15, 23, 42, 0.5); border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.05); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px 20px; text-align: left; border-bottom: 1px solid rgba(255, 255, 255, 0.05); font-size: 14px; }
        th { background-color: rgba(30, 41, 59, 0.8); color: #94a3b8; font-weight: 500; font-size: 13px; }
        tr:hover { background-color: rgba(30, 41, 59, 0.5); }
        
        .btn-pdf { background-color: rgba(244, 63, 94, 0.1); color: #fb7185; padding: 6px 12px; border-radius: 4px; font-size: 13px; font-weight: 600; text-decoration: none; border: 1px solid rgba(244, 63, 94, 0.3); transition: all 0.2s; display: inline-block;}
        .btn-pdf:hover { background-color: #f43f5e; color: #fff; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <div class="header">
        <h2><i class="fas fa-file-pdf" style="color:#ef4444;"></i> คลังประวัติเอกสารรายงาน (PDF Archives)</h2>
        <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> กลับสู่หน้าหลัก</a>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th width="20%">วันที่บันทึกเอกสาร</th>
                    <th width="35%">อ้างอิงแฟ้มคดี</th>
                    <th width="25%">เจ้าหน้าที่ผู้ออกรายงาน</th>
                    <th width="20%" style="text-align: center;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($reports) > 0): ?>
                    <?php foreach ($reports as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?php echo date('d/m/Y H:i:s', strtotime($row['created_at'])); ?></td>
                            <td style="color:#cbd5e1; font-weight:500;"><?php echo htmlspecialchars($row['case_name']); ?></td>
                            <td style="color:#cbd5e1;"><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td style="text-align: center;">
                                <a href="uploads/reports/<?php echo htmlspecialchars($row['file_path']); ?>" target="_blank" class="btn-pdf">
                                    <i class="fas fa-eye"></i> ดูไฟล์ PDF
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align: center; padding: 40px; color: #64748b;">ยังไม่มีประวัติการบันทึกเอกสาร PDF ในระบบ</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>