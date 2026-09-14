<?php

if (!function_exists('admin_e')) {
    function admin_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_header')) {
    function admin_header(string $active, string $subtitle = '', bool $wide = true): void
    {
        $links = [
            'dashboard' => ['label' => 'Dashboard', 'url' => 'index.php'],
            'register_member' => ['label' => 'Register Member', 'url' => 'register_member.php'],
            'manage_jobs' => ['label' => 'Manage Jobs', 'url' => 'manage_jobs.php'],
            'reports' => ['label' => 'Reports', 'url' => 'reports.php'],
            'members' => ['label' => 'Members', 'url' => 'members.php'],
            'loan_applications' => ['label' => 'Loan Applications', 'url' => 'loan_applications.php'],
            'withdrawals' => [
                'label' => 'Withdrawals',
                'url' => 'withdrawals.php',
                'sub' => [
                    'withdraw' => ['label' => 'Withdraw', 'url' => 'withdrawals.php'],
                    'withdrawal_logs' => ['label' => 'Withdrawal Logs', 'url' => 'withdrawal_logs.php'],
                ]
            ],
            'settings' => ['label' => 'Settings', 'url' => 'settings.php'],
        ];
        $shellClass = $wide ? 'admin-shell' : 'admin-shell admin-shell--narrow';
        ?>
        <aside class="admin-sidebar" id="adminSidebar" aria-label="Admin sidebar">
          <div class="admin-sidebar-brand">
            <img src="../assets/img/logo.png" alt="Mashirikiano SACCO" width="44">
            <div class="admin-sidebar-title">
              <span>Mashirikiano</span>
              <small>SACCO Admin</small>
            </div>
          </div>
          <nav class="admin-side-nav">
            <?php foreach ($links as $key => $link): ?>
              <?php if (isset($link['sub'])): ?>
                <?php
                  $isSubActive = ($active === $key || isset($link['sub'][$active]));
                ?>
                <div class="admin-nav-group <?= $isSubActive ? 'open' : '' ?>">
                  <a class="admin-nav-parent <?= $isSubActive ? 'active' : '' ?>" href="<?= admin_e($link['url']) ?>">
                    <span class="admin-nav-mark"><?= admin_e(substr($link['label'], 0, 1)) ?></span>
                    <span class="admin-nav-label flex-grow-1"><?= admin_e($link['label']) ?></span>
                    <span class="admin-nav-arrow">&#9662;</span>
                  </a>
                  <div class="admin-sub-nav">
                    <?php foreach ($link['sub'] as $subKey => $subLink): ?>
                      <a class="admin-sub-item <?= $active === $subKey ? 'active' : '' ?>" href="<?= admin_e($subLink['url']) ?>">
                        <span class="admin-sub-bullet">&bull;</span>
                        <span class="admin-nav-label"><?= admin_e($subLink['label']) ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php else: ?>
                <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= admin_e($link['url'] ?? $link[1]) ?>">
                  <span class="admin-nav-mark"><?= admin_e(substr($link['label'] ?? $link[0], 0, 1)) ?></span>
                  <span class="admin-nav-label"><?= admin_e($link['label'] ?? $link[0]) ?></span>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </nav>
        </aside>
        <div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>

        <header class="admin-header">
          <div class="container-fluid <?= admin_e($shellClass) ?>">
            <div class="admin-header-top">
              <button class="admin-menu-button" type="button" id="adminMenuButton" aria-label="Toggle admin menu" aria-controls="adminSidebar" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
              </button>
              <div class="admin-brand">
                <img src="../assets/img/logo.png" alt="Mashirikiano SACCO" width="48">
                <div>
                  <div class="admin-brand-title">Mashirikiano SACCO Admin</div>
                  <?php if ($subtitle !== ''): ?>
                    <div class="admin-brand-subtitle"><?= admin_e($subtitle) ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <a class="admin-logout-link" href="../auth/admin_logout.php">Logout</a>
            </div>
          </div>
        </header>
        <script>
          (function () {
            const body = document.body;
            const button = document.getElementById('adminMenuButton');
            const overlay = document.getElementById('adminSidebarOverlay');
            const stored = window.localStorage.getItem('adminSidebarCollapsed');

            if (stored === '1') {
              body.classList.add('admin-sidebar-collapsed');
            }

            function isSmallScreen() {
              return window.matchMedia('(max-width: 991px)').matches;
            }

            function syncExpanded() {
              if (button) {
                button.setAttribute('aria-expanded', body.classList.contains('admin-sidebar-open') ? 'true' : 'false');
              }
            }

            if (button) {
              button.addEventListener('click', function () {
                if (isSmallScreen()) {
                  body.classList.toggle('admin-sidebar-open');
                } else {
                  body.classList.toggle('admin-sidebar-collapsed');
                  window.localStorage.setItem('adminSidebarCollapsed', body.classList.contains('admin-sidebar-collapsed') ? '1' : '0');
                }
                syncExpanded();
              });
            }

            if (overlay) {
              overlay.addEventListener('click', function () {
                body.classList.remove('admin-sidebar-open');
                syncExpanded();
              });
            }

            window.addEventListener('resize', function () {
              if (!isSmallScreen()) {
                body.classList.remove('admin-sidebar-open');
              }
              syncExpanded();
            });

            syncExpanded();
          }());
        </script>
        <?php
    }
}
