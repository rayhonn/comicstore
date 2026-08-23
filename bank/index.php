<?php

require_once __DIR__ . '/../includes/auth.php';

redirect_to(
    current_role() === 'bank'
        ? app_path('bank/dashboard.php')
        : app_path('bank/login.php')
);
