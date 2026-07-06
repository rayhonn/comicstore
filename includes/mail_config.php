<?php
require_once __DIR__ . '/config.php';

if (!defined('MAIL_PASSWORD')) {
    error_log('[Mail] MAIL_PASSWORD not set in .env');
}