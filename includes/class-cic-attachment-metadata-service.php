<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICAttachmentMetadataService {
    private const MIME_WEBP = 'image/webp';

    /**
     * @var CICFileConversionService
     */
    private $fileConversionService;

    public function __construct(CICFileConversionService $fileConversionService) {
        $this->fileConversionService = $fileConversionService;
    }

    public function setAttachmentToWebp($attachmentId, $originalFilePath, $attachmentMetadata, &$failureReason) {
        $isSuccessful = false;
        $originalWebpPath = $this->fileConversionService->replacePathExtensionToWebp($originalFilePath);

        $hasValidWebpOriginal = '' !== $originalWebpPath
            && file_exists($originalWebpPath)
            && $this->fileConversionService->isPathInUploads($originalWebpPath);

        if (!$hasValidWebpOriginal) {
            $failureReason = 'missing_webp_original';
        } else {
            $metadata = is_array($attachmentMetadata) ? $attachmentMetadata : array();
            if ($this->updateAttachmentMetadataAsWebp($attachmentId, $metadata, $failureReason)) {
                $attachedFileUpdated = update_attached_file($attachmentId, $originalWebpPath);
                if (false === $attachedFileUpdated) {
                    $failureReason = 'attached_file_update_error';
                } else {
                    $isSuccessful = $this->updateAttachmentMimeTypePost($attachmentId, $failureReason);
                }
            }
        }

        return $isSuccessful;
    }

    private function updateAttachmentMetadataAsWebp($attachmentId, $metadata, &$failureReason) {
        $isSuccessful = true;

        if (!empty($metadata)) {
            if (isset($metadata['file']) && is_string($metadata['file'])) {
                $metadata['file'] = $this->fileConversionService->replacePathExtensionToWebp($metadata['file']);
            }

            $metadata['sizes'] = $this->normalizeMetadataSizesToWebp(
                isset($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : array()
            );

            $metadata['mime_type'] = self::MIME_WEBP;

            $metadataUpdated = wp_update_attachment_metadata($attachmentId, $metadata);
            if (false === $metadataUpdated) {
                $failureReason = 'metadata_update_error';
                $isSuccessful = false;
            }
        }

        return $isSuccessful;
    }

    private function normalizeMetadataSizesToWebp($sizes) {
        foreach ($sizes as $sizeName => $sizeData) {
            if (!is_array($sizeData) || empty($sizeData['file'])) {
                continue;
            }

            $sizes[$sizeName]['file'] = $this->fileConversionService->replacePathExtensionToWebp(wp_basename((string) $sizeData['file']));
            $sizes[$sizeName]['mime-type'] = self::MIME_WEBP;
        }

        return $sizes;
    }

    private function updateAttachmentMimeTypePost($attachmentId, &$failureReason) {
        $isSuccessful = true;
        $updatedPost = wp_update_post(
            array(
                'ID' => (int) $attachmentId,
                'post_mime_type' => self::MIME_WEBP,
            ),
            true
        );

        if (is_wp_error($updatedPost)) {
            $failureReason = 'attachment_post_update_error:' . $updatedPost->get_error_code();
            $isSuccessful = false;
        }

        return $isSuccessful;
    }
}
