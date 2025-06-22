/**
 * Timeline Data Extractor - Frontend
 * Calls PHP backend to extract data from Taiga API (avoids CORS issues)
 */

// Global variables for easy access
let extractedData = null;
let isExtracting = false;
let eventSource = null;

/**
 * Call PHP backend to extract timeline data with streaming progress
 */
async function extractTimelineDataWithProgress(baseUrl, authToken, userId, onProgress) {
    if (isExtracting) {
        console.log('Extraction already in progress...');
        return;
    }

    isExtracting = true;

    return new Promise((resolve, reject) => {
        try {
            console.log('Starting timeline data extraction with real-time progress...');

            // Create a unique session ID to identify this extraction
            const sessionId = Date.now().toString();
            
            // Start the streaming extraction
            // Get the current directory path to construct the correct API URL
            const currentPath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
            const apiUrl = window.location.origin + currentPath + 'timeline-api.php';
            const url = new URL(apiUrl);
            url.searchParams.set('stream', 'true');
            url.searchParams.set('baseUrl', baseUrl);
            url.searchParams.set('authToken', authToken);
            url.searchParams.set('userId', userId);
            url.searchParams.set('session', sessionId);
            
            eventSource = new EventSource(url.toString());

            // Handle different SSE events
            eventSource.addEventListener('start', function(e) {
                const data = JSON.parse(e.data);
                console.log('Start:', data.message);
                if (onProgress) onProgress('start', data);
            });

            eventSource.addEventListener('progress', function(e) {
                const data = JSON.parse(e.data);
                console.log('Progress:', data.message);
                if (onProgress) onProgress('progress', data);
            });

            eventSource.addEventListener('timeline_page', function(e) {
                const data = JSON.parse(e.data);
                console.log('Timeline page:', data.message);
                if (onProgress) onProgress('timeline_page', data);
            });

            eventSource.addEventListener('task_comments', function(e) {
                const data = JSON.parse(e.data);
                console.log('Task comments:', data.message);
                if (onProgress) onProgress('task_comments', data);
            });

            eventSource.addEventListener('time_found', function(e) {
                const data = JSON.parse(e.data);
                console.log('Time found:', data.message);
                if (onProgress) onProgress('time_found', data);
            });

            eventSource.addEventListener('raw_api_response', function(e) {
                const data = JSON.parse(e.data);
                console.log('=== RAW API RESPONSE ===');
                console.log('URL:', data.api_url);
                console.log('Item Type:', data.item_type);
                console.log('Item ID:', data.item_id);
                console.log('Response Count:', data.response_count);
                console.log('Full Response:', data.raw_response);
                console.log('=== END API RESPONSE ===');
                if (onProgress) onProgress('raw_api_response', data);
            });

            eventSource.addEventListener('complete', function(e) {
                const data = JSON.parse(e.data);
                console.log('Complete:', data.message);
                if (onProgress) onProgress('complete', data);
            });

            eventSource.addEventListener('result', function(e) {
                const result = JSON.parse(e.data);
                console.log('Final result received');
                extractedData = result;
                if (onProgress) onProgress('result', result);
                resolve(result);
            });

            eventSource.addEventListener('error', function(e) {
                const error = JSON.parse(e.data);
                console.error('Stream error:', error);
                if (onProgress) onProgress('error', error);
                reject(new Error(error.error || 'Stream error occurred'));
            });

            eventSource.addEventListener('end', function(e) {
                console.log('Stream ended');
                eventSource.close();
                eventSource = null;
                isExtracting = false;
            });

            eventSource.onerror = function(e) {
                console.error('EventSource failed:', e);
                eventSource.close();
                eventSource = null;
                isExtracting = false;
                reject(new Error('Connection to server lost'));
            };

        } catch (error) {
            console.error('Extraction failed:', error);
            isExtracting = false;
            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
            reject(error);
        }
    });
}

/**
 * Call PHP backend to extract timeline data (original method)
 */
async function extractTimelineData(baseUrl, authToken, userId) {
    if (isExtracting) {
        console.log('Extraction already in progress...');
        return;
    }

    isExtracting = true;
    
    try {
        console.log('Starting timeline data extraction via PHP backend...');
        
        // Get the current directory path to construct the correct API URL
        const currentPath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
        const apiUrl = window.location.origin + currentPath + 'timeline-api.php';
        
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                baseUrl: baseUrl,
                authToken: authToken,
                userId: userId
            })
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Unknown error occurred');
        }

        console.log(`Extraction completed! Found ${result.summary.totalItems} items with total time: ${result.summary.totalTimeFormatted}`);
        
        extractedData = result;
        return result;

    } catch (error) {
        console.error('Extraction failed:', error);
        throw error;
    } finally {
        isExtracting = false;
    }
}

/**
 * Stop ongoing extraction
 */
function stopExtraction() {
    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }
    isExtracting = false;
    console.log('Extraction stopped');
}

/**
 * Get current extracted data
 */
function getExtractedData() {
    return extractedData;
}

/**
 * Check if extraction is in progress
 */
function isExtractionInProgress() {
    return isExtracting;
}

/**
 * Clear extracted data
 */
function clearExtractedData() {
    extractedData = null;
}

// Legacy compatibility functions (if needed)
function initializeExtractor(baseUrl, authToken, userId) {
    // Not needed anymore since we're using PHP backend
    // But keeping for compatibility
    return {
        baseUrl: baseUrl,
        authToken: authToken,
        userId: userId
    };
}

async function startExtraction() {
    // This function is deprecated, but keeping for backward compatibility
    throw new Error('Use extractTimelineData() or extractTimelineDataWithProgress() directly instead of startExtraction()');
}

// Example usage:
/*
// Extract data with real-time progress
extractTimelineDataWithProgress('https://taiga.noorix.com', 'your-bearer-token', 20, (event, data) => {
    console.log(`Event: ${event}`, data);
})
.then(data => {
    console.log('Extraction completed:', data);
})
.catch(error => {
    console.error('Extraction failed:', error);
});

// Or use the original method without progress
extractTimelineData('https://taiga.noorix.com', 'your-bearer-token', 20)
.then(data => {
    console.log('Extraction completed:', data);
})
.catch(error => {
    console.error('Extraction failed:', error);
});
*/ 