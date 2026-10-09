<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Bangkok');

const DB_HOST = '127.0.0.1';
const DB_NAME = 'shop_pos';
const DB_USER = 'root';
const DB_PASS = ''; // ถ้าตั้งรหัสผ่าน MariaDB ให้ใส่ตรงนี้

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    return $pdo;
}