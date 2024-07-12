<?php
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

// Initialize objects
try {
    $db = new Database($config);
    $parser = new VMFParser($config);
    $comparator = new VMFComparator($parser, $config);
    $jobManager = new JobManager($db, $parser, $comparator, $config);
    $ajaxHandler = new AjaxHandler($jobManager, $config);
} catch (Exception $e) {
    logError("Initialization error", $e);
    die("Initialization error: " . $e->getMessage());
}

// Handle request
try {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        // AJAX request
        header('Content-Type: application/json');
        $result = $ajaxHandler->handleRequest();
        if ($result === false || $result === null) {
            throw new Exception("AjaxHandler returned invalid result");
        }
        echo $result;
    } else {
        // Normal request - output HTML
        // include 'index.html';
    }
} catch (Exception $e) {
    logError("Error processing request", $e);
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['error' => 'An unexpected error occurred. Please try again later.']);
    } else {
        echo "An unexpected error occurred. Please try again later.";
    }
}

// Cleanup
try {
    if (isset($db)) {
        $db->close();
    }

    if (isset($jobManager)) {
        $jobManager->cleanupTemporaryFiles();
    }

    if (isset($comparator) && method_exists($comparator, 'reset')) {
        $comparator->reset();
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
} catch (Exception $e) {
    logError("Error during cleanup", $e);
}

// Process pending jobs and clean up old jobs (consider moving to a cron job)
if (isset($jobManager)) {
    $jobManager->processPendingJobs();
    $jobManager->cleanupOldJobs(7);
}

function logError($message, Exception $e) {
    error_log($message . ": " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
}
