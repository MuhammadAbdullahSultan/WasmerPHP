<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wasmer PHP - Timeline Tools</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
        }

        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            max-width: 600px;
            width: 90%;
            text-align: center;
        }

        .header {
            margin-bottom: 30px;
        }

        .header h1 {
            color: #2563eb;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
            font-size: 1.1rem;
        }

        .tools-grid {
            display: grid;
            gap: 20px;
            margin-top: 30px;
        }

        .tool-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 25px;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
        }

        .tool-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-color: #2563eb;
        }

        .tool-card h3 {
            color: #1f2937;
            margin-bottom: 10px;
            font-size: 1.3rem;
        }

        .tool-card p {
            color: #666;
            line-height: 1.6;
        }

        .tool-card .icon {
            font-size: 2rem;
            margin-bottom: 15px;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            color: #666;
            font-size: 0.9rem;
        }

        .stats {
            background: #f0f9ff;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            font-size: 0.9rem;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Wasmer PHP</h1>
            <p>Timeline Data Extraction Tools</p>
        </div>

        <div class="stats">
            <strong>PHP Version:</strong> <?php echo phpversion(); ?> | 
            <strong>Server Time:</strong> <?php echo date('Y-m-d H:i:s T'); ?>
        </div>

        <div class="tools-grid">
            <a href="timeline-viewer.html" class="tool-card">
                <div class="icon">🕒</div>
                <h3>Timeline Data Extractor</h3>
                <p>Extract and analyze time tracking data from Taiga timeline API with real-time progress tracking</p>
            </a>

            <a href="info.php" class="tool-card">
                <div class="icon">ℹ️</div>
                <h3>PHP Info</h3>
                <p>View detailed PHP configuration and server information</p>
            </a>

            <a href="timeline-api.php" class="tool-card">
                <div class="icon">🔌</div>
                <h3>Timeline API</h3>
                <p>Direct access to the timeline extraction API endpoint for developers</p>
            </a>
        </div>

        <div class="footer">
            <p>Powered by Wasmer Edge | Built with PHP</p>
        </div>
    </div>
</body>
</html>
