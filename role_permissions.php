<?php

$role_permissions = [
    'super user' => [
        'dashboard', 'students', 'staff', 'attendance', 'discipline', 
        'assets', 'stationery', 'laboratory', 'visitors', 'gatepass', 'school_requirements', 'kitchen', 
        'o_level', 'a_level', 'timetable', 'health', 'fees', 
        'finance_docs', 'library', 'communication', 'parent_portal', 
        'reports', 'settings'
    ],
    'developer' => [
        'dashboard', 'students', 'staff', 'attendance', 'discipline', 
        'assets', 'stationery', 'laboratory', 'visitors', 'gatepass', 'school_requirements', 'kitchen', 
        'o_level', 'a_level', 'timetable', 'health', 'fees', 
        'finance_docs', 'library', 'communication', 'parent_portal', 
        'reports', 'settings'
    ],
    'school leader' => [
        'dashboard', 'students', 'staff', 'gatepass', 'school_requirements', 'attendance', 'discipline', 
        'reports'
    ],
    'class teacher' => [
        'dashboard',  'attendance', 'discipline', 'o_level', 
        'a_level', 'timetable', 'communication'
    ],
    'subject teacher' => [
        'dashboard', 'attendance', 'o_level', 'a_level', 'timetable', 'discipline'
    ],
    'nurse' => ['health'],
    'bursar' => ['dashboard', 'school_requirements', 'fees', 'finance_docs'],
    'librarian' => ['library'],
    'receptionist' => ['visitors'],
    'gateman' => ['visitors'],
    'lab attendant' => ['laboratory']
];
?>