# Memcrab Metrics

Memcrab Metrics is a library that provides an additional layer over the InfluxDB client to asynchronously send metrics to InfluxDB. 
It integrates with OpenSwoole for coroutine-based asynchronous operations and uses the InfluxDB line protocol for metric formatting.

## Features

- Asynchronous metric sending with OpenSwoole coroutine support.
- Easy integration with InfluxDB client (version 3.1.0).
- Allows metrics to be sent in the InfluxDB line protocol format.

## Installation

```sh
composer require memcrab/metrics
```

## Usage

### Initialize a client

```php
$influxDBListenerUrl = 'http://127.0.0.1:8186/api/v2/write';
Metric::obj()->init($influxDBListenerUrl);
```

### Sending Metrics

```php
Metric::obj()->write('cpu_usage', [
        'host' => 'server01'
    ],
    [
        'usage' => 45
    ],
    time()
);

```

## License

The gem is available as open source under the terms of the [MIT License](https://opensource.org/licenses/MIT).