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

admin_header('loans_portfolio', 'Loan Portfolio Analytics');
?>
<link rel="stylesheet" href="admin.css">
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
  .kpi-card {
    border-radius: 12px;
    border: 0;
    box-shadow: 0 4px 14px rgba(11, 59, 102, 0.06);
    background: #fff;
    padding: 1.5rem;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  .kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(11, 59, 102, 0.12);
  }
  .kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
  }
  .chart-card {
    border-radius: 12px;
    border: 0;
    box-shadow: 0 4px 14px rgba(11, 59, 102, 0.06);
    background: #fff;
    padding: 1.5rem;
    height: 100%;
  }
  .chart-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #0b3b66;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
  }
</style>

<main class="container-fluid admin-shell py-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h1 class="h3 fw-bold text-dark mb-1">Loan Portfolio Analytics</h1>
      <p class="text-muted mb-0">Executive visual dashboard for SACCO decision-making, liquidity monitoring, and credit risk control.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="loans_manage.php" class="btn btn-outline-primary fw-semibold">
        <i class="bi bi-list-check me-1"></i> Manage Loans
      </a>
      <a href="loans_create.php" class="btn btn-primary fw-semibold">
        <i class="bi bi-plus-circle me-1"></i> New Product
      </a>
    </div>
  </div>

  <!-- 1. TOP KPI SUMMARY CARDS -->
  <div class="row g-3 mb-4">
    <!-- Total Principal Disbursed -->
    <div class="col-sm-6 col-xl-3">
      <div class="kpi-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-bold text-uppercase">Principal Disbursed</span>
          <span class="kpi-icon bg-primary bg-opacity-10 text-primary">
            <i class="bi bi-cash-stack"></i>
          </span>
        </div>
        <div class="h3 fw-bold text-dark mb-1">
          KES <?= number_format($analytics['total_principal_disbursed'], 2) ?>
        </div>
        <div class="small text-muted">
          Lifetime funds disbursed across all products
        </div>
      </div>
    </div>

    <!-- Total Outstanding Principal Balance -->
    <div class="col-sm-6 col-xl-3">
      <div class="kpi-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-bold text-uppercase">Outstanding Principal</span>
          <span class="kpi-icon bg-warning bg-opacity-10 text-warning">
            <i class="bi bi-hourglass-split"></i>
          </span>
        </div>
        <div class="h3 fw-bold text-dark mb-1">
          KES <?= number_format($analytics['total_outstanding_principal'], 2) ?>
        </div>
        <div class="small text-muted">
          Active borrowing balance currently in circulation
        </div>
      </div>
    </div>

    <!-- Total Interest & Penalties Collected -->
    <div class="col-sm-6 col-xl-3">
      <div class="kpi-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-bold text-uppercase">Interest & Penalties</span>
          <span class="kpi-icon bg-success bg-opacity-10 text-success">
            <i class="bi bi-graph-up-arrow"></i>
          </span>
        </div>
        <div class="h3 fw-bold text-success mb-1">
          KES <?= number_format($analytics['total_interest_penalties_collected'], 2) ?>
        </div>
        <div class="small text-muted">
          Earned income collected via waterfall repayments
        </div>
      </div>
    </div>

    <!-- Portfolio at Risk (PAR) / Arrears Rate -->
    <div class="col-sm-6 col-xl-3">
      <div class="kpi-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-bold text-uppercase">Portfolio at Risk (PAR)</span>
          <span class="kpi-icon bg-danger bg-opacity-10 text-danger">
            <i class="bi bi-shield-exclamation"></i>
          </span>
        </div>
        <div class="h3 fw-bold <?= $analytics['arrears_rate'] > 5 ? 'text-danger' : 'text-dark' ?> mb-1">
          <?= number_format($analytics['arrears_rate'], 2) ?>%
        </div>
        <div class="small text-muted">
          <?= $analytics['arrears_loans_count'] ?> of <?= $analytics['active_loans_count'] ?> active loan(s) in arrears
        </div>
      </div>
    </div>
  </div>

  <!-- 2. GRAPHICAL CHARTS -->
  <div class="row g-4 mb-4">
    <!-- Disbursements vs Repayments (Trend Chart) -->
    <div class="col-lg-8">
      <div class="chart-card">
        <div class="chart-title">
          <i class="bi bi-bar-chart-line text-primary"></i> Disbursements vs. Repayments (Liquidity Trend)
        </div>
        <div class="small text-muted mb-3">Monthly comparison of disbursed credit versus capital and interest repaid over the last 6 months.</div>
        <div style="position: relative; height: 320px;">
          <canvas id="liquidityChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Loan Status Distribution (Donut Chart) -->
    <div class="col-lg-4">
      <div class="chart-card">
        <div class="chart-title">
          <i class="bi bi-pie-chart text-success"></i> Loan Status Distribution
        </div>
        <div class="small text-muted mb-3">Active vs In Arrears vs Fully Closed loans.</div>
        <div style="position: relative; height: 260px;">
          <canvas id="statusDonutChart"></canvas>
        </div>
        <div class="d-flex justify-content-around text-center mt-3 pt-2 border-top">
          <div>
            <div class="small text-muted">Active</div>
            <div class="fw-bold text-success"><?= $analytics['status_distribution']['ACTIVE'] ?></div>
          </div>
          <div>
            <div class="small text-muted">In Arrears</div>
            <div class="fw-bold text-danger"><?= $analytics['status_distribution']['IN_ARREARS'] ?></div>
          </div>
          <div>
            <div class="small text-muted">Closed</div>
            <div class="fw-bold text-secondary"><?= $analytics['status_distribution']['CLOSED'] ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Borrowing by Loan Product (Horizontal Bar Chart) -->
  <div class="row g-4">
    <div class="col-12">
      <div class="chart-card">
        <div class="chart-title">
          <i class="bi bi-hdd-stack text-warning"></i> Borrowing by Loan Product
        </div>
        <div class="small text-muted mb-3">Compares demand and disbursed principal volume across loan categories.</div>
        <div style="position: relative; height: 280px;">
          <canvas id="productBarChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // 1. Liquidity Chart: Disbursements vs Repayments
  const ctxLiquidity = document.getElementById('liquidityChart').getContext('2d');
  new Chart(ctxLiquidity, {
    type: 'bar',
    data: {
      labels: <?= json_encode($analytics['chart_months']) ?>,
      datasets: [
        {
          label: 'Disbursements (KES)',
          data: <?= json_encode($analytics['chart_disbursements']) ?>,
          backgroundColor: 'rgba(11, 59, 102, 0.75)',
          borderColor: '#0b3b66',
          borderWidth: 1.5,
          borderRadius: 6
        },
        {
          label: 'Repayments (KES)',
          data: <?= json_encode($analytics['chart_repayments']) ?>,
          backgroundColor: 'rgba(40, 167, 69, 0.75)',
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

  // 2. Status Donut Chart
  const ctxStatus = document.getElementById('statusDonutChart').getContext('2d');
  new Chart(ctxStatus, {
    type: 'doughnut',
    data: {
      labels: ['Active', 'In Arrears', 'Closed'],
      datasets: [{
        data: [
          <?= (int)$analytics['status_distribution']['ACTIVE'] ?>,
          <?= (int)$analytics['status_distribution']['IN_ARREARS'] ?>,
          <?= (int)$analytics['status_distribution']['CLOSED'] ?>
        ],
        backgroundColor: ['#28a745', '#dc3545', '#6c757d'],
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
      cutout: '70%'
    }
  });

  // 3. Product Horizontal Bar Chart
  <?php
    $productLabels = [];
    $productAmounts = [];
    foreach ($analytics['product_stats'] as $pStat) {
        $productLabels[] = $pStat['loanType'];
        $productAmounts[] = (float)$pStat['total_principal'];
    }
  ?>
  const ctxProduct = document.getElementById('productBarChart').getContext('2d');
  new Chart(ctxProduct, {
    type: 'bar',
    data: {
      labels: <?= json_encode($productLabels) ?>,
      datasets: [{
        label: 'Total Disbursed (KES)',
        data: <?= json_encode($productAmounts) ?>,
        backgroundColor: 'rgba(244, 166, 33, 0.8)',
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
              return 'Total: KES ' + Number(context.raw).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
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
});
</script>

