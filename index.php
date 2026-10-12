<?php
declare(strict_types=1);

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parseVector(string $input, string $label): array {
    $input = trim($input);
    if ($input === '') {
        throw new RuntimeException("Please enter the $label vector.");
    }
    $parts = preg_split('/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
    $values = [];
    foreach ($parts as $part) {
        if (!preg_match('/^\d+$/', $part)) {
            throw new RuntimeException("The $label vector must contain non-negative whole numbers separated by commas or spaces.");
        }
        $values[] = (int)$part;
    }
    return $values;
}

function parseMatrix(string $input, string $label): array {
    $input = trim($input);
    if ($input === '') {
        throw new RuntimeException("Please enter the $label matrix.");
    }
    $rows = preg_split('/[\r\n;]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
    $matrix = [];
    foreach ($rows as $row) {
        $values = preg_split('/[\s,]+/', trim($row), -1, PREG_SPLIT_NO_EMPTY);
        if (!$values) {
            continue;
        }
        $parsed = [];
        foreach ($values as $value) {
            if (!preg_match('/^\d+$/', $value)) {
                throw new RuntimeException("Each value in the $label matrix must be a non-negative whole number.");
            }
            $parsed[] = (int)$value;
        }
        $matrix[] = $parsed;
    }
    if (!$matrix) {
        throw new RuntimeException("Please enter at least one row in the $label matrix.");
    }
    $width = count($matrix[0]);
    if ($width === 0) {
        throw new RuntimeException("The $label matrix contains an empty row.");
    }
    foreach ($matrix as $index => $row) {
        if (count($row) !== $width) {
            throw new RuntimeException("Every row in the $label matrix must contain the same number of resource values. Check row " . ($index + 1) . ".");
        }
    }
    return $matrix;
}

function assertSameDimensions(array $a, array $b, string $aName, string $bName): void {
    if (count($a) !== count($b) || count($a[0]) !== count($b[0])) {
        throw new RuntimeException("The $aName and $bName matrices must have the same number of processes and resource columns.");
    }
}

function assertVectorWidth(array $vector, array $matrix, string $label): void {
    if (count($vector) !== count($matrix[0])) {
        throw new RuntimeException("The $label vector must have one value for each resource type (" . count($matrix[0]) . " values expected).");
    }
}

function vectorLessThanOrEqual(array $left, array $right): bool {
    foreach ($left as $i => $value) {
        if ($value > $right[$i]) {
            return false;
        }
    }
    return true;
}

function addVectors(array $left, array $right): array {
    $out = [];
    foreach ($left as $i => $value) {
        $out[] = $value + $right[$i];
    }
    return $out;
}

function rowIsZero(array $row): bool {
    foreach ($row as $value) {
        if ($value !== 0) return false;
    }
    return true;
}

function detectDeadlock(array $available, array $allocation, array $request): array {
    assertSameDimensions($allocation, $request, 'Allocation', 'Request');
    assertVectorWidth($available, $allocation, 'Available');

    $count = count($allocation);
    $work = $available;
    $finish = [];
    for ($i = 0; $i < $count; $i++) {
        // A process holding no resources cannot be part of a resource deadlock.
        $finish[$i] = rowIsZero($allocation[$i]);
    }

    $sequence = [];
    do {
        $progress = false;
        for ($i = 0; $i < $count; $i++) {
            if (!$finish[$i] && vectorLessThanOrEqual($request[$i], $work)) {
                $work = addVectors($work, $allocation[$i]);
                $finish[$i] = true;
                $sequence[] = 'P' . $i;
                $progress = true;
            }
        }
    } while ($progress);

    $deadlocked = [];
    foreach ($finish as $i => $done) {
        if (!$done) $deadlocked[] = 'P' . $i;
    }
    return ['deadlocked' => $deadlocked, 'sequence' => $sequence, 'final_work' => $work];
}

function bankersSafety(array $available, array $allocation, array $maximum): array {
    assertSameDimensions($allocation, $maximum, 'Allocation', 'Maximum');
    assertVectorWidth($available, $allocation, 'Available');

    $count = count($allocation);
    $need = [];
    for ($i = 0; $i < $count; $i++) {
        $row = [];
        foreach ($allocation[$i] as $j => $allocated) {
            if ($maximum[$i][$j] < $allocated) {
                throw new RuntimeException('Maximum demand cannot be smaller than current allocation. Check process P' . $i . ', resource R' . $j . '.');
            }
            $row[] = $maximum[$i][$j] - $allocated;
        }
        $need[] = $row;
    }

    $work = $available;
    $finish = array_fill(0, $count, false);
    $sequence = [];
    do {
        $progress = false;
        for ($i = 0; $i < $count; $i++) {
            if (!$finish[$i] && vectorLessThanOrEqual($need[$i], $work)) {
                $work = addVectors($work, $allocation[$i]);
                $finish[$i] = true;
                $sequence[] = 'P' . $i;
                $progress = true;
            }
        }
    } while ($progress);

    return ['safe' => count($sequence) === $count, 'sequence' => $sequence, 'need' => $need, 'unfinished' => array_map(fn($i) => 'P' . $i, array_keys(array_filter($finish, fn($done) => !$done)))];
}

function detectGraphCycle(string $input): array {
    $input = trim($input);
    if ($input === '') {
        throw new RuntimeException('Enter directed edges, for example: P0->R0; R0->P1; P1->R1; R1->P0.');
    }
    $parts = preg_split('/[;\r\n]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
    $adj = [];
    $nodes = [];
    $edges = [];
    foreach ($parts as $part) {
        if (!preg_match('/^\s*([A-Za-z][A-Za-z0-9_]*)\s*->\s*([A-Za-z][A-Za-z0-9_]*)\s*$/', $part, $m)) {
            throw new RuntimeException('Invalid edge: "' . trim($part) . '". Use the format Node->Node and separate edges with semicolons or new lines.');
        }
        $from = $m[1];
        $to = $m[2];
        $adj[$from] ??= [];
        $adj[$from][] = $to;
        $adj[$to] ??= [];
        $nodes[$from] = true;
        $nodes[$to] = true;
        $edges[] = [$from, $to];
    }

    $state = [];
    $stack = [];
    $cycle = [];
    $dfs = function (string $node) use (&$dfs, &$state, &$stack, &$cycle, &$adj): bool {
        $state[$node] = 1;
        $stack[] = $node;
        foreach ($adj[$node] ?? [] as $next) {
            if (($state[$next] ?? 0) === 0) {
                if ($dfs($next)) return true;
            } elseif (($state[$next] ?? 0) === 1) {
                $start = array_search($next, $stack, true);
                $cycle = array_slice($stack, $start === false ? 0 : $start);
                $cycle[] = $next;
                return true;
            }
        }
        array_pop($stack);
        $state[$node] = 2;
        return false;
    };

    foreach (array_keys($nodes) as $node) {
        if (($state[$node] ?? 0) === 0 && $dfs($node)) break;
    }
    return ['has_cycle' => count($cycle) > 0, 'cycle' => $cycle, 'edges' => $edges];
}

$module = $_GET['module'] ?? 'overview';
$allowedModules = ['overview', 'detection', 'banker', 'graph', 'prevention'];
if (!in_array($module, $allowedModules, true)) $module = 'overview';
$result = null;
$error = '';
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

$values = [
    'available' => $_POST['available'] ?? '1, 0, 0',
    'allocation' => $_POST['allocation'] ?? "0, 1, 0\n2, 0, 0\n0, 0, 1",
    'request' => $_POST['request'] ?? "0, 0, 0\n0, 0, 1\n1, 0, 0",
    'maximum' => $_POST['maximum'] ?? "1, 1, 0\n2, 1, 1\n1, 0, 1",
    'edges' => $_POST['edges'] ?? 'P0->R0; R0->P1; P1->R1; R1->P0',
];

if ($posted) {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'detection') {
            $available = parseVector($values['available'], 'Available');
            $allocation = parseMatrix($values['allocation'], 'Allocation');
            $request = parseMatrix($values['request'], 'Request');
            $result = ['type' => 'detection', 'data' => detectDeadlock($available, $allocation, $request)];
            $module = 'detection';
        } elseif ($action === 'banker') {
            $available = parseVector($values['available'], 'Available');
            $allocation = parseMatrix($values['allocation'], 'Allocation');
            $maximum = parseMatrix($values['maximum'], 'Maximum');
            $result = ['type' => 'banker', 'data' => bankersSafety($available, $allocation, $maximum)];
            $module = 'banker';
        } elseif ($action === 'graph') {
            $result = ['type' => 'graph', 'data' => detectGraphCycle($values['edges'])];
            $module = 'graph';
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'Something went wrong while analyzing the input. Please check the values and matrix dimensions.';
    }
}

function renderMatrix(array $matrix): string {
    $out = '<div class="matrix">';
    foreach ($matrix as $i => $row) {
        $out .= '<div class="matrix-row"><span class="process">P' . $i . '</span>';
        foreach ($row as $value) $out .= '<span class="matrix-cell">' . (int)$value . '</span>';
        $out .= '</div>';
    }
    return $out . '</div>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deadlock Handling Simulator</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <div class="brand-mark">OS</div>
    <div>
        <p class="eyebrow">OPERATING SYSTEMS PROJECT</p>
        <h1>Deadlock Handling Simulator</h1>
        <p class="header-subtitle">Explore detection, avoidance, resource-allocation graphs, and prevention.</p>
    </div>
</header>
<nav class="nav" aria-label="Main navigation">
    <a class="<?= $module === 'overview' ? 'active' : '' ?>" href="?module=overview">Overview</a>
    <a class="<?= $module === 'detection' ? 'active' : '' ?>" href="?module=detection">Detection</a>
    <a class="<?= $module === 'banker' ? 'active' : '' ?>" href="?module=banker">Banker's Algorithm</a>
    <a class="<?= $module === 'graph' ? 'active' : '' ?>" href="?module=graph">Resource Graph</a>
    <a class="<?= $module === 'prevention' ? 'active' : '' ?>" href="?module=prevention">Prevention</a>
</nav>
<main class="container">
<?php if ($error !== ''): ?>
    <section class="notice error" role="alert"><strong>Input needs attention</strong><p><?= h($error) ?></p></section>
<?php endif; ?>

<?php if ($module === 'overview'): ?>
    <section class="hero card">
        <div class="hero-copy">
            <span class="pill">PHP • Interactive simulator</span>
            <h2>Understand what happens when processes compete for resources.</h2>
            <p>Enter process and resource data to test a deadlock-detection algorithm, check system safety with the Banker's Algorithm, or look for cycles in a resource-allocation graph.</p>
            <a class="button-link" href="?module=detection">Start with detection <span aria-hidden="true">→</span></a>
        </div>
        <div class="hero-graphic" aria-hidden="true">
            <div class="node process-node">P0</div><div class="arrow arrow-one">→</div><div class="node resource-node">R0</div>
            <div class="arrow arrow-two">↓</div><div class="node process-node node-bottom">P1</div><div class="arrow arrow-three">←</div>
            <div class="loop-label">resource wait cycle</div>
        </div>
    </section>
    <section class="section-heading"><div><p class="eyebrow">PROJECT MODULES</p><h2>What you can demonstrate</h2></div></section>
    <div class="feature-grid">
        <a class="feature-card" href="?module=detection"><span class="feature-number">01</span><h3>Deadlock Detection</h3><p>Use Available, Allocation, and Request values to identify processes that cannot finish.</p><span class="card-link">Open simulator →</span></a>
        <a class="feature-card" href="?module=banker"><span class="feature-number">02</span><h3>Banker's Algorithm</h3><p>Calculate Need and search for a safe execution sequence before approving a state.</p><span class="card-link">Check a state →</span></a>
        <a class="feature-card" href="?module=graph"><span class="feature-number">03</span><h3>Resource Allocation Graph</h3><p>Enter process/resource edges and check whether the directed graph contains a cycle.</p><span class="card-link">Check a graph →</span></a>
        <a class="feature-card" href="?module=prevention"><span class="feature-number">04</span><h3>Prevention Concepts</h3><p>Review the four conditions associated with deadlock and common prevention strategies.</p><span class="card-link">Review concepts →</span></a>
    </div>
    <p class="footnote">Educational note: this project is a demonstration, not an operating-system resource manager.</p>

<?php elseif ($module === 'detection'): ?>
    <section class="page-intro"><p class="eyebrow">MODULE 01</p><h2>Deadlock Detection</h2><p>Enter the resources currently available, the allocation matrix, and each process's outstanding request. Use one matrix row per process and one column per resource type.</p></section>
    <section class="card form-card">
        <form method="post" action="?module=detection">
            <input type="hidden" name="action" value="detection">
            <div class="form-field"><label for="available-d">Available vector</label><input id="available-d" name="available" value="<?= h($values['available']) ?>" required><small>Example: <code>1, 0, 0</code></small></div>
            <div class="two-columns">
                <div class="form-field"><label for="allocation-d">Allocation matrix</label><textarea id="allocation-d" name="allocation" rows="5" required><?= h($values['allocation']) ?></textarea><small>One process per line; comma-separated values.</small></div>
                <div class="form-field"><label for="request-d">Request matrix</label><textarea id="request-d" name="request" rows="5" required><?= h($values['request']) ?></textarea><small>Outstanding requests, in the same process/resource order.</small></div>
            </div>
            <div class="actions"><button type="submit">Detect deadlock</button><a class="text-link" href="?module=detection">Reset example</a></div>
        </form>
    </section>
    <?php if (($result['type'] ?? '') === 'detection'): $d = $result['data']; ?>
        <section class="card result-card">
            <?php if ($d['deadlocked']): ?>
                <div class="result-heading danger"><span class="result-icon">!</span><div><p class="eyebrow">ANALYSIS RESULT</p><h3>Potential deadlock detected</h3></div></div>
                <p>These processes remained unfinished after the detection algorithm could no longer find an eligible process:</p>
                <div class="chips"><?php foreach ($d['deadlocked'] as $p): ?><span class="chip danger-chip"><?= h($p) ?></span><?php endforeach; ?></div>
            <?php else: ?>
                <div class="result-heading success"><span class="result-icon">✓</span><div><p class="eyebrow">ANALYSIS RESULT</p><h3>No deadlocked processes found</h3></div></div>
                <p>The algorithm was able to mark every process as finished in this simulation.</p>
            <?php endif; ?>
            <div class="result-details"><div><span class="detail-label">Processes that could finish</span><strong><?= $d['sequence'] ? h(implode(' → ', $d['sequence'])) : 'None' ?></strong></div><div><span class="detail-label">Work vector at stop</span><strong><?= h(implode(', ', $d['final_work'])) ?></strong></div></div>
            <p class="footnote">This module uses a multiple-instance resource deadlock-detection procedure. A process remaining unfinished is reported as part of the deadlocked set under the supplied state.</p>
        </section>
    <?php endif; ?>
    <aside class="hint-box"><strong>Input tips</strong><p>All matrices must have the same number of process rows and resource columns. Available must have one number for each resource column. Process names are assigned automatically as P0, P1, P2, and so on.</p></aside>

<?php elseif ($module === 'banker'): ?>
    <section class="page-intro"><p class="eyebrow">MODULE 02</p><h2>Banker's Algorithm</h2><p>Enter the Available vector, Allocation matrix, and Maximum-demand matrix. The simulator computes <strong>Need = Maximum − Allocation</strong> and checks whether a safe sequence exists.</p></section>
    <section class="card form-card">
        <form method="post" action="?module=banker">
            <input type="hidden" name="action" value="banker">
            <div class="form-field"><label for="available-b">Available vector</label><input id="available-b" name="available" value="<?= h($values['available']) ?>" required><small>Resources currently available.</small></div>
            <div class="two-columns">
                <div class="form-field"><label for="allocation-b">Allocation matrix</label><textarea id="allocation-b" name="allocation" rows="5" required><?= h($values['allocation']) ?></textarea><small>Resources each process currently holds.</small></div>
                <div class="form-field"><label for="maximum-b">Maximum matrix</label><textarea id="maximum-b" name="maximum" rows="5" required><?= h($values['maximum']) ?></textarea><small>Maximum resources each process may need.</small></div>
            </div>
            <div class="actions"><button type="submit">Check safe state</button><a class="text-link" href="?module=banker">Reset example</a></div>
        </form>
    </section>
    <?php if (($result['type'] ?? '') === 'banker'): $b = $result['data']; ?>
        <section class="card result-card">
            <div class="result-heading <?= $b['safe'] ? 'success' : 'danger' ?>"><span class="result-icon"><?= $b['safe'] ? '✓' : '!' ?></span><div><p class="eyebrow">SAFETY CHECK</p><h3><?= $b['safe'] ? 'System is in a safe state' : 'No safe sequence found' ?></h3></div></div>
            <?php if ($b['safe']): ?><p>At least one order allows every process to finish using the available resources and resources released by completed processes.</p><div class="sequence-box"><span class="detail-label">Safe sequence</span><strong><?= h(implode(' → ', $b['sequence'])) ?></strong></div><?php else: ?><p>The system cannot find a sequence that finishes every process with the supplied Available, Allocation, and Maximum values. This is an <strong>unsafe state</strong>; it does not by itself prove a current deadlock.</p><p class="detail-label">Processes that could not be added to a safe sequence</p><div class="chips"><?php foreach ($b['unfinished'] as $p): ?><span class="chip danger-chip"><?= h($p) ?></span><?php endforeach; ?></div><?php endif; ?>
            <h4>Calculated Need matrix</h4><?= renderMatrix($b['need']) ?>
        </section>
    <?php endif; ?>
    <aside class="hint-box"><strong>Remember</strong><p>Banker's Algorithm is primarily a deadlock-avoidance method. It checks whether a state is safe, not whether an existing deadlock is already present.</p></aside>

<?php elseif ($module === 'graph'): ?>
    <section class="page-intro"><p class="eyebrow">MODULE 03</p><h2>Resource Allocation Graph</h2><p>Represent each directed relationship as <code>Node-&gt;Node</code>. For example, <code>P0-&gt;R0</code> means process P0 requests resource R0, while <code>R0-&gt;P1</code> means resource R0 is allocated to process P1.</p></section>
    <section class="card form-card">
        <form method="post" action="?module=graph">
            <input type="hidden" name="action" value="graph">
            <div class="form-field"><label for="edges">Directed edges</label><textarea id="edges" name="edges" rows="4" required><?= h($values['edges']) ?></textarea><small>Separate edges with semicolons or new lines. Node labels can be P0, P1, R0, R1, etc.</small></div>
            <div class="actions"><button type="submit">Check for cycle</button><a class="text-link" href="?module=graph">Reset example</a></div>
        </form>
    </section>
    <?php if (($result['type'] ?? '') === 'graph'): $g = $result['data']; ?>
        <section class="card result-card">
            <div class="result-heading <?= $g['has_cycle'] ? 'danger' : 'success' ?>"><span class="result-icon"><?= $g['has_cycle'] ? '!' : '✓' ?></span><div><p class="eyebrow">GRAPH ANALYSIS</p><h3><?= $g['has_cycle'] ? 'Directed cycle found' : 'No directed cycle found' ?></h3></div></div>
            <?php if ($g['has_cycle']): ?><p>Cycle path found:</p><div class="sequence-box"><strong><?= h(implode(' → ', $g['cycle'])) ?></strong></div><p class="footnote">In a resource-allocation graph with one instance of each resource type, a cycle indicates deadlock. With multiple instances per resource type, a cycle alone is not sufficient to prove deadlock.</p><?php else: ?><p>The supplied directed graph does not contain a cycle.</p><?php endif; ?>
            <h4>Edges entered</h4><div class="edge-list"><?php foreach ($g['edges'] as [$from, $to]): ?><span><?= h($from) ?> <b>→</b> <?= h($to) ?></span><?php endforeach; ?></div>
        </section>
    <?php endif; ?>
    <aside class="hint-box"><strong>Graph convention</strong><p>Process-to-resource edges represent requests; resource-to-process edges represent allocations. The cycle interpretation depends on how many instances each resource type has.</p></aside>

<?php elseif ($module === 'prevention'): ?>
    <section class="page-intro"><p class="eyebrow">MODULE 04</p><h2>Deadlock Prevention</h2><p>Prevention designs a system so that at least one of the four necessary conditions for deadlock cannot hold.</p></section>
    <section class="condition-grid">
        <article class="condition-card"><span class="condition-number">01</span><h3>Mutual exclusion</h3><p>A resource can be used by only one process at a time.</p><p class="strategy"><strong>Possible approach:</strong> Make resources sharable where that is valid and safe.</p></article>
        <article class="condition-card"><span class="condition-number">02</span><h3>Hold and wait</h3><p>A process holds one resource while waiting for another.</p><p class="strategy"><strong>Possible approach:</strong> Require processes to request needed resources together before execution, where practical.</p></article>
        <article class="condition-card"><span class="condition-number">03</span><h3>No preemption</h3><p>A resource cannot be forcibly taken from a process holding it.</p><p class="strategy"><strong>Possible approach:</strong> Allow a process to release held resources when its next request cannot be granted, when the resource type supports it.</p></article>
        <article class="condition-card"><span class="condition-number">04</span><h3>Circular wait</h3><p>A circular chain of processes waits for resources held by the next process.</p><p class="strategy"><strong>Possible approach:</strong> Impose a consistent ordering of resource types and require requests to follow that order.</p></article>
    </section>
    <section class="card theory-card"><h3>Other deadlock-handling approaches</h3><div class="theory-row"><div><h4>Avoidance</h4><p>Evaluate requests dynamically and grant them only when the resulting state remains safe. The Banker's Algorithm is demonstrated in this project.</p></div><span class="theory-tag">Banker's Algorithm</span></div><div class="theory-row"><div><h4>Detection and recovery</h4><p>Allow deadlocks to occur, detect affected processes, then recover using a chosen policy such as terminating a process or reclaiming resources when possible.</p></div><span class="theory-tag">Detection module</span></div><div class="theory-row"><div><h4>Ostrich approach</h4><p>Ignore deadlocks when handling them is judged more costly than the expected impact. Your class material mentions this as one strategy.</p></div><span class="theory-tag">Concept</span></div></section>
<?php endif; ?>
</main>
<footer class="site-footer"><p>Deadlock Handling Simulator · Operating Systems · PHP</p><p>Use small, carefully checked examples for classroom demonstrations.</p></footer>
</body>
</html>
