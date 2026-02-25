<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-cic-lifecycle.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cic-plugin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cic-converter.php';

CICLifecycle::uninstall();
