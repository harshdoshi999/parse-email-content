<?php

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
    
    // Remove matches
    $pattern = '/=([A-Fa-f0-9]{2})/';
    $cleanedText = preg_replace($pattern, '', $htmlCleaned);

    $cleanedText = strip_tags($cleanedText);
    
    // Remove encoded sequences of the form =&#<number>;
    $cleanedText = preg_replace('/=\&#\d+;/', '', $cleanedText);

    return $cleanedText;
}

?>