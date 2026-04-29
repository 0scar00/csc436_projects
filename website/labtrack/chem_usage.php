<?php
// chem_usage.php — LabTrack Chemical Usage Log
// Lets users record which chemical (inventory item) was used in which experiment,
// and browse the full usage history.

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/database-connection.php';

if (!$logged_in) {
    header('Location: login.php');
    exit;
}

$username = htmlspecialchars($_SESSION['username'] ?? 'User');
$success  = '';
$error    = '';

// ── Load dropdowns ────────────────────────────────────────────────────────────
try {
    // Chemicals with their current inventory items
    $chemStmt = $pdo->query("
        SELECT ii.item_id,
               c.chemical_name,
               b.lot_number,
               ii.quantity,
               ii.unit,
               ii.status
        FROM   Inventory_item ii
        JOIN   Batch     b ON b.batch_id    = ii.batch_id
        JOIN   Chemical  c ON c.chemical_id = b.chemical_id
        WHERE  ii.status != 'Out of Stock'
        ORDER  BY c.chemical_name, b.lot_number
    ");
    $chemicals = $chemStmt->fetchAll();

    // Experiments
    $expStmt = $pdo->query("
        SELECT experiment_id, title, status
        FROM   Experiment
        ORDER  BY title ASC
    ");
    $experiments = $expStmt->fetchAll();
} catch (PDOException $e) {
    error_log('LabTrack chem_usage load error: ' . $e->getMessage());
    $error = 'Could not load form data. Please try again.';
    $chemicals   = [];
    $experiments = [];
}

// ── Handle POST (log a new usage entry) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $item_id       = (int) ($_POST['item_id']       ?? 0);
    $experiment_id = (int) ($_POST['experiment_id'] ?? 0);
    $qty_used      = trim($_POST['qty_used']         ?? '');
    $unit_used     = trim($_POST['unit_used']        ?? '');
    $notes         = trim($_POST['notes']            ?? '');

    if ($item_id <= 0 || $experiment_id <= 0 || $qty_used === '' || !is_numeric($qty_used)) {
        $error = 'Please fill in all required fields with valid values.';
    } else {
        try {
            // Insert into Chemical_usage (create table if absent — safe guard)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS Chemical_usage (
                    usage_id       INT          AUTO_INCREMENT PRIMARY KEY,
                    item_id        INT          NOT NULL,
                    experiment_id  INT          NOT NULL,
                    used_by_user_id INT         NOT NULL,
                    qty_used       DECIMAL(12,4) NOT NULL,
                    unit_used      VARCHAR(30)  NOT NULL,
                    notes          TEXT,
                    used_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (item_id)       REFERENCES Inventory_item(item_id),
                    FOREIGN KEY (experiment_id) REFERENCES Experiment(experiment_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $ins = $pdo->prepare("
                INSERT INTO Chemical_usage
                    (item_id, experiment_id, used_by_user_id, qty_used, unit_used, notes, used_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([
                $item_id,
                $experiment_id,
                $_SESSION['user_id'],
                (float) $qty_used,
                $unit_used,
                $notes ?: null,
            ]);

            $success = 'Usage entry recorded successfully.';
        } catch (PDOException $e) {
            error_log('LabTrack chem_usage insert error: ' . $e->getMessage());
            $error = 'Could not save the entry. Please try again.';
        }
    }
}

// ── Load usage history ────────────────────────────────────────────────────────
$search_exp  = trim($_GET['search_exp']  ?? '');
$search_chem = trim($_GET['search_chem'] ?? '');
$history     = [];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Chemical_usage (
            usage_id        INT           AUTO_INCREMENT PRIMARY KEY,
            item_id         INT           NOT NULL,
            experiment_id   INT           NOT NULL,
            used_by_user_id INT           NOT NULL,
            qty_used        DECIMAL(12,4) NOT NULL,
            unit_used       VARCHAR(30)   NOT NULL,
            notes           TEXT,
            used_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id)       REFERENCES Inventory_item(item_id),
            FOREIGN KEY (experiment_id) REFERENCES Experiment(experiment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hSql = "
        SELECT
            cu.usage_id,
            cu.qty_used,
            cu.unit_used,
            cu.notes,
            cu.used_at,
            c.chemical_name,
            b.lot_number,
            e.title          AS experiment_title,
            e.status         AS experiment_status,
            u.first_name,
            u.last_name
        FROM   Chemical_usage  cu
        JOIN   Inventory_item  ii ON ii.item_id       = cu.item_id
        JOIN   Batch           b  ON b.batch_id       = ii.batch_id
        JOIN   Chemical        c  ON c.chemical_id    = b.chemical_id
        JOIN   Experiment      e  ON e.experiment_id  = cu.experiment_id
        LEFT JOIN User         u  ON u.user_id        = cu.used_by_user_id
        WHERE  1=1
    ";
    $hParams = [];

    if ($search_chem !== '') {
        $hSql    .= " AND c.chemical_name LIKE ?";
        $hParams[] = '%' . $search_chem . '%';
    }
    if ($search_exp !== '') {
        $hSql    .= " AND e.title LIKE ?";
        $hParams[] = '%' . $search_exp . '%';
    }

    $hSql .= " ORDER BY cu.used_at DESC";

    $hStmt   = $pdo->prepare($hSql);
    $hStmt->execute($hParams);
    $history = $hStmt->fetchAll();
} catch (PDOException $e) {
    error_log('LabTrack chem_usage history error: ' . $e->getMessage());
    $history = [];
}

// ── Helper: experiment status badge ──────────────────────────────────────────
function expBadge(string $s): string {
    $s = strtolower($s);
    if ($s === 'active')                                      return 'badge-green';
    if ($s === 'planned' || $s === 'pending')                 return 'badge-amber';
    if ($s === 'completed' || $s === 'complete' || $s === 'done') return 'badge-cyan';
    if ($s === 'cancelled' || $s === 'canceled')              return 'badge-red';
    return 'badge-gray';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>LabTrack — Chemical Usage</title>
    <link rel="stylesheet" href="login.css" />
    <link rel="stylesheet" href="dashboard.css" />
    <style>
        /* ── Badge extras ─────────────────────────────────────────────── */
        .badge-cyan { background: rgba(56,189,248,.15); color: #38bdf8; }

        /* ── Two-column layout ────────────────────────────────────────── */
        .usage-layout {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 960px) {
            .usage-layout { grid-template-columns: 1fr; }
        }

        /* ── Form card ────────────────────────────────────────────────── */
        .form-card {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius);
            padding: 24px;
            position: sticky;
            top: 80px;
        }
        .form-card h3 {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--clr-text);
            margin-bottom: 20px;
        }
        .form-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 16px;
        }
        .form-field label {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--clr-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .form-field select,
        .form-field input[type="number"],
        .form-field input[type="text"],
        .form-field textarea {
            padding: 9px 12px;
            background: var(--clr-bg);
            border: 1px solid var(--clr-border);
            border-radius: 8px;
            color: var(--clr-text);
            font-size: 0.9rem;
            outline: none;
            width: 100%;
            transition: border-color .2s, box-shadow .2s;
            font-family: inherit;
        }
        .form-field select:focus,
        .form-field input:focus,
        .form-field textarea:focus {
            border-color: var(--clr-primary);
            box-shadow: 0 0 0 3px rgba(56,189,248,.15);
        }
        .form-field textarea { resize: vertical; min-height: 72px; }
        .qty-row {
            display: grid;
            grid-template-columns: 1fr 100px;
            gap: 8px;
        }

        .btn-submit {
            width: 100%;
            padding: 10px;
            background: var(--clr-primary);
            color: #0f172a;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 4px;
            transition: background .2s;
        }
        .btn-submit:hover { background: var(--clr-primary-h); }

        /* ── Alert banners ────────────────────────────────────────────── */
        .alert-success {
            background: rgba(52,211,153,.12);
            color: #34d399;
            border: 1px solid rgba(52,211,153,.3);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.875rem;
            margin-bottom: 16px;
        }
        .alert-error-dark {
            background: var(--clr-error-bg);
            color: var(--clr-error);
            border: 1px solid var(--clr-error);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.875rem;
            margin-bottom: 16px;
        }

        /* ── History filter bar ───────────────────────────────────────── */
        .hist-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
            margin-bottom: 18px;
        }
        .hist-filter label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--clr-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .hist-filter input[type="text"] {
            padding: 7px 11px;
            background: var(--clr-bg);
            border: 1px solid var(--clr-border);
            border-radius: 8px;
            color: var(--clr-text);
            font-size: 0.875rem;
            width: 190px;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        .hist-filter input[type="text"]:focus {
            border-color: var(--clr-primary);
            box-shadow: 0 0 0 3px rgba(56,189,248,.15);
        }
        .btn-filter {
            padding: 7px 16px;
            background: var(--clr-primary);
            color: #0f172a;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-filter:hover { background: var(--clr-primary-h); }
        .btn-filter-clear {
            padding: 7px 16px;
            background: transparent;
            color: var(--clr-muted);
            border: 1px solid var(--clr-border);
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: border-color .2s, color .2s;
        }
        .btn-filter-clear:hover { border-color: var(--clr-primary); color: var(--clr-primary); }

        /* ── History table tweaks ─────────────────────────────────────── */
        .chem-cell { font-weight: 600; color: var(--clr-text); }
        .lot-tag {
            display: inline-block;
            font-family: monospace;
            font-size: 0.78rem;
            color: var(--clr-muted);
            margin-top: 2px;
        }
        .exp-cell { color: var(--clr-text); font-size: 0.875rem; }
        .notes-cell {
            font-size: 0.8rem;
            color: var(--clr-muted);
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .date-cell { color: var(--clr-text); font-size: 0.875rem; }
        .date-cell .secondary {
            display: block;
            color: var(--clr-muted);
            font-size: 0.78rem;
            margin-top: 2px;
        }
        .qty-cell { font-weight: 600; color: var(--clr-text); white-space: nowrap; }
        .unit-tag { color: var(--clr-muted); font-weight: 400; font-size: 0.82rem; }

        .empty-panel {
            text-align: center;
            padding: 48px 20px;
            color: var(--clr-muted);
        }
        .empty-panel h4 { color: var(--clr-text); font-size: 1rem; margin-bottom: 6px; }
    </style>
</head>
<body class="dashboard-body">

<!-- ── Top Nav ────────────────────────────────────────────────────────────── -->
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
        <a href="dashboard.php"  class="nav-link">Dashboard</a>
        <a href="inventory.php"   class="nav-link">Inventory</a>
        <a href="experiments.php" class="nav-link">Experiments</a>
        <a href="documents.php"   class="nav-link">Documents</a>
        <a href="chem_usage.php"  class="nav-link active">Chemical Usage</a>
    </nav>

    <div class="topnav-user">
        <span class="user-greeting"><?= $username ?></span>
        <a href="logout.php" class="btn-logout" style="text-decoration:none;">Sign Out</a>
    </div>
</header>

<!-- ── Main ───────────────────────────────────────────────────────────────── -->
<main class="dash-main">

    <!-- Hero -->
    <section class="dash-hero">
        <div class="dash-hero-text">
            <h2>Chemical Usage</h2>
            <p>Record which chemicals were used in each experiment and browse the full usage history.</p>
        </div>
        <div class="dash-hero-badge">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 2v6L3 22h18L15 8V2"/>
                <line x1="9" y1="2" x2="15" y2="2"/>
                <path d="M6 18h12"/>
            </svg>
        </div>
    </section>

    <!-- Two-column layout: form + history table -->
    <div class="usage-layout">

        <!-- ── Log form ──────────────────────────────────────────────────── -->
        <div class="form-card">
            <h3>Log Chemical Usage</h3>

            <?php if ($success): ?>
                <div class="alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-error-dark"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="chem_usage.php">

                <div class="form-field">
                    <label for="item_id">Chemical / Lot *</label>
                    <select name="item_id" id="item_id" required>
                        <option value="">— Select a chemical —</option>
                        <?php foreach ($chemicals as $c): ?>
                            <option value="<?= (int) $c['item_id'] ?>"
                                <?= (isset($_POST['item_id']) && (int)$_POST['item_id'] === (int)$c['item_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['chemical_name']) ?>
                                — Lot <?= htmlspecialchars($c['lot_number']) ?>
                                (<?= htmlspecialchars(number_format((float)$c['quantity'], 2)) ?> <?= htmlspecialchars($c['unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="experiment_id">Experiment *</label>
                    <select name="experiment_id" id="experiment_id" required>
                        <option value="">— Select an experiment —</option>
                        <?php foreach ($experiments as $e): ?>
                            <option value="<?= (int) $e['experiment_id'] ?>"
                                <?= (isset($_POST['experiment_id']) && (int)$_POST['experiment_id'] === (int)$e['experiment_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['title']) ?>
                                (<?= htmlspecialchars($e['status']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label>Quantity Used *</label>
                    <div class="qty-row">
                        <input
                            type="number"
                            name="qty_used"
                            id="qty_used"
                            placeholder="e.g. 25.5"
                            step="0.0001"
                            min="0"
                            value="<?= htmlspecialchars($_POST['qty_used'] ?? '') ?>"
                            required
                        />
                        <input
                            type="text"
                            name="unit_used"
                            id="unit_used"
                            placeholder="mL"
                            maxlength="20"
                            value="<?= htmlspecialchars($_POST['unit_used'] ?? '') ?>"
                            required
                        />
                    </div>
                </div>

                <div class="form-field">
                    <label for="notes">Notes <span style="font-weight:400;text-transform:none;">(optional)</span></label>
                    <textarea name="notes" id="notes" placeholder="e.g. Used in titration step 3…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn-submit">
                    Log Usage
                </button>
            </form>
        </div>

        <!-- ── Usage history ──────────────────────────────────────────────── -->
        <div class="dash-panel" style="padding: 22px 24px;">
            <div class="panel-header" style="margin-bottom: 16px;">
                <h3>Usage History</h3>
                <span class="panel-link" style="cursor:default;"><?= count($history) ?> record<?= count($history) === 1 ? '' : 's' ?></span>
            </div>

            <!-- Filter bar -->
            <form method="GET" action="chem_usage.php" class="hist-filter">
                <label>
                    Chemical
                    <input type="text"
                           name="search_chem"
                           placeholder="e.g. Ethanol"
                           value="<?= htmlspecialchars($search_chem) ?>"
                           maxlength="100">
                </label>
                <label>
                    Experiment
                    <input type="text"
                           name="search_exp"
                           placeholder="e.g. Protein Wash"
                           value="<?= htmlspecialchars($search_exp) ?>"
                           maxlength="100">
                </label>
                <button type="submit" class="btn-filter">Filter</button>
                <?php if ($search_chem !== '' || $search_exp !== ''): ?>
                    <a href="chem_usage.php" class="btn-filter-clear">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (empty($history)): ?>
                <div class="empty-panel">
                    <h4>No usage records found.</h4>
                    <p>
                        <?php if ($search_chem !== '' || $search_exp !== ''): ?>
                            Try clearing your filters.
                        <?php else: ?>
                            Log a chemical usage entry using the form on the left.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Chemical</th>
                            <th>Experiment</th>
                            <th>Amount Used</th>
                            <th>Logged By</th>
                            <th>Date</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td>
                                <div class="chem-cell"><?= htmlspecialchars($row['chemical_name']) ?></div>
                                <span class="lot-tag">Lot <?= htmlspecialchars($row['lot_number']) ?></span>
                            </td>
                            <td>
                                <div class="exp-cell"><?= htmlspecialchars($row['experiment_title']) ?></div>
                                <span class="badge <?= expBadge($row['experiment_status'] ?? '') ?>" style="margin-top:4px;font-size:0.7rem;">
                                    <?= htmlspecialchars($row['experiment_status'] ?? '—') ?>
                                </span>
                            </td>
                            <td class="qty-cell">
                                <?= htmlspecialchars(number_format((float)$row['qty_used'], 4, '.', '')) ?>
                                <span class="unit-tag"><?= htmlspecialchars($row['unit_used']) ?></span>
                            </td>
                            <td style="color:var(--clr-text);font-size:0.875rem;">
                                <?php
                                    $fn = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                    echo $fn !== '' ? htmlspecialchars($fn) : '<span style="color:var(--clr-muted);">—</span>';
                                ?>
                            </td>
                            <td class="date-cell">
                                <?php
                                    if (!empty($row['used_at'])) {
                                        try {
                                            $dt = new DateTimeImmutable($row['used_at']);
                                            echo htmlspecialchars($dt->format('M j, Y'));
                                            echo '<span class="secondary">' . htmlspecialchars($dt->format('g:i a')) . '</span>';
                                        } catch (Exception $e) {
                                            echo htmlspecialchars($row['used_at']);
                                        }
                                    } else {
                                        echo '—';
                                    }
                                ?>
                            </td>
                            <td class="notes-cell" title="<?= htmlspecialchars($row['notes'] ?? '') ?>">
                                <?= !empty($row['notes']) ? htmlspecialchars($row['notes']) : '<span style="color:var(--clr-muted);">—</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div><!-- /.usage-layout -->

</main>

<footer class="dash-footer">
    LabTrack &copy; 2026 &mdash; University of Rhode Island
</footer>

</body>
</html>
