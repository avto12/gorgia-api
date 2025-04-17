// Countdown timer for admin layout (for cron-based sync)
 
document.addEventListener('DOMContentLoaded', function () {
    var countdownContainer = document.getElementById('countdown-timer');
    if (!countdownContainer) return;

    var countdownElement = document.getElementById('time-remaining');
    var timeRemaining = parseInt(countdownContainer.dataset.remaining, 10);

    if (isNaN(timeRemaining)) return;

    function pad(num) {
        return num.toString().padStart(1, '0');
    }

    function updateCountdown() {
        if (timeRemaining > 0) {
            var days = Math.floor(timeRemaining / (24 * 60 * 60));
            var hours = Math.floor((timeRemaining % (24 * 60 * 60)) / (60 * 60));
            var minutes = Math.floor((timeRemaining % (60 * 60)) / 60);
            var seconds = timeRemaining % 60;

            countdownElement.textContent =
            days + 'd ' + pad(hours) + 'h ' + pad(minutes) + 'm ' + pad(seconds) + 's';

            timeRemaining--;
            setTimeout(updateCountdown, 1000);
        } else {
            countdownElement.textContent = 'Sync in progress...';
        }
    }

    updateCountdown();
});

// Wait for the DOM to be fully loaded before executing the script
document.addEventListener('DOMContentLoaded', function () {
    // --- Countdown Timer for Cron-Based Sync ---
    // Get the countdown elements from the DOM
    const countdownElement = document.getElementById('time-remaining-product-update');
    const countdownContainer = document.getElementById('countdown-timer-product-update');

    // Check if countdown elements exist
    if (countdownElement && countdownContainer) {
        let timeRemaining = parseInt(countdownContainer.getAttribute('data-remaining'), 10);

        // Validate the time remaining value
        if (isNaN(timeRemaining)) {
            console.error("Invalid time remaining value for product update countdown");
        } else if (timeRemaining > 0) {
            // Function to update the countdown timer every second
            const updateCountdown = () => {
                if (timeRemaining <= 0) {
                    countdownElement.textContent = 'Syncing now...';
                    // Reload the page after 1 second to reflect the cron sync
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                    return;
                }

                // Calculate hours, minutes, and seconds from the remaining time
                const hours = Math.floor(timeRemaining / 3600);
                const minutes = Math.floor((timeRemaining % 3600) / 60);
                const seconds = timeRemaining % 60;

                countdownElement.textContent = `${hours}h ${minutes}m ${seconds}s`;
                timeRemaining--;

                // Continue the countdown every second
                setTimeout(updateCountdown, 1000);
            };

            // Start the countdown
            updateCountdown();
        } else if (countdownElement.textContent === '') {
            countdownElement.textContent = 'Disabled';
        }
    } else {
        console.warn('Countdown timer elements not found. Skipping countdown functionality.');
    }

    // --- Manual Sync with Batch Processing ---
    // Get the elements for manual sync
    const syncButton = document.getElementById('syncwoo-manual-button');
    const cancelButton = document.getElementById('syncwoo-manual-cancel');
    const resultDiv = document.getElementById('syncwoo-manual-result');
    const progressBar = document.querySelector('.progress-bar-manual');

    // Initialize sync state variables
    let syncInProgress = false;
    let controller = null;
    let processedCount = 0;
    let newCount = 0;
    let updatedCount = 0;
    let deletedCount = 0;
    const batchSize = 20; // Process 10 products at a time
    const delayBetweenBatches = 60 * 1000; // Delay between batches in milliseconds (60 seconds)

    // Check if all required elements for manual sync exist
    if (!syncButton || !cancelButton || !resultDiv || !progressBar) {
        console.warn('One or more required elements are missing for manual sync. Skipping sync functionality.');
        return;
    }

    // Add event listener for the "Start Sync" button
    syncButton.addEventListener('click', async function () {
        // Prevent multiple syncs from running simultaneously
        if (syncInProgress) {
            alert("Manual sync is already in progress. Please wait.");
            return;
        }

        syncInProgress = true;
        controller = new AbortController();
        const button = this;
        button.disabled = true;
        cancelButton.disabled = false;
        button.innerHTML = '<span class="spinner is-active"></span> Syncing...';

        // Reset UI and counters
        resultDiv.innerHTML = '<div class="notice notice-info"><p>Starting manual sync...</p></div>';
        progressBar.style.width = '0%';
        processedCount = 0;
        newCount = 0;
        updatedCount = 0;
        deletedCount = 0;

        // Clear any scheduled cron jobs during manual sync
        const responseClearCron = await fetch(syncwoo_vars.syncwoo_ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: new URLSearchParams({
                action: 'syncwoo_clear_cron',
                nonce: syncwoo_vars.syncwoo_nonce
            })
        });

        if (!responseClearCron.ok) {
            console.error('Failed to clear cron jobs');
        }

        try {
            let totalProducts = null;

            // Process batches until all products are synced or sync is cancelled
            while (syncInProgress) {
                // Send AJAX request to process a batch of products
                const response = await fetch(syncwoo_vars.syncwoo_ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    signal: controller.signal,
                    body: new URLSearchParams({
                        action: 'syncwoo_perform_sync',
                        nonce: syncwoo_vars.syncwoo_nonce,
                        processed_count: processedCount,
                        batch_size: batchSize
                    })
                });

                // Check if the response is successful
                if (!response.ok) {
                    const text = await response.text();
                    throw new Error(`Server error (${response.status}): ${text}`);
                }

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.data?.message || 'Unknown error');
                }

                // Update counters with the response data
                processedCount = data.data.processed_count;
                newCount += data.data.results.new_products;
                updatedCount += data.data.results.updated_products;
                deletedCount += data.data.results.deleted_products || 0;
                totalProducts = data.data.total_products;

                // Calculate and update the progress bar
                const progressPercentage = totalProducts && totalProducts > 0 
                    ? Math.min((processedCount / totalProducts) * 100, 100) 
                    : 0;
                progressBar.style.width = `${progressPercentage.toFixed(2)}%`;

                // Display the current sync status
                resultDiv.innerHTML = `
                    <div class="notice notice-success">
                        <p>${data.data.message}</p>
                        <p>Total Products in JSON: ${totalProducts}</p>
                        <p>New Products: ${newCount}</p>
                        <p>Updated Products: ${updatedCount}</p>
                        <p>Deleted Products: ${deletedCount}</p>
                        <p>Remaining: ${data.data.remaining}</p>
                    </div>
                `;

                // Break the loop if there are no more products to process
                if (data.data.remaining === 0) {
                    progressBar.style.width = '100%';
                    break;
                }

                // Wait for the specified delay between batches, allowing cancellation
                await new Promise((resolve) => {
                    const timeout = setTimeout(resolve, delayBetweenBatches);
                    cancelButton.addEventListener('click', () => {
                        clearTimeout(timeout);
                        controller.abort();
                    }, { once: true });
                });
            }

            // Display the final sync result if sync was not cancelled
            if (syncInProgress) {
                resultDiv.innerHTML = `
                    <div class="notice notice-success">
                        <p>Manual sync completed!</p>
                        <p>Total Products in JSON: ${totalProducts}</p>
                        <p>Total New Products: ${newCount}</p>
                        <p>Total Updated Products: ${updatedCount}</p>
                        <p>Total Deleted Products: ${deletedCount}</p>
                    </div>
                `;

                // Mark the initial manual sync as done
                await fetch(syncwoo_vars.syncwoo_ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams({
                        action: 'syncwoo_mark_initial_manual_sync_done',
                        nonce: syncwoo_vars.syncwoo_nonce
                    })
                });

                // Reschedule cron after manual sync
                await fetch(syncwoo_vars.syncwoo_ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams({
                        action: 'syncwoo_reschedule_cron',
                        nonce: syncwoo_vars.syncwoo_nonce
                    })
                });
            }
        } catch (error) {
            // Handle cancellation
            if (error.name === 'AbortError') {
                resultDiv.innerHTML = '<div class="notice notice-warning"><p>Manual sync cancelled</p></div>';
                progressBar.style.width = '0%';
            } else {
                // Handle other errors
                console.error('Manual sync error:', error);
                resultDiv.innerHTML = `
                    <div class="notice notice-error">
                        <p>Error: ${error.message}</p>
                        <p>Check console or server logs</p>
                    </div>
                `;
            }
        } finally {
            // Reset the sync state and UI
            syncInProgress = false;
            button.disabled = false;
            cancelButton.disabled = true;
            button.textContent = 'Start Sync';
            controller = null;
        }
    });

    // Add event listener for the "Cancel" button
    cancelButton.addEventListener('click', function () {
        if (controller && syncInProgress) {
            controller.abort();
            syncInProgress = false;
            resultDiv.innerHTML = '<div class="notice notice-warning"><p>Manual sync cancelled</p></div>';
            progressBar.style.width = '0%';
            syncButton.disabled = false;
            syncButton.textContent = 'Start Sync';
            this.disabled = true;
        }
    });
});