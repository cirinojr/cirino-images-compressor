<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICDebugLogger {
    /**
     * @var string
     */
    private $enabledOption;

    public function __construct($enabledOption = 'cic_debug_mode') {
        $this->enabledOption = (string) $enabledOption;
    }

    /**
     * @param string $message
     * @param array<string,mixed> $context
     *
     * @return void
     */
    public function log($message, $context = array()) {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = array(
            'message' => (string) $message,
            'context' => $context,
        );

        error_log('[CIC] ' . wp_json_encode($payload));
    }

    /**
     * @return bool
     */
    public function isEnabled() {
        return !empty(get_option($this->enabledOption, 0));
    }
}
