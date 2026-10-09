<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
date_default_timezone_set('Asia/Bangkok');

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// หน้าพิมพ์นี้ใช้ session เดียวกับ index.php และต้องเข้าสู่ระบบก่อน
if (empty($_SESSION['user'])) {
    header('Location: index.php?page=login');
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$quantity = filter_input(INPUT_GET, 'quantity', FILTER_VALIDATE_INT);
if (!$id || !$quantity || $quantity < 1 || $quantity > 500) {
    http_response_code(400);
    exit('ข้อมูลสินค้าหรือจำนวนฉลากไม่ถูกต้อง');
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, code, barcode, name FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    exit('ไม่สามารถอ่านข้อมูลสินค้าได้ กรุณาตรวจสอบการเชื่อมต่อฐานข้อมูล');
}

if (!$product || !preg_match('/^\d{13}$/', (string)$product['barcode'])) {
    http_response_code(404);
    exit('ไม่พบสินค้า หรือสินค้ายังไม่มีบาร์โค้ด EAN-13');
}

// ตรวจสอบ check digit อีกครั้งก่อนสร้างฉลาก
$digits = substr((string)$product['barcode'], 0, 12);
$sum = 0;
for ($i = 0; $i < 12; $i++) {
    $sum += ((int)$digits[$i]) * (($i % 2 === 0) ? 1 : 3);
}
$expectedCheckDigit = (10 - ($sum % 10)) % 10;
if ($expectedCheckDigit !== (int)substr((string)$product['barcode'], 12, 1)) {
    http_response_code(422);
    exit('Check digit ของบาร์โค้ดไม่ถูกต้อง กรุณาเปิดหน้า POS เพื่อสร้างบาร์โค้ดใหม่');
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>พิมพ์บาร์โค้ด - <?= h($product['name']) ?></title>
<style>
* { box-sizing: border-box; }
body { margin: 0; padding: 20px; font-family: Arial, Tahoma, sans-serif; color: #111; background: #f3f4f6; }
.toolbar { max-width: 1000px; margin: 0 auto 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.toolbar h1 { margin: 0; font-size: 20px; }
.actions { display: flex; gap: 8px; }
button, a { border: 0; border-radius: 6px; padding: 10px 16px; font-size: 14px; cursor: pointer; text-decoration: none; }
button { background: #2563eb; color: #fff; }
a { background: #e5e7eb; color: #111827; }
.meta { max-width: 1000px; margin: 0 auto 14px; font-size: 13px; color: #4b5563; }
.barcode-labels { max-width: 1000px; margin: 0 auto; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.barcode-label { background: #fff; text-align: center; padding: 8px; border: 1px dashed #9ca3af; border-radius: 5px; overflow: hidden; break-inside: avoid; page-break-inside: avoid; }
.barcode-name { font-size: 11px; font-weight: 700; overflow-wrap: anywhere; margin-bottom: 3px; }
.barcode-label svg { display: block; max-width: 100%; width: 100%; height: 48px; }
.barcode-code { font-size: 10px; letter-spacing: .3px; }
.error { margin: 20px; }
@media (max-width: 700px) { .barcode-labels { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media print {
  body { padding: 0; background: #fff; }
  .no-print { display: none !important; }
  .meta, .barcode-labels { max-width: none; }
  .barcode-labels { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 3mm; }
  .barcode-label { border: 1px dashed #aaa; border-radius: 0; }
  @page { margin: 8mm; }
}
</style>
</head>
<body>
<div class="toolbar no-print">
  <h1>ตัวอย่างฉลากบาร์โค้ด</h1>
  <div class="actions">
    <button type="button" onclick="window.print()">สั่งพิมพ์</button>
    <a href="index.php?page=products">กลับหน้าสินค้า</a>
  </div>
</div>
<div class="meta">สินค้า: <strong><?= h($product['name']) ?></strong> · รหัสสินค้า: <?= h($product['code']) ?> · EAN-13: <?= h($product['barcode']) ?> · จำนวน <?= (int)$quantity ?> ฉลาก</div>
<div class="barcode-labels" id="labels">
<?php for ($i = 0; $i < $quantity; $i++): ?>
  <div class="barcode-label">
    <div class="barcode-name"><?= h($product['name']) ?></div>
    <svg class="ean" data-value="<?= h($product['barcode']) ?>" aria-label="EAN-13 barcode"></svg>
    <div class="barcode-code"><?= h($product['barcode']) ?></div>
  </div>
<?php endfor; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
function renderLabels() {
  if (typeof JsBarcode === 'undefined') {
    alert('โหลดระบบสร้างบาร์โค้ดไม่สำเร็จ กรุณาตรวจสอบอินเทอร์เน็ต');
    return;
  }
  document.querySelectorAll('svg.ean').forEach(function (svg) {
    JsBarcode(svg, svg.dataset.value, {
      format: 'EAN13', displayValue: false, margin: 2, height: 40, width: 1.5
    });
  });
}
renderLabels();
</script>
</body>
</html>
