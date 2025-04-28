<?php declare(strict_types=1);

namespace Memcrab\Metrics;

require_once 'vendor/autoload.php';

use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;

class Metric
{
    private static ?self $instance = null;
    private string $telegrafUrl;
    private string $telegrafHost;
    private int $telegrafPort;
    private string $telegrafPath;
    private bool $initialized = false;
    private string $writePrecision;

    /**
     * Indicates whether metric sending is enabled.
     *
     * If set to false (e.g. in a LOCAL or testing environment), all metric operations
     * such as initialization, writing, and sending will be skipped.
     */
    private bool $enabled = true;
    

    private function __construct() {}
    private function __clone() {}
    public function __wakeup() {}

    public static function obj(): self 
    {
        return self::$instance ??= new self();
    }

    /**
     * Tests the availability of the Telegraf HTTP listener by performing a HEAD request.
     *
     * - Does not send actual data, only checks for a response.
     * - Uses a short timeout to quickly determine if the endpoint is reachable.
     * - Returns the HTTP response code or null if the connection failed.
     *
     * @param string $telegrafUrl Full URL to the Telegraf HTTP listener (e.g. http://localhost:8186/api/v2/write).
     *
     * @return int|null HTTP response code if the request succeeds, or null if there was a connection error.
     */
    public function testTelegrafConnection(string $telegrafUrl): ?int
    {
        $ch = curl_init($telegrafUrl);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true, // Perform a HEAD request (doesn't send actual data)
            CURLOPT_TIMEOUT => 2,   // Short timeout for fast failure
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE)?: null;;
        $error = curl_error($ch);
        
        curl_close($ch);

        return $httpCode;
    }

    /**
     * Initializes the Telegraf client connection parameters from the given URL
     * and checks the availability of the Telegraf server.
     *
     * - If $enabled is false, disables metric sending and skips initialization
     * - Parses the URL and stores host, port, and path.
     * - Sends a test request to verify Telegraf connectivity.
     * - Emits warnings for connection errors or HTTP status codes >= 400.
     *
     * @param string $telegrafUrl Full URL to the Telegraf HTTP listener (e.g. http://localhost:8186/api/v2/write).
     * @param bool $enabled Whether to enable metric sending (e.g. skip in LOCAL environment).
     * @param string $writePrecision The precision of the timestamp. This determines the unit of time for the timestamp.
     * Valid values are:
     * - 'ns' (nanoseconds)
     * - 'us' (microseconds)
     * - 'ms' (milliseconds)
     * - 's' (seconds)
     * Default value is Point::DEFAULT_WRITE_PRECISION ('ns').
     *
     * @return self Returns the current instance for method chaining.
     *
     * @triggers E_USER_WARNING If Telegraf is unreachable or returns a 4xx/5xx HTTP status.
     */
    public function init(string $telegrafUrl, bool $enabled = true, string $writePrecision = Point::DEFAULT_WRITE_PRECISION): self
    {
        if (!$enabled) {
            $this->enabled = false;
            return $this;
        }

        $httpCode = $this->testTelegrafConnection($telegrafUrl);

        if ($httpCode === null) {
            trigger_error(sprintf('⚠️ Telegraf is unreachable at %s', $telegrafUrl), E_USER_WARNING);
        }
        if ($httpCode >= 500) {
            trigger_error(sprintf('⚠️ Telegraf is running but has internal server error (HTTP %s)', $httpCode), E_USER_WARNING);
        }
        if ($httpCode >= 400) {
            trigger_error(sprintf('⚠️ Telegraf rejected request (HTTP %s). Check authentication and permissions.', $httpCode), E_USER_WARNING);
        }
        if (!in_array($writePrecision, WritePrecision::getAllowableEnumValues(), true)) {
            trigger_error(sprintf('⚠️ Invalid write precision: ‘%s’. The default precision will be used instead.', $writePrecision), E_USER_WARNING);
            $writePrecision = Point::DEFAULT_WRITE_PRECISION;
        }

        $this->telegrafUrl = $telegrafUrl;
        $parsedUrl = parse_url($telegrafUrl);
        $this->telegrafHost = $parsedUrl['host'] ?? '127.0.0.1';
        $this->telegrafPort = $parsedUrl['port'] ?? 8186;
        $this->telegrafPath = $parsedUrl['path'] ?? '/api/v2/write';
        $this->writePrecision = $writePrecision;
        $this->initialized = true;

        return $this;
    }

    /**
     * Writes a metric point with the specified tags, fields, and optional timestamp.
     *
     * @param string                        $name       Metric name.
     * @param array<string, string>         $tags       Associative array of tag key-value pairs. Keys and values will be cast to strings.
     * @param array<string, scalar>         $fields     Associative array of field key-value pairs. Must not be empty.
     * @param float|\DateTimeInterface|null $timestamp Optional timestamp for the metric.
     *
     * @throws \InvalidArgumentException If $fields is empty or $tags/$fields are not associative.
     * @throws \RuntimeException If initialization is missing.
     */
    public function write(string $name, array $tags, array $fields, null|float|\DateTimeInterface $timestamp = null): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->initialized) {
            throw new \RuntimeException('Metric initialization missing. Call init() first.');
        }
    
        if (empty($fields)) {
            throw new \InvalidArgumentException('Parameter $fields must not be empty.');
        }

        $PointWithContext = new PointWithContext($name);

        foreach ($tags as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Parameter $tags must be associative array.');
            }
            $PointWithContext->addTag($key, (string) $value);
        }

        foreach ($fields as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Parameter $fields must be associative array.');
            }
            $PointWithContext->addField($key, $value);
        }
        
        if ($timestamp) {
            $PointWithContext->time($timestamp);
        } else {
            $timestamp = \DateTimeImmutable::createFromFormat('U.u', number_format(microtime(true), 6, '.', ''))
            ->setTimezone(new \DateTimeZone('UTC'));
            $PointWithContext->time($timestamp, $this->writePrecision);
        }

        $this->send($PointWithContext);
    }

    /**
     * Sends the given metric point to the Telegraf endpoint, either in coroutine or non-coroutine context.
     *
     * Depending on the context (whether the code is running in a coroutine or not), this method either
     * uses a non-blocking Swoole client for better performance or a blocking request to ensure log delivery.
     *
     * @param PointWithContext $PointWithContext The point with context to send, containing the metric data and (coroutine) context information.
     *
     * @throws \InvalidArgumentException If line protocol generation fails due to empty fields.
     * @throws \RuntimeException If initialization is missing.
     */
    public function send(PointWithContext $PointWithContext): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->initialized) {
            throw new \RuntimeException('Metric initialization missing. Call init() first.');
        }

        $lineProtocol = $PointWithContext->toLineProtocol();

        if($lineProtocol === null) {
            throw new \InvalidArgumentException('Unable to generate line protocol due to empty fields. Metric has been dropped.');
        }

        # Different logging approaches to efficiently handle Swoole’s coroutine context:
        # - Coroutine context: Use a non-blocking Swoole client for better performance (no waiting for response)
        # - Non-coroutine context (before Swoole server start or after it stops): Use a blocking request to ensure log delivery (print errors to stdout if sending fails)
        # - Non-coroutine context with coroutine hook enabled (e.g., logging inside `on('start')` callback): Logging must be wrapped in a coroutine.
        $isRunningInCoroutine = $PointWithContext->context['isRunningInCoroutine'] ?? false;
        $isCoroutineHookEnable = $PointWithContext->context['isCoroutineHookEnable'] ?? false;

        if ($isRunningInCoroutine) {
            $this->sendInCoroutine($lineProtocol);
        } else {
            if($isCoroutineHookEnable) {
                Coroutine::create(function () use ($lineProtocol) {
                    $this->sendInCoroutine($lineProtocol);
                });
            } else {
                $this->sendOutsideCoroutine($lineProtocol);
            }
        }
    }

    /**
     * Sends the given Line Protocol payload to Telegraf using a blocking cURL request.
     *
     * This method is intended to be used **outside** of a Swoole coroutine context.
     *
     * If the request fails (cURL error or HTTP status ≥ 400), a warning message is printed to STDOUT.
     *
     * @param string $lineProtocol The metric data in InfluxDB Line Protocol format.
     *
     * @return void
     */
    private  function sendOutsideCoroutine(string $lineProtocol): void
    {
        $ch = curl_init($this->telegrafUrl);
    
        $headers = [
            'Content-Type: text/plain; charset=utf-8', #default
        ];
    
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $lineProtocol,
            CURLOPT_HTTPHEADER => $headers,
        ]);
    
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            fwrite(STDOUT, "Error while sending log via Telegraf Handler: cURL error - $error\n");
        } elseif ($httpCode >= 400) {
            fwrite(STDOUT, "Error while sending log via Telegraf Handler: HTTP $httpCode - Response: $response\n");
        }
    }
    
    /**
     * Sends the given Line Protocol payload to Telegraf using a non-blocking coroutine HTTP client.
     *
     * This method is designed to be called **inside** a Swoole coroutine.
     *
     * @param string $lineProtocol The metric data in InfluxDB Line Protocol format.
     *
     * @return void
     */
    private function sendInCoroutine(string $lineProtocol): void
    {
        $Client = new Client($this->telegrafHost, $this->telegrafPort);

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8', #default
        ];
        $Client->setHeaders($headers);
        
        $Client->post($this->telegrafPath, $lineProtocol);
        $Client->close();
    }
}
