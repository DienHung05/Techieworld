define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $form = $(element),
            $summary = $('[data-pvcart-summary]'),
            updateTimer = null,
            isSubmitting = false,
            checkoutStorageKey = 'pvmodern_checkout_v3',
            promoCodes = {
                SAVE10: {type: 'percent', value: 0.10},
                SAVE20: {type: 'percent', value: 0.20},
                SAVE50K: {type: 'fixed', value: 50000}
            };

        function readCheckoutState() {
            try {
                return JSON.parse(window.sessionStorage.getItem(checkoutStorageKey) || '{}');
            } catch (e) {
                return {};
            }
        }

        function writeCheckoutState(nextState) {
            var currentState = readCheckoutState();
            try {
                window.sessionStorage.setItem(
                    checkoutStorageKey,
                    JSON.stringify($.extend({}, currentState, nextState))
                );
            } catch (e) {}
        }

        function getCurrencyMeta() {
            var sample = String($summary.find('[data-summary-grand]').text() || '');

            if (sample.indexOf('$') !== -1) {
                return {locale: 'en-US', currency: 'USD', digits: 2};
            }

            return {locale: 'vi-VN', currency: 'VND', digits: 0};
        }

        function formatMoney(amount) {
            var meta = getCurrencyMeta();

            return new Intl.NumberFormat(meta.locale, {
                style: 'currency',
                currency: meta.currency,
                minimumFractionDigits: meta.digits,
                maximumFractionDigits: meta.digits
            }).format(parseFloat(amount || 0));
        }

        function escapeSvgText(value) {
            return String(value || 'Techieworld')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .slice(0, 64);
        }

        function placeholderImage(label) {
            var text = escapeSvgText(label || 'Product'),
                svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240" viewBox="0 0 240 240">' +
                    '<rect width="240" height="240" rx="18" fill="#f3f4f6"/>' +
                    '<rect x="48" y="60" width="144" height="104" rx="16" fill="#ffffff" stroke="#d1d5db" stroke-width="2"/>' +
                    '<circle cx="92" cy="104" r="16" fill="#dbeafe"/>' +
                    '<path d="M64 152l42-42 26 26 20-20 24 36H64z" fill="#bfdbfe"/>' +
                    '<text x="120" y="204" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" font-weight="700" fill="#111827">' + text + '</text>' +
                    '</svg>';

            return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
        }

        function attachImageFallbacks($scope) {
            $scope.find('.pvcart-item-thumb img').each(function () {
                var img = this,
                    label,
                    fallback;

                if (img.dataset.pvcartFallbackBound === '1') {
                    return;
                }

                img.dataset.pvcartFallbackBound = '1';
                label = img.getAttribute('alt') ||
                    $(img).closest('.pvcart-item-wrap').find('.pvcart-item-name').text() ||
                    'Product';
                fallback = placeholderImage(label);

                img.addEventListener('error', function () {
                    if (img.src !== fallback) {
                        img.src = fallback;
                    }
                });

                if (img.complete && img.naturalWidth === 0) {
                    img.src = fallback;
                }
            });
        }

        function calculatePromoDiscount(code, subtotal) {
            var normalized = String(code || '').trim().toUpperCase(),
                promo = promoCodes[normalized];

            if (!promo) {
                return null;
            }

            if (promo.type === 'percent') {
                return Math.round(subtotal * promo.value * 100) / 100;
            }

            return promo.value;
        }

        function renderPromoState() {
            var checkoutState = readCheckoutState(),
                subtotal = parseFloat($summary.data('subtotal') || 0),
                shipping = parseFloat($summary.data('shipping') || 0),
                serverDiscount = parseFloat($summary.data('discount') || 0),
                discount = parseFloat(checkoutState.discountAmount || 0),
                grandTotal = Math.max(0, subtotal + shipping - serverDiscount - discount),
                discountCode = String(checkoutState.discountCode || '').trim(),
                $discountRow = $summary.find('[data-summary-discount-row]'),
                $discountValue = $summary.find('[data-summary-discount]'),
                $grandValue = $summary.find('[data-summary-grand]'),
                $promoInput = $form.find('[data-promo-input]'),
                $promoFeedback = $form.find('[data-promo-feedback]'),
                $promoApply = $form.find('[data-promo-apply]'),
                $promoRemove = $form.find('[data-promo-remove]'),
                $promoRemoveCard = $form.find('[data-promo-remove-card]'),
                $promoApplied = $form.find('[data-promo-applied]'),
                $promoAppliedCode = $form.find('[data-promo-applied-code]'),
                $promoAppliedNote = $form.find('[data-promo-applied-note]'),
                $promoInline = $form.find('.pvcart-coupon-inline'),
                $promoHint = $form.find('.pvcart-coupon-hint').not('.pvcart-coupon-hint--inside'),
                hasClientPromo = discount > 0 && !!discountCode;

            if ($grandValue.length) {
                $grandValue.text(formatMoney(grandTotal));
            }

            if ($discountRow.length) {
                if (serverDiscount > 0 || hasClientPromo) {
                    $discountRow.removeAttr('hidden');
                    $discountValue.text('-' + formatMoney(serverDiscount + discount));
                } else {
                    $discountRow.attr('hidden', 'hidden');
                    $discountValue.text('');
                }
            }

            if (!$promoInput.length) {
                return;
            }

            if (hasClientPromo) {
                $promoInline.attr('hidden', 'hidden');
                $promoHint.attr('hidden', 'hidden');
                $promoApplied.removeAttr('hidden');
                $promoAppliedCode.text('Mã: ' + discountCode);
                $promoAppliedNote.text('Giảm ' + formatMoney(discount));
                $promoInput.val(discountCode).prop('disabled', true);
                $promoApply.attr('hidden', 'hidden');
                $promoRemove.attr('hidden', 'hidden');
                $promoRemoveCard.removeAttr('hidden');
                $promoFeedback
                    .text('Mã ' + discountCode + ' đang được áp dụng.')
                    .addClass('is-visible')
                    .removeAttr('hidden');
            } else {
                $promoApplied.attr('hidden', 'hidden');
                $promoInline.removeAttr('hidden');
                $promoHint.removeAttr('hidden');
                $promoInput.prop('disabled', false);
                if (!$form.find('[data-promo-box]').data('server-coupon')) {
                    $promoInput.val('');
                }
                $promoApply.removeAttr('hidden');
                if (!$form.find('[data-promo-box]').data('server-coupon')) {
                    $promoRemove.attr('hidden', 'hidden');
                    $promoRemoveCard.attr('hidden', 'hidden');
                    $promoFeedback.removeClass('is-visible').attr('hidden', 'hidden').text('');
                } else {
                    $promoApplied.removeAttr('hidden');
                    $promoInline.attr('hidden', 'hidden');
                    $promoHint.attr('hidden', 'hidden');
                    $promoAppliedCode.text('Mã: ' + $promoInput.val());
                    $promoAppliedNote.text('Khuyến mãi đã được áp dụng cho đơn hàng của bạn');
                    $promoRemove.attr('hidden', 'hidden');
                    $promoRemoveCard.removeAttr('hidden');
                }
            }
        }

         
        function scheduleUpdate() {
            clearTimeout(updateTimer);
            updateTimer = window.setTimeout(function () {
                if (isSubmitting) { return; }
                isSubmitting = true;
                $form.addClass('is-updating');
                $form.find('.action.update').trigger('click');
            }, 300);
        }

         
        $form.on('click', '[data-qty-step]', function () {
            var step       = parseFloat($(this).data('qty-step')) || 0,
                $btn       = $(this),
                $input     = $btn.siblings('label').find('[data-role="cart-item-qty"]'),
                currentVal, nextVal;

            
            if (!$input.length) {
                $input = $btn.closest('.pvcart-stepper').find('[data-role="cart-item-qty"]');
            }

            if (!$input.length) { return; }

            currentVal = parseFloat($input.val()) || 0;
            nextVal    = Math.max(1, currentVal + step);
            $input.val(nextVal).trigger('change');
        });

         
        $form.on('change', '[data-role="cart-item-qty"]', function () {
            var $input = $(this),
                value  = parseFloat($input.val()) || 1;
            $input.val(Math.max(1, value));
            scheduleUpdate();
        });

         
        $form.on('submit', function () {
            isSubmitting = true;
            $form.addClass('is-updating');
        });

         
        $form.on('click', '[data-promo-apply]', function (event) {
            var $input = $form.find('[data-promo-input]'),
                code = String($input.val() || '').trim().toUpperCase(),
                subtotal = parseFloat($summary.data('subtotal') || 0),
                discount = calculatePromoDiscount(code, subtotal),
                $feedback = $form.find('[data-promo-feedback]');

            if (!code) {
                event.preventDefault();
                event.stopImmediatePropagation();
                $feedback
                    .text('Vui lòng nhập mã khuyến mãi.')
                    .addClass('is-visible')
                    .removeAttr('hidden');
                return;
            }

            if (discount === null) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            writeCheckoutState({
                discountCode: code,
                discountAmount: discount
            });

            renderPromoState();
        });

        $form.on('click', '[data-promo-remove], [data-promo-remove-card]', function (event) {
            var hasServerCoupon = !!$form.find('[data-promo-box]').data('server-coupon');

            if (hasServerCoupon) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            writeCheckoutState({
                discountCode: '',
                discountAmount: 0
            });

            renderPromoState();
        });

        $form.on('keydown', '[data-promo-input]', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                $form.find('[data-promo-apply]').trigger('click');
            }
        });

        attachImageFallbacks($form);
        renderPromoState();
    };
});
