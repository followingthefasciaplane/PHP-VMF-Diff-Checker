<?php
return [
    'db' => [
        'host' => '',
        'user' => '',
        'pass' => '',
        'name' => ''
    ],
    'upload_dir' => '', // Your uploaded VMFs are stored here temporarily
    'max_file_size' => 500 * 1024 * 1024, // 500 MB
    'items_per_page' => 100,
    'job_check_interval' => 20000, // 20 seconds in milliseconds
    'allowed_file_extensions' => ['vmf'],
    'ignore_options_default' => [],
    'error_log_file' => '', // Your error logs are stored here
];
