<?php
ob_start();

$_GET['action'] = 'login';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Prepare POST body
$post_body = json_encode([
    'email' => 'admin@labcontrol.local',
    'password' => 'admin123'
]);

// Set up a wrapper to intercept file_get_contents for php://input
$orig_get_contents = file_get_contents(...);
stream_context_set_default([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-type: application/json',
        'content' => $post_body
    ]
]);

// Create a temporary stream wrapper
if (!stream_wrapper_exists('php_input')) {
    stream_wrapper_register('php_input', 'class {
        public $position = 0;
        public $data;
        public function stream_open($path, $mode, $options, &$opened_path) {
            global $post_body;
            $this->data = $post_body;
            return true;
        }
        public function stream_read($count) {
            $data = substr($this->data, $this->position, $count);
            $this->position += strlen($data);
            return $data;
        }
        public function stream_tell() { return $this->position; }
        public function stream_eof() { return $this->position >= strlen($this->data); }
    }');
}

include '../../labcontrol-backend/api/auth.php';
ob_end_clean();
?>

