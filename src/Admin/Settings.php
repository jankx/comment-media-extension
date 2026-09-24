<?php

namespace Jankx\Extensions\CommentMedia\Admin;

class Settings
{
    const FIELD_ENABLED            = 'cm_enabled';
    const FIELD_MAX_FILES          = 'cm_max_files';
    const FIELD_MAX_SIZE           = 'cm_max_size';
    const FIELD_ALLOWED_TYPES      = 'cm_allowed_types';
    const FIELD_ORPHAN_DAYS        = 'cm_orphan_days';
    const FIELD_UPLOAD_ZONE_POSITION = 'cm_upload_zone_position';
    const FIELD_POST_TYPES         = 'cm_post_types';

    public static function getOption(string $key, $default = null)
    {
        $options = get_option('jankx_options', []);

        if (isset($options[$key])) {
            return $options[$key];
        }

        return $default;
    }
}
