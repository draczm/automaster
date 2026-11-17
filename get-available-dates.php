<?php
require_once 'config/db.php';
require_once 'includes/schedule_helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $serviceId = (int)($_GET['service_id'] ?? 0);
    $days = (int)($_GET['days'] ?? 45);

    if ($serviceId <= 0) {
        throw new Exception('Сначала выберите услугу.');
    }

    if ($days < 7) {
        $days = 7;
    }

    if ($days > 90) {
        $days = 90;
    }

    $availableDates = [];

    for ($i = 0; $i <= $days; $i++) {
        $date = date('Y-m-d', strtotime('+' . $i . ' days'));

        $times = cr_get_available_times($pdo, $serviceId, $date);

        if (!empty($times)) {
            $availableDates[] = [
                'date' => $date,
                'times_count' => count($times)
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'dates' => $availableDates
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'dates' => []
    ], JSON_UNESCAPED_UNICODE);
}