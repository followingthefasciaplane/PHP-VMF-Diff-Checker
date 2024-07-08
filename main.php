<?php
// Load configuration
$config = require_once 'config.php';

// Autoloader function
spl_autoload_register(function ($class_name) {
    include $class_name . '.php';
});

// Initialize objects
try {
    $db = new Database($config);
    $parser = new VMFParser();
    $comparator = new VMFComparator();
    $jobManager = new JobManager($db, $parser, $comparator, $config);
    $ajaxHandler = new AjaxHandler($jobManager, $config);
} catch (Exception $e) {
    die("Initialization error: " . $e->getMessage());
}

// Handle request
try {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        // AJAX request
        header('Content-Type: application/json');
        echo $ajaxHandler->handleRequest();
    } else {
        // Normal request - output HTML
        // Don't know what to do with this yet
    }
} catch (Exception $e) {
    // Log the error
    error_log("Error processing request: " . $e->getMessage());
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['error' => 'An unexpected error occurred. Please try again later.']);
    } else {
        echo "An unexpected error occurred. Please try again later.";
    }
}

// Cleanup
try {
    // Close database connection
    if (isset($db)) {
        $db->close();
    }

    // Clean up any temporary files created during the request
    if (isset($jobManager)) {
        $jobManager->cleanupTemporaryFiles();
    }

    // Reset parser and comparator
    if (isset($parser)) {
        $parser->reset();
    }
    if (isset($comparator)) {
        $comparator->reset();
    }

    // Flush output buffers
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
} catch (Exception $e) {
    // Log cleanup errors, but don't expose them to the user
    error_log("Error during cleanup: " . $e->getMessage());
}

// Consider commenting this out if you don't want to do this here. 
// It might slow things down a bit. It's better suited for a cron job.
if (isset($jobManager)) {
    $jobManager->processPendingJobs(); // Process pending jobs and clean up old jobs
    $jobManager->cleanupOldJobs(7); 
}