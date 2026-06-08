<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Serialize\Serializer\Json;
use YourVendor\PVModern\Model\Checkout\CheckoutService;

class PlaceOrder implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Json $serializer,
        private readonly FormKey $formKey,
        private readonly CheckoutService $checkoutService
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $payload = $this->readPayload();
            $this->assertFormKey($payload);
            $result->setData($this->checkoutService->placeOrder($payload));
        } catch (LocalizedException $exception) {
            $result->setHttpResponseCode(422)->setData([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => 'Unable to place the order right now.',
            ]);
        }

        return $result;
    }

    
    private function readPayload(): array
    {
        $raw = (string) $this->request->getContent();
        if ($raw === '') {
            return $this->request->getParams();
        }

        $decoded = $this->serializer->unserialize($raw);
        return is_array($decoded) ? $decoded : [];
    }

    
    private function assertFormKey(array $payload): void
    {
        if (($payload['form_key'] ?? '') !== $this->formKey->getFormKey()) {
            throw new LocalizedException(__('Your checkout session expired. Refresh the page and try again.'));
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
