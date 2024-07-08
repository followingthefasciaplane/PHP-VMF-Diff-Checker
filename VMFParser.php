<?php
class VMFParser {
    private $maxDepth = 1000; // Prevent excessive nesting

    public function parseVMF($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception("Failed to read file: $filePath");
        }

        return $this->parseVMFContent($content);
    }

    public function parseVMFContent($content) {
        $lines = explode("\n", $content);
        $root = [];
        $stack = [&$root];
        $current = &$root;
        $key = null;
        $currentId = null;
        $idMap = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '//') === 0) continue; // Skip empty lines and comments

            if ($line === '{') {
                if (count($stack) >= $this->maxDepth) {
                    throw new Exception("Maximum nesting depth exceeded at line " . ($lineNumber + 1));
                }
                $current = $this->handleOpenBrace($current, $key, $currentId, $stack, $idMap);
                $key = null;
                $currentId = null;
            } elseif ($line === '}') {
                if (count($stack) <= 1) {
                    throw new Exception("Unmatched closing brace at line " . ($lineNumber + 1));
                }
                array_pop($stack);
                $current = &$stack[count($stack) - 1];
                $key = null;
            } elseif (preg_match('/^"([^"]+)"\s+"([^"]+)"$/', $line, $matches)) {
                $this->handleKeyValuePair($current, $matches[1], $matches[2], $currentId);
            } elseif (preg_match('/^"([^"]+)"$/', $line, $matches)) {
                $key = $matches[1];
            } elseif ($line === 'vertices_plus') {
                $this->handleVerticesPlus($current, $lines);
            } else {
                // Handle single words (like "solid" or "side")
                $current[] = $line;
            }
        }

        if (count($stack) > 1) {
            throw new Exception("Unclosed braces at end of file");
        }

        return [
            'tree' => $root,
            'idMap' => $idMap,
            'palette_plus' => $this->parsePalettePlus($content),
            'colorcorrection_plus' => $this->parseColorCorrectionPlus($content),
            'light_plus' => $this->parseLightPlus($content),
            'bgimages_plus' => $this->parseBgImagesPlus($content),
            'cameras' => $this->parseCameras($content),
            'cordons' => $this->parseCordons($content)
        ];
    }

    private function handleOpenBrace(&$current, $key, $currentId, &$stack, &$idMap) {
        $new = ['__id' => $currentId];
        if ($key !== null) {
            if (!isset($current[$key])) {
                $current[$key] = $new;
            } elseif (!is_array($current[$key])) {
                $current[$key] = [$current[$key], $new];
            } else {
                $current[$key][] = $new;
            }
            array_push($stack, $current);
            $current = &$current[$key];
            if (is_array($current) && !isset($current[0])) {
                $current = &$current[count($current) - 1];
            }
        } else {
            $current[] = $new;
            array_push($stack, $current);
            $current = &$current[count($current) - 1];
        }
        if ($currentId !== null) {
            $idMap[$currentId] = &$current;
        }
        return $current;
    }

    private function handleKeyValuePair(&$current, $k, $v, &$currentId) {
        if ($k === 'id') {
            $currentId = $v;
            $current['__id'] = $v;
        }
        if (in_array($k, ['plane', 'uaxis', 'vaxis', 'origin', 'angles'])) {
            // Special handling for vector-like values
            $v = explode(' ', $v);
        } elseif (is_numeric($v)) {
            // Handle potential numeric values
            $v = floatval($v);
        }
        $current[$k] = $v;
    }

    private function handleVerticesPlus(&$current, &$lines) {
        $current['vertices_plus'] = [];
        $vertex = [];
        while (($nextLine = trim(next($lines))) !== '}') {
            if (preg_match('/^"v"\s+"([-\d\.\s]+)"$/', $nextLine, $matches)) {
                $vertex[] = array_map('floatval', explode(' ', $matches[1]));
            } elseif ($nextLine === '}') {
                if (!empty($vertex)) {
                    $current['vertices_plus'][] = $vertex;
                    $vertex = [];
                }
            }
        }
        if (!empty($vertex)) {
            $current['vertices_plus'][] = $vertex;
        }
    }

    public function parsePalettePlus($content) {
        preg_match('/palette_plus\s*\{([^}]+)\}/s', $content, $matches);
        if (empty($matches)) return [];

        $palette = [];
        $lines = explode("\n", trim($matches[1]));
        foreach ($lines as $line) {
            if (preg_match('/^"color(\d+)"\s+"(\d+\s+\d+\s+\d+)"$/', $line, $colorMatch)) {
                $colorKey = 'color' . $colorMatch[1];
                $colorValue = array_map('intval', explode(' ', $colorMatch[2]));
                if (!isset($palette[$colorKey])) {
                    $palette[$colorKey] = $colorValue;
                } elseif (!is_array($palette[$colorKey][0])) {
                    $palette[$colorKey] = [$palette[$colorKey], $colorValue];
                } else {
                    $palette[$colorKey][] = $colorValue;
                }
            }
        }
        return $palette;
    }

    public function parseColorCorrectionPlus($content) {
        preg_match('/colorcorrection_plus\s*\{([^}]+)\}/s', $content, $matches);
        if (empty($matches)) return [];

        $colorcorrection = [];
        $lines = explode("\n", trim($matches[1]));
        foreach ($lines as $line) {
            if (preg_match('/^"([^"]+)"\s+"([^"]*)"$/', $line, $match)) {
                $key = $match[1];
                $value = $match[2];
                if (!isset($colorcorrection[$key])) {
                    $colorcorrection[$key] = $value;
                } elseif (!is_array($colorcorrection[$key])) {
                    $colorcorrection[$key] = [$colorcorrection[$key], $value];
                } else {
                    $colorcorrection[$key][] = $value;
                }
            }
        }
        return $colorcorrection;
    }

    public function parseLightPlus($content) {
        preg_match('/light_plus\s*\{([^}]+)\}/s', $content, $matches);
        if (empty($matches)) return [];

        $light = [];
        $lines = explode("\n", trim($matches[1]));
        foreach ($lines as $line) {
            if (preg_match('/^"([^"]+)"\s+"([^"]*)"$/', $line, $match)) {
                $key = $match[1];
                $value = is_numeric($match[2]) ? floatval($match[2]) : $match[2];
                if (!isset($light[$key])) {
                    $light[$key] = $value;
                } elseif (!is_array($light[$key])) {
                    $light[$key] = [$light[$key], $value];
                } else {
                    $light[$key][] = $value;
                }
            }
        }
        return $light;
    }

    public function parseBgImagesPlus($content) {
        preg_match('/bgimages_plus\s*\{([^}]*)\}/s', $content, $matches);
        if (empty($matches)) return [];

        $bgimages = [];
        if (trim($matches[1]) !== '') {
            $lines = explode("\n", trim($matches[1]));
            foreach ($lines as $line) {
                if (preg_match('/^"([^"]+)"\s+"([^"]*)"$/', $line, $match)) {
                    $key = $match[1];
                    $value = $match[2];
                    if (!isset($bgimages[$key])) {
                        $bgimages[$key] = $value;
                    } elseif (!is_array($bgimages[$key])) {
                        $bgimages[$key] = [$bgimages[$key], $value];
                    } else {
                        $bgimages[$key][] = $value;
                    }
                }
            }
        }
        return $bgimages;
    }

    public function parseCameras($content) {
        preg_match('/cameras\s*\{([^}]+)\}/s', $content, $matches);
        if (empty($matches)) return [];

        $cameras = [];
        $lines = explode("\n", trim($matches[1]));
        $currentCamera = null;
        foreach ($lines as $line) {
            if (preg_match('/^"([^"]+)"\s+"([^"]*)"$/', $line, $match)) {
                $key = $match[1];
                $value = $match[2];
                if ($key !== 'camera') {
                    if (!isset($cameras[$key])) {
                        $cameras[$key] = $value;
                    } elseif (!is_array($cameras[$key])) {
                        $cameras[$key] = [$cameras[$key], $value];
                    } else {
                        $cameras[$key][] = $value;
                    }
                }
            } elseif (trim($line) === 'camera') {
                $currentCamera = [];
            } elseif ($currentCamera !== null && preg_match('/^"([^"]+)"\s+"([^"]*)"$/', $line, $match)) {
                $currentCamera[$match[1]] = $match[2];
            } elseif (trim($line) === '}' && $currentCamera !== null) {
                $cameras['cameras'][] = $currentCamera;
                $currentCamera = null;
            }
        }
        return $cameras;
    }

    public function parseCordons($content) {
        preg_match('/cordons\s*\{([^}]+)\}/s', $content, $matches);
        if (empty($matches)) return [];

        $cordons = [];
        $lines = explode("\n", trim($matches[1]));
        foreach ($lines as $line) {
            if (preg_match('/^"([^"]+)"\s+"([^"]*)"$/', $line, $match)) {
                $key = $match[1];
                $value = $match[2];
                if (!isset($cordons[$key])) {
                    $cordons[$key] = $value;
                } elseif (!is_array($cordons[$key])) {
                    $cordons[$key] = [$cordons[$key], $value];
                } else {
                    $cordons[$key][] = $value;
                }
            }
        }
        return $cordons;
    }

    public function reset() {
        // No action needed as the parser doesn't maintain state between operations
        error_log("VMFParser reset called at " . date('Y-m-d H:i:s') . " (No action needed)");
    }

    public function formatVerticesPlus($verticesPlus) {
        $output = "vertices_plus\n{\n";
        foreach ($verticesPlus as $vertexSet) {
            foreach ($vertexSet as $vertex) {
                $output .= '    "v" "' . implode(' ', $vertex) . "\"\n";
            }
        }
        $output .= "}\n";
        return $output;
    }
}
