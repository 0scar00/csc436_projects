<?php
    include '../includes/session.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Document — LabTrack</title>
    <link rel="stylesheet" href="document.css">
</head>
<body>

<!-- ── Navigation ─────────────────────────────────────────────────────────── -->
<nav>
    <span class="brand">LabTrack</span>
    <a href="../dashboard.php">Dashboard</a>
    <a href="inventory.php">Inventory</a>
    <a href="../chem_usage.php">Chemical Usage</a>
    <a href="document.php">Documents</a>
    <a href="logout.php">Log out (<?= htmlspecialchars($_SESSION['username'] ?? 'User') ?>)</a>
</nav>

<div class="page-wrapper">
    <h1>Upload New Document</h1>

    <form method="POST" action="document.php" enctype="multipart/form-data" class="upload-form">
        <label>
            <span>Document Type</span>
            <input type="text" name="doc_type" required placeholder="e.g., Safety Data Sheet">
        </label>
        <label>
            <span>Chemical Name</span>
            <input type="text" name="chem_name" required placeholder="e.g., Acetone">
        </label>
        <label>
            <span>Experiment Name</span>
            <input type="text" name="exp_name" required placeholder="e.g., Reaction Study">
        </label>
        <label>
            <span>Lab ID</span>
            <input type="text" name="lab_id" required placeholder="e.g., Lab-101">
        </label>
        <label>
            <span>Notes</span>
            <textarea name="note" rows="3" placeholder="Additional notes about the document"></textarea>
        </label>
        <label>
            <span>Upload File</span>
            <input type="file" name="document_file" required>
        </label>
        <button type="submit" class="btn btn-primary">Upload Document</button>
        <a href="document.php" class="btn btn-outline">Cancel</a>
    </form>
</div>

</body>
</html>