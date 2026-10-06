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
         * SINGLE / MULTIPLE SELECTED: 1 or more members
         * --------------------------------------------- */
        } elseif ($action === 'single' || $action === 'selected') {
            $rawMemberIds = $_POST['member_ids'] ?? ($_POST['member_id'] ?? []);
            if (!is_array($rawMemberIds)) {
                $rawMemberIds = [$rawMemberIds];
            }
            $memberIds = array_values(array_filter(array_map('trim', $rawMemberIds)));

            if (empty($memberIds)) {
                throw new InvalidArgumentException('Please select at least one member.');
            }

            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $mStmt = $pdo->prepare(
                "SELECT MemberID, FirstName, LastName, PrimaryNumber
                 FROM members
                 WHERE MemberID IN ($placeholders)
                   AND Status = 'Active'"
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

                $_SESSION['msg_flash']      = "Message sent to {$singleName} ({$singlePhone}).";
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

            header('Location: messages.php?tab=single');
            exit;

        } else {
            throw new InvalidArgumentException('Unknown action.');
        }

    } catch (Throwable $e) {
        $_SESSION['msg_flash']      = $e->getMessage();
        $_SESSION['msg_flash_type'] = 'danger';
        if ($action === 'single' || $action === 'selected') {
            header('Location: messages.php?tab=single');
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
$initialTab  = ($_GET['tab'] ?? '') === 'single' ? 'single' : 'bulk';
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
    .tab-sub { font-size:.78rem; font-weight:500; opacity:.78; display:block; margin-top:4px; }

    .msg-panel          { display:none; }
    .msg-panel.active   { display:block; }

    .char-counter      { font-size:.78rem; color:#8ea2b8; text-align:right; margin-top:4px; }
    .char-counter.warn { color:#c0392b; font-weight:700; }

    .recipient-badge {
      display:inline-flex; align-items:center;
      background:#e8f0fe; color:#0b3b66; border-radius:20px;
      padding:6px 15px; font-weight:700; font-size:.88rem;
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
      background:#fff; border-radius:10px; padding:18px 22px;
      box-shadow:0 4px 14px rgba(13,38,67,.07);
      border-left: 4px solid #0b3b66;
    }
    .msg-metric.green { border-left-color: #28a745; }
    .msg-metric-val   { font-size:1.55rem; font-weight:800; color:#0b3b66; line-height:1.1; margin-bottom: 4px; }
    .msg-metric-label { font-size:.74rem; color:#8ea2b8; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
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
      </div>
    </div>

    <!-- Metrics row -->
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="msg-metric">
          <div class="msg-metric-val"><?= he($totalActive) ?></div>
          <div class="msg-metric-label">Active Members</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="msg-metric green">
          <div class="msg-metric-val">OramMobile</div>
          <div class="msg-metric-label">SMS Provider</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="msg-metric">
          <div class="msg-metric-val">160</div>
          <div class="msg-metric-label">Chars per SMS Part</div>
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
            Broadcast to all Members
            <span class="tab-sub">One message to every active member (<?= he($totalActive) ?>)</span>
          </button>
          <button class="msg-tab-btn <?= $initialTab === 'single' ? 'active' : '' ?>" id="tab-single-btn"
                  role="tab" aria-selected="<?= $initialTab === 'single' ? 'true' : 'false' ?>" aria-controls="panel-single"
                  onclick="switchTab('single')">
            Select Member(s)
            <span class="tab-sub">Choose one or more members to send to</span>
          </button>
        </div>

        <!-- ═══════════════ PANEL 1 — BULK ═══════════════ -->
        <div class="msg-panel <?= $initialTab === 'bulk' ? 'active' : '' ?>" id="panel-bulk" role="tabpanel" aria-labelledby="tab-bulk-btn">

          <form method="post" id="form-bulk" onsubmit="return confirmBulk()">
            <input type="hidden" name="action" value="bulk">

            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-bold">Recipients</label>
                <div>
                  <span class="recipient-badge">
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
                  Send to All <?= he($totalActive) ?> Member(s)
                </button>
                <?php if ($totalActive === 0): ?>
                  <span class="text-danger small ms-3">No active members with phone numbers.</span>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div><!-- /panel-bulk -->

        <!-- ═══════════════ PANEL 2 — SINGLE OR MULTIPLE MEMBERS ═══════════════ -->
        <div class="msg-panel <?= $initialTab === 'single' ? 'active' : '' ?>" id="panel-single" role="tabpanel" aria-labelledby="tab-single-btn">
          <form method="post" id="form-single" onsubmit="return validateSingleForm()">
            <input type="hidden" name="action" value="single">

            <div class="row g-3">
              <div class="col-12">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                  <label class="form-label fw-bold mb-0" for="member_ids">Select Member(s)</label>
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-dark border" id="selected-count-badge">0 selected</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllMembers()">Select All</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllMembers()">Clear</button>
                  </div>
                </div>

                <input
                  type="text"
                  class="form-control mb-2"
                  id="memberSearchInput"
                  placeholder="Filter members by name, National ID, or phone..."
                  oninput="filterMemberOptions(this.value)"
                >

                <select
                  class="form-select"
                  id="member_ids"
                  name="member_ids[]"
                  multiple
                  size="8"
                  onchange="updateSelectionCount(this)"
                  required
                >
                  <?php foreach ($members as $m): ?>
                    <?php
                      $phone = trim((string)($m['PrimaryNumber'] ?? ''));
                      $name = trim($m['FirstName'] . ' ' . $m['LastName']);
                      $hasPhone = ($phone !== '');
                    ?>
                    <option
                      value="<?= he($m['MemberID']) ?>"
                      data-name="<?= he($name) ?>"
                      data-phone="<?= he($phone) ?>"
                      data-search="<?= he(strtolower($name . ' ' . $m['NationalID'] . ' ' . $phone)) ?>"
                      <?= !$hasPhone ? 'disabled' : '' ?>
                    >
                      <?= he($name) ?> (ID: <?= he($m['NationalID']) ?> - <?= $hasPhone ? he($phone) : 'No phone' ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Hold Ctrl (or Cmd on Mac) to select multiple members, or use the Select All button.</div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold" for="single-msg">Message</label>
                <textarea
                  class="form-control"
                  id="single-msg"
                  name="message_body"
                  rows="5"
                  maxlength="1600"
                  placeholder="Type your message here&#8230;"
                  oninput="updateCounter(this,'single-counter','single-preview')"
                  required
                ></textarea>
                <div class="char-counter" id="single-counter">0 / 160 (1 SMS part)</div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold">Live Preview</label>
                <div class="sms-preview empty" id="single-preview">Your message will appear here&#8230;</div>
              </div>

              <div class="col-12">
                <button class="btn-send" type="submit" id="single-send-btn">Send Message</button>
              </div>
            </div>
          </form>
        </div><!-- /panel-single -->

      </div><!-- /card-body -->
    </section>
  </main>

  <script>
    function switchTab(tab) {
      ['bulk','single'].forEach(function(t) {
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

    function updateSelectionCount(select) {
      var count = 0;
      for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].selected) {
          count++;
        }
      }
      var badge = document.getElementById('selected-count-badge');
      if (badge) {
        badge.textContent = count + ' selected';
      }
      var btn = document.getElementById('single-send-btn');
      if (btn) {
        if (count === 0) {
          btn.textContent = 'Send Message';
        } else if (count === 1) {
          btn.textContent = 'Send to 1 Member';
        } else {
          btn.textContent = 'Send to ' + count + ' Selected Members';
        }
      }
    }

    function filterMemberOptions(query) {
      var q = query.trim().toLowerCase();
      var select = document.getElementById('member_ids');
      if (!select) return;
      for (var i = 0; i < select.options.length; i++) {
        var opt = select.options[i];
        var s = opt.getAttribute('data-search') || opt.text.toLowerCase();
        if (q === '' || s.indexOf(q) !== -1) {
          opt.style.display = '';
        } else {
          opt.style.display = 'none';
        }
      }
    }

    function selectAllMembers() {
      var select = document.getElementById('member_ids');
      if (!select) return;
      var q = (document.getElementById('memberSearchInput').value || '').trim().toLowerCase();
      for (var i = 0; i < select.options.length; i++) {
        var opt = select.options[i];
        if (opt.disabled) continue;
        if (q !== '' && opt.style.display === 'none') continue;
        opt.selected = true;
      }
      updateSelectionCount(select);
    }

    function deselectAllMembers() {
      var select = document.getElementById('member_ids');
      if (!select) return;
      for (var i = 0; i < select.options.length; i++) {
        select.options[i].selected = false;
      }
      updateSelectionCount(select);
    }

    function confirmBulk() {
      var total = <?= (int)$totalActive ?>;
      return confirm('You are about to send an SMS to ALL ' + total + ' active member(s).\n\nContinue?');
    }

    function validateSingleForm() {
      var select = document.getElementById('member_ids');
      var count = 0;
      if (select) {
        for (var i = 0; i < select.options.length; i++) {
          if (select.options[i].selected) count++;
        }
      }
      if (count === 0) {
        alert('Please select at least one member.');
        return false;
      }
      return confirm('Send message to ' + count + ' selected member(s)?');
    }
  </script>
</body>
</html>
