<?php

namespace Jankx\Extensions\CommentMedia;

use Jankx\Extensions\AbstractExtension;
use Jankx\Extensions\CommentMedia\Admin\Settings;
use Jankx\Extensions\CommentMedia\Admin\MediaLibraryFilter;
use Jankx\Extensions\CommentMedia\Admin\CleanupScheduler;
use Jankx\Dashboard\Elements\Page;
use Jankx\Dashboard\Elements\Section;
use Jankx\Dashboard\Factories\FieldFactory;

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

        add_action('wp_ajax_comment_media_upload', [$this, 'handleAjaxUpload']);
        add_action('wp_ajax_nopriv_comment_media_upload', [$this, 'handleAjaxUpload']);

        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        add_action('comment_post', [$this, 'saveMedia'], 10, 3);

        $this->registerUploadZoneHook();

        add_filter('comment_text', [$this, 'displayMedia'], 10, 2);

        // Đăng ký các tính năng admin qua hook để đảm bảo is_admin() đúng
        add_action('admin_init', [$this, 'registerSettingsPage']);
        add_action('admin_init', [$this, 'bootAdminFeatures']);
    }

    /**
     * Khởi động các tính năng admin sau khi WordPress context sẵn sàng.
     * Dùng hook admin_init thay vì is_admin() trực tiếp để tránh timing issue.
     */
    public function bootAdminFeatures(): void
    {
        // Tab "Ảnh bình luận" trong Media Library
        (new MediaLibraryFilter())->register();

        // Lên lịch xoá orphaned comment media
        (new CleanupScheduler())->register();
    }

    public function registerSettingsPage(): void
    {
        try {
            $app = \Jankx\Facades\App::getInstance();
            if (!$app || !$app->bound('theme-options')) {
                return;
            }

            $themeOptions = $app->make('theme-options');
            if (!$themeOptions) {
                return;
            }

            $adapter = $themeOptions->getAdapter();
            if (!$adapter || !method_exists($adapter, 'getFramework')) {
                return;
            }

            $framework = $adapter->getFramework();
            if (!$framework) {
                return;
            }

            $page = new Page(__('Media trong bình luận', 'jankx'));
            $page->setId('comment_media');
            $page->setIcon('dashicons-before dashicons-format-gallery');
            $page->setDescription(__('Cấu hình upload ảnh, video, audio trong bình luận.', 'jankx'));
            $page->setPriority(90);

            $section = new Section(__('Cài đặt Media', 'jankx'));
            $section->setId('comment_media_settings');
            $section->setDescription(__('Tùy chọn upload media trong bình luận.', 'jankx'));

            $fields = [
                [
                    'id' => 'cm_enabled',
                    'name' => __('Bật tính năng', 'jankx'),
                    'type' => 'switch',
                    'default' => true,
                    'on' => __('Bật', 'jankx'),
                    'off' => __('Tắt', 'jankx'),
                    'description' => __('Cho phép người dùng upload media khi bình luận.', 'jankx'),
                ],
                [
                    'id' => 'cm_max_files',
                    'name' => __('Số file tối đa', 'jankx'),
                    'type' => 'slider',
                    'min' => 1,
                    'max' => 10,
                    'step' => 1,
                    'default' => 3,
                    'display_value' => true,
                    'description' => __('Số lượng file tối đa mỗi bình luận.', 'jankx'),
                ],
                [
                    'id' => 'cm_max_size',
                    'name' => __('Dung lượng tối đa (MB)', 'jankx'),
                    'type' => 'slider',
                    'min' => 1,
                    'max' => 50,
                    'step' => 1,
                    'default' => 5,
                    'display_value' => true,
                    'description' => __('Dung lượng tối đa mỗi file.', 'jankx'),
                ],
                [
                    'id' => 'cm_allowed_types',
                    'name' => __('Loại file cho phép', 'jankx'),
                    'type' => 'checkbox',
                    'options' => [
                        'image' => __('Ảnh (JPG, PNG, GIF, WebP)', 'jankx'),
                        'video' => __('Video (MP4, WebM, OGG)', 'jankx'),
                        'audio' => __('Âm thanh (MP3, WAV, OGG)', 'jankx'),
                    ],
                    'default' => ['image', 'video', 'audio'],
                    'description' => __('Chọn các loại file được phép upload.', 'jankx'),
                ],
                [
                    'id' => 'cm_upload_zone_position',
                    'name' => __('Vị trí hộp kéo thả', 'jankx'),
                    'type' => 'select',
                    'options' => $this->getUploadZonePositions(),
                    'default' => 'comment_form_submit_field',
                    'description' => __('Chọn hook mà hộp kéo thả media sẽ được đăng ký trong biểu mẫu bình luận.', 'jankx'),
                ],
                [
                    'id' => 'cm_post_types',
                    'name' => __('Áp dụng cho post types', 'jankx'),
                    'type' => 'checkbox',
                    'options' => $this->getPostTypeChoices(),
                    'default' => [],
                    'description' => __('Chọn các post types được phép upload media khi bình luận. Bỏ trống = áp dụng cho mọi post type.', 'jankx'),
                ],
                [
                    'id' => 'cm_orphan_days',
                    'name' => __('Xoá ảnh chưa attach sau (ngày)', 'jankx'),
                    'type' => 'slider',
                    'min' => 1,
                    'max' => 30,
                    'step' => 1,
                    'default' => 7,
                    'display_value' => true,
                    'description' => __('Ảnh upload từ comment nhưng không được gắn vào bình luận nào sẽ tự động bị xoá sau số ngày này.', 'jankx'),
                ],
            ];

            foreach ($fields as $fieldData) {
                $field = FieldFactory::create(
                    $fieldData['id'],
                    $fieldData['name'],
                    $fieldData['type'],
                    $fieldData
                );
                if ($field) {
                    $section->addField($field);
                }
            }

            $page->addSection($section);
            $framework->addPage($page);

            error_log('Comment Media: Page registered. Sections: ' . count($page->getSections()));
            foreach ($page->getSections() as $sec) {
                error_log('  Section: ' . $sec->getTitle() . ' fields: ' . count($sec->getFields()));
            }
        } catch (\Exception $e) {
            error_log('Comment Media: Error registering settings page - ' . $e->getMessage());
        }
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
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => 'comment_media_upload',
            'nonce' => wp_create_nonce('comment_media_upload'),
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

    public function handleAjaxUpload(): void
    {
        $debug = defined('WP_DEBUG') && WP_DEBUG;

        if (empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'comment_media_upload')) {
            if ($debug)
                error_log('CommentMedia AJAX: nonce failed');
            wp_send_json_error(['message' => __('Security check failed.', 'jankx')]);
        }

        if (!is_user_logged_in()) {
            if ($debug)
                error_log('CommentMedia AJAX: not logged in');
            wp_send_json_error(['message' => __('Vui lòng đăng nhập để upload file.', 'jankx')]);
        }

        if (empty($_FILES['file'])) {
            if ($debug)
                error_log('CommentMedia AJAX: no file in $_FILES. Keys: ' . implode(', ', array_keys($_FILES)));
            wp_send_json_error(['message' => __('Không tìm thấy file.', 'jankx')]);
        }

        $file = $_FILES['file'];

        if ($debug) {
            error_log(sprintf(
                'CommentMedia AJAX file: name=%s type=%s size=%d error=%d',
                $file['name'],
                $file['type'],
                $file['size'],
                $file['error']
            ));
        }

        $handler = new \Jankx\Extensions\CommentMedia\Ajax\UploadHandler();
        $result = $handler->handleFile($file);

        if (is_wp_error($result)) {
            if ($debug)
                error_log('CommentMedia AJAX: handleFile error: ' . $result->get_error_message());
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($debug)
            error_log('CommentMedia AJAX: upload success, attachmentId=' . $result['attachmentId']);
        wp_send_json_success($result);
    }

    /**
     * Register the upload zone on the chosen comment form hook.
     *
     * The hook to attach to is configurable via theme options
     * (`comment_form_submit_field` by default). Other hooks can be added or
     * removed through the `jankx/comment_media/upload_zone_positions` filter.
     */
    protected function registerUploadZoneHook(): void
    {
        $positions = $this->getUploadZonePositions();
        $hook = $this->getUploadZonePosition();

        if (!isset($positions[$hook])) {
            return;
        }

        if ($hook === 'comment_form_submit_field') {
            add_filter($hook, [$this, 'prependUploadZoneToSubmit'], 10, 2);
            return;
        }

        add_action($hook, [$this, 'renderUploadZoneAction']);
    }

    /**
     * Available upload zone positions: comment form hook => human label.
     *
     * Hook names are the option value; labels are the human readable text.
     * Extend or trim the list via the `jankx/comment_media/upload_zone_positions`
     * filter (return a map of hook => label).
     */
    public function getUploadZonePositions(): array
    {
        $positions = [
            'comment_form_top'          => __('Đầu biểu mẫu bình luận', 'jankx'),
            'comment_form_submit_field' => __('Trên nút gửi bình luận', 'jankx'),
        ];

        return (array) apply_filters('jankx/comment_media/upload_zone_positions', $positions);
    }

    /**
     * The configured position hook; falls back to the default when unknown.
     */
    public function getUploadZonePosition(): string
    {
        $default = 'comment_form_submit_field';
        $positions = $this->getUploadZonePositions();
        $stored = Settings::getOption(Settings::FIELD_UPLOAD_ZONE_POSITION, $default);

        if (!is_string($stored) || !isset($positions[$stored])) {
            return $default;
        }

        return $stored;
    }

    /**
     * Post types that support comment media.
     *
     * An empty array means every post type is supported. The list can be
     * extended/trimmed via the `jankx/comment_media/supported_post_types`
     * filter (only used when the theme option is left empty).
     */
    public function getPostTypes(): array
    {
        $stored = Settings::getOption(Settings::FIELD_POST_TYPES, []);

        if (is_array($stored) && !empty($stored)) {
            return array_values(array_filter(array_map('sanitize_key', $stored)));
        }

        $postTypes = apply_filters('jankx/comment_media/supported_post_types', []);
        if (is_array($postTypes) && !empty($postTypes)) {
            return array_values(array_filter(array_map('sanitize_key', $postTypes)));
        }

        return [];
    }

    /**
     * All post types available as choices in the theme options, filtered by
     * `jankx/comment_media/post_type_choices` for custom ordering/trimming.
     */
    public function getPostTypeChoices(): array
    {
        $postTypes = get_post_types(['public' => true], 'objects');
        $choices = [];

        foreach ($postTypes as $postType) {
            $choices[$postType->name] = $postType->labels->singular_name
                ? $postType->labels->singular_name . ' (' . $postType->name . ')'
                : $postType->name;
        }

        return (array) apply_filters('jankx/comment_media/post_type_choices', $choices);
    }

    /**
     * Whether comment media is supported on the post type of a post.
     */
    public function isSupportedPostType(?int $postId = null): bool
    {
        $post = $postId ? get_post($postId) : get_post();
        if (!$post instanceof \WP_Post) {
            return false;
        }

        $postTypes = $this->getPostTypes();

        return empty($postTypes) || in_array($post->post_type, $postTypes, true);
    }

    /**
     * Render the upload zone for comment form hooks that expect output.
     */
    public function renderUploadZoneAction(): void
    {
        echo $this->renderUploadZone();
    }

    public function renderUploadZone(string $idPrefix = '', ?int $postId = null): string
    {
        if (!$this->isEnabled()) {
            return '';
        }
        if (!$this->isSupportedPostType($postId)) {
            return '';
        }

        $zoneId = $idPrefix . 'comment-media-upload-zone';
        $inputId = $idPrefix . 'comment-media-file-input';
        $gridId = $idPrefix . 'comment-media-preview-grid';

        ob_start();
        ?>
        <div class="comment-media-upload-wrapper">
            <div class="comment-media-upload-zone" id="<?php echo esc_attr($zoneId); ?>">
                <div class="comment-media-upload-content">
                    <svg class="comment-media-upload-icon" width="40" height="40" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                    <p class="comment-media-upload-text">
                        <?php echo esc_html__('Kéo thả ảnh, video hoặc âm thanh vào đây', 'jankx'); ?>
                    </p>
                    <p class="comment-media-upload-hint">
                        <?php echo esc_html__('hoặc', 'jankx'); ?>
                        <label class="comment-media-upload-btn" for="<?php echo esc_attr($inputId); ?>">
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
                <input type="file" id="<?php echo esc_attr($inputId); ?>" class="comment-media-file-input" multiple
                    accept="image/*,video/*,audio/*">
                <div class="comment-media-preview-grid" id="<?php echo esc_attr($gridId); ?>"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Prepend the upload zone right above the comment submit button.
     */
    public function prependUploadZoneToSubmit(string $submitField, array $args = []): string
    {
        return $this->renderUploadZone() . $submitField;
    }

    public function saveMedia(int $commentId, int $approved, array $commentData): void
    {
        if (empty($_POST['comment_media_ids'])) {
            return;
        }
        if (!$this->isSupportedPostType(!empty($commentData['comment_post_ID']) ? (int) $commentData['comment_post_ID'] : null)) {
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

        // Lấy post ID từ comment data
        $postId = !empty($commentData['comment_post_ID'])
            ? (int) $commentData['comment_post_ID']
            : 0;

        // Cập nhật meta cờ: attachment đã được attach vào comment
        foreach ($mediaIds as $attachmentId) {
            update_post_meta($attachmentId, '_comment_media_orphan', '0');
            update_post_meta($attachmentId, '_comment_media_comment_id', $commentId);
            update_post_meta($attachmentId, '_comment_media_post_id', $postId);
        }
    }

    public function displayMedia(string $commentText, $comment = null): string
    {
        if (!$comment instanceof \WP_Comment) {
            return $commentText;
        }

        $commentId = $comment->comment_ID;
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
            <?php foreach ($validIds as $mediaId):
                $url = wp_get_attachment_url($mediaId);
                $mimeType = get_post_mime_type($mediaId);
                $name = get_the_title($mediaId);
                $type = explode('/', $mimeType)[0];
                ?>
                <div class="comment-media-item comment-media-item--<?php echo esc_attr($type); ?>">
                    <?php if ($type === 'image'): ?>
                        <a href="<?php echo esc_url($url); ?>" class="comment-media-link"
                            data-lightbox="comment-media-<?php echo esc_attr($mediaId); ?>">
                            <?php echo wp_get_attachment_image($mediaId, 'medium', false, [
                                'class' => 'comment-media-image',
                                'alt' => esc_attr($name),
                                'loading' => 'lazy',
                            ]); ?>
                        </a>
                    <?php elseif ($type === 'video'): ?>
                        <video controls class="comment-media-video" preload="metadata">
                            <source src="<?php echo esc_url($url); ?>" type="<?php echo esc_attr($mimeType); ?>">
                            <?php echo esc_html__('Trình duyệt không hỗ trợ video.', 'jankx'); ?>
                        </video>
                    <?php elseif ($type === 'audio'): ?>
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
