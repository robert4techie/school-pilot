<?php
require_once "auth.php";
require_once 'conn.php'; 

$classes = [];
$sql = "SELECT DISTINCT current_class FROM students WHERE current_class IS NOT NULL AND current_class != '' ORDER BY current_class ASC";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
} else {
    die("Error: Could not fetch classes. " . $conn->error);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Class for Attendance - School Pilot</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body { 
            background: linear-gradient(135deg, #f0f2f5 0%, #e8eaf0 100%); 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            margin: 0; 
            padding: 20px;
            position: relative;
        }

        /* Background decoration */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(46, 125, 50, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(76, 175, 80, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 40% 80%, rgba(46, 125, 50, 0.03) 0%, transparent 50%);
            pointer-events: none;
            z-index: -1;
        }

        .selection-box { 
            background: white; 
            padding: 40px 45px; 
            border-radius: 20px; 
            box-shadow: 
                0 10px 40px rgba(0,0,0,0.08),
                0 4px 15px rgba(0,0,0,0.05),
                inset 0 1px 0 rgba(255,255,255,0.8); 
            width: 100%; 
            max-width: 520px;
            position: relative;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .selection-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, #4caf50, #66bb6a);
            border-radius: 0 0 10px 10px;
        }

        .selection-box h2 { 
            text-align: center; 
            color: #2e7d32; 
            margin-bottom: 30px; 
            font-size: 28px;
            font-weight: 700;
            position: relative;
            padding-bottom: 15px;
        }

        .selection-box h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 2px;
            background: linear-gradient(90deg, transparent, #4caf50, transparent);
        }

        .form-group { 
            margin-bottom: 25px; 
            position: relative;
        }

        .form-group label { 
            display: block; 
            margin-bottom: 10px; 
            font-weight: 600; 
            color: #555; 
            font-size: 15px;
            position: relative;
            padding-left: 8px;
        }

        .form-group label::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 16px;
            background: linear-gradient(180deg, #4caf50, #66bb6a);
            border-radius: 2px;
        }

        .form-control { 
            width: 100%; 
            padding: 16px 20px; 
            border: 2px solid #e0e0e0; 
            border-radius: 12px; 
            font-size: 16px; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            background: linear-gradient(145deg, #ffffff, #f8f9fa);
            -webkit-appearance: none; 
            -moz-appearance: none; 
            appearance: none;
            position: relative;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .form-control:focus { 
            outline: none; 
            border-color: #4caf50; 
            box-shadow: 
                0 0 0 4px rgba(76, 175, 80, 0.15),
                0 4px 12px rgba(0,0,0,0.1); 
            transform: translateY(-1px);
            background: #ffffff;
        }

        .form-control:disabled { 
            background: linear-gradient(145deg, #f5f5f5, #eeeeee); 
            cursor: not-allowed; 
            color: #999;
            border-color: #e8e8e8;
        }

        .form-control:hover:not(:disabled) {
            border-color: #c8e6c9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        /* Custom dropdown arrow */
        .form-group::after {
            content: '▼';
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: #4caf50;
            pointer-events: none;
            font-size: 12px;
            margin-top: 15px;
        }

        .button-group {
            display: flex;
            gap: 20px;
            margin-top: 35px;
        }

        .btnn {
            flex-grow: 1;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 700;
            color: white;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .btnn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btnn:hover::before {
            left: 100%;
        }

        .btnn:active {
            transform: translateY(2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .btnn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .btnn-record { 
            background: linear-gradient(135deg, #4caf50 0%, #45a049 100%); 
        }
        
        .btnn-record:hover:not(:disabled) { 
            background: linear-gradient(135deg, #45a049 0%, #3d8b40 100%);
        }

        .btnn-view { 
            background: linear-gradient(135deg, #337ab7 0%, #286090 100%); 
        }
        
        .btnn-view:hover:not(:disabled) { 
            background: linear-gradient(135deg, #286090 0%, #1e4f72 100%);
        }

        .btnn:disabled { 
            background: linear-gradient(135deg, #a5d6a7 0%, #90c695 100%); 
            cursor: not-allowed; 
            opacity: 0.6;
            transform: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .btnn:disabled::before {
            display: none;
        }

        /* Icon additions */
        .btnn-record::after {
            content: '📝';
            margin-left: 8px;
        }

        .btnn-view::after {
            content: '📊';
            margin-left: 8px;
        }

        /* Loading animation for select */
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .form-control:disabled {
            animation: pulse 1.5s infinite;
        }

        /* Responsive design */
        @media (max-width: 600px) {
            .selection-box {
                padding: 30px 25px;
                margin: 10px;
            }

            .button-group {
                flex-direction: column;
                gap: 15px;
            }

            .selection-box h2 {
                font-size: 24px;
            }

            .btnn {
                padding: 16px 20px;
                font-size: 15px;
            }
        }

        /* Subtle animations on load */
        .selection-box {
            animation: fadeInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-group {
            animation: slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation-fill-mode: both;
        }

        .form-group:nth-child(1) { animation-delay: 0.1s; }
        .form-group:nth-child(2) { animation-delay: 0.2s; }
        .button-group { 
            animation: slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation-delay: 0.3s;
            animation-fill-mode: both;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>
    <?php if (file_exists('nav.php')) { require_once "nav.php"; } ?>
    <div class="selection-box">
        <h2>Select Action</h2>
        
        <!-- The form now has an ID for JavaScript to target -->
        <form id="attendanceForm" method="GET">
            <div class="form-group">
                <label for="classFilter">Class</label>
                <select id="classFilter" name="class" class="form-control" required>
                    <option value="">-- Select a Class --</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= htmlspecialchars($class['current_class']) ?>">
                            <?= htmlspecialchars($class['current_class']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="streamFilter">Stream</label>
                <select id="streamFilter" name="stream" class="form-control" required disabled>
                    <option value="">-- First Select a Class --</option>
                </select>
            </div>
            
            <!-- NEW: Two buttons for the two different actions -->
            <div class="button-group">
                <button type="button" id="recordbtnn" class="btnn btnn-record" disabled>Record Attendance</button>
                <button type="button" id="viewbtnn" class="btnn btnn-view" disabled>View Attendance</button>
            </div>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const attendanceForm = document.getElementById('attendanceForm');
        const classFilter = document.getElementById('classFilter');
        const streamFilter = document.getElementById('streamFilter');
        const recordbtnn = document.getElementById('recordbtnn');
        const viewbtnn = document.getElementById('viewbtnn');

        // Function to enable/disable action buttons
        const toggleActionButtons = (enabled) => {
            recordbtnn.disabled = !enabled;
            viewbtnn.disabled = !enabled;
        };

        classFilter.addEventListener('change', function() {
            const selectedClass = this.value;
            
            streamFilter.innerHTML = '<option value="">-- Loading Streams... --</option>';
            streamFilter.disabled = true;
            toggleActionButtons(false); // Disable buttons when class changes

            if (selectedClass) {
                // Fetch streams for the selected class
                fetch(`get_streams.php?class=${encodeURIComponent(selectedClass)}`)
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok');
                        return response.json();
                    })
                    .then(data => {
                        if (data.success && data.streams.length > 0) {
                            streamFilter.innerHTML = '<option value="">-- Select a Stream --</option>';
                            data.streams.forEach(s => {
                                const option = document.createElement('option');
                                option.value = s.stream;
                                option.textContent = s.stream;
                                streamFilter.appendChild(option);
                            });
                            streamFilter.disabled = false;
                        } else {
                            streamFilter.innerHTML = '<option value="">-- No Streams Found --</option>';
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching streams:', error);
                        streamFilter.innerHTML = '<option value="">-- Error Loading --</option>';
                    });
            } else {
                 streamFilter.innerHTML = '<option value="">-- First Select a Class --</option>';
            }
        });

        streamFilter.addEventListener('change', function() {
            // Enable buttons only if both a class and a stream are selected
            if (this.value && classFilter.value) {
                toggleActionButtons(true);
            } else {
                toggleActionButtons(false);
            }
        });

        // --- NEW: Event listeners for the action buttons ---

        // When "Record Attendance" is clicked
        recordbtnn.addEventListener('click', function() {
            // Set the form's action and submit
            attendanceForm.action = 'record_attendance.php';
            attendanceForm.submit();
        });

        // When "View Report" is clicked
        viewbtnn.addEventListener('click', function() {
            // Set the form's action and submit
            attendanceForm.action = 'view_attendance_report.php';
            attendanceForm.submit();
        });
    });
    </script>
</body>
</html>