<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Payment\Provider;

use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\Checkout\OrderPaymentStatus;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\VietQrBuilder;

class BankTransferPaymentProvider extends AbstractPaymentProvider
{
    public function __construct(
        IntegrationConfig $integrationConfig,
        LoggerInterface $logger,
        private readonly VietQrBuilder $vietQrBuilder
    ) {
        parent::__construct($integrationConfig, $logger);
    }

    public function getCode(): string
    {
        return 'bank_transfer';
    }

    public function getLabel(): string
    {
        return 'Bank Transfer / VietQR';
    }

    public function getMethodCode(): string
    {
        return 'pvmodern_banktransfer';
    }

    public function getInitialStatus(): string
    {
        return OrderPaymentStatus::AWAITING_PAYMENT;
    }

    public function describeCheckoutMethod(array $context = []): array
    {
        $details = $this->integrationConfig->getBankTransferDetails();

        return parent::describeCheckoutMethod($context) + [
            'description' => 'Manual bank transfer with VietQR-style payment instructions.',
            'account_name' => $details['account_name'],
            'bank_name' => $details['bank_name'],
            'account_number' => $details['account_number'],
            'branch' => $details['branch'],
            'note_prefix' => $details['note_prefix'],
        ];
    }

    public function initialize(array $context): array
    {
        $details = $this->integrationConfig->getBankTransferDetails();
        $increment = $context['order_increment_id'] ?? 'PENDING';
        $transferCode = 'ORD' . preg_replace('/[^0-9]/', '', (string) $increment);
        if ($transferCode === 'ORD') {
            $transferCode = sprintf('ORD%d', time());
        }

        $amount = max(0, (int) round((float) ($context['amount'] ?? 0)));
        $qrCodeUrl = $this->vietQrBuilder->buildUrl($amount, $transferCode);

        return [
            'status' => $this->getInitialStatus(),
            'label' => $this->getLabel(),
            'provider' => 'bank_transfer',
            'reference' => $transferCode,
            'provider_order_id' => $transferCode,
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
            'qr_code_url' => $qrCodeUrl,
            'qr_payload' => $transferCode,
            'qr_channel_brand' => 'bank_qr',
            'amount' => $amount,
            'instructions' => [
                'account_name' => $details['account_name'],
                'account_number' => $details['account_number'],
                'bank_name' => $details['bank_name'],
                'branch' => $details['branch'],
                'transfer_reference' => $transferCode,
            ],
            'mock' => false,
        ];
    }
}
