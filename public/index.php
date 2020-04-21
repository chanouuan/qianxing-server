<?php

date_default_timezone_set('PRC');

if (isset($_SERVER['PATH_INFO'])) {
    if (strpos($_SERVER['PATH_INFO'], '.')) {
        http_response_code(404);
        exit(0);
    }
}

header('Access-Control-Allow-Origin: *');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
	http_response_code(200);
    exit(0);
}

define('APPLICATION_PATH', dirname(__DIR__));
define('APPLICATION_URL', isset($_SERVER['HTTP_HOST']) ? rtrim(implode('', [$_SERVER['REQUEST_SCHEME'], '://', $_SERVER['HTTP_HOST'], str_replace('index.php', '', $_SERVER['SCRIPT_NAME'])]), '/') : null);
define('TIMESTAMP', $_SERVER['REQUEST_TIME']);
define('MICROTIME', isset($_SERVER['REQUEST_TIME_FLOAT']) ? $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true));
if (isset($_SERVER['HTTP_APIVERSION'])) {
    define('APIVERSION', 'v' . intval($_SERVER['HTTP_APIVERSION']));
} else if (isset($_POST['apiversion'])) {
    define('APIVERSION', 'v' . intval($_POST['apiversion']));
} else if (isset($_GET['apiversion'])) {
    define('APIVERSION', 'v' . intval($_GET['apiversion']));
} else {
    define('APIVERSION', 'v1');
}

$composerPath = APPLICATION_PATH . '/vendor/autoload.php';
if (file_exists($composerPath)) {
    require $composerPath;
}
require APPLICATION_PATH . '/application/library/Common.php';
require APPLICATION_PATH . '/application/library/Init.php';

$controller->run();
