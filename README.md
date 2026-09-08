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
<img width="2400" height="1800" alt="final_model_comparison" src="https://github.com/user-attachments/assets/192cbcb3-c582-4653-a41b-50e617d5847c" />
<img width="2080" height="1635" alt="model_comparison" src="https://github.com/user-attachments/assets/dab733e5-b166-4125-a08a-581a66ced6c7" />
<img width="2354" height="2100" alt="faster_rcnn_confusion_matrix" src="https://github.com/user-attachments/assets/15a62ebd-ddc2-4802-a0a0-7692010bd9c6" />
<img width="3000" height="2250" alt="confusion_matrix_normalized" src="https://github.com/user-attachments/assets/97700a6e-66dc-4bf2-967f-a6aac9b01891" />


---
*Developed as a 4rd-year university project.*
