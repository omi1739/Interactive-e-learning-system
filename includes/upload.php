<?php
class FileUpload {
    private $allowedTypes = [];
    private $maxSize;
    private $uploadPath;

    public function __construct($uploadPath = '../uploads/') {
        $this->uploadPath = $uploadPath;
        $this->maxSize = 10 * 1024 * 1024; // 10MB default
    }

    public function setAllowedTypes($types) {
        $this->allowedTypes = $types;
    }

    public function setMaxSize($sizeInMB) {
        $this->maxSize = $sizeInMB * 1024 * 1024;
    }

    public function upload($file, $subdirectory = '') {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload error: ' . $file['error']);
        }

        // Check file size
        if ($file['size'] > $this->maxSize) {
            throw new Exception('File too large. Maximum size: ' . ($this->maxSize / 1024 / 1024) . 'MB');
        }

        // Check file type
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!empty($this->allowedTypes) && !in_array($fileExtension, $this->allowedTypes)) {
            throw new Exception('File type not allowed. Allowed types: ' . implode(', ', $this->allowedTypes));
        }

        // Create directory if it doesn't exist
        $fullPath = $this->uploadPath . $subdirectory;
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        // Generate safe filename
        $safeFilename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $file['name']);
        $destination = $fullPath . $safeFilename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            return [
                'file_path' => $destination,
                'file_name' => $file['name'],
                'safe_filename' => $safeFilename
            ];
        } else {
            throw new Exception('Failed to move uploaded file.');
        }
    }
}