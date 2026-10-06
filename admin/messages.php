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
         * SINGLE: POST /api/v1/messages
         * --------------------------------------------- */
        } elseif ($action === 'single') {
            $memberId = trim((string)($_POST['member_id'] ?? ''));
            if ($memberId === '') {
                throw new InvalidArgumentException('Please select a member.');
            }

            $mStmt = $pdo->prepare(
                "SELECT FirstName, LastName, PrimaryNumber
                 FROM members
                 WHERE MemberID = :id AND Status = 'Active'
                 LIMIT 1"
            );
            $mStmt->execute([':id' => $memberId]);
            $member = $mStmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                throw new RuntimeException('Member not found or is not active.');
            }

            $phone = trim((string)($member['PrimaryNumber'] ?? ''));
            if ($phone === '') {
                throw new RuntimeException('This member has no phone number on record.');
            }

            $sent = SmsService::sendSms($phone, $messageBody);
            if (!$sent) {
                throw new RuntimeException('Message delivery failed. Check SMS error logs.');
            }

            $name = trim($member['FirstName'] . ' ' . $member['LastName']);
            $_SESSION['msg_flash']      = "Message sent to {$name} ({$phone}).";
            $_SESSION['msg_flash_type'] = 'success';

        } else {
            throw new InvalidArgumentException('Unknown action.');
        }

    } catch (Throwable $e) {
        $_SESSION['msg_flash']      = $e->getMessage();
        $_SESSION['msg_flash_type'] = 'danger';
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
      transition:background .18s;
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
          <button class="msg-tab-btn active" id="tab-bulk-btn"
                  role="tab" aria-selected="true" aria-controls="panel-bulk"
                  onclick="switchTab('bulk')">
            <span class="tab-icon">&#128226;</span>
            Broadcast to All Members
            <span class="tab-sub">One message &rarr; every active member</span>
          </button>
          <button class="msg-tab-btn" id="tab-single-btn"
                  role="tab" aria-selected="false" aria-controls="panel-single"
                  onclick="switchTab('single')">
            <span class="tab-icon">&#127919;</span>
            Message a Specific Member
            <span class="tab-sub">Target one member by name</span>
          </button>
        </div>

        <!-- ═══════════════ PANEL 1 — BULK ═══════════════ -->
        <div class="msg-panel active" id="panel-bulk" role="tabpanel" aria-labelledby="tab-bulk-btn">

          <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-4" role="note">
            <span>&#8505;&#65039;</span>
            <div>
              Sends the <strong>same message</strong> to all
              <strong><?= he($totalActive) ?> active member(s)</strong> using the
              <strong>OramMobile Bulk SMS API</strong>
              (<code>POST /api/v1/messages/bulk</code>). Long messages are split automatically.
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
                  <span class="text-danger small ms-3">No active members with phone numbers.</span>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div><!-- /panel-bulk -->

        <!-- ═══════════════ PANEL 2 — SINGLE ═══════════════ -->
        <div class="msg-panel" id="panel-single" role="tabpanel" aria-labelledby="tab-single-btn">
          <form method="post" id="form-single">
            <input type="hidden" name="action" value="single">

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-bold" for="member_id">Select Member</label>
                <select
                  class="form-select"
                  id="member_id"
                  name="member_id"
                  onchange="updateSingleRecipient(this)"
                  required
                >
                  <option value="">&#8212; Choose a member &#8212;</option>
                  <?php foreach ($members as $m): ?>
                    <option
                      value="<?= he($m['MemberID']) ?>"
                      data-phone="<?= he($m['PrimaryNumber']) ?>"
                      data-name="<?= he(trim($m['FirstName'] . ' ' . $m['LastName'])) ?>"
                    >
                      <?= he(trim($m['FirstName'] . ' ' . $m['LastName'])) ?>
                      (<?= he($m['NationalID']) ?> &middot; <?= he($m['PrimaryNumber']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6 d-flex align-items-end">
                <div id="single-phone-badge" style="display:none">
                  <label class="form-label fw-bold d-block">Sending to</label>
                  <span class="recipient-badge">
                    <span class="dot"></span>
                    <span id="single-phone-display">&#8212;</span>
                  </span>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold" for="single-msg">Message</label>
                <textarea
                  class="form-control"
                  id="single-msg"
                  name="message_body"
                  rows="5"
                  maxlength="1600"
                  placeholder="Type your personalised message here&#8230;"
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
                <button class="btn-send" type="submit">&#127919; Send Message</button>
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
        document.getElementById('panel-' + t).classList.toggle('active', t === tab);
        var btn = document.getElementById('tab-' + t + '-btn');
        btn.classList.toggle('active', t === tab);
        btn.setAttribute('aria-selected', t === tab ? 'true' : 'false');
      });
    }

    function updateCounter(textarea, counterId, previewId) {
      var len   = textarea.value.length;
      var parts = len === 0 ? 1 : Math.ceil(len / 160);
      var el    = document.getElementById(counterId);
      el.textContent = len + ' / ' + (parts * 160) + ' (' + parts + ' SMS part' + (parts > 1 ? 's' : '') + ')';
      el.classList.toggle('warn', len > 160);
      var preview = document.getElementById(previewId);
      if (textarea.value.trim() === '') {
        preview.textContent = 'Your message will appear here\u2026';
        preview.classList.add('empty');
      } else {
        preview.textContent = textarea.value;
        preview.classList.remove('empty');
      }
    }

    function updateSingleRecipient(select) {
      var opt   = select.options[select.selectedIndex];
      var phone = opt ? (opt.getAttribute('data-phone') || '') : '';
      var name  = opt ? (opt.getAttribute('data-name')  || '') : '';
      var badge = document.getElementById('single-phone-badge');
      var disp  = document.getElementById('single-phone-display');
      if (phone) {
        disp.textContent    = name + ' \u00b7 ' + phone;
        badge.style.display = '';
      } else {
        badge.style.display = 'none';
      }
    }

    function confirmBulk() {
      var total = <?= (int)$totalActive ?>;
      return confirm('You are about to send an SMS to ALL ' + total + ' active member(s).\n\nContinue?');
    }
  </script>
</body>
</html>
