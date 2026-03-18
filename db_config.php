<?php
// db_config.php
$host = 'localhost';
$user = 'root';      // MariaDB 사용자 아이디
$pass = '';       // MariaDB 비밀번호
$dbName = 'attendance_system'; // 1단계에서 만든 DB 이름

// 소켓 경로를 포함하여 연결 시도
$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'DB 연결 실패: ' . $conn->connect_error]));
}

$conn->query("CREATE DATABASE IF NOT EXISTS attendance_system");
$conn->select_db($dbName);

$sql = file_get_contents('attendance_system.sql');
if ($conn->multi_query($sql)) {
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());
}

$conn->set_charset("utf8mb4");
?>
