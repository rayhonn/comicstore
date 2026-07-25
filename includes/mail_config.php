<?php

require_once __DIR__ . '/config.php';

if (!defined('MAIL_PASSWORD')) {
    app_log(
        'ERROR',
        'Mail',
        'MAIL_PASSWORD is not set in .env.'
    );
}