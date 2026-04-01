<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICBinaryOptimizerProvider implements CICOptimizerInterface {
    /**
     * @var CICCapabilitiesDetector
     */
    private $capabilities;

    /**
     * @var CICDebugLogger
     */
    private $logger;

    public function __construct(CICCapabilitiesDetector $capabilities, CICDebugLogger $logger) {
        $this->capabilities = $capabilities;
        $this->logger = $logger;
    }

    public function getName() {
        return 'binary';
    }

    public function supports($mimeType) {
        $mime = strtolower((string) $mimeType);

        return in_array($mime, array('image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/avif'), true);
    }

    public function optimize($sourcePath, $destPath, $options) {
        $sourcePath = (string) $sourcePath;
        $destPath = (string) $destPath;
        $sourceMime = isset($options['source_mime']) ? strtolower((string) $options['source_mime']) : '';
        $targetMime = isset($options['target_mime']) ? strtolower((string) $options['target_mime']) : $sourceMime;

        $copyOk = @copy($sourcePath, $destPath);
        if (!$copyOk) {
            return array(
                'success' => false,
                'engine' => $this->getName(),
                'reason' => 'copy_failed',
            );
        }

        if ('image/png' === $sourceMime && 'image/png' === $targetMime) {
            return $this->optimizePng($destPath, $options);
        }

        if ('image/webp' === $targetMime) {
            return $this->convertWithCwebp($sourcePath, $destPath, $options);
        }

        if ('image/avif' === $targetMime) {
            return $this->convertWithAvifenc($sourcePath, $destPath, $options);
        }

        return array(
            'success' => false,
            'engine' => $this->getName(),
            'reason' => 'unsupported_operation',
        );
    }

    /**
     * @param string $pngPath
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    private function optimizePng($pngPath, $options) {
        $caps = $this->capabilities->getCapabilities();
        $pngquantPath = isset($caps['binaries']['pngquant']) ? (string) $caps['binaries']['pngquant'] : '';
        $oxipngPath = isset($caps['binaries']['oxipng']) ? (string) $caps['binaries']['oxipng'] : '';

        $min = isset($options['pngquant_min_quality']) ? (int) $options['pngquant_min_quality'] : 65;
        $max = isset($options['pngquant_max_quality']) ? (int) $options['pngquant_max_quality'] : 85;

        $ran = false;

        if ('' !== $pngquantPath) {
            $command = escapeshellarg($pngquantPath)
                . ' --skip-if-larger --quality=' . (int) $min . '-' . (int) $max
                . ' --strip --force --output ' . escapeshellarg($pngPath)
                . ' -- ' . escapeshellarg($pngPath);

            $result = $this->runCommand($command);
            if ($result['ok']) {
                $ran = true;
            }
        }

        if ('' !== $oxipngPath) {
            $command = escapeshellarg($oxipngPath)
                . ' -o 4 --strip safe --alpha --preserve'
                . ' -- ' . escapeshellarg($pngPath);

            $result = $this->runCommand($command);
            if ($result['ok']) {
                $ran = true;
            }
        }

        if (!$ran) {
            return array(
                'success' => false,
                'engine' => $this->getName(),
                'reason' => 'png_binaries_unavailable',
            );
        }

        return array(
            'success' => true,
            'engine' => $this->getName() . ':pngquant/oxipng',
        );
    }

    /**
     * @param string $sourcePath
     * @param string $destPath
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    private function convertWithCwebp($sourcePath, $destPath, $options) {
        $caps = $this->capabilities->getCapabilities();
        $binaryPath = isset($caps['binaries']['cwebp']) ? (string) $caps['binaries']['cwebp'] : '';

        if ('' === $binaryPath) {
            return array(
                'success' => false,
                'engine' => $this->getName(),
                'reason' => 'cwebp_unavailable',
            );
        }

        $quality = isset($options['webp_quality']) ? (int) $options['webp_quality'] : 80;
        $effort = isset($options['compression_effort']) ? (int) $options['compression_effort'] : 6;
        $command = escapeshellarg($binaryPath)
            . ' -q ' . $quality
            . ' -m ' . max(0, min(6, $effort))
            . ' -mt'
            . ' ' . escapeshellarg($sourcePath)
            . ' -o ' . escapeshellarg($destPath);

        $result = $this->runCommand($command);
        if (!$result['ok']) {
            return array(
                'success' => false,
                'engine' => $this->getName(),
                'reason' => 'cwebp_failed',
            );
        }

        return array(
            'success' => true,
            'engine' => $this->getName() . ':cwebp',
        );
    }

    /**
     * @param string $sourcePath
     * @param string $destPath
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    private function convertWithAvifenc($sourcePath, $destPath, $options) {
        $caps = $this->capabilities->getCapabilities();
        $binaryPath = isset($caps['binaries']['avifenc']) ? (string) $caps['binaries']['avifenc'] : '';

        if ('' === $binaryPath) {
            return array(
                'success' => false,
                'engine' => $this->getName(),
                'reason' => 'avifenc_unavailable',
            );
        }

        $quality = isset($options['avif_quality']) ? (int) $options['avif_quality'] : 50;
        $speed = isset($options['compression_effort']) ? max(0, 10 - (int) $options['compression_effort']) : 4;

        $command = escapeshellarg($binaryPath)
            . ' --min ' . max(0, min(63, (int) floor($quality / 2)))
            . ' --max ' . max(0, min(63, (int) floor($quality / 2)))
            . ' --speed ' . max(0, min(10, $speed))
            . ' ' . escapeshellarg($sourcePath)
            . ' ' . escapeshellarg($destPath);

        $result = $this->runCommand($command);
        if (!$result['ok']) {
            return array(
                'success' => false,
                'engine' => $this->getName(),
                'reason' => 'avifenc_failed',
            );
        }

        return array(
            'success' => true,
            'engine' => $this->getName() . ':avifenc',
        );
    }

    /**
     * @param string $command
     *
     * @return array<string,mixed>
     */
    private function runCommand($command) {
        if (!function_exists('exec')) {
            return array('ok' => false, 'output' => array(), 'code' => 127);
        }

        $output = array();
        $code = 1;
        @exec($command . ' 2>&1', $output, $code);

        $result = array(
            'ok' => 0 === (int) $code,
            'output' => $output,
            'code' => (int) $code,
        );

        if (!$result['ok']) {
            $this->logger->log('binary_command_failed', array('command' => $command, 'code' => $code, 'output' => $output));
        }

        return $result;
    }
}
