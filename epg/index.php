<?php
/**
 * @file index.php
 * @brief 主页处理脚本
 * 
 * 该脚本处理来自客户端的请求，根据查询参数获取指定日期和频道的节目信息，
 * 并从 SQLite 数据库中提取或返回默认数据。
 * 
 * 作者: Tak
 * GitHub: https://github.com/taksssss/PHP-EPG-Docker-Server
 */

// 引入公共脚本
require_once 'public.php';

// 禁止输出错误提示
error_reporting(0);

// 初始化响应头信息
$init = [
    'status' => 200,
    'headers' => [
        'content-type' => 'application/json'
    ]
];

// 生成响应
function makeRes($body, $status = 200, $headers = []) {
    $headers['Access-Control-Allow-Origin'] = '*';
    http_response_code($status);
    foreach ($headers as $key => $value) {
        header("$key: $value");
    }
    echo $body;
}

// 获取当前日期
function getNowDate() {
    return date('Y-m-d');
}

// 格式化时间
function getFormatTime($time) {
    if (strlen($time) < 8) {
        return ['date' => getNowDate(), 'time' => ''];
    }

    $date = substr($time, 0, 4) . '-' . substr($time, 4, 2) . '-' . substr($time, 6, 2);
    $time = strlen($time) >= 12 ? substr($time, 8, 2) . ':' . substr($time, 10, 2) : '';

    return ['date' => $date, 'time' => $time];
}

// 从数据库读取 diyp、lovetv 数据，兼容未安装 memcached 的情况
function readEPGData($date, $channel, $db, $type) {
    global $Config;
    global $iconList;

    // 如果传入的日期小于当前日期，设置 cache_time 为 7 天
    $cache_time = ($date < date('Y-m-d')) ? 7 * 24 * 3600 : $Config['cache_time'];
    
    // 检查是否开启缓存并安装了 Memcached 类
    $memcached_enabled = $Config['cache_time'] && class_exists('Memcached');
    $cache_key = base64_encode("{$date}_{$channel}_{$type}");
    
    if ($memcached_enabled) {
        // 初始化 Memcached
        $memcached = new Memcached();
        $memcached->addServer('127.0.0.1', 11211); // 请确保 Memcached 服务器地址和端口正确

        // 从缓存中读取数据
        $cached_data = $memcached->get($cache_key);
        if ($cached_data) {
            return $cached_data;
        }
    }

    // 获取数据库类型（mysql 或 sqlite）
    $concat = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' 
        ? "CONCAT('%', LOWER(channel), '%')" 
        : "'%' || LOWER(channel) || '%'";

    // 优先精准匹配，其次正向模糊匹配，最后反向模糊匹配
    $stmt = $db->prepare("
        SELECT DISTINCT epg_diyp, channel
        FROM epg_data 
        WHERE date = :date 
        AND (
            LOWER(channel) = LOWER(:channel)
            OR LOWER(channel) LIKE LOWER(:like_channel)
            OR LOWER(:channel) LIKE $concat
        )
        ORDER BY 
            CASE 
                WHEN LOWER(channel) = LOWER(:channel) THEN 1 
                WHEN LOWER(channel) LIKE LOWER(:like_channel) THEN 2 
                ELSE 3 
            END, 
            CASE 
                WHEN LOWER(channel) = LOWER(:channel) THEN NULL
                WHEN LOWER(channel) LIKE LOWER(:like_channel) THEN LENGTH(channel)
                ELSE -LENGTH(channel)
            END
        LIMIT 1
    ");
    $stmt->execute([
        ':date' => $date, 
        ':channel' => $channel, 
        ':like_channel' => $channel . '%'
    ]);
    $row = $stmt->fetchColumn();

    if (!$row) {
        return false;
    }

    // 在解码和添加 icon 后再编码为 JSON
    $rowArray = json_decode($row, true);
    $iconUrl = $iconList[$rowArray['channel_name']] ?? "";
    $rowArray = array_merge(
        array_slice($rowArray, 0, array_search('url', array_keys($rowArray)) + 1),
        ['icon' => $iconUrl],
        array_slice($rowArray, array_search('url', array_keys($rowArray)) + 1)
    );
    $row = json_encode($rowArray, JSON_UNESCAPED_UNICODE);

    // 将处理后的数组重新编码为 JSON，输出或返回结果
    $row = json_encode($rowArray, JSON_UNESCAPED_UNICODE);

    if ($type === 'diyp') {
        // 如果 Memcached 可用，将结果存储到缓存中
        if ($memcached_enabled) {
            $memcached->set($cache_key, $row, $cache_time);
        }
        return $row;
    }

    if ($type === 'lovetv') {
        $diyp_data = json_decode($row, true);
        $date = $diyp_data['date'];
        $program = array_map(function($epg) use ($date) {
            $start_time = strtotime($date . ' ' . $epg['start']);
            $end_time = strtotime($date . ' ' . $epg['end']);
            $duration = $end_time - $start_time;
            return [
                'st' => $start_time,
                'et' => $end_time,
                'eventType' => '',
                'eventId' => '',
                't' => $epg['title'],
                'showTime' => gmdate('H:i', $duration),
                'duration' => $duration
            ];
        }, $diyp_data['epg_data']);

        // 查找当前节目
        $current_programme = $date === date('Y-m-d') ? findCurrentProgramme($program) : null;

        // 生成 lovetv 数据
        $lovetv_data = [
            $channel => [
                'isLive' => $current_programme ? $current_programme['t'] : '',
                'liveSt' => $current_programme ? $current_programme['st'] : 0,
                'channelName' => $diyp_data['channel_name'],
                'lvUrl' => $diyp_data['url'],
                'icon' => $diyp_data['icon'],
                'program' => $program
            ]
        ];

        $response = json_encode($lovetv_data, JSON_UNESCAPED_UNICODE);

        // 如果 Memcached 可用，将结果存储到缓存中
        if ($memcached_enabled) {
            $memcached->set($cache_key, $response, $cache_time);
        }

        return $response;
    }

    return false;
}

// 获取当前时间戳
function getNowTimestamp() {
    return time();
}

// 查找当前节目
function findCurrentProgramme($programmes) {
    $now = getNowTimestamp();
    foreach ($programmes as $programme) {
        if ($programme['st'] <= $now && $programme['et'] >= $now) {
            return $programme;
        }
    }
    return null;
}

// 处理请求
function fetchHandler() {
    global $init, $db, $Config, $iconList, $iconListDefault;

    $uri = parse_url($_SERVER['REQUEST_URI']);
    $query_params = [];
    if (isset($uri['query'])) {
        parse_str($uri['query'], $query_params);
    }

    // 获取并清理频道名称，繁体转换成简体
    $channel = cleanChannelName($query_params['ch'] ?? $query_params['channel'] ?? '', $t2s = true);

    $date = isset($query_params['date']) ? getFormatTime(preg_replace('/\D+/', '', $query_params['date']))['date'] : getNowDate();

    // 频道参数为空时，直接重定向到 t.xml 文件
    if (empty($channel)) {
        if ($Config['gen_xml'] == 1) {
            header('Content-Type: application/xml');
            header('Content-Disposition: attachment; filename="t.xml"');
            readfile('./t.xml');
        } else {
            // 输出消息并设置404状态码
            echo "404 Not Found. <br>未生成 xmltv 文件";
            http_response_code(404);
        }
        exit;
    }

    // 返回 diyp、lovetv 数据
    if (isset($query_params['ch']) || isset($query_params['channel'])) {
        $type = isset($query_params['ch']) ? 'diyp' : 'lovetv';
        $response = readEPGData($date, $channel, $db, $type);
    
        if ($response) {
            makeRes($response, $init['status'], $init['headers']);
        } else {
            $ret_default = !isset($Config['ret_default']) || $Config['ret_default'];
            $icon = $iconList[$channel] ?? $iconListDefault[$channel] ?? '';
            if ($type === 'diyp') {
                // 无法获取到数据时返回默认 diyp 数据
                $default_diyp_program_info = [
                    'date' => $date,
                    'channel_name' => $channel,
                    'url' => "https://github.com/taksssss/PHP-EPG-Server",
                    'icon' => $icon,
                    'epg_data' => !$ret_default ? '' : array_map(function($hour) {
                        return [
                            'start' => sprintf('%02d:00', $hour),
                            'end' => sprintf('%02d:00', ($hour + 1) % 24),
                            'title' => '精彩节目',
                            'desc' => ''
                        ];
                    }, range(0, 23, 1))
                ];
                $response = json_encode($default_diyp_program_info, JSON_UNESCAPED_UNICODE);
            } else {
                // 无法获取到数据时返回默认 lovetv 数据
                $default_lovetv_program_info = [
                    $channel => [
                        'isLive' => '',
                        'liveSt' => 0,
                        'channelName' => $channel,
                        'lvUrl' => 'https://github.com/taksssss/PHP-EPG-Docker-Server',
                        'icon' => $icon,
                        'program' => !$ret_default ? '' : array_map(function($hour) {
                            return [
                                'st' => strtotime(sprintf('%02d:00', $hour)),
                                'et' => strtotime(sprintf('%02d:00', ($hour + 1) % 24)),
                                't' => '精彩节目',
                                'd' => ''
                            ];
                        }, range(0, 23, 1))
                    ]
                ];
                $response = json_encode($default_lovetv_program_info, JSON_UNESCAPED_UNICODE);
            }
            makeRes($response, $init['status'], $init['headers']);
        }
    }

    // 默认响应
    makeRes('', $init['status'], $init['headers']);
}

// 执行请求处理
fetchHandler();

?>
