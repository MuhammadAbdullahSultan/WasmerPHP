<?php
// Clean any previous output buffer to prevent HTML errors
if (ob_get_level()) {
    ob_clean();
}

// Suppress error output to prevent HTML errors from breaking JSON response
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Increase limits for potentially long-running script
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '256M');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Handle both GET (streaming) and POST (regular) requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Handle GET request for streaming or testing
    if (isset($_GET['stream']) && $_GET['stream'] === 'true') {
        // Streaming mode with GET parameters
        $input = [
            'baseUrl' => $_GET['baseUrl'] ?? '',
            'authToken' => $_GET['authToken'] ?? '',
            'userId' => $_GET['userId'] ?? '',
            'stream' => true
        ];
    } else {
        // Test request
        echo json_encode([
            'status' => 'OK',
            'message' => 'Timeline API is working',
            'timestamp' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION
        ]);
        exit();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle POST request for regular extraction
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        exit();
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Validate required fields
$required_fields = ['baseUrl', 'authToken', 'userId'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit();
    }
}

$baseUrl = rtrim($input['baseUrl'], '/');
$authToken = $input['authToken'];
$userId = $input['userId'];
$isStreaming = isset($input['stream']) && $input['stream'] === true;

// Log start of extraction
error_log("Starting timeline extraction for user $userId" . ($isStreaming ? " (streaming mode)" : ""));

// If streaming mode, set up Server-Sent Events
if ($isStreaming) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Cache-Control');
    
    // Function to send SSE message
    function sendSSE($event, $data) {
        echo "event: $event\n";
        echo "data: " . json_encode($data) . "\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
    
    sendSSE('start', ['message' => 'Starting timeline extraction...']);
}

/**
 * Get the first day of current month
 */
function getFirstDayOfCurrentMonth() {
    try {
        return new DateTime(date('Y-m-01'));
    } catch (Exception $e) {
        error_log("Error creating DateTime: " . $e->getMessage());
        return new DateTime();
    }
}

/**
 * Check if a date is before the first day of current month
 */
function isDateBeforeCurrentMonth($dateString) {
    try {
        $itemDate = new DateTime($dateString);
        $firstDayOfMonth = getFirstDayOfCurrentMonth();
        return $itemDate < $firstDayOfMonth;
    } catch (Exception $e) {
        error_log("Error parsing date: $dateString - " . $e->getMessage());
        return false;
    }
}

/**
 * Extract time value from comment HTML
 */
function extractTimeFromCommentHtml($commentHtml) {
    if (empty($commentHtml) || !is_string($commentHtml)) {
        return 0;
    }
    
    $totalTime = 0;
    
    try {
        // Regex to match Time: or Time:: followed by a number (case insensitive)
        if (preg_match_all('/Time:+\s*(\d+(?:\.\d+)?)/i', $commentHtml, $matches)) {
            foreach ($matches[1] as $timeValue) {
                $totalTime += floatval($timeValue);
            }
        }
    } catch (Exception $e) {
        error_log("Error extracting time from comment: " . $e->getMessage());
    }
    
    return $totalTime;
}

/**
 * Fetch task/issue history comments from the history API
 */
function fetchTaskComments($baseUrl, $authToken, $userId, $itemId, $itemType = 'issue', $isStreaming = false) {
    $url = "$baseUrl/api/v1/history/$itemType/$itemId?type=comment";
    
    $headers = [
        'accept: application/json, text/plain, */*',
        'accept-language: en',
        'authorization: Bearer ' . $authToken,
        'priority: u=1, i',
        'referer: https://taiga.noorix.com/profile/abdullahsultan14',
        'sec-ch-ua: "Not)A;Brand";v="8", "Chromium";v="138", "Google Chrome";v="138"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'sec-fetch-dest: empty',
        'sec-fetch-mode: cors',
        'sec-fetch-site: same-origin',
        'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
        'x-lazy-pagination: true',
        'x-session-id: 96a3325a7453d093b8adfba0586b3f084053e9a0'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("CURL error for $itemType $itemId comments: $error");
        return [];
    }
    
    if ($httpCode !== 200) {
        error_log("HTTP error for $itemType $itemId comments: $httpCode");
        return [];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error for $itemType $itemId comments: " . json_last_error_msg());
        return [];
    }
    
    // Log the comment API response for debugging
    error_log("=== Comment API Response for $itemType $itemId ===");
    error_log("URL: $url");
    error_log("Response: " . json_encode($data, JSON_PRETTY_PRINT));
    error_log("=== End Comment API Response ===");
    
    // Send raw API response to console via streaming
    if ($isStreaming) {
        sendSSE('raw_api_response', [
            'message' => "Raw comment API response for $itemType #$itemId",
            'item_id' => $itemId,
            'item_type' => $itemType,
            'api_url' => $url,
            'response_count' => is_array($data) ? count($data) : 0,
            'raw_response' => $data
        ]);
    }
    
    // Filter comments by the specific user ID and extract time values
    $totalTimeValue = 0;
    $userComments = [];
    
    if (is_array($data)) {
        error_log("Processing " . count($data) . " history items for $itemType $itemId");
        
        foreach ($data as $index => $historyItem) {
            error_log("History item $index: User PK = " . ($historyItem['user']['pk'] ?? 'none') . ", Target User ID = $userId");
            
            // Check if this comment is from the specified user
            if (isset($historyItem['user']) && $historyItem['user']['pk'] == $userId) {
                error_log("Found comment from target user $userId in $itemType $itemId");
                
                if (isset($historyItem['comment_html']) && !empty($historyItem['comment_html'])) {
                    error_log("Comment HTML: " . $historyItem['comment_html']);
                    
                    $timeValue = extractTimeFromCommentHtml($historyItem['comment_html']);
                    error_log("Extracted time value: $timeValue");
                    
                    if ($timeValue > 0) {
                        $totalTimeValue += $timeValue;
                        $userComments[] = [
                            'comment_html' => $historyItem['comment_html'],
                            'comment' => $historyItem['comment'] ?? '',
                            'created_at' => $historyItem['created_at'],
                            'time_value' => $timeValue
                        ];
                        error_log("Added comment with time value $timeValue to results");
                    } else {
                        error_log("No time value found in comment");
                    }
                } else {
                    error_log("No comment_html found or empty");
                }
            }
        }
        
        error_log("Total time value for $itemType $itemId: $totalTimeValue");
    } else {
        error_log("Comment API response is not an array for $itemType $itemId");
    }
    
    return [
        'total_time' => $totalTimeValue,
        'comments' => $userComments
    ];
}

/**
 * Make API call for a specific page
 */
function fetchTimelinePage($baseUrl, $authToken, $userId, $page) {
    $url = "$baseUrl/api/v1/timeline/user/$userId?only_relevant=true&page=$page";
    
    $headers = [
        'accept: application/json, text/plain, */*',
        'accept-language: en',
        'authorization: Bearer ' . $authToken,
        'priority: u=1, i',
        'referer: https://taiga.noorix.com/profile/abdullahsultan14',
        'sec-ch-ua: "Not)A;Brand";v="8", "Chromium";v="138", "Google Chrome";v="138"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'sec-fetch-dest: empty',
        'sec-fetch-mode: cors',
        'sec-fetch-site: same-origin',
        'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
        'x-lazy-pagination: true',
        'x-session-id: 96a3325a7453d093b8adfba0586b3f084053e9a0'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("CURL error: $error");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP error: $httpCode");
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode error: " . json_last_error_msg());
    }
    
    return $data;
}

try {
    error_log("Initializing extraction variables");
    
    $currentPage = 1;
    $pagesProcessed = 0;
    $shouldContinue = true;
    $allTimelineItems = [];
    $uniqueTasks = [];
    
    $firstDayOfMonth = getFirstDayOfCurrentMonth();
    
    error_log("Starting timeline page extraction");
    
    if ($isStreaming) {
        sendSSE('progress', ['message' => 'Step 1: Collecting timeline items...', 'step' => 1, 'total_steps' => 3]);
    }
    
    // Step 1: Collect all timeline items and extract unique tasks
    while ($shouldContinue) {
        error_log("Fetching timeline page $currentPage...");
        
        if ($isStreaming) {
            sendSSE('timeline_page', ['message' => "Fetching timeline page $currentPage...", 'page' => $currentPage]);
        }
        
        try {
            $pageData = fetchTimelinePage($baseUrl, $authToken, $userId, $currentPage);
            $pagesProcessed++;
            
            if (empty($pageData)) {
                error_log("Empty page data, stopping extraction");
                if ($isStreaming) {
                    sendSSE('timeline_page', ['message' => 'No more timeline data found', 'page' => $currentPage]);
                }
                break;
            }
            
            $foundOldData = false;
            
            foreach ($pageData as $item) {
                // Check if this item is before current month
                if (isDateBeforeCurrentMonth($item['created'])) {
                    error_log("Found item from {$item['created']} which is before current month. Stopping extraction.");
                    $foundOldData = true;
                    break;
                }
                
                            // Check if item has the required structure and determine type
            $itemData = null;
            $itemId = null;
            $itemType = null;
            
            // Determine item type based on event_type
            if (isset($item['event_type'])) {
                if (strpos($item['event_type'], 'issue') !== false && isset($item['data']['issue'])) {
                    $itemData = $item['data']['issue'];
                    $itemId = $itemData['id'];
                    $itemType = 'issue';
                } elseif (strpos($item['event_type'], 'task') !== false && isset($item['data']['task'])) {
                    $itemData = $item['data']['task'];
                    $itemId = $itemData['id'];
                    $itemType = 'task';
                }
            }
            
            // Fallback: check for task data if no specific type determined
            if (!$itemData && isset($item['data']['task'])) {
                $itemData = $item['data']['task'];
                $itemId = $itemData['id'];
                $itemType = 'task';
            }
            
            // Fallback: check for issue data if no task data
            if (!$itemData && isset($item['data']['issue'])) {
                $itemData = $item['data']['issue'];
                $itemId = $itemData['id'];
                $itemType = 'issue';
            }
            
            if ($itemData && $itemId && $itemType) {
                // Store all timeline items
                $allTimelineItems[] = $item;
                
                // Collect unique items (use itemId as key to automatically deduplicate)
                if (!isset($uniqueTasks[$itemId])) {
                    $uniqueTasks[$itemId] = [
                        'item' => $itemData,
                        'item_type' => $itemType,
                        'event_type' => $item['event_type'] ?? 'unknown',
                        'project' => isset($item['data']['project']) ? $item['data']['project'] : null,
                        'created' => $item['created']
                    ];
                }
            }
            }
            
            if ($foundOldData) {
                $shouldContinue = false;
            } else {
                $currentPage++;
            }
            
            // Add a small delay to be respectful to the API
            usleep(100000); // 100ms delay
            
        } catch (Exception $e) {
            error_log("Error fetching timeline page $currentPage: " . $e->getMessage());
            break; // Stop on error but continue with what we have
        }
    }
    
    error_log("Found " . count($uniqueTasks) . " unique tasks from " . count($allTimelineItems) . " timeline items");
    
    if ($isStreaming) {
        sendSSE('progress', [
            'message' => 'Step 2: Fetching comments for ' . count($uniqueTasks) . ' unique tasks...', 
            'step' => 2, 
            'total_steps' => 3,
            'unique_tasks' => count($uniqueTasks),
            'timeline_items' => count($allTimelineItems)
        ]);
    }
    
    // Step 2: Fetch comments for each unique task
    $taskCommentsData = [];
    $taskCount = 0;
    $totalTasks = count($uniqueTasks);
    
    error_log("Starting comment extraction for $totalTasks tasks");
    
    foreach ($uniqueTasks as $itemId => $itemInfo) {
        $taskCount++;
        $itemType = $itemInfo['item_type'];
        $itemRef = $itemInfo['item']['ref'] ?? $itemId;
        $itemSubject = $itemInfo['item']['subject'] ?? 'Unknown';
        
        error_log("Fetching comments for $itemType $itemId ($taskCount/$totalTasks)...");
        
        if ($isStreaming) {
            sendSSE('task_comments', [
                'message' => "Fetching comments for $itemType #{$itemRef} ($taskCount/$totalTasks)...",
                'item_id' => $itemId,
                'item_type' => $itemType,
                'item_ref' => $itemRef,
                'item_subject' => $itemSubject,
                'event_type' => $itemInfo['event_type'],
                'current' => $taskCount,
                'total' => $totalTasks,
                'progress_percent' => round(($taskCount / $totalTasks) * 100)
            ]);
        }
        
        try {
            $commentData = fetchTaskComments($baseUrl, $authToken, $userId, $itemId, $itemType, $isStreaming);
            
            // Only store if there are time values
            if ($commentData['total_time'] > 0) {
                $taskCommentsData[$itemId] = $commentData;
                
                if ($isStreaming) {
                    sendSSE('time_found', [
                        'message' => "Found {$commentData['total_time']} hours in $itemType #{$itemRef}",
                        'item_id' => $itemId,
                        'item_type' => $itemType,
                        'item_ref' => $itemRef,
                        'time_value' => $commentData['total_time']
                    ]);
                }
            }
            
            // Add a small delay between comment API calls
            usleep(50000); // 50ms delay
            
        } catch (Exception $e) {
            error_log("Error fetching comments for $itemType $itemId: " . $e->getMessage());
            if ($isStreaming) {
                sendSSE('error', [
                    'message' => "Error fetching comments for $itemType $itemId: " . $e->getMessage(),
                    'item_id' => $itemId,
                    'item_type' => $itemType
                ]);
            }
        }
    }
    
    error_log("Found time data for " . count($taskCommentsData) . " tasks");
    
    if ($isStreaming) {
        sendSSE('progress', [
            'message' => 'Step 3: Processing final results...', 
            'step' => 3, 
            'total_steps' => 3,
            'tasks_with_time' => count($taskCommentsData)
        ]);
    }
    
    // Step 3: Process and build final results
    $finalData = [];
    
    foreach ($taskCommentsData as $itemId => $commentData) {
        try {
            $itemInfo = $uniqueTasks[$itemId];
            $itemType = $itemInfo['item_type'];
            
            // Combine all comments for display
            $combinedComment = '';
            foreach ($commentData['comments'] as $comment) {
                if (!empty($combinedComment)) {
                    $combinedComment .= ' | ';
                }
                $combinedComment .= strip_tags($comment['comment_html']);
            }
            
            $finalData[] = [
                'itemId' => $itemId,
                'itemType' => $itemType,
                'itemRef' => $itemInfo['item']['ref'] ?? $itemId,
                'itemSubject' => $itemInfo['item']['subject'] ?? 'Unknown',
                'eventType' => $itemInfo['event_type'],
                'comment' => $combinedComment,
                'timeValue' => $commentData['total_time'],
                'created' => $itemInfo['created'],
                'projectName' => $itemInfo['project'] ? $itemInfo['project']['name'] : 'Unknown',
                'commentDetails' => $commentData['comments'],
                // Keep legacy field names for backward compatibility
                'taskId' => $itemId,
                'taskRef' => $itemInfo['item']['ref'] ?? $itemId,
                'taskSubject' => $itemInfo['item']['subject'] ?? 'Unknown'
            ];
        } catch (Exception $e) {
            error_log("Error processing $itemType $itemId: " . $e->getMessage());
            // Continue with other items
        }
    }
    
    // Calculate total time
    $totalTime = array_sum(array_column($finalData, 'timeValue'));
    
    error_log("Extraction completed. Total items: " . count($finalData) . ", Total time: $totalTime");
    
    if ($isStreaming) {
        sendSSE('complete', [
            'message' => 'Extraction completed successfully!',
            'total_items' => count($finalData),
            'total_time' => $totalTime,
            'total_time_formatted' => number_format($totalTime, 2)
        ]);
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'items' => $finalData,
        'summary' => [
            'totalItems' => count($finalData),
            'totalTime' => $totalTime,
            'totalTimeFormatted' => number_format($totalTime, 2),
            'pagesProcessed' => $pagesProcessed,
            'uniqueTasksFound' => count($uniqueTasks),
            'tasksWithTimeData' => count($taskCommentsData),
            'cutoffDate' => $firstDayOfMonth->format('Y-m-d'),
            'extractionDate' => date('Y-m-d H:i:s')
        ]
    ];
    
    // Handle response based on mode
    if ($isStreaming) {
        // Send final data through SSE
        sendSSE('result', $response);
        sendSSE('end', ['message' => 'Stream ended']);
    } else {
        // Ensure we can encode the response as JSON
        $jsonResponse = json_encode($response);
        if ($jsonResponse === false) {
            error_log("JSON encoding failed: " . json_last_error_msg());
            echo json_encode([
                'success' => false,
                'error' => 'Failed to encode response as JSON'
            ]);
        } else {
            echo $jsonResponse;
        }
    }
    
} catch (Exception $e) {
    error_log("Timeline extraction error: " . $e->getMessage());
    
    if ($isStreaming) {
        sendSSE('error', [
            'success' => false,
            'error' => $e->getMessage()
        ]);
        sendSSE('end', ['message' => 'Stream ended with error']);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
} catch (Error $e) {
    error_log("Timeline extraction fatal error: " . $e->getMessage());
    
    if ($isStreaming) {
        sendSSE('error', [
            'success' => false,
            'error' => 'A fatal error occurred during extraction'
        ]);
        sendSSE('end', ['message' => 'Stream ended with error']);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'A fatal error occurred during extraction'
        ]);
    }
} catch (Throwable $e) {
    error_log("Timeline extraction unexpected error: " . $e->getMessage());
    
    if ($isStreaming) {
        sendSSE('error', [
            'success' => false,
            'error' => 'An unexpected error occurred'
        ]);
        sendSSE('end', ['message' => 'Stream ended with error']);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'An unexpected error occurred'
        ]);
    }
}
  
  // Ensure clean output
  if (ob_get_level()) {
      ob_end_clean();
  }
  ?> 