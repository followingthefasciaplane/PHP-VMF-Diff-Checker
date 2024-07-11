<?php

class VMFParser {
    private $config;
    private $tokenizer;
    private $currentToken;
    private $lookahead;
    private $stream;
    private $lineNumber = 0;
    private $comments = [];

    public function __construct(array $config = []) {
        $this->config = array_merge([
            'max_nesting_depth' => 50000,
            'chunk_size' => 8192,
            'allowed_sections' => [
                'versioninfo', 'visgroups', 'viewsettings', 'world', 'entity', 'cameras', 'cordons',
                'palette_plus', 'colorcorrection_plus', 'light_plus', 'postprocess_plus', 'bgimages_plus'
            ],
            'preserve_comments' => false,
            'multiple_values_behavior' => 'array', // 'array', 'last', or 'first'
            'streaming' => false,
        ], $config);
    
        $this->tokenizer = new VMFTokenizer($this->config);
    }

    public function parseVMF($filePath) {
        if (!file_exists($filePath)) {
            throw new VMFParserException("File not found: $filePath");
        }
    
        try {
            $this->stream = new SplFileObject($filePath, 'r');
            $this->currentToken = $this->tokenizer->getNextToken($this->stream, $this->lineNumber);
            $this->lookahead = $this->tokenizer->getNextToken($this->stream, $this->lineNumber);
    
            if ($this->config['streaming']) {
                return $this->parseDocumentStreaming();
            } else {
                return $this->parseDocument();
            }
        } catch (Exception $e) {
            error_log("VMFParser error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            throw new VMFParserException("Error parsing VMF file: " . $e->getMessage(), 0, $e);
        }
    }

    private function parseDocument() {
        $document = [
            'tree' => [],
            'idMap' => [],
        ];
    
        if ($this->config['preserve_comments']) {
            $document['comments'] = [];
        }
    
        while ($this->currentToken !== null) {
            $this->skipWhitespace();
            
            if ($this->currentToken === null) {
                break;
            }
    
            if ($this->currentToken['type'] === 'IDENTIFIER') {
                $sectionName = $this->currentToken['value'];
                $section = $this->parseSection();
                
                if ($section !== null) {
                    if (in_array($sectionName, $this->config['allowed_sections'])) {
                        $document['tree'][$sectionName] = $section['content'];
                        if ($sectionName === 'entity' || $sectionName === 'world') {
                            $this->updateIdMap($section['content'], $document['idMap']);
                        }
                    } else {
                        $document['tree']['unknown_sections'][$sectionName] = $section['content'];
                    }
                }
            } elseif ($this->currentToken['type'] === 'COMMENT' && $this->config['preserve_comments']) {
                $this->comments[] = $this->currentToken['value'];
                $this->getNextToken();
            } else {
                $this->getNextToken();
            }
            
            if ($this->config['preserve_comments'] && !empty($this->comments)) {
                $document['comments'] = array_merge($document['comments'], $this->comments);
                $this->comments = [];
            }
        }
    
        return $document;
    }

    private function parseDocumentStreaming() {
        return new VMFStreamingParser($this);
    }

    public function parseSection() {
        if ($this->currentToken === null) {
            return null;
        }
    
        $this->skipWhitespace();
    
        if ($this->currentToken['type'] !== 'IDENTIFIER') {
            $this->getNextToken();
            return null;
        }
    
        $sectionName = $this->currentToken['value'];
        $this->consume('IDENTIFIER');
        
        $this->skipWhitespace();
        
        if ($this->currentToken['type'] !== 'BRACE_OPEN') {
            $value = $this->parseValue();
            return ['name' => $sectionName, 'content' => $value];
        }
    
        $this->consume('BRACE_OPEN');
    
        $content = $this->parseBlock();
    
        if ($this->currentToken['type'] !== 'BRACE_CLOSE') {
            while ($this->currentToken !== null && $this->currentToken['type'] !== 'BRACE_CLOSE') {
                $this->getNextToken();
            }
        }
        if ($this->currentToken !== null) {
            $this->consume('BRACE_CLOSE');
        }
    
        return ['name' => $sectionName, 'content' => $content];
    }

    private function parseBlock($depth = 0) {
        if ($depth > $this->config['max_nesting_depth']) {
            error_log("Maximum nesting depth exceeded at line {$this->lineNumber}");
            return [];
        }

        $block = [];

        while ($this->currentToken !== null && $this->currentToken['type'] !== 'BRACE_CLOSE') {
            $this->skipWhitespace();

            if ($this->currentToken['type'] === 'IDENTIFIER' || $this->currentToken['type'] === 'STRING') {
                $key = $this->currentToken['value'];
                $this->getNextToken();
                $this->skipWhitespace();

                if ($key === 'vertices_plus') {
                    $value = $this->parseVerticesPlusBlock();
                } elseif ($key === 'editor') {
                    $value = $this->parseEditorBlock();
                } elseif ($this->currentToken['type'] === 'BRACE_OPEN') {
                    $this->consume('BRACE_OPEN');
                    $value = $this->parseBlock($depth + 1);
                    $this->consume('BRACE_CLOSE');
                } else {
                    $value = $this->parseValue();
                }

                $this->addValueToBlock($block, $key, $value);
            } elseif ($this->currentToken['type'] === 'COMMENT' && $this->config['preserve_comments']) {
                $this->comments[] = $this->currentToken['value'];
                $this->getNextToken();
            } elseif ($this->currentToken['type'] === 'NEWLINE' || $this->currentToken['type'] === 'UNKNOWN') {
                // Skip newlines and unknown tokens
                $this->getNextToken();
            } else {
                // Log unexpected tokens and skip them
                error_log("Unexpected token '{$this->currentToken['type']}' at line {$this->lineNumber}");
                $this->getNextToken();
            }
        }

        return $block;
    }
    
    private function parseVerticesPlusBlock() {
        $vertices = [];
        $this->consume('BRACE_OPEN');
        
        while ($this->currentToken !== null && $this->currentToken['type'] !== 'BRACE_CLOSE') {
            if ($this->currentToken['type'] === 'STRING' && $this->currentToken['value'] === 'v') {
                $this->getNextToken();
                $this->skipWhitespace();
                if ($this->currentToken['type'] === 'STRING') {
                    $vertices[] = array_map('floatval', preg_split('/\s+/', trim($this->currentToken['value'])));
                    $this->getNextToken();
                }
            } else {
                $this->getNextToken();
            }
            $this->skipWhitespace();
        }
        
        $this->consume('BRACE_CLOSE');
        return $vertices;
    }

    private function parseEditorBlock() {
        $editor = [];
        $this->consume('BRACE_OPEN');
        
        while ($this->currentToken !== null && $this->currentToken['type'] !== 'BRACE_CLOSE') {
            if ($this->currentToken['type'] === 'STRING' || $this->currentToken['type'] === 'IDENTIFIER') {
                $key = $this->currentToken['value'];
                $this->getNextToken();
                $this->skipWhitespace();
                $value = $this->parseValue();
                $editor[$key] = $value;
            } else {
                $this->getNextToken();
            }
            $this->skipWhitespace();
        }
        
        $this->consume('BRACE_CLOSE');
        return $editor;
    }

    private function addValueToBlock(&$block, $key, $value) {
        if (isset($block[$key])) {
            switch ($this->config['multiple_values_behavior']) {
                case 'array':
                    if (!is_array($block[$key])) {
                        $block[$key] = [$block[$key]];
                    }
                    $block[$key][] = $value;
                    break;
                case 'last':
                    $block[$key] = $value;
                    break;
                case 'first':
                    // Do nothing, keep the first value
                    break;
            }
        } else {
            $block[$key] = $value;
        }
    }

    private function parseValue() {
        $this->skipWhitespace();
        
        if ($this->currentToken['type'] === 'STRING' || $this->currentToken['type'] === 'NUMBER' || $this->currentToken['type'] === 'IDENTIFIER') {
            $value = $this->currentToken['value'];
            $this->getNextToken();
            return $this->parseValueType($value);
        } else {
            error_log("Unexpected token in value: {$this->currentToken['type']} at line {$this->lineNumber}");
            $this->getNextToken();
            return null;
        }
    }

    private function parseValueType($value) {
        if (preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return floatval($value);
        } elseif (in_array(strtolower($value), ['true', 'false'])) {
            return strtolower($value) === 'true';
        } elseif (preg_match('/^(\d+)\s+(\d+)\s+(\d+)$/', $value)) {
            return array_map('intval', explode(' ', $value));
        } elseif (preg_match('/^(-?\d+(\.\d+)?)\s+(-?\d+(\.\d+)?)\s+(-?\d+(\.\d+)?)$/', $value)) {
            return array_map('floatval', explode(' ', $value));
        } else {
            return $value;
        }
    }

    private function updateIdMap($content, &$idMap) {
        if (isset($content['id'])) {
            $idMap[$content['id']] = &$content;
        }
        foreach ($content as &$value) {
            if (is_array($value)) {
                $this->updateIdMap($value, $idMap);
            }
        }
    }

    private function consume($expectedType) {
        $this->skipWhitespace();
        if ($this->currentToken['type'] !== $expectedType) {
            error_log("Expected $expectedType, found {$this->currentToken['type']} at line {$this->lineNumber}");
        }
        $this->getNextToken();
    }

    private function skipWhitespace() {
        while ($this->currentToken !== null && 
                ($this->currentToken['type'] === 'WHITESPACE' || 
                $this->currentToken['type'] === 'NEWLINE' ||
                $this->currentToken['type'] === 'UNKNOWN')) {
            if ($this->currentToken['type'] === 'NEWLINE') {
                $this->lineNumber++;
            } elseif ($this->currentToken['type'] === 'UNKNOWN') {
                error_log("Skipping unknown character at line {$this->lineNumber}");
            }
            $this->getNextToken();
        }
    }

    private function getNextToken() {
        $this->currentToken = $this->lookahead;
        $this->lookahead = $this->tokenizer->getNextToken($this->stream, $this->lineNumber);
        return $this->currentToken;
    }

    public function serializeVMF($parsedVMF) {
        $output = '';
        foreach ($parsedVMF['tree'] as $sectionName => $sectionContent) {
            $output .= $this->serializeSection($sectionName, $sectionContent);
        }
        return $output;
    }

    private function serializeSection($name, $content, $indentLevel = 0) {
        $indent = str_repeat("\t", $indentLevel);
        $output = "$indent$name\n$indent{\n";
        $output .= $this->serializeBlock($content, $indentLevel + 1);
        $output .= "$indent}\n";
        return $output;
    }

    private function serializeBlock($block, $indentLevel) {
        $output = '';
        $indent = str_repeat("\t", $indentLevel);
        foreach ($block as $key => $value) {
            if ($key === 'editor') {
                $output .= $this->serializeEditorBlock($value, $indentLevel);
            } elseif ($key === 'vertices_plus') {
                $output .= $this->serializeVerticesPlus($key, $value, $indent);
            } elseif (is_array($value) && !isset($value[0])) {
                $output .= $this->serializeSection($key, $value, $indentLevel);
            } elseif (is_array($value)) {
                foreach ($value as $subValue) {
                    $output .= $this->serializeKeyValue($key, $subValue, $indent);
                }
            } else {
                $output .= $this->serializeKeyValue($key, $value, $indent);
            }
        }
        return $output;
    }
    
    private function serializeEditorBlock($editor, $indentLevel) {
        $indent = str_repeat("\t", $indentLevel);
        $output = $indent . "editor\n$indent{\n";
        foreach ($editor as $key => $value) {
            $output .= $this->serializeKeyValue($key, $value, $indent . "\t");
        }
        $output .= "$indent}\n";
        return $output;
    }

    private function serializeVerticesPlus($key, $vertices, $indent) {
        $output = "$indent\"$key\"\n$indent{\n";
        foreach ($vertices as $vertex) {
            $output .= $indent . "\t\"v\" \"" . implode(' ', $vertex) . "\"\n";
        }
        $output .= "$indent}\n";
        return $output;
    }

    private function serializeKeyValue($key, $value, $indent) {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $value = implode(' ', $value);
        }
        return $indent . "\"$key\" \"$value\"\n";
    }
}

class VMFTokenizer {
    private $tokens = [
        '/^"((?:[^"\\\\]|\\\\.)*)"/' => 'STRING',
        '/^([-+]?[0-9]*\.?[0-9]+)/' => 'NUMBER',
        '/^([a-zA-Z_]\w*)/' => 'IDENTIFIER',
        '/^\{/' => 'BRACE_OPEN',
        '/^\}/' => 'BRACE_CLOSE',
        '/^\/\/(.*)/' => 'COMMENT',
        '/^\r\n|\n|\r/' => 'NEWLINE',
        '/^[ \t]+/' => 'WHITESPACE',
    ];
    private $buffer = '';
    private $config;

    public function __construct($config) {
        $this->config = $config;
    }

    public function getNextToken(SplFileObject $stream, &$lineNumber) {
        while (true) {
            if (empty($this->buffer)) {
                if ($stream->eof()) {
                    return null;
                }
                $chunk = $stream->fread($this->config['chunk_size']);
                if ($chunk === false) {
                    return null;
                }
                $this->buffer .= $chunk;
            }

            foreach ($this->tokens as $pattern => $type) {
                if (preg_match($pattern, $this->buffer, $matches)) {
                    $value = isset($matches[1]) ? $matches[1] : $matches[0];
                    $this->buffer = substr($this->buffer, strlen($matches[0]));
                    
                    if ($type === 'NEWLINE') {
                        $lineNumber++;
                    }
                    
                    if ($type === 'STRING') {
                        $value = stripcslashes($value);
                    }

                    if ($type === 'COMMENT' && !$this->config['preserve_comments']) {
                        continue;
                    }

                    return ['type' => $type, 'value' => $value];
                }
            }

            // If we can't match any token, move forward one character
            if (!empty($this->buffer)) {
                $char = $this->buffer[0];
                $this->buffer = substr($this->buffer, 1);
                if ($char === "\n") {
                    $lineNumber++;
                }
                // Return unexpected character as an 'UNKNOWN' token
                return ['type' => 'UNKNOWN', 'value' => $char];
            } else {
                // If the buffer is empty and we couldn't match anything, we're done
                return null;
            }
        }
    }
}

class VMFStreamingParser implements Iterator {
    private $parser;
    private $currentSection;
    private $valid = true;

    public function __construct(VMFParser $parser) {
        $this->parser = $parser;
    }

    public function current() {
        return $this->currentSection;
    }

    public function next(): void {
        $this->currentSection = $this->parser->parseSection();
        if ($this->currentSection === null) {
            $this->valid = false;
        }
    }

    public function key() {
        return $this->currentSection !== null ? $this->currentSection['name'] : null;
    }

    public function valid(): bool {
        return $this->valid;
    }

    public function rewind(): void {
        // Since we can't actually rewind the file stream, we'll just reset the state
        $this->valid = true;
        $this->next();
    }
}

class VMFParserException extends Exception {}