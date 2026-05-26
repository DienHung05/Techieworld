(function () {
    'use strict';

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }
    function $all(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }
    function vnd(value) {
        return new Intl.NumberFormat('vi-VN', {style: 'currency', currency: 'VND', maximumFractionDigits: 0}).format(Number(value || 0));
    }
    var REAL_IMAGE_FALLBACK = 'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=1400&q=82';
    var IMAGE_FALLBACKS = {
        ai: 'https://images.unsplash.com/photo-1677442136019-21780ecad995?auto=format&fit=crop&w=1400&q=82',
        business: 'https://images.unsplash.com/photo-1554224155-6726b3ff858f?auto=format&fit=crop&w=1400&q=82',
        currency: 'https://images.unsplash.com/photo-1526304640581-d334cdbbf45e?auto=format&fit=crop&w=1400&q=82',
        fintech: 'https://images.unsplash.com/photo-1567427017947-545c5f8d16ad?auto=format&fit=crop&w=1400&q=82',
        mobile: 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=1400&q=82',
        weather: 'https://images.unsplash.com/photo-1504608524841-42fe6f032b4b?auto=format&fit=crop&w=1400&q=82'
    };
    function normalizeImage(value, type) {
        var src = String(value || '').trim();
        if (!src || src.indexOf('.svg') !== -1) { return IMAGE_FALLBACKS[String(type || '').toLowerCase()] || REAL_IMAGE_FALLBACK; }
        return src;
    }
    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(null, args); }, wait);
        };
    }
    function initTheme(root) {
        var saved = '';
        try { saved = localStorage.getItem('pvinfo_theme') || ''; } catch (e) {}
        if (saved === 'dark') { root.classList.add('is-dark'); }
        $all('[data-theme-toggle]', root).forEach(function (button) {
            button.addEventListener('click', function () {
                root.classList.toggle('is-dark');
                try { localStorage.setItem('pvinfo_theme', root.classList.contains('is-dark') ? 'dark' : 'light'); } catch (e) {}
            });
        });
    }
    function bindImageFallback(root) {
        $all('img', root).forEach(function (image) {
            image.onerror = function () {
                if (image.getAttribute('src') !== REAL_IMAGE_FALLBACK) {
                    image.setAttribute('src', REAL_IMAGE_FALLBACK);
                }
            };
        });
    }
    function requestJson(url) {
        return fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(function (response) {
            if (!response.ok) { throw new Error('Request failed'); }
            return response.json();
        });
    }
    function setHidden(node, hidden) {
        if (!node) { return; }
        if (hidden) { node.setAttribute('hidden', 'hidden'); } else { node.removeAttribute('hidden'); }
    }
    function articleCard(article) {
        return '<article class="pvnews-card"><a href="' + esc(article.url || '#') + '" target="_blank" rel="noopener">' +
            '<img src="' + esc(normalizeImage(article.image, article.category_slug || article.category)) + '" alt="' + esc(article.title) + '" loading="lazy">' +
            '<div class="pvnews-card-body"><div class="pvnews-meta"><strong>' + esc(article.category) + '</strong><span>' + esc(article.time) + '</span></div>' +
            '<h3>' + esc(article.title) + '</h3><p>' + esc(article.summary || 'Bài viết đang được cập nhật nội dung tóm tắt.') + '</p><span class="pvnews-source">' + esc(article.source || 'Unknown source') + '</span></div></a></article>';
    }
    function smallStory(article, className) {
        return '<a class="' + className + '" href="' + esc(article.url || '#') + '" target="_blank" rel="noopener"><img src="' + esc(normalizeImage(article.image, article.category_slug || article.category)) + '" alt="' + esc(article.title) + '" loading="lazy"><strong>' + esc(article.title) + '</strong></a>';
    }

    function initNews(root) {
        var apiUrl = root.getAttribute('data-api-url');
        var loading = $('[data-news-loading]', root);
        var error = $('[data-news-error]', root);
        var content = $('[data-news-content]', root);
        var form = $('[data-news-search-form]', root);
        var state = {category: 'all', page: 1, q: '', region: 'global', sort: 'latest'};
        try {
            var params = new URLSearchParams(window.location.search);
            state.category = params.get('category') || 'all';
            state.page = Math.max(parseInt(params.get('page'), 10) || 1, 1);
            state.q = params.get('q') || '';
            state.region = params.get('region') || 'global';
            state.sort = params.get('sort') || 'latest';
        } catch (e) {}
        if (form) {
            form.q.value = state.q;
            form.region.value = state.region;
            form.sort.value = state.sort;
        }

        function syncCats() {
            $all('[data-category]', root).forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-category') === state.category);
            });
        }
        function syncUrl() {
            var params = new URLSearchParams(window.location.search);
            if (state.category && state.category !== 'all') { params.set('category', state.category); } else { params.delete('category'); }
            if (state.q) { params.set('q', state.q); } else { params.delete('q'); }
            if (state.region && state.region !== 'global') { params.set('region', state.region); } else { params.delete('region'); }
            if (state.sort && state.sort !== 'latest') { params.set('sort', state.sort); } else { params.delete('sort'); }
            params.set('page', String(state.page));
            history.replaceState({}, '', window.location.pathname + '?' + params.toString());
        }
        function renderPagination(totalPages) {
            var wrap = $('[data-news-pagination]', root);
            if (!wrap) { return; }
            wrap.innerHTML = '';
            if (totalPages <= 1) { return; }
            var pages = [];
            for (var page = 1; page <= totalPages; page += 1) {
                if (page === 1 || page === totalPages || Math.abs(page - state.page) <= 1) {
                    pages.push(page);
                } else if (pages[pages.length - 1] !== '...') {
                    pages.push('...');
                }
            }
            if (state.page > 1) {
                var prev = document.createElement('button');
                prev.type = 'button';
                prev.textContent = 'Prev';
                prev.setAttribute('data-page', String(state.page - 1));
                wrap.appendChild(prev);
            }
            pages.forEach(function (page) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = String(page);
                if (page === '...') {
                    btn.disabled = true;
                    btn.className = 'is-dots';
                } else {
                    btn.className = page === state.page ? 'is-active' : '';
                    btn.setAttribute('data-page', String(page));
                }
                wrap.appendChild(btn);
            });
            if (state.page < totalPages) {
                var next = document.createElement('button');
                next.type = 'button';
                next.textContent = 'Next';
                next.setAttribute('data-page', String(state.page + 1));
                wrap.appendChild(next);
            }
        }
        function render(data) {
            var lead = data.lead || {};
            $('[data-breaking-list]', root).innerHTML = (data.breaking || []).map(function (headline) { return '<span>' + esc(headline) + '</span>'; }).join('');
            $('[data-lead-story]', root).innerHTML = '<a href="' + esc(lead.url || '#') + '" target="_blank" rel="noopener"><img src="' + esc(normalizeImage(lead.image, lead.category_slug || lead.category)) + '" alt="' + esc(lead.title) + '" loading="lazy"><div class="pvnews-lead-body"><div class="pvnews-meta"><strong>' + esc(lead.category) + '</strong><span>' + esc(lead.time) + '</span></div><h2>' + esc(lead.title) + '</h2><p>' + esc(lead.summary) + '</p><span class="pvnews-source">' + esc(lead.source) + '</span></div></a>';
            $('[data-top-stories]', root).innerHTML = (data.top || []).map(function (a) { return smallStory(a, 'pvnews-top-item'); }).join('');
            $('[data-latest-news]', root).innerHTML = (data.items || []).map(articleCard).join('');
            $('[data-popular-news]', root).innerHTML = (data.popular || []).map(function (a) { return smallStory(a, 'pvpopular-item'); }).join('');
            $('[data-hot-topics]', root).innerHTML = (data.topics || []).map(function (topic) { return '<span>' + esc(topic) + '</span>'; }).join('');
            $('[data-news-count]', root).textContent = (data.total || 0) + ' bài viết';
            if ($('[data-news-updated]', root)) { $('[data-news-updated]', root).textContent = data.updated_at ? ('Updated ' + data.updated_at) : ''; }
            setHidden($('[data-news-empty]', root), (data.items || []).length > 0);
            renderPagination(data.total_pages || 1);
            bindImageFallback(root);
        }
        function load() {
            syncCats();
            syncUrl();
            setHidden(loading, false);
            setHidden(error, true);
            setHidden(content, true);
            requestJson(apiUrl + '?category=' + encodeURIComponent(state.category) + '&page=' + encodeURIComponent(state.page) + '&q=' + encodeURIComponent(state.q) + '&region=' + encodeURIComponent(state.region) + '&sort=' + encodeURIComponent(state.sort))
                .then(function (data) {
                    render(data);
                    setHidden(content, false);
                })
                .catch(function () { setHidden(error, false); })
                .finally(function () { setHidden(loading, true); });
        }
        root.addEventListener('click', function (event) {
            var cat = event.target.closest('[data-category]');
            var page = event.target.closest('[data-page]');
            if (cat) {
                state.category = cat.getAttribute('data-category') || 'all';
                state.page = 1;
                load();
            }
            if (page) {
                state.page = parseInt(page.getAttribute('data-page'), 10) || 1;
                load();
                root.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
            if (event.target.closest('[data-news-retry]')) { load(); }
            if (event.target.closest('[data-news-refresh]')) { load(); }
            if (event.target.closest('[data-news-clear]') || event.target.closest('[data-news-reset]')) {
                if (form) {
                    form.q.value = '';
                    form.region.value = 'global';
                    form.sort.value = 'latest';
                }
                state = {category: 'all', page: 1, q: '', region: 'global', sort: 'latest'};
                load();
            }
            if (event.target.closest('[data-newsletter-submit]')) {
                var msg = $('[data-newsletter-message]', root);
                if (msg) {
                    msg.textContent = 'Đã lưu email để gửi newsletter.';
                    msg.className = 'is-success';
                }
            }
        });
        if (form) {
            var updateSearch = debounce(function () {
                state.q = form.q.value.trim();
                state.region = form.region.value;
                state.sort = form.sort.value;
                state.page = 1;
                load();
            }, 350);
            form.addEventListener('input', updateSearch);
            form.addEventListener('change', updateSearch);
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                updateSearch();
            });
        }
        load();
    }

    function initWeather(root) {
        var apiUrl = root.getAttribute('data-api-url');
        var form = $('[data-weather-form]', root);
        var loading = $('[data-weather-loading]', root);
        var error = $('[data-weather-error]', root);
        var content = $('[data-weather-content]', root);
        var lastUrl = '';
        function weatherUrl(extra) {
            var city = form.city.value || 'Hanoi';
            var unit = form.unit ? form.unit.value : 'metric';
            return apiUrl + (extra || ('?city=' + encodeURIComponent(city) + '&unit=' + encodeURIComponent(unit)));
        }
        function render(data) {
            var current = data.current || {};
            var unitLabel = data.unit === 'imperial' ? '°F' : '°C';
            $('[data-current-weather]', root).innerHTML = '<div class="pvweather-current-head"><div><h2>' + esc(data.location) + '</h2><p>' + esc(data.updated_at) + '</p></div><span>' + esc(current.icon) + '</span></div><div class="pvweather-temp"><strong>' + esc(current.temperature) + unitLabel + '</strong></div><h3>' + esc(current.condition) + '</h3><div class="pvweather-metrics"><div>Feels like<br><strong>' + esc(current.feels_like) + unitLabel + '</strong></div><div>Humidity<br><strong>' + esc(current.humidity) + '%</strong></div><div>Wind<br><strong>' + esc(current.wind) + '</strong></div><div>Pressure<br><strong>' + esc(current.pressure || '1012 hPa') + '</strong></div><div>Visibility<br><strong>' + esc(current.visibility || '10 km') + '</strong></div><div>UV<br><strong>' + esc(current.uv) + '</strong></div></div>';
            $('[data-weather-alert]', root).innerHTML = '<span class="pvweather-alert-badge">' + esc((data.alert || {}).severity || 'normal') + '</span><h2>Weather alert</h2><p><strong>' + esc((data.alert || {}).title) + '</strong></p><p>' + esc((data.alert || {}).description) + '</p><p>' + esc((data.alert || {}).time) + '</p>';
            $('[data-hourly-forecast]', root).innerHTML = (data.hourly || []).map(function (h) { return '<div class="pvweather-hour"><span>' + esc(h.time) + '</span><strong>' + esc(h.icon) + '</strong><b>' + esc(h.temp) + unitLabel + '</b><small>' + esc(h.rain) + '% rain</small><small>' + esc(h.wind || current.wind || '') + '</small></div>'; }).join('');
            $('[data-daily-forecast]', root).innerHTML = (data.daily || []).map(function (d) { return '<div class="pvweather-day"><strong>' + esc(d.day) + '</strong><p>' + esc(d.icon) + ' ' + esc(d.condition) + '</p><b>' + esc(d.min) + unitLabel + ' / ' + esc(d.max) + unitLabel + '</b><small>Rain ' + esc(d.rain || 0) + '% • Humidity ' + esc(d.humidity || current.humidity || 0) + '%</small></div>'; }).join('');
            if ($('[data-weather-chart]', root)) {
                var hourly = data.hourly || [];
                var max = Math.max.apply(null, hourly.map(function (h) { return Number(h.temp); })) || 1;
                var min = Math.min.apply(null, hourly.map(function (h) { return Number(h.temp); })) || 0;
                $('[data-weather-chart]', root).innerHTML = hourly.map(function (h) {
                    var height = 24 + ((Number(h.temp) - min) / Math.max(1, max - min)) * 76;
                    return '<span style="height:' + height + '%" title="' + esc(h.time) + ': ' + esc(h.temp) + unitLabel + '"><i>' + esc(h.time) + '</i></span>';
                }).join('');
            }
            $('[data-weather-news]', root).innerHTML = (data.news || []).map(function (n) { return '<article><img src="' + esc(normalizeImage(n.image, 'weather')) + '" alt="' + esc(n.title) + '" loading="lazy"><h3>' + esc(n.title) + '</h3><p>' + esc(n.summary) + '</p></article>'; }).join('');
            bindImageFallback(root);
        }
        function load(url) {
            lastUrl = url || weatherUrl();
            setHidden(loading, false); setHidden(error, true); setHidden(content, true);
            requestJson(lastUrl).then(function (data) {
                render(data);
                setHidden(content, false);
            }).catch(function () { setHidden(error, false); }).finally(function () { setHidden(loading, true); });
        }
        form.addEventListener('submit', function (event) { event.preventDefault(); load(); });
        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-weather-retry]')) { load(lastUrl); }
            if (event.target.closest('[data-use-location]')) {
                var msg = $('[data-geo-message]', root);
                if (!navigator.geolocation) { msg.textContent = 'Trình duyệt không hỗ trợ geolocation.'; return; }
                msg.textContent = 'Đang lấy vị trí...';
                navigator.geolocation.getCurrentPosition(function (position) {
                    msg.textContent = 'Đã lấy vị trí hiện tại.';
                    load(weatherUrl('?lat=' + encodeURIComponent(position.coords.latitude) + '&lon=' + encodeURIComponent(position.coords.longitude) + '&unit=' + encodeURIComponent(form.unit ? form.unit.value : 'metric')));
                }, function () {
                    msg.textContent = 'Bạn đã từ chối quyền vị trí. Vui lòng nhập city thủ công.';
                });
            }
        });
        load();
    }

    function initCurrency(root) {
        var apiUrl = root.getAttribute('data-api-url');
        var form = $('[data-currency-form]', root);
        var loading = $('[data-currency-loading]', root);
        var error = $('[data-currency-error]', root);
        var content = $('[data-currency-content]', root);
        var timer = null;
        var range = '1M';
        var hasLoaded = false;
        var favoritesKey = 'pvinfo_currency_favorites';
        var selectedPair = 'USD/VND';
        if (!form) { return; }
        function getFavorites() {
            try { return JSON.parse(localStorage.getItem(favoritesKey) || '[]') || []; } catch (e) { return []; }
        }
        function setFavorites(rows) {
            try { localStorage.setItem(favoritesKey, JSON.stringify(rows)); } catch (e) {}
        }
        function renderFavorites() {
            var wrap = $('[data-currency-favorites]', root);
            if (!wrap) { return; }
            var rows = getFavorites();
            wrap.innerHTML = rows.length ? '<strong>Favorites</strong>' + rows.map(function (pair) {
                return '<button type="button" data-convert-pair="' + esc(pair) + '">' + esc(pair) + '</button>';
            }).join('') : '<p class="pvinfo-note">Chưa có cặp tiền yêu thích.</p>';
        }
        function showError() {
            setHidden(loading, true);
            setHidden(error, false);
            if (!hasLoaded) { setHidden(content, true); }
        }
        function convert() {
            var url = apiUrl + '?mode=convert&amount=' + encodeURIComponent(form.amount.value || '100') + '&from=' + encodeURIComponent(form.from.value) + '&to=' + encodeURIComponent(form.to.value);
            return requestJson(url).then(function (data) {
                $('[data-convert-result]', root).textContent = Number(data.amount || 0).toLocaleString('en-US') + ' ' + data.from + ' = ' + Number(data.result || 0).toLocaleString('vi-VN', {maximumFractionDigits: data.to === 'VND' ? 0 : 4}) + ' ' + data.to;
                $('[data-convert-meta]', root).textContent = 'Updated ' + data.updated_at + ' • ' + data.source;
                selectedPair = data.from + '/' + data.to;
                var multi = data.multi || [];
                if ($('[data-currency-multi]', root)) {
                    $('[data-currency-multi]', root).innerHTML = '<strong>100 ' + esc(data.from) + ' tương đương</strong>' + multi.map(function (row) {
                        return '<span>' + esc(row.code) + ': <b>' + Number(row.value || 0).toLocaleString('vi-VN', {maximumFractionDigits: row.code === 'VND' ? 0 : 4}) + '</b></span>';
                    }).join('');
                }
            });
        }
        function latest() {
            return requestJson(apiUrl + '?mode=latest').then(function (data) {
                $('[data-currency-note]', root).textContent = data.note || '';
                $('[data-currency-updated]', root).textContent = 'Updated ' + data.updated_at + ' • ' + data.source;
                $('[data-rates-table]', root).innerHTML = (data.rates || []).map(function (r) {
                    var cls = Number(r.change) >= 0 ? 'pvcurrency-change-pos' : 'pvcurrency-change-neg';
                    return '<tr><td><strong>' + esc(r.pair) + '</strong></td><td>' + vnd(r.rate) + '</td><td class="' + cls + '">' + (Number(r.change) >= 0 ? '+' : '') + esc(r.change) + '%</td><td>' + esc(r.updated) + '</td><td><button type="button" data-convert-pair="' + esc(r.pair) + '">Convert</button></td></tr>';
                }).join('');
                $('[data-currency-watchlist]', root).innerHTML = (data.rates || []).slice(0, 4).map(function (r) { return '<div class="pvcurrency-watch-row"><strong>' + esc(r.pair) + '</strong><span>' + vnd(r.rate) + '</span></div>'; }).join('');
                renderFavorites();
                $('[data-currency-news]', root).innerHTML = (data.news || []).map(function (n) { return '<article><img src="' + esc(normalizeImage(n.image, 'currency')) + '" alt="' + esc(n.title) + '" loading="lazy"><h3>' + esc(n.title) + '</h3><p>' + esc(n.summary) + '</p></article>'; }).join('');
                bindImageFallback(root);
            });
        }
        function history() {
            $all('[data-range]', root).forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-range') === range);
            });
            return requestJson(apiUrl + '?mode=history&range=' + encodeURIComponent(range)).then(function (data) {
                var points = data.points || [];
                var max = Math.max.apply(null, points.map(function (p) { return Number(p.value); })) || 1;
                var min = Math.min.apply(null, points.map(function (p) { return Number(p.value); })) || 0;
                $('[data-currency-chart]', root).innerHTML = points.map(function (p, i) {
                    var v = Number(p.value);
                    var h = 20 + ((v - min) / Math.max(1, max - min)) * 80;
                    var prev = i > 0 ? Number(points[i - 1].value) : v;
                    var cls = 'pvcurrency-bar';
                    if (v > prev) { cls += ' is-up'; }
                    else if (v < prev) { cls += ' is-down'; }
                    return '<span class="' + cls + '" style="height:' + h + '%" title="' + esc(p.label) + ': ' + esc(p.value) + '"></span>';
                }).join('');
            });
        }
        function loadAll() {
            setHidden(loading, false);
            setHidden(error, true);
            if (!hasLoaded) { setHidden(content, true); }
            Promise.all([latest(), convert(), history()]).then(function () {
                hasLoaded = true;
                setHidden(content, false);
            }).catch(showError).finally(function () {
                setHidden(loading, true);
            });
        }
        function debounceConvert() {
            clearTimeout(timer);
            timer = setTimeout(function () { convert().catch(showError); }, 300);
        }
        form.addEventListener('input', debounceConvert);
        form.addEventListener('change', debounceConvert);
        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-currency-swap]')) {
                var from = form.from.value;
                form.from.value = form.to.value;
                form.to.value = from;
                convert().catch(showError);
            }
            if (event.target.closest('[data-currency-fav]')) {
                var pair = form.from.value + '/' + form.to.value;
                var rows = getFavorites();
                if (rows.indexOf(pair) === -1) {
                    rows.unshift(pair);
                    setFavorites(rows.slice(0, 6));
                } else {
                    setFavorites(rows.filter(function (row) { return row !== pair; }));
                }
                renderFavorites();
            }
            var pairButton = event.target.closest('[data-convert-pair]');
            if (pairButton) {
                var parts = String(pairButton.getAttribute('data-convert-pair') || selectedPair).split('/');
                if (parts.length === 2) {
                    form.from.value = parts[0];
                    form.to.value = parts[1];
                    convert().catch(showError);
                }
            }
            var rangeButton = event.target.closest('[data-range]');
            if (rangeButton) {
                range = rangeButton.getAttribute('data-range') || '1M';
                history().catch(showError);
            }
            if (event.target.closest('[data-currency-retry]')) {
                loadAll();
            }
            if (event.target.closest('[data-currency-refresh]')) {
                loadAll();
            }
        });
        renderFavorites();
        loadAll();
    }

    function initTracking(root) {
        var apiUrl = root.getAttribute('data-api-url');
        var form = $('[data-tracking-page-form]', root);
        var result = $('[data-tracking-page-result]', root);
        var search = $('[data-order-search]', root);
        var statusFilter = $('[data-order-status]', root);
        var orderList = $('[data-tracking-order-list]', root);
        var empty = $('[data-tracking-empty]', root);
        var demoOrders = [
            {id: 'TW-20260427-1001', date: '27/04/2026', total: '43.105.000 ₫', status: 'shipping', label: 'Đang giao', carrier: 'Giao Hàng Nhanh', provider: 'ghn', tracking: 'GHN-TW1001', timeline: ['Đã đặt hàng', 'Đã xác nhận', 'Đã thanh toán', 'Đang chuẩn bị', 'Đang giao']},
            {id: 'TW-20260426-0998', date: '26/04/2026', total: '15.990.000 ₫', status: 'processing', label: 'Đang xử lý', carrier: 'Shopee Express', provider: 'spx', tracking: 'SPX-TW0998', timeline: ['Đã đặt hàng', 'Đã xác nhận', 'Đang chuẩn bị']},
            {id: 'TW-20260425-0972', date: '25/04/2026', total: '7.200.000 ₫', status: 'complete', label: 'Hoàn thành', carrier: 'GHTK', provider: 'ghtk', tracking: 'GHTK-TW0972', timeline: ['Đã đặt hàng', 'Đã xác nhận', 'Đã thanh toán', 'Đang giao', 'Hoàn thành']},
            {id: 'TW-20260424-0940', date: '24/04/2026', total: '2.990.000 ₫', status: 'pending', label: 'Chờ xác nhận', carrier: 'Chưa phân bổ', provider: 'spx', tracking: '', timeline: ['Đã đặt hàng']}
        ];
        try {
            var params = new URLSearchParams(window.location.search);
            if (params.get('order_id')) { form.order_id.value = params.get('order_id'); }
            if (params.get('provider')) { form.provider.value = params.get('provider'); }
            if (params.get('tracking_number')) { form.tracking_number.value = params.get('tracking_number'); }
        } catch (e) {}
        function renderDashboard() {
            var q = String(search ? search.value : '').toLowerCase();
            var status = String(statusFilter ? statusFilter.value : 'all');
            var rows = demoOrders.filter(function (order) {
                return (status === 'all' || order.status === status) && (!q || order.id.toLowerCase().indexOf(q) !== -1 || order.tracking.toLowerCase().indexOf(q) !== -1);
            });
            var stats = [
                ['Tổng đơn', demoOrders.length],
                ['Đang giao', demoOrders.filter(function (o) { return o.status === 'shipping'; }).length],
                ['Hoàn thành', demoOrders.filter(function (o) { return o.status === 'complete'; }).length],
                ['Cần xử lý', demoOrders.filter(function (o) { return o.status === 'pending' || o.status === 'processing'; }).length]
            ];
            $('[data-tracking-stats]', root).innerHTML = stats.map(function (stat) {
                return '<article class="pvtracking-stat surface-card"><span>' + esc(stat[0]) + '</span><strong>' + esc(stat[1]) + '</strong></article>';
            }).join('');
            if (!orderList) { return; }
            orderList.innerHTML = rows.map(function (order) {
                return '<article class="pvtracking-order-card surface-card">' +
                    '<div class="pvtracking-order-head"><div><span>Mã đơn hàng</span><strong>' + esc(order.id) + '</strong></div><span class="pvtracking-status-badge pvtracking-status-badge--' + esc(order.status) + '">' + esc(order.label) + '</span></div>' +
                    '<div class="pvtracking-order-grid"><div><span>Ngày đặt</span><strong>' + esc(order.date) + '</strong></div><div><span>Tổng tiền</span><strong>' + esc(order.total) + '</strong></div><div><span>Bên vận chuyển</span><strong>' + esc(order.carrier) + '</strong></div><div><span>Mã vận đơn</span><strong>' + esc(order.tracking || 'Chưa có') + '</strong></div></div>' +
                    '<ol class="pvtracking-mini-timeline">' + order.timeline.map(function (step) { return '<li>' + esc(step) + '</li>'; }).join('') + '</ol>' +
                    '<div class="pvtracking-order-actions"><button type="button" data-order-detail="' + esc(order.id) + '">Xem chi tiết</button><button type="button" data-order-track="' + esc(order.id) + '">Theo dõi vận đơn</button></div>' +
                    '</article>';
            }).join('');
            setHidden(empty, rows.length > 0);
        }
        function renderOrderDetail(order) {
            result.innerHTML = '<div class="pvtracking-card">' +
                '<div class="pvtracking-status"><div><span>Chi tiết đơn hàng</span><strong>' + esc(order.id) + '</strong></div><span class="pvtracking-pill">' + esc(order.label) + '</span></div>' +
                '<div class="pvtracking-meta"><div><span>Ngày đặt</span><strong>' + esc(order.date) + '</strong></div><div><span>Tổng tiền</span><strong>' + esc(order.total) + '</strong></div><div><span>Bên vận chuyển</span><strong>' + esc(order.carrier) + '</strong></div><div><span>Mã vận đơn</span><strong>' + esc(order.tracking || 'Chưa có') + '</strong></div></div>' +
                '<h2>Timeline</h2><ol class="pvtracking-timeline">' + order.timeline.map(function (label) { return '<li><span class="pvtracking-dot"></span><div><strong>' + esc(label) + '</strong><small>Đang cập nhật</small></div></li>'; }).join('') + '</ol>' +
                '</div>';
        }
        function render(data) {
            result.innerHTML = '<div class="pvtracking-card">' +
                '<div class="pvtracking-status"><div><span>Trạng thái hiện tại</span><strong>' + esc(data.status) + '</strong></div><span class="pvtracking-pill">' + esc(data.carrier_label) + '</span></div>' +
                '<div class="pvtracking-meta"><div><span>Mã đơn</span><strong>' + esc(data.order_id) + '</strong></div><div><span>Mã vận đơn</span><strong>' + esc(data.tracking_number) + '</strong></div><div><span>Cập nhật cuối</span><strong>' + esc(data.updated_at) + '</strong></div><div><span>Dự kiến giao</span><strong>' + esc(data.eta) + '</strong></div></div>' +
                '<h2>Timeline</h2><ol class="pvtracking-timeline">' + (data.timeline || []).map(function (row) { return '<li><span class="pvtracking-dot"></span><div><strong>' + esc(row.label) + '</strong><small>' + esc(row.time) + '</small></div></li>'; }).join('') + '</ol>' +
                '</div>';
        }
        function submit() {
            var query = '?order_id=' + encodeURIComponent(form.order_id.value) + '&provider=' + encodeURIComponent(form.provider.value) + '&tracking_number=' + encodeURIComponent(form.tracking_number.value || '');
            result.innerHTML = '<div class="pvinfo-loading"><span></span><span></span><span></span></div>';
            requestJson(apiUrl + query).then(render).catch(function () {
                result.innerHTML = '<div class="pvinfo-error"><strong>Không tải được tracking.</strong><p>Vui lòng kiểm tra mã đơn hàng hoặc thử lại sau.</p></div>';
            });
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit();
        });
        root.addEventListener('input', function (event) {
            if (event.target && event.target.matches('[data-order-search]')) { renderDashboard(); }
        });
        root.addEventListener('change', function (event) {
            if (event.target && event.target.matches('[data-order-status]')) { renderDashboard(); }
        });
        root.addEventListener('click', function (event) {
            var detail = event.target.closest('[data-order-detail]');
            var track = event.target.closest('[data-order-track]');
            var order;
            if (detail) {
                order = demoOrders.find(function (row) { return row.id === detail.getAttribute('data-order-detail'); });
                if (order) { renderOrderDetail(order); result.scrollIntoView({behavior: 'smooth', block: 'start'}); }
            }
            if (track) {
                order = demoOrders.find(function (row) { return row.id === track.getAttribute('data-order-track'); });
                if (order) {
                    form.order_id.value = order.id;
                    form.provider.value = order.provider;
                    form.tracking_number.value = order.tracking;
                    submit();
                    result.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            }
        });
        renderDashboard();
        if (form.order_id.value) { submit(); }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var news = $('[data-pvinfo-news]');
        var weather = $('[data-pvinfo-weather]');
        var currency = $('[data-pvinfo-currency]');
        var tracking = $('[data-pvinfo-tracking]');
        [news, weather, currency, tracking].forEach(function (root) {
            if (root) { initTheme(root); }
        });
        if (news) { initNews(news); }
        if (weather) { initWeather(weather); }
        if (currency) { initCurrency(currency); }
        if (tracking) { initTracking(tracking); }
    });
}());

(function () {
    'use strict';

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }

    function $all(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function requestJson(url) {
        return fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed');
            }
            return response.json();
        });
    }

    function store(key, fallback) {
        try {
            return JSON.parse(localStorage.getItem(key) || JSON.stringify(fallback));
        } catch (e) {
            return fallback;
        }
    }

    function setStore(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {}
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, wait);
        };
    }

    function image(src, type) {
        var fallback = {
            news: 'https://images.unsplash.com/photo-1504711434969-e33886168f5c?auto=format&fit=crop&w=1400&q=82',
            currency: 'https://images.unsplash.com/photo-1526304640581-d334cdbbf45e?auto=format&fit=crop&w=1400&q=82',
            weather: 'https://images.unsplash.com/photo-1504608524841-42fe6f032b4b?auto=format&fit=crop&w=1400&q=82'
        };
        src = String(src || '').trim();
        return src && src.indexOf('.svg') === -1 ? src : (fallback[type] || fallback.news);
    }

    function show(node, visible) {
        if (!node) {
            return;
        }
        if (visible) {
            node.removeAttribute('hidden');
        } else {
            node.setAttribute('hidden', 'hidden');
        }
    }

    function toast(root, message, tone) {
        var stack = $('[data-toast-stack]', root);
        if (!stack) {
            return;
        }
        var item = document.createElement('div');
        item.className = 'gp-toast gp-toast--' + (tone || 'info');
        item.textContent = message;
        stack.appendChild(item);
        setTimeout(function () {
            item.classList.add('is-leaving');
            setTimeout(function () { item.remove(); }, 220);
        }, 2800);
    }

    function currency(value, code) {
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: code || 'VND',
            maximumFractionDigits: code === 'VND' ? 0 : 2
        }).format(Number(value || 0));
    }

    var currencyMeta = [
        ['USD', 'United States', '🇺🇸'], ['VND', 'Vietnam', '🇻🇳'], ['EUR', 'Eurozone', '🇪🇺'],
        ['JPY', 'Japan', '🇯🇵'], ['KRW', 'South Korea', '🇰🇷'], ['CNY', 'China', '🇨🇳'],
        ['GBP', 'United Kingdom', '🇬🇧'], ['AUD', 'Australia', '🇦🇺'], ['CAD', 'Canada', '🇨🇦'],
        ['SGD', 'Singapore', '🇸🇬'], ['THB', 'Thailand', '🇹🇭'], ['MYR', 'Malaysia', '🇲🇾'],
        ['IDR', 'Indonesia', '🇮🇩'], ['PHP', 'Philippines', '🇵🇭'], ['CHF', 'Switzerland', '🇨🇭'],
        ['HKD', 'Hong Kong', '🇭🇰'], ['INR', 'India', '🇮🇳']
    ];

    function initTheme(root) {
        var presets = {
            ocean: ['#2563eb', '#0ea5e9', '#a855f7'],
            sunrise: ['#f97316', '#fb7185', '#2563eb'],
            mint: ['#22c55e', '#14b8a6', '#2563eb'],
            purple: ['#a855f7', '#6366f1', '#22c55e'],
            minimal: ['#0f172a', '#64748b', '#2563eb'],
            darktech: ['#38bdf8', '#a855f7', '#22c55e']
        };
        function applyTheme(theme) {
            theme = theme || store('gp_theme', {preset: 'ocean', dark: false});
            var colors = presets[theme.preset] || presets.ocean;
            root.style.setProperty('--gp-primary', colors[0]);
            root.style.setProperty('--gp-secondary', colors[1]);
            root.style.setProperty('--gp-accent', colors[2]);
            root.classList.toggle('is-dark', !!theme.dark || theme.preset === 'darktech');
            $all('[data-theme-preset]', root).forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-theme-preset') === theme.preset);
            });
            setStore('gp_theme', theme);
        }
        applyTheme();
        root.addEventListener('click', function (event) {
            var panelToggle = event.target.closest('[data-theme-panel-toggle]');
            var panel = $('[data-theme-panel]', root);
            if (panelToggle && panel) {
                show(panel, panel.hasAttribute('hidden'));
            }
            var preset = event.target.closest('[data-theme-preset]');
            if (preset) {
                var current = store('gp_theme', {preset: 'ocean', dark: false});
                current.preset = preset.getAttribute('data-theme-preset') || 'ocean';
                applyTheme(current);
                toast(root, 'Theme preview saved.', 'success');
            }
            if (event.target.closest('[data-theme-toggle]')) {
                var theme = store('gp_theme', {preset: 'ocean', dark: false});
                theme.dark = !theme.dark;
                applyTheme(theme);
            }
            if (event.target.closest('[data-theme-reset]')) {
                applyTheme({preset: 'ocean', dark: false});
            }
        });
    }

    function initCommand(root) {
        var palette = $('[data-command-palette]', root);
        var input = $('[data-command-input]', root);
        var results = $('[data-command-results]', root);
        function open() {
            show(palette, true);
            if (input) { input.focus(); }
        }
        function close() {
            show(palette, false);
        }
        function render(q) {
            q = String(q || '').trim();
            var rows = [
                ['News: ' + (q || 'AI'), '/news?q=' + encodeURIComponent(q || 'AI')],
                ['Weather: ' + (q || 'Hanoi'), '/weather?city=' + encodeURIComponent(q || 'Hanoi')],
                ['Currency: USD/VND', '/currency?from=USD&to=VND']
            ];
            results.innerHTML = rows.map(function (row) {
                return '<a href="' + esc(row[1]) + '">' + esc(row[0]) + '</a>';
            }).join('');
        }
        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-command-open]')) {
                open();
                render('');
            }
            if (event.target === palette) {
                close();
            }
        });
        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                open();
                render('');
            }
            if (event.key === 'Escape') {
                close();
            }
        });
        if (input) {
            input.addEventListener('input', function () { render(input.value); });
        }
    }

    function initOffline(root) {
        var banner = $('[data-offline-banner]', root);
        function sync() {
            show(banner, !navigator.onLine);
        }
        window.addEventListener('online', sync);
        window.addEventListener('offline', sync);
        sync();
    }

    function newsCard(article) {
        return '<article class="gp-news-card gp-surface" data-article-id="' + esc(article.id || article.url || article.title) + '">' +
            '<a href="' + esc(article.url || '#') + '" target="_blank" rel="noopener" data-mark-read="' + esc(article.id || article.url || article.title) + '">' +
            '<img src="' + esc(image(article.image, 'news')) + '" alt="' + esc(article.title) + '" loading="lazy">' +
            '<div class="gp-news-card-body"><div class="gp-meta"><span>' + esc(article.category || 'News') + '</span><span>' + esc(article.time || '') + '</span></div>' +
            '<h3>' + esc(article.title) + '</h3><p>' + esc(article.summary || 'Summary is being updated.') + '</p><small>' + esc(article.source || 'Unknown source') + ' • ' + esc(article.author || 'Unknown author') + '</small></div></a>' +
            '<div class="gp-card-actions"><button type="button" data-save-article="' + esc(article.id || article.url || article.title) + '">Save</button><button type="button" data-copy-link="' + esc(article.url || '#') + '">Copy</button><button type="button" data-share-link="' + esc(article.url || '#') + '">Share</button><button type="button" data-ai-summary="' + esc(article.id || article.url || article.title) + '">AI Summary</button></div>' +
            '</article>';
    }

    function initNews(root) {
        var api = root.getAttribute('data-api-url');
        var form = $('[data-news-form]', root);
        var loading = $('[data-loading]', root);
        var error = $('[data-error]', root);
        var content = $('[data-news-content]', root);
        var savedKey = 'gp_saved_news';
        var readKey = 'gp_read_news';
        var state = {q: '', region: 'global', category: 'all', source: 'all', time: 'today', sort: 'latest', page: 1};
        try {
            var params = new URLSearchParams(window.location.search);
            ['q', 'region', 'category', 'source', 'time', 'sort'].forEach(function (key) {
                if (params.get(key)) { state[key] = params.get(key); }
            });
        } catch (e) {}
        Object.keys(state).forEach(function (key) {
            if (form && form[key]) { form[key].value = state[key]; }
        });

        function syncUrl() {
            var params = new URLSearchParams();
            Object.keys(state).forEach(function (key) {
                if (key !== 'page' && state[key] && state[key] !== 'all' && state[key] !== 'today' && state[key] !== 'latest') {
                    params.set(key, state[key]);
                }
            });
            if (state.page > 1) { params.set('page', String(state.page)); }
            history.replaceState({}, '', location.pathname + (params.toString() ? '?' + params.toString() : ''));
        }

        function renderSaved() {
            var saved = store(savedKey, []);
            $('[data-saved-news]', root).innerHTML = saved.length ? saved.slice(0, 6).map(function (item) {
                return '<a class="gp-mini-link" href="' + esc(item.url || '#') + '" target="_blank" rel="noopener">' + esc(item.title) + '</a>';
            }).join('') : '<p class="gp-muted">No saved articles yet.</p>';
            var read = store(readKey, []);
            $('[data-read-status]', root).innerHTML = '<p><strong>' + read.length + '</strong> articles marked as read.</p>';
        }

        function render(data, append) {
            var lead = data.lead || {};
            $('[data-breaking-list]', root).innerHTML = (data.breaking || []).map(function (headline) {
                return '<span>' + esc(headline) + '</span>';
            }).join('');
            $('[data-lead-story]', root).innerHTML =
                '<a href="' + esc(lead.url || '#') + '" target="_blank" rel="noopener"><img src="' + esc(image(lead.image, 'news')) + '" alt="' + esc(lead.title) + '" loading="lazy"><div><span class="gp-badge">' + esc(lead.category || 'News') + '</span><h2>' + esc(lead.title) + '</h2><p>' + esc(lead.summary || '') + '</p><small>' + esc(lead.source || '') + ' • ' + esc(lead.time || '') + '</small></div></a>';
            $('[data-top-stories]', root).innerHTML = (data.top || []).map(function (item) {
                return '<a class="gp-story-row" href="' + esc(item.url || '#') + '" target="_blank" rel="noopener"><img src="' + esc(image(item.image, 'news')) + '" alt="' + esc(item.title) + '" loading="lazy"><span>' + esc(item.category || 'News') + '</span><strong>' + esc(item.title) + '</strong></a>';
            }).join('');
            var grid = $('[data-latest-news]', root);
            grid.innerHTML = append ? grid.innerHTML + (data.items || []).map(newsCard).join('') : (data.items || []).map(newsCard).join('');
            $('[data-news-count]', root).textContent = (data.total || 0) + ' results • Updated ' + (data.updated_at || '');
            $('[data-news-updated]', root).textContent = data.mock ? 'Reference mode' : 'Live API';
            $('[data-hot-topics]', root).innerHTML = (data.topics || []).map(function (topic) { return '<button type="button" data-topic="' + esc(topic) + '">' + esc(topic) + '</button>'; }).join('');
            $('[data-popular-sources]', root).innerHTML = (data.popular || []).slice(0, 6).map(function (item) { return '<div class="gp-source-row"><strong>' + esc(item.source || 'Source') + '</strong><span>' + esc(item.category || 'News') + '</span></div>'; }).join('');
            $('[data-news-pagination]', root).innerHTML = '<button type="button" ' + (state.page <= 1 ? 'disabled' : '') + ' data-page-prev>Prev</button><span>Page ' + state.page + ' / ' + (data.total_pages || 1) + '</span><button type="button" ' + (state.page >= (data.total_pages || 1) ? 'disabled' : '') + ' data-page-next>Next</button>';
            show($('[data-empty]', root), !(data.items || []).length);
            renderSaved();
        }

        function load(append) {
            syncUrl();
            show(loading, true);
            show(error, false);
            show(content, append);
            var url = api + '?category=' + encodeURIComponent(state.category) + '&page=' + encodeURIComponent(state.page) + '&q=' + encodeURIComponent(state.q) + '&region=' + encodeURIComponent(state.region) + '&sort=' + encodeURIComponent(state.sort);
            requestJson(url).then(function (data) {
                render(data, append);
                show(content, true);
            }).catch(function () {
                show(error, true);
                toast(root, 'Could not load news feed.', 'error');
            }).finally(function () {
                show(loading, false);
            });
        }

        var updateFilters = debounce(function () {
            ['q', 'region', 'category', 'source', 'time', 'sort'].forEach(function (key) {
                state[key] = form[key] ? form[key].value : state[key];
            });
            state.page = 1;
            load(false);
        }, 350);

        form.addEventListener('submit', function (event) { event.preventDefault(); updateFilters(); });
        form.addEventListener('input', updateFilters);
        form.addEventListener('change', updateFilters);
        root.addEventListener('click', function (event) {
            var cat = event.target.closest('[data-news-category]');
            if (cat) {
                state.category = cat.getAttribute('data-news-category') || 'all';
                form.category.value = state.category;
                state.page = 1;
                load(false);
            }
            var topic = event.target.closest('[data-topic]');
            if (topic) {
                form.q.value = topic.getAttribute('data-topic') || '';
                updateFilters();
            }
            if (event.target.closest('[data-page-prev]')) { state.page = Math.max(1, state.page - 1); load(false); }
            if (event.target.closest('[data-page-next]')) { state.page += 1; load(false); }
            if (event.target.closest('[data-load-more]')) { state.page += 1; load(true); }
            if (event.target.closest('[data-reset-news]')) { state = {q: '', region: 'global', category: 'all', source: 'all', time: 'today', sort: 'latest', page: 1}; form.reset(); load(false); }
            if (event.target.closest('[data-save-news-filter]')) { setStore('gp_news_filter', state); toast(root, 'Filter saved locally.', 'success'); }
            if (event.target.closest('[data-retry]') || event.target.closest('[data-refresh-module]')) { load(false); }
            var save = event.target.closest('[data-save-article]');
            if (save) {
                var card = save.closest('[data-article-id]');
                var title = card ? (card.querySelector('h3') || {}).textContent : 'Saved article';
                var url = card ? (card.querySelector('a') || {}).href : '#';
                var rows = store(savedKey, []);
                if (!rows.some(function (item) { return item.url === url; })) {
                    rows.unshift({title: title, url: url});
                    setStore(savedKey, rows.slice(0, 30));
                }
                renderSaved();
                toast(root, 'Article saved.', 'success');
            }
            var copy = event.target.closest('[data-copy-link]');
            if (copy && navigator.clipboard) { navigator.clipboard.writeText(copy.getAttribute('data-copy-link') || ''); toast(root, 'Link copied.', 'success'); }
            var share = event.target.closest('[data-share-link]');
            if (share && navigator.share) { navigator.share({url: share.getAttribute('data-share-link') || location.href}); }
            var read = event.target.closest('[data-mark-read]');
            if (read) {
                var list = store(readKey, []);
                var id = read.getAttribute('data-mark-read');
                if (list.indexOf(id) === -1) { list.push(id); setStore(readKey, list); }
            }
            if (event.target.closest('[data-ai-summary]')) { toast(root, 'AI Summary: key points are generated from article title and description in this build.', 'info'); }
            if (event.target.closest('[data-focus-mode]')) { root.classList.toggle('is-focus-mode'); }
        });
        renderSaved();
        load(false);
    }

    function initCurrency(root) {
        var api = root.getAttribute('data-api-url');
        var form = $('[data-currency-form]', root);
        var loading = $('[data-loading]', root);
        var error = $('[data-error]', root);
        var content = $('[data-currency-content]', root);
        var range = '1M';
        var latestData = null;
        var convertData = null;
        var timer = null;
        var favoritesKey = 'gp_currency_favorites';
        var historyKey = 'gp_conversion_history';
        currencyMeta.forEach(function (row) {
            $all('[data-currency-select]', root).forEach(function (select) {
                var option = document.createElement('option');
                option.value = row[0];
                option.textContent = row[2] + ' ' + row[0] + ' — ' + row[1];
                select.appendChild(option);
            });
        });
        form.from.value = new URLSearchParams(location.search).get('from') || 'USD';
        form.to.value = new URLSearchParams(location.search).get('to') || 'VND';

        function renderFavorites() {
            var pairs = store(favoritesKey, []);
            $('[data-currency-favorites]', root).innerHTML = pairs.length ? pairs.map(function (pair) {
                return '<button type="button" data-pair="' + esc(pair) + '">' + esc(pair) + '</button>';
            }).join('') : '<p class="gp-muted">No favorite pairs yet.</p>';
        }

        function renderHistory() {
            var rows = store(historyKey, []);
            $('[data-conversion-history]', root).innerHTML = rows.length ? rows.slice(0, 8).map(function (row) {
                return '<div class="gp-history-row"><strong>' + esc(row.result) + '</strong><span>' + esc(row.time) + '</span></div>';
            }).join('') : '<p class="gp-muted">No conversion history yet.</p>';
        }

        function renderAlerts() {
            var alerts = store('gp_rate_alerts', []);
            $('[data-rate-alerts]', root).innerHTML = alerts.length ? alerts.map(function (row) { return '<div class="gp-alert-row">' + esc(row) + '</div>'; }).join('') : '<p class="gp-muted">Example: alert me when USD/VND exceeds 26,000.</p>';
        }

        function renderLatest(data) {
            latestData = data;
            $('[data-currency-note]', root).textContent = data.note || '';
            $('[data-currency-updated]', root).textContent = (data.mock ? 'Reference mode' : 'Live provider') + ' • Updated ' + (data.updated_at || '');
            $('[data-currency-watchlist]', root).innerHTML = (data.rates || []).slice(0, 5).map(function (row) {
                return '<div class="gp-market-row"><strong>' + esc(row.pair) + '</strong><span>' + currency(row.rate, 'VND') + '</span><small class="' + (Number(row.change) >= 0 ? 'is-up' : 'is-down') + '">' + (Number(row.change) >= 0 ? '+' : '') + esc(row.change) + '%</small></div>';
            }).join('');
            $('[data-rates-table]', root).innerHTML = (data.rates || []).map(function (row) {
                var code = String(row.pair || '').split('/')[0];
                var meta = currencyMeta.find(function (item) { return item[0] === code; }) || [code, 'Global', '🏳'];
                return '<tr><td><strong>' + esc(meta[2] + ' ' + code) + '</strong></td><td>' + esc(meta[1]) + '</td><td>' + currency(row.rate, 'VND') + '</td><td class="' + (Number(row.change) >= 0 ? 'is-up' : 'is-down') + '">' + (Number(row.change) >= 0 ? '+' : '') + esc(row.change) + '%</td><td>' + esc(row.updated || '') + '</td><td><button type="button" data-pair="' + esc(row.pair) + '">☆</button></td></tr>';
            }).join('');
            $('[data-currency-news]', root).innerHTML = (data.news || []).map(function (item) {
                return '<article class="gp-mini-card"><img src="' + esc(image(item.image, 'currency')) + '" alt="' + esc(item.title) + '" loading="lazy"><h3>' + esc(item.title) + '</h3><p>' + esc(item.summary || '') + '</p></article>';
            }).join('');
        }

        function renderConvert(data) {
            convertData = data;
            var resultText = Number(data.amount || 0).toLocaleString('en-US') + ' ' + data.from + ' = ' + Number(data.result || 0).toLocaleString('vi-VN', {maximumFractionDigits: data.to === 'VND' ? 0 : 4}) + ' ' + data.to;
            $('[data-convert-result]', root).textContent = resultText;
            $('[data-convert-meta]', root).textContent = '1 ' + data.from + ' = ' + Number((data.result || 0) / Math.max(1, data.amount || 1)).toLocaleString('vi-VN', {maximumFractionDigits: data.to === 'VND' ? 0 : 6}) + ' ' + data.to + ' • ' + (data.source || '') + ' • ' + (data.updated_at || '');
            $('[data-currency-multi]', root).innerHTML = (data.multi || []).map(function (row) {
                return '<span>' + esc(row.code) + '<strong>' + Number(row.value || 0).toLocaleString('vi-VN', {maximumFractionDigits: row.code === 'VND' ? 0 : 4}) + '</strong></span>';
            }).join('');
            var rows = store(historyKey, []);
            rows.unshift({result: resultText, time: data.updated_at || new Date().toLocaleString()});
            setStore(historyKey, rows.slice(0, 20));
            renderHistory();
        }

        function renderHistoryChart(data) {
            var points = data.points || [];
            var values = points.map(function (p) { return Number(p.value); });
            var max = Math.max.apply(null, values) || 1;
            var min = Math.min.apply(null, values) || 0;
            var first = values[0] || 0;
            var last = values[values.length - 1] || first;
            $('[data-chart-meta]', root).innerHTML = '<span>High ' + currency(max, 'VND') + '</span><span>Low ' + currency(min, 'VND') + '</span><span class="' + (last >= first ? 'is-up' : 'is-down') + '">' + ((last >= first ? '+' : '') + (((last - first) / Math.max(1, first)) * 100).toFixed(2)) + '%</span>';
            $('[data-currency-chart]', root).innerHTML = points.map(function (point, i) {
                var v = Number(point.value);
                var height = 18 + ((v - min) / Math.max(1, max - min)) * 82;
                var prev = i > 0 ? Number(points[i - 1].value) : v;
                var cls = v > prev ? 'is-up' : (v < prev ? 'is-down' : '');
                return '<span class="' + cls + '" style="height:' + height + '%" title="' + esc(point.label) + ': ' + esc(point.value) + '"><i>' + esc(point.label) + '</i></span>';
            }).join('');
            $all('[data-range]', root).forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-range') === range);
            });
        }

        function convert() {
            return requestJson(api + '?mode=convert&amount=' + encodeURIComponent(form.amount.value || '0') + '&from=' + encodeURIComponent(form.from.value) + '&to=' + encodeURIComponent(form.to.value)).then(renderConvert);
        }

        function latest() {
            return requestJson(api + '?mode=latest').then(renderLatest);
        }

        function history() {
            return requestJson(api + '?mode=history&range=' + encodeURIComponent(range) + '&from=' + encodeURIComponent(form.from.value) + '&to=' + encodeURIComponent(form.to.value)).then(renderHistoryChart);
        }

        function loadAll() {
            show(loading, true);
            show(error, false);
            requestJson(api + '?mode=latest').then(function (data) {
                renderLatest(data);
                return Promise.all([convert(), history()]);
            }).then(function () {
                show(content, true);
            }).catch(function () {
                show(error, true);
                toast(root, 'Could not load currency data.', 'error');
            }).finally(function () {
                show(loading, false);
            });
        }

        form.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { convert().catch(function () { toast(root, 'Conversion failed.', 'error'); }); }, 300);
        });
        form.addEventListener('change', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { Promise.all([convert(), history()]).catch(function () { toast(root, 'Currency update failed.', 'error'); }); }, 300);
        });
        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-currency-swap]')) {
                var from = form.from.value;
                form.from.value = form.to.value;
                form.to.value = from;
                Promise.all([convert(), history()]);
            }
            var pair = event.target.closest('[data-pair]');
            if (pair) {
                var value = pair.getAttribute('data-pair') || 'USD/VND';
                var parts = value.split('/');
                if (parts.length === 2) {
                    form.from.value = parts[0];
                    form.to.value = parts[1];
                    Promise.all([convert(), history()]);
                }
            }
            if (event.target.closest('[data-currency-fav]')) {
                var p = form.from.value + '/' + form.to.value;
                var favs = store(favoritesKey, []);
                if (favs.indexOf(p) === -1) { favs.unshift(p); } else { favs = favs.filter(function (row) { return row !== p; }); }
                setStore(favoritesKey, favs.slice(0, 12));
                renderFavorites();
                toast(root, 'Favorite pairs updated.', 'success');
            }
            var rangeButton = event.target.closest('[data-range]');
            if (rangeButton) {
                range = rangeButton.getAttribute('data-range') || '1M';
                history();
            }
            if (event.target.closest('[data-copy-conversion]') && navigator.clipboard && convertData) {
                navigator.clipboard.writeText($('[data-convert-result]', root).textContent || '');
                toast(root, 'Conversion copied.', 'success');
            }
            if (event.target.closest('[data-share-conversion]') && navigator.share) {
                navigator.share({text: $('[data-convert-result]', root).textContent || ''});
            }
            if (event.target.closest('[data-compact-toggle]')) {
                root.classList.toggle('is-compact');
            }
            if (event.target.closest('[data-add-rate-alert]')) {
                var alerts = store('gp_rate_alerts', []);
                alerts.unshift(form.from.value + '/' + form.to.value + ' threshold alert');
                setStore('gp_rate_alerts', alerts.slice(0, 8));
                renderAlerts();
            }
            if (event.target.closest('[data-retry]') || event.target.closest('[data-currency-refresh]') || event.target.closest('[data-refresh-module]')) {
                loadAll();
            }
        });
        renderFavorites();
        renderHistory();
        renderAlerts();
        loadAll();
    }

    function initWeather(root) {
        var api = root.getAttribute('data-api-url');
        var form = $('[data-weather-form]', root);
        var loading = $('[data-loading]', root);
        var error = $('[data-error]', root);
        var content = $('[data-weather-content]', root);
        var lastUrl = '';
        var favoritesKey = 'gp_weather_favorites';
        var recentKey = 'gp_weather_recent';
        function setCityValue(value) {
            value = String(value || '');
            if (!value || !form || !form.city) {
                return;
            }
            var exists = Array.prototype.some.call(form.city.options, function (option) {
                return option.value === value;
            });
            if (!exists) {
                var option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                form.city.appendChild(option);
            }
            form.city.value = value;
        }
        try {
            var weatherParams = new URLSearchParams(location.search);
            if (weatherParams.get('city')) { setCityValue(weatherParams.get('city')); }
            if (weatherParams.get('unit')) { form.unit.value = weatherParams.get('unit'); }
        } catch (e) {}

        function renderCities() {
            var recent = store(recentKey, []);
            $('[data-recent-cities]', root).innerHTML = recent.slice(0, 8).map(function (city) {
                return '<button type="button" data-city="' + esc(city) + '">' + esc(city) + '</button>';
            }).join('');
            var favs = store(favoritesKey, []);
            $('[data-favorite-cities]', root).innerHTML = favs.length ? favs.map(function (city) {
                return '<button type="button" data-city="' + esc(city) + '">' + esc(city) + '</button>';
            }).join('') : '<p class="gp-muted">No favorite cities yet.</p>';
        }

        function weatherUrl(extra) {
            return api + (extra || ('?city=' + encodeURIComponent(form.city.value || 'Hanoi') + '&unit=' + encodeURIComponent(form.unit.value || 'metric') + '&wind_unit=' + encodeURIComponent(form.wind_unit.value || 'kmh')));
        }

        function render(data) {
            var current = data.current || {};
            var unit = data.unit === 'imperial' ? '°F' : '°C';
            $('[data-current-weather]', root).innerHTML =
                '<div class="gp-current-top"><div><span class="gp-eyebrow">Current weather</span><h2>' + esc(data.location || '') + '</h2><p>Updated ' + esc(data.updated_at || '') + '</p></div><strong>' + esc(current.icon || '☁') + '</strong></div>' +
                '<div class="gp-temp">' + esc(current.temperature) + '<span>' + unit + '</span></div><h3>' + esc(current.condition || '') + '</h3>' +
                '<div class="gp-weather-metrics">' +
                metric('Feels like', esc(current.feels_like) + unit) + metric('High / Low', esc(current.high || current.temperature) + unit + ' / ' + esc(current.low || current.temperature) + unit) + metric('Humidity', esc(current.humidity) + '%') + metric('Wind', esc(current.wind || '')) + metric('Wind dir', esc(current.wind_direction || 'NE')) + metric('Pressure', esc(current.pressure || '1012 hPa')) + metric('Visibility', esc(current.visibility || '10 km')) + metric('UV index', esc(current.uv || 'N/A')) + metric('Rain chance', esc(current.rain_chance || 0) + '%') + metric('Sunrise', esc(current.sunrise || '06:00')) + metric('Sunset', esc(current.sunset || '18:00')) +
                '</div>';
            $('[data-weather-alert]', root).innerHTML = '<span class="gp-alert-badge">' + esc((data.alert || {}).severity || 'normal') + '</span><h2>Weather alerts</h2><strong>' + esc((data.alert || {}).title || 'No severe weather alerts.') + '</strong><p>' + esc((data.alert || {}).description || '') + '</p><small>' + esc((data.alert || {}).time || '') + '</small>';
            var aqi = data.aqi || {label: 'Good', value: 42, advice: 'Air quality is acceptable for outdoor activities.'};
            $('[data-air-quality]', root).innerHTML = '<h2>Air quality</h2><div class="gp-aqi"><strong>' + esc(aqi.value) + '</strong><span>' + esc(aqi.label) + '</span></div><p>' + esc(aqi.advice) + '</p>';
            $('[data-hourly-forecast]', root).innerHTML = (data.hourly || []).map(function (row) {
                return '<article><span>' + esc(row.time) + '</span><strong>' + esc(row.icon) + '</strong><b>' + esc(row.temp) + unit + '</b><small>' + esc(row.rain || 0) + '% rain</small><small>' + esc(row.wind || '') + '</small></article>';
            }).join('');
            $('[data-daily-forecast]', root).innerHTML = (data.daily || []).map(function (row) {
                return '<article><button type="button" data-day-detail><strong>' + esc(row.day) + '</strong><span>' + esc(row.icon) + ' ' + esc(row.condition) + '</span><b>' + esc(row.min) + unit + ' / ' + esc(row.max) + unit + '</b><small>Rain ' + esc(row.rain || 0) + '% • Humidity ' + esc(row.humidity || current.humidity || 0) + '% • Wind ' + esc(row.wind || current.wind || '') + '</small></button></article>';
            }).join('');
            $('[data-weather-recommendations]', root).innerHTML = '<div class="gp-recommend">' + ((current.rain_chance || 0) > 40 ? 'Bring an umbrella today.' : 'No umbrella needed for now.') + '</div><div class="gp-recommend">' + ((parseInt(current.uv, 10) || 0) > 6 ? 'Use sunscreen if you go outside.' : 'UV level is manageable.') + '</div><div class="gp-recommend">Dress for ' + esc(current.condition || 'current weather') + '.</div>';
            $('[data-weather-map]', root).innerHTML = '<span>' + esc(data.location || 'Map') + '</span><small>Radar, rain, clouds, wind and pressure layers placeholder. Connect Mapbox/OpenWeather map tiles for live radar.</small>';
            $('[data-weather-news]', root).innerHTML = (data.news || []).map(function (item) {
                return '<article class="gp-mini-card"><img src="' + esc(image(item.image, 'weather')) + '" alt="' + esc(item.title) + '" loading="lazy"><h3>' + esc(item.title) + '</h3><p>' + esc(item.summary || '') + '</p></article>';
            }).join('');
            renderCities();
        }

        function metric(label, value) {
            return '<div><span>' + label + '</span><strong>' + value + '</strong></div>';
        }

        function load(url) {
            lastUrl = url || weatherUrl();
            show(loading, true);
            show(error, false);
            requestJson(lastUrl).then(function (data) {
                var recent = store(recentKey, []);
                var city = form.city.value || data.location || 'Hanoi';
                recent = recent.filter(function (row) { return row !== city; });
                recent.unshift(city);
                setStore(recentKey, recent.slice(0, 10));
                render(data);
                show(content, true);
            }).catch(function () {
                show(error, true);
                toast(root, 'Could not load weather data.', 'error');
            }).finally(function () {
                show(loading, false);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            load();
        });
        root.addEventListener('click', function (event) {
            var cityButton = event.target.closest('[data-city]');
            if (cityButton) {
                setCityValue(cityButton.getAttribute('data-city') || 'Hanoi, Vietnam');
                load();
            }
            if (event.target.closest('[data-use-location]')) {
                var msg = $('[data-geo-message]', root);
                if (!navigator.geolocation) {
                    msg.textContent = 'Geolocation is not supported. Enter a city manually.';
                    return;
                }
                msg.textContent = 'Detecting your location...';
                navigator.geolocation.getCurrentPosition(function (pos) {
                    msg.textContent = 'Location detected.';
                    load(api + '?lat=' + encodeURIComponent(pos.coords.latitude) + '&lon=' + encodeURIComponent(pos.coords.longitude) + '&unit=' + encodeURIComponent(form.unit.value || 'metric'));
                }, function () {
                    msg.textContent = 'Location permission was denied. Using manual city search.';
                    toast(root, 'Location permission denied.', 'info');
                });
            }
            if (event.target.closest('[data-save-city]')) {
                var favs = store(favoritesKey, []);
                var city = form.city.value || 'Hanoi';
                if (favs.indexOf(city) === -1) {
                    favs.unshift(city);
                    setStore(favoritesKey, favs.slice(0, 12));
                }
                renderCities();
                toast(root, 'City saved.', 'success');
            }
            if (event.target.closest('[data-retry]') || event.target.closest('[data-refresh-module]')) {
                load(lastUrl || null);
            }
            if (event.target.closest('[data-day-detail]')) {
                toast(root, 'Daily detail expanded in compact preview mode.', 'info');
            }
        });
        renderCities();
        load();
    }

    document.addEventListener('DOMContentLoaded', function () {
        $all('[data-gp-root]').forEach(function (root) {
            initTheme(root);
            initCommand(root);
            initOffline(root);
            var module = root.getAttribute('data-gp-module');
            if (module === 'news') { initNews(root); }
            if (module === 'currency') { initCurrency(root); }
            if (module === 'weather') { initWeather(root); }
        });
    });
}());
