<?php

namespace Jankx\Extensions\CommentMedia\Admin;

/**
 * Thêm tab "Ảnh bình luận" vào trang Media Library (wp-admin/upload.php).
 *
 * URL patterns:
 *   /wp-admin/upload.php?comment_media=1            — tất cả comment media
 *   /wp-admin/upload.php?comment_media=1&orphan=1   — chỉ ảnh chưa attach vào comment
 */
class MediaLibraryFilter
{
    /** Post-meta key dùng để đánh cờ comment media */
    const META_FLAG       = '_is_comment_media';
    const META_ORPHAN     = '_comment_media_orphan';
    const META_COMMENT_ID = '_comment_media_comment_id';
    const META_POST_ID    = '_comment_media_post_id';
    const META_UPLOADED   = '_comment_media_uploaded_at';

    public function register(): void
    {
        // Tab / view trong list-mode upload.php
        add_filter('views_upload', [$this, 'addMediaLibraryViews']);

        // Filter query trong list mode
        add_action('pre_get_posts', [$this, 'filterQuery']);

        // Filter query trong grid mode (Media > ajax)
        add_filter('ajax_query_attachments_args', [$this, 'filterGridQuery']);

        // Cột bổ sung trong list view
        add_filter('manage_media_columns', [$this, 'addColumns']);
        add_action('manage_media_custom_column', [$this, 'renderColumn'], 10, 2);

        // Styles nhỏ cho trang upload.php
        add_action('admin_head-upload.php', [$this, 'inlineStyles']);
    }

    // -------------------------------------------------------------------------
    // Views / Tab
    // -------------------------------------------------------------------------

    public function addMediaLibraryViews(array $views): array
    {
        $commentMedia = isset($_GET['comment_media']) ? (int) $_GET['comment_media'] : 0;
        $orphan       = isset($_GET['orphan'])        ? (int) $_GET['orphan']        : 0;

        $totalAll    = $this->countCommentMedia(false);
        $totalOrphan = $this->countCommentMedia(true);

        // Tab chính — Ảnh bình luận
        $urlAll = add_query_arg([
            'comment_media' => '1',
            'orphan'        => false,
            'paged'         => false,
        ], 'upload.php');

        $activeAll = ($commentMedia && !$orphan) ? ' class="current"' : '';

        $views['comment_media'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            esc_url($urlAll),
            $activeAll,
            __('Ảnh bình luận', 'jankx'),
            $totalAll
        );

        // Sub-tab — Chưa attach (orphan)
        if ($totalOrphan > 0) {
            $urlOrphan = add_query_arg([
                'comment_media' => '1',
                'orphan'        => '1',
                'paged'         => false,
            ], 'upload.php');

            $activeOrphan = ($commentMedia && $orphan) ? ' class="current"' : '';

            $views['comment_media_orphan'] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($urlOrphan),
                $activeOrphan,
                __('Chưa attach', 'jankx'),
                $totalOrphan
            );
        }

        return $views;
    }

    // -------------------------------------------------------------------------
    // Query Filters
    // -------------------------------------------------------------------------

    public function filterQuery(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'upload') {
            return;
        }

        if (empty($_GET['comment_media'])) {
            return;
        }

        $metaQuery = [
            [
                'key'   => self::META_FLAG,
                'value' => '1',
            ],
        ];

        if (!empty($_GET['orphan'])) {
            $metaQuery[] = [
                'key'   => self::META_ORPHAN,
                'value' => '1',
            ];
        }

        $query->set('meta_query', $metaQuery);
    }

    /**
     * Filter cho grid mode (Media Library > grid) gọi qua AJAX.
     */
    public function filterGridQuery(array $query): array
    {
        if (empty($_REQUEST['comment_media'])) {
            return $query;
        }

        $metaQuery = [
            [
                'key'   => self::META_FLAG,
                'value' => '1',
            ],
        ];

        if (!empty($_REQUEST['orphan'])) {
            $metaQuery[] = [
                'key'   => self::META_ORPHAN,
                'value' => '1',
            ];
        }

        $query['meta_query'] = $metaQuery;

        return $query;
    }

    // -------------------------------------------------------------------------
    // Columns (list view)
    // -------------------------------------------------------------------------

    public function addColumns(array $columns): array
    {
        if (empty($_GET['comment_media'])) {
            return $columns;
        }

        // Chèn cột "Bình luận" và "Trạng thái" sau cột Author
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'author') {
                $new['cm_comment'] = __('Bình luận', 'jankx');
                $new['cm_post']    = __('Bài viết', 'jankx');
                $new['cm_status']  = __('Trạng thái', 'jankx');
            }
        }

        return $new;
    }

    public function renderColumn(string $columnName, int $postId): void
    {
        if (get_post_meta($postId, self::META_FLAG, true) !== '1') {
            echo '—';
            return;
        }

        switch ($columnName) {
            case 'cm_comment':
                $commentId = (int) get_post_meta($postId, self::META_COMMENT_ID, true);
                if ($commentId) {
                    $comment = get_comment($commentId);
                    if ($comment) {
                        printf(
                            '<a href="%s" title="%s">#%d</a><br><small>%s</small>',
                            esc_url(get_edit_comment_link($commentId)),
                            esc_attr(__('Xem bình luận', 'jankx')),
                            $commentId,
                            esc_html(wp_trim_words($comment->comment_content, 8))
                        );
                    } else {
                        echo '<span style="color:#999">' . esc_html__('Đã xoá', 'jankx') . '</span>';
                    }
                } else {
                    echo '<span style="color:#999">—</span>';
                }
                break;

            case 'cm_post':
                $postIdMeta = (int) get_post_meta($postId, self::META_POST_ID, true);
                if ($postIdMeta) {
                    $post = get_post($postIdMeta);
                    if ($post) {
                        printf(
                            '<a href="%s">%s</a>',
                            esc_url(get_edit_post_link($postIdMeta)),
                            esc_html(get_the_title($postIdMeta))
                        );
                    } else {
                        echo '<span style="color:#999">' . esc_html__('Đã xoá', 'jankx') . '</span>';
                    }
                } else {
                    echo '<span style="color:#999">—</span>';
                }
                break;

            case 'cm_status':
                $isOrphan = get_post_meta($postId, self::META_ORPHAN, true);
                $uploaded = (int) get_post_meta($postId, self::META_UPLOADED, true);

                if ($isOrphan === '1') {
                    $age = $uploaded ? human_time_diff($uploaded) . ' ' . __('trước', 'jankx') : '—';
                    printf(
                        '<span class="cm-badge cm-badge--orphan" title="%s">%s</span><br><small>%s %s</small>',
                        esc_attr(__('Ảnh chưa được gắn vào bình luận nào và sẽ bị xoá tự động', 'jankx')),
                        esc_html__('Chưa attach', 'jankx'),
                        esc_html__('Upload', 'jankx'),
                        esc_html($age)
                    );
                } else {
                    echo '<span class="cm-badge cm-badge--attached">' . esc_html__('Đã attach', 'jankx') . '</span>';
                }
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Inline Styles
    // -------------------------------------------------------------------------

    public function inlineStyles(): void
    {
        ?>
        <style>
            .cm-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                line-height: 1.6;
            }
            .cm-badge--orphan {
                background: #fcf0f1;
                color: #c0392b;
                border: 1px solid #f5c6cb;
            }
            .cm-badge--attached {
                background: #f0faf0;
                color: #1e7e34;
                border: 1px solid #c3e6cb;
            }
            #the-list td.cm_comment,
            #the-list td.cm_post,
            #the-list td.cm_status {
                white-space: normal;
            }
        </style>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function countCommentMedia(bool $orphanOnly): int
    {
        $metaQuery = [
            [
                'key'   => self::META_FLAG,
                'value' => '1',
            ],
        ];

        if ($orphanOnly) {
            $metaQuery[] = [
                'key'   => self::META_ORPHAN,
                'value' => '1',
            ];
        }

        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'meta_query'     => $metaQuery,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);

        return (int) $query->found_posts;
    }
}
