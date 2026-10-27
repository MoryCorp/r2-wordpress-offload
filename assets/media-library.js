/**
 * R2 status badges on attachment thumbnails in the Media Library grid view.
 */
(function ($) {
    'use strict';

    if (typeof numosR2Grid === 'undefined' || !numosR2Grid.statuses) {
        return;
    }

    var statuses = numosR2Grid.statuses;

    function getBadge(id) {
        var data = statuses[id];
        if (!data || !data.s) {
            return null;
        }

        if (data.s === 'synced' && data.d) {
            return { label: 'Cloud', cls: 'numos-r2-grid-badge-r2only' };
        }
        if (data.s === 'synced') {
            return { label: 'Cloud', cls: 'numos-r2-grid-badge-synced' };
        }
        if (data.s === 'partial') {
            return { label: 'Cloud~', cls: 'numos-r2-grid-badge-partial' };
        }
        return null;
    }

    function applyBadges() {
        $('.attachment').each(function () {
            var $el = $(this);
            if ($el.find('.numos-r2-grid-badge').length) {
                return;
            }

            var id = $el.data('id');
            if (!id) {
                return;
            }

            var badge = getBadge(id);
            if (!badge) {
                return;
            }

            var $thumb = $el.find('.attachment-preview');
            if (!$thumb.length) {
                return;
            }

            $thumb.css('position', 'relative');
            $thumb.append(
                '<span class="numos-r2-grid-badge ' + badge.cls + '">' + badge.label + '</span>'
            );
        });
    }

    $(document).ready(function () {
        applyBadges();

        // The grid adds attachments as they load (Backbone views): re-apply on each change.
        if (typeof MutationObserver !== 'undefined') {
            var container = document.querySelector('.attachments');
            if (container) {
                var observer = new MutationObserver(function () {
                    applyBadges();
                });
                observer.observe(container, { childList: true });
            }
        }
    });
})(jQuery);
