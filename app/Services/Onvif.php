<?php

declare(strict_types=1);

namespace Argus\Services;

use Argus\Support\Logger;
use Argus\Support\Settings;

/**
 * ONVIF discovery and profile lookup.
 *
 * find()     — WS-Discovery multicast probe (same broadcast domain only).
 * profiles() — GetCapabilities -> GetProfiles -> GetStreamUri/GetSnapshotUri,
 *              classified into a main stream and its sub-streams so a large
 *              wall can use the cheap profile.
 *
 * Credentials use WS-Security UsernameToken (password digest) and are also sent
 * as HTTP digest/basic, because firmware differs.
 */
final class Onvif
{
    private const DISCOVERY_TARGET = '239.255.255.250:3702';
    private const NS_DEVICE = 'http://www.onvif.org/ver10/device/wsdl';
    private const NS_MEDIA = 'http://www.onvif.org/ver10/media/wsdl';
    private const NS_SCHEMA = 'http://www.onvif.org/ver10/schema';

    /** @return array<int, array{xaddr: string, host: string, name: string, hardware: string, peer: string}> */
    public static function find(float $timeout = 3.0): array
    {
        $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);
        if (!$socket) {
            Logger::warning('onvif.discovery_unavailable', ['error' => $errstr ?: 'udp socket refused']);
            return [];
        }

        stream_set_blocking($socket, false);
        $sent = @stream_socket_sendto($socket, self::probeEnvelope(), 0, self::DISCOVERY_TARGET);

        if ($sent === false) {
            fclose($socket);
            Logger::warning('onvif.discovery_send_failed', []);
            return [];
        }

        $devices = [];
        $deadline = microtime(true) + max(0.5, min($timeout, 10.0));

        while (microtime(true) < $deadline) {
            $read = [$socket];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 0, 200000) < 1) {
                continue;
            }

            $peer = '';
            $raw = @stream_socket_recvfrom($socket, 65535, 0, $peer);
            if (!is_string($raw) || $raw === '') {
                continue;
            }

            $device = self::parseProbeMatch($raw, (string) $peer);
            if ($device !== null) {
                $devices[$device['xaddr']] = $device;
            }
        }

        fclose($socket);

        return array_values($devices);
    }

    /** @return array{ok: bool, error?: string, manufacturer?: string, model?: string, firmware?: string, serial?: string, host?: string} */
    public static function info(string $address, ?string $user = null, ?string $pass = null): array
    {
        $xaddr = self::address($address);
        if ($xaddr === '') {
            return ['ok' => false, 'error' => 'Enter a device address such as 10.0.0.20.'];
        }

        $response = self::call(
            $xaddr,
            self::NS_DEVICE . '/GetDeviceInformation',
            '<GetDeviceInformation xmlns="' . self::NS_DEVICE . '"/>',
            $user,
            $pass
        );

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error'] ?? 'GetDeviceInformation failed.'];
        }

        $xml = $response['xml'];

        return [
            'ok'           => true,
            'manufacturer' => self::text($xml, 'Manufacturer'),
            'model'        => self::text($xml, 'Model'),
            'firmware'     => self::text($xml, 'FirmwareVersion'),
            'serial'       => self::text($xml, 'SerialNumber'),
            'host'         => (string) (parse_url($xaddr, PHP_URL_HOST) ?: $address),
        ];
    }

    /**
     * Fetch every media profile with its stream and snapshot URI.
     *
     * @return array{ok: bool, error?: string, xaddr?: string, info?: array, profiles?: array<int, array>}
     */
    public static function profiles(string $address, ?string $user = null, ?string $pass = null): array
    {
        $xaddr = self::address($address);
        if ($xaddr === '') {
            return ['ok' => false, 'error' => 'Enter a device address such as 10.0.0.20 or http://10.0.0.20/onvif/device_service.'];
        }

        $info = self::info($xaddr, $user, $pass);
        $media = self::mediaService($xaddr, $user, $pass);

        if ($media === null) {
            return [
                'ok'    => false,
                'error' => 'The device does not expose an ONVIF media service. Check the address, the credentials and that ONVIF is enabled on the camera.',
                'info'  => $info,
            ];
        }

        $response = self::call(
            $media,
            self::NS_MEDIA . '/GetProfiles',
            '<GetProfiles xmlns="' . self::NS_MEDIA . '"/>',
            $user,
            $pass
        );

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error'] ?? 'GetProfiles failed.', 'info' => $info];
        }

        $profiles = [];

        foreach (self::elements($response['xml'], 'Profiles') as $node) {
            $token = (string) ($node->attributes()['token'] ?? '');
            if ($token === '') {
                continue;
            }

            $encoder = self::first($node, 'VideoEncoderConfiguration');
            $resolution = $encoder !== null ? self::first($encoder, 'Resolution') : null;
            $rate = $encoder !== null ? self::first($encoder, 'RateControl') : null;

            $width = $resolution !== null ? (int) self::value($resolution, 'Width') : 0;
            $height = $resolution !== null ? (int) self::value($resolution, 'Height') : 0;
            $fps = $rate !== null ? (int) round((float) self::value($rate, 'FrameRateLimit')) : 0;

            [$rtsp, $rtspUser, $rtspPass] = self::splitUri(self::streamUri($media, $token, $user, $pass));
            [$snapshot, $snapUser, $snapPass] = self::splitUri(self::snapshotUri($media, $token, $user, $pass));

            $profiles[] = [
                'token'    => $token,
                'name'     => self::value($node, 'Name') ?: $token,
                'encoding' => strtoupper(self::value($encoder, 'Encoding') ?: 'H264'),
                'width'    => $width,
                'height'   => $height,
                'fps'      => $fps,
                'rtsp'     => $rtsp,
                'snapshot' => $snapshot,
                'user'     => $rtspUser !== '' ? $rtspUser : ($snapUser !== '' ? $snapUser : ''),
                'password' => $rtspPass !== '' ? $rtspPass : $snapPass,
                'pixels'   => $width * $height,
            ];
        }

        if (!$profiles) {
            return [
                'ok'    => false,
                'error' => 'The camera reported no media profiles. Add it manually with its RTSP path instead.',
                'info'  => $info,
            ];
        }

        usort($profiles, static fn (array $a, array $b): int => $b['pixels'] <=> $a['pixels']);

        $labelledMain = false;
        foreach ($profiles as $index => $profile) {
            $hint = strtolower($profile['name']);
            $isMain = $profile['pixels'] > 0 && $profile['pixels'] === $profiles[0]['pixels'] && !$labelledMain;

            // Trust a profile that calls itself "sub" even if the sizes tie.
            if (str_contains($hint, 'sub') || str_contains($hint, 'low')) {
                $isMain = false;
            }

            $profiles[$index]['kind'] = $isMain ? 'main' : 'sub';
            if ($isMain) {
                $labelledMain = true;
            }
        }

        return [
            'ok'       => true,
            'xaddr'    => $xaddr,
            'media'    => $media,
            'info'     => $info,
            'profiles' => $profiles,
        ];
    }

    /** Accept "10.0.0.20", "10.0.0.20:8000" or a full ONVIF device service URL. */
    public static function address(string $input): string
    {
        $value = trim($input);
        if ($value === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $value)) {
            $value = 'http://' . $value;
        }

        $parts = parse_url($value);
        if (!$parts || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path === '/') {
            $path = '/onvif/device_service';
        }

        return $scheme . '://' . $parts['host'] . $port . $path;
    }

    /** Remove embedded credentials from a device-supplied URI. @return array{0: string, 1: string, 2: string} */
    public static function splitUri(string $uri): array
    {
        $uri = trim($uri);
        if ($uri === '') {
            return ['', '', ''];
        }

        $parts = parse_url($uri);
        if (!$parts || empty($parts['host'])) {
            return [$uri, '', ''];
        }

        $user = isset($parts['user']) ? rawurldecode((string) $parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '';

        if ($user === '' && $pass === '') {
            return [$uri, '', ''];
        }

        $clean = ($parts['scheme'] ?? 'rtsp') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . (string) ($parts['path'] ?? '');

        if (isset($parts['query'])) {
            $clean .= '?' . $parts['query'];
        }

        return [$clean, $user, $pass];
    }

    /* ------------------------------------------------------------------ soap */

    /** @return array{ok: bool, error?: string, xml?: \SimpleXMLElement, status?: int} */
    private static function call(string $url, string $action, string $body, ?string $user, ?string $pass, float $timeout = 6.0): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'The PHP cURL extension is required for ONVIF.'];
        }

        $verify = Settings::bool('verify_tls');
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => self::envelope($body, $user, $pass),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => (int) ceil($timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'Argus/' . ARGUS_VERSION,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/soap+xml; charset=utf-8; action="' . $action . '"',
                'Accept: application/soap+xml, text/xml',
            ],
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);

        if ($user !== null && $user !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . (string) $pass);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return [
                'ok'     => false,
                'status' => 0,
                'error'  => $error !== '' ? $error : 'The device did not answer the ONVIF request.',
            ];
        }

        $xml = self::xml((string) $raw);
        if ($xml === null) {
            return [
                'ok'     => false,
                'status' => $status,
                'error'  => 'The device answered with something that is not SOAP XML (HTTP ' . $status . '). Wrong port or ONVIF disabled?',
            ];
        }

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'status' => $status, 'xml' => $xml, 'error' => 'The camera rejected those credentials (HTTP ' . $status . ').'];
        }

        $fault = self::first($xml, 'Fault');
        if ($fault !== null) {
            $reason = self::value($fault, 'Text') ?: self::value($fault, 'faultstring');

            return [
                'ok'     => false,
                'status' => $status,
                'xml'    => $xml,
                'error'  => 'ONVIF fault' . ($reason !== '' ? ': ' . $reason : ''),
            ];
        }

        if ($status >= 400) {
            return ['ok' => false, 'status' => $status, 'xml' => $xml, 'error' => 'ONVIF request failed (HTTP ' . $status . ').'];
        }

        return ['ok' => true, 'status' => $status, 'xml' => $xml];
    }

    private static function mediaService(string $xaddr, ?string $user, ?string $pass): ?string
    {
        $response = self::call(
            $xaddr,
            self::NS_DEVICE . '/GetCapabilities',
            '<GetCapabilities xmlns="' . self::NS_DEVICE . '"><Category>Media</Category></GetCapabilities>',
            $user,
            $pass
        );

        if ($response['ok']) {
            $media = self::first($response['xml'], 'Media');
            if ($media !== null) {
                $addr = self::value($media, 'XAddr');
                if ($addr !== '') {
                    return $addr;
                }
            }
        }

        // Some firmware omits capabilities; the media service usually lives here.
        $response = self::call(
            self::origin($xaddr) . '/onvif/media_service',
            self::NS_MEDIA . '/GetProfiles',
            '<GetProfiles xmlns="' . self::NS_MEDIA . '"/>',
            $user,
            $pass
        );

        return $response['ok'] ? self::origin($xaddr) . '/onvif/media_service' : null;
    }

    private static function streamUri(string $media, string $token, ?string $user, ?string $pass): string
    {
        $body = '<GetStreamUri xmlns="' . self::NS_MEDIA . '">'
            . '<StreamSetup><Stream xmlns="' . self::NS_SCHEMA . '">RTP-Unicast</Stream>'
            . '<Transport xmlns="' . self::NS_SCHEMA . '"><Protocol>RTSP</Protocol></Transport></StreamSetup>'
            . '<ProfileToken>' . self::esc($token) . '</ProfileToken></GetStreamUri>';

        $response = self::call($media, self::NS_MEDIA . '/GetStreamUri', $body, $user, $pass);
        if (!$response['ok']) {
            return '';
        }

        return trim(self::value($response['xml'], 'Uri'));
    }

    private static function snapshotUri(string $media, string $token, ?string $user, ?string $pass): string
    {
        $body = '<GetSnapshotUri xmlns="' . self::NS_MEDIA . '">'
            . '<ProfileToken>' . self::esc($token) . '</ProfileToken></GetSnapshotUri>';

        $response = self::call($media, self::NS_MEDIA . '/GetSnapshotUri', $body, $user, $pass);
        if (!$response['ok']) {
            return '';
        }

        return trim(self::value($response['xml'], 'Uri'));
    }

    private static function probeEnvelope(): string
    {
        $messageId = 'uuid:' . self::uuid();

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing"'
            . ' xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery"'
            . ' xmlns:dn="http://www.onvif.org/ver10/network/wsdl">'
            . '<e:Header>'
            . '<w:MessageID>' . $messageId . '</w:MessageID>'
            . '<w:To e:mustUnderstand="true">urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>'
            . '<w:Action e:mustUnderstand="true">http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action>'
            . '</e:Header>'
            . '<e:Body><d:Probe><d:Types>dn:NetworkVideoTransmitter</d:Types></d:Probe></e:Body>'
            . '</e:Envelope>';
    }

    private static function envelope(string $body, ?string $user, ?string $pass): string
    {
        $security = '';

        if ($user !== null && $user !== '') {
            $nonce = random_bytes(16);
            $created = gmdate('Y-m-d\TH:i:s\Z');
            $digest = base64_encode(sha1($nonce . $created . (string) $pass, true));

            $security = '<wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"'
                . ' xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd"'
                . ' s:mustUnderstand="1">'
                . '<wsse:UsernameToken>'
                . '<wsse:Username>' . self::esc($user) . '</wsse:Username>'
                . '<wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">'
                . $digest . '</wsse:Password>'
                . '<wsse:Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">'
                . base64_encode($nonce) . '</wsse:Nonce>'
                . '<wsu:Created>' . $created . '</wsu:Created>'
                . '</wsse:UsernameToken></wsse:Security>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
            . '<s:Header>' . $security . '</s:Header>'
            . '<s:Body>' . $body . '</s:Body>'
            . '</s:Envelope>';
    }

    /* ------------------------------------------------------------------ xml */

    private static function xml(string $raw): ?\SimpleXMLElement
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($raw);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc instanceof \SimpleXMLElement ? $doc : null;
    }

    private static function first(?object $node, string $localName): ?\SimpleXMLElement
    {
        if (!$node instanceof \SimpleXMLElement) {
            return null;
        }

        $matches = self::elements($node, $localName);

        return $matches[0] ?? null;
    }

    /** @return array<int, \SimpleXMLElement> */
    private static function elements(?\SimpleXMLElement $node, string $localName): array
    {
        if ($node === null) {
            return [];
        }

        $found = [];
        foreach ($node->xpath('.//*') ?: [] as $child) {
            if ($child->getName() === $localName) {
                $found[] = $child;
            }
        }

        return $found;
    }

    private static function value(?object $node, string $localName): string
    {
        $element = self::first($node, $localName);
        if ($element === null) {
            return '';
        }

        return trim((string) $element);
    }

    private static function text(\SimpleXMLElement $xml, string $localName): string
    {
        return self::value($xml, $localName);
    }

    private static function parseProbeMatch(string $raw, string $peer): ?array
    {
        $doc = self::xml($raw);
        if ($doc === null) {
            return null;
        }

        $xaddrs = self::value($doc, 'XAddrs');
        if ($xaddrs === '') {
            return null;
        }

        $xaddr = '';
        foreach (preg_split('/\s+/', $xaddrs) ?: [] as $candidate) {
            if (preg_match('#^https?://#i', $candidate)) {
                $xaddr = $candidate;
                break;
            }
        }

        if ($xaddr === '') {
            return null;
        }

        $scopes = self::value($doc, 'Scopes');
        $host = (string) (parse_url($xaddr, PHP_URL_HOST) ?: $peer);

        return [
            'xaddr'    => $xaddr,
            'host'     => $host,
            'name'     => self::scopeValue($scopes, '/name/') ?: $host,
            'hardware' => self::scopeValue($scopes, '/hardware/'),
            'peer'     => $peer,
        ];
    }

    private static function scopeValue(string $scopes, string $needle): string
    {
        foreach (preg_split('/\s+/', $scopes) ?: [] as $scope) {
            $position = strpos($scope, $needle);
            if ($position === false) {
                continue;
            }

            return trim(rawurldecode(substr($scope, $position + strlen($needle))));
        }

        return '';
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        return ($parts['scheme'] ?? 'http') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
