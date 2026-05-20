<?php
// scheduler_control.php
// Simple start/stop control for pull_and_enqueue scheduler using a lock file.

header('Content-Type: application/json');

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$lockFile = __DIR__ . '/.scheduler_state.json';

function writeState($locked)
{
    global $lockFile;
    // preserve existing query if present
    $existing = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : [];
    $data = [
        'running' => $locked,
        'updated_at' => date('c'),
        'query' => isset($existing['query']) ? $existing['query'] : ''
    ];
    file_put_contents($lockFile, json_encode($data));
}

if ($action === 'start') {
    // optional SQL/query parameter to save in scheduler state
    $query = isset($_REQUEST['query']) ? $_REQUEST['query'] : '';
    // write state with query
    $existing = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : [];
    $existing['query'] = $query;
    $existing['running'] = true;
    $existing['updated_at'] = date('c');
    file_put_contents($lockFile, json_encode($existing));

    // Try to trigger pull_and_enqueue.php immediately. Use background execution
    // so the request returns quickly. Handle Windows and Unix-like OSes.
    $runner = PHP_BINARY;
    $script = escapeshellarg(__DIR__ . '/pull_and_enqueue.php');
    $arg = escapeshellarg($query);
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        // Windows: use start /B
        pclose(popen("start /B " . $runner . " " . $script . " " . $arg, "r"));
    } else {
        // Unix: run in background
        shell_exec($runner . ' ' . $script . ' ' . $arg . ' > /dev/null 2>&1 &');
    }

    echo json_encode(['success' => true, 'message' => 'Scheduler started']);
    exit;
} elseif ($action === 'stop') {
    // clear running flag but keep query if you want, or clear query as well.
    $existing = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : [];
    $existing['running'] = false;
    $existing['updated_at'] = date('c');
    // optionally clear query when stopping; uncomment next line to clear
    // $existing['query'] = '';
    file_put_contents($lockFile, json_encode($existing));
    echo json_encode(['success' => true, 'message' => 'Scheduler stopped']);
    exit;
} elseif ($action === 'status') {
    if (!file_exists($lockFile)) {
        echo json_encode(['success' => true, 'running' => false]);
        exit;
    }
    $d = json_decode(file_get_contents($lockFile), true);
    echo json_encode(['success' => true, 'running' => !empty($d['running'])]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
