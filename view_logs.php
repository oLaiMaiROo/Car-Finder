<?php
session_start();
require 'db.php';

// ล็อกประตู: เฉพาะ ADMIN เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าถึงหน้านี้! เฉพาะ Admin เท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

// ==========================================
// 📄 ระบบแบ่งหน้า (Pagination Logic)
// ==========================================
$records_per_page = 20; // กำหนดจำนวน Log ที่จะแสดงต่อ 1 หน้า

// รับค่าหมายเลขหน้าปัจจุบันจาก URL (ถ้าไม่มีให้ถือว่าเป็นหน้า 1)
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// คำนวณจุดเริ่มต้นของข้อมูล (Offset)
$offset = ($page - 1) * $records_per_page;

// 1. หาจำนวน Log ทั้งหมดในระบบ เพื่อเอามาคำนวณจำนวนหน้า
$stmt_total = $conn->query("SELECT COUNT(*) FROM user_log");
$total_records = $stmt_total->fetchColumn();
$total_pages = ceil($total_records / $records_per_page); // ปัดเศษขึ้นเสมอ

// 2. ดึงข้อมูล Log เฉพาะหน้าที่กำลังเปิดอยู่ (ใช้ LIMIT และ OFFSET)
$stmt = $conn->prepare("
    SELECT l.*, u.full_name, u.role 
    FROM user_log l 
    LEFT JOIN users u ON l.user_id = u.user_id 
    ORDER BY l.action_timestamp DESC 
    LIMIT :offset, :limit
");
// การส่งค่า LIMIT/OFFSET ใน PDO ต้องระบุเป็นตัวเลข (PARAM_INT)
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
// ==========================================
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ประวัติการใช้งาน | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Prompt', sans-serif; 
            background-color: #0f172a; /* Slate 900 */
            margin: 0; 
            padding: 0 0 40px 0; 
            color: #cbd5e1; 
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .container { 
            width: 100%;
            max-width: 1000px; 
            margin: 40px auto; 
            background: #1e293b; /* Slate 800 */
            padding: 35px; 
            border-radius: 12px; 
            border: 1px solid #334155;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2); 
            box-sizing: border-box;
            flex: 1;
        }
        
        /* ✨ อัปเดตส่วน Header เพื่อรองรับปุ่ม Export */
        .page-header {
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px solid #334155; 
            padding-bottom: 15px; 
            margin-bottom: 20px;
        }
        h2 { margin: 0; color: #f8fafc; font-size: 24px; font-weight: 600; }
        
        .btn-export {
            display: inline-flex; align-items: center; gap: 8px;
            background-color: #10b981; color: white; border: none; 
            padding: 10px 16px; border-radius: 6px; font-size: 14px; 
            font-weight: 500; text-decoration: none; transition: all 0.2s ease; 
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
        }
        .btn-export:hover {
            background-color: #059669; transform: translateY(-2px);
        }
        /* ✨ จบส่วน Header */

        .table-wrapper {
            margin-top: 20px;
            border-radius: 8px; 
            border: 1px solid #334155; 
            background: #0f172a;
            overflow-x: auto;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #1e293b; }
        th { background-color: #1e293b; color: #94a3b8; font-weight: 500; font-size: 14px; }
        tr { transition: background-color 0.2s ease; }
        tr:hover { background-color: #1e293b; }

        .log-index { color: #64748b; font-family: 'Courier New', monospace; font-size: 13px; }
        .log-time { color: #94a3b8; font-family: 'Courier New', monospace; font-size: 14px; }
        .log-user { color: #f8fafc; font-weight: 500; }
        
        .role-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; text-align: center; min-width: 60px; }
        .role-admin { color: #ef4444; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); }
        .role-leader { color: #f59e0b; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2); }
        .role-user { color: #10b981; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); }
        
        .log-action-badge { background-color: #334155; color: #e2e8f0; padding: 4px 10px; border-radius: 4px; font-size: 13px; border: 1px solid #475569; }

        .pagination { display: flex; justify-content: center; align-items: center; margin-top: 25px; gap: 10px; }
        .page-btn { background-color: #0f172a; color: #94a3b8; border: 1px solid #334155; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s ease; }
        .page-btn:hover { background-color: #3b82f6; color: #ffffff; border-color: #3b82f6; }
        .page-btn.disabled { opacity: 0.5; pointer-events: none; }
        .page-info { color: #cbd5e1; font-size: 14px; background-color: #1e293b; padding: 8px 16px; border-radius: 6px; border: 1px solid #334155; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    
    <div class="page-header">
        <h2>📝 ประวัติการใช้งานระบบ (System Activity Logs)</h2>
        <a href="export_logs.php" class="btn-export">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            
        </a>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ลำดับ</th>
                    <th>วัน-เวลาที่บันทึก</th>
                    <th>เจ้าหน้าที่</th>
                    <th>ระดับสิทธิ์</th>
                    <th>รายละเอียดการทำงาน</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($logs) > 0): ?>
                    <?php 
                        // คำนวณเลขลำดับให้รันต่อกันข้ามหน้า
                        $i = $offset + 1; 
                        foreach ($logs as $row): 
                    ?>
                        <tr>
                            <td class="log-index"><?php echo str_pad($i++, 3, '0', STR_PAD_LEFT); ?></td>
                            <td class="log-time"><?php echo date('d/m/Y H:i:s', strtotime($row['action_timestamp'])); ?></td>
                            <td class="log-user"><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td>
                                <?php 
                                    if ($row['role'] == 'ADMIN') echo "<span class='role-badge role-admin'>ADMIN</span>";
                                    elseif ($row['role'] == 'LEADER') echo "<span class='role-badge role-leader'>LEADER</span>";
                                    else echo "<span class='role-badge role-user'>USER</span>";
                                ?>
                            </td>
                            <td>
                                <span class="log-action-badge"><?php echo htmlspecialchars($row['action_type']); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #94a3b8; padding: 30px;">ไม่พบประวัติการใช้งานในระบบ</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <a href="?page=<?php echo $page - 1; ?>" class="page-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
            « ก่อนหน้า
        </a>
        
        <span class="page-info">หน้า <b><?php echo $page; ?></b> จาก <?php echo $total_pages; ?></span>
        
        <a href="?page=<?php echo $page + 1; ?>" class="page-btn <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
            ถัดไป »
        </a>
    </div>
    <?php endif; ?>

</div>

<?php include 'footer.php'; ?>

</body>
</html>