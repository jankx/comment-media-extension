(function ($) {
    'use strict';

    const CommentMedia = {
        config: null,
        uploadedFiles: [],
        $zone: null,
        $grid: null,
        $fileInput: null,
        $form: null,

        init: function () {
            this.config = window.commentMedia;
            if (!this.config) return;

            this.$form = $('form#commentform');
            if (!this.$form.length) return;

            this.$zone = $('#comment-media-upload-zone');
            this.$grid = $('#comment-media-preview-grid');
            this.$fileInput = $('#comment-media-file-input');

            if (!this.$zone.length) return;

            this.bindEvents();
            this.checkMaxFiles();
        },

        bindEvents: function () {
            const self = this;

            this.$fileInput.on('change', function (e) {
                self.handleFiles(e.target.files);
                this.value = '';
            });

            this.$zone.on('dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('comment-media-zone--dragover');
            });

            this.$zone.on('dragleave drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('comment-media-zone--dragover');
            });

            this.$zone.on('drop', function (e) {
                const files = e.originalEvent.dataTransfer.files;
                self.handleFiles(files);
            });

            this.$grid.on('click', '.comment-media-remove-btn', function (e) {
                e.preventDefault();
                const $item = $(this).closest('.comment-media-preview-item');
                const index = $item.data('index');
                self.removeFile(index, $item);
            });

            this.$form.on('submit', function () {
                self.onSubmit();
            });
        },

        handleFiles: function (files) {
            if (!files || !files.length) return;

            const self = this;
            const remaining = this.config.maxFiles - this.uploadedFiles.length;

            if (remaining <= 0) {
                this.showToast(this.config.i18n.maxFilesExceeded, 'warning');
                return;
            }

            const filesToProcess = Array.from(files).slice(0, remaining);

            if (files.length > remaining) {
                this.showToast(
                    this.config.i18n.maxFilesExceeded,
                    'warning'
                );
            }

            filesToProcess.forEach(function (file) {
                self.processFile(file);
            });
        },

        processFile: function (file) {
            const self = this;

            if (!this.validateFile(file)) {
                return;
            }

            const index = this.uploadedFiles.length;
            const $preview = this.createPreviewElement(file, index);
            this.$grid.append($preview);

            this.uploadFile(file, $preview, index);
        },

        validateFile: function (file) {
            const maxSizeBytes = this.config.maxSize * 1024 * 1024;

            if (file.size > maxSizeBytes) {
                this.showToast(
                    this.config.i18n.fileTooLarge + ': ' + file.name,
                    'error'
                );
                return false;
            }

            const fileType = this.getFileCategory(file.type);
            if (!fileType || !this.config.allowedTypes.includes(fileType)) {
                this.showToast(
                    this.config.i18n.invalidType + ': ' + file.name,
                    'error'
                );
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

        createPreviewElement: function (file, index) {
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

            var self = this;
            $item.append($preview, $removeBtn, $progress, $info);

            return $item;
        },

        uploadFile: function (file, $preview, index) {
            const self = this;
            const $progressBar = $preview.find('.comment-media-progress-bar');
            const $progress = $preview.find('.comment-media-progress');

            $preview.addClass('comment-media-preview-item--uploading');

            const formData = new FormData();
            formData.append('file', file);

            $.ajax({
                url: this.config.restUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                headers: {
                    'X-WP-Nonce': this.config.nonce,
                },
                xhr: function () {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener(
                        'progress',
                        function (evt) {
                            if (evt.lengthComputable) {
                                const percent = Math.round(
                                    (evt.loaded / evt.total) * 100
                                );
                                $progressBar.css('width', percent + '%');
                            }
                        },
                        false
                    );
                    return xhr;
                },
                success: function (response) {
                    if (response.success) {
                        self.uploadedFiles[index] = response.data;
                        $preview
                            .removeClass('comment-media-preview-item--uploading')
                            .addClass('comment-media-preview-item--done');
                        $progress.remove();
                        self.addHiddenInput(index, response.data.attachmentId);
                        self.checkMaxFiles();
                    } else {
                        self.handleUploadError(
                            $preview,
                            response.message || self.config.i18n.uploadError
                        );
                    }
                },
                error: function (xhr) {
                    let message = self.config.i18n.uploadError;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                    self.handleUploadError($preview, message);
                },
            });
        },

        handleUploadError: function ($preview, message) {
            $preview
                .removeClass('comment-media-preview-item--uploading')
                .addClass('comment-media-preview-item--error');
            $preview.find('.comment-media-progress').remove();
            $preview.find('.comment-media-preview-info').append(
                '<span class="comment-media-preview-error">' +
                    this.escapeHtml(message) +
                    '</span>'
            );
        },

        addHiddenInput: function (index, attachmentId) {
            this.$form.append(
                '<input type="hidden" name="comment_media_ids[]" value="' +
                    attachmentId +
                    '" class="comment-media-hidden-input" data-index="' +
                    index +
                    '">'
            );
        },

        removeFile: function (index, $item) {
            $item.fadeOut(200, function () {
                $(this).remove();
            });

            this.uploadedFiles[index] = null;

            this.$form.find(
                '.comment-media-hidden-input[data-index="' + index + '"]'
            ).remove();

            this.checkMaxFiles();
        },

        checkMaxFiles: function () {
            const count = this.uploadedFiles.filter(
                function (f) {
                    return f !== null;
                }
            ).length;

            if (count >= this.config.maxFiles) {
                this.$zone.addClass('comment-media-zone--max-reached');
                this.$fileInput.prop('disabled', true);
            } else {
                this.$zone.removeClass('comment-media-zone--max-reached');
                this.$fileInput.prop('disabled', false);
            }
        },

        onSubmit: function () {
            const uploading = this.$zone.find(
                '.comment-media-preview-item--uploading'
            ).length;

            if (uploading > 0) {
                this.showToast(this.config.i18n.uploading, 'warning');
                return false;
            }
        },

        showToast: function (message, type) {
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
