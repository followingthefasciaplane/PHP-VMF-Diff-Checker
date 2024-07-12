<?php

class VMFComparator {
    private $ignoreOptions;
    private $parser;

    public function __construct(VMFParser $parser) {
        $this->parser = $parser;
    }

    public function compareVMF($vmf1Path, $vmf2Path, $ignoreOptions = []) {
        $this->ignoreOptions = $ignoreOptions;

        try {
            $vmf1 = $this->parser->parseVMF($vmf1Path);
            $vmf2 = $this->parser->parseVMF($vmf2Path);
        } catch (VMFParserException $e) {
            throw new VMFComparatorException("Error parsing VMF files: " . $e->getMessage());
        }

        $differences = $this->findDifferences($vmf1, $vmf2);
        $stats = $this->gatherStats($vmf1, $vmf2);

        return ['differences' => $differences, 'stats' => $stats];
    }

    private function findDifferences($vmf1, $vmf2) {
        $differences = [
            'removed' => [],
            'added' => [],
            'changed' => [],
            'vertex_changed' => []
        ];

        $this->compareSections($vmf1, $vmf2, '', $differences);

        return $differences;
    }

    private function compareSections($tree1, $tree2, $path, &$differences) {
        foreach ($tree1 as $key => $value1) {
            $newPath = $path ? "$path.$key" : $key;
            if ($this->shouldIgnore($newPath)) continue;

            if (!isset($tree2[$key])) {
                $differences['removed'][] = ['path' => $newPath, 'value' => $value1];
            } elseif (is_array($value1) && is_array($tree2[$key])) {
                $this->compareSections($value1, $tree2[$key], $newPath, $differences);
            } elseif ($value1 !== $tree2[$key]) {
                $differences['changed'][] = [
                    'path' => $newPath,
                    'old_value' => $value1,
                    'new_value' => $tree2[$key]
                ];
            }
        }

        foreach ($tree2 as $key => $value2) {
            $newPath = $path ? "$path.$key" : $key;
            if ($this->shouldIgnore($newPath)) continue;

            if (!isset($tree1[$key])) {
                $differences['added'][] = ['path' => $newPath, 'value' => $value2];
            }
        }
    }

    private function gatherStats($vmf1, $vmf2) {
        return [
            'entity_counts' => [
                'vmf1' => $this->countEntities($vmf1),
                'vmf2' => $this->countEntities($vmf2)
            ],
            'brush_counts' => [
                'vmf1' => $this->countBrushes($vmf1),
                'vmf2' => $this->countBrushes($vmf2)
            ],
            'texture_counts' => [
                'vmf1' => $this->countTextures($vmf1),
                'vmf2' => $this->countTextures($vmf2)
            ],
            'displacement_counts' => [
                'vmf1' => $this->countDisplacements($vmf1),
                'vmf2' => $this->countDisplacements($vmf2)
            ],
            'skybox_info' => [
                'vmf1' => $this->getSkyboxInfo($vmf1),
                'vmf2' => $this->getSkyboxInfo($vmf2)
            ],
            'map_bounds' => [
                'vmf1' => $vmf1['map_bounds'] ?? [],
                'vmf2' => $vmf2['map_bounds'] ?? []
            ]
        ];
    }

    private function countEntities($vmf) {
        $counts = [];
        foreach ($vmf['entities'] as $entity) {
            $classname = $entity['classname'] ?? 'unknown';
            $counts[$classname] = ($counts[$classname] ?? 0) + 1;
        }
        return $counts;
    }

    private function countBrushes($vmf) {
        $count = count($vmf['world']['solid'] ?? []);
        foreach ($vmf['entities'] as $entity) {
            $count += count($entity['solid'] ?? []);
        }
        return $count;
    }

    private function countTextures($vmf) {
        $textures = [];
        $this->countTexturesInSolids($vmf['world']['solid'] ?? [], $textures);
        foreach ($vmf['entities'] as $entity) {
            $this->countTexturesInSolids($entity['solid'] ?? [], $textures);
        }
        return $textures;
    }

    private function countTexturesInSolids($solids, &$textures) {
        foreach ($solids as $solid) {
            foreach ($solid['side'] ?? [] as $side) {
                $material = $side['material'] ?? 'unknown';
                $textures[$material] = ($textures[$material] ?? 0) + 1;
            }
        }
    }

    private function countDisplacements($vmf) {
        $count = 0;
        $count += $this->countDisplacementsInSolids($vmf['world']['solid'] ?? []);
        foreach ($vmf['entities'] as $entity) {
            $count += $this->countDisplacementsInSolids($entity['solid'] ?? []);
        }
        return $count;
    }

    private function countDisplacementsInSolids($solids) {
        $count = 0;
        foreach ($solids as $solid) {
            foreach ($solid['side'] ?? [] as $side) {
                if (isset($side['dispinfo'])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function getSkyboxInfo($vmf) {
        return [
            'skyname' => $vmf['world']['skyname'] ?? null,
            'sky_camera' => $this->findSkyCameraEntity($vmf['entities'])
        ];
    }

    private function findSkyCameraEntity($entities) {
        foreach ($entities as $entity) {
            if (($entity['classname'] ?? '') === 'sky_camera') {
                return $entity;
            }
        }
        return null;
    }

    private function shouldIgnore($path) {
        foreach ($this->ignoreOptions as $option) {
            if (fnmatch($option, $path)) {
                return true;
            }
        }
        return false;
    }

    public function generateReport($differences, $stats) {
        $report = "VMF Comparison Report\n\n";

        $report .= "Differences:\n";
        $report .= "  Removed: " . count($differences['removed']) . "\n";
        $report .= "  Added: " . count($differences['added']) . "\n";
        $report .= "  Changed: " . count($differences['changed']) . "\n";

        $report .= "\nStatistics:\n";
        $report .= $this->generateCountComparison("Brushes", $stats['brush_counts']);
        $report .= $this->generateCountComparison("Displacements", $stats['displacement_counts']);

        $report .= "\nEntity Counts:\n";
        $report .= $this->generateEntityCountComparison($stats['entity_counts']);

        $report .= "\nTexture Counts:\n";
        $report .= $this->generateTextureCountComparison($stats['texture_counts']);

        $report .= "\nSkybox Info:\n";
        $report .= $this->generateSkyboxInfoComparison($stats['skybox_info']);

        $report .= "\nMap Bounds:\n";
        $report .= $this->generateMapBoundsComparison($stats['map_bounds']);

        return $report;
    }

    private function generateCountComparison($label, $counts) {
        return sprintf("  %s: %d vs %d\n", $label, $counts['vmf1'], $counts['vmf2']);
    }

    private function generateEntityCountComparison($entityCounts) {
        $report = "";
        $allEntities = array_unique(array_merge(array_keys($entityCounts['vmf1']), array_keys($entityCounts['vmf2'])));
        sort($allEntities);

        foreach ($allEntities as $entity) {
            $count1 = $entityCounts['vmf1'][$entity] ?? 0;
            $count2 = $entityCounts['vmf2'][$entity] ?? 0;
            if ($count1 != $count2) {
                $report .= sprintf("  %s: %d vs %d\n", $entity, $count1, $count2);
            }
        }

        return $report;
    }

    private function generateTextureCountComparison($textureCounts) {
        $report = "";
        $allTextures = array_unique(array_merge(array_keys($textureCounts['vmf1']), array_keys($textureCounts['vmf2'])));
        sort($allTextures);

        foreach ($allTextures as $texture) {
            $count1 = $textureCounts['vmf1'][$texture] ?? 0;
            $count2 = $textureCounts['vmf2'][$texture] ?? 0;
            if ($count1 != $count2) {
                $report .= sprintf("  %s: %d vs %d\n", $texture, $count1, $count2);
            }
        }

        return $report;
    }

    private function generateSkyboxInfoComparison($skyboxInfo) {
        $report = sprintf("  Skyname: %s vs %s\n", 
            $skyboxInfo['vmf1']['skyname'] ?? 'N/A', 
            $skyboxInfo['vmf2']['skyname'] ?? 'N/A');

        if ($skyboxInfo['vmf1']['sky_camera'] || $skyboxInfo['vmf2']['sky_camera']) {
            $report .= "  Sky Camera:\n";
            $skyCam1 = $skyboxInfo['vmf1']['sky_camera'] ?? [];
            $skyCam2 = $skyboxInfo['vmf2']['sky_camera'] ?? [];
            
            $allKeys = array_unique(array_merge(array_keys($skyCam1), array_keys($skyCam2)));
            foreach ($allKeys as $key) {
                $value1 = $skyCam1[$key] ?? 'N/A';
                $value2 = $skyCam2[$key] ?? 'N/A';
                if ($value1 !== $value2) {
                    $report .= sprintf("    %s: %s vs %s\n", $key, $value1, $value2);
                }
            }
        }

        return $report;
    }

    private function generateMapBoundsComparison($mapBounds) {
        $report = sprintf("  Min: (%s) vs (%s)\n",
            implode(", ", $mapBounds['vmf1']['min'] ?? ['N/A', 'N/A', 'N/A']),
            implode(", ", $mapBounds['vmf2']['min'] ?? ['N/A', 'N/A', 'N/A']));
        $report .= sprintf("  Max: (%s) vs (%s)\n",
            implode(", ", $mapBounds['vmf1']['max'] ?? ['N/A', 'N/A', 'N/A']),
            implode(", ", $mapBounds['vmf2']['max'] ?? ['N/A', 'N/A', 'N/A']));
        return $report;
    }
}

class VMFComparatorException extends Exception {}

?>