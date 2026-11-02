<?php
require dirname(__DIR__) . '/bootstrap.php';
$db = App\Config\Database::getInstance();

$adminHash = password_hash('Admin@1234', PASSWORD_BCRYPT, ['cost' => 12]);
$agentHash = password_hash('Agent@1234', PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
$stmt->execute([$adminHash, 'admin@dhaba.ai']);
$stmt->execute([$agentHash, 'agent@dhaba.ai']);

echo "Updated passwords successfully.\n";
