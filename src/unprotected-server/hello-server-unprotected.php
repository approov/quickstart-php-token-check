<?php declare(strict_types=1);

error_log($_SERVER['REQUEST_METHOD']. " ".$_SERVER['REQUEST_URI']);

function sendResponse(int $http_status_code, Array $response) {
    $response_body = json_encode($response);
    $content_length = strlen($response_body);

    http_response_code($http_status_code);
    header("Content-Type: application/json");
    header("Content-Length: ${content_length}");

    echo "${response_body}";
}

switch ($_SERVER['REQUEST_URI']) {
    case "/":
        sendResponse(200, ["message" => "Hello, World!"]);
        break;

    default:
        sendResponse(401, []);
        break;
}
