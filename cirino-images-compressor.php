<?php
/**
 * Plugin Name: Cirino Images Compressor
 * Plugin URI: https://github.com/cirinojr/cirino-images-compressor
 * Description: Convert and optimize WordPress media library images to WebP with batch processing and progress tracking.
 * Version: 0.1.1
 * Author: Cirino
 * Author URI: https://github.com/cirinojr
 * Text Domain: cirino-images-compressor
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CIC_VERSION', '0.1.1');
define('CIC_PLUGIN_FILE', __FILE__);
define('CIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CIC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CIC_PLUGIN_DIR . 'includes/class-cic-plugin.php';
require_once CIC_PLUGIN_DIR . 'includes/class-cic-lifecycle.php';
require_once CIC_PLUGIN_DIR . 'includes/class-cic-file-conversion-service.php';
require_once CIC_PLUGIN_DIR . 'includes/class-cic-image-stats-service.php';
require_once CIC_PLUGIN_DIR . 'includes/class-cic-attachment-metadata-service.php';
require_once CIC_PLUGIN_DIR . 'includes/class-cic-converter.php';
require_once CIC_PLUGIN_DIR . 'includes/class-cic-admin-page.php';

register_activation_hook(__FILE__, array('CICLifecycle', 'activate'));
register_deactivation_hook(__FILE__, array('CICLifecycle', 'deactivate'));

CICPlugin::getInstance()->run();
