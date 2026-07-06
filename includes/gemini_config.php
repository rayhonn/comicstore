<?php
require_once __DIR__ . '/config.php';

if (!defined('CLAUDE_API_KEY')) {
    error_log('[Claude] CLAUDE_API_KEY not set in .env');
}