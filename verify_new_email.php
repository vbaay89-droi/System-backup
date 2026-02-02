<?php
// This script does NOT use sessions. It is stateless.
require_once 'config.php'; // Your MySQLi connection

$status_redirect_url = 'login.php'; // Page to show messages
$token_from_url = $_GET['token'] ?? null;
$current_time = time();

// 1. Check if token is missing from URL
if (empty($token_from_url)) {
    header('Location: ' . $status_redirect_url . '?status=invalid_link');
    exit();
}

// 2. Find the user by the token
$stmt = $conn->prepare("SELECT id, new_email, token_expiry FROM users WHERE verification_token = ? LIMIT 1");
$stmt->bind_param("s", $token_from_url);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// 3. Check if token is valid
if (!$user) {
    // Token not found in DB or already used
    header('Location: ' . $status_redirect_url . '?status=invalid_link');
    exit();
}

// 4. Check if token is expired
if ($current_time > $user['token_expiry']) {
    // Token expired. Clear it from DB.
    $stmt_clear = $conn->prepare("UPDATE users SET new_email = NULL, verification_token = NULL, token_expiry = NULL WHERE id = ?");
    $stmt_clear->bind_param("i", $user['id']);
    $stmt_clear->execute();
    $stmt_clear->close();
    
    header('Location: ' . $status_redirect_url . '?status=token_expired');
    exit();
}

// --- Token is valid and not expired ---

$new_email = $user['new_email'];
$user_id = $user['id'];

// 5. (Final check) Make sure new email wasn't taken by *another* user
$stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
$stmt_check->bind_param("si", $new_email, $user_id);
$stmt_check->execute();
$stmt_check->store_result();
$redirect_status = '';

if ($stmt_check->num_rows > 0) {
     // Email was claimed by someone else while waiting
     $redirect_status = '?status=email_taken';
} else {
    // 6. SUCCESS: Update the user's email and clear the token fields
    $stmt_update = $conn->prepare("UPDATE users SET email = ?, new_email = NULL, verification_token = NULL, token_expiry = NULL WHERE id = ?");
    $stmt_update->bind_param("si", $new_email, $user_id);
    
    if ($stmt_update->execute()) {
        $redirect_status = '?status=email_success';
    } else {
        $redirect_status = '?status=db_error';
    }
    $stmt_update->close();
}
$stmt_check->close();

// 7. Redirect back to login page with the final status
header('Location: ' . $status_redirect_url . $redirect_status);
exit();
?>

