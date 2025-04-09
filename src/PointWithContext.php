<?php declare(strict_types=1);

namespace Memcrab\Metrics;

use InfluxDB2\Point;

class PointWithContext extends Point
{
    public array $context = [];

    public function __construct(
        $name,
        $tags = null,
        $fields =  null,
        $time = null,
        $precision = Point::DEFAULT_WRITE_PRECISION
    ) {
        parent::__construct($name, $tags, $fields, $time, $precision);
        
        $Processor = new CoroutineContextProcessor();
        $Processor($this);
    }
}