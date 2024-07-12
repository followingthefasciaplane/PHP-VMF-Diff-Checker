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
    
        $actualLineCount = count(file($filePath));
        error_log("Actual file line count: $actualLineCount");
    
        try {
            $this->stream = new SplFileObject($filePath, 'r');
            $this->currentToken = $this->tokenizer->getNextToken($this->stream, $this->lineNumber);
            $documents = [];
            while ($this->currentToken !== null) {
                $documents[] = $this->parseDocument();
            }
            
            error_log("Parsed line count: {$this->lineNumber}");
            if ($this->lineNumber != $actualLineCount) {
                error_log("Warning: Parsed line count does not match actual file line count");
            }
    
            return $documents;
        } catch (Exception $e) {
            $context = $this->getParsingContext();
            throw new VMFParserException("Error parsing VMF file: " . $e->getMessage() . " at line " . $this->lineNumber . "\nContext:\n" . $context, 0, $e);
        }
    }

    private function getParsingContext() {
        $context = "";
        $this->stream->seek($this->lineNumber - 5);
        for ($i = 0; $i < 10; $i++) {
            if ($this->stream->eof()) break;
            $context .= $this->stream->current();
            $this->stream->next();
        }
        return $context;
    }

    private function parseDocument() {
        $documents = [];
        $currentDocument = $this->initializeDocument();
    
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
                    $this->processSectionInDocument($currentDocument, $sectionName, $section);
                }
            } else {
                $this->getNextToken();
            }
    
            // Check if we've reached the end of a complete VMF structure
            if ($this->isEndOfVMFStructure()) {
                $documents[] = $currentDocument;
                $currentDocument = $this->initializeDocument();
            }
        }
    
        // Add the last document if it's not empty
        if (!empty($currentDocument['versioninfo']) || !empty($currentDocument['world']) || !empty($currentDocument['entities'])) {
            $documents[] = $currentDocument;
        }
    
        return $documents;
    }
    
    private function initializeDocument() {
        return [
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
    }
    
    private function isEndOfVMFStructure() {
        // Check if the next token is a top-level identifier
        return $this->currentToken !== null && 
            $this->currentToken['type'] === 'IDENTIFIER' && 
            in_array($this->currentToken['value'], ['versioninfo', 'visgroups', 'viewsettings', 'world']);
    }
    
    private function processSectionInDocument(&$document, $sectionName, $section) {
        switch ($sectionName) {
            case 'versioninfo':
                $document[$sectionName] = $this->parseVersionInfo($section['content']);
                break;
            case 'viewsettings':
                $document[$sectionName] = $this->parseViewSettings($section['content']);
                break;
            case 'world':
                $document[$sectionName] = $this->parseWorld($section['content']);
                $document['skybox_info'] = $this->parseSkyboxInfo($section['content']);
                $document['map_bounds'] = $this->parseMapBounds($section['content']);
                break;
            case 'entity':
                $document['entities'][] = $this->parseEntity($section['content']);
                break;
            case 'visgroups':
            case 'cameras':
            case 'instances':
                $document[$sectionName][] = $section['content'];
                break;
            case 'hidden':
                $document['hidden'][] = $section['content'];
                break;
            case 'cordon':
            case 'cordons':
                $document[$sectionName] = $this->parseCordon($section['content']);
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
            case 'palette_plus':
                $document[$sectionName] = $this->parsePalettePlus($section['content']);
                break;
            case 'colorcorrection_plus':
                $document[$sectionName] = $this->parseColorCorrectionPlus($section['content']);
                break;
            case 'light_plus':
                $document[$sectionName] = $this->parseLightPlus($section['content']);
                break;
            case 'bgimages_plus':
                $document[$sectionName] = $section['content'];
                break;
            default:
                $document[$sectionName] = $section['content'];
        }
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
        
        if ($this->currentToken === null || $this->currentToken['type'] !== 'BRACE_OPEN') {
            $value = $this->parseValue();
            return ['name' => $sectionName, 'content' => $value];
        }
    
        $this->consume('BRACE_OPEN');
        $content = $this->parseBlock();
        
        // If we've reached the end of the file, don't try to consume a closing brace
        if ($this->currentToken !== null && $this->currentToken['type'] === 'BRACE_CLOSE') {
            $this->consume('BRACE_CLOSE');
        } else if ($this->stream->eof()) {
            error_log("Warning: Unexpected end of file while parsing section '{$sectionName}'");
        }
    
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

            if ($this->currentToken === null) {
                throw new VMFParserException("Unexpected end of file while parsing block at line {$this->lineNumber}");
            }

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

        if ($this->currentToken === null) {
            throw new VMFParserException("Unexpected end of file while parsing block at line {$this->lineNumber}");
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
        } elseif (preg_match('/^\[(-?\d+(\.\d+)?)\s+(-?\d+(\.\d+)?)\s+(-?\d+(\.\d+)?)\s+(-?\d+(\.\d+)?)\]\s+(-?\d+(\.\d+)?)$/', $value, $matches)) {
            // For uaxis and vaxis
            return [
                'axis' => [floatval($matches[1]), floatval($matches[3]), floatval($matches[5])],
                'offset' => floatval($matches[7]),
                'scale' => floatval($matches[9])
            ];
        } else {
            return $value;
        }
    }

    private function parseVersionInfo($content) {
        return [
            'editorversion' => $content['editorversion'] ?? null,
            'editorbuild' => $content['editorbuild'] ?? null,
            'mapversion' => $content['mapversion'] ?? null,
            'formatversion' => $content['formatversion'] ?? null,
            'prefab' => $content['prefab'] ?? null,
        ];
    }

    private function parseViewSettings($content) {
        return [
            'bSnapToGrid' => $content['bSnapToGrid'] ?? null,
            'bShowGrid' => $content['bShowGrid'] ?? null,
            'bShowLogicalGrid' => $content['bShowLogicalGrid'] ?? null,
            'nGridSpacing' => $content['nGridSpacing'] ?? null,
        ];
    }

    private function parseWorld($content) {
        $world = [
            'id' => $content['id'] ?? null,
            'mapversion' => $content['mapversion'] ?? null,
            'classname' => $content['classname'] ?? null,
            'detailmaterial' => $content['detailmaterial'] ?? null,
            'detailvbsp' => $content['detailvbsp'] ?? null,
            'maxpropscreenwidth' => $content['maxpropscreenwidth'] ?? null,
            'skyname' => $content['skyname'] ?? null,
        ];

        if (isset($content['solid'])) {
            $world['solid'] = $this->parseSolids($content['solid']);
        }

        return $world;
    }

    private function parseSolids($solids) {
        if (!is_array($solids)) {
            $solids = [$solids];
        }

        return array_map(function($solid) {
            $parsedSolid = [
                'id' => $solid['id'] ?? null,
            ];

            if (isset($solid['side'])) {
                $parsedSolid['side'] = $this->parseSides($solid['side']);
            }

            if (isset($solid['editor'])) {
                $parsedSolid['editor'] = $this->parseEditor($solid['editor']);
            }

            return $parsedSolid;
        }, $solids);
    }

    private function parseSides($sides) {
        if (!is_array($sides)) {
            $sides = [$sides];
        }

        return array_map(function($side) {
            $parsedSide = [
                'id' => $side['id'] ?? null,
                'plane' => $side['plane'] ?? null,
                'material' => $side['material'] ?? null,
                'uaxis' => $side['uaxis'] ?? null,
                'vaxis' => $side['vaxis'] ?? null,
                'rotation' => $side['rotation'] ?? null,
                'lightmapscale' => $side['lightmapscale'] ?? null,
                'smoothing_groups' => $side['smoothing_groups'] ?? null,
            ];

            if (isset($side['vertices_plus'])) {
                $parsedSide['vertices_plus'] = $this->parseVerticesPlus($side['vertices_plus']);
            }

            if (isset($side['dispinfo'])) {
                $parsedSide['dispinfo'] = $this->parseDispInfo($side['dispinfo']);
            }

            return $parsedSide;
        }, $sides);
    }

    private function parseVerticesPlus($verticesPlus) {
        return array_map(function($v) {
            return array_map('floatval', explode(' ', $v));
        }, $verticesPlus);
    }

    private function parseDispInfo($dispinfo) {
        $parsedDispInfo = [
            'power' => $dispinfo['power'] ?? null,
            'startposition' => $dispinfo['startposition'] ?? null,
            'flags' => $dispinfo['flags'] ?? null,
            'elevation' => $dispinfo['elevation'] ?? null,
            'subdiv' => $dispinfo['subdiv'] ?? null,
        ];

        $nestedFields = ['normals', 'distances', 'offsets', 'offset_normals', 'alphas', 'triangle_tags'];
        foreach ($nestedFields as $field) {
            if (isset($dispinfo[$field])) {
                $parsedDispInfo[$field] = $this->parseDispInfoField($dispinfo[$field]);
            }
        }

        if (isset($dispinfo['allowed_verts'])) {
            $parsedDispInfo['allowed_verts'] = $dispinfo['allowed_verts'];
        }

        return $parsedDispInfo;
    }

    private function parseDispInfoField($field) {
        $parsed = [];
        foreach ($field as $key => $value) {
            $parsed[$key] = explode(' ', $value);
        }
        return $parsed;
    }

    private function parseEntity($content) {
        $entity = [
            'id' => $content['id'] ?? null,
            'classname' => $content['classname'] ?? null,
        ];

        $optionalFields = ['origin', 'spawnflags', 'StartDisabled'];
        foreach ($optionalFields as $field) {
            if (isset($content[$field])) {
                $entity[$field] = $content[$field];
            }
        }

        if (isset($content['solid'])) {
            $entity['solid'] = $this->parseSolids($content['solid']);
        }

        if (isset($content['editor'])) {
            $entity['editor'] = $this->parseEditor($content['editor']);
        }

        return $entity;
    }

    private function parseEditor($editor) {
        return [
            'color' => $editor['color'] ?? null,
            'visgroupshown' => $editor['visgroupshown'] ?? null,
            'visgroupautoshown' => $editor['visgroupautoshown'] ?? null,
            'logicalpos' => $editor['logicalpos'] ?? null,
        ];
    }

    private function parseSkyboxInfo($world) {
        $skyboxInfo = [
            'skyname' => $world['skyname'] ?? null,
            'sky_camera' => null
        ];

        if (isset($world['entity'])) {
            $entities = is_array($world['entity']) ? $world['entity'] : [$world['entity']];
            foreach ($entities as $entity) {
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

        $this->updateBoundsFromSolids($world['solid'] ?? [], $bounds);

        if (isset($world['entity'])) {
            $entities = is_array($world['entity']) ? $world['entity'] : [$world['entity']];
            foreach ($entities as $entity) {
                $this->updateBoundsFromSolids($entity['solid'] ?? [], $bounds);
            }
        }

        return $bounds;
    }

    private function updateBoundsFromSolids($solids, &$bounds) {
        if (!is_array($solids)) {
            $solids = [$solids];
        }

        foreach ($solids as $solid) {
            if (isset($solid['side'])) {
                $sides = is_array($solid['side']) ? $solid['side'] : [$solid['side']];
                foreach ($sides as $side) {
                    if (isset($side['vertices_plus'])) {
                        foreach ($side['vertices_plus'] as $vertex) {
                            $coords = explode(' ', $vertex);
                            for ($i = 0; $i < 3; $i++) {
                                $bounds['min'][$i] = min($bounds['min'][$i], $coords[$i]);
                                $bounds['max'][$i] = max($bounds['max'][$i], $coords[$i]);
                            }
                        }
                    }
                }
            }
        }
    }

    private function parseCordon($content) {
        return [
            'mins' => $content['mins'] ?? null,
            'maxs' => $content['maxs'] ?? null,
            'active' => $content['active'] ?? null,
        ];
    }

    private function parsePalettePlus($content) {
        $palette = [];
        for ($i = 0; $i <= 15; $i++) {
            $key = "color$i";
            if (isset($content[$key])) {
                $palette[$key] = array_map('intval', explode(' ', $content[$key]));
            }
        }
        return $palette;
    }

    private function parseColorCorrectionPlus($content) {
        $colorCorrection = [];
        for ($i = 0; $i <= 3; $i++) {
            $nameKey = "name$i";
            $weightKey = "weight$i";
            if (isset($content[$nameKey]) && isset($content[$weightKey])) {
                $colorCorrection[] = [
                    'name' => $content[$nameKey],
                    'weight' => floatval($content[$weightKey]),
                ];
            }
        }
        return $colorCorrection;
    }

    private function parseLightPlus($content) {
        return [
            'samples_sun' => $content['samples_sun'] ?? null,
            'samples_ambient' => $content['samples_ambient'] ?? null,
            'samples_vis' => $content['samples_vis'] ?? null,
            'texlight' => $content['texlight'] ?? null,
            'incremental_delay' => $content['incremental_delay'] ?? null,
            'bake_dist' => $content['bake_dist'] ?? null,
            'radius_scale' => $content['radius_scale'] ?? null,
            'brightness_scale' => $content['brightness_scale'] ?? null,
            'ao_scale' => $content['ao_scale'] ?? null,
            'bounced' => $content['bounced'] ?? null,
            'incremental' => $content['incremental'] ?? null,
            'supersample' => $content['supersample'] ?? null,
            'bleed_hack' => $content['bleed_hack'] ?? null,
            'soften_cosine' => $content['soften_cosine'] ?? null,
            'debug' => $content['debug'] ?? null,
            'cubemap' => $content['cubemap'] ?? null,
            'hdr' => $content['hdr'] ?? null,
        ];
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
                    error_log("Reached end of file at line $lineNumber");
                    return null;
                }
                $chunk = $stream->fread($this->config['chunk_size']);
                if ($chunk === false) {
                    error_log("Failed to read chunk at line $lineNumber");
                    return null;
                }
                $this->buffer .= $chunk;
                error_log("Refilled buffer at line $lineNumber. Buffer size: " . strlen($this->buffer));
            }

            foreach ($this->tokens as $pattern => $type) {
                if (preg_match($pattern, $this->buffer, $matches)) {
                    $value = isset($matches[1]) ? $matches[1] : $matches[0];
                    $this->buffer = substr($this->buffer, strlen($matches[0]));
                    
                    if ($type === 'NEWLINE') {
                        $lineNumber++;
                        error_log("Incremented line number to $lineNumber");
                    }
                    
                    if ($type === 'STRING') {
                        $value = stripcslashes($value);
                    }

                    error_log("Token found at line $lineNumber: Type=$type, Value=$value");
                    return ['type' => $type, 'value' => $value];
                }
            }

            if (!empty($this->buffer)) {
                $char = $this->buffer[0];
                $this->buffer = substr($this->buffer, 1);
                if ($char === "\n") {
                    $lineNumber++;
                    error_log("Incremented line number to $lineNumber (from buffer)");
                }
                error_log("Unknown character at line $lineNumber: " . ord($char));
                return ['type' => 'UNKNOWN', 'value' => $char];
            } else {
                error_log("Empty buffer at line $lineNumber");
                return null;
            }
        }
    }
}

class VMFParserException extends Exception {}

?>