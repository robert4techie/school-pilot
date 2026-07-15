<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        /* Header */
        .header {
            background: linear-gradient(45deg, #2c3e50, #34495e);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Navigation */
        .nav-tabs {
            display: flex;
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
            overflow-x: auto;
        }

        .nav-tab {
            padding: 15px 25px;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 1rem;
            color: #495057;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }

        .nav-tab:hover {
            background: #e9ecef;
            color: #007bff;
        }

        .nav-tab.active {
            background: white;
            color: #007bff;
            border-bottom-color: #007bff;
        }

        .nav-tab i {
            margin-right: 8px;
        }

        /* Content Area */
        .content {
            padding: 30px;
            min-height: 600px;
        }

        /* Dashboard Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid #e9ecef;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-right: 15px;
        }

        .card-icon.blue { background: linear-gradient(45deg, #007bff, #0056b3); }
        .card-icon.green { background: linear-gradient(45deg, #28a745, #1e7e34); }
        .card-icon.orange { background: linear-gradient(45deg, #fd7e14, #e55a00); }
        .card-icon.purple { background: linear-gradient(45deg, #6f42c1, #5a32a3); }
        .card-icon.red { background: linear-gradient(45deg, #dc3545, #b02a37); }
        .card-icon.teal { background: linear-gradient(45deg, #20c997, #17a085); }

        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
        }

        .card-content {
            color: #6c757d;
            line-height: 1.6;
        }

        /* Stats */
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin: 10px 0;
        }

        /* Tables */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: linear-gradient(45deg, #2c3e50, #34495e);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        tr:hover {
            background: #f8f9fa;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c3e50;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }

        /* Buttons */
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 10px;
            margin-bottom: 10px;
        }

        .btn-primary {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
        }

        .btn-success {
            background: linear-gradient(45deg, #28a745, #1e7e34);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(45deg, #dc3545, #b02a37);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Status badges */
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-tabs {
                flex-direction: column;
            }
            
            .header h1 {
                font-size: 2rem;
            }
        }

        /* Page-specific styles */
        .page-content {
            display: none;
        }

        .page-content.active {
            display: block;
        }

        /* Charts placeholder */
        .chart-container {
            height: 300px;
            background: #f8f9fa;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-utensils"></i> Kitchen Management System</h1>
            <p>Professional Kitchen Operations Dashboard</p>
        </div>

        <div class="nav-tabs">
            <button class="nav-tab active" onclick="showPage('dashboard')">
                <i class="fas fa-tachometer-alt"></i>Dashboard
            </button>
            <button class="nav-tab" onclick="showPage('inventory')">
                <i class="fas fa-warehouse"></i>Food Inventory
            </button>
            <button class="nav-tab" onclick="showPage('planner')">
                <i class="fas fa-clipboard-list"></i>Meal Planner
            </button>
            <button class="nav-tab" onclick="showPage('diets')">
                <i class="fas fa-heart"></i>Special Diets
            </button>
            <button class="nav-tab" onclick="showPage('staff')">
                <i class="fas fa-users"></i>Kitchen Staff
            </button>
            <button class="nav-tab" onclick="showPage('reports')">
                <i class="fas fa-chart-pie"></i>Reports
            </button>
        </div>

        <div class="content">
            <!-- Kitchen Dashboard -->
            <div id="dashboard" class="page-content active">
                <div class="dashboard-grid">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-icon blue">
                                <i class="fas fa-box"></i>
                            </div>
                            <div>
                                <div class="card-title">Total Inventory Items</div>
                            </div>
                        </div>
                        <div class="card-content">
                            <div class="stat-number">1,247</div>
                            <p>Items currently in stock</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-icon green">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div>
                                <div class="card-title">Meals Planned</div>
                            </div>
                        </div>
                        <div class="card-content">
                            <div class="stat-number">156</div>
                            <p>Meals scheduled this week</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-icon orange">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div>
                                <div class="card-title">Low Stock Alerts</div>
                            </div>
                        </div>
                        <div class="card-content">
                            <div class="stat-number">23</div>
                            <p>Items need restocking</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-icon purple">
                                <i class="fas fa-users"></i>
                            </div>
                            <div>
                                <div class="card-title">Active Staff</div>
                            </div>
                        </div>
                        <div class="card-content">
                            <div class="stat-number">18</div>
                            <p>Staff members on duty</p>
                        </div>
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Recent Activity</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Chicken Breast - Restocked</td>
                                <td>Inventory</td>
                                <td><span class="badge badge-success">Completed</span></td>
                                <td>2 hours ago</td>
                            </tr>
                            <tr>
                                <td>Lunch Menu - Updated</td>
                                <td>Meal Planning</td>
                                <td><span class="badge badge-success">Completed</span></td>
                                <td>3 hours ago</td>
                            </tr>
                            <tr>
                                <td>Rice - Low Stock Alert</td>
                                <td>Inventory</td>
                                <td><span class="badge badge-warning">Pending</span></td>
                                <td>4 hours ago</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Food Inventory -->
            <div id="inventory" class="page-content">
                <div class="card">
                    <div class="card-header">
                        <div class="card-icon green">
                            <i class="fas fa-warehouse"></i>
                        </div>
                        <div>
                            <div class="card-title">Food Inventory Management</div>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Item Name</label>
                                <input type="text" placeholder="Enter item name">
                            </div>
                            <div class="form-group">
                                <label>Category</label>
                                <select>
                                    <option>Vegetables</option>
                                    <option>Meat</option>
                                    <option>Dairy</option>
                                    <option>Grains</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" placeholder="Enter quantity">
                            </div>
                            <div class="form-group">
                                <label>Unit</label>
                                <select>
                                    <option>Kg</option>
                                    <option>Lbs</option>
                                    <option>Pieces</option>
                                    <option>Liters</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn-primary">Add Item</button>
                        <button class="btn btn-success">Update Stock</button>
                    </div>
                </div>
            </div>

            <!-- Meal Planner -->
            <div id="planner" class="page-content">
                <div class="card">
                    <div class="card-header">
                        <div class="card-icon blue">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div>
                            <div class="card-title">Weekly Meal Planner</div>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Meal Type</label>
                                <select>
                                    <option>Breakfast</option>
                                    <option>Lunch</option>
                                    <option>Dinner</option>
                                    <option>Snack</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date</label>
                                <input type="date">
                            </div>
                            <div class="form-group">
                                <label>Dish Name</label>
                                <input type="text" placeholder="Enter dish name">
                            </div>
                            <div class="form-group">
                                <label>Servings</label>
                                <input type="number" placeholder="Number of servings">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Ingredients Required</label>
                            <textarea rows="4" placeholder="List all ingredients needed"></textarea>
                        </div>
                        <button class="btn btn-primary">Schedule Meal</button>
                        <button class="btn btn-success">Generate Shopping List</button>
                    </div>
                </div>
            </div>

            <!-- Special Diets -->
            <div id="diets" class="page-content">
                <div class="card">
                    <div class="card-header">
                        <div class="card-icon red">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div>
                            <div class="card-title">Special Dietary Requirements</div>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Diet Type</label>
                                <select>
                                    <option>Vegetarian</option>
                                    <option>Vegan</option>
                                    <option>Gluten-Free</option>
                                    <option>Diabetic</option>
                                    <option>Low-Sodium</option>
                                    <option>Halal</option>
                                    <option>Kosher</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Number of People</label>
                                <input type="number" placeholder="How many people">
                            </div>
                            <div class="form-group">
                                <label>Meal Period</label>
                                <select>
                                    <option>Daily</option>
                                    <option>Weekly</option>
                                    <option>Monthly</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Special Instructions</label>
                            <textarea rows="3" placeholder="Any additional dietary restrictions or notes"></textarea>
                        </div>
                        <button class="btn btn-primary">Create Diet Plan</button>
                        <button class="btn btn-success">Generate Menu</button>
                    </div>
                </div>
            </div>

            <!-- Kitchen Staff -->
            <div id="staff" class="page-content">
                <div class="card">
                    <div class="card-header">
                        <div class="card-icon purple">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="card-title">Kitchen Staff Management</div>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Staff Name</label>
                                <input type="text" placeholder="Enter staff name">
                            </div>
                            <div class="form-group">
                                <label>Position</label>
                                <select>
                                    <option>Head Chef</option>
                                    <option>Sous Chef</option>
                                    <option>Cook</option>
                                    <option>Kitchen Assistant</option>
                                    <option>Dishwasher</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Shift</label>
                                <select>
                                    <option>Morning (6AM-2PM)</option>
                                    <option>Afternoon (2PM-10PM)</option>
                                    <option>Night (10PM-6AM)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Contact Number</label>
                                <input type="tel" placeholder="Phone number">
                            </div>
                        </div>
                        <button class="btn btn-primary">Add Staff</button>
                        <button class="btn btn-success">Schedule Shift</button>
                    </div>
                </div>
            </div>

            <!-- Reports -->
            <div id="reports" class="page-content">
                <div class="card">
                    <div class="card-header">
                        <div class="card-icon teal">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div>
                            <div class="card-title">Kitchen Reports & Analytics</div>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Report Type</label>
                                <select>
                                    <option>Inventory Summary</option>
                                    <option>Staff Performance</option>
                                    <option>Menu Analysis</option>
                                    <option>Cost Analysis</option>
                                    <option>Waste Report</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date Range</label>
                                <select>
                                    <option>Last 7 days</option>
                                    <option>Last 30 days</option>
                                    <option>Last 3 months</option>
                                    <option>Custom Range</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn-primary">Generate Report</button>
                        <button class="btn btn-success">Export to PDF</button>
                        
                        <div class="chart-container" style="margin-top: 25px;">
                            <p>Chart visualization would appear here</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showPage(pageId) {
            // Hide all pages
            const pages = document.querySelectorAll('.page-content');
            pages.forEach(page => page.classList.remove('active'));
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.nav-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected page
            document.getElementById(pageId).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
    </script>
</body>
</html>