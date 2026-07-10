<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
Auth::logout();
header('Location: index.php');
exit;
