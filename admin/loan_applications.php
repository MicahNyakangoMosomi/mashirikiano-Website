<?php

require_once __DIR__ . '/../classes/Auth.php';

Auth::requireAdmin();
header("Location: loans_manage?tab=pending");
exit;
