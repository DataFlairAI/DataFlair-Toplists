jQuery(document).ready(function($) {
    // Handle save for both buttons
    $('#dataflair-save-settings, #dataflair-save-settings-custom').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $message = $button.siblings('span[id*="dataflair-save-message"]');
        var originalText = $button.val();
        
        // Disable button and show loading
        $button.prop('disabled', true).val('Saving...');
        $message.removeClass('error').html('');
        
        // Get all form data
        var formData = {
            action: 'dataflair_save_settings',
            nonce: dataflairAdmin.nonce,
            dataflair_api_token: $('#dataflair_api_token').val() || '',
            dataflair_ribbon_bg_color: $('#dataflair_ribbon_bg_color').val() || '',
            dataflair_ribbon_text_color: $('#dataflair_ribbon_text_color').val() || '',
            dataflair_cta_bg_color: $('#dataflair_cta_bg_color').val() || '',
            dataflair_cta_text_color: $('#dataflair_cta_text_color').val() || ''
        };
        
        // Send AJAX request
        $.ajax({
            url: dataflairAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    $message.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
                    $button.val(originalText).prop('disabled', false);
                    
                    // Clear message after 3 seconds
                    setTimeout(function() {
                        $message.html('');
                    }, 3000);
                } else {
                    $message.html('<span style="color: #dc3232;">✗ ' + (response.data.message || 'Error saving settings') + '</span>');
                    $button.val(originalText).prop('disabled', false);
                }
            },
            error: function() {
                $message.html('<span style="color: #dc3232;">✗ Error saving settings. Please try again.</span>');
                $button.val(originalText).prop('disabled', false);
            }
        });
    });
    
    // Handle fetch all toplists button
    $('#dataflair-fetch-all-toplists').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $message = $('#dataflair-fetch-message');
        var originalText = $button.text();
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Fetching...');
        $message.removeClass('error').html('<span style="color: #0073aa;">⏳ Fetching toplists from API...</span>');
        
        // Send AJAX request
        $.ajax({
            url: dataflairAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dataflair_fetch_all_toplists',
                nonce: dataflairAdmin.fetchNonce
            },
            success: function(response) {
                if (response.success) {
                    $message.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
                    $button.text(originalText).prop('disabled', false);
                    
                    // Reload page after 2 seconds to show updated toplists
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $message.html('<span style="color: #dc3232;">✗ ' + (response.data.message || 'Error fetching toplists') + '</span>');
                    $button.text(originalText).prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'An error occurred while fetching toplists.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                $message.html('<span style="color: #dc3232;">✗ ' + errorMsg + '</span>');
                $button.text(originalText).prop('disabled', false);
            }
        });
    });
    
    // Handle fetch all brands button with batch processing
    $('#dataflair-fetch-all-brands').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $message = $('#dataflair-fetch-brands-message');
        var $progressBar = $('#dataflair-sync-progress');
        var $progressBarInner = $('#dataflair-progress-bar');
        var $progressText = $('#dataflair-progress-text');
        var originalText = $button.text();
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Syncing...');
        $message.html('<span style="color: #0073aa;">⏳ Starting sync...</span>');
        
        // Show progress bar
        $progressBar.show();
        $progressBarInner.css('width', '0%');
        $progressText.text('0%');
        
        // Send initial AJAX request
        $.ajax({
            url: dataflairAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dataflair_fetch_all_brands',
                nonce: dataflairAdmin.fetchBrandsNonce
            },
            success: function(response) {
                if (response.success && response.data.start_batch) {
                    // Start batch processing
                    syncBrandsBatch(1, 0);
                } else {
                    $message.html('<span style="color: #dc3232;">✗ ' + (response.data.message || 'Error starting sync') + '</span>');
                    $button.text(originalText).prop('disabled', false);
                    $progressBar.hide();
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'An error occurred while starting sync.';
                $message.html('<span style="color: #dc3232;">✗ ' + errorMsg + '</span>');
                $button.text(originalText).prop('disabled', false);
                $progressBar.hide();
            }
        });
        
        // Function to sync one batch (page) of brands
        function syncBrandsBatch(page, totalSynced) {
            $.ajax({
                url: dataflairAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dataflair_sync_brands_batch',
                    nonce: dataflairAdmin.syncBrandsBatchNonce,
                    page: page
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        totalSynced += data.synced || 0;
                        
                        // Update progress
                        var progress = Math.round((page / data.last_page) * 100);
                        $progressBarInner.css('width', progress + '%');
                        $progressText.text(progress + '% - Page ' + page + ' of ' + data.last_page);
                        
                        $message.html('<span style="color: #0073aa;">⏳ Synced ' + totalSynced + ' brands...</span>');
                        
                        // Check if there are more pages
                        if (!data.is_complete && page < data.last_page) {
                            // Sync next page
                            setTimeout(function() {
                                syncBrandsBatch(page + 1, totalSynced);
                            }, 500); // Small delay between batches
                        } else {
                            // All done
                            $progressBarInner.css('width', '100%');
                            $progressText.text('100% Complete');
                            $message.html('<span style="color: #46b450;">✓ Successfully synced ' + totalSynced + ' brands!</span>');
                            $button.text(originalText).prop('disabled', false);
                            
                            // Reload page after 2 seconds
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        $message.html('<span style="color: #dc3232;">✗ Error on page ' + page + ': ' + (response.data.message || 'Unknown error') + '</span>');
                        $button.text(originalText).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    $message.html('<span style="color: #dc3232;">✗ Error syncing page ' + page + '</span>');
                    $button.text(originalText).prop('disabled', false);
                }
            });
        }
    });
    
    // Accordion toggle
    $(document).on('click', '.brand-toggle', function() {
        var $button = $(this);
        var $row = $button.closest('tr');
        var $detailsRow = $row.next('.brand-details');
        var isExpanded = $button.attr('aria-expanded') === 'true';
        
        if (isExpanded) {
            $button.attr('aria-expanded', 'false');
            $button.find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
            $detailsRow.slideUp(200);
        } else {
            $button.attr('aria-expanded', 'true');
            $button.find('.dashicons').removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
            $detailsRow.slideDown(200);
        }
    });
    
    // Initialize custom multiselect filters
    $('.dataflair-multiselect-toggle').on('click', function(e) {
        e.stopPropagation();
        var $toggle = $(this);
        var $dropdown = $toggle.next('.dataflair-multiselect-dropdown');
        var $allDropdowns = $('.dataflair-multiselect-dropdown');
        
        // Close other dropdowns
        $allDropdowns.not($dropdown).hide();
        
        // Toggle this dropdown
        $dropdown.toggle();
    });
    
    // Search within multiselect
    $('.dataflair-multiselect-search input, .search-input').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        var $options = $(this).closest('.dataflair-multiselect-dropdown').find('.dataflair-multiselect-options label');
        
        $options.each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(searchTerm) > -1);
        });
    });
    
    // Select All
    $('.dataflair-multiselect-select-all').on('click', function(e) {
        e.preventDefault();
        var $dropdown = $(this).closest('.dataflair-multiselect-dropdown');
        $dropdown.find('.dataflair-multiselect-options input[type="checkbox"]').prop('checked', true);
        updateSelectedText($dropdown);
        applyFiltersAndSort();
    });
    
    // Clear
    $('.dataflair-multiselect-clear').on('click', function(e) {
        e.preventDefault();
        $(this).closest('.dataflair-multiselect-dropdown').find('.dataflair-multiselect-options input[type="checkbox"]').prop('checked', false);
        updateSelectedText($(this).closest('.dataflair-multiselect-dropdown'));
        applyFiltersAndSort();
    });
    
    // Apply filter when checkbox changes - use event delegation for dynamically loaded content
    $(document).on('change', '.dataflair-multiselect-options input[type="checkbox"]', function() {
        var $dropdown = $(this).closest('.dataflair-multiselect-dropdown');
        updateSelectedText($dropdown);
        applyFiltersAndSort();
    });
    
    // Function to update the selected text on the button
    function updateSelectedText($dropdown) {
        var $toggle = $dropdown.prev('.dataflair-multiselect-toggle');
        var $selectedText = $toggle.find('.selected-text');
        var $checkboxes = $dropdown.find('.dataflair-multiselect-options input[type="checkbox"]');
        var $checked = $checkboxes.filter(':checked');
        var total = $checkboxes.length;
        var checkedCount = $checked.length;
        
        if (checkedCount === 0) {
            // No selections - show default text based on filter type
            var filterType = $toggle.data('filter-type');
            if (filterType === 'licenses') {
                $selectedText.text('All Licenses');
            } else if (filterType === 'top_geos') {
                $selectedText.text('All Geos');
            } else if (filterType === 'payment_methods') {
                $selectedText.text('All Payments');
            } else {
                $selectedText.text('All');
            }
        } else if (checkedCount === 1) {
            // One selected - show the value
            $selectedText.text($checked.closest('label').find('span').text());
        } else if (checkedCount === total) {
            // All selected - show "All [Type]"
            var filterType = $toggle.data('filter-type');
            if (filterType === 'licenses') {
                $selectedText.text('All Licenses');
            } else if (filterType === 'top_geos') {
                $selectedText.text('All Geos');
            } else if (filterType === 'payment_methods') {
                $selectedText.text('All Payments');
            } else {
                $selectedText.text('All Selected');
            }
        } else {
            // Multiple selected - show count
            $selectedText.text(checkedCount + ' selected');
        }
    }
    
    // Update selected text when Select All is clicked
    $('.dataflair-multiselect-select-all').on('click', function(e) {
        e.preventDefault();
        var $dropdown = $(this).closest('.dataflair-multiselect-dropdown');
        $dropdown.find('.dataflair-multiselect-options input[type="checkbox"]').prop('checked', true);
        updateSelectedText($dropdown);
        applyFiltersAndSort();
    });
    
    // Close dropdowns when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.filter-group').length) {
            $('.dataflair-multiselect-dropdown').hide();
        }
    });
    
    // Clear all filters
    $('#dataflair-clear-all-filters').on('click', function() {
        $('.dataflair-multiselect-options input[type="checkbox"]').prop('checked', false);
        // Update all button texts
        $('.dataflair-multiselect-dropdown').each(function() {
            updateSelectedText($(this));
        });
        applyFiltersAndSort();
    });
    
    // Function to get selected values for a filter type
    function getSelectedFilterValues(filterType) {
        var values = [];
        $('.dataflair-multiselect-toggle[data-filter-type="' + filterType + '"]')
            .next('.dataflair-multiselect-dropdown')
            .find('input[type="checkbox"]:checked')
            .each(function() {
                values.push($(this).val());
            });
        return values;
    }
    
    // Sorting functionality
    var currentSort = { field: null, direction: 'asc' };
    
    $('.sort-link').on('click', function(e) {
        e.preventDefault();
        var $link = $(this);
        var sortField = $link.data('sort');
        
        // Toggle direction if clicking same field
        if (currentSort.field === sortField) {
            currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
        } else {
            currentSort.field = sortField;
            currentSort.direction = 'asc';
        }
        
        // Update UI
        $('.sort-link').removeClass('sorted-asc sorted-desc');
        $('.sorting-indicator .dashicons').removeClass('dashicons-arrow-up dashicons-arrow-down').addClass('dashicons-sort');
        
        $link.addClass('sorted-' + currentSort.direction);
        var $icon = $link.find('.sorting-indicator .dashicons');
        $icon.removeClass('dashicons-sort').addClass(currentSort.direction === 'asc' ? 'dashicons-arrow-up' : 'dashicons-arrow-down');
        
        // Apply sort
        sortBrands(sortField, currentSort.direction);
    });
    
    function sortBrands(field, direction) {
        var $table = $('.dataflair-brands-table tbody');
        var $rows = $table.find('tr.brand-row').get();
        
        $rows.sort(function(a, b) {
            var aVal, bVal;
            
            if (field === 'name') {
                aVal = $(a).data('brandName');
                bVal = $(b).data('brandName');
                return direction === 'asc' ? 
                    (aVal < bVal ? -1 : aVal > bVal ? 1 : 0) : 
                    (aVal > bVal ? -1 : aVal < bVal ? 1 : 0);
            } else if (field === 'offers' || field === 'trackers') {
                aVal = parseInt($(a).data(field + 'Count')) || 0;
                bVal = parseInt($(b).data(field + 'Count')) || 0;
                return direction === 'asc' ? aVal - bVal : bVal - aVal;
            }
            
            return 0;
        });
        
        // Re-append rows in sorted order
        $.each($rows, function(index, row) {
            var $row = $(row);
            var $detailRow = $row.next('.brand-details');
            $table.append($row);
            if ($detailRow.length) {
                $table.append($detailRow);
            }
        });
        
        // Reset to first page and update pagination after sorting
        currentPage = 1;
        updatePagination();
    }
    
    // Pagination variables
    var itemsPerPage = 50;
    var currentPage = 1;
    var totalPages = 1;
    
    function updatePagination() {
        var $visibleRows = $('.dataflair-brands-table tbody tr.brand-row:visible');
        var totalItems = $visibleRows.length;
        totalPages = Math.ceil(totalItems / itemsPerPage);
        
        if (currentPage > totalPages) {
            currentPage = totalPages || 1;
        }
        
        $('#total-pages').text(totalPages);
        $('#current-page-selector').val(currentPage);
        
        // Update button states
        $('#pagination-first, #pagination-prev').prop('disabled', currentPage === 1);
        $('#pagination-next, #pagination-last').prop('disabled', currentPage === totalPages || totalPages === 0);
        
        // Display current page
        displayPage(currentPage);
    }
    
    function displayPage(page) {
        var $allRows = $('.dataflair-brands-table tbody tr.brand-row:visible');
        var start = (page - 1) * itemsPerPage;
        var end = start + itemsPerPage;
        
        $allRows.each(function(index) {
            var $row = $(this);
            var $detailRow = $row.next('.brand-details');
            
            if (index >= start && index < end) {
                $row.show();
            } else {
                $row.hide();
                $detailRow.hide();
            }
        });
    }
    
    function applyFiltersAndSort() {
        var selectedLicenses = getSelectedFilterValues('licenses');
        var selectedGeos = getSelectedFilterValues('top_geos');
        var selectedPaymentMethods = getSelectedFilterValues('payment_methods');
        
        var $table = $('.dataflair-brands-table');
        var $rows = $table.find('tbody tr.brand-row');
        var visibleCount = 0;
        
        $rows.each(function() {
            var $row = $(this);
            var rowData = $row.data('brand-data');
            
            var matchesLicenses = selectedLicenses.length === 0;
            var matchesGeos = selectedGeos.length === 0;
            var matchesPayments = selectedPaymentMethods.length === 0;
            
            // Check licenses
            if (!matchesLicenses && rowData.licenses) {
                for (var i = 0; i < selectedLicenses.length; i++) {
                    if (rowData.licenses.indexOf(selectedLicenses[i]) > -1) {
                        matchesLicenses = true;
                        break;
                    }
                }
            }
            
            // Check geos
            if (!matchesGeos && rowData.topGeos) {
                for (var i = 0; i < selectedGeos.length; i++) {
                    if (rowData.topGeos.indexOf(selectedGeos[i]) > -1) {
                        matchesGeos = true;
                        break;
                    }
                }
            }
            
            // Check payment methods
            if (!matchesPayments && rowData.paymentMethods) {
                for (var i = 0; i < selectedPaymentMethods.length; i++) {
                    if (rowData.paymentMethods.indexOf(selectedPaymentMethods[i]) > -1) {
                        matchesPayments = true;
                        break;
                    }
                }
            }
            
            if (matchesLicenses && matchesGeos && matchesPayments) {
                $row.show();
                visibleCount++;
            } else {
                $row.hide();
                $row.next('.brand-details').hide();
            }
        });
        
        // Update count
        var totalCount = $rows.length;
        $('#dataflair-brands-count').text('Showing ' + visibleCount + ' of ' + totalCount + ' brands');
        
        // Reset to first page
        currentPage = 1;
        updatePagination();
    }
    
    // First page
    $('#pagination-first').on('click', function(e) {
        e.preventDefault();
        if (currentPage !== 1) {
            currentPage = 1;
            updatePagination();
        }
    });
    
    // Previous page
    $('#pagination-prev').on('click', function(e) {
        e.preventDefault();
        if (currentPage > 1) {
            currentPage--;
            updatePagination();
        }
    });
    
    // Next page
    $('#pagination-next').on('click', function(e) {
        e.preventDefault();
        if (currentPage < totalPages) {
            currentPage++;
            updatePagination();
        }
    });
    
    // Last page
    $('#pagination-last').on('click', function(e) {
        e.preventDefault();
        if (currentPage !== totalPages) {
            currentPage = totalPages;
            updatePagination();
        }
    });
    
    // Manual page input
    $('#current-page-selector').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            var page = parseInt($(this).val());
            var totalPages = parseInt($('#total-pages').text());
            
            if (page >= 1 && page <= totalPages) {
                currentPage = page;
                updatePagination();
            } else {
                $(this).val(currentPage);
            }
        }
    });
    
    $('#current-page-selector').on('blur', function() {
        var page = parseInt($(this).val());
        var totalPages = parseInt($('#total-pages').text());
        
        if (page >= 1 && page <= totalPages) {
            currentPage = page;
            updatePagination();
        } else {
            $(this).val(currentPage);
        }
    });
    
    // Initialize pagination on page load
    updatePagination();
    
    // Initialize selected text for all filters on page load
    $('.dataflair-multiselect-dropdown').each(function() {
        updateSelectedText($(this));
    });
    
    // ========================================
    // Alternative Toplists Functionality
    // ========================================
    
    // Toggle toplist accordion
    $(document).on('click', '.toplist-toggle-btn', function() {
        var $button = $(this);
        var $icon = $button.find('.dashicons');
        var toplistId = $button.closest('tr').data('toplist-id');
        var $accordionRow = $('.toplist-accordion-content[data-toplist-id="' + toplistId + '"]');
        
        if ($accordionRow.is(':visible')) {
            $icon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-right');
            $accordionRow.slideUp(200);
        } else {
            $icon.removeClass('dashicons-arrow-right').addClass('dashicons-arrow-down');
            $accordionRow.slideDown(200);
            
            // Load geos and alternative toplists for this toplist
            loadGeosForToplist(toplistId);
            loadAlternativeToplists(toplistId);
        }
    });
    
    // Load available geos
    function loadGeosForToplist(toplistId) {
        var $row = $('.toplist-accordion-content[data-toplist-id="' + toplistId + '"]');
        var $geoSelect = $row.find('.alt-geo-select');
        
        // Only load if not already loaded
        if ($geoSelect.find('option').length > 1) {
            return;
        }
        
        $.ajax({
            url: dataflairAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dataflair_get_available_geos',
                nonce: dataflairAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data.geos) {
                    $geoSelect.empty().append('<option value="">Select a geo...</option>');
                    response.data.geos.forEach(function(geo) {
                        $geoSelect.append('<option value="' + geo + '">' + geo + '</option>');
                    });
                }
            }
        });
    }
    
    // Load existing alternative toplists
    function loadAlternativeToplists(toplistId) {
        var $row = $('.toplist-accordion-content[data-toplist-id="' + toplistId + '"]');
        var $list = $row.find('.alternative-toplists-list');
        
        $list.html('<p>Loading...</p>');
        
        $.ajax({
            url: dataflairAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dataflair_get_alternative_toplists',
                nonce: dataflairAdmin.nonce,
                toplist_id: toplistId
            },
            success: function(response) {
                if (response.success) {
                    var alternatives = response.data.alternatives;
                    
                    if (alternatives.length === 0) {
                        $list.html('<p style="color: #999;">No alternative toplists set yet.</p>');
                    } else {
                        var html = '<table class="widefat" style="margin-bottom: 15px;"><thead><tr><th>Geo</th><th>Alternative Toplist ID</th><th>Actions</th></tr></thead><tbody>';
                        
                        alternatives.forEach(function(alt) {
                            html += '<tr>';
                            html += '<td>' + alt.geo + '</td>';
                            html += '<td>' + alt.alternative_toplist_id + '</td>';
                            html += '<td><button type="button" class="button button-small delete-alternative-toplist" data-alt-id="' + alt.id + '">Delete</button></td>';
                            html += '</tr>';
                        });
                        
                        html += '</tbody></table>';
                        $list.html(html);
                    }
                } else {
                    $list.html('<p style="color: #dc3232;">Error loading alternative toplists.</p>');
                }
            },
            error: function() {
                $list.html('<p style="color: #dc3232;">Error loading alternative toplists.</p>');
            }
        });
    }
    
    // Save alternative toplist
    $(document).on('click', '.save-alternative-toplist', function() {
        var $button = $(this);
        var $row = $button.closest('.toplist-accordion-content');
        var toplistId = $row.data('toplist-id');
        var geo = $row.find('.alt-geo-select').val();
        var altToplistId = $row.find('.alt-toplist-select').val();
        var $message = $row.find('.alt-save-message');
        
        if (!geo) {
            $message.html('<span style="color: #dc3232;">Please select a geo.</span>');
            setTimeout(function() { $message.html(''); }, 3000);
            return;
        }
        
        if (!altToplistId) {
            $message.html('<span style="color: #dc3232;">Please select an alternative toplist.</span>');
            setTimeout(function() { $message.html(''); }, 3000);
            return;
        }
        
        $button.prop('disabled', true).text('Saving...');
        $message.html('');
        
        $.ajax({
            url: dataflairAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dataflair_save_alternative_toplist',
                nonce: dataflairAdmin.nonce,
                toplist_id: toplistId,
                geo: geo,
                alternative_toplist_id: altToplistId
            },
            success: function(response) {
                if (response.success) {
                    $message.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
                    $button.prop('disabled', false).text('Add Alternative');
                    
                    // Reset selects
                    $row.find('.alt-geo-select').val('');
                    $row.find('.alt-toplist-select').val('');
                    
                    // Reload the list
                    loadAlternativeToplists(toplistId);
                    
                    setTimeout(function() { $message.html(''); }, 3000);
                } else {
                    $message.html('<span style="color: #dc3232;">✗ ' + (response.data.message || 'Error saving') + '</span>');
                    $button.prop('disabled', false).text('Add Alternative');
                }
            },
            error: function() {
                $message.html('<span style="color: #dc3232;">✗ Error saving. Please try again.</span>');
                $button.prop('disabled', false).text('Add Alternative');
            }
        });
    });
    
    // Delete alternative toplist
    $(document).on('click', '.delete-alternative-toplist', function() {
        var $button = $(this);
        var altId = $button.data('alt-id');
        var $row = $button.closest('.toplist-accordion-content');
        var toplistId = $row.data('toplist-id');
        
        if (!confirm('Are you sure you want to delete this alternative toplist mapping?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Deleting...');
        
        $.ajax({
            url: dataflairAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dataflair_delete_alternative_toplist',
                nonce: dataflairAdmin.nonce,
                id: altId
            },
            success: function(response) {
                if (response.success) {
                    // Reload the list
                    loadAlternativeToplists(toplistId);
                } else {
                    alert('Error deleting: ' + (response.data.message || 'Unknown error'));
                    $button.prop('disabled', false).text('Delete');
                }
            },
            error: function() {
                alert('Error deleting. Please try again.');
                $button.prop('disabled', false).text('Delete');
            }
        });
    });
});
