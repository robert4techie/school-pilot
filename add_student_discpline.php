<?php
require_once 'conn.php';
require_once "auth.php";
require_once 'tracking.php';
$tracker->trackAction("Add Student Discpline");

// Fetch classes and streams for dropdowns
$classes = array();
$streams = array();

$class_query = "SELECT DISTINCT current_class FROM students ORDER BY current_class";
$stream_query = "SELECT DISTINCT stream FROM students ORDER BY stream";

$class_result = mysqli_query($conn, $class_query);
while ($row = mysqli_fetch_assoc($class_result)) {
    $classes[] = $row['current_class'];
}

$stream_result = mysqli_query($conn, $stream_query);
while ($row = mysqli_fetch_assoc($stream_result)) {
    $streams[] = $row['stream'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Behavior Recording</title>
    <!-- Include Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Include Noty for notifications -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.js"></script>
    <style>
        /* Green Theme for School Pilot */
        :root {
            --primary-green: #2e7d32;
            --secondary-green: #4caf50;
            --light-green: #8bc34a;
            --dark-green: #1b5e20;
            --accent-green: #a5d6a7;
            --text-dark: #333;
            --text-light: #f5f5f5;
            --border-radius: 6px;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        body {
            background-color: #f0f4f0;
        }

        .behavior-form-container {
            max-width: 100%;
            margin: 20px auto;
            margin-top: 60px;
        }

        .behavior-form {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
        }

        .form-header {
            margin-bottom: 25px;
            border-bottom: 2px solid var(--accent-green);
            padding-bottom: 15px;
        }

        .form-header h2 {
            color: var(--primary-green);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-header p {
            color: var(--text-dark);
            margin: 5px 0 0;
            opacity: 0.8;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .form-section {
            background: #f9f9f9;
            padding: 20px;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--secondary-green);
        }

        .form-section h3 {
            color: var(--dark-green);
            margin-top: 0;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="datetime-local"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 15px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }

        .form-group input[readonly] {
            background-color: #e9ecef;
            cursor: not-allowed;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--light-green);
            outline: none;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .triple-column {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .select-wrapper {
            position: relative;
        }

        .select-wrapper::after {
            content: "▼";
            font-size: 12px;
            color: var(--primary-green);
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .type-selector {
            display: flex;
            gap: 15px;
            margin-top: 8px;
        }

        .type-selector input[type="radio"] {
            display: none;
        }

        .type-selector label {
            flex: 1;
            padding: 10px;
            text-align: center;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .positive-label {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            color: var(--dark-green);
        }

        .negative-label {
            background: #ffebee;
            border: 1px solid #ef9a9a;
            color: #c62828;
        }

        .type-selector input[type="radio"]:checked+.positive-label {
            background: var(--accent-green);
            border-color: var(--secondary-green);
            font-weight: bold;
        }

        .type-selector input[type="radio"]:checked+.negative-label {
            background: #ef9a9a;
            border-color: #c62828;
            color: white;
            font-weight: bold;
        }

        .behavior-categories,
        .action-suggestions {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .behavior-tag,
        .action-tag {
            padding: 4px 10px;
            background: #e8f5e9;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #c8e6c9;
        }

        .behavior-tag:hover,
        .action-tag:hover {
            background: var(--accent-green);
        }

        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .submit-btn {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .submit-btn:hover {
            background: var(--dark-green);
        }

        .noty_theme__mint.noty_type__success {
            background-color: #12b623ff;
            border-color: #1b5e20;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .triple-column {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php'; ?>

    <div class="behavior-form-container">
        <form id="behaviorForm" method="POST" action="api/save_behavior.php" class="behavior-form">
            <div class="form-header">
                <h2><i class="fas fa-clipboard-check"></i> Record Student Behavior</h2>
                <p>Document positive or negative student behaviors for tracking and intervention</p>
            </div>

            <div class="form-grid">
                <!-- Student Selection Section -->
                <div class="form-section">
                    <h3><i class="fas fa-user-graduate"></i> Student Information</h3>

                    <div class="form-group">
                        <label for="student_id">Student Name</label>
                        <select id="student_id" name="student_id" required>
                            <option value="">Select Student Name</option>
                            <!-- Student options will be loaded by JavaScript -->
                        </select>
                    </div>

                    <div class="triple-column">
                        <div class="form-group">
                            <label for="student_id_display">Student ID</label>
                            <input type="text" id="student_id_display" name="student_id_display" readonly>
                        </div>
                        <div class="form-group">
                            <label for="class">Class</label>
                            <input type="text" id="class" name="class" placeholder="Auto-filled" readonly required>
                        </div>
                        <div class="form-group">
                            <label for="stream">Stream</label>
                            <input type="text" id="stream" name="stream" placeholder="Auto-filled" readonly required>
                        </div>
                    </div>
                </div>

                <!-- Behavior Details Section -->
                <div class="form-section">
                    <h3><i class="fas fa-exclamation-circle"></i> Behavior Details</h3>
                    <div class="form-group">
                        <label for="type">Behavior Type</label>
                        <div class="type-selector">
                            <input type="radio" id="type_positive" name="type" value="Positive" required>
                            <label for="type_positive" class="positive-label">
                                <i class="fas fa-thumbs-up"></i> Positive
                            </label>
                            <input type="radio" id="type_negative" name="type" value="Negative" required>
                            <label for="type_negative" class="negative-label">
                                <i class="fas fa-thumbs-down"></i> Negative
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" placeholder="Describe the behavior in detail (what happened, where, when, etc.)" required></textarea>
                        <div class="behavior-categories">
                            <small>Common behaviors: </small>
                            <span class="behavior-tag" data-value="Bullying">Bullying</span>
                            <span class="behavior-tag" data-value="Disrespect">Disrespect</span>
                            <span class="behavior-tag" data-value="Tardiness">Tardiness</span>
                            <span class="behavior-tag" data-value="Helpfulness">Helpfulness</span>
                            <span class="behavior-tag" data-value="Leadership">Leadership</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="date_occurred">Date & Time Occurred</label>
                        <input type="datetime-local" id="date_occurred" name="date_occurred" required>
                    </div>
                </div>

                <!-- Reporter Information -->
                <div class="form-section">
                    <h3><i class="fas fa-user-tie"></i> Reporter & Action</h3>
                    <div class="form-group">
                        <label for="reporter_name">Reported By</label>
                        <input type="text" id="reporter_name" name="reporter_name" placeholder="e.g., Tumwesige Robertson (Teacher)" required>
                    </div>
                    <div class="form-group">
                        <label for="action_taken">Immediate Action Taken</label>
                        <textarea id="action_taken" name="action_taken" placeholder="Describe any immediate actions taken (e.g., warning, counseling, commendation)" required></textarea>
                        <div class="action-suggestions">
                            <small>Common actions: </small>
                            <span class="action-tag" data-value="Verbal warning">Verbal warning</span>
                            <span class="action-tag" data-value="Written warning">Written warning</span>
                            <span class="action-tag" data-value="Parent notified">Parent notified</span>
                            <span class="action-tag" data-value="Commendation">Commendation</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="follow_up">Recommended Follow-up</label>
                        <textarea id="follow_up" name="follow_up" placeholder="Suggest any follow-up actions needed"></textarea>
                    </div>
                </div>
            </div>

            <div class="form-footer">
                <button type="submit" name="submit" class="submit-btn">
                    <i class="fas fa-save"></i> Save Behavior Record
                </button>
            </div>
        </form>
    </div>

  <script>
document.addEventListener('DOMContentLoaded', function() {
    // Configure notifications
    Noty.overrideDefaults({
        layout: 'topRight',
        theme: 'mint',
        timeout: 3000,
        progressBar: true
    });

    // Load all students into the dropdown on page load
    loadAllStudents();

    // Event listener for student selection
    document.getElementById('student_id').addEventListener('change', function() {
        console.log('Student selection changed. Selected value:', this.value);
        console.log('Selected option:', this.options[this.selectedIndex]);
        
        if (this.value) {
            getStudentInfo(this.options[this.selectedIndex]);
        } else {
            // Clear fields if no student is selected
            document.getElementById('student_id_display').value = '';
            document.getElementById('class').value = '';
            document.getElementById('stream').value = '';
        }
    });

    // Set default date to current date and time
    const dateField = document.getElementById('date_occurred');
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    dateField.value = now.toISOString().slice(0, 16);

    // Form submission handler
    document.getElementById('behaviorForm').addEventListener('submit', function(e) {
        e.preventDefault();
        submitBehaviorForm();
    });

    // --- Helper functions for tags ---
    document.querySelectorAll('.behavior-tag').forEach(tag => {
        tag.addEventListener('click', function() {
            const textarea = document.getElementById('description');
            textarea.value += (textarea.value ? ', ' : '') + this.getAttribute('data-value');
        });
    });

    document.querySelectorAll('.action-tag').forEach(tag => {
        tag.addEventListener('click', function() {
            const textarea = document.getElementById('action_taken');
            textarea.value += (textarea.value ? ', ' : '') + this.getAttribute('data-value');
        });
    });

    // --- Core Functions ---

    function loadAllStudents() {
        console.log('Loading all students...');
        fetch('api/get_students.php?all=true')
            .then(response => response.json())
            .then(data => {
                console.log('Students API response:', data);
                
                if (data.status === 'success') {
                    const studentSelect = document.getElementById('student_id');
                    studentSelect.innerHTML = '<option value="">Select Student Name</option>';

                    data.data.forEach(student => {
                        console.log('Processing student:', student);
                        
                        const option = document.createElement('option');
                        option.value = student.student_id;
                        option.textContent = student.name;
                        option.setAttribute('data-class', student.current_class);
                        option.setAttribute('data-stream', student.stream);
                        option.setAttribute('data-studentid', student.student_id);
                        
                        console.log('Created option with value:', option.value, 'for student:', student.name);
                        
                        studentSelect.appendChild(option);
                    });
                    
                    console.log('Total options added:', studentSelect.options.length - 1); // -1 for the default option
                } else {
                    console.error('Failed to load students:', data.message);
                    showNotification('error', data.message || 'Failed to load students');
                }
            })
            .catch(error => {
                console.error('Error loading students:', error);
                showNotification('error', 'Failed to load students. Check network or endpoint.');
            });
    }

    function getStudentInfo(selectedOption) {
        const studentIdStr = selectedOption.getAttribute('data-studentid');
        const studentClass = selectedOption.getAttribute('data-class');
        const studentStream = selectedOption.getAttribute('data-stream');

        console.log('Getting student info:', {
            selectedValue: selectedOption.value,
            dataStudentId: studentIdStr,
            class: studentClass,
            stream: studentStream
        });

        document.getElementById('student_id_display').value = studentIdStr || '';
        document.getElementById('class').value = studentClass || '';
        document.getElementById('stream').value = studentStream || '';
    }

    function submitBehaviorForm() {
        const form = document.getElementById('behaviorForm');
        const formData = new FormData(form);
        
        console.log('=== FORM SUBMISSION DEBUG ===');
        console.log('Selected student option value:', document.getElementById('student_id').value);
        console.log('Selected student option text:', document.getElementById('student_id').options[document.getElementById('student_id').selectedIndex].text);
        
        // Debug: Log what's being submitted
        console.log('Form data being submitted:');
        for (let [key, value] of formData.entries()) {
            console.log(key + ':', value);
        }
        console.log('=== END DEBUG ===');
        
        const loadingNotification = showNotification('info', 'Saving record...', false);

        fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loadingNotification.close();
                console.log('Server response:', data);
                
                if (data.status === 'success') {
                    showNotification('success', data.message || 'Record saved successfully!');
                    form.reset();
                    const now = new Date();
                    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                    document.getElementById('date_occurred').value = now.toISOString().slice(0, 16);
                } else {
                    showNotification('error', data.message || 'Failed to save record.');
                }
            })
            .catch(error => {
                loadingNotification.close();
                console.error('Error submitting form:', error);
                showNotification('error', 'A network error occurred. Please try again.');
            });
    }

    function showNotification(type, message, autoClose = true) {
        return new Noty({
            type: type,
            text: message,
            timeout: autoClose ? 3000 : false,
        }).show();
    }
});
</script>
</body>

</html>