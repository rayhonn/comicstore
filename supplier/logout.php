<?php

require_once __DIR__ . '/../includes/auth.php';

destroy_session();

redirect_to(
    app_path('supplier/login.php')
);