<?php
declare(strict_types=1);

/**
 * 기관별 검사 결과 저장 엔드포인트.
 * 서울시민 마음잡고 프로젝트 참여 신청서(STEP 1~10) 응답을 저장합니다.
 * 테이블: submissions_swf_apply
 *
 * 접속 정보는 이 폴더 기준 두 단계 위(sites/db.config.php, 모든 기관이 공유)에서
 * 읽어옵니다. 비밀번호는 그 파일에만 채워 넣고, 이 파일이나 소스 저장소에는
 * 남기지 마세요.
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

$table = 'submissions_swf_apply';

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

    -- STEP 3 기본 정보
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(191) DEFAULT NULL,
    gender VARCHAR(10) DEFAULT NULL,
    birth_year VARCHAR(8) DEFAULT NULL,
    district VARCHAR(60) DEFAULT NULL,
    employment VARCHAR(4) DEFAULT NULL,

    -- STEP 2 참여 자격
    eligible VARCHAR(2) DEFAULT NULL,
    eligibility_answers VARCHAR(40) DEFAULT NULL,

    -- STEP 4 이중돌봄 유형
    care_type VARCHAR(2) DEFAULT NULL,
    care_type_combo VARCHAR(8) DEFAULT NULL,
    care_targets_all VARCHAR(120) DEFAULT NULL,

    -- STEP 5 돌봄 상황
    care_count VARCHAR(4) DEFAULT NULL,
    care_cohabit VARCHAR(4) DEFAULT NULL,
    care_hours VARCHAR(4) DEFAULT NULL,
    care_forms VARCHAR(40) DEFAULT NULL,
    care_support VARCHAR(4) DEFAULT NULL,

    -- STEP 6 돌봄 부담·소진
    burden_score INT DEFAULT NULL,
    burden_answers VARCHAR(120) DEFAULT NULL,

    -- STEP 7 사전 심리검사
    depression_score INT DEFAULT NULL,
    anxiety_score INT DEFAULT NULL,
    stress_score INT DEFAULT NULL,
    safety_answers VARCHAR(40) DEFAULT NULL,
    suicide_risk VARCHAR(10) DEFAULT NULL,

    -- STEP 8 참여 유형 / 집단상담 조건
    group_assignment VARCHAR(4) DEFAULT NULL,
    group_pref_days VARCHAR(60) DEFAULT NULL,
    group_pref_time VARCHAR(60) DEFAULT NULL,
    group_pref_mode VARCHAR(4) DEFAULT NULL,
    group_pref_site VARCHAR(120) DEFAULT NULL,
    group_barrier_flag VARCHAR(8) DEFAULT NULL,
    group_decline_reason VARCHAR(40) DEFAULT NULL,
    group_decline_etc VARCHAR(255) DEFAULT NULL,
    group_choose_reason VARCHAR(40) DEFAULT NULL,
    group_choose_etc VARCHAR(255) DEFAULT NULL,
    rec_consent_pre VARCHAR(4) DEFAULT NULL,
    switched_by_rec VARCHAR(8) DEFAULT NULL,
    rec_consent_signed VARCHAR(8) DEFAULT NULL,

    -- STEP 9 상담 신청 이유 / 1:1 조건
    reason_all VARCHAR(40) DEFAULT NULL,
    reason_etc VARCHAR(255) DEFAULT NULL,
    reason_primary VARCHAR(8) DEFAULT NULL,
    care_attribution VARCHAR(4) DEFAULT NULL,
    prior_counseling VARCHAR(4) DEFAULT NULL,
    counsel_mode VARCHAR(4) DEFAULT NULL,
    counsel_region VARCHAR(20) DEFAULT NULL,
    -- counselor_gender: 상담사 성별 선호 문항은 신청서에서 삭제(예약 화면에서 처리).
    -- 과거 접수분 보존을 위해 컬럼만 남겨 둡니다.
    counselor_gender VARCHAR(10) DEFAULT NULL,
    counsel_time VARCHAR(80) DEFAULT NULL,
    free_text TEXT DEFAULT NULL,

    submitted_at VARCHAR(40) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_row_id (row_id)
) DEFAULT CHARSET=utf8mb4");

// 이미 만들어진 테이블에는 CREATE TABLE IF NOT EXISTS 가 컬럼을 추가하지 않으므로,
// 문항이 늘어난 경우 누락된 컬럼만 골라 추가합니다.
$addedColumns = [
    'group_decline_reason' => 'VARCHAR(40) DEFAULT NULL',
    'group_decline_etc'    => 'VARCHAR(255) DEFAULT NULL',
    'group_choose_reason'  => 'VARCHAR(40) DEFAULT NULL',
    'group_choose_etc'     => 'VARCHAR(255) DEFAULT NULL',
    'rec_consent_pre'      => 'VARCHAR(4) DEFAULT NULL',
    'switched_by_rec'      => 'VARCHAR(8) DEFAULT NULL',
    'rec_consent_signed'   => 'VARCHAR(8) DEFAULT NULL',
];
$existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
foreach ($addedColumns as $column => $definition) {
    if (!in_array($column, $existing, true)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['rowId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$columnMap = [
    'rowId' => 'row_id',
    'timestamp' => 'submitted_at',
    'name' => 'name',
    'phone' => 'phone',
    'email' => 'email',
    'gender' => 'gender',
    'birthYear' => 'birth_year',
    'district' => 'district',
    'employment' => 'employment',
    'eligible' => 'eligible',
    'eligibilityAnswers' => 'eligibility_answers',
    'careType' => 'care_type',
    'careTypeCombo' => 'care_type_combo',
    'careTargetsAll' => 'care_targets_all',
    'careCount' => 'care_count',
    'careCohabit' => 'care_cohabit',
    'careHours' => 'care_hours',
    'careForms' => 'care_forms',
    'careSupport' => 'care_support',
    'burdenScore' => 'burden_score',
    'burdenAnswers' => 'burden_answers',
    'depressionScore' => 'depression_score',
    'anxietyScore' => 'anxiety_score',
    'stressScore' => 'stress_score',
    'safetyAnswers' => 'safety_answers',
    'suicideRisk' => 'suicide_risk',
    'groupAssignment' => 'group_assignment',
    'groupPrefDays' => 'group_pref_days',
    'groupPrefTime' => 'group_pref_time',
    'groupPrefMode' => 'group_pref_mode',
    'groupPrefSite' => 'group_pref_site',
    'groupBarrierFlag' => 'group_barrier_flag',
    'groupDeclineReason' => 'group_decline_reason',
    'groupDeclineEtc' => 'group_decline_etc',
    'groupChooseReason' => 'group_choose_reason',
    'groupChooseEtc' => 'group_choose_etc',
    'recConsentPre' => 'rec_consent_pre',
    'switchedByRec' => 'switched_by_rec',
    'reasonAll' => 'reason_all',
    'reasonEtc' => 'reason_etc',
    'reasonPrimary' => 'reason_primary',
    'careAttribution' => 'care_attribution',
    'priorCounseling' => 'prior_counseling',
    'counselMode' => 'counsel_mode',
    'counselRegion' => 'counsel_region',
    'counselTime' => 'counsel_time',
    'freeText' => 'free_text',
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
