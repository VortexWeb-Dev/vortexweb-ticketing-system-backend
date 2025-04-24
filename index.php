<?php

declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cache-Control");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

spl_autoload_register(function ($class) {
    require __DIR__ . "/src/{$class}.php";
});

$endpoint = $_GET['endpoint'] ?? null;
$id = $_GET['id'] ?? null;

$controllerClass = $endpoint ? ucfirst($endpoint) . 'Controller' : null;

if ($endpoint && class_exists($controllerClass)) {
    $controller = new $controllerClass();
    $controller->processRequest($_SERVER['REQUEST_METHOD'], $id);
} else {
    header("Content-Type: application/json");
    http_response_code(404);
    echo json_encode(["error" => "Resource '$endpoint' not found"]);
    exit;
}

exit;
