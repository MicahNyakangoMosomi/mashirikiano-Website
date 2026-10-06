<?php

require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SmsService.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();
Auth::startSession();

$pdo       = Database::connection();
$flash     = '';
$flashType = 'info';

/* -------------------------------------------------------
 * Read and clear flash from session
 * ----------------------------------------------------- */
if (isset($_SESSION['msg_flash'])) {
    $flash     = $_SESSION['msg_flash'];
    $flashType = $_SESSION['msg_flash_type'] ?? 'info';
    unset($_SESSION['msg_flash'], $_SESSION['msg_flash_type']);
}

/* -------------------------------------------------------
 * Fetch all active members
 * ----------------------------------------------------- */
$members = [];
try {
    $stmt = $pdo->query(
        "SELECT MemberID, NationalID, FirstName, LastName, PrimaryNumber
         FROM members
         WHERE Status = 'Active'
         ORDER BY FirstName, LastName"
    );
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('messages.php member fetch: ' . $e->getMessage());
}

/* -------------------------------------------------------
 * POST handler
 * ----------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = trim((string)($_POST['action']       ?? ''));
    $messageBody = trim((string)($_POST['message_body'] ?? ''));

    try {
        if ($messageBody === '') {
            throw new InvalidArgumentException('Message body cannot be empty.');
        }
        if (strlen($messageBody) > 1600) {
            throw new InvalidArgumentException('Message exceeds maximum length of 1600 characters.');
        }

        /* -----------------------------------------------
         * BULK: POST /api/v1/messages/bulk
         * Body: { sender_id, phones: [...], message }
         * --------------------------------------------- */
        if ($action === 'bulk') {
            $phoneStmt = $pdo->query(
                "SELECT PrimaryNumber FROM members
                 WHERE Status = 'Active'
                   AND PrimaryNumber IS NOT NULL
                   AND PrimaryNumber <> ''"
            );
            $phones = array_column($phoneStmt->fetchAll(PDO::FETCH_ASSOC), 'PrimaryNumber');

            if (empty($phones)) {
                throw new RuntimeException('No active members with a phone number found.');
            }

            $result = SmsService::sendBulkSms($phones, $messageBody);

            if (!$result['success']) {
                throw new RuntimeException('Bulk send failed: ' . ($result['error'] ?? 'Unknown error'));
            }

            $queued = $result['data']['queued'] ?? count($phones);
            $_SESSION['msg_flash']      = "Bulk SMS queued successfully for {$queued} recipient(s).";
            $_SESSION['msg_flash_type'] = 'success';

        /* -----------------------------------------------
         * SELECTED: One or more selected members
         * --------------------------------------------- */
        } elseif ($action === 'selected' || $action === 'single') {
            $rawMemberIds = $_POST['member_ids'] ?? ($_POST['member_id'] ?? []);
            if (!is_array($rawMemberIds)) {
                $rawMemberIds = [$rawMemberIds];
            }
            $memberIds = array_values(array_filter(array_map('trim', $rawMemberIds)));

            if (empty($memberIds)) {
                throw new InvalidArgumentException('Please select at least one member to receive the message.');
            }

            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $mStmt = $pdo->prepare(
                "SELECT MemberID, FirstName, LastName, PrimaryNumber
                 FROM members
                 WHERE MemberID IN ($placeholders) AND Status = 'Active'"
            );
            $mStmt->execute($memberIds);
            $selectedMembers = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($selectedMembers)) {
                throw new RuntimeException('None of the selected members were found or active.');
            }

            $validMembers = array_values(array_filter($selectedMembers, function ($m) {
                return !empty(trim((string)($m['PrimaryNumber'] ?? '')));
            }));

            if (empty($validMembers)) {
                throw new RuntimeException('The selected member(s) do not have a valid phone number on record.');
            }

            $phones = array_column($validMembers, 'PrimaryNumber');
            $count  = count($phones);

            if ($count === 1) {
                $singleMember = $validMembers[0];
                $singlePhone  = $singleMember['PrimaryNumber'];
                $singleName   = trim($singleMember['FirstName'] . ' ' . $singleMember['LastName']);

                $sent = SmsService::sendSms($singlePhone, $messageBody);
                if (!$sent) {
                    throw new RuntimeException("Message delivery failed to {$singleName} ({$singlePhone}). Check SMS logs.");
                }

                $_SESSION['msg_flash']      = "Message sent successfully to {$singleName} ({$singlePhone}).";
                $_SESSION['msg_flash_type'] = 'success';
            } else {
                $result = SmsService::sendBulkSms($phones, $messageBody);
                if (!$result['success']) {
                    throw new RuntimeException('Bulk send failed: ' . ($result['error'] ?? 'Unknown error'));
                }

                $queued = $result['data']['queued'] ?? $count;
                $_SESSION['msg_flash']      = "Message queued successfully for {$queued} selected member(s).";
                $_SESSION['msg_flash_type'] = 'success';
            }

            header('Location: messages.php?tab=selected');
            exit;

        } else {
            throw new InvalidArgumentException('Unknown action.');
        }

    } catch (Throwable $e) {
        $_SESSION['msg_flash']      = $e->getMessage();
        $_SESSION['msg_flash_type'] = 'danger';
        if ($action === 'selected' || $action === 'single') {
            header('Location: messages.php?tab=selected');
            exit;
        }
    }

    header('Location: messages.php');
    exit;
}

/* -------------------------------------------------------
 * Helper
 * ----------------------------------------------------- */
function he($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$totalActive = count($members);
$initialTab  = ($_GET['tab'] ?? '') === 'selected' ? 'selected' : 'bulk';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Send Messages | Mashirikiano SACCO Admin</title>
  <link rel="icon" type="image/x-icon" href="../assets/img/logo.png">
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    .msg-tabs { display:flex; gap:10px; margin-bottom:28px; }
    .msg-tab-btn {
      flex:1; padding:14px 12px; border-radius:10px; border:2px solid #dce5ee;
      background:#fff; font-weight:700; font-size:.95rem; color:#617083;
      cursor:pointer; transition:all .18s ease; text-align:center; line-height:1.3;
    }
    .msg-tab-btn:hover { border-color:#0b3b66; color:#0b3b66; }
    .msg-tab-btn.active {
      background:#0b3b66; border-color:#0b3b66; color:#fff;
      box-shadow:0 4px 18px rgba(11,59,102,.22);
    }
    .tab-icon  { font-size:1.5rem; display:block; margin-bottom:4px; }
    .tab-sub   { font-size:.78rem; font-weight:500; opacity:.78; display:block; margin-top:2px; }

    .msg-panel          { display:none; }
    .msg-panel.active   { display:block; }

    .char-counter      { font-size:.78rem; color:#8ea2b8; text-align:right; margin-top:4px; }
    .char-counter.warn { color:#c0392b; font-weight:700; }

    .recipient-badge {
      display:inline-flex; align-items:center; gap:7px;
      background:#e8f0fe; color:#0b3b66; border-radius:20px;
      padding:6px 15px; font-weight:700; font-size:.88rem;
    }
    .recipient-badge .dot {
      width:9px; height:9px; border-radius:50%; background:#28a745; flex-shrink:0;
    }

    .sms-preview {
      background:#f5f7fa; border:1px solid #dce5ee; border-radius:10px;
      padding:14px 16px; font-size:.92rem; color:#344055;
      min-height:58px; white-space:pre-wrap; word-break:break-word;
    }
    .sms-preview.empty { color:#adb5bd; font-style:italic; }

    .btn-send {
      background:#0b3b66; color:#fff; border:none; border-radius:8px;
      padding:12px 36px; font-weight:700; font-size:1rem; cursor:pointer;
      transition:background .18s, opacity .18s;
    }
    .btn-send:hover    { background:#0d4d85; }
    .btn-send:disabled { opacity:.55; cursor:not-allowed; }

    .msg-metric {
      background:#fff; border-radius:10px; padding:16px 20px;
      box-shadow:0 4px 14px rgba(13,38,67,.07);
      display:flex; align-items:center; gap:14px;
    }
    .msg-metric-icon {
      width:46px; height:46px; border-radius:10px;
      display:flex; align-items:center; justify-content:center;
      font-size:1.4rem; flex-shrink:0;
    }
    .bg-blue  { background:#e8f0fe; }
    .bg-green { background:#e6f4ea; }
    .msg-metric-val   { font-size:1.55rem; font-weight:800; color:#0b3b66; line-height:1.1; }
    .msg-metric-label { font-size:.74rem; color:#8ea2b8; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

    /* Member multi-select picker styles */
    .member-picker-box {
      border: 1px solid #dce5ee;
      border-radius: 10px;
      background: #fafbfc;
      padding: 14px;
    }
    .member-scroll-list {
      max-height: 270px;
      overflow-y: auto;
      border: 1px solid #e1e7ee;
      border-radius: 8px;
      background: #fff;
      padding: 6px;
    }
    .member-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 12px;
      border-radius: 6px;
      margin-bottom: 3px;
      cursor: pointer;
      user-select: none;
      transition: background .15s ease;
    }
    .member-row:hover {
      background: #f1f5fa;
    }
    .member-row.selected {
      background: #e8f0fe;
    }
    .member-row.has-no-phone {
      opacity: .5;
      cursor: not-allowed;
    }
    .member-chips-wrap {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      max-height: 90px;
      overflow-y: auto;
      margin-top: 10px;
      padding-top: 6px;
      border-top: 1px dashed #e1e7ee;
    }
    .member-chip {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: #0b3b66;
      color: #fff;
      font-size: .78rem;
      font-weight: 600;
      border-radius: 14px;
      padding: 3px 10px;
    }
    .member-chip-close {
      cursor: pointer;
      font-size: .95rem;
      line-height: 1;
      opacity: .8;
    }
    .member-chip-close:hover {
      opacity: 1;
    }
  </style>
</head>
<body>
  <?php admin_header('messages', 'Send Messages to Members', false); ?>

  <main class="container-fluid admin-shell py-4">

    <?php if ($flash !== ''): ?>
      <div class="alert alert-<?= he($flashType) ?> mb-3" role="alert"><?= he($flash) ?></div>
    <?php endif; ?>

    <div class="d-flex align-items-center gap-3 mb-4">
      <div>
        <h1 class="h4 fw-bold mb-0">Send Messages</h1>
        <p class="text-muted mb-0 small">SMS notifications via OramMobile API</p>
      </div>
    </div>

    <!-- Metrics row -->
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="msg-metric">
          <div class="msg-metric-icon bg-blue">&#128101;</div>
          <div>
            <div class="msg-metric-val"><?= he($totalActive) ?></div>
            <div class="msg-metric-label">Active Members</div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="msg-metric">
          <div class="msg-metric-icon bg-green">&#128225;</div>
          <div>
            <div class="msg-metric-val">OramMobile</div>
            <div class="msg-metric-label">SMS Provider</div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="msg-metric">
          <div class="msg-metric-icon bg-blue">&#9993;&#65039;</div>
          <div>
            <div class="msg-metric-val">160</div>
            <div class="msg-metric-label">Chars per SMS Part</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main card -->
    <section class="card panel">
      <div class="card-body p-4">

        <!-- Tab switcher -->
        <div class="msg-tabs" role="tablist">
          <button class="msg-tab-btn <?= $initialTab === 'bulk' ? 'active' : '' ?>" id="tab-bulk-btn"
                  role="tab" aria-selected="<?= $initialTab === 'bulk' ? 'true' : 'false' ?>" aria-controls="panel-bulk"
                  onclick="switchTab('bulk')">
            <span class="tab-icon">&#128226;</span>
            Broadcast to All Members
            <span class="tab-sub">One message &rarr; every active member (<?= he($totalActive) ?>)</span>
          </button>
          <button class="msg-tab-btn <?= $initialTab === 'selected' ? 'active' : '' ?>" id="tab-selected-btn"
                  role="tab" aria-selected="<?= $initialTab === 'selected' ? 'true' : 'false' ?>" aria-controls="panel-selected"
                  onclick="switchTab('selected')">
            <span class="tab-icon">&#128101;</span>
            Select Member(s)
            <span class="tab-sub">Choose one or more specific members</span>
          </button>
        </div>

        <!-- ═══════════════ PANEL 1 — BROADCAST TO ALL ═══════════════ -->
        <div class="msg-panel <?= $initialTab === 'bulk' ? 'active' : '' ?>" id="panel-bulk" role="tabpanel" aria-labelledby="tab-bulk-btn">

          <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-4" role="note">
            <span>&#8505;&#65039;</span>
            <div>
              Sends the <strong>same message</strong> to all
              <strong><?= he($totalActive) ?> active member(s)</strong> in one operation using the
              <strong>OramMobile Bulk SMS API</strong>
              (<code>POST /api/v1/messages/bulk</code>). Long messages are automatically split.
            </div>
          </div>

          <form method="post" id="form-bulk" onsubmit="return confirmBulk()">
            <input type="hidden" name="action" value="bulk">

            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-bold">Recipients</label>
                <div>
                  <span class="recipient-badge">
                    <span class="dot"></span>
                    All <?= he($totalActive) ?> active member(s)
                  </span>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold" for="bulk-msg">Message</label>
                <textarea
                  class="form-control"
                  id="bulk-msg"
                  name="message_body"
                  rows="5"
                  maxlength="1600"
                  placeholder="Type your broadcast message here&#8230;"
                  oninput="updateCounter(this,'bulk-counter','bulk-preview')"
                  required
                ></textarea>
                <div class="char-counter" id="bulk-counter">0 / 160 (1 SMS part)</div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold">Live Preview</label>
                <div class="sms-preview empty" id="bulk-preview">Your message will appear here&#8230;</div>
              </div>

              <div class="col-12">
                <button class="btn-send" type="submit"
                        <?= $totalActive === 0 ? 'disabled' : '' ?>>
                  &#128226; Send to All <?= he($totalActive) ?> Member(s)
                </button>
                <?php if ($totalActive === 0): ?>
                  <span class="text-danger small ms-3">No active members found.</span>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div><!-- /panel-bulk -->

        <!-- ═══════════════ PANEL 2 — SELECT ONE OR MORE MEMBERS ═══════════════ -->
        <div class="msg-panel <?= $initialTab === 'selected' ? 'active' : '' ?>" id="panel-selected" role="tabpanel" aria-labelledby="tab-selected-btn">

          <div class="alert alert-light border d-flex align-items-start gap-2 py-2 mb-4" role="note">
            <span>&#128161;</span>
            <div>
              Search and select <strong>one or multiple members</strong> below. You can also click <strong>Select All</strong> or search to filter specific members.
              Single recipients use direct delivery; multiple recipients use the bulk delivery API.
            </div>
          </div>

          <form method="post" id="form-selected" onsubmit="return validateSelectedForm()">
            <input type="hidden" name="action" value="selected">

            <div class="row g-3">
              <div class="col-12">
                <div class="member-picker-box">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <label class="form-label fw-bold mb-0">Select Recipient Members</label>
                    <div class="d-flex align-items-center gap-2">
                      <span class="recipient-badge">
                        <span class="dot"></span>
                        <span id="selected-counter-text">0 selected</span>
                      </span>
                      <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" onclick="selectAllEligible()">Select All</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold" onclick="clearAllSelections()">Clear All</button>
                    </div>
                  </div>

                  <!-- Quick search filter -->
                  <div class="input-group mb-2">
                    <span class="input-group-text bg-white">&#128269;</span>
                    <input
                      type="text"
                      class="form-control"
                      id="memberSearchInput"
                      placeholder="Type to filter by name, National ID, or phone number..."
                      oninput="filterMemberList(this.value)"
                    >
                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('memberSearchInput').value=''; filterMemberList('');">Reset</button>
                  </div>

                  <!-- Member Checkbox List -->
                  <div class="member-scroll-list" id="memberScrollList">
                    <?php if (empty($members)): ?>
                      <div class="text-muted p-3 text-center small">No active members found in the database.</div>
                    <?php else: ?>
                      <?php foreach ($members as $m): ?>
                        <?php
                          $name    = trim($m['FirstName'] . ' ' . $m['LastName']);
                          $phone   = trim((string)($m['PrimaryNumber'] ?? ''));
                          $natId   = trim((string)($m['NationalID'] ?? ''));
                          $hasPhone = ($phone !== '');
                          $searchStr = strtolower($name . ' ' . $natId . ' ' . $phone);
                        ?>
                        <label
                          class="member-row <?= !$hasPhone ? 'has-no-phone' : '' ?>"
                          data-search="<?= he($searchStr) ?>"
                          data-name="<?= he($name) ?>"
                          data-phone="<?= he($phone) ?>"
                          data-id="<?= he($m['MemberID']) ?>"
                          title="<?= !$hasPhone ? 'Member has no phone number on record' : '' ?>"
                        >
                          <div class="d-flex align-items-center gap-2">
                            <input
                              type="checkbox"
                              name="member_ids[]"
                              value="<?= he($m['MemberID']) ?>"
                              class="form-check-input member-checkbox"
                              <?= !$hasPhone ? 'disabled' : '' ?>
                              onchange="onMemberCheckboxChange(this)"
                            >
                            <span class="member-name-text fw-semibold"><?= he($name) ?></span>
                          </div>
                          <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-light text-dark border">ID: <?= he($natId) ?></span>
                            <?php if ($hasPhone): ?>
                              <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= he($phone) ?></span>
                            <?php else: ?>
                              <span class="badge bg-danger-subtle text-danger border border-danger-subtle">No phone</span>
                            <?php endif; ?>
                          </div>
                        </label>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>

                  <!-- Selected chips preview -->
                  <div class="member-chips-wrap" id="memberChipsWrap" style="display:none;"></div>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold" for="selected-msg">Message</label>
                <textarea
                  class="form-control"
                  id="selected-msg"
                  name="message_body"
                  rows="5"
                  maxlength="1600"
                  placeholder="Type your message for selected member(s) here&#8230;"
                  oninput="updateCounter(this,'selected-counter','selected-preview')"
                  required
                ></textarea>
                <div class="char-counter" id="selected-counter">0 / 160 (1 SMS part)</div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold">Live Preview</label>
                <div class="sms-preview empty" id="selected-preview">Your message will appear here&#8230;</div>
              </div>

              <div class="col-12">
                <button class="btn-send" type="submit" id="selected-send-btn" disabled>
                  &#128101; Send to Selected Member(s)
                </button>
                <span class="text-muted small ms-3" id="selection-hint">Please select at least one member above.</span>
              </div>
            </div>
          </form>
        </div><!-- /panel-selected -->

      </div><!-- /card-body -->
    </section>
  </main>

  <script>
    function switchTab(tab) {
      ['bulk','selected'].forEach(function(t) {
        var panel = document.getElementById('panel-' + t);
        if (panel) panel.classList.toggle('active', t === tab);
        var btn = document.getElementById('tab-' + t + '-btn');
        if (btn) {
          btn.classList.toggle('active', t === tab);
          btn.setAttribute('aria-selected', t === tab ? 'true' : 'false');
        }
      });
      if (window.history && window.history.replaceState) {
        var url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
      }
    }

    function updateCounter(textarea, counterId, previewId) {
      var len   = textarea.value.length;
      var parts = len === 0 ? 1 : Math.ceil(len / 160);
      var el    = document.getElementById(counterId);
      if (el) {
        el.textContent = len + ' / ' + (parts * 160) + ' (' + parts + ' SMS part' + (parts > 1 ? 's' : '') + ')';
        el.classList.toggle('warn', len > 160);
      }
      var preview = document.getElementById(previewId);
      if (preview) {
        if (textarea.value.trim() === '') {
          preview.textContent = 'Your message will appear here\u2026';
          preview.classList.add('empty');
        } else {
          preview.textContent = textarea.value;
          preview.classList.remove('empty');
        }
      }
    }

    function confirmBulk() {
      var total = <?= (int)$totalActive ?>;
      return confirm('You are about to send an SMS to ALL ' + total + ' active member(s).\n\nContinue?');
    }

    /* ----------------------------------------------------
     * Member multi-selection logic
     * -------------------------------------------------- */
    function filterMemberList(query) {
      var q = query.trim().toLowerCase();
      var rows = document.querySelectorAll('#memberScrollList .member-row');
      rows.forEach(function(row) {
        var search = row.getAttribute('data-search') || '';
        if (q === '' || search.indexOf(q) !== -1) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }

    function onMemberCheckboxChange(cb) {
      var row = cb.closest('.member-row');
      if (row) {
        row.classList.toggle('selected', cb.checked);
      }
      refreshSelectionState();
    }

    function selectAllEligible() {
      var searchInput = document.getElementById('memberSearchInput');
      var isFiltering = searchInput && searchInput.value.trim() !== '';

      var rows = document.querySelectorAll('#memberScrollList .member-row');
      rows.forEach(function(row) {
        if (isFiltering && row.style.display === 'none') {
          return; // Skip hidden rows if actively filtering
        }
        var cb = row.querySelector('.member-checkbox');
        if (cb && !cb.disabled) {
          cb.checked = true;
          row.classList.add('selected');
        }
      });
      refreshSelectionState();
    }

    function clearAllSelections() {
      var rows = document.querySelectorAll('#memberScrollList .member-row');
      rows.forEach(function(row) {
        var cb = row.querySelector('.member-checkbox');
        if (cb) {
          cb.checked = false;
        }
        row.classList.remove('selected');
      });
      refreshSelectionState();
    }

    function unselectMember(memberId) {
      var cb = document.querySelector('.member-checkbox[value="' + memberId + '"]');
      if (cb) {
        cb.checked = false;
        var row = cb.closest('.member-row');
        if (row) row.classList.remove('selected');
      }
      refreshSelectionState();
    }

    function refreshSelectionState() {
      var checkedCbs = document.querySelectorAll('.member-checkbox:checked');
      var count = checkedCbs.length;

      var counterEl = document.getElementById('selected-counter-text');
      if (counterEl) {
        counterEl.textContent = count + ' selected';
      }

      var sendBtn = document.getElementById('selected-send-btn');
      var hintEl  = document.getElementById('selection-hint');
      if (sendBtn) {
        sendBtn.disabled = (count === 0);
        if (count === 0) {
          sendBtn.innerHTML = '&#128101; Send to Selected Member(s)';
          if (hintEl) hintEl.textContent = 'Please select at least one member above.';
        } else if (count === 1) {
          sendBtn.innerHTML = '&#128101; Send to 1 Member';
          if (hintEl) hintEl.textContent = 'Will be sent via single SMS.';
        } else {
          sendBtn.innerHTML = '&#128101; Send to ' + count + ' Selected Members';
          if (hintEl) hintEl.textContent = 'Will be sent via Bulk SMS API.';
        }
      }

      // Render chips
      var chipsWrap = document.getElementById('memberChipsWrap');
      if (chipsWrap) {
        if (count === 0) {
          chipsWrap.innerHTML = '';
          chipsWrap.style.display = 'none';
        } else {
          chipsWrap.style.display = 'flex';
          var html = '';
          var maxShow = 15;
          var shown = 0;
          checkedCbs.forEach(function(cb) {
            if (shown >= maxShow) return;
            var row = cb.closest('.member-row');
            var name = row ? row.getAttribute('data-name') : 'Member';
            var id = cb.value;
            html += '<span class="member-chip">' +
                    escapeHtml(name) +
                    ' <span class="member-chip-close" onclick="unselectMember(\'' + id + '\')" title="Remove">&times;</span>' +
                    '</span>';
            shown++;
          });
          if (count > maxShow) {
            html += '<span class="badge bg-secondary align-self-center">+' + (count - maxShow) + ' more</span>';
          }
          chipsWrap.innerHTML = html;
        }
      }
    }

    function escapeHtml(str) {
      return (str || '').replace(/[&<>"']/g, function(m) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
      });
    }

    function validateSelectedForm() {
      var checkedCbs = document.querySelectorAll('.member-checkbox:checked');
      if (checkedCbs.length === 0) {
        alert('Please select at least one member before sending.');
        return false;
      }
      var count = checkedCbs.length;
      return confirm('Are you sure you want to send this message to ' + count + ' selected member(s)?');
    }
  </script>
</body>
</html>
