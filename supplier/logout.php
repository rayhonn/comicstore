<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(
        app_session_is_authenticated() &&
        current_role() === 'supplier'
            ? app_path('supplier/dashboard.php')
            : app_path('supplier/login.php')
    );
}

csrf_verify();
destroy_session();

redirect_to(
    app_path(
        'supplier/login.php?logged_out=1'
    )
);
