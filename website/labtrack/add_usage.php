<?php
// add_usage.php — LabTrack Log Chemical Usage
// Accepts item_id from GET, validates the amount, then runs a transaction that:
//   1. INSERTs a row into Usage_log
//   2. DECREMENTs Inventory_item.quantity
// Uses PDO prepared statements throughout. All output is escaped.

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/database-connection.php';

// ── Authentication guard ──────────────────────────────────────────────────────
if (!$logged_in) {
    header('Location: login.php');
    exit;
}


// ── Grab and validate item_id from GET ────────────────────────────────────────
$item_id = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);
if (!$item_id || $item_id < 1) {
    header('Location: inventory.php');
    exit;
}

// ── Page state ────────────────────────────────────────────────────────────────
$item        = null;   // fetched inventory row
$experiments = [];     // dropdown options
$success_msg = '';
$errors      = [];

// ── Fetch item details (read) ─────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT
            ii.item_id,
            ii.quantity,
            ii.unit,
            ii.status,
            c.chemical_name,
            c.hazard_class,
            b.lot_number,
            b.expiration_date,
            sl.location_name
        FROM Inventory_item   ii
        JOIN Batch            b   ON ii.batch_id    = b.batch_id
        JOIN Chemical         c   ON b.chemical_id  = c.chemical_id
        JOIN Storage_location sl  ON ii.location_id = sl.location_id
        WHERE ii.item_id = ?
        LIMIT 1
    ");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();

    if (!$item) {
        // Item doesn't exist — send user back
        header('Location: inventory.php');
        exit;
    }

    // Also load active experiments for the dropdown
    $expStmt = $pdo->prepare("
        SELECT experiment_id, title
        FROM   Experiment
        WHERE  status IN ('Active','Planned')
        ORDER  BY title ASC
    ");
    $expStmt->execute();
    $experiments = $expStmt->fetchAll();

} catch (PDOException $e) {
    error_log('LabTrack add_usage fetch error: ' . $e->getMessage());
    $errors[] = 'Could not load item details. Please try again.';
}

// ── Handle form submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $item) {

    // ── Collect & sanitize inputs ─────────────────────────────────────────
    $amount_used    = trim($_POST['amount_used']    ?? '');
    $experiment_id  = filter_input(INPUT_POST, 'experiment_id', FILTER_VALIDATE_INT);
    $notes          = trim($_POST['notes']          ?? '');

    // ── Server-side validation ────────────────────────────────────────────
    if ($amount_used === '') {
        $errors[] = 'Amount used is required.';
    } elseif (!is_numeric($amount_used)) {
        $errors[] = 'Amount used must be a number.';
    } elseif ((float)$amount_used <= 0) {
        $errors[] = 'Amount used must be greater than zero.';
    }

    if (!$experiment_id || $experiment_id < 1) {
        $errors[] = 'Please select an experiment.';
    }

    if (strlen($notes) > 500) {
        $errors[] = 'Notes must be 500 characters or fewer.';
    }

    // ── Negative-inventory guard (re-read quantity inside tx for safety) ──
    $amount_float = empty($errors) ? (float)$amount_used : 0;

    if (empty($errors)) {
        // ── TRANSACTION: INSERT usage log + UPDATE inventory ──────────────
        try {
            $pdo->beginTransaction();

            // 1. Lock the row and read current quantity (SELECT … FOR UPDATE)
            $lockStmt = $pdo->prepare("
                SELECT quantity
                FROM   Inventory_item
                WHERE  item_id = ?
                FOR UPDATE
            ");
            $lockStmt->execute([$item_id]);
            $locked = $lockStmt->fetch();

            if (!$locked) {
                throw new RuntimeException('Inventory item not found during transaction.');
            }

            $current_qty = (float)$locked['quantity'];

            if ($amount_float > $current_qty) {
                // Rollback before throwing so the lock is released
                $pdo->rollBack();
                $errors[] = sprintf(
                    'Not enough stock. You requested %.2f %s but only %.2f %s are available.',
                    $amount_float,
                    htmlspecialchars($item['unit']),
                    $current_qty,
                    htmlspecialchars($item['unit'])
                );
            } else {
                // 2. INSERT into Usage_log
                $insertStmt = $pdo->prepare("
                    INSERT INTO Usage_log
                        (experiment_id, inventory_item_id, used_by_user_id, used_at, amount_used, unit, notes)
                    VALUES
                        (?, ?, ?, NOW(), ?, ?, ?)
                ");
                $insertStmt->execute([
                    $experiment_id,
                    $item_id,
                    $_SESSION['user_id'],
                    $amount_float,
                    $item['unit'],
                    $notes !== '' ? $notes : null,
                ]);

                // 3. DECREMENT Inventory_item.quantity
                $new_qty = $current_qty - $amount_float;
                $new_status = match(true) {
                    $new_qty <= 0   => 'Out of Stock',
                    $new_qty < 100  => 'Low Stock',    // threshold: adjust as needed
                    default         => 'In Stock',
                };

                $updateStmt = $pdo->prepare("
                    UPDATE Inventory_item
                    SET    quantity = ?,
                           status   = ?
                    WHERE  item_id  = ?
                ");
                $updateStmt->execute([$new_qty, $new_status, $item_id]);

                $pdo->commit();

                // ── Refresh item display data after commit ────────────────
                $item['quantity'] = $new_qty;
                $item['status']   = $new_status;

                $success_msg = sprintf(
                    'Usage logged successfully. Recorded %.2f %s of %s. Remaining stock: %.2f %s.',
                    $amount_float,
                    htmlspecialchars($item['unit']),
                    htmlspecialchars($item['chemical_name']),
                    $new_qty,
                    htmlspecialchars($item['unit'])
                );
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('LabTrack usage transaction error: ' . $e->getMessage());
            $errors[] = 'A database error occurred. The usage was not recorded. Please try again.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('LabTrack usage runtime error: ' . $e->getMessage());
            $errors[] = 'Could not complete the operation. Please try again.';
        }
    }
}

$username = htmlspecialchars($_SESSION['username'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Usage — LabTrack</title>
    <link rel="stylesheet" href="login.css" />
    <link rel="stylesheet" href="dashboard.css" />
    <style>
        /* ── Page wrapper override (narrower for form pages) ──────────────── */
        .page-wrapper-form {
            max-width: 820px;
            margin: 0 auto;
            padding: 24px 28px 48px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .breadcrumb {
            font-size: .85rem;
            color: var(--clr-muted);
        }
        .breadcrumb a {
            color: var(--clr-primary);
            text-decoration: none;
        }
        .breadcrumb a:hover { text-decoration: underline; }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--clr-text);
            letter-spacing: -0.02em;
        }

        /* ── Cards (dark) ─────────────────────────────────────────────────── */
        .card {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius);
            padding: 24px;
        }
        .card h2 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--clr-text);
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--clr-border);
        }

        /* ── Item info grid ───────────────────────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 16px 24px;
        }
        .info-cell { display: flex; flex-direction: column; gap: 4px; }
        .info-cell .label {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .07em;
            font-weight: 700;
            color: var(--clr-muted);
        }
        .info-cell .value {
            font-size: .95rem;
            color: var(--clr-text);
            font-weight: 500;
        }
        .qty-display {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--clr-primary);
            line-height: 1.1;
        }

        /* ── Badges (dark — same palette as dashboard) ────────────────────── */
        .badge {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: .74rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-flammable { background: rgba(251,191,36,.15);  color: #fbbf24; }
        .badge-corrosive { background: rgba(248,113,113,.15); color: #f87171; }
        .badge-irritant  { background: rgba(56,189,248,.15);  color: #38bdf8; }
        .badge-low       { background: rgba(52,211,153,.15);  color: #34d399; }
        .badge-default   { background: rgba(148,163,184,.12); color: #94a3b8; }

        .status-in    { background: rgba(52,211,153,.15);  color: #34d399; }
        .status-low   { background: rgba(251,191,36,.15);  color: #fbbf24; }
        .status-out   { background: rgba(248,113,113,.15); color: #f87171; }

        /* ── Form (dark) ──────────────────────────────────────────────────── */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
        }
        .form-group:last-of-type { margin-bottom: 0; }

        label {
            font-size: .8rem;
            font-weight: 600;
            color: var(--clr-muted);
            letter-spacing: 0.02em;
        }
        label .required { color: var(--clr-error); margin-left: .2rem; }

        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: 10px 14px;
            background: var(--clr-bg);
            border: 1px solid var(--clr-border);
            border-radius: 8px;
            color: var(--clr-text);
            font-size: .95rem;
            font-family: inherit;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        input[type="number"]:focus,
        select:focus,
        textarea:focus {
            border-color: var(--clr-primary);
            box-shadow: 0 0 0 3px rgba(56,189,248,.15);
        }
        input::placeholder, textarea::placeholder { color: #475569; }
        input.input-error,
        select.input-error,
        textarea.input-error {
            border-color: var(--clr-error);
        }
        textarea { resize: vertical; min-height: 90px; }
        select option { background: var(--clr-surface); color: var(--clr-text); }

        .hint {
            font-size: .78rem;
            color: var(--clr-muted);
        }

        /* ── Progress bar ─────────────────────────────────────────────────── */
        .qty-bar-wrap {
            background: rgba(255,255,255,.06);
            border-radius: 999px;
            height: 6px;
            overflow: hidden;
            margin-top: 6px;
        }
        .qty-bar {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #34d399, #38bdf8);
            transition: width .4s ease;
        }

        /* ── Alerts (dark) ────────────────────────────────────────────────── */
        .alert {
            border-radius: 8px;
            padding: 12px 16px;
            font-size: .9rem;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .alert-success {
            background: rgba(52,211,153,.1);
            color: #34d399;
            border: 1px solid rgba(52,211,153,.4);
        }
        .alert-error {
            background: var(--clr-error-bg);
            color: var(--clr-error);
            border: 1px solid var(--clr-error);
        }
        .alert ul { margin: 6px 0 0 18px; }
        .alert ul li { margin-top: 2px; }

        /* ── Buttons (dark) ───────────────────────────────────────────────── */
        .btn-row {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 20px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: 10px 20px;
            border: 1px solid transparent;
            border-radius: 8px;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .2s, border-color .2s, color .2s;
        }
        .btn-submit {
            background: var(--clr-primary);
            color: #0f172a;
            border-color: var(--clr-primary);
        }
        .btn-submit:hover:not(:disabled) {
            background: var(--clr-primary-h);
            border-color: var(--clr-primary-h);
        }
        .btn-submit:disabled {
            background: rgba(56,189,248,.3);
            color: rgba(15,23,42,.6);
            border-color: rgba(56,189,248,.3);
            cursor: not-allowed;
        }
        .btn-cancel {
            background: transparent;
            color: var(--clr-muted);
            border: 1px solid var(--clr-border);
        }
        .btn-cancel:hover {
            border-color: var(--clr-primary);
            color: var(--clr-primary);
        }

        /* ── Transaction notice ───────────────────────────────────────────── */
        .tx-note {
            font-size: .78rem;
            color: var(--clr-muted);
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body class="dashboard-body">

<!-- ── Top Nav (matches dashboard) ─────────────────────────────────────── -->
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
        <a href="dashboard.html"  class="nav-link">Dashboard</a>
        <a href="inventory.php"   class="nav-link active">Inventory</a>
        <a href="experiments.php" class="nav-link">Experiments</a>
        <a href="documents.php"   class="nav-link">Documents</a>
    </nav>

    <div class="topnav-user">
        <span class="user-greeting"><?= $username ?></span>
        <a href="logout.php" class="btn-logout" style="text-decoration:none;">Sign Out</a>
    </div>
</header>

<main class="page-wrapper-form">

    <div class="breadcrumb">
        <a href="inventory.php">← Back to Inventory</a>
    </div>

    <h1 class="page-title">Log Chemical Usage</h1>

    <?php if ($success_msg): ?>
        <div class="alert alert-success">
            <span>✔</span>
            <div><?= $success_msg ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <span>⚠</span>
            <div>
                <?php if (count($errors) === 1): ?>
                    <?= htmlspecialchars($errors[0]) ?>
                <?php else: ?>
                    Please fix the following:
                    <ul>
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($item): ?>

    <!-- ── Chemical Info ──────────────────────────────────────────────────── -->
    <div class="card">
        <h2>📦 Item Details</h2>
        <div class="info-grid">
            <div class="info-cell">
                <span class="label">Chemical</span>
                <span class="value"><?= htmlspecialchars($item['chemical_name']) ?></span>
            </div>
            <div class="info-cell">
                <span class="label">Lot Number</span>
                <span class="value" style="font-family:monospace;">
                    <?= htmlspecialchars($item['lot_number']) ?>
                </span>
            </div>
            <div class="info-cell">
                <span class="label">Hazard Class</span>
                <span class="value">
                    <?php
                        $hz = strtolower($item['hazard_class']);
                        if (str_contains($hz, 'flamm'))     $hzC = 'badge-flammable';
                        elseif (str_contains($hz, 'corr'))  $hzC = 'badge-corrosive';
                        elseif (str_contains($hz, 'irrit')) $hzC = 'badge-irritant';
                        elseif (str_contains($hz, 'low'))   $hzC = 'badge-low';
                        else                                $hzC = 'badge-default';
                    ?>
                    <span class="badge <?= htmlspecialchars($hzC) ?>">
                        <?= htmlspecialchars($item['hazard_class']) ?>
                    </span>
                </span>
            </div>
            <div class="info-cell">
                <span class="label">Location</span>
                <span class="value"><?= htmlspecialchars($item['location_name']) ?></span>
            </div>
            <div class="info-cell">
                <span class="label">Expiration Date</span>
                <span class="value"><?= htmlspecialchars($item['expiration_date']) ?></span>
            </div>
            <div class="info-cell">
                <span class="label">Current Stock</span>
                <span class="value">
                    <span class="qty-display" id="display-qty">
                        <?= htmlspecialchars(number_format((float)$item['quantity'], 2)) ?>
                    </span>
                    <span style="color:#64748b;font-size:.85rem;">
                        <?= htmlspecialchars($item['unit']) ?>
                    </span>
                </span>
                <div class="qty-bar-wrap">
                    <div class="qty-bar" id="qty-bar" style="width:<?= min(100, (float)$item['quantity'] / 50) ?>%"></div>
                </div>
            </div>
            <div class="info-cell">
                <span class="label">Status</span>
                <span class="value">
                    <?php
                        $stClass = match(strtolower($item['status'])) {
                            'in stock'     => 'status-in',
                            'low stock'    => 'status-low',
                            'out of stock' => 'status-out',
                            default        => 'badge-default',
                        };
                    ?>
                    <span class="badge <?= htmlspecialchars($stClass) ?>">
                        <?= htmlspecialchars($item['status']) ?>
                    </span>
                </span>
            </div>
        </div>
    </div>

    <!-- ── Usage Form ─────────────────────────────────────────────────────── -->
    <?php if (strtolower($item['status']) === 'out of stock'): ?>
        <div class="alert alert-error">
            <span>⛔</span>
            <div>This item is out of stock and cannot be used.</div>
        </div>
        <a href="inventory.php" class="btn btn-cancel">← Back to Inventory</a>
    <?php else: ?>

    <div class="card">
        <h2>📝 Record Usage</h2>

        <form method="POST" action="add_usage.php?item_id=<?= (int)$item_id ?>" novalidate>

            <!-- Experiment -->
            <div class="form-group">
                <label for="experiment_id">
                    Experiment<span class="required">*</span>
                </label>
                <select
                    name="experiment_id"
                    id="experiment_id"
                    required
                    class="<?= (!empty($errors) && empty($_POST['experiment_id'])) ? 'input-error' : '' ?>"
                >
                    <option value="">— Select an experiment —</option>
                    <?php foreach ($experiments as $exp): ?>
                        <option
                            value="<?= (int)$exp['experiment_id'] ?>"
                            <?= (isset($_POST['experiment_id']) && (int)$_POST['experiment_id'] === (int)$exp['experiment_id']) ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($exp['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Amount Used -->
            <div class="form-group">
                <label for="amount_used">
                    Amount Used (<?= htmlspecialchars($item['unit']) ?>)<span class="required">*</span>
                </label>
                <input
                    type="number"
                    name="amount_used"
                    id="amount_used"
                    min="0.01"
                    max="<?= htmlspecialchars((string)$item['quantity']) ?>"
                    step="0.01"
                    placeholder="e.g. 250.00"
                    value="<?= htmlspecialchars($_POST['amount_used'] ?? '') ?>"
                    required
                    class="<?= (!empty($errors) && isset($_POST['amount_used'])) ? 'input-error' : '' ?>"
                    oninput="previewRemaining(this.value)"
                >
                <span class="hint" id="remaining-hint">
                    Max available: <?= htmlspecialchars(number_format((float)$item['quantity'], 2)) ?>
                    <?= htmlspecialchars($item['unit']) ?>
                </span>
            </div>

            <!-- Notes -->
            <div class="form-group">
                <label for="notes">Notes <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                <textarea
                    name="notes"
                    id="notes"
                    placeholder="Describe how this chemical was used…"
                    maxlength="500"
                ><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                <span class="hint">Max 500 characters.</span>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn btn-submit" id="submit-btn">
                    ✔ Log Usage
                </button>
                <a href="inventory.php" class="btn btn-cancel">Cancel</a>
            </div>

            <p class="tx-note">
                🔒 This operation runs inside a database transaction — both the usage
                record and inventory update succeed together or neither is saved.
            </p>
        </form>
    </div>

    <?php endif; ?>
    <?php endif; // $item exists ?>

</main>

<footer class="dash-footer">
    LabTrack &copy; 2026 &mdash; University of Rhode Island
</footer>

<script>
// ── Client-side "remaining" preview ──────────────────────────────────────────
const maxQty   = <?= json_encode((float)$item['quantity'] ?? 0) ?>;
const unit     = <?= json_encode($item['unit'] ?? '') ?>;
const hint     = document.getElementById('remaining-hint');
const submitBtn = document.getElementById('submit-btn');

function previewRemaining(val) {
    const used = parseFloat(val);
    if (isNaN(used) || used <= 0) {
        hint.textContent = `Max available: ${maxQty.toFixed(2)} ${unit}`;
        hint.style.color = '';
        submitBtn.disabled = false;
        return;
    }
    const remaining = maxQty - used;
    if (remaining < 0) {
        hint.textContent = `⚠ Exceeds available stock by ${Math.abs(remaining).toFixed(2)} ${unit}`;
        hint.style.color = '#f87171';
        submitBtn.disabled = true;
    } else {
        hint.textContent = `Remaining after use: ${remaining.toFixed(2)} ${unit}`;
        hint.style.color = remaining < 100 ? '#fbbf24' : '#34d399';
        submitBtn.disabled = false;
    }
}
</script>

</body>
</html>
