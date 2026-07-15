<?php
// analytics_export.php
require_once '../../includes/conn.php';

// Check if export is requested
if (!isset($_POST['export']) || $_POST['export'] !== 'pdf') {
    header('HTTP/1.0 400 Bad Request');
    echo 'Invalid export request';
    exit;
}

// Get form parameters
$class = $_POST['class'] ?? '';
$term = $_POST['term'] ?? '';
$year = $_POST['year'] ?? '';
$streams = $_POST['streams'] ?? [];
$subjects = $_POST['subjects'] ?? [];
$analysis_type = $_POST['analysis_type'] ?? 'overall';
$analysis_data = isset($_POST['analysis_data']) ? json_decode($_POST['analysis_data'], true) : null;

// Validate required parameters
if (empty($class) || empty($term) || empty($year) || empty($streams)) {
    header('HTTP/1.0 400 Bad Request');
    echo 'Missing required parameters';
    exit;
}

// If no analysis data provided, fetch fresh data
if (!$analysis_data) {
    // This would require calling the same logic as analytics_api.php
    // For now, we'll create a basic PDF with available data
    $analysis_data = [
        'summary' => ['total_students' => 0, 'class_average' => '0%', 'highest_score' => '0%', 'pass_rate' => '0%'],
        'gender' => ['male_count' => 0, 'female_count' => 0, 'male_average' => '0', 'female_average' => '0'],
        'top_performers' => [],
        'detailed_results' => []
    ];
}

// Create PDF content
$pdf_content = generatePDFContent($class, $term, $year, $streams, $subjects, $analysis_type, $analysis_data);

// Set headers for PDF download
$filename = "Performance_Analytics_" . str_replace(' ', '_', $class) . "_" . str_replace(' ', '_', $term) . "_" . $year . "_" . date('Y-m-d_H-i-s') . ".pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Simple PDF generation using HTML to PDF conversion
// Note: For production, consider using libraries like TCPDF, FPDF, or mPDF
echo generateSimplePDF($pdf_content, $filename);

function generatePDFContent($class, $term, $year, $streams, $subjects, $analysis_type, $data) {
    $streams_text = is_array($streams) ? implode(', ', $streams) : $streams;
    $subjects_text = is_array($subjects) && !in_array('All Subjects', $subjects) ? implode(', ', $subjects) : 'All Subjects';
    
    $content = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Performance Analytics Report</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                font-size: 12px;
                line-height: 1.4;
                margin: 20px;
                color: #333;
            }
            .header {
                text-align: center;
                border-bottom: 2px solid #4a9eff;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            .school-name {
                font-size: 18px;
                font-weight: bold;
                color: #2c3e50;
                margin-bottom: 5px;
            }
            .report-title {
                font-size: 16px;
                color: #4a9eff;
                margin-bottom: 10px;
            }
            .report-params {
                font-size: 11px;
                color: #6c757d;
                margin-top: 10px;
            }
            .section {
                margin-bottom: 25px;
                page-break-inside: avoid;
            }
            .section-title {
                font-size: 14px;
                font-weight: bold;
                color: #2c3e50;
                border-bottom: 1px solid #ddd;
                padding-bottom: 5px;
                margin-bottom: 15px;
            }
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
                margin-bottom: 20px;
            }
            .stat-item {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 3px;
                text-align: center;
                border-left: 3px solid #4a9eff;
            }
            .stat-label {
                font-size: 10px;
                color: #6c757d;
                text-transform: uppercase;
                margin-bottom: 5px;
            }
            .stat-value {
                font-size: 18px;
                font-weight: bold;
                color: #2c3e50;
            }
            .two-column {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }
            .performer-item {
                background: #fff;
                border: 1px solid #e9ecef;
                border-radius: 3px;
                padding: 10px;
                margin-bottom: 8px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .performer-rank {
                background: #4a9eff;
                color: white;
                border-radius: 50%;
                width: 25px;
                height: 25px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                font-size: 11px;
            }
            .performer-info {
                flex-grow: 1;
                margin-left: 10px;
            }
            .performer-name {
                font-weight: bold;
                font-size: 11px;
            }
            .performer-details {
                font-size: 10px;
                color: #6c757d;
            }
            .performer-score {
                font-weight: bold;
                color: #28a745;
            }
            .gender-stats {
                display: flex;
                justify-content: space-around;
                margin-bottom: 15px;
            }
            .gender-stat {
                text-align: center;
                padding: 15px;
                border-radius: 3px;
                flex: 1;
                margin: 0 5px;
            }
            .gender-stat.male {
                background: #4a9eff;
                color: white;
            }
            .gender-stat.female {
                background: #e91e63;
                color: white;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
            }
            .table th,
            .table td {
                border: 1px solid #dee2e6;
                padding: 8px;
                text-align: center;
                font-size: 10px;
            }
            .table th {
                background: #4a9eff;
                color: white;
                font-weight: bold;
            }
            .table tbody tr:nth-child(even) {
                background: #f8f9fa;
            }
            .footer {
                margin-top: 40px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
                text-align: center;
                font-size: 10px;
                color: #6c757d;
            }
            @media print {
                body { margin: 0; }
                .section { page-break-inside: avoid; }
            }
        </style>
    </head>
    <body>
        <div class='header'>
            <div class='school-name'>MACKAY MEMORIAL COLLEGE, NATEETE</div>
            <div class='report-title'>STUDENT PERFORMANCE ANALYTICS REPORT</div>
            <div class='report-params'>
                <strong>Class:</strong> " . htmlspecialchars($class) . " | 
                <strong>Term:</strong> " . htmlspecialchars($term) . " | 
                <strong>Year:</strong> " . htmlspecialchars($year) . " | 
                <strong>Streams:</strong> " . htmlspecialchars($streams_text) . "<br>
                <strong>Subjects:</strong> " . htmlspecialchars($subjects_text) . " | 
                <strong>Analysis Type:</strong> " . htmlspecialchars($analysis_type) . " | 
                <strong>Generated:</strong> " . date('F j, Y \a\t g:i A') . "
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class='section'>
            <div class='section-title'>Summary Statistics</div>
            <div class='stats-grid'>
                <div class='stat-item'>
                    <div class='stat-label'>Total Students</div>
                    <div class='stat-value'>" . htmlspecialchars($data['summary']['total_students']) . "</div>
                </div>
                <div class='stat-item'>
                    <div class='stat-label'>Class Average</div>
                    <div class='stat-value'>" . htmlspecialchars($data['summary']['class_average']) . "</div>
                </div>
                <div class='stat-item'>
                    <div class='stat-label'>Highest Score</div>
                    <div class='stat-value'>" . htmlspecialchars($data['summary']['highest_score']) . "</div>
                </div>
                <div class='stat-item'>
                    <div class='stat-label'>Pass Rate</div>
                    <div class='stat-value'>" . htmlspecialchars($data['summary']['pass_rate']) . "</div>
                </div>
            </div>
        </div>

        <!-- Performance Analysis -->
        <div class='section'>
            <div class='section-title'>Performance Analysis</div>
            <div class='two-column'>
                <div>
                    <h4>Top Performers</h4>";
                    
    if (!empty($data['top_performers'])) {
        foreach ($data['top_performers'] as $performer) {
            $content .= "
                    <div class='performer-item'>
                        <div class='performer-rank'>" . htmlspecialchars($performer['rank']) . "</div>
                        <div class='performer-info'>
                            <div class='performer-name'>" . htmlspecialchars($performer['name']) . "</div>
                            <div class='performer-details'>" . htmlspecialchars($performer['details']) . "</div>
                        </div>
                        <div class='performer-score'>" . htmlspecialchars($performer['score']) . "</div>
                    </div>";
        }
    } else {
        $content .= "<p>No performance data available</p>";
    }
    
    $content .= "
                </div>
                <div>
                    <h4>Gender Analysis</h4>
                    <div class='gender-stats'>
                        <div class='gender-stat male'>
                            <div style='font-size: 11px; opacity: 0.9;'>Male Students</div>
                            <div style='font-size: 18px; font-weight: bold; margin: 5px 0;'>" . htmlspecialchars($data['gender']['male_count']) . "</div>
                            <div style='font-size: 10px;'>Avg: " . htmlspecialchars($data['gender']['male_average']) . "%</div>
                        </div>
                        <div class='gender-stat female'>
                            <div style='font-size: 11px; opacity: 0.9;'>Female Students</div>
                            <div style='font-size: 18px; font-weight: bold; margin: 5px 0;'>" . htmlspecialchars($data['gender']['female_count']) . "</div>
                            <div style='font-size: 10px;'>Avg: " . htmlspecialchars($data['gender']['female_average']) . "%</div>
                        </div>
                    </div>
                    <div style='text-align: center; margin-top: 15px;'>
                        <strong>Performance Difference:</strong><br>
                        " . htmlspecialchars($data['gender']['difference']) . "
                    </div>
                </div>
            </div>
        </div>";

    // Detailed Results Table
    if (!empty($data['detailed_results'])) {
        $content .= "
        <div class='section'>
            <div class='section-title'>Detailed Results</div>
            <table class='table'>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Student Name</th>
                        <th>Student ID</th>
                        <th>Gender</th>
                        <th>Stream</th>
                        <th>Score (%)</th>
                        <th>Grade</th>
                        <th>Subjects</th>
                    </tr>
                </thead>
                <tbody>";
        
        foreach ($data['detailed_results'] as $student) {
            $content .= "
                    <tr>
                        <td>" . htmlspecialchars($student['rank']) . "</td>
                        <td>" . htmlspecialchars($student['name']) . "</td>
                        <td>" . htmlspecialchars($student['id']) . "</td>
                        <td>" . htmlspecialchars($student['gender']) . "</td>
                        <td>" . htmlspecialchars($student['stream']) . "</td>
                        <td>" . htmlspecialchars($student['score']) . "</td>
                        <td>" . htmlspecialchars($student['grade']) . "</td>
                        <td>" . htmlspecialchars($student['subjects']) . "</td>
                    </tr>";
        }
        
        $content .= "
                </tbody>
            </table>
        </div>";
    }

    $content .= "
        <div class='footer'>
            <p>This report was automatically generated by the Student Performance Analytics System</p>
            <p>Mackay Memorial College, Nateete - Academic Year " . htmlspecialchars($year) . "</p>
        </div>
    </body>
    </html>";

    return $content;
}

function generateSimplePDF($html_content, $filename) {
    // Simple HTML to PDF conversion
    // Note: This is a basic implementation. For production, use proper PDF libraries
    
    // Check if wkhtmltopdf is available (recommended for production)
    if (function_exists('shell_exec') && !empty(shell_exec('which wkhtmltopdf'))) {
        // Create temporary HTML file
        $temp_html = tempnam(sys_get_temp_dir(), 'analytics_') . '.html';
        file_put_contents($temp_html, $html_content);
        
        // Generate PDF using wkhtmltopdf
        $temp_pdf = tempnam(sys_get_temp_dir(), 'analytics_') . '.pdf';
        $command = "wkhtmltopdf --page-size A4 --orientation Portrait --margin-top 15mm --margin-bottom 15mm --margin-left 10mm --margin-right 10mm '$temp_html' '$temp_pdf' 2>/dev/null";
        shell_exec($command);
        
        if (file_exists($temp_pdf) && filesize($temp_pdf) > 0) {
            $pdf_content = file_get_contents($temp_pdf);
            unlink($temp_html);
            unlink($temp_pdf);
            return $pdf_content;
        }
        
        // Clean up temporary files
        unlink($temp_html);
        if (file_exists($temp_pdf)) {
            unlink($temp_pdf);
        }
    }
    
    // Fallback: Use TCPDF library if available
    if (class_exists('TCPDF')) {
        return generateTCPDF($html_content, $filename);
    }
    
    // Final fallback: Return HTML with PDF headers (browser will handle conversion)
    return $html_content;
}

function generateTCPDF($html_content, $filename) {
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Student Analytics System');
    $pdf->SetAuthor('Mackay Memorial College');
    $pdf->SetTitle('Performance Analytics Report');
    $pdf->SetSubject('Student Performance Analysis');
    
    // Set default header data
    $pdf->SetHeaderData('', 0, 'Performance Analytics Report', 'Generated on ' . date('Y-m-d H:i:s'));
    
    // Set header and footer fonts
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Set margins
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    
    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    
    // Add a page
    $pdf->AddPage();
    
    // Write HTML content
    $pdf->writeHTML($html_content, true, false, true, false, '');
    
    // Close and output PDF document
    return $pdf->Output('', 'S');
}

// Alternative simple PDF generation using DomPDF (if available)
function generateDomPDF($html_content, $filename) {
    if (class_exists('Dompdf\Dompdf')) {
        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml($html_content);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }
    return false;
}

// If no PDF library is available, try DomPDF as another fallback
if (!function_exists('shell_exec') || empty(shell_exec('which wkhtmltopdf'))) {
    if (!class_exists('TCPDF')) {
        $dompdf_output = generateDomPDF($pdf_content, $filename);
        if ($dompdf_output !== false) {
            echo $dompdf_output;
            exit;
        }
    }
}
?>