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
                return call('info', '', NULL);
        }
        break 1;
    case "users":
        switch ($segments[1] ?? "\0") {
            case "\0":
                $allowedMethods = ['GET', 'POST'];
                switch ($method) {
                    case 'GET':
                        return call('user', 'list', NULL);
                    case 'POST':
                        return call('user', 'add', NULL);
                }
                break 2;
            default:
                switch ($segments[2] ?? "\0") {
                    case "\0":
                        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
                        switch ($method) {
                            case 'GET':
                                return call('user', 'get', $segments[1]);
                            case 'POST':
                                return call('user', 'update', $segments[1]);
                            case 'PUT':
                                return call('user', 'update', $segments[1]);
                            case 'DELETE':
                                return call('user', 'delete', $segments[1]);
                        }
                        break 3;
                    case "photos":
                        switch ($segments[3] ?? "\0") {
                            case "\0":
                                $allowedMethods = ['GET', 'POST'];
                                switch ($method) {
                                    case 'GET':
                                        return call('', 'list-photos', $segments[1]);
                                    case 'POST':
                                        return call('', 'add-photos', $segments[1]);
                                }
                                break 4;
                        }
                        break 3;
                }
                break 2;
        }
        break 1;
    case "export":
        switch ($segments[1] ?? "\0") {
            case "\0":
                $allowedMethods = ['POST'];
                switch ($method) {
                    case 'POST':
                        return require 'scripts/export.php';
                }
                break 2;
        }
        break 1;
}

return call('', 'not-found', $id ?? NULL);