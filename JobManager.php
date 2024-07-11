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

            $ignore = implode(',', $ignoreOptions);
            $jobId = $this->db->insert('jobs', [
                'status' => 'pending',
                'file1' => $file1,
                'file2' => $file2,
                'ignore_options' => $ignore
            ]);

            $this->db->commit();

            // Process the job immediately
            if ($useStreaming) {
                $this->processJobStreaming($jobId);
            } else {
                $this->processJob($jobId);
            }

            return $jobId;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Failed to create job: " . $e->getMessage());
            throw $e;
        }
    }

    public function processJob($jobId) {
        try {
            $this->db->beginTransaction();

            // Update job status to processing
            $this->db->update('jobs', ['status' => 'processing'], 'id = ?', [$jobId]);

            // Get job details
            $job = $this->db->fetchOne("SELECT file1, file2, ignore_options FROM jobs WHERE id = ?", [$jobId]);
            
            if (!$job) {
                throw new Exception("Job not found");
            }

            $ignoreOptions = explode(',', $job['ignore_options']);
            
            // Process VMF files
            try {
                $parsedVMF1 = $this->parser->parseVMF($job['file1']);
                $parsedVMF2 = $this->parser->parseVMF($job['file2']);
            } catch (VMFParserException $e) {
                throw new Exception("Error parsing VMF file: " . $e->getMessage());
            }
            
            $comparisonResult = $this->comparator->compareVMF($parsedVMF1, $parsedVMF2, $ignoreOptions);
            
            // Store results
            $differences = json_encode($comparisonResult['differences']);
            $stats = json_encode($comparisonResult['stats']);
            $this->db->insert('results', [
                'job_id' => $jobId,
                'differences' => $differences,
                'stats' => $stats
            ]);
            
            // Update job status to completed
            $this->db->update('jobs', ['status' => 'completed'], 'id = ?', [$jobId]);

            $this->db->commit();

            // Clean up files
            $this->cleanupFiles($job['file1'], $job['file2']);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error processing job $jobId: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            
            // Update job status to failed
            $this->db->update('jobs', ['status' => 'failed'], 'id = ?', [$jobId]);
            
            // Store error message in results
            $errorMessage = json_encode(['error' => $e->getMessage()]);
            $this->db->insert('results', [
                'job_id' => $jobId,
                'differences' => $errorMessage,
                'stats' => $errorMessage
            ]);

            throw $e;
        }
    }

    public function processJobStreaming($jobId) {
        try {
            $this->db->beginTransaction();

            // Update job status to processing
            $this->db->update('jobs', ['status' => 'processing'], 'id = ?', [$jobId]);

            // Get job details
            $job = $this->db->fetchOne("SELECT file1, file2, ignore_options FROM jobs WHERE id = ?", [$jobId]);
            
            if (!$job) {
                throw new Exception("Job not found");
            }

            $ignoreOptions = explode(',', $job['ignore_options']);
            
            // Process VMF files using streaming
            $comparisonResult = $this->comparator->compareVMFStreaming($job['file1'], $job['file2'], $ignoreOptions);
            
            // Store results
            $differences = json_encode($comparisonResult['differences']);
            $stats = json_encode($comparisonResult['stats']);
            $this->db->insert('results', [
                'job_id' => $jobId,
                'differences' => $differences,
                'stats' => $stats
            ]);
            
            // Update job status to completed
            $this->db->update('jobs', ['status' => 'completed'], 'id = ?', [$jobId]);

            $this->db->commit();

            // Clean up files
            $this->cleanupFiles($job['file1'], $job['file2']);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error processing job $jobId: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            
            // Update job status to failed
            $this->db->update('jobs', ['status' => 'failed'], 'id = ?', [$jobId]);
            
            // Store error message in results
            $errorMessage = json_encode(['error' => $e->getMessage()]);
            $this->db->insert('results', [
                'job_id' => $jobId,
                'differences' => $errorMessage,
                'stats' => $errorMessage
            ]);

            throw $e;
        }
    }

    public function getJobStatus($jobId) {
        try {
            $result = $this->db->fetchOne("SELECT status FROM jobs WHERE id = ?", [$jobId]);
            return $result ? $result['status'] : null;
        } catch (Exception $e) {
            error_log("Error in getJobStatus: " . $e->getMessage());
            throw $e;
        }
    }

    public function fetchGetJobResult($jobId) {
        try {
            $result = $this->db->fetchOne("SELECT differences, stats FROM results WHERE job_id = ?", [$jobId]);
            
            if (!$result) {
                throw new Exception("No results found for job ID: " . $jobId);
            }
            
            return [
                'differences' => json_decode($result['differences'], true),
                'stats' => json_decode($result['stats'], true)
            ];
        } catch (Exception $e) {
            error_log("Error in getJobResult: " . $e->getMessage());
            throw $e;
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
                mkdir($uploadDir, 0755, true);
            }

            $filename = sprintf('%s.%s', sha1_file($file['tmp_name']), $ext);
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                throw new RuntimeException('Failed to move uploaded file.');
            }

            return $uploadDir . $filename;
        } catch (RuntimeException $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public function cleanupTemporaryFiles() {
        $uploadDir = $this->config['upload_dir'];
        $files = glob($uploadDir . '*'); // Get all file names
        $currentTime = time();
    
        foreach($files as $file) {
            if(is_file($file)) {
                if($currentTime - filemtime($file) >= 24 * 3600) { // Older than 24 hours
                    unlink($file);
                }
            }
        }
    
        // Optionally, log the cleanup
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