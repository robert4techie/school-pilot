// lab_schedule_scripts.js - Complete JavaScript for Lab Booking System

// Global variables
let currentWeek = new Date();
let currentView = 'calendar';
let allBookings = [];
let isEditMode = false;

// Initialize the application
document.addEventListener('DOMContentLoaded', function() {
    initializeCalendar();
    loadBookings();
    setupEventListeners();
});

// Setup event listeners
function setupEventListeners() {
    // Form submission
    document.getElementById('bookingForm').addEventListener('submit', handleFormSubmit);
    
    // Time validation
    document.getElementById('start-time').addEventListener('change', validateTimes);
    document.getElementById('end-time').addEventListener('change', validateTimes);
    
    // Conflict checking
    document.getElementById('booking-date').addEventListener('change', checkForConflicts);
    document.getElementById('start-time').addEventListener('change', checkForConflicts);
    document.getElementById('end-time').addEventListener('change', checkForConflicts);
    
    // Modal close on outside click
    document.getElementById('bookingModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // ESC key to close modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });
}

// Initialize calendar structure
function initializeCalendar() {
    const calendarHeader = document.getElementById('calendarHeader');
    const calendarGrid = document.getElementById('calendarGrid');
    
    // Create header with days of week
    const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    let headerHTML = '<div class="time-column">Time</div>';
    
    days.forEach(day => {
        headerHTML += `<div class="day-column">${day}</div>`;
    });
    
    calendarHeader.innerHTML = headerHTML;
    
    // Create time slots (8 AM to 6 PM)
    let gridHTML = '';
    for (let hour = 8; hour < 18; hour++) {
        const timeDisplay = formatHour(hour);
        gridHTML += `<div class="time-slot">${timeDisplay}</div>`;
        
        // Create cells for each day
        const dayIds = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        dayIds.forEach(dayId => {
            gridHTML += `<div class="calendar-cell" id="${dayId}-${hour}" data-day="${dayId}" data-hour="${hour}" onclick="openModalForTime('${dayId}', ${hour})"></div>`;
        });
    }
    
    calendarGrid.innerHTML = gridHTML;
}

// Format hour for display
function formatHour(hour) {
    const period = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour > 12 ? hour - 12 : (hour === 0 ? 12 : hour);
    return `${displayHour}:00 ${period}`;
}

// Load bookings from server
function loadBookings() {
    const weekStart = getWeekStart();
    const weekEnd = getWeekEnd();
    
    const params = new URLSearchParams({
        action: 'get_bookings',
        start_date: weekStart,
        end_date: weekEnd
    });
    
    fetch(`ajax_handler.php?${params}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allBookings = data.bookings;
                renderBookings();
                renderListView();
            } else {
                showNotification('Error loading bookings', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Failed to load bookings', 'error');
        });
}

// Render bookings in calendar view
function renderBookings() {
    // Clear existing bookings
    document.querySelectorAll('.booking-item').forEach(el => el.remove());
    
    allBookings.forEach(booking => {
        const cell = document.getElementById(booking.cellId);
        if (cell) {
            const bookingElement = createBookingElement(booking);
            cell.appendChild(bookingElement);
        }
    });
}

// Create booking element
function createBookingElement(booking) {
    const bookingDiv = document.createElement('div');
    bookingDiv.className = `booking-item ${booking.type}`;
    bookingDiv.onclick = (e) => {
        e.stopPropagation();
        editBooking(booking.id);
    };
    
    const duration = calculateDuration(booking.startTime, booking.endTime);
    
    bookingDiv.innerHTML = `
        <div class="booking-content">
            <div class="booking-title">${booking.title}</div>
            <div class="booking-time">${booking.startTime} - ${booking.endTime}</div>
            <div class="booking-person">${booking.person}</div>
        </div>
    `;
    
    // Set height based on duration
    const heightPerHour = 60; // Assuming each hour slot is 60px
    bookingDiv.style.height = `${(duration / 60) * heightPerHour}px`;
    
    return bookingDiv;
}

// Calculate duration in minutes
function calculateDuration(startTime, endTime) {
    const start = new Date(`2000-01-01 ${startTime}`);
    const end = new Date(`2000-01-01 ${endTime}`);
    return (end - start) / (1000 * 60);
}

// Render list view
function renderListView() {
    const bookingList = document.getElementById('bookingList');
    
    if (allBookings.length === 0) {
        bookingList.innerHTML = '<div class="no-bookings">No bookings found for the selected criteria.</div>';
        return;
    }
    
    let listHTML = '';
    allBookings.forEach(booking => {
        listHTML += `
            <div class="booking-card ${booking.type}" onclick="editBooking(${booking.id})">
                <div class="booking-card-header">
                    <h3>${booking.title}</h3>
                    <span class="booking-type-badge">${booking.type}</span>
                </div>
                <div class="booking-card-body">
                    <div class="booking-info">
                        <i class="fas fa-calendar"></i>
                        <span>${formatDate(booking.date)}</span>
                    </div>
                    <div class="booking-info">
                        <i class="fas fa-clock"></i>
                        <span>${booking.startTime} - ${booking.endTime}</span>
                    </div>
                    <div class="booking-info">
                        <i class="fas fa-user"></i>
                        <span>${booking.person}</span>
                    </div>
                    ${booking.equipment.length > 0 ? `
                        <div class="booking-info">
                            <i class="fas fa-tools"></i>
                            <span>${booking.equipment.join(', ')}</span>
                        </div>
                    ` : ''}
                    ${booking.notes ? `
                        <div class="booking-notes">
                            <i class="fas fa-sticky-note"></i>
                            ${booking.notes}
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    bookingList.innerHTML = listHTML;
}

// Switch between views
function switchView(view) {
    currentView = view;
    
    // Update button states
    document.querySelectorAll('.view-toggle button').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    // Show/hide containers
    const calendarContainer = document.querySelector('.calendar-container');
    const listContainer = document.querySelector('.list-container');
    
    if (view === 'calendar') {
        calendarContainer.style.display = 'block';
        listContainer.style.display = 'none';
    } else {
        calendarContainer.style.display = 'none';
        listContainer.style.display = 'block';
    }
}

// Change week navigation
function changeWeek(direction) {
    currentWeek.setDate(currentWeek.getDate() + (direction * 7));
    updateWeekDisplay();
    loadBookings();
}

// Update week display
function updateWeekDisplay() {
    const weekStart = getWeekStart();
    const weekEnd = getWeekEnd();
    const startDate = new Date(weekStart);
    const endDate = new Date(weekEnd);
    
    const dateText = `${startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
    document.getElementById('currentDate').textContent = dateText;
    
    // Update filter dates
    document.getElementById('start-date').value = weekStart;
    document.getElementById('end-date').value = weekEnd;
}

// Get week start (Monday)
function getWeekStart() {
    const date = new Date(currentWeek);
    const day = date.getDay();
    const diff = date.getDate() - day + (day === 0 ? -6 : 1);
    date.setDate(diff);
    return date.toISOString().split('T')[0];
}

// Get week end (Sunday)
function getWeekEnd() {
    const date = new Date(currentWeek);
    const day = date.getDay();
    const diff = date.getDate() - day + (day === 0 ? 0 : 7);
    date.setDate(diff);
    return date.toISOString().split('T')[0];
}

// Open modal for new booking
function openModal() {
    resetForm();
    isEditMode = false;
    document.querySelector('.modal-title').textContent = 'New Lab Booking';
    document.getElementById('deleteBtn').style.display = 'none';
    document.getElementById('bookingModal').style.display = 'flex';
    
    // Set default date to today
    document.getElementById('booking-date').value = new Date().toISOString().split('T')[0];
}

// Open modal for specific time slot
function openModalForTime(dayId, hour) {
    openModal();
    
    // Calculate the date for the selected day
    const weekStart = new Date(getWeekStart());
    const dayIndex = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].indexOf(dayId);
    const selectedDate = new Date(weekStart);
    selectedDate.setDate(weekStart.getDate() + dayIndex);
    
    // Set form values
    document.getElementById('booking-date').value = selectedDate.toISOString().split('T')[0];
    document.getElementById('start-time').value = `${hour.toString().padStart(2, '0')}:00`;
    document.getElementById('end-time').value = `${(hour + 1).toString().padStart(2, '0')}:00`;
}

// Close modal
function closeModal() {
    document.getElementById('bookingModal').style.display = 'none';
    hideConflictWarning();
    resetForm();
}

// Reset form
function resetForm() {
    document.getElementById('bookingForm').reset();
    document.getElementById('booking-id').value = '';
    
    // Clear equipment checkboxes
    document.querySelectorAll('.equipment-option input[type="checkbox"]').forEach(cb => {
        cb.checked = false;
    });
    
    hideConflictWarning();
}

// Edit existing booking
function editBooking(bookingId) {
    fetch(`ajax_handler.php?action=get_booking&id=${bookingId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populateForm(data.booking);
                isEditMode = true;
                document.querySelector('.modal-title').textContent = 'Edit Lab Booking';
                document.getElementById('deleteBtn').style.display = 'inline-block';
                document.getElementById('bookingModal').style.display = 'flex';
            } else {
                showNotification('Error loading booking details', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Failed to load booking details', 'error');
        });
}

// Populate form with booking data
function populateForm(booking) {
    document.getElementById('booking-id').value = booking.id;
    document.getElementById('booking-date').value = booking.booking_date;
    document.getElementById('start-time').value = booking.startTime;
    document.getElementById('end-time').value = booking.endTime;
    document.getElementById('booking-purpose').value = booking.purpose;
    document.getElementById('responsible-person').value = booking.responsible_person;
    document.getElementById('contact-email').value = booking.contact_email;
    document.getElementById('booking-title').value = booking.title;
    document.getElementById('booking-notes').value = booking.notes || '';
    
    // Set equipment checkboxes
    booking.equipment.forEach(equipment => {
        const checkbox = document.querySelector(`input[value="${equipment}"]`);
        if (checkbox) {
            checkbox.checked = true;
        }
    });
}

// Handle form submission
function handleFormSubmit(e) {
    e.preventDefault();
    
    const formData = new FormData();
    const bookingId = document.getElementById('booking-id').value;
    
    // Set action based on edit mode
    formData.append('action', isEditMode ? 'update_booking' : 'create_booking');
    
    if (isEditMode) {
        formData.append('id', bookingId);
    }
    
    // Collect form data
    formData.append('title', document.getElementById('booking-title').value);
    formData.append('booking_date', document.getElementById('booking-date').value);
    formData.append('start_time', document.getElementById('start-time').value);
    formData.append('end_time', document.getElementById('end-time').value);
    formData.append('purpose', document.getElementById('booking-purpose').value);
    formData.append('responsible_person', document.getElementById('responsible-person').value);
    formData.append('contact_email', document.getElementById('contact-email').value);
    formData.append('notes', document.getElementById('booking-notes').value);
    
    // Collect selected equipment
    const selectedEquipment = [];
    document.querySelectorAll('.equipment-option input[type="checkbox"]:checked').forEach(cb => {
        selectedEquipment.push(cb.value);
    });
    selectedEquipment.forEach(equipment => {
        formData.append('equipment[]', equipment);
    });
    
    // Submit form
    fetch('ajax_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(isEditMode ? 'Booking updated successfully' : 'Booking created successfully', 'success');
            closeModal();
            loadBookings();
        } else {
            if (data.conflicts) {
                showConflictWarning(data.conflicts);
            } else {
                showNotification(data.message || 'Failed to save booking', 'error');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Failed to save booking', 'error');
    });
}

// Delete booking
function deleteBooking() {
    if (!confirm('Are you sure you want to delete this booking?')) {
        return;
    }
    
    const bookingId = document.getElementById('booking-id').value;
    const formData = new FormData();
    formData.append('action', 'delete_booking');
    formData.append('id', bookingId);
    
    fetch('ajax_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Booking deleted successfully', 'success');
            closeModal();
            loadBookings();
        } else {
            showNotification(data.message || 'Failed to delete booking', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Failed to delete booking', 'error');
    });
}

// Validate start and end times
function validateTimes() {
    const startTime = document.getElementById('start-time').value;
    const endTime = document.getElementById('end-time').value;
    
    if (startTime && endTime) {
        if (startTime >= endTime) {
            document.getElementById('end-time').setCustomValidity('End time must be after start time');
        } else {
            document.getElementById('end-time').setCustomValidity('');
        }
    }
}

// Check for booking conflicts
function checkForConflicts() {
    const date = document.getElementById('booking-date').value;
    const startTime = document.getElementById('start-time').value;
    const endTime = document.getElementById('end-time').value;
    const bookingId = document.getElementById('booking-id').value;
    
    if (!date || !startTime || !endTime) {
        hideConflictWarning();
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'check_conflicts');
    formData.append('booking_date', date);
    formData.append('start_time', startTime);
    formData.append('end_time', endTime);
    
    if (bookingId) {
        formData.append('exclude_id', bookingId);
    }
    
    fetch('ajax_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.hasConflicts) {
            showConflictWarning(data.conflicts);
        } else {
            hideConflictWarning();
        }
    })
    .catch(error => {
        console.error('Error checking conflicts:', error);
    });
}

// Show conflict warning
function showConflictWarning(conflicts) {
    const warningDiv = document.getElementById('conflictWarning');
    const conflictList = document.getElementById('conflictList');
    
    let conflictHTML = '';
    conflicts.forEach(conflict => {
        conflictHTML += `
            <div class="conflict-item">
                <strong>${conflict.title}</strong><br>
                ${formatDate(conflict.booking_date)} ${conflict.start_time} - ${conflict.end_time}<br>
                Responsible: ${conflict.responsible_person}
            </div>
        `;
    });
    
    conflictList.innerHTML = conflictHTML;
    warningDiv.style.display = 'block';
}

// Hide conflict warning
function hideConflictWarning() {
    document.getElementById('conflictWarning').style.display = 'none';
}

// Apply filters in list view
function applyFilters() {
    const startDate = document.getElementById('start-date').value;
    const endDate = document.getElementById('end-date').value;
    const type = document.getElementById('type-filter').value;
    const person = document.getElementById('person-filter').value;
    
    const params = new URLSearchParams({
        action: 'get_bookings'
    });
    
    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    if (type) params.append('type', type);
    if (person) params.append('person', person);
    
    fetch(`ajax_handler.php?${params}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allBookings = data.bookings;
                renderListView();
            } else {
                showNotification('Error applying filters', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Failed to apply filters', 'error');
        });
}

// Show notification
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${message}</span>
    `;
    
    // Add to page
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.classList.add('show');
    }, 100);
    
    // Hide and remove notification
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 300);
    }, 4000);
}

// Format date for display
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
}

// Utility function to get current date in YYYY-MM-DD format
function getCurrentDate() {
    return new Date().toISOString().split('T')[0];
}

// Initialize week display on load
updateWeekDisplay();