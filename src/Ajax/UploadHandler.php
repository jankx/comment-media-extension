<?php

namespace Jankx\Extensions\CommentMedia\Ajax;

use Jankx\Extensions\CommentMedia\CommentMediaExtension;

class UploadHandler
{
    const ALLOWED_IMAGE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    const ALLOWED_VIDEO_TYPES = [
        'video/mp4',
        'video/webm',
        'video/ogg',
    ];

    const ALLOWED_AUDIO_TYPES = [
        'audio/mpeg',
        'audio/wav',
        'audio/ogg',
        'audio/mp3',
    ];

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!is_user_logged_in()) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Vui lòng đăng nhập để upload file.', 'jankx'),
            ], 401);
        }

        if (empty($_FILES['file'])) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Không tìm thấy file.', 'jankx'),
            ], 400);
        }

        $file = $_FILES['file'];
        $result = $this->handleFile($file);

        if (is_wp_error($result)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    public function handleFile(array $file)
    {
        $debug = defined('WP_DEBUG') && WP_DEBUG;

        if ($debug) error_log('CommentMedia UploadHandler: handleFile name=' . $file['name'] . ' type=' . $file['type'] . ' size=' . $file['size'] . ' tmp=' . $file['tmp_name']);

        $error = $this->validateFile($file);
        if ($error !== null) {
            if ($debug) error_log('CommentMedia UploadHandler: validate failed: ' . $error->get_error_message());
            return $error;
        }

        if ($debug) error_log('CommentMedia UploadHandler: validation passed, calling uploadFile');

        $attachmentId = $this->uploadFile($file);

        if (is_wp_error($attachmentId)) {
            if ($debug) error_log('CommentMedia UploadHandler: uploadFile failed: ' . $attachmentId->get_error_message());
            return $attachmentId;
        }

        if ($debug) error_log('CommentMedia UploadHandler: uploadFile success, id=' . $attachmentId);

        $attachmentUrl = wp_get_attachment_url($attachmentId);
        $mimeType = get_post_mime_type($attachmentId);
        $type = explode('/', $mimeType)[0];

        return [
            'attachmentId' => $attachmentId,
            'url' => $attachmentUrl,
            'type' => $type,
            'mimeType' => $mimeType,
            'name' => sanitize_file_name($file['name']),
            'size' => $file['size'],
            'sizeFormatted' => size_format($file['size']),
        ];
    }

    protected function validateFile(array $file): ?\WP_Error
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new \WP_Error('upload_error', $this->getUploadErrorMessage($file['error']));
        }

        $extension = CommentMediaExtension::get_instance();
        if (!$extension) {
            return new \WP_Error('extension_unavailable', __('Extension chưa được khởi tạo.', 'jankx'));
        }
        $maxSize = $extension->getMaxSize() * 1024 * 1024;

        if ($file['size'] > $maxSize) {
            return new \WP_Error(
                'file_too_large',
                sprintf(
                    __('File quá lớn. Tối đa cho phép: %s', 'jankx'),
                    size_format($maxSize)
                )
            );
        }

        $mimeType = wp_check_filetype($file['name'])['type'] ?? '';
        $allowedTypes = $extension->getAllowedTypes();

        $allowedMimes = $this->getAllowedMimes($allowedTypes);

        if (!in_array($mimeType, $allowedMimes, true)) {
            return new \WP_Error(
                'invalid_type',
                sprintf(
                    __('Loại file "%s" không được phép.', 'jankx'),
                    $mimeType
                )
            );
        }

        return null;
    }

    /**
     * @return int|\WP_Error
     */
    protected function uploadFile(array $file)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $time = current_time('mysql');
        $uploads = wp_upload_dir($time);
        if (!$uploads || !empty($uploads['error'])) {
            return new \WP_Error('upload_dir_error', $uploads['error']);
        }

        $filename = wp_unique_filename($uploads['path'], $file['name']);

        $new_file = $uploads['path'] . '/' . $filename;

        if (false === @copy($file['tmp_name'], $new_file)) {
            return new \WP_Error('upload_failed', sprintf(
                __('Không thể copy file đến %s.', 'jankx'),
                $uploads['subdir']
            ));
        }

        @chmod($new_file, 0644);

        $url = $uploads['url'] . '/' . $filename;
        $type = $file['type'];

        $attachmentData = wp_read_image_metadata($new_file);

        $categoryId = $this->getOrCreateCommentCategory();

        $attachmentArgs = [
            'post_title' => sanitize_file_name($file['name']),
            'post_mime_type' => $type,
            'post_status' => 'inherit',
            'post_content' => '',
            'guid' => $url,
            'post_category' => [$categoryId],
        ];

        if (!empty($attachmentData)) {
            if (!empty($attachmentData['title'])) {
                $attachmentArgs['post_title'] = $attachmentData['title'];
            }
            if (!empty($attachmentData['description'])) {
                $attachmentArgs['post_content'] = $attachmentData['description'];
            }
        }

        $attachmentId = wp_insert_attachment($attachmentArgs, $new_file);

        if (is_wp_error($attachmentId)) {
            return $attachmentId;
        }

        $imageMetadata = wp_generate_attachment_metadata($attachmentId, $new_file);
        wp_update_attachment_metadata($attachmentId, $imageMetadata);

        // Gắn cờ để phân biệt comment media và phục vụ filter trong Media Library
        update_post_meta($attachmentId, '_is_comment_media', '1');
        update_post_meta($attachmentId, '_comment_media_uploaded_at', time());
        update_post_meta($attachmentId, '_comment_media_post_id', 0);
        update_post_meta($attachmentId, '_comment_media_comment_id', 0);
        update_post_meta($attachmentId, '_comment_media_orphan', '1'); // Chưa attach vào comment nào

        return $attachmentId;
    }

    protected function getOrCreateCommentCategory(): int
    {
        $categoryName = 'Comments';
        $categorySlug = 'comment-media';

        $existing = get_category_by_slug($categorySlug);

        if ($existing) {
            return $existing->term_id;
        }

        $categoryId = wp_insert_category([
            'cat_name' => $categoryName,
            'category_nicename' => $categorySlug,
            'category_description' => __('Media attachments from comments', 'jankx'),
        ]);

        return is_wp_error($categoryId) ? 0 : $categoryId;
    }

    protected function getAllowedMimes(array $types): array
    {
        $mimes = [];

        if (in_array('image', $types, true)) {
            $mimes = array_merge($mimes, self::ALLOWED_IMAGE_TYPES);
        }

        if (in_array('video', $types, true)) {
            $mimes = array_merge($mimes, self::ALLOWED_VIDEO_TYPES);
        }

        if (in_array('audio', $types, true)) {
            $mimes = array_merge($mimes, self::ALLOWED_AUDIO_TYPES);
        }

        return $mimes;
    }

    protected function getUploadErrorMessage(int $errorCode): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => __('File vượt quá giới hạn kích thước cho phép trên server.', 'jankx'),
            UPLOAD_ERR_FORM_SIZE => __('File vượt quá giới hạn kích thước biểu mẫu.', 'jankx'),
            UPLOAD_ERR_PARTIAL => __('File chỉ được upload một phần.', 'jankx'),
            UPLOAD_ERR_NO_FILE => __('Không có file nào được upload.', 'jankx'),
            UPLOAD_ERR_NO_TMP_DIR => __('Thư mục tạm thời bị thiếu.', 'jankx'),
            UPLOAD_ERR_CANT_WRITE => __('Không thể ghi file vào disk.', 'jankx'),
            UPLOAD_ERR_EXTENSION => __('Upload bị dừng bởi extension.', 'jankx'),
        ];

        return $errors[$errorCode] ?? __('Lỗi upload không xác định.', 'jankx');
    }
}
