<?php

class VMFParser {
    private $config;
    private $tokenizer;
    private $currentToken;
    private $stream;
    private $lineNumber = 0;
    private $maxLines;

    public function __construct(array $config = []) {
        $this->config = array_merge([
            'max_nesting_depth' => 50000,
            'chunk_size' => 8192,
            'max_lines' => 1000000,
        ], $config);
    
        $this->tokenizer = new VMFTokenizer($this->config);
        $this->maxLines = $this->config['max_lines'];
    }

    public function parseVMF($filePath) {
        if (!file_exists($filePath)) {
            throw new VMFParserException("File not found: $filePath");
        }
    
        try {
            $this->stream = new SplFileObject($filePath, 'r');
            $this->currentToken = $this->tokenizer->getNextToken($this->stream, $this->lineNumber);
            return $this->parseDocument();
        } catch (Exception $e) {
            throw new VMFParserException("Error parsing VMF file: " . $e->getMessage() . " at line " . $this->lineNumber, 0, $e);
        }
    }

    private function parseDocument() {
        $document = [
            'versioninfo' => [],
            'visgroups' => [],
            'viewsettings' => [],
            'world' => [],
            'entities' => [],
            'hidden' => [],
            'cameras' => [],
            'cordon' => [],
            'cordons' => [],
            'custom_visgroups' => [],
            'instances' => [],
            'instance_parameters' => [],
            'palette_plus' => [],
            'colorcorrection_plus' => [],
            'light_plus' => [],
            'bgimages_plus' => [],
            'skybox_info' => [],
            'map_bounds' => [],
        ];
    
        while ($this->currentToken !== null) {
            $this->checkLineLimit();
            $this->skipWhitespace();
            
            if ($this->currentToken === null) {
                break;
            }
    
            if ($this->currentToken['type'] === 'IDENTIFIER') {
                $sectionName = $this->currentToken['value'];
                $section = $this->parseSection();
                
                if ($section !== null) {
                    switch ($sectionName) {
                        case 'versioninfo':
                        case 'viewsettings':
                        case 'cordon':
                        case 'cordons':
                        case 'palette_plus':
                        case 'colorcorrection_plus':
                        case 'light_plus':
                        case 'bgimages_plus':
                            $document[$sectionName] = $section['content'];
                            break;
                        case 'world':
                            $document[$sectionName] = $section['content'];
                            $document['skybox_info'] = $this->parseSkyboxInfo($section['content']);
                            $document['map_bounds'] = $this->parseMapBounds($section['content']);
                            break;
                        case 'visgroups':
                        case 'cameras':
                        case 'instances':
                            $document[$sectionName][] = $section['content'];
                            break;
                        case 'entity':
                            $document['entities'][] = $section['content'];
                            break;
                        case 'hidden':
                            $document['hidden'][] = $section['content'];
                            break;
                        case 'custom_visgroups':
                            $document['custom_visgroups'] = $section['content'];
                            break;
                        case 'instance_parameters':
                            $document['instance_parameters'] = array_merge(
                                $document['instance_parameters'],
                                $section['content']
                            );
                            break;
                        default:
                            $document[$sectionName] = $section['content'];
                    }
                }
            } else {
                $this->getNextToken();
            }
        }
    
        return $document;
    }

    private function parseSection() {
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
        $this->consume('BRACE_CLOSE');
    
        return ['name' => $sectionName, 'content' => $content];
    }

    private function parseBlock($depth = 0) {
        if ($depth > $this->config['max_nesting_depth']) {
            throw new VMFParserException("Maximum nesting depth exceeded at line {$this->lineNumber}");
        }

        $block = [];

        while ($this->currentToken !== null && $this->currentToken['type'] !== 'BRACE_CLOSE') {
            $this->checkLineLimit();
            $this->skipWhitespace();

            if ($this->currentToken['type'] === 'IDENTIFIER' || $this->currentToken['type'] === 'STRING') {
                $key = $this->currentToken['value'];
                $this->getNextToken();
                $this->skipWhitespace();

                if ($this->currentToken['type'] === 'BRACE_OPEN') {
                    $this->consume('BRACE_OPEN');
                    $value = $this->parseBlock($depth + 1);
                    $this->consume('BRACE_CLOSE');
                } else {
                    $value = $this->parseValue();
                }

                if (isset($block[$key]) && !is_array($block[$key])) {
                    $block[$key] = [$block[$key]];
                    $block[$key][] = $value;
                } elseif (isset($block[$key]) && is_array($block[$key])) {
                    $block[$key][] = $value;
                } else {
                    $block[$key] = $value;
                }
            } else {
                $this->getNextToken();
            }
        }

        return $block;
    }

    private function parseValue() {
        $this->skipWhitespace();
        
        if ($this->currentToken['type'] === 'STRING' || $this->currentToken['type'] === 'NUMBER' || $this->currentToken['type'] === 'IDENTIFIER') {
            $value = $this->currentToken['value'];
            $this->getNextToken();
            return $this->parseValueType($value);
        } else {
            $this->getNextToken();
            return null;
        }
    }

    private function parseSkyboxInfo($world) {
        $skyboxInfo = [
            'skyname' => $world['skyname'] ?? null,
            'sky_camera' => null
        ];

        if (isset($world['entities'])) {
            foreach ($world['entities'] as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'sky_camera') {
                    $skyboxInfo['sky_camera'] = $entity;
                    break;
                }
            }
        }

        return $skyboxInfo;
    }

    private function parseMapBounds($world) {
        $bounds = [
            'min' => [PHP_FLOAT_MAX, PHP_FLOAT_MAX, PHP_FLOAT_MAX],
            'max' => [PHP_FLOAT_MIN, PHP_FLOAT_MIN, PHP_FLOAT_MIN]
        ];

        $this->updateBoundsFromBrushes($world, $bounds);
        if (isset($world['entities'])) {
            foreach ($world['entities'] as $entity) {
                $this->updateBoundsFromBrushes($entity, $bounds);
            }
        }

        return $bounds;
    }

    private function updateBoundsFromBrushes($container, &$bounds) {
        if (isset($container['solid'])) {
            $solids = is_array($container['solid']) ? $container['solid'] : [$container['solid']];
            foreach ($solids as $solid) {
                if (isset($solid['vertices_plus'])) {
                    foreach ($solid['vertices_plus'] as $vertexSet) {
                        foreach ($vertexSet as $vertex) {
                            for ($i = 0; $i < 3; $i++) {
                                $bounds['min'][$i] = min($bounds['min'][$i], $vertex[$i]);
                                $bounds['max'][$i] = max($bounds['max'][$i], $vertex[$i]);
                            }
                        }
                    }
                }
            }
        }
    }

    private function parseValueType($value) {
        if (preg_match('/^-?\d+$/', $value)) {
            return intval($value);
        } elseif (preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return floatval($value);
        } elseif (in_array(strtolower($value), ['true', 'false'])) {
            return strtolower($value) === 'true';
        } elseif (preg_match('/^\((-?\d+(\.\d+)?)\s+(-?\d+(\.\d+)?)\s+(-?\d+(\.\d+)?)\)$/', $value, $matches)) {
            return [
                floatval($matches[1]),
                floatval($matches[3]),
                floatval($matches[5])
            ];
        } elseif (preg_match('/^(-?\d+(\.\d+)?)\s+(-?\d+(\.\d+)?)\s+(-?\d+(\.\d+)?)$/', $value)) {
            return array_map('floatval', explode(' ', $value));
        } elseif (preg_match('/^(\d+)\s+(\d+)\s+(\d+)$/', $value)) {
            return array_map('intval', explode(' ', $value)); // For RGB values
        } else {
            return $value;
        }
    }

    private function consume($expectedType) {
        $this->skipWhitespace();
        if ($this->currentToken === null || $this->currentToken['type'] !== $expectedType) {
            $foundType = $this->currentToken ? $this->currentToken['type'] : 'END_OF_FILE';
            throw new VMFParserException("Expected $expectedType, found $foundType at line {$this->lineNumber}");
        }
        $this->getNextToken();
    }

    private function skipWhitespace() {
        while ($this->currentToken !== null && 
                ($this->currentToken['type'] === 'WHITESPACE' || 
                $this->currentToken['type'] === 'NEWLINE' ||
                $this->currentToken['type'] === 'UNKNOWN')) {
            $this->getNextToken();
        }
    }

    private function getNextToken() {
        $this->currentToken = $this->tokenizer->getNextToken($this->stream, $this->lineNumber);
        return $this->currentToken;
    }

    private function checkLineLimit() {
        if ($this->lineNumber > $this->maxLines) {
            throw new VMFParserException("Exceeded maximum number of lines ({$this->maxLines})");
        }
    }
}

class VMFTokenizer {
    private $tokens = [
        '/^"((?:[^"\\\\]|\\\\.)*)"/' => 'STRING',
        '/^([-+]?[0-9]*\.?[0-9]+)/' => 'NUMBER',
        '/^([a-zA-Z_]\w*)/' => 'IDENTIFIER',
        '/^\{/' => 'BRACE_OPEN',
        '/^\}/' => 'BRACE_CLOSE',
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

                    return ['type' => $type, 'value' => $value];
                }
            }

            if (!empty($this->buffer)) {
                $char = $this->buffer[0];
                $this->buffer = substr($this->buffer, 1);
                if ($char === "\n") {
                    $lineNumber++;
                }
                return ['type' => 'UNKNOWN', 'value' => $char];
            } else {
                return null;
            }
        }
    }
}

class VMFParserException extends Exception {}