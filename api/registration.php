<?php
// ============================================================
// Registration API — Public POST + Admin GET/DELETE
// ============================================================
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$db = getDB();

// Auto-create table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS registrations (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ref_number    VARCHAR(40)  NOT NULL UNIQUE,
    full_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL,
    phone         VARCHAR(15)  NOT NULL,
    national_id   VARCHAR(10)  NOT NULL UNIQUE,
    city          VARCHAR(80)  NOT NULL,
    gender        ENUM('male','female') NOT NULL,
    prev_customer TINYINT(1)   NOT NULL DEFAULT 0,
    ip_address    VARCHAR(45)  DEFAULT NULL,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// أضف العمود إن لم يكن موجوداً (للجداول القديمة)
try {
    $db->exec("ALTER TABLE registrations ADD COLUMN IF NOT EXISTS
        prev_customer TINYINT(1) NOT NULL DEFAULT 0 AFTER gender");
} catch (Exception $e) { /* العمود موجود بالفعل */ }

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// PUBLIC POST — submit registration
// ============================================================
if ($method === 'POST' && empty($_GET['admin'])) {

    // Check competition is active
    $rows = $db->query("SELECT `key`, value FROM settings WHERE `key` IN
        ('comp_active','comp_title','comp_success_msg','comp_ref_prefix')")->fetchAll();
    $settings = [];
    foreach ($rows as $r) $settings[$r['key']] = $r['value'];

    if (($settings['comp_active'] ?? '1') === '0') {
        echo json_encode(['success' => false,
            'message' => 'التسجيل في المسابقة مغلق حالياً'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // ---- Sanitize ----
    $full_name   = clean($body['full_name']   ?? '');
    $email       = strtolower(trim($body['email']       ?? ''));
    $phone       = trim($body['phone']       ?? '');
    $national_id = trim($body['national_id'] ?? '');
    $city        = clean($body['city']        ?? '');
    $gender        = trim($body['gender']        ?? '');
    $prev_customer = isset($body['prev_customer']) ? (int)(bool)$body['prev_customer'] : -1;
    $terms         = !empty($body['terms']);

    // ---- Validate ----
    $errors = [];
    if (mb_strlen($full_name) < 3)
        $errors[] = 'الاسم الكامل مطلوب ولا يقل عن 3 أحرف';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'البريد الإلكتروني غير صحيح';
    if (!preg_match('/^05\d{8}$/', $phone))
        $errors[] = 'رقم الجوال يجب أن يبدأ بـ 05 ويكون 10 أرقام';
    if (!preg_match('/^[12]\d{9}$/', $national_id))
        $errors[] = 'رقم الهوية يجب أن يكون 10 أرقام ويبدأ بـ 1 أو 2';
    if (mb_strlen($city) < 2)
        $errors[] = 'المدينة مطلوبة';
    if (!in_array($gender, ['male', 'female']))
        $errors[] = 'يرجى اختيار الجنس';
    if ($prev_customer === -1)
        $errors[] = 'يرجى الإجابة على سؤال التعامل السابق مع مخازن العناية';
    if (!$terms)
        $errors[] = 'يجب الموافقة على الشروط والأحكام';

    if ($errors) {
        echo json_encode(['success' => false,
            'message' => implode('، ', $errors)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Check duplicate national ID ----
    $dup = $db->prepare("SELECT id FROM registrations WHERE national_id = ?");
    $dup->execute([$national_id]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false,
            'message' => 'رقم الهوية هذا مسجّل مسبقاً في المسابقة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Check duplicate email ----
    $dupEmail = $db->prepare("SELECT id FROM registrations WHERE email = ?");
    $dupEmail->execute([$email]);
    if ($dupEmail->fetch()) {
        echo json_encode(['success' => false,
            'message' => 'البريد الإلكتروني هذا مسجّل مسبقاً في المسابقة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Generate unique ref_number ----
    $prefix     = preg_replace('/[^\w\p{Arabic}]/u', '', $settings['comp_ref_prefix'] ?? 'MK');
    $firstName  = explode(' ', trim($full_name))[0];
    $ref_number = '';
    for ($i = 0; $i < 10; $i++) {
        $rand       = str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        $candidate  = $prefix . '-' . $firstName . $rand;
        $chk        = $db->prepare("SELECT id FROM registrations WHERE ref_number = ?");
        $chk->execute([$candidate]);
        if (!$chk->fetch()) { $ref_number = $candidate; break; }
    }
    if (!$ref_number) {
        echo json_encode(['success' => false,
            'message' => 'حدث خطأ أثناء التسجيل، يرجى المحاولة مجدداً'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Insert ----
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $db->prepare("INSERT INTO registrations
        (ref_number, full_name, email, phone, national_id, city, gender, prev_customer, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$ref_number, $full_name, $email, $phone,
                    $national_id, $city, $gender, $prev_customer, $ip]);

    // ---- Fetch back for date ----
    $row = $db->prepare("SELECT created_at FROM registrations WHERE id = ?");
    $row->execute([$db->lastInsertId()]);
    $created_at = $row->fetchColumn();

    // Format date
    $dateObj = new DateTime($created_at);
    $months  = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو',
                 'يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
    $dateAr  = $dateObj->format('j') . ' ' . $months[(int)$dateObj->format('n')]
             . ' ' . $dateObj->format('Y');

    echo json_encode([
        'success'     => true,
        'ref_number'  => $ref_number,
        'full_name'   => $full_name,
        'date'        => $dateAr,
        'success_msg' => $settings['comp_success_msg'] ?? '',
        'comp_title'  => $settings['comp_title'] ?? 'مسابقة مخازن العناية',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// ADMIN GET — list registrations with filters
// ============================================================
if ($method === 'GET' && !empty($_GET['admin'])) {
    requireAuth();

    $where  = [];
    $params = [];

    if (!empty($_GET['search'])) {
        $s = '%' . $_GET['search'] . '%';
        $where[]  = '(full_name LIKE ? OR phone LIKE ? OR national_id LIKE ? OR email LIKE ?)';
        $params   = array_merge($params, [$s, $s, $s, $s]);
    }
    if (!empty($_GET['city'])) {
        $where[]  = 'city = ?';
        $params[] = $_GET['city'];
    }
    if (!empty($_GET['gender'])) {
        $where[]  = 'gender = ?';
        $params[] = $_GET['gender'];
    }
    if (!empty($_GET['date_from'])) {
        $where[]  = 'DATE(created_at) >= ?';
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where[]  = 'DATE(created_at) <= ?';
        $params[] = $_GET['date_to'];
    }

    // For CSV export: no limit (streams directly)
    // For JSON view: cap at 5000 rows to prevent memory exhaustion
    $limitClause = (!empty($_GET['export']) && $_GET['export'] === 'csv') ? '' : ' LIMIT 5000';
    $sql = 'SELECT * FROM registrations'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY created_at DESC' . $limitClause;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Export CSV
    if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="registrations_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
        fputcsv($out, ['#','رقم المرجع','الاسم الكامل','البريد الإلكتروني',
                        'رقم الجوال','رقم الهوية','المدينة','الجنس','تاريخ التسجيل']);
        foreach ($rows as $i => $r) {
            fputcsv($out, [
                $i + 1,
                $r['ref_number'],
                $r['full_name'],
                $r['email'],
                $r['phone'],
                $r['national_id'],
                $r['city'],
                $r['gender'] === 'male' ? 'ذكر' : 'أنثى',
                $r['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    // Stats
    $total   = $db->query("SELECT COUNT(*) FROM registrations")->fetchColumn();
    $today   = $db->query("SELECT COUNT(*) FROM registrations WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $cities  = $db->query("SELECT city, COUNT(*) as cnt FROM registrations GROUP BY city ORDER BY cnt DESC")->fetchAll();
    $genders = $db->query("SELECT gender, COUNT(*) as cnt FROM registrations GROUP BY gender")->fetchAll();

    echo json_encode([
        'success' => true,
        'rows'    => $rows,
        'stats'   => [
            'total'   => (int)$total,
            'today'   => (int)$today,
            'cities'  => $cities,
            'genders' => $genders,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// ADMIN DELETE
// ============================================================
if ($method === 'DELETE' && !empty($_GET['admin'])) {
    requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM registrations WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Bad request'], JSON_UNESCAPED_UNICODE);
