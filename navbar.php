<?php
// 1. เช็ก Session และเชื่อมต่อฐานข้อมูล
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// ✨ 2. ระบบอัปเดตเวลาใช้งานล่าสุด (เปิดสวิตช์ Online) ✨
if (isset($_SESSION['user_id'])) {
    try {
        $update_time = $conn->prepare("UPDATE users SET last_seen = NOW(), is_online = 1 WHERE user_id = ?");
        $update_time->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {
        // ดัก Error ไว้เงียบๆ
    }
}
?>

<style>
    /* CSS สำหรับ Navbar ธีมคลีน/ทางการ (Dark Minimal) */
    .navbar {
        background-color: #1e293b;
        border-bottom: 1px solid #334155;
        padding: 0 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        height: 65px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        position: sticky;
        top: 0;
        z-index: 1000;
        font-family: 'Prompt', sans-serif;
    }
    
    .nav-left-section {
        display: flex;
        align-items: center;
        gap: 10px; /* ลดระยะห่างให้พอดี */
    }

    /* ✨ ปุ่มย้อนกลับแบบไอคอนซ้ายสุด ✨ */
    .btn-nav-back {
        color: #cbd5e1;
        background-color: transparent;
        border: none;
        padding: 8px;
        border-radius: 50%;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: 0.2s;
        cursor: pointer;
    }
    .btn-nav-back:hover {
        background-color: rgba(255, 255, 255, 0.1);
        color: #f8fafc;
    }
    .btn-nav-back svg {
        width: 22px;
        height: 22px;
    }

    .nav-brand {
        color: #f8fafc;
        font-size: 18px;
        font-weight: 600;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .nav-links {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    .nav-link {
        color: #cbd5e1;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: color 0.2s, background-color 0.2s;
        padding: 8px 14px;
        border-radius: 6px;
    }
    .nav-link:hover {
        color: #3b82f6;
        background-color: rgba(59, 130, 246, 0.1);
    }
    .nav-user {
        display: flex;
        align-items: center;
        gap: 15px;
        border-left: 1px solid #334155;
        padding-left: 20px;
    }
    
    .user-profile-link {
        text-decoration: none;
        text-align: right;
        line-height: 1.2;
        padding: 6px 12px;
        border-radius: 6px;
        transition: background-color 0.2s;
    }
    .user-profile-link:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    .user-name {
        color: #f8fafc;
        font-size: 14px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .role-badge-nav {
        color: #94a3b8;
        font-size: 11px;
        font-weight: 600;
        margin-top: 2px;
    }
    .btn-logout {
        background-color: rgba(239, 68, 68, 0.1);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.2);
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        transition: 0.2s;
    }
    .btn-logout:hover {
        background-color: #ef4444;
        color: white;
    }
</style>

<nav class="navbar">
    <div class="nav-left-section">
        <a href="javascript:history.back()" class="btn-nav-back" title="ย้อนกลับ">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
        </a>
        <a href="index.php" class="nav-brand">
            🛡️ Car Finder
        </a>
    </div>


    
    <div class="nav-links">
        <a href="index.php" class="nav-link">หน้าแรก (Dashboard)</a>
        <a href="search.php" class="nav-link">ค้นหาหลักฐาน</a>
        <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'LEADER')): ?>
            <a href="assign_case.php" class="nav-link">มอบหมายงาน</a> 
        <?php endif; ?>
        
        <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'ADMIN' || $_SESSION['role'] === 'LEADER')): ?>
            <a href="manage_suspect.php" class="nav-link">จัดการรถเป้าหมาย</a>
            <a href="statistics.php" class="nav-link">สถิติระบบ</a>
        <?php endif; ?>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN'): ?>
            <a href="user_list.php" class="nav-link">รายชื่อเจ้าหน้าที่</a>
        <?php endif; ?>
    </div>

    <div class="nav-user">
        <?php if(isset($_SESSION['full_name'])): ?>
            <a href="profile.php" class="user-profile-link" title="จัดการข้อมูลส่วนตัว">
                <div class="user-name">👤 <?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="role-badge-nav">STATUS: <?php echo htmlspecialchars($_SESSION['role']); ?></div>
            </a>
        <?php endif; ?>
        <a href="logout.php" class="btn-logout">ออกจากระบบ</a>
    </div>
</nav>