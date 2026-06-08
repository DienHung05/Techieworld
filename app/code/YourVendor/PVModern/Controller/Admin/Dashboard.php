<?php
declare(strict_types=1);
namespace YourVendor\PVModern\Controller\Admin;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use YourVendor\PVModern\Helper\PaymentDb;

class Dashboard implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly PaymentDb $paymentDb,
        private readonly Login $loginHelper
    ) {}

    public function execute()
    {
        if (!$this->loginHelper->isAuthenticated()) {
            $redirect = $this->redirectFactory->create();
            $redirect->setPath('pvadmin/admin/login');
            return $redirect;
        }

        $raw = $this->rawFactory->create();
        $raw->setHeader('Content-Type', 'text/html; charset=utf-8');
        $raw->setHeader('X-Frame-Options', 'DENY');
        $raw->setHeader('Cache-Control', 'no-store, no-cache');
        $raw->setContents($this->renderDashboard());
        return $raw;
    }

    private function renderDashboard(): string
    {
        $pendingCount = $this->paymentDb->countOrders('pending_review');
        $paidCount = $this->paymentDb->countOrders('paid');
        $totalCount = $this->paymentDb->countOrders();

        return $this->getDashboardHtml($pendingCount, $paidCount, $totalCount);
    }

    private function getDashboardHtml(int $pendingCount, int $paidCount, int $totalCount): string
    {
        
        return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PVModern — Xác nhận thanh toán</title>
<style>
/* ── Reset & Base ── */
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:#0f172a;color:#e2e8f0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:14px;line-height:1.5}
a{color:#60a5fa;text-decoration:none}
/* ── Layout ── */
.pva-app{display:flex;flex-direction:column;min-height:100vh}
.pva-header{background:#1e293b;border-bottom:1px solid #334155;padding:0 24px;display:flex;align-items:center;justify-content:space-between;height:56px;position:sticky;top:0;z-index:100}
.pva-logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:16px;color:#f1f5f9}
.pva-logo .pva-logo-icon{background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:16px}
.pva-header-right{display:flex;align-items:center;gap:12px}
.pva-badge{background:#ef4444;color:#fff;border-radius:20px;padding:2px 8px;font-size:11px;font-weight:700}
.pva-btn-sm{padding:6px 14px;border-radius:7px;border:1px solid #475569;background:#1e293b;color:#cbd5e1;cursor:pointer;font-size:13px;transition:all .2s}
.pva-btn-sm:hover{background:#334155;border-color:#60a5fa;color:#f1f5f9}
.pva-btn-logout{border-color:#ef4444;color:#ef4444}
.pva-btn-logout:hover{background:#450a0a}
.pva-main{flex:1;padding:24px}
/* ── Stats ── */
.pva-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px}
.pva-stat-card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:16px 20px}
.pva-stat-card .stat-label{color:#94a3b8;font-size:12px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.pva-stat-card .stat-value{font-size:28px;font-weight:700;color:#f1f5f9}
.pva-stat-card .stat-sub{font-size:12px;margin-top:2px}
.stat-card--pending .stat-value{color:#f59e0b}
.stat-card--paid .stat-value{color:#10b981}
/* ── Toolbar ── */
.pva-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:14px 16px}
.pva-toolbar select,.pva-toolbar input{padding:7px 12px;background:#0f172a;border:1px solid #475569;border-radius:7px;color:#e2e8f0;font-size:13px;outline:none}
.pva-toolbar select:focus,.pva-toolbar input:focus{border-color:#3b82f6}
.pva-toolbar label{color:#94a3b8;font-size:12px;margin-right:4px}
.pva-refresh-btn{margin-left:auto;padding:7px 14px;background:#3b82f6;border:none;border-radius:7px;color:#fff;cursor:pointer;font-size:13px;font-weight:500;transition:background .2s}
.pva-refresh-btn:hover{background:#2563eb}
/* ── Tabs ── */
.pva-tabs{display:flex;gap:0;border-bottom:1px solid #334155;margin-bottom:20px}
.pva-tab{padding:10px 20px;cursor:pointer;border-bottom:2px solid transparent;color:#94a3b8;font-size:13px;font-weight:500;transition:all .2s;white-space:nowrap}
.pva-tab.active{color:#60a5fa;border-bottom-color:#3b82f6}
.pva-tab:hover:not(.active){color:#e2e8f0}
.pva-tab-badge{background:#ef4444;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:700;margin-left:4px;vertical-align:middle}
/* ── Table ── */
.pva-table-wrap{background:#1e293b;border:1px solid #334155;border-radius:12px;overflow:hidden}
.pva-table{width:100%;border-collapse:collapse}
.pva-table thead th{background:#162032;padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;font-weight:600;border-bottom:1px solid #334155;white-space:nowrap}
.pva-table tbody td{padding:12px 14px;border-bottom:1px solid #1e293b;vertical-align:middle}
.pva-table tbody tr:last-child td{border-bottom:none}
.pva-table tbody tr:hover td{background:#263549}
/* ── Status badges ── */
.pva-status{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
.pva-status::before{content:'';display:inline-block;width:6px;height:6px;border-radius:50%;background:currentColor}
.pva-status--pending{background:#422006;color:#fb923c}
.pva-status--pending_review{background:#422006;color:#f59e0b}
.pva-status--paid{background:#052e16;color:#4ade80}
.pva-status--failed{background:#450a0a;color:#f87171}
.pva-status--expired{background:#1e1b4b;color:#818cf8}
/* ── Method badges ── */
.pva-method{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;background:#1e293b;border:1px solid #334155}
.pva-method--momo{background:#2d0c1e;border-color:#ae2d68;color:#f9a8d4}
.pva-method--vnpay{background:#0c1a2d;border-color:#005BAA;color:#93c5fd}
.pva-method--bank_qr{background:#0a2014;border-color:#16a34a;color:#86efac}
.pva-method--card{background:#1a0a2d;border-color:#7c3aed;color:#c4b5fd}
/* ── Screenshot thumbnail ── */
.pva-thumb{width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #334155;cursor:pointer;transition:transform .2s}
.pva-thumb:hover{transform:scale(1.1)}
.pva-no-proof{color:#475569;font-size:12px;font-style:italic}
/* ── Action buttons ── */
.pva-action-row{display:flex;gap:6px;flex-wrap:wrap}
.pva-approve-btn,.pva-reject-btn,.pva-view-btn{padding:5px 12px;border-radius:6px;border:none;cursor:pointer;font-size:12px;font-weight:600;transition:all .2s}
.pva-approve-btn{background:#15803d;color:#fff}
.pva-approve-btn:hover{background:#16a34a}
.pva-reject-btn{background:#991b1b;color:#fff}
.pva-reject-btn:hover{background:#dc2626}
.pva-view-btn{background:#1e3a5f;color:#93c5fd;border:1px solid #2563eb}
.pva-view-btn:hover{background:#1d4ed8;color:#fff}
.pva-approve-btn:disabled,.pva-reject-btn:disabled,.pva-view-btn:disabled{opacity:.5;cursor:not-allowed}
/* ── Modal ── */
.pva-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:1000;align-items:center;justify-content:center;padding:20px}
.pva-modal-bg.active{display:flex}
.pva-modal{background:#1e293b;border:1px solid #334155;border-radius:16px;width:100%;max-width:900px;max-height:90vh;overflow-y:auto;display:flex;flex-direction:column}
.pva-modal-header{padding:16px 20px;border-bottom:1px solid #334155;display:flex;align-items:center;justify-content:space-between}
.pva-modal-header h2{font-size:16px;font-weight:700;color:#f1f5f9}
.pva-modal-close{background:none;border:none;color:#94a3b8;cursor:pointer;font-size:20px;line-height:1;padding:4px}
.pva-modal-close:hover{color:#f1f5f9}
.pva-modal-body{padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:20px}
.pva-modal-body .screenshot-col{display:flex;flex-direction:column;gap:12px}
.pva-modal-body .info-col{display:flex;flex-direction:column;gap:12px}
.pva-full-img{max-width:100%;border-radius:10px;border:1px solid #334155}
.pva-order-field{display:flex;flex-direction:column;gap:2px;background:#0f172a;border-radius:8px;padding:10px 12px}
.pva-order-field label{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.pva-order-field span{font-size:14px;color:#e2e8f0;font-weight:500}
.pva-checklist{background:#0f172a;border-radius:8px;padding:12px}
.pva-checklist h4{font-size:12px;text-transform:uppercase;color:#64748b;margin-bottom:10px;letter-spacing:.5px}
.pva-check-item{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #1e293b}
.pva-check-item:last-child{border-bottom:none}
.pva-check-item input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#3b82f6}
.pva-check-item label{flex:1;cursor:pointer;color:#cbd5e1;font-size:13px}
.pva-modal-footer{padding:16px 20px;border-top:1px solid #334155;display:flex;gap:10px;justify-content:flex-end}
.pva-modal-approve{padding:9px 20px;background:#15803d;border:none;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;font-size:14px;transition:background .2s}
.pva-modal-approve:hover{background:#16a34a}
.pva-modal-reject{padding:9px 20px;background:#991b1b;border:none;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;font-size:14px;transition:background .2s}
.pva-modal-reject:hover{background:#dc2626}
.pva-modal-reject-note{flex:1;padding:8px 12px;background:#0f172a;border:1px solid #475569;border-radius:8px;color:#e2e8f0;font-size:13px;resize:none;min-height:60px}
/* ── Toast ── */
.pva-toast-wrap{position:fixed;bottom:24px;right:24px;z-index:2000;display:flex;flex-direction:column;gap:8px}
.pva-toast{padding:12px 18px;border-radius:10px;color:#fff;font-size:13px;font-weight:500;box-shadow:0 4px 20px rgba(0,0,0,.4);animation:slideIn .3s ease;display:flex;align-items:center;gap:8px}
.pva-toast--success{background:#15803d}
.pva-toast--error{background:#991b1b}
@keyframes slideIn{from{transform:translateX(100px);opacity:0}to{transform:translateX(0);opacity:1}}
/* ── Loading ── */
.pva-loading{text-align:center;padding:48px;color:#64748b}
.pva-spinner{display:inline-block;width:28px;height:28px;border:3px solid #334155;border-top-color:#3b82f6;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
/* ── Empty state ── */
.pva-empty{text-align:center;padding:48px 20px;color:#64748b}
.pva-empty-icon{font-size:40px;margin-bottom:12px}
.pva-empty p{margin-top:6px;font-size:13px}
/* ── Note textarea ── */
.pva-note-group{display:flex;flex-direction:column;gap:6px}
.pva-note-group label{font-size:12px;color:#64748b}
.pva-note-group textarea{padding:8px 10px;background:#0f172a;border:1px solid #475569;border-radius:7px;color:#e2e8f0;font-size:13px;resize:vertical;min-height:72px;outline:none}
.pva-note-group textarea:focus{border-color:#3b82f6}
/* ── Amount highlight ── */
.amount-highlight{font-weight:700;color:#4ade80;font-size:15px}
/* ── Verification log ── */
.pva-log-item{background:#0f172a;border-radius:8px;padding:10px 14px;margin-bottom:8px;border-left:3px solid #334155}
.pva-log-item.source-casso{border-left-color:#10b981}
.pva-log-item.source-manual_admin{border-left-color:#3b82f6}
.pva-log-item.source-customer_upload{border-left-color:#f59e0b}
.pva-log-item.source-casso_unmatched{border-left-color:#ef4444}
.pva-log-meta{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#64748b;margin-top:4px}
/* ── Responsive ── */
@media(max-width:768px){
  .pva-modal-body{grid-template-columns:1fr}
  .pva-stats{grid-template-columns:1fr 1fr}
  .pva-main{padding:12px}
  .pva-header{padding:0 14px}
  .pva-table{font-size:12px}
  .pva-table thead th:nth-child(4),.pva-table thead th:nth-child(5){display:none}
  .pva-table tbody td:nth-child(4),.pva-table tbody td:nth-child(5){display:none}
}
</style>
</head>
<body>
<div class="pva-app">

  <!-- Header -->
  <header class="pva-header">
    <div class="pva-logo">
      <div class="pva-logo-icon">💳</div>
      <span>PVModern Admin</span>
    </div>
    <div class="pva-header-right">
      <span id="pvaLiveTime" style="color:#64748b;font-size:12px"></span>
      <button class="pva-btn-sm pva-btn-logout" onclick="location.href='/pvadmin/admin/logout'">Đăng xuất</button>
    </div>
  </header>

  <!-- Main content -->
  <main class="pva-main">

    <!-- Stats row -->
    <div class="pva-stats">
      <div class="pva-stat-card stat-card--pending">
        <div class="stat-label">Chờ xét duyệt</div>
        <div class="stat-value" id="statPending">{$pendingCount}</div>
        <div class="stat-sub" style="color:#f59e0b">MoMo / VNPay</div>
      </div>
      <div class="pva-stat-card stat-card--paid">
        <div class="stat-label">Đã xác nhận</div>
        <div class="stat-value" id="statPaid">{$paidCount}</div>
        <div class="stat-sub" style="color:#10b981">Tổng hôm nay</div>
      </div>
      <div class="pva-stat-card">
        <div class="stat-label">Tổng đơn</div>
        <div class="stat-value">{$totalCount}</div>
        <div class="stat-sub" style="color:#64748b">Toàn hệ thống</div>
      </div>
      <div class="pva-stat-card" style="border-color:#1d4ed8">
        <div class="stat-label">Casso tự động</div>
        <div class="stat-value" id="statCasso" style="color:#60a5fa">—</div>
        <div class="stat-sub" style="color:#64748b">Hôm nay</div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="pva-tabs">
      <div class="pva-tab active" data-tab="pending_review">Cần xét duyệt <span class="pva-tab-badge" id="tabBadge">{$pendingCount}</span></div>
      <div class="pva-tab" data-tab="all">Tất cả đơn</div>
      <div class="pva-tab" data-tab="paid">Đã thanh toán</div>
      <div class="pva-tab" data-tab="casso_log">Lịch sử Casso</div>
    </div>

    <!-- Toolbar -->
    <div class="pva-toolbar">
      <label>Phương thức:</label>
      <select id="filterMethod">
        <option value="">Tất cả</option>
        <option value="momo">MoMo</option>
        <option value="vnpay">VNPay</option>
        <option value="bank_qr">Ngân hàng QR</option>
        <option value="card">PayPal</option>
      </select>
      <label>Ngày:</label>
      <input type="date" id="filterDate" value="">
      <input type="search" id="filterSearch" placeholder="Tìm mã đơn, tên KH..." style="min-width:200px">
      <button class="pva-refresh-btn" onclick="loadOrders()">⟳ Làm mới</button>
    </div>

    <!-- Orders panel -->
    <div id="panelOrders">
      <div class="pva-table-wrap">
        <table class="pva-table">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="checkAll" title="Chọn tất cả"></th>
              <th>Mã đơn</th>
              <th>Khách hàng</th>
              <th>Phương thức</th>
              <th>Số tiền</th>
              <th>Ảnh chụp</th>
              <th>Thời gian</th>
              <th>Trạng thái</th>
              <th>Thao tác</th>
            </tr>
          </thead>
          <tbody id="ordersBody">
            <tr><td colspan="9" class="pva-loading"><div class="pva-spinner"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Casso Log panel -->
    <div id="panelCasso" style="display:none">
      <div class="pva-table-wrap" style="padding:16px">
        <div id="cassoLogBody"><div class="pva-loading"><div class="pva-spinner"></div></div></div>
      </div>
    </div>

  </main>
</div>

<!-- Order Detail Modal -->
<div class="pva-modal-bg" id="orderModal">
  <div class="pva-modal">
    <div class="pva-modal-header">
      <h2 id="modalTitle">Chi tiết đơn hàng</h2>
      <button class="pva-modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="pva-modal-body">
      <div class="screenshot-col">
        <div style="color:#94a3b8;font-size:12px;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Ảnh xác nhận thanh toán</div>
        <img src="" id="modalScreenshot" class="pva-full-img" alt="Screenshot">
        <div id="modalNoScreenshot" style="padding:48px;text-align:center;color:#475569;background:#0f172a;border-radius:8px;display:none">
          <div style="font-size:32px;margin-bottom:8px">🖼</div>
          <p>Chưa có ảnh xác nhận</p>
        </div>
        <!-- Verification checklist -->
        <div class="pva-checklist">
          <h4>Danh sách kiểm tra</h4>
          <div class="pva-check-item">
            <input type="checkbox" id="chk1">
            <label for="chk1">Ảnh rõ nét, không bị che khuất</label>
          </div>
          <div class="pva-check-item">
            <input type="checkbox" id="chk2">
            <label for="chk2">Số tiền khớp với đơn hàng: <span id="chkAmount" style="color:#4ade80;font-weight:700"></span></label>
          </div>
          <div class="pva-check-item">
            <input type="checkbox" id="chk3">
            <label for="chk3">Trạng thái "Thành công" / "Giao dịch thành công"</label>
          </div>
          <div class="pva-check-item">
            <input type="checkbox" id="chk4">
            <label for="chk4">Ảnh không phải screenshot cũ / ảnh giả mạo</label>
          </div>
          <div class="pva-check-item">
            <input type="checkbox" id="chk5">
            <label for="chk5">Thời gian giao dịch hợp lệ (trong 30 phút)</label>
          </div>
        </div>
      </div>
      <div class="info-col">
        <div class="pva-order-field"><label>Mã đơn hàng</label><span id="mOrdId">—</span></div>
        <div class="pva-order-field"><label>Khách hàng</label><span id="mCustomer">—</span></div>
        <div class="pva-order-field"><label>Email / SĐT</label><span id="mContact">—</span></div>
        <div class="pva-order-field"><label>Phương thức thanh toán</label><span id="mMethod">—</span></div>
        <div class="pva-order-field"><label>Số tiền cần thanh toán</label><span id="mAmount" class="amount-highlight">—</span></div>
        <div class="pva-order-field"><label>Thời gian tạo</label><span id="mCreated">—</span></div>
        <div class="pva-order-field"><label>Hết hạn lúc</label><span id="mExpires">—</span></div>
        <div class="pva-note-group" id="rejectNoteGroup" style="display:none">
          <label>Lý do từ chối (tùy chọn):</label>
          <textarea id="rejectNote" placeholder="Nhập lý do từ chối..."></textarea>
        </div>
      </div>
    </div>
    <div class="pva-modal-footer">
      <button class="pva-modal-reject" id="modalRejectBtn" onclick="modalAction('reject')">✕ Từ chối</button>
      <button class="pva-modal-approve" id="modalApproveBtn" onclick="modalAction('approve')">✓ Xác nhận đã thanh toán</button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="pva-toast-wrap" id="toastWrap"></div>

<script>
var pvCurrentTab = 'pending_review';
var pvCurrentOrderId = null;
var pvOrders = [];
var pvAutoRefreshTimer = null;

// Tab switching
document.querySelectorAll('.pva-tab').forEach(function(tab) {
  tab.addEventListener('click', function() {
    document.querySelectorAll('.pva-tab').forEach(function(t){t.classList.remove('active')});
    tab.classList.add('active');
    pvCurrentTab = tab.dataset.tab;
    if (pvCurrentTab === 'casso_log') {
      document.getElementById('panelOrders').style.display = 'none';
      document.getElementById('panelCasso').style.display = '';
      loadCassoLog();
    } else {
      document.getElementById('panelOrders').style.display = '';
      document.getElementById('panelCasso').style.display = 'none';
      loadOrders();
    }
  });
});

function getFilters() {
  return {
    status: pvCurrentTab === 'all' ? '' : (pvCurrentTab === 'casso_log' ? '' : pvCurrentTab),
    method: document.getElementById('filterMethod').value,
    date: document.getElementById('filterDate').value,
    search: document.getElementById('filterSearch').value.trim()
  };
}

function loadOrders() {
  var tbody = document.getElementById('ordersBody');
  tbody.innerHTML = '<tr><td colspan="9" class="pva-loading"><div class="pva-spinner"></div></td></tr>';
  var f = getFilters();
  var qs = Object.keys(f).map(function(k){return k+'='+encodeURIComponent(f[k])}).join('&');
  fetch('/pvadmin/admin/orders?' + qs)
    .then(function(r){return r.json()})
    .then(function(data) {
      pvOrders = data.orders || [];
      renderOrdersTable(pvOrders);
      // Update stats
      if (data.stats) {
        document.getElementById('statPending').textContent = data.stats.pending_review || 0;
        document.getElementById('statPaid').textContent = data.stats.paid || 0;
        document.getElementById('tabBadge').textContent = data.stats.pending_review || 0;
        document.getElementById('statCasso').textContent = data.stats.casso_today || 0;
      }
    })
    .catch(function(){
      tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:#ef4444">Lỗi khi tải dữ liệu</td></tr>';
    });
}

function renderOrdersTable(orders) {
  var tbody = document.getElementById('ordersBody');
  if (!orders.length) {
    tbody.innerHTML = '<tr><td colspan="9"><div class="pva-empty"><div class="pva-empty-icon">📭</div><p style="font-size:15px;font-weight:600;color:#94a3b8">Không có đơn nào</p><p>Không tìm thấy đơn hàng phù hợp.</p></div></td></tr>';
    return;
  }
  tbody.innerHTML = orders.map(function(o) {
    var statusBadge = '<span class="pva-status pva-status--'+o.payment_status+'">'+statusLabel(o.payment_status)+'</span>';
    var methodBadge = '<span class="pva-method pva-method--'+o.payment_method+'">'+methodLabel(o.payment_method)+'</span>';
    var thumb = o.screenshot_url
      ? '<img src="/pvadmin/admin/screenshot?id='+o.id+'&t='+Date.now()+'" class="pva-thumb" onclick="openModal('+o.id+')" alt="proof" title="Click to view">'
      : '<span class="pva-no-proof">—</span>';
    var canAct = (o.payment_status === 'pending_review');
    var actions = '<div class="pva-action-row">'
      + '<button class="pva-view-btn" onclick="openModal('+o.id+')" title="Xem chi tiết">👁 Chi tiết</button>'
      + (canAct ? '<button class="pva-approve-btn" onclick="quickAction('+o.id+',\'approve\')" title="Duyệt ngay">✓</button>' : '')
      + (canAct ? '<button class="pva-reject-btn" onclick="quickAction('+o.id+',\'reject\')" title="Từ chối">✕</button>' : '')
      + '</div>';
    var amountFmt = Number(o.total_amount).toLocaleString('vi-VN') + 'đ';
    var timeAgo = relativeTime(o.created_at);
    return '<tr>'
      + '<td><input type="checkbox" class="row-check" data-id="'+o.id+'"></td>'
      + '<td><strong style="color:#f1f5f9">#'+escHtml(o.magento_increment_id||o.id)+'</strong><br><span style="font-size:11px;color:#475569">'+escHtml(o.transfer_code)+'</span></td>'
      + '<td><span style="color:#e2e8f0">'+escHtml(o.customer_name)+'</span><br><span style="font-size:11px;color:#475569">'+escHtml(o.customer_email)+'</span></td>'
      + '<td>'+methodBadge+'</td>'
      + '<td><strong style="color:#4ade80">'+amountFmt+'</strong></td>'
      + '<td>'+thumb+'</td>'
      + '<td style="font-size:12px;color:#94a3b8">'+timeAgo+'</td>'
      + '<td>'+statusBadge+'</td>'
      + '<td>'+actions+'</td>'
      + '</tr>';
  }).join('');
}

function openModal(orderId) {
  var o = pvOrders.find(function(x){return x.id == orderId});
  if (!o) return;
  pvCurrentOrderId = orderId;
  document.getElementById('modalTitle').textContent = 'Đơn #' + (o.magento_increment_id || o.id);
  document.getElementById('mOrdId').textContent = '#' + (o.magento_increment_id || o.id) + ' — Mã CK: ' + o.transfer_code;
  document.getElementById('mCustomer').textContent = o.customer_name;
  document.getElementById('mContact').textContent = (o.customer_email || '') + (o.customer_phone ? ' / ' + o.customer_phone : '');
  document.getElementById('mMethod').textContent = methodLabel(o.payment_method);
  var amt = Number(o.total_amount).toLocaleString('vi-VN') + 'đ';
  document.getElementById('mAmount').textContent = amt;
  document.getElementById('chkAmount').textContent = amt;
  document.getElementById('mCreated').textContent = formatDate(o.created_at);
  document.getElementById('mExpires').textContent = formatDate(o.expires_at);

  // Screenshot
  var hasScreenshot = !!o.screenshot_url;
  document.getElementById('modalScreenshot').style.display = hasScreenshot ? '' : 'none';
  document.getElementById('modalNoScreenshot').style.display = hasScreenshot ? 'none' : '';
  if (hasScreenshot) {
    document.getElementById('modalScreenshot').src = '/pvadmin/admin/screenshot?id=' + o.id + '&t=' + Date.now();
  }

  // Show/hide reject note
  document.getElementById('rejectNoteGroup').style.display = '';
  document.getElementById('rejectNote').value = '';

  // Reset checkboxes
  ['chk1','chk2','chk3','chk4','chk5'].forEach(function(id){document.getElementById(id).checked = false});

  // Disable actions if not reviewable
  var canAct = (o.payment_status === 'pending_review' || o.payment_status === 'pending');
  document.getElementById('modalApproveBtn').disabled = !canAct;
  document.getElementById('modalRejectBtn').disabled = (o.payment_status === 'paid' || o.payment_status === 'failed');

  document.getElementById('orderModal').classList.add('active');
}

function closeModal() {
  document.getElementById('orderModal').classList.remove('active');
  pvCurrentOrderId = null;
}

function modalAction(action) {
  if (!pvCurrentOrderId) return;
  var note = document.getElementById('rejectNote').value.trim();
  doAction(pvCurrentOrderId, action, note);
  closeModal();
}

function quickAction(orderId, action) {
  if (action === 'approve') {
    doAction(orderId, 'approve', '');
  } else {
    var note = prompt('Lý do từ chối (tùy chọn):') || '';
    doAction(orderId, 'reject', note);
  }
}

function doAction(orderId, action, note) {
  var endpoint = action === 'approve' ? '/pvadmin/admin/approve' : '/pvadmin/admin/reject';
  fetch(endpoint, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id: orderId, note: note})
  })
  .then(function(r){return r.json()})
  .then(function(data) {
    if (data.success) {
      showToast(action === 'approve' ? '✓ Đã xác nhận thanh toán' : '✕ Đã từ chối thanh toán', action === 'approve' ? 'success' : 'error');
      loadOrders();
    } else {
      showToast('Lỗi: ' + (data.message || 'Unknown'), 'error');
    }
  })
  .catch(function(){showToast('Lỗi kết nối', 'error')});
}

function loadCassoLog() {
  var el = document.getElementById('cassoLogBody');
  el.innerHTML = '<div class="pva-loading"><div class="pva-spinner"></div></div>';
  fetch('/pvadmin/admin/orders?tab=casso_log&date=' + (document.getElementById('filterDate').value || ''))
    .then(function(r){return r.json()})
    .then(function(data) {
      var logs = data.verifications || [];
      if (!logs.length) {
        el.innerHTML = '<div class="pva-empty"><div class="pva-empty-icon">📊</div><p style="color:#94a3b8">Chưa có giao dịch Casso hôm nay</p></div>';
        return;
      }
      el.innerHTML = logs.map(function(l) {
        return '<div class="pva-log-item source-'+escHtml(l.source)+'">'
          + '<div style="display:flex;justify-content:space-between;align-items:center">'
          + '<strong style="color:#f1f5f9">' + (l.source === 'casso' ? '🏦 Casso tự động' : l.source === 'casso_unmatched' ? '⚠ Casso chưa khớp' : '👤 Admin thủ công') + '</strong>'
          + '<span style="color:#4ade80;font-weight:700">' + (l.amount ? Number(l.amount).toLocaleString('vi-VN') + 'đ' : '—') + '</span>'
          + '</div>'
          + '<div class="pva-log-meta">'
          + (l.transaction_id ? '<span>TxID: ' + escHtml(l.transaction_id) + '</span>' : '')
          + '<span>' + formatDate(l.created_at) + '</span>'
          + (l.note ? '<span style="color:#94a3b8">' + escHtml(l.note.substring(0,100)) + '</span>' : '')
          + '</div>'
          + '</div>';
      }).join('');
    });
}

// Helpers
function statusLabel(s) {
  return {pending:'Chờ TT',pending_review:'Chờ xét',paid:'Đã TT',failed:'Thất bại',expired:'Hết hạn'}[s]||s;
}
function methodLabel(m) {
  return {momo:'MoMo',vnpay:'VNPay',bank_qr:'Ngân hàng',card:'PayPal',cod:'COD'}[m]||m;
}
function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function formatDate(d) {
  if (!d) return '—';
  var dt = new Date(d.replace(' ', 'T'));
  return dt.toLocaleString('vi-VN', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
function relativeTime(d) {
  var diff = Math.floor((Date.now() - new Date(d.replace(' ','T')).getTime()) / 1000);
  if (diff < 60) return diff + ' giây trước';
  if (diff < 3600) return Math.floor(diff/60) + ' phút trước';
  if (diff < 86400) return Math.floor(diff/3600) + ' giờ trước';
  return Math.floor(diff/86400) + ' ngày trước';
}
function showToast(msg, type) {
  var wrap = document.getElementById('toastWrap');
  var el = document.createElement('div');
  el.className = 'pva-toast pva-toast--' + (type || 'success');
  el.textContent = msg;
  wrap.appendChild(el);
  setTimeout(function(){el.remove()}, 3500);
}

// Live clock
function updateClock() {
  document.getElementById('pvaLiveTime').textContent = new Date().toLocaleString('vi-VN',{hour:'2-digit',minute:'2-digit',second:'2-digit',day:'2-digit',month:'2-digit'});
}
setInterval(updateClock, 1000);
updateClock();

// Filter events
document.getElementById('filterMethod').addEventListener('change', loadOrders);
document.getElementById('filterDate').addEventListener('change', loadOrders);
document.getElementById('filterSearch').addEventListener('input', function(){
  var self = this;
  clearTimeout(self._t);
  self._t = setTimeout(loadOrders, 400);
});

// Check all
document.getElementById('checkAll').addEventListener('change', function(){
  document.querySelectorAll('.row-check').forEach(function(c){c.checked = document.getElementById('checkAll').checked});
});

// Close modal on backdrop click
document.getElementById('orderModal').addEventListener('click', function(e){
  if (e.target === this) closeModal();
});

// Auto-refresh every 30 seconds when on pending_review tab
function scheduleAutoRefresh() {
  clearTimeout(pvAutoRefreshTimer);
  pvAutoRefreshTimer = setTimeout(function(){
    if (pvCurrentTab === 'pending_review') loadOrders();
    scheduleAutoRefresh();
  }, 30000);
}
scheduleAutoRefresh();

// Initial load
loadOrders();
</script>
</body></html>
HTML;
    }
}
