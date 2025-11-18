<?php
session_start();
require_once 'config.php'; // Use the same DB connection as login.php

$token = $_GET['token'] ?? '';

if (empty($token)) {
    // No token provided
    header('Location: request_account.php?status=invalid_token');
    exit();
}

try {
    // 1. Find the token in the database
    $stmt = $conn->prepare("SELECT * FROM pending_verifications WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $request = $result->fetch_assoc();
        
        // 2. Check if the token is expired
        $expires_at = strtotime($request['expires_at']);
        if (time() > $expires_at) {
            // Token is expired, delete it
            $conn->query("DELETE FROM pending_verifications WHERE token = '$token'");
            header('Location: request_account.php?status=token_expired');
            exit();
        }
        
        // 3. Token is valid and not expired. Move user to account_requests.
        
        // Use a transaction for safety
        $conn->begin_transaction();
        
        try {
            
            // --- THIS IS THE FIX ---
            // The INSERT query now includes 'requested_by_user_id' and provides 'NULL'
            $stmt_insert = $conn->prepare(
                "INSERT INTO account_requests (full_name, username, email, requested_role, status, requested_by_user_id, created_at) 
                 VALUES (?, ?, ?, ?, 'pending', NULL, NOW())"
            );
            // The bind_param is still "ssss" because we only have 4 variable placeholders (?)
            $stmt_insert->bind_param("ssss", 
                $request['full_name'], 
                $request['username'], 
                $request['email'], 
                $request['requested_role']
            );
            // --- END OF FIX ---

            $stmt_insert->execute();
            
            // Delete the token so it can't be used again
            $stmt_delete = $conn->prepare("DELETE FROM pending_verifications WHERE token = ?");
            $stmt_delete->bind_param("s", $token);
            $stmt_delete->execute();
            
            // If all good, commit
            $conn->commit();
            
            // Redirect to login page with a success message
            header('Location: login.php?status=verified');
            exit();

        } catch (Exception $e) {
            // Something went wrong with the transaction, roll back
            $conn->rollback();
            error_log("Verification Error (Transaction): {$e->getMessage()}");
            header('Location: request_account.php?status=invalid_token');
            exit();
        }

    } else {
        // Token not found
        header('Location: request_account.php?status=invalid_token');
        exit();
    }
    
} catch (Exception $e) {
    error_log("Verification Error (Main): {$e->getMessage()}");
    header('Location: request_account.php?status=invalid_token');
    exit();
}
?>