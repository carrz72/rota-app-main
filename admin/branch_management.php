<?php
require_once '../includes/auth.php';
require_once '../includes/SessionManager.php';
require_once '../includes/PermissionManager.php';
require_once '../includes/DatabaseManager.php';
require_once '../includes/Validator.php';
require_once '../functions/branch_functions.php';

// Initialize managers
SessionManager::requireAdmin();
$permissions = new PermissionManager($conn, SessionManager::getUserId());
$db = new DatabaseManager($conn);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_branch'])) {
        // Check permissions
        if (!$permissions->canCreateBranch()) {
            SessionManager::redirectWithError('branch_management.php', 'Only system administrators can create new branches.');
        }

        // Validate input
        $validator = Validator::validateBranch($_POST);

        if ($validator->fails()) {
            SessionManager::redirectWithError('branch_management.php', $validator->getFirstError());
        }

        // Insert branch
        $data = $validator->getSanitizedData();
        $data['code'] = strtoupper($data['code']);

        $result = $db->insert('branches', $data);

        if ($result['success']) {
            SessionManager::redirectWithSuccess('branch_management.php', "Branch '{$data['name']}' created successfully!");
        } else {
            $message = ($result['code'] == 23000)
                ? "Branch name or code already exists. Please choose different values."
                : "Error creating branch: " . $result['error'];
            SessionManager::redirectWithError('branch_management.php', $message);
        }
    }

    if (isset($_POST['update_branch'])) {
        $branchId = $_POST['branch_id'];

        // Check permissions
        if (!$permissions->canEditBranch($branchId)) {
            SessionManager::redirectWithError('branch_management.php', 'You can only edit your own branch.');
        }

        // Validate input
        $validator = Validator::validateBranch($_POST);

        if ($validator->fails()) {
            SessionManager::redirectWithError('branch_management.php', $validator->getFirstError());
        }

        // Update branch
        $data = $validator->getSanitizedData();
        unset($data['code']); // Don't allow code changes on update

        $result = $db->update('branches', $data, 'id = ?', [$branchId]);

        if ($result['success']) {
            SessionManager::redirectWithSuccess('branch_management.php', 'Branch updated successfully!');
        } else {
            SessionManager::redirectWithError('branch_management.php', 'Error updating branch: ' . $result['error']);
        }
    }
}// Get accessible branches and statistics
$branches = $permissions->getAccessibleBranches(true); // Include inactive for management

// Get branch statistics
$branch_stats = [];
foreach ($branches as $branch) {
    $stats = getCrossBranchStats($conn, $branch['id']);
    $branch_stats[$branch['id']] = $stats;
}

// Get messages
$messages = SessionManager::getAllMessages();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title>Branch Management - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-building"></i> Branch Management</h1>
            </div>
            <div class="admin-actions">
                <a href="admin_dashboard.php" class="admin-btn secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($permissions->isSuperAdmin()): ?>
            <div class="info-message">
                <i class="fas fa-crown"></i> <strong>Super Administrator Mode:</strong> You can create new branches and edit
                all branches.
            </div>
        <?php elseif ($permissions->getUserBranch()): ?>
            <div class="info-message">
                <i class="fas fa-info-circle"></i> <strong>Branch Manager Mode:</strong> You can view all branches but only
                edit your own branch.
            </div>
        <?php else: ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i> <strong>No Branch Assignment:</strong> You must be assigned to a
                branch or be a super administrator to fully use this system.
            </div>
        <?php endif; ?>

        <?php echo SessionManager::displayMessages(); ?>

        <?php if ($permissions->canCreateBranch()): ?>
            <div class="admin-panel">
                <div class="admin-panel-header">
                    <h2><i class="fas fa-plus"></i> Create New Branch</h2>
                </div>
                <div class="admin-panel-body">
                    <form method="POST" class="admin-form">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">
                                    <i class="fas fa-building"></i> Branch Name *
                                </label>
                                <input type="text" id="name" name="name" required>
                            </div>
                            <div class="form-group">
                                <label for="code">
                                    <i class="fas fa-tag"></i> Branch Code *
                                </label>
                                <input type="text" id="code" name="code" required maxlength="10"
                                    placeholder="e.g., MAIN, NORTH">
                            </div>
                            <div class="form-group">
                                <label for="phone">
                                    <i class="fas fa-phone"></i> Phone
                                </label>
                                <input type="tel" id="phone" name="phone">
                            </div>
                            <div class="form-group">
                                <label for="email">
                                    <i class="fas fa-envelope"></i> Email
                                </label>
                                <input type="email" id="email" name="email">
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label for="address">
                                    <i class="fas fa-map-marker-alt"></i> Address
                                </label>
                                <textarea id="address" name="address" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="create_branch" class="admin-btn">
                                <i class="fas fa-plus"></i> Create Branch
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-list"></i>
                    <?php echo $permissions->isSuperAdmin() ? 'All Branches' : 'Branch Information'; ?>
                    <?php if (!$permissions->isSuperAdmin() && $permissions->getUserBranch()): ?>
                        <small>(You can only edit your own branch)</small>
                    <?php endif; ?>
                </h2>
            </div>

            <!-- Search Feature -->
            <div class="search-container" style="padding: 20px; border-bottom: 1px solid #e0e0e0;">
                <div class="search-group">
                    <label for="branch-search">
                        <i class="fas fa-search"></i> Search Branches
                    </label>
                    <div class="search-input-wrapper">
                        <input type="text" id="branch-search"
                            placeholder="Search by name, code, address, phone, or email..." class="search-input">
                        <button type="button" id="clear-search" class="clear-search-btn" title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <small class="search-info">
                        <span id="search-results-count"><?php echo count($branches); ?></span>
                        of <?php echo count($branches); ?> branches shown
                    </small>
                </div>
            </div>

            <div class="admin-panel-body">
                <div id="branches-container"><?php foreach ($branches as $branch): ?>
                        <div class="admin-panel" style="margin-bottom: 20px;">
                            <div class="admin-panel-header">
                                <h3>
                                    <i class="fas fa-building"></i>
                                    <?php echo htmlspecialchars($branch['name']); ?>
                                    <small>(<?php echo htmlspecialchars($branch['code']); ?>)</small>
                                    <?php if ($branch['id'] == $permissions->getUserBranch()): ?>
                                        <span class="badge success">YOUR BRANCH</span>
                                    <?php endif; ?>
                                </h3>
                                <span class="badge <?php echo $branch['status'] === 'active' ? 'success' : 'danger'; ?>">
                                    <?php echo ucfirst($branch['status']); ?>
                                </span>
                            </div>
                            <div class="admin-panel-body">
                                <div class="form-grid">
                                    <div>
                                        <strong><i class="fas fa-map-marker-alt"></i> Address:</strong><br>
                                        <?php echo htmlspecialchars($branch['address'] ?: 'Not specified'); ?>
                                    </div>
                                    <div>
                                        <strong><i class="fas fa-phone"></i> Contact:</strong><br>
                                        <?php if ($branch['phone']): ?>
                                            <span><i class="fas fa-phone"></i>
                                                <?php echo htmlspecialchars($branch['phone']); ?></span><br>
                                        <?php endif; ?>
                                        <?php if ($branch['email']): ?>
                                            <span><i class="fas fa-envelope"></i>
                                                <?php echo htmlspecialchars($branch['email']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!$branch['phone'] && !$branch['email']): ?>
                                            <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Branch Statistics -->
                                <div class="stats-grid" style="margin-top: 20px;">
                                    <div class="stat-card">
                                        <div class="stat-value">
                                            <?php echo $branch_stats[$branch['id']]['total_requests'] ?? 0; ?>
                                        </div>
                                        <div class="stat-label">Total Requests</div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-value">
                                            <?php echo $branch_stats[$branch['id']]['fulfilled_requests'] ?? 0; ?>
                                        </div>
                                        <div class="stat-label">Fulfilled</div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-value">
                                            <?php echo $branch_stats[$branch['id']]['pending_requests'] ?? 0; ?>
                                        </div>
                                        <div class="stat-label">Pending</div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-value">
                                            <?php echo $branch_stats[$branch['id']]['declined_requests'] ?? 0; ?>
                                        </div>
                                        <div class="stat-label">Declined</div>
                                    </div>
                                </div>

                                <!-- Edit Form (only for own branch or super admin) -->
                                <?php if ($permissions->canEditBranch($branch['id'])): ?>
                                    <details style="margin-top: 20px;">
                                        <summary class="admin-btn secondary"
                                            style="cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                                            <i class="fas fa-edit"></i> Edit Branch
                                        </summary>
                                        <div style="margin-top: 15px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                                            <form method="POST" class="admin-form">
                                                <input type="hidden" name="branch_id" value="<?php echo $branch['id']; ?>">
                                                <div class="form-grid">
                                                    <div class="form-group">
                                                        <label for="branch_name_<?php echo $branch['id']; ?>">
                                                            <i class="fas fa-building"></i> Branch Name
                                                        </label>
                                                        <input type="text" id="branch_name_<?php echo $branch['id']; ?>"
                                                            name="name" value="<?php echo htmlspecialchars($branch['name']); ?>"
                                                            required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="branch_status_<?php echo $branch['id']; ?>">
                                                            <i class="fas fa-toggle-on"></i> Status
                                                        </label>
                                                        <select id="branch_status_<?php echo $branch['id']; ?>" name="status">
                                                            <option value="active" <?php echo $branch['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                            <option value="inactive" <?php echo $branch['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                        </select>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="branch_phone_<?php echo $branch['id']; ?>">
                                                            <i class="fas fa-phone"></i> Phone
                                                        </label>
                                                        <input type="tel" id="branch_phone_<?php echo $branch['id']; ?>"
                                                            name="phone"
                                                            value="<?php echo htmlspecialchars($branch['phone']); ?>">
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="branch_email_<?php echo $branch['id']; ?>">
                                                            <i class="fas fa-envelope"></i> Email
                                                        </label>
                                                        <input type="email" id="branch_email_<?php echo $branch['id']; ?>"
                                                            name="email"
                                                            value="<?php echo htmlspecialchars($branch['email']); ?>">
                                                    </div>
                                                    <div class="form-group" style="grid-column: 1 / -1;">
                                                        <label for="branch_address_<?php echo $branch['id']; ?>">
                                                            <i class="fas fa-map-marker-alt"></i> Address
                                                        </label>
                                                        <textarea id="branch_address_<?php echo $branch['id']; ?>"
                                                            name="address"
                                                            rows="3"><?php echo htmlspecialchars($branch['address']); ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" name="update_branch" class="admin-btn">
                                                        <i class="fas fa-save"></i> Update Branch
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </details>
                                <?php else: ?>
                                    <div class="info-message" style="margin-top: 15px;">
                                        <i class="fas fa-lock"></i> You can only edit your own branch
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- No results message (hidden by default) -->
                    <div id="no-results-message" class="no-results" style="display: none;">
                        <div class="info-message">
                            <i class="fas fa-search"></i> No branches found matching your search criteria.
                            <br><small>Try adjusting your search terms.</small>
                        </div>
                    </div>

                </div> <!-- Close branches-container -->
            </div>
        </div>
    </div>

    <style>
        .search-container {
            background-color: #f8f9fa;
        }

        .search-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .search-group label {
            font-weight: 600;
            color: #333;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-input {
            padding: 12px 45px 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: all 0.3s ease;
            font-family: "newFont", Arial, sans-serif;
            width: 100%;
            box-sizing: border-box;
        }

        .search-input:focus {
            outline: none;
            border-color: #fd2b2b;
            box-shadow: 0 0 0 3px rgba(253, 43, 43, 0.1);
        }

        .clear-search-btn {
            position: absolute;
            right: 8px;
            background: none;
            border: none;
            padding: 8px;
            cursor: pointer;
            color: #999;
            border-radius: 4px;
            transition: all 0.2s ease;
            display: none;
        }

        .clear-search-btn:hover {
            background-color: #f0f0f0;
            color: #fd2b2b;
        }

        .clear-search-btn.visible {
            display: block;
        }

        .search-info {
            color: #666;
            font-size: 12px;
            font-style: italic;
        }

        .branch-panel {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .branch-panel.hidden {
            display: none;
        }

        .no-results {
            text-align: center;
            padding: 40px 20px;
        }

        /* Highlight search matches */
        .search-highlight {
            background-color: #fff3cd;
            padding: 1px 2px;
            border-radius: 2px;
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .search-container {
                padding: 15px;
            }

            .search-input {
                padding: 10px 40px 10px 12px;
                font-size: 16px;
                /* Prevent zoom on mobile */
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('branch-search');
            const clearBtn = document.getElementById('clear-search');
            const branchPanels = document.querySelectorAll('#branches-container .admin-panel');
            const resultsCount = document.getElementById('search-results-count');
            const noResultsMessage = document.getElementById('no-results-message');
            const totalBranches = branchPanels.length;

            function highlightText(element, searchTerm) {
                if (!searchTerm) return;

                const walker = document.createTreeWalker(
                    element,
                    NodeFilter.SHOW_TEXT,
                    null,
                    false
                );

                const textNodes = [];
                let node;
                while (node = walker.nextNode()) {
                    textNodes.push(node);
                }

                textNodes.forEach(textNode => {
                    if (textNode.parentElement.classList.contains('search-highlight')) return;

                    const text = textNode.textContent;
                    const lowerText = text.toLowerCase();
                    const lowerSearchTerm = searchTerm.toLowerCase();

                    if (lowerText.includes(lowerSearchTerm)) {
                        const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
                        const highlightedText = text.replace(regex, '<span class="search-highlight">$1</span>');

                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = highlightedText;

                        while (tempDiv.firstChild) {
                            textNode.parentNode.insertBefore(tempDiv.firstChild, textNode);
                        }
                        textNode.remove();
                    }
                });
            }

            function removeHighlights() {
                const highlights = document.querySelectorAll('.search-highlight');
                highlights.forEach(highlight => {
                    const parent = highlight.parentNode;
                    parent.replaceChild(document.createTextNode(highlight.textContent), highlight);
                    parent.normalize();
                });
            }

            function performSearch() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                let visibleCount = 0;

                // Remove previous highlights
                removeHighlights();

                branchPanels.forEach(function (panel) {
                    // Add branch-panel class for styling
                    panel.classList.add('branch-panel');

                    // Get searchable text from specific elements
                    const branchName = panel.querySelector('h3')?.textContent.toLowerCase() || '';
                    const branchCode = panel.querySelector('small')?.textContent.toLowerCase() || '';
                    const address = panel.querySelector('[class*="address"]')?.textContent.toLowerCase() || '';
                    const contact = panel.querySelector('[class*="contact"]')?.textContent.toLowerCase() || '';
                    const panelText = panel.textContent.toLowerCase();

                    // Check if search term matches any content
                    const matches = searchTerm === '' ||
                        branchName.includes(searchTerm) ||
                        branchCode.includes(searchTerm) ||
                        address.includes(searchTerm) ||
                        contact.includes(searchTerm) ||
                        panelText.includes(searchTerm);

                    if (matches) {
                        panel.classList.remove('hidden');
                        visibleCount++;

                        // Highlight matching text
                        if (searchTerm && searchTerm.length > 1) {
                            highlightText(panel, searchTerm);
                        }
                    } else {
                        panel.classList.add('hidden');
                    }
                });

                // Update results count
                resultsCount.textContent = visibleCount;

                // Show/hide clear button
                if (searchTerm) {
                    clearBtn.classList.add('visible');
                } else {
                    clearBtn.classList.remove('visible');
                }

                // Show/hide no results message
                if (visibleCount === 0 && searchTerm !== '') {
                    noResultsMessage.style.display = 'block';
                } else {
                    noResultsMessage.style.display = 'none';
                }
            }

            // Real-time search with debouncing
            let searchTimeout;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(performSearch, 150);
            });

            // Clear search
            clearBtn.addEventListener('click', function () {
                searchInput.value = '';
                performSearch();
                searchInput.focus();
            });

            // Initial setup
            performSearch();
        });
    </script>
</body>

</html>