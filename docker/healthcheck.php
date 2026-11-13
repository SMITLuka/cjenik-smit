<?php
// Zdravlje kontejnera: web server odgovara (bilo koji HTTP status ispod 500 je u redu, npr. 401 na /api/v1/ping).
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 4]]);
@file_get_contents('http://127.0.0.1/api/v1/ping', false, $ctx);
$headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
$status = isset($headers[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m) ? (int)$m[1] : 0;
exit($status > 0 && $status < 500 ? 0 : 1);
