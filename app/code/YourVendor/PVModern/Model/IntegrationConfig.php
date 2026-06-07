<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model;

class IntegrationConfig
{
    /** @var array<string, string>|null */
    private static ?array $fileEnvCache = null;
    /** @var int Mtime of pvmodern.env when the cache was loaded — lets us
     *  cache-bust automatically when ops edits the file without recycling FPM. */
    private static int $fileEnvCacheMtime = 0;

    public function getString(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            $fileEnv = $this->loadFileEnv();
            if (array_key_exists($key, $fileEnv)) {
                $value = $fileEnv[$key];
            }
        }
        if ($value === false || $value === null) {
            return $default;
        }

        $value = trim((string) $value);
        return $value === '' ? $default : $value;
    }

    /**
     * Load env values from BP/pvmodern.env (KEY=VALUE per line, # comments OK).
     * Cached per FPM worker — reloads automatically when the file's mtime
     * changes so config edits take effect without an FPM reload.
     *
     * @return array<string, string>
     */
    private function loadFileEnv(): array
    {
        $path = BP . '/pvmodern.env';
        $mtime = is_readable($path) ? (int) @filemtime($path) : 0;
        if (self::$fileEnvCache !== null && $mtime === self::$fileEnvCacheMtime) {
            return self::$fileEnvCache;
        }
        self::$fileEnvCache = [];
        self::$fileEnvCacheMtime = $mtime;
        if ($mtime === 0) {
            return self::$fileEnvCache;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '') {
                self::$fileEnvCache[$k] = $v;
            }
        }
        return self::$fileEnvCache;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->getString($key, (string) $default) ?? $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->getString($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function isMockModeEnabled(string $scope): bool
    {
        $global = $this->getBool('PVMODERN_CHECKOUT_MOCK', true);

        if ($scope === 'shipping') {
            return $this->getBool('PVMODERN_SHIPPING_MOCK', $global);
        }

        if ($scope === 'payment') {
            return $this->getBool('PVMODERN_PAYMENT_MOCK', $global);
        }

        return $global;
    }

    public function getAppBaseUrl(): string
    {
        return rtrim((string) ($this->getString('APP_BASE_URL', '') ?? ''), '/');
    }

    public function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $base = $this->getAppBaseUrl();
        if ($base === '') {
            return $path;
        }

        return $base . '/' . ltrim($path, '/');
    }

    public function getShippingToken(string $provider): ?string
    {
        return $this->getString(strtoupper($provider) . '_API_TOKEN');
    }

    public function getShippingShopId(string $provider): ?string
    {
        return $this->getString(strtoupper($provider) . '_SHOP_ID');
    }

    /**
     * @return array<string, string>
     */
    public function getBankTransferDetails(): array
    {
        return [
            'account_name' => $this->getString('PVMODERN_BANK_ACCOUNT_NAME', 'DIEN MANH HUNG') ?? 'DIEN MANH HUNG',
            'account_number' => $this->getString('PVMODERN_BANK_ACCOUNT_NUMBER', '4661104867') ?? '4661104867',
            'bank_name' => $this->getString('PVMODERN_BANK_NAME', 'BIDV') ?? 'BIDV',
            'bank_code' => $this->getString('PVMODERN_BANK_CODE', 'BIDV') ?? 'BIDV',
            'bank_bin' => $this->getString('PVMODERN_BANK_BIN', '970418') ?? '970418',
            'branch' => $this->getString('PVMODERN_BANK_BRANCH', '') ?? '',
            'note_prefix' => $this->getString('PVMODERN_BANK_TRANSFER_PREFIX', 'ORD') ?? 'ORD',
        ];
    }

    public function getStaticQrBaseUrl(): string
    {
        return rtrim($this->getString('PVMODERN_STATIC_QR_BASE_URL', '/media/pvmodern/qr') ?? '/media/pvmodern/qr', '/');
    }

    /**
     * @return array<string, string>
     */
    public function getGatewayConfig(): array
    {
        return [
            'merchant_code' => $this->getString('PVMODERN_GATEWAY_MERCHANT_CODE', 'sandbox-techieworld') ?? 'sandbox-techieworld',
            'public_key' => $this->getString('PVMODERN_GATEWAY_PUBLIC_KEY', 'mock-public-key') ?? 'mock-public-key',
            'callback_url' => $this->getString('PVMODERN_GATEWAY_CALLBACK_URL', '/pvmodern/checkout/callback') ?? '/pvmodern/checkout/callback',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function getMomoConfig(): array
    {
        return [
            'endpoint' => $this->getString('MOMO_CREATE_URL', $this->getString('MOMO_ENDPOINT', 'https://test-payment.momo.vn/v2/gateway/api/create')),
            'query_url' => $this->getString('MOMO_QUERY_URL', 'https://test-payment.momo.vn/v2/gateway/api/query'),
            'partner_code' => $this->getString('MOMO_PARTNER_CODE'),
            'access_key' => $this->getString('MOMO_ACCESS_KEY'),
            'secret_key' => $this->getString('MOMO_SECRET_KEY'),
            'redirect_url' => $this->absoluteUrl((string) $this->getString('MOMO_REDIRECT_URL', $this->getString('PVMODERN_GATEWAY_RETURN_URL', '/pvmodern/checkout/momoReturn'))),
            'ipn_url' => $this->absoluteUrl((string) $this->getString('MOMO_IPN_URL', $this->getString('PVMODERN_GATEWAY_IPN_URL', '/api/webhooks/momo'))),
            'request_type' => $this->getString('MOMO_REQUEST_TYPE', 'captureWallet'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function getVnpayConfig(): array
    {
        return [
            'payment_url' => $this->getString('VNPAY_PAYMENT_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'),
            'tmn_code' => $this->getString('VNPAY_TMN_CODE'),
            'hash_secret' => $this->getString('VNPAY_HASH_SECRET'),
            'return_url' => $this->absoluteUrl((string) $this->getString('VNPAY_RETURN_URL', $this->getString('PVMODERN_GATEWAY_RETURN_URL', '/pvmodern/checkout/vnpayReturn'))),
            'ipn_url' => $this->absoluteUrl((string) $this->getString('VNPAY_IPN_URL', '/api/webhooks_vnpay/ipn')),
            'locale' => $this->getString('VNPAY_LOCALE', 'vn'),
            'expire_minutes' => $this->getString('VNPAY_EXPIRE_MINUTES', '15'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function getStripeConfig(): array
    {
        return [
            'secret_key' => $this->getString('STRIPE_SECRET_KEY'),
            'publishable_key' => $this->getString('STRIPE_PUBLISHABLE_KEY'),
            'webhook_secret' => $this->getString('STRIPE_WEBHOOK_SECRET'),
            'checkout_sessions_url' => $this->getString('STRIPE_CHECKOUT_SESSIONS_URL', 'https://api.stripe.com/v1/checkout/sessions'),
            'api_base_url' => $this->getString('STRIPE_API_BASE_URL', 'https://api.stripe.com/v1'),
            'success_url' => $this->absoluteUrl((string) $this->getString('STRIPE_SUCCESS_URL', '/checkout?payment_result=pending&gateway=stripe')),
            'cancel_url' => $this->absoluteUrl((string) $this->getString('STRIPE_CANCEL_URL', '/checkout?payment_result=failed&gateway=stripe')),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function getPaypalConfig(): array
    {
        return [
            'business_account' => $this->getString('PAYPAL_BUSINESS_ACCOUNT', $this->getString('PAYPAL_SANDBOX_BUSINESS_ACCOUNT')),
            'personal_account' => $this->getString('PAYPAL_PERSONAL_ACCOUNT', $this->getString('PAYPAL_SANDBOX_PERSONAL_ACCOUNT')),
            'payment_url' => $this->getString('PAYPAL_PAYMENT_URL', 'https://www.sandbox.paypal.com/cgi-bin/webscr'),
            'ipn_verify_url' => $this->getString('PAYPAL_IPN_VERIFY_URL', 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr'),
            'return_url' => $this->absoluteUrl((string) $this->getString('PAYPAL_RETURN_URL', '/pvmodern/checkout/paypalReturn')),
            'cancel_url' => $this->absoluteUrl((string) $this->getString('PAYPAL_CANCEL_URL', '/payment-confirmation')),
            'notify_url' => $this->absoluteUrl((string) $this->getString('PAYPAL_NOTIFY_URL', '/api/webhooks/paypalIpn')),
            'currency' => $this->getString('PAYPAL_CURRENCY', 'USD'),
            'vnd_to_usd_rate' => $this->getString('PAYPAL_VND_TO_USD_RATE', '25000'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function getCassoConfig(): array
    {
        return [
            'api_key' => $this->getString('CASSO_API_KEY'),
            'webhook_secret' => $this->getString('CASSO_WEBHOOK_SECRET', $this->getString('PVMODERN_CASSO_TOKEN')),
            'api_base_url' => $this->getString('CASSO_API_BASE_URL', 'https://oauth.casso.vn/v2'),
            'sandbox_mode' => $this->getBool('CASSO_SANDBOX_MODE', false),
        ];
    }
}
