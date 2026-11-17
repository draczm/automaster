<?php
require_once 'config/db.php';
require_once 'includes/schedule_helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $serviceId = (int)($_GET['service_id'] ?? 0);
    $date = trim($_GET['date'] ?? '');
    $time = trim($_GET['time'] ?? '');

    if ($serviceId <= 0) {
        throw new Exception('Услуга не выбрана.');
    }

    if (!cr_valid_date($date)) {
        throw new Exception('Дата передана в неверном формате.');
    }

    if (!cr_valid_time($time)) {
        throw new Exception('Время передано в неверном формате.');
    }

    $masters = cr_get_available_masters($pdo, $serviceId, $date, $time);

    echo json_encode([
        'success' => true,
        'service_id' => $serviceId,
        'date' => $date,
        'time' => cr_time_short($time),
        'masters_count' => count($masters),
        'masters' => $masters
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'masters_count' => 0,
        'masters' => []
    ], JSON_UNESCAPED_UNICODE);
}