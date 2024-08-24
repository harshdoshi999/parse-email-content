<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

// Load environment variables from .env file
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Access environment variables
$secretKey = $_ENV['SECRET_KEY'];
$algo = $_ENV['JWT_ALGO'];

// Database connection (using PDO)
$host = $_ENV['DB_HOST'];
$dbname = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// JWT Middleware
$jwtMiddleware = function (Request $request, $handler) use ($secretKey, $algo) {
    $authHeader = $request->getHeader('Authorization');
    
    if (!$authHeader) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Authorization header not found']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }

    $token = str_replace('Bearer ', '', $authHeader[0]);

    try {
        $decoded = JWT::decode($token, new Key($secretKey, $algo));
        $request = $request->withAttribute('jwt', $decoded);
        return $handler->handle($request);
    } catch (\Exception $e) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Invalid token']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }
};

// Login route - Generates JWT
$app->post('/login', function (Request $request, Response $response) use ($pdo, $secretKey, $algo) {
    $data = $request->getParsedBody();
    $email = $data['email'];
    $password = $data['password'];

    if (($email == "andre.thivierge@inflektion.ai" || $email == "haafiz.dossa@inflektion.ai") && $password == "12345678") {
        $payload = [
            'id' => $password,
            'email' => $email,
            'exp' => time() + (60 * 60) // Token expires in 1 hour
        ];
        $jwt = JWT::encode($payload, $secretKey, $algo);

        $response->getBody()->write(json_encode(['token' => $jwt]));
    } else {
        $response->getBody()->write(json_encode(['error' => 'Invalid credentials']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }

    return $response->withHeader('Content-Type', 'application/json');
});

// Protected routes

// Get all emails (Protected by JWT)
$app->get('/emails', function (Request $request, Response $response) use ($pdo) {
    // Get query parameters for pagination
    $page = $request->getQueryParams()['page'] ?? 1;
    $perPage = $request->getQueryParams()['per_page'] ?? 10;

    // Ensure that page and per_page are integers and valid
    $page = filter_var($page, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) ?: 1;
    $perPage = filter_var($perPage, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) ?: 10;

    // Calculate the offset
    $offset = ($page - 1) * $perPage;

    // Prepare and execute the SQL query with pagination
    $stmt = $pdo->prepare("SELECT * FROM successful_emails LIMIT :per_page OFFSET :offset");
    $stmt->bindValue(':per_page', (int)$perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get the total number of records for pagination info
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM successful_emails");
    $total = $countStmt->fetchColumn();

    // Build response with pagination info
    $responseData = [
        'current_page' => $page,
        'per_page' => $perPage,
        'total' => (int)$total,
        'total_pages' => ceil($total / $perPage),
        'data' => $emails
    ];

    $response->getBody()->write(json_encode($responseData));
    return $response->withHeader('Content-Type', 'application/json');
})->add($jwtMiddleware);

// Get a single email by ID (Protected by JWT)
$app->get('/emails/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $stmt = $pdo->prepare("SELECT * FROM successful_emails WHERE id = ? AND deleted_at = NULL");
    $stmt->execute([$id]);
    $email = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($email) {
        $response->getBody()->write(json_encode($email));
    } else {
        $response->getBody()->write(json_encode(['error' => 'Email not found']));
    }

    return $response->withHeader('Content-Type', 'application/json');
})->add($jwtMiddleware);

// Create a new email record (Protected by JWT)
$app->post('/emails', function (Request $request, Response $response) use ($pdo) {
    $data = $request->getParsedBody();

    $raw_text = "TODO";

    $stmt = $pdo->prepare("INSERT INTO successful_emails (email, raw_text, processed) VALUES (?, ?, true)");
    $stmt->execute([$data['email'], $raw_text]);

    $response->getBody()->write(json_encode(['status' => 'Email created']));
    return $response->withHeader('Content-Type', 'application/json');
})->add($jwtMiddleware);

// Update an existing email (Protected by JWT)
$app->put('/emails/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = $request->getParsedBody();

    $stmt = $pdo->prepare("UPDATE successful_emails SET email = ?, raw_text = ? WHERE id = ?");
    $stmt->execute([$data['email'], $data['raw_text'], $id]);

    $response->getBody()->write(json_encode(['status' => 'Email updated']));
    return $response->withHeader('Content-Type', 'application/json');
})->add($jwtMiddleware);

// Delete an email (Protected by JWT)
$app->delete('/emails/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];

    $stmt = $pdo->prepare("UPDATE successful_emails SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    $response->getBody()->write(json_encode(['status' => 'Email deleted']));
    return $response->withHeader('Content-Type', 'application/json');
})->add($jwtMiddleware);

$app->run();
