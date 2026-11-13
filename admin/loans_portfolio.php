<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/LoanService.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();
Auth::startSession();

$pdo = Database::connection();
LoanService::ensureTables($pdo);

// Get portfolio metrics and chart datasets
$analytics = LoanService::getPortfolioAnalytics($pdo);

// Compute derived metrics safely
$disbursedTotal = (float)($analytics['total_principal_disbursed'] ?? 0);
$repaidTotal    = (float)($analytics['total_principal_repaid'] ?? 0);
$interestTotal  = (float)($analytics['total_interest_penalties_collected'] ?? 0);
$combinedReturn = $repaidTotal + $interestTotal;
$recoveryRate   = ($disbursedTotal > 0) ? round(($repaidTotal / $disbursedTotal) * 100, 1) : 0.0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Loan Portfolio Analytics | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    body { background-color: #f4f6f9; font-family: system-ui, -apple-system, sans-serif; }
    
    .kpi-card {
      border-radius: 14px;
      border: 1px solid rgba(11, 59, 102, 0.08);
      box-shadow: 0 4px 16px rgba(11, 59, 102, 0.05);
      background: #ffffff;
      padding: 1.5rem;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .kpi-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 24px rgba(11, 59, 102, 0.10);
    }
    .kpi-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.45rem;
    }
    .chart-card {
      border-radius: 14px;
      border: 1px solid rgba(11, 59, 102, 0.08);
      box-shadow: 0 4px 16px rgba(11, 59, 102, 0.05);
      background: #ffffff;
      padding: 1.5rem;
      height: 100%;
    }
    .chart-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: #0b3b66;
      margin-bottom: 0.35rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .summary-badge-item {
      background: #ffffff;
      border: 1px solid rgba(11, 59, 102, 0.08);
      border-radius: 10px;
      padding: 0.85rem 1.25rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 8px rgba(11, 59, 102, 0.03);
    }
    .table-custom th {
      background-color: #f8f9fa;
      font-weight: 600;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .table-custom td { vertical-align: middle; }
  </style>
</head>
<body>

  <?php admin_header('loans_portfolio', 'Loan Portfolio Analytics'); ?>

  <main class="container-fluid admin-shell py-4">

    <!-- Top Action / Title Row -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h3 fw-bold text-dark mb-1">Loan Portfolio Analytics</h1>
        <p class="text-muted mb-0">Executive visual dashboard for SACCO capital allocation, liquidity monitoring, and credit risk control.</p>
      </div>
      <div class="d-flex gap-2">
        <a href="loans_manage" class="btn btn-outline-primary fw-semibold">
          Manage Loans
        </a>
        <a href="loans_create" class="btn btn-primary fw-semibold">
          Configure Products
        </a>
      </div>
    </div>

    <!-- 1. TOP KPI SUMMARY CARDS -->
    <div class="row g-3 mb-4">
      <!-- Total Principal Disbursed -->
      <div class="col-sm-6 col-xl-3">
        <div class="kpi-card">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-3">
              <span class="text-muted small fw-bold text-uppercase">Principal Disbursed</span>
            </div>
            <div class="h3 fw-bold text-dark mb-1">
              KES <?= number_format($disbursedTotal, 2) ?>
            </div>
          </div>
          <div class="small text-muted border-top pt-2 mt-3">
            Cumulative credit extended across all products
          </div>
        </div>
      </div>

      <!-- Total Outstanding Principal Balance -->
      <div class="col-sm-6 col-xl-3">
        <div class="kpi-card">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-3">
              <span class="text-muted small fw-bold text-uppercase">Outstanding Principal</span>
            </div>
            <div class="h3 fw-bold text-dark mb-1">
              KES <?= number_format((float)$analytics['total_outstanding_principal'], 2) ?>
            </div>
          </div>
          <div class="small text-muted border-top pt-2 mt-3">
            Active circulating borrowing balance in the market
          </div>
        </div>
      </div>

      <!-- Total Recovered Capital & Earnings -->
      <div class="col-sm-6 col-xl-3">
        <div class="kpi-card">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-3">
              <span class="text-muted small fw-bold text-uppercase">Total Cash Collected</span>
            </div>
            <div class="h3 fw-bold text-success mb-1">
              KES <?= number_format($combinedReturn, 2) ?>
            </div>
          </div>
          <div class="small text-muted border-top pt-2 mt-3 d-flex justify-content-between">
            <span>Principal: <strong>KES <?= number_format($repaidTotal, 2) ?></strong></span>
            <span>Fees: <strong>KES <?= number_format($interestTotal, 2) ?></strong></span>
          </div>
        </div>
      </div>

      <!-- Portfolio at Risk (PAR) / Arrears Rate -->
      <div class="col-sm-6 col-xl-3">
        <div class="kpi-card">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-3">
              <span class="text-muted small fw-bold text-uppercase">Portfolio at Risk (PAR)</span>
            </div>
            <div class="h3 fw-bold <?= $analytics['arrears_rate'] > 5 ? 'text-danger' : 'text-success' ?> mb-1">
              <?= number_format($analytics['arrears_rate'], 2) ?>%
            </div>
          </div>
          <div class="small text-muted border-top pt-2 mt-3">
            <?= (int)$analytics['arrears_loans_count'] ?> of <?= (int)$analytics['active_loans_count'] ?> active loan(s) currently in arrears
          </div>
        </div>
      </div>
    </div>

    <!-- 2. QUICK STATS RIBBON -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="summary-badge-item">
          <div>
            <div class="small text-muted text-uppercase fw-semibold" style="font-size:0.75rem;">Active Borrowers</div>
            <div class="fs-5 fw-bold text-success"><?= (int)$analytics['status_distribution']['ACTIVE'] ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-badge-item">
          <div>
            <div class="small text-muted text-uppercase fw-semibold" style="font-size:0.75rem;">In Arrears</div>
            <div class="fs-5 fw-bold text-danger"><?= (int)$analytics['status_distribution']['IN_ARREARS'] ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-badge-item">
          <div>
            <div class="small text-muted text-uppercase fw-semibold" style="font-size:0.75rem;">Fully Settled</div>
            <div class="fs-5 fw-bold text-secondary"><?= (int)$analytics['status_distribution']['CLOSED'] ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-badge-item">
          <div>
            <div class="small text-muted text-uppercase fw-semibold" style="font-size:0.75rem;">Recovery Rate</div>
            <div class="fs-5 fw-bold text-primary"><?= number_format($recoveryRate, 1) ?>%</div>
          </div>
        </div>
      </div>
    </div>

    <!-- 3. GRAPHICAL CHARTS ROW -->
    <div class="row g-4 mb-4">
      <!-- Disbursements vs Repayments (Liquidity Trend) -->
      <div class="col-lg-8">
        <div class="chart-card">
          <div class="chart-title">
            Disbursements vs. Repayments (Liquidity Trend)
          </div>
          <div class="small text-muted mb-3">Monthly comparison of disbursed credit volume versus cash repayments collected over the last 6 months.</div>
          <div style="position: relative; height: 320px;">
            <canvas id="liquidityChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Loan Status Distribution (Donut Chart) -->
      <div class="col-lg-4">
        <div class="chart-card">
          <div class="chart-title">
            Loan Status Distribution
          </div>
          <div class="small text-muted mb-3">Breakdown of operational portfolio states.</div>
          <div style="position: relative; height: 240px;">
            <canvas id="statusDonutChart"></canvas>
          </div>
          <div class="d-flex justify-content-around text-center mt-3 pt-3 border-top">
            <div>
              <div class="small text-muted">Active</div>
              <div class="fw-bold text-success fs-5"><?= (int)$analytics['status_distribution']['ACTIVE'] ?></div>
            </div>
            <div>
              <div class="small text-muted">In Arrears</div>
              <div class="fw-bold text-danger fs-5"><?= (int)$analytics['status_distribution']['IN_ARREARS'] ?></div>
            </div>
            <div>
              <div class="small text-muted">Closed</div>
              <div class="fw-bold text-secondary fs-5"><?= (int)$analytics['status_distribution']['CLOSED'] ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 4. PRODUCT VOLUME & TABLE ROW -->
    <div class="row g-4">
      <!-- Borrowing by Loan Product (Horizontal Bar Chart) -->
      <div class="col-lg-7">
        <div class="chart-card">
          <div class="chart-title">
            Borrowing Volume by Loan Facility
          </div>
          <div class="small text-muted mb-3">Compares borrower volume and capital concentration across individual loan types.</div>
          <div style="position: relative; height: 290px;">
            <canvas id="productBarChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Breakdown Table -->
      <div class="col-lg-5">
        <div class="chart-card">
          <div class="chart-title">
            Facility Allocation Breakdown
          </div>
          <div class="small text-muted mb-3">Live distribution by loan product.</div>
          <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
              <thead>
                <tr>
                  <th>Product</th>
                  <th class="text-center">Loans</th>
                  <th class="text-end">Total Disbursed</th>
                  <th class="text-end">Share</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($analytics['product_stats'])): ?>
                  <?php foreach ($analytics['product_stats'] as $ps): ?>
                    <?php 
                      $pAmt = (float)$ps['total_principal'];
                      $share = ($disbursedTotal > 0) ? round(($pAmt / $disbursedTotal) * 100, 1) : 0;
                    ?>
                    <tr>
                      <td class="fw-bold text-dark"><?= admin_e($ps['loanType']) ?></td>
                      <td class="text-center">
                        <span class="badge bg-light text-dark border"><?= (int)$ps['loan_count'] ?></span>
                      </td>
                      <td class="text-end fw-semibold text-success">
                        KES <?= number_format($pAmt, 2) ?>
                      </td>
                      <td class="text-end">
                        <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold"><?= $share ?>%</span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="4" class="text-center py-4 text-muted">
                      No active loans disbursed yet.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </main>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    // 1. Liquidity Chart: Disbursements vs Repayments
    const ctxLiquidity = document.getElementById('liquidityChart');
    if (ctxLiquidity) {
      new Chart(ctxLiquidity.getContext('2d'), {
        type: 'bar',
        data: {
          labels: <?= json_encode($analytics['chart_months'] ?? []) ?>,
          datasets: [
            {
              label: 'Disbursements (KES)',
              data: <?= json_encode($analytics['chart_disbursements'] ?? []) ?>,
              backgroundColor: 'rgba(11, 59, 102, 0.82)',
              borderColor: '#0b3b66',
              borderWidth: 1.5,
              borderRadius: 6
            },
            {
              label: 'Repayments (KES)',
              data: <?= json_encode($analytics['chart_repayments'] ?? []) ?>,
              backgroundColor: 'rgba(40, 167, 69, 0.82)',
              borderColor: '#28a745',
              borderWidth: 1.5,
              borderRadius: 6
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'top' },
            tooltip: {
              callbacks: {
                label: function(context) {
                  return context.dataset.label + ': KES ' + Number(context.raw).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                callback: function(value) {
                  return 'KES ' + Number(value).toLocaleString();
                }
              }
            }
          }
        }
      });
    }

    // 2. Status Donut Chart
    const ctxStatus = document.getElementById('statusDonutChart');
    if (ctxStatus) {
      const activeCnt = <?= (int)($analytics['status_distribution']['ACTIVE'] ?? 0) ?>;
      const arrearsCnt = <?= (int)($analytics['status_distribution']['IN_ARREARS'] ?? 0) ?>;
      const closedCnt = <?= (int)($analytics['status_distribution']['CLOSED'] ?? 0) ?>;
      const totalCnt = activeCnt + arrearsCnt + closedCnt;

      new Chart(ctxStatus.getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: ['Active', 'In Arrears', 'Closed'],
          datasets: [{
            data: totalCnt > 0 ? [activeCnt, arrearsCnt, closedCnt] : [1, 0, 0],
            backgroundColor: totalCnt > 0 ? ['#28a745', '#dc3545', '#6c757d'] : ['#e9ecef', '#e9ecef', '#e9ecef'],
            borderWidth: 2,
            hoverOffset: 4
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom' }
          },
          cutout: '72%'
        }
      });
    }

    // 3. Product Horizontal Bar Chart
    <?php
      $productLabels = [];
      $productAmounts = [];
      if (!empty($analytics['product_stats'])) {
          foreach ($analytics['product_stats'] as $pStat) {
              $productLabels[] = $pStat['loanType'];
              $productAmounts[] = (float)$pStat['total_principal'];
          }
      }
    ?>
    const ctxProduct = document.getElementById('productBarChart');
    if (ctxProduct) {
      const prodLabels = <?= json_encode($productLabels) ?>;
      const prodData = <?= json_encode($productAmounts) ?>;

      new Chart(ctxProduct.getContext('2d'), {
        type: 'bar',
        data: {
          labels: prodLabels.length > 0 ? prodLabels : ['No active loans'],
          datasets: [{
            label: 'Total Disbursed (KES)',
            data: prodData.length > 0 ? prodData : [0],
            backgroundColor: 'rgba(228, 112, 1, 0.85)',
            borderColor: '#e47001',
            borderWidth: 1.5,
            borderRadius: 6
          }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function(context) {
                  return 'Total Disbursed: KES ' + Number(context.raw).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
              }
            }
          },
          scales: {
            x: {
              beginAtZero: true,
              ticks: {
                callback: function(value) {
                  return 'KES ' + Number(value).toLocaleString();
                }
              }
            }
          }
        }
      });
    }
  });
  </script>
</body>
</html>
