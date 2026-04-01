<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICGdOptimizerProvider implements CICOptimizerInterface {
    /**
     * @var CICCapabilitiesDetector
     */
    private $capabilities;

    public function __construct(CICCapabilitiesDetector $capabilities) {
        $this->capabilities = $capabilities;
    }

    public function getName() {
        return 'gd';
    }

    public function supports($mimeType) {
        $caps = $this->capabilities->getCapabilities();
        if (empty($caps['gd']['available'])) {
            return false;
        }

        $mime = strtolower((string) $mimeType);

        return in_array($mime, array('image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/avif'), true);
    }

    public function optimize($sourcePath, $destPath, $options) {
        $sourceMime = isset($options['source_mime']) ? strtolower((string) $options['source_mime']) : '';
        $targetMime = isset($options['target_mime']) ? strtolower((string) $options['target_mime']) : $sourceMime;

        $source = $this->createImageResource($sourcePath, $sourceMime);
        if (!$this->isImageResource($source)) {
            return array(
                'success' => false,
                'engine' => $this->getName(),
                'reason' => 'gd_source_open_error',
            );
        }

        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagepalettetotruecolor($source);

        $ok = false;

        if (in_array($targetMime, array('image/jpeg', 'image/jpg'), true) && function_exists('imagejpeg')) {
            $quality = isset($options['jpeg_quality']) ? (int) $options['jpeg_quality'] : 80;
            imageinterlace($source, !empty($options['jpeg_progressive']));
            $ok = imagejpeg($source, $destPath, max(1, min(100, $quality)));
        } elseif ('image/png' === $targetMime && function_exists('imagepng')) {
            $level = isset($options['level']) ? (string) $options['level'] : 'balanced';
            $colors = $this->getPngColorsByLevel($level);
            if ($colors > 0) {
                imagetruecolortopalette($source, true, $colors);
                imagesavealpha($source, true);
            }
            $ok = imagepng($source, $destPath, 9);
        } elseif ('image/webp' === $targetMime && function_exists('imagewebp')) {
            $quality = isset($options['webp_quality']) ? (int) $options['webp_quality'] : 80;
            $ok = imagewebp($source, $destPath, max(1, min(100, $quality)));
        } elseif ('image/avif' === $targetMime && function_exists('imageavif')) {
            $quality = isset($options['avif_quality']) ? (int) $options['avif_quality'] : 50;
            $ok = imageavif($source, $destPath, max(1, min(100, $quality)));
        }

        imagedestroy($source);

        return array(
            'success' => (bool) $ok,
            'engine' => $this->getName(),
            'reason' => $ok ? '' : 'gd_save_error',
        );
    }

    /**
     * @param string $path
     * @param string $mime
     *
     * @return resource|GdImage|null
     */
    private function createImageResource($path, $mime) {
        if ('image/jpeg' === $mime || 'image/jpg' === $mime) {
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null;
        }

        if ('image/png' === $mime) {
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null;
        }

        if ('image/webp' === $mime) {
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        }

        if ('image/avif' === $mime) {
            return function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : null;
        }

        return null;
    }

    /**
     * @param mixed $resource
     *
     * @return bool
     */
    private function isImageResource($resource) {
        if (is_object($resource) && class_exists('GdImage') && $resource instanceof GdImage) {
            return true;
        }

        return is_resource($resource);
    }

    /**
     * @param string $level
     *
     * @return int
     */
    private function getPngColorsByLevel($level) {
        if ('lossless' === $level) {
            return 0;
        }

        if ('balanced' === $level) {
            return 256;
        }

        if ('aggressive' === $level) {
            return 192;
        }

        return 128;
    }
}
