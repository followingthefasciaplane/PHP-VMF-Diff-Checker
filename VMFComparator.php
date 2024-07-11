<?php
class VMFComparator {
    private $ignoreOptions;
    private $parser;

    public function __construct() {
        $this->parser = new VMFParser();
    }

    public function compareVMF($vmf1, $vmf2, $ignoreOptions) {
        $this->ignoreOptions = $ignoreOptions;

        if (!isset($vmf1['tree']) || !isset($vmf2['tree']) || !is_array($vmf1['tree']) || !is_array($vmf2['tree'])) {
            throw new Exception("Invalid VMF structure");
        }

        $differences = [
            'removed' => [],
            'added' => [],
            'changed' => [],
            'vertex_changed' => [],
            'comments' => []
        ];
        $stats = $this->initializeStats();

        $this->gatherStats($vmf1['tree'], $stats, 'vmf1');
        $this->gatherStats($vmf2['tree'], $stats, 'vmf2');

        $this->compareElements($vmf1['tree'], $vmf2['tree'], '', $differences, $stats);

        if ($stats['vertex_changes'] > 0) {
            $stats['average_vertex_deviation'] = $stats['total_vertex_deviation'] / $stats['vertex_changes'];
        }

        // Compare additional sections
        $additionalSections = [
            'palette_plus' => 'comparePalettePlus',
            'colorcorrection_plus' => 'compareColorCorrectionPlus',
            'light_plus' => 'compareLightPlus',
            'bgimages_plus' => 'compareBgImagesPlus',
            'cameras' => 'compareCameras',
            'cordons' => 'compareCordons'
        ];

        foreach ($additionalSections as $section => $compareMethod) {
            $stats[$section . '_differences'] = $this->$compareMethod(
                $vmf1['tree'][$section] ?? [],
                $vmf2['tree'][$section] ?? []
            );
        }

        // Compare comments if they are preserved
        if (isset($vmf1['comments']) && isset($vmf2['comments'])) {
            $differences['comments'] = $this->compareComments($vmf1['comments'], $vmf2['comments']);
        }

        return ['differences' => $differences, 'stats' => $stats];
    }

    public function compareVMFStreaming($filePath1, $filePath2, $ignoreOptions) {
        $this->ignoreOptions = $ignoreOptions;

        $stream1 = $this->parser->parseVMF($filePath1);
        $stream2 = $this->parser->parseVMF($filePath2);

        $differences = [
            'removed' => [],
            'added' => [],
            'changed' => [],
            'vertex_changed' => [],
            'comments' => []
        ];
        $stats = $this->initializeStats();

        while ($stream1->valid() && $stream2->valid()) {
            $section1 = $stream1->current();
            $section2 = $stream2->current();

            if ($section1['name'] === $section2['name']) {
                $this->compareStreamingSections($section1, $section2, $differences, $stats);
                $stream1->next();
                $stream2->next();
            } elseif ($section1['name'] < $section2['name']) {
                $differences['removed'][] = $section1;
                $stream1->next();
            } else {
                $differences['added'][] = $section2;
                $stream2->next();
            }
        }

        // Handle remaining sections in either stream
        while ($stream1->valid()) {
            $differences['removed'][] = $stream1->current();
            $stream1->next();
        }

        while ($stream2->valid()) {
            $differences['added'][] = $stream2->current();
            $stream2->next();
        }

        if ($stats['vertex_changes'] > 0) {
            $stats['average_vertex_deviation'] = $stats['total_vertex_deviation'] / $stats['vertex_changes'];
        }

        return ['differences' => $differences, 'stats' => $stats];
    }

    private function compareStreamingSections($section1, $section2, &$differences, &$stats) {
        $path = $section1['name'];
        if ($this->shouldIgnore($path)) {
            return;
        }

        $this->compareElements($section1['content'], $section2['content'], $path, $differences, $stats);

        // Update stats for the current section
        $this->gatherStatsForSection($section1['content'], $stats, 'vmf1');
        $this->gatherStatsForSection($section2['content'], $stats, 'vmf2');
    }

    private function gatherStatsForSection($section, &$stats, $key) {
        $stats['brush_counts'][$key] += $this->countBrushes($section);
        $stats['displacement_counts'][$key] += $this->countDisplacements($section);
        $stats['vertex_counts'][$key] += $this->countVertices($section);
        $this->countTextures($section, $stats['texture_counts'][$key]);
        $this->countSmoothingGroups($section, $stats['smoothing_group_counts'][$key]);
        $stats['connections_counts'][$key] += $this->countConnections($section);
        $this->countEntities($section, $stats['entity_counts'][$key]);
        $stats['func_detail_counts'][$key] += $this->countFuncDetail($section);
        $stats['areaportal_counts'][$key] += $this->countAreaportals($section);
        $stats['occluder_counts'][$key] += $this->countOccluders($section);
        $stats['hint_brush_counts'][$key] += $this->countHintBrushes($section);
        $stats['ladder_counts'][$key] += $this->countLadders($section);
        $stats['water_volume_counts'][$key] += $this->countWaterVolumes($section);
        $this->updateSkyboxInfo($section, $stats['skybox_info'][$key]);
        $stats['spawn_point_counts'][$key] += $this->countSpawnPoints($section);
        $stats['buy_zone_counts'][$key] += $this->countBuyZones($section);
        $stats['bombsite_counts'][$key] += $this->countBombsites($section);
    }

    private function compareComments($comments1, $comments2) {
        $commentDiffs = [
            'removed' => [],
            'added' => [],
            'changed' => []
        ];

        $allCommentKeys = array_unique(array_merge(array_keys($comments1), array_keys($comments2)));

        foreach ($allCommentKeys as $key) {
            if (!isset($comments2[$key])) {
                $commentDiffs['removed'][] = $comments1[$key];
            } elseif (!isset($comments1[$key])) {
                $commentDiffs['added'][] = $comments2[$key];
            } elseif ($comments1[$key] !== $comments2[$key]) {
                $commentDiffs['changed'][] = [
                    'old' => $comments1[$key],
                    'new' => $comments2[$key]
                ];
            }
        }

        return $commentDiffs;
    }

    private function updateSkyboxInfo($section, &$skyboxInfo) {
        if (isset($section['skyname'])) {
            $skyboxInfo['skyname'] = $section['skyname'];
        }
        if (isset($section['entity'])) {
            $entities = is_array($section['entity']) ? $section['entity'] : [$section['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'sky_camera') {
                    $skyboxInfo['sky_camera'] = $entity;
                    break;
                }
            }
        }
    }

    private function initializeStats() {
        return [
            'total_differences' => 0,
            'vertex_changes' => 0,
            'total_vertex_deviation' => 0,
            'max_vertex_deviation' => 0,
            'entity_counts' => ['vmf1' => [], 'vmf2' => []],
            'brush_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'displacement_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'visgroup_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'camera_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'cordon_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'texture_counts' => ['vmf1' => [], 'vmf2' => []],
            'smoothing_group_counts' => ['vmf1' => [], 'vmf2' => []],
            'connections_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'group_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'func_detail_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'areaportal_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'occluder_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'hint_brush_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'ladder_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'water_volume_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'skybox_info' => ['vmf1' => [], 'vmf2' => []],
            'spawn_point_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'buy_zone_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'bombsite_counts' => ['vmf1' => 0, 'vmf2' => 0],
        ];
    }

    private function gatherStats($vmf, &$stats, $key) {
        $stats['brush_counts'][$key] = $this->countBrushes($vmf);
        $stats['displacement_counts'][$key] = $this->countDisplacements($vmf);
        $stats['visgroup_counts'][$key] = $this->countVisgroups($vmf);
        $stats['camera_counts'][$key] = $this->countCameras($vmf);
        $stats['cordon_counts'][$key] = $this->countCordons($vmf);
        $stats['texture_counts'][$key] = $this->countTextures($vmf);
        $stats['vertex_counts'][$key] = $this->countVertices($vmf);
        $stats['smoothing_group_counts'][$key] = $this->countSmoothingGroups($vmf);
        $stats['connections_counts'][$key] = $this->countConnections($vmf);
        $stats['entity_counts'][$key] = $this->countEntities($vmf);
        $stats['group_counts'][$key] = $this->countGroups($vmf);
        $stats['func_detail_counts'][$key] = $this->countFuncDetail($vmf);
        $stats['areaportal_counts'][$key] = $this->countAreaportals($vmf);
        $stats['occluder_counts'][$key] = $this->countOccluders($vmf);
        $stats['hint_brush_counts'][$key] = $this->countHintBrushes($vmf);
        $stats['ladder_counts'][$key] = $this->countLadders($vmf);
        $stats['water_volume_counts'][$key] = $this->countWaterVolumes($vmf);
        $stats['skybox_info'][$key] = $this->getSkyboxInfo($vmf);
        $stats['spawn_point_counts'][$key] = $this->countSpawnPoints($vmf);
        $stats['buy_zone_counts'][$key] = $this->countBuyZones($vmf);
        $stats['bombsite_counts'][$key] = $this->countBombsites($vmf);
    }

    private function compareElements($tree1, $tree2, $path, &$differences, &$stats) {
        $allKeys = array_unique(array_merge(array_keys($tree1), array_keys($tree2)));
        
        foreach ($allKeys as $key) {
            $newPath = $path ? "$path.$key" : $key;
            if ($this->shouldIgnore($newPath)) {
                continue;
            }
            
            if (!isset($tree2[$key])) {
                $differences['removed'][] = [
                    'path' => $newPath,
                    'value' => $tree1[$key]
                ];
                $stats['total_differences']++;
            } elseif (!isset($tree1[$key])) {
                $differences['added'][] = [
                    'path' => $newPath,
                    'value' => $tree2[$key]
                ];
                $stats['total_differences']++;
            } else {
                $elem1 = $tree1[$key];
                $elem2 = $tree2[$key];
                if (is_array($elem1) && is_array($elem2)) {
                    $this->compareElements($elem1, $elem2, $newPath, $differences, $stats);
                } elseif ($elem1 !== $elem2) {
                    $differences['changed'][] = [
                        'path' => $newPath,
                        'old_value' => $elem1,
                        'new_value' => $elem2
                    ];
                    $stats['total_differences']++;
                }
            }
        }
    }

    private function compareElementProperties($elem1, $elem2, $path, $id, &$differences, &$stats) {
        $allKeys = array_unique(array_merge(array_keys($elem1), array_keys($elem2)));
        
        foreach ($allKeys as $key) {
            if ($key === '__id') continue; // Skip the ID field in comparison
            
            $newPath = "$path.$key";
            if ($this->shouldIgnore($newPath)) {
                continue;
            }
            
            if (!array_key_exists($key, $elem2)) {
                $differences['removed'][] = [
                    'id' => $id,
                    'path' => $newPath,
                    'value' => $elem1[$key]
                ];
                $stats['total_differences']++;
            } elseif (!array_key_exists($key, $elem1)) {
                $differences['added'][] = [
                    'id' => $id,
                    'path' => $newPath,
                    'value' => $elem2[$key]
                ];
                $stats['total_differences']++;
            } else {
                $value1 = $elem1[$key];
                $value2 = $elem2[$key];
                if (is_array($value1) && is_array($value2)) {
                    if ($key === 'vertices_plus') {
                        $vertexDiff = $this->compareVertices($value1, $value2);
                        if ($vertexDiff) {
                            $differences['vertex_changed'][] = [
                                'id' => $id,
                                'path' => $newPath,
                                'difference' => $vertexDiff
                            ];
                            $stats['vertex_changes'] += $vertexDiff['changed_count'];
                            $stats['total_vertex_deviation'] += $vertexDiff['total_deviation'];
                            $stats['max_vertex_deviation'] = max($stats['max_vertex_deviation'], $vertexDiff['max_deviation']);
                        }
                    } elseif (isset($value1[0]) && isset($value2[0])) {
                        // Compare arrays of values
                        $arrayDiff = array_diff($value1, $value2);
                        if (!empty($arrayDiff)) {
                            $differences['changed'][] = [
                                'id' => $id,
                                'path' => $newPath,
                                'old_value' => $value1,
                                'new_value' => $value2
                            ];
                            $stats['total_differences']++;
                        }
                    } else {
                        $this->compareElements($value1, $value2, $newPath, $differences, $stats);
                    }
                } elseif ($value1 !== $value2) {
                    $differences['changed'][] = [
                        'id' => $id,
                        'path' => $newPath,
                        'old_value' => $value1,
                        'new_value' => $value2
                    ];
                    $stats['total_differences']++;
                }
            }
        }
    }

    private function compareVertices($vertices1, $vertices2) {
        $changedCount = 0;
        $totalDeviation = 0;
        $maxDeviation = 0;

        $count = max(count($vertices1), count($vertices2));
        for ($i = 0; $i < $count; $i++) {
            if (!isset($vertices1[$i]) || !isset($vertices2[$i])) {
                $changedCount++;
                continue;
            }
            
            $vertexSet1 = $vertices1[$i];
            $vertexSet2 = $vertices2[$i];
            
            $setCount = max(count($vertexSet1), count($vertexSet2));
            for ($j = 0; $j < $setCount; $j++) {
                if (!isset($vertexSet1[$j]) || !isset($vertexSet2[$j])) {
                    $changedCount++;
                    continue;
                }
                
                $v1 = $vertexSet1[$j];
                $v2 = $vertexSet2[$j];
                
                $deviation = 0;
                for ($k = 0; $k < 3; $k++) {
                    $diff = abs($v1[$k] - $v2[$k]);
                    $deviation += $diff;
                    $maxDeviation = max($maxDeviation, $diff);
                }
                
                if ($deviation > 0) {
                    $changedCount++;
                    $totalDeviation += $deviation;
                }
            }
        }

        if ($changedCount > 0) {
            return [
                'changed_count' => $changedCount,
                'total_deviation' => $totalDeviation,
                'max_deviation' => $maxDeviation,
                'avg_deviation' => $totalDeviation / ($changedCount * 3)
            ];
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

    
    private function comparePalettePlus($palette1, $palette2) {
        $differences = [];
        $allColors = array_unique(array_merge(array_keys($palette1), array_keys($palette2)));
        
        foreach ($allColors as $color) {
            if (!isset($palette2[$color])) {
                $differences[] = ["removed" => [$color => $palette1[$color]]];
            } elseif (!isset($palette1[$color])) {
                $differences[] = ["added" => [$color => $palette2[$color]]];
            } elseif ($palette1[$color] !== $palette2[$color]) {
                if (is_array($palette1[$color]) && is_array($palette2[$color]) && isset($palette1[$color][0]) && isset($palette2[$color][0])) {
                    $arrayDiff = array_diff($palette1[$color], $palette2[$color]);
                    if (!empty($arrayDiff)) {
                        $differences[] = ["changed" => [$color => ["old" => $palette1[$color], "new" => $palette2[$color]]]];
                    }
                } else {
                    $differences[] = ["changed" => [$color => ["old" => $palette1[$color], "new" => $palette2[$color]]]];
                }
            }
        }
        
        return $differences;
    }

    private function compareColorCorrectionPlus($cc1, $cc2) {
        $differences = [];
        $allKeys = array_unique(array_merge(array_keys($cc1), array_keys($cc2)));
        
        foreach ($allKeys as $key) {
            if (!isset($cc2[$key])) {
                $differences[] = ["removed" => [$key => $cc1[$key]]];
            } elseif (!isset($cc1[$key])) {
                $differences[] = ["added" => [$key => $cc2[$key]]];
            } elseif ($cc1[$key] !== $cc2[$key]) {
                if (is_array($cc1[$key]) && is_array($cc2[$key])) {
                    $arrayDiff = array_diff($cc1[$key], $cc2[$key]);
                    if (!empty($arrayDiff)) {
                        $differences[] = ["changed" => [$key => ["old" => $cc1[$key], "new" => $cc2[$key]]]];
                    }
                } else {
                    $differences[] = ["changed" => [$key => ["old" => $cc1[$key], "new" => $cc2[$key]]]];
                }
            }
        }
        
        return $differences;
    }

    private function compareLightPlus($light1, $light2) {
        $differences = [];
        $allKeys = array_unique(array_merge(array_keys($light1), array_keys($light2)));
        
        foreach ($allKeys as $key) {
            if (!isset($light2[$key])) {
                $differences[] = ["removed" => [$key => $light1[$key]]];
            } elseif (!isset($light1[$key])) {
                $differences[] = ["added" => [$key => $light2[$key]]];
            } elseif ($light1[$key] !== $light2[$key]) {
                if (is_array($light1[$key]) && is_array($light2[$key])) {
                    $arrayDiff = array_diff($light1[$key], $light2[$key]);
                    if (!empty($arrayDiff)) {
                        $differences[] = ["changed" => [$key => ["old" => $light1[$key], "new" => $light2[$key]]]];
                    }
                } else {
                    $differences[] = ["changed" => [$key => ["old" => $light1[$key], "new" => $light2[$key]]]];
                }
            }
        }
        
        return $differences;
    }

    private function compareBgImagesPlus($bg1, $bg2) {
        $differences = [];
        $allKeys = array_unique(array_merge(array_keys($bg1), array_keys($bg2)));
        
        foreach ($allKeys as $key) {
            if (!isset($bg2[$key])) {
                $differences[] = ["removed" => [$key => $bg1[$key]]];
            } elseif (!isset($bg1[$key])) {
                $differences[] = ["added" => [$key => $bg2[$key]]];
            } elseif ($bg1[$key] !== $bg2[$key]) {
                if (is_array($bg1[$key]) && is_array($bg2[$key])) {
                    $arrayDiff = array_diff($bg1[$key], $bg2[$key]);
                    if (!empty($arrayDiff)) {
                        $differences[] = ["changed" => [$key => ["old" => $bg1[$key], "new" => $bg2[$key]]]];
                    }
                } else {
                    $differences[] = ["changed" => [$key => ["old" => $bg1[$key], "new" => $bg2[$key]]]];
                }
            }
        }
        
        return $differences;
    }

    private function compareCameras($cameras1, $cameras2) {
        $differences = [];
        $allKeys = array_unique(array_merge(array_keys($cameras1), array_keys($cameras2)));
        
        foreach ($allKeys as $key) {
            if ($key === 'cameras') {
                if (isset($cameras1['cameras']) && isset($cameras2['cameras']) && 
                    is_array($cameras1['cameras']) && is_array($cameras2['cameras'])) {
                    $cameraDiffs = $this->compareCameraList($cameras1['cameras'], $cameras2['cameras']);
                    if (!empty($cameraDiffs)) {
                        $differences['cameras'] = $cameraDiffs;
                    }
                }
            } elseif (!isset($cameras2[$key])) {
                $differences[] = ["removed" => [$key => $cameras1[$key]]];
            } elseif (!isset($cameras1[$key])) {
                $differences[] = ["added" => [$key => $cameras2[$key]]];
            } elseif ($cameras1[$key] !== $cameras2[$key]) {
                if (is_array($cameras1[$key]) && is_array($cameras2[$key])) {
                    $arrayDiff = array_diff($cameras1[$key], $cameras2[$key]);
                    if (!empty($arrayDiff)) {
                        $differences[] = ["changed" => [$key => ["old" => $cameras1[$key], "new" => $cameras2[$key]]]];
                    }
                } else {
                    $differences[] = ["changed" => [$key => ["old" => $cameras1[$key], "new" => $cameras2[$key]]]];
                }
            }
        }
        
        return $differences;
    }

    private function compareCameraList($list1, $list2) {
        $differences = [];
        $count = max(count($list1), count($list2));
        
        for ($i = 0; $i < $count; $i++) {
            if (!isset($list2[$i])) {
                $differences[] = ["removed" => $list1[$i]];
            } elseif (!isset($list1[$i])) {
                $differences[] = ["added" => $list2[$i]];
            } else {
                $cameraDiff = $this->compareCamera($list1[$i], $list2[$i]);
                if (!empty($cameraDiff)) {
                    $differences[] = ["changed" => $cameraDiff];
                }
            }
        }
        
        return $differences;
    }

    private function compareCamera($camera1, $camera2) {
        $differences = [];
        $allKeys = array_unique(array_merge(array_keys($camera1), array_keys($camera2)));
        
        foreach ($allKeys as $key) {
            if (!isset($camera2[$key])) {
                $differences[$key] = ["removed" => $camera1[$key]];
            } elseif (!isset($camera1[$key])) {
                $differences[$key] = ["added" => $camera2[$key]];
            } elseif ($camera1[$key] !== $camera2[$key]) {
                if (is_array($camera1[$key]) && is_array($camera2[$key])) {
                    $arrayDiff = array_diff($camera1[$key], $camera2[$key]);
                    if (!empty($arrayDiff)) {
                        $differences[$key] = ["old" => $camera1[$key], "new" => $camera2[$key]];
                    }
                } else {
                    $differences[$key] = ["old" => $camera1[$key], "new" => $camera2[$key]];
                }
            }
        }
        
        return $differences;
    }

    private function compareCordons($cordons1, $cordons2) {
        $differences = [];
        $allKeys = array_unique(array_merge(array_keys($cordons1), array_keys($cordons2)));
        
        foreach ($allKeys as $key) {
            if (!isset($cordons2[$key])) {
                $differences[] = ["removed" => [$key => $cordons1[$key]]];
            } elseif (!isset($cordons1[$key])) {
                $differences[] = ["added" => [$key => $cordons2[$key]]];
            } elseif ($cordons1[$key] !== $cordons2[$key]) {
                if (is_array($cordons1[$key]) && is_array($cordons2[$key])) {
                    $arrayDiff = array_diff($cordons1[$key], $cordons2[$key]);
                    if (!empty($arrayDiff)) {
                        $differences[] = ["changed" => [$key => ["old" => $cordons1[$key], "new" => $cordons2[$key]]]];
                    }
                } else {
                    $differences[] = ["changed" => [$key => ["old" => $cordons1[$key], "new" => $cordons2[$key]]]];
                }
            }
        }
        
        return $differences;
    }

    // Helper methods for counting various elements
    private function countBrushes($vmf) {
        $count = 0;
        if (isset($vmf['world']['solid'])) {
            $count += is_array($vmf['world']['solid']) ? 
                (isset($vmf['world']['solid'][0]) ? count($vmf['world']['solid']) : 1) : 1;
        }
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['solid'])) {
                    $count += is_array($entity['solid']) ? 
                        (isset($entity['solid'][0]) ? count($entity['solid']) : 1) : 1;
                }
            }
        }
        return $count;
    }

    private function countDisplacements($vmf) {
        $count = 0;
        if (isset($vmf['world'])) {
            $count += $this->countDisplacementsInBrushes($vmf['world']);
        }
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                $count += $this->countDisplacementsInBrushes($entity);
            }
        }
        return $count;
    }

    private function countDisplacementsInBrushes($brushContainer) {
        $count = 0;
        if (isset($brushContainer['solid'])) {
            $solids = is_array($brushContainer['solid']) ? $brushContainer['solid'] : [$brushContainer['solid']];
            foreach ($solids as $solid) {
                if (isset($solid['side'])) {
                    $sides = is_array($solid['side']) ? $solid['side'] : [$solid['side']];
                    foreach ($sides as $side) {
                        if (isset($side['dispinfo'])) {
                            $count++;
                        }
                    }
                }
            }
        }
        return $count;
    }

    private function countVertices($vmf) {
        $count = 0;
        if (isset($vmf['world'])) {
            $count += $this->countVerticesInBrushes($vmf['world']);
        }
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                $count += $this->countVerticesInBrushes($entity);
            }
        }
        return $count;
    }

    private function countVerticesInBrushes($brushContainer) {
        $count = 0;
        if (isset($brushContainer['solid'])) {
            $solids = is_array($brushContainer['solid']) ? $brushContainer['solid'] : [$brushContainer['solid']];
            foreach ($solids as $solid) {
                if (isset($solid['vertices_plus'])) {
                    foreach ($solid['vertices_plus'] as $vertexSet) {
                        $count += count($vertexSet);
                    }
                }
            }
        }
        return $count;
    }

    private function countVisgroups($vmf) {
        if (!isset($vmf['visgroups']['visgroup'])) return 0;
        $visgroups = $vmf['visgroups']['visgroup'];
        return is_array($visgroups) ? (isset($visgroups[0]) ? count($visgroups) : 1) : 1;
    }

    private function countCameras($vmf) {
        if (!isset($vmf['cameras']['camera'])) return 0;
        $cameras = $vmf['cameras']['camera'];
        return is_array($cameras) ? (isset($cameras[0]) ? count($cameras) : 1) : 1;
    }

    private function countCordons($vmf) {
        if (!isset($vmf['cordons']['cordon'])) return 0;
        $cordons = $vmf['cordons']['cordon'];
        return is_array($cordons) ? (isset($cordons[0]) ? count($cordons) : 1) : 1;
    }

    private function countTextures($vmf) {
        $textures = [];
        if (isset($vmf['world'])) {
            $this->countTexturesInBrushes($vmf['world'], $textures);
        }
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                $this->countTexturesInBrushes($entity, $textures);
            }
        }
        return $textures;
    }

    private function countTexturesInBrushes($brushContainer, &$textures) {
        if (isset($brushContainer['solid'])) {
            $solids = is_array($brushContainer['solid']) ? $brushContainer['solid'] : [$brushContainer['solid']];
            foreach ($solids as $solid) {
                if (isset($solid['side'])) {
                    $sides = is_array($solid['side']) ? $solid['side'] : [$solid['side']];
                    foreach ($sides as $side) {
                        if (isset($side['material'])) {
                            $textures[$side['material']] = ($textures[$side['material']] ?? 0) + 1;
                        }
                    }
                }
            }
        }
    }

    private function countSmoothingGroups($vmf) {
        $groups = [];
        if (isset($vmf['world'])) {
            $this->countSmoothingGroupsInBrushes($vmf['world'], $groups);
        }
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                $this->countSmoothingGroupsInBrushes($entity, $groups);
            }
        }
        return $groups;
    }

    private function countSmoothingGroupsInBrushes($brushContainer, &$groups) {
        if (isset($brushContainer['solid'])) {
            $solids = is_array($brushContainer['solid']) ? $brushContainer['solid'] : [$brushContainer['solid']];
            foreach ($solids as $solid) {
                if (isset($solid['side'])) {
                    $sides = is_array($solid['side']) ? $solid['side'] : [$solid['side']];
                    foreach ($sides as $side) {
                        if (isset($side['smoothing_groups'])) {
                            $groups[$side['smoothing_groups']] = ($groups[$side['smoothing_groups']] ?? 0) + 1;
                        }
                    }
                }
            }
        }
    }

    private function countConnections($vmf) {
        $count = 0;
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['connections'])) {
                    $connections = $entity['connections'];
                    $count += is_array($connections) ? (isset($connections[0]) ? count($connections) : 1) : 1;
                }
            }
        }
        return $count;
    }

    private function countEntities($vmf) {
        $entities = [];
        if (isset($vmf['entity'])) {
            $entityList = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entityList as $entity) {
                if (isset($entity['classname'])) {
                    $entities[$entity['classname']] = ($entities[$entity['classname']] ?? 0) + 1;
                }
            }
        }
        return $entities;
    }

    private function countGroups($vmf) {
        if (!isset($vmf['group'])) return 0;
        $groups = $vmf['group'];
        return is_array($groups) ? (isset($groups[0]) ? count($groups) : 1) : 1;
    }

    private function countFuncDetail($vmf) {
        $count = 0;
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_detail') {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countAreaportals($vmf) {
        $count = 0;
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_areaportal') {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countOccluders($vmf) {
        $count = 0;
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_occluder') {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countHintBrushes($vmf) {
        $count = 0;
        if (isset($vmf['world'])) {
            $count += $this->countHintBrushesInBrushes($vmf['world']);
        }
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                $count += $this->countHintBrushesInBrushes($entity);
            }
        }
        return $count;
    }

    private function countHintBrushesInBrushes($brushContainer) {
        $count = 0;
        if (isset($brushContainer['solid'])) {
            $solids = is_array($brushContainer['solid']) ? $brushContainer['solid'] : [$brushContainer['solid']];
            foreach ($solids as $solid) {
                if (isset($solid['side'])) {
                    $sides = is_array($solid['side']) ? $solid['side'] : [$solid['side']];
                    foreach ($sides as $side) {
                        if (isset($side['material']) && strpos($side['material'], 'tools/toolshint') === 0) {
                            $count++;
                            break;  // Count the brush only once
                        }
                    }
                }
            }
        }
        return $count;
    }

    private function countLadders($vmf) {
        $count = 0;
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_ladder') {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countWaterVolumes($vmf) {
        $count = 0;
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && in_array($entity['classname'], ['func_water', 'func_water_lod'])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function getSkyboxInfo($vmf) {
        $skyboxInfo = [];
        if (isset($vmf['world']['skyname'])) {
            $skyboxInfo['skyname'] = $vmf['world']['skyname'];
        }
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'sky_camera') {
                    $skyboxInfo['sky_camera'] = $entity;
                    break;
                }
            }
        }
        return $skyboxInfo;
    }

    private function countSpawnPoints($vmf) {
        $count = 0;
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && in_array($entity['classname'], ['info_player_terrorist', 'info_player_counterterrorist'])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countBuyZones($vmf) {
        $count = 0;
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_buyzone') {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countBombsites($vmf) {
        $count = 0;
        if (isset($vmf['entity'])) {
            $entities = is_array($vmf['entity']) ? $vmf['entity'] : [$vmf['entity']];
            foreach ($entities as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_bomb_target') {
                    $count++;
                }
            }
        }
        return $count;
    }

    public function reset() {
        $this->ignoreOptions = [];
        $this->parser = new VMFParser();
        error_log("VMFComparator reset completed at " . date('Y-m-d H:i:s'));
    }
}

