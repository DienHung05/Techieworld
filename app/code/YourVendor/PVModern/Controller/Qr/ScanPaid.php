<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Qr;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\CassoTransactionProcessor;
use YourVendor\PVModern\Helper\PaymentDb;


class ScanPaid implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly IntegrationConfig $integrationConfig,
        private readonly CassoTransactionProcessor $transactionProcessor,
        private readonly PaymentDb $paymentDb
    ) {
    }

    public function execute()
    {
        $result = $this->rawFactory->create();

        if (!$this->integrationConfig->getBool('PVMODERN_PAYMENT_DEMO', false)) {
            return $result->setHttpResponseCode(403)
                ->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)
                ->setContents('Demo mode is disabled. This endpoint is unavailable.');
        }

        $orderParam = (string) ($this->request->getParam('order') ?: $this->request->getParam('orderId') ?: '');
        $transferCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $orderParam) ?: '');
        if (!preg_match('/^ORD[0-9]+$/', $transferCode)) {
            return $this->landingHtml('error', 'Mã đơn hàng không hợp lệ.', $result);
        }

        
        $incrementId = preg_replace('/^ORD0*/', '', $transferCode) ?: '';
        $incrementId = str_pad($incrementId, 9, '0', STR_PAD_LEFT);
        $pvOrder = $this->paymentDb->findByIncrementId($incrementId);
        if (!$pvOrder) {
            return $this->landingHtml('error', 'Không tìm thấy đơn hàng tương ứng.', $result);
        }

        $status = (string) ($pvOrder['payment_status'] ?? 'pending');
        if ($status === 'paid') {
            return $this->landingHtml('paid', 'Đơn hàng đã được xác nhận thanh toán.', $result);
        }
        if (in_array($status, ['expired', 'cancelled', 'failed'], true)) {
            return $this->landingHtml('error', sprintf('Trạng thái đơn hàng: %s.', $status), $result);
        }

        $amount = (int) round((float) ($pvOrder['total_amount'] ?? 0));
        $fakeTxn = [
            'description' => sprintf('%s scan-to-pay demo', $transferCode),
            'amount'      => $amount,
            'id'          => 'DEMO-SCAN-' . time(),
            'tid'         => 'DEMO-SCAN-' . time(),
            'kind'        => 1,
        ];
        $rawPayload = json_encode(['source' => 'demo_scan', 'transferCode' => $transferCode, 'amount' => $amount]);
        $this->transactionProcessor->process($fakeTxn, $rawPayload ?: '', true);

        
        $after = $this->paymentDb->findByIncrementId($incrementId);
        $afterStatus = (string) ($after['payment_status'] ?? 'pending');

        return $this->landingHtml(
            $afterStatus === 'paid' ? 'paid' : 'pending',
            $afterStatus === 'paid'
                ? 'Thanh toán demo đã được ghi nhận. Quay lại trình duyệt máy tính để xem trang xác nhận.'
                : 'Đã gửi yêu cầu xác nhận. Đang xử lý...',
            $result
        );
    }

    
    private function landingHtml(string $kind, string $message, $result)
    {
        $color = match ($kind) {
            'paid'  => ['#10b981', '#ecfdf5', '#065f46', '✓'],
            'error' => ['#ef4444', '#fef2f2', '#991b1b', '✕'],
            default => ['#f59e0b', '#fffbeb', '#78350f', '⌛'],
        };
        $html = <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Demo Payment</title>
<style>
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:{$color[1]};color:{$color[2]};min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px}
  .card{max-width:380px;text-align:center;background:#fff;border:2px solid {$color[0]};border-radius:24px;padding:36px 28px;box-shadow:0 12px 32px rgba(0,0,0,.08)}
  .icon{width:88px;height:88px;border-radius:50%;background:{$color[0]};color:#fff;font-size:48px;line-height:88px;margin:0 auto 18px;font-weight:700}
  h1{font-size:22px;margin:0 0 12px;color:{$color[2]}}
  p{margin:0;font-size:15px;line-height:1.5;color:#475569}
  .demo-tag{margin-top:20px;display:inline-block;background:{$color[1]};color:{$color[2]};font-size:11px;font-weight:700;letter-spacing:1px;padding:4px 10px;border-radius:999px}
</style>
</head>
<body>
  <div class="card">
    <div class="icon">{$color[3]}</div>
    <h1>{$message}</h1>
    <p>Đây là chế độ demo — không có giao dịch ngân hàng thật.</p>
    <span class="demo-tag">DEMO MODE</span>
  </div>
</body>
</html>
HTML;
        return $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setContents($html);
    }
}
