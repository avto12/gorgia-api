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

// Batch sync (for manual sync with progress bar)
document.addEventListener('DOMContentLoaded', function () {
    const syncButton = document.getElementById('syncwoo-button');
    const cancelButton = document.getElementById('syncwoo-cancel');
    const resultDiv = document.getElementById('syncwoo-result');
    const progressBar = document.querySelector('.progress-bar');
    let syncInProgress = false;
    let controller = null;
    let processedCount = 0;
    let newCount = 0;
    let updatedCount = 0;
    let deletedCount = 0;
    const batchSize = 10;
    const delayBetweenBatches = 60 * 1000;

    if (!syncButton || !cancelButton || !resultDiv || !progressBar) {
        console.error('One or more required elements are missing for batch sync');
        return;
    }

    syncButton.addEventListener('click', async function () {
        if (syncInProgress) {
            alert("Sync is already in progress. Please wait.");
            return;
        }

        syncInProgress = true;
        controller = new AbortController();
        const button = this;
        button.disabled = true;
        cancelButton.disabled = false;
        button.innerHTML = '<span class="spinner is-active"></span> Syncing...';

        resultDiv.innerHTML = '<div class="notice notice-info"><p>Starting sync...</p></div>';
        progressBar.style.width = '0%';
        processedCount = 0;
        newCount = 0;
        updatedCount = 0;
        deletedCount = 0;

        try {
            let totalProducts = null;

            while (syncInProgress) {
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

                if (!response.ok) {
                    const text = await response.text();
                    throw new Error(`Server error (${response.status}): ${text}`);
                }

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.data?.message || 'Unknown error');
                }

                processedCount = data.data.processed_count;
                newCount += data.data.results.new_products;
                updatedCount += data.data.results.updated_products;
                deletedCount += data.data.results.deleted_products || 0;
                totalProducts = data.data.total_products;

                const progressPercentage = totalProducts ? Math.min((processedCount / totalProducts) * 100, 100) : 0;
                progressBar.style.width = `${progressPercentage}%`;

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

                if (data.data.remaining === 0) {
                    progressBar.style.width = '100%';
                    break;
                }

                await new Promise((resolve) => {
                    const timeout = setTimeout(resolve, delayBetweenBatches);
                    cancelButton.addEventListener('click', () => {
                        clearTimeout(timeout);
                        controller.abort();
                    }, { once: true });
                });
            }

            if (syncInProgress) {
                resultDiv.innerHTML = `
                    <div class="notice notice-success">
                        <p>Sync completed!</p>
                        <p>Total Products in JSON: ${totalProducts}</p>
                        <p>Total New Products: ${newCount}</p>
                        <p>Total Updated Products: ${updatedCount}</p>
                        <p>Total Deleted Products: ${deletedCount}</p>
                    </div>
                `;
            }

        } catch (error) {
            if (error.name === 'AbortError') {
                resultDiv.innerHTML = '<div class="notice notice-warning"><p>Sync cancelled</p></div>';
                progressBar.style.width = '0%';
            } else {
                console.error('Sync error:', error);
                resultDiv.innerHTML = `
                    <div class="notice notice-error">
                        <p>Error: ${error.message}</p>
                        <p>Check console or server logs</p>
                    </div>
                `;
            }
        } finally {
            syncInProgress = false;
            button.disabled = false;
            cancelButton.disabled = true;
            button.textContent = 'Sync Now';
            controller = null;
        }
    });

    cancelButton.addEventListener('click', function () {
        if (controller && syncInProgress) {
            controller.abort();
            syncInProgress = false;
            resultDiv.innerHTML = '<div class="notice notice-warning"><p>Sync cancelled</p></div>';
            progressBar.style.width = '0%';
            syncButton.disabled = false;
            syncButton.textContent = 'Sync Now';
            this.disabled = true;
        }
    });
});

// Manual sync (with batch processing)
document.addEventListener('DOMContentLoaded', function () {
    const manualSyncButton = document.getElementById('syncwoo-manual-button');
    const manualCancelButton = document.getElementById('syncwoo-manual-cancel');
    const manualResultDiv = document.getElementById('syncwoo-manual-result');
    const manualProgressBar = document.querySelector('.progress-bar-manual');
    let manualSyncInProgress = false;
    let manualController = null;
    let manualProcessedCount = 0;
    let manualNewCount = 0;
    let manualUpdatedCount = 0;
    let manualDeletedCount = 0;
    const batchSize = 10; // Process 10 products at a time
    const delayBetweenBatches = 60 * 1000; // Delay between batches

    if (!manualSyncButton || !manualCancelButton || !manualResultDiv || !manualProgressBar) {
        console.error('One or more required elements are missing for manual sync');
        return;
    }

    manualSyncButton.addEventListener('click', async function () {
        if (manualSyncInProgress) {
            alert("Manual sync is already in progress. Please wait.");
            return;
        }

        manualSyncInProgress = true;
        manualController = new AbortController();
        const button = this;
        button.disabled = true;
        manualCancelButton.disabled = false;
        button.innerHTML = '<span class="spinner is-active"></span> Syncing...';

        manualResultDiv.innerHTML = '<div class="notice notice-info"><p>Starting manual sync...</p></div>';
        manualProgressBar.style.width = '0%';
        manualProcessedCount = 0;
        manualNewCount = 0;
        manualUpdatedCount = 0;
        manualDeletedCount = 0;

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

            while (manualSyncInProgress) {
                const response = await fetch(syncwoo_vars.syncwoo_ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    signal: manualController.signal,
                    body: new URLSearchParams({
                        action: 'syncwoo_perform_sync',
                        nonce: syncwoo_vars.syncwoo_nonce,
                        processed_count: manualProcessedCount,
                        batch_size: batchSize
                    })
                });

                if (!response.ok) {
                    const text = await response.text();
                    throw new Error(`Server error (${response.status}): ${text}`);
                }

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.data?.message || 'Unknown error');
                }

                manualProcessedCount = data.data.processed_count;
                manualNewCount += data.data.results.new_products;
                manualUpdatedCount += data.data.results.updated_products;
                manualDeletedCount += data.data.results.deleted_products || 0;
                totalProducts = data.data.total_products;

                const progressPercentage = totalProducts ? Math.min((manualProcessedCount / totalProducts) * 100, 100) : 0;
                manualProgressBar.style.width = `${progressPercentage}%`;

                manualResultDiv.innerHTML = `
                    <div class="notice notice-success">
                        <p>${data.data.message}</p>
                        <p>Total Products in JSON: ${totalProducts}</p>
                        <p>New Products: ${manualNewCount}</p>
                        <p>Updated Products: ${manualUpdatedCount}</p>
                        <p>Deleted Products: ${manualDeletedCount}</p>
                        <p>Remaining: ${data.data.remaining}</p>
                    </div>
                `;

                if (data.data.remaining === 0) {
                    manualProgressBar.style.width = '100%';
                    break;
                }

                await new Promise((resolve) => {
                    const timeout = setTimeout(resolve, delayBetweenBatches);
                    manualCancelButton.addEventListener('click', () => {
                        clearTimeout(timeout);
                        manualController.abort();
                    }, { once: true });
                });
            }

            if (manualSyncInProgress) {
                manualResultDiv.innerHTML = `
                    <div class="notice notice-success">
                        <p>Manual sync completed!</p>
                        <p>Total Products in JSON: ${totalProducts}</p>
                        <p>Total New Products: ${manualNewCount}</p>
                        <p>Total Updated Products: ${manualUpdatedCount}</p>
                        <p>Total Deleted Products: ${manualDeletedCount}</p>
                    </div>
                `;

                // Mark initial manual sync as done
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
            if (error.name === 'AbortError') {
                manualResultDiv.innerHTML = '<div class="notice notice-warning"><p>Manual sync cancelled</p></div>';
                manualProgressBar.style.width = '0%';
            } else {
                console.error('Manual sync error:', error);
                manualResultDiv.innerHTML = `
                    <div class="notice notice-error">
                        <p>Error: ${error.message}</p>
                        <p>Check console or server logs</p>
                    </div>
                `;
            }
        } finally {
            manualSyncInProgress = false;
            button.disabled = false;
            manualCancelButton.disabled = true;
            button.textContent = 'Start Sync';
            manualController = null;
        }
    });

    manualCancelButton.addEventListener('click', function () {
        if (manualController && manualSyncInProgress) {
            manualController.abort();
            manualSyncInProgress = false;
            manualResultDiv.innerHTML = '<div class="notice notice-warning"><p>Manual sync cancelled</p></div>';
            manualProgressBar.style.width = '0%';
            manualSyncButton.disabled = false;
            manualSyncButton.textContent = 'Start Sync';
            this.disabled = true;
        }
    });

    // Countdown timer for product update frequency (for cron-based sync)
    const countdownElement = document.getElementById('time-remaining-product-update');
    const countdownContainer = document.getElementById('countdown-timer-product-update');
    if (countdownElement && countdownContainer) {
        let timeRemaining = parseInt(countdownContainer.getAttribute('data-remaining'), 10);

        if (isNaN(timeRemaining)) {
            console.error("Invalid time remaining value for product update countdown");
            return;
        }

        if (timeRemaining > 0) {
            const updateCountdown = () => {
                if (timeRemaining <= 0) {
                    countdownElement.textContent = 'Syncing now...';
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                    return;
                }

                const hours = Math.floor(timeRemaining / 3600);
                const minutes = Math.floor((timeRemaining % 3600) / 60);
                const seconds = timeRemaining % 60;

                countdownElement.textContent = `${hours}h ${minutes}m ${seconds}s`;
                timeRemaining--;

                setTimeout(updateCountdown, 1000);
            };

            updateCountdown();
        } else if (countdownElement.textContent === '') {
            countdownElement.textContent = 'Disabled';
        }
    }
});