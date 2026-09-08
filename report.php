<?php
session_start();
require 'db.php';

// ป้องกันคนไม่มีสิทธิ์แอบเข้ามาปริ้นรายงาน
if (!isset($_SESSION['user_id'])) {
    die("Access Denied: คุณไม่มีสิทธิ์ออกรายงาน");
}

// 📦 ดึงไลบรารี mPDF มาใช้งาน
require_once __DIR__ . '/vendor/autoload.php';

// รับค่าจากฟอร์ม
$case_id = $_POST['case_id'] ?? '';
$remark = $_POST['report_remark'] ?? '';
$selected_ids = $_POST['selected_ids'] ?? [];

if (empty($case_id)) {
    die("Error: ไม่พบรหัสคดี");
}

// 1. ดึงข้อมูลคดีและคนออกรายงาน
$stmt_case = $conn->prepare("SELECT * FROM case_file WHERE case_file_id = ?");
$stmt_case->execute([$case_id]);
$case_info = $stmt_case->fetch();

// 2. สร้าง SQL Query ตามเงื่อนไข 
$params = [];
if (!empty($selected_ids)) {
    $inQuery = implode(',', array_fill(0, count($selected_ids), '?'));
    $sql = "SELECT d.*, i.image_path, i.frame_number, v.video_id, v.video_path, v.fps, sv.suspect_type 
            FROM detections d 
            JOIN images i ON d.image_id = i.image_id 
            JOIN videos v ON i.video_id = v.video_id 
            LEFT JOIN suspect_vehicle sv ON d.detected_license_plate = sv.license_plate
            WHERE d.detection_id IN ($inQuery)
            ORDER BY d.detected_at DESC";
    $params = $selected_ids;
} else {
    $search_plate  = $_POST['plate'] ?? '';
    $search_type   = $_POST['type'] ?? '';
    $search_color  = $_POST['color'] ?? '';
    $search_date   = $_POST['date'] ?? '';
    $search_video  = $_POST['video_id'] ?? '';
    $search_second = $_POST['second'] ?? '';

    $sql = "SELECT d.*, i.image_path, i.frame_number, v.video_id, v.video_path, v.fps, sv.suspect_type 
            FROM detections d 
            JOIN images i ON d.image_id = i.image_id 
            JOIN videos v ON i.video_id = v.video_id 
            LEFT JOIN suspect_vehicle sv ON d.detected_license_plate = sv.license_plate
            WHERE v.case_file_id = ?";
    $params[] = $case_id;

    if ($search_plate !== '') { $sql .= " AND d.detected_license_plate LIKE ?"; $params[] = "%$search_plate%"; }
    if ($search_type !== '') { $sql .= " AND d.detected_vehicle_type LIKE ?"; $params[] = "%$search_type%"; }
    if ($search_color !== '') { $sql .= " AND d.detected_color LIKE ?"; $params[] = "%$search_color%"; }
    if ($search_date !== '') { $sql .= " AND DATE(d.detected_at) = ?"; $params[] = $search_date; }
    if ($search_video !== '') { $sql .= " AND v.video_id = ?"; $params[] = $search_video; }
    if ($search_second !== '') { $sql .= " AND FLOOR(i.frame_number / COALESCE(NULLIF(v.fps, 0), 30)) = ?"; $params[] = $search_second; }
    
    $sql .= " ORDER BY d.detected_at DESC";
}

$stmt_ai = $conn->prepare($sql);
$stmt_ai->execute($params);
$detections = $stmt_ai->fetchAll();

// 3. เริ่มจัดหน้ากระดาษ PDF HTML
$html = '
<style>
    body { font-family: "garuda", sans-serif; color: #333; } 
    h2 { text-align: center; color: #1e293b; margin-bottom: 5px; font-size: 22px; }
    .header-sub { text-align: center; font-size: 14px; color: #64748b; margin-top: 0; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;}
    .case-info { background-color: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #cbd5e1; font-size: 14px; line-height: 1.6;}
    .remark-box { background-color: #fffbeb; padding: 15px; border-left: 5px solid #f59e0b; margin-bottom: 20px; font-size: 14px; line-height: 1.6;}
    table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px; }
    th, td { border: 1px solid #cbd5e1; padding: 12px 8px; text-align: left; vertical-align: middle; }
    th { background-color: #e2e8f0; font-weight: bold; text-align: center; font-size: 13px;}
    .img-box { width: 120px; text-align: center; }
    .img-box img { max-width: 120px; max-height: 80px; border-radius: 4px; border: 1px solid #94a3b8;}
    .alert-text { color: #ef4444; font-weight: bold; }
    .plate-text { font-size: 16px; font-weight: bold; color: #0f172a; }
</style>
';

$html .= '<h2>รายงานผลการวิเคราะห์ยานพาหนะ (AI Evidence Report)</h2>';
$html .= '<div class="header-sub">ออกรายงานโดย: ' . htmlspecialchars($_SESSION['full_name']) . ' | วันที่พิมพ์: ' . date('d/m/Y H:i') . '</div>';

$official_case_text = !empty($case_info['official_case_no']) ? htmlspecialchars($case_info['official_case_no']) : '<span style="color:#94a3b8;">(รอระบุเลขคดี)</span>';
$reporter_text = !empty($case_info['reporter_name']) ? htmlspecialchars($case_info['reporter_name']) : '-';
$phone_text = !empty($case_info['reporter_phone']) ? htmlspecialchars($case_info['reporter_phone']) : '-';
$incident_date_text = !empty($case_info['incident_date']) ? date('d/m/Y', strtotime($case_info['incident_date'])) : '-';
$province_text = !empty($case_info['plate_province']) ? htmlspecialchars($case_info['plate_province']) : '-';

$html .= '
<div class="case-info">
    <table style="width: 100%; border: none; font-size: 14px;">
        <tr>
            <td style="border: none; padding: 4px;"><b>เลขที่รับแจ้งความ:</b> ' . $official_case_text . '</td>
            <td style="border: none; padding: 4px;"><b>วันที่เกิดเหตุ:</b> ' . $incident_date_text . '</td>
        </tr>
        <tr>
            <td style="border: none; padding: 4px;"><b>ชื่อผู้แจ้ง:</b> ' . $reporter_text . '</td>
            <td style="border: none; padding: 4px;"><b>เบอร์โทรติดต่อ:</b> ' . $phone_text . '</td>
        </tr>
        <tr>
            <td style="border: none; padding: 4px;"><b>หัวข้อคดี:</b> ' . htmlspecialchars($case_info['case_name']) . '</td>
            <td style="border: none; padding: 4px;"><b>จังหวัด (ป้ายทะเบียน):</b> ' . $province_text . '</td>
        </tr>
    </table>
</div>';

if (!empty($remark)) {
    $html .= '<div class="remark-box"><b style="color: #d97706;">📝 บันทึกความเห็นเจ้าหน้าที่ / รายละเอียดเพิ่มเติม:</b><br>' . nl2br(htmlspecialchars($remark)) . '</div>';
}

$html .= '<table><thead><tr><th width="8%">ลำดับ</th><th width="22%">ภาพหลักฐาน</th><th width="25%">ป้ายทะเบียน / สถานะ</th><th width="25%">ประเภท / สีรถ</th><th width="20%">จุดที่พบในวิดีโอ</th></tr></thead><tbody>';

if (count($detections) > 0) {
    $i = 1;
    foreach ($detections as $ai) {
        $fps = (!empty($ai['fps']) && $ai['fps'] > 0) ? $ai['fps'] : 30;
        $frame_num = !empty($ai['frame_number']) ? $ai['frame_number'] : 0;
        $seconds = floor($frame_num / $fps); 
        $time_display = gmdate("i:s", $seconds);
        $status = $ai['suspect_type'] ? '<br><span class="alert-text">🚨 '.($ai['suspect_type']=='STOLEN'?'รถถูกขโมย':'รถแจ้งเบาะแส').'</span>' : '';
        $img_path = $ai['image_path'];
        $img_tag = (file_exists($img_path)) ? '<img src="'.$img_path.'">' : '<span style="color:#94a3b8;">ไม่พบรูปภาพ</span>';

        $html .= '<tr><td style="text-align:center;">' . $i++ . '</td><td class="img-box">' . $img_tag . '</td><td><span class="plate-text">' . htmlspecialchars($ai['detected_license_plate']) . '</span>' . $status . '</td><td>' . htmlspecialchars($ai['detected_vehicle_type']) . '<br>สี: ' . htmlspecialchars($ai['detected_color']) . '<br>AI มั่นใจ: ' . number_format($ai['confidence_score'], 2) . '%</td><td>นาทีที่ ' . $time_display . '<br>(รหัสวิดีโอ: V-' . $ai['video_id'] . ')</td></tr>';
    }
} else {
    $html .= '<tr><td colspan="5" style="text-align:center; padding: 30px; color:#64748b;">ไม่มีข้อมูลยานพาหนะในรายงานฉบับนี้</td></tr>';
}
$html .= '</tbody></table>';

// 4. สั่ง mPDF ทำงาน
$mpdf = new \Mpdf\Mpdf([
    'default_font' => 'garuda',
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_top' => 20,
    'margin_bottom' => 20,
    'margin_left' => 15,
    'margin_right' => 15
]);

$mpdf->shrink_tables_to_fit = 1;
$mpdf->SetTitle('รายงานคดี_' . $case_info['case_name']);
$mpdf->SetFooter('หน้าที่ {PAGENO} / {nb} | สร้างโดย Car Finder System');
$mpdf->WriteHTML($html);

// ⭐️ ส่วนที่เพิ่มใหม่: บันทึกไฟล์ลง Server และลง Database
$report_dir = 'uploads/reports/';
if (!is_dir($report_dir)) {
    mkdir($report_dir, 0777, true); 
}

$fileName = 'Report_Case_' . $case_id . '_' . time() . '.pdf';
$savePath = $report_dir . $fileName;

$mpdf->Output($savePath, 'F');

try {
    $user_id = $_SESSION['user_id'] ?? 1; 
    
    $stmt_report = $conn->prepare("INSERT INTO report (case_file_id, file_path, generated_by) VALUES (?, ?, ?)");
    $stmt_report->execute([$case_id, $fileName, $user_id]);
    
} catch (PDOException $e) {
    die("<div style='background:#fee2e2; padding:20px; border:2px solid #ef4444; border-radius:8px; margin:20px; font-family:Prompt, sans-serif;'>
            <h3 style='color:#b91c1c; margin-top:0;'>🚨 Database Error: บันทึกข้อมูลไม่สำเร็จ!</h3>
            <code style='display:block; background:#fff; padding:15px; color:#ef4444; border-radius:4px; margin-top:10px; font-size:16px; font-weight:bold;'>" . $e->getMessage() . "</code>
         </div>");
}

$mpdf->Output($fileName, 'I'); 
?>