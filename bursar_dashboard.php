<?php
require_once 'conn.php';
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Bursar Dashboard");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bursar Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-green: #2e7d32;
            --dark-green: #1b5e20;
            --light-green: #81c784;
            --accent-green: #4caf50;
            --background: #f5f9f5;
        }

        body {
            background-color: var(--background);
        }

        .dashboard-header {
            margin-top: 65px;
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            padding: 1rem;
            border-radius: 10px 10px 10px 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 100%;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="rgba(255,255,255,0.1)"><path d="M30,10 Q50,5 70,20 T90,50 Q95,70 80,90 T50,95 Q30,90 20,70 T10,30 Q15,10 30,10 Z"/></svg>');
            background-repeat: no-repeat;
            background-position: right center;
            background-size: contain;
            opacity: 0.8;
        }

        .greeting-container {
            max-width: 800px;
            margin-left: 30px;
        }

        .greeting-text {
            font-size: 1rem;
            opacity: 0.9;
            margin-top: 0.5rem;
        }

        .date-badge {
            background-color: rgba(255, 255, 255, 0.15);
            padding: 0.20rem 0.60rem;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            margin-top: 0.5rem;
        }

        .date-badge i {
            margin-right: 0.5rem;
            font-size: 0.9rem;
        }

        .highlight {
            color: #fff;
        }


        .badge-green {
            background-color: var(--accent-green);
        }

        @media (max-width: 768px) {
            .dashboard-header {
                padding: 1.5rem 1rem;
            }

            .dashboard-header::before {
                width: 100px;
                opacity: 0.5;
            }

            .greeting-text {
                font-size: 1rem;
            }
        }

        /* Add this to your existing CSS, after the media queries */
        @keyframes slideInFromLeft {
            0% {
                transform: translateX(-100%);
                opacity: 0;
            }

            100% {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeInUp {
            0% {
                transform: translateY(30px);
                opacity: 0;
            }

            100% {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .greeting-container {
            animation: slideInFromLeft 0.8s ease-out;
        }

        .greeting-text {
            animation: fadeInUp 1s ease-out 0.3s both;
        }

        .date-badge {
            animation: fadeInUp 1s ease-out 0.6s both;
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php'; ?>
    <div class="container-fluid">
        <!-- Dashboard Header -->
        <div class="dashboard-header mb-4">
            <div class="greeting-container">
                <h3><i class="fas fa-tachometer-alt me-2"></i> Bursar Dashboard</h3>
                <p class="greeting-text mb-2" id="greeting">Loading greeting...</p>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i>
                    <span id="current-date"></span>
                </div>
            </div>
        </div>

        <!-- Dashboard content would go here -->

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Set current date with more formatting options
            const dateOptions = {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };

            const now = new Date();
            document.getElementById('current-date').textContent =
                now.toLocaleDateString('en-US', dateOptions).replace(' at', ' •');

            // Get current time and username
            const username = "<?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id']); ?>";
            const hour = now.getHours();

            // Define greeting based on time of day
            let timeGreeting;
            if (hour >= 5 && hour < 12) {
                timeGreeting = "Good morning";
            } else if (hour >= 12 && hour < 17) {
                timeGreeting = "Good afternoon";
            } else if (hour >= 17 && hour < 22) {
                timeGreeting = "Good evening";
            } else {
                timeGreeting = "Good night";
            }

            // Creative phrases for different times of day
            const phrases = {
                morning: [
                    "Here's what's brewing today.",
                    "Let's kickstart your day.",
                    "Your day is taking shape.",
                    "Rise and shine! Here's your morning update.",
                    "A fresh start with today's news.",
                    "Let's see what's in store.",
                    "Morning vibes and daily updates.",
                    "Seize the day with these updates."
                ],
                afternoon: [
                    "Here's the midday scoop.",
                    "Your day is in full swing.",
                    "Here's what's happening today.",
                    "Afternoon check-in: here's the latest.",
                    "Power through your day with these updates.",
                    "The sun is high, and so is the news flow.",
                    "Midday refresh: your update is here.",
                    "Afternoon delights and daily bites."
                ],
                evening: [
                    "As the day winds down, here's the latest.",
                    "Here's today's wrap-up.",
                    "Let's review today's highlights.",
                    "Evening edition: today's key moments.",
                    "Sunset stories from your day.",
                    "The day's final chapters are here.",
                    "Twilight tidbits to reflect on.",
                    "Evening winds bring today's roundup."
                ],
                night: [
                    "Before you sign off, here's a quick update.",
                    "Here's a nightcap of information.",
                    "One last look at today's events.",
                    "Nightly news to end your day.",
                    "Today's final thoughts before bed.",
                    "The moon is up, and here's your update.",
                    "Starry skies and today's goodnights.",
                    "Today's curtain call with these updates."
                ]
            };

            // Select phrase based on time
            let phrasesArray;
            if (hour >= 5 && hour < 12) {
                phrasesArray = phrases.morning;
            } else if (hour >= 12 && hour < 17) {
                phrasesArray = phrases.afternoon;
            } else if (hour >= 17 && hour < 22) {
                phrasesArray = phrases.evening;
            } else {
                phrasesArray = phrases.night;
            }

            const randomPhrase = phrasesArray[Math.floor(Math.random() * phrasesArray.length)];

            // Set the greeting text
            const greetingElement = document.getElementById('greeting');
            greetingElement.innerHTML = `<span class="highlight">${timeGreeting}, ${username}!</span> ${randomPhrase}`;
        });
    </script>
</body>

</html>