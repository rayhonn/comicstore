<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(
        current_role() === 'bank'
            ? app_path('bank/dashboard.php')
            : app_path('bank/login.php')
    );
}

csrf_verify();
destroy_session();

redirect_to(
    app_path('bank/login.php?logged_out=1')
);
