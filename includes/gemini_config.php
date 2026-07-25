<?php

require_once __DIR__ . '/config.php';

if (!defined('CLAUDE_API_KEY')) {
    app_log(
        'WARNING',
        'Claude',
        'CLAUDE_API_KEY is not set in .env.'
    );
}