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
            dataflair_api_base_url: $('#dataflair_api_base_url').val() || '',
            dataflair_ribbon_bg_color: $('#dataflair_ribbon_bg_color').val() || '',
            dataflair_ribbon_text_color: $('#dataflair_ribbon_text_color').val() || '',
            dataflair_cta_bg_color: $('#dataflair_cta_bg_color').val() || '',
            dataflair_cta_text_color: $('#dataflair_cta_text_color').val() || '',
            dataflair_brands_api_version: $('input[name="dataflair_brands_api_version"]:checked').val() || 'v1'
        };
        
        // Send AJAX request
        $.ajax({
            url: dataflairAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            timeout: 10000, // 10 second timeout
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
            error: function(xhr, status, error) {
                var errorMsg = 'Error saving settings. Please try again.';
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                $message.html('<span style="color: #dc3232;">✗ ' + errorMsg + '</span>');
                $button.val(originalText).prop('disabled', false);
            }
        });
    });
    
    // Handle fetch all toplists button with batch processing
    $('#dataflair-fetch-all-toplists').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $message = $('#dataflair-fetch-message');
        var $progressBar = $('#dataflair-toplist-sync-progress');
        var $progressBarInner = $('#dataflair-toplist-progress-bar');
        var $progressText = $('#dataflair-toplist-progress-text');
        var originalText = $button.text();
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Fetching...');
        $message.removeClass('error').html('<span style="color: #0073aa;">⏳ Starting sync...</span>');
        
        // Show progress bar
        $progressBar.show();
        $progressBarInner.css('width', '0%');
        $progressText.text('0%');
        
        // Send initial AJAX request
        $.ajax({
            url: dataflairAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dataflair_fetch_all_toplists',
                nonce: dataflairAdmin.fetchNonce
            },
            success: function(response) {
                if (response.success && response.data.start_batch) {
                    // Sequential mode (concurrency=1) — diagnosing sigma upstream
                    // contention. The bulk ?include=items endpoint times out at
                    // 0 bytes when 3 in-flight; running serial eliminates queueing.
                    runToplistsPool(1);
                } else {
                    $message.html('<span style="color: #dc3232;">✗ ' + (response.data.message || 'Error starting sync') + '</span>');
                    $button.text(originalText).prop('disabled', false);
                    $progressBar.hide();
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'An error occurred while starting sync.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                $message.html('<span style="color: #dc3232;">✗ ' + errorMsg + '</span>');
                $button.text(originalText).prop('disabled', false);
                $progressBar.hide();
            }
        });

        // Parallel toplists sync pool.
        // - Fetch page 1 sequentially to learn last_page.
        // - Fan out pages 2..last_page with up to `concurrency` in flight.
        // - Each page handles its own partial-retry backoff independently
        //   (H13 Phase 0B: 1s → 2s → 4s → cap 8s, max 10 retries per page).
        // - Inter-page pause was 500ms; now 0 — the upstream isn't rate-
        //   limiting itself by client cadence, only by latency.
        function fetchToplistPage(page, partialCount, onDone) {
            partialCount = partialCount || 0;
            $.ajax({
                url: dataflairAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dataflair_sync_toplists_batch',
                    nonce: dataflairAdmin.syncToplistsBatchNonce,
                    page: page
                },
                success: function(response) {
                    if (!response.success) {
                        onDone({page: page, ok: false, message: (response.data && response.data.message) || 'Unknown error'});
                        return;
                    }
                    var data = response.data;
                    if (data.partial === true && !data.is_complete) {
                        var nextPartialCount = partialCount + 1;
                        if (nextPartialCount > 3) {
                            onDone({page: page, ok: false, message: 'stuck after 3 partial retries — page consistently exceeds budget; check debug.log for ToplistSync.http and ToplistSync.store_slow entries'});
                            return;
                        }
                        var delayMs = Math.min(1000 * Math.pow(2, nextPartialCount - 1), 4000);
                        setTimeout(function() { fetchToplistPage(page, nextPartialCount, onDone); }, delayMs);
                        return;
                    }
                    onDone({
                        page:      page,
                        ok:        true,
                        synced:    data.synced || 0,
                        errors:    data.errors || 0,
                        skipped:   !!data.skipped,
                        last_page: data.last_page,
                        is_complete: !!data.is_complete
                    });
                },
                error: function() {
                    onDone({page: page, ok: false, message: 'network error'});
                }
            });
        }

        function runToplistsPool(concurrency) {
            var totalSynced = 0;
            var totalErrors = 0;
            var skippedPages = [];
            var fatal = false;

            // Step 1: page 1 alone, to learn last_page.
            fetchToplistPage(1, 0, function(r1) {
                if (!r1.ok) {
                    $message.html('<span style="color: #dc3232;">✗ Error on page 1: ' + (r1.message || 'unknown') + '</span>');
                    $button.text(originalText).prop('disabled', false);
                    return;
                }
                totalSynced  += r1.synced;
                totalErrors  += r1.errors;
                if (r1.skipped) skippedPages.push(1);

                var lastPage   = r1.last_page || 1;
                var queue      = [];
                for (var p = 2; p <= lastPage; p++) queue.push(p);
                var inFlight   = 0;
                var pagesDone  = 1;
                var totalPages = lastPage;

                $progressBarInner.css('width', Math.round((pagesDone / totalPages) * 100) + '%');
                $progressText.text(Math.round((pagesDone / totalPages) * 100) + '% - Page ' + pagesDone + ' of ' + totalPages);
                $message.html('<span style="color: #0073aa;">⏳ Synced ' + totalSynced + ' toplists...</span>');

                if (queue.length === 0 || r1.is_complete) {
                    finishToplistsSync(totalSynced, totalErrors, skippedPages);
                    return;
                }

                function pump() {
                    if (fatal) return;
                    while (inFlight < concurrency && queue.length > 0) {
                        var p = queue.shift();
                        inFlight++;
                        fetchToplistPage(p, 0, function(res) {
                            inFlight--;
                            if (!res.ok) {
                                totalErrors++;
                                skippedPages.push(res.page);
                            } else {
                                totalSynced += res.synced;
                                totalErrors += res.errors;
                                if (res.skipped) skippedPages.push(res.page);
                            }
                            pagesDone++;
                            var pct = Math.min(100, Math.round((pagesDone / totalPages) * 100));
                            $progressBarInner.css('width', pct + '%');
                            $progressText.text(pct + '% - Page ' + pagesDone + ' of ' + totalPages);
                            $message.html('<span style="color: #0073aa;">⏳ Synced ' + totalSynced + ' toplists...</span>');

                            if (queue.length === 0 && inFlight === 0) {
                                finishToplistsSync(totalSynced, totalErrors, skippedPages);
                            } else {
                                pump();
                            }
                        });
                    }
                }
                pump();
            });
        }

        function finishToplistsSync(totalSynced, totalErrors, skippedPages) {
            $progressBarInner.css('width', '100%');
            $progressText.text('100% Complete');
            var finalMsg = '<span style="color: #46b450;">✓ Successfully synced ' + totalSynced + ' toplists!</span>';
            if (totalErrors > 0) {
                finalMsg += ' <span style="color: #dc3232;">(' + totalErrors + ' errors - check debug.log)</span>';
            }
            if (skippedPages.length > 0) {
                skippedPages.sort(function(a, b) { return a - b; });
                finalMsg += ' <span style="color: #d98500;">(skipped page' + (skippedPages.length > 1 ? 's' : '') + ' ' + skippedPages.join(', ') + ' — will retry on next sync)</span>';
            }
            $message.html(finalMsg);
            $button.text(originalText).prop('disabled', false);
            setTimeout(function() { location.reload(); }, 2000);
        }
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
        
        // H13 (Phase 0B): same partial-loop backoff as the toplists handler.
        // 1s → 2s → 4s → cap 8s, cap 10 consecutive partials per page.
        function syncBrandsBatch(page, totalSynced, partialCount) {
            partialCount = partialCount || 0;
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

                        var statusMsg = '<span style="color: #0073aa;">⏳ Synced ' + totalSynced + ' brands';
                        if (data.partial) {
                            statusMsg += ' (page ' + page + ' partial — budget yielded, retrying)';
                        }
                        statusMsg += '...</span>';
                        $message.html(statusMsg);

                        // Partial response → re-issue the same page with backoff.
                        if (data.partial === true && !data.is_complete) {
                            var nextPartialCount = partialCount + 1;
                            if (nextPartialCount > 3) {
                                $message.html('<span style="color: #dc3232;">✗ Page ' + page + ' stuck after 3 partial retries — aborting. Check debug.log for slow upstream/store entries. <a href="#" class="dataflair-dismiss-error">dismiss</a></span>');
                                $button.text(originalText).prop('disabled', false);
                                return;
                            }
                            var delayMs = Math.min(1000 * Math.pow(2, nextPartialCount - 1), 4000);
                            setTimeout(function() {
                                syncBrandsBatch(page, totalSynced, nextPartialCount);
                            }, delayMs);
                            return;
                        }

                        // Check if there are more pages
                        if (!data.is_complete && page < data.last_page) {
                            // No artificial pause — upstream latency already
                            // gates request cadence.
                            syncBrandsBatch(page + 1, totalSynced, 0);
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

        // Dismissible partial-loop error handler — shared across both buttons.
        $(document).on('click', '.dataflair-dismiss-error', function(e) {
            e.preventDefault();
            $(this).closest('span').fadeOut(200);
        });
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
    
    // Initialize Select2 for brand filters (only if Select2 is available)
    if (typeof $.fn.select2 !== 'undefined') {
        $('#dataflair-filter-licenses').select2({
            placeholder: 'All Licenses',
            allowClear: true,
            width: '100%'
        });
        
        $('#dataflair-filter-top-geos').select2({
            placeholder: 'All Geos',
            allowClear: true,
            width: '100%'
        });
        
        $('#dataflair-filter-payment-methods').select2({
            placeholder: 'All Payments',
            allowClear: true,
            width: '100%'
        });
        
        // Apply filters when Select2 changes — debounced so rapid chip
        // add/remove batches into a single call instead of firing per chip
        var debouncedApply = debounce(applyFiltersAndSort, 200);
        $('.dataflair-select2').on('change', debouncedApply);
    }
    
    // Debounce — delays fn until ms have passed since the last call
    function debounce(fn, ms) {
        var timer;
        return function() { clearTimeout(timer); timer = setTimeout(fn, ms); };
    }

    // Cached filter state — refreshed once per applyFiltersAndSort call,
    // then reused by every rowMatchesFilters call to avoid N×3 DOM reads
    var activeFilters = { licenses: [], geos: [], payments: [] };

    function refreshActiveFilters() {
        activeFilters.licenses = getSelectedFilterValues('licenses');
        activeFilters.geos     = getSelectedFilterValues('top_geos');
        activeFilters.payments = getSelectedFilterValues('payment_methods');
    }

    // Function to get selected values for a filter type (updated for Select2)
    function getSelectedFilterValues(filterType) {
        var $select = $('.dataflair-select2[data-filter-type="' + filterType + '"]');
        var selectedValues = $select.val();
        if (!selectedValues || !Array.isArray(selectedValues)) {
            return [];
        }
        return selectedValues.filter(function(value) {
            return value !== null && value !== '';
        });
    }
    
    // Clear all filters
    $('#dataflair-clear-all-filters').on('click', function() {
        if (typeof $.fn.select2 !== 'undefined') {
            $('.dataflair-select2').val(null).trigger('change');
        }
        applyFiltersAndSort();
    });
    
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
    var itemsPerPage = 20; // Default to 20 items per page
    var currentPage = 1;
    var totalPages = 1;
    var isUpdatingPagination = false; // Prevent concurrent updates
    
    // Helper function to check if a row matches current filters
    function rowMatchesFilters($row) {
        var rowData = $row.data('brand-data');
        if (!rowData) return false;

        // Use pre-cached activeFilters — no DOM reads inside the loop
        var matchesLicenses = activeFilters.licenses.length === 0;
        var matchesGeos     = activeFilters.geos.length === 0;
        var matchesPayments = activeFilters.payments.length === 0;

        if (!matchesLicenses && rowData.licenses) {
            for (var i = 0; i < activeFilters.licenses.length; i++) {
                if (rowData.licenses.indexOf(activeFilters.licenses[i]) > -1) { matchesLicenses = true; break; }
            }
        }
        if (!matchesGeos && rowData.topGeos) {
            for (var i = 0; i < activeFilters.geos.length; i++) {
                if (rowData.topGeos.indexOf(activeFilters.geos[i]) > -1) { matchesGeos = true; break; }
            }
        }
        if (!matchesPayments && rowData.paymentMethods) {
            for (var i = 0; i < activeFilters.payments.length; i++) {
                if (rowData.paymentMethods.indexOf(activeFilters.payments[i]) > -1) { matchesPayments = true; break; }
            }
        }

        return matchesLicenses && matchesGeos && matchesPayments;
    }
    
    function updatePagination() {
        // Prevent concurrent calls
        if (isUpdatingPagination) {
            return;
        }
        isUpdatingPagination = true;
        
        try {
            // Get all brand rows and count those that match filters
            // This count should not be affected by pagination hiding rows
            var $allRows = $('.dataflair-brands-table tbody tr.brand-row');
            var visibleCount = 0;
            
            // Count filtered rows (regardless of current visibility)
            $allRows.each(function() {
                if (rowMatchesFilters($(this))) {
                    visibleCount++;
                }
            });
            
            var totalItems = visibleCount;
            var totalCount = $allRows.length;
            totalPages = Math.ceil(totalItems / itemsPerPage);

            // Ensure totalPages is at least 1 if there are items
            if (totalItems > 0 && totalPages === 0) {
                totalPages = 1;
            }

            // Ensure currentPage is valid
            if (currentPage > totalPages && totalPages > 0) {
                currentPage = totalPages;
            } else if (totalPages === 0) {
                currentPage = 1;
            }

            // Update brand count (single source of truth — here we already have visibleCount)
            $('#dataflair-brands-count').text('Showing ' + totalItems + ' of ' + totalCount + ' brands');

            // Update UI
            $('#total-pages').text(totalPages);
            $('#current-page-selector').val(currentPage);
            
            // Update button states
            $('#pagination-first, #pagination-prev').prop('disabled', currentPage === 1 || totalPages === 0);
            $('#pagination-next, #pagination-last').prop('disabled', currentPage === totalPages || totalPages === 0);
            
            // Display current page (this will show/hide rows based on pagination)
            displayPage(currentPage);
        } finally {
            isUpdatingPagination = false;
        }
    }
    
    function displayPage(page) {
        // Get all brand rows
        var $allRows = $('.dataflair-brands-table tbody tr.brand-row');
        var start = (page - 1) * itemsPerPage;
        var end = start + itemsPerPage;
        
        var visibleIndex = 0;
        
        $allRows.each(function() {
            var $row = $(this);
            var $detailRow = $row.next('.brand-details');
            var matchesFilters = rowMatchesFilters($row);
            
            if (matchesFilters) {
                // Row matches filters - show or hide based on pagination
                if (visibleIndex >= start && visibleIndex < end) {
                    $row.show();
                } else {
                    $row.hide();
                    $detailRow.hide();
                }
                visibleIndex++;
            } else {
                // Row doesn't match filters - always hide
                $row.hide();
                $detailRow.hide();
            }
        });
    }
    
    function applyFiltersAndSort() {
        // Refresh cached filter values once — rowMatchesFilters + updatePagination
        // will both use these without touching the DOM again
        refreshActiveFilters();
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
        // Re-read totalPages from DOM to ensure we have latest value
        var currentTotalPages = parseInt($('#total-pages').text()) || totalPages;
        if (currentPage < currentTotalPages && currentTotalPages > 0) {
            currentPage++;
            updatePagination();
        }
    });
    
    // Last page
    $('#pagination-last').on('click', function(e) {
        e.preventDefault();
        // Re-read totalPages from DOM to ensure we have latest value
        var currentTotalPages = parseInt($('#total-pages').text()) || totalPages;
        if (currentTotalPages > 0 && currentPage !== currentTotalPages) {
            currentPage = currentTotalPages;
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
    
    // Items per page selector
    $('#items-per-page-selector').on('change', function() {
        itemsPerPage = parseInt($(this).val()) || 20;
        // Reset to first page when changing items per page
        currentPage = 1;
        updatePagination();
    });
    
    // Initialize items per page selector
    $('#items-per-page-selector').val(itemsPerPage);
    
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

    // =========================================================
    // TOPLISTS — template filter + sortable columns
    // =========================================================
    if ($('.dataflair-toplists-table').length) {

        // Init Select2 for template filter
        if (typeof $.fn.select2 !== 'undefined') {
            $('#dataflair-filter-template').select2({
                placeholder: 'All Templates',
                allowClear: true,
                width: '100%'
            });
            $('#dataflair-filter-template').on('change', debounce(applyToplistFiltersAndSort, 200));
        }

        // Clear filter
        $('#dataflair-clear-toplist-filters').on('click', function() {
            $('#dataflair-filter-template').val(null).trigger('change');
        });

        // Sort state
        var toplistSort = { field: null, direction: 'asc' };

        // Sortable column click
        $(document).on('click', '.toplist-sort-link', function(e) {
            e.preventDefault();
            var field = $(this).data('sort');
            if (toplistSort.field === field) {
                toplistSort.direction = toplistSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                toplistSort.field = field;
                toplistSort.direction = 'asc';
            }
            // Update indicators
            $('.toplist-sort-indicator').text('');
            $(this).find('.toplist-sort-indicator').text(toplistSort.direction === 'asc' ? ' ↑' : ' ↓');
            applyToplistFiltersAndSort();
        });

        function applyToplistFiltersAndSort() {
            var selectedTemplate = $('#dataflair-filter-template').val() || '';
            var $tbody = $('.dataflair-toplists-table tbody');
            var $rows  = $tbody.find('tr.toplist-row');
            var total  = $rows.length;

            // Collect row + accordion pairs
            var pairs = [];
            $rows.each(function() {
                var $row = $(this);
                pairs.push({ row: $row, accordion: $row.next('.toplist-accordion-content') });
            });

            // Filter
            var visible = pairs.filter(function(p) {
                var template = p.row.data('template') || '';
                var matches  = !selectedTemplate || template === selectedTemplate;
                if (!matches) {
                    p.row.hide();
                    p.accordion.hide();
                } else {
                    p.row.show();
                }
                return matches;
            });

            // Sort visible pairs
            if (toplistSort.field) {
                visible.sort(function(a, b) {
                    var av, bv;
                    if (toplistSort.field === 'template') {
                        av = (a.row.data('template') || '').toLowerCase();
                        bv = (b.row.data('template') || '').toLowerCase();
                    } else if (toplistSort.field === 'items') {
                        av = parseInt(a.row.data('items'), 10) || 0;
                        bv = parseInt(b.row.data('items'), 10) || 0;
                    } else if (toplistSort.field === 'last_synced') {
                        av = a.row.data('last-synced') || '';
                        bv = b.row.data('last-synced') || '';
                    }
                    if (av < bv) return toplistSort.direction === 'asc' ? -1 : 1;
                    if (av > bv) return toplistSort.direction === 'asc' ?  1 : -1;
                    return 0;
                });
                // Re-append in sorted order
                $.each(visible, function(i, p) {
                    $tbody.append(p.row).append(p.accordion);
                });
            }

            $('#dataflair-toplists-count').text('Showing ' + visible.length + ' of ' + total + ' toplists');
        }
    }

});
