<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Block\Adminhtml\Payments;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\FormKey;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\IntegrationConfig;

class Dashboard extends Template
{
    protected $_template = 'YourVendor_PVModern::payments/dashboard.phtml';

    public function __construct(
        Context $context,
        private readonly PaymentDb $paymentDb,
        private readonly FormKey $formKeyHelper,
        private readonly IntegrationConfig $integrationConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrders(array $filters = []): array
    {
        return $this->paymentDb->listOrders($filters, 200);
    }

    public function countByStatus(string $status): int
    {
        return $this->paymentDb->countOrders($status);
    }

    public function countAll(): int
    {
        return $this->paymentDb->countOrders();
    }

    public function getScreenshotUrl(int $orderId): string
    {
        return $this->getUrl('pvmodern_payments/payments/screenshot', ['id' => $orderId, '_nosecret' => true]);
    }

    public function getApproveUrl(): string
    {
        return $this->getUrl('pvmodern_payments/payments/approve');
    }

    public function getRejectUrl(): string
    {
        return $this->getUrl('pvmodern_payments/payments/reject');
    }

    public function getOrdersJsonUrl(): string
    {
        return $this->getUrl('pvmodern_payments/payments/orders');
    }

    public function getSimulateWebhookUrl(): string
    {
        return $this->getUrl('pvmodern_payments/payments/simulateWebhook');
    }

    public function isMockModeEnabled(): bool
    {
        return $this->integrationConfig->isMockModeEnabled('payment');
    }

    public function getFormKey(): string
    {
        return $this->formKeyHelper->getFormKey();
    }

    public function formatAmount(float $amount): string
    {
        return number_format($amount, 0, ',', '.') . 'đ';
    }
}
