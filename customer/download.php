<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);
header('Pragma: no-cache');

redirect_to(
    app_path('customer/collection.php')
);