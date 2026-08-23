<?php

namespace Jankx\Extensions\CommentMedia\Admin;

/**
 * Lập lịch WP-Cron xoá orphaned comment media (ảnh upload từ comments
 * nhưng chưa hoặc không bao giờ được attach vào bình luận nào).
 *
 * Cron hook: comment_media_cleanup
 * Tần suất:  Hàng ngày (daily)
 */
class CleanupScheduler
{
    const CRON_HOOK    = 'comment_media_cleanup';
    const CRON_RECUR   = 'daily';
    const DEFAULT_DAYS = 7; // Xoá orphan sau 7 ngày nếu không được cấu hình

    public function register(): void
    {
        // Đăng ký callback cho cron hook
        add_action(self::CRON_HOOK, [$this, 'runCleanup']);

        // Kích hoạt cron nếu chưa được lên lịch
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_RECUR, self::CRON_HOOK);
        }
    }

    /**
     * Huỷ cron khi extension bị vô hiệu (gọi từ deactivation hook nếu có).
     */
    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Callback cron: xoá attachment orphan quá hạn.
     */
    public function runCleanup(): void
    {
        $days      = $this->getOrphanDays();
        $threshold = time() - ($days * DAY_IN_SECONDS);

        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 50, // Xử lý từng batch 50 để tránh timeout
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => MediaLibraryFilter::META_FLAG,
                    'value' => '1',
                ],
                [
                    'key'   => MediaLibraryFilter::META_ORPHAN,
                    'value' => '1',
                ],
                [
                    'key'     => MediaLibraryFilter::META_UPLOADED,
                    'value'   => $threshold,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        if (empty($query->posts)) {
            return;
        }

        $deleted = 0;
        $failed  = 0;

        foreach ($query->posts as $attachmentId) {
            // Double-check orphan flag trước khi xoá
            if (get_post_meta($attachmentId, MediaLibraryFilter::META_ORPHAN, true) !== '1') {
                continue;
            }

            // Kiểm tra xem attachment này có đang được dùng ở comment nào không
            // (phòng trường hợp meta không được cập nhật đúng)
            $commentId = (int) get_post_meta($attachmentId, MediaLibraryFilter::META_COMMENT_ID, true);
            if ($commentId > 0) {
                $comment = get_comment($commentId);
                if ($comment) {
                    // Thực ra đã được attach — cập nhật lại cờ và bỏ qua
                    update_post_meta($attachmentId, MediaLibraryFilter::META_ORPHAN, '0');
                    continue;
                }
            }

            $result = wp_delete_attachment($attachmentId, true);
            if ($result) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'CommentMedia CleanupScheduler: deleted=%d failed=%d (threshold=%d days)',
                $deleted,
                $failed,
                $days
            ));
        }
    }

    /**
     * Chạy thủ công — dùng để test hoặc trigger từ admin.
     */
    public static function runManual(): array
    {
        $scheduler = new self();
        $scheduler->runCleanup();

        return [
            'success' => true,
            'message' => __('Đã chạy dọn dẹp orphan media.', 'jankx'),
        ];
    }

    private function getOrphanDays(): int
    {
        $days = (int) Settings::getOption(Settings::FIELD_ORPHAN_DAYS, self::DEFAULT_DAYS);

        return max(1, $days);
    }
}
