<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Block\Checkout;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use YourVendor\PVModern\Model\Checkout\CheckoutService;
use YourVendor\PVModern\Model\IntegrationConfig;

class Flow extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly CheckoutService $checkoutService,
        private readonly FormKey $formKey,
        private readonly Json $serializer,
        private readonly IntegrationConfig $integrationConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function getSerializedBootstrap(): string
    {
        $payload = $this->checkoutService->buildCheckoutBootstrap() + [
            'form_key' => $this->getFormKey(),
        ];

        return $this->serializer->serialize($payload);
    }

    public function isPaymentMockMode(): bool
    {
        return $this->integrationConfig->isMockModeEnabled('payment');
    }

    public function isPaymentConfirmationOnly(): bool
    {
        return (bool) $this->getData('is_payment_confirmation_only');
    }

    /**
     * When PVMODERN_PAYMENT_DEMO=true, the storefront is wired against SePay's
     * sandbox. Real bank transfers will NOT auto-confirm — payments are
     * simulated via SePay Test Mode. The step-4 template renders a visible
     * DEMO MODE banner using this flag so testers know what to expect.
     */
    public function isPaymentDemoMode(): bool
    {
        return $this->integrationConfig->getBool('PVMODERN_PAYMENT_DEMO', false);
    }
}
