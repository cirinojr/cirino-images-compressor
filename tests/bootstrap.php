<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['cic_test_uploads_basedir'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cic-tests-uploads';
if (!is_dir($GLOBALS['cic_test_uploads_basedir'])) {
    mkdir($GLOBALS['cic_test_uploads_basedir'], 0777, true);
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        return $value;
    }
}

if (!function_exists('wp_image_editor_supports')) {
    function wp_image_editor_supports($args) {
        return false;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return array('basedir' => $GLOBALS['cic_test_uploads_basedir']);
    }
}

if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path($path) {
        return str_replace('\\', '/', (string) $path);
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim((string) $string, '/\\') . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('wp_basename')) {
    function wp_basename($path) {
        return basename((string) $path);
    }
}

if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata($attachmentId) {
        return array('sizes' => array());
    }
}

if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        return $default;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value) {
        return json_encode($value);
    }
}

require_once dirname(__DIR__) . '/includes/class-cic-optimizer-interface.php';
require_once dirname(__DIR__) . '/includes/class-cic-capabilities-detector.php';
require_once dirname(__DIR__) . '/includes/class-cic-debug-logger.php';
require_once dirname(__DIR__) . '/includes/class-cic-binary-optimizer-provider.php';
require_once dirname(__DIR__) . '/includes/class-cic-imagick-optimizer-provider.php';
require_once dirname(__DIR__) . '/includes/class-cic-gd-optimizer-provider.php';
require_once dirname(__DIR__) . '/includes/class-cic-file-conversion-service.php';
