<?php declare(strict_types=1);

require "vendor/autoload.php";

error_reporting(E_ALL ^ E_DEPRECATED);
error_log($_SERVER['REQUEST_METHOD']. " ".$_SERVER['REQUEST_URI']);

$env = Dotenv\Dotenv::createArrayBacked(__DIR__)->load();

if (empty($env['APPROOV_BASE64_SECRET'])) {
    throw new Exception("Missing in the .env file the variable: APPROOV_BASE64_SECRET");
}

define('APPROOV_BASE64_SECRET', base64_decode($env['APPROOV_BASE64_SECRET'], true));

function verifyApproovToken(Array $headers): ?stdClass {
    try {
        if (empty($headers['Approov-Token'])) {
            // You may want to add some logging here
            return null;
        }

        $approov_token = $headers['Approov-Token'];

        return \Firebase\JWT\JWT::decode($approov_token, constant('APPROOV_BASE64_SECRET'), ['HS256']);

    } catch(\UnexpectedValueException $exception) {
        // You may want to add some logging here
        return null;
    } catch(\InvalidArgumentException $exception) {
        // You may want to add some logging here
        return null;
    } catch(\DomainException $exception) {
        // You may want to add some logging here
        return null;
    }

    // You may want to add some logging here
    return null;
}

function sendResponse(int $http_status_code, Array $response) {
    $response_body = json_encode((object)$response);
    $content_length = strlen($response_body);

    http_response_code($http_status_code);
    header("Content-Type: application/json");
    header("Content-Length: ${content_length}");

    echo "${response_body}";
}

$headers = getallheaders();
$approov_token_claims = verifyApproovToken($headers);

if (!$approov_token_claims) {
    sendResponse(401, []);
    exit;
}

switch ($_SERVER['REQUEST_URI']) {
    case "/":
        sendResponse(200, ["message" => "Hello, World!"]);
        break;

    default:
        sendResponse(401, []);
        break;
}
