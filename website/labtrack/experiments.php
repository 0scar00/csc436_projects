<?php
// experiments.php — LabTrack Experiments Browser
// Lists every row from the Experiment table, joined to Lab + User for display.
// Dark-navy theme to match dashboard.html.

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/database-connection.php';

// ── Authentication guard ──────────────────────────────────────────────────────
if (!$logged_in) {
    header('Location: login.php');
    exit;
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$search       = trim($_GET['search']        ?? '');
$status_filter = trim($_GET['status']        ?? '');

// ── Query ─────────────────────────────────────────────────────────────────────
$sql = "
    SELECT
        e.experiment_id,
        e.title,
        e.description,
        e.status,
        e.created_at,
        l.lab_name,
        l.room_num,
        u.first_name,
        u.last_name,
        u.role
    FROM   Experiment e
    LEFT JOIN Lab  l ON l.lab_id   = e.lab_id
    LEFT JOIN User u ON u.user_id = e.created_by_user_id
    WHERE 1=1
";

$params = [];

if ($search !== '') {
    $sql      .= " AND e.title LIKE ?";
    $params[]  = '%' . $search . '%';
}

if ($status_filter !== '') {
    $sql      .= " AND e.status = ?";
    $params[]  = $status_filter;
}

$sql .= " ORDER BY e.created_at DESC";

// ── Execute ───────────────────────────────────────────────────────────────────
$experiments = [];
$error = '';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $experiments = $stmt->fetchAll();

    // Distinct statuses for the filter dropdown
    $statusStmt = $pdo->query("SELECT DISTINCT status FROM Experiment ORDER BY status ASC");
    $statuses   = $statusStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('LabTrack experiments error: ' . $e->getMessage());
    $error    = 'Could not load experiments. Please try again.';
    $statuses = [];
}

// ── Counts ────────────────────────────────────────────────────────────────────
$total      = count($experiments);
$activeCnt  = 0;
$plannedCnt = 0;
$doneCnt    = 0;
foreach ($experiments as $row) {
    $st = strtolower($row['status'] ?? '');
    if ($st === 'active')      $activeCnt++;
    elseif ($st === 'planned') $plannedCnt++;
    elseif ($st === 'completed' || $st === 'complete' || $st === 'done') $doneCnt++;
}

$username = htmlspecialchars($_SESSION['username'] ?? 'User');

// ── Helper: status badge class ────────────────────────────────────────────────
function statusBadge(string $status): string {
    $s = strtolower($status);
    if ($s === 'active')                              return 'badge-green';
    if ($s === 'planned' || $s === 'pending')         return 'badge-amber';
    if ($s === 'completed' || $s === 'complete' || $s === 'done') return 'badge-cyan';
    if ($s === 'cancelled' || $s === 'canceled')      return 'badge-red';
    return 'badge-gray';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>LabTrack — Experiments</title>
    <link rel="stylesheet" href="login.css" />
    <link rel="stylesheet" href="dashboard.css" />
    <style>
        /* ── Page-specific overrides (dark theme) ─────────────────────── */
        .page-title {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--clr-text);
            margin-bottom: 4px;
        }
        .page-subtitle {
            font-size: 0.9rem;
            color: var(--clr-muted);
            margin-bottom: 4px;
        }

        /* ── Filter bar ───────────────────────────────────────────────── */
        .exp-filter-bar {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius);
            padding: 16px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .exp-filter-bar label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--clr-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .exp-filter-bar input[type="text"],
        .exp-filter-bar select {
            padding: 8px 12px;
            background: var(--clr-bg);
            border: 1px solid var(--clr-border);
            border-radius: 8px;
            color: var(--clr-text);
            font-size: 0.9rem;
            min-width: 220px;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        .exp-filter-bar input[type="text"]:focus,
        .exp-filter-bar select:focus {
            border-color: var(--clr-primary);
            box-shadow: 0 0 0 3px rgba(56,189,248,.15);
        }
        .exp-filter-bar select { min-width: 160px; }

        .btn-apply,
        .btn-clear {
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .2s, border-color .2s, color .2s;
            display: inline-flex;
            align-items: center;
        }
        .btn-apply {
            background: var(--clr-primary);
            color: #0f172a;
            border: 1px solid var(--clr-primary);
        }
        .btn-apply:hover { background: var(--clr-primary-h); border-color: var(--clr-primary-h); }
        .btn-clear {
            background: transparent;
            color: var(--clr-muted);
            border: 1px solid var(--clr-border);
        }
        .btn-clear:hover { border-color: var(--clr-primary); color: var(--clr-primary); }

        /* ── Status badges (extra colour) ─────────────────────────────── */
        .badge-cyan { background: rgba(56,189,248,.15); color: #38bdf8; }

        /* ── Experiments table tweaks ─────────────────────────────────── */
        .exp-title {
            font-weight: 600;
            color: var(--clr-text);
        }
        .exp-desc {
            font-size: 0.8rem;
            color: var(--clr-muted);
            margin-top: 2px;
            max-width: 340px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .exp-meta-cell {
            color: var(--clr-text);
            font-size: 0.875rem;
        }
        .exp-meta-cell .secondary {
            display: block;
            color: var(--clr-muted);
            font-size: 0.78rem;
            margin-top: 2px;
        }

        /* ── Empty state ──────────────────────────────────────────────── */
        .empty-panel {
            text-align: center;
            padding: 50px 20px;
            color: var(--clr-muted);
        }
        .empty-panel h4 {
            color: var(--clr-text);
            font-size: 1rem;
            margin-bottom: 6px;
        }

        /* ── Alert ────────────────────────────────────────────────────── */
        .alert-error-dark {
            background: var(--clr-error-bg);
            color: var(--clr-error);
            border: 1px solid var(--clr-error);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.9rem;
            margin-bottom: 16px;
        }
    </style>
</head>
<body class="dashboard-body">

<!-- ── Top Nav ──────────────────────────────────────────── -->
<header class="topnav">
    <div class="topnav-brand">
        <div class="logo-sm">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/>
            </svg>
        </div>
        <span class="brand-name">LabTrack</span>
    </div>

    <nav class="topnav-links">
        <a href="dashboard.html" class="nav-link">Dashboard</a>
        <a href="inventory.php"  class="nav-link">Inventory</a>
        <a href="experiments.php" class="nav-link active">Experiments</a>
        <a href="documents.php"  class="nav-link">Documents</a>
    </nav>

    <div class="topnav-user">
        <span class="user-greeting"><?= $username ?></span>
        <a href="logout.php" class="btn-logout" style="text-decoration:none;">Sign Out</a>
    </div>
</header>

<!-- ── Main ─────────────────────────────────────────────── -->
<main class="dash-main">

    <!-- Hero -->
    <section class="dash-hero">
        <div class="dash-hero-text">
            <h2>Experiments</h2>
            <p>All experiments tracked across the lab — search, filter and review status at a glance.</p>
        </div>
        <div class="dash-hero-badge">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 2v6L3 22h18L15 8V2"/>
                <line x1="9" y1="2" x2="15" y2="2"/>
            </svg>
        </div>
    </section>

    <!-- Stat cards -->
    <section class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon cyan">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 17H7A5 5 0 0 1 7 7h2"/>
                    <path d="M15 7h2a5 5 0 0 1 0 10h-2"/>
                    <line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
            </div>
            <div class="stat-info">
                <span class="stat-label">Total Experiments</span>
                <span class="stat-value"><?= $total ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <div class="stat-info">
                <span class="stat-label">Active</span>
                <span class="stat-value"><?= $activeCnt ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon amber">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <div class="stat-info">
                <span class="stat-label">Planned</span>
                <span class="stat-value"><?= $plannedCnt ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon red">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <div class="stat-info">
                <span class="stat-label">Completed</span>
                <span class="stat-value"><?= $doneCnt ?></span>
            </div>
        </div>
    </section>

    <?php if ($error): ?>
        <div class="alert-error-dark"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Filter bar -->
    <form method="GET" action="experiments.php" class="exp-filter-bar">
        <label>
            Search Title
            <input type="text"
                   name="search"
                   placeholder="e.g. Protein Wash"
                   value="<?= htmlspecialchars($search) ?>"
                   maxlength="100">
        </label>

        <label>
            Status
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $st): ?>
                    <option value="<?= htmlspecialchars($st) ?>"
                        <?= $status_filter === $st ? 'selected' : '' ?>>
                        <?= htmlspecialchars($st) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <button type="submit" class="btn-apply">Apply</button>

        <?php if ($search !== '' || $status_filter !== ''): ?>
            <a href="experiments.php" class="btn-clear">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Experiments table -->
    <div class="dash-panel">
        <div class="panel-header">
            <h3>All Experiments</h3>
            <span class="panel-link" style="cursor:default;"><?= $total ?> result<?= $total === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($total === 0): ?>
            <div class="empty-panel">
                <h4>No experiments found.</h4>
                <p>
                    <?php if ($search !== '' || $status_filter !== ''): ?>
                        Try clearing your filters.
                    <?php else: ?>
                        No experiments have been recorded yet.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Lab</th>
                        <th>Created By</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($experiments as $row): ?>
                    <tr>
                        <td>
                            <div class="exp-title"><?= htmlspecialchars($row['title']) ?></div>
                            <?php if (!empty($row['description'])): ?>
                                <div class="exp-desc"><?= htmlspecialchars($row['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= statusBadge($row['status'] ?? '') ?>">
                                <?= htmlspecialchars($row['status'] ?? 'Unknown') ?>
                            </span>
                        </td>
                        <td class="exp-meta-cell">
                            <?= htmlspecialchars($row['lab_name'] ?? '—') ?>
                            <?php if (!empty($row['room_num'])): ?>
                                <span class="secondary">Room <?= htmlspecialchars($row['room_num']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="exp-meta-cell">
                            <?php if (!empty($row['first_name']) || !empty($row['last_name'])): ?>
                                <?= htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?>
                                <?php if (!empty($row['role'])): ?>
                                    <span class="secondary"><?= htmlspecialchars($row['role']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--clr-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="exp-meta-cell">
                            <?php
                                $created = $row['created_at'] ?? '';
                                if ($created) {
                                    try {
                                        $dt = new DateTimeImmutable($created);
                                        echo htmlspecialchars($dt->format('M j, Y'));
                                        echo '<span class="secondary">' . htmlspecialchars($dt->format('g:i a')) . '</span>';
                                    } catch (Exception $e) {
                                        echo htmlspecialchars($created);
                                    }
                                } else {
                                    echo '—';
                                }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</main>

<footer class="dash-footer">
    LabTrack &copy; 2026 &mdash; University of Rhode Island
</footer>

</body>
</html>
