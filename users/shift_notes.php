<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/session_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../functions/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$shift_id = (int) ($_GET['shift_id'] ?? 0);

if ($shift_id <= 0) {
    $_SESSION['error_message'] = 'Invalid shift ID';
    header("Location: shifts.php");
    exit();
}

// Get shift details
$stmt = $conn->prepare("
    SELECT s.*, u.username, r.name as role_name
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN roles r ON s.role_id = r.id
    WHERE s.id = ?
");
$stmt->execute([$shift_id]);
$shift = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shift) {
    $_SESSION['error_message'] = 'Shift not found';
    header("Location: shifts.php");
    exit();
}

// Check access
$is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
$is_assigned = $shift['user_id'] == $user_id;

if (!$is_admin && !$is_assigned) {
    $_SESSION['error_message'] = 'You do not have access to this shift';
    header("Location: shifts.php");
    exit();
}

// Format shift details
$shift_date_formatted = date('l, F j, Y', strtotime($shift['shift_date']));
$shift_time = date('g:i A', strtotime($shift['start_time'])) . ' - ' . date('g:i A', strtotime($shift['end_time']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <title>Shift Notes - Open Rota</title>
    
    <link rel="icon" type="image/png" href="../images/icon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="apple-touch-icon" href="../images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/shift_notes.css">
</head>
<body>
    <header>
        <div class="top-bar">
            <button class="menu-toggle" id="menu-toggle">
                <i class="fa fa-bars"></i>
            </button>
            <h2 class="page-title"><i class="fas fa-sticky-note"></i> Shift Notes</h2>
            <a href="shifts.php" class="btn-back">
                <i class="fa fa-arrow-left"></i>
            </a>
        </div>

        <div class="mobile-menu" id="mobile-menu">
            <nav>
                <ul>
                    <li><a href="dashboard.php"><i class="fa fa-tachometer"></i> Dashboard</a></li>
                    <li><a href="shifts.php"><i class="fa fa-calendar"></i> My Shifts</a></li>
                    <li><a href="rota.php"><i class="fa fa-table"></i> Rota</a></li>
                    <li><a href="roles.php"><i class="fa fa-users"></i> Roles</a></li>
                    <li><a href="payroll.php"><i class="fa fa-money"></i> Payroll</a></li>
                    <li><a href="settings.php"><i class="fa fa-cog"></i> Settings</a></li>
                    <?php if ($is_admin): ?>
                        <li><a href="../admin/admin_dashboard.php"><i class="fa fa-shield"></i> Admin</a></li>
                    <?php endif; ?>
                    <li><a href="../functions/logout.php"><i class="fa fa-sign-out"></i> Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="container">
        <!-- Shift Info Card -->
        <div class="shift-info-card">
            <div class="shift-info-header">
                <div>
                    <h3><i class="fas fa-calendar-day"></i> <?php echo htmlspecialchars($shift_date_formatted); ?></h3>
                    <p class="shift-details">
                        <span><i class="fas fa-clock"></i> <?php echo htmlspecialchars($shift_time); ?></span>
                        <span><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($shift['role_name']); ?></span>
                        <?php if ($shift['location']): ?>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($shift['location']); ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="shift-employee">
                        <i class="fas fa-user"></i> Assigned to: <strong><?php echo htmlspecialchars($shift['username']); ?></strong>
                    </p>
                </div>
            </div>
        </div>

        <!-- Add Note Form -->
        <div class="add-note-card">
            <h3><i class="fas fa-plus-circle"></i> Add New Note</h3>
            <form id="addNoteForm">
                <div class="form-group">
                    <textarea 
                        id="noteText" 
                        placeholder="Enter handover notes, important information, or reminders for the next shift..."
                        rows="4"
                        maxlength="5000"
                        required
                    ></textarea>
                    <div class="char-count">
                        <span id="charCount">0</span> / 5000 characters
                    </div>
                </div>
                <div class="form-actions">
                    <label class="important-checkbox">
                        <input type="checkbox" id="isImportant">
                        <span><i class="fas fa-star"></i> Mark as Important</span>
                    </label>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Note
                    </button>
                </div>
            </form>
        </div>

        <!-- Notes List -->
        <div class="notes-container">
            <div class="notes-header">
                <h3><i class="fas fa-list"></i> Shift Notes</h3>
                <div class="notes-filters">
                    <button class="filter-btn active" data-filter="all">
                        <i class="fas fa-th"></i> All
                    </button>
                    <button class="filter-btn" data-filter="important">
                        <i class="fas fa-star"></i> Important
                    </button>
                </div>
            </div>
            
            <div id="notesList" class="notes-list">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i> Loading notes...
                </div>
            </div>
        </div>
    </div>

    <script>
        const shiftId = <?php echo $shift_id; ?>;
        const userId = <?php echo $user_id; ?>;
        const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
        let currentFilter = 'all';
        let allNotes = [];

        // Load notes on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadNotes();
            
            // Character counter
            const noteText = document.getElementById('noteText');
            const charCount = document.getElementById('charCount');
            
            noteText.addEventListener('input', function() {
                charCount.textContent = this.value.length;
            });
            
            // Add note form submission
            document.getElementById('addNoteForm').addEventListener('submit', function(e) {
                e.preventDefault();
                addNote();
            });
            
            // Filter buttons
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    currentFilter = this.dataset.filter;
                    displayNotes();
                });
            });
        });

        function loadNotes() {
            fetch(`../functions/shift_notes_api.php?action=get_notes&shift_id=${shiftId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        allNotes = data.notes;
                        displayNotes();
                    } else {
                        showError(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Failed to load notes');
                });
        }

        function displayNotes() {
            const notesList = document.getElementById('notesList');
            
            let filteredNotes = allNotes;
            if (currentFilter === 'important') {
                filteredNotes = allNotes.filter(note => note.is_important == 1);
            }
            
            if (filteredNotes.length === 0) {
                notesList.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-sticky-note"></i>
                        <p>${currentFilter === 'important' ? 'No important notes yet' : 'No notes yet for this shift'}</p>
                        <small>Add the first note using the form above</small>
                    </div>
                `;
                return;
            }
            
            notesList.innerHTML = filteredNotes.map(note => {
                const canDelete = isAdmin || note.created_by == userId;
                const canToggle = isAdmin || note.created_by == userId;
                const isImportant = note.is_important == 1;
                const timestamp = new Date(note.created_at).toLocaleString('en-GB', {
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                return `
                    <div class="note-card ${isImportant ? 'important' : ''}" data-note-id="${note.id}">
                        <div class="note-header">
                            <div class="note-author">
                                <i class="fas fa-user-circle"></i>
                                <strong>${escapeHtml(note.author_name)}</strong>
                            </div>
                            <div class="note-actions">
                                ${canToggle ? `
                                    <button onclick="toggleImportant(${note.id}, ${isImportant ? 0 : 1})" 
                                            class="btn-icon ${isImportant ? 'active' : ''}" 
                                            title="${isImportant ? 'Unmark as important' : 'Mark as important'}">
                                        <i class="fas fa-star"></i>
                                    </button>
                                ` : ''}
                                ${canDelete ? `
                                    <button onclick="deleteNote(${note.id})" class="btn-icon btn-delete" title="Delete note">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                        <div class="note-content">
                            ${escapeHtml(note.note).replace(/\n/g, '<br>')}
                        </div>
                        <div class="note-footer">
                            ${isImportant ? '<span class="important-badge"><i class="fas fa-star"></i> Important</span>' : ''}
                            <span class="note-timestamp"><i class="fas fa-clock"></i> ${timestamp}</span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function addNote() {
            const noteText = document.getElementById('noteText').value.trim();
            const isImportant = document.getElementById('isImportant').checked ? 1 : 0;
            
            if (!noteText) {
                showError('Please enter a note');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_note');
            formData.append('shift_id', shiftId);
            formData.append('note', noteText);
            formData.append('is_important', isImportant);
            
            fetch('../functions/shift_notes_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess('Note added successfully!');
                    document.getElementById('noteText').value = '';
                    document.getElementById('isImportant').checked = false;
                    document.getElementById('charCount').textContent = '0';
                    loadNotes();
                } else {
                    showError(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Failed to add note');
            });
        }

        function toggleImportant(noteId, isImportant) {
            const formData = new FormData();
            formData.append('action', 'toggle_important');
            formData.append('note_id', noteId);
            
            fetch('../functions/shift_notes_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message);
                    loadNotes();
                } else {
                    showError(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Failed to update note');
            });
        }

        function deleteNote(noteId) {
            if (!confirm('Are you sure you want to delete this note?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_note');
            formData.append('note_id', noteId);
            
            fetch('../functions/shift_notes_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess('Note deleted');
                    loadNotes();
                } else {
                    showError(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Failed to delete note');
            });
        }

        function showSuccess(message) {
            showToast(message, 'success');
        }

        function showError(message) {
            showToast(message, 'error');
        }

        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(toast);
            
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Mobile menu toggle
        document.getElementById('menu-toggle').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.toggle('active');
        });
    </script>
</body>
</html>