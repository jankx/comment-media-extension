<?php

namespace Jankx\Extensions\CommentMedia\Helpers;

class MediaHelper
{
    const ALLOWED_MIME_TYPES = [
        'image' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ],
        'video' => [
            'video/mp4',
            'video/webm',
            'video/ogg',
        ],
        'audio' => [
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'audio/mp3',
        ],
    ];

    const FILE_EXTENSIONS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'video' => ['mp4', 'webm', 'ogg'],
        'audio' => ['mp3', 'wav', 'ogg'],
    ];

    public static function getAllowedMimeTypes(array $types = null): array
    {
        if ($types === null) {
            $types = ['image', 'video', 'audio'];
        }

        $mimes = [];
        foreach ($types as $type) {
            if (isset(self::ALLOWED_MIME_TYPES[$type])) {
                $mimes = array_merge($mimes, self::ALLOWED_MIME_TYPES[$type]);
            }
        }

        return $mimes;
    }

    public static function getAllowedExtensions(array $types = null): array
    {
        if ($types === null) {
            $types = ['image', 'video', 'audio'];
        }

        $extensions = [];
        foreach ($types as $type) {
            if (isset(self::FILE_EXTENSIONS[$type])) {
                $extensions = array_merge($extensions, self::FILE_EXTENSIONS[$type]);
            }
        }

        return $extensions;
    }

    public static function getFileType(string $mimeType): string
    {
        foreach (self::ALLOWED_MIME_TYPES as $type => $mimes) {
            if (in_array($mimeType, $mimes, true)) {
                return $type;
            }
        }

        return 'unknown';
    }

    public static function formatFileSize(int $bytes): string
    {
        return size_format($bytes);
    }

    public static function getImageThumbnail(int $attachmentId, string $size = 'thumbnail'): string
    {
        $thumbnail = wp_get_attachment_image_url($attachmentId, $size);

        if (!$thumbnail) {
            return '';
        }

        return $thumbnail;
    }

    public static function isImage(int $attachmentId): bool
    {
        $mimeType = get_post_mime_type($attachmentId);

        return strpos($mimeType, 'image/') === 0;
    }

    public static function isVideo(int $attachmentId): bool
    {
        $mimeType = get_post_mime_type($attachmentId);

        return strpos($mimeType, 'video/') === 0;
    }

    public static function isAudio(int $attachmentId): bool
    {
        $mimeType = get_post_mime_type($attachmentId);

        return strpos($mimeType, 'audio/') === 0;
    }

    public static function getCommentMediaIds(int $commentId): array
    {
        $mediaIds = get_comment_meta($commentId, 'jankx_media_attachment_ids', true);

        if (empty($mediaIds) || !is_array($mediaIds)) {
            return [];
        }

        return array_filter(array_map('intval', $mediaIds));
    }

    public static function deleteCommentMedia(int $commentId): bool
    {
        $mediaIds = self::getCommentMediaIds($commentId);

        if (empty($mediaIds)) {
            return true;
        }

        $deleted = true;
        foreach ($mediaIds as $mediaId) {
            $result = wp_delete_attachment($mediaId, true);
            if (!$result) {
                $deleted = false;
            }
        }

        return $deleted;
    }
}
