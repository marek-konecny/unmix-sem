<?php
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptDirectory = dirname($_SERVER['SCRIPT_NAME']);

if ($scriptDirectory === '/' || $scriptDirectory === '\\')
    $routePath = $requestUri;
else {
    if (strpos($requestUri, $scriptDirectory) === 0)
        $routePath = substr($requestUri, strlen($scriptDirectory));
    else
        $routePath = $requestUri;
}
$routePath = trim($routePath, '/');

$isFrontendRoute = $routePath === '' || $routePath === 'index.php' || $routePath === 'home' || $routePath === 'frontend';
if ($isFrontendRoute) {
    header('Content-Type: text/html');
    readfile(__DIR__ . '/frontend.html');
    exit;
}

if (strpos($routePath, 'api') !== 0) {
    http_response_code(404);
    echo "404 Not Found";
    exit;
}

$apiRouteString = trim(substr($routePath, 3), '/');
$apiRouteSegments = explode('/', $apiRouteString);

$apiEndpoint = $apiRouteSegments[0] ?? '';
$requestedSceneId = isset($apiRouteSegments[1]) ? urldecode($apiRouteSegments[1]) : null;
$requestedImageId = isset($apiRouteSegments[2]) ? urldecode($apiRouteSegments[2]) : null;

ob_start();

$errorLogFilePath = __DIR__ . '/api_errors.log';

function appendToErrorLog($errorLevel, $errorMessage, $errorFile = 'Unknown', $errorLine = 0) {
    global $errorLogFilePath;
    $currentTimestamp = date('Y-m-d H:i:s');
    $formattedLogEntry = "[$currentTimestamp] [$errorLevel] $errorMessage in $errorFile on line $errorLine" . PHP_EOL;
    @file_put_contents($errorLogFilePath, $formattedLogEntry, FILE_APPEND | LOCK_EX);
}

function extractHttpRequestHeaders() {
    $extractedHeaders = array();
    foreach($_SERVER as $serverVariableKey => $serverVariableValue) {
        if (substr($serverVariableKey, 0, 5) <> 'HTTP_')
            continue;
        $formattedHeaderName = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($serverVariableKey, 5)))));
        $extractedHeaders[$formattedHeaderName] = $serverVariableValue;
    }
    return $extractedHeaders;
}

set_error_handler(function($errorNumber, $errorMessage, $errorFile, $errorLine) {
    appendToErrorLog("PHP Error ($errorNumber)", $errorMessage, $errorFile, $errorLine);
    return true;
});

set_exception_handler(function($caughtException) {
    appendToErrorLog("Uncaught Exception", $caughtException->getMessage(), $caughtException->getFile(), $caughtException->getLine());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Internal Server Error"]);
    exit;
});

register_shutdown_function(function() {
    $lastOccurredError = error_get_last();
    $isFatalError = $lastOccurredError && ($lastOccurredError['type'] === E_ERROR || $lastOccurredError['type'] === E_PARSE);
    if ($isFatalError) {
        appendToErrorLog("FATAL ERROR", $lastOccurredError['message'], $lastOccurredError['file'], $lastOccurredError['line']);
        if (!headers_sent()) {
            http_response_code(500);
            echo json_encode(["status" => "error"]);
        }
    }
});

$databaseHost = getenv('DB_HOST');
$databaseName = getenv('DB_NAME');
$databaseUsername = getenv('DB_USER');
$databasePassword = getenv('DB_PASS');
$absoluteImageDirectory = __DIR__ . '/images/';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Content-Type: application/json");

try {
    $databaseConnection = new PDO("mysql:host=$databaseHost;dbname=$databaseName;charset=utf8mb4", $databaseUsername, $databasePassword);
    $databaseConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $databaseConnection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $databaseException) {
    appendToErrorLog("DB Fail", $databaseException->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "DB Connection Failed"]);
    ob_end_flush();
    exit;
}

$apiAuditLogEntry = [
    'api_key_id' => null,
    'endpoint'   => $_SERVER['REQUEST_URI'],
    'method'     => $_SERVER['REQUEST_METHOD'],
    'headers'    => json_encode(extractHttpRequestHeaders()),
    'query'      => $_SERVER['QUERY_STRING'] ?? '',
    'body'       => file_get_contents('php://input'),
    'response'   => '',
    'skip_resp'  => false
];

$providedApiKey = $_GET['key'] ?? '';

try {
    $apiKeyAuthenticationStatement = $databaseConnection->prepare("SELECT id, forced_variant, mobile_allowed FROM api_keys WHERE key_value = ? LIMIT 1");
    $apiKeyAuthenticationStatement->execute([$providedApiKey]);
    $authenticatedUserRecord = $apiKeyAuthenticationStatement->fetch();

    if (!$authenticatedUserRecord) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        flushResponseAndSaveAuditLog($databaseConnection, $apiAuditLogEntry);
    }
    $apiAuditLogEntry['api_key_id'] = $authenticatedUserRecord['id'];
} catch (Exception $authenticationException) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Auth Error"]);
    exit;
}

try {
    switch ($apiEndpoint) {
        case 'init':
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                returnHttp405MethodNotAllowed();
                break;
            }
            
            if (isset($authenticatedUserRecord['forced_variant']) && $authenticatedUserRecord['forced_variant'] !== null)
                $assignedVariant = (int)$authenticatedUserRecord['forced_variant'];
            else
                $assignedVariant = rand(1, 2);

            $foundImageFiles = glob($absoluteImageDirectory . "*/*.{jpg,jpeg,png}", GLOB_BRACE);
            $formattedImageList = [];
            if ($foundImageFiles) {
                foreach($foundImageFiles as $imageFilepath)
                    $formattedImageList[] = [
                        "scene_id" => basename(dirname($imageFilepath)),
                        "id" => pathinfo($imageFilepath, PATHINFO_FILENAME)
                    ];
            }
            echo json_encode(["status" => "success", "variant" => $assignedVariant, "mobileAllowed" => (bool)$authenticatedUserRecord['mobile_allowed'], "images" => $formattedImageList]);
            break;

        case 'image':
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                returnHttp405MethodNotAllowed();
                break;
            }
            if (!$requestedSceneId || !$requestedImageId) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Scene ID and image ID required"]);
                break;
            }
            $sanitizedSceneId = basename($requestedSceneId);
            $sanitizedImageId = basename($requestedImageId);
            $matchingImageFilesArray = glob($absoluteImageDirectory . $sanitizedSceneId . '/' . $sanitizedImageId . ".*");
           
            if (!empty($matchingImageFilesArray)) {
                $targetImageFilePath = $matchingImageFilesArray[0];
                $targetImageExtension = pathinfo($targetImageFilePath, PATHINFO_EXTENSION);
                $rawImageData = file_get_contents($targetImageFilePath);
                if ($rawImageData !== false) {
                    $base64EncodedImageString = 'data:image/' . $targetImageExtension . ';base64,' . base64_encode($rawImageData);
                    echo json_encode(["status" => "success", "scene_id" => $sanitizedSceneId, "id" => $sanitizedImageId, "data" => $base64EncodedImageString]);
                    $apiAuditLogEntry['skip_resp'] = true;
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Read error"]);
                }
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Not found"]);
            }
            break;

        case 'submit':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                returnHttp405MethodNotAllowed();
                break;
            }
            $decodedJsonPayloadArray = json_decode($apiAuditLogEntry['body'], true);
            if (!is_array($decodedJsonPayloadArray) || empty($decodedJsonPayloadArray)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Invalid payload"]);
                break;
            }
            
            if (!isset($decodedJsonPayloadArray['variant'])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Missing variant in payload"]);
                break;
            }
            
            $submittedVariant = (int)$decodedJsonPayloadArray['variant'];
            
            $insertTrialRecordStatement = $databaseConnection->prepare("INSERT INTO trials (api_key_id, variant, results) VALUES (?, ?, ?)");
            $insertTrialRecordStatement->execute([$authenticatedUserRecord['id'], $submittedVariant, $apiAuditLogEntry['body']]);
            http_response_code(201);
            echo json_encode(["status" => "success"]);
            break;

        default:
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Endpoint not found: $apiEndpoint"]);
            break;
    }
} catch (Exception $endpointProcessingException) {
    appendToErrorLog("Endpoint Error", $endpointProcessingException->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Processing Error"]);
}

flushResponseAndSaveAuditLog($databaseConnection, $apiAuditLogEntry);

function returnHttp405MethodNotAllowed() {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}

function flushResponseAndSaveAuditLog($databaseConnection, $apiAuditLogEntry) {
    $capturedResponseBody = ob_get_contents();
    ob_end_flush();
    flush();
    $dataToLogAsResponse = $apiAuditLogEntry['skip_resp'] ? '[IMAGE DATA]' : $capturedResponseBody;
    try {
        $insertLogEntryStatement = $databaseConnection->prepare("INSERT INTO request_logs (api_key_id, endpoint, method, headers, query_params, request_body, response_body) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertLogEntryStatement->execute([$apiAuditLogEntry['api_key_id'], $apiAuditLogEntry['endpoint'], $apiAuditLogEntry['method'], $apiAuditLogEntry['headers'], $apiAuditLogEntry['query'], $apiAuditLogEntry['body'], $dataToLogAsResponse]);
    } catch (Exception $loggingException) {}
    exit;
}
?>
