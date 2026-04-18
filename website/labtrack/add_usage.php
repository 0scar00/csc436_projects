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
    <style>
        /* ── Reset & base ─────────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f4f8;
            color: #1e293b;
            min-height: 100vh;
        }

        /* ── Nav ──────────────────────────────────────────────────────────── */
        nav {
            background: #1e40af;
            color: #fff;
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            height: 56px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 6px rgba(0,0,0,.25);
        }
        nav .brand  { font-weight: 700; font-size: 1.2rem; margin-right: auto; }
        nav a {
            color: rgba(255,255,255,.85);
            text-decoration: none;
            font-size: .9rem;
            padding: .25rem .5rem;
            border-radius: 4px;
            transition: background .15s;
        }
        nav a:hover { background: rgba(255,255,255,.15); color: #fff; }

        /* ── Page layout ──────────────────────────────────────────────────── */
        .page-wrapper {
            max-width: 760px;
            margin: 2rem auto;
            padding: 0 1.25rem;
        }

        .breadcrumb {
            font-size: .85rem;
            color: #64748b;
            margin-bottom: 1.25rem;
        }
        .breadcrumb a { color: #1e40af; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }

        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
        }

        /* ── Cards ────────────────────────────────────────────────────────── */
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            margin-bottom: 1.25rem;
        }
        .card h2 {
            font-size: 1rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 1rem;
            padding-bottom: .5rem;
            border-bottom: 1px solid #f1f5f9;
        }

        /* ── Item info grid ───────────────────────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: .75rem 1.5rem;
        }
        .info-cell { display: flex; flex-direction: column; gap: .15rem; }
        .info-cell .label {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .07em;
            font-weight: 700;
            color: #94a3b8;
        }
        .info-cell .value {
            font-size: .95rem;
            color: #1e293b;
            font-weight: 500;
        }
        .qty-display {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e40af;
        }

        /* ── Badges ───────────────────────────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: .2rem .6rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 600;
        }
        .badge-flammable { background: #fef9c3; color: #854d0e; }
        .badge-corrosive { background: #fee2e2; color: #991b1b; }
        .badge-irritant  { background: #e0f2fe; color: #075985; }
        .badge-low       { background: #dcfce7; color: #166534; }
        .badge-default   { background: #f1f5f9; color: #475569; }

        .status-in    { background: #dcfce7; color: #166534; }
        .status-low   { background: #fef9c3; color: #854d0e; }
        .status-out   { background: #fee2e2; color: #991b1b; }

        /* ── Form ─────────────────────────────────────────────────────────── */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: .35rem;
            margin-bottom: 1rem;
        }
        .form-group:last-of-type { margin-bottom: 0; }

        label {
            font-size: .85rem;
            font-weight: 600;
            color: #475569;
        }
        label .required { color: #dc2626; margin-left: .2rem; }

        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: .55rem .85rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: .95rem;
            font-family: inherit;
            transition: border-color .15s, box-shadow .15s;
            background: #fff;
            color: #1e293b;
        }
        input[type="number"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.2);
        }
        input.input-error,
        select.input-error,
        textarea.input-error {
            border-color: #f87171;
        }
        textarea { resize: vertical; min-height: 80px; }

        .hint {
            font-size: .8rem;
            color: #94a3b8;
        }

        /* ── Progress bar ─────────────────────────────────────────────────── */
        .qty-bar-wrap {
            background: #f1f5f9;
            border-radius: 999px;
            height: 8px;
            overflow: hidden;
            margin-top: .5rem;
        }
        .qty-bar {
            height: 100%;
            border-radius: 999px;
            background: #22c55e;
            transition: width .4s ease;
        }

        /* ── Alerts ───────────────────────────────────────────────────────── */
        .alert {
            border-radius: 8px;
            padding: .9rem 1.1rem;
            margin-bottom: 1.25rem;
            font-size: .9rem;
            display: flex;
            gap: .5rem;
            align-items: flex-start;
        }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert ul { margin: .4rem 0 0 1.1rem; }
        .alert ul li { margin-top: .2rem; }

        /* ── Buttons ──────────────────────────────────────────────────────── */
        .btn-row {
            display: flex;
            gap: .75rem;
            align-items: center;
            margin-top: 1.25rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .55rem 1.2rem;
            border: none;
            border-radius: 6px;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: filter .15s;
        }
        .btn:hover { filter: brightness(1.1); }
        .btn-submit  { background: #1e40af; color: #fff; }
        .btn-cancel  { background: #fff; color: #475569; border: 1px solid #cbd5e1; }

        .btn-submit:disabled {
            background: #93c5fd;
            cursor: not-allowed;
            filter: none;
        }

        /* ── Transaction notice ───────────────────────────────────────────── */
        .tx-note {
            font-size: .78rem;
            color: #64748b;
            margin-top: .5rem;
            display: flex;
            align-items: center;
            gap: .35rem;
        }
    </style>
</head>
<body>

<!-- ── Navigation ─────────────────────────────────────────────────────────── -->
<nav>
    <span class="brand">🧪 LabTrack</span>
    <a href="dashboard.html">Dashboard</a>
    <a href="inventory.php">Inventory</a>
    <a href="logout.php">Log out (<?= $username ?>)</a>
</nav>

<div class="page-wrapper">

    <div class="breadcrumb">
        <a href="inventory.php">← Back to Inventory</a>
    </div>

    <h1>Log Chemical Usage</h1>

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

</div>

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
        hint.style.color = '#dc2626';
        submitBtn.disabled = true;
    } else {
        hint.textContent = `Remaining after use: ${remaining.toFixed(2)} ${unit}`;
        hint.style.color = remaining < 100 ? '#b45309' : '#16a34a';
        submitBtn.disabled = false;
    }
}
</script>

</body>
</html>
