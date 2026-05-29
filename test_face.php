<?php
$url = 'http://190.145.135.122:8547/ISAPI/AccessControl/FaceInfo/Search?format=json';
$user = 'admin';
$pass = '900752620ch*';

$query = [
    "FaceInfoSearchCond" => [
        "searchID" => "1",
        "searchResultPosition" => 0,
        "maxResults" => 5
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: $status\n";
echo substr($response, 0, 1000) . "\n";
