<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$role = current_role();

$loginDestination = match ($role) {
    'admin',
    'staff' =>
        app_path('admin/login.php?logged_out=1'),

    'supplier' =>
        app_path('supplier/login.php?logged_out=1'),

    default =>
        app_path('login.php?logged_out=1'),
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $activeDestination = match ($role) {
        'admin' =>
            app_path('admin/dashboard.php'),

        'staff' =>
            app_path('staff/dashboard.php'),

        'supplier' =>
            app_path('supplier/dashboard.php'),

        'customer' =>
            app_path('index.php'),

        default =>
            $loginDestination,
    };

    redirect_to($activeDestination);
}

csrf_verify();
destroy_session();

redirect_to($loginDestination);
