<?php
// inventory.php — LabTrack Inventory Browser
// Shows all inventory items with JOIN across Inventory_item → Batch → Chemical → Storage_location
// Supports: search by chemical name, filter for items expiring within 30 days

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/database-connection.php';
// ── Authentication guard ──────────────────────────────────────────────────────
if (!$logged_in) {
    header('Location: login.php');
    exit;
}

// ── Input sanitization ────────────────────────────────────────────────────────
$search        = trim($_GET['search']     ?? '');
$expiring_soon = isset($_GET['expiring_soon']) && $_GET['expiring_soon'] === '1';

// ── Build query ───────────────────────────────────────────────────────────────
// Real column names from schema:
//   Chemical:        chemical_name, default_unit
//   Batch:           lot_number, expiration_date
//   Inventory_item:  item_id, quantity, unit, status
//   Storage_location: location_name

$sql = "
    SELECT
        ii.item_id,
        c.chemical_name,
        c.hazard_class,
        b.lot_number,
        b.expiration_date,
        ii.quantity,
        ii.unit,
        ii.status,
        sl.location_name
    FROM Inventory_item   ii
    JOIN Batch            b   ON ii.batch_id   = b.batch_id
    JOIN Chemical         c   ON b.chemical_id = c.chemical_id
    JOIN Storage_location sl  ON ii.location_id = sl.location_id
    WHERE 1=1
";

$params = [];

if ($search !== '') {
    $sql    .= " AND c.chemical_name LIKE ?";
    $params[] = '%' . $search . '%';
}

if ($expiring_soon) {
    // Items whose expiration_date falls within the next 30 calendar days
    $sql    .= " AND b.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

$sql .= " ORDER BY b.expiration_date ASC";

// ── Execute ───────────────────────────────────────────────────────────────────
$items = [];
$error = '';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('LabTrack inventory error: ' . $e->getMessage());
    $error = 'Could not load inventory. Please try again.';
}

// ── Helper: highlight rows near expiry ───────────────────────────────────────
function expiryClass(string $expDate): string {
    $today = new DateTimeImmutable('today');
    $exp   = new DateTimeImmutable($expDate);
    $days  = (int) $today->diff($exp)->days * ($exp >= $today ? 1 : -1);

    if ($days < 0)   return 'row-expired';
    if ($days <= 30) return 'row-expiring-soon';
    return '';
}

$username = htmlspecialchars($_SESSION['username'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory — LabTrack</title>
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
        nav .brand {
            font-weight: 700;
            font-size: 1.2rem;
            letter-spacing: .03em;
            margin-right: auto;
        }
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
        .page-wrapper { max-width: 1100px; margin: 2rem auto; padding: 0 1.25rem; }

        h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1.25rem;
        }

        /* ── Filter bar ───────────────────────────────────────────────────── */
        .filter-bar {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: flex-end;
            margin-bottom: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .filter-bar label {
            display: flex;
            flex-direction: column;
            gap: .3rem;
            font-size: .85rem;
            font-weight: 600;
            color: #475569;
        }
        .filter-bar input[type="text"] {
            padding: .45rem .75rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: .95rem;
            width: 240px;
            transition: border-color .15s, box-shadow .15s;
        }
        .filter-bar input[type="text"]:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.2);
        }
        .checkbox-label {
            flex-direction: row !important;
            align-items: center;
            gap: .4rem !important;
            cursor: pointer;
        }
        .checkbox-label input { width: 16px; height: 16px; cursor: pointer; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .45rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            transition: filter .15s;
            text-decoration: none;
        }
        .btn:hover { filter: brightness(1.1); }
        .btn-primary  { background: #1e40af; color: #fff; }
        .btn-outline  { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
        .btn-sm       { padding: .3rem .75rem; font-size: .82rem; }
        .btn-log      { background: #0891b2; color: #fff; }

        /* ── Stats chips ──────────────────────────────────────────────────── */
        .stats-row {
            display: flex;
            gap: .75rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .chip {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: .5rem 1rem;
            font-size: .82rem;
            color: #64748b;
            box-shadow: 0 1px 2px rgba(0,0,0,.05);
        }
        .chip strong { color: #1e293b; font-size: 1.1rem; display: block; }

        /* ── Table ────────────────────────────────────────────────────────── */
        .table-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .9rem;
        }
        thead th {
            background: #f8fafc;
            padding: .75rem 1rem;
            text-align: left;
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .1s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f8fafc; }
        tbody td { padding: .7rem 1rem; vertical-align: middle; }

        /* ── Row status colours ───────────────────────────────────────────── */
        .row-expired      { background: #fff1f2; }
        .row-expired:hover{ background: #ffe4e6; }
        .row-expiring-soon      { background: #fffbeb; }
        .row-expiring-soon:hover{ background: #fef3c7; }

        /* ── Badges ───────────────────────────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: .15rem .55rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-flammable  { background: #fef9c3; color: #854d0e; }
        .badge-corrosive  { background: #fee2e2; color: #991b1b; }
        .badge-irritant   { background: #e0f2fe; color: #075985; }
        .badge-low        { background: #dcfce7; color: #166534; }
        .badge-default    { background: #f1f5f9; color: #475569; }

        .status-badge { background: #dcfce7; color: #166534; }
        .status-low   { background: #fef9c3; color: #854d0e; }
        .status-out   { background: #fee2e2; color: #991b1b; }

        .exp-warning { color: #b45309; font-weight: 600; }
        .exp-danger  { color: #dc2626; font-weight: 700; }

        /* ── Empty / error state ──────────────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #94a3b8;
        }
        .empty-state p { margin-top: .5rem; font-size: .9rem; }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: .75rem 1rem;
            margin-bottom: 1rem;
            font-size: .9rem;
        }

        /* ── Legend ───────────────────────────────────────────────────────── */
        .legend {
            display: flex;
            gap: 1.25rem;
            margin-top: 1rem;
            flex-wrap: wrap;
            font-size: .8rem;
            color: #64748b;
        }
        .legend-item { display: flex; align-items: center; gap: .4rem; }
        .legend-swatch {
            width: 12px; height: 12px;
            border-radius: 3px;
            border: 1px solid rgba(0,0,0,.1);
        }
        .swatch-expired { background: #fecdd3; }
        .swatch-soon    { background: #fde68a; }
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
    <h1>Chemical Inventory</h1>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ── Filter bar ─────────────────────────────────────────────────────── -->
    <form method="GET" action="inventory.php" class="filter-bar">
        <label>
            Search Chemical
            <input
                type="text"
                name="search"
                placeholder="e.g. Ethanol"
                value="<?= htmlspecialchars($search) ?>"
                maxlength="100"
            >
        </label>

        <label class="checkbox-label">
            <input
                type="checkbox"
                name="expiring_soon"
                value="1"
                <?= $expiring_soon ? 'checked' : '' ?>
            >
            Expiring within 30 days
        </label>

        <button type="submit" class="btn btn-primary">Search</button>

        <?php if ($search !== '' || $expiring_soon): ?>
            <a href="inventory.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>

    <!-- ── Stats chips ────────────────────────────────────────────────────── -->
    <?php
        $total    = count($items);
        $expCount = 0;
        $today    = new DateTimeImmutable('today');
        foreach ($items as $row) {
            $exp  = new DateTimeImmutable($row['expiration_date']);
            $days = (int) $today->diff($exp)->days * ($exp >= $today ? 1 : -1);
            if ($days >= 0 && $days <= 30) $expCount++;
        }
    ?>
    <div class="stats-row">
        <div class="chip"><strong><?= $total ?></strong>Items shown</div>
        <?php if ($expCount > 0): ?>
            <div class="chip" style="border-color:#fcd34d;">
                <strong style="color:#b45309;"><?= $expCount ?></strong>Expiring ≤ 30 days
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Main table ─────────────────────────────────────────────────────── -->
    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Chemical Name</th>
                    <th>Hazard Class</th>
                    <th>Lot Number</th>
                    <th>Quantity</th>
                    <th>Location</th>
                    <th>Expiration Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <strong>No inventory items found.</strong>
                            <?php if ($search !== '' || $expiring_soon): ?>
                                <p>Try clearing your filters.</p>
                            <?php else: ?>
                                <p>No chemicals have been added to inventory yet.</p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $i => $row):
                    $rowClass  = expiryClass($row['expiration_date']);
                    $exp       = new DateTimeImmutable($row['expiration_date']);
                    $daysLeft  = (int) $today->diff($exp)->days * ($exp >= $today ? 1 : -1);

                    // Expiration display
                    if ($daysLeft < 0) {
                        $expDisplay = '<span class="exp-danger">Expired (' . abs($daysLeft) . 'd ago)</span>';
                    } elseif ($daysLeft <= 30) {
                        $expDisplay = '<span class="exp-warning">'
                            . htmlspecialchars($row['expiration_date'])
                            . ' (' . $daysLeft . 'd)</span>';
                    } else {
                        $expDisplay = htmlspecialchars($row['expiration_date']);
                    }

                    // Hazard badge
                    $hz = strtolower($row['hazard_class']);
                    if (str_contains($hz, 'flamm'))    $hzClass = 'badge-flammable';
                    elseif (str_contains($hz, 'corr')) $hzClass = 'badge-corrosive';
                    elseif (str_contains($hz, 'irrit'))$hzClass = 'badge-irritant';
                    elseif (str_contains($hz, 'low'))  $hzClass = 'badge-low';
                    else                               $hzClass = 'badge-default';

                    // Status badge — override with "Expired" if past expiration date
                    $statusClass = match(strtolower($row['status'])) {
                        'in stock'     => 'status-badge',
                        'low stock'    => 'status-low',
                        'out of stock' => 'status-out',
                        default        => 'badge-default',
                    };
                    // If physically expired, override status display regardless of DB value
                    $statusLabel = $row['status'];
                    if ($daysLeft < 0) {
                        $statusClass  = 'status-out';
                        $statusLabel  = 'Expired';
                    }
                ?>
                <tr class="<?= htmlspecialchars($rowClass) ?>">
                    <td style="color:#94a3b8;font-size:.8rem;"><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($row['chemical_name']) ?></strong></td>
                    <td>
                        <span class="badge <?= htmlspecialchars($hzClass) ?>">
                            <?= htmlspecialchars($row['hazard_class']) ?>
                        </span>
                    </td>
                    <td style="font-family:monospace;font-size:.85rem;">
                        <?= htmlspecialchars($row['lot_number']) ?>
                    </td>
                    <td>
                        <?= htmlspecialchars(number_format((float)$row['quantity'], 2)) ?>
                        <span style="color:#64748b;font-size:.82rem;">
                            <?= htmlspecialchars($row['unit']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($row['location_name']) ?></td>
                    <td><?= $expDisplay ?></td>
                    <td>
                        <span class="badge <?= htmlspecialchars($statusClass) ?>">
                            <?= htmlspecialchars($statusLabel) ?>
                        </span>
                    </td>
                    <td>
                        <a
                            href="add_usage.php?item_id=<?= (int) $row['item_id'] ?>"
                            class="btn btn-sm btn-log"
                        >Log Usage</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Legend ─────────────────────────────────────────────────────────── -->
    <div class="legend">
        <div class="legend-item">
            <div class="legend-swatch swatch-expired"></div> Expired
        </div>
        <div class="legend-item">
            <div class="legend-swatch swatch-soon"></div> Expiring within 30 days
        </div>
    </div>
</div>

</body>
</html>
