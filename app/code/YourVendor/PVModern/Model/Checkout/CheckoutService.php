<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\PaymentAttemptService;
use YourVendor\PVModern\Model\Payment\PaymentManager;
use YourVendor\PVModern\Model\PurchaseCodeGenerator;
use YourVendor\PVModern\Model\Shipping\PickupLocationProvider;
use YourVendor\PVModern\Model\Shipping\ShippingManager;

class CheckoutService
{
    private const PROCESSING_FLAG = 'pvmodern_checkout_processing';

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CatalogImageHelper $catalogImageHelper,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentManager $paymentManager,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly PurchaseCodeGenerator $purchaseCodeGenerator,
        private readonly ShippingManager $shippingManager,
        private readonly PickupLocationProvider $pickupLocationProvider,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly UrlInterface $urlBuilder,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly IntegrationConfig $integrationConfig
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCheckoutBootstrap(): array
    {
        $quote = $this->checkoutSession->getQuote();
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress = $quote->getBillingAddress();
        $customer = $this->customerSession->getCustomer();

        $fullName = trim((string) implode(' ', array_filter([
            (string) ($shippingAddress->getFirstname() ?: $billingAddress->getFirstname() ?: $customer->getFirstname()),
            (string) ($shippingAddress->getLastname() ?: $billingAddress->getLastname() ?: $customer->getLastname()),
        ])));

        return [
            'endpoints' => [
                'quote' => $this->urlBuilder->getUrl('pvmodern/checkout/quote'),
                'place_order' => $this->urlBuilder->getUrl('pvmodern/checkout/placeOrder'),
                'locations' => $this->urlBuilder->getUrl('pvmodern/api/locations'),
                'payment_status' => $this->urlBuilder->getUrl('api/payments/status'),
                'payment_create' => $this->urlBuilder->getUrl('api/payments/create'),
                'payment_events' => $this->urlBuilder->getUrl('api/paymentSessions/events'),
            ],
            'customer' => [
                'is_logged_in' => $this->customerSession->isLoggedIn(),
                'full_name' => $fullName,
                'email' => (string) ($quote->getCustomerEmail() ?: $customer->getEmail()),
                'phone' => (string) ($shippingAddress->getTelephone() ?: $billingAddress->getTelephone()),
                'address' => [
                    'street' => implode(' ', (array) ($shippingAddress->getStreet() ?: $billingAddress->getStreet())),
                    'city' => (string) ($shippingAddress->getCity() ?: $billingAddress->getCity()),
                    'region' => (string) ($shippingAddress->getRegion() ?: $billingAddress->getRegion()),
                    'postcode' => (string) ($shippingAddress->getPostcode() ?: $billingAddress->getPostcode()),
                    'country_id' => (string) ($shippingAddress->getCountryId() ?: $billingAddress->getCountryId() ?: 'VN'),
                ],
            ],
            'cart' => $this->buildCartSummary(),
            'pickup_locations' => $this->pickupLocationProvider->getLocations(),
            'payment_methods' => $this->paymentManager->getFrontendMethods(['quote' => $quote]),
            'defaults' => [
                'receiving_method' => 'delivery',
                'country_id' => 'VN',
                'note' => '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function quoteShippingOptions(array $payload): array
    {
        $normalized = $this->normalizePayload($payload, false);
        $quote = $this->checkoutSession->getQuote();
        if (!$quote->hasItems()) {
            throw new LocalizedException(__('Your cart is empty.'));
        }

        if ($normalized['receiving_method'] === 'pickup') {
            $location = $this->pickupLocationProvider->getLocation((string) $normalized['pickup_store']);

            return [
                'receiving_method' => 'pickup',
                'shipping_methods' => [[
                    'provider' => 'pickup',
                    'label' => 'Pickup at Techieworld store',
                    'method_code' => 'pvmodernshipping_pickup',
                    'amount' => 0.0,
                    'amount_formatted' => $this->formatPrice(0.0),
                    'eta_label' => 'Ready in 2 hours',
                    'description' => 'Reserve online and pick up in store.',
                    'mock' => true,
                    'store' => $location,
                ]],
                'summary' => $this->buildSummary(0.0),
                'payment_methods' => $this->paymentManager->getFrontendMethods([
                    'receiving_method' => 'pickup',
                    'quote' => $quote,
                ]),
            ];
        }

        $shippingMethods = [];
        foreach ($this->shippingManager->getQuotes([
            'city' => $normalized['city'],
            'region' => $normalized['region'],
            'postcode' => $normalized['postcode'],
            'country_id' => $normalized['country_id'],
            'item_count' => $quote->getItemsQty() ?: count($quote->getAllVisibleItems()),
            'package_weight' => max(0.5, (float) $quote->getItemsQty()),
        ]) as $quoteRow) {
            $shippingMethods[] = $quoteRow + [
                'amount_formatted' => $this->formatPrice((float) $quoteRow['amount']),
            ];
        }

        if (empty($shippingMethods)) {
            return [
                'receiving_method' => 'delivery',
                'shipping_methods' => [],
                'summary' => $this->buildSummary(0.0),
                'payment_methods' => $this->paymentManager->getFrontendMethods([
                    'receiving_method' => 'delivery',
                    'quote' => $quote,
                ]),
                'error' => 'No shipping methods are currently available for this address.',
            ];
        }

        return [
            'receiving_method' => 'delivery',
            'shipping_methods' => $shippingMethods,
            'summary' => $this->buildSummary((float) $shippingMethods[0]['amount']),
            'payment_methods' => $this->paymentManager->getFrontendMethods([
                'receiving_method' => 'delivery',
                'quote' => $quote,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function placeOrder(array $payload): array
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote->hasItems()) {
            throw new LocalizedException(__('Your cart is empty.'));
        }

        if ($this->checkoutSession->getData(self::PROCESSING_FLAG)) {
            throw new LocalizedException(__('Your order is already being processed. Please wait.'));
        }

        $this->checkoutSession->setData(self::PROCESSING_FLAG, true);

        try {
            $normalized = $this->normalizePayload($payload, true);
            [$firstName, $lastName] = $this->splitName($normalized['full_name']);
            $billingData = [
                'firstname' => $firstName,
                'lastname' => $lastName,
                'email' => $normalized['email'],
                'telephone' => $normalized['phone'],
                'street' => [$normalized['street']],
                'city' => $normalized['city'],
                'region' => $normalized['region'],
                'postcode' => $normalized['postcode'],
                'country_id' => $normalized['country_id'],
            ];

            if ($this->customerSession->isLoggedIn()) {
                $quote->setCustomerEmail($normalized['email'] ?: (string) $this->customerSession->getCustomer()->getEmail());
                $quote->setCustomerIsGuest(false);
            } else {
                $quote->setCustomerEmail($normalized['email']);
                $quote->setCustomerFirstname($firstName);
                $quote->setCustomerLastname($lastName);
                $quote->setCustomerIsGuest(true);
                $quote->setCustomerGroupId(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);
                $quote->setCheckoutMethod(CartManagementInterface::METHOD_GUEST);
            }

            $quote->getBillingAddress()->addData($billingData);

            $selectedShipping = null;
            if ($normalized['receiving_method'] === 'pickup') {
                $store = $this->pickupLocationProvider->getLocation($normalized['pickup_store']);
                if (!$store) {
                    throw new LocalizedException(__('Please select a pickup location.'));
                }

                $quote->getShippingAddress()->addData([
                    'firstname' => 'Techieworld',
                    'lastname' => 'Pickup',
                    'company' => $store['name'],
                    'telephone' => $normalized['phone'],
                    'street' => [$store['street']],
                    'city' => $store['city'],
                    'region' => $store['region'],
                    'postcode' => $store['postcode'],
                    'country_id' => $store['country_id'],
                ]);
                $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();
                $quote->getShippingAddress()->setShippingMethod('pvmodernshipping_pickup');
                $quote->getShippingAddress()->setShippingDescription('Pickup at ' . $store['name']);
                $selectedShipping = [
                    'provider' => 'pickup',
                    'label' => 'Pickup at Techieworld store',
                    'amount' => 0.0,
                    'eta_label' => 'Ready in 2 hours',
                ];
            } else {
                $quotesResponse = $this->quoteShippingOptions($normalized);
                $availableMethods = $quotesResponse['shipping_methods'] ?? [];
                foreach ($availableMethods as $method) {
                    if (($method['method_code'] ?? '') === $normalized['shipping_method']) {
                        $selectedShipping = $method;
                        break;
                    }
                }

                if (!$selectedShipping) {
                    throw new LocalizedException(__('Please choose a valid shipping method.'));
                }

                $quote->getShippingAddress()->addData($billingData);
                $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();
                $quote->getShippingAddress()->setShippingMethod((string) $selectedShipping['method_code']);
                $quote->getShippingAddress()->setShippingDescription(
                    sprintf('%s • %s', $selectedShipping['label'], $selectedShipping['eta_label'])
                );
            }

            $paymentProvider = $this->paymentManager->getProvider($normalized['payment_method']);
            if (!$paymentProvider) {
                throw new LocalizedException(__('Please choose a valid payment method.'));
            }

            $quote->getPayment()->importData(['method' => $paymentProvider->getMethodCode()]);
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            $orderId = (int) $this->cartManagement->placeOrder((int) $quote->getId());
            $order = $this->orderRepository->get($orderId);
            $paymentInit = $paymentProvider->initialize([
                'quote' => $quote,
                'order' => $order,
                'amount' => (float) $order->getGrandTotal(),
                'order_increment_id' => $order->getIncrementId(),
                'customer' => $normalized,
                'gateway_channel' => $normalized['gateway_channel'] ?: 'vnpay',
                'bank_id' => $normalized['bank_id'],
                'wallet_id' => $normalized['wallet_id'],
                'card_last4' => $normalized['card_last4'],
            ]);
            $paymentInit = $this->normalizePaymentResponse($paymentInit);
            $paymentInit['amount'] = (int) round((float) ($paymentInit['amount'] ?? $order->getGrandTotal()));
            $frontendPaymentMethod = $this->resolveFrontendPaymentMethod($normalized);
            if ($normalized['payment_method'] !== 'cod') {
                $paymentInit = $this->paymentAttemptService->registerAttemptForOrder(
                    $order,
                    (string) ($paymentInit['provider'] ?? $this->resolveProviderForFrontendMethod($frontendPaymentMethod)),
                    $paymentInit,
                    $frontendPaymentMethod
                );
            }

            $orderPayment = $order->getPayment();
            if ($orderPayment) {
                $orderPayment->setAdditionalInformation('pvmodern_payment_status', $paymentInit['status'] ?? OrderPaymentStatus::PENDING);
                $orderPayment->setAdditionalInformation('pvmodern_payment_context', $this->json->serialize($paymentInit));
                $orderPayment->setAdditionalInformation('gateway_channel', $normalized['gateway_channel']);
                $orderPayment->setAdditionalInformation('wallet_id', $normalized['wallet_id']);
                $orderPayment->setAdditionalInformation('pvmodern_shipping_provider', (string) ($selectedShipping['provider'] ?? 'pickup'));
                $orderPayment->setAdditionalInformation('pvmodern_shipping_context', $this->json->serialize([
                    'order_increment_id' => $order->getIncrementId(),
                    'shipping' => $selectedShipping,
                    'customer' => $normalized,
                ]));
            }

            if (!empty($normalized['note'])) {
                $order->setCustomerNote((string) $normalized['note']);
                $order->setCustomerNoteNotify(false);
            }

            $shipment = null;
            $fulfillImmediately = $normalized['payment_method'] === 'cod';
            if ($fulfillImmediately && ($selectedShipping['provider'] ?? 'pickup') !== 'pickup') {
                $shipment = $this->shippingManager->createShipment((string) $selectedShipping['provider'], [
                    'order_increment_id' => $order->getIncrementId(),
                    'shipping' => $selectedShipping,
                    'customer' => $normalized,
                ]);
                if (!empty($shipment['tracking_number'])) {
                    $order->addCommentToStatusHistory(
                        sprintf(
                            'Delivery order created with %s. Tracking: %s',
                            strtoupper((string) ($selectedShipping['provider'] ?? 'carrier')),
                            (string) $shipment['tracking_number']
                        )
                    );
                }
            } elseif ($fulfillImmediately) {
                $order->addCommentToStatusHistory('Customer selected store pickup.');
            } else {
                $order->addCommentToStatusHistory('Fulfillment is deferred until a verified server-side payment confirmation is received.');
            }

            $order->addCommentToStatusHistory(
                sprintf(
                    'PVModern checkout completed via %s. Payment status: %s.',
                    $paymentProvider->getLabel(),
                    (string) ($paymentInit['status'] ?? OrderPaymentStatus::PENDING)
                )
            );
            $this->orderRepository->save($order);

            $this->checkoutSession->setLastQuoteId((int) $quote->getId());
            $this->checkoutSession->setLastSuccessQuoteId((int) $quote->getId());
            $this->checkoutSession->setLastOrderId((int) $order->getEntityId());
            $this->checkoutSession->setLastRealOrderId((string) $order->getIncrementId());
            $this->checkoutSession->setLastOrderStatus((string) $order->getStatus());

            if ($normalized['payment_method'] !== 'cod') {
                $this->restoreQuoteForPendingPayment($order);
            }

            $purchaseCode = $this->purchaseCodeGenerator->generateFromOrder($order);

            return [
                'success' => true,
                'order_id' => (int) $order->getEntityId(),
                'increment_id' => (string) $order->getIncrementId(),
                'purchase_code' => $purchaseCode,
                'payment' => $paymentInit,
                'shipping' => $selectedShipping,
                'shipment' => $shipment,
                'summary' => [
                    'subtotal' => $this->formatPrice((float) $order->getSubtotal()),
                    'shipping' => $this->formatPrice((float) $order->getShippingAmount()),
                    'grand_total' => $this->formatPrice((float) $order->getGrandTotal()),
                ],
                'success_url' => $this->urlBuilder->getUrl('checkout/onepage/success'),
            ];
        } catch (\Throwable $exception) {
            $this->logger->error('[PVModern][Checkout] placeOrder failed', ['message' => $exception->getMessage()]);

            if ($exception instanceof LocalizedException) {
                throw $exception;
            }

            throw new LocalizedException(__('We could not place the order. Please review your checkout details and try again.'));
        } finally {
            $this->checkoutSession->unsetData(self::PROCESSING_FLAG);
        }
    }

    private function restoreQuoteForPendingPayment(Order $order): void
    {
        try {
            if (!$order->getQuoteId()) {
                return;
            }

            if ($this->checkoutSession->restoreQuote()) {
                return;
            }

            $quote = $this->cartRepository->get((int) $order->getQuoteId());
            $quote->setIsActive(true)->setReservedOrderId(null);
            $this->cartRepository->save($quote);
            $this->checkoutSession->replaceQuote($quote);
        } catch (\Throwable $exception) {
            $this->logger->warning('[PVModern][Checkout] unable to restore pending-payment quote', [
                'order' => $order->getIncrementId(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCartSummary(): array
    {
        $quote = $this->checkoutSession->getQuote();
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $brand = $product ? $product->getAttributeText('manufacturer') : '';
            $brand = is_array($brand) ? implode(', ', $brand) : (string) $brand;
            $basePrice = $product ? (float) $product->getPrice() : (float) $item->getCalculationPrice();
            $finalPrice = (float) $item->getCalculationPrice();

            $items[] = [
                'id' => (int) $item->getItemId(),
                'name' => (string) $item->getName(),
                'sku' => (string) $item->getSku(),
                'brand' => trim($brand) !== '' ? trim($brand) : 'Techieworld',
                'qty' => (int) $item->getQty(),
                'image_url' => $product ? $this->catalogImageHelper->init($product, 'product_thumbnail_image')->getUrl() : '',
                'price' => $finalPrice,
                'price_formatted' => $this->formatPrice($finalPrice),
                'original_price' => $basePrice,
                'original_price_formatted' => $this->formatPrice($basePrice),
                'discount_percent' => ($basePrice > 0.0 && $basePrice > $finalPrice)
                    ? (int) round((1 - ($finalPrice / $basePrice)) * 100)
                    : 0,
                'row_total' => (float) $item->getRowTotal(),
                'row_total_formatted' => $this->formatPrice((float) $item->getRowTotal()),
            ];
        }

        $itemCount = 0;
        foreach ($items as $itemRow) {
            $itemCount += (int) ($itemRow['qty'] ?? 0);
        }

        return [
            'count' => $itemCount,
            'items' => $items,
            'subtotal' => (float) $quote->getSubtotal(),
            'subtotal_formatted' => $this->formatPrice((float) $quote->getSubtotal()),
            'grand_total' => (float) $quote->getGrandTotal(),
            'grand_total_formatted' => $this->formatPrice((float) $quote->getGrandTotal()),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function normalizePayload(array $payload, bool $requireFinalSelections): array
    {
        $receivingMethod = $this->normalizeString($payload['receiving_method'] ?? 'delivery');
        $street = $this->normalizeString($payload['address'] ?? $payload['street'] ?? '');

        $normalized = [
            'full_name' => $this->normalizeString($payload['full_name'] ?? ''),
            'email' => $this->normalizeString($payload['email'] ?? ''),
            'phone' => $this->normalizeString($payload['phone'] ?? ''),
            'street' => $street,
            'city' => $this->normalizeString($payload['city'] ?? 'Ho Chi Minh City'),
            'region' => $this->normalizeString($payload['region'] ?? 'Ho Chi Minh'),
            'postcode' => $this->normalizeString($payload['postcode'] ?? '700000'),
            'country_id' => strtoupper($this->normalizeString($payload['country_id'] ?? 'VN')),
            'receiving_method' => in_array($receivingMethod, ['pickup', 'delivery'], true) ? $receivingMethod : 'delivery',
            'pickup_store' => $this->normalizeString($payload['pickup_store'] ?? ''),
            'payment_method' => $this->normalizeString($payload['payment_method'] ?? ''),
            'shipping_method' => $this->normalizeString($payload['shipping_method'] ?? ''),
            'bank_id' => $this->normalizeString($payload['bank_id'] ?? ''),
            'wallet_id' => $this->normalizeString($payload['wallet_id'] ?? ''),
            'gateway_channel' => $this->normalizeString($payload['gateway_channel'] ?? $payload['wallet_id'] ?? ''),
            'card_last4' => $this->normalizeString($payload['card_last4'] ?? ''),
            'note' => $this->normalizeString($payload['note'] ?? ''),
        ];

        if ($normalized['full_name'] === '') {
            throw new LocalizedException(__('Please enter your full name.'));
        }
        if ($normalized['email'] === '' || !filter_var($normalized['email'], FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Please enter a valid email address.'));
        }
        if ($normalized['phone'] === '') {
            throw new LocalizedException(__('Please enter your phone number.'));
        }

        if ($normalized['receiving_method'] === 'delivery' && $normalized['street'] === '') {
            throw new LocalizedException(__('Please enter your shipping address.'));
        }
        if ($normalized['receiving_method'] === 'pickup' && $normalized['pickup_store'] === '') {
            throw new LocalizedException(__('Please choose a pickup location.'));
        }

        if ($requireFinalSelections) {
            if ($normalized['payment_method'] === '') {
                throw new LocalizedException(__('Please choose a payment method.'));
            }
            if ($normalized['receiving_method'] === 'delivery' && $normalized['shipping_method'] === '') {
                throw new LocalizedException(__('Please choose a shipping method.'));
            }
        }

        return $normalized;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        if (count($parts) <= 1) {
            return [$parts[0] ?? 'Customer', ''];
        }

        $lastName = (string) array_pop($parts);
        return [trim(implode(' ', $parts)), $lastName];
    }

    /**
     * @return array<string, string>
     */
    private function buildSummary(float $shippingAmount): array
    {
        $quote = $this->checkoutSession->getQuote();
        $subtotal = (float) $quote->getSubtotal();
        $grandTotal = $subtotal + $shippingAmount;

        return [
            'subtotal' => $this->formatPrice($subtotal),
            'shipping' => $this->formatPrice($shippingAmount),
            'grand_total' => $this->formatPrice($grandTotal),
        ];
    }

    private function formatPrice(float $amount): string
    {
        $formatted = (string) $this->priceCurrency->format($amount, false);
        return $this->stripRedundantDecimals($formatted);
    }

    /**
     * Strip the redundant ",00" / ".00" decimal tail that Magento appends for
     * VND-style integer currencies (e.g. "10.000,00 ₫" → "10.000 ₫").
     * Anchored to either end-of-string or a currency suffix so that
     * thousands separators (which always have more digits after them) survive.
     */
    private function stripRedundantDecimals(string $formatted): string
    {
        return (string) preg_replace('/[.,]0+(?=\s|[^\d.,]|$)/', '', $formatted);
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function normalizePaymentResponse(array $payment): array
    {
        $paymentUrl = (string) ($payment['paymentUrl'] ?? $payment['payment_url'] ?? $payment['redirect_url'] ?? '');
        $qrCodeUrl = (string) ($payment['qrCodeUrl'] ?? $payment['qr_code_url'] ?? '');
        $qrPayload = (string) ($payment['qrPayload'] ?? $payment['qr_payload'] ?? $paymentUrl);

        if ($qrCodeUrl === '' && ($qrPayload !== '' || $paymentUrl !== '')) {
            $qrCodeUrl = $this->buildQrCodeUrl($qrPayload !== '' ? $qrPayload : $paymentUrl);
        }

        if ($paymentUrl !== '') {
            $payment['paymentUrl'] = $paymentUrl;
            $payment['payment_url'] = $paymentUrl;
            $payment['redirect_url'] = $paymentUrl;
        }

        if ($qrPayload !== '') {
            $payment['qrPayload'] = $qrPayload;
            $payment['qr_payload'] = $qrPayload;
        }

        if ($qrCodeUrl !== '') {
            $payment['qrCodeUrl'] = $qrCodeUrl;
            $payment['qr_code_url'] = $qrCodeUrl;
        }

        return $payment;
    }

    /**
     * Build a same-origin QR image URL. If the payload looks like a bare ORD
     * transfer code (no scheme), wrap it into the demo scanpaid URL so phones
     * scanning the QR can actually open something. Routes through our
     * /api/qr/url proxy so CSP `img-src 'self'` always permits the image.
     */
    private function buildQrCodeUrl(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $payload)) {
            // Bare reference like "ORD000000076" — turn it into a scannable URL
            // pointing at the demo scanpaid endpoint on the current store host.
            $base = rtrim((string) $this->urlBuilder->getBaseUrl(['_secure' => true]), '/');
            $payload = $base . '/api/qr/scanpaid?order=' . rawurlencode($payload);
        }
        return '/api/qr/url?size=540&data=' . rawurlencode($payload);
    }

    /**
     * @param array<string, string> $normalized
     */
    private function resolveFrontendPaymentMethod(array $normalized): string
    {
        $walletId = strtolower((string) ($normalized['wallet_id'] ?? ''));
        if (in_array($walletId, ['momo', 'vnpay', 'card', 'bank_qr'], true)) {
            return $walletId;
        }

        if (($normalized['payment_method'] ?? '') === 'bank_transfer') {
            return 'bank_qr';
        }

        return (string) ($normalized['payment_method'] ?? '');
    }

    private function resolveProviderForFrontendMethod(string $frontendPaymentMethod): string
    {
        return match ($frontendPaymentMethod) {
            'momo' => 'momo',
            'vnpay' => 'vnpay',
            'card' => 'paypal',
            'bank_qr' => 'bank_transfer',
            default => $frontendPaymentMethod,
        };
    }

    private function normalizeString(mixed $value): string
    {
        return trim((string) $value);
    }
}
