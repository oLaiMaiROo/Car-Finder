import cv2
import mysql.connector
from datetime import datetime
from ultralytics import YOLO
import os
import numpy as np
import sys
import re
import easyocr # ✨ นำเข้าไลบรารีอ่านป้ายทะเบียน

# 1. เชื่อมต่อฐานข้อมูล
def connect_db():
    try:
        return mysql.connector.connect(
            host="localhost",
            user="root",
            password="",
            database="my_project_db"
        )
    except Exception as e:
        print(f" Database Connection Error: {e}")
        sys.exit(1)

# 2. ฟังก์ชันวิเคราะห์สีด้วย OpenCV
def detect_vehicle_color(frame, box):
    try:
        x1, y1, x2, y2 = map(int, box.xyxy[0])
        cropped_img = frame[y1:y2, x1:x2]
        if cropped_img.size == 0: return "ไม่ระบุ (Unknown)"
        h, w = cropped_img.shape[:2]
        
        center_crop = cropped_img[int(h*0.3):int(h*0.8), int(w*0.3):int(w*0.7)]
        if center_crop.size == 0: center_crop = cropped_img 
        
        hsv = cv2.cvtColor(center_crop, cv2.COLOR_BGR2HSV)
        
        color_ranges = {
            "ขาว (White)": [(0, 0, 180), (180, 50, 255)],
            "ดำ (Black)": [(0, 0, 0), (180, 255, 50)],
            "เทา/เงิน (Grey)": [(0, 0, 50), (180, 50, 180)],
            "แดง (Red)": [(0, 100, 50), (10, 255, 255)], 
            "แดง (Red)_2": [(160, 100, 50), (180, 255, 255)],
            "ส้ม (Orange)": [(11, 100, 50), (25, 255, 255)],
            "เหลือง (Yellow)": [(26, 100, 50), (35, 255, 255)],
            "เขียว (Green)": [(36, 50, 50), (85, 255, 255)],
            "น้ำเงิน (Blue)": [(86, 50, 50), (125, 255, 255)],
            "ชมพู/ม่วง (Pink)": [(126, 50, 50), (159, 255, 255)]
        }
        
        max_pixels = 0
        dominant_color = "สีอื่นๆ (Other)"
        
        for color_name, (lower, upper) in color_ranges.items():
            lower_np = np.array(lower, dtype=np.uint8)
            upper_np = np.array(upper, dtype=np.uint8)
            mask = cv2.inRange(hsv, lower_np, upper_np)
            pixel_count = cv2.countNonZero(mask)
            
            if pixel_count > max_pixels:
                max_pixels = pixel_count
                dominant_color = color_name.replace("_2", "")
                
        if max_pixels == 0: return "ไม่ระบุ (Unknown)"
        return dominant_color
    except: 
        return "ไม่ระบุ (Unknown)"

#  3. ฟังก์ชันอ่านป้ายทะเบียนด้วย EasyOCR
def detect_license_plate(frame, box, reader):
    try:
        x1, y1, x2, y2 = map(int, box.xyxy[0])
        vehicle_crop = frame[y1:y2, x1:x2]
        if vehicle_crop.size == 0: return "-"
        
        # ตัดเอาเฉพาะครึ่งล่างของตัวรถ (เพราะป้ายทะเบียนมักอยู่ด้านล่าง) เพื่อให้ OCR ทำงานเร็วและแม่นขึ้น
        h, w = vehicle_crop.shape[:2]
        lower_half = vehicle_crop[int(h*0.4):h, :]
        
        # สั่งให้ EasyOCR อ่านข้อความภาษาไทยและอังกฤษ
        results = reader.readtext(lower_half, detail=0)
        
        plate_text = ""
        for text in results:
            # กรองเอาเฉพาะตัวอักษรไทย อังกฤษ และตัวเลข (ตัดพวกอักขระพิเศษขยะออก)
            clean_text = re.sub(r'[^ก-๙a-zA-Z0-9]', '', text)
            if len(clean_text) >= 2: # เอาเฉพาะคำที่ยาว 2 ตัวอักษรขึ้นไป
                plate_text += clean_text + " "
                
        plate_text = plate_text.strip()
        return plate_text if plate_text != "" else "-"
    except Exception as e:
        return "-"

print("🔄 กำลังโหลดโมเดล YOLO AI...")
model = YOLO('best.pt') 

print(" กำลังโหลดโมเดล OCR อ่านป้ายทะเบียน...")
# ✨ โหลดโมเดลอ่านตัวอักษรภาษาไทยและอังกฤษ (ให้ใช้การ์ดจอทำงานถ้ามี)
ocr_reader = easyocr.Reader(['th', 'en'], gpu=True)

def process_video(video_id, video_path, ai_mode='full'):
    db = connect_db()
    cursor = db.cursor()
    
    cap = cv2.VideoCapture(video_path)
    if not cap.isOpened():
        print(f" ไม่สามารถเปิดวิดีโอได้: {video_path}")
        return

    try:
        fps = cap.get(cv2.CAP_PROP_FPS)
        if fps <= 0 or np.isnan(fps): fps = 30.0
        original_width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
        original_height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
        
        cursor.execute("UPDATE videos SET fps = %s WHERE video_id = %s", (float(fps), video_id))
        db.commit()
    except Exception as e:
        fps = 30.0
        original_width, original_height = 1280, 720 

    max_width = 1280 
    if original_width > max_width:
        ratio = max_width / original_width
        target_width = max_width
        target_height = int(original_height * ratio)
    else:
        target_width = original_width
        target_height = original_height

    out = None
    annotated_path = video_path.rsplit('.', 1)[0] + '_annotated.mp4'
    if ai_mode == 'full':
        fourcc = cv2.VideoWriter_fourcc(*'avc1') 
        out = cv2.VideoWriter(annotated_path, fourcc, fps, (target_width, target_height))

    frame_count = 0
    if not os.path.exists('uploads/captures'):
        os.makedirs('uploads/captures')

    thai_classes = {0: "รถยนต์", 1: "รถจักรยานยนต์", 2: "รถกระบะ", 3: "รถแท็กซี่", 4: "รถบรรทุก"}
    eng_classes = {0: "Car", 1: "Motorcycle", 2: "Pickup", 3: "Taxi", 4: "Truck"}

    while cap.isOpened():
        success, frame = cap.read()
        if not success: break
        
        if frame.shape[1] > max_width:
            frame = cv2.resize(frame, (target_width, target_height))
        
        frame_count += 1
        
        is_save_frame = (frame_count % 30 == 0)

        if ai_mode == 'quick' and not is_save_frame:
            continue

        results = model(frame, verbose=False, device=0)

        for result in results:
            for box in result.boxes:
                cls = int(box.cls[0])
                conf = float(box.conf[0]) * 100 
                
                if cls in [0, 1, 2, 3, 4] and conf > 50:
                    
                    vehicle_type = thai_classes.get(cls, "ไม่ทราบประเภท")
                    en_type = eng_classes.get(cls, "Unknown")
                    
                    detected_color = detect_vehicle_color(frame, box)
                    
                    x1, y1, x2, y2 = map(int, box.xyxy[0])
                    cv2.rectangle(frame, (x1, y1), (x2, y2), (0, 255, 0), 2)
                    
                    en_color = detected_color.split('(')[-1].replace(')', '') if '(' in detected_color else detected_color
                    label = f"{en_type} | {en_color} | {conf:.1f}%"
                    
                    (tw, th), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.5, 1)
                    y_label_bg = max(0, y1 - 20)
                    y_label_text = max(15, y1 - 6)
                    cv2.rectangle(frame, (x1, y_label_bg), (x1 + tw + 5, y_label_bg + 20), (0, 255, 0), -1)
                    cv2.putText(frame, label, (x1 + 2, y_label_text), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 0, 0), 1, cv2.LINE_AA)
                    
                    # ✨ เซฟรูปลง DB พร้อมสั่งให้อ่านป้ายทะเบียน (ทำเฉพาะเฟรมนี้เท่านั้น เพื่อไม่ให้เครื่องค้าง)
                    if is_save_frame:
                        # สั่ง OCR อ่านป้าย
                        detected_plate = detect_license_plate(frame, box, ocr_reader)
                        
                        timestamp = datetime.now().strftime("%Y%m%d%H%M%S")
                        img_name = f"uploads/captures/v{video_id}_f{frame_count}_{timestamp}.jpg"
                        
                        cv2.imwrite(img_name, frame)
                        
                        cursor.execute("INSERT INTO images (video_id, frame_number, image_path) VALUES (%s, %s, %s)", 
                                      (video_id, frame_count, img_name))
                        img_id = cursor.lastrowid
                        
                        # นำทะเบียนรถ (detected_plate) บันทึกลงฐานข้อมูลแทนเครื่องหมายขีด (-)
                        cursor.execute("INSERT INTO detections (image_id, detected_license_plate, detected_vehicle_type, detected_color, confidence_score) VALUES (%s, %s, %s, %s, %s)",
                                      (img_id, detected_plate, vehicle_type, detected_color, conf))
                        db.commit()
                        print(f" ตรวจพบ {vehicle_type} สี {detected_color} ทะเบียน [{detected_plate}] ที่เฟรม {frame_count}")
        
        if ai_mode == 'full' and out is not None:
            out.write(frame)

    cap.release()
    
    if ai_mode == 'full' and out is not None:
        out.release()
        cursor.execute("UPDATE videos SET video_path = %s WHERE video_id = %s", (annotated_path, video_id))
        db.commit()
        print(f" ประมวลผลเสร็จสิ้น! สร้างวิดีโอ AI สำเร็จ: {annotated_path}")
    else:
        print(" สแกนด่วน (Quick Mode) เสร็จสิ้น! ข้อมูลถูกบันทึกลงระบบแล้ว")

    cursor.close()
    db.close()

if __name__ == "__main__":
    if len(sys.argv) > 2:
        vid_id = sys.argv[1]
        v_path = sys.argv[2]
        mode = sys.argv[3] if len(sys.argv) > 3 else 'full'
        process_video(vid_id, v_path, mode)
    else:
        print(" ข้อมูลไม่ครบ")