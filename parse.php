<?php

// Database connection
$host = "localhost";
$user = "root";
$password = "";
$database = "emails";

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Batch size
$batchSize = 50;  // Number of records to process per batch

function clean_html($html) {
    // Load HTML into a DOMDocument
    $dom = new DOMDocument;
    libxml_use_internal_errors(true); // Suppress errors due to malformed HTML
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    // Create a new DOMDocument to store cleaned HTML
    $cleanedDom = new DOMDocument;
    $cleanedDom->preserveWhiteSpace = false;
    
    // Import the content
    $cleanedDom->appendChild($cleanedDom->importNode($dom->documentElement, true));

    // Remove inline styles and attributes
    $xpath = new DOMXPath($cleanedDom);
    $nodes = $xpath->query('//@style | //@class | //@id | //@url-id');
    foreach ($nodes as $node) {
        $node->parentNode->removeAttribute($node->nodeName);
    }

    // Remove unwanted tags
    $tagsToRemove = ['script', 'style'];
    foreach ($tagsToRemove as $tag) {
        $nodes = $xpath->query("//{$tag}");
        foreach ($nodes as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    // Ensure all tags are properly closed
    $htmlCleaned = $cleanedDom->saveHTML();
    
    // Decode HTML entities
    $htmlCleaned = html_entity_decode($htmlCleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $pattern = '/=([A-Fa-f0-9]{2})/';
    
    // Remove matches
    $cleanedText = preg_replace($pattern, '', $htmlCleaned);
    $cleanedText = strip_tags($cleanedText);
    // Remove encoded sequences of the form =&#<number>;
    $cleanedText = preg_replace('/=\&#\d+;/', '', $cleanedText);

    return $cleanedText;
}

function extractPlainText($rawEmailContent) {
    // Locate the boundary from the Content-Type header
    preg_match('/boundary="([^"]+)"/', $rawEmailContent, $matches);
    $boundary = $matches[1] ?? null;
    
    if (!$boundary) {
        return null; // No boundary found, likely not a multipart message
    }

    // Split the email into parts based on the boundary
    $parts = explode('--' . $boundary, $rawEmailContent);

    foreach ($parts as $part) {
        // Check for the plain text part
        if (strpos($part, 'Content-Type: text/plain') !== false) {
            // Extract the plain text content, removing headers and boundaries
            $plainTextStart = strpos($part, "\r\n\r\n") + 4;
            $plainText = trim(substr($part, $plainTextStart));

            return $plainText;
        }
    }

    return null; // No plain text part found
}

// Start from the first batch
$offset = 0;

// Fetch a batch of unprocessed records
//$query = "SELECT id, email FROM successful_emails WHERE raw_text IS NULL LIMIT $batchSize OFFSET $offset";
//$result = $conn->query($query);

do {
    // Fetch a batch of unprocessed records
    $query = "SELECT id, email FROM successful_emails WHERE `processed` = FALSE LIMIT $batchSize OFFSET $offset";
    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        // Loop through each record in the batch
        while ($row = $result->fetch_assoc()) {
            $id = $row['id'];
            $rawEmailContent = $row['email'];

            // Extract plain text content
            $plainTextContent = clean_html($rawEmailContent);

            if ($plainTextContent !== null) {
                // Update the record with the extracted plain text
                $updateQuery = "UPDATE successful_emails SET raw_text = ?, processed = TRUE WHERE id = ?";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param("si", $plainTextContent, $id);

                if ($stmt->execute()) {
                    echo "Record with ID $id successfully processed.\n";
                } else {
                    echo "Error updating record with ID $id: " . $stmt->error . "\n";
                }

                $stmt->close();
            }
        }

        // Increment the offset for the next batch
        $offset += $batchSize;
    }

} while ($result->num_rows > 0);  // Continue until no more records are found

// Close connection
$conn->close();

?>