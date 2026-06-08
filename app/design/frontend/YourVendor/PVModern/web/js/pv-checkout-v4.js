define(['jquery', 'mage/cookies', 'Magento_Customer/js/customer-data'], function ($, cookies, customerData) {
    'use strict';

    var CITIES = [
        {name: 'An Giang', districts: ['Long Xuyên', 'Châu Đốc', 'Tân Châu', 'Châu Phú']},
        {name: 'Bà Rịa - Vũng Tàu', districts: ['Vũng Tàu', 'Bà Rịa', 'Long Điền', 'Phú Mỹ']},
        {name: 'Bạc Liêu', districts: ['Bạc Liêu', 'Giá Rai', 'Hòa Bình', 'Vĩnh Lợi']},
        {name: 'Bắc Giang', districts: ['Bắc Giang', 'Việt Yên', 'Yên Dũng', 'Lạng Giang']},
        {name: 'Bắc Kạn', districts: ['Bắc Kạn', 'Ba Bể', 'Chợ Đồn', 'Bạch Thông']},
        {name: 'Bắc Ninh', districts: ['Bắc Ninh', 'Từ Sơn', 'Quế Võ', 'Yên Phong']},
        {name: 'Bến Tre', districts: ['Bến Tre', 'Ba Tri', 'Châu Thành', 'Mỏ Cày Nam']},
        {name: 'Bình Dương', districts: ['Thủ Dầu Một', 'Dĩ An', 'Thuận An', 'Bến Cát']},
        {name: 'Bình Định', districts: ['Quy Nhơn', 'An Nhơn', 'Hoài Nhơn', 'Tuy Phước']},
        {name: 'Bình Phước', districts: ['Đồng Xoài', 'Phước Long', 'Bình Long', 'Chơn Thành']},
        {name: 'Bình Thuận', districts: ['Phan Thiết', 'La Gi', 'Hàm Thuận Bắc', 'Bắc Bình']},
        {name: 'Cà Mau', districts: ['Cà Mau', 'Năm Căn', 'Đầm Dơi', 'U Minh']},
        {name: 'Cao Bằng', districts: ['Cao Bằng', 'Bảo Lạc', 'Trùng Khánh', 'Hòa An']},
        {name: 'Đắk Lắk', districts: ['Buôn Ma Thuột', 'Buôn Hồ', 'Ea Kar', 'Krông Pắc']},
        {name: 'Đắk Nông', districts: ['Gia Nghĩa', 'Đắk Mil', 'Đắk R’lấp', 'Cư Jút']},
        {name: 'Điện Biên', districts: ['Điện Biên Phủ', 'Mường Lay', 'Điện Biên', 'Tuần Giáo']},
        {name: 'Đồng Nai', districts: ['Biên Hòa', 'Long Khánh', 'Nhơn Trạch', 'Trảng Bom']},
        {name: 'Đồng Tháp', districts: ['Cao Lãnh', 'Sa Đéc', 'Hồng Ngự', 'Lấp Vò']},
        {name: 'Gia Lai', districts: ['Pleiku', 'An Khê', 'Ayun Pa', 'Chư Sê']},
        {name: 'Hà Giang', districts: ['Hà Giang', 'Đồng Văn', 'Mèo Vạc', 'Vị Xuyên']},
        {name: 'Hà Nam', districts: ['Phủ Lý', 'Duy Tiên', 'Kim Bảng', 'Lý Nhân']},
        {name: 'Hà Tĩnh', districts: ['Hà Tĩnh', 'Hồng Lĩnh', 'Kỳ Anh', 'Cẩm Xuyên']},
        {name: 'Hải Dương', districts: ['Hải Dương', 'Chí Linh', 'Kinh Môn', 'Nam Sách']},
        {name: 'Hậu Giang', districts: ['Vị Thanh', 'Ngã Bảy', 'Châu Thành', 'Phụng Hiệp']},
        {name: 'Hòa Bình', districts: ['Hòa Bình', 'Lương Sơn', 'Mai Châu', 'Tân Lạc']},
        {name: 'Hưng Yên', districts: ['Hưng Yên', 'Mỹ Hào', 'Văn Lâm', 'Khoái Châu']},
        {name: 'Khánh Hòa', districts: ['Nha Trang', 'Cam Ranh', 'Ninh Hòa', 'Diên Khánh']},
        {name: 'Kiên Giang', districts: ['Rạch Giá', 'Hà Tiên', 'Phú Quốc', 'Châu Thành']},
        {name: 'Kon Tum', districts: ['Kon Tum', 'Đăk Hà', 'Ngọc Hồi', 'Sa Thầy']},
        {name: 'Lai Châu', districts: ['Lai Châu', 'Tam Đường', 'Than Uyên', 'Mường Tè']},
        {name: 'Lâm Đồng', districts: ['Đà Lạt', 'Bảo Lộc', 'Đức Trọng', 'Di Linh']},
        {name: 'Lạng Sơn', districts: ['Lạng Sơn', 'Cao Lộc', 'Hữu Lũng', 'Chi Lăng']},
        {name: 'Lào Cai', districts: ['Lào Cai', 'Sa Pa', 'Bảo Thắng', 'Bát Xát']},
        {name: 'Long An', districts: ['Tân An', 'Kiến Tường', 'Bến Lức', 'Đức Hòa']},
        {name: 'Nam Định', districts: ['Nam Định', 'Mỹ Lộc', 'Ý Yên', 'Hải Hậu']},
        {name: 'Nghệ An', districts: ['Vinh', 'Cửa Lò', 'Thái Hòa', 'Diễn Châu']},
        {name: 'Ninh Bình', districts: ['Ninh Bình', 'Tam Điệp', 'Hoa Lư', 'Gia Viễn']},
        {name: 'Ninh Thuận', districts: ['Phan Rang - Tháp Chàm', 'Ninh Hải', 'Ninh Phước', 'Thuận Nam']},
        {name: 'Phú Thọ', districts: ['Việt Trì', 'Phú Thọ', 'Lâm Thao', 'Thanh Ba']},
        {name: 'Phú Yên', districts: ['Tuy Hòa', 'Sông Cầu', 'Đông Hòa', 'Tây Hòa']},
        {name: 'Quảng Bình', districts: ['Đồng Hới', 'Ba Đồn', 'Bố Trạch', 'Lệ Thủy']},
        {name: 'Quảng Nam', districts: ['Tam Kỳ', 'Hội An', 'Điện Bàn', 'Núi Thành']},
        {name: 'Quảng Ngãi', districts: ['Quảng Ngãi', 'Đức Phổ', 'Bình Sơn', 'Sơn Tịnh']},
        {name: 'Quảng Ninh', districts: ['Hạ Long', 'Cẩm Phả', 'Uông Bí', 'Móng Cái']},
        {name: 'Quảng Trị', districts: ['Đông Hà', 'Quảng Trị', 'Gio Linh', 'Vĩnh Linh']},
        {name: 'Sóc Trăng', districts: ['Sóc Trăng', 'Vĩnh Châu', 'Ngã Năm', 'Kế Sách']},
        {name: 'Sơn La', districts: ['Sơn La', 'Mộc Châu', 'Mai Sơn', 'Thuận Châu']},
        {name: 'Tây Ninh', districts: ['Tây Ninh', 'Trảng Bàng', 'Hòa Thành', 'Gò Dầu']},
        {name: 'Thái Bình', districts: ['Thái Bình', 'Quỳnh Phụ', 'Tiền Hải', 'Đông Hưng']},
        {name: 'Thái Nguyên', districts: ['Thái Nguyên', 'Sông Công', 'Phổ Yên', 'Đại Từ']},
        {name: 'Thanh Hóa', districts: ['Thanh Hóa', 'Sầm Sơn', 'Bỉm Sơn', 'Nghi Sơn']},
        {name: 'Thừa Thiên Huế', districts: ['Huế', 'Hương Thủy', 'Hương Trà', 'Phú Vang']},
        {name: 'Tiền Giang', districts: ['Mỹ Tho', 'Gò Công', 'Cai Lậy', 'Châu Thành']},
        {name: 'Trà Vinh', districts: ['Trà Vinh', 'Duyên Hải', 'Càng Long', 'Cầu Ngang']},
        {name: 'Tuyên Quang', districts: ['Tuyên Quang', 'Sơn Dương', 'Hàm Yên', 'Yên Sơn']},
        {name: 'Vĩnh Long', districts: ['Vĩnh Long', 'Bình Minh', 'Long Hồ', 'Mang Thít']},
        {name: 'Vĩnh Phúc', districts: ['Vĩnh Yên', 'Phúc Yên', 'Bình Xuyên', 'Tam Đảo']},
        {name: 'Yên Bái', districts: ['Yên Bái', 'Nghĩa Lộ', 'Yên Bình', 'Văn Chấn']},
        {name: 'Thành phố Cần Thơ', districts: ['Ninh Kiều', 'Bình Thủy', 'Cái Răng', 'Ô Môn', 'Thốt Nốt']},
        {name: 'Thành phố Đà Nẵng', districts: ['Hải Châu', 'Sơn Trà', 'Thanh Khê', 'Liên Chiểu', 'Ngũ Hành Sơn']},
        {name: 'Thành phố Hà Nội', districts: ['Hoàn Kiếm', 'Ba Đình', 'Cầu Giấy', 'Đống Đa', 'Hai Bà Trưng', 'Thanh Xuân', 'Nam Từ Liêm', 'Long Biên']},
        {name: 'Thành phố Hải Phòng', districts: ['Hồng Bàng', 'Ngô Quyền', 'Lê Chân', 'Hải An', 'Kiến An']},
        {name: 'Thành phố Hồ Chí Minh', districts: ['Quận 1', 'Quận 3', 'Quận 5', 'Quận 7', 'Quận 10', 'Bình Thạnh', 'Tân Bình', 'Thủ Đức']}
    ];

    var ADDRESS_DATA = CITIES;
    var DISTRICT_WARDS = {};
    var WARDS = ['Phường/Xã trung tâm', 'Phường 1', 'Phường 2', 'Phường 3', 'Xã 1', 'Xã 2'];
    var WARD_MAP = {
        'Quận 1': ['Phường Bến Nghé', 'Phường Bến Thành', 'Phường Đa Kao', 'Phường Nguyễn Thái Bình', 'Phường Tân Định'],
        'Quận 3': ['Phường Võ Thị Sáu', 'Phường 9', 'Phường 10', 'Phường 11', 'Phường 12'],
        'Quận 5': ['Phường 1', 'Phường 2', 'Phường 3', 'Phường 4', 'Phường 5'],
        'Quận 7': ['Phường Tân Phong', 'Phường Tân Phú', 'Phường Tân Quy', 'Phường Phú Mỹ'],
        'Bình Thạnh': ['Phường 1', 'Phường 11', 'Phường 19', 'Phường 22', 'Phường 25'],
        'Tân Bình': ['Phường 1', 'Phường 2', 'Phường 4', 'Phường 12', 'Phường 15'],
        'Thủ Đức': ['Phường Linh Trung', 'Phường Linh Xuân', 'Phường Hiệp Bình Chánh', 'Phường Thảo Điền'],
        'Hoàn Kiếm': ['Phường Hàng Bạc', 'Phường Hàng Bài', 'Phường Hàng Bông', 'Phường Tràng Tiền'],
        'Ba Đình': ['Phường Điện Biên', 'Phường Đội Cấn', 'Phường Kim Mã', 'Phường Ngọc Hà'],
        'Cầu Giấy': ['Phường Dịch Vọng', 'Phường Dịch Vọng Hậu', 'Phường Nghĩa Đô', 'Phường Yên Hòa'],
        'Đống Đa': ['Phường Cát Linh', 'Phường Láng Hạ', 'Phường Ô Chợ Dừa', 'Phường Quang Trung'],
        'Hải Châu': ['Phường Hải Châu I', 'Phường Hải Châu II', 'Phường Bình Hiên', 'Phường Thạch Thang'],
        'Sơn Trà': ['Phường An Hải Bắc', 'Phường Mân Thái', 'Phường Nại Hiên Đông', 'Phường Thọ Quang'],
        'Ninh Kiều': ['Phường An Cư', 'Phường An Hòa', 'Phường Cái Khế', 'Phường Xuân Khánh']
    };

    var SHIPPING_METHODS = [
        {id: 'pvmodernshipping_spx', provider: 'spx', name: 'Shopee Express', price: 25000, description: 'Giao hàng trong 2-3 ngày, toàn quốc', eta: '27/04/2026 (2 ngày)'},
        {id: 'pvmodernshipping_ghn', provider: 'ghn', name: 'Giao Hàng Nhanh', price: 35000, description: 'Giao hàng nhanh 1-2 ngày', eta: '26/04/2026 (1 ngày)'},
        {id: 'pvmodernshipping_ghtk', provider: 'ghtk', name: 'Giao Hàng Tiết Kiệm', price: 15000, description: 'Giao hàng tiết kiệm 3-5 ngày', eta: '30/04/2026 (5 ngày)'}
    ];

    var PAYMENT_METHODS = [
        {id: 'bank_qr',  providerCode: 'bank_transfer', title: 'QR Ngân hàng',           gateway: 'bank_qr'},
        {id: 'momo',     providerCode: 'online_gateway', title: 'Ví MoMo',               gateway: 'momo'},
        {id: 'vnpay',    providerCode: 'online_gateway', title: 'VNPay',                 gateway: 'vnpay'},
        {id: 'card',     providerCode: 'online_gateway', title: 'PayPal',                gateway: 'paypal'},
        {id: 'cod',      providerCode: 'cod',            title: 'Thanh toán khi nhận hàng', gateway: ''}
    ];

    var BANKS = [
        {id: 'vcb', name: 'Vietcombank', code: '970436'},
        {id: 'tcb', name: 'Techcombank', code: '970407'},
        {id: 'acb', name: 'ACB', code: '970416'},
        {id: 'vpb', name: 'VPBank', code: '970432'}
    ];

    var _countdownTimer  = null;
    var _pollTimer       = null;
    var _eventSource     = null;
    var _countdownSecs   = 0;
    var _copyValues      = {};

    return function (config, element) {
        var $root = $(element);
        var bootstrap = readBootstrap();
        var storageKey = 'pvmodern_checkout_customer_flow';
        var STATE_VERSION = 'v7';
        var isSubmitting = false;
        var state = $.extend(true, {
            step: 1,
            maxUnlockedStep: 1,
            fullName: '',
            phone: '',
            email: '',
            addressLine1: '',
            addressLine2: '',
            province: '',
            district: '',
            ward: '',
            specialInstructions: '',
            saveDefault: false,
            shippingMethodId: '',
            paymentMethodId: '',
            bankId: 'vcb',
            walletId: 'momo',
            card: {holderName: '', number: '', expiry: '', cvv: ''},
            order: null,
            paymentStatus: 'idle'
        }, loadState());

        function readBootstrap() {
            var node = document.querySelector($root.data('bootstrap-selector') || '#pvcheckout-bootstrap');
            if (!node) {
                return {};
            }
            try {
                return JSON.parse(node.textContent || '{}');
            } catch (e) {
                return {};
            }
        }

        function checkoutFormKey() {
            if ($.mage && $.mage.cookies && $.mage.cookies.get('form_key')) {
                return $.mage.cookies.get('form_key');
            }
            return bootstrap.form_key || '';
        }

        function loadState() {
            try {
                var saved = JSON.parse(window.sessionStorage.getItem(storageKey) || '{}') || {};
                if (saved._v !== STATE_VERSION) { return {}; }
                return saved;
            } catch (e) {
                return {};
            }
        }

        function saveState() {
            try {
                window.sessionStorage.setItem(storageKey, JSON.stringify($.extend({_v: STATE_VERSION}, state)));
            } catch (e) {}
        }

        function esc(value) {
            return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function getRequestedStep() {
            try {
                var step = parseInt(new URLSearchParams(window.location.search).get('step'), 10) || 0;
                return step > 5 ? 1 : step;
            } catch (e) {
                return 0;
            }
        }

        function loadVietnamLocations() {
            $.ajax({
                url: (bootstrap.endpoints || {}).locations || '/pvmodern/api/locations',
                method: 'GET',
                dataType: 'json',
                timeout: 6500
            }).done(function (response) {
                var rows = Array.isArray(response) ? response : (response.locations || []);
                if (!Array.isArray(rows) || !rows.length) {
                    return;
                }
                DISTRICT_WARDS = {};
                ADDRESS_DATA = rows.map(function (province) {
                    var districts = (province.districts || []).map(function (district) {
                        if (typeof district === 'string') {
                            DISTRICT_WARDS[province.name + '|' + district] = [];
                            return district;
                        }
                        DISTRICT_WARDS[province.name + '|' + district.name] = (district.wards || []).map(function (ward) {
                            return typeof ward === 'string' ? ward : ward.name;
                        }).filter(Boolean);
                        return district.name;
                    });
                    return {name: province.name, districts: districts};
                });
                populateCities();
            });
        }

        function formatVND(value) {
            return new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND',
                maximumFractionDigits: 0
            }).format(parseFloat(value || 0));
        }

        function paymentIcon(type) {
            var icons = {
                cod: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16v10H4z"/><path d="M8 11h8"/><path d="M8 15h3"/></svg>',
                bank: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 10 9-6 9 6"/><path d="M5 10h14"/><path d="M6 10v8"/><path d="M10 10v8"/><path d="M14 10v8"/><path d="M18 10v8"/><path d="M4 18h16"/></svg>',
                card: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/></svg>',
                wallet: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h15a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a3 3 0 0 1 3-3h13"/><path d="M16 13h.01"/></svg>'
            };
            return icons[type] || icons.card;
        }

        function qrImageUrl(data) {
            data = String(data || '');
            if (!data) {
                return '';
            }
            return 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=10&data=' + encodeURIComponent(data);
        }

        function qrPlaceholder(label) {
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="260" height="260" viewBox="0 0 260 260">' +
                '<rect width="260" height="260" rx="22" fill="#f8fafc"/>' +
                '<rect x="52" y="52" width="48" height="48" rx="5" fill="#0f172a"/>' +
                '<rect x="160" y="52" width="48" height="48" rx="5" fill="#0f172a"/>' +
                '<rect x="52" y="160" width="48" height="48" rx="5" fill="#0f172a"/>' +
                '<rect x="118" y="118" width="24" height="24" fill="#0f172a"/>' +
                '<rect x="158" y="126" width="18" height="18" fill="#0f172a"/>' +
                '<rect x="184" y="154" width="26" height="26" fill="#0f172a"/>' +
                '<rect x="124" y="174" width="18" height="18" fill="#0f172a"/>' +
                '<text x="130" y="232" text-anchor="middle" fill="#64748b" font-family="Arial" font-size="13" font-weight="700">' + esc(String(label || 'QR code').slice(0, 28)) + '</text>' +
                '</svg>';
            return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
        }

        function rememberQrAsset($img) {
            if (!$img.length) {
                return '';
            }
            var stored = $img.data('pvLocalQrSrc');
            if (stored) {
                return stored;
            }
            var src = $img.attr('src') || '';
            if (src) {
                $img.data('pvLocalQrSrc', src);
            }
            return src;
        }

        function paymentQrPayload(pay, fallback) {
            pay = pay || {};
            return pay.qrPayload || pay.qr_payload ||
                pay.paymentUrl || pay.payment_url ||
                pay.checkout_url || pay.redirect_url ||
                pay.deeplinkUrl || pay.deeplink_url ||
                pay.reference || fallback || '';
        }

        function paymentRedirectUrl(pay) {
            pay = pay || {};
            return pay.redirect_url || pay.paymentUrl || pay.payment_url ||
                pay.checkout_url || pay.deeplinkUrl || pay.deeplink_url || '';
        }

        function isRealVnpayPayment(pay) {
            return state.paymentMethodId === 'vnpay' && !!paymentRedirectUrl(pay || {});
        }

        function isPaypalPayment(pay) {
            pay = pay || {};
            return state.paymentMethodId === 'card' &&
                String(pay.provider || '').toLowerCase() === 'paypal' &&
                !!paymentRedirectUrl(pay);
        }

        function isPendingLikeStatus(status) {
            status = String(status || '').toLowerCase();
            return status === '' || status === 'idle' || status === 'pending' || status === 'awaiting_payment';
        }

        function hasPendingRealVnpayPayment() {
            return !!(state.order && isRealVnpayPayment(state.order.payment || {}) && isPendingLikeStatus(state.paymentStatus));
        }

        function clearPaymentOverlay() {
            $root.find('[data-pcp-overlay]').attr('hidden', 'hidden');
            $root.find('[data-pcp-overlay-inner]').empty();
        }

        function resetPendingPaymentState(targetStep) {
            if (targetStep > 2 || state.paymentStatus === 'paid') {
                return;
            }
            stopCountdown();
            stopPaymentPolling();
            clearPaymentOverlay();
            state.paymentStatus = 'idle';
            if (targetStep === 1) {
                state.order = null;
                state.maxUnlockedStep = Math.max(1, Math.min(state.maxUnlockedStep || 1, 3));
                try {
                    if (window.history && window.history.replaceState && isPaymentConfirmationRoute()) {
                        window.history.replaceState({}, '', '/checkout');
                    }
                } catch (e) {}
            }
            saveState();
        }

        function qrProxyUrl(data, size) {
            data = String(data || '');
            if (!data) {
                return '';
            }
            return '/api/qr/url?size=' + encodeURIComponent(size || 540) + '&data=' + encodeURIComponent(data);
        }

        function secondsUntil(dateValue, fallbackSeconds) {
            if (!dateValue) {
                return fallbackSeconds;
            }
            var numeric = parseInt(dateValue, 10);
            if (!isNaN(numeric) && String(dateValue).match(/^[0-9]+$/)) {
                var seconds = numeric > 9999999999 ? Math.floor(numeric / 1000) : numeric;
                return Math.max(0, seconds - Math.floor(Date.now() / 1000));
            }

            var raw = String(dateValue);
            var normalized = raw;
            if (/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/.test(raw)) {
                normalized = raw.replace(' ', 'T') + 'Z';
            }
            var timestamp = Date.parse(normalized);
            if (!timestamp || isNaN(timestamp)) {
                return fallbackSeconds;
            }
            return Math.max(0, Math.floor((timestamp - Date.now()) / 1000));
        }

        function vnpayExpireEpochFromUrl(url) {
            if (!url) {
                return 0;
            }
            try {
                var params = new URL(url).searchParams;
                var raw = params.get('vnp_ExpireDate') || '';
                if (!/^[0-9]{14}$/.test(raw)) {
                    return 0;
                }
                var year = parseInt(raw.slice(0, 4), 10);
                var month = parseInt(raw.slice(4, 6), 10) - 1;
                var day = parseInt(raw.slice(6, 8), 10);
                var hour = parseInt(raw.slice(8, 10), 10);
                var min = parseInt(raw.slice(10, 12), 10);
                var sec = parseInt(raw.slice(12, 14), 10);
                return Math.floor(Date.UTC(year, month, day, hour - 7, min, sec) / 1000);
            } catch (e) {
                return 0;
            }
        }

        function paymentExpirySeconds(pay, fallbackSeconds) {
            pay = pay || {};
            if (state.paymentMethodId === 'vnpay') {
                var vnpayEpoch = vnpayExpireEpochFromUrl(paymentRedirectUrl(pay));
                if (vnpayEpoch > 0) {
                    var vnpaySeconds = secondsUntil(vnpayEpoch, fallbackSeconds);
                    return vnpaySeconds > 0 ? vnpaySeconds : fallbackSeconds;
                }
            }
            var epoch = parseInt(pay.expires_at_epoch || pay.expiresAtEpoch || 0, 10);
            if (epoch > 0) {
                return secondsUntil(epoch, fallbackSeconds);
            }
            return secondsUntil(pay.expires_at || pay.expiresAt, fallbackSeconds);
        }

        function setQrImage($img, primarySrc, fallbackSrc, label) {
            if (!$img.length) {
                return;
            }

            var localFallback = fallbackSrc || rememberQrAsset($img);
            var finalFallback = qrPlaceholder(label);
            var src = primarySrc || localFallback || finalFallback;

            $img.off('error.pvqr').on('error.pvqr', function () {
                var current = this.getAttribute('src') || '';
                if (localFallback && current !== localFallback) {
                    this.src = localFallback;
                    return;
                }
                if (current !== finalFallback) {
                    this.src = finalFallback;
                }
            });

            $img.attr('src', src).removeAttr('hidden').show();
            $img.each(function () {
                if (this.complete && this.naturalWidth === 0) {
                    $(this).triggerHandler('error.pvqr');
                }
            });
        }

        function cartItems() {
            
            
            
            
            
            if (state.cartSnapshotLocked && state.cartSnapshot && state.cartSnapshot.length) {
                return state.cartSnapshot;
            }
            var live = ((bootstrap.cart || {}).items || []);
            if (live.length) {
                
                
                state.cartSnapshot = live.slice();
                return live;
            }
            return state.cartSnapshot || [];
        }

        function subtotal() {
            var fallback = parseFloat((bootstrap.cart || {}).subtotal || 0);
            var live = ((bootstrap.cart || {}).items || []);
            if (live.length && !state.cartSnapshotSubtotal) {
                state.cartSnapshotSubtotal = fallback || 0;
            }
            return cartItems().reduce(function (sum, item) {
                return sum + parseFloat(item.row_total || ((item.price || 0) * (item.qty || 1)) || 0);
            }, 0) || fallback || state.cartSnapshotSubtotal || 0;
        }

        function itemCount() {
            var n = cartItems().reduce(function (sum, item) {
                return sum + (parseInt(item.qty, 10) || 0);
            }, 0);
            return n || parseInt((bootstrap.cart || {}).count || 0, 10) || (state.cartSnapshot || []).length;
        }

        function normalizeSnapshotItems(items) {
            if (!Array.isArray(items)) {
                return [];
            }
            return items.map(function (item) {
                var qty = parseInt(item.qty || item.qty_ordered || item.quantity || 1, 10) || 1;
                var price = parseFloat(item.price || item.unit_price || 0) || 0;
                var rowTotal = parseFloat(item.row_total || item.rowTotal || 0) || (price * qty);
                return {
                    name: item.name || item.product_name || item.sku || 'Product',
                    qty: qty,
                    price: price,
                    row_total: rowTotal,
                    image_url: item.image_url || item.image || item.thumbnail || '',
                    sku: item.sku || ''
                };
            }).filter(function (item) {
                return !!item.name;
            });
        }

        function restoreCartSnapshotFromResponse(res) {
            var items = normalizeSnapshotItems((res || {}).items || (res || {}).cart_items || (res || {}).order_items || []);
            if (!items.length || (state.cartSnapshot && state.cartSnapshot.length)) {
                return;
            }
            state.cartSnapshot = items;
            state.cartSnapshotLocked = true;
            state.cartSnapshotSubtotal = items.reduce(function (sum, item) {
                return sum + (parseFloat(item.row_total || 0) || 0);
            }, 0);
        }

        function selectedShipping() {
            var method = SHIPPING_METHODS.find(function (row) {
                return row.id === state.shippingMethodId;
            });
            if (!method) {
                return null;
            }
            method = $.extend({}, method);
            method.price = quoteShippingPrice(method);
            return method;
        }

        function selectedPayment() {
            return PAYMENT_METHODS.find(function (method) {
                return method.id === state.paymentMethodId;
            }) || null;
        }

        function total() {
            if (state.step === 4 || state.step === 5) {
                var srv = state.order && state.order.payment && parseInt(state.order.payment.amount, 10);
                if (srv > 0) { return srv; }
                if (state.orderTotal) { return state.orderTotal; }
            }
            var shipping = selectedShipping();
            return subtotal() + (shipping ? shipping.price : 0);
        }

        function shippingDistanceSurcharge() {
            if (!state.province) {
                return 0;
            }
            if (state.province === 'Thành phố Hồ Chí Minh') {
                return 0;
            }
            if (state.province === 'Thành phố Hà Nội') {
                return 14000;
            }
            if (state.province === 'Thành phố Đà Nẵng' || state.province === 'Thành phố Cần Thơ') {
                return 9000;
            }
            return 17000;
        }

        function quoteShippingPrice(method) {
            return method.price + shippingDistanceSurcharge();
        }

        function placeholder(label) {
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="112" height="112" viewBox="0 0 112 112">' +
                '<rect width="112" height="112" rx="16" fill="#f1f5f9"/>' +
                '<text x="56" y="52" text-anchor="middle" fill="#0f172a" font-family="Arial" font-size="11" font-weight="700">Techieworld</text>' +
                '<text x="56" y="70" text-anchor="middle" fill="#64748b" font-family="Arial" font-size="9">' + esc(String(label || 'Product').slice(0, 18)) + '</text>' +
                '</svg>';
            return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
        }

        function bindImageFallback($scope) {
            $scope.find('img').each(function () {
                var img = this;
                if (img.dataset.pvBound === '1') {
                    return;
                }
                img.dataset.pvBound = '1';
                img.addEventListener('error', function () {
                    img.src = placeholder(img.getAttribute('alt') || 'Product');
                });
                if (img.complete && img.naturalWidth === 0) {
                    img.src = placeholder(img.getAttribute('alt') || 'Product');
                }
            });
        }

        function showAlert(message) {
            var $alert = $root.find('[data-alert]');
            if (!message) {
                $alert.attr('hidden', 'hidden').text('');
                return;
            }
            $alert.removeAttr('hidden').text(message);
            $alert[0].scrollIntoView({behavior: 'smooth', block: 'center'});
        }

        function togglePanel($panel, shouldShow) {
            if (shouldShow) {
                $panel.removeAttr('hidden');
            } else {
                $panel.attr('hidden', 'hidden');
            }
        }

        function setError(name, message) {
            var $error = $root.find('[data-error="' + name + '"]');
            var $field = $root.find('[data-field="' + name + '"], [data-card-field="' + name + '"]');
            $error.text(message || '');
            $field.toggleClass('is-error', !!message);
        }

        function clearErrors() {
            $root.find('[data-error]').text('');
            $root.find('.is-error').removeClass('is-error');
            showAlert('');
        }

        function populateCities() {
            var html = '<option value="">Chọn tỉnh/thành phố</option>';
            ADDRESS_DATA.forEach(function (city) {
                html += '<option value="' + esc(city.name) + '">' + esc(city.name) + '</option>';
            });
            $root.find('[data-province-select]').html(html).val(state.province);
            populateDistricts();
        }

        function populateDistricts() {
            var city = ADDRESS_DATA.find(function (row) { return row.name === state.province; });
            var $district = $root.find('[data-district-select]');
            var $ward = $root.find('[data-ward-select]');
            if (!city) {
                $district.html('<option value="">Chọn tỉnh/thành phố trước</option>').prop('disabled', true);
                $ward.html('<option value="">Chọn quận/huyện trước</option>').prop('disabled', true);
                renderShippingMethods();
                renderSummary();
                return;
            }
            var html = '<option value="">Chọn quận/huyện</option>';
            city.districts.forEach(function (district) {
                html += '<option value="' + esc(district) + '">' + esc(district) + '</option>';
            });
            $district.html(html).prop('disabled', false).val(state.district);
            populateWards();
            renderShippingMethods();
            renderSummary();
        }

        function populateWards() {
            var $ward = $root.find('[data-ward-select]');
            if (!state.district) {
                $ward.html('<option value="">Chọn quận/huyện trước</option>').prop('disabled', true);
                return;
            }
            var html = '<option value="">Chọn phường/xã</option>';
            getWardOptions(state.province, state.district).forEach(function (ward) {
                html += '<option value="' + esc(ward) + '">' + esc(ward) + '</option>';
            });
            $ward.html(html).prop('disabled', false).val(state.ward);
        }

        function getWardOptions(city, district) {
            if (DISTRICT_WARDS[city + '|' + district] && DISTRICT_WARDS[city + '|' + district].length) {
                return DISTRICT_WARDS[city + '|' + district];
            }
            if (WARD_MAP[district]) {
                return WARD_MAP[district];
            }
            if (/huyện/i.test(district)) {
                return ['Thị trấn ' + district.replace(/^Huyện\s+/i, ''), 'Xã Trung tâm', 'Xã Đông', 'Xã Tây', 'Xã Nam'];
            }
            if (/thị xã/i.test(district)) {
                return ['Phường Trung tâm', 'Phường 1', 'Phường 2', 'Xã ven đô'];
            }
            if (/thành phố|tp\.?/i.test(district)) {
                return ['Phường Trung tâm', 'Phường 1', 'Phường 2', 'Phường 3', 'Xã ngoại thành'];
            }
            return WARDS;
        }

        function fillCustomerDefaults() {
            var customer = bootstrap.customer || {};
            var address = customer.address || {};
            if (!state.fullName && customer.full_name) { state.fullName = customer.full_name; }
            if (!state.email && customer.email) { state.email = customer.email; }
            if (!state.phone && customer.phone) { state.phone = customer.phone; }
            if (!state.addressLine1 && address.street) { state.addressLine1 = address.street; }
        }

        function syncFields() {
            $root.find('[data-field]').each(function () {
                var $field = $(this);
                var key = $field.data('field');
                if ($field.attr('type') === 'checkbox') {
                    $field.prop('checked', !!state[key]);
                } else {
                    $field.val(state[key] || '');
                }
            });
            $root.find('[data-card-field]').each(function () {
                var $field = $(this);
                $field.val(state.card[$field.data('card-field')] || '');
            });
        }

        function renderShippingMethods() {
            var html = '';
            SHIPPING_METHODS.forEach(function (method) {
                var selected = method.id === state.shippingMethodId;
                var price = quoteShippingPrice(method);
                var surchargeNote = shippingDistanceSurcharge() > 0 ? ' (+phụ phí khoảng cách)' : '';
                html += '<button type="button" class="pvco3-ship-card' + (selected ? ' is-selected' : '') + '" data-shipping-method="' + esc(method.id) + '">' +
                    '<span class="pvco3-ship-radio"></span>' +
                    '<span class="pvco3-ship-info">' +
                    '<span class="pvco3-ship-name">' + esc(method.name) + esc(surchargeNote) + '</span>' +
                    '<span class="pvco3-ship-desc">' + esc(method.description) + '</span>' +
                    '<span class="pvco3-ship-eta">Dự kiến: ' + esc(method.eta) + '</span>' +
                    '</span>' +
                    '<span class="pvco3-ship-price">' + formatVND(price) + '</span>' +
                    '</button>';
            });
            $root.find('[data-ship-list]').html(html);
        }

        function renderPaymentMethods() {
            var selected = state.paymentMethodId;
            $root.find('[data-pm-card]').each(function () {
                var pm = $(this).data('pm');
                $(this).toggleClass('is-selected', pm === selected);
            });
            var $next = $root.find('[data-payment-next]');
            if (selected) {
                $next.removeClass('is-disabled').prop('disabled', false);
            } else {
                $next.addClass('is-disabled').prop('disabled', true);
            }
        }

        function renderSummary() {
            var html = '';
            cartItems().forEach(function (item) {
                var rowTotal = parseFloat(item.row_total || ((item.price || 0) * (item.qty || 1)) || 0);
                var image = item.image_url || placeholder(item.name);
                html += '<div class="pvco3-sidebar-item">' +
                    '<img class="pvco3-sidebar-item-img" src="' + esc(image) + '" alt="' + esc(item.name) + '" loading="lazy"/>' +
                    '<div class="pvco3-sidebar-item-body">' +
                    '<p class="pvco3-sidebar-item-name">' + esc(item.name) + '</p>' +
                    '<div class="pvco3-sidebar-item-meta">' +
                    '<span>x ' + esc(item.qty || 1) + '</span>' +
                    '<strong>' + formatVND(rowTotal) + '</strong>' +
                    '</div></div></div>';
            });
            $root.find('[data-sidebar-items]').html(html || '<p style="color:#94a3b8;font-size:14px">Giỏ hàng trống.</p>');
            bindImageFallback($root.find('[data-sidebar-items]'));

            var shipping = selectedShipping();
            $root.find('[data-sidebar-subtotal]').text(formatVND(subtotal()));
            $root.find('[data-sidebar-count]').text(itemCount());
            $root.find('[data-sidebar-grand]').text(formatVND(total()));
            if (shipping) {
                $root.find('[data-sidebar-shipping]').text(formatVND(shipping.price));
                $root.find('[data-sidebar-shipping-row]').removeAttr('hidden');
                $root.find('[data-sidebar-carrier]').text(shipping.name);
                $root.find('[data-sidebar-carrier-row]').removeAttr('hidden');
            } else {
                $root.find('[data-sidebar-shipping-row]').attr('hidden', 'hidden');
                $root.find('[data-sidebar-carrier-row]').attr('hidden', 'hidden');
            }
        }

        function stepMeta(step) {
            return [
                null,
                ['Thông tin nhận hàng', 'Địa chỉ & bên vận chuyển'],
                ['Phương thức thanh toán', 'COD / Bank / Card / Wallet'],
                ['Xác nhận', 'Kiểm tra đơn hàng'],
                ['Xác nhận thanh toán', 'QR / payment URL'],
                ['Hoàn thành', 'Đơn hàng thành công']
            ][step];
        }

        function goToStep(step) {
            state.step = step;
            state.maxUnlockedStep = Math.max(state.maxUnlockedStep, step);
            
            
            
            if (!state.cartSnapshotLocked) {
                var liveItems = ((bootstrap.cart || {}).items || []);
                if (liveItems.length) {
                    state.cartSnapshot = liveItems.slice();
                    state.cartSnapshotSubtotal = parseFloat((bootstrap.cart || {}).subtotal || 0) || state.cartSnapshotSubtotal;
                }
            }
            
            
            
            if (step === 5) {
                try {
                    $(window).trigger('pvCartCountChanged', [0]);
                    customerData.invalidate(['cart']);
                    customerData.reload(['cart'], true);
                } catch (e) {}
            }
            saveState();

            var $layout  = $root.find('[data-checkout-layout]');
            var $qr      = $root.find('[data-qr-screen]');
            var $success = $root.find('[data-success-screen]');
            var $panels  = $root.find('[data-panel]');

            $layout.attr('hidden', 'hidden');
            $qr.attr('hidden', 'hidden');
            $success.attr('hidden', 'hidden');
            $panels.attr('hidden', 'hidden');

            if (step <= 3) {
                $layout.removeAttr('hidden');
                $root.find('[data-panel="' + step + '"]').removeAttr('hidden');
            } else if (step === 4) {
                $qr.removeAttr('hidden');
                renderPaymentConfirmation();
            } else {
                $success.removeAttr('hidden');
                renderComplete();
            }

            renderStepper();
            renderSummary();
            if (step === 3) { renderReview(); }
            element.scrollIntoView({behavior: 'smooth', block: 'start'});
        }

        function renderStepper() {
            $root.find('[data-step-indicator]').each(function () {
                var $item = $(this);
                var step = parseInt($item.data('step-indicator'), 10);
                var $circle = $item.find('[data-circle]');
                $item.removeClass('is-active is-completed is-disabled');
                if (step < state.step) {
                    $item.addClass('is-completed');
                    $circle.html('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="16" height="16"><path d="M20 6 9 17l-5-5"/></svg>');
                } else if (step === state.step) {
                    $item.addClass('is-active');
                    $circle.text(step);
                } else {
                    $item.addClass(step <= state.maxUnlockedStep ? '' : 'is-disabled');
                    $circle.text(step);
                }
            });
            $root.find('[data-connector]').each(function () {
                var $c = $(this);
                var parts = ($c.data('connector') + '').split('-');
                var fromStep = parseInt(parts[0], 10);
                $c.toggleClass('is-done', fromStep < state.step);
            });
            $root.find('[data-progress-fill]').css('width', ((state.step / 5) * 100) + '%');
        }

        function validateInformation() {
            var ok = true;
            clearErrors();
            if ((state.fullName || '').trim().length < 2) { setError('fullName', 'Vui lòng nhập họ và tên'); ok = false; }
            if (!/^[0-9]{9,11}$/.test((state.phone || '').trim())) { setError('phone', 'Số điện thoại không hợp lệ'); ok = false; }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((state.email || '').trim())) { setError('email', 'Email không hợp lệ'); ok = false; }
            if ((state.addressLine1 || '').trim().length < 5) { setError('addressLine1', 'Vui lòng nhập địa chỉ'); ok = false; }
            if (!state.province) { setError('province', 'Vui lòng chọn tỉnh/thành phố'); ok = false; }
            if (!state.district) { setError('district', 'Vui lòng chọn quận/huyện'); ok = false; }
            if (!state.ward) { setError('ward', 'Vui lòng chọn phường/xã'); ok = false; }
            focusFirstError();
            return ok;
        }

        function validateCarrier() {
            var ok = true;
            clearErrors();
            if (!state.shippingMethodId) {
                setError('shippingMethod', 'Vui lòng chọn bên vận chuyển');
                ok = false;
            }
            focusFirstError();
            return ok;
        }

        function validatePayment() {
            clearErrors();
            if (!state.paymentMethodId) {
                setError('paymentMethod', 'Vui lòng chọn phương thức thanh toán');
                focusFirstError();
                return false;
            }
            return true;
        }

        function focusFirstError() {
            var $first = $root.find('[data-error]').filter(function () { return !!$(this).text(); }).first();
            if ($first.length) {
                $first[0].scrollIntoView({behavior: 'smooth', block: 'center'});
                $first.closest('[class*="field"], [class*="pvco3-field"]').find('input,select,textarea').first().trigger('focus');
            }
        }

        function renderReview() {
            var shipping = selectedShipping();
            var payment = selectedPayment();
            var addressParts = [state.addressLine1, state.addressLine2, state.ward, state.district, state.province].filter(Boolean);

            $root.find('[data-review-address]').html(
                '<p><strong>' + esc(state.fullName) + '</strong></p>' +
                '<p>' + esc(state.phone) + ' &nbsp;·&nbsp; ' + esc(state.email) + '</p>' +
                '<p>' + esc(addressParts.join(', ')) + '</p>'
            );

            $root.find('[data-review-shipping]').html(
                shipping
                    ? '<p><strong>' + esc(shipping.name) + '</strong> &nbsp;·&nbsp; ' + formatVND(shipping.price) + '</p>' +
                      '<p style="color:#64748b;font-size:13px">' + esc(shipping.description) + '</p>' +
                      '<p style="color:#64748b;font-size:13px">Dự kiến: ' + esc(shipping.eta) + '</p>'
                    : '<p style="color:#94a3b8">Chưa chọn bên vận chuyển.</p>'
            );

            $root.find('[data-review-payment]').html(
                payment
                    ? '<p><strong>' + esc(payment.title) + '</strong></p>' + renderPaymentReviewDetail()
                    : '<p style="color:#94a3b8">Chưa chọn phương thức thanh toán.</p>'
            );

            var productRows = '';
            cartItems().forEach(function (item) {
                var rowTotal = parseFloat(item.row_total || ((item.price || 0) * (item.qty || 1)) || 0);
                productRows += '<div class="pvco3-review-item">' +
                    '<img class="pvco3-review-item-img" src="' + esc(item.image_url || placeholder(item.name)) + '" alt="' + esc(item.name) + '"/>' +
                    '<div class="pvco3-review-item-body"><p class="pvco3-review-item-name">' + esc(item.name) + '</p>' +
                    '<p style="color:#64748b;font-size:13px">x ' + esc(item.qty || 1) + '</p></div>' +
                    '<strong>' + formatVND(rowTotal) + '</strong></div>';
            });
            $root.find('[data-review-items]').html(productRows || '<p style="color:#94a3b8;font-size:14px">Giỏ hàng trống.</p>');
            bindImageFallback($root.find('[data-review-items]'));

            $root.find('[data-review-totals]').html(
                '<div class="pvco3-review-total-row"><span>Tạm tính</span><strong>' + formatVND(subtotal()) + '</strong></div>' +
                '<div class="pvco3-review-total-row"><span>Phí vận chuyển</span><strong>' + formatVND(shipping ? shipping.price : 0) + '</strong></div>' +
                '<div class="pvco3-review-total-row pvco3-review-total-row--grand"><span>Tổng cộng</span><strong>' + formatVND(total()) + '</strong></div>'
            );
        }

        function renderPaymentReviewDetail() {
            var pm = state.paymentMethodId;
            if (pm === 'bank_qr') {
                var bank = BANKS.find(function (row) { return row.id === state.bankId; }) || BANKS[0];
                return '<p style="color:#64748b;font-size:13px">Ngân hàng: ' + esc(bank.name) + '<br>Quét QR sau khi xác nhận đơn</p>';
            }
            if (pm === 'momo') {
                return '<p style="color:#64748b;font-size:13px">Ví MoMo — Thanh toán qua QR/app sau khi xác nhận đơn</p>';
            }
            if (pm === 'vnpay') {
                return '<p style="color:#64748b;font-size:13px">VNPay — Thanh toán qua cổng VNPay sau khi xác nhận đơn</p>';
            }
            if (pm === 'card') {
                return '<p style="color:#64748b;font-size:13px">PayPal — Thanh toán bằng tài khoản PayPal sau khi xác nhận đơn</p>';
            }
            if (pm === 'cod') {
                return '<p style="color:#64748b;font-size:13px">Thanh toán khi nhận hàng</p>';
            }
            return '';
        }

        var BRAND_THEME = {
            bank_qr: {
                label: 'QR Ngân hàng',
                pill:  'BIDV VietQR',
                bar:   'pvco3-pch-bar--bank_qr',
                icon:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="m3 9 9-6 9 6v1H3z"/><rect x="5" y="10" width="14" height="8"/><path d="M3 18h18"/></svg>',
                pillIcon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="m3 9 9-6 9 6v1H3z"/><rect x="5" y="10" width="14" height="8"/></svg>',
                hint:  'Mở app ngân hàng → Quét QR → Xác nhận'
            },
            momo: {
                label: 'Ví MoMo',
                pill:  'MoMo',
                bar:   'pvco3-pch-bar--momo',
                icon:  '<span style="font-size:18px;font-weight:900">M</span>',
                pillIcon: '<span style="font-size:13px;font-weight:900;color:#a50064">M</span>',
                hint:  'Mở app MoMo → Quét QR → Xác nhận'
            },
            vnpay: {
                label: 'VNPay',
                pill:  'VNPay',
                bar:   'pvco3-pch-bar--vnpay',
                icon:  '<span style="font-size:14px;font-weight:900">VN</span>',
                pillIcon: '<span style="font-size:11px;font-weight:900;color:#005BAA">VN</span>',
                hint:  'Bấm nút thanh toán hoặc quét QR để mở cổng VNPay'
            },
            card: {
                label: 'PayPal',
                pill:  'PayPal',
                bar:   'pvco3-pch-bar--card',
                icon:  '<span style="font-size:14px;font-weight:900">PP</span>',
                pillIcon: '<span style="font-size:11px;font-weight:900;color:#003087">PP</span>',
                hint:  'Mở cổng PayPal sandbox để hoàn tất thanh toán'
            },
            cod: {
                label: 'COD',
                pill:  '',
                bar:   'pvco3-pch-bar--cod',
                icon:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="2" y="6" width="20" height="13" rx="2"/><path d="M8 11h8M8 15h5"/></svg>',
                pillIcon: '',
                hint:  ''
            }
        };

        function renderPaymentConfirmation() {
            var pm     = state.paymentMethodId;
            var order  = state.order || {};
            var pay    = order.payment || {};
            var amount = parseInt(pay.amount, 10) || parseInt(state.orderTotal, 10) || total();
            var theme  = BRAND_THEME[pm] || BRAND_THEME.bank_qr;
            var vnpayUrl = paymentRedirectUrl(pay);
            var realVnpay = isRealVnpayPayment(pay);
            var realPaypal = isPaypalPayment(pay);
            var directGateway = realVnpay || realPaypal;
            var $payConfirmWrap = $root.find('[data-pay-confirm-wrap]');
            var $vnpayDirect = $root.find('[data-vnpay-direct]');

            $root.find('[data-pch-bar]').attr('class', 'pvco3-pch-bar ' + theme.bar);
            $root.find('[data-pch-icon]').html(theme.icon);
            $root.find('[data-pch-provider]').text(theme.label);
            $root.find('[data-pch-amount]').text(formatVND(amount));
            $root.find('[data-pch-amount-2]').text(formatVND(amount));
            $root.find('[data-pcp-brand-name]').text(theme.pill);
            $root.find('[data-pcp-brand-icon]').html(theme.pillIcon);
            $root.find('[data-pcp-hint]').text(theme.hint);

            var instructions = pay.instructions || {};
            var ref = realVnpay
                ? (pay.provider_order_id || pay.reference || order.incrementId || String(order.orderId || '').replace(/^#/, ''))
                : (instructions.transfer_reference
                   || pay.reference
                   || pay.qr_payload
                   || ('ORD' + String(order.orderId || '').replace(/[^0-9]/g, '')));

            if (directGateway) {
                var gatewayName = realPaypal ? 'PayPal' : 'VNPay';
                var gatewayCode = realPaypal ? 'PP' : 'VN';
                var vnpayFailed = state.paymentStatus === 'failed';
                $payConfirmWrap.attr('hidden', 'hidden').hide();
                $vnpayDirect.removeAttr('hidden').show();
                $vnpayDirect.toggleClass('is-failed', vnpayFailed);
                $root.find('[data-direct-mark-code]').text(gatewayCode);
                $root.find('[data-direct-mark-label]').text(gatewayName);
                $root.find('[data-direct-title]').text('Thanh toán trên ' + gatewayName);
                $root.find('[data-direct-copy]').text(realPaypal
                    ? 'Bấm nút bên dưới để mở PayPal. Website dùng Business account để nhận tiền; khi PayPal mở ra, hãy đăng nhập bằng Personal buyer để test thanh toán.'
                    : 'Bấm nút bên dưới để mở cổng VNPay chính thức. Sau khi thanh toán xong, VNPay sẽ đưa bạn quay lại Techieworld và hệ thống tự xác nhận bằng IPN.');
                
                
                $root.find('[data-paypal-accounts]').attr('hidden', 'hidden').hide();
                $root.find('[data-direct-open-label]').text('Mở ' + gatewayName);
                $root.find('[data-vnpay-direct-open]').attr('href', vnpayUrl || '#');
                $root.find('[data-vnpay-direct-order]').text(ref || order.incrementId || order.orderId || '—');
                $root.find('[data-vnpay-direct-amount]').text(formatVND(amount));
                $root.find('[data-vnpay-direct-status]').text(vnpayFailed
                    ? gatewayName + ' báo giao dịch chưa thành công. Bạn có thể mở lại sandbox để thử lại.'
                    : 'Đang chờ ' + gatewayName + ' xác nhận giao dịch');
                _copyValues['amount'] = String(Math.round(amount));
                _copyValues['ref'] = ref;
                clearPaymentOverlay();

                if (pm !== 'cod') {
                    startCountdown(paymentExpirySeconds(pay, 30 * 60));
                    fetchPvPaymentData();
                    startPaymentEventStream();
                }
                return;
            }

            $vnpayDirect.attr('hidden', 'hidden').hide();
            $vnpayDirect.removeClass('is-failed');
            $payConfirmWrap.removeAttr('hidden').show();

            $root.find('[data-pcp-ref]').text(ref);
            $root.find('[data-pcp-bank-name]').text(instructions.bank_name || 'BIDV');
            $root.find('[data-pcp-bank-holder]').text(instructions.account_name || 'NGUYEN VAN A');
            $root.find('[data-pcp-bank-number]').text(instructions.account_number || '0000000000');
            $root.find('[data-pcp-ref-label]').text('Nội dung chuyển khoản');
            $root.find('[data-pcp-bank-info]').show();
            var $vnpayActions = $root.find('[data-pcp-vnpay-actions]');
            $vnpayActions.attr('hidden', 'hidden').hide();
            $root.find('[data-pcp-vnpay-open]').attr('href', '#');
            $root.find('[data-pcp-warning]').text('⚠ Vui lòng giữ nguyên số tiền và nội dung — hệ thống sẽ tự động xác nhận trong vài giây.');
            $root.find('[data-pcp-autodetect-text]').text('Đang chờ thanh toán — tự động xác nhận sau khi nhận tiền');
            $root.find('[data-pcp-badges]').html('<span>🔒 SSL 256-bit</span><span>VietQR</span><span>Napas 247</span>');

            _copyValues['amount'] = String(Math.round(amount));
            _copyValues['ref']    = ref;

            var $qrImg = $root.find('[data-pcp-qr-img]');
            var qr = pay.qr_code_url || pay.qrCodeUrl || '';
            if (!qr) {
                
                
                
                
                var scanUrl = window.location.origin + '/api/qr/scanpaid?order=' +
                              encodeURIComponent(ref);
                qr = qrProxyUrl(scanUrl, 540);
            }
            $root.find('[data-pcp-qr-loading]').show();
            $qrImg.attr('hidden', 'hidden').removeAttr('src');
            setQrImage($qrImg, qr, '', 'Payment QR');
            $qrImg.one('load', function () {
                $root.find('[data-pcp-qr-loading]').hide();
                $qrImg.removeAttr('hidden').show();
            });

            clearPaymentOverlay();

            if (pm !== 'cod') {
                startCountdown(paymentExpirySeconds(pay, 30 * 60));
                fetchPvPaymentData();
                startPaymentEventStream();
                if (state.paymentStatus === 'failed') {
                    showStatusOverlay('failed');
                }
            }
        }

        function startCountdown(seconds) {
            stopCountdown();
            seconds = parseInt(seconds, 10);
            if (isNaN(seconds) || seconds <= 0) {
                seconds = hasPendingRealVnpayPayment() ? (30 * 60) : 0;
            }
            _countdownSecs = seconds;
            function tick() {
                var m = Math.floor(_countdownSecs / 60);
                var s = _countdownSecs % 60;
                var txt = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
                $root.find('[data-countdown]').text(txt)
                    .toggleClass('is-warning', _countdownSecs <= 120 && _countdownSecs > 0)
                    .toggleClass('is-ok', _countdownSecs > 120);
                if (_countdownSecs <= 0) {
                    stopCountdown();
                    stopPaymentPolling();
                    if (hasPendingRealVnpayPayment()) {
                        $root.find('[data-countdown]').text('--:--').removeClass('is-warning is-ok');
                        startPaymentPolling();
                        return;
                    }
                    showStatusOverlay('expired');
                    return;
                }
                _countdownSecs--;
                _countdownTimer = window.setTimeout(tick, 1000);
            }
            tick();
        }

        function stopCountdown() {
            if (_countdownTimer) { window.clearTimeout(_countdownTimer); _countdownTimer = null; }
        }

        function startPaymentEventStream() {
            stopPaymentPolling();
            if (!state.order || !state.order.orderId) { return; }
            var orderId = (state.order.incrementId || state.order.orderId || '').replace(/^#/, '');
            if (!orderId) { return; }
            var eventsUrl = ((bootstrap.endpoints || {}).payment_events || '/api/paymentSessions/events') +
                '?orderId=' + encodeURIComponent(orderId);
            if (_eventSource) { _eventSource.close(); _eventSource = null; }
            try {
                _eventSource = new window.EventSource(eventsUrl);
            } catch (e) {
                startPaymentPolling();
                return;
            }
             
            startPaymentPolling();

            _eventSource.addEventListener('status', function (e) {
                var data;
                try { data = JSON.parse(e.data); } catch (err) { return; }
                var status = (data.status || '').toLowerCase();
                state.paymentStatus = status;
                saveState();
                if (status === 'paid') {
                    _eventSource.close(); _eventSource = null;
                    stopCountdown(); stopPaymentPolling();
                    showStatusOverlay('paid');
                    window.setTimeout(function () { goToStep(5); }, 500);
                } else if (status === 'failed' || status === 'cancelled') {
                    _eventSource.close(); _eventSource = null;
                    stopCountdown(); stopPaymentPolling();
                    showStatusOverlay('failed');
                } else if (status === 'expired') {
                    _eventSource.close(); _eventSource = null;
                    stopCountdown(); stopPaymentPolling();
                    showStatusOverlay('expired');
                }
            });
            _eventSource.onerror = function () {
                if (_eventSource) { _eventSource.close(); _eventSource = null; }
                 
            };
        }

        function startPaymentPolling() {
            stopPaymentPolling();
            if (!state.order || !state.order.orderId) { return; }
            var incId = (state.order.incrementId || state.order.orderId || '').replace(/^#/, '');
            if (!incId) { return; }
            var endpoint = '/api/payments/pvstatus';
            function poll() {
                $.ajax({
                    url: endpoint,
                    method: 'GET',
                    dataType: 'json',
                    data: {orderId: incId}
                }).done(function (res) {
                    var status = (res.status || '').toLowerCase();
                    if (state.paymentStatus !== 'paid' || status === 'paid') {
                        state.paymentStatus = status;
                    }
                    if (res.pv_order_id) { state.order.pvOrderId = res.pv_order_id; }
                    saveState();
                    if (status === 'paid') {
                        if (_eventSource) { try { _eventSource.close(); } catch (e) {} _eventSource = null; }
                        stopCountdown(); stopPaymentPolling();
                        if (state.step !== 5) {
                            showStatusOverlay('paid');
                            window.setTimeout(function () { goToStep(5); }, 500);
                        }
                    } else if (status === 'failed' || status === 'cancelled') {
                        stopCountdown(); stopPaymentPolling();
                        showStatusOverlay('failed');
                    } else if (status === 'expired') {
                        stopCountdown(); stopPaymentPolling();
                        showStatusOverlay('expired');
                    } else {
                        _pollTimer = window.setTimeout(poll, 2000);
                    }
                }).fail(function () {
                    _pollTimer = window.setTimeout(poll, 5000);
                });
            }
             
            _pollTimer = window.setTimeout(poll, 500);
        }

        function stopPaymentPolling() {
            if (_pollTimer) { window.clearTimeout(_pollTimer); _pollTimer = null; }
            if (_eventSource) { _eventSource.close(); _eventSource = null; }
        }

        function fetchPvPaymentData() {
            if (!state.order || !state.order.orderId) { return; }
            $.ajax({
                url: '/api/payments/pvstatus',
                method: 'GET',
                dataType: 'json',
                data: {orderId: state.order.orderId.replace(/^#/, '')}
            }).done(function (res) {
                if (!res.success) { return; }
                var status = String(res.status || '').toLowerCase();
                if (status && state.paymentStatus !== 'paid') {
                    state.paymentStatus = status;
                }
                restoreCartSnapshotFromResponse(res);
                if (res.payment) {
                    state.order.payment = $.extend({}, state.order.payment || {}, res.payment);
                }
                if (res.pv_order_id) {
                    state.order.pvOrderId = res.pv_order_id;
                }
                saveState();
                if (res.transfer_code && !isRealVnpayPayment(state.order.payment || {})) {
                    $root.find('[data-pcp-ref]').text(res.transfer_code);
                    _copyValues['ref'] = res.transfer_code;
                }
                var expirySource = res.expires_at_epoch || res.expiresAt || res.expires_at;
                if (res.payment && (res.payment.expires_at_epoch || res.payment.expiresAtEpoch || res.payment.expires_at || res.payment.expiresAt)) {
                    expirySource = res.payment.expires_at_epoch || res.payment.expiresAtEpoch || res.payment.expires_at || res.payment.expiresAt;
                }
                if (expirySource) {
                    var expiryPayment = $.extend({}, state.order.payment || {});
                    if (!expiryPayment.expires_at_epoch && !expiryPayment.expiresAtEpoch && !expiryPayment.expires_at && !expiryPayment.expiresAt) {
                        expiryPayment.expires_at_epoch = expirySource;
                    }
                    var secsLeft = paymentExpirySeconds(expiryPayment, 30 * 60);
                    if (secsLeft > 0) { stopCountdown(); startCountdown(secsLeft); }
                }
                renderSummary();
            });
        }


        function showStatusOverlay(status) {
            var html = '';
            if (status === 'paid') {
                html = '<div class="pvco3-pcp-overlay-icon" style="color:#16a34a">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="64" height="64"><circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-5"/></svg>' +
                    '</div><h3 style="margin:12px 0 6px;color:#15803d">Thanh toán thành công!</h3>' +
                    '<p style="color:#64748b;font-size:14px">Đang chuyển hướng về trang hoàn thành…</p>';
            } else if (status === 'failed') {
                html = '<div class="pvco3-pcp-overlay-icon" style="color:#dc2626">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="64" height="64"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>' +
                    '</div><h3 style="margin:12px 0 6px;color:#dc2626">Thanh toán thất bại</h3>' +
                    '<p style="color:#64748b;font-size:14px;margin-bottom:16px">Giao dịch không thành công. Vui lòng thử lại.</p>' +
                    '<button type="button" class="pvco3-btn pvco3-btn--primary" data-goto-step="2">Chọn lại phương thức</button>';
            } else {
                html = '<div class="pvco3-pcp-overlay-icon" style="color:#b45309">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="64" height="64"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>' +
                    '</div><h3 style="margin:12px 0 6px;color:#b45309">Giao dịch hết hạn</h3>' +
                    '<p style="color:#64748b;font-size:14px;margin-bottom:16px">Mã QR đã hết hạn. Vui lòng đặt lại đơn hàng.</p>' +
                    '<button type="button" class="pvco3-btn pvco3-btn--outline" data-goto-step="1">Đặt lại</button>';
            }
            $root.find('[data-pcp-overlay-inner]').html(html);
            $root.find('[data-pcp-overlay]').removeAttr('hidden');
        }

        function renderComplete() {
            var shipping = selectedShipping();
            var payment = selectedPayment();
            var orderId = state.order && state.order.orderId ? state.order.orderId : '#SHOP-' + new Date().getFullYear() + '-0001';
            var purchaseCode = (state.order && state.order.purchaseCode) ? state.order.purchaseCode : '';
            $root.find('[data-success-order-number]').text(orderId);
            $root.find('[data-success-order-date]').text('Ngày đặt: ' + new Date().toLocaleDateString('vi-VN'));
             
            var $pcBox = $root.find('[data-success-purchase-code]');
            if ($pcBox.length) {
                if (purchaseCode) {
                    $pcBox.find('[data-pc-value]').text(purchaseCode);
                    $pcBox.removeAttr('hidden');
                } else {
                    $pcBox.attr('hidden', 'hidden');
                }
            }
            $root.find('[data-success-info]').html(
                '<div class="pvco3-review-total-row"><span>Sản phẩm</span><strong>' + itemCount() + ' sản phẩm</strong></div>' +
                '<div class="pvco3-review-total-row"><span>Tổng tiền</span><strong>' + formatVND(total()) + '</strong></div>' +
                '<div class="pvco3-review-total-row"><span>Thanh toán</span><strong>' + esc(payment ? payment.title : '') + '</strong></div>' +
                '<div class="pvco3-review-total-row"><span>Vận chuyển</span><strong>' + esc(shipping ? shipping.name : '') + '</strong></div>' +
                '<div class="pvco3-review-total-row"><span>Dự kiến</span><strong>' + esc(shipping ? shipping.eta.replace(/ \(.+\)/, '') : '') + '</strong></div>'
            );
        }

        function placeOrder() {
            if (isSubmitting) { return; }
            clearErrors();
            isSubmitting = true;
            stopCountdown();
            stopPaymentPolling();
            clearPaymentOverlay();
            var $btn = $root.find('[data-place-order]');
            $btn.addClass('is-loading').prop('disabled', true);
            $btn.find('.pvco3-place-label').attr('hidden', 'hidden');
            $btn.find('.pvco3-place-loading').removeAttr('hidden');
            var payment = selectedPayment();
            var shipping = selectedShipping();
            var pm = state.paymentMethodId;
            var gatewayChannel = {
                bank_qr: 'bank_qr', momo: 'momo', vnpay: 'vnpay', card: 'paypal', cod: ''
            }[pm] || '';
            var payload = {
                form_key: checkoutFormKey(),
                full_name: state.fullName,
                email: state.email,
                phone: state.phone,
                address: [state.addressLine1, state.addressLine2].filter(Boolean).join(', '),
                street: [state.addressLine1, state.addressLine2].filter(Boolean).join(', '),
                city: state.province,
                region: state.district,
                postcode: '700000',
                country_id: 'VN',
                receiving_method: 'delivery',
                payment_method: payment ? payment.providerCode : '',
                shipping_method: shipping ? shipping.id : '',
                note: state.specialInstructions,
                bank_id: state.bankId,
                wallet_id: pm,
                gateway_channel: gatewayChannel,
                shipping_quote_amount: shipping ? shipping.price : 0
            };
            $.ajax({
                url: (bootstrap.endpoints || {}).place_order || '',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload)
            }).done(function (response) {
                 
                var realIncId = String(response.increment_id || '').replace(/^#/, '');
                var displayId = realIncId
                    ? '#' + realIncId
                    : ('#PV' + Math.random().toString(36).slice(2, 6).toUpperCase() + Math.floor(1000 + Math.random() * 9000));
                state.order = {
                    orderId: displayId,
                    incrementId: realIncId,
                    purchaseCode: response.purchase_code || '',
                    payment: response.payment || {},
                    shipping: response.shipping || {}
                };
                state.paymentStatus = (response.payment && response.payment.status) ? response.payment.status : 'pending';
                 
                state.cartSnapshot = cartItems().slice();
                state.cartSnapshotLocked = true;
                state.orderTotal = parseInt(response.payment && response.payment.amount, 10) || total();
                saveState();
                 
                if (state.paymentMethodId !== 'cod') {
                    try {
                        var incId = (response.increment_id || '').replace(/^#/, '');
                        if (incId && window.history && window.history.replaceState) {
                            window.history.replaceState({}, '', '/payment-confirmation?orderId=' + encodeURIComponent(incId));
                        }
                    } catch (e) {}
                }
                 
                if (state.paymentMethodId === 'cod') {
                    goToStep(5);
                    return;
                }
                goToStep(4);
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};
                showAlert(response.message || 'Đặt hàng thất bại. Vui lòng kiểm tra lại thông tin.');
            }).always(function () {
                isSubmitting = false;
                var $b = $root.find('[data-place-order]');
                $b.removeClass('is-loading').prop('disabled', false);
                $b.find('.pvco3-place-label').removeAttr('hidden');
                $b.find('.pvco3-place-loading').attr('hidden', 'hidden');
            });
        }

        function checkPaymentStatus() {
            if (!state.order || !state.order.orderId) { return; }
            startPaymentEventStream();
        }

        function bindEvents() {
            $root.on('input change', '[data-field]', function () {
                var $field = $(this);
                var key = $field.data('field');
                state[key] = $field.attr('type') === 'checkbox' ? $field.is(':checked') : $field.val();
                if (key === 'province') { state.district = ''; state.ward = ''; populateDistricts(); }
                if (key === 'district') { state.ward = ''; populateWards(); renderShippingMethods(); renderSummary(); }
                saveState();
            });
            $root.on('input change', '[data-card-field]', function () {
                state.card[$(this).data('card-field')] = $(this).val();
                saveState();
            });
            $root.on('click', '[data-shipping-method]', function () {
                state.shippingMethodId = $(this).data('shipping-method');
                saveState();
                renderShippingMethods();
                renderSummary();
            });
            $root.on('click', '[data-pm-card]', function () {
                state.paymentMethodId = $(this).data('pm');
                saveState();
                renderPaymentMethods();
            });
            $root.on('click', '[data-pcp-copy]', function () {
                var $btn = $(this);
                var key  = $btn.data('pcp-copy');
                var val  = _copyValues[key] || $root.find('[data-pcp-' + key.replace(/_/g, '-') + ']').text() || '';
                if (!val) { return; }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(val).catch(function () {});
                }
                $btn.addClass('is-copied').attr('title', 'Đã sao chép!');
                window.setTimeout(function () { $btn.removeClass('is-copied').attr('title', 'Sao chép'); }, 1400);
            });
            $root.on('click', '[data-copy-pc]', function () {
                var $btn = $(this);
                var val = $root.find('[data-pc-value]').text().trim();
                if (!val) { return; }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(val).catch(function () {});
                }
                $btn.addClass('is-copied').attr('title', 'Đã sao chép!');
                window.setTimeout(function () { $btn.removeClass('is-copied').attr('title', 'Sao chép'); }, 1400);
            });
            $root.on('click', '[data-copy-button]', function () {
                var $button = $(this);
                var val = $button.data('copy-value') || $root.find($button.data('copy-source') || '').text() || '';
                if (!val) { return; }
                if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(val).catch(function () {}); }
                $button.text('Copied');
                window.setTimeout(function () { $button.text('Copy'); }, 1200);
            });
            $root.on('click', '[data-next-step]', function () {
                var next = parseInt($(this).data('next-step'), 10);
                if (next === 2 && (!validateInformation() || !validateCarrier())) { return; }
                if (next === 3 && !validatePayment()) { return; }
                stopCountdown(); stopPaymentPolling();
                goToStep(next);
            });
            $root.on('click', '[data-prev-step]', function () {
                stopCountdown(); stopPaymentPolling();
                goToStep(parseInt($(this).data('prev-step'), 10));
            });
            $root.on('click', '[data-goto-step]', function () {
                var targetStep = parseInt($(this).data('goto-step'), 10);
                resetPendingPaymentState(targetStep);
                stopCountdown(); stopPaymentPolling();
                goToStep(targetStep);
            });
            $root.on('click', '[data-step-indicator]', function () {
                var step = parseInt($(this).data('step-indicator'), 10);
                if (step <= state.maxUnlockedStep) { stopCountdown(); stopPaymentPolling(); goToStep(step); }
            });
            $root.on('click', '[data-edit-step]', function () {
                var step = parseInt($(this).data('edit-step'), 10);
                var shouldScrollShipping = !!$(this).data('scroll-shipping');
                stopCountdown(); stopPaymentPolling();
                goToStep(step);
                if (shouldScrollShipping) {
                    window.setTimeout(function () {
                        var el = document.getElementById('pv-shipping-method-section');
                        if (el) { el.scrollIntoView({behavior: 'smooth', block: 'start'}); }
                    }, 120);
                }
            });
            $root.on('click', '[data-complete-track]', function () {
                var orderId = state.order && state.order.orderId ? state.order.orderId : '';
                var shipping = selectedShipping();
                var provider = shipping && shipping.provider ? shipping.provider : 'spx';
                window.location.href = '/order-tracking?order_id=' + encodeURIComponent(orderId.replace(/^#/, '')) + '&provider=' + encodeURIComponent(provider);
            });
            $root.on('click', '[data-place-order]', placeOrder);
            $root.on('click', '[data-check-payment-status]', checkPaymentStatus);
            $root.on('click', '[data-download-invoice]', function () {
                var orderId   = state.order && state.order.orderId ? state.order.orderId : '—';
                var orderDate = new Date().toLocaleDateString('vi-VN', {day:'2-digit', month:'2-digit', year:'numeric'});
                var shipping  = selectedShipping();
                var payment   = selectedPayment();
                var items     = cartItems();
                var sub       = subtotal();
                var shipPrice = shipping ? quoteShippingPrice(shipping) : 0;
                var grandTotal = state.orderTotal || total();

                var address = [state.addressLine1, state.addressLine2, state.ward, state.district, state.province]
                    .filter(Boolean).join(', ');

                var itemRows = '';
                items.forEach(function (item) {
                    var qty   = item.qty || 1;
                    var price = parseFloat(item.price || 0);
                    var row   = parseFloat(item.row_total || (price * qty));
                    itemRows +=
                        '<tr>' +
                        '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9">' + esc(item.name) + '</td>' +
                        '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;text-align:center">' + qty + '</td>' +
                        '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;text-align:right">' + formatVND(price) + '</td>' +
                        '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;text-align:right">' + formatVND(row) + '</td>' +
                        '</tr>';
                });

                var purchaseCode = state.order && state.order.purchaseCode ? state.order.purchaseCode : '';
                var purchaseCodeRow = purchaseCode
                    ? '<tr><td colspan="3" style="padding:8px 12px;text-align:right;color:#64748b">Mã bảo hành</td>' +
                      '<td style="padding:8px 12px;text-align:right;font-weight:600;color:#0b63d8">' + esc(purchaseCode) + '</td></tr>'
                    : '';

                var html =
                    '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">' +
                    '<title>Hóa đơn ' + esc(orderId) + '</title>' +
                    '<style>' +
                    '*{box-sizing:border-box;margin:0;padding:0}' +
                    'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px;color:#1e293b;background:#fff;padding:32px}' +
                    '.inv-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:32px;padding-bottom:20px;border-bottom:2px solid #0b63d8}' +
                    '.inv-brand{font-size:22px;font-weight:800;color:#0b63d8;letter-spacing:-0.5px}' +
                    '.inv-brand span{display:block;font-size:12px;font-weight:400;color:#64748b;margin-top:2px}' +
                    '.inv-meta{text-align:right;font-size:13px;color:#64748b}' +
                    '.inv-meta strong{display:block;font-size:18px;font-weight:700;color:#1e293b;margin-bottom:4px}' +
                    '.inv-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px}' +
                    '.inv-section h3{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#64748b;margin-bottom:8px}' +
                    '.inv-section p{font-size:13px;color:#1e293b;line-height:1.6}' +
                    'table{width:100%;border-collapse:collapse;margin-bottom:0}' +
                    'thead tr{background:#f8fafc}' +
                    'thead th{padding:10px 12px;text-align:left;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #e2e8f0}' +
                    'thead th:not(:first-child){text-align:right}' +
                    'thead th:nth-child(3){text-align:center}' +
                    '.totals-row td{padding:6px 12px;font-size:13px}' +
                    '.totals-row td:first-child{color:#64748b}' +
                    '.grand-row td{padding:10px 12px;font-size:15px;font-weight:700;border-top:2px solid #0b63d8;color:#0b63d8}' +
                    '.inv-footer{margin-top:32px;padding-top:16px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center}' +
                    '@media print{body{padding:16px}button{display:none!important}.no-print{display:none!important}}' +
                    '</style></head><body>' +
                    '<div class="inv-header">' +
                    '<div class="inv-brand">PV Modern<span>pvmodern.vn</span></div>' +
                    '<div class="inv-meta"><strong>HÓA ĐƠN MUA HÀNG</strong>Mã đơn: ' + esc(orderId) + '<br>Ngày đặt: ' + esc(orderDate) + '</div>' +
                    '</div>' +
                    '<div class="inv-grid">' +
                    '<div class="inv-section"><h3>Thông tin khách hàng</h3>' +
                    '<p><strong>' + esc(state.fullName || '—') + '</strong><br>' +
                    esc(state.phone || '') + (state.email ? '<br>' + esc(state.email) : '') + '<br>' +
                    esc(address || '—') + '</p></div>' +
                    '<div class="inv-section"><h3>Thông tin giao hàng</h3>' +
                    '<p>Phương thức: <strong>' + esc(shipping ? shipping.name : '—') + '</strong><br>' +
                    'Dự kiến: <strong>' + esc(shipping ? shipping.eta : '—') + '</strong><br>' +
                    'Thanh toán: <strong>' + esc(payment ? payment.title : '—') + '</strong></p></div>' +
                    '</div>' +
                    '<table>' +
                    '<thead><tr><th>Sản phẩm</th><th style="text-align:center">SL</th><th style="text-align:right">Đơn giá</th><th style="text-align:right">Thành tiền</th></tr></thead>' +
                    '<tbody>' + itemRows + '</tbody>' +
                    '<tfoot>' +
                    '<tr class="totals-row"><td colspan="3" style="text-align:right;padding:8px 12px;color:#64748b">Tạm tính</td><td style="padding:8px 12px;text-align:right">' + formatVND(sub) + '</td></tr>' +
                    '<tr class="totals-row"><td colspan="3" style="text-align:right;padding:8px 12px;color:#64748b">Phí vận chuyển</td><td style="padding:8px 12px;text-align:right">' + formatVND(shipPrice) + '</td></tr>' +
                    purchaseCodeRow +
                    '<tr class="grand-row"><td colspan="3" style="text-align:right">TỔNG CỘNG</td><td style="text-align:right">' + formatVND(grandTotal) + '</td></tr>' +
                    '</tfoot>' +
                    '</table>' +
                    '<div class="inv-footer">Cảm ơn bạn đã mua hàng tại PV Modern · pvmodern.vn · Mọi thắc mắc vui lòng liên hệ hỗ trợ</div>' +
                    '</body></html>';

                var w = window.open('', '_blank', 'width=800,height=900');
                if (!w) { return; }
                w.document.write(html);
                w.document.close();
                w.focus();
                setTimeout(function () { w.print(); }, 400);
            });
        }

        function isPaymentConfirmationRoute() {
            try {
                if ($root.attr('data-payment-confirmation-only') === '1') { return true; }
                return /\/payment-confirmation(\/|\?|$)/.test(window.location.pathname + window.location.search);
            } catch (e) { return false; }
        }

        function recoverOrderFromUrl(callback) {
            var urlOrderId = '';
            try {
                urlOrderId = new URLSearchParams(window.location.search).get('orderId') || '';
            } catch (e) {}
            if (!urlOrderId) { callback(false); return; }
            $.ajax({
                url: '/api/payments/pvstatus',
                method: 'GET',
                dataType: 'json',
                data: {orderId: urlOrderId}
            }).done(function (res) {
                if (!res || !res.success) { callback(false); return; }
                 
                var realIncId = String(res.orderId || urlOrderId).replace(/^#/, '');
                state.order = state.order || {};
                state.order.orderId = '#' + realIncId;
                state.order.incrementId = realIncId;
                state.order.pvOrderId = res.pv_order_id || state.order.pvOrderId;
                state.order.payment = res.payment || state.order.payment || {};
                if (res.payment && res.payment.amount) {
                    state.orderTotal = parseInt(res.payment.amount, 10);
                } else if (res.total_amount) {
                    state.orderTotal = Math.round(parseFloat(res.total_amount));
                }
                state.paymentMethodId = res.payment_method || state.paymentMethodId || 'bank_qr';
                state.paymentStatus = String(res.status || 'pending').toLowerCase();
                state.step = (state.paymentStatus === 'paid') ? 5 : 4;
                state.maxUnlockedStep = Math.max(state.maxUnlockedStep || 1, state.step);
                restoreCartSnapshotFromResponse(res);
                saveState();
                callback(true);
            }).fail(function () { callback(false); });
        }

        function init() {
            var requestedStep = getRequestedStep();
            var onConfirmationRoute = isPaymentConfirmationRoute();
            var params;
            try {
                params = new URLSearchParams(window.location.search);
                if (params.get('payment_result') === 'failed' && state.order) {
                    state.paymentStatus = 'failed';
                    state.step = 4;
                    state.maxUnlockedStep = Math.max(state.maxUnlockedStep || 1, 4);
                }
            } catch (e) {}
            if (requestedStep === 1 && !onConfirmationRoute) {
                state.step = 1;
                state.maxUnlockedStep = 1;
                state.order = null;
                state.paymentStatus = 'idle';
                saveState();
            }

            function finalizeInit() {
                if (!state.step || state.step < 1 || state.step > 5) {
                    state.step = 1;
                    state.maxUnlockedStep = Math.max(1, state.maxUnlockedStep || 1);
                } else if (state.step === 5 && !state.order) {
                    state.step = 1;
                    state.maxUnlockedStep = 1;
                } else if (state.step === 4 && (!state.order || !state.order.orderId) && !onConfirmationRoute) {
                    state.step = 3;
                    state.maxUnlockedStep = Math.max(3, state.maxUnlockedStep || 3);
                }
                fillCustomerDefaults();
                populateCities();
                loadVietnamLocations();
                syncFields();
                renderShippingMethods();
                renderPaymentMethods();
                renderSummary();
                bindEvents();
                goToStep(state.step || 1);
            }

            if (onConfirmationRoute) {
                state.step = state.step && state.step >= 4 ? state.step : 4;
                state.maxUnlockedStep = Math.max(state.maxUnlockedStep || 1, state.step);
                 
                var urlOrderId = '';
                try {
                    urlOrderId = new URLSearchParams(window.location.search).get('orderId') || '';
                } catch (e) {}
                var stateIncId = state.order && (state.order.incrementId || String(state.order.orderId || '').replace(/^#/, ''));
                if (urlOrderId && stateIncId !== urlOrderId) {
                     
                    state.order = null;
                }
                if (urlOrderId) {
                    recoverOrderFromUrl(function () { finalizeInit(); });
                    return;
                }
                if (!state.order || !state.order.orderId || !state.order.incrementId) {
                    recoverOrderFromUrl(function () { finalizeInit(); });
                    return;
                }
            }
            finalizeInit();
        }

        init();
    };
});
