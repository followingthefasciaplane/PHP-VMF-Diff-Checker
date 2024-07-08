<?php

class AjaxHandler {
    private $jobManager;
    private $config;

    public function __construct(JobManager $jobManager, array $config) {
        $this->jobManager = $jobManager;
        $this->config = $config;
    }

    public function handleRequest() {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception("Invalid request method.");
            }

            if (!isset($_POST['action'])) {
                throw new Exception("No action specified.");
            }

            switch ($_POST['action']) {
                case 'upload':
                    return $this->handleUploadAction();
                case 'status':
                    return $this->handleStatusAction();
                case 'result':
                    return $this->handleResultAction();
                default:
                    throw new Exception("Invalid action specified.");
            }
        } catch (Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    private function handleUploadAction() {
        try {
            if (!isset($_FILES['vmf1']) || !isset($_FILES['vmf2'])) {
                throw new Exception("Please upload both VMF files.");
            }

            $vmf1Path = $this->jobManager->handleVMFUpload($_FILES['vmf1']);
            $vmf2Path = $this->jobManager->handleVMFUpload($_FILES['vmf2']);

            if ($vmf1Path === false || $vmf2Path === false) {
                throw new Exception("File upload failed.");
            }

            $ignoreOptions = isset($_POST['ignore']) ? explode(',', $_POST['ignore']) : [];
            $jobId = $this->jobManager->createJob($vmf1Path, $vmf2Path, $ignoreOptions);

            return $this->jsonResponse(['jobId' => $jobId]);
        } catch (Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    private function handleStatusAction() {
        try {
            if (!isset($_POST['jobId'])) {
                throw new Exception("No job ID provided.");
            }

            $jobId = filter_var($_POST['jobId'], FILTER_VALIDATE_INT);
            if ($jobId === false) {
                throw new Exception("Invalid job ID.");
            }

            $status = $this->jobManager->getJobStatus($jobId);
            if ($status === null) {
                throw new Exception("Job not found");
            }

            return $this->jsonResponse(['status' => $status]);
        } catch (Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    private function handleResultAction() {
        try {
            if (!isset($_POST['jobId'])) {
                throw new Exception("No job ID provided.");
            }

            $jobId = filter_var($_POST['jobId'], FILTER_VALIDATE_INT);
            if ($jobId === false) {
                throw new Exception("Invalid job ID.");
            }

            $result = $this->jobManager->getJobResult($jobId);
            if (!$result) {
                throw new Exception("No results found for this job ID.");
            }

            return $this->jsonResponse($result);
        } catch (Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        return json_encode($data);
    }
}