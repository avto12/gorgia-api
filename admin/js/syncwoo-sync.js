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

// add products to sync
document.addEventListener('DOMContentLoaded', function() {
    const syncButton = document.getElementById('syncwoo-button');
    const cancelButton = document.getElementById('syncwoo-cancel');  // Add Cancel Button
    const resultDiv = document.getElementById('syncwoo-result');
    let syncInProgress = false;
    let controller = null;

    if (syncButton && resultDiv) {
        syncButton.addEventListener('click', async function() {
            // Check if sync is already in progress
            if (syncInProgress) {
                alert("Sync is already in progress. Please wait.");
                return;
            }

            syncInProgress = true;
            controller = new AbortController();  // Create a new AbortController for each sync
            const button = this;
            button.disabled = true;
            button.innerHTML = '<span class="spinner is-active"></span> Syncing...';

            resultDiv.innerHTML = '<div class="notice notice-info"><p>Processing request...</p></div>';

            try {
                const response = await fetch(syncwoo_vars.syncwoo_ajax_url, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                        "Accept": "application/json"
                    },
                    signal: controller.signal,  // Attach the controller signal to the fetch request
                    body: new URLSearchParams({
                        action: 'syncwoo_perform_sync',
                        nonce: syncwoo_vars.syncwoo_nonce
                    })
                });

                // Ensure the response is valid JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Non-JSON response:', text.substring(0, 300));
                    throw new Error('Server returned invalid response');
                }

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.data?.message || 'Sync failed');
                }

                // Success case
                resultDiv.innerHTML = `
                    <div class="notice notice-success">
                        <p>${data.data.message}</p>
                        <p>Processed ${data.data.results?.processed ?? 0} products</p>
                        ${data.data.results?.errors?.length ? `
                            <div class="error-list">
                                <h4>Errors:</h4>
                                <ul>
                                    ${data.data.results.errors.map(err => `<li>${err}</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                    </div>
                `;
            } catch (error) {
                if (error.name === 'AbortError') {
                    resultDiv.innerHTML = `
                        <div class="notice notice-warning">
                            <p>Sync was cancelled.</p>
                        </div>
                    `;
                } else {
                    console.error('Sync error:', error);
                    resultDiv.innerHTML = `
                        <div class="notice notice-error">
                            <p>${error.message}</p>
                            <p>Please check the console for details.</p>
                        </div>
                    `;
                }
            } finally {
                syncInProgress = false;
                button.disabled = false;
                button.textContent = "Sync Now";
            }
        });

        // Cancel Sync
        cancelButton.addEventListener('click', function() {
            if (controller) {
                controller.abort();  // Abort the fetch request, effectively stopping the sync
            }
        });
    }
});
