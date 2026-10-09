<?php
declare(strict_types=1);

/* Shop POS — PHP 8.1+ / MySQL or MariaDB */

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $value): string
{
    return number_format((float)$value, 2);
}

function redirect(string $page = 'dashboard', array $params = []): never
{
    $params = array_merge(['page' => $page], $params);
    header('Location: index.php?' . http_build_query($params));
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function require_csrf(): void
{
    $submitted = (string)($_POST['csrf_token'] ?? '');
    $saved = (string)($_SESSION['csrf_token'] ?? '');

    if ($submitted === '' || !hash_equals($saved, $submitted)) {
        http_response_code(403);
        exit('คำขอไม่ถูกต้อง กรุณากลับไปโหลดหน้าเว็บใหม่');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login');
    }
}

function get_product(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    return $product ?: null;
}

function price_for(array $product, string $type): float
{
    $columns = [
        'base' => 'price_base',
        'wholesale' => 'price_wholesale',
        'retail' => 'price_retail',
        'general' => 'price_general',
    ];

    $column = $columns[$type] ?? 'price_retail';

    return (float)$product[$column];
}

function price_label(string $type): string
{
    return [
        'base' => 'ราคากลาง',
        'wholesale' => 'ราคาขายส่ง',
        'retail' => 'ราคาหน้าร้าน',
        'general' => 'ราคาทั่วไป',
    ][$type] ?? 'ราคาหน้าร้าน';
}

function valid_quantity(mixed $value): int
{
    $quantity = filter_var($value, FILTER_VALIDATE_INT);

    if ($quantity === false || $quantity < 0 || $quantity > 100000000) {
        throw new RuntimeException('จำนวนสินค้าไม่ถูกต้อง');
    }

    return $quantity;
}

function valid_price(mixed $value): float
{
    $price = filter_var($value, FILTER_VALIDATE_FLOAT);

    if ($price === false || !is_finite((float)$price)
        || $price < 0 || $price > 9999999999) {
        throw new RuntimeException('ราคาสินค้าไม่ถูกต้อง');
    }

    return round((float)$price, 2);
}

function receipt_number(): string
{
    return 'INV' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
}

/** สร้างบาร์โค้ด EAN-13 สำหรับสินค้าในร้าน */
function make_ean13(PDO $pdo, string $productCode): string
{
    $skuDigits = preg_replace('/\D+/', '', $productCode) ?? '';
    $skuPart = substr(str_pad($skuDigits, 6, '0', STR_PAD_LEFT), -6);

    // EAN-13 12 หลักข้อมูล: prefix ภายในร้าน 20 + SKU 6 หลัก + เลขสุ่ม 4 หลัก
    // เลขสุ่ม 4 หลักต้องไม่มีตัวเลขซ้ำกันภายในชุด และห้ามซ้ำกับสินค้ารายการอื่น
    for ($attempt = 0; $attempt < 5040; $attempt++) {
        $digits = range(0, 9);
        for ($i = count($digits) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$digits[$i], $digits[$j]] = [$digits[$j], $digits[$i]];
        }
        $randomPart = implode('', array_slice($digits, 0, 4));

        $checkRandom = $pdo->prepare(
            "SELECT id FROM products WHERE SUBSTRING(barcode, 9, 4) = ? LIMIT 1"
        );
        $checkRandom->execute([$randomPart]);
        if ($checkRandom->fetchColumn()) {
            continue;
        }

        $body = '20' . $skuPart . $randomPart;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int)$body[$i];
            $sum += ($i % 2 === 0) ? $digit : ($digit * 3);
        }
        $checkDigit = (10 - ($sum % 10)) % 10;
        $barcode = $body . (string)$checkDigit;

        $check = $pdo->prepare('SELECT id FROM products WHERE barcode = ? LIMIT 1');
        $check->execute([$barcode]);
        if (!$check->fetchColumn()) {
            return $barcode;
        }
    }

    throw new RuntimeException('สร้างบาร์โค้ดไม่สำเร็จ กรุณาลองใหม่');
}

/** ตรวจสอบคอลัมน์บาร์โค้ดและเติมเลขให้สินค้าที่มีอยู่ */
function ensure_product_barcodes(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE products ADD COLUMN barcode CHAR(13) NULL AFTER code');
    }

    $index = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'uq_products_barcode'")->fetch();
    if (!$index) {
        $pdo->exec('CREATE UNIQUE INDEX uq_products_barcode ON products (barcode)');
    }

    // ตรวจสอบรูปแบบบาร์โค้ดและเลขตรวจสอบของสินค้าแต่ละรายการ
    $rows = $pdo->query('SELECT id, code, barcode FROM products ORDER BY id')->fetchAll();
    $seenRandomParts = [];
    $update = $pdo->prepare('UPDATE products SET barcode = ? WHERE id = ?');
    $clear = $pdo->prepare('UPDATE products SET barcode = NULL WHERE id = ?');

    foreach ($rows as $row) {
        $barcode = (string)($row['barcode'] ?? '');
        $valid = (bool)preg_match('/^\d{13}$/', $barcode);
        $randomPart = $valid ? substr($barcode, 8, 4) : '';

        if ($valid) {
            $body = substr($barcode, 0, 12);
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int)$body[$i] * (($i % 2 === 0) ? 1 : 3);
            }
            $expected = (10 - ($sum % 10)) % 10;
            $valid = $expected === (int)$barcode[12]
                && count(array_unique(str_split($randomPart))) === 4
                && !isset($seenRandomParts[$randomPart]);
        }

        if (!$valid) {
            // Clear first so the generator does not see this product's old number as a collision.
            $clear->execute([(int)$row['id']]);
            $barcode = make_ean13($pdo, (string)$row['code']);
            $update->execute([$barcode, (int)$row['id']]);
            $randomPart = substr($barcode, 8, 4);
        }

        $seenRandomParts[$randomPart] = true;
    }
}

/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

try {
    $pdo = db();
} catch (Throwable $ex) {
    http_response_code(500);
    exit(
        '<h2>เชื่อมต่อฐานข้อมูลไม่สำเร็จ</h2>' .
        '<p>ตรวจสอบการตั้งค่า DB_HOST, DB_NAME, DB_USER และ DB_PASS ใน config.php</p>'
    );
}

try {
    ensure_product_barcodes($pdo);
} catch (Throwable $ex) {
    http_response_code(500);
    exit('<h2>ปรับปรุงฐานข้อมูลบาร์โค้ดไม่สำเร็จ</h2><p>ตรวจสอบสิทธิ์ ALTER/CREATE INDEX ของ MySQL และลองใหม่</p>');
}

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/

$action = (string)($_POST['action'] ?? '');

if ($action !== '') {
    require_csrf();

    try {
        /*
        |------------------------------------------------------------------
        | Login
        |------------------------------------------------------------------
        */

        if ($action === 'login') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            $stmt = $pdo->prepare(
                'SELECT id, username, password_hash, role
                 FROM users WHERE username = ? LIMIT 1'
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                throw new RuntimeException('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
            }

            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
            ];

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            flash('เข้าสู่ระบบสำเร็จ');
            redirect('dashboard');
        }

        /*
        |------------------------------------------------------------------
        | Logout
        |------------------------------------------------------------------
        */

        if ($action === 'logout') {
            require_login();

            unset($_SESSION['user']);
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            redirect('login');
        }

        require_login();

        /*
        |------------------------------------------------------------------
        | Add / edit product
        |------------------------------------------------------------------
        */

        if ($action === 'save_product') {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)($_POST['code'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));

            if ($code === '' || $name === '') {
                throw new RuntimeException('กรุณากรอกรหัสสินค้าและชื่อสินค้า');
            }

            if (mb_strlen($code) > 100 || mb_strlen($name) > 200
                || mb_strlen($category) > 100) {
                throw new RuntimeException('ข้อมูลสินค้ายาวเกินกำหนด');
            }

            $base = valid_price($_POST['price_base'] ?? 0);
            $wholesale = valid_price($_POST['price_wholesale'] ?? 0);
            $retail = valid_price($_POST['price_retail'] ?? 0);
            $general = valid_price($_POST['price_general'] ?? 0);

            $pdo->beginTransaction();

            if ($id > 0) {
                $old = get_product($pdo, $id);

                if (!$old) {
                    throw new RuntimeException('ไม่พบสินค้าที่ต้องการแก้ไข');
                }

                $stmt = $pdo->prepare(
                    'UPDATE products
                     SET code=?, name=?, category=?,
                         price_base=?, price_wholesale=?,
                         price_retail=?, price_general=?
                     WHERE id=?'
                );

                $stmt->execute([
                    $code,
                    $name,
                    $category,
                    $base,
                    $wholesale,
                    $retail,
                    $general,
                    $id,
                ]);

                $productId = $id;
                $message = 'แก้ไขข้อมูลสินค้าเรียบร้อยแล้ว';
            } else {
                $initialStock = valid_quantity($_POST['stock'] ?? 0);

                $stmt = $pdo->prepare(
                    'INSERT INTO products
                     (code, name, category, price_base, price_wholesale,
                      price_retail, price_general, stock)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $code,
                    $name,
                    $category,
                    $base,
                    $wholesale,
                    $retail,
                    $general,
                    $initialStock,
                ]);

                $productId = (int)$pdo->lastInsertId();

                if ($initialStock > 0) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO stock_movements
                         (product_id, type, quantity, note, user_id)
                         VALUES (?, ?, ?, ?, ?)'
                    );

                    $stmt->execute([
                        $productId,
                        'opening',
                        $initialStock,
                        'ยอดสต็อกเริ่มต้น',
                        current_user()['id'],
                    ]);
                }

                $message = 'เพิ่มสินค้าเรียบร้อยแล้ว';
            }

            $pdo->commit();

            flash($message);
            redirect('products', ['barcode_product' => $productId]);
        }

        /*
        |------------------------------------------------------------------
        | Receive stock
        |------------------------------------------------------------------
        */

        if ($action === 'receive_stock') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $quantity = valid_quantity($_POST['quantity'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));

            if ($productId < 1 || $quantity < 1) {
                throw new RuntimeException('กรุณาเลือกสินค้าและระบุจำนวนรับเข้า');
            }

            if (mb_strlen($note) > 255) {
                throw new RuntimeException('หมายเหตุยาวเกินกำหนด');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT id FROM products WHERE id=? FOR UPDATE'
            );
            $stmt->execute([$productId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('ไม่พบสินค้า');
            }

            $stmt = $pdo->prepare(
                'UPDATE products SET stock = stock + ? WHERE id=?'
            );
            $stmt->execute([$quantity, $productId]);

            $stmt = $pdo->prepare(
                'INSERT INTO stock_movements
                 (product_id, type, quantity, note, user_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $productId,
                'receive',
                $quantity,
                $note,
                current_user()['id'],
            ]);

            $pdo->commit();

            flash('รับสินค้าเข้าสต็อกเรียบร้อยแล้ว');
            redirect('stock');
        }

        /*
        |------------------------------------------------------------------
        | Adjust stock
        |------------------------------------------------------------------
        */

        if ($action === 'adjust_stock') {
            if (current_user()['role'] !== 'admin') {
                throw new RuntimeException('เฉพาะผู้ดูแลระบบเท่านั้นที่ปรับสต็อกได้');
            }

            $productId = (int)($_POST['product_id'] ?? 0);
            $newStock = valid_quantity($_POST['new_stock'] ?? -1);
            $note = trim((string)($_POST['note'] ?? ''));

            if ($productId < 1) {
                throw new RuntimeException('กรุณาเลือกสินค้า');
            }

            if ($note === '' || mb_strlen($note) > 255) {
                throw new RuntimeException('กรุณาระบุเหตุผลในการปรับสต็อก');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT stock FROM products WHERE id=? FOR UPDATE'
            );
            $stmt->execute([$productId]);
            $old = $stmt->fetch();

            if (!$old) {
                throw new RuntimeException('ไม่พบสินค้า');
            }

            $oldStock = (int)$old['stock'];
            $difference = $newStock - $oldStock;

            $stmt = $pdo->prepare(
                'UPDATE products SET stock=? WHERE id=?'
            );
            $stmt->execute([$newStock, $productId]);

            if ($difference !== 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO stock_movements
                     (product_id, type, quantity, note, user_id)
                     VALUES (?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $productId,
                    'adjustment',
                    $difference,
                    $note,
                    current_user()['id'],
                ]);
            }

            $pdo->commit();

            flash('ปรับสต็อกเรียบร้อยแล้ว');
            redirect('stock');
        }

        /*
        |------------------------------------------------------------------
        | Save sale
        |------------------------------------------------------------------
        */

        if ($action === 'save_sale') {
            $itemsJson = (string)($_POST['items'] ?? '');
            $items = json_decode($itemsJson, true);
            $priceType = (string)($_POST['price_type'] ?? 'retail');
            $discount = valid_price($_POST['discount'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));

            $allowedTypes = ['base', 'wholesale', 'retail', 'general'];

            if (!is_array($items) || count($items) === 0) {
                throw new RuntimeException('กรุณาเพิ่มสินค้าลงในตะกร้าก่อน');
            }

            if (count($items) > 200) {
                throw new RuntimeException('มีสินค้าในรายการมากเกินไป');
            }

            if (!in_array($priceType, $allowedTypes, true)) {
                throw new RuntimeException('ประเภทราคาไม่ถูกต้อง');
            }

            if (mb_strlen($note) > 255) {
                throw new RuntimeException('หมายเหตุยาวเกินกำหนด');
            }

            /*
             * รวมสินค้าที่ซ้ำกันก่อนตัดสต็อก
             */
            $quantities = [];

            foreach ($items as $item) {
                $productId = filter_var(
                    $item['id'] ?? null,
                    FILTER_VALIDATE_INT
                );
                $quantity = filter_var(
                    $item['qty'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if ($productId === false || $productId < 1
                    || $quantity === false || $quantity < 1) {
                    throw new RuntimeException('รายการสินค้าไม่ถูกต้อง');
                }

                $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;

                if ($quantities[$productId] > 1000000) {
                    throw new RuntimeException('จำนวนสินค้ามากเกินไป');
                }
            }

            ksort($quantities);

            $pdo->beginTransaction();

            $lockedProducts = [];
            $subtotal = 0.0;

            /*
             * ล็อกแถวสินค้าและตรวจสต็อกภายใน transaction
             */
            foreach ($quantities as $productId => $quantity) {
                $stmt = $pdo->prepare(
                    'SELECT * FROM products WHERE id=? FOR UPDATE'
                );
                $stmt->execute([$productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new RuntimeException('ไม่พบสินค้า ID ' . $productId);
                }

                if ((int)$product['stock'] < $quantity) {
                    throw new RuntimeException(
                        'สินค้า ' . $product['name'] .
                        ' มีคงเหลือ ' . $product['stock'] .
                        ' ชิ้น แต่ต้องการขาย ' . $quantity . ' ชิ้น'
                    );
                }

                $unitPrice = price_for($product, $priceType);
                $lineTotal = round($unitPrice * $quantity, 2);

                $product['sale_quantity'] = $quantity;
                $product['sale_unit_price'] = $unitPrice;
                $product['sale_line_total'] = $lineTotal;

                $lockedProducts[] = $product;
                $subtotal += $lineTotal;
            }

            $subtotal = round($subtotal, 2);

            if ($discount > $subtotal) {
                throw new RuntimeException('ส่วนลดต้องไม่มากกว่ายอดรวมสินค้า');
            }

            $total = round($subtotal - $discount, 2);
            $receiptNo = receipt_number();

            $stmt = $pdo->prepare(
                'INSERT INTO sales
                 (receipt_no, user_id, subtotal, discount, total, price_type, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $receiptNo,
                current_user()['id'],
                $subtotal,
                $discount,
                $total,
                $priceType,
                $note,
            ]);

            $saleId = (int)$pdo->lastInsertId();

            foreach ($lockedProducts as $product) {
                $productId = (int)$product['id'];
                $quantity = (int)$product['sale_quantity'];

                $stmt = $pdo->prepare(
                    'INSERT INTO sale_items
                     (sale_id, product_id, product_code, product_name,
                      quantity, unit_price, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $saleId,
                    $productId,
                    $product['code'],
                    $product['name'],
                    $quantity,
                    $product['sale_unit_price'],
                    $product['sale_line_total'],
                ]);

                $stmt = $pdo->prepare(
                    'UPDATE products SET stock = stock - ? WHERE id=?'
                );
                $stmt->execute([$quantity, $productId]);

                $stmt = $pdo->prepare(
                    'INSERT INTO stock_movements
                     (product_id, type, quantity, note, user_id)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $productId,
                    'sale',
                    -$quantity,
                    'ขายเลขที่ ' . $receiptNo,
                    current_user()['id'],
                ]);
            }

            $pdo->commit();

            flash('บันทึกการขายสำเร็จ เลขที่ ' . $receiptNo);
            redirect('receipt', ['id' => $saleId]);
        }

        /*
        |------------------------------------------------------------------
        | Delete product
        |------------------------------------------------------------------
        */

        if ($action === 'delete_product') {
            if (current_user()['role'] !== 'admin') {
                throw new RuntimeException('เฉพาะผู้ดูแลระบบเท่านั้นที่ลบสินค้าได้');
            }

            $productId = (int)($_POST['product_id'] ?? 0);

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM sale_items WHERE product_id=?'
            );
            $stmt->execute([$productId]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'สินค้านี้มีประวัติการขายแล้ว ไม่สามารถลบได้'
                );
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT id FROM products WHERE id=? FOR UPDATE'
            );
            $stmt->execute([$productId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('ไม่พบสินค้า');
            }

            $stmt = $pdo->prepare(
                'DELETE FROM stock_movements WHERE product_id=?'
            );
            $stmt->execute([$productId]);

            $stmt = $pdo->prepare(
                'DELETE FROM products WHERE id=?'
            );
            $stmt->execute([$productId]);

            $pdo->commit();

            flash('ลบสินค้าเรียบร้อยแล้ว');
            redirect('products');
        }

        throw new RuntimeException('ไม่พบคำสั่งที่ต้องการ');

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($ex instanceof PDOException && (string)$ex->getCode() === '23000') {
            flash('รหัสสินค้านี้มีอยู่แล้ว กรุณาใช้รหัสอื่น', 'error');
        } else {
            flash($ex->getMessage(), 'error');
        }

        $returnPage = (string)($_POST['return_page'] ?? 'dashboard');

        $allowedPages = [
            'login', 'dashboard', 'products', 'stock',
            'pos', 'sales', 'report', 'users',
        ];

        if (!in_array($returnPage, $allowedPages, true)) {
            $returnPage = 'dashboard';
        }

        redirect($returnPage);
    }
}

/*
|--------------------------------------------------------------------------
| Page routing
|--------------------------------------------------------------------------
*/

$page = (string)($_GET['page'] ?? 'dashboard');

$allowedPages = [
    'login', 'dashboard', 'products', 'stock',
    'pos', 'sales', 'report', 'receipt', 'users',
];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

if ($page !== 'login') {
    require_login();
}

// ส่งออกรายงานสินค้า โดยใช้ตัวกรองเดียวกับหน้ารายงาน
if ($page === 'report' && (string)($_GET['export'] ?? '') === 'excel') {
    $exportSearch = trim((string)($_GET['q'] ?? ''));
    $exportSql = "
        SELECT p.code, p.name, p.category, p.stock,
               COALESCE(SUM(si.quantity), 0) AS sold_quantity,
               COALESCE(SUM(si.line_total), 0) AS gross_sales
        FROM products p
        LEFT JOIN sale_items si ON si.product_id = p.id
    ";
    $exportParams = [];
    if ($exportSearch !== '') {
        $exportSql .= ' WHERE p.code LIKE ? OR p.name LIKE ?';
        $exportLike = '%' . $exportSearch . '%';
        $exportParams = [$exportLike, $exportLike];
    }
    $exportSql .= ' GROUP BY p.id, p.code, p.name, p.category, p.stock ORDER BY gross_sales DESC, p.name ASC LIMIT 500';
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute($exportParams);
    $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'product-report-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $out = fopen('php://output', 'wb');
    // UTF-8 BOM ช่วยให้ Excel อ่านภาษาไทยได้ถูกต้อง
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['รหัสสินค้า', 'ชื่อสินค้า', 'หมวดหมู่', 'ขายสะสม (ชิ้น)', 'ยอดขายสะสม', 'คงเหลือ']);
    foreach ($exportRows as $row) {
        // ป้องกัน Excel ตีความรหัสที่ขึ้นต้นด้วยเครื่องหมายพิเศษเป็นสูตร
        $code = (string)$row['code'];
        if ($code !== '' && preg_match('/^[=+@\-\t\r]/', $code)) {
            $code = "'" . $code;
        }
        fputcsv($out, [
            $code,
            $row['name'],
            $row['category'],
            (int)$row['sold_quantity'],
            number_format((float)$row['gross_sales'], 2, '.', ''),
            (int)$row['stock'],
        ]);
    }
    fclose($out);
    exit;
}

$user = current_user();
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function nav_active(string $current, string $target): string
{
    return $current === $target ? 'active' : '';
}

/*
|--------------------------------------------------------------------------
| Dashboard statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'products' => 0,
    'stock' => 0,
    'sales_today' => 0,
    'amount_today' => 0,
];

if ($user) {
    $stats['products'] = (int)$pdo->query(
        'SELECT COUNT(*) FROM products'
    )->fetchColumn();

    $stats['stock'] = (int)$pdo->query(
        'SELECT COALESCE(SUM(stock), 0) FROM products'
    )->fetchColumn();

    $stmt = $pdo->query(
        'SELECT COUNT(*) AS count_sales,
                COALESCE(SUM(total), 0) AS amount
         FROM sales
         WHERE created_at >= CURDATE()
           AND created_at < CURDATE() + INTERVAL 1 DAY'
    );

    $today = $stmt->fetch();

    $stats['sales_today'] = (int)$today['count_sales'];
    $stats['amount_today'] = (float)$today['amount'];
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>ระบบจัดการร้านค้า | Shop POS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --bg: #f3f6fb;
    --card: #ffffff;
    --text: #172033;
    --muted: #64748b;
    --border: #e2e8f0;
    --success: #15803d;
    --danger: #dc2626;
    --warning: #b45309;
    --radius: 14px;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: "IBM Plex Sans Thai", "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    font-size: 14px;
}

a { color: var(--primary); text-decoration: none; }
button, input, select, textarea { font: inherit; }

button, .btn {
    cursor: pointer;
    border: 0;
    border-radius: 9px;
    padding: 10px 14px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    text-decoration: none;
}

.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-light { background: #eaf0f8; color: #26344d; }
.btn-danger { background: #fee2e2; color: #991b1b; }
.btn-success { background: #dcfce7; color: #166534; }
.btn-small { padding: 6px 9px; font-size: 12px; }
.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 16px;
}
.pagination-summary { color: var(--muted, #64748b); font-size: 13px; }
.pagination-controls { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
.pagination-controls a, .pagination-controls span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    min-height: 34px;
    padding: 6px 10px;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    text-decoration: none;
    font-size: 13px;
}
.pagination-controls .current-page { background: var(--primary, #2563eb); color: #fff; border-color: var(--primary, #2563eb); }
.pagination-controls .disabled-page { opacity: .4; }
@media (max-width: 600px) {
    .pagination-bar { align-items: flex-start; flex-direction: column; }
}

input, select, textarea {
    width: 100%;
    min-width: 0;
    border: 1px solid #cbd5e1;
    background: white;
    border-radius: 9px;
    padding: 10px 11px;
    color: var(--text);
    outline: none;
}

input:focus, select:focus, textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px #2563eb1c;
}

label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}

.form-group { margin-bottom: 15px; }

small, .muted { color: var(--muted); }

.topbar {
    height: 66px;
    background: white;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 0 24px;
    position: sticky;
    top: 0;
    z-index: 10;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 800;
    font-size: 18px;
}

.brand-icon {
    background: var(--primary);
    color: white;
    border-radius: 10px;
    padding: 9px 11px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.layout {
    display: grid;
    grid-template-columns: 225px minmax(0, 1fr);
    min-height: calc(100vh - 66px);
}

.sidebar {
    background: white;
    border-right: 1px solid var(--border);
    padding: 20px 12px;
}

.sidebar-label {
    color: #94a3b8;
    font-size: 11px;
    font-weight: 800;
    padding: 12px 12px 7px;
    letter-spacing: .06em;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    color: #475569;
    border-radius: 10px;
    margin-bottom: 4px;
    font-weight: 600;
}

.nav-link:hover, .nav-link.active {
    background: #eff6ff;
    color: var(--primary);
}

.content {
    padding: 25px;
    min-width: 0;
    max-width: 1600px;
    width: 100%;
    margin: 0 auto;
}

.page-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 22px;
}

.page-heading h1 {
    margin: 0 0 5px;
    font-size: 25px;
}

.page-heading p { margin: 0; color: var(--muted); }

.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px #0f172a04;
}

.card h2, .card h3 { margin-top: 0; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.stat-card {
    background: white;
    border: 1px solid var(--border);
    padding: 20px;
    border-radius: var(--radius);
}

.stat-label { color: var(--muted); font-weight: 600; }
.stat-value { font-size: 27px; font-weight: 800; margin-top: 9px; }
.stat-note { color: var(--muted); font-size: 12px; margin-top: 4px; }

.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 16px;
}

.toolbar input { flex: 1; min-width: 180px; }

.table-wrap {
    overflow-x: auto;
    width: 100%;
}

table {
    width: 100%;
    border-collapse: collapse;
    white-space: nowrap;
}

th, td {
    text-align: left;
    padding: 12px 10px;
    border-bottom: 1px solid var(--border);
}

th {
    color: var(--muted);
    background: #f8fafc;
    font-size: 12px;
}

td { vertical-align: middle; }
tbody tr:hover { background: #fafcff; }

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    background: #e0f2fe;
    color: #075985;
    font-size: 12px;
    font-weight: 700;
}

.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-success { background: #dcfce7; color: #166534; }

.flash {
    padding: 13px 16px;
    border-radius: 10px;
    margin-bottom: 18px;
    background: #dcfce7;
    color: #166534;
}

.flash.error { background: #fee2e2; color: #991b1b; }

.form-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 18px;
}

.empty {
    text-align: center;
    color: var(--muted);
    padding: 35px 15px;
}

.price { font-variant-numeric: tabular-nums; }

.pos-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(320px, 1fr);
    gap: 20px;
    align-items: start;
}

.scan-row {
    display: flex;
    gap: 8px;
}

.scan-row input { flex: 1; }

.cart-total {
    font-size: 28px;
    font-weight: 800;
    text-align: right;
    margin: 15px 0;
}

.cart-table input { width: 75px; }

.login-page {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 20px;
    background: linear-gradient(145deg, #eaf2ff, #f7f9fc);
}

.login-card {
    width: 100%;
    max-width: 420px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 18px 60px #1e293b12;
}

.login-card h1 { margin-top: 0; }
.login-card .brand { margin-bottom: 24px; }

.receipt {
    background: white;
    max-width: 650px;
    margin: auto;
    padding: 30px;
    border: 1px solid var(--border);
    border-radius: 12px;
}

.receipt-header { text-align: center; margin-bottom: 25px; }

.barcode-labels {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}

.barcode-label {
    text-align: center;
    padding: 8px;
    border: 1px dashed #cbd5e1;
    border-radius: 5px;
    overflow: hidden;
    break-inside: avoid;
}

.barcode-label svg {
    max-width: 100%;
    height: 48px;
}

.barcode-name {
    font-size: 11px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.barcode-code { font-size: 10px; }

@media (max-width: 1000px) {
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .pos-layout { grid-template-columns: 1fr; }
    .grid-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 720px) {
    .topbar { padding: 0 12px; height: 58px; }
    .brand { font-size: 15px; }
    .topbar-right { gap: 5px; }
    .layout { grid-template-columns: 1fr; }
    .sidebar {
        display: flex;
        gap: 5px;
        overflow-x: auto;
        padding: 8px;
        border-right: 0;
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 58px;
        z-index: 9;
    }
    .sidebar-label { display: none; }
    .nav-link {
        white-space: nowrap;
        padding: 10px;
        font-size: 12px;
        margin: 0;
    }
    .content { padding: 14px; }
    .page-heading h1 { font-size: 21px; }
    .stats-grid { gap: 9px; }
    .stat-card { padding: 13px; }
    .stat-value { font-size: 21px; }
    .card { padding: 14px; }
    .grid-2, .grid-3 { grid-template-columns: 1fr; }
    .barcode-labels { grid-template-columns: repeat(2, 1fr); }
    .topbar-right .user-name { display: none; }
}

.report-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.btn-success { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 40px; padding: 10px 15px; border: 1px solid #15803d; border-radius: 10px; background: #16a34a; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
.btn-success:hover { background: #15803d; color: #fff; }
@media print {
    body { background: white; }
    .report-actions, .toolbar.no-print { display: none !important; }
    body:has(.page-heading h1) .content { padding: 0; }

    .no-print, .topbar, .sidebar { display: none !important; }
    .layout { display: block; min-height: auto; }
    .content { padding: 0; max-width: none; }
    .card, .receipt { border: 0; box-shadow: none; padding: 0; }
    .barcode-labels { gap: 3mm; }
    .barcode-label { border: 1px dashed #aaa; }
    .receipt { max-width: none; }
    @page { margin: 8mm; }
}


/* ปรับขนาดปุ่ม ช่องกรอกข้อมูล และการแสดงผลบนหน้าจอขนาดต่าง ๆ */
:root { --radius: 14px; }
button, .btn {
    min-height: 40px;
    line-height: 1.25;
    transition: background-color .15s ease, box-shadow .15s ease, transform .15s ease;
}
button:hover:not(:disabled), .btn:hover { box-shadow: 0 2px 7px #0f172a14; }
button:active:not(:disabled) { transform: translateY(1px); }
button:disabled { opacity: .55; cursor: not-allowed; box-shadow: none; }
input, select, textarea { min-height: 42px; }
textarea { resize: vertical; }
.card { padding: clamp(15px, 2vw, 22px); }
.page-heading { margin-bottom: 20px; }
.page-heading h1 { line-height: 1.25; }
.table-wrap { border: 1px solid var(--border); border-radius: 10px; }
.table-wrap table { margin: 0; }
.table-wrap th:first-child, .table-wrap td:first-child { padding-left: 14px; }
.scan-row { align-items: stretch; }
.scan-row input { min-width: 0; }
.scan-row button { flex: 0 0 auto; min-width: 86px; }
.camera-controls { align-items: center; }
.camera-controls button { min-width: 145px; }
.camera-panel {
    margin-top: 14px;
    padding: 12px;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    background: #f8fbff;
}
.camera-preview {
    position: relative;
    overflow: hidden;
    display: grid;
    place-items: center;
    width: 100%;
    max-height: 380px;
    min-height: 180px;
    background: #0f172a;
    border-radius: 10px;
}
.camera-preview video { display: block; width: 100%; max-height: 380px; object-fit: contain; }
.camera-guide {
    pointer-events: none;
    position: absolute;
    width: min(78%, 420px);
    height: 30%;
    border: 2px solid #34d399;
    border-radius: 8px;
    box-shadow: 0 0 0 999px #0206171f;
}
.camera-status-row { display: flex; align-items: center; gap: 8px; margin-top: 11px; font-weight: 700; color: #166534; }
.camera-live-dot { width: 9px; height: 9px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 4px #dcfce7; flex: 0 0 auto; }
.camera-help { margin: 8px 0 0; line-height: 1.5; }
@media (max-width: 720px) {
    .scan-row { flex-wrap: wrap; }
    .scan-row input { flex: 1 1 100%; }
    .scan-row button { flex: 1 1 auto; }
    .camera-controls { display: grid; grid-template-columns: 1fr 1fr; }
    .camera-controls button { min-width: 0; width: 100%; padding-inline: 10px; }
    .table-wrap th, .table-wrap td { padding: 10px 8px; }
    .page-heading { align-items: flex-start; }
    .form-actions > button { max-width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { transition-duration: .01ms !important; animation-duration: .01ms !important; }
}


/* รูปแบบตัวอักษรและองค์ประกอบในหน้าภาพรวมกับหน้าสินค้า */
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --text: #172033;
    --muted: #64748b;
}
body { font-family: "IBM Plex Sans Thai", "Inter", system-ui, sans-serif; letter-spacing: -.01em; }
button, input, select, textarea { font-family: inherit; }
.dashboard-sale-btn {
    display: inline-flex; align-items: center; gap: 11px; min-height: 58px;
    padding: 9px 15px 9px 10px; border-radius: 14px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff;
    box-shadow: 0 7px 18px #2563eb2b; transition: transform .16s ease, box-shadow .16s ease;
}
.dashboard-sale-btn:hover { color: #fff; transform: translateY(-1px); box-shadow: 0 10px 22px #2563eb38; }
.dashboard-sale-icon { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 11px; background: #ffffff26; font-size: 25px; font-weight: 500; }
.dashboard-sale-btn strong, .dashboard-sale-btn small { display: block; text-align: left; }
.dashboard-sale-btn strong { font-size: 14px; line-height: 1.35; }
.dashboard-sale-btn small { margin-top: 2px; color: #dbeafe; font-size: 11px; font-weight: 500; }
.dashboard-sale-arrow { margin-left: 5px; font-size: 19px; opacity: .85; }
.section-heading { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 15px; }
.section-heading h2 { margin: 0 0 4px; }
.section-heading p { margin: 0; font-size: 12px; }
.shortcut-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 11px; }
.shortcut-card { display: flex; align-items: center; gap: 11px; min-width: 0; min-height: 86px; padding: 13px; border: 1px solid var(--border); border-radius: 13px; background: #fff; color: var(--text); transition: border-color .15s ease, background .15s ease, transform .15s ease, box-shadow .15s ease; }
.shortcut-card:hover { color: var(--text); border-color: #bfdbfe; background: #f8fbff; transform: translateY(-1px); box-shadow: 0 5px 14px #0f172a0b; }
.shortcut-icon { display: grid; place-items: center; flex: 0 0 42px; width: 42px; height: 42px; border-radius: 12px; font-size: 21px; font-weight: 700; }
.shortcut-products .shortcut-icon { background: #eff6ff; color: #2563eb; }
.shortcut-stock .shortcut-icon { background: #ecfdf5; color: #047857; }
.shortcut-pos .shortcut-icon { background: #fff7ed; color: #c2410c; }
.shortcut-report .shortcut-icon { background: #f5f3ff; color: #6d28d9; }
.shortcut-copy { flex: 1; min-width: 0; }
.shortcut-copy strong { display: block; font-size: 13px; line-height: 1.45; }
.shortcut-copy small { display: block; margin-top: 4px; color: var(--muted); font-size: 11px; line-height: 1.45; }
.shortcut-arrow { align-self: flex-start; color: #94a3b8; font-size: 15px; }
.product-search-toolbar { align-items: stretch; gap: 8px; }
.product-search-input-wrap { display: flex; align-items: center; flex: 1 1 280px; min-width: 180px; position: relative; }
.product-search-input-wrap .search-icon { position: absolute; left: 13px; color: #94a3b8; font-size: 22px; line-height: 1; pointer-events: none; }
.product-search-input-wrap input { padding-left: 40px; min-height: 42px; }
.search-submit-btn { min-width: 94px; }
.btn-clear-search { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 42px; padding: 0 13px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; color: #475569; font-weight: 600; white-space: nowrap; transition: background .15s ease, border-color .15s ease; }
.btn-clear-search span:first-child { display: grid; place-items: center; width: 19px; height: 19px; border-radius: 50%; background: #f1f5f9; color: #64748b; font-size: 16px; line-height: 1; }
.btn-clear-search:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }
.product-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; min-width: 190px; }
.product-actions form { margin: 0; }
.btn-action { display: inline-flex; align-items: center; justify-content: center; gap: 5px; min-height: 32px; padding: 6px 9px; border: 1px solid transparent; border-radius: 8px; font-size: 12px; font-weight: 600; line-height: 1.2; white-space: nowrap; transition: background .15s ease, border-color .15s ease, transform .15s ease; }
.btn-action span:first-child { font-size: 14px; }
.btn-edit { background: #eff6ff; border-color: #dbeafe; color: #1d4ed8; }
.btn-edit:hover { background: #dbeafe; color: #1e40af; }
.btn-barcode { background: #f5f3ff; border-color: #ede9fe; color: #6d28d9; }
.btn-barcode:hover { background: #ede9fe; }
.btn-delete { background: #fff1f2; border-color: #ffe4e6; color: #be123c; }
.btn-delete:hover { background: #ffe4e6; }
.product-delete-form { display: inline-flex; }
.product-actions .btn-action:focus-visible, .shortcut-card:focus-visible, .dashboard-sale-btn:focus-visible, .btn-clear-search:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
@media (max-width: 900px) { .shortcut-grid { grid-template-columns: 1fr; } }
@media (max-width: 720px) {
    .dashboard-sale-btn { min-height: 52px; }
    .shortcut-grid { grid-template-columns: 1fr; }
    .shortcut-card { min-height: 72px; }
    .product-search-input-wrap { flex-basis: 100%; }
    .search-submit-btn { flex: 1 1 auto; }
    .btn-clear-search { flex: 0 0 auto; }
    .product-actions { min-width: 0; gap: 5px; }
    .btn-action { padding: 6px 8px; }
}


/* หน้าต่างป๊อปอัปสำหรับกล้องและตะกร้าสินค้า */
.popup-overlay[hidden] { display: none !important; }
.popup-overlay { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 18px; background: rgba(15, 23, 42, .62); }
.popup-dialog { width: min(900px, 100%); max-height: min(92vh, 900px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px #02061755; padding: clamp(16px, 3vw, 26px); }
.popup-dialog.cart-dialog { width: min(980px, 100%); }
.popup-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.popup-header h2 { margin: 0; }
.popup-header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.popup-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
.popup-actions > button { flex: 1 1 150px; }

.combined-scan-dialog {
    width: min(1180px, 100%);
    max-height: min(94vh, 1000px);
    overflow: auto;
}
.combined-popup-header { margin-bottom: 12px; }
.combined-scan-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 18px;
    align-items: start;
}
.combined-camera-column, .combined-cart-column {
    min-width: 0;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px;
    background: #fff;
}
.combined-camera-column h3, .combined-cart-column h3 {
    margin: 0 0 12px;
    font-size: 17px;
}
.combined-camera-column .camera-panel { margin-top: 0; }
.combined-camera-column .camera-preview { min-height: 220px; max-height: 340px; }
.combined-camera-column .camera-preview video { max-height: 340px; }
.combined-camera-column .camera-controls { display: grid; grid-template-columns: 1fr 1fr; }
.combined-camera-column .camera-controls button { min-width: 0; width: 100%; }
.combined-camera-hint { margin: 10px 0 0; }
.combined-cart-table-wrap { max-height: 34vh; overflow: auto; }
.combined-cart-table-wrap table { min-width: 440px; }
.combined-cart-column .form-group { margin-bottom: 10px; }
.combined-cart-actions { position: sticky; bottom: 0; padding-top: 10px; background: #fff; }
@media (max-width: 760px) {
    .combined-scan-dialog { max-height: 96vh; }
    .combined-scan-layout { grid-template-columns: minmax(0, 1fr); gap: 12px; }
    .combined-camera-column, .combined-cart-column { padding: 11px; }
    .combined-camera-column .camera-preview { min-height: 170px; max-height: 240px; }
    .combined-camera-column .camera-preview video { max-height: 240px; }
    .combined-cart-table-wrap { max-height: 32vh; }
}

.pos-toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; margin: 14px 0; }
.pos-toolbar-actions button { flex: 1 1 170px; }
#product-list tbody tr[hidden] { display: none; }
@media (max-width: 600px) { .popup-overlay { padding: 8px; align-items: flex-end; } .popup-dialog { max-height: 94vh; border-radius: 16px 16px 10px 10px; } }


/* กล่องแจ้งเตือน */
.notice-overlay[hidden] { display: none !important; }
.notice-overlay {
    position: fixed;
    inset: 0;
    z-index: 3000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(15, 23, 42, .48);
    backdrop-filter: blur(3px);
    animation: notice-fade-in .18s ease-out;
}
.notice-box {
    width: min(100%, 390px);
    padding: 24px;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .24);
    text-align: center;
    animation: notice-pop-in .24s cubic-bezier(.2,.8,.2,1);
}
.notice-icon {
    width: 54px;
    height: 54px;
    margin: 0 auto 14px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #e0f2fe;
    color: #0369a1;
    font-size: 27px;
    font-weight: 700;
}
.notice-box[data-type="error"] .notice-icon { background: #fee2e2; color: #b91c1c; }
.notice-box[data-type="success"] .notice-icon { background: #dcfce7; color: #15803d; }
.notice-box h3 { margin: 0 0 8px; font-size: 19px; }
.notice-box p { margin: 0; color: #475569; line-height: 1.6; overflow-wrap: anywhere; }
.notice-actions { display: flex; justify-content: center; gap: 10px; margin-top: 22px; }
.notice-actions button {
    min-width: 94px;
    border: 0;
    border-radius: 10px;
    padding: 10px 16px;
    cursor: pointer;
    font: inherit;
    font-weight: 600;
}
.notice-ok, .notice-confirm { background: #2563eb; color: #fff; }
.notice-cancel { background: #f1f5f9; color: #334155; }
@keyframes notice-fade-in { from { opacity: 0; } to { opacity: 1; } }
@keyframes notice-pop-in { from { opacity: 0; transform: translateY(10px) scale(.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
@media (prefers-reduced-motion: reduce) {
    .notice-overlay, .notice-box { animation: none; }
}

</style>
</head>

<body>

<?php if ($page === 'login'): ?>

<div class="login-page">
    <div class="login-card">
        <div class="brand">
            <span class="brand-icon">POS</span>
            <span>ระบบจัดการร้านค้า</span>
        </div>

        <h1>เข้าสู่ระบบ</h1>
        <p class="muted">กรุณาเข้าสู่ระบบเพื่อจัดการสินค้าและการขาย</p>

        <?php if ($flashMessage): ?>
            <div class="flash <?= e($flashMessage['type'] ?? '') === 'error' ? 'error' : '' ?>">
                <?= e($flashMessage['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label for="username">ชื่อผู้ใช้</label>
                <input id="username" name="username" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">รหัสผ่าน</label>
                <input id="password" type="password" name="password"
                       required autocomplete="current-password">
            </div>

            <button class="btn-primary" style="width:100%">เข้าสู่ระบบ</button>
        </form>

        <p class="muted" style="margin-top:20px;font-size:12px">
            ถ้าใช้งานครั้งแรก โปรดเปลี่ยนรหัสผ่านก่อนใช้งานจริง
        </p>
    </div>
</div>

<?php else: ?>

<header class="topbar no-print">
    <div class="brand">
        <span class="brand-icon">POS</span>
        <span>Shop POS</span>
    </div>

    <div class="topbar-right">
        <span class="user-name">
            <?= e($user['username'] ?? '') ?>
            (<?= e(($user['role'] ?? '') === 'admin' ? 'ผู้ดูแลระบบ' : 'พนักงาน') ?>)
        </span>

        <form method="post" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="btn-light btn-small">ออกจากระบบ</button>
        </form>
    </div>
</header>

<div class="layout">
    <aside class="sidebar no-print">
        <div class="sidebar-label">เมนูหลัก</div>

        <a class="nav-link <?= nav_active($page, 'dashboard') ?>" href="index.php?page=dashboard">
            <span>▦</span> ภาพรวม
        </a>

        <a class="nav-link <?= nav_active($page, 'pos') ?>" href="index.php?page=pos">
            <span>▣</span> ขายสินค้า
        </a>

        <div class="sidebar-label">จัดการสินค้า</div>

        <a class="nav-link <?= nav_active($page, 'products') ?>" href="index.php?page=products">
            <span>▤</span> รายการสินค้า
        </a>

        <a class="nav-link <?= nav_active($page, 'stock') ?>" href="index.php?page=stock">
            <span>▥</span> สต็อกสินค้า
        </a>

        <div class="sidebar-label">รายงาน</div>

        <a class="nav-link <?= nav_active($page, 'sales') ?>" href="index.php?page=sales">
            <span>▧</span> ประวัติการขาย
        </a>

        <a class="nav-link <?= nav_active($page, 'report') ?>" href="index.php?page=report">
            <span>▨</span> รายงานสินค้า
        </a>
    </aside>

    <main class="content">

        <?php if ($flashMessage): ?>
            <div class="flash <?= ($flashMessage['type'] ?? '') === 'error' ? 'error' : '' ?>">
                <?= e($flashMessage['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <?php if ($page === 'dashboard'): ?>

            <div class="page-heading">
                <div>
                    <h1>ภาพรวมร้านค้า</h1>
                    <p>สรุปข้อมูลร้านค้า ณ วันนี้</p>
                </div>
                <a href="index.php?page=pos" class="btn-primary dashboard-sale-btn">
                    <span class="dashboard-sale-icon" aria-hidden="true">＋</span>
                    <span><strong>ขายสินค้า</strong><small>เริ่มทำรายการขาย</small></span>
                    <span class="dashboard-sale-arrow" aria-hidden="true">→</span>
                </a>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">รายการสินค้า</div>
                    <div class="stat-value"><?= number_format($stats['products']) ?></div>
                    <div class="stat-note">จำนวน SKU ในระบบ</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">สินค้าคงเหลือ</div>
                    <div class="stat-value"><?= number_format($stats['stock']) ?></div>
                    <div class="stat-note">รวมทุกสินค้า (ชิ้น)</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">บิลขายวันนี้</div>
                    <div class="stat-value"><?= number_format($stats['sales_today']) ?></div>
                    <div class="stat-note">จำนวนใบเสร็จ</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">ยอดขายวันนี้</div>
                    <div class="stat-value" style="font-size:23px">
                        ฿<?= money($stats['amount_today']) ?>
                    </div>
                    <div class="stat-note">หลังหักส่วนลด</div>
                </div>
            </div>

            <div class="grid-2">
                <section class="card">
                    <div class="section-heading">
                        <div><h2>เมนูลัด</h2><p class="muted">ไปยังส่วนที่ใช้งานบ่อยได้อย่างรวดเร็ว</p></div>
                    </div>
                    <div class="shortcut-grid">
                        <a class="shortcut-card shortcut-products" href="index.php?page=products">
                            <span class="shortcut-icon" aria-hidden="true">＋</span>
                            <span class="shortcut-copy"><strong>เพิ่มหรือแก้ไขสินค้า</strong><small>จัดการรายการสินค้าและราคา</small></span>
                            <span class="shortcut-arrow" aria-hidden="true">↗</span>
                        </a>
                        <a class="shortcut-card shortcut-stock" href="index.php?page=stock">
                            <span class="shortcut-icon" aria-hidden="true">▤</span>
                            <span class="shortcut-copy"><strong>รับสินค้าเข้าสต็อก</strong><small>เพิ่มจำนวนสินค้าคงเหลือ</small></span>
                            <span class="shortcut-arrow" aria-hidden="true">↗</span>
                        </a>
                        <a class="shortcut-card shortcut-pos" href="index.php?page=pos">
                            <span class="shortcut-icon" aria-hidden="true">▣</span>
                            <span class="shortcut-copy"><strong>เปิดหน้าขายสินค้า</strong><small>สแกนสินค้าและคิดเงิน</small></span>
                            <span class="shortcut-arrow" aria-hidden="true">↗</span>
                        </a>
                        <a class="shortcut-card shortcut-report" href="index.php?page=report">
                            <span class="shortcut-icon" aria-hidden="true">▥</span>
                            <span class="shortcut-copy"><strong>ดูรายงานสินค้า</strong><small>ตรวจสอบข้อมูลและสรุปสินค้า</small></span>
                            <span class="shortcut-arrow" aria-hidden="true">↗</span>
                        </a>
                    </div>
                </section>

                <section class="card">
                    <h2>รายการขายล่าสุด</h2>

                    <?php
                    $recentSalesPage = max(1, (int)($_GET['dashboard_sales_page'] ?? 1));
                    $recentSalesPerPage = 5;
                    $recentSalesCount = (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
                    $recentSalesPages = max(1, (int)ceil($recentSalesCount / $recentSalesPerPage));
                    $recentSalesPage = min($recentSalesPage, $recentSalesPages);
                    $recentSalesOffset = ($recentSalesPage - 1) * $recentSalesPerPage;
                    $recentSalesStmt = $pdo->prepare(
                        'SELECT id, receipt_no, total, created_at FROM sales ORDER BY id DESC LIMIT ? OFFSET ?'
                    );
                    $recentSalesStmt->bindValue(1, $recentSalesPerPage, PDO::PARAM_INT);
                    $recentSalesStmt->bindValue(2, $recentSalesOffset, PDO::PARAM_INT);
                    $recentSalesStmt->execute();
                    $recentSales = $recentSalesStmt->fetchAll();
                    ?>

                    <?php if (!$recentSales): ?>
                        <div class="empty">ยังไม่มีรายการขาย</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr><th>เลขที่บิล</th><th>ยอดขาย</th><th>เวลา</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recentSales as $sale): ?>
                                    <tr>
                                        <td>
                                            <a href="index.php?page=receipt&id=<?= (int)$sale['id'] ?>">
                                                <?= e($sale['receipt_no']) ?>
                                            </a>
                                        </td>
                                        <td class="price">฿<?= money($sale['total']) ?></td>
                                        <td><?= e($sale['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="pagination-bar">
                            <div class="pagination-summary">แสดง <?= $recentSalesCount ? number_format($recentSalesOffset + 1) . '–' . number_format(min($recentSalesOffset + $recentSalesPerPage, $recentSalesCount)) : '0' ?> จากทั้งหมด <?= number_format($recentSalesCount) ?> บิล</div>
                            <?php if ($recentSalesPages > 1): ?>
                                <nav class="pagination-controls" aria-label="หน้ารายการขายล่าสุด">
                                    <?php if ($recentSalesPage > 1): ?><a href="index.php?page=dashboard&amp;dashboard_sales_page=<?= $recentSalesPage - 1 ?>&amp;low_stock_page=<?= max(1, (int)($_GET['low_stock_page'] ?? 1)) ?>">←</a><?php else: ?><span class="disabled-page">←</span><?php endif; ?>
                                    <?php for ($pg = max(1, $recentSalesPage - 2); $pg <= min($recentSalesPages, $recentSalesPage + 2); $pg++): ?>
                                        <?php if ($pg === $recentSalesPage): ?><span class="current-page"><?= $pg ?></span><?php else: ?><a href="index.php?page=dashboard&amp;dashboard_sales_page=<?= $pg ?>&amp;low_stock_page=<?= max(1, (int)($_GET['low_stock_page'] ?? 1)) ?>"><?= $pg ?></a><?php endif; ?>
                                    <?php endfor; ?>
                                    <?php if ($recentSalesPage < $recentSalesPages): ?><a href="index.php?page=dashboard&amp;dashboard_sales_page=<?= $recentSalesPage + 1 ?>&amp;low_stock_page=<?= max(1, (int)($_GET['low_stock_page'] ?? 1)) ?>">→</a><?php else: ?><span class="disabled-page">→</span><?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <section class="card">
                <h2>สินค้าใกล้หมด</h2>

                <?php
                $lowStockPage = max(1, (int)($_GET['low_stock_page'] ?? 1));
                $lowStockPerPage = 10;
                $lowStockCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE stock <= 5')->fetchColumn();
                $lowStockPages = max(1, (int)ceil($lowStockCount / $lowStockPerPage));
                $lowStockPage = min($lowStockPage, $lowStockPages);
                $lowStockOffset = ($lowStockPage - 1) * $lowStockPerPage;
                $lowStockStmt = $pdo->prepare('SELECT id, code, name, stock FROM products WHERE stock <= 5 ORDER BY stock ASC, name ASC LIMIT ? OFFSET ?');
                $lowStockStmt->bindValue(1, $lowStockPerPage, PDO::PARAM_INT);
                $lowStockStmt->bindValue(2, $lowStockOffset, PDO::PARAM_INT);
                $lowStockStmt->execute();
                $lowStock = $lowStockStmt->fetchAll();
                ?>

                <?php if (!$lowStock): ?>
                    <div class="empty">ไม่มีสินค้าใกล้หมด</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>รหัสสินค้า</th><th>ชื่อสินค้า</th><th>คงเหลือ</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($lowStock as $p): ?>
                                <tr>
                                    <td><?= e($p['code']) ?></td>
                                    <td><?= e($p['name']) ?></td>
                                    <td>
                                        <span class="badge <?= (int)$p['stock'] === 0 ? 'badge-danger' : '' ?>">
                                            <?= number_format((int)$p['stock']) ?> ชิ้น
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-bar">
                        <div class="pagination-summary">แสดง <?= $lowStockCount ? number_format($lowStockOffset + 1) . '–' . number_format(min($lowStockOffset + $lowStockPerPage, $lowStockCount)) : '0' ?> จากทั้งหมด <?= number_format($lowStockCount) ?> รายการ</div>
                        <?php if ($lowStockPages > 1): ?>
                            <nav class="pagination-controls" aria-label="หน้าสินค้าใกล้หมด">
                                <?php if ($lowStockPage > 1): ?><a href="index.php?page=dashboard&amp;low_stock_page=<?= $lowStockPage - 1 ?>&amp;dashboard_sales_page=<?= max(1, (int)($_GET['dashboard_sales_page'] ?? 1)) ?>">←</a><?php else: ?><span class="disabled-page">←</span><?php endif; ?>
                                <?php for ($pg = max(1, $lowStockPage - 2); $pg <= min($lowStockPages, $lowStockPage + 2); $pg++): ?>
                                    <?php if ($pg === $lowStockPage): ?><span class="current-page"><?= $pg ?></span><?php else: ?><a href="index.php?page=dashboard&amp;low_stock_page=<?= $pg ?>&amp;dashboard_sales_page=<?= max(1, (int)($_GET['dashboard_sales_page'] ?? 1)) ?>"><?= $pg ?></a><?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($lowStockPage < $lowStockPages): ?><a href="index.php?page=dashboard&amp;low_stock_page=<?= $lowStockPage + 1 ?>&amp;dashboard_sales_page=<?= max(1, (int)($_GET['dashboard_sales_page'] ?? 1)) ?>">→</a><?php else: ?><span class="disabled-page">→</span><?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($page === 'products'): ?>

            <?php
            $search = trim((string)($_GET['q'] ?? ''));
            $editId = (int)($_GET['edit'] ?? 0);
            $editProduct = $editId > 0 ? get_product($pdo, $editId) : null;

            $productConditions = '';
            $productParams = [];

            if ($search !== '') {
                $productConditions = ' WHERE code LIKE ? OR name LIKE ? OR category LIKE ?';
                $like = '%' . $search . '%';
                $productParams = [$like, $like, $like];
            }

            $countProductsStmt = $pdo->prepare('SELECT COUNT(*) FROM products' . $productConditions);
            $countProductsStmt->execute($productParams);
            $totalProducts = (int)$countProductsStmt->fetchColumn();

            $productsPerPage = 10;
            $totalProductPages = max(1, (int)ceil($totalProducts / $productsPerPage));
            $productPage = filter_var($_GET['product_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $productPage = $productPage === false ? 1 : min((int)$productPage, $totalProductPages);
            $productOffset = ($productPage - 1) * $productsPerPage;

            $sql = 'SELECT * FROM products' . $productConditions . ' ORDER BY id DESC LIMIT ? OFFSET ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($productParams, [$productsPerPage, $productOffset]));
            $products = $stmt->fetchAll();
            ?>

            <div class="page-heading">
                <div>
                    <h1>จัดการสินค้า</h1>
                    <p>เพิ่มสินค้า กำหนดราคาทั้ง 4 ประเภท และพิมพ์บาร์โค้ด</p>
                </div>
                <button class="btn-primary" onclick="document.getElementById('product-form').scrollIntoView({behavior:'smooth'})">
                    ＋ เพิ่มสินค้า
                </button>
            </div>

            <section class="card" id="product-form">
                <h2><?= $editProduct ? 'แก้ไขสินค้า' : 'เพิ่มสินค้าใหม่' ?></h2>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_product">
                    <input type="hidden" name="return_page" value="products">
                    <input type="hidden" name="id" value="<?= (int)($editProduct['id'] ?? 0) ?>">

                    <div class="grid-3">
                        <div class="form-group">
                            <label>รหัสสินค้า / Barcode *</label>
                            <input name="code" maxlength="100" required
                                   value="<?= e($editProduct['code'] ?? '') ?>"
                                   placeholder="เช่น 8851234567890">
                            <small>รหัสนี้จะใช้ค้นหาด้วยเครื่องสแกน</small>
                        </div>

                        <div class="form-group">
                            <label>ชื่อสินค้า *</label>
                            <input name="name" maxlength="200" required
                                   value="<?= e($editProduct['name'] ?? '') ?>"
                                   placeholder="ชื่อสินค้า">
                        </div>

                        <div class="form-group">
                            <label>หมวดหมู่</label>
                            <input name="category" maxlength="100"
                                   value="<?= e($editProduct['category'] ?? '') ?>"
                                   placeholder="เช่น อุปกรณ์ร้านค้า">
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label>ราคากลาง (บาท)</label>
                            <input type="number" name="price_base" min="0" step="0.01" required
                                   value="<?= e($editProduct['price_base'] ?? '0.00') ?>">
                        </div>

                        <div class="form-group">
                            <label>ราคาขายส่ง (บาท)</label>
                            <input type="number" name="price_wholesale" min="0" step="0.01" required
                                   value="<?= e($editProduct['price_wholesale'] ?? '0.00') ?>">
                        </div>

                        <div class="form-group">
                            <label>ราคาหน้าร้าน (บาท)</label>
                            <input type="number" name="price_retail" min="0" step="0.01" required
                                   value="<?= e($editProduct['price_retail'] ?? '0.00') ?>">
                        </div>

                        <div class="form-group">
                            <label>ราคาทั่วไป (บาท)</label>
                            <input type="number" name="price_general" min="0" step="0.01" required
                                   value="<?= e($editProduct['price_general'] ?? '0.00') ?>">
                        </div>
                    </div>

                    <?php if (!$editProduct): ?>
                        <div class="form-group">
                            <label>สต็อกเริ่มต้น (ชิ้น)</label>
                            <input type="number" name="stock" min="0" step="1" value="0" required>
                            <small>หากมีสินค้าอยู่แล้ว ให้ระบุจำนวนที่มีจริง ระบบจะบันทึกเป็นยอดตั้งต้น</small>
                        </div>
                    <?php else: ?>
                        <p class="muted">
                            สินค้าคงเหลือปัจจุบัน:
                            <strong><?= number_format((int)$editProduct['stock']) ?> ชิ้น</strong>
                            หากต้องการเพิ่มหรือลดสต็อก ให้ไปที่เมนูสต็อกสินค้า
                        </p>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button class="btn-primary">บันทึกสินค้า</button>
                        <?php if ($editProduct): ?>
                            <a class="btn-light" href="index.php?page=products">ยกเลิก</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="card">
                <h2>รายการสินค้า <span class="muted" style="font-size:13px;font-weight:400">ทั้งหมด <?= number_format($totalProducts) ?> รายการ</span></h2>

                <form class="toolbar product-search-toolbar" method="get" role="search">
                    <input type="hidden" name="page" value="products">
                    <div class="product-search-input-wrap">
                        <span class="search-icon" aria-hidden="true">⌕</span>
                        <input name="q" value="<?= e($search) ?>"
                               aria-label="ค้นหาสินค้า"
                               placeholder="ค้นหารหัสสินค้า ชื่อสินค้า หรือหมวดหมู่">
                    </div>
                    <button class="btn-primary search-submit-btn" type="submit"><span aria-hidden="true">⌕</span> ค้นหา</button>
                    <a class="btn-clear-search" href="index.php?page=products" aria-label="ล้างคำค้นหา" title="ล้างคำค้นหา"><span aria-hidden="true">×</span><span>ล้างคำค้นหา</span></a>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>รหัสสินค้า</th>
                                <th>ชื่อสินค้า</th>
                                <th>ราคากลาง</th>
                                <th>ขายส่ง</th>
                                <th>หน้าร้าน</th>
                                <th>ราคาทั่วไป</th>
                                <th>คงเหลือ</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><?= e($p['code']) ?></td>
                                <td>
                                    <strong><?= e($p['name']) ?></strong>
                                    <div class="muted"><?= e($p['category']) ?></div>
                                </td>
                                <td class="price"><?= money($p['price_base']) ?></td>
                                <td class="price"><?= money($p['price_wholesale']) ?></td>
                                <td class="price"><?= money($p['price_retail']) ?></td>
                                <td class="price"><?= money($p['price_general']) ?></td>
                                <td>
                                    <span class="badge <?= (int)$p['stock'] === 0 ? 'badge-danger' : '' ?>">
                                        <?= number_format((int)$p['stock']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="product-actions">
                                        <a class="btn-action btn-edit" href="index.php?page=products&edit=<?= (int)$p['id'] ?>" title="แก้ไขสินค้า" aria-label="แก้ไขสินค้า <?= e($p['name']) ?>">
                                            <span aria-hidden="true">✎</span><span>แก้ไข</span>
                                        </a>

                                        <button type="button"
                                            class="btn-action btn-barcode js-open-barcode"
                                            data-id="<?= (int)$p['id'] ?>"
                                            data-code="<?= e($p['code']) ?>"
                                            data-barcode="<?= e($p['barcode'] ?? '') ?>"
                                            data-name="<?= e($p['name']) ?>"
                                            data-stock="<?= (int)$p['stock'] ?>"
                                            title="ดูและพิมพ์บาร์โค้ด" aria-label="ดูและพิมพ์บาร์โค้ด <?= e($p['name']) ?>">
                                            <span aria-hidden="true">▥</span><span>บาร์โค้ด</span>
                                        </button>

                                        <?php if (($user['role'] ?? '') === 'admin'): ?>
                                            <form class="product-delete-form js-confirm-form" method="post" data-confirm="ยืนยันลบสินค้านี้?">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="return_page" value="products">
                                                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                                                <button class="btn-action btn-delete" type="submit" title="ลบสินค้า" aria-label="ลบสินค้า <?= e($p['name']) ?>"><span aria-hidden="true">×</span><span>ลบ</span></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!$products): ?>
                    <div class="empty">ไม่พบสินค้า</div>
                <?php endif; ?>

                <?php if ($totalProducts > 0): ?>
                    <div class="pagination-bar">
                        <div class="pagination-summary">
                            แสดง <?= number_format($productOffset + 1) ?>–<?= number_format(min($productOffset + count($products), $totalProducts)) ?>
                            จากทั้งหมด <?= number_format($totalProducts) ?> รายการ
                        </div>
                        <?php if ($totalProductPages > 1): ?>
                            <nav class="pagination-controls" aria-label="หน้ารายการสินค้า">
                                <?php if ($productPage > 1): ?>
                                    <a href="index.php?page=products&amp;q=<?= rawurlencode($search) ?>&amp;product_page=<?= $productPage - 1 ?>" aria-label="ย้อนกลับ">←</a>
                                <?php else: ?>
                                    <span class="disabled-page" aria-disabled="true">←</span>
                                <?php endif; ?>

                                <?php for ($pageNumber = 1; $pageNumber <= $totalProductPages; $pageNumber++): ?>
                                    <?php if ($pageNumber === $productPage): ?>
                                        <span class="current-page" aria-current="page"><?= $pageNumber ?></span>
                                    <?php else: ?>
                                        <a href="index.php?page=products&amp;q=<?= rawurlencode($search) ?>&amp;product_page=<?= $pageNumber ?>"><?= $pageNumber ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($productPage < $totalProductPages): ?>
                                    <a href="index.php?page=products&amp;q=<?= rawurlencode($search) ?>&amp;product_page=<?= $productPage + 1 ?>" aria-label="หน้าถัดไป">→</a>
                                <?php else: ?>
                                    <span class="disabled-page" aria-disabled="true">→</span>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card" id="barcode-panel" hidden>
                <h2>พิมพ์ฉลากบาร์โค้ด</h2>
                <p class="muted">
                    เลือกจำนวนฉลากที่ต้องการพิมพ์ ไม่จำเป็นต้องเท่ากับสต็อกเสมอไป
                    แต่ค่าเริ่มต้นจะใช้จำนวนสินค้าคงเหลือ
                </p>

                <div class="grid-2">
                    <div class="form-group">
                        <label>ชื่อสินค้า</label>
                        <input id="barcode-name" readonly>
                    </div>
                    <div class="form-group">
                        <label>บาร์โค้ด EAN-13</label>
                        <input id="barcode-code" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label>จำนวนฉลาก</label>
                    <input type="number" id="barcode-quantity" min="1" max="500" value="1">
                    <small>จำกัดครั้งละ 500 ฉลาก เพื่อป้องกันการพิมพ์จำนวนมากโดยไม่ตั้งใจ</small>
                </div>

                <div class="form-actions no-print">
                    <button class="btn-primary" onclick="printBarcodes()">พิมพ์ฉลาก</button>
                    <button class="btn-light" onclick="document.getElementById('barcode-panel').hidden=true">ปิด</button>
                </div>

                <div id="barcode-print-area" class="barcode-labels"></div>
            </section>

        <?php elseif ($page === 'stock'): ?>

            <?php
            $search = trim((string)($_GET['q'] ?? ''));
            $stockPage = max(1, (int)($_GET['stock_page'] ?? 1));
            $stockPerPage = 10;
            $stockWhere = '';
            $stockParams = [];
            if ($search !== '') {
                $stockWhere = ' WHERE code LIKE ? OR name LIKE ?';
                $like = '%' . $search . '%';
                $stockParams = [$like, $like];
            }
            $stockCountStmt = $pdo->prepare('SELECT COUNT(*) FROM products' . $stockWhere);
            $stockCountStmt->execute($stockParams);
            $stockCount = (int)$stockCountStmt->fetchColumn();
            $stockPages = max(1, (int)ceil($stockCount / $stockPerPage));
            $stockPage = min($stockPage, $stockPages);
            $stockOffset = ($stockPage - 1) * $stockPerPage;
            $allStockProducts = $pdo->query('SELECT id, code, name, stock FROM products ORDER BY name')->fetchAll();
            $stockStmt = $pdo->prepare('SELECT * FROM products' . $stockWhere . ' ORDER BY name LIMIT ? OFFSET ?');
            foreach ($stockParams as $i => $value) $stockStmt->bindValue($i + 1, $value, PDO::PARAM_STR);
            $stockStmt->bindValue(count($stockParams) + 1, $stockPerPage, PDO::PARAM_INT);
            $stockStmt->bindValue(count($stockParams) + 2, $stockOffset, PDO::PARAM_INT);
            $stockStmt->execute();
            $stockProducts = $stockStmt->fetchAll();

            $movementPage = max(1, (int)($_GET['movement_page'] ?? 1));
            $movementPerPage = 10;
            $movementCount = (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
            $movementPages = max(1, (int)ceil($movementCount / $movementPerPage));
            $movementPage = min($movementPage, $movementPages);
            $movementOffset = ($movementPage - 1) * $movementPerPage;
            $movementStmt = $pdo->prepare(
                'SELECT sm.*, p.code, p.name, u.username
                 FROM stock_movements sm
                 JOIN products p ON p.id=sm.product_id
                 LEFT JOIN users u ON u.id=sm.user_id
                 ORDER BY sm.id DESC LIMIT ? OFFSET ?'
            );
            $movementStmt->bindValue(1, $movementPerPage, PDO::PARAM_INT);
            $movementStmt->bindValue(2, $movementOffset, PDO::PARAM_INT);
            $movementStmt->execute();
            $movements = $movementStmt->fetchAll();
            ?>

            <div class="page-heading">
                <div>
                    <h1>สต็อกสินค้า</h1>
                    <p>รับสินค้าเข้า ตรวจยอดคงเหลือ และตรวจสอบประวัติการเคลื่อนไหว</p>
                </div>
            </div>

            <section class="card">
                <h2>รับสินค้าเข้าสต็อก</h2>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="receive_stock">
                    <input type="hidden" name="return_page" value="stock">

                    <div class="grid-3">
                        <div class="form-group">
                            <label>สินค้า</label>
                            <select name="product_id" required>
                                <option value="">เลือกสินค้า</option>
                                <?php foreach ($allStockProducts as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>">
                                        <?= e($p['code'] . ' - ' . $p['name']) ?>
                                        (คงเหลือ <?= (int)$p['stock'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>จำนวนรับเข้า (ชิ้น)</label>
                            <input type="number" name="quantity" min="1" max="100000000" required>
                        </div>

                        <div class="form-group">
                            <label>หมายเหตุ</label>
                            <input name="note" maxlength="255" placeholder="เช่น รับของจากซัพพลายเออร์">
                        </div>
                    </div>

                    <button class="btn-primary">บันทึกรับสินค้า</button>
                </form>
            </section>

            <?php if (($user['role'] ?? '') === 'admin'): ?>
            <section class="card">
                <h2>ปรับยอดสต็อกให้ตรงกับของจริง</h2>
                <p class="muted">
                    ใช้กรณีตรวจนับสินค้าแล้วจำนวนจริงไม่ตรงกับระบบ
                    ระบบจะบันทึกส่วนต่างลงในประวัติ
                </p>

                <form class="js-confirm-form" method="post" data-confirm="ยืนยันปรับยอดสต็อก?">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="adjust_stock">
                    <input type="hidden" name="return_page" value="stock">

                    <div class="grid-3">
                        <div class="form-group">
                            <label>สินค้า</label>
                            <select name="product_id" required>
                                <option value="">เลือกสินค้า</option>
                                <?php foreach ($allStockProducts as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>">
                                        <?= e($p['code'] . ' - ' . $p['name']) ?>
                                        (<?= (int)$p['stock'] ?> ชิ้น)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>ยอดคงเหลือจริง</label>
                            <input type="number" name="new_stock" min="0" max="100000000" required>
                        </div>

                        <div class="form-group">
                            <label>เหตุผล</label>
                            <input name="note" maxlength="255" required
                                   placeholder="เช่น ตรวจนับประจำวัน">
                        </div>
                    </div>

                    <button class="btn-primary">ปรับสต็อก</button>
                </form>
            </section>
            <?php endif; ?>

            <section class="card">
                <h2>ยอดคงเหลือสินค้า <span class="muted" style="font-size:13px;font-weight:400">ทั้งหมด <?= number_format($stockCount) ?> รายการ</span></h2>

                <form class="toolbar" method="get">
                    <input type="hidden" name="page" value="stock">
                    <input name="q" value="<?= e($search) ?>" placeholder="ค้นหารหัสหรือชื่อสินค้า">
                    <button class="btn-primary">ค้นหา</button>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>รหัส</th><th>สินค้า</th><th>หมวดหมู่</th><th>คงเหลือ</th><th>ราคาหน้าร้าน</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stockProducts as $p): ?>
                            <tr>
                                <td><?= e($p['code']) ?></td>
                                <td><?= e($p['name']) ?></td>
                                <td><?= e($p['category']) ?></td>
                                <td>
                                    <span class="badge <?= (int)$p['stock'] === 0 ? 'badge-danger' : '' ?>">
                                        <?= number_format((int)$p['stock']) ?>
                                    </span>
                                </td>
                                <td><?= money($p['price_retail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-bar">
                    <div class="pagination-summary">แสดง <?= $stockCount ? number_format($stockOffset + 1) . '–' . number_format(min($stockOffset + $stockPerPage, $stockCount)) : '0' ?> จากทั้งหมด <?= number_format($stockCount) ?> รายการ</div>
                    <?php if ($stockPages > 1): ?>
                        <nav class="pagination-controls" aria-label="หน้าสต็อกสินค้า">
                            <?php if ($stockPage > 1): ?><a href="index.php?page=stock&amp;q=<?= rawurlencode($search) ?>&amp;stock_page=<?= $stockPage - 1 ?>&amp;movement_page=<?= $movementPage ?>">←</a><?php else: ?><span class="disabled-page">←</span><?php endif; ?>
                            <?php for ($pg = max(1, $stockPage - 2); $pg <= min($stockPages, $stockPage + 2); $pg++): ?>
                                <?php if ($pg === $stockPage): ?><span class="current-page"><?= $pg ?></span><?php else: ?><a href="index.php?page=stock&amp;q=<?= rawurlencode($search) ?>&amp;stock_page=<?= $pg ?>&amp;movement_page=<?= $movementPage ?>"><?= $pg ?></a><?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($stockPage < $stockPages): ?><a href="index.php?page=stock&amp;q=<?= rawurlencode($search) ?>&amp;stock_page=<?= $stockPage + 1 ?>&amp;movement_page=<?= $movementPage ?>">→</a><?php else: ?><span class="disabled-page">→</span><?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <h2>ประวัติการเคลื่อนไหวสต็อก <span class="muted" style="font-size:13px;font-weight:400">ทั้งหมด <?= number_format($movementCount) ?> รายการ</span></h2>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>วันที่</th><th>รหัส</th><th>สินค้า</th>
                                <th>ประเภท</th><th>จำนวนเปลี่ยนแปลง</th><th>หมายเหตุ</th><th>ผู้ทำรายการ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($movements as $m): ?>
                            <?php
                            $typeLabels = [
                                'opening' => 'ยอดเริ่มต้น',
                                'receive' => 'รับเข้า',
                                'sale' => 'ขายออก',
                                'adjustment' => 'ปรับสต็อก',
                                'return' => 'คืนสินค้า',
                            ];
                            ?>
                            <tr>
                                <td><?= e($m['created_at']) ?></td>
                                <td><?= e($m['code']) ?></td>
                                <td><?= e($m['name']) ?></td>
                                <td><?= e($typeLabels[$m['type']] ?? $m['type']) ?></td>
                                <td>
                                    <?= (int)$m['quantity'] > 0 ? '+' : '' ?>
                                    <?= number_format((int)$m['quantity']) ?>
                                </td>
                                <td><?= e($m['note']) ?></td>
                                <td><?= e($m['username'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar">
                    <div class="pagination-summary">แสดง <?= $movementCount ? number_format($movementOffset + 1) . '–' . number_format(min($movementOffset + $movementPerPage, $movementCount)) : '0' ?> จากทั้งหมด <?= number_format($movementCount) ?> รายการ</div>
                    <?php if ($movementPages > 1): ?>
                        <nav class="pagination-controls" aria-label="หน้าประวัติสต็อก">
                            <?php if ($movementPage > 1): ?><a href="index.php?page=stock&amp;q=<?= rawurlencode($search) ?>&amp;stock_page=<?= $stockPage ?>&amp;movement_page=<?= $movementPage - 1 ?>">←</a><?php else: ?><span class="disabled-page">←</span><?php endif; ?>
                            <?php for ($pg = max(1, $movementPage - 2); $pg <= min($movementPages, $movementPage + 2); $pg++): ?>
                                <?php if ($pg === $movementPage): ?><span class="current-page"><?= $pg ?></span><?php else: ?><a href="index.php?page=stock&amp;q=<?= rawurlencode($search) ?>&amp;stock_page=<?= $stockPage ?>&amp;movement_page=<?= $pg ?>"><?= $pg ?></a><?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($movementPage < $movementPages): ?><a href="index.php?page=stock&amp;q=<?= rawurlencode($search) ?>&amp;stock_page=<?= $stockPage ?>&amp;movement_page=<?= $movementPage + 1 ?>">→</a><?php else: ?><span class="disabled-page">→</span><?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </div>
                <?php if (!$movements): ?>
                    <div class="empty">ยังไม่มีประวัติสต็อก</div>
                <?php endif; ?>
            </section>

        <?php elseif ($page === 'pos'): ?>

            <?php
            $posProducts = $pdo->query(
                'SELECT id, code, barcode, name, stock, price_base,
                        price_wholesale, price_retail, price_general
                 FROM products WHERE stock > 0
                 ORDER BY name LIMIT 1000'
            )->fetchAll();
            ?>

            <div class="page-heading">
                <div>
                    <h1>ขายสินค้า</h1>
                    <p>ค้นหาสินค้า สแกนบาร์โค้ด และจัดการรายการขาย</p>
                </div>
            </div>

            <section class="card">
                <h2>ค้นหาหรือสแกนสินค้า</h2>
                <div class="scan-row">
                    <input id="scan-input" autocomplete="off" placeholder="ยิงบาร์โค้ดที่นี่ หรือพิมพ์รหัสสินค้า">
                    <button type="button" class="btn-primary" onclick="addByCode()">เพิ่ม</button>
                </div>
                <p class="muted">เครื่องสแกน USB ส่วนใหญ่ส่งรหัสผ่านคีย์บอร์ดและกด Enter อัตโนมัติ</p>
                <div class="form-group">
                    <label>ค้นหาจากรายการสินค้า</label>
                    <input id="product-search" placeholder="พิมพ์ชื่อหรือรหัสสินค้า">
                </div>
                <div class="pos-toolbar-actions">
                    <button id="camera-open-btn" type="button" class="btn-primary" onclick="openCameraPopup()">📷 เปิดกล้องสแกนบาร์โค้ด</button>
                    <button id="cart-open-btn" type="button" class="btn-success" onclick="openCartPopup()">🛒 เปิดตะกร้าสินค้า <span id="cart-count">(0)</span></button>
                </div>
                <div class="table-wrap">
                    <table id="product-list">
                        <thead><tr><th>รหัส</th><th>สินค้า</th><th>คงเหลือ</th><th>เพิ่ม</th></tr></thead>
                        <tbody>
                        <?php foreach ($posProducts as $p): ?>
                            <tr data-code="<?= e(mb_strtolower($p['code'])) ?>" data-name="<?= e(mb_strtolower($p['name'])) ?>">
                                <td><?= e($p['code']) ?></td><td><?= e($p['name']) ?></td><td><?= (int)$p['stock'] ?></td>
                                <td><button type="button" class="btn-light btn-small" onclick="addProduct(<?= (int)$p['id'] ?>)">＋ เพิ่ม</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-bar">
                    <div class="pagination-summary" id="pos-product-summary">แสดงรายการสินค้า</div>
                    <nav class="pagination-controls" id="pos-product-pagination" aria-label="หน้ารายการสินค้าเพื่อขาย"></nav>
                </div>
            </section>

            <div id="camera-popup" class="popup-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="camera-popup-title">
                <div class="popup-dialog combined-scan-dialog">
                    <div class="popup-header combined-popup-header">
                        <h2>สแกนบาร์โค้ดและตะกร้าสินค้า</h2>
                        <button type="button" class="btn-light" onclick="closeCameraPopup()" aria-label="ปิดหน้าต่าง">✕ ปิด</button>
                    </div>

                    <div class="combined-scan-layout">
                        <section class="combined-camera-column" aria-labelledby="camera-popup-title">
                            <h3 id="camera-popup-title">📷 สแกนบาร์โค้ด</h3>
                            <div id="camera-area" class="camera-panel">
                                <div class="camera-preview"><video id="camera-video" autoplay muted playsinline></video><div class="camera-guide" aria-hidden="true"></div></div>
                                <div class="camera-status-row"><span class="camera-live-dot" aria-hidden="true"></span><span id="camera-status" role="status" aria-live="polite">กดปุ่มเปิดกล้องเพื่อเริ่มสแกน</span></div>
                                <p class="muted camera-help">กล้องจะเปิดค้างไว้หลังสแกนแต่ละครั้ง หากต้องการหยุด ให้กด “ปิดกล้อง”</p>
                            </div>
                            <div class="popup-actions camera-controls">
                                <button id="camera-start-btn" class="btn-primary" type="button" onclick="startCamera()">📷 เปิดกล้อง</button>
                                <button id="camera-stop-btn" class="btn-light" type="button" onclick="stopCamera()" disabled>ปิดกล้อง</button>
                            </div>
                            <p class="muted combined-camera-hint">สแกนแล้ว รายการจะเพิ่มในตะกร้าด้านขวาทันที</p>
                        </section>

                        <section class="combined-cart-column" aria-labelledby="cart-popup-title">
                            <h3 id="cart-popup-title">🛒 ตะกร้าสินค้า <span class="muted" id="cart-popup-count">(0 รายการ)</span></h3>
                            <form method="post" id="sale-form">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="save_sale">
                                <input type="hidden" name="return_page" value="pos">
                                <input type="hidden" name="items" id="sale-items">
                                <div class="form-group"><label>ประเภทราคาที่ใช้กับบิลนี้</label><select name="price_type" id="price-type"><option value="retail">ราคาหน้าร้าน</option><option value="wholesale">ราคาขายส่ง</option><option value="general">ราคาทั่วไป</option><option value="base">ราคากลาง</option></select></div>
                                <div class="table-wrap combined-cart-table-wrap"><table class="cart-table"><thead><tr><th>สินค้า</th><th>จำนวน</th><th>ราคา</th><th>รวม</th><th></th></tr></thead><tbody id="cart-body"><tr><td colspan="5" class="empty">ยังไม่มีสินค้าในตะกร้า</td></tr></tbody></table></div>
                                <div class="form-group" style="margin-top:15px"><label>ส่วนลดท้ายบิล (บาท)</label><input type="number" name="discount" id="discount" min="0" step="0.01" value="0"></div>
                                <div class="form-group"><label>หมายเหตุ</label><input name="note" maxlength="255" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></div>
                                <div class="muted">ยอดก่อนส่วนลด</div><div id="subtotal" class="price">฿0.00</div>
                                <div class="muted" style="margin-top:10px">ยอดสุทธิ</div><div class="cart-total" id="total">฿0.00</div>
                                <div class="popup-actions combined-cart-actions"><button class="btn-primary" type="submit">บันทึกการขาย</button><button class="btn-light" type="button" onclick="clearCart()">ล้างตะกร้า</button></div>
                            </form>
                        </section>
                    </div>
                </div>
            </div>

            <script>
            /*
             * ข้อมูลสินค้าใช้สำหรับแสดงรายการและคำนวณตัวอย่างบนหน้าจอเท่านั้น
             * ฝั่งเซิร์ฟเวอร์จะตรวจสอบราคาและสต็อกใหม่ก่อนบันทึกทุกครั้ง
             */
            const products = <?= json_encode(
                array_map(static function (array $p): array {
                    return [
                        'id' => (int)$p['id'],
                        'code' => $p['code'],
                        'barcode' => $p['barcode'],
                        'name' => $p['name'],
                        'stock' => (int)$p['stock'],
                        'price_base' => (float)$p['price_base'],
                        'price_wholesale' => (float)$p['price_wholesale'],
                        'price_retail' => (float)$p['price_retail'],
                        'price_general' => (float)$p['price_general'],
                    ];
                }, $posProducts),
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS |
                JSON_HEX_QUOT | JSON_HEX_AMP
            ) ?>;

            const productMap = new Map(products.map(p => [p.id, p]));
            let cart = new Map();

            const priceColumns = {
                retail: 'price_retail',
                wholesale: 'price_wholesale',
                general: 'price_general',
                base: 'price_base'
            };

            const moneyJS = value =>
                Number(value).toLocaleString('th-TH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

            function addProduct(id) {
                const p = productMap.get(Number(id));

                if (!p) {
                    alert('ไม่พบสินค้า');
                    return;
                }

                const current = cart.get(p.id)?.qty || 0;

                if (current + 1 > p.stock) {
                    alert('สินค้าไม่เพียงพอ คงเหลือ ' + p.stock + ' ชิ้น');
                    return;
                }

                cart.set(p.id, {id: p.id, qty: current + 1});
                renderCart();
            }

            function addByCode() {
                const input = document.getElementById('scan-input');
                const code = input.value.trim();

                if (!code) return;

                const p = products.find(item => item.code === code || item.barcode === code);

                if (!p) {
                    alert('ไม่พบรหัสสินค้า: ' + code);
                    input.select();
                    return;
                }

                addProduct(p.id);
                input.value = '';
                input.focus();
            }

            function removeProduct(id) {
                cart.delete(Number(id));
                renderCart();
            }

            function changeQuantity(id, value) {
                const p = productMap.get(Number(id));
                const qty = Number.parseInt(value, 10);

                if (!p || !Number.isInteger(qty) || qty < 1) {
                    removeProduct(id);
                    return;
                }

                if (qty > p.stock) {
                    alert('สินค้าไม่เพียงพอ คงเหลือ ' + p.stock + ' ชิ้น');
                    renderCart();
                    return;
                }

                cart.set(p.id, {id: p.id, qty});
                renderCart();
            }

            function renderCart() {
                const body = document.getElementById('cart-body');
                const priceType = document.getElementById('price-type').value;

                body.replaceChildren();

                let subtotal = 0;
                const items = [];

                for (const item of cart.values()) {
                    const p = productMap.get(item.id);
                    if (!p) continue;

                    const price = Number(p[priceColumns[priceType]]);
                    const line = Math.round(price * item.qty * 100) / 100;
                    subtotal += line;

                    items.push({id: p.id, qty: item.qty});

                    const tr = document.createElement('tr');

                    const name = document.createElement('td');
                    name.textContent = p.name;

                    const qtyCell = document.createElement('td');
                    const qtyInput = document.createElement('input');
                    qtyInput.type = 'number';
                    qtyInput.min = '1';
                    qtyInput.max = String(p.stock);
                    qtyInput.value = String(item.qty);
                    qtyInput.addEventListener('change', () =>
                        changeQuantity(p.id, qtyInput.value)
                    );
                    qtyCell.appendChild(qtyInput);

                    const priceCell = document.createElement('td');
                    priceCell.textContent = moneyJS(price);

                    const lineCell = document.createElement('td');
                    lineCell.textContent = moneyJS(line);

                    const removeCell = document.createElement('td');
                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'btn-danger btn-small';
                    removeButton.textContent = '×';
                    removeButton.addEventListener('click', () => removeProduct(p.id));
                    removeCell.appendChild(removeButton);

                    tr.append(name, qtyCell, priceCell, lineCell, removeCell);
                    body.appendChild(tr);
                }

                if (items.length === 0) {
                    const tr = document.createElement('tr');
                    const td = document.createElement('td');
                    td.colSpan = 5;
                    td.className = 'empty';
                    td.textContent = 'ยังไม่มีสินค้าในตะกร้า';
                    tr.appendChild(td);
                    body.appendChild(tr);
                }

                const discount = Math.max(
                    0,
                    Number(document.getElementById('discount').value) || 0
                );

                document.getElementById('subtotal').textContent =
                    '฿' + moneyJS(subtotal);

                document.getElementById('total').textContent =
                    '฿' + moneyJS(Math.max(0, subtotal - discount));

                document.getElementById('sale-items').value = JSON.stringify(items);
                const count = Array.from(cart.values()).reduce((sum, item) => sum + item.qty, 0);
                const countButton = document.getElementById('cart-count');
                const popupCount = document.getElementById('cart-popup-count');
                if (countButton) countButton.textContent = '(' + count + ')';
                if (popupCount) popupCount.textContent = '(' + cart.size + ' รายการ / ' + count + ' ชิ้น)';
            }

            async function clearCart() {
                const accepted = await window.showConfirm('ต้องการล้างตะกร้าสินค้าหรือไม่?');
                if (!accepted) return;
                cart.clear();
                renderCart();
                document.getElementById('scan-input').focus();
            }

            document.getElementById('scan-input').addEventListener('keydown', event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    addByCode();
                }
            });

            const productRows = Array.from(document.querySelectorAll('#product-list tbody tr'));
            const posProductsPerPage = 10;
            let posProductPage = 1;

            function renderProductPage() {
                const q = document.getElementById('product-search').value.trim().toLocaleLowerCase();
                const filteredRows = productRows.filter(row => {
                    const code = row.dataset.code || '';
                    const name = row.dataset.name || '';
                    return code.includes(q) || name.includes(q);
                });
                const pages = Math.max(1, Math.ceil(filteredRows.length / posProductsPerPage));
                posProductPage = Math.min(posProductPage, pages);
                const start = (posProductPage - 1) * posProductsPerPage;
                productRows.forEach(row => { row.hidden = true; });
                filteredRows.slice(start, start + posProductsPerPage).forEach(row => { row.hidden = false; });
                document.getElementById('pos-product-summary').textContent =
                    `แสดง ${filteredRows.length ? start + 1 : 0}–${Math.min(start + posProductsPerPage, filteredRows.length)} จากทั้งหมด ${filteredRows.length} รายการ`;
                const nav = document.getElementById('pos-product-pagination');
                nav.replaceChildren();
                const addPageButton = (label, page, active = false, disabled = false) => {
                    const el = document.createElement(disabled || active ? 'span' : 'a');
                    el.textContent = label;
                    if (active) el.className = 'current-page';
                    if (disabled) el.className = 'disabled-page';
                    if (!active && !disabled) {
                        el.href = '#product-list';
                        el.addEventListener('click', event => { event.preventDefault(); posProductPage = page; renderProductPage(); });
                    }
                    nav.appendChild(el);
                };
                addPageButton('←', Math.max(1, posProductPage - 1), false, posProductPage === 1);
                for (let page = Math.max(1, posProductPage - 2); page <= Math.min(pages, posProductPage + 2); page++) {
                    addPageButton(String(page), page, page === posProductPage, false);
                }
                addPageButton('→', Math.min(pages, posProductPage + 1), false, posProductPage === pages);
            }

            document.getElementById('product-search').addEventListener('input', () => {
                posProductPage = 1;
                renderProductPage();
            });

            function openCameraPopup() {
                document.getElementById('camera-popup').hidden = false;
                renderCart();
                if (!cameraActive && !cameraStarting) startCamera();
            }
            function closeCameraPopup() {
                stopCamera();
                document.getElementById('camera-popup').hidden = true;
                document.getElementById('scan-input').focus();
            }
            function openCartPopup() {
                document.getElementById('camera-popup').hidden = false;
                renderCart();
            }
            function closeCartPopup() {
                closeCameraPopup();
            }
            document.querySelectorAll('.popup-overlay').forEach(overlay => {
                overlay.addEventListener('click', event => {
                    if (event.target === overlay) closeCameraPopup();
                });
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && !document.getElementById('camera-popup').hidden) {
                    closeCameraPopup();
                }
            });

            document.getElementById('price-type').addEventListener('change', renderCart);
            document.getElementById('discount').addEventListener('input', renderCart);

            let saleSubmitting = false;
            document.getElementById('sale-form').addEventListener('submit', async event => {
                if (saleSubmitting) return;
                event.preventDefault();

                if (cart.size === 0) {
                    window.showAlert('กรุณาเพิ่มสินค้าก่อนบันทึกการขาย', 'error');
                    return;
                }

                const items = Array.from(cart.values());
                const subtotal = items.reduce((sum, item) => {
                    const p = productMap.get(item.id);
                    return sum + Number(p[priceColumns[
                        document.getElementById('price-type').value
                    ]]) * item.qty;
                }, 0);

                const discount = Number(document.getElementById('discount').value) || 0;
                if (discount < 0 || discount > subtotal) {
                    window.showAlert('กรุณาตรวจสอบส่วนลด', 'error');
                    return;
                }

                const accepted = await window.showConfirm(
                    'ยืนยันบันทึกการขาย ยอดสุทธิ ฿' + moneyJS(subtotal - discount) + ' ?'
                );
                if (accepted) {
                    saleSubmitting = true;
                    event.target.requestSubmit();
                }
            });

            let cameraStream = null;
            let cameraActive = false;
            let cameraStarting = false;
            let cameraDetector = null;
            let scanFramePending = false;
            let lastCameraCode = '';
            let lastCameraCodeSeenAt = 0;

            async function startCamera() {
                if (cameraActive || cameraStarting) return;

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    alert('เบราว์เซอร์นี้ไม่สามารถเปิดกล้องได้ กรุณาใช้ HTTPS หรือ localhost และอนุญาตการใช้กล้อง');
                    return;
                }

                if (!('BarcodeDetector' in window)) {
                    alert('เบราว์เซอร์นี้ยังไม่รองรับการสแกนบาร์โค้ดด้วยกล้อง กรุณาใช้ Chrome/Edge รุ่นล่าสุด หรือเครื่องสแกน USB');
                    return;
                }

                cameraStarting = true;
                const startButton = document.getElementById('camera-start-btn');
                const stopButton = document.getElementById('camera-stop-btn');
                const area = document.getElementById('camera-area');
                const status = document.getElementById('camera-status');
                startButton.disabled = true;
                startButton.textContent = 'กำลังเปิดกล้อง…';

                try {
                    const supported = await BarcodeDetector.getSupportedFormats();
                    const formats = supported.filter(f =>
                        ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e', 'itf', 'codabar'].includes(f)
                    );

                    if (!formats.length) {
                        throw new Error('เบราว์เซอร์ไม่รองรับรูปแบบบาร์โค้ดที่ต้องการ');
                    }

                    cameraDetector = new BarcodeDetector({formats});
                    cameraStream = await navigator.mediaDevices.getUserMedia({
                        video: {facingMode: {ideal: 'environment'}, width: {ideal: 1280}, height: {ideal: 720}},
                        audio: false
                    });

                    const video = document.getElementById('camera-video');
                    video.srcObject = cameraStream;
                    area.hidden = false;
                    await video.play();

                    cameraActive = true;
                    lastCameraCode = '';
                    lastCameraCodeSeenAt = 0;
                    startButton.textContent = '📷 กล้องเปิดอยู่';
                    stopButton.disabled = false;
                    status.textContent = 'กล้องพร้อมสแกน — กล้องจะไม่ปิดหลังสแกน';
                    scanCameraFrame();
                } catch (error) {
                    console.error('Camera start error:', error);
                    stopCamera();
                    alert(error && error.name === 'NotAllowedError'
                        ? 'ไม่ได้รับอนุญาตให้ใช้กล้อง กรุณาอนุญาต Camera ในการตั้งค่าเว็บไซต์'
                        : 'เปิดกล้องไม่สำเร็จ กรุณาตรวจสอบสิทธิ์กล้อง และเปิดเว็บผ่าน HTTPS หรือ localhost');
                } finally {
                    cameraStarting = false;
                    if (!cameraActive) {
                        startButton.disabled = false;
                        startButton.textContent = '📷 เปิดกล้อง';
                    }
                }
            }

            async function scanCameraFrame() {
                if (!cameraActive || scanFramePending) return;
                scanFramePending = true;
                try {
                    const video = document.getElementById('camera-video');
                    if (video && video.readyState >= 2 && cameraDetector) {
                        const codes = await cameraDetector.detect(video);
                        const detected = codes.find(item => item.rawValue && item.rawValue.trim());
                        const now = Date.now();

                        if (detected) {
                            const value = detected.rawValue.trim();
                            lastCameraCodeSeenAt = now;
                            if (value !== lastCameraCode) {
                                lastCameraCode = value;
                                const input = document.getElementById('scan-input');
                                input.value = value;
                                addByCode();
                                const status = document.getElementById('camera-status');
                                if (status) status.textContent = 'สแกนแล้ว: ' + value + ' — พร้อมสแกนรายการถัดไป';
                            }
                        } else if (lastCameraCode && now - lastCameraCodeSeenAt > 900) {
                            // ยอมให้สแกนรหัสเดิมซ้ำได้ หลังนำบาร์โค้ดออกจากภาพแล้ว
                            lastCameraCode = '';
                            const status = document.getElementById('camera-status');
                            if (status) status.textContent = 'กล้องพร้อมสแกนรายการถัดไป';
                        }
                    }
                } catch (error) {
                    // ข้ามเฟรมที่อ่านไม่ได้ แต่คงกล้องไว้และลองเฟรมถัดไป
                    console.debug('Camera scan frame skipped:', error);
                } finally {
                    scanFramePending = false;
                    if (cameraActive) requestAnimationFrame(scanCameraFrame);
                }
            }

            function stopCamera() {
                cameraActive = false;
                cameraStarting = false;
                cameraDetector = null;
                scanFramePending = false;

                if (cameraStream) {
                    cameraStream.getTracks().forEach(track => track.stop());
                    cameraStream = null;
                }

                const video = document.getElementById('camera-video');
                if (video) video.srcObject = null;

                const area = document.getElementById('camera-area');
                if (area) area.hidden = false;
                const status = document.getElementById('camera-status');
                if (status) status.textContent = 'กล้องปิดแล้ว กด “เปิดกล้อง” เพื่อเริ่มสแกนอีกครั้ง';

                const startButton = document.getElementById('camera-start-btn');
                const stopButton = document.getElementById('camera-stop-btn');
                if (startButton) {
                    startButton.disabled = false;
                    startButton.textContent = '📷 เปิดกล้องสแกนบาร์โค้ด';
                }
                if (stopButton) stopButton.disabled = true;
                lastCameraCode = '';
                lastCameraCodeSeenAt = 0;
            }

            window.addEventListener('pagehide', stopCamera);

            renderCart();
            renderProductPage();
            document.getElementById('scan-input').focus();
            </script>

        <?php elseif ($page === 'sales'): ?>

            <?php
            $from = trim((string)($_GET['from'] ?? date('Y-m-01')));
            $to = trim((string)($_GET['to'] ?? date('Y-m-d')));

            $validDate = static function (string $value): bool {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                return $date !== false && $date->format('Y-m-d') === $value;
            };

            if (!$validDate($from) || !$validDate($to) || $from > $to) {
                $from = date('Y-m-01');
                $to = date('Y-m-d');
            }

            $countSalesStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM sales
                 WHERE created_at >= ?
                   AND created_at < DATE_ADD(?, INTERVAL 1 DAY)'
            );
            $countSalesStmt->execute([$from, $to]);
            $totalSales = (int)$countSalesStmt->fetchColumn();

            $sumSalesStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(total), 0) FROM sales
                 WHERE created_at >= ?
                   AND created_at < DATE_ADD(?, INTERVAL 1 DAY)'
            );
            $sumSalesStmt->execute([$from, $to]);
            $sumSales = (float)$sumSalesStmt->fetchColumn();

            $salesPerPage = 10;
            $totalSalesPages = max(1, (int)ceil($totalSales / $salesPerPage));
            $salesPage = filter_var($_GET['sales_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $salesPage = $salesPage === false ? 1 : min((int)$salesPage, $totalSalesPages);
            $salesOffset = ($salesPage - 1) * $salesPerPage;

            $stmt = $pdo->prepare(
                'SELECT s.*, u.username
                 FROM sales s LEFT JOIN users u ON u.id=s.user_id
                 WHERE s.created_at >= ?
                   AND s.created_at < DATE_ADD(?, INTERVAL 1 DAY)
                 ORDER BY s.id DESC LIMIT ? OFFSET ?'
            );
            $stmt->execute([$from, $to, $salesPerPage, $salesOffset]);
            $sales = $stmt->fetchAll();
            ?>

            <div class="page-heading">
                <div>
                    <h1>ประวัติการขาย</h1>
                    <p>ตรวจสอบบิลขายย้อนหลัง</p>
                </div>
            </div>

            <section class="card">
                <form class="toolbar" method="get">
                    <input type="hidden" name="page" value="sales">

                    <div>
                        <label>ตั้งแต่วันที่</label>
                        <input type="date" name="from" value="<?= e($from) ?>">
                    </div>

                    <div>
                        <label>ถึงวันที่</label>
                        <input type="date" name="to" value="<?= e($to) ?>">
                    </div>

                    <button class="btn-primary" style="align-self:end">ค้นหา</button>
                </form>

                <div class="stats-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                    <div class="stat-card">
                        <div class="stat-label">จำนวนบิลในผลลัพธ์</div>
                        <div class="stat-value"><?= number_format($totalSales) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">ยอดขายสุทธิในผลลัพธ์</div>
                        <div class="stat-value">฿<?= money($sumSales) ?></div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>เลขที่บิล</th><th>วันที่</th><th>พนักงาน</th>
                                <th>ประเภทราคา</th><th>ก่อนส่วนลด</th><th>ส่วนลด</th><th>ยอดสุทธิ</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sales as $s): ?>
                            <tr>
                                <td><?= e($s['receipt_no']) ?></td>
                                <td><?= e($s['created_at']) ?></td>
                                <td><?= e($s['username'] ?? '-') ?></td>
                                <td><?= e(price_label($s['price_type'])) ?></td>
                                <td><?= money($s['subtotal']) ?></td>
                                <td><?= money($s['discount']) ?></td>
                                <td><strong><?= money($s['total']) ?></strong></td>
                                <td>
                                    <a class="btn-light btn-small"
                                       href="index.php?page=receipt&id=<?= (int)$s['id'] ?>">
                                        ดูใบเสร็จ
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!$sales): ?>
                    <div class="empty">ไม่พบรายการขายในช่วงวันที่นี้</div>
                <?php endif; ?>

                <?php if ($totalSales > 0): ?>
                    <div class="pagination-bar">
                        <div class="pagination-summary">
                            แสดง <?= number_format($salesOffset + 1) ?>–<?= number_format(min($salesOffset + count($sales), $totalSales)) ?>
                            จากทั้งหมด <?= number_format($totalSales) ?> บิล
                        </div>
                        <?php if ($totalSalesPages > 1): ?>
                            <nav class="pagination-controls" aria-label="หน้าประวัติการขาย">
                                <?php if ($salesPage > 1): ?>
                                    <a href="index.php?page=sales&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;sales_page=<?= $salesPage - 1 ?>" aria-label="ย้อนกลับ">←</a>
                                <?php else: ?>
                                    <span class="disabled-page" aria-disabled="true">←</span>
                                <?php endif; ?>

                                <?php for ($pageNumber = 1; $pageNumber <= $totalSalesPages; $pageNumber++): ?>
                                    <?php if ($pageNumber === $salesPage): ?>
                                        <span class="current-page" aria-current="page"><?= $pageNumber ?></span>
                                    <?php else: ?>
                                        <a href="index.php?page=sales&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;sales_page=<?= $pageNumber ?>"><?= $pageNumber ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($salesPage < $totalSalesPages): ?>
                                    <a href="index.php?page=sales&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;sales_page=<?= $salesPage + 1 ?>" aria-label="หน้าถัดไป">→</a>
                                <?php else: ?>
                                    <span class="disabled-page" aria-disabled="true">→</span>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($page === 'report'): ?>

            <?php
            $reportSearch = trim((string)($_GET['q'] ?? ''));
            $reportPage = max(1, (int)($_GET['report_page'] ?? 1));
            $reportPerPage = 10;

            $whereSql = '';
            $reportParams = [];
            if ($reportSearch !== '') {
                $whereSql = ' WHERE p.code LIKE ? OR p.name LIKE ?';
                $like = '%' . $reportSearch . '%';
                $reportParams = [$like, $like];
            }

            $countSql = "SELECT COUNT(*) FROM products p" . $whereSql;
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($reportParams);
            $totalReportProducts = (int)$countStmt->fetchColumn();
            $totalReportPages = max(1, (int)ceil($totalReportProducts / $reportPerPage));
            $reportPage = min($reportPage, $totalReportPages);
            $reportOffset = ($reportPage - 1) * $reportPerPage;

            $totalsSql = "
                SELECT COALESCE(SUM(product_sales.sold_quantity), 0) AS total_sold,
                       COALESCE(SUM(product_sales.gross_sales), 0) AS total_gross
                FROM (
                    SELECT p.id,
                           COALESCE(SUM(si.quantity), 0) AS sold_quantity,
                           COALESCE(SUM(si.line_total), 0) AS gross_sales
                    FROM products p
                    LEFT JOIN sale_items si ON si.product_id = p.id
                    $whereSql
                    GROUP BY p.id
                ) AS product_sales
            ";
            $totalsStmt = $pdo->prepare($totalsSql);
            $totalsStmt->execute($reportParams);
            $reportTotals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
            $totalSold = (int)($reportTotals['total_sold'] ?? 0);
            $totalGross = (float)($reportTotals['total_gross'] ?? 0);

            $sql = "
                SELECT p.id, p.code, p.name, p.category, p.stock,
                       COALESCE(SUM(si.quantity), 0) AS sold_quantity,
                       COALESCE(SUM(si.line_total), 0) AS gross_sales
                FROM products p
                LEFT JOIN sale_items si ON si.product_id = p.id
                $whereSql
                GROUP BY p.id, p.code, p.name, p.category, p.stock
                ORDER BY gross_sales DESC, p.name ASC
                LIMIT ? OFFSET ?
            ";

            $stmt = $pdo->prepare($sql);
            $queryParams = $reportParams;
            $queryParams[] = $reportPerPage;
            $queryParams[] = $reportOffset;
            foreach ($queryParams as $i => $value) {
                $stmt->bindValue($i + 1, $value, ($i >= count($reportParams)) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            $reportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $reportStart = $totalReportProducts > 0 ? $reportOffset + 1 : 0;
            $reportEnd = min($reportOffset + $reportPerPage, $totalReportProducts);
            ?>

            <div class="page-heading">
                <div>
                    <h1>รายงานสินค้า</h1>
                    <p>ยอดขายสะสมและจำนวนที่ขายได้ของสินค้าแต่ละรายการ · ทั้งหมด <?= number_format($totalReportProducts) ?> รายการ</p>
                </div>
                <div class="report-actions no-print">
                    <a class="btn-success" href="index.php?page=report&amp;export=excel<?= $reportSearch !== '' ? '&amp;q=' . rawurlencode($reportSearch) : '' ?>">⇩ Export Excel</a>
                    <button class="btn-light" type="button" onclick="window.print()">พิมพ์รายงาน</button>
                </div>
            </div>

            <div class="stats-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                <div class="stat-card">
                    <div class="stat-label">จำนวนชิ้นที่ขายได้สะสม</div>
                    <div class="stat-value"><?= number_format($totalSold) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">ยอดรวมรายการสินค้าที่ขาย</div>
                    <div class="stat-value">฿<?= money($totalGross) ?></div>
                </div>
            </div>

            <section class="card">
                <form class="toolbar no-print" method="get">
                    <input type="hidden" name="page" value="report">
                    <input name="q" value="<?= e($reportSearch) ?>"
                           placeholder="ค้นหาสินค้าจากรหัสหรือชื่อ">
                    <button class="btn-primary">ค้นหา</button>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>รหัส</th><th>สินค้า</th><th>หมวดหมู่</th>
                                <th>ขายสะสม (ชิ้น)</th><th>ยอดขายสะสม</th><th>คงเหลือ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reportRows as $r): ?>
                            <tr>
                                <td><?= e($r['code']) ?></td>
                                <td><?= e($r['name']) ?></td>
                                <td><?= e($r['category']) ?></td>
                                <td><?= number_format((int)$r['sold_quantity']) ?></td>
                                <td class="price"><?= money($r['gross_sales']) ?></td>
                                <td><?= number_format((int)$r['stock']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar no-print">
                    <div class="pagination-summary">
                        แสดง <?= number_format($reportStart) ?>–<?= number_format($reportEnd) ?>
                        จากทั้งหมด <?= number_format($totalReportProducts) ?> รายการ
                    </div>
                    <?php if ($totalReportPages > 1): ?>
                        <nav class="pagination-controls" aria-label="หน้ารายงานสินค้า">
                            <?php if ($reportPage > 1): ?>
                                <a href="index.php?page=report&amp;q=<?= rawurlencode($reportSearch) ?>&amp;report_page=<?= $reportPage - 1 ?>" aria-label="หน้าก่อนหน้า">←</a>
                            <?php else: ?>
                                <span class="disabled-page" aria-disabled="true">←</span>
                            <?php endif; ?>

                            <?php
                            $reportPageStart = max(1, $reportPage - 2);
                            $reportPageEnd = min($totalReportPages, $reportPage + 2);
                            if ($reportPageStart > 1) {
                                echo '<a href="index.php?page=report&amp;q=' . rawurlencode($reportSearch) . '&amp;report_page=1">1</a>';
                                if ($reportPageStart > 2) echo '<span class="pagination-ellipsis">…</span>';
                            }
                            for ($p = $reportPageStart; $p <= $reportPageEnd; $p++):
                                if ($p === $reportPage): ?>
                                    <span class="current-page" aria-current="page"><?= $p ?></span>
                                <?php else: ?>
                                    <a href="index.php?page=report&amp;q=<?= rawurlencode($reportSearch) ?>&amp;report_page=<?= $p ?>"><?= $p ?></a>
                                <?php endif;
                            endfor;
                            if ($reportPageEnd < $totalReportPages) {
                                if ($reportPageEnd < $totalReportPages - 1) echo '<span class="pagination-ellipsis">…</span>';
                                echo '<a href="index.php?page=report&amp;q=' . rawurlencode($reportSearch) . '&amp;report_page=' . $totalReportPages . '">' . $totalReportPages . '</a>';
                            }
                            ?>

                            <?php if ($reportPage < $totalReportPages): ?>
                                <a href="index.php?page=report&amp;q=<?= rawurlencode($reportSearch) ?>&amp;report_page=<?= $reportPage + 1 ?>" aria-label="หน้าถัดไป">→</a>
                            <?php else: ?>
                                <span class="disabled-page" aria-disabled="true">→</span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </div>

                <?php if (!$reportRows): ?>
                    <div class="empty">ไม่พบข้อมูลสินค้า</div>
                <?php endif; ?>

                <p class="muted">
                    หมายเหตุ: ยอดขายสะสมคำนวณจากรายการขายก่อนหักส่วนลดท้ายบิล
                    และรวมประวัติการขายทั้งหมดที่มีในระบบ
                </p>
            </section>

        <?php elseif ($page === 'receipt'): ?>

            <?php
            $receiptId = (int)($_GET['id'] ?? 0);

            $stmt = $pdo->prepare(
                'SELECT s.*, u.username
                 FROM sales s LEFT JOIN users u ON u.id=s.user_id
                 WHERE s.id=? LIMIT 1'
            );
            $stmt->execute([$receiptId]);
            $receipt = $stmt->fetch();

            $receiptItems = [];

            if ($receipt) {
                $stmt = $pdo->prepare(
                    'SELECT * FROM sale_items WHERE sale_id=? ORDER BY id'
                );
                $stmt->execute([$receiptId]);
                $receiptItems = $stmt->fetchAll();
            }
            ?>

            <?php if (!$receipt): ?>
                <section class="card">
                    <h2>ไม่พบใบเสร็จ</h2>
                    <a class="btn-primary" href="index.php?page=sales">กลับไปประวัติการขาย</a>
                </section>
            <?php else: ?>
                <div class="page-heading no-print">
                    <div>
                        <h1>ใบเสร็จรับเงิน</h1>
                        <p><?= e($receipt['receipt_no']) ?></p>
                    </div>

                    <div class="form-actions" style="margin:0">
                        <button class="btn-primary" onclick="window.print()">พิมพ์ใบเสร็จ</button>
                        <button> <a class="btn-light" href="index.php?page=sales">กลับ</a> </button>
                    </div>
                </div>

                <section class="receipt">
                    <div class="receipt-header">
                        <h2 style="margin-bottom:5px">ใบเสร็จรับเงิน</h2>
                        <div class="muted">Shop POS</div>
                        <div style="margin-top:10px">
                            เลขที่: <strong><?= e($receipt['receipt_no']) ?></strong>
                        </div>
                        <div>วันที่: <?= e($receipt['created_at']) ?></div>
                        <div>พนักงาน: <?= e($receipt['username'] ?? '-') ?></div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>สินค้า</th><th>จำนวน</th><th>ราคา/ชิ้น</th><th>รวม</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($receiptItems as $item): ?>
                                <tr>
                                    <td>
                                        <?= e($item['product_name']) ?>
                                        <div class="muted"><?= e($item['product_code']) ?></div>
                                    </td>
                                    <td><?= number_format((int)$item['quantity']) ?></td>
                                    <td><?= money($item['unit_price']) ?></td>
                                    <td><?= money($item['line_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="max-width:300px;margin:20px 0 0 auto">
                        <div style="display:flex;justify-content:space-between;margin:8px 0">
                            <span>รวมสินค้า</span>
                            <strong>฿<?= money($receipt['subtotal']) ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin:8px 0">
                            <span>ส่วนลด</span>
                            <strong>฿<?= money($receipt['discount']) ?></strong>
                        </div>
                        <hr>
                        <div style="display:flex;justify-content:space-between;font-size:20px;margin:12px 0">
                            <strong>ยอดสุทธิ</strong>
                            <strong>฿<?= money($receipt['total']) ?></strong>
                        </div>
                    </div>

                    <?php if ($receipt['note'] !== ''): ?>
                        <p>หมายเหตุ: <?= e($receipt['note']) ?></p>
                    <?php endif; ?>

                    <div style="text-align:center;margin-top:30px">
                        ขอบคุณที่ใช้บริการ
                    </div>
                </section>
            <?php endif; ?>

        <?php endif; ?>

        <footer class="muted no-print" style="text-align:center;padding:20px 0;font-size:12px">
            Shop POS · ระบบจัดการสินค้าและขายหน้าร้าน
        </footer>
    </main>
</div>

<?php endif; ?>

<!-- JsBarcode ใช้สร้างบาร์โค้ดสำหรับฉลาก -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>

<script>
/*
|--------------------------------------------------------------------------
| Barcode label printing
|--------------------------------------------------------------------------
*/

let barcodeData = null;

function openBarcode(id, code, barcode, name, stock) {
    barcodeData = {
        id: Number(id),
        code: String(code),
        barcode: String(barcode || ''),
        name: String(name),
        stock: Number(stock)
    };

    const panel = document.getElementById('barcode-panel');

    if (!panel) return;

    document.getElementById('barcode-name').value = barcodeData.name;
    document.getElementById('barcode-code').value = barcodeData.barcode;

    document.getElementById('barcode-quantity').value =
        Math.max(1, Math.min(500, barcodeData.stock || 1));

    panel.hidden = false;
    panel.scrollIntoView({behavior: 'smooth'});
}

document.addEventListener('click', function (event) {
    const button = event.target.closest('.js-open-barcode');

    if (!button) {
        return;
    }

    openBarcode(
        button.dataset.id,
        button.dataset.code,
        button.dataset.barcode,
        button.dataset.name,
        button.dataset.stock
    );
});

function printBarcodes() {
    if (!barcodeData) {
        alert('กรุณาเลือกสินค้าก่อน');
        return;
    }

    const quantity = Number(document.getElementById('barcode-quantity').value);
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > 500) {
        alert('จำนวนฉลากต้องอยู่ระหว่าง 1 ถึง 500');
        return;
    }

    // เปิดฉลากในหน้าต่างใหม่สำหรับพิมพ์
    const url = 'barcode_print.php?' + new URLSearchParams({
        id: String(barcodeData.id),
        quantity: String(quantity)
    }).toString();
    const printWindow = window.open(url, '_blank');
    if (!printWindow) {
        alert('เบราว์เซอร์บล็อกหน้าต่างใหม่ กรุณาอนุญาตป๊อปอัปสำหรับเว็บไซต์นี้แล้วลองอีกครั้ง');
    }
}
</script>


<div id="notice-overlay" class="notice-overlay" hidden>
    <section id="notice-box" class="notice-box" data-type="info" role="alertdialog"
             aria-modal="true" aria-labelledby="notice-title" aria-describedby="notice-message">
        <div id="notice-icon" class="notice-icon" aria-hidden="true">i</div>
        <h3 id="notice-title">แจ้งเตือน</h3>
        <p id="notice-message"></p>
        <div class="notice-actions" id="notice-actions"></div>
    </section>
</div>

<script>
(function () {
    const overlay = document.getElementById('notice-overlay');
    const box = document.getElementById('notice-box');
    const title = document.getElementById('notice-title');
    const message = document.getElementById('notice-message');
    const icon = document.getElementById('notice-icon');
    const actions = document.getElementById('notice-actions');
    let finishDialog = null;

    function openDialog(text, options = {}) {
        const type = options.type || 'info';
        const isConfirm = options.confirm === true;
        box.dataset.type = type;
        title.textContent = options.title || (isConfirm ? 'ยืนยันรายการ' : type === 'error' ? 'เกิดข้อผิดพลาด' : type === 'success' ? 'สำเร็จ' : 'แจ้งเตือน');
        message.textContent = String(text ?? '');
        icon.textContent = type === 'error' ? '!' : type === 'success' ? '✓' : '?';
        actions.replaceChildren();

        return new Promise(resolve => {
            finishDialog = resolve;
            const close = value => {
                overlay.hidden = true;
                document.removeEventListener('keydown', onKeyDown);
                finishDialog = null;
                resolve(value);
            };
            const onKeyDown = event => {
                if (event.key === 'Escape' && isConfirm) close(false);
                if (event.key === 'Enter' && !isConfirm) close(true);
            };
            document.addEventListener('keydown', onKeyDown);

            if (isConfirm) {
                const cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'notice-cancel';
                cancel.textContent = 'ยกเลิก';
                cancel.addEventListener('click', () => close(false));
                actions.appendChild(cancel);
            }

            const ok = document.createElement('button');
            ok.type = 'button';
            ok.className = isConfirm ? 'notice-confirm' : 'notice-ok';
            ok.textContent = isConfirm ? 'ยืนยัน' : 'ตกลง';
            ok.addEventListener('click', () => close(isConfirm ? true : true));
            actions.appendChild(ok);

            overlay.hidden = false;
            ok.focus();
        });
    }

    window.showAlert = function (text, type = 'info') {
        openDialog(text, {type});
    };
    window.showConfirm = function (text) {
        return openDialog(text, {confirm: true, type: 'info'});
    };

    // เปลี่ยน alert เดิมให้ใช้หน้าต่างแจ้งเตือนของระบบ
    window.alert = function (text) {
        window.showAlert(text, 'info');
    };

    document.addEventListener('click', event => {
        if (event.target === overlay && !finishDialog) overlay.hidden = true;
    });

    document.querySelectorAll('.js-confirm-form').forEach(form => {
        form.addEventListener('submit', async event => {
            if (form.dataset.confirmed === 'yes') {
                delete form.dataset.confirmed;
                return;
            }
            event.preventDefault();
            const accepted = await window.showConfirm(form.dataset.confirm || 'ยืนยันทำรายการนี้?');
            if (accepted) {
                form.dataset.confirmed = 'yes';
                form.requestSubmit();
            }
        });
    });

    const flashMessages = document.querySelectorAll('.flash');
    flashMessages.forEach(item => {
        const type = item.classList.contains('error') ? 'error' : 'success';
        const text = item.textContent.trim();
        if (text) window.showAlert(text, type);
        item.hidden = true;
    });
})();
</script>

</body>
</html>