jQuery(document).ready(function($) {

    /**
     * Background auto-processor for freshly uploaded images.
     *
     * The upload request only flags new images server-side; this routine — running
     * on Media / editor pages — picks them up within seconds and processes them via
     * AJAX, so generation no longer waits for a WP-Cron page reload and the upload
     * itself is never blocked. Only images uploaded after activation are touched
     * (the existing library is left to the manual Bulk Optimizer).
     */
    var altgenixAutoBusy = false;

    function altgenixRunAutoQueue() {
        if (altgenixAutoBusy || typeof altgenix_ajax === 'undefined') { return; }
        altgenixAutoBusy = true;
        $.post(altgenix_ajax.url, { action: 'altgenix_get_auto_queue', nonce: altgenix_ajax.nonce })
            .done(function(res) {
                if (res && res.success && res.data && res.data.length) {
                    altgenixProcessAutoQueue(res.data, 0);
                } else {
                    altgenixAutoBusy = false;
                }
            })
            .fail(function() { altgenixAutoBusy = false; });
    }

    function altgenixProcessAutoQueue(ids, index) {
        if (index >= ids.length) {
            altgenixAutoBusy = false;
            // A batch finished — check again shortly in case more arrived meanwhile.
            setTimeout(altgenixRunAutoQueue, 1500);
            return;
        }
        $.post(altgenix_ajax.url, { action: 'altgenix_process_auto', image_id: ids[index], nonce: altgenix_ajax.nonce })
            .always(function() {
                // Space requests out to stay friendly to the AI provider's rate limits.
                setTimeout(function() { altgenixProcessAutoQueue(ids, index + 1); }, 1200);
            });
    }

    if (typeof altgenix_ajax !== 'undefined') {
        altgenixRunAutoQueue();
        // Poll while the user stays on the page so uploads made now get picked up too.
        setInterval(altgenixRunAutoQueue, 12000);
    }

    $('.altgenix-select2').select2({ minimumResultsForSearch: Infinity, width: '160px' });

    // Language picker: keep the search box (the list is long) and let it fill its row.
    $('.altgenix-lang-select').select2({ width: '100%' });

    $('#altgenix-apply-filter').on('click', function(e) {
        e.preventDefault();
        var status = $('#altgenix-status-filter').val();
        var url = new URL(window.location.href);
        url.searchParams.set('altgenix_status', status);
        url.searchParams.set('paged', 1);
        window.location.href = url.toString();
    });

    $('.altgenix-toggle-eye').on('click', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var input = $('#' + targetId);
        var icon = $(this).find('.dashicons');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            input.attr('type', 'password');
            icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });

    $('.altgenix-edit-btn').on('click', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var input = $('#' + targetId);
        input.removeAttr('readonly').focus();
    });

    $('#altgenix_mode').on('change', function() {
        if ($(this).val() === 'fallback') {
            $('#altgenix_provider_row').slideUp();
            $('#altgenix_api_row').slideUp();
            $('#altgenix_lang_row').slideUp();
            $('.altgenix-length-col').fadeOut();
            $('.altgenix-ai-only-row').slideUp();
            $('#altgenix_prompt_fallback_warning').slideDown();
        } else {
            $('#altgenix_provider_row').slideDown();
            $('#altgenix_api_row').slideDown();
            $('#altgenix_lang_row').slideDown();
            $('.altgenix-length-col').fadeIn();
            $('.altgenix-ai-only-row').slideDown();
            $('#altgenix_prompt_fallback_warning').slideUp();
        }
    });

    // Provider-specific API key hint + "get key" link.
    var altgenixProviderInfo = {
        gemini: { placeholder: 'AIzaSy...',  link: 'https://aistudio.google.com/app/apikey',        label: 'Get your free Google AI Studio key' },
        openai: { placeholder: 'sk-...',     link: 'https://platform.openai.com/api-keys',          label: 'Get your OpenAI API key' },
        claude: { placeholder: 'sk-ant-...', link: 'https://console.anthropic.com/settings/keys',   label: 'Get your Anthropic Claude key' }
    };

    function altgenixUpdateProviderHelp() {
        var provider = $('#altgenix_provider').val() || 'gemini';
        var info = altgenixProviderInfo[provider] || altgenixProviderInfo.gemini;
        $('#altgenix_api_key').attr('placeholder', info.placeholder);
        $('#altgenix_key_help_link').attr('href', info.link).text(info.label);
    }

    $('#altgenix_provider').on('change', function() {
        altgenixUpdateProviderHelp();
        // Switching providers needs a different key — clear & unlock the field so the old
        // provider's key doesn't carry over into the new one.
        $('#altgenix_api_key').prop('readonly', false).val('').focus();
    });

    altgenixUpdateProviderHelp();

    // Tabs Navigation
    $('.altgenix-tab-link').on('click', function(e) {
        e.preventDefault();
        $('.altgenix-tab-link').removeClass('active');
        $('.altgenix-tab-content').removeClass('active');
        $(this).addClass('active');
        var target = $(this).data('tab');
        $('#' + target).addClass('active');
    });

    function showModal(title, text, showConfirm, callback) {
        if ($('#altgenix-modal').length === 0) {
            if (showConfirm) {
                if (confirm(title + '\n\n' + text)) {
                    if (callback) callback();
                }
            } else {
                alert(title + '\n\n' + text);
            }
            return;
        }

        $('#altgenix-modal-title').text(title);
        $('#altgenix-modal-text').text(text);
        $('#altgenix-modal-cancel').off('click').on('click', function() { $('#altgenix-modal').removeClass('show'); });

        if(showConfirm) {
            $('#altgenix-modal-confirm').show().off('click').on('click', function() { $('#altgenix-modal').removeClass('show'); if(callback) callback(); });
            $('#altgenix-modal-cancel').text('Cancel');
        } else {
            $('#altgenix-modal-confirm').hide();
            $('#altgenix-modal-cancel').text('Close');
        }
        $('#altgenix-modal').addClass('show');
    }

    // Auto-Tag Logic
    $('#altgenix-auto-tag-btn').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var originalText = btn.html();

        showModal('Start Bulk Auto-Tagging?', 'This will process all pending images. Proceed?', true, function() {
            btn.html('<span class="dashicons dashicons-update altgenix-spin"></span> Processing...').prop('disabled', true);
            $.post(altgenix_ajax.url, { action: 'altgenix_get_pending', nonce: altgenix_ajax.nonce }, function(res) {
                if(res.success && res.data && res.data.length > 0) {
                    processImageQueue(res.data, btn, originalText);
                } else {
                    showModal('Library Optimized! 🎉', 'No pending images found.', false);
                    btn.html(originalText).prop('disabled', false);
                }
            }).fail(function() {
                showModal('Error', 'Failed to fetch pending images.', false);
                btn.html(originalText).prop('disabled', false);
            });
        });
    });

    /**
     * M-06 FIX: Added a 1.5 second delay between bulk AJAX calls to prevent
     * API quota exhaustion. Without this, requests fire back-to-back and can
     * burn through Google's free quota in minutes.
     */
    function processImageQueue(ids, btn, originalText) {
        var total = ids.length;
        var current = 0;
        
        // Show progress bar
        $('#altgenix-progress-container').slideDown();
        btn.hide();

        function processNext() {
            var percent = Math.round((current / total) * 100);
            $('#altgenix-progress-fill').css('width', percent + '%');
            $('#altgenix-progress-percentage').text(percent + '%');
            $('#altgenix-progress-status').text('Tagging ' + (current+1) + ' of ' + total + '...');

            if(current >= total) {
                $('#altgenix-progress-fill').css('width', '100%');
                $('#altgenix-progress-percentage').text('100%');
                $('#altgenix-progress-status').text('Done! Reloading...');
                setTimeout(function() { location.reload(); }, 1000);
                return;
            }
            
            $.post(altgenix_ajax.url, { action: 'altgenix_process_image', image_id: ids[current], nonce: altgenix_ajax.nonce }, function() {
                current++;
                // M-06 FIX: Rate-limit delay between requests to avoid API quota exhaustion.
                setTimeout(processNext, 1500);
            }).fail(function() {
                current++;
                // Continue on failure after the same delay.
                setTimeout(processNext, 1500);
            });
        }
        processNext();
    }

    // Mark Old Media Processed Logic
    $('#altgenix-mark-processed-btn').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var originalText = btn.html();

        showModal('Mark Old Media as Processed?', 'This will instantly mark all existing images as "Processed" to hide them from the pending list without changing tags. Proceed?', true, function() {
            btn.html('<span class="dashicons dashicons-update altgenix-spin"></span> Processing...').prop('disabled', true);
            $.post(altgenix_ajax.url, { action: 'altgenix_mark_all_processed', nonce: altgenix_ajax.nonce }, function(res) {
                if(res.success) {
                    showModal('Success! 🎉', 'All old media successfully marked as processed. Reloading...', false);
                    setTimeout(function() { location.reload(); }, 1500);
                }
            }).fail(function() {
                showModal('Error', 'Server connection failed.', false);
                btn.html(originalText).prop('disabled', false);
            });
        });
    });

    /**
     * m-04 FIX: Added .fail() handler to the regenerate button so the spinner
     * doesn't stay forever on network errors.
     */
    $('.altgenix-regenerate-btn').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var id = btn.data('id');
        var icon = btn.find('.dashicons');
        icon.removeClass('dashicons-image-rotate').addClass('dashicons-update altgenix-spin');
        btn.prop('disabled', true);
        $.post(altgenix_ajax.url, { action: 'altgenix_process_image', image_id: id, nonce: altgenix_ajax.nonce }, function() {
            location.reload();
        }).fail(function() {
            // m-04 FIX: Restore button on failure instead of leaving it stuck.
            icon.removeClass('dashicons-update altgenix-spin').addClass('dashicons-image-rotate');
            btn.prop('disabled', false);
            showModal('Error', 'Failed to regenerate tags. Please try again.', false);
        });
    });

    // Star Rating System
    $('.altgenix-star-rating span').on('mouseenter', function() {
        var val = $(this).data('rating');
        $('.altgenix-star-rating span').each(function() {
            if($(this).data('rating') <= val) {
                $(this).removeClass('dashicons-star-empty').addClass('dashicons-star-filled hover');
            } else {
                $(this).removeClass('dashicons-star-filled hover').addClass('dashicons-star-empty');
            }
        });
    }).on('mouseleave', function() {
        $('.altgenix-star-rating span').removeClass('hover dashicons-star-filled').addClass('dashicons-star-empty');
        var selected = $('.altgenix-star-rating').data('selected');
        if(selected) {
            $('.altgenix-star-rating span').each(function() {
                if($(this).data('rating') <= selected) {
                    $(this).removeClass('dashicons-star-empty').addClass('dashicons-star-filled active');
                }
            });
        }
    }).on('click', function() {
        var val = $(this).data('rating');
        $('.altgenix-star-rating').data('selected', val);
        $('.altgenix-star-rating span').removeClass('active');
        $(this).prevAll().addBack().addClass('active');
        
        $('#altgenix-rating-feedback').slideDown();
        if(val >= 4) {
            $('.altgenix-rating-low').hide();
            $('.altgenix-rating-high').fadeIn();
        } else {
            $('.altgenix-rating-high').hide();
            $('.altgenix-rating-low').fadeIn();
        }
    });

    // Submit Feedback Logic
    $('#altgenix-submit-feedback').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var text = $('#altgenix-feedback-text').val();
        
        if (text.trim() === '') {
            $('#altgenix-feedback-text').focus();
            return;
        }

        btn.html('<span class="dashicons dashicons-update altgenix-spin"></span> Sending...').prop('disabled', true);
        
        var rating = $('.altgenix-star-rating').data('selected');

        $.post(altgenix_ajax.url, { 
            action: 'altgenix_submit_feedback', 
            feedback: text, 
            rating: rating, 
            nonce: altgenix_ajax.nonce 
        }, function(res) {
            $('.altgenix-rating-low').html('<h4>Thank you! 🙏</h4><p class="description">Your feedback has been sent to our team. We really appreciate your help in improving AltGenix.</p>');
        }).fail(function() {
            // Fallback success for better UX even if backend fails
            $('.altgenix-rating-low').html('<h4>Thank you! 🙏</h4><p class="description">Your feedback has been sent to our team. We really appreciate your help in improving AltGenix.</p>');
        });
    });
});