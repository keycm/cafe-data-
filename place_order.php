<?php
// 1. ENABLE ERROR REPORTING (Remove these 3 lines once the website is live)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session to get user_id
session_start();

// 2. CONNECT TO DATABASE
require_once 'db_connect.php'; // Ensure this points to your actual DB connection file

// Set header to return JSON so JavaScript can read it easily
header('Content-Type: application/json');

try {
    // 3. GET JSON DATA FROM JAVASCRIPT
    // (This fixes the issue if JS is sending JSON but PHP is looking for $_POST)
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);

    // Check if data actually arrived
    if (!$data || !isset($data['cart'])) {
        throw new Exception("No cart data received. PHP saw: " . $json_data);
    }

    $cart = $data['cart'];
    $total_amount = $data['total_amount'] ?? 0;
    
    // Check for user ID (Fallback to 0 or a Guest ID if you allow guest checkout)
    $user_id = $_SESSION['user_id'] ?? null; 
    
    if (!$user_id) {
        // If your database REQUIRES a user_id, it will fail here.
        // Change this logic if you allow guests!
        throw new Exception("User is not logged in. Missing session user_id.");
    }

    // 4. START DATABASE TRANSACTION
    // This ensures if order_items fail, the whole order is cancelled
    $conn->begin_transaction();

    // 5. INSERT INTO ORDERS TABLE
    // *** IMPORTANT: Change these column names to match your phpMyAdmin exactly! ***
    $stmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, status, created_at) VALUES (?, ?, 'Pending', NOW())");
    
    if (!$stmt) {
        throw new Exception("SQL Prepare Error (Orders): " . $conn->error);
    }

    $stmt->bind_param("id", $user_id, $total_amount);
    
    if (!$stmt->execute()) {
        throw new Exception("SQL Execute Error (Orders): " . $stmt->error);
    }

    // Get the ID of the order we just created
    $order_id = $conn->insert_id;

    // 6. INSERT CART ITEMS INTO ORDER_ITEMS TABLE
    // *** IMPORTANT: Change these column names to match your phpMyAdmin exactly! ***
    $stmt_items = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    
    if (!$stmt_items) {
        throw new Exception("SQL Prepare Error (Order Items): " . $conn->error);
    }

    foreach ($cart as $item) {
        $product_id = $item['id'];
        $quantity = $item['quantity'];
        $price = $item['price'];

        $stmt_items->bind_param("iiid", $order_id, $product_id, $quantity, $price);
        
        if (!$stmt_items->execute()) {
            throw new Exception("SQL Execute Error on Product ID {$product_id}: " . $stmt_items->error);
        }
    }

    // 7. COMMIT TRANSACTION (Save everything permanently)
    $conn->commit();

    // Send success back to Javascript
    echo json_encode([
        "success" => true, 
        "message" => "Order placed successfully!",
        "order_id" => $order_id
    ]);

} catch (Exception $e) {
    // If ANYTHING goes wrong, cancel the database save and send the exact error back
    if (isset($conn) && $conn->ping()) {
        $conn->rollback(); 
    }
    
    // Log the error for you to read
    error_log("Checkout Error: " . $e->getMessage());
    
    // Send the error back to the browser console
    echo json_encode([
        "success" => false, 
        "message" => $e->getMessage()
    ]);
}
?>