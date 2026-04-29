<?php
    include '../includes/session.php';
    include '../includes/database-connection.php';

    // download file
    if (isset($_GET['linkId'])) {
        // Clear any previous output buffering
        ob_clean();
        
        $file_path = $_GET['linkId'];

        // Security: Validate the file exists and prevent directory traversal
        if (file_exists($file_path)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file_path));
            
            // Read the file and exit to prevent the rest of the HTML from loading
            readfile($file_path);
            $redirect = $_GET['redirect'] ?? 'document.php';

            header("Location: $redirect");
            exit;
        } else {
            $error = "Error: File not found at " . htmlspecialchars($file_path);
            $redirect = $_GET['redirect'] ?? 'document.php';

            header("Location: $redirect");
        }
    }

    // upload document
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $doc_type = $_POST['doc_type'];

        $chemical_name = $_POST['chem_name'];
        $experiment_name = $_POST['exp_name'];
        
        $lab_id = $_POST['lab_id'];

        // actual file name
        $file_name = $_FILES['document_file']['name'];
        $temp_name = $_FILES['document_file']['tmp_name'];
        $notes = $_POST['note'];
        $upload_path = 'documents/' . $file_name;

        // move file into document folder
        if(move_uploaded_file($temp_name, $upload_path)) {
            $chemical_sql = "SELECT chemical_id FROM Chemical WHERE chemical_name = ?;";

            $stmt = $pdo->prepare($chemical_sql);
            $stmt->execute([$chemical_name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $chemical_id = $row['chemical_id'];

            // experiment id
            $exp_sql = "SELECT experiment_id FROM Experiment WHERE title = ?;";

            $stmt = $pdo->prepare($exp_sql);
            $stmt->execute([$experiment_name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $experiment_id;

            if($row) {
                $experiment_id = $row['experiment_id'];
            }
            else {
                $experiment_id = NULL;
            }

            $sql = 'INSERT INTO Document (lab_id, uploaded_by_user_id, doc_type, file_name, file_url, uploaded_at, notes, chemical_id, experiment_id)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?);';

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$lab_id, $_SESSION['user_id'], $doc_type, $file_name, $upload_path, $notes, $chemical_id, $experiment_id]);
        }
    }

    // query for documents
    $search_result = [];
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search']) && !empty($_GET['search'])) {
        $like_string = "%" . $_GET['search'] . "%";
        $sql = "SELECT document_id, lab_id, doc_type, file_url, file_name, uploaded_at, notes FROM Document
                WHERE file_name LIKE ? OR notes LIKE ?;";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$like_string, $like_string]);
        $search_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    else {
        $sql = "SELECT document_id, lab_id, doc_type, file_url, file_name, uploaded_at, notes FROM Document;";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $search_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents — LabTrack</title>
    <link rel="stylesheet" href="document.css">
</head>
<body>

<!-- ── Navigation ─────────────────────────────────────────────────────────── -->
<nav>
    <span class="brand">LabTrack</span>
    <a href="../dashboard.php">Dashboard</a>
    <a href="../inventory.php">Inventory</a>
    <a href="../chem_usage.php">Chemical Usage</a>
    <a href="document.php">Documents</a>
    <a href="../logout.php">Log out (<?= htmlspecialchars($_SESSION['username'] ?? 'User') ?>)</a>
</nav>

<div class="page-wrapper">
    <h1>Document Management</h1>

    <form method="GET" action="document.php" class="filter-bar">
        <label>
            <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search by file name or notes">
        </label>
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="upload_doc.php" class="btn btn-primary">Add New Document</a>
        <?php if (!empty($_GET['search'])): ?>
            <a href="document.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>

    <?php if (!empty($error)): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Document ID</th>
                    <th>Lab ID</th>
                    <th>Document Type</th>
                    <th>File name</th>
                    <th>Uploaded At</th>
                    <th>Notes</th>
                    <th>Download</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($search_result)): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <strong>No documents found.</strong>
                            <p>Try uploading a new document or adjusting your search.</p>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($search_result as $i => $row): ?>
                <tr>
                    <td><?=  htmlspecialchars($row['document_id']) ?></td>
                    <td><?= htmlspecialchars($row['lab_id']) ?></td>
                    <td><?= htmlspecialchars($row['doc_type']) ?></td>
                    <td><?= htmlspecialchars($row['file_name']) ?></td>
                    <td><?= htmlspecialchars($row['uploaded_at']) ?></td>
                    <td><?= htmlspecialchars($row['notes']) ?></td>
                    <td><a href="document.php?linkId=<?php echo htmlentities(urlencode($row['file_url'])); ?>">Download</a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>