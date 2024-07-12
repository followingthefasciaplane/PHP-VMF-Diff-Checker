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

        $differences = [
            'removed' => [],
            'added' => [],
            'changed' => [],
            'vertex_changed' => []
        ];
        $stats = $this->initializeStats();

        $this->gatherStats($vmf1, $stats, 'vmf1');
        $this->gatherStats($vmf2, $stats, 'vmf2');

        $this->compareElements($vmf1, $vmf2, '', $differences, $stats);

        if ($stats['vertex_changes'] > 0) {
            $stats['average_vertex_deviation'] = $stats['total_vertex_deviation'] / $stats['vertex_changes'];
        }

        return ['differences' => $differences, 'stats' => $stats];
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
                    if ($key === 'vertices_plus') {
                        $vertexDiff = $this->compareVertices($elem1, $elem2);
                        if ($vertexDiff) {
                            $differences['vertex_changed'][] = [
                                'path' => $newPath,
                                'difference' => $vertexDiff
                            ];
                            $stats['vertex_changes'] += $vertexDiff['changed_count'];
                            $stats['total_vertex_deviation'] += $vertexDiff['total_deviation'];
                            $stats['max_vertex_deviation'] = max($stats['max_vertex_deviation'], $vertexDiff['max_deviation']);
                        }
                    } elseif ($key === 'entities') {
                        $this->compareEntities($elem1, $elem2, $newPath, $differences, $stats);
                    } elseif (in_array($key, ['custom_visgroups', 'instance_parameters', 'palette_plus', 'colorcorrection_plus', 'light_plus', 'bgimages_plus'])) {
                        $this->compareHammerPlusElements($elem1, $elem2, $newPath, $differences, $stats);
                    } else {
                        $this->compareElements($elem1, $elem2, $newPath, $differences, $stats);
                    }
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

    private function compareEntities($entities1, $entities2, $path, &$differences, &$stats) {
        $entityMap1 = $this->mapEntities($entities1);
        $entityMap2 = $this->mapEntities($entities2);

        $allIds = array_unique(array_merge(array_keys($entityMap1), array_keys($entityMap2)));

        foreach ($allIds as $id) {
            $newPath = "$path.$id";
            if (!isset($entityMap2[$id])) {
                $differences['removed'][] = [
                    'path' => $newPath,
                    'value' => $entityMap1[$id]
                ];
                $stats['total_differences']++;
            } elseif (!isset($entityMap1[$id])) {
                $differences['added'][] = [
                    'path' => $newPath,
                    'value' => $entityMap2[$id]
                ];
                $stats['total_differences']++;
            } else {
                $this->compareElements($entityMap1[$id], $entityMap2[$id], $newPath, $differences, $stats);
            }
        }
    }

    private function compareHammerPlusElements($elem1, $elem2, $path, &$differences, &$stats) {
        $allKeys = array_unique(array_merge(array_keys($elem1), array_keys($elem2)));
        
        foreach ($allKeys as $key) {
            $newPath = "$path.$key";
            if (!isset($elem2[$key])) {
                $differences['removed'][] = [
                    'path' => $newPath,
                    'value' => $elem1[$key]
                ];
                $stats['total_differences']++;
            } elseif (!isset($elem1[$key])) {
                $differences['added'][] = [
                    'path' => $newPath,
                    'value' => $elem2[$key]
                ];
                $stats['total_differences']++;
            } elseif ($elem1[$key] !== $elem2[$key]) {
                $differences['changed'][] = [
                    'path' => $newPath,
                    'old_value' => $elem1[$key],
                    'new_value' => $elem2[$key]
                ];
                $stats['total_differences']++;
            }
        }
    }

    private function mapEntities($entities) {
        $map = [];
        foreach ($entities as $entity) {
            $id = isset($entity['id']) ? $entity['id'] : (isset($entity['targetname']) ? $entity['targetname'] : uniqid('entity_'));
            $map[$id] = $entity;
        }
        return $map;
    }

    private function shouldIgnore($path) {
        foreach ($this->ignoreOptions as $option) {
            if (fnmatch($option, $path)) {
                return true;
            }
        }
        return false;
    }

    private function initializeStats() {
        return [
            'total_differences' => 0,
            'vertex_changes' => 0,
            'total_vertex_deviation' => 0,
            'max_vertex_deviation' => 0,
            'brush_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'entity_counts' => ['vmf1' => [], 'vmf2' => []],
            'texture_counts' => ['vmf1' => [], 'vmf2' => []],
            'displacement_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'visgroup_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'camera_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'cordon_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'vertex_counts' => ['vmf1' => 0, 'vmf2' => 0],
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
            'hostage_rescue_zone_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'hostage_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'weapon_spawn_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'light_entity_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'trigger_entity_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'map_bounds' => ['vmf1' => [], 'vmf2' => []],
            'instance_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'custom_visgroup_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'palette_plus_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'colorcorrection_plus_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'light_plus_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'bgimages_plus_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'instance_parameter_counts' => ['vmf1' => 0, 'vmf2' => 0],
            'total_entity_count' => ['vmf1' => 0, 'vmf2' => 0],
            'total_brush_count' => ['vmf1' => 0, 'vmf2' => 0],
            'total_side_count' => ['vmf1' => 0, 'vmf2' => 0],
            'total_texture_count' => ['vmf1' => 0, 'vmf2' => 0],
            'unique_texture_count' => ['vmf1' => 0, 'vmf2' => 0],
            'cordons_active' => ['vmf1' => false, 'vmf2' => false],
            'version_info' => ['vmf1' => [], 'vmf2' => []],
        ];
    }

    private function gatherStats($vmf, &$stats, $key) {
        $stats['brush_counts'][$key] = $this->countBrushes($vmf);
        $stats['entity_counts'][$key] = $this->countEntities($vmf);
        $stats['texture_counts'][$key] = $this->countTextures($vmf);
        $stats['displacement_counts'][$key] = $this->countDisplacements($vmf);
        $stats['visgroup_counts'][$key] = $this->countVisgroups($vmf);
        $stats['camera_counts'][$key] = $this->countCameras($vmf);
        $stats['cordon_counts'][$key] = $this->countCordons($vmf);
        $stats['vertex_counts'][$key] = $this->countVertices($vmf);
        $stats['smoothing_group_counts'][$key] = $this->countSmoothingGroups($vmf);
        $stats['connections_counts'][$key] = $this->countConnections($vmf);
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
        $stats['hostage_rescue_zone_counts'][$key] = $this->countHostageRescueZones($vmf);
        $stats['hostage_counts'][$key] = $this->countHostages($vmf);
        $stats['weapon_spawn_counts'][$key] = $this->countWeaponSpawnPoints($vmf);
        $stats['light_entity_counts'][$key] = $this->countLightEntities($vmf);
        $stats['trigger_entity_counts'][$key] = $this->countTriggerEntities($vmf);
        $stats['map_bounds'][$key] = $this->getMapBounds($vmf);
        $stats['instance_counts'][$key] = $this->countInstances($vmf);
        $stats['custom_visgroup_counts'][$key] = $this->countCustomVisgroups($vmf);
        
        // New Hammer++ specific stats
        $stats['palette_plus_counts'][$key] = $this->countPalettePlus($vmf);
        $stats['colorcorrection_plus_counts'][$key] = $this->countColorCorrectionPlus($vmf);
        $stats['light_plus_counts'][$key] = $this->countLightPlus($vmf);
        $stats['bgimages_plus_counts'][$key] = $this->countBgImagesPlus($vmf);
        $stats['instance_parameter_counts'][$key] = $this->countInstanceParameters($vmf);
        
        // Additional stats
        $stats['total_entity_count'][$key] = count($stats['entity_counts'][$key]);
        $stats['total_brush_count'][$key] = $stats['brush_counts'][$key];
        $stats['total_side_count'][$key] = $this->countSides($vmf);
        $stats['total_texture_count'][$key] = array_sum($stats['texture_counts'][$key]);
        $stats['unique_texture_count'][$key] = count($stats['texture_counts'][$key]);
        $stats['cordons_active'][$key] = $this->areCordonsActive($vmf);
        $stats['version_info'][$key] = $this->getVersionInfo($vmf);
    }
    
    // Add these new methods to the VMFComparator class:
    
    private function countSides($vmf) {
        $count = 0;
        if (isset($vmf['world']['solid'])) {
            $count += $this->countSidesInBrushes($vmf['world']);
        }
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                $count += $this->countSidesInBrushes($entity);
            }
        }
        return $count;
    }
    
    private function countSidesInBrushes($brushContainer) {
        $count = 0;
        if (isset($brushContainer['solid'])) {
            $solids = is_array($brushContainer['solid']) ? $brushContainer['solid'] : [$brushContainer['solid']];
            foreach ($solids as $solid) {
                if (isset($solid['side'])) {
                    $count += is_array($solid['side']) ? count($solid['side']) : 1;
                }
            }
        }
        return $count;
    }
    
    private function areCordonsActive($vmf) {
        return isset($vmf['cordons']['active']) && $vmf['cordons']['active'] == '1';
    }
    
    private function getVersionInfo($vmf) {
        return isset($vmf['versioninfo']) ? $vmf['versioninfo'] : [];
    }
    
    private function countInstanceParameters($vmf) {
        return isset($vmf['instance_parameters']) ? count($vmf['instance_parameters']) : 0;
    }

    private function countPalettePlus($vmf) {
        return isset($vmf['palette_plus']) ? count($vmf['palette_plus']) : 0;
    }

    private function countColorCorrectionPlus($vmf) {
        return isset($vmf['colorcorrection_plus']) ? count($vmf['colorcorrection_plus']) : 0;
    }

    private function countLightPlus($vmf) {
        return isset($vmf['light_plus']) ? count($vmf['light_plus']) : 0;
    }

    private function countBgImagesPlus($vmf) {
        return isset($vmf['bgimages_plus']) ? count($vmf['bgimages_plus']) : 0;
    }

    private function countBrushes($vmf) {
        $count = 0;
        if (isset($vmf['world']['solid'])) {
            $count += is_array($vmf['world']['solid']) ? 
                (isset($vmf['world']['solid'][0]) ? count($vmf['world']['solid']) : 1) : 1;
        }
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['solid'])) {
                    $count += is_array($entity['solid']) ? 
                        (isset($entity['solid'][0]) ? count($entity['solid']) : 1) : 1;
                }
            }
        }
        return $count;
    }

    private function countEntities($vmf) {
        $entities = [];
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname'])) {
                    $entities[$entity['classname']] = ($entities[$entity['classname']] ?? 0) + 1;
                }
            }
        }
        return $entities;
    }

    private function countTextures($vmf) {
        $textures = [];
        if (isset($vmf['world'])) {
            $this->countTexturesInBrushes($vmf['world'], $textures);
        }
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
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

    private function countDisplacements($vmf) {
        $count = 0;
        if (isset($vmf['world'])) {
            $count += $this->countDisplacementsInBrushes($vmf['world']);
        }
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
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
    
    private function countVisgroups($vmf) {
        return isset($vmf['visgroups']) ? count($vmf['visgroups']) : 0;
    }
    
    private function countCameras($vmf) {
        return isset($vmf['cameras']) ? count($vmf['cameras']) : 0;
    }
    
    private function countCordons($vmf) {
        return isset($vmf['cordon']) ? 1 : 0;
    }
    
    private function countVertices($vmf) {
        $count = 0;
        if (isset($vmf['world'])) {
            $count += $this->countVerticesInBrushes($vmf['world']);
        }
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
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
    
    private function countSmoothingGroups($vmf) {
        $groups = [];
        if (isset($vmf['world'])) {
            $this->countSmoothingGroupsInBrushes($vmf['world'], $groups);
        }
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
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
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['connections'])) {
                    $connections = $entity['connections'];
                    $count += is_array($connections) ? (isset($connections[0]) ? count($connections) : 1) : 1;
                }
            }
        }
        return $count;
    }
    
    private function countGroups($vmf) {
        return isset($vmf['group']) ? (is_array($vmf['group']) ? count($vmf['group']) : 1) : 0;
    }
    
    private function countFuncDetail($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_detail') {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function countAreaportals($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_areaportal') {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function countOccluders($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
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
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
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
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_ladder') {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function countWaterVolumes($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
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
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
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
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && in_array($entity['classname'], ['info_player_terrorist', 'info_player_counterterrorist'])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countBuyZones($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_buyzone') {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function countBombsites($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_bomb_target') {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function countHostageRescueZones($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'func_hostage_rescue') {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function countHostages($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && $entity['classname'] === 'hostage_entity') {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function countWeaponSpawnPoints($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && strpos($entity['classname'], 'weapon_') === 0) {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function countLightEntities($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && in_array($entity['classname'], ['light', 'light_spot', 'light_environment'])) {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function countTriggerEntities($vmf) {
        $count = 0;
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                if (isset($entity['classname']) && strpos($entity['classname'], 'trigger_') === 0) {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    private function getMapBounds($vmf) {
        $minX = $minY = $minZ = PHP_FLOAT_MAX;
        $maxX = $maxY = $maxZ = PHP_FLOAT_MIN;
    
        $processVertex = function($vertex) use (&$minX, &$minY, &$minZ, &$maxX, &$maxY, &$maxZ) {
            $minX = min($minX, $vertex[0]);
            $minY = min($minY, $vertex[1]);
            $minZ = min($minZ, $vertex[2]);
            $maxX = max($maxX, $vertex[0]);
            $maxY = max($maxY, $vertex[1]);
            $maxZ = max($maxZ, $vertex[2]);
        };
    
        $processBrushes = function($brushContainer) use ($processVertex) {
            if (isset($brushContainer['solid'])) {
                $solids = is_array($brushContainer['solid']) ? $brushContainer['solid'] : [$brushContainer['solid']];
                foreach ($solids as $solid) {
                    if (isset($solid['vertices_plus'])) {
                        foreach ($solid['vertices_plus'] as $vertexSet) {
                            foreach ($vertexSet as $vertex) {
                                $processVertex($vertex);
                            }
                        }
                    }
                }
            }
        };
    
        if (isset($vmf['world'])) {
            $processBrushes($vmf['world']);
        }
        if (isset($vmf['entities'])) {
            foreach ($vmf['entities'] as $entity) {
                $processBrushes($entity);
            }
        }
    
        return [
            'min' => [$minX, $minY, $minZ],
            'max' => [$maxX, $maxY, $maxZ]
        ];
    }

    private function countInstances($vmf) {
        return isset($vmf['instances']) ? count($vmf['instances']) : 0;
    }

    private function countCustomVisgroups($vmf) {
        return isset($vmf['custom_visgroups']) ? count($vmf['custom_visgroups']) : 0;
    }

    public function generateReport($differences, $stats) {
        $report = "VMF Comparison Report\n\n";

        $report .= "Differences:\n";
        $report .= "  Removed: " . count($differences['removed']) . "\n";
        $report .= "  Added: " . count($differences['added']) . "\n";
        $report .= "  Changed: " . count($differences['changed']) . "\n";
        $report .= "  Vertex Changes: " . count($differences['vertex_changed']) . "\n\n";

        $report .= "Statistics:\n";
        $report .= "  Total Differences: " . $stats['total_differences'] . "\n";
        $report .= "  Vertex Changes: " . $stats['vertex_changes'] . "\n";
        $report .= "  Average Vertex Deviation: " . ($stats['vertex_changes'] > 0 ? $stats['total_vertex_deviation'] / $stats['vertex_changes'] : 0) . "\n";
        $report .= "  Max Vertex Deviation: " . $stats['max_vertex_deviation'] . "\n\n";

        $report .= $this->generateCountComparison("Brushes", $stats['brush_counts']);
        $report .= $this->generateCountComparison("Displacements", $stats['displacement_counts']);
        $report .= $this->generateCountComparison("Visgroups", $stats['visgroup_counts']);
        $report .= $this->generateCountComparison("Cameras", $stats['camera_counts']);
        $report .= $this->generateCountComparison("Cordons", $stats['cordon_counts']);
        $report .= $this->generateCountComparison("Vertices", $stats['vertex_counts']);
        $report .= $this->generateCountComparison("Connections", $stats['connections_counts']);
        $report .= $this->generateCountComparison("Groups", $stats['group_counts']);
        $report .= $this->generateCountComparison("Func Detail", $stats['func_detail_counts']);
        $report .= $this->generateCountComparison("Areaportals", $stats['areaportal_counts']);
        $report .= $this->generateCountComparison("Occluders", $stats['occluder_counts']);
        $report .= $this->generateCountComparison("Hint Brushes", $stats['hint_brush_counts']);
        $report .= $this->generateCountComparison("Ladders", $stats['ladder_counts']);
        $report .= $this->generateCountComparison("Water Volumes", $stats['water_volume_counts']);
        $report .= $this->generateCountComparison("Spawn Points", $stats['spawn_point_counts']);
        $report .= $this->generateCountComparison("Buy Zones", $stats['buy_zone_counts']);
        $report .= $this->generateCountComparison("Bombsites", $stats['bombsite_counts']);
        $report .= $this->generateCountComparison("Hostage Rescue Zones", $stats['hostage_rescue_zone_counts']);
        $report .= $this->generateCountComparison("Hostages", $stats['hostage_counts']);
        $report .= $this->generateCountComparison("Weapon Spawn Points", $stats['weapon_spawn_counts']);
        $report .= $this->generateCountComparison("Light Entities", $stats['light_entity_counts']);
        $report .= $this->generateCountComparison("Trigger Entities", $stats['trigger_entity_counts']);
        $report .= $this->generateCountComparison("Instances", $stats['instance_counts']);
        $report .= $this->generateCountComparison("Custom Visgroups", $stats['custom_visgroup_counts']);
        $report .= $this->generateCountComparison("Palette Plus", $stats['palette_plus_counts']);
        $report .= $this->generateCountComparison("Color Correction Plus", $stats['colorcorrection_plus_counts']);
        $report .= $this->generateCountComparison("Light Plus", $stats['light_plus_counts']);
        $report .= $this->generateCountComparison("Background Images Plus", $stats['bgimages_plus_counts']);


        $report .= "\nEntity Counts:\n";
        $report .= $this->generateEntityCountComparison($stats['entity_counts']);

        $report .= "\nTexture Counts:\n";
        $report .= $this->generateTextureCountComparison($stats['texture_counts']);

        $report .= "\nSmoothing Group Counts:\n";
        $report .= $this->generateSmoothingGroupCountComparison($stats['smoothing_group_counts']);

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

    private function generateSmoothingGroupCountComparison($smoothingGroupCounts) {
        $report = "";
        $allGroups = array_unique(array_merge(array_keys($smoothingGroupCounts['vmf1']), array_keys($smoothingGroupCounts['vmf2'])));
        sort($allGroups);

        foreach ($allGroups as $group) {
            $count1 = $smoothingGroupCounts['vmf1'][$group] ?? 0;
            $count2 = $smoothingGroupCounts['vmf2'][$group] ?? 0;
            if ($count1 != $count2) {
                $report .= sprintf("  Group %s: %d vs %d\n", $group, $count1, $count2);
            }
        }

        return $report;
    }

    private function generateSkyboxInfoComparison($skyboxInfo) {
        $report = "";
        $report .= sprintf("  Skyname: %s vs %s\n", 
            $skyboxInfo['vmf1']['skyname'] ?? 'N/A', 
            $skyboxInfo['vmf2']['skyname'] ?? 'N/A');

        if (isset($skyboxInfo['vmf1']['sky_camera']) || isset($skyboxInfo['vmf2']['sky_camera'])) {
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
        $report = "";
        $report .= sprintf("  Min: (%s) vs (%s)\n",
            implode(", ", $mapBounds['vmf1']['min'] ?? ['N/A', 'N/A', 'N/A']),
            implode(", ", $mapBounds['vmf2']['min'] ?? ['N/A', 'N/A', 'N/A']));
        $report .= sprintf("  Max: (%s) vs (%s)\n",
            implode(", ", $mapBounds['vmf1']['max'] ?? ['N/A', 'N/A', 'N/A']),
            implode(", ", $mapBounds['vmf2']['max'] ?? ['N/A', 'N/A', 'N/A']));
        return $report;
    }
}

class VMFComparatorException extends Exception {}