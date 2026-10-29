<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

$cid = $_GET['cid'] ?? '';
if (!preg_match('/^\d{3,10}$/', $cid)) {
    json_response(['error' => 'Invalid CID.'], 400);
}

try { $quarter = requested_quarter(); } catch (InvalidArgumentException $error) { json_response(['error' => $error->getMessage()], 400); }
try { $records = vatsim_history($cid, $quarter['start']); } catch (Throwable $error) { json_response(['error' => 'VATSIM API request failed: ' . $error->getMessage()], 502); }
$groups = [];
$recordCount = 0;

foreach ($records as $record) {
    $minutes = session_minutes($record, $quarter);
    if ($minutes <= 0) continue;
    $recordCount++;
    [$division, $area] = classify_session($record);
    $seat = session_callsign($record);
    $groups[$division] ??= [];
    $groups[$division][$area] ??= ['minutes' => 0.0, 'seats' => []];
    $groups[$division][$area]['minutes'] += $minutes;
    $groups[$division][$area]['seats'][$seat] = ($groups[$division][$area]['seats'][$seat] ?? 0) + $minutes;
}

$divisions = [];
foreach ($groups as $name => $areas) {
    $areaRows = [];
    $divisionMinutes = 0.0;
    foreach ($areas as $areaName => $area) {
        $seats = [];
        foreach ($area['seats'] as $seat => $minutes) $seats[] = ['name' => $seat, 'minutes' => $minutes];
        usort($seats, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
        $areaRows[] = ['name' => $areaName, 'minutes' => $area['minutes'], 'seats' => $seats];
        $divisionMinutes += $area['minutes'];
    }
    usort($areaRows, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
    $divisions[] = ['name' => $name, 'minutes' => $divisionMinutes, 'areas' => $areaRows];
}
usort($divisions, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
$total = array_sum(array_column($divisions, 'minutes'));
json_response(['totalMinutes' => $total, 'recordCount' => $recordCount, 'areaCount' => array_sum(array_map(fn($d) => count($d['areas']), $divisions)), 'maxMinutes' => $divisions[0]['minutes'] ?? 1, 'divisions' => $divisions, 'quarter' => ['year' => $quarter['year'], 'number' => $quarter['number'], 'start' => gmdate('c', $quarter['start']), 'end' => gmdate('c', $quarter['end'])]]);
