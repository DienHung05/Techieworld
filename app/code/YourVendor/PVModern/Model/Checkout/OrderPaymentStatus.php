<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Checkout;

class OrderPaymentStatus
{
    public const PENDING = 'pending';
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const MANUAL_REVIEW = 'manual_review';
    public const COD_PENDING = 'cod_pending';
}
