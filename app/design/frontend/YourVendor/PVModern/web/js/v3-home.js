define(['jquery', 'mage/cookies'], function ($) {
    'use strict';

    var PRODUCTS_PAGE_SIZE = 8;

    function parseNumber(value) {
        var parsed = parseFloat(value);
        return isNaN(parsed) ? null : parsed;
    }

    function normalizeText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    return function (config, element) {
        var $root = $(element),
            $grid = $root.find('[data-product-grid]'),
            $filterButtons = $root.find('[data-filter]'),
            $categorySelect = $root.find('[data-category-filter]'),
            $sort = $root.find('[data-sort]'),
            $availability = $root.find('[data-availability]'),
            $priceMin = $root.find('[data-price-min]'),
            $priceMax = $root.find('[data-price-max]'),
            $search = $root.find('[data-product-search]'),
            $viewButtons = $root.find('[data-view]'),
            $pagination = $root.find('[data-pagination]'),
            $recentSlider = $root.find('[data-recent-slider]'),
            $recentPrev = $root.find('[data-recent-prev]'),
            $recentNext = $root.find('[data-recent-next]'),
            $newsReveal = $root.find('[data-news-reveal]'),
            $newsToggle = $root.find('[data-news-toggle]'),
            $newsPanel = $root.find('[data-news-panel]'),
            $hero = $root.find('[data-hero-carousel]'),
            $heroTrack = $root.find('[data-hero-track]'),
            $heroDots = $root.find('[data-hero-dots]'),
            $heroLoading = $root.find('[data-hero-loading]'),
            $heroEmpty = $root.find('[data-hero-empty]'),
            $heroError = $root.find('[data-hero-error]'),
            heroIndex = 0,
            heroTimer = null,
            heroDelay = 5000,
            heroTouchStartX = null,
            pageSize = PRODUCTS_PAGE_SIZE,
            currentPage = 1,
            resultsAnchor = 'pv3-products';

        function escapeHtml(value) {
            return $('<div/>').text(String(value || '')).html();
        }

        function sanitizeTheme(value) {
            return String(value || 'violet').replace(/[^a-z0-9_-]/gi, '') || 'violet';
        }

        function getCards() {
            return $grid.children('.pv3-product-card');
        }

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

        function syncFormKey($scope) {
            var key = currentFormKey();

            if (!key) {
                return;
            }

            ($scope || $root).find('input[name="form_key"]').val(key).attr('value', key);
        }

        function getUrlState() {
            var params;

            try {
                params = new URLSearchParams(window.location.search);
                return {
                    category: normalizeText(params.get('category')).toLowerCase() || 'all',
                    availability: normalizeText(params.get('availability')).toLowerCase() || 'all',
                    search: normalizeText(params.get('q')),
                    sort: normalizeText(params.get('sort')) || 'price_desc',
                    min: normalizeText(params.get('min')),
                    max: normalizeText(params.get('max')),
                    page: Math.max(parseInt(params.get('page'), 10) || 1, 1)
                };
            } catch (error) {
                return {
                    category: 'all',
                    availability: 'all',
                    search: '',
                    sort: 'price_desc',
                    min: '',
                    max: '',
                    page: 1
                };
            }
        }

        function syncUrlState() {
            var params, state, query;

            try {
                params = new URLSearchParams(window.location.search);
            } catch (error) {
                return;
            }

            state = {
                category: String(getActiveCategory() || 'all').toLowerCase(),
                availability: String($availability.val() || 'all').toLowerCase(),
                search: normalizeText($search.val()),
                sort: String($sort.val() || 'price_desc'),
                min: normalizeText($priceMin.val()),
                max: normalizeText($priceMax.val()),
                page: currentPage
            };

            if (state.category && state.category !== 'all') {
                params.set('category', state.category);
            } else {
                params.delete('category');
            }

            if (state.availability && state.availability !== 'all') {
                params.set('availability', state.availability);
            } else {
                params.delete('availability');
            }

            if (state.search) {
                params.set('q', state.search);
            } else {
                params.delete('q');
            }

            if (state.sort && state.sort !== 'price_desc') {
                params.set('sort', state.sort);
            } else {
                params.delete('sort');
            }

            if (state.min) {
                params.set('min', state.min);
            } else {
                params.delete('min');
            }

            if (state.max) {
                params.set('max', state.max);
            } else {
                params.delete('max');
            }

            params.set('page', String(Math.max(1, state.page)));

            query = params.toString();
            window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : '') + '#' + resultsAnchor);
        }

        function getActiveCategory() {
            if ($categorySelect.length) {
                return String($categorySelect.val() || 'all').toLowerCase();
            }
            return String($filterButtons.filter('.is-active').data('filter') || 'all').toLowerCase();
        }

        function updateStats() {
            var $cards = getCards().not('.is-filtered-out'),
                inStock = 0,
                lowStock = 0;

            $cards.each(function () {
                var stock = String($(this).data('stock') || '');
                if (stock === 'in_stock') {
                    inStock += 1;
                }
                if (stock === 'low_stock') {
                    lowStock += 1;
                }
            });

            $root.find('[data-stat="count"]').text($cards.length);
            $root.find('[data-stat="instock"]').text(inStock);
            $root.find('[data-stat="lowstock"]').text(lowStock);
        }

        function buildPaginationModel(totalPages) {
            var pages = [],
                start,
                end,
                page;

            if (totalPages <= 7) {
                for (page = 1; page <= totalPages; page += 1) {
                    pages.push(page);
                }
                return pages;
            }

            pages.push(1);

            if (currentPage <= 4) {
                start = 2;
                end = 5;
            } else if (currentPage >= totalPages - 3) {
                start = totalPages - 4;
                end = totalPages - 1;
            } else {
                start = currentPage - 1;
                end = currentPage + 1;
            }

            if (start > 2) {
                pages.push('ellipsis-left');
            }

            for (page = start; page <= end; page += 1) {
                if (page > 1 && page < totalPages) {
                    pages.push(page);
                }
            }

            if (end < totalPages - 1) {
                pages.push('ellipsis-right');
            }

            pages.push(totalPages);
            return pages;
        }

        function appendPagerButton(label, page, extraClass, disabled) {
            var $button = $('<button/>', {
                type: 'button',
                'class': 'pv3-pagination-button' + (extraClass ? ' ' + extraClass : ''),
                text: label
            });

            if (typeof page === 'number') {
                $button.attr('data-page', page);
            }

            if (disabled) {
                $button.prop('disabled', true).attr('aria-disabled', 'true');
            }

            $pagination.append($button);
        }

        function renderPagination(totalItems) {
            var totalPages = Math.max(1, Math.ceil(totalItems / pageSize)),
                model;

            if (!$pagination.length) {
                return;
            }

            $pagination.empty();

            if (totalPages <= 1) {
                $pagination.attr('hidden', 'hidden');
                return;
            }

            $pagination.removeAttr('hidden');

            appendPagerButton('Prev', currentPage - 1, 'is-nav is-prev', currentPage <= 1);

            model = buildPaginationModel(totalPages);
            $.each(model, function (_, token) {
                if (typeof token === 'number') {
                    appendPagerButton(String(token), token, token === currentPage ? 'is-active' : '', false);
                } else {
                    $pagination.append($('<span/>', {
                        'class': 'pv3-pagination-gap',
                        'aria-hidden': 'true',
                        text: '...'
                    }));
                }
            });

            appendPagerButton('Next', currentPage + 1, 'is-nav is-next', currentPage >= totalPages);
        }

        function applyPagination() {
            var $matched = getCards().not('.is-filtered-out'),
                totalPages = Math.max(1, Math.ceil($matched.length / pageSize)),
                startIndex;

            if (currentPage > totalPages) {
                currentPage = totalPages;
            }

            getCards().each(function () {
                this.style.display = '';
            }).addClass('is-page-hidden');
            startIndex = (currentPage - 1) * pageSize;
            $matched.slice(startIndex, startIndex + pageSize).removeClass('is-page-hidden');
            getCards().filter('.is-filtered-out').addClass('is-page-hidden');

            renderPagination($matched.length);
            updateStats();
            syncUrlState();
        }

        function sortCards() {
            var mode = $sort.val(),
                cards = getCards().get();

            cards.sort(function (a, b) {
                var $a = $(a),
                    $b = $(b),
                    priceA = parseFloat($a.data('price')) || 0,
                    priceB = parseFloat($b.data('price')) || 0,
                    nameA = String($a.data('name') || '').toLowerCase(),
                    nameB = String($b.data('name') || '').toLowerCase(),
                    stockA = parseInt($a.data('stock-order'), 10) || 0,
                    stockB = parseInt($b.data('stock-order'), 10) || 0;

                if (mode === 'price_asc') {
                    return priceA - priceB;
                }
                if (mode === 'price_desc') {
                    return priceB - priceA;
                }
                if (mode === 'name_asc') {
                    return nameA.localeCompare(nameB);
                }
                if (mode === 'stock') {
                    return stockA - stockB;
                }
                return 0;
            });

            $.each(cards, function (_, card) {
                $grid.append(card);
            });
        }

        function applyFilters(resetPage) {
            var activeCategory = getActiveCategory(),
                availability = String($availability.val() || 'all').toLowerCase(),
                minPrice = parseNumber($priceMin.val()),
                maxPrice = parseNumber($priceMax.val()),
                search = String($search.val() || '').toLowerCase();

            getCards().each(function () {
                var $card = $(this),
                    categories = String($card.data('categories') || '').toLowerCase().split(/\s+/),
                    stock = String($card.data('stock') || '').toLowerCase(),
                    price = parseFloat($card.data('price')) || 0,
                    haystack = [
                        String($card.data('name') || ''),
                        String($card.data('brand') || ''),
                        String($card.data('category') || '')
                    ].join(' ').toLowerCase(),
                    matches = true;

                if (activeCategory !== 'all' && $.inArray(activeCategory, categories) === -1) {
                    matches = false;
                }
                if (availability !== 'all' && availability !== stock) {
                    matches = false;
                }
                if (minPrice !== null && price < minPrice) {
                    matches = false;
                }
                if (maxPrice !== null && price > maxPrice) {
                    matches = false;
                }
                if (search && stock === 'out_of_stock') {
                    matches = false;
                }
                if (search && haystack.indexOf(search) === -1) {
                    matches = false;
                }

                $card.toggleClass('is-filtered-out', !matches);
            });

            if (resetPage) {
                currentPage = 1;
            }

            applyPagination();
        }

        function scrollToProducts() {
            try {
                document.getElementById(resultsAnchor).scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            } catch (error) {
            }
        }

        function updateRecentArrows() {
            if (!$recentSlider.length) {
                return;
            }

            var el = $recentSlider.get(0),
                maxScroll = Math.max(0, el.scrollWidth - el.clientWidth),
                current = el.scrollLeft;

            $recentPrev.prop('disabled', current <= 8);
            $recentNext.prop('disabled', current >= maxScroll - 8);
        }

        function scrollRecent(direction) {
            if (!$recentSlider.length) {
                return;
            }
            var amount = Math.round($recentSlider.innerWidth() * 0.86);
            $recentSlider.stop().animate({
                scrollLeft: $recentSlider.scrollLeft() + (direction * amount)
            }, 260, updateRecentArrows);
        }

        function syncControlsFromUrl() {
            var state = getUrlState();

            currentPage = state.page;

            if ($categorySelect.length && $categorySelect.find('option[value="' + state.category + '"]').length) {
                $categorySelect.val(state.category);
            }
            if ($availability.length) {
                $availability.val(state.availability);
            }
            if ($search.length) {
                $search.val(state.search);
            }
            if ($sort.length && $sort.find('option[value="' + state.sort + '"]').length) {
                $sort.val(state.sort);
            }
            if ($priceMin.length) {
                $priceMin.val(state.min);
            }
            if ($priceMax.length) {
                $priceMax.val(state.max);
            }
        }

        function toggleNewsReveal(forceOpen) {
            var isOpen = $newsReveal.hasClass('is-open');
            var shouldOpen = typeof forceOpen === 'boolean' ? forceOpen : !isOpen;

            if (!$newsReveal.length) { return; }

            $newsReveal.toggleClass('is-open', shouldOpen);
            $newsToggle.attr('aria-expanded', shouldOpen ? 'true' : 'false');
            $newsPanel.attr('aria-hidden', shouldOpen ? 'false' : 'true');
        }

        function heroSlides() {
            return $heroTrack.children('[data-hero-slide]');
        }

        function renderHeroDots(total) {
            var html = '';

            for (var index = 0; index < total; index += 1) {
                html += '<button type="button" data-hero-dot="' + index + '" aria-label="Go to banner ' + (index + 1) + '"></button>';
            }

            $heroDots.html(html);
        }

        function updateHero() {
            var $slides = heroSlides(),
                total = $slides.length;

            if (!$hero.length || !$heroTrack.length || total === 0) {
                return;
            }

            heroIndex = Math.max(0, Math.min(heroIndex, total - 1));
            $heroTrack.css('transform', 'translateX(' + (-heroIndex * 100) + '%)');

            $slides.each(function (index) {
                var active = index === heroIndex;
                $(this)
                    .toggleClass('is-active', active)
                    .attr('aria-hidden', active ? 'false' : 'true');
            });

            $heroDots.find('[data-hero-dot]').each(function (index) {
                var active = index === heroIndex;
                $(this)
                    .toggleClass('is-active', active)
                    .attr('aria-current', active ? 'true' : 'false');
            });
        }

        function stopHeroAuto() {
            if (heroTimer) {
                window.clearInterval(heroTimer);
                heroTimer = null;
            }
        }

        function startHeroAuto() {
            stopHeroAuto();
            if (heroSlides().length <= 1) {
                return;
            }

            heroTimer = window.setInterval(function () {
                goHero(1);
            }, heroDelay);
        }

        function goHero(direction) {
            var total = heroSlides().length;
            if (total <= 0) {
                return;
            }

            heroIndex = (heroIndex + direction + total) % total;
            updateHero();
        }

        function goHeroTo(index) {
            var total = heroSlides().length;
            if (total <= 0) {
                return;
            }

            heroIndex = Math.max(0, Math.min(index, total - 1));
            updateHero();
        }

        function buildHeroSlide(slide, index, total) {
            var image = slide.image || slide.mobileImage || '',
                mobileImage = slide.mobileImage || image,
                theme = sanitizeTheme(slide.theme),
                title = escapeHtml(slide.title || 'Techieworld update'),
                subtitle = escapeHtml(slide.subtitle || ''),
                badge = escapeHtml(slide.badge || 'NEW'),
                ctaLabel = escapeHtml(slide.ctaLabel || 'Xem ngay'),
                ctaLink = escapeHtml(slide.ctaLink || '#pv3-products'),
                alt = escapeHtml(slide.alt || slide.title || 'Techieworld banner'),
                price = escapeHtml(slide.price || '');

            return '' +
                '<article class="pvhero-slide ' + (index === 0 ? 'is-active ' : '') + 'pvhero-slide--' + theme + '" data-hero-slide aria-roledescription="slide" aria-label="' + (index + 1) + ' / ' + total + '">' +
                    '<div class="pvhero-copy">' +
                        '<span class="pvhero-badge">' + badge + '</span>' +
                        '<h2>' + title + '</h2>' +
                        (subtitle ? '<p>' + subtitle + '</p>' : '') +
                        '<div class="pvhero-actions">' +
                            '<a class="pvhero-cta" href="' + ctaLink + '">' +
                                '<span>' + ctaLabel + '</span>' +
                                '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>' +
                            '</a>' +
                            (price ? '<span class="pvhero-price">' + price + '</span>' : '') +
                        '</div>' +
                    '</div>' +
                    '<a class="pvhero-media" href="' + ctaLink + '" tabindex="-1">' +
                        '<picture>' +
                            '<source media="(max-width: 640px)" srcset="' + escapeHtml(mobileImage) + '">' +
                            '<img src="' + escapeHtml(image) + '" alt="' + alt + '" loading="' + (index === 0 ? 'eager' : 'lazy') + '"' + (index === 0 ? ' fetchpriority="high"' : '') + ' />' +
                        '</picture>' +
                    '</a>' +
                '</article>';
        }

        function renderHero(slides) {
            var html = '',
                normalized = $.grep(slides || [], function (slide) {
                    return slide && slide.image && slide.title;
                });

            if (!$hero.length || !$heroTrack.length) {
                return;
            }

            $heroLoading.attr('hidden', 'hidden');
            $heroError.attr('hidden', 'hidden');

            if (!normalized.length) {
                $heroEmpty.removeAttr('hidden');
                $hero.find('[data-hero-viewport], [data-hero-prev], [data-hero-next], [data-hero-dots]').attr('hidden', 'hidden');
                stopHeroAuto();
                return;
            }

            $heroEmpty.attr('hidden', 'hidden');
            $hero.find('[data-hero-viewport], [data-hero-prev], [data-hero-next], [data-hero-dots]').removeAttr('hidden');

            $.each(normalized, function (index, slide) {
                html += buildHeroSlide(slide, index, normalized.length);
            });

            $heroTrack.html(html);
            renderHeroDots(normalized.length);
            heroIndex = 0;
            updateHero();
            startHeroAuto();
        }

        function refreshHeroFromEndpoint() {
            var endpoint;

            if (!$hero.length || !$heroTrack.length) {
                return;
            }

            endpoint = String($hero.data('banners-endpoint') || '');
            if (!endpoint) {
                updateHero();
                startHeroAuto();
                return;
            }

            if (!heroSlides().length) {
                $heroLoading.removeAttr('hidden');
            }

            $.ajax({
                url: endpoint,
                method: 'GET',
                dataType: 'json',
                timeout: 7000
            }).done(function (response) {
                if (response && response.success && $.isArray(response.items)) {
                    renderHero(response.items);
                    return;
                }
                if (!heroSlides().length) {
                    renderHero([]);
                }
            }).fail(function () {
                $heroLoading.attr('hidden', 'hidden');
                if (heroSlides().length) {
                    $heroError.attr('hidden', 'hidden');
                    $heroEmpty.attr('hidden', 'hidden');
                    updateHero();
                    startHeroAuto();
                    return;
                }
                $heroError.removeAttr('hidden');
                $heroEmpty.removeAttr('hidden');
            });
        }

        function initHeroCarousel() {
            if (!$hero.length || !$heroTrack.length) {
                return;
            }

            updateHero();
            startHeroAuto();
            refreshHeroFromEndpoint();

            $hero.on('mouseenter focusin', stopHeroAuto);
            $hero.on('mouseleave focusout', startHeroAuto);

            $hero.on('click', '[data-hero-prev]', function () {
                stopHeroAuto();
                goHero(-1);
                startHeroAuto();
            });

            $hero.on('click', '[data-hero-next]', function () {
                stopHeroAuto();
                goHero(1);
                startHeroAuto();
            });

            $hero.on('click', '[data-hero-dot]', function () {
                stopHeroAuto();
                goHeroTo(parseInt($(this).attr('data-hero-dot'), 10) || 0);
                startHeroAuto();
            });

            $hero.on('click', '[data-hero-retry]', refreshHeroFromEndpoint);

            $hero.on('keydown', function (event) {
                if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    goHero(-1);
                }
                if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    goHero(1);
                }
            });

            $hero.on('touchstart', function (event) {
                var touch = event.originalEvent.touches && event.originalEvent.touches[0];
                heroTouchStartX = touch ? touch.clientX : null;
            });

            $hero.on('touchend', function (event) {
                var touch = event.originalEvent.changedTouches && event.originalEvent.changedTouches[0],
                    delta;

                if (heroTouchStartX === null || !touch) {
                    return;
                }

                delta = touch.clientX - heroTouchStartX;
                heroTouchStartX = null;

                if (Math.abs(delta) < 48) {
                    return;
                }

                stopHeroAuto();
                goHero(delta > 0 ? -1 : 1);
                startHeroAuto();
            });
        }

        $filterButtons.on('click', function () {
            $filterButtons.removeClass('is-active');
            $(this).addClass('is-active');
            applyFilters(true);
        });

        $categorySelect.on('change', function () {
            applyFilters(true);
        });

        $sort.on('change', function () {
            sortCards();
            applyFilters(true);
        });

        $availability.on('change', function () {
            applyFilters(true);
        });

        $priceMin.add($priceMax).on('input change', function () {
            applyFilters(true);
        });

        $search.on('input', function () {
            applyFilters(true);
        });

        $viewButtons.on('click', function () {
            var view = $(this).data('view');
            $viewButtons.removeClass('is-active');
            $(this).addClass('is-active');
            $grid.toggleClass('is-list', view === 'list');
        });

        $root.on('submit', '.pv3-card-form, .pv3-recent-form, form[action*="/checkout/cart/add"]', function () {
            syncFormKey($(this));
        });

        $pagination.on('click', '[data-page]', function (event) {
            event.preventDefault();

            if ($(this).prop('disabled')) {
                return;
            }

            currentPage = Math.max(parseInt($(this).data('page'), 10) || 1, 1);
            applyPagination();
            scrollToProducts();
        });

        $recentPrev.on('click', function () {
            scrollRecent(-1);
        });

        $recentNext.on('click', function () {
            scrollRecent(1);
        });

        $recentSlider.on('scroll', updateRecentArrows);
        $(window).on('resize', updateRecentArrows);

         
        $newsToggle.on('click', function (e) {
            e.preventDefault();
            toggleNewsReveal();
        });

        $(document).on('click.pvNews touchstart.pvNews', function (event) {
            if ($newsReveal.hasClass('is-open') && !$(event.target).closest('[data-news-reveal]').length) {
                toggleNewsReveal(false);
            }
        });

        initHeroCarousel();
        syncFormKey($root);
        syncControlsFromUrl();
        sortCards();
        applyFilters(false);
        updateRecentArrows();
    };
});
