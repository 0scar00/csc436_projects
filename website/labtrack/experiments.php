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
// Handle Add Experiment form submission
$expAddErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_experiment') {
    $exp_title       = trim($_POST['exp_title']       ?? '');
    $exp_description = trim($_POST['exp_description'] ?? '');
    $exp_status      = trim($_POST['exp_status']      ?? 'Planned');
    $exp_lab_id      = (int)($_POST['exp_lab_id']     ?? 0);
    $user_id         = (int)($_SESSION['user_id']     ?? 0);

    if (!$exp_title || !$exp_lab_id) {
        $expAddErr = 'Please fill in the title and lab.';
    } else {
        try {
            $pdo->prepare("INSERT INTO Experiment (lab_id, created_by_user_id, title, description, created_at, status) VALUES (?,?,?,?,NOW(),?)")
                ->execute([$exp_lab_id, $user_id, $exp_title, $exp_description ?: null, $exp_status]);
            header('Location: experiments.php');
            exit;
        } catch (PDOException $e) {
            error_log('Add experiment error: ' . $e->getMessage());
            $expAddErr = 'Failed to add experiment. Please try again.';
        }
    }
}

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

// Fetch labs for Add Experiment modal
try {
    $labs = $pdo->query("SELECT lab_id, lab_name FROM Lab ORDER BY lab_name ASC")->fetchAll();
} catch (PDOException $e) {
    $labs = [];
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

        /* -- Add-record modal ------------------------------------------------- */
        .btn-add {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: 9px 18px;
            background: var(--clr-primary);
            color: #0f172a;
            border: 1px solid var(--clr-primary);
            border-radius: 8px;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }
        .btn-add:hover { background: var(--clr-primary-h); }
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius);
            padding: 26px 30px 20px;
            width: 100%;
            max-width: 580px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        .modal-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--clr-text);
            margin-bottom: 18px;
        }
        .modal-close-btn {
            position: absolute;
            top: 12px;
            right: 16px;
            background: none;
            border: none;
            color: var(--clr-muted);
            font-size: 1.4rem;
            cursor: pointer;
            line-height: 1;
            padding: 0;
        }
        .modal-close-btn:hover { color: var(--clr-text); }
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 11px 18px;
        }
        .form-grid-2 .f-full { grid-column: 1 / -1; }
        .f-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .f-field label {
            font-size: .72rem;
            font-weight: 600;
            color: var(--clr-muted);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .f-field input,
        .f-field select,
        .f-field textarea {
            padding: 8px 11px;
            background: var(--clr-bg);
            border: 1px solid var(--clr-border);
            border-radius: 8px;
            color: var(--clr-text);
            font-size: .875rem;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
            width: 100%;
            box-sizing: border-box;
        }
        .f-field input:focus,
        .f-field select:focus,
        .f-field textarea:focus {
            border-color: var(--clr-primary);
            box-shadow: 0 0 0 3px rgba(56,189,248,.15);
        }
        .f-field input::placeholder,
        .f-field textarea::placeholder { color: #475569; }
        .modal-footer-row {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid var(--clr-border);
        }
        .modal-save-btn {
            padding: 8px 20px;
            background: var(--clr-primary);
            color: #0f172a;
            border: 1px solid var(--clr-primary);
            border-radius: 8px;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }
        .modal-save-btn:hover { background: var(--clr-primary-h); }
        .modal-cancel-btn {
            padding: 8px 16px;
            background: transparent;
            color: var(--clr-muted);
            border: 1px solid var(--clr-border);
            border-radius: 8px;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            transition: border-color .2s, color .2s;
        }
        .modal-cancel-btn:hover { border-color: var(--clr-primary); color: var(--clr-primary); }
        .modal-alert-err {
            background: var(--clr-error-bg);
            color: var(--clr-error);
            border: 1px solid var(--clr-error);
            border-radius: 8px;
            padding: 8px 14px;
            font-size: .875rem;
            margin-bottom: 14px;
        }
    </style>
</head>
<body class="dashboard-body">

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
        <a href="chem_usage.php" class="nav-link">Chemical Usage</a>
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
    <div style="display:flex;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="btn-add" onclick="document.getElementById('modalAddExp').classList.add('open')">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Experiment
        </button>
    </div>

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

<!-- New Experiment modal -->
<div class="modal-overlay" id="modalAddExp">
    <div class="modal-box">
        <button class="modal-close-btn" onclick="document.getElementById('modalAddExp').classList.remove('open')" aria-label="Close">&times;</button>
        <div class="modal-title">Add New Experiment</div>

        <?php if ($expAddErr): ?>
            <div class="modal-alert-err"><?= htmlspecialchars($expAddErr) ?></div>
        <?php endif; ?>

        <form method="POST" action="experiments.php">
            <input type="hidden" name="action" value="add_experiment">
            <div class="form-grid-2">
                <div class="f-field f-full">
                    <label>Title *</label>
                    <input type="text" name="exp_title" required maxlength="150"
                           placeholder="e.g. Protein Wash Buffer Prep"
                           value="<?= htmlspecialchars($_POST['exp_title'] ?? '') ?>">
                </div>
                <div class="f-field f-full">
                    <label>Description</label>
                    <textarea name="exp_description" rows="3"
                              placeholder="Brief description of the experiment..."><?= htmlspecialchars($_POST['exp_description'] ?? '') ?></textarea>
                </div>
                <div class="f-field">
                    <label>Status *</label>
                    <select name="exp_status" required>
                        <?php foreach (['Planned','Active','Completed','Cancelled'] as $st): ?>
                            <option value="<?= htmlspecialchars($st) ?>"
                                <?= (($_POST['exp_status'] ?? 'Planned') === $st ? 'selected' : '') ?>>
                                <?= htmlspecialchars($st) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-field">
                    <label>Lab *</label>
                    <select name="exp_lab_id" required>
                        <option value="">Select lab...</option>
                        <?php foreach ($labs as $lab): ?>
                            <option value="<?= (int)$lab['lab_id'] ?>"
                                <?= ((int)($_POST['exp_lab_id'] ?? 0) === (int)$lab['lab_id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars($lab['lab_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="modal-cancel-btn" onclick="document.getElementById('modalAddExp').classList.remove('open')">Cancel</button>
                <button type="submit" class="modal-save-btn">Save Experiment</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('modalAddExp').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
<?php if ($expAddErr): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('modalAddExp').classList.add('open');
});
<?php endif; ?>
</script>

<footer class="dash-footer">
    LabTrack &copy; 2026 &mdash; University of Rhode Island
</footer>

</body>
</html>
