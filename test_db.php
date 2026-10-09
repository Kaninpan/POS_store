<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = db();
    echo "เชื่อมต่อฐานข้อมูลสำเร็จ";
    echo "<br>Database: " . htmlspecialchars(DB_NAME);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>" .
        htmlspecialchars($e->getMessage()) .
        "</pre>";
}