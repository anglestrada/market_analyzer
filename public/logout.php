<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    logout_user();
}
// Note: while DEV_AUTO_LOGIN is on, localhost requests are logged straight back in from .env.
redirect('/login.php');
