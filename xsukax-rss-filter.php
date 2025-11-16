<?php
/**
 * xsukax RSS Filter - Dynamic Feed Filtering
 * Base64 encoded configuration for reliability
 */

// Suppress warnings for clean XML output
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

// Check if serving a feed
if (isset($_GET['config'])) {
    serveDynamicFeed($_GET['config']);
    exit;
}

// Process form submission
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = generateFeedURL();
}

/**
 * Serve dynamic RSS feed
 */
function serveDynamicFeed($configData) {
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    try {
        // Decode configuration
        $config = json_decode(base64_decode($configData), true);
        
        if (!$config || !isset($config['sources']) || !isset($config['keywords'])) {
            header('HTTP/1.1 400 Bad Request');
            header('Content-Type: application/xml; charset=UTF-8');
            echo '<?xml version="1.0" encoding="UTF-8"?><error>Invalid feed configuration</error>';
            exit;
        }
        
        $sources = $config['sources'];
        $keywords = $config['keywords'];
        $title = isset($config['title']) ? $config['title'] : 'Filtered RSS Feed';
        $titlesOnly = isset($config['titles_only']) && $config['titles_only'];
        
        // Build feed URL for atom:link
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $currentFeedURL = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        
        // Fetch and filter feeds
        $filteredItems = [];
        
        foreach ($sources as $sourceURL) {
            try {
                $xml = fetchRSS($sourceURL);
                if ($xml === false) continue;
                
                $rss = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($rss === false) continue;
                
                $namespaces = $rss->getNamespaces(true);
                
                foreach ($rss->channel->item as $item) {
                    $itemTitle = (string)$item->title;
                    $itemDesc = (string)$item->description;
                    $itemLink = (string)$item->link;
                    
                    // Get full content
                    $contentEncoded = '';
                    if (isset($namespaces['content'])) {
                        $content = $item->children($namespaces['content']);
                        $contentEncoded = (string)$content->encoded;
                    }
                    
                    // Build search text
                    $searchText = $titlesOnly ? 
                        strtolower($itemTitle) : 
                        strtolower($itemTitle . ' ' . $itemDesc . ' ' . $itemLink . ' ' . $contentEncoded);
                    
                    // Check if matches any keyword
                    $matched = false;
                    foreach ($keywords as $keyword) {
                        if (stripos($searchText, trim($keyword)) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                    
                    if ($matched) {
                        $filteredItems[] = preserveCompleteItem($item, $namespaces);
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        // Serve RSS
        header('Content-Type: application/rss+xml; charset=UTF-8');
        echo generateRSS($title, $filteredItems, $currentFeedURL);
        
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><error>Feed generation failed</error>';
    }
}

/**
 * Preserve all item elements
 */
function preserveCompleteItem($item, $namespaces) {
    $data = [
        'title' => (string)$item->title,
        'link' => (string)$item->link,
        'description' => (string)$item->description,
        'pubDate' => (string)$item->pubDate,
        'comments' => (string)$item->comments,
        'author' => (string)$item->author,
        'categories' => [],
    ];
    
    // GUID
    if (isset($item->guid)) {
        $data['guid'] = (string)$item->guid;
        $data['guid_isPermaLink'] = (string)$item->guid['isPermaLink'];
    }
    
    // Categories
    foreach ($item->category as $category) {
        $data['categories'][] = (string)$category;
    }
    
    // Dublin Core
    if (isset($namespaces['dc'])) {
        $dc = $item->children($namespaces['dc']);
        $data['dc:creator'] = (string)$dc->creator;
        $data['dc:date'] = (string)$dc->date;
    }
    
    // Content Encoded
    if (isset($namespaces['content'])) {
        $content = $item->children($namespaces['content']);
        $data['content:encoded'] = (string)$content->encoded;
    }
    
    // WFW
    if (isset($namespaces['wfw'])) {
        $wfw = $item->children($namespaces['wfw']);
        $data['wfw:commentRss'] = (string)$wfw->commentRss;
    }
    
    // Slash
    if (isset($namespaces['slash'])) {
        $slash = $item->children($namespaces['slash']);
        $data['slash:comments'] = (string)$slash->comments;
    }
    
    // Enclosure
    if (isset($item->enclosure)) {
        $data['enclosure'] = [
            'url' => (string)$item->enclosure['url'],
            'length' => (string)$item->enclosure['length'],
            'type' => (string)$item->enclosure['type']
        ];
    }
    
    return $data;
}

/**
 * Generate RSS XML
 */
function generateRSS($title, $items, $feedURL) {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0"' . "\n";
    $xml .= '  xmlns:content="http://purl.org/rss/1.0/modules/content/"' . "\n";
    $xml .= '  xmlns:wfw="http://wellformedweb.org/CommentAPI/"' . "\n";
    $xml .= '  xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n";
    $xml .= '  xmlns:atom="http://www.w3.org/2005/Atom"' . "\n";
    $xml .= '  xmlns:slash="http://purl.org/rss/1.0/modules/slash/">' . "\n";
    $xml .= '  <channel>' . "\n";
    $xml .= '    <title>' . xmlEscape($title) . '</title>' . "\n";
    $xml .= '    <atom:link href="' . xmlEscape($feedURL) . '" rel="self" type="application/rss+xml" />' . "\n";
    $xml .= '    <link>https://xsukax.github.io</link>' . "\n";
    $xml .= '    <description>Filtered RSS feed by xsukax RSS Filter</description>' . "\n";
    $xml .= '    <lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";
    $xml .= '    <language>en-US</language>' . "\n";
    $xml .= '    <generator>xsukax RSS Filter v2.0</generator>' . "\n\n";
    
    foreach ($items as $item) {
        $xml .= '    <item>' . "\n";
        
        if (!empty($item['title'])) {
            $xml .= '      <title><![CDATA[' . $item['title'] . ']]></title>' . "\n";
        }
        
        if (!empty($item['link'])) {
            $xml .= '      <link>' . xmlEscape($item['link']) . '</link>' . "\n";
        }
        
        if (!empty($item['comments'])) {
            $xml .= '      <comments>' . xmlEscape($item['comments']) . '</comments>' . "\n";
        }
        
        if (!empty($item['dc:creator'])) {
            $xml .= '      <dc:creator><![CDATA[' . $item['dc:creator'] . ']]></dc:creator>' . "\n";
        }
        
        if (!empty($item['pubDate'])) {
            $xml .= '      <pubDate>' . $item['pubDate'] . '</pubDate>' . "\n";
        }
        
        if (!empty($item['categories'])) {
            foreach ($item['categories'] as $category) {
                if (!empty($category)) {
                    $xml .= '      <category><![CDATA[' . $category . ']]></category>' . "\n";
                }
            }
        }
        
        if (!empty($item['guid'])) {
            $isPermaLink = !empty($item['guid_isPermaLink']) ? $item['guid_isPermaLink'] : 'false';
            $xml .= '      <guid isPermaLink="' . $isPermaLink . '">' . xmlEscape($item['guid']) . '</guid>' . "\n";
        }
        
        if (!empty($item['description'])) {
            $xml .= '      <description><![CDATA[' . $item['description'] . ']]></description>' . "\n";
        }
        
        if (!empty($item['content:encoded'])) {
            $xml .= '      <content:encoded><![CDATA[' . $item['content:encoded'] . ']]></content:encoded>' . "\n";
        }
        
        if (!empty($item['wfw:commentRss'])) {
            $xml .= '      <wfw:commentRss>' . xmlEscape($item['wfw:commentRss']) . '</wfw:commentRss>' . "\n";
        }
        
        if (!empty($item['slash:comments'])) {
            $xml .= '      <slash:comments>' . $item['slash:comments'] . '</slash:comments>' . "\n";
        }
        
        if (!empty($item['enclosure']['url'])) {
            $xml .= '      <enclosure url="' . xmlEscape($item['enclosure']['url']) . '"';
            $xml .= ' length="' . xmlEscape($item['enclosure']['length']) . '"';
            $xml .= ' type="' . xmlEscape($item['enclosure']['type']) . '" />' . "\n";
        }
        
        $xml .= '    </item>' . "\n\n";
    }
    
    $xml .= '  </channel>' . "\n";
    $xml .= '</rss>';
    
    return $xml;
}

/**
 * Generate feed URL from form
 */
function generateFeedURL() {
    $sources = isset($_POST['sources']) ? array_filter($_POST['sources']) : [];
    $keywords = isset($_POST['keywords']) ? $_POST['keywords'] : '';
    $title = isset($_POST['title']) ? trim($_POST['title']) : 'Filtered RSS Feed';
    $titlesOnly = isset($_POST['titles_only']);
    
    // Validate
    $sources = array_values(array_filter($sources, function($url) {
        return filter_var(trim($url), FILTER_VALIDATE_URL);
    }));
    
    if (empty($sources)) {
        return ['error' => 'Please provide at least one valid RSS source URL.'];
    }
    
    $keywordArray = array_values(array_filter(array_map('trim', explode(',', $keywords))));
    
    if (empty($keywordArray)) {
        return ['error' => 'Please provide at least one keyword for filtering.'];
    }
    
    // Test fetch for stats
    $totalItems = 0;
    $matchedItems = 0;
    $processedSources = 0;
    $errors = [];
    
    foreach ($sources as $sourceURL) {
        try {
            $xml = fetchRSS($sourceURL);
            if ($xml === false) {
                $errors[] = "Failed to fetch: " . htmlspecialchars($sourceURL);
                continue;
            }
            
            $rss = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($rss === false) {
                $errors[] = "Failed to parse: " . htmlspecialchars($sourceURL);
                continue;
            }
            
            $namespaces = $rss->getNamespaces(true);
            
            foreach ($rss->channel->item as $item) {
                $totalItems++;
                
                $itemTitle = (string)$item->title;
                $itemDesc = (string)$item->description;
                $itemLink = (string)$item->link;
                
                $contentEncoded = '';
                if (isset($namespaces['content'])) {
                    $content = $item->children($namespaces['content']);
                    $contentEncoded = (string)$content->encoded;
                }
                
                $searchText = $titlesOnly ? 
                    strtolower($itemTitle) : 
                    strtolower($itemTitle . ' ' . $itemDesc . ' ' . $itemLink . ' ' . $contentEncoded);
                
                foreach ($keywordArray as $keyword) {
                    if (stripos($searchText, trim($keyword)) !== false) {
                        $matchedItems++;
                        break;
                    }
                }
            }
            
            $processedSources++;
            
        } catch (Exception $e) {
            $errors[] = "Error: " . htmlspecialchars($sourceURL);
        }
    }
    
    // Create config and encode
    $config = [
        'sources' => $sources,
        'keywords' => $keywordArray,
        'title' => $title,
        'titles_only' => $titlesOnly
    ];
    
    $encodedConfig = base64_encode(json_encode($config));
    $feedURL = getCurrentURL() . '?config=' . $encodedConfig;
    
    return [
        'success' => true,
        'feedURL' => $feedURL,
        'totalItems' => $totalItems,
        'matchedItems' => $matchedItems,
        'processedSources' => $processedSources,
        'errors' => $errors,
        'config' => $config
    ];
}

/**
 * Fetch RSS via cURL
 */
function fetchRSS($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'xsukax RSS Filter/2.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 200) ? $response : false;
}

/**
 * XML escape
 */
function xmlEscape($str) {
    return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Get current URL
 */
function getCurrentURL() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>xsukax RSS Filter - Dynamic Feed Filtering</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .slide-in { animation: slideIn 0.3s ease-out; }
        @keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Notifications -->
    <div id="notifications" class="fixed top-4 right-4 z-50 space-y-2 max-w-sm"></div>

    <!-- Header -->
    <header class="bg-gradient-to-r from-blue-600 to-blue-700 text-white shadow-lg">
        <div class="container mx-auto px-4 py-6">
            <h1 class="text-3xl font-bold mb-2">xsukax RSS Filter</h1>
            <p class="text-blue-100 text-sm">Multi-source RSS filtering - Reliable base64 encoded configuration</p>
        </div>
    </header>

    <!-- Main -->
    <main class="container mx-auto px-4 py-8 max-w-6xl">
        <!-- PHP Check -->
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
            <h3 class="font-semibold text-blue-800 mb-1">✓ PHP is Working!</h3>
            <p class="text-xs text-blue-600">Server: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?> | PHP: <?php echo PHP_VERSION; ?></p>
        </div>

        <!-- Form -->
        <section class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 pb-3 border-b-2 border-blue-500">Configuration</h2>
            
            <form method="POST">
                <!-- Title -->
                <div class="mb-6">
                    <label for="title" class="block text-sm font-semibold text-gray-700 mb-2">RSS Feed Title</label>
                    <input type="text" name="title" id="title" placeholder="My Filtered Feed" value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <!-- Keywords -->
                <div class="mb-6">
                    <label for="keywords" class="block text-sm font-semibold text-gray-700 mb-2">Keywords (comma-separated)</label>
                    <input type="text" name="keywords" id="keywords" placeholder="Python, Security, Encryption" value="<?php echo isset($_POST['keywords']) ? htmlspecialchars($_POST['keywords']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" required>
                    <p class="text-xs text-gray-500 mt-1">Items matching ANY keyword will be included</p>
                </div>

                <!-- Options -->
                <div class="mb-6">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="titles_only" <?php echo isset($_POST['titles_only']) ? 'checked' : ''; ?> class="w-4 h-4 text-blue-600 rounded">
                        <span class="ml-3 text-sm text-gray-700">Titles Only</span>
                        <span class="ml-2 text-xs text-gray-500">(Search only titles, not descriptions/content)</span>
                    </label>
                </div>

                <!-- Sources -->
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-3">
                        <label class="block text-sm font-semibold text-gray-700">RSS Feed Sources</label>
                        <button type="button" onclick="addSource()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">+ Add</button>
                    </div>
                    <div id="sources" class="space-y-3">
                        <?php
                        $sources = isset($_POST['sources']) ? $_POST['sources'] : [''];
                        foreach ($sources as $i => $src) {
                            echo '<div class="flex gap-2"><input type="url" name="sources[]" placeholder="https://example.com/feed.xml" value="' . htmlspecialchars($src) . '" class="flex-1 px-4 py-2 border rounded-lg" required>';
                            if ($i > 0 || count($sources) > 1) {
                                echo '<button type="button" onclick="this.parentElement.remove()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Remove</button>';
                            }
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="flex gap-3">
                    <button type="submit" class="flex-1 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold shadow-md">Generate Feed</button>
                    <button type="button" onclick="location.href='<?php echo $_SERVER['PHP_SELF']; ?>'" class="px-6 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 font-semibold">Reset</button>
                </div>
            </form>
        </section>

        <?php if ($result && isset($result['success'])): ?>
        <!-- Results -->
        <section class="bg-white rounded-lg shadow-md p-6 fade-in">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 pb-3 border-b-2 border-green-500">Feed Generated Successfully!</h2>

            <?php if (!empty($result['errors'])): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
                <h3 class="font-semibold text-yellow-800 mb-2">Warnings:</h3>
                <ul class="list-disc list-inside text-sm text-yellow-700">
                    <?php foreach ($result['errors'] as $err): ?>
                        <li><?php echo $err; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
                    <div class="text-sm text-blue-600 font-semibold">Total Items</div>
                    <div class="text-3xl font-bold text-blue-700"><?php echo $result['totalItems']; ?></div>
                </div>
                <div class="bg-green-50 rounded-lg p-4 border border-green-200">
                    <div class="text-sm text-green-600 font-semibold">Matched Items</div>
                    <div class="text-3xl font-bold text-green-700"><?php echo $result['matchedItems']; ?></div>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 border border-purple-200">
                    <div class="text-sm text-purple-600 font-semibold">Sources</div>
                    <div class="text-3xl font-bold text-purple-700"><?php echo $result['processedSources']; ?></div>
                </div>
            </div>

            <?php if ($result['matchedItems'] == 0): ?>
            <div class="bg-orange-50 border-l-4 border-orange-500 p-4 mb-6">
                <h3 class="font-semibold text-orange-800 mb-2">⚠️ No Items Matched</h3>
                <p class="text-sm text-orange-700 mb-2">Try: broader keywords, uncheck "Titles Only", or verify keywords exist in sources.</p>
            </div>
            <?php endif; ?>

            <!-- Config Info -->
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
                <h3 class="font-semibold text-blue-800 mb-2">📋 Feed Configuration</h3>
                <div class="text-sm text-blue-700 space-y-1">
                    <p><strong>Title:</strong> <?php echo htmlspecialchars($result['config']['title']); ?></p>
                    <p><strong>Keywords:</strong> <?php echo htmlspecialchars(implode(', ', $result['config']['keywords'])); ?></p>
                    <p><strong>Sources:</strong> <?php echo count($result['config']['sources']); ?> feed(s)</p>
                    <p><strong>Mode:</strong> <?php echo $result['config']['titles_only'] ? 'Titles Only' : 'Full Content Search'; ?></p>
                </div>
            </div>

            <!-- RSS URL -->
            <div class="bg-gradient-to-br from-green-50 to-emerald-50 border-2 border-green-400 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-3">
                    <span class="bg-green-600 text-white px-3 py-1 rounded-md text-sm mr-3">✓ RSS Feed URL</span>
                    Ready to use!
                </h3>
                <div class="flex gap-2 mb-3">
                    <input type="text" id="feedURL" readonly value="<?php echo htmlspecialchars($result['feedURL']); ?>" class="flex-1 px-4 py-3 bg-white border-2 border-green-300 rounded-lg font-mono text-xs select-all">
                    <button onclick="copy()" class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">Copy</button>
                </div>
                <a href="<?php echo htmlspecialchars($result['feedURL']); ?>" target="_blank" class="block text-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">View Live Feed</a>
                <p class="text-xs text-gray-600 mt-3">💡 Always fetches fresh content. Config is base64 encoded in URL for reliability.</p>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($result && isset($result['error'])): ?>
        <section class="bg-red-50 border-l-4 border-red-500 p-6 rounded-lg">
            <h3 class="font-semibold text-red-800 mb-2">Error</h3>
            <p class="text-red-700"><?php echo htmlspecialchars($result['error']); ?></p>
        </section>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-gray-300 py-6 mt-12">
        <div class="container mx-auto px-4 text-center">
            <p class="text-sm">xsukax RSS Filter &copy; 2025 - Reliable base64 encoding</p>
            <p class="text-xs mt-2 text-gray-400">Server-side · Always fresh · Complete metadata</p>
        </div>
    </footer>

    <script>
        function addSource() {
            const div = document.createElement('div');
            div.className = 'flex gap-2 fade-in';
            div.innerHTML = '<input type="url" name="sources[]" placeholder="https://example.com/feed.xml" class="flex-1 px-4 py-2 border rounded-lg" required><button type="button" onclick="this.parentElement.remove()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Remove</button>';
            document.getElementById('sources').appendChild(div);
        }

        function copy() {
            const input = document.getElementById('feedURL');
            input.select();
            document.execCommand('copy');
            notify('Feed URL copied!', 'success');
        }

        function notify(msg, type = 'info') {
            const div = document.createElement('div');
            div.className = `slide-in px-6 py-3 rounded-lg shadow-lg text-white font-medium ${type === 'success' ? 'bg-green-600' : 'bg-blue-600'}`;
            div.textContent = msg;
            document.getElementById('notifications').appendChild(div);
            setTimeout(() => {
                div.style.opacity = '0';
                div.style.transition = 'all 0.3s';
                setTimeout(() => div.remove(), 300);
            }, 3000);
        }
    </script>
</body>
</html>