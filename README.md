# Car-Finder: Smart CCTV Vehicle Detection System

## About The Project
This project is a web-based artificial intelligence system designed to assist security agencies and law enforcement in tracking lost or suspect vehicles. By uploading CCTV footage to the web application, the system utilizes AI to automatically detect and search for vehicles based on specific characteristics (e.g., color, type, and license plate). This significantly reduces manual video inspection time, minimizes human error, and enhances the efficiency of criminal investigations.

*(Note: The web application is powered purely by the **YOLO** model for fast and efficient real-time processing. A CNN/Faster R-CNN model was also trained and evaluated during the research phase strictly for performance comparison purposes.)*

## Key Features
* **Role-based Access Control:** Secure system for Admins, Team Leaders, and General Users.
* **Video/Image Upload:** Upload CCTV evidence for AI processing.
* **Smart Search:** Filter detected vehicles by specific conditions (color, type, license plate, date/time).
* **Timestamp Capture:** Automatically captures and logs timestamps of suspect vehicles.
* **Reporting & History:** View past database records and generate statistical reports.

## Tech Stack
* **AI Model (Deployed on Web):** YOLO (Fast & real-time detection)
* **AI Model (Research & Comparison):** CNN (Faster R-CNN)
* **Web & Database:** PHP, MySQL
* **Tools:** Python, OpenCV, Git, GitHub

## Model Comparison Highlights (Research Phase)
Based on our evaluation using Confusion Matrices and mAP scores:
* **YOLO (Deployed):** Chosen for the web system due to its higher mAP and real-time processing speed. It provided cleaner bounding boxes and significantly fewer False Negatives (missed vehicles).
* **CNN / Faster R-CNN (Research only):** Achieved lower mAP and struggled with overlapping bounding boxes on background objects (e.g., advertising boards).

## Results & Visualizations
<img width="2400" height="1800" alt="final_model_comparison" src="https://github.com/user-attachments/assets/192cbcb3-c582-4653-a41b-50e617d5847c" />
<img width="2080" height="1635" alt="model_comparison" src="https://github.com/user-attachments/assets/dab733e5-b166-4125-a08a-581a66ced6c7" />
<img width="2354" height="2100" alt="faster_rcnn_confusion_matrix" src="https://github.com/user-attachments/assets/15a62ebd-ddc2-4802-a0a0-7692010bd9c6" />
<img width="3000" height="2250" alt="confusion_matrix_normalized" src="https://github.com/user-attachments/assets/97700a6e-66dc-4bf2-967f-a6aac9b01891" />


---
*Developed as a Computer Science academic project at Suan Dusit University.*
