<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/database-connection.php';

if (!$logged_in) {
    header('Location: login.php');
    exit;
}

$username = htmlspecialchars($_SESSION['username'] ?? 'User');

try {
    $totalChemicals = (int) $pdo->query('SELECT COUNT(*) FROM Chemical')->fetchColumn();
    $activeBatches = (int) $pdo->query("SELECT COUNT(DISTINCT batch_id) FROM Inventory_item WHERE status <> 'Out of Stock'")->fetchColumn();
    $experiments = (int) $pdo->query('SELECT COUNT(*) FROM Experiment')->fetchColumn();
    $expiringSoon = (int) $pdo->query("SELECT COUNT(*) FROM Batch WHERE expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

    $chemicals = $pdo->query("\n        SELECT\n            c.chemical_name,\n            c.cas_number,\n            c.hazard_class,\n            ii.status\n        FROM Chemical c\n        LEFT JOIN Batch b ON b.chemical_id = c.chemical_id\n        LEFT JOIN Inventory_item ii ON ii.batch_id = b.batch_id\n        GROUP BY c.chemical_id, c.chemical_name, c.cas_number, c.hazard_class, ii.status\n        ORDER BY c.chemical_name ASC\n    ")->fetchAll();

    $expiringBatches = $pdo->query("\n        SELECT\n            c.chemical_name,\n            b.lot_number,\n            b.expiration_date\n        FROM Batch b\n        JOIN Chemical c ON c.chemical_id = b.chemical_id\n        ORDER BY b.expiration_date ASC\n        LIMIT 3\n    ")->fetchAll();
} catch (PDOException $e) {
    error_log('LabTrack dashboard error: ' . $e->getMessage());
    $totalChemicals = 0;
    $activeBatches = 0;
    $experiments = 0;
    $expiringSoon = 0;
    $chemicals = [];
    $expiringBatches = [];
}

function badgeClass(string $hazard): string {
    $hazard = strtolower($hazard);
    if (str_contains($hazard, 'flamm')) return 'badge-amber';
    if (str_contains($hazard, 'corr')) return 'badge-red';
    if (str_contains($hazard, 'irrit')) return 'badge-gray';
    if (str_contains($hazard, 'low')) return 'badge-gray';
    return 'badge-gray';
}

function statusClass(string $status): string {
    $status = strtolower($status);
    if ($status === 'in stock') return 'badge-green';
    if ($status === 'low stock') return 'badge-amber';
    if ($status === 'out of stock') return 'badge-red';
    return 'badge-gray';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>LabTrack — Dashboard</title>
  <link rel="stylesheet" href="login.css" />
  <link rel="stylesheet" href="dashboard.css" />
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
      <a href="dashboard.php" class="nav-link active">Dashboard</a>
      <a href="inventory.php" class="nav-link">Inventory</a>
      <a href="experiments.php" class="nav-link">Experiments</a>
      <a href="documents.php" class="nav-link">Documents</a>
      <a href="chem_usage.php" class="nav-link">Chemical Usage</a>
    </nav>

    <div class="topnav-user">
      <span id="welcomeUser" class="user-greeting"><?= $username ?></span>
      <button id="logoutBtn" class="btn-logout">Sign Out</button>
    </div>
  </header>

  <main class="dash-main">

    <section class="dash-hero">
      <div class="dash-hero-text">
        <h2>Welcome back<span id="heroName"></span></h2>
        <p>Here's a snapshot of your lab's current status.</p>
      </div>
      <div class="dash-hero-badge">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M7 2v11m0 0a4 4 0 1 0 4 4V7a4 4 0 0 1 4-4h2"/>
          <circle cx="7" cy="17" r="1"/>
        </svg>
      </div>
    </section>

    <section class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon cyan">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 3h18v4H3z"/><path d="M3 7v14h18V7"/>
            <path d="M9 11h6M9 15h4"/>
          </svg>
        </div>
        <div class="stat-info">
          <span class="stat-label">Total Chemicals</span>
          <span class="stat-value"><?= $totalChemicals ?></span>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon amber">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2z"/>
            <path d="M12 6v6l4 2"/>
          </svg>
        </div>
        <div class="stat-info">
          <span class="stat-label">Active Batches</span>
          <span class="stat-value"><?= $activeBatches ?></span>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon green">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 17H7A5 5 0 0 1 7 7h2"/>
            <path d="M15 7h2a5 5 0 0 1 0 10h-2"/>
            <line x1="8" y1="12" x2="16" y2="12"/>
          </svg>
        </div>
        <div class="stat-info">
          <span class="stat-label">Experiments</span>
          <span class="stat-value"><?= $experiments ?></span>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
        </div>
        <div class="stat-info">
          <span class="stat-label">Expiring Soon</span>
          <span class="stat-value"><?= $expiringSoon ?></span>
        </div>
      </div>
    </section>

    <div class="dash-content-row">
      <div class="dash-panel">
        <div class="panel-header">
          <h3>Chemical Inventory</h3>
          <a href="inventory.php" class="panel-link">View all →</a>
        </div>
        <table class="dash-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>CAS #</th>
              <th>Hazard</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($chemicals as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['chemical_name']) ?></td>
                <td><?= htmlspecialchars($row['cas_number']) ?></td>
                <td><span class="badge <?= htmlspecialchars(badgeClass($row['hazard_class'])) ?>"><?= htmlspecialchars($row['hazard_class']) ?></span></td>
                <td><span class="badge <?= htmlspecialchars(statusClass($row['status'] ?? '')) ?>"><?= htmlspecialchars($row['status'] ?? 'Unknown') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="dash-side">
        <div class="dash-panel">
          <div class="panel-header">
            <h3>Expiring Batches</h3>
          </div>
          <ul class="exp-list">
            <?php foreach ($expiringBatches as $index => $row): ?>
              <li class="exp-item">
                <div class="exp-dot <?= $index === 0 ? 'red' : 'amber' ?>"></div>
                <div>
                  <span class="exp-name"><?= htmlspecialchars($row['chemical_name']) ?> — Lot <?= htmlspecialchars($row['lot_number']) ?></span>
                  <span class="exp-date">Expires <?= htmlspecialchars(date('M j, Y', strtotime($row['expiration_date']))) ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div class="dash-panel">
          <div class="panel-header">
            <h3>Quick Actions</h3>
          </div>
          <div class="quick-actions">
            <a href="inventory.php" class="qa-btn">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
              </svg>
              View Inventory
            </a>
            <a href="inventory.php" class="qa-btn">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
              </svg>
              Log Usage
            </a>
            <a href="experiments.php" class="qa-btn">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
              </svg>
              New Experiment
            </a>
            <a href="documents.php" class="qa-btn">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
              </svg>
              Upload Document
            </a>
          </div>
        </div>
      </div>
    </div>
  </main>

  <footer class="dash-footer">
    LabTrack &copy; 2026 &mdash; University of Rhode Island
  </footer>

  <script src="dashboard.js"></script>
</body>
</html>
