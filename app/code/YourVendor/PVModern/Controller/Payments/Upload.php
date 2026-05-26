<?php
declare(strict_types=1);
namespace YourVendor\PVModern\Controller\Payments;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\App\DeploymentConfig;
use YourVendor\PVModern\Helper\PaymentDb;

class Upload implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const ALLOWED_EXT  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly DirectoryList $directoryList,
        private readonly PaymentDb $paymentDb,
        private readonly DeploymentConfig $deploymentConfig
    ) {}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException { return null; }
    public function validateForCsrf(RequestInterface $request): ?bool { return true; }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        return $result->setHttpResponseCode(410)->setData([
            'success' => false,
            'message' => 'Screenshot upload has been removed. Payments are now confirmed automatically via Casso webhook.',
        ]);

        $pvOrderId = (int)($this->request->getParam('pv_order_id') ?? 0);
        if ($pvOrderId <= 0) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'Invalid order']);
        }

        $pvOrder = $this->paymentDb->findById($pvOrderId);
        if (!$pvOrder) {
            return $result->setHttpResponseCode(404)->setData(['success' => false, 'message' => 'Order not found']);
        }
        if (!in_array($pvOrder['payment_method'], ['momo', 'vnpay', 'bank_qr', 'card'], true)) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'Upload not supported for this payment method']);
        }
        if ($pvOrder['payment_status'] === 'paid') {
            return $result->setData(['success' => true, 'message' => 'Already paid']);
        }

        // Validate uploaded file
        $files = $this->request->getFiles();
        $file = $files['file'] ?? null;
        if (!$file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'No file uploaded or upload error']);
        }
        if ($file['size'] > self::MAX_BYTES) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'File exceeds 5MB limit']);
        }

        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'Only JPG, PNG, WEBP images allowed']);
        }
        $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $ext = in_array($origExt, self::ALLOWED_EXT, true) ? $origExt : 'jpg';

        // Generate secure token + save path
        $token = bin2hex(random_bytes(24)); // 48-char hex
        $pubRoot = $this->directoryList->getPath('pub') . '/media/pvmodern/proofs/' . $token;
        if (!is_dir($pubRoot)) { mkdir($pubRoot, 0755, true); }

        $filename = 'proof.' . $ext;
        $destPath = $pubRoot . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return $result->setHttpResponseCode(500)->setData(['success' => false, 'message' => 'Failed to save file']);
        }

        // Relative URL stored in DB
        $screenshotUrl = 'pvmodern/proofs/' . $token . '/' . $filename;

        $this->paymentDb->updateOrder($pvOrderId, [
            'screenshot_url' => $screenshotUrl,
            'screenshot_token' => $token,
            'payment_status' => 'pending_review',
            'current_step' => 4,
        ]);

        $this->paymentDb->logVerification([
            'pv_order_id' => $pvOrderId,
            'source' => 'customer_upload',
            'amount' => (float)$pvOrder['total_amount'],
            'transaction_id' => null,
            'raw_payload' => json_encode(['token' => $token, 'filename' => $filename]),
            'note' => 'Customer uploaded payment proof',
        ]);

        // Admin notification via Telegram (if configured)
        $this->notifyAdmin($pvOrder, $pvOrderId);

        return $result->setData([
            'success' => true,
            'message' => 'Ảnh đã được gửi thành công. Vui lòng chờ admin xác nhận.',
            'status' => 'pending_review',
        ]);
    }

    private function notifyAdmin(array $pvOrder, int $pvOrderId): void
    {
        $tgToken = (string)$this->deploymentConfig->get('pvmodern/telegram_bot_token', '');
        $tgChatId = (string)$this->deploymentConfig->get('pvmodern/telegram_chat_id', '');
        if ($tgToken === '' || $tgChatId === '') { return; }

        $msg = "🔔 *Xác nhận thanh toán mới*\n"
            . "Đơn: `" . $pvOrder['magento_increment_id'] . "`\n"
            . "Khách: " . $pvOrder['customer_name'] . "\n"
            . "PP: " . strtoupper($pvOrder['payment_method']) . "\n"
            . "Số tiền: " . number_format((float)$pvOrder['total_amount'], 0, ',', '.') . "đ\n"
            . "➡ Admin vào /pvadmin/admin/dashboard để xét duyệt";

        $url = 'https://api.telegram.org/bot' . $tgToken . '/sendMessage';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $tgChatId,
                'text' => $msg,
                'parse_mode' => 'Markdown',
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
