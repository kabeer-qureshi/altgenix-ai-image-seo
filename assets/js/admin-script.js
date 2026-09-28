jQuery(function ($) {
    'use strict';
    if (typeof altgenix_ajax === 'undefined') { return; }
    var config = altgenix_ajax;
    var bulkBusy = false;
    var autoBusy = false;
    var autoStopped = false;
    var form = $('#altgenix-settings-form');
    var dirty = false;
    var revision = 0;
    var saving = false;
    var savedProvider = $('#altgenix_provider').val() || 'gemini';
    var activeProvider = savedProvider;
    var savedKeys = $.extend({}, config.saved_keys || {});
    var drafts = {};
    var editingKeys = {};
    var modelStates = {};
    var verifyingModels = false;

    function request(action, data) {
        return $.ajax({ url: config.url, type: 'POST', dataType: 'json', timeout: 55000,
            data: $.extend({ action: action, nonce: config.nonce }, data || {}) });
    }
    function message(res, fallback) {
        if (res && res.responseJSON) { res = res.responseJSON; }
        return res && res.data && typeof res.data.message === 'string' ? res.data.message : fallback;
    }
    function toast(text, error, link) {
        var container = $('.altgenix-toast-container');
        if (!container.length) { container = $('<div class="altgenix-toast-container" aria-live="polite"></div>').appendTo('body'); }
        // The settings screen keeps a sticky save bar in the bottom-right corner,
        // which is exactly where these land. Lift them clear of it on that screen.
        container.toggleClass('altgenix-toast-container-raised', $('#altgenix-save-bar').length > 0);
        var item = $('<div class="altgenix-toast show"></div>').addClass(error ? 'altgenix-toast-error' : 'altgenix-toast-success');
        var body = $('<div class="altgenix-toast-body"></div>').appendTo(item);
        $('<span class="altgenix-toast-text"></span>').text(text).appendTo(body);
        // Somewhere to actually send people. The feedback endpoint answers a mail
        // failure with "the link is below", and there was no link.
        if (link && link.href) {
            $('<a class="altgenix-toast-link" target="_blank" rel="noopener"></a>')
                .attr('href', link.href).text(link.label || 'Open').appendTo(body);
        }
        $('<button type="button" class="altgenix-toast-close" aria-label="Dismiss notification">\u00d7</button>')
            .on('click', function () { window.clearTimeout(item.data('timer')); item.remove(); })
            .appendTo(item);
        item.appendTo(container);
        // Errors are usually the long ones worth reading, so they get longer, and
        // hovering holds any of them open instead of pulling it away mid-sentence.
        function schedule() { item.data('timer', window.setTimeout(function () { item.remove(); }, error ? 14000 : 7000)); }
        item.on('mouseenter', function () { window.clearTimeout(item.data('timer')); }).on('mouseleave', schedule);
        schedule();
    }
    // jQuery hands a failed request three arguments; only the first was being read,
    // so a request that simply ran out of time was reported as an expired login and
    // sent people off to sign in again for nothing.
    function failMessage(xhr, textStatus, fallback) {
        if (textStatus === 'timeout') {
            return 'The request took too long and was cut off. The image may still have been processed \u2014 refresh the list before retrying.';
        }
        if (xhr && xhr.status === 403) {
            // The plugin's own 403s explain themselves ("You are not allowed to edit
            // this image"); only a bare nonce failure means the session is stale.
            return message(xhr, '') || 'Your session has expired or the permission was withdrawn. Reload this page and sign in again.';
        }
        if (xhr && xhr.status === 0) {
            return 'The connection to this site dropped before the request finished.';
        }
        return message(xhr, fallback);
    }
    // rename_copy() reports what happened to every file it touched. All of it was
    // being returned and then thrown away, so a rename that half-failed read as a
    // clean success.
    function processToastText(res) {
        var data = (res && res.data) || {};
        var parts = [data.message || 'Image updated.'];
        if (data.rename_message) { parts.push(data.rename_message); }
        if (data.delete_failures) {
            parts.push(data.delete_failures + ' old file' + (data.delete_failures === 1 ? '' : 's') + ' could not be deleted.');
        } else if (data.deleted_old_files) {
            parts.push(data.deleted_old_files + ' old file' + (data.deleted_old_files === 1 ? '' : 's') + ' deleted.');
        }
        return parts.join(' ');
    }
    function ensureModal() {
        if ($('#altgenix-modal').length) { return; }
        $('body').append(
            '<div id="altgenix-modal" class="altgenix-modal-overlay" aria-hidden="true">' +
                '<div class="altgenix-modal-box"><h3 id="altgenix-modal-title">Confirm</h3><p id="altgenix-modal-text">Proceed?</p>' +
                    '<div id="altgenix-modal-extra" class="altgenix-modal-extra" style="display:none;">' +
                        '<div id="altgenix-field-options" class="altgenix-field-options" style="display:none;">' +
                            '<div class="altgenix-field-options-title">Choose what to regenerate</div>' +
                            '<div class="altgenix-field-options-grid">' +
                                '<label class="altgenix-field-option" data-field="alt"><input type="checkbox" id="altgenix-field-alt" value="alt"><span>Alt Text</span></label>' +
                                '<label class="altgenix-field-option" data-field="title"><input type="checkbox" id="altgenix-field-title" value="title"><span>Title</span></label>' +
                                '<label class="altgenix-field-option" data-field="caption"><input type="checkbox" id="altgenix-field-caption" value="caption"><span>Caption</span></label>' +
                                '<label class="altgenix-field-option" data-field="description"><input type="checkbox" id="altgenix-field-description" value="description"><span>Description</span></label>' +
                                '<label class="altgenix-field-option" data-field="filename" style="display:none;"><input type="checkbox" id="altgenix-field-filename" value="filename"><span>File Name</span></label>' +
                            '</div>' +
                        '</div>' +
                        '<label id="altgenix-delete-old-option" class="altgenix-delete-old-option" for="altgenix-delete-old-files" style="display:none;">' +
                            '<input type="checkbox" id="altgenix-delete-old-files" value="1">' +
                            '<span><strong>Delete old files after successful rename</strong><small>Old image URLs may stop working if they are used in existing posts, builders, caches, or external links.</small></span>' +
                        '</label>' +
                    '</div>' +
                    '<div class="altgenix-modal-actions"><button id="altgenix-modal-cancel" class="altgenix-btn-outline">Cancel</button><button id="altgenix-modal-confirm" class="altgenix-btn-primary">Yes</button></div>' +
                '</div>' +
            '</div>'
        );
    }
    function buttonFieldDefaults(button) {
        var $button = $(button);
        return {
            alt: $button.attr('data-field-alt-default') === '1',
            title: $button.attr('data-field-title-default') === '1',
            caption: $button.attr('data-field-caption-default') === '1',
            description: $button.attr('data-field-description-default') === '1',
            filename: $button.attr('data-field-filename-default') === '1'
        };
    }
    function modal(title, text, callback, options) {
        options = options || {};
        ensureModal();
        var previousFocus = document.activeElement;
        $('#altgenix-modal-title').text(title);
        $('#altgenix-modal-text').text(text).css('white-space', 'pre-line');
        var $extra = $('#altgenix-modal-extra');
        var $fieldsWrap = $('#altgenix-field-options');
        var $fieldOptions = $fieldsWrap.find('.altgenix-field-option');
        var $deleteOld = $('#altgenix-delete-old-files');
        var $deleteOldOption = $('#altgenix-delete-old-option');
        var fieldDefaults = $.extend({ alt: false, title: false, caption: false, description: false, filename: false }, options.fieldsDefault || {});
        function selectedFields() {
            var fields = {};
            $fieldOptions.each(function () {
                var $option = $(this), field = $option.attr('data-field');
                fields[field] = $option.is(':visible') && $option.find('input').is(':checked');
            });
            return fields;
        }
        function refreshExtraState() {
            var fields = selectedFields();
            var filenameSelected = !!fields.filename;
            var allowDelete = !!options.showDeleteOld && (!options.showFields || filenameSelected);
            $deleteOldOption.toggle(allowDelete);
            if (!allowDelete) { $deleteOld.prop('checked', false); }
            var hasField = false;
            if (options.showFields) {
                $.each(fields, function (name, enabled) {
                    if (name !== 'filename' || options.showFilenameField) {
                        if (enabled) { hasField = true; return false; }
                    }
                });
            } else {
                hasField = true;
            }
            $('#altgenix-modal-confirm').prop('disabled', !!callback && !hasField);
        }
        $fieldOptions.each(function () {
            var $option = $(this), field = $option.attr('data-field');
            var visible = !!options.showFields && (field !== 'filename' || !!options.showFilenameField);
            $option.toggle(visible).toggleClass('is-disabled', !visible);
            $option.find('input').prop('checked', !!fieldDefaults[field]);
        });
        $deleteOld.prop('checked', false);
        $fieldsWrap.toggle(!!options.showFields);
        $extra.toggle(!!options.showFields || !!options.showDeleteOld);
        $fieldOptions.find('input').off('change.altgenixModal').on('change.altgenixModal', refreshExtraState);
        function close() {
            $('#altgenix-modal').removeClass('show').attr('aria-hidden', 'true').off('click.altgenixBackdrop');
            $(document).off('keydown.altgenixModal');
            $fieldOptions.find('input').off('change.altgenixModal');
            if (previousFocus && previousFocus.focus) { previousFocus.focus(); }
        }
        $('#altgenix-modal-cancel').text(callback ? 'Cancel' : 'Close').off('click').on('click', close);
        $('#altgenix-modal-confirm')
            .text(options.confirmText || 'Yes')
            .toggleClass('altgenix-btn-danger', !!options.danger)
            .toggleClass('altgenix-btn-primary', !options.danger)
            .prop('disabled', false)
            .toggle(!!callback).off('click').on('click', function () {
            var modalData = {
                fields: selectedFields(),
                deleteOld: $deleteOld.length && $deleteOld.is(':checked') && (!options.showFields || selectedFields().filename)
            };
            close();
            if (callback) { callback(modalData); }
        });
        refreshExtraState();
        $('#altgenix-modal').addClass('show').attr({ role: 'dialog', 'aria-modal': 'true', 'aria-hidden': 'false', 'aria-labelledby': 'altgenix-modal-title' });
        // Clicking the dimmed area is the same as Cancel. Nothing here is
        // destructive on its own, so this cannot lose work.
        $('#altgenix-modal').off('click.altgenixBackdrop').on('click.altgenixBackdrop', function (event) {
            if (event.target === this) { close(); }
        });
        $('#altgenix-modal-cancel').trigger('focus');
        $(document).off('keydown.altgenixModal').on('keydown.altgenixModal', function (event) {
            if (event.key === 'Escape') { close(); }
            if (event.key === 'Tab') {
                event.preventDefault();
                var focusable = $('#altgenix-modal').find('button:visible, input:visible').filter(':not(:disabled)');
                var current = focusable.index(document.activeElement);
                if (event.shiftKey) { current = current <= 0 ? focusable.length - 1 : current - 1; }
                else { current = current >= focusable.length - 1 ? 0 : current + 1; }
                focusable.eq(current).trigger('focus');
            }
        });
    }
    // These only ever opened on :hover, which means a keyboard user and anyone on a
    // tablet could not read a single one of them.
    $('.altgenix-tip').each(function () {
        var $tip = $(this);
        var description = $.trim($tip.find('.altgenix-tip-content').text());
        $tip.attr({ tabindex: '0', role: 'button', 'aria-label': description ? 'Help: ' + description : 'More information' });
    });
    $(document).on('keydown', '.altgenix-tip', function (event) {
        if (event.key === 'Escape') { $(this).trigger('blur'); }
    });
    // "Unsaved" means the form differs from what was last saved, not "something was
    // touched". A plain flag stayed set after a change was undone — switching the
    // provider just to remove a key, then back again, still blocked leaving the page.
    var savedSnapshot = null;
    function formSnapshot() { return form.length ? form.serialize() : ''; }
    function setClean() { savedSnapshot = formSnapshot(); dirty = false; $('#altgenix-save-state').prop('hidden', true); }
    function markDirty() {
        revision++;
        dirty = savedSnapshot === null || formSnapshot() !== savedSnapshot;
        $('#altgenix-save-state').prop('hidden', !dirty);
    }

    if (config.bulk_url && !$('#altgenix-media-shortcut').length) {
        var shortcut = $('<a id="altgenix-media-shortcut" class="page-title-action"></a>').attr('href', config.bulk_url).text('Optimize with AltGenix');
        var anchor = $('.wrap .page-title-action').last();
        if (anchor.length) { anchor.after(shortcut); } else { $('.wrap h1').first().after(shortcut); }
    }
    // Accessible custom select UI. The native <select> remains the submitted source
    // of truth; the custom control mirrors it without exposing or duplicating secrets.
    var customSelectCounter = 0;
    function customSelectLabel($select) {
        var explicit = $.trim($select.attr('aria-label') || '');
        if (explicit) { return explicit; }
        var id = $select.attr('id');
        if (id) {
            var linked = $('label[for]').filter(function () { return $(this).attr('for') === id; }).first();
            if (linked.length) { return $.trim(linked.text()); }
        }
        var rowLabel = $select.closest('.altgenix-form-row').children('label').first();
        if (rowLabel.length) {
            var clone = rowLabel.clone();
            clone.children().remove();
            var text = $.trim(clone.text());
            if (text) { return text; }
        }
        if (id === 'altgenix-status-filter') { return 'Filter by AltGenix status'; }
        return 'Select an option';
    }
    function closeCustomSelect($wrapper, returnFocus) {
        if (!$wrapper || !$wrapper.length) { return; }
        $wrapper.removeClass('open');
        $wrapper.find('.altgenix-select-btn').attr('aria-expanded', 'false');
        if (returnFocus) { $wrapper.find('.altgenix-select-btn').trigger('focus'); }
    }
    function focusCustomOption($menu, index) {
        var options = $menu.children('.altgenix-select-option:not(.disabled)');
        if (!options.length) { return; }
        var safeIndex = Math.max(0, Math.min(index, options.length - 1));
        options.eq(safeIndex).trigger('focus');
    }
    function openCustomSelect($wrapper, direction) {
        $('.altgenix-custom-select.open').each(function () { closeCustomSelect($(this), false); });
        $wrapper.addClass('open');
        var $button = $wrapper.find('.altgenix-select-btn');
        var $menu = $wrapper.find('.altgenix-select-menu');
        $button.attr('aria-expanded', 'true');
        var options = $menu.children('.altgenix-select-option:not(.disabled)');
        var selectedIndex = options.index(options.filter('.selected').first());
        if (selectedIndex < 0) { selectedIndex = direction === 'up' ? options.length - 1 : 0; }
        focusCustomOption($menu, selectedIndex);
    }
    function refreshCustomSelect($select) {
        var $wrapper = $select.next('.altgenix-custom-select');
        if (!$wrapper.length) { return; }
        var $button = $wrapper.find('.altgenix-select-btn');
        var $value = $button.find('.altgenix-select-value');
        var $menu = $wrapper.find('.altgenix-select-menu').empty();
        var selectedText = '';
        $select.find('option').each(function (index) {
            var $option = $(this);
            var optionId = $menu.attr('id') + '-option-' + index;
            var $item = $('<li class="altgenix-select-option" role="option" tabindex="-1"></li>')
                .attr({ id: optionId, 'data-value': $option.val(), 'aria-selected': $option.is(':selected') ? 'true' : 'false' })
                .text($option.text());
            if ($option.prop('disabled')) { $item.addClass('disabled').attr('aria-disabled', 'true'); }
            if ($option.is(':selected')) { $item.addClass('selected'); selectedText = $option.text(); }
            $menu.append($item);
        });
        if (!selectedText) { selectedText = $select.find('option:selected').text() || $select.find('option').first().text() || ''; }
        $value.text(selectedText);
        $button.prop('disabled', $select.prop('disabled')).attr('aria-label', customSelectLabel($select) + (selectedText ? ': ' + selectedText : ''));
    }
    function initCustomSelect($select) {
        if ($select.data('altgenix-custom')) { refreshCustomSelect($select); return; }
        $select.data('altgenix-custom', true);
        var selectId = $select.attr('id');
        if (!selectId) { selectId = 'altgenix-select-' + (++customSelectCounter); $select.attr('id', selectId); }
        var menuId = selectId + '-custom-listbox';
        var $wrapper = $('<div class="altgenix-custom-select"></div>');
        var $button = $('<button type="button" class="altgenix-select-btn" aria-haspopup="listbox" aria-expanded="false"></button>')
            .attr('aria-controls', menuId)
            .append('<span class="altgenix-select-value"></span>')
            .append('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"></polyline></svg>');
        var $menu = $('<ul class="altgenix-select-menu" role="listbox"></ul>').attr('id', menuId);
        $wrapper.append($button, $menu);
        var inlineStyle = $select.attr('style') || '';
        var maxWidth = inlineStyle.match(/max-width\s*:\s*([^;]+)/i);
        if (maxWidth) { $wrapper.css('max-width', $.trim(maxWidth[1])); }
        $select.addClass('altgenix-select-native').attr({ 'aria-hidden': 'true', tabindex: '-1' }).after($wrapper);
        refreshCustomSelect($select);

        $button.on('click', function (event) {
            event.preventDefault(); event.stopPropagation();
            if ($button.prop('disabled')) { return; }
            if ($wrapper.hasClass('open')) { closeCustomSelect($wrapper, false); }
            else { openCustomSelect($wrapper, 'down'); }
        }).on('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault(); openCustomSelect($wrapper, event.key === 'ArrowUp' ? 'up' : 'down');
            } else if (event.key === 'Escape' && $wrapper.hasClass('open')) {
                event.preventDefault(); closeCustomSelect($wrapper, false);
            }
        });
        $menu.on('click', '.altgenix-select-option:not(.disabled)', function (event) {
            event.preventDefault(); event.stopPropagation();
            // Picking the option that is already selected is not a change.
            var picked = $(this).attr('data-value');
            if ($select.val() !== picked) { $select.val(picked).trigger('change'); }
            closeCustomSelect($wrapper, true);
        }).on('keydown', '.altgenix-select-option:not(.disabled)', function (event) {
            var $items = $menu.children('.altgenix-select-option:not(.disabled)');
            var index = $items.index(this);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                focusCustomOption($menu, index + (event.key === 'ArrowDown' ? 1 : -1));
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault(); focusCustomOption($menu, event.key === 'Home' ? 0 : $items.length - 1);
            } else if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault(); $(this).trigger('click');
            } else if (event.key === 'Escape') {
                event.preventDefault(); closeCustomSelect($wrapper, true);
            } else if (event.key === 'Tab') {
                closeCustomSelect($wrapper, false);
            }
        });
    }
    $('.altgenix-select').each(function () { initCustomSelect($(this)); });
    $(document).on('change.altgenixCustomSelect', '.altgenix-select', function () { refreshCustomSelect($(this)); });
    $(document).on('click.altgenixCustomSelect', function () { $('.altgenix-custom-select.open').each(function () { closeCustomSelect($(this), false); }); });
    // Reloading used to drop you back on General no matter which tab you were
    // working in. sessionStorage keeps it per browser tab and leaves the URL alone.
    var TAB_STORE = 'altgenixSettingsTab';
    function storeTab(id) { try { window.sessionStorage.setItem(TAB_STORE, id); } catch (e) {} }
    function readTab() { try { return window.sessionStorage.getItem(TAB_STORE); } catch (e) { return null; } }
    function activateTab(id) {
        var $link = $('.altgenix-tab-link[data-tab="' + id + '"]');
        if (!id || !$link.length || !$('#' + id).length) { return false; }
        $('.altgenix-tab-link').removeClass('active').attr({ 'aria-selected': 'false', tabindex: '-1' });
        $link.addClass('active').attr({ 'aria-selected': 'true', tabindex: '0' });
        $('.altgenix-tab-content').removeClass('active');
        $('#' + id).addClass('active');
        return true;
    }
    $('.altgenix-tab-link').on('click', function () {
        var id = $(this).data('tab');
        if (activateTab(id)) { storeTab(id); }
    }).on('keydown', function (event) {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' && event.key !== 'Home' && event.key !== 'End') { return; }
        event.preventDefault();
        var $links = $('.altgenix-tab-link');
        var index = $links.index(this);
        if (event.key === 'Home') { index = 0; }
        else if (event.key === 'End') { index = $links.length - 1; }
        else if (event.key === 'ArrowRight') { index = (index + 1) % $links.length; }
        else { index = (index - 1 + $links.length) % $links.length; }
        $links.eq(index).trigger('focus').trigger('click');
    });
    if ($('.altgenix-tab-link').length) {
        $('.altgenix-tab-link').attr('tabindex', '-1');
        if (!activateTab(readTab())) { activateTab($('.altgenix-tab-link').first().data('tab')); }
    }
    $('#altgenix-per-page').on('change', function () {
        var url = new URL(window.location.href);
        url.searchParams.set('altgenix_per_page', $(this).val());
        url.searchParams.set('paged', '1');
        window.location.href = url.toString();
    });
    // Applies on change, like the per-page control. A separate Filter button was
    // one more click for nothing.
    $('#altgenix-status-filter').on('change', function () {
        var url = new URL(window.location.href);
        url.searchParams.set('altgenix_status', $(this).val());
        url.searchParams.set('paged', '1');
        window.location.href = url.toString();
    });
    function selectedImageIds() {
        return $('.altgenix-row-select:checked').map(function () { return parseInt($(this).val(), 10); }).get().filter(function (id) { return !!id; });
    }
    function updateBulkSelectionUI() {
        var ids = selectedImageIds();
        var count = ids.length;
        var total = $('.altgenix-row-select').length;
        $('#altgenix-bulk-regenerate-btn').prop('disabled', !count || bulkBusy).find('.altgenix-bulk-btn-label').text('Regenerate selected (' + count + ')');
        $('#altgenix-mark-processed-btn').prop('disabled', !count || bulkBusy).find('.altgenix-bulk-btn-label').text('Mark as done (' + count + ')');
        $('#altgenix-select-all').prop('checked', !!count && count === total).prop('indeterminate', !!count && count < total);
    }
    $(document).on('change', '#altgenix-select-all', function () {
        $('.altgenix-row-select').prop('checked', $(this).is(':checked'));
        updateBulkSelectionUI();
    });
    $(document).on('change', '.altgenix-row-select', updateBulkSelectionUI);
    updateBulkSelectionUI();

    function trimWords(text, limit) {
        var words = $.trim(String(text || '')).split(/\s+/);
        return words.length <= limit ? words.join(' ') : words.slice(0, limit).join(' ') + '\u2026';
    }
    function showAlt($cell, alt) {
        if (alt) { $cell.text(trimWords(alt, 10)).attr('title', alt); }
        else { $cell.text('No alt text').removeAttr('title'); }
        $cell.css('cursor', '');
    }
    function updateRowAfterAction(id, response, forceProcessed) {
        var $row = $('tr[data-image-id="' + id + '"]');
        if (!$row.length) { return; }
        if (response && response.new_filename) { $row.find('.altgenix-filename-cell strong').text(response.new_filename); }
        var $badge = $row.find('.altgenix-status-badge');
        var $detail = $row.find('.altgenix-text-muted').first();
        var status = response && response.status;
        // 'fallback' is Filename mode finishing successfully; it marks the image
        // processed exactly like 'success' does, so the badge has to follow.
        if (forceProcessed || status === 'success' || status === 'fallback') {
            $badge.removeClass('altgenix-badge-warning altgenix-badge-danger').addClass('altgenix-badge-success').text('Processed');
            // The column shows alt text; a row that was Failed still shows its error until this runs.
            if (response && typeof response.item_alt === 'string') { showAlt($detail, response.item_alt); }
            if ($row.find('.altgenix-regenerate-btn').attr('data-ai-mode') === '0') { $row.find('.altgenix-regenerate-btn').remove(); }
        }
    }
    // "Process all remaining (N)" must not keep advertising work that is already done.
    // Asked from the server once an action finishes, because a failed regenerate can
    // put an image back in the queue and the browser cannot tell every case apart.
    function setRemaining(count) {
        var $button = $('#altgenix-auto-tag-btn');
        if (!$button.length) { return; }
        count = Math.max(0, parseInt(count, 10) || 0);
        $button.attr('data-count', count).find('.altgenix-bulk-btn-label')
            .text(count ? 'Process all remaining (' + count + ')' : 'Nothing left to process');
        if (!bulkBusy) { $button.prop('disabled', !count); }
    }
    function refreshRemaining() {
        if (!$('#altgenix-auto-tag-btn').length) { return; }
        request('altgenix_remaining_count').done(function (res) {
            if (res && res.success && res.data) { setRemaining(res.data.remaining); }
        });
    }
    function fieldListText(fields) {
        var names = [];
        if (fields.alt) { names.push('alt text'); }
        if (fields.title) { names.push('title'); }
        if (fields.caption) { names.push('caption'); }
        if (fields.description) { names.push('description'); }
        if (names.length < 2) { return names.join(''); }
        return names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1];
    }
    function plural(count, word) { return count + ' ' + word + (count === 1 ? '' : 's'); }
    function runSummary(done, failed, skipped) {
        var parts = [done + ' processed'];
        if (failed) { parts.push(failed + ' failed'); }
        if (skipped) { parts.push(skipped + ' skipped'); }
        return parts.join(', ') + '.' + (failed ? '\nFailed images stay in the list so you can retry them.' : '');
    }
    function regenerateText(aiMode) {
        return aiMode
            ? 'Pick the fields to rewrite. Text already in those fields will be replaced. Each image is one request to your AI provider.'
            : 'Pick the fields to rewrite from the filename. Text already in those fields will be replaced.';
    }
    // WordPress saves a field edited in the media modal when it loses focus — and
    // clicking our button is what takes the focus. If that save is still in flight
    // when the regenerate starts, it can land second and put the old text back
    // (seen in browser testing: the toast said success, the old alt text stayed).
    // Wait for open requests to finish first, but never longer than five seconds.
    function whenIdle(callback) {
        var waited = 0;
        (function check() {
            if (!$.active || waited >= 5000) { callback(); return; }
            waited += 100;
            window.setTimeout(check, 100);
        })();
    }
    // After a run, put the new text into whichever edit form is open. The classic
    // attachment screen otherwise keeps the text it loaded with, and pressing Update
    // writes that back over the run. The media modal re-renders itself only for
    // title and caption changes, so alt text and description would look unchanged.
    function applySavedFields(button, id, fields) {
        if (!fields || typeof fields !== 'object') { return; }
        if ($('body').hasClass('post-type-attachment') && String($('#post_ID').val()) === String(id)) {
            var targets = { title: '#title', alt: '#attachment_alt', caption: '#attachment_caption', description: '#attachment_content' };
            $.each(targets, function (key, selector) {
                if (typeof fields[key] === 'string') { $(selector).val(fields[key]); }
            });
            if (typeof fields.title === 'string') { $('#title-prompt-text').toggleClass('screen-reader-text', fields.title !== ''); }
        }
        var $scope = button.closest('.attachment-details');
        if (!$scope.length) { $scope = button.closest('.media-sidebar'); }
        if ($scope.length) {
            $.each(['title', 'alt', 'caption', 'description'], function (_, key) {
                if (typeof fields[key] === 'string') { $scope.find('[data-setting="' + key + '"]').find('input, textarea').val(fields[key]); }
            });
        }
    }
    // A failed image used to sit there still saying "Pending", which reads as
    // "nothing happened" rather than "this one needs another go".
    function markRowFailed(id, errorText) {
        var $row = $('tr[data-image-id="' + id + '"]');
        if (!$row.length) { return; }
        $row.find('.altgenix-status-badge')
            .removeClass('altgenix-badge-warning altgenix-badge-success')
            .addClass('altgenix-badge-danger').text('Failed');
        if (errorText) {
            $row.find('.altgenix-text-muted').first()
                .text(trimWords(errorText, 8)).attr('title', errorText).css('cursor', 'help');
        }
    }
    function buildProcessPayload(id, modalData) {
        var payload = { image_id: id, fields: [] };
        $.each(modalData.fields || {}, function (field, enabled) {
            if (enabled) { payload.fields.push(field); }
        });
        payload.delete_old = modalData && modalData.deleteOld ? 1 : 0;
        return payload;
    }
    function runSelectedRegeneration(button, ids, modalData) {
        if (!ids.length) { return; }
        bulkBusy = true;
        button.prop('disabled', true);
        // This loop waits ~900ms per image on top of the provider round trip, so
        // without a visible bar the screen just sits there looking hung.
        showProgress('Starting\u2026');
        var index = 0, done = 0, failed = 0, skipped = 0;
        function finish(error) {
            bulkBusy = false;
            button.prop('disabled', false);
            hideProgress();
            updateBulkSelectionUI();
            refreshRemaining();
            var summary = runSummary(done, failed, skipped);
            modal(error ? 'Run stopped' : 'Regenerate complete', error ? error + '\n\n' + summary : summary);
        }
        function next() {
            if (stopRequested) { finish('Stopped after ' + (done + failed + skipped) + ' of ' + ids.length + ' images.'); return; }
            if (index >= ids.length) { finish(); return; }
            var id = ids[index++];
            progress(done + failed + skipped, ids.length, 'Processing image ' + index + ' of ' + ids.length);
            request('altgenix_process_image', buildProcessPayload(id, modalData)).done(function (res) {
                if (res && res.success) {
                    if (res.data && res.data.status === 'skipped') { skipped++; }
                    else { done++; }
                    updateRowAfterAction(id, res.data || {}, false);
                } else {
                    failed++;
                    if (!(res && res.data && res.data.still_processed)) { markRowFailed(id, message(res, 'Processing failed.')); }
                }
            }).fail(function (xhr, textStatus) {
                failed++;
                markRowFailed(id, failMessage(xhr, textStatus, 'The request did not complete.'));
            }).always(function () { setTimeout(next, 900); });
        }
        next();
    }
    function dismiss(action, element) {
        request(action).done(function (res) {
            if (res && res.success) { element.hide(); }
            else { toast(message(res, 'Could not save dismissal.'), true); }
        }).fail(function (xhr) { toast(message(xhr, 'Could not save dismissal.'), true); });
    }
    $('#altgenix-dismiss-banner').on('click', function () { dismiss('altgenix_dismiss_banner', $('#altgenix-service-banner')); });
    $(document).on('click', '.altgenix-service-notice .notice-dismiss', function () { dismiss('altgenix_dismiss_services', $('.altgenix-service-notice')); });

    var providerInfo = {
        gemini: ['https://aistudio.google.com/app/apikey', 'Google AI Studio'],
        openai: ['https://platform.openai.com/api-keys', 'OpenAI'],
        claude: ['https://console.anthropic.com/settings/keys', 'Anthropic'],
        deepseek: ['https://platform.deepseek.com/api_keys', 'DeepSeek']
    };

    function readModelOptions() {
        var models = [];
        $('#altgenix_model option').each(function () {
            var value = $(this).val();
            if (value) { models.push(value); }
        });
        return models;
    }

    if (config.verified) {
        modelStates[savedProvider] = {
            usesSavedKey: true,
            keyDraft: '',
            models: readModelOptions(),
            selection: $('#altgenix_model').val() || ''
        };
    }

    function matchingModelState() {
        var state = modelStates[activeProvider];
        if (!state) { return null; }
        var typed = $.trim($('#altgenix_api_key').val());
        if (typed) {
            return !state.usesSavedKey && state.keyDraft === typed ? state : null;
        }
        return state.usesSavedKey && !!savedKeys[activeProvider] ? state : null;
    }

    function renderActiveModels() {
        var $select = $('#altgenix_model');
        if (!$select.length) { return null; }
        var state = matchingModelState();
        $select.empty();
        if (state && state.models && state.models.length) {
            // One "automatic" entry instead of two different things both called
            // "recommended". The list is cheapest first, and Automatic takes the top.
            $select.append($('<option></option>').val('').text('Automatic (cheapest available)'));
            $.each(state.models, function (index, id) {
                $select.append($('<option></option>').val(id).text(id));
            });
            var selection = state.models.indexOf(state.selection) >= 0 ? state.selection : '';
            state.selection = selection;
            $select.val(selection).prop('disabled', false);
            var typed = $.trim($('#altgenix_api_key').val()) !== '';
            $('#altgenix_verified_note')
                .removeClass('is-neutral is-error').addClass('is-success')
                .text('Key verified · ' + state.models.length + ' model' + (state.models.length === 1 ? '' : 's') + ' available.' + (typed ? ' Save Settings to use this key.' : ''));
        } else {
            $select.append($('<option></option>').val('').text('Verify API key to load models')).val('').prop('disabled', true);
            var hasKey = $.trim($('#altgenix_api_key').val()) !== '' || !!savedKeys[activeProvider];
            $('#altgenix_verified_note')
                .removeClass('is-success is-error').addClass('is-neutral')
                .text(hasKey ? 'Verify the API key to load available models.' : 'Enter an API key, then verify it to load available models.');
        }
        refreshCustomSelect($select);
        return state;
    }

    function syncConditions() {
        if (!form.length) { return; }
        var ai = $('#altgenix_mode').val() === 'ai';
        var $keyInput = $('#altgenix_api_key');
        var hasSavedKey = !!savedKeys[activeProvider];
        var typedKey = $.trim($keyInput.val()) !== '';
        var editingKey = !!editingKeys[activeProvider] || typedKey;

        $('#altgenix_provider_row, #altgenix_api_row, #altgenix_model_row, #altgenix_lang_row, #altgenix_rename_file_row, .altgenix-length-col, .altgenix-ai-only-row').toggle(ai);
        $('#altgenix_prompt_fallback_warning').toggle(!ai);
        $('#altgenix_custom_prompt').prop('disabled', false).prop('readonly', !ai);

        var state = renderActiveModels();
        var currentVerified = !!(ai && state && state.models && state.models.length);
        $('#altgenix_escalation_row').toggle(currentVerified);

        var $editKey = $('.altgenix-edit-icon[data-target="altgenix_api_key"]');
        var $eyeKey = $('.altgenix-toggle-eye[data-target="altgenix_api_key"]');
        var $keyStatus = $('#altgenix-api-key-status');
        var $removeKey = $('#altgenix-remove-key');
        if (hasSavedKey && !editingKey) {
            $keyInput.prop('readonly', true).attr('placeholder', '••••••••••••••••').addClass('altgenix-api-key-saved');
            $editKey.css('display', 'flex');
            $eyeKey.hide();
        } else {
            $keyInput.prop('readonly', false).attr('placeholder', hasSavedKey ? 'Enter a new API key to replace the saved key' : 'Enter your API key').removeClass('altgenix-api-key-saved');
            $editKey.hide();
            $eyeKey.css('display', 'flex');
        }

        if (hasSavedKey) {
            $keyStatus.show();
            var savedState = state && state.usesSavedKey;
            $keyStatus.find('.altgenix-api-key-status-icon').attr('class', 'dashicons dashicons-yes-alt altgenix-api-key-status-icon');
            $keyStatus.find('.altgenix-api-key-status-text').text(typedKey ? 'Saved key remains active until you save the replacement.' : (savedState ? 'API Key Saved & Verified' : 'API Key Saved'));
            $removeKey.show();
        } else {
            $keyStatus.hide();
            $removeKey.hide();
        }

        var info = providerInfo[activeProvider] || providerInfo.gemini;
        $('#altgenix_key_help_link').attr({ href: info[0], rel: 'noopener noreferrer' }).text('Get your ' + info[1] + ' API key');

        var canVerify = ai && ($.trim($keyInput.val()) !== '' || hasSavedKey) && !verifyingModels;
        $('#altgenix-verify-models').prop('disabled', !canVerify);
    }

    $('#altgenix_provider').on('change', function () {
        drafts[activeProvider] = $('#altgenix_api_key').val();
        editingKeys[activeProvider] = !!editingKeys[activeProvider] || !!drafts[activeProvider];
        activeProvider = $(this).val();
        $('#altgenix_api_key').val(drafts[activeProvider] || '').attr('type', 'password');
        syncConditions();
    });
    $('#altgenix_mode').on('change', syncConditions);
    $('#altgenix_api_key').on('input', syncConditions);
    $('#altgenix_model').on('change', function () {
        var state = matchingModelState();
        if (state) { state.selection = $(this).val() || ''; }
    });

    $('.altgenix-toggle-eye').on('click', function () {
        var input = $('#' + $(this).data('target'));
        if (input.prop('readonly')) { return; }
        var showing = input.attr('type') === 'password';
        input.attr('type', showing ? 'text' : 'password');
        $(this).find('.dashicons').toggleClass('dashicons-visibility', !showing).toggleClass('dashicons-hidden', showing);
    });
    $('.altgenix-edit-icon[data-target="altgenix_api_key"]').on('click', function (event) {
        event.preventDefault();
        editingKeys[activeProvider] = true;
        $('#altgenix_api_key').prop('readonly', false).attr('placeholder', 'Enter a new API key to replace the saved key').removeClass('altgenix-api-key-saved').trigger('focus');
        syncConditions();
    });

    $('#altgenix-verify-models').on('click', function () {
        if (verifyingModels) { return; }
        var provider = activeProvider;
        var typedKey = $.trim($('#altgenix_api_key').val());
        if (!typedKey && !savedKeys[provider]) { toast('Enter an API key before verifying models.', true); return; }

        var $button = $(this);
        var $icon = $button.find('.dashicons');
        var originalText = $button.find('span').last().text();
        verifyingModels = true;
        $button.prop('disabled', true).addClass('is-loading');
        $icon.addClass('altgenix-spin');
        $button.find('span').last().text('Verifying...');

        request('altgenix_verify_models', { provider: provider, api_key: typedKey }).done(function (res) {
            if (!res || !res.success || !res.data || !Array.isArray(res.data.valid_models)) {
                $('#altgenix_verified_note').removeClass('is-success is-neutral').addClass('is-error').text(message(res, 'The API key could not be verified.'));
                toast(message(res, 'The API key could not be verified.'), true);
                return;
            }
            var previous = modelStates[provider];
            var previousSelection = previous ? previous.selection : ($('#altgenix_model').val() || '');
            modelStates[provider] = {
                usesSavedKey: typedKey === '',
                keyDraft: typedKey,
                models: res.data.valid_models.slice(0),
                selection: res.data.valid_models.indexOf(previousSelection) >= 0 ? previousSelection : ''
            };
            syncConditions();
            toast('API key verified and model list refreshed.');
        }).fail(function (xhr) {
            var text = message(xhr, 'The API key could not be verified.');
            $('#altgenix_verified_note').removeClass('is-success is-neutral').addClass('is-error').text(text);
            toast(text, true);
        }).always(function () {
            verifyingModels = false;
            $button.removeClass('is-loading');
            $icon.removeClass('altgenix-spin');
            $button.find('span').last().text(originalText);
            var canVerifyNow = $('#altgenix_mode').val() === 'ai' && ($.trim($('#altgenix_api_key').val()) !== '' || !!savedKeys[activeProvider]);
            $button.prop('disabled', !canVerifyNow);
        });
    });

    $('#altgenix-remove-key').on('click', function () {
        if (!savedKeys[activeProvider]) { return; }
        var provider = activeProvider;
        var providerLabel = $('#altgenix_provider option:selected').text() || 'this provider';
        var dirtyBefore = dirty;
        modal(
            'Remove saved API key?',
            'This will permanently remove the saved ' + providerLabel + ' API key. If it is the active provider, AI generation will switch to Filename mode. Any unsaved replacement typed for this provider will also be cleared.',
            function () {
                var $button = $('#altgenix-remove-key').prop('disabled', true).text('Removing...');
                request('altgenix_remove_provider_key', { provider: provider }).done(function (res) {
                    if (!res || !res.success || !res.data) { toast(message(res, 'Could not remove the saved API key.'), true); return; }
                    savedKeys = $.extend({}, res.data.saved_keys || {});
                    delete drafts[provider];
                    delete editingKeys[provider];
                    delete modelStates[provider];
                    if (activeProvider === provider) { $('#altgenix_api_key').val('').attr('type', 'password'); }
                    if (!dirtyBefore && res.data.switched_to_fallback) {
                        $('#altgenix_mode').val('fallback');
                        refreshCustomSelect($('#altgenix_mode'));
                    }
                    syncConditions();
                    // The server's saved state just changed underneath the form.
                    if (!dirtyBefore) { setClean(); } else { markDirty(); }
                    toast(res.data.message || 'Saved API key removed.');
                }).fail(function (xhr) {
                    toast(message(xhr, 'Could not remove the saved API key.'), true);
                }).always(function () {
                    $button.prop('disabled', false).text('Remove Key');
                    syncConditions();
                });
            },
            { confirmText: 'Remove Key', danger: true }
        );
    });

    syncConditions();
    if (form.length) {
        setClean();
        form.on('input change', 'input, textarea, select', markDirty);
        $(document).on('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key && event.key.toLowerCase() === 's') { event.preventDefault(); form.trigger('submit'); }
        });
        form.on('submit', function (event) {
            event.preventDefault();
            if (saving) { return; }
            var key = $('#altgenix_api_key').val().trim();
            if ($('#altgenix_mode').val() === 'ai' && !key && !savedKeys[activeProvider]) {
                toast('Enter an API key before enabling AI.', true); markDirty(); return;
            }
            var sentRevision = revision;
            var sentSnapshot = form.serialize();
            var data = sentSnapshot + '&action=altgenix_save_settings';
            var button = form.find('[type="submit"]');
            var label = button.text();
            saving = true; button.prop('disabled', true).text('Saving...');
            $.ajax({ url: config.url, type: 'POST', dataType: 'json', data: data, timeout: 40000 }).done(function (res) {
                if (!res || !res.success || !res.data || !res.data.saved) { toast(message(res, 'Settings were not saved.'), true); markDirty(); return; }
                var state = res.data;
                savedKeys = $.extend({}, state.saved_keys || {});
                savedProvider = state.settings.provider;
                modelStates[savedProvider] = {
                    usesSavedKey: true,
                    keyDraft: '',
                    models: (state.valid_models || []).slice(0),
                    selection: state.settings.model || ''
                };
                if (revision === sentRevision) {
                    $.each(state.settings, function (name, value) {
                        var field = form.find('[name="altgenix_settings[' + name + ']"]');
                        if (field.is(':checkbox')) { field.prop('checked', value === 1); } else { field.val(value); }
                    });
                    activeProvider = savedProvider;
                    delete drafts[activeProvider]; delete editingKeys[activeProvider];
                    $('#altgenix_api_key').val('').attr('type', 'password');
                    $('.altgenix-select').each(function () { refreshCustomSelect($(this)); });
                    $('.altgenix-regenerate-btn').attr('data-rename-default', state.settings.rename_file === 1 ? '1' : '0');
                }
                syncConditions();
                if (revision === sentRevision) { setClean(); }
                else {
                    // Edits made while this save was in flight are still unsaved.
                    savedSnapshot = sentSnapshot;
                    dirty = formSnapshot() !== savedSnapshot;
                    $('#altgenix-save-state').prop('hidden', !dirty);
                }
                toast(revision === sentRevision ? state.message : 'Earlier changes saved. You still have unsaved edits.');
            }).fail(function (xhr) { toast(message(xhr, 'Settings could not be saved. Your edits are still here.'), true); markDirty(); })
                .always(function () { saving = false; button.prop('disabled', false).text(label); });
        });
    }
    $(window).on('beforeunload', function (event) {
        if (dirty || saving || bulkBusy) { event.preventDefault(); event.originalEvent.returnValue = ''; return ''; }
    });

    // Uploads were processed in complete silence: nothing on screen said the
    // plugin was working, so alt text simply appeared later, or didn't.
    function autoStatus(count) {
        var $chip = $('#altgenix-auto-status');
        if (!count) { $chip.remove(); return; }
        if (!$chip.length) {
            $chip = $('<div id="altgenix-auto-status" class="altgenix-auto-status" role="status" aria-live="polite"></div>').appendTo('body');
        }
        $chip.text('AltGenix: processing ' + count + ' new image' + (count === 1 ? '' : 's') + '\u2026');
    }
    var autoIdleRuns = 0, autoTimer = null;
    function scheduleAutoQueue() {
        if (autoStopped || !config.auto_queue) { return; }
        window.clearTimeout(autoTimer);
        // 15s while there is work. Once the queue has come back empty a few times it
        // drops to two minutes — this poll runs for as long as a Media Library tab
        // stays open, and at a flat 15s that is 240 admin-ajax hits an hour for
        // nothing on every open tab.
        autoTimer = window.setTimeout(autoQueue, autoIdleRuns >= 4 ? 120000 : 15000);
    }
    function autoQueue() {
        if (!config.auto_queue || autoStopped) { return; }
        if (autoBusy || bulkBusy || document.hidden) { scheduleAutoQueue(); return; }
        autoBusy = true;
        request('altgenix_get_auto_queue').done(function (res) {
            var ids = res && res.success && Array.isArray(res.data) ? res.data : [];
            if (!res || !res.success) { autoStopped = true; }
            autoIdleRuns = ids.length ? 0 : autoIdleRuns + 1;
            function next() {
                if (!ids.length || bulkBusy) { autoBusy = false; autoStatus(0); scheduleAutoQueue(); return; }
                autoStatus(ids.length);
                request('altgenix_process_auto', { image_id: ids.shift() }).always(function () { setTimeout(next, 1200); });
            }
            next();
        }).fail(function (xhr) {
            if (xhr.status === 403) { autoStopped = true; }
            autoBusy = false; autoIdleRuns++; autoStatus(0); scheduleAutoQueue();
        });
    }
    if (config.auto_queue) {
        // Coming back to a tab that was hidden should feel immediate rather than
        // waiting out whatever backoff had built up while nobody was looking.
        $(document).on('visibilitychange', function () { if (!document.hidden) { autoIdleRuns = 0; autoQueue(); } });
        autoQueue();
    }

    function progress(done, total, text) {
        var percent = total ? Math.min(100, Math.round(done / total * 100)) : 0;
        $('#altgenix-progress-fill').css('width', percent + '%');
        $('#altgenix-progress-percentage').text(percent + '%');
        $('#altgenix-progress-status').text(text);
    }
    // Set by the Stop button and read between images. An in-flight request is
    // always allowed to finish — aborting one mid-write is how an image ends up
    // paid for at the provider and unrecorded here.
    var stopRequested = false;
    function showProgress(text) {
        stopRequested = false;
        $('#altgenix-stop-run').prop('disabled', false).text('Stop');
        $('#altgenix-progress-container').show();
        progress(0, 1, text || 'Starting\u2026');
    }
    function hideProgress() {
        $('#altgenix-progress-container').hide();
        progress(0, 1, '');
    }
    $(document).on('click', '#altgenix-stop-run', function () {
        if (!bulkBusy) { return; }
        stopRequested = true;
        $(this).prop('disabled', true).text('Stopping\u2026');
    });
    function runBulk(button) {
        if (bulkBusy) { return; }
        bulkBusy = true; button.prop('disabled', true);
        showProgress('Reading the queue\u2026');
        var cursor = 0, done = 0, failed = 0, skipped = 0, total = 0;
        function finish(error) {
            bulkBusy = false; button.prop('disabled', false);
            hideProgress();
            refreshRemaining();
            var summary = runSummary(done, failed, skipped);
            if ($('.altgenix-pagination').length) { summary += '\nReload the page to see the results on other pages.'; }
            modal(error ? 'Run stopped' : 'All done', (error ? error + '\n\n' : '') + summary);
        }
        function batch() {
            if (stopRequested) { finish('Stopped after ' + (done + failed + skipped) + ' images.'); return; }
            request('altgenix_get_pending', { after_id: cursor }).done(function (res) {
                if (!res || !res.success || !res.data || !Array.isArray(res.data.ids)) { finish(message(res, 'Could not fetch pending images.')); return; }
                var ids = res.data.ids;
                total = Math.max(total, done + failed + skipped + (Number(res.data.remaining) || 0));
                if (!ids.length) { finish(); return; }
                if (!res.data.next_cursor || res.data.next_cursor <= cursor) { finish('The queue did not advance. Please refresh and retry.'); return; }
                cursor = res.data.next_cursor;
                function next() {
                    if (stopRequested) { finish('Stopped after ' + (done + failed + skipped) + ' of ' + total + ' images.'); return; }
                    if (!ids.length) { batch(); return; }
                    progress(done + failed + skipped, total, 'Processing image ' + (done + failed + skipped + 1) + ' of ' + total);
                    var currentId = ids.shift();
                    request('altgenix_process_image', { image_id: currentId }).done(function (result) {
                        if (result && result.success) {
                            if (result.data && result.data.status === 'skipped') { skipped++; } else { done++; }
                            updateRowAfterAction(currentId, result.data || {}, false);
                        } else {
                            failed++;
                            markRowFailed(currentId, message(result, 'Processing failed.'));
                        }
                    }).fail(function (xhr, textStatus) {
                        failed++;
                        markRowFailed(currentId, failMessage(xhr, textStatus, 'The request did not complete.'));
                    }).always(function () { setTimeout(next, 1200); });
                }
                next();
            }).fail(function (xhr, textStatus) { finish(failMessage(xhr, textStatus, 'Could not read the queue.')); });
        }
        batch();
    }
    $('#altgenix-bulk-regenerate-btn').on('click', function () {
        var button = $(this), ids = selectedImageIds();
        if (bulkBusy || !ids.length) { return; }
        modal(
            'Regenerate ' + plural(ids.length, 'selected image') + '?',
            regenerateText(button.attr('data-ai-mode') === '1'),
            function (modalData) { runSelectedRegeneration(button, ids, modalData); },
            {
                showFields: true,
                showFilenameField: button.attr('data-ai-mode') === '1' && button.attr('data-can-rename') === '1',
                showDeleteOld: button.attr('data-ai-mode') === '1' && button.attr('data-can-rename') === '1',
                fieldsDefault: buttonFieldDefaults(button),
                confirmText: 'Regenerate'
            }
        );
    });
    $('#altgenix-auto-tag-btn').on('click', function () {
        var button = $(this);
        if (bulkBusy || button.prop('disabled')) { return; }
        var count = parseInt(button.attr('data-count'), 10) || 0;
        var fields = fieldListText(buttonFieldDefaults(button));
        if (!fields) {
            modal('Nothing to generate', 'Every field is switched off. Turn on at least one field under Settings > Generation Control.');
            return;
        }
        // Say exactly what will be overwritten and on how many images, in the words
        // the rest of WordPress uses, before anything is spent.
        var text = 'AltGenix will write the ' + fields + ' for ' + plural(count, 'image') +
            ' that ' + (count === 1 ? 'is' : 'are') + ' pending or failed. Text already in those fields will be replaced.\n\n' +
            (button.attr('data-ai-mode') === '1'
                ? 'Each image is one request to your AI provider.'
                : 'The text comes from each filename. No API is used.') +
            '\n\nWrote some of this text yourself? Tick those images and use “Mark as done” first.';
        modal('Process all remaining images?', text, function () { runBulk(button); }, { confirmText: 'Start' });
    });
    $('#altgenix-mark-processed-btn').on('click', function () {
        var button = $(this), ids = selectedImageIds();
        if (bulkBusy || !ids.length) { return; }
        modal('Mark ' + plural(ids.length, 'image') + ' as done?', 'Their text stays exactly as it is, and they stop being listed as pending. Use this for images you have already written yourself, or ones that keep failing. Images still being uploaded are skipped.', function () {
            bulkBusy = true;
            button.prop('disabled', true);
            request('altgenix_mark_selected_processed', { image_ids: ids }).done(function (res) {
                if (!res || !res.success || !res.data) { modal('Action stopped', message(res, 'Could not update statuses.')); return; }
                var alts = res.data.updated_alts || {};
                $.each((res.data.updated_ids || []), function (_, id) {
                    updateRowAfterAction(id, { item_alt: typeof alts[id] === 'string' ? alts[id] : undefined }, true);
                    $('tr[data-image-id="' + id + '"]').find('.altgenix-row-select').prop('checked', false);
                });
                updateBulkSelectionUI();
                toast(plural(res.data.count, 'image') + ' marked as done.' + (res.data.skipped ? ' ' + res.data.skipped + ' skipped (already done or still uploading).' : ''));
            }).fail(function (xhr) { modal('Action stopped', message(xhr, 'Connection failed.')); })
              .always(function () { bulkBusy = false; button.prop('disabled', false); updateBulkSelectionUI(); refreshRemaining(); });
        }, { confirmText: 'Mark as done' });
    });
    $(document).on('click', '.altgenix-regenerate-btn, .altgenix-rename-btn', function () {
        var button = $(this), id = button.data('id'), renameRetry = button.hasClass('altgenix-rename-btn');
        var aiMode = button.attr('data-ai-mode') === '1';
        var canRename = button.attr('data-can-rename') === '1';
        if (button.prop('disabled') || bulkBusy) { return; }
        function run(modalData) {
            var icon = button.find('.dashicons'), original = icon.attr('class');
            var payload = renameRetry
                ? { attachment_id: id, delete_old: modalData && modalData.deleteOld ? 1 : 0 }
                : buildProcessPayload(id, modalData);
            button.prop('disabled', true); icon.attr('class', 'dashicons dashicons-update altgenix-spin');
            whenIdle(function () { request(renameRetry ? 'altgenix_rename_existing' : 'altgenix_process_image', payload).done(function (res) {
                if (!res || !res.success) {
                    var errorText = message(res, 'The action failed.');
                    // An image that already had good text keeps it; only this attempt failed.
                    if (res && res.data && res.data.still_processed) { errorText += '\n\nThe image keeps its previous text and stays processed.'; }
                    else { markRowFailed(id, errorText); }
                    modal('Processing error', errorText);
                    return;
                }
                toast(processToastText(res));
                applySavedFields(button, id, res.data && res.data.fields);
                if (window.wp && wp.media && wp.media.attachment) { wp.media.attachment(id).fetch(); }
                updateRowAfterAction(id, res.data || {}, false);
                if (renameRetry) { button.remove(); }
            }).fail(function (xhr, textStatus) {
                var text = failMessage(xhr, textStatus, 'The action did not complete.');
                markRowFailed(id, text);
                modal('Processing error', text);
            })
                .always(function () { button.prop('disabled', false); icon.attr('class', original); updateBulkSelectionUI(); refreshRemaining(); }); });
        }
        modal(
            renameRetry ? 'Retry this filename?' : 'Regenerate this image?',
            renameRetry
                ? 'Applies the filename the AI already wrote for this image. No new AI request is made.'
                : regenerateText(aiMode),
            run,
            renameRetry
                ? { showDeleteOld: true, confirmText: 'Retry rename' }
                : { showFields: true, showFilenameField: aiMode && canRename, showDeleteOld: aiMode && canRename, fieldsDefault: buttonFieldDefaults(button), confirmText: 'Regenerate' }
        );
    });

    $('.altgenix-star-rating span').attr({ role: 'button', tabindex: '0' }).each(function () { $(this).attr('aria-label', 'Rate ' + $(this).data('rating') + ' stars'); })
        .on('click keydown', function (event) {
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') { return; }
            event.preventDefault();
            var value = $(this).data('rating');
            $('.altgenix-star-rating').data('selected', value);
            $('.altgenix-star-rating span').each(function () { $(this).toggleClass('dashicons-star-filled', $(this).data('rating') <= value).toggleClass('dashicons-star-empty', $(this).data('rating') > value); });
            // These two blocks are alternatives, not a pair. Showing both asked a
            // five-star rater to "tell us what went wrong".
            var wantsFix = value <= 3;
            $('#altgenix-rating-feedback').show();
            $('.altgenix-rating-low').toggle(wantsFix);
            $('.altgenix-rating-high').toggle(!wantsFix);
        });
    $('#altgenix-submit-feedback').on('click', function () {
        var button = $(this), text = $('#altgenix-feedback-text').val().trim();
        if (!text) { $('#altgenix-feedback-text').trigger('focus'); return; }
        button.prop('disabled', true);
        request('altgenix_submit_feedback', { feedback: text, rating: $('.altgenix-star-rating').data('selected') || 3 }).done(function (res) {
            if (res && res.success) { toast('Feedback sent.'); $('#altgenix-feedback-text').val(''); }
            else {
                var support = res && res.data && res.data.support ? { href: res.data.support, label: 'Open the support forum' } : null;
                toast(message(res, 'Feedback was not sent. Your text is still here.'), true, support);
            }
        }).fail(function (xhr) { toast(message(xhr, 'Feedback was not sent. Please try the support forum.'), true); }).always(function () { button.prop('disabled', false); });
    });
    $('#altgenix-review-link').on('click', function () { request('altgenix_record_review', { rating: $('.altgenix-star-rating').data('selected') || 3 }); });
    $('#altgenix-rate-again').on('click', function () {
        $('#altgenix-already-rated').hide();
        $('#altgenix-rating-card').show();
        // Reopening kept the previous stars lit and both follow-up blocks open.
        $('.altgenix-star-rating').removeData('selected');
        $('.altgenix-star-rating span').addClass('dashicons-star-empty').removeClass('dashicons-star-filled');
        $('#altgenix-rating-feedback, .altgenix-rating-low, .altgenix-rating-high').hide();
    });
});
