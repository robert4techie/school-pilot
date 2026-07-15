<?php
/**
 * api/books_handler.php
 * JSON API for library_books.php
 * Lives at:  <project-root>/api/books_handler.php
 */

require_once '../auth.php';
require_once '../conn.php';

// ── Always respond with JSON ──────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── Prevent stray output from corrupting JSON ─────────────────────────────────
ob_start();

// ── Access control ────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$uid  = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$row  = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

$allowed = ['librarian', 'developer', 'super user', 'school leader'];
if (!in_array(strtolower(trim($row['role'])), $allowed, true)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

// ── Helper: send JSON and exit ────────────────────────────────────────────────
function respond(array $payload, int $code = 200): void
{
    ob_end_clean();
    http_response_code($code);
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Helper: CSRF validation ───────────────────────────────────────────────────
function verifyCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) &&
           hash_equals($_SESSION['csrf_token'], $token);
}

// ── Route on HTTP method ──────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════════════════════════
// GET  requests  (read-only, no CSRF needed)
// ════════════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {

    $action = $_GET['action'] ?? '';

    // ── getAllBooks ──────────────────────────────────────────────────────────
    if ($action === 'getAllBooks') {
        $search    = trim($_GET['search']    ?? '');
        $subject   = trim($_GET['subject']   ?? '');
        $class     = trim($_GET['class']     ?? '');
        $publisher = trim($_GET['publisher'] ?? '');

        $query  = "SELECT book_id, date_added, title, author, subject, class,
                          publisher, supplier, copies, notes
                   FROM books WHERE 1=1";
        $params = [];
        $types  = '';

        if ($search !== '') {
            $query   .= " AND (title LIKE ? OR author LIKE ? OR subject LIKE ? OR publisher LIKE ?)";
            $like     = "%$search%";
            $params   = array_merge($params, [$like, $like, $like, $like]);
            $types   .= 'ssss';
        }
        if ($subject !== '') {
            $query   .= " AND subject = ?";
            $params[] = $subject;
            $types   .= 's';
        }
        if ($class !== '') {
            $query   .= " AND class = ?";
            $params[] = $class;
            $types   .= 's';
        }
        if ($publisher !== '') {
            $query   .= " AND publisher = ?";
            $params[] = $publisher;
            $types   .= 's';
        }

        $query .= " ORDER BY date_added DESC";

        $stmt = $conn->prepare($query);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        respond($books);
    }

    // ── getBook ──────────────────────────────────────────────────────────────
    if ($action === 'getBook') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'Invalid book ID.'], 400);
        }

        $stmt = $conn->prepare("SELECT * FROM books WHERE book_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $book = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$book) {
            respond(['success' => false, 'message' => 'Book not found.'], 404);
        }

        respond(['success' => true, 'data' => $book]);
    }

    respond(['success' => false, 'message' => 'Unknown action.'], 400);
}

// ════════════════════════════════════════════════════════════════════════════════
// POST requests  (writes — require CSRF)
// ════════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {

    // Read JSON body (library_books.php sends Content-Type: application/json)
    $raw    = file_get_contents('php://input');
    $body   = json_decode($raw, true);

    if (!is_array($body)) {
        respond(['success' => false, 'message' => 'Invalid request body.'], 400);
    }

    // CSRF check
    $token = $body['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        respond(['success' => false, 'message' => 'Invalid or expired security token. Please refresh the page.'], 403);
    }

    $action = $body['action'] ?? '';

    // ── addBook ──────────────────────────────────────────────────────────────
    if ($action === 'addBook') {
        $title     = trim($body['title']     ?? '');
        $author    = trim($body['author']    ?? '');
        $subject   = trim($body['subject']   ?? '');
        $class     = trim($body['class']     ?? '');
        $publisher = trim($body['publisher'] ?? '');
        $supplier  = trim($body['supplier']  ?? '');
        $copies    = (int) ($body['copies']  ?? 0);
        $notes     = trim($body['notes']     ?? '');
        $dateAdded = trim($body['date_added'] ?? date('Y-m-d H:i:s'));

        if ($title === '' || $author === '' || $subject === '' || $copies < 1) {
            respond(['success' => false, 'message' => 'Title, author, subject and at least 1 copy are required.'], 422);
        }

        // Normalise date_added to DATETIME format
        if (strlen($dateAdded) === 16) {          // "YYYY-MM-DDTHH:MM"
            $dateAdded = str_replace('T', ' ', $dateAdded) . ':00';
        } elseif (strlen($dateAdded) === 10) {    // "YYYY-MM-DD"
            $dateAdded .= ' 00:00:00';
        }

        $stmt = $conn->prepare(
            "INSERT INTO books (date_added, title, author, subject, class, publisher, supplier, copies, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sssssssis',
            $dateAdded, $title, $author, $subject, $class,
            $publisher, $supplier, $copies, $notes
        );

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond(['success' => false, 'message' => 'Database error: ' . $err], 500);
        }
        $newId = $stmt->insert_id;
        $stmt->close();

        respond(['success' => true, 'message' => 'Book added successfully.', 'book_id' => $newId]);
    }

    // ── updateBook ───────────────────────────────────────────────────────────
    if ($action === 'updateBook') {
        $id        = (int) ($body['book_id'] ?? 0);
        $title     = trim($body['title']     ?? '');
        $author    = trim($body['author']    ?? '');
        $subject   = trim($body['subject']   ?? '');
        $class     = trim($body['class']     ?? '');
        $publisher = trim($body['publisher'] ?? '');
        $supplier  = trim($body['supplier']  ?? '');
        $copies    = (int) ($body['copies']  ?? 0);
        $notes     = trim($body['notes']     ?? '');

        if ($id <= 0 || $title === '' || $author === '' || $subject === '' || $copies < 1) {
            respond(['success' => false, 'message' => 'All required fields must be filled.'], 422);
        }

        // title, author, subject, class, publisher, supplier = 6 strings
        // copies = int, notes = string, book_id (WHERE) = int  → ssssssisi
        $stmt = $conn->prepare(
            "UPDATE books
             SET title=?, author=?, subject=?, class=?, publisher=?, supplier=?, copies=?, notes=?
             WHERE book_id=?"
        );
        $stmt->bind_param('ssssssisi',
            $title, $author, $subject, $class,
            $publisher, $supplier, $copies, $notes, $id
        );

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond(['success' => false, 'message' => 'Database error: ' . $err], 500);
        }
        $stmt->close();

        respond(['success' => true, 'message' => 'Book updated successfully.']);
    }

    // ── deleteBook ───────────────────────────────────────────────────────────
    if ($action === 'deleteBook') {
        $id = (int) ($body['book_id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'Invalid book ID.'], 400);
        }

        $stmt = $conn->prepare("DELETE FROM books WHERE book_id = ?");
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond(['success' => false, 'message' => 'Database error: ' . $err], 500);
        }
        $stmt->close();

        respond(['success' => true, 'message' => 'Book deleted successfully.']);
    }

    respond(['success' => false, 'message' => 'Unknown action.'], 400);
}

// ── Any other method ──────────────────────────────────────────────────────────
ob_end_clean();
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit;