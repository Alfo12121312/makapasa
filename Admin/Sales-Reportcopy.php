<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '../Sales-Report.php' . ($query !== '' ? ('?' . $query) : '');
header('Location: ' . $target);
exit();
