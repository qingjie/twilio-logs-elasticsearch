<?php
/**
 * Created by PhpStorm.
 * User: allen.torres
 * Date: 9/14/17
 * Time: 11:48 AM
 */
date_default_timezone_set("UTC");

$opts = getopt("h", ["eshost::", "esauth::", "sentdate::", "twiliosid:", "twiliotoken:"]);
//die(print_r($opts, true));

if (isset($opts['h'])) {
    echo "Twilio Message Log Elastic Exporter\n
    --eshost: http://localhost:9200\n
    [optional] --esauth: base64 encoded username:password\n
    [optional] --sentdate: defaults to -1 day\n
    --twiliosid: Twilio Account SID\n
    --twiliotoken: Twilio Token\n";
    exit;
}

if (!isset($opts['twiliosid']) || !isset($opts['twiliotoken'])) {
    echo "--twiliosid and --twiliotoken are required.\n";
    exit;
}


$time = (isset($opts['sentdate'])) ? strtotime($opts['sentdate']) : strtotime('-1 day');
$logDate = date('Y.m.d', $time);

$twilioAuth = base64_encode($opts['twiliosid'].":".$opts['twiliotoken']);
$twilioMessageUri = "/2010-04-01/Accounts/{$opts['twiliosid']}/SMS/Messages.json";

$elasticAuth = (isset($opts['esauth'])) ? $opts['esauth'] : "";
$elasticSearchUrl = (isset($opts['eshost'])) ? $opts['eshost'] : "http://localhost:9200";

$esIndex = "twilio-logs-$logDate";


/**
 * Get Twilio Data.
 *
 * @param $uri
 * @param array $params
 * @return array|null
 */
function twilio_get($uri, $params = []) {
    global $twilioAuth;

    $response = null;

    $url = "https://api.twilio.com" . $uri;
    if (!empty($params)) {
        $url = $url . "?" . http_build_query($params);
    }

    echo "Fetching logs for: $url\n";
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPGET => 1,
        CURLOPT_HTTPHEADER => [
            "Authorization: Basic $twilioAuth"
        ]
    ];
    curl_setopt_array($ch, $opts);

    $exec = curl_exec($ch);

    if ($exec !== false) {
        $response = [
            'body' => $exec,
            'info' => curl_getinfo($ch)
        ];
    }else{
        echo "Failed to fetch logs for $url\n";
    }

    curl_close($ch);

    return $response;
}

/**
 * Post data to an elasticsearch cluster.
 *
 * @param $url
 * @param $data
 * @return array|null
 */
function elastic_post($url, $data) {
    global $elasticAuth;

    $response = null;

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => [
            "Authorization: Basic $elasticAuth",
            'Content-Type: application/x-ndjson'
        ]
    ];
    curl_setopt_array($ch, $opts);

    $exec = curl_exec($ch);

    if ($exec !== false) {
        $response = [
            'body' => $exec,
            'info' => curl_getinfo($ch)
        ];
    }

    curl_close($ch);

    return $response;

}

/**
 * Recursive method to index log data from Twilio.
 *
 * @param $esUrl
 * @param $esIndex
 * @param $docType
 * @param $docId
 * @param $logs
 * @param null $nextUri
 */
function index_logs ($esUrl, $esIndex, $docType, $docId, $logs, $nextUri = null) {
    $bulkPost = "";
    if (is_array($logs)) {
        foreach ($logs as $message) {
            $action = [
                'index' => [
                    '_index' => $esIndex,
                    '_type' => $docType,
                    '_id' => $message[$docId]
                ]
            ];
            $bulkPost .= json_encode($action) . "\n";

            //format dates
            foreach (['date_created', 'date_updated', 'date_sent'] as $arrKey) {
                $message[$arrKey] = strtotime($message[$arrKey]);
            }
            $bulkPost .= json_encode($message) . "\n";
        }
    }

    if (!empty($bulkPost)) {
        $index = elastic_post($esUrl . "/$esIndex/_bulk", $bulkPost);
        if (!$index || (isset($index['error']) && $index['error'])) {
            echo "Faild to index logs in $esUrl. Response Info: " . print_r($index, true) . "\n";
        }
    }

    echo "Next URI: $nextUri\n";
    if (!empty($nextUri)) {
        $nextLogs = twilio_get($nextUri);
        if ($nextLogs) {
            $nextLogData = json_decode($nextLogs['body'], true);
            index_logs($esUrl, $esIndex, $docType, $docId, $nextLogData['sms_messages'], $nextLogData['next_page_uri']);
        }else {
            echo "Error fetching Twilio logs for {$nextLogs['next_page_uri']}. Curl Info: " . print_r($nextLogs['info'], true) . "\n";
        }
    }
}


echo "Fetching messages sent on: $logDate \n";
echo "Writing logs to : $elasticSearchUrl/$esIndex\n";

$logs = twilio_get($twilioMessageUri, ['DateSent' => $logDate]);

if ($logs) {
    $logData = json_decode($logs['body'], true);
    if (!empty($logData['sms_messages'])) {
        index_logs($elasticSearchUrl, $esIndex, 'sms', 'sid', $logData['sms_messages'], $logData['next_page_uri']);
    }
}else{
    echo "Error fetching Twilio logs for $logDate. Curl Info: " . print_r($logs['info'], true) . "\n";
}