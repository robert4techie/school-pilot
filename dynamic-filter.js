document.addEventListener('DOMContentLoaded', function() {
    // Get all filter elements
    const searchInput = document.getElementById('search');
    const classSelect = document.getElementById('class');
    const streamSelect = document.getElementById('stream');
    const typeSelect = document.getElementById('type');
    
    // Add event listeners to all filter elements
    const filterElements = [searchInput, classSelect, streamSelect, typeSelect];
    
    filterElements.forEach(element => {
        // For text inputs use 'input' event with debounce
        if (element.tagName === 'INPUT') {
            let debounceTimer;
            element.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    updateFilters();
                }, 300); // 300ms debounce to prevent excessive requests while typing
            });
        } 
        // For select elements use 'change' event
        else if (element.tagName === 'SELECT') {
            element.addEventListener('change', updateFilters);
        }
    });
    
    // Function to update URL and reload content
    function updateFilters() {
        // Start with current URL and remove existing query parameters
        let url = new URL(window.location.href);
        
        // Get current page from URL if it exists
        const currentPage = url.searchParams.get('page') || '1';
        
        // Clear all existing parameters
        url.search = '';
        
        // Set page back to 1 if filters change
        url.searchParams.set('page', '1');
        
        // Add filter parameters if they have values
        if (searchInput.value.trim()) {
            url.searchParams.set('search', searchInput.value.trim());
        }
        
        if (classSelect.value) {
            url.searchParams.set('class', classSelect.value);
        }
        
        if (streamSelect.value) {
            url.searchParams.set('stream', streamSelect.value);
        }
        
        if (typeSelect.value) {
            url.searchParams.set('type', typeSelect.value);
        }
        
        // Navigate to new URL (reloads the page with new filters)
        window.location.href = url.toString();
    }
    
    // Add visual feedback that filters are active
    filterElements.forEach(element => {
        if ((element.tagName === 'INPUT' && element.value.trim()) || 
            (element.tagName === 'SELECT' && element.value)) {
            element.classList.add('active-filter');
            
            // Add a small indicator next to the label
            const label = document.querySelector(`label[for="${element.id}"]`);
            if (label && !label.querySelector('.filter-indicator')) {
                const indicator = document.createElement('span');
                indicator.className = 'filter-indicator';
                indicator.innerHTML = '&bull;'; // Bullet character as indicator
                indicator.title = 'Filter is active';
                label.appendChild(indicator);
            }
        }
    });
    
    // Add keyboard navigation support - submit on Enter key
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            updateFilters();
        }
    });
    
    // Add clear filter functionality
    const filterForm = document.querySelector('.filter-form');
    if (filterForm) {
        // Create clear filters button if any filters are active
        const anyFilterActive = [...filterElements].some(el => 
            (el.tagName === 'INPUT' && el.value.trim()) || 
            (el.tagName === 'SELECT' && el.value)
        );
        
        if (anyFilterActive && !document.getElementById('clear-filters')) {
            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.id = 'clear-filters';
            clearBtn.className = 'btn btn-outline btn-sm';
            clearBtn.innerHTML = '<i class="fas fa-times"></i> Clear Filters';
            clearBtn.addEventListener('click', function() {
                // Reset all filters
                filterElements.forEach(el => {
                    el.value = '';
                });
                // Update the page
                updateFilters();
            });
            
            // Find a good spot to insert the button in the filter form
            const lastFormGroup = filterForm.querySelector('.form-group:last-child');
            if (lastFormGroup) {
                lastFormGroup.after(clearBtn);
            } else {
                filterForm.appendChild(clearBtn);
            }
        }
    }
});