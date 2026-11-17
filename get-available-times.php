<?php
require_once 'config/db.php';
require_once 'includes/schedule_helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $serviceId = (int)($_GET['service_id'] ?? 0);
    $date = trim($_GET['date'] ?? '');

    if ($serviceId <= 0) {
        throw new Exception('Услуга не выбрана.');
    }

    if (!cr_valid_date($date)) {
        throw new Exception('Дата передана в неверном формате.');
    }

    $times = cr_get_available_times($pdo, $serviceId, $date);

    echo json_encode([
        'success' => true,
        'service_id' => $serviceId,
        'date' => $date,
        'times_count' => count($times),
        'times' => $times
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'times_count' => 0,
        'times' => []
    ], JSON_UNESCAPED_UNICODE);
}