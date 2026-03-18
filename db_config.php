<?php
// db_config.php
$host = 'localhost';
$user = 'root';      // MariaDB 사용자 아이디
$pass = 'Ehlswkd6023!';       // MariaDB 비밀번호
$dbName = 'attendance_system'; // 1단계에서 만든 DB 이름
// 시놀로지 MariaDB 10 전용 소켓 경로 추가
$socket = '/run/mysqld/mysqld10.sock'; 

// 소켓 경로를 포함하여 연결 시도
$conn = new mysqli($host, $user, $pass, $dbName, null, $socket); 

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'DB 연결 실패: ' . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");
?>