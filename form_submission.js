/**
 * Sick Bay Visit Form Submission Handler
 * Enhanced version with better error handling
 */

document.addEventListener('DOMContentLoaded', function() {
    const sickBayForm = document.getElementById('sickBayVisitForm');
    if (sickBayForm) {
        sickBayForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // UI State Management
            const submitButton = sickBayForm.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.innerHTML;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitButton.disabled = true;
            
            clearValidationErrors();
            
            // Form Data Collection
            const formData = new FormData(sickBayForm);
            
            // Enhanced Client-side Validation
            const requiredFields = ['visitDate', 'visitTime', 'student_id', 'attendedBy'];
            let validationErrors = [];
            
            requiredFields.forEach(field => {
                const value = formData.get(field);
                if (!value || value.trim() === '') {
                    validationErrors.push({
                        field,
                        message: `${field.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase())} is required`
                    });
                }
            });
            
            if (validationErrors.length > 0) {
                submitButton.innerHTML = originalButtonText;
                submitButton.disabled = false;
                showValidationErrors(validationErrors);
                showNotification('Please fill in all required fields', 'error');
                return;
            }
            
            try {
                // AJAX Request with Error Handling
                const response = await fetch('save_sickbay_visit.php', {
                    method: 'POST',
                    body: formData
                });
                
                // Check for HTTP errors
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                // Parse JSON response
                const data = await response.json();
                
                // Handle response
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(resetForm, 1000);
                } else {
                    if (data.errors && Object.keys(data.errors).length > 0) {
                        showValidationErrors(Object.entries(data.errors).map(([field, message]) => ({ field, message })));
                    }
                    showNotification(data.message || 'An error occurred while saving the record.', 'error');
                }
            } catch (error) {
                console.error('Form submission error:', error);
                showNotification(
                    error.message.includes('JSON') 
                        ? 'Server returned invalid response. Please contact support.' 
                        : `Error: ${error.message}`,
                    'error'
                );
            } finally {
                submitButton.innerHTML = originalButtonText;
                submitButton.disabled = false;
            }
        });
    }
    
    function clearValidationErrors() {
        document.querySelectorAll('.is-invalid').forEach(el => {
            el.classList.remove('is-invalid');
        });
        
        document.querySelectorAll('.invalid-feedback').forEach(el => {
            el.remove();
        });
    }
    
    function showValidationErrors(errors) {
        clearValidationErrors();
        
        errors.forEach(error => {
            const field = document.getElementById(error.field) || 
                         document.querySelector(`[name="${error.field}"]`);
            
            if (field) {
                field.classList.add('is-invalid');
                
                const errorElement = document.createElement('div');
                errorElement.className = 'invalid-feedback';
                errorElement.textContent = error.message;
                
                field.parentNode.appendChild(errorElement);
            }
        });
    }
    
    function showNotification(message, type = 'info') {
        // Implement your notification system here
        // Example using Bootstrap Toast:
        const toastContainer = document.getElementById('toastContainer');
        if (toastContainer) {
            const toastId = 'toast-' + Date.now();
            const toastHtml = `
                <div id="${toastId}" class="toast align-items-center text-white bg-${type}" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">${message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.getElementById(toastId);
            new bootstrap.Toast(toastElement).show();
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                toastElement.remove();
            }, 5000);
        } else {
            // Fallback to alert
            alert(`${type.toUpperCase()}: ${message}`);
        }
    }
    // Function to clear validation errors
    function clearValidationErrors() {
        // Remove error classes
        document.querySelectorAll('.error-field').forEach(field => {
            field.classList.remove('error-field');
        });
        
        // Remove error messages
        document.querySelectorAll('.error-message').forEach(message => {
            message.parentNode.removeChild(message);
        });
    }
    
    // Function to show validation errors
    function showValidationErrors(errors) {
        if (!errors || !Array.isArray(errors)) return;
        
        let errorHtml = '<ul class="error-list">';
        errors.forEach(error => {
            errorHtml += `<li>${error}</li>`;
        });
        errorHtml += '</ul>';
        
        showNotification(errorHtml, 'error', 'Validation Errors');
    }
    
    // Function to show notification
    function showNotification(message, type = 'info', title = '') {
        // Implementation depends on your notification system
        // This is a placeholder for your actual notification function
        console.log(`Notification (${type}): ${title ? title + ' - ' : ''}${message}`);
        
        // Example with basic browser alert if no notification system exists
        if (typeof window.showToast === 'undefined') {
            if (type === 'error') {
                alert(`Error: ${message}`);
            } else {
                alert(message);
            }
        } else {
            // Use your notification system
            window.showToast(message, type, title);
        }
    }
    
    // Function to reset form after successful submission
    function resetForm() {
        sickBayForm.reset();
        
        // Set today's date as default
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('visitDate').value = today;
        
        // Set current time as default
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        document.getElementById('visitTime').value = `${hours}:${minutes}`;
        
        // Clear student class and stream
        const studentClassEl = document.getElementById('studentClass');
        const studentStreamEl = document.getElementById('studentStream');
        if (studentClassEl) studentClassEl.value = '';
        if (studentStreamEl) studentStreamEl.value = '';
        
        // Reset medication container to have only one row
        const medicationContainer = document.getElementById('medicationContainer');
        if (medicationContainer) {
            const medicationRows = medicationContainer.querySelectorAll('.medication-row');
            
            // Keep only the first medication row and clear its values
            for (let i = 1; i < medicationRows.length; i++) {
                medicationContainer.removeChild(medicationRows[i]);
            }
            
            // Clear the values in the first medication row
            const firstRow = medicationContainer.querySelector('.medication-row');
            if (firstRow) {
                const selectEl = firstRow.querySelector('select');
                const dosageEl = firstRow.querySelector('input[name="dosages[]"]');
                const timeEl = firstRow.querySelector('input[name="medicationTimes[]"]');
                
                if (selectEl) selectEl.value = '';
                if (dosageEl) dosageEl.value = '';
                if (timeEl) timeEl.value = '';
            }
        }
        
        // Hide parent notes and follow-up sections
        const parentNotesGroup = document.getElementById('parentNotesGroup');
        const followupSection = document.getElementById('followupSection');
        
        if (parentNotesGroup) parentNotesGroup.classList.add('hidden');
        if (followupSection) followupSection.classList.add('hidden');
        
        // Reset action taken to default
        const defaultAction = document.getElementById('action1');
        if (defaultAction) defaultAction.checked = true;
        
        // Show success message
        showNotification('Form has been reset with new values', 'success', 'Form Reset');
    }
});