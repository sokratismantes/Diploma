<?php
$apiKey = 'AIzaSyCDo3tvi8PwAfMJbc7SJ9kmRH0IgIfp6HI';

$model = 'gemini-2.5-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

$payload = [
  "contents" => [
    [
      "parts" => [
        ["text" => "Πες μου μια σύντομη πρόταση για την Τεχνητή Νοημοσύνη."]
      ]
    ]
  ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json; charset=utf-8',
    'x-goog-api-key: ' . $apiKey
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP: {$httpCode}\n";
if ($err) {
  echo "cURL error: {$err}\n";
}
echo $response;
