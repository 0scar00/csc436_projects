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
// Handle Add Document form submission
$docAddErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_document') {
    $doc_mode    = $_POST['doc_mode']          ?? 'upload';
    $doc_lab_id  = (int)($_POST['doc_lab_id']  ?? 0);
    $doc_type    = trim($_POST['doc_type_val'] ?? '');
    $doc_notes   = trim($_POST['doc_notes']    ?? '');
    $doc_chem_id = (($_POST['doc_chemical_id']    ?? '') !== '') ? (int)$_POST['doc_chemical_id']    : null;
    $doc_exp_id  = (($_POST['doc_experiment_id']  ?? '') !== '') ? (int)$_POST['doc_experiment_id']  : null;
    $user_id     = (int)($_SESSION['user_id'] ?? 0);
    $doc_file_name = '';
    $doc_file_url  = '';

    if ($doc_mode === 'upload') {
        if (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
            $docAddErr = 'File upload failed. Please select a valid file.';
        } else {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $origName = basename($_FILES['doc_file']['name']);
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowed  = ['pdf','doc','docx','txt','xlsx','png','jpg','jpeg'];
            if (!in_array($ext, $allowed, true)) {
                $docAddErr = 'File type not allowed. Allowed: ' . implode(', ', $allowed);
            } else {
                $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
                $destPath = $uploadDir . $safeName;
                if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $destPath)) {
                    $doc_file_name = $origName;
                    $doc_file_url  = 'uploads/' . $safeName;
                } else {
                    $docAddErr = 'Could not save the uploaded file.';
                }
            }
        }
    } else {
        $doc_file_name = trim($_POST['doc_file_name'] ?? '');
        $doc_file_url  = trim($_POST['doc_file_url']  ?? '');
        if (!$doc_file_name || !$doc_file_url) {
            $docAddErr = 'File name and URL are required.';
        }
    }

    if (!$docAddErr) {
        if (!$doc_lab_id || !$doc_type || !$doc_file_name || !$user_id) {
            $docAddErr = 'Please fill in all required fields.';
        } else {
            try {
                $pdo->prepare("INSERT INTO Document (lab_id, uploaded_by_user_id, doc_type, file_name, file_url, uploaded_at, notes, chemical_id, experiment_id) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                    ->execute([$doc_lab_id, $user_id, $doc_type, $doc_file_name, $doc_file_url, $doc_notes ?: null, $doc_chem_id, $doc_exp_id]);
                header('Location: documents.php');
                exit;
            } catch (PDOException $e) {
                error_log('Add document error: ' . $e->getMessage());
                $docAddErr = 'Failed to save document. Please try again.';
            }
        }
    }
}

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

// Fetch dropdown data for Add Document modal
try {
    $docLabs        = $pdo->query("SELECT lab_id, lab_name FROM Lab ORDER BY lab_name ASC")->fetchAll();
    $docChemicals   = $pdo->query("SELECT chemical_id, chemical_name FROM Chemical ORDER BY chemical_name ASC")->fetchAll();
    $docExperiments = $pdo->query("SELECT experiment_id, title FROM Experiment ORDER BY title ASC")->fetchAll();
} catch (PDOException $e) {
    $docLabs = $docChemicals = $docExperiments = [];
}

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
    <div style="display:flex;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="btn-add" onclick="document.getElementById('modalAddDoc').classList.add('open')">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Document
        </button>
    </div>

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

<!-- Add Document modal -->
<div class="modal-overlay" id="modalAddDoc">
    <div class="modal-box">
        <button class="modal-close-btn" onclick="document.getElementById('modalAddDoc').classList.remove('open')" aria-label="Close">&times;</button>
        <div class="modal-title">Add Document</div>

        <?php if ($docAddErr): ?>
            <div class="modal-alert-err"><?= htmlspecialchars($docAddErr) ?></div>
        <?php endif; ?>

        <form method="POST" action="documents.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_document">

            <div style="display:flex;gap:16px;margin-bottom:14px;">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.875rem;color:var(--clr-text);">
                    <input type="radio" name="doc_mode" value="upload" onchange="toggleDocMode(this.value)"
                           <?= (($_POST['doc_mode'] ?? 'upload') !== 'manual' ? 'checked' : '') ?>>
                    Upload File
                </label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.875rem;color:var(--clr-text);">
                    <input type="radio" name="doc_mode" value="manual" onchange="toggleDocMode(this.value)"
                           <?= (($_POST['doc_mode'] ?? '') === 'manual' ? 'checked' : '') ?>>
                    Enter URL Manually
                </label>
            </div>

            <div class="form-grid-2">
                <div class="f-field">
                    <label>Lab *</label>
                    <select name="doc_lab_id" required>
                        <option value="">Select lab...</option>
                        <?php foreach ($docLabs as $lab): ?>
                            <option value="<?= (int)$lab['lab_id'] ?>"
                                <?= ((int)($_POST['doc_lab_id'] ?? 0) === (int)$lab['lab_id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars($lab['lab_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-field">
                    <label>Document Type *</label>
                    <select name="doc_type_val" required>
                        <option value="">Select type...</option>
                        <?php foreach (['Protocol','SDS','Instruction','Report','Other'] as $dt): ?>
                            <option value="<?= htmlspecialchars($dt) ?>"
                                <?= (($_POST['doc_type_val'] ?? '') === $dt ? 'selected' : '') ?>>
                                <?= htmlspecialchars($dt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="f-field f-full" id="docUploadField">
                    <label>File *</label>
                    <input type="file" name="doc_file"
                           accept=".pdf,.doc,.docx,.txt,.xlsx,.png,.jpg,.jpeg">
                </div>

                <div class="f-field" id="docNameField" style="display:none;">
                    <label>File Name *</label>
                    <input type="text" name="doc_file_name" maxlength="150"
                           placeholder="e.g. ethanol_sds.pdf"
                           value="<?= htmlspecialchars($_POST['doc_file_name'] ?? '') ?>">
                </div>
                <div class="f-field" id="docUrlField" style="display:none;">
                    <label>File URL *</label>
                    <input type="url" name="doc_file_url" maxlength="225"
                           placeholder="https://example.com/file.pdf"
                           value="<?= htmlspecialchars($_POST['doc_file_url'] ?? '') ?>">
                </div>

                <div class="f-field">
                    <label>Linked Chemical</label>
                    <select name="doc_chemical_id">
                        <option value="">None</option>
                        <?php foreach ($docChemicals as $ch): ?>
                            <option value="<?= (int)$ch['chemical_id'] ?>"
                                <?= ((int)($_POST['doc_chemical_id'] ?? 0) === (int)$ch['chemical_id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars($ch['chemical_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-field">
                    <label>Linked Experiment</label>
                    <select name="doc_experiment_id">
                        <option value="">None</option>
                        <?php foreach ($docExperiments as $ex): ?>
                            <option value="<?= (int)$ex['experiment_id'] ?>"
                                <?= ((int)($_POST['doc_experiment_id'] ?? 0) === (int)$ex['experiment_id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars($ex['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-field f-full">
                    <label>Notes</label>
                    <textarea name="doc_notes" rows="2"
                              placeholder="Optional notes..."><?= htmlspecialchars($_POST['doc_notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="modal-footer-row">
                <button type="button" class="modal-cancel-btn" onclick="document.getElementById('modalAddDoc').classList.remove('open')">Cancel</button>
                <button type="submit" class="modal-save-btn">Save Document</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('modalAddDoc').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
function toggleDocMode(mode) {
    var uploadField = document.getElementById('docUploadField');
    var nameField   = document.getElementById('docNameField');
    var urlField    = document.getElementById('docUrlField');
    if (mode === 'upload') {
        uploadField.style.display = '';
        nameField.style.display   = 'none';
        urlField.style.display    = 'none';
    } else {
        uploadField.style.display = 'none';
        nameField.style.display   = '';
        urlField.style.display    = '';
    }
}
<?php if (($_POST['doc_mode'] ?? '') === 'manual'): ?>
document.addEventListener('DOMContentLoaded', function() { toggleDocMode('manual'); });
<?php endif; ?>
<?php if ($docAddErr): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('modalAddDoc').classList.add('open');
});
<?php endif; ?>
</script>

<footer class="dash-footer">
    LabTrack &copy; 2026 &mdash; University of Rhode Island
</footer>

</body>
</html>
