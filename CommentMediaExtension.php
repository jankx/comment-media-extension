<?php

namespace Jankx\Extensions\CommentMedia;

use Jankx\Extensions\AbstractExtension;
use Jankx\Extensions\CommentMedia\Admin\Settings;

class CommentMediaExtension extends AbstractExtension
{
    protected static $instance;

    const COMMENT_META_KEY = 'jankx_media_attachment_ids';

    public function __construct()
    {
        $this->register_autoloader();
        parent::__construct();
    }

    protected function register_autoloader()
    {
        spl_autoload_register(function ($class) {
            $prefix = 'Jankx\\Extensions\\CommentMedia\\';
            $base_dir = __DIR__ . '/src/';
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }
            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
            if (file_exists($file)) {
                require $file;
            }
        });
    }

    public function init(): void
    {
        self::$instance = $this;
    }

    public static function get_instance(): ?self
    {
        return self::$instance;
    }

    public function register_hooks(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);

        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        add_filter('comment_form_default_fields', [$this, 'addMediaUploadZone']);

        add_action('comment_post', [$this, 'saveMedia'], 10, 3);

        add_filter('comment_text', [$this, 'displayMedia'], 10, 2);
    }

    public function enqueueAssets(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        wp_enqueue_style(
            'comment-media',
            $this->get_extension_url() . '/assets/comment-media.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'comment-media',
            $this->get_extension_url() . '/assets/comment-media.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('comment-media', 'commentMedia', [
            'restUrl' => rest_url('comment-media/v1/upload'),
            'nonce' => wp_create_nonce('wp_rest'),
            'maxFiles' => $this->getMaxFiles(),
            'maxSize' => $this->getMaxSize(),
            'allowedTypes' => $this->getAllowedTypes(),
            'i18n' => [
                'dropHere' => __('Thả file vào đây', 'jankx'),
                'clickToUpload' => __('Click để chọn file', 'jankx'),
                'orDragDrop' => __('hoặc kéo thả', 'jankx'),
                'uploading' => __('Đang upload...', 'jankx'),
                'uploadSuccess' => __('Upload thành công', 'jankx'),
                'uploadError' => __('Upload thất bại', 'jankx'),
                'removeFile' => __('Xóa file', 'jankx'),
                'maxFilesExceeded' => __('Đã đạt giới hạn số file tối đa', 'jankx'),
                'invalidType' => __('Loại file không được phép', 'jankx'),
                'fileTooLarge' => __('File quá lớn', 'jankx'),
                'images' => __('Ảnh', 'jankx'),
                'videos' => __('Video', 'jankx'),
                'audio' => __('Âm thanh', 'jankx'),
            ],
        ]);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('comment-media/v1', '/upload', [
            'methods' => 'POST',
            'callback' => [$this, 'handleUpload'],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
            'args' => [
                'file' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return !empty($_FILES['file']);
                    },
                ],
            ],
        ]);
    }

    public function handleUpload(\WP_REST_Request $request): \WP_REST_Response
    {
        $handler = new \Jankx\Extensions\CommentMedia\Ajax\UploadHandler();
        return $handler->handle($request);
    }

    public function addMediaUploadZone(array $fields): array
    {
        if (!$this->isEnabled()) {
            return $fields;
        }

        ob_start();
        ?>
        <div class="comment-media-upload-wrapper">
            <div class="comment-media-upload-zone" id="comment-media-upload-zone">
                <div class="comment-media-upload-content">
                    <svg class="comment-media-upload-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                    <p class="comment-media-upload-text">
                        <?php echo esc_html__('Kéo thả ảnh, video hoặc âm thanh vào đây', 'jankx'); ?>
                    </p>
                    <p class="comment-media-upload-hint">
                        <?php echo esc_html__('hoặc', 'jankx'); ?>
                        <label class="comment-media-upload-btn" for="comment-media-file-input">
                            <?php echo esc_html__('chọn file', 'jankx'); ?>
                        </label>
                    </p>
                    <p class="comment-media-upload-limits">
                        <?php
                        $types = $this->getAllowedTypesLabels();
                        echo esc_html(sprintf(
                            __('Tối đa %1$d file, mỗi file %2$s. Hỗ trợ: %3$s', 'jankx'),
                            $this->getMaxFiles(),
                            size_format($this->getMaxSize() * 1024 * 1024),
                            implode(', ', $types)
                        ));
                        ?>
                    </p>
                </div>
                <input type="file"
                       id="comment-media-file-input"
                       class="comment-media-file-input"
                       multiple
                       accept="image/*,video/*,audio/*">
                <div class="comment-media-preview-grid" id="comment-media-preview-grid"></div>
            </div>
        </div>
        <?php
        $mediaField = ob_get_clean();

        $commentField = $fields['comment'];
        $fields['comment'] = $mediaField . $commentField;

        return $fields;
    }

    public function saveMedia(int $commentId, int $approved, array $commentData): void
    {
        if (empty($_POST['comment_media_ids'])) {
            return;
        }

        $mediaIds = array_map('intval', (array) $_POST['comment_media_ids']);
        $mediaIds = array_filter($mediaIds);

        if (empty($mediaIds)) {
            return;
        }

        $maxFiles = $this->getMaxFiles();
        $mediaIds = array_slice($mediaIds, 0, $maxFiles);

        update_comment_meta($commentId, self::COMMENT_META_KEY, $mediaIds);
    }

    public function displayMedia(string $commentText, int $commentId = 0): string
    {
        if (!$commentId) {
            return $commentText;
        }

        $mediaIds = get_comment_meta($commentId, self::COMMENT_META_KEY, true);

        if (empty($mediaIds) || !is_array($mediaIds)) {
            return $commentText;
        }

        $mediaHtml = $this->renderMediaGrid($mediaIds);

        return $commentText . $mediaHtml;
    }

    protected function renderMediaGrid(array $mediaIds): string
    {
        $validIds = array_filter($mediaIds, function ($id) {
            return wp_attachment_is_image($id) || get_post_mime_type($id) !== false;
        });

        if (empty($validIds)) {
            return '';
        }

        ob_start();
        ?>
        <div class="comment-media-grid">
            <?php foreach ($validIds as $mediaId) :
                $url = wp_get_attachment_url($mediaId);
                $mimeType = get_post_mime_type($mediaId);
                $name = get_the_title($mediaId);
                $type = explode('/', $mimeType)[0];
            ?>
                <div class="comment-media-item comment-media-item--<?php echo esc_attr($type); ?>">
                    <?php if ($type === 'image') : ?>
                        <a href="<?php echo esc_url($url); ?>"
                           class="comment-media-link"
                           data-lightbox="comment-media-<?php echo esc_attr($mediaId); ?>">
                            <?php echo wp_get_attachment_image($mediaId, 'medium', false, [
                                'class' => 'comment-media-image',
                                'alt' => esc_attr($name),
                                'loading' => 'lazy',
                            ]); ?>
                        </a>
                    <?php elseif ($type === 'video') : ?>
                        <video controls class="comment-media-video" preload="metadata">
                            <source src="<?php echo esc_url($url); ?>" type="<?php echo esc_attr($mimeType); ?>">
                            <?php echo esc_html__('Trình duyệt không hỗ trợ video.', 'jankx'); ?>
                        </video>
                    <?php elseif ($type === 'audio') : ?>
                        <div class="comment-media-audio">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 18V5l12-2v13"></path>
                                <circle cx="6" cy="18" r="3"></circle>
                                <circle cx="18" cy="16" r="3"></circle>
                            </svg>
                            <audio controls class="comment-media-audio-player" preload="metadata">
                                <source src="<?php echo esc_url($url); ?>" type="<?php echo esc_attr($mimeType); ?>">
                                <?php echo esc_html__('Trình duyệt không hỗ trợ âm thanh.', 'jankx'); ?>
                            </audio>
                            <span class="comment-media-audio-name"><?php echo esc_html($name); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function isEnabled(): bool
    {
        return (bool) Settings::getOption(Settings::FIELD_ENABLED, true);
    }

    public function getMaxFiles(): int
    {
        return (int) Settings::getOption(Settings::FIELD_MAX_FILES, 3);
    }

    public function getMaxSize(): int
    {
        return (int) Settings::getOption(Settings::FIELD_MAX_SIZE, 5);
    }

    public function getAllowedTypes(): array
    {
        return Settings::getOption(Settings::FIELD_ALLOWED_TYPES, ['image', 'video', 'audio']);
    }

    public function getAllowedTypesLabels(): array
    {
        $types = $this->getAllowedTypes();
        $labels = [];

        $map = [
            'image' => __('Ảnh', 'jankx'),
            'video' => __('Video', 'jankx'),
            'audio' => __('Âm thanh', 'jankx'),
        ];

        foreach ($types as $type) {
            if (isset($map[$type])) {
                $labels[] = $map[$type];
            }
        }

        return $labels;
    }
}
