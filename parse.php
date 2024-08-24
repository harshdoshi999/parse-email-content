<?php

// Import functions.php 
require_once 'functions.php';

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

// Start from the first batch
$offset = 0;

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