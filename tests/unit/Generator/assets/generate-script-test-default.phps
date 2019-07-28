<?php

$method = $_SERVER["REQUEST_METHOD"];
$path = rawurldecode(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH));
$segments = $path === "/" ? [] : explode("/", trim($path, "/"));
$allowedMethods = [];

switch ($segments[0] ?? "\0") {
    case "\0":
        $allowedMethods = ['GET'];
        switch ($method) {
            case 'GET':
                return info();
        }
        break 1;
}

if ($allowedMethods === []) {
    http_response_code(404);
    echo "Not Found";
} else {
    http_response_code(405);
    header('Allow: ' . join(', ', $allowedMethods));
    echo "Method Not Allowed";
}