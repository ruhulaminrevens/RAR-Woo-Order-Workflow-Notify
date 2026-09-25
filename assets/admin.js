/* RAR Woo Order Workflow & Notify — admin helpers (bulk print, media, colour, copy link). */
(function ($) {
    'use strict';

    var cfg = window.rarWowAdmin || {};

    // Bulk print: open the merged PDF in a new tab instead of reloading the list.
    $(document).on('click', '#doaction, #doaction2', function (e) {
        var $form = $(this).closest('form');
        var which = this.id === 'doaction2' ? '#bulk-action-selector-bottom' : '#bulk-action-selector-top';
        var action = $form.find(which).val();

        if (!cfg.printActions || !cfg.printActions[action]) {
            return;
        }

        var ids = $form.find('input[name="id[]"]:checked, input[name="post[]"]:checked').map(function () {
            return this.value;
        }).get().sort(function (a, b) { return a - b; });

        e.preventDefault();

        if (!ids.length) {
            window.alert(cfg.noSelection || 'Select orders first.');
            return;
        }

        var url = cfg.docUrl + '&type=' + encodeURIComponent(cfg.printActions[action]) +
            '&order_ids=' + encodeURIComponent(ids.join(',')) + '&_wpnonce=' + encodeURIComponent(cfg.nonce);

        window.open(url, '_blank', 'noopener');
    });

    // Logo picker.
    $(document).on('click', '.rar-wow-media-select', function (e) {
        e.preventDefault();

        var $wrap = $(this).closest('.rar-wow-media');
        var frame = wp.media({ title: 'Select shop logo', button: { text: 'Use this logo' }, multiple: false, library: { type: 'image' } });

        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            var src = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;

            $wrap.find('input[type=hidden]').val(att.id);
            $wrap.find('.rar-wow-media-preview').attr('src', src).show();
            $wrap.find('.rar-wow-media-remove').show();
        });

        frame.open();
    });

    $(document).on('click', '.rar-wow-media-remove', function (e) {
        e.preventDefault();

        var $wrap = $(this).closest('.rar-wow-media');
        $wrap.find('input[type=hidden]').val('');
        $wrap.find('.rar-wow-media-preview').attr('src', '').hide();
        $(this).hide();
    });

    // Copy customer invoice link.
    $(document).on('click', '.rar-wow-mb-copy', function (e) {
        e.preventDefault();

        var link = $(this).data('copy');
        var $el = $(this);

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(function () {
                $el.text('Link copied ✓');
            });
        } else {
            window.prompt('Copy the customer invoice link:', link);
        }
    });

    $(function () {
        if ($.fn.wpColorPicker) {
            $('.rar-wow-color').wpColorPicker();
        }
    });
})(jQuery);
