<?php

use app\core\services\AuthenticationService;
use app\core\models\dto\UserDto;

require_once '../../app/config/AppConfig.php';
require_once '../../app/config/DBConfig.php';
require_once '../../app/vendor/autoload.php';

session_start();

try {
    $authService = new AuthenticationService();


} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}