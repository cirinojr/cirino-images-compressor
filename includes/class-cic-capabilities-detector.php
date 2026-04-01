<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICCapabilitiesDetector {
    /**
     * @var array<string,mixed>|null
     */
    private $cached = null;

    /**
     * @return array<string,mixed>
     */
    public function getCapabilities() {
        if (is_array($this->cached)) {
            return $this->cached;
        }

        $imagickLoaded = extension_loaded('imagick') && class_exists('Imagick');
        $gdLoaded = function_exists('gd_info');
        $gdInfo = $gdLoaded ? gd_info() : array();

        $capabilities = array(
            'binaries' => array(
                'pngquant' => $this->resolveBinary('pngquant'),
                'oxipng' => $this->resolveBinary('oxipng'),
                'cwebp' => $this->resolveBinary('cwebp'),
                'avifenc' => $this->resolveBinary('avifenc'),
            ),
            'imagick' => array(
                'available' => $imagickLoaded,
                'supports_webp' => $imagickLoaded && $this->imagickSupportsFormat('WEBP'),
                'supports_avif' => $imagickLoaded && $this->imagickSupportsFormat('AVIF'),
            ),
            'gd' => array(
                'available' => $gdLoaded,
                'supports_webp' => !empty($gdInfo['WebP Support']) || function_exists('imagewebp'),
                'supports_avif' => !empty($gdInfo['AVIF Support']) || function_exists('imageavif'),
            ),
            'wp' => array(
                'supports_webp' => wp_image_editor_supports(array('mime_type' => 'image/webp')),
                'supports_avif' => wp_image_editor_supports(array('mime_type' => 'image/avif')),
            ),
        );

        $this->cached = $capabilities;

        return $capabilities;
    }

    /**
     * @param string $binaryName
     *
     * @return string
     */
    public function resolveBinary($binaryName) {
        $binaryName = trim((string) $binaryName);
        if ('' === $binaryName) {
            return '';
        }

        $custom = apply_filters('cic_binary_paths', array());
        if (is_array($custom) && !empty($custom[$binaryName])) {
            $candidate = (string) $custom[$binaryName];
            if ('' !== $candidate && @is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        $extensions = array('');
        if ('\\' === DIRECTORY_SEPARATOR) {
            $extensions = array('.exe', '.bat', '.cmd', '');
        }

        foreach ($paths as $pathDir) {
            $pathDir = trim($pathDir);
            if ('' === $pathDir) {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate = rtrim($pathDir, '/\\') . DIRECTORY_SEPARATOR . $binaryName . $extension;
                if (@is_file($candidate) && @is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @param string $format
     *
     * @return bool
     */
    private function imagickSupportsFormat($format) {
        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            return false;
        }

        try {
            $formats = Imagick::queryFormats((string) $format);

            return !empty($formats);
        } catch (Exception $e) {
            return false;
        }
    }
}
