<?php

if (!defined('ABSPATH')) {
    exit;
}

interface CICOptimizerInterface {
    /**
     * @param string $mimeType
     *
     * @return bool
     */
    public function supports($mimeType);

    /**
     * @param string $sourcePath
     * @param string $destPath
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function optimize($sourcePath, $destPath, $options);

    /**
     * @return string
     */
    public function getName();
}
