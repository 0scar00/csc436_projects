<?php
session_start();
header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/config.php';

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
    exit;
}

// Limit input length to prevent abuse
if (strlen($username) > 100 || strlen($password) > 255) {
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit;
}

try {
    $pdo = getDB();

    $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'staff_login'")->fetchColumn();

    if (!$tableExists) {
        echo json_encode([
            'success' => false,
            'message' => 'Login is not configured for this database. Import the staff_login table or update auth.php to match your schema.'
        ]);
        exit;
    }

    // Fetch user by username only, then verify hashed password separately
    $stmt = $pdo->prepare("SELECT user_id, username, email, password FROM staff_login WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        $_SESSION['user_id']  = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email']    = $user['email'];

        echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
    } else {
        // Generic message — do not reveal whether username or password was wrong
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    }
} catch (PDOException $e) {
    error_log('LabTrack auth error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
}