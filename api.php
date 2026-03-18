<?php
// api.php 수정본
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'db_config.php';

// [DB 자동 구축 보완] 회의록 테이블이 없을 경우 자동 생성
$create_minutes_table = "
CREATE TABLE IF NOT EXISTS `meeting_minutes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `date` VARCHAR(20) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT,
  `assignments` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$conn->query($create_minutes_table);

$create_certificates_table = "
CREATE TABLE IF NOT EXISTS `certificates` (
  `id` VARCHAR(50) PRIMARY KEY,
  `user_id` VARCHAR(50) NOT NULL,
  `userName` VARCHAR(100) NOT NULL,
  `type` VARCHAR(100) NOT NULL,
  `issueDate` VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$conn->query($create_certificates_table);

$method = $_SERVER['REQUEST_METHOD'];

// 빈 문자열을 NULL로 변환해주는 도우미 함수
function nullIfEmpty($value)
{
    return (isset($value) && trim($value) !== '') ? $value : null;
}

if ($method === 'GET') {
    $data = [];

    // 1. 사용자 정보 가져오기
    $res = $conn->query("SELECT * FROM users ORDER BY sortOrder ASC");
    $users = $res->fetch_all(MYSQLI_ASSOC);
    foreach ($users as &$u) {
        $u['contractLogs'] = json_decode($u['contractLogs'] ?? '[]', true);
        $u['contractHistory'] = json_decode($u['contractHistory'] ?? '[]', true);
        // 숫자 타입으로 변환 (JS Strict check 대비)
        $u['leaveTotal'] = isset($u['leaveTotal']) ? floatval($u['leaveTotal']) : 0;
        /* ▼▼▼ [추가] 출근부 제외 여부 변환 ▼▼▼ */
        $u['exclude_attendance'] = (isset($u['exclude_attendance']) && $u['exclude_attendance'] == 1) ? true : false;
        $u['leaveUsed'] = isset($u['leaveUsed']) ? floatval($u['leaveUsed']) : 0;
        $u['sortOrder'] = isset($u['sortOrder']) ? intval($u['sortOrder']) : 0;
        $u['reportOrder'] = isset($u['reportOrder']) ? intval($u['reportOrder']) : 9999;
    }
    $data['users'] = $users;

    // 2. 문서 정보 가져오기 (api.php 내 GET 부분)
    $res = $conn->query("SELECT * FROM docs ORDER BY createdAt DESC");
    $docs = $res->fetch_all(MYSQLI_ASSOC);
    foreach ($docs as &$d) {
        // [수정 포인트] 데이터가 문자열이면 decode하고, 실패하거나 비어있으면 빈 배열([])을 할당합니다.
        if (is_string($d['approvalLineSnapshot'])) {
            $decoded = json_decode($d['approvalLineSnapshot'], true);
            $d['approvalLineSnapshot'] = is_array($decoded) ? $decoded : [];
        }
        else if (!is_array($d['approvalLineSnapshot'])) {
            $d['approvalLineSnapshot'] = [];
        }

        // 숫자 타입 강제 변환
        $d['vacationDays'] = isset($d['vacationDays']) ? floatval($d['vacationDays']) : 0;
        $d['approvalStep'] = isset($d['approvalStep']) ? intval($d['approvalStep']) : 0;
        $d['isDeleted'] = (isset($d['isDeleted']) && $d['isDeleted'] == 1) ? true : false;
        $d['isRead'] = (isset($d['isRead']) && $d['isRead'] == 1) ? true : false;
    }
    $data['docs'] = $docs;

    // [추가됨] 3. 출근부 기록 가져오기
    $res = $conn->query("SELECT * FROM attendance_logs ORDER BY date DESC");
    $logs = $res->fetch_all(MYSQLI_ASSOC);
    foreach ($logs as &$log) {
        $log['overtime'] = floatval($log['overtime']);
        $log['short_time'] = floatval($log['short_time']);
    }
    $data['attendance_logs'] = $logs;

    // [신규] 3-1. 일용직 기록 가져오기
    $res = $conn->query("SELECT * FROM daily_attendance ORDER BY date DESC");
    $daily_logs = $res->fetch_all(MYSQLI_ASSOC);
    foreach ($daily_logs as &$log) {
        $log['overtime'] = floatval($log['overtime']);
        $log['short_time'] = floatval($log['short_time']);
    }
    $data['daily_logs'] = $daily_logs;

    // [신규] 3-2. 회의록(Meeting Minutes) 가져오기
    $res = $conn->query("SELECT * FROM meeting_minutes ORDER BY date DESC, created_at DESC");
    $meeting_minutes = [];
    if ($res) {
        $meeting_minutes = $res->fetch_all(MYSQLI_ASSOC);
    }
    $data['meeting_minutes'] = $meeting_minutes;

    // 3. 시스템 설정 가져오기
    $res = $conn->query("SELECT * FROM system_settings");
    while ($row = $res->fetch_assoc()) {
        $decoded = json_decode($row['data_value'] ?? '', true);
        // JSON 디코딩에 성공하면 디코딩된 값을, 실패하면 원본 문자열을 사용
        $data[$row['id']] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $row['data_value'];
    }

    /* ▼▼▼ [수정] 4. 식대 설정 (월별) 가져오기 (meal_config) ▼▼▼ */
    $mealConfigMap = [];
    $res = $conn->query("SELECT * FROM meal_config");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            // DB에는 문자열로 저장되어 있으므로, JSON 객체로 변환하여 리스트에 담습니다.
            // 예: key='cfg_2026-01', value='{"use":true, "price":5000}'
            $mealConfigMap[$row['setting_key']] = json_decode($row['setting_value'], true);
        }
    }
    // 프론트엔드에서 'mealConfigMap'이라는 이름으로 받게 됩니다.
    $data['mealConfigMap'] = $mealConfigMap;

    /* ▼▼▼ [추가] 5. 식수 기록 가져오기 (meal_records) ▼▼▼ */
    $mealData = [];
    $res = $conn->query("SELECT * FROM meal_records");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            // 키 형식: u_아이디_년월 또는 d_이름_년월
            $prefix = ($row['target_type'] == 'user') ? 'u' : 'd';
            $key = $prefix . '_' . $row['target_id'] . '_' . $row['target_ym'];
            $mealData[$key] = $row['meal_count'];
        }
    }
    $data['mealData'] = $mealData;

    // JSON 출력 전 버퍼 비우기 (불필요한 공백 제거)
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ▼▼▼ [추가] 식대 관련 POST 요청 처리 (기존 POST 로직 앞에 배치) ▼▼▼ */
$action = $_GET['action'] ?? ''; // URL 파라미터 확인

// 1. 식대 설정 저장 (월별 저장 방식으로 변경)
if ($method === 'POST' && $action == 'save_meal_settings') {
    $ym = $_POST['ym']; // 예: '2026-01'
    $use = $_POST['use_meal'] === 'true'; // 문자열 'true'를 불리언으로 변환
    $price = $_POST['meal_price'];

    // 키 생성: cfg_2026-01
    $key = 'cfg_' . $ym;
    // 값 생성: {"use":true, "price":5000} (JSON 포맷)
    $value = json_encode(['use' => $use, 'price' => $price]);

    // 없으면 추가(INSERT), 있으면 수정(UPDATE)
    $stmt = $conn->prepare("INSERT INTO meal_config (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("sss", $key, $value, $value);
    $stmt->execute();

    echo json_encode(['status' => 'ok']);
    exit;
}

// 2. 개별 식수 저장
if ($method === 'POST' && $action == 'save_meal_count') {
    $ym = $_POST['ym'];
    $type = $_POST['type'];
    $key = $_POST['key'];
    $count = $_POST['count'];

    $stmt = $conn->prepare("INSERT INTO meal_records (target_ym, target_type, target_id, meal_count) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE meal_count = ?");
    $stmt->bind_param("sssis", $ym, $type, $key, $count, $count);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'ok']);
    }
    else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// ------------------------------------------------------------------
// [신규] 증명서 발급 추가 API (attendance_view.html용 - 개별 저장)
// ------------------------------------------------------------------
if ($method === 'POST' && $action == 'add_certificate') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => '데이터 없음']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. system_settings에서 기존 certificates 가져오기
        $res = $conn->query("SELECT data_value FROM system_settings WHERE id = 'certificates'");
        $existing = [];
        if ($row = $res->fetch_assoc()) {
            $existing = json_decode($row['data_value'], true) ?: [];
        }

        // 2. 새 증명서 추가
        array_unshift($existing, $input);

        // 3. system_settings에 다시 저장
        $certJson = json_encode($existing, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("REPLACE INTO system_settings (id, data_value) VALUES ('certificates', ?)");
        $stmt->bind_param("s", $certJson);
        $stmt->execute();

        // 4. certificates 테이블에도 동기화
        $certId = strval($input['id']);
        $userName = $input['targetUser']['name'] ?? '';
        $userId = $input['targetUser']['name'] ?? '';
        $certType = $input['type'] ?? '';
        $certDate = $input['date'] ?? '';
        $stmt2 = $conn->prepare("INSERT IGNORE INTO certificates (id, user_id, userName, type, issueDate) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("sssss", $certId, $userId, $userName, $certType, $certDate);
        $stmt2->execute();

        // 5. settings의 lastIssuingAuthority, lastIssuerName 갱신
        $res2 = $conn->query("SELECT data_value FROM system_settings WHERE id = 'settings'");
        if ($settingsRow = $res2->fetch_assoc()) {
            $settingsData = json_decode($settingsRow['data_value'], true) ?: [];
            if (isset($input['institution'])) $settingsData['lastIssuingAuthority'] = $input['institution'];
            if (isset($input['issuer'])) $settingsData['lastIssuerName'] = $input['issuer'];
            $settingsJson = json_encode($settingsData, JSON_UNESCAPED_UNICODE);
            $stmt3 = $conn->prepare("REPLACE INTO system_settings (id, data_value) VALUES ('settings', ?)");
            $stmt3->bind_param("s", $settingsJson);
            $stmt3->execute();
        }

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// [신규] 증명서 삭제 API
if ($method === 'POST' && $action == 'delete_certificate') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        echo json_encode(['success' => false, 'message' => 'ID 누락']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. system_settings에서 기존 certificates 가져와 해당 ID 제거
        $res = $conn->query("SELECT data_value FROM system_settings WHERE id = 'certificates'");
        $existing = [];
        if ($row = $res->fetch_assoc()) {
            $existing = json_decode($row['data_value'], true) ?: [];
        }

        $targetId = $input['id'];
        $filtered = array_values(array_filter($existing, function($c) use ($targetId) {
            return strval($c['id']) !== strval($targetId);
        }));

        $certJson = json_encode($filtered, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("REPLACE INTO system_settings (id, data_value) VALUES ('certificates', ?)");
        $stmt->bind_param("s", $certJson);
        $stmt->execute();

        // 2. certificates 테이블에서도 삭제
        $stmt2 = $conn->prepare("DELETE FROM certificates WHERE id = ?");
        $idStr = strval($targetId);
        $stmt2->bind_param("s", $idStr);
        $stmt2->execute();

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => '데이터 없음']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // ------------------------------------------------------------------
        // 1. [사원 정보 저장] (데이터 있을 때만 삭제 후 저장)
        // ------------------------------------------------------------------
        if (isset($input['users']) && is_array($input['users'])) {
            // 1. 기존 데이터 초기화
            $conn->query("DELETE FROM users");

            // 2. 새로운 사원 정보 삽입 (exclude_attendance 포함)
            $stmt = $conn->prepare("INSERT INTO users (id, name, rank, joinDate, isContract, gender, rrnBack, email, resignDate, phone, birthDate, address, zone, note, contractEndDate, signature, contractLogs, contractHistory, sortOrder, leaveTotal, leaveUsed, reportOrder, exclude_attendance, size_top, size_bottom, size_shoe) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($input['users'] as $u) {
                if (empty($u['id'])) continue; // [안정성] ID 누락 시 건너뜀
                // 데이터 정제 로직
                $jDate = nullIfEmpty($u['joinDate'] ?? '');
                $rDate = nullIfEmpty($u['resignDate'] ?? '');
                $ceDate = nullIfEmpty($u['contractEndDate'] ?? '');
                $bDate = nullIfEmpty($u['birthDate'] ?? '');

                // 불리언/문자열 타입을 숫자(0 또는 1)로 변환
                $isContract = (isset($u['isContract']) && ($u['isContract'] === '1' || $u['isContract'] === true || $u['isContract'] === 1)) ? 1 : 0;
                $isExclude = (isset($u['exclude_attendance']) && ($u['exclude_attendance'] == 1 || $u['exclude_attendance'] === true)) ? 1 : 0;

                // JSON 데이터 처리
                $cLogs = is_array($u['contractLogs'] ?? null) ? json_encode($u['contractLogs'], JSON_UNESCAPED_UNICODE) : '[]';
                $cHistory = is_array($u['contractHistory'] ?? null) ? json_encode($u['contractHistory'], JSON_UNESCAPED_UNICODE) : '[]';

                // 기본값 처리
                $rOrder = isset($u['reportOrder']) ? intval($u['reportOrder']) : 9999;
                $sOrder = isset($u['sortOrder']) ? intval($u['sortOrder']) : 0;
                $lTotal = isset($u['leaveTotal']) ? floatval($u['leaveTotal']) : 0;
                $lUsed = isset($u['leaveUsed']) ? floatval($u['leaveUsed']) : 0;

                // 2. 바인딩 수정
                $stmt->bind_param(
                    "ssssisssssssssssssiddiisss", // <--- [중요] 끝에 'sss' 3개 추가됨
                    $u['id'], // s
                    $u['name'], // s
                    $u['rank'], // s
                    $jDate, // s
                    $isContract, // i
                    $u['gender'], // s
                    $u['rrnBack'], // s
                    $u['email'], // s
                    $rDate, // s
                    $u['phone'], // s
                    $bDate, // s
                    $u['address'], // s
                    $u['zone'], // s
                    $u['note'], // s
                    $ceDate, // s
                    $u['signature'], // s
                    $cLogs, // s
                    $cHistory, // s
                    $sOrder, // i
                    $lTotal, // d
                    $lUsed, // d
                    $rOrder, // i
                    $isExclude, // i
                    $u['size_top'], // s (추가됨)
                    $u['size_bottom'], // s (추가됨)
                    $u['size_shoe'] // s (추가됨)
                );
                $stmt->execute();
            }
        }


        // ------------------------------------------------------------------
        // 2. [문서 저장] (수정됨)
        // ------------------------------------------------------------------
        // [수정] count($input['docs']) > 0 조건을 제거했습니다.
        // 빈 배열([])이 들어와도 안으로 진입해서 DELETE를 수행해야 합니다.
        if (isset($input['docs']) && is_array($input['docs'])) {

            // 1. 기존 문서 모두 삭제 (빈 배열일 때도 실행되어야 함)
            $conn->query("DELETE FROM docs");

            // 2. 데이터가 있을 때만 INSERT 수행
            if (count($input['docs']) > 0) {
                $stmt = $conn->prepare("INSERT INTO docs (id, type, authorId, authorName, authorRank, title, content, startDate, endDate, leaveType, vacationDays, status, createdAt, approvedAt, approvalStep, approvalLineSnapshot, rejectReason, isDeleted, isRead, finalApproveType) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                foreach ($input['docs'] as $d) {
                    if (empty($d['id'])) continue; // [안정성] ID 누락 시 건너뜀
                    $vacDays = floatval($d['vacationDays'] ?? 0);
                    $apprStep = intval($d['approvalStep'] ?? 0);
                    $isDel = (!empty($d['isDeleted'])) ? 1 : 0;
                    $isR = (!empty($d['isRead'])) ? 1 : 0;
                    $lineSnap = is_array($d['approvalLineSnapshot']) ? json_encode($d['approvalLineSnapshot'], JSON_UNESCAPED_UNICODE) : '[]';
                    $aAt = nullIfEmpty($d['approvedAt'] ?? '');
                    $sAt = nullIfEmpty($d['startDate'] ?? '');
                    $eAt = nullIfEmpty($d['endDate'] ?? '');

                    $stmt->bind_param("ssssssssssdssssissis", $d['id'], $d['type'], $d['authorId'], $d['authorName'], $d['authorRank'], $d['title'], $d['content'], $sAt, $eAt, $d['leaveType'], $vacDays, $d['status'], $d['createdAt'], $aAt, $apprStep, $lineSnap, $d['rejectReason'], $isDel, $isR, $d['finalApproveType']);
                    $stmt->execute();
                }
            }
        }

        // ------------------------------------------------------------------
        // 3. [출근부 기록 저장] (수정됨)
        // ------------------------------------------------------------------
        // [수정 1] count(...) > 0 조건을 제거했습니다. 
        // 빈 배열([])이 들어왔을 때도 이 안으로 들어와서 DELETE를 실행해야 하기 때문입니다.
        if (isset($input['attendance_logs']) && is_array($input['attendance_logs'])) {

            // 1. 무조건 기존 기록 초기화 (빈 배열이어도 실행됨)
            $conn->query("DELETE FROM attendance_logs");

            // 2. 데이터가 1개 이상일 때만 INSERT 실행
            // [수정 2] INSERT 로직을 감싸는 if문을 내부에 새로 추가했습니다.
            if (count($input['attendance_logs']) > 0) {
                $stmt = $conn->prepare("INSERT INTO attendance_logs (user_id, date, status, overtime, short_time, note) VALUES (?, ?, ?, ?, ?, ?)");

                foreach ($input['attendance_logs'] as $log) {
                    $uid = $log['user_id'] ?? null;
                    $date = $log['date'] ?? null;
                    if (!$uid || !$date) continue; // 필수값 누락 시 건너뜀

                    $status = $log['status'] ?? 'weekday';
                    $over = floatval($log['overtime'] ?? 0);
                    $short = floatval($log['short_time'] ?? 0);
                    $note = $log['note'] ?? '';

                    $stmt->bind_param("sssdss", $uid, $date, $status, $over, $short, $note);
                    $stmt->execute();
                }
            }
        }

        // ------------------------------------------------------------------
        // 4. [일용직 등 나머지] (수정됨: 동기화 적용)
        // ------------------------------------------------------------------
        if (isset($input['daily_logs']) && is_array($input['daily_logs'])) {

            // 1. 기존 일용직 기록 초기화 (화면에서 삭제된 건 DB에서도 지우기 위함)
            $conn->query("DELETE FROM daily_attendance");

            // 2. 데이터가 있을 때만 INSERT 실행
            if (count($input['daily_logs']) > 0) {
                $stmt = $conn->prepare("INSERT INTO daily_attendance (worker_name, date, status, overtime, short_time, note) VALUES (?, ?, ?, ?, ?, ?)");

                foreach ($input['daily_logs'] as $log) {
                    $name = $log['worker_name'] ?? null;
                    $date = $log['date'] ?? null;
                    if (!$name || !$date) continue; // 필수값 누락 시 건너뜀

                    $status = $log['status'] ?? 'weekday'; // 값이 없으면 기본 'weekday'
                    $over = floatval($log['overtime'] ?? 0);
                    $short = floatval($log['short_time'] ?? 0);
                    $note = $log['note'] ?? '';

                    // ON DUPLICATE KEY UPDATE 제거하고 단순 INSERT로 변경
                    $stmt->bind_param("sssdss", $name, $date, $status, $over, $short, $note);
                    $stmt->execute();
                }
            }
        }

        // (기타 설정 저장 로직들... 기존 그대로 두세요)
        $others = ['settings', 'customHolidays', 'events', 'certificates', 'adminPassword', 'dailyWorkerNames', 'attendanceNotes', 'reportFilter'];
        $stmt = $conn->prepare("REPLACE INTO system_settings (id, data_value) VALUES (?, ?)");
        foreach ($others as $key) {
            if (isset($input[$key])) {
                $val = is_array($input[$key]) ? json_encode($input[$key], JSON_UNESCAPED_UNICODE) : $input[$key];
                $stmt->bind_param("ss", $key, $val);
                $stmt->execute();
            }
        }

        // ------------------------------------------------------------------
        // 11. [증명서 발급 기록 저장] (단순 동기화)
        // ------------------------------------------------------------------
        if (isset($input['certificates']) && is_array($input['certificates'])) {
            $conn->query("DELETE FROM certificates");
            if (count($input['certificates']) > 0) {
                $stmt = $conn->prepare("INSERT INTO certificates (id, user_id, userName, type, issueDate) VALUES (?, ?, ?, ?, ?)");
                foreach ($input['certificates'] as $cert) {
                    $cid = $cert['id'] ?? null;
                    $uid = $cert['user_id'] ?? $cert['targetUser']['name'] ?? null;
                    if (!$cid || !$uid) continue; // [안정성] 필수값 누락 시 건너뜀

                    $uName = $cert['userName'] ?? $cert['targetUser']['name'] ?? '';
                    $ctype = $cert['type'] ?? '';
                    $idate = $cert['issueDate'] ?? $cert['date'] ?? '';

                    $stmt->bind_param("sssss", $cid, $uid, $uName, $ctype, $idate);
                    $stmt->execute();
                }
            }
        }

        // ------------------------------------------------------------------
        // 12. [회의록(Meeting Minutes) 저장]
        // ------------------------------------------------------------------
        if (isset($input['meeting_minutes']) && is_array($input['meeting_minutes'])) {
            $conn->query("DELETE FROM meeting_minutes");
            if (count($input['meeting_minutes']) > 0) {
                $stmt = $conn->prepare("INSERT INTO meeting_minutes (date, title, content, assignments) VALUES (?, ?, ?, ?)");
                foreach ($input['meeting_minutes'] as $mm) {
                    if (empty($mm['date']) || empty($mm['title'])) continue; // [안정성] 필수값 누락 시 건너뜀
                    // assignments가 이미 json 문자열로 넘어왔겠지만 혹시 모를 상황 대비
                    $assignments = is_array($mm['assignments'] ?? null) ? json_encode($mm['assignments'], JSON_UNESCAPED_UNICODE) : ($mm['assignments'] ?? '[]');
                    $stmt->bind_param("ssss", $mm['date'], $mm['title'], $mm['content'], $assignments);
                    $stmt->execute();
                }
            }
        }

        // 일용직 수정/삭제 액션
        if (isset($input['action'])) {
            // (기존 코드 유지)
            if ($input['action'] === 'rename_daily_worker') {
                $stmt = $conn->prepare("UPDATE daily_attendance SET worker_name = ? WHERE worker_name = ?");
                $stmt->bind_param("ss", $input['newName'], $input['oldName']);
                $stmt->execute();
            }
            if ($input['action'] === 'delete_daily_worker') {
                $stmt = $conn->prepare("DELETE FROM daily_attendance WHERE worker_name = ?");
                $stmt->bind_param("s", $input['targetName']);
                $stmt->execute();
            }
        }

        $conn->commit();
        echo json_encode(['success' => true]);

    }
    catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>