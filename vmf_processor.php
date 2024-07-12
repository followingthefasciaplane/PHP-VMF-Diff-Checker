<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/rampslid/vmfcheck.rampsliders.wiki/error.log');

// Start output buffering
ob_start();

// Load configuration
$config = require_once 'config.php';

// Autoloader function
spl_autoload_register(function ($class_name) {
    $file = $class_name . '.php';
    if (file_exists($file)) {
        require_once $file;
    } else {
        throw new Exception("Unable to load class: $class_name");
    }
});

// Error logging helper function
function logError($message, $exception) {
    error_log($message . ": " . $exception->getMessage() . "\nStack trace: " . $exception->getTraceAsString());
}

// JSON response helper function
function jsonResponse($data, $statusCode = 200) {
    ob_clean(); // Clear any output buffered so far
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Check if it's an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Initialize objects
try {
    $db = new Database($config);
    $parser = new VMFParser($config);
    $comparator = new VMFComparator($parser, $config);
    $jobManager = new JobManager($db, $parser, $comparator, $config);
    $ajaxHandler = new AjaxHandler($jobManager, $config);
} catch (Exception $e) {
    logError("Initialization error", $e);
    if ($isAjax) {
        jsonResponse(['error' => 'Initialization error: ' . $e->getMessage()], 500);
    } else {
        die("Initialization error: " . $e->getMessage());
    }
}

// Handle request
try {
    if ($isAjax) {
        // AJAX request
        $result = $ajaxHandler->handleRequest();
        if ($result === false || $result === null) {
            throw new Exception("AjaxHandler returned invalid result");
        }
        
        // Output JSON
        jsonResponse(json_decode($result, true)); // Decode and re-encode to ensure it's valid JSON
    } else {
        // Normal request - output HTML
        include 'index.html';
    }
} catch (Throwable $e) {
    logError("Error processing request", $e);
    
    if ($isAjax) {
        jsonResponse([
            'error' => 'An unexpected error occurred. Please try again later.',
            'debug_message' => $e->getMessage(),
            'debug_trace' => $e->getTraceAsString()
        ], 500);
    } else {
        echo "An unexpected error occurred. Please try again later.";
    }
}

// End output buffering and flush
ob_end_flush();

// Cleanup and background tasks can be handled here if needed
// Consider moving these to a separate cron job or background process
// if (isset($jobManager)) {
//     $jobManager->processPendingJobs();
//     $jobManager->cleanupOldJobs(7);
// }
?>