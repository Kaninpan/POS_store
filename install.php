<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec(
        "CREATE DATABASE IF NOT EXISTS shop_pos
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $pdo->exec("USE shop_pos");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT '',
        price_base DECIMAL(12,2) NOT NULL DEFAULT 0,
        price_wholesale DECIMAL(12,2) NOT NULL DEFAULT 0,
        price_retail DECIMAL(12,2) NOT NULL DEFAULT 0,
        price_general DECIMAL(12,2) NOT NULL DEFAULT 0,
        stock INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS stock_movements (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        type ENUM('opening','receive','sale','adjustment','return') NOT NULL,
        quantity INT NOT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        user_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(product_id),
        FOREIGN KEY(product_id) REFERENCES products(id),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS sales (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        receipt_no VARCHAR(40) NOT NULL UNIQUE,
        user_id INT UNSIGNED NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        price_type ENUM('retail','wholesale','general','base') NOT NULL DEFAULT 'retail',
        note VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS sale_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sale_id BIGINT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        product_code VARCHAR(100) NOT NULL,
        product_name VARCHAR(200) NOT NULL,
        quantity INT UNSIGNED NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        line_total DECIMAL(12,2) NOT NULL,
        FOREIGN KEY(sale_id) REFERENCES sales(id),
        FOREIGN KEY(product_id) REFERENCES products(id)
    ) ENGINE=InnoDB;
    ");

    $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO users(username,password_hash,role)
             VALUES(?,?,'admin')"
        );
        $stmt->execute([
            'admin',
            password_hash('1', PASSWORD_DEFAULT)
        ]);
    }

    echo '<meta charset="utf-8">';
    echo '<h1>ติดตั้งฐานข้อมูลสำเร็จ</h1>';
    echo '<p>ฐานข้อมูล: shop_pos</p>';
    echo '<p>บัญชีผู้ดูแล: admin</p>';
    echo '<p>รหัสผ่านเริ่มต้น: 1</p>';
    echo '<p>ขั้นต่อไป เปิด <a href="index.php">ระบบร้านค้า</a></p>';
    echo '<p style="color:red">โปรดเปลี่ยนรหัสผ่านและลบ install.php หลังติดตั้ง</p>';

} catch (Throwable $e) {
    http_response_code(500);
    echo '<meta charset="utf-8"><h2>ติดตั้งไม่สำเร็จ</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}