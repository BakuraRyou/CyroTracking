<?php

namespace CyroTracking\Controllers;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;

class MetaTrackingController extends Controller
{
    public function track(Request $request, Response $response, ConfigRepository $config)
    {
        $input = $this->getInput($request);

        if (!$this->isSameOrigin() || !$this->isEnabled($config) || !$this->isTruthy($this->getValue($input, 'consent', false))) {
            return $this->json($response, ['success' => false, 'message' => 'tracking_not_allowed'], 403);
        }

        $pixelId = trim((string)$config->get('CyroTracking.meta_pixel_id', ''));
        $accessToken = trim((string)$config->get('CyroTracking.meta_access_token', ''));
        $graphVersion = $this->sanitizeGraphVersion($config->get('CyroTracking.meta_graph_api_version', 'v20.0'));
        $serverEnabled = $this->isTruthy($config->get('CyroTracking.send_meta_server_events', true));

        if (!$serverEnabled || $pixelId === '' || $accessToken === '') {
            return $this->json($response, ['success' => false, 'message' => 'meta_server_disabled']);
        }

        $eventName = $this->sanitizeEventName($this->getValue($input, 'event_name', 'PageView'));
        $eventId = $this->sanitizeText($this->getValue($input, 'event_id', ''), 128);
        $eventSourceUrl = $this->sanitizeUrl($this->getValue($input, 'event_source_url', ''));
        $referrerUrl = $this->sanitizeUrl($this->getValue($input, 'referrer_url', ''));
        $customData = $this->getValue($input, 'custom_data', []);

        if (!is_array($customData)) {
            $customData = [];
        }

        $payload = [
            'data' => [[
                'event_name' => $eventName,
                'event_time' => time(),
                'event_id' => $eventId,
                'action_source' => 'website',
                'event_source_url' => $eventSourceUrl,
                'user_data' => $this->buildUserData($input),
                'custom_data' => $this->sanitizeCustomData($customData)
            ]]
        ];

        if ($referrerUrl !== '') {
            $payload['data'][0]['referrer_url'] = $referrerUrl;
        }

        $testEventCode = trim((string)$config->get('CyroTracking.meta_test_event_code', ''));
        if ($testEventCode !== '') {
            $payload['test_event_code'] = $testEventCode;
        }

        $url = 'https://graph.facebook.com/' . $graphVersion . '/' . rawurlencode($pixelId) . '/events?access_token=' . rawurlencode($accessToken);
        $result = $this->postJson($url, $payload);

        return $this->json($response, [
            'success' => $result['success'],
            'status' => $result['status'],
            'response' => $result['response']
        ], $result['success'] ? 200 : 502);
    }

    private function buildUserData(array $input)
    {
        $userData = [
            'client_ip_address' => $this->getClientIp(),
            'client_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
        ];

        $fbp = $this->sanitizeText($this->getValue($input, 'fbp', ''), 255);
        $fbc = $this->sanitizeText($this->getValue($input, 'fbc', ''), 255);

        if ($fbp !== '') {
            $userData['fbp'] = $fbp;
        }

        if ($fbc !== '') {
            $userData['fbc'] = $fbc;
        }

        $hashedUserData = $this->sanitizeHashedUserData($this->getValue($input, 'user_data', []));

        foreach ($hashedUserData as $key => $value) {
            if (!empty($value)) {
                $userData[$key] = $value;
            }
        }

        return array_filter($userData);
    }

    private function getInput(Request $request)
    {
        $customData = json_decode((string)$request->get('custom_data', ''), true);
        $userData = json_decode((string)$request->get('user_data', ''), true);

        return [
            'consent' => $request->get('consent', false),
            'event_name' => $request->get('event_name', 'PageView'),
            'event_id' => $request->get('event_id', ''),
            'event_source_url' => $request->get('event_source_url', ''),
            'referrer_url' => $request->get('referrer_url', ''),
            'fbp' => $request->get('fbp', ''),
            'fbc' => $request->get('fbc', ''),
            'custom_data' => is_array($customData) ? $customData : [],
            'user_data' => is_array($userData) ? $userData : []
        ];
    }

    private function getValue(array $data, $key, $default = null)
    {
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    private function getClientIp()
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $parts = explode(',', $_SERVER[$key]);
                return trim($parts[0]);
            }
        }

        return '';
    }

    private function sanitizeCustomData(array $data)
    {
        $allowed = [
            'currency',
            'value',
            'content_type',
            'content_ids',
            'contents',
            'search_string',
            'num_items',
            'order_id',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_id',
            'utm_content',
            'utm_term',
            'gclid',
            'fbclid',
            'msclkid',
            'landing_page',
            'original_referrer'
        ];
        $clean = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $clean[$key] = $this->sanitizeCustomValue($key, $data[$key]);
            }
        }

        return $clean;
    }

    private function sanitizeCustomValue($key, $value)
    {
        if ($key === 'currency') {
            return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)$value), 0, 3));
        }

        if ($key === 'value') {
            return (float)$value;
        }

        if ($key === 'num_items') {
            return (int)$value;
        }

        if ($key === 'content_ids') {
            return $this->sanitizeTextArray($value, 128);
        }

        if ($key === 'contents') {
            return $this->sanitizeContents($value);
        }

        if (is_array($value)) {
            return $this->sanitizeTextArray($value, 500);
        }

        return $this->sanitizeText($value, 500);
    }

    private function sanitizeTextArray($value, $maxLength)
    {
        if (!is_array($value)) {
            return [];
        }

        $clean = [];

        foreach ($value as $item) {
            if (!is_array($item) && !is_object($item)) {
                $clean[] = $this->sanitizeText($item, $maxLength);
            }
        }

        return $clean;
    }

    private function sanitizeContents($value)
    {
        if (!is_array($value)) {
            return [];
        }

        $clean = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = [];

            if (array_key_exists('id', $item)) {
                $row['id'] = $this->sanitizeText($item['id'], 128);
            }

            if (array_key_exists('quantity', $item)) {
                $row['quantity'] = (int)$item['quantity'];
            }

            if (array_key_exists('item_price', $item)) {
                $row['item_price'] = (float)$item['item_price'];
            }

            if (!empty($row)) {
                $clean[] = $row;
            }
        }

        return $clean;
    }

    private function sanitizeHashedUserData($data)
    {
        if (!is_array($data)) {
            return [];
        }

        $allowed = ['em', 'ph', 'fn', 'ln', 'external_id'];
        $clean = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $value = $this->sanitizeHashValue($data[$key]);

                if ($value !== null) {
                    $clean[$key] = $value;
                }
            }
        }

        return $clean;
    }

    private function sanitizeHashValue($value)
    {
        if (is_array($value)) {
            $clean = [];

            foreach ($value as $item) {
                $hash = $this->sanitizeHash($item);

                if ($hash !== '') {
                    $clean[] = $hash;
                }
            }

            return !empty($clean) ? $clean : null;
        }

        $hash = $this->sanitizeHash($value);

        return $hash !== '' ? $hash : null;
    }

    private function sanitizeHash($value)
    {
        $value = strtolower(trim((string)$value));

        return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
    }

    private function postJson($url, array $payload)
    {
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 4);

        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        return [
            'success' => $status >= 200 && $status < 300,
            'status' => $status,
            'response' => $response !== false ? $response : $error
        ];
    }

    private function json(Response $response, array $payload, $status = 200)
    {
        return $response->make(
            json_encode($payload),
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store'
            ]
        );
    }

    private function isEnabled(ConfigRepository $config)
    {
        $enabled = $config->get('CyroTracking.enabled', true);

        return $enabled === null || $enabled === '' || $this->isTruthy($enabled);
    }

    private function isSameOrigin()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST) : '';
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) : '';

        if ($origin !== '' && strcasecmp($origin, $host) !== 0) {
            return false;
        }

        if ($referer !== '' && strcasecmp($referer, $host) !== 0) {
            return false;
        }

        return true;
    }

    private function isTruthy($value)
    {
        return $value === true || $value === 'true' || $value === '1' || $value === 1;
    }

    private function sanitizeEventName($value)
    {
        $value = preg_replace('/[^A-Za-z0-9_]/', '', (string)$value);

        return $value !== '' ? substr($value, 0, 64) : 'PageView';
    }

    private function sanitizeUrl($value)
    {
        return substr(filter_var((string)$value, FILTER_SANITIZE_URL), 0, 2048);
    }

    private function sanitizeGraphVersion($value)
    {
        $value = trim((string)$value);

        if (!preg_match('/^v[0-9]+\.[0-9]+$/', $value)) {
            return 'v20.0';
        }

        return $value;
    }

    private function sanitizeText($value, $maxLength)
    {
        return substr(trim((string)$value), 0, $maxLength);
    }
}
