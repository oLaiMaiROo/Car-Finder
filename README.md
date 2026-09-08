#  Car-Finder: Vehicle Detection (YOLOv8 vs Faster R-CNN)

##  About The Project
This project focuses on real-time vehicle detection and classification from CCTV footage (Car, Motorcycle, Pickup, Taxi, Truck). It includes a comprehensive performance comparison between two leading object detection models: **YOLOv8** and **Faster R-CNN**, integrated with a web-based management system.

##  Tech Stack
* **AI/Computer Vision:** Python, PyTorch, YOLOv8, Faster R-CNN, OpenCV
* **Web & Database:** PHP, MySQL
* **Tools:** Git, GitHub

##  Model Comparison Highlights
Based on our evaluation using Confusion Matrices and mAP scores:
* **YOLOv8 (Anchor-Free):** Achieved higher mAP (65.50%) and real-time processing speed. It provided cleaner bounding boxes and significantly fewer False Negatives (missed vehicles).
* **Faster R-CNN (Anchor-Based):** Achieved lower mAP (46.84%) and struggled with overlapping bounding boxes on background objects (e.g., advertising boards).

> **Conclusion:** While YOLOv8 showed some False Positives on backgrounds, its high recall and cleaner detection make it the superior choice for this CCTV monitoring system.

##  Results & Visualizations
*(เดี๋ยวเราค่อยมาอัปโหลดรูปกราฟ Confusion Matrix และรูปรถสวยๆ มาแปะตรงนี้ทีหลังครับ)*

---
*Developed as a 4rd-year university project.*