/**
 * PVModern v7.0 — Header RequireJS Widget
 * ==========================================
 * MEGA MENU: Removed entirely — pure CSS :hover handles it (see _extend.less).
 * UNDERLINE: Fires on mouseenter of each nav-item (mirrors CSS :hover perfectly).
 * MOBILE: Slide toggle.
 * CART: Real-time via customerData KO observable.
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'mage/cookies'
], function ($, customerData) {
    'use strict';

    return function (config, element) {
        var $root    = $(element);
        var $nav     = $root.find('#pv3-nav');
        var $ind     = $root.find('#pv3-nav-indicator');
        var $toggle  = $root.find('[data-role="menu-toggle"]');
        var $mobMenu = $root.find('[data-role="mobile-menu"]');

        /* ── Page context from PHP data-* attributes ──────────────────────── */
        var isCategoryPage = ($nav.data('cat-page') === 'true');
        var activeCat      = ($nav.data('active') || '').trim();
        var $activeLink    = activeCat
            ? $nav.find('a[data-cat="' + activeCat + '"]')
            : $();

        function getCookie(name) {
            var value = '; ' + document.cookie,
                parts = value.split('; ' + name + '=');

            if (parts.length === 2) {
                return decodeURIComponent(parts.pop().split(';').shift() || '');
            }

            return '';
        }

        function setCookie(name, value) {
            var date = new Date(),
                cookiesConfig = window.cookiesConfig || {},
                secure = cookiesConfig.secure ? '; secure' : '',
                sameSite = '; samesite=' + (cookiesConfig.samesite || 'lax');

            date.setTime(date.getTime() + 86400000);
            document.cookie = name + '=' + encodeURIComponent(value || '') + '; expires=' + date.toUTCString() + secure + '; path=/' + sameSite;
        }

        function generateFormKey() {
            var chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
                key = '',
                i;

            for (i = 0; i < 16; i += 1) {
                key += chars.charAt(Math.floor(Math.random() * chars.length));
            }

            return key;
        }

        function currentFormKey() {
            var key = '';

            if ($.mage && $.mage.cookies && typeof $.mage.cookies.get === 'function') {
                key = $.mage.cookies.get('form_key') || '';
            }
            key = key || getCookie('form_key');
            if (!key) {
                key = generateFormKey();
                setCookie('form_key', key);
            }

            return key;
        }

        function syncCartFormKey(form) {
            var key = currentFormKey();
            if (!key) {
                return;
            }
            $(form).find('input[name="form_key"]').val(key).attr('value', key);
        }

        $(document).on('submit.pvGlobalCartFormKey', 'form[action*="/checkout/cart/add"]', function () {
            syncCartFormKey(this);
        });

        /* ═══════════════════════════════════════════════════════════════════
           CARET ROTATION — mirrors CSS :hover (visual feedback only)
           The mega-menu itself is shown/hidden by pure CSS :hover.
           JS only rotates the caret SVG to match hover state.
        ═══════════════════════════════════════════════════════════════════ */
        $nav.find('.pv3-nav-item').each(function () {
            var $item  = $(this);
            var $caret = $item.find('.pv3-nav-caret');
            $item.on('mouseenter.pvCaret', function () { $caret.addClass('is-open'); });
            $item.on('mouseleave.pvCaret', function () { $caret.removeClass('is-open'); });
        });

        /* ═══════════════════════════════════════════════════════════════════
           SLIDING UNDERLINE v7.0 — strict state machine
           ─────────────────────────────────────────────────────────────────
           Rules (matching user spec):
           A) Hover any nav item  → indicator slides to that link instantly
           B) Leave entire nav    → (i)  Category page: snap back to activeCat
                                    (ii) Other page: hide indicator
           C) Page load (cat pg)  → indicator at activeCat
           D) Page load (other)   → indicator hidden
        ═══════════════════════════════════════════════════════════════════ */

        function getNavLeft() {
            return $nav[0] ? $nav[0].getBoundingClientRect().left : 0;
        }

        function moveIndicatorTo($link) {
            if (!$link || !$link.length) { return; }
            var rect   = $link[0].getBoundingClientRect();
            var padL   = parseInt($link.css('paddingLeft'),  10) || 0;
            var padR   = parseInt($link.css('paddingRight'), 10) || 0;
            var $caret = $link.find('.pv3-nav-caret');
            /* Subtract caret width from the text-only zone */
            var caret  = $caret.length ? ($caret[0].getBoundingClientRect().width + 3) : 0;
            var indL   = rect.left - getNavLeft() + padL;
            var indW   = Math.max(0, rect.width - padL - padR - caret);
            $ind.css({ left: indL + 'px', width: indW + 'px', opacity: 1 });
        }

        function hideIndicator()  { $ind.css('opacity', 0); }
        function snapToActive()   {
            if ($activeLink && $activeLink.length) {
                moveIndicatorTo($activeLink);
            } else {
                hideIndicator();
            }
        }

        /* Init */
        if (isCategoryPage && $activeLink.length) {
            $activeLink.addClass('is-active');
            setTimeout(snapToActive, 0);
        } else {
            hideIndicator();
        }

        /* Resize: recalculate */
        $(window).on('resize.pvNav', function () {
            if (isCategoryPage && $activeLink.length) { snapToActive(); }
        });

        /* Hover per nav-item (mirrors pure CSS :hover exactly) */
        $nav.find('.pv3-nav-item').each(function () {
            var $item = $(this);
            var $link = $item.find('> a.pv3-nav-link');
            $item.on('mouseenter.pvNav', function () { moveIndicatorTo($link); });
        });

        /* Deals link */
        $nav.find('a.pv3-nav-link.is-deals').on('mouseenter.pvNav', function () {
            moveIndicatorTo($(this));
        });

        /* Cursor leaves entire nav: snap or hide */
        $nav.on('mouseleave.pvNav', function () {
            if (isCategoryPage && $activeLink.length) {
                snapToActive();
            } else {
                hideIndicator();
            }
        });

        /* Click: update active state for SPA-style navigation */
        $nav.find('a[data-cat]').on('click.pvNav', function () {
            var catKey = $(this).data('cat');
            if (catKey && catKey !== 'deals') {
                activeCat      = catKey;
                isCategoryPage = true;
                $activeLink    = $(this);
                $nav.find('a.pv3-nav-link').removeClass('is-active');
                $activeLink.addClass('is-active');
                moveIndicatorTo($activeLink);
            }
        });

        /* ═══════════════════════════════════════════════════════════════════
           MOBILE MENU TOGGLE
        ═══════════════════════════════════════════════════════════════════ */
        if ($toggle.length && $mobMenu.length) {
            $toggle.on('click.pvMobile', function () {
                var isOpen = $toggle.attr('aria-expanded') === 'true';
                $toggle.attr('aria-expanded', isOpen ? 'false' : 'true')
                       .toggleClass('is-open', !isOpen);
                if (isOpen) {
                    $mobMenu.slideUp(200, function () { $mobMenu.attr('hidden', ''); });
                } else {
                    $mobMenu.removeAttr('hidden').hide().slideDown(200);
                }
            });
            $(document).on('click.pvMobile', function (e) {
                if (!$(e.target).closest('.pv3-header-shell').length) {
                    if ($toggle.attr('aria-expanded') === 'true') {
                        $toggle.attr('aria-expanded', 'false').removeClass('is-open');
                        $mobMenu.slideUp(160, function () { $mobMenu.attr('hidden', ''); });
                    }
                }
            });
        }

        /* ═══════════════════════════════════════════════════════════════════
           REAL-TIME CART BADGE (Magento customerData KO observable)
        ═══════════════════════════════════════════════════════════════════ */
        (function initCartBadge() {
            var $badge = $root.find('[data-cart-badge]');
            if (!$badge.length) return;

            function applyCount(count) {
                count = parseInt(count, 10) || 0;
                if (count > 0) { $badge.text(count).css('display', ''); }
                else           { $badge.text('').css('display', 'none'); }
            }

            var cartData = customerData.get('cart');

            // Read the snapshot the checkout widget froze when place_order ran.
            // While the customer is mid-payment at step 4, we ignore Magento's
            // (correctly-empty) cart section and display the snapshot count
            // instead — otherwise the header would flash to 0 the moment the
            // order is placed, while the customer is still waiting for their
            // bank transfer to confirm.
            function readFrozenSnapshotCount() {
                try {
                    var raw = window.sessionStorage.getItem('pvmodern_checkout_customer_flow');
                    if (!raw) return null;
                    var saved = JSON.parse(raw);
                    if (!saved || !saved.cartSnapshotLocked) return null;
                    if (saved.step && saved.step >= 5) return null; // payment done — let Magento's 0 through
                    var snap = saved.cartSnapshot || [];
                    return snap.reduce(function (s, i) { return s + (parseInt(i.qty, 10) || 1); }, 0);
                } catch (e) { return null; }
            }

            function refreshBadge(data) {
                var frozen = readFrozenSnapshotCount();
                if (frozen !== null) { applyCount(frozen); return; }
                /* Use the sum of qty across items so the badge shows the true
                   product quantity (e.g. 3 of one item + 2 of another → 5),
                   not the line-item count. Magento's summary_count is the
                   number of distinct lines, not the total qty. */
                var count = 0;
                if (data && data.items && data.items.length) {
                    count = data.items.reduce(function (s, i) {
                        return s + (parseFloat(i.qty) || 0);
                    }, 0);
                } else if (data && typeof data.summary_qty !== 'undefined') {
                    count = parseFloat(data.summary_qty) || 0;
                } else if (data && typeof data.qty_count !== 'undefined') {
                    count = parseFloat(data.qty_count) || 0;
                } else if (data && typeof data.summary_count !== 'undefined') {
                    count = parseFloat(data.summary_count) || 0;
                }
                applyCount(Math.round(count));
            }
            cartData.subscribe(refreshBadge);
            refreshBadge(cartData());

            /* Immediate update from checkout widget — honored regardless of
               freeze state because pvCartCountChanged is fired explicitly
               (e.g. at step 5 when we DO want to clear the badge). */
            $(window).on('pvCartCountChanged', function (e, count) { applyCount(count); });
        }());

        (function initAuthAwareActions() {
            var customer = customerData.get('customer');

            function refreshAuth(data) {
                var isLoggedIn = !!(data && (data.firstname || data.fullname || data.email));
                $root.find('[data-auth-signout]').prop('hidden', !isLoggedIn);
            }

            customer.subscribe(refreshAuth);
            refreshAuth(customer());
        }());

        /* ═══════════════════════════════════════════════════════════════════
           SEARCH AUTOSUGGEST
           Uses the PVModern controller endpoint so live Magento products
           added in admin show in the main header as the customer types.
        ═══════════════════════════════════════════════════════════════════ */
        (function initSearchSuggest() {
            var $form  = $root.find('.pv3-search');
            var $input = $form.find('.pv3-search-input');
            var $drop  = $form.find('#pv3-search-dropdown');
            /* Read attributes directly — jQuery .data() can return stale-cached values
               when other widgets mutate the DOM, which silently breaks suggestions. */
            var suggestUrl = String($form.attr('data-suggest-url') || $form.data('suggest-url') || '');
            var searchUrl  = String($form.attr('data-search-url')  || $form.data('search-url')  || '');
            var resultsAnchor = String($form.attr('data-results-anchor') || 'pv3-products');
            var minLength  = parseInt($form.attr('data-min-length'), 10) || 1;
            var debounceTimer = null;
            var activeIdx = -1;
            var request = null;
            var cache = {};

            if (!$form.length || !$input.length || !$drop.length || !suggestUrl) {
                return;
            }

            function normalizeQuery(value) {
                return String(value || '').replace(/\s+/g, ' ').trim();
            }

            function escHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function buildSearchUrl(query) {
                var normalized = normalizeQuery(query);
                var suffix = resultsAnchor ? ('#' + resultsAnchor) : '';

                if (!normalized) {
                    return searchUrl + suffix;
                }

                return searchUrl + '?q=' + encodeURIComponent(normalized) + suffix;
            }

            function getLinks() {
                return $drop.find('.pv3-search-item');
            }

            function showDrop() {
                $form.attr('aria-expanded', 'true');
                $drop.prop('hidden', false).show();
            }

            function hideDrop() {
                activeIdx = -1;
                $form.attr('aria-expanded', 'false');
                $drop.prop('hidden', true).hide();
            }

            function syncActiveItem() {
                var $links = getLinks();
                $links.removeClass('is-active');
                if (activeIdx >= 0 && $links.eq(activeIdx).length) {
                    $links.eq(activeIdx).addClass('is-active');
                    $links.eq(activeIdx)[0].scrollIntoView({block: 'nearest'});
                }
            }

            function renderItems(items, query) {
                var html = '';

                if (!items.length) {
                    html =
                        '<div class="pv3-search-loading">' +
                            'No products found for "<strong>' + escHtml(query) + '</strong>"' +
                        '</div>';
                } else {
                    html = $.map(items, function (item, index) {
                        var oldPrice = item.original_price
                            ? '<del class="pv3-sd-old">' + escHtml(item.original_price) + '</del>'
                            : '';
                        var discount = item.discount
                            ? '<span class="pv3-sd-disc">-' + escHtml(item.discount) + '%</span>'
                            : '';
                        var sku = item.sku
                            ? '<span class="pv3-search-sku">' + escHtml(item.sku) + '</span>'
                            : '';

                        return (
                            '<a href="' + escHtml(item.url) + '" class="pv3-search-item" role="option" data-idx="' + index + '">' +
                                '<span class="pv3-search-thumb">' +
                                    '<img class="pv3-search-img" src="' + escHtml(item.image) + '" alt="' + escHtml(item.name) + '" loading="lazy" />' +
                                '</span>' +
                                '<span class="pv3-search-info">' +
                                    '<span class="pv3-search-name">' + escHtml(item.name) + '</span>' +
                                    sku +
                                    '<span class="pv3-search-price">' + oldPrice + escHtml(item.price) + discount + '</span>' +
                                '</span>' +
                            '</a>'
                        );
                    }).join('');

                    html +=
                        '<a href="' + escHtml(buildSearchUrl(query)) + '" class="pv3-search-item pv3-sd-all" role="option">' +
                            '<span class="pv3-search-info pv3-search-info--all">' +
                                'View all results for "' + escHtml(query) + '" ->' +
                            '</span>' +
                        '</a>';
                }

                activeIdx = -1;
                $drop.html(html);
                showDrop();
            }

            function fetchSuggestions(query) {
                if (request && request.readyState !== 4) {
                    try { request.abort(); } catch (e) { /* ignore */ }
                }

                if (cache[query]) {
                    renderItems(cache[query], query);
                    return;
                }

                $drop.html('<div class="pv3-search-loading">Searching...</div>');
                showDrop();

                request = $.ajax({
                    url: suggestUrl,
                    method: 'GET',
                    dataType: 'json',
                    cache: false,
                    timeout: 8000,
                    data: {q: query}
                }).done(function (response) {
                    var currentQuery = normalizeQuery($input.val());
                    var items = response && $.isArray(response.items) ? response.items : [];

                    cache[query] = items;
                    if (currentQuery === query) {
                        renderItems(items, query);
                    }
                }).fail(function (xhr) {
                    if (xhr && (xhr.statusText === 'abort' || xhr.readyState === 0)) {
                        return;
                    }
                    /* Surface backend errors to the dropdown so they aren't silent. */
                    var hint = (xhr && xhr.responseJSON && xhr.responseJSON.error)
                        ? ' (' + xhr.responseJSON.error + ')'
                        : '';
                    $drop.html('<div class="pv3-search-loading">Search is temporarily unavailable.' + escHtml(hint) + '</div>');
                    showDrop();
                });
            }

            $input.on('input', function () {
                var query = normalizeQuery($(this).val());

                clearTimeout(debounceTimer);
                if (query.length < minLength) {
                    hideDrop();
                    return;
                }

                debounceTimer = setTimeout(function () {
                    fetchSuggestions(query);
                }, 220);
            });

            $input.on('focus', function () {
                var query = normalizeQuery($input.val());
                if (query.length >= minLength && $drop.children().length) {
                    showDrop();
                }
            });

            $form.on('submit', function (event) {
                var query = normalizeQuery($input.val());

                event.preventDefault();
                window.location.assign(buildSearchUrl(query));
            });

            $input.on('keydown', function (event) {
                var $links = getLinks();

                if (!$links.length) {
                    return;
                }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    activeIdx = Math.min(activeIdx + 1, $links.length - 1);
                    syncActiveItem();
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    activeIdx = Math.max(activeIdx - 1, -1);
                    syncActiveItem();
                } else if (event.key === 'Enter' && activeIdx >= 0) {
                    event.preventDefault();
                    $links.eq(activeIdx)[0].click();
                } else if (event.key === 'Escape') {
                    hideDrop();
                }
            });

            $drop.on('mouseenter', '.pv3-search-item', function () {
                activeIdx = parseInt($(this).data('idx'), 10);
                if (isNaN(activeIdx)) {
                    activeIdx = -1;
                }
                syncActiveItem();
            });

            $(document).on('click.pvSearch', function (event) {
                if (!$(event.target).closest('.pv3-search').length) {
                    hideDrop();
                }
            });
        }());

        (function initOrderTracking() {
            var $modal = $root.find('[data-tracking-modal]');
            var $open = $root.find('[data-tracking-open]');
            var $close = $root.find('[data-tracking-close]');
            var $form = $root.find('[data-tracking-form]');
            var $result = $root.find('[data-tracking-result]');
            var endpoint = String($modal.data('tracking-url') || '');
            var pending = null;

            if (!$modal.length || !$open.length || !$form.length || !endpoint) {
                return;
            }

            function trackingEsc(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function openModal() {
                $modal.prop('hidden', false).addClass('is-open');
                setTimeout(function () {
                    $form.find('input[name="order_id"]').trigger('focus');
                }, 40);
            }

            function closeModal() {
                $modal.removeClass('is-open').prop('hidden', true);
            }

            function renderTracking(data) {
                var timeline = $.map(data.timeline || [], function (row) {
                    return (
                        '<li>' +
                            '<span class="pv3-tracking-dot"></span>' +
                            '<div><strong>' + trackingEsc(row.label) + '</strong>' +
                            '<small>' + trackingEsc(formatTrackingTime(row.time)) + '</small></div>' +
                        '</li>'
                    );
                }).join('');

                $result.html(
                    '<div class="pv3-tracking-card">' +
                        '<div class="pv3-tracking-status">' +
                            '<span>' + trackingEsc(data.status || 'Đang cập nhật') + '</span>' +
                            '<strong>' + trackingEsc(data.carrier_label || '') + '</strong>' +
                        '</div>' +
                        '<dl class="pv3-tracking-meta">' +
                            '<div><dt>Mã vận đơn</dt><dd>' + trackingEsc(data.tracking_number || '-') + '</dd></div>' +
                            '<div><dt>Cập nhật cuối</dt><dd>' + trackingEsc(formatTrackingTime(data.updated_at)) + '</dd></div>' +
                            '<div><dt>Dự kiến giao</dt><dd>' + trackingEsc(data.eta || '-') + '</dd></div>' +
                        '</dl>' +
                        '<ol class="pv3-tracking-timeline">' + timeline + '</ol>' +
                    '</div>'
                ).prop('hidden', false);
            }

            function formatTrackingTime(value) {
                var date = value ? new Date(value) : null;
                if (!date || isNaN(date.getTime())) {
                    return value || '-';
                }
                return date.toLocaleString('vi-VN', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            $open.on('click.pvTracking', openModal);
            $close.on('click.pvTracking', closeModal);
            $(document).on('click.pvTrackingOrderLink', '[data-order-track]', function (event) {
                event.preventDefault();
                openModal();
                $form.find('[name="order_id"]').val($(this).data('order-id') || '');
                $form.find('[name="provider"]').val($(this).data('provider') || 'spx');
                $form.trigger('submit');
            });

            $(document).on('keydown.pvTracking', function (event) {
                if (event.key === 'Escape' && !$modal.prop('hidden')) {
                    closeModal();
                }
            });

            $form.on('submit.pvTracking', function (event) {
                event.preventDefault();
                if (pending && pending.readyState !== 4) {
                    pending.abort();
                }

                $form.addClass('is-loading');
                $result.html('<div class="pv3-tracking-loading">Đang kiểm tra vận đơn...</div>').prop('hidden', false);

                pending = $.ajax({
                    url: endpoint,
                    method: 'GET',
                    dataType: 'json',
                    data: {
                        order_id: $.trim($form.find('[name="order_id"]').val()),
                        provider: $form.find('[name="provider"]').val(),
                        tracking_number: $.trim($form.find('[name="tracking_number"]').val())
                    }
                }).done(function (response) {
                    if (!response || response.success === false) {
                        $result.html('<div class="pv3-tracking-error">' + trackingEsc(response && response.message ? response.message : 'Không tìm thấy vận đơn.') + '</div>');
                        return;
                    }
                    renderTracking(response);
                }).fail(function (xhr) {
                    if (xhr && xhr.statusText === 'abort') {
                        return;
                    }
                    $result.html('<div class="pv3-tracking-error">Không thể kết nối hệ thống theo dõi. Vui lòng thử lại sau.</div>');
                }).always(function () {
                    $form.removeClass('is-loading');
                });
            });
        }());
    };
});
