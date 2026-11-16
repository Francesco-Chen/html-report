<?php
/**
 * HTML Report Hub - Card-Based Dashboard & Management System (Single-File Edition)
 * Tech Stack: Native PHP (PDO SQLite) + HTML5/CSS3 + Vanilla JS + SortableJS
 */

declare(strict_types=1);
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

// ==================== 1. Basic Configuration & Environment Initialization ====================
define('DB_FILE', __DIR__ . '/reports.sqlite');
define('DOC_DIR', __DIR__ . '/document');
define('COVER_DIR', __DIR__ . '/covers');

// Automatically create physical storage directories and security protection files
foreach ([DOC_DIR, COVER_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Write Apache anti-execution configuration to prevent uploaded files from executing as PHP scripts
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "php_flag engine off\nAddType text/plain .php .php3 .php4 .php5 .phtml .phar\n");
    }
}

// Connect to and initialize SQLite database
try {
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            filename TEXT DEFAULT NULL,
            cover_image TEXT DEFAULT NULL,
            display_order INTEGER DEFAULT 0,
            token TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_display_order ON reports(display_order ASC, id DESC);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_token ON reports(token);
    ");
} catch (PDOException $e) {
    http_response_code(500);
    die("Database initialization failed: " . htmlspecialchars($e->getMessage()));
}

// ==================== 2. Core Utility Functions ====================

/**
 * Unified JSON response output and script termination
 */
function jsonResponse(string $status, string $message, array $data = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['status' => $status, 'message' => $message];
    if (!empty($data)) {
        $payload['data'] = $data;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Generate 64-character cryptographically secure random hexadecimal token
 */
function generateUniqueToken(PDO $pdo): string {
    do {
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE token = ?");
        $stmt->execute([$token]);
    } while ($stmt->fetchColumn() > 0);
    return $token;
}

/**
 * Sanitize invalid characters in filenames, retaining alphanumeric characters, underscores, and dashes
 */
function sanitizeFilenameTitle(string $title): string {
    // Strip Windows/Linux illegal path characters \ / : * ? " < > | and control characters
    $clean = preg_replace('/[\\\\\/:\*\?"<>\|~\x00-\x1F]+/u', '', trim($title));
    $clean = preg_replace('/\s+/u', '_', $clean);
    $clean = trim($clean, '._-');
    if ($clean === '') {
        $clean = 'report';
    }
    return mb_substr($clean, 0, 50, 'UTF-8');
}

/**
 * Extract <title> content from HTML document
 */
function extractHtmlTitle(string $html): ?string {
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
        $title = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title !== '') {
            return mb_substr($title, 0, 120, 'UTF-8');
        }
    }
    return null;
}

/**
 * Safely delete physical files (directory traversal defense)
 */
function safeUnlink(?string $relativePath, string $allowedBaseDir): void {
    if (empty($relativePath)) {
        return;
    }
    $fullPath = __DIR__ . '/' . ltrim($relativePath, '/');
    $realBase = realpath($allowedBaseDir);
    $realFile = realpath($fullPath);
    if ($realBase && $realFile && strpos($realFile, $realBase) === 0 && is_file($realFile)) {
        @unlink($realFile);
    }
}

/**
 * Save HTML content or uploaded HTML file (supports in-place overwrite to keep URL permanent)
 */
function saveHtmlDocument(?array $fileInfo, ?string $rawHtml, string $titleForFile, ?string $existingRelativePath = null): array {
    $htmlContent = null;

    // Check file upload first
    if ($fileInfo && isset($fileInfo['error']) && $fileInfo['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("HTML file upload failed. Error code: " . $fileInfo['error']);
        }
        $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'htm'], true)) {
            throw new RuntimeException("Security alert: Report files only allow .html or .htm format!");
        }
        $htmlContent = file_get_contents($fileInfo['tmp_name']);
        if ($htmlContent === false) {
            throw new RuntimeException("Unable to read uploaded HTML temporary file.");
        }
    } elseif ($rawHtml !== null && trim($rawHtml) !== '') {
        $htmlContent = $rawHtml;
    } else {
        return ['relative_path' => null, 'extracted_title' => null];
    }

    $extractedTitle = extractHtmlTitle($htmlContent);
    $finalTitle = ($titleForFile !== '') ? $titleForFile : ($extractedTitle ?? 'Untitled_Report');

    // If card already has an HTML file, overwrite it in place so the access URL remains permanently fixed
    if (!empty($existingRelativePath)) {
        $relativePath = ltrim($existingRelativePath, '/');
        $fullPath = __DIR__ . '/' . $relativePath;
    } else {
        // Initial upload: generate standard timestamped filename
        $safeName = sanitizeFilenameTitle($finalTitle);
        $filename = time() . '_' . mt_rand(100, 999) . '_' . $safeName . '.html';
        $relativePath = 'document/' . $filename;
        $fullPath = DOC_DIR . '/' . $filename;
    }

    if (file_put_contents($fullPath, $htmlContent) === false) {
        throw new RuntimeException("Failed to write HTML file. Please check permissions for document/ directory.");
    }

    return [
        'relative_path'   => $relativePath,
        'extracted_title' => $extractedTitle,
    ];
}

/**
 * Validate and save uploaded cover image
 */
function saveCoverImage(?array $fileInfo): ?string {
    if (!$fileInfo || !isset($fileInfo['error']) || $fileInfo['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Cover image upload failed. Error code: " . $fileInfo['error']);
    }

    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        throw new RuntimeException("Security alert: Cover images only support JPG, JPEG, PNG, GIF, and WebP formats!");
    }

    // Verify genuine image information to prevent script upload with forged extensions
    $imgInfo = @getimagesize($fileInfo['tmp_name']);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if ($imgInfo === false || !in_array($imgInfo['mime'], $allowedMimes, true)) {
        throw new RuntimeException("Security alert: Uploaded file is not a valid image!");
    }

    $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $relativePath = 'covers/' . $filename;
    $fullPath = COVER_DIR . '/' . $filename;

    if (!move_uploaded_file($fileInfo['tmp_name'], $fullPath)) {
        throw new RuntimeException("Failed to save cover image. Please check permissions for covers/ directory.");
    }

    return $relativePath;
}

/**
 * Extract client-provided Card Token (supports Authorization: Bearer / X-Card-Token / POST token)
 */
function getRequestCardToken(): ?string {
    $headers = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            $headers[strtolower((string)$k)] = $v;
        }
    }
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $headerName = strtolower(str_replace('_', '-', substr($k, 5)));
            $headers[$headerName] = $v;
        }
    }

    if (!empty($headers['authorization'])) {
        if (preg_match('/Bearer\s+([a-fA-F0-9]{32,128})/i', $headers['authorization'], $m)) {
            return trim($m[1]);
        }
    }
    if (!empty($headers['x-card-token'])) {
        return trim((string)$headers['x-card-token']);
    }
    if (!empty($_POST['token'])) {
        return trim((string)$_POST['token']);
    }
    return null;
}

// ==================== 3. Routing & Business Controllers ====================

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_REQUEST['action'] ?? '';

if ($method === 'POST') {
    $detectedToken = getRequestCardToken();
    $internalActions = [
        'create_full',
        'create_quick',
        'update_card',
        'delete_card',
        'reorder',
        'reset_token',
        'get_card_detail'
    ];

    // Determine whether to route to external [Card-Dedicated API Endpoint]
    $isExternalApi = ($action === 'api_update') || ($detectedToken !== null && !in_array($action, $internalActions, true));

    if ($isExternalApi) {
        if (empty($detectedToken)) {
            jsonResponse('error', 'Invalid card token', [], 401);
        }

        $stmt = $pdo->prepare("SELECT * FROM reports WHERE token = ? LIMIT 1");
        $stmt->execute([$detectedToken]);
        $card = $stmt->fetch();

        if (!$card) {
            jsonResponse('error', 'Invalid card token', [], 403);
        }

        try {
            $inputTitle = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
            $rawHtml = isset($_POST['raw_html']) ? (string)$_POST['raw_html'] : null;
            $htmlFile = $_FILES['html_file'] ?? null;
            $coverFile = $_FILES['cover_image'] ?? null;

            $hasHtmlUpload = ($htmlFile && isset($htmlFile['error']) && $htmlFile['error'] !== UPLOAD_ERR_NO_FILE)
                             || ($rawHtml !== null && trim($rawHtml) !== '');
            $hasCoverUpload = ($coverFile && isset($coverFile['error']) && $coverFile['error'] !== UPLOAD_ERR_NO_FILE);

            if (!$hasHtmlUpload && !$hasCoverUpload && $inputTitle === '') {
                jsonResponse('error', 'No updatable fields provided (please supply at least one of: html_file, raw_html, cover_image, or title)', [], 400);
            }

            $newFilename = $card['filename'];
            $newCover = $card['cover_image'];
            $extractedTitle = null;

            // 1. Process HTML update (overwrite in place to keep URL permanent)
            if ($hasHtmlUpload) {
                $preliminaryTitle = $inputTitle !== '' ? $inputTitle : $card['title'];
                $docResult = saveHtmlDocument($htmlFile, $rawHtml, $preliminaryTitle, $card['filename']);
                if ($docResult['relative_path']) {
                    if ($card['filename'] && $card['filename'] !== $docResult['relative_path']) {
                        safeUnlink($card['filename'], DOC_DIR);
                    }
                    $newFilename = $docResult['relative_path'];
                    $extractedTitle = $docResult['extracted_title'];
                }
            }

            // 2. Determine final title: title param > HTML <title> > existing title
            if ($inputTitle !== '') {
                $finalTitle = $inputTitle;
            } elseif ($extractedTitle !== null && $extractedTitle !== '') {
                $finalTitle = $extractedTitle;
            } else {
                $finalTitle = $card['title'];
            }

            // 3. Process cover image update
            if ($hasCoverUpload) {
                $uploadedCover = saveCoverImage($coverFile);
                if ($uploadedCover) {
                    safeUnlink($card['cover_image'], COVER_DIR);
                    $newCover = $uploadedCover;
                }
            }

            $now = date('Y-m-d H:i:s');
            $updateStmt = $pdo->prepare("
                UPDATE reports
                SET title = ?, filename = ?, cover_image = ?, updated_at = ?
                WHERE id = ? AND token = ?
            ");
            $updateStmt->execute([
                $finalTitle,
                $newFilename,
                $newCover,
                $now,
                $card['id'],
                $detectedToken
            ]);

            jsonResponse('success', 'Report updated successfully', [
                'id'         => (int)$card['id'],
                'title'      => $finalTitle,
                'updated_at' => $now,
                'url'        => $newFilename ?? ''
            ]);
        } catch (Throwable $e) {
            jsonResponse('error', $e->getMessage(), [], 400);
        }
    }

    // ==================== Web Management Internal Actions ====================
    try {
        switch ($action) {
            // 1. Complete card creation
            case 'create_full':
                $inputTitle = trim((string)($_POST['title'] ?? ''));
                $rawHtml = isset($_POST['raw_html']) ? (string)$_POST['raw_html'] : null;
                $htmlFile = $_FILES['html_file'] ?? null;
                $coverFile = $_FILES['cover_image'] ?? null;

                $docRes = saveHtmlDocument($htmlFile, $rawHtml, $inputTitle);
                $finalTitle = $inputTitle !== '' ? $inputTitle : ($docRes['extracted_title'] ?? 'Untitled_Report_' . date('His'));

                // Normalize filename if title was empty but extracted from HTML
                $relativePath = $docRes['relative_path'];
                if ($inputTitle === '' && $docRes['extracted_title'] && $relativePath) {
                    $safeName = sanitizeFilenameTitle($finalTitle);
                    $newRel = 'document/' . time() . '_' . $safeName . '.html';
                    if (@rename(__DIR__ . '/' . $relativePath, __DIR__ . '/' . $newRel)) {
                        $relativePath = $newRel;
                    }
                }

                $coverPath = saveCoverImage($coverFile);
                $token = generateUniqueToken($pdo);
                $now = date('Y-m-d H:i:s');

                $minOrder = (int)$pdo->query("SELECT COALESCE(MIN(display_order), 0) FROM reports")->fetchColumn();
                $order = $minOrder - 1;

                $stmt = $pdo->prepare("
                    INSERT INTO reports (title, filename, cover_image, display_order, token, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$finalTitle, $relativePath, $coverPath, $order, $token, $now, $now]);

                jsonResponse('success', 'Card created successfully', [
                    'id'    => (int)$pdo->lastInsertId(),
                    'title' => $finalTitle,
                    'token' => $token
                ]);
                break;

            // 2. Quick placeholder creation
            case 'create_quick':
                $inputTitle = trim((string)($_POST['title'] ?? ''));
                if ($inputTitle === '') {
                    $inputTitle = 'Pending_Report_' . date('md_His');
                }
                $token = generateUniqueToken($pdo);
                $now = date('Y-m-d H:i:s');
                $minOrder = (int)$pdo->query("SELECT COALESCE(MIN(display_order), 0) FROM reports")->fetchColumn();
                $order = $minOrder - 1;

                $stmt = $pdo->prepare("
                    INSERT INTO reports (title, filename, cover_image, display_order, token, created_at, updated_at)
                    VALUES (?, NULL, NULL, ?, ?, ?, ?)
                ");
                $stmt->execute([$inputTitle, $order, $token, $now, $now]);

                jsonResponse('success', 'Placeholder card generated. You can now push content using the dedicated Token.', [
                    'id'    => (int)$pdo->lastInsertId(),
                    'title' => $inputTitle,
                    'token' => $token
                ]);
                break;

            // 3. Get single card details (including raw HTML for edit modal)
            case 'get_card_detail':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ?");
                $stmt->execute([$id]);
                $card = $stmt->fetch();
                if (!$card) {
                    jsonResponse('error', 'Card not found', [], 404);
                }
                $rawHtml = '';
                if (!empty($card['filename'])) {
                    $fullPath = __DIR__ . '/' . ltrim($card['filename'], '/');
                    if (is_file($fullPath)) {
                        $rawHtml = (string)file_get_contents($fullPath);
                    }
                }
                $card['raw_html'] = $rawHtml;
                jsonResponse('success', 'OK', $card);
                break;

            // 4. Edit and update card
            case 'update_card':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ?");
                $stmt->execute([$id]);
                $card = $stmt->fetch();
                if (!$card) {
                    jsonResponse('error', 'Card not found', [], 404);
                }

                $inputTitle = trim((string)($_POST['title'] ?? ''));
                $rawHtml = isset($_POST['raw_html']) ? (string)$_POST['raw_html'] : null;
                $updateSourceFlag = ($_POST['update_source'] ?? '0') === '1';
                $removeCoverFlag = ($_POST['remove_cover'] ?? '0') === '1';
                $htmlFile = $_FILES['html_file'] ?? null;
                $coverFile = $_FILES['cover_image'] ?? null;

                $newFilename = $card['filename'];
                $newCover = $card['cover_image'];
                $extractedTitle = null;

                $hasFileUpload = ($htmlFile && isset($htmlFile['error']) && $htmlFile['error'] !== UPLOAD_ERR_NO_FILE);
                if ($hasFileUpload || ($updateSourceFlag && $rawHtml !== null)) {
                    if ($hasFileUpload || trim($rawHtml) !== '') {
                        $docRes = saveHtmlDocument($htmlFile, $rawHtml, $inputTitle !== '' ? $inputTitle : $card['title'], $card['filename']);
                        if ($docRes['relative_path']) {
                            if ($card['filename'] && $card['filename'] !== $docRes['relative_path']) {
                                safeUnlink($card['filename'], DOC_DIR);
                            }
                            $newFilename = $docRes['relative_path'];
                            $extractedTitle = $docRes['extracted_title'];
                        }
                    } else {
                        // If source code was cleared in source edit mode, remove old HTML file
                        safeUnlink($card['filename'], DOC_DIR);
                        $newFilename = null;
                    }
                }

                $finalTitle = $inputTitle !== '' ? $inputTitle : ($extractedTitle ?? $card['title']);

                if ($removeCoverFlag) {
                    safeUnlink($card['cover_image'], COVER_DIR);
                    $newCover = null;
                }
                $uploadedCover = saveCoverImage($coverFile);
                if ($uploadedCover) {
                    safeUnlink($card['cover_image'], COVER_DIR);
                    $newCover = $uploadedCover;
                }

                $now = date('Y-m-d H:i:s');
                $upStmt = $pdo->prepare("
                    UPDATE reports
                    SET title = ?, filename = ?, cover_image = ?, updated_at = ?
                    WHERE id = ?
                ");
                $upStmt->execute([$finalTitle, $newFilename, $newCover, $now, $id]);

                jsonResponse('success', 'Card updated successfully', [
                    'id'         => $id,
                    'title'      => $finalTitle,
                    'updated_at' => $now,
                    'url'        => $newFilename ?? ''
                ]);
                break;

            // 5. Cascade delete card and physical files
            case 'delete_card':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ?");
                $stmt->execute([$id]);
                $card = $stmt->fetch();
                if (!$card) {
                    jsonResponse('error', 'Card not found', [], 404);
                }

                safeUnlink($card['filename'], DOC_DIR);
                safeUnlink($card['cover_image'], COVER_DIR);

                $delStmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
                $delStmt->execute([$id]);

                jsonResponse('success', 'Card and associated physical files permanently deleted');
                break;

            // 6. Batch drag-and-drop reordering
            case 'reorder':
                $ordersJson = $_POST['orders'] ?? '[]';
                $ids = json_decode((string)$ordersJson, true);
                if (!is_array($ids)) {
                    jsonResponse('error', 'Invalid sorting data format', [], 400);
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE reports SET display_order = ? WHERE id = ?");
                foreach (array_values($ids) as $index => $cardId) {
                    $stmt->execute([(int)$index, (int)$cardId]);
                }
                $pdo->commit();

                jsonResponse('success', 'Card order saved successfully');
                break;

            // 7. Reset single card dedicated Token
            case 'reset_token':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id FROM reports WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) {
                    jsonResponse('error', 'Card not found', [], 404);
                }

                $newToken = generateUniqueToken($pdo);
                $now = date('Y-m-d H:i:s');
                $upStmt = $pdo->prepare("UPDATE reports SET token = ?, updated_at = ? WHERE id = ?");
                $upStmt->execute([$newToken, $now, $id]);

                jsonResponse('success', 'Dedicated Token reset successfully. Old Token is now invalid.', [
                    'id'    => $id,
                    'token' => $newToken
                ]);
                break;

            default:
                jsonResponse('error', 'Unknown action request', [], 400);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse('error', $e->getMessage(), [], 400);
    }
}

// ==================== 4. Fetch Card List & Render Frontend View ====================

$cards = $pdo->query("SELECT * FROM reports ORDER BY display_order ASC, id DESC")->fetchAll();

// Derive full API endpoint URL for generating accurate cURL examples
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$currentEndpointUrl = $scheme . '://' . $host . $scriptPath;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HTML Report Hub - Card-Based Dashboard & Management System</title>
    <!-- SortableJS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <style>
        :root {
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --success: #10b981;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 10px;
            --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
            --shadow-hover: 0 18px 30px -10px rgba(15, 23, 42, 0.16), 0 8px 12px -6px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Navigation Bar */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 0.9rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .brand-logo {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), #818cf8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            font-size: 1.15rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
        }

        .brand-title {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .brand-badge {
            font-size: 0.75rem;
            background: #e0e7ff;
            color: var(--primary);
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-weight: 600;
        }

        .nav-controls {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            flex-wrap: wrap;
        }

        /* Mode Switch Pill */
        .mode-switch {
            display: inline-flex;
            background: #e2e8f0;
            padding: 4px;
            border-radius: 999px;
            user-select: none;
        }

        .mode-btn {
            border: none;
            background: transparent;
            padding: 0.42rem 1.05rem;
            border-radius: 999px;
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .mode-btn.active {
            background: var(--bg-surface);
            color: var(--text-main);
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
        }

        body.mode-manage .mode-btn[data-mode="manage"].active {
            background: var(--primary);
            color: #ffffff;
        }

        /* General Button Styles */
        .btn {
            border: 1px solid transparent;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-outline {
            background: #fff;
            border-color: var(--border);
            color: var(--text-main);
        }
        .btn-outline:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .btn-danger {
            background: var(--danger);
            color: #fff;
        }
        .btn-danger:hover {
            background: var(--danger-hover);
        }

        /* Management Mode Notice Banner */
        .manage-banner {
            display: none;
            background: linear-gradient(90deg, #eef2ff 0%, #e0e7ff 100%);
            border-bottom: 1px solid #c7d2fe;
            color: #3730a3;
            padding: 0.65rem 2rem;
            font-size: 0.87rem;
            align-items: center;
            justify-content: space-between;
        }

        body.mode-manage .manage-banner {
            display: flex;
        }

        /* Main Container & Responsive 1:1 Grid */
        .main-container {
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
            padding: 2rem;
            flex: 1;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1.5rem;
        }

        /* 1:1 Square Card Layout */
        .report-card {
            position: relative;
            aspect-ratio: 1 / 1;
            border-radius: var(--radius-lg);
            overflow: hidden;
            background: var(--bg-surface);
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.28s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
        }

        /* View Mode Hover Elevation */
        body.mode-view .report-card:hover {
            transform: translateY(-7px);
            box-shadow: var(--shadow-hover);
        }

        /* Card Background Cover Layer */
        .card-cover {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            transition: transform 0.45s ease;
        }

        body.mode-view .report-card:hover .card-cover {
            transform: scale(1.05);
        }

        /* Default Gradient Cover (when no image uploaded) */
        .card-cover-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: rgba(255, 255, 255, 0.85);
            text-align: center;
        }

        .card-cover-placeholder .placeholder-icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
            opacity: 0.9;
        }

        /* Status Badges */
        .status-pill {
            position: absolute;
            top: 0.85rem;
            right: 0.85rem;
            z-index: 4;
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
            backdrop-filter: blur(6px);
        }

        .status-pill.ready {
            background: rgba(15, 23, 42, 0.55);
            color: #f8fafc;
        }

        .status-pill.empty {
            background: rgba(245, 158, 11, 0.92);
            color: #fff;
        }

        /* Bottom Gradient Overlay & Title Info */
        .card-info-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(
                to top,
                rgba(15, 23, 42, 0.92) 0%,
                rgba(15, 23, 42, 0.5) 38%,
                rgba(15, 23, 42, 0.05) 75%,
                transparent 100%
            );
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 1.25rem;
            z-index: 2;
        }

        .card-title {
            color: #ffffff;
            font-size: 1.08rem;
            font-weight: 700;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
            margin-bottom: 0.35rem;
        }

        .card-meta {
            color: rgba(241, 245, 249, 0.75);
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* View Mode Click Hotspot */
        .card-view-link {
            position: absolute;
            inset: 0;
            z-index: 5;
            cursor: pointer;
            text-decoration: none;
        }

        /* Disable Click-through in Manage Mode */
        body.mode-manage .card-view-link {
            display: none !important;
            pointer-events: none !important;
        }

        /* Manage Mode Overlay, Drag Handle & Action Buttons */
        .card-manage-layer {
            display: none;
            position: absolute;
            inset: 0;
            z-index: 10;
            background: rgba(15, 23, 42, 0.35);
            backdrop-filter: blur(2px);
            flex-direction: column;
            justify-content: space-between;
            padding: 0.85rem;
        }

        body.mode-manage .card-manage-layer {
            display: flex;
        }

        body.mode-manage .report-card {
            border: 2px dashed #94a3b8;
            cursor: grab;
        }

        body.mode-manage .report-card:active {
            cursor: grabbing;
        }

        .drag-handle {
            align-self: flex-start;
            background: rgba(255, 255, 255, 0.92);
            color: var(--text-main);
            padding: 0.35rem 0.65rem;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            cursor: grab;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }

        .manage-btn-group {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.45rem;
            background: rgba(15, 23, 42, 0.78);
            padding: 0.5rem;
            border-radius: 12px;
            backdrop-filter: blur(8px);
        }

        .card-act-btn {
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            padding: 0.45rem 0.3rem;
            border-radius: 8px;
            font-size: 0.76rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            text-align: center;
            white-space: nowrap;
        }

        .card-act-btn:hover {
            background: rgba(255, 255, 255, 0.26);
        }

        .card-act-btn.token-btn {
            background: rgba(79, 70, 229, 0.85);
            border-color: rgba(129, 140, 248, 0.5);
        }
        .card-act-btn.token-btn:hover {
            background: rgba(67, 56, 202, 1);
        }

        .card-act-btn.del-btn {
            background: rgba(239, 68, 68, 0.82);
            border-color: rgba(248, 113, 113, 0.4);
        }
        .card-act-btn.del-btn:hover {
            background: rgba(220, 38, 38, 1);
        }

        .sortable-ghost {
            opacity: 0.35;
            transform: scale(0.96);
        }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            background: var(--bg-surface);
            border-radius: var(--radius-lg);
            border: 2px dashed var(--border);
            padding: 4rem 2rem;
            text-align: center;
            color: var(--text-muted);
        }

        /* Modal General Styles */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 200;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
        }

        .modal-backdrop.show {
            display: flex;
        }

        .modal-dialog {
            background: var(--bg-surface);
            width: 100%;
            max-width: 680px;
            max-height: 90vh;
            border-radius: var(--radius-lg);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalIn 0.2s ease-out;
        }

        @keyframes modalIn {
            from { opacity: 0; transform: translateY(12px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header {
            padding: 1.15rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            font-size: 1.08rem;
            font-weight: 700;
        }

        .modal-close {
            border: none;
            background: transparent;
            font-size: 1.4rem;
            line-height: 1;
            color: var(--text-muted);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            background: #f8fafc;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        /* Form Controls */
        .form-group {
            margin-bottom: 1.15rem;
        }

        .form-label {
            display: block;
            font-size: 0.86rem;
            font-weight: 600;
            margin-bottom: 0.45rem;
            color: var(--text-main);
        }

        .form-hint {
            font-size: 0.76rem;
            color: var(--text-muted);
            margin-top: 0.3rem;
        }

        .form-input, .form-textarea {
            width: 100%;
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            color: var(--text-main);
            background: #fff;
            transition: border-color 0.15s;
        }

        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12);
        }

        .form-textarea {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            min-height: 170px;
            resize: vertical;
        }

        /* Tab Navigation */
        .tab-bar {
            display: flex;
            gap: 0.5rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.25rem;
        }

        .tab-item {
            padding: 0.55rem 1rem;
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-muted);
            border: none;
            background: transparent;
            border-bottom: 2px solid transparent;
            cursor: pointer;
        }

        .tab-item.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        /* Code Box */
        .code-box {
            background: #0f172a;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.8rem;
            line-height: 1.55;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
            margin-top: 0.45rem;
        }

        .token-display-row {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .token-display-row input {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            background: #f8fafc;
            font-weight: 600;
            color: #334155;
        }

        /* Toast Notifications */
        .toast-wrap {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 999;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .toast-msg {
            background: #1e293b;
            color: #fff;
            padding: 0.75rem 1.2rem;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 500;
            box-shadow: 0 10px 20px rgba(0,0,0,0.18);
            animation: modalIn 0.2s ease;
        }
        .toast-msg.error {
            background: var(--danger);
        }
        .toast-msg.success {
            background: #059669;
        }

        @media (max-width: 640px) {
            .navbar { padding: 0.85rem 1rem; }
            .main-container { padding: 1.2rem 1rem; }
            .card-grid { grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 1rem; }
        }
    </style>
</head>
<body class="mode-view">

    <!-- Top Navbar -->
    <header class="navbar">
        <div class="brand">
            <div class="brand-logo">R</div>
            <div>
                <span class="brand-title">HTML Report Hub</span>
                <span class="brand-badge"><?= count($cards) ?> <?= count($cards) === 1 ? 'Report' : 'Reports' ?></span>
            </div>
        </div>

        <div class="nav-controls">
            <!-- Dual-mode Switcher -->
            <div class="mode-switch" role="group" aria-label="Mode Switch">
                <button type="button" class="mode-btn active" data-mode="view" onclick="switchMode('view')">
                    📖 View Mode
                </button>
                <button type="button" class="mode-btn" data-mode="manage" onclick="switchMode('manage')">
                    ⚙️ Manage Mode
                </button>
            </div>

            <!-- Web Creation Actions -->
            <button type="button" class="btn btn-outline" onclick="openQuickCreateModal()">
                ⚡ Quick Placeholder
            </button>
            <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                ➕ Create Report
            </button>
        </div>
    </header>

    <!-- Management Mode Banner -->
    <div class="manage-banner">
        <span>🔧 <strong>Manage Mode Active</strong>: Link redirection is disabled. Hold and drag cards to reorder (auto-saved), or use the buttons below each card to manage API Token, edit details, or delete.</span>
        <button type="button" class="btn btn-outline" style="padding:0.25rem 0.7rem;font-size:0.78rem;" onclick="switchMode('view')">Return to View Mode</button>
    </div>

    <!-- Main Card Grid -->
    <main class="main-container">
        <div id="cardGrid" class="card-grid">
            <?php if (empty($cards)): ?>
                <div class="empty-state">
                    <h3 style="font-size:1.15rem;color:var(--text-main);margin-bottom:0.5rem;">No HTML Reports Yet</h3>
                    <p style="font-size:0.9rem;margin-bottom:1.25rem;">Click "Create Report" to upload an HTML file, or "Quick Placeholder" to get a dedicated API Token for automated script push.</p>
                    <button type="button" class="btn btn-primary" onclick="openCreateModal()">➕ Create First Report</button>
                </div>
            <?php else: ?>
                <?php
                // Default gradient backgrounds displayed when no cover image is provided
                $gradients = [
                    'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)',
                    'linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%)',
                    'linear-gradient(135deg, #059669 0%, #0d9488 100%)',
                    'linear-gradient(135deg, #d97706 0%, #dc2626 100%)',
                    'linear-gradient(135deg, #db2777 0%, #9333ea 100%)',
                    'linear-gradient(135deg, #334155 0%, #0f172a 100%)',
                ];
                foreach ($cards as $card):
                    $id = (int)$card['id'];
                    $title = htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8');
                    $filename = $card['filename'] ? htmlspecialchars($card['filename'], ENT_QUOTES, 'UTF-8') : '';
                    $cover = $card['cover_image'] ? htmlspecialchars($card['cover_image'], ENT_QUOTES, 'UTF-8') : '';
                    $token = htmlspecialchars($card['token'], ENT_QUOTES, 'UTF-8');
                    $updatedAt = htmlspecialchars(substr((string)$card['updated_at'], 0, 16), ENT_QUOTES, 'UTF-8');
                    $bgGradient = $gradients[$id % count($gradients)];
                    $hasReport = !empty($filename);
                ?>
                <div class="report-card" data-id="<?= $id ?>" data-title="<?= $title ?>" data-token="<?= $token ?>">
                    <!-- Cover Background -->
                    <?php if ($cover !== ''): ?>
                        <div class="card-cover" style="background-image: url('<?= $cover ?>');"></div>
                    <?php else: ?>
                        <div class="card-cover card-cover-placeholder" style="background: <?= $bgGradient ?>;">
                            <div class="placeholder-icon"><?= $hasReport ? '📊' : '⏳' ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Status Badges -->
                    <?php if ($hasReport): ?>
                        <span class="status-pill ready">HTML Report</span>
                    <?php else: ?>
                        <span class="status-pill empty">Pending API Push</span>
                    <?php endif; ?>

                    <!-- Bottom Info Overlay -->
                    <div class="card-info-overlay">
                        <div class="card-title" title="<?= $title ?>"><?= $title ?></div>
                        <div class="card-meta">
                            <span>#<?= $id ?></span>
                            <span>Updated <?= $updatedAt ?></span>
                        </div>
                    </div>

                    <!-- View Mode Click Overlay (Opens report in new tab with cache-busting timestamp param) -->
                    <?php if ($hasReport): ?>
                        <a class="card-view-link" href="<?= $filename ?>?v=<?= strtotime($card['updated_at'] ?? 'now') ?>" target="_blank" rel="noopener" title="Click to open '<?= $title ?>' in a new tab"></a>
                    <?php else: ?>
                        <a class="card-view-link" href="javascript:void(0);" onclick="showToast('This placeholder card has no HTML report content yet', 'error')" title="No report content yet"></a>
                    <?php endif; ?>

                    <!-- Manage Mode Layer -->
                    <div class="card-manage-layer">
                        <div class="drag-handle" title="Hold and drag to reorder">
                            ⠿ Drag to Reorder
                        </div>
                        <div class="manage-btn-group">
                            <button type="button" class="card-act-btn token-btn"
                                onclick="openTokenModalFromCard(this)">
                                🔑 Token
                            </button>
                            <button type="button" class="card-act-btn"
                                onclick="openEditModal(<?= $id ?>)">
                                ✏️ Edit
                            </button>
                            <button type="button" class="card-act-btn del-btn"
                                onclick="confirmDeleteCardFromCard(this)">
                                🗑️ Delete
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- ==================== Modal 1: Create Report Card ==================== -->
    <div id="createModal" class="modal-backdrop">
        <div class="modal-dialog">
            <div class="modal-header">
                <span class="modal-title">➕ Create HTML Report Card</span>
                <button type="button" class="modal-close" onclick="closeModal('createModal')">&times;</button>
            </div>
            <form id="createFullForm" onsubmit="submitCreateFull(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Report Title (Optional, will auto-extract from HTML &lt;title&gt; if empty)</label>
                        <input type="text" name="title" class="form-input" placeholder="e.g. 2026 Q4 Core Metrics & Analytics Report">
                    </div>

                    <div class="tab-bar">
                        <button type="button" class="tab-item active" onclick="switchCreateTab('file', this)">📁 Upload HTML File</button>
                        <button type="button" class="tab-item" onclick="switchCreateTab('code', this)">💻 Paste HTML Code</button>
                    </div>

                    <div id="createTabFile" class="form-group">
                        <label class="form-label">Select HTML Report File (.html / .htm only)</label>
                        <input type="file" name="html_file" accept=".html,.htm" class="form-input">
                        <div class="form-hint">If not uploading now, you can push content anytime using the card's dedicated API Token.</div>
                    </div>

                    <div id="createTabCode" class="form-group" style="display:none;">
                        <label class="form-label">Paste Full HTML Source Code</label>
                        <textarea name="raw_html" class="form-textarea" placeholder="<!DOCTYPE html><html><head><title>Report Title</title></head><body>...</body></html>"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Card Cover Image (Optional, 1:1 ratio recommended, supports JPG/PNG/GIF/WebP)</label>
                        <input type="file" name="cover_image" accept=".jpg,.jpeg,.png,.gif,.webp" class="form-input">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('createModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Report Card</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================== Modal 2: Quick Placeholder Card ==================== -->
    <div id="quickModal" class="modal-backdrop">
        <div class="modal-dialog" style="max-width: 480px;">
            <div class="modal-header">
                <span class="modal-title">⚡ Quick Placeholder Card</span>
                <button type="button" class="modal-close" onclick="closeModal('quickModal')">&times;</button>
            </div>
            <form id="createQuickForm" onsubmit="submitCreateQuick(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Placeholder Title (Optional, auto-generated if left empty)</label>
                        <input type="text" name="title" class="form-input" placeholder="e.g. Daily CI/CD Build Monitoring Report">
                        <div class="form-hint">A dedicated API Token and cURL automation push examples will be generated immediately upon creation.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('quickModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">⚡ Generate & Get Token</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================== Modal 3: Dedicated Token & API Guide ==================== -->
    <div id="tokenModal" class="modal-backdrop">
        <div class="modal-dialog">
            <div class="modal-header">
                <span class="modal-title">🔑 Dedicated Card Token & API Integration Guide</span>
                <button type="button" class="modal-close" onclick="closeModal('tokenModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="tokenModalCardId">
                <div class="form-group">
                    <label class="form-label">Bound Card: <span id="tokenModalCardTitle" style="color:var(--primary);"></span></label>
                    <div class="token-display-row">
                        <input type="text" id="tokenModalInput" class="form-input" readonly>
                        <button type="button" class="btn btn-primary" onclick="copyText(document.getElementById('tokenModalInput').value, 'Token copied to clipboard')">Copy Token</button>
                        <button type="button" class="btn btn-danger" onclick="resetCardToken()">Reset Token</button>
                    </div>
                    <div class="form-hint">🔒 Permission Isolation: This Token only grants permission to update this specific card. It cannot access, modify, or impact any other cards.</div>
                </div>

                <div class="form-group">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <label class="form-label" style="margin-bottom:0;">Example 1: Upload new HTML report file & cover image via cURL</label>
                        <button type="button" class="btn btn-outline" style="padding:0.2rem 0.6rem;font-size:0.75rem;" onclick="copyText(document.getElementById('curlFileExample').innerText, 'cURL command copied')">Copy Command</button>
                    </div>
                    <pre id="curlFileExample" class="code-box"></pre>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <label class="form-label" style="margin-bottom:0;">Example 2: Push raw HTML string (raw_html) directly via cURL</label>
                        <button type="button" class="btn btn-outline" style="padding:0.2rem 0.6rem;font-size:0.75rem;" onclick="copyText(document.getElementById('curlRawExample').innerText, 'cURL command copied')">Copy Command</button>
                    </div>
                    <pre id="curlRawExample" class="code-box"></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('tokenModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- ==================== Modal 4: Edit Report Card ==================== -->
    <div id="editModal" class="modal-backdrop">
        <div class="modal-dialog">
            <div class="modal-header">
                <span class="modal-title">✏️ Edit Report Card</span>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form id="editCardForm" onsubmit="submitUpdateCard(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="editCardId">
                    <input type="hidden" name="update_source" id="editUpdateSource" value="0">

                    <div class="form-group">
                        <label class="form-label">Card Title</label>
                        <input type="text" name="title" id="editCardTitle" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Replace or Upload New HTML Report File (Optional, .html / .htm only)</label>
                        <input type="file" name="html_file" accept=".html,.htm" class="form-input">
                    </div>

                    <div class="form-group">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.45rem;">
                            <label class="form-label" style="margin-bottom:0;">View / Edit HTML Source Code</label>
                            <label style="font-size:0.8rem;color:var(--primary);cursor:pointer;font-weight:600;">
                                <input type="checkbox" id="enableSourceEdit" onchange="toggleSourceEdit(this.checked)"> Enable source editing & overwrite on save
                            </label>
                        </div>
                        <textarea name="raw_html" id="editCardRawHtml" class="form-textarea" readonly style="background:#f8fafc;"></textarea>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Replace Cover Image (Optional, supports JPG/PNG/GIF/WebP)</label>
                        <input type="file" name="cover_image" accept=".jpg,.jpeg,.png,.gif,.webp" class="form-input">
                        <label style="display:inline-flex;align-items:center;gap:0.4rem;margin-top:0.5rem;font-size:0.82rem;color:var(--danger);cursor:pointer;">
                            <input type="checkbox" name="remove_cover" value="1" id="editRemoveCover"> Remove current cover image (restore default gradient background)
                        </label>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content:space-between;">
                    <button type="button" class="btn btn-outline" onclick="openTokenFromEdit()">🔑 View / Reset Card Token</button>
                    <div style="display:flex;gap:0.65rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastWrap" class="toast-wrap"></div>

    <script>
        const API_ENDPOINT = <?= json_encode($currentEndpointUrl, JSON_UNESCAPED_SLASHES) ?>;
        let sortableInstance = null;
        let currentEditingCard = null;

        // Initialize SortableJS drag-and-drop sorting (active only in Manage Mode)
        document.addEventListener('DOMContentLoaded', () => {
            const savedMode = sessionStorage.getItem('report_ui_mode') || 'view';
            initSortable();
            switchMode(savedMode);
        });

        function initSortable() {
            const grid = document.getElementById('cardGrid');
            if (!grid || !window.Sortable) return;

            sortableInstance = new Sortable(grid, {
                animation: 200,
                disabled: true,
                draggable: '.report-card',
                ghostClass: 'sortable-ghost',
                onEnd: async function () {
                    const cards = Array.from(grid.querySelectorAll('.report-card'));
                    const orderedIds = cards.map(el => parseInt(el.getAttribute('data-id'), 10));
                    const fd = new FormData();
                    fd.append('action', 'reorder');
                    fd.append('orders', JSON.stringify(orderedIds));

                    try {
                        const res = await fetch('index.php', { method: 'POST', body: fd });
                        const json = await res.json();
                        if (json.status === 'success') {
                            showToast('Card order saved successfully', 'success');
                        } else {
                            showToast(json.message || 'Failed to save card order', 'error');
                        }
                    } catch (err) {
                        showToast('Network error, card order not saved', 'error');
                    }
                }
            });
        }

        // Switch between [View Mode] and [Manage Mode]
        function switchMode(mode) {
            const isManage = (mode === 'manage');
            document.body.classList.toggle('mode-manage', isManage);
            document.body.classList.toggle('mode-view', !isManage);

            document.querySelectorAll('.mode-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
            });

            if (sortableInstance) {
                sortableInstance.option('disabled', !isManage);
            }
            sessionStorage.setItem('report_ui_mode', mode);
        }

        // Modal Dialog Controls
        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        function openCreateModal() {
            document.getElementById('createFullForm').reset();
            openModal('createModal');
        }

        function openQuickCreateModal() {
            document.getElementById('createQuickForm').reset();
            openModal('quickModal');
        }

        function switchCreateTab(type, btnEl) {
            document.querySelectorAll('#createModal .tab-item').forEach(b => b.classList.remove('active'));
            btnEl.classList.add('active');
            document.getElementById('createTabFile').style.display = (type === 'file') ? 'block' : 'none';
            document.getElementById('createTabCode').style.display = (type === 'code') ? 'block' : 'none';
        }

        // Submit Full Report Creation
        async function submitCreateFull(e) {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'create_full');

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'success') {
                    closeModal('createModal');
                    showToast('Card created successfully! Refreshing...', 'success');
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast(json.message || 'Failed to create card', 'error');
                }
            } catch (err) {
                showToast('Request failed. Please check network or file size limits.', 'error');
            }
        }

        // Submit Quick Placeholder Creation
        async function submitCreateQuick(e) {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'create_quick');

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'success') {
                    closeModal('quickModal');
                    sessionStorage.setItem('open_token_after_reload', JSON.stringify(json.data));
                    location.reload();
                } else {
                    showToast(json.message || 'Failed to generate placeholder card', 'error');
                }
            } catch (err) {
                showToast('Network error', 'error');
            }
        }

        // Automatically pop up Token modal if redirected after quick creation
        window.addEventListener('load', () => {
            const pending = sessionStorage.getItem('open_token_after_reload');
            if (pending) {
                sessionStorage.removeItem('open_token_after_reload');
                try {
                    const data = JSON.parse(pending);
                    openTokenModal(data.id, data.title, data.token);
                    showToast('Placeholder card created. Please copy your dedicated Token below.', 'success');
                } catch (_) {}
            }
        });

        // Open Token & cURL Examples Modal
        function openTokenModal(id, title, token) {
            document.getElementById('tokenModalCardId').value = id;
            document.getElementById('tokenModalCardTitle').textContent = `${title} (#${id})`;
            document.getElementById('tokenModalInput').value = token;
            renderCurlExamples(token);
            openModal('tokenModal');
        }

        function openTokenModalFromCard(btn) {
            const card = btn.closest('.report-card');
            if (!card) return;
            openTokenModal(card.dataset.id, card.dataset.title, card.dataset.token);
        }

        function confirmDeleteCardFromCard(btn) {
            const card = btn.closest('.report-card');
            if (!card) return;
            confirmDeleteCard(card.dataset.id, card.dataset.title);
        }

        function renderCurlExamples(token) {
            const fileCmd = `curl -X POST "${API_ENDPOINT}" \\\n  -H "Authorization: Bearer ${token}" \\\n  -F "html_file=@/path/to/report.html" \\\n  -F "cover_image=@/path/to/cover.png" \\\n  -F "title=Latest Automated Analysis Report"`;

            const rawCmd = `curl -X POST "${API_ENDPOINT}" \\\n  -H "X-Card-Token: ${token}" \\\n  -F "raw_html=<!DOCTYPE html><html><head><title>Auto-extracted Title</title></head><body><h1>Hello Report Hub</h1></body></html>"`;

            document.getElementById('curlFileExample').textContent = fileCmd;
            document.getElementById('curlRawExample').textContent = rawCmd;
        }

        // Reset Dedicated Token
        async function resetCardToken() {
            const id = document.getElementById('tokenModalCardId').value;
            if (!confirm('Are you sure you want to reset this card\'s dedicated Token? Existing automated scripts must be updated with the new Token to continue pushing updates.')) {
                return;
            }
            const fd = new FormData();
            fd.append('action', 'reset_token');
            fd.append('id', id);

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'success') {
                    const newToken = json.data.token;
                    document.getElementById('tokenModalInput').value = newToken;
                    renderCurlExamples(newToken);
                    // Synchronize updated token to DOM dataset
                    const cardEl = document.querySelector(`.report-card[data-id="${id}"]`);
                    if (cardEl) {
                        cardEl.dataset.token = newToken;
                    }
                    if (currentEditingCard && String(currentEditingCard.id) === String(id)) {
                        currentEditingCard.token = newToken;
                    }
                    showToast(json.message, 'success');
                } else {
                    showToast(json.message || 'Failed to reset Token', 'error');
                }
            } catch (err) {
                showToast('Network request error', 'error');
            }
        }

        // Open Edit Card Modal
        async function openEditModal(id) {
            const fd = new FormData();
            fd.append('action', 'get_card_detail');
            fd.append('id', id);

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status !== 'success') {
                    showToast(json.message || 'Failed to fetch card details', 'error');
                    return;
                }
                currentEditingCard = json.data;
                document.getElementById('editCardForm').reset();
                document.getElementById('editCardId').value = currentEditingCard.id;
                document.getElementById('editCardTitle').value = currentEditingCard.title;
                document.getElementById('editCardRawHtml').value = currentEditingCard.raw_html || '';
                document.getElementById('enableSourceEdit').checked = false;
                toggleSourceEdit(false);
                openModal('editModal');
            } catch (err) {
                showToast('Error reading card details', 'error');
            }
        }

        function toggleSourceEdit(enabled) {
            const textarea = document.getElementById('editCardRawHtml');
            const hiddenFlag = document.getElementById('editUpdateSource');
            textarea.readOnly = !enabled;
            textarea.style.background = enabled ? '#ffffff' : '#f8fafc';
            hiddenFlag.value = enabled ? '1' : '0';
        }

        function openTokenFromEdit() {
            if (!currentEditingCard) return;
            closeModal('editModal');
            openTokenModal(currentEditingCard.id, currentEditingCard.title, currentEditingCard.token);
        }

        // Submit Card Update
        async function submitUpdateCard(e) {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'update_card');

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'success') {
                    closeModal('editModal');
                    showToast('Card changes saved! Refreshing...', 'success');
                    setTimeout(() => location.reload(), 450);
                } else {
                    showToast(json.message || 'Failed to save changes', 'error');
                }
            } catch (err) {
                showToast('Request failed', 'error');
            }
        }

        // Delete Card and Physical Files
        async function confirmDeleteCard(id, title) {
            if (!confirm(`Are you sure you want to permanently delete "${title}"?\nWarning: This will physically delete the HTML report in document/ and the cover image in covers/. This action cannot be undone!`)) {
                return;
            }
            const fd = new FormData();
            fd.append('action', 'delete_card');
            fd.append('id', id);

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'success') {
                    const cardEl = document.querySelector(`.report-card[data-id="${id}"]`);
                    if (cardEl) cardEl.remove();
                    showToast(json.message, 'success');
                } else {
                    showToast(json.message || 'Failed to delete card', 'error');
                }
            } catch (err) {
                showToast('Delete request failed', 'error');
            }
        }

        // Clipboard Copy Helper
        async function copyText(text, successMsg = 'Copied to clipboard') {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const temp = document.createElement('textarea');
                    temp.value = text;
                    document.body.appendChild(temp);
                    temp.select();
                    document.execCommand('copy');
                    temp.remove();
                }
                showToast(successMsg, 'success');
            } catch (err) {
                showToast('Copy failed, please copy manually', 'error');
            }
        }

        // Toast Notification System
        function showToast(msg, type = 'info') {
            const wrap = document.getElementById('toastWrap');
            const el = document.createElement('div');
            el.className = `toast-msg ${type}`;
            el.textContent = msg;
            wrap.appendChild(el);
            setTimeout(() => {
                el.style.opacity = '0';
                el.style.transition = 'opacity 0.25s ease';
                setTimeout(() => el.remove(), 250);
            }, 3000);
        }
    </script>
</body>
</html>