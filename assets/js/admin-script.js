jQuery(document).ready(function($) {
    
    $('.altgenix-select2').select2({ minimumResultsForSearch: Infinity, width: '160px' });

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
            $('#altgenix_api_row').slideUp();
            $('.altgenix-length-col').fadeOut();
        } else {
            $('#altgenix_api_row').slideDown();
            $('.altgenix-length-col').fadeIn();
        }
    });

    function showModal(title, text, showConfirm, callback) {
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
                if(res.success && res.data.length > 0) {
                    processImageQueue(res.data, btn, originalText);
                } else {
                    showModal('Library Optimized! 🎉', 'No pending images found.', false);
                    btn.html(originalText).prop('disabled', false);
                }
            });
        });
    });

    function processImageQueue(ids, btn, originalText) {
        var total = ids.length;
        var current = 0;
        function processNext() {
            if(current >= total) {
                btn.html('Done! Reloading...');
                setTimeout(function() { location.reload(); }, 1000);
                return;
            }
            btn.html('<span class="dashicons dashicons-update altgenix-spin"></span> Tagging ' + (current+1) + ' of ' + total);
            $.post(altgenix_ajax.url, { action: 'altgenix_process_image', image_id: ids[current], nonce: altgenix_ajax.nonce }, function() {
                current++; processNext(); 
            }).fail(function() { current++; processNext(); });
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

    $('.altgenix-regenerate-btn').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var id = btn.data('id');
        var icon = btn.find('.dashicons');
        icon.removeClass('dashicons-image-rotate').addClass('dashicons-update altgenix-spin');
        btn.prop('disabled', true);
        $.post(altgenix_ajax.url, { action: 'altgenix_process_image', image_id: id, nonce: altgenix_ajax.nonce }, function() { location.reload(); });
    });
});
