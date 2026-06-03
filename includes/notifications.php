<?php
function sendNotification($pdo, $user_id, $title, $message, $type = 'system') {
    $pdo->prepare("INSERT INTO notifications (notif_user_id, notif_title, notif_message, notif_type) VALUES (?, ?, ?, ?)")
        ->execute([$user_id, $title, $message, $type]);
}

function sendNotificationAll($pdo, $title, $message, $type = 'system') {
    $users = $pdo->query("SELECT user_id FROM users WHERE user_role = 'customer' AND user_is_active = 1")
                 ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        sendNotification($pdo, $user['user_id'], $title, $message, $type);
    }
}
?>