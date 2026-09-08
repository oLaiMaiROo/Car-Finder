<?php
session_start();
require 'db.php';

// ล็อกประตู: ต้องล็อกอินก่อนถึงจะเข้าได้
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";

// 1. รับค่า Status จากการลบ 
if (isset($_GET['status']) && $_GET['status'] == 'deleted') {
    $message = "<script>document.addEventListener('DOMContentLoaded', function() { Swal.fire({icon: 'success', title: 'ลบสำเร็จ!', text: 'แฟ้มคดีและไฟล์ที่เกี่ยวข้องถูกลบออกจากระบบแล้ว', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#3b82f6'}); });</script>";
}

// 2. เมื่อมีการกดปุ่ม "สร้างแฟ้มคดี"
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_case'])) {
    if ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'LEADER') {
        
        // --- ข้อมูลแฟ้มคดี ---
        $case_name = trim($_POST['case_name']);
        $official_case_no = trim($_POST['official_case_no'] ?? '');
        $case_details = trim($_POST['case_details'] ?? '');
        $reporter_name = trim($_POST['reporter_name'] ?? '');
        $reporter_phone = trim($_POST['reporter_phone'] ?? '');
        $incident_date = !empty($_POST['incident_date']) ? $_POST['incident_date'] : NULL;
        $plate_province = trim($_POST['plate_province'] ?? '');
        
        // --- ข้อมูลรถเป้าหมาย/รถต้องสงสัย ---
        $suspect_plate = trim($_POST['suspect_plate'] ?? '');
        $suspect_v_type = $_POST['suspect_v_type'] ?? 'รถยนต์ (Car)';
        $suspect_color = trim($_POST['suspect_color'] ?? '');
        $suspect_status = $_POST['suspect_status'] ?? 'REPORTED';

        $created_by = $_SESSION['user_id']; 

        if (!empty($case_name)) {
            $stmt = $conn->prepare("INSERT INTO case_file (case_name, official_case_no, case_details, reporter_name, reporter_phone, incident_date, plate_province, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt->execute([$case_name, $official_case_no, $case_details, $reporter_name, $reporter_phone, $incident_date, $plate_province, $created_by])) {
                
                $new_case_id = $conn->lastInsertId();

                if (!empty($suspect_plate)) {
                    $stmt_suspect = $conn->prepare("INSERT INTO suspect_vehicle (case_file_id, license_plate, vehicle_type, color, suspect_type) VALUES (?, ?, ?, ?, ?)");
                    $stmt_suspect->execute([$new_case_id, $suspect_plate, $suspect_v_type, $suspect_color, $suspect_status]);
                }

                if (function_exists('saveLog')) {
                    saveLog($conn, $_SESSION['user_id'], "สร้างแฟ้มคดีใหม่: " . $case_name);
                }
                $message = "<div class='success'>✅ สร้างแฟ้มคดี '$case_name' ลงในฐานข้อมูลสำเร็จแล้ว</div>";
            }
        }
    } else {
        $message = "<div class='error'>❌ คุณไม่มีสิทธิ์ในการสร้างแฟ้มคดี</div>";
    }
}

// ==========================================
// 🔒 ระบบกรองคดี: ของใครของมัน (Role-based Access)
// ==========================================
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

if ($role === 'ADMIN' || $role === 'LEADER') {
    $stmt = $conn->query("
        SELECT c.case_file_id, c.case_name, c.official_case_no, c.created_at, c.created_by, c.assigned_to, 
               u.full_name as creator_name, a.full_name as assigned_name 
        FROM case_file c 
        LEFT JOIN users u ON c.created_by = u.user_id 
        LEFT JOIN users a ON c.assigned_to = a.user_id
        ORDER BY c.case_file_id DESC
    ");
    $cases = $stmt->fetchAll();
} else {
    $stmt = $conn->prepare("
        SELECT c.case_file_id, c.case_name, c.official_case_no, c.created_at, c.created_by, c.assigned_to, 
               u.full_name as creator_name, a.full_name as assigned_name 
        FROM case_file c 
        LEFT JOIN users u ON c.created_by = u.user_id 
        LEFT JOIN users a ON c.assigned_to = a.user_id 
        WHERE c.assigned_to = ?
        ORDER BY c.case_file_id DESC
    ");
    $stmt->execute([$user_id]);
    $cases = $stmt->fetchAll();
}

// ✨ ระบบรันเลขคดีอัตโนมัติ (เช่น สภ.เมือง-001/2569) ✨
$thai_year = date('Y') + 543; // หาปี พ.ศ. ปัจจุบัน
$stmt_count = $conn->query("SELECT COUNT(*) FROM case_file WHERE YEAR(created_at) = YEAR(CURRENT_DATE())");
$current_year_cases = $stmt_count->fetchColumn() + 1; // นับจำนวนคดีในปีนี้ แล้วบวก 1 เป็นคดีต่อไป
$auto_generated_case_no = "สภ.เมือง-" . str_pad($current_year_cases, 3, "0", STR_PAD_LEFT) . "/" . $thai_year;

?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการแฟ้มคดี | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; padding: 0 0 40px 0; color: #cbd5e1; }
        .container { max-width: 1100px; margin: 40px auto; background: #1e293b; padding: 35px; border-radius: 12px; border: 1px solid #334155; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2); }
        h2 { color: #f8fafc; margin-top: 0; border-bottom: 1px solid #334155; padding-bottom: 15px; font-size: 24px; font-weight: 600; }
        .form-box { background-color: #0f172a; padding: 24px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #334155; border-left: 4px solid #ef4444; }
        .form-box label { color: #94a3b8; font-weight: 500; font-size: 14px; display: block; margin-bottom: 5px; }
        input[type="text"], input[type="date"], textarea, select { width: 100%; padding: 10px 12px; background-color: #1e293b; border: 1px solid #475569; border-radius: 6px; font-size: 14px; color: #f8fafc; font-family: 'Prompt', sans-serif; transition: all 0.2s ease; box-sizing: border-box; }
        input:focus, textarea:focus, select:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
        button { padding: 10px 24px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: 500; transition: all 0.2s ease; }
        button:hover { background-color: #2563eb; transform: translateY(-1px); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #0f172a; border-radius: 8px; overflow: hidden; border: 1px solid #334155; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid #334155; vertical-align: middle; }
        th { background-color: #1e293b; color: #94a3b8; font-weight: 500; font-size: 14px; }
        tr:hover { background-color: #1e293b; }
        .success { color: #10b981; background-color: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .error { color: #ef4444; background-color: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .btn-group { display: flex; gap: 8px; }
        .action-btn { display: inline-flex; align-items: center; justify-content: center; padding: 6px 12px; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border: 1px solid transparent; cursor: pointer; }
        .btn-open { color: #3b82f6; border-color: #3b82f6; } .btn-open:hover { background-color: #3b82f6; color: white; }
        .btn-edit { color: #f59e0b; border-color: #f59e0b; } .btn-edit:hover { background-color: #f59e0b; color: white; }
        .btn-delete { color: #ef4444; border-color: #ef4444; } .btn-delete:hover { background-color: #ef4444; color: white; }
        .badge-status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; display: inline-block;}
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <h2>📁 จัดการแฟ้มคดี (Case Management) <?php echo ($_SESSION['role'] == 'USER') ? '- งานของคุณ' : ''; ?></h2>
    <?php echo $message; ?>

    <?php if ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'LEADER'): ?>
    <div class="form-box">
        <h3 style="color: #f8fafc; margin-top: 0; margin-bottom: 20px; border-bottom: 1px dashed #334155; padding-bottom: 10px;">
            📝 บันทึกรับแจ้งความ / สร้างแฟ้มคดีใหม่
        </h3>
        <form method="POST" action="cases.php">
            <!-- ข้อมูลแฟ้มคดีหลัก -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px;">
                <div>
                    <label for="official_case_no">เลขที่รับแจ้งความ / รหัสคดีทางการ:</label>
                    <input type="text" id="official_case_no" name="official_case_no" value="<?php echo $auto_generated_case_no; ?>">
                    <p style="font-size: 11px; color: #64748b; margin: 5px 0 0 0;">💡 ระบบรันเลขให้อัตโนมัติ (คุณสามารถลบและพิมพ์เลขใบแจ้งความจริงใส่แทนได้)</p>
                </div>
                <div>
                    <label for="case_name">หัวข้อคดี (ยี่ห้อรถและทะเบียน): <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="case_name" name="case_name" required placeholder="เช่น รถยนต์หาย Honda Civic กท 1234">
                </div>
            </div>

            <!-- ข้อมูลผู้แจ้งและวันเวลา -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 15px;">
                <div>
                    <label for="reporter_name">ชื่อ-นามสกุล ผู้แจ้ง:</label>
                    <input type="text" id="reporter_name" name="reporter_name" placeholder="เช่น นายสมชาย รักดี">
                </div>
                <div>
                    <label for="reporter_phone">เบอร์โทรศัพท์ติดต่อ:</label>
                    <input type="text" id="reporter_phone" name="reporter_phone" placeholder="เช่น 081-xxx-xxxx">
                </div>
                <div>
                    <label for="incident_date">วันที่เกิดเหตุ / วันที่รถหาย:</label>
                    <input type="date" id="incident_date" name="incident_date">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <label for="plate_province">จังหวัด (ป้ายทะเบียน):</label>
                    <input type="text" id="plate_province" name="plate_province" placeholder="เช่น กรุงเทพมหานคร, พะเยา">
                </div>
                <div>
                    <label for="case_details">พฤติการณ์ / ข้อมูลจุดสังเกต / สถานที่เกิดเหตุ:</label>
                    <input type="text" id="case_details" name="case_details" placeholder="เช่น จอดไว้หน้าห้าง... สีรถ... ตำหนิสติกเกอร์ท้ายรถ...">
                </div>
            </div>

            <!-- หมวดใหม่: ลงทะเบียนรถเป้าหมายอัตโนมัติ -->
            <div style="border-top: 1px dashed #475569; margin: 25px 0; padding-top: 20px;">
                <h4 style="color: #f59e0b; margin-top: 0; margin-bottom: 15px;">🚗 ขึ้นบัญชีรถเป้าหมาย / รถต้องสงสัย (สามารถเว้นว่างได้)</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label for="suspect_plate">ทะเบียนรถเป้าหมาย:</label>
                        <input type="text" id="suspect_plate" name="suspect_plate" placeholder="เช่น กท 1234">
                    </div>
                    <div>
                        <label for="suspect_v_type">ประเภทรถ:</label>
                        <select id="suspect_v_type" name="suspect_v_type">
                            <option value="รถยนต์ (Car)">รถยนต์ (Car)</option>
                            <option value="รถกระบะ (Pickup)">รถกระบะ (Pickup)</option>
                            <option value="รถจักรยานยนต์ (Motorcycle)">รถจักรยานยนต์ (Motorcycle)</option>
                            <option value="รถบรรทุก (Truck)">รถบรรทุก (Truck)</option>
                        </select>
                    </div>
                    <div>
                        <label for="suspect_color">สีรถ:</label>
                        <input type="text" id="suspect_color" name="suspect_color" placeholder="เช่น ดำ, ขาว">
                    </div>
                    <div>
                        <label for="suspect_status">สถานะคดี:</label>
                        <select id="suspect_status" name="suspect_status">
                            <option value="STOLEN">รถถูกขโมย (Stolen)</option>
                            <option value="REPORTED">รถแจ้งเบาะแส (Reported)</option>
                        </select>
                    </div>
                </div>
                <p style="font-size: 12px; color: #94a3b8; margin: 0;">💡 หากระบุทะเบียนรถเป้าหมาย ระบบจะนำไปเชื่อมโยงกับฐานข้อมูล AI เพื่อเฝ้าระวังให้โดยอัตโนมัติ</p>
            </div>
            
            <div style="text-align: right;">
                <button type="submit" name="create_case" style="background-color: #ef4444;">🚨 ยืนยันการรับแจ้งเหตุ (เปิดแฟ้มคดี)</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th width="8%">รหัสระบบ</th>
                <th width="22%">ชื่อคดีสืบสวน</th>
                <th width="18%">รหัสคดีทางการ</th>
                <th width="15%">ผู้สร้างคดี</th>
                <th width="12%">วันที่สร้าง</th>
                <th width="15%">ผู้รับผิดชอบ</th>
                <th width="15%">เครื่องมือจัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($cases) > 0): ?>
                <?php foreach ($cases as $row): ?>
                    <tr>
                        <td class="case-id">#<?php echo htmlspecialchars($row['case_file_id']); ?></td>
                        <td class="case-title"><?php echo htmlspecialchars($row['case_name']); ?></td>
                        <td style="color: #cbd5e1; font-size: 14px;">
                            <?php 
                            if (!empty($row['official_case_no'])) {
                                echo "<span style='color:#f8fafc; font-weight:500;'>" . htmlspecialchars($row['official_case_no']) . "</span>";
                            } else {
                                echo "<span style='color:#64748b;'>รอระบุเลขคดี</span>";
                            }
                            ?>
                        </td>
                        <td style="color: #cbd5e1; font-size: 14px;"><i class="fas fa-user-edit" style="color: #64748b; font-size: 12px;"></i> <?php echo htmlspecialchars($row['creator_name'] ? $row['creator_name'] : 'ไม่ทราบชื่อ'); ?></td>
                        <td style="color: #64748b; font-size: 13px;"><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></td>
                        
                        <td>
                            <?php if ($row['assigned_to']): ?>
                                <span class="badge-status" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2);">✅ <?php echo htmlspecialchars($row['assigned_name']); ?></span>
                            <?php else: ?>
                                <span class="badge-status" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2);">⏳ รอส่งมอบ</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="btn-group">
                                <a href="case_detail.php?id=<?php echo $row['case_file_id']; ?>" class="action-btn btn-open">📂 เปิดคดี</a>
                                <?php if ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'LEADER'): ?>
                                    <a href="edit_case.php?id=<?php echo $row['case_file_id']; ?>" class="action-btn btn-edit">✏️</a>
                                    <button class="action-btn btn-delete" onclick="confirmDelete(<?php echo $row['case_file_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['case_name'])); ?>')">🗑️</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #64748b; padding: 40px;">
                        <span style="font-size: 30px; display: block; margin-bottom: 10px;">📁</span>
                        ยังไม่มีแฟ้มคดีในระบบ หรือคุณยังไม่ได้รับมอบหมายงานใดๆ
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function confirmDelete(id, name) {
    Swal.fire({
        title: 'ยืนยันการลบแฟ้มคดี?',
        html: "คุณกำลังจะลบแฟ้มคดี <b>'" + name + "'</b><br><span style='color:#ef4444; font-size:14px;'>⚠️ คำเตือน: ข้อมูลวิดีโอและรูปรถที่เกี่ยวข้องจะถูกลบทิ้งอย่างถาวร!</span>",
        icon: 'warning',
        background: '#1e293b',
        color: '#f8fafc',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#475569',
        confirmButtonText: 'ใช่, ลบถาวร!',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'delete_case.php?id=' + id;
        }
    })
}
</script>

</body>
</html>