<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config\Enum;

enum CompressionType
{
    case snappy;
    case gzip;
    case lz4;
    case zstd;
}
