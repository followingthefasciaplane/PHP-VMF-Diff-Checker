<?php

class JobManager {
    private $db;
    private $parser;
    private $comparator;
    private $config;

    public function __construct(Database $db, VMFParser $parser, VMFComparator $comparator, array $config) {
        $this->db = $db;
        $this->parser = $parser;
        $this->comparator = $comparator;
        $this->config = $config;
    }

    public function createJob($file1, $file2, $ignoreOptions, $useStreaming = false) {
        try {
            $this->db->beginTransaction();
            error_log("Starting job creation for files: $file1, $file2");
            
            $ignore = implode(',', $ignoreOptions);
            $jobId = $this->db->insert('jobs', [
                'status' => 'pending',
                'file1' => $file1,
                'file2' => $file2,
                'ignore_options' => $ignore,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            error_log("Job inserted with ID: $jobId");
            
            $this->db->commit();
            error_log("Transaction committed");
            
            $this->queueJob($jobId, $useStreaming);
            error_log("Job queued");
            
            return $jobId;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Failed to create job: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            throw new JobManagerException("Failed to create job", 0, $e);
        }
    }

    private function queueJob($jobId, $useStreaming) {
        // Implement job queuing mechanism here
        // For example, you could use a message queue system or a simple database table
        // For now, we'll process it immediately, but this should be replaced with a proper queuing system
        if ($useStreaming) {
            $this->processJobStreaming($jobId);
        } else {
            $this->processJob($jobId);
        }
    }

    public function processJob($jobId) {
        try {
            $this->db->beginTransaction();
    
            $this->updateJobStatus($jobId, 'processing');
    
            $job = $this->getJobDetails($jobId);
            $ignoreOptions = explode(',', $job['ignore_options']);
            
            error_log("Processing job $jobId: Parsing VMF1");
            $parsedVMF1 = $this->parser->parseVMF($job['file1']);
            
            error_log("Processing job $jobId: Parsing VMF2");
            $parsedVMF2 = $this->parser->parseVMF($job['file2']);
            
            error_log("Processing job $jobId: Comparing VMFs");
            $comparisonResult = $this->comparator->compareVMF($parsedVMF1, $parsedVMF2, $ignoreOptions);
            
            error_log("Processing job $jobId: Storing results");
            $this->storeJobResults($jobId, $comparisonResult);
            
            $this->updateJobStatus($jobId, 'completed');
    
            $this->db->commit();
    
            $this->cleanupFiles($job['file1'], $job['file2']);
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error processing job $jobId: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            $this->handleJobError($jobId, $e);
        }
    }

    public function processJobStreaming($jobId) {
        try {
            $this->db->beginTransaction();

            $this->updateJobStatus($jobId, 'processing');

            $job = $this->getJobDetails($jobId);
            $ignoreOptions = explode(',', $job['ignore_options']);
            
            $comparisonResult = $this->comparator->compareVMFStreaming($job['file1'], $job['file2'], $ignoreOptions);
            
            $this->storeJobResults($jobId, $comparisonResult);
            
            $this->updateJobStatus($jobId, 'completed');

            $this->db->commit();

            $this->cleanupFiles($job['file1'], $job['file2']);
        } catch (Exception $e) {
            $this->db->rollback();
            $this->handleJobError($jobId, $e);
        }
    }

    private function updateJobStatus($jobId, $status) {
        $this->db->update('jobs', ['status' => $status], 'id = ?', [$jobId]);
    }

    private function getJobDetails($jobId) {
        $job = $this->db->fetchOne("SELECT file1, file2, ignore_options FROM jobs WHERE id = ?", [$jobId]);
        if (!$job) {
            throw new JobManagerException("Job not found");
        }
        return $job;
    }

    private function storeJobResults($jobId, $comparisonResult) {
        $differences = json_encode($comparisonResult['differences']);
        $stats = json_encode($comparisonResult['stats']);
        $this->db->insert('results', [
            'job_id' => $jobId,
            'differences' => $differences,
            'stats' => $stats
        ]);
    }

    private function handleJobError($jobId, Exception $e) {
        error_log("Error processing job $jobId: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $this->updateJobStatus($jobId, 'failed');
        $errorMessage = json_encode(['error' => $e->getMessage()]);
        $this->db->insert('results', [
            'job_id' => $jobId,
            'differences' => $errorMessage,
            'stats' => $errorMessage
        ]);
        throw new JobManagerException("Error processing job", 0, $e);
    }

    public function getJobStatus($jobId) {
        try {
            $result = $this->db->fetchOne("SELECT status FROM jobs WHERE id = ?", [$jobId]);
            return $result ? $result['status'] : null;
        } catch (Exception $e) {
            error_log("Error in getJobStatus: " . $e->getMessage());
            throw new JobManagerException("Failed to get job status", 0, $e);
        }
    }

    public function fetchGetJobResult($jobId) {
        try {
            $result = $this->db->fetchOne("SELECT differences, stats FROM results WHERE job_id = ?", [$jobId]);
            
            if (!$result) {
                throw new JobManagerException("No results found for job ID: " . $jobId);
            }
            
            return [
                'differences' => json_decode($result['differences'], true),
                'stats' => json_decode($result['stats'], true)
            ];
        } catch (Exception $e) {
            error_log("Error in getJobResult: " . $e->getMessage());
            throw new JobManagerException("Failed to fetch job result", 0, $e);
        }
    }

    public function cleanupFiles($file1, $file2) {
        $files = [$file1, $file2];
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

// In JobManager.php
    public function handleVMFUpload($file) {
        try {
            if (!isset($file['error']) || is_array($file['error'])) {
                throw new RuntimeException('Invalid parameters.');
            }

            switch ($file['error']) {
                case UPLOAD_ERR_OK:
                    break;
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    throw new RuntimeException('Exceeded filesize limit.');
                default:
                    throw new RuntimeException('Unknown error.');
            }

            if ($file['size'] > $this->config['max_file_size']) {
                throw new RuntimeException('File size exceeds the limit of ' . ($this->config['max_file_size'] / 1024 / 1024) . ' MB');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            if (false === $ext = array_search(
                $finfo->file($file['tmp_name']),
                array(
                    'vmf' => 'text/plain',
                ),
                true
            )) {
                throw new RuntimeException('Invalid file format.');
            }

            $uploadDir = $this->config['upload_dir'];
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    throw new RuntimeException('Failed to create upload directory.');
                }
            }

            $filename = sprintf('%s.%s', sha1_file($file['tmp_name']), $ext);
            $uploadedFilePath = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $uploadedFilePath)) {
                throw new RuntimeException('Failed to move uploaded file.');
            }

            // Check if the file is readable
            if (!is_readable($uploadedFilePath)) {
                throw new RuntimeException('Uploaded file is not readable.');
            }

            // Validate VMF file content
            $fileContent = file_get_contents($uploadedFilePath);
            if ($fileContent === false) {
                throw new RuntimeException('Failed to read uploaded file contents.');
            }

            if (strpos($fileContent, 'versioninfo') === false) {
                unlink($uploadedFilePath); // Remove invalid file
                throw new RuntimeException('Invalid VMF file format.');
            }

            return $uploadedFilePath;
        } catch (RuntimeException $e) {
            error_log("Error handling VMF upload: " . $e->getMessage());
            return false;
        }
    }

    public function cleanupTemporaryFiles() {
        $uploadDir = $this->config['upload_dir'];
        $files = glob($uploadDir . '*');
        $currentTime = time();
    
        foreach($files as $file) {
            if(is_file($file) && ($currentTime - filemtime($file) >= 24 * 3600)) {
                unlink($file);
            }
        }
    
        error_log("Temporary files cleanup completed at " . date('Y-m-d H:i:s'));
    }

    public function processPendingJobs() {
        $pendingJobs = $this->db->fetchAll("SELECT id FROM jobs WHERE status = 'pending' LIMIT 5");
        foreach ($pendingJobs as $job) {
            try {
                $this->processJob($job['id']);
            } catch (Exception $e) {
                error_log("Failed to process job {$job['id']}: " . $e->getMessage());
            }
        }
    }

    public function cleanupOldJobs($daysOld = 7) {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysOld days"));
        $oldJobs = $this->db->fetchAll("SELECT id, file1, file2 FROM jobs WHERE created_at < ?", [$cutoffDate]);
        
        foreach ($oldJobs as $job) {
            $this->cleanupFiles($job['file1'], $job['file2']);
            $this->db->query("DELETE FROM results WHERE job_id = ?", [$job['id']]);
            $this->db->query("DELETE FROM jobs WHERE id = ?", [$job['id']]);
        }
    }
}

class JobManagerException extends Exception {}

?>