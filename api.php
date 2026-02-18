<?php
// Bussigt! API — GTFS-RT vehicle positions + stop search via SL open API
// Combined endpoint: ?action=search|lines for stop discovery, default for bus positions

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, max-age=0');

// ---- Stop search & line discovery (via SL open API) ----
$action = $_GET['action'] ?? '';

if ($action === 'search') {
    $q = mb_strtolower(trim($_GET['q'] ?? ''));
    if (strlen($q) < 2) { echo json_encode(['stops' => []]); exit; }

    // Cache all SL stops (with stop_areas for GTFS ID mapping) for 24h
    $cacheFile = sys_get_temp_dir() . '/bussigt_stops_v2.json';
    $cacheAge = file_exists($cacheFile) ? time() - filemtime($cacheFile) : 999999;
    if ($cacheAge < 86400 && filesize($cacheFile) > 1000) {
        $allStops = json_decode(file_get_contents($cacheFile), true);
    } else {
        // expand=true gives us stop_areas which map to GTFS stop IDs
        $url = 'https://transport.integration.sl.se/v1/sites?expand=true';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_HTTPHEADER=>['Accept: application/json']]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($resp === false || $code !== 200) { echo json_encode(['error'=>'SL API error','code'=>$code]); exit; }
        $raw = json_decode($resp, true);
        $allStops = [];
        foreach ($raw as $site) {
            $siteId = $site['id'] ?? null; $name = $site['name'] ?? ''; $lat = $site['lat'] ?? null; $lon = $site['lon'] ?? null;
            if (!$siteId || !$lat || !$lon) continue;
            $stopAreas = $site['stop_areas'] ?? [];
            $allStops[] = ['s'=>(int)$siteId,'n'=>$name,'la'=>(float)$lat,'lo'=>(float)$lon,'sa'=>$stopAreas];
        }
        file_put_contents($cacheFile, json_encode($allStops));
    }

    // Filter by query
    $stops = []; $count = 0;
    foreach ($allStops as $s) {
        if (mb_strpos(mb_strtolower($s['n']), $q) !== false) {
            // Build GTFS stop IDs from stop_areas (the correct mapping)
            $gtfsIds = [];
            foreach (($s['sa'] ?? []) as $sa) {
                $padded = str_pad($sa, 6, '0', STR_PAD_LEFT);
                $gtfsIds[] = "9022001{$padded}001";
                $gtfsIds[] = "9022001{$padded}002";
            }
            // Fallback to siteId if no stop_areas
            if (empty($gtfsIds)) {
                $padded = str_pad($s['s'], 6, '0', STR_PAD_LEFT);
                $gtfsIds = ["9022001{$padded}001","9022001{$padded}002"];
            }
            $stops[] = ['id'=>implode(',',$gtfsIds),'siteId'=>$s['s'],'name'=>$s['n'],'lat'=>$s['la'],'lon'=>$s['lo']];
            if (++$count >= 15) break;
        }
    }
    echo json_encode(['stops'=>$stops], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'lines') {
    $siteId = $_GET['siteId'] ?? '';
    if (!$siteId) { echo json_encode(['lines' => []]); exit; }
    $url = "https://transport.integration.sl.se/v1/sites/{$siteId}/departures?transport=BUS&forecast=120";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5, CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($resp === false || $code !== 200) { echo json_encode(['error'=>'SL departures error','code'=>$code]); exit; }
    $data = json_decode($resp, true); $lineSet = [];
    foreach (($data['departures'] ?? []) as $dep) {
        $line = $dep['line']['designation'] ?? null;
        if ($line && !isset($lineSet[$line])) $lineSet[$line] = ['num'=>$line,'transport'=>$dep['line']['transport_mode']??'BUS'];
    }
    $lines = array_values($lineSet);
    usort($lines, function($a,$b){ return strnatcmp($a['num'],$b['num']); });
    echo json_encode(['lines'=>$lines], JSON_UNESCAPED_UNICODE); exit;
}

// ---- Vehicle positions (original functionality) ----
$RT_KEY = 'YOUR_TRAFIKLAB_GTFS_RT_KEY';
$VP_URL = "https://opendata.samtrafiken.se/gtfs-rt/sl/VehiclePositions.pb?key={$RT_KEY}";
$TU_URL = "https://opendata.samtrafiken.se/gtfs-rt/sl/TripUpdates.pb?key={$RT_KEY}";

// ---- API call logging & monitoring ----
$STATS_FILE = sys_get_temp_dir() . '/bussigt_api_stats.json';
function logApiCall($type) {
    global $STATS_FILE;
    $stats = [];
    if (file_exists($STATS_FILE)) {
        $stats = json_decode(file_get_contents($STATS_FILE), true) ?: [];
    }
    $today = date('Y-m-d');
    if (!isset($stats[$today])) $stats[$today] = ['trafiklab' => 0, 'cached' => 0, 'requests' => 0];
    $stats[$today][$type]++;
    // Keep only last 30 days
    $keys = array_keys($stats);
    if (count($keys) > 30) {
        $stats = array_slice($stats, -30, 30, true);
    }
    file_put_contents($STATS_FILE, json_encode($stats));
}

// ---- Server-side cache (5s TTL) ----
// All users share the same Trafiklab feed — massively reduces API calls
$CACHE_TTL = 5; // seconds
$VP_CACHE = sys_get_temp_dir() . '/bussigt_vp_cache.pb';
$TU_CACHE = sys_get_temp_dir() . '/bussigt_tu_cache.pb';

function getCachedOrFetch($url, $cacheFile, $ttl) {
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        logApiCall('cached');
        return file_get_contents($cacheFile);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_ENCODING=>'gzip', CURLOPT_TIMEOUT=>8]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($data !== false && $code === 200) {
        file_put_contents($cacheFile, $data);
        logApiCall('trafiklab');
    }
    return ($data !== false && $code === 200) ? $data : false;
}

// Stats endpoint
if ($action === 'stats') {
    $stats = file_exists($STATS_FILE) ? json_decode(file_get_contents($STATS_FILE), true) : [];
    $today = $stats[date('Y-m-d')] ?? ['trafiklab' => 0, 'cached' => 0, 'requests' => 0];
    $monthTotal = 0;
    foreach ($stats as $day => $s) {
        if (substr($day, 0, 7) === date('Y-m')) $monthTotal += $s['trafiklab'];
    }
    echo json_encode([
        'today' => $today,
        'month_trafiklab_calls' => $monthTotal,
        'month_limit_bronze' => 30000,
        'month_limit_silver' => 2000000,
        'usage_pct_bronze' => round($monthTotal / 30000 * 100, 1),
        'daily' => $stats,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Stop IDs — from query param or default to Drottninghamsvägen
$HOME_STOPS = isset($_GET['stop']) ? array_filter(explode(',', $_GET['stop'])) : ['9022001040205001', '9022001040205002', '9021001040205000'];

// Requested lines — from query param or default
$requestedLines = isset($_GET['lines']) ? array_filter(explode(',', $_GET['lines'])) : null;

// Log page request
logApiCall('requests');

// Load trip→line mapping
$mapFile = __DIR__ . '/trip_line_map.json';
if (!file_exists($mapFile)) {
    echo json_encode(['error' => 'trip_line_map.json missing']);
    exit;
}
$tripLineMap = json_decode(file_get_contents($mapFile), true);

// ---- Protobuf helpers ----
function readVarint(&$data, &$offset, $len) {
    $result = 0; $shift = 0;
    while ($offset < $len) {
        $byte = ord($data[$offset++]);
        $result |= ($byte & 0x7F) << $shift;
        if (($byte & 0x80) === 0) break;
        $shift += 7;
    }
    return $result;
}

function readBytes(&$data, &$offset, $len) {
    $length = readVarint($data, $offset, $len);
    $bytes = substr($data, $offset, $length);
    $offset += $length;
    return $bytes;
}

function skipField(&$data, &$offset, $len, $wireType) {
    switch ($wireType) {
        case 0: readVarint($data, $offset, $len); break;
        case 1: $offset += 8; break;
        case 2: $l = readVarint($data, $offset, $len); $offset += $l; break;
        case 5: $offset += 4; break;
    }
}

// ---- Stop name lookup (from SL API cache) ----
function getStopNameMap() {
    $cacheFile = sys_get_temp_dir() . '/bussigt_stops_v2.json';
    if (!file_exists($cacheFile)) return [];
    $allStops = json_decode(file_get_contents($cacheFile), true);
    if (!$allStops) return [];
    // Build GTFS stop_id → name map
    $map = [];
    foreach ($allStops as $s) {
        foreach (($s['sa'] ?? []) as $sa) {
            $padded = str_pad($sa, 6, '0', STR_PAD_LEFT);
            $map["9022001{$padded}001"] = $s['n'];
            $map["9022001{$padded}002"] = $s['n'];
        }
        // Also map by siteId
        $padded = str_pad($s['s'], 6, '0', STR_PAD_LEFT);
        $map["9022001{$padded}001"] = $s['n'];
        $map["9022001{$padded}002"] = $s['n'];
    }
    return $map;
}

// ---- Parse a single StopTimeUpdate, return [stopId, arrTime, depTime] ----
function parseStopTimeUpdate($stuData) {
    $so = 0; $sl2 = strlen($stuData);
    $stopId = ''; $arrTime = 0; $depTime = 0;
    while ($so < $sl2) {
        $stag = readVarint($stuData, $so, $sl2);
        $sf = $stag >> 3; $sw = $stag & 7;
        if ($sf === 4 && $sw === 2) {
            $stopId = readBytes($stuData, $so, $sl2);
        } elseif ($sf === 2 && $sw === 2) {
            $aData = readBytes($stuData, $so, $sl2);
            $ao = 0; $al = strlen($aData);
            while ($ao < $al) {
                $atag = readVarint($aData, $ao, $al);
                $af = $atag >> 3; $aw = $atag & 7;
                if ($af === 2 && $aw === 0) { $arrTime = readVarint($aData, $ao, $al); }
                else { skipField($aData, $ao, $al, $aw); }
            }
        } elseif ($sf === 3 && $sw === 2) {
            $dData = readBytes($stuData, $so, $sl2);
            $do2 = 0; $dl = strlen($dData);
            while ($do2 < $dl) {
                $dtag = readVarint($dData, $do2, $dl);
                $df = $dtag >> 3; $dw = $dtag & 7;
                if ($df === 2 && $dw === 0) { $depTime = readVarint($dData, $do2, $dl); }
                else { skipField($dData, $do2, $dl, $dw); }
            }
        } else {
            skipField($stuData, $so, $sl2, $sw);
        }
    }
    return [$stopId, $arrTime, $depTime];
}

// ---- Parse TripUpdates for ETAs + terminus info ----
function fetchEtas($data, $tripLineMap, $homeStops, $requestedLines) {
    if ($data === false) return [[], []];

    $etas = []; // trip_id → arrival_time at home stop
    $terminus = []; // trip_id → ['stop_id'=>..., 'arr_time'=>...]
    $offset = 0; $len = strlen($data);

    while ($offset < $len) {
        $tag = readVarint($data, $offset, $len);
        $field = $tag >> 3; $wire = $tag & 7;
        if ($field === 2 && $wire === 2) {
            $entityData = readBytes($data, $offset, $len);
            $eo = 0; $el = strlen($entityData);
            while ($eo < $el) {
                $etag = readVarint($entityData, $eo, $el);
                $ef = $etag >> 3; $ew = $etag & 7;
                if ($ef === 3 && $ew === 2) {
                    $tuData = readBytes($entityData, $eo, $el);
                    $to = 0; $tl = strlen($tuData);
                    $tripId = '';
                    $stopUpdates = [];
                    while ($to < $tl) {
                        $ttag = readVarint($tuData, $to, $tl);
                        $tf = $ttag >> 3; $tw = $ttag & 7;
                        if ($tf === 1 && $tw === 2) {
                            $tdData = readBytes($tuData, $to, $tl);
                            $tdo = 0; $tdl = strlen($tdData);
                            while ($tdo < $tdl) {
                                $tdtag = readVarint($tdData, $tdo, $tdl);
                                $tdf = $tdtag >> 3; $tdw = $tdtag & 7;
                                if ($tdf === 1 && $tdw === 2) { $tripId = readBytes($tdData, $tdo, $tdl); }
                                else { skipField($tdData, $tdo, $tdl, $tdw); }
                            }
                        } elseif ($tf === 2 && $tw === 2) {
                            $stuData = readBytes($tuData, $to, $tl);
                            $stopUpdates[] = $stuData;
                        } else {
                            skipField($tuData, $to, $tl, $tw);
                        }
                    }
                    $etaLine = null;
                    if ($tripId && isset($tripLineMap[$tripId])) {
                        $mapVal = $tripLineMap[$tripId];
                        $etaLine = (strpos($mapVal, '|') !== false) ? explode('|', $mapVal, 2)[0] : $mapVal;
                    }
                    if (!$etaLine && isset($routeId) && strlen($routeId) > 12) $etaLine = ltrim(substr($routeId, 7, 5), '0');
                    if ($etaLine && (!$requestedLines || in_array($etaLine, $requestedLines))) {
                        $lastStopId = ''; $lastArrTime = 0;
                        foreach ($stopUpdates as $stuData) {
                            list($stopId, $arrTime, $depTime) = parseStopTimeUpdate($stuData);
                            // Check home stop ETA
                            if (in_array($stopId, $homeStops)) {
                                $t = $arrTime ?: $depTime;
                                if ($t > 0) $etas[$tripId] = $t;
                            }
                            // Track last stop (terminus) — the last update with arrival time
                            $t = $arrTime ?: $depTime;
                            if ($stopId && $t > 0) {
                                $lastStopId = $stopId;
                                $lastArrTime = $t;
                            }
                        }
                        // Store terminus info
                        if ($lastStopId && $lastArrTime > 0) {
                            $terminus[$tripId] = ['stop_id' => $lastStopId, 'arr_time' => $lastArrTime];
                        }
                    }
                } else {
                    skipField($entityData, $eo, $el, $ew);
                }
            }
        } else {
            skipField($data, $offset, $len, $wire);
        }
    }
    return [$etas, $terminus];
}

// ---- Parse VehiclePositions ----
function fetchVehicles($data, $tripLineMap, $etas, $terminus, $stopNames, $requestedLines = null) {
    if ($data === false) return [];

    $vehicles = [];
    $statusNames = [0=>'INCOMING_AT', 1=>'STOPPED_AT', 2=>'IN_TRANSIT_TO'];
    $offset = 0; $len = strlen($data);

    while ($offset < $len) {
        $tag = readVarint($data, $offset, $len);
        $field = $tag >> 3; $wire = $tag & 7;
        if ($field === 2 && $wire === 2) {
            $entityData = readBytes($data, $offset, $len);
            $eo = 0; $el = strlen($entityData);
            $entityId = ''; $vpData = null;
            while ($eo < $el) {
                $etag = readVarint($entityData, $eo, $el);
                $ef = $etag >> 3; $ew = $etag & 7;
                if ($ef === 1 && $ew === 2) { $entityId = readBytes($entityData, $eo, $el); }
                elseif ($ef === 4 && $ew === 2) { $vpData = readBytes($entityData, $eo, $el); }
                else { skipField($entityData, $eo, $el, $ew); }
            }
            if (!$vpData) continue;

            // Parse VehiclePosition
            $vo = 0; $vl = strlen($vpData);
            $tripId = ''; $routeId = ''; $lat = 0; $lon = 0;
            $bearing = null; $speed = null; $status = 2; $ts = 0; $vid = '';
            while ($vo < $vl) {
                $vtag = readVarint($vpData, $vo, $vl);
                $vf = $vtag >> 3; $vw = $vtag & 7;
                if ($vf === 1 && $vw === 2) {
                    $td = readBytes($vpData, $vo, $vl);
                    $tdo = 0; $tdl = strlen($td);
                    while ($tdo < $tdl) {
                        $ttag = readVarint($td, $tdo, $tdl);
                        $tf = $ttag >> 3; $tw = $ttag & 7;
                        if ($tf === 1 && $tw === 2) $tripId = readBytes($td, $tdo, $tdl);
                        elseif ($tf === 5 && $tw === 2) $routeId = readBytes($td, $tdo, $tdl);
                        else skipField($td, $tdo, $tdl, $tw);
                    }
                } elseif ($vf === 2 && $vw === 2) {
                    $pd = readBytes($vpData, $vo, $vl);
                    $po = 0; $pl = strlen($pd);
                    while ($po < $pl) {
                        $ptag = readVarint($pd, $po, $pl);
                        $pf = $ptag >> 3; $pw = $ptag & 7;
                        if ($pf===1&&$pw===5) { $lat = unpack('f',substr($pd,$po,4))[1]; $po+=4; }
                        elseif ($pf===2&&$pw===5) { $lon = unpack('f',substr($pd,$po,4))[1]; $po+=4; }
                        elseif ($pf===3&&$pw===5) { $bearing = unpack('f',substr($pd,$po,4))[1]; $po+=4; }
                        elseif ($pf===4&&$pw===1) { $po+=8; }
                        elseif ($pf===5&&$pw===5) { $speed = unpack('f',substr($pd,$po,4))[1]; $po+=4; }
                        else skipField($pd, $po, $pl, $pw);
                    }
                } elseif ($vf===4&&$vw===0) { $status = readVarint($vpData, $vo, $vl); }
                elseif ($vf===5&&$vw===0) { $ts = readVarint($vpData, $vo, $vl); }
                elseif ($vf===8&&$vw===2) {
                    $vdd = readBytes($vpData, $vo, $vl);
                    $vdo = 0; $vdl = strlen($vdd);
                    while ($vdo < $vdl) {
                        $vdtag = readVarint($vdd, $vdo, $vdl);
                        $vdf = $vdtag >> 3; $vdw = $vdtag & 7;
                        if ($vdf===1&&$vdw===2) $vid = readBytes($vdd, $vdo, $vdl);
                        else skipField($vdd, $vdo, $vdl, $vdw);
                    }
                } else skipField($vpData, $vo, $vl, $vw);
            }

            $line = null; $staticDest = null;
            if ($tripId && isset($tripLineMap[$tripId])) {
                $mapVal = $tripLineMap[$tripId];
                if (strpos($mapVal, '|') !== false) {
                    list($line, $staticDest) = explode('|', $mapVal, 2);
                } else {
                    $line = $mapVal;
                }
            }
            if (!$line && $routeId && strlen($routeId) > 12) {
                $line = ltrim(substr($routeId, 7, 5), '0');
            }
            if (!$line) continue;
            if ($requestedLines && !in_array($line, $requestedLines)) continue;

            $v = [
                'id' => $vid ?: $entityId,
                'line' => $line,
                'lat' => round($lat, 6),
                'lon' => round($lon, 6),
                'bearing' => $bearing !== null ? round($bearing, 1) : null,
                'speed' => $speed !== null ? round($speed, 2) : null,
                'status' => $statusNames[$status] ?? 'IN_TRANSIT_TO',
                'timestamp' => $ts,
            ];

            // Add ETA if available
            if ($tripId && isset($etas[$tripId])) {
                $eta = $etas[$tripId];
                $mins = round(($eta - time()) / 60);
                if ($mins >= 0 && $mins <= 120) {
                    $v['eta_minutes'] = $mins;
                    $v['eta_time'] = date('H:i', $eta);
                }
            }

            // Add destination info (from static GTFS + realtime terminus ETA)
            if ($staticDest) {
                $v['destination'] = $staticDest;
            }
            if ($tripId && isset($terminus[$tripId])) {
                $term = $terminus[$tripId];
                // Use terminus stop name from realtime if no static dest
                if (!$staticDest) {
                    $termName = $stopNames[$term['stop_id']] ?? null;
                    if ($termName) $v['destination'] = $termName;
                }
                // Terminus ETA from realtime
                $termArr = $term['arr_time'];
                $termMins = round(($termArr - time()) / 60);
                if ($termMins >= 0 && $termMins <= 180) {
                    $v['dest_minutes'] = $termMins;
                    $v['dest_time'] = date('H:i', $termArr);
                }
            }

            $vehicles[] = $v;
        } else {
            skipField($data, $offset, $len, $wire);
        }
    }
    return $vehicles;
}

// ---- Main ----
$tuData = getCachedOrFetch($TU_URL, $TU_CACHE, $CACHE_TTL);
$vpData = getCachedOrFetch($VP_URL, $VP_CACHE, $CACHE_TTL);
list($etas, $terminus) = fetchEtas($tuData, $tripLineMap, $HOME_STOPS, $requestedLines);
$stopNames = !empty($terminus) ? getStopNameMap() : [];
$vehicles = fetchVehicles($vpData, $tripLineMap, $etas, $terminus, $stopNames, $requestedLines);

echo json_encode([
    'timestamp' => time(),
    'vehicles' => $vehicles,
], JSON_UNESCAPED_UNICODE);
