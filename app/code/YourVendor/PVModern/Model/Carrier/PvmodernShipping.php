<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use YourVendor\PVModern\Model\Shipping\ShippingManager;

class PvmodernShipping extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'pvmodernshipping';

    protected $_isFixed = false;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        private readonly \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        private readonly \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        private readonly ShippingManager $shippingManager,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        
        $result = $this->rateResultFactory->create();

        $quotes = $this->shippingManager->getQuotes([
            'city' => (string) $request->getDestCity(),
            'region' => (string) $request->getDestRegionCode(),
            'postcode' => (string) $request->getDestPostcode(),
            'country_id' => (string) ($request->getDestCountryId() ?: 'VN'),
            'item_count' => (int) $request->getPackageQty(),
            'package_weight' => (float) $request->getPackageWeight(),
        ]);

        foreach ($quotes as $quote) {
            $result->append($this->buildMethod($quote));
        }

        $result->append($this->buildMethod([
            'provider' => 'pickup',
            'label' => 'Pickup at Techieworld store',
            'method_code' => 'pvmodernshipping_pickup',
            'amount' => 0.0,
            'currency' => 'USD',
            'eta_label' => 'Ready in 2 hours',
            'eta_min' => 0,
            'eta_max' => 0,
            'description' => 'Reserve online and collect directly from a Techieworld location.',
            'mock' => true,
        ]));

        return $result;
    }

    public function getAllowedMethods(): array
    {
        return [
            'ghn' => 'Giao Hang Nhanh (GHN)',
            'ghtk' => 'Giao Hang Tiet Kiem (GHTK)',
            'spx' => 'Shopee Express (SPX)',
            'pickup' => 'Pickup at Techieworld store',
        ];
    }

    
    private function buildMethod(array $quote): \Magento\Quote\Model\Quote\Address\RateResult\Method
    {
        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));
        $methodCode = str_replace($this->_code . '_', '', (string) $quote['method_code']);
        $method->setMethod($methodCode);
        $method->setMethodTitle(sprintf('%s • %s', (string) $quote['label'], (string) $quote['eta_label']));
        $method->setPrice((float) $quote['amount']);
        $method->setCost((float) $quote['amount']);

        return $method;
    }
}
