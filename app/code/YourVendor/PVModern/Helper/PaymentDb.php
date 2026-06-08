<?php
declare(strict_types=1);
namespace YourVendor\PVModern\Helper;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class PaymentDb
{
    private AdapterInterface $conn;

    public function __construct(ResourceConnection $resource)
    {
        $this->conn = $resource->getConnection();
    }

    public function getConnection(): AdapterInterface
    {
        return $this->conn;
    }

    public function getOrderTable(): string
    {
        return $this->conn->getTableName('pv_payment_order');
    }

    public function getVerifTable(): string
    {
        return $this->conn->getTableName('pv_payment_verification');
    }

    public function getAttemptTable(): string
    {
        return $this->conn->getTableName('pv_payment_attempt');
    }

    public function getEventTable(): string
    {
        return $this->conn->getTableName('pv_payment_event');
    }

    public function getReviewTable(): string
    {
        return $this->conn->getTableName('pv_bank_transfer_review');
    }

    public function getFulfillmentJobTable(): string
    {
        return $this->conn->getTableName('pv_fulfillment_job');
    }

    public function findByIncrementId(string $incrementId): ?array
    {
        
        
        
        
        
        $candidates = $this->incrementIdCandidates($incrementId);
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $row = $this->conn->fetchRow(
            'SELECT * FROM ' . $this->getOrderTable()
            . ' WHERE magento_increment_id IN (' . $placeholders . ') ORDER BY id DESC LIMIT 1',
            $candidates
        );
        return $row ?: null;
    }

    
    private function incrementIdCandidates(string $incrementId): array
    {
        $incrementId = trim($incrementId);
        $stripped = ltrim($incrementId, '0');
        if ($stripped === '') {
            $stripped = '0';
        }
        $padded = str_pad($stripped, 9, '0', STR_PAD_LEFT);
        
        return array_values(array_unique(array_filter([$incrementId, $padded, $stripped])));
    }

    public function findById(int $id): ?array
    {
        $row = $this->conn->fetchRow(
            'SELECT * FROM ' . $this->getOrderTable() . ' WHERE id = ?',
            [$id]
        );
        return $row ?: null;
    }

    public function findByTransferCode(string $code): ?array
    {
        $row = $this->conn->fetchRow(
            'SELECT * FROM ' . $this->getOrderTable() . ' WHERE transfer_code = ? LIMIT 1',
            [$code]
        );
        return $row ?: null;
    }

    public function createOrder(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $this->conn->insert($this->getOrderTable(), $data);
        return (int) $this->conn->lastInsertId();
    }

    public function updateOrder(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->conn->update($this->getOrderTable(), $data, ['id = ?' => $id]);
    }

    public function transitionOrder(int $id, string $status, array $extra = []): void
    {
        $this->updateOrder($id, $extra + [
            'payment_status' => $status,
            'last_status_change_at' => date('Y-m-d H:i:s'),
            'status_version' => new \Zend_Db_Expr('status_version + 1'),
        ]);
    }

    public function logVerification(array $data): void
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->conn->insert($this->getVerifTable(), $data);
    }

    public function createAttempt(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $data['last_status_change_at'] = $data['last_status_change_at'] ?? $now;
        $this->conn->insert($this->getAttemptTable(), $data);
        return (int) $this->conn->lastInsertId();
    }

    public function updateAttempt(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->conn->update($this->getAttemptTable(), $data, ['id = ?' => $id]);
    }

    public function transitionAttempt(int $id, string $status, array $extra = []): void
    {
        $this->updateAttempt($id, $extra + [
            'status' => $status,
            'last_status_change_at' => date('Y-m-d H:i:s'),
            'status_version' => new \Zend_Db_Expr('status_version + 1'),
        ]);
    }

    public function findAttemptById(int $id): ?array
    {
        $row = $this->conn->fetchRow(
            'SELECT * FROM ' . $this->getAttemptTable() . ' WHERE id = ?',
            [$id]
        );
        return $row ?: null;
    }

    public function findLatestAttemptForIncrement(string $incrementId): ?array
    {
        $candidates = $this->incrementIdCandidates($incrementId);
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $row = $this->conn->fetchRow(
            'SELECT * FROM ' . $this->getAttemptTable()
            . ' WHERE magento_increment_id IN (' . $placeholders . ') ORDER BY id DESC LIMIT 1',
            $candidates
        );
        return $row ?: null;
    }

    public function findAttemptByProviderOrderId(string $provider, string $providerOrderId): ?array
    {
        if ($providerOrderId === '') {
            return null;
        }
        $row = $this->conn->fetchRow(
            'SELECT * FROM ' . $this->getAttemptTable()
            . ' WHERE provider = ? AND provider_order_id = ? ORDER BY id DESC LIMIT 1',
            [$provider, $providerOrderId]
        );
        return $row ?: null;
    }

    public function findAttemptByProviderSessionId(string $provider, string $sessionId): ?array
    {
        if ($sessionId === '') {
            return null;
        }
        $row = $this->conn->fetchRow(
            'SELECT * FROM ' . $this->getAttemptTable()
            . ' WHERE provider = ? AND provider_session_id = ? ORDER BY id DESC LIMIT 1',
            [$provider, $sessionId]
        );
        return $row ?: null;
    }

    public function findAttemptByProviderTransactionId(string $provider, string $transactionId): ?array
    {
        if ($transactionId === '') {
            return null;
        }
        $row = $this->conn->fetchRow(
            'SELECT * FROM ' . $this->getAttemptTable()
            . ' WHERE provider = ? AND provider_transaction_id = ? ORDER BY id DESC LIMIT 1',
            [$provider, $transactionId]
        );
        return $row ?: null;
    }

    public function listPendingAttempts(int $olderThanSeconds = 0, int $limit = 100): array
    {
        $where = "status IN ('pending', 'awaiting_payment')";
        $bind = [];
        if ($olderThanSeconds > 0) {
            $where .= ' AND created_at <= ?';
            $bind[] = date('Y-m-d H:i:s', time() - $olderThanSeconds);
        }

        return $this->conn->fetchAll(
            'SELECT * FROM ' . $this->getAttemptTable()
            . ' WHERE ' . $where
            . ' ORDER BY created_at ASC LIMIT ' . (int) $limit,
            $bind
        );
    }

    public function listExpiredPendingAttempts(int $limit = 200): array
    {
        return $this->conn->fetchAll(
            'SELECT * FROM ' . $this->getAttemptTable()
            . " WHERE status IN ('pending', 'awaiting_payment') AND expires_at < ?"
            . ' ORDER BY expires_at ASC LIMIT ' . (int) $limit,
            [date('Y-m-d H:i:s')]
        );
    }

    public function logPaymentEvent(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['received_at'] = $data['received_at'] ?? $now;
        $this->conn->insert($this->getEventTable(), $data);
        return (int) $this->conn->lastInsertId();
    }

    public function updatePaymentEvent(int $id, array $data): void
    {
        $this->conn->update($this->getEventTable(), $data, ['id = ?' => $id]);
    }

    public function hasAcceptedEvent(string $provider, ?string $providerEventId, ?string $transactionId): bool
    {
        $where = ['provider = ?', 'processing_result IN ("accepted", "duplicate")'];
        $bind = [$provider];
        $or = [];
        if ($providerEventId) {
            $or[] = 'provider_event_id = ?';
            $bind[] = $providerEventId;
        }
        if ($transactionId) {
            $or[] = 'provider_transaction_id = ?';
            $bind[] = $transactionId;
        }
        if (!$or) {
            return false;
        }
        $where[] = '(' . implode(' OR ', $or) . ')';

        return (bool) $this->conn->fetchOne(
            'SELECT 1 FROM ' . $this->getEventTable()
            . ' WHERE ' . implode(' AND ', $where)
            . ' LIMIT 1',
            $bind
        );
    }

    public function findReviewByTransactionId(string $transactionId): ?array
    {
        if ($transactionId === '') {
            return null;
        }
        $row = $this->conn->fetchRow(
            'SELECT * FROM ' . $this->getReviewTable() . ' WHERE casso_transaction_id = ? ORDER BY id DESC LIMIT 1',
            [$transactionId]
        );
        return $row ?: null;
    }

    public function createReview(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $this->conn->insert($this->getReviewTable(), $data);
        return (int) $this->conn->lastInsertId();
    }

    public function updateReview(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->conn->update($this->getReviewTable(), $data, ['id = ?' => $id]);
    }

    public function listReviews(string $status = '', int $limit = 100): array
    {
        $bind = [];
        $sql = 'SELECT * FROM ' . $this->getReviewTable();
        if ($status !== '') {
            $sql .= ' WHERE status = ?';
            $bind[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limit;
        return $this->conn->fetchAll($sql, $bind);
    }

    public function createFulfillmentJobIfMissing(array $data): bool
    {
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        try {
            $this->conn->insert($this->getFulfillmentJobTable(), $data);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function updateFulfillmentJob(string $incrementId, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->conn->update($this->getFulfillmentJobTable(), $data, ['magento_increment_id = ?' => $incrementId]);
    }

    public function listOrders(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = [];
        $bind = [];
        if (!empty($filters['status'])) {
            $where[] = 'payment_status = ?';
            $bind[] = $filters['status'];
        }
        if (!empty($filters['method'])) {
            $where[] = 'payment_method = ?';
            $bind[] = $filters['method'];
        }
        if (!empty($filters['date'])) {
            $where[] = 'DATE(created_at) = ?';
            $bind[] = $filters['date'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . $filters['search'] . '%';
            $where[] = '(magento_increment_id LIKE ? OR customer_name LIKE ? OR transfer_code LIKE ?)';
            $bind = array_merge($bind, [$like, $like, $like]);
        }
        $sql = 'SELECT * FROM ' . $this->getOrderTable();
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        return $this->conn->fetchAll($sql, $bind);
    }

    public function countOrders(string $status = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->getOrderTable();
        $bind = [];
        if ($status) { $sql .= ' WHERE payment_status = ?'; $bind[] = $status; }
        return (int) $this->conn->fetchOne($sql, $bind);
    }

    public function listVerifications(int $pvOrderId = 0, string $date = ''): array
    {
        $where = [];
        $bind = [];
        if ($pvOrderId) { $where[] = 'pv_order_id = ?'; $bind[] = $pvOrderId; }
        if ($date) { $where[] = 'DATE(created_at) = ?'; $bind[] = $date; }
        $sql = 'SELECT * FROM ' . $this->getVerifTable();
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY created_at DESC LIMIT 200';
        return $this->conn->fetchAll($sql, $bind);
    }

    public function generateTransferCode(string $incrementId): string
    {
        return 'ORD' . preg_replace('/[^0-9]/', '', $incrementId);
    }
}
