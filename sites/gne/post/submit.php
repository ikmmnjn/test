<?php
declare(strict_types=1);

/**
 * 기관별 사후 심리검사·상담만족도 조사 결과 저장 엔드포인트.
 * 메이커가 기관 페이지를 생성할 때 이 파일을 test/sites/<기관>/post/submit.php 로
 * 복사하면서 submissions_gne_post 자리를 기관 전용 테이블 이름(submissions_<코드>_post)으로
 * 바꿔 넣습니다.
 *
 * submit.php(사전)와 마찬가지로 접속 정보는 이 폴더 기준 두 단계 위(sites/db.config.php,
 * 모든 기관이 공유)에서 읽어옵니다.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$configPath = dirname(__DIR__, 2) . '/db.config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'DB config missing: ' . $configPath]);
    exit;
}
$config = require $configPath;

$table = 'submissions_gne_post';

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['dbname'],
        $config['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// 최초 요청 시 테이블이 없으면 자동 생성 (기관 추가 시 수동 SQL 작업 불필요)
$pdo->exec("CREATE TABLE IF NOT EXISTS `$table` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    row_id VARCHAR(191) NOT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    gender VARCHAR(10) DEFAULT NULL,
    depression_score INT DEFAULT NULL,
    anxiety_score INT DEFAULT NULL,
    stress_score INT DEFAULT NULL,
    satisfaction_1 INT DEFAULT NULL,
    satisfaction_2 INT DEFAULT NULL,
    satisfaction_3 INT DEFAULT NULL,
    satisfaction_4 INT DEFAULT NULL,
    satisfaction_5 INT DEFAULT NULL,
    satisfaction_6 INT DEFAULT NULL,
    satisfaction_avg DECIMAL(3,1) DEFAULT NULL,
    satisfaction_comment TEXT DEFAULT NULL,
    submitted_at VARCHAR(40) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_row_id (row_id)
) DEFAULT CHARSET=utf8mb4");

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['rowId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$columnMap = [
    'rowId' => 'row_id',
    'name' => 'name',
    'phone' => 'phone',
    'gender' => 'gender',
    'timestamp' => 'submitted_at',
    'depressionScore' => 'depression_score',
    'anxietyScore' => 'anxiety_score',
    'stressScore' => 'stress_score',
    'satisfaction1' => 'satisfaction_1',
    'satisfaction2' => 'satisfaction_2',
    'satisfaction3' => 'satisfaction_3',
    'satisfaction4' => 'satisfaction_4',
    'satisfaction5' => 'satisfaction_5',
    'satisfaction6' => 'satisfaction_6',
    'satisfactionAvg' => 'satisfaction_avg',
    'satisfactionComment' => 'satisfaction_comment',
];

$fields = [];
foreach ($columnMap as $jsonKey => $column) {
    if (array_key_exists($jsonKey, $data)) {
        $fields[$column] = $data[$jsonKey];
    }
}
if (!isset($fields['row_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing rowId']);
    exit;
}

$columns = array_keys($fields);
$placeholders = array_map(fn($c) => ':' . $c, $columns);
$updateAssignments = array_map(
    fn($c) => "`$c` = VALUES(`$c`)",
    array_filter($columns, fn($c) => $c !== 'row_id')
);

$sql = sprintf(
    'INSERT INTO `%s` (`%s`) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
    $table,
    implode('`, `', $columns),
    implode(', ', $placeholders),
    implode(', ', $updateAssignments)
);

try {
    $stmt = $pdo->prepare($sql);
    foreach ($fields as $column => $value) {
        $stmt->bindValue(':' . $column, $value);
    }
    $stmt->execute();
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Insert failed']);
}
