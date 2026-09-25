(function ($) {
    'use strict';

    const CommentMedia = {
        config: null,
        uploaders: [],

        init: function () {
            this.config = window.commentMedia;
            if (!this.config) return;

            const zones = document.querySelectorAll('.comment-media-upload-zone');
            if (!zones.length) return;

            const self = this;
            zones.forEach(function (zone) {
                self.uploaders.push(self.createUploader(zone));
            });

            window.commentMediaUploaders = this.uploaders;
        },

        createUploader: function (zone) {
            const $zone = $(zone);
            const $grid = $zone.find('.comment-media-preview-grid').first();
            const $fileInput = $zone.find('.comment-media-file-input').first();

            if (!$zone.length || !$grid.length || !$fileInput.length) {
                return null;
            }

            let $owner = $zone.closest('form#commentform');
            if (!$owner.length) {
                $owner = $zone.closest('.jankx-review-form');
            }
            if (!$owner.length) {
                $owner = $zone.closest('form');
            }
            if (!$owner.length) {
                $owner = $zone;
            }

            const uploader = {
                config: CommentMedia.config,
                $zone: $zone,
                $grid: $grid,
                $fileInput: $fileInput,
                $owner: $owner,
                uploading: 0,
                seq: 0
            };

            CommentMedia.bindEvents(uploader);
            CommentMedia.checkMaxFiles(uploader);

            $zone.on('comment-media:reset', function () {
                CommentMedia.reset(uploader);
            });

            return uploader;
        },

        bindEvents: function (u) {
            const self = this;

            u.$fileInput.on('change', function (e) {
                self.handleFiles(u, e.target.files);
                this.value = '';
            });

            u.$zone.on('dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('comment-media-zone--dragover');
            });

            u.$zone.on('dragleave drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('comment-media-zone--dragover');
            });

            u.$zone.on('drop', function (e) {
                self.handleFiles(u, e.originalEvent.dataTransfer.files);
            });

            u.$grid.on('click', '.comment-media-remove-btn', function (e) {
                e.preventDefault();
                const $item = $(this).closest('.comment-media-preview-item');
                self.removeFile(u, $item.data('index'), $item);
            });

            u.$owner.on('submit', function () {
                if (self.isUploading(u)) {
                    self.showToast(u, self.config.i18n.uploading, 'warning');
                    return false;
                }
            });
        },

        isUploading: function (u) {
            return u.uploading > 0;
        },

        handleFiles: function (u, files) {
            if (!files || !files.length) return;

            const self = this;
            const remaining = this.config.maxFiles - this.countFiles(u);

            if (remaining <= 0) {
                this.showToast(u, this.config.i18n.maxFilesExceeded, 'warning');
                return;
            }

            const filesToProcess = Array.from(files).slice(0, remaining);

            if (files.length > remaining) {
                this.showToast(u, this.config.i18n.maxFilesExceeded, 'warning');
            }

            filesToProcess.forEach(function (file) {
                self.processFile(u, file);
            });
        },

        processFile: function (u, file) {
            if (!this.validateFile(u, file)) {
                return;
            }

            const key = u.seq++;
            const $preview = this.createPreviewElement(u, file, key);
            u.$grid.append($preview);

            this.uploadFile(u, file, $preview, key);
        },

        validateFile: function (u, file) {
            const maxSizeBytes = this.config.maxSize * 1024 * 1024;

            if (file.size > maxSizeBytes) {
                this.showToast(u, this.config.i18n.fileTooLarge + ': ' + file.name, 'error');
                return false;
            }

            const fileType = this.getFileCategory(file.type);
            if (!fileType || !this.config.allowedTypes.includes(fileType)) {
                this.showToast(u, this.config.i18n.invalidType + ': ' + file.name, 'error');
                return false;
            }

            return true;
        },

        getFileCategory: function (mimeType) {
            if (mimeType.startsWith('image/')) return 'image';
            if (mimeType.startsWith('video/')) return 'video';
            if (mimeType.startsWith('audio/')) return 'audio';
            return null;
        },

        createPreviewElement: function (u, file, index) {
            const self = this;
            const $item = $('<div class="comment-media-preview-item" data-index="' + index + '">');
            const $preview = $('<div class="comment-media-preview-content">');
            const $removeBtn = $('<button type="button" class="comment-media-remove-btn" title="' + this.config.i18n.removeFile + '">×</button>');
            const $progress = $('<div class="comment-media-progress"><div class="comment-media-progress-bar"></div></div>');
            const $info = $('<div class="comment-media-preview-info"><span class="comment-media-preview-name">' + this.escapeHtml(file.name) + '</span><span class="comment-media-preview-size">' + this.formatSize(file.size) + '</span></div>');

            const fileType = this.getFileCategory(file.type);

            if (fileType === 'image') {
                const reader = new FileReader();
                reader.onload = function (e) {
                    $preview.prepend('<img src="' + e.target.result + '" alt="' + self.escapeHtml(file.name) + '" class="comment-media-preview-img">');
                };
                reader.readAsDataURL(file);
            } else if (fileType === 'video') {
                const url = URL.createObjectURL(file);
                $preview.prepend('<video src="' + url + '" class="comment-media-preview-video" preload="metadata"></video>');
            } else if (fileType === 'audio') {
                $preview.prepend('<div class="comment-media-preview-audio-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></div>');
            }

            $item.append($preview, $removeBtn, $progress, $info);

            return $item;
        },

        uploadFile: function (u, file, $preview, key) {
            const self = this;
            const $progressBar = $preview.find('.comment-media-progress-bar');
            const $progress = $preview.find('.comment-media-progress');

            $preview.addClass('comment-media-preview-item--uploading');
            u.uploading++;

            const formData = new FormData();
            formData.append('action', this.config.action);
            formData.append('nonce', this.config.nonce);
            formData.append('file', file);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.config.ajaxUrl, true);

            xhr.upload.addEventListener('progress', function (evt) {
                if (evt.lengthComputable) {
                    const percent = Math.round((evt.loaded / evt.total) * 100);
                    $progressBar.css('width', percent + '%');
                }
            }, false);

            xhr.onload = function () {
                u.uploading = Math.max(0, u.uploading - 1);

                let response;
                try {
                    response = JSON.parse(xhr.responseText);
                } catch (e) {
                    response = null;
                }

                if (xhr.status >= 200 && xhr.status < 300 && response && response.success) {
                    $preview
                        .removeClass('comment-media-preview-item--uploading')
                        .addClass('comment-media-preview-item--done');
                    $progress.css('width', '100%');
                    $progress.remove();
                    self.addHiddenInput(u, response.data.attachmentId, key);
                    self.checkMaxFiles(u);
                } else {
                    let message = self.config.i18n.uploadError;
                    if (response && response.message) {
                        message = response.message;
                    }
                    self.handleUploadError(u, $preview, message);
                }
            };

            xhr.onerror = function () {
                u.uploading = Math.max(0, u.uploading - 1);
                self.handleUploadError(u, $preview, self.config.i18n.uploadError);
            };

            xhr.send(formData);
        },

        handleUploadError: function (u, $preview, message) {
            $preview
                .removeClass('comment-media-preview-item--uploading')
                .addClass('comment-media-preview-item--error');
            $preview.find('.comment-media-progress').remove();
            $preview.find('.comment-media-preview-info').append(
                '<span class="comment-media-preview-error">' +
                    this.escapeHtml(message) +
                    '</span>'
            );
            this.checkMaxFiles(u);
        },

        addHiddenInput: function (u, attachmentId, index) {
            u.$owner.append(
                '<input type="hidden" name="comment_media_ids[]" value="' +
                    attachmentId +
                    '" class="comment-media-hidden-input" data-index="' +
                    index +
                    '">'
            );
        },

        removeFile: function (u, index, $item) {
            $item.fadeOut(200, function () {
                $(this).remove();
            });

            u.$owner.find(
                '.comment-media-hidden-input[data-index="' + index + '"]'
            ).remove();

            this.checkMaxFiles(u);
        },

        countFiles: function (u) {
            return u.$grid.find('.comment-media-preview-item').length;
        },

        checkMaxFiles: function (u) {
            const count = this.countFiles(u);

            if (count >= this.config.maxFiles) {
                u.$zone.addClass('comment-media-zone--max-reached');
                u.$fileInput.prop('disabled', true);
            } else {
                u.$zone.removeClass('comment-media-zone--max-reached');
                u.$fileInput.prop('disabled', false);
            }
        },

        reset: function (u) {
            u.$grid.empty();
            u.$owner.find('.comment-media-hidden-input').remove();
            u.uploading = 0;
            u.seq = 0;
            this.checkMaxFiles(u);
        },

        showToast: function (u, message, type) {
            type = type || 'info';

            const $toast = $(
                '<div class="comment-media-toast comment-media-toast--' +
                    type +
                    '">' +
                    this.escapeHtml(message) +
                    '</div>'
            );

            $('body').append($toast);

            setTimeout(function () {
                $toast.addClass('comment-media-toast--show');
            }, 10);

            setTimeout(function () {
                $toast.removeClass('comment-media-toast--show');
                setTimeout(function () {
                    $toast.remove();
                }, 300);
            }, 3000);
        },

        formatSize: function (bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        },

        escapeHtml: function (text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            };
            return text.replace(/[&<>"']/g, function (m) {
                return map[m];
            });
        },
    };

    $(document).ready(function () {
        CommentMedia.init();
    });
})(jQuery);
