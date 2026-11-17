<?php

function cr_valid_date($date)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parts = explode('-', $date);

    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

function cr_valid_time($time)
{
    return preg_match('/^\d{2}:\d{2}$/', $time)
        || preg_match('/^\d{2}:\d{2}:\d{2}$/', $time);
}

function cr_time_short($time)
{
    return substr($time, 0, 5);
}

function cr_time_full($time)
{
    $time = trim($time);

    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time . ':00';
    }

    return $time;
}

function cr_get_service($pdo, $serviceId)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM services
        WHERE id = ?
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([(int)$serviceId]);

    return $stmt->fetch();
}

function cr_service_duration_minutes($service)
{
    if (isset($service['duration_minutes']) && (int)$service['duration_minutes'] > 0) {
        return (int)$service['duration_minutes'];
    }

    if (isset($service['duration_hours']) && (int)$service['duration_hours'] > 0) {
        return (int)$service['duration_hours'] * 60;
    }

    return 60;
}

function cr_get_candidate_masters($pdo, $serviceId, $date)
{
    $weekday = (int)date('N', strtotime($date));

    $stmt = $pdo->prepare("
        SELECT
            employees.id,
            employees.full_name,
            employees.position,
            employees.specialization,
            employees.commission_percent,
            employee_schedule.start_time,
            employee_schedule.end_time,
            employee_schedule.break_start,
            employee_schedule.break_end
        FROM employees
        INNER JOIN employee_services
            ON employee_services.employee_id = employees.id
        INNER JOIN employee_schedule
            ON employee_schedule.employee_id = employees.id
        WHERE employee_services.service_id = ?
          AND employee_services.is_active = 1
          AND employees.is_master = 1
          AND employees.work_status = 'Работает'
          AND employee_schedule.weekday_num = ?
          AND employee_schedule.is_working = 1
        ORDER BY employees.full_name
    ");

    $stmt->execute([(int)$serviceId, $weekday]);

    return $stmt->fetchAll();
}

function cr_interval_overlaps($startA, $endA, $startB, $endB)
{
    return $startA < $endB && $endA > $startB;
}

function cr_master_has_order_conflict($pdo, $employeeId, $startDatetime, $endDatetime)
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM appointments
        WHERE employee_id = ?
          AND status IN ('Новая', 'Подтверждена', 'В работе', 'Перенесена')
          AND start_datetime IS NOT NULL
          AND end_datetime IS NOT NULL
          AND ? < end_datetime
          AND ? > start_datetime
        LIMIT 1
    ");

    $stmt->execute([
        (int)$employeeId,
        $startDatetime,
        $endDatetime
    ]);

    return (bool)$stmt->fetch();
}

function cr_master_has_time_off_conflict($pdo, $employeeId, $date, $startTime, $endTime)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM employee_time_off
        WHERE employee_id = ?
          AND status = 'Активно'
          AND ? BETWEEN date_from AND date_to
    ");

    $stmt->execute([(int)$employeeId, $date]);
    $timeOffRows = $stmt->fetchAll();

    foreach ($timeOffRows as $row) {
        if ($row['time_from'] === null || $row['time_to'] === null) {
            return true;
        }

        if (cr_interval_overlaps(
            cr_time_full($startTime),
            cr_time_full($endTime),
            cr_time_full($row['time_from']),
            cr_time_full($row['time_to'])
        )) {
            return true;
        }
    }

    return false;
}

function cr_master_available_for_interval($pdo, $master, $date, $time, $durationMinutes)
{
    $start = new DateTimeImmutable($date . ' ' . cr_time_full($time));
    $end = $start->modify('+' . (int)$durationMinutes . ' minutes');

    $workStart = new DateTimeImmutable($date . ' ' . cr_time_full($master['start_time']));
    $workEnd = new DateTimeImmutable($date . ' ' . cr_time_full($master['end_time']));

    if ($start < $workStart || $end > $workEnd) {
        return false;
    }

    if ($master['break_start'] !== null && $master['break_end'] !== null) {
        $breakStart = new DateTimeImmutable($date . ' ' . cr_time_full($master['break_start']));
        $breakEnd = new DateTimeImmutable($date . ' ' . cr_time_full($master['break_end']));

        if ($start < $breakEnd && $end > $breakStart) {
            return false;
        }
    }

    $startDatetime = $start->format('Y-m-d H:i:s');
    $endDatetime = $end->format('Y-m-d H:i:s');

    if (cr_master_has_order_conflict($pdo, $master['id'], $startDatetime, $endDatetime)) {
        return false;
    }

    if (cr_master_has_time_off_conflict(
        $pdo,
        $master['id'],
        $date,
        $start->format('H:i:s'),
        $end->format('H:i:s')
    )) {
        return false;
    }

    return true;
}

function cr_get_available_masters($pdo, $serviceId, $date, $time)
{
    if (!cr_valid_date($date) || !cr_valid_time($time)) {
        return [];
    }

    $service = cr_get_service($pdo, $serviceId);

    if (!$service) {
        return [];
    }

    $durationMinutes = cr_service_duration_minutes($service);
    $masters = cr_get_candidate_masters($pdo, $serviceId, $date);
    $available = [];

    foreach ($masters as $master) {
        if (cr_master_available_for_interval($pdo, $master, $date, $time, $durationMinutes)) {
            $available[] = [
                'id' => (int)$master['id'],
                'full_name' => $master['full_name'],
                'position' => $master['position'],
                'specialization' => $master['specialization'],
                'commission_percent' => (float)$master['commission_percent']
            ];
        }
    }

    return $available;
}

function cr_get_available_times($pdo, $serviceId, $date)
{
    if (!cr_valid_date($date)) {
        return [];
    }

    if ($date < date('Y-m-d')) {
        return [];
    }

    $service = cr_get_service($pdo, $serviceId);

    if (!$service) {
        return [];
    }

    $durationMinutes = cr_service_duration_minutes($service);
    $masters = cr_get_candidate_masters($pdo, $serviceId, $date);

    if (!$masters) {
        return [];
    }

    $times = [];
    $now = new DateTimeImmutable();

    foreach ($masters as $master) {
        $slot = new DateTimeImmutable($date . ' ' . cr_time_full($master['start_time']));
        $workEnd = new DateTimeImmutable($date . ' ' . cr_time_full($master['end_time']));

        while ($slot->modify('+' . $durationMinutes . ' minutes') <= $workEnd) {
            if ($date === date('Y-m-d') && $slot <= $now) {
                $slot = $slot->modify('+30 minutes');
                continue;
            }

            $time = $slot->format('H:i');

            if (cr_master_available_for_interval($pdo, $master, $date, $time, $durationMinutes)) {
                if (!isset($times[$time])) {
                    $times[$time] = 0;
                }

                $times[$time]++;
            }

            $slot = $slot->modify('+30 minutes');
        }
    }

    ksort($times);

    $result = [];

    foreach ($times as $time => $mastersCount) {
        if ($mastersCount > 0) {
            $result[] = [
                'time' => $time,
                'label' => $time,
                'masters_count' => $mastersCount
            ];
        }
    }

    return $result;
}