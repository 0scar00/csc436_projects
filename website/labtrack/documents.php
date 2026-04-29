<?php
// documents.php — LabTrack Documents Browser
// Shows every row from the Document table, joined to Lab + User + linked
// Chemical / Experiment for context. Dark-navy theme to match dashboard.

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/database-connection.php';

// ── Authentication guard ──────────────────────────────────────────────────────
if (!$logged_in) {
    header('Location: login.php');
    exit;
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$search       = trim($_GET['search']    ?? '');
$type_filter  = trim($_GET['doc_type']  ?? '');

// ── Query ─────────────────────────────────────────────────────────────────────
$sql = "
    SELECT
        d.document_id,
        d.doc_type,
        d.file_name,
        d.file_url,
        d.uploaded_at,
        d.notes,
        l.lab_name,
        u.first_name,
        u.last_name,
        c.chemical_name,
        e.title AS experiment_title
    FROM   Document d
    LEFT JOIN Lab        l ON l.lab_id        = d.lab_id
    LEFT JOIN User       u ON u.user_id       = d.uploaded_by_user_id
    LEFT JOIN Chemical   c ON c.chemical_id   = d.chemical_id
    LEFT JOIN Experiment e ON e.experiment_id = d.experiment_id
    WHERE 1=1
";

$params = [];
if ($search !== '') {
    $sql      .= " AND d.file_name LIKE ?";
    $params[]  = '%' . $search . '%';
}
if ($type_filter !== '') {
    $sql      .= " AND d.doc_type = ?";
    $params[]  = $type_filter;
}
$sql .= " ORDER BY d.uploaded_at DESC";

// ── Execute ───────────────────────────────────────────────────────────────────
$documents = [];
$types     = [];
$error     = '';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $documents = $stmt->fetchAll();

    $typeStmt = $pdo->query("SELECT DISTINCT doc_type FROM Document ORDER BY doc_type ASC");
    $types    = $typeStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('LabTrack documents error: ' . $e->getMessage());
    $error = 'Could not load documents. Please try again.';
}

$total    = count($documents);
$username = htmlspecialchars($_SESSION['username'] ?? 'User');

// Helper: badge class per doc_type
function docTypeBadge(string $t): string {
    $s = strtolower($t);
    if ($s === 'sds')        return 'badge-red';
    if ($s === 'protocol')   return 'badge-cyan';
    if ($s === 'instruction')return 'badge-amber';
    if ($s === 'report')     return 'badge-green';
    return 'badge-gray';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>LabTrack — Documents</title>
    <link rel="stylesheet" href="login.css" />
    <link rel="stylesheet" href="dashboard.css" />
    <style>
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

        .badge-cyan { background: rgba(56,189,248,.15); color: #38bdf8; }

        .doc-file {
            font-weight: 600;
            color: var(--clr-text);
            font-family: monospace;
        }
        .doc-link {
            color: var(--clr-primary);
            text-decoration: none;
            font-size: 0.82rem;
        }
        .doc-link:hover { text-decoration: underline; }
        .doc-notes {
            font-size: 0.78rem;
            color: var(--clr-muted);
            margin-top: 4px;
            max-width: 320px;
        }
        .meta-cell {
            color: var(--clr-text);
            font-size: 0.875rem;
        }
        .meta-cell .secondary {
            display: block;
            color: var(--clr-muted);
            font-size: 0.78rem;
            margin-top: 2px;
        }
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
        <a href="inventory.php"   class="nav-link">Inventory</a>
        <a href="experiments.php" class="nav-link">Experiments</a>
        <a href="documents.php"   class="nav-link active">Documents</a>
        <a href="chem_usage.php"  class="nav-link">Chemical Usage</a>
    </nav>

    <div class="topnav-user">
        <span class="user-greeting"><?= $username ?></span>
        <a href="logout.php" class="btn-logout" style="text-decoration:none;">Sign Out</a>
    </div>
</header>

<main class="dash-main">

    <section class="dash-hero">
        <div class="dash-hero-text">
            <h2>Documents</h2>
            <p>Lab protocols, SDS sheets, and instructions linked to chemicals and experiments.</p>
        </div>
        <div class="dash-hero-badge">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
        </div>
    </section>

    <?php if ($error): ?>
        <div class="alert-error-dark"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="GET" action="documents.php" class="exp-filter-bar">
        <label>
            Search Filename
            <input type="text"
                   name="search"
                   placeholder="e.g. ethanol_sds.pdf"
                   value="<?= htmlspecialchars($search) ?>"
                   maxlength="150">
        </label>

        <label>
            Document Type
            <select name="doc_type">
                <option value="">All types</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>"
                        <?= $type_filter === $t ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <button type="submit" class="btn-apply">Apply</button>

        <?php if ($search !== '' || $type_filter !== ''): ?>
            <a href="documents.php" class="btn-clear">Clear</a>
        <?php endif; ?>
    </form>

    <div class="dash-panel">
        <div class="panel-header">
            <h3>All Documents</h3>
            <span class="panel-link" style="cursor:default;"><?= $total ?> result<?= $total === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($total === 0): ?>
            <div class="empty-panel">
                <h4>No documents found.</h4>
                <p>
                    <?php if ($search !== '' || $type_filter !== ''): ?>
                        Try clearing your filters.
                    <?php else: ?>
                        No documents have been uploaded yet.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Type</th>
                        <th>Linked To</th>
                        <th>Lab</th>
                        <th>Uploaded By</th>
                        <th>Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $row): ?>
                    <tr>
                        <td>
                            <div class="doc-file"><?= htmlspecialchars($row['file_name']) ?></div>
                            <?php if (!empty($row['file_url'])): ?>
                                <a class="doc-link"
                                   href="<?= htmlspecialchars($row['file_url']) ?>"
                                   target="_blank" rel="noopener noreferrer">Open ↗</a>
                            <?php endif; ?>
                            <?php if (!empty($row['notes'])): ?>
                                <div class="doc-notes"><?= htmlspecialchars($row['notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= docTypeBadge($row['doc_type'] ?? '') ?>">
                                <?= htmlspecialchars($row['doc_type'] ?? 'Unknown') ?>
                            </span>
                        </td>
                        <td class="meta-cell">
                            <?php if (!empty($row['chemical_name'])): ?>
                                <?= htmlspecialchars($row['chemical_name']) ?>
                                <span class="secondary">Chemical</span>
                            <?php elseif (!empty($row['experiment_title'])): ?>
                                <?= htmlspecialchars($row['experiment_title']) ?>
                                <span class="secondary">Experiment</span>
                            <?php else: ?>
                                <span style="color:var(--clr-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="meta-cell">
                            <?= htmlspecialchars($row['lab_name'] ?? '—') ?>
                        </td>
                        <td class="meta-cell">
                            <?php if (!empty($row['first_name']) || !empty($row['last_name'])): ?>
                                <?= htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?>
                            <?php else: ?>
                                <span style="color:var(--clr-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="meta-cell">
                            <?php
                                $up = $row['uploaded_at'] ?? '';
                                if ($up) {
                                    try {
                                        $dt = new DateTimeImmutable($up);
                                        echo htmlspecialchars($dt->format('M j, Y'));
                                        echo '<span class="secondary">' . htmlspecialchars($dt->format('g:i a')) . '</span>';
                                    } catch (Exception $e) {
                                        echo htmlspecialchars($up);
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
