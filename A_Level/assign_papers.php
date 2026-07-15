<?php
// Database connection
require_once '../auth.php';
require_once '../conn.php';

// Initialize variables
$class = isset($_GET['class']) ? $_GET['class'] : 'Senior Five';
?>

<!DOCTYPE html>
<html data-bs-theme="light" lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Assign Subject Papers</title>
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <link rel="stylesheet" href="../assets/fonts/font-awesome.min.css">
    <link rel="stylesheet" href="../assets/fonts/fontawesome5-overrides.min.css">
    <style>
        /* Green Color Palette */
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --white: #ffffff;
            --light-gray: #f5f5f5;
            --gray: #e0e0e0;
            --dark-gray: #757575;
            --text-dark: #333333;
            --success-bg: #d4edda;
            --success-text: #155724;
            --success-border: #28a745;
            --error-bg: #f8d7da;
            --error-text: #721c24;
            --error-border: #dc3545;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        /* Basic Reset & Body */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--light-gray);
            color: var(--text-dark);
            font-size: 14px;
            line-height: 1.6;
        }

        /* Page Wrapper for Centering */
        .page-wrapper {
            max-width: 100%;
            margin: 2rem auto;
            padding: 2rem;
            background-color: var(--white);
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        /* Page Header */
        .page-header {
            background-color: var(--primary-dark);
            color: var(--white);
            padding: 15px 25px;
            margin-bottom: 2rem;
            border-radius: 6px;
            font-size: 1.2rem;
            font-weight: 600;
        }

        /* Filters Section */
        .filters-container {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
            background-color: #fafafa;
            padding: 1.5rem;
            border-radius: 6px;
            border: 1px solid var(--gray);
        }

        .filter-item {
            flex: 1;
        }

        .filter-item label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--primary-dark);
        }

        .filter-item select,
        .filter-item input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray);
            border-radius: 4px;
            font-size: 14px;
            transition: var(--transition);
        }

        .filter-item select:focus,
        .filter-item input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.2);
            outline: none;
        }

        .search-box {
            position: relative;
        }

        .search-box i {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(-50%);
            color: var(--dark-gray);
        }

        .search-box input {
            padding-left: 35px;
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border-radius: 6px;
            overflow: hidden;
        }

        .data-table th {
            background-color: var(--primary-color);
            color: var(--white);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            padding: 14px 18px;
            text-align: left;
            cursor: pointer;
            position: relative;
            user-select: none;
            transition: var(--transition);
        }

        .data-table th:hover {
            background-color: var(--primary-dark);
        }

        .data-table th.sort-asc::after,
        .data-table th.sort-desc::after {
            content: '';
            position: absolute;
            right: 10px;
            top: calc(50% - 2px);
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
        }

        .data-table th.sort-asc::after {
            border-bottom: 5px solid var(--accent-color);
        }

        .data-table th.sort-desc::after {
            border-top: 5px solid var(--accent-color);
        }

        .data-table td {
            padding: 14px 18px;
            border-bottom: 1px solid var(--gray);
            vertical-align: middle;
        }

        .data-table tbody tr:nth-child(even) {
            background-color: var(--light-gray);
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background-color: #e8f5e9;
            /* Light green hover */
        }

        .subject-name {
            font-weight: 600;
            color: var(--primary-dark);
        }

        .subject-details {
            font-size: 0.8rem;
            color: var(--dark-gray);
            display: block;
            margin-top: 3px;
        }

        /* Paper Selection Buttons */
        .paper-selection {
            display: flex;
            gap: 8px;
        }

        .paper-checkbox {
            display: none;
        }

        .paper-label {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 5px;
            color: var(--dark-gray);
            background-color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            border: 1px solid #ccc;
        }

        .paper-checkbox:checked+.paper-label {
            background-color: var(--primary-light);
            color: var(--white);
            border-color: var(--primary-color);
        }

        /* Status Badge */
        .status-badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-ready {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .status-required {
            background-color: var(--error-bg);
            color: var(--error-text);
        }

        /* Buttons */
        .button {
            background-color: var(--dark-gray);
            color: var(--white);
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }

        .button:hover {
            opacity: 0.85;
        }

        .button i {
            margin-right: 8px;
        }

        /* Message/Notification Container */
        #messageContainer {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 320px;
            color: var(--white);
            padding: 15px 20px;
            border-radius: 6px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            display: none;
            animation: slideIn 0.4s ease-out forwards;
        }

        #messageContainer i {
            margin-right: 10px;
        }

        @keyframes slideIn {
            from {
                transform: translateX(110%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(110%);
                opacity: 0;
            }
        }

        .success-message {
            background-color: var(--primary-light);
            border-left: 5px solid var(--primary-dark);
        }

        .error-message {
            background-color: var(--error-border);
            border-left: 5px solid var(--error-text);
        }

        /* Loading Overlay & Spinner */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            display: none;
            /* Controlled by JS */
        }

        .spinner-container {
            text-align: center;
            background-color: var(--white);
            padding: 25px 40px;
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--gray);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px auto;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Empty table cell style */
        .empty-cell {
            text-align: center;
            padding: 40px;
        }
    </style>
</head>

<body>
    <?php  require_once '../nav.php'; ?>
    <div class="page-wrapper">
        <header class="page-header">
            ASSIGN A-LEVEL SUBJECT PAPERS
        </header>

        <div id="messageContainer"></div>

        <div class="loading-overlay" id="loadingOverlay">
            <div class="spinner-container">
                <div class="spinner"></div>
                <div>Loading paper settings...</div>
            </div>
        </div>

        <main class="content-panel">
            <div class="filters-container">
                <div class="filter-item">
                    <label for="class">Class Selection:</label>
                    <select name="class" id="class">
                        <option value="Senior Five" <?php echo ($class == 'Senior Five') ? 'selected' : ''; ?>>Senior Five</option>
                        <option value="Senior Six" <?php echo ($class == 'Senior Six') ? 'selected' : ''; ?>>Senior Six</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label for="searchSubject">Search Subjects:</label>
                    <div class="search-box">
                        <i class="fa fa-search"></i>
                        <input type="text" id="searchSubject" placeholder="Type to search...">
                    </div>
                </div>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 35%;" data-sort="subject">Subject</th>
                        <th style="width: 50%;" data-sort="papers">Papers</th>
                        <th style="width: 15%;" data-sort="status">Status</th>
                    </tr>
                </thead>
                <tbody id="subjects-list">
                </tbody>
            </table>

            <div class="actions">
                <button type="button" class="button" id="resetBtn">
                    <i class="fa fa-refresh"></i> Reset Selection
                </button>
            </div>
        </main>
    </div>

    <script src="../assets/js/jquery.min.js"></script>
    <script>
        // Global variable to track current class
        let currentClass = '<?php echo $class; ?>';
        // Track current sort state
        let currentSort = {
            column: 'subject',
            direction: 'asc'
        };

        // Function to load subjects via AJAX
        function loadSubjects(selectedClass) {
            // Show loading overlay
            $('#loadingOverlay').fadeIn(200);

            // Send AJAX request
            $.ajax({
                url: 'get_subjects.php',
                type: 'GET',
                data: {
                    class: selectedClass
                },
                dataType: 'json',
                success: function(response) {
                    // Hide loading overlay
                    $('#loadingOverlay').fadeOut(200);

                    if (response.success) {
                        displaySubjects(response.subjects, response.existingPapers);
                        // Apply current sort after displaying subjects
                        sortTable(currentSort.column, currentSort.direction);
                    } else {
                        $('#subjects-list').html(`
                        <tr>
                            <td colspan="3" class="empty-cell">
                                <i class="fa fa-exclamation-circle fa-3x" style="color:#757575; margin-bottom: 1rem;"></i>
                                <p>${response.message || 'Error loading subjects'}</p>
                            </td>
                        </tr>
                    `);
                    }
                },
                error: function(xhr, status, error) {
                    // Hide loading overlay
                    $('#loadingOverlay').fadeOut(200);

                    // Display error message
                    $('#subjects-list').html(`
                    <tr>
                        <td colspan="3" class="empty-cell">
                            <i class="fa fa-exclamation-circle fa-3x" style="color:#757575; margin-bottom: 1rem;"></i>
                            <p>Error connecting to server. Please try again.</p>
                        </td>
                    </tr>
                `);
                }
            });
        }

        // Function to display subjects
        function displaySubjects(subjects, existingPapers) {
            const subjectsList = $('#subjects-list');
            subjectsList.empty();

            if (subjects.length === 0) {
                subjectsList.html(`
                <tr>
                    <td colspan="3" class="empty-cell">
                        <i class="fa fa-folder-open-o fa-3x" style="color:#757575; margin-bottom: 1rem;"></i>
                        <p>No A-Level subjects found in the database.</p>
                    </td>
                </tr>
            `);
                return;
            }

            // Process each subject
            subjects.forEach(subject => {
                const subjectId = subject.subj_id;
                const subjectName = subject.subj_name;
                const subjectCode = subject.codea || subject.code || '';
                const subjectAbbr = subject.subj_abbr;

                // Get existing papers for this subject (if any)
                const subjectPapers = existingPapers[subjectName] || [];

                // Create subject row with paper checkboxes
                const papersHtml = generatePaperSelections(subjectId, subjectName, subjectPapers);

                // Determine status badge
                const statusBadge = getStatusBadge(subjectName, subjectPapers);

                // Count selected papers for sorting
                const paperCount = subjectPapers.length;

                const row = `
                <tr data-subject="${subjectName.toLowerCase()}" data-paper-count="${paperCount}" data-status="${subjectPapers.length === 0 ? 'required' : 'ready'}">
                    <td>
                        <span class="subject-name">${subjectName}</span>
                        <span class="subject-details">Code: ${subjectCode} | Abbr: ${subjectAbbr}</span>
                    </td>
                    <td>
                        ${papersHtml}
                    </td>
                    <td>
                        ${statusBadge}
                    </td>
                </tr>
            `;

                subjectsList.append(row);
            });

            // Set up event listeners for paper checkboxes
            setupPaperCheckboxes();
        }

        // Function to generate paper selection checkboxes for a subject
        function generatePaperSelections(subjectId, subjectName, selectedPapers) {
            const safeSubjectName = subjectName.toLowerCase().replace(/\s+/g, '-');

            return `
            <div class="paper-selection" data-subject-id="${subjectId}" data-subject-name="${subjectName}">
                <div>
                    <input type="checkbox" id="paper-${safeSubjectName}-1" 
                           class="paper-checkbox" 
                           data-subject="${subjectName}" 
                           data-paper="I" 
                           ${selectedPapers.includes('I') ? 'checked' : ''}>
                    <label class="paper-label" for="paper-${safeSubjectName}-1">I</label>
                </div>
                <div>
                    <input type="checkbox" id="paper-${safeSubjectName}-2" 
                           class="paper-checkbox" 
                           data-subject="${subjectName}" 
                           data-paper="II" 
                           ${selectedPapers.includes('II') ? 'checked' : ''}>
                    <label class="paper-label" for="paper-${safeSubjectName}-2">II</label>
                </div>
                <div>
                    <input type="checkbox" id="paper-${safeSubjectName}-3" 
                           class="paper-checkbox" 
                           data-subject="${subjectName}" 
                           data-paper="III" 
                           ${selectedPapers.includes('III') ? 'checked' : ''}>
                    <label class="paper-label" for="paper-${safeSubjectName}-3">III</label>
                </div>
                <div>
                    <input type="checkbox" id="paper-${safeSubjectName}-4" 
                           class="paper-checkbox" 
                           data-subject="${subjectName}" 
                           data-paper="IV" 
                           ${selectedPapers.includes('IV') ? 'checked' : ''}>
                    <label class="paper-label" for="paper-${safeSubjectName}-4">IV</label>
                </div>
            </div>
        `;
        }

        // Function to determine status badge
        function getStatusBadge(subjectName, selectedPapers) {
            if (selectedPapers.length === 0) {
                return '<span class="status-badge status-required">Required</span>';
            } else {
                return '<span class="status-badge status-ready">Ready</span>';
            }
        }

        // Function to update status badge after selection change
        function updateStatusBadge(subjectName) {
            const row = $(`tr[data-subject="${subjectName.toLowerCase()}"]`);
            const checkboxes = row.find('.paper-checkbox:checked');

            if (checkboxes.length === 0) {
                row.find('.status-badge').removeClass('status-ready').addClass('status-required').text('Required');
                row.attr('data-status', 'required');
                row.attr('data-paper-count', '0');
            } else {
                row.find('.status-badge').removeClass('status-required').addClass('status-ready').text('Ready');
                row.attr('data-status', 'ready');
                row.attr('data-paper-count', checkboxes.length);
            }
        }

        // Set up event listeners for paper checkboxes
        function setupPaperCheckboxes() {
            $('.paper-checkbox').change(function() {
                const checkbox = $(this);
                const subjectName = checkbox.data('subject');
                const paperValue = checkbox.data('paper');
                const isChecked = checkbox.prop('checked');

                // Update status badge
                updateStatusBadge(subjectName);

                // Auto save on change
                const paperSelection = checkbox.closest('.paper-selection');
                const subjectId = paperSelection.data('subject-id');

                // Send AJAX request to save/update
                savePaperSelection(subjectId, subjectName, paperValue, isChecked);

                // Re-sort table if we're sorting by paper count or status
                if (currentSort.column === 'papers' || currentSort.column === 'status') {
                    sortTable(currentSort.column, currentSort.direction);
                }
            });
        }

        // Save individual paper selection
        function savePaperSelection(subjectId, subjectName, paper, isChecked) {
            // Show loading overlay
            $('#loadingOverlay').fadeIn(200);

            // Prepare data for AJAX
            const data = {
                subject_id: subjectId,
                subject_name: subjectName,
                paper: paper,
                class: currentClass,
                action: isChecked ? 'add' : 'remove'
            };

            // Send AJAX request to update
            $.ajax({
                url: 'update_subject_paper.php',
                type: 'POST',
                data: data,
                dataType: 'json',
                success: function(response) {
                    $('#loadingOverlay').fadeOut(200);

                    if (response.success) {
                        showMessage(response.message, 'success');
                    } else {
                        // If there was an error, revert the checkbox state
                        $(`input[data-subject="${subjectName}"][data-paper="${paper}"]`).prop('checked', !isChecked);
                        updateStatusBadge(subjectName);
                        showMessage(response.message || 'Error updating paper selection', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $('#loadingOverlay').fadeOut(200);

                    // If there was an error, revert the checkbox state
                    $(`input[data-subject="${subjectName}"][data-paper="${paper}"]`).prop('checked', !isChecked);
                    updateStatusBadge(subjectName);
                    showMessage('Error connecting to server. Please try again.', 'error');
                }
            });
        }

        // Function to filter subjects based on search term
        function filterSubjects(searchTerm) {
            $('tr[data-subject]').each(function() {
                const subject = $(this).data('subject');
                if (subject.includes(searchTerm)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        }

        // Function to sort the table
        function sortTable(column, direction) {
            const tbody = $('#subjects-list');
            const rows = tbody.find('tr').toArray();

            // Update sort indicators in the table headers
            $('.data-table th').removeClass('sort-asc sort-desc');
            $(`.data-table th[data-sort="${column}"]`).addClass(`sort-${direction}`);

            // Update current sort state
            currentSort.column = column;
            currentSort.direction = direction;

            // Sort rows based on column and direction
            rows.sort((a, b) => {
                let aValue, bValue;

                if (column === 'subject') {
                    // Sort by subject name
                    aValue = $(a).data('subject').toString().toLowerCase();
                    bValue = $(b).data('subject').toString().toLowerCase();
                } else if (column === 'papers') {
                    // Sort by number of papers selected
                    aValue = parseInt($(a).data('paper-count')) || 0;
                    bValue = parseInt($(b).data('paper-count')) || 0;
                } else if (column === 'status') {
                    // Sort by status (required first or ready first)
                    aValue = $(a).data('status');
                    bValue = $(b).data('status');
                }

                // Compare values based on direction
                if (direction === 'asc') {
                    if (aValue < bValue) return -1;
                    if (aValue > bValue) return 1;
                    return 0;
                } else {
                    if (aValue > bValue) return -1;
                    if (aValue < bValue) return 1;
                    return 0;
                }
            });

            // Reappend sorted rows to table
            $.each(rows, function(index, row) {
                tbody.append(row);
            });
        }

        // Function to display messages
        function showMessage(message, type) {
            const messageContainer = $('#messageContainer');
            messageContainer.removeClass('success-message error-message info-message');

            let icon = '';
            if (type === 'success') {
                messageContainer.addClass('success-message');
                icon = 'fa-check-circle';
            } else if (type === 'info') {
                messageContainer.addClass('info-message');
                icon = 'fa-info-circle';
            } else {
                messageContainer.addClass('error-message');
                icon = 'fa-exclamation-circle';
            }

            messageContainer.html(`<i class="fa ${icon}"></i> ${message}`);
            messageContainer.css('animation', 'slideIn 0.3s forwards');
            messageContainer.show();

            // Hide message after 3 seconds
            setTimeout(function() {
                messageContainer.css('animation', 'slideOut 0.3s forwards');
                setTimeout(function() {
                    messageContainer.hide();
                }, 300);
            }, 3000);
        }

        // Reset all selections
        function resetSelections() {
            if (confirm('Are you sure you want to reset all paper selections for this class?')) {
                // Show loading overlay
                $('#loadingOverlay').fadeIn(200);

                // Send AJAX request to reset
                $.ajax({
                    url: 'reset_subject_papers.php',
                    type: 'POST',
                    data: {
                        class: currentClass
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#loadingOverlay').fadeOut(200);

                        if (response.success) {
                            showMessage('All paper selections have been reset', 'success');
                            loadSubjects(currentClass); // Reload subjects
                        } else {
                            showMessage(response.message || 'Error resetting paper selections', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#loadingOverlay').fadeOut(200);
                        showMessage('Error connecting to server. Please try again.', 'error');
                    }
                });
            }
        }

        // Document ready function
        $(document).ready(function() {
            // Initial load of subjects
            loadSubjects(currentClass);

            // Handle class selection change
            $('#class').change(function() {
                currentClass = $(this).val();
                loadSubjects(currentClass);
            });

            // Handle search
            $('#searchSubject').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();
                filterSubjects(searchTerm);
            });

            // Reset button
            $('#resetBtn').click(function() {
                resetSelections();
            });

            // Add sort functionality to table headers
            $('.data-table').on('click', 'th', function() {
                const column = $(this).data('sort');
                if (!column) return;

                let direction = 'asc';

                // Toggle direction if already sorted by this column
                if (currentSort.column === column) {
                    direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                }

                // Perform sort
                sortTable(column, direction);
            });
        });
    </script>
</body>

</html>