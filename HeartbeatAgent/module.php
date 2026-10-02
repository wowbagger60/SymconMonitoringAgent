<?php

declare(strict_types=1);

class HeartbeatAgent extends IPSModuleStrict
{
    private const PROTOCOL_VERSION = 1;
    private const AGENT_VERSION = '1.0.0';
    private const TIMEOUT_SECONDS = 15;

    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_INVALID_CONFIGURATION = 200;
    private const STATUS_TRANSMISSION_FAILED = 201;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyString('SystemID', '');
        $this->RegisterPropertyString(
            'Endpoint',
            'https://monitoring.cassanisautomation.fr/heartbeat.php'
        );
        $this->RegisterPropertyString('SharedSecret', '');
        $this->RegisterPropertyInteger('IntervalSeconds', 60);

        $this->RegisterTimer(
            'HeartbeatTimer',
            0,
            "IPS_RequestAction(\$_IPS['TARGET'], 'SendHeartbeat', false);"
        );

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('HeartbeatTimer', 0);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(self::STATUS_INACTIVE);
            return;
        }

        $configurationError = $this->GetConfigurationError();
        if ($configurationError !== '') {
            $this->SendDebug('Configuration', $configurationError, 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }

        $this->SetStatus(self::STATUS_ACTIVE);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            // Send shortly after applying the configuration, then use the regular interval.
            $this->SetBuffer('ShortTimer', '1');
            $this->SetTimerInterval('HeartbeatTimer', 1000);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== IPS_KERNELMESSAGE || ($Data[0] ?? null) !== KR_READY) {
            return;
        }

        if (!$this->ReadPropertyBoolean('Active') || $this->GetConfigurationError() !== '') {
            return;
        }

        $this->SetBuffer('ShortTimer', '1');
        $this->SetTimerInterval('HeartbeatTimer', 5000);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'SendHeartbeat') {
            throw new InvalidArgumentException('Invalid ident: ' . $Ident);
        }

        $manual = (bool) $Value;
        if (!$manual && !$this->ReadPropertyBoolean('Active')) {
            return;
        }

        $configurationError = $this->GetConfigurationError();
        if ($configurationError !== '') {
            $this->SendDebug('Configuration', $configurationError, 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }

        $this->TransmitHeartbeat();
    }

    private function TransmitHeartbeat(): void
    {
        $semaphore = 'HeartbeatAgent.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 0)) {
            $this->SendDebug('Heartbeat', 'Skipped: already running', 0);
            return;
        }

        try {
            $startedAt = time();
            $lastSequence = (int) $this->GetBuffer('LastSequence');
            $sequence = max($lastSequence + 1, $startedAt);

            // Persist before sending. A failed or interrupted request must not reuse a sequence.
            $this->SetBuffer('LastSequence', (string) $sequence);

            $payload = [
                'protocol'       => self::PROTOCOL_VERSION,
                'system'         => $this->ReadPropertyString('SystemID'),
                'sequence'       => $sequence,
                'sentAt'         => $startedAt,
                'kernelStarted'  => IPS_GetKernelStartTime(),
                'symconVersion'  => IPS_GetKernelVersion(),
                'agentVersion'   => self::AGENT_VERSION
            ];

            $body = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $signature = hash_hmac(
                'sha256',
                $startedAt . "\n" . $body,
                $this->ReadPropertyString('SharedSecret')
            );

            $request = $this->PostHeartbeat($body, $startedAt, $signature);
            $result = [
                'status'      => $request['success'] ? 'ok' : 'error',
                'startedAt'   => $startedAt,
                'completedAt' => time(),
                'sequence'    => $sequence,
                'httpCode'    => $request['httpCode'],
                'durationMs'  => $request['durationMs'],
                'error'       => $request['error']
            ];

            $this->SetBuffer(
                'LastResult',
                json_encode(
                    $result,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                )
            );
            $this->SendDebug('Heartbeat', $result, 0);

            if ($request['success']) {
                $this->SetStatus(
                    $this->ReadPropertyBoolean('Active')
                        ? self::STATUS_ACTIVE
                        : self::STATUS_INACTIVE
                );
            } else {
                $this->SetStatus(self::STATUS_TRANSMISSION_FAILED);
                IPS_LogMessage(
                    'HeartbeatAgent',
                    sprintf(
                        'Heartbeat for %s failed: HTTP %d, %s',
                        $this->ReadPropertyString('SystemID'),
                        $request['httpCode'],
                        $request['error']
                    )
                );
            }
        } finally {
            IPS_SemaphoreLeave($semaphore);
            $this->RestoreRegularTimer();
        }
    }

    /** @return array{success: bool, httpCode: int, durationMs: int, error: string} */
    private function PostHeartbeat(string $body, int $timestamp, string $signature): array
    {
        $startedAt = microtime(true);
        $networkError = false;

        set_error_handler(
            static function (int $severity, string $message) use (&$networkError): bool {
                $networkError = true;
                return true;
            }
        );

        try {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'timeout'       => self::TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                    'header'        => implode("\r\n", [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Connection: close',
                        'X-System-ID: ' . $this->ReadPropertyString('SystemID'),
                        'X-Timestamp: ' . $timestamp,
                        'X-Signature: ' . $signature
                    ]) . "\r\n",
                    'content'       => $body
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true
                ]
            ]);

            $responseBody = file_get_contents(
                $this->ReadPropertyString('Endpoint'),
                false,
                $context
            );
        } finally {
            restore_error_handler();
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $headers = $http_response_header ?? [];
        $httpCode = 0;

        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches) === 1) {
            $httpCode = (int) $matches[1];
        }

        if ($httpCode === 204) {
            return [
                'success'    => true,
                'httpCode'   => 204,
                'durationMs' => $durationMs,
                'error'      => ''
            ];
        }

        if ($httpCode > 0) {
            return [
                'success'    => false,
                'httpCode'   => $httpCode,
                'durationMs' => $durationMs,
                'error'      => 'http_error: ' . $httpCode
            ];
        }

        return [
            'success'    => false,
            'httpCode'   => 0,
            'durationMs' => $durationMs,
            'error'      => $networkError || $responseBody === false ? 'network_error' : 'request_failed'
        ];
    }

    private function GetConfigurationError(): string
    {
        $systemID = $this->ReadPropertyString('SystemID');
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,62}[a-z0-9]$/', $systemID) !== 1) {
            return 'Invalid SystemID';
        }

        $secret = $this->ReadPropertyString('SharedSecret');
        if (preg_match('/^[a-f0-9]{64}$/', $secret) !== 1) {
            return 'Invalid SharedSecret';
        }

        $endpoint = $this->ReadPropertyString('Endpoint');
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || !str_starts_with($endpoint, 'https://')) {
            return 'Invalid Endpoint';
        }

        $interval = $this->ReadPropertyInteger('IntervalSeconds');
        if ($interval < 30 || $interval > 3600) {
            return 'Invalid IntervalSeconds';
        }

        return '';
    }

    private function RestoreRegularTimer(): void
    {
        if ($this->GetBuffer('ShortTimer') === '1') {
            $this->SetBuffer('ShortTimer', '0');
        }

        if ($this->ReadPropertyBoolean('Active') && $this->GetConfigurationError() === '') {
            $this->SetTimerInterval(
                'HeartbeatTimer',
                $this->ReadPropertyInteger('IntervalSeconds') * 1000
            );
        } else {
            $this->SetTimerInterval('HeartbeatTimer', 0);
        }
    }
}
