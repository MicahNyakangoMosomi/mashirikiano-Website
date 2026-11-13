<?php
$tab = isset($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : '';
header('Location: ../loans_manage.php' . $tab);
exit;

