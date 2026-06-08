#!/usr/bin/env bash
# sepay-demo-pay.sh — fire a sandbox SePay webhook against the local Magento
# instance for a given pending order. Use this in DEMO MODE to instantly
# confirm payment from the terminal without clicking through SePay's UI.
#
# Usage:
#   bin/sepay-demo-pay.sh ORD000000074
#   bin/sepay-demo-pay.sh 74        # auto-prefixed to ORD0000000074
#
# The script:
#   1. Resolves the order number to a transfer_code like ORD000000074
#   2. Looks up the order's expected amount from the DB
#   3. Builds a SePay-shaped webhook payload
#   4. POSTs to https://127.0.0.1/api/webhooks/sepay with the sandbox API key
#   5. Reports the resulting paid/manual_review state from the DB
#
# Requires the file /var/www/Magento/pvmodern.env to define SEPAY_SANDBOX_API_KEY.
set -euo pipefail

MAGE_ROOT="/var/www/Magento"
ENV_FILE="${MAGE_ROOT}/pvmodern.env"
ENDPOINT="https://127.0.0.1/api/webhooks/sepay"

if [ "$#" -lt 1 ]; then
    echo "Usage: $0 <order_number_or_transfer_code>"
    echo "Examples:"
    echo "  $0 ORD000000074"
    echo "  $0 74"
    exit 1
fi

# Normalize the input — accept ORD000000074 or just 74
INPUT="$1"
if [[ "$INPUT" =~ ^[0-9]+$ ]]; then
    TRANSFER_CODE=$(printf "ORD%09d" "$INPUT")
elif [[ "$INPUT" =~ ^ORD[0-9]+$ ]]; then
    TRANSFER_CODE="$INPUT"
else
    echo "ERROR: input must be a number (74) or a transfer code (ORD000000074)" >&2
    exit 1
fi

# Pull the sandbox key from the env file
SECRET=$(grep -E '^SEPAY_SANDBOX_API_KEY=' "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2-)
if [ -z "$SECRET" ]; then
    echo "ERROR: SEPAY_SANDBOX_API_KEY missing from $ENV_FILE" >&2
    exit 1
fi

# Look up the order's expected amount from the pv_payment_order table
DB_USER=$(php -r '$c = require "'"$MAGE_ROOT"'/app/etc/env.php"; echo $c["db"]["connection"]["default"]["username"];')
DB_PASS=$(php -r '$c = require "'"$MAGE_ROOT"'/app/etc/env.php"; echo $c["db"]["connection"]["default"]["password"];')
DB_NAME=$(php -r '$c = require "'"$MAGE_ROOT"'/app/etc/env.php"; echo $c["db"]["connection"]["default"]["dbname"];')

ORDER_ROW=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -BN -e "SELECT magento_increment_id, total_amount, payment_status FROM pv_payment_order WHERE transfer_code='$TRANSFER_CODE' ORDER BY id DESC LIMIT 1" 2>/dev/null) || true

if [ -z "$ORDER_ROW" ]; then
    echo "ERROR: no pv_payment_order found with transfer_code='$TRANSFER_CODE'" >&2
    exit 1
fi

ORDER_ID=$(echo "$ORDER_ROW" | awk '{print $1}')
TOTAL_AMOUNT=$(echo "$ORDER_ROW" | awk '{print $2}')
CURRENT_STATUS=$(echo "$ORDER_ROW" | awk '{print $3}')
ROUNDED_AMOUNT=$(printf '%.0f' "$TOTAL_AMOUNT")

echo "→ Order: $ORDER_ID (status: $CURRENT_STATUS)"
echo "→ Memo:  $TRANSFER_CODE"
echo "→ Amount: $ROUNDED_AMOUNT VND"
echo ""

# Build the SePay-shaped payload
TS=$(date +%s)
PAYLOAD=$(cat <<EOF
{
  "id": $TS,
  "gateway": "BIDV",
  "transactionDate": "$(date '+%Y-%m-%d %H:%M:%S')",
  "accountNumber": "0000000000",
  "subAccount": "",
  "code": "$TRANSFER_CODE",
  "content": "$TRANSFER_CODE thanh toan don hang",
  "transferType": "in",
  "description": "Demo sandbox payment",
  "transferAmount": $ROUNDED_AMOUNT,
  "accumulated": $ROUNDED_AMOUNT,
  "referenceCode": "DEMO-$TS"
}
EOF
)

echo "→ Firing sandbox webhook..."
RESPONSE=$(curl -sk -X POST -H "Content-Type: application/json" -H "Authorization: Apikey $SECRET" -d "$PAYLOAD" "$ENDPOINT" -w "\n%{http_code}" --max-time 10)
HTTP=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -n -1)

echo "← HTTP $HTTP"
echo "← Body: $BODY"
echo ""

# Re-check DB
sleep 1
NEW_STATUS=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -BN -e "SELECT payment_status FROM pv_payment_order WHERE transfer_code='$TRANSFER_CODE' ORDER BY id DESC LIMIT 1" 2>/dev/null) || true
echo "→ Order status now: $NEW_STATUS"

if [ "$NEW_STATUS" = "paid" ]; then
    echo "✓ Demo payment confirmed. Browser at /payment-confirmation?orderId=$ORDER_ID should advance to step 5 within ~3 seconds."
else
    echo "⚠ Status didn't flip to paid. Check pv_payment_event table for the reason."
fi
