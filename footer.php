<style>
    /* 1. บังคับให้ body มีความสูงเต็มจอ และใช้ Flexbox จัดการ Layout */
    body {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        padding-bottom: 0 !important; 
    }

    /* 🌟 2. แก้บั๊กกล่องหดตัว (บังคับให้ Container กางเต็มพื้นที่) */
    .container {
        width: 100%;
        box-sizing: border-box;
    }

    /* 3. สไตล์ Enterprise Footer */
    .enterprise-footer {
        background-color: #0f172a; /* สีกลืนไปกับพื้นหลังหลัก */
        border-top: 1px solid rgba(255, 255, 255, 0.05); /* เส้นกั้นบางๆ */
        padding: 25px 20px;
        text-align: center;
        font-family: 'Prompt', sans-serif;
        color: #64748b; 
        font-size: 13px;
        width: 100%;
        
        /* margin-top: auto จะดัน Footer ลงไปขอบล่างสุดเสมอ */
        margin-top: auto; 
        
        box-sizing: border-box;
        position: relative;
        z-index: 10; 
    }
    
    .enterprise-footer p {
        margin: 0;
        letter-spacing: 0.5px;
    }
    .enterprise-footer .highlight {
        color: #3b82f6; /* เน้นชื่อมหาลัยด้วยสีฟ้า Primary */
        font-weight: 500;
    }
</style>

<footer class="enterprise-footer">
    <p>&copy; <?php echo date('Y'); ?> AI Vehicle Detection System. All rights reserved.</p>
    <p style="margin-top: 6px;">Developed by <span class="highlight">COM-SCI Suan Dusit University</span></p>
</footer>