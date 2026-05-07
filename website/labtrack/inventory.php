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
// Handle Add Chemical form submission
$addErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_chemical') {
    $chem_name       = trim($_POST['chem_name']       ?? '');
    $cas_number      = trim($_POST['cas_number']      ?? '');
    $hazard_class    = trim($_POST['hazard_class']    ?? '');
    $default_unit    = trim($_POST['default_unit']    ?? '');
    $supplier_id     = (int)($_POST['supplier_id']   ?? 0);
    $lot_number      = trim($_POST['lot_number']      ?? '');
    $received_date   = trim($_POST['received_date']   ?? '');
    $expiration_date = trim($_POST['expiration_date'] ?? '');
    $concentration   = (($_POST['concentration'] ?? '') !== '') ? (float)$_POST['concentration'] : null;
    $unit_cost       = (float)($_POST['unit_cost']    ?? 0);
    $location_id     = (int)($_POST['location_id']   ?? 0);
    $quantity        = (float)($_POST['quantity']     ?? 0);
    $unit            = trim($_POST['unit']            ?? '');
    $inv_status      = trim($_POST['inv_status']      ?? 'In Stock');

    if (!$chem_name || !$cas_number || !$hazard_class || !$default_unit ||
        !$supplier_id || !$lot_number || !$received_date || !$expiration_date ||
        !$unit_cost || !$location_id || !$quantity || !$unit) {
        $addErr = 'Please fill in all required fields.';
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO Chemical (chemical_name, cas_number, hazard_class, default_unit) VALUES (?,?,?,?)")
                ->execute([$chem_name, $cas_number, $hazard_class, $default_unit]);
            $chemical_id = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO Batch (supplier_id, chemical_id, lot_number, received_date, expiration_date, concentration, unit_cost) VALUES (?,?,?,?,?,?,?)")
                ->execute([$supplier_id, $chemical_id, $lot_number, $received_date, $expiration_date, $concentration, $unit_cost]);
            $batch_id = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO Inventory_item (batch_id, location_id, quantity, unit, status) VALUES (?,?,?,?,?)")
                ->execute([$batch_id, $location_id, $quantity, $unit, $inv_status]);
            $pdo->commit();
            header('Location: inventory.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Add chemical error: ' . $e->getMessage());
            $addErr = 'Failed to add chemical. Please try again.';
        }
    }
}

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

// Fetch suppliers and storage locations for Add Chemical modal
try {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM Supplier ORDER BY supplier_name ASC")->fetchAll();
    $locations = $pdo->query("SELECT location_id, location_name FROM Storage_location ORDER BY location_name ASC")->fetchAll();
} catch (PDOException $e) {
    $suppliers = [];
    $locations = [];
}

$username = htmlspecialchars($_SESSION['username'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory — LabTrack</title>
    <link rel="stylesheet" href="login.css" />
    <link rel="stylesheet" href="dashboard.css" />
    <style>
        /* ── Page heading override ────────────────────────────────────────── */
        .page-wrapper-inv {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px 28px 48px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ── Filter bar (dark) ────────────────────────────────────────────── */
        .filter-bar {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius);
            padding: 16px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .filter-bar label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: .78rem;
            font-weight: 600;
            color: var(--clr-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .filter-bar input[type="text"] {
            padding: 8px 12px;
            background: var(--clr-bg);
            border: 1px solid var(--clr-border);
            border-radius: 8px;
            color: var(--clr-text);
            font-size: .9rem;
            width: 240px;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .filter-bar input[type="text"]:focus {
            border-color: var(--clr-primary);
            box-shadow: 0 0 0 3px rgba(56,189,248,.15);
        }
        .filter-bar input::placeholder { color: #475569; }
        .checkbox-label {
            flex-direction: row !important;
            align-items: center;
            gap: .4rem !important;
            cursor: pointer;
            text-transform: none !important;
            letter-spacing: 0 !important;
            font-size: .85rem !important;
            color: var(--clr-text) !important;
        }
        .checkbox-label input {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--clr-primary);
        }

        /* ── Buttons (dark theme) ─────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: 8px 18px;
            border: 1px solid transparent;
            border-radius: 8px;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s, border-color .2s, color .2s, filter .2s;
            text-decoration: none;
        }
        .btn-primary {
            background: var(--clr-primary);
            color: #0f172a;
            border-color: var(--clr-primary);
        }
        .btn-primary:hover {
            background: var(--clr-primary-h);
            border-color: var(--clr-primary-h);
        }
        .btn-outline {
            background: transparent;
            color: var(--clr-muted);
            border: 1px solid var(--clr-border);
        }
        .btn-outline:hover {
            border-color: var(--clr-primary);
            color: var(--clr-primary);
        }
        .btn-sm { padding: 6px 12px; font-size: .78rem; }
        .btn-log {
            background: rgba(56,189,248,.12);
            color: var(--clr-primary);
            border: 1px solid rgba(56,189,248,.3);
        }
        .btn-log:hover {
            background: rgba(56,189,248,.2);
            border-color: var(--clr-primary);
            color: var(--clr-primary);
        }

        /* ── Stat chips (dark) ────────────────────────────────────────────── */
        .stats-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .chip {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: 10px;
            padding: 10px 16px;
            font-size: .78rem;
            color: var(--clr-muted);
        }
        .chip strong {
            color: var(--clr-text);
            font-size: 1.2rem;
            display: block;
            line-height: 1.2;
            margin-bottom: 2px;
        }

        /* ── Table card (dark) ────────────────────────────────────────────── */
        .table-card {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .table-card table {
            width: 100%;
            border-collapse: collapse;
            font-size: .875rem;
        }
        .table-card thead th {
            background: rgba(255,255,255,.03);
            padding: 12px 14px;
            text-align: left;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--clr-muted);
            border-bottom: 1px solid var(--clr-border);
            white-space: nowrap;
        }
        .table-card tbody tr {
            border-bottom: 1px solid rgba(51,65,85,.5);
            transition: background .15s;
        }
        .table-card tbody tr:last-child { border-bottom: none; }
        .table-card tbody tr:hover { background: rgba(255,255,255,.03); }
        .table-card tbody td {
            padding: 12px 14px;
            vertical-align: middle;
            color: var(--clr-text);
        }

        /* ── Row status colours (dark variants) ───────────────────────────── */
        .row-expired       { background: rgba(248,113,113,.06); }
        .row-expired:hover { background: rgba(248,113,113,.12) !important; }
        .row-expiring-soon       { background: rgba(251,191,36,.06); }
        .row-expiring-soon:hover { background: rgba(251,191,36,.12) !important; }

        /* ── Badges (dark variants — keep existing class names) ───────────── */
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

        .status-badge { background: rgba(52,211,153,.15);  color: #34d399; }
        .status-low   { background: rgba(251,191,36,.15);  color: #fbbf24; }
        .status-out   { background: rgba(248,113,113,.15); color: #f87171; }

        .exp-warning { color: #fbbf24; font-weight: 600; }
        .exp-danger  { color: #f87171; font-weight: 700; }

        /* ── Empty / error state ──────────────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--clr-muted);
        }
        .empty-state strong { color: var(--clr-text); font-size: 1rem; }
        .empty-state p { margin-top: 6px; font-size: .9rem; }

        .alert-error {
            background: var(--clr-error-bg);
            color: var(--clr-error);
            border: 1px solid var(--clr-error);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: .9rem;
        }

        /* ── Legend (dark) ────────────────────────────────────────────────── */
        .legend {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            font-size: .8rem;
            color: var(--clr-muted);
            padding-top: 4px;
        }
        .legend-item { display: flex; align-items: center; gap: .4rem; }
        .legend-swatch {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            border: 1px solid var(--clr-border);
        }
        .swatch-expired { background: rgba(248,113,113,.4); }
        .swatch-soon    { background: rgba(251,191,36,.4); }

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
            text-decoration: none;
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
            max-width: 640px;
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
        .form-section-lbl {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--clr-muted);
            margin: 14px 0 9px;
        }
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
        <a href="dashboard.php"  class="nav-link">Dashboard</a>
        <a href="inventory.php"   class="nav-link active">Inventory</a>
        <a href="experiments.php" class="nav-link">Experiments</a>
        <a href="documents.php"   class="nav-link">Documents</a>
        <a href="chem_usage.php"  class="nav-link">Chemical Usage</a>
    </nav>

    <div class="topnav-user">
        <span class="user-greeting"><?= $username ?></span>
        <a href="logout.php" class="btn-logout" style="text-decoration:none;">Sign Out</a>
    </div>
</header>

<main class="dash-main page-wrapper-inv">

    <!-- Hero -->
    <section class="dash-hero">
        <div class="dash-hero-text">
            <h2>Chemical Inventory</h2>
            <p>Browse every batch of every chemical — search, filter and log usage in one place.</p>
        </div>
        <div class="dash-hero-badge">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 3h18v4H3z"/><path d="M3 7v14h18V7"/>
                <path d="M9 11h6M9 15h4"/>
            </svg>
        </div>
    </section>

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
    <div style="display:flex;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="btn-add" onclick="document.getElementById('modalAddChem').classList.add('open')">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Chemical
        </button>
    </div>

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

                    if (strpos($hz, 'flamm') !== false) {
                        $hzClass = 'badge-flammable';
                    }
                    elseif (strpos($hz, 'corr') !== false) {
                        $hzClass = 'badge-corrosive';
                    }
                    elseif (strpos($hz, 'irrit') !== false) {
                        $hzClass = 'badge-irritant';
                    }
                    elseif (strpos($hz, 'low') !== false) {
                        $hzClass = 'badge-low';
                    }
                    else {
                        $hzClass = 'badge-default';
                    }                          $hzClass = 'badge-default';

                    // AUTO STOCK STATUS (based on quantity)
                    if ($row['quantity'] <= 0) {
                        $statusLabel = 'Out of Stock';
                        $statusClass = 'status-out';
                    } elseif ($row['quantity'] <= 500) {
                        $statusLabel = 'Low Stock';
                        $statusClass = 'status-low';
                    } else {
                        $statusLabel = 'In Stock';
                        $statusClass = 'status-badge';
                    }
                    // If expired, override everything
                    if ($daysLeft < 0) {
                        $statusLabel = 'Expired';
                        $statusClass = 'status-out';
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

<!-- Add Chemical modal -->
<div class="modal-overlay" id="modalAddChem">
    <div class="modal-box">
        <button class="modal-close-btn" onclick="document.getElementById('modalAddChem').classList.remove('open')" aria-label="Close">&times;</button>
        <div class="modal-title">Add New Chemical</div>

        <?php if ($addErr): ?>
            <div class="modal-alert-err"><?= htmlspecialchars($addErr) ?></div>
        <?php endif; ?>

        <form method="POST" action="inventory.php">
            <input type="hidden" name="action" value="add_chemical">

            <p class="form-section-lbl">Chemical Information</p>
            <div class="form-grid-2">
                <div class="f-field">
                    <label>Chemical Name *</label>
                    <input type="text" name="chem_name" required maxlength="100"
                           placeholder="e.g. Ethanol"
                           value="<?= htmlspecialchars($_POST['chem_name'] ?? '') ?>">
                </div>
                <div class="f-field">
                    <label>CAS Number *</label>
                    <input type="text" name="cas_number" required maxlength="50"
                           placeholder="e.g. 64-17-5"
                           value="<?= htmlspecialchars($_POST['cas_number'] ?? '') ?>">
                </div>
                <div class="f-field">
                    <label>Hazard Class *</label>
                    <select name="hazard_class" required>
                        <option value="">Select...</option>
                        <?php foreach (['Flammable','Corrosive','Irritant','Low Hazard','Toxic','Oxidizer'] as $hc): ?>
                            <option value="<?= htmlspecialchars($hc) ?>"
                                <?= (($_POST['hazard_class'] ?? '') === $hc ? 'selected' : '') ?>>
                                <?= htmlspecialchars($hc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-field">
                    <label>Default Unit *</label>
                    <input type="text" name="default_unit" required maxlength="20"
                           placeholder="e.g. mL, g"
                           value="<?= htmlspecialchars($_POST['default_unit'] ?? '') ?>">
                </div>
            </div>

            <p class="form-section-lbl">Batch Information</p>
            <div class="form-grid-2">
                <div class="f-field">
                    <label>Supplier *</label>
                    <select name="supplier_id" required>
                        <option value="">Select supplier...</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= (int)$sup['supplier_id'] ?>"
                                <?= ((int)($_POST['supplier_id'] ?? 0) === (int)$sup['supplier_id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars($sup['supplier_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-field">
                    <label>Lot Number *</label>
                    <input type="text" name="lot_number" required maxlength="50"
                           placeholder="e.g. ETOH-2401"
                           value="<?= htmlspecialchars($_POST['lot_number'] ?? '') ?>">
                </div>
                <div class="f-field">
                    <label>Received Date *</label>
                    <input type="date" name="received_date" required
                           value="<?= htmlspecialchars($_POST['received_date'] ?? '') ?>">
                </div>
                <div class="f-field">
                    <label>Expiration Date *</label>
                    <input type="date" name="expiration_date" required
                           value="<?= htmlspecialchars($_POST['expiration_date'] ?? '') ?>">
                </div>
                <div class="f-field">
                    <label>Concentration (optional)</label>
                    <input type="number" name="concentration" step="0.01" min="0"
                           placeholder="e.g. 0.50"
                           value="<?= htmlspecialchars($_POST['concentration'] ?? '') ?>">
                </div>
                <div class="f-field">
                    <label>Unit Cost ($) *</label>
                    <input type="number" name="unit_cost" step="0.01" min="0" required
                           placeholder="e.g. 45.50"
                           value="<?= htmlspecialchars($_POST['unit_cost'] ?? '') ?>">
                </div>
            </div>

            <p class="form-section-lbl">Inventory Item</p>
            <div class="form-grid-2">
                <div class="f-field">
                    <label>Storage Location *</label>
                    <select name="location_id" required>
                        <option value="">Select location...</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int)$loc['location_id'] ?>"
                                <?= ((int)($_POST['location_id'] ?? 0) === (int)$loc['location_id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars($loc['location_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-field">
                    <label>Quantity *</label>
                    <input type="number" name="quantity" step="0.01" min="0" required
                           placeholder="e.g. 500"
                           value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>">
                </div>
                <div class="f-field">
                    <label>Unit *</label>
                    <input type="text" name="unit" required maxlength="20"
                           placeholder="e.g. mL, g"
                           value="<?= htmlspecialchars($_POST['unit'] ?? '') ?>">
                </div>
                <div class="f-field">
                    <label>Status *</label>
                    <select name="inv_status">
                        <?php foreach (['In Stock','Low Stock','Out of Stock'] as $st): ?>
                            <option value="<?= htmlspecialchars($st) ?>"
                                <?= (($_POST['inv_status'] ?? 'In Stock') === $st ? 'selected' : '') ?>>
                                <?= htmlspecialchars($st) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="modal-footer-row">
                <button type="button" class="modal-cancel-btn" onclick="document.getElementById('modalAddChem').classList.remove('open')">Cancel</button>
                <button type="submit" class="modal-save-btn">Save Chemical</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('modalAddChem').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
<?php if ($addErr): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('modalAddChem').classList.add('open');
});
<?php endif; ?>
</script>

</main>

<footer class="dash-footer">
    LabTrack &copy; 2026 &mdash; University of Rhode Island
</footer>

</body>
</html>
