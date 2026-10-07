<?php
/**
 * health.php
 * 
 * A lightweight endpoint for uptime monitoring and cron jobs (e.g. cron-job.org)
 * to prevent the free tier Render server from spinning down.
 */

http_response_code(200);
header('Content-Type: application/json');

echo json_encode([
    'status' => 'ok',
    'timestamp' => time()
]);
exit;
