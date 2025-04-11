// countdown timer
 
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


document.addEventListener('DOMContentLoaded', function () {
    const syncButton = document.getElementById('syncwoo-button');
    const cancelButton = document.getElementById('syncwoo-cancel');
    const resultDiv = document.getElementById('syncwoo-result');
    const progressBar = document.querySelector('.progress-bar');
    let syncInProgress = false;
    let controller = null;
    let processedCount = 0;
    const batchSize = 10;
    const delayBetweenBatches = 60 * 1000;

    if (!syncButton || !cancelButton || !resultDiv || !progressBar) {
        console.error('One or more required elements are missing');
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
        button.innerHTML = '<span class="spinner is-active"></span> Syncing...';

        resultDiv.innerHTML = '<div class="notice notice-info"><p>Starting sync...</p></div>';
        progressBar.style.width = '0%';
        processedCount = 0;

        try {
            let totalProducts = null;

            while (true) {
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
                if (totalProducts === null && data.data.remaining !== undefined) {
                    totalProducts = processedCount + data.data.remaining;
                }

                const progressPercentage = totalProducts ? Math.min((processedCount / totalProducts) * 100, 100) : 0;
                progressBar.style.width = `${progressPercentage}%`;

                resultDiv.innerHTML = `
                    <div class="notice notice-success">
                        <p>${data.data.message}</p>
                        <p>Processed: ${processedCount}</p>
                        <p>Remaining: ${data.data.remaining}</p>
                    </div>
                `;

                if (data.data.remaining === 0) {
                    progressBar.style.width = '100%';
                    break;
                }

                await new Promise(resolve => setTimeout(resolve, delayBetweenBatches));
            }

            resultDiv.innerHTML = `
                <div class="notice notice-success">
                    <p>Sync completed!</p>
                    <p>Total processed: ${processedCount}</p>
                </div>
            `;

        } catch (error) {
            if (error.name === 'AbortError') {
                resultDiv.innerHTML = '<div class="notice notice-warning"><p>Sync cancelled</p></div>';
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
            button.textContent = 'Sync Now';
            if (controller && controller.signal.aborted) {
                progressBar.style.width = '0%';
            }
        }
    });

    cancelButton.addEventListener('click', function () {
        if (controller) {
            controller.abort();
        }
    });
});