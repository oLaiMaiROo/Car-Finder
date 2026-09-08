<?php
session_start();
require 'db.php';

// ล็อกประตู: ให้ทุกคนที่ล็อกอินเข้าได้
if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('กรุณาเข้าสู่ระบบ!'); window.location.href='login.php';</script>";
    exit();
}

$role = $_SESSION['role'];
$alert_script = ""; 

// 1. เมื่อกดปุ่มเพิ่มข้อมูล (ADMIN)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_suspect']) && $role === 'ADMIN') {
    $license_plate = trim($_POST['license_plate']);
    $vehicle_type = $_POST['vehicle_type'];
    $color = trim($_POST['color']);
    $suspect_type = $_POST['suspect_type'];
    // ✨ รับค่าคดีที่เชื่อมโยง
    $case_file_id = !empty($_POST['case_file_id']) ? $_POST['case_file_id'] : NULL;

    if (!empty($license_plate)) {
        $stmt = $conn->prepare("INSERT INTO suspect_vehicle (case_file_id, license_plate, vehicle_type, color, suspect_type) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$case_file_id, $license_plate, $vehicle_type, $color, $suspect_type])) {
            if (function_exists('saveLog')) saveLog($conn, $_SESSION['user_id'], "เพิ่มข้อมูลเป้าหมายใหม่ ทะเบียน: " . $license_plate);
            $alert_script = "Swal.fire({ icon: 'success', title: 'บันทึกสำเร็จ!', text: 'เพิ่มข้อมูลรถเข้าสู่ระบบแล้ว', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#3b82f6' });";
        }
    }
}

// 1.5. เมื่อกดปุ่มแก้ไขข้อมูล (ADMIN)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_suspect']) && $role === 'ADMIN') {
    $edit_id = $_POST['suspect_id'];
    $license_plate = trim($_POST['license_plate']);
    $vehicle_type = $_POST['vehicle_type'];
    $color = trim($_POST['color']);
    $suspect_type = $_POST['suspect_type'];
    // ✨ รับค่าคดีที่เชื่อมโยง
    $case_file_id = !empty($_POST['case_file_id']) ? $_POST['case_file_id'] : NULL;

    if (!empty($license_plate)) {
        $stmt = $conn->prepare("UPDATE suspect_vehicle SET case_file_id = ?, license_plate = ?, vehicle_type = ?, color = ?, suspect_type = ? WHERE suspect_id = ?");
        if ($stmt->execute([$case_file_id, $license_plate, $vehicle_type, $color, $suspect_type, $edit_id])) {
            if (function_exists('saveLog')) saveLog($conn, $_SESSION['user_id'], "แก้ไขข้อมูลเป้าหมาย ทะเบียน: " . $license_plate);
            $alert_script = "Swal.fire({ icon: 'success', title: 'อัปเดตสำเร็จ!', text: 'แก้ไขข้อมูลรถเป้าหมายแล้ว', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#3b82f6' });";
        }
    }
}

// 2. เมื่อกดปุ่มลบข้อมูล
if (isset($_GET['delete_id']) && $role === 'ADMIN') {
    $del_id = $_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM suspect_vehicle WHERE suspect_id = ?");
    if ($stmt->execute([$del_id])) {
        if (function_exists('saveLog')) saveLog($conn, $_SESSION['user_id'], "ลบข้อมูลเป้าหมาย (รหัสอ้างอิง: #" . $del_id . ")");
        $_SESSION['del_success'] = true;
        header("Location: manage_suspect.php");
        exit();
    }
}

if (isset($_SESSION['del_success'])) {
    $alert_script = "Swal.fire({ icon: 'success', title: 'ลบข้อมูลเป้าหมายสำเร็จ', background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#3b82f6', timer: 2000, showConfirmButton: false });";
    unset($_SESSION['del_success']);
}

// ✨ ดึงรายชื่อแฟ้มคดีทั้งหมดมาทำ Dropdown
$stmt_cases = $conn->query("SELECT case_file_id, case_name, official_case_no FROM case_file ORDER BY created_at DESC");
$all_cases = $stmt_cases->fetchAll(PDO::FETCH_ASSOC);

// 3. ดึงข้อมูลรถต้องสงสัยทั้งหมด
$suspects = [];
if ($role === 'ADMIN' || $role === 'USER') {
    // ✨ JOIN กับ case_file เพื่อเอาเลขคดีมาโชว์ในตาราง
    $stmt = $conn->query("
        SELECT sv.*, c.official_case_no, c.case_name 
        FROM suspect_vehicle sv 
        LEFT JOIN case_file c ON sv.case_file_id = c.case_file_id 
        ORDER BY sv.created_at DESC
    ");
    $suspects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4. ดึงข้อมูลรูปภาพรถที่ตรวจพบ
$detected_results = [];
$total_pages = 1;
$page = 1;

if ($role === 'LEADER' || $role === 'USER') {
    $records_per_page = 12;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $records_per_page;

    // ✨ JOIN ตาราง case_file รอบที่สอง (sc) เพื่อดึงคดีต้นทางของรถต้องสงสัย
    $base_sql = "FROM detections d
                 JOIN images i ON d.image_id = i.image_id
                 JOIN videos v ON i.video_id = v.video_id
                 JOIN case_file c ON v.case_file_id = c.case_file_id
                 LEFT JOIN suspect_vehicle sv ON REPLACE(d.detected_license_plate, ' ', '') = REPLACE(sv.license_plate, ' ', '')
                 LEFT JOIN case_file sc ON sv.case_file_id = sc.case_file_id
                 WHERE (d.is_verified IN (1, 2) OR sv.license_plate IS NOT NULL)"; 

    $params = [];

    $count_sql = "SELECT COUNT(*) " . $base_sql;
    $stmt_count = $conn->prepare($count_sql);
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    
    $total_pages = ceil($total_records / $records_per_page);
    if ($total_pages < 1) $total_pages = 1;

    $main_sql = "SELECT d.*, i.image_path, i.frame_number, v.video_path, v.fps, 
                        c.case_name, c.official_case_no, c.case_file_id, 
                        sv.suspect_type, sc.official_case_no AS suspect_official_no, sc.case_name AS suspect_case_name " 
                . $base_sql . " ORDER BY d.is_verified DESC, d.detected_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt_detected = $conn->prepare($main_sql);
    $stmt_detected->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    $stmt_detected->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt_detected->execute();
    $detected_results = $stmt_detected->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการรถต้องสงสัย | ระบบตรวจจับยานพาหนะ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; margin: 0; padding: 0 0 40px 0; color: #cbd5e1; min-height: 100vh; display: flex; flex-direction: column; }
        .container { width: 90%; max-width: 1200px; margin: 40px auto; background: #1e293b; padding: 35px; border-radius: 12px; border: 1px solid #334155; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2); box-sizing: border-box; flex: 1; }
        h2 { color: #f8fafc; margin-top: 0; border-bottom: 1px solid #334155; padding-bottom: 15px; font-size: 24px; font-weight: 600; }
        .form-box { background-color: #0f172a; padding: 24px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #334155; border-left: 4px solid #ef4444; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .form-group { display: flex; flex-direction: column; }
        label { font-weight: 500; color: #e2e8f0; margin-bottom: 8px; font-size: 14px; }
        input[type="text"], select { padding: 10px 12px; border: 1px solid #475569; border-radius: 6px; background-color: #1e293b; color: #f8fafc; font-family: 'Prompt', sans-serif; font-size: 14px; transition: all 0.2s ease; }
        input[type="text"]:focus, select:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
        .btn-add { padding: 12px 24px; background-color: #ef4444; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: 500; transition: all 0.2s ease; align-self: flex-end; }
        .btn-add:hover { background-color: #dc2626; transform: translateY(-1px); }
        .btn-export { display: inline-flex; align-items: center; gap: 8px; background-color: #10b981; color: white; padding: 10px 16px; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500; transition: all 0.2s ease; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2); }
        .btn-export:hover { background-color: #059669; transform: translateY(-2px); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #0f172a; border-radius: 8px; overflow: hidden; border: 1px solid #334155; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid #334155; }
        th { background-color: #1e293b; color: #94a3b8; font-weight: 500; font-size: 14px;}
        tr:hover { background-color: #1e293b; }
        .btn-edit { background: transparent; color: #3b82f6; padding: 6px 12px; border: 1px solid #3b82f6; border-radius: 6px; font-size: 13px; font-weight: 500; transition: all 0.2s ease; cursor: pointer; margin-right: 5px; }
        .btn-edit:hover { background: #3b82f6; color: #ffffff; }
        .btn-delete { background: transparent; color: #ef4444; padding: 6px 12px; border: 1px solid #ef4444; border-radius: 6px; font-size: 13px; font-weight: 500; transition: all 0.2s ease; cursor: pointer; }
        .btn-delete:hover { background: #ef4444; color: #ffffff; }
        .badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; letter-spacing: 0.5px; display: inline-block; }
        .bg-danger { color: #f8fafc; background-color: #ef4444; }
        .bg-warning { color: #78350f; background-color: #f59e0b; }
        .plate-text { color: #f8fafc; font-weight: 600; background-color: #334155; padding: 4px 10px; border-radius: 4px; border: 1px solid #475569; }
        .result-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; margin-top: 20px; }
        .card { background-color: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.05); border-radius: 10px; overflow: hidden; transition: 0.2s; }
        .card.alert-card { border: 1px solid #ef4444; }
        .card img { width: 100%; height: 160px; object-fit: cover; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        .card-body { padding: 15px; }
        .plate-badge-card { background-color: #334155; color: #f8fafc; font-size: 18px; font-weight: 600; padding: 6px 12px; border-radius: 6px; display: inline-block; margin-bottom: 12px; border: 1px solid #475569; }
        .alert-text { background: rgba(239, 68, 68, 0.1); color: #ef4444; padding: 8px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 10px; border: 1px solid rgba(239, 68, 68, 0.2); }
        .btn-case { display: block; width: 100%; text-align: center; background: #3b82f6; color: white; padding: 8px 0; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500; margin-top: 10px; transition: 0.2s; }
        .btn-case:hover { background: #2563eb; }
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.8); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: #1e293b; padding: 30px; border-radius: 12px; width: 90%; max-width: 600px; border: 1px solid #334155; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    
    <?php if ($role === 'ADMIN' || $role === 'USER'): ?>
        <h2>🚨 ข้อมูลรถต้องสงสัย (Suspect Vehicles)</h2>
        <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
            <a href="export_suspect.php" class="btn-export">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            </a>
        </div>

        <?php if ($role === 'ADMIN'): ?>
            <div class="form-box">
                <form method="POST" action="manage_suspect.php">
                    <div class="form-row">
                        <div class="form-group">
                            <label>ป้ายทะเบียนรถ</label>
                            <input type="text" name="license_plate" required placeholder="เช่น กท 1234">
                        </div>
                        <div class="form-group">
                            <label>ประเภทยานพาหนะ</label>
                            <select name="vehicle_type" required>
                                <option value="รถยนต์ (Car)">รถยนต์ (Car)</option>
                                <option value="รถกระบะ (Pickup)">รถกระบะ (Pickup)</option>
                                <option value="รถจักรยานยนต์ (Motorcycle)">รถจักรยานยนต์ (Motorcycle)</option>
                                <option value="รถบรรทุก (Truck)">รถบรรทุก (Truck)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>สีรถ (ถ้าทราบ)</label>
                            <input type="text" name="color" placeholder="เช่น ดำ, ขาว, แดง">
                        </div>
                        <div class="form-group">
                            <label>สถานะเบาะแส</label>
                            <select name="suspect_type" required>
                                <option value="STOLEN">รถถูกขโมย (Stolen)</option>
                                <option value="REPORTED">รถที่ได้รับแจ้งเบาะแส (Reported)</option>
                            </select>
                        </div>
                    </div>
                    <!-- ✨ เพิ่ม Dropdown ให้เลือกว่ารถคันนี้มาจากคดีไหน -->
                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>เชื่อมโยงกับแฟ้มคดี (คดีต้นทางที่รับแจ้งความ)</label>
                            <select name="case_file_id">
                                <option value="">-- ไม่ระบุ / แจ้งเบาะแสทั่วไป (ไม่มีเลขคดี) --</option>
                                <?php foreach ($all_cases as $c): ?>
                                    <?php $case_label = !empty($c['official_case_no']) ? $c['official_case_no'] . ' - ' . $c['case_name'] : $c['case_name']; ?>
                                    <option value="<?php echo $c['case_file_id']; ?>">
                                        <?php echo htmlspecialchars($case_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="text-align: right; margin-top: 10px;">
                        <button type="submit" name="add_suspect" class="btn-add">➕ บันทึกข้อมูลเป้าหมาย</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div style="background-color: rgba(59, 130, 246, 0.1); padding: 15px; border-radius: 8px; border: 1px solid rgba(59, 130, 246, 0.2); margin-bottom: 30px; color: #93c5fd; font-size: 14px;">
                ℹ️ <b>โหมดการดูข้อมูล (View Only):</b> คุณสามารถตรวจสอบและดาวน์โหลดรายชื่อเป้าหมายได้ สิทธิ์ในการเพิ่ม/แก้ไข/ลบ จะถูกจำกัดไว้สำหรับผู้ดูแลระบบเท่านั้น
            </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>รหัส</th><th>ป้ายทะเบียน</th><th>ประเภท/สี</th><th>แจ้งหายจากคดี</th><th>สถานะ</th><th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($suspects) > 0): ?>
                    <?php foreach ($suspects as $row): ?>
                        <tr>
                            <td style="color: #64748b;">#<?php echo htmlspecialchars($row['suspect_id']); ?></td>
                            <td><span class="plate-text"><?php echo htmlspecialchars($row['license_plate']); ?></span></td>
                            <td>
                                <?php echo htmlspecialchars($row['vehicle_type']); ?><br>
                                <span style="font-size: 12px; color: #94a3b8;">สี: <?php echo htmlspecialchars($row['color'] ? $row['color'] : '-'); ?></span>
                            </td>
                            <!-- ✨ โชว์เลขคดีต้นทาง -->
                            <td style="color: #cbd5e1; font-size: 13px;">
                                <?php 
                                    if(!empty($row['official_case_no'])) {
                                        echo "<span style='color:#3b82f6; font-weight:600;'>".htmlspecialchars($row['official_case_no'])."</span>";
                                    } elseif(!empty($row['case_name'])) {
                                        echo htmlspecialchars($row['case_name']);
                                    } else {
                                        echo "<span style='color:#64748b;'>ไม่ระบุคดี</span>";
                                    }
                                ?>
                            </td>
                            <td>
                                <?php if ($row['suspect_type'] == 'STOLEN'): ?>
                                    <span class="badge bg-danger">ถูกขโมย</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">แจ้งเบาะแส</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($role === 'ADMIN'): ?>
                                    <button class="btn-edit" onclick="openEditModal(<?php echo $row['suspect_id']; ?>, '<?php echo htmlspecialchars($row['license_plate']); ?>', '<?php echo htmlspecialchars($row['vehicle_type']); ?>', '<?php echo htmlspecialchars($row['color']); ?>', '<?php echo htmlspecialchars($row['suspect_type']); ?>', '<?php echo $row['case_file_id']; ?>')">แก้ไข</button>
                                    <button class="btn-delete" onclick="confirmDelete(<?php echo $row['suspect_id']; ?>)">ลบ</button>
                                <?php else: ?>
                                    <span style="color: #64748b; font-size: 13px;">🔒 ไม่อนุญาต</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 30px;">ไม่มีข้อมูลรถต้องสงสัยในฐานข้อมูล</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($role === 'LEADER' || $role === 'USER'): ?>
        <h2 style="<?php echo ($role === 'USER') ? 'margin-top: 50px;' : ''; ?>">📸 รถต้องสงสัยที่ตรวจพบโดย AI</h2>
        
        <div class="result-grid">
            <?php if (count($detected_results) > 0): ?>
                <?php foreach ($detected_results as $car): ?>
                    <div class="card <?php echo !empty($car['suspect_type']) ? 'alert-card' : ''; ?>">
                        <img src="<?php echo htmlspecialchars($car['image_path']); ?>">
                        <div class="card-body">
                            <?php if (!empty($car['suspect_type'])): ?>
                                <div class="alert-text" style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">
                                    <span>🚨 ตรวจพบเป้าหมาย! (<?php echo $car['suspect_type'] == 'STOLEN' ? 'รถขโมย' : 'แจ้งเบาะแส'; ?>)</span>
                                    <!-- ✨ โชว์ว่ารถที่เจอ หายมาจากคดีไหน ✨ -->
                                    <span style="font-size: 11px; color: #f8fafc; font-weight: 500;">
                                        📌 จากคดี: <?php echo !empty($car['suspect_official_no']) ? htmlspecialchars($car['suspect_official_no']) : (!empty($car['suspect_case_name']) ? htmlspecialchars($car['suspect_case_name']) : 'ไม่ระบุคดีต้นทาง'); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <div>
                                <div class="plate-badge-card"><?php echo htmlspecialchars($car['detected_license_plate']); ?></div>
                                
                                <?php if ($car['is_verified'] == 1): ?>
                                    <span style="font-size: 11px; padding: 3px 8px; border-radius: 4px; background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); vertical-align: super; margin-left: 5px;">✅ ยืนยันแล้ว</span>
                                <?php elseif ($car['is_verified'] == 2): ?>
                                    <span style="font-size: 11px; padding: 3px 8px; border-radius: 4px; background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); vertical-align: super; margin-left: 5px;">📤 ส่งตรวจแล้ว</span>
                                <?php endif; ?>
                            </div>

                            <p style="font-size:13px; margin: 4px 0; color:#94a3b8;"><b>ประเภท:</b> <?php echo htmlspecialchars($car['detected_vehicle_type']); ?></p>
                            <p style="font-size:13px; margin: 4px 0; color:#94a3b8;"><b>สีรถ:</b> <?php echo htmlspecialchars($car['detected_color']); ?></p>
                            <p style="font-size:13px; margin: 4px 0; color:#94a3b8;"><b>พบในวิดีโอของคดี:</b> <span style="color:#cbd5e1; font-weight:500;"><?php echo !empty($car['official_case_no']) ? htmlspecialchars($car['official_case_no']) : htmlspecialchars($car['case_name']); ?></span></p>
                            
                            <?php 
                                $fps = (!empty($car['fps']) && $car['fps'] > 0) ? $car['fps'] : 30;
                                $seconds = floor(($car['frame_number'] ?? 0) / $fps); 
                                $time_display = gmdate("i:s", $seconds); 
                            ?>
                            <div style="background:#1e293b; color:#e2e8f0; padding:6px; text-align:center; border-radius:6px; font-size:12px; margin:10px 0;">⏱️ พบที่นาที: <?php echo $time_display; ?></div>
                            <a href="case_detail.php?id=<?php echo $car['case_file_id']; ?>" class="btn-case">📂 เข้าดูแฟ้มคดีนี้</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1; text-align: center; color: #94a3b8; padding: 30px; background: #0f172a; border-radius: 8px; border: 1px solid #334155;">
                    ยังไม่มีข้อมูลรถต้องสงสัยที่ตรวจพบโดย AI หรือยังไม่มีการยืนยันสถานะ
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 15px; margin-top: 30px;">
            <a href="?page=<?php echo $page - 1; ?>" <?php echo ($page <= 1) ? 'style="pointer-events: none; opacity: 0.5; padding: 8px 16px; border: 1px solid #475569; color: #94a3b8; border-radius: 6px; text-decoration: none; background: transparent;"' : 'style="padding: 8px 16px; border: 1px solid #3b82f6; color: #3b82f6; border-radius: 6px; text-decoration: none; background: transparent; transition: all 0.2s ease;" onmouseover="this.style.background=\'#3b82f6\'; this.style.color=\'#fff\'" onmouseout="this.style.background=\'transparent\'; this.style.color=\'#3b82f6\'"'; ?>>« ก่อนหน้า</a>
            <span style="color: #cbd5e1; font-size: 15px;">หน้า <b style="color: #f8fafc;"><?php echo $page; ?></b> จาก <?php echo $total_pages; ?></span>
            <a href="?page=<?php echo $page + 1; ?>" <?php echo ($page >= $total_pages) ? 'style="pointer-events: none; opacity: 0.5; padding: 8px 16px; border: 1px solid #475569; color: #94a3b8; border-radius: 6px; text-decoration: none; background: transparent;"' : 'style="padding: 8px 16px; border: 1px solid #3b82f6; color: #3b82f6; border-radius: 6px; text-decoration: none; background: transparent; transition: all 0.2s ease;" onmouseover="this.style.background=\'#3b82f6\'; this.style.color=\'#fff\'" onmouseout="this.style.background=\'transparent\'; this.style.color=\'#3b82f6\'"'; ?>>ถัดไป »</a>
        </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<!-- Modal สำหรับแก้ไขข้อมูล -->
<div id="editModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="color: #f8fafc; margin-top: 0; border-bottom: 1px solid #334155; padding-bottom: 15px;">✏️ แก้ไขข้อมูลเป้าหมาย</h3>
        <form method="POST" action="manage_suspect.php">
            <input type="hidden" name="suspect_id" id="edit_suspect_id">
            <div class="form-row">
                <div class="form-group">
                    <label>ป้ายทะเบียนรถ</label>
                    <input type="text" name="license_plate" id="edit_license_plate" required>
                </div>
                <div class="form-group">
                    <label>ประเภทยานพาหนะ</label>
                    <select name="vehicle_type" id="edit_vehicle_type" required>
                        <option value="รถยนต์ (Car)">รถยนต์ (Car)</option>
                        <option value="รถกระบะ (Pickup)">รถกระบะ (Pickup)</option>
                        <option value="รถจักรยานยนต์ (Motorcycle)">รถจักรยานยนต์ (Motorcycle)</option>
                        <option value="รถบรรทุก (Truck)">รถบรรทุก (Truck)</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>สีรถ (ถ้าทราบ)</label>
                    <input type="text" name="color" id="edit_color">
                </div>
                <div class="form-group">
                    <label>สถานะเบาะแส</label>
                    <select name="suspect_type" id="edit_suspect_type" required>
                        <option value="STOLEN">รถถูกขโมย (Stolen)</option>
                        <option value="REPORTED">รถที่ได้รับแจ้งเบาะแส (Reported)</option>
                    </select>
                </div>
            </div>
            <!-- ✨ เพิ่ม Dropdown คดีในหน้าแก้ไข -->
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label>เชื่อมโยงกับแฟ้มคดี (คดีต้นทางที่รับแจ้งความ)</label>
                    <select name="case_file_id" id="edit_case_file_id">
                        <option value="">-- ไม่ระบุ / แจ้งเบาะแสทั่วไป (ไม่มีเลขคดี) --</option>
                        <?php foreach ($all_cases as $c): ?>
                            <?php $case_label = !empty($c['official_case_no']) ? $c['official_case_no'] . ' - ' . $c['case_name'] : $c['case_name']; ?>
                            <option value="<?php echo $c['case_file_id']; ?>">
                                <?php echo htmlspecialchars($case_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="text-align: right; margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-delete" style="color: #cbd5e1; border-color: #475569;" onclick="closeEditModal()">ยกเลิก</button>
                <button type="submit" name="edit_suspect" class="btn-add" style="background-color: #3b82f6;">💾 บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
    <?php if(!empty($alert_script)) echo $alert_script; ?>

    function openEditModal(id, plate, type, color, status, case_id) {
        document.getElementById('edit_suspect_id').value = id;
        document.getElementById('edit_license_plate').value = plate;
        document.getElementById('edit_vehicle_type').value = type;
        document.getElementById('edit_color').value = color;
        document.getElementById('edit_suspect_type').value = status;
        document.getElementById('edit_case_file_id').value = case_id; // เซ็ตค่าคดีที่เชื่อมไว้
        document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function confirmDelete(id) {
        Swal.fire({
            title: 'ยืนยันการลบข้อมูล?', text: "หากลบแล้ว จะไม่สามารถกู้คืนข้อมูลเป้าหมายนี้ได้!",
            icon: 'warning', showCancelButton: true, background: '#1e293b', color: '#f8fafc',
            confirmButtonColor: '#ef4444', cancelButtonColor: '#475569',
            confirmButtonText: 'ใช่, ลบข้อมูลเลย!', cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) window.location.href = 'manage_suspect.php?delete_id=' + id;
        });
    }
</script>

</body>
</html>