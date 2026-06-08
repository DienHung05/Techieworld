<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Setup\Patch\Schema;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class AddVerifiedPaymentIntegrationTables implements SchemaPatchInterface
{
    public function __construct(private readonly SchemaSetupInterface $schemaSetup)
    {
    }

    public function apply(): static
    {
        $setup = $this->schemaSetup;
        $setup->startSetup();
        $conn = $setup->getConnection();
        $now = date('Y-m-d H:i:s');

        $pvOrderTable = $setup->getTable('pv_payment_order');
        if ($conn->isTableExists($pvOrderTable)) {
            $this->addColumnIfMissing($pvOrderTable, 'payment_attempt_id', Table::TYPE_INTEGER, null, [
                'unsigned' => true,
                'nullable' => true,
                'default' => null,
            ]);
            $this->addColumnIfMissing($pvOrderTable, 'provider_order_id', Table::TYPE_TEXT, 128, ['nullable' => true, 'default' => null]);
            $this->addColumnIfMissing($pvOrderTable, 'provider_transaction_id', Table::TYPE_TEXT, 128, ['nullable' => true, 'default' => null]);
            $this->addColumnIfMissing($pvOrderTable, 'provider_session_id', Table::TYPE_TEXT, 128, ['nullable' => true, 'default' => null]);
            $this->addColumnIfMissing($pvOrderTable, 'currency', Table::TYPE_TEXT, 3, ['nullable' => false, 'default' => 'VND']);
            $this->addColumnIfMissing($pvOrderTable, 'checkout_url', Table::TYPE_TEXT, 512, ['nullable' => true, 'default' => null]);
            $this->addColumnIfMissing($pvOrderTable, 'payment_url', Table::TYPE_TEXT, 512, ['nullable' => true, 'default' => null]);
            $this->addColumnIfMissing($pvOrderTable, 'qr_code_url', Table::TYPE_TEXT, 512, ['nullable' => true, 'default' => null]);
            $this->addColumnIfMissing($pvOrderTable, 'qr_code_payload', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null]);
            $this->addColumnIfMissing($pvOrderTable, 'deeplink_url', Table::TYPE_TEXT, 512, ['nullable' => true, 'default' => null]);
            $this->addColumnIfMissing($pvOrderTable, 'signature_verified', Table::TYPE_SMALLINT, null, [
                'nullable' => false,
                'default' => 0,
            ]);
            $this->addColumnIfMissing($pvOrderTable, 'raw_create_response', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null]);
            $this->addColumnIfMissing($pvOrderTable, 'status_version', Table::TYPE_INTEGER, null, [
                'unsigned' => true,
                'nullable' => false,
                'default' => 0,
            ]);
            $this->addColumnIfMissing($pvOrderTable, 'last_status_change_at', Table::TYPE_DATETIME, null, [
                'nullable' => true,
                'default' => null,
            ]);
            $this->addIndexIfMissing($pvOrderTable, $setup->getIdxName('pv_payment_order', 'provider_order_id'), ['provider_order_id']);
            $this->addIndexIfMissing($pvOrderTable, $setup->getIdxName('pv_payment_order', 'provider_transaction_id'), ['provider_transaction_id']);
            $this->addIndexIfMissing($pvOrderTable, $setup->getIdxName('pv_payment_order', 'payment_attempt_id'), ['payment_attempt_id']);
        }

        if (!$conn->isTableExists($setup->getTable('pv_payment_attempt'))) {
            $table = $conn->newTable($setup->getTable('pv_payment_attempt'))
                ->addColumn('id', Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
                ->addColumn('pv_order_id', Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
                ->addColumn('magento_increment_id', Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => ''])
                ->addColumn('provider', Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => ''])
                ->addColumn('provider_order_id', Table::TYPE_TEXT, 128, ['nullable' => true, 'default' => null])
                ->addColumn('provider_transaction_id', Table::TYPE_TEXT, 128, ['nullable' => true, 'default' => null])
                ->addColumn('status', Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => 'pending'])
                ->addColumn('amount', Table::TYPE_DECIMAL, '12,2', ['nullable' => false, 'default' => '0.00'])
                ->addColumn('currency', Table::TYPE_TEXT, 3, ['nullable' => false, 'default' => 'VND'])
                ->addColumn('checkout_url', Table::TYPE_TEXT, 512, ['nullable' => true, 'default' => null])
                ->addColumn('payment_url', Table::TYPE_TEXT, 512, ['nullable' => true, 'default' => null])
                ->addColumn('qr_code_url', Table::TYPE_TEXT, 512, ['nullable' => true, 'default' => null])
                ->addColumn('qr_code_payload', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null])
                ->addColumn('deeplink_url', Table::TYPE_TEXT, 512, ['nullable' => true, 'default' => null])
                ->addColumn('provider_session_id', Table::TYPE_TEXT, 128, ['nullable' => true, 'default' => null])
                ->addColumn('status_version', Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
                ->addColumn('last_status_change_at', Table::TYPE_DATETIME, null, ['nullable' => false, 'default' => $now])
                ->addColumn('client_secret', Table::TYPE_TEXT, 512, ['nullable' => true, 'default' => null])
                ->addColumn('signature_verified', Table::TYPE_SMALLINT, null, ['nullable' => false, 'default' => 0])
                ->addColumn('raw_create_response', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null])
                ->addColumn('created_at', Table::TYPE_DATETIME, null, ['nullable' => false, 'default' => $now])
                ->addColumn('updated_at', Table::TYPE_DATETIME, null, ['nullable' => false, 'default' => $now])
                ->addColumn('paid_at', Table::TYPE_DATETIME, null, ['nullable' => true, 'default' => null])
                ->addColumn('expires_at', Table::TYPE_DATETIME, null, ['nullable' => false, 'default' => $now])
                ->addIndex($setup->getIdxName('pv_payment_attempt', 'pv_order_id'), ['pv_order_id'])
                ->addIndex($setup->getIdxName('pv_payment_attempt', 'magento_increment_id'), ['magento_increment_id'])
                ->addIndex($setup->getIdxName('pv_payment_attempt', 'provider_order_id'), ['provider_order_id'])
                ->addIndex($setup->getIdxName('pv_payment_attempt', 'provider_transaction_id'), ['provider_transaction_id'])
                ->addIndex($setup->getIdxName('pv_payment_attempt', 'provider_session_id'), ['provider_session_id'])
                ->addIndex($setup->getIdxName('pv_payment_attempt', 'status'), ['status'])
                ->setComment('PVModern Verified Payment Attempts');
            $conn->createTable($table);
        }

        if (!$conn->isTableExists($setup->getTable('pv_payment_event'))) {
            $table = $conn->newTable($setup->getTable('pv_payment_event'))
                ->addColumn('id', Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
                ->addColumn('payment_attempt_id', Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
                ->addColumn('pv_order_id', Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
                ->addColumn('magento_increment_id', Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => ''])
                ->addColumn('provider', Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => ''])
                ->addColumn('event_type', Table::TYPE_TEXT, 64, ['nullable' => false, 'default' => ''])
                ->addColumn('provider_event_id', Table::TYPE_TEXT, 128, ['nullable' => true, 'default' => null])
                ->addColumn('provider_transaction_id', Table::TYPE_TEXT, 128, ['nullable' => true, 'default' => null])
                ->addColumn('signature_verified', Table::TYPE_SMALLINT, null, ['nullable' => false, 'default' => 0])
                ->addColumn('amount', Table::TYPE_DECIMAL, '12,2', ['nullable' => true, 'default' => null])
                ->addColumn('currency', Table::TYPE_TEXT, 3, ['nullable' => false, 'default' => 'VND'])
                ->addColumn('raw_payload', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null])
                ->addColumn('processing_result', Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => 'accepted'])
                ->addColumn('error_message', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null])
                ->addColumn('received_at', Table::TYPE_DATETIME, null, ['nullable' => false, 'default' => $now])
                ->addColumn('processed_at', Table::TYPE_DATETIME, null, ['nullable' => true, 'default' => null])
                ->addIndex($setup->getIdxName('pv_payment_event', 'payment_attempt_id'), ['payment_attempt_id'])
                ->addIndex($setup->getIdxName('pv_payment_event', 'provider_event_id'), ['provider_event_id'])
                ->addIndex($setup->getIdxName('pv_payment_event', 'provider_transaction_id'), ['provider_transaction_id'])
                ->addIndex($setup->getIdxName('pv_payment_event', 'provider'), ['provider'])
                ->setComment('PVModern Payment Callback Events');
            $conn->createTable($table);
        }

        if (!$conn->isTableExists($setup->getTable('pv_bank_transfer_review'))) {
            $table = $conn->newTable($setup->getTable('pv_bank_transfer_review'))
                ->addColumn('id', Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
                ->addColumn('casso_transaction_id', Table::TYPE_TEXT, 128, ['nullable' => false, 'default' => ''])
                ->addColumn('amount', Table::TYPE_DECIMAL, '12,2', ['nullable' => false, 'default' => '0.00'])
                ->addColumn('description', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null])
                ->addColumn('transaction_time', Table::TYPE_DATETIME, null, ['nullable' => true, 'default' => null])
                ->addColumn('matched_order_id', Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true, 'default' => null])
                ->addColumn('status', Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => 'unmatched'])
                ->addColumn('review_reason', Table::TYPE_TEXT, 255, ['nullable' => true, 'default' => null])
                ->addColumn('resolved_by', Table::TYPE_TEXT, 128, ['nullable' => true, 'default' => null])
                ->addColumn('resolved_at', Table::TYPE_DATETIME, null, ['nullable' => true, 'default' => null])
                ->addColumn('raw_payload', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null])
                ->addColumn('created_at', Table::TYPE_DATETIME, null, ['nullable' => false, 'default' => $now])
                ->addColumn('updated_at', Table::TYPE_DATETIME, null, ['nullable' => false, 'default' => $now])
                ->addIndex($setup->getIdxName('pv_bank_transfer_review', 'casso_transaction_id'), ['casso_transaction_id'])
                ->addIndex($setup->getIdxName('pv_bank_transfer_review', 'status'), ['status'])
                ->addIndex($setup->getIdxName('pv_bank_transfer_review', 'matched_order_id'), ['matched_order_id'])
                ->setComment('PVModern Bank Transfer Manual Reviews');
            $conn->createTable($table);
        }

        if (!$conn->isTableExists($setup->getTable('pv_fulfillment_job'))) {
            $table = $conn->newTable($setup->getTable('pv_fulfillment_job'))
                ->addColumn('id', Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
                ->addColumn('magento_increment_id', Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => ''])
                ->addColumn('order_entity_id', Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
                ->addColumn('status', Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => 'pending'])
                ->addColumn('provider', Table::TYPE_TEXT, 32, ['nullable' => true, 'default' => null])
                ->addColumn('raw_context', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null])
                ->addColumn('result_payload', Table::TYPE_TEXT, '64k', ['nullable' => true, 'default' => null])
                ->addColumn('created_at', Table::TYPE_DATETIME, null, ['nullable' => false, 'default' => $now])
                ->addColumn('updated_at', Table::TYPE_DATETIME, null, ['nullable' => false, 'default' => $now])
                ->addColumn('processed_at', Table::TYPE_DATETIME, null, ['nullable' => true, 'default' => null])
                ->addIndex(
                    $setup->getIdxName('pv_fulfillment_job', 'magento_increment_id', AdapterInterface::INDEX_TYPE_UNIQUE),
                    ['magento_increment_id'],
                    ['type' => AdapterInterface::INDEX_TYPE_UNIQUE]
                )
                ->addIndex($setup->getIdxName('pv_fulfillment_job', 'status'), ['status'])
                ->setComment('PVModern Idempotent Fulfillment Jobs');
            $conn->createTable($table);
        }

        $setup->endSetup();
        return $this;
    }

    public static function getDependencies(): array
    {
        return [CreatePaymentTables::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    
    private function addColumnIfMissing(string $table, string $column, string $type, mixed $size, array $definition): void
    {
        $conn = $this->schemaSetup->getConnection();
        if (!$conn->tableColumnExists($table, $column)) {
            $columnDefinition = ['type' => $type] + $definition;
            $columnDefinition['comment'] = $columnDefinition['comment'] ?? $column;
            if ($size !== null) {
                $columnDefinition['length'] = $size;
            }
            $conn->addColumn($table, $column, $columnDefinition);
        }
    }

    
    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        $conn = $this->schemaSetup->getConnection();
        $indexes = $conn->getIndexList($table);
        if (!isset($indexes[$indexName])) {
            $conn->addIndex($table, $indexName, $columns);
        }
    }
}
